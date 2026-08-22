<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * **Un campo que no se manda no es un campo que no cambia: es un campo que se
 * pisa.** Ver [05 §68](../../docs/migracion/05-codigo-muerto-y-roto.md).
 *
 * `putUpdate` de profesores y de alumnos rellena con valores por defecto lo que el
 * cuerpo no trae, y las pantallas de edición no traen cuatro campos. El más caro
 * es `is_active`: **se pisa a 1**, así que corregirle el teléfono a un docente le
 * devuelve la entrada al sistema.
 *
 * ## Por qué el interruptor y el formulario no se ven entre sí
 *
 * Medido en `myvc_front`, que es lo que decide si el fallo está vivo: **el
 * interruptor de «Activo» no pasa por `update`**. Las tres rejillas que lo pintan
 * —`ProfesoresCtrl:186`, `AlumnosCtrl:675`, `MatriculasCtrl:458`— llaman a
 * `guardar-valor`, una ruta aparte que escribe esa única columna. El formulario de
 * edición no tiene la casilla, así que **no manda `is_active` nunca**, y cada
 * guardado deshace el interruptor sin que quien edita se entere.
 *
 * ## Lo que estos casos NO son
 *
 * No son «arreglar los seis sitios». Son seis, y **sólo dos son el fallo**: los
 * otros cuatro cuelgan de un `new User`, donde nacer activo es lo correcto. El
 * discriminador es `new User` contra `User::find()`, **no** el nombre del método
 * —`putUpdate` también da de alta—. Por eso aquí hay un caso que comprueba que el
 * alta sigue naciendo activa: si alguien «arregla» los seis, ese caso cae.
 */
class CamposQueSePisanTest extends CasoDeContrato
{
    /** Quien alcanza estas dos rutas sin tropezar con ningún guard. */
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /**
     * Un profesor con cuenta, y **la cuenta desactivada**.
     *
     * La condición se **construye** aquí y no se busca: el seed no trae ninguna
     * cuenta apagada, así que un test que la buscara pasaría sin medir nada. Van
     * diez veces que el seed vacío deja verde algo que no mira.
     */
    private function profesorConCuentaApagada(): object
    {
        $profesor = DB::selectOne('SELECT p.id, p.user_id, p.nombres, p.apellidos, p.num_doc,
                u.username, u.email, u.password
            FROM profesores p
            INNER JOIN users u ON u.id = p.user_id
            WHERE p.deleted_at IS NULL AND u.deleted_at IS NULL
            ORDER BY p.id LIMIT 1');

        $this->assertNotNull($profesor, 'El seed necesita un profesor con cuenta.');

        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$profesor->user_id]);

        return $profesor;
    }

    /** El cuerpo de la pantalla vieja: lo que manda `ProfesoresEditCtrl`, sin `is_active`. */
    private function cuerpoDeLaPantallaDeProfesor(object $profesor): array
    {
        return [
            'nombres_profesor' => $profesor->nombres,
            'apellidos_profesor' => $profesor->apellidos,
            'num_doc' => $profesor->num_doc,
            'telefono' => '3001234567',
            'username' => $profesor->username,
            'email2' => $profesor->email,
        ];
    }

    private function estaActiva(int $userId): int
    {
        return (int) DB::selectOne('SELECT is_active FROM users WHERE id = ?', [$userId])->is_active;
    }

    /**
     * El fallo, medido por donde duele: **corregirle el teléfono a un docente le
     * devuelve la entrada al sistema**.
     */
    public function test_editar_un_profesor_no_reactiva_su_cuenta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $profesor = $this->profesorConCuentaApagada();

        $r = $this->withToken($token)->putJson(
            '/api/profesores/update/'.$profesor->id,
            $this->cuerpoDeLaPantallaDeProfesor($profesor)
        );

        $r->assertStatus(200);
        $this->assertSame(0, $this->estaActiva($profesor->user_id),
            'Editar la ficha reactivó la cuenta: `is_active` se pisó con el valor por defecto.');
    }

    /**
     * Y el mismo fallo en la ficha de alumno, que es la que un colegio tiene 1.280
     * veces frente a 47 docentes.
     */
    public function test_editar_un_alumno_no_reactiva_su_cuenta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuentaApagada();

        $r = $this->withToken($token)->putJson(
            '/api/alumnos/update/'.$alumno->id,
            $this->cuerpoDeLaPantallaDeAlumno($alumno)
        );

        $r->assertStatus(200);
        $this->assertSame(0, $this->estaActiva($alumno->user_id),
            'Editar la ficha reactivó la cuenta del alumno.');
    }

    /** Un alumno con cuenta, apagada aquí por lo mismo que el profesor. */
    private function alumnoConCuentaApagada(): object
    {
        $alumno = DB::selectOne('SELECT a.id, a.user_id, a.nombres, a.apellidos, a.documento,
                a.ciudad_nac, a.ciudad_doc, a.tipo_doc, a.email,
                u.username, u.email AS email_cuenta, u.password
            FROM alumnos a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.deleted_at IS NULL AND u.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed necesita un alumno con cuenta.');

        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$alumno->user_id]);

        return $alumno;
    }

    /**
     * El cuerpo de `alumnosEdit.html`: los catálogos van como objeto porque el
     * controlador lee `['id']` de cada uno, y sin ellos escribiría `null` encima.
     */
    private function cuerpoDeLaPantallaDeAlumno(object $alumno, array $extra = []): array
    {
        return array_merge([
            'nombres' => $alumno->nombres,
            'apellidos' => $alumno->apellidos,
            'documento' => $alumno->documento,
            'telefono' => '3001234567',
            'ciudad_nac' => ['id' => $alumno->ciudad_nac],
            'ciudad_doc' => ['id' => $alumno->ciudad_doc],
            'tipo_doc' => ['id' => $alumno->tipo_doc],
            'tipo_sangre' => ['sangre' => 'O+'],
            'username' => $alumno->username,
            'email2' => $alumno->email_cuenta,
            'grupo' => ['id' => null],
        ], $extra);
    }

    /**
     * El otro lado, y es el que impide «arreglarlo» de más: **mandar el valor sigue
     * decidiendo**. Si alguien tapa el fallo ignorando `is_active`, esto cae.
     */
    public function test_mandar_is_active_lo_sigue_decidiendo(): void
    {
        $token = $this->tokenDeSuperusuario();
        $profesor = $this->profesorConCuentaApagada();

        $cuerpo = $this->cuerpoDeLaPantallaDeProfesor($profesor);

        $this->withToken($token)->putJson('/api/profesores/update/'.$profesor->id,
            $cuerpo + ['is_active' => 1])->assertStatus(200);
        $this->assertSame(1, $this->estaActiva($profesor->user_id), 'Mandar 1 tiene que encenderla.');

        $this->olvidarControladores();

        $this->withToken($token)->putJson('/api/profesores/update/'.$profesor->id,
            $cuerpo + ['is_active' => 0])->assertStatus(200);
        $this->assertSame(0, $this->estaActiva($profesor->user_id), 'Mandar 0 tiene que apagarla.');
    }

    /**
     * **Las cuatro altas no se tocan.** `putUpdate` de un profesor que todavía no
     * tiene cuenta la crea con un `new User`, y ahí `is_active = 1` por defecto es
     * lo correcto: una cuenta que nace, nace pudiendo entrar.
     */
    public function test_dar_de_alta_desde_update_sigue_creando_la_cuenta_activa(): void
    {
        $token = $this->tokenDeSuperusuario();

        $profesor = DB::selectOne('SELECT id, nombres, apellidos, num_doc FROM profesores
            WHERE user_id IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1');

        if ($profesor === null) {
            $id = DB::table('profesores')->insertGetId([
                'nombres' => 'Sin', 'apellidos' => 'Cuenta', 'num_doc' => '999888777',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $profesor = DB::selectOne('SELECT id, nombres, apellidos, num_doc FROM profesores WHERE id = ?', [$id]);
        }

        $r = $this->withToken($token)->putJson('/api/profesores/update/'.$profesor->id, [
            'nombres_profesor' => $profesor->nombres,
            'apellidos_profesor' => $profesor->apellidos,
            'num_doc' => $profesor->num_doc,
            'username' => 'nuevo.docente.'.$profesor->id,
            'email2' => 'nuevo.docente@example.com',
        ]);

        $r->assertStatus(200);

        $creado = DB::selectOne('SELECT is_active FROM users WHERE username = ?',
            ['nuevo.docente.'.$profesor->id]);

        $this->assertNotNull($creado, 'El alta tiene que haber creado la cuenta.');
        $this->assertSame(1, (int) $creado->is_active,
            'La cuenta que nace tiene que nacer activa: eso NO es el fallo de la §68.');
    }

    /**
     * El correo de la cuenta y el de la persona son **dos columnas de dos tablas**,
     * y `putUpdate` de profesores escribe `users.email` con lo que venga en
     * `email2`. Si no viene, hoy escribe `null` encima.
     */
    public function test_editar_un_profesor_sin_mandar_email2_no_borra_el_correo_de_la_cuenta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $profesor = $this->profesorConCuentaApagada();

        $cuerpo = $this->cuerpoDeLaPantallaDeProfesor($profesor);
        unset($cuerpo['email2']);

        $this->withToken($token)->putJson('/api/profesores/update/'.$profesor->id, $cuerpo)
            ->assertStatus(200);

        $ahora = DB::selectOne('SELECT email FROM users WHERE id = ?', [$profesor->user_id])->email;

        $this->assertSame($profesor->email, $ahora,
            'Sin `email2` en el cuerpo, el correo de la cuenta se borró.');
    }

    /**
     * La casilla de contraseña de `alumnosEdit.html:229` está atada a `$ctrl.alumno`,
     * que es el objeto entero que se manda. Vaciarla después de escribir en ella
     * manda `password: ''` — y con la condición invertida de
     * `AlumnosController:726`, eso guarda **el hash de la cadena vacía**.
     *
     * Y entrar con la contraseña vacía responde 200: es la §26, otra vez.
     */
    public function test_una_contrasena_vacia_no_se_guarda(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuentaApagada();

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpoDeLaPantallaDeAlumno($alumno, ['password' => '']))
            ->assertStatus(200);

        $ahora = DB::selectOne('SELECT password FROM users WHERE id = ?', [$alumno->user_id])->password;

        $this->assertSame($alumno->password, $ahora,
            'Una contraseña vacía cambió el hash de la cuenta.');
        $this->assertFalse(Hash::check('', $ahora),
            'La cuenta quedó con el hash de la cadena vacía, que es entrar sin contraseña.');
    }

    /**
     * **La ficha de alumno no guardaba nada, y contestaba 422.** Ver 05 §69.
     *
     * `sanarInputAlumno` convierte `ciudad_nac`, `tipo_doc` y `ciudad_doc` de
     * `{id: N}` al número; `putUpdate` volvía a indexar `['id']` encima. Con
     * `error_reporting(-1)` —que lo pone Laravel al arrancar, así que vale también
     * en producción— ese aviso de PHP es una excepción, y el `catch` del método la
     * convertía en «Datos incorrectos».
     *
     * Se comprueba por el resultado y no por el 200: **la ciudad tiene que seguir
     * siendo la que se mandó**. Si alguien «arregla» el 422 escribiendo `null`, este
     * caso lo dice.
     */
    public function test_la_ficha_de_alumno_guarda_de_verdad(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuentaApagada();

        // La condición se construye: el alumno del seed no trae ciudad de nacimiento,
        // y sin ella este caso pasaría sin distinguir el arreglo de escribir null.
        $ciudad = DB::selectOne('SELECT id FROM ciudades ORDER BY id LIMIT 1');
        $this->assertNotNull($ciudad, 'El seed necesita al menos una ciudad.');

        $cuerpo = $this->cuerpoDeLaPantallaDeAlumno($alumno, [
            'ciudad_nac' => ['id' => $ciudad->id],
            'ciudad_doc' => ['id' => $ciudad->id],
            'telefono' => '3009998877',
        ]);

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id, $cuerpo)
            ->assertStatus(200);

        $guardado = DB::selectOne('SELECT ciudad_nac, ciudad_doc, telefono FROM alumnos WHERE id = ?',
            [$alumno->id]);

        $this->assertSame((int) $ciudad->id, (int) $guardado->ciudad_nac,
            'La ciudad de nacimiento no se guardó con el id que se mandó.');
        $this->assertSame((int) $ciudad->id, (int) $guardado->ciudad_doc);
        $this->assertSame('3009998877', $guardado->telefono, 'El teléfono no se guardó.');
    }

    /**
     * El desplegable de grupo de la ficha sólo pone `grupo` en el cuerpo cuando
     * alguien lo toca. Sin él, el `Request::input('grupo')['id']` del final tiraba el
     * mismo 422 **después** de haber escrito la ficha y la cuenta: guardaba y decía
     * que no.
     */
    public function test_guardar_sin_tocar_el_desplegable_de_grupo_no_rompe(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuentaApagada();

        $cuerpo = $this->cuerpoDeLaPantallaDeAlumno($alumno);
        unset($cuerpo['grupo']);

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id, $cuerpo)
            ->assertStatus(200);
    }

    /**
     * Y el otro lado de la condición invertida: **escribir una contraseña de verdad
     * ahora la cambia**.
     *
     * Esto **enciende** una casilla que llevaba años sin hacer nada, y va dicho en el
     * despliegue: quien la use esperaba justo esto —la pantalla la pide dos veces y
     * la verifica—, pero antes de esta tanda no pasaba nada al guardar.
     */
    public function test_una_contrasena_de_verdad_cambia_el_hash(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuentaApagada();

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpoDeLaPantallaDeAlumno($alumno, ['password' => 'algoDeVerdad']))
            ->assertStatus(200);

        $ahora = DB::selectOne('SELECT password FROM users WHERE id = ?', [$alumno->user_id])->password;

        $this->assertNotSame($alumno->password, $ahora, 'La contraseña no cambió.');
        $this->assertTrue(Hash::check('algoDeVerdad', $ahora),
            'La contraseña guardada no es la que se mandó.');
    }

    /**
     * El correo de la cuenta de un alumno, que es el caso donde **sí** hay quien lo
     * pise sin que el cliente mande nada: `sanarInputUser` rellena `email2` con el
     * correo de la **persona** cuando no viene `email1` —y `email1` no lo manda
     * ningún cliente; su única función es desactivar ese apaño—. 05 §68.3.
     */
    public function test_editar_un_alumno_no_muda_el_correo_de_la_cuenta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConCuentaApagada();

        $cuerpo = $this->cuerpoDeLaPantallaDeAlumno($alumno, ['email' => 'correo.de.la.persona@example.com']);
        unset($cuerpo['email2']);

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id, $cuerpo)
            ->assertStatus(200);

        $ahora = DB::selectOne('SELECT email FROM users WHERE id = ?', [$alumno->user_id])->email;

        $this->assertSame($alumno->email_cuenta, $ahora,
            'El correo de la cuenta se sustituyó por el de la persona.');
    }

    /**
     * El alta comparte el mismo `Request::input('grupo')['id']`, y ahí el 422 llegaba
     * **después de crear el alumno**: quedaba creado y la pantalla decía que no.
     *
     * Un alta sin grupo es un alumno sin matrícula —lo dice el propio `$grupo_id =
     * false` de al lado—, así que lo que se comprueba es que responde y que el alumno
     * existe, no que haya matrícula.
     */
    public function test_un_alta_sin_grupo_no_contesta_que_fallo(): void
    {
        $token = $this->tokenDeSuperusuario();

        $documento = '90'.random_int(1000000, 9999999);

        $r = $this->withToken($token)->postJson('/api/alumnos/store', [
            'nombres' => 'Alta', 'apellidos' => 'Sin Grupo',
            'sexo' => 'M', 'fecha_nac' => '2010-05-04',
            'documento' => $documento,
            'tipo_sangre' => ['sangre' => 'O+'],
            'username' => 'alta.sin.grupo.'.$documento,
        ]);

        // 201, que es lo que ya contestaba el alta cuando llegaba al final.
        $r->assertStatus(201);

        $creado = DB::selectOne('SELECT id FROM alumnos WHERE documento = ?', [$documento]);
        $this->assertNotNull($creado, 'El alta tiene que haber creado al alumno.');
    }
}
