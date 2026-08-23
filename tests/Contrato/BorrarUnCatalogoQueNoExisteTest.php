<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §82 — Borrar un catálogo que no existe: ocho decían 404 y dos decían que sí.
 *
 * La [§70](../../docs/migracion/05-codigo-muerto-y-roto.md) midió **qué se lleva
 * por delante** borrar un catálogo del colegio. Lo que no había mirado nadie es
 * la pregunta de al lado, que es más barata y da antes: **qué contesta cuando no
 * hay nada que borrar.**
 *
 * De las diez rutas de borrar del lote, ocho contestaban 404 y dos contestaban
 * **200 diciendo que sí**:
 *
 *   - `DELETE definiciones_comportamiento/destroy/{id}` -> **200** con el texto
 *     plano `No se encontró`. Ni siquiera es JSON: todas las demás respuestas de
 *     error de esta API son un objeto con `message`, así que un cliente que la
 *     parsee no saca el motivo — saca un fallo de parseo, o la cadena entera.
 *   - `DELETE contratos/destroy/{id}` -> **200** con el cuerpo `0`.
 *     `Contrato::destroy($id)` devuelve *cuántas filas borró*, y ese número se
 *     devolvía tal cual.
 *
 * Las dos son de la familia que persigue `tools/respuestas-que-mienten.py`
 * (§37, §45): **el 200 es lo que el front usa para decidir que la fila se fue.**
 * La rejilla la quita de la pantalla y la fila sigue ahí hasta que alguien
 * recarga; y en el caso de contratos, lo que se quita de la pantalla es un
 * profesor que sigue contratado.
 *
 * ## Por qué contratos es una que ya se debería haber cerrado
 *
 * `postIndex` de ese mismo controlador **ya se cerró** en la §78: contratar a un
 * profesor que no existe escribía una fila huérfana y contestaba 200. Se arregló
 * el alta y se dejó la baja. Es la misma forma que la papelera de la §76 —cinco
 * sitios cerrados y la mitad que devuelve abierta un mes— y la misma que acaba
 * de aparecer en `boletines2/destroy`:
 *
 * > **Cerrar una serie sobre una población no la cierra sobre la de al lado.**
 * > Al cerrar hay que escribir sobre qué población se cerró.
 *
 * ## Sobre qué población se cierra ésta
 *
 * Sobre **las diez tablas de catálogo del lote A**, y comprobado que la
 * operación no vive en otro sitio: un grep de `Modelo::destroy|find|findOrFail`
 * y otro de `DELETE FROM` / `UPDATE ... SET deleted_at` sobre las diez tablas,
 * los dos contra toda `app/`, no dan ninguna escritura ni borrado suyo fuera de
 * estos diez controladores. Es la comprobación que faltó en las series de
 * arriba: **el nombre del fichero no dice sobre qué tabla escribe.**
 */
class BorrarUnCatalogoQueNoExisteTest extends CasoDeContrato
{
    /**
     * Las diez contestan 404, y ninguna borra nada.
     *
     * Se acumula y se afirma una vez, por lo mismo que en `EditarUnCatalogoTest`:
     * un `assertStatus` dentro del bucle habría parado en la primera y no diría
     * cuántas de las diez estaban de verdad cubiertas.
     */
    public function test_las_diez_rutas_de_borrar_dan_404_con_un_id_que_no_existe(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        // [tabla, ruta]. El id se calcula por tabla y no es una constante: un
        // 99999999 a pelo se lee como «un número grande» y no dice de dónde sale.
        $rutas = [
            'areas' => ['areas', 'areas/destroy/{id}'],
            'frases' => ['frases', 'frases/destroy/{id}'],
            'frases_asignatura' => ['frases_asignatura', 'frases_asignatura/destroy/{id}'],
            'definiciones_comportamiento' => ['definiciones_comportamiento',
                'definiciones_comportamiento/destroy/{id}'],
            'escalas_de_valoracion' => ['escalas_de_valoracion', 'escalas/destroy/{id}'],
            'tipos_documentos' => ['tipos_documentos', 'tiposdocumento/{id}'],
            'materias' => ['materias', 'materias/destroy/{id}'],
            'contratos' => ['contratos', 'contratos/destroy/{id}'],
            'niveles_educativos' => ['niveles_educativos', 'niveles_educativos/destroy/{id}'],
            'grados' => ['grados', 'grados/destroy/{id}'],
        ];

        $mienten = [];

        foreach ($rutas as $nombre => [$tabla, $ruta]) {
            $inexistente = (int) DB::selectOne("SELECT IFNULL(MAX(id),0) + 1000 n FROM {$tabla}")->n;
            $antes = (int) DB::selectOne("SELECT COUNT(*) n FROM {$tabla}")->n;

            $r = $this->withToken($token)->json('DELETE',
                '/api/'.str_replace('{id}', (string) $inexistente, $ruta));

            if ($r->status() !== 404) {
                $mienten[] = "{$nombre} ({$ruta}) -> {$r->status()}  cuerpo: "
                    .mb_substr(trim($r->getContent()), 0, 60);
            }

            $this->assertSame($antes, (int) DB::selectOne("SELECT COUNT(*) n FROM {$tabla}")->n,
                "`{$ruta}` con un id que no existe cambió el número de filas de `{$tabla}`.");

            $this->olvidarControladores();
        }

        $this->assertSame([], $mienten,
            count($mienten).' de '.count($rutas).' rutas de borrar contestan que sí a un id que no '
            ."existe. Un 200 es lo que el front usa para quitar la fila de la pantalla.\n  "
            .implode("\n  ", $mienten));
    }

    /**
     * Y borrar de verdad sigue funcionando, en las dos que se tocaron.
     *
     * Sin esto el arreglo podría ser un `abort(404)` que se come también el caso
     * bueno, y lo que se apagaría es el botón de «Quitar» de dos pantallas sin
     * que nada falle. Se comprueba **la fila de después**, no el 200.
     */
    public function test_las_dos_que_se_tocaron_siguen_borrando(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $def = DB::selectOne('SELECT id FROM definiciones_comportamiento
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->json('DELETE', '/api/definiciones_comportamiento/destroy/'.$def->id)
            ->assertStatus(200);

        $this->assertNotNull(
            DB::selectOne('SELECT deleted_at FROM definiciones_comportamiento WHERE id = ?', [$def->id])->deleted_at,
            'La definición no se fue a la papelera.'
        );
        $this->olvidarControladores();

        $contrato = DB::selectOne('SELECT id FROM contratos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        // El cuerpo es `1` —las filas borradas— y se fija porque es lo que el
        // front lee: cambiarlo por otra cosa es cambiar el contrato, aunque el
        // 200 siga igual.
        $this->withToken($token)->json('DELETE', '/api/contratos/destroy/'.$contrato->id)
            ->assertStatus(200)->assertSee('1');

        $this->assertNotNull(
            DB::selectOne('SELECT deleted_at FROM contratos WHERE id = ?', [$contrato->id])->deleted_at,
            'El contrato no se fue a la papelera: el profesor sigue contratado.'
        );
    }

    /**
     * Un contrato que ya está en la papelera tampoco «se borra».
     *
     * Es el criterio que ya usa `EscalasDeValoracionController::exigirQueLaEscalaExista`
     * y no es un detalle de purista: sin mirar `deleted_at`, la comprobación de
     * existencia diría que sí y `Contrato::destroy` devolvería `0` — o sea el
     * mismo 200 mentiroso que se acaba de cerrar, sólo que por otra puerta.
     */
    public function test_un_contrato_que_ya_esta_en_la_papelera_da_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $contrato = DB::selectOne('SELECT id FROM contratos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->json('DELETE', '/api/contratos/destroy/'.$contrato->id)->assertStatus(200);
        $this->olvidarControladores();

        $this->withToken($token)->json('DELETE', '/api/contratos/destroy/'.$contrato->id)->assertStatus(404);
    }
}
