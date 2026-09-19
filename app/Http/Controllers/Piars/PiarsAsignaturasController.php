<?php

namespace App\Http\Controllers\Piars;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Piars\Utils\PiarsAsignaturasUtils;
use App\Models\Profesor;
use App\Support\HtmlDelEditor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class PiarsAsignaturasController extends Controller
{
    use ResuelveElUsuario;

    public function getAsignaturas($grupo_id, $alumno_id)
    {
        if ($this->user->tipo === 'Profesor') {

            $asignaturas = Profesor::asignaturas($this->user->year_id, $this->user->persona_id);

        } elseif (in_array($this->user->tipo, ['Usuario'])) {

            // **Gemelo de `Profesor::asignaturas` copiado a mano**, con otro `WHERE`:
            // aquél pregunta por el docente y éste por el grupo, así que no se pueden
            // fundir. Lo que se añada a aquel SELECT se añade a éste **en el mismo
            // commit**: si no, esta ruta contesta dos formas según quién pregunte —y
            // no lo caza nada, porque `muestreo-piars-asignaturas` es la instantánea
            // de ESTA rama y la del `Profesor` no tiene ninguna—. `materia_id` y
            // `grado_id` entraron así. La diferencia que queda —`caritas`, que aquí
            // no está— es anterior y no se toca aquí.

            $consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
					a.materia_id, g.grado_id,
					m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.titular_id,
					gr.nivel_educativo_id
				FROM asignaturas a
				inner join materias m on m.id=a.materia_id and m.deleted_at is null
				inner join grupos g on g.id=a.grupo_id and g.deleted_at is null
				inner join grados gr on gr.id=g.grado_id and gr.deleted_at is null 
				where g.id=:grupo_id and a.deleted_at is null
				order by g.orden, a.orden, m.materia, m.alias, a.id';

            $asignaturas = DB::select($consulta, [
                ':grupo_id' => $grupo_id,
            ]);

        }

        $piarsAsignaturasUtils = new PiarsAsignaturasUtils;

        for ($i = 0; $i < count($asignaturas); $i++) {
            $asignaturas[$i]->piar_asignatura = $piarsAsignaturasUtils->getCreatePiarAsignatura($asignaturas[$i]->asignatura_id, $alumno_id);
        }

        return $asignaturas;
    }

    public function putField()
    {
        $now = Carbon::now('America/Bogota');

        $id = Request::input('id');
        $field = Request::input('field');
        $text = Request::input('text');
        $updated_at = $now;
        $updated_by = $this->user->user_id;

        // campos seguros para evitar ataques sql injection
        $validFields = ['apoyo_razonable', 'seguimientos'];
        if (! in_array($field, $validFields)) {
            return response()->json(['error' => 'Invalid'], 400);
        }

        // El texto es HTML del editor y el cliente lo pinta como HTML: lo que no
        // pase por aquí se ejecuta en la sesión de quien abra el PIAR.
        $text = HtmlDelEditor::limpiar($text);

        $consulta = "UPDATE piars_asignaturas
			SET $field=?, updated_at=?, updated_by=?
			WHERE id=?";
        $piars = DB::update($consulta, [
            $text, $updated_at, $updated_by, $id,
        ]);

        return ['piars' => $piars];
    }
}
