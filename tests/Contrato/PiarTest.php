<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Quién entra al PIAR, que resultó ser cualquiera.
 *
 * El PIAR —el plan individual de ajustes razonables— lo lleva `myvc_front_2`, la
 * única aplicación Angular del sistema y la única que no habla con el resto de la
 * API. Por eso sus catorce rutas se habían mirado poco: no las golpea el barrido
 * de un token normal ni salen en las pantallas que se revisaron.
 *
 * Se llegó aquí por la puerta de al lado. Joseth pidió ver qué endpoints toca el
 * PIAR antes de decidir el alcance del rol `Psicólogo`, y la lista contestó otra
 * pregunta: **el PIAR no pregunta por el rol en ningún sitio** —autoriza por
 * `tipo`— y una de sus rutas no preguntaba nada en absoluto.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §35.
 */
class PiarTest extends CasoDeContrato
{
    /** Una fila de PIAR sobre la que escribir: el seed no trae ninguna. */
    private function filaDePiar(): object
    {
        $alumno = DB::selectOne('SELECT a.id, g.year_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene ningún alumno matriculado.');

        $id = DB::table('piars_alumnos')->insertGetId([
            'alumno_id' => $alumno->id,
            'year_id' => $alumno->year_id,
            'valoracion_pedagogica' => 'lo que escribió el colegio',
        ]);

        return (object) ['id' => $id, 'alumno_id' => $alumno->id];
    }

    /**
     * `piars-alumnos/field` no llevaba guard ninguno.
     *
     * Escribe tres columnas de texto largo —valoración pedagógica, ajustes
     * generales y reporte— eligiendo la fila **por su `id`**, no por el alumno.
     * O sea que con un token cualquiera y un número se reescribía el PIAR de
     * cualquier alumno del colegio. El test comprueba las dos mitades: que el
     * 403 llega y que la columna no se movió.
     */
    public function test_una_familia_no_reescribe_el_piar_de_nadie(): void
    {
        $piar = $this->filaDePiar();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->putJson('/api/piars-alumnos/field', [
                    'id' => $piar->id,
                    'field' => 'valoracion_pedagogica',
                    'text' => "lo escribió un {$tipo}",
                ])->assertStatus(403);
        }

        $this->assertSame('lo que escribió el colegio',
            DB::table('piars_alumnos')->where('id', $piar->id)->value('valoracion_pedagogica'),
            'La familia llegó a escribir en el PIAR.');
    }

    /**
     * Los documentos del PIAR los pone el colegio, no la familia.
     *
     * Las dos rutas llevaban `persona.propia`, que a un alumno le deja pasar
     * sobre lo suyo — y el PIAR del alumno es «lo suyo» para ese guard. Decisión
     * de Joseth, 21 ago 2026: el PIAR entero es del personal.
     */
    public function test_una_familia_no_toca_los_documentos_del_piar(): void
    {
        $piar = $this->filaDePiar();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->postJson('/api/piars-alumnos/document', [
                'alumno_id' => $piar->alumno_id,
                'documentField' => 'documento1',
            ])->assertStatus(403);

            $this->withToken($token)
                ->deleteJson('/api/piars-alumnos/document/'.$piar->alumno_id, [
                    'file_name' => 'documento1',
                ])->assertStatus(403);
        }
    }

    /** Y el personal sigue escribiendo, que es para lo que existe la ruta. */
    public function test_el_personal_sigue_escribiendo_el_piar(): void
    {
        $piar = $this->filaDePiar();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/piars-alumnos/field', [
                'id' => $piar->id,
                'field' => 'valoracion_pedagogica',
                'text' => 'lo escribió el psicólogo',
            ])->assertStatus(200);

        $this->assertSame('lo escribió el psicólogo',
            DB::table('piars_alumnos')->where('id', $piar->id)->value('valoracion_pedagogica'));
    }

    /**
     * El PIAR solo ve a los alumnos ya marcados con `nee`, y marcarlos es de otro.
     *
     * `PiarsAlumnoUtils` filtra por `a.nee=1` en sus dos consultas, así que un
     * alumno que no esté marcado no aparece en ninguna pantalla del PIAR. La
     * marca se pone en `alumnos/guardar-valor`, que hoy exige superusuario: el
     * psicólogo trabaja el PIAR pero no puede meter a nadie en él. Este test fija
     * la mitad que no cambia —el filtro— para que el arreglo de la otra mitad se
     * apoye en algo.
     */
    public function test_el_piar_solo_lista_a_los_alumnos_con_nee(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        // El mismo filtro que el controlador, estado incluido: sin `estado` la
        // cuenta da uno de más, porque en el grupo hay un alumno con nee cuya
        // matrícula no está ni ASIS ni MATR y el PIAR no lo lista.
        $conNee = DB::selectOne('SELECT COUNT(DISTINCT a.id) c FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("ASIS","MATR")
            WHERE m.grupo_id = ? AND a.nee = 1 AND a.deleted_at IS NULL', [$grupo->id]);

        $r = $this->withToken($token)->getJson('/api/piars-grupos/contexto-de-grupo/'.$grupo->id);
        $r->assertStatus(200);

        $this->assertGreaterThan(0, (int) $conNee->c,
            'Sin ningún alumno con nee en el grupo este test pasaría sin comprobar nada.');

        $this->assertCount((int) $conNee->c, $r->json('data.alumnos_piar'),
            'El PIAR lista alumnos que nadie ha marcado con nee, o deja fuera a los marcados.');

        // Y la otra clave de la misma respuesta no filtra: `data.alumnos` son los
        // del grupo entero, con teléfono, dirección y `nee_descripcion` de cada
        // uno. Es personal del colegio quien lo recibe, así que no es un agujero
        // de los de la §34; se fija aquí porque la diferencia entre las dos claves
        // es justo lo que un refactor confundiría.
        $this->assertGreaterThan(count($r->json('data.alumnos_piar')), count($r->json('data.alumnos')));
    }
}
