<?php

namespace App\Services;

use App\Models\TokenDeSesion;
use App\Support\Reloj;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Abre, valida, refresca y cierra sesiones.
 *
 * Es el único sitio que sabe qué es un token válido. `User::fromToken()`, el
 * middleware `auth.token` y el guard `sesion` de config/auth.php pasan todos
 * por aquí, para que no haya dos respuestas distintas a la misma pregunta.
 *
 * Aquí llegó a convivir el camino de los JWT viejos, para no expulsar a nadie el
 * día de la Fase 3. Se quitó al saltar a Laravel 10: `tymon/jwt-auth` solo
 * declara soporte hasta `illuminate ^9` y era el bloqueante duro del framework.
 *
 * **Una sesión son DOS tokens**, no uno:
 *
 * - el de **acceso** viaja en cada petición, vive 60 min y no puede renovarse
 *   a sí mismo;
 * - el de **refresco** solo se manda a `POST /api/auth/refresh`, vive 14 días
 *   y rota en cada uso.
 *
 * Con un solo token que se renueva a sí mismo después de caducar —que es como
 * funciona el refresh de JWT— el token no caduca de verdad: quien lo robe lo
 * renueva mientras dure la ventana de refresco. Separarlos hace que lo que se
 * expone en cada petición muera en una hora.
 *
 * Los dos comparten el campo `name` ('web:<uuid>'), que identifica la SESIÓN.
 * Por eso cerrar sesión borra el par con un solo DELETE.
 *
 * El contrato de cara a los clientes está en docs/migracion/07-sesion.md.
 */
class Sesion
{
    /** Prefijo de la memoria por petición. Ver resolver(). */
    private const MEMORIA = 'usuario.sesion.';

    /**
     * Abre una sesión: par de tokens nuevo.
     *
     * @return array{el_token: string, refresco: string, expira_en: int}
     */
    public function abrir(User $usuario, string $origen = 'web'): array
    {
        $this->limpiarCaducados($usuario);

        $sesion = $origen.':'.Str::uuid()->toString();

        [$acceso, $accesoPlano] = $this->emitir(
            $usuario, $sesion, [TokenDeSesion::ACCESO], (int) config('sesion.acceso_ttl')
        );

        [, $refrescoPlano] = $this->emitir(
            $usuario, $sesion, [TokenDeSesion::REFRESCO], (int) config('sesion.refresco_ttl')
        );

        return [
            'el_token' => $accesoPlano,
            'refresco' => $refrescoPlano,
            'expira_en' => $acceso->segundosDeVida(),
        ];
    }

    /**
     * Un solo token de acceso, largo y sin refresco, para las rutas viejas.
     *
     * `POST login/credentials` devuelve `{ el_token }` y nada más, y un front
     * que no conoce `/api/auth/*` no sabría qué hacer con un refresco. Para que
     * su sesión dure lo mismo que hoy, este token vive lo que vivía el JWT
     * (24 h) en vez de una hora.
     */
    public function abrirLegado(User $usuario, string $origen = 'legado'): string
    {
        $this->limpiarCaducados($usuario);

        [, $plano] = $this->emitir(
            $usuario,
            $origen.':'.Str::uuid()->toString(),
            [TokenDeSesion::ACCESO],
            (int) config('sesion.legado_ttl')
        );

        return $plano;
    }

    /**
     * El refresco que trae la petición, si vale.
     *
     * Devuelve null cuando no hay, no es de refresco, o ya no vale. Quien llama
     * decide el código: aquí no se sabe si la ruta quiere 401 o algo distinto.
     */
    public function refrescoDe(string $plano): ?TokenDeSesion
    {
        $token = $this->buscar($plano);

        if ($token === null || ! $token->esDeRefresco()) {
            return null;
        }

        if ($token->haCaducado()) {
            // Caducado Y ya rotado es reutilización, no olvido: alguien guardó
            // un refresco viejo y lo ha vuelto a presentar. No se cierra la
            // sesión por ello —un despiste del cliente dejaría a todo el mundo
            // fuera sin que nadie hubiera hecho nada malo—, pero queda escrito
            // para poder verlo.
            if ($token->fueRotado()) {
                $this->anotarReutilizacion($token);
            }

            return null;
        }

        return $token;
    }

    /**
     * Rota el refresco y devuelve un par nuevo.
     *
     * **La gracia.** El viejo no se borra: se le pone `expires_at` a unos
     * segundos vista y se apunta cuál lo sustituyó. Durante esa ventana se
     * sigue aceptando y devuelve otro par. Sin eso, dos pestañas que renuevan
     * casi a la vez —la segunda con el refresco que la primera acaba de
     * jubilar— cerrarían la sesión del usuario sin que hiciera nada mal, y de
     * forma intermitente, que es la peor clase de fallo que se puede reportar.
     * En informes se trabaja con varias pestañas abiertas, así que no es raro.
     *
     * Pasada la gracia, el mismo token es un 401 y queda anotado.
     *
     * @return array{el_token: string, refresco: string, expira_en: int}
     */
    public function rotar(TokenDeSesion $token): array
    {
        $usuario = $token->tokenable;

        // El invariante, escrito donde se usa. `SesionController` ya comprueba
        // esto mismo antes de llamar y devuelve 401 —un token de alguien que ya
        // no está no renueva nada—, pero ahí es una defensa y aquí es la
        // condición de la que depende todo lo de abajo: `tokenable` es un
        // `morphTo`, así que devuelve `null` para un usuario borrado, y sin este
        // `abort` la renovación de una cuenta dada de baja sería un 500 en vez
        // del 401 que le toca.
        if (! $usuario instanceof User) {
            abort(401, 'Token inválido, prohibido entrar.');
        }

        $sesion = $token->name;

        [$acceso, $accesoPlano] = $this->emitir(
            $usuario, $sesion, [TokenDeSesion::ACCESO], (int) config('sesion.acceso_ttl')
        );

        [$refresco, $refrescoPlano] = $this->emitir(
            $usuario, $sesion, [TokenDeSesion::REFRESCO], (int) config('sesion.refresco_ttl')
        );

        $gracia = Carbon::now()->addSeconds((int) config('sesion.gracia_refresco'));

        $token->forceFill([
            'reemplazado_por' => $refresco->getKey(),
            // El mínimo de los dos: la gracia alarga la vida de un token a
            // punto de morir, nunca la de uno al que aún le quedaban horas.
            'expires_at' => $token->expires_at !== null && $token->expires_at->lt($gracia)
                ? $token->expires_at
                : $gracia,
        ])->save();

        return [
            'el_token' => $accesoPlano,
            'refresco' => $refrescoPlano,
            'expira_en' => $acceso->segundosDeVida(),
        ];
    }

    /** Cierra la sesión a la que pertenece este token: borra el par entero. */
    public function cerrar(TokenDeSesion $token): void
    {
        $token->newQuery()
            ->where('tokenable_type', $token->tokenable_type)
            ->where('tokenable_id', $token->tokenable_id)
            ->where('name', $token->name)
            ->delete();
    }

    /** Cierra todas las sesiones del usuario. Devuelve cuántos tokens borró. */
    public function cerrarTodas(User $usuario): int
    {
        return $usuario->tokens()->delete();
    }

    /**
     * El usuario de la petición, o null. NUNCA aborta.
     *
     * Es lo que necesita un guard de Laravel (`Auth::viaRequest`): devolver
     * null y dejar que decida quien llame. Para el camino que sí aborta con los
     * mensajes de siempre, ver `exigirUsuario()`.
     */
    public function usuarioDe(Request $peticion): ?User
    {
        $plano = $this->tokenPlanoDe($peticion);

        return $plano === null ? null : $this->resolver($plano, false);
    }

    /**
     * El usuario de la petición, abortando con los mensajes de siempre.
     *
     * Los tres mensajes ('No existe Token', 'Token ha expirado.', 'Token
     * inválido, prohibido entrar.') los distingue el frontend AngularJS, así
     * que son contrato aunque no lo parezcan. Ver
     * docs/migracion/04-auditoria-autenticacion.md.
     */
    public function exigirUsuario(Request $peticion): User
    {
        $plano = $this->tokenPlanoDe($peticion);

        if ($plano === null) {
            abort(401, 'No existe Token');
        }

        return $this->exigirDeToken($plano);
    }

    /** El usuario de un token concreto, abortando si no vale. */
    public function exigirDeToken(string $plano): User
    {
        return $this->resolver($plano, true);
    }

    /**
     * El camino único: un token, o el usuario que hay detrás.
     *
     * `$abortando` es lo único que cambia entre el guard —que devuelve null— y
     * el middleware —que responde 401 con el mensaje que toque—. La decisión de
     * qué token vale está aquí y solo aquí.
     *
     * **Y se resuelve una vez por petición.** Cada petición preguntaba dos
     * veces: el limitador de `RouteServiceProvider` llama a `$request->user()`
     * —que pasa por el guard `sesion`— solo para decidir la clave del cubo, y
     * después el middleware `auth.token` vuelve a resolver por su cuenta. Eran
     * dos `SELECT` a `personal_access_tokens` y dos a `users` idénticos,
     * medidos: 9 consultas en un `GET api/periodos` del que solo una era del
     * endpoint. Es el paso 7 del plan de rendimiento.
     *
     * La memoria va en los `attributes` de la petición y no en una propiedad,
     * porque `app(Sesion::class)` construye una instancia nueva cada vez y
     * porque una propiedad sobreviviría a la petición el día que esto corra
     * bajo Octane. Es el mismo sitio donde `User::fromToken()` guarda el
     * contexto.
     */
    private function resolver(string $plano, bool $abortando): ?User
    {
        $memoria = self::MEMORIA.hash('xxh3', $plano);
        // `request()` y no `Request::instance()`: aquí `Request` es la clase
        // Illuminate\Http\Request, no la facade, y no tiene ese método estático.
        $peticion = request();

        if ($peticion->attributes->has($memoria)) {
            return $peticion->attributes->get($memoria);
        }

        $usuario = $this->resolverDeVerdad($plano, $abortando);

        // Solo se guarda lo que resolvió. Un null significa que el token no
        // vale, y el siguiente que pregunte puede necesitar el `abort()` con su
        // mensaje —el guard pregunta sin abortar y el middleware abortando—.
        if ($usuario !== null) {
            $peticion->attributes->set($memoria, $usuario);
        }

        return $usuario;
    }

    private function resolverDeVerdad(string $plano, bool $abortando): ?User
    {
        if ($this->tieneNuestraForma($plano)) {
            $token = $this->buscar($plano);

            // Un refresco presentado como si fuera de acceso cae en el mismo
            // saco que uno inventado, y es a propósito: el refresco solo abre
            // la puerta de /api/auth/refresh.
            if ($token === null || ! $token->esDeAcceso()) {
                return $abortando ? abort(401, 'Token inválido, prohibido entrar.') : null;
            }

            if ($token->haCaducado()) {
                return $abortando ? abort(401, 'Token ha expirado.') : null;
            }

            $usuario = $token->tokenable;

            if (! $usuario instanceof User) {
                return $abortando ? abort(401, 'Token inválido, prohibido entrar.') : null;
            }

            // A quien han desactivado o borrado se le cae la sesión AHORA, no
            // cuando caduque el token que ya tiene.
            //
            // `POST /api/auth/refresh` ya lo comprobaba, así que una cuenta dada
            // de baja no podía renovar — pero el token de acceso que llevaba en
            // la mano seguía valiendo hasta su hora: **60 minutos, y 24 h si lo
            // emitió `login/credentials`**, que es la puerta de los fronts que
            // todavía no conocen `/api/auth/*`. Para «apagar la cuenta de
            // alguien», que es lo que un colegio hace un lunes por la mañana,
            // ese hueco es justo el que importa. Ver
            // docs/migracion/12-larastan-nivel-7.md §16.
            //
            // No cuesta una consulta: `tokenable` ya trae la fila entera de
            // `users`, y estas dos columnas vienen dentro.
            //
            // `deleted_at` va con ella aunque hoy no la escriba ningún endpoint
            // —`App\User` no usa SoftDeletes y la papelera de usuarios está
            // vacía— porque `Services\Login` sí la filtra al entrar: sin esto,
            // el día que alguien añada el borrado, quien tuviera sesión abierta
            // se quedaría dentro renovando cada catorce días para siempre.
            if (! $usuario->is_active || $usuario->deleted_at !== null) {
                return $abortando ? abort(401, 'Usuario invalidado') : null;
            }

            $this->marcarUso($token);

            return $usuario->withAccessToken($token);
        }

        // No hay un segundo formato al que caer: desde que se quitó
        // tymon/jwt-auth, lo que no es un token de Sanctum no es nada.
        return $abortando ? abort(401, 'Token inválido, prohibido entrar.') : null;
    }

    /**
     * La fila del token que trae la petición, si es de Sanctum.
     *
     * `$aunqueCaducado` existe por el logout: cerrar sesión con el token ya
     * vencido tiene que funcionar —es el caso normal de quien vuelve al día
     * siguiente—, y además es cuando más falta hace borrarlo de la tabla.
     */
    public function tokenDe(Request $peticion, bool $aunqueCaducado = false): ?TokenDeSesion
    {
        $plano = $this->tokenPlanoDe($peticion);

        if ($plano === null || ! $this->tieneNuestraForma($plano)) {
            return null;
        }

        $token = $this->buscar($plano);

        if ($token === null) {
            return null;
        }

        return $aunqueCaducado || ! $token->haCaducado() ? $token : null;
    }

    /**
     * De dónde se saca el token de la petición.
     *
     * Del header `Authorization: Bearer`, y si no, de un parámetro `token`.
     * Ese segundo camino no se estrena aquí: `tymon/jwt-auth` lo acepta desde
     * siempre (su cadena de parsers es AuthHeaders → QueryString → InputSource),
     * así que quitarlo ahora rompería a cualquier cliente que lo use. Ninguno de
     * los cuatro que conocemos lo hace —todos mandan el header—, pero "que yo
     * sepa" no es un inventario.
     *
     * Por eso el aviso al log: si en unos meses no ha aparecido ni una línea, se
     * puede quitar con fundamento. Un token en la URL acaba en los logs de
     * acceso del servidor y en el historial del navegador, así que la intención
     * es quitarlo.
     */
    public function tokenPlanoDe(Request $peticion): ?string
    {
        $plano = $peticion->bearerToken();

        if (is_string($plano) && $plano !== '') {
            return $plano;
        }

        $delParametro = $peticion->input('token');

        if (is_string($delParametro) && $delParametro !== '') {
            Log::notice('Token recibido por parámetro y no por cabecera', [
                'ruta' => $peticion->path(),
            ]);

            return $delParametro;
        }

        return null;
    }

    /**
     * Los tokens de Sanctum son '<id>|<40 caracteres>'.
     *
     * Mirar la forma antes de ir a la base ahorra una consulta por cada cadena
     * que ni siquiera puede ser un token nuestro. Servía además para distinguir
     * los JWT viejos, que eran tres bloques separados por puntos; eso se acabó
     * al quitar tymon/jwt-auth, pero el ahorro sigue.
     */
    private function tieneNuestraForma(string $plano): bool
    {
        return (bool) preg_match('/^\d+\|/', $plano);
    }

    private function buscar(string $plano): ?TokenDeSesion
    {
        $token = TokenDeSesion::findToken($plano);

        return $token instanceof TokenDeSesion ? $token : null;
    }

    private function emitir(User $usuario, string $sesion, array $habilidades, int $minutos): array
    {
        $plano = Str::random(40);

        $token = $usuario->tokens()->create([
            'name' => $sesion,
            'token' => hash('sha256', $plano),
            'abilities' => $habilidades,
            'expires_at' => Carbon::now()->addMinutes($minutos),
        ]);

        return [$token, $token->getKey().'|'.$plano];
    }

    /**
     * `last_used_at`, pero no en cada petición.
     *
     * Escribirlo siempre convierte cualquier GET en una escritura, y aquí eso
     * se paga: son alojamientos compartidos y la API se llama muchas veces por
     * pantalla. Con cinco minutos de holgura el dato sigue sirviendo para lo
     * único que se usa —ver si una sesión sigue viva— y la mayoría de las
     * peticiones no escriben nada.
     */
    private function marcarUso(TokenDeSesion $token): void
    {
        if ($token->last_used_at !== null && $token->last_used_at->gt(Carbon::now()->subMinutes(5))) {
            return;
        }

        $token->forceFill(['last_used_at' => Carbon::now()])->save();
    }

    /**
     * Al abrir sesión, tira los tokens ya muertos de ese usuario.
     *
     * Barato —va por el índice de `tokenable`— y evita que la tabla crezca sin
     * fin en el caso normal. La limpieza global es `php artisan sesion:limpiar`.
     */
    private function limpiarCaducados(User $usuario): void
    {
        $usuario->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }

    private function anotarReutilizacion(TokenDeSesion $token): void
    {
        Log::warning('Refresco ya rotado presentado de nuevo', [
            'token_id' => $token->getKey(),
            'sesion' => $token->name,
            'user_id' => $token->tokenable_id,
        ]);

        DB::insert(
            'INSERT INTO bitacoras (descripcion, affected_person_name, affected_element_type, created_at, created_by)
             VALUES (?, ?, "refresco_reutilizado", ?, ?)',
            [
                'Refresco ya rotado presentado de nuevo. Sesión: '.$token->name,
                '',
                // La ÚNICA de este fichero que cambia de reloj, y por eso lleva nota:
                // las demás `Carbon::now()` de aquí gobiernan expiraciones que sólo
                // se comparan consigo mismas y pueden seguir en UTC. Ésta escribe
                // en `bitacoras.created_at`, que es de todos. Ver 18 §1.1.
                //
                // Y el motivo por el que las otras se QUEDAN, que es lo que impide
                // que alguien las «unifique» dentro de seis meses (lo apuntó
                // `8myvc-d2`): `last_used_at` se compara contra
                // `Carbon::now()->subMinutes(5)` **en el mismo proceso**, así que
                // moverla a Bogotá no cambiaría nada hoy — pero rompería la
                // comparación el día que uno de los dos lados se lea de la base.
                Reloj::ahora(),
                (int) $token->tokenable_id,
            ]
        );

        /*
         * El rastro nuevo, al lado del viejo (18 §4). Y la decisión que hay
         * detrás de `sinActor`, que es lo único no obvio de esta línea:
         *
         * `bitacoras` escribe arriba `created_by = $token->tokenable_id`, o sea
         * **afirma que el dueño del token fue quien lo presentó**. Eso es
         * exactamente lo contrario de lo que este suceso significa: un refresco ya
         * rotado presentado de nuevo es la señal de que el token **puede estar en
         * otras manos**, y atribuirlo a su dueño convierte la única fila que
         * documenta el robo en una fila que acusa a la víctima.
         *
         * Lo que sí se sabe es de quién es el token, y eso es una identidad
         * *pretendida*, no comprobada: va a `actor_intentado`, que es la columna
         * que existe para eso. La consulta extra es de un `SELECT` por id sobre
         * `users` y sólo ocurre en un suceso de seguridad, que por definición es
         * raro; a cambio, la fila se puede leer sin salir a buscar a nadie.
         */
        $dueno = DB::selectOne('SELECT username FROM users WHERE id = ?', [(int) $token->tokenable_id]);

        Auditoria::registrar()
            ->denegado('refresco_reutilizado', (int) $token->getKey())
            ->sinActor($dueno->username ?? null)
            ->resumen('Refresco ya rotado presentado de nuevo. Sesión: '.$token->name)
            ->guardar();
    }
}
