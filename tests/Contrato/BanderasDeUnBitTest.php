<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las banderas `tinyint(1)` salen con el mismo tipo JSON las escriba quien las escriba.
 *
 * Siete columnas del esquema son `tinyint(1)` y el código las escribía con
 * booleanos de PHP —`$periodo->actual = false`—. Eloquent no vuelve a leer la
 * fila después de `save()`, así que **la respuesta de la llamada que crea la
 * fila lleva `false` y la de cualquier llamada posterior lleva `0`**: el mismo
 * campo, del mismo registro, con dos tipos distintos según por dónde se pida.
 *
 * Medido antes de decidir nada: con `PDO::ATTR_EMULATE_PREPARES` en false, que
 * es como está, un `tinyint(1)` vuelve de MySQL como `int`. O sea que `0` es lo
 * que reciben los cuatro clientes en el 99% de las peticiones y `false` es la
 * excepción — por eso el arreglo va hacia `0`, no hacia castear a booleano.
 *
 * El propio autor lo escribía de las dos formas en el mismo objeto: en
 * `postStore` conviven `actual = false` y `profes_pueden_editar_notas = 1`,
 * dos banderas idénticas, dos líneas seguidas.
 *
 * Esto se comprueba **por el viaje de ida y vuelta**, no mirando el código: se
 * crea, se vuelve a pedir, y se compara el tipo. Es la única forma de ver un
 * fallo que no cambia el 200 ni la fila guardada.
 */
class BanderasDeUnBitTest extends CasoDeContrato
{
    /**
     * Un año con periodos y el token de alguien de ese año, en ese orden.
     *
     * El año sale del usuario y no al revés, por la trampa que ya avisa
     * `tokenDelPersonalDe()`: el año del seed con periodos más nuevos no tiene
     * por qué tener personal, y sin personal no hay token con el que pedir.
     */
    private function yearYToken(): array
    {
        $usuario = DB::selectOne('SELECT u.username, p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($usuario, 'El seed no tiene ningún Usuario con periodo.');

        return [(int) $usuario->year_id, $this->tokenDe($usuario->username)];
    }

    public function test_el_periodo_recien_creado_trae_actual_del_mismo_tipo_que_al_releerlo(): void
    {
        [$year, $token] = $this->yearYToken();
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        $creado = $this->postJson("/api/periodos/store/{$year}", [
            'numero' => 9,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-03-31',
            'fecha_plazo' => '2026-04-05',
            // 201 y no 200: devolver un modelo recién creado desde el
            // controlador hace que Laravel ponga el código de creación.
        ], $cabeceras)->assertStatus(201)->json();

        $releido = collect($this->getJson("/api/periodos/show/{$year}", $cabeceras)
            ->assertStatus(200)->json())
            ->firstWhere('id', $creado['id']);

        $this->assertNotNull($releido, 'El periodo creado debe salir al releer el año.');

        $this->assertSame($releido['actual'], $creado['actual'],
            '`actual` no puede salir con un tipo al crear y con otro al releer.');
    }

    public function test_establecer_actual_devuelve_la_bandera_como_la_devuelve_una_lectura(): void
    {
        [$year, $token] = $this->yearYToken();
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        $periodos = $this->getJson("/api/periodos/show/{$year}", $cabeceras)
            ->assertStatus(200)->json();

        $noActual = collect($periodos)->firstWhere('actual', 0);
        $this->assertNotNull($noActual, 'El seed debe traer algún periodo no actual.');

        $cambiado = $this->putJson("/api/periodos/establecer-actual/{$noActual['id']}", [], $cabeceras)
            ->assertStatus(200)->json();

        $releido = collect($this->getJson("/api/periodos/show/{$year}", $cabeceras)
            ->assertStatus(200)->json())
            ->firstWhere('id', $noActual['id']);

        $this->assertSame($releido['actual'], $cambiado['actual'],
            '`actual` no puede salir con un tipo al cambiarlo y con otro al releerlo.');
    }
}
