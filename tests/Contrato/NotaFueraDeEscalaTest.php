<?php

namespace Tests\Contrato;

use App\Support\EscalaDeNotas;
use Illuminate\Support\Facades\DB;

/**
 * Una nota que no cabe en la escala del colegio ya no se guarda.
 *
 * Hasta el 24 ago 2026 **no lo comprobaba nadie en el servidor**: los diez sitios
 * que miran `escalas_de_valoracion.porc_final` son para pintar la banda —
 * SUPERIOR, ALTO, BÁSICO— y ninguno rechaza. El único guardián era el navegador.
 *
 * Y se notaba: en esta base, con escala **de 0 a 50**, hay **92 notas fuera de
 * rango** —65 con `100`, 24 con `95`, dos con `78` y una con `89`—, o sea notas
 * tecleadas como si la escala fuera de 100. Todas en los años 1 a 5 y **ninguna
 * en los cuatro más recientes**, que es lo que hizo razonable cerrar la puerta
 * ahora: no rompe nada vivo.
 *
 * Lo destapó el front al encontrar un `[nzMax]="100"` a mano en una de sus tres
 * pantallas hermanas —las otras dos sí guardan—. Arreglar sólo esa pantalla
 * dejaba el sistema cubierto **por costumbre y no por diseño**. Ver 18 §4.5.1.
 *
 * **Los tres primeros tests son el comportamiento; el cuarto es la decisión.**
 * Un año sin escala configurada **deja pasar**, y eso hay que fijarlo con un test
 * porque parece un olvido y no lo es: rechazar cuando falta la configuración
 * convierte un hueco de configuración en dejar a un colegio entero sin poder
 * calificar. Ver la cabecera de `App\Support\EscalaDeNotas`.
 */
class NotaFueraDeEscalaTest extends CasoDeContrato
{
    protected function setUp(): void
    {
        parent::setUp();
        EscalaDeNotas::olvidar();
    }

    public function test_una_nota_por_encima_del_maximo_no_se_guarda(): void
    {
        [$token, $notaId, $maximo] = $this->unaNotaEditable();

        $this->withToken($token)
            ->putJson('/api/notas/update/'.$notaId, ['nota' => $maximo + 1])
            ->assertStatus(422);

        // Y lo que de verdad importa: **el estado no cambió**. Un 422 con la
        // escritura hecha sería peor que no validar, porque el cliente se queda
        // creyendo que no se guardó.
        $this->assertSame(
            20,
            (int) DB::table('notas')->where('id', $notaId)->value('nota'),
            'Contestó 422 pero escribió igual.'
        );
    }

    public function test_una_nota_negativa_tampoco(): void
    {
        [$token, $notaId] = $this->unaNotaEditable();

        $this->withToken($token)
            ->putJson('/api/notas/update/'.$notaId, ['nota' => -1])
            ->assertStatus(422);

        $this->assertSame(20, (int) DB::table('notas')->where('id', $notaId)->value('nota'));
    }

    /**
     * La otra mitad, y no es de adorno: una validación que rechaza de más se
     * nota tarde y se nota en producción.
     */
    public function test_una_nota_dentro_de_la_escala_se_sigue_guardando(): void
    {
        [$token, $notaId, $maximo] = $this->unaNotaEditable();

        $this->withToken($token)
            ->putJson('/api/notas/update/'.$notaId, ['nota' => $maximo])
            ->assertStatus(200);

        $this->assertSame($maximo, (int) DB::table('notas')->where('id', $notaId)->value('nota'),
            'El máximo exacto tiene que caber: la escala es cerrada por arriba.');
    }

    /**
     * En el lote la fuera de escala **no tira el lote**: vuelve en `fallidas` y
     * las demás se guardan. Es el contrato que la pantalla ya sabe pintar, y la
     * diferencia entre corregir una celda y perder una columna de cuarenta.
     */
    public function test_en_el_lote_la_de_fuera_falla_sola(): void
    {
        [$token, $notaId, $maximo, $otraId] = $this->unaNotaEditable();

        $r = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => [
                ['id' => $notaId, 'nota' => $maximo + 50],
                ['id' => $otraId, 'nota' => 30],
            ],
        ])->assertStatus(200);

        $this->assertSame(1, $r->json('guardadas'), 'La que cabía tenía que guardarse.');
        $this->assertCount(1, $r->json('fallidas'));
        $this->assertSame($notaId, $r->json('fallidas.0.id'));
        $this->assertStringContainsString('escala', $r->json('fallidas.0.motivo'));

        $this->assertSame(20, (int) DB::table('notas')->where('id', $notaId)->value('nota'));
        $this->assertSame(30, (int) DB::table('notas')->where('id', $otraId)->value('nota'));
    }

    /**
     * **La decisión, fijada:** un año sin escala configurada NO bloquea.
     *
     * Parece un olvido y es deliberado. Los colegios crean sus propias escalas;
     * un año recién abierto puede no tenerlas todavía, y rechazar entonces deja
     * a sus profesores sin calificar. De los dos fallos posibles, dejar pasar es
     * el estado de hoy y bloquear sería nuevo y peor.
     *
     * Y **no se inventa un máximo por defecto**, que es la tercera vía y la peor:
     * el front tiene un `?? 100` que en un colegio de 0 a 50 **afloja el límite
     * al doble**. Un valor inventado es peor que ninguno porque parece una
     * comprobación.
     */
    public function test_sin_escala_configurada_se_deja_pasar(): void
    {
        [$token, $notaId, $maximo] = $this->unaNotaEditable();

        $yearId = (int) DB::selectOne(
            'SELECT p.year_id FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id
               INNER JOIN unidades u ON u.id = s.unidad_id
               INNER JOIN periodos p ON p.id = u.periodo_id
              WHERE n.id = ?', [$notaId]
        )->year_id;

        DB::table('escalas_de_valoracion')->where('year_id', $yearId)->delete();
        EscalaDeNotas::olvidar();

        $this->withToken($token)
            ->putJson('/api/notas/update/'.$notaId, ['nota' => $maximo + 1])
            ->assertStatus(200);
    }

    /**
     * Una nota de 20 editable por un profesor, con el máximo de su escala.
     *
     * @return array{0:string,1:int,2:int,3:int} token, nota, máximo, otra nota
     */
    private function unaNotaEditable(): array
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo.');

        // El interruptor del periodo (§27): sin esto el test fallaría por el
        // guard y no por la escala, que es el fallo que no enseña nada.
        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
            WHERE a.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM matriculas m WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1', [$suyo->id]);

        $this->assertNotNull($asignatura, 'El seed no tiene una asignatura con matrículas.');

        $alumnos = DB::select('SELECT DISTINCT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 2',
            [$asignatura->grupo_id]);

        $this->assertCount(2, $alumnos, 'El montaje necesita dos alumnos.');

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $suyo->id,
            'definicion' => 'UNIDAD ESCALA',
            'porcentaje' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subId = DB::table('subunidades')->insertGetId([
            'unidad_id' => $unidadId,
            'definicion' => 'SUB ESCALA',
            'porcentaje' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = [];

        foreach ($alumnos as $alumno) {
            $ids[] = (int) DB::table('notas')->insertGetId([
                'subunidad_id' => $subId,
                'alumno_id' => $alumno->alumno_id,
                'nota' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $maximo = EscalaDeNotas::maximo((int) $suyo->year_id);

        $this->assertNotNull($maximo, 'El año del seed no tiene escala: este test necesita una.');

        return [$token, $ids[0], $maximo, $ids[1]];
    }
}
