<?php

namespace Tests\Contrato;

use App\Services\CodigoDeInscripcion;
use Illuminate\Support\Facades\DB;

/**
 * **Las dos rutas del formulario de inscripción impreso.**
 *
 *     POST informes/formularios-inscripcion        acuña
 *     GET  informes/formularios-inscripcion/{lote} NO acuña
 *
 * Lo que este fichero defiende no es que las rutas contesten 200: es que **acuñar
 * un código es irreversible** y que las tres propiedades que hacen útil al código
 * se cumplen de verdad contra la base.
 *
 * Por eso casi todas las pruebas **cuentan filas antes y después**. Un test que
 * mirase sólo la respuesta pasaría igual el día que el `GET` empiece a acuñar en
 * silencio, que es exactamente el fallo que el front nos hizo ver: sin `lote_id`,
 * una impresora atascada cuesta diez códigos y no lo dice nadie.
 */
class FormulariosInscripcionTest extends CasoDeContrato
{
    private const RUTA = '/api/informes/formularios-inscripcion';

    public function test_en_blanco_salen_tantos_codigos_como_se_piden_y_todos_distintos(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $r = $this->withToken($token)->postJson(self::RUTA, [
            'modo' => 'nuevos',
            'cantidad' => 5,
            'cierra' => '31 de octubre de 2026',
        ]);

        $r->assertStatus(200);
        $cuerpo = $r->json();

        $this->assertCount(5, $cuerpo['formularios']);

        $codigos = array_column($cuerpo['formularios'], 'codigo');

        $this->assertCount(5, array_unique($codigos),
            'Dos ejemplares salieron con el mismo código: el papel dejaría de identificar a nadie.');

        foreach ($codigos as $codigo) {
            $this->assertTrue(CodigoDeInscripcion::esValido($codigo),
                "La API acuñó «{$codigo}» y su propio validador lo rechaza.");
        }

        foreach ($cuerpo['formularios'] as $formulario) {
            $this->assertNull($formulario['alumno'],
                'Un formulario en blanco trae alumno: entonces no está en blanco.');
        }

        $this->assertSame('31 de octubre de 2026', $cuerpo['cierra']);
        $this->assertArrayHasKey('colegio', $cuerpo);
    }

    /**
     * **La propiedad que pidió Joseth: un código por alumno y año.**
     *
     * Reimprimir 5°A porque se atascó la impresora tiene que devolver **los mismos
     * códigos y el mismo lote**. Si devolviera otros, el código dejaría de
     * identificar al alumno en cuanto alguien imprime dos veces — y eso es lo único
     * que el código hace.
     */
    public function test_reimprimir_la_renovacion_devuelve_los_mismos_codigos(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $grupo = $this->unGrupoConAlumnos();

        $primera = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'antiguos', 'grupo_id' => $grupo->id])->assertStatus(200)->json();

        $antes = $this->cuantasOrdenes();

        $segunda = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'antiguos', 'grupo_id' => $grupo->id])->assertStatus(200)->json();

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Reimprimir acuñó códigos nuevos. El get-or-create no está funcionando.');

        $this->assertSame($primera['lote_id'], $segunda['lote_id'],
            'La reimpresión dio otro lote, así que el primero dejaría de resolver.');

        $this->assertSame(
            array_column($primera['formularios'], 'codigo'),
            array_column($segunda['formularios'], 'codigo'),
            'La reimpresión dio otros códigos: el papel que tiene la familia deja de valer.');
    }

    public function test_la_renovacion_trae_los_datos_resueltos_a_texto(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $grupo = $this->unGrupoConAlumnos();

        $cuerpo = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'antiguos', 'grupo_id' => $grupo->id])->assertStatus(200)->json();

        $this->assertNotEmpty($cuerpo['formularios']);

        $alumno = $cuerpo['formularios'][0]['alumno'];

        $this->assertNotNull($alumno, 'La renovación salió sin datos: sería un formulario en blanco.');
        $this->assertArrayHasKey('nombres', $alumno);
        $this->assertArrayHasKey('acudientes', $alumno);

        // Lo que de verdad se comprueba: que NO viajan ids donde va texto. Un papel
        // no puede imprimir un `ciudad_id`, y si viajara el id el front tendría que
        // bajarse los catálogos enteros para pintar cuarenta hojas.
        foreach (['tipo_doc', 'ciudad_doc', 'ciudad_nac', 'ciudad_resid'] as $campo) {
            $this->assertArrayHasKey($campo, $alumno);
            $this->assertFalse(is_int($alumno[$campo]),
                "«{$campo}» viaja como id y no como texto: el papel imprimiría un número.");
        }

        $this->assertLessThanOrEqual(2, count($alumno['acudientes']),
            'Salen más de dos acudientes: la hoja no da para eso (23 de 25 cm medidos).');
    }

    /**
     * El `GET` de un lote **no puede escribir nada**. Es su razón de existir.
     */
    public function test_releer_un_lote_no_acuna(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $lote = $this->withToken($token)->postJson(self::RUTA,
            ['modo' => 'nuevos', 'cantidad' => 3])->assertStatus(200)->json();

        $antes = $this->cuantasOrdenes();

        $releido = $this->withToken($token)
            ->getJson(self::RUTA.'/'.$lote['lote_id'])->assertStatus(200)->json();

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Releer un lote acuñó códigos. Es justo lo que esta ruta existe para no hacer.');

        $this->assertSame(
            array_column($lote['formularios'], 'codigo'),
            array_column($releido['formularios'], 'codigo'),
            'El lote releído no trae los mismos códigos que se imprimieron.');
    }

    public function test_un_lote_que_no_existe_es_404(): void
    {
        $this->withToken($this->tokenDelPersonalLlano())
            ->getJson(self::RUTA.'/no-existe-este-lote')
            ->assertStatus(404);
    }

    /**
     * Acuñar es irreversible, así que la cantidad se valida **antes** de escribir.
     */
    public function test_una_cantidad_imposible_es_422_y_no_escribe(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $antes = $this->cuantasOrdenes();

        foreach ([0, -3, 201, 5000, 'muchos', 2.5] as $cantidad) {
            $this->withToken($token)->postJson(self::RUTA,
                ['modo' => 'nuevos', 'cantidad' => $cantidad])
                ->assertStatus(422);
        }

        $this->assertSame($antes, $this->cuantasOrdenes(),
            'Una cantidad rechazada dejó filas escritas.');
    }

    public function test_un_modo_que_no_existe_es_422(): void
    {
        $this->withToken($this->tokenDelPersonalLlano())
            ->postJson(self::RUTA, ['modo' => 'todos', 'cantidad' => 1])
            ->assertStatus(422);
    }

    /**
     * El grupo llega por el cuerpo, así que se comprueba que sea **del año de la
     * sesión**. Sin esto se podría imprimir la renovación de un grupo de hace ocho
     * años, con los alumnos de entonces.
     */
    public function test_un_grupo_de_otro_anio_es_404(): void
    {
        $ajeno = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN years y ON y.id=g.year_id AND y.actual=0 AND y.deleted_at IS NULL
            WHERE g.deleted_at IS NULL LIMIT 1');

        $this->assertNotNull($ajeno, 'El seed no tiene grupos de años no actuales: esto no mediría nada.');

        $this->withToken($this->tokenDelPersonalLlano())
            ->postJson(self::RUTA, ['modo' => 'antiguos', 'grupo_id' => $ajeno->id])
            ->assertStatus(404);
    }

    /**
     * El tope se valida aquí, no en la base.
     *
     * Sin esto, el docker trunca en silencio a 120 y **MariaDB 10.5 en producción
     * aborta**: verde en desarrollo y roto en los dieciséis.
     */
    public function test_una_fecha_limite_larguisima_es_422_y_no_escribe(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $antes = $this->cuantasOrdenes();

        $this->withToken($token)->postJson(self::RUTA, [
            'modo' => 'nuevos',
            'cantidad' => 1,
            'cierra' => str_repeat('x', 121),
        ])->assertStatus(422);

        $this->assertSame($antes, $this->cuantasOrdenes());
    }

    public function test_sin_token_las_dos_son_401(): void
    {
        $this->postJson(self::RUTA, ['modo' => 'nuevos', 'cantidad' => 1])->assertStatus(401);
        $this->getJson(self::RUTA.'/lo-que-sea')->assertStatus(401);
    }

    public function test_un_alumno_no_puede_imprimir_formularios(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->withToken($token)->postJson(self::RUTA, ['modo' => 'nuevos', 'cantidad' => 1])
            ->assertStatus(403);
    }

    private function cuantasOrdenes(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) c FROM ordenes_inscripcion')->c;
    }

    private function unGrupoConAlumnos(): object
    {
        $grupo = DB::selectOne('SELECT g.id
            FROM grupos g
            INNER JOIN years y ON y.id=g.year_id AND y.actual=1 AND y.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id=g.id AND m.deleted_at IS NULL
                AND (m.estado="ASIS" OR m.estado="MATR")
            WHERE g.deleted_at IS NULL
            GROUP BY g.id
            HAVING COUNT(m.id) > 0
            LIMIT 1');

        $this->assertNotNull($grupo,
            'El seed no tiene ningún grupo del año actual con alumnos: nada de esto mediría.');

        return $grupo;
    }
}
