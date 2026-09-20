<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `GET muro/app` — el muro sin lo que la app no pinta.
 *
 * Pedido por `myvc_flutter` (§5 de su `backend-pendiente.md`) y autorizado por
 * Joseth el 19 sep 2026. El porqué de la forma está en `MuroController`; aquí
 * están los cinco fallos que esta clase existe para cazar, y cuatro de ellos
 * son **mudos**.
 *
 * **1. Que vuelva a viajar el calendario.** Es el 99 % de la respuesta y la app
 * no lo lee. Si alguien «unifica» las dos rutas algún día, el ahorro se
 * deshace sin que nada se rompa: la app seguiría funcionando, sólo que cara.
 *
 * **2. Que `horario_version_id` se caiga y quede `horario_hoy` solo.** Es el
 * fallo más caro de los cinco y el más fácil de cometer, porque **la respuesta
 * sigue siendo válida**: con la clave ausente, la app no distingue «este
 * colegio no ha publicado horario» de «hoy no tienes clases» y vuelve a
 * decirle «Hoy no tienes clases» a todos los docentes de los dieciséis, todos
 * los días. Por eso el caso comprueba que viajan **las dos**, no que viaje una.
 *
 * **3. Que se cuelen columnas del acudido.** La consulta vieja trae treinta y
 * tantas —documento, eps, tipo de sangre, dirección, teléfono— y la app lee
 * ocho. Un `SELECT *` aquí no rompería nada visible: publicaría datos
 * personales de un menor en cada apertura de la app.
 *
 * **4. Que se caiga `ausencias_periodo`.** Para un acudiente va colgada de cada
 * acudido y para un alumno en la raíz; son los dos sitios y los dos se miran.
 * Sin ella la pantalla de asistencia sale vacía **en 200**.
 *
 * **5. Que alguien «arregle» la ruta vieja de paso.** El último caso la llama y
 * exige que `eventos` siga ahí: el panel del front web lo pinta, y el trato de
 * esta entrega es que la vieja no se toca.
 */
class MuroParaLaAppTest extends CasoDeContrato
{
    /** Las ocho que lee `AcudidoModel.fromJson`, más las faltas que se le cuelgan. */
    private const CLAVES_DEL_ACUDIDO = [
        'alumno_id', 'nombres', 'apellidos', 'pazysalvo', 'foto_id', 'foto_nombre',
        'nombre_grupo', 'grupo_abrev', 'orden', 'ausencias_periodo',
    ];

    /**
     * Un acudiente **con acudidos en el año en curso**.
     *
     * No vale el primero que devuelva `usuarioDeTipo('Acudiente')`: el del seed
     * puede no tener ninguno, y entonces `alumnos` sale `[]` y los casos de las
     * columnas pasan sin haber mirado una sola fila. Es el mismo «0 encontrados»
     * que no distingue *«revisé y no lo era»* de *«no revisé nada»* — y aquí
     * además lo que no se revisaría son datos personales de un menor.
     */
    private function acudienteConAcudidos(): object
    {
        /*
         * **El sujeto se FABRICA, y eso es la mitad de este test.**
         *
         * Medido en el seed: los **76** acudientes viven en el año `1`, y las
         * matrículas están en los años `7` y `8`. O sea que **no hay ni un
         * acudiente con acudidos**, que es exactamente lo que avisa
         * `24-el-panel-de-inicio.md`: *«el acudiente medido no tiene acudidos en el
         * año en curso, así que sus 8 consultas son el suelo y no el caso normal»*.
         *
         * Con el seed tal cual, `alumnos` sale `[]` y los casos de abajo pasan
         * **sin haber mirado una sola fila**: el «0 encontrados» que no distingue
         * *«revisé y no lo era»* de *«no revisé nada»* — y aquí lo que no se
         * revisaría son datos personales de un menor. Relajar las consultas hasta
         * que encontraran a alguien habría dado el mismo verde con mejor cara.
         *
         * Las tres escrituras van dentro de la transacción del test: el seed no se
         * toca y el de al lado no se entera.
         */
        $periodo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN years y ON y.id = p.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            WHERE p.actual = 1 AND p.deleted_at IS NULL LIMIT 1');

        $this->assertNotNull($periodo, 'El seed no tiene un periodo actual en el año actual.');

        $alumno = DB::selectOne('SELECT a.id
            FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                 AND m.estado IN ("ASIS", "MATR", "PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($alumno,
            'El año actual del seed no tiene ni un alumno matriculado. Sin eso no hay acudido que mirar.');

        $fila = DB::selectOne('SELECT u.*, ac.id AS acudiente_id FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ni un acudiente con ficha.');

        // Al año en curso, que es donde vive un acudiente de verdad.
        DB::table('users')->where('id', $fila->id)->update(['periodo_id' => $periodo->id]);

        DB::table('parentescos')->insert([
            'acudiente_id' => $fila->acudiente_id,
            'alumno_id' => $alumno->id,
            'parentesco' => 'Padre',
            'created_at' => now(),
        ]);

        return $fila;
    }

    #[Test]
    public function test_el_acudiente_recibe_sus_acudidos_y_ni_un_evento(): void
    {
        $acudiente = $this->acudienteConAcudidos();

        $r = $this->withToken($this->tokenDe($acudiente->username))->getJson('/api/muro/app');

        $r->assertStatus(200);

        $this->assertArrayNotHasKey('eventos', $r->json(),
            'Volvió el calendario: son 128 KB que la app tira sin mirar, y es la razón de que esta ruta exista.');

        $this->assertNotEmpty($r->json('alumnos'),
            'Sin acudidos no se está comprobando nada de lo de abajo.');
    }

    #[Test]
    public function test_el_acudido_no_lleva_ni_un_dato_personal_de_mas(): void
    {
        $acudiente = $this->acudienteConAcudidos();

        $r = $this->withToken($this->tokenDe($acudiente->username))->getJson('/api/muro/app');

        $sobran = array_diff(array_keys($r->json('alumnos')[0]), self::CLAVES_DEL_ACUDIDO);

        $this->assertSame([], array_values($sobran),
            'El acudido viaja con columnas que la app no lee: '.implode(', ', $sobran).'. '.
            'No es peso, son datos personales de un menor en cada apertura de la app.');
    }

    #[Test]
    public function test_cada_acudido_trae_sus_faltas(): void
    {
        $acudiente = $this->acudienteConAcudidos();

        $r = $this->withToken($this->tokenDe($acudiente->username))->getJson('/api/muro/app');

        foreach ($r->json('alumnos') as $alumno) {
            $this->assertArrayHasKey('ausencias_periodo', $alumno,
                'Es la única de las siete consultas por acudido cuyo resultado la app sí mira.');
        }
    }

    /**
     * **Las dos claves del horario viajan juntas o no viaja ninguna.**
     *
     * Separadas son exactamente la ambigüedad que `horario_version_id` vino a
     * cerrar, así que el caso comprueba la pareja y no cada una por su lado.
     */
    #[Test]
    public function test_el_docente_recibe_el_horario_con_su_version(): void
    {
        $prof = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($prof, 'El seed no tiene ningún profesor con ficha.');

        $r = $this->withToken($this->tokenDe($prof->username))->getJson('/api/muro/app');

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertArrayHasKey('horario_hoy', $cuerpo);
        $this->assertArrayHasKey('horario_version_id', $cuerpo,
            'Falta el puntero de la versión oficial. Sin él, `horario_hoy: []` vuelve a significar dos '.
            'cosas y la app le dice «Hoy no tienes clases» a todo el mundo todos los días.');
    }

    #[Test]
    public function test_el_alumno_recibe_sus_faltas_en_la_raiz(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $r = $this->withToken($this->tokenDe($alumno->username))->getJson('/api/muro/app');

        $r->assertStatus(200);
        $this->assertArrayHasKey('ausencias_periodo', $r->json(),
            'Para un alumno las faltas van sueltas arriba, no colgadas de un acudido.');
        $this->assertArrayNotHasKey('eventos', $r->json());
    }

    #[Test]
    public function test_sin_token_no_contesta(): void
    {
        $this->getJson('/api/muro/app')->assertStatus(401);
    }

    /**
     * **La ruta vieja no se toca, y esto lo ata.**
     *
     * `ChangesAsked/to-me` le sigue mandando el calendario a quien lo pinta: el
     * panel del front web carga sus eventos justamente de ahí
     * (`AnunciosCtrl.ts:1485`). Si alguien recorta ahí «ya que estamos», a las
     * familias que abren el panel en el navegador les desaparece el calendario
     * y el síntoma aparece en otro repositorio.
     */
    #[Test]
    public function test_la_ruta_vieja_le_sigue_mandando_el_calendario_al_front_web(): void
    {
        $acudiente = $this->acudienteConAcudidos();

        $r = $this->withToken($this->tokenDe($acudiente->username))->getJson('/api/ChangesAsked/to-me');

        $r->assertStatus(200);
        $this->assertArrayHasKey('eventos', $r->json(),
            'Se recortó la ruta vieja. El trato de esta entrega es que no se toca: la pinta el front web.');
    }
}
