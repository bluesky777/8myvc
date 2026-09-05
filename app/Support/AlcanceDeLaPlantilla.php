<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Qué filas de la plantilla del año le tocan a una asignatura.
 *
 * Es la **decisión 8** de Joseth (2 sep 2026), §5.7.a de
 * [28](../../docs/migracion/28-competencias-e-indicadores.md): la plantilla deja
 * de ser «una sola para todas las asignaturas del año» y cada fila puede decir a
 * qué nivel educativo y a qué materia va dirigida.
 *
 * ## Por qué esto es una clase y no dos consultas iguales
 *
 * Lo leen **dos** sitios que tienen que decidir lo mismo: el sembrador viejo
 * (`UnidadesController::getDeAsignaturaPeriodo`, el `GET` que escribe) y la
 * pantalla nueva (`PlantillaNotasController`, que la enseña y que siembra a
 * mano). Con la regla copiada en los dos, el día que alguien afine la precedencia
 * en uno **la pantalla enseñaría una plantilla y el sembrador aplicaría otra**, y
 * ese fallo no da error: da una rejilla distinta de la que el colegio vio. Es el
 * mismo movimiento que ya hicieron `DefinitivasDeAsignatura` con las definitivas
 * y `Auditoria` con la bitácora.
 *
 * ## La precedencia: gana la más específica, y las cuatro gradas están ordenadas
 *
 * Una asignatura es materia × grupo, y de ahí salen sus dos coordenadas: la
 * **materia** directamente, y el **nivel educativo** por grupo → grado → nivel.
 * Una fila de plantilla casa si sus columnas no nulas coinciden. De todas las que
 * casan **se aplica sólo la grada más alta**:
 *
 *   | grada | la fila dice | ejemplo |
 *   |---|---|---|
 *   | 3 | nivel **y** materia | «Lengua de preescolar» |
 *   | 2 | sólo nivel | «todo preescolar» |
 *   | 1 | sólo materia | «Lengua, en todo el colegio» |
 *   | 0 | ninguna de las dos | la plantilla de siempre |
 *
 * **Es la grada entera y no la fila**: una plantilla son varias unidades que
 * suman 100, así que mezclar dos gradas daría un reparto que nadie escribió —
 * «las cuatro de siempre **más** la única de preescolar» suma 200.
 *
 * **Y el nivel gana a la materia (grada 2 sobre grada 1), que es la única
 * decisión discutible de aquí.** El caso que hizo existir esto es preescolar: una
 * plantilla de **una fila** para un nivel donde el segundo piso de la rejilla es
 * un peaje (803 de 1.169 subunidades repiten el texto de su unidad, contra 76 de
 * 31.873 en el resto). Si una fila «Lengua, en todo el colegio» le ganara a «todo
 * preescolar», la docente de preescolar volvería a recibir la rejilla que esto
 * viene a quitarle, y el módulo no serviría para lo que se pidió.
 *
 * **La precedencia NO puede depender del orden de inserción**, y por eso se
 * calcula con la grada y no con un `ORDER BY … LIMIT 1`: un `ORDER BY` sobre `id`
 * hace exactamente lo correcto hasta el día que alguien reescribe una fila, y
 * entonces cambia la plantilla de un colegio sin que nadie haya tocado nada. El
 * test que lo fija **invierte el orden de inserción**, que es lo único que
 * distingue una precedencia de una casualidad.
 *
 * ## NULL es «sin restricción», y eso es lo que hace la migración aditiva
 *
 * Toda fila que existía antes de `2026_09_05_200000_alcance_de_la_plantilla`
 * tiene las dos columnas a NULL, o sea grada 0, o sea **la única grada que hay**:
 * mientras nadie use la pantalla nueva, esto selecciona exactamente las mismas
 * filas que la consulta que sustituye.
 */
final class AlcanceDeLaPlantilla
{
    /** Las columnas de la plantilla que se leen. Nombradas: nunca `SELECT *`. */
    private const COLUMNAS = 'id, definicion, porcentaje, obligatoria, orden, nivel_educativo_id, materia_id';

    /**
     * Las dos coordenadas de una asignatura: su materia y el nivel educativo de
     * su grupo. `null` si la asignatura no existe o está en la papelera.
     *
     * `nivel_educativo_id` puede volver nulo **con la asignatura viva**:
     * `grados.nivel_educativo_id` es anulable y hay colegios con grados sin nivel
     * asignado. Eso no es un error, es una asignatura que sólo puede casar con
     * filas que no pidan nivel — que es lo correcto: una plantilla «de preescolar»
     * no debe caerle a un grado del que nadie ha dicho de qué nivel es.
     */
    public static function deAsignatura(int $asignaturaId): ?object
    {
        $filas = DB::select(
            'SELECT a.materia_id, g2.nivel_educativo_id
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               JOIN grados g2 ON g2.id = g.grado_id AND g2.deleted_at IS NULL
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$asignaturaId]
        );

        if ($filas === []) {
            return null;
        }

        return (object) [
            'materia_id' => $filas[0]->materia_id === null ? null : (int) $filas[0]->materia_id,
            'nivel_educativo_id' => $filas[0]->nivel_educativo_id === null ? null : (int) $filas[0]->nivel_educativo_id,
        ];
    }

    /**
     * Las unidades de plantilla del año que le tocan a esa combinación, ya
     * resuelta la precedencia y ordenadas como se van a sembrar.
     *
     * @return list<object>
     */
    public static function unidadesPara(int $yearId, ?int $nivelId, ?int $materiaId): array
    {
        $candidatas = DB::select(
            'SELECT '.self::COLUMNAS.'
               FROM unidades_por_defecto
              WHERE year_id = ?
                AND deleted_at IS NULL
                AND (nivel_educativo_id IS NULL OR nivel_educativo_id = ?)
                AND (materia_id        IS NULL OR materia_id        = ?)
              ORDER BY orden, id',
            [$yearId, $nivelId, $materiaId]
        );

        return self::soloLaGradaMasAlta($candidatas);
    }

    /**
     * Todas las filas de la plantilla del año, sin filtrar por alcance: es lo que
     * enseña `GET plantilla-notas`, que tiene que mostrar también las que hoy no
     * le tocan a nadie.
     *
     * @return list<object>
     */
    public static function todasDelAnio(int $yearId): array
    {
        return array_values(DB::select(
            'SELECT '.self::COLUMNAS.'
               FROM unidades_por_defecto
              WHERE year_id = ? AND deleted_at IS NULL
              ORDER BY orden, id',
            [$yearId]
        ));
    }

    /**
     * La grada de una fila. Pública porque la enseña la pantalla —el colegio tiene
     * que poder ver **por qué** una fila le gana a otra— y porque el test la fija.
     */
    public static function grada(?int $nivelId, ?int $materiaId): int
    {
        if ($nivelId !== null && $materiaId !== null) {
            return 3;
        }

        if ($nivelId !== null) {
            return 2;
        }

        return $materiaId !== null ? 1 : 0;
    }

    /**
     * @param  list<object>  $candidatas
     * @return list<object>
     */
    private static function soloLaGradaMasAlta(array $candidatas): array
    {
        $mayor = -1;

        foreach ($candidatas as $fila) {
            $grada = self::grada(
                $fila->nivel_educativo_id === null ? null : (int) $fila->nivel_educativo_id,
                $fila->materia_id === null ? null : (int) $fila->materia_id
            );

            if ($grada > $mayor) {
                $mayor = $grada;
            }
        }

        return array_values(array_filter($candidatas, fn ($fila) => self::grada(
            $fila->nivel_educativo_id === null ? null : (int) $fila->nivel_educativo_id,
            $fila->materia_id === null ? null : (int) $fila->materia_id
        ) === $mayor));
    }
}
