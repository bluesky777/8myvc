<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Un grupo de preescolar recorre el camino entero de calificar por competencias.**
 *
 * Escrito el 15 sep 2026 para contestar una pregunta que sólo estaba contestada por
 * composición: la Entrega 7 del doc 28 —preescolar deja de teclearse alumno por
 * alumno— se daba por cubierta porque sus cuatro piezas existen (la plantilla con
 * alcance, el catálogo por materia y grado, el marcado en `frases_asignatura` y
 * `grupos.caritas`). **Nadie la había recorrido.**
 *
 * ## Por qué hace falta CONSTRUIR el caso, que es el primer hallazgo
 *
 * **El seed no tiene preescolar.** Medido ese día: sus únicos niveles educativos son
 * `Educación Básica Académica3` con Tercero y Cuarto, **cero grupos con `caritas`**.
 * Así que ningún test que espere el caso del seed puede cubrir preescolar — y la
 * familia `BolfinalesPreescolarController` / `FrasesPreescolarTest` **no lo cubre
 * tampoco**: ejercita sus rutas, no un grupo de preescolar de verdad.
 *
 * Es el patrón de `PlanillaSinIndependientesTest` y `BolIndependienteEnLosInformesTest`:
 * cuando el seed no puede traer el caso, **el caso se construye**, porque un hueco del
 * seed se describe como hueco y a partir de ahí pasa siempre.
 *
 * ## Y lo que este fichero afirma es que preescolar NO es un caso especial
 *
 * `getRejilla` recibe `asignatura_id` y `periodo_id` y nada más: no mira el nivel
 * educativo, ni el grado, ni `caritas`. La afirmación de esta clase es que eso se
 * cumple **de punta a punta y no sólo en la firma del método** — sembrar desde el
 * plan de área, leer la rejilla, marcar y verlo impreso en el boletín por
 * competencias.
 *
 * Si algún día preescolar necesita una rama propia en el backend, es aquí donde se
 * pondrá rojo primero.
 */
class PreescolarCalificaPorCompetenciasTest extends CasoDeContrato
{
    private string $token = '';

    /**
     * Un grupo de **preescolar** completo: nivel educativo, grado, grupo con
     * `caritas`, una asignatura y tres alumnos matriculados.
     *
     * Todo dentro de la transacción del test, así que no ensucia el seed.
     */
    private function unPreescolar(): object
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        // **El permiso de la plantilla, y hace falta ANTES del token**: sembrar el plan
        // de área exige `can_edit_plantilla_notas` dentro del método —es el coordinador
        // quien siembra, no el docente llano—, y sin él `desempenos/sembrar` contesta
        // 403. Lo comprobó este mismo caso al escribirse.
        //
        // El sujeto sigue siendo un docente **llano**: el permiso se le da por rol, que
        // es como lo tendría en un colegio. Con un superusuario el verde no diría nada.
        $this->darPermisoDePlantilla((int) $usuario->id);

        $this->token = $this->tokenDe($usuario->username);

        // Después del login y no antes: `Services\Login` reescribe `users.periodo_id`
        // al periodo `actual` en cada inicio de sesión.
        $contexto = DB::selectOne(
            'SELECT p.year_id, p.id AS periodo_id FROM users u
               INNER JOIN periodos p ON p.id = u.periodo_id
              WHERE u.id = ?',
            [$usuario->id]
        );

        $yearId = (int) $contexto->year_id;

        $nivelId = (int) DB::table('niveles_educativos')->insertGetId([
            'nombre' => 'Educación Preescolar', 'abrev' => 'PRE', 'orden' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $gradoId = (int) DB::table('grados')->insertGetId([
            'nombre' => 'Transición', 'abrev' => 'TRA', 'orden' => 0,
            'nivel_educativo_id' => $nivelId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // `caritas = 1` — el interruptor que hoy distingue a preescolar en los
        // boletines. Se pone a propósito para que, si alguien lo convierte en una rama
        // del backend, este caso lo note.
        $grupoId = (int) DB::table('grupos')->insertGetId([
            'nombre' => 'Transición A', 'abrev' => 'TRA-A', 'year_id' => $yearId,
            'grado_id' => $gradoId, 'orden' => 0, 'caritas' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $materiaId = (int) DB::table('materias')->whereNull('deleted_at')->orderBy('id')->value('id');
        $profesorId = (int) DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');

        $asignaturaId = (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => $materiaId, 'grupo_id' => $grupoId, 'profesor_id' => $profesorId,
            'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('periodos')->where('year_id', $yearId)->update(['profes_pueden_editar_notas' => 1]);

        // **El periodo del USUARIO, no el primero del año.** El primero del año es el
        // que sale solo al escribir esto, y con él los tres primeros pasos pasan y el
        // cuarto falla: el boletín por competencias imprime `$this->user->periodo_id` y
        // **la ruta no acepta ningún `periodo_a_calcular`** — está atado al periodo
        // activo por construcción, que es el límite medido el 14 sep 2026 (35, «lo que
        // sigue abierto»). Marcar en un periodo y leerlo en otro no enseña nada, y el
        // fallo se lee como si la rejilla no escribiera.
        $periodoId = (int) $contexto->periodo_id;

        $alumnos = array_map(
            fn ($fila) => (int) $fila->id,
            DB::select('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 3')
        );

        $this->assertCount(3, $alumnos, 'El seed no tiene tres alumnos.');

        foreach ($alumnos as $alumnoId) {
            DB::table('matriculas')->insert([
                'alumno_id' => $alumnoId, 'grupo_id' => $grupoId, 'estado' => 'MATR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (object) [
            'year_id' => $yearId,
            'nivel_id' => $nivelId,
            'grado_id' => $gradoId,
            'grupo_id' => $grupoId,
            'materia_id' => $materiaId,
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoId,
            'alumnos' => $alumnos,
        ];
    }

    /** El permiso que exige sembrar, por rol y no por la columna de superusuario. */
    private function darPermisoDePlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'CoordinadoraDePreescolarDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'CoordinadoraDePreescolarDePrueba',
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

    /** Un logro en el plan de área, por materia + grado + periodo. */
    private function enElPlan(object $caso, string $definicion): int
    {
        return (int) DB::table('desempenos_por_defecto')->insertGetId([
            'year_id' => $caso->year_id,
            'materia_id' => $caso->materia_id,
            'grado_id' => $caso->grado_id,
            'periodo_id' => $caso->periodo_id,
            'definicion' => $definicion,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pedir(string $verbo, string $ruta, array $cuerpo = [])
    {
        $cabeceras = ['Authorization' => 'Bearer '.$this->token];

        if ($verbo === 'getJson') {
            return $this->getJson("/api/{$ruta}", $cabeceras);
        }

        return $this->{$verbo}("/api/{$ruta}", $cuerpo, $cabeceras);
    }

    /**
     * **El seed no tiene preescolar, y eso se fija en vez de descubrirse otra vez.**
     *
     * No es una curiosidad: es la razón por la que los demás casos de este fichero
     * construyen el grupo. El día que el seed traiga preescolar, este caso se pone
     * rojo y quien lo lea sabrá que puede dejar de construirlo.
     */
    #[Test]
    public function el_seed_no_trae_preescolar_y_por_eso_el_caso_se_construye(): void
    {
        $conCaritas = DB::table('grupos')->whereNull('deleted_at')->where('caritas', 1)->count();

        $this->assertSame(0, $conCaritas,
            'El seed ya trae grupos con `caritas`. Este fichero construye el suyo porque no los '
            .'había: si ahora los hay, revise si conviene medir sobre los del seed.');
    }

    /**
     * **El camino entero**: sembrar el plan de área → leer la rejilla → marcar →
     * verlo en el boletín por competencias.
     *
     * Los cuatro pasos en un solo caso **a propósito**. Partido en cuatro, cada
     * mitad pasaría con la siguiente rota y haría falta repetir la construcción del
     * grupo cuatro veces; y lo que se quiere afirmar no es que cada paso funcione,
     * sino que **encadenan**.
     */
    #[Test]
    public function un_grupo_de_preescolar_recorre_el_camino_entero(): void
    {
        $caso = $this->unPreescolar();

        // ── 1. El plan de área del colegio, y se siembra en la asignatura ──────────
        $this->enElPlan($caso, 'Reconoce las vocales en su nombre');

        $sembrado = $this->pedir('putJson', 'desempenos/sembrar');
        $sembrado->assertStatus(200);

        $this->assertGreaterThan(0, $sembrado->json('sembradas'),
            'La siembra no llegó a la asignatura de preescolar. `desempenos_por_defecto` cruza '
            .'materia + grado + periodo, así que si esto falla el grado de Transición no está '
            .'entrando en ese cruce.');

        $desempenoId = (int) DB::table('desempenos')
            ->where('asignatura_id', $caso->asignatura_id)->whereNull('deleted_at')
            ->orderBy('id')->value('id');

        $this->assertGreaterThan(0, $desempenoId, 'No quedó ningún desempeño en la asignatura.');

        // ── 2. La rejilla, que en preescolar viene SIN premarcar ───────────────────
        $rejilla = $this->pedir('getJson',
            "desempenos/rejilla?asignatura_id={$caso->asignatura_id}&periodo_id={$caso->periodo_id}");
        $rejilla->assertStatus(200);

        $this->assertSame(3, $rejilla->json('poblacion.alumnos'),
            'La rejilla no ve a los tres alumnos del grupo de preescolar.');

        $this->assertGreaterThan(0, $rejilla->json('poblacion.desempenos'),
            'La rejilla no ve el desempeño sembrado.');

        // **Ésta es la afirmación de preescolar, y no es un fallo**: la rejilla
        // premarca desde la definitiva numérica, y en preescolar no hay notas. Los
        // tres alumnos salen `sin_definitiva` y el docente marca a mano, que es
        // literalmente lo que pedía la Entrega 7(c): «la valoración se MARCA, no se
        // teclea». Si algún día se premarcara aquí, sería de una nota que preescolar
        // no pone.
        $this->assertSame(3, $rejilla->json('poblacion.alumnos_sin_definitiva'),
            'Algún alumno de preescolar llegó con definitiva numérica: o el seed cambió, o la '
            .'rejilla está premarcando desde una nota que preescolar no pone.');

        // ── 3. El docente marca ────────────────────────────────────────────────────
        $escalaId = (int) DB::table('escalas_de_valoracion')
            ->where('year_id', $caso->year_id)->whereNull('deleted_at')
            ->orderBy('orden')->value('id');

        $this->assertGreaterThan(0, $escalaId, 'El año no tiene escala de valoración.');

        $marcado = $this->pedir('putJson', 'desempenos/rejilla', [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'celdas' => [[
                'alumno_id' => $caso->alumnos[0],
                'desempeno_id' => $desempenoId,
                'escala_id' => $escalaId,
            ]],
        ]);
        $marcado->assertStatus(200);

        $celda = DB::selectOne(
            'SELECT frase, nivel, escala_id, desempeno_id FROM frases_asignatura
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$caso->alumnos[0], $caso->asignatura_id, $caso->periodo_id]
        );

        $this->assertNotNull($celda, 'Marcar no escribió la celda en `frases_asignatura`.');
        $this->assertSame('Reconoce las vocales en su nombre', $celda->frase,
            'La celda no congeló el texto del desempeño.');
        $this->assertNotNull($celda->nivel, 'La celda no congeló el nombre de la banda.');

        // ── 4. Y sale impreso en el boletín por competencias ───────────────────────
        $boletin = $this->pedir('putJson',
            "boletines-competencias/detailed-notas/{$caso->grupo_id}",
            ['requested_alumnos' => [$caso->alumnos[0]]]);

        $boletin->assertStatus(200);

        $this->assertStringContainsString(
            'Reconoce las vocales en su nombre',
            json_encode($boletin->json(), JSON_UNESCAPED_UNICODE),
            'El desempeño marcado no llegó al boletín por competencias del alumno de preescolar. '
            .'El camino entero se corta en el último paso, que es el único que el colegio ve.'
        );
    }
}
