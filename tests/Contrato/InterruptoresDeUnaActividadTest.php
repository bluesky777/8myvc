<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **Cuál de los interruptores de una actividad decide de verdad lo que ve un
 * alumno**, medido abriendo el examen con un token de alumno y no leyendo el
 * `WHERE`.
 *
 * La pregunta viene de la tabla del [§5 de 09](../../docs/migracion/09-pendientes.md),
 * donde `para_alumnos` lleva desde el 21 ago 2026 con la anotación «sin un uso claro
 * separado de `compartida`». Esto la contesta.
 *
 * El profesor tiene cuatro maneras de decir «quién ve esto», y sólo dos deciden:
 *
 * | Interruptor | Ruta que lo mueve | Qué decide para un alumno |
 * |---|---|---|
 * | `in_action` | `actividades/guardar` | **cierra**: 403 «todavía no está abierta» |
 * | fila en `ws_actividades_compartidas` | `insert-`/`quitando-grupo-compartido` | **decide** el acceso de otro grupo |
 * | `compartida` | `actividades/set-compartida` | nada |
 * | `para_alumnos` | `actividades/para-alumnos-toggle` | nada |
 *
 * Los dos últimos sólo se leen en listados **del lado del profesor**
 * (`actividades/compartidas` y la pantalla de corregir). `exigirQueLaActividadLeCorresponda`
 * —la comprobación que cerró el lado del alumno— no los mira.
 *
 * > **El interruptor esconde en una pantalla y no cierra en la otra.** Es la misma
 * > forma que `vt_votaciones.in_action`, que manda al usuario a otra pantalla y no
 * > cierra la urna (11-votaciones.md).
 *
 * **Se fija y no se juzga.** Hacer que `para_alumnos` cierre es una línea, y es
 * justo lo que no se puede decidir desde aquí: hoy un alumno abre exámenes que ese
 * interruptor dice que no son para él, así que encenderlo **esconde de golpe
 * actividades que hoy se ven** en los dieciséis colegios.
 */
class InterruptoresDeUnaActividadTest extends CasoDeContrato
{
    /** El alumno, su grupo y una asignatura suya — todo del año actual. */
    private function alumnoConAsignatura(): object
    {
        $fila = DB::selectOne('SELECT a.id AS alumno_id, u.username, m.grupo_id, asi.id AS asignatura_id
            FROM alumnos a
            INNER JOIN users u ON u.id = a.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN asignaturas asi ON asi.grupo_id = g.id AND asi.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita un alumno matriculado con asignatura en el año actual.');

        return $fila;
    }

    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** La crea por la API —`POST actividades/crear`— y la deja abierta, que es lo que sí cierra. */
    private function actividadAbiertaEn(string $token, int $asignaturaId): int
    {
        $r = $this->withToken($token)->postJson('/api/actividades/crear', [
            'asignatura_id' => $asignaturaId,
        ]);
        $r->assertStatus(201);

        $id = (int) $r->json('id');

        // `in_action` lo mueve `actividades/guardar`, que es otra pantalla y otro
        // caso; aquí interesa el estado, no el camino.
        DB::update('UPDATE ws_actividades SET in_action = 1, inicia_at = NULL, para_alumnos = 1, compartida = 1
            WHERE id = ?', [$id]);

        return $id;
    }

    /**
     * `para_alumnos` y `compartida` **no le quitan el examen a nadie**: el alumno lo
     * sigue abriendo con los dos apagados.
     */
    public function test_apagar_para_alumnos_y_compartida_no_le_cierra_nada_al_alumno(): void
    {
        $alumno = $this->alumnoConAsignatura();
        $personal = $this->tokenDeSuperusuario();
        $actividadId = $this->actividadAbiertaEn($personal, (int) $alumno->asignatura_id);

        $tokenAlumno = $this->tokenDe($alumno->username);

        $this->withToken($tokenAlumno)->putJson('/api/mis-actividades/mi-actividad', [
            'actividad_id' => $actividadId,
        ])->assertStatus(200);

        $this->olvidarControladores();

        $this->withToken($personal)->putJson('/api/actividades/para-alumnos-toggle', [
            'actividad_id' => $actividadId, 'para_alumnos' => 0,
        ])->assertStatus(200);

        $this->withToken($personal)->putJson('/api/actividades/set-compartida', [
            'actividad_id' => $actividadId, 'compartida' => 0,
        ])->assertStatus(200);

        $this->assertSame(0, (int) DB::selectOne('SELECT para_alumnos FROM ws_actividades WHERE id = ?',
            [$actividadId])->para_alumnos, 'El toggle no llegó a escribir.');

        $this->olvidarControladores();

        $this->withToken($tokenAlumno)->putJson('/api/mis-actividades/mi-actividad', [
            'actividad_id' => $actividadId,
        ])->assertStatus(200);
    }

    /**
     * Y el que sí decide: **quitar el grupo compartido le cierra la puerta**.
     *
     * La actividad es de una asignatura de OTRO grupo, montado aquí —`!=` no
     * devuelve un grupo ajeno en este seed, que es la trampa que ya costó cuatro
     * veces lo mismo—, así que el único camino por el que le llega al alumno es la
     * fila de `ws_actividades_compartidas`.
     */
    public function test_quitar_el_grupo_compartido_si_le_cierra_la_puerta(): void
    {
        $alumno = $this->alumnoConAsignatura();
        $personal = $this->tokenDeSuperusuario();

        $ajeno = $this->grupoAjenoDelMismoAnio((int) DB::selectOne(
            'SELECT year_id FROM grupos WHERE id = ?', [$alumno->grupo_id])->year_id);

        $actividadId = $this->actividadAbiertaEn($personal, (int) $ajeno->asignatura_id);

        $tokenAlumno = $this->tokenDe($alumno->username);

        // Sin compartir todavía: no es suya.
        $this->withToken($tokenAlumno)->putJson('/api/mis-actividades/mi-actividad', [
            'actividad_id' => $actividadId,
        ])->assertStatus(403);

        $this->olvidarControladores();

        // 201, que es lo que ya contestaba: crea la fila de `ws_actividades_compartidas`.
        $this->withToken($personal)->putJson('/api/actividades/insert-grupo-compartido', [
            'actividad_id' => $actividadId, 'grupo_id' => $alumno->grupo_id,
        ])->assertStatus(201);

        $this->olvidarControladores();

        $this->withToken($tokenAlumno)->putJson('/api/mis-actividades/mi-actividad', [
            'actividad_id' => $actividadId,
        ])->assertStatus(200);

        $this->olvidarControladores();

        $this->withToken($personal)->putJson('/api/actividades/quitando-grupo-compartido', [
            'actividad_id' => $actividadId, 'grupo_id' => $alumno->grupo_id,
        ])->assertStatus(200);

        $this->olvidarControladores();

        $this->withToken($tokenAlumno)->putJson('/api/mis-actividades/mi-actividad', [
            'actividad_id' => $actividadId,
        ])->assertStatus(403);
    }
}
