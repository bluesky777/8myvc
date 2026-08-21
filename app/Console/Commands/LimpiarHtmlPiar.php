<?php

namespace App\Console\Commands;

use App\Support\HtmlDelEditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pasa por el saneador el HTML del PIAR que ya está guardado.
 *
 * **Por qué hace falta un comando y no basta con sanear al escribir.** Desde el
 * 20 ago 2026 los `putField` limpian lo que entra, pero eso no toca lo que lleva
 * años en la base. Y el cliente pintaba esos campos con el sanitizador de
 * Angular desactivado, así que cualquier cosa que se colara entonces sigue ahí y
 * se sigue ejecutando al abrir el PIAR.
 *
 * **Se ejecuta una vez por colegio**, como el resto: dieciséis bases distintas,
 * cada una con su copia (ver docs/DESPLIEGUE.md). No es periódico — con el
 * saneado al escribir puesto, la segunda pasada no encuentra nada.
 *
 * **Por qué reescribe solo lo que cambia.** Comparar antes de escribir deja el
 * `updated_at` de las filas limpias como estaba, que es lo que mira el colegio
 * para saber quién tocó un PIAR por última vez. Una migración que le ponga la
 * fecha de hoy a los seis mil registros borra esa información.
 *
 * **Qué cambia de verdad, medido sobre la base de desarrollo (20 ago 2026).**
 * 186 filas, y ninguna cambia de aspecto. Se auditó primero qué hay guardado:
 * las 17 etiquetas y las 3 propiedades CSS que aparecen —`color`,
 * `background-color` y `text-align`— están todas en la lista blanca, así que no
 * se pierde ni una etiqueta. Lo que cambia es:
 *
 * - Normalización sin efecto: `<br>` → `<br />`, `rgb(255, 255, 255)` →
 *   `rgb(255,255,255)`. Es la mayoría.
 * - `color: var(--tw-prose-bold)` y compañía, 288 declaraciones que se caen.
 *   Son variables CSS que llegaron pegando texto de fuera (Tailwind) o de la
 *   propia tabla de Angular Material. Ninguna de esas variables existe donde se
 *   pinta el PIAR, así que el navegador ya las descartaba: se ve igual.
 * - `text-align: start`, 37 declaraciones que HTMLPurifier no admite —solo
 *   `left|right|center|justify`—. En un idioma que se lee de izquierda a
 *   derecha `start` ES `left`, que además es el valor por defecto. Las 335 de
 *   `justify`, que sí se ven, se conservan.
 *
 * Conviene repetir la medición en cada colegio antes de escribir: `--dry-run`
 * cuenta, y `-v` dice qué fila y qué columna.
 */
class LimpiarHtmlPiar extends Command
{
    protected $signature = 'piar:limpiar-html
                            {--dry-run : Enseña qué cambiaría y no escribe nada}';

    protected $description = 'Sanea el HTML ya guardado en los campos de texto del PIAR';

    /** Tabla => columnas de texto enriquecido que escribe el editor. */
    private const CAMPOS = [
        'piars_alumnos' => ['valoracion_pedagogica', 'ajustes_generales', 'reporte'],
        'piars_asignaturas' => ['apoyo_razonable', 'seguimientos'],
        'piars_grupos' => ['caracterizacion_grupo'],
        'piars_config' => ['reporte_default'],
    ];

    public function handle(): int
    {
        $simulacro = (bool) $this->option('dry-run');
        $totalTocadas = 0;

        foreach (self::CAMPOS as $tabla => $columnas) {
            $tocadas = $this->limpiarTabla($tabla, $columnas, $simulacro);
            $totalTocadas += $tocadas;

            $this->line(sprintf('  %-20s %d fila(s) con HTML que cambia', $tabla, $tocadas));
        }

        if ($simulacro) {
            $this->warn("Simulacro: no se ha escrito nada. {$totalTocadas} fila(s) cambiarían.");

            return 0;
        }

        $this->info("Listo. {$totalTocadas} fila(s) reescritas.");

        return 0;
    }

    /**
     * @param  string[]  $columnas
     */
    private function limpiarTabla(string $tabla, array $columnas, bool $simulacro): int
    {
        $lista = implode(', ', $columnas);

        // Sin `chunk` de Eloquent a propósito: estas tablas no tienen modelo y
        // el resto del proyecto habla SQL crudo. La mayor, `piars_asignaturas`,
        // ronda las pocas miles de filas — cabe de una vez.
        $filas = DB::select("SELECT id, {$lista} FROM {$tabla}");

        $tocadas = 0;

        foreach ($filas as $fila) {
            $cambios = [];

            foreach ($columnas as $columna) {
                $antes = $fila->{$columna};
                $despues = HtmlDelEditor::limpiar($antes);

                if ($despues !== $antes) {
                    $cambios[$columna] = $despues;
                }
            }

            if ($cambios === []) {
                continue;
            }

            $tocadas++;

            if ($this->output->isVerbose()) {
                $this->line("    {$tabla}#{$fila->id}: ".implode(', ', array_keys($cambios)));
            }

            if ($simulacro) {
                continue;
            }

            // `updated_at` no entra en el SET: esto es una limpieza técnica, no
            // una edición de nadie, y la columna dice quién tocó el PIAR.
            $asignaciones = implode(', ', array_map(
                fn ($columna) => "{$columna}=?",
                array_keys($cambios)
            ));

            DB::update(
                "UPDATE {$tabla} SET {$asignaciones} WHERE id=?",
                [...array_values($cambios), $fila->id]
            );
        }

        return $tocadas;
    }
}
