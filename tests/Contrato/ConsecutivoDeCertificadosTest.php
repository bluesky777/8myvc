<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/**
 * El consecutivo de los certificados: **la red del arreglo, y ya en verde.**
 *
 * Nacieron **rojos a proposito** —el punto 1 de la lista de la manana de Joseth,
 * [05 §195](../../docs/migracion/05-codigo-muerto-y-roto.md) y [§225](../../docs/migracion/05-codigo-muerto-y-roto.md)—
 * para que la decision se tomara delante de una prueba y no de un parrafo. El
 * arreglo entro la noche del 25 (`docs/migracion/noche-2026-08-25/cert-1.md`), asi
 * que **se les quito `#[Group('rojo')]` y pasaron a la suite**. Ese paso es lo que
 * los convierte en la red y no en una queja archivada.
 *
 * ## Lo que vigila cada uno
 *
 * 1. **la carrera** — que la lectura del consecutivo salga con `FOR UPDATE` y
 *    dentro de la transaccion del controlador;
 * 2. **la consecuencia** — dos aperturas seguidas, dos numeros distintos;
 * 3. **por que hace falta** — el patron sin bloqueo, ejecutado a mano, pierde un
 *    incremento. **Ese ejecuta su propia copia y no el codigo**, y lo dice en su
 *    primera linea;
 * 4. **`cambiar-contador-certificados` sin validacion**, y **5.** su hermano de
 *    folios, **que no habia nombrado nadie**: la lista de la manana habla solo de
 *    certificados. Van como dos tests y no como un `dataProvider` a proposito —
 *    si manana se arregla uno solo, esto tiene que decir **cual falta**;
 * 6. **que la validacion no rompa el caso bueno** — la pantalla manda `'007'`, y
 *    la primera version la habria rechazado con 422;
 * 7. **que valores queman un folio y cuales no**, valor por valor.
 *
 * ## El que estaba aqui y no podia existir
 *
 * El test original de la carrera ejecutaba el `SELECT` y los dos `UPDATE` **crudos,
 * copiados a mano desde el controlador**, y afirmaba que subian 2. Era el
 * instrumento correcto sobre el objeto equivocado: **no llamaba al endpoint, asi
 * que ningun arreglo del controlador podia ponerlo en verde** — y copiarle el
 * `FOR UPDATE` tampoco, porque `CasoDeContrato` usa `DatabaseTransactions`, o sea
 * **una sola conexion**, y un `FOR UPDATE` no se bloquea contra si mismo.
 *
 * Queda escrito aqui para que nadie lo vuelva a intentar dentro de dos meses:
 * **un rojo que no puede volverse verde no es una red, es un parrafo con
 * parentesis** — y el sitio donde eso se detecta es **preguntando que objeto mide
 * el test**, no si el test pasa.
 */
class ConsecutivoDeCertificadosTest extends CasoDeContrato
{
    /**
     * La lectura del consecutivo sale **dentro de transaccion y con `FOR UPDATE`**.
     *
     * ## Por que este test no es el que habia aqui
     *
     * El que habia ejecutaba el `SELECT` y los dos `UPDATE` **crudos, copiados a
     * mano desde el controlador**, dentro del propio test: leer A, leer B, escribir
     * A, escribir B. El intercalado era real y el mensaje que imprimia era cierto
     * —dos aperturas gastando un solo numero son dos folios oficiales con el mismo
     * consecutivo— pero **medía su propia copia del patron, no el codigo**. Poner
     * `FOR UPDATE` en `BolfinalesController` lo dejaba **exactamente igual de
     * rojo**, y aunque se le hubiera copiado tambien el `FOR UPDATE` tampoco
     * pasaria: `CasoDeContrato` usa `DatabaseTransactions`, o sea **una sola
     * conexion**, y un `FOR UPDATE` no se bloquea contra si mismo.
     *
     * Era **el instrumento correcto sobre el objeto equivocado**: un rojo que no
     * podia volverse verde lo arreglara quien lo arreglara, o sea **no una red**.
     *
     * ## Lo que mira este, que si distingue el antes del despues
     *
     * Llama al endpoint **de verdad** y escucha con `DB::listen` la consulta que
     * lee el contador. Dos cosas, y **cada una se puede cumplir sin la otra**:
     *
     *   1. que la consulta lleve `FOR UPDATE` —sin el, las dos lecturas ven el
     *      mismo valor y un incremento se pierde—;
     *   2. que se emita con `transactionLevel() > 1` — el 1 es la transaccion del
     *      propio test; **el segundo nivel es el que abre el controlador**. Un
     *      `FOR UPDATE` fuera de transaccion suelta el bloqueo al acabar la
     *      sentencia y no protege nada.
     *
     * No depende de tiempos ni del planificador, asi que no puede fallar «a
     * veces» — y un test que falla a veces se acaba desactivando.
     *
     * ## Y el matiz honesto, que va aqui y no en una nota al pie
     *
     * **Esto afirma sobre el MECANISMO, no sobre el resultado.** El resultado que
     * de verdad importa —*«dos peticiones simultaneas no repiten numero»*— **no es
     * observable en esta suite**, porque `DatabaseTransactions` da **una sola
     * conexion** y la exclusion mutua necesita dos. Lo que este test garantiza es
     * que el bloqueo esta pedido y en el sitio correcto; que MySQL lo respeta es
     * cosa de MySQL.
     *
     * Se escribe asi a proposito: **un guardian que promete mas de lo que puede ver
     * es justo lo que este fichero acaba de quitar de en medio.**
     */
    public function test_el_consecutivo_se_lee_bloqueado_y_dentro_de_transaccion(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $lecturas = [];

        // Solo la consulta que nos importa: esta peticion emite miles (05 §210).
        DB::listen(function ($consulta) use (&$lecturas) {
            if (stripos($consulta->sql, 'contador_certificados') !== false
                && stripos($consulta->sql, 'select') !== false) {
                $lecturas[] = [
                    'sql' => $consulta->sql,
                    'nivel' => DB::transactionLevel(),
                ];
            }
        });

        $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => true],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertNotEmpty($lecturas,
            'El endpoint no leyo `contador_certificados`: este test no esta midiendo el '
            .'incremento, asi que un verde suyo no diria nada.');

        $conBloqueo = array_values(array_filter($lecturas,
            fn ($l) => stripos($l['sql'], 'for update') !== false));

        $this->assertNotEmpty($conBloqueo,
            'La lectura del consecutivo salio SIN `FOR UPDATE`: '.$lecturas[0]['sql'].' — dos '
            .'secretarias abriendo el certificado a la vez leen el mismo numero y escriben el '
            .'mismo numero, y eso son DOS CERTIFICADOS CON EL MISMO CONSECUTIVO.');

        $this->assertGreaterThan(1, $conBloqueo[0]['nivel'],
            'La lectura lleva `FOR UPDATE` pero se emitio en el nivel de transaccion '
            .$conBloqueo[0]['nivel'].', que es el del propio test: el controlador no abrio la '
            .'suya. Un `FOR UPDATE` sin transaccion suelta el bloqueo al acabar la sentencia y '
            .'la carrera sigue abierta.');
    }

    /**
     * Y el viaje de ida y vuelta: **dos aperturas, dos numeros distintos**.
     *
     * El test de arriba comprueba el mecanismo; este comprueba la consecuencia que
     * le importa a secretaria, llamando al endpoint dos veces. No demuestra la
     * exclusion mutua —para eso harian falta dos conexiones de verdad, y esta suite
     * tiene una— pero **si cae si alguien rompe el incremento** al tocar la
     * transaccion nueva, que es justo lo que un arreglo puede romper sin querer.
     */
    public function test_dos_aperturas_seguidas_gastan_dos_numeros_distintos(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $lee = fn () => (int) DB::selectOne(
            'SELECT contador_certificados FROM years WHERE deleted_at is null and actual=1'
        )->contador_certificados;

        $antes = $lee();

        for ($i = 0; $i < 2; $i++) {
            $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
                ['aumentar_contador' => true],
                ['Authorization' => 'Bearer '.$token])->assertStatus(200);
        }

        $this->assertSame($antes + 2, $lee(),
            'Dos aperturas del certificado dejaron el consecutivo en '.$lee().' partiendo de '
            .$antes.'. Tienen que gastar un numero cada una: un numero saltado se justifica '
            .'ante quien reclama, uno repetido no.');
    }

    /**
     * **Este test ejecuta su propia copia del patron, NO el codigo de produccion.**
     *
     * Esa primera linea es la mitad del test. Lo que hace es correr a mano el
     * `SELECT` y los dos `UPDATE` **sin bloqueo**, en el orden exacto que produce la
     * concurrencia real —leer A, leer B, escribir A, escribir B— y **afirmar que se
     * pierde un incremento**. O sea que documenta **la consecuencia** en lenguaje
     * ejecutable, que es mas de lo que hace un parrafo:
     *
     *     las dos leen 115 y las dos escriben 116
     *     -> DOS CERTIFICADOS CON EL MISMO CONSECUTIVO
     *
     * **Lo que NO hace es vigilar `BolfinalesController`.** Antes estaba escrito al
     * reves —afirmando que subia 2, o sea rojo— y asi **no podia volverse verde lo
     * arreglara quien lo arreglara**, porque no llamaba al endpoint. Un rojo que no
     * puede volverse verde no es una red: es un parrafo con parentesis, y ademas
     * habria dejado a los demas de esta clase fuera de la suite para siempre.
     *
     * La vigilancia del codigo esta en
     * `test_el_consecutivo_se_lee_bloqueado_y_dentro_de_transaccion`. Esto es la
     * explicacion de por que aquel importa.
     */
    public function test_el_patron_sin_bloqueo_pierde_un_incremento(): void
    {
        $lectura = 'SELECT id, contador_certificados FROM years WHERE deleted_at is null and actual=1';

        $antes = DB::select($lectura);

        $this->assertNotEmpty($antes,
            'El seed no tiene un year `actual=1`: sin eso este test no demuestra la carrera, '
            .'no demuestra nada.');

        $partida = (int) $antes[0]->contador_certificados;
        $yearId = $antes[0]->id;

        // Secretaria A abre la pantalla. Secretaria B abre la pantalla.
        $leeA = (int) DB::select($lectura)[0]->contador_certificados;
        $leeB = (int) DB::select($lectura)[0]->contador_certificados;

        // Cada una escribe lo que leyo, mas uno.
        DB::update('UPDATE years SET contador_certificados=? WHERE id=?', [$leeA + 1, $yearId]);
        DB::update('UPDATE years SET contador_certificados=? WHERE id=?', [$leeB + 1, $yearId]);

        $final = (int) DB::select($lectura)[0]->contador_certificados;

        $this->assertSame($leeA, $leeB,
            'Las dos lecturas sin bloqueo vieron valores distintos ('.$leeA.' y '.$leeB.'): '
            .'este test ya no esta reproduciendo el intercalado que pretende reproducir.');

        $this->assertSame($partida + 1, $final,
            'Dos aperturas y un solo numero gastado es lo que este test demuestra que pasa '
            .'SIN bloqueo. Si aqui salen dos, el intercalado dejo de reproducirse y quien '
            .'lea esto ya no tiene delante la consecuencia que justifica el `FOR UPDATE`.');
    }

    /**
     * `cambiar-contador-certificados` acepta cualquier cosa del cuerpo.
     *
     * `putCambiarContadorCertificados()` es **una línea**: mete
     * `Request::input('contador')` en la columna y contesta `'Cambiado'`. Sin
     * validación, sin comprobar que sea un número, y **con `auth.personal`**, que
     * en este proyecto son los 51 profesores del colegio ([05 §213](../../docs/migracion/05-codigo-muerto-y-roto.md):
     * un `Profesor` escribe hoy en 93 endpoints).
     *
     * O sea que **no es la carrera lo peor de esta familia**: la carrera necesita
     * dos personas coincidiendo, y esto lo hace una sola a propósito, o sin
     * querer con un cuerpo mal formado.
     *
     * El aserto pide **422** —el código correcto para un cuerpo que no vale— por
     * la regla de la casa: *en código nuevo se usan los códigos HTTP correctos
     * aunque el legacy de al lado devuelva 400 para todo*.
     */
    public function test_cambiar_contador_certificados_no_deberia_aceptar_lo_que_no_es_un_numero(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $antes = DB::select('SELECT contador_certificados FROM years WHERE deleted_at is null and actual=1');
        $this->assertNotEmpty($antes, 'Sin year `actual=1` este test no comprueba nada.');
        $valorPrevio = $antes[0]->contador_certificados;

        $r = $this->putJson('/api/bolfinales/cambiar-contador-certificados',
            ['contador' => 'no soy un número'],
            ['Authorization' => 'Bearer '.$token]);

        $despues = DB::select('SELECT contador_certificados FROM years WHERE deleted_at is null and actual=1')[0]
            ->contador_certificados;

        // Las dos mitades, porque cada una se puede cumplir sin la otra: un 422
        // que igualmente escribe, o un 200 que no escribe.
        $this->assertSame(422, $r->getStatusCode(),
            'Contestó '.$r->getStatusCode().' a un consecutivo que no es un número.');

        $this->assertSame($valorPrevio, $despues,
            'El consecutivo de certificados del colegio quedó en «'.$despues.'». '
            .'Cualquiera con `auth.personal` puede escribir ahí lo que quiera.');
    }

    /**
     * Y la hermana que no había nombrado nadie: **el contador de folios**.
     *
     * `putCambiarContadorFolios()` es **la misma línea sobre otra columna**, con
     * el mismo guard y la misma ausencia de validación. La lista de Joseth habla
     * sólo del de certificados; **son dos**.
     *
     * Va como test propio y no como un `dataProvider` con el otro **a propósito**:
     * si mañana se arregla uno solo, esto tiene que seguir en rojo y decir cuál
     * falta. Un caso de datos compartido daría un único fallo y se leería como
     * «el arreglo no entró».
     */
    public function test_cambiar_contador_folios_tiene_exactamente_la_misma_puerta(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();
        $this->encenderFolio();

        [$grupo, $token] = $this->grupoYPersonal();

        $antes = DB::select('SELECT contador_folios FROM years WHERE deleted_at is null and actual=1');
        $this->assertNotEmpty($antes, 'Sin year `actual=1` este test no comprueba nada.');
        $valorPrevio = $antes[0]->contador_folios;

        $r = $this->putJson('/api/bolfinales/cambiar-contador-folios',
            ['contador' => 'no soy un número'],
            ['Authorization' => 'Bearer '.$token]);

        $despues = DB::select('SELECT contador_folios FROM years WHERE deleted_at is null and actual=1')[0]
            ->contador_folios;

        $this->assertSame(422, $r->getStatusCode(),
            'Contestó '.$r->getStatusCode().' a un contador de folios que no es un número.');

        $this->assertSame($valorPrevio, $despues,
            'El contador de folios quedó en «'.$despues.'», por la misma puerta que el de certificados.');
    }

    /**
     * **La validacion no puede romper el caso bueno**, y este casi se rompe.
     *
     * `certificadoEstudioDir.html` es un `<input ng-model="year.contador_certificados">`
     * **sin `type="number"`**: AngularJS manda **la cadena tal cual la trajo el
     * backend**. Y el relleno esta ahi: en `simonbolivar_testing_e0`, **7 de los 8
     * years vivos** llevan ceros a la izquierda —`007`, `021`, `022`, `037`, `044`,
     * `045`, `060`— y el octavo es el actual, que solo se libra por haber pasado de
     * tres digitos. O sea que la pantalla que hoy funciona manda `'007'`, y `007` es
     * literalmente el year id=1.
     *
     * La primera version de esta validacion usaba `FILTER_VALIDATE_INT`, y
     * **`filter_var('007', FILTER_VALIDATE_INT)` es `false`**: habria contestado
     * **422 a la pantalla buena** en todos los colegios con relleno. Una validacion
     * que rechaza el caso que venia a proteger es peor que no tenerla, porque
     * ademas parece que funciona -- el test del cuerpo invalido sigue verde.
     *
     * Este test es el que lo impide, y por eso comprueba **las dos mitades**: que
     * entra, y que **el relleno se conserva** — devolver el entero convertiria
     * `'007'` en `'7'` y eso cambia el numero impreso en un papel oficial.
     */
    public function test_el_consecutivo_relleno_de_ceros_sigue_entrando(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();
        $this->encenderFolio();

        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        foreach (['contador-certificados' => 'contador_certificados',
            'contador-folios' => 'contador_folios'] as $ruta => $columna) {

            $this->putJson('/api/bolfinales/cambiar-'.$ruta, ['contador' => '007'], $cab)
                ->assertStatus(200);

            $guardado = DB::selectOne(
                "SELECT {$columna} v FROM years WHERE deleted_at is null and actual=1"
            )->v;

            $this->assertSame('007', $guardado,
                'La pantalla mando «007» a `'.$ruta.'` y quedo «'.$guardado.'». Siete de los '
                .'ocho years estan rellenados a tres digitos: perder el relleno cambia el '
                .'numero impreso, y rechazarlo con 422 rompe la pantalla que hoy funciona.');
        }
    }

    /**
     * **Que quema un folio y que no, valor por valor.** La tabla es el guardian.
     *
     * Antes esto era un solo caso —la cadena `"false"`— y en rojo. Un `filter_var`
     * suelto no dice nada dentro de seis meses; lo que hay que poder leer de un
     * vistazo es **la frontera entera**, porque el arreglo se defiende por su forma
     * y no por su linea:
     *
     * **El cambio es estrictamente asimetrico hacia el lado seguro.** Con `== true`
     * quemaba cualquier cadena no vacia que no fuera `"0"` — incluida `"false"`, que
     * es lo que manda un cliente creyendo decir «no subas». Con
     * `FILTER_VALIDATE_BOOLEAN` quema solo lo que significa «si». **Todo lo que deja
     * de quemar dejaba de deber quemarse, y no hay ni un solo valor que hoy no queme
     * y manana si.** En papel oficial la direccion irreversible es quemar: un folio
     * no quemado se quema despues, uno quemado no vuelve.
     *
     * Por eso el aserto va en **las dos direcciones**: si manana alguien «simplifica»
     * esto de vuelta, cae por la mitad de arriba; si alguien lo endurece de mas y el
     * certificado deja de gastar numero, cae por la de abajo. Un test que solo
     * mirara la mitad segura pasaria con un endpoint que ya no incrementa nunca.
     *
     * **No sustituye la cura del front** (05 §225: tiene que OMITIR la clave, no
     * mandar `false`). La respalda — las copias de `myvc_front` desplegadas en los
     * dieciseis colegios pueden ir a versiones distintas.
     */
    public function test_que_valores_queman_un_folio_y_cuales_no(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $lee = fn () => (int) DB::selectOne(
            'SELECT contador_certificados FROM years WHERE deleted_at is null and actual=1'
        )->contador_certificados;

        // true = tiene que gastar un numero; false = no puede gastarlo.
        $esperado = [
            'true (bool)' => [true, true],
            '1 (entero)' => [1, true],
            '"1" (cadena)' => ['1', true],
            '"true" (cadena)' => ['true', true],
            '"yes"' => ['yes', true],
            '"on"' => ['on', true],

            'false (bool)' => [false, false],
            '0 (entero)' => [0, false],
            '"0" (cadena)' => ['0', false],
            '"false" (cadena)' => ['false', false],
            '"" (vacia)' => ['', false],
            '"si" (cualquier cadena)' => ['si', false],
            'null' => [null, false],
        ];

        $quemaron = [];
        $noQuemaron = [];

        foreach ($esperado as $etiqueta => [$valor, $deberiaQuemar]) {
            $antes = $lee();

            $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
                ['aumentar_contador' => $valor],
                ['Authorization' => 'Bearer '.$token])->assertStatus(200);

            $subio = $lee() - $antes;
            if ($subio > 0) {
                $quemaron[] = $etiqueta;
            } else {
                $noQuemaron[] = $etiqueta;
            }

            $this->assertSame($deberiaQuemar, $subio > 0,
                $deberiaQuemar
                    ? 'Mandar '.$etiqueta.' NO gasto un numero, y significa «si»: el '
                        .'certificado saldria con el consecutivo del anterior.'
                    : 'Mandar '.$etiqueta.' gasto un folio oficial. `'.$etiqueta.'` no '
                        .'significa «si», y un numero quemado no vuelve.');
        }

        // Sin esto, un fallo que impidiera llegar al endpoint dejaria trece «no
        // quemo» que se leerian como «la puerta esta cerrada».
        $this->assertCount(6, $quemaron,
            'Quemaron '.count($quemaron).' valores ('.implode(', ', $quemaron).'): la '
            .'frontera se movio y hay que volver a mirarla, no actualizar el numero.');

        $this->assertCount(7, $noQuemaron,
            'No quemaron '.count($noQuemaron).' valores: idem.');
    }

    /**
     * **Fijar el consecutivo pasa a ser de secretaria.** Decision de Joseth, 26 ago 2026.
     *
     * Era lo unico que le faltaba a esta familia: la carrera, la validacion y la quema
     * entraron la noche del 25 (`noche-2026-08-25/cert-1.md`), y el permiso quedo escrito
     * ahi como un si o un no **porque no era simetrico** -- a diferencia del
     * `FILTER_VALIDATE_BOOLEAN`, restringir esto **le quita un control a alguien que hoy
     * lo tiene delante**, y eso no lo decide una sesion.
     *
     * El sujeto es un `Usuario` **activo, del anio actual y sin `is_superuser`**, que es
     * exactamente el caso que hoy pasaba: `auth.personal` lo dejaba entrar.
     *
     * ## Las dos mitades, porque se cumplen por separado
     *
     * Un 403 que igualmente escribe deja el consecutivo movido **y ademas miente**; un 200
     * que no escribe se leeria como que la puerta sigue abierta. Se comprueban las dos, y
     * **en los dos endpoints**: `cambiar-contador-folios` es la hermana que no habia
     * nombrado nadie hasta la §225, y la forma de que no se vuelva a quedar fuera es que
     * el guard viva en `consecutivoValidado()`, por donde pasan los dos.
     */
    public function test_un_docente_corriente_ya_no_fija_el_consecutivo(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();
        $this->encenderFolio();

        $token = $this->tokenDelPersonalLlano();

        foreach (['contador-certificados' => 'contador_certificados',
            'contador-folios' => 'contador_folios'] as $ruta => $columna) {

            $lee = fn () => DB::selectOne(
                "SELECT {$columna} v FROM years WHERE deleted_at is null and actual=1"
            )->v;

            $antes = $lee();

            $r = $this->putJson('/api/bolfinales/cambiar-'.$ruta, ['contador' => '999'],
                ['Authorization' => 'Bearer '.$token]);

            $this->assertSame(403, $r->getStatusCode(),
                '`'.$ruta.'` contesto '.$r->getStatusCode().' a alguien del personal que no es '
                .'administrativo. El numero que va impreso en un papel oficial lo fija '
                .'secretaria.');

            $this->assertSame($antes, $lee(),
                'El 403 de `'.$ruta.'` llego con la columna ya movida a «'.$lee().'»: un 403 '
                .'que igualmente escribe deja el consecutivo roto y ademas miente.');

            $this->olvidarControladores();
        }
    }

    /**
     * Y la otra mitad del permiso: **un `Secretario` sin `is_superuser` si entra**.
     *
     * **Es la misma persona que el test de arriba**, y esa es toda la gracia: lo unico que
     * cambia entre el 403 y el 200 es la fila de `role_user`. Sin este test, poner
     * `abort(403)` a secas tambien pasaria el anterior -- y habria cerrado la pantalla a
     * secretaria, que es justo a quien se le abre.
     *
     * El rol se fabrica aqui dentro, como en `SecretarioTest`: `construir-bd-test.sh`
     * hace `TRUNCATE TABLE roles` antes del seed, asi que la fila de
     * `2026_08_21_100000_create_rol_secretario` no sobrevive -- y aunque sobreviviera,
     * **no se la dio a nadie**, que es el caso que hay que comprobar.
     */
    public function test_el_secretario_sin_superusuario_si_lo_fija(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();
        $this->encenderFolio();

        $token = $this->tokenDeUnSecretario();

        foreach (['contador-certificados' => 'contador_certificados',
            'contador-folios' => 'contador_folios'] as $ruta => $columna) {

            $this->putJson('/api/bolfinales/cambiar-'.$ruta, ['contador' => '007'],
                ['Authorization' => 'Bearer '.$token])
                ->assertStatus(200);

            $this->assertSame('007', DB::selectOne(
                "SELECT {$columna} v FROM years WHERE deleted_at is null and actual=1"
            )->v, 'El Secretario recibio 200 de `'.$ruta.'` y la columna no se movio.');

            $this->olvidarControladores();
        }
    }

    /**
     * **Fijar el consecutivo a mano deja rastro.** Antes no lo dejaba en ninguna parte.
     *
     * La 05 §231 lo dijo con todas las letras: `putCambiarContadorCertificados` **no
     * escribe en `bitacoras`**, asi que *«el consecutivo esta en 500 y ayer estaba en
     * 143»* no tenia a quien preguntarle. Y no es higiene: **el unico rastro de que se
     * emitio un certificado es que un contador subio**, o sea que quien lo mueve a mano
     * borra la unica cuenta que hay.
     *
     * Esto **no** contesta *«¿cuantos emitimos y a quien?»* -- para eso hace falta la tabla
     * de emitidos, que es una migracion en quince producciones y decision de Joseth. Lo que
     * hace es separar las dos mitades de la pregunta: desde hoy **la mano se ve**.
     *
     * Se afirma sobre los dos valores y no sobre el numero de lineas: `valor_anterior` es
     * lo unico que no se puede reconstruir despues, porque el `UPDATE` ya lo piso.
     */
    public function test_fijar_el_consecutivo_a_mano_deja_rastro(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();

        [, $token] = $this->grupoYPersonal();

        $previo = DB::selectOne(
            'SELECT contador_certificados v FROM years WHERE deleted_at is null and actual=1'
        )->v;

        $desde = (int) DB::table('auditoria')->max('id');

        $this->putJson('/api/bolfinales/cambiar-contador-certificados', ['contador' => '815'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $lineas = DB::select('SELECT * FROM auditoria WHERE id > ? AND entidad = ? ORDER BY id',
            [$desde, 'year_config']);

        $this->assertCount(1, $lineas,
            'Fijar el consecutivo a mano dejo '.count($lineas).' lineas de auditoria. Tiene que '
            .'dejar exactamente una: es el unico sitio donde queda que alguien movio la cuenta.');

        $this->assertSame('editar', $lineas[0]->accion);

        $this->assertSame($previo, json_decode((string) $lineas[0]->valor_anterior, true),
            'La linea no se llevo dentro el valor que habia antes, y es el unico que no se '
            .'puede reconstruir: el `UPDATE` ya lo piso.');

        $this->assertSame('815', json_decode((string) $lineas[0]->valor_nuevo, true));

        $this->assertNotNull($lineas[0]->actor_user_id,
            'La linea no dice quien lo hizo, que es la pregunta entera.');
    }

    /**
     * Y el rastro de **quemar** uno, que es el que la §231 echaba en falta de verdad.
     *
     * Abrir el «Certificado periodos» gasta un numero. Hasta hoy eso no dejaba nada
     * escrito, asi que **un numero quemado por abrir la pantalla era indistinguible de uno
     * emitido** -- no dificil: **no habia dato que los separara**. Con esta linea quedan
     * separados: la quema se ve, con quien y de que numero a cual.
     *
     * La linea va **dentro de la transaccion del incremento**, asi que si el incremento se
     * deshace el rastro se deshace con el. Este test no puede ver esa propiedad --haria
     * falta forzar el rollback-- y por eso no la afirma: mira lo que si es observable,
     * que la quema quedo escrita y con los dos valores.
     */
    public function test_quemar_un_folio_deja_rastro(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $previo = DB::selectOne(
            'SELECT contador_certificados v FROM years WHERE deleted_at is null and actual=1'
        )->v;

        $desde = (int) DB::table('auditoria')->max('id');

        $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => true],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $lineas = DB::select('SELECT * FROM auditoria WHERE id > ? AND entidad = ? ORDER BY id',
            [$desde, 'year_config']);

        $this->assertCount(1, $lineas,
            'Abrir el certificado quemo un numero y dejo '.count($lineas).' lineas. Sin una '
            .'linea por quema, un numero gastado por mirar sigue siendo indistinguible de uno '
            .'emitido, que es lo que la 05 §231 fue a medir y no pudo.');

        $this->assertSame($previo, json_decode((string) $lineas[0]->valor_anterior, true));

        $this->assertSame((int) $previo + 1, json_decode((string) $lineas[0]->valor_nuevo, true),
            'La linea no dice a que numero subio.');

        $this->assertStringContainsString('Quemo', (string) $lineas[0]->resumen,
            'El resumen no distingue una quema de un cambio a mano, y esa es la distincion '
            .'entera: la pantalla que las lea tiene que poder separarlas.');
    }

    /**
     * **El colegio que no numera sus constancias no quema ni un numero.**
     *
     * Decision de Joseth del 26 ago 2026: *«hay colegios a los que no les importa llevar
     * esos contadores o folios; que tengan la opcion»*
     * (`docs/migracion/21-certificados-y-folios.md`).
     *
     * **Lo que este test arregla de verdad, y no se ve mirando la pantalla:** hasta hoy un
     * colegio que **no imprimia** el numero --el front ya ocultaba el «No.» cuando la
     * columna estaba vacia-- **seguia gastandolo** en cada apertura del certificado. Su
     * contador subia solo, nadie lo miraba, y el dia que alguien encendiera esa casilla
     * habria aparecido un numero que no correspondia a ningun papel emitido.
     *
     * Aqui **no se enciende el interruptor**, que es justo el punto: el seed los deja
     * apagados, asi que este test mide el estado de un colegio que no los usa.
     */
    public function test_si_el_colegio_no_usa_consecutivo_no_se_quema_nada(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $lee = fn () => DB::selectOne(
            'SELECT contador_certificados v FROM years WHERE deleted_at is null and actual=1'
        )->v;

        $antes = $lee();
        $desde = (int) DB::table('auditoria')->max('id');

        $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => true],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame($antes, $lee(),
            'El colegio no lleva consecutivo de certificados y abrir el certificado le gasto '
            .'un numero igual: paso de «'.$antes.'» a «'.$lee().'».');

        // Y la otra mitad, que se puede incumplir sin la primera: si no se quemo nada,
        // tampoco puede haber quedado escrito que se quemo algo.
        $this->assertCount(0, DB::select(
            'SELECT id FROM auditoria WHERE id > ? AND entidad = ?', [$desde, 'year_config']),
            'No se quemo ningun numero pero quedo una linea de auditoria diciendo que si.');
    }

    /**
     * Y los dos endpoints contestan **409** en ese colegio, cada uno por su interruptor.
     *
     * **409 y no 403**: no es que quien llama no pueda --el sujeto de este test es
     * administrativo y en un colegio que los use entraria--, es que **la operacion no
     * aplica aqui**. Y no es 422 porque el cuerpo esta bien: `'815'` es un consecutivo
     * perfectamente valido; lo que no encaja es el estado del sistema.
     *
     * Van los dos en el mismo test **con asertos separados y mensaje propio**, porque los
     * interruptores son dos y uno puede quedarse sin cablear.
     */
    public function test_si_el_colegio_no_los_usa_los_dos_endpoints_contestan_409(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [, $token] = $this->grupoYPersonal();

        foreach (['contador-certificados' => 'contador_certificados',
            'contador-folios' => 'contador_folios'] as $ruta => $columna) {

            $lee = fn () => DB::selectOne(
                "SELECT {$columna} v FROM years WHERE deleted_at is null and actual=1"
            )->v;

            $antes = $lee();

            $r = $this->putJson('/api/bolfinales/cambiar-'.$ruta, ['contador' => '815'],
                ['Authorization' => 'Bearer '.$token]);

            $this->assertSame(409, $r->getStatusCode(),
                '`'.$ruta.'` contesto '.$r->getStatusCode().' en un colegio que no lleva ese '
                .'contador. Guardar ahi un numero que nadie imprime lo deja esperando a que '
                .'alguien encienda la casilla.');

            $this->assertSame($antes, $lee(),
                'El 409 de `'.$ruta.'` llego con la columna ya movida a «'.$lee().'».');

            $this->olvidarControladores();
        }
    }

    /**
     * **Enciende el consecutivo en el year actual, dentro de la transaccion del test.**
     *
     * Hace falta explicitamente y **eso no es un inconveniente: es la conducta**. Desde el
     * 26 ago 2026 numerar las constancias es **opcional por colegio**
     * (`docs/migracion/21-certificados-y-folios.md`), asi que un test que quema un numero
     * sin decir que el colegio los lleva estaria midiendo una configuracion y no el codigo.
     *
     * Y hace falta **ademas** por donde corren las cosas: `construir-bd-test.sh` ejecuta las
     * migraciones **antes** del seed, y el seed reinserta `years`, asi que la derivacion que
     * hace la migracion --encender el interruptor donde el contador no esta vacio-- **se la
     * lleva por delante el seed** y todos los years quedan en 0. Es exactamente lo que ya
     * dejo documentado `create_permiso_can_view_auditoria`, y por eso los tests se montan su
     * propia configuracion en vez de depender de la que traiga la copia.
     */
    private function encenderConsecutivo(): void
    {
        DB::update('UPDATE years SET usa_consecutivo_certificados=1 WHERE actual=1 and deleted_at is null');
    }

    /** Lo mismo para el folio, que es el otro interruptor y va por separado. */
    private function encenderFolio(): void
    {
        DB::update('UPDATE years SET usa_folio_certificados=1 WHERE actual=1 and deleted_at is null');
    }

    /**
     * Un `Usuario` del personal **sin `is_superuser`** y con el rol `Secretario` puesto.
     *
     * El mismo sujeto que `tokenDelPersonalLlano()` -- se le pide a el su fila para que el
     * 403 y el 200 sean la misma persona con y sin la fila de `role_user`.
     */
    private function tokenDeUnSecretario(): string
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $rol = DB::table('roles')->where('name', 'Secretario')->first();

        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => (int) ($rol->id ?? DB::table('roles')->insertGetId([
                'name' => 'Secretario',
                'created_at' => now(),
                'updated_at' => now(),
            ])),
        ]);

        return $this->tokenDe($usuario->username);
    }
}
