<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `alumnos/alumnos-parecidos` — el buscador de duplicados que sí sirve para decidir.
 *
 * Existe porque los dos que había devuelven cuatro campos y con cuatro campos la pantalla
 * de alta sólo podía pintar un aviso amarillo. Lo que se fija aquí es lo que hace falta
 * para pulsar «es éste»: que el candidato venga **con su historial**, y que el servidor
 * diga si la coincidencia es fuerte —porque de eso depende que el alta se frene—.
 *
 * Medido en el docker el 21 sep 2026: 27 documentos repetidos, 55 fichas. El caso real es
 * el mismo chico dos veces, con RC en una ficha y TI en la otra.
 */
class AlumnosParecidosTest extends CasoDeContrato
{
    private const RUTA = '/api/alumnos/alumnos-parecidos';

    private function token(): string
    {
        $u = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($u->username);
    }

    /**
     * EL DOCUMENTO EXACTO ES COINCIDENCIA FUERTE, y eso es lo que frena el alta.
     *
     * `hay_fuertes` lo calcula el servidor a propósito: si lo dedujera la pantalla, el
     * criterio viviría en dos sitios y el día que cambie sólo cambiaría uno.
     */
    public function test_el_documento_exacto_es_fuerte_y_trae_historial(): void
    {
        $alumno = DB::selectOne('SELECT a.id, a.nombres, a.apellidos, a.documento FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND TRIM(COALESCE(a.documento, "")) <> ""
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene ningún alumno con documento y matrícula.');

        $r = $this->withToken($this->token())->putJson(self::RUTA, ['documento' => $alumno->documento]);

        $r->assertStatus(200)->assertJson(['hay_fuertes' => true]);

        $candidatos = $r->json('candidatos');
        $ids = array_column($candidatos, 'alumno_id');
        $this->assertContains((int) $alumno->id, $ids);

        // El historial, que es lo que ninguno de los dos buscadores viejos devuelve.
        $suyo = $candidatos[array_search((int) $alumno->id, $ids, true)];
        $this->assertSame('documento', $suyo['coincidencia']);
        $this->assertGreaterThan(0, $suyo['matriculas']);
        $this->assertNotNull($suyo['ultimo_grupo']);
        $this->assertNotNull($suyo['ultimo_year']);
    }

    /**
     * MAYÚSCULAS Y TILDES NO IMPIDEN LA COINCIDENCIA FUERTE.
     *
     * Es la forma exacta del duplicado real: «DAVID ALEJANDRO ARAQUE GUERRERO» y «David
     * Alejandro Araque Guerrero». La colación `utf8mb4_unicode_ci` lo resuelve sola, y este
     * test existe para que se note el día que alguien cambie la colación o meta un `BINARY`.
     */
    public function test_el_nombre_casa_aunque_cambien_las_mayusculas(): void
    {
        $alumno = DB::selectOne('SELECT nombres, apellidos FROM alumnos
            WHERE deleted_at IS NULL AND TRIM(COALESCE(apellidos, "")) <> "" ORDER BY id LIMIT 1');

        $this->assertNotNull($alumno);

        $r = $this->withToken($this->token())->putJson(self::RUTA, [
            'nombres'   => mb_strtoupper($alumno->nombres),
            'apellidos' => mb_strtolower($alumno->apellidos),
        ]);

        $r->assertStatus(200);
        $this->assertContains('nombre_exacto', array_column($r->json('candidatos'), 'coincidencia'));
        $this->assertTrue($r->json('hay_fuertes'));
    }

    /**
     * UN PARECIDO NO FRENA NADA. Hay hermanos, y hay primos con los dos apellidos iguales:
     * si un trozo de nombre bloqueara el alta, la pantalla sería inusable y el freno se
     * acabaría quitando entero.
     */
    public function test_un_parecido_no_es_fuerte(): void
    {
        /*
         * **El seed tiene los nombres anonimizados y ninguno pasa de 6 caracteres** (medido:
         * `MAX(CHAR_LENGTH(nombres)) = 6` sobre 68 alumnos). Un test escrito para nombres de
         * verdad —«> 6 caracteres»— no encuentra a nadie y revienta leyendo `null`, que es lo
         * que pasó al escribirlo. Se coge el más largo que haya y se corta.
         */
        $alumno = DB::selectOne('SELECT nombres FROM alumnos
            WHERE deleted_at IS NULL AND CHAR_LENGTH(nombres) >= 5
            ORDER BY CHAR_LENGTH(nombres) DESC, id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene ningún alumno con nombre de 5 o más letras.');

        $r = $this->withToken($this->token())->putJson(self::RUTA, [
            'nombres' => mb_substr($alumno->nombres, 0, 4),
        ]);

        $r->assertStatus(200);
        $this->assertSame([], array_values(array_filter(
            array_column($r->json('candidatos'), 'coincidencia'),
            static fn ($c) => $c !== 'parecido')));
        $this->assertFalse($r->json('hay_fuertes'));
    }

    /**
     * LOS DE LA PAPELERA VIENEN DENTRO, y marcados.
     *
     * El caso que más duele es el del retirado de hace años: quien da el alta no lo
     * encuentra, lo crea de nuevo y aparecen las dos fichas. Un buscador que filtre
     * `deleted_at` repite el error que existe para evitar.
     */
    public function test_los_borrados_salen_marcados(): void
    {
        $alumno = DB::selectOne('SELECT id, nombres, apellidos, documento FROM alumnos
            WHERE deleted_at IS NULL AND TRIM(COALESCE(documento, "")) <> "" ORDER BY id LIMIT 1');

        DB::table('alumnos')->where('id', $alumno->id)->update(['deleted_at' => now()]);

        $r = $this->withToken($this->token())->putJson(self::RUTA, ['documento' => $alumno->documento]);

        $candidatos = $r->json('candidatos');
        $ids = array_column($candidatos, 'alumno_id');

        $this->assertContains((int) $alumno->id, $ids, 'El alumno de la papelera no sale.');
        $this->assertTrue($candidatos[array_search((int) $alumno->id, $ids, true)]['en_papelera']);
    }

    /** Sin nada que buscar no se devuelve el colegio entero. */
    public function test_con_dos_letras_no_devuelve_nada(): void
    {
        $r = $this->withToken($this->token())->putJson(self::RUTA, ['nombres' => 'ab']);

        $r->assertStatus(200)->assertExactJson(['candidatos' => [], 'hay_fuertes' => false]);
    }

    /** Sin token no se listan alumnos. */
    public function test_exige_sesion(): void
    {
        $this->putJson(self::RUTA, ['documento' => '123'])->assertStatus(401);
    }
}
