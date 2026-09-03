<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `GET horario/versiones` — y lo que de verdad decide este lote: **listar no es
 * descargar**.
 *
 * Es la §5.3 de [23-horarios.md](../../docs/migracion/23-horarios.md) y la decisión
 * 12 de Joseth (2 sep 2026). La ruta lleva `auth.personal` **y nada más**, o sea
 * que la puede llamar cualquiera de los **53 docentes** del colegio — y esa apertura
 * se pudo conceder *porque* el listado devuelve nombre, fecha, quién subió, si es la
 * oficial y el veredicto, **y ni el fichero de proyecto ni las lecciones**.
 *
 * ## Qué protege qué, MEDIDO mutando el controlador y no razonado
 *
 * La primera versión de este docblock decía que el test se pondría rojo si alguien
 * cambiaba el `SELECT` a `hv.*`. **Se probó y es falso**, y la forma de averiguarlo
 * fue mutar el controlador a mano y mirar:
 *
 * | mutación | ¿se filtra el blob? | ¿rojo? |
 * |---|---|---|
 * | `SELECT hv.*`, dejando el mapa | **no** | verde, y es lo correcto |
 * | devolver `$filas` crudas, dejando el `SELECT` | **no** | rojo, pero **por la forma**, no por el blob |
 * | **las dos a la vez** | **sí** | rojo, y ahí sí canta el blob |
 *
 * O sea que son **dos defensas independientes y cada una basta sola**: el `SELECT`
 * que nombra sus columnas impide que el blob llegue a PHP, y el `array_map` que
 * nombra sus claves impide que salga aunque llegara. Quitar una no filtra nada —y
 * por eso el verde de la primera fila **no es un test flojo, es la respuesta
 * correcta**—; el día que caigan las dos, este fichero es lo único que hay, y ahí se
 * pone rojo.
 *
 * Por eso la fuga se comprueba por **dos caminos que fallan por separado** —el juego
 * de claves de cada fila y el cuerpo crudo de la respuesta, que caza el blob aunque
 * viaje con otro nombre— y además hay un control de que **el blob existe y es
 * alcanzable** (`test_el_control_de_la_fuga_sabe_ponerse_rojo`). Sin ese control, el
 * verde no distinguiría *«no se filtra»* de *«la marca nunca llegó a la base»*.
 *
 * ## Lo que este fichero NO fija
 *
 * El 403 de alumnos y acudientes, que es de `HorarioAutorizacionTest` y ya está.
 * Aquí se entra siempre como personal llano, que es el sujeto más pequeño que puede
 * llamar a esta ruta: si lo que se midiera fuese un superusuario, el verde diría
 * menos de lo que parece.
 */
class HorarioListadoTest extends CasoDeContrato
{
    /**
     * El año con personal llano que **no** es el actual, para la decisión 13.
     *
     * En la base de tests hay tres años con `Usuario` no superusuario: 1 (2018),
     * 7 (2024) y 8 (2025, el actual). Se usa el 7 y no el 1 porque el 1 es el que
     * ya arrastra los cuarenta tests que documenta `usuarioLlanoDelPersonal()`.
     */
    private const ANIO_PASADO = 7;

    /**
     * Un trozo de texto que no puede salir de ningún otro sitio.
     *
     * Va dentro del `proyecto` para poder buscarlo en el cuerpo **crudo** de la
     * respuesta: así la comprobación no depende de cómo se llame la clave. Si el
     * blob se colara bajo `proyecto`, bajo `datos` o dentro de otro campo, esta
     * cadena aparecería igual.
     */
    private const MARCA_DEL_BLOB = 'MARCA-QUE-SOLO-VIVE-EN-EL-PROYECTO-8f3a';

    /** Las claves que el contrato deja salir, y ninguna más. */
    private const CLAVES = [
        'id', 'year_id', 'nombre', 'subida_por', 'subida_por_username',
        'created_at', 'es_oficial', 'comprobaciones',
    ];

    /**
     * Mete una versión y devuelve su id.
     *
     * `proyecto` lleva siempre la marca porque **la columna es `NOT NULL`** y porque
     * una fila con el blob vacío haría pasar el test de la fuga sin comprobar nada:
     * sería el «0 encontrados» que no distingue *«revisé y no había»* de *«no había
     * nada que revisar»*.
     */
    private function versionEn(int $yearId, string $nombre, ?int $subidaPor = null, ?string $veredicto = null): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $yearId,
                $nombre,
                $subidaPor,
                '{"proyecto":"'.self::MARCA_DEL_BLOB.'"}',
                $veredicto,
                '2026-09-04 10:00:00',
                '2026-09-04 10:00:00',
            ]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /** El año del token que se usa en casi todos: el del personal llano del año actual. */
    private function anioDelSujeto(): int
    {
        return (int) DB::selectOne(
            'SELECT p.year_id FROM users u JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?',
            [$this->usuarioLlanoDelPersonal()->id]
        )->year_id;
    }

    private function listar(?string $token = null)
    {
        return $this->getJson('/api/horario/versiones', [
            'Authorization' => 'Bearer '.($token ?? $this->tokenDelPersonalLlano()),
        ]);
    }

    /**
     * EL TEST DEL LOTE: el fichero de proyecto no sale, por ninguna puerta.
     */
    #[Test]
    public function el_listado_no_devuelve_el_proyecto_ni_las_lecciones(): void
    {
        $this->versionEn($this->anioDelSujeto(), 'Versión con blob dentro');

        $r = $this->listar()->assertStatus(200);
        $filas = $r->json();

        $this->assertCount(1, $filas, 'La versión recién metida tiene que salir; si no, lo de abajo no comprueba nada.');

        // Camino 1: el juego de claves, exacto por los dos lados.
        $this->assertSame(self::CLAVES, array_keys($filas[0]),
            'El listado devuelve claves distintas de las del contrato (§5.3). Si aparece '
            .'`proyecto` o `lecciones`, esta ruta acaba de entregarle el horario entero del '
            .'colegio a cualquiera de los 53 docentes: listar no es descargar.');

        // Camino 2: el cuerpo crudo. Falla aunque el blob viaje con otro nombre.
        $this->assertStringNotContainsString(self::MARCA_DEL_BLOB, $r->getContent(),
            'El contenido del fichero de proyecto ha aparecido en la respuesta, da igual bajo '
            .'qué clave. Es el `.myvch` del colegio entero.');
    }

    /**
     * Y el control del anterior: **el blob existe y es alcanzable**.
     *
     * Sin esto, el verde de arriba no distingue «el `SELECT` nombra sus columnas» de
     * «la marca nunca se guardó». Aquí se corre la consulta con el asterisco que el
     * controlador **no** usa y se exige que el blob **sí** salga: eso demuestra que
     * lo único que lo deja fuera es cómo está escrito ese `SELECT`, que es
     * exactamente lo que el test de arriba protege.
     */
    #[Test]
    public function el_control_de_la_fuga_sabe_ponerse_rojo(): void
    {
        $id = $this->versionEn($this->anioDelSujeto(), 'Versión con blob dentro');

        $conAsterisco = DB::selectOne('SELECT hv.* FROM horario_versiones hv WHERE hv.id = ?', [$id]);

        $this->assertStringContainsString(self::MARCA_DEL_BLOB, (string) $conAsterisco->proyecto,
            'La marca no está en la base, así que el test de la fuga estaría pasando por no '
            .'tener nada que encontrar. Es el «0 encontrados» sin población.');
    }

    /**
     * Cuál es la oficial sale del **puntero** `years.horario_version_id`.
     *
     * No de una bandera en `horario_versiones`: MySQL no tiene índices parciales, así
     * que una bandera no se puede atar a «como mucho una por año» y el día que
     * hubiera dos en verdadero este listado enseñaría dos oficiales sin que nada
     * fallara (§5.1).
     */
    #[Test]
    public function dice_cual_es_la_oficial_y_sale_del_puntero_del_anio(): void
    {
        $anio = $this->anioDelSujeto();
        $primera = $this->versionEn($anio, 'La vieja');
        $segunda = $this->versionEn($anio, 'La buena');

        DB::update('UPDATE years SET horario_version_id = ? WHERE id = ?', [$segunda, $anio]);

        $porId = collect($this->listar()->assertStatus(200)->json())->keyBy('id');

        $this->assertTrue($porId[$segunda]['es_oficial'], 'La apuntada por el año tiene que salir como oficial.');
        $this->assertFalse($porId[$primera]['es_oficial'], 'Y sólo ella: `es_oficial` no es «existe una oficial».');
    }

    /**
     * Sin puntero, **ninguna** es oficial — y eso es un estado, no un fallo.
     *
     * `years.horario_version_id` nace `NULL` y **subir no es publicar** (decisión
     * 17): una versión recién subida no le llega a nadie hasta que alguien la
     * marca.
     */
    #[Test]
    public function sin_puntero_ninguna_es_oficial(): void
    {
        $anio = $this->anioDelSujeto();
        DB::update('UPDATE years SET horario_version_id = NULL WHERE id = ?', [$anio]);
        $this->versionEn($anio, 'Recién subida');

        $filas = $this->listar()->assertStatus(200)->json();

        $this->assertSame([false], array_column($filas, 'es_oficial'),
            'Con el puntero en NULL ninguna versión es la oficial: subir no es publicar.');
    }

    /**
     * Sólo salen las del año del token.
     *
     * El año sale del token y no de un parámetro, así que este test es el que dice
     * que el filtro existe: sin él, un docente vería las versiones de todos los años
     * del colegio en una sola lista y no habría forma de saber de cuál es cada una.
     */
    #[Test]
    public function solo_lista_las_del_anio_del_token(): void
    {
        $delSujeto = $this->anioDelSujeto();
        $mia = $this->versionEn($delSujeto, 'Del año del token');
        $ajena = $this->versionEn(self::ANIO_PASADO, 'De otro año');

        $ids = array_column($this->listar()->assertStatus(200)->json(), 'id');

        $this->assertContains($mia, $ids);
        $this->assertNotContains($ajena, $ids, 'Se ha colado una versión de otro año.');
    }

    /**
     * Y en un año **pasado** también se lista, que es la decisión 13.
     *
     * Moverse por un año pasado es el producto ([16](../../docs/migracion/16-escribir-en-un-anio-pasado.md)),
     * y lo que frena las escrituras allí es el interruptor del **periodo**. Un
     * horario no cuelga de ningún periodo, así que ese candado no le aplica.
     * **Filtrar por `y.actual` aquí dejaría el listado vacío en 200** — la forma de
     * fallo que este repositorio lleva contada: se lee como «no hay versiones».
     */
    #[Test]
    public function tambien_lista_en_un_anio_pasado(): void
    {
        $id = $this->versionEn(self::ANIO_PASADO, 'La de 2024');

        // **El sujeto se coloca en el año pasado A MANO, y no con
        // `tokenDelPersonalLlanoDe()`, que para esto no sirve.** Ese ayudante elige
        // al usuario por su año, pero el token se saca entrando por
        // `login/credentials`, y **`Login::entrar()` mueve al usuario al periodo del
        // año actual** si estaba en otro (`app/Services/Login.php:188`). O sea que
        // pedirle un token «del año 7» devuelve uno del 8 y este test pasaría a
        // medir el año equivocado — con la lista vacía en 200, que es justo el fallo
        // silencioso contra el que avisa el docblock de ese mismo ayudante.
        // Comprobado, no supuesto: con él, `$user->year_id` sale **8**.
        //
        // **Sí hay ruta que mueve de año, y sería el camino mejor**:
        // `PUT years/useractive/{year_id}` (`YearsController::putUseractive`) busca el
        // periodo del mismo número en el año destino y escribe `users.periodo_id`. Se
        // construye aquí a mano de todas formas porque lo que este test fija es **el
        // filtro del controlador**, no cómo llega el usuario a ese año; el día que el
        // ayudante compartido aprenda a colocar al sujeto —tarea de `8myvc-af`—, esto
        // se sustituye por una llamada a esa ruta y el estado pasa a producirse **como
        // lo produce el producto** (16), que es lo que manda la casa.
        //
        // *Y una corrección que vale más que el dato: la primera versión de este
        // comentario afirmaba que esa ruta **no existía**. El `grep` que lo «demostró»
        // sí la encontraba —posiciones 18 y 19 de 19— y yo lo había cortado con
        // `| head`, o sea que leí el corte como si fuera la población. Es la regla de
        // `tools/` aplicada a la terminal: un resultado sin su población no distingue
        // «no hay» de «no miré».*
        $token = $this->tokenDelPersonalLlano();

        $periodo = DB::selectOne(
            'SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [self::ANIO_PASADO]
        );
        $this->assertNotNull($periodo, 'El año '.self::ANIO_PASADO.' no tiene periodos en el seed.');

        DB::update('UPDATE users SET periodo_id = ? WHERE id = ?',
            [$periodo->id, $this->usuarioLlanoDelPersonal()->id]);

        $filas = $this->listar($token)->assertStatus(200)->json();

        $this->assertSame([$id], array_column($filas, 'id'),
            'Un año pasado tiene versiones y se listan igual (decisión 13). Si el `SELECT` '
            .'filtrara por `y.actual`, aquí saldría una lista vacía en 200 — indistinguible '
            .'de «este año no tiene ninguna».');
    }

    /**
     * El veredicto viaja **como se guardó**, no recalculado: es el historial.
     */
    #[Test]
    public function el_veredicto_viaja_como_se_guardo(): void
    {
        $guardado = ['piezas_revisadas' => 312, 'ih' => ['completas' => 133, 'incompletas' => 1]];

        $this->versionEn($this->anioDelSujeto(), 'Con veredicto',
            null, (string) json_encode($guardado, JSON_UNESCAPED_UNICODE));

        $filas = $this->listar()->assertStatus(200)->json();

        $this->assertSame($guardado, $filas[0]['comprobaciones'],
            'El veredicto tiene que volver tal cual se escribió. Recalcularlo diría lo que el '
            .'servidor opina HOY de una versión comprobada con el código de otro día, que es '
            .'justo lo que el veredicto guardado existe para no perder.');
    }

    /**
     * Un veredicto ilegible **no se convierte en `null`**.
     *
     * `json_decode` devuelve `null` para un JSON roto y para el texto `"null"`, y las
     * dos lecturas acabarían en el mismo hueco de la respuesta. Un veredicto ilegible
     * se tiene que ver; uno borrado en silencio se lee como que no había ninguno —
     * que es el `[]` de la §2 otra vez.
     */
    #[Test]
    public function un_veredicto_ilegible_no_se_borra_en_silencio(): void
    {
        $roto = '{esto no es json';
        $this->versionEn($this->anioDelSujeto(), 'Con el veredicto roto', null, $roto);

        $filas = $this->listar()->assertStatus(200)->json();

        $this->assertSame($roto, $filas[0]['comprobaciones'],
            'Un veredicto que no se puede decodificar viaja tal cual. Con `null` no se '
            .'distinguiría de una versión que nunca tuvo veredicto.');
    }

    /**
     * Una versión de un usuario que ya no está **sigue saliendo**.
     *
     * `subida_por` es `users.id` **sin foránea a propósito** —el rastro sobrevive a
     * que la cuenta se borre—, así que el `JOIN` con `users` tiene que ser `LEFT`. Con
     * el `INNER`, borrar a quien subió haría desaparecer su versión del listado sin
     * que nada fallara.
     */
    #[Test]
    public function una_version_de_un_usuario_que_ya_no_existe_sigue_saliendo(): void
    {
        $id = $this->versionEn($this->anioDelSujeto(), 'La subió alguien que ya no está', 999999);

        $filas = collect($this->listar()->assertStatus(200)->json())->keyBy('id');

        $this->assertArrayHasKey($id, $filas->all(),
            'La versión ha desaparecido porque su autor no está en `users`: el JOIN tiene que ser LEFT.');
        $this->assertSame(999999, $filas[$id]['subida_por']);
        $this->assertNull($filas[$id]['subida_por_username'],
            'Sin fila en `users` no hay nombre, y eso se dice con null en vez de esconder la versión.');
    }

    /**
     * La más nueva primero, y el orden es por `id` y no por `created_at`.
     *
     * `Reloj::ahoraTexto()` tiene resolución de segundo: dos subidas del mismo segundo
     * empatan, y un empate deja el orden a merced del plan de MySQL — o sea distinto
     * entre dos llamadas iguales. `id` es monótono y no empata nunca.
     */
    #[Test]
    public function la_mas_nueva_va_primero(): void
    {
        $anio = $this->anioDelSujeto();
        $primera = $this->versionEn($anio, 'Primera');
        $segunda = $this->versionEn($anio, 'Segunda');

        $ids = array_column($this->listar()->assertStatus(200)->json(), 'id');

        $this->assertSame([$segunda, $primera], $ids,
            'El listado va de la más nueva a la más vieja: es lo que se mira primero.');
    }
}
