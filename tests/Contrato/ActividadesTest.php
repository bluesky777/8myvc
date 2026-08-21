<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El lado del AUTOR de las actividades, mirado por el resultado.
 *
 * `MisActividadesTest` cubre el lado del alumno y `OpcionesTest` las cuatro de
 * `opciones/*`. Falta éste: las doce rutas de `actividades/*`, que son con las
 * que un profesor crea el examen, lo edita, lo comparte y lo borra.
 *
 * Las doce llevan `auth.personal`, así que ninguna familia las alcanza y la
 * pregunta de quién entra está contestada. La que no había mirado nadie es la
 * siguiente: **qué puede hacer un profesor con la actividad de otro profesor.**
 *
 * `ws_actividades` tiene `created_by`, o sea que de quién es una actividad se
 * puede saber. Ningún método de este controlador lo mira.
 *
 * La tabla está vacía en el seed, así que las actividades las monta el test y la
 * transacción las deshace.
 */
class ActividadesTest extends CasoDeContrato
{
    /**
     * Personal del colegio del año que tiene grupos con alumnos.
     *
     * **Ojo: el primero por id resulta ser superusuario** (el `1`), y conviene
     * saberlo al leer el resto del fichero. No invalida nada de lo que se fija
     * aquí —ninguno de estos métodos mira `is_superuser`, así que el resultado es
     * el mismo para los 51 profesores—, pero sí importa en `actividades/datos`,
     * que es el único que sí lo mira. Para ése está `administrativo()`.
     */
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
     * Un examen con todos sus campos puestos, de quien se diga.
     *
     * Se rellenan todos a propósito: lo que se mira después es cuáles
     * sobreviven a un guardado, y con la mitad en null no se distinguiría
     * «lo borró» de «ya estaba vacío».
     *
     * **`created_by` guarda el `persona_id`, no el `users.id`**, aunque el nombre
     * diga lo contrario: lo escribe `postCrear()` con `$user->persona_id` y lo
     * lee `putCompartidas()` con lo mismo. Aquí da igual cuál de los dos se use
     * —lo que se comprueba es que ningún método lo mira—, pero **no da igual el
     * día que se escriba el dueño de verdad**: ver 13-actividades.md §2.
     */
    private function actividadDe(int $duenoId, int $grupoId): int
    {
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL
                                     ORDER BY id LIMIT 1', [$grupoId]);

        $this->assertNotNull($asignatura, 'El grupo del seed no tiene asignaturas.');

        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        return DB::table('ws_actividades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'descripcion' => 'Examen del primer periodo',
            'tipo' => 'E',
            'compartida' => 1,
            'para_alumnos' => 1,
            'can_upload' => 1,
            'in_action' => 1,
            'duracion_preg' => 90,
            'duracion_exam' => 3600,
            'oportunidades' => 2,
            'one_by_one' => 1,
            'tipo_calificacion' => 'Por puntos',
            'contenido' => 'lo que sea',
            'created_by' => $duenoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Guardar sin un campo **lo borra**, no lo deja como estaba.
     *
     * `putGuardar()` asigna trece campos seguidos con `Request::input('x')` a
     * secas, y `Request::input()` de algo que no viene devuelve `null`. Así que
     * un cliente que mande solo lo que cambió —que es lo que hace cualquiera que
     * no sepa que este endpoint espera el objeto entero— **vacía todo lo demás**:
     * la duración del examen, las oportunidades, el tipo de calificación.
     *
     * No es una fuga ni un permiso: es un examen configurado que se queda en
     * blanco sin que nada falle, y respondiendo 200 con el objeto ya vaciado
     * dentro. Ver 13-actividades.md §1.
     */
    public function test_guardar_sin_un_campo_lo_borra(): void
    {
        $docente = $this->docente();
        $actividad = $this->actividadDe($docente->user_id, $docente->grupo_id);

        $this->withToken($docente->token)
            ->putJson('/api/actividades/guardar', [
                'id' => $actividad,
                'descripcion' => 'Examen del primer periodo (corregido)',
            ])
            ->assertStatus(200);

        $fila = DB::table('ws_actividades')->where('id', $actividad)->first();

        $this->assertSame('Examen del primer periodo (corregido)', $fila->descripcion,
            'Lo que sí se mandó se guarda.');

        // Y lo que no se mandó, que era la configuración del examen. Unos quedan
        // en null y otros en cadena vacía, y la diferencia no es del código: las
        // columnas NOT NULL reciben el null y **MySQL lo convierte en silencio**,
        // porque `config/database.php` lleva `'strict' => false`. Con el modo
        // estricto puesto, esta llamada sería un error en vez de un vaciado.
        $this->assertSame('', $fila->tipo_calificacion, 'El tipo de calificación se fue.');
        $this->assertNull($fila->contenido, 'El contenido se fue.');
        $this->assertNull($fila->one_by_one, 'El «una a una» se fue.');
        $this->assertSame(0, (int) $fila->duracion_exam, 'Y la duración del examen quedó en cero.');
    }

    /**
     * Cualquiera del personal edita la actividad de otro.
     *
     * `putGuardar()` hace `WsActividad::findOrFail(Request::input('id'))` y
     * escribe. No mira `created_by`, que está ahí y dice de quién es.
     *
     * Con `auth.personal` delante eso son los 51 profesores del colegio sobre
     * cualquier examen — y como el guardado es el del test de arriba, **editar
     * el examen de otro y vaciarlo son la misma llamada**.
     */
    public function test_el_personal_edita_la_actividad_de_otro(): void
    {
        $docente = $this->docente();
        $ajena = $this->actividadDe($docente->user_id + 1, $docente->grupo_id);

        $this->withToken($docente->token)
            ->putJson('/api/actividades/guardar', [
                'id' => $ajena,
                'descripcion' => 'Lo cambió otro',
                'in_action' => 0,
            ])
            ->assertStatus(200);

        $fila = DB::table('ws_actividades')->where('id', $ajena)->first();

        $this->assertSame('Lo cambió otro', $fila->descripcion);
        $this->assertSame(0, (int) $fila->in_action, 'Y le cerró el examen a mitad.');
        $this->assertNotSame($docente->user_id, (int) $fila->created_by,
            'La actividad seguía siendo de otro.');
    }

    /** Y la borra. */
    public function test_el_personal_borra_la_actividad_de_otro(): void
    {
        $docente = $this->docente();
        $ajena = $this->actividadDe($docente->user_id + 1, $docente->grupo_id);

        $this->withToken($docente->token)
            ->deleteJson('/api/actividades/destroy/'.$ajena)
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('ws_actividades')->where('id', $ajena)->value('deleted_at'),
            'La actividad de otro se fue a la papelera.'
        );
    }

    /**
     * Compartir no comprueba nada, y lo único que lo para es la base de datos.
     *
     * `putInsertGrupoCompartido()` es un `new WsActividadCompartida()` con los dos
     * ids del cuerpo y un `save()`. Sin `findOrFail`, sin mirar `created_by`, sin
     * validación. Con un id que no existe **no responde 404 ni 422: revienta con
     * la clave foránea**, que es un 500 con SQL dentro.
     *
     * Se fija tal cual —con ruta y roto se documenta—, y lo que hay que ver es
     * que la integridad la sostiene el esquema y no el código: `ws_actividades_compartidas`
     * tiene FOREIGN KEY a `ws_actividades` y a `grupos`. Es la única de las tres
     * tablas de este dominio que las lleva, así que aquí hubo suerte.
     */
    public function test_compartir_una_actividad_que_no_existe_es_500(): void
    {
        $docente = $this->docente();

        $inventada = ((int) DB::table('ws_actividades')->max('id')) + 1000;

        $this->withToken($docente->token)
            ->putJson('/api/actividades/insert-grupo-compartido', [
                'actividad_id' => $inventada,
                'grupo_id' => $docente->grupo_id,
            ])
            ->assertStatus(500);

        $this->assertSame(
            0,
            DB::table('ws_actividades_compartidas')->where('actividad_id', $inventada)->count()
        );
    }

    /** Compartir la actividad de otro con el grupo que sea, también. */
    public function test_el_personal_comparte_la_actividad_de_otro(): void
    {
        $docente = $this->docente();
        $ajena = $this->actividadDe($docente->user_id + 1, $docente->grupo_id);

        $otroGrupo = $this->grupoAjenoDelMismoAnio($docente->year_id);

        $this->withToken($docente->token)
            ->putJson('/api/actividades/insert-grupo-compartido', [
                'actividad_id' => $ajena,
                'grupo_id' => $otroGrupo->grupo_id,
            ])
            ->assertStatus(201);

        $this->assertSame(
            1,
            DB::table('ws_actividades_compartidas')->where('actividad_id', $ajena)
                ->where('grupo_id', $otroGrupo->grupo_id)->count()
        );
    }

    /** Un `Usuario` del colegio que NO es superusuario, que es el caso de la §6. */
    private function administrativo(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.is_superuser = 0 AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario,
            "El seed no tiene un Usuario sin superusuario en el año {$grupo->year_id}.");

        return (object) [
            'user_id' => (int) $usuario->id,
            'grupo_id' => (int) $grupo->id,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    /**
     * El cuarto tipo de usuario cae por el hueco: 200 y las listas vacías.
     *
     * `putDatos()` tiene dos ramas, `is_superuser` y `tipo == 'Profesor'`. Un
     * **administrativo** —tipo `Usuario` sin superusuario, que es lo que son las
     * secretarias— no entra en ninguna, así que `mis_asignaturas` y
     * `otras_asignaturas` se quedan como los arrays vacíos con los que empiezan.
     *
     * Y responde **200 con la lista de grupos dentro**, que es lo que lo hace
     * difícil de ver: la pantalla se pinta, el selector de grupos se llena, y al
     * elegir un grupo no aparece ninguna asignatura. No parece un permiso que
     * falta; parece que el grupo no tiene nada.
     *
     * Es la forma que ya lleva encontradas cuatro caras en este repo —el `switch`
     * de cuatro ramas al que le falta la cuarta—: la 05 §25.3 en Tardanzas, la
     * 05 §44 en las fotos, y ésta. Ver 13-actividades.md §6.
     */
    public function test_un_administrativo_recibe_las_listas_vacias(): void
    {
        $admin = $this->administrativo();

        $respuesta = $this->withToken($admin->token)
            ->putJson('/api/actividades/datos', ['grupo_id' => $admin->grupo_id])
            ->assertStatus(200);

        $this->assertNotEmpty($respuesta->json('grupos'), 'El selector de grupos sí se llena.');
        $this->assertSame([], $respuesta->json('mis_asignaturas'));
        $this->assertSame([], $respuesta->json('otras_asignaturas'));
    }

    /** Y el superusuario, con el mismo cuerpo, sí las recibe. */
    public function test_el_superusuario_si_recibe_las_asignaturas(): void
    {
        $docente = $this->docente();

        $respuesta = $this->withToken($docente->token)
            ->putJson('/api/actividades/datos', ['grupo_id' => $docente->grupo_id])
            ->assertStatus(200);

        $this->assertNotEmpty($respuesta->json('otras_asignaturas'),
            'El mismo grupo, el mismo cuerpo, y a éste sí le llegan.');
    }

    /**
     * Y pedir por una asignatura que no existe es 500.
     *
     * Las dos ramas de `putDatos()` resuelven el grupo con
     * `DB::select($consulta, [...])[0]->grupo_id`. Es el `[0]` de siempre, aquí
     * por partida doble en el mismo método.
     */
    public function test_pedir_por_una_asignatura_que_no_existe_es_500(): void
    {
        $docente = $this->docente();

        $inventada = ((int) DB::table('asignaturas')->max('id')) + 1000;

        $this->withToken($docente->token)
            ->putJson('/api/actividades/datos', ['asign_id' => $inventada])
            ->assertStatus(500);
    }

    /**
     * El segundo listado tiene el mismo hueco, y peor: las claves ni existen.
     *
     * `putCompartidas()` repite las dos ramas de la §6 —`is_superuser` y
     * `tipo == 'Profesor'`— y a un administrativo no le entra ninguna. La
     * diferencia con `actividades/datos` es que allí las listas se inicializan
     * vacías arriba y aquí **no se inicializan las tres claves `actv_*`**: no
     * llegan vacías, **no llegan**.
     *
     * Para el cliente eso es `undefined` en vez de `[]`, que es la diferencia
     * entre una tabla vacía y un error de JavaScript.
     */
    public function test_el_segundo_listado_no_le_manda_ni_las_claves(): void
    {
        $admin = $this->administrativo();

        $respuesta = $this->withToken($admin->token)
            ->putJson('/api/actividades/compartidas', [])
            ->assertStatus(200);

        $this->assertNotEmpty($respuesta->json('grupos'), 'Los grupos sí llegan.');

        foreach (['actv_alumnos', 'actv_profes', 'actv_acudi'] as $clave) {
            $this->assertNull($respuesta->json($clave), "La clave {$clave} no viaja.");
        }
    }

    /** Y al superusuario sí le llegan las tres. */
    public function test_al_superusuario_le_llegan_las_tres_listas(): void
    {
        $docente = $this->docente();

        $respuesta = $this->withToken($docente->token)
            ->putJson('/api/actividades/compartidas', [])
            ->assertStatus(200);

        foreach (['actv_alumnos', 'actv_profes', 'actv_acudi'] as $clave) {
            $this->assertIsArray($respuesta->json($clave), "La clave {$clave} sí viaja.");
        }
    }
}
