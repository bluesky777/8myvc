<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Lo que alcanza un token cualquiera: las rutas que escriben sin `auth.personal`.
 *
 * El guard va por defecto a toda la API y `auth.personal` se pone ruta a ruta, así
 * que las que llevan sólo `auth.token` las alcanzan **las 2.321 cuentas** — alumnos
 * y acudientes incluidos. El barrido de agosto midió qué **devuelven**; lo que aquí
 * se mide es qué **escriben**, que es la mitad que faltaba.
 *
 * Salen cuatro respuestas distintas y ninguna es un agujero de autorización nuevo:
 * dos rechazan bien, una obedece al token y no al cuerpo, y una —la del correo—
 * hace exactamente lo que le pidan. Ésa es la que importa y está en el
 * [§5 de 09](../../docs/migracion/09-pendientes.md).
 */
class EscriturasConSoloTokenTest extends CasoDeContrato
{
    /**
     * Un alumno cambia la llave de recuperación de otra cuenta, y así le quita la suya.
     *
     * `users.email` no es un dato de perfil: **es la llave de la cuenta**, porque es
     * a donde `postRecuperarClave` manda el enlace (§36.1). Y esta ruta la escribe
     * sin validar nada — ni que sea una dirección, ni que no sea de otro.
     *
     * El test hace **el viaje entero**, que es la única forma de que se vea: el
     * alumno se pone el correo de otra cuenta con id más alto, la dueña pide su
     * reseteo, y el enlace sale **a nombre del alumno**, porque `postRecuperarClave`
     * se queda con la cuenta de id más bajo del grupo. A la dueña le llega a su
     * buzón un enlace que cambia la contraseña de un desconocido, y ella no puede
     * recuperar la suya.
     *
     * No es robo de cuenta: el correo llega al buzón de la víctima, no al del
     * alumno. Es **quitarle la recuperación**, para siempre y a cualquiera con un id
     * más alto que el tuyo. El mecanismo es el mismo que la §13 del 12 documentó
     * como accidente —ocho hermanos con el correo de un padre— con la diferencia de
     * que aquí se puede hacer a propósito.
     *
     * **Joseth decidió el 22 ago 2026 no tocarlo y medirlo**, así que este test fija
     * el agujero, no el arreglo. Lo que hace es que el día que alguien ponga la
     * validación, esto falle y le cuente qué estaba pasando antes — que es lo que un
     * `assertStatus(200)` a secas no diría.
     */
    public function test_un_alumno_se_queda_con_el_correo_de_otra_cuenta_y_con_su_enlace(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($alumno->username);

        // La víctima tiene que tener el id MÁS ALTO: es lo que decide quién se queda
        // el enlace. Con el id más bajo el ataque no hace nada, y el test pasaría
        // sin medir.
        $victima = DB::selectOne('SELECT id, username FROM users
            WHERE id > ? AND deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT 1', [$alumno->id]);

        $this->assertNotNull($victima, 'El seed necesita una cuenta con id mayor que la del alumno.');

        DB::update('UPDATE users SET email = ? WHERE id = ?', ['victima@ejemplo.test', $victima->id]);

        $this->withToken($token)->putJson('/api/perfiles/guardar-mi-email-restore',
            ['email_restore' => 'victima@ejemplo.test'])->assertStatus(200);

        $this->assertSame('victima@ejemplo.test',
            DB::table('users')->where('id', $alumno->id)->value('email'),
            'La ruta dejó de aceptar el correo de otra cuenta: alguien puso la validación del §5.');

        // Y ahora la dueña pide su reseteo.
        config(['app.frontend_url' => 'https://colegio.test/']);
        DB::table('password_reminders')->delete();

        $this->postJson('/api/login/recuperar-clave', ['email' => 'victima@ejemplo.test'])
            ->assertStatus(200);

        $emitido = DB::table('password_reminders')->where('email', 'victima@ejemplo.test')->first();

        $this->assertNotNull($emitido, 'No se emitió ningún enlace: el test dejó de medir el viaje.');
        $this->assertSame($alumno->username, $emitido->username,
            'El enlace ya no sale a nombre del alumno. Si es porque se cerró la ruta del correo, '
            .'hay que reescribir este test; si no, cambió `postRecuperarClave` y hay que mirar por qué.');
    }

    /**
     * Y acepta cualquier cadena, que es de donde salen las 677 sin dirección.
     *
     * El §9 del 12 contó **677 cuentas cuyo «correo no es una dirección»** y las
     * puso en el 91% que no puede recuperar la contraseña. Ésta es una de las vías
     * por las que se llenan: guarda lo que le manden, y vacío lo deja a `NULL`.
     */
    public function test_el_correo_de_la_cuenta_acepta_cualquier_cadena(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($alumno->username);

        $this->withToken($token)->putJson('/api/perfiles/guardar-mi-email-restore',
            ['email_restore' => 'no-es-un-correo'])->assertStatus(200);

        $this->assertSame('no-es-un-correo',
            DB::table('users')->where('id', $alumno->id)->value('email'));

        $this->withToken($token)->putJson('/api/perfiles/guardar-mi-email-restore',
            ['email_restore' => ''])->assertStatus(200);

        $this->assertNull(DB::table('users')->where('id', $alumno->id)->value('email'),
            'Mandar el campo vacío deja la cuenta sin correo, que es sin recuperación.');
    }

    /**
     * Los dos autocompletados los alcanza un alumno, y devuelven el colegio entero.
     *
     * `alumnos/eps-check` y `acudientes/ocupaciones-check` son `LIKE '%texto%'` sobre
     * una columna de todos los alumnos y de todos los acudientes. Con el texto vacío
     * el patrón es `%%` y devuelven la lista completa.
     *
     * Se fija y no se cierra porque lo que devuelven es **`distinct`**: la EPS de
     * alguien no sale ligada a su nombre, sale el conjunto de EPS que usa el colegio.
     * Eso es lo que lo separa de la §34 y lo que hace que quepa esperar una decisión
     * en vez de un arreglo. Lo que el test impide es que dejen de ser `distinct` sin
     * que nadie lo note — el día que alguien añada `a.nombres` a ese `SELECT`, esto
     * falla.
     */
    public function test_un_alumno_saca_la_lista_de_eps_y_de_ocupaciones_del_colegio(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        // La clave de la respuesta y el nombre de la columna no son la misma
        // palabra —`ocupaciones` envuelve filas de `ocupacion`— y darlo por hecho
        // costó un rojo. Van las dos.
        $rutas = [
            ['alumnos/eps-check', 'eps', 'eps'],
            ['acudientes/ocupaciones-check', 'ocupaciones', 'ocupacion'],
        ];

        foreach ($rutas as [$ruta, $clave, $columna]) {
            $r = $this->withToken($token)->putJson('/api/'.$ruta, ['texto' => '']);

            $r->assertStatus(200);

            $filas = $r->json($clave);
            $this->assertNotEmpty($filas, "`{$ruta}` llegó vacía: el test no mediría nada.");

            // Una sola clave por fila, y es la columna del autocompletado. Si algún
            // día salen dos, lo que viaja deja de ser un conjunto y pasa a ser una
            // lista de personas.
            $this->assertSame([$columna], array_keys((array) $filas[0]),
                "`{$ruta}` empezó a devolver más de una columna: deja de ser un `distinct` "
                .'y pasa a ligar el dato con alguien.');
        }
    }

    /**
     * «Guardar valor varios» guarda **uno** cuando lo llama un profesor.
     *
     * Las dos ramas del método hacen lo mismo con una diferencia de una línea: la
     * del administrativo recorre el bucle entero y devuelve al salir; la del
     * profesor **devuelve dentro del bucle**, en la primera vuelta. O sea que de N
     * alumnos guarda el primero, contesta 200 y tira los demás sin decir nada.
     *
     * Es una **mina y no un fallo vivo**: la rama del profesor cuelga de
     * `years.profes_can_edit_alumnos`, que está apagada en los dieciséis colegios
     * (05 §29.1) y cuya decisión Joseth aplazó a después de la migración. Este test
     * la enciende para poder medirla, que es lo único que hace visible una mina
     * antes de que estalle.
     *
     * Se comprueba con **dos alumnos y mirando las dos filas**, no el código de
     * respuesta: con uno solo el fallo no existe, y mirando el 200 no se ve nunca.
     */
    public function test_guardar_valor_varios_guarda_solo_el_primero_para_un_profesor(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        // `persona_id` NO es una columna de `users`: vive en el stdClass que monta
        // `ContextoDeUsuario` (CLAUDE.md). La ficha se liga por `profesores.user_id`.
        $contexto = DB::selectOne('SELECT p.year_id, pr.id AS persona_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($contexto, 'El profesor del seed llegó sin ficha o sin periodo.');

        // La rama pide que el profesor sea **titular** del grupo del alumno, así que
        // se le hace titular de uno del seed en vez de buscar el que ya lo sea: si
        // no hubiera ninguno, el test pasaría por el `else` y no mediría el bucle.
        $grupo = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE g.year_id = ? AND g.deleted_at IS NULL
            GROUP BY g.id HAVING COUNT(*) >= 2 ORDER BY g.id LIMIT 1', [$contexto->year_id]);

        $this->assertNotNull($grupo, 'El seed necesita un grupo con dos alumnos matriculados.');

        DB::update('UPDATE grupos SET titular_id = ? WHERE id = ?', [$contexto->persona_id, $grupo->id]);
        DB::update('UPDATE years SET profes_can_edit_alumnos = 1 WHERE id = ?', [$contexto->year_id]);

        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 2', [$grupo->id]);

        DB::update('UPDATE alumnos SET religion = ? WHERE id IN (?, ?)',
            ['antes', $alumnos[0]->alumno_id, $alumnos[1]->alumno_id]);

        $this->withToken($token)->putJson('/api/alumnos/guardar-valor-varios', [
            'propiedad' => 'religion',
            'valor' => 'despues',
            'alumnos' => [
                ['alumno_id' => $alumnos[0]->alumno_id],
                ['alumno_id' => $alumnos[1]->alumno_id],
            ],
        ])->assertStatus(200);

        $this->assertSame('despues',
            DB::table('alumnos')->where('id', $alumnos[0]->alumno_id)->value('religion'),
            'Ni el primero se guardó: el test dejó de medir lo que quería.');

        $this->assertSame('antes',
            DB::table('alumnos')->where('id', $alumnos[1]->alumno_id)->value('religion'),
            'El segundo alumno SÍ se guardó, o sea que el `return` salió del bucle. Si es un '
            .'arreglo, bienvenido: hay que cambiar este test y contarlo en 05 §79.');
    }

    /**
     * Las tres que sí rechazan, y una que obedece al token en vez de al cuerpo.
     *
     * Se recorren juntas porque el valor de este bloque es negativo: **por aquí no
     * pasa nada**, y saberlo es lo que permite dejar de mirar. `guardar-valor-varios`
     * y `alumnos/forcedelete` comprueban con `Autoriza` dentro del método —por eso
     * su ruta no lleva `auth.personal` y aun así están cerradas— y `mis-acudidos`
     * liga por `$user->persona_id`, no por un id del cuerpo.
     */
    public function test_las_demas_rutas_de_solo_token_no_dejan_pasar_a_un_alumno(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($alumno->username);

        $this->withToken($token)->putJson('/api/alumnos/guardar-valor-varios', ['alumnos' => []])
            ->assertStatus(400);

        $enPapelera = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NOT NULL ORDER BY id LIMIT 1');

        if ($enPapelera === null) {
            $enPapelera = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
            DB::update('UPDATE alumnos SET deleted_at = ? WHERE id = ?', ['2026-08-22 07:00:00', $enPapelera->id]);
        }

        $this->withToken($token)->deleteJson('/api/alumnos/forcedelete/'.$enPapelera->id)
            ->assertStatus(400);

        $this->assertNotNull(DB::table('alumnos')->where('id', $enPapelera->id)->value('id'),
            'El rechazo borró al alumno antes de contestar que no.');

        // Y la lectura de la familia: sale la lista del acudiente del token. Se pide
        // con un cuerpo que trae el id de OTRO acudiente, que es lo que un cliente
        // podría mandar; la ruta no lo mira.
        $acudiente = $this->usuarioDeTipo('Acudiente');
        $otro = DB::selectOne('SELECT id FROM acudientes
            WHERE user_id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$acudiente->id]);

        $r = $this->withToken($this->tokenDe($acudiente->username))
            ->putJson('/api/acudientes/mis-acudidos', ['acudiente_id' => $otro?->id]);

        $r->assertStatus(200);

        $mios = DB::selectOne('SELECT COUNT(DISTINCT p.alumno_id) n FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.user_id = ?
            WHERE p.deleted_at IS NULL', [$acudiente->id])->n;

        $this->assertLessThanOrEqual((int) $mios, count($r->json('alumnos')),
            'Salieron más acudidos de los que tiene: la ruta empezó a leer el id del cuerpo.');
    }
}
