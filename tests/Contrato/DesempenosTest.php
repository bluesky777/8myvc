<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las siete rutas de `desempenos` — el **modelo plano** de
 * [39](../../docs/migracion/39-el-modelo-plano-por-competencias.md).
 *
 * ## Qué queda de este fichero, y por qué se fue lo demás
 *
 * Tenía **veintitrés casos sobre veintiuna rutas**, y catorce de esas rutas ya no
 * existen: **D31** borró la capa por asignatura —el colegio y el docente escriben
 * las mismas filas físicas, así que no hay a dónde sembrar ni `por_defecto` que
 * candar— y **P1.bis** borró la rejilla —el nivel se deriva de la definitiva al
 * imprimir, no se marca casilla a casilla—.
 *
 * Con eso se cayeron, y **no por recortar sino porque afirmaban un contrato que ya
 * no existe**: los siete de `sembrar`, los seis del candado de la D14 y su rodeo,
 * los dos del `<=>` de la planilla del docente, y el que exigía **403 al docente
 * llano en las siete del colegio** — que es justo lo contrario de lo que ahora
 * manda la **P1.quater**: el docente escribe en la materia y el grado que da.
 *
 * > **Y lo que entró en su lugar no tiene caso aquí todavía**, que es deliberado y
 * > no un olvido: el permiso con alcance —`Autoriza::puedeEscribirDesempenos`— y el
 * > copiado entre años por `periodos.numero` se prueban **a mano primero**, por
 * > preferencia explícita de Joseth. Lo que este fichero no puede hacer es quedarse
 * > en rojo afirmando lo de antes.
 *
 * ## Lo que sigue cazando, y ninguno de los tres da error solo
 *
 * **1. `copiar` inventándose un origen `men`.** Es la **D12**: el MEN publica por
 * conjunto de grados y el desempeño va **por periodo**, así que no hay catálogo
 * externo que traer. El 422 lo dice con esas palabras en vez de contestar un «tipo
 * no válido» que invitaría a implementarlo.
 *
 * **2. Un `orden` parcial.** La lista tiene que traer el grupo entero: una lista a
 * medias deja huecos y repetidos, que es el estado del que se viene.
 *
 * **3. `definicion` volviendo a ser `varchar(255)`.** Es
 * `FraseLargaEnElBoletinTest` aplicado a este camino: el síntoma sería un 200 con
 * el desempeño cortado a mitad de palabra **y ya impreso**.
 */
class DesempenosTest extends CasoDeContrato
{
    /**
     * **No hay origen `men`, y eso es la D12 entera**: el MEN publica estándares
     * por conjunto de grados y el desempeño va **por periodo**, que es lo que cada
     * colegio decide en su plan de área. El 422 lo dice con esas palabras en vez de
     * contestar un «tipo no válido» que invitaría a implementarlo.
     */
    #[Test]
    public function test_copiar_no_acepta_el_origen_men(): void
    {
        $caso = $this->unCasoLimpio();

        $r = $this->pedir('putJson', 'desempenos/copiar', [
            'destino' => ['materia_id' => $caso->materia_id, 'periodo_id' => $caso->periodo_id],
            'origen' => ['tipo' => 'men'],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('men', strtolower((string) $r->json('message')));
    }

    #[Test]
    public function test_copiar_de_otro_grado_trae_los_textos_y_no_duplica(): void
    {
        $caso = $this->unCasoLimpio();
        $otroGrado = (int) DB::table('grados')->where('id', '<>', $caso->grado_id)
            ->whereNull('deleted_at')->orderBy('id')->value('id');

        $this->enElPlan($caso, ['definicion' => 'El que ya estaba escrito']);

        $cuerpo = [
            'destino' => [
                'materia_id' => $caso->materia_id,
                'grado_id' => $otroGrado,
                'periodo_id' => $caso->periodo_id,
            ],
            'origen' => ['tipo' => 'grado', 'grado_id' => $caso->grado_id],
        ];

        $primera = $this->pedir('putJson', 'desempenos/copiar', $cuerpo);
        $primera->assertStatus(200);
        $this->assertSame(1, $primera->json('copiados'));

        $segunda = $this->pedir('putJson', 'desempenos/copiar', $cuerpo);
        $segunda->assertStatus(200);
        $this->assertSame(0, $segunda->json('copiados'));
        $this->assertSame(1, $segunda->json('saltados_por_duplicado'));
    }

    /** El origen igual al destino es un 200 que no hace nada: 422 con el motivo. */
    #[Test]
    public function test_copiar_un_grupo_sobre_si_mismo_es_422(): void
    {
        $caso = $this->unCasoLimpio();

        $this->pedir('putJson', 'desempenos/copiar', [
            'destino' => [
                'materia_id' => $caso->materia_id,
                'grado_id' => $caso->grado_id,
                'periodo_id' => $caso->periodo_id,
            ],
            'origen' => ['tipo' => 'grado', 'grado_id' => $caso->grado_id],
        ])->assertStatus(422);
    }

    #[Test]
    public function test_reordenar_el_plan_exige_el_grupo_entero(): void
    {
        $caso = $this->unCasoLimpio();
        $uno = $this->enElPlan($caso, ['definicion' => 'Primero']);
        $otro = $this->enElPlan($caso, ['definicion' => 'Segundo', 'orden' => 1]);

        $base = [
            'materia_id' => $caso->materia_id,
            'grado_id' => $caso->grado_id,
            'periodo_id' => $caso->periodo_id,
        ];

        $this->pedir('putJson', 'desempenos/orden', $base + ['orden' => [$uno]])
            ->assertStatus(422);

        $r = $this->pedir('putJson', 'desempenos/orden', $base + ['orden' => [$otro, $uno]]);
        $r->assertStatus(200);
        $this->assertSame(0, (int) DB::table('desempenos_por_defecto')->where('id', $otro)->value('orden'));
    }

    /**
     * **El texto largo viaja entero**, que es `FraseLargaEnElBoletinTest` aplicado a
     * este camino. Con `definicion varchar(255)` este caso se ve rojo, y el síntoma
     * sería un 200 con el desempeño cortado a mitad de palabra **ya impreso**.
     */
    #[Test]
    public function test_un_desempeno_de_302_caracteres_no_se_corta(): void
    {
        $caso = $this->unCasoLimpio();
        $largo = str_repeat('Reconoce y explica con sus palabras el porqué. ', 7);
        $largo = mb_substr($largo, 0, 302);

        $this->assertSame(302, mb_strlen($largo));

        $r = $this->pedir('postJson', 'desempenos', [
            'materia_id' => $caso->materia_id,
            'grado_id' => $caso->grado_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => $largo,
        ]);

        $r->assertStatus(200);
        $this->assertSame($largo, $r->json('definicion'));
        $this->assertSame($largo,
            DB::table('desempenos_por_defecto')->where('id', $r->json('id'))->value('definicion'));
    }

    /**
     * **Ningún informe de hoy lee el plan de área**, y por eso un año sin plan
     * escrito da el boletín de hoy.
     *
     * Es más fuerte que comparar una respuesta con la tabla vacía, porque **esa
     * comparación pasaría igual con el boletín ya enchufado**. El día que llegue un
     * informe nuevo que la lea, es él quien se añade a la lista, no esta regla la
     * que se borra.
     *
     * > **Ese día llegó el 13 sep 2026** y la excepción es una,
     * > `BoletinPorCompetenciasController`. Para los tres de hoy la regla sigue
     * > entera, y desde el 17 sep con más motivo: el boletín plano lee
     * > `desempenos_por_defecto` **vivo**, así que un informe de los de siempre que
     * > se asomara ahí cambiaría de contenido sin que nadie lo pidiera.
     */
    #[Test]
    public function test_ningun_informe_de_hoy_lee_las_tablas_de_desempenos(): void
    {
        $miradas = array_merge(
            glob(app_path('Http/Controllers/Informes/*.php')) ?: [],
            [
                app_path('Models/Unidad.php'),
                app_path('Services/BoletinIndependiente.php'),
                app_path('Http/Controllers/UnidadesController.php'),
                app_path('Http/Controllers/NotasController.php'),
                app_path('Http/Controllers/FrasesAsignaturaController.php'),
            ]
        );

        // **El boletín nuevo SÍ la lee: es su razón de existir.** Va por nombre, para
        // que un informe nuevo que la lea sin decirlo siga saliendo en rojo.
        $elDeLaFase6 = 'BoletinPorCompetenciasController.php';

        $culpables = [];

        foreach ($miradas as $fichero) {
            if (! is_file($fichero) || basename($fichero) === $elDeLaFase6) {
                continue;
            }

            $texto = (string) file_get_contents($fichero);

            if (preg_match('/\b(FROM|JOIN|INTO|UPDATE|table\()\s*[\'"`]?desempenos/i', $texto) === 1) {
                $culpables[] = basename($fichero);
            }
        }

        $this->assertTrue(
            is_file(app_path('Http/Controllers/Informes/'.$elDeLaFase6)),
            'La excepción de la Fase 6 apunta a un fichero que ya no existe: quítala.'
        );

        $this->assertSame([], $culpables,
            'Un informe de los de hoy pasó a leer `desempenos`: '.implode(', ', $culpables).".\n".
            'Los tres boletines de hoy NO cambian (D16, y la §4 del doc 28).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ayudantes
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un grupo nuevo con una asignatura dentro, **sin una sola fila de plan de
     * área**, en el año del token y con los periodos abiertos.
     *
     * Es **nuevo y no del seed** porque «una materia y un grado sin plan escrito»
     * no se puede sacar con un `WHERE`: la transacción del test lo deshace al
     * terminar.
     */
    private function unCasoLimpio(): object
    {
        $yearId = $this->anioDelToken();

        $molde = DB::selectOne('SELECT grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$yearId]);
        $this->assertNotNull($molde, "El seed no tiene ningún grupo en el año {$yearId}.");

        $grupoId = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo limpio de desempeños', 'abrev' => 'LID', 'year_id' => $yearId,
            'grado_id' => $molde->grado_id, 'orden' => 97, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $materia = (int) DB::table('materias')->whereNull('deleted_at')->orderBy('id')->value('id');
        $profesor = (int) DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');

        $asignaturaId = (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => $materia, 'grupo_id' => $grupoId, 'profesor_id' => $profesor,
            'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('periodos')->where('year_id', $yearId)->update(['profes_pueden_editar_notas' => 1]);

        $periodoId = (int) DB::table('periodos')->where('year_id', $yearId)
            ->whereNull('deleted_at')->orderBy('numero')->orderBy('id')->value('id');

        return (object) [
            'year_id' => $yearId,
            'grupo_id' => $grupoId,
            'grado_id' => (int) $molde->grado_id,
            'materia_id' => $materia,
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoId,
        ];
    }

    /** Una fila del plan de área dirigida a ese caso. */
    private function enElPlan(object $caso, array $campos = []): int
    {
        return (int) DB::table('desempenos_por_defecto')->insertGetId($campos + [
            'year_id' => $caso->year_id,
            'materia_id' => $caso->materia_id,
            'grado_id' => $caso->grado_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => 'Desempeño de prueba',
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pedir(string $verbo, string $ruta, array $cuerpo = [])
    {
        return $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDe($this->usuarioDeTipo('Usuario')->username));
    }

    /**
     * **`getJson` no acepta cuerpo y los otros tres sí**, y su segundo parámetro son
     * las cabeceras: pasarle el cuerpo ahí da un `TypeError` dentro de `json_encode`
     * que no se parece en nada a la causa.
     */
    private function llamar(string $verbo, string $ruta, array $cuerpo, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        if ($verbo === 'getJson') {
            return $this->getJson("/api/{$ruta}", $cabeceras);
        }

        return $this->{$verbo}("/api/{$ruta}", $cuerpo, $cabeceras);
    }

    private function anioDelToken(): int
    {
        return (int) $this->pedir('getJson', 'desempenos')->json('year_id');
    }
}
