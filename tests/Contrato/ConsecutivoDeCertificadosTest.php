<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * El consecutivo de los certificados: **estos tests están ROJOS a propósito.**
 *
 * Es el punto **1** de la lista de la mañana de Joseth
 * ([ESTADO-ACTUAL](../../docs/migracion/ESTADO-ACTUAL.md), [05 §195](../../docs/migracion/05-codigo-muerto-y-roto.md)),
 * y **la decisión de qué hacer no es de ninguna sesión**. Lo que sí se pidió por
 * escrito —*«el test que falle hoy: si sale rojo, el arreglo entra con red»*— es
 * esto: **que mañana la decisión se tome delante de una prueba en rojo y no
 * delante de un párrafo.**
 *
 * **No arreglan nada, no cambian conducta y no tocan datos** (cada test corre en
 * su transacción). Sólo dicen, en lenguaje ejecutable, qué tendría que pasar y
 * hoy no pasa.
 *
 *     docker exec -w /app/.worktrees/12 -e DB_TEST_DATABASE=simonbolivar_testing_12 \
 *         8myvc-app-1 php artisan test --group=rojo
 *
 * ## Por qué van en un grupo excluido de la corrida normal
 *
 * Un test rojo permanente dentro de la suite **convierte el verde en ruido**: a
 * la tercera corrida nadie distingue «el rojo de siempre» de uno nuevo, y el
 * siguiente fallo de verdad entra sin que salte nada. Es el mismo motivo por el
 * que `barrido` está excluido, y por eso `rojo` se añade al lado en
 * `phpunit.xml` en vez de dejar estos tres sueltos.
 *
 * **El día que se arreglen, se quita `#[Group('rojo')]` y pasan a la suite.** Eso
 * es lo que los convierte en la red del arreglo y no en una queja archivada.
 *
 * ## Lo que fija cada uno
 *
 * 1. **la carrera**, demostrada sin hilos y sin depender de tiempos;
 * 2. **`PUT bolfinales/cambiar-contador-certificados` sin validación**;
 * 3. **`PUT bolfinales/cambiar-contador-folios`, que es la misma puerta** y que
 *    no había nombrado nadie — el punto 2 de la lista de Joseth habla sólo de
 *    certificados. *Son dos endpoints con el mismo fallo, no uno: la pregunta
 *    «¿quién más hace esto mismo?» aplicada al sitio donde se encontró.*
 */
#[Group('rojo')]
class ConsecutivoDeCertificadosTest extends CasoDeContrato
{
    /**
     * Dos personas abriendo el certificado a la vez se llevan el MISMO número.
     *
     * ## Esto no necesita concurrencia para ser cierto, y por eso el test no la usa
     *
     * El controlador hace **leer, sumar en PHP, escribir**
     * (`Informes/BolfinalesController:86-99`):
     *
     *     SELECT id, contador_certificados FROM years WHERE deleted_at is null and actual=1
     *     UPDATE years SET contador_certificados=? WHERE id=?      // (int)$leido + 1
     *
     * **sin transacción y sin `FOR UPDATE`**. Un test con hilos de verdad
     * dependería de que el planificador los cruce, o sea que **fallaría a veces**
     * — y un test que falla a veces se acaba desactivando. Aquí se ejecutan las
     * mismas dos sentencias **en el orden exacto que produce la concurrencia
     * real** (leer A, leer B, escribir A, escribir B), que es determinista y
     * suficiente: si dos lecturas ven el mismo valor, las dos escrituras escriben
     * el mismo valor, y **un incremento se pierde**.
     *
     * Con un `FOR UPDATE` dentro de una transacción, la segunda lectura esperaría
     * y este intercalado **no podría ocurrir**. O sea que el test no comprueba una
     * casualidad de tiempos: comprueba **la propiedad que hoy no se sostiene**.
     *
     * **Y la consecuencia no es un número saltado, que se justifica: son dos
     * folios oficiales con el mismo consecutivo**, que es lo que no se justifica.
     */
    public function test_dos_lecturas_a_la_vez_no_pueden_gastar_el_mismo_numero(): void
    {
        $lectura = 'SELECT id, contador_certificados FROM years WHERE deleted_at is null and actual=1';

        $antes = DB::select($lectura);

        $this->assertNotEmpty($antes,
            'El seed no tiene un year `actual=1`: sin eso este test no mide la carrera, no mide nada.');

        $partida = (int) $antes[0]->contador_certificados;
        $yearId = $antes[0]->id;

        // Secretaría A abre la pantalla. Secretaría B abre la pantalla.
        $leeA = (int) DB::select($lectura)[0]->contador_certificados;
        $leeB = (int) DB::select($lectura)[0]->contador_certificados;

        // Cada una escribe lo que leyó, más uno. Tal cual lo hace el controlador.
        DB::update('UPDATE years SET contador_certificados=? WHERE id=?', [$leeA + 1, $yearId]);
        DB::update('UPDATE years SET contador_certificados=? WHERE id=?', [$leeB + 1, $yearId]);

        $final = (int) DB::select($lectura)[0]->contador_certificados;

        $this->assertSame($partida + 2, $final,
            'Dos aperturas consumieron un solo número: las dos leyeron '.$leeA.' y las dos '
            .'escribieron '.($leeA + 1).'. En papel eso son DOS CERTIFICADOS CON EL MISMO '
            .'CONSECUTIVO. Falta la transacción con `FOR UPDATE` alrededor de la lectura y '
            .'la escritura de `years.contador_certificados`.');
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
     * Mandar `"false"` **quema un número**.
     *
     * El incremento está detrás de `Request::input('aumentar_contador') == true`,
     * con `==` y no `===`. En PHP **cualquier cadena no vacía que no sea `'0'` es
     * cierta**, así que la cadena `"false"` —que es lo que manda un cliente que
     * cree estar diciendo «no subas»— entra en el `if`.
     *
     * Medido, valor a valor, en `Tests\Barrido\QuemaDelConsecutivoTest`:
     *
     *     sin la clave    no sube        "true"    SUBE
     *     true (bool)     SUBE           "false"   SUBE   <- éste
     *     false (bool)    no sube        "0"       no sube
     *     0 (entero)      no sube        "si"      SUBE
     *
     * **Y esto decide dónde está la cura**, que era la pregunta abierta: el
     * servidor sólo sube cuando se lo piden, así que **el front puede arreglarlo
     * solo y sin tocar los dieciséis despliegues** — pero **no le basta con mandar
     * `false`: tiene que mandar el booleano, no la cadena, o mejor OMITIR la
     * clave**. Es una regla que **nadie puede adivinar leyendo el endpoint**, y por
     * eso está aquí escrita en vez de en la cabeza de quien lo midió.
     *
     * Es la misma comparación laxa que ya se corrigió **doce líneas más arriba en
     * el mismo fichero** (`year_selected`, donde `0 == 'true'` era cierto). *Se
     * arregló la de al lado y no ésta, porque la de al lado dio un síntoma visible
     * y ésta sólo gasta un número que nadie echa en falta.*
     */
    public function test_la_cadena_false_no_deberia_quemar_un_numero(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $lee = fn () => (int) DB::selectOne(
            'SELECT contador_certificados FROM years WHERE deleted_at is null and actual=1'
        )->contador_certificados;

        $antes = $lee();

        $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => 'false'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame($antes, $lee(),
            'Mandar la CADENA "false" subió el consecutivo de '.$antes.' a '.$lee().'. '
            .'`== true` es cierto para cualquier cadena no vacía que no sea "0", así que un '
            .'cliente que cree estar diciendo «no subas» quema un folio oficial.');
    }
}
