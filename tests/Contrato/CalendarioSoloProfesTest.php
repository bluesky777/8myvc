<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §115.1 — El calendario decidía qué te enseña con una bandera que mandabas tú.
 * **Cerrado por el §150** la madrugada del 23 ago 2026; este test pasó a afirmar
 * lo contrario, que es lo que su propio docblock pedía.
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
 * tiene **0 de 5** con guard —medido con la misma función que usa el candado; este
 * docblock dijo «1 de 6» hasta el §151—, o sea `$conGuard < 2`, así que **nunca
 * entró** en el candado de familia, ni siquiera entre las siete que se salen por
 * el umbral (§114). Es el otro lado del mismo `if`, y desde el §151 las 18
 * familias que están en esa situación **dejan rastro**:
 * `FamiliasQueNuncaEntranTest`.
 *
 * ## Y cayó, que era el aviso
 *
 * Este test decía: «si alguien lo arregla, este test cae, y lo que hay que hacer
 * es cambiar la comprobación por la contraria». Pasó exactamente eso —el §150— y
 * eso es lo que se ha hecho. **La pareja de casos se conserva entera** en vez de
 * borrar el primero: lo que uno afirmaba y lo que afirma ahora, juntos, es lo que
 * cuenta qué se decidió.
 *
 * El criterio elegido **no es nuevo**: es el `($user->tipo == 'Profesor') ||
 * $user->is_superuser` que ya usaban las otras cuatro rutas del controlador. El
 * otro candidato —«no es alumno ni acudiente»— se descartó **contando cuentas**:
 * habría ampliado el calendario interno a diez cuentas administrativas. Ver
 * docs/migracion/noche-2026-08-23/q.md §150.1.
 *
 * Lo que cubre esta clase es **el caso que lo encontró**. Las cinco mitades del
 * arreglo —profesor, superusuario, administrativo, alumno y acudiente— están en
 * `CalendarioInternoTest`.
 */
class CalendarioSoloProfesTest extends CasoDeContrato
{
    /**
     * **La bandera del cuerpo ya no le abre nada a un alumno.**
     *
     * Es el mismo caso, con la afirmación dada la vuelta: se comparan **las dos
     * respuestas del mismo token** —no una contra un número fijo, que mediría el
     * seed— y ahora tienen que ser **idénticas**.
     *
     * Se conserva la comprobación de los ids además de la de la cuenta: «devuelve
     * lo mismo» por casualidad no es lo mismo que «no se cuela ninguno de los que
     * el colegio marcó», y era esa segunda la que convertía esto en un hallazgo.
     */
    public function test_la_bandera_del_cuerpo_ya_no_le_abre_nada_a_un_alumno(): void
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

        $this->assertCount(count($comoAlumno), $diciendoQueEsProfe,
            'La bandera del cuerpo volvió a cambiar lo que ve un alumno: se deshizo el §150.');

        $idsInternos = array_column(DB::select('SELECT id FROM calendario
            WHERE solo_profes = 1 AND deleted_at IS NULL ORDER BY id'), 'id');

        $this->assertSame([], array_values(array_intersect(
            array_column($diciendoQueEsProfe, 'id'), $idsInternos)),
            'Un alumno recibió eventos marcados `solo_profes = 1` diciendo en el cuerpo que era profesor.');
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
