<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Quién puede con los alumnos, y qué pasa con la papelera.
 *
 * `AlumnosController` era el mayor hueco de la cobertura que quedaba —8 de 17—
 * y guardaba **siete copias a mano** del criterio que `App\Support\Autoriza`
 * existe para no volver a tener repartido. Estos tests fijan el criterio y el
 * viaje de ida y vuelta de la papelera, que es lo que puede romperse en silencio.
 */
class AlumnosTest extends CasoDeContrato
{
    /** El profesor del seed, con la bandera del año encendida o apagada. */
    private function profesorConBandera(bool $encendida): array
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $this->tokenDe($profesor->username);

        $year = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);
        DB::table('years')->where('id', $year->year_id)
            ->update(['profes_can_edit_alumnos' => $encendida ? 1 : 0]);

        return [$profesor, $this->tokenDe($profesor->username)];
    }

    private function tokenDelSuperusuario(): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        return $this->tokenDe($fila->username);
    }

    private function unAlumno(): object
    {
        return DB::selectOne('SELECT id, nombres, apellidos FROM alumnos
            WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
    }

    /**
     * El criterio vive en `Autoriza` y no copiado en el controlador.
     *
     * Eran siete copias de `($tipo == 'Profesor' && $profes_can_edit_alumnos) ||
     * $is_superuser || Role::isSecretario(...)`. Con la regla en un sitio, la
     * pregunta pendiente de quién es el «Secretario» (09 §5) se contesta en una
     * línea. El test comprueba la regla, no su valor de hoy.
     */
    public function test_el_criterio_de_alumnos_es_el_de_autoriza(): void
    {
        $conBandera = (object) ['tipo' => 'Profesor', 'profes_can_edit_alumnos' => 1, 'user_id' => 0];
        $sinBandera = (object) ['tipo' => 'Profesor', 'profes_can_edit_alumnos' => 0, 'user_id' => 0];
        $superusuario = (object) ['tipo' => 'Usuario', 'is_superuser' => 1, 'user_id' => 0];
        $familia = (object) ['tipo' => 'Alumno', 'is_superuser' => 0, 'user_id' => 0];

        $this->assertTrue(Autoriza::puedeEditarAlumnos($conBandera));
        $this->assertFalse(Autoriza::puedeEditarAlumnos($sinBandera));
        $this->assertTrue(Autoriza::puedeEditarAlumnos($superusuario));
        $this->assertFalse(Autoriza::puedeEditarAlumnos($familia));

        // Y las dos reglas son dos a propósito, aunque hoy digan lo mismo:
        // crear un alumno y borrarlo definitivamente —20 tablas en cascada— son
        // la misma condición por herencia, no por decisión.
        $this->assertSame(
            Autoriza::puedeBorrarAlumnos($conBandera),
            Autoriza::puedeEditarAlumnos($conBandera));
    }

    /** Con la bandera apagada, un profesor no manda alumnos a la papelera. */
    public function test_sin_la_bandera_el_profesor_no_borra_alumnos(): void
    {
        [, $token] = $this->profesorConBandera(false);
        $alumno = $this->unAlumno();

        $this->withToken($token)->deleteJson('/api/alumnos/destroy/'.$alumno->id)
            ->assertStatus(400);

        $this->assertNull(DB::table('alumnos')->where('id', $alumno->id)->value('deleted_at'));
    }

    /** Y con la bandera encendida, sí — y vuelve entero de la papelera. */
    public function test_el_alumno_va_a_la_papelera_y_vuelve(): void
    {
        [, $token] = $this->profesorConBandera(true);
        $alumno = $this->unAlumno();

        $enPapelera = fn () => count($this->withToken($this->tokenDelSuperusuario())
            ->getJson('/api/alumnos/trashed')->json());
        $antes = $enPapelera();

        $this->withToken($token)->deleteJson('/api/alumnos/destroy/'.$alumno->id)
            ->assertStatus(200);

        $this->assertNotNull(DB::table('alumnos')->where('id', $alumno->id)->value('deleted_at'));
        $this->assertSame($antes + 1, $enPapelera());

        $this->withToken($token)->putJson('/api/alumnos/restore/'.$alumno->id, [])
            ->assertStatus(200);

        $this->assertNull(DB::table('alumnos')->where('id', $alumno->id)->value('deleted_at'));
        $this->assertSame($antes, $enPapelera());
    }

    /** Borrar dos veces el mismo no es un 500: es el 400 que ya devolvía. */
    public function test_borrar_un_alumno_que_ya_esta_en_la_papelera_es_400(): void
    {
        [, $token] = $this->profesorConBandera(true);
        $alumno = $this->unAlumno();

        $this->withToken($token)->deleteJson('/api/alumnos/destroy/'.$alumno->id)->assertStatus(200);
        $this->withToken($token)->deleteJson('/api/alumnos/destroy/'.$alumno->id)->assertStatus(400);
    }

    /**
     * Cambiar la clave de un grupo entero la cambia a ese grupo y a nadie más.
     *
     * Es la hermana individual de las masivas de la [§26](05-codigo-muerto-y-roto.md):
     * ésta sí tiene a quién limitarse. Se comprueba con un alumno de otro grupo,
     * que es la mitad que un `UPDATE ... JOIN` mal escrito se lleva por delante.
     */
    public function test_cambiar_las_claves_de_un_grupo_no_toca_a_los_demas(): void
    {
        $token = $this->tokenDelSuperusuario();
        [$grupo] = $this->grupoYPersonal();

        // «De otro grupo» es no tener NINGUNA matrícula en éste, no tener una en
        // otro: un alumno arrastra matrículas de varios años y casi todos tienen
        // las dos cosas. Con el filtro flojo el test acusaba a la consulta de un
        // fallo que era del test.
        $deFuera = DB::selectOne('SELECT u.id, u.password FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            WHERE u.deleted_at IS NULL AND NOT EXISTS (
                SELECT 1 FROM matriculas m2 WHERE m2.alumno_id = a.id AND m2.grupo_id = ?
            ) ORDER BY u.id LIMIT 1', [$grupo->id]);

        $delGrupo = DB::selectOne('SELECT u.id FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ? AND m.deleted_at IS NULL
            WHERE u.deleted_at IS NULL ORDER BY u.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($delGrupo, 'El grupo del seed no tiene alumnos con cuenta.');

        $this->withToken($token)->putJson('/api/alumnos/cambiar-claves',
            ['clave' => 'del-grupo-1234', 'grupo_id' => $grupo->id])->assertStatus(200);

        $this->assertTrue(Hash::check('del-grupo-1234',
            (string) DB::table('users')->where('id', $delGrupo->id)->value('password')));

        if ($deFuera !== null) {
            $this->assertSame($deFuera->password,
                DB::table('users')->where('id', $deFuera->id)->value('password'),
                'Se cambió la clave de un alumno que no es del grupo.');
        }
    }

    /** Una familia no llega a ninguna de las de administración de alumnos. */
    public function test_una_familia_no_administra_alumnos(): void
    {
        $alumno = $this->unAlumno();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            // 400 y no 403: `alumnos/destroy/{id}` es de las pocas de este
            // controlador que **no** llevan `auth.personal`. Lo que la defiende
            // es la condición de dentro, que responde 400 desde siempre.
            $this->withToken($token)->deleteJson('/api/alumnos/destroy/'.$alumno->id)
                ->assertStatus(400);
            $this->withToken($token)->putJson('/api/alumnos/cambiar-claves',
                ['clave' => 'x', 'grupo_id' => 1])->assertStatus(403);
            $this->withToken($token)->getJson('/api/alumnos/trashed')->assertStatus(403);
        }

        $this->assertNull(DB::table('alumnos')->where('id', $alumno->id)->value('deleted_at'));
    }
}
