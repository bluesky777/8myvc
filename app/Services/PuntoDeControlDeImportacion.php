<?php

namespace App\Services;

use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Por dónde iba una importación cuando el servidor la cortó.
 *
 * Es el §1 de docs/migracion/09-pendientes.md. `max_execution_time` está en
 * 300 s en la cuenta de cPanel **porque las importaciones de alumnos tardaban
 * mucho**; para poder bajarlo, la importación tiene que dejar de ser una sola
 * petición que o entra entera o se pierde. Este objeto es lo que la parte en
 * trozos recuperables sin cambiarle el contrato a ninguno de los cuatro
 * clientes: el importador sigue respondiendo lo mismo, solo que volver a subir
 * el archivo continúa en vez de empezar.
 *
 * **La granularidad es la fila, y el avance se escribe DENTRO de la transacción
 * de la fila.** Esa es toda la garantía: una fila está aplicada si y solo si el
 * punto de control la da por hecha. El plan original decía «por lotes, de N en
 * N», y se hizo así en su lugar por dos razones medidas:
 *
 * - Los lotes se pensaron contra la memoria, y la memoria no es el problema:
 *   `memory_limit` son 768M y una hoja de un colegio entero cabe de sobra. Lo
 *   que se agota es el tiempo.
 * - Anotar de N en N obliga a reprocesar hasta N-1 filas al reanudar, y
 *   reprocesar una fila de alumno **no** es inocuo: el camino de acudientes
 *   inserta sin mirar si ya estaba. Con la fila entera en una transacción no se
 *   reprocesa ninguna, y la duplicación deja de ser posible en vez de ser
 *   improbable.
 *
 * El coste es un UPDATE más por alumno sobre las ocho escrituras que ya hace
 * cada fila, y la transacción que lo envuelve ahorra los `fsync` sueltos de esas
 * ocho: no se paga, se cambia de sitio.
 *
 * **Dos importaciones a la vez del mismo archivo** comparten la fila y se pisan
 * el avance. No se ha resuelto: hoy tampoco se resolvía —dos secretarías subiendo
 * la misma hoja al mismo tiempo se pisaban los datos, que es peor— y arreglarlo
 * de verdad es un `SELECT ... FOR UPDATE` que bloquea la segunda petición
 * durante los 300 s de la primera. Se anota para que quien lo vea sepa que se
 * miró.
 *
 * **La hora: Bogotá, como todo lo demás, desde el 22 sep 2026.** Esta tabla era
 * la ÚNICA excepción del repo —se escribía con `now()`, o sea en UTC— y estaba
 * declarada como tal en `Tests\Contrato\RelojUnicoTest`. El motivo era que
 * `inicio` y `fin` sólo se restan entre sí, así que la zona no cambiaba ningún
 * resultado; y el motivo por el que NO se movía antes era que las filas viejas
 * se quedarían cinco horas por delante, dejando la columna con dos relojes.
 *
 * **Decisión de Joseth, y lo que la desbloqueó fue mirar el producto en vez del
 * código: la tabla nació el 20 ago 2026 y NINGÚN colegio la ha usado todavía.**
 * La importación de alumnos es para principios de año y la de notas la está
 * construyendo el front ahora mismo. Sin filas viejas no hay dos relojes que
 * crear, así que la mudanza sale gratis y no lleva migración detrás. Lo que
 * habría sido «pisar datos con su rastro delante» es, aquí, cambiar tres letras.
 *
 * > **Antes de desplegar esto, comprobarlo, que es la premisa entera:**
 * > `SELECT COUNT(*) FROM importaciones;` en los diecisiete. Si alguno tiene
 * > filas, esas fechas se quedan en UTC y la columna sí gana el segundo reloj —
 * > y entonces la decisión vuelve a estar abierta.
 *
 * **Qué NO cubre.** Si la secretaría, en vez de volver a subir el mismo archivo,
 * exporta uno nuevo y sube ese, la huella cambia y esto no reanuda nada. No hace
 * falta que lo haga: la hoja recién exportada ya trae el `id` de los alumnos que
 * sí entraron, y el importador los actualiza en vez de crearlos. Los dos caminos
 * reales están cubiertos, cada uno por su lado.
 */
class PuntoDeControlDeImportacion
{
    public const EN_PROCESO = 'en_proceso';

    public const COMPLETADA = 'completada';

    public const FALLIDA = 'fallida';

    /**
     * **El nombre de quien empezó una importación, nunca su número.**
     *
     * Vive en una constante porque lo usan dos consultas —{@see pendienteDe} y
     * {@see laDeLaHuella}— y las dos se lo enseñan a la misma persona: si una
     * cayera al `username` y la otra no, la misma importación tendría dos nombres
     * en dos pantallas.
     *
     * **El `username` no es una red por si acaso: es el caso NORMAL.** Medido el
     * 20 sep 2026 en la copia de desarrollo: de las 22 cuentas de tipo `Usuario`
     * —los administrativos— ninguna tiene ficha en `profesores`. Unir sólo contra
     * `profesores` habría dejado el nombre vacío justo para todos ellos.
     */
    private const QUIEN_LA_EMPEZO = 'COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(p.nombres, ""), " ", COALESCE(p.apellidos, ""))), ""),
                        u.username
                    )';

    /** Las uniones que {@see QUIEN_LA_EMPEZO} necesita. Van juntas o no van. */
    private const UNIONES_DEL_NOMBRE = 'LEFT JOIN users u ON u.id = i.created_by
             LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL';

    /**
     * Las claves de una hoja del acta que se **suman** entre pasadas.
     *
     * Van declaradas y no deducidas de `is_numeric` por una razón que se ve de
     * inmediato en cuanto falta: `asignatura_id` es un número y **no se suma**.
     * Con la regla automática, una hoja que apareciera en dos tandas acabaría
     * apuntando a la asignatura 604 porque 302 + 302 son 604, y el acta diría que
     * las notas entraron en una asignatura que no existe.
     *
     * **Y las que faltan en esta lista faltan a propósito**, que es la parte que
     * no se adivina. Lo que se suma tiene que ser *lo que hizo esta pasada*, y sólo
     * lo es cuando la pasada siguiente **no lo vuelve a contar**:
     *
     * - `escritas`, `borradas`, `ausencias_*` — se suman: las filas que el punto de
     *   control da por hechas ni se estudian en la pasada siguiente, así que cada
     *   escritura se cuenta una vez y sólo una.
     * - `se_quedan_fuera` — **se suma sólo si la hoja entró**, y se sustituye si no.
     *   Ver {@see juntarLasHojas}.
     * - Las filas descartadas **no son un contador**: viajan como lista de
     *   identificadores en `descartadas` y se unen sin repetir, porque una fila que
     *   no se puede escribir tampoco se marca como hecha y **la vuelve a diagnosticar
     *   cada pasada**. Sumarlas diría que una importación reanudada tres veces
     *   descartó el triple de filas de las que tenía el archivo.
     *
     * @var list<string>
     */
    private const CONTADORES_DE_HOJA = [
        'escritas', 'borradas', 'ausencias_creadas', 'ausencias_borradas', 'indicadores_creados',
    ];

    /**
     * Lo que en una hoja del acta es un **conjunto** y no un número: se une sin
     * repetir.
     *
     * Las dos por el mismo motivo —lo que no se pudo hacer se vuelve a diagnosticar
     * en cada pasada— y por eso llevan identidad propia: los motivos son su propio
     * texto, y las filas descartadas el `id` estable que les pone la F6
     * (`hoja` + fila + tipo), que es el mismo en las dos tandas.
     *
     * @var list<string>
     */
    private const LISTAS_DE_HOJA = ['motivos', 'descartadas'];

    /** Mapa `nombre de hoja` => última fila (base 0) que se sabe aplicada. */
    private array $avance;

    private function __construct(
        private readonly int $id,
        private readonly bool $reanudada,
        array $avance,
        private int $filas,
    ) {
        $this->avance = $avance;
    }

    /**
     * Abre el punto de control de este archivo: continúa el que hubiera a medias
     * o empieza uno.
     *
     * La huella es del CONTENIDO, no del nombre: la secretaría sube tres veces
     * `alumnos.xlsx` y son tres archivos distintos.
     *
     * Se reanuda cualquier estado que no sea 'completada'. Una importación que
     * terminó bien no bloquea volver a subir el mismo archivo —eso es lo que se
     * hace para corregir cuatro celdas— así que esa arranca de cero, como
     * siempre.
     */
    /**
     * El nombre del cerrojo que impide que dos peticiones reanuden lo mismo.
     *
     * **Cabe en 64 caracteres, que es el tope de `GET_LOCK` en MySQL y MariaDB**
     * — por eso la huella va recortada a 32: son 128 bits de un sha256, que no
     * se adivinan y no colisionan por accidente entre dos archivos de un mismo
     * colegio. Pasarse del tope no da error: **trunca**, y dos nombres distintos
     * pasarían a ser el mismo cerrojo.
     */
    public static function cerrojo(string $tipo, string $huella, int $year): string
    {
        return 'myvc:imp:'.$tipo.':'.$year.':'.substr($huella, 0, 32);
    }

    /**
     * Toma el cerrojo, o dice que no pudo. **No espera.**
     *
     * ## Por qué un cerrojo de MySQL y no una columna
     *
     * Porque **se suelta solo cuando se cae la conexión**, que es justo el caso
     * que hay que cubrir: si el proceso muere a media importación, el cerrojo
     * desaparece con él y la siguiente petición puede continuar. Una columna
     * `tomada_at` haría falta limpiarla con un cron —que en cPanel no hay— y el
     * primer corte dejaría la importación bloqueada hasta que alguien lo mirara
     * a mano.
     *
     * Y no cuesta migración, ni instantánea, ni tocar las dieciséis bases.
     *
     * ## Por qué no espera (`0` de tiempo de espera)
     *
     * Porque quien llega segundo es **otra pestaña del mismo navegador o la
     * misma persona con doble clic**, no una cola de trabajo. Esperar sería
     * dejarla colgada hasta 20 s para acabar haciendo un trabajo que ya está
     * hecho; decirlo de inmediato deja que el cliente reintente cuando quiera.
     */
    public static function tomarCerrojo(string $tipo, string $huella, int $year): bool
    {
        $fila = DB::selectOne('SELECT GET_LOCK(?, 0) AS tomado',
            [self::cerrojo($tipo, $huella, $year)]);

        // `GET_LOCK` devuelve 1 si lo tomó, 0 si no pudo y NULL si hubo error.
        // Un NULL se trata como «no pudo»: importar dos veces a la vez es peor
        // que no importar, y quien reintenta no pierde nada.
        return $fila !== null && (int) $fila->tomado === 1;
    }

    public static function soltarCerrojo(string $tipo, string $huella, int $year): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS soltado',
            [self::cerrojo($tipo, $huella, $year)]);
    }

    public static function abrir(string $tipo, string $huella, int $year, ?string $archivo, ?int $usuario): self
    {
        $anterior = DB::selectOne(
            'SELECT id, avance, filas FROM importaciones
             WHERE tipo = ? AND huella = ? AND year = ? AND estado <> ?
             ORDER BY id DESC LIMIT 1',
            [$tipo, $huella, $year, self::COMPLETADA]
        );

        if ($anterior !== null) {
            DB::update(
                'UPDATE importaciones SET estado = ?, error = NULL, updated_at = ? WHERE id = ?',
                [self::EN_PROCESO, Reloj::ahora(), $anterior->id]
            );

            return new self(
                (int) $anterior->id,
                true,
                json_decode((string) $anterior->avance, true) ?: [],
                (int) $anterior->filas,
            );
        }

        // Fuera de cualquier transacción a propósito: la fila tiene que existir
        // aunque el proceso muera en la primera hoja. En autocommit —que es como
        // corre el importador— esto queda escrito antes de leer el archivo.
        DB::insert(
            'INSERT INTO importaciones (tipo, huella, archivo, year, avance, filas, estado, created_by, inicio, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
            [$tipo, $huella, $archivo, $year, '{}', self::EN_PROCESO, $usuario, Reloj::ahora(), Reloj::ahora(), Reloj::ahora()]
        );

        return new self((int) DB::getPdo()->lastInsertId(), false, [], 0);
    }

    /** Para el `id` de la fila, que es lo que se anota en los mensajes de error. */
    public function id(): int
    {
        return $this->id;
    }

    /** Si esta llamada continuó una importación anterior en vez de empezarla. */
    public function reanudada(): bool
    {
        return $this->reanudada;
    }

    /** Cuántas filas lleva aplicadas, contando las de los intentos anteriores. */
    public function filas(): int
    {
        return $this->filas;
    }

    /**
     * Si esta fila de esta hoja ya está aplicada.
     *
     * Se compara con `>=` y no con `==` porque las hojas se recorren enteras: la
     * pregunta no es «¿es la siguiente?» sino «¿está esta ya detrás del punto de
     * control?».
     */
    public function yaProcesada(string $hoja, int $fila): bool
    {
        return isset($this->avance[$hoja]) && $fila <= $this->avance[$hoja];
    }

    /**
     * Deja anotado que la fila quedó aplicada.
     *
     * **Se llama dentro de la transacción de la fila**, no después. Llamarla
     * fuera reabre justo el agujero que esto cierra: el proceso muere entre el
     * commit de la fila y el de su marca, y al reanudar la fila se repite.
     */
    public function anotar(string $hoja, int $fila): void
    {
        $this->apuntar($hoja, $fila);
        $this->volcar();
    }

    /**
     * Mueve la marca **en memoria** y no escribe.
     *
     * Es la mitad de `anotar()` que usa el proceso por lotes: dentro de una
     * transacción de lote, las filas y su marca tienen que entrar juntas, pero
     * **no hace falta una escritura por fila** — con una al final del lote se
     * consigue lo mismo y se ahorra una consulta por alumno, que era el 15 % de
     * las del importador.
     *
     * **Nunca se llama sin un `volcar()` detrás dentro de la misma
     * transacción**: la marca en memoria que no se escribe es la que hace que al
     * reanudar se salten filas que no entraron.
     */
    public function apuntar(string $hoja, int $fila): void
    {
        $this->avance[$hoja] = $fila;
        $this->filas++;
    }

    /**
     * Cuántas filas tenía el archivo, para que el aviso de «a medias» pueda dar
     * un denominador y no un número suelto.
     *
     * **Sólo crece.** Si la lectura revienta en la tercera pestaña, el total que
     * se conoce es parcial y menor que el de la tanda anterior; escribirlo
     * encima haría que el aviso dijera «600 de 400». Se guarda el mayor visto,
     * que es lo único que no puede mentir hacia abajo.
     */
    public function anotarElTotal(int $filasDelArchivo): void
    {
        if ($filasDelArchivo <= 0) {
            return;
        }

        DB::update(
            'UPDATE importaciones SET filas_totales = GREATEST(COALESCE(filas_totales, 0), ?), updated_at = ?
              WHERE id = ?',
            [$filasDelArchivo, Reloj::ahora(), $this->id]
        );
    }

    /** Escribe de una vez la marca que `apuntar()` fue moviendo. */
    public function volcar(): void
    {
        DB::update(
            'UPDATE importaciones SET avance = ?, filas = ?, updated_at = ? WHERE id = ?',
            [json_encode($this->avance, JSON_UNESCAPED_UNICODE), $this->filas, Reloj::ahora(), $this->id]
        );
    }

    public function completar(): void
    {
        DB::update(
            'UPDATE importaciones SET estado = ?, fin = ?, updated_at = ? WHERE id = ?',
            [self::COMPLETADA, Reloj::ahora(), Reloj::ahora(), $this->id]
        );
    }

    /**
     * Lo que esta tanda no supo traducir, guardado con la importación.
     *
     * **Acumula, no pisa.** Una importación reanudada tiene avisos de las dos
     * tandas y la pantalla del escenario 8 los enseña juntos: «de las 63 filas
     * ya escritas, 4 llevaron un valor que no se reconoció» habla de filas que
     * se escribieron en otro proceso y puede que hace meses. Si esto pisara, al
     * reanudar se perdería justo lo que esa frase cuenta.
     *
     * Se recorta a 2.000 avisos por si alguien sube una hoja entera escrita en
     * otro idioma: lo que protege la fila no es el límite de la columna —es
     * `longText`— sino que la respuesta siga cabiendo en una pantalla.
     *
     * @param  array<int, array<string, mixed>>  $nuevos
     */
    public function guardarAvisos(array $nuevos): void
    {
        if ($nuevos === []) {
            return;
        }

        $fila = DB::selectOne('SELECT avisos FROM importaciones WHERE id = ?', [$this->id]);
        $previos = json_decode((string) ($fila->avisos ?? ''), true);

        $todos = array_slice(array_merge(is_array($previos) ? $previos : [], $nuevos), -2000);

        DB::update(
            'UPDATE importaciones SET avisos = ?, updated_at = ? WHERE id = ?',
            [json_encode($todos, JSON_UNESCAPED_UNICODE), Reloj::ahora(), $this->id]
        );
    }

    /**
     * **Lo que esta pasada HIZO**, sumado a lo que hicieron las anteriores.
     *
     * Es lo que el acta (`GET planilla-offline/acta/{id}`) imprime, y la razón de
     * que exista la columna: la respuesta de `postImportar` ya cuenta lo hecho,
     * pero se va con la respuesta. Semanas después, cuando alguien pregunta qué
     * entró, lo único que queda es esta fila.
     *
     * ## Acumula, como los avisos y al revés que las respuestas
     *
     * La regla de esta clase ya estaba escrita un método más arriba y aquí manda
     * igual: **un aviso es algo que pasó y una respuesta es una instrucción
     * vigente**. Lo hecho es de los primeros, y además es la razón por la que esto
     * se pidió con nombre propio: *una importación cortada y continuada tiene que
     * dar un acta con el total, no con la última tanda*. Si esto pisara, el acta de
     * una importación de cuarenta filas cortada en la treinta y nueve diría que
     * entró **una** nota.
     *
     * Lo único que pisa es `contexto` —quién subió, por cuenta de quién, de qué
     * libro—, y pisa porque describe **la importación**, no la pasada: es el mismo
     * archivo y el mismo libro en las dos tandas. Con dos excepciones dentro, que
     * son justo las que cuentan la historia de las tandas:
     *
     * - **`pasadas`** se incrementa. Es lo que hace que el acta pueda decir «se
     *   subió en tres veces» en vez de fingir que fue de una.
     * - **`reanudada`** se queda en `true` en cuanto lo fue una vez. La pregunta
     *   del acta no es «¿fue reanudada la última pasada?» sino «¿esta importación
     *   se cortó alguna vez?», y ésa sólo se puede contestar acumulando: la
     *   **última** pasada de una importación cortada es la que la terminó, y si
     *   sólo se guardara la suya el acta diría que no hubo corte.
     *
     * ## Y por qué se escribe también cuando la pasada revienta
     *
     * Porque las filas que se escribieron antes del error **están escritas**. Un
     * acta que sólo contara las pasadas que terminaron bien sería, exactamente, un
     * acta que no cuenta lo que entró — y el caso en que alguien la pide es el
     * caso en que algo salió mal.
     *
     * @param  array<string, mixed>  $deEstaPasada  `totales`, `por_hoja`, `indicadores`, `contexto`
     */
    public function guardarHechos(array $deEstaPasada): void
    {
        $fila = DB::selectOne('SELECT hechos FROM importaciones WHERE id = ?', [$this->id]);
        $previo = json_decode((string) ($fila->hechos ?? ''), true);
        $previo = is_array($previo) ? $previo : [];

        $todo = [
            'totales' => $this->sumar($previo['totales'] ?? [], $deEstaPasada['totales'] ?? []),
            'por_hoja' => $this->juntarLasHojas($previo['por_hoja'] ?? [], $deEstaPasada['por_hoja'] ?? []),
            'indicadores' => $this->juntarLosIndicadores(
                $previo['indicadores'] ?? [], $deEstaPasada['indicadores'] ?? []
            ),
            'contexto' => array_merge($previo['contexto'] ?? [], $deEstaPasada['contexto'] ?? []),
        ];

        $todo['contexto']['pasadas'] = (int) ($previo['contexto']['pasadas'] ?? 0) + 1;
        $todo['contexto']['reanudada'] = ($previo['contexto']['reanudada'] ?? false)
            || ($deEstaPasada['contexto']['reanudada'] ?? false);

        DB::update(
            'UPDATE importaciones SET hechos = ?, updated_at = ? WHERE id = ?',
            [json_encode($todo, JSON_UNESCAPED_UNICODE), Reloj::ahora(), $this->id]
        );
    }

    /**
     * Suma dos mapas de contadores, **conservando las claves que sólo tiene uno**.
     *
     * No es un `array_map` sobre uno de los dos: una pasada que no creó ningún
     * indicador no trae esa clave, y quedarse con las del previo perdería las
     * nuevas. Lo que no sea un número se ignora en vez de convertirse en cero
     * silenciosamente.
     *
     * @param  array<string, mixed>  $previo
     * @param  array<string, mixed>  $nuevo
     * @return array<string, int>
     */
    private function sumar(array $previo, array $nuevo): array
    {
        $suma = [];

        foreach ([$previo, $nuevo] as $mapa) {
            foreach ($mapa as $clave => $valor) {
                if (is_numeric($valor)) {
                    $suma[$clave] = ($suma[$clave] ?? 0) + (int) $valor;
                }
            }
        }

        return $suma;
    }

    /**
     * Las hojas de las dos tandas, **juntadas por nombre de hoja**.
     *
     * Por el nombre y no por la posición porque el orden de las hojas de una
     * pasada reanudada no es el de la primera: las que quedaron enteras detrás del
     * punto de control ni se estudian, así que la segunda tanda trae menos hojas y
     * en otro orden. Sumar por índice mezclaría las notas de Matemáticas con las
     * de Sociales **sin dar ningún error**.
     *
     * Los motivos y las filas descartadas se unen sin repetir: lo que no se pudo
     * hacer se vuelve a diagnosticar en cada pasada, y un acta con «el periodo está
     * cerrado» escrito tres veces se lee peor, no mejor.
     *
     * ## La excepción que no se adivina: `se_quedan_fuera` de una hoja que NO entró
     *
     * Cuando una hoja entera se cae —periodo cerrado, asignatura que ya no es de
     * este docente— el ensayo cuenta **todas** sus casillas como «se quedan fuera»,
     * y lo vuelve a hacer idéntico en cada pasada: esa hoja no tiene ninguna fila
     * marcada como hecha, así que nada la salta. Sumarlo diría que una planilla de
     * 40 casillas dejó fuera 120 en tres tandas.
     *
     * Así que si la pasada nueva dice que la hoja está `fuera`, su cuenta
     * **sustituye** en vez de sumarse; si la hoja entró, se suma, porque entonces
     * cada pasada sólo ha mirado las casillas que le tocaban. Los demás contadores
     * se suman en los dos casos: una hoja `fuera` no escribe nada, así que los suyos
     * son ceros y sumarlos no cambia nada — y el día que una hoja entre en la
     * primera tanda y se caiga en la segunda (el colegio cerró el periodo entre
     * medias), lo escrito **sigue escrito** y el acta tiene que decirlo.
     *
     * @param  array<string, mixed>  $previo
     * @param  array<string, mixed>  $nuevo
     * @return array<string, mixed>
     */
    private function juntarLasHojas(array $previo, array $nuevo): array
    {
        foreach ($nuevo as $nombre => $hoja) {
            if (! isset($previo[$nombre]) || ! is_array($previo[$nombre])) {
                $previo[$nombre] = $hoja;

                continue;
            }

            $antes = $previo[$nombre];

            $listas = [];

            foreach (self::LISTAS_DE_HOJA as $clave) {
                $listas[$clave] = array_values(array_unique(array_merge(
                    is_array($antes[$clave] ?? null) ? $antes[$clave] : [],
                    is_array($hoja[$clave] ?? null) ? $hoja[$clave] : [],
                )));
            }

            $contadores = array_flip(self::CONTADORES_DE_HOJA);

            $fuera = [
                'se_quedan_fuera' => ($hoja['fuera'] ?? false) === true
                    ? (int) ($hoja['se_quedan_fuera'] ?? 0)
                    : (int) ($antes['se_quedan_fuera'] ?? 0) + (int) ($hoja['se_quedan_fuera'] ?? 0),
            ];

            $previo[$nombre] = array_merge(
                $antes,
                $hoja,
                $this->sumar(
                    array_intersect_key($antes, $contadores),
                    array_intersect_key($hoja, $contadores),
                ),
                $fuera,
                $listas,
            );
        }

        return $previo;
    }

    /**
     * Los indicadores creados por las dos tandas, **sin repetir la misma subunidad**.
     *
     * El de-duplicado no es defensivo de más: la F9 es idempotente por nombre
     * —vuelve a encontrar el indicador que ya creó en vez de crear otro— así que
     * una pasada reanudada puede volver a *reportar* la misma subunidad sin
     * haberla creado dos veces. Un acta que la contara dos veces diría que
     * coordinación creó dos columnas donde hay una.
     *
     * @param  list<array<string, mixed>>  $previo
     * @param  list<array<string, mixed>>  $nuevo
     * @return list<array<string, mixed>>
     */
    private function juntarLosIndicadores(array $previo, array $nuevo): array
    {
        $vistos = [];
        $todos = [];

        foreach (array_merge($previo, $nuevo) as $indicador) {
            $llave = (string) ($indicador['subunidad_id'] ?? json_encode($indicador));

            if (isset($vistos[$llave])) {
                continue;
            }

            $vistos[$llave] = true;
            $todos[] = $indicador;
        }

        return $todos;
    }

    /**
     * Lo que contestó la persona, para que reanudar no obligue a contestarlo
     * otra vez.
     *
     * Éstas SÍ pisan, al revés que los avisos, y es la diferencia que importa:
     * un aviso es algo que pasó —histórico, se acumula— y una respuesta es una
     * instrucción vigente. Quien vuelve a subir el archivo con el mapa
     * corregido está corrigiendo lo que dijo antes, no añadiendo.
     *
     * Un `null` no borra: significa «esta subida no traía instrucciones», que
     * no es lo mismo que «olvida las que te di».
     *
     * @param  array<string, mixed>|null  $respuestas
     */
    public function guardarRespuestas(?array $respuestas): void
    {
        if ($respuestas === null) {
            return;
        }

        DB::update(
            'UPDATE importaciones SET respuestas = ?, updated_at = ? WHERE id = ?',
            [json_encode($respuestas, JSON_UNESCAPED_UNICODE), Reloj::ahora(), $this->id]
        );
    }

    /**
     * La importación de este año que quedó a medias, si la hay.
     *
     * Es lo que la pantalla pregunta **al entrar**, antes de que nadie elija un
     * fichero: no sabe ningún `id`, sabe que entró a importar. Devuelve la más
     * reciente sin terminar, con quién la empezó —la pantalla dice «empezada el
     * 14 de enero a las 9:41 por Marta Ospina», y un nombre evita que alguien
     * pise el trabajo de otra persona— y con el error tal cual.
     *
     * `estado <> 'completada'` es el mismo criterio con el que `abrir()` decide
     * reanudar, y tiene que seguir siéndolo: si esto enseñara una importación
     * que aquél no va a reanudar, el botón «seguir donde se quedó» mentiría.
     *
     * **`empezada_por` cae al `username` cuando no hay ficha de profesor, y eso
     * no es una red por si acaso: es el caso NORMAL.** Medido el 20 sep 2026 en
     * la copia de desarrollo: de las **22 cuentas de tipo `Usuario`** —los
     * administrativos, que son quienes importan alumnos— **ninguna** tiene ficha
     * en `profesores`; las 47 que la tienen son docentes. O sea que unir sólo
     * contra `profesores` habría dejado el nombre vacío **justo para todos los
     * que usan esta pantalla**, y la cabecera diría «empezada el 14 de enero a
     * las 9:41 por» y nada.
     */
    public static function pendienteDe(string $tipo, int $year): ?object
    {
        return DB::selectOne(
            'SELECT i.id, i.archivo, i.huella, i.year, i.avance, i.filas, i.filas_totales, i.estado, i.error,
                    i.avisos, i.respuestas, i.inicio, i.fin, i.created_by,
                    '.self::QUIEN_LA_EMPEZO.' AS empezada_por
             FROM importaciones i
             '.self::UNIONES_DEL_NOMBRE.'
             WHERE i.tipo = ? AND i.year = ? AND i.estado <> ?
             ORDER BY i.id DESC LIMIT 1',
            [$tipo, $year, self::COMPLETADA]
        );
    }

    /**
     * La última importación de **este archivo**, la haya terminado o no.
     *
     * Es lo que contesta «esto ya se subió» en el ensayo de la planilla: la huella
     * es el contenido, así que una coincidencia aquí **es el mismo fichero**, no uno
     * que se llame igual.
     *
     * **No filtra por estado, y es la decisión que la hace útil.** Una importación
     * que reventó a medias es más importante de contar que una que fue bien, porque
     * es justo la que deja al docente sin saber qué entró. Filtrar por `completada`
     * escondería el único caso en que este aviso hace falta de verdad.
     *
     * **`id DESC` y no `inicio DESC`**: dos filas del mismo archivo pueden empezar
     * dentro del mismo segundo —la tabla guarda segundos— y entonces el orden por
     * fecha es el que devuelva el motor. El `id` no empata nunca.
     *
     * `quien` sale de {@see QUIEN_LA_EMPEZO}, el mismo criterio que `pendienteDe`:
     * el nombre de la ficha de profesor y, si no la hay —el caso normal en las
     * cuentas administrativas—, el `username`. **Nunca el número de `created_by`**,
     * que es lo que el front pintaría si esto devolviera la columna a secas.
     */
    public static function laDeLaHuella(string $tipo, string $huella): ?object
    {
        return DB::selectOne(
            'SELECT i.id, i.huella, i.year, i.estado, i.error, i.hechos,
                    i.inicio, i.fin, i.created_at, i.created_by,
                    '.self::QUIEN_LA_EMPEZO.' AS quien
             FROM importaciones i
             '.self::UNIONES_DEL_NOMBRE.'
             WHERE i.tipo = ? AND i.huella = ?
             ORDER BY i.id DESC LIMIT 1',
            [$tipo, $huella]
        );
    }

    /**
     * Guarda por qué se cortó y deja la fila reanudable.
     *
     * El mensaje se recorta a lo que cabe en un `text` sin cargarse la fila, y
     * lleva el fichero y la línea porque el caso corriente —«Undefined array key
     * 0» en una hoja cuyo grupo no existe— es indistinguible de otros diez sin
     * eso.
     */
    public function fallar(Throwable $e): void
    {
        $mensaje = mb_substr(
            get_class($e).': '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')',
            0, 60000
        );

        DB::update(
            'UPDATE importaciones SET estado = ?, error = ?, updated_at = ? WHERE id = ?',
            [self::FALLIDA, $mensaje, Reloj::ahora(), $this->id]
        );
    }

    /**
     * Pasa a 'fallida' lo que lleva `$minutos` sin escribir un lote.
     *
     * **No reanuda nada — no puede.** El archivo que sube la secretaría sólo
     * existe durante la petición HTTP que lo trae (`request()->file('file')` en
     * `ImportarController`); no se guarda en disco. Si la pestaña se cierra a
     * medias no queda de dónde leer la fila siguiente, así que lo único que se
     * puede hacer del lado del servidor es dejar de mentir: sin esto, la fila
     * se queda en 'en_proceso' para siempre y `pendienteDe()` la sigue
     * devolviendo como si algo la fuera a continuar donde la dejó.
     *
     * El umbral es minutos SIN escritura, no minutos desde que empezó: una
     * importación larga sigue tocando `updated_at` en cada `volcar()`, así que
     * esto no se dispara mientras haya alguien al otro lado subiendo lotes.
     *
     * @return int cuántas filas marcó
     */
    public static function marcarAbandonadas(int $minutos = 10): int
    {
        $corte = Reloj::ahora()->subMinutes($minutos);
        $mensaje = "Abandonada: sin actividad en más de {$minutos} minutos ".
            '(la pestaña se cerró o el proceso murió a medias). '.
            'Vuelve a subir el mismo archivo para continuar donde se quedó.';

        return DB::update(
            'UPDATE importaciones SET estado = ?, error = ?, updated_at = ?
              WHERE estado = ? AND updated_at < ?',
            [self::FALLIDA, $mensaje, Reloj::ahora(), self::EN_PROCESO, $corte]
        );
    }
}
