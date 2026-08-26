<?php namespace App\Http\Controllers\Alumnos;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;
use \Log;

use App\User;
use App\Models\Matricula;
use App\Models\Year;
use App\Models\Alumno;

use App\Http\Controllers\Controller;


class FoliosController extends Controller {

	/**
	 * **Fabricaba folios en masa, y ya no fabrica ninguno: contesta 409.**
	 *
	 * Llenaba de golpe todos los `nro_folio` vacios del anio actual con
	 * `CONCAT(y.year,'-',m.alumno_id)`. **Esta es la maquina que produjo los 1.612
	 * folios inventados** que se midieron en la copia local
	 * (docs/migracion/21-certificados-y-folios.md §2.2), y no fabricaba folios: fabricaba
	 * ids de alumno con el anio delante. **Un folio es la hoja del libro de matriculas**, y
	 * lo que se imprime en la constancia esta para que quien la lea vaya a comprobarla al
	 * archivo; `2025-1234` no lleva a ninguna parte.
	 *
	 * ## Por que 409 y no borrar la ruta
	 *
	 * La regla de la casa: **sin ruta y roto se borra; con ruta se documenta**. Borrarla
	 * convertiria un 200 en un 404 sin decirle a nadie que pretendia hacer. Y **409 y no
	 * 403**: no es que quien llama no pueda, es que **la operacion ya no existe** -- es un
	 * conflicto con el estado del sistema, no un problema de permisos.
	 *
	 * **Poblacion revisada antes de cortarla:** los siete arboles de cliente de
	 * `~/DESARROLLOS` --`myvc_front`, `myvc_front_2`, `myvc_flutter`, `myvc_dist`,
	 * `tardanzasMyvc-old`, `arc` y `landingLAL`--. **Cero ficheros la nombran.** O sea que
	 * esto no le quita un boton a nadie; lo que quita es una puerta por la que el sistema
	 * se rellenaba solo de numeros que no significan nada.
	 *
	 * Decision de Joseth del 26 ago 2026: los colegios que quieran folio lo llevan a mano
	 * (opcion A del 21); los que no, apagan el interruptor y la casilla no se imprime.
	 */
	public function getIniciar()
	{
		abort(409, 'El folio ya no se genera automaticamente: es la hoja del libro de matriculas y se escribe a mano.');
	}
	
	
}

