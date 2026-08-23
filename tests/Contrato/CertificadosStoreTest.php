<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §103 — El alta de un membrete de certificado, la que faltaba de las seis.
 *
 * `ConfigCertificadosTest` cubría el índice, la edición, el año y el borrado.
 * `certificados/store` era la única sin comprobar, y de ahí salieron dos cosas
 * que solo se ven llamándola **junto a su hermana `certificados/update`**:
 *
 * 1. **Escribe una fila entera de nulls con el cuerpo vacío** y contesta 201.
 * 2. **Crear y editar esperan un nombre de clave distinto para la misma imagen.**
 */
class CertificadosStoreTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    private function unaImagen(): int
    {
        $id = (int) DB::table('images')->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->assertNotSame(0, $id, 'El seed no tiene imágenes.');

        return $id;
    }

    /** El camino bueno: 201 con el modelo dentro. */
    public function test_se_crea_un_membrete(): void
    {
        $token = $this->tokenDelPersonal();
        $imagen = $this->unaImagen();

        $r = $this->withToken($token)->postJson('/api/certificados/store', [
            'nombre' => 'Membrete de rectoría',
            'encabezado_img_id' => ['id' => $imagen],
            'encabezado_width' => 600,
            'encabezado_height' => 120,
            'piepagina_solo_ultima_pagina' => 1,
        ]);

        // 201 y no 200: es un modelo Eloquent recién creado, que es lo que hace que
        // Laravel ponga el 201. Igual que `ciudades/guardar-ciudad` (§85) y al revés
        // que `ordinales/store` (§87), que relee la fila con `DB::select` y sale 200.
        $r->assertStatus(201);

        $fila = DB::table('config_certificados')->orderByDesc('id')->first();
        $this->assertSame('Membrete de rectoría', $fila->nombre);
        $this->assertSame($imagen, (int) $fila->encabezado_img_id);
        $this->assertSame(1, (int) $fila->piepagina_solo_ultima_pagina);
        $this->assertNotNull($fila->created_by, 'El alta se firma.');
    }

    /**
     * §103.1 — Con el cuerpo vacío **escribe la fila** y contesta 201.
     *
     * `config_certificados` no tiene ninguna columna `NOT NULL`, así que el
     * `INSERT` con todo en null pasa. Es exactamente lo que le pasaba a
     * `contratos`, el único de los nueve catálogos de la [§78](../../docs/migracion/05-codigo-muerto-y-roto.md)
     * que escribía basura — y el porqué es el mismo: **lo que impide que los demás
     * escriban no es el código, es el esquema.**
     *
     * > Lo que esto añade a la §78 no es otro ejemplo: es que **aquella se cerró
     * > sobre nueve rutas de catálogo y `certificados/store` no era una de ellas**.
     * > La serie está agotada para su población, no para el patrón. Es la misma
     * > lección que la papelera (§76) y que los boletines de este mismo reparto.
     *
     * Lo que deja detrás es un membrete sin nombre en el desplegable de membretes,
     * que es de dónde se elige el papel de un certificado. **Se mide y se anota**:
     * ponerle una validación es visible en dieciséis colegios y hoy nadie ha medido
     * qué manda el front al crear.
     */
    public function test_el_cuerpo_vacio_deja_un_membrete_sin_nombre(): void
    {
        $token = $this->tokenDelPersonal();
        $antes = DB::table('config_certificados')->count();

        $r = $this->withToken($token)->postJson('/api/certificados/store', []);
        $r->assertStatus(201);

        $this->assertSame($antes + 1, DB::table('config_certificados')->count(),
            'Escribe. Ninguna columna de `config_certificados` es NOT NULL.');

        $fila = DB::table('config_certificados')->orderByDesc('id')->first();
        $this->assertNull($fila->nombre);
        $this->assertNull($fila->encabezado_img_id);

        // Los dos únicos que no salen en null son los que llevan defecto escrito a
        // mano en el controlador. La diferencia entre unos y otros no es de
        // criterio: es que a esos dos alguien les puso el segundo argumento.
        $this->assertSame(0, (int) $fila->encabezado_solo_primera_pagina);
        $this->assertSame(0, (int) $fila->piepagina_solo_ultima_pagina);

        // Y sale en el desplegable de donde se elige el papel de un certificado.
        $membretes = $this->withToken($token)->getJson('/api/certificados')->assertStatus(200)->json();
        $this->assertContains((int) $fila->id,
            array_map('intval', array_column($membretes['certificados'] ?? $membretes, 'id')),
            'El membrete vacío no se queda escondido: se elige como cualquier otro.');
    }

    /**
     * §103.2 — Crear y editar piden **claves distintas** para la misma imagen.
     *
     * `postStore` lee `encabezado_img_id` y `piepagina_img_id`; `putUpdate` lee
     * `encabezado_img` y `piepagina_img`. Los dos escriben en la misma columna.
     *
     * Y las consecuencias no son simétricas, que es lo que lo hace caro: en el
     * alta, la clave que no toca **no pone imagen**; en la edición, la clave que no
     * toca **la borra**, porque `putUpdate` tiene el `else { = null }` que el alta
     * no tiene. O sea que un cliente que mande el mismo objeto a las dos rutas
     * **crea el membrete con imagen y se la quita al primer guardado**.
     *
     * `ConfigCertificadosTest::test_editar_sin_imagen_la_borra_y_es_a_proposito`
     * ya fijaba la segunda mitad. Lo que faltaba era la primera, y con ella el
     * porqué: «sin imagen» puede ser «con la clave que usa la otra ruta».
     */
    public function test_crear_y_editar_no_llaman_igual_a_la_misma_imagen(): void
    {
        $token = $this->tokenDelPersonal();
        $imagen = $this->unaImagen();

        // Con la clave de la EDICIÓN, el alta no guarda imagen.
        $this->withToken($token)->postJson('/api/certificados/store',
            ['nombre' => 'Con la clave de editar', 'encabezado_img' => ['id' => $imagen]])
            ->assertStatus(201);

        $creado = DB::table('config_certificados')->orderByDesc('id')->first();
        $this->assertNull($creado->encabezado_img_id,
            '`store` no lee `encabezado_img`: lee `encabezado_img_id`.');

        // Con la clave del ALTA, la edición la borra.
        DB::table('config_certificados')->where('id', $creado->id)->update(['encabezado_img_id' => $imagen]);

        $this->withToken($token)->putJson('/api/certificados/update',
            ['id' => $creado->id, 'nombre' => 'Con la clave de crear', 'encabezado_img_id' => ['id' => $imagen]])
            ->assertStatus(200);

        $this->assertNull(DB::table('config_certificados')->where('id', $creado->id)->value('encabezado_img_id'),
            '`update` no lee `encabezado_img_id`, y además tiene el `else` que la pone en null.');

        // Y cada una con la suya sí funciona, que es lo que hace que no se note.
        $this->withToken($token)->putJson('/api/certificados/update',
            ['id' => $creado->id, 'nombre' => 'Con la suya', 'encabezado_img' => ['id' => $imagen]])
            ->assertStatus(200);

        $this->assertSame($imagen,
            (int) DB::table('config_certificados')->where('id', $creado->id)->value('encabezado_img_id'));
    }

    /**
     * §103.3 — Y si la imagen llega como id suelto en vez de como objeto: **500**.
     *
     * `Request::input('encabezado_img_id')['id']` sobre un entero es
     * «Trying to access array offset on int». La forma que espera la ruta —un
     * objeto con `id` dentro— no está escrita en ninguna parte y no la valida
     * nadie; el `if` de arriba solo comprueba que el valor no sea vacío.
     *
     * Se mide y se anota: no escribe, y el arreglo (aceptar las dos formas) es
     * decisión de contrato, no de código.
     */
    public function test_la_imagen_como_id_suelto_revienta(): void
    {
        $token = $this->tokenDelPersonal();
        $antes = DB::table('config_certificados')->count();

        $r = $this->withToken($token)->postJson('/api/certificados/store',
            ['nombre' => 'Con el id suelto', 'encabezado_img_id' => $this->unaImagen()]);

        $r->assertStatus(500);
        $this->assertSame('Trying to access array offset on int', $r->json('message'));

        $this->assertSame($antes, DB::table('config_certificados')->count(),
            'Revienta antes del `save()`: no deja fila.');
    }

    /** Y una familia no crea membretes. */
    public function test_una_familia_no_crea_membretes(): void
    {
        $antes = DB::table('config_certificados')->count();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->postJson('/api/certificados/store', ['nombre' => 'X'])->assertStatus(403);
        }

        $this->assertSame($antes, DB::table('config_certificados')->count());
    }
}
