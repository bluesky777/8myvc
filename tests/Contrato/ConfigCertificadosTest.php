<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * La configuración de los certificados de estudio.
 *
 * Seis rutas y la cobertura del 21 de agosto de 2026 decía **1 de 6**: el mayor
 * hueco de `routes/api/informes.php`, que es el fichero de lo que el colegio
 * imprime y firma.
 *
 * Lo que se configura aquí no es un dato de un alumno: es **el membrete**. El
 * `config_certificado` dice qué imagen va de encabezado y de pie de página, con
 * sus márgenes, y `years.encabezado_certificado` lleva el texto de cabecera. Un
 * certificado de estudio es un documento que el colegio entrega firmado, así que
 * lo que se rompa aquí sale impreso.
 *
 * Las seis llevan `auth.personal`, o sea los 51 profesores.
 *
 * **Una asimetría que NO es un fallo, y conviene decirlo para que nadie la
 * «arregle»:** `postStore()` lee `encabezado_img_id` y `putUpdate()` lee
 * `encabezado_img`. Se fue a mirar el front y los dos formularios son distintos
 * de verdad —`configCertificados.html` liga el de crear a
 * `newcertif.encabezado_img_id` y el de editar a `currentCertif.encabezado_img`—,
 * así que la API espeja al cliente. Feo y correcto.
 */
class ConfigCertificadosTest extends CasoDeContrato
{
    private function personal(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene personal en el año {$grupo->year_id}.");

        return (object) [
            'user_id' => (int) $usuario->id,
            'year_id' => (int) $grupo->year_id,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    private function unCertificado(): int
    {
        return DB::table('config_certificados')->insertGetId([
            'nombre' => 'Membrete de pruebas',
            'encabezado_width' => 600,
            'encabezado_height' => 120,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** El índice entrega todos los membretes del colegio, que es lo que la pantalla pinta. */
    public function test_el_indice_trae_los_membretes(): void
    {
        $personal = $this->personal();
        $mio = $this->unCertificado();

        $lista = $this->withToken($personal->token)
            ->getJson('/api/certificados')
            ->assertStatus(200)
            ->json();

        $this->assertContains($mio, collect($lista)->pluck('id')->all());
    }

    /**
     * **Se puede apuntar un año a un membrete que no existe**, y responde
     * «Cambiado».
     *
     * `putActual()` coge `config_certificado_estudio_id` del cuerpo y lo escribe
     * en `years` sin comprobar que exista. No hay clave foránea que lo pare
     * —`years` no la lleva para esta columna—, así que la fila queda apuntando al
     * vacío.
     *
     * Y lo que hace que importe es cuándo se nota: **no se nota aquí**. Se nota
     * el día que alguien imprime un certificado, que es justo el día que no se
     * puede esperar. Ver 14-certificados.md §1.
     */
    public function test_se_apunta_el_ano_a_un_membrete_inexistente(): void
    {
        $personal = $this->personal();

        $inventado = ((int) DB::table('config_certificados')->max('id')) + 1000;
        $antes = DB::table('years')->where('id', $personal->year_id)->value('config_certificado_estudio_id');

        $this->withToken($personal->token)
            ->putJson('/api/certificados/actual', [
                'year_id' => $personal->year_id,
                'config_certificado_estudio_id' => $inventado,
            ])
            ->assertStatus(200);

        $this->assertSame(
            $inventado,
            (int) DB::table('years')->where('id', $personal->year_id)->value('config_certificado_estudio_id'),
            'El año quedó apuntando a un membrete que no existe.'
        );

        DB::table('years')->where('id', $personal->year_id)
            ->update(['config_certificado_estudio_id' => $antes]);
    }

    /**
     * Y se puede cambiar el membrete y el encabezado **de cualquier año**.
     *
     * `year_id` llega por el cuerpo y las dos rutas hacen `Year::find()` y
     * `save()`. Nadie mira si ese año es el del usuario. Con `auth.personal`
     * delante, cualquiera de los 51 profesores reescribe la cabecera de los
     * certificados de un año que no es el suyo — incluidos los años cerrados, de
     * los que se siguen pidiendo certificados de estudio viejos.
     */
    public function test_se_cambia_el_encabezado_de_otro_ano(): void
    {
        $personal = $this->personal();

        $otro = DB::selectOne('SELECT id, encabezado_certificado FROM years
            WHERE id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$personal->year_id]);

        $this->assertNotNull($otro, 'El seed no tiene un segundo año.');

        $this->withToken($personal->token)
            ->putJson('/api/certificados/encabezado', [
                'year_id' => $otro->id,
                'encabezado_certificado' => 'REESCRITO POR OTRO AÑO',
            ])
            ->assertStatus(200);

        $this->assertSame(
            'REESCRITO POR OTRO AÑO',
            DB::table('years')->where('id', $otro->id)->value('encabezado_certificado')
        );

        DB::table('years')->where('id', $otro->id)
            ->update(['encabezado_certificado' => $otro->encabezado_certificado]);
    }

    /**
     * Un id que no existe es 404 — **arreglado el 21 ago 2026**.
     *
     * Los cuatro métodos resolvían con `find()` y seguían escribiendo propiedades
     * sobre el `null` que devuelve, que en PHP 8 es fatal: **500 donde tocaba
     * 404**, cuatro veces en un controlador de seis métodos.
     *
     * Entró en el barrido de los `::find()` sin `OrFail` de todo el repo. El
     * argumento para hacerlo, que no es cosmético: un 500 no es una elección de
     * código sino un proceso que revienta, y **con `APP_DEBUG` encendido devuelve
     * la traza al cliente** — que es justo lo que la 01 tiene pendiente de
     * comprobar colegio a colegio. Ver 14-certificados.md §3.
     */
    #[DataProvider('rutasQueResuelvenPorId')]
    public function test_un_id_que_no_existe_es_404(string $metodo, string $ruta, string $clave): void
    {
        $personal = $this->personal();

        $inventado = ((int) DB::table('config_certificados')->max('id')) + 1000;

        $cuerpo = $clave === '' ? [] : [$clave => $inventado];
        $url = $clave === '' ? $ruta.'/'.$inventado : $ruta;

        $this->withToken($personal->token)
            ->json($metodo, '/api/'.$url, $cuerpo)
            ->assertStatus(404);
    }

    /** @return array<string, array{string, string, string}> */
    public static function rutasQueResuelvenPorId(): array
    {
        return [
            'update de un membrete que no existe' => ['PUT', 'certificados/update', 'id'],
            'destroy de un membrete que no existe' => ['DELETE', 'certificados/destroy', ''],
            'actual sobre un año que no existe' => ['PUT', 'certificados/actual', 'year_id'],
            'encabezado sobre un año que no existe' => ['PUT', 'certificados/encabezado', 'year_id'],
        ];
    }

    /**
     * Editar sin mandar la imagen la borra, y aquí **está escrito a propósito**.
     *
     * `putUpdate()` tiene el `else` puesto: si no viene `encabezado_img`, escribe
     * `null`. Es la forma de vaciar el membrete desde la pantalla, así que no es
     * el descuido de [13-actividades.md §1](13-actividades.md) —donde el `null`
     * llega por omisión y nadie lo quiso— sino una decisión.
     *
     * Se fija porque **las dos formas se parecen tanto que se confunden**: quien
     * venga a arreglar «los campos que se borran al guardar» tiene que saber que
     * en este método el borrado es la función, y que quitarlo dejaría el membrete
     * sin poderse vaciar.
     */
    public function test_editar_sin_imagen_la_borra_y_es_a_proposito(): void
    {
        $personal = $this->personal();

        $imagen = DB::selectOne('SELECT id FROM images WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($imagen, 'El seed no tiene imágenes.');

        $id = $this->unCertificado();
        DB::table('config_certificados')->where('id', $id)->update(['encabezado_img_id' => $imagen->id]);

        $this->withToken($personal->token)
            ->putJson('/api/certificados/update', ['id' => $id, 'nombre' => 'Sin membrete'])
            ->assertStatus(200);

        $this->assertNull(
            DB::table('config_certificados')->where('id', $id)->value('encabezado_img_id'),
            'La imagen de encabezado se vació, que es lo que hace el `else`.'
        );
    }

    /**
     * Y `created_by` se reescribe al editar, así que dice quién tocó el último.
     *
     * `putUpdate()` asigna `$certif->created_by = $user->user_id`. La columna se
     * llama «creado por» y guarda «editado por»: el rastro de quién creó el
     * membrete se pierde en la primera edición.
     *
     * Menor, pero se anota porque es la tercera columna de propiedad de esta
     * serie que no guarda lo que su nombre dice — con `ws_actividades.created_by`
     * guardando `persona_id` y `ws_preguntas.added_by` guardando `user_id`.
     */
    public function test_created_by_guarda_quien_edito_el_ultimo(): void
    {
        $personal = $this->personal();

        $id = $this->unCertificado();

        $this->assertSame(1, (int) DB::table('config_certificados')->where('id', $id)->value('created_by'));

        $this->withToken($personal->token)
            ->putJson('/api/certificados/update', ['id' => $id, 'nombre' => 'Editado'])
            ->assertStatus(200);

        $this->assertSame(
            $personal->user_id,
            (int) DB::table('config_certificados')->where('id', $id)->value('created_by'),
            'Quien editó se llevó el `created_by`.'
        );
    }
}
