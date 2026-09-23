<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\VtActa;
use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **Las actas de papel**: el recuento del grupo que votó sin pantalla.
 *
 * El caso es literal y es el que este controlador tiene que servir: una
 * coordinadora se lleva tres grupos de preescolar a otra sede, votan en papeletas,
 * ella las cuenta encima de una mesa y mete **los montones**, no las personas.
 *
 * ## LO PRIMERO, porque decide la forma de todos los endpoints de abajo
 *
 * > **Nunca una fila por alumno. Cantidades por cargo y candidato.**
 *
 * No es una simplificación: es que el dato no existe. De un montón de papeletas no
 * se puede sacar quién votó qué, y meterlas como filas de `vt_votos` con un usuario
 * inventado chocaría además con `vt_votos_un_voto_por_cargo` a la segunda papeleta
 * —y con razón—. Las dos formas se suman **al contar**, en
 * `VtResultadosController`, no antes.
 *
 * Efecto secundario bueno, y está en el docblock de `VtActa`: **el acta es más
 * secreta que la pantalla**. De un acta no se puede sacar a quién votó nadie.
 *
 * ## LAS CINCO RUTAS
 *
 *     GET     actas/{votacion}                  las actas de esa elección, con su estado
 *     GET     actas/{votacion}/grupo/{grupo}    el acta de ese grupo, o una en blanco
 *     PUT     actas/{votacion}/grupo/{grupo}    guarda las cantidades (borrador)
 *     POST    actas/{acta}/firmar               la deja firmada, y a partir de ahí inmutable
 *     DELETE  actas/{acta}                      sólo si NO está firmada
 *
 * Las cinco son **de personal**. Van con `auth.personal` en `routes/`, y además con
 * un `exigirPersonal()` aquí dentro — a propósito, y explicado en ese método.
 *
 * ## BORRADOR Y FIRMADA SON DOS ESTADOS, Y SÓLO UNO SE PUEDE TOCAR
 *
 * `firmada_en` nulo es el borrador. Un acta sin firmar **es un acta válida**, sólo
 * que provisional: exigir la firma para guardar dejaría al colegio con el recuento
 * en un papel encima de la mesa mientras busca al titular (700000).
 *
 * Firmada es **inmutable**: `PUT` contesta 409 y `DELETE` también. Y eso es todo lo
 * que hay — **anular un acta firmada no existe todavía**, y no se inventa aquí: es
 * otra operación, con otro permiso y con su propio rastro, y hacerla pasar por un
 * `DELETE` sería darle a cualquiera del personal la llave de deshacer una firma.
 * Queda dicho para que se decida, no para que se descubra.
 *
 * ## `conto_user_id` Y `firmada_por` SON DOS PERSONAS
 *
 * Quien metió los números y quien los avala. Este controlador escribe la primera en
 * cada `PUT` —el último que tecleó— y la segunda **una sola vez**, en `firmar`.
 *
 * ## EL TOPE POR CARGO, que es la única validación que no es de forma
 *
 * Por cada cargo, la suma de lo contado no puede pasar del número de estudiantes
 * del grupo: 422 nombrando el cargo que se pasó. El tope sale de
 * `VtActa::estudiantesDelGrupo()`, que **no** es el censo digital acotado —ver allí
 * las dos diferencias, y por qué usar `censo()` habría devuelto cero justo para los
 * grupos que tienen acta—.
 *
 * La base no comprueba esto y es deliberado (700000): *«un acta con más votos que
 * alumnos es un error de conteo que el colegio tiene que ver y corregir, no un
 * `INSERT` rechazado a medianoche»*. Aquí hay una pantalla delante y alguien que
 * puede volver a contar el montón, así que aquí sí.
 */
class VtActasController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Quién NO es personal del colegio.
     *
     * Repetido de `App\Http\Middleware\ExigirPersonal`, donde la lista es privada.
     * Se repite en vez de exportarla porque **ese fichero no es de este encargo** y
     * abrir una constante de un middleware para una ruta nueva es tocar la puerta de
     * los otros 74 endpoints que ya la usan.
     *
     * @var list<string>
     */
    private const NO_ES_PERSONAL = ['Alumno', 'Acudiente'];

    /** El tope de `vt_actas.observacion` que se acepta; la columna es `text`. */
    private const LARGO_OBSERVACION = 2000;

    /**
     * Las actas de una elección, con su estado.
     *
     * Devuelve además `grupos_sin_acta`, que es lo que la pantalla necesita para
     * ofrecer «contar este grupo» sin pedir el listado de grupos por su cuenta: son
     * **todos** los grupos vivos del año de la votación que todavía no tienen acta,
     * participen o no en la urna digital — quien vota en papel suele ser justo el
     * que está apartado de ella.
     */
    public function getIndex($votacion_id)
    {
        $this->exigirPersonal();

        $votacion = $this->votacionOMuere($votacion_id);

        $actas = DB::select('SELECT a.id, a.votacion_id, a.grupo_id, a.observacion,
                    a.conto_user_id, a.firmada_por, a.firmada_en, a.created_at, a.updated_at,
                    g.nombre AS grupo_nombre, g.abrev AS grupo_abrev, g.orden AS grupo_orden,
                    uc.username AS conto_username, uf.username AS firmante_username,
                    (SELECT COALESCE(SUM(av.cantidad), 0) FROM vt_acta_votos av WHERE av.acta_id = a.id) AS papeletas
                FROM vt_actas a
                INNER JOIN grupos g ON g.id = a.grupo_id
                LEFT JOIN users uc ON uc.id = a.conto_user_id
                LEFT JOIN users uf ON uf.id = a.firmada_por
                WHERE a.votacion_id = ?
             ORDER BY g.orden, g.nombre',
            [$votacion->id]);

        foreach ($actas as $acta) {
            $acta->estado = $acta->firmada_en === null ? 'borrador' : 'firmada';
            $acta->papeletas = (int) $acta->papeletas;
        }

        $sinActa = DB::select('SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id
                FROM grupos g
                WHERE g.year_id = ? AND g.deleted_at IS NULL
                  AND g.id NOT IN (SELECT a.grupo_id FROM vt_actas a WHERE a.votacion_id = ?)
             ORDER BY g.orden, g.nombre',
            [$votacion->year_id, $votacion->id]);

        return [
            'votacion' => ['id' => (int) $votacion->id, 'nombre' => $votacion->nombre, 'year_id' => $votacion->year_id],
            'actas' => $actas,
            'grupos_sin_acta' => $sinActa,
        ];
    }

    /**
     * El acta de un grupo, o **una en blanco lista para contar**.
     *
     * Y esa es la decisión de este endpoint: sin acta **no devuelve 404**, devuelve
     * los cargos y los candidatos con `cantidad` en cero y `acta: null`. La pantalla
     * de contar papeletas no puede pintarse a medias, y obligarla a pedir los cargos
     * por otro lado dejaría la papeleta de papel armándose en el front, que es donde
     * se pierde un candidato sin que nadie lo note.
     *
     * `estudiantes` viaja siempre: es el tope que el `PUT` va a exigir, y la pantalla
     * tiene que poder avisar **antes** de mandar, no después del 422.
     */
    public function getGrupo($votacion_id, $grupo_id)
    {
        $this->exigirPersonal();

        $votacion = $this->votacionOMuere($votacion_id);
        $grupo = $this->grupoOMuere($grupo_id, $votacion->year_id);

        $acta = $this->actaDelGrupo($votacion->id, $grupo->id);

        $contadas = [];

        if ($acta !== null) {
            foreach (DB::select('SELECT aspiracion_id, candidato_id, cantidad
                    FROM vt_acta_votos WHERE acta_id = ?', [$acta->id]) as $fila) {
                $contadas[$this->llave($fila->aspiracion_id, $fila->candidato_id)] = (int) $fila->cantidad;
            }
        }

        $cargos = [];

        foreach ($this->cargosDe($votacion->id) as $cargo) {
            $candidatos = [];

            foreach ($this->candidatosDe($cargo->id) as $candidato) {
                $candidato->cantidad = $contadas[$this->llave($cargo->id, $candidato->candidato_id)] ?? 0;
                $candidatos[] = $candidato;
            }

            $cargos[] = [
                'aspiracion_id' => (int) $cargo->id,
                'aspiracion' => $cargo->aspiracion,
                'abrev' => $cargo->abrev,
                'candidatos' => $candidatos,
                // El blanco no es un candidato: es `candidato_id` nulo. Va aparte y
                // no metido en la lista de arriba con un id falso, que es lo que
                // hacía el módulo viejo y lo que obligaba a la pantalla a
                // distinguirlo por el nombre.
                'blanco' => ['cantidad' => $contadas[$this->llave($cargo->id, null)] ?? 0],
            ];
        }

        return [
            'votacion' => ['id' => (int) $votacion->id, 'nombre' => $votacion->nombre, 'year_id' => $votacion->year_id],
            'grupo' => [
                'id' => (int) $grupo->id,
                'nombre' => $grupo->nombre,
                'abrev' => $grupo->abrev,
                'estudiantes' => VtActa::estudiantesDelGrupo($grupo->id, $votacion->year_id),
            ],
            'acta' => $acta === null ? null : $this->acta($acta),
            'cargos' => $cargos,
        ];
    }

    /**
     * Guarda las cantidades. **Siempre como borrador.**
     *
     * El cuerpo es una lista plana que se parece a la tabla, y eso es a propósito:
     *
     *     {
     *       "observacion": "Jardin voto en papel: no hay equipos en el aula",
     *       "votos": [
     *         {"aspiracion_id": 911, "candidato_id": 921,  "cantidad": 14},
     *         {"aspiracion_id": 911, "candidato_id": null, "cantidad": 3}
     *       ]
     *     }
     *
     * `candidato_id` nulo —o ausente— es el voto en blanco, la misma convención que
     * `vt_votos` desde el 22 sep 2026. Una forma y no dos.
     *
     * ## SE BORRA Y SE VUELVE A ESCRIBIR, dentro de una transacción
     *
     * Y no un `INSERT … ON DUPLICATE KEY UPDATE` fila a fila, aunque el único lo
     * permitiría: lo que manda la pantalla **es el recuento entero**, así que un
     * candidato que desaparece del cuerpo tiene que desaparecer de la tabla. Con
     * upsert habría que mandar además la lista de lo que ya no está, y esa es la
     * clase de contrato que se cumple mal una vez y deja un número viejo sumando
     * para siempre.
     *
     * Los `id` de `vt_acta_votos` cambian en cada guardado. No los mira nadie: son
     * cantidades de un borrador, no un libro.
     */
    public function putGrupo($votacion_id, $grupo_id)
    {
        $this->exigirPersonal();

        $votacion = $this->votacionOMuere($votacion_id);
        $grupo = $this->grupoOMuere($grupo_id, $votacion->year_id);

        $acta = $this->actaDelGrupo($votacion->id, $grupo->id);

        if ($acta !== null && $acta->firmada_en !== null) {
            return $this->conflicto('Esa acta ya está firmada; a partir de la firma no se puede editar.');
        }

        $estudiantes = VtActa::estudiantesDelGrupo($grupo->id, $votacion->year_id);
        $votos = $this->votosQueVienen($votacion->id, $estudiantes);
        $observacion = $this->observacionQueViene();

        $ahora = Reloj::ahoraTexto();

        DB::transaction(function () use ($votacion, $grupo, $acta, $votos, $observacion, $ahora) {
            if ($acta === null) {
                DB::insert('INSERT INTO vt_actas (votacion_id, grupo_id, conto_user_id, observacion, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?)',
                    [$votacion->id, $grupo->id, $this->user->user_id, $observacion, $ahora, $ahora]);

                $acta_id = (int) DB::getPdo()->lastInsertId();
            } else {
                $acta_id = (int) $acta->id;

                DB::update('UPDATE vt_actas SET conto_user_id = ?, observacion = ?, updated_at = ? WHERE id = ?',
                    [$this->user->user_id, $observacion, $ahora, $acta_id]);
            }

            DB::delete('DELETE FROM vt_acta_votos WHERE acta_id = ?', [$acta_id]);

            foreach ($votos as $voto) {
                DB::insert('INSERT INTO vt_acta_votos (acta_id, aspiracion_id, candidato_id, cantidad, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?)',
                    [$acta_id, $voto['aspiracion_id'], $voto['candidato_id'], $voto['cantidad'], $ahora, $ahora]);
            }
        });

        return $this->getGrupo($votacion->id, $grupo->id);
    }

    /**
     * Firma el acta. **Es el único camino de borrador a firmada, y no tiene vuelta.**
     *
     * Guarda `firmada_por` y `firmada_en` y con eso el acta queda cerrada: el `PUT` y
     * el `DELETE` contestan 409 desde ese momento.
     *
     * ## LO QUE NO SE VUELVE A COMPROBAR AL FIRMAR, dicho para que no se lea como un olvido
     *
     * **El tope por cargo no se revalida.** Se comprobó al guardar; si entre el
     * guardado y la firma matriculan a alguien menos en el grupo —una retirada—, el
     * tope baja y el acta que ya estaba bien pasaría a no poder firmarse **nunca**.
     * Las papeletas se contaron el día que se contaron; lo que cambió después no las
     * deshace.
     *
     * **Un acta vacía se puede firmar**, y también es a propósito: un grupo donde no
     * votó nadie es un hecho, y obligar a inventar una cantidad para poder cerrarlo
     * sería peor que registrarlo en cero.
     */
    public function postFirmar($acta_id)
    {
        $this->exigirPersonal();

        $acta = $this->actaOMuere($acta_id);

        if ($acta->firmada_en !== null) {
            return $this->conflicto('Esa acta ya estaba firmada.');
        }

        $ahora = Reloj::ahoraTexto();

        DB::update('UPDATE vt_actas SET firmada_por = ?, firmada_en = ?, updated_at = ? WHERE id = ?',
            [$this->user->user_id, $ahora, $ahora, $acta->id]);

        return $this->acta($this->actaOMuere($acta->id));
    }

    /**
     * Tira un acta **que no esté firmada**.
     *
     * `vt_acta_votos` se va detrás por la clave ajena `ON DELETE CASCADE`, que es lo
     * correcto aquí: las cantidades no son nada sin el acta de la que salieron.
     *
     * Firmada contesta 409 y **no hay forma de anularla**: ver la cabecera.
     */
    public function deleteDestroy($acta_id)
    {
        $this->exigirPersonal();

        $acta = $this->actaOMuere($acta_id);

        if ($acta->firmada_en !== null) {
            return $this->conflicto(
                'Esa acta está firmada y no se puede borrar. '.
                'Anular un acta firmada es otra operación y todavía no existe.'
            );
        }

        DB::delete('DELETE FROM vt_actas WHERE id = ?', [$acta->id]);

        return ['ok' => true, 'msg' => 'Acta eliminada'];
    }

    /*
     * ─────────────────────────────────────────────────────────────────────────
     *  De aquí para abajo, lo que no es un endpoint
     * ─────────────────────────────────────────────────────────────────────────
     */

    /**
     * Corta con 403 si quien pide no es personal del colegio.
     *
     * **Y sí, las rutas llevarán además `auth.personal`.** Está repetido a propósito:
     * el guard de la ruta lo escribe otra persona en otro fichero —`routes/` no es de
     * este encargo— y una ruta a la que se le olvide el middleware abriría el recuento
     * de papel a cualquier alumno con token. Es exactamente el fallo que documenta el
     * propio `ExigirPersonal` en su cabecera: cuatro controladores que **creían**
     * estar comprobando esto y no lo conseguían.
     *
     * El criterio es el mismo que el del middleware —«no es alumno ni acudiente»— y
     * **no** `is_superuser`: el colegio tiene secretarías y coordinaciones sin
     * superusuario, y son justo las que cuentan papeletas (decisión de Joseth, 18 ago
     * 2026, en `ExigirPersonal`).
     */
    private function exigirPersonal(): void
    {
        Autoriza::exigir(
            ! in_array($this->user->tipo ?? '', self::NO_ES_PERSONAL, true),
            'No tienes permiso'
        );
    }

    /** La elección, viva. 404 si no existe. */
    private function votacionOMuere($votacion_id)
    {
        $votacion = DB::selectOne('SELECT id, nombre, year_id, can_see_results
            FROM vt_votaciones WHERE id = ? AND deleted_at IS NULL', [$votacion_id]);

        if ($votacion === null) {
            abort(404, 'Esa votación no existe.');
        }

        return $votacion;
    }

    /**
     * El grupo, **y que sea del año de la votación**.
     *
     * El año es el de la elección y no el del usuario, por lo mismo que lo es en
     * `VtVotacion::censo()`: un profesor que se pasa a 2025 para mirar un boletín no
     * puede cambiar de qué grupos se cuentan papeletas en la elección de 2026.
     */
    private function grupoOMuere($grupo_id, $year_id)
    {
        $grupo = DB::selectOne('SELECT id, nombre, abrev, orden, grado_id, year_id
            FROM grupos WHERE id = ? AND year_id = ? AND deleted_at IS NULL', [$grupo_id, $year_id]);

        if ($grupo === null) {
            abort(404, 'Ese grupo no es del año de esta votación.');
        }

        return $grupo;
    }

    /** El acta por su id. 404 si no existe. */
    private function actaOMuere($acta_id)
    {
        $acta = DB::selectOne('SELECT * FROM vt_actas WHERE id = ?', [$acta_id]);

        if ($acta === null) {
            abort(404, 'Esa acta no existe.');
        }

        return $acta;
    }

    /** El acta de un grupo en una elección, o null. El único de la base garantiza que es una. */
    private function actaDelGrupo($votacion_id, $grupo_id)
    {
        return DB::selectOne('SELECT * FROM vt_actas WHERE votacion_id = ? AND grupo_id = ?',
            [$votacion_id, $grupo_id]);
    }

    /** Los cargos de la elección, en el orden en que se crearon. */
    private function cargosDe($votacion_id)
    {
        return DB::select('SELECT id, aspiracion, abrev FROM vt_aspiraciones
            WHERE votacion_id = ? AND deleted_at IS NULL ORDER BY id', [$votacion_id]);
    }

    /**
     * Los candidatos de un cargo, **para contar papeletas**.
     *
     * No se usa `VtCandidato::porAspiracion()`, y es una decisión medida: esa consulta
     * **une sólo con `alumnos` matriculados en el año**, así que un candidato cuyo
     * `user_id` no sea el de un alumno matriculado *desaparece de la lista en
     * silencio* (11 §1, y está escrito en el propio modelo). En la papeleta digital
     * eso ya es un fallo conocido; en un acta de papel sería peor todavía — el montón
     * de ese candidato existe, está encima de la mesa, y no habría dónde meterlo.
     *
     * Aquí se parte de `vt_candidatos` y el nombre se resuelve con `LEFT JOIN`: si no
     * hay ficha de alumno, el candidato sale igual con el `username` detrás.     *
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

    /**
     * Valida el cuerpo entero y lo devuelve normalizado.
     *
     * Cuatro cosas, y ninguna es de estilo:
     *
     *   1. **La forma**: lista de objetos con `aspiracion_id` y `cantidad`.
     *   2. **Que el cargo y el candidato sean de ESTA elección.** Sin esto, un
     *      `aspiracion_id` de otra votación entraría y el recuento de esa otra
     *      elección subiría desde aquí. Es la familia de la §8 de IDOR.
     *   3. **Que no venga el mismo par dos veces.** El único de la base lo rechazaría
     *      con un 1062 —incluido el blanco, desde la 800000—, pero eso llegaría a la
     *      pantalla como un 500 sin decir cuál. Aquí es un 422 que lo nombra.
     *   4. **El tope por cargo.**
     *
     * @return list<array{aspiracion_id:int, candidato_id:?int, cantidad:int}>
     */
    private function votosQueVienen($votacion_id, int $estudiantes): array
    {
        $crudos = Request::input('votos');

        if (! is_array($crudos)) {
            abort(422, 'Hay que mandar `votos` como una lista de cantidades.');
        }

        $cargos = [];

        foreach ($this->cargosDe($votacion_id) as $cargo) {
            $cargos[(int) $cargo->id] = $cargo;
        }

        $candidatos = [];

        foreach (DB::select('SELECT c.id, c.aspiracion_id FROM vt_candidatos c
                INNER JOIN vt_aspiraciones a ON a.id = c.aspiracion_id AND a.votacion_id = ?
                WHERE c.deleted_at IS NULL', [$votacion_id]) as $fila) {
            $candidatos[(int) $fila->id] = (int) $fila->aspiracion_id;
        }

        $votos = [];
        $vistos = [];
        $sumaDelCargo = [];

        foreach ($crudos as $crudo) {
            $crudo = is_object($crudo) ? (array) $crudo : $crudo;

            if (! is_array($crudo)) {
                abort(422, 'Cada cantidad tiene que venir como un objeto con `aspiracion_id`, `candidato_id` y `cantidad`.');
            }

            $aspiracion_id = $crudo['aspiracion_id'] ?? null;

            if (! is_numeric($aspiracion_id) || ! array_key_exists((int) $aspiracion_id, $cargos)) {
                abort(422, 'El cargo `'.(is_scalar($aspiracion_id) ? $aspiracion_id : '?').'` no es de esta votación.');
            }

            $aspiracion_id = (int) $aspiracion_id;

            /*
             * Ausente y nulo son lo mismo: **el voto en blanco**. Se aceptan los dos
             * porque una pantalla que no pinta candidato no tiene por qué mandar la
             * clave, y exigirla convertiría el blanco en el caso raro otra vez —que
             * es de lo que venimos, con `blanco_aspiracion_id`—.
             */
            $candidato_id = $crudo['candidato_id'] ?? null;

            if ($candidato_id !== null && $candidato_id !== '') {
                if (! is_numeric($candidato_id) || ($candidatos[(int) $candidato_id] ?? null) !== $aspiracion_id) {
                    abort(422, 'El candidato `'.(is_scalar($candidato_id) ? $candidato_id : '?').'` no se presenta a `'.$cargos[$aspiracion_id]->aspiracion.'`.');
                }

                $candidato_id = (int) $candidato_id;
            } else {
                $candidato_id = null;
            }

            $cantidad = $crudo['cantidad'] ?? null;

            if (! is_numeric($cantidad) || (int) $cantidad != $cantidad || (int) $cantidad < 0) {
                abort(422, 'La cantidad de `'.$cargos[$aspiracion_id]->aspiracion.'` tiene que ser un número entero de cero para arriba.');
            }

            $cantidad = (int) $cantidad;

            $llave = $this->llave($aspiracion_id, $candidato_id);

            if (isset($vistos[$llave])) {
                abort(422, $candidato_id === null
                    ? 'El voto en blanco de `'.$cargos[$aspiracion_id]->aspiracion.'` viene dos veces; tiene que ser una sola cantidad.'
                    : 'El candidato `'.$candidato_id.'` viene dos veces en `'.$cargos[$aspiracion_id]->aspiracion.'`.');
            }

            $vistos[$llave] = true;
            $sumaDelCargo[$aspiracion_id] = ($sumaDelCargo[$aspiracion_id] ?? 0) + $cantidad;

            $votos[] = [
                'aspiracion_id' => $aspiracion_id,
                'candidato_id' => $candidato_id,
                'cantidad' => $cantidad,
            ];
        }

        foreach ($sumaDelCargo as $aspiracion_id => $suma) {
            if ($suma > $estudiantes) {
                abort(422, 'En `'.$cargos[$aspiracion_id]->aspiracion.'` se contaron '.$suma.
                    ' papeletas y el grupo tiene '.$estudiantes.' estudiantes. Hay que volver a contar ese montón.');
            }
        }

        return $votos;
    }

    /** La observación, recortada. Vacía es `null` y no una cadena vacía. */
    private function observacionQueViene(): ?string
    {
        $observacion = Request::input('observacion');

        if (! is_string($observacion)) {
            return null;
        }

        $observacion = trim($observacion);

        if ($observacion === '') {
            return null;
        }

        if (mb_strlen($observacion) > self::LARGO_OBSERVACION) {
            abort(422, 'La observación no puede pasar de '.self::LARGO_OBSERVACION.' caracteres.');
        }

        return $observacion;
    }

    /** La cabecera del acta tal como la lee la pantalla. */
    private function acta($acta): array
    {
        return [
            'id' => (int) $acta->id,
            'votacion_id' => (int) $acta->votacion_id,
            'grupo_id' => (int) $acta->grupo_id,
            'estado' => $acta->firmada_en === null ? 'borrador' : 'firmada',
            'observacion' => $acta->observacion,
            'conto_user_id' => (int) $acta->conto_user_id,
            'firmada_por' => $acta->firmada_por === null ? null : (int) $acta->firmada_por,
            'firmada_en' => $acta->firmada_en,
            'created_at' => $acta->created_at,
            'updated_at' => $acta->updated_at,
        ];
    }

    /**
     * La llave de un par cargo/candidato, con el blanco dentro.
     *
     * El `0` del blanco es **el mismo criterio que el índice único** de la base desde
     * la 800000 —`COALESCE(candidato_id, 0)`—, y por eso lo que este método considera
     * repetido es exactamente lo que la base rechazaría. Escribirlo de otra forma aquí
     * dejaría un 422 y un 1062 discrepando.
     */
    private function llave($aspiracion_id, $candidato_id): string
    {
        return ((int) $aspiracion_id).':'.($candidato_id === null ? '0' : (int) $candidato_id);
    }

    /** 409 con el mismo cuerpo que el resto del repositorio. */
    private function conflicto(string $mensaje)
    {
        return response()->json(['ok' => false, 'msg' => $mensaje], 409);
    }
}
