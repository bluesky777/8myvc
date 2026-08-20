<?php

namespace App\Services;

use App\Models\Periodo;
use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Monta el objeto "usuario" que usa medio proyecto.
 *
 * No es el usuario de la tabla `users`: es persona + grupo + año + periodo +
 * la configuración del colegio + roles + permisos, todo aplanado en un objeto,
 * con un `switch` de cuatro ramas —Profesor, Alumno, Acudiente, Usuario— y
 * consultas de cuarenta columnas con seis JOIN. Cuesta de 5 a 8 consultas.
 *
 * **Esto vivía dentro de `User::fromToken()`, mezclado con la validación del
 * token.** Separarlo es lo que permite cambiar de mecanismo de autenticación
 * sin tocar los 325 sitios que llaman a `fromToken()`: el token lo valida
 * App\Services\Sesion y el contexto lo monta esto, y ninguno sabe del otro.
 *
 * El código se movió tal cual, solo reindentado. Las consultas no se tocaron:
 * los snapshots de tests/Contrato/Snapshots/login-contexto-*.json comprueban
 * que la forma del objeto no cambió para ninguno de los cuatro tipos.
 */
class ContextoDeUsuario
{
    /** Ver yaSeReintento(). */
    private const REINTENTO = 'usuario.contexto.reintento';

    /**
     * El contexto del usuario dado.
     *
     * Aborta con 400 cuando el usuario no da contexto —sin ficha, sin
     * matrícula, o inactivo—, con los mismos mensajes de siempre, que el
     * frontend distingue uno por uno.
     */
    public function para(User $userTemp)
    {
        if (! $userTemp->periodo_id) {
            $userTemp->periodo_id = Periodo::where('actual', '=', true)->first()->id;
            $userTemp->save();
        }

        $segundos = (int) config('rendimiento.contexto.segundos', 0);

        $usuario = $segundos > 0
            ? Cache::remember(static::clave($userTemp), $segundos, fn () => $this->construir($userTemp))
            : $this->construir($userTemp);

        // Fuera de la caché a propósito: es una estática de proceso, y una
        // petición servida desde la caché tiene que dejarla puesta igual. Lo
        // lee el cálculo de notas en 26 sitios, desde métodos que no reciben
        // usuario. Sacarla de ahí es tocar el cálculo de notas, que el §5 del
        // plan protege.
        User::$nota_minima_aceptada = $usuario->nota_minima_aceptada;

        return $usuario;
    }

    /**
     * La clave del contexto de una persona en un periodo.
     *
     * Lleva el nombre de la base dentro, y no por gusto: cada colegio tiene su
     * propia base pero comparte el servidor, y el día que `storage/` o el driver
     * de caché dejen de ser propios de cada uno —hoy lo son— una clave
     * `usuario.contexto.5` le serviría al usuario 5 de un colegio el contexto
     * del 5 de otro. Cuesta nada y quita esa clase entera de accidente.
     *
     * El periodo va en la clave porque al cambiar de año o de periodo el
     * contexto cambia entero: así se invalida solo, sin que nadie lo borre.
     */
    private static function clave(User $userTemp): string
    {
        return 'usuario.contexto.'.DB::connection()->getDatabaseName().'.'.$userTemp->id.'.'.$userTemp->periodo_id;
    }

    /**
     * Olvida el contexto de esa persona, para que la siguiente petición lo
     * vuelva a montar.
     *
     * Se llama al cambiarle los roles: los permisos viajan dentro del contexto
     * y `RolesController` decide con ellos, así que un permiso retirado tiene
     * que dejar de valer en el acto y no cuando caduque la caché.
     */
    public static function olvidar(User $usuario): void
    {
        Cache::forget(static::clave($usuario));
    }

    /** El contexto montado desde la base, sin pasar por la caché. */
    private function construir(User $userTemp)
    {
        $usuario = [];

        $consulta = '';
        $tipo_tmp = $userTemp->tipo;
        $is_super = $userTemp->is_superuser;

        switch ($tipo_tmp) {  // Alumno, Profesor, Acudiente, Usuario.
            case 'Profesor':

                $consulta = 'SELECT p.id as persona_id, p.nombres, p.apellidos, p.sexo, p.fecha_nac, p.ciudad_nac, p.user_id, u.username,
                                IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
                                p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
                                p.firma_id, i3.nombre as firma_nombre,
                                "N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo,
                                "N/A" as year_matricula_id, per.id as periodo_id, per.numero as numero_periodo, per.profes_pueden_editar_notas, per.profes_pueden_nivelar,
                                y.id as year_id, y.year, y.nota_minima_aceptada, y.actual as year_actual, per.actual as periodo_actual,
                                y.unidad_displayname, y.subunidad_displayname, y.unidades_displayname, y.subunidades_displayname, y.show_materias_todas,
                                y.genero_unidad, y.genero_subunidad, per.fecha_plazo, y.alumnos_can_see_notas, y.logo_id,
                                y.si_recupera_materia_recup_indicador, y.year_pasado_en_bol, y.mostrar_puesto_boletin, y.puestos_alfabeticamente, y.mostrar_nota_comport_boletin, y.profes_can_edit_alumnos,
                                y.compromiso_familiar_label
                            from profesores p
                            left join images i on i.id=:imagen_id
                            left join images i2 on i2.id=p.foto_id
                            left join images i3 on i3.id=p.firma_id
                            left join periodos per on per.id=:periodo_id
                            left join years y on y.id=per.year_id
                            left join users u on u.id=p.user_id
                            where p.deleted_at is null and p.user_id=:user_id';

                $usuario = DB::select($consulta, [
                    ':user_id' => $userTemp->id,
                    ':imagen_id' => $userTemp->imagen_id,
                    ':periodo_id' => $userTemp->periodo_id,
                ]);

                break;

            case 'Alumno':

                $consulta = 'SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id,
                                a.sexo, a.fecha_nac, a.ciudad_nac, a.pazysalvo, a.deuda, u.username, m.fecha_pension,
                                IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
                                a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
                                g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo,
                                g.year_id as year_matricula_id, per.id as periodo_id, per.numero as numero_periodo,
                                y.id as year_id, y.year, y.nota_minima_aceptada, y.actual as year_actual, per.actual as periodo_actual,
                                y.unidad_displayname, y.subunidad_displayname, y.unidades_displayname, y.subunidades_displayname,
                                y.genero_unidad, y.genero_subunidad, per.fecha_plazo, y.mostrar_nota_comport_boletin, y.si_recupera_materia_recup_indicador, y.year_pasado_en_bol, y.alumnos_can_see_notas, y.logo_id,
                                y.prematr_antiguos, y.msg_when_students_blocked, y.compromiso_familiar_label
                            from alumnos a
                            inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM")
                            inner join grupos g on g.id=m.grupo_id
                            left join images i on i.id=:imagen_id
                            left join images i2 on i2.id=a.foto_id
                            left join periodos per on per.id=:periodo_id
                            inner join years y on y.id=per.year_id and g.year_id=y.id
                            left join users u on u.id=a.user_id
                            where a.deleted_at is null and a.user_id=:user_id';

                $usuario = DB::select($consulta, [
                    ':user_id' => $userTemp->id,
                    ':imagen_id' => $userTemp->imagen_id,
                    ':periodo_id' => $userTemp->periodo_id,
                ]);

                break;

            case 'Acudiente':

                $consulta = 'SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, u.is_superuser,
                                ac.sexo, u.email, ac.fecha_nac, ac.ciudad_nac,
                                u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
                                ac.foto_id, IFNULL(i2.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
                                "N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo,
                                "N/A" as year_matricula_id, per.id as periodo_id, per.numero as numero_periodo,
                                y.id as year_id, y.year, y.nota_minima_aceptada, y.actual as year_actual, per.actual as periodo_actual,
                                y.unidad_displayname, y.subunidad_displayname, y.unidades_displayname, y.subunidades_displayname,
                                y.genero_unidad, y.genero_subunidad, per.fecha_plazo, y.si_recupera_materia_recup_indicador, y.mostrar_nota_comport_boletin, y.alumnos_can_see_notas, y.logo_id,
                                y.prematr_antiguos, y.compromiso_familiar_label
                            from acudientes ac
                            left join images i on i.id=:imagen_id
                            left join images i2 on i2.id=ac.foto_id
                            left join periodos per on per.id=:periodo_id
                            inner join years y on y.id=per.year_id
                            left join users u on u.id=ac.user_id
                            where ac.deleted_at is null and ac.user_id=:user_id';

                $usuario = DB::select($consulta, [
                    ':user_id' => $userTemp->id,
                    ':imagen_id' => $userTemp->imagen_id,
                    ':periodo_id' => $userTemp->periodo_id,
                ]);

                break;

            case 'Usuario':

                $consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.is_superuser, u.tipo,
                                u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, u.profesor_id,
                                u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
                                u.imagen_id as foto_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
                                "N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo,
                                "N/A" as year_matricula_id, per.id as periodo_id, per.numero as numero_periodo, per.profes_pueden_editar_notas, per.profes_pueden_nivelar,
                                y.id as year_id, y.year, y.nota_minima_aceptada, y.actual as year_actual, per.actual as periodo_actual,
                                y.unidad_displayname, y.subunidad_displayname, y.unidades_displayname, y.subunidades_displayname, y.show_materias_todas,
                                y.genero_unidad, y.genero_subunidad, per.fecha_plazo, y.si_recupera_materia_recup_indicador, y.year_pasado_en_bol, y.mostrar_nota_comport_boletin, y.alumnos_can_see_notas, y.logo_id,
                                y.puestos_alfabeticamente, y.compromiso_familiar_label
                            from users u
                            left join periodos per on per.id=u.periodo_id
                            left join years y on y.id=per.year_id
                            left join images i on i.id=u.imagen_id and i.deleted_at is null
                            where u.id=:user_id and u.deleted_at is null';

                $usuario = DB::select($consulta, [
                    ':user_id' => $userTemp->id,
                ]);

                break;

        }

        if (count($usuario) == 0) {
            if ($userTemp->is_active) {
                if ($this->yaSeReintento()) {
                    abort(400, 'user_inactivo_por_mucho_logueo');
                } else {
                    $this->marcarReintento();

                    $consulta = 'SELECT p.*
                        from alumnos a
                        inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM")
                        inner join grupos g on g.id=m.grupo_id and g.deleted_at is null
                        inner join years y on g.year_id=y.id and y.deleted_at is null
                        inner join periodos p on p.year_id=y.id and p.deleted_at is null
                        left join users u on u.id=a.user_id
                        where a.deleted_at is null and a.user_id=:user_id ORDER BY id DESC LIMIT 1';

                    $periodos = DB::select($consulta, [
                        ':user_id' => $userTemp->id,
                    ]);

                    if (count($periodos) > 0) {

                        $consulta = 'UPDATE users SET periodo_id=? WHERE id=?';
                        $periodos = DB::select($consulta, [$periodos[0]->id, $userTemp->id]);

                        // Con el periodo ya arreglado, volver a resolver.
                        // Antes se llamaba y se tiraba el resultado: quien
                        // entraba en esta rama recibía null. Un alumno con
                        // el periodo de otro año veía un 200 con el cuerpo
                        // vacío al entrar, y al segundo intento funcionaba
                        // —porque el UPDATE de arriba ya había corregido el
                        // periodo—, así que parecía cosa de una vez.
                        return $this->para($userTemp->fresh());
                    } else {
                        abort(400, 'user_inactivo_por_falta_periodos');
                    }

                }

            } else {
                abort(400, 'user_inactivo');
            }

        }

        $usuario = (array) $usuario[0];
        $userTemp = (array) $userTemp['attributes'];
        // return $userTemp;

        $usuario = array_merge($usuario, $userTemp);
        $usuario = (object) $usuario;

        if (! isset($usuario->tipo)) {
            $usuario->tipo = $tipo_tmp;
        }
        if (! isset($usuario->is_superuser)) {
            $usuario->is_superuser = $is_super;
        }

        // *************************************************
        //    Traeremos los roles y permisos
        // *************************************************

        $roles = DB::select('SELECT r.*
            FROM roles r
            INNER JOIN role_user rs ON r.id=rs.role_id
            WHERE rs.user_id=?', [$usuario->user_id]);

        $usuario->roles = $roles;

        // Los permisos de todos los roles en UNA consulta, no una por rol.
        // Era el paso 8 del plan de rendimiento: con `role_user` en 2.346 filas
        // hay usuarios con más de un rol, y cada uno sumaba una consulta a cada
        // petición que hicieran.
        //
        // La lista tiene que salir igual que salía, porque va en la respuesta
        // del login: se reagrupa por rol en el orden en que vienen los roles, y
        // dentro de cada rol por `permission_id`, que es el orden que devolvía
        // el bucle viejo —por el índice de `permission_role`, sin ORDER BY y sin
        // que nadie se lo hubiera pedido—. Aquí va escrito. Los repetidos se
        // conservan: un permiso que dan dos roles salía dos veces.
        $idsDeRol = array_column($usuario->roles, 'id');
        $perms = [];

        if ($idsDeRol !== []) {
            $marcas = implode(',', array_fill(0, count($idsDeRol), '?'));

            $filas = DB::select('SELECT pmr.role_id, pm.name from permission_role pmr
                    inner join permissions pm on pm.id = pmr.permission_id
                where pmr.role_id in ('.$marcas.')
                order by pmr.permission_id', $idsDeRol);

            $porRol = [];

            foreach ($filas as $fila) {
                $porRol[$fila->role_id][] = $fila->name;
            }

            foreach ($idsDeRol as $id) {
                foreach ($porRol[$id] ?? [] as $nombre) {
                    $perms[] = $nombre;
                }
            }
        }

        $usuario->perms = $perms;
        // `token` llevaba dentro el objeto Token de JWT, que al serializar sale
        // como {} porque no tiene ni una propiedad pública: el frontend lleva
        // años recibiendo un objeto vacío. Se mantiene la clave —está en los
        // snapshots y quitarla sería cambiar la forma sin ganar nada— pero ya
        // no se le mete nada.
        $usuario->token = new \stdClass;

        return $usuario;
    }

    /**
     * El guardia contra la recursión, atado a la petición y no a la clase.
     *
     * Cuando un usuario activo no da contexto, esto vuelve a intentarlo una vez
     * con el periodo corregido. La segunda vez tiene que rendirse, o se llama a
     * sí mismo sin fin.
     *
     * Era `User::$intentoLogueoPorActive`, una estática que se ponía a 1 y **no
     * la reiniciaba nadie**. Con PHP-FPM da igual, porque cada petición empieza
     * con el proceso limpio. Bajo Octane —que el plan contempla— el primer
     * usuario que pasara por aquí dejaría el 1 puesto para siempre, y a partir
     * de ahí TODOS recibirían 'user_inactivo_por_mucho_logueo' sin haber
     * reintentado nada.
     */
    private function yaSeReintento(): bool
    {
        return request()->attributes->get(self::REINTENTO, false) === true;
    }

    private function marcarReintento(): void
    {
        request()->attributes->set(self::REINTENTO, true);
    }
}
