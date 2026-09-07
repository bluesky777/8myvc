<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * El **viaje** del `.myvch`: subirlo por `postVersiones` y bajarlo por `getProyecto`,
 * comparando el fichero con el que salió.
 *
 * ## Por qué hace falta otro fichero, teniendo `HorarioSubidaTest` y `HorarioProyectoTest`
 *
 * Porque **ninguno de los dos recorre el viaje**, y eso no se ve leyendo sus nombres:
 *
 *   - `HorarioSubidaTest` sube y mira **el 201 y el veredicto**. Nunca vuelve a por el
 *     fichero.
 *   - `HorarioProyectoTest` baja y compara byte a byte —su
 *     `devuelve_el_proyecto_byt_e_a_byt_e_como_se_subio` dice justo eso—, pero la fila la
 *     mete él con un `DB::insert` (`versionEn()`). **`postVersiones` no está en el
 *     camino.**
 *
 * O sea que las dos mitades están cubiertas y **la costura no**: hoy se puede romper el
 * guardado del blob —recodificarlo, escaparlo, truncarlo al insertar— y **los dieciséis
 * tests de esas dos clases siguen verdes**, porque el que baja compara contra lo que él
 * mismo insertó y el que sube nunca mira lo que se guardó.
 *
 * Es el criterio de esta casa aplicado donde faltaba: **el viaje de ida y vuelta en vez de
 * una llamada** (`CLAUDE.md`, «lo que los hace encontrar cosas»).
 *
 * ## Y esto existe porque la única prueba que había era una foto
 *
 * El viaje se ejercitó **a mano** el 6 sep 2026 contra el docker, con `curl` y `sha256`,
 * sobre los dos ficheros reales del escritorio: `colegio.myvch` (30.161 b) y
 * `lleno.myvch` (128.779 b, con sus 312 piezas), y los dos volvieron idénticos
 * ([23 §9.ter](../../docs/migracion/23-horarios.md)). **Eso fue cierto ese día y no vuelve
 * a correr mañana**, que es exactamente lo que este fichero convierte en guarda.
 *
 * ## Lo que NO se fija aquí, a propósito
 *
 * **El truncado por encima de `MEDIUMTEXT`** (16.777.215 b), que hoy contesta `201` con el
 * fichero cortado. No se clava por dos razones y las dos importan: es la **decisión 5 de
 * la §10.2 y está sin contestar**, y además fijaría **el comportamiento del docker**, que
 * corre sin `sql_mode` estricto — en los dieciséis, con `STRICT_TRANS_TABLES` de serie en
 * MariaDB, el mismo caso aborta con un 1406. *Un test que congela el fallo de un entorno
 * hace más difícil arreglar el del otro.*
 */
class HorarioViajeDelFicheroTest extends CasoDeContrato
{
    /**
     * Un `.myvch` con **todo lo que rompe el viaje metido dentro a propósito**.
     *
     * No es un blob de adorno: cada trozo cubre una forma distinta de que el fichero
     * vuelva «bien» y no lo abra el escritorio.
     *
     *   tabuladores y saltos    lo que el escapado de JSON duplica (§10.2: ×1,41 vacío)
     *   comillas y barras       lo que un escapado de más convierte en \\" y \\\\
     *   acentos                 lo que se pierde si algo recodifica a latin-1
     *   emoji de 4 bytes        lo que `utf8mb4` guarda y `utf8` (3 bytes) trunca en seco
     *
     * El emoji es el que no sobra aunque lo parezca: `utf8mb3` acepta el resto de esta
     * cadena sin rechistar, así que sin un carácter de cuatro bytes **una columna con el
     * juego equivocado pasaría este test entero**.
     *
     * **Y termina en `}` y no en salto de línea, como los dos ficheros reales** (`tail -c
     * 4` de `colegio.myvch` y `lleno.myvch`: los dos acaban en `\t}\n}`). No es un detalle
     * de estilo: `TrimStrings` **se come los saltos de los extremos** y con uno al final
     * este blob no volvería idéntico. Eso tiene su propio caso, abajo, y **no se esconde
     * eligiendo un fichero que le esquive el bulto**.
     */
    private function unMyvch(): string
    {
        return "{\n".
               "\t\"formato\": 1,\n".
               "\t\"programa\": \"arnés punta-a-punta\",\n".
               "\t\"proyecto\": {\n".
               "\t\t\"colegio\": \"COLEGIO ADVENTISTA SIMÓN BOLIVAR\",\n".
               "\t\t\"nota\": \"comillas \\\"dentro\\\", una barra \\\\ suelta y un salón 🏫\",\n".
               "\t\t\"niveles\": [\"Preescolar\", \"Básica\", \"Media\"],\n".
               "\t\t\"piezas\": []\n".
               "\t}\n".
               '}';
    }

    /** El año abierto del seed, que es contra el que se sube. */
    private function anio(): object
    {
        $fila = DB::selectOne('SELECT id, year, nombre_colegio FROM years WHERE actual = 1 AND deleted_at IS NULL');

        $this->assertNotNull($fila, 'El seed no tiene ningún año con `actual = 1`.');

        return $fila;
    }

    /**
     * Sube un fichero y devuelve el `id` de la versión.
     *
     * **Sin piezas**, y no por comodidad: lo que se prueba aquí es el viaje del blob, que
     * no depende de ninguna colocación —`una_version_sin_ninguna_pieza_entra` ya fija que
     * eso es legítimo—. Meter piezas ataría este fichero a los ids del seed sin cubrir ni
     * un byte más del fichero.
     */
    private function subir(string $proyecto, string $nombre = 'Viaje de ida y vuelta'): int
    {
        $anio = $this->anio();

        $r = $this->postJson('/api/horario/versiones', [
            'version' => [
                'nombre' => $nombre,
                'year_id' => (int) $anio->id,
                'anio' => (int) $anio->year,
                'nombre_colegio' => (string) $anio->nombre_colegio,
            ],
            'proyecto' => $proyecto,
            'piezas' => [],
        ], [
            'Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username),
        ])->assertStatus(201);

        return (int) $r->json('id');
    }

    /**
     * Baja el proyecto de una versión.
     *
     * Con superusuario porque `getProyecto` exige `puedePublicarHorario` **dentro** del
     * método y el rol `Coord académico` está vacío: con cualquier otro sujeto el 403 se
     * leería como un fallo del viaje.
     */
    private function bajar(int $versionId): TestResponse
    {
        return $this->getJson("/api/horario/versiones/{$versionId}/proyecto", [
            'Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username),
        ]);
    }

    /**
     * Los dos ficheros son el mismo, y el hash va **antes** que la comparación cruda.
     *
     * No es adorno de diagnóstico: si difieren, `assertSame` sobre dos blobs vuelca los
     * dos enteros y el fallo se vuelve ilegible justo cuando hay que leerlo. El hash dice
     * en una línea que difieren; el `assertSame` de después dice en qué.
     */
    private function assertMismoFichero(string $esperado, string $real, string $porque): void
    {
        $this->assertSame(hash('sha256', $esperado), hash('sha256', $real),
            $porque."\n".
            'subió '.strlen($esperado).' b, bajó '.strlen($real).' b.');

        $this->assertSame($esperado, $real, $porque);
    }

    // ────────────────────────────────────────────────────────────────── el viaje

    #[Test]
    public function el_fichero_vuelve_identico_despues_de_subirlo_de_verdad(): void
    {
        $fichero = $this->unMyvch();

        $r = $this->bajar($this->subir($fichero))->assertStatus(200);

        // `getContent()` y no `assertJson`: lo que sale no es JSON de esta API, es el
        // fichero del escritorio. Leerlo como JSON pasaría con el blob escapado, que es
        // justo el fallo que se descarga sin error y no abre el escritorio.
        $this->assertMismoFichero($fichero, $r->getContent(),
            'El fichero no volvió como se subió. O se escapó al meterlo en el cuerpo, o se '.
            'recodificó al guardarlo, o se cortó: las tres se descargan con un 200 y '.
            'ninguna la abre el escritorio.');

        // La barra de progreso del escritorio se cree esta cabecera. `strlen` y no
        // `mb_strlen`: una cabecera cuenta bytes, y aquí dentro hay acentos y un emoji.
        $this->assertSame((string) strlen($fichero), $r->headers->get('Content-Length'),
            '`Content-Length` no cuenta los bytes que van en el cuerpo.');
    }

    /**
     * **FALLO CONOCIDO, FIJADO A PROPÓSITO: `TrimStrings` se come los saltos de línea de
     * los extremos del fichero.**
     *
     * `app/Http/Middleware/TrimStrings.php` es middleware **global** (`Kernel.php:22`) y su
     * `$except` sólo protege las tres claves de contraseña, así que recorta `proyecto`
     * como recorta cualquier otro campo. Un `.myvch` que termine en `\n` **se guarda con
     * un byte menos** y el `201` no dice nada.
     *
     * **Lo peor no es el byte: es que el fichero recortado SIGUE SIENDO JSON VÁLIDO**, así
     * que el escritorio lo abre tan tranquilo y el daño sólo se ve si alguien compara
     * hashes. Es la familia de la §2 otra vez — algo que no da error y se lee como que fue
     * bien.
     *
     * **Hoy no muerde y por eso se fija en vez de arreglarse**: los dos únicos `.myvch`
     * reales terminan en `}`, medido con `tail -c 4`. Pero es **casualidad del serializador
     * del escritorio**, no una garantía: un `JSON.stringify(...) + "\n"`, un editor que
     * cierre el fichero con salto o un `writeFile` con la convención de POSIX lo rompen sin
     * tocar nada de aquí.
     *
     * **No se arregla en este lote porque el arreglo es global**: sacar `proyecto` del
     * recorte se hace en un middleware que pasa por **las 578 rutas**, y esta casa ya
     * decidió una vez —el envoltorio del 422 con `motivo`, 3 sep 2026— que lo que sólo
     * pide un cliente **no se hace global**. Es la decisión 6 de la
     * [§10.2](../../docs/migracion/23-horarios.md) y es de Joseth.
     *
     * *Este test se pone ROJO el día que alguien lo arregle, y eso es lo que se quiere:
     * obliga a mover el documento en el mismo commit en vez de dejar dos verdades.*
     */
    #[Test]
    public function los_saltos_de_linea_de_los_extremos_se_pierden_y_es_un_fallo_conocido(): void
    {
        $fichero = $this->unMyvch()."\n";

        $bajado = $this->bajar($this->subir($fichero, 'Con salto al final'))
            ->assertStatus(200)
            ->getContent();

        $this->assertSame($this->unMyvch(), $bajado,
            'El fichero volvió con el salto final puesto. Si `TrimStrings` ha dejado de '.
            'recortar `proyecto`, ESTO ES UNA BUENA NOTICIA: quita este test, pon el caso '.
            'en el del viaje normal y cierra la decisión 6 de la §10.2 del 23.');

        $this->assertSame(strlen($fichero) - 1, strlen($bajado),
            'Se pierde exactamente el salto de los extremos, ni más ni menos.');
    }

    #[Test]
    public function un_fichero_corrupto_hace_el_viaje_entero_y_vuelve_igual_de_corrupto(): void
    {
        // Ni siquiera es JSON. **Y tiene que subir**: `postVersiones` NO parsea el blob a
        // propósito (§4) — es un fichero opaco de un programa de escritorio que esta API
        // no sabe leer y no le toca validar. Quien sí lo lee es `getLecciones`, y para eso
        // tiene sus cinco estados con `ilegible` dentro.
        //
        // Si algún día esto empieza a dar 422, la pregunta no es «cómo lo arreglo»: es
        // quién decidió que este servidor sabe qué es un `.myvch` válido.
        $basura = "esto no es un .myvch, es un fichero a medias \x01\x02 y con acentos: ñÁ";

        $r = $this->bajar($this->subir($basura, 'Un .myvch roto'))->assertStatus(200);

        $this->assertMismoFichero($basura, $r->getContent(),
            'Un fichero corrupto tiene que volver corrupto y EXACTO. Que vuelva distinto '.
            'significa que algo por el camino se puso a interpretarlo.');
    }

    #[Test]
    public function subir_dos_veces_el_mismo_fichero_da_dos_versiones_y_ninguna_pisa_a_la_otra(): void
    {
        $fichero = $this->unMyvch();

        $primera = $this->subir($fichero, 'La de la mañana');
        $segunda = $this->subir($fichero, 'La de la tarde');

        // **No hay deduplicación y no debe haberla**: cada subida es una versión (regla 1
        // del controlador), y dos subidas idénticas son dos momentos del año, no una
        // repetida. Colapsarlas le borraría al colegio el rastro de cuándo subió qué.
        $this->assertNotSame($primera, $segunda,
            'Las dos subidas devolvieron el mismo id: alguien puso deduplicación donde el '.
            'contrato dice que cada subida es una versión.');

        foreach ([$primera, $segunda] as $id) {
            $this->assertMismoFichero($fichero, $this->bajar($id)->assertStatus(200)->getContent(),
                "La versión {$id} no devolvió su fichero intacto después de la segunda subida.");
        }

        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) AS n FROM horario_versiones')->n,
            'Tienen que quedar dos filas: una por subida.');
    }

    #[Test]
    public function los_acentos_y_los_emoji_de_cuatro_bytes_vuelven_identicos(): void
    {
        // `horario_versiones.proyecto` es `utf8mb4`, y esto es lo que lo comprueba desde
        // fuera. Con `utf8mb3` —tres bytes— el emoji no cabe: fuera de modo estricto MySQL
        // **corta la cadena ahí mismo y no da error**, así que el fichero volvería con un
        // 200, más corto, y sin que nada se pusiera rojo salvo esto.
        $fichero = '{"colegio":"ESCUELA ñÁÉíóú","salón":"🏫","nota":"ü·ç — 🎓"}';

        $r = $this->bajar($this->subir($fichero, 'Con emoji dentro'))->assertStatus(200);

        $this->assertMismoFichero($fichero, $r->getContent(),
            'Los caracteres de cuatro bytes no sobrevivieron el viaje: la columna no es '.
            '`utf8mb4`, o algo recodificó por el camino.');

        // El control del control: que la cadena de arriba de verdad lleve un carácter de
        // cuatro bytes. Sin esto, cambiarla por una «limpia» dejaría el test verde y
        // vacío, que es la forma que tienen estos casos de morirse sin avisar.
        $this->assertSame(4, strlen('🏫'),
            'El fichero de prueba perdió su carácter de cuatro bytes y ya no comprueba nada.');
    }
}
