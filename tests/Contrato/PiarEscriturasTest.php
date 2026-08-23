<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las escrituras del PIAR: el acta de acuerdo, el campo de una asignatura, la
 * caracterización de un grupo y la configuración del módulo.
 *
 * `PiarTest` ya fijaba que una familia no toca nada y que el personal sí escribe.
 * Lo que faltaba de estas cuatro era **qué contestan cuando el cuerpo viene
 * incompleto**, y ahí las cuatro dan cosas distintas: 422, 400, 200 con el número
 * de filas tocadas, y **500 siempre** (§102).
 */
class PiarEscriturasTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /**
     * El seed no trae ni una fila de `piars_asignaturas` ni de `piars_grupos`
     * —solo alumnos con NEE—, así que estos tests se fabrican las suyas, como
     * `BitacorasTest`. Es un hueco del seed, no del código: la pantalla del PIAR
     * las crea al abrirse por primera vez.
     */
    private function unaAsignaturaDelPiar(): object
    {
        $fila = DB::selectOne('SELECT asi.id AS asignatura_id, asi.grupo_id, g.year_id, m.alumno_id
            FROM asignaturas asi
            INNER JOIN grupos g ON g.id = asi.grupo_id AND g.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE asi.deleted_at IS NULL ORDER BY asi.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene una asignatura con alumnos matriculados.');

        DB::table('piars_asignaturas')->insert([
            'asignatura_id' => $fila->asignatura_id,
            'alumno_id' => $fila->alumno_id,
            'year' => $fila->year_id,
            'apoyo_razonable' => '<p>Lo que hubiera antes</p>',
            'seguimientos' => null,
            'updated_by' => 1,
        ]);

        return DB::table('piars_asignaturas')->orderByDesc('id')->first();
    }

    private function unGrupoDelPiar(): object
    {
        $grupo = $this->grupoConAlumnos();

        DB::table('piars_grupos')->insert([
            'grupo_id' => $grupo->id,
            'titular_id' => 0,
            'year_id' => $grupo->year_id,
            'caracterizacion_grupo' => '<p>Lo que hubiera antes</p>',
        ]);

        return DB::table('piars_grupos')->orderByDesc('id')->first();
    }

    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($super, 'El seed no tiene superusuario.');

        return $this->tokenDe($super->username);
    }

    /**
     * §102 — `piars-config/config` no puede funcionar nunca: 500 `Undefined
     * variable $field`.
     *
     * El método es **restos de dos copiar-y-pegar**. La mitad de arriba es el
     * bloque de `$validFields` de `piars-asignaturas/field`, donde sí tiene
     * sentido —allí el nombre de la columna llega del cliente—; aquí se copió con
     * `['documento1','documento2']` dentro y **sin la línea que define `$field`**.
     * La mitad de abajo es el `UPDATE` de `piars-actas-acuerdo/document`, con sus
     * cuatro variables —`$fullPath`, `$arr`, `$alumno_id`— que aquí tampoco
     * existen. Y por si algo de eso se arreglara, la consulta filtra por
     * `year_id`, que **no es una columna de `piars_config`**.
     *
     * Entre medias, el método calcula `$reporte_default` y `$config` con cuidado y
     * no los usa. O sea: **tres razones independientes por las que no puede
     * escribir**, y ninguna se ve sin ejecutarla.
     *
     * > **No se arregla, y no es pereza.** «Con ruta y roto se documenta»: borrarla
     * > convierte un 500 en un 404 sin decirle a nadie qué pretendía esa pantalla, y
     * > escribirla de nuevo sería inventar un endpoint que **no llama ningún
     * > cliente de los cuatro** —en `front_2` la única llamada está comentada
     * > (§35.2)—, sin nadie a quien preguntarle qué debía guardar. Va a PARA JOSETH.
     *
     * Lo que sí queda cerrado con esto: **no escribe nada.** Reventar antes de
     * llegar a la consulta es lo único bueno que tiene.
     */
    public function test_la_configuracion_del_piar_revienta_y_no_escribe(): void
    {
        $token = $this->tokenDeSuperusuario();
        $antes = DB::table('piars_config')->get()->toArray();

        $r = $this->withToken($token)->putJson('/api/piars-config/config', [
            'id' => DB::table('piars_config')->value('id'),
            'reporte_default' => '<h2>Otra plantilla</h2>',
            'config' => '{"a":1}',
        ]);

        $r->assertStatus(500);
        $this->assertSame('Undefined variable $field', $r->json('message'),
            'El 500 exacto. Si cambia, es que alguien tocó el método — y entonces hay que mirar si ya escribe.');

        $this->assertEquals($antes, DB::table('piars_config')->get()->toArray(),
            'Revienta antes de la consulta: la configuración del PIAR se queda como estaba.');
    }

    /** Y la validación de arriba sí funciona: sin `id`, 422 antes de reventar. */
    public function test_la_configuracion_del_piar_pide_el_id_antes_de_reventar(): void
    {
        $this->withToken($this->tokenDeSuperusuario())
            ->putJson('/api/piars-config/config', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id']);
    }

    /**
     * El criterio de quién entra sí quedó bien, y es lo que arregló la §35.2.
     *
     * Antes decía `if ($this->user->is_superuser)` **sin `return` y con la
     * condición al revés**. Ahora es superusuario o Secretario. Se comprueba con
     * alguien del personal que no sea ninguno de los dos — que existe, y es la
     * mayoría del colegio.
     */
    public function test_el_personal_de_a_pie_no_entra_en_la_configuracion_del_piar(): void
    {
        $comun = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND (u.is_superuser = 0 OR u.is_superuser IS NULL)
              AND u.id NOT IN (SELECT ru.user_id FROM role_user ru
                               INNER JOIN roles r ON r.id = ru.role_id AND r.name = "Secretario")
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($comun, 'El seed no tiene personal sin superusuario ni Secretario.');

        $this->withToken($this->tokenDe($comun->username))
            ->putJson('/api/piars-config/config', ['id' => 1])
            ->assertStatus(403);
    }

    /**
     * `piars-asignaturas/field` corta por lista blanca **antes** de tocar nada.
     *
     * Aquí el nombre de la columna sí llega del cliente y se concatena, así que la
     * lista es la defensa. Devuelve **400** con `{"error":"Invalid"}`, que no es el
     * 422 que usaría el código nuevo — se fija tal cual porque es lo que el front
     * lee hoy.
     */
    public function test_el_campo_de_una_asignatura_del_piar_solo_admite_dos_nombres(): void
    {
        $token = $this->tokenDelPersonal();
        $fila = $this->unaAsignaturaDelPiar();

        foreach (['otro_campo', 'apoyo_razonable=1, seguimientos', 'id', ''] as $campo) {
            $this->withToken($token)->putJson('/api/piars-asignaturas/field',
                ['id' => $fila->id, 'field' => $campo, 'text' => 'x'])
                ->assertStatus(400)
                ->assertExactJson(['error' => 'Invalid']);
        }

        $this->assertEquals($fila, DB::table('piars_asignaturas')->where('id', $fila->id)->first());
    }

    /** Los dos que sí admite escriben, y el HTML del editor pasa por el limpiador. */
    public function test_el_campo_de_una_asignatura_guarda_y_limpia_el_html(): void
    {
        $token = $this->tokenDelPersonal();
        $fila = $this->unaAsignaturaDelPiar();

        $r = $this->withToken($token)->putJson('/api/piars-asignaturas/field', [
            'id' => $fila->id,
            'field' => 'apoyo_razonable',
            'text' => '<p>Apoyo <b>continuo</b></p><script>alert(1)</script>',
        ]);

        // 200 con el NÚMERO DE FILAS TOCADAS dentro. Es lo contrario de las cuatro
        // de `ordinales` (§87), que dicen «Cambiado» sin haber tocado nada: aquí la
        // respuesta se puede creer porque trae el recuento.
        $r->assertStatus(200);
        $this->assertSame(1, $r->json('piars'));

        $guardado = DB::table('piars_asignaturas')->where('id', $fila->id)->value('apoyo_razonable');
        $this->assertStringContainsString('<b>continuo</b>', $guardado, 'El formato del editor se conserva.');
        $this->assertStringNotContainsString('<script', $guardado,
            'El cliente pinta esto como HTML: lo que no limpie el servidor se ejecuta en la sesión de quien abra el PIAR.');
        $this->assertNotNull(DB::table('piars_asignaturas')->where('id', $fila->id)->value('updated_by'));
    }

    /** Y sin `id` la respuesta lo dice: cero filas. */
    public function test_sin_id_la_respuesta_dice_que_no_toco_nada(): void
    {
        $token = $this->tokenDelPersonal();

        $this->withToken($token)->putJson('/api/piars-asignaturas/field',
            ['field' => 'seguimientos', 'text' => 'x'])
            ->assertStatus(200)->assertExactJson(['piars' => 0]);

        $this->withToken($token)->putJson('/api/piars-grupos/contexto-de-grupo',
            ['caracterizacion_grupo' => '<b>x</b>'])
            ->assertStatus(200)->assertExactJson(['piars' => 0]);
    }

    /** La caracterización del grupo: guarda, limpia y firma. */
    public function test_la_caracterizacion_de_un_grupo_guarda_y_limpia_el_html(): void
    {
        $token = $this->tokenDelPersonal();
        $fila = $this->unGrupoDelPiar();

        $r = $this->withToken($token)->putJson('/api/piars-grupos/contexto-de-grupo', [
            'id' => $fila->id,
            'caracterizacion_grupo' => '<p>Grupo <i>diverso</i></p><img src=x onerror=alert(1)>',
        ]);

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('piars'));

        $guardado = DB::table('piars_grupos')->where('id', $fila->id)->value('caracterizacion_grupo');
        $this->assertStringContainsString('<i>diverso</i>', $guardado);
        $this->assertStringNotContainsString('onerror', $guardado);
    }

    /**
     * El acta de acuerdo: sin fichero, 422; y borrar la de un alumno sin acta, 404.
     *
     * `postDocument` es de las **dos validaciones que hay en todo el proyecto**
     * junto con la de `piars-config`, y por eso este es de los pocos sitios donde
     * un cuerpo vacío contesta lo que debe sin que lo pare el esquema.
     */
    public function test_el_acta_de_acuerdo_pide_fichero_y_no_borra_lo_que_no_hay(): void
    {
        $token = $this->tokenDelPersonal();
        $alumno = DB::table('alumnos')->whereNull('deleted_at')->orderBy('id')->first();
        $year = DB::table('years')->whereNull('deleted_at')->orderBy('id')->value('id');

        $this->withToken($token)->postJson('/api/piars-actas-acuerdo/document',
            ['alumno_id' => $alumno->id, 'year_id' => $year])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->withToken($token)->postJson('/api/piars-actas-acuerdo/document', [])
            ->assertStatus(422)->assertJsonValidationErrors(['file', 'alumno_id', 'year_id']);

        DB::table('piars_actas_acuerdo')->where('alumno_id', $alumno->id)->where('year_id', $year)->delete();

        // 404 y no 500: antes se caía con `$document` sin definir, que era un 500
        // diciendo «no existe».
        $this->withToken($token)->deleteJson('/api/piars-actas-acuerdo/document/'.$alumno->id,
            ['yearId' => $year])->assertStatus(404);
    }

    /**
     * Borrar un acta la vacía, deja el rastro y **no borra la fila**.
     *
     * `documento` se pone a null y el valor viejo se apila en `history` con quién
     * lo hizo. Es lo contrario de `bitacoras` (§88), donde borrar no dejaba firma:
     * aquí el rastro es el punto del módulo.
     */
    public function test_borrar_un_acta_la_vacia_y_apila_el_rastro(): void
    {
        $token = $this->tokenDelPersonal();
        $alumno = DB::table('alumnos')->whereNull('deleted_at')->orderBy('id')->first();
        $year = (int) DB::table('years')->whereNull('deleted_at')->orderBy('id')->value('id');

        DB::table('piars_actas_acuerdo')->where('alumno_id', $alumno->id)->where('year_id', $year)->delete();
        DB::table('piars_actas_acuerdo')->insert([
            'alumno_id' => $alumno->id, 'year_id' => $year,
            'documento' => 'user_1/acta.pdf', 'history' => json_encode([['documento' => 'user_1/acta.pdf']]),
        ]);

        $r = $this->withToken($token)->deleteJson('/api/piars-actas-acuerdo/document/'.$alumno->id,
            ['yearId' => $year]);

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('document'), 'La respuesta trae el número de filas tocadas.');

        $fila = DB::table('piars_actas_acuerdo')->where('alumno_id', $alumno->id)
            ->where('year_id', $year)->first();

        $this->assertNotNull($fila, 'La fila se queda: lo que se borra es el documento.');
        $this->assertNull($fila->documento);

        $historia = json_decode($fila->history, true);
        $this->assertCount(2, $historia, 'El borrado se apila sobre lo que hubiera.');
        $this->assertSame('user_1/acta.pdf', $historia[1]['documento']);
        $this->assertArrayHasKey('updated_by_name', $historia[1],
            'Y con nombre: es el rastro que el colegio lee, no el user_id.');
    }
}
