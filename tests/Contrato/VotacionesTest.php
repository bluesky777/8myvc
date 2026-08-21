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
    private function eleccionAbierta(object $votante): object
    {
        $yearId = $votante->year_id;

        $votacionId = DB::table('vt_votaciones')->insertGetId([
            'user_id' => $votante->user_id,
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
}
