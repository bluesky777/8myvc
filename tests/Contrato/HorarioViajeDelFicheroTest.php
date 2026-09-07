<?php

namespace Tests\Contrato;

use App\Http\Controllers\HorarioController;
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
 * ## Las dos decisiones que este fichero fija desde el 6 sep 2026
 *
 * Nacieron aquí como fallos medidos y **sin test a propósito**, porque estaban sin
 * contestar; Joseth las cerró ese día y ahora lo que se fija es lo contrario de lo que se
 * fijaba:
 *
 *   - **Decisión 5** — por encima de `MEDIUMTEXT` (16.777.215 b) la subida contestaba `201`
 *     y guardaba el fichero cortado. Ahora es **422 con `proyecto-demasiado-grande`**. Lo
 *     que lo decidió no fue el tamaño sino que **el docker y los dieciséis fallaban
 *     distinto** — aquí truncaba en silencio, allí habría sido `1406 → 500`.
 *   - **Decisión 6** — `TrimStrings` recortaba los saltos de los extremos. Ahora
 *     `proyecto` va en su `$except` y **el fichero vuelve entero**.
 *
 * *Las dos se comprobaron en rojo antes de darlas por buenas, que es lo único que
 * distingue un test que protege de uno que acompaña.*
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
     * 4` de `colegio.myvch` y `lleno.myvch`: los dos acaban en `\t}\n}`), para que este blob
     * sea el fichero que de verdad viaja hoy. **Los saltos de los extremos tienen su propio
     * caso** —`los_saltos_de_linea_de_los_extremos_sobreviven_el_viaje`—, que es donde vive
     * la decisión 6: hasta el 6 sep 2026 `TrimStrings` se los comía, y el blob de aquí le
     * esquivaba el bulto **por casualidad**, igual que los dos ficheros reales. *Por eso el
     * caso se fabrica aparte en vez de meterle un salto a éste: los dos hechos son distintos
     * y cada uno tiene que poder ponerse rojo solo.*
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

    /**
     * El token del sujeto, **acuñado una vez por test**.
     *
     * `tokenDe()` hace un login de verdad, y el limitador de la API corta a partir de la
     * 121 por minuto: los casos que recorren varios ficheros en un bucle se comían la cuota
     * ellos solos y salía un **429 donde parecía un fallo del viaje**. El sujeto es el mismo
     * en las dos puntas —sube y descarga—, así que reutilizarlo no afloja nada de lo que
     * estos casos comprueban; quién puede llamar a cada ruta lo fija
     * `HorarioAutorizacionTest`.
     */
    private ?string $token = null;

    private function token(): string
    {
        return $this->token ??= $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
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
            'Authorization' => 'Bearer '.$this->token(),
        ])->assertStatus(201);

        return (int) $r->json('id');
    }

    /**
     * Sube un proyecto **sin exigir 201**: los casos del tope necesitan ver el 422.
     */
    private function subirCrudo(string $proyecto, string $nombre): TestResponse
    {
        $anio = $this->anio();

        return $this->postJson('/api/horario/versiones', [
            'version' => [
                'nombre' => $nombre,
                'year_id' => (int) $anio->id,
                'anio' => (int) $anio->year,
                'nombre_colegio' => (string) $anio->nombre_colegio,
            ],
            'proyecto' => $proyecto,
            'piezas' => [],
        ], [
            'Authorization' => 'Bearer '.$this->token(),
        ]);
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
            'Authorization' => 'Bearer '.$this->token(),
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
     * Los saltos de línea de los extremos **sobreviven el viaje** — decisión 6, contestada
     * por Joseth el 6 sep 2026.
     *
     * **Este test estaba escrito al revés hasta hoy**, fijando el fallo: `TrimStrings` es
     * middleware global y recortaba `proyecto`, así que un `.myvch` terminado en `\n` se
     * guardaba con un byte menos, contestaba `201` y **seguía siendo JSON válido** — el
     * escritorio lo abría y no había ningún síntoma. Se arregla con `proyecto` en el
     * `$except` del middleware, y **la premisa de que eso era quirúrgico se midió antes**:
     * ninguna otra de las 578 rutas usa un campo de petición con ese nombre.
     *
     * Se prueban **los dos extremos y el doble**, porque el recorte se los llevaba todos y
     * un test que sólo mire el final dejaría pasar media regresión.
     *
     * ## Contra qué protege de verdad, que no es contra el fichero de hoy
     *
     * Hoy ningún `.myvch` trae saltos en los extremos: el escritorio escribe con
     * `JSON.stringify` a secas, que no pone salto final —medido sobre cuatro de sus proyectos,
     * los cuatro con `texto.trim() === texto`—. **Pero esa garantía no es nuestra y tiene un
     * agujero conocido**: su `enviar()` acepta un fichero de proyecto ya hecho y sólo llama al
     * serializador si no se lo dan (`envio.ts:625`), o sea que hay un camino que no pasa por
     * él.
     *
     * **Y el modo de fallo que va a ocurrir de verdad tiene nombre:** alguien añade un `\n`
     * final *«para que el fichero acabe bien»*. **El daño no sería un error** — se guardaría el
     * fichero sin ese carácter, con `201`, y **sin este caso no se pondría nada rojo en ninguno
     * de los dos repositorios**. *Este test es la mitad nuestra de esa garantía; la suya está en
     * el comentario de su `escribirProyecto()`.*
     */
    #[Test]
    public function los_saltos_de_linea_de_los_extremos_sobreviven_el_viaje(): void
    {
        $casos = [
            'al final' => $this->unMyvch()."\n",
            'al principio' => "\n".$this->unMyvch(),
            'dos al final' => $this->unMyvch()."\n\n",
            'en los dos extremos' => "\n".$this->unMyvch()."\n",
        ];

        foreach ($casos as $donde => $fichero) {
            $bajado = $this->bajar($this->subir($fichero, "Con salto {$donde}"))
                ->assertStatus(200)
                ->getContent();

            $this->assertMismoFichero($fichero, $bajado,
                "El salto de línea {$donde} no sobrevivió el viaje. Si `proyecto` ha salido ".
                'del `$except` de `TrimStrings`, el fichero vuelve recortado, con `201` y '.
                'siendo JSON válido: el escritorio lo abre y nadie se entera.');
        }
    }

    /**
     * Un proyecto por encima del tope es **422 y no `201` a medias** — decisión 5, contestada
     * por Joseth el 6 sep 2026.
     *
     * Antes de ese día, por encima de `MEDIUMTEXT` la subida contestaba `201` y guardaba el
     * fichero cortado. Lo que lo cerró no fue el tamaño —el `.myvch` más grande mide 128.779 b,
     * **130 veces menos**— sino que **el docker y los dieciséis fallaban distinto**: aquí
     * truncaba en silencio y allí, con `STRICT_TRANS_TABLES` de MariaDB, habría sido un
     * `1406 → 500`.
     *
     * ## Por qué el tope se baja aquí en vez de mandar 16 MB
     *
     * **Porque mandarlos tumbó la suite entera**, y el modo en que la tumbó es el motivo de que
     * esto se cuente: `Allowed memory size of 268435456 bytes exhausted` dentro de
     * `MySqlConnection` —el `INSERT` del caso que comprueba que justo en el tope SÍ entra—,
     * con **el `Tests:` sin llegar a imprimirse y el código de salida en 0**. O sea que un
     * `grep '⨯'` daba limpio sobre una suite que ni había terminado.
     *
     * Bajando el tope se ejercita **el mismo mecanismo** —el `strlen()`, el `motivo`, las
     * cifras y que no se escriba nada— por unos pocos KB, y que el valor de serie sea el de la
     * columna lo fija `el_tope_de_serie_es_el_de_la_columna`. *Lo que había que probar era el
     * contorno, no los megabytes.*
     */
    #[Test]
    public function un_proyecto_mas_grande_que_el_tope_es_422_y_no_se_guarda_cortado(): void
    {
        $tope = 2048;
        config(['horario.maximo_del_proyecto' => $tope]);

        $r = $this->subirCrudo(str_repeat('a', $tope + 1), 'Un proyecto pasado de tope')
            ->assertStatus(422);

        $this->assertSame('proyecto-demasiado-grande', $r->json('motivo'),
            'El rechazo por tamaño tiene que traer su propio `motivo`: con `cuerpo-mal-formado` '.
            'el escritorio revisaría su JSON, que está bien, en vez de mirar su fichero.');
        $this->assertSame($tope + 1, $r->json('bytes'));
        $this->assertSame($tope, $r->json('maximo'));

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM horario_versiones')->n,
            'Se rechazó por tamaño y quedó una fila. La versión entra entera o no entra.');

        // El control por el otro lado: justo EN el tope entra. Sin esto, un tope roto «hacia
        // abajo» —que rechazara todo— pasaría la mitad de arriba sin despeinarse.
        $this->subirCrudo(str_repeat('a', $tope), 'Justo en el tope')->assertStatus(201);
    }

    /**
     * El tope cuenta **BYTES y no caracteres**, que es donde se habría escapado entero.
     *
     * La salida que parecía obvia —una regla `max:` de Laravel— **no vale**: `max` resuelve a
     * `mb_strlen`, comprobado contra este árbol (`str_repeat('ñ', 10)` son 10 caracteres y 20
     * bytes, y pasa un `max:15`). Con la regla puesta, un proyecto de acentos —o sea **todos**,
     * que los colegios se llaman `SIMÓN`— cabría en el contador y lo truncaría MySQL igual.
     *
     * Por eso este caso manda **la mitad de caracteres que el tope y un byte más de bytes**: un
     * contador de caracteres lo deja pasar y uno de bytes lo rechaza.
     */
    #[Test]
    public function el_tope_del_proyecto_cuenta_bytes_y_no_caracteres(): void
    {
        $tope = 2048;
        config(['horario.maximo_del_proyecto' => $tope]);

        $proyecto = str_repeat('ñ', intdiv($tope, 2) + 1);

        $this->assertLessThan($tope, mb_strlen($proyecto),
            'El caso ya no distingue nada: hacen falta menos caracteres que el tope.');
        $this->assertGreaterThan($tope, strlen($proyecto));

        $r = $this->subirCrudo($proyecto, 'Acentos hasta arriba')->assertStatus(422);

        $this->assertSame('proyecto-demasiado-grande', $r->json('motivo'),
            'Pasó un proyecto que cabe en caracteres y no en bytes: el tope está contando '.
            'con `mb_strlen` y MySQL lo va a truncar igual.');
    }

    /**
     * El tope **de serie** es el de la columna, y una configuración no puede subirlo.
     *
     * Las dos mitades importan y ninguna se prueba sola. La primera es el número real —los
     * casos de arriba lo bajan para no mandar 16 MB, así que **sin esto nadie fijaría el valor
     * que se despliega**—. La segunda es que `maximoDelProyecto()` recorta contra la columna:
     * un tope configurado por encima devolvería el truncado mudo detrás de un `201`, que es el
     * fallo que cerró la decisión 5. *Una configuración puede apretar el tope; no puede reabrir
     * la decisión.*
     *
     * **Y el recorte se comprueba llamando al método, no subiendo 16 MB**, que es la lección
     * que dejó tumbar la suite: para ver el clamp por HTTP habría que pasarse del tope de la
     * columna **de verdad**, y eso son decenas de MB en vuelo por un `min()`. *Lo que aquí es
     * lógica pura se prueba como lógica pura; lo que es contrato se prueba por la ruta.*
     */
    #[Test]
    public function el_tope_de_serie_es_el_de_la_columna_y_no_se_puede_subir(): void
    {
        $this->assertSame(16777215, (int) config('horario.maximo_del_proyecto'),
            'El tope de serie tiene que ser lo que cabe en un `MEDIUMTEXT`: quien manda es la columna.');

        $sonda = new class extends HorarioController
        {
            public function tope(): int
            {
                return $this->maximoDelProyecto();
            }
        };

        config(['horario.maximo_del_proyecto' => 999_999_999]);
        $this->assertSame(16777215, $sonda->tope(),
            'Con el tope configurado por encima de la columna se aceptaría más de lo que cabe: '.
            'MySQL volvería a truncar en silencio y la decisión 5 quedaría deshecha desde un '.
            'fichero de configuración.');

        config(['horario.maximo_del_proyecto' => 4096]);
        $this->assertSame(4096, $sonda->tope(), 'Apretar el tope sí vale: es la dirección segura.');

        config(['horario.maximo_del_proyecto' => 0]);
        $this->assertSame(1, $sonda->tope(),
            'Un cero mal puesto dejaría la ruta rechazándolo todo y pareciendo caída; con 1 '.
            'sigue rota pero se ve al primer intento.');
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
