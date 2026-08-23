<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §109–110 — De los 143 identificadores del cuerpo sin comprobar, **cuál es el
 * que más pesa**.
 *
 * El lote es «lee y reporta», y una lista sin clasificar es más peligrosa que no
 * tenerla. Después de cruzar el detector con el guard quedan **139 rutas** y
 * **28 familias** de identificador; de ellas, **23 rutas reciben del cuerpo un
 * identificador que nombra a una persona** y no lo comprueba nadie dentro del
 * método. Las 23 llevan `auth.personal`, así que **ninguna familia llega** — la
 * pregunta que queda no es «¿entra un alumno?» sino «¿puede un miembro del
 * personal actuar sobre alguien que no es suyo?».
 *
 * Casi todas caen en la decisión ya tomada: **las 44 rutas de escritura que
 * llevan solo `auth.personal` se dejaron abiertas a propósito** para no dejar
 * fuera a un coordinador sin rol. Se miden y no se cierran.
 *
 * **Una es de otra clase, y es la que este test fija.**
 *
 * `acudientes/seleccionar-parentesco` toma `acudiente_id` y `alumno_id` del
 * cuerpo y escribe la fila de `parentescos` que los une. Esa fila **no es un dato
 * más: es la que decide quién puede ver a quién.** La regla de negocio del
 * sistema —«un acudiente ve lo suyo y lo completo de sus acudidos»— se resuelve
 * mirando `parentescos`, así que **escribir ahí reparte acceso a los datos de un
 * menor**, y es la única de las 143 que hace eso.
 *
 * Y su hermana `quitar-parentesco-alumno` hace lo contrario con un solo id.
 *
 * Se mide desde donde se ve —**lo que el acudiente recibe después**— y no desde
 * la fila. No se cierra: repartir acudientes es trabajo del colegio, y quién del
 * personal puede hacerlo es la misma decisión de las 44. Lo que faltaba era saber
 * que esta ruta no es como las otras 22.
 */
class QuienDecideDeQuienEsUnAlumnoTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** Un acudiente con cuenta y un alumno que **no** es suyo. */
    private function acudienteYUnAlumnoAjeno(): object
    {
        $acudiente = DB::selectOne('SELECT ac.id, u.username FROM acudientes ac
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE ac.deleted_at IS NULL AND u.tipo = "Acudiente" ORDER BY ac.id LIMIT 1');
        $this->assertNotNull($acudiente, 'El seed necesita un acudiente con cuenta.');

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            WHERE a.deleted_at IS NULL
              AND a.id NOT IN (SELECT alumno_id FROM parentescos WHERE acudiente_id = ? AND deleted_at IS NULL)
            ORDER BY a.id LIMIT 1', [$acudiente->id]);
        $this->assertNotNull($ajeno, 'El seed necesita un alumno que no sea de ese acudiente.');

        $acudiente->alumno_ajeno = $ajeno->id;

        return $acudiente;
    }

    /**
     * §109 — Antes de escribir el parentesco, el acudiente no llega al alumno
     * ajeno; después, sí.
     *
     * Es el viaje entero, y es lo único que demuestra qué reparte esta ruta: una
     * llamada de un miembro del personal cualquiera, con dos ids del cuerpo, y el
     * acudiente pasa a poder pedir la ficha de ese alumno.
     */
    public function test_escribir_un_parentesco_le_abre_el_alumno_al_acudiente(): void
    {
        $ctx = $this->acudienteYUnAlumnoAjeno();
        $tokenAcudiente = $this->tokenDe($ctx->username);

        // `persona.propia` es quien lo para, y para antes de entrar al método.
        $this->withToken($tokenAcudiente)->putJson('/api/acudientes/de-persona',
            ['alumno_id' => $ctx->alumno_ajeno])->assertStatus(403);

        $this->olvidarControladores();

        // Y un miembro del personal cualquiera lo cambia con dos ids del cuerpo.
        $this->withToken($this->tokenDelPersonal())->putJson('/api/acudientes/seleccionar-parentesco', [
            'acudiente_id' => $ctx->id,
            'alumno_id' => $ctx->alumno_ajeno,
            'parentesco' => 'Tío',
            'observaciones' => 'Escrito con dos ids del cuerpo',
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('parentescos')
            ->where('acudiente_id', $ctx->id)->where('alumno_id', $ctx->alumno_ajeno)
            ->whereNull('deleted_at')->count());

        $this->olvidarControladores();

        $r = $this->withToken($tokenAcudiente)->putJson('/api/acudientes/de-persona',
            ['alumno_id' => $ctx->alumno_ajeno]);

        $r->assertStatus(200);
        $this->assertNotSame(403, $r->getStatusCode(),
            'La fila de `parentescos` es la que abre la puerta, y la escribe una ruta con dos ids del cuerpo.');
    }

    /** Y quitarla la cierra otra vez: un solo id del cuerpo, sin más preguntas. */
    public function test_quitar_el_parentesco_vuelve_a_cerrarla(): void
    {
        $parentesco = DB::selectOne('SELECT p.id, p.acudiente_id, p.alumno_id, u.username
            FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN periodos pe ON pe.id = u.periodo_id
            WHERE p.deleted_at IS NULL AND u.tipo = "Acudiente" ORDER BY p.id LIMIT 1');
        $this->assertNotNull($parentesco, 'El seed necesita un parentesco con cuenta de acudiente.');

        $tokenAcudiente = $this->tokenDe($parentesco->username);

        $this->withToken($tokenAcudiente)->putJson('/api/acudientes/de-persona',
            ['alumno_id' => $parentesco->alumno_id])->assertStatus(200);

        $this->olvidarControladores();

        $r = $this->withToken($this->tokenDelPersonal())->putJson('/api/acudientes/quitar-parentesco-alumno',
            ['parentesco_id' => $parentesco->id]);
        $r->assertStatus(200);

        $fila = DB::table('parentescos')->where('id', $parentesco->id)->first();
        $this->assertNotNull($fila->deleted_at, 'El borrado es blando.');
        $this->assertNotNull($fila->deleted_by, 'Y queda firmado, que es lo que le falta a otras.');

        $this->olvidarControladores();

        // Y vuelve a estar cerrada: el guard mira `parentescos`, así que quitar la
        // fila le quita el acudido.
        $this->withToken($tokenAcudiente)->putJson('/api/acudientes/de-persona',
            ['alumno_id' => $parentesco->alumno_id])->assertStatus(403);
    }

    /** Una familia no escribe ni borra parentescos: es lo único que ya está cerrado. */
    public function test_una_familia_no_reparte_acudidos(): void
    {
        $ctx = $this->acudienteYUnAlumnoAjeno();
        $antes = DB::table('parentescos')->whereNull('deleted_at')->count();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/acudientes/seleccionar-parentesco', [
                'acudiente_id' => $ctx->id, 'alumno_id' => $ctx->alumno_ajeno, 'parentesco' => 'Tío',
            ])->assertStatus(403);

            $this->withToken($token)->putJson('/api/acudientes/quitar-parentesco-alumno',
                ['parentesco_id' => 1])->assertStatus(403);
        }

        $this->assertSame($antes, DB::table('parentescos')->whereNull('deleted_at')->count());
    }

    /**
     * §110 — `historiales/de-usuario` es la gemela de `bitacoras/{user_id?}`.
     *
     * Mismo `user_id` del cuerpo sin comprobar, mismo `auth.personal`, y **la
     * misma consulta detrás**: `HistorialCalc` es lo que también lee
     * `ChangesAsked/to-me`. Lo que se cerró en la [§88](b.md) fue el borrado de
     * una bitácora; **quién puede leer el rastro de quién sigue abierto en las
     * dos**, y ahora está medido en las dos.
     */
    public function test_cualquiera_del_personal_lee_el_historial_de_otro(): void
    {
        $yo = $this->usuarioDeTipo('Usuario');
        $otro = DB::selectOne('SELECT id FROM users WHERE id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$yo->id]);

        DB::table('historiales')->insert([
            'user_id' => $otro->id, 'tipo' => 'Usuario', 'ip' => '10.0.0.1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/historiales/de-usuario', ['user_id' => $otro->id]);

        $r->assertStatus(200);
        $this->assertNotEmpty($r->json('historial') ?? $r->json(),
            'El rastro de sesiones de otro usuario sale con solo pedirlo por su id.');

        // Y a una familia sí le está cerrado, como en bitácoras.
        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->putJson('/api/historiales/de-usuario', ['user_id' => $otro->id])
                ->assertStatus(403);
        }
    }
}
