<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Dos escrituras de notas que no dejaban línea en `auditoria`.
 *
 * - `PUT detalles/eliminar-notas-periodo` borra **físicamente** todas las notas
 *   de un alumno en un periodo: sin la línea no queda ni qué valor tenían.
 * - `PUT matriculas/traer-notas-del-grupo-anterior` crea o **pisa** definitivas
 *   en el grupo nuevo: sin la línea, la nota que había debajo se pierde.
 *
 * Como sus hermanos, **se mira la fila que queda y no el 200**: `guardar()` se
 * traga sus errores, así que una entidad mal escrita deja la respuesta intacta
 * y el rastro vacío.
 */
class AuditoriaDeLosBorradosYTraidasDeNotasTest extends CasoDeContrato
{
    /** Borrar las notas del periodo deja una línea `borrar nota` por cada una, con su valor. */
    public function test_borrar_las_notas_del_periodo_deja_una_linea_por_nota(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $periodo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id]);
        DB::table('periodos')->where('id', $periodo->id)->update(['profes_pueden_editar_notas' => 1]);

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$periodo->year_id]);
        $this->assertNotNull($asignatura, 'El seed necesita una asignatura con alumnos en el año del profesor.');

        $alumno = (int) DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$asignatura->grupo_id])->alumno_id;

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignatura->id, 'periodo_id' => $periodo->id,
            'definicion' => 'Unidad de pruebas', 'porcentaje' => 100, 'orden' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Dos notas con valores distintos, para que cada línea tenga que llevar el suyo.
        $notas = [];
        foreach ([2, 4] as $i => $valor) {
            $subunidadId = DB::table('subunidades')->insertGetId([
                'unidad_id' => $unidadId, 'definicion' => 'Subunidad de pruebas '.$i,
                'porcentaje' => 50, 'orden' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('notas')->insertGetId([
                'subunidad_id' => $subunidadId, 'alumno_id' => $alumno, 'nota' => $valor,
                'created_by' => $usuario->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // El valor que quedó en la base, que es el que la línea tiene que llevar: la
            // columna puede redondear lo que se le pasa.
            $notas[$id] = DB::table('notas')->where('id', $id)->value('nota');
        }

        // Las que ya tuviera el alumno en ese grupo y periodo también se borran.
        $todas = array_map(fn ($f) => (int) $f->id, DB::select('SELECT n.id FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.periodo_id = ?
            INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
            WHERE n.alumno_id = ?', [$periodo->id, $asignatura->grupo_id, $alumno]));

        $r = $this->withToken($token)->putJson('/api/detalles/eliminar-notas-periodo', [
            'periodo_id' => $periodo->id, 'alumno_id' => $alumno, 'grupo_id' => $asignatura->grupo_id,
        ]);
        $r->assertStatus(200);
        $this->assertSame(count($todas), (int) $r->getContent());

        $lineas = DB::table('auditoria')->where('entidad', 'nota')->where('accion', Auditoria::BORRAR)
            ->whereIn('entidad_id', $todas)->get()->keyBy('entidad_id');

        $this->assertCount(count($todas), $lineas, 'No quedó una línea de borrado por cada nota borrada.');

        foreach ($notas as $id => $valor) {
            $linea = $lineas[$id];
            $this->assertEquals($alumno, $linea->alumno_id, 'La línea no dice de qué alumno era la nota.');
            $this->assertEquals($asignatura->id, $linea->asignatura_id);
            $this->assertEquals($periodo->id, $linea->periodo_id);
            $this->assertEquals($valor, json_decode((string) $linea->valor_anterior),
                'La línea no guarda el valor que tenía la nota: después del borrado físico no hay otro sitio.');
            $this->assertNull($linea->valor_nuevo);
            $this->assertNotNull($linea->actor_user_id, 'La línea no dice quién borró.');
        }
    }

    /**
     * Traer las definitivas deja `crear nota_final` la primera vez y `editar` con
     * la nota de debajo cuando pisa.
     */
    public function test_traer_las_definitivas_anota_las_creadas_y_las_pisadas(): void
    {
        $super = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');
        $token = $this->tokenDe($super->username);

        $fila = DB::selectOne('SELECT nf.alumno_id, a.grupo_id
            FROM notas_finales nf
            INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.deleted_at IS NULL
            GROUP BY nf.alumno_id, a.grupo_id ORDER BY COUNT(*) DESC, nf.alumno_id, a.grupo_id LIMIT 1');
        $this->assertNotNull($fila, 'El seed no tiene ninguna definitiva con asignatura.');

        $origen = DB::table('grupos')->where('id', $fila->grupo_id)->first();
        $destino = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo de prueba (auditoría)', 'year_id' => $origen->year_id,
            'grado_id' => $origen->grado_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('asignaturas')->where('grupo_id', $origen->id)->whereNull('deleted_at')->get() as $a) {
            DB::table('asignaturas')->insert([
                'materia_id' => $a->materia_id, 'grupo_id' => $destino,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $alumno = (int) $fila->alumno_id;
        $cuerpo = ['alumno_id' => $alumno, 'grupo_origen' => $origen->id, 'grupo_destino' => $destino];

        $primera = $this->withToken($token)->putJson('/api/matriculas/traer-notas-del-grupo-anterior', $cuerpo)
            ->assertStatus(200)->json();
        $this->assertGreaterThan(0, $primera['creadas'], 'No se trajo nada: el test no mediría nada.');

        $enDestino = DB::table('notas_finales')
            ->join('asignaturas', 'asignaturas.id', '=', 'notas_finales.asignatura_id')
            ->where('notas_finales.alumno_id', $alumno)->where('asignaturas.grupo_id', $destino)
            ->get(['notas_finales.id', 'notas_finales.nota', 'notas_finales.asignatura_id', 'notas_finales.periodo_id']);

        $creadas = DB::table('auditoria')->where('entidad', 'nota_final')->where('accion', Auditoria::CREAR)
            ->whereIn('entidad_id', $enDestino->pluck('id'))->get()->keyBy('entidad_id');
        $this->assertCount($primera['creadas'], $creadas, 'No quedó una línea por cada definitiva traída.');

        foreach ($enDestino as $nf) {
            $linea = $creadas[$nf->id];
            $this->assertEquals($alumno, $linea->alumno_id);
            $this->assertEquals($nf->asignatura_id, $linea->asignatura_id);
            $this->assertEquals($nf->periodo_id, $linea->periodo_id);
            $this->assertNull($linea->valor_anterior, 'Una definitiva nueva no tenía valor anterior.');
            $this->assertEquals($nf->nota, json_decode((string) $linea->valor_nuevo));
        }

        // Se pone otra nota debajo, para que pisar tenga algo distinto que anotar.
        DB::table('notas_finales')->whereIn('id', $enDestino->pluck('id'))->update(['nota' => 1]);

        $segunda = $this->withToken($token)->putJson('/api/matriculas/traer-notas-del-grupo-anterior', $cuerpo)
            ->assertStatus(200)->json();
        $this->assertSame($primera['creadas'], $segunda['pisadas']);

        $pisadas = DB::table('auditoria')->where('entidad', 'nota_final')->where('accion', Auditoria::EDITAR)
            ->whereIn('entidad_id', $enDestino->pluck('id'))->get()->keyBy('entidad_id');
        $this->assertCount($segunda['pisadas'], $pisadas, 'No quedó una línea por cada definitiva pisada.');

        foreach ($enDestino as $nf) {
            $linea = $pisadas[$nf->id];
            $this->assertEquals($alumno, $linea->alumno_id);
            $this->assertEquals(1, json_decode((string) $linea->valor_anterior),
                'La línea no guarda la nota que había debajo antes de pisarla.');
            $this->assertEquals($nf->nota, json_decode((string) $linea->valor_nuevo));
        }
    }
}
