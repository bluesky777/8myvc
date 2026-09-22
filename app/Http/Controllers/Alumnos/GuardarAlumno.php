<?php

namespace App\Http\Controllers\Alumnos;

use App\Models\Matricula;
use App\Services\Auditoria;
use App\Support\ColumnaSegura;
use App\Support\CorreoDeLaCuenta;
use App\Support\FilaQueSeVaAEscribir;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class GuardarAlumno
{
    public function valor($user, $propiedad, $valor, $user_id = false, $year_id = false, $alumno_id = false)
    {

        $consulta = '';
        $datos = [];
        $now = Carbon::now('America/Bogota');

        if (! $alumno_id) {
            $alumno_id = Request::input('alumno_id');
        }

        if ($propiedad == 'fecha_nac' || $propiedad == 'fecha_retiro' || $propiedad == 'prematriculado') {
            $valor = Carbon::parse($valor);
        }

        switch ($propiedad) {
            case 'username':
            case 'email':
            case 'is_active':

                if (! $user_id) {
                    $user_id = Request::input('user_id');
                }

                FilaQueSeVaAEscribir::exigir('users', 'id', $user_id, 'Esa cuenta de usuario');

                // `email` aquí es el de la CUENTA —esta rama escribe `users`—, así que
                // pasa por la misma regla que el resto: una cadena sin nada delante de
                // la arroba no es una dirección y el reseteo la encontraría igual.
                // Sólo `email`: envolver `$valor` a secas dejaría en null los
                // `username`, que comparten este bloque.
                if ($propiedad === 'email') {
                    $valor = CorreoDeLaCuenta::oNada($valor);
                }

                [$tabla, $columna, $filaId, $entidad] = ['users', ColumnaSegura::exigir('users', $propiedad), $user_id, 'usuario'];
                $consulta = 'UPDATE users SET '.$columna.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
                $datos = [':valor' => $valor, ':modificador' => $user->user_id, ':fecha' => $now, ':user_id' => $user_id];

                break;

            case 'nuevo':
            case 'fecha_pension':
            case 'fecha_retiro':
            case 'fecha_matricula':
            case 'razon_retiro':
            case 'repitente':
            case 'prematriculado':
            case 'programar':
            case 'descripcion_recomendacion':
            case 'efectuar_una':
            case 'promovido':
            case 'descripcion_efectuada':
            case 'nro_folio':

                /*
                 * **Cuál es la matrícula del año la decide `Matricula`, y ya no esta
                 * consulta.** Es la §9.5 del plan, y era el fallo que nadie ve porque
                 * nadie mira estos campos al día siguiente: aquí se escribía en `[0]` de
                 * una consulta **sin `ORDER BY`, sin `m.deleted_at` y sin `g.deleted_at`**,
                 * mientras la ficha leía `[0]` de otra que sí filtra y ordena por
                 * `a.apellidos` —un empate total para un solo alumno—. Con dos matrículas
                 * vivas del mismo año, **se lee de una y se escribe en otra**, y las tres
                 * columnas que salen por aquí son `repitente`, `promovido` y `nro_folio`.
                 *
                 * Lo que había además de eso, y no vuelve:
                 *
                 *   - el `// Tengo confusión con INNER o LEFT grupos` del autor, que era
                 *     exactamente esta pregunta sin contestar;
                 *   - y cuatro columnas seleccionadas —`a.id`, `a.user_id`, `g.id`,
                 *     `g.titular_id`— **que no lee nadie**: sólo se usaba `matricula_id`.
                 *
                 * El 400 se conserva tal cual. Es raro que un no-controlador devuelva una
                 * respuesta HTTP, pero cambiarlo aquí es cambiarle el contrato a los cinco
                 * llamadores de `AlumnosController`, y esto no va de eso.
                 */
                $matricula = Matricula::laDelAnio((int) $alumno_id, (int) $year_id);

                // **404 y ya no el 400 que puso la §9.5.** Esta rama era la única que
                // distinguía «no existe» de «no cambió nada», y lo hacía con un código
                // distinto del que ahora usan las otras dos. Una misma ruta contestando
                // dos códigos para la misma condición es peor que cualquiera de los dos:
                // el cliente tendría que aprenderse cuál toca según la propiedad que
                // mande. Es contrato, y está anotado como tal.
                if ($matricula === null) {
                    abort(404, 'Ese alumno no tiene matrícula en este año.');
                }

                [$tabla, $columna, $filaId, $entidad] = ['matriculas', ColumnaSegura::exigir('matriculas', $propiedad), $matricula->id, 'matricula'];
                $consulta = 'UPDATE matriculas SET '.$columna.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:matricula_id';
                $datos = [
                    ':valor' => $valor,
                    ':modificador' => $user->user_id,
                    ':fecha' => $now,
                    ':matricula_id' => $matricula->id,
                ];
                break;

            default:

                FilaQueSeVaAEscribir::exigir('alumnos', 'id', $alumno_id, 'Ese alumno');

                [$tabla, $columna, $filaId, $entidad] = ['alumnos', ColumnaSegura::exigir('alumnos', $propiedad), $alumno_id, 'alumno'];
                $consulta = 'UPDATE alumnos SET '.$columna.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:alumno_id';
                $datos = [
                    ':valor' => $valor,
                    ':modificador' => $user->user_id,
                    ':fecha' => $now,
                    ':alumno_id' => $alumno_id,
                ];
                break;
        }

        /*
         * **Ya no se mira lo que devuelve `DB::update`, y ésa es la opción A entera.**
         * Devuelve filas AFECTADAS, y MySQL da 0 cuando el UPDATE no cambia ningún valor
         * — no cuando no encuentra la fila. Con eso, `'No guardado'` juntaba «el valor ya
         * era ése» con «esa fila no existe», y **guardar dos veces lo mismo contestaba
         * «No guardado» con 200 y el estado correcto**.
         *
         * Ahora la fila se comprueba arriba, rama por rama: si no está, la petición ya ha
         * cortado con 404. Llegar hasta aquí significa que la fila existe, así que el
         * único resultado posible es «guardado» — cambiara algo o no. Un fallo real de la
         * base no pasa por esta línea: lanza excepción y sale 500, igual que antes.
         *
         * `'No guardado'` desaparece de los dos métodos de este fichero. 09 §13, opción A,
         * decidida por Joseth el 1 sep 2026.
         */

        /*
         * **El valor de ANTES, leído aquí y no deducido después.** Es la decisión de
         * Joseth del 21 sep: un rastro que sólo dice *a qué quedó* no sirve para lo que
         * se pidió. Deducirlo de la línea anterior tampoco vale mientras 178 de los 221
         * métodos que escriben no dejen rastro: la cadena tiene huecos y la deducción
         * afirmaría una continuidad falsa en vez de enseñar el hueco.
         *
         * Cuesta **una consulta por clave primaria** en un guardado de un campo de
         * formulario, de uno en uno. No hay lote por aquí: los tres llamadores de
         * `AlumnosController` mandan un campo por petición.
         *
         * `$columna` no viene del cuerpo: en las ramas dinámicas ya pasó por
         * `ColumnaSegura::exigir()` unas líneas más arriba, y en las fijas es un
         * literal. Y `$tabla` sale de esta misma función, nunca de la petición.
         */
        $antes = DB::selectOne("SELECT {$columna} AS valor FROM `{$tabla}` WHERE id = ?", [$filaId]);

        DB::update($consulta, $datos);

        /*
         * El sujeto es la fila escrita —`$filaId`, que sale de la rama— y no el id que
         * vino en el cuerpo: es la regla del sujeto del 18. `deAlumno()` va aparte
         * porque las tres ramas escriben en tablas distintas y **todas hablan del mismo
         * alumno**: sin eso, `auditoria/alumno/{id}` no encontraría el cambio de su
         * propio correo.
         */
        Auditoria::registrar()
            ->editar($entidad, (int) $filaId)
            ->deAlumno($alumno_id ? (int) $alumno_id : null)
            ->en(year: $year_id ? (int) $year_id : null)
            ->de($antes->valor ?? null)
            ->a($valor)
            ->resumen('Cambió '.trim($columna, '`').' en '.$tabla)
            ->guardar();

        return 'Guardado';

    }

    public function valorAcudiente($acudiente_id, $parentesco_id, $user_acud_id, $propiedad, $valor, $user_id)
    {

        $consulta = '';
        $datos = [];
        $now = Carbon::now('America/Bogota');

        if ($propiedad == 'fecha_nac') {
            $valor = Carbon::parse($valor);
        }

        switch ($propiedad) {
            case 'username':
                FilaQueSeVaAEscribir::exigir('users', 'id', $user_acud_id, 'Esa cuenta de usuario');
                [$tabla, $columna, $filaId, $entidad] = ['users', 'username', $user_acud_id, 'usuario'];
                $consulta = 'UPDATE users SET username=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
                $datos = [':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':user_id' => $user_acud_id];
                break;

            case 'email2':
                // El correo de la CUENTA, que es por donde busca la recuperación de
                // contraseña (`LoginController:240-266`, cuatro consultas y las cuatro
                // sobre `users.email`). Sin esta rama caía en el `default`, que hace
                // `UPDATE acudientes SET …` pasando por `ColumnaSegura::exigir()`: como
                // `acudientes` **no tiene ninguna columna `email2`**, no guardaba en el
                // sitio equivocado — reventaba. Comprobado en `information_schema` el
                // 20 sep 2026.
                //
                // `email` a secas sigue cayendo en el `default` y escribe la ficha, que
                // es lo correcto: son dos correos distintos y los dos se editan.
                FilaQueSeVaAEscribir::exigir('users', 'id', $user_acud_id, 'Esa cuenta de usuario');
                [$tabla, $columna, $filaId, $entidad] = ['users', 'email', $user_acud_id, 'usuario'];
                $consulta = 'UPDATE users SET email=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
                $datos = [':valor' => CorreoDeLaCuenta::oNada($valor), ':modificador' => $user_id, ':fecha' => $now, ':user_id' => $user_acud_id];
                break;

            case 'parentesco':
                FilaQueSeVaAEscribir::exigir('parentescos', 'id', $parentesco_id, 'Ese parentesco');
                [$tabla, $columna, $filaId, $entidad] = ['parentescos', 'parentesco', $parentesco_id, 'parentesco'];
                $consulta = 'UPDATE parentescos SET parentesco=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:parentesco_id';
                $datos = [':valor' => $valor, ':modificador' => $user_id, ':fecha' => $now, ':parentesco_id' => $parentesco_id];
                break;

            default:
                FilaQueSeVaAEscribir::exigir('acudientes', 'id', $acudiente_id, 'Ese acudiente');
                [$tabla, $columna, $filaId, $entidad] = ['acudientes', ColumnaSegura::exigir('acudientes', $propiedad), $acudiente_id, 'acudiente'];
                $consulta = 'UPDATE acudientes SET '.$columna.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:acudiente_id';
                $datos = [
                    ':valor' => $valor,
                    ':modificador' => $user_id,
                    ':fecha' => $now,
                    ':acudiente_id' => $acudiente_id,
                ];
                break;
        }

        /*
         * **Ya no se mira lo que devuelve `DB::update`, y ésa es la opción A entera.**
         * Devuelve filas AFECTADAS, y MySQL da 0 cuando el UPDATE no cambia ningún valor
         * — no cuando no encuentra la fila. Con eso, `'No guardado'` juntaba «el valor ya
         * era ése» con «esa fila no existe», y **guardar dos veces lo mismo contestaba
         * «No guardado» con 200 y el estado correcto**.
         *
         * Ahora la fila se comprueba arriba, rama por rama: si no está, la petición ya ha
         * cortado con 404. Llegar hasta aquí significa que la fila existe, así que el
         * único resultado posible es «guardado» — cambiara algo o no. Un fallo real de la
         * base no pasa por esta línea: lanza excepción y sale 500, igual que antes.
         *
         * `'No guardado'` desaparece de los dos métodos de este fichero. 09 §13, opción A,
         * decidida por Joseth el 1 sep 2026.
         */

        /*
         * **El valor de ANTES, leído aquí y no deducido después.** Es la decisión de
         * Joseth del 21 sep: un rastro que sólo dice *a qué quedó* no sirve para lo que
         * se pidió. Deducirlo de la línea anterior tampoco vale mientras 178 de los 221
         * métodos que escriben no dejen rastro: la cadena tiene huecos y la deducción
         * afirmaría una continuidad falsa en vez de enseñar el hueco.
         *
         * Cuesta **una consulta por clave primaria** en un guardado de un campo de
         * formulario, de uno en uno. No hay lote por aquí: los tres llamadores de
         * `AlumnosController` mandan un campo por petición.
         *
         * `$columna` no viene del cuerpo: en las ramas dinámicas ya pasó por
         * `ColumnaSegura::exigir()` unas líneas más arriba, y en las fijas es un
         * literal. Y `$tabla` sale de esta misma función, nunca de la petición.
         */
        $antes = DB::selectOne("SELECT {$columna} AS valor FROM `{$tabla}` WHERE id = ?", [$filaId]);

        DB::update($consulta, $datos);

        /*
         * Sin `deAlumno()`: aquí el sujeto es el acudiente o su cuenta, y colgar esto de
         * un alumno cualquiera de los suyos diría que se le cambió algo a él. Se
         * encuentra por `auditoria/entidad/acudiente/{id}`.
         */
        Auditoria::registrar()
            ->editar($entidad, (int) $filaId)
            ->de($antes->valor ?? null)
            ->a($valor)
            ->resumen('Cambió '.trim($columna, '`').' en '.$tabla)
            ->guardar();

        return 'Guardado';

    }
}
