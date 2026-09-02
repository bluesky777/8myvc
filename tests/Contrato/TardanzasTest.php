<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las seis rutas de Tardanzas, que autentican por el cuerpo y no por token.
 *
 * Son las únicas de la API que llevan `withoutMiddleware('auth.token')` sin ser
 * públicas: el lector es un aparato montado en la puerta del colegio y manda
 * usuario y contraseña en CADA petición. Eso las deja fuera del barrido
 * —que golpea con un token— y fuera de `AutorizacionTest`, que mira guards.
 * Hasta este test nadie había comprobado ninguna de las seis respuestas: eran
 * cinco de los cinco controladores que la medición de cobertura del 20 de agosto
 * dio con cero rutas comprobadas.
 *
 * Lo que fijan estos tests no es la forma de la respuesta sino **quién la
 * consigue**, que es lo que resultó estar mal. Ver
 * docs/migracion/05-codigo-muerto-y-roto.md §25.
 */
class TardanzasTest extends CasoDeContrato
{
    /**
     * Todos los valores de una respuesta, sin importar cómo estén anidados.
     *
     * Se compara contra la respuesta ya decodificada y no contra el texto: en el
     * JSON un hash bcrypt sale con las barras escapadas (`$2y$10$a\/b`), así que
     * buscar el hash en crudo dentro del cuerpo no acierta nunca y el test pasaría
     * estando el hash. Se descubrió comprobando este test al revés.
     */
    private function hojasDe(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [$valor];
        }

        $hojas = [];
        foreach ($valor as $v) {
            $hojas = array_merge($hojas, $this->hojasDe($v));
        }

        return $hojas;
    }

    /** Las credenciales que espera el lector, en el cuerpo de cada petición. */
    private function credencialesDe(string $tipo): array
    {
        return [
            'username' => $this->usuarioDeTipo($tipo)->username,
            'password' => self::CLAVE,
        ];
    }

    public function test_el_lector_deja_entrar_al_personal(): void
    {
        foreach (['Profesor', 'Usuario'] as $tipo) {
            $r = $this->postJson('/api/tardanzas/login', $this->credencialesDe($tipo));

            $r->assertStatus(200);
            $this->assertNotNull($r->json('user_id'), "El lector no reconoció a un {$tipo}.");
            $this->assertNotNull($r->json('periodo_id'));
        }
    }

    /**
     * Un alumno o un acudiente no entra al lector, y antes sí.
     *
     * Los tres métodos verificaban la contraseña y no miraban de quién era. Dos
     * de ellos se rompían solos —su `switch` por tipo no tiene rama para Alumno
     * ni Acudiente, así que reventaban con «Undefined array key 0» en un 500—, y
     * el tercero no tiene `switch` y respondía 200. Se cerraron los tres.
     */
    public function test_un_alumno_o_un_acudiente_no_entra_al_lector(): void
    {
        // `tardanzas/login/traer-datos` se retiró el 2 sep 2026 (decisión de Joseth,
        // ver `TLoginController`). Las otras dos siguen y este test con ellas.
        $rutas = [
            'tardanzas/login',
            'tardanzas/login/traer-datos-ausencias',
        ];

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $credenciales = $this->credencialesDe($tipo);

            foreach ($rutas as $ruta) {
                $this->postJson('/api/'.$ruta, $credenciales)
                    ->assertStatus(403);
            }
        }
    }

    /**
     * El que de verdad entregaba algo, medido.
     *
     * `traer-datos-ausencias` devolvía a cualquier alumno —con su propia clave y
     * sin token— todas las ausencias y tardanzas del colegio: en la base de
     * desarrollo, 801 filas de 51 alumnos distintos. Y el año lo elige el
     * cuerpo, así que tampoco era «el suyo».
     *
     * El test no fija el 801: fija que lo que sale para un alumno no es más que
     * lo suyo. Un número del seed se rompería al regenerarlo y no diría nada.
     */
    public function test_un_alumno_no_saca_las_ausencias_del_colegio(): void
    {
        $credenciales = $this->credencialesDe('Alumno');

        $ausencias = DB::selectOne('SELECT COUNT(*) c FROM ausencias WHERE deleted_at IS NULL');
        $this->assertGreaterThan(0, $ausencias->c,
            'Sin ausencias en la base este test pasaría diciendo que la puerta está cerrada.');

        foreach (DB::select('SELECT id FROM years WHERE deleted_at IS NULL') as $year) {
            $this->postJson('/api/tardanzas/login/traer-datos-ausencias',
                $credenciales + ['year_id' => $year->id])->assertStatus(403);
        }
    }

    /** Y el personal las sigue sacando, que es para lo que existe la ruta. */
    public function test_el_personal_sigue_sacando_las_ausencias(): void
    {
        $r = $this->postJson('/api/tardanzas/login/traer-datos-ausencias',
            $this->credencialesDe('Usuario'));

        $r->assertStatus(200);
        $this->assertIsArray($r->json());
    }

    /**
     * La contraseña en claro ya no es una credencial.
     *
     * El respaldo que se quitó comparaba la columna `password` con el texto
     * tecleado y, si acertaba, la hasheaba en su sitio y dejaba entrar. Se
     * comprueba escribiendo una fila sin hashear a propósito: dentro de la
     * transacción del test, y es la única forma de comprobar que la puerta ya no
     * está —el seed no tiene ninguna.
     */
    public function test_una_columna_sin_hashear_no_deja_entrar(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        DB::table('users')->where('id', $usuario->id)->update(['password' => 'enclaro123']);

        $this->postJson('/api/tardanzas/login', [
            'username' => $usuario->username,
            'password' => 'enclaro123',
        ])->assertStatus(400);

        $this->assertSame('enclaro123',
            DB::table('users')->where('id', $usuario->id)->value('password'),
            'El respaldo que se quitó reescribía la columna al pasar por aquí.');
    }

    /**
     * El lector ya no recibe el hash de la contraseña.
     *
     * Estaba en los cuatro `SELECT` del `switch` y salía en la respuesta de
     * `login` y de `traer-datos`. La §25.4 lo había dejado a propósito, por si el
     * aparato lo necesitara para validar sin red; se fue a mirar el cliente y no
     * lo usa. El test mira **la respuesta entera**, no la clave `password`: si
     * mañana el hash vuelve a salir con otro nombre, esto lo ve igual.
     */
    public function test_el_hash_no_sale_en_la_respuesta(): void
    {
        foreach (['Profesor', 'Usuario'] as $tipo) {
            $hash = DB::table('users')->where('id', $this->usuarioDeTipo($tipo)->id)->value('password');
            $this->assertStringStartsWith('$2', (string) $hash,
                'Si la columna dejara de ser bcrypt, este test pasaría sin comprobar nada.');

            foreach (['tardanzas/login', 'tardanzas/login/traer-datos-ausencias'] as $ruta) {
                $respuesta = $this->postJson('/api/'.$ruta, $this->credencialesDe($tipo))
                    ->assertStatus(200)
                    ->json();

                $this->assertNotContains($hash, $this->hojasDe($respuesta),
                    "{$ruta} sigue devolviendo el hash de un {$tipo}.");
            }
        }
    }

    /** Sin usuario es 401 y con usuario equivocado 400, que es como estaba. */
    public function test_los_dos_codigos_de_credenciales_no_cambian(): void
    {
        $this->postJson('/api/tardanzas/login', [])->assertStatus(401);

        $this->postJson('/api/tardanzas/login', [
            'username' => 'no-existe-nadie-asi',
            'password' => 'x',
        ])->assertStatus(400);
    }

    /**
     * Subir ausencias sigue exigiendo ser del colegio, y sigue siendo un 400.
     *
     * TSubirController ya lo comprobaba; se fija aquí porque es la mitad del
     * módulo y porque el 400 es deliberado: no se cambia a 403 una respuesta que
     * el lector ya recibe hoy en los dieciséis colegios.
     */
    public function test_subir_ausencias_exige_ser_del_colegio(): void
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $ausencia = [
            'alumno_id' => $alumno->id,
            'asignatura_id' => null,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => '07:00',
            'tipo' => 'Ausencia',
            'fecha_hora' => '2026-08-20 07:00:00',
            'periodo_id' => $periodo->id,
        ];

        $this->putJson('/api/tardanzas/subir/poner-ausencia',
            $this->credencialesDe('Alumno') + $ausencia)->assertStatus(400);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c;

        $r = $this->putJson('/api/tardanzas/subir/poner-ausencia',
            $this->credencialesDe('Profesor') + $ausencia);

        $r->assertStatus(200);
        $this->assertSame($antes + 1, DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c,
            'La ausencia del profesor no llegó a escribirse.');
        $this->assertSame((int) $alumno->id, $r->json('alumno_id'));
    }

    /**
     * Subir un lote escribe las altas y las bajas en la misma petición.
     *
     * Es la ruta que usa de verdad el aparato de la puerta: acumula lo del día
     * sin red y lo sube entero. Cada elemento lleva su propio `uploaded`, y
     * `to_delete` significa «esto lo borré en el aparato, bórralo aquí»; los
     * demás son altas. Las dos mitades van en el mismo `foreach`, así que lo que
     * hay que fijar es que **un lote mixto haga las dos cosas** — no una, ni la
     * primera hasta que aparece la otra.
     *
     * La baja se comprueba sobre una falta que **no puso este lote**, porque es
     * lo que pasa de verdad: el aparato borra una tardanza puesta ayer.
     */
    public function test_subir_un_lote_mixto_da_de_alta_y_borra_en_la_misma_peticion(): void
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $vieja = DB::table('ausencias')->insertGetId([
            'alumno_id' => $alumno->id,
            'periodo_id' => $periodo->id,
            'cantidad_ausencia' => 0,
            'cantidad_tardanza' => 1,
            'entrada' => 1,
            'tipo' => 'tardanza',
            'fecha_hora' => '2026-08-21 07:10:00',
            'uploaded' => 'created',
            'created_at' => '2026-08-21 07:10:00',
            'updated_at' => '2026-08-21 07:10:00',
        ]);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM ausencias WHERE deleted_at IS NULL')->c;

        $r = $this->postJson('/api/tardanzas/subir', $this->credencialesDe('Profesor') + [
            'ausencias_to_create' => [
                [
                    'uploaded' => 'to_delete',
                    'id' => $vieja,
                ],
                [
                    'uploaded' => 'created',
                    'alumno_id' => $alumno->id,
                    'asignatura_id' => null,
                    'cantidad_ausencia' => 1,
                    'cantidad_tardanza' => 0,
                    'entrada' => 1,
                    'tipo' => 'ausencia',
                    'fecha_hora' => '2026-08-22 07:00:00',
                    'periodo_id' => $periodo->id,
                ],
            ],
        ]);

        $r->assertStatus(200);
        $this->assertSame('Datos subidos', $r->json('result'),
            'La respuesta del lector es esta cadena y es el contrato con el aparato.');

        $borrada = DB::selectOne('SELECT deleted_at, deleted_by, uploaded FROM ausencias WHERE id = ?', [$vieja]);

        $this->assertNotNull($borrada->deleted_at, 'La baja del lote no llegó a borrarse.');
        $this->assertSame('deleted', $borrada->uploaded);
        $this->assertNotNull($borrada->deleted_by, 'El lector firma sus borrados, y éste salió sin firma.');

        $this->assertSame($antes, DB::selectOne('SELECT COUNT(*) c FROM ausencias WHERE deleted_at IS NULL')->c,
            'El lote mixto no dejó una alta y una baja: se hizo solo una de las dos.');
    }

    /**
     * Subir un lote con una baja que no existe **no revienta y sigue con el resto**.
     *
     * `postIndex` usa `Ausencia::find()` y comprueba el resultado; sus hermanas de
     * `eliminar-ausencia` usan `findOrFail()`. Esa diferencia es correcta y por
     * eso se fija: el aparato sube lo del día entero, y si una fila ya la borró
     * alguien desde la web, un 404 tiraría el lote completo y perdería lo que
     * traía detrás. Es la única ruta del módulo donde tragarse un id que no existe
     * es lo que hay que hacer.
     */
    public function test_una_baja_que_ya_no_existe_no_tira_el_resto_del_lote(): void
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $inexistente = (int) DB::selectOne('SELECT IFNULL(MAX(id),0) + 1000 n FROM ausencias')->n;

        $antes = DB::selectOne('SELECT COUNT(*) c FROM ausencias WHERE deleted_at IS NULL')->c;

        $this->postJson('/api/tardanzas/subir', $this->credencialesDe('Profesor') + [
            'ausencias_to_create' => [
                ['uploaded' => 'to_delete', 'id' => $inexistente],
                [
                    'uploaded' => 'created',
                    'alumno_id' => $alumno->id,
                    'asignatura_id' => null,
                    'cantidad_ausencia' => 0,
                    'cantidad_tardanza' => 1,
                    'entrada' => 1,
                    'tipo' => 'tardanza',
                    'fecha_hora' => '2026-08-22 07:05:00',
                    'periodo_id' => $periodo->id,
                ],
            ],
        ])->assertStatus(200);

        $this->assertSame($antes + 1, DB::selectOne('SELECT COUNT(*) c FROM ausencias WHERE deleted_at IS NULL')->c,
            'La baja fantasma se llevó por delante el alta que venía detrás.');
    }

    /**
     * Borrar una falta por el lector exige ser del colegio, y firma.
     *
     * La mitad que faltaba de `test_subir_ausencias_exige_ser_del_colegio`: allí
     * se comprobó quién puede **poner** una falta sin token, aquí quién puede
     * **quitarla**. Un alumno manda su usuario y su contraseña, que son válidos, y
     * se queda en el 400 de «no tienes permiso» — que es deliberado y no se sube a
     * 403 porque el aparato ya lo recibe hoy en los dieciséis colegios.
     *
     * Y se comprueba que **la falta sigue ahí** después del rechazo, no solo el
     * código: un rechazo que borra antes de contestar es la forma de fallo que
     * este repo lleva persiguiendo desde la §71.
     */
    public function test_el_lector_no_borra_una_falta_para_un_alumno_y_si_para_el_personal(): void
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $id = DB::table('ausencias')->insertGetId([
            'alumno_id' => $alumno->id,
            'periodo_id' => $periodo->id,
            'cantidad_ausencia' => 0,
            'cantidad_tardanza' => 1,
            'entrada' => 1,
            'tipo' => 'tardanza',
            'fecha_hora' => '2026-08-22 07:15:00',
            'uploaded' => 'created',
            'created_at' => '2026-08-22 07:15:00',
            'updated_at' => '2026-08-22 07:15:00',
        ]);

        $this->putJson('/api/tardanzas/subir/eliminar-ausencia',
            $this->credencialesDe('Alumno') + ['ausencia_id' => $id])->assertStatus(400);

        $this->assertNull(DB::selectOne('SELECT deleted_at FROM ausencias WHERE id = ?', [$id])->deleted_at,
            'El rechazo borró la falta antes de contestar que no.');

        $r = $this->putJson('/api/tardanzas/subir/eliminar-ausencia',
            $this->credencialesDe('Profesor') + ['ausencia_id' => $id]);

        $r->assertStatus(200);
        $this->assertSame('Eliminada', $r->getContent(),
            'La respuesta es una cadena pelada y es el contrato con el aparato.');

        $fila = DB::selectOne('SELECT deleted_at, deleted_by, uploaded FROM ausencias WHERE id = ?', [$id]);

        $this->assertNotNull($fila->deleted_at, 'La falta no quedó borrada.');
        $this->assertSame('deleted', $fila->uploaded);
        $this->assertNotNull($fila->deleted_by, 'El lector firma sus borrados, y éste salió sin firma.');
    }
}
