<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las tres rutas de `informes-recientes` — el historial de la pantalla nueva de
 * `/informes` en `app2`.
 *
 * ## Qué existe esto para cazar, y NINGUNO de los cinco da error solo
 *
 * Es la propiedad que decide qué se prueba aquí: esta familia no tiene un camino
 * que reviente. Todos sus fallos posibles **contestan 200 y guardan algo
 * distinto de lo que se pidió**, así que lo que se mira es la tabla y la huella,
 * nunca el código de respuesta.
 *
 * **1. Que la huella se acepte del cuerpo.** Es la que más cara sale y la que más
 * fácil se cuela: el front **tiene el mismo algoritmo escrito** para su
 * `localStorage`, así que aceptarla parece un ahorro evidente. En cuanto un
 * cliente la calcula, dos clientes que normalicen distinto —o el mismo antes y
 * después de un refactor— meten **dos filas para el mismo informe**, que es
 * exactamente lo que el `UNIQUE` existe para impedir. Y no se notaría: la
 * pantalla enseñaría el mismo informe dos veces y parecería un duplicado tonto.
 *
 * **2. Que la normalización deje de ordenar las claves.** `{grupo:1,periodo:2}` y
 * `{periodo:2,grupo:1}` son el mismo informe. Sin `ksort`, dos huellas y dos
 * filas — y el orden de las claves de un objeto JSON **no lo controla nadie**:
 * depende de en qué orden lo arme el cliente ese día.
 *
 * **3. Que `params` entre en la huella.** Son los textos de pintar
 * (`['Segundo A', 'periodo 1']`). Si entraran, **renombrar un grupo cambiaría la
 * huella y orfanaría la fila**: el mismo informe pasaría a ser dos, y el viejo
 * inalcanzable. Es un fallo que aparece semanas después del cambio que lo causa.
 *
 * **4. Que el recorte al tope se vaya al cliente.** El front ya recorta, así que
 * quitarlo de aquí no rompería ninguna pantalla **hoy**. Lo que lo hace falso es
 * que **dos pestañas a la vez se pasan del tope aunque las dos recorten**, y
 * entonces la tabla crece sin techo con 2.358 cuentas dentro.
 *
 * **5. Que `user_id` o `year_id` se lean del cuerpo.** Hoy salen de la sesión, y
 * ésa es la única razón por la que estas rutas no necesitan guard de propiedad.
 * El día que alguien acepte un `user_id` para «poder probarlo», la familia pasa a
 * escribir en el historial de otro sin que ningún guard se entere — porque no hay
 * ninguno que mire identificadores, y **no lo hay a propósito**.
 *
 * Contrato en `docs/migracion/ESTADO-ACTUAL.md`, casilla del 18 sep 2026.
 */
class InformesRecientesTest extends CasoDeContrato
{
    /** El mismo tope que el controlador. Escrito aquí a mano a propósito: si se lee de allí, cambiarlo no pone nada rojo. */
    private const TOPE = 6;

    /**
     * Las tres, con el cuerpo mínimo para llegar al guard.
     *
     * Van en un proveedor por lo mismo que las nueve de la plantilla: el control
     * que las hace valer es **«quitar `auth.personal` tiene que ponerlas rojas las
     * tres»**. Con sólo las escrituras cubiertas, un `getIndex` abierto dejaría el
     * historial de cualquiera a la vista de un alumno y nadie se enteraría.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function lasTresRutas(): array
    {
        return [
            'leer el historial' => ['getJson', []],
            'guardar un informe' => ['postJson', ['clave' => 'x', 'url' => '/x']],
            'vaciar el historial' => ['deleteJson', []],
        ];
    }

    #[Test]
    #[DataProvider('lasTresRutas')]
    public function test_un_alumno_no_toca_el_historial(string $verbo, array $cuerpo): void
    {
        $antes = $this->censo();

        $r = $this->llamar($verbo, $cuerpo, $this->tokenDe($this->usuarioDeTipo('Alumno')->username));

        $r->assertStatus(403);
        $this->assertSame($antes, $this->censo(),
            'Contestó 403 y escribió igual: el guard frena la respuesta pero no la escritura.');
    }

    #[Test]
    #[DataProvider('lasTresRutas')]
    public function test_un_acudiente_tampoco(string $verbo, array $cuerpo): void
    {
        $r = $this->llamar($verbo, $cuerpo, $this->tokenDe($this->usuarioDeTipo('Acudiente')->username));

        $r->assertStatus(403);
    }

    /**
     * **El caso principal: el mismo informe dos veces es UNA fila.**
     *
     * Y se comprueba que la segunda llamada **mueve la fecha**, no sólo que no
     * duplica: un `INSERT IGNORE` también dejaría una fila, y entonces el informe
     * que acabas de sacar se hundiría en la lista en vez de subir.
     */
    #[Test]
    public function test_el_mismo_informe_dos_veces_es_una_fila_y_sube(): void
    {
        $cuerpo = $this->informe(['grupo_id' => 109, 'periodo_a_calcular' => 1]);

        $primera = $this->pedir('postJson', $cuerpo);
        $primera->assertStatus(200);

        DB::update('UPDATE informes_recientes SET updated_at = ? WHERE id = ?',
            ['2020-01-01 00:00:00', $primera->json('id')]);

        $segunda = $this->pedir('postJson', $cuerpo);

        $this->assertSame(1, $this->censo(), 'El mismo informe dejó dos filas.');
        $this->assertSame($primera->json('id'), $segunda->json('id'), 'Insertó en vez de refrescar.');
        $this->assertNotSame('2020-01-01 00:00:00', $this->filaUnica()->updated_at,
            'No movió la fecha: el informe recién sacado se hundiría en la lista.');
    }

    /**
     * **La normalización, en sus tres formas, y las tres dan la MISMA huella.**
     *
     * Van juntas en un proveedor porque las tres prueban la misma propiedad desde
     * ángulos distintos, y porque lo que se compara es siempre contra el mismo
     * original — que es lo que hace que un fallo señale cuál de las tres se rompió.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function lasQueSonElMismoInforme(): array
    {
        return [
            'las claves al revés' => [['periodo_a_calcular' => 1, 'grupo_id' => 109]],
            'con un null de más' => [['grupo_id' => 109, 'periodo_a_calcular' => 1, 'periodo_id' => null]],
            'con una cadena vacía de más' => [['grupo_id' => 109, 'periodo_a_calcular' => 1, 'alumno_id' => '']],
        ];
    }

    #[Test]
    #[DataProvider('lasQueSonElMismoInforme')]
    public function test_la_huella_no_depende_del_orden_ni_de_las_vacias(array $eleccion): void
    {
        $original = $this->pedir('postJson', $this->informe(['grupo_id' => 109, 'periodo_a_calcular' => 1]));

        $otra = $this->pedir('postJson', $this->informe($eleccion));

        $this->assertSame($original->json('huella_parametros'), $otra->json('huella_parametros'),
            'Dos formas de escribir el MISMO informe dieron huellas distintas.');
        $this->assertSame(1, $this->censo(), 'Y por eso dejaron dos filas.');
    }

    /**
     * **`params` se guarda pero NO entra en la huella.**
     *
     * El día que entre, renombrar un grupo parte la fila en dos y la vieja queda
     * inalcanzable. Aquí se manda el mismo informe con los textos cambiados —que
     * es exactamente lo que pasa cuando el colegio renombra «Segundo A»— y tiene
     * que seguir siendo una fila, con los textos nuevos.
     */
    #[Test]
    public function test_renombrar_el_grupo_no_parte_la_fila_en_dos(): void
    {
        $eleccion = ['grupo_id' => 109, 'periodo_a_calcular' => 1];

        $antes = $this->pedir('postJson', $this->informe($eleccion, ['Segundo A', 'periodo 1']));
        $despues = $this->pedir('postJson', $this->informe($eleccion, ['2-A Mañana', 'periodo 1']));

        $this->assertSame($antes->json('huella_parametros'), $despues->json('huella_parametros'),
            'Los textos de pintar entraron en la huella: renombrar un grupo orfanaría la fila.');
        $this->assertSame(1, $this->censo());
        $this->assertSame(['2-A Mañana', 'periodo 1'], $despues->json('params'),
            'No refrescó los textos: el renglón seguiría pintando el nombre viejo.');
    }

    /**
     * **La huella NO se acepta del cuerpo, aunque venga con el nombre exacto.**
     *
     * Es el atajo que parece gratis porque el front ya la calcula. Si se aceptara,
     * este test guardaría la mentira que se le manda.
     */
    #[Test]
    public function test_una_huella_mandada_por_el_cliente_se_ignora(): void
    {
        $mentira = str_repeat('a', 64);

        $r = $this->pedir('postJson', array_merge($this->informe(['grupo_id' => 109]), ['huella_parametros' => $mentira]));

        $this->assertNotSame($mentira, $r->json('huella_parametros'),
            'Aceptó la huella del cliente: dos clientes que normalicen distinto duplicarán filas.');
        $this->assertNotSame($mentira, $this->filaUnica()->huella_parametros);
    }

    /**
     * **`user_id` y `year_id` salen de la sesión y no del cuerpo.**
     *
     * Es la única razón por la que esta familia no lleva guard de propiedad: no
     * acepta ningún identificador de persona. El día que lo acepte, no hay ningún
     * mecanismo detrás que lo pare.
     */
    #[Test]
    public function test_no_se_puede_escribir_en_el_historial_de_otro(): void
    {
        $yo = $this->usuarioLlanoDelPersonal();
        $otro = DB::selectOne('SELECT id FROM users WHERE id <> ? AND deleted_at IS NULL LIMIT 1', [$yo->id]);

        $this->pedir('postJson', array_merge($this->informe(['grupo_id' => 109]), [
            'user_id' => $otro->id,
            'year_id' => 99999,
        ]), $this->tokenDe($yo->username));

        $fila = $this->filaUnica();

        $this->assertSame((int) $yo->id, (int) $fila->user_id, 'Escribió en el historial de otra persona.');
        $this->assertNotSame(99999, (int) $fila->year_id, 'Aceptó el año del cuerpo.');
    }

    /**
     * **El recorte va en el servidor**, aunque el front también recorte.
     *
     * Se mandan TOPE+1 informes distintos y tienen que quedar TOPE, con el más
     * viejo fuera. Sin esto, dos pestañas a la vez hacen crecer la tabla sin techo.
     */
    #[Test]
    public function test_pasado_el_tope_se_va_el_mas_viejo(): void
    {
        for ($i = 1; $i <= self::TOPE + 1; $i++) {
            $this->pedir('postJson', array_merge($this->informe(['grupo_id' => $i], ["grupo {$i}"]), ['clave' => "informe-{$i}"]));
        }

        $this->assertSame(self::TOPE, $this->censo(), 'El recorte no está en el servidor.');

        $claves = array_column($this->pedir('getJson')->json(), 'clave');

        $this->assertNotContains('informe-1', $claves, 'Se quedó el más viejo en vez del más nuevo.');
        $this->assertSame('informe-'.(self::TOPE + 1), $claves[0], 'El último no salió el primero.');
    }

    /**
     * **Un texto largo es 422 y no una fila cortada.**
     *
     * `clave` es `varchar(64)` y este docker **no está en modo estricto**: un texto
     * más largo entraría recortado con un 200. **MariaDB 10.5, que es lo que corre
     * en los dieciséis, aborta** — así que dejarlo a la base es un 200 aquí y un
     * 500 allí. Se comprueba aquí y no en el esquema.
     */
    #[Test]
    public function test_una_clave_de_mas_de_64_es_422_y_no_se_guarda_cortada(): void
    {
        $r = $this->pedir('postJson', array_merge($this->informe(['grupo_id' => 109]), ['clave' => str_repeat('c', 65)]));

        $r->assertStatus(422);
        $this->assertSame(0, $this->censo(), 'Contestó 422 y guardó la fila cortada igual.');
    }

    /**
     * **`DELETE` acota a usuario + año**, y no se lleva el histórico de otros años.
     *
     * «Limpiar la lista» es limpiar lo que la pantalla enseña, y la pantalla enseña
     * el año en curso. Se siembra una fila de otro año directamente en la tabla
     * —la API no deja escribir en un año que no es el del token, que es justo lo
     * que el test anterior fija— y tiene que sobrevivir.
     */
    #[Test]
    public function test_vaciar_no_se_lleva_el_historial_de_otros_anios(): void
    {
        $mia = $this->pedir('postJson', $this->informe(['grupo_id' => 109]));
        $fila = $this->filaUnica();

        $otroAnio = DB::selectOne('SELECT id FROM years WHERE id <> ? LIMIT 1', [$fila->year_id]);

        if ($otroAnio === null) {
            $this->markTestSkipped('El seed sólo tiene un año: este caso no se puede montar.');
        }

        DB::insert(
            'INSERT INTO informes_recientes (user_id, year_id, clave, etiqueta, ruta, parametros, huella_parametros, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$fila->user_id, $otroAnio->id, 'del-año-pasado', 'X', '/x', '[]', str_repeat('b', 64)]
        );

        $this->pedir('deleteJson')->assertStatus(200);

        $this->assertSame(1, $this->censo(), 'Vació también el histórico de otro año.');
        $this->assertSame((int) $otroAnio->id, (int) $this->filaUnica()->year_id);
        $this->assertNotSame($mia->json('id'), (int) $this->filaUnica()->id);
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /** Un cuerpo de `POST` con lo que manda el front: `clave`, `url`, `params[]` y `eleccion{}`. */
    private function informe(array $eleccion, array $params = ['Segundo A', 'periodo 1']): array
    {
        return [
            'clave' => 'boletines-periodo',
            'url' => '/informes-nuevo/boletines-periodo/109/1',
            'params' => $params,
            'eleccion' => $eleccion,
        ];
    }

    /** Cuántas filas hay en la tabla entera — no sólo las mías: un fallo de alcance tiene que verse. */
    private function censo(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS c FROM informes_recientes')->c;
    }

    private function filaUnica(): object
    {
        return DB::selectOne('SELECT * FROM informes_recientes ORDER BY id DESC LIMIT 1');
    }

    /**
     * **El token se acuña UNA vez por test y se reutiliza.**
     *
     * `tokenDelPersonalLlano()` abre sesión de verdad, o sea una petición a
     * `login` por llamada. El caso del tope hace siete seguidas y con un token por
     * cada una **el limitador contesta 429** —120 por minuto— y el test falla por
     * un motivo que no tiene nada que ver con lo que mide. Se guarda y se reusa,
     * que además es lo que hace un cliente de verdad.
     */
    private ?string $miToken = null;

    private function pedir(string $verbo, array $cuerpo = [], ?string $token = null)
    {
        return $this->llamar($verbo, $cuerpo, $token ?? ($this->miToken ??= $this->tokenDelPersonalLlano()));
    }

    /**
     * `getJson` no acepta cuerpo y su segundo parámetro son las cabeceras: pasarle
     * el cuerpo ahí da un `TypeError` dentro de `json_encode` que no se parece en
     * nada a la causa. Va en un ayudante para que el proveedor trate las tres igual.
     */
    private function llamar(string $verbo, array $cuerpo, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        if ($verbo === 'getJson') {
            return $this->getJson('/api/informes-recientes', $cabeceras);
        }

        return $this->{$verbo}('/api/informes-recientes', $cuerpo, $cabeceras);
    }
}
