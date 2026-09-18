<?php

namespace App\Support;

/**
 * El catálogo de los **Estándares Básicos de Competencias** del Ministerio de
 * Educación Nacional, empaquetado con el código.
 *
 * Es la **D11** de `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md`, literal:
 * *«El programa las sugiere: catálogo del MEN empaquetado, y el colegio lo
 * adopta»*, porque *«una pantalla que nace en blanco es la que no se usa — es lo
 * que ya le pasó a `frases`»*.
 *
 * ## Fichero de datos, NO tabla sembrada — y qué se gana
 *
 * El catálogo vive en `resources/datos/catalogo-men.json` y **no se siembra en
 * ninguna base**. Corregir una errata del MEN es cambiar un fichero y desplegar;
 * con una tabla sembrada serían **dieciséis migraciones** y, peor, dieciséis
 * copias que ya habrían divergido. Es el mismo invariante que la plantilla:
 * **el catálogo siembra, no manda**.
 *
 * ## Y desde el 17 sep 2026 **esta API no adopta nada**: sugiere
 *
 * Hasta ese día lo adoptaba `CompetenciasController::putCopiar` con
 * `origen.tipo = "men"`, que copiaba el texto a `competencias.definicion`. Esa
 * familia se fue entera con el modelo plano
 * ([39](../../docs/migracion/39-el-modelo-plano-por-competencias.md)), y **la
 * adopción no se mudó a `desempenos/copiar`**: no es un olvido, es la **D12** —el
 * MEN publica por *conjunto de grados* y el desempeño va **por periodo**, así que
 * no hay forma de repartir un estándar en cuatro periodos que no sea decidirlo el
 * colegio—.
 *
 * O sea que hoy este catálogo se **lee** por `GET desempenos/catalogo-men` y el
 * texto lo copia **quien mira la pantalla**, con un `POST desempenos` por fila. El
 * invariante no cambia y por eso sigue valiendo entero: **lo escrito es del
 * colegio**, y si mañana este fichero cambia una tilde, la fila del colegio no se
 * entera.
 *
 * ## Lo que el MEN no cubre nace vacío, y hay que DECIRLO
 *
 * D11 lo deja escrito: los EBC llegan a **Lenguaje, Matemáticas, Ciencias
 * Naturales, Ciencias Sociales, Competencias Ciudadanas e Inglés**. *«Religión,
 * Artes, Ed. Física y Tecnología se escriben una vez.»*
 *
 * **Eso no es un fallo y no se contesta con un 404.** Se contesta con la lista
 * vacía y el motivo, y hay dos motivos distintos que este fichero separa a
 * propósito:
 *
 * | motivo | qué significa |
 * |---|---|
 * | `sin_estandares` | el MEN **no publica** Estándares Básicos para esa área. Es definitivo: la pantalla puede decir «el MEN no publica estándares de Educación Religiosa» |
 * | `sin_emparejar`  | la materia **no se reconoció**. Puede ser una de las que el MEN no cubre, o puede ser Lenguaje escrito de una forma que este fichero no conoce |
 *
 * Juntarlos en un «no hay nada» daría el mismo silencio a *«el MEN no lo
 * publica»* y a *«no te entendí»*, y el colegio no podría distinguir la
 * limitación del despiste. Es «población, no `OK`» aplicado a un catálogo.
 *
 * ## El emparejamiento por nombre es una SUGERENCIA, y se dice cuál usó
 *
 * `materias` es **texto libre por colegio**: en la copia de desarrollo hay
 * *«CIENCIAS SOCIALES, HISTORIA GEOGRAFÍA, CONSTITUCIÓN, POLÍTICA Y DEMOCRACIA»*
 * y *«CIENCIAS NATURALES  Y EDUCACIÓN AMBIENTAL»* —con dos espacios—. No existe
 * ninguna columna que diga qué área del MEN es cada materia, así que **se
 * adivina por el nombre**, y la respuesta dice **qué clave casó** para que se
 * pueda ver que la adivinanza fue la buena. El que no esté de acuerdo manda
 * `area` a mano y este emparejador no opina.
 *
 * ## Por qué `str_contains` sobre el nombre normalizado y no algo más listo
 *
 * Porque lo que hay que resolver es un nombre largo que **contiene** el del área,
 * no dos nombres parecidos. Una distancia de edición entre *«CIENCIAS SOCIALES,
 * HISTORIA GEOGRAFÍA, CONSTITUCIÓN, POLÍTICA Y DEMOCRACIA»* y *«Ciencias
 * Sociales»* es enorme y no casaría; el `str_contains` casa. Gana **la clave más
 * larga**, que es lo que hace que *«ciencias naturales»* gane a *«ciencias»*.
 */
class CatalogoDelMen
{
    /**
     * El fichero ya leído. **Vive lo que vive la petición** y por eso no hay nada
     * que invalidar: es un fichero del despliegue, no una fila que alguien pueda
     * cambiar mientras se contesta.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $catalogo = null;

    /** Para los tests, que corren mil peticiones en un proceso. */
    public static function olvidar(): void
    {
        self::$catalogo = null;
    }

    public static function fichero(): string
    {
        return resource_path('datos/catalogo-men.json');
    }

    /** @return array<string, mixed> */
    public static function cargar(): array
    {
        if (self::$catalogo === null) {
            $crudo = @file_get_contents(self::fichero());

            if ($crudo === false) {
                abort(500, 'Falta el catálogo del MEN: '.self::fichero());
            }

            $leido = json_decode($crudo, true);

            if (! is_array($leido)) {
                abort(500, 'El catálogo del MEN no es un JSON válido: '.self::fichero());
            }

            self::$catalogo = $leido;
        }

        return self::$catalogo;
    }

    /**
     * Los cinco conjuntos de grados del MEN, por clave.
     *
     * @return array<string, array{nombre: string, grados: list<int>}>
     */
    public static function conjuntos(): array
    {
        return self::cargar()['conjuntos'];
    }

    /**
     * Las áreas que el MEN sí cubre, sin sus competencias dentro — para pintar un
     * selector sin arrastrar el catálogo entero.
     *
     * @return list<array{clave: string, nombre: string, documento: string, competencias: int}>
     */
    public static function areas(): array
    {
        $salida = [];

        foreach (self::cargar()['areas'] as $area) {
            $salida[] = [
                'clave' => $area['clave'],
                'nombre' => $area['nombre'],
                'documento' => $area['documento'],
                'competencias' => count($area['competencias']),
            ];
        }

        return $salida;
    }

    /** @return array<string, mixed>|null */
    public static function area(?string $clave): ?array
    {
        if ($clave === null || $clave === '') {
            return null;
        }

        foreach (self::cargar()['areas'] as $area) {
            if ($area['clave'] === $clave) {
                return $area;
            }
        }

        return null;
    }

    /**
     * A qué área del MEN se parece el nombre de una materia del colegio.
     *
     * Devuelve siempre las tres cosas —el área, el motivo y la clave que casó—
     * porque **quien pregunta necesita poder explicar el «no»**:
     *
     *     ['area' => 'lenguaje',  'motivo' => 'emparejada',      'por' => 'lengua castellana']
     *     ['area' => null,        'motivo' => 'sin_estandares',  'por' => 'educacion fisica']
     *     ['area' => null,        'motivo' => 'sin_emparejar',   'por' => null]
     *
     * ## Gana la clave más larga, y se miran las DOS listas a la vez
     *
     * Las dos reglas salieron de medir el emparejador contra las **35 materias de
     * la copia de desarrollo**, y cada una arregla un fallo real que tenía:
     *
     * | materia del colegio | decía | por qué | dice ahora |
     * |---|---|---|---|
     * | `EDUCACION FISICA, RECREACION Y DEPORTES` | **Ciencias Naturales** | «fisica» casaba, y las áreas se miraban antes que las no cubiertas | sin estándares, por `educacion fisica` |
     * | `DIMENSIÓN ESTÉTICA` | **Competencias Ciudadanas** | «etica» **dentro de «estética»** | sin estándares, por `dimension estetica` |
     *
     * El segundo es el que vale la pena recordar: un `str_contains` suelto empareja
     * *estética* con *ética* y **manda las competencias ciudadanas a la clase de
     * arte**. Por eso el emparejamiento va con **límite de palabra** y no con
     * subcadena, y por eso las dos listas compiten juntas en vez de una primero.
     *
     * @return array{area: ?string, motivo: string, por: ?string}
     */
    public static function areaDeMateria(?string $materia): array
    {
        $normal = self::normalizar((string) $materia);

        if ($normal === '') {
            return ['area' => null, 'motivo' => 'sin_emparejar', 'por' => null];
        }

        $candidatas = [];

        foreach (self::cargar()['areas'] as $area) {
            foreach ($area['materias'] as $clave) {
                $candidatas[] = [$clave, $area['clave']];
            }
        }

        // Las que el MEN **no publica**. Compiten en la misma lista y con la misma
        // regla: si no, «educacion fisica» pierde contra «fisica» por el orden.
        foreach (self::cargar()['areas_sin_estandares'] as $sin) {
            foreach ($sin['materias'] as $clave) {
                $candidatas[] = [$clave, null];
            }
        }

        $mejorClave = null;
        $mejorArea = null;

        foreach ($candidatas as [$clave, $area]) {
            if (! self::contienePalabra($normal, $clave)) {
                continue;
            }

            if ($mejorClave === null || mb_strlen($clave) > mb_strlen($mejorClave)) {
                $mejorClave = $clave;
                $mejorArea = $area;
            }
        }

        if ($mejorClave === null) {
            return ['area' => null, 'motivo' => 'sin_emparejar', 'por' => null];
        }

        return [
            'area' => $mejorArea,
            'motivo' => $mejorArea === null ? 'sin_estandares' : 'emparejada',
            'por' => $mejorClave,
        ];
    }

    /**
     * ¿Está esta clave en el texto **como palabra**, y no dentro de otra?
     *
     * Las dos son ya texto normalizado —minúsculas, sin tildes y sin puntuación—,
     * así que basta con mirar que a los lados haya un espacio o el borde de la
     * cadena. Se hace a mano y no con `\b` de PCRE porque la clave puede llevar
     * espacios (`educacion fisica`) y porque así no hay que escapar nada.
     */
    private static function contienePalabra(string $texto, string $clave): bool
    {
        return str_contains(' '.$texto.' ', ' '.$clave.' ');
    }

    /**
     * A qué conjunto de grados del MEN pertenece un grado del colegio.
     *
     * `grados` tampoco tiene ninguna columna que lo diga: se lee el número de
     * `abrev` —que en la copia de desarrollo es literalmente `"1"` … `"11"`— y si
     * ahí no hay número, del `nombre` («Primero» … «Once»).
     *
     *     ['conjunto' => '6-7', 'grado' => 6,    'motivo' => 'emparejado']
     *     ['conjunto' => null,  'grado' => 0,    'motivo' => 'preescolar']
     *     ['conjunto' => null,  'grado' => null, 'motivo' => 'sin_emparejar']
     *
     * **Preescolar sale con su propio motivo y no como «no te entendí»**: los EBC
     * empiezan en 1.º y eso es un hecho del documento, no un fallo del colegio.
     * Lo suyo son los Derechos Básicos de Aprendizaje de Transición, que **no
     * están en este fichero** (ver el `README` de `resources/datos/`).
     *
     * @return array{conjunto: ?string, grado: ?int, motivo: string}
     */
    public static function conjuntoDeGrado(?string $abrev, ?string $nombre): array
    {
        $numero = self::numeroDeGrado($abrev) ?? self::numeroDeGrado($nombre);

        if ($numero === null) {
            $texto = self::normalizar((string) $abrev).' '.self::normalizar((string) $nombre);

            foreach (['prejardin', 'prekinder', 'kinder', 'jardin', 'transicion', 'parvulos', 'preescolar', 'pre'] as $clave) {
                if (preg_match('/\b'.$clave.'/', $texto) === 1) {
                    return ['conjunto' => null, 'grado' => 0, 'motivo' => 'preescolar'];
                }
            }

            return ['conjunto' => null, 'grado' => null, 'motivo' => 'sin_emparejar'];
        }

        foreach (self::conjuntos() as $clave => $conjunto) {
            if (in_array($numero, $conjunto['grados'], true)) {
                return ['conjunto' => $clave, 'grado' => $numero, 'motivo' => 'emparejado'];
            }
        }

        return ['conjunto' => null, 'grado' => $numero, 'motivo' => 'fuera_de_rango'];
    }

    /**
     * **Los tres granos del catálogo, y por qué no son un capricho.**
     *
     * Al transcribir los documentos del MEN salió una cosa que el plan de la Fase
     * 2 daba por uniforme y no lo es: **sólo cuatro de las seis áreas publican
     * «enunciado identificador»**.
     *
     * | `tipo` | quién lo publica | qué es |
     * |---|---|---|
     * | `enunciado` | Lenguaje, Ciencias Naturales, Ciencias Sociales, Competencias Ciudadanas | el **enunciado identificador** del MEN, que es exactamente una competencia |
     * | `eje` | Matemáticas e Inglés | el título de la columna —el tipo de pensamiento, la habilidad—, porque **esas dos guías no traen enunciado**: la expresión «enunciado identificador» sale siete veces en la Guía 3 y las siete en el capítulo de Lenguaje |
     * | `estandar` | Matemáticas e Inglés | la viñeta suelta, que es el grano más fino del documento |
     *
     * Para Matemáticas lo dice el propio MEN (Guía 3, pág. 76): *«los Estándares
     * Básicos de Competencias en Matemáticas seleccionan algunos de los niveles de
     * avance en el desarrollo de las competencias asociadas con los cinco tipos de
     * pensamiento matemático»* — o sea que **la competencia es el pensamiento** y
     * el estándar es un nivel de avance dentro de ella.
     *
     * **Por eso adoptar trae `enunciado` y `eje` y no `estandar`.** Un colegio que
     * adoptara Inglés de 10.º-11.º con los estándares dentro se llevaría **49
     * filas** donde quería cinco. Los `estandar` viajan igual —son el documento— y
     * se piden a propósito, uno a uno por su código.
     *
     * @param  list<string>|null  $tipos  `null` es todos
     * @return list<array{codigo: string, conjunto: string, grupo: string, tipo: string, definicion: string}>
     */
    public static function competencias(?string $areaClave, ?string $conjunto = null, ?array $tipos = null): array
    {
        $area = self::area($areaClave);

        if ($area === null) {
            return [];
        }

        $salida = [];

        foreach ($area['competencias'] as $competencia) {
            if ($conjunto !== null && $competencia['conjunto'] !== $conjunto) {
                continue;
            }

            if ($tipos !== null && ! in_array($competencia['tipo'], $tipos, true)) {
                continue;
            }

            $salida[] = $competencia;
        }

        return $salida;
    }

    /** Los tipos que se adoptan si nadie dice otra cosa: el grano de competencia. */
    public const ADOPTABLES = ['enunciado', 'eje'];

    /**
     * Sin tildes, sin mayúsculas, sin puntuación y sin espacios de sobra.
     *
     * El `strtr` es a mano y no `iconv('ASCII//TRANSLIT')` por lo de siempre: el
     * resultado de `iconv` depende del locale del servidor, y **los dieciséis
     * colegios no corren en el mismo**. Un emparejador que casa en desarrollo y
     * no en producción es peor que uno que no casa nunca.
     */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto) ?? '';

        return trim(preg_replace('/\s+/', ' ', $texto) ?? '');
    }

    /** El número de grado que haya dentro de un texto, o `null`. */
    private static function numeroDeGrado(?string $texto): ?int
    {
        $normal = self::normalizar((string) $texto);

        if ($normal === '') {
            return null;
        }

        if (preg_match('/\b(\d{1,2})\b/', $normal, $partes) === 1) {
            $numero = (int) $partes[1];

            return $numero >= 1 && $numero <= 11 ? $numero : null;
        }

        $ordinales = [
            'primero' => 1, 'primer' => 1, 'segundo' => 2, 'tercero' => 3, 'tercer' => 3,
            'cuarto' => 4, 'quinto' => 5, 'sexto' => 6, 'septimo' => 7, 'setimo' => 7,
            'octavo' => 8, 'noveno' => 9, 'decimo' => 10, 'undecimo' => 11,
            'once' => 11, 'onceavo' => 11, 'undecimo grado' => 11,
        ];

        foreach ($ordinales as $palabra => $numero) {
            if (preg_match('/\b'.$palabra.'\b/', $normal) === 1) {
                return $numero;
            }
        }

        return null;
    }
}
