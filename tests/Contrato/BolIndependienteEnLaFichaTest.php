<?php

namespace Tests\Contrato;

use App\Models\Grupo;
use Illuminate\Support\Facades\DB;

/**
 * Los dos campos que la fase 2 añade a lo que ya existe: la ficha y el badge.
 *
 * §6.4 de [19-boletin-independiente.md](../../docs/migracion/19-boletin-independiente.md).
 *
 *   - `PUT alumnos/show` → **`bol_independiente_periodos`**, la lista de los cuatro
 *     periodos del año con `aplica` y `tiene_datos`. Es por donde la ficha lee la
 *     marca; ya no es un booleano porque la marca ya no es del año.
 *   - `Grupo::alumnos($grupo, '', $periodo)` → **`bol_independiente_datos`**, el badge
 *     de la planilla: `tiene_datos` aplanado al periodo del token.
 *
 * ## Los dos campos nacieron mal y los corrigió el front, y aquí está por qué importa
 *
 * El plan decía que el badge era `alumno.bol_independiente_periodo`. Pero a la lista
 * de `alumnos` sólo llegan los que van **con el grupo**, así que ese booleano valdría
 * `false` en las treinta filas, **siempre y en todos los colegios**. Un campo que no
 * varía no es un campo pobre: es uno sobre el que alguien ramificará sin que su rama
 * muerta se note nunca. Por eso el badge entra con **nombre propio** y separa los dos
 * casos que sí se distinguen — el que tiene estructura propia guardada y el que nunca
 * ha tenido nada.
 *
 * ## Lo que estos tests miran de verdad
 *
 * **Que los dos campos contesten lo mismo.** Están escritos en dos consultas
 * distintas —cuatro periodos de un alumno en `AlumnosController`, treinta alumnos de
 * un periodo en `Grupo`— porque las preguntas tienen forma distinta, y lo que los ata
 * no es una cadena compartida: es este cuadre. Es el mismo criterio que
 * `AlumnosDetrasDelNumeroTest` aplicó a las cifras del panel.
 */
class BolIndependienteEnLaFichaTest extends CasoDeContrato
{
    /**
     * @return array{grupo: int, year: int, periodos: list<int>, alumno: int, asignatura: int}
     */
    private function escenario(): array
    {
        $grupo = $this->grupoConAlumnos();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
              ORDER BY m.alumno_id LIMIT 1',
            [$grupo->id]
        );

        $asignatura = DB::selectOne(
            'SELECT a.id FROM asignaturas a WHERE a.grupo_id = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El grupo elegido no tiene alumnos matriculados.');
        $this->assertNotNull($asignatura, 'El grupo elegido no tiene asignaturas.');
        $this->assertCount(4, $periodos, 'El año del grupo no tiene cuatro periodos.');

        return [
            'grupo' => (int) $grupo->id,
            'year' => (int) $grupo->year_id,
            'periodos' => $periodos,
            'alumno' => (int) $alumno->alumno_id,
            'asignatura' => (int) $asignatura->id,
        ];
    }

    /** Una unidad **con dueño**: es lo que hace `tiene_datos` verdadero. */
    private function unidadPropia(int $asignatura, int $periodo, int $alumno): int
    {
        return (int) DB::table('unidades')->insertGetId([
            'definicion' => 'Unidad del boletín independiente (test)',
            'porcentaje' => 100,
            'periodo_id' => $periodo,
            'asignatura_id' => $asignatura,
            'alumno_id' => $alumno,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * La lista de la ficha, indexada por `periodo_id`.
     *
     * **El token se guarda y se reutiliza, y no es una optimización.** El cuadre de
     * abajo pide la ficha de los treinta y siete alumnos del grupo, y un login por
     * ficha choca contra el limitador de `login/credentials`: la respuesta 38 llega
     * con **429** y el test falla por el instrumento y no por lo que mide.
     */
    private ?string $tokenDelAnio = null;

    private function periodosDeLaFicha(int $alumno, int $year): array
    {
        $this->tokenDelAnio ??= $this->tokenDelPersonalDe($year);

        $r = $this->withToken($this->tokenDelAnio)
            ->putJson('/api/alumnos/show', ['id' => $alumno]);

        $r->assertStatus(200);

        $lista = $r->json('alumno.bol_independiente_periodos');

        $this->assertIsArray($lista, 'La ficha no trae `bol_independiente_periodos`.');

        return collect($lista)->keyBy('periodo_id')->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // bol_independiente_periodos
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Vienen SIEMPRE los cuatro periodos, no sólo las filas que existen.**
     *
     * No es cosmética: una lista con sólo las filas presentes obliga al navegador a
     * decidir qué significa una ausencia, y **este módulo perdió una semana justamente
     * por leer una ausencia al revés** — el `COALESCE(bip.aplica, 1)` que hacía que
     * marcar a un alumno en octubre le repintara el boletín del primer periodo.
     * Mandando los cuatro no hay default que inventar del otro lado.
     */
    public function test_la_ficha_trae_los_cuatro_periodos_aunque_no_haya_ni_una_fila(): void
    {
        $e = $this->escenario();

        $this->assertSame(0, (int) DB::selectOne(
            'SELECT COUNT(*) c FROM bol_ind_periodos WHERE alumno_id = ?', [$e['alumno']]
        )->c, 'El alumno elegido ya tiene filas de marca: el caso «sin ninguna» no se está midiendo.');

        $lista = $this->periodosDeLaFicha($e['alumno'], $e['year']);

        $this->assertCount(4, $lista, 'La ficha no trae los cuatro periodos del año.');

        foreach ($e['periodos'] as $periodo) {
            $this->assertArrayHasKey($periodo, $lista, "Falta el periodo {$periodo}.");
            $this->assertFalse($lista[$periodo]['aplica'], 'Sin fila, un periodo va con el grupo.');
            $this->assertIsBool($lista[$periodo]['aplica'], '`aplica` tiene que ser un booleano y no un "0".');
            $this->assertIsBool($lista[$periodo]['tiene_datos'], '`tiene_datos` tiene que ser un booleano.');
            $this->assertIsInt($lista[$periodo]['numero'], '`numero` tiene que ser un entero.');
        }
    }

    /**
     * **Los cuatro estados, y los cuatro dicen algo distinto.**
     *
     * El que la pantalla tiene que gritar es `aplica` **sin** `tiene_datos`: va aparte
     * y no tiene ni una unidad propia, o sea la §9.1 —su definitiva va a salir 0 y
     * nadie va a recibir un error—. Y el contrario, `tiene_datos` sin `aplica`, es
     * literalmente lo que se pidió: *«no debe borrar los datos … pero esos datos deben
     * ser ignorados»*.
     */
    public function test_los_cuatro_estados_de_aplica_por_tiene_datos(): void
    {
        $e = $this->escenario();
        [$p1, $p2, $p3, $p4] = $e['periodos'];

        // p1: nada de nada — el caso de todo el mundo hoy.
        // p2: marcado y con estructura propia — el estado normal de un marcado.
        $this->marcarIndependiente($e['alumno'], $p2, true);
        $this->unidadPropia($e['asignatura'], $p2, $e['alumno']);
        // p3: marcado y SIN nada suyo — la §9.1, el que se cae por el hueco.
        $this->marcarIndependiente($e['alumno'], $p3, true);
        // p4: con datos guardados pero este periodo va con el grupo.
        $this->marcarIndependiente($e['alumno'], $p4, false);
        $this->unidadPropia($e['asignatura'], $p4, $e['alumno']);

        $lista = $this->periodosDeLaFicha($e['alumno'], $e['year']);

        $esperado = [
            $p1 => [false, false],
            $p2 => [true, true],
            $p3 => [true, false],
            $p4 => [false, true],
        ];

        foreach ($esperado as $periodo => [$aplica, $tieneDatos]) {
            $this->assertSame($aplica, $lista[$periodo]['aplica'],
                "El periodo {$periodo} tenía que llegar con aplica=".var_export($aplica, true));
            $this->assertSame($tieneDatos, $lista[$periodo]['tiene_datos'],
                "El periodo {$periodo} tenía que llegar con tiene_datos=".var_export($tieneDatos, true));
        }
    }

    /** Una unidad borrada blandamente no es «tiene datos»: sale de todos los cálculos. */
    public function test_una_unidad_propia_borrada_no_cuenta_como_datos(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        $unidad = $this->unidadPropia($e['asignatura'], $periodo, $e['alumno']);

        $this->assertTrue($this->periodosDeLaFicha($e['alumno'], $e['year'])[$periodo]['tiene_datos']);

        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);

        $this->assertFalse($this->periodosDeLaFicha($e['alumno'], $e['year'])[$periodo]['tiene_datos'],
            'Una unidad en la papelera sigue contando como estructura propia.');
    }

    /**
     * **El campo viene también por la rama sin matrícula del año**, y eso cierra una
     * trampa que el plan traía escrita.
     *
     * `putShow` tiene dos consultas: si el alumno no tiene matrícula del año del token,
     * cae a una segunda que sale sólo de `alumnos`. La §6.4 avisaba de que ahí el campo
     * no vendría, y `undefined` significaría «no matriculado este año» y no
     * «desmarcado» — dos cosas distintas con la misma cara. Con la marca colgada de
     * `(alumno_id, periodo_id)` el campo **ya no depende de la matrícula**: los periodos
     * salen del año del token.
     */
    public function test_el_campo_viene_tambien_sin_matricula_del_anio(): void
    {
        $e = $this->escenario();

        // Se construye y no se busca: **en el seed no hay ninguno** —los 68 alumnos
        // vivos tienen los 68 matrícula en el año actual, medido el 31 ago 2026—, y
        // saltarse el caso dejaría sin comprobar justo la rama que el plan avisaba de
        // que se quedaría sin el campo.
        $ajeno = (int) DB::table('alumnos')->insertGetId([
            'nombres' => 'Sin',
            'apellidos' => 'Matrícula',
            'sexo' => 'F',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(4, $this->periodosDeLaFicha($ajeno, $e['year']),
            'Por la rama sin matrícula la ficha se quedó sin `bol_independiente_periodos`.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // bol_independiente_datos, el badge
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Sin periodo el campo no viaja**, y eso es lo que deja quietas las veinte
     * respuestas que llaman a `Grupo::alumnos` y no saben de periodos.
     */
    public function test_sin_periodo_el_badge_no_viaja(): void
    {
        $e = $this->escenario();

        foreach (Grupo::alumnos($e['grupo']) as $alumno) {
            $this->assertObjectNotHasProperty('bol_independiente_datos', $alumno,
                'El badge se está colando en las llamadas que no piden periodo: eso mueve veinte respuestas.');
        }
    }

    /** Con periodo, y `false` para todos mientras nadie tenga nada suyo. */
    public function test_con_periodo_el_badge_viaja_y_hoy_es_falso_para_todos(): void
    {
        $e = $this->escenario();

        $alumnos = Grupo::alumnos($e['grupo'], '', $e['periodos'][0]);

        $this->assertNotEmpty($alumnos, 'El grupo elegido no devolvió alumnos.');

        foreach ($alumnos as $alumno) {
            $this->assertObjectHasProperty('bol_independiente_datos', $alumno);
            $this->assertFalse($alumno->bol_independiente_datos);
        }
    }

    /**
     * **El badge distingue al que tiene estructura propia guardada, con el periodo
     * yendo con el grupo.** Que es el caso entero para el que existe.
     */
    public function test_el_badge_marca_al_que_tiene_datos_propios(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        $this->unidadPropia($e['asignatura'], $periodo, $e['alumno']);

        $marcados = [];

        foreach (Grupo::alumnos($e['grupo'], '', $periodo) as $alumno) {
            if ($alumno->bol_independiente_datos) {
                $marcados[] = (int) $alumno->alumno_id;
            }
        }

        $this->assertSame([$e['alumno']], $marcados,
            'El badge no señaló exactamente al alumno con unidad propia.');
    }

    /**
     * **El cuadre: el badge y la ficha contestan lo mismo.**
     *
     * Es lo único que ata las dos consultas, que están escritas por separado a
     * propósito —tienen forma distinta— y que si divergieran harían que la ficha dijera
     * «tiene datos» y la planilla no, sobre el mismo alumno y el mismo periodo. Se
     * comprueba **alumno a alumno y periodo a periodo**, no sobre un total: dos listas
     * que suman lo mismo pueden estar cruzadas.
     */
    public function test_el_badge_de_la_planilla_cuadra_con_la_ficha(): void
    {
        $e = $this->escenario();

        // Se monta un reparto desigual a propósito: sin nadie con datos, las dos
        // consultas coinciden diciendo `false` a todo y el cuadre no comprueba nada.
        $otro = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
                AND m.alumno_id <> ? ORDER BY m.alumno_id LIMIT 1',
            [$e['grupo'], $e['alumno']]
        );

        $this->assertNotNull($otro, 'El grupo necesita dos alumnos para que el cuadre distinga algo.');

        $periodo = $e['periodos'][1];
        $this->unidadPropia($e['asignatura'], $periodo, $e['alumno']);
        $this->unidadPropia($e['asignatura'], $e['periodos'][2], (int) $otro->alumno_id);

        $porBadge = [];

        foreach (Grupo::alumnos($e['grupo'], '', $periodo) as $alumno) {
            $porBadge[(int) $alumno->alumno_id] = (bool) $alumno->bol_independiente_datos;
        }

        $this->assertGreaterThan(1, count($porBadge), 'La planilla trajo un solo alumno: el cuadre no dice nada.');
        $this->assertContains(true, $porBadge, 'Nadie salió con el badge puesto: el caso positivo no se está midiendo.');
        $this->assertContains(false, $porBadge, 'Todos salieron con el badge puesto: el caso negativo no se está midiendo.');

        foreach ($porBadge as $alumnoId => $badge) {
            $ficha = $this->periodosDeLaFicha($alumnoId, $e['year']);

            $this->assertSame($ficha[$periodo]['tiene_datos'], $badge,
                "El badge de la planilla y `tiene_datos` de la ficha discrepan para el alumno {$alumnoId} "
                ."en el periodo {$periodo}: son la misma pregunta contestada por dos consultas.");
        }
    }
}
