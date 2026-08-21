<?php

namespace Tests\Contrato;

use App\Http\Middleware\ExigirPersonaPropia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Quién puede hacer qué, una vez presentado un token válido.
 *
 * Tener token prueba que eres alguien, no que puedas ver lo que pides. Este
 * archivo cubre los cuatro guards de autorización que existían escritos en el
 * código y **no se ejecutaban ninguna**. No hizo falta deducirlo: se comprobó
 * golpeando los endpoints con un token de alumno del seed.
 *
 * Ver docs/migracion/06-autorizacion.md.
 */
class AutorizacionTest extends CasoDeContrato
{
    /** Los tres controladores de boletines son copias y sirven el mismo dato. */
    public static function familiasDeBoletines(): array
    {
        return [
            'boletines' => ['boletines'],
            'boletines2' => ['boletines2'],
            'boletines3' => ['boletines3'],
        ];
    }

    /** El alumno y su grupo, más un compañero suyo que no es él. */
    private function alumnoYCompanero(): array
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $mio = DB::select('SELECT a.id alumno_id, m.grupo_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id
                AND m.estado IN ("MATR","ASIS","PREM") AND m.deleted_at IS NULL
            WHERE a.user_id = ? AND a.deleted_at IS NULL LIMIT 1', [$usuario->id])[0];

        $otro = DB::select('SELECT a.id alumno_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.id <> ? AND a.deleted_at IS NULL LIMIT 1',
            [$mio->grupo_id, $mio->alumno_id])[0];

        return [$this->tokenDe($usuario->username), $mio, $otro];
    }

    private function pedir(string $token, string $familia, int $grupoId, ?array $alumno)
    {
        $cuerpo = $alumno === null ? [] : ['requested_alumnos' => [$alumno]];

        return $this->putJson("/api/{$familia}/detailed-notas/{$grupoId}", $cuerpo,
            ['Authorization' => 'Bearer '.$token]);
    }

    /**
     * El agujero que motiva todo esto.
     *
     * `abort()` lanza una HttpException, que es un Throwable, así que el
     * `catch (\Throwable $th)` del constructor de BoletinesController se la
     * tragaba entera. Respondía 200 con el boletín del compañero.
     */
    #[DataProvider('familiasDeBoletines')]
    public function test_un_alumno_no_puede_pedir_el_boletin_de_otro(string $familia): void
    {
        [$token, $mio, $otro] = $this->alumnoYCompanero();

        $this->pedir($token, $familia, $mio->grupo_id,
            ['alumno_id' => $otro->alumno_id, 'matricula_id' => $otro->matricula_id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes ver el de otros');
    }

    #[DataProvider('familiasDeBoletines')]
    public function test_un_alumno_si_puede_pedir_el_suyo(string $familia): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $r = $this->pedir($token, $familia, $mio->grupo_id,
            ['alumno_id' => $mio->alumno_id, 'grupo_id' => $mio->grupo_id]);

        $this->assertNotSame(403, $r->getStatusCode(),
            'El guard rechaza a un alumno pidiendo su propio boletín.');
    }

    /**
     * El alumno recibe su boletín, y solo el suyo.
     *
     * Va contra `boletines/detailed-notas`, que es la ruta del flujo real: el
     * estado `panel.boletin_acudiente` de `myvc_front` es el que usan alumno y
     * acudiente, y llama a esa. Las variantes 2 y 3 son maquetas para el personal.
     *
     * **El payload es el que manda el frontend**: `[{alumno_id, grupo_id}]`, sin
     * `matricula_id` (`NotasAlumnoCtrl.js`, `verMiBoletin()` y `verBoletin()`).
     * Importa que sea ese y no uno cómodo: con `matricula_id` el endpoint
     * respondía bien y sin él respondía 500 desde 2021, así que un test con el
     * payload inventado habría dado verde sobre una pantalla rota.
     */
    public function test_el_alumno_recibe_su_boletin_y_solo_el_suyo(): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $r = $this->pedir($token, 'boletines', $mio->grupo_id,
            ['alumno_id' => $mio->alumno_id, 'grupo_id' => $mio->grupo_id]);

        $r->assertStatus(200);

        // La respuesta es [grupo, year, alumnos, escalas].
        $alumnos = $r->json('2');

        $this->assertCount(1, $alumnos,
            'Pidió su boletín y le devolvieron '.count($alumnos).' alumnos.');

        $this->assertSame($mio->alumno_id, $alumnos[0]['alumno_id']);
    }

    /**
     * boletines3 sale aunque un área no tenga asignaturas en el grupo.
     *
     * `Area::agrupar_asignaturas_periodos()` dividía la suma de notas entre el
     * número de asignaturas del área sin comprobar que hubiera alguna. Un área
     * creada en el año pero sin asignaturas en ese grupo dejaba `$found` en 0 y
     * el informe respondía **500 "Division by zero"** — no esa área: el grupo
     * entero. Ahora esa área sale con la nota y el desempeño en blanco.
     *
     * El seed tiene un área así, que es como apareció.
     */
    public function test_boletines3_sale_con_un_area_sin_asignaturas(): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $r = $this->pedir($token, 'boletines3', $mio->grupo_id,
            ['alumno_id' => $mio->alumno_id, 'grupo_id' => $mio->grupo_id]);

        $r->assertStatus(200);

        $vacias = [];

        foreach ($r->json('2') as $alumno) {
            foreach ($alumno['areas'] ?? [] as $area) {
                if (($area['cant'] ?? null) === 0) {
                    $vacias[] = $area;
                }
            }
        }

        $this->assertNotEmpty($vacias,
            "El seed ya no tiene ningún área sin asignaturas, así que este test\n".
            'no comprueba nada. Regenérala o construye el caso a mano.');

        foreach ($vacias as $area) {
            $this->assertSame('', $area['per1_nota'], 'Un área sin asignaturas trajo nota.');
            $this->assertSame('', $area['desempenio_per1']);
        }
    }

    /**
     * Sin lista concreta se está pidiendo el grupo entero, que es lo que hace
     * `detailed-notas-year`. Un alumno no puede.
     */
    #[DataProvider('familiasDeBoletines')]
    public function test_un_alumno_no_puede_pedir_el_grupo_entero(string $familia): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $this->pedir($token, $familia, $mio->grupo_id, null)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Pedis más de lo que debes');

        $this->getJson("/api/{$familia}/detailed-notas-year/{$mio->grupo_id}",
            ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }

    /** Un profesor sigue viendo el grupo entero: el guard no le aplica. */
    public function test_un_profesor_sigue_viendo_el_grupo_entero(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        [, $mio] = $this->alumnoYCompanero();

        $r = $this->pedir($this->tokenDe($profesor->username), 'boletines', $mio->grupo_id, null);

        $this->assertNotSame(403, $r->getStatusCode(),
            'El guard de boletín propio está rechazando al personal del colegio.');
    }

    private function acudienteConAcudido(): array
    {
        $fila = DB::select('SELECT u.username, ac.id acudiente_id, p.alumno_id, m.grupo_id, m.id matricula_id
            FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = u.periodo_id
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotEmpty($fila, 'El seed no tiene ningún acudiente con parentesco y matrícula.');

        return [$this->tokenDe($fila[0]->username), $fila[0]];
    }

    public function test_un_acudiente_no_puede_pedir_el_de_quien_no_es_su_acudido(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        $ajeno = DB::select('SELECT a.id alumno_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE a.id <> ? AND a.deleted_at IS NULL LIMIT 1', [$suyo->alumno_id])[0];

        $this->pedir($token, 'boletines', $suyo->grupo_id,
            ['alumno_id' => $ajeno->alumno_id, 'matricula_id' => $ajeno->matricula_id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No es acudiente de este alumno. Lo siento.');
    }

    public function test_un_acudiente_si_puede_pedir_el_de_su_acudido(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        DB::update('UPDATE alumnos SET pazysalvo = 1 WHERE id = ?', [$suyo->alumno_id]);

        $r = $this->pedir($token, 'boletines', $suyo->grupo_id,
            ['alumno_id' => $suyo->alumno_id, 'matricula_id' => $suyo->matricula_id]);

        $this->assertNotSame(403, $r->getStatusCode(),
            'El guard rechaza a un acudiente pidiendo el boletín de su acudido.');
    }

    /**
     * El paz y salvo vuelve a decidir.
     *
     * Estaba escrito y no se aplicaba: cualquier acudiente con deuda veía el
     * boletín igual. Joseth pidió activarlo (18 ago 2026) sabiendo que se nota
     * en producción desde el despliegue.
     */
    public function test_un_acudiente_con_deuda_no_ve_el_boletin(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        DB::update('UPDATE alumnos SET pazysalvo = 0 WHERE id = ?', [$suyo->alumno_id]);

        $this->pedir($token, 'boletines', $suyo->grupo_id,
            ['alumno_id' => $suyo->alumno_id, 'matricula_id' => $suyo->matricula_id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No está a paz y salvo. Lo siento.');
    }

    /** El rechazo deja rastro: es lo que mira el colegio cuando alguien reclama. */
    public function test_el_intento_rechazado_queda_en_bitacoras(): void
    {
        [$token, $mio, $otro] = $this->alumnoYCompanero();

        $antes = DB::table('bitacoras')->where('affected_element_type', 'AlumnoVerBoletin')->count();

        $this->pedir($token, 'boletines', $mio->grupo_id,
            ['alumno_id' => $otro->alumno_id, 'matricula_id' => $otro->matricula_id])
            ->assertStatus(403);

        $this->assertSame($antes + 1,
            DB::table('bitacoras')->where('affected_element_type', 'AlumnoVerBoletin')->count(),
            'El intento de ver el boletín de otro no quedó registrado.');
    }

    /**
     * La otra forma de pedir un alumno.
     *
     * `certificados-persona` no manda `requested_alumnos` sino un `alumno_id`
     * suelto. Es la misma familia —devuelve las matrículas de esa persona— y el
     * middleware entiende las dos formas; si solo entendiera una, esta ruta
     * seguiría abierta y el arreglo de los boletines no serviría de nada.
     */
    public function test_certificados_persona_solo_del_alumno_propio(): void
    {
        [$token, $mio, $otro] = $this->alumnoYCompanero();
        $cab = ['Authorization' => 'Bearer '.$token];

        $this->putJson('/api/certificados-persona', ['alumno_id' => $otro->alumno_id], $cab)
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes ver el de otros');

        $this->assertNotSame(403,
            $this->putJson('/api/certificados-persona', ['alumno_id' => $mio->alumno_id], $cab)->getStatusCode(),
            'El guard rechaza a un alumno pidiendo sus propias matrículas.');
    }

    /**
     * `requisitos` y `piars-grupos` los podía usar cualquiera con token.
     *
     * Un alumno llegaba a `DELETE api/requisitos/destroy/{id}` y recibía 200
     * "Eliminado". El único intento de guard era un `return 'No tienes
     * permiso';` dentro de un constructor, que no detiene nada.
     */
    public function test_un_alumno_no_entra_en_lo_del_personal(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);
        $cab = ['Authorization' => 'Bearer '.$token];

        $this->putJson('/api/requisitos', [], $cab)->assertStatus(403);
        $this->deleteJson('/api/requisitos/destroy/999999', [], $cab)->assertStatus(403);
        $this->getJson('/api/piars-grupos/grupos', $cab)->assertStatus(403);
    }

    /** Y el personal sin superusuario sigue entrando: era el riesgo del cambio. */
    public function test_el_personal_sin_superusuario_sigue_entrando(): void
    {
        $fila = DB::select('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotEmpty($fila, 'El seed no tiene ningún Usuario sin superusuario.');

        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($fila[0]->username)];

        $this->putJson('/api/requisitos', [], $cab)->assertStatus(200);
        $this->getJson('/api/piars-grupos/grupos', $cab)->assertStatus(200);
    }

    /**
     * Qué rutas llevan cada guard de autorización.
     *
     * Sin esto, quitar un `->middleware(...)` de una ruta no rompería nada: los
     * tests de arriba solo miran las rutas que nombran, y el agujero original era
     * precisamente que la copia de al lado no tenía la comprobación.
     *
     * **Era una lista escrita a mano de 31 rutas y ahora son más de doscientas**,
     * después de repartir `auth.personal` y `persona.propia` por la revisión de
     * IDOR. A ese tamaño una lista a mano deja de leerse y se convierte en algo
     * que se actualiza sin mirar, así que pasa a snapshot: el diff del `.json` es
     * lo que hay que revisar cuando cambie, y ahí sí se ve qué ruta ganó o perdió
     * un guard.
     *
     * El modo va al lado de la ruta —`[sin-paz-y-salvo]`, `[user_id]`— porque
     * hasta el 19 ago 2026 esta comprobación usaba un `in_array` exacto y las
     * rutas con modo no entraban en la lista: podían perderlo sin que fallara
     * nada. `notas/alumno` llevaba meses así.
     */
    public function test_los_guards_estan_en_las_rutas_que_deben(): void
    {
        $guards = ['auth.personal', 'persona.propia', 'boletin.propio'];
        $mapa = [];

        foreach ($guards as $guard) {
            $mapa[$guard] = [];
        }

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            foreach ($ruta->middleware() as $aplicado) {
                foreach ($guards as $guard) {
                    $modo = null;

                    if ($aplicado === $guard) {
                        $modo = '';
                    } elseif (str_starts_with((string) $aplicado, $guard.':')) {
                        $modo = ' ['.substr($aplicado, strlen($guard) + 1).']';
                    }

                    if ($modo === null) {
                        continue;
                    }

                    foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                        $mapa[$guard][] = $verbo.' '.$ruta->uri().$modo;
                    }
                }
            }
        }

        foreach ($mapa as $guard => $rutas) {
            sort($rutas);
            $mapa[$guard] = $rutas;

            $this->assertNotEmpty($rutas, "Ninguna ruta lleva '{$guard}'.");
        }

        $this->compararConInstantanea('guards-por-ruta', $mapa);
    }

    /**
     * El guard puesto no basta: tiene que reconocer lo que la ruta llama id.
     *
     * Esto sale del tercer punto ciego de la misma familia
     * ([05 §13.2](../../docs/migracion/05-codigo-muerto-y-roto.md)).
     * `DELETE images-users/destroy/{id}` llevaba `persona.propia` desde la
     * revisión de IDOR y **el guard no miraba nada**: recoge los identificadores
     * por su NOMBRE, y esa era la única ruta de imagen que llamaba `{id}` a lo
     * que sus cuatro hermanas llaman `{imagen_id}`. Sin identificador
     * reconocible, `ExigirPersonaPropia` entiende «lo mío» y deja pasar — que es
     * lo correcto para las rutas que no llevan ninguno, y lo peor posible para
     * una que lleva uno con otro nombre. Un alumno borraba la foto de
     * cualquiera.
     *
     * La regla que se comprueba aquí es la forma exacta de aquel fallo, y sirve
     * para cualquier nombre nuevo: **si una ruta trae identificadores y el guard
     * no reconoce NINGUNO, el guard no está haciendo nada.** Las rutas sin
     * identificador ninguno no entran — esas sí significan «lo mío»— y las que
     * traen uno reconocido junto a otros que no, tampoco: de esas se ocupa el
     * snapshot de abajo.
     *
     * Las claves se leen del propio middleware por reflexión, no se copian aquí:
     * una lista repetida se queda corta el día que el guard aprenda una clave
     * nueva, que es el fallo que este test existe para impedir.
     *
     * **Lo que este test NO ve**, y hay que saberlo: solo mira los parámetros de
     * la URL. El guard lee también el cuerpo y la query, y ahí una clave con
     * nombre nuevo sigue siendo invisible desde fuera. Para eso no hay atajo
     * estático — hace falta golpear la ruta, como en `SuperficieDeUnAlumnoTest`.
     */
    public function test_el_guard_reconoce_algun_identificador_de_cada_ruta_que_protege(): void
    {
        $ciegas = [];

        foreach ($this->rutasDePersonaPropia() as $ruta) {
            if ($ruta['identificadores'] !== [] && $ruta['reconocidos'] === []) {
                $ciegas[] = $ruta['uri'].' → '.implode(', ', $ruta['identificadores']);
            }
        }

        $this->assertSame([], $ciegas,
            "Estas rutas llevan 'persona.propia' y el guard no reconoce ninguno de sus identificadores, ".
            "así que las deja pasar enteras.\n".
            'O se renombra el parámetro a uno de los que el guard busca, o se le dice a qué apunta con '.
            "'persona.propia:<clave>', como hacen los perfiles/*/{id}.");
    }

    /**
     * Y los que el guard no reconoce aunque la ruta esté cubierta.
     *
     * Aquí no se puede afirmar nada: `{grupo_id}` y `{asignatura_id}` no nombran
     * a una persona y es correcto que el guard los ignore. Pero el día que
     * aparezca uno que sí —un `{expediente_id}` de otro alumno junto al
     * `{alumno_id}` que sí se comprueba— nadie lo va a notar sin verlo en un
     * diff. Por eso va a snapshot y no a `assert`: la máquina no sabe cuál de
     * los dos es, y una persona sí.
     */
    public function test_los_identificadores_que_el_guard_no_reconoce(): void
    {
        $mapa = [];

        foreach ($this->rutasDePersonaPropia() as $ruta) {
            $sinReconocer = array_values(array_diff($ruta['identificadores'], $ruta['reconocidos']));

            if ($sinReconocer !== []) {
                $mapa[$ruta['uri']] = [
                    'reconocidos' => $ruta['reconocidos'],
                    'sin_reconocer' => $sinReconocer,
                ];
            }
        }

        ksort($mapa);

        $this->compararConInstantanea('persona-propia-identificadores', $mapa);
    }

    /**
     * Cada ruta con `persona.propia`, con sus identificadores y cuáles de ellos
     * mira el guard.
     *
     * «Identificador» es el parámetro que se llama `id` o acaba en `_id`. Los
     * demás —`{tamanio}`, `{year}`— no nombran nada que tenga dueño.
     *
     * @return list<array{uri: string, identificadores: list<string>, reconocidos: list<string>}>
     */
    private function rutasDePersonaPropia(): array
    {
        /** @var list<string> $claves */
        $claves = (new \ReflectionClass(ExigirPersonaPropia::class))->getConstant('CLAVES');

        $rutas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            foreach ($ruta->middleware() as $aplicado) {
                $aplicado = (string) $aplicado;

                if ($aplicado !== 'persona.propia' && ! str_starts_with($aplicado, 'persona.propia:')) {
                    continue;
                }

                // El `{id}` genérico solo cuenta como reconocido si la ruta ha
                // dicho a qué apunta. Es el mecanismo que le faltaba a
                // `images-users/destroy`.
                $declarado = str_contains($aplicado, ':')
                    ? substr($aplicado, strlen('persona.propia:'))
                    : null;

                $identificadores = array_values(array_filter(
                    $ruta->parameterNames(),
                    fn ($p) => $p === 'id' || str_ends_with($p, '_id')
                ));

                $reconocidos = array_values(array_filter(
                    $identificadores,
                    fn ($p) => in_array($p, $claves, true) || ($p === 'id' && $declarado !== null)
                ));

                $rutas[] = [
                    'uri' => $ruta->uri().($declarado === null ? '' : ' ['.$declarado.']'),
                    'identificadores' => $identificadores,
                    'reconocidos' => $reconocidos,
                ];
            }
        }

        return $rutas;
    }

    /**
     * La hermana que no lleva el guard que llevan las once restantes.
     *
     * Es la forma exacta de todo lo que encontraron la §14, la §15 y la §16, y
     * la que ninguna de las tres herramientas mira: `inventario-autorizacion.py`
     * pregunta si la ruta trae el identificador de una persona —así que no ve
     * las que no traen ninguno—, y el barrido pregunta qué sale y qué se
     * escribe —así que no ve lo del colegio que no es de nadie—. Entre las dos
     * se colaron `unidades/trashed`, `subunidades/trashed`, `editnota/trashed`,
     * `asignaturas/papelera` y el GET de `unidades/de-asignatura-periodo` que
     * escribía. **Las cinco eran la única de su familia sin guard**, y eso sí es
     * mecánico.
     *
     * La regla: si un prefijo tiene dos o más rutas con guard de propiedad y
     * alguna sin él, esa alguna se mira. **No afirma que esté mal** —hay
     * excepciones legítimas y están abajo, una a una con su motivo— sino que no
     * se ha decidido. Una ruta nueva que nazca sin el guard de sus hermanas
     * rompe el test el mismo día, que es lo único que impide que la lista vuelva
     * a crecer sin que nadie lo note.
     *
     * Se mide el prefijo y no el controlador a propósito: `editnota/trashed`
     * está en `EditnotaController` y devuelve alumnos borrados, y lo que la
     * delató fue estar sola en `editnota/*`, no en su clase.
     *
     * @return array<string, string> uri => por qué se queda sin el guard de sus hermanas
     */
    private const EXCEPCIONES_DE_FAMILIA = [
        // Las lecturas de catálogo. Son las nueve que `inventario-autorizacion.py`
        // sigue contando y que esperan una decisión en 08: no exponen a nadie,
        // pero nadie ha dicho si un alumno tiene que poder leerlas. Van aquí con
        // el índice de cada recurso, que es el mismo caso.
        'GET api/areas' => 'catálogo, pendiente de decisión en 08',
        'GET api/comportamiento' => 'catálogo, pendiente de decisión en 08',
        'GET api/definiciones_comportamiento' => 'catálogo, pendiente de decisión en 08',
        'GET api/escalas' => 'catálogo, pendiente de decisión en 08',
        'GET api/frases' => 'catálogo, pendiente de decisión en 08',
        'GET api/grados' => 'catálogo, pendiente de decisión en 08',
        'GET api/grados/show/{id}' => 'catálogo, pendiente de decisión en 08',
        'GET api/grupos' => 'catálogo, pendiente de decisión en 08',
        'GET api/materias' => 'catálogo, pendiente de decisión en 08',
        'GET api/niveles_educativos' => 'catálogo, pendiente de decisión en 08',
        'GET api/niveles_educativos/show/{id}' => 'catálogo, pendiente de decisión en 08',
        'GET api/nota_comportamiento' => 'catálogo, pendiente de decisión en 08',
        'GET api/periodos' => 'catálogo, pendiente de decisión en 08',
        'GET api/periodos/show/{year_id}' => 'catálogo, pendiente de decisión en 08',
        'GET api/tiposdocumento' => 'catálogo, pendiente de decisión en 08',
        'GET api/asignaturas' => 'catálogo, pendiente de decisión en 08',
        'GET api/asignaturas/show/{asignatura_id}' => 'catálogo, pendiente de decisión en 08',

        // Decididas y abiertas a propósito.
        'GET api/ChangesAsked/to-me' => 'la pantalla de inicio de los cuatro tipos; cada rama se acota sola (05 §16.1)',
        'GET api/years' => 'la configuración del colegio, que necesita todo el mundo al entrar',
        'GET api/years/colegio' => 'lo mismo. El `telefono` que suelta es el del colegio',
        'GET api/contratos' => 'lo llama la app de Flutter desde pantallas de familia; qué columnas se recortan está en 09 §5',

        // Defendidas por dentro, que es por lo que el inventario tampoco las lista.
        'GET api/definitivas_periodos/arreglar-duplicados' => 'User::pueden_modificar_definitivas() corta a todo el que no sea superusuario o profesor con permiso',
        'PUT api/piars-alumnos/field' => 'comprueba dentro del método; responde 400 a una familia',
        'PUT api/images-users/imagenes-de-usuario' => 'sin `user_id` significa «las mías», que es lo que devuelve',

        // Rotas desde antes de la migración, con su entrada en 05.
        'GET api/definitivas_periodos' => 'rota: `$profe_id` no se define para este tipo de usuario (05, tabla de variables sin definir)',
        'GET api/editnota/detailed-notas-year' => 'rota: usa `$grupo_id` y la ruta no lleva parámetros (05, misma tabla)',

        // El flujo de votar, que es lo que queda abierto del módulo a propósito
        // después de cerrarle catorce (05 §18). `VotarCtrl` es el único estado de
        // `votaciones/*` del front sin `needed_permissions`.
        'GET api/candidatos/conaspiraciones' => 'la papeleta; se acota con actualInscrito($user) — y por eso está rota para una familia, 05 §18.4',
        'POST api/votos/store' => 'votar. Si esto lleva guard, no hay elecciones',
        'PUT api/votos/show' => 'los resultados de las votaciones del que pregunta; se acota por su user_id',

        // La que espera la decisión que Joseth dejó abierta.
        'GET api/asignaturas/listasignaturas-alone' => 'le da a un alumno las asignaturas del profesor con su id; es la pregunta abierta de 05 §11.2, anotada en 09 §5',
    ];

    public function test_ninguna_ruta_se_queda_sola_sin_el_guard_de_su_familia(): void
    {
        $familias = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $uri = $ruta->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $familias[explode('/', $uri)[1]][] = [
                    'clave' => $verbo.' '.$uri,
                    'guardada' => $this->llevaGuardDePropiedad($ruta),
                ];
            }
        }

        $solas = [];

        foreach ($familias as $prefijo => $rutas) {
            $conGuard = count(array_filter($rutas, fn ($r) => $r['guardada']));
            $sinGuard = count($rutas) - $conGuard;

            // La señal es «la que se quedó sola», no «esta familia está abierta».
            // Hacen falta al menos dos hermanas con guard —una sola no establece
            // ninguna costumbre— y que las que no lo llevan sean minoría clara.
            // Una familia mayoritariamente sin guard es otra pregunta y más
            // grande, y tiene su sitio en el snapshot de abajo: aquí daría
            // sesenta líneas de ruido y taparía las cinco que importan.
            if ($conGuard < 2 || $sinGuard > max(2, intdiv(count($rutas), 4))) {
                continue;
            }

            foreach ($rutas as $r) {
                if (! $r['guardada'] && ! array_key_exists($r['clave'], self::EXCEPCIONES_DE_FAMILIA)) {
                    $solas[] = $r['clave'].'   (sus hermanas de '.$prefijo.'/*: '
                        .$conGuard.' de '.count($rutas).' con guard)';
                }
            }
        }

        sort($solas);

        $this->assertSame([], $solas,
            'Estas rutas son la excepción de su familia: sus hermanas llevan guard de
'.
            'propiedad y ellas no. Es la forma exacta de los cinco agujeros de 05 §16.
'.
            'Míralas y, si están bien así, añádelas a EXCEPCIONES_DE_FAMILIA con el motivo.');
    }

    /**
     * Y al revés: una excepción que deje de hacer falta tiene que gritar.
     *
     * Es el mismo mecanismo que el `count` de `phpstan.neon`. Sin esto, la lista
     * de arriba solo puede crecer: alguien pone el guard, la entrada se queda, y
     * el día que la ruta vuelva a perderlo nadie se entera.
     */
    public function test_no_sobra_ninguna_excepcion_de_familia(): void
    {
        $abiertas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/') || $this->llevaGuardDePropiedad($ruta)) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $abiertas[] = $verbo.' '.$ruta->uri();
            }
        }

        $sobran = array_values(array_diff(array_keys(self::EXCEPCIONES_DE_FAMILIA), $abiertas));

        $this->assertSame([], $sobran,
            'Estas entradas de EXCEPCIONES_DE_FAMILIA ya no describen nada: o la ruta
'.
            'lleva guard, o ya no existe. Bórralas.');
    }

    /**
     * Cuántas de cada familia llevan guard, que es lo que el `assert` deja fuera.
     *
     * El test de arriba solo mira la que se quedó sola. Lo contrario —una familia
     * en la que la mayoría NO lleva guard, como `matriculas/*` con 2 de 16— no se
     * puede afirmar desde aquí: puede ser un módulo entero de administración que
     * nadie ha repasado, o puede estar bien porque el barrido ya lo midió por el
     * otro lado. La máquina no sabe cuál de las dos, y una persona sí.
     *
     * Va a snapshot por el mismo motivo que
     * `test_los_identificadores_que_el_guard_no_reconoce`: no afirma nada, pero
     * una familia que se abre o se cierra aparece en el diff.
     */
    public function test_cuantas_de_cada_familia_llevan_guard(): void
    {
        $familias = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/')) {
                continue;
            }

            $prefijo = explode('/', $ruta->uri())[1];

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $familias[$prefijo]['total'] = ($familias[$prefijo]['total'] ?? 0) + 1;
                $familias[$prefijo]['con_guard'] = ($familias[$prefijo]['con_guard'] ?? 0)
                    + ($this->llevaGuardDePropiedad($ruta) ? 1 : 0);
            }
        }

        ksort($familias);

        $this->compararConInstantanea('guard-por-familia', $familias);
    }

    /** Un guard de propiedad, con o sin el `:clave` que le dice a qué apunta el `{id}`. */
    /**
     * Las que están solas entre sus hermanas de OPERACIÓN, y por qué está bien.
     *
     * Mismo formato y mismo propósito que {@see EXCEPCIONES_DE_FAMILIA}: cada
     * entrada es una decisión escrita, y el test de más abajo grita si deja de
     * hacer falta.
     */
    private const EXCEPCIONES_DE_HERMANAS = [
        // Las que comprueban dentro del método. El inventario no las lista por lo
        // mismo, y el barrido las cuenta como mudas porque abortan con 400 o 401
        // en vez de con 403 — que es lo que hace el legacy en todas partes.
        'DELETE api/acudientes/destroy/{id}' => 'aborta 403 si no es superusuario ni Secretario',
        'DELETE api/alumnos/destroy/{id}' => 'aborta 400 salvo superusuario, Secretario o profesor con permiso',
        'DELETE api/alumnos/forcedelete/{id}' => 'lo mismo',
        'PUT api/alumnos/restore/{id}' => 'lo mismo',
        'PUT api/alumnos/update/{id}' => 'lo mismo',
        'POST api/alumnos/store' => 'lo mismo',
        'DELETE api/matriculas/destroy/{id}' => 'aborta 400 salvo superusuario o profesor con permiso',
        'DELETE api/enfermeria/destroy/{id}' => 'aborta 401 si no es superusuario ni Usuario',
        'POST api/acudientes/crear' => 'aborta 403 si no es superusuario, Profesor ni Secretario',

        // El módulo de tardanzas se autentica solo, y por eso ni siquiera lleva
        // `auth.token`: el lector de códigos manda usuario y contraseña en CADA
        // petición y `TSubirController::user()` exige Profesor o superusuario.
        'PUT api/tardanzas/subir/eliminar-ausencia' => 'TSubirController::user() exige Profesor o superusuario',
        'PUT api/tardanzas/subir/poner-ausencia' => 'lo mismo',

        // Defendidas por dentro, las dos que aparecen al bastar UNA hermana.
        'PUT api/piars-alumnos/field' => 'comprueba dentro del método; responde 400 a una familia',
        'PUT api/publicaciones/restaurar' => 'exigeQueLaPublicacionSeaSuya() corta antes del UPDATE',

        // Abiertas a propósito, con su decisión en otro sitio.
        'POST api/myimages/store' => 'subir su propia imagen es lo que hace una familia; el dueño sale del token',
        'POST api/votos/store' => 'votar. Si esto lleva guard, no hay elecciones (05 §18)',
        'PUT api/aplicacion-descargas/detailed' => 'devuelve el `$user` del token y nada más (05 §12)',
        'GET api/editnota/detailed-notas-year' => 'rota: usa `$grupo_id` y la ruta no lleva parámetros (05)',
    ];

    /**
     * La otra mitad de la pregunta de la §17, que hasta hoy no se hacía.
     *
     * El candado de familia mira el prefijo de la URL —`matriculas/*`— y dice si
     * una se quedó sola sin el guard que llevan sus vecinas. **Eso no ve el caso
     * de la §23:** `matriculas/alumnos-grado-anterior` iba sin guard mientras sus
     * tres hermanas de operación —`matriculas/alumnos-con-grado-anterior` y las
     * dos de `prematriculas`— lo llevaban. No estaba sola en su familia, porque
     * `matriculas/*` tiene muchas con guard; estaba sola entre las rutas que
     * apuntan al **mismo nombre de método**.
     *
     * Son dos preguntas distintas y las dos hacen falta. Ésta es la segunda:
     * agrupa por `Controlador@metodo` y no por prefijo. `putDatos` existe en
     * siete controladores y `deleteDestroy` en cuarenta y dos, y esa repetición
     * es la costumbre del proyecto: cuando cuarenta llevan guard y dos no, las
     * dos hay que mirarlas.
     *
     * **El umbral NO es el mismo que el de familia, y el porqué importa.** Allí
     * hacen falta dos hermanas con guard porque compartir prefijo de URL es una
     * relación floja: `matriculas/*` son treinta rutas que no se parecen en nada
     * y una sola con guard no establece ninguna costumbre. Aquí basta **una**,
     * porque compartir nombre de método es una relación fuerte: en este proyecto
     * significa que la operación está copiada y pegada en dos controladores.
     * `putAlumnosGradoAnterior` existe exactamente dos veces, y ése era el caso —
     * con el umbral de familia se habría escapado, comprobado quitándole el guard.
     *
     * Lo que sí se conserva es que las que no lo llevan sean minoría: un nombre de
     * método mayoritariamente sin guard no dice nada.
     */
    public function test_ninguna_ruta_se_queda_sola_entre_sus_hermanas_de_operacion(): void
    {
        $operaciones = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $accion = $ruta->getActionName();

            if (! str_starts_with($ruta->uri(), 'api/') || ! str_contains($accion, '@')) {
                continue;
            }

            $metodo = explode('@', $accion)[1];

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $operaciones[$metodo][] = [
                    'clave' => $verbo.' '.$ruta->uri(),
                    'guardada' => $this->llevaGuardDePropiedad($ruta),
                ];
            }
        }

        $solas = [];

        foreach ($operaciones as $metodo => $rutas) {
            $conGuard = count(array_filter($rutas, fn ($r) => $r['guardada']));
            $sinGuard = count($rutas) - $conGuard;

            if ($conGuard < 1 || $sinGuard > max(1, intdiv(count($rutas), 4))) {
                continue;
            }

            foreach ($rutas as $r) {
                if (! $r['guardada'] && ! array_key_exists($r['clave'], self::EXCEPCIONES_DE_HERMANAS)) {
                    $solas[] = $r['clave'].'   (sus hermanas de @'.$metodo.': '
                        .$conGuard.' de '.count($rutas).' con guard)';
                }
            }
        }

        sort($solas);

        $this->assertSame([], $solas,
            "Estas rutas apuntan al mismo método que otras que SÍ llevan guard de\n"
            ."propiedad, y ellas no. Es la forma exacta de las de 05 §23.\n"
            .'Míralas y, si están bien así, añádelas a EXCEPCIONES_DE_HERMANAS con el motivo.');
    }

    /** Y al revés, igual que en el de familia: una excepción que sobre tiene que gritar. */
    public function test_no_sobra_ninguna_excepcion_de_hermanas(): void
    {
        $abiertas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/') || $this->llevaGuardDePropiedad($ruta)) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $abiertas[] = $verbo.' '.$ruta->uri();
            }
        }

        $sobran = array_values(array_diff(array_keys(self::EXCEPCIONES_DE_HERMANAS), $abiertas));

        sort($sobran);

        $this->assertSame([], $sobran,
            'Estas entradas de EXCEPCIONES_DE_HERMANAS ya no hacen falta: la ruta lleva '
            .'guard, o ya no existe. Bórralas, o la lista solo puede crecer.');
    }

    private function llevaGuardDePropiedad(\Illuminate\Routing\Route $ruta): bool
    {
        foreach ($ruta->gatherMiddleware() as $m) {
            if (in_array(explode(':', (string) $m)[0], ['auth.personal', 'persona.propia', 'boletin.propio'], true)) {
                return true;
            }
        }

        return false;
    }
}
