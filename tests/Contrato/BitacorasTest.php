<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §88 — El rastro de lo que hace cada usuario, y qué pasa al borrarlo.
 *
 * `bitacoras` guarda `descripcion`, `affected_person_name` y los valores viejo y
 * nuevo de lo que se tocó: es con lo que un colegio contesta «¿quién cambió esta
 * nota?» y «¿quién intentó entrar en mi cuenta?». `BitacorasController` estaba en
 * 1 de 2 rutas comprobadas y **el seed no trae ni una fila**, así que estos tests
 * se fabrican las suyas.
 *
 * Lo que se vio ejecutando borrar y volver a listar —no leyendo ninguna de las
 * dos—: **el borrado marcaba la fila y el listado seguía enseñándola.** Un botón
 * de borrar cuyo efecto no se ve en el listado que lo contiene.
 *
 * ### Sobre qué población se cerró, que es lo que hay que escribir
 *
 * `bitacoras` la leen **siete consultas repartidas por cuatro ficheros**, y no
 * todas filtran igual. Medido con un grep de la tabla en todo `app/`, no leyendo
 * este controlador:
 *
 * | Quién lee | ¿esconde las borradas? |
 * |---|---|
 * | `BitacorasController::getIndex` | **ahora sí** — es lo que arregla esta serie |
 * | `ChangeAskedController` · intentos de login fallidos | sí |
 * | `HistorialCalc::intentos_fallidos_de_usuario` | sí |
 * | `HistorialesController` · las bitácoras de una sesión | sí |
 * | `ChangeAskedController` · `cant_cambios` de cada sesión | **no** |
 * | `HistorialCalc::historial_sesiones_de_usuario` · `cant_cambios` | **no** |
 * | `historiales/nota-detalle` · **quién cambió la nota** | **no** |
 *
 * O sea que esto se cierra sobre **una de las siete**, y las otras seis son de
 * otros lotes. Y la que no filtra es la que más importa: **borrar la bitácora no
 * borra el rastro de quién cambió una nota**, que es lo que salva la situación.
 * Si algún día alguien «uniformiza» esa consulta, la auditoría de notas se
 * apagaría con ella.
 */
class BitacorasTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** Una bitácora de otro usuario, que es el caso que importa. */
    private function bitacoraDe(int $userId): int
    {
        DB::table('bitacoras')->insert([
            'created_by' => $userId,
            'descripcion' => 'Cambió la nota de un alumno',
            'affected_person_name' => 'Fulanito de Tal',
            'affected_person_type' => 'Alumno',
            'affected_element_type' => 'Nota',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('bitacoras')->orderByDesc('id')->value('id');
    }

    private function otroUsuario(int $distintoDe): object
    {
        $fila = DB::selectOne('SELECT id, username FROM users
            WHERE id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$distintoDe]);

        $this->assertNotNull($fila, 'El seed no tiene un segundo usuario.');

        return $fila;
    }

    /** Sin id en la URL, el listado es el de quien pregunta. */
    public function test_el_listado_sin_id_es_el_de_uno_mismo(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $mia = $this->bitacoraDe((int) $yo->id);
        $ajena = $this->bitacoraDe((int) $this->otroUsuario((int) $yo->id)->id);

        $ids = array_column($this->withToken($this->tokenDe($yo->username))
            ->getJson('/api/bitacoras')->assertStatus(200)->json(), 'id');

        $this->assertContains($mia, $ids);
        $this->assertNotContains($ajena, $ids, 'El listado sin id no mezcla las de otro.');
    }

    /**
     * Con un id en la URL, el listado es el de ese usuario — **y ahora hay que poder**.
     *
     * **Este test decía lo contrario, y decirlo era su trabajo.** Fijaba que
     * `bitacoras/{user_id?}` iba con `auth.personal` y nada más, o sea que
     * cualquiera de los 51 profesores leía el rastro de cualquier usuario del
     * colegio —con los nombres de las personas afectadas dentro— y lo dejaba
     * escrito así: *«se mide y se fija; quién puede leer el rastro de quién es
     * decisión del colegio»*.
     *
     * **La decisión llegó** —la 3 de `18-auditoria.md`, cableada en el lote AUD-5—
     * y es: lo propio siempre, lo de otro sólo con `can_view_auditoria`. Así que el
     * caso se invierte en vez de borrarse: las dos mitades en el mismo sitio, para
     * que se vea que lo que cambió fue la respuesta y no la pregunta.
     */
    public function test_el_rastro_de_otro_ya_no_lo_lee_cualquiera(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = $this->otroUsuario((int) $yo->id);
        $ajena = $this->bitacoraDe((int) $otro->id);
        $token = $this->tokenDe($yo->username);

        // Sin el permiso: 403, donde hasta el 25 ago 2026 había un 200 con nombres.
        $this->withToken($token)->getJson('/api/bitacoras/'.$otro->id)->assertStatus(403);

        // Con el permiso: lo de siempre, incluido el nombre de la persona afectada
        // — que es lo que hace que esto importe, y por eso se sigue comprobando.
        $this->darPermisoDeAuditoria((int) $yo->id);

        $filas = $this->withToken($token)
            ->getJson('/api/bitacoras/'.$otro->id)->assertStatus(200)->json();

        $this->assertContains($ajena, array_column($filas, 'id'));
        $this->assertSame('Fulanito de Tal', $filas[0]['affected_person_name'],
            'Sale el nombre de la persona afectada, que es lo que hace que esto importe.');
    }

    /** Y una familia no llega a ninguna de las dos. */
    public function test_una_familia_no_toca_el_rastro(): void
    {
        $yo = $this->usuarioDeTipo('Usuario');
        $ajena = $this->bitacoraDe((int) $yo->id);

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->getJson('/api/bitacoras/'.$yo->id)->assertStatus(403);
            $this->withToken($token)->deleteJson('/api/bitacoras/destroy/'.$ajena)->assertStatus(403);
        }

        $this->assertNull(DB::table('bitacoras')->where('id', $ajena)->value('deleted_at'));
    }

    /**
     * §88 — Borrar una bitácora ahora la saca del listado, y deja firma.
     *
     * Las dos mitades fallaban por separado: la fila quedaba marcada y **seguía
     * saliendo** en el mismo listado desde el que se borra, y `deleted_by` se
     * quedaba en null teniendo el usuario ya resuelto dos líneas arriba. Cada
     * mitad tiene su test.
     */
    public function test_borrar_una_bitacora_la_saca_del_listado(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($yo->username);

        // **Sobre la PROPIA, y eso lo cambió AUD-5.** Este caso usaba la de otro
        // usuario y su listado ajeno, que desde el 25 ago 2026 contesta 403 sin
        // `can_view_auditoria`. Lo que mide no es quién puede leer —eso tiene sus
        // dos casos aparte— sino **que borrar la saque del listado**, y eso se ve
        // igual de bien en el de uno mismo. Sembrarle el permiso aquí habría
        // metido en este test una condición que no es la suya.
        $mia = $this->bitacoraDe((int) $yo->id);

        $antes = count($this->withToken($token)->getJson('/api/bitacoras')->json());

        $this->withToken($token)->deleteJson('/api/bitacoras/destroy/'.$mia)
            ->assertStatus(200)->assertSee('Bitácora eliminada');

        $this->assertSame($antes - 1,
            count($this->withToken($token)->getJson('/api/bitacoras')->json()),
            'El listado desde el que se borra tiene que dejar de enseñarla.');

        $fila = DB::table('bitacoras')->where('id', $mia)->first();
        $this->assertNotNull($fila, 'El borrado es blando: la fila se queda.');
        $this->assertNotNull($fila->deleted_at);
    }

    /**
     * Y la otra mitad, que fallaba por su cuenta: **quién borró**.
     *
     * `deleted_by` se quedaba en null teniendo el usuario ya resuelto dos líneas
     * arriba. En un registro de auditoría es lo peor que puede faltar: **borrar el
     * rastro no dejaba rastro.** Va en su propio test para que, al caer, diga cuál
     * de las dos mitades se rompió.
     */
    public function test_borrar_una_bitacora_deja_escrito_quien_la_borro(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $ajena = $this->bitacoraDe((int) $this->otroUsuario((int) $yo->id)->id);

        $this->withToken($this->tokenDe($yo->username))
            ->deleteJson('/api/bitacoras/destroy/'.$ajena)->assertStatus(200);

        $this->assertSame((int) $yo->id,
            (int) DB::table('bitacoras')->where('id', $ajena)->value('deleted_by'),
            'Quien borra un rastro queda escrito, o el rastro no vale nada.');
    }

    /**
     * Lo que NO cambia al borrar: el rastro de quién cambió una nota.
     *
     * `historiales/nota-detalle` —lo que contesta «¿quién puso esta nota?»— lee
     * `bitacoras` **sin mirar `deleted_at`**, así que borrarla no la quita de ahí. Es lo que evita que `bitacoras/destroy` sea un borrador de
     * auditoría, y es una asimetría **a favor**: queda escrita para que nadie la
     * «uniformice» sin saber lo que apaga.
     */
    public function test_borrar_la_bitacora_no_borra_quien_cambio_la_nota(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($yo->username);

        // `historiales/nota-detalle` pide `can_view_auditoria` desde AUD-5: la
        // pregunta es por una NOTA y contesta quién la cambió, así que no tiene
        // mitad «lo tuyo» que dejar abierta. Lo que este caso mide sigue siendo
        // otra cosa —que borrar la bitácora NO borre el rastro de la nota—, así
        // que se le da el permiso y se mide lo de siempre.
        $this->darPermisoDeAuditoria((int) $yo->id);

        $nota = DB::table('notas')->whereNull('deleted_at')->orderBy('id')->first();
        $this->assertNotNull($nota, 'El seed no tiene notas.');

        DB::table('bitacoras')->insert([
            'created_by' => $yo->id,
            'affected_element_type' => 'Nota',
            'affected_element_id' => $nota->id,
            'affected_element_old_value_int' => 30,
            'affected_element_new_value_int' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = (int) DB::table('bitacoras')->orderByDesc('id')->value('id');

        $enElHistorial = fn () => array_column(
            $this->withToken($token)->putJson('/api/historiales/nota-detalle', ['nota_id' => $nota->id])->json('cambios') ?? [],
            'bit_id');

        $this->assertContains($id, $enElHistorial());

        $this->withToken($token)->deleteJson('/api/bitacoras/destroy/'.$id)->assertStatus(200);

        $this->assertContains($id, $enElHistorial(),
            'Borrar la bitácora NO la quita del rastro de la nota — y es lo que hay que conservar.');
    }

    /**
     * Un id que no existe: 200 «Bitácora eliminada» igual.
     *
     * `deleteDestroy` es un `UPDATE ... WHERE id=?` suelto: sin fila que casar no
     * hace nada y contesta lo mismo. Es la familia de `respuestas-que-mienten.py`
     * por un camino que la herramienta no ve —no hay nada que «frene» la
     * escritura, es que el `WHERE` no casa—. **Se mide y se anota**: cambiarlo a
     * 404 es visible en dieciséis colegios y hoy nadie sabe qué manda el front.
     */
    public function test_borrar_una_bitacora_que_no_existe_contesta_que_la_borro(): void
    {
        $token = $this->tokenDelPersonal();
        $inexistente = ((int) DB::table('bitacoras')->max('id')) + 1000;

        $this->withToken($token)->deleteJson('/api/bitacoras/destroy/'.$inexistente)
            ->assertStatus(200)->assertSee('Bitácora eliminada');
    }
}
