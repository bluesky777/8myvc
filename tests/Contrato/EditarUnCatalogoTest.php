<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §81 — Editar un catálogo del colegio con el cuerpo vacío se lo lleva por
 * delante, y contesta 200.
 *
 * `CrearUnCatalogoTest` (§78) midió la mitad de crear y salió con una conclusión
 * que se leyó como tranquilizadora:
 *
 * > **Lo que impide que ocho de los nueve escriban basura no es el código: es el
 * > esquema.**
 *
 * Esta es la otra mitad de las mismas parejas, y **la conclusión no se arrastra**.
 * De seis rutas de editar, las seis vacían la fila y contestan 200.
 *
 * ## Por qué el mismo esquema salva a crear y no a editar
 *
 * No es que los controladores de editar sean peores: son los mismos, línea por
 * línea, leyendo `Request::input(...)` sin una sola validación. Lo que cambia es
 * qué hace MySQL. Con `strict => false` —`config/database.php`, las dos
 * conexiones— y sobre la misma columna `areas.nombre`, medido contra la base:
 *
 *     UPDATE areas SET nombre=NULL WHERE id=1    ->  Warning 1048, la fila queda con ''
 *     INSERT INTO areas (nombre) VALUES (NULL)   ->  ERROR   1048, rechazado
 *
 * **Mismo código 1048, distinta severidad.** El `NOT NULL` al que la §78 le
 * atribuyó el mérito de frenar los INSERT no frena ni uno solo de los UPDATE: los
 * convierte en `''` sin decir nada. Y como no lanza excepción, el `try/catch` que
 * tres de estos controladores tienen alrededor —el que sí traduce el fallo de
 * crear a 422— aquí no ve nada que traducir.
 *
 * ## Lo que costó verlo: dos de los seis parecían sanos
 *
 * `grados` contestaba 422 y `materias` 500, así que la primera medida dijo
 * «cuatro de seis». Los dos códigos venían del mismo sitio y ninguno del campo
 * que importa: `Request::input('nivel')['id']` y `Request::input('area')['id']`
 * sobre `null`. Con el cuerpo mínimo que pasa por delante de ese offset —`{"nivel":
 * {"id":1}}`, `{"area_id":3}`— los dos dan 200 y vacían igual que los otros
 * cuatro.
 *
 * > **Una respuesta correcta por el motivo equivocado tapa justo lo que parece
 * > estar cubriendo.** Un 422 se lee como «se validó».
 *
 * ## Qué se arregló, y por qué ése y no otro
 *
 * El arreglo es el de la [§68](../../docs/migracion/05-codigo-muerto-y-roto.md),
 * que ya está en este repo con su clase: **un campo que no se manda no es un
 * campo que no cambia, es un campo que se pisa.** Se asigna sólo lo que vino
 * (`App\Support\CamposQueVinieron`).
 *
 * Se eligió frente a la otra opción —exigir la columna obligatoria y devolver
 * 422, que es lo que ya hace crear— porque **no le cambia el código de estado a
 * ningún cliente**: los cuatro fronts mandan la fila entera desde su formulario,
 * así que para ellos el comportamiento es idéntico. Un 422 nuevo sí habría que
 * ir a mirarlo pantalla por pantalla en dieciséis colegios.
 *
 * Lo que este test **no** hace es unificar las respuestas: siguen siendo 200 con
 * el cuerpo vacío (areas, tiposdocumento), 200 con la fila (frases, niveles,
 * materias) y 200 con la cadena `Cambiado` (grados). Fijarlas es lo que hace que
 * unificarlas sea una decisión y no un efecto.
 */
class EditarUnCatalogoTest extends CasoDeContrato
{
    /**
     * Ninguna de las seis vacía la fila con el cuerpo vacío.
     *
     * Lo que se afirma de verdad es **la fila de después**, no el código: el 200
     * ya lo daban antes: es lo que hay detrás lo que había cambiado.
     *
     * El cuerpo de cada caso no es vacío del todo en dos de los seis, y eso es
     * deliberado: con el cuerpo literalmente vacío `grados` y `materias`
     * contestan por el offset y no llegan a la asignación, que es justo lo que
     * los escondió. Se les manda el mínimo que llega hasta el `save()`.
     */
    public function test_ningun_catalogo_se_vacia_al_editarlo_con_el_cuerpo_vacio(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        // [tabla, ruta, columnas que se comprueban, cuerpo, estado medido el 22 ago 2026]
        $catalogos = [
            'areas' => ['areas', 'areas/update/{id}', 'nombre, alias, orden', [], 200],
            'frases' => ['frases', 'frases/update/{id}', 'frase, tipo_frase', [], 200],
            'niveles_educativos' => ['niveles_educativos', 'niveles_educativos/update/{id}',
                'nombre, abrev, orden', [], 200],
            'tipos_documentos' => ['tipos_documentos', 'tiposdocumento/{id}', 'tipo, abrev', [], 200],

            // Los dos que el offset escondía. `{nivel}` y `{area}` se rellenan
            // abajo con el valor que ya tiene la fila, para que el cuerpo mínimo
            // no cambie nada por sí mismo.
            'grados' => ['grados', 'grados/update/{id}', 'nombre, abrev, orden, nivel_educativo_id',
                ['nivel' => ['id' => '{nivel}']], 200],
            'materias' => ['materias', 'materias/update/{id}', 'materia, alias, area_id',
                ['area_id' => '{area}'], 200],
        ];

        // Se acumula y se afirma UNA vez al final, y no es estilo: con un
        // `assertSame` dentro del bucle el test para en el primero que falle, y
        // al revertir el arreglo para comprobarlo sólo demostraba que `areas`
        // había regresado. Seis rutas tapadas y un test que cae no dice cuántas
        // de las seis estaban cubiertas — regla 4 de la noche.
        $pisados = [];
        $codigos = [];

        foreach ($catalogos as $nombre => [$tabla, $ruta, $columnas, $cuerpo, $esperado]) {
            $fila = DB::selectOne("SELECT id, {$columnas} FROM {$tabla} ORDER BY id LIMIT 1");
            $this->assertNotNull($fila, "El seed no tiene ninguna fila en `{$tabla}`.");

            $cuerpo = $this->rellenar($cuerpo, $fila);
            $antes = $this->sinId($fila);

            $r = $this->withToken($token)->json('PUT',
                '/api/'.str_replace('{id}', (string) $fila->id, $ruta), $cuerpo);

            if ($r->status() !== $esperado) {
                $codigos[] = "{$nombre}: esperado {$esperado}, llegó {$r->status()}";
            }

            $despues = $this->sinId(DB::selectOne("SELECT id, {$columnas} FROM {$tabla} WHERE id = ?", [$fila->id]));

            if ($antes !== $despues) {
                $pisados[] = "{$nombre} ({$ruta})\n     antes:   ".json_encode($antes, JSON_UNESCAPED_UNICODE)
                    ."\n     después: ".json_encode($despues, JSON_UNESCAPED_UNICODE);
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $codigos,
            'Alguna cambió de código de estado. Si es por una validación nueva, hay que mirar qué hace '
            ."el front con el código nuevo; si no, es una regresión.\n  ".implode("\n  ", $codigos));

        $this->assertSame([], $pisados,
            count($pisados).' de '.count($catalogos).' rutas de editar pisaron columnas que el cliente no '
            ."mandó. El catálogo se queda vacío y la respuesta sigue siendo 200: es la §81.\n  "
            .implode("\n  ", $pisados));
    }

    /**
     * Y editar de verdad sigue funcionando, con la forma de respuesta de siempre.
     *
     * La otra mitad, y no es de adorno: el arreglo mete un `if` delante de cada
     * asignación, y un `if` mal puesto apaga el botón «Guardar» de la pantalla de
     * catálogos en dieciséis colegios sin que nada falle. Se comprueba **el viaje
     * de ida y vuelta** —se escribe y se relee de la base— y no el 200.
     */
    public function test_editar_mandando_los_campos_sigue_escribiendo(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $area = DB::selectOne('SELECT id FROM areas ORDER BY id LIMIT 1');
        $this->withToken($token)->json('PUT', '/api/areas/update/'.$area->id,
            ['nombre' => 'ÁREA CAMBIADA', 'alias' => 'AC', 'orden' => 99])->assertStatus(200);

        $this->assertSame(
            ['nombre' => 'ÁREA CAMBIADA', 'alias' => 'AC', 'orden' => 99],
            (array) DB::selectOne('SELECT nombre, alias, orden FROM areas WHERE id = ?', [$area->id])
        );
        $this->olvidarControladores();

        // `grados` por su lado, que es el que más cambió: el offset de `nivel`
        // se sustituyó por `Request::input('nivel.id')` y hay que ver que sigue
        // aceptando las DOS formas con las que el front nombra el nivel.
        $grado = DB::selectOne('SELECT id FROM grados WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $otro = DB::selectOne('SELECT id FROM niveles_educativos WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');

        $this->withToken($token)->json('PUT', '/api/grados/update/'.$grado->id,
            ['nombre' => 'GRADO X', 'abrev' => 'GX', 'orden' => 7, 'nivel' => ['id' => $otro->id]])
            ->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame(
            ['nombre' => 'GRADO X', 'abrev' => 'GX', 'orden' => 7, 'nivel_educativo_id' => (int) $otro->id],
            (array) DB::selectOne('SELECT nombre, abrev, orden, nivel_educativo_id FROM grados WHERE id = ?', [$grado->id])
        );
        $this->olvidarControladores();

        // La segunda forma: `nivel_educativo_id` plano, que es la que usa el
        // `merge` de tres líneas y la que se habría perdido al tocar el offset.
        $primero = DB::selectOne('SELECT id FROM niveles_educativos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->json('PUT', '/api/grados/update/'.$grado->id,
            ['nombre' => 'GRADO Y', 'nivel_educativo_id' => $primero->id])->assertStatus(200);

        $this->assertSame((int) $primero->id,
            (int) DB::selectOne('SELECT nivel_educativo_id FROM grados WHERE id = ?', [$grado->id])->nivel_educativo_id,
            'La forma plana `nivel_educativo_id` dejó de escribir: es la que usa el `merge`.');
    }

    /**
     * `materias/update` con un id que no existe da 404 como sus nueve hermanas.
     *
     * Antes daba **500**, y no por el id: `Request::input('area')['id']` sobre
     * `null` reventaba **antes** del `findOrFail`, así que la ruta no llegaba
     * nunca a mirar si la materia existía. Es la §52 con otra cara — un
     * `findOrFail` que sí estaba puesto, y al que no se llegaba.
     */
    public function test_editar_una_materia_que_no_existe_da_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $inexistente = (int) DB::selectOne('SELECT IFNULL(MAX(id),0) + 1000 n FROM materias')->n;

        $this->withToken($token)->json('PUT', '/api/materias/update/'.$inexistente, [])
            ->assertStatus(404);
    }

    /** El cuerpo de la tabla trae marcas `{nivel}` y `{area}`: se rellenan con la fila real. */
    private function rellenar(array $cuerpo, object $fila): array
    {
        array_walk_recursive($cuerpo, function (&$v) use ($fila) {
            if ($v === '{nivel}') {
                $v = $fila->nivel_educativo_id;
            }
            if ($v === '{area}') {
                $v = $fila->area_id;
            }
        });

        return $cuerpo;
    }

    /** @return array<string, mixed> */
    private function sinId(object $fila): array
    {
        $columnas = (array) $fila;
        unset($columnas['id']);

        return $columnas;
    }
}
