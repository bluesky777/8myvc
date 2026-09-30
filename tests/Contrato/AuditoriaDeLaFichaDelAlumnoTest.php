<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * La ficha del alumno entera, su papelera y su fusión, ahora en `auditoria`.
 *
 * `alumnos/update` era la escritura más grande de la ficha sin rastro: el guardado
 * campo a campo (`GuardarAlumno`) ya dejaba línea y el formulario completo no. Lo
 * mismo la papelera por las dos rutas —`alumnos/*` y `editnota/*`, que pese al
 * nombre borran ALUMNOS— y la unión de dos fichas.
 *
 * Como sus hermanos, **se mira la fila que queda y no el 200**: `Auditoria::guardar()`
 * se traga sus excepciones, así que una línea perdida contesta igual que una escrita.
 */
class AuditoriaDeLaFichaDelAlumnoTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');
        $this->assertNotNull($super, 'El seed no tiene superusuario con periodo.');

        return $this->tokenDe($super->username);
    }

    /** Las líneas de un alumno como entidad, más recientes primero. */
    private function lineasDe(string $entidad, int $id): array
    {
        return DB::select('SELECT * FROM auditoria WHERE entidad = ? AND entidad_id = ? ORDER BY id DESC',
            [$entidad, $id]);
    }

    private function alumnoConCuenta(): object
    {
        $alumno = DB::selectOne('SELECT a.id, a.user_id, a.nombres, a.apellidos, a.documento,
                a.ciudad_nac, a.ciudad_doc, a.tipo_doc, u.username
            FROM alumnos a INNER JOIN users u ON u.id = a.user_id
            WHERE a.deleted_at IS NULL AND u.deleted_at IS NULL ORDER BY a.id LIMIT 1');
        $this->assertNotNull($alumno, 'El seed necesita un alumno con cuenta.');

        return $alumno;
    }

    /** El cuerpo mínimo de `alumnos/update`, el mismo de `FichaDelAlumnoQueNoSeGuardaTest`. */
    private function cuerpo(object $alumno, array $extra = []): array
    {
        return array_merge([
            'nombres' => $alumno->nombres,
            'apellidos' => $alumno->apellidos,
            'documento' => $alumno->documento,
            'ciudad_nac' => ['id' => $alumno->ciudad_nac],
            'ciudad_doc' => ['id' => $alumno->ciudad_doc],
            'tipo_doc' => ['id' => $alumno->tipo_doc],
            'tipo_sangre' => ['sangre' => 'O+'],
            'grupo' => ['id' => null],
        ], $extra);
    }

    /** Un alumno sin nada colgado, ya en la papelera: el único que se deja borrar de verdad. */
    private function alumnoEnLaPapelera(): int
    {
        return (int) DB::table('alumnos')->insertGetId([
            'nombres' => 'Borrable', 'apellidos' => 'De Prueba', 'sexo' => 'F',
            'documento' => '990011223', 'deleted_at' => '2026-09-01 10:00:00',
        ]);
    }

    public function test_guardar_la_ficha_deja_una_linea_con_lo_que_cambio(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuenta();
        DB::table('alumnos')->where('id', $alumno->id)->update(['nombres' => 'Nombre Viejo', 'eps' => 'SURA']);

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpo($alumno, ['nombres' => 'Nombre Nuevo', 'eps' => 'SURA', 'username' => $alumno->username]))
            ->assertStatus(200);

        $lineas = $this->lineasDe('alumno', (int) $alumno->id);
        $this->assertCount(1, $lineas, 'Guardar la ficha no dejó exactamente una línea de alumno.');

        $l = $lineas[0];
        $de = json_decode((string) $l->valor_anterior, true);
        $a = json_decode((string) $l->valor_nuevo, true);

        $this->assertSame(Auditoria::EDITAR, $l->accion);
        $this->assertEquals($alumno->id, $l->alumno_id, 'La línea no cuelga del alumno.');
        $this->assertSame('Nombre Viejo', $de['nombres']);
        $this->assertSame('Nombre Nuevo', $a['nombres']);
        $this->assertArrayNotHasKey('eps', $a, 'Una columna que no cambió entró en la línea.');
        $this->assertArrayNotHasKey('updated_at', $a, 'Los sellos no son un cambio.');
        $this->assertSame('PUT alumnos/update/{id}', $l->ruta);
    }

    /** Sin `username` no hay `save()` (§118): la línea tiene que decir lo que pasó, o sea nada. */
    public function test_sin_username_no_se_guarda_y_no_hay_linea(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuenta();

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpo($alumno, ['nombres' => 'No Se Guarda']))
            ->assertStatus(200);

        $this->assertCount(0, $this->lineasDe('alumno', (int) $alumno->id));
    }

    /** La clave cambia y se dice; el hash no entra en la fila. */
    public function test_la_contrasena_se_anota_sin_su_valor(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuenta();

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpo($alumno, ['username' => $alumno->username, 'password' => 'OtraClave2026']))
            ->assertStatus(200);

        $lineas = $this->lineasDe('usuario', (int) $alumno->user_id);
        $this->assertCount(1, $lineas, 'Cambiar la clave no dejó línea de la cuenta.');

        $l = $lineas[0];
        $this->assertEquals($alumno->id, $l->alumno_id);
        $this->assertStringContainsString('la contraseña', (string) $l->resumen);
        $hash = (string) DB::table('users')->where('id', $alumno->user_id)->value('password');
        $this->assertStringNotContainsString($hash, (string) $l->valor_nuevo, 'El hash de la clave entró en la auditoría.');
        $this->assertStringNotContainsString('password', (string) $l->valor_nuevo.$l->valor_anterior);
    }

    /** @return list<array{0: string, 1: string, 2: string}> papelera, restaurar y borrado por cada prefijo */
    public static function rutas(): array
    {
        return [
            'alumnos' => ['alumnos/destroy', 'alumnos/restore', 'alumnos/forcedelete'],
            'editnota' => ['editnota/destroy', 'editnota/restore', 'editnota/forcedelete'],
        ];
    }

    /** @dataProvider rutas */
    public function test_la_papelera_y_la_vuelta_dejan_su_linea(string $destroy, string $restore, string $forcedelete): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuenta();

        $this->withToken($token)->deleteJson('/api/'.$destroy.'/'.$alumno->id)->assertStatus(200);
        $this->olvidarControladores();
        $this->withToken($token)->putJson('/api/'.$restore.'/'.$alumno->id)->assertStatus(200);

        [$vuelta, $ida] = $this->lineasDe('alumno', (int) $alumno->id);

        $this->assertSame(Auditoria::BORRAR, $ida->accion);
        $this->assertEquals($alumno->id, $ida->alumno_id);
        $this->assertNull(json_decode((string) $ida->valor_anterior, true)['deleted_at']);
        $this->assertNotNull(json_decode((string) $ida->valor_nuevo, true)['deleted_at']);
        $this->assertSame('DELETE '.$destroy.'/{id}', $ida->ruta);

        $this->assertSame(Auditoria::RESTAURAR, $vuelta->accion);
        $this->assertEquals($alumno->id, $vuelta->alumno_id);
        $this->assertSame(json_decode((string) $ida->valor_nuevo, true)['deleted_at'],
            json_decode((string) $vuelta->valor_anterior, true)['deleted_at'],
            'Restaurar no dice de qué fecha de papelera volvió.');
        $this->assertNull(json_decode((string) $vuelta->valor_nuevo, true)['deleted_at']);
    }

    /** @dataProvider rutas */
    public function test_el_borrado_definitivo_guarda_la_ficha_entera(string $destroy, string $restore, string $forcedelete): void
    {
        $token = $this->tokenDeSuperusuario();
        $id = $this->alumnoEnLaPapelera();

        $this->withToken($token)->deleteJson('/api/'.$forcedelete.'/'.$id)->assertStatus(200);

        $this->assertNull(DB::table('alumnos')->where('id', $id)->first(), 'No se borró.');

        $lineas = $this->lineasDe('alumno', $id);
        $this->assertCount(1, $lineas);
        $l = $lineas[0];
        $de = json_decode((string) $l->valor_anterior, true);

        $this->assertSame(Auditoria::BORRAR, $l->accion);
        $this->assertEquals($id, $l->alumno_id);
        $this->assertSame('Borrable', $de['nombres'], 'La ficha borrada no quedó en la línea.');
        $this->assertSame('990011223', $de['documento']);
        $this->assertNull($l->valor_nuevo);
    }

    /** Una línea en cada ficha, y cada una nombra a la otra. */
    public function test_la_fusion_dice_que_ficha_se_unio_en_cual(): void
    {
        $filas = DB::select('SELECT a.id FROM alumnos a
            INNER JOIN notas_finales nf ON nf.alumno_id = a.id
            WHERE a.deleted_at IS NULL GROUP BY a.id ORDER BY COUNT(*) DESC, a.id LIMIT 2');
        $this->assertCount(2, $filas, 'El seed no tiene dos alumnos con notas.');
        [$destino, $origen] = [(int) $filas[0]->id, (int) $filas[1]->id];

        $this->withToken($this->tokenDeSuperusuario())->putJson('/api/alumnos/fusionar', [
            'origen_id' => $origen, 'destino_id' => $destino,
        ])->assertStatus(200);

        $delOrigen = $this->lineasDe('alumno', $origen);
        $delDestino = $this->lineasDe('alumno', $destino);
        $this->assertCount(1, $delOrigen);
        $this->assertCount(1, $delDestino);

        $this->assertSame(Auditoria::BORRAR, $delOrigen[0]->accion, 'El origen va a la papelera: es un borrado.');
        $this->assertEquals($origen, $delOrigen[0]->alumno_id);
        $this->assertSame($destino, json_decode((string) $delOrigen[0]->valor_nuevo, true)['unida_en']);
        $this->assertSame($origen, json_decode((string) $delOrigen[0]->valor_anterior, true)['id']);

        $this->assertSame(Auditoria::EDITAR, $delDestino[0]->accion);
        $this->assertEquals($destino, $delDestino[0]->alumno_id);
        $this->assertSame($origen, json_decode((string) $delDestino[0]->valor_nuevo, true)['recibio_de']);
    }
}
