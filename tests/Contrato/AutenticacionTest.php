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
        ['POST',   'tardanzas/login/traer-datos-ausencias'],
        ['POST',   'tardanzas/subir'],
        ['PUT',    'tardanzas/subir/eliminar-ausencia'],
        ['PUT',    'tardanzas/subir/poner-ausencia'],
        ['PUT',    'publicaciones/ultimas'],
        ['GET',    'publicaciones/ultimas'],
        ['GET',    'colegio/logo'],

        // **La decimotercera, y la primera que RECIBE algo en vez de darlo.**
        // Quien sube el comprobante del pago del formulario es la familia de un
        // aspirante que todavía no es alumno: no tiene cuenta y no puede tenerla,
        // así que no hay token que exigir. Lo que la defiende no es un guard —es el
        // carácter de control del código, el limitador `colilla` por IP y por
        // código, la lista blanca de tipos, y sobre todo el tope de tres
        // comprobantes por orden y uno solo pendiente, que es lo único que no se
        // reinicia con el reloj. Doc 41 §5; autorizada por Joseth el 19 sep 2026.
        ['POST',   'colillas-inscripcion/{codigo}'],

        // **La decimosexta (20 sep 2026), y la PRIMERA DE LECTURA de este módulo.**
        // Hasta ella, las tres públicas del formulario eran las tres de escritura: la
        // familia mandaba su comprobante y **no tenía forma de saber si se lo
        // aprobaron, se lo rechazaron ni por qué**. El motivo del rechazo ya se
        // guardaba —`putRechazar` lo exige— y sólo lo veía el personal.
        //
        // No espera al correo a propósito: el aviso que debía cerrarlo va por correo,
        // y `lalvirtual.com` —el `MAIL_FROM_ADDRESS` de quince colegios— **no está
        // registrado** desde el 2 sep y falla callado; además sólo el 9,2 % de los
        // acudientes vivos tiene correo (doc 42). Es *pull* en vez de *push*, y para
        // quien no tiene cuenta es el único canal que funciona seguro.
        //
        // **Lo que la hace aceptable no es el limitador: es lo que NO devuelve.** La
        // llave es un código que se dicta por teléfono y viaja en un papel que pasa
        // de mano en mano, así que no salen el nombre del alumno, su documento, sus
        // teléfonos ni el fichero del recibo —la URL es la llave—. Sale el trámite,
        // no la persona, y lo fija `LaFamiliaPreguntaTest` buscando el dato en el
        // JSON entero, no campo a campo. Doc 41 §10.
        ['GET',    'colillas-inscripcion/{codigo}'],

        // **La decimocuarta y la decimoquinta: el pago en línea del mismo
        // formulario.** La primera la abre la familia —el mismo aspirante sin
        // cuenta de la de arriba— y la segunda **no la llama una persona**: la
        // llama el servidor de la pasarela, así que no hay ninguna sesión que
        // exigir y ningún token que pudiera existir.
        //
        // Lo que defiende al webhook no es un guard y el orden importa: primero
        // la referencia se busca en `pagos_inscripcion` —una consulta indexada
        // que descarta lo ajeno **sin salir a internet**—, después la firma del
        // evento, que es obligatoria, y sólo entonces la reconsulta a la pasarela
        // con la llave privada, si el colegio la dio. Al checkout lo acota el
        // carácter de control del código, el limitador por IP y por código, y el
        // tope de diez intentos por orden, que es de la fila y no del reloj.
        //
        // Doc 41 §7; autorizadas por Joseth el 19 sep 2026.
        ['POST',   'pagos-inscripcion/{codigo}/checkout'],
        ['POST',   'pagos-inscripcion/webhook'],
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
