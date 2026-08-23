<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §115.1 — El calendario decide qué te enseña con una bandera que mandas tú.
 *
 * `CalendarioController::putThisYear()`, entero:
 *
 *     $is_prof_admin = Request::input('is_prof_admin');
 *     if ($is_prof_admin == 'true') {
 *         $eventos = DB::select('SELECT * FROM calendario WHERE deleted_at is null');
 *     } else {
 *         $eventos = DB::select('SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null');
 *     }
 *
 * **La columna `solo_profes` es el interruptor con el que el colegio marca un
 * evento como interno, y quien decide si se aplica es el que pregunta.** Un
 * alumno que mande `is_prof_admin=true` recibe los 37 eventos que el colegio
 * marcó para el personal. No hay guard: la ruta lleva `auth.token` a secas.
 *
 * ## Cómo salió, que es el motivo de que esté en el lote J
 *
 * La ruta **ya estaba cubierta** por
 * `MuestreoDeLecturasConContextoTest::test_el_calendario_del_year`, que fija la
 * forma de la respuesta y pasa en verde. Nadie preguntó **de quién** son los
 * eventos que devuelve. Es la pregunta del lote J en una línea:
 *
 * > Un test que fija un 200 no dice que la ruta esté bien: dice que alguien miró
 * > otra cosa.
 *
 * Y no la vio ninguno de los dos candados de `AutorizacionTest`: `calendario/*`
 * tiene 1 de 6 con guard, o sea `$conGuard < 2`, así que **nunca entró** en el
 * candado de familia — ni siquiera está entre las siete que se salen por el
 * umbral (§114). Es el otro lado del mismo `if`.
 *
 * ## Lo que este test hace y lo que no
 *
 * **Fija lo que pasa hoy, no lo arregla.** El arreglo es de una línea —sacar el
 * `is_prof_admin` del token en vez del cuerpo— pero cambia lo que reciben los
 * cuatro clientes en una pantalla que todos abren, y `calendario/*` no es de
 * ningún lote de esta noche. Va anotado para quien coordina.
 *
 * Si alguien lo arregla, este test cae, y lo que hay que hacer es cambiar el
 * `assertGreaterThan` por la comprobación contraria. Que caiga es el aviso.
 */
class CalendarioSoloProfesTest extends CasoDeContrato
{
    /**
     * Un alumno que dice ser profesor recibe los eventos internos.
     *
     * Se comparan **las dos respuestas del mismo token**, y no una contra un
     * número fijo: lo que se afirma es que **la bandera del cuerpo cambia lo que
     * sale**, que es la frase exacta del fallo. Un número fijo mediría el seed.
     */
    public function test_la_bandera_del_cuerpo_le_abre_a_un_alumno_los_eventos_del_personal(): void
    {
        $internos = (int) DB::selectOne('SELECT COUNT(*) n FROM calendario
            WHERE solo_profes = 1 AND deleted_at IS NULL')->n;

        $this->assertGreaterThan(0, $internos,
            'El seed no tiene ningún evento con `solo_profes = 1`: sin eso este test no puede '
            .'distinguir las dos respuestas y pasaría sin comprobar nada.');

        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $comoAlumno = $this->withToken($token)->json('PUT', '/api/calendario/this-year', [])
            ->assertStatus(200)->json();
        $this->olvidarControladores();

        $diciendoQueEsProfe = $this->withToken($token)
            ->json('PUT', '/api/calendario/this-year', ['is_prof_admin' => 'true'])
            ->assertStatus(200)->json();

        $this->assertCount(count($comoAlumno) + $internos, $diciendoQueEsProfe,
            'Ha cambiado lo que devuelve `calendario/this-year` con `is_prof_admin=true`. Si es '
            .'porque se cerró —o sea, porque la bandera ya sale del token y no del cuerpo—, este '
            .'test tiene que pasar a afirmar lo contrario: que las dos respuestas son iguales.');

        // Y la parte que lo hace un hallazgo y no una diferencia de cuentas: los
        // que aparecen de más son EXACTAMENTE los que el colegio marcó como
        // internos. Sin esto, «devuelve más filas» podría ser cualquier cosa.
        $idsInternos = array_column(DB::select('SELECT id FROM calendario
            WHERE solo_profes = 1 AND deleted_at IS NULL ORDER BY id'), 'id');

        $deMas = array_values(array_diff(
            array_column($diciendoQueEsProfe, 'id'),
            array_column($comoAlumno, 'id')
        ));
        sort($deMas);

        $this->assertSame($idsInternos, $deMas,
            'Los eventos que aparecen de más al decir `is_prof_admin=true` ya no son exactamente '
            .'los marcados con `solo_profes = 1`.');
    }

    /**
     * Y sin la bandera, el alumno recibe sólo los públicos.
     *
     * La otra mitad, que es la que dice que el interruptor **sí funciona** cuando
     * nadie miente: `solo_profes` no está roto ni lo ignora nadie —como pasaba
     * con `para_alumnos` en la §74—, funciona exactamente como debe. Lo único que
     * falla es **quién contesta la pregunta de si aplicarlo**.
     *
     * La diferencia importa para decidir el arreglo: aquí no hay que enseñarle a
     * nadie a leer una columna, hay que mover de sitio de dónde sale un booleano.
     */
    public function test_sin_la_bandera_el_interruptor_del_colegio_si_se_respeta(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $eventos = $this->withToken($token)->json('PUT', '/api/calendario/this-year', [])
            ->assertStatus(200)->json();

        $this->assertNotEmpty($eventos, 'El calendario salió vacío: así no se comprueba nada.');

        $internos = array_column(DB::select('SELECT id FROM calendario
            WHERE solo_profes = 1 AND deleted_at IS NULL'), 'id');

        $this->assertSame([], array_values(array_intersect(
            array_column($eventos, 'id'), $internos)),
            'Sin la bandera también se cuela algún evento interno: entonces `solo_profes` no lo '
            .'está aplicando nadie, que es un fallo distinto y peor que el de arriba.');
    }
}
