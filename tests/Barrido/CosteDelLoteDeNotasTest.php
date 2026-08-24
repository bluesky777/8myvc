<?php

namespace Tests\Barrido;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * Cuánto cuesta de verdad guardar **una columna de notas**: en lote contra de una
 * en una.
 *
 * **No es un test: imprime, no afirma.** Existe por la §7.c del
 * [plan de la pantalla de notas](../../docs/migracion/20-pantalla-de-notas.md):
 * `PUT notas/lote` tenía trece tests y **ninguna medición**, y la tabla de su §2
 * lleva escrita la palabra *estimado* porque estaba compuesta a partir de las
 * piezas medidas en otro sitio —los ~28 ms de arranque de la
 * [§1 del 02](../../docs/migracion/02-plan-rendimiento.md), los ~40–80 ms de
 * resolver quién pregunta de su §4, los ~1,7 ms del agregado de
 * [`coste-del-recalculo.php`](../../tools/coste-del-recalculo.php)— sin que nadie
 * cronometrara el endpoint entero. **Este repositorio distingue lo estimado de lo
 * medido**, y esto es lo que convierte una cosa en la otra.
 *
 *     docker exec -w /app/.worktrees/<sufijo> -e DB_TEST_DATABASE=simonbolivar_testing_<sufijo> \
 *         8myvc-app-1 php artisan test --group=barrido --filter=CosteDelLoteDeNotasTest
 *
 *     # y con otra columna u otro número de pasadas:
 *     -e LOTE_ALUMNOS=30 -e LOTE_PASADAS=21
 *
 * Va en el grupo `barrido`, que `phpunit.xml` excluye de la corrida normal, por lo
 * mismo que el otro que vive aquí: tarda, escribe, y lo que devuelve es un
 * informe. Y vive en `tests/` y no en `tools/` **porque cronometrar una escritura
 * es ejecutarla**: la transacción que envuelve cada test es lo único que hace eso
 * inocuo. Un script en `tools/` tendría que elegir entre no medir el camino real o
 * dejar notas escritas.
 *
 * ---
 *
 * ## Lo que mide, y lo que NO puede medir
 *
 * Se cronometran **tres bloques sobre la misma columna**, en la misma corrida:
 *
 * | Bloque | Qué es |
 * |---|---|
 * | `sueltas` | N peticiones `PUT notas/update/{id}`, que es lo que hace la planilla hoy |
 * | `lote` | **una** petición `PUT notas/lote` con las N notas dentro |
 * | `control` | N peticiones a `GET periodos`, que casi no hace nada |
 * | `recalculo` | N llamadas a `recalcularPorNota()` **sin petición de por medio** |
 *
 * **Los dos últimos son la mitad del experimento y no un adorno.** La afirmación
 * que hay que confirmar o tumbar no es «el lote es más rápido» —eso lo sabe
 * cualquiera— sino **de dónde sale la diferencia**: el plan dice que lo caro no es
 * el recálculo sino el coste fijo de resolver quién pregunta. Sin el `control` y
 * el `recalculo`, lo que cuesta una nota por encima del camino común sale por
 * **resta**, y una resta no dice de qué está hecha: un número grande en `sueltas`
 * no distingue *«resolver al usuario es caro»* de *«recalcular es caro»* de
 * *«escribir es caro»*, y las tres lecturas mandan a optimizar sitios distintos.
 * Midiendo los dos por separado, el reparto se **calcula** en vez de suponerse.
 *
 * **Lo que este montaje NO incluye, y por eso el ahorro real es MAYOR que el que
 * imprime:**
 *
 *   - **el arranque del framework.** `$this->putJson()` reutiliza la aplicación ya
 *     construida, así que los ~28 ms de la §1 del 02 se pagan una vez por proceso
 *     y no una por petición. En producción son N veces en `sueltas` y una en
 *     `lote`;
 *   - **php-fpm, nginx y la red.** Aquí no hay proceso nuevo ni socket;
 *   - **la simultaneidad**, que es lo que de verdad llena `Entry Processes`
 *     (§2 del 20). Esto mide **tiempo**, y el contador cuenta **peticiones
 *     dentro de PHP a la vez**. Un solo proceso midiendo en serie no puede ver
 *     eso.
 *
 * O sea que **todo lo que sale aquí es una cota inferior del ahorro.** Se dice
 * arriba y se repite en la salida porque un número medido se cita después sin su
 * asterisco.
 *
 * ## Por qué medianas y por qué alternando el orden
 *
 * Las tres lecciones que costó el *3× que no existía*
 * ([02](../../docs/migracion/02-plan-rendimiento.md)) valen enteras aquí, y ésta
 * es exactamente la misma familia de medición:
 *
 *   1. **Medir una vez es medir el estado de la caché.** Van 11 pasadas por
 *      defecto y se toma la mediana.
 *   2. **Un orden fijo entre dos variantes las compara con la caché de la otra.**
 *      La segunda siempre parece mejor. Aquí los tres bloques **rotan** de orden
 *      en cada pasada, y además la primera pasada entera se descarta.
 *   3. **Las filas leídas no dependen de la caché y el tiempo sí.** Por eso se
 *      cuenta también el **número de consultas** de cada bloque: cuando el tiempo
 *      y las consultas no cuenten la misma historia, la que miente es la del
 *      tiempo.
 *
 * ## El `throttle` se apaga para cronometrar, y se mide aparte
 *
 * Toda la API va detrás de `throttle:api` — **120 por minuto y por usuario**
 * ([RouteServiceProvider:63](../../app/Providers/RouteServiceProvider.php#L63)).
 * Con el limitador puesto, cronometrar 45 peticiones once veces daría 429 a partir
 * de la tercera pasada, y **un 429 es rapidísimo**: la medición saldría *mejor*
 * cuanto más roto estuviera el caso. Ése es justo el modo de fallo que este
 * repositorio persigue —un instrumento que miente con la cara del resultado— así
 * que además de apagarlo, **cada petición del bloque `sueltas` comprueba que
 * devolvió 200**, y el bloque aborta si alguna no lo hizo.
 *
 * Y como apagarlo esconde una parte de la respuesta, el límite se mide **en su
 * propio bloque** ({@see medirElLimite()}), que es lo que confirma o tumba la
 * sospecha de la §1 del 20 —«el error que sale cuando lleva muchos envíos a la
 * vez es un 429»— en vez de dejarla escrita como sospecha.
 */
#[Group('barrido')]
class CosteDelLoteDeNotasTest extends CasoDeContrato
{
    /** Cuántos alumnos tiene la columna que se cronometra. Una columna real son 30–45. */
    private const ALUMNOS_POR_DEFECTO = 45;

    /** Pasadas completas. Impar, para que la mediana sea un valor medido y no una media de dos. */
    private const PASADAS_POR_DEFECTO = 11;

    /**
     * La firma en el SQL de la agregación de `DefinitivasDeAsignatura::calcular()`.
     *
     * La misma que usa `GuardarNotasEnLoteTest`, y por la misma razón: es un trozo
     * de la **fórmula**, no del `FROM`, así que no cuenta las otras consultas del
     * recálculo —el UPSERT, el sello, el porcentaje—.
     */
    private const FIRMA_DEL_AGREGADO = '(s.porcentaje / 100) * n.nota';

    /** @var list<string> */
    private array $informe = [];

    public function test_cuanto_cuesta_guardar_una_columna_de_notas(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $alumnos = max(1, (int) (getenv('LOTE_ALUMNOS') ?: self::ALUMNOS_POR_DEFECTO));
        $pasadas = max(3, (int) (getenv('LOTE_PASADAS') ?: self::PASADAS_POR_DEFECTO));

        [$token, $ctx] = $this->columnaDeNotas($alumnos);

        $this->linea('');
        $this->linea('Coste de guardar una columna de notas — `PUT notas/lote` contra N `PUT notas/update`');
        $this->linea(str_repeat('=', 88));
        $this->poblacion($ctx, $pasadas);

        $n = count($ctx['columna']);

        $medidas = ['sueltas' => [], 'lote' => [], 'control' => [], 'recalculo' => []];
        $consultas = ['sueltas' => [], 'lote' => [], 'control' => [], 'recalculo' => []];
        $agregados = ['sueltas' => [], 'lote' => [], 'control' => [], 'recalculo' => []];

        // Los tres bloques ROTAN de orden en cada pasada. Con un orden fijo, el
        // segundo cobra el buffer pool que calentó el primero — que es literalmente
        // lo que produjo el 3× que no existía.
        $orden = ['sueltas', 'lote', 'control', 'recalculo'];

        for ($pasada = 0; $pasada <= $pasadas; $pasada++) {
            $rotado = $orden;

            for ($giro = 0; $giro < $pasada % count($orden); $giro++) {
                $rotado[] = array_shift($rotado);
            }

            foreach ($rotado as $bloque) {
                $valor = 10 + ($pasada % 80);   // dentro de la escala, y distinto cada vez

                $resultado = match ($bloque) {
                    'sueltas' => $this->cronometrar(fn () => $this->guardarUnaAUna($token, $ctx['columna'], $valor)),
                    'lote' => $this->cronometrar(fn () => $this->guardarEnLote($token, $ctx['columna'], $valor)),
                    'control' => $this->cronometrar(fn () => $this->pedirNVeces($token, $n)),
                    'recalculo' => $this->cronometrar(fn () => $this->recalcularNVeces($ctx['columna'], (int) $ctx['profesor'])),
                    // El `default` no es ceremonia de larastan: un bloque nuevo en
                    // `$orden` sin su rama aquí saldría en la tabla con tiempo cero,
                    // que es un número plausible y falso. Que reviente.
                    default => throw new \LogicException('Bloque sin cronómetro: '.$bloque),
                };

                // **La primera pasada entera se descarta**: es la que paga la
                // compilación de OPcache, el primer plan de cada consulta y el
                // buffer pool frío. Se ejecuta igual, para que la de índice 1
                // encuentre el terreno como lo encontrarán las demás.
                if ($pasada === 0) {
                    continue;
                }

                $medidas[$bloque][] = $resultado['ms'];
                $consultas[$bloque][] = $resultado['consultas'];
                $agregados[$bloque][] = $resultado['agregados'];
            }
        }

        $this->tabla($n, $medidas, $consultas, $agregados);
        $this->lectura($n, $medidas, $consultas);
        $this->medirElLimite($token, $ctx['columna']);

        $this->volcar();

        // No hay nada que afirmar: esto imprime. Lo único que se comprueba es que
        // llegó hasta el final con la población que dijo — un informe que se corta
        // a la mitad y no falla es la forma de que un número parcial se cite como
        // si fuera el total.
        $this->assertCount($pasadas, $medidas['lote'],
            'El informe no completó sus pasadas: los números de arriba no son los que dice la cabecera.');
    }

    /**
     * Las N notas de la columna, **una petición por nota**, que es lo que hace la
     * planilla hoy.
     *
     * Cada respuesta se comprueba. Un 429, un 400 por el interruptor del periodo o
     * un 422 son todos **más rápidos** que guardar de verdad, así que sin esta
     * comprobación el bloque roto sería el que mejor mide.
     */
    private function guardarUnaAUna(string $token, array $columna, int $valor): void
    {
        foreach ($columna as $notaId) {
            $respuesta = $this->withToken($token)->putJson('/api/notas/update/'.$notaId, ['nota' => $valor]);

            if ($respuesta->getStatusCode() !== 200) {
                $this->fail('Una `notas/update` del bloque contestó '.$respuesta->getStatusCode()
                    .'. Lo que se estaría cronometrando es el camino de error, que es más rápido: '
                    .$respuesta->getContent());
            }
        }
    }

    /** Las mismas N notas, **una sola petición**. */
    private function guardarEnLote(string $token, array $columna, int $valor): void
    {
        $respuesta = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => $valor], $columna),
        ]);

        if ($respuesta->getStatusCode() !== 200) {
            $this->fail('El lote contestó '.$respuesta->getStatusCode().': '.$respuesta->getContent());
        }

        $guardadas = $respuesta->json('guardadas');

        if ($guardadas !== count($columna)) {
            $this->fail('El lote dijo guardar '.var_export($guardadas, true).' de '.count($columna)
                .' notas. Un lote que guarda menos también tarda menos, y el número de arriba sería mentira.');
        }
    }

    /**
     * El control: N peticiones que **casi no hacen nada** salvo el camino común.
     *
     * `GET periodos` es la ruta con la que el 02 §4 midió la resolución del token
     * —«pasó de 9 consultas a 7, y de las 7 sólo una es del endpoint»—, así que es
     * la que hace comparables los dos documentos. Lo que mide este bloque es
     * **el precio de entrar**, no el de hacer algo.
     */
    private function pedirNVeces(string $token, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $respuesta = $this->withToken($token)->getJson('/api/periodos');

            if ($respuesta->getStatusCode() !== 200) {
                $this->fail('El control contestó '.$respuesta->getStatusCode()
                    .', así que no está midiendo el camino común sino el de error.');
            }
        }
    }

    /**
     * El recálculo de la definitiva, **N veces y sin petición de por medio**.
     *
     * Es el bloque que convierte el resto en una medida. Sin él, lo que cuesta una
     * nota por encima del camino común sale por **resta** —`sueltas − control`— y
     * una resta no dice de qué está hecha: dentro caben el UPDATE, la bitácora, el
     * recálculo y cualquier cosa que `putUpdate` haga y `getIndex` de periodos no.
     * Y justo ahí está la afirmación que hay que confirmar o tumbar, que es **de
     * dónde sale el coste**, no cuánto es.
     *
     * Se llama al servicio directamente, sin HTTP, que es la única forma de
     * cronometrar **sólo eso**. Es el mismo `recalcularPorNota` que llama
     * `putUpdate`, una vez por nota — o sea exactamente el trabajo que el lote
     * colapsa en uno.
     */
    private function recalcularNVeces(array $columna, int $userId): void
    {
        foreach ($columna as $notaId) {
            DefinitivasDeAsignatura::recalcularPorNota((int) $notaId, $userId);
        }
    }

    /**
     * **El límite de peticiones, medido y no supuesto.**
     *
     * La §1 del [20](../../docs/migracion/20-pantalla-de-notas.md) deja escrito
     * como *sospecha* que el error que ven los docentes «cuando lleva muchos
     * envíos a la vez» es un 429: tres columnas de 45 son 135 peticiones contra un
     * cubo de 120 por minuto. Aquí se comprueba, que es más barato que abrir la
     * pestaña de red de un colegio.
     *
     * Se hace **al final y con el limitador puesto otra vez**, en su propio
     * bloque, porque gastar el cubo antes de cronometrar contaminaría todo lo de
     * arriba. Y el mismo camino en lote son **tres** peticiones, que es la
     * comparación entera.
     */
    private function medirElLimite(string $token, array $columna): void
    {
        // Se vuelve a poner: el `withoutMiddleware` del principio lo había
        // sustituido por un pasamanos para poder cronometrar.
        $this->app->forgetInstance(ThrottleRequests::class);

        $tope = null;
        $limite = 3 * count($columna);

        for ($i = 1; $i <= $limite; $i++) {
            $codigo = $this->withToken($token)
                ->putJson('/api/notas/update/'.$columna[$i % count($columna)], ['nota' => 30])
                ->getStatusCode();

            if ($codigo === 429) {
                $tope = $i;

                break;
            }
        }

        $this->linea('');
        $this->linea('El límite de peticiones (`throttle:api`, 120/min por usuario)');
        $this->linea(str_repeat('-', 88));

        if ($tope === null) {
            $this->linea(sprintf(
                '  %d peticiones seguidas y NINGUNA dio 429. El cubo no se agotó: o el límite no está '
                .'puesto en este entorno, o el contador venía ya gastado. La sospecha de la §1 del 20 '
                .'NO queda confirmada por esta corrida.',
                $limite
            ));
        } else {
            $this->linea(sprintf(
                '  La petición número %d de %d devolvió **429**. Tres columnas de %d notas son %d '
                .'peticiones: las últimas se van con Too Many Requests, que es exactamente el error '
                .'que reportan los docentes (§1 del 20). En lote, esas tres columnas son 3 peticiones.',
                $tope, $limite, count($columna), $limite
            ));
        }
    }

    /**
     * Cronometra una acción y cuenta lo que ejecutó.
     *
     * Devuelve el tiempo **y** el número de consultas **y** cuántas veces corrió la
     * agregación del recálculo, porque son tres historias distintas y sólo una
     * depende de la caché. `hrtime()` y no `microtime()`: es monótono, así que un
     * ajuste de reloj a mitad de la pasada no puede dar un tiempo negativo.
     *
     * @return array{ms: float, consultas: int, agregados: int}
     */
    private function cronometrar(callable $accion): array
    {
        $consultas = 0;
        $agregados = 0;

        // No hay `DB::unlisten`. El oyente se queda hasta el final del test, así
        // que los contadores se pasan por referencia y se leen al momento: cada
        // llamada tiene los suyos y los de las llamadas anteriores ya no se miran.
        DB::listen(function ($consulta) use (&$consultas, &$agregados) {
            $consultas++;

            if (str_contains($consulta->sql, self::FIRMA_DEL_AGREGADO)) {
                $agregados++;
            }
        });

        $desde = hrtime(true);
        $accion();
        $ms = (hrtime(true) - $desde) / 1e6;

        return ['ms' => $ms, 'consultas' => $consultas, 'agregados' => $agregados];
    }

    /**
     * Una columna de verdad: **N alumnos del grupo más grande del seed**, sobre una
     * asignatura con cuatro subunidades.
     *
     * La columna que se guarda es **una subunidad × N alumnos**, que es lo que
     * hace un docente al bajar por la planilla. Las otras tres subunidades existen
     * porque el agregado del recálculo las lee todas: una asignatura con una sola
     * columna mediría un recálculo que no se parece al real.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function columnaDeNotas(int $cuantos): array
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $periodoId = (int) $suyo->id;

        // El grupo MÁS GRANDE, no el primero: quien se queja de la planilla es el
        // profesor del grupo grande. Es el mismo criterio que `coste-del-recalculo.php`.
        $asignatura = DB::selectOne(
            'SELECT a.id, a.grupo_id, g.nombre AS grupo, COUNT(DISTINCT m.alumno_id) AS alumnos
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
               INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
              GROUP BY a.id, a.grupo_id, g.nombre
              ORDER BY alumnos DESC, a.id
              LIMIT 1',
            [$periodoId]
        );

        $this->assertNotNull($asignatura, 'El seed no tiene una asignatura con matrículas en el año del usuario.');

        $alumnos = DB::select(
            'SELECT DISTINCT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT '.$cuantos,
            [$asignatura->grupo_id]
        );

        $this->assertNotEmpty($alumnos, 'El montaje necesita alumnos matriculados.');

        $columna = [];
        $notasPuestas = 0;

        foreach ([1, 2] as $numeroUnidad) {
            $unidadId = DB::table('unidades')->insertGetId([
                'asignatura_id' => $asignatura->id,
                'periodo_id' => $periodoId,
                'definicion' => 'UNIDAD DE MEDICION '.$numeroUnidad,
                'porcentaje' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([1, 2] as $numeroSub) {
                $subId = DB::table('subunidades')->insertGetId([
                    'unidad_id' => $unidadId,
                    'definicion' => 'SUB '.$numeroUnidad.'.'.$numeroSub,
                    'porcentaje' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($alumnos as $alumno) {
                    $notaId = DB::table('notas')->insertGetId([
                        'subunidad_id' => $subId,
                        'alumno_id' => $alumno->alumno_id,
                        'nota' => 20,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $notasPuestas++;

                    // La columna que se cronometra es UNA subunidad por todos los
                    // alumnos. Las otras tres están para que el agregado lea lo que
                    // leería de verdad.
                    if ($numeroUnidad === 1 && $numeroSub === 1) {
                        $columna[] = $notaId;
                    }
                }
            }
        }

        // El estado de partida lo deja el propio servicio, igual que en el test de
        // contrato: así lo que se cronometra es el disparador y no la primera
        // creación de las filas de `notas_finales`.
        DefinitivasDeAsignatura::recalcular((int) $asignatura->id, $periodoId, (int) $profesor->id);

        return [$token, [
            'asignatura' => (int) $asignatura->id,
            'grupo' => $asignatura->grupo,
            'grupo_id' => (int) $asignatura->grupo_id,
            'periodo' => $periodoId,
            'alumnos_del_grupo' => (int) $asignatura->alumnos,
            'notas_de_la_asignatura' => $notasPuestas,
            'columna' => $columna,
            'profesor' => (int) $profesor->id,
        ]];
    }

    /**
     * La población y la máquina, antes de cualquier número.
     *
     * **Ninguna herramienta de este repositorio imprime un resultado sin decir
     * sobre cuántos.** Aquí además hace falta la máquina: un tiempo sin la máquina
     * en la que se tomó no se puede comparar con el de la semana que viene, y se
     * cita igual.
     */
    private function poblacion(array $ctx, int $pasadas): void
    {
        $mysql = DB::selectOne('SELECT VERSION() AS v');
        $nucleos = (int) trim((string) @shell_exec('nproc 2>/dev/null')) ?: 0;

        $this->linea(sprintf('  Población · columna de **%d notas** (una subunidad × %d alumnos)', count($ctx['columna']), count($ctx['columna'])));
        $this->linea(sprintf('              grupo `%s` (id %d), %d alumnos matriculados en total',
            $ctx['grupo'], $ctx['grupo_id'], $ctx['alumnos_del_grupo']));
        $this->linea(sprintf('              asignatura %d, periodo %d, %d notas en la asignatura (4 subunidades)',
            $ctx['asignatura'], $ctx['periodo'], $ctx['notas_de_la_asignatura']));
        $this->linea(sprintf('              base `%s`', DB::connection()->getDatabaseName()));
        $this->linea(sprintf('              %d pasadas medidas + 1 descartada, orden rotado, se toma la MEDIANA', $pasadas));
        $this->linea('');
        $this->linea(sprintf('  Máquina   · %s', php_uname()));
        $this->linea(sprintf('              PHP %s · OPcache %s · MySQL %s%s',
            PHP_VERSION,
            function_exists('opcache_get_status') && @opcache_get_status(false) !== false ? 'encendido' : 'apagado',
            $mysql->v ?? '?',
            $nucleos > 0 ? ' · '.$nucleos.' núcleos' : ''));
        $this->linea('              (contenedor `8myvc-app-1`; la base va en otro contenedor, así que');
        $this->linea('               cada consulta paga un salto de red local que en el servidor es un socket)');
        $this->linea(sprintf('  Carga     · antes: %s', $this->carga()));
    }

    /**
     * La carga de la máquina, **impresa junto al número y no aparte**.
     *
     * La noche del 24 ago 2026 esta máquina estaba al 97% de swap con catorce
     * sesiones vivas, y un cronómetro tomado ahí mide el swap y no el endpoint. Lo
     * que sobrevive a eso es **la razón** entre los dos bloques, porque están
     * alternados en la misma ventana y la carga se cancela entre ellos; lo que no
     * sobrevive son los milisegundos absolutos.
     *
     * Se imprime al principio y al final: si las dos lecturas son muy distintas,
     * la ventana no fue la misma para todos los bloques y **la razón tampoco vale**.
     */
    private function carga(): string
    {
        $loadavg = @file_get_contents('/proc/loadavg');

        return $loadavg === false ? 'desconocida' : trim(explode(' ', $loadavg)[0]).' (1 min, dentro del contenedor)';
    }

    /** @param array<string, list<float>> $medidas */
    private function tabla(int $n, array $medidas, array $consultas, array $agregados): void
    {
        $this->linea('');
        $this->linea(sprintf('  %-34s %10s %10s %10s %10s %10s', '', 'mediana', 'mejor', 'peor', 'consultas', 'agregados'));
        $this->linea('  '.str_repeat('-', 86));

        $filas = [
            'sueltas' => sprintf('%d × PUT notas/update', $n),
            'lote' => sprintf('1 × PUT notas/lote (%d notas)', $n),
            'control' => sprintf('%d × GET periodos (el precio de entrar)', $n),
            'recalculo' => sprintf('%d × recalcularPorNota() a pelo', $n),
        ];

        foreach ($filas as $clave => $titulo) {
            $this->linea(sprintf(
                '  %-34s %9s %9s %9s %10s %10s',
                $titulo,
                $this->ms($this->mediana($medidas[$clave])),
                $this->ms(min($medidas[$clave])),
                $this->ms(max($medidas[$clave])),
                (int) $this->mediana(array_map('floatval', $consultas[$clave])),
                (int) $this->mediana(array_map('floatval', $agregados[$clave])),
            ));
        }
    }

    /**
     * Lo que dicen los números, escrito aquí y no dejado al lector.
     *
     * Un informe que imprime tres tiempos y se calla deja que quien lo lea elija la
     * lectura que ya traía. Las dos que importan —de dónde sale el coste, y qué
     * parte de él quita el lote— se calculan y se dicen.
     */
    private function lectura(int $n, array $medidas, array $consultas): void
    {
        $sueltas = $this->mediana($medidas['sueltas']);
        $lote = $this->mediana($medidas['lote']);
        $control = $this->mediana($medidas['control']);
        $recalculo = $this->mediana($medidas['recalculo']);

        $porPeticion = $sueltas / $n;
        $fijo = $control / $n;
        $recalculoPorNota = $recalculo / $n;
        $resto = $porPeticion - $fijo - $recalculoPorNota;

        $this->linea('');
        $this->linea('  Lectura — de dónde sale el coste de una nota');
        $this->linea('  '.str_repeat('-', 86));
        $this->linea(sprintf('  Una `notas/update` cuesta %s, y se reparte así:', $this->ms($porPeticion)));
        $this->linea('');
        $this->linea(sprintf('    %-46s %10s   %3d%%', 'el camino común (resolver quién pregunta y volver)',
            $this->ms($fijo), (int) round(100 * $fijo / max($porPeticion, 0.0001))));
        $this->linea(sprintf('    %-46s %10s   %3d%%', 'recalcular la definitiva de esa nota',
            $this->ms($recalculoPorNota), (int) round(100 * $recalculoPorNota / max($porPeticion, 0.0001))));
        $this->linea(sprintf('    %-46s %10s   %3d%%', 'lo demás (UPDATE, bitácora, enrutar, serializar)',
            $this->ms($resto), (int) round(100 * $resto / max($porPeticion, 0.0001))));
        $this->linea('');
        $this->linea('  **El lote se lleva las dos primeras**: el camino común una vez en vez de N, y un');
        $this->linea('  recálculo por par (asignatura, periodo) en vez de uno por nota. Lo tercero lo paga');
        $this->linea('  igual, porque es el trabajo de escribir la nota y hay que escribirla.');
        $this->linea('');
        $this->linea(sprintf('  La columna entera: %s de una en una contra %s en lote → **%.1f×**.',
            $this->ms($sueltas), $this->ms($lote), $lote > 0 ? $sueltas / $lote : 0));
        $this->linea(sprintf('  En consultas: %d contra %d, que es el número que NO depende de la carga.',
            (int) $this->mediana(array_map('floatval', $consultas['sueltas'])),
            (int) $this->mediana(array_map('floatval', $consultas['lote']))));

        // La razón de las mejores pasadas, que es la de la máquina menos ocupada.
        // Con la máquina cargada todo se hace más lento **sumando**, y una suma
        // igual en los dos lados ACERCA la razón a 1: o sea que la carga no
        // exagera esta ventaja, la esconde. Decirlo importa porque el número de
        // arriba se va a citar sin la máquina al lado.
        $mejorRazon = min($medidas['lote']) > 0 ? min($medidas['sueltas']) / min($medidas['lote']) : 0;

        $this->linea('');
        $this->linea(sprintf('  Y entre las pasadas MEJORES —las de la máquina menos ocupada— la razón es **%.1f×**.', $mejorRazon));
        $this->linea('  La carga no exagera esta ventaja: la esconde. Se suma casi igual a los dos lados,');
        $this->linea('  y una suma igual a los dos lados acerca cualquier razón a 1.');
        $this->linea('');
        $this->linea('  Y todo esto es una COTA INFERIOR del ahorro real. Aquí NO se paga:');
        $this->linea(sprintf('    · el arranque del framework por petición (~28 ms, 02 §1): %d veces sueltas, 1 en lote;', $n));
        $this->linea('    · php-fpm, nginx ni la red;');
        $this->linea('    · y OPcache está APAGADO en el CLI (`opcache.enable_cli=Off`) y encendido en fpm,');
        $this->linea('      así que ni siquiera es el mismo PHP el que atiende en producción.');
        $this->linea('  Lo que sí es de verdad y no depende de nada de eso son **las consultas**.');
        $this->linea('');
        $this->linea(sprintf('  Carga · después: %s', $this->carga()));
        $this->linea('  Si esta carga y la de la cabecera son muy distintas, los bloques no midieron en la');
        $this->linea('  misma ventana y **la razón tampoco vale**: hay que repetirlo.');
    }

    /** @param list<float> $valores */
    private function mediana(array $valores): float
    {
        sort($valores);
        $mitad = intdiv(count($valores), 2);

        return count($valores) % 2 === 1
            ? $valores[$mitad]
            : ($valores[$mitad - 1] + $valores[$mitad]) / 2;
    }

    private function ms(float $ms): string
    {
        return number_format($ms, $ms < 10 ? 2 : 1, ',', '.').' ms';
    }

    private function linea(string $texto): void
    {
        $this->informe[] = $texto;
    }

    /**
     * Se acumula y se vuelca al final, no se imprime sobre la marcha.
     *
     * Es la primera de las cosas que la segunda pasada del otro barrido encontró
     * **en el barrido mismo**: una respuesta que envía su propio contenido vacía el
     * buffer de salida y se lleva por delante las líneas ya escritas. Decía «17
     * rutas» y enseñaba once.
     */
    private function volcar(): void
    {
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $this->informe).PHP_EOL.PHP_EOL);
    }
}
