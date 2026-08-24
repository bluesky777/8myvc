<?php

namespace Tests\Barrido;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * `PUT promovidos/calcular-grupo`: el único del censo que tiene el patrón **y
 * escribe**.
 *
 *     docker exec -w /app/.worktrees/12 -e DB_TEST_DATABASE=simonbolivar_testing_12 \
 *         8myvc-app-1 php artisan test --group=barrido --filter=CosteYAtomicidadDePromovidosTest
 *
 * La [05 §224](../../docs/migracion/05-codigo-muerto-y-roto.md) midió las ocho
 * copias del patrón de `asignaturasPerdidasDeAlumno` por **coste**. Ésta es la
 * única en la que el coste **no es la pregunta interesante**: hace un
 * `DB::update` **dentro del bucle de alumnos**, y lo que escribe es
 * `matriculas.promovido` — **quién pasa el año**.
 *
 * O sea que la pregunta no es «cuánto tarda» sino **«qué queda si se corta a la
 * mitad»**, que es distinta y no la contesta ningún conteo de consultas.
 *
 * ## Lo que ya está cubierto y NO se repite aquí
 *
 * - **quién puede llamarlo**: `SuperficieDeUnAlumnoTest::test_una_familia_no_decide_quien_pasa_el_anio`,
 *   y lo comprueba **por el efecto** —un 403 que llegue después del `UPDATE` no
 *   vale de nada—;
 * - **la forma de la respuesta**: `GruposTest::test_la_forma_del_calculo_de_promovidos`,
 *   con instantánea.
 *
 * Lo que no cubre nadie es **el volumen, la atomicidad y la idempotencia**, y las
 * tres juntas son las que dicen si un corte a la mitad es recuperable.
 *
 * ## Imprime, no comprueba (salvo el falso verde)
 *
 * Los números dependen del seed. Lo único que se afirma es que **el oyente contó
 * algo** y que **la segunda llamada no movió nada** — que es el hallazgo, no una
 * cota.
 */
#[Group('barrido')]
class CosteYAtomicidadDePromovidosTest extends CasoDeContrato
{
    public function test_cuanto_escribe_y_que_queda_si_se_corta(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $columnas = ['promovido', 'promedio', 'cant_asign_perdidas', 'cant_areas_perdidas'];

        $foto = fn () => DB::table('matriculas')
            ->where('grupo_id', $grupo->id)
            ->orderBy('id')
            ->get(array_merge(['id'], $columnas))
            ->map(fn ($f) => (array) $f)
            ->keyBy('id')
            ->all();

        $protegidas = DB::table('matriculas')
            ->where('grupo_id', $grupo->id)
            ->where('promovido', 'LIKE', '%(manual)%')
            ->count();

        $vivas = DB::table('matriculas')->where('grupo_id', $grupo->id)->count();

        // Oyente único, fuera de todo bucle. Ver `CosteDelBoletinFinalTest`.
        $conteo = ['total' => 0, 'update_matriculas' => 0];

        DB::listen(function ($c) use (&$conteo) {
            $conteo['total']++;

            if (preg_match('~^\s*UPDATE\s+matriculas~i', $c->sql) === 1) {
                $conteo['update_matriculas']++;
            }
        });

        $antes = $foto();

        $conteo = ['total' => 0, 'update_matriculas' => 0];
        $desde = hrtime(true);
        $this->putJson('/api/promovidos/calcular-grupo', ['grupo_id' => $grupo->id],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);
        $ms1 = (hrtime(true) - $desde) / 1e6;
        $primera = $conteo;

        $tras1 = $foto();

        // **La segunda llamada es la pregunta entera.** Si el cálculo es
        // idempotente, un corte a la mitad se cura repitiéndolo y el daño de una
        // petición interrumpida es «hay que volver a darle»; si no lo es, el
        // grupo queda en un estado que nadie sabe reconstruir.
        $conteo = ['total' => 0, 'update_matriculas' => 0];
        $desde = hrtime(true);
        $this->putJson('/api/promovidos/calcular-grupo', ['grupo_id' => $grupo->id],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);
        $ms2 = (hrtime(true) - $desde) / 1e6;
        $segunda = $conteo;

        $tras2 = $foto();

        $cambiadas1 = $this->cuantasDifieren($antes, $tras1, $columnas);
        $cambiadas2 = $this->cuantasDifieren($tras1, $tras2, $columnas);

        fwrite(STDERR, sprintf(
            "\n%s\n".
            "  grupo %d · %d matrículas, de ellas %d marcadas `(manual)`\n".
            "  base `%s`\n".
            "  %s\n".
            "  primera llamada   %5d consultas · %3d UPDATE matriculas · %6.0f ms · %d filas distintas\n".
            "  segunda llamada   %5d consultas · %3d UPDATE matriculas · %6.0f ms · %d filas distintas\n".
            "  %s\n".
            "  atomicidad:   NINGUNA transacción en PromovidosController — %d escrituras sueltas\n".
            "  idempotencia: la segunda llamada dejó %s\n".
            "  %s\n\n",
            '`PUT promovidos/calcular-grupo` — qué escribe y qué queda si se corta',
            $grupo->id, $vivas, $protegidas,
            DB::connection()->getDatabaseName(),
            str_repeat('-', 78),
            $primera['total'], $primera['update_matriculas'], $ms1, $cambiadas1,
            $segunda['total'], $segunda['update_matriculas'], $ms2, $cambiadas2,
            str_repeat('-', 78),
            $primera['update_matriculas'],
            $cambiadas2 === 0 ? 'EL MISMO ESTADO (idempotente)' : 'OTRO estado — NO es idempotente',
            str_repeat('-', 78)
        ));

        $this->assertGreaterThan(0, $primera['total'],
            'El oyente no contó ninguna consulta: el informe de arriba no mide nada.');

        // **Éste sí es un hallazgo y no una cota**: si deja de ser idempotente,
        // un corte a la mitad pasa de «vuelve a darle» a «reconstruye a mano
        // quién pasó el año», y eso cambia la gravedad entera de la §226.
        $this->assertSame(0, $cambiadas2,
            'La segunda llamada seguida cambió '.$cambiadas2.' filas: el cálculo ya NO es '
            .'idempotente, así que una petición cortada a la mitad deja el grupo en un estado '
            .'que repetir la llamada no arregla.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $a
     * @param  array<int, array<string, mixed>>  $b
     * @param  array<int, string>  $columnas
     */
    private function cuantasDifieren(array $a, array $b, array $columnas): int
    {
        $n = 0;

        foreach ($a as $id => $fila) {
            foreach ($columnas as $col) {
                if (($b[$id][$col] ?? null) !== $fila[$col]) {
                    $n++;
                    break;
                }
            }
        }

        return $n;
    }
}
