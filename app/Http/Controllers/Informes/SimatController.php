<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use App\Exports\AlumnosExport;
use Maatwebsite\Excel\Facades\Excel;

use App\User;
use App\Models\Year;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;


class SimatController extends Controller {

	public function getIndex()
	{
        return 'Holaa';


    }


	public function getAlumnos()
	{
        // **El segundo 500 vivo de la API 2.x, y el que el barrido del front no
        // vio.** Su aviso señalaba la llamada de `getAlumnosExportar` como código
        // muerto —cierto: está detrás del `return` de abajo— pero en este fichero
        // hay DOS `Excel::create`, y ésta no tiene nada delante. Está enrutada en
        // `informes.php:99` y la llama `myvc_front` desde `InformesCtrl.ts:700`.
        //
        // Es la diferencia entre barrer la pantalla y barrer el patrón: la
        // población real era **3 llamadas en 2 ficheros**, de las cuales **2
        // vivas y rotas**, no una.
        //
        // Y la reutiliza tal cual: `AlumnosExport` monta exactamente lo que este
        // método montaba —los grupos del año, sus alumnos con acudientes, una
        // hoja por grupo titulada con su `abrev` y la vista `simat`—, así que
        // escribir un export nuevo habría sido una segunda copia de la misma
        // consulta. Lo único que cambia es el nombre del fichero, que es lo que
        // distinguía a las dos rutas.
        //
        // Se pierde el `setBorder`/`setWidth`/`setHeight` del original, igual que
        // se perdió el día que se migró `getAlumnosExportar`. Nadie tiene un
        // fichero reciente con esos bordes: esto no salía.
        $user = User::fromToken();

        return Excel::download(new AlumnosExport,
            'Alumnos con acudientes '.$user->year.'.xlsx');
    }


	public function getAlumnosExportar()
	{
        // **Detrás de este `return` había 80 líneas muertas**, y lo estaban desde
        // que alguien puso el `Excel::download` delante sin borrar lo de abajo.
        // Se van hoy porque eran la tercera y última llamada a la API 2.x de
        // `maatwebsite/excel` que quedaba en `app/`, y dejarlas obliga al
        // centinela de `ExportacionesExcelTest` a llevar una excepción — un
        // centinela con excepciones no es un centinela.
        //
        // Es código inalcanzable, no una ruta rota: la regla del repo —«sin ruta
        // y roto se borra; con ruta y roto se documenta»— protege el endpoint, y
        // el endpoint sigue aquí y funcionando. Lo que se borra es lo que no
        // puede ejecutarse nunca.
        //
        // Lo que se llevan, por si alguien lo echa de menos: el `setBorder`, el
        // `setWidth`, el `setHeight` y el `Comentarios()` que pintaba la fila de
        // ayudas. Nada de eso sale hoy en el fichero —lo que sale es
        // `AlumnosExport`, sin estilos— así que no se pierde nada que un usuario
        // esté viendo. Está en git si hace falta: `git show 0dc21d7 -- ` este
        // fichero.
        return Excel::download(new AlumnosExport, 'alumnos.xlsx');
    }    /**
     * **NO SE BORRA, aunque no la llame nadie.** Y estuvo borrada media hora.
     *
     * Al quitar el bloque muerto de `getAlumnosExportar` este método se quedó sin
     * llamantes, así que se fue con él — privado, cero usos, borrado limpio. Mal:
     * `phpstan.neon` llevaba desde el 19 ago 2026 una anotación explicando
     * exactamente por qué seguía aquí, y no la leí antes de borrar.
     *
     * Lo que hay dentro **es la especificación de la plantilla del SIMAT**: qué
     * espera cada columna de la hoja que la secretaría rellena y devuelve
     * —«Coloque: MATR, ASIS, RETI, DESE», «¿Es urbano? SI o NO», «Coloque "No
     * aplica" si no tiene SISBEN»—. Eso es lo que `ImporterFixer`, que **sí está
     * vivo**, lee de vuelta. El export 3.x (`AlumnosSheet`) no escribe esas
     * ayudas, así que la plantilla sale hoy sin instrucciones y **este método es
     * el único sitio del repositorio donde están**.
     *
     * O sea que borrarlo no habría roto ningún test —no lo llama nadie— y habría
     * dejado al importador vivo sin su especificación escrita. Es la forma de
     * fallo de la casa otra vez, en el sentido contrario: **el detector tenía
     * razón (código muerto) y aun así la acción era la equivocada**, porque la
     * razón para conservarlo no estaba en el código sino anotada al lado.
     *
     * Lo que hay que hacer con esto algún día, y no es este lote: llevar estas
     * ayudas al `AlumnosSheet` con `WithEvents`/`AfterSheet`, para que la
     * plantilla vuelva a salir con ellas y la especificación viva donde se usa.
     * Mientras tanto se queda aquí, sin llamantes y a propósito.
     * Ver 05-codigo-muerto-y-roto.md §12.2 y noche-2026-08-24/exp-1.md.
     */
    private function Comentarios(&$sheet, $numero=1){
        
        $sheet->getComment('A'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('B'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('C'.$numero)->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('E'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('E'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('K'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('L'.$numero)->getText()->createTextRun('Coloque: MATR, ASIS, RETI, DESE');
        $sheet->getComment('P'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('Q'.$numero)->getText()->createTextRun('¿Es urbano? SI o NO');
        $sheet->getComment('U'.$numero)->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el antiguo SISBEN.');
        $sheet->getComment('V'.$numero)->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el nuevo SISBEN tipo 3.');
        $sheet->getComment('X'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('Y'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('Z'.$numero)->getText()->createTextRun('M o F');
        $sheet->getComment('AA'.$numero)->getText()->createTextRun('Si el año pasado NO finalizó en la institución, coloque SI, de lo contrario, especifique que NO es nuevo.');
        
        
        $sheet->getComment('AE'.$numero)->getText()->createTextRun('Si sabe el ID del acudiente, coloquelo aquí e ignore las demás columnas para asignar el acudiente con ese ID a este alumno. Si es un acudiente nuevo, no debe poner ID, ignore esta columna');
        $sheet->getComment('AH'.$numero)->getText()->createTextRun('M o F');
        $sheet->getComment('AI'.$numero)->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('AJ'.$numero)->getText()->createTextRun('SI o NO');
        $sheet->getComment('AK'.$numero)->getText()->createTextRun('Padre, Madre, Hermano, Hermana, Abuelo, Abuela, Tío, Tía, Primo(a), Otro');
        $sheet->getComment('AM'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('AN'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('AS'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('AU'.$numero)->getText()->createTextRun('Comentarios sobre este acudiente del alumno');
        
        $sheet->getComment('AV'.$numero)->getText()->createTextRun('Si sabe el ID del acudiente, coloquelo aquí e ignore las demás columnas para asignar el acudiente con ese ID a este alumno. Si es un acudiente nuevo, no debe poner ID, ignore esta columna');
        $sheet->getComment('AY'.$numero)->getText()->createTextRun('M o F');
        $sheet->getComment('AZ'.$numero)->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('BA'.$numero)->getText()->createTextRun('SI o NO');
        $sheet->getComment('BB'.$numero)->getText()->createTextRun('Padre, Madre, Hermano, Hermana, Abuelo, Abuela, Tío, Tía, Primo(a), Otro');
        $sheet->getComment('BD'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('BE'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('BJ'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('BL'.$numero)->getText()->createTextRun('Comentarios sobre este acudiente del alumno');
        
    }
}
