<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * El **alcance** de la plantilla de notas — la Entrega 7(a) de
 * [28](../../docs/migracion/28-competencias-e-indicadores.md) §5.7.a, decisión 8
 * de Joseth del 2 sep 2026.
 *
 * ## De qué va, en una frase
 *
 * Una fila de `unidades_por_defecto` puede decir a qué **nivel educativo** y a qué
 * **materia** va dirigida. Existe porque preescolar no necesita otro modelo sino
 * una plantilla de **una fila**: medido, **803 de 1.169** subunidades de preescolar
 * repiten literalmente el texto de su unidad, contra **76 de 31.873** en el resto
 * del colegio. La docente teclea el logro dos veces porque la nota no puede colgar
 * de la unidad — el segundo piso es un peaje, no una herramienta.
 *
 * ## Los tres fallos que esto existe para cazar, y los tres son mudos
 *
 * **1. Que la migración no sea aditiva de verdad.** Con las dos columnas a NULL en
 * todas las filas —que es como amanecen los dieciséis colegios— el sembrador tiene
 * que seleccionar **exactamente** las mismas filas que antes. Si no, el estreno de
 * esta tanda es que a alguien se le queda la rejilla vacía.
 *
 * **2. Que la precedencia dependa del orden de inserción.** Un `ORDER BY id LIMIT 1`
 * hace lo correcto hasta el día que alguien reescribe una fila, y entonces la
 * plantilla de un colegio cambia sin que nadie haya tocado nada. Por eso el caso
 * de abajo **invierte el orden de inserción**: es lo único que distingue una
 * precedencia de una casualidad.
 *
 * **3. Que se mezclen dos gradas.** Una plantilla son varias unidades que suman
 * 100. «Las cuatro de siempre **más** la única de preescolar» suma 200, y el
 * síntoma no es un error: es una definitiva mal repartida.
 */
class AlcanceDeLaPlantillaTest extends CasoDeContrato
{
    /**
     * **La prueba de que los dieciséis colegios no se enteran.**
     *
     * Una plantilla con las dos columnas a NULL —o sea, toda fila que existía antes
     * de `2026_09_05_200000_alcance_de_la_plantilla`— se siembra igual que siempre.
     * Sin esto, la lectura de que «es aditiva» sería una afirmación y no una
     * medición.
     */
    #[Test]
    public function test_una_plantilla_sin_alcance_se_siembra_como_siempre(): void
    {
        $e = $this->escenario();
        $this->filaDePlantilla($e->year_id, 'La de siempre', null, null);

        $this->abrirLaRejilla($e, $e->asignatura_limpia)->assertStatus(200);

        $this->assertSame(['La de siempre'], $this->unidadesSembradas($e->asignatura_limpia, $e->periodo),
            'Con las dos columnas a NULL el sembrador tiene que copiar exactamente lo que copiaba antes.');
    }

    /**
     * Una fila dirigida a preescolar **no** le cae a un grupo de básica.
     *
     * Es la mitad que se ve. La otra —que sí le cae a preescolar— va en el caso de
     * abajo, y hacen falta las dos: un filtro que no seleccione **nada** pasaría
     * ésta y dejaría la plantilla de preescolar sin sembrar a nadie.
     */
    #[Test]
    public function test_una_fila_de_preescolar_no_se_siembra_en_basica(): void
    {
        $e = $this->escenario();
        $this->filaDePlantilla($e->year_id, 'Valoración del periodo', $e->nivel_preescolar, null);

        $this->abrirLaRejilla($e, $e->asignatura_limpia)->assertStatus(200);

        $this->assertSame([], $this->unidadesSembradas($e->asignatura_limpia, $e->periodo),
            'La plantilla de UNA fila de preescolar se le sembró a un grupo de básica: '.
            'esa asignatura acaba de quedarse con una sola casilla al 100 %.');
    }

    /** Y a preescolar sí, que es para lo que existe. */
    #[Test]
    public function test_una_fila_de_preescolar_si_se_siembra_en_preescolar(): void
    {
        $e = $this->escenario();
        $this->filaDePlantilla($e->year_id, 'Valoración del periodo', $e->nivel_preescolar, null);

        $this->abrirLaRejilla($e, $e->asignatura_preescolar)->assertStatus(200);

        $this->assertSame(['Valoración del periodo'],
            $this->unidadesSembradas($e->asignatura_preescolar, $e->periodo));
    }

    /**
     * **Gana la más específica, y el orden de inserción no manda.** Los dos órdenes
     * posibles. **Van como dos casos y no como un
     * bucle dentro de uno**, y eso lo decidió un rojo: las dos vueltas compartían
     * año, así que la plantilla de la primera seguía viva en la segunda y el test
     * fallaba por sus propios restos. Con el proveedor, cada orden corre en su
     * propia transacción y el escenario nace limpio.
     *
     * @return array<string, array{list<string>}>
     */
    public static function losDosOrdenes(): array
    {
        return [
            'primero la general' => [['general', 'especifica']],
            'primero la específica' => [['especifica', 'general']],
        ];
    }

    #[Test]
    #[DataProvider('losDosOrdenes')]
    public function test_la_mas_especifica_gana_pase_lo_que_pase_con_el_orden(array $orden): void
    {
        $e = $this->escenario();

        foreach ($orden as $cual) {
            $cual === 'general'
                ? $this->filaDePlantilla($e->year_id, 'La de siempre', null, null)
                : $this->filaDePlantilla($e->year_id, 'Valoración del periodo', $e->nivel_preescolar, null);
        }

        $this->abrirLaRejilla($e, $e->asignatura_preescolar)->assertStatus(200);

        $this->assertSame(['Valoración del periodo'],
            $this->unidadesSembradas($e->asignatura_preescolar, $e->periodo),
            'Insertando en el orden ['.implode(', ', $orden).'] ganó la que no era: '.
            'la precedencia está saliendo del orden de inserción y no de la especificidad.');

        // Y la general sigue valiendo para quien no tiene una más específica.
        $this->abrirLaRejilla($e, $e->asignatura_limpia)->assertStatus(200);
        $this->assertSame(['La de siempre'],
            $this->unidadesSembradas($e->asignatura_limpia, $e->periodo),
            'La fila de preescolar le quitó la suya a un grupo de básica.');
    }

    /**
     * **Las dos gradas no se mezclan: se aplica una entera.**
     *
     * Con una fila general al 100 y una de preescolar al 100, preescolar tiene que
     * recibir **una** unidad y no dos. Si se mezclaran, el reparto sumaría 200 y la
     * definitiva saldría a la mitad **sin que nada dé error**.
     */
    #[Test]
    public function test_no_se_mezclan_dos_gradas_en_el_mismo_reparto(): void
    {
        $e = $this->escenario();
        $this->filaDePlantilla($e->year_id, 'General A', null, null);
        $this->filaDePlantilla($e->year_id, 'General B', null, null);
        $this->filaDePlantilla($e->year_id, 'Valoración del periodo', $e->nivel_preescolar, null);

        $this->abrirLaRejilla($e, $e->asignatura_preescolar)->assertStatus(200);

        $this->assertSame(['Valoración del periodo'],
            $this->unidadesSembradas($e->asignatura_preescolar, $e->periodo),
            'Se mezclaron las dos gradas: el reparto de esa asignatura suma 200 y nadie lo va a ver.');
    }

    /**
     * **El nivel le gana a la materia**, que es la única decisión discutible de todo
     * esto y por eso lleva su propio caso.
     *
     * Si una fila «esta materia, en todo el colegio» le ganara a «todo preescolar»,
     * la docente de preescolar volvería a recibir la rejilla de dos pisos que este
     * módulo existe para quitarle — y el caso llegaría el primer día que un colegio
     * dirigiera una plantilla por materia, que es lo natural en bachillerato.
     */
    #[Test]
    public function test_el_nivel_le_gana_a_la_materia(): void
    {
        $e = $this->escenario();
        $materia = (int) DB::table('asignaturas')->where('id', $e->asignatura_preescolar)->value('materia_id');

        $this->filaDePlantilla($e->year_id, 'Por materia', null, $materia);
        $this->filaDePlantilla($e->year_id, 'Valoración del periodo', $e->nivel_preescolar, null);

        $this->abrirLaRejilla($e, $e->asignatura_preescolar)->assertStatus(200);

        $this->assertSame(['Valoración del periodo'],
            $this->unidadesSembradas($e->asignatura_preescolar, $e->periodo));
    }

    /**
     * Una fila dirigida a un nivel **y** a una materia le gana a las dos anteriores.
     * Es la grada 3, y va porque una precedencia con tres escalones probados y el
     * cuarto sin probar es media precedencia.
     */
    #[Test]
    public function test_nivel_y_materia_juntos_ganan_a_cada_uno_por_separado(): void
    {
        $e = $this->escenario();
        $materia = (int) DB::table('asignaturas')->where('id', $e->asignatura_preescolar)->value('materia_id');

        $this->filaDePlantilla($e->year_id, 'La de siempre', null, null);
        $this->filaDePlantilla($e->year_id, 'Por materia', null, $materia);
        $this->filaDePlantilla($e->year_id, 'Por nivel', $e->nivel_preescolar, null);
        $this->filaDePlantilla($e->year_id, 'Nivel y materia', $e->nivel_preescolar, $materia);

        $this->abrirLaRejilla($e, $e->asignatura_preescolar)->assertStatus(200);

        $this->assertSame(['Nivel y materia'],
            $this->unidadesSembradas($e->asignatura_preescolar, $e->periodo));
    }

    /**
     * Una fila dirigida a un nivel que **ningún grupo tiene** no se siembra en
     * ninguna parte, y sobre todo **no deja a nadie sin plantilla**.
     *
     * Es el fallo que dejaría la ausencia de clave foránea si no se pensara: una
     * fila apuntando a un nivel borrado se queda quieta. Lo que no puede pasar es
     * que su presencia tape a la general — eso sería una plantilla que desaparece
     * porque alguien reorganizó el catálogo de niveles.
     */
    #[Test]
    public function test_una_fila_dirigida_a_un_nivel_que_nadie_tiene_no_tapa_a_la_general(): void
    {
        $e = $this->escenario();
        $huerfano = (int) DB::table('niveles_educativos')->insertGetId([
            'nombre' => 'Nivel sin grupos', 'orden' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->filaDePlantilla($e->year_id, 'La de siempre', null, null);
        $this->filaDePlantilla($e->year_id, 'La huérfana', $huerfano, null);

        $this->abrirLaRejilla($e, $e->asignatura_limpia)->assertStatus(200);

        $this->assertSame(['La de siempre'], $this->unidadesSembradas($e->asignatura_limpia, $e->periodo));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ayudantes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un profesor con el periodo **abierto** —sin eso el sembrador no escribe, que
     * es la regla 2 de §4— y dos asignaturas sin ninguna unidad: una de básica y
     * otra de preescolar.
     */
    private function escenario(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $preescolar = DB::selectOne('SELECT g.id, g.nivel_educativo_id FROM grados g
            INNER JOIN niveles_educativos n ON n.id = g.nivel_educativo_id AND n.deleted_at IS NULL
            WHERE g.deleted_at IS NULL AND g.nivel_educativo_id IS NOT NULL
            ORDER BY n.id, g.orden, g.id LIMIT 1');

        $basica = DB::selectOne('SELECT g.id, g.nivel_educativo_id FROM grados g
            WHERE g.deleted_at IS NULL AND g.nivel_educativo_id IS NOT NULL
              AND g.nivel_educativo_id <> ? ORDER BY g.id LIMIT 1', [$preescolar->nivel_educativo_id]);

        $this->assertNotNull($basica,
            'El seed no tiene dos niveles educativos distintos, y sin ellos esto no mide nada.');

        return (object) [
            'token' => $token,
            'periodo' => (int) $suyo->id,
            'year_id' => (int) $suyo->year_id,
            'nivel_preescolar' => (int) $preescolar->nivel_educativo_id,
            'asignatura_preescolar' => $this->asignaturaLimpiaEn((int) $suyo->year_id, (int) $preescolar->id),
            'asignatura_limpia' => $this->asignaturaLimpiaEn((int) $suyo->year_id, (int) $basica->id),
        ];
    }

    /**
     * Un grupo nuevo de ese grado con una asignatura dentro, **sin ninguna unidad**.
     *
     * Hace falta crearlo y no reutilizar uno del seed por lo que ya avisa
     * `grupoAjenoDelMismoAnio`: las asignaturas del seed **ya tienen unidades**, y
     * el sembrador sólo entra cuando hay cero. Con una asignatura montada, todos
     * estos casos pasarían con el alcance y sin él.
     */
    private function asignaturaLimpiaEn(int $yearId, int $gradoId): int
    {
        $grupoId = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo de prueba del grado '.$gradoId,
            'abrev' => 'PRU',
            'year_id' => $yearId,
            'grado_id' => $gradoId,
            'orden' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $materia = DB::selectOne('SELECT id FROM materias WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        return (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => $materia->id,
            'grupo_id' => $grupoId,
            'profesor_id' => $profesor->id,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function filaDePlantilla(int $yearId, string $definicion, ?int $nivelId, ?int $materiaId): int
    {
        $id = (int) DB::table('unidades_por_defecto')->insertGetId([
            'definicion' => $definicion,
            'porcentaje' => 100,
            'year_id' => $yearId,
            'obligatoria' => 0,
            'orden' => 0,
            'nivel_educativo_id' => $nivelId,
            'materia_id' => $materiaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subunidades_por_defecto')->insert([
            'definicion' => $definicion.' — única',
            'porcentaje' => 100,
            'unidad_defec_id' => $id,
            'nota_default' => 0,
            'obligatoria' => 0,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** El `GET` que lee y **de paso siembra**, que es el camino que hay que medir. */
    private function abrirLaRejilla(object $e, int $asignaturaId)
    {
        return $this->withToken($e->token)
            ->getJson("/api/unidades/de-asignatura-periodo/{$asignaturaId}/{$e->periodo}");
    }

    /**
     * Lo que quedó **en la tabla**, no lo que devolvió la respuesta.
     *
     * @return list<string>
     */
    private function unidadesSembradas(int $asignaturaId, int $periodoId): array
    {
        return array_values(DB::table('unidades')
            ->where('asignatura_id', $asignaturaId)
            ->where('periodo_id', $periodoId)
            ->whereNull('deleted_at')
            ->orderBy('orden')->orderBy('id')
            ->pluck('definicion')->map(fn ($d) => (string) $d)->all());
    }
}
