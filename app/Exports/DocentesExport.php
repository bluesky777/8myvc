<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * El listado de docentes de un año, en una hoja.
 *
 * Sustituye al `Excel::create(...)->sheet(...)` de
 * `ExcelListadoDocentesController::getDocentes`, que era la API de
 * `maatwebsite/excel` **2.x** sobre una instalación **3.1.70**: `Excel::create()`
 * no existe ahí y no hay `__call` que la rescate, así que la ruta contestaba
 * `BadMethodCallException` —un 500— desde el salto de versión mayor.
 *
 * **Una hoja y no varias**, a diferencia de `AlumnosExport`: el original tenía un
 * solo `->sheet('Docentes', ...)`. Por eso implementa `FromView` directamente en
 * vez de `WithMultipleSheets` con un `Sheet` aparte — la pareja Export+Sheet de
 * sus vecinos existe porque aquéllos hacen una hoja por grupo.
 *
 * **La consulta y la vista se copian sin tocar una coma.** Es un arreglo de la
 * llamada, no de lo que sale: si el fichero cambiara de contenido, nadie podría
 * decir si es el arreglo o un cambio colado dentro.
 *
 * Lo que **sí se pierde** y hay que saberlo: el `setBorder`, el `setWidth` y el
 * `setHeight` del original. En 3.x eso se hace con `WithStyles`/`ShouldAutoSize`
 * y **no se añade aquí a propósito** — el listado lleva años sin salir, así que
 * nadie tiene un fichero con esos bordes al que comparar, y el estilo es una
 * decisión de quien lo use, no del arreglo. Sus dos vecinos que funcionan
 * (`AlumnosSheet`, `AcudientesSheet`) tampoco lo llevan.
 */
class DocentesExport implements FromView, WithTitle
{
    public function __construct(private int $yearId) {}

    public function view(): View
    {
        $consulta = 'SELECT p.*, c.id as contrato_id, ci.ciudad as ciudad_nac_nombre, ci.departamento as depart_nac_nombre, 
                ci2.ciudad as ciudad_doc_nombre, ci2.departamento as depart_doc_nombre, t.tipo as tipo_doc_nombre, t.abrev, u.username 
            FROM profesores p 
            INNER JOIN contratos c ON c.profesor_id=p.id and c.deleted_at is null 
            LEFT JOIN ciudades ci ON ci.id=p.ciudad_nac and ci.deleted_at is null 
            LEFT JOIN ciudades ci2 ON ci2.id=p.ciudad_doc and ci2.deleted_at is null 
            LEFT JOIN tipos_documentos t ON t.id=p.tipo_doc and t.deleted_at is null 
            LEFT JOIN users u ON u.id=p.user_id and u.deleted_at is null 
            WHERE p.deleted_at is null and c.year_id=?';

        $profesores = DB::select($consulta, [$this->yearId]);

        foreach ($profesores as $profesor) {
            $grupos = DB::select(
                'SELECT g.abrev, g.id, g.orden FROM grupos g
                  WHERE g.deleted_at is null and g.titular_id=? and year_id=?',
                [$profesor->id, $this->yearId]
            );

            // `implode` en vez del bucle con el `if ($j < $cant_g-1)` de dentro:
            // mismo resultado, y el original tenía la coma final como caso
            // especial escrito a mano.
            $profesor->grupos = implode(',', array_column($grupos, 'abrev'));
            $profesor->orden_grupo = $grupos[0]->orden ?? null;
        }

        return view('listado-docentes', compact('profesores'));
    }

    public function title(): string
    {
        return 'Docentes';
    }
}
