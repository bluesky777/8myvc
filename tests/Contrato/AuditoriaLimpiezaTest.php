<?php

namespace Tests\Contrato;

use App\Support\LimpiezaDeAuditoria;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;

/**
 * La pestaña «Limpieza» de `/auditoria` (contrato 4): `GET auditoria/limpieza/previa`,
 * `POST auditoria/limpieza` y `GET auditoria/limpieza/historial`.
 *
 * Borra datos: todo se siembra dentro de la transacción del test, con fechas de 2026
 * anteriores a la tope (`HASTA`), y lo que deja el propio login es de hoy, así que nunca
 * entra en ningún borrado. En la base de tests `auditoria`, `bitacoras`, `historiales` e
 * `importaciones` nacen vacías.
 *
 * El año actual del seed es 2025 (periodos 30..33, el 31 marcado `actual`); el 34 es el
 * P1 de 2026, que no es el año actual.
 */
class AuditoriaLimpiezaTest extends CasoDeContrato
{
    private const HASTA = '2026-06-30';

    private const P1_2025 = 30;

    private const P2_2025_ACTUAL = 31;

    private const P1_2026 = 34;

    private object $yo;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yo = $this->usuarioLlanoDelPersonal();
        DB::table('users')->where('id', $this->yo->id)->update(['is_superuser' => 1]);
        $this->token = $this->tokenDe($this->yo->username);
    }

    private function linea(array $campos): int
    {
        return (int) DB::table('auditoria')->insertGetId($campos + [
            'accion' => 'editar',
            'entidad' => 'nota',
            'entidad_id' => 1,
            'alumno_id' => 987654,
            'alumno_nombre' => 'ALUMNA DE PRUEBA',
            'actor_user_id' => 1,
            'actor_nombre' => 'Actor de prueba',
            'actor_tipo' => 'Profesor',
            'atribucion' => 'sesion',
            'ocurrido_en' => '2026-03-10 10:00:00.000',
        ]);
    }

    private function ingreso(string $creado): int
    {
        return (int) DB::table('historiales')->insertGetId([
            'user_id' => $this->yo->id, 'tipo' => 'Usuario', 'entorno' => 'Desktop', 'created_at' => $creado,
        ]);
    }

    private function bitacora(string $creada, ?int $historial = null): int
    {
        return (int) DB::table('bitacoras')->insertGetId([
            'created_by' => $this->yo->id, 'historial_id' => $historial, 'descripcion' => 'prueba', 'created_at' => $creada,
        ]);
    }

    private function importacion(string $estado, string $fin): int
    {
        return (int) DB::table('importaciones')->insertGetId([
            'tipo' => 'alumnos', 'huella' => str_repeat('a', 64), 'year' => 2026, 'estado' => $estado,
            'inicio' => $fin, 'fin' => $fin, 'cambios' => '{"5":{"nombres":["A","B"]}}',
        ]);
    }

    private function previa(array $query): array
    {
        $r = $this->withToken($this->token)->getJson('/api/auditoria/limpieza/previa?'.http_build_query($query));
        $r->assertStatus(200);

        return $r->json();
    }

    private function limpiar(array $incluir, string $hasta = self::HASTA): array
    {
        $r = $this->withToken($this->token)->postJson('/api/auditoria/limpieza', [
            'hasta' => $hasta, 'incluir' => $incluir, 'confirmacion' => 'BORRAR',
        ]);
        $r->assertStatus(200);

        return $r->json();
    }

    public function test_solo_el_superusuario_y_no_ve_auditoria(): void
    {
        $this->withToken($this->token)->getJson('/api/auditoria/permisos')->assertJsonPath('limpieza', true);

        // Con `veAuditoria` y sin superusuario: 403 en las tres, y la pestaña no sale.
        $otro = DB::selectOne('SELECT u.* FROM users u WHERE u.tipo = "Usuario" AND u.is_active = 1
            AND u.deleted_at IS NULL AND u.is_superuser = 0 AND u.id <> ? ORDER BY u.id LIMIT 1', [$this->yo->id]);
        $this->darPermisoDeAuditoria((int) $otro->id);
        $suyo = $this->tokenDe($otro->username);
        $this->linea(['ocurrido_en' => '2026-03-10 10:00:00.000']);

        $this->withToken($suyo)->getJson('/api/auditoria/permisos')->assertJsonPath('limpieza', false);
        $this->withToken($suyo)->getJson('/api/auditoria/limpieza/previa?hasta='.self::HASTA)->assertStatus(403);
        $this->withToken($suyo)->getJson('/api/auditoria/limpieza/historial')->assertStatus(403);
        $this->withToken($suyo)->postJson('/api/auditoria/limpieza', [
            'hasta' => self::HASTA, 'incluir' => ['auditoria'], 'confirmacion' => 'BORRAR',
        ])->assertStatus(403);

        $this->assertSame(1, DB::table('auditoria')->where('ocurrido_en', '<', '2026-07-01')->count());
        $this->assertSame(0, DB::table('auditoria_limpiezas')->count());
    }

    public function test_hoy_una_fecha_futura_o_sin_borrar_dan_422_y_no_borran_nada(): void
    {
        $this->linea(['ocurrido_en' => '2026-03-10 10:00:00.000']);
        $hoy = Reloj::ahora()->format('Y-m-d');
        $manana = Reloj::ahora()->addDay()->format('Y-m-d');
        $ayer = Reloj::ahora()->subDay()->format('Y-m-d');
        $haceUnMes = Reloj::ahora()->subMonthNoOverflow()->format('Y-m-d');
        $tope = Reloj::ahora()->subMonthNoOverflow()->subDay()->format('Y-m-d');

        foreach ([$hoy, $manana, $ayer, $haceUnMes, '2026-02-30', 'ayer', ''] as $hasta) {
            $this->withToken($this->token)->getJson('/api/auditoria/limpieza/previa?hasta='.$hasta)->assertStatus(422);
            $this->withToken($this->token)->postJson('/api/auditoria/limpieza', [
                'hasta' => $hasta, 'incluir' => ['auditoria'], 'confirmacion' => 'BORRAR',
            ])->assertStatus(422);
        }

        foreach ([null, '', 'borrar', 'BORRA', 'SI'] as $confirmacion) {
            $this->withToken($this->token)->postJson('/api/auditoria/limpieza', [
                'hasta' => self::HASTA, 'incluir' => ['auditoria'], 'confirmacion' => $confirmacion,
            ])->assertStatus(422);
        }

        foreach ([[], ['auditoria', 'users'], 'todo'] as $incluir) {
            $this->withToken($this->token)->postJson('/api/auditoria/limpieza', [
                'hasta' => self::HASTA, 'incluir' => $incluir, 'confirmacion' => 'BORRAR',
            ])->assertStatus(422);
        }

        $this->assertSame(1, DB::table('auditoria')->where('ocurrido_en', '<', '2026-07-01')->count());
        $this->assertSame(0, DB::table('auditoria_limpiezas')->count());

        // Ayer sí vale.
        $this->withToken($this->token)->getJson('/api/auditoria/limpieza/previa?hasta='.$tope)->assertStatus(200);
    }

    public function test_la_previa_cuenta_periodos_completos_y_parciales(): void
    {
        // 2025 P1: tres, todas dentro → completo.
        foreach (['2026-02-01', '2026-03-01', '2026-04-01'] as $f) {
            $this->linea(['periodo_id' => self::P1_2025, 'ocurrido_en' => "$f 08:00:00.000"]);
        }
        // 2025 P2 (el actual): dos dentro, una fuera → parcial. La del 30 jun a las 23:59
        // está dentro: la fecha tope es inclusiva.
        $this->linea(['periodo_id' => self::P2_2025_ACTUAL, 'ocurrido_en' => '2026-05-01 08:00:00.000']);
        $this->linea(['periodo_id' => self::P2_2025_ACTUAL, 'entidad' => 'comportamiento', 'ocurrido_en' => '2026-06-30 23:59:59.999']);
        $this->linea(['periodo_id' => self::P2_2025_ACTUAL, 'ocurrido_en' => '2026-07-01 00:00:00.000']);
        // Sin periodo: una de datos y una de otra cosa.
        $this->linea(['entidad' => 'alumno', 'ocurrido_en' => '2026-01-15 08:00:00.000']);
        $this->linea(['entidad' => 'unidad', 'ocurrido_en' => '2026-01-20 08:00:00.000']);
        // La constancia de una limpieza anterior: nunca cuenta.
        $this->linea(['entidad' => 'limpieza', 'ocurrido_en' => '2026-01-01 08:00:00.000']);

        $p = $this->previa(['hasta' => self::HASTA, 'incluir' => 'auditoria']);

        $this->assertSame(self::HASTA, $p['hasta']);
        $this->assertSame(['auditoria' => 7, 'importaciones' => 0, 'bitacoras' => 0, 'historiales' => 0], $p['cuenta']);
        $this->assertSame(['datos' => 1, 'notas' => 4, 'convivencia' => 1, 'otras' => 1], $p['por_familia']);
        $this->assertSame([
            ['periodo_id' => self::P1_2025, 'year' => 2025, 'numero' => 1, 'total_linea' => 3, 'a_borrar' => 3, 'completo' => true, 'actual' => false],
            ['periodo_id' => self::P2_2025_ACTUAL, 'year' => 2025, 'numero' => 2, 'total_linea' => 3, 'a_borrar' => 2, 'completo' => false, 'actual' => true],
        ], $p['periodos']);
        $this->assertSame(2, $p['sin_periodo']);
        $this->assertTrue($p['toca_periodo_actual']);
        $this->assertTrue($p['toca_anio_actual']);
        $this->assertSame(['periodo_id' => self::P2_2025_ACTUAL, 'year' => 2025, 'numero' => 2], $p['periodo_actual']);
        $this->assertSame('2026-01-15 08:00:00.000', $p['primera_fecha']);
        $this->assertSame('2026-07-01', $p['queda_desde']);
        $this->assertSame('2026-07-01 00:00:00.000', $p['ultima_fecha_que_queda']);
        $this->assertSame(0, $p['historial_huerfano']);

        // Hasta marzo sólo llega a 2025 P1: no toca el periodo actual, sí el año actual.
        $p = $this->previa(['hasta' => '2026-03-31', 'incluir' => 'auditoria']);
        $this->assertSame(4, $p['cuenta']['auditoria']);
        $this->assertSame([[
            'periodo_id' => self::P1_2025, 'year' => 2025, 'numero' => 1, 'total_linea' => 3, 'a_borrar' => 2, 'completo' => false, 'actual' => false,
        ]], $p['periodos']);
        $this->assertFalse($p['toca_periodo_actual']);
        $this->assertTrue($p['toca_anio_actual']);

        // Hasta enero, sólo líneas sin periodo y sin año: ni periodo ni año actual.
        $p = $this->previa(['hasta' => '2026-01-31', 'incluir' => 'auditoria']);
        $this->assertSame(2, $p['cuenta']['auditoria']);
        $this->assertSame([], $p['periodos']);
        $this->assertFalse($p['toca_periodo_actual']);
        $this->assertFalse($p['toca_anio_actual']);

        // …pero con su año en la línea, sí.
        $this->linea(['entidad' => 'alumno', 'year_id' => 8, 'ocurrido_en' => '2026-01-16 08:00:00.000']);
        $this->assertTrue($this->previa(['hasta' => '2026-01-31', 'incluir' => 'auditoria'])['toca_anio_actual']);

        // Un periodo de otro año (2026 P1) no es el actual aunque lleve `actual = 1`.
        $this->linea(['periodo_id' => self::P1_2026, 'ocurrido_en' => '2026-01-17 08:00:00.000']);
        $p = $this->previa(['hasta' => '2026-01-31', 'incluir' => 'auditoria']);
        $this->assertSame([self::P1_2026], array_column($p['periodos'], 'periodo_id'));
        $this->assertFalse($p['toca_periodo_actual']);
    }

    public function test_la_previa_sin_incluir_son_las_casillas_de_serie_y_avisa_de_los_ingresos_huerfanos(): void
    {
        $viejo = $this->ingreso('2026-05-01 08:00:00');
        $this->linea(['historial_id' => $viejo, 'ocurrido_en' => '2026-05-02 08:00:00.000']);   // se va con él
        $this->linea(['historial_id' => $viejo, 'ocurrido_en' => '2026-07-02 08:00:00.000']);   // se queda huérfana

        // Un ingreso con un token vivo no se borra (el token perdería su ingreso)…
        $conToken = (int) DB::table('personal_access_tokens')->orderByDesc('id')->value('historial_id');
        $this->assertGreaterThan(0, $conToken, 'El login tendría que dejar el token atado a su ingreso.');
        DB::table('historiales')->where('id', $conToken)->update(['created_at' => '2026-05-01 08:00:00']);
        $this->linea(['historial_id' => $conToken, 'ocurrido_en' => '2026-07-03 08:00:00.000']);
        // …ni uno con una bitácora que se queda (el CASCADE se la llevaría).
        $conBitacora = $this->ingreso('2026-05-01 08:00:00');
        $this->bitacora('2026-07-05 08:00:00', $conBitacora);
        // Y el de una bitácora que también se borra, sí.
        $conBitacoraVieja = $this->ingreso('2026-05-01 08:00:00');
        $this->bitacora('2026-05-05 08:00:00', $conBitacoraVieja);

        $deSerie = $this->previa(['hasta' => self::HASTA]);
        $this->assertSame(['auditoria', 'importaciones', 'bitacoras'], $deSerie['incluir']);
        $this->assertSame(0, $deSerie['cuenta']['historiales']);
        $this->assertSame(0, $deSerie['historial_huerfano']);

        $p = $this->previa(['hasta' => self::HASTA, 'incluir' => 'auditoria,bitacoras,historiales']);
        $this->assertSame(2, $p['cuenta']['historiales']);   // $viejo y $conBitacoraVieja
        $this->assertSame(1, $p['cuenta']['bitacoras']);
        $this->assertSame(1, $p['historial_huerfano']);

        // Sin la casilla de bitácoras, la vieja se queda y bloquea su ingreso.
        $p = $this->previa(['hasta' => self::HASTA, 'incluir' => 'auditoria,historiales']);
        $this->assertSame(1, $p['cuenta']['historiales']);
        $this->assertSame(1, $p['historial_huerfano']);

        // Sin la de auditoría, las dos líneas de $viejo se quedan huérfanas.
        $this->assertSame(2, $this->previa(['hasta' => self::HASTA, 'incluir' => 'historiales'])['historial_huerfano']);

        // Y el borrado hace lo que dijo la previa.
        $r = $this->limpiar(['auditoria', 'historiales']);
        $this->assertSame(1, $r['cuenta']['historiales']);
        $this->assertFalse(DB::table('historiales')->where('id', $viejo)->exists());
        $this->assertTrue(DB::table('historiales')->where('id', $conToken)->exists());
        $this->assertTrue(DB::table('historiales')->where('id', $conBitacora)->exists());
        $this->assertTrue(DB::table('historiales')->where('id', $conBitacoraVieja)->exists());
        $this->assertSame(2, DB::table('bitacoras')->where('descripcion', 'prueba')->count(),
            'Sin la casilla de bitácoras, borrar ingresos no puede llevarse ninguna por el CASCADE.');
    }

    public function test_borra_hasta_la_fecha_respeta_las_casillas_y_deja_constancia(): void
    {
        $antes = $this->linea(['ocurrido_en' => '2026-06-30 23:59:59.999']);
        $despues = $this->linea(['ocurrido_en' => '2026-07-01 00:00:00.000']);
        $bitVieja = $this->bitacora('2026-04-01 08:00:00');
        $bitNueva = $this->bitacora('2026-07-01 08:00:00');
        $impVieja = $this->importacion('completada', '2026-04-01 08:00:00');
        $impNueva = $this->importacion('completada', '2026-07-01 08:00:00');
        $impAMedias = $this->importacion('fallida', '2026-04-01 08:00:00');
        $ingresoViejo = $this->ingreso('2026-04-01 08:00:00');

        // Sólo bitácoras: la auditoría y las importaciones no se tocan.
        $r = $this->limpiar(['bitacoras']);
        $this->assertSame(['auditoria' => 0, 'importaciones' => 0, 'bitacoras' => 1, 'historiales' => 0], $r['cuenta']);
        $this->assertFalse(DB::table('bitacoras')->where('id', $bitVieja)->exists());
        $this->assertTrue(DB::table('bitacoras')->where('id', $bitNueva)->exists());
        $this->assertTrue(DB::table('auditoria')->where('id', $antes)->exists());
        $this->assertNotNull(DB::table('importaciones')->where('id', $impVieja)->value('cambios'));

        // Las de serie.
        $r = $this->limpiar(['auditoria', 'importaciones', 'bitacoras']);
        $this->assertSame(['auditoria' => 1, 'importaciones' => 1, 'bitacoras' => 0, 'historiales' => 0], $r['cuenta']);
        $this->assertSame(LimpiezaDeAuditoria::TERMINADA, $r['estado']);
        $this->assertFalse(DB::table('auditoria')->where('id', $antes)->exists());
        $this->assertTrue(DB::table('auditoria')->where('id', $despues)->exists());
        // La importación no se borra: sólo pierde lo que cambió.
        $this->assertTrue(DB::table('importaciones')->where('id', $impVieja)->exists());
        $this->assertNull(DB::table('importaciones')->where('id', $impVieja)->value('cambios'));
        $this->assertNotNull(DB::table('importaciones')->where('id', $impNueva)->value('cambios'));
        $this->assertNotNull(DB::table('importaciones')->where('id', $impAMedias)->value('cambios'),
            'Una importación sin terminar se va a reanudar: lo que lleva cambiado no se toca.');
        $this->assertTrue(DB::table('historiales')->where('id', $ingresoViejo)->exists(),
            'Los ingresos no vienen marcados: sin su casilla no se borran.');

        // La constancia, en las dos tablas.
        $fila = DB::table('auditoria_limpiezas')->where('id', $r['id'])->first();
        $this->assertSame((int) $this->yo->id, (int) $fila->user_id);
        $this->assertSame(self::HASTA, $fila->hasta);
        $this->assertSame('auditoria,importaciones,bitacoras', $fila->incluir);
        $this->assertSame([1, 1, 0], [(int) $fila->borrados_auditoria, (int) $fila->borrados_importaciones, (int) $fila->borrados_bitacoras]);
        $this->assertSame([1, 1, 0], [(int) $fila->previa_auditoria, (int) $fila->previa_importaciones, (int) $fila->previa_bitacoras]);
        $this->assertSame(LimpiezaDeAuditoria::TERMINADA, $fila->estado);
        $this->assertNotNull($fila->fin);

        $linea = DB::table('auditoria')->where('entidad', 'limpieza')->where('entidad_id', $r['id'])->first();
        $this->assertNotNull($linea, 'La limpieza tiene que dejar su línea en `auditoria`.');
        $this->assertSame('borrar', $linea->accion);
        $this->assertSame((int) $this->yo->id, (int) $linea->actor_user_id);

        // Y el historial las enseña, de la más nueva a la más vieja.
        $h = $this->withToken($this->token)->getJson('/api/auditoria/limpieza/historial')->assertStatus(200)->json('filas');
        $this->assertCount(2, $h);
        $this->assertSame($r['id'], $h[0]['id']);
        $this->assertSame(['auditoria', 'importaciones', 'bitacoras'], $h[0]['incluir']);
        $this->assertSame(['bitacoras'], $h[1]['incluir']);
        $this->assertSame(['id', 'user_id', 'nombre', 'foto', 'hasta', 'incluir', 'previa', 'cuenta', 'inicio', 'fin', 'estado', 'error'], array_keys($h[0]));
    }

    public function test_volver_a_lanzarla_es_idempotente_y_no_se_borra_su_propia_constancia(): void
    {
        $this->linea(['ocurrido_en' => '2026-03-10 10:00:00.000']);
        $this->bitacora('2026-03-10 10:00:00');

        $primera = $this->limpiar(['auditoria', 'bitacoras'], '2026-04-30');
        $this->assertSame(1, $primera['cuenta']['auditoria']);

        // La constancia de la primera: se le pone fecha vieja para ver que ni así se borra.
        DB::table('auditoria')->where('entidad', 'limpieza')->update(['ocurrido_en' => '2026-01-01 00:00:00.000']);

        $segunda = $this->limpiar(['auditoria', 'bitacoras']);
        $this->assertSame(['auditoria' => 0, 'importaciones' => 0, 'bitacoras' => 0, 'historiales' => 0], $segunda['cuenta']);
        $this->assertSame(LimpiezaDeAuditoria::TERMINADA, $segunda['estado']);
        $this->assertSame(2, DB::table('auditoria')->where('entidad', 'limpieza')->count());
        $this->assertSame(2, DB::table('auditoria_limpiezas')->count());
        $this->assertSame(0, $this->previa(['hasta' => self::HASTA, 'incluir' => 'auditoria,bitacoras'])['cuenta']['auditoria']);
    }

    public function test_borra_en_tandas_de_cinco_mil(): void
    {
        $filas = [];
        for ($i = 0; $i < LimpiezaDeAuditoria::TANDA + 1; $i++) {
            $filas[] = [
                'accion' => 'editar', 'entidad' => 'nota', 'entidad_id' => $i, 'actor_user_id' => 1,
                'atribucion' => 'sesion', 'ocurrido_en' => '2026-03-10 10:00:00.000',
            ];
        }
        foreach (array_chunk($filas, 1000) as $trozo) {
            DB::table('auditoria')->insert($trozo);
        }
        $this->linea(['ocurrido_en' => '2026-07-01 00:00:00.000']);

        $r = $this->limpiar(['auditoria']);

        $this->assertSame(LimpiezaDeAuditoria::TANDA + 1, $r['cuenta']['auditoria']);
        $this->assertSame(0, DB::table('auditoria')->where('ocurrido_en', '<', '2026-07-01')->where('entidad', '<>', 'limpieza')->count());
        $this->assertSame(1, DB::table('auditoria')->where('ocurrido_en', '2026-07-01 00:00:00.000')->count());
    }
}
