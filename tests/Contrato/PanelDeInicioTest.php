<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Lo que el panel de inicio dejó de hacer el 2 sep 2026.
 *
 * `GET api/ChangesAsked/to-me` es la primera pantalla de los cuatro tipos y el
 * endpoint más caro de la aplicación. Medido ese día sobre la copia del
 * contenedor (24-el-panel-de-inicio.md §1), antes de estos dos recortes:
 *
 *     Usuario     39 consultas · ~700 ms · 274 KB
 *     Profesor    75 consultas ·  ~30 ms · 279 KB
 *     Alumno      49 consultas · ~620 ms · 225 KB
 *
 * Los dos arreglos que este test fija atacan las dos columnas distintas —el
 * tiempo de uno y el trabajo del otro— y **ninguno cambia la forma de la
 * respuesta**: las diez claves siguen ahí.
 *
 * ## Y por qué se cuentan consultas y no milisegundos
 *
 * Mismo criterio que `BoletinFinalConsultaInvarianteTest`: un aserto de
 * milisegundos depende de la máquina y el de consultas no. Con la trampa que
 * aquel test dejó escrita y que aquí también decide el resultado: **un oyente que
 * no se engancha cuenta cero y parece un éxito**. Por eso cada cota va con su
 * mitad de población — que el oyente haya visto trabajo de verdad.
 */
class PanelDeInicioTest extends CasoDeContrato
{
    /**
     * El alumno recibe la clave, y vacía.
     *
     * Antes traía los dieciséis docentes contratados **con su `porcentaje`**, que
     * es lo al día que va cada uno con su planeación: un indicador de desempeño
     * del profesorado, en la pantalla de inicio de un alumno, que no pinta ningún
     * cliente —en la aplicación vieja el recuadro va bajo
     * `hasRoleOrPerm(['admin','profesor'])`, en `app2` bajo `visible() = admin ||
     * profesor`, y `myvc_flutter` no lee la clave—. Calcularlo eran dos consultas
     * agregadas por docente y **los ~620 ms enteros de su panel**.
     *
     * **Las dos mitades, y la segunda es la que hace que esto mida algo**: que al
     * alumno le llegue vacío, y que al superusuario le siga llegando lleno. Sin la
     * segunda, un seed sin docentes contratados daría verde con el código viejo.
     */
    public function test_el_panel_de_un_alumno_no_trae_el_avance_de_sus_docentes(): void
    {
        $delAlumno = $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->getJson('/api/ChangesAsked/to-me')
            ->assertStatus(200);

        $delAlumno->assertJsonPath('profes_actuales', []);

        $delSuperusuario = $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
            ->getJson('/api/ChangesAsked/to-me')
            ->assertStatus(200)
            ->json('profes_actuales');

        $this->assertNotEmpty(
            $delSuperusuario,
            'El seed no le da docentes contratados a nadie, así que el vacío del alumno no demuestra nada. '.
            'Sin esta mitad el test pasaría igual con el código de antes.'
        );

        $this->assertArrayHasKey(
            'porcentaje',
            (array) $delSuperusuario[0],
            'El avance sigue calculándose para quien sí lo pinta: esto no era quitar la función, era quitarla de una pantalla.'
        );
    }

    /**
     * El horario del docente sale en dos consultas, no en dos por asignatura.
     *
     * Era un N+1 de dos pisos —una consulta de unidades por asignatura y una de
     * subunidades por unidad—, y para el docente medido eran **60 de sus 75
     * consultas**. Después: 17.
     *
     * La cota es «como mucho una por llamada al horario», y las llamadas son dos
     * —hoy y mañana—. No se fija en una igualdad a propósito: cuántas de las dos
     * traen asignaturas depende del día de la semana, y un test que dependa del
     * día es un test que falla los sábados.
     */
    public function test_el_horario_del_docente_no_vuelve_al_n_mas_uno(): void
    {
        $profesor = $this->docenteConHorarioMontado();

        // El filtro por día lo decide la columna del año, y en el seed las siete
        // columnas de día están vacías: sin esto el horario vuelve vacío y no hay
        // N+1 que contar. Enciende la rama de «enséñalo todo», que es una de las
        // dos que el controlador tiene y está usada en otros años del colegio.
        DB::update('UPDATE years SET show_materias_todas = 1 WHERE id = ?', [$profesor->year_id]);

        $unidades = 0;
        $subunidades = 0;

        DB::listen(function ($consulta) use (&$unidades, &$subunidades) {
            if (str_contains($consulta->sql, 'FROM unidades')) {
                $unidades++;
            }
            if (str_contains($consulta->sql, 'FROM subunidades')) {
                $subunidades++;
            }
        });

        $horario = $this->withToken($this->tokenDe($profesor->username))
            ->getJson('/api/ChangesAsked/to-me')
            ->assertStatus(200)
            ->json('horario_hoy');

        // La población primero: sin ella, «0 consultas de unidades» es el mejor
        // resultado posible y también el de no haber medido nada.
        $conUnidades = 0;
        $conSubunidades = 0;

        foreach ($horario as $asignatura) {
            foreach ($asignatura['unidades'] ?? [] as $unidad) {
                $conUnidades++;
                $conSubunidades += count($unidad['subunidades'] ?? []);
            }
        }

        $this->assertGreaterThanOrEqual(4, count($horario),
            'El docente elegido tiene que dictar varias asignaturas o el N+1 no multiplica nada.');
        $this->assertGreaterThanOrEqual(16, $conUnidades,
            'Y varias unidades por asignatura: es el piso de dentro del bucle viejo.');
        $this->assertGreaterThan(0, $conSubunidades,
            'Y al menos una subunidad, que es el piso más hondo y el que más consultas costaba.');

        $this->assertLessThanOrEqual(2, $unidades,
            "El horario volvió a pedir las unidades asignatura por asignatura: {$unidades} consultas.");
        $this->assertLessThanOrEqual(2, $subunidades,
            "El horario volvió a pedir las subunidades unidad por unidad: {$subunidades} consultas.");
    }

    /**
     * El docente del seed que tiene de verdad unidades y subunidades en su periodo.
     *
     * No vale el primero: `usuarioDeTipo('Profesor')` devuelve el de menor id, y
     * ése tiene **cero** asignaturas en el seed. Con él las dos cotas de arriba se
     * cumplen contando cero, que es justo el fallo contra el que este test lleva
     * su mitad de población.
     */
    private function docenteConHorarioMontado(): object
    {
        $fila = DB::selectOne('SELECT u.username, g.year_id, count(distinct s.id) as subunidades
            FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.profesor_id = pr.id AND a.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.periodo_id = u.periodo_id
                AND un.deleted_at IS NULL AND un.alumno_id IS NULL
            INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            GROUP BY u.username, g.year_id
            ORDER BY subunidades DESC, u.username
            LIMIT 1');

        $this->assertNotNull($fila,
            "Ningún docente del seed tiene unidades con subunidades en su periodo.\n".
            'Regenéralo con: php tools/generar-seed-test.php');

        return $fila;
    }
}
