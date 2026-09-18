<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las dos rutas de `accesos-favoritos` — los favoritos del menú de cada persona
 * en `app2`.
 *
 * ## SON DOS RUTAS Y NO TRES, y este fichero es parte de por qué
 *
 * El plan autorizado eran tres. `orden` es una propiedad de la **lista** y no de
 * un renglón, así que el `PUT` manda la lista entera y con eso marcar, desmarcar,
 * reordenar y renombrar son **una sola escritura atómica**; un `DELETE` aparte no
 * haría nada que no haga mandar la lista sin ese renglón. Lo que eso significa
 * para las pruebas es que **casi todo lo que hay que cazar aquí es de la escritura
 * entera**, no de un verbo concreto.
 *
 * ## Qué existe esto para cazar
 *
 * **1. Que una lista rechazada escriba a medias.** Es el riesgo que trae elegir
 * «la lista entera»: si la comprobación viviera dentro del bucle en vez de antes,
 * una lista con el tercer renglón malo dejaría los dos primeros escritos y el
 * menú a medio cambiar — con un 422 delante diciendo que no se hizo nada. **La
 * respuesta y la tabla dirían cosas distintas**, que es la familia de fallo que
 * este repositorio persigue.
 *
 * **2. Que el `PUT` deje de quitar lo que falta.** Si se convirtiera en un upsert
 * sin la limpieza, desmarcar dejaría de funcionar **y no daría error**: se manda
 * la lista sin ese renglón, contesta 200, y el favorito sigue ahí. Es el único
 * camino que tiene la pantalla para quitar uno.
 *
 * **3. Que borre y reinserte en vez de actualizar.** Sería más corto y pasaría
 * todo lo demás. Lo que se pierde es `created_at` —«desde cuándo lo tengo
 * marcado»—, que es lo único que esta tabla sabe y **no se puede reconstruir**.
 *
 * **4. Que un texto largo entre cortado.** `ruta` y `etiqueta` son `varchar(255)`
 * y este docker no está en modo estricto: entraría recortado con un 200, y
 * **MariaDB 10.5 —los dieciséis— abortaría**. Y aquí corta de verdad: `ruta` lleva
 * los parámetros, así que una dirección larga cortada es un favorito que **apunta
 * a otro sitio**, no uno con el nombre feo.
 *
 * **5. Que `user_id` se lea del cuerpo.** Igual que en su hermana: es la razón por
 * la que esta familia no lleva guard de propiedad.
 *
 * Contrato en `docs/migracion/ESTADO-ACTUAL.md`, casilla del 18 sep 2026.
 */
class AccesosFavoritosTest extends CasoDeContrato
{
    /** El mismo tope que el controlador, escrito a mano: leerlo de allí haría que cambiarlo no pusiera nada rojo. */
    private const TOPE = 30;

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function lasDosRutas(): array
    {
        return [
            'leer los favoritos' => ['getJson', []],
            'guardar la lista' => ['putJson', ['favoritos' => [['ruta' => '/x', 'etiqueta' => 'X']]]],
        ];
    }

    #[Test]
    #[DataProvider('lasDosRutas')]
    public function test_un_alumno_no_toca_los_favoritos(string $verbo, array $cuerpo): void
    {
        $antes = $this->censo();

        $r = $this->llamar($verbo, $cuerpo, $this->tokenDe($this->usuarioDeTipo('Alumno')->username));

        $r->assertStatus(403);
        $this->assertSame($antes, $this->censo(),
            'Contestó 403 y escribió igual: el guard frena la respuesta pero no la escritura.');
    }

    #[Test]
    #[DataProvider('lasDosRutas')]
    public function test_un_acudiente_tampoco(string $verbo, array $cuerpo): void
    {
        $this->llamar($verbo, $cuerpo, $this->tokenDe($this->usuarioDeTipo('Acudiente')->username))
            ->assertStatus(403);
    }

    /**
     * **La lista que llega es la lista que queda**: reordenar, renombrar y quitar,
     * en una sola escritura.
     *
     * Es el caso central y prueba las tres operaciones a la vez a propósito: por
     * separado, cada una podría pasar con un upsert sin limpieza. Juntas, no.
     */
    #[Test]
    public function test_el_put_deja_la_lista_exactamente_como_llega(): void
    {
        $this->pedir('putJson', $this->lista([
            ['/lista-alumnos', 'Alumnos'],
            ['/materias', 'Materias'],
            ['/grupos', 'Grupos'],
        ]))->assertStatus(200);

        $r = $this->pedir('putJson', $this->lista([
            ['/grupos', 'Grupos'],
            ['/lista-alumnos', 'Estudiantes'],
        ]));

        $this->assertSame(
            [['/grupos', 'Grupos', 0], ['/lista-alumnos', 'Estudiantes', 1]],
            array_map(static fn ($f) => [$f['ruta'], $f['etiqueta'], $f['orden']], $r->json()),
            'La lista guardada no es la que se mandó.'
        );

        $this->assertSame(2, $this->censo(), 'No quitó el favorito que faltaba en la lista nueva.');
        $this->assertNull($this->favorito('/materias'), 'Desmarcar no funciona: el renglón que falta sigue ahí.');
    }

    /**
     * **Reordenar conserva `created_at`.**
     *
     * Un borrar-y-reinsertar pasaría todo lo demás y perdería esto en silencio.
     */
    #[Test]
    public function test_reordenar_no_pierde_desde_cuando_lo_tengo_marcado(): void
    {
        $this->pedir('putJson', $this->lista([['/uno', 'Uno'], ['/dos', 'Dos']]));

        $nacimiento = '2020-01-01 00:00:00';
        DB::update('UPDATE accesos_favoritos SET created_at = ?', [$nacimiento]);

        $this->pedir('putJson', $this->lista([['/dos', 'Dos'], ['/uno', 'Uno']]));

        $this->assertSame($nacimiento, $this->favorito('/uno')->created_at,
            'Borró y reinsertó: se pierde «desde cuándo lo tengo marcado», que no se puede reconstruir.');
        $this->assertSame(0, (int) $this->favorito('/dos')->orden, 'Y además no reordenó.');
    }

    /**
     * **Una lista mal formada no escribe NADA, ni siquiera los renglones buenos
     * que van delante del malo.**
     *
     * El renglón que rompe va el **tercero** a propósito: con la comprobación
     * dentro del bucle, los dos primeros ya estarían escritos cuando salta el 422.
     *
     * @return array<string, array{array<int, array<string, mixed>>}>
     */
    public static function listasQueNoValen(): array
    {
        return [
            'la misma ruta dos veces' => [[
                ['ruta' => '/a', 'etiqueta' => 'A'],
                ['ruta' => '/b', 'etiqueta' => 'B'],
                ['ruta' => '/a', 'etiqueta' => 'Otra vez A'],
            ]],
            'un renglón sin etiqueta' => [[
                ['ruta' => '/a', 'etiqueta' => 'A'],
                ['ruta' => '/b', 'etiqueta' => 'B'],
                ['ruta' => '/c'],
            ]],
            'un renglón sin ruta' => [[
                ['ruta' => '/a', 'etiqueta' => 'A'],
                ['ruta' => '/b', 'etiqueta' => 'B'],
                ['etiqueta' => 'C'],
            ]],
        ];
    }

    #[Test]
    #[DataProvider('listasQueNoValen')]
    public function test_una_lista_rechazada_no_escribe_ni_los_buenos(array $favoritos): void
    {
        $r = $this->pedir('putJson', ['favoritos' => $favoritos]);

        $r->assertStatus(422);
        $this->assertSame(0, $this->censo(),
            'Contestó 422 y escribió los renglones buenos igual: la respuesta y la tabla dicen cosas distintas.');
    }

    /**
     * **Y una lista rechazada tampoco pisa la que ya estaba.**
     *
     * El caso anterior parte de vacío, así que pasaría igual si la escritura fuera
     * «borra todo y vuelve a insertar» con el borrado antes de la comprobación.
     * Aquí hay algo que perder.
     */
    #[Test]
    public function test_una_lista_rechazada_deja_intacta_la_anterior(): void
    {
        $this->pedir('putJson', $this->lista([['/uno', 'Uno'], ['/dos', 'Dos']]));

        $this->pedir('putJson', ['favoritos' => [['ruta' => '/tres', 'etiqueta' => 'Tres'], ['ruta' => '/tres', 'etiqueta' => 'Otra']]])
            ->assertStatus(422);

        $this->assertSame(2, $this->censo(), 'La lista rechazada se llevó por delante la que ya estaba.');
        $this->assertNotNull($this->favorito('/uno'));
        $this->assertNotNull($this->favorito('/dos'));
    }

    /**
     * **Una dirección de más de 255 es 422 y no un favorito que apunta a otro sitio.**
     *
     * Aquí el corte silencioso no afea un nombre: `ruta` lleva los parámetros, así
     * que una dirección cortada **lleva a otra pantalla o a ninguna**.
     */
    #[Test]
    public function test_una_ruta_de_mas_de_255_es_422_y_no_se_guarda_cortada(): void
    {
        $larga = '/informes/'.str_repeat('z', 250);

        $this->pedir('putJson', $this->lista([[$larga, 'Larga']]))->assertStatus(422);

        $this->assertSame(0, $this->censo(), 'Contestó 422 y guardó la dirección cortada igual.');
    }

    #[Test]
    public function test_pasado_el_tope_es_422_y_no_escribe(): void
    {
        $muchos = [];

        for ($i = 0; $i <= self::TOPE; $i++) {
            $muchos[] = ['ruta' => "/r{$i}", 'etiqueta' => "E{$i}"];
        }

        $this->pedir('putJson', ['favoritos' => $muchos])->assertStatus(422);
        $this->assertSame(0, $this->censo());
    }

    /** **La lista vacía vacía**, que es como la pantalla quita el último. */
    #[Test]
    public function test_la_lista_vacia_deja_el_menu_sin_favoritos(): void
    {
        $this->pedir('putJson', $this->lista([['/uno', 'Uno']]));

        $this->pedir('putJson', ['favoritos' => []])->assertStatus(200);

        $this->assertSame(0, $this->censo(), 'No se puede quitar el último favorito.');
    }

    /** `favoritos` que no es una lista es 422 y no un menú vaciado por accidente. */
    #[Test]
    public function test_un_cuerpo_sin_lista_no_vacia_el_menu(): void
    {
        $this->pedir('putJson', $this->lista([['/uno', 'Uno']]));

        $this->pedir('putJson', ['favoritos' => 'todos'])->assertStatus(422);

        $this->assertSame(1, $this->censo(), 'Un cuerpo mal formado vació el menú.');
    }

    /** Lo mismo que en su hermana: `user_id` sale de la sesión, y por eso no hace falta guard de propiedad. */
    #[Test]
    public function test_no_se_puede_escribir_en_el_menu_de_otro(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = DB::selectOne('SELECT id FROM users WHERE id <> ? AND deleted_at IS NULL LIMIT 1', [$yo->id]);

        $this->pedir('putJson', $this->lista([['/uno', 'Uno']]) + ['user_id' => $otro->id], $this->tokenDe($yo->username));

        $this->assertSame((int) $yo->id, (int) $this->favorito('/uno')->user_id,
            'Escribió en el menú de otra persona.');
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /** @param array<int, array{0: string, 1: string}> $pares */
    private function lista(array $pares): array
    {
        return ['favoritos' => array_map(static fn ($p) => ['ruta' => $p[0], 'etiqueta' => $p[1]], $pares)];
    }

    private function censo(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS c FROM accesos_favoritos')->c;
    }

    private function favorito(string $ruta): ?object
    {
        return DB::selectOne('SELECT * FROM accesos_favoritos WHERE ruta = ?', [$ruta]);
    }

    /** Un token por test y no uno por petición: cada uno es un `login` de verdad y el limitador son 120 por minuto. */
    private ?string $miToken = null;

    private function pedir(string $verbo, array $cuerpo = [], ?string $token = null)
    {
        return $this->llamar($verbo, $cuerpo, $token ?? ($this->miToken ??= $this->tokenDelPersonalLlano()));
    }

    private function llamar(string $verbo, array $cuerpo, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        if ($verbo === 'getJson') {
            return $this->getJson('/api/accesos-favoritos', $cabeceras);
        }

        return $this->{$verbo}('/api/accesos-favoritos', $cuerpo, $cabeceras);
    }
}
