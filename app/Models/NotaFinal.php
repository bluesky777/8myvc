<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\DefinitivasDeAsignatura;


use Carbon\Carbon;
use App\Models\Grupo;
use App\User;
use App\Models\Periodo;
use App\Models\Debugging;
use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `notas_finales`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property ?int $alumno_id
 * @property ?int $asignatura_id
 * @property ?int $periodo_id
 * @property ?int $periodo
 * @property int $nota
 * @property ?int $recuperada
 * @property ?int $manual
 * @property ?int $updated_by
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * > **`$nota` ya NO es un `int`, y el bloque de arriba no puede saberlo.**
 * > `2026_08_30_200000_notas_finales_en_decimal` la pasó a `DECIMAL(7,4)`, y PDO
 * > devuelve un `DECIMAL` como **cadena** (`"43.7500"`). El bloque se genera desde
 * > `database/schema/mysql-schema.sql`, que es el volcado **congelado de
 * > producción** y por diseño no lleva lo que añaden las migraciones — así que
 * > regenerarlo con `tools/columnas-en-los-modelos.php` vuelve a escribir `int` y
 * > **no es un error del script**: es que producción todavía va por detrás.
 * >
 * > Lo que importa para quien escriba código aquí: **no castees a `(int)`**, que
 * > trunca hacia abajo (`(int)"43.7500"` es 43, no 44). Si el valor va al JSON,
 * > sale de la consulta con `CAST(... AS DOUBLE)` para que siga siendo un número;
 * > si llega crudo, `(float)`.
 */



class NotaFinal extends Model {

	/**
	 * La tabla es `notas_finales`, y hay que decirlo.
	 *
	 * Sin esto Eloquent deduce `nota_finals`, que no existe. Hoy no se nota
	 * porque todo lo que hay en esta clase son métodos estáticos con SQL
	 * escrito a mano, y ninguno pasa por la tabla del modelo; el primer
	 * `NotaFinal::where(...)` que alguien escriba se lleva un
	 * «Table 'nota_finals' doesn't exist». Salió al anotar las columnas de los
	 * modelos desde el esquema real (tools/columnas-en-los-modelos.php): fue el
	 * único modelo de los 45 cuya tabla no se pudo resolver.
	 */
	protected $table = 'notas_finales';

	protected $fillable = [];



	
	public static $consulta_alumnos_grupo_nota_final = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, m.grupo_id, m.estado, 
							CAST(nf1.nota AS DOUBLE) as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1, nf1.updated_by as updated_by_1, nf1.created_at as created_at_1, nf1.updated_at as updated_at_1,
							CAST(nf2.nota AS DOUBLE) as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2, nf2.updated_by as updated_by_2, nf2.created_at as created_at_2, nf2.updated_at as updated_at_2,
							CAST(nf3.nota AS DOUBLE) as nota_final_per3, nf3.id as nf_id_3, nf3.recuperada as recuperada_3, nf3.manual as manual_3, nf3.updated_by as updated_by_3, nf3.created_at as created_at_3, nf3.updated_at as updated_at_3,
							CAST(nf4.nota AS DOUBLE) as nota_final_per4, nf4.id as nf_id_4, nf4.recuperada as recuperada_4, nf4.manual as manual_4, nf4.updated_by as updated_by_4, nf4.created_at as created_at_4, nf4.updated_at as updated_at_4,
                            rf.id as recu_id, rf.year as recu_year, rf.nota as recu_nota, rf.updated_at as recu_updated_at, rf.updated_by as recu_updated_by,
                            
                            cast(r1.DefMateria as decimal(7,4)) as def_materia_auto_1, r1.updated_at as updated_at_def_1, IF(nf1.updated_at > r1.updated_at, FALSE, TRUE) AS nfinal1_desactualizada, r1.periodo_id as periodo_id1, 
                            cast(r2.DefMateria as decimal(7,4)) as def_materia_auto_2, r2.updated_at as updated_at_def_2, IF(nf2.updated_at > r2.updated_at, FALSE, TRUE) AS nfinal2_desactualizada, r2.periodo_id as periodo_id2, 
                            cast(r3.DefMateria as decimal(7,4)) as def_materia_auto_3, r3.updated_at as updated_at_def_3, IF(nf3.updated_at > r3.updated_at, FALSE, TRUE) AS nfinal3_desactualizada, r3.periodo_id as periodo_id3, 
                            cast(r4.DefMateria as decimal(7,4)) as def_materia_auto_4, r4.updated_at as updated_at_def_4, IF(nf4.updated_at > r4.updated_at, FALSE, TRUE) AS nfinal4_desactualizada, r4.periodo_id as periodo_id4, 
                            
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="MATR" or m.estado="ASIS") and m.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asign_id1 and nf1.periodo=1
						left join notas_finales nf2 on nf2.alumno_id=a.id and nf2.asignatura_id=:asign_id2 and nf2.periodo=2
						left join notas_finales nf3 on nf3.alumno_id=a.id and nf3.asignatura_id=:asign_id3 and nf3.periodo=3
						left join notas_finales nf4 on nf4.alumno_id=a.id and nf4.asignatura_id=:asign_id4 and nf4.periodo=4
                        
                        left join (
							SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                            FROM(
                                SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
                                    sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                                FROM asignaturas asi 
                                inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                                inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                                inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                                inner join periodos p1 on p1.numero=1 and p1.id=u.periodo_id and p1.deleted_at is null
                                where asi.deleted_at is null and asi.id=:asign_id5
                                group by n.alumno_id, s.unidad_id, s.id
                            )df1
                            group by df1.alumno_id, df1.periodo_id
						)r1 ON r1.alumno_id=a.id
                        
                        left join (
							SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                            FROM(
                                SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
                                    sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                                FROM asignaturas asi 
                                inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                                inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                                inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                                inner join periodos p1 on p1.numero=2 and p1.id=u.periodo_id and p1.deleted_at is null
                                where asi.deleted_at is null and asi.id=:asign_id6
                                group by n.alumno_id, s.unidad_id, s.id
                            )df1
                            group by df1.alumno_id, df1.periodo_id
						)r2 ON r2.alumno_id=a.id
                        
                        left join (
							SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                            FROM(
                                SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
                                    sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                                FROM asignaturas asi 
                                inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                                inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                                inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                                inner join periodos p1 on p1.numero=3 and p1.id=u.periodo_id and p1.deleted_at is null
                                where asi.deleted_at is null and asi.id=:asign_id7
                                group by n.alumno_id, s.unidad_id, s.id
                            )df1
                            group by df1.alumno_id, df1.periodo_id
						)r3 ON r3.alumno_id=a.id
                        
                        left join (
							SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                            FROM(
                                SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at,
                                    sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                                FROM asignaturas asi 
                                inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                                inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                                inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                                inner join periodos p1 on p1.numero=4 and p1.id=u.periodo_id and p1.deleted_at is null
                                where asi.deleted_at is null and asi.id=:asign_id8
                                group by n.alumno_id, s.unidad_id, s.id
                            )df1
                            group by df1.alumno_id, df1.periodo_id
						)r4 ON r4.alumno_id=a.id
                        
                        left join recuperacion_final rf ON rf.alumno_id=a.id and rf.asignatura_id=nf1.asignatura_id
                        
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';




    /**
     * **Los cuatro DELETE+INSERT de aquí eran los últimos `INSERT` sin guarda de
     * `notas_finales`** — fase 3 de docs/migracion/10-definitivas.md, 24 ago 2026.
     *
     * Cada uno borraba las automáticas del alumno en su periodo **excluyendo las
     * manuales y las recuperadas** —a propósito, para no pisar lo que puso un
     * profesor— y después reponía la automática **del mismo alumno cuya manual se
     * acababa de conservar**. Eso es el duplicado auto+manual de la §2, y con la
     * clave única de la fase 2 sería un 500 al abrir esta pantalla.
     *
     * Ahora los cuatro llaman a `DefinitivasDeAsignatura`, que decide por
     * existencia y respeta `manual` y `recuperada` **en un único punto** en vez de
     * en cinco. **Las condiciones de arriba se dejan como estaban**: cambiar a la
     * vez quién escribe y cuándo se dispara haría imposible saber cuál de las dos
     * cosas movió un número.
     */
    public static function alumnos_grupo_nota_final($grupo_id, $asignatura_id, $user_id){

        $consulta = self::$consulta_alumnos_grupo_nota_final;

        $alumnos = DB::select($consulta, [':grupo_id'=>$grupo_id, ':asign_id1'=>$asignatura_id, ':asign_id2'=>$asignatura_id, ':asign_id3'=>$asignatura_id, ':asign_id4'=>$asignatura_id, 
                                            ':asign_id5'=>$asignatura_id, ':asign_id6'=>$asignatura_id, ':asign_id7'=>$asignatura_id, ':asign_id8'=>$asignatura_id ]);

        $per_desact = ['per1' => false, 'per2' => false, 'per3' => false, 'per4' => false];
        
        $now 		= Carbon::now('America/Bogota');
        $cant_alum  = count($alumnos);
        
        for ($i=0; $i < $cant_alum; $i++) { 
            
            $alumnos[$i]->promedio_automatico = round(($alumnos[$i]->nota_final_per1 + $alumnos[$i]->nota_final_per2 + $alumnos[$i]->nota_final_per3 + $alumnos[$i]->nota_final_per4) / 4, 0);
            
            if($alumnos[$i]->nfinal1_desactualizada && $alumnos[$i]->updated_at_def_1){
                $per_desact['per1'] = true;
                
                DefinitivasDeAsignatura::recalcular(
                    (int) $asignatura_id,
                    (int) $alumnos[$i]->periodo_id1,
                    $user_id,
                    (int) $alumnos[$i]->alumno_id
                );
                
            }
            if($alumnos[$i]->nfinal2_desactualizada && $alumnos[$i]->updated_at_def_2){
                $per_desact['per2'] = true;
                
                DefinitivasDeAsignatura::recalcular(
                    (int) $asignatura_id,
                    (int) $alumnos[$i]->periodo_id2,
                    $user_id,
                    (int) $alumnos[$i]->alumno_id
                );
                
            }
            if($alumnos[$i]->nfinal3_desactualizada && $alumnos[$i]->updated_at_def_3){
                $per_desact['per3'] = true;
                
                DefinitivasDeAsignatura::recalcular(
                    (int) $asignatura_id,
                    (int) $alumnos[$i]->periodo_id3,
                    $user_id,
                    (int) $alumnos[$i]->alumno_id
                );
                
            }
            if($alumnos[$i]->nfinal4_desactualizada && $alumnos[$i]->updated_at_def_4){
                $per_desact['per4'] = true;
                
                DefinitivasDeAsignatura::recalcular(
                    (int) $asignatura_id,
                    (int) $alumnos[$i]->periodo_id4,
                    $user_id,
                    (int) $alumnos[$i]->alumno_id
                );
                
            }
        }
        
        
        if ($per_desact['per1'] == true || $per_desact['per2'] == true || $per_desact['per3'] == true || $per_desact['per4'] == true) {
            
            $alumnos = DB::select(self::$consulta_alumnos_grupo_nota_final, [':grupo_id'=>$grupo_id, ':asign_id1'=>$asignatura_id, ':asign_id2'=>$asignatura_id, ':asign_id3'=>$asignatura_id, ':asign_id4'=>$asignatura_id, 
                                            ':asign_id5'=>$asignatura_id, ':asign_id6'=>$asignatura_id, ':asign_id7'=>$asignatura_id, ':asign_id8'=>$asignatura_id ]);
        
        }
        return ['alumnos' => $alumnos, 'per_desact' => $per_desact];

    }


    
    

	public static function calcularAsignaturaPeriodo($asignatura_id, $periodo_id, $num_periodo)
	{
		$user 			= User::fromToken();
		$now 			= Carbon::now('America/Bogota');

        /*
		DB::delete('DELETE nf FROM notas_finales nf
					WHERE (nf.manual is null or nf.manual=0) and (nf.recuperada is null or nf.recuperada=0) and nf.asignatura_id=? and nf.periodo_id=?', 
                    [ $asignatura_id, $periodo_id ]);
        */
		DB::delete('DELETE FROM notas_finales
                        WHERE id IN (select * from
                            (SELECT id FROM notas_finales nf WHERE 
                                (nf.manual is null or nf.manual=0) and (nf.recuperada is null or nf.recuperada=0) and nf.asignatura_id=? and nf.periodo_id=?
                                ORDER BY id
                            )  as res
                        )', 
                        [ $asignatura_id, $periodo_id ]);
        

		$consulta = 'SELECT r1.alumno_id,
			    cast(r1.DefMateria as decimal(7,4)) as def_materia_auto, r1.updated_at, r1.periodo_id
			FROM (
				SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
				FROM(
					SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
						sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
					FROM asignaturas asi 
					inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
					inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
					inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
					inner join periodos p1 on p1.numero=:num_periodo and p1.id=u.periodo_id and p1.deleted_at is null
					where asi.deleted_at is null and asi.id=:asignatura_id
					group by n.alumno_id, s.unidad_id, s.id
				)df1
				group by df1.alumno_id, df1.periodo_id
			)r1';
		
		$defi_autos = DB::select($consulta, [ ':num_periodo'=>$num_periodo, ':asignatura_id'=>$asignatura_id ]);
		$cant_def = count($defi_autos);
					
		for ($i=0; $i < $cant_def; $i++) { 
			
			// **Los diez valores van ligados; antes iban concatenados.** Tres de ellos
			// —`$asignatura_id`, `$periodo_id` y `$num_periodo`— son los argumentos
			// del método, y los cuatro llamantes se los pasan **crudos de
			// `Request::input`**: `unidades/update`, `unidades/destroy`,
			// `subunidades/update` y `subunidades/destroy`. O sea que esto era una
			// inyección sobre un INSERT en `notas_finales`, dentro de un bucle, por
			// cuatro rutas de `auth.personal`.
			//
			// Es el gemelo del de `DefinitivasPeriodosController` (c24706e): la misma
			// consulta copiada, con los mismos valores del cuerpo. Se arregló aquel
			// primero porque lo señaló otra sesión; éste salió de mirar los hermanos,
			// que es lo que la asimetría enseña — cuando una consulta concatena, la
			// pregunta no es si está mal sino **dónde más está copiada**.
			//
			// Se liga y nada más: ni una línea de lógica. El método entero está
			// condenado —es uno de los seis escritores que la fase 3 de
			// docs/migracion/10-definitivas.md sustituye por
			// `App\Services\DefinitivasDeAsignatura`— pero sigue desplegado en los
			// dieciséis colegios y la fase 3 no tiene fecha.
			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, created_at, updated_at) 
						SELECT * FROM (SELECT ? as alumno_id, ? as asignatura_id, ? as periodo_id, ? as periodo, ? as nota_asignatura, 0 as recuperada, 0 as manual, ? as crea, ? as fecha) AS tmp
						WHERE NOT EXISTS (
							SELECT id FROM notas_finales WHERE alumno_id=? and asignatura_id=? and periodo_id=?
						) LIMIT 1';

			// **VERBOS-1 no lo tocó, y no es un olvido.** Éste es un `DB::select` que
			// ESCRIBE, uno de los ocho, y se queda con la palabra vieja **porque el método
			// que lo contiene no lo llama nadie**: `calcularAsignaturaPeriodo` no tiene un
			// solo camino en todo `app/` (comprobado en BI-2, y antes en el 05 y en
			// noche-2026-08-24/med-5). Cambiarle la palabra sería mover código muerto, y la
			// regla de la casa para lo que no tiene ruta es otra: la decide Joseth con los
			// otros 34 métodos sin camino.
			//
			// **Lo que sí hay que saber si algún día se resucita:** este INSERT es COPIA
			// PALABRA POR PALABRA del de `DefinitivasPeriodosController:147`, que sí está
			// vivo y **ya se arregló** — allí es `DB::insert`. Si vuelves a poner en pie
			// este método, trae la palabra de allí: si no, resucitas la versión vieja y el
			// censo de «qué escribe» vuelve a tener un agujero justo en `notas_finales`.
			DB::select($consulta, [
				$defi_autos[$i]->alumno_id, $asignatura_id, $periodo_id, $num_periodo,
				$defi_autos[$i]->def_materia_auto, $user->user_id, $now,
				$defi_autos[$i]->alumno_id, $asignatura_id, $periodo_id,
			]);
			
		}
		
		return 'Calculado';
	}



}



