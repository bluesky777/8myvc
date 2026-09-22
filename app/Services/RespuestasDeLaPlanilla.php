<?php

namespace App\Services;

/**
 * Lo que el docente decidió sobre el libro de notas que está subiendo.
 *
 * Es la hermana de {@see RespuestasDeLaImportacion} para «notas sin internet»
 * (fase 2 del plan `myvc_front/PLAN-NOTAS-SIN-INTERNET.md`, §5). Viaja **con el
 * fichero** en el mismo multipart, no por una ruta propia: es parte de «sube esto
 * con estas instrucciones» y no un recurso aparte.
 *
 * ## La forma la fija el front, y se lee tal cual
 *
 * El documento que llega es el que construye `app2/src/app/datos/planilla-offline.ts`:
 *
 * ```
 * { version: 1, huella,
 *   estructura?: { hoja, columna, decision: 'fuera' | 'mover:<subunidad_id>' }[],
 *   celdas?:     { valor, decision: 'dejar' | 'revisar' | 'interpretar:<n>' }[],
 *   escala?:     { valor, decision: 'fuera' | 'topar' }[],
 *   choques?:    { por_defecto: 'archivo'|'sistema',
 *                  excepciones: { id, decision: 'archivo'|'sistema' }[] },
 *   reserva?:    { hoja, columna, decision: 'crear'|'fuera', nombre?, peso? }[],
 *   filas?:      { id, decision: 'es:<alumno_id>' | 'fuera' }[],
 *   ausencias?:  { hoja, tipo: 'ausencias'|'tardanzas', direccion: 'sube'|'baja',
 *                  decision: 'aplicar' | 'dejar' }[] }
 * ```
 *
 * **La decisión y su parámetro van en la misma cadena** (`mover:1204`,
 * `interpretar:5`), y eso no es un capricho de formato: hace imposible el estado a
 * medias que aquí sería caro —un `mover` sin destino, un `interpretar` sin
 * número—. Se parte al leer, y si el parámetro no es un número la decisión **cae
 * al defecto** en vez de aplicarse a medias.
 *
 * ## Las llaves, que son tres y distintas a propósito
 *
 * | Familia | Llave | Por qué |
 * |---|---|---|
 * | `celdas`, `escala` | **el valor suelto** | Una hoja con doce `4,5` es **una** pregunta. Es la regla del importador de alumnos: trece renglones se revisan, ochocientos no |
 * | `estructura`, `reserva` | **`hoja` + `columna`** | En un libro de doce hojas, la `F` de *4A Matemáticas* y la `F` de *3B Geometría* son indicadores distintos |
 * | `choques` | **el `id` del choque** | Un choque es la nota de un alumno concreto, y la pantalla de §6.3 existe para verlos uno a uno. El `id` lo emite el ensayo |
 * | `filas` | **el `id` de la fila** | **La única familia que se decide fila a fila**, y lo es porque cada fila es una persona distinta (§5 del plan). El `id` lo emite el ensayo |
 * | `ausencias` | **`hoja` + `tipo` + `direccion`** | La F8 se decide por columna, y **la dirección parte la columna en dos**: subir y bajar no son la misma pregunta ni tienen el mismo precio |
 *
 * ## Ninguna ausencia significa nada
 *
 * Cada renglón trae su `decision` escrita, `revisar` incluido. Que un valor no esté
 * en la lista significa **que no se ha decidido**, y eso tiene que poder
 * distinguirse de «se decidió dejarlo como está»: lo primero es un problema que la
 * pantalla sigue enseñando, lo segundo es un problema resuelto.
 *
 * Y el defecto de cada familia es **el que no pierde trabajo**: una celda que no se
 * entiende **no se escribe**, una nota fuera de escala **no se escribe**, una
 * columna cuyo indicador desapareció **no se escribe**, y en un choque **manda el
 * sistema**. Todos los defectos son «no tocar», que es la única elección que se
 * puede deshacer volviendo a subir el archivo.
 *
 * ## LA EXCEPCIÓN, Y ES EL PUNTO ENTERO DE LA FASE 4
 *
 * `ausencias` es **la única familia cuyo defecto no es el mismo en las dos
 * direcciones**, y esa asimetría no es una comodidad de pantalla: es la regla de
 * negocio de la §3.6 del plan escrita en código.
 *
 * | Dirección | Defecto | Por qué |
 * |---|---|---|
 * | `sube` | **`aplicar`** | Añadir faltas no borra nada. Lo peor que puede pasar es que queden fechadas el día de la importación, y eso se ve, se explica y se corrige falta a falta |
 * | `baja` | **`dejar`** | Bajar un conteo **borra filas con sus fechas**, y esas fechas son las que leen las planillas de ausencias de los acudientes. No ocurre sin que alguien lo pida con esa sección puesta |
 *
 * O sea que el principio de arriba —«el defecto es el que no pierde trabajo»— se
 * mantiene entero: lo que no se puede deshacer volviendo a subir el archivo es el
 * borrado, y ése es justo el que necesita que alguien lo pida.
 *
 * ## Y lo que NO se aplica se dice
 *
 * {@see NO_APLICADAS} es el mismo mecanismo del importador de alumnos: una sección
 * que se acepta y no se interpreta **se declara en la respuesta con su motivo**.
 * **Hoy está vacía**: las filas que no se reconocen (F6) salieron en la fase 3 y
 * las ausencias y tardanzas (F8/D5) en la fase 4. Se deja el mecanismo montado
 * porque la lista se vuelve a llenar en cuanto haya una fase que prometa algo que
 * todavía no hace.
 */
class RespuestasDeLaPlanilla
{
    /**
     * Las secciones que cambian lo que hace la importación.
     *
     * `firma` va al final y **no está en el documento que manda el front**: es la
     * única forma que hay hoy de resolver el peldaño 2 de la §4.7 —confirmar la
     * lista de un libro con la firma rota— y se acepta para no dejar ese camino sin
     * salida. Está anotado como pendiente de reconciliar con el contrato del front.
     *
     * @var list<string>
     */
    private const APLICADAS = ['estructura', 'celdas', 'escala', 'choques', 'reserva', 'filas',
        'ausencias', 'firma'];

    /**
     * Las que se aceptan y **no** se interpretan, con el motivo que la respuesta
     * enseña.
     *
     * **Está vacía desde la fase 4**, y el mecanismo se queda montado a propósito:
     * es lo único que impide que el asistente prometa que algo entró cuando la fase
     * que lo escribe todavía no existe. Volverá a llenarse con la fase 5.
     *
     * Que el front borre el paso entero de una sección declarada aquí es
     * **funcionamiento y no cosmética** —lo hizo la F6 al salir en la fase 3—: es
     * mejor no enseñar botones que el servidor no va a obedecer.
     *
     * @var array<string, string>
     */
    private const NO_APLICADAS = [];

    /** La celda que no es un entero (F4). */
    public const CELDA_INTERPRETAR = 'interpretar';

    public const CELDA_DEJAR = 'dejar';

    public const CELDA_REVISAR = 'revisar';

    /** La nota que no cabe en la escala (F5). */
    public const ESCALA_FUERA = 'fuera';

    public const ESCALA_TOPAR = 'topar';

    /** La columna cuyo indicador ya no existe (F2). */
    public const COLUMNA_FUERA = 'fuera';

    public const COLUMNA_MOVER = 'mover';

    /** La columna de reserva con notas escritas (F9, D12). */
    public const RESERVA_CREAR = 'crear';

    public const RESERVA_FUERA = 'fuera';

    /** Quién manda cuando la celda cambió en los dos sitios (F7). */
    public const CHOQUE_SISTEMA = 'sistema';

    public const CHOQUE_ARCHIVO = 'archivo';

    /** La fila que no se reconoce (F6). `es:<alumno_id>` lleva la persona dentro. */
    public const FILA_ES = 'es';

    public const FILA_FUERA = 'fuera';

    /** El conteo de ausencias o tardanzas que cambió (F8, D5). */
    public const AUSENCIAS_APLICAR = 'aplicar';

    public const AUSENCIAS_DEJAR = 'dejar';

    /** Las dos direcciones, que no valen lo mismo y por eso llevan nombre. */
    public const SUBE = 'sube';

    public const BAJA = 'baja';

    /** Los dos conteos, **en plural**, que es como los escribe el contrato del front. */
    public const AUSENCIAS = 'ausencias';

    public const TARDANZAS = 'tardanzas';

    /** @param array<string, mixed> $crudas */
    public function __construct(private array $crudas) {}

    /**
     * Si estas respuestas son de OTRO fichero.
     *
     * Igual que en el importador de alumnos, y por el mismo agujero: alguien
     * aprueba doce decisiones, corrige una celda y sube. Las respuestas siguen
     * encajando —casan por valor, no por contenido del libro— y se aplicarían a un
     * archivo que nadie revisó.
     *
     * Aquí es **más fácil de disparar** que allí: la forma normal de trabajar es
     * «corrijo el Excel y vuelvo a subir», así que la huella cambia de verdad y a
     * menudo.
     *
     * Unas respuestas sin `huella` se aceptan: exigirla rompería a quien mande
     * instrucciones a mano, y lo que se busca es cazar el cambio silencioso.
     */
    public function sonDeOtroFichero(string $huellaDelFichero): bool
    {
        $suya = $this->crudas['huella'] ?? null;

        return is_string($suya) && $suya !== '' && $suya !== $huellaDelFichero;
    }

    /**
     * Si el docente confirmó la lista de un libro con **la firma rota** (peldaño 2
     * de la §4.7).
     *
     * No es una casilla de «acepto»: es la única forma de que el peldaño 2 sea
     * distinto del 1. Con la firma rota el espejo no vale, así que **todas** las
     * celdas con valor cuentan como cambiadas —no se puede saber cuáles tocó— y lo
     * que se pide confirmar es esa lista entera, no la firma.
     */
    public function confirmaLaFirmaRota(): bool
    {
        $firma = $this->crudas['firma'] ?? null;

        if (! is_array($firma)) {
            return false;
        }

        return ($firma['decision'] ?? null) === 'confirmo';
    }

    /**
     * Qué hacer con una celda cuyo texto no es un entero (F4).
     *
     * **La llave es el texto crudo de la celda**, tal y como salió del `.xlsx`:
     * `4,5`, `N/A`, `=D3*2`, `Excelente`. Se compara normalizado —sin espacios de
     * los lados— porque un `" 4,5"` y un `"4,5"` son el mismo problema para quien
     * lo mira, y tener que decidirlos por separado es exactamente lo que esta clase
     * viene a evitar.
     *
     * `interpretar:<n>` lleva el número **dentro de la decisión**. Sin número no
     * interpreta nada y cae a `dejar`: aplicar un cero por defecto sería regalarle
     * al alumno la peor nota posible por un fallo de forma.
     *
     * @return array{decision: string, nota: ?int}
     */
    public function queHacerConLaCelda(string $valor): array
    {
        foreach ($this->seccion('celdas') as $renglon) {
            if (! $this->mismoValor($renglon['valor'] ?? null, $valor)) {
                continue;
            }

            [$verbo, $parametro] = $this->partir($renglon['decision'] ?? null);

            if ($verbo === self::CELDA_INTERPRETAR && $parametro !== null && is_numeric($parametro)) {
                return ['decision' => self::CELDA_INTERPRETAR, 'nota' => (int) $parametro];
            }

            return [
                'decision' => $verbo === self::CELDA_REVISAR ? self::CELDA_REVISAR : self::CELDA_DEJAR,
                'nota' => null,
            ];
        }

        return ['decision' => self::CELDA_DEJAR, 'nota' => null];
    }

    /**
     * Qué hacer con una nota que no cabe en la escala del año (F5).
     *
     * También por valor: un colegio que pasó de 0-100 a 0-50 recibe hojas enteras
     * llenas de `70`, `80` y `95`, y son **tres** decisiones y no doscientas.
     *
     * El defecto es `fuera` —no se escribe— y no `topar`: topar un 95 a 50 en un
     * colegio de 0 a 50 convierte «el docente calificó sobre 100» en «todos
     * sacaron la máxima», que es un dato falso y creíble.
     */
    public function queHacerConLaEscala(string $valor): string
    {
        foreach ($this->seccion('escala') as $renglon) {
            if ($this->mismoValor($renglon['valor'] ?? null, $valor)) {
                return $this->partir($renglon['decision'] ?? null)[0] === self::ESCALA_TOPAR
                    ? self::ESCALA_TOPAR
                    : self::ESCALA_FUERA;
            }
        }

        return self::ESCALA_FUERA;
    }

    /**
     * Qué hacer con una columna cuyo indicador ya no existe (F2).
     *
     * **La llave es `hoja` + `columna`**, nunca la columna sola: en un libro de doce
     * hojas la `F` de *4A Matemáticas* y la `F` de *3B Geometría* son indicadores
     * distintos, y una decisión que se colara de una hoja a otra escribiría notas en
     * la asignatura equivocada **sin dar error**.
     *
     * `mover:<subunidad_id>` es el caso de verdad frecuente: coordinación borró el
     * indicador y creó otro con el texto corregido, y las notas de la columna son de
     * ése.
     *
     * @return array{decision: string, subunidad_id: ?int}
     */
    public function queHacerConLaColumna(string $hoja, string $columna): array
    {
        foreach ($this->seccion('estructura') as $renglon) {
            if ((string) ($renglon['hoja'] ?? '') !== $hoja
                || (string) ($renglon['columna'] ?? '') !== $columna) {
                continue;
            }

            [$verbo, $parametro] = $this->partir($renglon['decision'] ?? null);

            if ($verbo === self::COLUMNA_MOVER && $parametro !== null && is_numeric($parametro)) {
                return ['decision' => self::COLUMNA_MOVER, 'subunidad_id' => (int) $parametro];
            }

            return ['decision' => self::COLUMNA_FUERA, 'subunidad_id' => null];
        }

        return ['decision' => self::COLUMNA_FUERA, 'subunidad_id' => null];
    }

    /**
     * Qué hacer con una columna **de reserva** en la que el docente escribió (F9).
     *
     * `crear` necesita nombre, y en modo `porcentaje` también peso: es la §9.7 del
     * plan —el Logro tiene que seguir sumando 100— y por eso el peso lo da el
     * docente y **no se reparte solo**, que cambiaría notas ya guardadas.
     *
     * Un `crear` sin nombre cae a `fuera`: un indicador llamado `""` es un
     * indicador que nadie va a reconocer en la planilla de la semana que viene.
     *
     * @return array{decision: string, nombre: ?string, peso: ?int}
     */
    public function queHacerConLaReserva(string $hoja, string $columna): array
    {
        foreach ($this->seccion('reserva') as $renglon) {
            if ((string) ($renglon['hoja'] ?? '') !== $hoja
                || (string) ($renglon['columna'] ?? '') !== $columna) {
                continue;
            }

            if ($this->partir($renglon['decision'] ?? null)[0] !== self::RESERVA_CREAR) {
                return ['decision' => self::RESERVA_FUERA, 'nombre' => null, 'peso' => null];
            }

            $nombre = trim((string) ($renglon['nombre'] ?? ''));

            if ($nombre === '') {
                return ['decision' => self::RESERVA_FUERA, 'nombre' => null, 'peso' => null];
            }

            return [
                'decision' => self::RESERVA_CREAR,
                'nombre' => $nombre,
                'peso' => isset($renglon['peso']) && is_numeric($renglon['peso']) ? (int) $renglon['peso'] : null,
            ];
        }

        return ['decision' => self::RESERVA_FUERA, 'nombre' => null, 'peso' => null];
    }

    /**
     * Quién manda en un choque (F7): **por defecto el sistema**.
     *
     * Es «lo seguro» de la pantalla de §6.3 y el motivo está escrito ahí: si el
     * sistema cambió **después** de la descarga, es que alguien lo tocó sabiendo lo
     * que hacía —quizá el propio docente desde el móvil—. El archivo que trae el
     * valor viejo no sabe nada de eso.
     *
     * El front manda `por_defecto` **siempre**, también cuando el docente no tocó
     * nada: su defecto es una decisión que la pantalla ya le enseñó, y callarla
     * dejaría que aquí se aplicara otra.
     */
    public function defectoDeLosChoques(): string
    {
        $choques = $this->crudas['choques'] ?? null;

        $defecto = is_array($choques) ? ($choques['por_defecto'] ?? null) : null;

        return $defecto === self::CHOQUE_ARCHIVO ? self::CHOQUE_ARCHIVO : self::CHOQUE_SISTEMA;
    }

    /**
     * Quién manda en **este** choque, con la excepción por `id` si la hay.
     *
     * Es la única llave celda a celda de toda la clase, y es a propósito: la opción
     * global de la pantalla es un atajo, no una venda, y «ver una por una» tiene que
     * poder decidir una por una.
     *
     * **El `id` lo emite el ensayo** y es estable para el mismo archivo: sale de la
     * hoja, el alumno y el indicador, que son las tres cosas que identifican una
     * casilla y ninguna de las cuales se mueve al reenviar el fichero.
     */
    public function queHacerConElChoque(string $id): string
    {
        $choques = $this->crudas['choques'] ?? null;
        $excepciones = is_array($choques) ? ($choques['excepciones'] ?? []) : [];

        if (is_array($excepciones)) {
            foreach ($excepciones as $renglon) {
                if (! is_array($renglon) || (string) ($renglon['id'] ?? '') !== $id) {
                    continue;
                }

                return $this->partir($renglon['decision'] ?? null)[0] === self::CHOQUE_ARCHIVO
                    ? self::CHOQUE_ARCHIVO
                    : self::CHOQUE_SISTEMA;
            }
        }

        return $this->defectoDeLosChoques();
    }

    /**
     * De quién es una fila que no se reconoce (F6).
     *
     * **La única llave por fila de toda la clase**, y es a propósito: las demás
     * familias agrupan porque doce `4,5` son una sola pregunta, pero aquí cada fila
     * es **una persona distinta** y agrupar sería preguntar por dos a la vez.
     *
     * `es:<alumno_id>` lleva la persona dentro por lo mismo que `mover:<subunidad>`:
     * un «sí, es él» sin decir quién es la peor forma de resolver esto.
     *
     * **El defecto no es `fuera`: es `null`, o sea «todavía no se ha decidido».** Y
     * la diferencia importa más aquí que en ninguna otra familia: las dos hacen lo
     * mismo —esa fila no se escribe— pero una es un problema que la pantalla sigue
     * enseñando y la otra es un problema resuelto. Si el defecto fuera `fuera`, un
     * libro con tres nombres escritos a mano y sin tocar diría que todo está
     * decidido.
     *
     * **Y aquí no se comprueba que el alumno exista ni que esté en el grupo.** Eso
     * lo hace el ensayo, que es quien sabe de qué grupo es la hoja; esta clase lee
     * lo que llegó y no se lo cree.
     *
     * @return array{decision: ?string, alumno_id: ?int}
     */
    public function queHacerConLaFila(string $id): array
    {
        foreach ($this->seccion('filas') as $renglon) {
            if ((string) ($renglon['id'] ?? '') !== $id) {
                continue;
            }

            [$verbo, $parametro] = $this->partir($renglon['decision'] ?? null);

            if ($verbo === self::FILA_ES && $parametro !== null && is_numeric($parametro)
                && (int) $parametro > 0) {
                return ['decision' => self::FILA_ES, 'alumno_id' => (int) $parametro];
            }

            // Un `es:` sin alumno cae a `fuera` y no a «sin decidir»: la persona
            // contestó, lo que llegó no se puede aplicar, y volver a preguntarle lo
            // mismo sin decir nada sería un bucle mudo.
            return ['decision' => self::FILA_FUERA, 'alumno_id' => null];
        }

        return ['decision' => null, 'alumno_id' => null];
    }

    /**
     * Qué hacer con un conteo de ausencias o tardanzas que cambió (F8, D5).
     *
     * **La llave es `hoja` + `tipo` + `direccion`**, y la dirección está ahí porque
     * es la mitad de la pregunta. En la misma columna de la misma hoja puede haber
     * a la vez alumnos a los que el docente les sube las faltas y alumnos a los que
     * se las baja, y **no son la misma decisión**: subir añade filas fechadas el día
     * de la importación y bajar **borra** filas con sus fechas, que son las que leen
     * las planillas de ausencias de los acudientes.
     *
     * De ahí los dos defectos, que es lo único de esta clase que no es simétrico:
     *
     * - `sube` → **`aplicar`**. Añadir no borra nada.
     * - `baja` → **`dejar`**. **Borrar historia no ocurre sin que alguien lo pida**,
     *   y pedirlo es mandar esta sección con `aplicar` para esa hoja, ese tipo y esa
     *   dirección. Un documento de respuestas que no la traiga no borra una sola
     *   fila, por muchas casillas que el docente haya bajado en el Excel.
     *
     * ## Si la sección llega, manda ella. Estos defectos son la red de abajo
     *
     * El renglón de `familias.ausencias[]` **no tiene campo `decision`** —es el
     * contrato que el front construyó—, así que el defecto lo pinta la pantalla y
     * **manda la sección entera con las dos decisiones escritas** en cuanto hay un
     * renglón, igual que hace con `choques`. Aquí eso se lee de una manera: **lo que
     * llega se obedece tal cual**; los defectos de arriba son para el cliente que no
     * manda la sección —uno viejo, un colegio sin desplegar, unas instrucciones
     * escritas a mano— y son **los mismos números** a propósito.
     *
     * Lo que no puede pasar es que los dos lados tengan defectos propios y un día
     * dejen de coincidir en silencio. Por eso viajan explícitos y por eso el ensayo
     * devuelve además `por_defecto` en cada renglón: para que la diferencia, si
     * apareciera, se pueda ver en la propia respuesta.
     *
     * Un renglón cuya tripleta **no** venga en una sección que sí llegó cae también
     * al defecto, y es lo correcto: pasa cuando volver a ensayar destapa una
     * dirección nueva —la base se movió entre la primera pantalla y la subida— y esa
     * pregunta **no se la han hecho a nadie todavía**.
     *
     * `tipo` llega **en plural** (`ausencias` / `tardanzas`), que es como lo escribe
     * el contrato del front; el singular de `ausencias.tipo` en la base es cosa del
     * ensayo y no se cuela hasta aquí. Un tipo o una dirección que no sean de los
     * suyos no casan con ningún renglón y caen al defecto, que es lo prudente.
     */
    public function queHacerConLasAusencias(string $hoja, string $tipo, string $direccion): string
    {
        foreach ($this->seccion('ausencias') as $renglon) {
            if ((string) ($renglon['hoja'] ?? '') !== $hoja
                || (string) ($renglon['tipo'] ?? '') !== $tipo
                || (string) ($renglon['direccion'] ?? '') !== $direccion) {
                continue;
            }

            return $this->partir($renglon['decision'] ?? null)[0] === self::AUSENCIAS_APLICAR
                ? self::AUSENCIAS_APLICAR
                : self::AUSENCIAS_DEJAR;
        }

        return self::porDefectoSegunLaDireccion($direccion);
    }

    /**
     * El defecto de una dirección, **para que la pantalla lo pinte ya marcado**.
     *
     * Es público porque el ensayo lo manda dentro de cada renglón: si el front
     * tuviera que deducirlo, el día que este criterio cambiara habría dos defectos
     * distintos —el que se pinta y el que se aplica— y nadie lo notaría hasta que
     * alguien borrara faltas sin haberlo pedido.
     */
    public static function porDefectoSegunLaDireccion(string $direccion): string
    {
        return $direccion === self::BAJA ? self::AUSENCIAS_DEJAR : self::AUSENCIAS_APLICAR;
    }

    /**
     * Qué llegó, qué se aplicó y qué no — con el motivo al lado.
     *
     * @return array{recibidas:int, aplicadas:list<string>, no_aplicadas:list<array<string,mixed>>}
     */
    public function resumen(): array
    {
        $noAplicadas = [];

        foreach (self::noAplicadas() as $seccion => $motivo) {
            $cuantas = count($this->seccion($seccion));

            if ($cuantas > 0) {
                $noAplicadas[] = ['seccion' => $seccion, 'cuantas' => $cuantas, 'motivo' => $motivo];
            }
        }

        return [
            'recibidas' => $this->cuantasEnTotal(),
            'no_aplicadas' => $noAplicadas,

            // El reverso de `no_aplicadas`, y hace falta desde que esa lista puede
            // venir vacía: sin él, «vacía» se lee igual que «no se calculó».
            'aplicadas' => array_values(array_filter(
                self::APLICADAS,
                fn (string $seccion) => $this->cuantasDeLaSeccion($seccion) > 0
            )),
        ];
    }

    public function hayAlguna(): bool
    {
        return $this->cuantasEnTotal() > 0;
    }

    /** @return array<string, mixed> */
    public function crudas(): array
    {
        return $this->crudas;
    }

    /**
     * Las secciones declaradas como no aplicadas, leídas como array cualquiera.
     *
     * Existe por lo mismo que en `RespuestasDeLaImportacion`: para que el análisis
     * estático no dé el `foreach` por muerto el día que la lista se vacíe. **Ese día
     * llegó** —la fase 4 sacó `ausencias`, que era la última— y la función se queda
     * justo por eso: el mecanismo tiene que seguir en pie para la fase 5.
     *
     * @return array<string, string>
     */
    private static function noAplicadas(): array
    {
        return self::NO_APLICADAS;
    }

    /**
     * Parte `verbo:parámetro` en sus dos mitades.
     *
     * **La decisión y su parámetro viajan juntos** (`mover:1204`, `interpretar:5`), y
     * eso es lo que hace imposible el estado a medias: no hay forma de mandar un
     * `mover` sin destino y que aquí parezca una decisión tomada. Si el parámetro no
     * está o no es número, quien llama cae al defecto.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function partir(mixed $decision): array
    {
        if (! is_string($decision) || $decision === '') {
            return [null, null];
        }

        $trozos = explode(':', $decision, 2);

        return [$trozos[0], $trozos[1] ?? null];
    }

    /**
     * Dos valores de celda son el mismo problema.
     *
     * Se compara como **texto y sin espacios de los lados**: lo que llega del
     * `.xlsx` puede ser `int`, `float` o `string` según por dónde vino, y un
     * `4` y un `"4"` no pueden ser dos decisiones distintas.
     */
    private function mismoValor(mixed $delRenglon, string $valor): bool
    {
        if ($delRenglon === null) {
            return false;
        }

        return trim((string) $delRenglon) === trim($valor);
    }

    private function cuantasEnTotal(): int
    {
        $total = 0;

        foreach (array_merge(self::APLICADAS, array_keys(self::NO_APLICADAS)) as $seccion) {
            $total += $this->cuantasDeLaSeccion($seccion);
        }

        return $total;
    }

    /**
     * Cuántos renglones trae una sección, contando las tres formas que tienen.
     *
     * `choques` es la excepción de forma: llega como objeto con `por_defecto` y
     * `excepciones` porque su decisión **es** global con excepciones (§6.3). `firma`
     * es un objeto suelto. Las demás son listas.
     */
    private function cuantasDeLaSeccion(string $nombre): int
    {
        if ($nombre === 'choques') {
            $choques = $this->crudas['choques'] ?? null;

            if (! is_array($choques)) {
                return 0;
            }

            $excepciones = is_array($choques['excepciones'] ?? null) ? $choques['excepciones'] : [];

            return count($excepciones) + (isset($choques['por_defecto']) ? 1 : 0);
        }

        if ($nombre === 'firma') {
            return $this->confirmaLaFirmaRota() ? 1 : 0;
        }

        return count($this->seccion($nombre));
    }

    /** @return array<int, array<string, mixed>> */
    private function seccion(string $nombre): array
    {
        $valor = $this->crudas[$nombre] ?? [];

        return is_array($valor) ? array_values(array_filter($valor, 'is_array')) : [];
    }
}
