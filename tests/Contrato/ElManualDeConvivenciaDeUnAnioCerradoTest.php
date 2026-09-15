<?php

namespace Tests\Contrato;

use App\Support\AnioCerrado;
use Illuminate\Support\Facades\DB;

/**
 * El cuarto catálogo con año — **el manual de convivencia**, y el que
 * `16-escribir-en-un-anio-pasado.md` dejó fuera en la primera pasada.
 *
 * Las otras tres —`frases`, `escalas_de_valoracion` y `contratos`— se cerraron el
 * 14 sep 2026 y viven en `EscrituraDeCatalogoDeOtroAnioTest`. **Ésta entró
 * después, el mismo día y por decisión aparte de Joseth**, y va en su propio
 * fichero porque su historia es distinta y conviene que se lea entera:
 *
 * ## Por qué había quedado fuera, y por qué entra igual
 *
 * El doc 16 lo señaló como **el único de los cuatro al que llega un profesor**: el
 * estado `panel.ordinales` de `myvc_front` pide `can_work_like_teacher` **o**
 * `can_work_like_admin`, y su pantalla tiene un **selector de año de verdad** que
 * escribe en el que se elija. Los otros tres viven en pantallas que sólo ve
 * `admin`. De ahí su aviso: *«si se cierra, el selector de año deja de servir para
 * nadie — aquí hay que tocar el front sí o sí»*.
 *
 * Joseth lo cerró igual, con eso delante: **un año cerrado es un año cerrado para
 * todo.** El aviso al front va con ello, y es la parte que no la hace este
 * fichero.
 *
 * ## Son CINCO escrituras y la familia parecía de cuatro
 *
 * `postStore`, `putUpdate`, `putGuardarValor`, `putDestroy` — y **`putGuardarValorConfig`**,
 * que escribe en `dis_configuraciones`, otra tabla con `year_id` y que es la
 * configuración del manual **de ese año**. Sin ella el cierre tendría un agujero
 * del tamaño de la propia pantalla: artículos bloqueados y la configuración que
 * los gobierna abierta de par en par.
 *
 * ## Y `postStore` es el único `store` de los cuatro catálogos que lleva candado
 *
 * Porque es el único que **toma el `year_id` del cuerpo**. `frases`, `escalas` y
 * `contratos` estampan `$user->year_id` al crear, así que no pueden sembrar fuera
 * de su año y su `store` se queda sin guard a propósito — razonado en
 * `EscalasDeValoracionController::postStore`. Aquí, crear un artículo del manual de
 * 2022 **es una llamada**, no un descuido de contexto.
 */
class ElManualDeConvivenciaDeUnAnioCerradoTest extends CasoDeContrato
{
    /**
     * Las cinco, para quien no es superusuario: **403 y nada escrito**.
     *
     * Se mira la fila de después y el conteo de la tabla, no el código: un 403 con
     * el `INSERT` ya hecho sería lo peor de los dos mundos y desde el status se ven
     * igual.
     */
    public function test_las_cinco_escrituras_del_manual_son_403_en_un_anio_cerrado(): void
    {
        $cerrado = $this->unAnioCerradoConManual();
        $token = $this->tokenDelPersonalLlanoDe($this->yearIdCorriente());

        $colados = [];

        foreach ($this->lasCincoEscrituras($cerrado) as $nombre => $e) {
            $antes = $this->huella($cerrado);

            $r = $this->withToken($token)->json($e['verbo'], $e['url'], $e['cuerpo']);

            if ($r->status() !== 403) {
                $colados[] = "{$nombre} -> ".$r->status().' y no 403';
            }

            if ($antes !== $this->huella($cerrado)) {
                $colados[] = "{$nombre} -> escribió pese al corte";
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $colados,
            "Alguna escritura del manual de convivencia alcanzó un año cerrado sin ser superusuario.\n  "
            .implode("\n  ", $colados));
    }

    /**
     * Y el superusuario sigue llegando a las cinco.
     *
     * Sin este caso, endurecer el criterio de «superusuario» a «nadie» dejaría el
     * fichero entero en verde: el de arriba sólo afirma que el personal llano no
     * puede. Es el mismo par que sujeta las otras tres.
     */
    public function test_un_superusuario_sigue_escribiendo_el_manual_de_un_anio_cerrado(): void
    {
        $cerrado = $this->unAnioCerradoConManual();

        $superusuario = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($superusuario, 'El seed no tiene ningún superusuario.');

        $token = $this->tokenDe($superusuario->username);

        $fallan = [];

        foreach ($this->lasCincoEscrituras($cerrado) as $nombre => $e) {
            $r = $this->withToken($token)->json($e['verbo'], $e['url'], $e['cuerpo']);

            if ($r->status() !== 200) {
                $fallan[] = "{$nombre} -> ".$r->status().' y no 200';
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $fallan,
            "Un superusuario dejó de poder corregir el manual de convivencia de un año cerrado.\n"
            ."Eso NO es lo que se decidió: lo que se cerró es QUIÉN, no QUÉ.\n  "
            .implode("\n  ", $fallan));
    }

    /**
     * **Y el año en curso se sigue escribiendo sin ser superusuario.**
     *
     * Es el caso que más importa de este fichero, porque es el que dice que no se
     * le ha roto la pantalla al profesor que la usa. El doc 16 avisaba de que
     * cerrar esto *«deja el selector de año sin servir para nadie»*; lo que aquí se
     * fija es que eso vale **sólo para los años cerrados** y que el año corriente
     * sigue exactamente como estaba.
     */
    public function test_el_anio_corriente_se_sigue_escribiendo_sin_ser_superusuario(): void
    {
        $yearId = $this->yearIdCorriente();

        $ordinal = DB::selectOne('SELECT id FROM dis_ordinales
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$yearId]);

        $this->assertNotNull($ordinal, "El seed no tiene ordinales del año corriente ({$yearId}).");

        $token = $this->tokenDelPersonalLlanoDe($yearId);

        $this->withToken($token)->json('PUT', '/api/ordinales/guardar-valor', [
            'ordinal_id' => $ordinal->id, 'propiedad' => 'pagina', 'valor' => 42,
        ])->assertStatus(200);

        $this->assertSame(42, (int) DB::selectOne('SELECT pagina FROM dis_ordinales WHERE id = ?',
            [$ordinal->id])->pagina, 'Contestó 200 y no escribió.');
    }

    /**
     * Las cinco, con su cuerpo, en un solo sitio.
     *
     * `postStore` es la única que **nombra el año**; las otras cuatro mandan sólo un
     * id y el año lo deriva el controlador de la fila — que es lo que hace que el
     * candado no se pueda abrir nombrando el año de al lado (§27).
     *
     * @return array<string, array<string, mixed>>
     */
    private function lasCincoEscrituras(object $cerrado): array
    {
        return [
            'ordinales/store' => ['verbo' => 'POST', 'url' => '/api/ordinales/store',
                'cuerpo' => ['year_id' => $cerrado->year_id, 'ordinal' => 99,
                    'tipo' => 'Grave', 'descripcion' => 'SEMBRADO EN UN AÑO CERRADO', 'pagina' => 1]],
            'ordinales/update' => ['verbo' => 'PUT', 'url' => '/api/ordinales/update',
                'cuerpo' => ['id' => $cerrado->ordinal_id, 'ordinal' => 98, 'tipo' => 'Leve',
                    'descripcion' => 'PISADO', 'pagina' => 7]],
            'ordinales/guardar-valor' => ['verbo' => 'PUT', 'url' => '/api/ordinales/guardar-valor',
                'cuerpo' => ['ordinal_id' => $cerrado->ordinal_id, 'propiedad' => 'pagina', 'valor' => 77]],
            'ordinales/guardar-valor-config' => ['verbo' => 'PUT', 'url' => '/api/ordinales/guardar-valor-config',
                // `reinicia_por_periodo` es una columna REAL de `dis_configuraciones`
                // (esquema, línea 868). Con un nombre inventado, `ColumnaSegura::exigir`
                // cortaría antes de escribir y el caso del superusuario fallaría por el
                // motivo equivocado — y el del 403 pasaría sin haber tocado el candado.
                'cuerpo' => ['config_id' => $cerrado->config_id, 'propiedad' => 'reinicia_por_periodo', 'valor' => 1]],
            'ordinales/destroy' => ['verbo' => 'PUT', 'url' => '/api/ordinales/destroy',
                'cuerpo' => ['ordinal_id' => $cerrado->ordinal_id]],
        ];
    }

    /**
     * Lo que hay escrito del manual de ese año, para comparar antes y después.
     *
     * Lleva **el conteo de la tabla** además de las filas, porque una de las cinco
     * es un `INSERT`: mirar sólo el ordinal y la configuración que ya existían no
     * vería un artículo sembrado de más, que es justo lo que `postStore` haría.
     */
    private function huella(object $cerrado): string
    {
        return json_encode([
            'ordinal' => DB::selectOne('SELECT * FROM dis_ordinales WHERE id = ?', [$cerrado->ordinal_id]),
            'config' => DB::selectOne('SELECT * FROM dis_configuraciones WHERE id = ?', [$cerrado->config_id]),
            'cuantos' => DB::selectOne('SELECT COUNT(*) AS n FROM dis_ordinales WHERE year_id = ?',
                [$cerrado->year_id])->n,
        ], JSON_THROW_ON_ERROR);
    }

    /** Un año cerrado que tenga a la vez un ordinal y una configuración. */
    private function unAnioCerradoConManual(): object
    {
        $fila = DB::selectOne('SELECT o.year_id, MIN(o.id) AS ordinal_id,
                  (SELECT c.id FROM dis_configuraciones c
                    WHERE c.year_id = o.year_id AND c.deleted_at IS NULL ORDER BY c.id LIMIT 1) AS config_id
            FROM dis_ordinales o
            WHERE o.deleted_at IS NULL
            GROUP BY o.year_id
            HAVING config_id IS NOT NULL
            ORDER BY o.year_id ASC LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún año con ordinales y configuración.');
        $this->assertTrue(AnioCerrado::estaCerrado($fila->year_id),
            "El año {$fila->year_id} no está cerrado, así que este fichero no mediría el candado.");

        return $fila;
    }

    private function yearIdCorriente(): int
    {
        $fila = DB::selectOne('SELECT id FROM years
            WHERE actual = 1 AND deleted_at IS NULL ORDER BY year ASC LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún año con `actual = 1`.');

        return (int) $fila->id;
    }
}
