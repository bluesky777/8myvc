<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **Un campo que no se manda no es un campo que no cambia — y en la rejilla lo que
 * se pisa es el porcentaje.** §92.2.
 *
 * Es la [§68](../../docs/migracion/05-codigo-muerto-y-roto.md) aplicada a los dos
 * `putUpdate` del lote C. `SubunidadesController::putUpdate` asigna tres columnas
 * con `Request::input()` **sin defecto**:
 *
 * ```php
 * $subunidad->definicion   = Request::input('definicion');
 * $subunidad->porcentaje   = Request::input('porcentaje');
 * $subunidad->nota_default = $nota_def;      // ← se convierte en 0
 * ```
 *
 * Un cuerpo que no traiga `porcentaje` lo deja a **null**, y `porcentaje` es lo
 * que pesa el componente dentro de la unidad: la definitiva del alumno se calcula
 * con `(u.porcentaje/100)*((s.porcentaje/100)*n.nota)`. O sea que **un cuerpo
 * parcial no borra un dato descriptivo: borra un peso, y la nota que sale al
 * boletín cambia.**
 *
 * Y ese `putUpdate` recalcula las definitivas de la asignatura tres líneas más
 * abajo, así que el efecto no espera a nada.
 *
 * ## Y su vecino, que parecía igual y no lo es
 *
 * `NotaComportamientoController::putUpdate` asigna también tres columnas del
 * cuerpo, pero cada una **dentro de un `if (Request::has(...))`**. Un barrido que
 * cuente `$obj->col = Request::input('col')` los da a los dos por iguales; la
 * diferencia está en la línea de antes. El segundo caso de esta clase existe para
 * que esa diferencia esté **medida** y no leída — y para que el día que alguien
 * unifique los dos `putUpdate` se vea cuál de los dos comportamientos se está
 * llevando por delante.
 */
class PorcentajeQueSePisaTest extends CasoDeContrato
{
    /** Un profesor con su año ABIERTO: aquí no se mide el candado, se mide el cuerpo. */
    private function profesorConElAnioAbierto(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);

        return (object) ['token' => $token, 'periodo' => $suyo];
    }

    /**
     * **Renombrar una subunidad sin mandar su porcentaje lo conserva.**
     *
     * Antes lo dejaba a `null` —medido: 50 → null— y con él se iba el peso del
     * componente en la definitiva, que este mismo método recalcula veinte líneas
     * más abajo.
     */
    public function test_actualizar_una_subunidad_sin_mandar_el_porcentaje_lo_conserva(): void
    {
        $e = $this->profesorConElAnioAbierto();

        $sub = DB::selectOne('SELECT s.id, s.definicion, s.porcentaje FROM subunidades s
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            WHERE u.periodo_id = ? AND s.deleted_at IS NULL AND s.porcentaje IS NOT NULL
            ORDER BY s.id LIMIT 1', [$e->periodo->id]);

        if ($sub === null) {
            $this->markTestSkipped('El seed no tiene subunidades con porcentaje en el periodo del profesor.');
        }

        $this->withToken($e->token)->putJson('/api/subunidades/update/'.$sub->id, [
            'definicion' => 'sólo le cambio el nombre',
        ])->assertStatus(200);

        $ahora = DB::selectOne('SELECT definicion, porcentaje FROM subunidades WHERE id = ?', [$sub->id]);

        $this->assertSame('sólo le cambio el nombre', $ahora->definicion,
            'Ni siquiera guardó lo que sí se mandó.');

        $this->assertEquals($sub->porcentaje, $ahora->porcentaje,
            'El porcentaje se fue con un cuerpo que no lo nombraba. Es el peso del componente en la definitiva.');
    }

    /**
     * El otro lado, sin el cual el arreglo sería «no dejar cambiar el porcentaje»:
     * **mandarlo lo sigue decidiendo, incluido ponerlo a 0**.
     *
     * El 0 no es puntillería: es el valor que un defecto mal escrito confunde con
     * «no vino». Si el arreglo fuera `?: $subunidad->porcentaje`, este caso cae.
     */
    public function test_mandar_el_porcentaje_lo_sigue_decidiendo_incluido_el_cero(): void
    {
        $e = $this->profesorConElAnioAbierto();

        $sub = DB::selectOne('SELECT s.id, s.porcentaje FROM subunidades s
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            WHERE u.periodo_id = ? AND s.deleted_at IS NULL ORDER BY s.id LIMIT 1', [$e->periodo->id]);

        if ($sub === null) {
            $this->markTestSkipped('El seed no tiene subunidades en el periodo del profesor.');
        }

        $this->withToken($e->token)->putJson('/api/subunidades/update/'.$sub->id, [
            'definicion' => 'con porcentaje a cero',
            'porcentaje' => 0,
        ])->assertStatus(200);

        $this->assertEquals(0, DB::table('subunidades')->where('id', $sub->id)->value('porcentaje'),
            'Un 0 mandado a propósito se trató como «no vino».');
    }

    /**
     * **El vecino que parecía igual: `nota_comportamiento/update` no pisa nada**,
     * porque cada asignación va dentro de su `Request::has()`.
     *
     * Medido y no leído a propósito: es la diferencia que un barrido por
     * `$obj->col = Request::input('col')` no ve, y la que decide si hay dos
     * arreglos o uno.
     */
    public function test_actualizar_una_nota_de_comportamiento_no_pisa_lo_que_no_viene(): void
    {
        $e = $this->profesorConElAnioAbierto();

        $nc = DB::selectOne('SELECT id, nota, familiar_nota, familiar_ausencias FROM nota_comportamiento
            WHERE periodo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$e->periodo->id]);

        if ($nc === null) {
            $this->markTestSkipped('El seed no tiene notas de comportamiento en el periodo del profesor.');
        }

        DB::table('nota_comportamiento')->where('id', $nc->id)
            ->update(['familiar_nota' => 55, 'familiar_ausencias' => 7]);

        $this->withToken($e->token)->putJson('/api/nota_comportamiento/update/'.$nc->id, [
            'nota' => 90,
        ])->assertStatus(200);

        $ahora = DB::selectOne('SELECT nota, familiar_nota, familiar_ausencias FROM nota_comportamiento
            WHERE id = ?', [$nc->id]);

        $this->assertEquals([90, 55, 7], [$ahora->nota, $ahora->familiar_nota, $ahora->familiar_ausencias],
            'Este sí pisaba: entonces el `Request::has()` de su putUpdate dejó de valer, o alguien mete el cuerpo por otro sitio.');
    }
}
