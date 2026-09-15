<?php

namespace Tests\Contrato;

use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El avance del docente no puede bajar por una columna que el modo le dijo que
 * dejara de mantener.**
 *
 * `ChangeAskedController::getToMe` publica un porcentaje de avance por docente que
 * sale a medias de dos preguntas: si las **unidades** de cada asignatura suman 100 y
 * si las **subunidades** de cada unidad suman 100. La segunda deja de significar
 * nada con `years.reparto_subunidades = 'promedio'`, porque ese reparto es
 * exactamente el trabajo que la Entrega 5 le quita al docente (28 §5.5) — y
 * `subunidades.porcentaje` es `int NULL DEFAULT 0`, así que cada subunidad nueva
 * entra con **0**.
 *
 * O sea que un colegio que encendiera el promedio vería a **todos** sus docentes con
 * la mitad de la barra clavada en cero, sin una sola pantalla que dijera por qué. No
 * es un rótulo raro: es una cuenta que sale como **juicio sobre el trabajo de una
 * persona**.
 *
 * ## Es el mismo fallo que ya estaba documentado ahí, por el otro lado
 *
 * El comentario que vive encima de esa consulta cuenta que un alumno con boletín
 * independiente hacía sumar `100 + 100 = 200` y **bajaba el avance del docente sin
 * que él hubiera hecho nada mal**. Esto es la misma familia: la métrica castiga una
 * condición que el docente no controla. Lo que cambia es de dónde viene.
 *
 * ## Por qué hacen falta los dos casos
 *
 * El caso de `porcentaje` no es decoración. Sin él, «en promedio no baja» se
 * cumpliría igual si alguien **borrara la comprobación entera** — y entonces el
 * avance dejaría de detectar al docente que de verdad tiene los porcentajes mal en
 * un colegio que no ha encendido nada. Uno de los dos dice que la regla se apaga; el
 * otro, que sigue encendida donde toca.
 */
class AvanceDelDocenteEnPromedioTest extends CasoDeContrato
{
    /**
     * **En `porcentaje`, unas subunidades que no suman 100 siguen bajando el avance.**
     */
    #[Test]
    public function en_porcentaje_una_unidad_descuadrada_baja_el_avance(): void
    {
        $ctx = $this->docenteConUnaUnidadDescuadrada(RepartoDeLaNota::PORCENTAJE);

        $this->assertLessThan(100, $ctx['avance'],
            'Con las subunidades sin sumar 100 y el año en `porcentaje`, el avance tiene que '
            .'bajar. Si sale 100, la comprobación ya no comprueba nada y el caso de promedio '
            .'de abajo pasaría por el motivo equivocado.');
    }

    /**
     * **Y en `promedio` el mismo montaje no le quita nada.**
     *
     * Mismo docente, mismas subunidades sin sumar 100, sólo cambia el enum del año.
     */
    #[Test]
    public function en_promedio_la_misma_unidad_no_baja_el_avance(): void
    {
        $ctx = $this->docenteConUnaUnidadDescuadrada(RepartoDeLaNota::PROMEDIO);

        $this->assertSame(100.0, round($ctx['avance'], 4),
            'El año está en `promedio`, donde nadie mantiene `subunidades.porcentaje` —es lo que '
            ."la Entrega 5 le quita al docente— y aun así el avance baja.\n"
            .'Un colegio que encienda el promedio vería a todos sus docentes con media barra en '
            .'cero por no haber hecho algo que el modo les dice que no hagan.');
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /**
     * Un docente con **una sola asignatura**, sus unidades sumando 100 y las
     * subunidades de una de ellas **sin** sumar 100.
     *
     * La asignatura única no es comodidad: el avance divide por
     * `cant_asignaturas`, así que con dos el número deja de ser 0 ó 100 y el aserto
     * tendría que afirmar una fracción — que se lee peor y se rompe con el seed.
     *
     * Se monta y no se busca: hace falta que las unidades **sí** cuadren y las
     * subunidades **no**, que es una combinación que el seed no tiene por qué tener.
     * Y eso es justamente lo que separa las dos mitades de la cuenta.
     *
     * @return array<string, mixed>
     */
    private function docenteConUnaUnidadDescuadrada(string $modo): array
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.profesor_id, a.grupo_id,
                u.username, g.year_id, per.id AS periodo_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN periodos per ON per.year_id = g.year_id AND per.actual = 1
                AND per.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene una asignatura con profesor activo del año actual.');

        // **Una sola asignatura para este docente.** Las demás se mandan a la papelera
        // porque el avance es una media sobre `cant_asignaturas`: con las del seed
        // dentro, el número depende de datos que este test no controla.
        DB::table('asignaturas')
            ->where('profesor_id', $fila->profesor_id)
            ->where('id', '<>', $fila->asignatura_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        DB::table('unidades')
            ->where('asignatura_id', $fila->asignatura_id)
            ->where('periodo_id', $fila->periodo_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        // La unidad SÍ cuadra —100— para que la otra mitad del avance esté completa y
        // lo único que pueda bajar el número sea la de subunidades.
        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $fila->asignatura_id,
            'periodo_id' => $fila->periodo_id,
            'definicion' => 'UNIDAD DEL AVANCE',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Y las subunidades NO: 40 + 30 = 70. Es lo que deja un colegio en promedio en
        // cuanto el docente añade una casilla sin teclear porcentaje.
        foreach ([40, 30] as $i => $peso) {
            DB::table('subunidades')->insert([
                'unidad_id' => $unidadId,
                'definicion' => 'SUB '.($i + 1),
                'porcentaje' => $peso,
                'orden' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('years')->where('id', $fila->year_id)->update(['reparto_subunidades' => $modo]);

        // **Se pide con un SUPERUSUARIO y no con el propio docente**, y no es un
        // detalle del montaje: `getToMe` sólo llena `profes_actuales` en la rama de
        // `tipo == 'Usuario' && is_superuser` (`ChangeAskedController:39`). La otra
        // rama lo deja vacío salvo que llegue `anchoWindow > 500`. O sea que **esta
        // cuenta es la que el colegio ve sobre sus docentes**, no la que el docente ve
        // de sí mismo — que es justo lo que la vuelve un juicio y no un rótulo.
        $admin = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $admin->is_superuser,
            'El sujeto tiene que ser superusuario o `profes_actuales` sale vacío y el caso no mide nada.');

        $suYear = DB::selectOne('SELECT p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?', [$admin->id]);

        $this->assertSame((int) $fila->year_id, (int) ($suYear->year_id ?? 0),
            'El superusuario del seed no está en el año del montaje: la consulta filtra por '
            .'`g.year_id = $user->year_id` y el docente no saldría en la lista.');

        $r = $this->withToken($this->tokenDe($admin->username))->getJson('/api/ChangesAsked/to-me');

        $r->assertStatus(200);

        $avance = null;

        foreach ($r->json('profes_actuales') ?? [] as $profe) {
            if ((int) ($profe['profesor_id'] ?? 0) === (int) $fila->profesor_id) {
                $avance = $profe['porcentaje'] ?? null;
            }
        }

        $this->assertNotNull($avance,
            'El docente del montaje no salió en `profes_actuales` con su porcentaje: el caso no '
            .'estaría midiendo el avance de nadie.');

        return ['avance' => (float) $avance, 'profesor' => (int) $fila->profesor_id];
    }
}
