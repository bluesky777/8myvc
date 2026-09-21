<?php

namespace App\Services;

/**
 * Lo que una persona decidió sobre un fichero, leído sin creerse nada.
 *
 * Es lo que la pantalla del ensayo manda con la subida: «CARNÉ DIPLOMÁTICO es
 * Cédula», «Activo es MATR», «la fila 15 es el alumno 1055». Hasta hoy eso se
 * guardaba y se devolvía **y no lo interpretaba nadie**: el coordinador veía qué
 * iba a pasar y no podía cambiarlo.
 *
 * ## La llave de una decisión es el VALOR, no la fila
 *
 * `(columna, valor_original)` y nunca el número de fila. Es literalmente el
 * punto de todo esto: **una decisión y no ochocientas**. Trece renglones se
 * revisan; ochocientos no, y con la fila como llave volveríamos a tener
 * ochocientas decisiones con otro nombre.
 *
 * ## Ninguna ausencia significa nada
 *
 * Cada renglón trae su `decision` escrita, `a_revisar` incluido. Que un valor no
 * esté en la lista significa **que no se ha decidido**, y eso tiene que poder
 * distinguirse de «se decidió dejarlo como está» — es el mismo fallo que la Fase
 * 1 quitó del `tipo_doc = 3`, y sería feo repetirlo en la capa de encima.
 *
 * ## Y lo que NO se aplica se dice
 *
 * Esta clase acepta el documento entero —vocabularios, vacíos, repetidos,
 * duplicados, hojas— pero hoy **sólo se aplican los vocabularios**. Las demás
 * secciones se cuentan y se declaran como no aplicadas en la respuesta, porque
 * una decisión que se ignora en silencio es el mismo silencio que este módulo
 * empezó quitando.
 */
class RespuestasDeLaImportacion
{
    /**
     * Las secciones que cambian lo que hace el importador.
     *
     * **Las cinco desde el 21 sep 2026.** Hasta ese día sólo se aplicaban los
     * vocabularios y las otras cuatro se guardaban y se declaraban como no
     * aplicadas — media funcionalidad, dicha en su propia cara para no prometer
     * nada falso. Lo levantó `myvc-front-51` y lo autorizó Joseth.
     *
     * @var list<string>
     */
    private const APLICADAS = ['vocabularios', 'vacios', 'repetidos', 'duplicados', 'hojas'];

    /**
     * Las que se aceptarían y no se interpretarían, con el motivo que la
     * respuesta enseña. **Hoy no queda ninguna**, y la constante se queda porque
     * es el mecanismo que impide que la pantalla prometa algo que no ocurre: la
     * siguiente sección que entre a medias se declara aquí.
     *
     * @var array<string, string>
     */
    private const NO_APLICADAS = [];

    /**
     * La misma lista, leída como array cualquiera.
     *
     * **Existe sólo para que larastan no la trate como «siempre vacía»** y
     * marque el `foreach` de abajo como muerto: hoy lo está, y el día que entre
     * una sección a medias dejará de estarlo. Silenciar el aviso en
     * `phpstan.neon` escondería el único sitio donde eso se declara.
     *
     * @return array<string, string>
     */
    private static function noAplicadas(): array
    {
        return self::NO_APLICADAS;
    }

    /**
     * Lo que se hace con una celda vacía, y es la sección que más data pierde
     * hoy.
     *
     * El `UPDATE` del importador **escribe todas las columnas**, así que una
     * celda vacía no es «no dice nada»: es «bórralo». Lo dice el propio catálogo
     * del ensayo —*«Se BORRA la que hubiera, porque el UPDATE escribe todas las
     * columnas»*— y es el comportamiento de siempre, así que **`defecto` sigue
     * siendo lo que pasa si nadie decide nada**.
     */
    public const VACIO_DEFECTO = 'defecto';

    public const VACIO_CONSERVAR = 'conservar';

    /** Qué gana cuando el mismo documento sale dos veces en el fichero. */
    public const DUPLICADO_ULTIMA = 'ultima';

    public const DUPLICADO_PRIMERA = 'primera';

    /** Qué se hace con un alumno cuyo documento ya está en la base. */
    public const REPETIDO_ACTUALIZAR = 'actualizar';

    public const REPETIDO_OMITIR = 'omitir';

    /** Qué se hace con una hoja que no casa con ningún grupo del año. */
    public const HOJA_PARAR = 'parar';

    public const HOJA_OMITIR = 'omitir';

    /** @param array<string, mixed> $crudas */
    public function __construct(private array $crudas) {}

    /**
     * Si estas respuestas son de OTRO fichero.
     *
     * Es el agujero que rodea todo este módulo: alguien aprueba trece
     * decisiones, cambia una celda y sube. Las respuestas siguen encajando
     * —casan por nombre de columna, no por contenido— y se aplican a un libro
     * que nadie revisó. **Los números saldrían igual, sólo que serían otros.**
     *
     * Sin `huella` no se puede comprobar, así que unas respuestas que no la
     * traen se aceptan: exigirla rompería a quien mande instrucciones a mano, y
     * lo que se busca es cazar el cambio silencioso, no imponer un formato.
     */
    public function sonDeOtroFichero(string $huellaDelFichero): bool
    {
        $suya = $this->crudas['huella'] ?? null;

        return is_string($suya) && $suya !== '' && $suya !== $huellaDelFichero;
    }

    /**
     * Las equivalencias en la forma que entiende `ImporterFixer`:
     * campo => [valor del fichero => valor bueno].
     *
     * `a_revisar` **no produce equivalencia**, y es una decisión, no un olvido:
     * significa «no lo decido todavía», así que el valor sigue sin reconocerse y
     * sigue dejando su aviso. Convertirlo en el valor por defecto sería
     * exactamente lo que la Fase 1 vino a quitar — decidir por alguien y no
     * decirlo.
     *
     * @return array<string, array<string, mixed>>
     */
    public function equivalencias(): array
    {
        $mapa = [];

        foreach ($this->seccion('vocabularios') as $renglon) {
            $columna = $renglon['columna'] ?? null;
            $original = $renglon['valor_original'] ?? null;
            $decision = $renglon['decision'] ?? null;

            if (! is_string($columna) || $original === null) {
                continue;
            }

            if ($decision === 'usar_id' && isset($renglon['id'])) {
                $mapa[$columna][(string) $original] = $renglon['id'];
            }

            if ($decision === 'usar_valor' && isset($renglon['valor'])) {
                $mapa[$columna][(string) $original] = $renglon['valor'];
            }
        }

        return $mapa;
    }

    /**
     * Qué hacer con la celda vacía de esta columna.
     *
     * **La llave es la COLUMNA, nunca la fila** — es la regla del §5.5 del 45, y
     * es el punto de todo esto: una decisión y no ochocientas.
     *
     * `a_revisar` devuelve el defecto a propósito: significa «no lo decido
     * todavía», y lo que pasa mientras tanto es lo que pasaba antes. Decidir por
     * alguien y no decirlo es lo que este módulo vino a quitar.
     */
    public function queHacerConVacio(string $columna): string
    {
        foreach ($this->seccion('vacios') as $renglon) {
            if (($renglon['columna'] ?? null) !== $columna) {
                continue;
            }

            return ($renglon['decision'] ?? null) === self::VACIO_CONSERVAR
                ? self::VACIO_CONSERVAR
                : self::VACIO_DEFECTO;
        }

        return self::VACIO_DEFECTO;
    }

    /** Las columnas cuya celda vacía hay que conservar. @return list<string> */
    public function columnasAConservar(): array
    {
        $columnas = [];

        foreach ($this->seccion('vacios') as $renglon) {
            if (($renglon['decision'] ?? null) === self::VACIO_CONSERVAR
                && is_string($renglon['columna'] ?? null)) {
                $columnas[] = $renglon['columna'];
            }
        }

        return array_values(array_unique($columnas));
    }

    /**
     * Qué se hace con un alumno cuyo documento ya existe en la base.
     *
     * Por defecto se actualiza, que es lo que hace la idempotencia desde el
     * 20 ago 2026 y lo que evita crear duplicados. `omitir` es para el caso
     * contrario: una hoja de alumnos NUEVOS en la que un documento repetido es
     * un error de quien la llenó, y machacar la ficha buena sería el daño.
     */
    public function queHacerConRepetido(string $documento): string
    {
        foreach ($this->seccion('repetidos') as $renglon) {
            // **La respuesta espeja la pregunta, y la pregunta emite
            // `documento_en_la_hoja`** (`EnsayoDeLaImportacion::posiblesRepetidos`).
            // Se acepta también `documento` porque es el nombre que sale solo al
            // escribirlo a mano, y rechazar por el nombre de una llave sería
            // perder una decisión que la persona sí tomó.
            $suyo = (string) ($renglon['documento_en_la_hoja'] ?? $renglon['documento'] ?? '');

            if ($suyo !== $documento) {
                continue;
            }

            return ($renglon['decision'] ?? null) === self::REPETIDO_OMITIR
                ? self::REPETIDO_OMITIR
                : self::REPETIDO_ACTUALIZAR;
        }

        return self::REPETIDO_ACTUALIZAR;
    }

    /**
     * Cuál gana cuando el mismo documento sale dos veces DENTRO del fichero.
     *
     * Hoy gana la última porque el importador procesa en orden y la segunda
     * pasada pisa a la primera — no porque nadie lo eligiera. Que se pueda decir
     * `primera` es lo que convierte ese accidente en una decisión.
     */
    public function cualGanaEnDuplicado(string $documento): string
    {
        foreach ($this->seccion('duplicados') as $renglon) {
            if ((string) ($renglon['documento'] ?? '') !== $documento) {
                continue;
            }

            return ($renglon['decision'] ?? null) === self::DUPLICADO_PRIMERA
                ? self::DUPLICADO_PRIMERA
                : self::DUPLICADO_ULTIMA;
        }

        return self::DUPLICADO_ULTIMA;
    }

    /**
     * Qué se hace con una hoja que no casa con ningún grupo del año.
     *
     * Por defecto **para la importación entera**, que es lo que pasa hoy: el
     * importador resuelve la pestaña contra `grupos` antes de mirar si trae
     * filas, así que una hoja mal nombrada revienta con 500 y no entra nadie.
     *
     * `omitir` es la salida para el caso corriente que eso castiga: la hoja de
     * notas, la de instrucciones o la del año pasado que alguien dejó dentro del
     * libro. **Y omitir se declara**, no se calla: la respuesta dice cuántas
     * hojas se saltaron y cuáles.
     */
    public function queHacerConHoja(string $hoja): string
    {
        foreach ($this->seccion('hojas') as $renglon) {
            // El ensayo emite las hojas con la llave `nombre`, así que es la que
            // manda; `hoja` se acepta por lo mismo que arriba.
            $suya = (string) ($renglon['nombre'] ?? $renglon['hoja'] ?? '');

            if ($suya !== $hoja) {
                continue;
            }

            return ($renglon['decision'] ?? null) === self::HOJA_OMITIR
                ? self::HOJA_OMITIR
                : self::HOJA_PARAR;
        }

        return self::HOJA_PARAR;
    }

    /**
     * Qué llegó, qué se aplicó y qué no — con el motivo al lado.
     *
     * `usadas` no se cuenta aquí: lo cuenta el traductor mientras traduce, que
     * es el único que sabe si una equivalencia llegó a casar con alguna fila.
     * Una respuesta **aceptada** y una respuesta **usada** son cosas distintas:
     * aprobar que «CARNÉ DIPLOMÁTICO es Cédula» y que luego esa celda no esté en
     * el fichero no es un fallo, pero tampoco es haberla aplicado.
     *
     * @param  array<string, int>  $usadas
     * @return array<string, mixed>
     */
    public function resumen(array $usadas): array
    {
        $decididas = 0;
        $aRevisar = 0;

        foreach ($this->seccion('vocabularios') as $renglon) {
            ($renglon['decision'] ?? null) === 'a_revisar' ? $aRevisar++ : $decididas++;
        }

        $noAplicadas = [];

        foreach (self::noAplicadas() as $seccion => $motivo) {
            $cuantas = count($this->seccion($seccion));

            if ($cuantas > 0) {
                $noAplicadas[] = ['seccion' => $seccion, 'cuantas' => $cuantas, 'motivo' => $motivo];
            }
        }

        return [
            'recibidas' => $this->cuantasEnTotal(),
            'vocabularios_decididos' => $decididas,
            'vocabularios_a_revisar' => $aRevisar,
            'veces_que_se_usaron' => array_sum($usadas),
            'por_campo' => $usadas,
            'no_aplicadas' => $noAplicadas,

            // Qué secciones de las que llegaron SÍ cambian lo que hace el
            // importador. Es el reverso de `no_aplicadas` y hace falta desde que
            // esa lista puede venir vacía: sin él, «vacía» se lee igual que «no
            // se calculó».
            'aplicadas' => array_values(array_filter(
                self::APLICADAS,
                fn (string $seccion) => count($this->seccion($seccion)) > 0
            )),
        ];
    }

    public function hayAlguna(): bool
    {
        return $this->cuantasEnTotal() > 0;
    }

    private function cuantasEnTotal(): int
    {
        $total = 0;

        foreach (array_merge(self::APLICADAS, array_keys(self::NO_APLICADAS)) as $seccion) {
            $total += count($this->seccion($seccion));
        }

        return $total;
    }

    /** @return array<int, array<string, mixed>> */
    private function seccion(string $nombre): array
    {
        $valor = $this->crudas[$nombre] ?? [];

        return is_array($valor) ? array_values(array_filter($valor, 'is_array')) : [];
    }
}
