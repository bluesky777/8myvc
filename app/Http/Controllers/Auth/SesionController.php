<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ContextoDeUsuario;
use App\Services\Login;
use App\Services\Sesion;
use App\Services\VotacionesPendientes;
use App\User;
use Illuminate\Http\Request;

/**
 * Entrar, renovar y salir.
 *
 * El contrato completo, con los códigos de error y los números, está en
 * docs/migracion/07-sesion.md. Lo esencial: una sesión son DOS tokens.
 *
 * - El de **acceso** viaja en cada petición y vive una hora. No puede renovarse
 *   a sí mismo, y ahí está la gracia: un token que se renueva después de
 *   caducar no caduca, solo lo parece.
 * - El de **refresco** solo se manda aquí, a `refrescar()`, vive catorce días y
 *   rota en cada uso.
 *
 * Las rutas viejas (`login/credentials`, `login/logout`) siguen existiendo y
 * funcionando: cada colegio despliega su propio front, y durante la transición
 * habrá colegios con el backend nuevo y el front de antes. Ver LoginController.
 */
class SesionController extends Controller
{
    /**
     * POST /api/auth/login
     *
     * Devuelve el par, los segundos de vida del de acceso, y de propina el
     * contexto del usuario, para ahorrarle al frontend la segunda vuelta que
     * hoy da a `POST /api/login`.
     */
    public function login(Request $peticion)
    {
        // Ruta nueva, códigos correctos: faltar un campo es 422, no 400. Las
        // credenciales equivocadas siguen siendo 400 'invalid_credentials',
        // que es lo que el frontend ya distingue.
        $peticion->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $entrada = app(Login::class)->entrar($peticion);

        $usuario = $entrada['usuario'];

        $respuesta = app(Sesion::class)->abrir($usuario, $this->origen($peticion));

        if ($entrada['cambia_anio'] !== null) {
            $respuesta['cambia_anio'] = $entrada['cambia_anio'];
        }

        // `fresh()` porque `entrar()` puede haberle cambiado el periodo, y el
        // contexto se monta a partir de él.
        $contexto = app(ContextoDeUsuario::class)->para($usuario->fresh());
        $contexto = app(VotacionesPendientes::class)->adjuntarA($contexto);

        $respuesta['usuario'] = json_decode(json_encode($contexto), true);

        return $respuesta;
    }

    /**
     * POST /api/auth/refresh
     *
     * Va FUERA del guard `auth.token`, y tiene que ser así: el guard exige un
     * token de acceso vivo, y si hiciera falta uno vivo para renovar, no se
     * podría renovar nunca. Aquí el token que se presenta es el de refresco.
     */
    public function refrescar(Request $peticion)
    {
        $sesion = app(Sesion::class);

        $plano = $sesion->tokenPlanoDe($peticion);

        if ($plano === null) {
            return response()->json(['error' => 'refresco_invalido'], 401);
        }

        $refresco = $sesion->refrescoDe($plano);

        // Cae aquí también quien manda el token de acceso por error: solo el de
        // refresco abre esta puerta.
        if ($refresco === null) {
            return response()->json(['error' => 'refresco_invalido'], 401);
        }

        $usuario = $refresco->tokenable;

        if (! $usuario instanceof User) {
            return response()->json(['error' => 'refresco_invalido'], 401);
        }

        // A quien han desactivado entre medias no se le renueva. Sin esto, un
        // usuario dado de baja seguiría dentro catorce días.
        if (! $usuario->is_active) {
            return response()->json(['error' => 'user_inactivo'], 400);
        }

        return $sesion->rotar($refresco);
    }

    /**
     * POST /api/auth/logout
     *
     * Borra el par entero. Responde 200 aunque no hubiera nada que borrar: el
     * frontend tiene que poder limpiar su estado pase lo que pase aquí, y un
     * error le dejaría atrapado en una sesión que ya no vale.
     */
    public function logout(Request $peticion)
    {
        $sesion = app(Sesion::class);

        $token = $sesion->tokenDe($peticion, true);

        if ($token !== null) {
            $sesion->cerrar($token);
        }

        return ['ok' => true];
    }

    /**
     * POST /api/auth/logout-all
     *
     * Cierra la sesión en todos los dispositivos. Es lo que hay que llamar
     * cuando alguien sospecha que le han robado el token — y hasta la Fase 3 no
     * existía ninguna forma de hacerlo, porque un JWT no se puede revocar.
     */
    public function logoutTodas(Request $peticion)
    {
        $usuario = app(Sesion::class)->usuarioDe($peticion);

        if (! $usuario instanceof User) {
            return ['ok' => true, 'borrados' => 0];
        }

        return ['ok' => true, 'borrados' => app(Sesion::class)->cerrarTodas($usuario)];
    }

    /**
     * GET /api/auth/me
     *
     * Lo mismo que devuelve `POST /api/login`, hasta las votaciones
     * pendientes, porque es el mismo código. La diferencia es el verbo y que
     * esta sí exige token.
     */
    public function yo(Request $peticion)
    {
        $contexto = User::fromToken(false, $peticion);

        $contexto = app(VotacionesPendientes::class)->adjuntarA($contexto);

        return json_decode(json_encode($contexto), true);
    }

    /**
     * De dónde entra, para poder distinguir las sesiones en la tabla.
     *
     * No es seguridad —el cliente lo dice de sí mismo—, es para poder mirar
     * `personal_access_tokens` y saber si una sesión es del navegador o de la
     * app. El nombre completo es 'web:<uuid>'; esto es solo la primera mitad.
     */
    private function origen(Request $peticion): string
    {
        $declarado = (string) $peticion->input('origen', 'web');

        return preg_match('/^[a-z0-9_-]{1,20}$/i', $declarado) ? strtolower($declarado) : 'web';
    }
}
