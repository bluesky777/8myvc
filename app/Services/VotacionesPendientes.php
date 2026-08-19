<?php

namespace App\Services;

use App\Models\VtVotacion;
use Illuminate\Support\Facades\DB;

/**
 * Le cuelga al contexto del usuario las votaciones en las que está inscrito y
 * todavía no ha completado.
 *
 * Esto vivía dentro de `LoginController::postIndex`. Se saca aquí porque
 * `GET /api/auth/me` tiene que devolver exactamente lo mismo que `POST
 * /api/login`, y "exactamente lo mismo" solo se sostiene si es el mismo código.
 *
 * La clave `votaciones` solo aparece si hay alguna pendiente. No se pone a
 * lista vacía cuando no las hay, y eso es contrato: el frontend comprueba la
 * existencia de la clave, no su longitud.
 */
class VotacionesPendientes
{
    public function adjuntarA($usuario)
    {
        $votaciones = VtVotacion::actualesInscrito($usuario, true);
        $pendientes = [];

        $cantidad = count($votaciones);

        if ($cantidad === 0) {
            return $usuario;
        }

        for ($i = 0; $i < $cantidad; $i++) {
            $aspiraciones = DB::select('SELECT * FROM vt_aspiraciones WHERE votacion_id=?', [$votaciones[$i]->id]);

            $completos = VtVotacion::verificarVotosCompletos($aspiraciones, $votaciones[$i]->id, $usuario->user_id);

            $votaciones[$i]->completos = $completos;

            if (! $completos) {
                array_push($pendientes, $votaciones[$i]);
            }
        }

        if (count($pendientes) > 0) {
            $usuario->votaciones = $pendientes;
        }

        return $usuario;
    }
}
