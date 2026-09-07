<?php

namespace App\Services;

/**
 * Firma una licencia del programa de horarios. Ed25519, y **sólo eso**.
 *
 * El contrato entero —qué campos, qué bytes, qué rechaza el otro lado— está en
 * [docs/migracion/31-licencia-del-horario.md](../../docs/migracion/31-licencia-del-horario.md),
 * y la decisión que lo manda es la **25** de `myvc_horarios/docs/decisiones.md`:
 * *la licencia se firma con Ed25519 y la emite `8myvc`*.
 *
 * ## Esta clase NO decide quién tiene derecho a una licencia
 *
 * Recibe unos datos y devuelve el fichero firmado. Quién puede pedirla, con qué
 * permiso y por qué ruta es **otra decisión, y a 6 sep 2026 sigue sin tomarla
 * Joseth** — por eso este servicio existe sin controlador y sin ruta. Separarlo
 * así no es orden: es lo que permite probar la parte medida (los bytes) sin
 * inventarse la parte que no está decidida (la autorización).
 *
 * ## Y NO guarda la clave privada: se la dan
 *
 * De dónde sale esa clave es **la pregunta abierta más cara de este módulo**, y
 * está desarrollada en la §3 del documento. En una línea: el binario de Rust
 * lleva incrustada **una sola** clave pública (`const PUBLICA: [u8; 32]` en
 * `escritorio/src-tauri/src/licencia.rs`), así que en todo el sistema hay
 * **exactamente una** clave privada — no puede haber una por colegio, porque
 * entonces habría diecisiete públicas y el binario sólo tiene sitio para una.
 * Recibirla por constructor deja esa decisión donde se pueda tomar una vez, en
 * lugar de congelarla aquí leyendo un `env()`.
 *
 * ## Lo que esta clase se gasta en impedir, que es un solo fallo
 *
 * **Emitir una licencia perfectamente firmada que el otro lado no entienda.**
 * Es el estado `ilegible` de `nucleo/licencia.ts`, y es el peor de los cinco
 * para diagnosticar: la firma cuadra, el fichero se abre, el programa dice que
 * el emisor está roto y no hay nada que mirar en la máquina del colegio. En este
 * lenguaje se produce solo — `DB::select` devuelve los enteros de MySQL **como
 * cadenas**, y un `colegioId` que viaje como `"3"` se rechaza allí por
 * `typeof !== 'number'` sin que aquí falle nada—. Por eso los campos entran
 * declarados `int` y la carga se comprueba después de construirla.
 */
class FirmaDeLicencia
{
    /**
     * El número de formato del **envoltorio**, que no es el de la carga.
     *
     * Va fuera de la firma a propósito: los siete campos de la decisión 27 son
     * una decisión cerrada y ninguno es una versión, así que el hueco se reserva
     * en la parte no firmada, que no es de esa decisión. Rust rechaza cualquier
     * otro número con un mensaje que pide una versión más nueva del programa
     * (`FORMATO` en `licencia.rs`), así que **subir esto deja fuera a todos los
     * binarios instalados** y no es un cambio cosmético.
     */
    public const FORMATO = 1;

    /**
     * Las dos ediciones, y no hay una tercera. La diferencia entre ellas es
     * **una sola cosa**: si el programa puede hablar con el servidor de MyVC.
     */
    public const EDICIONES = ['myvc', 'independiente'];

    /**
     * El tope que aplica el verificador antes de tocar base64 (`TOPE` en
     * `licencia.rs`): 16 KiB para el fichero entero. Se comprueba aquí también
     * porque un fichero que se pasa **no da un error de licencia, da uno de
     * «esto no parece una licencia»**, y descubrirlo en el colegio es peor que
     * descubrirlo al emitir. Sólo lo puede provocar una `huella[]` enorme.
     */
    public const TOPE = 16 * 1024;

    /**
     * @param  string  $clavePrivada  Los 64 bytes de la clave secreta de Ed25519
     *                                tal y como los devuelve
     *                                `sodium_crypto_sign_secretkey()`. En crudo,
     *                                no en base64 ni en hexadecimal.
     */
    public function __construct(private readonly string $clavePrivada)
    {
        // Se comprueba aquí y no al firmar porque una clave de longitud
        // equivocada es un fallo de despliegue, no de una petición: quien la
        // configuró mal quiere enterarse al arrancar, no el día que un colegio
        // pida su licencia.
        if (strlen($clavePrivada) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException(
                'La clave privada de licencias mide '.strlen($clavePrivada).' bytes y Ed25519 pide '
                .SODIUM_CRYPTO_SIGN_SECRETKEYBYTES.'. Si la guardaste en base64 o en hexadecimal, decodifícala antes.'
            );
        }
    }

    /**
     * Devuelve el texto del fichero `.myvcl`, listo para guardar o para mandar.
     *
     * `caduca` y `huella` viajan **firmados desde la primera licencia aunque hoy
     * no los mire nadie**. Es la decisión 27 y no un olvido: reservar el hueco
     * ahora es gratis, y después cuesta reemitir todas las licencias vivas más
     * un binario que entienda dos formatos.
     *
     * @param  string[]  $huella  Las máquinas a las que se ata. Hoy no lo comprueba nadie.
     * @param  ?string  $emitida  ISO 8601. Se pasa sólo para poder reproducir una
     *                            emisión byte a byte; en producción se deja en
     *                            `null` y lo pone el reloj.
     */
    public function emitir(
        int $colegioId,
        string $nombreColegio,
        string $edicion,
        int $anioEscolar,
        ?string $caduca = null,
        array $huella = [],
        ?string $emitida = null,
    ): string {
        if (! in_array($edicion, self::EDICIONES, true)) {
            throw new \InvalidArgumentException(
                "No conozco la edición «{$edicion}». Las que hay: ".implode(', ', self::EDICIONES).'.'
            );
        }

        // Vacío se rechaza y no se sustituye por un genérico: el otro lado lo
        // rechaza igual, y este nombre acaba impreso al pie de las 22 hojas del
        // horario por docente. «Colegio» ahí es peor que no imprimir el renglón.
        if (trim($nombreColegio) === '') {
            throw new \InvalidArgumentException('El nombre del colegio va en el pie del horario impreso: no puede ir vacío.');
        }

        $licencia = [
            'colegioId' => $colegioId,
            'nombreColegio' => $nombreColegio,
            'edicion' => $edicion,
            'anioEscolar' => $anioEscolar,
            'caduca' => $caduca,
            // `array_values` y no el array tal cual: un array de PHP con huecos
            // en los índices —el que deja `array_filter`— se serializa como
            // objeto JSON, y allí `huella` se comprueba con `Array.isArray`. La
            // licencia saldría firmada e ilegible.
            'huella' => array_values($huella),
            'emitida' => $emitida ?? $this->ahora(),
        ];

        // Los dos indicadores están MEDIDOS, no elegidos por gusto: con ellos la
        // carga que produce esto es byte a byte la misma que la del arnés de
        // TypeScript (`herramientas/emitir-licencia-de-prueba.ts`), y la prueba
        // de este servicio lo fija con el vector de referencia. Sin
        // `UNESCAPED_UNICODE`, «Simón Bolívar» viaja como `ó` — que es JSON
        // válido y se lee igual, pero deja de ser comparable con el otro emisor,
        // que es lo único que hace comprobable esta traducción.
        $carga = json_encode($licencia, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($carga === false) {
            throw new \RuntimeException('La licencia no se pudo serializar a JSON: '.json_last_error_msg());
        }

        $fichero = json_encode([
            'formato' => self::FORMATO,
            // La carga viaja en base64 porque **la firma se comprueba sobre
            // bytes exactos**. Si viajara como objeto JSON, verificarla
            // obligaría a volver a serializarla, y cualquier diferencia de orden
            // de claves o de escapado —la que mete cualquier librería al
            // reserializar— tumbaría la firma de una licencia buena.
            'carga' => base64_encode($carga),
            'firma' => base64_encode(sodium_crypto_sign_detached($carga, $this->clavePrivada)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";

        if (strlen($fichero) > self::TOPE) {
            throw new \RuntimeException(
                'La licencia mide '.strlen($fichero).' bytes y el programa no acepta más de '.self::TOPE
                .': la rechazaría diciendo que no parece una licencia. Lo único que puede crecer así es `huella`.'
            );
        }

        return $fichero;
    }

    /**
     * La fecha tal y como la escribe `new Date().toISOString()` del otro emisor:
     * UTC, con milisegundos y con `Z`.
     *
     * Se imita ese formato **y no el de Laravel** porque los dos emisores tienen
     * que producir licencias indistinguibles: el día que alguien compare dos, la
     * diferencia no puede ser quién las firmó.
     */
    private function ahora(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
