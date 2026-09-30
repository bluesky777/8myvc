<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Acudientes, perfiles, imágenes y las masivas de usuario, ahora en `auditoria`.
 *
 * El criterio es el de sus hermanos: **se mira la fila que queda, no el 200**.
 * `Auditoria::guardar()` se traga cualquier excepción, así que una entidad mal
 * escrita devuelve 200 con el rastro perdido. Todos van por la API de verdad, con
 * su token, para comprobar que la línea está dentro del método y detrás del guard.
 *
 * Lo que fija además, porque es una decisión: **el parentesco cuelga del alumno**
 * (`alumno_id` puesto) y la ficha del acudiente no, como en
 * `GuardarAlumno::valorAcudiente`.
 */
class AuditoriaDeAcudientesPerfilesEImagenesTest extends CasoDeContrato
{
    private function tokenDelSuperusuario(): string
    {
        return $this->tokenDe($this->superusuario()->username);
    }

    private function superusuario(): object
    {
        $fila = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario con periodo.');

        return $fila;
    }

    /** @return array<int, object> */
    private function lineasDe(string $entidad, int $id): array
    {
        return DB::select('SELECT * FROM auditoria WHERE entidad = ? AND entidad_id = ? ORDER BY id',
            [$entidad, $id]);
    }

    private function ultimaLinea(string $entidad, ?int $id = null): ?object
    {
        return $id === null
            ? DB::selectOne('SELECT * FROM auditoria WHERE entidad = ? AND entidad_id IS NULL ORDER BY id DESC LIMIT 1', [$entidad])
            : DB::selectOne('SELECT * FROM auditoria WHERE entidad = ? AND entidad_id = ? ORDER BY id DESC LIMIT 1', [$entidad, $id]);
    }

    /** Una imagen con nombre conocido. Sin dueño es del colegio y la puede poner el personal. */
    private function imagenLlamada(string $nombre, ?int $duenio = null): int
    {
        return (int) DB::table('images')->insertGetId([
            'nombre' => $nombre, 'user_id' => $duenio, 'created_at' => now(),
        ]);
    }

    private function unAlumno(): object
    {
        return DB::selectOne('SELECT id, user_id, celular FROM alumnos
            WHERE deleted_at IS NULL AND user_id IS NOT NULL ORDER BY id LIMIT 1');
    }

    public function test_crear_un_acudiente_deja_la_ficha_sin_alumno_y_el_parentesco_con_el(): void
    {
        $alumno = $this->unAlumno();

        $r = $this->withToken($this->tokenDelSuperusuario())->postJson('/api/acudientes/crear', [
            'nombres' => 'Aud4', 'apellidos' => 'Acudiente', 'sexo' => 'F',
            'documento' => (string) random_int(800000000, 899999999),
            'alumno_id' => $alumno->id,
            'parentesco' => ['parentesco' => 'Madre'],
        ]);
        $r->assertStatus(200);

        $acudienteId = (int) DB::table('acudientes')->where('nombres', 'Aud4')->orderByDesc('id')->value('id');
        $parentescoId = (int) DB::table('parentescos')->where('acudiente_id', $acudienteId)->value('id');

        $ficha = $this->lineasDe('acudiente', $acudienteId);
        $this->assertCount(1, $ficha, 'Crear el acudiente no dejó exactamente una línea de su ficha.');
        $this->assertSame(Auditoria::CREAR, $ficha[0]->accion);
        $this->assertNull($ficha[0]->alumno_id, 'La ficha del acudiente no cuelga de un alumno.');
        $this->assertSame('Aud4', json_decode($ficha[0]->valor_nuevo, true)['nombres']);
        $this->assertStringContainsString('Aud4 Acudiente', $ficha[0]->resumen);

        $union = $this->lineasDe('parentesco', $parentescoId);
        $this->assertCount(1, $union, 'El parentesco nuevo no dejó su línea.');
        $this->assertSame(Auditoria::CREAR, $union[0]->accion);
        $this->assertEquals($alumno->id, $union[0]->alumno_id, 'El parentesco no dice de qué alumno es.');
        $this->assertSame('Madre', json_decode($union[0]->valor_nuevo, true)['parentesco']);
    }

    public function test_el_parentesco_se_pone_se_cambia_y_se_quita_con_su_alumno(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $alumno = $this->unAlumno();
        $acudiente = DB::selectOne('SELECT id FROM acudientes WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');

        $this->withToken($token)->putJson('/api/acudientes/seleccionar-parentesco', [
            'acudiente_id' => $acudiente->id, 'alumno_id' => $alumno->id, 'parentesco' => 'Tío',
        ])->assertStatus(200);

        $id = (int) DB::table('parentescos')->where(['acudiente_id' => $acudiente->id, 'alumno_id' => $alumno->id])
            ->whereNull('deleted_at')->orderByDesc('id')->value('id');

        $this->withToken($token)->putJson('/api/acudientes/seleccionar-parentesco', [
            'parentesco_acudiente_cambiar_id' => $id, 'parentesco' => 'Abuelo',
        ])->assertStatus(200);

        // Reguardar lo mismo no es un cambio.
        $this->withToken($token)->putJson('/api/acudientes/seleccionar-parentesco', [
            'parentesco_acudiente_cambiar_id' => $id, 'parentesco' => 'Abuelo',
        ])->assertStatus(200);

        $this->withToken($token)->putJson('/api/acudientes/quitar-parentesco-alumno', ['parentesco_id' => $id])
            ->assertStatus(200);

        $lineas = $this->lineasDe('parentesco', $id);
        $this->assertSame([Auditoria::CREAR, Auditoria::EDITAR, Auditoria::BORRAR], array_column($lineas, 'accion'));

        foreach ($lineas as $linea) {
            $this->assertEquals($alumno->id, $linea->alumno_id, 'Una línea del parentesco no dice de qué alumno es.');
        }

        $this->assertSame('Tío', json_decode($lineas[1]->valor_anterior, true)['parentesco']);
        $this->assertSame('Abuelo', json_decode($lineas[1]->valor_nuevo, true)['parentesco']);
        $this->assertSame('Abuelo', json_decode($lineas[2]->valor_anterior, true)['parentesco']);
        $this->assertNull($lineas[2]->valor_nuevo);
    }

    public function test_editar_la_ficha_desde_perfiles_deja_linea_por_columna(): void
    {
        $token = $this->tokenDelSuperusuario();
        $alumno = $this->unAlumno();

        $this->withToken($token)->putJson('/api/perfiles/update/'.$alumno->id, [
            'tipo' => 'Alumno', 'celular' => '3119990001',
        ])->assertStatus(200);

        $linea = $this->ultimaLinea('alumno', (int) $alumno->id);
        $this->assertNotNull($linea, 'Editar la ficha del alumno no dejó línea.');
        $this->assertEquals($alumno->id, $linea->alumno_id);
        $this->assertSame(Auditoria::EDITAR, $linea->accion);
        $this->assertSame('3119990001', json_decode($linea->valor_nuevo, true));
        $this->assertSame($alumno->celular, json_decode((string) $linea->valor_anterior, true));

        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->withToken($token)->putJson('/api/perfiles/update/'.$profesor->id, [
            'tipo' => 'Profesor', 'celular' => '3119990002',
        ])->assertStatus(200);

        $linea = $this->ultimaLinea('profesor', (int) $profesor->id);
        $this->assertNotNull($linea, 'Editar la ficha del profesor no dejó línea.');
        $this->assertNull($linea->alumno_id);
        $this->assertSame('3119990002', json_decode($linea->valor_nuevo, true));

        $acudiente = DB::selectOne('SELECT id FROM acudientes WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->withToken($token)->putJson('/api/perfiles/update/'.$acudiente->id, [
            'tipo' => 'Ac', 'celular' => '3119990003',
        ])->assertStatus(200);

        $this->assertSame('3119990003',
            json_decode((string) $this->ultimaLinea('acudiente', (int) $acudiente->id)?->valor_nuevo, true));
    }

    public function test_la_papelera_de_perfiles_se_anota_como_grupo(): void
    {
        $token = $this->tokenDelSuperusuario();
        $grupo = DB::selectOne('SELECT id, nombre, year_id FROM grupos WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');

        $this->withToken($token)->deleteJson('/api/perfiles/destroy/'.$grupo->id)->assertStatus(200);
        $this->withToken($token)->putJson('/api/perfiles/restore/'.$grupo->id, [])->assertStatus(200);
        $this->withToken($token)->deleteJson('/api/perfiles/destroy/'.$grupo->id)->assertStatus(200);
        $this->withToken($token)->deleteJson('/api/perfiles/forcedelete/'.$grupo->id)->assertStatus(200);

        $lineas = $this->lineasDe('grupo', (int) $grupo->id);
        $this->assertSame([Auditoria::BORRAR, Auditoria::RESTAURAR, Auditoria::BORRAR, Auditoria::BORRAR],
            array_column($lineas, 'accion'));
        $this->assertStringContainsString('definitivamente el grupo '.$grupo->nombre, $lineas[3]->resumen);
        $this->assertEquals($grupo->year_id, $lineas[3]->year_id);
    }

    public function test_crear_todas_las_cuentas_deja_una_linea_por_cuenta(): void
    {
        $alumno = $this->unAlumno();
        DB::update('UPDATE alumnos SET user_id = NULL WHERE id = ?', [$alumno->id]);
        $acudiente = (int) DB::table('acudientes')->whereNull('deleted_at')->orderBy('id')->value('id');
        DB::update('UPDATE acudientes SET user_id = NULL WHERE id = ?', [$acudiente]);

        $this->withToken($this->tokenDelSuperusuario())->putJson('/api/perfiles/creartodoslosusuarios', [])
            ->assertStatus(200);

        $cuentaDelAlumno = (int) DB::table('alumnos')->where('id', $alumno->id)->value('user_id');
        $linea = $this->ultimaLinea('usuario', $cuentaDelAlumno);
        $this->assertNotNull($linea, 'La cuenta nueva del alumno no dejó línea.');
        $this->assertSame(Auditoria::CREAR, $linea->accion);
        $this->assertEquals($alumno->id, $linea->alumno_id);
        $this->assertSame('Alumno', json_decode($linea->valor_nuevo, true)['tipo']);
        $this->assertArrayNotHasKey('password', json_decode($linea->valor_nuevo, true));

        $cuentaDelAcudiente = (int) DB::table('acudientes')->where('id', $acudiente)->value('user_id');
        $linea = $this->ultimaLinea('usuario', $cuentaDelAcudiente);
        $this->assertNotNull($linea, 'La cuenta nueva del acudiente no dejó línea.');
        $this->assertNull($linea->alumno_id);
    }

    public function test_la_foto_de_un_alumno_por_perfiles_guarda_el_nombre_del_fichero(): void
    {
        $alumno = $this->unAlumno();
        $vieja = $this->imagenLlamada('aud4-vieja.jpg');
        $nueva = $this->imagenLlamada('aud4-nueva.jpg');
        DB::update('UPDATE alumnos SET foto_id = ? WHERE id = ?', [$vieja, $alumno->id]);

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/perfiles/cambiarimgunalumno/'.$alumno->id, ['imgOficialAlumno' => $nueva])
            ->assertStatus(200);

        $linea = $this->ultimaLinea('alumno', (int) $alumno->id);
        $this->assertNotNull($linea);
        $this->assertEquals($alumno->id, $linea->alumno_id);
        $this->assertSame('aud4-vieja.jpg', json_decode($linea->valor_anterior, true));
        $this->assertSame('aud4-nueva.jpg', json_decode($linea->valor_nuevo, true));
    }

    public function test_las_imagenes_de_images_users_guardan_el_nombre_del_fichero(): void
    {
        $alumno = $this->unAlumno();
        $cuenta = DB::selectOne('SELECT id, username FROM users WHERE id = ?', [$alumno->user_id]);
        $profesor = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        // La foto oficial de la ficha, puesta por un profesor.
        $foto = $this->imagenLlamada('aud4-foto.jpg');
        $this->withToken($profesor)->putJson('/api/images-users/cambiar-foto-un-usuario/'.$cuenta->id, ['imagen_id' => $foto])
            ->assertStatus(200);
        $linea = $this->ultimaLinea('alumno', (int) $alumno->id);
        $this->assertEquals($alumno->id, $linea?->alumno_id);
        $this->assertSame('aud4-foto.jpg', json_decode($linea->valor_nuevo, true));

        // El avatar de la cuenta, por el propio alumno.
        $avatar = $this->imagenLlamada('aud4-avatar.jpg', (int) $cuenta->id);
        $this->withToken($this->tokenDe($cuenta->username))
            ->putJson('/api/images-users/cambiar-imagen-un-usuario/'.$cuenta->id, ['imagen_id' => $avatar])
            ->assertStatus(200);
        $linea = $this->ultimaLinea('usuario', (int) $cuenta->id);
        $this->assertSame(Auditoria::EDITAR, $linea?->accion);
        $this->assertEquals($alumno->id, $linea->alumno_id, 'La cuenta del alumno cuelga de él.');
        $this->assertSame('aud4-avatar.jpg', json_decode($linea->valor_nuevo, true));

        // Sin superusuario, la imagen de perfil y la oficial son pedidos.
        $this->withToken($this->tokenDe($cuenta->username))
            ->putJson('/api/images-users/cambiar-imagen-perfil/'.$cuenta->id, ['imagen_id' => $avatar])
            ->assertStatus(200);
        $pedido = DB::selectOne('SELECT * FROM auditoria WHERE entidad = "pedido_de_cambio" ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($pedido, 'Pedir cambiar la imagen de perfil no dejó línea.');
        $this->assertSame('aud4-avatar.jpg', json_decode($pedido->valor_nuevo, true));
        $this->assertStringContainsString('imagen de perfil', $pedido->resumen);

        $this->withToken($this->tokenDe($cuenta->username))
            ->putJson('/api/images-users/cambiar-imagen-oficial/'.$cuenta->id, ['foto_id' => $avatar])
            ->assertStatus(200);
        $oficial = DB::selectOne('SELECT * FROM auditoria WHERE entidad = "pedido_de_cambio" ORDER BY id DESC LIMIT 1');
        $this->assertNotSame($pedido->id, $oficial->id, 'Pedir la foto oficial no dejó línea.');
        $this->assertSame((int) $pedido->entidad_id, (int) $oficial->entidad_id, 'Los dos van en el mismo pedido abierto.');
        $this->assertSame('aud4-avatar.jpg', json_decode($oficial->valor_nuevo, true));
        $this->assertStringContainsString('foto oficial', $oficial->resumen);

        // Con superusuario, la imagen de perfil se cambia en el acto.
        $super = $this->superusuario();
        $delColegio = $this->imagenLlamada('aud4-super.jpg');
        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/images-users/cambiar-imagen-perfil/'.$super->id, ['imagen_id' => $delColegio])
            ->assertStatus(200);
        $linea = $this->ultimaLinea('usuario', (int) $super->id);
        $this->assertSame('aud4-super.jpg', json_decode((string) $linea?->valor_nuevo, true));
        $this->assertNull($linea->alumno_id);
    }

    public function test_las_masivas_de_documento_como_usuario_dejan_una_linea_por_acto(): void
    {
        $token = $this->tokenDelSuperusuario();

        foreach (['alumnos', 'acudientes'] as $quienes) {
            $antes = (int) DB::table('auditoria')->count();

            $this->withToken($token)->putJson('/api/cambiar-usuarios/poner-documento-como-username-'.$quienes, [])
                ->assertStatus(200);

            $this->assertSame($antes + 1, (int) DB::table('auditoria')->count(), "La masiva de {$quienes} no dejó una sola línea.");
            $linea = $this->ultimaLinea('usuario');
            $this->assertSame(Auditoria::EDITAR, $linea->accion);
            $this->assertMatchesRegularExpression('/de \d+ cuentas de '.$quienes.'$/', $linea->resumen);
        }

        $this->withToken($token)->putJson('/api/cambiar-usuarios/documento-como-username', ['destino' => 'alumnos'])
            ->assertStatus(200);
        $linea = $this->ultimaLinea('usuario');
        $nuevo = json_decode($linea->valor_nuevo, true);
        $this->assertSame('alumnos', $nuevo['destino']);
        $this->assertIsInt($nuevo['cambiados']);
        $this->assertStringContainsString('cuentas de alumnos', $linea->resumen);
    }
}
