<?php namespace App\Http\Controllers\Alumnos;



use Request;
use DB;
use Hash;
use Carbon\Carbon;

use App\User;
use App\Models\Year;
use App\Models\Periodo;
use App\Models\Debugging;
use \Log;



class ImporterFixer {

    public $tipos_doc;
    public $cant_td;
    
	
	public function __construct()
	{
        $this->tipos_doc    = DB::select('SELECT id, tipo, abrev FROM tipos_documentos WHERE deleted_at is null');
        $this->cant_td      = count($this->tipos_doc);
        $this->ciudades    	= DB::select('SELECT id, ciudad FROM ciudades WHERE deleted_at is null');
        $this->cant_ciud    = count($this->ciudades);
    }
    

	public function verificar(&$alumno, $year)
	{
		$cons = '';
		$consA1 = '';
		$consA2 = '';
		$ciudad_id_A1 = null;
		$ciudad_id_A2 = null;
		Log::info("id: " . $alumno["id"]) . ". Nombre: " . $alumno["primer_nombre"];
		//if ($alumno->tipo_de_documento == 'fecha_nac')
		//	$valor = Carbon::parse($valor);

		// Tipo doc
		for ($i=0; $i < $this->cant_td; $i++) { 
			
			$tipo_low 		= strtolower($this->tipos_doc[$i]->tipo);
			$abrev_low 		= strtolower($this->tipos_doc[$i]->abrev);
			$altipo_low 	= strtolower($alumno["tipo_de_documento"]);
			$A1tipo_low 	= strtolower($alumno["tipo_docu_acud1"]);
			$A2tipo_low 	= strtolower($alumno["tipo_docu_acud2"]);
			
            if( $tipo_low == $altipo_low || $abrev_low == $altipo_low ){
                $alumno["tipo_doc"] = $this->tipos_doc[$i]->id;
            }
            if( $tipo_low == $A1tipo_low || $abrev_low == $A1tipo_low ){
                $alumno["tipo_docu_acud1"] = $this->tipos_doc[$i]->id;
            }
            if( $tipo_low == $A2tipo_low || $abrev_low == $A2tipo_low ){
                $alumno["tipo_docu_acud2"] = $this->tipos_doc[$i]->id;
            }
        } 
        if(!array_key_exists("tipo_doc", $alumno)){
            $alumno["tipo_doc"] = 3; // 3 es Tarjeta de identidad
		}

		$alumno["no_matricula"] = $alumno["numero_matricula"];
		if(is_int($alumno["fecha_de_nacim"])){
			$alumno["fecha_de_nacim"] = Carbon::parse('30-12-1899')->addDays($alumno["fecha_de_nacim"])->format('Y-m-d');
		}

		// ciudad de doc y ciudad de nac
		for ($i=0; $i < $this->cant_ciud; $i++) { 
            if(strtolower($this->ciudades[$i]->ciudad) == strtolower($alumno["lugar_de_expedicion_ciudad"]) || $this->ciudades[$i]->id == $alumno["lugar_de_expedicion_ciudad"]){
				$cons .= ', ciudad_doc='.$this->ciudades[$i]->id;
			}
			if(strtolower($this->ciudades[$i]->ciudad) == strtolower($alumno["ciudad_nacimiento"]) || $this->ciudades[$i]->id == $alumno["ciudad_nacimiento"]){
				$cons .= ', ciudad_nac='.$this->ciudades[$i]->id;
            }
			if(strtolower($this->ciudades[$i]->ciudad) == strtolower($alumno["ciudad_residencia"]) || $this->ciudades[$i]->id == $alumno["ciudad_residencia"]){
				$cons .= ', ciudad_resid='.$this->ciudades[$i]->id;
            }
			if(strtolower($this->ciudades[$i]->ciudad) == strtolower($alumno["ciudad_docu_acud1"]) || $this->ciudades[$i]->id == $alumno["ciudad_docu_acud1"]){
				$consA1 		.= ', ciudad_doc='.$this->ciudades[$i]->id;
				$ciudad_id_A1 	= $this->ciudades[$i]->id;
            }
			if(strtolower($this->ciudades[$i]->ciudad) == strtolower($alumno["ciudad_docu_acud2"]) || $this->ciudades[$i]->id == $alumno["ciudad_docu_acud2"]){
				$consA2 		.= ', ciudad_doc='.$this->ciudades[$i]->id;
				$ciudad_id_A2 	= $this->ciudades[$i]->id;
            }
		}
		
		// is_urbana
		if(strtolower($alumno["urbana"])=='si'){
            $cons .= ', is_urbana=1';
		}else if(strtolower($alumno["urbana"])=='no'){
			$cons .= ', is_urbana=0';
		}
		
		// SISBEN
		if(strtolower($alumno["sisben"])=='no aplica' || $alumno["sisben"]=='' || is_null($alumno["sisben"])){
            $cons .= ', has_sisben=0, nro_sisben=null';
		}else{
			$cons .= ', has_sisben=1, nro_sisben='.$alumno["sisben"];
		}
		
		// SISBEN 3
		if(strtolower($alumno["sisben_3"])=='no aplica' || $alumno["sisben_3"]=='' || is_null($alumno["sisben_3"])){
            $cons .= ', has_sisben_3=0, nro_sisben_3=null';
		}else{
			$cons .= ', has_sisben_3=1, nro_sisben_3='.$alumno["sisben_3"];
		}
		
		// Nuevo
		if(strtolower($alumno["nuevo"])=='no' || $alumno["nuevo"]=='' || is_null($alumno["nuevo"])){
			$alumno["es_nuevo"]=0;
		}else if(strtolower($alumno["nuevo"])=='si'){
			$alumno["es_nuevo"]=1;
		}
		
		
		
		// Es acudiente 1
		if(strtolower($alumno["es_el_acudiente_acud1"])=='no' || $alumno["es_el_acudiente_acud1"]=='' || is_null($alumno["es_el_acudiente_acud1"])){
			$alumno["is_acudiente1"]=0;
			//Debugging::pin('$alumno["es_el_acudiente_acud1"]=="no" ', $alumno["es_el_acudiente_acud1"]);
		}else if(strtolower($alumno["es_el_acudiente_acud1"])=='si'){
			$alumno["is_acudiente1"]=1;
			//Debugging::pin('$alumno->es_el_acudiente_acud1=="SI" ');
		}
		
		// Es acudiente 2
		if(strtolower($alumno["es_el_acudiente_acud2"])=='no' || $alumno["es_el_acudiente_acud2"]=='' || is_null($alumno["es_el_acudiente_acud2"])){
			$alumno["is_acudiente2"]=0;
		}else if(strtolower($alumno["es_el_acudiente_acud2"])=='si'){
			$alumno["is_acudiente2"]=1;
		}
		
		// Parentesco 1
		if(is_null($alumno["parentesco_acud1"]) || $alumno["parentesco_acud1"]==''){
			$alumno["parentesco_acud1"] = 'Madre';
		}
		// Parentesco 2
		if(is_null($alumno["parentesco_acud2"]) || $alumno["parentesco_acud2"]==''){
			$alumno["parentesco_acud2"] = 'Madre';
		}
		
		return ['consulta' => $cons, 'consultaA1' => $consA1, 'consultaA2' => $consA2, 'ciudad_id_A1' => $ciudad_id_A1, 'ciudad_id_A2' => $ciudad_id_A2];

	}



	public function valorAcudiente($acudiente_id, $parentesco_id, $user_acud_id, $propiedad, $valor, $user_id)
	{

		$consulta 	= '';
		$datos 		= [];
		$now 		= Carbon::now('America/Bogota');

		if ($propiedad == 'fecha_nac')
			$valor = Carbon::parse($valor);

		switch ($propiedad) {
			case 'username':
				$consulta 	= 'UPDATE users SET username=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':user_id' => $user_acud_id ];
				break;
			
			case 'parentesco':
				$consulta 	= 'UPDATE parentescos SET parentesco=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:parentesco_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':parentesco_id' => $parentesco_id ];
				break;
			
			default:
				$consulta = 'UPDATE acudientes SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:acudiente_id';
				$datos 		= [
					':valor'		=> $valor, 
					':modificador'	=> $user_id, 
					':fecha' 		=> $now,
					':acudiente_id'	=> $acudiente_id
				];
				break;
		}
		
		
		$consulta = DB::raw($consulta);

		$res = DB::update($consulta, $datos);

		if($res)
			return 'Guardado';
		else
			return 'No guardado';

	}



}