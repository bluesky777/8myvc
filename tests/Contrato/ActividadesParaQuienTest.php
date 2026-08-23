<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §104 — «Para quién es esta actividad»: los dos interruptores que faltaban, y su
 * ruta de guardar, que no puede funcionar nunca.
 *
 * La [§74](../../docs/migracion/05-codigo-muerto-y-roto.md) midió `para_alumnos`
 * abriendo el examen con un token de alumno y encontró que **no cierra nada**: con
 * el interruptor apagado el alumno abre el examen igual, porque
 * `exigirQueLaActividadLeCorresponda` no lo mira. Los dos de este lote son sus
 * gemelos, y la pregunta era la misma: **¿decide `para_acudientes` algo para el
 * acudiente, y `para_profesores` algo para el profesor?**
 *
 * La respuesta es que **no**, las dos veces, y medida igual: abriendo la actividad
 * con el token de cada uno, no leyendo el `WHERE`. Los tres interruptores de la
 * familia esconden en el listado del profesor y no cierran ninguna puerta.
 *
 * > Que la respuesta coincida con la de su hermana **no la hacía predecible**: la
 * > comprobación que cerró el lado del alumno trata `Alumno` y `Acudiente` en la
 * > misma rama, pero al profesor lo deja salir antes por otro camino, así que las
 * > tres podrían haber salido distintas. Lo que confirma es la forma, no el valor.
 *
 * Y `mis-actividades/guardar` —la que quedaba sin comprobar de este controlador—
 * resultó ser lo mismo que `piars-config/config` (§102): **un endpoint enrutado
 * que no puede funcionar nunca**, y por la misma causa.
 */
class ActividadesParaQuienTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($super, 'El seed no tiene superusuario.');

        return $this->tokenDe($super->username);
    }

    /** Un acudiente del año actual, con un acudido matriculado y una asignatura suya. */
    private function acudienteConAcudido(): object
    {
        $fila = DB::selectOne('SELECT u.username, asi.id AS asignatura_id
            FROM acudientes ac
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN parentescos pa ON pa.acudiente_id = ac.id AND pa.deleted_at IS NULL
            INNER JOIN alumnos a ON a.id = pa.alumno_id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN asignaturas asi ON asi.grupo_id = g.id AND asi.deleted_at IS NULL
            WHERE ac.deleted_at IS NULL ORDER BY ac.id LIMIT 1');

        $this->assertNotNull($fila,
            'El seed necesita un acudiente con un acudido matriculado en el año actual.');

        return $fila;
    }

    /** Creada por la API y **abierta**, que es el único interruptor que sí cierra. */
    private function actividadAbiertaEn(string $token, int $asignaturaId): int
    {
        $r = $this->withToken($token)->postJson('/api/actividades/crear', ['asignatura_id' => $asignaturaId]);
        $r->assertStatus(201);

        $id = (int) $r->json('id');

        DB::update('UPDATE ws_actividades SET in_action = 1, inicia_at = NULL, compartida = 1,
            para_alumnos = 1, para_profesores = 1, para_acudientes = 1 WHERE id = ?', [$id]);

        return $id;
    }

    /**
     * §104.1 — Apagar `para_acudientes` no le cierra nada a un acudiente.
     *
     * Es la §74 sobre la otra mitad de la familia. Lo que decide es `in_action`,
     * y este interruptor solo se lee en los listados del profesor
     * (`actividades/compartidas` y la pantalla de corregir).
     *
     * **Se fija y no se juzga**, por lo mismo que su hermana: hacerlo cerrar es una
     * línea, y esconde de golpe actividades que hoy se ven en dieciséis colegios.
     */
    public function test_apagar_para_acudientes_no_le_cierra_nada_al_acudiente(): void
    {
        $acudiente = $this->acudienteConAcudido();
        $personal = $this->tokenDeSuperusuario();
        $actividad = $this->actividadAbiertaEn($personal, (int) $acudiente->asignatura_id);
        $token = $this->tokenDe($acudiente->username);

        $this->withToken($token)->putJson('/api/mis-actividades/mi-actividad',
            ['actividad_id' => $actividad])->assertStatus(200);

        $this->olvidarControladores();

        $this->withToken($personal)->putJson('/api/actividades/para-acudientes-toggle',
            ['actividad_id' => $actividad, 'para_acudientes' => 0])->assertStatus(200);

        $this->assertSame(0, (int) DB::table('ws_actividades')->where('id', $actividad)->value('para_acudientes'),
            'El interruptor sí se escribe: lo que no hace es decidir.');

        // Con `para_acudientes` apagado el acudiente sigue abriendo la actividad:
        // esa es toda la sección, y es el mismo 200 de antes de apagarlo.
        $this->withToken($token)->putJson('/api/mis-actividades/mi-actividad',
            ['actividad_id' => $actividad])->assertStatus(200);
    }

    /** Y `para_profesores` tampoco le cierra nada a un profesor. */
    public function test_apagar_para_profesores_no_le_cierra_nada_al_profesor(): void
    {
        $acudiente = $this->acudienteConAcudido();
        $personal = $this->tokenDeSuperusuario();
        $actividad = $this->actividadAbiertaEn($personal, (int) $acudiente->asignatura_id);
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $this->withToken($token)->putJson('/api/mis-actividades/mi-actividad',
            ['actividad_id' => $actividad])->assertStatus(200);

        $this->olvidarControladores();

        $this->withToken($personal)->putJson('/api/actividades/para-profesores-toggle',
            ['actividad_id' => $actividad, 'para_profesores' => 0])->assertStatus(200);

        $this->assertSame(0, (int) DB::table('ws_actividades')->where('id', $actividad)->value('para_profesores'));

        // Y aquí ni siquiera llega a mirarse: la comprobación devuelve antes para
        // todo el que no es Alumno ni Acudiente. Un profesor abre lo que sea.
        $this->withToken($token)->putJson('/api/mis-actividades/mi-actividad',
            ['actividad_id' => $actividad])->assertStatus(200);
    }

    /**
     * Los dos conmutadores, sin el valor, **apagan**. Y es al revés que en votaciones.
     *
     * `Request::input('para_acudientes')` sin defecto devuelve null, y la columna
     * lo recibe como 0 porque `config/database.php` lleva `'strict' => false`.
     * Los conmutadores de `vt_votaciones`, que son el mismo patrón, llevan
     * `Request::input('x', true)` y **encienden** en el mismo caso (§101).
     *
     * O sea: dos módulos, el mismo «guardar un interruptor suelto», y **el cuerpo
     * incompleto hace lo contrario en cada uno**. Ninguno de los dos está mal por
     * sí solo; lo que no existe es un criterio.
     */
    public function test_el_conmutador_sin_el_valor_apaga(): void
    {
        $acudiente = $this->acudienteConAcudido();
        $personal = $this->tokenDeSuperusuario();
        $actividad = $this->actividadAbiertaEn($personal, (int) $acudiente->asignatura_id);

        foreach ([['para-acudientes-toggle', 'para_acudientes'], ['para-profesores-toggle', 'para_profesores']] as [$ruta, $columna]) {
            DB::table('ws_actividades')->where('id', $actividad)->update([$columna => 1]);

            $this->withToken($personal)->putJson('/api/actividades/'.$ruta,
                ['actividad_id' => $actividad])->assertStatus(200);

            $this->assertSame(0, (int) DB::table('ws_actividades')->where('id', $actividad)->value($columna),
                "`{$ruta}` sin el valor apaga el interruptor.");
        }
    }

    /** Sin `actividad_id`: 404, que es lo honesto. `findOrFail(null)` no encuentra nada. */
    public function test_el_conmutador_sin_actividad_es_404(): void
    {
        $personal = $this->tokenDeSuperusuario();

        $this->withToken($personal)->putJson('/api/actividades/para-acudientes-toggle',
            ['para_acudientes' => 1])->assertStatus(404);
        $this->withToken($personal)->putJson('/api/actividades/para-profesores-toggle',
            ['para_profesores' => 1])->assertStatus(404);
    }

    /** Y una familia no mueve ninguno de los dos. */
    public function test_una_familia_no_mueve_los_interruptores(): void
    {
        $acudiente = $this->acudienteConAcudido();
        $personal = $this->tokenDeSuperusuario();
        $actividad = $this->actividadAbiertaEn($personal, (int) $acudiente->asignatura_id);

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/actividades/para-acudientes-toggle',
                ['actividad_id' => $actividad, 'para_acudientes' => 0])->assertStatus(403);
            $this->withToken($token)->putJson('/api/actividades/para-profesores-toggle',
                ['actividad_id' => $actividad, 'para_profesores' => 0])->assertStatus(403);
            $this->withToken($token)->putJson('/api/mis-actividades/guardar',
                ['id' => $actividad, 'descripcion' => 'X'])->assertStatus(403);
        }

        $this->assertSame(1, (int) DB::table('ws_actividades')->where('id', $actividad)->value('para_acudientes'));
    }

    /**
     * §104.2 — `mis-actividades/guardar` no puede funcionar nunca: 500 por una
     * columna que no existe.
     *
     * Es **una copia de `actividades/guardar`** —los trece campos, en el mismo
     * orden, con las mismas cuatro líneas alrededor— con **uno cambiado**:
     * `tipo_calificacion` por `puntaje_por_promedio`, que **no es una columna de
     * `ws_actividades`**. El modelo lo tiene anotado como lo que es, «el puntaje
     * calculado al resolver la actividad», o sea un atributo que el código le
     * cuelga al objeto para armar la respuesta — nunca algo que se guarde.
     *
     * Eloquent no distingue: mete el nombre en el `UPDATE` y MySQL contesta
     * `Unknown column 'puntaje_por_promedio' in 'field list'`. Toda la petición
     * cae, así que **no escribe nada** — ni siquiera la descripción, que sí venía.
     *
     * Y por eso este es el segundo de la noche con la misma forma que
     * `piars-config/config` (§102): **enrutado, alcanzable, y roto desde el primer
     * día por un copiar-y-pegar que nadie ejecutó**. Los dos se ven en un segundo
     * llamándolos y en ninguno leyéndolos: el código parece correcto.
     *
     * > **No se arregla.** El nombre bueno no se puede deducir: si fuera
     * > `tipo_calificacion` —lo que dice su hermana— esta ruta pasaría a escribir
     * > trece campos que hoy no escribe, y con el criterio de su hermana, que es
     * > **vaciar lo que no venga en el cuerpo**. Convertir un 500 en un vaciado
     * > silencioso de exámenes en dieciséis colegios no es un arreglo. Va a
     * > PARA JOSETH junto con la §102.
     */
    public function test_guardar_desde_mis_actividades_revienta_y_no_escribe(): void
    {
        $acudiente = $this->acudienteConAcudido();
        $personal = $this->tokenDeSuperusuario();
        $actividad = $this->actividadAbiertaEn($personal, (int) $acudiente->asignatura_id);

        DB::table('ws_actividades')->where('id', $actividad)->update([
            'descripcion' => 'Examen del primer periodo',
            'contenido' => 'El enunciado largo del examen',
            'oportunidades' => 3,
            'duracion_exam' => 45,
            'tipo_calificacion' => 'Por puntos',
        ]);
        $antes = DB::table('ws_actividades')->where('id', $actividad)->first();

        $r = $this->withToken($personal)->putJson('/api/mis-actividades/guardar', [
            'id' => $actividad,
            'descripcion' => 'Cambiada',
            'contenido' => 'Otro enunciado',
            'in_action' => 1,
            'oportunidades' => 5,
            'duracion_preg' => 60,
            'duracion_exam' => 45,
            'compartida' => 1,
            'can_upload' => 0,
            'tipo' => 'Examen',
            'one_by_one' => 0,
            'puntaje_por_promedio' => 1,
        ]);

        $r->assertStatus(500);
        $this->assertStringContainsString("Unknown column 'puntaje_por_promedio'", (string) $r->json('message'),
            'El 500 exacto. Si cambia, alguien tocó el método — y entonces hay que mirar si ya escribe.');

        $this->assertEquals($antes, DB::table('ws_actividades')->where('id', $actividad)->first(),
            'La petición cae entera: ni la descripción, que sí venía, llega a la fila.');
    }

    /** Con el cuerpo entero tampoco, que es lo que descarta que sea un cuerpo incompleto. */
    public function test_ni_con_el_cuerpo_minimo_ni_sin_el_campo_que_sobra(): void
    {
        $acudiente = $this->acudienteConAcudido();
        $personal = $this->tokenDeSuperusuario();
        $actividad = $this->actividadAbiertaEn($personal, (int) $acudiente->asignatura_id);

        $this->withToken($personal)->putJson('/api/mis-actividades/guardar',
            ['id' => $actividad])->assertStatus(500);

        $this->withToken($personal)->putJson('/api/mis-actividades/guardar',
            ['id' => $actividad, 'descripcion' => 'Solo el título'])->assertStatus(500);
    }

    /**
     * Su hermana `actividades/guardar` sí funciona — y por eso el contraste importa.
     *
     * La misma llamada, en la ruta de al lado, contesta 200 **y deja el examen en
     * blanco** (13-actividades §1, ya fijado por `ActividadesTest`). O sea que de
     * las dos hermanas, una revienta sin escribir y la otra escribe de más. Aquí
     * solo se comprueba que siguen siendo dos comportamientos distintos: si algún
     * día coinciden, es que alguien tocó una de las dos.
     */
    public function test_las_dos_hermanas_no_se_comportan_igual(): void
    {
        $acudiente = $this->acudienteConAcudido();
        $personal = $this->tokenDeSuperusuario();
        $actividad = $this->actividadAbiertaEn($personal, (int) $acudiente->asignatura_id);

        DB::table('ws_actividades')->where('id', $actividad)->update(['contenido' => 'El enunciado']);

        $this->withToken($personal)->putJson('/api/mis-actividades/guardar',
            ['id' => $actividad, 'descripcion' => 'Por la de mis-actividades'])->assertStatus(500);
        $this->assertSame('El enunciado',
            DB::table('ws_actividades')->where('id', $actividad)->value('contenido'));

        $this->withToken($personal)->putJson('/api/actividades/guardar',
            ['id' => $actividad, 'descripcion' => 'Por la de actividades'])->assertStatus(200);
        $this->assertNull(DB::table('ws_actividades')->where('id', $actividad)->value('contenido'),
            'La hermana que funciona es la que borra el enunciado.');
    }
}
