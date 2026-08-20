<?php

namespace App\Services;

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
 * **La hora.** Las marcas de esta tabla se escriben con `now()`, o sea en la
 * zona de `config/app.php` (UTC hoy), mientras el importador de al lado escribe
 * con `Carbon::now('America/Bogota')`. Es a propósito y no crea la trampa del
 * §2 de 09-pendientes.md: `inicio` y `fin` solo se restan entre sí, nunca se
 * comparan con una fecha de otra tabla, así que unificar las zonas no cambia
 * ningún resultado — solo desplaza cinco horas lo que se lee en pantalla.
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
                [self::EN_PROCESO, now(), $anterior->id]
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
            [$tipo, $huella, $archivo, $year, '{}', self::EN_PROCESO, $usuario, now(), now(), now()]
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
        $this->avance[$hoja] = $fila;
        $this->filas++;

        DB::update(
            'UPDATE importaciones SET avance = ?, filas = ?, updated_at = ? WHERE id = ?',
            [json_encode($this->avance, JSON_UNESCAPED_UNICODE), $this->filas, now(), $this->id]
        );
    }

    public function completar(): void
    {
        DB::update(
            'UPDATE importaciones SET estado = ?, fin = ?, updated_at = ? WHERE id = ?',
            [self::COMPLETADA, now(), now(), $this->id]
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
            [self::FALLIDA, $mensaje, now(), $this->id]
        );
    }
}
