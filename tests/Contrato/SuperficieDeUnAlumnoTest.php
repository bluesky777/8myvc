<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
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

    /**
     * Un GET que escribe, que es lo que hacía falta mirar y no se estaba mirando.
     *
     * `GET unidades/de-asignatura-periodo/{asignatura}/{periodo}` era la única de
     * `unidades/*` sin `auth.personal`, y no es una lectura: cuando esa asignatura
     * y ese periodo no tienen unidades todavía, **las crea** a partir de las
     * unidades por defecto del año, con `created_by` de quien pregunta. Un alumno
     * y un acudiente creaban unidades y subunidades en la estructura de notas del
     * colegio pidiendo una URL.
     *
     * No lo encontró ninguna de las tres herramientas, y por tres motivos
     * distintos que vale la pena separar: el inventario lo tenía en la lista de
     * lecturas de estructura pendientes de decidir —porque preguntaba por el
     * identificador, no por lo que hace—; el barrido de escrituras lo golpeó con
     * `asignatura_id=0`; y aunque lo hubiera golpeado bien, **`unidades_por_defecto`
     * está vacía en el seed**, así que la rama que escribe no se ejecuta. Por eso
     * este test la llena primero: sin esa fila, pasaría dijera lo que dijera el
     * código. Ver 05 §16.
     */
    public function test_una_familia_no_crea_unidades_con_un_get(): void
    {
        $par = DB::selectOne('SELECT a.id AS asignatura_id, p.id AS periodo_id, p.year_id
            FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM unidades u WHERE u.asignatura_id = a.id
                              AND u.periodo_id = p.id AND u.deleted_at IS NULL)
            ORDER BY a.id, p.id LIMIT 1');

        $this->assertNotNull($par, 'El seed no tiene ninguna asignatura sin unidades en algún periodo.');

        // El colegio de verdad tiene unidades por defecto y el seed no. Sin esto
        // el endpoint sale por el `return ''` y no escribe nunca.
        DB::insert('INSERT INTO unidades_por_defecto (definicion, porcentaje, obligatoria, orden, year_id, created_at)
                    VALUES ("Candado", 100, 0, 1, ?, ?)', [$par->year_id, now()]);

        DB::insert('INSERT INTO subunidades_por_defecto (definicion, porcentaje, unidad_defec_id, obligatoria, orden, created_at)
                    VALUES ("Candado", 100, ?, 0, 1, ?)', [DB::getPdo()->lastInsertId(), now()]);

        $ruta = "/api/unidades/de-asignatura-periodo/{$par->asignatura_id}/{$par->periodo_id}";

        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            $antes = DB::table('unidades')->count();

            $this->getJson($ruta, $cab)->assertStatus(403);

            $this->assertSame($antes, DB::table('unidades')->count(),
                "El 403 llegó tarde: {$quien} ya había creado unidades con un GET.");
        }
    }

    /**
     * Las papeleras académicas, que no las abre ninguna familia.
     *
     * Cuatro rutas de `academico` se quedaron sin guard, y las cuatro son
     * pantallas de administración cuyas familias enteras ya llevaban
     * `auth.personal`. Dos de ellas **no devuelven lo que dice su nombre**:
     * `subunidades/trashed` y `editnota/trashed` son la misma consulta copiada, y
     * lo que traen son los ALUMNOS BORRADOS del colegio con su documento, su
     * fecha de nacimiento, su celular y su dirección.
     *
     * Aquí solo se comprueba el 403 y no que el dato no salga, al revés que en
     * `noSaleElDato()`, y es a propósito: **el seed no tiene ningún alumno
     * borrado**, así que la comprobación del cuerpo pasaría sin significar nada.
     * Es justo lo que escondió estas dos hasta ahora — el barrido las vio
     * responder `[]` y siguió. Ver 05 §16.
     */
    public static function papelerasAcademicas(): array
    {
        return [
            'la papelera de asignaturas' => ['asignaturas/papelera'],
            'la papelera de unidades' => ['unidades/trashed'],
            'los alumnos borrados, con nombre de subunidades' => ['subunidades/trashed'],
            'los alumnos borrados otra vez, con nombre de editnota' => ['editnota/trashed'],
        ];
    }

    #[DataProvider('papelerasAcademicas')]
    public function test_una_familia_no_abre_las_papeleras_academicas(string $ruta): void
    {
        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            // `assertSame` y no `assertStatus`, que se come el mensaje: sin decir
            // quién de los dos falló, el caso se lee igual para los dos.
            $this->assertSame(403, $this->getJson('/api/'.$ruta, $cab)->getStatusCode(),
                "{$quien} sigue abriendo {$ruta}.");
        }
    }

    /**
     * Pedir una asignatura de otro año era un 500, y es un 404.
     *
     * `Asignatura::detallada` une por `g.year_id`, así que una asignatura que no
     * sea del año desde el que se pregunta no devuelve filas — y el `[0]` de la
     * última línea reventaba. No era un error del servidor: era que esa
     * asignatura no existe en ese año, que es exactamente un 404. Con `APP_DEBUG`
     * puesto, además, el 500 se llevaba la traza dentro.
     *
     * Se pide con un token del personal a propósito: la ruta sigue sin guard de
     * propiedad —está en las diez que esperan decisión, [08](../../docs/migracion/08-revision-idor.md)—
     * y lo que este caso fija es el código, no quién puede pedirla.
     */
    public function test_una_asignatura_de_otro_anio_no_revienta(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $otroAnio = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            WHERE g.year_id <> ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($otroAnio, 'El seed no tiene asignaturas de otro año.');

        $this->getJson("/api/asignaturas/show/{$otroAnio->id}",
            ['Authorization' => 'Bearer '.$token])->assertStatus(404);
    }

    /**
     * Un token de alumno y uno de acudiente, para los casos que valen para los dos.
     *
     * La clave es quién es, porque es lo que sale en el mensaje cuando falla: sin
     * eso, un caso que se rompe solo para el acudiente se lee igual que uno que se
     * rompe para los dos.
     *
     * @return array<string, array<string, string>>
     */
    private function cabecerasDeUnaFamilia(): array
    {
        $familia = [];

        foreach (['un alumno' => 'Alumno', 'un acudiente' => 'Acudiente'] as $quien => $tipo) {
            $familia[$quien] = [
                'Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo($tipo)->username),
            ];
        }

        return $familia;
    }

    /**
     * Los otros dos buscadores, y la comilla que los delataba.
     *
     * `alumnos/personas-check` y `alumnos/documento-check` se cerraron en
     * [05 §11.3]; `buscar/por-nombre` y `buscar/por-apellido` se quedaron abiertos
     * dos días más, y hacen lo mismo: con una letra devolvían a cualquier alumno
     * 49 compañeros con su `alumno_id`, su `user_id` y su grupo. Es «quién reparte
     * las llaves» de [08 §4] otra vez — un buscador recibe `texto_a_buscar` y no
     * un id, así que para el inventario no tiene identificador y no sale en
     * ninguna lista.
     *
     * Y el texto entraba **interpolado en la cadena de la consulta**. Para verlo
     * no hacía falta ningún atacante: basta un alumno que se apellide O'Brien.
     * Por eso el segundo caso busca una comilla — es la misma prueba y es la que
     * se puede escribir sin construir un ataque.
     */
    public function test_una_familia_no_busca_a_los_demas_alumnos(): void
    {
        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            foreach (['buscar/por-nombre', 'buscar/por-apellido'] as $ruta) {
                $this->assertSame(403,
                    $this->putJson('/api/'.$ruta, ['texto_a_buscar' => 'a'], $cab)->getStatusCode(),
                    "{$quien} sigue buscando por {$ruta}.");
            }
        }
    }

    /** Y el personal sigue buscando, también a quien lleve un apóstrofo en el apellido. */
    public function test_el_personal_busca_y_una_comilla_ya_no_revienta(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $this->assertNotSame(403,
            $this->putJson('/api/buscar/por-apellido', ['texto_a_buscar' => 'a'], $cab)->getStatusCode(),
            'El guard está cortando al personal, que es quien usa el buscador.');

        // Sin parametrizar, esto era un 500: la comilla cerraba la cadena.
        $this->putJson('/api/buscar/por-apellido', ['texto_a_buscar' => "o'"], $cab)
            ->assertStatus(200);

        $this->putJson('/api/buscar/por-nombre', ['texto_a_buscar' => "o'"], $cab)
            ->assertStatus(200);

        $this->assertNotNull($grupo);
    }

    /**
     * La cartera del colegio, que no miraba el token ni una vez.
     *
     * Las tres rutas sacan su alcance del CUERPO —`year_id`, `grupo_actual`— o de
     * ningún sitio, y `putAlumnos` tiene el `User::fromToken()` comentado. Un
     * alumno se descargaba el Excel de deudores del colegio.
     *
     * El barrido no las vio por las dos mitades de su límite a la vez: dos piden
     * por el cuerpo, que él manda vacío, y la tercera devuelve un xlsx, cuyos
     * bytes su detector de datos personales no puede leer. Ver 05 §17.
     */
    public function test_una_familia_no_abre_la_cartera(): void
    {
        $casos = [
            ['PUT', 'cartera/solo-deudores', ['year_id' => 8]],
            ['PUT', 'cartera/alumnos', ['grupo_actual' => 84]],
            ['GET', 'cartera/exportar-solo-deudores', []],
        ];

        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            foreach ($casos as [$verbo, $ruta, $cuerpo]) {
                $this->assertSame(403,
                    $this->json($verbo, '/api/'.$ruta, $cuerpo, $cab)->getStatusCode(),
                    "{$quien} sigue abriendo {$ruta}.");
            }
        }
    }

    /**
     * Y lo más caro: quién pasa el año.
     *
     * `PUT promovidos/calcular-grupo` no es un cálculo que se devuelve: **escribe
     * `matriculas.promovido`** de todo el grupo que se nombre en el cuerpo —solo
     * respeta las filas ya marcadas `(manual)`— y de paso devuelve 331 KB con las
     * notas de ese grupo. Un alumno y un acudiente lo hacían sobre un grupo que
     * no es suyo.
     *
     * Se comprueba por el efecto y no por el código, como el resto de escrituras
     * de este archivo: un 403 que llegue después del `UPDATE` no vale de nada.
     */
    public function test_una_familia_no_decide_quien_pasa_el_anio(): void
    {
        $grupo = $this->grupoConAlumnos();

        $antes = DB::table('matriculas')->where('grupo_id', $grupo->id)
            ->orderBy('id')->pluck('promovido', 'id')->all();

        $this->assertNotSame([], $antes, 'El grupo del seed no tiene matrículas que mirar.');

        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            $this->assertSame(403,
                $this->putJson('/api/promovidos/calcular-grupo', ['grupo_id' => $grupo->id], $cab)->getStatusCode(),
                "{$quien} sigue calculando la promoción de un grupo.");

            $this->assertSame($antes,
                DB::table('matriculas')->where('grupo_id', $grupo->id)
                    ->orderBy('id')->pluck('promovido', 'id')->all(),
                "El 403 llegó tarde: {$quien} ya había cambiado quién pasa el año.");
        }
    }

    /**
     * El módulo de votaciones, que estaba abierto casi entero.
     *
     * El patrón es el de [05 §15] otra vez y sin variación: **el guard fue a la
     * ruta que tiene `{id}` en la URL**. `destroy/{id}` lo llevaba en las cinco
     * familias del módulo; `store`, `update` y los listados, no. Con el barrido
     * mandando un cuerpo plausible salió lo que había detrás: un alumno creaba
     * votaciones, creaba y editaba los cargos, inscribía como candidato a
     * cualquier `user_id`, y leía el censo con los datos personales de todos **y
     * a quién votó cada uno**.
     *
     * Qué se cierra y qué no lo decidió el front, no el criterio: `VotarCtrl` es
     * el único estado de `votaciones/*` sin `needed_permissions`, y llama a dos
     * endpoints — `votaciones/en-accion-inscrito` y `votos/store`—. Los demás
     * cuelgan de pantallas con `can_edit_participantes` o `can_edit_candidatos`,
     * o no los llama ningún cliente. Ver 05 §18.
     */
    public static function administracionDeVotaciones(): array
    {
        return [
            'crear una votación' => ['POST', 'votaciones/store'],
            'el directorio de cuentas sin inscribir' => ['GET', 'votaciones/unsignedsusers'],
            'crear un cargo' => ['POST', 'aspiraciones/store'],
            'editar un cargo' => ['PUT', 'aspiraciones/update'],
            'el censo del evento' => ['GET', 'participantes'],
            'todos los inscritos' => ['GET', 'participantes/allinscritos'],
            'los datos del censo' => ['PUT', 'participantes/datos'],
            'guardar inscripciones' => ['PUT', 'participantes/guardar-inscripciones'],
            'inscribir profesores' => ['POST', 'participantes/inscribir-profesores'],
            'bloquear participantes' => ['PUT', 'participantes/set-locked'],
            'el censo con el voto de cada uno' => ['PUT', 'participantes/votantes'],
            'todos los candidatos' => ['GET', 'candidatos'],
            'inscribir un candidato' => ['POST', 'candidatos/store'],
            'todos los votos del colegio' => ['GET', 'votos'],
        ];
    }

    #[DataProvider('administracionDeVotaciones')]
    public function test_una_familia_no_administra_las_votaciones(string $verbo, string $ruta): void
    {
        $cuerpo = [
            'grupo_id' => $this->grupoConAlumnos()->id,
            'votacion_id' => 1, 'aspiracion_id' => 1, 'user_id' => 1, 'id' => 1,
        ];

        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            $this->assertSame(403,
                $this->json($verbo, '/api/'.$ruta, $cuerpo, $cab)->getStatusCode(),
                "{$quien} sigue alcanzando {$ruta}.");
        }
    }

    /**
     * Y lo que hace falta para votar sigue abierto, que es la otra mitad.
     *
     * Cerrar catorce rutas de un módulo sin comprobar esto sería dejar sin
     * elecciones a dieciséis colegios. Se mira `assertNotSame(403)` y no un 200:
     * en el seed no hay ninguna votación en acción, así que varias contestan
     * vacío por motivos suyos, y `candidatos/conaspiraciones` contesta 500 por el
     * suyo, que tiene su propio test aquí abajo. Lo que aquí importa es lo único
     * que este caso puede afirmar: que el guard no las corte.
     */
    public function test_una_familia_sigue_pudiendo_votar(): void
    {
        $abiertas = [
            ['GET', 'votaciones'], ['GET', 'votaciones/actual'],
            ['GET', 'votaciones/actual-in-action'], ['GET', 'votaciones/en-accion-inscrito'],
            ['GET', 'candidatos/conaspiraciones'], ['PUT', 'votos/show'], ['POST', 'votos/store'],
        ];

        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            foreach ($abiertas as [$verbo, $ruta]) {
                $this->assertNotSame(403,
                    $this->json($verbo, '/api/'.$ruta, [], $cab)->getStatusCode(),
                    "El guard está cortando a {$quien} en {$ruta}, que es del flujo de votar.");
            }
        }
    }

    /**
     * La papeleta lleva rota para las familias desde siempre, y aquí queda fijado.
     *
     * `candidatos/conaspiraciones` llama a `VtVotacion::actualInscrito($user)`
     * **en la rama de Alumno y Acudiente, y ese método no existe** —los que hay
     * son `actual`, `actualInAction` y `actualesInscrito`, en plural—. Un alumno
     * que abra la papeleta recibe un 500, y lo ha recibido siempre.
     *
     * No lo encontró el muestreo de la P2, que golpeó una lectura por controlador
     * con un token de verdad ([05 §8]): ésta es una lectura sin parámetros y sí
     * se golpeó, pero **con un token del personal**, y el `else` del personal usa
     * un método que sí existe. Es el mismo tipo de punto ciego que el resto de
     * esta serie — la herramienta preguntaba bien y con un solo tipo de usuario.
     *
     * Se deja roto con la regla de siempre —con ruta y roto se documenta— porque
     * arreglarlo es decidir qué votación es «la suya» cuando hay varias en curso,
     * y de paso encender para los alumnos una pantalla que hoy no funciona en
     * dieciséis colegios. Está en la tabla del 09 §5. Este test fija el error
     * exacto para que el día que se arregle, falle y haya que venir aquí.
     */
    public function test_la_papeleta_de_una_familia_sigue_rota(): void
    {
        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            $this->assertSame(500,
                $this->getJson('/api/candidatos/conaspiraciones', $cab)->getStatusCode(),
                "La papeleta ya no responde 500 a {$quien}: si se ha arreglado, ".
                'actualiza este test y la entrada de 05 §18.');
        }
    }

    /**
     * Una elección de verdad, votada de punta a punta con un token de alumno.
     *
     * Los otros dos casos del módulo comprueban puertas: que catorce respondan
     * 403 y que siete no. **Eso no es lo mismo que votar.** Cerrar catorce rutas
     * de un módulo y comprobarlo leyendo el front es exactamente el error que
     * este archivo lleva evitando desde la P1: el 403 se mira, el resultado no.
     *
     * Así que aquí se monta la elección —votación en acción, un cargo, un
     * candidato, y el grupo del alumno inscrito como participante— y se recorre
     * el camino real de `VotarCtrl`, que son dos llamadas y solo dos:
     *
     *   1. `GET votaciones/en-accion-inscrito`, que es de donde el panel saca las
     *      aspiraciones y, dentro de cada una, sus candidatos. **No** de
     *      `candidatos/conaspiraciones`, que es la pantalla de prueba.
     *   2. `POST votos/store` con el `candidato_id` que salió de ahí.
     *
     * Y se comprueba por el efecto: que la fila esté en `vt_votos` con el
     * `user_id` del alumno. Un 200 no prueba que el voto se haya guardado.
     */
    public function test_un_alumno_vota_de_verdad(): void
    {
        [$votacion, $candidato] = $this->montarUnaEleccionPara('Alumno');

        $yo = $this->usuarioDeTipo('Alumno');
        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($yo->username)];

        $abiertas = $this->getJson('/api/votaciones/en-accion-inscrito', $cab);
        $abiertas->assertStatus(200);

        $lista = $abiertas->json();

        $this->assertNotSame([], $lista,
            'El alumno no ve la votación en la que está inscrito: `en-accion-inscrito` '
            .'es la primera de las dos llamadas de VotarCtrl y sin ella no hay pantalla.');

        $this->assertSame((int) $votacion, (int) $lista[0]['id']);

        $this->assertNotEmpty($lista[0]['aspiraciones'] ?? [],
            'La votación llega sin aspiraciones, así que el panel no pinta nada que votar.');

        $candidatos = $lista[0]['aspiraciones'][0]['candidatos'] ?? [];

        $this->assertNotEmpty($candidatos,
            'La aspiración llega sin candidatos. El panel los saca de aquí, no de '
            .'`candidatos/conaspiraciones`.');

        $this->assertSame((int) $candidato, (int) $candidatos[0]['candidato_id']);

        $antes = DB::table('vt_votos')->where('user_id', $yo->id)->count();

        // 201 y no 200: el método devuelve el modelo recién creado y Laravel le
        // pone el código de creado. Se fija el que da, no el que uno espera.
        $this->postJson('/api/votos/store',
            ['votacion_id' => $votacion, 'candidato_id' => $candidato], $cab)
            ->assertStatus(201);

        $this->assertSame($antes + 1,
            DB::table('vt_votos')->where('user_id', $yo->id)->count(),
            'El 200 llegó sin voto detrás: `votos/store` no guardó nada.');

        $this->assertSame((int) $candidato,
            (int) DB::table('vt_votos')->where('user_id', $yo->id)
                ->orderByDesc('id')->value('candidato_id'));
    }

    /** Y un acudiente, por la misma puerta: `persona.propia` no vive en este módulo. */
    public function test_un_acudiente_tambien_llega_a_la_papeleta(): void
    {
        $this->montarUnaEleccionPara('Acudiente');

        $acudiente = $this->usuarioDeTipo('Acudiente');
        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($acudiente->username)];

        // `actualesInscrito()` solo mira `matriculas` para el tipo Alumno, así que
        // un acudiente no entra en la lista aunque su acudido sí. Lo que este caso
        // fija es lo único que depende de lo que se cerró: que el guard no lo
        // corte. Que la lista le llegue vacía es de antes y no lo cambia esto.
        $this->getJson('/api/votaciones/en-accion-inscrito', $cab)->assertStatus(200);

        // 404 y no 500 desde el barrido de los `::find()` sin `OrFail`
        // (21 ago 2026): el cuerpo va vacío, así que `postStore()` resuelve un
        // `candidato_id` nulo. Antes reventaba leyendo una propiedad de `null`;
        // ahora `VtCandidato::findOrFail()` dice que no existe, que es lo correcto.
        // Lo que este caso fija sigue siendo lo mismo — **que el guard no le corta
        // el paso al acudiente**—, y eso no ha cambiado: 404 es la respuesta del
        // controlador, no del middleware. Ver 14-certificados.md §3.
        $this->postJson('/api/votos/store', [], $cab)->assertStatus(404);
    }

    /**
     * La elección mínima que hace falta para que un alumno pueda votar.
     *
     * Son cuatro filas y las cuatro importan: `actualesInscrito()` exige que la
     * votación sea `actual` **y** `in_action`, y que el grupo del alumno esté en
     * `vt_participantes.grupo_profes_acudientes` —que es una columna de texto con
     * un id de grupo dentro, y por eso `unsignedsusers` está rota (05 §8)—.
     *
     * @return array{0: int, 1: int} el id de la votación y el del candidato
     */
    private function montarUnaEleccionPara(string $tipo): array
    {
        $quien = $this->usuarioDeTipo($tipo);

        $grupo = DB::selectOne('SELECT m.grupo_id, g.year_id FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.year_id = g.year_id
            WHERE u.id = ? LIMIT 1', [$quien->id]);

        if ($grupo === null) {
            $grupo = DB::selectOne('SELECT g.id AS grupo_id, g.year_id FROM grupos g
                WHERE g.deleted_at IS NULL ORDER BY g.id DESC LIMIT 1');
        }

        DB::insert('INSERT INTO vt_votaciones (user_id, year_id, nombre, actual, in_action, locked,
                        votan_profes, votan_acudientes, created_at, updated_at)
                    VALUES (?, ?, "Elección de prueba", 1, 1, 0, 1, 1, ?, ?)',
            [$quien->id, $grupo->year_id, now(), now()]);
        $votacion = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO vt_participantes (grupo_profes_acudientes, votacion_id, locked, created_at, updated_at)
                    VALUES (?, ?, 0, ?, ?)', [(string) $grupo->grupo_id, $votacion, now(), now()]);

        DB::insert('INSERT INTO vt_aspiraciones (votacion_id, aspiracion, abrev, created_at, updated_at)
                    VALUES (?, "Personero", "PER", ?, ?)', [$votacion, now(), now()]);
        $aspiracion = (int) DB::getPdo()->lastInsertId();

        // El candidato tiene que ser alguien con ficha y matrícula, porque
        // `VtCandidato::porAspiracion()` une contra `alumnos` y `matriculas`.
        $otro = DB::selectOne('SELECT a.user_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.user_id IS NOT NULL AND a.user_id <> ? AND a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1', [$grupo->year_id, $quien->id]);

        $this->assertNotNull($otro, 'El seed no tiene otro alumno que pueda ser candidato.');

        DB::insert('INSERT INTO vt_candidatos (user_id, aspiracion_id, plancha, numero, locked, created_at, updated_at)
                    VALUES (?, ?, "A", "1", 0, ?, ?)', [$otro->user_id, $aspiracion, now(), now()]);

        return [$votacion, (int) DB::getPdo()->lastInsertId()];
    }

    /**
     * Un alumno importando alumnos, que es la escritura más grande de la API.
     *
     * `POST importar/algo/{year}` es el importador **vivo** —los otros tres de su
     * familia están rotos con la firma de maatwebsite 2.x ([05 §8])— y no llevaba
     * ningún guard. Medido antes de cerrarlo: un alumno y un acudiente subieron
     * una hoja y la importación **se ejecutó entera** —`estado: completada`, 37
     * filas, `created_by` el del alumno— escribiendo 37 `alumnos`, 37
     * `matriculas`, 44 `acudientes` y 44 `parentescos`.
     *
     * Que el número de alumnos no cambiara es mérito de la idempotencia por
     * documento que se hizo en [09 §1], no del guard: la hoja era un export de
     * los que ya estaban. Una hoja editada crea y modifica lo que diga.
     *
     * **El barrido no podía verlo**, y esta vez no por el cuerpo: `postAlgo`
     * empieza con `if (Request::hasFile('file'))` y el barrido no manda archivos.
     * Es el tercer sabor del mismo límite —primero el cuerpo vacío, luego el
     * `xlsx` que no sabe leer, y ahora el archivo que no sabe mandar.
     *
     * Se comprueba por el efecto: que no quede fila en `importaciones`. Un 403
     * que llegara después de la primera hoja ya habría escrito.
     */
    public function test_una_familia_no_importa_alumnos(): void
    {
        $personal = $this->usuarioDeTipo('Usuario');

        $anio = (int) DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $personal->periodo_id)
            ->value('years.year');

        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$this->tokenDe($personal->username)]);
        $r->assertStatus(200);

        $hoja = tempnam(sys_get_temp_dir(), 'importar').'.xlsx';
        copy($this->archivoDescargado($r), $hoja);

        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            $antes = DB::table('importaciones')->count();

            $this->post("/api/importar/algo/{$anio}",
                ['file' => new UploadedFile($hoja, 'alumnos.xlsx', null, null, true)], $cab)
                ->assertStatus(403);

            $this->assertSame($antes, DB::table('importaciones')->count(),
                "El 403 llegó tarde: {$quien} ya había abierto una importación.");
        }

        @unlink($hoja);
    }

    /**
     * Y el `UPDATE` de los folios, que no mira el token ni una vez.
     *
     * `GET folios/iniciar` no llama a `fromToken()`: numera de golpe todas las
     * matrículas del año actual que no tengan folio. En el seed afecta a cero
     * filas porque todas lo tienen, y por eso el barrido la enseñaba escribiendo
     * sin que se viera el daño — es la misma trampa que `unidades_por_defecto` y
     * que los alumnos borrados de [05 §16.4]. Aquí se comprueba la puerta, que
     * es lo único que este seed puede demostrar, y se deja dicho por qué.
     */
    public function test_una_familia_no_numera_los_folios_del_colegio(): void
    {
        foreach ($this->cabecerasDeUnaFamilia() as $quien => $cab) {
            $this->assertSame(403, $this->getJson('/api/folios/iniciar', $cab)->getStatusCode(),
                "{$quien} sigue numerando los folios del colegio.");
        }
    }

    /**
     * El examen de otro alumno: responderlo por él y darlo por terminado.
     *
     * `mis-actividades/seleccionar-opcion` y `finalizar-actividad` reciben un
     * `actividad_resuelta_id` por el cuerpo y **no comprobaban de quién era**.
     * Medido antes de arreglarlo: un alumno borró la respuesta de otro y escribió
     * la suya —`seleccionar-opcion` hace `DELETE` y luego `INSERT`— y le puso
     * `terminado = 1` al intento del otro, que es cerrarle el examen en mitad de
     * la prueba.
     *
     * **No pueden llevar `auth.personal`**: responder un examen es justo lo que
     * hace un alumno. Y `persona.propia` tampoco sirve, porque el identificador
     * que viaja nombra un intento y no una persona, y el guard recoge los
     * identificadores por su nombre — es el mismo motivo por el que la §13.2
     * necesitó su propio candado. La comprobación va dentro del controlador.
     *
     * El seed no tiene ninguna actividad, así que el caso monta la suya: el
     * examen del profesor, el intento del OTRO alumno, y una respuesta dentro.
     * Ver 05 §20.
     */
    public function test_un_alumno_no_responde_el_examen_de_otro(): void
    {
        [$ajena, $pregunta] = $this->montarElExamenDeOtroAlumno();

        [, , , , $cab] = $this->actores();

        $respuestasAntes = DB::table('ws_respuestas')
            ->where('actividad_resuelta_id', $ajena)->pluck('id')->all();

        $this->putJson('/api/mis-actividades/seleccionar-opcion',
            ['actividad_resuelta_id' => $ajena, 'pregunta_id' => $pregunta, 'tipo_pregunta' => 'U'], $cab)
            ->assertStatus(403);

        $this->assertSame($respuestasAntes,
            DB::table('ws_respuestas')->where('actividad_resuelta_id', $ajena)->pluck('id')->all(),
            'El 403 llegó tarde: la respuesta del otro alumno ya estaba borrada.');

        $this->putJson('/api/mis-actividades/finalizar-actividad',
            ['actividad_resuelta_id' => $ajena], $cab)->assertStatus(403);

        $this->assertSame(0,
            (int) DB::table('ws_actividades_resueltas')->where('id', $ajena)->value('terminado'),
            'El examen del otro alumno quedó cerrado.');
    }

    /** Y el suyo lo sigue respondiendo, que es la otra mitad. */
    public function test_un_alumno_responde_el_suyo(): void
    {
        [, $pregunta, $actividad] = $this->montarElExamenDeOtroAlumno();

        [, $mio, , , $cab] = $this->actores();

        DB::insert('INSERT INTO ws_actividades_resueltas (persona_id, actividad_id, terminado, created_at, updated_at)
                    VALUES (?, ?, 0, ?, ?)', [$mio->id, $actividad, now(), now()]);
        $mia = (int) DB::getPdo()->lastInsertId();

        $this->putJson('/api/mis-actividades/seleccionar-opcion',
            ['actividad_resuelta_id' => $mia, 'pregunta_id' => $pregunta, 'tipo_pregunta' => 'U'], $cab)
            ->assertStatus(201);

        $this->putJson('/api/mis-actividades/finalizar-actividad',
            ['actividad_resuelta_id' => $mia], $cab)->assertStatus(200);

        $this->assertSame(1,
            (int) DB::table('ws_actividades_resueltas')->where('id', $mia)->value('terminado'),
            'El alumno ya no puede terminar su propia actividad.');
    }

    /**
     * Un alumno creando un grupo del colegio, por la ruta que dice «perfiles».
     *
     * `PerfilesController::postStore` **no crea un perfil: crea un `Grupo`**. Es
     * un método que se quedó del copiar y pegar, y no comprueba nada — ni guard en
     * la ruta ni una línea dentro—. Con el año sacado del token, así que el grupo
     * nace en el año en curso del colegio.
     *
     * **Por qué no salió en cuatro pasadas del barrido:** lee
     * `Request::input('titular')['id']`, un array anidado, y el barrido manda
     * `titular_id` plano. El índice sobre `null` lanza, el `catch` de al lado lo
     * convierte en 422 y desde fuera se ve una ruta que no hace nada. Lo señaló el
     * control con superusuario, que es lo que sabe distinguir «no le dejaron» de
     * «no llegó a intentarlo».
     */
    public function test_un_alumno_no_crea_grupos_del_colegio(): void
    {
        [, , , , $cab] = $this->actores();

        $antes = DB::table('grupos')->count();

        $titular = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $grado = DB::selectOne('SELECT id FROM grados WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->postJson('/api/perfiles/store', [
            'nombre' => 'Grupo del alumno',
            'abrev' => 'GDA',
            'titular' => ['id' => $titular->id],
            'grado' => ['id' => $grado->id],
            'valormatricula' => 0,
            'valorpension' => 0,
            'orden' => 99,
            'caritas' => 0,
        ], $cab)->assertStatus(403);

        $this->assertSame($antes, DB::table('grupos')->count(),
            'No nació ningún grupo.');
    }

    /**
     * Y un alumno reescribiendo el tablón del colegio.
     *
     * `publicaciones/guardar-edicion` hace `UPDATE publicaciones ... WHERE id=:id`
     * con el `id` que llega del cuerpo y **sin mirar de quién es la publicación**.
     * No solo el texto: también `para_alumnos`, `para_acudientes`, `para_profes` y
     * `para_administradores`, o sea a quién se le enseña.
     *
     * **Por qué tampoco salió:** sin `publi_para` en el cuerpo, `$para_todos` se
     * queda sin asignar y la petición muere en 500 antes del `UPDATE`. El barrido
     * lo leía como una ruta que no escribe. Es la misma forma que
     * `folios/iniciar`: rota o vacía por fuera, viva en cuanto le llega el cuerpo
     * que espera.
     */
    public function test_un_alumno_no_reescribe_una_publicacion_del_colegio(): void
    {
        [, , , , $cab] = $this->actores();

        $publi = DB::selectOne('SELECT id, contenido FROM publicaciones ORDER BY id LIMIT 1');

        $this->assertNotNull($publi, 'El seed tiene publicaciones; sin una, este test no prueba nada.');

        $this->putJson('/api/publicaciones/guardar-edicion', [
            'id' => $publi->id,
            'publi_para' => 'publi_para_todos',
            'contenido' => 'Reescrita por un alumno',
        ], $cab)->assertStatus(403);

        $this->assertSame($publi->contenido,
            DB::table('publicaciones')->where('id', $publi->id)->value('contenido'),
            'La publicación sigue diciendo lo que decía.');
    }

    /** Y el personal sigue creándolos, que cerrar de más también se nota. */
    public function test_el_personal_sigue_creando_grupos_por_esa_ruta(): void
    {
        $antes = DB::table('grupos')->count();

        $titular = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $grado = DB::selectOne('SELECT id FROM grados WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->postJson('/api/perfiles/store', [
            'nombre' => 'Grupo del personal',
            'abrev' => 'GDP',
            'titular' => ['id' => $titular->id],
            'grado' => ['id' => $grado->id],
            'valormatricula' => 0,
            'valorpension' => 0,
            'orden' => 99,
            'caritas' => 0,
        ], ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username)])
            ->assertStatus(201);

        $this->assertSame($antes + 1, DB::table('grupos')->count());
    }

    /**
     * Y el autor sigue editando la suya, que es lo que el lápiz del front hace.
     *
     * Publica con su token —`putStore` saca el `persona_id` del token, no del
     * cuerpo— y edita eso mismo. Si esto fallara, el arreglo habría apagado el
     * botón de editar de todo el colegio.
     */
    public function test_el_autor_sigue_editando_su_publicacion(): void
    {
        [, , , , $cab] = $this->actores();

        $this->putJson('/api/publicaciones/store', [
            'publi_para' => 'publi_para_todos',
            'contenido' => 'La mía',
        ], $cab)->assertSuccessful();

        $mia = DB::selectOne('SELECT id FROM publicaciones ORDER BY id DESC LIMIT 1');

        $this->putJson('/api/publicaciones/guardar-edicion', [
            'id' => $mia->id,
            'publi_para' => 'publi_para_todos',
            'contenido' => 'La mía, corregida',
        ], $cab)->assertStatus(200);

        $this->assertSame('La mía, corregida',
            DB::table('publicaciones')->where('id', $mia->id)->value('contenido'));
    }

    /**
     * Un alumno creando cuentas de acudiente, con la contraseña puesta a mano.
     *
     * `AcudientesController::postCrearUsuario` no comprueba nada: crea un `User`
     * de tipo `Acudiente` con `Hash::make('123456')` y **reapunta
     * `acudientes.user_id` a la cuenta nueva**, así que si ese acudiente ya tenía
     * una, se queda fuera y entra la recién hecha — cuya contraseña conoce quien
     * la pidió. Y un acudiente ve lo completo de sus acudidos.
     *
     * Solo lo llaman pantallas de personal —`AlumnosCtrl` y `PrematriculasCtrl`,
     * el botón «Crear su usuario (aún no tiene)»—, así que `auth.personal` es lo
     * que ya decía el front.
     *
     * **Por qué no salió en cinco pasadas:** lee `Request::input('acudiente')`
     * como array —`$acu['nombres']`— y el barrido manda `acudiente_id` plano. El
     * índice sobre `null` lanza y la ruta responde 500, que desde fuera es una que
     * no hace nada. Igual que `perfiles/store`.
     */
    public function test_un_alumno_no_crea_cuentas_de_acudiente(): void
    {
        [, , , , $cab] = $this->actores();

        $acudiente = DB::selectOne('SELECT id, nombres, sexo, user_id FROM acudientes
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $antes = DB::table('users')->count();

        $this->postJson('/api/acudientes/crear-usuario', [
            'acudiente' => [
                'id' => $acudiente->id,
                'nombres' => $acudiente->nombres,
                'sexo' => $acudiente->sexo,
            ],
        ], $cab)->assertStatus(403);

        $this->assertSame($antes, DB::table('users')->count(),
            'No nació ninguna cuenta.');

        $this->assertSame($acudiente->user_id,
            DB::table('acudientes')->where('id', $acudiente->id)->value('user_id'),
            'Y el acudiente sigue apuntando a la cuenta que tenía.');
    }

    /**
     * Los acudientes de un grupo entero, con su documento y su teléfono.
     *
     * `acudientes/datos` recibe `grupo_actual` por el cuerpo y devuelve **todos
     * los acudientes de ese grupo** con `documento`, `telefono`, `celular`,
     * `email`, `direccion`, `barrio` y `fecha_nac`, más los alumnos de cada uno.
     * La consulta filtra por grupo y **no por año**, así que sirve cualquier grupo
     * del colegio. Es la pantalla del personal —sus `columnDefs` traen el botón de
     * resetear contraseña—, y no llevaba guard.
     *
     * **Por qué no salió:** el barrido manda `grupo_actual` como número y el
     * controlador hace `$grupo_actual['id']`. El índice sobre un int lanza y la
     * ruta responde 500. Tercera de la misma pasada que se escondía tras un array
     * anidado, después de `perfiles/store` y `acudientes/crear-usuario`.
     */
    public function test_un_alumno_no_ve_los_acudientes_de_un_grupo(): void
    {
        // El sujeto NO puede ser «el primer alumno»: 56 de los 68 del seed están
        // matriculados en los dos grupos —el del año pasado y el de éste—, así que
        // para ellos no existe ningún grupo ajeno y el test pasaría sin probar
        // nada. Es el mismo cuidado que se lleva `sujetoDeBarrido()`, y la razón
        // por la que 36 rutas estuvieron sin medirse hasta la §16.
        $quien = DB::selectOne('SELECT u.username, sus.grupo_id FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN (SELECT alumno_id, MIN(grupo_id) AS grupo_id, COUNT(DISTINCT grupo_id) AS grupos
                        FROM matriculas WHERE deleted_at IS NULL GROUP BY alumno_id) sus
                ON sus.alumno_id = a.id AND sus.grupos = 1
            WHERE u.tipo = "Alumno" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $ajeno = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL AND id <> ?
            ORDER BY id LIMIT 1', [$quien->grupo_id]);

        $this->assertNotNull($ajeno, 'Sin un grupo ajeno este test no prueba nada.');

        $r = $this->putJson('/api/acudientes/datos', ['grupo_actual' => ['id' => $ajeno->id]],
            ['Authorization' => 'Bearer '.$this->tokenDe($quien->username)]);

        $r->assertStatus(403);

        // Y el efecto, que es lo que importa: ni un documento ajeno en la
        // respuesta. Antes salían los de todos los acudientes del grupo.
        $this->assertStringNotContainsString('"documento"', (string) $r->getContent());
    }

    /** Y el personal sigue viendo la rejilla, que es su pantalla de siempre. */
    public function test_el_personal_sigue_viendo_los_acudientes_de_un_grupo(): void
    {
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $r = $this->putJson('/api/acudientes/datos', ['grupo_actual' => ['id' => $grupo->id]],
            ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username)]);

        $r->assertStatus(200);

        $this->assertNotSame([], $r->json('acudientes'),
            'La rejilla sigue trayendo acudientes; si no, el guard cerró de más.');
    }

    /** Y sigue creando la cuenta del acudiente que no tiene, que es el botón. */
    public function test_el_personal_sigue_creando_la_cuenta_de_un_acudiente(): void
    {
        $acudiente = DB::selectOne('SELECT id, nombres, sexo FROM acudientes
            WHERE user_id IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($acudiente, 'El seed tiene acudientes sin cuenta; sin uno esto no prueba nada.');

        $antes = DB::table('users')->count();

        $this->postJson('/api/acudientes/crear-usuario', [
            'acudiente' => ['id' => $acudiente->id, 'nombres' => $acudiente->nombres, 'sexo' => $acudiente->sexo],
        ], ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username)])
            ->assertStatus(201);

        $this->assertSame($antes + 1, DB::table('users')->count());
    }

    /**
     * Y los alumnos del grupo entero, por la cuarta hermana.
     *
     * `matriculas/alumnos-grado-anterior` devuelve el grupo que le nombren con
     * `fecha_nac`, `celular`, `direccion` y `religion` — 24 KB con un token de
     * alumno—. Sus tres hermanas llevan `auth.personal` desde siempre:
     * `matriculas/alumnos-con-grado-anterior` y las dos de `prematriculas`. Ésta
     * se quedó fuera, y el candado de la §17 no la vio porque comprueba que no
     * quede una sola sin guard en su familia, y la familia `matriculas` tiene
     * muchas con él.
     *
     * **Por qué no salió antes:** lee `$grupo_actual['id']`, y hasta esta pasada
     * el barrido mandaba `grupo_actual` como número. Con las dos formas del cuerpo
     * apareció a la primera.
     */
    public function test_un_alumno_no_ve_los_alumnos_de_otro_grupo_por_matriculas(): void
    {
        $quien = DB::selectOne('SELECT u.username, sus.grupo_id FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN (SELECT alumno_id, MIN(grupo_id) AS grupo_id, COUNT(DISTINCT grupo_id) AS grupos
                        FROM matriculas WHERE deleted_at IS NULL GROUP BY alumno_id) sus
                ON sus.alumno_id = a.id AND sus.grupos = 1
            WHERE u.tipo = "Alumno" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $ajeno = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL AND id <> ?
            ORDER BY id LIMIT 1', [$quien->grupo_id]);

        $r = $this->putJson('/api/matriculas/alumnos-grado-anterior',
            ['grupo_actual' => ['id' => $ajeno->id]],
            ['Authorization' => 'Bearer '.$this->tokenDe($quien->username)]);

        $r->assertStatus(403);

        $this->assertStringNotContainsString('"celular"', (string) $r->getContent());
    }

    /**
     * La pantalla de corregir del profesor, con las respuestas de todo el grupo.
     *
     * `respuestas/actividad` recibe `actividad_id` por el cuerpo y devuelve, para
     * cada grupo al que se compartió, **todos sus alumnos con lo que contestaron**:
     * nombres, foto, si terminaron, `puntaje_manual` y la respuesta a cada
     * pregunta. Es la pantalla `panel.respuestas` del front, a la que se llega
     * desde Actividades — y `actividades/datos`, que es la que abre Actividades,
     * lleva `auth.personal` desde siempre. Ésta no.
     *
     * **Por qué el barrido no podía decirlo:** `ws_actividades` está vacía en el
     * seed, así que ni el alumno ni el superusuario del control sacaban nada. Es
     * la sexta vez que ese vacío tapa algo, y aquí se resuelve montando la
     * actividad — que es la regla que quedó escrita en 03-tests.md: si falta la
     * fila, la monta el test que la necesita.
     *
     * Y la otra rama del método está rota desde siempre: para una actividad NO
     * compartida hace `DB::select('')` con la consulta vacía, así que responde
     * 500. Queda en 05 §24.
     */
    public function test_un_alumno_no_ve_las_respuestas_de_todo_un_grupo(): void
    {
        [$actividad, $grupo] = $this->montarUnaActividadCompartida();

        [, , , , $cab] = $this->actores();

        $r = $this->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad], $cab);

        $r->assertStatus(403);

        $this->assertStringNotContainsString('"puntaje_manual"', (string) $r->getContent(),
            'Ni una respuesta ajena en el cuerpo.');

        // Y el profesor sigue corrigiendo, que es de quien es la pantalla.
        $this->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad],
            ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username)])
            ->assertStatus(200);

        $this->assertNotSame(0, $grupo, 'La actividad se compartió con un grupo de verdad.');
    }

    /**
     * Y la otra rama del mismo método, que está rota desde siempre.
     *
     * Para una actividad **no compartida** —que son la mayoría: `compartida`
     * viene a 0 por defecto— el `else` hace `$consulta = '';` y a continuación
     * `DB::select($consulta, ...)`. Una consulta vacía no es SQL, así que el
     * profesor que abra «Ver resultados» de cualquier actividad normal recibe un
     * 500 desde que existe la pantalla.
     *
     * Es la familia de la §8 —SQL que no puede ejecutarse— y se queda: tiene ruta,
     * y qué debe devolver esa pantalla para una actividad de un solo grupo es una
     * decisión del colegio, no un arreglo. El test fija el error exacto para que
     * el día que se arregle se note. Ver 05 §24.
     */
    public function test_ver_resultados_de_una_actividad_no_compartida_sigue_rota(): void
    {
        $actividad = $this->montarUnaActividadNoCompartida();

        $this->putJson('/api/respuestas/actividad', ['actividad_id' => $actividad],
            ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username)])
            ->assertStatus(500);
    }

    /** Una actividad del profesor sin compartir, que es el caso normal. */
    private function montarUnaActividadNoCompartida(): int
    {
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        DB::insert('INSERT INTO ws_actividades (asignatura_id, periodo_id, descripcion, tipo, compartida,
                        can_upload, in_action, duracion_preg, duracion_exam, oportunidades, one_by_one,
                        contenido, created_at, updated_at)
                    VALUES (?, ?, "Sin compartir", "E", 0, 0, 1, 60, 3600, 1, 0, "", ?, ?)',
            [$asignatura->id, $periodo->id, now(), now()]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Una actividad compartida con un grupo, y el intento de un alumno dentro.
     *
     * @return array{0: int, 1: int} actividad y grupo
     */
    private function montarUnaActividadCompartida(): array
    {
        $grupo = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$grupo->id]);
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $otro = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id]);

        DB::insert('INSERT INTO ws_actividades (asignatura_id, periodo_id, descripcion, tipo, compartida,
                        para_alumnos, can_upload, in_action, duracion_preg, duracion_exam, oportunidades,
                        one_by_one, contenido, created_at, updated_at)
                    VALUES (?, ?, "Compartida", "E", 1, 1, 0, 1, 60, 3600, 1, 0, "", ?, ?)',
            [$asignatura->id ?? 0, $periodo->id, now(), now()]);
        $actividad = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO ws_actividades_compartidas (actividad_id, grupo_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?)', [$actividad, $grupo->id, now(), now()]);

        DB::insert('INSERT INTO ws_actividades_resueltas (persona_id, actividad_id, terminado,
                        is_puntaje_manual, puntaje_manual, created_at, updated_at)
                    VALUES (?, ?, 1, 1, 45, ?, ?)', [$otro->id ?? 0, $actividad, now(), now()]);

        return [$actividad, (int) $grupo->id];
    }

    /**
     * Un examen del profesor y el intento de OTRO alumno, con una respuesta dentro.
     *
     * El seed no trae ninguna actividad —`ws_actividades` está vacía—, y por eso
     * el barrido pasó por estas dos sin poder decir nada: golpeaba con un
     * `actividad_resuelta_id` que no existía. Es la quinta vez que el seed vacío
     * tapa un hallazgo.
     *
     * @return array{0: int, 1: int, 2: int} intento ajeno, pregunta y actividad
     */
    private function montarElExamenDeOtroAlumno(): array
    {
        [, $mio, $otro] = $this->actores();

        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        DB::insert('INSERT INTO ws_actividades (asignatura_id, periodo_id, descripcion, tipo, compartida,
                        can_upload, in_action, duracion_preg, duracion_exam, oportunidades, one_by_one,
                        contenido, created_at, updated_at)
                    VALUES (?, ?, "Examen del profesor", "E", 0, 0, 1, 60, 3600, 1, 0, "", ?, ?)',
            [$asignatura->id, $periodo->id, now(), now()]);
        $actividad = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO ws_actividades_resueltas (persona_id, actividad_id, terminado, created_at, updated_at)
                    VALUES (?, ?, 0, ?, ?)', [$otro->id, $actividad, now(), now()]);
        $ajena = (int) DB::getPdo()->lastInsertId();

        $this->assertNotSame((int) $mio->id, (int) $otro->id,
            'El intento tiene que ser de otro alumno para que este caso pruebe algo.');

        DB::insert('INSERT INTO ws_preguntas (actividad_id, enunciado, tipo_pregunta, orden, created_at, updated_at)
                    VALUES (?, "¿?", "U", 1, ?, ?)', [$actividad, now(), now()]);
        $pregunta = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO ws_respuestas (actividad_resuelta_id, pregunta_id, tipo_pregunta, created_at, updated_at)
                    VALUES (?, ?, "U", ?, ?)', [$ajena, $pregunta, now(), now()]);

        return [$ajena, $pregunta, $actividad];
    }
}
