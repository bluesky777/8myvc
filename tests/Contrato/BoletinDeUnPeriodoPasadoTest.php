<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **`periodo_id` en los ocho boletines de periodo** — la regla de Joseth del 15 sep
 * 2026: *«no importa si el periodo cerró, siempre se puede imprimir boletines del
 * mismo»*.
 *
 * ## Qué faltaba de verdad, que no es lo que parecía
 *
 * No era que «los boletines» no aceptaran un periodo: **dependía de quién mirase**.
 * El personal ya podía —`periodos/useractive` mueve su contexto a cualquier periodo
 * vivo, que es el selector de la barra— y el alumno ya veía sus NOTAS de todos los
 * periodos por `notas/alumno`. Lo que no tenía camino era **su boletín**: estas ocho
 * rutas imprimen `$user->periodo_id` y `periodos/useractive` es `auth.personal`, así
 * que la familia estaba clavada al periodo activo del colegio sin nada con que
 * moverlo. Por eso el caso que manda aquí es el del **alumno**, y no el del docente.
 *
 * ## Lo que se comprueba, y por qué cada cosa
 *
 *  1. **Las cuatro familias lo aceptan**, el de competencias incluido: no estrena el
 *     problema, lo hereda.
 *  2. **Sin el campo no cambia nada.** Es lo que deja desplegar esto sin tocar los
 *     cuatro clientes: `myvc_flutter` es una sola app para los dieciséis y una
 *     versión vieja convive meses.
 *  3. **Pedir un periodo pasado NO recalcula.** `putDetailedNotas` escribe —pone al
 *     día las definitivas del alumno que abre—, y eso se razonó inocuo para el
 *     periodo ACTIVO, que era el único alcanzable. Con un periodo cerrado, quien
 *     abre puede ser el acudiente y lo que se reescribiría son definitivas ya
 *     impresas. Un boletín de un periodo que pasó es una lectura.
 *  4. **Un periodo de otro año es 422**, y no un boletín mezclado: los controladores
 *     leen `$user->year_id` en una docena de sitios, así que un periodo de 2024 con
 *     el usuario en 2025 daría escalas de un año y notas de otro, en 200.
 */
class BoletinDeUnPeriodoPasadoTest extends CasoDeContrato
{
    /** Las ocho rutas, por familia. */
    private const FAMILIAS = ['boletines', 'boletines2', 'boletines3', 'boletines-competencias'];

    public function test_las_cuatro_familias_imprimen_el_periodo_pedido_y_no_el_activo(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);
        $otro = $this->unPeriodoQueNoEsElActivo($token, (int) $grupo->id, $periodos);

        foreach (self::FAMILIAS as $familia) {
            foreach (['detailed-notas', 'detailed-notas-group'] as $ruta) {
                $r = $this->putJson("/api/{$familia}/{$ruta}/{$grupo->id}",
                    ['periodo_id' => $otro->id], $this->como($token))->assertStatus(200);

                $this->assertSame(
                    $otro->numero,
                    $this->numeroDelBoletin($familia, $r->json()),
                    "{$familia}/{$ruta} imprimió el periodo activo y no el pedido."
                );
            }
        }
    }

    /**
     * El caso que justifica la entrega: el alumno, que **no puede mover su periodo**.
     *
     * Se comprueba también que sigue sin poder pedir el de otro —el guard
     * `boletin.propio` no se relaja porque el cuerpo traiga un periodo—, porque un
     * campo nuevo en una ruta con guard es exactamente donde se cuela un permiso.
     */
    public function test_un_alumno_pide_su_boletin_de_un_periodo_cerrado_y_no_el_de_otro(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($usuario->username);

        /*
         * **El año se lee DESPUÉS del login y del propio usuario**, no del grupo:
         * `Services\Login` reescribe `users.periodo_id` al periodo actual en cada
         * inicio de sesión, y el boletín se calcula contra `$user->year_id`. Sacar el
         * año del grupo daba un periodo que no es del año del usuario — y entonces lo
         * que contesta 422 es mi propia comprobación, no el caso que quiero probar.
         */
        $activo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.id = ?', [$usuario->id]);

        $this->assertNotNull($activo, 'El alumno se quedó sin periodo tras el login.');

        $mio = DB::selectOne('SELECT a.id alumno_id, m.grupo_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
            WHERE a.user_id = ? AND a.deleted_at IS NULL LIMIT 1', [$activo->year_id, $usuario->id]);

        $this->assertNotNull($mio, 'El alumno del seed no está matriculado en un grupo de su año.');

        $otro = DB::selectOne('SELECT id, numero FROM periodos
            WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$activo->year_id, $activo->id]);

        $this->assertNotNull($otro, 'El año del alumno tiene un solo periodo.');

        // No puede moverse solo: ésa es la puerta que no tiene.
        $this->putJson("/api/periodos/useractive/{$otro->id}", [], $this->como($token))->assertStatus(403);

        // **Nombrándose a sí mismo**: el guard exige `requested_alumnos`, porque pedir
        // la ruta pelada es pedir el grupo entero. El periodo va al lado, no en vez de.
        $suyo = ['requested_alumnos' => [['alumno_id' => $mio->alumno_id, 'grupo_id' => $mio->grupo_id]]];

        $r = $this->putJson("/api/boletines/detailed-notas/{$mio->grupo_id}",
            $suyo + ['periodo_id' => $otro->id], $this->como($token))->assertStatus(200);

        $this->assertSame((int) $otro->numero, $this->numeroDelBoletin('boletines', $r->json()));

        /*
         * **Y el campo nuevo no afloja el guard**, que es donde se cuela un permiso:
         * pedir el de un compañero sigue siendo 403 **con periodo pedido y sin él**.
         * Se comprueba con las dos llamadas y no con una, porque lo que hay que
         * descartar es justo que el `periodo_id` cambie el desenlace.
         */
        $companero = DB::selectOne('SELECT a.id alumno_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.id <> ? AND a.deleted_at IS NULL LIMIT 1',
            [$mio->grupo_id, $mio->alumno_id]);

        $this->assertNotNull($companero, 'El grupo del alumno no tiene un compañero.');

        $delOtro = ['requested_alumnos' => [
            ['alumno_id' => $companero->alumno_id, 'matricula_id' => $companero->matricula_id],
        ]];

        $this->putJson("/api/boletines/detailed-notas/{$mio->grupo_id}",
            $delOtro, $this->como($token))->assertStatus(403);

        $this->putJson("/api/boletines/detailed-notas/{$mio->grupo_id}",
            $delOtro + ['periodo_id' => $otro->id], $this->como($token))->assertStatus(403);
    }

    /**
     * Sin `periodo_id`, byte a byte lo de antes: el periodo del usuario.
     */
    public function test_sin_el_campo_se_imprime_el_periodo_activo_de_siempre(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $activo = $this->periodoDelToken($token, (int) $grupo->id);

        foreach (self::FAMILIAS as $familia) {
            $r = $this->putJson("/api/{$familia}/detailed-notas-group/{$grupo->id}",
                [], $this->como($token))->assertStatus(200);

            $this->assertSame(
                $activo->numero,
                $this->numeroDelBoletin($familia, $r->json()),
                "{$familia} cambió de conducta sin que nadie le mandara un periodo."
            );
        }
    }

    /**
     * **La mitad del diseño que no estaba en la decisión**: leer un periodo cerrado
     * no puede reescribir sus definitivas.
     */
    public function test_pedir_un_periodo_pasado_no_recalcula_las_definitivas(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);
        $otro = $this->unPeriodoQueNoEsElActivo($token, (int) $grupo->id, $periodos);

        $alumno = DB::selectOne('SELECT alumno_id FROM matriculas
            WHERE grupo_id = ? AND deleted_at IS NULL AND estado IN ("MATR","ASIS","PREM")
            ORDER BY id LIMIT 1', [$grupo->id]);

        $huella = fn () => DB::selectOne(
            'SELECT COUNT(*) n, COALESCE(MAX(updated_at), "-") ultimo FROM notas_finales
              WHERE alumno_id = ?', [$alumno->alumno_id]
        );

        $antes = $huella();

        $this->putJson("/api/boletines/detailed-notas/{$grupo->id}", [
            'periodo_id' => $otro->id,
            'requested_alumnos' => [['alumno_id' => $alumno->alumno_id]],
        ], $this->como($token))->assertStatus(200);

        $this->assertEquals($antes, $huella(),
            'Abrir el boletín de un periodo pasado recalculó definitivas: eso reescribe papel ya impreso.');
    }

    public function test_un_periodo_de_otro_anio_o_inventado_es_422_y_no_un_boletin_mezclado(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $deOtroAnio = DB::selectOne('SELECT p.id FROM periodos p
            INNER JOIN grupos g ON g.id = ?
            WHERE p.year_id <> g.year_id AND p.deleted_at IS NULL LIMIT 1', [$grupo->id]);

        $this->assertNotNull($deOtroAnio, 'El seed no tiene periodos de otro año: el caso no se puede montar.');

        foreach ([$deOtroAnio->id, 999999999, 'orden', -3, 0] as $malo) {
            $this->putJson("/api/boletines/detailed-notas-group/{$grupo->id}",
                ['periodo_id' => $malo], $this->como($token))->assertStatus(422);
        }

        // Y la cadena vacía es «no mando periodo», no un 422: es lo que manda un
        // `<select>` sin elegir, y romper ahí sería romper la pantalla por nada.
        $this->putJson("/api/boletines/detailed-notas-group/{$grupo->id}",
            ['periodo_id' => ''], $this->como($token))->assertStatus(200);
    }

    // ── Andamio ─────────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function como(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** @param list<int> $periodos */
    private function unPeriodoQueNoEsElActivo(string $token, int $grupoId, array $periodos): object
    {
        $activo = $this->periodoDelToken($token, $grupoId);

        foreach ($periodos as $id) {
            if ($id !== $activo->id) {
                $fila = DB::selectOne('SELECT id, numero FROM periodos WHERE id = ?', [$id]);

                return (object) ['id' => (int) $fila->id, 'numero' => (int) $fila->numero];
            }
        }

        $this->fail('El año del grupo tiene un solo periodo: el caso no se puede montar.');
    }

    /**
     * El periodo activo de ese token, **preguntándoselo a la API** y no a la base.
     *
     * Deducirlo de `users.periodo_id` es la trampa de siempre: `Services\Login`
     * reescribe esa columna al periodo `actual` **en cada inicio de sesión**, así que
     * lo que había antes de pedir el token no tiene por qué ser lo que hay después. Un
     * boletín sin `periodo_id` imprime por definición el activo, así que la respuesta
     * es la fuente que no puede desincronizarse.
     */
    private function periodoDelToken(string $token, int $grupoId): object
    {
        $cuerpo = $this->putJson("/api/boletines/detailed-notas-group/{$grupoId}",
            [], $this->como($token))->assertStatus(200)->json();

        $numero = (int) ((array) $cuerpo[1])['periodo'];

        $fila = DB::selectOne(
            'SELECT p.id FROM periodos p INNER JOIN grupos g ON g.id = ?
              WHERE p.year_id = g.year_id AND p.numero = ? AND p.deleted_at IS NULL LIMIT 1',
            [$grupoId, $numero]
        );

        $this->assertNotNull($fila, "El periodo activo (numero {$numero}) no es del año del grupo {$grupoId}.");

        return (object) ['id' => (int) $fila->id, 'numero' => $numero];
    }

    /**
     * El número de periodo que dice la respuesta, que **no está en el mismo sitio en
     * las cuatro**: los tres de siempre lo ponen en `year->periodo` de la tupla, y el
     * de competencias en su quinta posición.
     *
     * @param  array<int|string, mixed>  $cuerpo
     */
    private function numeroDelBoletin(string $familia, array $cuerpo): int
    {
        $year = (array) $cuerpo[1];

        return (int) $year['periodo'];
    }
}
