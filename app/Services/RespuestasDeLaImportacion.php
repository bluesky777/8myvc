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
    /** Las secciones que hoy cambian lo que hace el importador. */
    private const APLICADAS = ['vocabularios'];

    /**
     * Las que se aceptan, se guardan y **todavía no se interpretan**, con el
     * motivo que la respuesta enseña. No es una lista de pendientes: es lo que
     * impide que la pantalla prometa algo que no ocurre.
     */
    private const NO_APLICADAS = [
        'vacios' => 'Todavía no se interpretan: una celda vacía sigue cayendo en el valor por defecto.',
        'repetidos' => 'Todavía no se interpretan: el importador sigue decidiendo por documento.',
        'duplicados' => 'Todavía no se interpretan: dentro del fichero sigue ganando la última fila.',
        'hojas' => 'Todavía no se interpretan: una hoja que no casa con un grupo sigue parando la importación.',
    ];

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

        foreach (self::NO_APLICADAS as $seccion => $motivo) {
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
