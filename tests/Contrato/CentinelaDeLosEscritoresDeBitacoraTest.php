<?php

namespace Tests\Contrato;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Los diez escritores de `bitacoras`, fijados — y con ellos su reloj.
 *
 * `tools/salud-de-la-bitacora.php` es la fase 0 del plan de
 * [docs/migracion/18-auditoria.md](../../docs/migracion/18-auditoria.md), y
 * clasifica cada fila en «escrita en UTC» o «escrita en Bogotá» **por su
 * `affected_element_type`**, con una lista escrita a mano en dos constantes. Esa
 * lista sale de leer los diez `INSERT INTO bitacoras` del proyecto, uno a uno.
 *
 * **Una lista a mano sin centinela dura hasta el siguiente que escriba.** Y el
 * modo en que fallaría es el peor de los dos posibles: un escritor nuevo con el
 * reloj en UTC no rompe la herramienta —la herramienta seguiría imprimiendo un
 * reparto con toda confianza— sino que cuenta de menos justo en la dirección que
 * tranquiliza, y la decisión que depende de ese número («¿se puede reinterpretar
 * la historia vieja?») se tomaría con el dato malo.
 *
 * Por eso este test **no comprueba que el código esté bien**: comprueba que la
 * población que la herramienta cree conocer sigue siendo la que hay. Si alguien
 * añade un `INSERT INTO bitacoras`, esto se pone rojo y le manda a decidir en
 * qué reloj escribe, que es la decisión que de verdad hay que tomar.
 *
 * Cuando la fase 3 del 18 sustituya `bitacoras` por `auditoria` y su escritor
 * único, este test se borra: ya no habrá lista que mantener.
 */
class CentinelaDeLosEscritoresDeBitacoraTest extends TestCase
{
    /**
     * Los diez, con el reloj que usa cada uno. Medido el 24 ago 2026.
     *
     * Diez y no nueve: el primer recuento sumó mal ocho ficheros y publicó 9.
     * Lo cazó este test en su primera ejecución, que es exactamente para lo que
     * está — un número a mano se equivoca la primera vez, no la décima.
     *
     * El valor NO es cosmético: es lo que decide si las filas de ese sitio se
     * cuentan como UTC o como Bogotá. `now()` y `Carbon::now()` son UTC porque
     * `config/app.php` dice `'timezone' => 'UTC'`; el resto pasa la zona a mano.
     *
     * @var array<string, string>
     */
    private const ESCRITORES = [
        'app/Http/Middleware/ExigirPersonaPropia.php' => 'UTC',
        'app/Http/Middleware/ExigirBoletinPropio.php' => 'UTC',
        'app/Services/Sesion.php' => 'UTC',
        'app/Services/Login.php' => 'Bogotá',
        'app/Http/Controllers/SubunidadesController.php' => 'Bogotá',
        'app/Http/Controllers/YearsController.php' => 'Bogotá',
    ];

    /**
     * Los dos que escriben más de una vez, con cuántas. Van aparte porque el
     * conteo por fichero es lo que caza un INSERT nuevo dentro de un fichero
     * que ya escribía — el caso que una lista de nombres deja pasar entero.
     *
     * @var array<string, int>
     */
    private const CON_VARIOS = [
        'app/Http/Controllers/NotasController.php' => 2,
        'app/Http/Controllers/DefinitivasPeriodosController.php' => 2,
    ];

    private const TOTAL_ESPERADO = 10;

    #[Test]
    public function los_escritores_de_bitacora_siguen_siendo_diez(): void
    {
        $encontrados = $this->insertsPorFichero();
        $total = array_sum($encontrados);

        $this->assertSame(
            self::TOTAL_ESPERADO,
            $total,
            'Los `INSERT INTO bitacoras` han pasado de '.self::TOTAL_ESPERADO." a {$total}.\n\n".
            "No es un fallo: es una decisión sin tomar. Quien haya añadido (o quitado) uno\n".
            "tiene que decir EN QUÉ RELOJ escribe y actualizar las constantes\n".
            "ESCRITOS_EN_UTC / ESCRITOS_EN_BOGOTA de tools/salud-de-la-bitacora.php,\n".
            "que es lo que reparte las filas entre UTC y Bogotá. Sin eso la herramienta\n".
            "sigue imprimiendo un reparto con toda confianza y cuenta de menos.\n\n".
            'Encontrados ahora: '.json_encode($encontrados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function cada_escritor_sigue_estando_donde_estaba(): void
    {
        $encontrados = $this->insertsPorFichero();
        $esperados = array_merge(
            array_map(static fn (): int => 1, self::ESCRITORES),
            self::CON_VARIOS
        );

        ksort($encontrados);
        ksort($esperados);

        $this->assertSame(
            $esperados,
            $encontrados,
            "La lista de ficheros que escriben en `bitacoras` ha cambiado.\n\n".
            "Un fichero nuevo aquí necesita una entrada en ESCRITOS_EN_UTC o en\n".
            "ESCRITOS_EN_BOGOTA de tools/salud-de-la-bitacora.php, con el tipo que\n".
            'escribe y el reloj que usa. Ver docs/migracion/18-auditoria.md §1.1.'
        );
    }

    /**
     * Y el que de verdad muerde: que los tres de UTC lo sigan siendo.
     *
     * El centinela de arriba caza que aparezca un escritor. Éste caza lo
     * contrario, que no se ve en ningún conteo: que alguien **cambie el reloj**
     * de un escritor que ya existe —de `now()` a `Carbon::now('America/Bogota')`
     * o al revés— sin tocar la herramienta. Los ficheros seguirían siendo los
     * mismos, el total seguiría siendo diez, y el reparto pasaría a mentir.
     */
    #[Test]
    public function los_escritores_en_utc_siguen_usando_el_reloj_en_utc(): void
    {
        foreach (self::ESCRITORES as $fichero => $reloj) {
            if ($reloj !== 'UTC') {
                continue;
            }

            $codigo = (string) file_get_contents(base_path($fichero));

            $this->assertMatchesRegularExpression(
                '/(?<![\w>])(?:Carbon::)?now\(\s*\)/',
                $codigo,
                "{$fichero} ya no usa `now()` / `Carbon::now()` sin zona.\n\n".
                "Si se le ha puesto la zona, sus filas dejan de estar en UTC y\n".
                "tools/salud-de-la-bitacora.php tiene que moverlo de ESCRITOS_EN_UTC\n".
                "a ESCRITOS_EN_BOGOTA — con la fecha del cambio, porque a partir de\n".
                'ese despliegue las filas viejas y las nuevas tienen relojes distintos.'
            );
        }
    }

    /**
     * **Un comentario que menciona `INSERT INTO bitacoras` NO es un escritor.**
     *
     * Va con su propio test porque la primera versión de esto contaba con
     * `preg_match_all` sobre el texto del fichero, comentarios incluidos, y eso
     * **falló de verdad el 24 ago 2026**: `8myvc-39` escribió
     * `App\Services\Auditoria` con un docblock que explicaba por qué existe
     * —*«hoy hay 10 INSERT INTO bitacoras repartidos en 8 ficheros»*— y este
     * centinela cantó **once**.
     *
     * **La documentación del escritor único contaba como el escritor número
     * once.** Y el número era correcto: había once coincidencias. Lo que no había
     * era once escritores.
     *
     * Es el mismo error que dio **257 en vez de 256** unas horas antes, y por eso
     * la respuesta es la que este repo ya pagó: **contar sobre tokens y no con
     * una regex.** Los comentarios llegan como `T_COMMENT`/`T_DOC_COMMENT` y una
     * expresión regular no sabe la diferencia; `token_get_all()` sí.
     */
    #[Test]
    public function un_comentario_que_lo_menciona_no_cuenta_como_escritor(): void
    {
        $conComentario = <<<'PHP'
            <?php
            // Aquí antes había un INSERT INTO bitacoras y se quitó.
            /** Ver los INSERT INTO bitacoras del proyecto. */
            class X {
                public function y() {
                    DB::insert('INSERT INTO bitacoras (created_by) VALUES (?)', [1]);
                }
            }
            PHP;

        $this->assertSame(1, $this->contarEnCodigo($conComentario),
            'Cuenta menciones en vez de consultas: es el fallo del 24 ago, cuando '.
            'el docblock de `App\Services\Auditoria` contó como el escritor once.');

        $soloComentarios = <<<'PHP'
            <?php
            // INSERT INTO bitacoras
            /* INSERT INTO bitacoras */
            /** INSERT INTO bitacoras */
            PHP;

        $this->assertSame(0, $this->contarEnCodigo($soloComentarios));
    }

    /**
     * Los `INSERT INTO bitacoras` que hay ahora mismo, por fichero.
     *
     * Cuenta sobre `app/` y no sobre el resultado de una petición a propósito:
     * lo que se está fijando es **el código que puede escribir**, no el que
     * escribió hoy. Un escritor que sólo se dispara en un caso raro cuenta igual
     * — de hecho es el que más, porque es el que nadie recuerda.
     *
     * @return array<string, int>
     */
    private function insertsPorFichero(): array
    {
        $encontrados = [];
        $raiz = base_path('app');

        /** @var iterable<\SplFileInfo> $ficheros */
        $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

        foreach ($ficheros as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $cuantos = $this->contarEnCodigo((string) file_get_contents($fichero->getPathname()));

            if ($cuantos > 0) {
                $relativa = str_replace(base_path().'/', '', $fichero->getPathname());
                $encontrados[$relativa] = $cuantos;
            }
        }

        return $encontrados;
    }

    /**
     * Cuántas consultas —no menciones— hay en un trozo de código.
     *
     * Sólo mira **cadenas literales**: `T_CONSTANT_ENCAPSED_STRING` (las
     * comillas simples y dobles, que es donde vive el SQL de este repo) y
     * `T_ENCAPSED_AND_WHITESPACE` (heredocs y cadenas interpoladas). Los
     * comentarios son `T_COMMENT` y `T_DOC_COMMENT` y **no entran**, que es todo
     * el arreglo.
     *
     * Es método aparte y no un `private` enterrado en el bucle **para que se
     * pueda probar con un trozo de código a mano**, que es lo que permite fijar
     * el caso del docblock sin necesitar un fichero trampa dentro de `app/`.
     */
    private function contarEnCodigo(string $codigo): int
    {
        $cuantos = 0;

        foreach (token_get_all($codigo) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $cuantos += preg_match_all('/INSERT\s+INTO\s+bitacoras/i', $token[1]);
        }

        return $cuantos;
    }
}
