<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/**
 * La API exige token en todas sus rutas menos en diecinueve, y esas diecinueve son
 * estas.
 *
 * (El docblock decía **quince** con la lista en dieciocho, y la lista es la que el
 * test compara contra el router: la que estaba mal era la frase. Corregido el 1 sep
 * 2026 al entrar la diecinueve, contando la constante y no la memoria. Es el mismo
 * fallo que `CLAUDE.md` documenta con tres cifras, y aquí volvió a pasar porque
 * **este número no lo comprueba nadie**: el test ata la LISTA, no la frase.)
 *
 * Antes el guard iba ruta por ruta: 88 rutas con `->middleware('auth.token')` y
 * las otras 445 confiando en que su método llamara a `User::fromToken()`. Eso no
 * se sostiene. Al sacar esa llamada de los constructores aparecieron tres
 * métodos que devuelven antes de leer `$this->user` —`acudientes/datos`,
 * `alumnos/personas-check` y `prematriculas/alumnos-con-grado-anterior`— y las
 * tres quedaron abiertas sin que nadie hubiera tocado el archivo de rutas.
 *
 * Ahora el guard se aplica en grupo a toda la API (`routes/api.php`) y las
 * excepciones se marcan una a una con `->withoutMiddleware('auth.token')`. Este
 * test fija esa lista: una ruta nueva no puede quedar abierta por descuido, y
 * abrirla a propósito obliga a pasar por aquí.
 */
class AutenticacionTest extends CasoDeContrato
{
    /**
     * Las únicas rutas de la API que no exigen token. Dos motivos distintos, y
     * conviene no confundirlos:
     *
     *   - Las nueve de `login/*` y `publicaciones/ultimas` son la entrada al
     *     sistema: el frontend las llama sin sesión. Ver RutasPreLoginTest, que
     *     además comprueba que ninguna responda 401.
     *   - Las seis de `tardanzas/*` **sí autentican**, pero no con token: el
     *     lector manda usuario y contraseña en el cuerpo de CADA petición y el
     *     método las verifica con `App\Support\Credenciales`. No son públicas;
     *     el guard de token las cerraría igual y el lector no podría entrar.
     *   - Las tres de `auth/*` son de la Fase 3, y cada una tiene su motivo
     *     escrito al lado de la ruta en routes/api/auth.php. En corto: entrar
     *     no requiere estar dentro; refrescar se hace justo cuando el token de
     *     acceso ya no vale; y salir tiene que funcionar con el token vencido.
     *     `auth/refresh` sí responde 401 sin token — no está en la lista de
     *     RutasPreLoginTest, que es la de pantallas previas al login.
     *   - `colegio/logo` es la última, del 1 sep 2026, y es la única que no va del
     *     login ni del lector de tardanzas: la pantalla de entrada no tiene token,
     *     así que no puede pedir `GET years`, y el colegio que cambiaba su logo
     *     dentro seguía enseñando el viejo en su propia puerta. **Decisión de
     *     Joseth**, con la exposición medida antes (§245 del 05): el fichero ya se
     *     descargaba sin sesión desde `public/images/perfil/`, y la ruta solo dice
     *     cuál de ellos es. No acepta ningún identificador y no escribe nada.
     */
    private const SIN_GUARD = [
        ['POST',   'auth/login'],
        ['POST',   'auth/refresh'],
        ['POST',   'auth/logout'],
        ['POST',   'login'],
        ['PUT',    'login/crear-prematricula'],
        ['POST',   'login/credentials'],
        ['PUT',    'login/logout'],
        ['PUT',    'login/reset-password'],
        ['POST',   'login/recuperar-clave'],
        ['POST',   'login/ver-pass'],
        ['POST',   'tardanzas/login'],
        ['POST',   'tardanzas/login/traer-datos'],
        ['POST',   'tardanzas/login/traer-datos-ausencias'],
        ['POST',   'tardanzas/subir'],
        ['PUT',    'tardanzas/subir/eliminar-ausencia'],
        ['PUT',    'tardanzas/subir/poner-ausencia'],
        ['PUT',    'publicaciones/ultimas'],
        ['GET',    'publicaciones/ultimas'],
        ['GET',    'colegio/logo'],
    ];

    /**
     * Verbo + URI de cada ruta de la API, separadas en las que exigen token y
     * las que no.
     *
     * @return array{0: array<int, array{0: string, 1: string}>, 1: array<int, string>}
     */
    private function rutasPorGuard(): array
    {
        $conGuard = [];
        $sinGuard = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/')) {
                continue;
            }

            foreach ($ruta->methods() as $verbo) {
                if ($verbo === 'HEAD') {
                    continue;
                }

                if ($this->exigeToken($ruta)) {
                    // Los {parametros} se rellenan con un valor cualquiera: el
                    // guard corre antes que el controlador, así que da igual cuál.
                    $conGuard[] = [$verbo, '/'.preg_replace('/\{[^}]+\}/', '1', $ruta->uri())];
                } else {
                    $sinGuard[] = $verbo.' '.substr($ruta->uri(), strlen('api/'));
                }
            }
        }

        sort($sinGuard);

        return [$conGuard, $sinGuard];
    }

    public function test_solo_estas_rutas_no_exigen_token(): void
    {
        $esperadas = array_map(fn ($r) => $r[0].' '.$r[1], self::SIN_GUARD);
        sort($esperadas);

        [, $sinGuard] = $this->rutasPorGuard();

        $this->assertSame($esperadas, $sinGuard,
            "Cambió la lista de rutas que no exigen token.\n".
            "Si sobra alguna, es un agujero. Si falta alguna, se rompió la entrada al sistema\n".
            'o el lector de tardanzas. Cualquiera de las dos cosas se justifica aquí, en el '.
            "docblock de SIN_GUARD,\ny se regenera la auditoría con tools/auditar-autenticacion.php");
    }

    /**
     * El guard está puesto Y funciona.
     *
     * Que la ruta lleve el middleware en la tabla no prueba que rechace: podría
     * responder 500 antes, o 200 si el middleware no llega a correr. Aquí se
     * comprueba el resultado, ruta por ruta.
     */
    public function test_sin_token_todas_las_demas_responden_401(): void
    {
        // La API lleva un limitador global de 60 peticiones por minuto
        // (`throttle:api`). Este test recorre las 518 rutas de una tacada, así
        // que a partir de la 60 recibiría 429 en vez de 401 y estaríamos
        // comprobando el limitador, no el guard.
        $this->withoutMiddleware(ThrottleRequests::class);

        [$conGuard] = $this->rutasPorGuard();

        $fallos = [];

        foreach ($conGuard as [$verbo, $uri]) {
            // getStatusCode() y no status(): varias de estas rutas devuelven un
            // fichero (BinaryFileResponse, los exportadores a Excel), y ese tipo
            // de respuesta no tiene status().
            $codigo = $this->json($verbo, $uri)->getStatusCode();

            if ($codigo !== 401) {
                $fallos[] = sprintf('%-7s %-52s devolvió %d', $verbo, $uri, $codigo);
            }
        }

        $this->assertSame([], $fallos,
            "Estas rutas deberían rechazar con 401 a quien no presenta token:\n".
            implode("\n", $fallos));
    }

    /**
     * El guard no rechaza a un usuario legítimo, sea del tipo que sea.
     *
     * Antes esto recorría las 88 rutas a las que se les había puesto el guard a
     * mano, porque cada una podía haberse roto por su cuenta. Ya no: el guard es
     * un único middleware aplicado en grupo, así que lo que puede fallar no es
     * la ruta sino el usuario — `User::fromToken()` aborta con 400 para quien no
     * tenga contexto resoluble (ficha, matrícula, grupo, periodo del año que
     * corresponde). Por eso se recorren los cuatro tipos, no las rutas.
     */
    public function test_con_token_valido_el_guard_deja_pasar(): void
    {
        $rechazadas = [];

        foreach (['Alumno', 'Profesor', 'Acudiente', 'Usuario'] as $tipo) {
            $usuario = $this->usuarioDeTipo($tipo);
            $token = $this->tokenDe($usuario->username);

            $codigo = $this->getJson('/api/ciudades', ['Authorization' => 'Bearer '.$token])
                ->getStatusCode();

            if ($codigo === 401) {
                $rechazadas[] = $tipo;
            }
        }

        $this->assertSame([], $rechazadas,
            'El guard rechaza a un usuario con token válido de tipo: '.implode(', ', $rechazadas));
    }
}
