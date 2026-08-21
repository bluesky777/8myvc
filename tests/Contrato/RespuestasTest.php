<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La pantalla de corregir: `PUT respuestas/actividad`.
 *
 * Una sola ruta, y es la que más devuelve de todo el dominio: por cada grupo al
 * que se compartió la actividad, **todos sus alumnos** con nombre, foto, si
 * terminaron, su `puntaje_manual` y su comentario. Lleva `auth.personal` desde la
 * la 05 §24, que es lo que la cerró a las familias — pero **nadie había mirado qué
 * responde**, y el método tiene cuatro caminos de los que solo uno funciona.
 *
 * `ws_actividades` está vacía en el seed, así que todo se monta aquí.
 */
class RespuestasTest extends CasoDeContrato
{
    /** Personal del colegio del año que tiene grupos con alumnos. */
    private function docente(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene personal en el año {$grupo->year_id}.");

        return (object) [
            'user_id' => (int) $usuario->id,
            'year_id' => (int) $grupo->year_id,
            'grupo_id' => (int) $grupo->id,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    /**
     * Una actividad compartida con el grupo del alumno, con una pregunta.
     *
     * `$para` elige a qué público va, que es lo que decide el camino que toma
     * `putActividad()`.
     */
    private function actividadCompartida(object $docente, string $para = 'para_alumnos', int $compartida = 1): int
    {
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL
                                     ORDER BY id LIMIT 1', [$docente->grupo_id]);
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($asignatura, 'El grupo del seed no tiene asignaturas.');

        $actividadId = DB::table('ws_actividades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'descripcion' => 'Examen para corregir',
            'tipo' => 'E',
            'compartida' => $compartida,
            $para => 1,
            'created_by' => $docente->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ws_preguntas')->insert([
            'actividad_id' => $actividadId,
            'enunciado' => 'Pregunta única',
            'orden' => 1,
            'tipo_pregunta' => 'Test',
            'puntos' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ws_actividades_compartidas')->insert([
            'actividad_id' => $actividadId,
            'grupo_id' => $docente->grupo_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actividadId;
    }

    /**
     * El camino que funciona: la lista de corregir, con todos los alumnos.
     *
     * Se fija la forma porque es lo que lee la pantalla, y porque hace falta
     * tenerla antes de tocar el bucle de la §5.3 — que es una optimización, y una
     * optimización sin la forma fijada es un cambio a ciegas.
     */
    public function test_la_lista_de_corregir_trae_los_alumnos_del_grupo(): void
    {
        $docente = $this->docente();
        $actividad = $this->actividadCompartida($docente);

        $respuesta = $this->withToken($docente->token)
            ->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad])
            ->assertStatus(200);

        $this->assertSame($actividad, (int) $respuesta->json('actividad.id'));
        $this->assertCount(1, $respuesta->json('grupos'), 'Un solo grupo compartido.');

        $alumnos = $respuesta->json('grupos.0.alumnos');

        $matriculados = DB::table('matriculas')->where('grupo_id', $docente->grupo_id)
            ->whereIn('estado', ['MATR', 'ASIS'])->whereNull('deleted_at')->count();

        $this->assertCount($matriculados, $alumnos,
            'Salen todos los matriculados del grupo, hayan respondido o no.');

        $this->assertArrayHasKey('puntaje_manual', $alumnos[0]);
        $this->assertArrayHasKey('foto_nombre', $alumnos[0]);
    }

    /**
     * Una actividad **sin compartir** responde 500, y siempre lo ha hecho.
     *
     * La rama `else` de `putActividad()` es esto, literal:
     *
     *     $consulta = '';
     *     $alumnos = DB::select($consulta, [$user->year_id]);
     *
     * Una consulta vacía. No es que devuelva mal: es que no se puede ejecutar. El
     * `$alumnos` que sale ni siquiera se usa después.
     *
     * O sea que la pantalla de corregir **solo existe si la actividad está
     * compartida**, y con `compartida = 0` —que es el valor por defecto de la
     * columna— el profesor recibe un 500. Con ruta y roto se documenta.
     */
    public function test_una_actividad_sin_compartir_es_500(): void
    {
        $docente = $this->docente();
        $actividad = $this->actividadCompartida($docente, compartida: 0);

        $this->withToken($docente->token)
            ->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad])
            ->assertStatus(500);
    }

    /**
     * Y las de profesores y acudientes devuelven **una palabra**.
     *
     * `return 'Profesores';` y `return 'Acudientes';`, tal cual. No es un error
     * ni una lista vacía: es el hueco donde nunca se escribió la pantalla, y ha
     * llegado hasta aquí devolviendo una cadena suelta con 200 dentro.
     *
     * Es una respuesta que miente de la manera más literal de todas las que lleva
     * encontradas este repo: el cliente pide la corrección de una actividad de
     * profesores y recibe un 200 con la palabra «Profesores».
     */
    public function test_las_de_profesores_y_acudientes_devuelven_una_palabra(): void
    {
        $docente = $this->docente();

        foreach (['para_profesores' => 'Profesores', 'para_acudientes' => 'Acudientes'] as $campo => $palabra) {
            $actividad = $this->actividadCompartida($docente, para: $campo);

            $this->withToken($docente->token)
                ->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad])
                ->assertStatus(200)
                ->assertSee($palabra);
        }
    }

    /**
     * Compartida pero sin público es un 200 con la actividad y sin nadie.
     *
     * Los tres `if` de dentro no tienen `else`, así que si la actividad está
     * compartida y ninguno de los tres `para_*` está encendido, la ejecución cae
     * hasta el `return $datos` del final, que solo lleva `actividad`. La pantalla
     * de corregir sale sin la clave `grupos`, no vacía: **ausente**.
     */
    public function test_compartida_sin_publico_no_trae_la_clave_grupos(): void
    {
        $docente = $this->docente();
        $actividad = $this->actividadCompartida($docente);

        DB::table('ws_actividades')->where('id', $actividad)->update(['para_alumnos' => 0]);

        $respuesta = $this->withToken($docente->token)
            ->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad])
            ->assertStatus(200);

        $this->assertNull($respuesta->json('grupos'));
        $this->assertNotNull($respuesta->json('actividad'), 'La actividad sí viaja.');
    }

    /** Una actividad que no existe es 500, por el `[0]` de siempre. */
    public function test_una_actividad_que_no_existe_es_500(): void
    {
        $docente = $this->docente();

        $inventada = ((int) DB::table('ws_actividades')->max('id')) + 1000;

        $this->withToken($docente->token)
            ->putJson('/api/respuestas/actividad', ['actividad_id' => $inventada])
            ->assertStatus(500);
    }

    /**
     * Cualquiera del personal corrige el examen de otro.
     *
     * Es la §2 de 13-actividades.md aplicada al sitio donde más pesa: aquí no se
     * edita configuración, **se leen los datos personales de todos los alumnos**
     * de todos los grupos a los que se compartió, con foto y con nota.
     */
    public function test_el_personal_abre_la_correccion_de_otro(): void
    {
        $docente = $this->docente();
        $actividad = $this->actividadCompartida($docente);

        DB::table('ws_actividades')->where('id', $actividad)
            ->update(['created_by' => $docente->user_id + 1]);

        $this->withToken($docente->token)
            ->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad])
            ->assertStatus(200)
            ->assertJsonCount(1, 'grupos');
    }

    /**
     * El bucle que hace el mismo trabajo N veces, medido.
     *
     * `putActividad()` tiene dos `for` anidados y **el de dentro no usa el índice
     * del de fuera**: el exterior recorre `count($grupos)` y el interior vuelve a
     * lanzar la MISMA consulta de grupos y a recorrerlos todos otra vez. Cada
     * vuelta pisa `$grupos` con un resultado idéntico, así que el resultado final
     * es correcto — y por eso no lo ha notado nadie.
     *
     * Lo que cuesta es cuadrático: con G grupos compartidos, la consulta de
     * alumnos —que es la cara, con cinco joins y todos los matriculados— se
     * ejecuta **G × G veces en vez de G**. Con tres grupos son nueve.
     *
     * Se mide en vez de leerse, porque «esto parece O(n²)» es justo el tipo de
     * afirmación que este repo pide comprobar. No se arregla aquí: el arreglo es
     * borrar el bucle exterior, y va junto a la forma de la respuesta que fija
     * `test_la_lista_de_corregir_trae_los_alumnos_del_grupo`.
     */
    public function test_la_consulta_de_alumnos_se_repite_al_cuadrado(): void
    {
        $docente = $this->docente();
        $actividad = $this->actividadCompartida($docente);

        // Dos grupos más, para que G valga 3.
        foreach ([1, 2] as $i) {
            $otro = $this->grupoAjenoDelMismoAnio($docente->year_id);

            DB::table('ws_actividades_compartidas')->insert([
                'actividad_id' => $actividad,
                'grupo_id' => $otro->grupo_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $consultasDeAlumnos = 0;

        DB::listen(function ($consulta) use (&$consultasDeAlumnos) {
            if (str_contains($consulta->sql, 'ws_actividades_resueltas ar on ar.persona_id')) {
                $consultasDeAlumnos++;
            }
        });

        $this->withToken($docente->token)
            ->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad])
            ->assertStatus(200)
            ->assertJsonCount(3, 'grupos');

        $this->assertSame(9, $consultasDeAlumnos,
            'Tres grupos compartidos deberían costar tres consultas de alumnos, no nueve. '.
            'Si esto baja a 3, alguien borró el bucle exterior: bórrese este test.');
    }
}
