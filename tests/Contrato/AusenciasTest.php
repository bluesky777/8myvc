<?php

namespace Tests\Contrato;

use App\Support\Reloj;
use Illuminate\Support\Facades\DB;

/**
 * Las seis rutas de ausencias que nadie había mirado.
 *
 * Las ausencias salen en el boletín y en el observador, y el módulo ya había dado
 * un hallazgo por otro lado: la §25, donde un alumno sacaba las de todo el colegio
 * por el lector de tardanzas. Estas seis son las de la aplicación normal.
 *
 * La §40 nació de aquí —crear una ausencia no comprobaba el periodo y editarla
 * sí— y se decidió dos veces: abierto el 21 ago 2026, cerrado el 29 ago 2026.
 * Manda la segunda, y la cabecera del controlador cuenta las dos.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §40.
 */
class AusenciasTest extends CasoDeContrato
{
    /** Un profesor, su token, su periodo y una asignatura con alumnos. */
    private function contexto(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $periodo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($asignatura, 'El seed necesita una asignatura con alumnos en el año del profesor.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$asignatura->grupo_id]);

        return (object) [
            'token' => $token,
            'user_id' => $prof->id,
            'periodo' => $periodo,
            'asignatura' => $asignatura,
            'alumno_id' => $alumno->alumno_id,
        ];
    }

    /** Anotar una ausencia la escribe, y en el periodo del profesor. */
    public function test_anotar_una_ausencia_la_escribe_en_su_periodo(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 2,
            'fecha_hora' => '2026-08-21 07:00:00',
        ]);

        // 201 y no 200: la respuesta es un modelo Eloquent recién creado y
        // Laravel lo pone solo. Es el mismo caso que `opciones/add-opcion` de la
        // §27.2 y el contrario que `years/store`, que vuelve a buscar el modelo
        // antes de devolverlo y por eso responde 200. Se fija el número que los
        // clientes reciben hoy.
        $r->assertStatus(201);

        $fila = DB::table('ausencias')->where('id', $r->json('id'))->first();

        $this->assertNotNull($fila, 'La ausencia no llegó a escribirse.');
        $this->assertEquals($c->periodo->id, $fila->periodo_id,
            'La ausencia no se escribió en el periodo del profesor.');
        $this->assertSame('ausencia', $fila->tipo,
            'El tipo se deduce de la cantidad y dejó de deducirse.');
    }

    /**
     * Sin `fecha_hora` en el cuerpo, la falta se anota **hoy** y no en ninguna parte.
     *
     * Esta era la única de las tres rutas que anotan una falta que aceptaba el
     * campo vacío: sus dos vecinas —`agregar-ausencia` y `agregar-tardanza`—
     * pasan lo que reciban por `Carbon::parse()`, y con null eso ya era ahora.
     * Una fila con `fecha_hora` en null cuenta en los totales del boletín y no
     * aparece en ningún listado por día, así que el hueco solo se ve al buscar
     * por qué no cuadran los dos números.
     */
    public function test_una_falta_sin_fecha_se_anota_hoy(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 1,
        ]);

        $r->assertStatus(201);

        $fila = DB::table('ausencias')->where('id', $r->json('id'))->first();

        $this->assertNotNull($fila->fecha_hora,
            'Una falta sin fecha volvió a quedarse sin día.');
        // `Reloj::ahora()` y no `now()`: el día de un colegio es el de Bogotá, y
        // entre las 00:00 y las 05:00 UTC los dos no son el mismo. Comprobarlo
        // contra `now()` habría dejado un test que falla de madrugada.
        $this->assertStringStartsWith(Reloj::ahora()->toDateString(), (string) $fila->fecha_hora,
            'La falta sin fecha no se anotó hoy, en hora de Bogotá.');

        // Y **la respuesta la trae en ISO**, no en el texto de MySQL que devuelve
        // cuando el cliente sí la manda: el modelo no declara casts, así que el
        // `Carbon` sobrevive hasta la serialización. La misma columna llega en dos
        // formatos según el camino, y se fija aquí porque `app2` ya lee los dos
        // —`fechaCortaDeFalta`, con su prueba— y eso es lo que lo hace una
        // diferencia y no una rotura.
        $this->assertStringContainsString('T', (string) $r->json('fecha_hora'),
            'El alta sin fecha dejó de contestar la fecha en ISO.');
    }

    /** Y una tardanza es una fila de tipo `tardanza` con cantidad 1. */
    public function test_agregar_una_tardanza_escribe_una_de_tipo_tardanza(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->postJson('/api/ausencias/agregar-tardanza', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'now' => '2026-08-21 07:10:00',
        ]);

        $r->assertStatus(201);

        $fila = DB::table('ausencias')->where('id', $r->json('id'))->first();

        $this->assertSame('tardanza', $fila->tipo);
        $this->assertEquals(1, $fila->cantidad_tardanza);
    }

    /**
     * El listado por asignatura trae a los alumnos con lo suyo, y nada de más.
     *
     * Cuelga de cada alumno un `userData`, y lo que importa comprobar de él es lo
     * que **no** lleva: es una lista de columnas nombrada, no un `SELECT *`, así
     * que no arrastra credenciales. Se fija para que siga siéndolo.
     */
    public function test_el_listado_por_asignatura_no_trae_credenciales(): void
    {
        $c = $this->contexto();

        $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 1,
            'fecha_hora' => '2026-08-21 08:00:00',
        ])->assertStatus(201);

        $r = $this->withToken($c->token)
            ->getJson('/api/ausencias/detailed/'.$c->asignatura->id);

        $r->assertStatus(200);
        $this->assertStringNotContainsString('$2y$', (string) $r->getContent(),
            'El listado de ausencias devuelve un hash.');
        $this->assertStringNotContainsString('"password"', (string) $r->getContent());
    }

    /**
     * Con el periodo cerrado **no se corrige, no se cambia de tipo y no se borra**.
     *
     * ESTE TEST HA DICHO LAS DOS COSAS, y conviene saberlo antes de cambiarlo otra
     * vez. Nació afirmando que las tres rutas respetaban el periodo cerrado —era
     * lo que hacían, tres de las 26 llamadas de la §27—; el 21 ago 2026 Joseth
     * decidió lo contrario y el test se dio la vuelta; el **29 ago 2026** lo
     * decidió al revés y vuelve a su forma original: *«la asistencia no se puede
     * modificar en un periodo que esté bloqueado para editar notas»*.
     *
     * Lo que se comprueba no es sólo el 400: es que **la fila no cambió**. Un
     * guard que contesta 400 después de escribir no es un guard, y es un fallo que
     * ya se cazó una vez en `notas/lote` —allí la escala tapaba el permiso—.
     */
    public function test_con_el_periodo_cerrado_no_se_corrige_ni_se_borra(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 3,
            'fecha_hora' => '2026-08-21 09:00:00',
        ]);
        $ausenciaId = $r->json('id');

        DB::table('periodos')->where('year_id', $c->periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $this->withToken($c->token)->putJson('/api/ausencias/cambiar-tipo-ausencia', [
            'ausencia_id' => $ausenciaId,
            'new_tipo' => 'tardanza',
        ])->assertStatus(400);

        $this->assertSame('ausencia',
            DB::table('ausencias')->where('id', $ausenciaId)->value('tipo'),
            'El 400 llegó pero la fila ya estaba cambiada: el guard va después de escribir.');

        $this->withToken($c->token)
            ->deleteJson('/api/ausencias/destroy/'.$ausenciaId)
            ->assertStatus(400);

        $this->assertNull(DB::table('ausencias')->where('id', $ausenciaId)->value('deleted_at'),
            'La falta se borró con el periodo cerrado.');
    }

    /**
     * Con el periodo cerrado **tampoco se anota**.
     *
     * Es la otra mitad del cambio del 29 ago 2026, y la que de verdad se nota: son
     * las rutas que usa `myvc_flutter` para pasar lista. Ver la cabecera del
     * controlador — a partir de aquí, un profesor con el periodo cerrado recibe
     * 400 también desde el móvil, y eso es lo pedido.
     */
    public function test_con_el_periodo_cerrado_tampoco_se_anota(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('year_id', $c->periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c;

        $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 1,
            'fecha_hora' => '2026-08-21 10:00:00',
        ])->assertStatus(400);

        $this->withToken($c->token)->postJson('/api/ausencias/agregar-tardanza', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'now' => '2026-08-21 10:05:00',
        ])->assertStatus(400);

        $this->assertSame($antes, DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c,
            'Se escribió una falta con el periodo cerrado.');
    }

    /**
     * **LA SECRETARÍA NO SE QUEDA FUERA**, y ésta es la prueba que separa lo que se
     * pidió de lo que habría venido de regalo.
     *
     * El camino corto era llamar a `User::pueden_editar_notas()`, que ya mira esta
     * misma bandera. Su rama final contesta **403 a todo el que no sea profesor ni
     * superusuario**, así que habría cerrado la asistencia a la secretaría —que la
     * pasa y no toca una nota en su vida— dentro de un cambio que hablaba de
     * periodos. Por eso hay un guard nuevo que comprueba sólo el periodo.
     *
     * Con el periodo CERRADO: el profesor recibe 400 y la secretaría escribe. Si
     * alguien «simplifica» el guard, esto falla y le cuenta lo que se lleva.
     */
    public function test_el_periodo_cerrado_no_alcanza_a_quien_no_es_profesor(): void
    {
        $c = $this->contexto();

        $secre = $this->usuarioDeTipo('Usuario');
        $tokenSecre = $this->tokenDe($secre->username);

        // TODOS los periodos, y no sólo los del año del profesor: la secretaría
        // del seed puede estar en otro año, y entonces «pasó» sin que su periodo
        // estuviera cerrado — o sea sin probar nada. Cerrándolos todos, lo único
        // que la deja escribir es que la guarda no la alcanza, que es el punto.
        DB::table('periodos')->update(['profes_pueden_editar_notas' => 0]);

        $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 1,
        ])->assertStatus(400);

        $this->withToken($tokenSecre)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 1,
        ])->assertStatus(201);
    }

    /**
     * Corregir el día de una falta **ajena** se puede, y ahora es una decisión.
     *
     * `putGuardarCambiosAusencia` tenía una comprobación de permisos calculada y
     * tirada a la basura —`Role::isCoorDisciplinario()` seguido de un `if` con el
     * cuerpo vacío—, lo mismo que `deleteDestroy`. `myvc_front` la vio en la fase
     * 11 y la dejó apuntada por ser del backend; nadie volvió.
     *
     * Se le preguntó a Joseth el 22 ago 2026 con el alcance medido delante y
     * contestó que **se queda abierto**, en la misma línea que el interruptor del
     * periodo: corregir una falta mal puesta es trabajo de asistencia. El cálculo
     * muerto se retiró y el porqué quedó escrito en el controlador.
     *
     * **Este test existe para el día que alguien quiera cerrarlo.** Si se rellena
     * el `if`, esto falla y le cuenta qué se lleva por delante: el menú de
     * AngularJS enseña «Asistencias» a `profesor`, y `myvc_flutter` —una sola app
     * para los dieciséis colegios— borra y corrige desde la pantalla de
     * asistencia del profesor sin mirar ningún rol. La app no se publica el mismo
     * día que el backend.
     *
     * La falta que se corrige es de **otro grupo**, y a propósito: lo que está
     * abierto no es solo «cualquier profesor», es «cualquier profesor sobre
     * cualquier falta del colegio». Quien cierre esto tiene que decidir las dos.
     */
    public function test_corregir_el_dia_de_una_falta_ajena_se_puede_y_queda_firmado(): void
    {
        $c = $this->contexto();
        $ajeno = $this->grupoAjenoDelMismoAnio((int) $c->periodo->year_id);

        $ausenciaId = DB::table('ausencias')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $ajeno->asignatura_id,
            'periodo_id' => $c->periodo->id,
            'cantidad_ausencia' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->withToken($c->token)->putJson('/api/ausencias/guardar-cambios-ausencia', [
            'ausencia_id' => $ausenciaId,
            'fecha_hora' => '2026-08-19 07:00:00',
        ]);

        $r->assertStatus(200);

        $fila = DB::table('ausencias')->where('id', $ausenciaId)->first();

        $this->assertStringStartsWith('2026-08-19', (string) $fila->fecha_hora,
            'La corrección no llegó a la fila de un grupo ajeno.');
        $this->assertNotNull($fila->updated_by,
            'Corregir una falta dejó de anotar quién la corrigió.');
    }

    /**
     * Borrar una falta **la firma**, y hasta el 22 ago 2026 no la firmaba.
     *
     * De las tres rutas que borran una ausencia, dos —la del lector y la de
     * `asistencias/*`— ponían `deleted_by`; la de las pantallas web y la de
     * Flutter no ponía nada. En la copia de producción del 22 ago hay **5.689
     * ausencias borradas y 5.684 sin autor**, y las cinco firmadas son justo las
     * que pasaron por el lector.
     *
     * Es la contrapartida de la decisión de arriba: si borrar sigue abierto a
     * cualquiera del personal, el rastro es lo único que queda — y estaba en
     * blanco.
     *
     * Se comprueba sobre una falta de **otro grupo** por lo mismo que el test de
     * arriba, y se mira el valor y no solo que no sea nulo: `deleted_by` es de
     * `users`, no de `personas`, y confundirlos es la trampa de siempre en este
     * repo (`$user->user_id` contra `$user->persona_id`).
     */
    public function test_borrar_una_falta_ajena_anota_quien_fue(): void
    {
        $c = $this->contexto();
        $ajeno = $this->grupoAjenoDelMismoAnio((int) $c->periodo->year_id);

        $ausenciaId = DB::table('ausencias')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $ajeno->asignatura_id,
            'periodo_id' => $c->periodo->id,
            'cantidad_ausencia' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($c->token)
            ->deleteJson('/api/ausencias/destroy/'.$ausenciaId)
            ->assertStatus(200);

        $fila = DB::table('ausencias')->where('id', $ausenciaId)->first();

        $this->assertNotNull($fila->deleted_at, 'La falta no se borró.');
        $this->assertSame((int) $c->user_id, (int) $fila->deleted_by,
            'Borrar una falta no anota quién fue, o anota el id equivocado.');
    }
}
