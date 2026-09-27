<?php

namespace Tests\Contrato;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * LA AYUDA DE IA SALE DEL PLAN DE EVALUACIÓN (26 sep 2026).
 *
 * Revisar un texto va a estar en el PIAR y en la disciplina, que usan docentes sin el permiso de
 * la plantilla. Desde hoy `ia/estado` e `ia/texto/revisar` sólo piden ser personal —quién la tiene
 * lo decide el proxy—, `ia/plantilla/proponer` sigue con su permiso, y `ia/uso` es de rectoría.
 */
class IaParaTodoElPersonalTest extends CasoDeContrato
{
    private const USO = [
        'colegio' => 'local',
        'mes' => '2026-09',
        'gastado' => 0.12,
        'tope' => 5,
        'llamadas' => 7,
        'por_tipo' => ['logro' => 5, 'piar' => 2],
        'modelo' => ['id' => 'claude-x', 'nombre' => 'Claude X'],
        'usos' => ['plantilla' => 3, 'texto' => 20],
        'activo' => true,
        'usuarios' => [['id' => '9', 'nombre' => 'Ana', 'plantilla' => 1, 'texto' => 4, 'costo' => 0.05]],
    ];

    private function conProxy(): void
    {
        config(['services.ia.url' => 'https://proxy.test', 'services.ia.secreto' => 'secreto-de-prueba']);
    }

    /** Un docente del seed que sólo tiene el rol `Profesor`, sin superusuario ni nombramiento. */
    private function docente(string ...$masRoles): string
    {
        $u = $this->usuarioDeTipo('Profesor');
        $p = DB::selectOne('SELECT id, user_id FROM profesores WHERE user_id = ? AND deleted_at IS NULL', [$u->id]);
        $this->assertNotNull($p, 'El seed no tiene un docente con usuario.');

        DB::table('users')->where('id', $p->user_id)->update(['is_superuser' => 0]);
        DB::table('years')->where('actual', 1)->update(['secretario_id' => null, 'tesorero_id' => null]);
        DB::table('role_user')->where('user_id', $p->user_id)->delete();
        foreach (['Profesor', ...$masRoles] as $rol) {
            DB::table('role_user')->insert(['user_id' => $p->user_id, 'role_id' => $this->rol($rol)]);
        }

        return $this->tokenDe((string) DB::table('users')->where('id', $p->user_id)->value('username'));
    }

    private function rol(string $nombre): int
    {
        return (int) (DB::table('roles')->where('name', $nombre)->whereNull('deleted_at')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => $nombre, 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_un_docente_sin_permiso_de_plantilla_pide_estado_y_revision(): void
    {
        $this->conProxy();
        Http::fake([
            'proxy.test/gasto*' => Http::response(['disponible' => true, 'gastado' => 0, 'tope' => 5, 'quedan' => ['texto' => 9]]),
            'proxy.test/texto/revisar' => Http::response(['sirve' => true]),
        ]);
        $token = $this->docente();

        // La premisa: este docente NO tiene el permiso de la plantilla. Si lo tuviera, lo de abajo
        // pasaría también con el código de antes.
        $this->withToken($token)->postJson('/api/ia/plantilla/proponer', [])->assertStatus(403);

        $this->withToken($token)->getJson('/api/ia/estado')->assertStatus(200)->assertJson(['disponible' => true]);
        $this->withToken($token)->postJson('/api/ia/texto/revisar', ['texto' => 'Falta de respeto', 'tipo' => 'falta'])
            ->assertStatus(200)->assertJson(['sirve' => true]);

        // `tipo` llega al proxy tal cual, aunque no sea de la plantilla.
        Http::assertSent(fn (PeticionHttp $p) => str_ends_with($p->url(), '/texto/revisar') && $p->data()['tipo'] === 'falta');
    }

    public function test_el_400_del_proxy_llega_con_su_frase(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response(['error' => 'No conozco el tipo «poema».'], 400)]);

        $this->withToken($this->docente())->postJson('/api/ia/texto/revisar', ['texto' => 'x', 'tipo' => 'poema'])
            ->assertStatus(400)->assertJson(['message' => 'No conozco el tipo «poema».']);
    }

    public function test_un_docente_no_ve_el_uso(): void
    {
        $this->conProxy();
        Http::fake();

        $this->withToken($this->docente())->getJson('/api/ia/uso')->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_el_rector_ve_el_uso_del_proxy(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/uso*' => Http::response(self::USO)]);

        $r = $this->withToken($this->docente('Rector'))->getJson('/api/ia/uso')->assertStatus(200);

        $this->assertSame(self::USO + ['configurada' => true, 'disponible' => true], $r->json());
        Http::assertSent(function (PeticionHttp $p) {
            parse_str((string) parse_url($p->url(), PHP_URL_QUERY), $query);

            return $p->hasHeader('Authorization', 'Bearer secreto-de-prueba')
                && in_array('Rector', $query['usuario']['roles'] ?? [], true);
        });
    }

    public function test_el_uso_sin_configurar_dice_configurada_false(): void
    {
        config(['services.ia.url' => '', 'services.ia.secreto' => '']);
        Http::fake();

        $this->withToken($this->docente('Rector'))->getJson('/api/ia/uso')
            ->assertStatus(200)->assertExactJson(['configurada' => false]);
    }

    public function test_el_uso_con_el_proxy_caido_dice_disponible_false(): void
    {
        $this->conProxy();
        $token = $this->docente('Rector');

        Http::fake(['proxy.test/*' => fn () => throw new ConnectionException('no contesta')]);
        $this->withToken($token)->getJson('/api/ia/uso')
            ->assertStatus(200)->assertExactJson(['configurada' => true, 'disponible' => false]);
    }

    public function test_el_uso_con_el_proxy_en_error_dice_disponible_false(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response('caído', 500)]);

        $this->withToken($this->docente('Rector'))->getJson('/api/ia/uso')
            ->assertStatus(200)->assertExactJson(['configurada' => true, 'disponible' => false]);
    }
}
