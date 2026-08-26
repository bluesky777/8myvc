<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/**
 * **El folio no se fabrica.** Decision de Joseth del 26 ago 2026.
 *
 * ## Que es un folio, porque de ahi sale todo lo demas
 *
 * Un folio es **una hoja de un libro empastado** —el libro de matriculas—, y lo que se
 * imprime en la constancia esta ahi para que **quien la lea vaya a comprobarla al
 * archivo**: *«consta en el folio 237 del libro de matriculas del año 2018»*. No es un
 * identificador del alumno.
 *
 * El sistema escribia `anio-alumno_id` —`2025-1234`—, que **no es la hoja de ningun
 * libro**. Medido en la copia local de `simonbolivar`, un colegio de los quince
 * (`docs/migracion/21-certificados-y-folios.md` §2.2):
 *
 *     1.440  vacias
 *     1.612  fabricadas: `anio-alumno_id` sobre la fila de ese alumno
 *       257  fabricadas CON LA FORMA de otro: `2023-156` en cuatro alumnos distintos
 *       233  folios de verdad, escritos a mano -- y la practica murio en 2023
 *
 * O sea que **el 53 % de las que tenian folio imprimian un numero que no apunta a ninguna
 * parte**, y 257 imprimian uno que nombra a otra persona. Un folio en blanco es honesto;
 * uno inventado no.
 *
 * ## Que vigila cada test, y que NO
 *
 * 1. **la conducta**, por HTTP: matricular a alguien ya no le pone folio;
 * 2. **la maquina en masa**: `folios/iniciar`, que llenaba de golpe todos los vacios del
 *    anio, contesta 409 y no toca ninguna fila;
 * 3. **el fichero entero**: un barrido de `app/` que cae si alguien vuelve a escribir la
 *    linea en cualquiera de los siete sitios de los que se quito.
 *
 * El tercero es el que de verdad protege esto, y por eso **imprime cuantos ficheros
 * reviso**: eran **siete sitios en cuatro ficheros** y un barrido que no encuentre nada
 * porque no miro nada se lee igual que uno que encontro cero.
 */
class FolioQueNoSeFabricaTest extends CasoDeContrato
{
    /**
     * Rematricular a alguien que estaba en la papelera **ya no le inventa un folio**.
     *
     * Va por HTTP y no llamando al modelo: `Matricula::matricularUno()` tenia **cuatro** de
     * los siete sitios, repartidos por ramas que dependen de si la matricula estaba en la
     * papelera, si venia de otro grupo o si es nueva. Llamar al metodo por dentro elegiria
     * una rama; la peticion elige la que toque.
     *
     * **Y se ataca la rama de la papelera a proposito**, que es donde vivian **dos de los
     * cuatro** —las dos con `if ($matri->nro_folio == null)`, que son exactamente las que
     * rellenaban un hueco que alguien podia haber dejado vacio adrede—.
     *
     * El sujeto se monta aqui dentro, dentro de la transaccion del test, porque el seed no
     * tiene ni una matricula en la papelera ni un alumno fuera de su grupo: **68 alumnos,
     * 124 matriculas, dos grupos, cero en papelera**. Buscar uno con `NOT IN` devolvia null
     * y el test no medía nada.
     */
    public function test_rematricular_desde_la_papelera_ya_no_inventa_un_folio(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $matricula = DB::selectOne('SELECT id, alumno_id FROM matriculas
            WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($matricula, 'El grupo del seed no tiene matriculas.');

        // A la papelera y sin folio: el estado exacto en el que la rama de arriba lo
        // rellenaba. Se deshace solo al acabar el test (`DatabaseTransactions`).
        DB::update('UPDATE matriculas SET deleted_at = NOW(), nro_folio = NULL WHERE id = ?',
            [$matricula->id]);

        $this->postJson('/api/matriculas/matricularuno', [
            'alumno_id' => $matricula->alumno_id,
            'grupo_id' => $grupo->id,
            'year_id' => $grupo->year_id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $fila = DB::selectOne('SELECT deleted_at, nro_folio FROM matriculas WHERE id = ?',
            [$matricula->id]);

        // Sin esto, un endpoint que no hiciera nada dejaria el folio en null y el test
        // pasaria sin haber ejercido la rama.
        $this->assertNull($fila->deleted_at,
            'La matricula sigue en la papelera: el endpoint no la restauro, asi que este '
            .'test no llego a la rama donde estaba la linea.');

        $this->assertNull($fila->nro_folio,
            'Al restaurarla le puso el folio «'.$fila->nro_folio.'», y nadie lo escribio: lo '
            .'fabrico el sistema. Un folio es la hoja del libro de matriculas y no se puede '
            .'deducir del id del alumno.');
    }

    /**
     * `folios/iniciar` era **la maquina que produjo los 1.612**, y ya no produce ninguno.
     *
     * Llenaba de una sentencia todos los `nro_folio` vacios del anio actual. **No lo llama
     * ningun cliente**: se reviso en los siete arboles de `~/DESARROLLOS` —`myvc_front`,
     * `myvc_front_2`, `myvc_flutter`, `myvc_dist`, `tardanzasMyvc-old`, `arc` y
     * `landingLAL`— y **cero ficheros la nombran**.
     *
     * **409 y no 404**: la regla de la casa es que una ruta que existe se documenta en vez
     * de borrarse, porque un 404 no le dice a nadie que pretendia hacer esa pantalla.
     *
     * El aserto son **las dos mitades**, que se pueden incumplir por separado: que conteste
     * 409, y que **no haya llenado nada antes de contestarlo**.
     */
    public function test_folios_iniciar_ya_no_fabrica_en_masa(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [, $token] = $this->grupoYPersonal();

        // El seed no tiene ni un hueco --124 matriculas, todas con folio--, asi que se
        // abre uno aqui dentro: sin nada que rellenar, «no fabrico» y «no habia nada que
        // fabricar» dan el mismo verde, y de las dos lecturas la falsa es la que hace
        // archivar el asunto.
        $victima = DB::selectOne('SELECT m.id FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1
            WHERE m.deleted_at IS NULL ORDER BY m.id LIMIT 1');

        $this->assertNotNull($victima,
            'El seed no tiene ninguna matricula en el anio actual, que es el unico que este '
            .'endpoint miraba.');

        DB::update('UPDATE matriculas SET nro_folio = NULL WHERE id = ?', [$victima->id]);

        $vaciosAntes = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM matriculas WHERE deleted_at IS NULL AND (nro_folio IS NULL OR nro_folio = "")'
        )->c;

        $this->assertGreaterThan(0, $vaciosAntes,
            'No hay ni una matricula sin folio, asi que este test no puede distinguir «no '
            .'fabrico» de «no habia nada que fabricar».');

        $r = $this->getJson('/api/folios/iniciar', ['Authorization' => 'Bearer '.$token]);

        $this->assertSame(409, $r->getStatusCode(),
            'Contesto '.$r->getStatusCode().': la maquina que fabricaba folios en masa sigue '
            .'en pie.');

        $this->assertSame($vaciosAntes, (int) DB::selectOne(
            'SELECT COUNT(*) c FROM matriculas WHERE deleted_at IS NULL AND (nro_folio IS NULL OR nro_folio = "")'
        )->c, 'Contesto 409 y aun asi lleno folios: llego con el UPDATE ya hecho.');
    }

    /**
     * **El barrido, que es lo que de verdad protege esto.**
     *
     * Los dos de arriba miran dos caminos; este mira **el codigo entero**, porque la linea
     * estaba en **siete sitios de cuatro ficheros** —cuatro en `Models\Matricula`, uno en
     * `AlumnosController`, uno en `MatriculasController` y el `UPDATE` de
     * `FoliosController`— y volver a ponerla en cualquiera de ellos lo deshace.
     *
     * Busca una asignacion a `nro_folio` que tenga cerca un `year` y un `alumno_id`, que es
     * la forma de todas las que habia:
     *
     *     $matricula->nro_folio = $year->year . '-' . $alumno_id;
     *     SET m.nro_folio=CONCAT(y.year,"-", m.alumno_id)
     *
     * **Lo que este test NO promete**, y va escrito para que nadie lo cuente de mas: no
     * impide que alguien fabrique un folio **con otra formula**. Fija la que existia y su
     * forma; una nueva seria un hallazgo nuevo.
     */
    public function test_ningun_sitio_de_app_fabrica_un_folio(): void
    {
        $ficheros = [];
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $ficheros[] = $f->getPathname();
            }
        }

        // Sin esto, un `base_path()` equivocado daria cero fabricantes sobre cero ficheros
        // y se leeria como «esta limpio». Es la regla de la casa: ninguna medicion dice OK
        // sin decir su poblacion.
        $this->assertGreaterThan(200, count($ficheros),
            'El barrido solo encontro '.count($ficheros).' ficheros PHP en `app/`: no esta '
            .'mirando lo que dice mirar, asi que su verde no vale.');

        /*
         * **El control positivo, y sin el este test no vale nada.**
         *
         * Un barrido que no encuentra nada puede estar limpio o estar roto, y las dos cosas
         * se leen igual: cero. Aqui se le dan **las dos lineas que de verdad existieron** y
         * se exige que las cace; y ademas **una que NO debe cazar** --la lista de columnas
         * de un `SELECT`--, que es justo el falso positivo que tuvo la primera version.
         *
         * Sin esto, apretar el detector para quitar los dos falsos positivos podria haberlo
         * dejado sin cazar nada, y el verde habria sido el mismo.
         */
        $caza = fn (string $l) => preg_match('/nro_folio\s*=/i', $l)
            && (preg_match('/CONCAT\s*\(/i', $l) || preg_match('/\.\s*[\'"]-[\'"]\s*\./', $l))
            && preg_match('/year/i', $l)
            && preg_match('/alumno_id/i', $l);

        $this->assertTrue((bool) $caza('$matricula->nro_folio = $year->year . \'-\' . $alumno_id;'),
            'El detector ya no caza la linea de PHP que se quito de `Models\\Matricula`: '
            .'su cero significa «no mire», no «esta limpio».');

        $this->assertTrue((bool) $caza('SET m.nro_folio=CONCAT(y.year,"-", m.alumno_id);'),
            'El detector ya no caza el `UPDATE` en masa de `FoliosController`.');

        $this->assertFalse((bool) $caza('SELECT y.year, m.alumno_id, m.nro_folio, m.created_by'),
            'El detector vuelve a cazar una lista de columnas de un SELECT, que LEE el folio '
            .'y no lo fabrica. Es el falso positivo que tuvo la primera version, en '
            .'`ActasEvaluacionController:797` y `AlumnosController:654`.');

        $culpables = [];

        foreach ($ficheros as $ruta) {
            foreach (explode("\n", (string) file_get_contents($ruta)) as $n => $linea) {
                if (stripos($linea, 'nro_folio') === false) {
                    continue;
                }
                /*
                 * **Una ASIGNACION que CONCATENA**, y las tres condiciones hacen falta.
                 *
                 * La primera version pedia `nro_folio` seguido de `=` **o de una coma**, y
                 * la coma cazaba `m.nro_folio,` dentro de la lista de columnas de dos
                 * `SELECT` —`ActasEvaluacionController:797` y `AlumnosController:654`—, que
                 * no fabrican nada: leen. **Dos falsos positivos, y los dos con el aspecto
                 * exacto del problema**, porque esas listas llevan `year` y `alumno_id`
                 * dentro.
                 *
                 * Lo que separa leer de fabricar es **construir el valor**, asi que la
                 * condicion es la concatenacion: `CONCAT(...)` en el SQL crudo o `. '-' .`
                 * en PHP. Las dos formas que habia:
                 *
                 *     $matricula->nro_folio = $year->year . '-' . $alumno_id;
                 *     SET m.nro_folio=CONCAT(y.year,"-", m.alumno_id)
                 */
                if (preg_match('/nro_folio\s*=/i', $linea)
                    && (preg_match('/CONCAT\s*\(/i', $linea)
                        || preg_match('/\.\s*[\'"]-[\'"]\s*\./', $linea))
                    && preg_match('/year/i', $linea)
                    && preg_match('/alumno_id/i', $linea)) {
                    $culpables[] = str_replace(base_path().'/', '', $ruta).':'.($n + 1)
                        .'  '.trim($linea);
                }
            }
        }

        $this->assertSame([], $culpables,
            "Alguien volvio a fabricar el folio:\n  ".implode("\n  ", $culpables)
            ."\n\n`anio-alumno_id` no es la hoja de ningun libro. Un folio es una posicion en "
            .'el libro de matriculas y se escribe a mano; si esta vacio, la constancia no lo '
            ."imprime.\nEl porque, con los numeros: docs/migracion/21-certificados-y-folios.md\n"
            .'(Revisados '.count($ficheros).' ficheros PHP de `app/`.)');
    }
}
