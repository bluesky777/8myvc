<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT historiales/sesion` — la pantalla que dice **qué se tocó en esta sesión**, y
 * las cuatro maneras que tiene de no decirlo.
 *
 * Es la pantalla que pidió Joseth: el colegio la abre cuando una familia reclama
 * una nota y quiere saber quién la tocó. Devuelve la fila de `historiales` y,
 * dentro, sus `bitacoras` — y esas bitácoras salen de **una** consulta con
 * **tres `INNER JOIN`** y **ningún filtro por `affected_element_type`**
 * ([HistorialesController:135](../../app/Http/Controllers/Historiales/HistorialesController.php#L135)):
 *
 *     INNER JOIN alumnos a     ON b.affected_user_id = a.id AND a.deleted_at IS NULL
 *     INNER JOIN notas n       ON n.id = b.affected_element_id
 *     INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
 *
 * De ahí salen cuatro causas independientes de que una fila no se enseñe, y este
 * archivo tiene **un test por causa** en vez de uno que las mezcle: son cuatro
 * arreglos distintos y **el orden en que se arreglen decide si el usuario nota
 * algo**.
 *
 * `tools/historial-que-cuenta-de-menos.php` mide cuál se ve antes —sólo lee— y en
 * la copia de desarrollo da **4 de 34 bitácoras enseñadas, 30 calladas (88%)**, con
 * las 30 repartidas mitad y mitad entre el join de `alumnos` y el de `notas`. La
 * que se ve antes es **el tipo**: se dispara en toda sesión que haga algo que no
 * sea teclear notas, mientras que las otras tres necesitan que alguien haya
 * borrado algo.
 *
 * > La frase que resume el hallazgo: **la pantalla no cuenta de menos las notas
 * > —ésas las cuenta bien— sino LA SESIÓN**, que es lo que dice su nombre.
 *
 * ## Y hay una causa que ninguna herramienta puede medir
 *
 * `notas/destroy` es **`DELETE FROM notas WHERE id=?`**, un borrado **duro**,
 * aunque el modelo `Nota` use SoftDeletes
 * ([NotasController:756](../../app/Http/Controllers/NotasController.php#L756)). Así
 * que al borrar una nota **la bitácora sobrevive y su nota no**, y el `INNER JOIN`
 * la pierde. Y como la fila no queda, **no hay nada que contar**: `COUNT(*) FROM
 * notas WHERE deleted_at IS NOT NULL` da cero y eso no significa que no se borren
 * notas, significa que las borradas no están.
 *
 * **Por eso esa causa vive aquí y no en la herramienta**: la única forma de
 * demostrarla es borrar una nota y mirar qué pasa, y la única forma de que eso sea
 * inocuo es la transacción de un test.
 *
 * Estos tests **fijan el estado actual**, no lo arreglan. Cambiar la consulta es
 * cambiarle la respuesta a una pantalla desplegada en dieciséis colegios, y hay
 * que decidir antes qué debe enseñar una bitácora que no es de una nota.
 */
class HistorialDeSesionCuentaDeMenosTest extends CasoDeContrato
{
    /**
     * **Causa 2, la que más duele: se borra la nota y la sesión se olvida de que
     * existió.**
     *
     * Va primera porque es la que hace inútil la pantalla justo cuando se usa: se
     * abre para saber quién tocó una nota, y la nota que más se reclama es la que
     * ya no está.
     *
     * **Se comprueban las dos mitades, y hacen falta las dos.** Que la pantalla
     * enseñe cero no dice nada por sí solo —podría ser que no hubiera pasado
     * nada—; lo que lo convierte en un fallo es que **la bitácora sigue en la
     * tabla**. El rastro existe, y la pantalla que existe para leerlo no lo lee.
     *
     * Y se borra **por la ruta**, no con un `DELETE` del test: lo que se afirma es
     * lo que hace el botón que tiene el docente delante.
     */
    public function test_al_borrar_la_nota_su_bitacora_desaparece_de_la_sesion_aunque_siga_en_la_tabla(): void
    {
        $ctx = $this->unaSesionQueTocoUnaNota();

        // De partida se ve, que es lo que hace que el «no se ve» de después
        // signifique algo.
        $this->assertCount(1, $this->bitacorasDeLaSesion($ctx),
            'El montaje ya no mide lo que cree: la bitácora tenía que verse antes de borrar nada.');

        $this->withToken($ctx['token_profesor'])
            ->deleteJson('/api/notas/destroy/'.$ctx['nota'])
            ->assertStatus(200);

        // La nota se fue de la tabla del todo: es un DELETE duro, no un SoftDelete.
        $this->assertNull(DB::table('notas')->where('id', $ctx['nota'])->first(),
            'La nota sigue en la tabla, así que `notas/destroy` ya no es un borrado duro '
            .'y este test mide otra cosa. Hay que reescribirlo.');

        // Y la bitácora sí sigue: el rastro no se perdió.
        $this->assertSame(1, DB::table('bitacoras')->where('id', $ctx['bitacora'])->count(),
            'La bitácora se fue con la nota: entonces el problema es otro y peor.');

        $this->assertSame([], $this->bitacorasDeLaSesion($ctx),
            'La bitácora sigue en la tabla y la pantalla ya no la enseña: la sesión que '
            .'borró una nota parece no haber hecho nada.');
    }

    /**
     * **Causa 1, la que se ve antes: una bitácora que no es de una nota no sale.**
     *
     * La consulta une **todas** las bitácoras de la sesión con `notas` por
     * `affected_element_id`, sin mirar `affected_element_type`. Pero ese id es de
     * la tabla que diga el tipo — de `subunidades` en «Nueva subunidad», de `users`
     * en `AlumnoPideAjeno:user_id`, de `notas_finales` en `NF_UPDATE`—, así que la
     * unión no encuentra nada y la fila se va.
     *
     * Es la que se dispara en **casi todas** las sesiones: medido, **30 de 34**
     * bitácoras de la copia de desarrollo no son de tipo `Nota`, y las otras tres
     * causas necesitan que alguien haya borrado algo.
     *
     * El test mete **las dos en la misma sesión** a propósito: con sólo la ajena,
     * una respuesta vacía no distinguiría «se cayó por el tipo» de «el montaje no
     * escribió nada».
     */
    public function test_una_bitacora_que_no_es_de_una_nota_no_sale_en_la_sesion(): void
    {
        $ctx = $this->unaSesionQueTocoUnaNota();

        // Lo mismo que anota el guard cuando un alumno pide algo ajeno: el id es un
        // `users.id`, no un `notas.id`.
        DB::table('bitacoras')->insert([
            'created_by' => $ctx['user_profesor'],
            'historial_id' => $ctx['historial'],
            'affected_user_id' => $ctx['alumno'],
            'affected_person_type' => 'Al',
            'affected_element_type' => 'AlumnoVerBoletin',
            'affected_element_id' => $ctx['user_alumno'],
            'created_at' => now(),
        ]);

        $this->assertSame(2, DB::table('bitacoras')->where('historial_id', $ctx['historial'])->count(),
            'El montaje necesita las dos bitácoras en la misma sesión.');

        $vistas = $this->bitacorasDeLaSesion($ctx);

        $this->assertCount(1, $vistas,
            'La pantalla enseñó '.count($vistas).' de 2. La que falta no es de tipo `Nota` y se '
            .'cayó por el `INNER JOIN notas`, que se le aplica igual.');

        $this->assertSame('Nota', $vistas[0]['affected_element_type']);
    }

    /**
     * **Causa 3: borrar la columna se lleva el rastro de lo que se escribió en
     * ella.**
     *
     * `s.deleted_at IS NULL` en el join. Y no es un caso raro: en la copia de
     * desarrollo hay **35.796 notas colgando de subunidades borradas**, el 3,07% de
     * 1.165.565. Cada vez que un docente quita una columna de la planilla, todo lo
     * que se anotó sobre esas notas deja de existir para esta pantalla.
     *
     * Se borra la subunidad **como la borra la aplicación** —marcando
     * `deleted_at`, que es lo que hace SoftDeletes— y no con un `DELETE`, porque lo
     * que se afirma es qué pasa con las 35.796 que ya están así.
     */
    public function test_una_subunidad_borrada_se_lleva_la_bitacora_de_sus_notas(): void
    {
        $ctx = $this->unaSesionQueTocoUnaNota();

        $this->assertCount(1, $this->bitacorasDeLaSesion($ctx));

        DB::table('subunidades')->where('id', $ctx['subunidad'])->update(['deleted_at' => now()]);

        $this->assertSame([], $this->bitacorasDeLaSesion($ctx),
            'La nota sigue ahí y su bitácora también; lo único borrado es la columna, y '
            .'con ella se fue el rastro de lo que se escribió dentro.');
    }

    /**
     * **Causa 4: un alumno borrado se lleva su rastro.**
     *
     * `a.deleted_at IS NULL` en el join. 39 de 1.284 alumnos están borrados en la
     * copia de desarrollo, un 3,04%.
     *
     * Y aquí la lectura es menos obvia que en las otras tres, así que conviene
     * dejarla escrita: **puede que esto sea lo que se quiere.** Un alumno retirado
     * quizá no deba salir en una pantalla del colegio. Lo que no puede ser es que
     * **se decida por un join** y que la pantalla no distinga «no hay rastro» de
     * «hay rastro de alguien a quien no te enseño».
     */
    public function test_un_alumno_borrado_se_lleva_su_rastro_de_la_sesion(): void
    {
        $ctx = $this->unaSesionQueTocoUnaNota();

        $this->assertCount(1, $this->bitacorasDeLaSesion($ctx));

        DB::table('alumnos')->where('id', $ctx['alumno'])->update(['deleted_at' => now()]);

        $this->assertSame([], $this->bitacorasDeLaSesion($ctx));
    }

    /**
     * **Y el otro lado, que no es contar de menos sino contar MAL: una bitácora
     * ajena que sí sale, atribuida a otra cosa.**
     *
     * Éste es el test que más vale del archivo, porque describe un borde que hoy no
     * se dispara y **está a un dato de dispararse**. La consulta une por
     * `affected_element_id` sin mirar el tipo, así que basta con que ese id
     * —que es de otra tabla— **exista en `notas`** para que la fila vuelva con el
     * nombre del alumno y la definición de la subunidad **de una nota que no tiene
     * nada que ver**.
     *
     * `notas` tiene **1.165.565 filas** en la copia de desarrollo, o sea que
     * cualquier id pequeño de cualquier otra tabla existe en `notas` con mucha
     * probabilidad. Medido, **15 de las 34 bitácoras de una sesión ya pasan los
     * joins de `notas` y `subunidades`**, y lo único que las tumba es que su
     * `affected_user_id` no sea un alumno vivo.
     *
     * > **O sea que hoy no salga ninguna mal atribuida no es por diseño: es por
     * > accidente.** Y el accidente tiene fecha de caducidad —
     * > `AcudientePideAjeno:alumno_id` **ya lleva un alumno** en esa columna—, así
     * > que este test existe para que cuando se cumpla ya esté escrito qué pasa.
     */
    public function test_una_bitacora_ajena_con_alumno_vivo_sale_atribuida_a_otra_nota(): void
    {
        $ctx = $this->unaSesionQueTocoUnaNota();

        // Una bitácora que NO es de una nota, con un alumno vivo detrás y un
        // `affected_element_id` que resulta ser el id de la nota de la sesión.
        DB::table('bitacoras')->insert([
            'created_by' => $ctx['user_profesor'],
            'historial_id' => $ctx['historial'],
            'affected_user_id' => $ctx['alumno'],
            'affected_person_type' => 'Al',
            'affected_element_type' => 'AcudientePideAjeno:alumno_id',
            'affected_element_id' => $ctx['nota'],
            'created_at' => now(),
        ]);

        $vistas = $this->bitacorasDeLaSesion($ctx);

        $this->assertCount(2, $vistas,
            'La bitácora ajena no salió, así que este borde ya no está abierto y hay que '
            .'volver a leer la consulta: puede que se le haya puesto el filtro por tipo.');

        $ajena = null;

        foreach ($vistas as $fila) {
            if ($fila['affected_element_type'] === 'AcudientePideAjeno:alumno_id') {
                $ajena = $fila;
            }
        }

        $this->assertNotNull($ajena);

        // Y viene con la definición de la subunidad de la nota, que es la mentira:
        // esta bitácora no habla de ninguna subunidad.
        $this->assertSame($ctx['definicion_subunidad'], $ajena['definicion'],
            'La fila ajena vuelve SIN el nombre de la subunidad, así que la mala atribución '
            .'es otra de la que dice este test.');
    }

    /**
     * **Y el segundo motivo por el que esta pantalla se lee mal: es un `PUT` y no
     * escribe nada.**
     *
     * Es una de las **115 rutas no-`GET` que no escriben** de la
     * [§175](../../docs/migracion/05-codigo-muerto-y-roto.md). Aquí importa dos
     * veces: cualquiera que instrumente esta API —un cortafuegos de medición, un
     * clasificador de «qué escribe»— va a asumir que un `PUT` escribe, **porque es
     * lo que significa en todas partes menos aquí**, y las cuatro de `historiales`
     * caen justo en el cajón equivocado.
     *
     * Se cuenta **el trabajo y no el verbo**: se mira si la petición ejecutó alguna
     * escritura, que es la misma idea que el resto del contrato — mirar el
     * resultado, no la forma.
     *
     * ## Y aquí el aserto NO es «cero escrituras», que es lo que este test buscaba
     *
     * Se escribió esperando cero y salió **una**, y la que salió afina la §175 en
     * vez de contradecirla:
     *
     *     update `personal_access_tokens` set `last_used_at` = ?, `updated_at` = ? where `id` = ?
     *
     * Eso **no es del endpoint**: es la contabilidad del token, y la hace **toda
     * peticion autenticada de esta API**. O sea que la afirmación exacta es «**el
     * método** no escribe», no «**la petición** no escribe», y la diferencia es la
     * que importa para lo que la §175 avisa:
     *
     *   - `tools/verbos-que-mienten.py` busca marcas de escritura **dentro del
     *     método**, así que por diseño no puede ver ésta. Su número sigue
     *     contestando lo que dice contestar;
     *   - pero quien use ese número para montar **una réplica de sólo lectura** o
     *     un cortafuegos de medición se va a encontrar con que **las 115 escriben
     *     una fila igual**. Un `PUT` que «no escribe» revienta contra una réplica.
     *
     * Por eso el aserto es **exactamente esta escritura y ninguna más**, y no un
     * cero: un cero habría que romperlo el día que alguien añada la línea de
     * verdad, y así el test dice **qué** escribe y no sólo cuánto.
     */
    public function test_pedir_el_detalle_de_una_sesion_solo_escribe_la_marca_del_token(): void
    {
        $ctx = $this->unaSesionQueTocoUnaNota();

        $escrituras = [];

        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $consulta->sql) === 1) {
                $escrituras[] = $consulta->sql;
            }
        });

        $this->withToken($ctx['token'])
            ->putJson('/api/historiales/sesion', ['historial_id' => $ctx['historial']])
            ->assertStatus(200);

        $delEndpoint = array_values(array_filter(
            $escrituras,
            fn (string $sql) => ! str_contains($sql, 'personal_access_tokens')
        ));

        $this->assertSame([], $delEndpoint,
            "Un `PUT` que ahora sí escribe algo suyo:\n".implode("\n", $delEndpoint));

        // Y la otra mitad: que la del token SÍ está. Sin esto, el filtro de arriba
        // dejaría el test en verde el día que el oyente deje de recibir consultas
        // —un `DB::listen` que no se engancha da cero escrituras y parece un éxito—.
        $this->assertCount(1, $escrituras,
            'La petición ya no escribe la marca `last_used_at` del token, o el oyente de '
            .'consultas no está recibiendo nada. Lo segundo dejaría este test en verde '
            .'sin comprobar nada.');
    }

    /**
     * Lo que la pantalla enseña de la sesión, tal cual sale de la respuesta.
     *
     * @return list<array<string, mixed>>
     */
    private function bitacorasDeLaSesion(array $ctx): array
    {
        return $this->withToken($ctx['token'])
            ->putJson('/api/historiales/sesion', ['historial_id' => $ctx['historial']])
            ->assertStatus(200)
            ->json('historial.bitacoras');
    }

    /**
     * Una sesión de un profesor que **cambió una nota de verdad**, con su bitácora.
     *
     * La nota se cambia **por la ruta** (`PUT notas/update`) y no insertando la
     * bitácora a mano: lo que estos tests afirman es qué pasa con el rastro que
     * deja la aplicación, y una bitácora escrita por el test podría tener las
     * columnas que le convengan. Aquí el `affected_user_id` lo elige el
     * controlador, que es la mitad del asunto.
     *
     * El token del profesor y el del personal son **dos**: el profesor es el que
     * puede tocar la nota, y `historiales/sesion` va detrás de `auth.personal`, que
     * también lo deja pasar — pero se usa el del personal para leer, porque es
     * quien abre esa pantalla en el colegio.
     *
     * @return array<string, mixed>
     */
    private function unaSesionQueTocoUnaNota(): array
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $tokenProfesor = $this->tokenDe($profesor->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $par = DB::selectOne('SELECT a.id AS asignatura_id, m.alumno_id, al.user_id
              FROM asignaturas a
              INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
              INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
              INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
             WHERE a.deleted_at IS NULL
             ORDER BY a.id, m.alumno_id LIMIT 1', [$suyo->id]);

        $this->assertNotNull($par, 'El seed no tiene una asignatura con alumnos en el año del usuario.');

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $par->asignatura_id,
            'periodo_id' => $suyo->id,
            'definicion' => 'UNIDAD DEL HISTORIAL',
            'porcentaje' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definicion = 'COLUMNA DEL HISTORIAL';

        $subId = DB::table('subunidades')->insertGetId([
            'unidad_id' => $unidadId,
            'definicion' => $definicion,
            'porcentaje' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notaId = DB::table('notas')->insertGetId([
            'subunidad_id' => $subId,
            'alumno_id' => $par->alumno_id,
            'nota' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // La bitácora la escribe el controlador, con el `historial_id` de la sesión
        // que abrió el profesor al entrar.
        $this->withToken($tokenProfesor)
            ->putJson('/api/notas/update/'.$notaId, ['nota' => 40])
            ->assertStatus(200);

        $bitacora = DB::table('bitacoras')
            ->where('affected_element_type', 'Nota')
            ->where('affected_element_id', $notaId)
            ->orderByDesc('id')->first();

        $this->assertNotNull($bitacora, 'Editar la nota no dejó bitácora, así que no hay sesión que mirar.');
        $this->assertNotNull($bitacora->historial_id,
            'La bitácora salió sin `historial_id`, así que no cuelga de ninguna sesión y estos '
            .'tests no medirían nada.');

        return [
            'token' => $this->tokenDelPersonalDe((int) $suyo->year_id),
            'token_profesor' => $tokenProfesor,
            'user_profesor' => (int) $profesor->id,
            'historial' => (int) $bitacora->historial_id,
            'bitacora' => (int) $bitacora->id,
            'nota' => $notaId,
            'subunidad' => $subId,
            'definicion_subunidad' => $definicion,
            'alumno' => (int) $par->alumno_id,
            'user_alumno' => (int) $par->user_id,
        ];
    }
}
