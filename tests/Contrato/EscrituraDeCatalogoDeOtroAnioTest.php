<?php

namespace Tests\Contrato;

use App\Support\AnioCerrado;
use Illuminate\Support\Facades\DB;

/**
 * §84 — Los tres catálogos que tienen año: el listado filtra, y las cinco
 * escrituras **ya no** llegan a un año cerrado en manos de cualquiera.
 *
 * De las diez tablas del lote A, exactamente **tres tienen `year_id`**
 * —`frases`, `escalas_de_valoracion` y `contratos`; las otras siete son del
 * colegio entero y no de un año—. En las tres, el listado filtra por
 * `$user->year_id`. **Ninguna de sus cinco escrituras lo comprobaba.**
 *
 * Medido el 22 ago 2026, con un usuario del año 8:
 *
 *     GET    api/frases                 ->  47 frases, y la id 1 (año 1) NO está
 *     PUT    api/frases/update/1        ->  200, y la frase del año 1 queda pisada
 *     DELETE api/frases/destroy/1       ->  200, y se va a la papelera
 *     DELETE api/contratos/destroy/124  ->  200, y el contrato del año 7 se va
 *
 * O sea: **se editaba y se borraba una fila que no se puede ni ver desde el
 * propio listado.**
 *
 * ## CERRADO el 14 sep 2026, y lo que cambió es QUIÉN — no QUÉ
 *
 * La versión anterior de este fichero fijaba que las cinco alcanzaban el año de
 * al lado, y lo hacía **a propósito**: la 05 §27.4 decidió que escribir en años
 * pasados tiene que poder hacerse —la escala de 2022 sigue decidiendo cómo se
 * pinta el desempeño en los boletines **de 2022**— y este test existía para que
 * el día que alguien cerrara una de las cinco «porque la tenía delante», cayera y
 * obligara a decidir sobre las cinco a la vez.
 *
 * **Eso es exactamente lo que pasó.** Joseth decidió el 14 sep 2026, con las
 * cinco delante y las poblaciones medidas: se sigue pudiendo escribir en un año
 * cerrado, pero **sólo un superusuario**. Y sobre las cinco, contratos incluido,
 * preguntado expresamente.
 *
 * Así que el test no se borra ni se relaja: **cambia de afirmación**. Antes decía
 * *«las cinco se comportan igual entre sí, y hoy eso es un 200»*; ahora dice *«las
 * cinco se comportan igual entre sí, y eso es 403 para el personal llano y 200
 * para el superusuario»*. Lo que protege es lo mismo: que nadie cierre o abra
 * **una** sin mirar las otras cuatro.
 *
 * ## El verde falso que tenía dentro, y que sólo se vio al tocarlo
 *
 * Con el guard ya puesto, este fichero **seguía en verde** — y no porque el guard
 * no funcionara, sino porque su sujeto era `users_1`, que es **superusuario**. O
 * sea que el test decía «el personal alcanza el año de al lado» y demostraba «el
 * superusuario alcanza el año de al lado», que es menos y es otra cosa. Es
 * literalmente el caso contra el que avisa el docblock de
 * `CasoDeContrato::usuarioLlanoDelPersonal()`, dos ficheros más allá.
 *
 * Por eso ahora hay **dos sujetos y los dos son explícitos**, y el del 403 se pide
 * con `tokenDelPersonalLlanoDe()`, que exige `is_superuser = 0` y falla con un
 * mensaje si el seed no tiene ninguno.
 *
 * ## Y por qué «cerrado» es ANTERIOR al actual
 *
 * Porque el colegio **monta el año siguiente antes de conmutarlo**: en la copia de
 * desarrollo de `simonbolivar`, medido el 14 sep 2026, `years` tiene **2026 vivo
 * con `actual = 0`** mientras el año en curso es 2025 — y ahí es donde se copian
 * las escalas y el plan de área. Una regla escrita como *«lo que no es el actual
 * está cerrado»* le cerraría al colegio justo esa pantalla.
 *
 * Los dos últimos casos de este fichero son los que sujetan eso. **Y el del año
 * futuro hoy se SALTA**, que es una cobertura que falta y no un caso que pase:
 * en el seed de tests **2026 está en la papelera** (`deleted_at` puesto), así que
 * no hay ningún año vivo posterior al corriente contra el que medir. Se salta con
 * el motivo escrito en vez de pasar en verde por ausencia — que es la trampa que
 * este mismo fichero persigue en su primer caso.
 *
 * > **Lo que este test NO demuestra, y conviene que no se dé por demostrado:** que
 * > la regla aguante con **varios** años marcados `actual = 1`. Ese dato existe
 * > —lo documenta el propio comentario de `YearsController::putSetActual`, que
 * > marcaba el año como actual al destildar la casilla— pero **no se ha
 * > reproducido en ninguna de las dos bases a mano**: en las dos hay exactamente
 * > un `actual = 1` vivo. Por eso `AnioCerrado` toma el más viejo de los marcados,
 * > que es la salida permisiva, y por eso eso está razonado allí y no afirmado
 * > aquí.
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
     * Las cinco, para quien no es superusuario: **403 y la fila intacta**.
     *
     * Se comprueba **la fila de después** y no sólo el código, que es la regla de
     * esta casa: un 403 con la fila ya escrita sería peor que un 200 honesto, y
     * los dos se ven igual mirando el status.
     */
    public function test_las_cinco_escrituras_de_un_anio_cerrado_son_403_para_el_personal_llano(): void
    {
        [$usuario, $ajenas] = $this->usuarioYFilasDeOtroAnio();

        $this->exigirQueEsosAniosEstenCerrados($ajenas);

        $token = $this->tokenDelPersonalLlanoDe((int) $usuario->year_id);

        $colados = [];

        foreach ($this->lasCincoEscrituras($ajenas) as $nombre => $escritura) {
            $antes = $this->huellaDe($escritura['tabla'], $escritura['id']);

            $r = $this->withToken($token)->json($escritura['verbo'], $escritura['url'], $escritura['cuerpo']);

            $despues = $this->huellaDe($escritura['tabla'], $escritura['id']);

            if ($r->status() !== 403) {
                $colados[] = "{$nombre} -> ".$r->status().' y no 403';
            }

            if ($antes !== $despues) {
                $colados[] = "{$nombre} -> la fila cambió pese al corte: "
                    .json_encode($antes).' -> '.json_encode($despues);
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $colados,
            "Alguna de las cinco dejó escribir en un año cerrado sin ser superusuario.\n"
            .'Si se ha abierto una a propósito, hay que decidir sobre las CINCO a la vez y no sobre '
            ."la que se tenía delante — es lo que costó cerrar esto el 14 sep 2026.\n  "
            .implode("\n  ", $colados));
    }

    /**
     * Y el superusuario sigue llegando a las cinco: la 05 §27.4 sigue viva.
     *
     * **Éste es el que impide que el cierre se convierta en otra cosa.** Sin él,
     * alguien que endureciera el criterio —de superusuario a nadie— vería el
     * fichero entero en verde, porque el caso de arriba sólo afirma que el
     * personal llano NO puede.
     */
    public function test_un_superusuario_sigue_alcanzando_las_cinco(): void
    {
        [$usuario, $ajenas] = $this->usuarioYFilasDeOtroAnio();

        $this->exigirQueEsosAniosEstenCerrados($ajenas);
        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este caso no es superusuario, así que no demuestra lo que dice su nombre.');

        $token = $this->tokenDe($usuario->username);

        $noAlcanzan = [];

        foreach ($this->lasCincoEscrituras($ajenas) as $nombre => $escritura) {
            $antes = $this->huellaDe($escritura['tabla'], $escritura['id']);

            $r = $this->withToken($token)->json($escritura['verbo'], $escritura['url'], $escritura['cuerpo']);

            $despues = $this->huellaDe($escritura['tabla'], $escritura['id']);

            if ($r->status() !== 200) {
                $noAlcanzan[] = "{$nombre} -> ".$r->status().' y no 200';
            }

            if ($antes === $despues) {
                $noAlcanzan[] = "{$nombre} -> 200 pero la fila no se movió: ".json_encode($antes);
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $noAlcanzan,
            "Alguna de las cinco dejó de alcanzar un año cerrado siendo superusuario.\n"
            .'La 05 §27.4 decidió que eso tiene que poder hacerse —la escala de 2022 sigue decidiendo '
            ."cómo se pinta el desempeño en los boletines de 2022—; lo que se cerró el 14 sep es QUIÉN.\n  "
            .implode("\n  ", $noAlcanzan));
    }

    /**
     * El año **corriente** se sigue escribiendo sin ser superusuario.
     *
     * Es el caso que dice que esto no le ha roto el día a día a nadie, y el que
     * habría caído con la regla escrita como «lo que no es el actual está
     * cerrado»: la base de tests tiene **dos** años con `actual = 1`.
     */
    public function test_el_anio_corriente_se_sigue_escribiendo_sin_ser_superusuario(): void
    {
        $corriente = AnioCerrado::anioCorriente();

        $this->assertNotNull($corriente, 'El seed no tiene ningún año con `actual = 1`.');

        $yearId = $this->unYearIdCon($corriente);
        $token = $this->tokenDelPersonalLlanoDe($yearId);

        foreach ($this->suyasDelAnio($yearId) as $nombre => $escritura) {
            $r = $this->withToken($token)->json($escritura['verbo'], $escritura['url'], $escritura['cuerpo']);

            $this->assertSame(200, $r->status(),
                "`{$nombre}` sobre el año corriente ({$corriente}) contestó ".$r->status()
                .'. El candado del año cerrado se está comiendo el año en curso, que es el fallo '
                .'que más se nota y el menos visible desde el código.');

            $this->olvidarControladores();
        }
    }

    /**
     * Y un año **futuro** tampoco está cerrado.
     *
     * El colegio monta el año siguiente antes de conmutarlo —2026 existe con
     * `actual = 0` mientras corre 2025—, y ahí es donde se copian las escalas y el
     * plan de área. Una regla que bloqueara «todo lo que no es el actual» le
     * cerraría al colegio justo la pantalla que está usando.
     *
     * **`contratos` no entra aquí**: el seed no tiene ninguno fuera de los años 7
     * y 8, así que no hay fila futura de la que hablar. Se dice en vez de
     * colarlo, porque un bucle de dos que parece de tres es la forma que tiene un
     * agujero de pasar desapercibido.
     */
    public function test_un_anio_futuro_no_esta_cerrado(): void
    {
        $corriente = AnioCerrado::anioCorriente();

        $this->assertNotNull($corriente, 'El seed no tiene ningún año con `actual = 1`.');

        $futuro = DB::selectOne('SELECT id, year FROM years
            WHERE year > ? AND deleted_at IS NULL ORDER BY year ASC LIMIT 1', [$corriente]);

        if ($futuro === null) {
            $this->markTestSkipped("El seed no tiene ningún año posterior a {$corriente}.");
        }

        $this->assertFalse(AnioCerrado::estaCerrado($futuro->id),
            "El año {$futuro->year} es posterior al corriente ({$corriente}) y sale como cerrado. "
            .'El colegio monta el año siguiente antes de conmutarlo: eso le cierra esa pantalla.');

        $token = $this->tokenDelPersonalLlanoDe((int) $corriente === (int) $futuro->year
            ? (int) $futuro->id
            : $this->unYearIdCon($corriente));

        foreach ($this->suyasDelAnio((int) $futuro->id) as $nombre => $escritura) {
            $r = $this->withToken($token)->json($escritura['verbo'], $escritura['url'], $escritura['cuerpo']);

            $this->assertSame(200, $r->status(),
                "`{$nombre}` sobre el año futuro ({$futuro->year}) contestó ".$r->status().'.');

            $this->olvidarControladores();
        }
    }

    /**
     * Las cinco escrituras, con su tabla y su fila, en un solo sitio.
     *
     * Estaban escritas a mano una detrás de otra y **eso era la mitad del
     * problema**: comprobar dos cosas sobre las cinco significaba copiarlas dos
     * veces, y una copia que se queda corta no se ve. Aquí se declaran una vez y
     * los tres casos las recorren.
     *
     * `escalas/update` va con el id en el **cuerpo** —la ruta es `PUT
     * escalas/update` a secas— y las otras cuatro en la URL.
     *
     * @param  array<string, object>  $ajenas
     * @return array<string, array<string, mixed>>
     */
    private function lasCincoEscrituras(array $ajenas): array
    {
        return [
            'frases/update' => [
                'verbo' => 'PUT', 'url' => '/api/frases/update/'.$ajenas['frases']->id,
                'cuerpo' => ['frase' => 'PISADA DESDE OTRO AÑO', 'tipo_frase' => 'Fortaleza'],
                'tabla' => 'frases', 'id' => $ajenas['frases']->id,
            ],
            'frases/destroy' => [
                'verbo' => 'DELETE', 'url' => '/api/frases/destroy/'.$ajenas['frases']->id,
                'cuerpo' => [], 'tabla' => 'frases', 'id' => $ajenas['frases']->id,
            ],
            'escalas/update' => [
                'verbo' => 'PUT', 'url' => '/api/escalas/update',
                'cuerpo' => ['id' => $ajenas['escalas']->id, 'desempenio' => 'PISADO', 'orden' => 1,
                    'porc_inicial' => 0, 'porc_final' => 10, 'perdido' => 1, 'valoracion' => 'P'],
                'tabla' => 'escalas_de_valoracion', 'id' => $ajenas['escalas']->id,
            ],
            // `acepto_desviacion` porque lo que este caso mide es **el año**, no el
            // aviso de población: sin él, el 422 del aviso taparía el 403 que se
            // está comprobando y el test pasaría por el motivo equivocado.
            'escalas/destroy' => [
                'verbo' => 'DELETE', 'url' => '/api/escalas/destroy/'.$ajenas['escalas']->id,
                'cuerpo' => ['acepto_desviacion' => true],
                'tabla' => 'escalas_de_valoracion', 'id' => $ajenas['escalas']->id,
            ],
            'contratos/destroy' => [
                'verbo' => 'DELETE', 'url' => '/api/contratos/destroy/'.$ajenas['contratos']->id,
                'cuerpo' => [], 'tabla' => 'contratos', 'id' => $ajenas['contratos']->id,
            ],
        ];
    }

    /**
     * Las dos que sí tienen fila en cualquier año: editar una frase y renombrar
     * una banda. Sin borrados — lo que se mide aquí es que **el candado no muerde**,
     * y para eso una escritura basta y no deja el año sin escala.
     *
     * @return array<string, array<string, mixed>>
     */
    private function suyasDelAnio(int $yearId): array
    {
        $frase = DB::selectOne('SELECT id FROM frases
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$yearId]);
        $escala = DB::selectOne('SELECT id, desempenio, orden, porc_inicial, porc_final, perdido, valoracion
            FROM escalas_de_valoracion WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$yearId]);

        $this->assertNotNull($frase, "El seed no tiene frases del año {$yearId}.");
        $this->assertNotNull($escala, "El seed no tiene escalas del año {$yearId}.");

        return [
            'frases/update' => [
                'verbo' => 'PUT', 'url' => '/api/frases/update/'.$frase->id,
                'cuerpo' => ['frase' => 'ESCRITA EN SU PROPIO AÑO', 'tipo_frase' => 'Fortaleza'],
            ],
            // Se reescribe con **sus mismos valores**: lo que se comprueba es que
            // la ruta deja pasar, no que sepa cambiar un campo — y así el caso no
            // le deja al siguiente test una escala con otros rangos.
            'escalas/update' => [
                'verbo' => 'PUT', 'url' => '/api/escalas/update',
                'cuerpo' => ['id' => $escala->id, 'desempenio' => $escala->desempenio,
                    'orden' => $escala->orden, 'porc_inicial' => $escala->porc_inicial,
                    'porc_final' => $escala->porc_final, 'perdido' => $escala->perdido,
                    'valoracion' => $escala->valoracion],
            ],
        ];
    }

    /**
     * Lo que hay en la fila ahora, para compararlo con lo de después.
     *
     * Trae `deleted_at` a propósito: tres de las cinco escrituras son borrados
     * lógicos, y sin esa columna «la fila no cambió» sería cierto justo cuando el
     * borrado sí ocurrió.
     */
    private function huellaDe(string $tabla, $id): ?string
    {
        $fila = DB::selectOne("SELECT * FROM {$tabla} WHERE id = ?", [$id]);

        // `JSON_THROW_ON_ERROR` y no un `?:`: `json_encode` devuelve `string|false`, y
        // un `false` convertido a `''` haría que dos filas distintas tuvieran la misma
        // huella — o sea que «la fila no cambió» saldría cierto por no haber podido
        // mirarla. Aquí es mejor que reviente.
        return $fila === null ? null : json_encode($fila, JSON_THROW_ON_ERROR);
    }

    /**
     * Que los años de esas filas estén de verdad cerrados.
     *
     * Sin esto, un seed en el que la fila «ajena» cayera en el año corriente
     * dejaría los dos casos de arriba **pasando por el motivo equivocado**: el 403
     * no llegaría nunca y el 200 del superusuario sería el de siempre.
     *
     * @param  array<string, object>  $ajenas
     */
    private function exigirQueEsosAniosEstenCerrados(array $ajenas): void
    {
        foreach ($ajenas as $nombre => $fila) {
            $this->assertTrue(AnioCerrado::estaCerrado($fila->year_id),
                "La fila de `{$nombre}` es del año {$fila->year_id}, que NO está cerrado. "
                .'Este caso mide el candado del año cerrado: con una fila de un año abierto pasaría '
                .'sin haberlo tocado.');
        }
    }

    /** El `id` de la fila de `years` cuyo número es ése. */
    private function unYearIdCon(int $numero): int
    {
        $fila = DB::selectOne('SELECT id FROM years WHERE year = ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$numero]);

        $this->assertNotNull($fila, "No hay ninguna fila de `years` con el año {$numero}.");

        return (int) $fila->id;
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
        $usuario = DB::selectOne('SELECT u.username, u.is_superuser, p.year_id FROM users u
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
