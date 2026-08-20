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
