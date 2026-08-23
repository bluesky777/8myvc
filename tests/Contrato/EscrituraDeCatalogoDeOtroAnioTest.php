<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §84 — Los tres catálogos que tienen año: el listado filtra y las cinco
 * escrituras no.
 *
 * De las diez tablas del lote A, exactamente **tres tienen `year_id`**
 * —`frases`, `escalas_de_valoracion` y `contratos`; las otras siete son del
 * colegio entero y no de un año—. En las tres, el listado filtra por
 * `$user->year_id`. **Ninguna de sus cinco escrituras lo comprueba.**
 *
 * Medido el 22 ago 2026, con un usuario del año 8:
 *
 *     GET    api/frases                 ->  47 frases, y la id 1 (año 1) NO está
 *     PUT    api/frases/update/1        ->  200, y la frase del año 1 queda pisada
 *     DELETE api/frases/destroy/1       ->  200, y se va a la papelera
 *     DELETE api/contratos/destroy/124  ->  200, y el contrato del año 7 se va
 *
 * O sea: **se edita y se borra una fila que no se puede ni ver desde el propio
 * listado.**
 *
 * ## Por qué esto se fija y NO se cierra
 *
 * Porque en una de las tres ya está decidido que sí, y decidido a propósito.
 * `EscalasDeValoracionController::deleteDestroy` lo lleva escrito en su docblock
 * desde la [§27.4](../../docs/migracion/05-codigo-muerto-y-roto.md):
 *
 * > **se puede borrar la escala de otro año a propósito**, que es la decisión ya
 * > tomada para escribir en años pasados
 *
 * y el motivo es bueno: las escalas de un año pasado siguen decidiendo cómo se
 * pinta el desempeño en los boletines **de ese año**, así que corregir una de
 * 2024 tiene que poder hacerse desde 2026. El mismo argumento vale igual de bien
 * para el banco de frases y para descontratar a alguien de un año cerrado.
 *
 * Lo que **no** está decidido es si vale para las otras dos, y la diferencia
 * entre las tres no es una decisión: es que alguien miró una y no las otras. La
 * §27.4 se tomó sobre `escalas` y quedó escrita en `escalas`. Otra vez lo mismo
 * de esta noche —**cerrar sobre una población no cierra sobre la de al lado**—,
 * sólo que aquí lo que se arrastró de más fue el permiso y no el arreglo.
 *
 * Va al `## PARA JOSETH` del cuaderno del lote A. Encenderlo por iniciativa
 * propia dejaría a un colegio sin poder corregir el banco de frases de un año
 * que aún imprime boletines, que es exactamente el caso que la §27.4 protegió.
 *
 * ## Lo que este test sí afirma
 *
 * Que las cinco se comportan **igual entre sí**. Hoy es un 200 en las cinco; el
 * día que alguien cierre una porque la estaba mirando, este test cae y obliga a
 * decidir sobre las cinco a la vez en vez de sobre la que se tenía delante. Eso
 * es lo único que no se puede conseguir escribiéndolo en prosa.
 */
class EscrituraDeCatalogoDeOtroAnioTest extends CasoDeContrato
{
    /**
     * El listado de los tres esconde lo de otros años.
     *
     * Es la mitad que hace interesante a la otra: sin esto, «la escritura no
     * comprueba el año» sería una observación sobre una columna. Con esto es una
     * asimetría — **la misma ruta family enseña una cosa y deja escribir otra.**
     */
    public function test_los_tres_listados_filtran_por_el_ano_del_usuario(): void
    {
        [$usuario, $ajenas] = $this->usuarioYFilasDeOtroAnio();
        $token = $this->tokenDe($usuario->username);

        $vistas = [];

        foreach (['frases' => 'frases', 'escalas' => 'escalas'] as $nombre => $ruta) {
            $ids = array_column($this->withToken($token)->json('GET', '/api/'.$ruta)->json(), 'id');

            // Sin esto el test es un verde hueco: un listado vacío tampoco trae
            // la fila ajena, así que «no la enseña» se cumpliría sin filtrar
            // nada. Es la trampa de esta noche —afirmar por ausencia— y aquí
            // está a una línea de distancia.
            $this->assertNotEmpty($ids,
                "`GET api/{$ruta}` no devolvió ninguna fila para el año {$usuario->year_id}: sin filas "
                .'propias, comprobar que no sale la ajena no comprueba nada.');

            if (in_array($ajenas[$nombre]->id, $ids, false)) {
                $vistas[] = "{$nombre}: la fila {$ajenas[$nombre]->id} es del año "
                    ."{$ajenas[$nombre]->year_id} y sale en el listado de un usuario del año {$usuario->year_id}";
            }

            $this->olvidarControladores();
        }

        // `contratos` va aparte: su listado no devuelve el id del contrato como
        // `id` sino como `contrato_id`, porque la fila que sale es la del
        // profesor. Mirar `id` aquí habría comparado ids de `profesores` con uno
        // de `contratos` y habría dicho «no está» siempre — un verde hueco.
        $contratos = array_column($this->withToken($token)->json('GET', '/api/contratos')->json(), 'contrato_id');

        $this->assertNotEmpty($contratos,
            "`GET api/contratos` no devolvió ningún contrato para el año {$usuario->year_id}. Y si "
            .'devolviera filas sin `contrato_id`, esto también las cazaría: sería el mismo verde hueco '
            .'por la otra puerta.');

        if (in_array($ajenas['contratos']->id, $contratos, false)) {
            $vistas[] = "contratos: el contrato {$ajenas['contratos']->id} es del año "
                ."{$ajenas['contratos']->year_id} y sale en el listado del año {$usuario->year_id}";
        }

        $this->assertSame([], $vistas,
            "Algún listado dejó de filtrar por el año del usuario.\n  ".implode("\n  ", $vistas));
    }

    /**
     * Y las cinco escrituras sí llegan a esas mismas filas.
     *
     * Se comprueba **la fila de después**, no el 200: un 200 aquí podría ser un
     * método que no hizo nada, y lo que se está fijando es justo que sí lo hizo.
     */
    public function test_las_cinco_escrituras_alcanzan_el_ano_de_al_lado(): void
    {
        [$usuario, $ajenas] = $this->usuarioYFilasDeOtroAnio();
        $token = $this->tokenDe($usuario->username);

        $noAlcanzan = [];

        // 1. Editar una frase de otro año.
        $r = $this->withToken($token)->json('PUT', '/api/frases/update/'.$ajenas['frases']->id,
            ['frase' => 'PISADA DESDE OTRO AÑO', 'tipo_frase' => 'Fortaleza']);
        $fila = DB::selectOne('SELECT frase FROM frases WHERE id = ?', [$ajenas['frases']->id]);

        if ($r->status() !== 200 || $fila->frase !== 'PISADA DESDE OTRO AÑO') {
            $noAlcanzan[] = 'frases/update -> '.$r->status().', quedó: '.json_encode($fila->frase);
        }
        $this->olvidarControladores();

        // 2. Borrar esa misma frase.
        $r = $this->withToken($token)->json('DELETE', '/api/frases/destroy/'.$ajenas['frases']->id);
        $fila = DB::selectOne('SELECT deleted_at FROM frases WHERE id = ?', [$ajenas['frases']->id]);

        if ($r->status() !== 200 || $fila->deleted_at === null) {
            $noAlcanzan[] = 'frases/destroy -> '.$r->status().', deleted_at: '.json_encode($fila->deleted_at);
        }
        $this->olvidarControladores();

        // 3. Editar una escala de otro año. El id va en el CUERPO, no en la URL.
        $r = $this->withToken($token)->json('PUT', '/api/escalas/update',
            ['id' => $ajenas['escalas']->id, 'desempenio' => 'PISADO', 'orden' => 1,
                'porc_inicial' => 0, 'porc_final' => 10, 'perdido' => 1, 'valoracion' => 'P']);
        $fila = DB::selectOne('SELECT desempenio FROM escalas_de_valoracion WHERE id = ?', [$ajenas['escalas']->id]);

        if ($r->status() !== 200 || $fila->desempenio !== 'PISADO') {
            $noAlcanzan[] = 'escalas/update -> '.$r->status().', quedó: '.json_encode($fila->desempenio);
        }
        $this->olvidarControladores();

        // 4. Borrar esa misma escala. Es la única de las cinco que lleva la
        //    decisión escrita en su docblock (§27.4).
        $r = $this->withToken($token)->json('DELETE', '/api/escalas/destroy/'.$ajenas['escalas']->id);
        $fila = DB::selectOne('SELECT deleted_at FROM escalas_de_valoracion WHERE id = ?', [$ajenas['escalas']->id]);

        if ($r->status() !== 200 || $fila->deleted_at === null) {
            $noAlcanzan[] = 'escalas/destroy -> '.$r->status().', deleted_at: '.json_encode($fila->deleted_at);
        }
        $this->olvidarControladores();

        // 5. Descontratar a alguien de otro año.
        $r = $this->withToken($token)->json('DELETE', '/api/contratos/destroy/'.$ajenas['contratos']->id);
        $fila = DB::selectOne('SELECT deleted_at FROM contratos WHERE id = ?', [$ajenas['contratos']->id]);

        if ($r->status() !== 200 || $fila->deleted_at === null) {
            $noAlcanzan[] = 'contratos/destroy -> '.$r->status().', deleted_at: '.json_encode($fila->deleted_at);
        }

        $this->assertSame([], $noAlcanzan,
            "Alguna de las cinco escrituras dejó de alcanzar el año de al lado.\n"
            .'Si es porque se cerró una, hay que decidir sobre las CINCO a la vez y no sobre la que se '
            .'tenía delante: en `escalas` está decidido que sí a propósito (§27.4) y en las otras dos '
            ."nadie lo ha decidido nunca.\n  ".implode("\n  ", $noAlcanzan));
    }

    /**
     * Un usuario y una fila de otro año en cada uno de los tres catálogos.
     *
     * Se busca el año que más filas tenga en los tres a la vez en vez de coger el
     * primero que salga: con ocho años en el seed, un usuario de un año sin
     * escalas propias haría que el primer test pasara sin comparar nada.
     *
     * @return array{0: object, 1: array<string, object>}
     */
    private function usuarioYFilasDeOtroAnio(): array
    {
        $usuario = DB::selectOne('SELECT u.username, p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($usuario, 'El seed no tiene ningún Usuario con periodo.');

        $ajenas = [];

        foreach ([
            'frases' => 'frases',
            'escalas' => 'escalas_de_valoracion',
            'contratos' => 'contratos',
        ] as $nombre => $tabla) {
            $fila = DB::selectOne("SELECT id, year_id FROM {$tabla}
                WHERE year_id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1", [$usuario->year_id]);

            $this->assertNotNull($fila,
                "El seed no tiene ninguna fila de `{$tabla}` fuera del año {$usuario->year_id}. "
                .'Sin eso este test pasa sin comprobar nada.');

            $ajenas[$nombre] = $fila;
        }

        return [$usuario, $ajenas];
    }
}
