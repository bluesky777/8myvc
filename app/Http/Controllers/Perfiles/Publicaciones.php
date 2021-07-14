<?php namespace App\Http\Controllers\Perfiles;



use DB;
use Carbon\Carbon;

use App\User;



class Publicaciones {


	public static function ultimas_publicaciones($tipo_persona)
	{
		$consulta_comentarios = 'SELECT c.*, 
			Case 
				When c.tipo_persona="Profesor" Then Concat(pr.nombres, " " , pr.apellidos) 
				When c.tipo_persona="Alumno" Then Concat(a.nombres, " " , a.apellidos) 
				When c.tipo_persona="Acudiente" Then Concat(ac.nombres, " " , ac.apellidos) 
				When c.tipo_persona="Usuario" Then u.username 
				End
				AS nombre_autor, 
			Case 
				When c.tipo_persona="Profesor" Then ipr.nombre
				When c.tipo_persona="Alumno" Then ial.nombre
				When c.tipo_persona="Acudiente" Then iac.nombre
				When c.tipo_persona="Usuario" Then ius.nombre 
				End
				AS foto_autor
			FROM comentarios c
			LEFT JOIN profesores pr ON pr.id=c.persona_id and pr.deleted_at is null
			LEFT JOIN images ipr ON ipr.id=pr.foto_id and ipr.deleted_at is null
			
			LEFT JOIN alumnos a ON a.id=c.persona_id and a.deleted_at is null
			LEFT JOIN images ial ON ial.id=a.foto_id and ial.deleted_at is null
			
			LEFT JOIN acudientes ac ON ac.id=c.persona_id and ac.deleted_at is null
			LEFT JOIN images iac ON iac.id=ac.foto_id and iac.deleted_at is null
			
			LEFT JOIN users u ON u.id=c.persona_id and u.deleted_at is null
			LEFT JOIN images ius ON ius.id=u.imagen_id and ius.deleted_at is null
			WHERE c.publicacion_id=? and c.deleted_at is null';
			
			
			
			
		if ($tipo_persona == 'Alumno') {
			
			$consulta = 'SELECT p.*, 
				Case 
					When p.tipo_persona="Profesor" Then Concat(pr.nombres, " " , pr.apellidos) 
					When p.tipo_persona="Usuario" Then u.username 
					End
					AS nombre_autor, 
				Case 
					When p.tipo_persona="Profesor" Then ipr.nombre
					When p.tipo_persona="Usuario" Then ius.nombre 
					End
					AS foto_autor
				FROM publicaciones p
				LEFT JOIN profesores pr ON pr.id=p.persona_id and pr.deleted_at is null
				LEFT JOIN images ipr ON ipr.id=pr.foto_id and ipr.deleted_at is null
				LEFT JOIN users u ON u.id=p.persona_id and u.deleted_at is null
				LEFT JOIN images ius ON ius.id=u.imagen_id and ius.deleted_at is null
				
				WHERE (p.para_todos=1 or p.para_alumnos=1) and p.deleted_at is null order by p.updated_at desc limit 10';
				
			$publicaciones  = DB::select($consulta);
			$cant           = count($publicaciones);
			
			for ($i=0; $i < $cant; $i++) { 
				$publicaciones[$i]->comentarios = DB::select($consulta_comentarios, [$publicaciones[$i]->id]);
			}
			
			return $publicaciones;
		}
		
		
		if ($tipo_persona == 'Acudiente') {
			
			$consulta = 'SELECT p.*, 
				Case 
					When p.tipo_persona="Profesor" Then Concat(pr.nombres, " " , pr.apellidos) 
					When p.tipo_persona="Usuario" Then u.username 
					End
					AS nombre_autor, 
				Case 
					When p.tipo_persona="Profesor" Then ipr.nombre
					When p.tipo_persona="Usuario" Then ius.nombre 
					End
					AS foto_autor
				FROM publicaciones p
				LEFT JOIN profesores pr ON pr.id=p.persona_id and pr.deleted_at is null
				LEFT JOIN images ipr ON ipr.id=pr.foto_id and ipr.deleted_at is null
				LEFT JOIN users u ON u.id=p.persona_id and u.deleted_at is null
				LEFT JOIN images ius ON ius.id=u.imagen_id and ius.deleted_at is null
				
				WHERE (p.para_todos=1 or p.para_acudientes=1) and p.deleted_at is null order by p.updated_at desc limit 10';
				
			$publicaciones  = DB::select($consulta);
			$cant           = count($publicaciones);
			
			for ($i=0; $i < $cant; $i++) { 
				$publicaciones[$i]->comentarios = DB::select($consulta_comentarios, [$publicaciones[$i]->id]);
			}
			
			return $publicaciones;
		}
		
		
		
		if ($tipo_persona == 'Usuario') {
			
			$consulta = 'SELECT p.*, 
				Case 
					When p.tipo_persona="Profesor" Then Concat(pr.nombres, " " , pr.apellidos) 
					When p.tipo_persona="Usuario" Then u.username 
					End
					AS nombre_autor, 
				Case 
					When p.tipo_persona="Profesor" Then ipr.nombre
					When p.tipo_persona="Usuario" Then ius.nombre 
					End
					AS foto_autor
				FROM publicaciones p
				LEFT JOIN profesores pr ON pr.id=p.persona_id and pr.deleted_at is null
				LEFT JOIN images ipr ON ipr.id=pr.foto_id and ipr.deleted_at is null
				LEFT JOIN users u ON u.id=p.persona_id and u.deleted_at is null
				LEFT JOIN images ius ON ius.id=u.imagen_id and ius.deleted_at is null
				
				WHERE p.deleted_at is null order by p.updated_at desc limit 10';
				
			$publicaciones  = DB::select($consulta);
			$cant           = count($publicaciones);
			
			for ($i=0; $i < $cant; $i++) { 
				$publicaciones[$i]->comentarios = DB::select($consulta_comentarios, [$publicaciones[$i]->id]);
			}
			
			return $publicaciones;
		}
		
		
		if ($tipo_persona == 'Profesor') {
			
			$consulta = 'SELECT p.*, 
				Case 
					When p.tipo_persona="Profesor" Then Concat(pr.nombres, " " , pr.apellidos) 
					When p.tipo_persona="Usuario" Then u.username 
					End
					AS nombre_autor, 
				Case 
					When p.tipo_persona="Profesor" Then ipr.nombre
					When p.tipo_persona="Usuario" Then ius.nombre 
					End
					AS foto_autor
				FROM publicaciones p
				LEFT JOIN profesores pr ON pr.id=p.persona_id and pr.deleted_at is null
				LEFT JOIN images ipr ON ipr.id=pr.foto_id and ipr.deleted_at is null
				LEFT JOIN users u ON u.id=p.persona_id and u.deleted_at is null
				LEFT JOIN images ius ON ius.id=u.imagen_id and ius.deleted_at is null
				
				WHERE (p.para_todos=1 or p.para_profes=1) and p.deleted_at is null order by p.updated_at desc limit 10';
				
			$publicaciones  = DB::select($consulta);
			$cant           = count($publicaciones);
			
			for ($i=0; $i < $cant; $i++) { 
				$publicaciones[$i]->comentarios = DB::select($consulta_comentarios, [$publicaciones[$i]->id]);
			}
			
			return $publicaciones;
		}
		
		
		if ($tipo_persona == 'Todos') {
			
			$consulta = 'SELECT p.*, 
				Case 
					When p.tipo_persona="Profesor" Then Concat(pr.nombres, " " , pr.apellidos) 
					When p.tipo_persona="Usuario" Then u.username 
					End
					AS nombre_autor, 
				Case 
					When p.tipo_persona="Profesor" Then ipr.nombre
					When p.tipo_persona="Usuario" Then ius.nombre 
					End
					AS foto_autor
				FROM publicaciones p
				LEFT JOIN profesores pr ON pr.id=p.persona_id and pr.deleted_at is null
				LEFT JOIN images ipr ON ipr.id=pr.foto_id and ipr.deleted_at is null
				LEFT JOIN users u ON u.id=p.persona_id and u.deleted_at is null
				LEFT JOIN images ius ON ius.id=u.imagen_id and ius.deleted_at is null
				
				WHERE (p.para_todos=1) and p.deleted_at is null order by p.updated_at desc limit 5';
				
			$publicaciones  = DB::select($consulta);
			$cant           = count($publicaciones);
			
			for ($i=0; $i < $cant; $i++) { 
				$consulta = 'SELECT count(c.publicacion_id) as cantidad
					FROM comentarios c
					WHERE c.publicacion_id=? and c.deleted_at is null
					GROUP BY c.publicacion_id';
				
				$comentarios = DB::select($consulta, [$publicaciones[$i]->id]);
				$publicaciones[$i]->comentarios = 0;
				
				if (count($comentarios) > 0) {
					
					$publicaciones[$i]->comentarios = $comentarios[0]->cantidad;
				}
			}
			
			return $publicaciones;
		}
		
		
	}



}