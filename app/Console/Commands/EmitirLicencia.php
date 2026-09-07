<?php

namespace App\Console\Commands;

use App\Services\FirmaDeLicencia;
use Illuminate\Console\Command;

/**
 * Emite una licencia firmada del programa de horarios, y enseña la clave pública
 * que hay que incrustar en el binario.
 *
 * El contrato del fichero está en
 * [docs/migracion/31-licencia-del-horario.md](../../../docs/migracion/31-licencia-del-horario.md);
 * la decisión que lo manda es la **25** de `myvc_horarios/docs/decisiones.md`.
 *
 * ## Por qué esto es un comando y no una ruta
 *
 * Se decidió el 6 sep 2026, y las razones no son de comodidad:
 *
 * - **Una licencia se emite una vez por colegio**, a mano, el día que se vende.
 *   Eso no es una pantalla: son diecisiete emisiones en la vida del producto.
 * - **La clave privada no tiene por qué ser alcanzable desde una petición
 *   HTTP.** Con una ruta, el proceso que atiende a cualquier acudiente puede
 *   leer la clave con la que se firman las licencias de los diecisiete colegios.
 * - Y una ruta nueva es una decisión de Joseth que mueve el contador de
 *   `CLAUDE.md` y tres snapshots. Un comando no mueve ninguno de los cuatro.
 *
 * ## Dónde se corre esto
 *
 * **Decidido por Joseth el 6 sep 2026: NO se corre en los servidores.** La clave
 * privada vive sólo en la máquina desde la que se emite. En todo el sistema hay
 * una sola —el binario incrusta una sola pública— y repartirla por los diecisiete
 * `.env` haría que cualquiera de los hostings compartidos sirviera para fabricar
 * licencias de todos los demás. Además, un colegio de la edición **independiente
 * no tiene despliegue de `8myvc`**, así que su licencia no la puede emitir «su»
 * API: no existe.
 *
 * ## Y la clave todavía no existe, también por decisión
 *
 * El mismo día, Joseth aplazó fabricarla: la licencia no le importa aún, esa parte
 * de la aplicación se enseñará bloqueada y por ahora sólo se entra contra el
 * servidor web. **Así que este comando está terminado y probado, y hoy no puede
 * emitir nada** — le falta una clave que alguien decidió no fabricar. **No es un
 * cabo suelto: es una espera con condición de caducidad** —*mientras no haya que
 * empaquetar para ningún colegio*—. Ver la §3.2.bis del documento.
 */
class EmitirLicencia extends Command
{
    protected $signature = 'licencia:emitir
        {--id= : El identificador del colegio que va DENTRO de la firma}
        {--colegio= : El nombre tal y como se quiere leer al pie del horario impreso}
        {--edicion=myvc : myvc | independiente}
        {--anio= : El año lectivo, como 2026. Por defecto, el año en curso}
        {--caduca= : ISO 8601. Viaja firmado y HOY NO LO COMPRUEBA NADIE}
        {--huella= : Máquinas separadas por comas. Ídem}
        {--salida= : Dónde escribir el .myvcl. Por defecto, a la pantalla}
        {--publica : No emite: imprime los 32 bytes de la clave pública para incrustarlos en Rust}';

    protected $description = 'Emite una licencia firmada del programa de horarios (Ed25519)';

    public function handle(): int
    {
        $ruta = config('licencia.clave_privada');

        if ($ruta === null || ! is_file($ruta)) {
            // Se dice dónde se buscó y cómo se fabrica, porque el caso normal de
            // este error es la primera vez que alguien lo corre.
            $this->error('No hay clave privada de licencias.');
            $this->line('');
            $this->line('  LICENCIA_CLAVE_PRIVADA = '.($ruta ?? 'sin definir'));
            $this->line('');
            $this->line('  Es la RUTA a un fichero con los 64 bytes de la clave secreta de Ed25519,');
            $this->line('  no la clave. Para fabricar una (y SÓLO se fabrica una vez en la vida del');
            $this->line('  producto: el binario incrusta su pública y no se puede cambiar sin');
            $this->line('  reinstalar los colegios):');
            $this->line('');
            $this->line('      php -r \'$p=sodium_crypto_sign_keypair();');
            $this->line('        file_put_contents("licencia-privada.bin", sodium_crypto_sign_secretkey($p));\'');
            $this->line('      chmod 600 licencia-privada.bin');
            $this->line('');
            $this->line('  PERO ANTES lee la §3.2.bis de docs/migracion/31-licencia-del-horario.md:');
            $this->line('  el 6 sep 2026 Joseth decidió NO fabricarla todavía, mientras no haya que');
            $this->line('  empaquetar para ningún colegio. Fabricarla es irreversible: su pública se');
            $this->line('  incrusta en los binarios instalados, y cambiarla obliga a reinstalarlos.');

            return self::FAILURE;
        }

        $firma = new FirmaDeLicencia(file_get_contents($ruta));

        if ($this->option('publica')) {
            return $this->imprimirLaPublica($ruta);
        }

        // La variable NO se llama `$obligatoria`, y no es capricho: `obligatoria`
        // es una columna `tinyint(1)` del esquema, y `CensoDeInterruptoresTest`
        // cuenta los interruptores que no lee nadie buscando el nombre de cada
        // columna en `app/`, `routes/`, `config/` y los seeders. Con ese nombre
        // aquí, y un `if` delante, el censo daba esa columna por **leída** y su
        // población bajaba de 93 a 92 — una variable local de un comando de
        // licencias haciéndose pasar por el lector de una columna del colegio.
        // Medido el 6 sep 2026: con el nombre puesto el censo se pone rojo, y
        // renombrarla es el arreglo; subir la constante habría escondido el
        // fallo y arrastrado al §105, que cruza esa población con los cuatro
        // clientes.
        foreach (['id', 'colegio'] as $queHaceFalta) {
            if ($this->option($queHaceFalta) === null) {
                $this->error("Falta --{$queHaceFalta}. Van los dos dentro de la firma y no tienen valor por defecto.");

                return self::FAILURE;
            }
        }

        $huella = $this->option('huella');

        try {
            $licencia = $firma->emitir(
                colegioId: (int) $this->option('id'),
                nombreColegio: (string) $this->option('colegio'),
                edicion: (string) $this->option('edicion'),
                anioEscolar: (int) ($this->option('anio') ?? date('Y')),
                caduca: $this->option('caduca'),
                huella: $huella === null ? [] : array_map(trim(...), explode(',', $huella)),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // Se para aquí a propósito: una licencia mal formada se firma
            // perfectamente y muere en el colegio como «el emisor está roto»,
            // que es el estado más difícil de diagnosticar de los cinco.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $salida = $this->option('salida');

        if ($salida === null) {
            $this->line($licencia);

            return self::SUCCESS;
        }

        file_put_contents($salida, $licencia);
        $this->info("Escrito {$salida}");
        $this->line('');
        $this->line('  NO comprobado desde aquí: que el programa la acepte. Esto firma; el que manda');
        $this->line('  es `verificar_licencia` de Rust, con SU clave incrustada. Si esa clave todavía');
        $this->line('  es la de desarrollo, este fichero no lo abre nadie — corre --publica y compara.');

        return self::SUCCESS;
    }

    /**
     * Los 32 bytes de la pública, formateados como el `const PUBLICA` de Rust.
     *
     * Es la **petición 1 de `myvc_horarios` a este repositorio** —la que bloquea
     * que puedan empaquetar nada para un colegio—, y se sirve así, ya pegable,
     * porque el error de copiar 32 números a mano no da la cara hasta que un
     * colegio abre su licencia y le dice que no es para este programa.
     */
    private function imprimirLaPublica(string $ruta): int
    {
        $publica = sodium_crypto_sign_publickey_from_secretkey(file_get_contents($ruta));
        $bytes = array_values(unpack('C*', $publica));

        $this->line('// Pegar en escritorio/src-tauri/src/licencia.rs, sustituyendo la de desarrollo.');
        $this->line('const PUBLICA: [u8; 32] = [');
        foreach (array_chunk($bytes, 8) as $fila) {
            $this->line('    '.implode(', ', $fila).', //');
        }
        $this->line('];');
        $this->line('');
        $this->line('hex: '.bin2hex($publica));

        return self::SUCCESS;
    }
}
