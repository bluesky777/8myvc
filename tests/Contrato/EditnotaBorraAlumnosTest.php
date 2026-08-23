<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **`editnota` no borra notas: borra ALUMNOS**, y por una puerta con menos llave
 * que la de al lado.
 *
 * `EditnotaController` es la pantalla con la que se corrige el histórico académico
 * de un alumno ya promovido —notas de otro año lectivo—. Tres de sus rutas no
 * tocan ninguna nota:
 *
 * | Ruta | Qué hace de verdad |
 * |---|---|
 * | `DELETE api/editnota/destroy/{id}` | manda un **alumno** a la papelera |
 * | `PUT api/editnota/restore/{id}` | lo saca de la papelera |
 * | `DELETE api/editnota/forcedelete/{id}` | lo borra definitivamente |
 *
 * Es la forma de la [§65](../../docs/migracion/05-codigo-muerto-y-roto.md) —un
 * controlador que opera sobre otra cosa de la que dice su nombre— con un agravante:
 * **las mismas tres operaciones existen en `AlumnosController` con criterio**, y
 * aquí no lo tenían.
 *
 * ## La asimetría, que es lo que lo señaló
 *
 * | Operación | `AlumnosController` | `EditnotaController` (antes) |
 * |---|---|---|
 * | a la papelera | `puedeEditarAlumnos` | **nada** |
 * | restaurar | `puedeEditarAlumnos` | **nada** |
 * | borrado definitivo | `puedeBorrarAlumnos` | `puedeBorrarAlumnos` |
 *
 * El tercero se cerró en su día —tiene el comentario que lo cuenta—, **y los otros
 * dos se quedaron**. Cerrar una de tres es lo que pasa cuando se arregla el sitio
 * que se está mirando y no la operación.
 *
 * Y el criterio no es teórico: `puedeEditarAlumnos` es superusuario **o** profesor
 * con `profes_can_edit_alumnos`, que está **apagada en los dieciséis colegios**. O
 * sea que hoy un profesor no puede mandar un alumno a la papelera por
 * `alumnos/destroy` y sí podía por `editnota/destroy`.
 *
 * Ningún cliente llama a estas tres: `EditnotaApi.ts` sólo declara
 * `alum-asignatura`, con un comentario que dice «cubierto hasta donde hay call
 * site». Cerrarlas no apaga ninguna pantalla.
 */
class EditnotaBorraAlumnosTest extends CasoDeContrato
{
    /**
     * Un profesor con la bandera **apagada aquí mismo**.
     *
     * Se construye y no se busca: si el seed la trajera encendida, el profesor
     * pasaría el criterio de verdad y el caso quedaría verde sin medir el guard.
     * Van doce.
     */
    private function tokenDeProfesorSinBandera(): string
    {
        DB::update('UPDATE years SET profes_can_edit_alumnos = 0');

        return $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
    }

    private function alumnoVivo(): object
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($alumno, 'El seed necesita un alumno.');

        return $alumno;
    }

    private function estaEnLaPapelera(int $alumnoId): bool
    {
        return DB::selectOne('SELECT deleted_at FROM alumnos WHERE id = ?', [$alumnoId])->deleted_at !== null;
    }

    /**
     * Un profesor sin la bandera **no manda un alumno a la papelera por aquí**, que
     * es lo que ya decía su hermana de `AlumnosController`.
     *
     * Se comprueba el resultado y no sólo el código: un 403 que además borre sería
     * el mismo fallo con mejor pinta.
     */
    public function test_un_profesor_no_manda_un_alumno_a_la_papelera_por_editnota(): void
    {
        $token = $this->tokenDeProfesorSinBandera();
        $alumno = $this->alumnoVivo();

        $this->withToken($token)->deleteJson('/api/editnota/destroy/'.$alumno->id)
            ->assertStatus(403);

        $this->assertFalse($this->estaEnLaPapelera($alumno->id),
            'El alumno acabó en la papelera pese al rechazo.');
    }

    /** Y tampoco lo saca de ella. */
    public function test_un_profesor_no_restaura_un_alumno_por_editnota(): void
    {
        $token = $this->tokenDeProfesorSinBandera();
        $alumno = $this->alumnoVivo();

        DB::update('UPDATE alumnos SET deleted_at = NOW() WHERE id = ?', [$alumno->id]);

        $this->withToken($token)->putJson('/api/editnota/restore/'.$alumno->id)
            ->assertStatus(403);

        $this->assertTrue($this->estaEnLaPapelera($alumno->id),
            'El alumno salió de la papelera pese al rechazo.');
    }

    /**
     * El borrado definitivo **sigue cerrado**, que es el que ya lo estaba. Está aquí
     * para que se vea que las tres se cierran con el mismo criterio y no queda otra
     * vez una de tres.
     */
    public function test_un_profesor_no_borra_definitivamente_por_editnota(): void
    {
        $token = $this->tokenDeProfesorSinBandera();
        $alumno = $this->alumnoVivo();

        DB::update('UPDATE alumnos SET deleted_at = NOW() WHERE id = ?', [$alumno->id]);

        $this->withToken($token)->deleteJson('/api/editnota/forcedelete/'.$alumno->id)
            ->assertStatus(403);

        $this->assertNotNull(DB::selectOne('SELECT id FROM alumnos WHERE id = ?', [$alumno->id]),
            'El alumno se borró de la tabla pese al rechazo.');
    }

    /**
     * El otro lado, sin el cual esto sería «cerrar la puerta con la casa dentro»:
     * **quien sí tiene el criterio sigue pudiendo**, y por las dos puertas.
     *
     * Un superusuario manda el alumno a la papelera por `editnota/destroy` y lo saca
     * por `editnota/restore`, que es el viaje de ida y vuelta.
     */
    public function test_un_superusuario_sigue_pudiendo_por_las_dos_rutas(): void
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $token = $this->tokenDe($super->username);

        $alumno = $this->alumnoVivo();

        $this->withToken($token)->deleteJson('/api/editnota/destroy/'.$alumno->id)
            ->assertStatus(200);
        $this->assertTrue($this->estaEnLaPapelera($alumno->id), 'No llegó a la papelera.');

        $this->olvidarControladores();

        $this->withToken($token)->putJson('/api/editnota/restore/'.$alumno->id)
            ->assertStatus(200);
        $this->assertFalse($this->estaEnLaPapelera($alumno->id), 'No volvió de la papelera.');
    }

    /**
     * La única de las cuatro que un cliente llama de verdad —`EditnotaApi.ts` sólo
     * declara ésta— y la única que trata de notas.
     *
     * Se fija lo que hay, **sin juzgarlo**, con el porqué al lado como pide la §54:
     * `periodos_a_calcular` viaja en el cuerpo y `Periodo::hastaPeriodo` sólo conoce
     * tres valores —`de_usuario`, `de_colegio`, `todos`—. Con cualquier otro no
     * falla: devuelve un `stdClass` vacío, el `foreach` no da vueltas y **la pantalla
     * del histórico sale vacía en 200**. O sea que una errata en un cliente vacía la
     * pantalla sin que nadie se entere, que es la forma de la §45 otra vez.
     *
     * No se arregla aquí porque decidir qué debe contestar —422, o tratar lo
     * desconocido como `de_usuario`— cambia lo que ve una pantalla en dieciséis
     * colegios, y hoy ningún cliente manda un valor malo.
     */
    public function test_un_periodos_a_calcular_desconocido_devuelve_vacio_en_200(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalLlanoDe($grupo->year_id);

        $fila = DB::selectOne('SELECT n.alumno_id, u.asignatura_id
            FROM notas n
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 1');
        $this->assertNotNull($fila, 'El seed necesita una nota con su asignatura.');

        $bueno = $this->withToken($token)->putJson('/api/editnota/alum-asignatura', [
            'alumno_id' => $fila->alumno_id, 'asignatura_id' => $fila->asignatura_id,
            'periodos_a_calcular' => 'todos',
        ]);
        $bueno->assertStatus(200);
        $this->assertNotEmpty($bueno->json(), 'Con un valor bueno tiene que traer periodos.');

        $this->olvidarControladores();

        $malo = $this->withToken($token)->putJson('/api/editnota/alum-asignatura', [
            'alumno_id' => $fila->alumno_id, 'asignatura_id' => $fila->asignatura_id,
            'periodos_a_calcular' => 'de_usuarios',   // la errata que nadie ve
        ]);
        $malo->assertStatus(200);
        $this->assertEmpty($malo->json(),
            'Si esto deja de estar vacío es que alguien decidió qué hacer con lo desconocido: cámbiese el caso y anótese la decisión.');
    }
}
