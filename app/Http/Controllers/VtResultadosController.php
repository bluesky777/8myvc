<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\VtActa;
use App\Models\VtVotacion;
use Illuminate\Support\Facades\DB;

/**
 * **El escrutinio**: la urna digital y las actas de papel, sumadas.
 *
 *     GET  resultados/{votacion}
 *
 * ## LA SUMA ES LA RAZÓN DE QUE ESTO EXISTA
 *
 * Un voto puede haber entrado por tres caminos y los tres cuentan lo mismo:
 *
 *   - `propio` — alguien votó desde su cuenta, donde estuviera;
 *   - `mesa`   — votó en una mesa, con alguien del colegio delante;
 *   - `papel`  — su grupo votó en papeletas y una persona contó los montones.
 *
 * Los dos primeros son filas de `vt_votos` y se distinguen por `origen`. El tercero
 * **no es una fila por persona y no puede serlo**: de un montón de papeletas no se
 * saca quién votó qué (ver `VtActasController`). Por eso el desglose viaja en cada
 * cargo y en cada candidato: es lo que la pantalla pinta como *«1.088 de la
 * plataforma, 63 de tres actas»*, y sin él un colegio no puede explicar de dónde
 * salió un número.
 *
 * ## EL BUG QUE ARRASTRABA EL CÓDIGO VIEJO, Y QUE AQUÍ NO SE REPITE
 *
 * > **El total de un cargo se dejaba fuera los votos en blanco.**
 *
 * Venía de contar uniendo con `vt_candidatos`: un voto en blanco no tiene candidato
 * con el que unir, así que desaparecía del `total` aunque sí saliera en su propia
 * casilla. Un cargo con 40 votos y 8 blancos decía «total 32», **y los porcentajes
 * de los candidatos salían de ese 32**, o sea inflados uno por uno. Está descrito en
 * `VtVoto::deCandidato()`, que se reescribió el 22 sep 2026 por lo mismo.
 *
 * Aquí el total de un cargo es **todo lo que se echó en esa urna** —candidatos más
 * blanco, digital más papel— y los porcentajes salen de ahí. El blanco cuenta en el
 * total como cuenta en una urna de verdad.
 *
 * ## `can_see_results` DECIDE EL NÚMERO, NO LA ESTRUCTURA
 *
 * Y con una excepción que es la mitad de la regla: **antes de publicar, el recuento
 * lo ve exactamente quien puede publicarlo** —superusuario, rectoría, coordinación y
 * quien creó la elección—, que es `VtVotacion::puedePublicarResultados()`. El
 * interruptor existe para que nadie vea el marcador antes de que se decida
 * publicarlo, no para que el rector no pueda mirar su propia elección.
 *
 * > **Esto era «al personal del colegio se le da siempre» hasta el 23 sep 2026**, o
 * > sea todo el que no fuera Alumno ni Acudiente, y el colegio dijo que era
 * > demasiado: un docente de matemáticas, la enfermera y la secretaria veían el
 * > escrutinio en vivo sin que nadie lo publicara. El criterio entero y quién lo
 * > decidió están en el predicado del modelo y en la 11 §9.
 *
 * A quien no puede publicar y tiene el interruptor apagado se le devuelve la
 * estructura —cargos y candidatos— y **ni un número: ni el del candidato, ni el
 * blanco, ni el total, ni la participación**. Es más de lo que hacía el código viejo,
 * donde el `if` mezclaba «dame la papeleta» con «dame el conteo» y el escrutinio en
 * vivo viajaba dentro del JSON de cualquier alumno con la elección abierta (11 §1).
 *
 * `conteo_visible` va en la respuesta para que la pantalla sepa por qué no hay
 * números, en vez de pintar ceros.
 */
class VtResultadosController extends Controller
{
    use ResuelveElUsuario;

    /** Los tres caminos por los que entra un voto. Los dos primeros son `vt_votos.origen`. */
    private const ORIGENES = ['propio', 'mesa', 'papel'];

    public function getShow($votacion_id)
    {
        // `user_id` es el dueño de la elección y lo pide
        // `VtVotacion::puedePublicarResultados()`. Sin él, quien la creó no se
        // reconocería a sí mismo y no fallaría nada: ver el docblock del predicado.
        $votacion = DB::selectOne('SELECT id, user_id, nombre, year_id, can_see_results, locked, in_action, actual
            FROM vt_votaciones WHERE id = ? AND deleted_at IS NULL', [$votacion_id]);

        if ($votacion === null) {
            abort(404, 'Esa votación no existe.');
        }

        // El interruptor decide el número; y antes de publicarlo lo ve quien puede
        // publicarlo, que es la otra mitad de la regla. Ver la cabecera de la clase.
        $conConteo = (bool) $votacion->can_see_results
            || VtVotacion::puedePublicarResultados($votacion, $this->user);

        $cabecera = [
            'votacion' => [
                'id' => (int) $votacion->id,
                'nombre' => $votacion->nombre,
                'year_id' => $votacion->year_id,
                'can_see_results' => (int) $votacion->can_see_results,
            ],
            'conteo_visible' => $conConteo,
        ];

        if (! $conConteo) {
            // La estructura y nada más. Ver la cabecera de la clase.
            return $cabecera + ['cargos' => $this->cargosSinNumeros($votacion->id)];
        }

        return $cabecera + $this->escrutinio($votacion);
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  El recuento
     * ─────────────────────────────────────────────────────────────────────────
     */

    /**
     * Cargo a cargo, con las dos urnas dentro.
     *
     * Tres consultas para toda la elección y no tres por candidato, que es lo que
     * hacía el módulo viejo —`VtVoto::deCandidato()` una vez por cada uno, y cada
     * llamada con dos subconsultas dentro—. Con veinte candidatos y cuatro cargos eso
     * eran ochenta consultas para pintar una pantalla.
     */
    private function escrutinio($votacion): array
    {
        $digital = [];

        foreach (DB::select('SELECT aspiracion_id, candidato_id, origen, COUNT(*) AS cantidad
                FROM vt_votos WHERE votacion_id = ?
             GROUP BY aspiracion_id, candidato_id, origen', [$votacion->id]) as $fila) {

            $llave = $this->llave($fila->aspiracion_id, $fila->candidato_id);

            /*
             * `origen` es `varchar(6)` con defecto 'propio' y la migración 600000 lo
             * rellenó así en las filas viejas —antes de esta tanda no había mesas—.
             * Aun así se comprueba: un valor inventado a mano en la base no puede
             * hacer desaparecer un voto del total, que es lo que pasaría si se sumara
             * a una casilla que la pantalla no pinta.
             */
            $origen = in_array($fila->origen, self::ORIGENES, true) ? $fila->origen : 'propio';

            $digital[$llave][$origen] = ($digital[$llave][$origen] ?? 0) + (int) $fila->cantidad;
        }

        $papel = [];
        $actasPorCargo = [];

        foreach (VtActa::recuentoDeLaVotacion($votacion->id) as $fila) {
            $papel[$this->llave($fila->aspiracion_id, $fila->candidato_id)] = (int) $fila->cantidad;
            $actasPorCargo[(int) $fila->aspiracion_id] = true;
        }

        $cargos = [];
        $totalPorOrigen = ['propio' => 0, 'mesa' => 0, 'papel' => 0];

        foreach ($this->cargosDe($votacion->id) as $cargo) {
            $casillas = [];

            foreach ($this->candidatosDe($cargo->id) as $candidato) {
                $casillas[] = $this->casilla($candidato, $digital, $papel, (int) $cargo->id, (int) $candidato->candidato_id);
            }

            // El blanco va aparte y **cuenta en el total**: es la mitad del bug de la
            // cabecera. No es un candidato con id falso metido en la lista de arriba.
            $blanco = $this->casilla(null, $digital, $papel, (int) $cargo->id, null);

            $total = $blanco['total'];

            foreach ($casillas as $casilla) {
                $total += $casilla['total'];
            }

            $porOrigen = ['propio' => 0, 'mesa' => 0, 'papel' => 0];

            foreach (array_merge($casillas, [$blanco]) as $casilla) {
                foreach (self::ORIGENES as $origen) {
                    $porOrigen[$origen] += $casilla['origen'][$origen];
                    $totalPorOrigen[$origen] += $casilla['origen'][$origen];
                }
            }

            foreach ($casillas as $i => $casilla) {
                $casillas[$i]['porcentaje'] = $this->porcentaje($casilla['total'], $total);
            }

            $blanco['porcentaje'] = $this->porcentaje($blanco['total'], $total);

            $cargos[] = [
                'aspiracion_id' => (int) $cargo->id,
                'aspiracion' => $cargo->aspiracion,
                'abrev' => $cargo->abrev,
                'candidatos' => $casillas,
                'blanco' => $blanco,
                'total' => $total,
                'origen' => $porOrigen,
            ];
        }

        $actas = (int) DB::selectOne('SELECT COUNT(*) AS cuantas FROM vt_actas WHERE votacion_id = ?',
            [$votacion->id])->cuantas;

        return [
            'cargos' => $cargos,
            'origen' => $totalPorOrigen + ['actas' => $actas],
            'participacion' => $this->participacion($votacion),
        ];
    }

    /**
     * Una casilla del escrutinio: un candidato, o el blanco.
     *
     * `total` es la suma de los tres orígenes, y **el desglose viaja al lado**. Eso es
     * lo que permite explicar un número sin volver a preguntar: un candidato con 63
     * votos de los que 60 son de una sola acta es un dato distinto de uno con 63
     * repartidos.
     *
     * @param  object|null  $candidato  null = el voto en blanco
     * @return array{candidato_id:?int, nombres:?string, apellidos:?string, plancha:?string, numero:?string, foto_nombre:?string, imagen_nombre:?string, blanco:bool, total:int, origen:array<string,int>, porcentaje:float}
     */
    private function casilla($candidato, array $digital, array $papel, int $aspiracion_id, ?int $candidato_id): array
    {
        $llave = $this->llave($aspiracion_id, $candidato_id);

        $origen = [
            'propio' => (int) ($digital[$llave]['propio'] ?? 0),
            'mesa' => (int) ($digital[$llave]['mesa'] ?? 0),
            'papel' => (int) ($papel[$llave] ?? 0),
        ];

        return [
            'candidato_id' => $candidato_id,
            'nombres' => $candidato === null ? 'Voto en Blanco' : $candidato->nombres,
            'apellidos' => $candidato === null ? null : $candidato->apellidos,
            'plancha' => $candidato === null ? null : $candidato->plancha,
            'numero' => $candidato === null ? null : $candidato->numero,
            // La cara del candidato. El blanco no tiene, y va en nulo y no en el
            // `voto_en_blanco.jpg` que se inventa la papeleta vieja: ese fichero es
            // del front web y desde una respuesta es una ruta que el cliente no tiene.
            'foto_nombre' => $candidato === null ? null : $candidato->foto_nombre,
            'imagen_nombre' => $candidato === null ? null : $candidato->imagen_nombre,
            'blanco' => $candidato === null,
            'total' => $origen['propio'] + $origen['mesa'] + $origen['papel'],
            'origen' => $origen,
            'porcentaje' => 0.0,
        ];
    }

    /**
     * Cuánta gente votó, sobre el censo, y por grado.
     *
     * ## EL CENSO SALE DE `VtVotacion::censo()` Y NO DE UNA CONSULTA DE AQUÍ
     *
     * La regla —matrícula viva del **año de la votación**, estado que no sea `RETI`
     * ni `DESE`, grupo no apartado, una fila por alumno— vive en un sitio a propósito,
     * y copiarla aquí sería tener dos censos que se separan el día que se toque uno.
     * Lo único que este método añade es el **grado**, que esa consulta no devuelve, y
     * lo resuelve con una tabla de `grupo_id → grado` en una consulta.
     *
     * ## LAS TRES CIFRAS SON TRES COSAS DISTINTAS, y por eso van las tres
     *
     *   - `votantes` — gente **del censo** que votó. Es el numerador del porcentaje.
     *   - `otros_votantes` — gente que votó y **no está en el censo**: docentes,
     *     acudientes, administrativos. `votan_profes` existe desde 2014 y esa gente no
     *     tiene censo, sólo el interruptor de su estamento. Sin esta cifra, un colegio
     *     que suma las dos columnas encuentra un descuadre y no sabe de dónde sale.
     *   - `papel` — lo que entró por las actas, **aparte y sin porcentaje**.
     *
     * ## POR QUÉ EL PAPEL NO ENTRA EN EL PORCENTAJE
     *
     * Porque no hay votantes que contar: hay papeletas. Una persona vota varios cargos,
     * así que 63 papeletas no son 63 personas, y repartirlas sería inventarse un
     * número. Lo más cerca que se puede estar es `votantes_estimados` —el cargo más
     * votado de cada acta, que es una **cota inferior** cierta: nadie pudo votar ese
     * cargo dos veces— y va con ese nombre para que no se lea como un recuento.
     *
     * Y hay una segunda razón, que es la que se ve en el ensayo 901: el grupo que vota
     * en papel suele estar marcado `participa = 0`, o sea que **no está en el censo**.
     * Meterlo en el numerador sin estar en el denominador daría porcentajes por encima
     * de cien sin que nada estuviera mal.
     */
    private function participacion($votacion): array
    {
        $censo = VtVotacion::censo($votacion);

        $votantes = [];

        foreach (DB::select('SELECT DISTINCT user_id FROM vt_votos WHERE votacion_id = ?',
            [$votacion->id]) as $fila) {
            $votantes[(int) $fila->user_id] = true;
        }

        $gradoDelGrupo = [];

        foreach (DB::select('SELECT g.id, g.grado_id, gr.nombre AS grado, gr.orden
                FROM grupos g
                LEFT JOIN grados gr ON gr.id = g.grado_id
                WHERE g.year_id = ? AND g.deleted_at IS NULL', [$votacion->year_id]) as $fila) {
            $gradoDelGrupo[(int) $fila->id] = $fila;
        }

        $porGrado = [];
        $enElCenso = [];
        $conVoto = 0;

        foreach ($censo as $fila) {
            $enElCenso[(int) $fila->user_id] = true;

            $grupo = $gradoDelGrupo[(int) $fila->grupo_id] ?? null;
            $grado_id = $grupo === null ? 0 : (int) ($grupo->grado_id ?? 0);

            if (! isset($porGrado[$grado_id])) {
                $porGrado[$grado_id] = [
                    'grado_id' => $grado_id ?: null,
                    'grado' => $grupo->grado ?? 'Sin grado',
                    'orden' => $grupo->orden ?? null,
                    'censo' => 0,
                    'votantes' => 0,
                    'porcentaje' => 0.0,
                ];
            }

            $porGrado[$grado_id]['censo']++;

            if (isset($votantes[(int) $fila->user_id])) {
                $porGrado[$grado_id]['votantes']++;
                $conVoto++;
            }
        }

        foreach ($porGrado as $grado_id => $datos) {
            $porGrado[$grado_id]['porcentaje'] = $this->porcentaje($datos['votantes'], $datos['censo']);
        }

        // `usort` reindexa, así que de aquí sale ya una lista: no hace falta
        // `array_values` detrás.
        usort($porGrado, fn ($a, $b) => ($a['orden'] ?? PHP_INT_MAX) <=> ($b['orden'] ?? PHP_INT_MAX));

        $fuera = 0;

        foreach (array_keys($votantes) as $user_id) {
            if (! isset($enElCenso[$user_id])) {
                $fuera++;
            }
        }

        return [
            'censo' => count($censo),
            'votantes' => $conVoto,
            'otros_votantes' => $fuera,
            'porcentaje' => $this->porcentaje($conVoto, count($censo)),
            'por_grado' => $porGrado,
            'papel' => $this->participacionDePapel($votacion),
        ];
    }

    /**
     * Lo que entró por las actas, con su cota inferior de votantes.
     *
     * `votantes_estimados` es, por cada acta, **el cargo más votado**: nadie pudo
     * votar el mismo cargo dos veces, así que esa cifra no puede pasarse por arriba.
     * Sumada sobre las actas da la cota de toda la elección.
     */
    private function participacionDePapel($votacion): array
    {
        $porCargo = DB::select('SELECT av.acta_id, av.aspiracion_id, SUM(av.cantidad) AS cantidad
                FROM vt_acta_votos av
                INNER JOIN vt_actas a ON a.id = av.acta_id
                WHERE a.votacion_id = ?
             GROUP BY av.acta_id, av.aspiracion_id', [$votacion->id]);

        $papeletas = 0;
        $mayorPorActa = [];

        foreach ($porCargo as $fila) {
            $cantidad = (int) $fila->cantidad;
            $papeletas += $cantidad;
            $acta_id = (int) $fila->acta_id;

            $mayorPorActa[$acta_id] = max($mayorPorActa[$acta_id] ?? 0, $cantidad);
        }

        $estudiantes = 0;
        $actas = DB::select('SELECT id, grupo_id, firmada_en FROM vt_actas WHERE votacion_id = ?', [$votacion->id]);

        $firmadas = 0;

        foreach ($actas as $acta) {
            $estudiantes += VtActa::estudiantesDelGrupo($acta->grupo_id, $votacion->year_id);

            if ($acta->firmada_en !== null) {
                $firmadas++;
            }
        }

        return [
            'actas' => count($actas),
            'actas_firmadas' => $firmadas,
            'papeletas' => $papeletas,
            'estudiantes' => $estudiantes,
            'votantes_estimados' => array_sum($mayorPorActa),
        ];
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  Lo que se devuelve cuando el conteo no viaja
     * ─────────────────────────────────────────────────────────────────────────
     */

    /** Los cargos y sus candidatos, sin una sola cifra. */
    private function cargosSinNumeros($votacion_id): array
    {
        $cargos = [];

        foreach ($this->cargosDe($votacion_id) as $cargo) {
            $candidatos = [];

            foreach ($this->candidatosDe($cargo->id) as $candidato) {
                $candidatos[] = [
                    'candidato_id' => (int) $candidato->candidato_id,
                    'nombres' => $candidato->nombres,
                    'apellidos' => $candidato->apellidos,
                    'plancha' => $candidato->plancha,
                    'numero' => $candidato->numero,
                    'foto_nombre' => $candidato->foto_nombre,
                    'imagen_nombre' => $candidato->imagen_nombre,
                    'blanco' => false,
                ];
            }

            $candidatos[] = ['candidato_id' => null, 'nombres' => 'Voto en Blanco', 'apellidos' => null,
                'plancha' => null, 'numero' => null, 'foto_nombre' => null, 'imagen_nombre' => null,
                'blanco' => true];

            $cargos[] = [
                'aspiracion_id' => (int) $cargo->id,
                'aspiracion' => $cargo->aspiracion,
                'abrev' => $cargo->abrev,
                'candidatos' => $candidatos,
            ];
        }

        return $cargos;
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  Lo de siempre
     * ─────────────────────────────────────────────────────────────────────────
     */

    private function cargosDe($votacion_id)
    {
        return DB::select('SELECT id, aspiracion, abrev FROM vt_aspiraciones
            WHERE votacion_id = ? AND deleted_at IS NULL ORDER BY id', [$votacion_id]);
    }

    /**
     * Los candidatos de un cargo.
     *
     * Mismo criterio que `VtActasController::candidatosDe()` y por el mismo motivo:
     * **no** se usa `VtCandidato::porAspiracion()`, que une sólo con alumnos
     * matriculados del año y hace desaparecer en silencio a cualquier otro (11 §1).
     * En un escrutinio eso sería peor que en la papeleta: los votos de ese candidato
     * existen en `vt_votos`, así que no saldría su fila **pero su total seguiría
     * dentro del total del cargo**, y las cifras no cuadrarían sin explicación.     *
     * **La cara sale por el mismo camino que la papeleta**: `foto_nombre` de
     * `alumnos.foto_id`, `imagen_nombre` de `users.imagen_id`, y los dos con el
     * respaldo por sexo resuelto en SQL —`default_female.png` / `default_male.png`—,
     * que es lo que hacen `VtCandidato::porAspiracion()` y
     * `VtCensoController::getConductores()`. Copiar ese orden es el punto: resolverlo
     * aquí de otra forma le pondría al mismo candidato una cara en la papeleta y otra
     * en el recuento. El `default_*.png` no está en disco —da 404— y el `nz-avatar`
     * del front cae a las iniciales solo; el `COALESCE(a.sexo, u.sexo)` es por el
     * candidato sin ficha de alumno, que aquí sí sale.
     */
    private function candidatosDe($aspiracion_id)
    {
        return DB::select('SELECT c.id AS candidato_id, c.plancha, c.numero, c.user_id,
                    a.nombres, a.apellidos, u.username,
                    a.foto_id, IFNULL(f.nombre, IF(COALESCE(a.sexo, u.sexo) = "F", "default_female.png", "default_male.png")) AS foto_nombre,
                    u.imagen_id, IFNULL(i.nombre, IF(COALESCE(a.sexo, u.sexo) = "F", "default_female.png", "default_male.png")) AS imagen_nombre
                FROM vt_candidatos c
                LEFT JOIN users u ON u.id = c.user_id
                LEFT JOIN alumnos a ON a.user_id = c.user_id AND a.deleted_at IS NULL
                LEFT JOIN images f ON f.id = a.foto_id AND f.deleted_at IS NULL
                LEFT JOIN images i ON i.id = u.imagen_id AND i.deleted_at IS NULL
                WHERE c.aspiracion_id = ? AND c.deleted_at IS NULL
             ORDER BY c.plancha, c.id',
            [$aspiracion_id]);
    }

    /** La misma llave que usa `VtActasController`: el blanco es el `0`. */
    private function llave($aspiracion_id, $candidato_id): string
    {
        return ((int) $aspiracion_id).':'.($candidato_id === null ? '0' : (int) $candidato_id);
    }

    /** Con dos decimales, y cero cuando no hay de qué sacar el porcentaje. */
    private function porcentaje(int $parte, int $total): float
    {
        return $total > 0 ? round($parte * 100 / $total, 2) : 0.0;
    }
}
