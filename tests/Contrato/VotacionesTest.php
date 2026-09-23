<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las votaciones del colegio, miradas por el resultado.
 *
 * Los guards de estas rutas ya estaban puestos y `AutorizacionTest` los fija.
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
 *
 * **El rediseño del 22 sep 2026 (11-votaciones.md §8) cerró casi todo lo que esta
 * clase fijaba como fallo**, y Joseth decidió pasar los tests al contrato nuevo:
 * los que fijaban un fallo cerrado se invirtieron para fijar el arreglo. Seis se
 * borraron porque su ruta ya no existe —`RutasTest` vigila el conjunto—:
 * `votos/destroy` y `votos/update` (tocaban candidatos, §4; el voto es inmutable),
 * los tres de `participantes/votantes` (el voto nominal, su N+1 y el grupo sin
 * relación, §6; la tabla `vt_participantes` se fue y la lista nominal sale sólo por
 * `auditoria/{id}`) y `GET votos` (§7.1).
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
            // El votante de estos tests es un `Usuario`, o sea del estamento
            // administrativo, y desde el §8 su estamento vota sólo si se enciende:
            // la columna nace en 0 y sin esto `votos/store` contesta 403.
            'votan_administrativos' => 1,
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
     * guards, y aquí además no hace falta que sea profesor.
     *
     * **Y que NO sea superusuario**, desde el 23 sep 2026. Antes era el primero
     * del tipo —el usuario 1, superusuario—, y con el §9.1 eso deja de valer: el
     * superusuario ve el recuento siempre y administra cualquier elección, así que
     * «el personal no puede X» se leía como «el superusuario puede X» y salía verde
     * por el motivo equivocado (la trampa que cuenta `usuarioLlanoDelPersonal()`).
     */
    private function votante(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.is_superuser = 0
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario,
            "El seed no tiene ningún Usuario que no sea superusuario en el año {$grupo->year_id}.");

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
     *
     * **Y desde el §9.1 (23 sep 2026) el número lo ve quien puede publicarlo**
     * —superusuario, rectoría, coordinación y el dueño de la elección— aunque
     * `can_see_results` esté apagado. Por eso la elección es de otro: con el
     * votante de dueño, el conteo le llegaría y el test mediría otra regla.
     */
    public function test_con_los_resultados_ocultos_llega_la_papeleta_sin_el_conteo(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe, $this->otroUsuarioDe($profe->year_id, $profe->user_id));

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

        // Le llega por `actualesInscrito()` —está en acción y su estamento vota—,
        // no por ser suya.
        $this->assertNotNull($nuestra, 'La elección no le llega al votante; el test no compara nada.');
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
     *
     * La elección es de otro por lo mismo que en el test de arriba: al dueño el
     * número le llega siempre (§9.1), y aquí lo que se mira es que lo abra el
     * interruptor.
     */
    public function test_con_los_resultados_visibles_el_conteo_llega(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe, $this->otroUsuarioDe($profe->year_id, $profe->user_id));

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
     * Con la urna pausada no se vota — **arreglado el 21 ago 2026**.
     *
     * `locked` es el único interruptor que para la votación, y `postStore()` no lo
     * leía: se votaba con el candado echado.
     *
     * **Es una pausa y no un cierre**, y por eso el código es **423 Locked** y el
     * mensaje dice «pausada». Joseth: *«se puede inactivar la votación tal como
     * esté y luego continuar para que los alumnos que no habían podido votar sigan
     * votando»*. Decirle a un alumno que la votación terminó cuando va a seguir en
     * diez minutos sería la misma clase de mentira que esta serie persigue.
     */
    public function test_con_la_urna_pausada_no_se_vota(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->update(['locked' => 1]);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[0],
            ])
            ->assertStatus(423)
            ->assertSee('pausada');

        $this->assertSame(
            0,
            DB::table('vt_votos')->where('user_id', $profe->user_id)->whereNull('deleted_at')->count(),
            'Y no entró el voto.'
        );
    }

    /**
     * Y al reanudarla, el que no había podido votar vota.
     *
     * Es la otra mitad de «pausa, no cierre», y la que hace falta fijar: un
     * arreglo que dejara la votación inservible después de un `locked` sería peor
     * que el fallo. Se comprueba el viaje entero — pausar, rechazar, reanudar,
     * votar.
     */
    public function test_al_reanudarla_se_sigue_votando(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->update(['locked' => 1]);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[0],
            ])
            ->assertStatus(423);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->update(['locked' => 0]);

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
            'El voto que la pausa había frenado entra al reanudar.'
        );
    }

    /**
     * Sin estar en acción no se vota — **invertido el 23 sep 2026**.
     *
     * Hasta el rediseño esto fijaba lo contrario («con `in_action = 0` también se
     * vota, y así debe ser»), que era la decisión del §2.1: `in_action` era un
     * redirector del front y no un candado. El encargo del 22 sep pidió exigirlo
     * y se exige: `VtVotacion::exigirUrnaAbierta()` contesta **423** con «no está
     * abierta».
     *
     * Es criterio **sin decidir** (11-votaciones.md §8, «Lo que queda», punto 1):
     * vive en un solo `if` rotulado para poder quitarse. Si se quita, este test es
     * el que tiene que volver a su forma anterior.
     */
    public function test_sin_estar_en_accion_no_se_vota(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)
            ->update(['in_action' => 0, 'locked' => 0]);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[0],
            ])
            ->assertStatus(423)
            ->assertJsonPath('message', 'La votación no está abierta');

        $this->assertSame(
            0,
            DB::table('vt_votos')->where('user_id', $profe->user_id)
                ->where('votacion_id', $eleccion->votacion_id)->count(),
            'Con la elección fuera de acción no entra el voto.'
        );
    }

    /**
     * Votar dos veces el mismo cargo: el segundo es un **409** y el primero se
     * queda — **invertido el 23 sep 2026**.
     *
     * Antes esto fijaba que el segundo voto **sustituía** al primero: lo hacía
     * `VtVoto::verificarNoVoto()`, que no verificaba nada y mandaba el anterior a
     * la papelera (11-votaciones.md §3). El rediseño (§8, punto 4) hizo el voto
     * inmutable y lo garantiza la base, con el índice único
     * `(votacion_id, aspiracion_id, user_id)`.
     *
     * Y el 409 dice **que** votó, no **a quién**: la constancia no lleva el
     * candidato, porque eso sería la fuga del voto secreto del §6 por otra puerta.
     */
    public function test_votar_dos_veces_el_mismo_cargo_da_409_y_no_cambia_el_voto(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[0],
            ])
            ->assertStatus(201);

        $segundo = $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[1],
            ])
            ->assertStatus(409);

        $this->assertSame($eleccion->aspiracion_id, (int) $segundo->json('voto.aspiracion_id'),
            'El 409 trae la constancia del voto que ya había en ese cargo.');
        $this->assertArrayNotHasKey('candidato_id', $segundo->json('voto'),
            'Y la constancia no dice a quién votó.');

        $this->assertSame(
            [$eleccion->candidatos[0]],
            DB::table('vt_votos')->where('user_id', $profe->user_id)
                ->where('votacion_id', $eleccion->votacion_id)
                ->pluck('candidato_id')->all(),
            'Queda un solo voto, y es el primero: el recuento no se infla y el voto no cambia.'
        );
    }

    /**
     * Un voto que ya estaba en la urna no se sustituye — **invertido el 23 sep
     * 2026**.
     *
     * Antes fijaba que ni siquiera `vt_votos.locked = 1` protegía un voto:
     * `verificarNoVoto()` lo traía en el `SELECT` y no lo miraba (§3). Ahora la
     * pregunta ya no es el `locked` de la fila: **ningún** voto emitido se toca.
     * Se monta la fila a mano —como la dejaría una mesa u otra petición— para
     * comprobar que `votos/store` la reconoce aunque no la haya escrito él.
     */
    public function test_un_voto_que_ya_estaba_no_se_sustituye(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        $votoId = DB::table('vt_votos')->insertGetId([
            'user_id' => $profe->user_id,
            'votacion_id' => $eleccion->votacion_id,
            'aspiracion_id' => $eleccion->aspiracion_id,
            'candidato_id' => $eleccion->candidatos[0],
            'locked' => 1,
        ]);

        $this->withToken($profe->token)
            ->postJson('/api/votos/store', [
                'votacion_id' => $eleccion->votacion_id,
                'candidato_id' => $eleccion->candidatos[1],
            ])
            ->assertStatus(409);

        $voto = DB::table('vt_votos')->where('id', $votoId)->first();

        $this->assertNull($voto->deleted_at, 'El voto que ya estaba sigue vivo.');
        $this->assertSame($eleccion->candidatos[0], (int) $voto->candidato_id, 'Y con su candidato.');
    }

    /**
     * El personal ya no destapa los resultados de la votación de OTRO — **invertido
     * el 23 sep 2026**.
     *
     * Antes fijaba que sí: los `set-*` recibían el `id` por el cuerpo y su `UPDATE`
     * no llevaba condición de dueño, así que cualquiera con `auth.personal` —los
     * 51 profesores— encendía el recuento de cualquier elección (11-votaciones.md
     * §5). Hasta hoy este test salía verde **por el motivo equivocado**: su
     * votante era un superusuario, que sí puede.
     *
     * Ahora `set-permiso-ver-results` pasa por `VtVotacion::exigirPublicable()`
     * (§9.1): superusuario, rectoría, coordinación o quien creó la elección. Un
     * `Usuario` llano con rol de psicología, sobre la elección de otro, recibe
     * **403** y la fila no se mueve. El control es su propia elección, donde el
     * mismo cuerpo contesta 200: así el 403 es por el dueño y no por otra cosa.
     */
    public function test_el_personal_no_destapa_los_resultados_de_la_votacion_de_otro(): void
    {
        $profe = $this->votante();
        $ajena = $this->eleccionAbierta($profe, $this->otroUsuarioDe($profe->year_id, $profe->user_id));

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-permiso-ver-results', [
                'id' => $ajena->votacion_id,
                'can_see_results' => true,
            ])
            ->assertStatus(403);

        $this->assertSame(
            0,
            (int) DB::table('vt_votaciones')->where('id', $ajena->votacion_id)->value('can_see_results'),
            'El recuento de una elección que no es suya sigue tapado.'
        );

        $propia = $this->eleccionAbierta($profe);

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-permiso-ver-results', [
                'id' => $propia->votacion_id,
                'can_see_results' => true,
            ])
            ->assertStatus(200);

        $this->assertSame(
            1,
            (int) DB::table('vt_votaciones')->where('id', $propia->votacion_id)->value('can_see_results'),
            'Y en la suya sí lo publica: el 403 de arriba es por no ser el dueño.'
        );
    }

    /**
     * Y el candado de la elección de otro tampoco se abre — **invertido el 23 sep
     * 2026**, por lo mismo: `set-locked` pasa por
     * `VtVotacion::exigirAdministrable()` (superusuario o dueño) y contesta 403.
     * Ver 11-votaciones.md §5 y §8.
     */
    public function test_el_personal_no_abre_el_candado_de_la_votacion_de_otro(): void
    {
        $profe = $this->votante();
        $ajena = $this->eleccionAbierta($profe, $this->otroUsuarioDe($profe->year_id, $profe->user_id));

        DB::table('vt_votaciones')->where('id', $ajena->votacion_id)->update(['locked' => 1]);

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-locked', ['id' => $ajena->votacion_id, 'locked' => false])
            ->assertStatus(403);

        $this->assertSame(1, (int) DB::table('vt_votaciones')->where('id', $ajena->votacion_id)->value('locked'),
            'El candado de la elección ajena sigue echado.');
    }

    /**
     * Ningún interruptor escribe en una elección de la papelera — **invertido el 23
     * sep 2026**.
     *
     * Antes fijaba que dos sí: `set-actual` y `set-in-action` iban por
     * `DB::statement('UPDATE …')` y el scope de `SoftDeletes`, que vive en el
     * modelo, no los cubría; los otros cuatro iban por Eloquent y se salvaban
     * sin que nadie lo pidiera (11-votaciones.md §5.1). La lección de 09 —«la
     * misma protección, dos caminos, y solo uno cubierto»— en la misma clase.
     *
     * Ahora todos pasan por `VtVotacion::exigirAdministrable()`, que busca con
     * `find()` y el scope puesto: una elección en la papelera es **404** por los
     * dos caminos, y la fila no cambia. Se prueba uno de cada antiguo camino.
     */
    public function test_ningun_interruptor_escribe_en_la_papelera(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)
            ->update(['deleted_at' => now(), 'locked' => 0, 'in_action' => 0]);

        // El que iba por Eloquent.
        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-locked', ['id' => $eleccion->votacion_id, 'locked' => true])
            ->assertStatus(404);

        // El que iba por SQL crudo, y es el que antes entraba.
        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-in-action', ['id' => $eleccion->votacion_id, 'in_action' => true])
            ->assertStatus(404);

        $fila = DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->first();

        $this->assertSame(0, (int) $fila->locked, 'set-locked no toca la papelera.');
        $this->assertSame(0, (int) $fila->in_action, 'set-in-action tampoco, que es lo que cambió.');
    }

    /**
     * Sin el campo en el cuerpo, un interruptor contesta **422** y no escribe —
     * **invertido el 23 sep 2026**.
     *
     * Antes fijaba que una llamada con sólo el `id` hacía cosas opuestas según a
     * qué interruptor llegara: `set-locked` cerraba la elección (defecto `true`) y
     * `set-permiso-ver-results` tapaba los resultados (defecto `false`). Es la forma
     * de 05 §26, y el §8 la cierra en «Averías que se encontraron de paso»: el
     * valor es obligatorio. Se prueban los dos que antes iban en sentidos
     * contrarios, para que quede escrito que ahora contestan lo mismo.
     */
    public function test_sin_el_campo_el_interruptor_da_422_y_no_escribe(): void
    {
        $profe = $this->votante();
        $eleccion = $this->eleccionAbierta($profe);

        DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->update(['can_see_results' => 1]);

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-locked', ['id' => $eleccion->votacion_id])
            ->assertStatus(422);

        $this->withToken($profe->token)
            ->putJson('/api/votaciones/set-permiso-ver-results', ['id' => $eleccion->votacion_id])
            ->assertStatus(422);

        $fila = DB::table('vt_votaciones')->where('id', $eleccion->votacion_id)->first();

        $this->assertSame(0, (int) $fila->locked, 'Sin mandar `locked`, la elección sigue abierta.');
        $this->assertSame(1, (int) $fila->can_see_results, 'Sin mandar `can_see_results`, sigue como estaba.');
    }

    /**
     * Sin elección, la papeleta le contesta al alumno lo mismo que al personal —
     * **invertido el 23 sep 2026**.
     *
     * Antes fijaba un **500**: `getConaspiraciones()` se abría en dos ramas y la
     * comprobación de nulo estaba sólo en la del personal, así que un alumno sin
     * elección —el caso normal casi todo el año— llegaba a `$votacion->id` con
     * `null` (05 §18.4, 11-votaciones.md §7.2). El rediseño (§8) sacó la
     * comprobación de las dos ramas: ahora los cuatro tipos de usuario reciben la
     * misma forma, `[{sin_votaciones_propias: true}]`, en 200.
     */
    public function test_sin_eleccion_la_papeleta_contesta_igual_al_alumno_y_al_personal(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($alumno->username);

        // No se monta ninguna elección: es el estado normal del colegio.
        $this->withToken($token)
            ->getJson('/api/candidatos/conaspiraciones')
            ->assertStatus(200)
            ->assertExactJson([['sin_votaciones_propias' => true]]);

        $profe = $this->votante();

        $this->withToken($profe->token)
            ->getJson('/api/candidatos/conaspiraciones')
            ->assertStatus(200)
            ->assertExactJson([['sin_votaciones_propias' => true]]);
    }
}
