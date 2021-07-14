<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\ConfigCertificado;
use App\Models\Year;

class ConfigCertificadosController extends Controller {


	public function getIndex()
	{
		$user = User::fromToken();

		$certificados = ConfigCertificado::all();

		return $certificados;
	}

	

	public function postStore()
	{
		$user = User::fromToken();

		$certif = new ConfigCertificado;

		if (Request::input('encabezado_img_id')) {
			$certif->encabezado_img_id = Request::input('encabezado_img_id')['id'];
		}
		if (Request::input('piepagina_img_id')) {
			$certif->piepagina_img_id = Request::input('piepagina_img_id')['id'];
		}

		$certif->nombre 				= Request::input('nombre');
		$certif->encabezado_width 		= Request::input('encabezado_width');
		$certif->encabezado_height 		= Request::input('encabezado_height');
		$certif->encabezado_margin_top 	= Request::input('encabezado_margin_top');
		$certif->encabezado_margin_left = Request::input('encabezado_margin_left');
		$certif->encabezado_solo_primera_pagina = Request::input('encabezado_solo_primera_pagina', 0);
		$certif->piepagina_width 		= Request::input('piepagina_width');
		$certif->piepagina_height 		= Request::input('piepagina_height');
		$certif->piepagina_margin_bottom = Request::input('piepagina_margin_bottom');
		$certif->piepagina_margin_left 	= Request::input('piepagina_margin_left');
		$certif->piepagina_solo_ultima_pagina = Request::input('piepagina_solo_ultima_pagina', 0);
		$certif->created_by = $user->user_id;
		$certif->save();


		return $certif;
	}



	public function putUpdate()
	{
		$user = User::fromToken();

		$certif = ConfigCertificado::find(Request::input('id'));

		if (Request::input('encabezado_img')) {
			$certif->encabezado_img_id = Request::input('encabezado_img')['id'];
		}else{
			$certif->encabezado_img_id = null;
		}
		
		if (Request::input('piepagina_img')) {
			$certif->piepagina_img_id = Request::input('piepagina_img')['id'];
		}else{
			$certif->piepagina_img_id = null;
		}
		
		$certif->nombre 				= Request::input('nombre');
		$certif->encabezado_width 		= Request::input('encabezado_width');
		$certif->encabezado_height 		= Request::input('encabezado_height');
		$certif->encabezado_margin_top 	= Request::input('encabezado_margin_top');
		$certif->encabezado_margin_left = Request::input('encabezado_margin_left');
		$certif->encabezado_solo_primera_pagina = Request::input('encabezado_solo_primera_pagina', 0);
		$certif->piepagina_width 		= Request::input('piepagina_width');
		$certif->piepagina_height 		= Request::input('piepagina_height');
		$certif->piepagina_margin_bottom = Request::input('piepagina_margin_bottom');
		$certif->piepagina_margin_left 	= Request::input('piepagina_margin_left');
		$certif->piepagina_solo_ultima_pagina = Request::input('piepagina_solo_ultima_pagina', 0);
		$certif->created_by = $user->user_id;
		$certif->save();


		return $certif;
	}

	

	public function putActual()
	{
		$user = User::fromToken();

		$year = Year::find(Request::input('year_id'));

		$year->config_certificado_estudio_id = Request::input('config_certificado_estudio_id');
		$year->save();

		return 'Cambiado';
	}



	public function putEncabezado()
	{
		$user = User::fromToken();

		$year = Year::find(Request::input('year_id'));

		$year->encabezado_certificado = Request::input('encabezado_certificado');
		$year->save();

		return 'Cambiado';
	}


	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$certif = ConfigCertificado::find($id);
		$certif->delete();
		return $certif;
	}



}


