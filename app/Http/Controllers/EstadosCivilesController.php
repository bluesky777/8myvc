<?php namespace App\Http\Controllers;

class EstadosCivilesController extends Controller {

	/**
	 * Display a listing of the resource.
	 * GET /estadocivils
	 *
	 * @return Response
	 */
	public function index()
	{
		return EstadoCivil::all();
	}

	/**
	 * Show the form for creating a new resource.
	 * GET /estadocivils/create
	 *
	 * @return Response
	 */
	public function create()
	{
		//
	}

	/**
	 * Store a newly created resource in storage.
	 * POST /estadocivils
	 *
	 * @return Response
	 */
	public function store()
	{
		Eloquent::unguard();
		try {
			$estado = EstadoCivil::create([
				'estado'=>	Input::get('estado'),
				'abrev'	=>	Input::get('abrev'),

			]);
			return $estado;
		} catch (Exception $e) {
			return $e;
		}
	}

	/**
	 * Display the specified resource.
	 * GET /estadocivils/{id}
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($id)
	{
		//
	}

	/**
	 * Show the form for editing the specified resource.
	 * GET /estadocivils/{id}/edit
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($id)
	{
		//
	}

	/**
	 * Update the specified resource in storage.
	 * PUT /estadocivils/{id}
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($id)
	{
		$estado = EstadoCivil::findOrFail($id);
		try {
			$estado->fill([
				'estado'=>	Input::get('estado'),
				'abrev'	=>	Input::get('abrev'),

			]);

			$estado->save();
		} catch (Exception $e) {
			return $e;
		}
	}

	/**
	 * Remove the specified resource from storage.
	 * DELETE /estadocivils/{id}
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id)
	{
		$estado = EstadoCivil::findOrFail($id);
		$estado->delete();

		return $estado;
	}

}