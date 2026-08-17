<?php namespace App\Http\Controllers;

use App\User;
use App\Models\Permission;

class PermissionsController extends Controller {


	public function getIndex()
	{
		// Sin token esto exponía el catálogo completo de permisos del sistema.
		// Los métodos de escritura de abajo son cuerpos vacíos, así que no había
		// escritura que cerrar aquí.
		User::fromToken();

		return Permission::all();
	}

	public function postIndex()
	{
		//
	}

	public function getShow($id)
	{
		//
	}

	public function putUpdate($id)
	{
		//
	}

	public function deleteDestroy($id)
	{
		//
	}

}