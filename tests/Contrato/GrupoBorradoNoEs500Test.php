<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Pedir un grupo que no existe —o que está en la papelera— contesta **404**, no
 * una traza de PHP.
 *
 * `Grupo::datos()` terminaba en `DB::select(...)[0]` sin comprobar nada, y su
 * consulta filtra `g.deleted_at is null`. O sea que **un grupo borrado y un
 * grupo inventado daban lo mismo: «Undefined array key 0», 500**. Y por ahí pasan
 * **diecisiete** llamantes — los tres controladores de boletines, planillas,
 * puestos, certificados, PIAR, `editnota`…
 *
 * Lo destapó `myvc-front-12` verificando en Chrome el 24 ago 2026:
 * `boletines/detailed-notas-group/1` devolvía 500. **Es el caso feo y no el
 * bonito**, que es la razón de que llevara años ahí: la pantalla no revienta ni
 * pinta veneno, sólo no trae nada, así que nadie miró la pestaña de red. En la
 * copia de producción el grupo 1 existe y está borrado **desde enero de 2018**.
 *
 * Se prueba con **las dos formas de no estar**, porque la consulta las confunde a
 * propósito y el arreglo tiene que cubrir las dos: un id que no existe, y un id
 * que existe con `deleted_at`. Y se prueba por **más de una ruta**, porque lo que
 * se arregló no es el boletín: es el método por el que pasan los diecisiete.
 */
class GrupoBorradoNoEs500Test extends CasoDeContrato
{
    /**
     * El grupo borrado **se monta aquí**, no se busca: el seed no trae ninguno en
     * la papelera y saltarse el test por eso dejaría sin cubrir justo la mitad
     * que se rompió en producción — donde el grupo 1 lleva borrado desde 2018.
     *
     * Es la regla del 09: si lo que falta es la fila, se monta en el test que la
     * necesita. El borrado es blando y la transacción del test lo deshace.
     */
    private function idBorrado(): int
    {
        $fila = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita al menos un grupo vivo.');

        DB::table('grupos')->where('id', $fila->id)->update(['deleted_at' => now()]);

        return (int) $fila->id;
    }

    private function idInventado(): int
    {
        return ((int) DB::selectOne('SELECT MAX(id) AS m FROM grupos')->m) + 1000;
    }

    public function test_el_boletin_de_un_grupo_borrado_es_404(): void
    {
        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        $this->withToken($token)
            ->putJson('/api/boletines/detailed-notas-group/'.$this->idBorrado(), ['periodo_a_calcular' => 1])
            ->assertStatus(404);
    }

    public function test_el_boletin_de_un_grupo_inventado_es_404(): void
    {
        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        $this->withToken($token)
            ->putJson('/api/boletines/detailed-notas-group/'.$this->idInventado(), ['periodo_a_calcular' => 1])
            ->assertStatus(404);
    }

    /**
     * Y por otra ruta distinta, para que quede claro que lo arreglado es
     * `Grupo::datos()` y no el boletín.
     */
    public function test_la_planilla_de_un_grupo_borrado_tambien_es_404(): void
    {
        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        $this->withToken($token)
            ->getJson('/api/planillas/show-grupo/'.$this->idBorrado())
            ->assertStatus(404);
    }
}
