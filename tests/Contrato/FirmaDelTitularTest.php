<?php

namespace Tests\Contrato;

use App\Models\Grupo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * La firma del titular es una SOLICITUD: la sube él, la aprueba otro.
 *
 * Las tres preguntas del pedido, cada una con su mitad que sí y su mitad que no:
 * el titular no se aprueba a sí mismo, un docente llano no aprueba, y lo pendiente
 * no llega a lo que se imprime (`Grupo::datos`, que es de donde lo lee el boletín).
 */
class FirmaDelTitularTest extends CasoDeContrato
{
    private string $directorioPrevio;

    private string $temporal;

    /** Ver `ImagenesTest::setUp`: la subida escribe en una ruta relativa. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directorioPrevio = (string) getcwd();
        $this->temporal = sys_get_temp_dir().'/contrato-firma-titular-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->temporal);
        chdir($this->temporal);
    }

    protected function tearDown(): void
    {
        chdir($this->directorioPrevio);
        File::deleteDirectory($this->temporal);

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_la_pendiente_no_se_imprime_y_la_aprobada_si(): void
    {
        [$titular, $grupo] = $this->titular();
        $antes = $this->firmaImpresa($grupo);

        $r = $this->withToken($this->tokenDe($titular->username))
            ->post('/api/firmas-del-titular/solicitar', ['file' => $this->firma('firma.png')]);

        $r->assertStatus(201);
        $this->assertSame('pendiente', $r->json('solicitud.estado'));
        $nueva = (int) $r->json('solicitud.firma_id_new');

        $this->assertSame($antes, $this->firmaImpresa($grupo),
            'La firma pendiente llegó a lo que imprime el boletín antes de aprobarse.');

        $jefe = $this->conRol($this->otroProfesor([$titular->id]), 'Coord académico');

        $this->withToken($this->tokenDe($jefe->username))
            ->putJson('/api/firmas-del-titular/aprobar/'.$r->json('solicitud.asked_id'))
            ->assertStatus(200);

        $this->assertSame($nueva, $this->firmaImpresa($grupo),
            'Aprobada, la firma tiene que ser la que imprime el boletín.');

        $this->assertSame(2, DB::table('auditoria')->where('entidad', 'pedido_de_cambio')
            ->where('entidad_id', $r->json('solicitud.asked_id'))->count(),
            'Pedirla y aprobarla dejan una línea cada una en la auditoría.');

        $this->withToken($this->tokenDe($titular->username))
            ->getJson('/api/firmas-del-titular/mia')
            ->assertJsonPath('solicitud.estado', 'aprobada');
    }

    public function test_el_titular_no_se_aprueba_a_si_mismo_aunque_tenga_el_rol(): void
    {
        [$titular, $grupo] = $this->titular();
        $this->conRol($titular, 'Coord académico');
        $antes = $this->firmaImpresa($grupo);

        $token = $this->tokenDe($titular->username);
        $id = $this->withToken($token)
            ->post('/api/firmas-del-titular/solicitar', ['file' => $this->firma('firma.jpg')])
            ->json('solicitud.asked_id');

        $this->withToken($token)->putJson('/api/firmas-del-titular/aprobar/'.$id)->assertStatus(403);
        $this->withToken($token)->putJson('/api/firmas-del-titular/rechazar/'.$id, ['motivo' => 'x'])->assertStatus(403);

        $this->assertSame($antes, $this->firmaImpresa($grupo));
        $this->assertSame('pendiente', $this->withToken($token)->getJson('/api/firmas-del-titular/mia')->json('solicitud.estado'));
    }

    public function test_un_docente_llano_no_aprueba_ni_ve_la_bandeja(): void
    {
        [$titular, $grupo] = $this->titular();
        $antes = $this->firmaImpresa($grupo);

        $id = $this->withToken($this->tokenDe($titular->username))
            ->post('/api/firmas-del-titular/solicitar', ['file' => $this->firma('firma.png')])
            ->json('solicitud.asked_id');

        $llano = $this->otroProfesor([$titular->id]);
        $token = $this->tokenDe($llano->username);

        $this->withToken($token)->putJson('/api/firmas-del-titular/aprobar/'.$id)->assertStatus(403);
        $this->withToken($token)->putJson('/api/firmas-del-titular/rechazar/'.$id, ['motivo' => 'no'])->assertStatus(403);
        $this->withToken($token)->getJson('/api/firmas-del-titular/pendientes')->assertStatus(403);

        $this->assertSame($antes, $this->firmaImpresa($grupo));
    }

    public function test_un_docente_llano_tampoco_pone_la_firma_por_la_puerta_directa(): void
    {
        [$titular, $grupo] = $this->titular();
        $antes = $this->firmaImpresa($grupo);

        $imagen = (int) $this->withToken($this->tokenDe($titular->username))
            ->post('/api/myimages/store-firma', ['file' => $this->firma('firma.png')])
            ->json('id');

        $this->withToken($this->tokenDe($titular->username))
            ->putJson('/api/images-users/cambiar-firma-un-profe/'.$titular->persona, ['imagen_id' => $imagen])
            ->assertStatus(403);

        $this->assertSame($antes, $this->firmaImpresa($grupo));
    }

    public function test_rechazar_pide_motivo_y_el_titular_lo_lee(): void
    {
        [$titular, $grupo] = $this->titular();
        $antes = $this->firmaImpresa($grupo);

        $id = $this->withToken($this->tokenDe($titular->username))
            ->post('/api/firmas-del-titular/solicitar', ['file' => $this->firma('firma.png')])
            ->json('solicitud.asked_id');

        $secre = $this->conRol($this->otroProfesor([$titular->id]), 'Secretario');
        $token = $this->tokenDe($secre->username);

        $this->assertContains((int) $id, array_map('intval',
            array_column($this->withToken($token)->getJson('/api/firmas-del-titular/pendientes')->json('pendientes'), 'asked_id')));

        $this->withToken($token)->putJson('/api/firmas-del-titular/rechazar/'.$id, ['motivo' => '  '])->assertStatus(422);
        $this->withToken($token)->putJson('/api/firmas-del-titular/rechazar/'.$id, ['motivo' => 'Sale con el fondo cuadriculado'])
            ->assertStatus(200);

        $mia = $this->withToken($this->tokenDe($titular->username))->getJson('/api/firmas-del-titular/mia');
        $mia->assertJsonPath('solicitud.estado', 'rechazada');
        $mia->assertJsonPath('solicitud.motivo', 'Sale con el fondo cuadriculado');

        $this->assertSame($antes, $this->firmaImpresa($grupo));
    }

    public function test_quien_no_es_titular_no_puede_pedir(): void
    {
        [$titular] = $this->titular();
        $otro = $this->otroProfesor([$titular->id]);
        DB::table('grupos')->where('titular_id', $otro->persona)->update(['titular_id' => null]);

        $this->withToken($this->tokenDe($otro->username))
            ->post('/api/firmas-del-titular/solicitar', ['file' => $this->firma('firma.png')])
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Un docente y un grupo de SU año del que se le hace titular. */
    private function titular(): array
    {
        $u = DB::selectOne('SELECT u.*, pr.id AS persona, pe.year_id FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            INNER JOIN periodos pe ON pe.id = u.periodo_id
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM grupos g WHERE g.year_id = pe.year_id AND g.deleted_at IS NULL)
            ORDER BY u.id LIMIT 1');
        $this->assertNotNull($u, 'El seed necesita un docente con grupos en su año.');
        $p = $u;

        $grupo = DB::selectOne('SELECT id FROM grupos WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$p->year_id]);
        $this->assertNotNull($grupo, 'El seed necesita un grupo en el año del docente.');

        DB::table('grupos')->where('id', $grupo->id)->update(['titular_id' => $p->persona]);
        DB::table('role_user')->where('user_id', $u->id)->delete();
        DB::table('users')->where('id', $u->id)->update(['is_superuser' => 0]);

        return [$u, (int) $grupo->id];
    }

    /** Otro docente activo, sin ningún rol, que el seed sepa resolver. */
    private function otroProfesor(array $menos): object
    {
        $u = DB::selectOne('SELECT u.*, pr.id AS persona FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            INNER JOIN periodos pe ON pe.id = u.periodo_id
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL AND u.is_superuser = 0
              AND u.id NOT IN ('.implode(',', array_map('intval', $menos)).')
              AND EXISTS (SELECT 1 FROM grupos g WHERE g.year_id = pe.year_id AND g.deleted_at IS NULL)
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed necesita dos docentes.');
        DB::table('role_user')->where('user_id', $u->id)->delete();

        return $u;
    }

    private function conRol(object $u, string $nombre): object
    {
        $rol = DB::table('roles')->where('name', $nombre)->value('id')
            ?? DB::table('roles')->insertGetId(['name' => $nombre, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('role_user')->insert(['user_id' => $u->id, 'role_id' => $rol]);

        return $u;
    }

    /** Lo que el boletín pinta como firma del titular de ese grupo. */
    private function firmaImpresa(int $grupo): ?int
    {
        $f = Grupo::datos($grupo)->firma_id ?? null;

        return $f === null ? null : (int) $f;
    }

    private function firma(string $nombre): UploadedFile
    {
        $lienzo = imagecreatetruecolor(300, 90);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imageline($lienzo, 10, 70, 290, 20, imagecolorallocate($lienzo, 0, 0, 120));

        $ruta = $this->temporal.'/'.uniqid().'-'.$nombre;
        str_ends_with($nombre, '.png') ? imagepng($lienzo, $ruta) : imagejpeg($lienzo, $ruta);
        imagedestroy($lienzo);

        return new UploadedFile($ruta, $nombre, null, null, true);
    }
}
