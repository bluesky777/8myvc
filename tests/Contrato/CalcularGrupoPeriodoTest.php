<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use App\Support\Reloj;
use App\Support\SellaConElReloj;
use Illuminate\Support\Facades\DB;

/**
 * **El botón «Calcular definitivas per N» reescribe la rejilla de un periodo
 * cerrado.** Es la ruta que contesta la pregunta del lote C — §90.
 *
 * `PUT api/definitivas_periodos/calcular-grupo-periodo` borra las definitivas
 * automáticas del `periodo_id` que le llega **en el cuerpo** y las vuelve a
 * insertar calculadas de las notas. No llama a `pueden_modificar_definitivas` ni
 * a `pueden_editar_notas`: es la única de las ocho rutas de su controlador que no
 * pregunta.
 *
 * | Ruta del mismo controlador | ¿Pregunta por el interruptor? |
 * |---|---|
 * | `update`, `update-recuperacion`, `toggle-manual`, `toggle-recuperada` | sí |
 * | `eliminar-recuperada`, `destroy/{id}`, `arreglar-duplicados` | sí |
 * | **`calcular-grupo-periodo`** | **no** |
 *
 * ## Por qué nadie había vuelto: la vecina de al lado sí está cerrada
 *
 * En `routes/api/academico.php` son estas dos, **seguidas** (:124 y :125 el 23 ago):
 *
 * ```
 * PUT definitivas_periodos/calcular-grupo-periodo             <-- viva, escribe
 * PUT definitivas_periodos/calcular-notas-finales-asignatura  <-- 410 desde la §71
 * ```
 *
 * Mismo controlador, mismo `auth.personal`, nombres que empiezan igual. La
 * [§71](../../docs/migracion/05-codigo-muerto-y-roto.md) cortó la segunda con un
 * 410 porque borraba las definitivas puestas a mano y **nunca llegó a calcular
 * ninguna**; la primera se quedó. En la tabla de la §77.2 —la que convierte los
 * cuatro «NO pregunta» del detector en veredictos— la fila de
 * `putCalcularGrupoPeriodo` dice «§71, cortada con 410», que es **la vecina**.
 *
 * O sea que el detector la señaló bien las dos veces y el veredicto se le
 * atribuyó a la de al lado. Por eso el último caso de esta clase golpea **las
 * dos** rutas en la misma petición: un número al lado del otro no se puede
 * confundir, una fila de tabla sí.
 *
 * ## Lo que esto NO es
 *
 * No es el cálculo de las definitivas, que está congelado por decisión de Joseth
 * hasta que termine la migración ([10-definitivas.md](../../docs/migracion/10-definitivas.md)).
 * **Aquí no se toca el cálculo**: se mide y se fija quién puede dispararlo y con
 * qué periodo, que es lo que el lote C tiene abierto.
 */
class CalcularGrupoPeriodoTest extends CasoDeContrato
{
    /**
     * Un grupo con definitivas, un profesor de su año, y **el año entero cerrado**
     * —los dos interruptores— para que lo que pase no pueda pasar por permiso.
     *
     * Se pide el grupo por la cantidad de definitivas y no `grupoConAlumnos()`
     * porque lo que aquí se mide es la rejilla, no la lista.
     */
    private function escenario(bool $conManuales = false): object
    {
        $orden = $conManuales ? 'SUM(nf.manual = 1) DESC, total DESC' : 'total DESC';

        $fila = DB::selectOne('SELECT a.grupo_id, nf.periodo_id, p.numero, p.year_id, COUNT(*) total
            FROM notas_finales nf
            INNER JOIN asignaturas a ON a.id = nf.asignatura_id
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            GROUP BY a.grupo_id, nf.periodo_id, p.numero, p.year_id
            ORDER BY '.$orden.' LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita definitivas para medir esto.');

        $profesor = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.year_id = ?
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1', [$fila->year_id]);

        $this->assertNotNull($profesor, "El seed necesita un Profesor del año {$fila->year_id}.");

        // El token primero: `Services\Login` reescribe `users.periodo_id` al entrar.
        $token = $this->tokenDe($profesor->username);

        DB::table('periodos')->where('year_id', $fila->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $fila->token = $token;

        return $fila;
    }

    /** Las definitivas de ese grupo y periodo: cuántas hay y con qué ids. */
    private function definitivas(object $e): array
    {
        return array_map(fn ($f) => (int) $f->id, DB::select(
            'SELECT nf.id FROM notas_finales nf
             INNER JOIN asignaturas a ON a.id = nf.asignatura_id
             WHERE a.grupo_id = ? AND nf.periodo_id = ? ORDER BY nf.id',
            [$e->grupo_id, $e->periodo_id]
        ));
    }

    private function calcular(object $e)
    {
        return $this->withToken($e->token)->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $e->grupo_id,
            'periodo_id' => $e->periodo_id,
            'num_periodo' => $e->numero,
        ]);
    }

    /**
     * **Con el periodo cerrado, la rejilla se reescribe entera y contesta 200.**
     *
     * No se cuentan filas —el recálculo devuelve el mismo número— sino **los ids**:
     * el método hace `DELETE` y luego `INSERT`, así que ninguna fila sobrevive
     * aunque la cuenta salga igual. Mirar el conteo diría que no pasó nada, que es
     * exactamente la forma de mirar el estado en vez del resultado.
     *
     * Los valores están fijados como lo que hay **hoy**, no como lo que debería
     * ser: 200 y cero supervivientes. El día que alguien decida cerrarlo, este
     * caso cae, y ahí está escrito lo que hay que decidir.
     */
    public function test_con_el_periodo_cerrado_reescribe_la_rejilla_y_contesta_200(): void
    {
        $e = $this->escenario();

        $antes = $this->definitivas($e);
        $this->assertNotEmpty($antes, 'Sin definitivas antes no se mide nada.');

        $this->calcular($e)->assertStatus(200)->assertSee('Calculado');

        $despues = $this->definitivas($e);

        $this->assertSame([], array_values(array_intersect($antes, $despues)),
            'Alguna definitiva del periodo cerrado sobrevivió: cambió el criterio del DELETE.');

        $this->assertNotEmpty($despues,
            'La rejilla quedó vacía: el recálculo borró y no insertó, que sería la §71 aquí.');
    }

    /**
     * **La que se lleva por delante y la que no**, que es lo único que hace que un
     * 200 aquí no sea catastrófico.
     *
     * El `DELETE` filtra `(manual is null or manual=0) and (recuperada is null or
     * recuperada=0)`, o sea que respeta lo escrito a mano — **al revés que la §71**,
     * que tenía el criterio invertido y se llevaba justo las manuales. Se fija
     * porque es lo que separa este hallazgo de aquél.
     */
    public function test_lo_puesto_a_mano_sobrevive_al_recalculo(): void
    {
        $e = $this->escenario(conManuales: true);

        $manuales = array_map(fn ($f) => (int) $f->id, DB::select(
            'SELECT nf.id FROM notas_finales nf
             INNER JOIN asignaturas a ON a.id = nf.asignatura_id
             WHERE a.grupo_id = ? AND nf.periodo_id = ? AND (nf.manual = 1 OR nf.recuperada = 1)',
            [$e->grupo_id, $e->periodo_id]
        ));

        if ($manuales === []) {
            $this->markTestSkipped('Ese grupo no tiene definitivas manuales en el seed.');
        }

        $this->calcular($e)->assertStatus(200);

        $vivas = DB::table('notas_finales')->whereIn('id', $manuales)->count();

        $this->assertSame(count($manuales), $vivas,
            'El recálculo se llevó definitivas manuales. Eso es la §71 y aquí no debería pasar.');
    }

    /**
     * **La asimetría con sus hermanas**, que es lo que convierte el 200 de arriba en
     * un hallazgo y no en una curiosidad.
     *
     * Con el MISMO token y el MISMO periodo cerrado, tocar una definitiva por
     * `update` contesta 400 «No tienes permiso» y no la cambia. O sea que el
     * interruptor con el que el colegio cierra el periodo **está puesto y funciona**;
     * lo que pasa es que hay una puerta que no lo consulta, y esa puerta escribe más
     * filas de una vez que ninguna de las que sí lo consultan.
     */
    public function test_su_hermana_de_al_lado_si_pregunta_por_el_interruptor(): void
    {
        $e = $this->escenario();

        $nf = DB::selectOne('SELECT nf.id, nf.nota FROM notas_finales nf
            INNER JOIN asignaturas a ON a.id = nf.asignatura_id
            WHERE a.grupo_id = ? AND nf.periodo_id = ? ORDER BY nf.id LIMIT 1',
            [$e->grupo_id, $e->periodo_id]);

        $this->assertNotNull($nf, 'Sin una definitiva no se mide la hermana.');

        $this->withToken($e->token)->putJson('/api/definitivas_periodos/update', [
            'nf_id' => $nf->id,
            'nota' => 1,
            'num_periodo' => $e->numero,
        ])->assertStatus(400);

        $this->assertEquals($nf->nota,
            DB::table('notas_finales')->where('id', $nf->id)->value('nota'),
            'La definitiva se cambió pese al 400.');
    }

    /**
     * **Las dos vecinas, en la misma petición y con el mismo token.**
     *
     * Existe por cómo se escondió esto: no por un detector ciego —
     * `escrituras-en-las-notas.py` la lista como «NO pregunta» desde que existe—
     * sino porque en la tabla que convierte esa lista en veredictos la fila de
     * `putCalcularGrupoPeriodo` dice «cortada con 410», que es lo que le pasó a la
     * de al lado.
     *
     * Dos códigos en un mismo `assertSame` no se pueden confundir. Una fila de una
     * tabla escrita a mano, sí.
     */
    public function test_la_cortada_con_410_es_la_vecina_y_no_esta(): void
    {
        $e = $this->escenario();

        $codigos = [];

        $codigos['calcular-grupo-periodo'] = $this->calcular($e)->status();

        $this->olvidarControladores();

        $codigos['calcular-notas-finales-asignatura'] = $this->withToken($e->token)
            ->putJson('/api/definitivas_periodos/calcular-notas-finales-asignatura', [
                'profesor_id' => 1,
            ])->status();

        $this->assertSame(
            ['calcular-grupo-periodo' => 200, 'calcular-notas-finales-asignatura' => 410],
            $codigos,
            'Las dos vecinas dejaron de contestar lo que contestaban. Si `calcular-grupo-periodo` ya no es 200, alguien la cerró: anótese la decisión, que es la del §90.'
        );
    }

    /**
     * **Pulsar el botón tiene que APAGAR el aviso que lo acompaña.**
     *
     * Desde el 17 sep 2026 el tablero de informes pinta «Notas finales
     * desactualizadas Per N» con `DefinitivasDeAsignatura::estadoDelGrupo()`, y
     * debajo pone **este** botón. Nadie había atado las dos puntas, y Zaragoza
     * reportó el 21 sep —al día siguiente del despliegue— que el aviso no se iba
     * por más que lo pulsaran.
     *
     * Eran dos cosas, y las dos se ven desde aquí:
     *
     * 1. `faltan` contaba matriculados sin notas, que este método **no escribe
     *    nunca** porque su consulta sale de un `INNER JOIN notas`;
     * 2. la fila se sellaba con `Carbon::now('America/Bogota')` y la app corre en
     *    UTC, así que nacía **cinco horas por detrás** del sello de sus propias
     *    notas.
     *
     * El montaje pone el sello **a un minuto**, que es la distancia a la que un
     * desfase de relojes decide el resultado: con el sello viejo del seed este test
     * pasaría con el fallo puesto. Y lo pone con `Reloj::ahora()`, o sea **en el
     * reloj en el que este sistema guarda** — el caso en que el sello viene en el
     * otro reloj es el del test de abajo, y sigue roto a propósito.
     */
    public function test_tras_pulsar_el_boton_el_tablero_deja_de_marcar_el_grupo(): void
    {
        $e = $this->escenario();

        $this->sellarLasNotas($e, Reloj::ahora()->subMinute());

        // Y las definitivas, viejas: es el estado que deja cualquier nota puesta
        // después del último cálculo.
        DB::table('notas_finales')->whereIn('id', $this->definitivas($e))
            ->update(['updated_at' => '2000-01-01 00:00:00']);

        $this->assertGreaterThan(0, $this->desactualizadasDe($e),
            'El montaje no dejó el grupo marcado, así que apagarlo después no demuestra nada.');

        $this->calcular($e)->assertStatus(200);

        $this->assertSame(0, $this->desactualizadasDe($e),
            'El grupo sigue marcado después de pulsar el botón: es el aviso que Zaragoza no podía quitar.');
    }

    /**
     * **Y con un sello viejo, escrito en UTC, el aviso sigue encendido — eso es lo
     * que queda y es a propósito.**
     *
     * Hasta el 21 sep 2026 `subunidades` y `unidades` sellaban con los
     * `timestamps` de Eloquent, que van en UTC, mientras que `notas`,
     * `notas_finales` y todo lo que pasa por {@see Reloj} va en
     * Bogotá: cinco horas dentro de la misma comparación. Medido en la copia de
     * Zaragoza, **2.262 pares (nota, subunidad) separados exactamente cinco horas
     * y ninguno en el mismo segundo**.
     *
     * Los modelos ya sellan en Bogotá ({@see SellaConElReloj}), así
     * que **no se escriben filas nuevas así**. Las que ya estaban no las toca
     * nadie —arreglarlas sería una migración de datos, y la decisión fue no
     * hacerla: se apagan solas en cuanto pasan las cinco horas o alguien vuelve a
     * guardar—. Este caso fija ese residuo: mientras exista una fila vieja en UTC,
     * su asignatura sigue marcada.
     *
     * Si un día se pone rojo, lo que hay que mirar es si alguien devolvió el reloj
     * de Eloquent a esos modelos; el centinela de eso es
     * `RelojUnicoTest::los_modelos_del_sello_sellan_en_bogota`.
     */
    public function test_con_el_sello_en_el_reloj_de_eloquent_el_aviso_no_se_apaga(): void
    {
        $e = $this->escenario();

        // `now()` es el reloj de Eloquent —UTC—, que es con el que se sellan las
        // subunidades: cinco horas por delante de lo que escribe el botón.
        $this->sellarLasNotas($e, now()->subMinute());

        $this->calcular($e)->assertStatus(200);

        $this->assertGreaterThan(0, $this->desactualizadasDe($e),
            'Una fila vieja en UTC ya no marca la asignatura. Si es porque alguien reparó los '
            .'datos, bórrese este caso con la medición al lado; si es porque el detector dejó '
            .'de mirar el sello, es otra cosa y hay que leerla.');
    }

    /** Pone el sello de las notas del grupo en la fecha dada. */
    private function sellarLasNotas(object $e, \DateTimeInterface $cuando): void
    {
        DB::update(
            'UPDATE notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id
               INNER JOIN unidades u ON u.id = s.unidad_id
               INNER JOIN asignaturas a ON a.id = u.asignatura_id
                SET n.updated_at = ?
              WHERE a.grupo_id = ? AND u.periodo_id = ? AND n.deleted_at IS NULL',
            [$cuando->format('Y-m-d H:i:s'), $e->grupo_id, $e->periodo_id]
        );
    }

    /** Cuántas asignaturas del grupo salen marcadas en el tablero. */
    private function desactualizadasDe(object $e): int
    {
        $marcadas = 0;

        foreach (DefinitivasDeAsignatura::estadoDelGrupo((int) $e->grupo_id, (int) $e->periodo_id) as $fila) {
            if ($fila['desactualizada']) {
                $marcadas++;
            }
        }

        return $marcadas;
    }
}
