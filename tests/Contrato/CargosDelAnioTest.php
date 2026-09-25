<?php

namespace Tests\Contrato;

use App\Models\Role;
use App\Services\ContextoDeUsuario;
use App\Support\Autoriza;
use App\User;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * LOS CARGOS DEL AÑO TRAEN SU ROL (24 sep 2026), y lo que cuelga de eso.
 *
 * Joseth: *«si un profe es secretario y también tesorero (cosa muy común) su usuario docente pueda
 * hacer todo lo permitido a un secretario, tesorero y docente, lo mismo si solo es asignado
 * solamente como tesorero»*. El rol sale de `years.secretario_id` / `years.tesorero_id` del año
 * ACTUAL, sin fila en `role_user` (`Role::rolesDelNombramiento`).
 *
 * También fija el pendiente «sin secretario o tesorero» y que 8myvc le diga al proxy de IA quién
 * pide, que es de lo que depende el tablero del proxy.
 */
class CargosDelAnioTest extends CasoDeContrato
{
    private function anio(): int
    {
        return (int) DB::table('years')->where('actual', 1)->value('id');
    }

    /** Un docente del seed con usuario y sin ningún rol de administración. */
    private function docente(): object
    {
        // El de `usuarioDeTipo`, que es uno que puede iniciar sesión en el año actual: el primer
        // docente con usuario del seed contesta 400 al login (sin contexto) y la prueba del
        // pendiente se quedaría midiendo el login.
        $u = $this->usuarioDeTipo('Profesor');
        $p = DB::selectOne('SELECT id, user_id FROM profesores WHERE user_id = ? AND deleted_at IS NULL', [$u->id]);
        $this->assertNotNull($p, 'El seed no tiene un docente con usuario.');
        DB::table('users')->where('id', $p->user_id)->update(['is_superuser' => 0]);

        DB::table('role_user')->where('user_id', $p->user_id)->delete();
        DB::table('role_user')->insert(['user_id' => $p->user_id, 'role_id' => $this->rol('Profesor')]);

        return $p;
    }

    /** El id del rol; `Tesorero` lo crea la migración del 24 sep, y la base de pruebas puede no tenerla. */
    private function rol(string $nombre): int
    {
        return (int) (DB::table('roles')->where('name', $nombre)->whereNull('deleted_at')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => $nombre, 'created_at' => now(), 'updated_at' => now()]));
    }

    private function nombrar(?int $secretario, ?int $tesorero, ?int $yearId = null): void
    {
        $this->rol('Secretario');
        $this->rol('Tesorero');
        DB::table('years')->where('id', $yearId ?? $this->anio())
            ->update(['secretario_id' => $secretario, 'tesorero_id' => $tesorero]);
    }

    /** @return list<string> */
    private function rolesDe(int $userId): array
    {
        return array_column(Role::getUserRoles($userId), 'name');
    }

    public function test_sin_nombramiento_el_docente_solo_es_docente(): void
    {
        $p = $this->docente();
        $this->nombrar(null, null);

        $this->assertSame(['Profesor'], $this->rolesDe($p->user_id));
    }

    public function test_nombrado_secretario_y_tesorero_suma_los_dos_sin_perder_el_de_docente(): void
    {
        $p = $this->docente();
        $this->nombrar($p->id, $p->id);

        $this->assertSame(['Profesor', 'Secretario', 'Tesorero'], $this->rolesDe($p->user_id));
        $this->assertTrue(Role::isSecretario($p->user_id));
        $this->assertTrue(Role::isTesorero($p->user_id));
    }

    public function test_solo_tesorero(): void
    {
        $p = $this->docente();
        $this->nombrar(null, $p->id);

        $this->assertSame(['Profesor', 'Tesorero'], $this->rolesDe($p->user_id));
        $this->assertFalse(Role::isSecretario($p->user_id));
    }

    public function test_el_rol_dura_lo_que_dura_el_nombramiento_y_no_se_escribe_en_role_user(): void
    {
        $p = $this->docente();
        $this->nombrar($p->id, null);
        $this->assertContains('Secretario', $this->rolesDe($p->user_id));

        $this->nombrar(null, null);
        $this->assertNotContains('Secretario', $this->rolesDe($p->user_id));
        $this->assertSame(1, DB::table('role_user')->where('user_id', $p->user_id)->count());
    }

    public function test_un_nombramiento_de_otro_anio_no_da_el_rol(): void
    {
        $p = $this->docente();
        $otro = (int) DB::table('years')->where('actual', 0)->whereNull('deleted_at')->value('id');
        $this->assertGreaterThan(0, $otro, 'El seed no tiene un año que no sea el actual.');
        $this->nombrar(null, null);
        $this->nombrar($p->id, $p->id, $otro);

        $this->assertSame(['Profesor'], $this->rolesDe($p->user_id));
    }

    public function test_quien_ya_tenia_el_rol_a_mano_no_lo_tiene_dos_veces(): void
    {
        $p = $this->docente();
        DB::table('role_user')->insert(['user_id' => $p->user_id, 'role_id' => $this->rol('Secretario')]);
        $this->nombrar($p->id, null);

        $this->assertSame(1, count(array_keys($this->rolesDe($p->user_id), 'Secretario')));
    }

    public function test_el_contexto_de_la_sesion_lleva_los_roles_del_cargo_y_sus_permisos_de_verdad(): void
    {
        $p = $this->docente();
        $this->nombrar($p->id, $p->id);

        $contexto = (new ContextoDeUsuario)->para(User::find($p->user_id));
        $nombres = array_map(static fn ($r) => $r->name, $contexto->roles);

        $this->assertContains('Secretario', $nombres);
        $this->assertContains('Tesorero', $nombres);
        $this->assertTrue(Autoriza::esAdministrativo($contexto));
        $this->assertTrue(Autoriza::puedeResolverColillas($contexto, (int) $contexto->year_id));
    }

    public function test_el_tesorero_nombrado_aprueba_colillas_y_un_docente_sin_cargo_no(): void
    {
        $p = $this->docente();
        $this->nombrar(null, null);
        $sinCargo = (new ContextoDeUsuario)->para(User::find($p->user_id));
        $this->assertFalse(Autoriza::puedeResolverColillas($sinCargo, $this->anio()));

        $this->nombrar(null, $p->id);
        $conCargo = (new ContextoDeUsuario)->para(User::find($p->user_id));
        $this->assertTrue(Autoriza::puedeResolverColillas($conCargo, $this->anio()));
        $this->assertFalse(Autoriza::esAdministrativo($conCargo), 'Tesorero no es secretaría.');
    }

    /* ── El pendiente ──────────────────────────────────────────────────────────────────── */

    /** @return array<string, array<string, mixed>> */
    private function pendientesDelSuper(): array
    {
        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
            ->getJson('/api/pendientes/mios')->assertStatus(200);

        return array_column($r->json('pendientes'), null, 'tipo');
    }

    public function test_el_pendiente_sale_si_faltan_los_dos_y_dice_cuales(): void
    {
        $this->nombrar(null, null);
        $p = $this->pendientesDelSuper()['sin_secretario_tesorero'] ?? null;

        $this->assertNotNull($p);
        $this->assertSame('No hay secretario ni tesorero nombrados este año', $p['titular']);
        $this->assertSame('Nombrarlos', $p['destino']['etiqueta']);
        $this->assertSame('/colegio/'.$this->anio().'/ficha', $p['destino']['ruta']);
        $this->assertSame('posponible', $p['insistencia']);
    }

    public function test_si_falta_uno_nombra_solo_ese(): void
    {
        $this->nombrar($this->docente()->id, null);
        $p = $this->pendientesDelSuper()['sin_secretario_tesorero'] ?? null;

        $this->assertSame('No hay tesorero nombrado este año', $p['titular'] ?? null);
        $this->assertSame('Nombrarlo', $p['destino']['etiqueta'] ?? null);
    }

    public function test_con_los_dos_nombrados_se_va_aunque_sean_la_misma_persona(): void
    {
        $p = $this->docente();
        $this->nombrar($p->id, $p->id);

        $this->assertArrayNotHasKey('sin_secretario_tesorero', $this->pendientesDelSuper());
    }

    public function test_un_docente_no_lo_ve(): void
    {
        $p = $this->docente();
        $this->nombrar(null, null);
        $username = (string) DB::table('users')->where('id', $p->user_id)->value('username');
        $r = $this->withToken($this->tokenDe($username))->getJson('/api/pendientes/mios')->assertStatus(200);

        $this->assertNotContains('sin_secretario_tesorero', array_column($r->json('pendientes'), 'tipo'));
    }

    /* ── Lo que 8myvc le dice al proxy de IA ──────────────────────────────────────────── */

    public function test_la_ia_le_dice_al_proxy_quien_pide_y_respeta_su_disponible(): void
    {
        config(['services.ia.url' => 'https://proxy.test', 'services.ia.secreto' => 'secreto-de-prueba']);
        Http::fake(['proxy.test/*' => Http::response([
            'disponible' => false,
            'gastado' => 0.1,
            'tope' => 0.3,
            'quedan' => ['plantilla' => 5, 'texto' => 9],
        ])]);

        $super = $this->usuarioDeTipo('Usuario');
        $r = $this->withToken($this->tokenDe($super->username))->getJson('/api/ia/estado')->assertStatus(200);

        $this->assertFalse($r->json('disponible'), 'El proxy dijo que no: el colegio no se lo salta.');
        $this->assertSame(['plantilla' => 5, 'texto' => 9], $r->json('quedan'));

        Http::assertSent(function (PeticionHttp $peticion) use ($super) {
            parse_str((string) parse_url($peticion->url(), PHP_URL_QUERY), $query);

            return str_starts_with($peticion->url(), 'https://proxy.test/gasto')
                && $peticion->hasHeader('Authorization', 'Bearer secreto-de-prueba')
                && (string) ($query['usuario']['id'] ?? '') === (string) $super->id
                && ($query['usuario']['superusuario'] ?? null) === '1';
        });
    }

    public function test_la_frase_del_proxy_llega_tal_cual_cuando_se_acaban_los_usos(): void
    {
        config(['services.ia.url' => 'https://proxy.test', 'services.ia.secreto' => 'secreto-de-prueba']);
        Http::fake(['proxy.test/*' => Http::response(['error' => 'Ya usaste tus 2 revisiones de este mes.'], 429)]);

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
            ->postJson('/api/ia/texto/revisar', ['texto' => 'Trabajo', 'tipo' => 'logro']);

        $r->assertStatus(429);
        $this->assertSame('Ya usaste tus 2 revisiones de este mes.', $r->json('message'));

        Http::assertSent(fn (PeticionHttp $p) => ($p->data()['usuario']['id'] ?? null) !== null);
    }
}
