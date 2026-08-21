<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las votaciones del colegio, miradas por el resultado.
 *
 * Los guards de estas 26 rutas ya estaban puestos y `AutorizacionTest` los fija.
 * Lo que nadie había mirado nunca es **qué devuelven y qué escriben**, que es la
 * pregunta que ha encontrado todo lo demás — `tools/cobertura-de-rutas.py` daba
 * cero tests propios para este dominio.
 *
 * Y aquí esa pregunta pesa más que en otros sitios, porque una elección tiene
 * dos reglas que no son de autorización sino de procedimiento: **el recuento no
 * se ve mientras se vota** y **cada uno vota una vez**. Ninguna de las dos la
 * comprueba un guard: las dos viven dentro del controlador, o no viven.
 *
 * El seed trae una sola votación viva (id 5, `in_action=0`, `locked=1`), así que
 * las situaciones de una elección abierta se montan aquí dentro y la transacción
 * del test las deshace.
 */
class VotacionesTest extends CasoDeContrato
{
    /**
     * Una elección abierta, como la del día de la votación: en acción y con el
     * recuento escondido, que es para lo que existe `can_see_results`.
     *
     * Devuelve el id de la votación, el de su aspiración y los de sus candidatos.
     */
    private function eleccionAbierta(object $votante, ?int $duenoId = null): object
    {
        $yearId = $votante->year_id;

        $votacionId = DB::table('vt_votaciones')->insertGetId([
            'user_id' => $duenoId ?? $votante->user_id,
            'year_id' => $yearId,
            'nombre' => 'Elección de prueba',
            'votan_profes' => 1,
            'votan_acudientes' => 1,
            'locked' => 0,
            'actual' => 1,
            'in_action' => 1,
            'can_see_results' => 0,
        ]);

        $aspiracionId = DB::table('vt_aspiraciones')->insertGetId([
            'votacion_id' => $votacionId,
            'aspiracion' => 'PERSONERO',
        ]);

        // **Los candidatos tienen que ser personas de verdad del seed.**
        // `VtCandidato::porAspiracion()` une `vt_candidatos` con `users` y con un
        // UNION de `profesores`/`alumnos`, así que un `user_id` inventado no se
        // filtra con un error: **desaparece de la papeleta en silencio**. Con
        // `user_id => 1` la lista salía con un solo elemento —el «Voto en
        // Blanco», que se añade después— y un `assertNotEmpty` encima pasaba sin
        // haber mirado ningún candidato.
        // Y **alumnos matriculados en este año**, no cualquier persona: la
        // consulta viva de `porAspiracion()` une solo con `alumnos`, exige
        // matrícula en MATR/ASIS/PREM y filtra por `usus.year_id = :year_id`.
        // (La versión comentada justo encima sí unía con profesores y usuarios;
        // la que corre, no.)
        $personas = DB::select('SELECT DISTINCT a.user_id FROM alumnos a
            INNER JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ?
            WHERE a.deleted_at IS NULL
            ORDER BY a.user_id LIMIT 2', [$yearId]);

        $this->assertCount(2, $personas,
            "El seed no tiene dos alumnos matriculados en el año {$yearId} para poner de candidatos.");

        $candidatos = [];

        foreach ($personas as $numero => $persona) {
            $candidatos[] = DB::table('vt_candidatos')->insertGetId([
                'user_id' => $persona->user_id,
                'aspiracion_id' => $aspiracionId,
                'plancha' => $numero + 1,
                'numero' => $numero + 1,
                'locked' => 0,
            ]);
        }

        return (object) [
            'votacion_id' => $votacionId,
            'aspiracion_id' => $aspiracionId,
            'candidatos' => $candidatos,
        ];
    }

    /**
     * Alguien del colegio que pueda pedir esta votación, y del año que importa.
     *
     * **El año no se elige, y aquí decide dos cosas a la vez.** Los candidatos
     * tienen que ser alumnos matriculados en el año de la votación —lo exige
     * `porAspiracion()`—, y los alumnos del seed están en los años 7 y 8. El
     * primer profesor del seed, en cambio, está en el 4: montar la elección
     * contra su año deja la papeleta vacía y el test no compara nada.
     *
     * Así que se parte de `grupoConAlumnos()`, que es el par año/grupo que sí
     * tiene gente, y se busca personal de ESE año. Se pide un `Usuario` por lo
     * mismo que lo hace `tokenDelPersonalDe()`: es el tipo que atraviesa los
     * guards, y aquí además no hace falta que sea profesor — la votación se crea
     * a su nombre, así que `putShow()` se la devuelve por `votacionesMias` sin
     * pasar por el censo.
     */
    private function votante(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene ningún Usuario en el año {$grupo->year_id}.");

        return (object) [
            'user_id' => (int) $usuario->id,
            'year_id' => (int) $grupo->year_id,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    /** Otro usuario del colegio, del mismo año, para poner de dueño de una votación ajena. */
    private function otroUsuarioDe(int $yearId, int $distintoDe): int
    {
        $otro = DB::selectOne('SELECT u.id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.is_active = 1 AND u.deleted_at IS NULL AND u.id <> ?
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$distintoDe, $yearId]);

        $this->assertNotNull($otro, "El seed no tiene un segundo usuario en el año {$yearId}.");

        return (int) $otro->id;
    }

    /**
     * `permitir` da la papeleta, no el recuento — y esa es toda la corrección.
     *
     * El campo lo manda una pantalla viva: `TarjetonesCtrl` pide
     * `permitir: true` para pintar el tarjetón. Y `tarjetones.html` **no dibuja
     * `cantidad` por ningún lado**; el que la dibuja es `resultados.html`, cuyo
     * controlador manda `permitir: false`. O sea que el front nunca quiso los
     * números: quería la estructura.
     *
     * El `if` de `putShow()` mezclaba las dos cosas —`can_see_results ||
     * permitir`—, así que el escrutinio en vivo viajaba dentro del JSON de
     * cualquiera que abriera el tarjetón. No en pantalla; en el JSON. Y el botón
     * «Tarjetones» del front **no lleva `ng-if`**, mientras sus tres hermanos sí,
     * así que lo alcanza un alumno.
     *
     * Se separaron: `permitir` decide la estructura, `can_see_results` decide el
     * número. Ver 11-votaciones.md §1.
     */
    public function test_con_los_resultados_ocultos_llega_la_papeleta_sin_el_conteo(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[0],
            ])->assertStatus(201);

        $sinPedirlo = $this->withToken($profe->token)
            ->putJson('/api/votos/show', [])
            ->assertStatus(200)
            ->json('votaciones');

        $nuestra = collect($sinPedirlo)->firstWhere('id', $eleccion->votacion_id);

        $this->assertNotNull($nuestra, 'La elección no le llega al profesor; el test no compara nada.');
        $this->assertArrayNotHasKey('aspiraciones', $nuestra,
            'Sin `permitir` y con los resultados ocultos no viaja ni la papeleta.');

        $conPapeleta = collect(
            $this->withToken($profe->token)
                ->putJson('/api/votos/show', ['permitir' => true])
                ->assertStatus(200)
                ->json('votaciones')
        )->firstWhere('id', $eleccion->votacion_id);

        // La papeleta sí: es lo que el tarjetón necesita para imprimirse.
        $this->assertArrayHasKey('aspiraciones', $conPapeleta);
        $this->assertSame($eleccion->aspiracion_id, $conPapeleta['aspiraciones'][0]['id']);

        $candidatos = $conPapeleta['aspiraciones'][0]['candidatos'];

        // Los dos candidatos y el «Voto en Blanco». Se cuenta en vez de mirar si
        // está vacío: con un `user_id` que no es una persona del seed la papeleta
        // sale con el blanco solo, y «no vacía» lo daría por bueno.
        $this->assertCount(3, $candidatos, 'La papeleta tiene que traer los dos candidatos y el blanco.');
        $this->assertEqualsCanonicalizing(
            $eleccion->candidatos,
            collect($candidatos)->pluck('candidato_id')->filter()->all(),
            'Los candidatos que se imprimen son los de esta aspiración.'
        );

        // Y el número no, que es el arreglo. Se mira candidato a candidato,
        // incluido el «Voto en Blanco» que se añade al final como uno más.
        foreach ($candidatos as $candidato) {
            $this->assertArrayNotHasKey('cantidad', $candidato,
                'El conteo en vivo viaja con la papeleta.');
            $this->assertArrayNotHasKey('total', $candidato,
                'El total en vivo viaja con la papeleta.');
        }
    }

    /**
     * Y con los resultados destapados, el conteo vuelve: no se ha roto la
     * pantalla de resultados, que es el riesgo de este arreglo.
     */
    public function test_con_los_resultados_visibles_el_conteo_llega(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[0],
            ])->assertStatus(201);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)
            ->update(['can_see_results' => 1]);

        $candidatos = collect(
            $this->withToken($profe->token)
                ->putJson('/api/votos/show', ['permitir' => false])
                ->assertStatus(200)
                ->json('votaciones')
        )->firstWhere('id', $eleccion->votacion_id)['aspiraciones'][0]['candidatos'];

        $votado = collect($candidatos)->firstWhere('candidato_id', $eleccion->candidatos[0]);

        $this->assertNotNull($votado, 'El candidato votado no está en el recuento.');
        $this->assertSame(1, (int) $votado['cantidad'], 'El voto emitido se cuenta.');
        $this->assertArrayHasKey('total', $votado);
    }

    /**
     * La papeleta cerrada con llave sigue aceptando votos.
     *
     * `locked` es el candado de la votación y `in_action` el interruptor de
     * «se está votando». `votos/store` no mira ninguno de los dos: inserta y
     * responde el voto.
     */
    public function test_se_vota_en_una_eleccion_cerrada(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)
            ->update(['locked' => 1, 'in_action' => 0]);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[0],
            ])
            ->assertStatus(201);

        $this->assertSame(
            1,
            DB::table('vt_votos')->where('user_id', $profe->user_id)
                ->where('candidato_id', $eleccion->candidatos[0])
                ->whereNull('deleted_at')->count(),
            'El voto entró con la votación cerrada y fuera de acción.'
        );
    }

    /**
     * Votar dos veces no deja dos votos: borra el primero.
     *
     * Y eso hay que mirarlo por el resultado, porque el método que lo hace se
     * llama `VtVoto::verificarNoVoto()` y **no verifica nada**: busca el voto
     * anterior del mismo usuario en la misma aspiración y lo manda a la papelera
     * para que quepa el nuevo. El nombre dice que comprueba; lo que hace es
     * dejar cambiar el voto.
     *
     * Que el recuento no se infle es lo que salva esto de ser un fallo grave, y
     * por eso se fija aquí: es la propiedad de la que depende todo lo demás.
     */
    public function test_votar_dos_veces_cambia_el_voto_y_no_lo_duplica(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        foreach ($eleccion->candidatos as $candidatoId) {
            $this->withToken($profe->token)
                ->postJson('/api/votos/store', [
                    'votacion_id' => $eleccion->votacion_id,
                    'candidato_id' => $candidatoId,
                ])
                ->assertStatus(201);
        }

        $vivos = DB::table('vt_votos')->where('user_id', $profe->user_id)
            ->whereIn('candidato_id', $eleccion->candidatos)
            ->whereNull('deleted_at')->pluck('candidato_id')->all();

        $this->assertSame([$eleccion->candidatos[1]], $vivos,
            'Debe quedar vivo solo el último voto: el recuento no se infla.');

        $this->assertSame(
            1,
            DB::table('vt_votos')->where('user_id', $profe->user_id)
                ->where('candidato_id', $eleccion->candidatos[0])
                ->whereNotNull('deleted_at')->count(),
            'Y el primero queda en la papelera, no borrado de verdad.'
        );
    }

    /**
     * `locked` en el voto tampoco lo protege.
     *
     * La columna existe y la consulta de `verificarNoVoto` la trae en el SELECT
     * —`vv.locked`— y luego no la mira. Un voto marcado como bloqueado se
     * sustituye igual que cualquier otro.
     */
    public function test_un_voto_bloqueado_se_sustituye(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        $votoId = DB::table('vt_votos')->insertGetId([
            'user_id' => $profe->user_id,
            'candidato_id' => $eleccion->candidatos[0],
            'locked' => 1,
        ]);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[1],
            ])
            ->assertStatus(201);

        $this->assertNotNull(
            DB::table('vt_votos')->where('id', $votoId)->value('deleted_at'),
            'El voto bloqueado se fue a la papelera igual.'
        );
    }

    /**
     * `votos/update` y `votos/destroy` no tocan votos: tocan CANDIDATOS.
     *
     * Los dos métodos están en `VtVotosController` y los dos hacen
     * `VtCandidato::findOrFail($id)`. Así que `DELETE api/votos/destroy/{id}`
     * **borra un candidato de la papeleta** —con todos sus votos apuntándole— y
     * el id que espera es el de `vt_candidatos`, no el del voto.
     *
     * Se fija tal como está porque las dos rutas existen y `auth.personal` las
     * cubre; lo que hace falta es que quede escrito qué borran de verdad, que es
     * lo contrario de lo que dice su nombre. Ver 11-votaciones.md §4.
     */
    public function test_votos_destroy_borra_un_candidato(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        $votoId = DB::table('vt_votos')->insertGetId([
            'user_id' => $profe->user_id,
            'candidato_id' => $eleccion->candidatos[0],
            'locked' => 0,
        ]);

        $this->withToken($profe->token)
            ->deleteJson('/api/votos/destroy/'.$eleccion->candidatos[0])
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('vt_candidatos')->where('id', $eleccion->candidatos[0])->value('deleted_at'),
            'Lo que se fue a la papelera fue el candidato.'
        );

        $this->assertNull(
            DB::table('vt_votos')->where('id', $votoId)->value('deleted_at'),
            'Y el voto, que es lo que la ruta dice borrar, sigue vivo.'
        );
    }

    /**
     * `PUT votos/update/{id}` no puede funcionar nunca.
     *
     * Rellena `tipo` y `abrev` sobre un `VtCandidato`, y `vt_candidatos` no
     * tiene esas columnas —el esquema congelado dice `plancha` y `numero`—.
     * Como el modelo va con `$fillable = []`, Eloquent lanza
     * `MassAssignmentException` antes de llegar a la base, el `catch` la
     * convierte en 422 y el resultado es el mismo con cualquier cuerpo.
     *
     * Va aquí, y no borrada, por la regla: con ruta y rota se documenta.
     */
    public function test_votos_update_responde_422_con_cualquier_cuerpo(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        foreach ([[], ['tipo' => 'X', 'abrev' => 'Y'], ['plancha' => 9]] as $cuerpo) {
            $this->withToken($profe->token)
                ->putJson('/api/votos/update/'.$eleccion->candidatos[0], $cuerpo)
                ->assertStatus(422);
        }

        $this->assertSame(
            1,
            (int) DB::table('vt_candidatos')->where('id', $eleccion->candidatos[0])->value('plancha'),
            'Y no escribe nada por el camino.'
        );
    }

    /**
     * Cualquiera del personal destapa los resultados de la votación de OTRO.
     *
     * Los seis `set-*` de `VtVotacionesController` reciben el `id` por el cuerpo
     * y su `UPDATE` **no lleva condición de dueño, ni de año, ni de papelera**:
     * `VtVotacion::where('id', $id)->update([...])`. Con `auth.personal` delante,
     * eso son los 51 profesores del colegio sobre cualquier elección.
     *
     * Y `set-permiso-ver-results` es el que más pesa, porque llega al mismo sitio
     * que la §1 por otro camino: la §1 se arregló para que el conteo no viajara
     * con la papeleta, y esto **enciende el conteo de verdad**, en la fila.
     *
     * Se fija sin arreglar: son los interruptores de la pantalla de
     * administración y acotarlos por dueño choca con que hoy «la votación
     * actual» ya significa dos cosas distintas según quién pregunte. Ver
     * 11-votaciones.md §5.
     */
    public function test_el_personal_destapa_los_resultados_de_la_votacion_de_otro(): void
    {
        $profe = $this->votante();
        $ajena = $this->eleccionAbierta($profe, $this->otroUsuarioDe($profe->year_id, $profe->user_id));

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-permiso-ver-results', [
                'id' => $ajena->votacion_id,
                'can_see_results' => true,
            ])
            ->assertStatus(200);

        $this->assertSame(
            1,
            (int) DB::table('vt_votaciones')->where('id', $ajena->votacion_id)->value('can_see_results'),
            'Se destapó el recuento de una elección que no es suya.'
        );
    }

    /** Y el candado de la elección de otro se abre igual. */
    public function test_el_personal_abre_el_candado_de_la_votacion_de_otro(): void
    {
        $profe = $this->votante();
        $ajena = $this->eleccionAbierta($profe, $this->otroUsuarioDe($profe->year_id, $profe->user_id));

        DB::table('vt_votaciones')->where('id', $ajena->votacion_id)->update(['locked' => 1]);

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-locked', ['id' => $ajena->votacion_id, 'locked' => false])
            ->assertStatus(200);

        $this->assertSame(0, (int) DB::table('vt_votaciones')->where('id', $ajena->votacion_id)->value('locked'));
    }

    /**
     * De los seis interruptores, dos escriben en la papelera y cuatro no.
     *
     * Y no es una decisión: es **por dónde se escribe**. Los cuatro que van por
     * Eloquent —`VtVotacion::where('id',$id)->update(...)`— los protege el scope
     * de `SoftDeletes` sin que nadie lo pidiera. Los dos que van por SQL crudo
     * —`set-actual` y `set-in-action`, que hacen
     * `DB::statement('UPDATE vt_votaciones v SET ... WHERE v.id=?')`— no lo
     * tienen, porque el scope vive en el modelo y ahí no hay modelo.
     *
     * Es la lección de 09, «la misma protección, dos caminos, y solo uno
     * cubierto», otra vez y en el mismo fichero: en un proyecto con 990
     * consultas crudas, lo que protege el modelo protege el camino que este
     * proyecto casi no usa. Aquí los dos caminos están **en la misma clase, a
     * setenta líneas de distancia**.
     *
     * El daño hoy es pequeño —los lectores filtran la papelera— pero deja filas
     * borradas cambiando de estado, así que un `restore` devuelve algo distinto
     * de lo que se borró.
     */
    public function test_solo_los_dos_interruptores_de_sql_crudo_escriben_en_la_papelera(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)
            ->update(['deleted_at' => now(), 'locked' => 0, 'in_action' => 0]);

        // Eloquent: el scope de SoftDeletes lo para, aunque nadie lo escribió.
        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-locked', ['id' => $eleccion->votacion_id, 'locked' => true])
            ->assertStatus(200);

        $this->assertSame(
            0,
            (int) DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->value('locked'),
            'set-locked va por Eloquent y no toca la papelera.'
        );

        // SQL crudo: entra.
        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-in-action', ['id' => $eleccion->votacion_id, 'in_action' => true])
            ->assertStatus(200);

        $this->assertSame(
            1,
            (int) DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->value('in_action'),
            'set-in-action va por DB::statement y sí entra en la papelera.'
        );
    }

    /**
     * Sin el campo en el cuerpo, tres de los seis se encienden solos.
     *
     * `Request::input('locked', true)`, y lo mismo `votan_profes`,
     * `votan_acudientes` y `actual`. Los otros dos —`in_action` y
     * `can_see_results`— tienen por defecto `false`. O sea que **una llamada con
     * solo el `id` dentro hace cosas opuestas según a qué interruptor le llegue**,
     * y en la mitad de ellos la cosa que hace es la restrictiva.
     *
     * Es la forma de 05 §26: una llamada sin el campo escribió el valor por
     * defecto sobre 1.280 alumnos. Aquí el daño es pequeño y la forma es la
     * misma, así que se deja fijada.
     */
    public function test_sin_el_campo_el_candado_se_cierra_solo(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        $this->assertSame(0, (int) DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->value('locked'));

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-locked', ['id' => $eleccion->votacion_id])
            ->assertStatus(200);

        $this->assertSame(
            1,
            (int) DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->value('locked'),
            'Sin mandar `locked`, la elección queda cerrada.'
        );

        // Y el de al lado hace lo contrario con el mismo cuerpo.
        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->update(['can_see_results' => 1]);

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-permiso-ver-results', ['id' => $eleccion->votacion_id])
            ->assertStatus(200);

        $this->assertSame(
            0,
            (int) DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->value('can_see_results'),
            'Sin mandar `can_see_results`, los resultados se tapan.'
        );
    }

    /**
     * El censo dice **a quién votó cada uno**, y eso lo ven los 51 profesores.
     *
     * `PUT participantes/votantes` devuelve, por cada matriculado del grupo y por
     * cada cargo de la elección, **las filas de `vt_votos`** con su
     * `candidato_id` dentro. No es un recuento agregado: es el voto de esa
     * persona, nominal.
     *
     * La [05 §18](05-codigo-muerto-y-roto.md) ya lo decía leyendo el código. Esto
     * lo fija por el resultado, que es otra cosa: lo que se comprueba aquí no es
     * que la consulta lo pida, es que **llega al cliente**.
     *
     * Se fija sin arreglar. El voto secreto es una decisión del colegio y no del
     * código: puede que la pantalla exista precisamente para auditar quién votó
     * —hay colegios que lo quieren— o puede que sea un descuido. Lo que no puede
     * es no estar escrito. Ver 11-votaciones.md §6.
     */
    public function test_el_censo_dice_a_quien_voto_cada_uno(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);
        $grupo = $this->grupoConAlumnos();

        // Un alumno del grupo emite su voto.
        $alumno = DB::selectOne('SELECT a.user_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE m.grupo_id = ? AND a.deleted_at IS NULL AND a.user_id IS NOT NULL
            ORDER BY a.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($alumno, 'El grupo del seed no tiene alumnos con cuenta.');

        DB::table('vt_votos')->insert([
            'user_id' => $alumno->user_id,
            'candidato_id' => $eleccion->candidatos[0],
            'locked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $participantes = $this->withToken($profe->token)
            ->putJson('/api/participantes/votantes', [
                'grupo_id' => $grupo->id,
                'votacion_id' => $eleccion->votacion_id,
            ])
            ->assertStatus(200)
            ->json('participantes');

        $suyo = collect($participantes)->firstWhere('user_id', $alumno->user_id);

        $this->assertNotNull($suyo, 'El votante sale en el censo.');

        $votos = collect($suyo['aspiraciones'])->pluck('votos')->flatten(1);

        $this->assertSame(
            [$eleccion->candidatos[0]],
            $votos->pluck('candidato_id')->all(),
            'Y con él viaja el candidato al que votó, por su nombre.'
        );
    }

    /**
     * Y cuesta una consulta por participante y cargo, medido.
     *
     * `putVotantes()` tiene dos bucles anidados y **la consulta de aspiraciones
     * está dentro del primero**: se lanza una vez por participante con los mismos
     * parámetros y el mismo resultado. Después, una consulta de votos por cada
     * cargo de cada participante.
     *
     * O sea `P × (1 + A)` consultas para P matriculados y A cargos. Con los 30 de
     * un grupo y cuatro cargos son **150**, y el grupo grande de un colegio real
     * tiene más de treinta.
     *
     * Es la misma forma que el bucle de `respuestas/actividad` en
     * [13-actividades.md §5.3](13-actividades.md) —trabajo repetido dentro de un
     * bucle, resultado correcto, nadie lo nota— y sale el mismo día en dos
     * dominios distintos. Se mide y no se arregla: sacar la consulta del bucle es
     * de una línea, pero primero hay que tener fijada la forma, y eso es el test
     * de arriba.
     */
    public function test_el_censo_repite_la_consulta_de_cargos_por_cada_participante(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);
        $grupo = $this->grupoConAlumnos();

        $participantes = DB::table('matriculas')->where('grupo_id', $grupo->id)
            ->whereIn('estado', ['MATR', 'ASIS'])->whereNull('deleted_at')->count();

        $this->assertGreaterThan(1, $participantes, 'Con un solo participante no se ve la repetición.');

        $consultasDeCargos = 0;

        DB::listen(function ($consulta) use (&$consultasDeCargos) {
            if (str_contains($consulta->sql, 'FROM vt_aspiraciones WHERE votacion_id')) {
                $consultasDeCargos++;
            }
        });

        $this->withToken($profe->token)
            ->putJson('/api/participantes/votantes', [
                'grupo_id' => $grupo->id,
                'votacion_id' => $eleccion->votacion_id,
            ])
            ->assertStatus(200);

        $this->assertSame($participantes, $consultasDeCargos,
            "La lista de cargos se pidió {$consultasDeCargos} veces, una por participante. ".
            'Debería pedirse una sola. Si esto baja a 1, alguien la sacó del bucle: bórrese este test.');
    }

    /**
     * Y no comprueba que el grupo y la elección tengan nada que ver.
     *
     * `grupo_id` y `votacion_id` llegan por el cuerpo y se usan por separado: uno
     * elige a los participantes y el otro los cargos. Nadie mira si ese grupo
     * está inscrito en esa elección —que es lo que dice `vt_participantes`—, así
     * que se puede pedir el censo de un grupo cualquiera contra una elección
     * cualquiera y sale una tabla con sentido aparente.
     */
    public function test_el_grupo_y_la_eleccion_no_tienen_que_ver_nada(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);
        $ajeno = $this->grupoAjenoDelMismoAnio($profe->year_id);

        $this->withToken($profe->token)
            ->putJson('/api/participantes/votantes', [
                'grupo_id' => $ajeno->grupo_id,
                'votacion_id' => $eleccion->votacion_id,
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['participantes']);
    }
}
