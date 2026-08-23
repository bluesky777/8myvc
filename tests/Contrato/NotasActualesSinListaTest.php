<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT notas-actuales-alumnos/{grupo}` **sin `requested_alumnos`** devuelve el
 * grupo entero, no un 500.
 *
 * `putIndex` lee `Request::input('requested_alumnos', '')` y se lo pasa a
 * `detailedNotasGrupo`, que lo recorre con `foreach`. **En PHP 8 recorrer una
 * cadena lanza** —«foreach() argument must be of type array|object, string
 * given»— y en PHP 5 y 7 simplemente no hacía nada.
 *
 * Ahí está lo que hace este caso distinto de un fallo cualquiera: **el fallo era
 * benigno y alguien construyó encima.** La pantalla del front documenta que sin
 * la galleta este endpoint «responde con la lista de alumnos vacía» y decidió
 * **no compensarlo** apoyándose en eso. Dejó de ser cierto con el salto de
 * versión, y nadie relacionó las dos cosas — que es la forma en que una
 * actualización de plataforma rompe algo que nadie tocó.
 *
 * Lo destapó `myvc-front-12` el 24 ago 2026 barriendo 107 rutas en Chrome con el
 * log del backend delante.
 *
 * ## Y por qué el test manda el cuerpo vacío a propósito
 *
 * Porque **es la rama que nadie prueba**: los tests pasan el parámetro, y el
 * defecto sólo lo toma un cliente real que no lo manda. Es la misma forma que el
 * `[0]` de `Grupo::datos()` —el caso feo no lo pide nadie hasta que lo pide un
 * usuario— y por eso los dos aparecieron el mismo día y ninguno lo encontró una
 * suite.
 */
class NotasActualesSinListaTest extends CasoDeContrato
{
    public function test_sin_lista_de_alumnos_no_revienta(): void
    {
        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        $this->withToken($token)
            ->putJson('/api/notas-actuales-alumnos/'.$this->grupoConAlumnos()->id, [
                'periodo_a_calcular' => 1,
            ])
            ->assertStatus(200);
    }

    /**
     * Y devuelve **a alguien**, que es la mitad que el 200 no comprueba.
     *
     * Antes de PHP 8 esto contestaba 200 con la lista vacía, así que un test que
     * sólo mirara el código habría estado verde con el endpoint sin devolver
     * nada — durante años.
     */
    public function test_sin_lista_devuelve_el_grupo_entero(): void
    {
        $grupo = (int) $this->grupoConAlumnos()->id;
        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        $cuerpo = $this->withToken($token)
            ->putJson('/api/notas-actuales-alumnos/'.$grupo, ['periodo_a_calcular' => 1])
            ->assertStatus(200)
            ->json();

        $matriculados = (int) DB::selectOne('SELECT COUNT(*) AS n FROM matriculas
            WHERE grupo_id = ? AND deleted_at IS NULL AND estado IN ("MATR", "ASIS")', [$grupo])->n;

        $this->assertCount($matriculados, $cuerpo[2],
            'Sin lista tienen que salir todos los matriculados del grupo, y salieron '
            .count($cuerpo[2]).' de '.$matriculados.'.');
    }
}
