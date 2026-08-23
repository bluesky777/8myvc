<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §120 — Lo que se pisa fuera de la ficha del alumno: el examen y dos catálogos
 * pequeños.
 *
 * Cuatro métodos de la misma forma —resolver una fila que existe y asignarle
 * columnas con `Request::input('x')` sin defecto— en sitios que no son de ningún
 * lote. **El tamaño de la fila no dice nada sobre su peso**: la de dos columnas
 * de aquí abajo deja una opción de examen sin texto, y la de siete deja la
 * pregunta valiendo cero puntos.
 *
 * Ninguno se arregla. Los cuatro tienen la misma pregunta detrás —*¿quién los
 * llama, y manda la fila entera?*— y la respuesta no está en el backend.
 */
class ColumnasQuePisaElExamenTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($super, 'El seed no tiene superusuario.');

        return $this->tokenDe($super->username);
    }

    /**
     * Una pregunta de examen, creada por la API. **El seed no tiene ninguna**
     * —`ws_actividades` está vacía—, así que esto la fabrica entera.
     */
    private function unaPreguntaPuntuada(string $token): int
    {
        $asignatura = DB::selectOne('SELECT asi.id FROM asignaturas asi
            INNER JOIN grupos g ON g.id = asi.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            WHERE asi.deleted_at IS NULL ORDER BY asi.id LIMIT 1');
        $this->assertNotNull($asignatura, 'El seed necesita una asignatura del año actual.');

        $actividad = $this->withToken($token)->postJson('/api/actividades/crear',
            ['asignatura_id' => $asignatura->id])->assertStatus(201)->json('id');

        $pregunta = (int) $this->withToken($token)->postJson('/api/preguntas/crear',
            ['actividad_id' => $actividad, 'tipo_pregunta' => 'Selección'])
            ->assertStatus(201)->json('id');

        DB::table('ws_preguntas')->where('id', $pregunta)->update([
            'enunciado' => '¿Cuál es la capital de Colombia?',
            'ayuda' => 'Piensa en el altiplano',
            'puntos' => 10, 'duracion' => 90, 'aleatorias' => 1,
            'texto_arriba' => 'Lee con calma', 'texto_abajo' => 'Revisa antes de enviar',
        ]);

        return $pregunta;
    }

    /**
     * §120.1 — Guardar una pregunta sin mandar `puntos` **la deja valiendo cero**.
     *
     * Siete columnas asignadas seguidas sin defecto. La que importa es `puntos`:
     * es `NOT NULL DEFAULT 0` en el esquema, así que el null se convierte en **0**
     * en silencio —`config/database.php` lleva `'strict' => false`— en vez de
     * fallar. **El `NOT NULL` no frena un UPDATE**; solo frena un alta.
     *
     * Un profesor que corrija la redacción de una pregunta ya puntuada, desde un
     * cliente que mande solo el enunciado, deja esa pregunta sin valor en el
     * examen. Y no hay nada en la respuesta que lo diga: 200 con la fila ya
     * vaciada dentro.
     */
    public function test_guardar_una_pregunta_sin_puntos_la_deja_en_cero(): void
    {
        $token = $this->tokenDeSuperusuario();
        $pregunta = $this->unaPreguntaPuntuada($token);

        $r = $this->withToken($token)->putJson('/api/preguntas/guardar',
            ['id' => $pregunta, 'enunciado' => '¿Cuál es la capital del país?']);

        $r->assertStatus(200);

        $fila = DB::table('ws_preguntas')->where('id', $pregunta)->first();

        $this->assertSame('¿Cuál es la capital del país?', $fila->enunciado, 'Lo que se manda, se guarda.');
        $this->assertSame(0, (int) $fila->puntos, 'La pregunta se queda valiendo cero puntos.');
        $this->assertNull($fila->duracion, 'Y sin tiempo.');
        $this->assertNull($fila->ayuda);
        $this->assertNull($fila->aleatorias);
        $this->assertNull($fila->texto_arriba);
        $this->assertNull($fila->texto_abajo);

        // Lo que NO se pisa, que es lo que separa esta ruta de un vaciado total:
        // `tipo_pregunta` y `orden` no están en la lista de las siete.
        $this->assertNotNull($fila->tipo_pregunta);
    }

    /**
     * §120.2 — Y guardar una opción sin mandar su texto **la deja en blanco**.
     *
     * Dos columnas, y es la fila más pequeña de la lista. Reordenar las opciones
     * de una pregunta —mandar `id` y `orden`— borra el texto de la opción, que es
     * lo que el alumno lee para elegirla. `is_correct` no está en la lista, así
     * que la opción **sigue siendo la correcta y ya no dice nada**.
     */
    public function test_guardar_una_opcion_sin_su_texto_la_deja_en_blanco(): void
    {
        $token = $this->tokenDeSuperusuario();
        $pregunta = $this->unaPreguntaPuntuada($token);

        $opcion = (int) $this->withToken($token)->putJson('/api/opciones/add-opcion',
            ['pregunta_id' => $pregunta, 'definicion' => 'Bogotá', 'orden' => 2, 'is_correct' => 1])
            ->assertStatus(201)->json('id');

        $this->withToken($token)->putJson('/api/opciones/guardar',
            ['id' => $opcion, 'orden' => 3])->assertStatus(200);

        $fila = DB::table('ws_opciones')->where('id', $opcion)->first();

        $this->assertSame(3, (int) $fila->orden, 'Lo que se manda, se guarda.');
        $this->assertNull($fila->definicion, 'Y el texto que el alumno lee se fue.');
        $this->assertSame(1, (int) $fila->is_correct,
            'Sigue siendo la respuesta correcta, y ya no dice nada.');
    }

    /**
     * §120.3 — La candidatura de una votación se queda **sin nombre**, y el
     * `NOT NULL` no lo impide.
     *
     * `vt_aspiraciones.aspiracion` y `.abrev` son las dos `NOT NULL`, y aun así el
     * UPDATE con null pasa: MySQL las convierte en cadena vacía. Es la
     * demostración más limpia de que **el esquema solo protege el alta**.
     *
     * Y su hermana `aspiraciones/store` lo enseña por el otro lado: escribe **solo
     * `votacion_id`**, así que crea la candidatura con el nombre ya en blanco.
     */
    public function test_una_candidatura_se_queda_sin_nombre(): void
    {
        $token = $this->tokenDeSuperusuario();
        $votacion = DB::table('vt_votaciones')->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->assertNotNull($votacion, 'El seed no tiene votaciones.');

        $creada = $this->withToken($token)->postJson('/api/aspiraciones/store',
            ['votacion_id' => $votacion]);
        $creada->assertStatus(201);

        $id = (int) $creada->json('id');

        // El alta ya la deja sin nombre: `postStore` no escribe ni `aspiracion` ni
        // `abrev`, y las dos son NOT NULL sin defecto.
        $this->assertSame('', (string) DB::table('vt_aspiraciones')->where('id', $id)->value('aspiracion'));

        DB::table('vt_aspiraciones')->where('id', $id)->update(['aspiracion' => 'Personero', 'abrev' => 'PER']);

        $this->withToken($token)->putJson('/api/aspiraciones/update',
            ['id' => $id, 'abrev' => 'PRS'])->assertStatus(200);

        $fila = DB::table('vt_aspiraciones')->where('id', $id)->first();
        $this->assertSame('PRS', $fila->abrev);
        $this->assertSame('', (string) $fila->aspiracion,
            'Cambiar la abreviatura borró el nombre de la candidatura, y la columna es NOT NULL.');
    }

    /**
     * §120.4 — Y los países: **el alta pide `pais_new`**, no `pais`, y no escribe
     * la abreviatura. Editarlos y borrarlos **no tiene ruta**.
     *
     * Tres cosas de un controlador de cuatro métodos:
     *
     * - `postStore` lee `pais_new`. Mandar `pais` —el nombre de la columna, y el
     *   que usa `update`— da **500** por el `NOT NULL`. Es la misma asimetría de
     *   nombres que `certificados` (§103), y aquí el esquema sí frena.
     * - `postStore` **no escribe `abrev`**, y `abrev` es lo que devuelve
     *   `ciudades/paisdeciudad` (§85). Un país creado por la API se queda sin
     *   abreviatura.
     * - `update()` y `destroy()` **existen y no están enrutados**: no hay forma de
     *   arreglarlo ni de borrarlo. Comprobado contra `route:list`.
     */
    public function test_los_paises_solo_se_pueden_crear_y_a_medias(): void
    {
        $token = $this->tokenDeSuperusuario();
        $antes = DB::table('paises')->count();

        // El nombre de la columna NO es el nombre del campo del cuerpo.
        $this->withToken($token)->postJson('/api/paises/store', ['pais' => 'CHILE'])
            ->assertStatus(500);
        $this->assertSame($antes, DB::table('paises')->count(), 'El NOT NULL sí frena el alta.');

        $this->withToken($token)->postJson('/api/paises/store', [])->assertStatus(500);
        $this->assertSame($antes, DB::table('paises')->count());

        // Con el nombre bueno: 200 y devuelve el catálogo entero, no el país.
        $r = $this->withToken($token)->postJson('/api/paises/store', ['pais_new' => 'PERÚ']);
        $r->assertStatus(200);
        $this->assertSame($antes + 1, DB::table('paises')->count());

        $creado = DB::table('paises')->orderByDesc('id')->first();
        $this->assertSame('PERÚ', $creado->pais);
        $this->assertNull($creado->abrev,
            '`postStore` no escribe `abrev`, y no hay ruta para ponérsela después.');
        $this->assertNull($creado->created_at, 'Ni fechas: el INSERT es crudo y no las pone.');
    }

    /** Y una familia no toca ninguna de las cuatro. */
    public function test_una_familia_no_toca_el_examen_ni_los_catalogos(): void
    {
        $token = $this->tokenDeSuperusuario();
        $pregunta = $this->unaPreguntaPuntuada($token);
        $antes = DB::table('ws_preguntas')->where('id', $pregunta)->first();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $suyo = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($suyo)->putJson('/api/preguntas/guardar',
                ['id' => $pregunta, 'enunciado' => 'X'])->assertStatus(403);
            $this->withToken($suyo)->putJson('/api/opciones/guardar',
                ['id' => 1, 'orden' => 1])->assertStatus(403);
            $this->withToken($suyo)->putJson('/api/aspiraciones/update',
                ['id' => 1, 'abrev' => 'X'])->assertStatus(403);
            $this->withToken($suyo)->postJson('/api/paises/store',
                ['pais_new' => 'X'])->assertStatus(403);
        }

        $this->assertEquals($antes, DB::table('ws_preguntas')->where('id', $pregunta)->first());
    }
}
