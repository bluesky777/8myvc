<?php

namespace App\Http\Controllers\Alumnos;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Alumno;
use App\Models\Debugging;
use App\Models\Matricula;
use App\Models\Role;
use App\Services\PuntoDeControlDeImportacion;
use App\Support\SafeUpload;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\BeforeSheet;
use Maatwebsite\Excel\Facades\Excel;

class AlumnoSheetImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        return $rows;
    }

    public function headingRow(): int
    {
        return 2;
    }
}
class AlumnosImport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new AlumnoSheetImport,
        ];
    }
}
class ExcelUtils implements ToArray, WithEvents, WithHeadingRow
{
    public $sheetNames;

    public $sheetData;

    public $year;

    public $fixer;

    /**
     * El punto de control por el que se sabe qué filas están ya aplicadas.
     *
     * Lo abre el controlador, no esta clase: la huella con la que se reconoce
     * «el mismo archivo» se saca del fichero subido, y aquí solo llegan las
     * hojas ya leídas.
     */
    public $punto;

    /**
     * Qué hizo de verdad, contado mientras lo hace.
     *
     * Lo pide la pantalla del ensayo: el informe final **compara lo prometido
     * con lo hecho**, y para eso los dos tienen que hablar de lo mismo. Hasta
     * hoy la respuesta era la cadena `'Importados.'` y estos números no los
     * sabía nadie — ni siquiera cuántas filas eran, que `importaciones` sí
     * guarda pero sin repartirlas entre crear y actualizar.
     *
     * `reencontrados` va aparte de `actualizados` a propósito: son las filas
     * que venían **sin `id`** y aun así no se crearon, porque su documento ya
     * estaba. Es la idempotencia trabajando, y es el número que dice cuántos
     * alumnos duplicados se habrían creado antes del 20 ago 2026.
     *
     * @var array<string, int>
     */
    public $hechos = [
        'filas' => 0,
        'creados' => 0,
        'actualizados' => 0,
        'reencontrados' => 0,
        'usuarios_creados' => 0,
        'matriculas_creadas' => 0,
        'saltadas' => 0,
    ];

    public function __construct($year, $fixer, PuntoDeControlDeImportacion $punto)
    {
        $this->sheetNames = [];
        $this->sheetData = [];
        $this->year = $year;
        $this->fixer = $fixer;
        $this->punto = $punto;
    }

    /**
     * Una hoja del archivo, que es un grupo.
     *
     * maatwebsite llama a esto una vez por pestaña y le pasa las filas ya
     * leídas; el nombre de la pestaña llega por el evento BeforeSheet, no por
     * aquí, y de ahí el `sheetNames` de la primera línea.
     */
    public function array(array $array)
    {
        $sheetName = $this->sheetNames[count($this->sheetNames) - 1];
        $this->sheetData[$sheetName] = $array;
        $now = Carbon::now('America/Bogota');
        $abrev = $sheetName;

        // Una hoja que quedó entera detrás del punto de control se salta sin
        // mirar siquiera qué grupo era. Reanudar un archivo de dieciséis
        // pestañas por la última no puede costar dieciséis consultas de grupo.
        if (count($array) > 0 && $this->punto->yaProcesada($abrev, count($array) - 1)) {
            $this->hechos['saltadas'] += count($array);

            return;
        }

        $consulta = 'SELECT g.id, g.abrev, g.year_id FROM grupos g inner join years y on y.id=g.year_id WHERE g.abrev=? and g.deleted_at is null and y.deleted_at is null and y.year=?;';
        $grupos = DB::select($consulta, [$abrev, $this->year]);

        // Esto era `DB::select(...)[0]`, y una pestaña cuyo nombre no fuera el
        // de ningún grupo del año daba «Undefined array key 0» — que no dice
        // cuál era la pestaña, y es el fallo corriente cuando alguien sube la
        // hoja del año pasado. Sigue siendo un 500, porque cambiarlo es tocar
        // el contrato de la pantalla; lo que cambia es que ahora se puede leer
        // en `importaciones.error` con el nombre dentro.
        if (count($grupos) === 0) {
            throw new \RuntimeException("La hoja '".$abrev."' no corresponde a ningún grupo del año ".$this->year.'.');
        }

        $grupo = $grupos[0];
        $results = $array;

        for ($f = 0; $f < count($results); $f++) {

            if ($this->punto->yaProcesada($abrev, $f)) {
                $this->hechos['saltadas']++;

                continue;
            }

            $this->hechos['filas']++;

            // La fila entera y su marca de avance, en la MISMA transacción.
            //
            // Ahí está toda la garantía: una fila de alumno son ocho escrituras
            // —alumno, usuario, rol, matrícula y los dos acudientes con sus
            // parentescos— y sin transacción el proceso puede morir con tres
            // hechas. Antes eso dejaba medio alumno en la base y nadie sabía
            // cuál; ahora la fila entra entera o no entra, y el punto de control
            // no puede mentir porque se guarda con ella.
            DB::transaction(function () use ($results, $f, $grupo, $abrev, $now) {
                $this->procesarFila($results[$f], $grupo, $abrev, $now);
                $this->punto->anotar($abrev, $f);
            });
        }
    }

    /**
     * Lo que hace falta hacer con una fila de la hoja: crear o actualizar al
     * alumno, su usuario, su matrícula y sus dos acudientes.
     *
     * Era el cuerpo del `for` de arriba; sale a su propio método para poder
     * envolverlo en una transacción.
     */
    private function procesarFila($alumno, $grupo, $abrev, $now)
    {

        $res = $this->fixer->verificar($alumno, $this->year);
        $alumno['ciudad_docu_acud1'] = $res['ciudad_id_A1'];
        $alumno['ciudad_docu_acud2'] = $res['ciudad_id_A2'];

        // Idempotencia por la clave natural, que es la otra mitad de poder
        // reanudar: saber por dónde ibas no sirve si volver a pasar por una
        // fila crea un alumno repetido.
        //
        // La hoja trae el `id` de los alumnos que ya estaban y lo trae vacío
        // para los nuevos, así que hasta hoy «vacío» significaba «créalo»,
        // sin mirar si ese documento ya estaba en la base. Eso duplicaba —
        // alumno, usuario y matrícula— en dos casos reales: la importación
        // que se cortó y se volvió a subir, y el alumno que cambia de grupo y
        // alguien escribe a mano en la hoja del grupo nuevo.
        //
        // El documento es la clave natural, y el importador ya lo usaba como
        // tal en el de cartera (`UPDATE alumnos ... WHERE documento=?`).
        $reencontrado = false;

        if (! $alumno['id']) {
            $id = $this->idPorDocumento($alumno['nro_de_documento']);

            if ($id !== null) {
                $alumno['id'] = $id;
                $reencontrado = true;
            }
        }

        // Los nombres se recomponen de dos columnas, y se recortan porque la
        // segunda casi siempre viene vacía: un alumno de un solo nombre de
        // pila quedaba guardado como 'Irene ', con el espacio dentro. Lo
        // destapó el test de ida y vuelta —exportar e importar lo exportado—,
        // que hasta este arreglo cambiaba a 68 de los 68 alumnos del seed.

        if ($alumno['id']) {
            $this->hechos['actualizados']++;

            if ($reencontrado) {
                $this->hechos['reencontrados']++;
            }

            $consulta = 'UPDATE alumnos SET no_matricula=?, nombres=?, apellidos=?, sexo=?, fecha_nac=?,
					tipo_doc=?, documento=?, no_matricula=?, direccion=?, barrio=?, telefono=?, celular=?, estrato=?, 
					tipo_sangre=?, eps=?, religion=?, nro_sisben=?, updated_at=?'.$res['consulta'].' WHERE id=?';

            // Los valores del fragmento van en medio: entra detras de `updated_at=?`
            // y delante del `WHERE id=?`, asi que el orden del array tiene que ser
            // el mismo. Ver 05 §60.
            DB::update($consulta, array_merge([$alumno['no_matricula'], trim($alumno['primer_nombre'].' '.$alumno['segundo_nombre']), trim($alumno['primer_apellido'].' '.$alumno['segundo_apellido']), $alumno['sexo'], $alumno['fecha_de_nacim'],
                $alumno['tipo_doc'], $alumno['nro_de_documento'], $alumno['numero_matricula'], $alumno['direccion_residencia'], $alumno['barrio'], $alumno['telefono'], $alumno['celular'], $alumno['estrato'],
                $alumno['rh'], $alumno['eps'], $alumno['religion'], $alumno['sisben'], $now], $res['valores'], [$alumno['id']]));

            // `matriculas.estado` es varchar(4), y lo que llega aqui es texto de
            // una hoja de calculo. Un «Activo» se guardaba como «Acti», que NO
            // esta entre los codigos que usa el colegio —hoy en esta base son
            // MATR, RETI, PREM, FORM, ASIS, PREA y DESE—, asi que el alumno
            // desaparecia de la consulta que alimenta el export, el SIMAT y
            // todas las listas: existe, tiene matricula, y no sale en ninguna
            // parte. En el docker pasa callado (`sql_mode` vacio) y en la
            // MariaDB estricta de produccion aborta a media hoja. Las dos son
            // malas y son distintas.
            //
            // No se traduce —«Activo» podria ser MATR o ASIS y eso lo decide el
            // colegio, no este fichero—: lo que NO cabe simplemente no se
            // escribe, y la matricula conserva el estado que ya tenia. No
            // escribir es peor que escribir bien y mucho mejor que escribir
            // mal, porque un estado corrupto no se nota hasta que alguien echa
            // en falta a un alumno.
            $estadoCabe = $alumno['estado_matricula'] === null
                || mb_strlen(trim((string) $alumno['estado_matricula'])) <= 4;

            if ($estadoCabe) {
                DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.estado=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno['es_nuevo'], $alumno['estado_matricula'], $now, $alumno['id']]);
            } else {
                // `nuevo` si se escribe: no tiene nada que ver con el estado y
                // descartarlo tambien seria castigar dos columnas por una.
                DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno['es_nuevo'], $now, $alumno['id']]);

                $this->fixer->avisos[] = [
                    'fila' => $alumno['numero_matricula'] ?? null,
                    'campo' => 'estado_matricula',
                    'valor' => (string) $alumno['estado_matricula'],
                    'motivo' => 'No cabe en matriculas.estado (4 caracteres) y se habria '
                              .'guardado cortado, dejando al alumno fuera de las listas. '
                              .'La matricula conserva el estado que ya tenia.',
                ];
            }

            // El «no eliminar» de esta línea era literal, y decía la verdad:
            // esto ERA el punto de control. Dejaba en `debugging` una fila por
            // alumno para poder mirar a mano por dónde iba la importación
            // cuando el servidor la cortaba.
            //
            // Ya no hace ese trabajo —lo hace `importaciones`, que el código
            // sabe leer y esto no— y se queda porque es el único rastro de las
            // importaciones anteriores a hoy en las dieciséis bases. Borrarla
            // es una limpieza aparte, con su decisión: `debugging` crece una
            // fila por alumno importado y no se limpia nunca.
            Debugging::pin('Alum_id: '.$alumno['id'], 'Grupo: '.$abrev, 'Grupo_id: '.$grupo->id);

            // Acudiente 1
            $this->modificar_acudiente1($alumno, $now, $res['consultaA1']);

            // Acudiente 2
            $this->modificar_acudiente2($alumno, $now, $res['consultaA2']);

            // Solo cuando se llegó aquí por el documento. Una fila que TRAÍA su
            // id sigue comportándose exactamente igual que antes: la matrícula
            // se actualiza, no se crea. Lo que se cubre es el alumno que la
            // hoja daba por nuevo y resultó existir — sin esto se quedaría
            // actualizado pero fuera del grupo de la pestaña.
            if ($reencontrado) {
                $this->asegurarMatricula($alumno['id'], $grupo, $now);
            }

        } else {

            $alumno_row = $alumno;
            if ($alumno_row['primer_nombre']) {
                $alumno = new Alumno;
                $alumno->nombres = trim($alumno_row['primer_nombre'].' '.$alumno_row['segundo_nombre']);
                $alumno->apellidos = trim($alumno_row['primer_apellido'].' '.$alumno_row['segundo_apellido']);
                $alumno->sexo = $alumno_row['sexo'] ? $alumno_row['sexo'] : 'M';
                $alumno->tipo_doc = $alumno_row['tipo_doc'];
                $alumno->documento = $alumno_row['nro_de_documento'];
                $alumno->no_matricula = $alumno_row['numero_matricula'];
                $alumno->direccion = $alumno_row['direccion_residencia'];
                $alumno->barrio = $alumno_row['barrio'];
                $alumno->fecha_nac = $alumno_row['fecha_de_nacim'];
                $alumno->telefono = $alumno_row['telefono'];
                $alumno->celular = $alumno_row['celular'];
                $alumno->estrato = $alumno_row['estrato'];
                $alumno->eps = $alumno_row['eps'];
                $alumno->tipo_sangre = $alumno_row['rh'];
                $alumno->religion = $alumno_row['religion'];
                $alumno->nro_sisben = $alumno_row['sisben'];
                $alumno->save();

                $this->hechos['creados']++;

                $alumno_row['id'] = $alumno->id;

                $opera = new OperacionesAlumnos;

                $usuario = new User;
                $usuario->username = $opera->username_no_repetido($alumno->nombres, 'alumno'.$alumno->id);
                $usuario->password = Hash::make('123456');
                $usuario->sexo = $alumno_row['sexo'] ? $alumno_row['sexo'] : 'M';
                $usuario->is_superuser = 0;
                $usuario->periodo_id = 1; // Verificar que haya un periodo cod 1
                $usuario->is_active = 1;
                $usuario->tipo = 'Alumno';
                $usuario->save();

                $this->hechos['usuarios_creados']++;

                $role = Role::where('name', 'Alumno')->get();
                // $usuario->attachRole($role[0]);
                $usuario->roles()->attach($role[0]['id']);

                $alumno->user_id = $usuario->id;
                $alumno->save();

                $matricula = new Matricula;
                $matricula->alumno_id = $alumno->id;
                $matricula->grupo_id = $grupo->id;
                $matricula->estado = 'MATR';
                $matricula->fecha_matricula = $now;
                $matricula->save();

                $this->hechos['matriculas_creadas']++;

                // Acudiente 1
                $this->modificar_acudiente1($alumno_row, $now, $res['consultaA1']);

                // Acudiente 2
                $this->modificar_acudiente2($alumno_row, $now, $res['consultaA2']);

            }

        }

    }

    /**
     * El id del alumno que ya tiene ese documento, si lo hay.
     *
     * `alumnos.documento` no es UNIQUE y no puede serlo: hay filas históricas
     * con el documento vacío o repetido, y un índice único ahí haría fallar el
     * despliegue en los colegios que las tengan. Se comprueba leyendo, y con
     * `ORDER BY id` para que dos filas repetidas den siempre la misma — una
     * importación que eligiera una u otra según el humor de MySQL sería peor
     * que la que duplicaba.
     *
     * ## Por qué el documento NO se convierte a cadena antes de atarlo
     *
     * Parece que habría que hacerlo y hay un `EXPLAIN` que lo pide, así que
     * aquí queda por qué no, medido el 19 sep 2026 en `simonbolivar`:
     *
     * | parámetro | type | key |
     * |---|---|---|
     * | `'1035123456'` | `ref` | `alumnos_documento_index` |
     * | `1035123456` | `index` | `PRIMARY` |
     *
     * O sea: **el índice que se creó el 20 ago se esquiva** cuando la celda del
     * Excel es numérica. Y aun así no se toca, por dos medidas:
     *
     * 1. **No cuesta.** 200 búsquedas: 36 ms como texto, **26 ms como número**.
     *    La tabla son 1.284 filas y recorrer el índice entero es más barato que
     *    el `ORDER BY id` que la otra forma tiene que resolver aparte. La
     *    hipótesis de «800 barridos contra los 300 s de cPanel» se midió y es
     *    falsa a esta escala. Vuélvase a medir en una base grande antes de
     *    darla por cierta allí.
     * 2. **Y convertir ROMPERÍA gente.** `'01035123456' = 1035123456` es cierto
     *    y `'01035123456' = '1035123456'` es falso. Hay **7 alumnos vivos con
     *    cero a la izquierda**: la comparación numérica de hoy los encuentra y
     *    la de cadena no. Pasarlo a texto crearía un duplicado de cada uno —
     *    exactamente el fallo que la §1 de `09-pendientes.md` vino a cerrar.
     *
     * Lo que sí hacía falta era **descartar el 0**, que es el único valor con
     * el que esa conversión implícita hace daño; está justo debajo.
     *
     * *El síntoma era real y la consecuencia que se le suponía, falsa. Antes de
     * actuar sobre un `EXPLAIN`, medir el tiempo y mirar a quién deja fuera.*
     */
    private function idPorDocumento($documento)
    {
        $documento = is_string($documento) ? trim($documento) : $documento;

        if ($documento === null || $documento === '') {
            return null;
        }

        // Un documento que vale CERO no busca: descarta.
        //
        // Cuando la celda del Excel es numérica, `$documento` llega como int y PDO
        // lo ata como número, así que MySQL convierte la COLUMNA para comparar. Con
        // un valor de verdad eso es inofensivo e incluso conveniente —ver el
        // comentario del método—, pero con un 0 deja de serlo: cualquier documento
        // que no sea un número se convierte también a 0, así que `documento = 0`
        // casaría con TODOS ellos y devolvería el primero por `ORDER BY id`. El
        // alumno de la fila se fundiría con un desconocido, en silencio y en 200.
        //
        // Medido el 19 sep 2026 en `simonbolivar`: `'AB1234' = 0` da 1, y dos
        // documentos no numéricos cualesquiera colisionan entre sí en 0. En esta
        // base hay 0 filas así, o sea que hoy no muerde AQUÍ — los otros quince
        // colegios no están medidos, y es una columna que rellena una secretaría.
        //
        // El `''` de arriba no cubre este caso: un 0 entero no es cadena vacía.
        if (is_int($documento) && $documento === 0) {
            return null;
        }

        $fila = DB::selectOne('SELECT id FROM alumnos WHERE documento = ? and deleted_at is null ORDER BY id LIMIT 1', [$documento]);

        return $fila === null ? null : (int) $fila->id;
    }

    /**
     * Matricula al alumno en el grupo de la pestaña si no lo estaba ya en ese
     * año.
     *
     * La comprobación es por AÑO y no por grupo, igual que el UPDATE de
     * matrículas de unas líneas más arriba: un alumno tiene una matrícula por
     * año, y crearle otra por haber aparecido en la pestaña de al lado lo
     * dejaría en dos grupos a la vez.
     */
    private function asegurarMatricula($alumno_id, $grupo, $now)
    {
        $ya = DB::selectOne('SELECT m.id FROM matriculas m INNER JOIN grupos g ON g.id=m.grupo_id
			WHERE m.alumno_id=? and g.year_id=? and m.deleted_at is null and g.deleted_at is null LIMIT 1',
            [$alumno_id, $grupo->year_id]);

        if ($ya !== null) {
            return;
        }

        $matricula = new Matricula;
        $matricula->alumno_id = $alumno_id;
        $matricula->grupo_id = $grupo->id;
        $matricula->estado = 'MATR';
        $matricula->fecha_matricula = $now;
        $matricula->save();
    }

    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->sheetNames[] = $event->getSheet()->getDelegate()->getTitle();
            },
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getSheetNames()
    {
        return $this->sheetNames;
    }

    public function headingRow(): int
    {
        return 2;
    }

    private function modificar_acudiente1(&$alumno, $now, $consulta)
    {

        $alumno['sexo_acud1'] = ((is_null($alumno['sexo_acud1']) || $alumno['sexo_acud1'] == '') ? 'M' : $alumno['sexo_acud1']);

        if ($alumno['id_acud1'] > 0 && (! (is_null($alumno['nombres_acud1']) || $alumno['nombres_acud1'] == ''))) {

            // Si tiene código y tiene nombre escrito, sólo quiere modificarlo
            DB::update('UPDATE acudientes SET nombres=?, apellidos=?, sexo=?, tipo_doc=?, documento=?, is_acudiente=?, telefono=?, celular=?, ocupacion=?, direccion=?, email=?, updated_at=?'.$consulta.' WHERE id=?',
                [$alumno['nombres_acud1'], $alumno['apellidos_acud1'], $alumno['sexo_acud1'], $alumno['tipo_docu_acud1'], $alumno['documento_acud1'], ($alumno['is_acudiente1'] ? $alumno['is_acudiente1'] : 1),
                    $alumno['telefono_acud1'], $alumno['celular_acud1'], $alumno['ocupacion_acud1'], $alumno['direccion_acud1'], $alumno['email_acud1'], $now, $alumno['id_acud1']]);

            DB::update('UPDATE parentescos p INNER JOIN acudientes a ON a.id=p.acudiente_id and p.alumno_id=? and p.acudiente_id=? and p.deleted_at is null and a.deleted_at is null 
				SET p.parentesco=?, p.observaciones=?, p.updated_at=?', [$alumno['id'], $alumno['id_acud1'], $alumno['parentesco_acud1'], $alumno['observaciones_acud1'], $now]);

        } elseif ($alumno['id_acud1'] > 0 && (is_null($alumno['nombres_acud1']) || $alumno['nombres_acud1'] == '')) {

            // Si tiene código y NO tiene nombre escrito, quiere añadirlo como nuevo acudiente de este alumno, NO modificarlo
            DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$alumno['id_acud1'], $alumno['id'], $alumno['parentesco_acud1'], $alumno['observaciones_acud1'], $now, $now]);

        } else {
            if (! (is_null($alumno['nombres_acud1']) || $alumno['nombres_acud1'] == '')) {
                DB::insert('INSERT INTO acudientes(nombres, apellidos, sexo, tipo_doc, documento, is_acudiente, telefono, celular, ocupacion, direccion, email, created_at, updated_at, ciudad_doc) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$alumno['nombres_acud1'], $alumno['apellidos_acud1'], $alumno['sexo_acud1'], $alumno['tipo_docu_acud1'], $alumno['documento_acud1'], ($alumno['is_acudiente1'] ? $alumno['is_acudiente1'] : 1),
                        $alumno['telefono_acud1'], $alumno['celular_acud1'], $alumno['ocupacion_acud1'], $alumno['direccion_acud1'], $alumno['email_acud1'], $now, $now, $alumno['ciudad_docu_acud1']]);

                $last_id = DB::getPdo()->lastInsertId();
                DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$last_id, $alumno['id'], $alumno['parentesco_acud1'], $alumno['observaciones_acud1'], $now, $now]);
            }
        }
    }

    private function modificar_acudiente2(&$alumno, $now, $consulta)
    {

        $alumno['sexo_acud2'] = ((is_null($alumno['sexo_acud2']) || $alumno['sexo_acud2'] == '') ? 'M' : $alumno['sexo_acud2']);

        if ($alumno['id_acud2'] > 0 && (! (is_null($alumno['nombres_acud2']) || $alumno['nombres_acud2'] == ''))) {

            // Si tiene código y tiene nombre escrito, sólo quiere modificarlo
            DB::update('UPDATE acudientes SET nombres=?, apellidos=?, sexo=?, tipo_doc=?, documento=?, is_acudiente=?, telefono=?, celular=?, ocupacion=?, direccion=?, email=?, updated_at=?'.$consulta.' WHERE id=?',
                [$alumno['nombres_acud2'], $alumno['apellidos_acud2'], $alumno['sexo_acud2'], $alumno['tipo_docu_acud2'], $alumno['documento_acud2'], ($alumno['is_acudiente2'] ? $alumno['is_acudiente2'] : 1),
                    $alumno['telefono_acud2'], $alumno['celular_acud2'], $alumno['ocupacion_acud2'], $alumno['direccion_acud2'], $alumno['email_acud2'], $now, $alumno['id_acud2']]);

            DB::update('UPDATE parentescos p INNER JOIN acudientes a ON a.id=p.acudiente_id and p.alumno_id=? and p.acudiente_id=? and p.deleted_at is null and a.deleted_at is null 
				SET p.parentesco=?, p.observaciones=?, p.updated_at=?', [$alumno['id'], $alumno['id_acud2'], $alumno['parentesco_acud2'], $alumno['observaciones_acud2'], $now]);

        } elseif ($alumno['id_acud2'] > 0 && (is_null($alumno['nombres_acud2']) || $alumno['nombres_acud2'] == '')) {

            // Si tiene código y NO tiene nombre escrito, quiere añadirlo como nuevo acudiente de este alumno, NO modificarlo
            DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$alumno['id_acud2'], $alumno['id'], $alumno['parentesco_acud2'], $alumno['observaciones_acud2'], $now, $now]);

        } else {
            if (! (is_null($alumno['nombres_acud2']) || $alumno['nombres_acud2'] == '')) {
                DB::insert('INSERT INTO acudientes(nombres, apellidos, sexo, tipo_doc, documento, is_acudiente, telefono, celular, ocupacion, direccion, email, created_at, updated_at, ciudad_doc) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$alumno['nombres_acud2'], $alumno['apellidos_acud2'], $alumno['sexo_acud2'], $alumno['tipo_docu_acud2'], $alumno['documento_acud2'], ($alumno['is_acudiente2'] ? $alumno['is_acudiente2'] : 1),
                        $alumno['telefono_acud2'], $alumno['celular_acud2'], $alumno['ocupacion_acud2'], $alumno['direccion_acud2'], $alumno['email_acud2'], $now, $now, $alumno['ciudad_docu_acud2']]);

                $last_id = DB::getPdo()->lastInsertId();
                DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$last_id, $alumno['id'], $alumno['parentesco_acud2'], $alumno['observaciones_acud2'], $now, $now]);
            }
        }
    }
}

class ImportarController extends Controller
{
    use ResuelveElUsuario;

    /**
     * La importación de alumnos: una pestaña por grupo, una fila por alumno.
     *
     * **Es reanudable desde el 20 ago 2026** (§1 de docs/migracion/09-pendientes.md).
     * Si el servidor la corta —`max_execution_time` son 300 s en cPanel, y está
     * en 300 por esto— volver a subir el MISMO archivo continúa por donde iba en
     * vez de empezar de cero. Quien lo hace es
     * App\Services\PuntoDeControlDeImportacion; el porqué de cada decisión está
     * ahí y no aquí.
     *
     * **La respuesta no cambia.** Sigue siendo la cadena pelada 'Importados.',
     * que es lo que leen hoy los cuatro clientes —uno de ellos la app de Flutter,
     * que es UNA para los dieciséis colegios y por tanto no se puede escalonar—.
     * Ese es justo el motivo de que se haya hecho reanudable y no encolado: la
     * cola devuelve un identificador y obliga a preguntar después, y eso sí es
     * cambiar el contrato (§3 del mismo documento).
     */
    public function postAlgo($year)
    {
        if (Request::hasFile('file')) {
            $archivo = request()->file('file');

            // La huella es del CONTENIDO del archivo, no de su nombre: la
            // secretaría sube tres veces `alumnos.xlsx` y son tres archivos
            // distintos. Es lo que hace que «volver a subir el mismo» tenga un
            // significado que el código pueda comprobar.
            $punto = PuntoDeControlDeImportacion::abrir(
                'alumnos',
                hash_file('sha256', $archivo->getRealPath()),
                (int) $year,
                SafeUpload::nombreParaGuardar($archivo),
                $this->user->user_id
            );

            $fixer = new ImporterFixer;
            $Import = new ExcelUtils($year, $fixer, $punto);

            // El error se guarda y se vuelve a lanzar: el 500 que ve el cliente
            // es el mismo de siempre —cambiarlo es tocar el contrato— pero deja
            // de ser la única señal de que algo pasó. Y la fila queda en
            // 'fallida', que se reanuda igual que 'en_proceso'.
            try {
                Excel::import($Import, $archivo);
            } catch (\Throwable $e) {
                $punto->fallar($e);
                throw $e;
            }

            $punto->completar();

            // Aquí había un bucle que llenaba un `$data` que no devolvía nadie,
            // instanciando `AlumnosImport` una vez por hoja. Se va con el
            // cambio de respuesta: era lo único que instanciaba esa clase.
            //
            // LO QUE SE DEVOLVÍA HASTA HOY ERA LA CADENA `'Importados.'`, y eso
            // no era inocuo. Medido el 20 sep 2026 contra el docker:
            //
            //     status 200 · Content-Type text/html · cuerpo 'Importados.'
            //
            // `app2` sube por `comunes/subida/subida.ts`, que llama a
            // `http.post` **sin `responseType`** —o sea `'json'`—, y Angular
            // convierte un 2xx cuyo cuerpo no parsea en un ERROR. Así que las
            // dos pantallas nuevas enseñaban «no se pudieron importar» después
            // de una importación que había funcionado. Lo dedujo la sesión del
            // front leyendo su propio código y lo confirmó esta medición.
            //
            // Devolver JSON arregla ese camino además de abrir el contrato.
            // **Los errores siguen siendo texto**, decidido por Joseth el 20
            // sep: la pantalla vieja pinta `status + ': ' + data` y con un JSON
            // ahí saldría `500: [object Object]`.
            return response()->json([
                'ok' => true,
                'importacion_id' => $punto->id(),
                'reanudada' => $punto->reanudada(),
                'filas_del_archivo' => $punto->filas(),
                'hechos' => $Import->hechos,
                'avisos' => $fixer->avisos,
            ]);
        }

        // Se queda en texto a propósito, por lo mismo que los errores: es la
        // rama de «no me mandaste fichero», y los cuatro llamadores mandan uno
        // siempre.
        return 'No se encontró archivo.';
    }

    /**
     * La importación de cartera, que **está rota y lleva años estándolo**.
     *
     * `Excel::import($ruta, $closure)` es la firma de maatwebsite/excel 2.x. En la
     * 3.x el primer argumento es el objeto de importación y el segundo la ruta,
     * así que el closure llega donde se espera una ruta y `pathinfo()` revienta:
     * 500 en cada llamada, desde antes de esta migración. Es el mismo error exacto
     * que `getIndex()` unas líneas más abajo.
     *
     * No salió en el muestreo de rutas del 20 ago 2026 porque aquello golpeaba
     * lecturas sin parámetro, y esta es un POST con un archivo dentro. Lo destapó
     * el trabajo de la importación reanudable, yendo a mirar los dos importadores
     * que se daban por vivos.
     *
     * **Se deja rota a propósito**, con la regla del proyecto: con ruta y rota se
     * documenta, porque borrarla convierte un 500 en un 404 sin decirle a nadie
     * qué pretendía hacer esa pantalla. Qué debe hacer —y si la operación debe
     * existir— es una decisión del colegio.
     * Ver docs/migracion/05-codigo-muerto-y-roto.md §8.4; el error queda fijado
     * por tests/Contrato/ExcelTest.php.
     */
    public function postCartera()
    {
        if (Request::hasFile('file')) {
            $path = Request::file('file')->getRealPath();

            $rr = Excel::import($path, function ($reader) {

                $now = Carbon::now('America/Bogota');
                $results = $reader->all();

                for ($i = 0; $i < count($results); $i++) {
                    $alumno = $results[$i];

                    if (strtolower($results[$i]->pazysalvo) == 'si' || strtolower($results[$i]->paz_y_salvo) == 'si') {
                        $pazysalvo = 1;
                    } else {
                        $pazysalvo = 0;
                    }

                    $fecha_pension = null;

                    if ($results[$i]->fecha_pension) {
                        $fecha_pension = Carbon::parse($results[$i]->fecha_pension);
                    }

                    if ($results[$i]->fecha) {
                        $fecha_pension = Carbon::parse($results[$i]->fecha);
                    }

                    if ($results[$i]->documento) {
                        $consulta = 'UPDATE alumnos SET deuda=?, pazysalvo=? WHERE documento=?;';
                        $actua = DB::update($consulta, [$results[$i]->deuda, $pazysalvo, $results[$i]->documento]);

                        DB::update('UPDATE matriculas m INNER JOIN alumnos a ON a.id=m.alumno_id and m.deleted_at is null 
							SET m.fecha_pension=? WHERE a.documento=? and a.deleted_at is null',
                            [$fecha_pension, $alumno->documento]);

                        // No eliminar para continuar si se cae el servidor!!
                        Debugging::pin('Alum_documento: '.$alumno->documento);

                    }

                }

            });
        }

        return (array) $rr;
    }

    public function getIndex()
    {

        $rr = Excel::import('app/Http/Controllers/Alumnos/archivos/alumnos.xls', function ($reader) {

            $results = $reader->all();
            $now = Carbon::parse(Request::input('fecha_matricula'));

            for ($i = 0; $i < count($results); $i++) {

                $abrev = $results[$i]->getTitle();
                $consulta = 'SELECT * FROM grupos WHERE abrev=?';
                $grupo = DB::select($consulta, [$abrev])[0];

                for ($f = 0; $f < count($results[$i]); $f++) {

                    $alumno_row = $results[$i][$f];

                    $alumno = new Alumno;
                    $alumno->nombres = $alumno_row->nombres;
                    $alumno->apellidos = $alumno_row->apellidos;
                    $alumno->sexo = $alumno_row->sexo ? $alumno_row->sexo : 'M';
                    $alumno->save();

                    $opera = new OperacionesAlumnos;

                    $usuario = new User;
                    $usuario->username = $opera->username_no_repetido($alumno->nombres, 'alumno'.$alumno->id);
                    $usuario->password = Hash::make('123456');
                    $usuario->sexo = $alumno_row->sexo ? $alumno_row->sexo : 'M';
                    $usuario->is_superuser = 0;
                    $usuario->periodo_id = 1; // Verificar que haya un periodo cod 1
                    $usuario->is_active = 1;
                    $usuario->tipo = 'Alumno';
                    $usuario->save();

                    $role = Role::where('name', 'Alumno')->get();
                    // Entrust no está instalado; es la misma migración que ya tenía hecha
                    // AlumnosController y que aquí quedó sin hacer.
                    $usuario->roles()->attach($role[0]['id']);

                    $alumno->user_id = $usuario->id;
                    $alumno->save();

                    $matricula = new Matricula;
                    $matricula->alumno_id = $alumno->id;
                    $matricula->grupo_id = $grupo->id;
                    $matricula->estado = 'MATR';
                    $matricula->fecha_matricula = $now;
                    $matricula->save();

                }
            }
        });

        return (array) $rr;
    }

    public function getModificar($year)
    {
        $host = apache_request_headers()['Host'];
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        } else {
            $extension = 'xlsx';
        }

        $rr = Excel::import('app/Http/Controllers/Alumnos/archivos/alumnos-modificar-'.$year.'.'.$extension, function ($reader) use ($year) {

            $now = Carbon::now('America/Bogota');
            $results = $reader->all();
            $fixer = new ImporterFixer;

            for ($i = 0; $i < count($results); $i++) {

                $abrev = $results[$i]->getTitle();
                $consulta = 'SELECT g.id, g.abrev, g.year_id FROM grupos g inner join years y on y.id=g.year_id WHERE g.abrev=? and g.deleted_At is null and y.deleted_at is null and y.year=?;';
                $grupo = DB::select($consulta, [$abrev, $year])[0];

                for ($f = 0; $f < count($results[$i]); $f++) {

                    $alumno = $results[$i][$f];
                    $res = $fixer->verificar($alumno, $year);
                    $alumno->ciudad_docu_acud1 = $res['ciudad_id_A1'];
                    $alumno->ciudad_docu_acud2 = $res['ciudad_id_A2'];

                    if ($alumno->id) {
                        $consulta = 'UPDATE alumnos SET no_matricula=?, nombres=?, apellidos=?, sexo=?, fecha_nac=?, 
							tipo_doc=?, documento=?, no_matricula=?, direccion=?, barrio=?, telefono=?, celular=?, estrato=?, 
							tipo_sangre=?, eps=?, religion=?, updated_at=?'.$res['consulta'].' WHERE id=?';

                        // Aqui la consulta NO liga `nro_sisben`, al reves que la de procesarFila:
                        // el fragmento es el unico que la escribe por esta ruta. Ver 05 §60.
                        DB::update($consulta, array_merge([$alumno->no_matricula, $alumno->primer_nombre.' '.$alumno->segundo_nombre, $alumno->primer_apellido.' '.$alumno->segundo_apellido, $alumno->sexo, $alumno->fecha_de_nacim,
                            $alumno->tipo_doc, $alumno->nro_de_documento, $alumno->numero_matricula, $alumno->direccion_residencia, $alumno->barrio, $alumno->telefono, $alumno->celular, $alumno->estrato,
                            $alumno->rh, $alumno->eps, $alumno->religion, $now], $res['valores'], [$alumno->id]));

                        DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.estado=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno->es_nuevo, $alumno->estado_matricula, $now, $alumno->id]);

                        // No eliminar!!
                        Debugging::pin('Alum_id: '.$alumno->id, 'Grupo: '.$abrev, 'Grupo_id: '.$grupo->id);

                        // Acudiente 1
                        // $this->modificar_acudiente1($alumno, $now, $res['consultaA1']);

                        // Acudiente 2
                        // $this->modificar_acudiente2($alumno, $now, $res['consultaA2']);

                    }

                }

            }

        });

        return (array) $rr;
    }
}
