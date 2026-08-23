<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La fila que decide quién puede ver a quién, y quién la escribe — §103.
 *
 * `PUT acudientes/seleccionar-parentesco` toma `acudiente_id` y `alumno_id` **del
 * cuerpo** y escribe la fila de `parentescos` que los une. Y esa fila no es un
 * dato de contacto: es **de dónde salen los acudidos** que resuelve
 * `ExigirPersonaPropia`, o sea el permiso de un acudiente sobre los datos de un
 * alumno.
 *
 * Lo midió el lote H desde donde se ve, que es lo que lo convierte en hallazgo y
 * no en una línea de código: **el acudiente recibe 403 pidiendo los datos de ese
 * alumno, alguien del personal hace una llamada con dos ids del cuerpo, y pasa a
 * 200.** De las 143 rutas que reciben un identificador por el cuerpo, es la única
 * cuya consecuencia es **darle a una persona acceso a los datos de otra**.
 *
 * Y estaba a media lista, con solo dos identificadores: ordenar por tamaño no la
 * habría encontrado nunca.
 *
 * **No se cierra aquí.** Lleva `auth.personal`, así que cae dentro de las 44
 * rutas de configuración que Joseth dejó abiertas a propósito —cerrarlas dejaría
 * fuera a un coordinador que hoy las usa y no tiene el rol—. Lo que hace este
 * test es que esa decisión se tome **con el resultado a la vista** en vez de con
 * el nombre del endpoint: no es «cualquier profesor edita un parentesco», es
 * **cualquier profesor le da a un adulto acceso a la ficha de un menor**.
 */
class ParentescoDaAccesoTest extends CasoDeContrato
{
    /**
     * El viaje entero: 403, una llamada de un tercero, 200.
     *
     * Se mide sobre `acudientes/de-persona`, que lleva `persona.propia` y responde
     * con la ficha de los acudientes de un alumno. Da igual cuál se elija —lo que
     * decide es el guard, no el endpoint— pero se comprueba **antes y después con
     * la misma llamada**, porque un 200 suelto no dice de dónde vino el permiso.
     */
    public function test_un_profesor_le_da_a_un_acudiente_acceso_a_un_alumno_ajeno(): void
    {
        $acudiente = $this->usuarioDeTipo('Acudiente');
        $tokenAcudiente = $this->tokenDe($acudiente->username);
        $acudienteId = (int) DB::table('acudientes')->where('user_id', $acudiente->id)->value('id');

        // Un alumno que NO es suyo: la comprobación de que el test mide algo.
        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            WHERE a.deleted_at IS NULL AND a.id NOT IN (
                SELECT p.alumno_id FROM parentescos p WHERE p.acudiente_id = ? AND p.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1', [$acudienteId]);

        $this->assertNotNull($ajeno, 'El seed no tiene ningún alumno ajeno a ese acudiente.');

        // 1. Antes: no es suyo, y el guard lo dice.
        $antes = $this->withToken($tokenAcudiente)->putJson('/api/acudientes/de-persona', [
            'alumno_id' => $ajeno->id,
        ]);

        $this->assertSame(403, $antes->status(),
            'El acudiente ya llegaba a ese alumno antes de emparejarlos: el test no mide nada.');

        // 2. Un tercero cualquiera del personal — ni el acudiente ni la familia —
        //    hace UNA llamada con los dos ids en el cuerpo.
        $profesor = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $r = $this->withToken($profesor)->putJson('/api/acudientes/seleccionar-parentesco', [
            'acudiente_id' => $acudienteId,
            'alumno_id' => $ajeno->id,
            'parentesco' => 'Tío',
        ]);

        $this->assertSame(200, $r->status(),
            'Cambió quién puede emparejar a un acudiente con un alumno — mídelo y actualiza el §103.');

        // 3. Después: la misma llamada del principio, y ahora pasa.
        $despues = $this->withToken($tokenAcudiente)->putJson('/api/acudientes/de-persona', [
            'alumno_id' => $ajeno->id,
        ]);

        $this->assertSame(200, $despues->status(),
            'El parentesco dejó de dar acceso: entonces `ExigirPersonaPropia` cambió de fuente '
            .'y el §103 ya no describe lo que hay.');
    }

    /**
     * Y una familia no puede emparejarse a sí misma con quien quiera.
     *
     * Es la mitad que hace que esto sea una decisión sobre el personal y no un
     * agujero abierto a todos: `auth.personal` cierra la puerta a alumnos y
     * acudientes, así que **nadie se da acceso a sí mismo**. Sin este test, «la
     * ruta que reparte acceso no comprueba nada» se leería mucho peor de lo que
     * es.
     */
    public function test_una_familia_no_se_empareja_a_si_misma(): void
    {
        $acudiente = $this->usuarioDeTipo('Acudiente');
        $acudienteId = (int) DB::table('acudientes')->where('user_id', $acudiente->id)->value('id');

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            WHERE a.deleted_at IS NULL AND a.id NOT IN (
                SELECT p.alumno_id FROM parentescos p WHERE p.acudiente_id = ? AND p.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1', [$acudienteId]);

        foreach (['Acudiente', 'Alumno'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->assertSame(403,
                $this->withToken($token)->putJson('/api/acudientes/seleccionar-parentesco', [
                    'acudiente_id' => $acudienteId,
                    'alumno_id' => $ajeno->id,
                    'parentesco' => 'Tío',
                ])->status(),
                "Un {$tipo} se emparejó con un alumno ajeno — eso sí sería un agujero y no una decisión.");
        }
    }

    /**
     * Deshacer el emparejamiento también quita el acceso, y lo hace cualquiera igual.
     *
     * `acudientes/quitar-parentesco-alumno` es la mitad que devuelve, y va aquí
     * porque **una pareja se mira junta**: si dar acceso lo puede hacer cualquiera
     * del personal y quitarlo no, o al revés, eso es lo que hay que saber antes de
     * decidir. Hoy las dos piden lo mismo.
     */
    public function test_quitar_el_parentesco_devuelve_el_403(): void
    {
        $acudiente = $this->usuarioDeTipo('Acudiente');
        $tokenAcudiente = $this->tokenDe($acudiente->username);
        $acudienteId = (int) DB::table('acudientes')->where('user_id', $acudiente->id)->value('id');
        $profesor = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            WHERE a.deleted_at IS NULL AND a.id NOT IN (
                SELECT p.alumno_id FROM parentescos p WHERE p.acudiente_id = ? AND p.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1', [$acudienteId]);

        $this->withToken($profesor)->putJson('/api/acudientes/seleccionar-parentesco', [
            'acudiente_id' => $acudienteId,
            'alumno_id' => $ajeno->id,
            'parentesco' => 'Tío',
        ])->assertStatus(200);

        $parentescoId = (int) DB::table('parentescos')
            ->where('acudiente_id', $acudienteId)->where('alumno_id', $ajeno->id)
            ->whereNull('deleted_at')->value('id');

        $this->withToken($profesor)->putJson('/api/acudientes/quitar-parentesco-alumno', [
            'parentesco_id' => $parentescoId,
        ])->assertStatus(200);

        $this->assertSame(403,
            $this->withToken($tokenAcudiente)->putJson('/api/acudientes/de-persona', [
                'alumno_id' => $ajeno->id,
            ])->status(),
            'Quitar el parentesco no le quitó el acceso: el guard lee `deleted_at IS NULL`, '
            .'así que si esto falla es que dejó de borrarse en blando.');
    }
}
