<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * La definitiva de un grupo, con y sin alumnos de boletín independiente.
 *
 * BI-2: `DefinitivasDeAsignatura::calcular()` es **el recalculador único**, y era
 * una de las 59 lecturas sin alcance. Acotarla es lo que impide que el día que una
 * unidad tenga dueño **las unidades del independiente entren en la definitiva de
 * los otros treinta**.
 *
 * ## Las dos mitades, y cada una sin la otra no prueba nada
 *
 * 1. **Con nadie marcado no se mueve nada.** Es el criterio de aceptación del lote
 *    —`u.alumno_id <=> NULL` selecciona exactamente las filas de hoy— y lo cubre
 *    además la suite entera sin regenerar un snapshot. Aquí se comprueba sobre la
 *    definitiva concreta, que es el número que sale impreso.
 * 2. **Y con alguien marcado, cambia sólo lo suyo.** Sin esta mitad, la primera se
 *    cumpliría igual **si el alcance no estuviera puesto**: un test que sólo mira
 *    que nada se movió pasa idéntico sobre el código de antes. *Es la trampa de
 *    siempre — comprobar que algo no cambió no prueba que el cambio esté.*
 *
 * La segunda mitad **falla sobre el código anterior a BI-2**, y por eso es la red:
 * sin el `<=>` y sin el `GROUP BY u.alumno_id`, el alumno marcado seguiría
 * llevándose la definitiva calculada con las unidades del grupo.
 *
 * Todo ocurre dentro de la transacción del test: ni la marca ni el dueño salen de
 * aquí.
 */
class DefinitivaConAlcanceTest extends CasoDeContrato
{
    public function test_marcar_a_un_alumno_solo_cambia_lo_suyo(): void
    {
        BoletinIndependiente::olvidar();

        $grupo = $this->grupoConAlumnos();

        $donde = DB::selectOne(
            'SELECT u.asignatura_id, u.periodo_id, u.id AS unidad_id
               FROM unidades u
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL
              WHERE u.deleted_at IS NULL
              GROUP BY u.asignatura_id, u.periodo_id, u.id
              LIMIT 1', [$grupo->id]);

        $this->assertNotNull($donde,
            'El seed no tiene una unidad con notas en este grupo: el test no calcularía nada.');

        $antes = $this->porAlumno($donde->asignatura_id, $donde->periodo_id);

        // **La precondición que hace que el test distinga algo.** Con un solo alumno
        // con definitiva, «cambió lo suyo» y «cambió todo» son lo mismo.
        $this->assertGreaterThan(1, count($antes),
            'Sólo un alumno tiene definitiva en esta asignatura: el test no distinguiría '
            .'«cambia lo suyo» de «cambia todo».');

        $conNotas = array_keys(array_filter($antes, fn ($f) => $f['notas'] > 0));

        $this->assertNotEmpty($conNotas, 'Ningún alumno tiene notas: no hay nada que mover.');

        $marcado = $conNotas[0];

        // ── Mitad 1: nadie marcado, nada se mueve ────────────────────────────────
        $this->assertSame($antes, $this->porAlumno($donde->asignatura_id, $donde->periodo_id),
            'Dos llamadas seguidas sin tocar nada dieron resultados distintos: el cálculo no es '
            .'estable y el resto del test no mediría lo que cree.');

        // ── Mitad 2: marcamos a uno ──────────────────────────────────────────────
        // Marca **este** periodo, que es el que la consulta está calculando. Antes
        // esto era un `UPDATE matriculas SET boletin_independiente = 1`, o sea el año
        // entero: con la decisión 7 la marca es por periodo y esa columna se retiró.
        $this->marcarIndependiente($marcado, (int) $donde->periodo_id);

        $despues = $this->porAlumno($donde->asignatura_id, $donde->periodo_id);

        // El marcado pasa a mirar SUS unidades, y no tiene ninguna: se queda sin
        // notas. Eso es lo correcto y es lo que hace visible que el alcance está
        // puesto — con el código de antes seguiría llevándose la del grupo.
        $this->assertSame(0, $despues[$marcado]['notas'],
            'El alumno '.$marcado.' está marcado como de boletín independiente y NO tiene '
            .'unidades propias, así que su definitiva debería calcularse sobre cero notas. '
            .'Siguió llevándose la del grupo: el alcance no está llegando a la consulta.');

        // Y los demás, intactos — que es la otra mitad de «sólo cambia lo suyo».
        foreach ($antes as $alumnoId => $fila) {
            if ($alumnoId === $marcado) {
                continue;
            }

            $this->assertSame($fila, $despues[$alumnoId],
                'La definitiva del alumno '.$alumnoId.' cambió al marcar a OTRO como de boletín '
                .'independiente. El alcance está separando mal: lo de uno movió lo de los demás.');
        }
    }

    /** @return array<int, array{nota:int, notas:int}> */
    private function porAlumno($asignaturaId, $periodoId): array
    {
        $filas = [];

        foreach (DefinitivasDeAsignatura::calcular((int) $asignaturaId, (int) $periodoId) as $f) {
            $filas[(int) $f->alumno_id] = ['nota' => (int) $f->nota, 'notas' => (int) $f->notas];
        }

        ksort($filas);

        return $filas;
    }
}
