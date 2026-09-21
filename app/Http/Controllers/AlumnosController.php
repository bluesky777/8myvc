<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Alumnos\GuardarAlumno;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Models\Alumno;
use App\Models\Ausencia;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Nota;
use App\Models\Periodo;
use App\Models\Role;
use App\Models\Unidad;
use App\Models\Year;
use App\Support\AlumnosParecidos;
use App\Support\Autoriza;
use App\Support\CamposQueVinieron;
use App\Support\CorreoDeLaCuenta;
use App\Support\DuplicadosDeAlumnos;
use App\Support\FusionDeAlumnos;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class AlumnosController extends Controller
{
    use ResuelveElUsuario;

    public function getIndex()
    {
        $previous_year = $this->user->year - 1;
        $id_previous_year = 0;
        $previous_year = Year::where('year', $previous_year)->first();

        if ($previous_year) {
            $id_previous_year = $previous_year->id;
        }

        $consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, a.pazysalvo, a.deuda,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_superuser, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:id_previous_year
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year_id and m.deleted_at is null )
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year2_id and m.deleted_at is null 
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is null';

        return DB::select($consulta, [
            ':id_previous_year' => $id_previous_year,
            ':year_id' => $this->user->year_id,
            ':year2_id' => $this->user->year_id,
        ]);
    }

    /**
     * Cambiar de una vez la contraseña de todos los alumnos de un grupo.
     *
     * **Pedía `auth.personal` y nada más**, o sea que cualquiera de los 51
     * profesores de la copia de producción podía reescribir las contraseñas de un
     * grupo entero con dos campos del cuerpo. Se cerró a superusuario por decisión
     * de Joseth el 23 ago 2026 (09 §cierre de la noche del 22 al 23).
     *
     * **No es una restricción nueva de hecho, sino de derecho: nadie pierde un
     * botón que hoy vea.** El único cliente que la llama es `myvc_front`, desde el
     * panel «Cambiar claves y usuarios» de la pantalla de Alumnos, que el menú
     * enseña con `hasRoleOrPerm(['admin', 'secretario'])`; y medido el 23 ago en la
     * copia de producción: **10 `is_superuser`, 10 con rol `Admin`, los mismos
     * diez, y cero `Secretario`** —el rol existe desde el 21 ago y no lo tiene
     * nadie—. Es la misma equivalencia de la §28.4 y el mismo razonamiento del
     * §97.
     *
     * Se ancla a `esAdministrativo` y no a `puedeEditarAlumnos` porque **editar la
     * ficha de un alumno y reescribir la contraseña de treinta son dos cosas
     * distintas**: lo segundo deja a un grupo entero fuera de su cuenta y no se
     * puede deshacer —el hash anterior no se guarda en ningún sitio—.
     *
     * **Y `esAdministrativo`, no `esSuperusuario`, desde el 24 ago 2026.** El
     * cierre del 23 la trajo desde `auth.personal` —cualquiera de los 51
     * profesores— y eligió el criterio más estrecho de los dos sin compararlo con
     * el de al lado. Comparados, salía al revés de lo razonable: esto alcanza a
     * **un grupo**, y las cuatro `cambiar-usuarios/*`, que alcanzan al **colegio
     * entero**, piden `esAdministrativo`, o sea menos. La operación pequeña pedía
     * más que la grande.
     *
     * Joseth lo resolvió **por alcance** (opción C, 24 ago): quien puede lo de un
     * grupo es el administrativo, y lo irreversible de 1.280 se reserva. Además
     * coincide con lo que ya había dicho el 21 ago —«puede cambiarle la
     * contraseña/username a los alumnos y acudientes solamente»—, que es
     * literalmente esto.
     *
     * Hoy no le da un botón a nadie que no lo tuviera: cero `Secretario` en la
     * base y los 10 `Admin` son los mismos 10 `is_superuser` (§28.4).
     */
    public function putCambiarClaves()
    {
        Autoriza::exigir(Autoriza::esAdministrativo($this->user),
            'No tienes permiso para cambiar las contraseñas de un grupo.');

        $clave = Request::input('clave');
        $grupo_id = Request::input('grupo_id');
        $clave = Hash::make($clave);

        // **`m.estado` y `u.deleted_at` faltaban, y no era una decisión.** Sin el
        // primero alcanzaba a los retirados que siguen colgando del grupo, y sin
        // el segundo a cuentas de la papelera. Que fue un descuido y no un
        // criterio lo dice el vecino: la masiva de colegio entero
        // —`CambiarUsuariosController:31`— sí lleva `u.deleted_at is null`, y
        // `alumnos/de-grupo` sí filtra MATR/ASIS. El docblock de arriba discute
        // A QUIÉN se le permite llamar y no dice ni una palabra sobre a quién
        // alcanza: se decidió el guard y no se miró la consulta.
        //
        // Lo vio la sesión de `myvc_flutter` el 24 ago comparando esta consulta
        // con la de `alumnos/de-grupo`, que es la que su pantalla usa para pintar
        // la lista sobre la que se aprieta este botón. Sin esto, la pantalla
        // enseña 30 alumnos y la operación toca 34.
        $consulta = 'UPDATE users u 
			INNER JOIN alumnos a ON a.user_id=u.id and a.deleted_at is null
			INNER JOIN matriculas m ON a.id=m.alumno_id and m.deleted_at is null
			SET u.password=:clave
			WHERE m.grupo_id=:grupo_id and m.estado in ("MATR","ASIS") and u.deleted_at is null';

        // `DB::update` y no `DB::select`: devuelve las filas tocadas, y la pantalla
        // necesita decir «cambiadas 31» en vez de un «Listo» a ciegas. Con
        // `DB::select` el número no existe y nadie puede comprobar el alcance de
        // una operación irreversible.
        $cambiadas = DB::update($consulta, [
            ':clave' => $clave,
            ':grupo_id' => $grupo_id,
        ]);

        // **Cambiar la forma aquí no rompe a nadie, comprobado en los dos clientes
        // que la llaman**: `myvc_front` hace `.then(() => toastr.success('Claves
        // cambiadas'))` con un texto fijo suyo y no mira el cuerpo
        // (`AlumnosCtrl.ts:454`), y `myvc_flutter` solo mira el código de estado
        // —su propio docblock anota «no devuelve cuántas cambió», que es justo lo
        // que se arregla aquí—. Se conserva la palabra por si algún colegio tiene
        // una copia vieja del front que sí la lea.
        return ['resultado' => 'Cambiadas', 'cambiadas' => $cambiadas];
    }

    public function getSinMatriculas()
    {
        $consulta = 'SELECT m.id as matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				g.year_id, m.grupo_id, g.nombre as nombre_grupo, g.abrev as abrevgrupo,
				a.foto_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
				m.estado 
			FROM alumnos a 
			INNER JOIN matriculas m on m.alumno_id=a.id and a.deleted_at is null and m.deleted_at is null 
			INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year_id and a.id=m.alumno_id and g.deleted_at is null
			LEFT JOIN images i on i.id=a.foto_id and i.deleted_at is null';

        return DB::select($consulta, [
            ':year_id' => $this->user->year_id,
        ]);
    }

    public function putDeGrupo($grupo_id)
    {
        $alumnos = DB::select('SELECT a.id, a.nombres, a.apellidos, a.sexo, m.estado,
						a.foto_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						m.estado  
					FROM alumnos a
					INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
					LEFT JOIN images i on i.id=a.foto_id and i.deleted_at is null
					WHERE a.deleted_at is null and m.grupo_id=?', [$grupo_id]);

        return ['alumnos' => $alumnos];
    }

    public function putYearsConNotas()
    {
        $alumno_id = Request::input('alumno_id');
        $res = [];

        $years = DB::select('SELECT distinct(y.id) as year_id, y.year FROM years y 
						INNER JOIN periodos p ON p.year_id=y.id and p.deleted_at is null
						INNER JOIN unidades u ON u.periodo_id=p.id and u.deleted_at is null
						INNER JOIN subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						INNER JOIN notas n ON n.alumno_id=? and n.subunidad_id=s.id and n.deleted_at is null
						WHERE y.deleted_at is null', [$alumno_id]);

        for ($i = 0; $i < count($years); $i++) {

            $grupos = DB::select('SELECT distinct(g.id) as grupo_id, g.abrev, g.nombre, g.year_id FROM grupos g  
							INNER JOIN asignaturas a ON a.grupo_id=g.id and a.deleted_at is null
							INNER JOIN unidades u ON u.asignatura_id=a.id and u.deleted_at is null
							INNER JOIN subunidades s ON s.unidad_id=u.id and s.deleted_at is null
							INNER JOIN notas n ON n.alumno_id=? and n.subunidad_id=s.id and n.deleted_at is null
							WHERE g.deleted_at is null and g.year_id=?', [$alumno_id, $years[$i]->year_id]);

            $years[$i]->grupos = $grupos;

            for ($j = 0; $j < count($years[$i]->grupos); $j++) {

                $periodos = DB::select('SELECT distinct(p.id), p.numero, p.year_id FROM periodos p  
								INNER JOIN unidades u ON u.periodo_id=p.id and u.deleted_at is null
								INNER JOIN subunidades s ON s.unidad_id=u.id and s.deleted_at is null
								INNER JOIN notas n ON n.alumno_id=? and n.subunidad_id=s.id and n.deleted_at is null
								WHERE p.deleted_at is null and p.year_id=?', [$alumno_id, $years[$i]->year_id]);

                $years[$i]->grupos[$j]->periodos = $periodos;

            }
            array_push($res, $years[$i]);
        }

        // Años para el destino de las notas
        $years_dest = DB::select('SELECT y.id as year_id, y.year, m.estado, m.created_at, m.updated_at, m.updated_by, g.id as grupo_id, g.abrev, g.nombre
						FROM years y 
						INNER JOIN grupos g ON g.year_id=y.id and g.deleted_at is null 
						INNER JOIN matriculas m ON m.grupo_id=g.id and m.alumno_id=? and m.deleted_at is null 
						WHERE y.deleted_at is null', [$alumno_id]);

        for ($i = 0; $i < count($years_dest); $i++) {

            $periodos = DB::select('SELECT p.id, p.numero, p.year_id FROM periodos p  
							WHERE p.deleted_at is null and p.year_id=?', [$years_dest[$i]->year_id]);

            $years_dest[$i]->periodos = $periodos;
        }

        return ['years' => $res, 'years_dest' => $years_dest];
    }

    public function checkOrChangeUsername($user_id)
    {

        $user = User::where('username', Request::input('username'))->first();
        // mientras el user exista iteramos y aumentamos i
        if ($user) {

            if ($user->id == $user_id) {
                return;
            }

            $username = $user->username;
            $i = 0;
            while (count((array) User::where('username', $username)->first()) > 0) {
                $i++;
                $username = $user->username.$i;
            }
            Request::merge(['username' => $username]);
        }

    }

    public function putEpsCheck()
    {
        $texto = Request::input('texto');
        $consulta = 'SELECT distinct eps FROM alumnos WHERE eps like :texto;';

        $res = DB::select($consulta, [':texto' => '%'.$texto.'%']);

        return ['eps' => $res];
    }

    public function postStore()
    {
        if (
            Autoriza::puedeEditarAlumnos($this->user)) {

            $alumno = [];

            try {
                $now = Carbon::parse(Request::input('fecha_matricula'));
                $this->sanarInputAlumno();

                $date = Carbon::createFromFormat('Y-m-d', Request::input('fecha_nac'));

                $alumno = new Alumno;
                $alumno->no_matricula = Request::input('no_matricula');
                $alumno->nombres = Request::input('nombres');
                $alumno->apellidos = Request::input('apellidos');
                $alumno->sexo = Request::input('sexo');
                // $alumno->user_id	=	Request::input('user_id');
                $alumno->fecha_nac = $date->format('Y-m-d');
                $alumno->ciudad_nac = Request::input('ciudad_nac');
                $alumno->tipo_doc = Request::input('tipo_doc');
                $alumno->documento = Request::input('documento');
                $alumno->ciudad_doc = Request::input('ciudad_doc');
                $alumno->tipo_sangre = Request::input('tipo_sangre')['sangre'];
                $alumno->eps = Request::input('eps');
                $alumno->telefono = Request::input('telefono');
                $alumno->celular = Request::input('celular');
                $alumno->barrio = Request::input('barrio');
                $alumno->estrato = Request::input('estrato');
                $alumno->ciudad_resid = Request::input('ciudad_resid');
                $alumno->religion = Request::input('religion');
                $alumno->email = Request::input('email');
                $alumno->facebook = Request::input('facebook');
                $alumno->pazysalvo = Request::input('pazysalvo');
                $alumno->deuda = Request::input('deuda');
                $alumno->updated_by = $this->user->user_id;
                $alumno->save();

                $this->sanarInputUser();

                $this->checkOrChangeUsername($alumno->user_id);

                $yearactual = Year::actual();
                $periodo_actual = Periodo::where('actual', true)
                    ->where('year_id', $yearactual->id)->first();

                if (! is_object($periodo_actual)) {
                    $periodo_actual = Periodo::where('year_id', $yearactual->id)->first();
                    $periodo_actual->actual = 1;
                    $periodo_actual->updated_by = $this->user->user_id;
                    $periodo_actual->save();
                }

                $usuario = new User;
                $usuario->username = Request::input('username');
                $usuario->password = Hash::make(Request::input('password', '123456'));
                // **Desde `email` y NO desde `email2`, que es lo que hace que la red de
                // `sanarInputUser` no pinte nada aquí.** Se deja como estaba —cambiarlo a
                // `email2` sería otra decisión— y lo que se le pone delante es la regla de
                // qué puede vivir en `users.email`: el alta de la aplicación VIEJA manda el
                // literal `'@gmail.com'` cuando no se teclea correo, y una cadena sin nada
                // delante de la arroba no es una dirección. 678 cuentas vivas la llevan.
                $usuario->email = CorreoDeLaCuenta::oNada(Request::input('email'));
                $usuario->sexo = Request::input('sexo');
                $usuario->is_superuser = Autoriza::concederSuperusuario($this->user, Request::input('is_superuser'));
                $usuario->periodo_id = $periodo_actual->id;
                $usuario->is_active = Request::input('is_active', 1);
                $usuario->tipo = 'Alumno';
                $usuario->updated_by = $this->user->user_id;
                $usuario->save();

                $role = Role::where('name', 'Alumno')->get();
                // $usuario->attachRole($role[0]);
                $usuario->roles()->attach($role[0]['id']);

                $alumno->user_id = $usuario->id;
                $alumno->save();

                $alumno->user = $usuario;

                $grupo_id = false;
                // `grupo.id` en vez de `Request::input('grupo')['id']`: indexar un
                // cuerpo que no trae el campo es un aviso de PHP, y Laravel arranca con
                // `error_reporting(-1)`, así que ese aviso es una excepción y el `catch`
                // la convierte en 422 **después de haber creado el alumno**. Un alta sin
                // grupo es un alumno sin matrícula, que es lo que dice `$grupo_id =
                // false`; no es un error que haya que esconder detrás de un 422. 05 §69.
                if (Request::input('grupo.id')) {
                    $grupo_id = Request::input('grupo.id');
                } elseif (Request::input('grupo_sig.id')) {
                    $grupo_id = Request::input('grupo_sig.id');
                }

                if ($grupo_id) {
                    $matricula = new Matricula;
                    $matricula->alumno_id = $alumno->id;
                    // El folio ya no se fabrica: `anio-alumno_id` no es la hoja de ningun libro
                    // (21 §2.2, y `Models\Matricula` lo explica entero). Se llena a mano.
                    $matricula->grupo_id = $grupo_id;
                    $matricula->nuevo = Request::input('nuevo');
                    $matricula->repitente = Request::input('repitente');
                    $matricula->created_by = $this->user->user_id;

                    if (Request::input('prematricula')) {
                        $matricula->estado = 'PREM';
                        $matricula->prematriculado = $now;
                    } elseif (Request::input('llevo_formulario')) {
                        $matricula->estado = 'FORM';
                    } else {
                        $matricula->estado = 'MATR';
                        $matricula->fecha_matricula = $now;
                    }

                    $matricula->save();

                    $grupo = Grupo::find((int) $matricula->grupo_id);
                    $alumno->grupo = $grupo;

                }

                return $alumno;

            } catch (\Exception $e) {
                abort(422, 'Datos incorrectos');
            }

        } else {
            return abort(400, 'No tiene permisos para editar');
        }
    }

    public function sanarInputAlumno()
    {
        if (is_array(Request::input('tipo_sangre'))) {
            if (! array_key_exists('sangre', Request::input('tipo_sangre'))) {
                Request::merge(['tipo_sangre' => ['sangre' => '']]);
            }
        } else {
            Request::merge(['tipo_sangre' => ['sangre' => '']]);
        }

        if (Request::has('ciudad_nac')) {
            if (Request::input('ciudad_nac')['id']) {
                Request::merge(['ciudad_nac' => Request::input('ciudad_nac')['id']]);
            } else {
                Request::merge(['ciudad_nac' => null]);
            }
        }

        if (Request::has('tipo_doc')) {
            if (Request::input('tipo_doc')['id']) {
                Request::merge(['tipo_doc' => Request::input('tipo_doc')['id']]);
            } else {
                Request::merge(['tipo_doc' => null]);
            }
        }

        if (Request::has('ciudad_doc')) {
            if (Request::input('ciudad_doc')['id']) {
                Request::merge(['ciudad_doc' => Request::input('ciudad_doc')['id']]);
            } else {
                Request::merge(['ciudad_doc' => null]);
            }
        }

        if (Request::has('foto')) {

            if (isset(Request::input('foto')['id'])) {
                Request::merge(['foto_id' => Request::input('foto')['id']]);
            } elseif (is_string(Request::input('foto'))) {
                Request::merge(['foto_id' => Request::input('foto')]);
            } else {
                Request::merge(['foto_id' => null]);
            }
        }

    }

    public function sanarInputUser()
    {
        /*
        //separamos el nombre de la img y la extensión
        $info = explode(".", $file->getClientOriginalName());
        $primer = $info[0];
        */

        if (! Request::input('username')) {
            if (Request::input('documento')) {
                Request::merge(['username' => Request::input('documento')]);
            } else {
                $dirtyName = Request::input('nombres');
                $name = preg_replace('/\s+/', '', $dirtyName);
                Request::merge(['username' => $name]);
            }
        }

        // **`email1` no existe: cero apariciones en los cuatro clientes**, comprobado
        // el 24 ago 2026. Así que esta rama corría SIEMPRE y pisaba el `email2` que
        // el cliente sí manda con el correo de la FICHA —dos columnas de dos tablas—
        // o con `usuario@myvc.com`.
        //
        // Y la guarda de arriba no lo paraba: `$vinieron->trae('email2')` contesta
        // *«¿vino la clave?»* —sí, vino— y no *«¿es éste el valor que vino?»*. La
        // §68.3 cerró el caso de que el campo NO viniera; éste es el de que venga y
        // llegue sustituido.
        //
        // **Y aquí estaba disparado**, al contrario que en el gemelo de profesores:
        // `AlumnosEditCtrl.ts:122` hace `$ctrl.alumno.email2 = alumno.user.email`, o
        // sea que la pantalla manda el correo de la cuenta de vuelta **y** el
        // `username` que abre el bloque. En cada guardado de una ficha de alumno.
        //
        // La condición correcta es la que `email1` quería decir: derivar un correo
        // **sólo si no hay ninguno**. Cambia una palabra, y el defecto del alta
        // —cuenta nueva sin correo— se conserva. Ver 05 §173.2 y
        // noche-2026-08-24/profes-1.md.
        // **Y el `else` que derivaba `username@myvc.com` se quitó el 20 sep 2026**,
        // decidido por Joseth: al crear a nadie se le inventa un correo, y quien no
        // tiene se queda sin él. El comentario de arriba defendía ese defecto
        // —«cuenta nueva sin correo»— y lo que se midió ese día es que el defecto
        // hacía daño, no bien.
        //
        // `users.email` es por donde busca la recuperación de contraseña
        // (`LoginController:240-266`, cuatro consultas y las cuatro sobre esa
        // columna). Un buzón que no existe hace que el método **encuentre** la
        // cuenta, mande el enlace y conteste «Enviado»: cambia «no llega» por «no
        // llega y además creemos que sí». Medido en el docker: **30 cuentas vivas
        // con `@myvc.com`, 16 de ellas activas** —11 profesores, 2 alumnos, 3 sin
        // ficha—, o sea que de los 12 profesores que el reseteo alcanza, **11 no
        // pueden recuperar nada**. Alcanzable no es recuperable.
        //
        // **Esas 30 se quedan como están**, decidido el mismo día y con los números
        // delante: está sabido, no es un olvido.
        //
        // Y esto cambia también la EDICIÓN, a sabiendas: si una pantalla manda la
        // clave `email2` vacía, antes se fabricaba uno y **se escribía encima del que
        // hubiera** —`$vinieron->trae('email2')` contesta que sí, porque la clave
        // vino—. Ahora ese caso guarda vacío, o sea que vaciar el campo se respeta.
        //
        // **Y no se deriva de cualquier cosa**: el alta de la aplicación vieja manda el
        // literal `'@gmail.com'` cuando no se teclea correo, que es una cadena no vacía
        // y por tanto pasaba este `if`. `CorreoDeLaCuenta` dice qué puede vivir en
        // `users.email` y por qué esa columna tiene regla y la ficha no.
        if (! Request::input('email2') && CorreoDeLaCuenta::oNada(Request::input('email')) !== null) {
            Request::merge(['email2' => CorreoDeLaCuenta::oNada(Request::input('email'))]);
        }
    }

    /**
     * La ficha de un alumno. **Es la hermana en detalle de la §34.**
     *
     * Devuelve documento, tipo de sangre, EPS, dirección, teléfono, religión,
     * sisbén, deuda y `nee`/`nee_descripcion` —las necesidades educativas
     * especiales—, y la ruta lleva solo `auth.token`. Tenía una rama para
     * acudientes que sí comprueba el vínculo y **un `else` que cubría a todos los
     * demás, incluido un alumno**, buscando por `a.id` sin mirar de quién es. Con
     * token de alumno y el id de otro respondía 200 con la ficha entera.
     *
     * **Por qué no lo cazó `persona.propia`**, que existe justo para esto: el
     * identificador aquí se llama **`id`**, y la lista de nombres que ese guard
     * reconoce es `alumno_id`, `user_id`, `persona_id`, `acudiente_id`,
     * `profesor_id`, `matricula_id`, `imagen_id`, `img_id`. Su propio docblock lo
     * había previsto —«comprobar solo la que uno espera deja abierta la que no»— y
     * aun así faltaba ésta. **`id` no se le puede añadir a esa lista**: media API
     * lo usa para cosas que no son personas —una unidad, una nota, un año— y el
     * guard intentaría resolverlas como si lo fueran. Por eso se cierra aquí.
     *
     * Ver 05 §41.
     */
    public function putShow()
    {
        $id = Request::input('id');
        $con_grupos = Request::input('con_grupos');

        if ($this->user->tipo === 'Alumno' && (int) $id !== (int) $this->user->persona_id) {
            abort(403, 'Solo puedes consultar lo tuyo');
        }

        if ($this->user->tipo == 'Acudiente') {

            $consulta = 'SELECT distinct(a.id) as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
								a.fecha_nac, a.tipo_doc, a.documento, a.tipo_sangre, a.eps, a.telefono, a.celular, 
								a.direccion, a.barrio, a.estrato, a.religion, u.email, a.facebook, a.created_by, a.updated_by,
								a.pazysalvo, a.deuda, 
								u.username, u.is_superuser, u.is_active,
								u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
								a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
								p.parentesco, p.observaciones, g.nombre as nombre_grupo, g.orden
							FROM alumnos a 
							inner join parentescos p on p.alumno_id=a.id and p.acudiente_id=?
							left join users u on a.user_id=u.id and u.deleted_at is null
							left join images i on i.id=u.imagen_id and i.deleted_at is null
							left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
							left join matriculas m on m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
							left join grupos g on g.id=m.grupo_id and g.deleted_at is null and g.year_id=?
							where a.deleted_at is null and p.deleted_at is null  and g.nombre is not null
							order by g.orden, a.apellidos, a.nombres';

            $alumnos = DB::select($consulta, [$this->user->persona_id, $this->user->year_id]);
            $encontrado = false;
            for ($i = 0; $i < count($alumnos); $i++) {
                if ($alumnos[$i]->alumno_id == $id) {
                    $encontrado = true;
                }
            }
            if (! $encontrado) {
                return response()->json(['autorizado' => false, 'msg' => 'No es tu acudido'], 400);
            }
        }

        $consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, g.nombre as grupo_nombre, g.abrev as grupo_abrev, 
				a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, a.deleted_at,
				c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, a.egresado,
				a.direccion, a.barrio, a.is_urbana, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, c3.departamento as departamento_resid_nombre, a.religion, u.email, a.facebook, a.created_by, a.updated_by,
				a.pazysalvo, a.deuda, m.grupo_id, a.is_urbana, IF(a.is_urbana, "SI", "NO") as es_urbana,
				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				u.username, u.is_active, a.nee, a.nee_descripcion,
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
				m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, IF(m.nuevo, "SI", "NO") as es_nuevo, m.repitente, m.fecha_pension,
				a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3, m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada 
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
			INNER JOIN grupos g ON g.id=m.grupo_id AND g.year_id=:year_id
			left join users u on a.user_id=u.id and u.deleted_at is null
			left join images i on i.id=u.imagen_id and i.deleted_at is null
			left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
			left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
			left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
			where '.Matricula::FILTRO_DEL_ANIO.'
			order by '.Matricula::ORDEN_DEL_ANIO.'
			limit 1';
        // he quitado el      a.deleted_at is null

        /*
         * **Cuál es «la matrícula del año» lo dice `Matricula`, y ahora lo dice para
         * los dos lados.** §9.5 del 19-boletin-independiente.md.
         *
         * Aquí estaba `order by a.apellidos, a.nombres` y **eso no desempataba
         * nada**: la consulta es de UN alumno, así que ordenar por su apellido y su
         * nombre es un empate total y `[0]` era «la primera que devuelva MySQL» —en
         * la práctica, la de id más bajo—. `GuardarAlumno::valor` se quedaba con
         * `[0]` de otra consulta que ni siquiera filtraba `deleted_at`. Con dos
         * matrículas vivas del mismo año se leía de una y se escribía en otra, y las
         * columnas que lo sufren son `repitente`, `promovido` y `nro_folio`.
         *
         * **La regla la dicta `Matricula::matricularUno()`, no una preferencia:** es
         * el único sitio que crea matrículas, y cuando encuentra varias del mismo año
         * **activa una y borra las demás**. El sistema ya promete «una viva por año»;
         * lo que faltaba es que los dos lados leyeran esa promesa igual **cuando la
         * promesa no se cumple**. Entre dos vivas gana la posterior porque una segunda
         * fila sólo aparece si alguien volvió a matricular.
         *
         * **Lo que esto le cambia a un alumno de verdad**, y va escrito porque es el
         * efecto visible del arreglo: en la copia de `simonbolivar` medida el 1 sep
         * 2026 hay **3.578** pares (alumno, año) con matrícula viva y **uno solo con
         * dos** —el alumno 1097 en el año 7—, y sus dos filas tienen `promovido` y
         * `nro_folio` distintos. **Su ficha pasa a enseñar el otro par de valores.**
         * Decisión de Joseth del 1 sep 2026, tomada con esa cifra delante.
         *
         * **Un colegio, no quince**: lo medido es la copia que tenemos delante, y en
         * los otros catorce puede haber más pares o ninguno.
         *
         * El `g.deleted_at` se sube del `JOIN` al `WHERE` para que la regla entera
         * quepa en `FILTRO_DEL_ANIO` y se lea de un tirón. Con un `INNER JOIN` las dos
         * formas seleccionan lo mismo.
         *
         * **`bol_independiente_periodos` NO se cuelga de esta fila**, ni de ninguna
         * matrícula: sale del año del token y de `bol_ind_periodos`, y por eso viaja
         * también por la rama del alumno **sin** matrícula del año (§6.4, lote D).
         * Colgarlo de aquí devolvería a `undefined` sus dos significados —«no
         * matriculado» y «desmarcado»— que costaron semanas de distinguir.
         */

        // \Log::info('Año '.$this->user->year_id);
        $alumno = DB::select($consulta, [':alumno_id' => $id, ':year_id' => $this->user->year_id]);

        if (count($alumno) > 0) {

            $alumno = $alumno[0];

            return $this->comprobar_alumno_con_grupos($alumno, $con_grupos);

        } else {

            $consulta = 'SELECT a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
					a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, a.deleted_at,
					c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, a.egresado,
					a.direccion, a.barrio, a.is_urbana, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, c3.departamento as departamento_resid_nombre, a.religion, u.email, a.facebook, a.created_by, a.updated_by,
					a.pazysalvo, a.deuda, a.is_urbana, IF(a.is_urbana, "SI", "NO") as es_urbana,
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.username, u.is_active, a.nee, a.nee_descripcion,
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
					a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3
				FROM alumnos a 
				left join users u on a.user_id=u.id and u.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
				left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
				left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
				where a.id=:alumno_id
				order by a.apellidos, a.nombres';
            // he quitado el      a.deleted_at is null

            $alumno = DB::select($consulta, [':alumno_id' => $id]);

            if (count($alumno) > 0) {

                $alumno = $alumno[0];

                return $this->comprobar_alumno_con_grupos($alumno, $con_grupos);

            } else {
                return ['pailas' => 'nada'];
            }
        }

    }

    public function comprobar_alumno_con_grupos($alumno, $con_grupos)
    {
        $grados = [];
        $grados_sig = [];
        $tipos_doc = [];

        $consulta = 'SELECT y.id, y.id as year_id, y.year, y.actual FROM years y WHERE y.deleted_at is null ORDER BY y.year desc limit 1';
        $year_ult = DB::select($consulta)[0];

        // Las columnas de `matriculas` van nombradas y NO se vuelve a `m.*`. Lo trajo
        // `matriculas.boletin_independiente`, que con `*` habría entrado en esta
        // respuesta sin que ninguna instantánea lo cazara — aquí no hay.
        //
        // **Esa columna se retiró el 31 ago 2026** (§2.2 del 19-boletin-independiente.md:
        // la marca pasó a ser por periodo y dos columnas que pueden discrepar acaban
        // discrepando), y **quitarla no movió nada precisamente porque esta lista no la
        // incluía**: el trabajo defensivo del 24 ago se cobró en la dirección contraria
        // a la que se hizo. La regla no caduca con la columna — la próxima que se añada
        // a `matriculas` entra por `*` igual de callada. La lista sale del esquema
        // congelado, no escrita a mano. §5.ter de noche-2026-08-24/bi-1.md.
        $consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, g.nombre as grupo_nombre, g.abrev as grupo_abrev, 
				m.id, m.alumno_id, m.grupo_id, m.estado, m.prematriculado, m.fecha_retiro, m.fecha_matricula, m.fecha_pension, m.razon_retiro, m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada, m.profes_editar_notas, m.nuevo, m.repitente, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas, m.anios_in_cole, m.nro_folio, m.created_by, m.updated_by, m.deleted_by, m.deleted_at, m.created_at, m.updated_at, y.year, g.year_id, m.estado
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
			INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at is null
			INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null
			where a.deleted_at is null and m.deleted_at is null
			order by y.year desc, g.orden';

        $matriculas = DB::select($consulta, [':alumno_id' => $alumno->alumno_id]);

        // Requisitos de cada año
        for ($i = 0; $i < count($matriculas); $i++) {

            // Verifico si el último año, está en las matrículas de este alumno
            if ($year_ult->id == $matriculas[$i]->year_id) {
                $year_ult->entrado = true;
            }

            $matriculas[$i]->requisitos = $this->traer_requisitos_detalle($alumno->alumno_id, $matriculas[$i]);
        }

        if (! isset($year_ult->entrado)) {
            $year_ult->requisitos = $this->traer_requisitos_detalle($alumno->alumno_id, $year_ult);
            array_unshift($matriculas, $year_ult);
        }

        // Matrícula del siguiente año
        $consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, g.nombre as grupo_nombre, g.abrev as grupo_abrev, m.grupo_id, m.estado, m.nuevo, m.repitente, m.prematriculado, m.fecha_matricula, y.id as year_id, y.year as year,
				m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada 
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
			INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at is null
			INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:anio
			where a.deleted_at is null and m.deleted_at is null
			order by y.year, g.orden';

        $matri_next = DB::select($consulta, [':alumno_id' => $alumno->alumno_id, ':anio' => ($this->user->year + 1)]);

        $alumno->next_year = [];
        if (count($matri_next) > 0) {
            $alumno->next_year = $matri_next[0];
        }

        $alumno->bol_independiente_periodos = $this->bolIndependientePeriodos((int) $alumno->alumno_id);

        if ($con_grupos) {
            // Grupos actuales
            $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
					p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
					g.created_at, g.updated_at, gra.nombre as nombre_grado
				from grupos g
				inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
				left join profesores p on p.id=g.titular_id
				where g.deleted_at is null
				order by g.orden';

            $grados = DB::select($consulta, [':year_id' => $this->user->year_id]);

            // Grupos próximo año
            $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at
				from grupos g
				inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
				where g.deleted_at is null order by g.orden';

            $grados_sig = DB::select($consulta, [':anio' => ($this->user->year + 1)]);

            // Tipos documentos
            $consulta = 'SELECT * from tipos_documentos where deleted_at is null';
            $tipos_doc = DB::select($consulta);
        }

        return ['alumno' => $alumno, 'grupos' => $grados, 'grupos_siguientes' => $grados_sig,
            'tipos_doc' => $tipos_doc, 'matriculas' => $matriculas];
    }

    /**
     * Por dónde la ficha lee la marca del boletín independiente: **los CUATRO
     * periodos del año, siempre**, no sólo las filas que existan.
     *
     * §6.4 de [19-boletin-independiente.md](../../../docs/migracion/19-boletin-independiente.md).
     *
     * ```jsonc
     * [{ "periodo_id": 91, "numero": 1, "aplica": false, "tiene_datos": false }, ...]
     * ```
     *
     * ## Mandar los cuatro no es cosmética
     *
     * Una lista con sólo las filas presentes en `bol_ind_periodos` obliga al cliente a
     * decidir qué significa una ausencia, y **este módulo perdió una semana justamente
     * por leer una ausencia al revés** —el `COALESCE(bip.aplica, 1)` que hacía que
     * marcar a un alumno en octubre le repintara el boletín del primer periodo—.
     * Mandando los cuatro no hay default que inventar en el navegador.
     *
     * ## Los cuatro estados son `aplica` × `tiene_datos`, y los cuatro dicen algo
     *
     * El que la pantalla tiene que gritar es **`aplica` sin `tiene_datos`**: va aparte
     * y no tiene ni una unidad propia, o sea la §9.1 —su definitiva va a salir 0 y
     * nadie va a recibir un error—. Y el contrario, `tiene_datos` sin `aplica`, es
     * literalmente lo que se pidió: *«no debe borrar los datos … pero esos datos deben
     * ser ignorados»*.
     *
     * ## `tiene_datos` lo contesta el backend porque el navegador no puede
     *
     * Es un `EXISTS` por periodo, y desde la ficha serían **cuatro peticiones** para
     * pintar una pestaña. El `EXISTS` va correlacionado dentro de la consulta de
     * periodos para que sean cuatro búsquedas por índice y no cuatro viajes.
     *
     * **El índice que sirve NO es `unidades_alcance_index`.** Aquél es
     * `(asignatura_id, periodo_id, alumno_id)` y aquí se pregunta por
     * `(alumno_id, periodo_id)`, así que su columna izquierda no aparece: lo resuelve
     * `unidades_alumno_id_foreign`, el índice que la clave foránea de `alumno_id`
     * arrastra consigo. Comprobado con `EXPLAIN`: `ref` sobre ese índice, y **cero
     * filas** en el caso de todo el mundo hoy —nadie marcado, ninguna unidad con
     * dueño—, que es el que se ejecuta en cada apertura de ficha de los quince colegios.
     *
     * ## El campo va en las DOS ramas de `putShow`, y eso cierra una trampa del plan
     *
     * La §6.4 avisaba de que si el alumno no tiene matrícula del año, `putShow` cae a
     * la segunda consulta y el campo no vendría: `undefined` significando «no
     * matriculado» y no «desmarcado». Con la marca colgada de `(alumno_id, periodo_id)`
     * **el campo ya no depende de la matrícula** —los periodos salen del año del token
     * y el estado de `bol_ind_periodos`—, así que sale por las dos ramas y `undefined`
     * deja de ser ambiguo.
     *
     * @return list<array{periodo_id: int, numero: int, aplica: bool, tiene_datos: bool}>
     */
    private function bolIndependientePeriodos(int $alumno_id): array
    {
        // `COALESCE(bip.aplica, 0)`: **la fila que falta significa «va con el grupo»**.
        // Es la decisión 7 y es el mismo carácter que gobierna
        // `BoletinIndependiente::ALCANCE`; si los dos dejaran de decir lo mismo, la
        // ficha enseñaría un estado y el boletín imprimiría el otro.
        //
        // El `EXISTS` es **el mismo predicado** que el de `Grupo::alumnos` para
        // `bol_independiente_datos`, que es ese campo aplanado al periodo del token. Se
        // escriben dos veces porque las dos preguntas tienen forma distinta —aquí,
        // cuatro periodos de un alumno; allí, treinta alumnos de un periodo— y
        // `BolIndependientePeriodosTest` comprueba que **coinciden**, que es una
        // garantía más fuerte que compartir una cadena.
        $consulta = 'SELECT p.id AS periodo_id, p.numero,
				IF(COALESCE(bip.aplica, 0) = 1, 1, 0) AS aplica,
				EXISTS (SELECT 1 FROM unidades u
						 WHERE u.alumno_id = ? AND u.periodo_id = p.id AND u.deleted_at IS NULL) AS tiene_datos
			FROM periodos p
			LEFT JOIN bol_ind_periodos bip ON bip.periodo_id = p.id AND bip.alumno_id = ?
			WHERE p.year_id = ? AND p.deleted_at IS NULL
			ORDER BY p.numero, p.id';

        $filas = DB::select($consulta, [$alumno_id, $alumno_id, $this->user->year_id]);

        // A `bool` y a `int` en PHP: MySQL devuelve los dos como 0/1 y PDO los trae como
        // cadena. Un `"0"` es verdadero en JavaScript, así que dejarlo pasar es
        // exactamente el fallo que este campo existe para no tener.
        return array_values(array_map(static fn ($f) => [
            'periodo_id' => (int) $f->periodo_id,
            'numero' => (int) $f->numero,
            'aplica' => (bool) $f->aplica,
            'tiene_datos' => (bool) $f->tiene_datos,
        ], $filas));
    }

    public function traer_requisitos_detalle($alumno_id, $matricula)
    {

        // Traemos los requisitos de cada año y su detalle si ya lo tiene
        $consulta_requisitos = 'SELECT m.*, m.descripcion as descripcion_titulo FROM requisitos_matricula m
				WHERE m.year_id=?';

        $requisitos_year = DB::select($consulta_requisitos, [$matricula->year_id]);

        $consulta_requisitos = 'SELECT m.*, m.descripcion as descripcion_titulo, a.id as requisito_alumno_id, a.estado, a.descripcion FROM requisitos_matricula m
				LEFT JOIN requisitos_alumno a ON a.requisito_id=m.id
				WHERE m.year_id=? and a.alumno_id='.$alumno_id;

        $requisitos_alumno = DB::select($consulta_requisitos, [$matricula->year_id]);

        $now = Carbon::parse(Request::input('fecha_matricula'));

        for ($j = 0; $j < count($requisitos_year); $j++) {
            $requi_year = $requisitos_year[$j];
            $found = false;

            for ($k = 0; $k < count($requisitos_alumno); $k++) {

                if ($requi_year->id == $requisitos_alumno[$k]->id) {
                    $found = true;
                }
            }

            if (! $found) {
                $consulta = 'INSERT INTO requisitos_alumno(alumno_id, requisito_id, estado, created_at) 
						VALUES(?, ?, "falta", ?)';

                DB::insert($consulta, [$alumno_id, $requisitos_year[$j]->id, $now]);

            }
        }

        // Ejecutamos otra vez para traer con los nuevos requisitos_alumnos ingresados
        $requisitos_year = DB::select($consulta_requisitos, [$matricula->year_id]);

        return $requisitos_year;
    }

    public function putUpdate($id)
    {
        if (Autoriza::puedeEditarAlumnos($this->user)) {

            $alumno = Alumno::findOrFail($id);

            // ANTES del primer `sanar*`, que hace `Request::merge()`: después,
            // `Request::has()` ya no distingue lo que mandó el cliente. 05 §68.
            $vinieron = CamposQueVinieron::capturar();

            $this->sanarInputAlumno();

            try {
                $alumno->no_matricula = Request::input('no_matricula');
                $alumno->nombres = Request::input('nombres');
                $alumno->apellidos = Request::input('apellidos');
                $alumno->sexo = Request::input('sexo', 'M');
                $alumno->fecha_nac = Request::input('fecha_nac');
                // Sin `['id']`, y no es cosmético: `sanarInputAlumno` ya convirtió los
                // tres de `{id: N}` al número. Volver a indexar era indexar un entero
                // —o un null—, y Laravel arranca con `error_reporting(-1)`, así que ese
                // aviso de PHP se convierte en excepción, la caza el `catch` de abajo y
                // **la ficha entera contestaba 422 «Datos incorrectos» sin guardar
                // nada**. La hermana de al lado, `postStore`, lee estos mismos tres
                // campos sin `['id']` desde siempre: la asimetría entre hermanas es lo
                // que lo señaló. 05 §69.
                $alumno->ciudad_nac = Request::input('ciudad_nac');
                $alumno->tipo_doc = Request::input('tipo_doc');
                $alumno->documento = Request::input('documento');
                $alumno->ciudad_doc = Request::input('ciudad_doc');
                $alumno->tipo_sangre = Request::input('tipo_sangre')['sangre'];
                $alumno->eps = Request::input('eps');
                $alumno->telefono = Request::input('telefono');
                $alumno->celular = Request::input('celular');
                $alumno->barrio = Request::input('barrio');
                $alumno->estrato = Request::input('estrato');
                $alumno->ciudad_resid = Request::input('ciudad_resid');
                $alumno->religion = Request::input('religion');
                $alumno->email = Request::input('email');
                $alumno->facebook = Request::input('facebook');
                $alumno->foto_id = Request::input('foto_id');
                $alumno->pazysalvo = Request::input('pazysalvo', true);
                $alumno->deuda = Request::input('deuda');

                if ($alumno->user_id and Request::has('username')) {

                    $this->sanarInputUser();
                    $this->checkOrChangeUsername($alumno->user_id);

                    $usuario = User::find($alumno->user_id);
                    $usuario->username = Request::input('username');
                    $usuario->is_superuser = 0;
                    $usuario->updated_by = $this->user->user_id;

                    // Cuenta que ya existe: lo que el cuerpo no trae, no se toca. Ver el
                    // gemelo en ProfesoresController y 05 §68.1.
                    if ($vinieron->trae('is_active')) {
                        $usuario->is_active = (int) Request::boolean('is_active');
                    }

                    // `sanarInputUser` regenera `email2` desde el correo de la PERSONA
                    // cuando no viene `email1` —que no lo manda nadie—, así que sin esta
                    // guarda el correo de la cuenta se mudaba de columna. 05 §68.3.
                    if ($vinieron->trae('email2')) {
                        $usuario->email = CorreoDeLaCuenta::oNada(Request::input('email2'));
                    }

                    // La condición estaba invertida: escribía la contraseña **sólo si
                    // venía vacía**, o sea que teclear una de verdad no hacía nada y
                    // borrar la casilla dejaba la cuenta con el hash de la cadena vacía
                    // —y entrar con la contraseña vacía responde 200, que es la §26—.
                    // La casilla existe y es alcanzable: `alumnosEdit.html:229` la ata a
                    // `$ctrl.alumno`, que es el objeto entero que se manda.
                    // `filled` es las dos cosas a la vez: si no viene, no se toca; si
                    // viene vacía, tampoco. 05 §68.2.1.
                    if (Request::filled('password')) {
                        $usuario->password = Hash::make(Request::input('password'));
                    }

                    $usuario->save();

                    $alumno->user_id = $usuario->id;
                    $alumno->updated_by = $this->user->user_id;

                    $alumno->save();

                    $alumno->user = $usuario;
                }

                if (! $alumno->user_id and Request::has('username')) {

                    $this->sanarInputUser();
                    $this->checkOrChangeUsername($alumno->user_id);

                    $yearactual = Year::actual();
                    $periodo_actual = Periodo::where('actual', true)
                        ->where('year_id', $yearactual->id)->first();

                    $usuario = new User;
                    $usuario->username = Request::input('username');
                    $usuario->password = Hash::make(Request::input('password', '123456'));
                    $usuario->email = CorreoDeLaCuenta::oNada(Request::input('email2'));
                    $usuario->is_superuser = 0;
                    $usuario->is_active = Request::input('is_active', 1);
                    $usuario->periodo_id = $periodo_actual->id;
                    $usuario->created_by = $this->user->user_id;
                    $usuario->save();

                    $alumno->user_id = $usuario->id;

                    $alumno->save();

                    $alumno->user = $usuario;
                }

                // El desplegable de grupo de la ficha sólo pone `grupo` en el cuerpo
                // cuando alguien lo toca —`putShow` no devuelve ese objeto—, así que en
                // el guardado normal esto indexaba un null y tiraba el 422 **después**
                // de haber escrito ya la ficha y la cuenta: guardaba y decía que no.
                // 05 §69.
                if (Request::input('grupo.id')) {

                    $grupo_id = Request::input('grupo.id');

                    $matricula = Matricula::matricularUno($alumno->id, $grupo_id, false, $this->user->user_id);

                    $grupo = Grupo::find((int) $matricula->grupo_id);
                    $alumno->grupo = $grupo;
                }

                return $alumno;
            } catch (\Exception $e) {
                abort(422, 'Datos incorrectos');
            }
        } else {
            // El mensaje decía «eliminar alumnos definitivamente», copiado del
            // `forcedelete` de más abajo. Esta ruta EDITA, y quien lea el aviso o el
            // log de un colegio creería que alguien intentó borrar a un alumno. El
            // criterio que se comprueba arriba es `puedeEditarAlumnos`, no
            // `puedeBorrarAlumnos`. Salió del barrido de cobertura del 21 ago 2026,
            // que fue el primero que leyó lo que responde esta ruta. Ver 05 §54.
            return abort(403, 'No tienes permiso para editar alumnos.');
        }
    }

    /*************************************************************
     * Guardar por VALOR
     *************************************************************/
    public function putGuardarValor()
    {
        $year_id = Request::input('year_id', $this->user->year_id);

        if ($this->user->tipo == 'Acudiente') {
            return response()->json(['autorizado' => false, 'msg' => 'No puedes cambiar a un alumno'], 400);
        }

        if ($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) {
            $consulta = 'SELECT a.id, a.user_id, g.id as grupo_id, g.titular_id, m.id as matricula_id FROM alumnos a
							INNER JOIN matriculas m ON m.alumno_id=a.id
							INNER JOIN grupos g ON g.id=m.grupo_id AND g.year_id=? AND g.titular_id=?
							WHERE a.id=?';
            $alumno = DB::select($consulta, [$year_id, $this->user->persona_id, Request::input('alumno_id')]);

            if (count($alumno) > 0) {
                $alumno = $alumno[0];
                $guardarAlumno = new GuardarAlumno;

                return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));
            } else {
                return response()->json(['autorizado' => false, 'msg' => 'No eres el titular'], 400);
            }

        } elseif (Autoriza::esAdministrativo($this->user)) {

            $guardarAlumno = new GuardarAlumno;

            return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));

            // El comentario que había aquí decía «Debo verificar que tenga rol
            // Psicólogo. Por ahora lo dejo Usuario para que funcione», y lo que estaba
            // escrito debajo era `$this->user->tipo == 'Psicólogo'` — un valor que
            // `tipo` no toma nunca, así que la rama no se ejecutaba jamás y las
            // necesidades educativas especiales solo las escribía un superusuario.
            // Su autor sabía cuál era el criterio bueno; lo que faltaba era que el rol
            // se preguntara donde vive. Decidido por Joseth el 21 ago 2026 después de
            // ver que el PIAR filtra por `nee=1` y que sin esto el psicólogo no puede
            // meter a nadie en él. Ver 05 §30.2 y §35.3.
        } elseif (Role::isPsicologo($this->user->user_id) && (Request::input('propiedad') == 'nee' || Request::input('propiedad') == 'nee_descripcion')) {

            $guardarAlumno = new GuardarAlumno;

            return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));

        } else {
            return abort(400, 'No tiene permisos');
        }

    }

    /*
     * «AGUDELO GONZALEZ MATIAS» NO ES NI UN NOMBRE NI UN APELLIDO, Y ES LO QUE TECLEA TODO EL MUNDO.
     *
     * La condición era `nombres like %texto% or apellidos like %texto%`: lo tecleado se comparaba
     * ENTERO contra cada mitad por separado, así que en cuanto alguien juntaba el nombre con los
     * apellidos --en el orden que fuera-- no casaba ninguna de las dos y el buscador contestaba que
     * esa persona no existe. Medido contra el docker el 19 sep 2026: el alumno 1 es «MATIAS /
     * AGUDELO GONZALEZ» y buscar «AGUDELO GONZALEZ MATIAS» devolvía 0 filas.
     *
     * Ahora lo tecleado se parte en palabras y **se piden todas, en cualquier orden**, contra el
     * nombre completo. Es la misma regla que ya usa el buscador de pantallas de `app2` (`buscarEn`,
     * en `cascara/buscador/indice.ts`), así que las tres listas del mismo cuadro se comportan igual.
     *
     * CON UNA SOLA PALABRA DEVUELVE LO MISMO QUE ANTES, que es lo que importa para los otros cuatro
     * que llaman aquí --el front viejo, `sidebarMenu` y la app de Flutter--: `CONCAT(nombres, ' ',
     * apellidos)` contiene las dos mitades, así que lo que casaba con una sigue casando.
     *
     * Sin nada escrito la condición queda en `LIKE '%%'` y devuelve a todo el mundo, que es también
     * lo que hacía antes: quien mande el texto vacío recibe lo de siempre.
     */
    private function porTodasLasPalabras($texto)
    {
        $palabras = preg_split('/\s+/', trim((string) $texto), -1, PREG_SPLIT_NO_EMPTY);
        if (! $palabras) {
            $palabras = [''];
        }

        $condiciones = [];
        $valores = [];

        foreach ($palabras as $i => $palabra) {
            $condiciones[] = "CONCAT(a.nombres, ' ', a.apellidos) like :palabra{$i}";
            $valores[':palabra'.$i] = '%'.$palabra.'%';
        }

        /* Los paréntesis no son adorno: sin ellos el `and a.deleted_at is null` de quien llama se
         * pega sólo a la primera condición --`AND` ata más fuerte que `OR`-- y era justo el fallo
         * que tenía la consulta de aquí abajo: buscando por apellidos SÍ salían los de la papelera
         * (35 en el docker local) y buscando por nombres no. */
        return ['('.implode(' and ', $condiciones).')', $valores];
    }

    /**
     * ¿Este alumno ya existe? Con lo necesario para decidirlo.
     *
     * Los dos que ya había —`personas-check` y `documento-check`— devuelven cuatro campos, y
     * con cuatro campos la pantalla de alta sólo podía pintar un aviso amarillo. Éste devuelve
     * de qué año viene cada candidato, en qué grupo estuvo, cómo acabó y cuántas definitivas
     * trae, que es lo que hace falta para pulsar «es éste» en vez de crear la ficha otra vez.
     *
     * No se tocan los dos viejos: los llama el front sin migrar y la app de Flutter.
     * Ver App\Support\AlumnosParecidos.
     */
    /**
     * Las fichas que parecen la misma persona. No escribe.
     *
     * Es la pantalla de arreglo: hasta hoy, dos fichas del mismo chico sólo se podían resolver
     * borrando una con `forcedelete` y perdiendo su expediente. Ver App\Support\DuplicadosDeAlumnos.
     */
    public function putDuplicados()
    {
        Autoriza::exigir(Autoriza::esAdministrativo($this->user),
            'Solo un administrativo puede revisar los alumnos duplicados.');

        return DuplicadosDeAlumnos::listar();
    }

    /** Qué pasaría al unir dos fichas. NO escribe: es lo que se mira antes de decidir. */
    public function putRevisarFusion()
    {
        Autoriza::exigir(Autoriza::esAdministrativo($this->user),
            'Solo un administrativo puede unir fichas de alumnos.');

        return FusionDeAlumnos::revisar(
            (int) Request::input('origen_id'),
            (int) Request::input('destino_id'),
        );
    }

    /**
     * Y las une.
     *
     * **`esSuperusuario`, un escalón por encima de mirar.** Mover el expediente de un alumno a
     * otra ficha y mandar la primera a la papelera no se deshace solo, y el propio repo lo dejó
     * escrito: «fusionar dos expedientes mal es de lo poco aquí que no se deshace con un DELETE»
     * (`EnsayoDeLaImportacion.php:457`). Revisar lo puede hacer la secretaría; ejecutarlo, no.
     */
    public function putFusionar()
    {
        Autoriza::exigir(Autoriza::esSuperusuario($this->user),
            'Solo un superusuario puede unir dos fichas de alumno.');

        $decisiones = Request::input('decisiones', []);

        return FusionDeAlumnos::fusionar(
            (int) Request::input('origen_id'),
            (int) Request::input('destino_id'),
            is_array($decisiones) ? $decisiones : [],
            $this->user->user_id ?? null,
        );
    }

    public function putAlumnosParecidos()
    {
        return AlumnosParecidos::buscar(
            Request::input('nombres'),
            Request::input('apellidos'),
            Request::input('documento'),
        );
    }

    public function putPersonasCheck()
    {
        $texto = Request::input('texto');

        /*
         * EL PARAMETRO SE VUELVE A LEER, Y LLEVABA TIEMPO IGNORADO. Estaba comentado y debajo un
         * `$todos_anios = true;` escrito a pelo, asi que daba igual lo que mandara el cliente.
         * Medido contra el docker el 2026-09-18: con `todos_anios=false` devolvia las mismas cinco
         * personas que con `true`, incluida una alumna cuya unica matricula es del `year_id` 1
         * estando el usuario en el 9.
         *
         * A quien le importa, de los cinco que llaman aqui:
         *
         *   - `PersonaCtrl.ts:856` (front viejo) manda **la casilla que marca el usuario**: era una
         *     casilla muerta, y vuelve a servir con esto.
         *   - el buscador de `app2` mandaba `false` y recibia todos los años igual.
         *   - `sidebarMenu.ts` y `AlumnosNewCtrl.ts` NO lo mandan, y `AsignarAcudienteAOtroModal`
         *     manda `true`. Por eso el valor por defecto es `true`: para esos tres no cambia nada.
         *
         * `filter_var` y no un `(bool)`: por JSON llega un booleano, pero por formulario llegaria la
         * cadena `"false"`, y `(bool) "false"` es `true` -- que es como un arreglo de esto se
         * convierte en el mismo fallo con otra cara.
         */
        $todos_anios = filter_var(Request::input('todos_anios', true), FILTER_VALIDATE_BOOLEAN);

        [$condicion, $valores] = $this->porTodasLasPalabras($texto);

        if ($todos_anios) {
            $consulta = 'SELECT a.id as alumno_id, a.nombres, a.apellidos, "alumno" as tipo, a.deleted_at, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					FROM alumnos a
					INNER JOIN matriculas m on a.id=m.alumno_id and m.deleted_at is null
					LEFT JOIN images i2 on i2.id=a.foto_id and i2.deleted_at is null
					WHERE a.deleted_at is null and '.$condicion.'
					GROUP BY a.id order by a.nombres, a.apellidos';
            // INNER JOIN matriculas para evitar que se repita. Sólo traerá los que tengan alguna matricula en el sistema.

            $res = DB::select($consulta, $valores);

            return ['personas' => $res];
        } else {
            $consulta = 'SELECT m.alumno_id, a.nombres, a.apellidos, m.id as matricula_id, "alumno" as tipo, g.abrev, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				FROM alumnos a
				INNER JOIN matriculas m on a.id=m.alumno_id and (m.estado="ASIS" or m.estado="MATR")
				INNER JOIN grupos g on g.year_id=:anio and g.id=m.grupo_id and g.deleted_at is null
				LEFT JOIN images i2 on i2.id=a.foto_id and i2.deleted_at is null
				WHERE '.$condicion.'
				GROUP BY m.alumno_id, m.id order by g.orden';

            $res = DB::select($consulta, [':anio' => $this->user->year_id] + $valores);

            return ['personas' => $res];

        }
    }

    public function putDocumentoCheck()
    {
        $texto = Request::input('texto');

        $consulta = 'SELECT a.id as alumno_id, a.documento, a.nombres, a.apellidos, "alumno" as tipo, a.deleted_at
			FROM alumnos a
			WHERE documento like :texto';

        $res = DB::select($consulta, [':texto' => '%'.$texto.'%']);

        return ['personas' => $res];

    }

    public function putGuardarValorVarios()
    {
        $year_id = Request::input('year_id', $this->user->year_id);

        if ($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) {

            $alumnos = Request::input('alumnos');
            $cant = count($alumnos);

            for ($i = 0; $i < $cant; $i++) {
                $consulta = 'SELECT a.id, a.user_id, g.id as grupo_id, g.titular_id, m.id as matricula_id FROM alumnos a
								INNER JOIN matriculas m ON m.alumno_id=a.id
								INNER JOIN grupos g ON g.id=m.grupo_id AND g.year_id=? AND g.titular_id=?
								WHERE a.id=?';
                $alumno = DB::select($consulta, [$this->user->year_id, $this->user->persona_id, $alumnos[$i]['alumno_id']]);

                if (count($alumno) > 0) {
                    $alumno = $alumno[0];
                    $guardarAlumno = new GuardarAlumno;

                    return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, $alumnos[$i]['alumno_id']);
                } else {
                    return response()->json(['autorizado' => false, 'msg' => 'No eres el titular'], 400);
                }

            }

        } elseif (Autoriza::esAdministrativo($this->user)) {

            $alumnos = Request::input('alumnos');
            $cant = count($alumnos);

            for ($i = 0; $i < $cant; $i++) {

                $guardarAlumno = new GuardarAlumno;
                $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), false, $year_id, $alumnos[$i]['alumno_id']);

            }

            return 'Cambios realizados';
        } else {
            return abort(400, 'No tiene permisos');
        }

    }

    public function deleteDestroy($id)
    {
        if (Autoriza::puedeEditarAlumnos($this->user)) {
            $alumno = Alumno::find($id);
            // Alumno::destroy($id);
            // $alumno->restore();
            // $queries = DB::getQueryLog();
            // $last_query = end($queries);
            // return $last_query;

            if ($alumno) {
                $alumno->delete();
            } else {
                return abort(400, 'Alumno no existe o está en Papelera.');
            }

            return $alumno;
        } else {
            return abort(400, 'No tiene permisos');
        }
    }

    public function deleteForcedelete($id)
    {
        if (Autoriza::puedeBorrarAlumnos($this->user)) {
            $alumno = Alumno::onlyTrashed()->findOrFail($id);

            $alumno->forceDelete();

            return $alumno;
        } else {
            return abort(400, 'No tiene permisos');
        }
    }

    public function putRestore($id)
    {
        if (Autoriza::puedeEditarAlumnos($this->user)) {
            $alumno = Alumno::onlyTrashed()->findOrFail($id);

            $alumno->restore();

            return $alumno;
        } else {
            return abort(400, 'No tiene permisos');
        }
    }

    public function getTrashed()
    {
        $user = $this->user;

        $previous_year = $user->year - 1;
        $id_previous_year = 0;
        $previous_year = Year::where('year', '=', $previous_year)->first();

        $consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:id_previous_year
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year_id)
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year2_id
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is not null';

        return DB::select($consulta, [
            ':id_previous_year' => $id_previous_year,
            ':year_id' => $user->year_id,
            ':year2_id' => $user->year_id,
        ]);
    }
}
