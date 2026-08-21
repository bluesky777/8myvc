<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El bucle de reordenar, que está copiado en cinco controladores.
 *
 * Sale de contar los `Modelo::find()` sin `OrFail`: de los diez sitios donde el
 * resultado se usa **en la línea siguiente sin comprobar**, seis son este mismo
 * bucle en `areas`, `materias`, `subunidades` y `unidades`. Con un id que no
 * existe, `find()` devuelve null y `->orden` revienta: **500 donde tocaba 404**.
 *
 * El de unidades se arregló en la §47 leyéndolo; los otros cinco salieron al
 * contarlos. Ver 05 §52.
 */
class ReordenarTest extends CasoDeContrato
{
    private function token(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** @return array<string, array{0:string,1:string,2:bool}> ruta, tabla y si anida el cuerpo */
    public static function rutasQueReordenan(): array
    {
        // `materias` **anida** el suyo bajo `partFrom`, y las otras tres lo mandan
        // plano. Se descubrió al escribir esto: con el cuerpo plano, materias daba
        // 500 por `$partFrom['sortHash']` sobre null, no por el `find()`. Es la
        // §23 —la misma clave leída de dos formas en sitios distintos— dentro de
        // un bucle que por lo demás está copiado y pegado.
        return [
            'áreas' => ['/api/areas/update-orden', 'areas', false],
            'materias' => ['/api/materias/update-orden', 'materias', true],
            'subunidades' => ['/api/subunidades/update-orden', 'subunidades', false],
            'unidades' => ['/api/unidades/update-orden', 'unidades', false],
        ];
    }

    private function cuerpo(array $sortHash, bool $anidado): array
    {
        return $anidado ? ['partFrom' => ['sortHash' => $sortHash]] : ['sortHash' => $sortHash];
    }

    #[DataProvider('rutasQueReordenan')]
    public function test_reordenar_algo_que_no_existe_es_404(string $ruta, string $tabla, bool $anidado): void
    {
        $maximo = (int) DB::table($tabla)->max('id');

        $this->withToken($this->token())
            ->putJson($ruta, $this->cuerpo([[(string) ($maximo + 1000) => 1]], $anidado))
            ->assertStatus(404);
    }

    /** Y el camino bueno sigue reordenando, que es lo que no se puede romper. */
    public function test_un_area_se_reordena(): void
    {
        $area = DB::selectOne('SELECT id FROM areas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        if ($area === null) {
            $this->markTestSkipped('El seed no tiene áreas.');
        }

        $this->withToken($this->token())
            ->putJson('/api/areas/update-orden', ['sortHash' => [[(string) $area->id => 6]]])
            ->assertStatus(200);

        $this->assertSame(6, (int) DB::table('areas')->where('id', $area->id)->value('orden'));
    }

    public function test_una_materia_se_reordena(): void
    {
        $materia = DB::selectOne('SELECT id FROM materias WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($this->token())
            ->putJson('/api/materias/update-orden', ['partFrom' => ['sortHash' => [[(string) $materia->id => 4]]]])
            ->assertStatus(200);

        $this->assertSame(4, (int) DB::table('materias')->where('id', $materia->id)->value('orden'));
    }
}
