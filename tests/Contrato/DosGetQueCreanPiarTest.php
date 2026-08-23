<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los dos GET que crean PIAR, y el que se separó de su gemelo — §147.
 *
 * Los destapó la curva de profundidad: **ninguno de los dos es visible para un
 * barrido en línea**, porque la escritura vive un salto más allá, en las clases
 * de `Piars/Utils`. Y los dos llevan el nombre diciéndolo —
 * `getCreatePiarAsignatura`, `getAlumnosPiar`— lo que hace que **el detector
 * fuera el que no llegaba, no el código el que se escondía**.
 *
 * El PIAR es el Plan Individual de Ajustes Razonables: el documento de un alumno
 * con necesidades educativas especiales. Que una fila suya nazca sola importa más
 * que en otras tablas.
 *
 * **Y aquí las dos gemelas no hacen lo mismo, que es el hallazgo:**
 *
 * ```
 * PiarsAlumnoUtils::getAlumnosPiar          if ($alumnoGrupo->nee) { INSERT }   <- comprueba
 * PiarsAsignaturasUtils::getCreatePiarAsignatura   INSERT sin mirar nada        <- no comprueba
 * ```
 *
 * La primera solo le abre el PIAR a quien ya está marcado con `nee = 1`, que es
 * mecanismo: el contenedor del documento de quien lo necesita. La segunda lo crea
 * **para el `alumno_id` que llegue por la URL**, tenga `nee` o no.
 */
class DosGetQueCreanPiarTest extends CasoDeContrato
{
    /**
     * La del grupo solo le abre PIAR a quien tiene `nee`. Eso es mecanismo.
     *
     * Va primero porque es la que demuestra que **el criterio existe en este
     * módulo**: sin ella, lo de abajo se leería como «así se hace aquí» en vez de
     * como una divergencia entre dos copias.
     */
    public function test_la_del_grupo_solo_le_abre_piar_a_quien_tiene_nee(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $grupo = $this->grupoConAlumnos();

        $sinNee = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND (a.nee IS NULL OR a.nee = 0) ORDER BY a.id LIMIT 1',
            [$grupo->id]);

        $this->assertNotNull($sinNee, 'El grupo elegido no tiene ningún alumno sin `nee`.');

        DB::table('piars_alumnos')->where('alumno_id', $sinNee->id)->delete();

        $this->withToken($token)->getJson('/api/piars-grupos/contexto-de-grupo/'.$grupo->id)
            ->assertStatus(200);

        $this->assertSame(0,
            DB::table('piars_alumnos')->where('alumno_id', $sinNee->id)->count(),
            'Abrir el contexto del grupo le abrió PIAR a un alumno sin `nee` — §147.');
    }

    /**
     * Y la de asignaturas se lo crea a cualquiera, `nee` o no.
     *
     * Se mide sobre un alumno **sin** `nee` a propósito: es el caso en el que las
     * dos gemelas discrepan, y el único que distingue «así se hace aquí» de «a
     * ésta se le olvidó la comprobación».
     *
     * No se arregla aquí: añadirle el `if ($alumno->nee)` **apagaría la pantalla
     * de PIAR por asignatura** para cualquier alumno que el colegio esté
     * valorando y todavía no haya marcado, y eso es una decisión del colegio
     * sobre su propio procedimiento. Queda medido y anotado.
     */
    public function test_la_de_asignaturas_le_crea_piar_a_cualquiera(): void
    {
        // **Token de `Usuario`, no de profesor, y no es un detalle del test.** Las
        // dos ramas de `getAsignaturas` no alcanzan lo mismo: la de `Profesor`
        // recorre **sus** asignaturas —`Profesor::asignaturas($year, $persona)`— y
        // la de `Usuario` recorre **todas las del grupo**. Medido: con token de
        // profesor del seed se crean **cero** filas, porque no da clase en ese
        // grupo. Un test escrito con el token de profesor habría dado verde
        // diciendo que no escribe.
        $token = $this->tokenDelPersonalLlano();
        $grupo = $this->grupoConAlumnos();

        $sinNee = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND (a.nee IS NULL OR a.nee = 0) ORDER BY a.id LIMIT 1',
            [$grupo->id]);

        DB::table('piars_asignaturas')->where('alumno_id', $sinNee->id)->delete();

        $r = $this->withToken($token)
            ->getJson('/api/piars-asignaturas/asignaturas/'.$grupo->id.'/'.$sinNee->id);
        $r->assertStatus(200);

        $creadas = DB::table('piars_asignaturas')->where('alumno_id', $sinNee->id)->count();

        $this->assertGreaterThan(0, $creadas,
            'Dejó de crear PIAR de asignatura a un alumno sin `nee`: si se cerró, este test se '
            .'cambia y se dice qué se decidió — §147.');
    }

    /**
     * Y para una familia revienta: `$asignaturas` no se define.
     *
     * `getAsignaturas` rellena `$asignaturas` en dos ramas —`Profesor` y
     * `Usuario`— y **no tiene `else`**. La ruta lleva `persona.propia`, que deja
     * pasar a un alumno **sobre lo suyo** y a un acudiente sobre sus acudidos, así
     * que esas dos ramas llegan al `count($asignaturas)` con la variable sin
     * definir: **error fatal en PHP 8**.
     *
     * O sea que el guard hace su trabajo —comprueba que el alumno es suyo— y lo
     * que hay al otro lado no sabe atenderlo. Es la familia de «variables sin
     * definir» del 05, y aquí con un guard delante que la hace parecer una ruta
     * pensada para familias.
     */
    public function test_para_una_familia_la_de_asignaturas_revienta(): void
    {
        $cuenta = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($cuenta->username);
        $alumnoId = (int) DB::table('alumnos')->where('user_id', $cuenta->id)->value('id');

        $grupo = DB::selectOne('SELECT m.grupo_id FROM matriculas m
            WHERE m.alumno_id = ? AND m.deleted_at IS NULL LIMIT 1', [$alumnoId]);

        $this->assertNotNull($grupo, 'Esa cuenta de alumno no tiene matrícula.');

        $antes = DB::table('piars_asignaturas')->where('alumno_id', $alumnoId)->count();

        $this->assertSame(500,
            $this->withToken($token)
                ->getJson('/api/piars-asignaturas/asignaturas/'.$grupo->grupo_id.'/'.$alumnoId)
                ->status(),
            'La rama de familia dejó de dar 500: si se arregló, hay que decir qué devuelve '
            .'ahora, porque el guard la deja pasar a propósito — §147.');

        $this->assertSame($antes,
            DB::table('piars_asignaturas')->where('alumno_id', $alumnoId)->count(),
            'Reventó después de haber escrito: entonces escribe antes del fatal y el orden '
            .'importa — §147.');
    }

    /** Repetir no duplica: las dos crean lo que falta y nada más. */
    public function test_ninguna_de_las_dos_duplica(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $grupo = $this->grupoConAlumnos();

        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id]);

        $ruta = '/api/piars-asignaturas/asignaturas/'.$grupo->id.'/'.$alumno->id;

        $this->withToken($token)->getJson($ruta)->assertStatus(200);
        $primera = DB::table('piars_asignaturas')->where('alumno_id', $alumno->id)->count();

        $this->withToken($token)->getJson($ruta)->assertStatus(200);
        $segunda = DB::table('piars_asignaturas')->where('alumno_id', $alumno->id)->count();

        $this->assertSame($primera, $segunda,
            "Abrir dos veces el PIAR por asignatura pasó de {$primera} a {$segunda} filas — §147.");
    }
}
