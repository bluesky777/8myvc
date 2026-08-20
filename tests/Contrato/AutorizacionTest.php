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
}
