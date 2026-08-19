<?php

namespace Tests\Contrato;

use App\Models\TokenDeSesion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * La sesión de la Fase 3: par acceso + refresco, con Sanctum.
 *
 * Lo que estos tests fijan no es "que Sanctum funcione" —eso es cosa de
 * Laravel— sino las tres decisiones que se tomaron aquí y que se pueden
 * deshacer sin querer:
 *
 *   1. El token de acceso NO sirve para renovarse a sí mismo. Si algún día
 *      alguien lo permite "por comodidad", el token deja de caducar de verdad.
 *   2. Cerrar sesión BORRA el token. Es lo que no hacía el JWT, y es la razón
 *      de toda la fase.
 *   3. El refresco recién rotado se acepta unos segundos. Sin eso, dos pestañas
 *      renovando a la vez cierran la sesión del usuario.
 *
 * El contrato de cara a los clientes está en docs/migracion/07-sesion.md.
 */
class SesionTest extends CasoDeContrato
{
    /** Entra por la ruta nueva y devuelve el par. */
    private function entrar(string $username): array
    {
        $r = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => self::CLAVE,
        ]);

        $r->assertStatus(200);

        return $r->json();
    }

    private function cab(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** Adelanta el reloj de un token concreto sin tocar el de los demás. */
    private function caducar(string $plano): void
    {
        $token = TokenDeSesion::findToken($plano);

        $this->assertNotNull($token, 'El token no está en la tabla.');

        $token->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();
    }

    public function test_login_devuelve_el_par_y_el_contexto(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        $par = $this->entrar($usuario->username);

        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40}$/', $par['el_token']);
        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40}$/', $par['refresco']);
        $this->assertNotSame($par['el_token'], $par['refresco']);

        // Los segundos de vida del de acceso, que es de donde el frontend saca
        // cada cuánto renovar.
        $this->assertGreaterThan(0, $par['expira_en']);
        $this->assertLessThanOrEqual(config('sesion.acceso_ttl') * 60, $par['expira_en']);

        // El contexto va incluido para ahorrar la segunda vuelta a /api/login.
        $this->assertSame($usuario->id, $par['usuario']['user_id']);
    }

    /** Falta un campo: 422, no 400. Ruta nueva, código correcto. */
    public function test_login_sin_contrasena_es_422(): void
    {
        $this->postJson('/api/auth/login', ['username' => 'lo-que-sea'])->assertStatus(422);
    }

    public function test_credenciales_malas_siguen_dando_400_invalid_credentials(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $r = $this->postJson('/api/auth/login', [
            'username' => $usuario->username,
            'password' => 'esta-no-es',
        ]);

        $r->assertStatus(400);
        $this->assertSame('invalid_credentials', $r->json('error'));
    }

    /**
     * El de acceso abre la API; el de refresco no.
     *
     * Es la mitad de la razón de que sean dos. Si el refresco valiera para
     * llamar a la API, viajaría en cada petición y estaría igual de expuesto
     * que el de acceso, que es justo lo que se evita.
     */
    public function test_el_refresco_no_abre_la_api(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $this->getJson('/api/ciudades', $this->cab($par['el_token']))->assertStatus(200);
        $this->getJson('/api/ciudades', $this->cab($par['refresco']))->assertStatus(401);
    }

    /**
     * Y la otra mitad: el de acceso no se renueva a sí mismo.
     *
     * Si se permitiera, el token que viaja en cada petición seguiría sirviendo
     * después de caducar mientras durara la ventana de refresco — o sea que no
     * caducaría, solo lo parecería.
     */
    public function test_el_acceso_no_sirve_para_refrescar(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $this->postJson('/api/auth/refresh', [], $this->cab($par['el_token']))->assertStatus(401);
    }

    public function test_el_acceso_caducado_no_entra(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $this->getJson('/api/ciudades', $this->cab($par['el_token']))->assertStatus(200);

        $this->caducar($par['el_token']);

        $this->getJson('/api/ciudades', $this->cab($par['el_token']))->assertStatus(401);
    }

    /** Con el refresco caducado ya no se renueva: hay que volver a entrar. */
    public function test_el_refresco_caducado_no_renueva(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $this->caducar($par['refresco']);

        $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']))->assertStatus(401);
    }

    /**
     * Refrescar da un par nuevo, y el de acceso viejo sigue vivo.
     *
     * Lo segundo importa: si al rotar se borrara el acceso anterior, la pestaña
     * que estuviera usándolo se comería un 401 en mitad de una pantalla.
     */
    public function test_refrescar_da_un_par_nuevo_sin_matar_el_acceso_anterior(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $r = $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']));

        $r->assertStatus(200);

        $nuevo = $r->json();

        $this->assertNotSame($par['el_token'], $nuevo['el_token']);
        $this->assertNotSame($par['refresco'], $nuevo['refresco']);

        $this->getJson('/api/ciudades', $this->cab($nuevo['el_token']))->assertStatus(200);
        $this->getJson('/api/ciudades', $this->cab($par['el_token']))->assertStatus(200);
    }

    /**
     * La gracia: el refresco recién rotado se sigue aceptando unos segundos.
     *
     * Es el caso de dos pestañas renovando casi a la vez. Sin gracia, la
     * segunda recibe 401 y el frontend cierra la sesión de un usuario que no ha
     * hecho nada mal — y de forma intermitente, que es lo peor de reportar.
     */
    public function test_el_refresco_recien_rotado_se_acepta_durante_la_gracia(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']))->assertStatus(200);

        // La misma pestaña rezagada, con el refresco que la otra acaba de jubilar.
        $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']))->assertStatus(200);
    }

    /** Pasada la gracia, ese mismo refresco ya no vale y queda anotado. */
    public function test_pasada_la_gracia_el_refresco_rotado_es_401_y_se_anota(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']))->assertStatus(200);

        $this->caducar($par['refresco']);

        $antes = DB::table('bitacoras')->where('affected_element_type', 'refresco_reutilizado')->count();

        $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']))->assertStatus(401);

        $this->assertSame(
            $antes + 1,
            DB::table('bitacoras')->where('affected_element_type', 'refresco_reutilizado')->count(),
            'Reutilizar un refresco ya rotado debería quedar escrito en bitacoras.'
        );
    }

    /**
     * **El titular de la Fase 3.** Cerrar sesión mata el token.
     *
     * Hasta ahora `login/logout` solo escribía la hora en `historiales` y el
     * JWT seguía valiendo 24 horas: quien copiara el token, o quien se sentara
     * después en el equipo compartido de la sala de profesores, seguía
     * entrando. Cerrar sesión era cosmético.
     */
    public function test_logout_mata_el_token_de_verdad(): void
    {
        $par = $this->entrar($this->usuarioDeTipo('Usuario')->username);

        $this->getJson('/api/ciudades', $this->cab($par['el_token']))->assertStatus(200);

        $this->postJson('/api/auth/logout', [], $this->cab($par['el_token']))->assertStatus(200);

        $this->getJson('/api/ciudades', $this->cab($par['el_token']))->assertStatus(401);

        // Y se lleva por delante el refresco de la misma sesión: si no, cerrar
        // sesión solo aplazaría el problema una hora.
        $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']))->assertStatus(401);
    }

    /** Y el logout viejo hace lo mismo, que es lo que usa el front sin actualizar. */
    public function test_el_logout_viejo_tambien_mata_el_token(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        $this->getJson('/api/ciudades', $this->cab($token))->assertStatus(200);

        $this->putJson('/api/login/logout', [], $this->cab($token))->assertStatus(200);

        $this->getJson('/api/ciudades', $this->cab($token))->assertStatus(401);
    }

    /** Cerrar una sesión no cierra las demás del mismo usuario. */
    public function test_logout_solo_cierra_su_sesion(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $movil = $this->entrar($usuario->username);
        $portatil = $this->entrar($usuario->username);

        $this->postJson('/api/auth/logout', [], $this->cab($movil['el_token']))->assertStatus(200);

        $this->getJson('/api/ciudades', $this->cab($movil['el_token']))->assertStatus(401);
        $this->getJson('/api/ciudades', $this->cab($portatil['el_token']))->assertStatus(200);
    }

    /**
     * `logout-all` sí las cierra todas.
     *
     * Es lo que hay que poder hacer cuando alguien sospecha que le han robado
     * el token, y hasta la Fase 3 no existía: un JWT no se puede revocar.
     */
    public function test_logout_all_cierra_todas_las_sesiones(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $movil = $this->entrar($usuario->username);
        $portatil = $this->entrar($usuario->username);

        $r = $this->postJson('/api/auth/logout-all', [], $this->cab($movil['el_token']));

        $r->assertStatus(200);
        $this->assertGreaterThanOrEqual(4, $r->json('borrados'));

        $this->getJson('/api/ciudades', $this->cab($movil['el_token']))->assertStatus(401);
        $this->getJson('/api/ciudades', $this->cab($portatil['el_token']))->assertStatus(401);
    }

    /** A quien han desactivado no se le renueva la sesión. */
    public function test_no_se_renueva_a_un_usuario_desactivado(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $par = $this->entrar($usuario->username);

        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$usuario->id]);

        $r = $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']));

        $r->assertStatus(400);
        $this->assertSame('user_inactivo', $r->json('error'));
    }

    /**
     * La ruta vieja emite un token largo y sin refresco.
     *
     * Un front que no conoce `/api/auth/*` no sabría qué hacer con un refresco,
     * así que su sesión tiene que durar de una vez lo que duraba el JWT (24 h).
     * Si aquí se emitiera el token corto, esos colegios sacarían al usuario
     * cada hora sin avisar a nadie.
     */
    public function test_la_ruta_vieja_emite_un_token_largo_y_sin_refresco(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $r = $this->postJson('/api/login/credentials', [
            'username' => $usuario->username,
            'password' => self::CLAVE,
        ]);

        $r->assertStatus(200);
        $this->assertArrayNotHasKey('refresco', $r->json());

        $token = TokenDeSesion::findToken($r->json('el_token'));

        $this->assertNotNull($token);
        $this->assertTrue(
            $token->expires_at->gt(Carbon::now()->addMinutes(config('sesion.acceso_ttl'))),
            'El token de la ruta vieja debería durar más que uno de acceso normal.'
        );
    }

    /**
     * Un token que no es nuestro no entra, sea lo que sea.
     *
     * Aquí vivían dos tests de los JWT viejos: que seguían valiendo y que el
     * interruptor los cortaba. Se fueron con `tymon/jwt-auth` al saltar a
     * Laravel 10. Lo que queda es la regla que dejaron: **lo que no es un token
     * de Sanctum no es nada**, y ya no hay un segundo formato al que caer.
     *
     * El JWT del caso de prueba es uno real, de los que emitía este backend
     * antes de la Fase 3.
     */
    #[DataProvider('tokensQueNoValen')]
    public function test_un_token_que_no_es_nuestro_no_entra(string $token): void
    {
        $this->getJson('/api/ciudades', $this->cab($token))->assertStatus(401);
    }

    public static function tokensQueNoValen(): array
    {
        return [
            'un JWT de los de antes' => ['eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9'
                .'.eyJzdWIiOjEsImlhdCI6MTc1NTYxNzE0NCwiZXhwIjoxNzU1NzAzNTQ0fQ'
                .'.qXQeQ3nS0cVYQ9m5Fh1x2pKcJ8lLwZ4tR7bN6vA3sEo'],
            'basura' => ['no-es-un-token'],
            'la forma pero inventado' => ['99999|'.str_repeat('a', 40)],
            'vacío como texto' => ['null'],
        ];
    }
}
