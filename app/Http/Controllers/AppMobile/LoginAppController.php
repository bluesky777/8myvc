<?php namespace App\Http\Controllers;


use JWTAuth;
use Browser;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

use Illuminate\Http\Request;
//use Request;
//use Auth;
use Hash;
use DB;
use Carbon\Carbon;


use App\User;
use App\Models\VtVotacion;
use App\Models\Periodo;
use App\Models\Year;
use App\Models\Role;
use \Log;



class LoginController extends Controller {
	

	public function postIndex(Request $request)
	{

		$user = [];
		$token = [];
		

		try
		{
			$token = JWTAuth::parseToken();

			if ($token){
				$user = User::fromToken(false, $request);
			}else if ((!($request->has('username')) && $request->input('username') != ''))  {
				return response()->json(['error' => 'Token expirado'], 401);
			}
		}
		catch(Tymon\JWTAuth\Exceptions\TokenExpiredException $e)
		{
			if (! count(Input::all())) {
				return response()->json(['error' => 'token_expired'], 401);
			}
		}
		catch(JWTException $e){
			// No haremos nada, continuaremos verificando datos.
		}

		return json_decode(json_encode($user), true);
	}


}