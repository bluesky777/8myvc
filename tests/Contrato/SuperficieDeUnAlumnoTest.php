<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Un alumno solo lo suyo; un acudiente, lo suyo y lo de sus acudidos.
 *
 * Es la regla que fijó Joseth el 19 ago 2026 después de la revisión de IDOR
 * (docs/migracion/08-revision-idor.md), y este archivo es lo que la sostiene.
 *
 * **Cada caso de aquí se escribió primero al revés.** La revisión encontró 27
 * cosas que un alumno podía hacer con su token —cambiarle el nombre de usuario al
 * rector, leer antecedentes médicos ajenos, abrirle un proceso disciplinario a un
 * compañero, borrar un año lectivo— y se fijaron con tests que las afirmaban. Al
 * cerrarlas, cada test falló, y ese fallo es lo que demostró que el guard llegaba
 * a la ruta. Luego se dieron la vuelta. Un test de autorización escrito solo en su
 * versión final no prueba que antes hiciera falta.
 *
 * **El token de un alumno es la línea correcta que hay que probar.** No es un
 * atacante externo: es la credencial que el colegio le da a cada uno de sus 1.279
 * alumnos, y la que cualquiera puede usar desde las herramientas del navegador sin
 * saber programar.
 *
 * Lo que este archivo NO fija es qué puede hacer el personal del colegio entre sí.
 * Eso queda como está hasta el refactor de permisos, y mezclarlo aquí habría
 * convertido un arreglo comprobable en uno que hay que discutir.
 */
class SuperficieDeUnAlumnoTest extends CasoDeContrato
{
    /** El alumno del seed, otro alumno que no es él, y un usuario del colegio. */
    private function actores(): array
    {
        $yo = $this->usuarioDeTipo('Alumno');

        $mio = DB::selectOne('SELECT id FROM alumnos WHERE user_id = ? AND deleted_at IS NULL', [$yo->id]);

        $otro = DB::selectOne('SELECT a.id, a.user_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE a.id <> ? AND a.user_id IS NOT NULL AND a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1', [$mio->id]);

        $personal = DB::selectOne('SELECT id, username FROM users
            WHERE is_superuser = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return [$yo, $mio, $otro, $personal, ['Authorization' => 'Bearer '.$this->tokenDe($yo->username)]];
    }

    /**
     * Le cambia el nombre de usuario al rector, y con eso lo deja fuera.
     *
     * De todo lo que hay en este archivo es lo más directo: `username` es con lo
     * que se entra, así que cambiárselo a otro es cerrarle la puerta de su propia
     * cuenta. No hace falta saber su contraseña ni su correo, solo su id, que es
     * un número pequeño.
     *
     * `perfiles/cambiarpassword/{id}` **sí** comprueba la contraseña antigua, así
     * que esa no se puede. Es la única de la familia que se defiende, y por eso
     * conviene decirlo: no es que el módulo de perfiles no compruebe nada, es que
     * comprueba en un sitio de tres.
     */
    public function test_un_alumno_no_le_cambia_el_username_a_otro(): void
    {
        [, , , $personal, $cab] = $this->actores();

        $antes = $personal->username;

        $this->putJson("/api/perfiles/guardar-username/{$personal->id}",
            ['username' => 'robado-'.$personal->id], $cab)->assertStatus(403);

        $this->assertSame($antes,
            DB::selectOne('SELECT username u FROM users WHERE id = ?', [$personal->id])->u,
            'El 403 llegó tarde: el nombre de usuario ya estaba cambiado.');
    }

    public function test_un_alumno_no_edita_el_perfil_de_otro(): void
    {
        [, $mio, , $personal, $cab] = $this->actores();

        $this->putJson("/api/perfiles/update/{$personal->id}",
            ['nombres' => 'X', 'tipo' => 'Alumno'], $cab)->assertStatus(403);

        // Y tampoco por el otro lado: `perfiles/update` elige la TABLA con el
        // `tipo` que manda el cliente, así que con su propio id y `tipo=Profesor`
        // editaría al profesor que tenga ese número. Por eso el guard comprueba
        // las dos cosas.
        $this->putJson("/api/perfiles/update/{$mio->id}",
            ['nombres' => 'X', 'tipo' => 'Profesor'], $cab)->assertStatus(403);
    }

    /**
     * Lecturas de datos de OTRA persona, pidiendo con su id.
     *
     * `enfermeria/datos` es el que más pesa de la lista: devuelve antecedentes
     * médicos —cirugías, alergias, vacunas— de cualquier alumno del colegio. Y
     * las dos de `piars-*` listan el grupo entero de un módulo cuyos datos el
     * generador del seed omite a propósito por ser «el dato más sensible del
     * sistema».
     */
    public static function lecturasDeOtro(): array
    {
        return [
            'bitácora de otro usuario' => ['GET', 'bitacoras/{user}', []],
            'historial de sesiones' => ['PUT', 'historiales/de-usuario', ['user_id' => '{user}']],
            'antecedentes médicos' => ['PUT', 'enfermeria/datos', ['alumno_id' => '{alumno}']],
            'acudientes de otro' => ['PUT', 'acudientes/de-persona', ['alumno_id' => '{alumno}', 'persona_id' => '{alumno}']],
            'matrículas de otro' => ['PUT', 'detalles/alumno', ['alumno_id' => '{alumno}']],
            'años con notas de otro' => ['PUT', 'alumnos/years-con-notas', ['alumno_id' => '{alumno}']],
            'asignaturas de otro' => ['PUT', 'mis-actividades/datos', ['alumno_id' => '{alumno}']],
            'frases de otro' => ['GET', 'frases_asignatura/show/{alumno}/1', []],
        ];
    }

    #[DataProvider('lecturasDeOtro')]
    public function test_un_alumno_no_lee_datos_de_otra_persona(string $verbo, string $ruta, array $cuerpo): void
    {
        [, , $otro, $personal, $cab] = $this->actores();

        $sustituir = fn ($v) => str_replace(['{alumno}', '{user}'], [$otro->id, $personal->id], (string) $v);

        $this->json($verbo, '/api/'.$sustituir($ruta), array_map($sustituir, $cuerpo), $cab)
            ->assertStatus(403);
    }

    /**
     * Y el grupo entero, sin pedir a nadie en concreto.
     *
     * Estas no necesitan ni el id de otro: con el de su propio grupo, un alumno
     * saca la lista completa con documentos, direcciones y teléfonos de sus 68
     * compañeros, el observador con los acudientes de cada uno, y la rejilla de
     * notas que edita el profesor.
     *
     * `boletines` y `bolfinales` ya no están en esta lista, y ese es el ejemplo
     * de lo que falta: se cerraron en el P0 con `boletin.propio`, que existe y
     * funciona. El resto de la pantalla del grupo se quedó abierta.
     */
    public static function lecturasDelGrupo(): array
    {
        return [
            'listado con documentos' => ['GET', 'grupos/listado/{grupo}'],
            'alumnos del grupo' => ['PUT', 'alumnos/de-grupo/{grupo}'],
            'observador con acudientes' => ['GET', 'observador/vertical/{grupo}/carta'],
            'observador horizontal' => ['PUT', 'observador-horizontal/horizontal/{grupo}'],
            'rejilla de notas del profesor' => ['PUT', 'editnota/detailed-notas/{grupo}'],
            'puestos del periodo' => ['PUT', 'puestos/detailed-notas-periodo/{grupo}'],
            'comportamiento del grupo' => ['GET', 'nota_comportamiento/detailed/{grupo}'],
            'alumnos con PIAR' => ['GET', 'piars-alumnos/alumnos/{grupo}'],
            'actas de acuerdo PIAR' => ['GET', 'piars-actas-acuerdo/matriculas/{grupo}'],
        ];
    }

    #[DataProvider('lecturasDelGrupo')]
    public function test_un_alumno_no_lee_el_grupo_entero(string $verbo, string $ruta): void
    {
        [, , , , $cab] = $this->actores();
        $grupo = $this->grupoConAlumnos();

        $this->json($verbo, '/api/'.str_replace('{grupo}', (string) $grupo->id, $ruta), [], $cab)
            ->assertStatus(403);
    }

    /**
     * Los listados que no nombran a nadie, y por eso nadie los miró.
     *
     * **Es el cuarto punto ciego de la misma familia, y el que más gente
     * expone.** Los tres anteriores —los buscadores de [05 §11.3], el inventario
     * de [08 §4] y el `{id}` que el guard no reconocía de [05 §13.2]— se
     * encontraron preguntando por la PETICIÓN: qué identificador viaja, y si el
     * guard lo mira. `inventario-autorizacion.py` contesta eso, y los dos
     * candados de `AutorizacionTest` también.
     *
     * Estas rutas no traen ningún identificador. `planillas/ver-simat` no pide
     * grupo: devuelve TODOS los del año. Ninguna herramienta las señaló porque
     * ninguna pregunta por el RESULTADO, que es justo el criterio que hace útiles
     * a los tests de contrato —mirar lo que sale y no el estado— y que aquí no se
     * estaba aplicando a la autorización.
     *
     * El patrón se ve al mirar sus vecinas: en `ProfesoresController` y en
     * `ContratosController` **todas las rutas llevan `auth.personal` menos el
     * listado**, y en `PlanillasController` lo llevan `show-grupo` y
     * `show-profesor` pero no las tres que no piden nada. Lo que se guardó fue lo
     * que nombraba a alguien.
     *
     * Lo dice el propio `ExigirPersonaPropia` en su cabecera: «lo que no puede
     * pasar es que una ruta de grupo entero llegue aquí sin id y salga entera:
     * esas llevan `auth.personal`». La regla estaba escrita; estas siete se
     * quedaron sin ella.
     *
     * Ninguna de las siete la llama una pantalla de familia: las tres de
     * planillas cuelgan de `panel.informes` —con `hasRoleOrPerm(['psicólogo'])`
     * encima—, `perfiles/usuariosall` es la rejilla de `UsuariosCtrl`,
     * `profesores` lo piden cinco pantallas de administración, y `grupos/show`
     * y `perfiles/show` no las llama nadie en ninguno de los cuatro clientes.
     */
    public static function listadosSinIdentificador(): array
    {
        return [
            // Todos los grupos del año con la ficha SIMAT completa de cada alumno:
            // documento, tipo de sangre, EPS, teléfono, dirección y correo.
            'planilla SIMAT del colegio' => ['GET', 'planillas/ver-simat', 'documento'],
            'planilla de ausencias' => ['GET', 'planillas/ver-ausencias', 'documento'],
            'listas personalizadas' => ['GET', 'planillas/listas-personalizadas', 'documento'],
            // El directorio entero: nombre, usuario, tipo, correo y fecha de
            // nacimiento de todas las personas del colegio.
            'directorio de usuarios' => ['GET', 'perfiles/usuariosall', 'fecha_nac'],
            // La hoja de vida de cada docente: documento, dirección, teléfono,
            // celular, correo y estado civil.
            'listado de profesores' => ['GET', 'profesores', 'num_doc'],
            // Un grupo cualquiera con la ficha completa de su titular. Y su
            // gemelo de `perfiles`, que es el mismo método copiado en el
            // controlador equivocado — ver [05 §14.2].
            'grupo ajeno con su titular' => ['GET', 'grupos/show/{grupo}', 'num_doc'],
            'el gemelo en perfiles' => ['GET', 'perfiles/show/{grupo}', 'num_doc'],
        ];
    }

    #[DataProvider('listadosSinIdentificador')]
    public function test_un_alumno_no_lee_los_listados_del_colegio(string $verbo, string $ruta, string $columna): void
    {
        [, , , , $cab] = $this->actores();
        $grupo = $this->grupoConAlumnos();

        $this->noSaleElDato($verbo, $ruta, $columna, $grupo->id, $cab);
    }

    /** Y un acudiente tampoco: la puerta es la misma para los dos. */
    #[DataProvider('listadosSinIdentificador')]
    public function test_un_acudiente_no_lee_los_listados_del_colegio(string $verbo, string $ruta, string $columna): void
    {
        $acudiente = $this->usuarioDeTipo('Acudiente');
        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($acudiente->username)];
        $grupo = $this->grupoConAlumnos();

        $this->noSaleElDato($verbo, $ruta, $columna, (int) $grupo->id, $cab);
    }

    /**
     * El 403 **y** que el dato no haya salido, que no son la misma comprobación.
     *
     * Mirar solo el estado deja pasar el caso que ya ocurrió una vez en este
     * proyecto: el guard responde el error después de que la operación haya
     * tenido efecto (§13.1). Aquí la versión de lectura de lo mismo sería un
     * cuerpo con datos y un código de error encima, así que se mira la columna
     * concreta que cada ruta filtraba — la que hizo fallar a este mismo caso
     * cuando se escribió al revés.
     */
    private function noSaleElDato(string $verbo, string $ruta, string $columna, int $grupoId, array $cab): void
    {
        $r = $this->json($verbo, '/api/'.str_replace('{grupo}', (string) $grupoId, $ruta), [], $cab);

        $r->assertStatus(403);

        $this->assertDoesNotMatchRegularExpression(
            '/"'.$columna.'"\s*:\s*"[^"]+"/',
            (string) $r->getContent(),
            "'{$ruta}' responde 403 y aun así trae '{$columna}' en el cuerpo."
        );
    }

    /**
     * Escrituras sobre otro alumno, comprobadas por su efecto y no por el 200.
     *
     * Un 200 no prueba nada si la petición no llegó a escribir —es la trampa que
     * ya costó tiempo en `NotasTest`—, así que cada una se comprueba contando
     * filas antes y después.
     */
    public function test_un_alumno_no_le_pone_un_proceso_disciplinario_a_otro(): void
    {
        [, , $otro, , $cab] = $this->actores();

        $antes = DB::selectOne('SELECT COUNT(*) n FROM dis_procesos WHERE alumno_id = ?', [$otro->id])->n;

        $this->postJson('/api/disciplina/store', ['alumno_id' => $otro->id], $cab)->assertStatus(403);

        $this->assertSame($antes,
            DB::selectOne('SELECT COUNT(*) n FROM dis_procesos WHERE alumno_id = ?', [$otro->id])->n,
            'El 403 llegó tarde: el proceso disciplinario ya estaba abierto.');
    }

    public function test_un_alumno_no_le_pone_una_ausencia_a_otro(): void
    {
        [, , $otro, , $cab] = $this->actores();

        $antes = DB::selectOne('SELECT COUNT(*) n FROM ausencias WHERE alumno_id = ?', [$otro->id])->n;

        $this->postJson('/api/ausencias/agregar-ausencia', ['alumno_id' => $otro->id], $cab)
            ->assertStatus(403);

        $this->assertSame($antes,
            DB::selectOne('SELECT COUNT(*) n FROM ausencias WHERE alumno_id = ?', [$otro->id])->n,
            'El 403 llegó tarde: la ausencia ya estaba puesta.');
    }

    /**
     * Y lo saca del colegio, sin papelera.
     *
     * `detalles/eliminar-matricula-destroy` es un `DELETE FROM matriculas WHERE
     * id=?` a pelo: no marca `deleted_at`, borra la fila. Se escribió este test
     * esperando encontrarla en la papelera y lo que había era un hueco, que es
     * como se supo. Un alumno le quita a otro la matrícula del año, y no queda
     * de dónde restaurarla.
     */
    public function test_un_alumno_no_borra_la_matricula_de_otro(): void
    {
        [, , $otro, , $cab] = $this->actores();

        $matricula = DB::selectOne('SELECT id FROM matriculas
            WHERE alumno_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$otro->id]);

        $this->putJson('/api/detalles/eliminar-matricula-destroy',
            ['matricula_id' => $matricula->id], $cab)->assertStatus(403);

        $this->assertNotNull(DB::selectOne('SELECT id FROM matriculas WHERE id = ?', [$matricula->id]),
            'El 403 llegó tarde: la matrícula ya no está, y no había papelera.');
    }

    /**
     * Y lo que ya no es ni de una persona: la configuración del colegio.
     *
     * Esto deja de ser IDOR y pasa a ser que faltan guards de rol, pero salió de
     * la misma revisión y es lo más destructivo de todo lo que hay aquí. Un
     * alumno borra el año lectivo 2018 con sus notas, o cambia cuál es el periodo
     * actual del colegio —que es lo que decide qué ven todos los profesores al
     * entrar—.
     *
     * `grupos/forcedelete` **no** está en la lista: se cerró en la Fase 6 y sigue
     * cerrado. Es la prueba de que el guard existe y de que lo que falta es
     * ponerlo en el resto.
     */
    public static function configuracionDelColegio(): array
    {
        return [
            'borrar el grupo' => ['DELETE', 'grupos/destroy/{grupo}'],
            'borrar un año lectivo' => ['DELETE', 'years/delete/1'],
            'borrar una materia' => ['DELETE', 'materias/destroy/1'],
            'cambiar el periodo actual' => ['PUT', 'periodos/establecer-actual/{periodo}'],
        ];
    }

    #[DataProvider('configuracionDelColegio')]
    public function test_un_alumno_no_toca_la_configuracion_del_colegio(string $verbo, string $ruta): void
    {
        [, , , , $cab] = $this->actores();
        $grupo = $this->grupoConAlumnos();

        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL
            ORDER BY numero LIMIT 1', [$grupo->year_id]);

        $url = str_replace(['{grupo}', '{periodo}'], [$grupo->id, $periodo->id], $ruta);

        $this->json($verbo, '/api/'.$url, [], $cab)->assertStatus(403);
    }

    /**
     * Las escrituras que tampoco nombran a nadie — la otra mitad de §14.
     *
     * El barrido de lectura contestaba «¿qué sale?». Este contesta **«¿llegó a
     * escribir?»**, que no es lo mismo que qué código respondió: en este proyecto
     * se lee con `PUT`, así que un 200 no distingue una consulta de un `UPDATE`.
     * Se midió escuchando las consultas de cada petición y quedándose con las que
     * insertan, actualizan o borran.
     *
     * De las 417 escrituras de la API, 133 llegaban al controlador con el token de
     * un alumno y **27 cambiaban datos de verdad**. El patrón es otra vez el
     * mismo, y en tres controladores enteros —actividades, preguntas y opciones—
     * se ve de un vistazo: **la única ruta que llevaba guard era `destroy/{id}`**,
     * la única que tiene un `{id}` en la URL.
     */
    public static function escriturasDelColegio(): array
    {
        return [
            // La peor de todas: `clave` y `grupo_id` en el cuerpo, y le pone esa
            // contraseña a TODOS los alumnos del grupo.
            'la contraseña de todo un grupo' => ['PUT', 'alumnos/cambiar-claves',
                ['clave' => 'robada-1234', 'grupo_id' => '{grupo}']],
            'crear los usuarios del colegio' => ['PUT', 'perfiles/creartodoslosusuarios', []],
            'el logo del colegio' => ['PUT', 'myimages/cambiarlogocolegio', ['imagen_id' => 1]],
            'la foto de otro alumno' => ['PUT', 'perfiles/cambiarimgunalumno/{otroAlumno}', []],
            'la foto de un usuario' => ['PUT', 'images-users/cambiar-foto-un-usuario/{otroUser}', []],
            'la firma de un profesor' => ['PUT', 'images-users/cambiar-firma-un-profe/{profesor}', []],
            // Los interruptores de la elección del colegio.
            'abrir la votación' => ['PUT', 'votaciones/set-actual', ['id' => '{votacion}', 'actual' => true]],
            'bloquear la votación' => ['PUT', 'votaciones/set-locked', ['id' => '{votacion}']],
            'quién puede votar' => ['PUT', 'votaciones/set-votan-acudientes', ['id' => '{votacion}']],
            'ver los resultados' => ['PUT', 'votaciones/set-permiso-ver-results', ['id' => '{votacion}']],
            // El fichero de acudientes, que se lee con PUT y por eso no salió en §14.
            'buscar en los acudientes' => ['PUT', 'acudientes/buscar', ['texto_a_buscar' => 'a']],
            'los acudientes sin asignar' => ['PUT', 'acudientes/no-asignados', []],
            'la ficha de los docentes' => ['PUT', 'participantes/profesores', []],
            // El lado del autor de las actividades. El del alumno es
            // `mis-actividades/*`, que sigue abierto y tiene su caso más abajo.
            'crear una pregunta' => ['POST', 'preguntas/crear', []],
            'compartir una actividad con un grupo' => ['PUT', 'actividades/insert-grupo-compartido', []],
            'editar una actividad' => ['PUT', 'actividades/edicion', []],
            'los alumnos del grado anterior' => ['PUT', 'matriculas/alumnos-con-grado-anterior', []],
        ];
    }

    #[DataProvider('escriturasDelColegio')]
    public function test_un_alumno_no_escribe_en_lo_del_colegio(string $verbo, string $ruta, array $cuerpo): void
    {
        [, , $otro, $personal, $cab] = $this->actores();
        $grupo = $this->grupoConAlumnos();

        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $votacion = DB::selectOne('SELECT id FROM vt_votaciones ORDER BY id LIMIT 1');

        $de = [
            '{grupo}' => $grupo->id,
            '{otroAlumno}' => $otro->id,
            '{otroUser}' => $personal->id,
            '{profesor}' => $profesor->id ?? 1,
            '{votacion}' => $votacion->id ?? 1,
        ];

        $this->json($verbo, '/api/'.strtr($ruta, array_map('strval', $de)),
            array_map(fn ($v) => is_string($v) ? strtr($v, array_map('strval', $de)) : $v, $cuerpo),
            $cab)->assertStatus(403);
    }

    /**
     * La contraseña de un compañero sigue siendo la suya.
     *
     * Es la de §15 que había que comprobar por su efecto y no por el 403: un
     * `UPDATE ... INNER JOIN matriculas` sobre un grupo entero no deja rastro en
     * la respuesta, y si el guard llegara tarde el daño ya estaría hecho y sería
     * **irreversible** — nadie guarda la contraseña anterior.
     */
    public function test_un_alumno_no_le_cambia_la_clave_a_todo_un_grupo(): void
    {
        [, , , , $cab] = $this->actores();
        $grupo = $this->grupoConAlumnos();

        $antes = DB::selectOne('SELECT u.password FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            ORDER BY u.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($antes, 'El grupo del seed no tiene alumnos con cuenta.');

        $this->putJson('/api/alumnos/cambiar-claves',
            ['clave' => 'robada-1234', 'grupo_id' => $grupo->id], $cab)->assertStatus(403);

        $this->assertSame($antes->password,
            DB::selectOne('SELECT u.password FROM users u
                INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
                INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
                ORDER BY u.id LIMIT 1', [$grupo->id])->password,
            'El 403 llegó tarde: las contraseñas del grupo ya estaban cambiadas.');
    }

    /**
     * Una imagen ajena no se vuelve suya, que es lo que abría la puerta a lo demás.
     *
     * `move-img-to-me` no es una fuga sino **una escalada**: hace
     * `UPDATE images SET user_id=<yo>` sin mirar de quién era, y en cuanto la
     * imagen es suya, sus hermanas —rotar, publicar, privatizar, borrar—
     * comprueban la propiedad y dicen que sí. El guard no veía nada que comprobar
     * porque aquí la clave se llama `img_id` y sus siete claves terminaban en
     * `_id` con otra grafía. Es la razón de que la lista de nombres tenga que
     * crecer con los endpoints y no al revés.
     */
    public function test_un_alumno_no_se_queda_con_la_imagen_de_otro(): void
    {
        [$yo, , , , $cab] = $this->actores();

        $ajena = DB::selectOne('SELECT id, user_id FROM images
            WHERE user_id IS NOT NULL AND user_id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$yo->id]);

        $this->assertNotNull($ajena, 'El seed no tiene ninguna imagen de otra persona.');

        $this->putJson('/api/images-users/move-img-to-me', ['img_id' => $ajena->id], $cab)
            ->assertStatus(403);

        $this->assertSame((int) $ajena->user_id,
            (int) DB::selectOne('SELECT user_id FROM images WHERE id = ?', [$ajena->id])->user_id,
            'La imagen cambió de dueño.');
    }

    /**
     * El muro: la publicación de otro no se toca, la propia sí.
     *
     * Aquí el guard no servía —la publicación no viaja como persona, viaja como
     * `publi_id`— y la regla existía **solo en el frontend**, en el `ng-if` del
     * botón de la papelera. Los dos casos van juntos a propósito: el segundo es
     * el que impide arreglar el primero cerrando la puerta entera.
     */
    public function test_un_alumno_borra_su_publicacion_y_no_la_de_otro(): void
    {
        [$yo, $mio, , , $cab] = $this->actores();

        $ajena = DB::selectOne('SELECT id FROM publicaciones
            WHERE NOT (persona_id = ? AND tipo_persona = "Alumno") AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$mio->id]);

        $this->assertNotNull($ajena, 'El seed no tiene ninguna publicación de otra persona.');

        $this->putJson('/api/publicaciones/delete', ['publi_id' => $ajena->id], $cab)
            ->assertStatus(403);

        $this->assertNull(DB::selectOne('SELECT deleted_at FROM publicaciones WHERE id = ?',
            [$ajena->id])->deleted_at, 'La publicación ajena quedó borrada.');

        // Y la suya sí, que es la mitad que no se puede perder.
        DB::insert('INSERT INTO publicaciones(persona_id, tipo_persona, contenido, created_at, updated_at)
            VALUES(?, "Alumno", "mía", ?, ?)', [$mio->id, now(), now()]);
        $suya = DB::getPdo()->lastInsertId();

        $this->putJson('/api/publicaciones/delete', ['publi_id' => $suya], $cab)->assertStatus(200);

        $this->assertNotNull(DB::selectOne('SELECT deleted_at FROM publicaciones WHERE id = ?',
            [$suya])->deleted_at, 'Un alumno ya no puede borrar su propia publicación.');
    }

    // ------------------------------------------------ Y lo que SÍ tienen que poder

    /**
     * La otra mitad de la regla, y la que de verdad hay que vigilar.
     *
     * Cerrar rutas es fácil; cerrarlas de más también, y se nota en producción
     * cuando una familia abre la app y no ve nada. Estos casos son el contrapeso:
     * un alumno tiene que seguir viendo LO SUYO por las mismas rutas que antes
     * usaba para ver lo de todos.
     *
     * Se comprueba `assertNotSame(403)` y no `assertStatus(200)` a propósito:
     * varias de estas responden 400 o 500 por motivos suyos, viejos, que no son
     * de autorización y que este archivo no viene a arreglar. Lo que aquí importa
     * es que el guard no sea quien las corta.
     */
    public static function loSuyoDeUnAlumno(): array
    {
        return [
            'sus matrículas' => ['PUT', 'detalles/alumno', ['alumno_id' => '{mio}']],
            'sus años con notas' => ['PUT', 'alumnos/years-con-notas', ['alumno_id' => '{mio}']],
            'sus asignaturas' => ['PUT', 'mis-actividades/datos', ['alumno_id' => '{mio}']],
            'su ficha de enfermería' => ['PUT', 'enfermeria/datos', ['alumno_id' => '{mio}']],
            'sus acudientes' => ['PUT', 'acudientes/de-persona', ['alumno_id' => '{mio}']],
            'sus imágenes' => ['PUT', 'myimages/datos-imagen', []],
            'sus notas' => ['GET', 'notas/alumno/{mio}', []],
        ];
    }

    #[DataProvider('loSuyoDeUnAlumno')]
    public function test_un_alumno_sigue_viendo_lo_suyo(string $verbo, string $ruta, array $cuerpo): void
    {
        [, $mio, , , $cab] = $this->actores();

        $sustituir = fn ($v) => str_replace('{mio}', (string) $mio->id, (string) $v);

        $r = $this->json($verbo, '/api/'.$sustituir($ruta), array_map($sustituir, $cuerpo), $cab);

        $this->assertNotSame(403, $r->getStatusCode(),
            "El guard está cortando a un alumno pidiendo lo suyo: {$verbo} {$ruta}.");
    }

    /**
     * Y un acudiente, lo de sus acudidos completo.
     *
     * «Completo» es la palabra de la regla: al acudiente no se le recorta nada de
     * lo de su acudido. Por eso `persona.propia` resuelve los parentescos y acepta
     * tanto el `alumno_id` del acudido como el `user_id` de su cuenta —hay rutas
     * que piden por uno y rutas que piden por el otro—, y por eso no comprueba el
     * paz y salvo, que es cosa de los boletines y ya lo lleva su propio guard.
     */
    public function test_un_acudiente_ve_lo_de_su_acudido_y_no_lo_de_otros(): void
    {
        $fila = DB::selectOne('SELECT u.username, p.alumno_id FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN alumnos a ON a.id = p.alumno_id AND a.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = u.periodo_id
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene un acudiente con acudido.');

        $ajeno = DB::selectOne('SELECT id FROM alumnos WHERE id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$fila->alumno_id]);

        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($fila->username)];

        foreach (['detalles/alumno', 'alumnos/years-con-notas', 'enfermeria/datos'] as $ruta) {
            $this->assertNotSame(403,
                $this->putJson("/api/{$ruta}", ['alumno_id' => $fila->alumno_id], $cab)->getStatusCode(),
                "El guard corta a un acudiente pidiendo lo de su acudido: {$ruta}.");

            $this->putJson("/api/{$ruta}", ['alumno_id' => $ajeno->id], $cab)->assertStatus(403);
        }
    }

    /** El rechazo deja rastro, que es lo que mira el colegio cuando alguien reclama. */
    public function test_el_intento_de_pedir_lo_ajeno_queda_en_bitacoras(): void
    {
        [, , $otro, , $cab] = $this->actores();

        $antes = DB::table('bitacoras')->where('affected_element_type', 'like', 'AlumnoPideAjeno%')->count();

        $this->putJson('/api/detalles/alumno', ['alumno_id' => $otro->id], $cab)->assertStatus(403);

        $this->assertSame($antes + 1,
            DB::table('bitacoras')->where('affected_element_type', 'like', 'AlumnoPideAjeno%')->count(),
            'El intento de pedir los datos de otro no quedó registrado.');
    }

    /** Lo que sí está cerrado, para que no se caiga sin que nadie lo note. */
    public function test_lo_que_ya_esta_cerrado_sigue_cerrado(): void
    {
        [, , , , $cab] = $this->actores();
        $grupo = $this->grupoConAlumnos();

        $rol = DB::selectOne('SELECT id FROM roles ORDER BY id LIMIT 1');

        // Fase 6: asignarse un rol a uno mismo.
        $this->putJson("/api/roles/addroletouser/{$rol->id}", ['user_id' => 1], $cab)->assertStatus(403);

        // Fase 6: el borrado definitivo de un grupo, que cascadea a 27 tablas.
        $this->deleteJson("/api/grupos/forcedelete/{$grupo->id}", [], $cab)->assertStatus(403);

        // P0: las notas de un compañero.
        $otro = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id]);

        $this->getJson("/api/notas/alumno/{$otro->id}", $cab)->assertStatus(403);

        // `perfiles/cambiarpassword` era la única de su familia que comprobaba algo
        // —pedía la contraseña antigua, y por eso respondía 400 y no 200—. Ahora
        // ni llega: el guard corta antes por ser de otro.
        $this->putJson('/api/perfiles/cambiarpassword/1',
            ['password' => 'x-1234', 'password_confirmation' => 'x-1234'], $cab)
            ->assertStatus(403);
    }
}
