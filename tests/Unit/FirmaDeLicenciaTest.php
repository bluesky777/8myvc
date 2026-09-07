<?php

namespace Tests\Unit;

use App\Services\FirmaDeLicencia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Que lo que firma esta API sea **exactamente** lo que acepta el programa de
 * horarios.
 *
 * ## Por qué esta prueba tiene un vector de referencia y no unas aserciones
 *
 * Aquí no hay nada que «esté bien» o «esté mal» por sí solo: hay un formato que
 * lo decide **otro repositorio**, y las dos mitades no comparten ni lenguaje ni
 * proceso. Una prueba que firmara y verificara con esta misma clase pasaría
 * igual de bien con el envoltorio cambiado, con otro sabor de base64 o firmando
 * unos bytes distintos de los que guarda — que es justo donde estaría el fallo
 * que ninguno de los dos lados notaría solo.
 *
 * Así que lo que se fija es un **vector**: una licencia concreta, con su fecha
 * puesta a mano para que la emisión sea reproducible, y los bytes que tiene que
 * producir. Y esos bytes no son una salida de esta clase que alguien pegó aquí:
 * son los del fichero que `myvc_horarios` lleva **pegado dentro de su
 * verificador de Rust**, en la constante `EMITIDO_POR_EL_ARNES` de
 * `escritorio/src-tauri/src/licencia.rs`, que su prueba
 * `lo_que_emite_el_arnes_de_typescript_lo_verifica_esto` mete por
 * `verificar_licencia()`. O sea: **estos bytes ya los verifica una prueba verde
 * de Rust**, y por eso valen como referencia.
 *
 * Se rehacen con esta orden, en `~/DESARROLLOS/myvc_horarios` (su salida es
 * reproducible byte a byte, para esto se hizo `--emitida`):
 *
 *     node herramientas/emitir-licencia-de-prueba.ts --colegio "Colegio Simón Bolívar" \
 *       --id 3 --caduca 2025-01-01 --huella maquina-1 \
 *       --emitida 2026-09-03T11:16:43.818Z --salida /tmp/rehecha.myvcl
 *
 * ## Y lo que esta prueba NO dice
 *
 * No dice que el programa abierto acepte la licencia, ni que exista la clave de
 * producción, ni que `sodium` esté compilado en el PHP de los diecisiete
 * colegios — que a 6 sep 2026 **sigue sin medirse** y está en la §2 de
 * `docs/migracion/31-licencia-del-horario.md`. Dice una cosa: que la traducción
 * del formato a este lenguaje es la correcta.
 */
class FirmaDeLicenciaTest extends TestCase
{
    /**
     * La semilla de la clave de **DESARROLLO**, que es pública a propósito en el
     * otro repositorio: de ella salen los 32 bytes que hoy lleva incrustado el
     * binario. Es el resumen SHA-256 de esta frase, y se deja la frase y no los
     * bytes para poder rehacerla sin copiarla de ningún sitio.
     *
     * **Esto no es la clave con la que se van a firmar licencias de verdad**, y
     * cuando exista la de producción esta prueba se queda como está: fija el
     * formato, no la clave.
     */
    private const FRASE_DE_DESARROLLO = 'myvc_horarios · licencia · clave de DESARROLLO · 2026-09-02';

    /** La carga del vector, en base64, tal y como está pegada en `licencia.rs`. */
    private const CARGA_ESPERADA = 'eyJjb2xlZ2lvSWQiOjMsIm5vbWJyZUNvbGVnaW8iOiJDb2xlZ2lvIFNpbcOzbiBCb2zDrXZhciIsImVkaWNpb24iOiJteXZjIiwiYW5pb0VzY29sYXIiOjIwMjYsImNhZHVjYSI6IjIwMjUtMDEtMDEiLCJodWVsbGEiOlsibWFxdWluYS0xIl0sImVtaXRpZGEiOiIyMDI2LTA5LTAzVDExOjE2OjQzLjgxOFoifQ==';

    /** La firma del vector, en base64. Ed25519 es determinista: para esa carga y esa clave, es ésta y no otra. */
    private const FIRMA_ESPERADA = '5bEXXGWei4cdQ3sZS6WrvvK7datwE5Dgap/Fx4r2t34G7bGigxCDRgqpN6OB+l1gNZG2RFwxgc5/XGJGsnnyAQ==';

    private function firmaDeDesarrollo(): FirmaDeLicencia
    {
        $par = sodium_crypto_sign_seed_keypair(hash('sha256', self::FRASE_DE_DESARROLLO, true));

        return new FirmaDeLicencia(sodium_crypto_sign_secretkey($par));
    }

    /** @return array<string,mixed> */
    private function emitirElVector(): array
    {
        $texto = $this->firmaDeDesarrollo()->emitir(
            colegioId: 3,
            nombreColegio: 'Colegio Simón Bolívar',
            edicion: 'myvc',
            anioEscolar: 2026,
            caduca: '2025-01-01',
            huella: ['maquina-1'],
            emitida: '2026-09-03T11:16:43.818Z',
        );

        return json_decode($texto, true);
    }

    #[Test]
    public function la_carga_es_byte_a_byte_la_del_otro_emisor(): void
    {
        // Si esto se pone rojo, lo que cambió es **cómo serializa PHP**, no la
        // criptografía: los indicadores de `json_encode`, el orden de los campos
        // o el escapado. Y una carga distinta es una firma distinta, así que el
        // programa la rechazaría entera.
        $this->assertSame(self::CARGA_ESPERADA, $this->emitirElVector()['carga']);
    }

    #[Test]
    public function la_firma_es_byte_a_byte_la_que_ya_verifica_la_prueba_de_rust(): void
    {
        $this->assertSame(self::FIRMA_ESPERADA, $this->emitirElVector()['firma']);
    }

    #[Test]
    public function el_envoltorio_lleva_los_tres_campos_y_el_formato_1(): void
    {
        $fichero = $this->emitirElVector();

        // El orden y el espaciado del envoltorio NO importan —Rust lo parsea con
        // `serde_json`—, pero los tres campos y el número sí: un formato que no
        // sea el 1 lo rechaza pidiendo una versión más nueva del programa.
        $this->assertSame(['formato', 'carga', 'firma'], array_keys($fichero));
        $this->assertSame(1, $fichero['formato']);
    }

    #[Test]
    public function la_firma_cuadra_con_la_clave_publica_que_le_corresponde(): void
    {
        $par = sodium_crypto_sign_seed_keypair(hash('sha256', self::FRASE_DE_DESARROLLO, true));
        $fichero = $this->emitirElVector();

        // El fallo típico de firmar es firmar unos bytes y guardar otros
        // —serializar dos veces—, y sale exactamente así: la firma es válida
        // para algo que no es lo que viaja en el fichero.
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($fichero['firma']),
            base64_decode($fichero['carga']),
            sodium_crypto_sign_publickey($par),
        ));
    }

    #[Test]
    public function los_enteros_viajan_como_enteros_aunque_lleguen_como_cadena(): void
    {
        // Es el fallo que este lenguaje produce solo: `DB::select` devuelve los
        // enteros de MySQL **como cadenas**, y allí un `colegioId` que llegue
        // como `"3"` se rechaza por `typeof !== 'number'`. La licencia saldría
        // PERFECTAMENTE FIRMADA y el programa diría que el emisor está roto, que
        // es el estado más difícil de diagnosticar de los cinco.
        $texto = $this->firmaDeDesarrollo()->emitir(
            colegioId: '3',            // @phpstan-ignore argument.type
            nombreColegio: 'Colegio de Prueba',
            edicion: 'myvc',
            anioEscolar: '2026',       // @phpstan-ignore argument.type
        );

        $carga = json_decode(base64_decode(json_decode($texto, true)['carga']), true);

        $this->assertSame(3, $carga['colegioId']);
        $this->assertSame(2026, $carga['anioEscolar']);
    }

    #[Test]
    public function la_huella_con_huecos_en_los_indices_sigue_siendo_una_lista(): void
    {
        // Un array de PHP al que le faltan índices —lo que deja `array_filter`—
        // se serializa como OBJETO JSON, y allí `huella` se comprueba con
        // `Array.isArray`. Otra licencia firmada e ilegible.
        $conHuecos = array_filter(['a', 'no', 'b'], fn ($m) => $m !== 'no');

        $texto = $this->firmaDeDesarrollo()->emitir(
            colegioId: 3,
            nombreColegio: 'Colegio de Prueba',
            edicion: 'myvc',
            anioEscolar: 2026,
            huella: $conHuecos,
        );

        $this->assertStringContainsString('"huella":["a","b"]', base64_decode(json_decode($texto, true)['carga']));
    }

    #[Test]
    public function una_edicion_que_no_existe_no_se_firma(): void
    {
        // Se para aquí y no allí: una edición desconocida es `ilegible` en el
        // colegio, y aquí es un mensaje con las dos que hay.
        $this->expectException(\InvalidArgumentException::class);

        $this->firmaDeDesarrollo()->emitir(
            colegioId: 3,
            nombreColegio: 'Colegio de Prueba',
            edicion: 'premium',
            anioEscolar: 2026,
        );
    }

    #[Test]
    public function un_nombre_de_colegio_en_blanco_no_se_firma(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->firmaDeDesarrollo()->emitir(
            colegioId: 3,
            nombreColegio: '   ',
            edicion: 'myvc',
            anioEscolar: 2026,
        );
    }

    #[Test]
    public function una_clave_que_no_mide_64_bytes_se_rechaza_al_construir(): void
    {
        // Una clave mal configurada es un fallo de despliegue: quien la puso
        // quiere enterarse al arrancar y no el día que un colegio pida su
        // licencia. El caso real es guardarla en base64 y no decodificarla.
        $this->expectException(\InvalidArgumentException::class);

        new FirmaDeLicencia('esto no son 64 bytes');
    }

    #[Test]
    public function sin_caduca_ni_huella_los_campos_viajan_igual(): void
    {
        // Decisión 27: `caduca` y `huella` viajan firmados **desde la primera
        // licencia** aunque hoy no los mire nadie. Si algún día alguien los
        // quita por no usarse, esta prueba se pone roja y le obliga a leer por
        // qué estaban: reservar el hueco ahora es gratis y después cuesta
        // reemitir todas las licencias vivas.
        $texto = $this->firmaDeDesarrollo()->emitir(
            colegioId: 3,
            nombreColegio: 'Colegio de Prueba',
            edicion: 'independiente',
            anioEscolar: 2026,
        );

        $carga = json_decode(base64_decode(json_decode($texto, true)['carga']), true);

        $this->assertArrayHasKey('caduca', $carga);
        $this->assertNull($carga['caduca']);
        $this->assertSame([], $carga['huella']);
    }

    #[Test]
    public function la_fecha_de_emision_se_escribe_como_la_del_otro_emisor(): void
    {
        // UTC, milisegundos y `Z`, que es lo que produce `new Date().toISOString()`.
        // No es cosmético: las licencias de los dos emisores tienen que ser
        // indistinguibles, y `emitida` es el único campo que pone el reloj.
        $texto = $this->firmaDeDesarrollo()->emitir(
            colegioId: 3,
            nombreColegio: 'Colegio de Prueba',
            edicion: 'myvc',
            anioEscolar: 2026,
        );

        $carga = json_decode(base64_decode(json_decode($texto, true)['carga']), true);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $carga['emitida']);
    }
}
