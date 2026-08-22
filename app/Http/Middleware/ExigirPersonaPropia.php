<?php

namespace App\Http\Middleware;

use App\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Un alumno solo sobre sí mismo; un acudiente, sobre sí y sobre sus acudidos.
 *
 * Es la regla que fijó Joseth el 19 ago 2026, después de la revisión de IDOR
 * (docs/migracion/08-revision-idor.md), y se aplica a las rutas que una familia
 * SÍ usa: su perfil, sus fotos, sus datos. Las que una familia no usa no llevan
 * esto sino `auth.personal`, que cierra la puerta entera.
 *
 * **Qué NO decide.** Lo que puede hacer el personal del colegio entre sí queda
 * como está: profesores y administrativos pasan de largo. Ordenar eso es el
 * refactor de permisos que viene después, y mezclarlo aquí habría convertido un
 * arreglo comprobable en uno que hay que discutir.
 *
 * **Por qué mira TODOS los identificadores y no uno.** El agujero de
 * `matriculas/prematricular` no era que faltara una comprobación: era que el id
 * viajaba en el cuerpo y nadie miraba ahí. Los endpoints de este sistema nombran
 * a una persona de seis maneras distintas —`alumno_id`, `user_id`, `persona_id`,
 * `acudiente_id`, `profesor_id`, `matricula_id`— unas por URL y otras por cuerpo,
 * y varios aceptan más de una a la vez. Comprobar solo la que uno espera deja
 * abierta la que no. Aquí se recogen todas las que vengan y **todas** tienen que
 * ser suyas.
 *
 * **Sin identificador se deja pasar**, y es correcto: significa «lo mío», que es
 * lo que el controlador resuelve del token. `myimages/datos-imagen` sin `user_id`
 * devuelve las imágenes de quien pregunta. Lo que no puede pasar es que una ruta
 * de grupo entero llegue aquí sin id y salga entera: esas llevan `auth.personal`.
 */
class ExigirPersonaPropia
{
    /** Los que sí tienen dueño. Cada uno se resuelve distinto. */
    private const CLAVES = [
        'alumno_id', 'user_id', 'persona_id', 'acudiente_id', 'profesor_id', 'matricula_id',
        // `img_id` es `imagen_id` con otro nombre, y va aquí porque una lista de
        // nombres se queda corta en cuanto un endpoint escribe el suyo:
        // `images-users/move-img-to-me` lo llama así y por eso el guard no veía
        // nada que comprobar. Ver 05-codigo-muerto-y-roto.md §15.
        //
        // Y `foto_id` es el TERCER nombre de lo mismo, encontrado por la pregunta
        // de la §53: `images-users/cambiar-imagen-oficial/{user_id}` lleva
        // `persona.propia` desde la revisión de IDOR y el guard leía el `user_id`
        // de la URL —suyo— sin ver la imagen que proponía el cuerpo. Medido: un
        // alumno dejaba la imagen de un superusuario en su propio pedido de foto
        // oficial, y un administrador que lo acepte se la pone en la ficha. Sus
        // dos hermanas del mismo controlador llaman `imagen_id` a este mismo
        // dato, así que la asimetría era del vocabulario y no del criterio.
        //
        // La lista lleva tres nombres para una sola cosa y ése es el aviso: en
        // este repo, un identificador con un nombre nuevo es un guard ciego.
        'imagen_id', 'img_id', 'foto_id',
    ];

    /**
     * @param  string|null  $como  Qué significa el segmento genérico `{id}` de la URL:
     *                             `user_id`, `persona_id`... Varias rutas de perfiles
     *                             llaman `id` a la persona, y sin decir a qué tabla
     *                             apunta no hay forma de saber de quién es.
     *
     *                             El valor especial **`username`** es otra cosa: dice
     *                             que la ruta nombra a la persona por su nombre de
     *                             usuario y no por un id. Se resuelve contra `users` y
     *                             se comprueba como si fuera `user_id`. Va aparte
     *                             —y no en `CLAVES`— porque un `username` en el cuerpo
     *                             de una petición suele ser el nombre que se quiere
     *                             PONER, no la persona a la que se apunta: mirarlo en
     *                             todas las rutas convertiría un renombrado legítimo
     *                             en un 403.
     */
    public function handle(Request $request, Closure $next, ?string $como = null)
    {
        $usuario = User::fromToken(false, $request);

        if ($usuario->tipo !== 'Alumno' && $usuario->tipo !== 'Acudiente') {
            return $next($request);
        }

        if (! $this->tipoDeclaradoEsElSuyo($usuario, $request)) {
            $this->anotar($usuario, 'tipo', 0);

            abort(403, 'Solo puedes consultar lo tuyo');
        }

        foreach ($this->identificadoresPedidos($request, $como) as $clave => $valor) {
            if (! $this->esSuyo($usuario, $clave, $valor)) {
                $this->anotar($usuario, $clave, $valor);

                abort(403, 'Solo puedes consultar lo tuyo');
            }
        }

        return $next($request);
    }

    /**
     * Los identificadores de persona que trae la petición, vengan por donde vengan.
     *
     * Se miran el cuerpo, la query y los segmentos de la URL, y también la lista
     * `requested_alumnos`, que es como piden el alumno los informes. Un valor
     * vacío o ausente no cuenta: no es que pidan a nadie, es que no piden.
     */
    private function identificadoresPedidos(Request $peticion, ?string $como): array
    {
        $pedidos = [];

        // El `{id}` genérico, cuando la ruta ha dicho a qué apunta.
        if ($como !== null && $como !== 'username' && ($generico = $peticion->route('id')) !== null) {
            $pedidos[$como] = (int) $generico;
        }

        // La persona nombrada por su username, cuando la ruta lo declara.
        if ($como === 'username') {
            $nombre = $peticion->route('username');

            if (is_string($nombre) && $nombre !== '') {
                $dueno = $this->usuarioLlamado($nombre);

                // Un username que no existe no nombra a nadie, así que no hay
                // nada que proteger: la ruta contestará lo que conteste —hoy,
                // un array vacío— y eso no es de este guard.
                if ($dueno !== null) {
                    $pedidos['user_id'] = $dueno;
                }
            }
        }

        foreach (self::CLAVES as $clave) {
            $valor = $peticion->input($clave) ?? $peticion->route($clave);

            if ($valor !== null && $valor !== '' && is_scalar($valor)) {
                $pedidos[$clave] = (int) $valor;
            }
        }

        $lista = $peticion->input('requested_alumnos');

        if (is_array($lista)) {
            foreach ($lista as $i => $pedido) {
                if (isset($pedido['alumno_id'])) {
                    $pedidos['requested_alumnos.'.$i.'.alumno_id'] = (int) $pedido['alumno_id'];
                }
            }
        }

        return $pedidos;
    }

    /**
     * Si la petición declara un `tipo`, tiene que ser el suyo.
     *
     * `PUT perfiles/update/{id}` elige la TABLA con el `tipo` que manda el cliente:
     * con `tipo=Profesor` busca en `profesores`, con `tipo=Alumno` en `alumnos`. O
     * sea que comprobar solo el id no basta — un alumno con id 460 podría editar al
     * profesor 460 diciendo que es profesor. Comprobar las dos cosas sí basta, y es
     * lo único de este guard que sabe de un endpoint concreto: se deja porque la
     * alternativa es dejar el agujero abierto hasta el refactor de permisos.
     *
     * `Ac` es como llama el frontend al acudiente en ese endpoint.
     */
    private function tipoDeclaradoEsElSuyo(object $usuario, Request $peticion): bool
    {
        $tipo = $peticion->input('tipo');

        if ($tipo === null || $tipo === '') {
            return true;
        }

        return $usuario->tipo === 'Alumno'
            ? $tipo === 'Alumno'
            : in_array($tipo, ['Ac', 'Acudiente'], true);
    }

    private function esSuyo(object $usuario, string $clave, int $valor): bool
    {
        $clave = str_contains($clave, 'alumno_id') ? 'alumno_id' : $clave;
        $clave = in_array($clave, ['img_id', 'foto_id'], true) ? 'imagen_id' : $clave;

        if ($usuario->tipo === 'Alumno') {
            return match ($clave) {
                'alumno_id', 'persona_id' => $valor === (int) $usuario->persona_id,
                'user_id' => $valor === (int) $usuario->user_id,
                'matricula_id' => $this->matriculaDe($valor) === (int) $usuario->persona_id,
                'imagen_id' => $this->duenoDeLaImagen($valor) === (int) $usuario->user_id,
                // Un alumno no tiene por qué nombrar a un acudiente ni a un
                // profesor: si lo hace, es que está pidiendo por otro.
                default => false,
            };
        }

        $acudidos = $this->acudidos((int) $usuario->persona_id);

        return match ($clave) {
            'alumno_id' => in_array($valor, $acudidos, true),
            'acudiente_id', 'persona_id' => $valor === (int) $usuario->persona_id
                || in_array($valor, $acudidos, true),
            'user_id' => $valor === (int) $usuario->user_id
                || in_array($valor, $this->usuariosDe($acudidos), true),
            'matricula_id' => in_array((int) $this->matriculaDe($valor), $acudidos, true),
            'imagen_id' => $this->duenoDeLaImagen($valor) === (int) $usuario->user_id
                || in_array((int) $this->duenoDeLaImagen($valor), $this->usuariosDe($acudidos), true),
            default => false,
        };
    }

    /**
     * El id de la cuenta que se llama así, si existe.
     *
     * La comparación la hace MySQL con la colación de la columna, que es
     * `utf8mb4_unicode_ci`: **ignora mayúsculas y tildes**, así que `maria.beleno`
     * resuelve a la cuenta de `maria.beleño`. Es a propósito que sea la misma
     * regla que usa el login, y no una más estricta: si con ese nombre se entra,
     * con ese nombre se comprueba.
     */
    private function usuarioLlamado(string $nombre): ?int
    {
        $fila = DB::selectOne(
            'SELECT id FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1',
            [$nombre]
        );

        return $fila === null ? null : (int) $fila->id;
    }

    /** Los alumnos de los que es acudiente. */
    private function acudidos(int $acudienteId): array
    {
        $filas = DB::select(
            'SELECT alumno_id FROM parentescos WHERE acudiente_id = ? AND deleted_at IS NULL',
            [$acudienteId]
        );

        return array_map(fn ($f) => (int) $f->alumno_id, $filas);
    }

    /** Las cuentas de esos alumnos, para las rutas que piden por `user_id`. */
    private function usuariosDe(array $alumnos): array
    {
        if ($alumnos === []) {
            return [];
        }

        $filas = DB::select(
            'SELECT user_id FROM alumnos WHERE id IN ('.implode(',', array_fill(0, count($alumnos), '?')).')
             AND user_id IS NOT NULL AND deleted_at IS NULL',
            $alumnos
        );

        return array_map(fn ($f) => (int) $f->user_id, $filas);
    }

    /**
     * De quién es una imagen.
     *
     * `images.user_id` es nullable —las imágenes públicas del colegio no son de
     * nadie— y `null` no es de quien pregunta, así que rotar o publicar una imagen
     * sin dueño queda para el personal. Es lo correcto: una imagen sin dueño es
     * del colegio.
     */
    private function duenoDeLaImagen(int $imagenId): ?int
    {
        $fila = DB::selectOne('SELECT user_id FROM images WHERE id = ?', [$imagenId]);

        return $fila === null || $fila->user_id === null ? null : (int) $fila->user_id;
    }

    /** De quién es una matrícula. Sin `deleted_at`: una matrícula borrada sigue siendo de alguien. */
    private function matriculaDe(int $matriculaId): ?int
    {
        $fila = DB::selectOne('SELECT alumno_id FROM matriculas WHERE id = ?', [$matriculaId]);

        return $fila === null ? null : (int) $fila->alumno_id;
    }

    /**
     * Deja constancia, igual que `ExigirBoletinPropio`.
     *
     * Es el rastro que mira el colegio cuando alguien reclama, y aquí importa más
     * que en los boletines: esto rechaza también intentos de ESCRITURA sobre otra
     * persona.
     */
    private function anotar(object $usuario, string $clave, int $valor): void
    {
        $historial = DB::select(
            'SELECT id FROM historiales WHERE user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
            [$usuario->user_id]
        );

        DB::insert(
            'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type,
                affected_element_type, created_at)
             VALUES (?, ?, ?, "Al", ?, ?)',
            [
                $usuario->user_id,
                $historial[0]->id ?? null,
                $valor,
                mb_substr($usuario->tipo.'PideAjeno:'.$clave, 0, 45),
                now(),
            ]
        );
    }
}
