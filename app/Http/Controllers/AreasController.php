<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Support\CamposQueVinieron;
use App\Support\CatalogoEnUso;
use App\Support\Reloj;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class AreasController extends Controller
{
    public function getIndex()
    {
        return Area::orderBy('orden')->get();
    }

    public function postIndex()
    {
        try {
            $area = new Area;
            $area->nombre = Request::input('nombre');
            $area->alias = Request::input('alias');
            $area->orden = Request::input('orden');
            $area->save();

            return $area;
        } catch (\Exception $e) {
            abort(422, 'Datos incorrectos');
        }
    }

    public function putUpdateOrden()
    {
        $user = User::fromToken();

        $sortHash = Request::input('sortHash');

        for ($row = 0; $row < count($sortHash); $row++) {
            foreach ($sortHash[$row] as $key => $value) {

                // `find()` devolvía null con un id que no existe y la línea de abajo
                // reventaba: 500 donde tocaba 404. El bucle de reordenar está copiado en
                // cinco controladores y los cinco lo tenían; el de unidades se arregló en
                // la §47 y éste salió al contarlos. Ver 05 §52.
                $area = Area::findOrFail((int) $key);
                $area->orden = (int) $value;
                $area->save();
            }
        }

        return 'Ordenado correctamente';
    }

    /**
     * §81. **Un campo que no se manda no es un campo que no cambia: es un campo
     * que se pisa** —la frase de la §68— y aquí se pisaba contra una columna
     * `NOT NULL`, que es donde deja de ser un despiste y pasa a borrarle el
     * catálogo al colegio.
     *
     * Medido el 22 ago 2026: `PUT areas/update/1` con el cuerpo vacío dejaba
     * `nombre` en `''`, `alias` y `orden` en `null`, y contestaba **200 con el
     * cuerpo vacío** — ni siquiera devuelve la fila, así que el front no tenía
     * dónde verlo.
     *
     * Lo que la §78 dio por bueno para **crear** no vale para **editar**, y no
     * porque el código sea distinto —es el mismo, igual de crédulo— sino porque
     * MySQL trata el mismo error de dos maneras. Con `strict => false`
     * (config/database.php) y sobre esta misma columna:
     *
     *     UPDATE areas SET nombre=NULL WHERE id=1   ->  Warning 1048, queda ''
     *     INSERT INTO areas (nombre) VALUES (NULL)  ->  ERROR   1048, rechazado
     *
     * Mismo código 1048, distinta severidad. O sea que **el `NOT NULL` al que la
     * §78 le atribuyó salvar a ocho de los nueve no salva a ninguno por este
     * lado**, y aquella conclusión no se puede arrastrar hasta aquí.
     *
     * El arreglo es el de la §68 —asignar sólo lo que vino— y no le cambia la
     * respuesta a ningún cliente que mande la fila entera, que es lo que manda
     * la pantalla de áreas.
     */
    public function putUpdate($id)
    {
        $vinieron = CamposQueVinieron::capturar();

        $area = Area::findOrFail($id);

        if ($vinieron->trae('nombre')) {
            $area->nombre = Request::input('nombre');
        }
        if ($vinieron->trae('alias')) {
            $area->alias = Request::input('alias');
        }
        if ($vinieron->trae('orden')) {
            $area->orden = Request::input('orden');
        }

        $area->save();

    }

    /**
     * Un área con materias vivas no se borra — §70 y decisión de Joseth del 23 ago.
     *
     * Misma forma que `grados`: la materia se queda apuntando a un área en la
     * papelera. Se cierra con las mismas dos mitades de siempre —corta y no
     * escribe— y **hoy bloquearía en 20 de las 22 áreas vivas**, o sea que las dos
     * que quedan libres son las únicas donde esta ruta hacía algo inocuo.
     *
     * **`niveles_educativos` se dejó fuera a propósito** aunque tiene la misma
     * forma: allí bloquearía **4 de 4**, y una ruta enrutada que siempre contesta
     * 422 es peor que la que no existe — no dice qué pretendía hacer la pantalla.
     */
    public function deleteDestroy($id)
    {
        $areas = Area::findOrFail($id);

        CatalogoEnUso::exigirQueNadieApunte('materias', 'area_id', $areas->id, 'materias');

        $areas->delete();

        return $areas;
    }

    /**
     * `GET areas/jefes` — los directores de área **del año del usuario**.
     *
     * Decisión de Joseth (17 sep 2026): *«cada año es un dueño diferente (director
     * de área) y no puedo cambiar años pasados por configurar el actual»*. Por eso
     * esto no sale de `areas` —que no tiene año— sino de `jefes_de_area`, y por eso
     * es una ruta aparte y no una columna más en `GET areas`.
     *
     * **Y hay un segundo motivo para que sea aparte, que es el que la cierra**:
     * `GET areas` no lleva `auth.personal`, así que lo leen alumnos y acudientes.
     * Quién dirige un área es información del personal, y colgarla de aquella
     * respuesta se la habría dado a los 2.284 que no son personal sin que nadie lo
     * decidiera.
     *
     * ## Devuelve TODAS las áreas, también las que no tienen jefe
     *
     * La pantalla es una rejilla de áreas con su columna de jefe, así que necesita
     * la fila igual cuando está vacía —ahí es donde va el «Asignar»—. Un `INNER
     * JOIN` habría devuelto sólo las nombradas y la pantalla no habría sabido
     * distinguir «esta área no tiene jefe» de «esta área no existe».
     *
     * ## `contratado` es el tercer estado, y sin él la pantalla miente
     *
     * Una jefatura se hereda del año anterior sin mirar el contrato —decisión de
     * Joseth, 18 sep 2026, ver `YearsController::copiarLosJefesDeArea`—, así que el
     * año nuevo puede abrir con un jefe que todavía no ha renovado. Eso **no es un
     * error** y no se corrige solo; lo que no puede pasar es que no se vea. Por eso
     * viaja `contratado`, que es lo que la celda pinta en ámbar.
     */
    public function getJefes()
    {
        $user = User::fromToken();

        return DB::select(
            'SELECT a.id AS area_id, a.nombre AS area, a.alias AS area_alias, a.orden,
					j.profesor_id, p.nombres, p.apellidos, p.foto_id,
					CASE WHEN j.profesor_id IS NULL THEN NULL
						 ELSE IFNULL(i.nombre, IF(p.sexo = "F", "default_female.png", "default_male.png"))
					END AS foto_nombre,
					CASE WHEN j.profesor_id IS NULL THEN NULL
						 WHEN c.id IS NULL THEN 0 ELSE 1 END AS contratado
			   FROM areas a
			   LEFT JOIN jefes_de_area j ON j.area_id = a.id AND j.year_id = ?
			   LEFT JOIN profesores p ON p.id = j.profesor_id AND p.deleted_at is null
			   LEFT JOIN images i ON i.id = p.foto_id AND i.deleted_at is null
			   LEFT JOIN contratos c ON c.profesor_id = j.profesor_id AND c.year_id = ? AND c.deleted_at is null
			  WHERE a.deleted_at is null
			  ORDER BY a.orden, a.id;',
            [$user->year_id, $user->year_id]
        );
    }

    /**
     * `PUT areas/jefes/{area_id}` — nombrar director de área en el año en curso.
     *
     * **Es un reemplazo, no un alta**, y por eso es `PUT` y no `POST`: la tabla
     * lleva `UNIQUE (year_id, area_id)` —un área tiene **un** jefe por año, y lo
     * impide la base— así que nombrar a otro sobre un área que ya tiene jefe
     * reventaría con un 1062 si esto insertara a ciegas. Se borra y se pone, dentro
     * de una transacción.
     *
     * ## Quién puede: coordinación académica, y no cualquier docente
     *
     * `areas/update` y `areas/destroy` se conforman con `auth.personal`, o sea que
     * hoy **cualquier docente puede renombrar un área**. Eso es holgura vieja y no
     * se toca aquí, pero **no se hereda**: nombrar al director de un área es un acto
     * de coordinación académica, que es exactamente lo que `puedeEditarPlantillaNotas`
     * ya significa (D13, D28). No hace falta permiso nuevo.
     *
     * ## El 422 que evita un 500, y no es prolijidad
     *
     * `jefes_de_area.profesor_id` tiene clave ajena a `profesores`, así que un id
     * que no exista **no da un error de validación: da un 1452 de MySQL**, o sea un
     * 500 con la traza dentro. Lo midió la sesión del front que construyó la
     * pantalla, y esa comprobación sobrevive intacta al cambio de tabla.
     *
     * Y se exige además que **no esté en la papelera**: un docente borrado
     * lógicamente sigue teniendo su fila, así que la clave ajena lo aceptaría y el
     * área abriría con un jefe que ninguna lista sabe pintar.
     */
    public function putJefe($area_id)
    {
        $user = User::fromToken();

        Autoriza::exigir(Autoriza::puedeEditarPlantillaNotas($user),
            'No tienes permiso para nombrar directores de área.');

        Autoriza::exigirEscrituraEnElAnio($user, $user->year_id, 'nombrar directores de área');

        $area = Area::findOrFail($area_id);

        $profesor_id = Request::input('profesor_id');

        if (! is_numeric($profesor_id) || (int) $profesor_id <= 0) {
            abort(422, '`profesor_id` hace falta y tiene que ser un identificador.');
        }

        $profesor_id = (int) $profesor_id;

        $profesor = DB::selectOne('SELECT id FROM profesores WHERE id=? AND deleted_at is null;', [$profesor_id]);

        if ($profesor === null) {
            abort(422, 'Ese docente no existe o está en la papelera.');
        }

        $ahora = Reloj::ahoraTexto();

        DB::transaction(function () use ($user, $area, $profesor_id, $ahora) {
            DB::delete('DELETE FROM jefes_de_area WHERE year_id=? AND area_id=?;', [$user->year_id, $area->id]);

            DB::insert('INSERT INTO jefes_de_area(year_id, area_id, profesor_id, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?)',
                [$user->year_id, $area->id, $profesor_id, $user->user_id, $ahora, $ahora]);
        });

        Auditoria::registrar()
            ->crear('jefe_de_area', (int) $area->id)
            ->en(year: (int) $user->year_id)
            ->a(['area_id' => (int) $area->id, 'profesor_id' => $profesor_id])
            ->resumen('Nombró director de área en '.$area->nombre)
            ->guardar();

        return $this->jefeDelArea((int) $user->year_id, (int) $area->id);
    }

    /**
     * `DELETE areas/jefes/{area_id}` — quitarle el director al área en este año.
     *
     * **Quitar es borrar la fila, no poner `NULL`**: la tabla no tiene columna
     * anulable donde dejarlo, y ésa es justo la propiedad que permite que el
     * `UNIQUE` sea real (ver la migración `2026_09_17_200000_jefe_de_area_por_anio`).
     *
     * Contesta **cuántas filas quitó** y no `OK`: un área que ya no tenía jefe y una
     * que sí lo tenía se responden igual de bien, pero **no son lo mismo**, y desde
     * la pantalla «no pasó nada» y «ya estaba quitado» se leen idénticos.
     */
    public function deleteJefe($area_id)
    {
        $user = User::fromToken();

        Autoriza::exigir(Autoriza::puedeEditarPlantillaNotas($user),
            'No tienes permiso para quitar directores de área.');

        Autoriza::exigirEscrituraEnElAnio($user, $user->year_id, 'quitar directores de área');

        $area = Area::findOrFail($area_id);

        $antes = $this->jefeDelArea((int) $user->year_id, (int) $area->id);

        $quitadas = DB::delete('DELETE FROM jefes_de_area WHERE year_id=? AND area_id=?;',
            [$user->year_id, $area->id]);

        if ($quitadas > 0) {
            Auditoria::registrar()
                ->borrar('jefe_de_area', (int) $area->id)
                ->en(year: (int) $user->year_id)
                ->de(['area_id' => (int) $area->id, 'profesor_id' => $antes->profesor_id ?? null])
                ->resumen('Quitó el director de área de '.$area->nombre)
                ->guardar();
        }

        return ['area_id' => (int) $area->id, 'quitadas' => $quitadas];
    }

    /** La fila de una jefatura, con el docente y su contrato — la misma forma que `getJefes`. */
    private function jefeDelArea(int $year_id, int $area_id)
    {
        return DB::selectOne(
            'SELECT a.id AS area_id, a.nombre AS area, a.alias AS area_alias, a.orden,
					j.profesor_id, p.nombres, p.apellidos, p.foto_id,
					CASE WHEN j.profesor_id IS NULL THEN NULL
						 ELSE IFNULL(i.nombre, IF(p.sexo = "F", "default_female.png", "default_male.png"))
					END AS foto_nombre,
					CASE WHEN j.profesor_id IS NULL THEN NULL
						 WHEN c.id IS NULL THEN 0 ELSE 1 END AS contratado
			   FROM areas a
			   LEFT JOIN jefes_de_area j ON j.area_id = a.id AND j.year_id = ?
			   LEFT JOIN profesores p ON p.id = j.profesor_id AND p.deleted_at is null
			   LEFT JOIN images i ON i.id = p.foto_id AND i.deleted_at is null
			   LEFT JOIN contratos c ON c.profesor_id = j.profesor_id AND c.year_id = ? AND c.deleted_at is null
			  WHERE a.id = ?;',
            [$year_id, $year_id, $area_id]
        );
    }
}
