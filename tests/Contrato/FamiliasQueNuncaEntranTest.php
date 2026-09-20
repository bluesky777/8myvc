<?php

namespace Tests\Contrato;

/**
 * **Las familias que el candado de familia no mira nunca.** §151.
 *
 * `AutorizacionTest::test_ninguna_ruta_se_queda_sola_sin_el_guard_de_su_familia`
 * es el candado que encontró los cinco agujeros de la §16, y tiene **dos puertas
 * de salida**:
 *
 * ```php
 * if ($conGuard < 2 || $sinGuard > max(2, intdiv(count($rutas), 4))) {
 *     continue;
 * }
 * ```
 *
 * La segunda —«esta familia está mayoritariamente abierta»— la declaró el §114 y
 * vive en un snapshot. **La primera no la declara nadie**: una familia con menos
 * de dos hermanas con guard **no entra nunca**, y el `continue` que la descarta
 * no deja rastro en ninguna parte.
 *
 * > Una familia **se sale** del candado por tener demasiadas puertas abiertas, y
 * > **no entra nunca** por tener demasiadas pocas cerradas. La segunda es más
 * > silenciosa, porque no aparece en ningún sitio.
 *
 * Ahí estaba `calendario/this-year`, que decidía por el cuerpo si aplicaba
 * `solo_profes` (§150).
 *
 * ## Lo que este caso NO dice
 *
 * **«Sin guard» no es «abierta».** Ninguna de estas rutas es alcanzable sin token
 * —lo prueba `RutasPreLoginTest::test_ninguna_otra_ruta_responde_sin_token`, que
 * recorre la API entera sin cabecera— y varias comprueban por dentro: las cuatro
 * escrituras de `enfermeria/*` abortan 403 sin el rol, y las de `publicaciones/*`
 * pasan por `exigeQueLaPublicacionSeaSuya`.
 *
 * Lo que ningún mecanismo les pregunta nunca es la otra mitad: **de quién es la
 * fila que tocan**. Esta lista es el sitio donde mirar esa pregunta, no una lista
 * de fallos.
 *
 * ## Una que entró el 24 ago y contesta esa pregunta sin guard
 *
 * `notificaciones/temas` (0 de 1) entra aquí y **está bien así**, que es
 * exactamente el matiz que este caso existe para poder escribir: no lleva guard
 * de propiedad porque **no acepta ningún id**. No se le pide de quién son los
 * temas, se contesta quién eres — un alumno recibe los suyos, un acudiente los de
 * sus acudidos, el personal ninguno—, y eso lo decide el controlador con el
 * `tipo` del token. Ponerle `boletin.propio` sería pedirle que comprobara un
 * `alumno_id` que la ruta no tiene.
 *
 * Lo fija `TemasDeNotificacionTest`, que comprueba que cada quien recibe **sólo
 * lo suyo** contando sus acudidos y comparando.
 *
 * ## Y otra del 1 sep 2026, por el motivo contrario: `colegio` (0 de 1)
 *
 * `GET colegio/logo` es **pública** —la duodécima, `RutasPreLoginTest::TOTAL_PUBLICAS`—,
 * así que no es que el candado no la mire: es que **no hay nada que mirar**. No acepta
 * ningún identificador, no lee la sesión (no hay), no escribe y devuelve un nombre de
 * fichero que el servidor web ya servía sin sesión. La pregunta que esta lista existe
 * para hacer —«¿de quién es la fila que toca?»— no tiene sujeto aquí.
 *
 * Entra igualmente en el censo, y eso es lo correcto: **una familia de una sola ruta
 * sin guard es exactamente la forma que tendría un agujero nuevo**, y la única
 * diferencia entre ésta y aquél es que ésta tiene su motivo escrito. Si mañana
 * `colegio/*` crece con una ruta que sí acepte un id, este renglón pasa a decir «0 de
 * 2» y hay que volver aquí.
 */
class FamiliasQueNuncaEntranTest extends CasoDeContrato
{
    /** Los mismos tres guards que mira el candado de familia. */
    private const GUARDS = ['auth.personal', 'persona.propia', 'boletin.propio'];

    /** Se defienden solas y antes del login: no cuentan como escrituras sin guard. */
    private const PRE_LOGIN = ['login', 'auth'];

    /** @return array<string, list<array{clave: string, guardada: bool, escribe: bool}>> */
    private function familias(): array
    {
        $familias = [];

        foreach (app('router')->getRoutes()->getRoutes() as $ruta) {
            $uri = $ruta->uri();

            if (! str_starts_with($uri, 'api/') || substr_count($uri, '/') < 1) {
                continue;
            }

            $guardada = false;

            foreach ($ruta->gatherMiddleware() as $m) {
                if (in_array(explode(':', (string) $m)[0], self::GUARDS, true)) {
                    $guardada = true;
                }
            }

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $familias[explode('/', $uri)[1]][] = [
                    'clave' => $verbo.' '.$uri,
                    'guardada' => $guardada,
                    'escribe' => in_array($verbo, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
                ];
            }
        }

        return $familias;
    }

    /**
     * **Cuáles son, con cuántas de sus rutas llevan guard.**
     *
     * Se guarda la lista y no sólo el número: un 18 que sigue siendo 18 porque una
     * familia entró y otra nació no diría nada, y es justo el movimiento que hay
     * que ver.
     */
    public function test_las_familias_que_nunca_entran_en_el_candado(): void
    {
        $nuncaEntran = [];

        foreach ($this->familias() as $prefijo => $rutas) {
            $conGuard = count(array_filter($rutas, fn ($r) => $r['guardada']));

            if ($conGuard < 2) {
                $nuncaEntran[$prefijo] = $conGuard.' de '.count($rutas);
            }
        }

        ksort($nuncaEntran);

        $this->compararConInstantanea('familias-que-nunca-entran-en-el-candado', $nuncaEntran);
    }

    /**
     * **Y cuántas escrituras hay dentro**, que es lo que mide el tamaño del hueco.
     *
     * `login/*` y `auth/*` quedan fuera: son pre-login por diseño y las fija
     * `RutasPreLoginTest`.
     *
     * El número está escrito para que **suba con ruido**: una ruta nueva de
     * escritura en una familia con menos de dos guards no la mira ningún candado
     * y aquí sí se ve.
     *
     * **Y se cuenta por el VERBO, que no es la operación.** Al menos **cuatro** de
     * las 26 sólo leen: `PUT api/publicaciones/ultimas` es el formulario público de
     * prematrícula —va por PUT desde 2024 y `RutasPreLoginTest` lo explica—,
     * `POST api/tardanzas/login/traer-datos-ausencias` trae datos, como dice su
     * nombre —su hermana `…/traer-datos` estaba en esta misma lista hasta que Joseth
     * la mandó retirar el 2 sep 2026—, y desde el 19 sep 2026 **`PUT
     * api/calendario/mes` y `PUT api/calendario/proximos`**, que leen el calendario
     * del mes y del futuro próximo. Se quedan dentro **a propósito**: quitarlas a
     * mano convertiría un recuento mecánico en una lista curada, y entonces el
     * día que una de ellas empiece a escribir de verdad no lo diría nadie. Lo que
     * hace falta es que esté escrito aquí, no que el número mienta menos.
     *
     * ## Las dos del calendario: por qué SUBEN el número y aun así están bien
     *
     * El aviso de abajo dice que si sube *«hay una ruta nueva que ningún
     * mecanismo va a preguntar de quién es la fila que toca»*, y de estas dos
     * **eso es falso, pero no por donde parece**. No es que tengan guard —no lo
     * tienen, y no deben tenerlo: el calendario del colegio es de todo el mundo,
     * igual que `calendario/this-year`—. Es que **preguntan de quién es cada fila
     * dentro del método**, con el token: un evento que no le toca a quien pregunta
     * no viaja. Lo fija `CalendarioMesTest`, caso a caso y por tipo de usuario.
     *
     * O sea que la familia `calendario/*` sigue teniendo **0 de 7** con guard y
     * sigue sin entrar nunca en el candado de familia, que es exactamente lo que
     * este centinela existe para dejar por escrito. Lo que cambia es que ahora
     * dos de esas siete **sí** tienen quien les pregunte, y son las únicas.
     * `crear-evento`, `guardar-evento` y `eliminar-evento` siguen defendiéndose
     * con su propio `if` de personal, y `sincronizar-cumples` también.
     */
    public function test_cuantas_escrituras_viven_donde_el_candado_no_llega(): void
    {
        $escrituras = [];

        foreach ($this->familias() as $prefijo => $rutas) {
            if (in_array($prefijo, self::PRE_LOGIN, true)) {
                continue;
            }

            $conGuard = count(array_filter($rutas, fn ($r) => $r['guardada']));

            if ($conGuard >= 2) {
                continue;
            }

            foreach ($rutas as $r) {
                if ($r['escribe'] && ! $r['guardada']) {
                    $escrituras[] = $r['clave'];
                }
            }
        }

        sort($escrituras);

        // **24 desde el 19 sep 2026, y esta vez SUBIÓ**, que es la dirección que el
        // mensaje de abajo llama sospechosa. Lo son las dos de `pagos-inscripcion`
        // —el checkout y el webhook del pago en línea del formulario—, y se aceptan
        // aquí con el motivo escrito en vez de callarlas metiendo la familia en
        // `PRE_LOGIN`, que es el atajo que había a mano.
        //
        // Lo que este candado pregunta es *«¿algún mecanismo comprueba de quién es la
        // fila que toca?»*, y en las dos la respuesta es **sí, pero no es un guard**:
        // es que **la llave es el dato**. Al checkout hay que traerle un código de
        // formulario que valida su propio carácter de control, y al webhook una
        // referencia de 64 caracteres que acuñamos nosotros y que no está publicada en
        // ninguna parte. Ninguno de los dos puede tocar la fila de otro sin saberse su
        // secreto, que es exactamente lo que un guard de propiedad garantiza por otro
        // camino — el mismo argumento que la colilla, y la colilla no cuenta aquí sólo
        // porque sus tres hermanas llevan guard y esta familia no tiene ninguna.
        //
        // **La comparación que importa**: si un día estas dos aceptaran un `orden_id`
        // suelto en el cuerpo en vez del código, seguirían contando 24 y ya serían un
        // agujero. Por eso el número no es la garantía; el porqué de cada renglón sí.
        //
        // **26 desde el 19 sep 2026**, al fundir `feat/calendario`: las dos de arriba
        // —`PUT calendario/mes` y `PUT calendario/proximos`—, cuyo porqué está en el
        // docblock. Son el tercer caso seguido en que este número SUBE y el renglón
        // está bien, y los tres por motivos distintos: el pago lleva la llave en el
        // dato, y éstas **preguntan de quién es la fila dentro del método**. Lo que
        // no ha aparecido todavía es el caso que este centinela busca de verdad —una
        // ruta que no pregunte nada—, y por eso el número se sube a mano y con el
        // motivo escrito cada vez, en vez de regenerarlo.
        //
        // (Antes eran 22, desde el 2 sep 2026, y aquella vez bajó por la razón buena:
        // Joseth mandó retirar `POST tardanzas/login/traer-datos`. Y 24 el 19 sep, con
        // las dos de `pagos-inscripcion`.)
        $this->assertCount(26, $escrituras,
            "Cambió cuántas escrituras viven en familias que el candado de familia no mira nunca.\n".
            "Si SUBIÓ, hay una ruta nueva que ningún mecanismo va a preguntar de quién es la fila que toca.\n".
            "Si BAJÓ, alguien puso un guard o la familia llegó a dos hermanas guardadas y entró en el candado: bien, y hay que actualizar el número.\n".
            'Ver docs/migracion/noche-2026-08-23/q.md §151.');

        $this->compararConInstantanea('escrituras-donde-el-candado-no-llega', $escrituras);
    }
}
