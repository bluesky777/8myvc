<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las cuatro filas de `route:list` cuya respuesta no miraba nadie.
 *
 * El [09](../../docs/migracion/09-pendientes.md) las dejó con nombre —«*lo que
 * queda sin comprobar tiene nombre: tres métodos y un verbo*»— y ahí se quedaron.
 * Esto las cierra. No es un resto difuso del 1%: son **crear una escala de
 * valoración, crear una definición de comportamiento escrita, crear una frase de
 * asignatura**, y el `PATCH` de `tiposdocumento`, que apunta al mismo método que
 * su `PUT` de la línea de arriba.
 *
 * > **Que la cobertura cuente rutas y no métodos es correcto —un verbo puede
 * > llevar otro guard— pero al leer el resto hay que separar las dos cosas**, o se
 * > sale a escribir un test para código que ya tiene uno. Por eso el del `PATCH`
 * > no comprueba lo que hace `update`, que ya está comprobado: comprueba que **el
 * > verbo llega al mismo sitio**, que es lo único que la otra ruta no dice.
 *
 * Las tres primeras son **crear una fila de catálogo**, y que sean justo esas tres
 * no es casualidad: los lotes de catálogos midieron editar y borrar, que es donde
 * estaban los síntomas.
 *
 * Y como el resto del contrato: **se mira el resultado, no el estado.** Lo que se
 * fija de cada una no es que conteste 200 —las cuatro contestan 200— sino **qué
 * fila queda escrita y qué cuerpo vuelve**, que es lo que lee el front.
 */
class TresMetodosYUnVerboTest extends CasoDeContrato
{
    /**
     * **`POST escalas/store` no crea la escala que le mandas: crea siempre la
     * misma.**
     *
     * El `INSERT` lleva los valores **escritos dentro de la consulta** —`SUPERIOR`,
     * orden 5, `S`, 91–100, `perdido = 0`— y no lee el cuerpo ni una vez
     * ([EscalasDeValoracionController:32](../../app/Http/Controllers/EscalasDeValoracionController.php#L32)).
     * O sea que es un **«añadir fila»** de rejilla: la pantalla crea el renglón con
     * una plantilla y después lo edita con `escalas/update`, que sí lee el cuerpo.
     *
     * Se comprueba mandando un cuerpo **contrario** a la plantilla en vez de uno
     * vacío, que es lo que separa «no lee el cuerpo» de «el cuerpo venía vacío».
     * Con un cuerpo vacío las dos hipótesis dan el mismo resultado y el test no
     * distinguiría cuál es cierta — que es justo lo que tiene que quedar escrito
     * aquí, porque **el día que alguien le añada validación al cuerpo esta ruta
     * dejará de funcionar sin que el cuerpo haya cambiado**.
     */
    public function test_crear_una_escala_ignora_el_cuerpo_y_escribe_siempre_la_plantilla(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        $yearId = (int) DB::table('users')
            ->join('periodos', 'periodos.id', '=', 'users.periodo_id')
            ->where('users.id', $usuario->id)
            ->value('periodos.year_id');

        $antes = DB::table('escalas_de_valoracion')->where('year_id', $yearId)->count();

        $cuerpo = $this->withToken($token)->postJson('/api/escalas/store', [
            'desempenio' => 'BAJO',
            'valoracion' => 'B',
            'porc_inicial' => 0,
            'porc_final' => 10,
            'orden' => 1,
            'perdido' => 1,
        ])->assertStatus(200)->json();

        $this->assertSame($antes + 1, DB::table('escalas_de_valoracion')->where('year_id', $yearId)->count(),
            'No quedó la fila nueva: el «añadir renglón» de la rejilla de escalas no añade nada.');

        // Lo que importa: NINGUNO de los seis campos que se mandaron llegó.
        $this->assertSame('SUPERIOR', $cuerpo['desempenio']);
        $this->assertSame('S', $cuerpo['valoracion']);
        $this->assertSame(91, (int) $cuerpo['porc_inicial']);
        $this->assertSame(100, (int) $cuerpo['porc_final']);
        $this->assertSame(5, (int) $cuerpo['orden']);
        $this->assertSame(0, (int) $cuerpo['perdido']);

        // Y en el año del que pregunta, no en el que venga por ninguna parte.
        $this->assertSame($yearId, (int) $cuerpo['year_id']);

        // La fila devuelta es la que se acaba de escribir, y eso hay que mirarlo en
        // la tabla: el método la relee con `ORDER BY id DESC` sobre TODO el año, así
        // que devolver «la última del año» y «la que acabo de crear» sólo coinciden
        // mientras los ids crezcan. Si algún día no coinciden, la rejilla pinta el
        // renglón de otro y lo edita.
        $ultima = DB::table('escalas_de_valoracion')->where('year_id', $yearId)
            ->orderByDesc('id')->first();

        $this->assertSame((int) $ultima->id, (int) $cuerpo['id']);
    }

    /**
     * **Crear una definición de comportamiento escrita**, que es la mitad libre de
     * esa pantalla: la otra ruta —`store`— engancha una frase del catálogo, y ésta
     * escribe el texto a mano.
     *
     * Se comprueba **dónde cae el texto**, y no es cosmético: la tabla tiene las
     * dos columnas, `frase_id` y `frase`, y quien lee la rejilla resuelve con
     * `IFNULL(f.frase, dc.frase)`. Una fila que guardara el texto en el sitio
     * equivocado se vería igual de bien en la respuesta de esta ruta y **vacía en
     * la rejilla**.
     */
    public function test_crear_una_definicion_escrita_guarda_el_texto_a_mano_y_no_del_catalogo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $comportamiento = DB::table('nota_comportamiento')->orderBy('id')->first();
        $this->assertNotNull($comportamiento, 'El seed no tiene ninguna nota de comportamiento.');

        $cuerpo = $this->withToken($token)->postJson('/api/definiciones_comportamiento/store-escrita', [
            'comportamiento_id' => $comportamiento->id,
            'frase' => 'Llegó tarde tres veces esta semana.',
        ])->assertStatus(201)->json();

        $fila = DB::table('definiciones_comportamiento')->where('id', $cuerpo['id'])->first();

        $this->assertNotNull($fila, 'Contestó con un id que no está en la tabla.');
        $this->assertSame('Llegó tarde tres veces esta semana.', $fila->frase);
        $this->assertNull($fila->frase_id,
            'Guardó la frase escrita a mano como si viniera del catálogo: la rejilla la leerá vacía.');
        $this->assertSame((int) $comportamiento->id, (int) $fila->comportamiento_id);
    }

    /**
     * Y con el cuerpo vacío **no escribe**, aunque lo diga con un 500.
     *
     * Es exactamente la misma forma que `CrearUnCatalogoTest` fija para sus nueve
     * hermanas, y la lección de aquel test vale aquí entera: **lo que impide que
     * escriba basura no es el código —no hay ninguna validación— sino el esquema.**
     * `comportamiento_id` es `NOT NULL` con clave ajena, así que el `INSERT`
     * revienta; sin ese `NOT NULL` esto sería un 200 con una fila huérfana, que es
     * lo que le pasó a `contratos`.
     *
     * El 500 se fija tal cual está y no se arregla aquí: cambiarlo es cambiarle la
     * respuesta a un front desplegado en dieciséis colegios, y esta sesión mide.
     */
    public function test_una_definicion_escrita_con_el_cuerpo_vacio_no_deja_fila(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $antes = DB::table('definiciones_comportamiento')->count();

        $this->withToken($token)
            ->postJson('/api/definiciones_comportamiento/store-escrita', [])
            ->assertStatus(500);

        $this->assertSame($antes, DB::table('definiciones_comportamiento')->count(),
            'Escribió una definición con el cuerpo vacío.');
    }

    /**
     * **`POST frases_asignatura/store` sin el parámetro guarda el texto; con él,
     * engancha la frase del catálogo — y entonces ignora el texto.**
     *
     * Las dos mitades van en el mismo test a propósito: el `if ($frase_id=='')` de
     * [FrasesAsignaturaController:28](../../app/Http/Controllers/FrasesAsignaturaController.php#L28)
     * es un `else`, no un `and`, así que **la segunda forma descarta el `frase`
     * del cuerpo**. Un test que sólo mirara una de las dos pasaría con las ramas
     * cambiadas.
     *
     * Y lo que devuelve **no es la frase creada: es la lista entera de las frases
     * de ese alumno en esa asignatura**, ya resuelta con `IFNULL(f.frase,
     * fa.frase)`. O sea que la respuesta de crear y la de `getShow` tienen la misma
     * forma, que es lo que permite a la pantalla repintar sin pedir otra vez.
     */
    public function test_crear_una_frase_de_asignatura_por_las_dos_puertas(): void
    {
        [$token, $ctx] = $this->profesorConAsignatura();

        // --- sin `frase_id`: el texto va a `frase` ---
        $lista = $this->withToken($token)->postJson('/api/frases_asignatura/store', [
            'alumno_id' => $ctx['alumno'],
            'asignatura_id' => $ctx['asignatura'],
            'frase' => 'Participa en clase.',
        ])->assertStatus(200)->json();

        $this->assertCount(1, $lista, 'La respuesta no es la lista de frases del alumno en esa asignatura.');
        $this->assertSame('Participa en clase.', $lista[0]['frase']);
        $this->assertNull($lista[0]['frase_id']);

        $escrita = DB::table('frases_asignatura')->where('id', $lista[0]['id'])->first();
        $this->assertSame('Participa en clase.', $escrita->frase);

        // El periodo es el DEL USUARIO y no el que venga en el cuerpo. Lo dice §27 y
        // lo dice el propio método; se comprueba porque es la clase de cosa que se
        // pierde al reescribir el método pensando en el cuerpo.
        $this->assertSame($ctx['periodo'], (int) $escrita->periodo_id);

        // --- con `frase_id`: engancha el catálogo y descarta el texto ---
        $frase = DB::table('frases')->whereNull('deleted_at')->orderBy('id')->first();
        $this->assertNotNull($frase, 'El seed no tiene frases de catálogo.');

        $lista = $this->withToken($token)->postJson('/api/frases_asignatura/store/'.$frase->id, [
            'alumno_id' => $ctx['alumno'],
            'asignatura_id' => $ctx['asignatura'],
            'frase' => 'Este texto NO se tiene que guardar.',
        ])->assertStatus(200)->json();

        $this->assertCount(2, $lista);

        $delCatalogo = null;

        foreach ($lista as $fila) {
            if ($fila['frase_id'] !== null) {
                $delCatalogo = $fila;
            }
        }

        $this->assertNotNull($delCatalogo, 'La segunda no quedó enganchada al catálogo.');
        $this->assertSame((int) $frase->id, (int) $delCatalogo['frase_id']);

        // La respuesta trae el texto DEL CATÁLOGO, no el del cuerpo: es el
        // `IFNULL(f.frase, fa.frase)` del modelo, y es lo que ve el docente.
        $this->assertSame($frase->frase, $delCatalogo['frase']);

        $enganchada = DB::table('frases_asignatura')->where('id', $delCatalogo['id'])->first();
        $this->assertNull($enganchada->frase,
            'Guardó el texto del cuerpo Y el id del catálogo: la fila tiene dos frases y sólo se lee una.');
    }

    /**
     * Y **con el periodo cerrado no se escribe ninguna frase.**
     *
     * `pueden_editar_notas` va antes del `save()`, igual que en las notas, y esto
     * es lo que dice que sigue yendo antes. Se mira **la tabla y no el código de la
     * respuesta**: un 400 con la fila ya escrita cumpliría el aserto del código y
     * sería el fallo entero.
     */
    public function test_con_el_periodo_cerrado_no_se_crea_la_frase(): void
    {
        [$token, $ctx] = $this->profesorConAsignatura();

        DB::table('periodos')->where('id', $ctx['periodo'])
            ->update(['profes_pueden_editar_notas' => 0]);

        $antes = DB::table('frases_asignatura')->count();

        $this->withToken($token)->postJson('/api/frases_asignatura/store', [
            'alumno_id' => $ctx['alumno'],
            'asignatura_id' => $ctx['asignatura'],
            'frase' => 'No debería entrar.',
        ])->assertStatus(400);

        $this->assertSame($antes, DB::table('frases_asignatura')->count(),
            'Escribió la frase con el periodo cerrado: el permiso se está comprobando después del save().');
    }

    /**
     * **El `PATCH` de `tiposdocumento` llega al mismo `update` que su `PUT`.**
     *
     * `routes/api/catalogos.php:40-41` registra los dos verbos contra el mismo
     * método. Lo que este test comprueba **no es lo que hace `update`** —eso ya lo
     * comprueban `EditarUnCatalogoTest` y compañía, y repetirlo aquí sería escribir
     * un test para código que ya tiene uno— sino **que el verbo llega**: que
     * `PATCH` no se queda en un 405 ni cae en otra ruta.
     *
     * Se comprueba con un cuerpo **parcial**, que es lo que un `PATCH` significa y
     * lo único que lo distingue de repetir el `PUT`: llega `abrev` y no llega
     * `tipo`, y el `tipo` tiene que seguir donde estaba. Esa es la garantía que
     * `CamposQueVinieron` da y la que se perdería si alguien reescribiera `update`
     * leyendo el cuerpo a secas — y se perdería **sólo por el `PATCH`**, porque por
     * el `PUT` el front manda la fila entera.
     */
    public function test_el_patch_de_tiposdocumento_edita_igual_que_el_put(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $tipo = DB::table('tipos_documentos')->whereNull('deleted_at')->orderBy('id')->first();
        $this->assertNotNull($tipo, 'El seed no tiene tipos de documento.');

        $this->withToken($token)
            ->json('PATCH', '/api/tiposdocumento/'.$tipo->id, ['abrev' => 'XX'])
            ->assertStatus(200);

        $despues = DB::table('tipos_documentos')->where('id', $tipo->id)->first();

        $this->assertSame('XX', $despues->abrev, 'El PATCH no llegó a `update`.');
        $this->assertSame($tipo->tipo, $despues->tipo,
            'El PATCH borró el campo que no venía en el cuerpo. `CamposQueVinieron` es justo lo que '
            .'impide eso, y por el PUT no se notaría: el front manda la fila entera.');
    }

    /**
     * Un profesor con el periodo abierto, y un alumno matriculado en una asignatura
     * de su año.
     *
     * Es el montaje de `GuardarNotasEnLoteTest` recortado a lo que hace falta aquí:
     * no hacen falta notas, sólo un par (alumno, asignatura) que exista de verdad —
     * `frases_asignatura` **no tiene claves ajenas**, así que con ids inventados
     * escribiría igual y el test no comprobaría nada del mundo real.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function profesorConAsignatura(): array
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $par = DB::selectOne('SELECT a.id AS asignatura_id, m.alumno_id
              FROM asignaturas a
              INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
              INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
             WHERE a.deleted_at IS NULL
             ORDER BY a.id, m.alumno_id LIMIT 1', [$suyo->id]);

        $this->assertNotNull($par, 'El seed no tiene una asignatura con matrículas en el año del usuario.');

        // Y sin frases previas, que es lo que hace comparables los `assertCount`.
        DB::table('frases_asignatura')
            ->where('alumno_id', $par->alumno_id)
            ->where('asignatura_id', $par->asignatura_id)
            ->where('periodo_id', $suyo->id)
            ->delete();

        return [$token, [
            'asignatura' => (int) $par->asignatura_id,
            'alumno' => (int) $par->alumno_id,
            'periodo' => (int) $suyo->id,
        ]];
    }
}
