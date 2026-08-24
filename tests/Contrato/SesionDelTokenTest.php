<?php

namespace Tests\Contrato;

use App\Models\TokenDeSesion;
use Illuminate\Support\Facades\DB;

/**
 * La sesión atada al token — fase 2 de
 * [18-auditoria.md](../../docs/migracion/18-auditoria.md).
 *
 * Hasta ahora el token y la fila de `historiales` se creaban en el mismo login y
 * **nunca volvían a hablarse**, así que los nueve sitios que escriben
 * `historial_id` lo resolvían con `order by id desc limit 1`: **el último login de
 * esa persona, no la sesión que está haciendo el cambio**.
 *
 * ## Por qué estos casos abren DOS sesiones y no una
 *
 * Con una sola sesión abierta, «el último ingreso» y «el ingreso de este token»
 * son el mismo número, y **un test con una sola sesión pasa igual de verde con el
 * fallo dentro**. Es la trampa que este trabajo entero viene a cerrar, así que los
 * casos que importan entran dos veces y **usan el token de la PRIMERA**.
 *
 * Y no hace falta imaginarse dos aparatos para que esto muerda: el refresco vive
 * **catorce días y rota en cada uso**, así que quien entra a diario puede llevar
 * **meses** sin teclear la contraseña — no hay login nuevo, `historiales` no
 * crece, y todas sus escrituras de esos meses colgaban del ingreso de hace meses.
 */
class SesionDelTokenTest extends CasoDeContrato
{
    /** Entra y devuelve el par de tokens. */
    private function entrar(string $username): array
    {
        $r = $this->postJson('/api/auth/login', ['username' => $username, 'password' => self::CLAVE]);
        $r->assertStatus(200);

        return $r->json();
    }

    private function cab(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** El ingreso que quedó grabado en la fila del token. */
    private function ingresoDelToken(string $plano): ?int
    {
        $token = TokenDeSesion::findToken($plano);

        $this->assertNotNull($token, 'El token no está en la tabla.');

        return $token->historial_id === null ? null : (int) $token->historial_id;
    }

    /** Una nota que se pueda editar con el token que se le pase. */
    private function unaNota(): object
    {
        $nota = DB::selectOne('SELECT id, nota FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($nota, 'El seed necesita una nota.');

        return $nota;
    }

    private function superusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $super->username;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que ata
    // ─────────────────────────────────────────────────────────────────────

    /** Entrar graba el ingreso en **los dos** tokens, no sólo en el de acceso. */
    public function test_el_login_graba_el_ingreso_en_los_dos_tokens(): void
    {
        $antes = (int) DB::table('historiales')->max('id');

        $par = $this->entrar($this->superusuario());

        $ingreso = (int) DB::table('historiales')->max('id');

        $this->assertGreaterThan($antes, $ingreso, 'El login no anotó ningún ingreso.');

        $this->assertSame($ingreso, $this->ingresoDelToken($par['el_token']),
            'El token de acceso no sabe de qué ingreso salió.');

        $this->assertSame($ingreso, $this->ingresoDelToken($par['refresco']),
            'El refresco no lo sabe, y es el que sobrevive: sin esto la atribución se '.
            'pierde en la primera rotación.');
    }

    /**
     * **La rotación lo arrastra**, y es la mitad que hace que esto sirva de algo.
     *
     * El refresco rota en cada uso. Si el par nuevo no heredara el ingreso, la
     * atribución sería cierta durante una hora y falsa el resto de los catorce
     * días — que es exactamente el caso que se viene a arreglar.
     */
    public function test_refrescar_arrastra_el_ingreso_al_par_nuevo(): void
    {
        $par = $this->entrar($this->superusuario());
        $ingreso = $this->ingresoDelToken($par['el_token']);

        $this->assertNotNull($ingreso);

        $this->olvidarControladores();

        $nuevo = $this->postJson('/api/auth/refresh', [], $this->cab($par['refresco']))
            ->assertStatus(200)->json();

        $this->assertSame($ingreso, $this->ingresoDelToken($nuevo['el_token']),
            'El par nuevo perdió el ingreso al rotar.');
        $this->assertSame($ingreso, $this->ingresoDelToken($nuevo['refresco']));
    }

    /** La ruta vieja —la que llama `myvc_flutter`— también ata su token. */
    public function test_la_ruta_vieja_tambien_ata_el_token_a_su_ingreso(): void
    {
        $antes = (int) DB::table('historiales')->max('id');

        $r = $this->postJson('/api/login/credentials', [
            'username' => $this->superusuario(), 'password' => self::CLAVE,
        ]);
        $r->assertStatus(200);

        $ingreso = (int) DB::table('historiales')->max('id');

        $this->assertGreaterThan($antes, $ingreso);
        $this->assertSame($ingreso, $this->ingresoDelToken($r->json('el_token')),
            'La ruta vieja no ata su token. Es la que usa la app —una sola para los '.
            'dieciséis colegios—, así que sin esto seguiría escribiendo atribuciones '.
            'adivinadas para todos.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que arregla — con dos sesiones, que es donde se ve
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **El caso entero del lote**: con dos sesiones abiertas, lo que se escribe
     * con la primera se atribuye a la primera.
     *
     * Antes se anotaba con `order by id desc limit 1`, o sea con **la segunda** —
     * el último login— aunque el cambio viniera de la primera. Un profesor con la
     * app en el móvil y el navegador abierto veía todo su trabajo del navegador
     * colgando del ingreso del móvil.
     */
    public function test_con_dos_sesiones_el_cambio_se_atribuye_a_la_suya(): void
    {
        $username = $this->superusuario();

        $primera = $this->entrar($username);
        $ingresoPrimera = $this->ingresoDelToken($primera['el_token']);

        $this->olvidarControladores();

        $segunda = $this->entrar($username);
        $ingresoSegunda = $this->ingresoDelToken($segunda['el_token']);

        $this->assertNotSame($ingresoPrimera, $ingresoSegunda,
            'Las dos entradas dieron el mismo ingreso: el montaje no mide lo que cree.');

        $this->olvidarControladores();

        $nota = $this->unaNota();
        $nueva = (float) $nota->nota == 4.2 ? 3.1 : 4.2;

        // Se escribe con la PRIMERA, que ya no es la última.
        $this->withToken($primera['el_token'])
            ->putJson('/api/notas/update/'.$nota->id, ['nota' => $nueva])
            ->assertStatus(200);

        $bitacora = DB::selectOne('SELECT historial_id FROM bitacoras
            WHERE affected_element_id = ? AND affected_element_type = "Nota"
            ORDER BY id DESC LIMIT 1', [$nota->id]);

        $this->assertNotNull($bitacora, 'No se escribió la bitácora.');
        $this->assertEquals($ingresoPrimera, $bitacora->historial_id,
            'La bitácora colgó el cambio del ÚLTIMO ingreso en vez del de la sesión '.
            'que lo hizo. Es la lista falsa que ve el colegio en «qué hizo en este ingreso».');

        $linea = DB::selectOne('SELECT sesion_id, historial_id, atribucion FROM auditoria
            WHERE entidad = "nota" AND entidad_id = ? ORDER BY id DESC LIMIT 1', [$nota->id]);

        $this->assertNotNull($linea, 'No se escribió la línea de auditoría.');
        $this->assertEquals($ingresoPrimera, $linea->historial_id);
        $this->assertSame('sesion', $linea->atribucion,
            'La auditoría sigue diciendo «aproximada» teniendo la atribución cierta. '.
            'Es la columna con la que la pantalla distingue lo que se sabe de lo que '.
            'se adivinó (18 §5.2).');
    }

    /**
     * **Salir en un aparato no le pone la hora de salida al otro.**
     *
     * Es el noveno sitio con la misma forma y el único que **escribe**: por eso no
     * sale en un barrido de `SELECT ... FROM historiales`. Con dos sesiones
     * abiertas, salir en el móvil marcaba la salida en la del navegador —que
     * seguía abierta— y dejaba la del móvil sin hora para siempre.
     */
    public function test_salir_de_una_sesion_no_cierra_la_otra(): void
    {
        $username = $this->superusuario();

        $primera = $this->entrar($username);
        $ingresoPrimera = $this->ingresoDelToken($primera['el_token']);

        $this->olvidarControladores();

        $segunda = $this->entrar($username);
        $ingresoSegunda = $this->ingresoDelToken($segunda['el_token']);

        $this->olvidarControladores();

        // Se sale de la PRIMERA.
        $this->putJson('/api/login/logout', [], $this->cab($primera['el_token']))
            ->assertStatus(200)
            ->assertSee('Deslogueado');

        $salidas = DB::select('SELECT id, logout_at FROM historiales WHERE id IN (?, ?)',
            [$ingresoPrimera, $ingresoSegunda]);

        $porId = [];
        foreach ($salidas as $fila) {
            $porId[(int) $fila->id] = $fila->logout_at;
        }

        $this->assertNotNull($porId[$ingresoPrimera] ?? null,
            'La sesión de la que se salió se quedó sin hora de salida.');

        $this->assertNull($porId[$ingresoSegunda] ?? null,
            'Se le puso la hora de salida a la OTRA sesión, que sigue abierta. '.
            'Es lo que hacía el `order by id desc limit 1`.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que NO hace: adivinar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un token de antes de la migración **no adivina un ingreso**: escribe NULL.
     *
     * Es la ventana de despliegue —hasta catorce días, lo que dure el refresco más
     * largo vivo— y la decisión que la gobierna: **un NULL dice «no se sabe»; la
     * adivinanza decía «fue ése» y se equivocaba sin avisar** (18 §5.2). La
     * auditoría además lo deja escrito en `atribucion`, que es la columna con la
     * que la pantalla puede decirlo en vez de disimularlo.
     */
    public function test_un_token_anterior_a_la_migracion_no_adivina(): void
    {
        $par = $this->entrar($this->superusuario());

        // Se le quita el ingreso a los dos tokens: así queda exactamente como los
        // que ya estaban emitidos el día del despliegue.
        foreach ([$par['el_token'], $par['refresco']] as $plano) {
            $token = TokenDeSesion::findToken($plano);
            $token->forceFill(['historial_id' => null])->save();
        }

        $this->olvidarControladores();

        $nota = $this->unaNota();

        $this->withToken($par['el_token'])
            ->putJson('/api/notas/update/'.$nota->id, ['nota' => (float) $nota->nota == 4.2 ? 3.1 : 4.2])
            ->assertStatus(200);

        $bitacora = DB::selectOne('SELECT historial_id FROM bitacoras
            WHERE affected_element_id = ? AND affected_element_type = "Nota"
            ORDER BY id DESC LIMIT 1', [$nota->id]);

        $this->assertNull($bitacora->historial_id,
            'Sin ingreso conocido se volvió a adivinar el último login.');

        $linea = DB::selectOne('SELECT sesion_id, historial_id, atribucion FROM auditoria
            WHERE entidad = "nota" AND entidad_id = ? ORDER BY id DESC LIMIT 1', [$nota->id]);

        $this->assertNull($linea->historial_id);
        $this->assertNull($linea->sesion_id);
        $this->assertSame('aproximada', $linea->atribucion,
            'La línea dice que la atribución es cierta sin serlo.');
    }

    /**
     * Y guardar una nota **ya no depende de tener un ingreso**.
     *
     * El cruce viejo era `FROM notas n, (select … historiales …) h`: un producto
     * cartesiano, así que **sin ninguna fila en `historiales` devolvía cero filas**
     * y el `[0]` de después reventaba. La escritura fallaba por no encontrar un
     * INGRESO, no por nada de la propia nota — y en `YearsController` eso caía en
     * un `catch` que contestaba 422 **con el año ya guardado**.
     */
    public function test_guardar_una_nota_ya_no_depende_de_tener_un_ingreso(): void
    {
        $par = $this->entrar($this->superusuario());

        foreach ([$par['el_token'], $par['refresco']] as $plano) {
            TokenDeSesion::findToken($plano)->forceFill(['historial_id' => null])->save();
        }

        // Y además se le quitan TODOS los ingresos, que es el caso que reventaba.
        $usuario = DB::selectOne('SELECT id FROM users WHERE username = ?', [$this->superusuario()]);
        DB::update('UPDATE historiales SET deleted_at = NOW() WHERE user_id = ?', [$usuario->id]);

        $this->olvidarControladores();

        $nota = $this->unaNota();

        // Enteros, y no es un detalle del test: **`notas.nota` es un `int`** (18
        // §4.5.1). Con 4.2 la fila guarda 4 y la comprobación mediría el redondeo
        // de la columna en vez de lo que este caso viene a mirar. Y 42 cabe en la
        // escala de este colegio, que va de 0 a 50.
        $nueva = (int) $nota->nota === 42 ? 31 : 42;

        $this->withToken($par['el_token'])
            ->putJson('/api/notas/update/'.$nota->id, ['nota' => $nueva])
            ->assertStatus(200);

        $this->assertEquals($nueva, DB::table('notas')->where('id', $nota->id)->value('nota'),
            'La nota no se guardó por no haber ningún ingreso, que no tiene nada que ver.');
    }
}
