<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT mis-actividades/datos` con el `alumno_id` de otro.
 *
 * Viene de la **§230 del front**, que midió que con `{"alumno_id": null}` o `0`
 * la ruta contesta **200 con datos de otra asignatura y otro grupo**, y concluyó:
 *
 * > *«No es una fuga: el sustituto es la persona de la sesión, así que nadie ve
 * > datos ajenos — ve los suyos con la etiqueta de otro.»*
 *
 * **La conclusión es correcta y el motivo no.** Y el motivo importa, porque de él
 * depende qué pasa el día que alguien toque esa ruta.
 *
 * ## Lo que la medición del front no podía ver
 *
 * Se midió **con la cuenta `administrador`**, y `ExigirPersonaPropia::handle()`
 * empieza así:
 *
 *     if ($usuario->tipo !== 'Alumno' && $usuario->tipo !== 'Acudiente') {
 *         return $next($request);
 *     }
 *
 * O sea que **un administrador no atraviesa el guard: lo esquiva entero.** No es
 * que aquella medición no pudiera *distinguir* las dos lecturas — es que **ejerció
 * un camino en el que el guard no existe**. Con esa cuenta, un id ajeno válido se
 * sirve porque el administrador puede verlo, no porque el controlador lo sustituya.
 *
 * ## Y quién protege de verdad, que es lo que hay que dejar escrito
 *
 * **No es la sustitución.** El `if (!$alumno_id) { $alumno_id = $user->persona_id; }`
 * del controlador **sólo se dispara cuando no hay id**, que es justo el caso en que
 * no hay nada que proteger; con un id ajeno **válido** no llega a entrar, y la
 * consulta filtra por `mt.alumno_id = ?` con lo que le den, **sin comprobar nada**.
 *
 * Lo que protege es **el middleware `persona.propia` de la ruta**. Por eso este
 * test existe: **el día que alguien se lo quite a esa ruta, el controlador no tiene
 * defensa ninguna** y nada más avisaría. Es la misma familia que el
 * [`NOT LIKE` de `promovidos`](../../docs/migracion/05-codigo-muerto-y-roto.md)
 * (§226): *la guarda está, funciona, y no es la que uno cree.*
 *
 * Se comprueba **por el efecto y con la cuenta que importa** —un alumno y un
 * acudiente—, no con la que pasa de largo.
 */
class MisActividadesIdAjenoTest extends CasoDeContrato
{
    private const RUTA = '/api/mis-actividades/datos';

    /**
     * Un alumno pidiendo por otro: 403, y queda anotado.
     *
     * El `abort(403)` del guard va acompañado de una fila en `bitacoras` con
     * `affected_element_type = 'AlumnoPideAjeno:alumno_id'`. **Se comprueban las
     * dos cosas**: un 403 sin rastro no le sirve al colegio el día que alguien
     * reclama, y el rastro es la mitad del guard que nadie mira.
     */
    public function test_un_alumno_no_puede_pedir_las_actividades_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $suyo = $this->fichaDe('alumnos', (int) $alumno->id);
        $otro = $this->otroAlumnoDistintoDe($suyo);

        $this->assertNotNull($otro,
            'El seed no tiene un segundo alumno: sin él este test no comprueba nada.');

        $antes = DB::table('bitacoras')->count();

        $r = $this->putJson(self::RUTA, ['alumno_id' => $otro],
            ['Authorization' => 'Bearer '.$this->tokenDe($alumno->username)]);

        $this->assertSame(403, $r->getStatusCode(),
            'Un alumno recibió '.$r->getStatusCode().' pidiendo las actividades del alumno '.$otro.'.');

        $this->assertGreaterThan($antes, DB::table('bitacoras')->count(),
            'El 403 llegó sin dejar rastro en `bitacoras`: el colegio no puede saber que lo intentó.');
    }

    /**
     * Sin id, lo suyo — y esto es lo único que hace la sustitución.
     *
     * `null` lo salta el guard **a propósito**: *«sin identificador se deja pasar:
     * significa lo mío»*. Ahí sí actúa el `if (!$alumno_id)` del controlador, y lo
     * que devuelve es del alumno de la sesión.
     */
    public function test_sin_alumno_id_devuelve_lo_suyo(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $cab = ['Authorization' => 'Bearer '.$this->tokenDe($alumno->username)];

        $sinClave = $this->putJson(self::RUTA, [], $cab);
        $conNulo = $this->putJson(self::RUTA, ['alumno_id' => null], $cab);

        $sinClave->assertStatus(200);
        $conNulo->assertStatus(200);

        $this->assertSame($sinClave->json(), $conNulo->json(),
            '`alumno_id: null` y no mandar la clave dejaron de ser lo mismo.');
    }

    /**
     * Y el cero, que es el que separa las dos lecturas.
     *
     * `0` es falsy para el controlador —el `if` lo sustituiría— **pero el guard lo
     * ve**: su filtro es `!== null && !== '' && is_scalar`, así que `0` llega a
     * `esSuyo()` y no es de nadie. **El guard contesta antes de que el controlador
     * llegue a sustituir nada.**
     *
     * Que el resultado sea distinto del de `null` es lo que demuestra que **quien
     * decide es el middleware y no la sustitución**: si mandara la sustitución, `0`
     * y `null` darían lo mismo.
     */
    public function test_el_cero_lo_para_el_guard_y_no_la_sustitucion(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $r = $this->putJson(self::RUTA, ['alumno_id' => 0],
            ['Authorization' => 'Bearer '.$this->tokenDe($alumno->username)]);

        $this->assertSame(403, $r->getStatusCode(),
            '`alumno_id: 0` dio '.$r->getStatusCode().'. Si da 200, el guard dejó pasar un id '
            .'que no es de nadie y quien contesta es la sustitución del controlador — que es '
            .'la lectura contraria a la de esta clase.');
    }

    /**
     * Un acudiente sólo por sus acudidos.
     *
     * Se incluye porque el guard **no trata igual a los dos**: para un acudiente,
     * `alumno_id` vale si está en `parentescos`. La regla de negocio dice «lo suyo
     * y lo completo de sus acudidos», así que el 403 tiene que ser por un alumno
     * que **no** sea suyo.
     */
    public function test_un_acudiente_no_puede_pedir_por_un_alumno_que_no_es_suyo(): void
    {
        $acudiente = $this->usuarioDeTipo('Acudiente');

        // **El id de la FICHA, no el de la cuenta.** `usuarioDeTipo()` devuelve una
        // fila de `users`, que no tiene `persona_id`: la primera versión de este
        // test leyó esa propiedad inexistente, la coaccionó a 0, y con
        // `acudiente_id = 0` la subconsulta no excluyó a nadie — así que eligió como
        // «ajeno» al primer alumno del seed, **que era acudido suyo**. Dio un 200 y
        // parecía una fuga. *El instrumento otra vez, y esta vez con la cara del
        // hallazgo que se estaba buscando.*
        $fichaAcudiente = $this->fichaDe('acudientes', (int) $acudiente->id);

        $this->assertNotSame(0, $fichaAcudiente,
            'No se pudo resolver la ficha del acudiente: sin eso la exclusión de acudidos no filtra nada.');

        $acudidos = DB::select(
            'SELECT alumno_id FROM parentescos WHERE acudiente_id = ? AND deleted_at IS NULL',
            [$fichaAcudiente]
        );

        $this->assertNotSame([], $acudidos,
            'Ese acudiente no tiene acudidos en el seed: cualquier alumno sería «ajeno» y el '
            .'test pasaría sin ejercer la regla de negocio.');

        $ajeno = DB::selectOne(
            'SELECT a.id FROM alumnos a
              WHERE a.deleted_at IS NULL
                AND a.id NOT IN (SELECT p.alumno_id FROM parentescos p
                                  WHERE p.acudiente_id = ? AND p.deleted_at IS NULL)
              LIMIT 1',
            [$fichaAcudiente]
        );

        $this->assertNotNull($ajeno, 'El seed no tiene un alumno que no sea acudido: no mide nada.');

        $r = $this->putJson(self::RUTA, ['alumno_id' => (int) $ajeno->id],
            ['Authorization' => 'Bearer '.$this->tokenDe($acudiente->username)]);

        $this->assertSame(403, $r->getStatusCode(),
            'Un acudiente recibió '.$r->getStatusCode().' pidiendo por el alumno '.$ajeno->id.
            ', que no es acudido suyo.');
    }

    /**
     * Y el personal pasa de largo — **que es por qué la §230 no vio nada**.
     *
     * Esto **no es un fallo y no se está pidiendo que cambie**: `ExigirPersonaPropia`
     * declara en su cabecera que lo que puede hacer el personal entre sí queda para
     * el refactor de permisos. Se fija aquí porque **es la explicación de una
     * medición que salió del revés**, y sin este test esa explicación es una frase
     * en un documento que nadie vuelve a comprobar.
     */
    public function test_el_personal_esquiva_el_guard_y_por_eso_medir_con_admin_no_ve_nada(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El grupo del seed no tiene matrículas.');

        $r = $this->putJson(self::RUTA, ['alumno_id' => (int) $alumno->alumno_id],
            ['Authorization' => 'Bearer '.$token]);

        $this->assertSame(200, $r->getStatusCode(),
            'El personal dejó de atravesar `persona.propia`. Si esto pasa a 403, la explicación '
            .'de por qué la §230 midió lo que midió deja de ser cierta y hay que reescribirla.');
    }

    /** El id de la ficha (`alumnos`/`acudientes`) de una cuenta de `users`. */
    private function fichaDe(string $tabla, int $userId): int
    {
        $fila = DB::selectOne(
            'SELECT id FROM '.$tabla.' WHERE user_id = ? AND deleted_at IS NULL LIMIT 1',
            [$userId]
        );

        return $fila === null ? 0 : (int) $fila->id;
    }

    private function otroAlumnoDistintoDe(int $suyo): ?int
    {
        $fila = DB::selectOne(
            'SELECT a.id FROM alumnos a
              INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
              WHERE a.deleted_at IS NULL AND a.id <> ? LIMIT 1',
            [$suyo]
        );

        return $fila === null ? null : (int) $fila->id;
    }
}
