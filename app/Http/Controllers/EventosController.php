<?php namespace App\Http\Controllers;

use App\User;
use App\Models\VtVotacion;
use App\Models\VtParticipante;
use App\Models\VtVoto;



class EventosController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();


		$votacion = VtVotacion::where('in_action', '=', true)->first();

		$hayVotacion = false;
		$signed = false;
		$voted = false;
		$rutear = false;
		
		if ($votacion){
			
			$hayVotacion = true;

			$signed = VtParticipante::isSigned($user->user_id, $votacion->id);

			if ($signed) {
				$voted = VtVoto::hasVoted($votacion->id, $signed->id);
				$rutear = true;
			}

			$eventos = array(
				'votaciones'=>array(
					'hay'	=> $hayVotacion, 
					'signed'=> $signed,
					'voted' => $voted,
					'rutear'=> $rutear,
					'state' => 'votaciones.votar',
				)
			);


			return $eventos;

		}else{
			$eventos = array(
				'votaciones'=>array(
					'hay'	=> false, 
					'signed'=> false,
					'voted' => false,
					'rutear'=> false,
					'state' => '',
				)
			);
			return $eventos;
		}


	}

	
	/**
	 * Store a newly created resource in storage.
	 * POST /eventos
	 *
	 * @return Response
	 */
	public function store()
	{
		//
	}

	/**
	 * Display the specified resource.
	 * GET /eventos/{id}
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
	 * GET /eventos/{id}/edit
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
	 * PUT /eventos/{id}
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($id)
	{
		//
	}

	/**
	 * Remove the specified resource from storage.
	 * DELETE /eventos/{id}
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id)
	{
		//
	}

}