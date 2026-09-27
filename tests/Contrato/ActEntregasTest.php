<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **las entregas de una tarea** (contrato §3.8 y §3.9).
 *
 * - Topes del servidor: 5 MB (422 con la frase que manda a pegar un enlace), una foto de hasta
 *   1600 px por el lado largo, y sólo los tipos de `SafeUpload`. **El 1280 del alumno lo pone el
 *   cliente** al reducir la foto (`reducirParaCaber`, §4.1); el servidor tiene un solo tope, 1600,
 *   para todos, así que aquí se mide ése.
 * - Una foto y un archivo por entrega: subir otro retira el anterior.
 * - El fichero lo ven quien lo subió, sus acudientes oficiales, el dueño y los directivos; otro
 *   alumno, 403.
 *
 * Los ficheros van a `storage/app/actividades/...`; `CasoDeActividades::tearDown` los borra.
 */
class ActEntregasTest extends CasoDeActividades
{
    private function tareaQuePide(array $entrega): int
    {
        $act = $this->crear($this->tarea(['entrega' => $entrega]));
        $this->publicar($act['id']);

        return $act['id'];
    }

    private function subir(int $id, object $alumno, string $clase, UploadedFile $file)
    {
        return $this->como($alumno)->post("/api/act/{$id}/archivo", ['clase' => $clase, 'file' => $file], ['Accept' => 'application/json']);
    }

    public function test_los_topes_de_tamano_y_de_lado(): void
    {
        $id = $this->tareaQuePide(['foto' => true, 'archivo' => true]);
        $yo = $this->alumno();

        $grande = $this->subir($id, $yo, 'archivo', UploadedFile::fake()->create('tesis.pdf', 6 * 1024, 'application/pdf'));
        $grande->assertStatus(422);
        $this->assertSame('El archivo pasa de 5 MB: pega un enlace', $grande->json('message'));

        $this->subir($id, $yo, 'foto', UploadedFile::fake()->image('ancha.jpg', 1601, 20))->assertStatus(422);

        $r = $this->subir($id, $yo, 'foto', UploadedFile::fake()->image('justa.jpg', 1600, 20));
        $r->assertStatus(200)->assertJson(['clase' => 'foto', 'ancho' => 1600, 'alto' => 20]);

        $this->subir($id, $yo, 'archivo', UploadedFile::fake()->create('taller.pdf', 4 * 1024, 'application/pdf'))->assertStatus(200);

        $this->assertSame(2, DB::table('ws_archivos')->where('actividad_id', $id)->count());
    }

    public function test_los_tipos(): void
    {
        $id = $this->tareaQuePide(['foto' => true, 'archivo' => true]);
        $yo = $this->alumno();

        $this->subir($id, $yo, 'archivo', UploadedFile::fake()->create('virus.exe', 10))->assertStatus(422);
        $this->subir($id, $yo, 'archivo', UploadedFile::fake()->create('notas.txt', 10))->assertStatus(422);
        $this->subir($id, $yo, 'foto', UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf'))->assertStatus(422);
        $this->subir($id, $yo, 'dibujo', UploadedFile::fake()->image('a.png', 10, 10))->assertStatus(422);

        $this->assertSame(0, DB::table('ws_archivos')->where('actividad_id', $id)->count());
    }

    public function test_solo_lo_que_la_tarea_pide(): void
    {
        $id = $this->tareaQuePide(['texto' => true, 'foto' => false, 'archivo' => false, 'enlace' => false]);
        $yo = $this->alumno();

        $this->subir($id, $yo, 'foto', UploadedFile::fake()->image('a.jpg', 10, 10))->assertStatus(422);
        $this->como($yo)->postJson("/api/act/{$id}/entregar", ['enlace' => 'https://drive.example/x'])->assertStatus(422);
        $this->como($yo)->postJson("/api/act/{$id}/entregar", [])->assertStatus(422);
        $this->assertSame(0, DB::table('ws_entregas')->where('actividad_id', $id)->count());
    }

    /** Una foto y un archivo por entrega: la segunda foto retira la primera. */
    public function test_una_foto_y_un_archivo(): void
    {
        $id = $this->tareaQuePide(['foto' => true, 'archivo' => true, 'texto' => true]);
        $yo = $this->alumno();

        $primera = $this->subir($id, $yo, 'foto', UploadedFile::fake()->image('uno.jpg', 100, 100))->assertStatus(200)->json('id');
        $segunda = $this->subir($id, $yo, 'foto', UploadedFile::fake()->image('dos.jpg', 100, 100))->assertStatus(200)->json('id');
        $archivo = $this->subir($id, $yo, 'archivo', UploadedFile::fake()->create('taller.pdf', 100, 'application/pdf'))->assertStatus(200)->json('id');

        $this->assertNotNull(DB::table('ws_archivos')->where('id', $primera)->value('deleted_at'), 'La primera foto no se retiró.');
        $this->como($yo)->postJson("/api/act/{$id}/entregar", ['foto_id' => $primera])->assertStatus(422);

        $r = $this->como($yo)->postJson("/api/act/{$id}/entregar", ['foto_id' => $segunda, 'archivo_id' => $archivo, 'texto' => 'Aquí va']);
        $r->assertStatus(200);
        $this->assertSame($segunda, $r->json('foto.id'));
        $this->assertSame($archivo, $r->json('archivo.id'));

        // Al docente le llega un aviso de entrega.
        $this->assertSame([(int) $this->escena()->titular->id], array_map(fn ($a) => (int) $a->user_id, $this->avisos($id, 'entregada')));
    }

    /** El archivo de otro alumno no se usa en la entrega propia. */
    public function test_no_se_entrega_el_archivo_de_otro(): void
    {
        $id = $this->tareaQuePide(['foto' => true]);
        $suya = $this->subir($id, $this->alumno(0), 'foto', UploadedFile::fake()->image('suya.jpg', 50, 50))->assertStatus(200)->json('id');

        $this->como($this->alumno(1))->postJson("/api/act/{$id}/entregar", ['foto_id' => $suya])->assertStatus(422);
    }

    public function test_quien_ve_el_fichero(): void
    {
        $id = $this->tareaQuePide(['foto' => true]);
        $e = $this->escena();
        $duenoDelFichero = collect($e->alumnos)->firstWhere('alumno_id', $e->acudiente->alumno_id);
        $otro = collect($e->alumnos)->first(fn ($a) => $a->alumno_id !== $duenoDelFichero->alumno_id);

        $archivo = $this->subir($id, $duenoDelFichero, 'foto', UploadedFile::fake()->image('mia.jpg', 50, 50))->assertStatus(200)->json('id');

        foreach ([$duenoDelFichero, 'titular', 'directivo', 'acudiente'] as $quien) {
            $r = $this->como($quien)->get("/api/act/archivos/{$archivo}");
            $this->assertSame(200, $r->getStatusCode(), 'No dejó ver el fichero a '.(is_object($quien) ? 'quien lo subió' : $quien).'.');
            $this->assertStringStartsWith('inline;', (string) $r->headers->get('Content-Disposition'));
        }

        foreach ([$otro, 'ajeno', 'llano'] as $quien) {
            $this->assertSame(403, $this->como($quien)->getJson("/api/act/archivos/{$archivo}")->status(),
                'Dejó ver el fichero a '.(is_object($quien) ? 'otro alumno' : $quien).'.');
        }
    }

    /** Las entregas del docente: una fila por alumno destinatario, con o sin entrega. */
    public function test_las_entregas_del_docente(): void
    {
        $id = $this->tareaQuePide(['texto' => true]);
        $this->como($this->alumno(0))->postJson("/api/act/{$id}/entregar", ['texto' => 'Hecho'])->assertStatus(200);

        $r = $this->como('titular')->getJson("/api/act/{$id}/entregas")->assertStatus(200);
        $porAlumno = collect($r->json('filas'))->keyBy('alumno.alumno_id');

        $this->assertGreaterThanOrEqual(count($this->escena()->alumnos), count($porAlumno));
        $this->assertSame('entregada', $porAlumno[$this->alumno(0)->alumno_id]['estado']);
        $this->assertSame('Hecho', $porAlumno[$this->alumno(0)->alumno_id]['entrega']['texto']);
        $this->assertSame('sin_entregar', $porAlumno[$this->alumno(1)->alumno_id]['estado']);
        $this->assertNull($porAlumno[$this->alumno(1)->alumno_id]['entrega']);
        $this->assertSame(50, $r->json('nota_maxima'));
        $this->assertSame(30, $r->json('aprobatoria'));

        // Filtrado por grupo, y 422 para lo que no es una tarea.
        $this->assertCount(count($porAlumno), $this->como('titular')->getJson("/api/act/{$id}/entregas?grupo_id={$this->escena()->grupo_id}")->json('filas'));
        [$encuesta] = $this->encuestaPublicada();
        $this->como('titular')->getJson("/api/act/{$encuesta}/entregas")->assertStatus(422);
    }
}
