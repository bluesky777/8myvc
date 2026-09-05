<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las nueve rutas de `plantilla-notas` — la **Entrega 1** de
 * [28](../../docs/migracion/28-competencias-e-indicadores.md) §5.1.
 *
 * ## Qué existe esto para cazar, y ninguno de los cuatro da error solo
 *
 * **1. Que cualquier docente configure la plantilla del colegio.** El guard de la
 * ruta es `auth.personal`, que cierra la puerta a alumnos y acudientes **y a nadie
 * más**: un profesor pasa. El criterio que lo para es
 * `Autoriza::puedeEditarPlantillaNotas`, y va DENTRO del método. Sin el caso del
 * docente llano, quitarlo del controlador no pondría **nada** en rojo — es
 * exactamente el control que salvó a `putTonoDocente`.
 *
 * **2. Una unidad borrada con sus subunidades vivas.** La clave foránea de
 * `subunidades_por_defecto` es `ON DELETE CASCADE`, y eso **sólo actúa en un
 * borrado físico**. Aquí el borrado es lógico, así que sin el `UPDATE` de la mano
 * las subunidades quedarían vivas colgando de una unidad de la papelera:
 * invisibles en la pantalla y **cazadas por el sembrador**, que las busca por
 * `unidad_defec_id` y no vuelve a preguntar por el padre. El síntoma sería una
 * rejilla con casillas que el colegio cree haber borrado.
 *
 * **3. Un texto cortado en silencio.** `definicion` es `varchar(255)` y MySQL aquí
 * **no está en modo estricto**: un texto más largo entra recortado y devuelve 200.
 * Es lo que la §1.ter acaba de medir en `frases_asignatura` —626 frases cortadas a
 * mitad de palabra, ya impresas en boletines— y una fila de plantilla **se copia a
 * todas las asignaturas del colegio**, así que el corte se multiplica.
 *
 * **4. Un `sembrar` que se lleve por delante notas ya puestas, o el reparto de un
 * estudiante con boletín independiente.** Las dos son escrituras masivas y las dos
 * son mudas: el 200 no falta nunca. Por eso lo que se mira aquí es **la tabla**, no
 * la respuesta.
 */
class PlantillaNotasTest extends CasoDeContrato
{
    /**
     * Las nueve, con el cuerpo mínimo para llegar al criterio de autorización.
     *
     * Van en un proveedor y no en nueve tests porque el control que las hace valer
     * es «**quitar el criterio del controlador tiene que ponerlas rojas todas**»: si
     * sólo se cubrieran las cuatro escrituras obvias, un `getIndex` sin criterio
     * dejaría la plantilla del colegio a la vista de cualquier docente y nadie se
     * enteraría.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function lasNueveRutas(): array
    {
        return [
            'leer la plantilla' => ['getJson', 'plantilla-notas', []],
            'crear una unidad' => ['postJson', 'plantilla-notas/unidad', ['definicion' => 'X', 'porcentaje' => 100]],
            'cambiar una unidad' => ['putJson', 'plantilla-notas/unidad/1', ['definicion' => 'X']],
            'borrar una unidad' => ['deleteJson', 'plantilla-notas/unidad/1', []],
            'crear una subunidad' => ['postJson', 'plantilla-notas/subunidad', ['unidad_id' => 1, 'definicion' => 'X']],
            'cambiar una subunidad' => ['putJson', 'plantilla-notas/subunidad/1', ['definicion' => 'X']],
            'borrar una subunidad' => ['deleteJson', 'plantilla-notas/subunidad/1', []],
            'reordenar' => ['putJson', 'plantilla-notas/orden', ['unidades' => [1]]],
            'sembrar' => ['putJson', 'plantilla-notas/sembrar', []],
        ];
    }

    #[Test]
    #[DataProvider('lasNueveRutas')]
    public function test_un_docente_llano_no_toca_la_plantilla_del_colegio(string $verbo, string $ruta, array $cuerpo): void
    {
        $antes = $this->censoDeLaPlantilla();

        $r = $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDelPersonalLlano());

        $r->assertStatus(403);
        $this->assertSame($antes, $this->censoDeLaPlantilla(),
            'Contestó 403 y escribió igual: el criterio frena la respuesta pero no la escritura.');
    }

    /**
     * **El caso que demuestra que el permiso se lee de verdad.**
     *
     * Con `is_superuser` puesto, las nueve pasarían aunque
     * `puedeEditarPlantillaNotas` mirase cualquier otra cosa —o nada—. Éste tiene
     * el permiso **y no es superusuario**, que es la única forma de que el verde
     * signifique algo. Y va por rol, que es como el permiso llega de verdad al
     * contexto: `ContextoDeUsuario` junta los permisos de todos los roles en
     * `perms`, y un atajo aquí comprobaría un camino que en producción no existe.
     */
    #[Test]
    public function test_con_el_permiso_y_sin_superusuario_si_entra(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto de este test NO puede ser superusuario: con la columna puesta, el verde '.
            'no diría nada sobre el permiso.');

        /*
         * **El 403 de este mismo usuario ya está cubierto** por el proveedor de las
         * nueve rutas —`tokenDelPersonalLlano()` es el token de éste—, así que aquí
         * no se vuelve a pedir sin permiso. Y no es sólo por no repetir: pedir
         * primero sin permiso y después con él **dentro del mismo test** devuelve
         * 403 las dos veces, porque el contexto del usuario se resuelve una vez por
         * proceso y la segunda petición reutiliza el `perms` vacío de la primera.
         * Eso es del banco de pruebas, no del código: en producción son dos
         * peticiones distintas. Un test escrito así se leería como «el permiso no
         * funciona» y lo que estaría midiendo es la memoria estática.
         */
        $this->darPermisoDePlantilla((int) $usuario->id);

        $conPermiso = $this->getJson('/api/plantilla-notas', [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ]);
        $conPermiso->assertStatus(200);
    }

    #[Test]
    public function test_la_plantilla_viaja_con_sus_subunidades_y_sus_repartos(): void
    {
        $yearId = $this->anioDelToken();
        $unidad = $this->unidadDePlantilla($yearId, ['definicion' => 'Cognitivo', 'porcentaje' => 60]);
        $this->subunidadDePlantilla($unidad, ['definicion' => 'Taller 1', 'porcentaje' => 100]);
        $this->unidadDePlantilla($yearId, ['definicion' => 'Actitudinal', 'porcentaje' => 40]);

        $r = $this->pedir('getJson', 'plantilla-notas');

        $r->assertStatus(200);
        $this->assertSame($yearId, $r->json('year_id'));
        $this->assertCount(2, $r->json('unidades'));
        $this->assertSame('Taller 1', $r->json('unidades.0.subunidades.0.definicion'));

        /*
         * **`repartos` es la mitad que hace útil esta pantalla, y no es la suma de
         * la tabla.** Desde que una fila puede ir dirigida a un nivel o a una
         * materia, lo que se aplica junto es el grupo que comparte destino. Con dos
         * plantillas correctas —una general y una de preescolar— la suma de la
         * tabla daría 200 y no querría decir nada.
         */
        $repartos = $r->json('repartos');
        $this->assertCount(1, $repartos, 'Las dos van sin alcance: es un solo reparto.');
        $this->assertSame(100, $repartos[0]['suma_porcentajes']);
        $this->assertSame(2, $repartos[0]['unidades']);
    }

    #[Test]
    public function test_una_unidad_que_no_suma_100_se_guarda_igual_y_devuelve_la_suma(): void
    {
        /*
         * **No se bloquea, y no es laxitud.** Para llegar a 100 hay que pasar por
         * estados intermedios: una pantalla que no deja guardar el 40 % porque
         * todavía no existe el 60 % no se puede usar. El aviso va donde duele —en
         * `sembrar`—, no donde estorba.
         */
        $r = $this->pedir('postJson', 'plantilla-notas/unidad', ['definicion' => 'Sola', 'porcentaje' => 40]);

        $r->assertStatus(200);
        $this->assertSame(40, $r->json('reparto.suma_porcentajes'),
            'Se guarda sin bloquear, pero la respuesta tiene que decir la suma para que el front la pinte en rojo.');
        $this->assertSame(40, (int) DB::table('unidades_por_defecto')
            ->where('id', $r->json('id'))->value('porcentaje'));
    }

    #[Test]
    public function test_borrar_una_unidad_se_lleva_sus_subunidades(): void
    {
        $unidad = $this->unidadDePlantilla($this->anioDelToken(), ['definicion' => 'Con hijas']);
        $subunidad = $this->subunidadDePlantilla($unidad, ['definicion' => 'Hija']);

        $r = $this->pedir('deleteJson', "plantilla-notas/unidad/{$unidad}");

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('subunidades_borradas'));

        /*
         * **La tabla, no el 200.** La FK es `ON DELETE CASCADE` y eso sólo actúa en
         * un borrado físico: sin el `UPDATE` a mano, esta subunidad seguiría viva
         * colgando de una unidad de la papelera — invisible en la pantalla y
         * **cazada por el sembrador**, que la busca por `unidad_defec_id`.
         */
        $this->assertNotNull(DB::table('subunidades_por_defecto')->where('id', $subunidad)->value('deleted_at'),
            'La unidad se fue a la papelera y su subunidad se quedó viva: el sembrador la seguiría copiando.');
    }

    #[Test]
    public function test_una_definicion_de_mas_de_255_es_422_y_no_se_guarda_cortada(): void
    {
        $largo = str_repeat('a', 300);

        $r = $this->pedir('postJson', 'plantilla-notas/unidad', ['definicion' => $largo, 'porcentaje' => 100]);

        $r->assertStatus(422);
        $this->assertSame(0, DB::table('unidades_por_defecto')
            ->where('year_id', $this->anioDelToken())->whereNull('deleted_at')->count(),
            'MySQL no está en modo estricto: sin el 422 esto entra cortado a 255, devuelve 200 y '.
            'el colegio se entera cuando lo ve impreso.');
    }

    #[Test]
    public function test_una_unidad_de_otro_anio_no_existe_para_este_token(): void
    {
        $otro = DB::selectOne('SELECT id FROM years WHERE id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$this->anioDelToken()]);

        $this->assertNotNull($otro, 'El seed no tiene un segundo año y sin él este caso no se puede montar.');

        $ajena = $this->unidadDePlantilla((int) $otro->id, ['definicion' => 'De otro año']);

        $this->pedir('putJson', "plantilla-notas/unidad/{$ajena}", ['definicion' => 'Tocada'])
            ->assertStatus(404);

        $this->assertSame('De otro año', DB::table('unidades_por_defecto')->where('id', $ajena)->value('definicion'),
            'No se toca nada de un año que no es el del token: es la regla de §4 por su otra cara.');
    }

    #[Test]
    public function test_reordenar_escribe_el_orden_y_rechaza_una_lista_parcial(): void
    {
        $yearId = $this->anioDelToken();
        $primera = $this->unidadDePlantilla($yearId, ['definicion' => 'A', 'orden' => 0]);
        $segunda = $this->unidadDePlantilla($yearId, ['definicion' => 'B', 'orden' => 1]);

        $r = $this->pedir('putJson', 'plantilla-notas/orden', ['unidades' => [$segunda, $primera]]);

        $r->assertStatus(200);
        $this->assertSame(0, (int) DB::table('unidades_por_defecto')->where('id', $segunda)->value('orden'));
        $this->assertSame(1, (int) DB::table('unidades_por_defecto')->where('id', $primera)->value('orden'));

        // Una lista a medias deja huecos y repetidos, que es el estado del que se
        // viene: `Unidad::arreglarOrden` existe para tapar exactamente eso.
        $this->pedir('putJson', 'plantilla-notas/orden', ['unidades' => [$primera]])
            ->assertStatus(422);
    }

    #[Test]
    public function test_sembrar_dice_la_poblacion_y_no_toca_lo_que_ya_esta_montado(): void
    {
        $yearId = $this->anioDelToken();
        $this->abrirLosPeriodos($yearId);
        $unidad = $this->unidadDePlantilla($yearId, ['definicion' => 'Del colegio', 'porcentaje' => 100]);
        $this->subunidadDePlantilla($unidad, ['definicion' => 'Única', 'porcentaje' => 100]);

        $primera = $this->pedir('putJson', 'plantilla-notas/sembrar');
        $primera->assertStatus(200);

        /*
         * **La respuesta dice la población, no `OK`.** Un «0 sembradas» tiene que
         * poder distinguirse de «no revisé nada», que es la regla de `tools/`
         * aplicada a un endpoint.
         */
        $this->assertGreaterThan(0, $primera->json('revisadas'));
        $this->assertGreaterThan(0, $primera->json('sembradas'));

        // Y la segunda vez no siembra nada: ya están montadas. Es el caso que
        // distingue «no hizo falta» de «no revisé».
        $segunda = $this->pedir('putJson', 'plantilla-notas/sembrar');
        $this->assertSame(0, $segunda->json('sembradas'));
        /*
         * **Lo que sembró la primera vez MÁS lo que ya se saltó** es exactamente lo
         * que la segunda salta por estructura. La resta importa: el seed trae
         * asignaturas que ya tienen rejilla, así que comparar sólo contra
         * `sembradas` da un número que no cuadra y **la lectura fácil sería “sembró
         * de menos”** cuando lo que pasa es que había trabajo hecho de antes.
         */
        $this->assertSame(
            $primera->json('sembradas') + $primera->json('saltadas_por_estructura'),
            $segunda->json('saltadas_por_estructura'),
            'Lo sembrado más lo ya montado tiene que ser lo que la segunda pasada salta entero.'
        );
    }

    #[Test]
    public function test_sembrar_no_escribe_nada_en_un_periodo_cerrado(): void
    {
        $yearId = $this->anioDelToken();
        DB::table('periodos')->where('year_id', $yearId)->update(['profes_pueden_editar_notas' => 0]);

        $unidad = $this->unidadDePlantilla($yearId, ['definicion' => 'Del colegio', 'porcentaje' => 100]);
        $this->subunidadDePlantilla($unidad, ['definicion' => 'Única', 'porcentaje' => 100]);

        $antes = $this->censoDeUnidades($yearId);

        $r = $this->pedir('putJson', 'plantilla-notas/sembrar');

        $r->assertStatus(200);
        $this->assertSame(0, $r->json('sembradas'));
        $this->assertGreaterThan(0, $r->json('saltadas_por_periodo_cerrado'));
        $this->assertSame($antes, $this->censoDeUnidades($yearId),
            'Regla 2 de §4: nada se siembra en un periodo cerrado, en ningún camino.');
    }

    #[Test]
    public function test_con_reemplazar_una_asignatura_con_una_sola_nota_no_se_toca(): void
    {
        $yearId = $this->anioDelToken();
        $this->abrirLosPeriodos($yearId);
        $unidad = $this->unidadDePlantilla($yearId, ['definicion' => 'Del colegio', 'porcentaje' => 100]);
        $this->subunidadDePlantilla($unidad, ['definicion' => 'Única', 'porcentaje' => 100]);

        $this->pedir('putJson', 'plantilla-notas/sembrar')->assertStatus(200);

        // Una nota, una sola, en la primera subunidad sembrada.
        $subunidad = DB::selectOne(
            'SELECT s.id, u.asignatura_id, u.periodo_id
               FROM subunidades s
               JOIN unidades u ON u.id = s.unidad_id
               JOIN asignaturas a ON a.id = u.asignatura_id
               JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ?
              WHERE u.por_defecto = 1 AND s.deleted_at IS NULL AND u.deleted_at IS NULL
              ORDER BY s.id LIMIT 1',
            [$yearId]
        );
        $this->assertNotNull($subunidad, 'La siembra de arriba no dejó ninguna subunidad que calificar.');

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        DB::table('notas')->insert([
            'nota' => 80, 'subunidad_id' => $subunidad->id, 'alumno_id' => $alumno->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $unidadesAntes = DB::table('unidades')
            ->where('asignatura_id', $subunidad->asignatura_id)
            ->where('periodo_id', $subunidad->periodo_id)
            ->whereNull('deleted_at')->pluck('id')->all();

        $r = $this->pedir('putJson', 'plantilla-notas/sembrar', ['reemplazar' => true]);

        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('saltadas_por_notas'));
        $this->assertSame($unidadesAntes, DB::table('unidades')
            ->where('asignatura_id', $subunidad->asignatura_id)
            ->where('periodo_id', $subunidad->periodo_id)
            ->whereNull('deleted_at')->pluck('id')->all(),
            'Una asignatura con UNA sola nota no se toca jamás, ni con `reemplazar`.');
    }

    #[Test]
    public function test_sembrar_no_toca_las_unidades_de_un_boletin_independiente(): void
    {
        $yearId = $this->anioDelToken();
        $this->abrirLosPeriodos($yearId);
        $unidad = $this->unidadDePlantilla($yearId, ['definicion' => 'Del colegio', 'porcentaje' => 100]);
        $this->subunidadDePlantilla($unidad, ['definicion' => 'Única', 'porcentaje' => 100]);

        /*
         * **Una asignatura recién creada y no una del seed**, y esto lo decidió un
         * control que NO se puso rojo. Con la primera asignatura del seed, que ya
         * tiene rejilla y notas, `sembrar` la salta por `saltadas_por_notas` **antes
         * de llegar a la regla 5**: quitarle el `alumno_id IS NULL` al controlador
         * dejaba este test en verde. Medía el nombre del caso, no el caso.
         */
        $asignatura = (object) [
            'id' => $this->asignaturaLimpia($yearId),
            'periodo_id' => (int) DB::table('periodos')->where('year_id', $yearId)
                ->whereNull('deleted_at')->orderBy('numero')->value('id'),
        ];
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        /*
         * Una unidad **con dueño** en esa asignatura+periodo. Es la regla 5 de §4, y
         * la que ya costó una vez «51 estudiantes y una asignatura al 110 %»: si el
         * sembrador contara esta fila, el curso entero se quedaría **sin sembrar**;
         * si la reemplazara, se llevaría por delante el reparto de ese estudiante.
         */
        $conDueno = DB::table('unidades')->insertGetId([
            'definicion' => 'Suya', 'porcentaje' => 100, 'periodo_id' => $asignatura->periodo_id,
            'asignatura_id' => $asignatura->id, 'alumno_id' => $alumno->id, 'orden' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->pedir('putJson', 'plantilla-notas/sembrar', ['reemplazar' => true]);

        $r->assertStatus(200);
        $this->assertGreaterThan(0, $r->json('independientes_respetadas'),
            'El contador tiene que subir cuando había filas con dueño: si valiera cero siempre, '.
            'no diría si la regla llegó a correr.');

        $suya = DB::table('unidades')->where('id', $conDueno)->first();
        $this->assertNull($suya->deleted_at, 'Se llevó por delante el reparto de un boletín independiente.');
        $this->assertSame(100, (int) $suya->porcentaje);

        // Y el curso SÍ recibió el suyo, que es la otra mitad: contar las filas con
        // dueño dejaría la asignatura sin sembrar y con la rejilla vacía.
        $this->assertGreaterThan(0, DB::table('unidades')
            ->where('asignatura_id', $asignatura->id)
            ->where('periodo_id', $asignatura->periodo_id)
            ->whereNull('alumno_id')->whereNull('deleted_at')->count(),
            'El curso se quedó sin rejilla porque el independiente le hizo sombra.');
    }

    #[Test]
    public function test_sembrar_un_reparto_que_no_suma_100_pide_que_lo_acepten(): void
    {
        $yearId = $this->anioDelToken();
        $this->abrirLosPeriodos($yearId);
        $unidad = $this->unidadDePlantilla($yearId, ['definicion' => 'A medias', 'porcentaje' => 70]);
        $this->subunidadDePlantilla($unidad, ['definicion' => 'Única', 'porcentaje' => 100]);

        $antes = $this->censoDeUnidades($yearId);

        $r = $this->pedir('putJson', 'plantilla-notas/sembrar');

        $r->assertStatus(422);
        $this->assertSame(70, $r->json('repartos_desviados.0.suma_porcentajes'),
            'El 422 tiene que traer la suma: el aviso donde duele sirve si dice cuánto falta.');
        $this->assertSame($antes, $this->censoDeUnidades($yearId),
            'El 422 frenó la respuesta y escribió igual.');

        // Y con el aviso aceptado, siembra. Es el patrón de `acepto_perder` del 23.
        $this->pedir('putJson', 'plantilla-notas/sembrar', ['acepto_desviacion' => true])
            ->assertStatus(200)
            ->assertJsonPath('sembradas', fn ($n) => $n > 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ayudantes
    // ─────────────────────────────────────────────────────────────────────────

    /** Con el token de un superusuario, que es quien puede el primer día. */
    private function pedir(string $verbo, string $ruta, array $cuerpo = [])
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de estos casos tiene que ser superusuario: sin él, el 403 del criterio '.
            'se leería como un fallo de la validación.');

        return $this->llamar($verbo, $ruta, $cuerpo, $this->tokenDe($usuario->username));
    }

    /**
     * **`getJson` no acepta cuerpo y los otros tres sí**, y su segundo parámetro
     * son las cabeceras: pasarle el cuerpo ahí no da un fallo de ruta, da un
     * `TypeError` dentro de `json_encode` que no se parece en nada a la causa.
     * Va en un ayudante para que el proveedor de las nueve rutas pueda tratarlas
     * a todas igual.
     */
    private function llamar(string $verbo, string $ruta, array $cuerpo, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        if ($verbo === 'getJson') {
            return $this->getJson("/api/{$ruta}", $cabeceras);
        }

        return $this->{$verbo}("/api/{$ruta}", $cuerpo, $cabeceras);
    }

    /** El año que el token trae puesto, preguntándoselo a la propia API. */
    private function anioDelToken(): int
    {
        return (int) $this->pedir('getJson', 'plantilla-notas')->json('year_id');
    }

    private function unidadDePlantilla(int $yearId, array $campos = []): int
    {
        return (int) DB::table('unidades_por_defecto')->insertGetId($campos + [
            'definicion' => 'Unidad de prueba',
            'porcentaje' => 100,
            'year_id' => $yearId,
            'obligatoria' => 0,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function subunidadDePlantilla(int $unidadId, array $campos = []): int
    {
        return (int) DB::table('subunidades_por_defecto')->insertGetId($campos + [
            'definicion' => 'Subunidad de prueba',
            'porcentaje' => 100,
            'unidad_defec_id' => $unidadId,
            'obligatoria' => 0,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un grupo nuevo con una asignatura dentro, **sin ninguna unidad y sin ninguna
     * nota**. Es lo único sobre lo que `sembrar` llega a hacer algo, así que es lo
     * único sobre lo que sus reglas se pueden medir.
     */
    private function asignaturaLimpia(int $yearId): int
    {
        $molde = DB::selectOne('SELECT grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$yearId]);

        $grupoId = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo limpio de pruebas', 'abrev' => 'LIM', 'year_id' => $yearId,
            'grado_id' => $molde->grado_id, 'orden' => 98, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $materia = DB::selectOne('SELECT id FROM materias WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        return (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => $materia->id, 'grupo_id' => $grupoId, 'profesor_id' => $profesor->id,
            'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function abrirLosPeriodos(int $yearId): void
    {
        DB::table('periodos')->where('year_id', $yearId)->update(['profes_pueden_editar_notas' => 1]);
    }

    /**
     * Cuántas filas vivas hay en las dos tablas de plantilla. Es lo que se compara
     * antes y después de un 403 o de un 422: la pregunta no es qué contestó, es si
     * escribió.
     */
    private function censoDeLaPlantilla(): string
    {
        return DB::table('unidades_por_defecto')->whereNull('deleted_at')->count()
            .'/'.DB::table('subunidades_por_defecto')->whereNull('deleted_at')->count();
    }

    private function censoDeUnidades(int $yearId): int
    {
        return (int) DB::table('unidades')
            ->join('asignaturas', 'asignaturas.id', '=', 'unidades.asignatura_id')
            ->join('grupos', 'grupos.id', '=', 'asignaturas.grupo_id')
            ->where('grupos.year_id', $yearId)
            ->whereNull('unidades.deleted_at')
            ->count();
    }

    /**
     * El permiso por rol, que es como llega de verdad al contexto. Calcado de
     * `darPermisoDeAuditoria`, y por el mismo motivo: `test-seed.sql` hace
     * `TRUNCATE` de `permissions`, así que lo que siembre la migración **no
     * sobrevive a construir la base** y un test que se apoyara en ello estaría
     * comprobando el seed y no el código.
     */
    private function darPermisoDePlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'JefeDeAreaDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'JefeDeAreaDePrueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        if (! DB::table('permission_role')->where('permission_id', $permiso)->where('role_id', $rol)->exists()) {
            DB::table('permission_role')->insert(['permission_id' => $permiso, 'role_id' => $rol]);
        }

        if (! DB::table('role_user')->where('user_id', $userId)->where('role_id', $rol)->exists()) {
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rol]);
        }
    }
}
