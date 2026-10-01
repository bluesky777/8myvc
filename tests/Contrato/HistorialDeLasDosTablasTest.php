<?php

namespace Tests\Contrato;

use App\Support\HistorialDeLasDosTablas;
use Illuminate\Support\Facades\DB;

/**
 * **Contrato 5: lo que se leía de `bitacoras` sale de `auditoria`, con la misma forma.**
 *
 * `historiales/nota-detalle` lo llama Flutter —una app para los dieciséis colegios,
 * sin versión mínima— y `app/`; `nota-final-detalle`, `historiales/de-usuario` y
 * `ChangesAsked/to-me` los llama `app/`. Ninguno se puede actualizar a la vez que el
 * servidor, así que estos casos fijan **las claves, su orden y sus tipos** con líneas
 * viejas, con nuevas y con las dos mezcladas, y que el tramo en que se escribían las
 * dos tablas no salga dos veces.
 *
 * Las fechas van escritas a mano y en 2025: el seed no trae ni `bitacoras` ni
 * `auditoria`, y así el corte (la primera línea nueva de cada entidad) es el que
 * pone cada caso y no el reloj del día en que se corra.
 */
class HistorialDeLasDosTablasTest extends CasoDeContrato
{
    /** Las claves de una fila de `cambios`, en el orden de la consulta vieja. */
    private const CLAVES_DE_UN_CAMBIO = ['bit_id', 'created_by_user_id', 'historial_id', 'created_at', 'new_value', 'old_value', 'creado_por'];

    private function superusuario(): object
    {
        $super = DB::selectOne('SELECT id, username FROM users
            WHERE is_superuser = 1 AND tipo = "Usuario" AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($super, 'El seed necesita un superusuario.');

        return (object) ['id' => (int) $super->id, 'username' => (string) $super->username, 'token' => $this->tokenDe($super->username)];
    }

    private function vieja(string $tipo, int $id, int $por, ?int $nueva, ?int $antes, string $cuando): int
    {
        return (int) DB::table('bitacoras')->insertGetId([
            'created_by' => $por,
            'affected_user_id' => 1,
            'affected_person_type' => 'Al',
            'affected_element_type' => $tipo,
            'affected_element_id' => $id,
            'affected_element_new_value_int' => $nueva,
            'affected_element_old_value_int' => $antes,
            'created_at' => $cuando,
        ]);
    }

    /** @param  array<string, mixed>  $mas */
    private function nueva(string $entidad, int $id, int $por, mixed $nueva, mixed $antes, string $cuando, string $accion = 'editar', array $mas = []): int
    {
        $num = fn ($v) => is_int($v) ? $v : null;

        return (int) DB::table('auditoria')->insertGetId([
            'actor_user_id' => $por,
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $id,
            'valor_anterior' => $antes === null ? null : json_encode($antes),
            'valor_nuevo' => $nueva === null ? null : json_encode($nueva),
            'valor_anterior_num' => $num($antes),
            'valor_nuevo_num' => $num($nueva),
            'atribucion' => 'aproximada',
            'ocurrido_en' => $cuando,
            ...$mas,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function cambiosDeLaNota(string $token, int $notaId): array
    {
        $r = $this->withToken($token)->putJson('/api/historiales/nota-detalle', ['nota_id' => $notaId])->assertStatus(200);

        return $r->json('cambios');
    }

    private function unaNota(): int
    {
        return (int) DB::table('notas')->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    /** Claves en orden y tipos de una línea nueva: los de la vieja. */
    private function assertFormaDeUnCambio(array $c): void
    {
        $this->assertSame(self::CLAVES_DE_UN_CAMBIO, array_keys($c), 'Cambiaron las claves o su orden: Flutter lee esta fila por nombre.');
        $this->assertIsInt($c['bit_id']);
        $this->assertIsInt($c['created_by_user_id']);
        $this->assertTrue($c['historial_id'] === null || is_int($c['historial_id']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $c['created_at'],
            'La fecha no tiene la forma de la vieja (sin milisegundos): `DateTime.tryParse` la lee, pero `app/` la pinta tal cual.');
        $this->assertTrue($c['new_value'] === null || is_int($c['new_value']), 'new_value dejó de ser entero.');
        $this->assertTrue($c['old_value'] === null || is_int($c['old_value']), 'old_value dejó de ser entero.');
        $this->assertIsString($c['creado_por']);
    }

    /**
     * **Sólo líneas viejas: la respuesta es la de la consulta de siempre**, fila a
     * fila y byte a byte. Es el caso de toda nota que nadie ha tocado desde el corte.
     */
    public function test_con_solo_lineas_viejas_la_respuesta_es_la_de_siempre(): void
    {
        $su = $this->superusuario();
        $nota = $this->unaNota();

        $this->vieja('Nota', $nota, $su->id, 30, 20, '2025-03-01 10:00:00');
        $this->vieja('Nota', $nota, $su->id, 35, 30, '2025-03-01 11:00:00');

        $esperado = json_decode(json_encode(DB::select(
            '(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, concat(p.nombres, " ", p.apellidos) as creado_por
				FROM bitacoras b inner join users u on u.id=b.created_by inner join profesores p on p.user_id=u.id
				where b.affected_element_type="Nota" and b.affected_element_id=?)
			UNION
			(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, u.username as creado_por
				FROM bitacoras b inner join users u on u.id=b.created_by AND u.tipo<>"Profesor"
				where b.affected_element_type="Nota" and b.affected_element_id=?)', [$nota, $nota])), true);

        $this->assertCount(2, $esperado);
        $this->assertSame($esperado, $this->cambiosDeLaNota($su->token, $nota));
    }

    /** **Sólo líneas nuevas**: mismas claves, mismo orden, mismos tipos. */
    public function test_con_solo_lineas_nuevas_la_forma_es_la_de_la_vieja(): void
    {
        $su = $this->superusuario();
        $nota = $this->unaNota();

        $id = $this->nueva('nota', $nota, $su->id, 40, 30, '2025-03-02 10:00:00.400');

        $cambios = $this->cambiosDeLaNota($su->token, $nota);

        $this->assertCount(1, $cambios);
        $this->assertFormaDeUnCambio($cambios[0]);
        $this->assertSame(HistorialDeLasDosTablas::DESPLAZAMIENTO + $id, $cambios[0]['bit_id']);
        $this->assertSame([40, 30, '2025-03-02 10:00:00', (string) $su->username],
            [$cambios[0]['new_value'], $cambios[0]['old_value'], $cambios[0]['created_at'], $cambios[0]['creado_por']]);
    }

    /**
     * **Mezcladas**: lo de antes del corte sale de la vieja, el tramo escrito en las
     * dos sale una vez, el reguardado de ese tramo no sale, y lo que sólo está en la
     * nueva sale. Y `bit_id` —por el que ordenan Flutter y `app/`— sigue el orden en
     * que pasaron.
     */
    public function test_mezcladas_salen_una_vez_y_en_orden(): void
    {
        $su = $this->superusuario();
        $nota = $this->unaNota();

        $antes = $this->vieja('Nota', $nota, $su->id, 30, 20, '2025-03-01 10:00:00');

        // El tramo de las dos: la vieja con la hora de inicio de la petición, la nueva un poco después.
        $this->vieja('Nota', $nota, $su->id, 40, 30, '2025-03-02 10:00:00');
        $gemela = $this->nueva('nota', $nota, $su->id, 40, 30, '2025-03-02 10:00:00.400');
        $this->vieja('Nota', $nota, $su->id, 40, 40, '2025-03-02 11:00:00');   // reguardado

        // Una nivelación que corrige la inicial: la vieja apunta la vigente y la nueva la original.
        $this->vieja('Nota', $nota, $su->id, 42, 40, '2025-03-02 12:00:00');
        $corregida = $this->nueva('nota', $nota, $su->id, 38, 36, '2025-03-02 12:00:00.200');

        // Después de quitar los escritores de `bitacoras`: sólo la nueva.
        $despues = $this->nueva('nota', $nota, $su->id, 45, 42, '2025-03-03 09:00:00.000', 'nivelar');

        $cambios = $this->cambiosDeLaNota($su->token, $nota);

        $ids = array_column($cambios, 'bit_id');
        $d = HistorialDeLasDosTablas::DESPLAZAMIENTO;
        $this->assertSame([$antes, $d + $gemela, $d + $corregida, $d + $despues], $ids,
            'Salen duplicadas, falta alguna o el orden por bit_id no es el de los hechos.');

        foreach ($cambios as $c) {
            $this->assertFormaDeUnCambio($c);
        }
    }

    /**
     * La definitiva: `nota_final` es DECIMAL y la vieja la apuntaba redondeada, así
     * que la nueva llega redondeada también. Y las marcas (manual, recuperada), que
     * `auditoria` guarda como `editar` con un 1/0, no son cambios de la nota.
     */
    public function test_la_definitiva_llega_redondeada_y_sin_las_marcas(): void
    {
        $su = $this->superusuario();
        $nf = (int) DB::table('notas_finales')->orderBy('id')->value('id');
        $this->assertGreaterThan(0, $nf, 'El seed necesita una definitiva.');

        $vieja = $this->vieja('NF_UPDATE', $nf, $su->id, 40, 35, '2025-04-01 10:00:00');
        $nueva = $this->nueva('nota_final', $nf, $su->id, '43.7500', '40.0000', '2025-04-02 10:00:00.000');
        $this->nueva('nota_final', $nf, $su->id, 1, null, '2025-04-02 11:00:00.000', 'editar', ['resumen' => 'Marcó la definitiva como manual']);

        $cambios = $this->withToken($su->token)->putJson('/api/historiales/nota-final-detalle', ['nf_id' => $nf])
            ->assertStatus(200)->json('cambios');

        $this->assertSame([$vieja, HistorialDeLasDosTablas::DESPLAZAMIENTO + $nueva], array_column($cambios, 'bit_id'));
        $this->assertFormaDeUnCambio($cambios[1]);
        $this->assertSame([44, 40], [$cambios[1]['new_value'], $cambios[1]['old_value']]);
    }

    /**
     * **Los intentos fallidos salen de `auditoria`** con las columnas de
     * `SELECT * FROM bitacoras`, en su orden. Lo viejo de antes del corte sigue, y el
     * intento escrito en las dos sale una vez.
     */
    public function test_los_intentos_fallidos_salen_de_auditoria_con_la_forma_de_siempre(): void
    {
        $su = $this->superusuario();
        $desc = 'Intento login>> Entorno: web, Dirección: 190.85.44.7';

        $viejo = (int) DB::table('bitacoras')->insertGetId([
            'created_by' => 0, 'descripcion' => $desc.' (viejo)', 'affected_person_name' => $su->username,
            'affected_element_type' => 'intento_login', 'created_at' => '2025-01-01 08:00:00',
        ]);
        DB::table('bitacoras')->insert([
            'created_by' => 0, 'descripcion' => $desc, 'affected_person_name' => $su->username,
            'affected_element_type' => 'intento_login', 'created_at' => '2025-01-02 08:00:00',
        ]);
        $nuevo = $this->nueva('intento_login', 0, 0, null, null, '2025-01-02 08:00:00.300', 'denegado',
            ['entidad_id' => null, 'actor_user_id' => null, 'actor_intentado' => $su->username, 'resumen' => $desc]);

        $intentos = $this->withToken($su->token)->getJson('/api/ChangesAsked/to-me')->assertStatus(200)->json('intentos_fallidos');

        $columnas = array_map(fn ($c) => $c->Field, DB::select('SHOW COLUMNS FROM bitacoras'));
        $this->assertSame([HistorialDeLasDosTablas::DESPLAZAMIENTO + $nuevo, $viejo], array_column($intentos, 'id'),
            'El intento escrito en las dos sale dos veces, o falta el viejo.');
        $this->assertSame($columnas, array_keys($intentos[0]), 'La fila nueva no tiene las columnas de bitacoras en su orden.');
        $this->assertSame($columnas, array_keys($intentos[1]));
        $this->assertSame([0, $desc, (string) $su->username, 'intento_login', '2025-01-02 08:00:00'],
            [$intentos[0]['created_by'], $intentos[0]['descripcion'], $intentos[0]['affected_person_name'],
                $intentos[0]['affected_element_type'], $intentos[0]['created_at']]);
    }

    /**
     * **`cant_cambios` cuenta en `auditoria` por `historial_id`**, y un ingreso de
     * antes, que sólo tiene bitácora, conserva su cifra. En los dos sitios que la
     * dan: el panel (`to-me`) y `historiales/de-usuario`.
     */
    /**
     * **El detalle de un ingreso (`historiales/sesion`)**: uno viejo sale con la
     * consulta de siempre, fila a fila; uno nuevo sale de `auditoria` con las mismas
     * columnas en el mismo orden, y lo escrito en las dos tablas no sale dos veces.
     */
    public function test_el_detalle_de_un_ingreso_sale_de_la_tabla_que_tiene_sus_cambios(): void
    {
        $su = $this->superusuario();
        $nota = DB::selectOne('SELECT n.id, n.alumno_id FROM notas n
            INNER JOIN alumnos a ON a.id = n.alumno_id AND a.deleted_at IS NULL
            INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
            WHERE n.deleted_at IS NULL ORDER BY n.id LIMIT 1');
        $this->assertNotNull($nota, 'El seed necesita una nota con alumno y subunidad.');

        $sesion = fn () => (int) DB::table('historiales')->insertGetId(['user_id' => $su->id, 'tipo' => 'login', 'created_at' => '2025-05-01 08:00:00']);
        $fila = fn (int $h, int $nueva, int $antes, string $cuando) => DB::table('bitacoras')->insert([
            'created_by' => $su->id, 'historial_id' => $h, 'affected_user_id' => $nota->alumno_id, 'affected_person_type' => 'Al',
            'affected_element_type' => 'Nota', 'affected_element_id' => $nota->id,
            'affected_element_new_value_int' => $nueva, 'affected_element_old_value_int' => $antes, 'created_at' => $cuando,
        ]);
        $detalle = fn (int $h) => $this->withToken($su->token)->putJson('/api/historiales/sesion', ['historial_id' => $h])
            ->assertStatus(200)->json('historial.bitacoras');

        $vieja = $sesion();
        $fila($vieja, 30, 20, '2025-05-01 08:10:00');
        $esperado = json_decode(json_encode(DB::select('SELECT b.*, a.nombres, a.apellidos, s.definicion FROM bitacoras b
            inner join alumnos a ON b.affected_user_id=a.id and a.deleted_at is null
            inner join notas n ON n.id=b.affected_element_id
            inner join subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
            WHERE b.historial_id=? and b.deleted_at is null', [$vieja])), true);
        $this->assertCount(1, $esperado);
        $this->assertSame($esperado, $detalle($vieja), 'El ingreso viejo dejó de salir como salía.');

        $nueva = $sesion();
        $fila($nueva, 40, 30, '2025-05-02 08:10:00');   // la gemela del tramo de las dos
        $id = $this->nueva('nota', (int) $nota->id, $su->id, 40, 30, '2025-05-02 08:10:00.300', 'editar', ['historial_id' => $nueva, 'alumno_id' => $nota->alumno_id]);

        $filas = $detalle($nueva);
        $this->assertCount(1, $filas, 'El ingreso nuevo enseña el cambio dos veces, o ninguna.');
        $this->assertSame(array_keys($esperado[0]), array_keys($filas[0]), 'La fila nueva no tiene las columnas de la vieja en su orden.');
        $this->assertSame(
            [HistorialDeLasDosTablas::DESPLAZAMIENTO + $id, (int) $su->id, $nueva, (int) $nota->alumno_id, 'Nota', (int) $nota->id, 40, 30, '2025-05-02 08:10:00'],
            [$filas[0]['id'], $filas[0]['created_by'], $filas[0]['historial_id'], $filas[0]['affected_user_id'], $filas[0]['affected_element_type'],
                $filas[0]['affected_element_id'], $filas[0]['affected_element_new_value_int'], $filas[0]['affected_element_old_value_int'], $filas[0]['created_at']]
        );
        $this->assertSame([$esperado[0]['nombres'], $esperado[0]['apellidos'], $esperado[0]['definicion']],
            [$filas[0]['nombres'], $filas[0]['apellidos'], $filas[0]['definicion']]);
    }

    public function test_cant_cambios_cuenta_en_auditoria_y_lo_viejo_se_queda(): void
    {
        $su = $this->superusuario();
        $sesion = fn (string $cuando) => (int) DB::table('historiales')->insertGetId([
            'user_id' => $su->id, 'tipo' => 'login', 'created_at' => $cuando, 'updated_at' => $cuando,
        ]);

        $vieja = $sesion('2030-01-01 08:00:00');
        $nueva = $sesion('2030-01-02 08:00:00');

        foreach ([1, 2] as $_) {
            DB::table('bitacoras')->insert(['created_by' => $su->id, 'historial_id' => $vieja, 'affected_element_type' => 'Nota', 'created_at' => '2025-01-01 08:00:00']);
        }
        foreach ([1, 2, 3] as $_) {
            $this->nueva('nota', 1, $su->id, 30, 20, '2030-01-02 09:00:00.000', 'editar', ['historial_id' => $nueva]);
        }
        // Una del tramo de las dos en la sesión nueva: no suma dos veces.
        DB::table('bitacoras')->insert(['created_by' => $su->id, 'historial_id' => $nueva, 'affected_element_type' => 'Nota', 'created_at' => '2030-01-02 09:00:00']);

        $porRuta = [
            'to-me' => $this->withToken($su->token)->getJson('/api/ChangesAsked/to-me')->assertStatus(200)->json('historial'),
            'de-usuario' => $this->withToken($su->token)->putJson('/api/historiales/de-usuario', ['user_id' => $su->id])->assertStatus(200)->json('historial'),
        ];

        foreach ($porRuta as $ruta => $historial) {
            $cuenta = array_column($historial, 'cant_cambios', 'id');
            $this->assertSame(3, $cuenta[$nueva] ?? null, "$ruta: el ingreso nuevo no cuenta lo de auditoria.");
            $this->assertSame(2, $cuenta[$vieja] ?? null, "$ruta: el ingreso viejo perdió su cifra de la bitácora.");
        }
    }
}
