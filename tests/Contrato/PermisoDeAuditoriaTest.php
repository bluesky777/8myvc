<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;

/**
 * Quién puede ver el rastro de quién — las seis rutas viejas de la auditoría.
 *
 * Es la **decisión 3** de `docs/migracion/18-auditoria.md`, y son tres cosas a la
 * vez: un permiso por rol, sembrado sólo a rectoría y coordinación, y **lo propio
 * se ve siempre sin permiso**. Lote AUD-5, detalle en `noche-2026-08-25/aud-5.md`.
 *
 * **Lo que había antes, que es lo que hace que esto no sea cosmético:** las seis
 * iban con `auth.personal` y **nada más**. Cualquiera del personal leía la bitácora
 * de un compañero —o la de su rector— poniendo su número en la URL, y
 * `historiales/de-usuario` cogía el `user_id` **del cuerpo** y devolvía sus
 * sesiones y **sus intentos de login fallidos** sin mirar de quién era: `$user` se
 * resolvía y no se usaba.
 *
 * **Cada test se fabrica su permiso y su rol dentro de su transacción**, y no es
 * comodidad: `database/dumps/test-seed.sql` hace `TRUNCATE TABLE permissions`,
 * `permission_role`, `roles` y `role_user` antes de insertar, y las migraciones
 * corren **antes** del seed en `tools/construir-bd-test.sh`. O sea que **lo que
 * siembra la migración no sobrevive a construir la base**, y un test que se apoyara
 * en ello estaría comprobando el seed y no el código. Es la misma nota que ya dejó
 * escrita `SecretarioTest`, y aquí muerde igual.
 *
 * **Las dos mitades en cada ruta**, que es lo que pide la ficha del lote: quién
 * entra y quién no. Un test que sólo comprueba el 403 no distingue «la guarda
 * funciona» de «la ruta está rota para todos».
 */
class PermisoDeAuditoriaTest extends CasoDeContrato
{
    /** Otro usuario del personal, distinto del que se le pase. El «tercero» de todos los casos. */
    private function otroDelPersonal(int $distintoDe): object
    {
        $fila = DB::selectOne('SELECT u.* FROM users u
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.id <> ? ORDER BY u.id LIMIT 1', [$distintoDe]);

        $this->assertNotNull($fila, 'El seed necesita al menos dos usuarios del personal para esta prueba.');

        return $fila;
    }

    /** Un ingreso de esa persona, para las rutas que preguntan por `historial_id`. */
    private function ingresoDe(int $userId): int
    {
        return (int) DB::table('historiales')->insertGetId([
            'user_id' => $userId,
            'tipo' => 'login',
            'ip' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  GET bitacoras/{user_id?}
    // ─────────────────────────────────────────────────────────────────────

    public function test_su_propia_bitacora_se_ve_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($yo->username);

        // Sin id: la forma que usa la pantalla para «lo mío».
        $this->withToken($token)->getJson('/api/bitacoras')->assertStatus(200);

        // Y con SU id puesto a mano, que tiene que dar lo mismo.
        $this->withToken($token)->getJson('/api/bitacoras/'.$yo->id)->assertStatus(200);
    }

    public function test_la_bitacora_de_otro_no_se_ve_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = $this->otroDelPersonal($yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->getJson('/api/bitacoras/'.$otro->id)
            ->assertStatus(403);
    }

    public function test_la_bitacora_de_otro_se_ve_con_el_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = $this->otroDelPersonal($yo->id);

        $this->darPermisoDeAuditoria((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->getJson('/api/bitacoras/'.$otro->id)
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PUT historiales/de-usuario — el IDOR
    // ─────────────────────────────────────────────────────────────────────

    public function test_sus_propias_sesiones_se_ven_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/de-usuario', ['user_id' => $yo->id])
            ->assertStatus(200);
    }

    public function test_las_sesiones_de_otro_no_se_ven_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = $this->otroDelPersonal($yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/de-usuario', ['user_id' => $otro->id])
            ->assertStatus(403);
    }

    public function test_las_sesiones_de_otro_se_ven_con_el_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = $this->otroDelPersonal($yo->id);

        $this->darPermisoDeAuditoria((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/de-usuario', ['user_id' => $otro->id])
            ->assertStatus(200);
    }

    /**
     * **El cuerpo sin `user_id` no es «lo mío»: es «otro».**
     *
     * Si el `null` cayera del lado de lo propio, bastaría con **no mandar el
     * campo** para saltarse la comprobación entera — el agujero con otra forma. Va
     * su caso porque es la clase de fallo que no se ve leyendo el método.
     */
    public function test_un_cuerpo_sin_user_id_no_se_cuela_como_propio(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/de-usuario', [])
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PUT historiales/sesion
    // ─────────────────────────────────────────────────────────────────────

    public function test_su_propio_ingreso_se_ve_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $ingreso = $this->ingresoDe((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/sesion', ['historial_id' => $ingreso])
            ->assertStatus(200);
    }

    public function test_el_ingreso_de_otro_no_se_ve_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = $this->otroDelPersonal($yo->id);
        $ingreso = $this->ingresoDe((int) $otro->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/sesion', ['historial_id' => $ingreso])
            ->assertStatus(403);
    }

    public function test_el_ingreso_de_otro_se_ve_con_el_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = $this->otroDelPersonal($yo->id);
        $ingreso = $this->ingresoDe((int) $otro->id);

        $this->darPermisoDeAuditoria((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/sesion', ['historial_id' => $ingreso])
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PUT historiales/nota-detalle y nota-final-detalle
    //  Aquí NO hay mitad «lo tuyo»: se pregunta por una nota, no por una persona.
    // ─────────────────────────────────────────────────────────────────────

    public function test_quien_cambio_una_nota_no_se_ve_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $nota = DB::selectOne('SELECT id FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/nota-detalle', ['nota_id' => $nota->id])
            ->assertStatus(403);
    }

    public function test_quien_cambio_una_nota_se_ve_con_el_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $nota = DB::selectOne('SELECT id FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->darPermisoDeAuditoria((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/nota-detalle', ['nota_id' => $nota->id])
            ->assertStatus(200);
    }

    public function test_quien_cambio_una_definitiva_no_se_ve_sin_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $nf = DB::selectOne('SELECT id FROM notas_finales ORDER BY id LIMIT 1');

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/nota-final-detalle', ['nf_id' => $nf->id])
            ->assertStatus(403);
    }

    public function test_quien_cambio_una_definitiva_se_ve_con_el_permiso(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $nf = DB::selectOne('SELECT id FROM notas_finales ORDER BY id LIMIT 1');

        $this->darPermisoDeAuditoria((int) $yo->id);

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/nota-final-detalle', ['nf_id' => $nf->id])
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  El superusuario, y la migración
    // ─────────────────────────────────────────────────────────────────────

    /**
     * El superusuario entra sin que nadie le siembre nada.
     *
     * Va su caso porque es lo que evita que el despliegue deje a los dieciséis
     * colegios sin nadie que pueda mirar: si el permiso no llegara a sembrarse,
     * los diez `is_superuser` siguen entrando.
     */
    public function test_el_superusuario_ve_la_de_otro_sin_permiso_sembrado(): void
    {
        $jefe = DB::selectOne('SELECT u.* FROM users u
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($jefe, 'El seed no tiene ningún superusuario activo.');

        $otro = $this->otroDelPersonal((int) $jefe->id);

        $this->withToken($this->tokenDe($jefe->username))
            ->getJson('/api/bitacoras/'.$otro->id)
            ->assertStatus(200);
    }

    /**
     * **La migración, ejecutada de verdad** — y no por gusto.
     *
     * Lo que siembra no sobrevive a `construir-bd-test.sh` (ver la cabecera), así
     * que sin este caso **el reparto que decide la decisión 3 no lo ejecuta
     * ninguna suite**: el fichero se cargaría al construir la base y su efecto se
     * borraría acto seguido, y nadie notaría que reparte mal. Es el §5.bis del
     * briefing —«si tu entregable no lo ejecuta ninguna suite, ése es el primer
     * hallazgo de tu lote»— aplicado a una migración.
     *
     * Corre dentro de la transacción del test, sobre los roles que el seed sí trae.
     */
    public function test_la_migracion_siembra_rector_y_coordinacion_academica_y_nadie_mas(): void
    {
        DB::table('permission_role')->whereIn('permission_id', function ($q) {
            $q->select('id')->from('permissions')->where('name', Autoriza::PERMISO_AUDITORIA);
        })->delete();
        DB::table('permissions')->where('name', Autoriza::PERMISO_AUDITORIA)->delete();

        require_once base_path('database/migrations/2026_08_25_200000_create_permiso_can_view_auditoria.php');
        (new \CreatePermisoCanViewAuditoria)->up();

        $conElPermiso = DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->join('roles as r', 'r.id', '=', 'pr.role_id')
            ->where('p.name', Autoriza::PERMISO_AUDITORIA)
            ->orderBy('r.name')
            ->pluck('r.name')->all();

        $this->assertSame(['Coord académico', 'Rector'], $conElPermiso,
            "La decisión 3 dice «rector y coordinación», y en `roles` hay DOS coordinaciones.\n"
            .'`Coord disciplinario` se deja fuera a propósito: quién lleva la disciplina no es '
            .'obviamente quién puede ver quién cambió una nota, y eso lo decide el colegio.');
    }

    /** Correrla dos veces no duplica nada: dieciséis bases con vidas separadas. */
    public function test_la_migracion_se_puede_correr_dos_veces(): void
    {
        require_once base_path('database/migrations/2026_08_25_200000_create_permiso_can_view_auditoria.php');

        (new \CreatePermisoCanViewAuditoria)->up();
        (new \CreatePermisoCanViewAuditoria)->up();

        $filas = DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('p.name', Autoriza::PERMISO_AUDITORIA)
            ->count();

        $this->assertSame(2, $filas, 'La segunda pasada duplicó el reparto.');
    }
}
