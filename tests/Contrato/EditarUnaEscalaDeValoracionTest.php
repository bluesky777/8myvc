<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §122 — La séptima de la §81, la que ningún detector podía ver.
 *
 * La [§81](../../docs/migracion/05-codigo-muerto-y-roto.md) cerró seis rutas de
 * editar catálogo que vaciaban la fila y contestaban 200. El lote D barrió
 * después esa misma operación por todo `app/` y dio 28 métodos. **Ésta no salió
 * en ninguna de las dos listas**, y las dos la tenían delante.
 *
 * ## Por qué no salió
 *
 * El detector de D busca un método que **resuelva una fila que ya existe**
 * (`find`, `findOrFail`, `first`, `onlyTrashed`) y le asigne dos o más columnas
 * con `Request::input(...)` sin defecto. `EscalasDeValoracionController::putUpdate`
 * no hace ninguna de las dos cosas:
 *
 *   - comprueba que la fila existe con un `SELECT` dentro de un helper privado,
 *     `exigirQueLaEscalaExista()`, y
 *   - escribe con un `DB::update` crudo de nueve columnas leídas como
 *     `$request->porc_inicial`, `$request->desempenio`…
 *
 * O sea que **no es un falso negativo de la clasificación: es que el método no
 * llegó a ser candidato.** La población de partida no era `app/`, era la parte de
 * `app/` que usa Eloquent — y en este repo hay 990 consultas crudas. El número
 * bueno es «28 de lo que ese patrón alcanza», no «28 de `app/`».
 *
 * Es la misma trampa que la §53 dejó escrita —*el detector también se queda ciego
 * ante un nombre nuevo*—, sólo que aquí el nombre nuevo no es un helper con otro
 * nombre: es **una escritura que no pasa por el ORM**.
 *
 * Y por mi parte, tampoco salió en la §81 por una razón que conviene escribir: al
 * medir las rutas de editar con el cuerpo vacío, ésta contestó **404** y se fue de
 * la lista con cara de estar bien. El 404 era correcto —el id va en el cuerpo, y
 * sin cuerpo no hay id que buscar— pero **contestaba a otra pregunta**. Es la
 * tercera vez esta noche que una respuesta correcta por el motivo equivocado tapa
 * lo que parece cubrir; las otras dos fueron `grados` con su 422 y `materias` con
 * su 500.
 *
 * ## Lo que pesa
 *
 * De las nueve columnas que ese `UPDATE` escribe, **seis son `NOT NULL`**:
 * `desempenio`, `valoracion`, `porc_inicial`, `porc_final`, `orden` y `perdido`.
 * Con `strict => false` eso no es un error, es `''` en las de texto y `0` en las
 * de número (§81). Y `porc_inicial=0, porc_final=0` es **la banda colapsada** en
 * la tabla que decide cómo se pinta el desempeño en todos los boletines del año.
 *
 * ## El arreglo, y por qué no es `CamposQueVinieron` aquí
 *
 * El lote D midió el discriminador entre las dos herramientas:
 * `CamposQueVinieron` hace falta cuando el controlador tiene un `Request::merge()`
 * o un `sanarInput*` **antes** de leer, porque a esa altura `has()` ya no
 * distingue lo que mandó el cliente. `EscalasDeValoracionController` **no tiene
 * ninguno de los dos** (comprobado: cero coincidencias en el fichero), así que
 * basta el defecto de `Request::input()`.
 *
 * Y el defecto sale de la fila que ya está en la base, que además no cuesta una
 * consulta: `exigirQueLaEscalaExista()` ya hacía ese `SELECT` — sólo pedía `id`.
 * Ahora devuelve la fila y el `UPDATE` la usa de respaldo columna a columna.
 */
class EditarUnaEscalaDeValoracionTest extends CasoDeContrato
{
    /**
     * Mandar sólo el id no vacía la escala.
     *
     * El cuerpo es `{id}` a secas y no vacío del todo **a propósito**: con el
     * cuerpo vacío esta ruta contesta 404 porque no hay id que buscar, y ese 404
     * es lo que la sacó de la §81. La pregunta de verdad empieza justo después
     * del id.
     */
    public function test_mandar_solo_el_id_no_vacia_la_escala(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $columnas = 'desempenio, valoracion, porc_inicial, porc_final, descripcion, orden, perdido, '
            .'icono_infantil, icono_adolescente';

        $escala = DB::selectOne("SELECT id, {$columnas} FROM escalas_de_valoracion
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1");

        $this->assertNotNull($escala, 'El seed no tiene ninguna escala de valoración.');

        $antes = (array) $escala;

        $this->withToken($token)->json('PUT', '/api/escalas/update', ['id' => $escala->id])
            ->assertStatus(200)->assertSee('Guardado');

        $despues = (array) DB::selectOne("SELECT id, {$columnas} FROM escalas_de_valoracion
            WHERE id = ?", [$escala->id]);

        $this->assertSame($antes, $despues,
            'Mandar sólo el id vació la escala de valoración. Las seis columnas `NOT NULL` de esta '
            .'tabla no dan error con `strict => false`: se quedan en `\'\'` y en `0`, y '
            .'`porc_inicial=0, porc_final=0` es la banda colapsada en lo que decide cómo se pinta el '
            .'desempeño en todos los boletines del año. Es la §81 en una ruta que ningún detector '
            .'podía ver, porque escribe con SQL crudo. Ver §122.');
    }

    /**
     * Y editar de verdad sigue escribiendo las nueve columnas.
     *
     * La otra mitad: el arreglo mete un respaldo detrás de cada `input()`, y un
     * respaldo mal puesto convierte «guardar» en «no guardar nada» sin que nada
     * falle — la pantalla de escalas seguiría contestando `Guardado`. Se comprueba
     * **el viaje de ida y vuelta**, releyendo la fila.
     */
    public function test_editar_una_escala_entera_sigue_escribiendo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $escala = DB::selectOne('SELECT id FROM escalas_de_valoracion
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $nuevo = [
            'id' => $escala->id,
            'desempenio' => 'EXCELENTE',
            'valoracion' => 'E',
            'porc_inicial' => 95,
            'porc_final' => 100,
            'descripcion' => 'Descripción nueva',
            'orden' => 9,
            'perdido' => 0,
            'icono_infantil' => 'infantil.png',
            'icono_adolescente' => 'adolescente.png',
        ];

        $this->withToken($token)->json('PUT', '/api/escalas/update', $nuevo)->assertStatus(200);

        $fila = (array) DB::selectOne('SELECT desempenio, valoracion, porc_inicial, porc_final,
            descripcion, orden, perdido, icono_infantil, icono_adolescente
            FROM escalas_de_valoracion WHERE id = ?', [$escala->id]);

        unset($nuevo['id']);

        $this->assertSame($nuevo, $fila,
            'Editar una escala entera dejó de escribir alguna columna. El respaldo que evita que se '
            .'vacíe no puede evitar también que se guarde.');
    }

    /**
     * Y un `0` mandado a propósito sigue siendo un `0`.
     *
     * Es el caso que rompe el respaldo escrito de la forma cómoda. Un
     * `$request->porc_inicial ?: $actual->porc_inicial` —o cualquier respaldo que
     * mire si el valor es *falsy*— convertiría un `0` legítimo en el valor viejo,
     * y `porc_inicial=0` es el borde inferior de la escala más baja del colegio:
     * existe en la tabla y es correcto.
     *
     * Lo mismo con `perdido`, que es un `tinyint(1)` cuyo valor normal **es** 0.
     * El respaldo tiene que mirar si la clave vino, no si el valor es cierto.
     */
    public function test_un_cero_mandado_a_proposito_no_se_reemplaza_por_el_valor_viejo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        // Van en DOS filas distintas, y no es rebuscado: en las cuatro escalas
        // de cada año la única con `perdido = 1` es BAJO, que es justamente la
        // que empieza en `porc_inicial = 0`. Pedir las dos condiciones en una
        // sola fila no encuentra ninguna, y la primera versión de este test se
        // saltó entero por eso — dejando sin medir el requisito más fino de los
        // tres. **Un test que se salta se lee igual que uno que pasa** en la
        // línea de resumen.
        $conValor = DB::selectOne('SELECT id, porc_inicial FROM escalas_de_valoracion
            WHERE deleted_at IS NULL AND porc_inicial <> 0 ORDER BY id LIMIT 1');
        $perdida = DB::selectOne('SELECT id, perdido FROM escalas_de_valoracion
            WHERE deleted_at IS NULL AND perdido <> 0 ORDER BY id LIMIT 1');

        $this->assertNotNull($conValor, 'El seed no tiene ninguna escala con `porc_inicial` distinto de 0.');
        $this->assertNotNull($perdida, 'El seed no tiene ninguna escala con `perdido = 1`.');

        $this->withToken($token)->json('PUT', '/api/escalas/update',
            ['id' => $conValor->id, 'porc_inicial' => 0])->assertStatus(200);
        $this->olvidarControladores();

        $this->withToken($token)->json('PUT', '/api/escalas/update',
            ['id' => $perdida->id, 'perdido' => 0])->assertStatus(200);

        $this->assertSame(
            [0, 0],
            [
                (int) DB::selectOne('SELECT porc_inicial FROM escalas_de_valoracion WHERE id = ?',
                    [$conValor->id])->porc_inicial,
                (int) DB::selectOne('SELECT perdido FROM escalas_de_valoracion WHERE id = ?',
                    [$perdida->id])->perdido,
            ],
            'Un `0` mandado a propósito se reemplazó por el valor viejo. El respaldo está mirando si '
            .'el valor es cierto en vez de si la clave vino, y los dos ceros de esta tabla son '
            .'legítimos: `porc_inicial = 0` es el borde inferior de la escala más baja del colegio y '
            .'`perdido = 0` es el valor normal de las tres escalas que se aprueban.');
    }
}
