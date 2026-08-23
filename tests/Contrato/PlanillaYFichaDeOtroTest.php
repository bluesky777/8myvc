<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las lecturas del lote: qué se ve de otro y qué cuesta verlo — §100.
 *
 * Las cuatro rutas de aquí llevan `auth.personal`, o sea que la puerta está
 * cerrada para las familias y **abierta de par en par para los 51 profesores**.
 * Lo que no estaba medido es qué sale por ellas y cuánto cuesta: la vecina ya
 * medida de `planillas` monta **1 + 13 + 378 consultas** en una petición
 * ([05 §75.6](../../docs/migracion/05-codigo-muerto-y-roto.md)), y la de al lado
 * nadie la había contado.
 */
class PlanillaYFichaDeOtroTest extends CasoDeContrato
{
    /**
     * La planilla de un profesor que no está daba 500 — la §98 otra vez.
     *
     * `getShowProfesor()` llama a `Profesor::detallado()`, que termina en
     * `$profesor[0]` sin comprobar nada, y **es el mismo fatal que `show`**. El
     * modelo no se toca: lo llaman seis sitios y arreglarlo ahí convertiría seis
     * 500 en seis comportamientos distintos sin haber medido ninguno. Se arregla
     * en el llamante, que es lo que hace comprobable el cambio.
     */
    public function test_la_planilla_de_un_profesor_que_no_esta_da_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $inexistente = (int) DB::table('profesores')->max('id') + 1000;

        $this->assertSame(404,
            $this->withToken($token)->getJson('/api/planillas/show-profesor/'.$inexistente)->status(),
            'La planilla de un profesor inexistente dejó de dar 404 — §100.');
    }

    /**
     * La planilla de un grupo de OTRO año sale, y sale mezclada.
     *
     * `getShowGrupo()` toma el grupo del id de la URL y los periodos del **año del
     * token**, y no los casa. O sea que la rejilla de un grupo de 2024 se pinta
     * con las columnas de periodo de 2026. No es una fuga —`auth.personal` ya
     * cerró la puerta a las familias, y el histórico del colegio es del colegio—
     * pero es **una respuesta que no describe nada real**, y hay precedente
     * exacto: `asignaturas/restaurar` alcanzaba todos los años mientras su
     * listado enseñaba uno ([PapeleraRestaurarTest](PapeleraRestaurarTest.php)).
     *
     * **No se cierra aquí.** Con 461 rutas medidas y ningún cliente conocido que
     * pida un grupo de otro año, cerrarlo es decidir si el colegio consulta
     * histórico por esta pantalla, y eso no es de esta noche. Se fija lo que hay.
     */
    public function test_la_planilla_de_un_grupo_de_otro_ano_sale_con_los_periodos_del_token(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $suYear = (int) DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id])->year_id;

        $ajeno = DB::selectOne('SELECT id, year_id FROM grupos
            WHERE year_id <> ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$suYear]);

        if ($ajeno === null) {
            $this->markTestSkipped('El seed solo trae grupos de un año.');
        }

        $r = $this->withToken($token)->getJson('/api/planillas/show-grupo/'.$ajeno->id);
        $r->assertStatus(200);

        // La respuesta es `[year, asignaturas]`, y ese `year` es el del TOKEN:
        // `datos_basicos()` lo llama `year_id`, no `id`.
        $this->assertSame($suYear, (int) $r->json()[0]['year_id'],
            'El año de la respuesta dejó de ser el del token — entonces alguien lo casó, y el §100 cambia.');

        $this->assertNotSame((int) $ajeno->year_id, $suYear,
            'El grupo elegido resultó ser del mismo año: el test no medía nada.');
    }

    /**
     * Lo que cuesta la rejilla de ausencias de los acudientes, contado.
     *
     * `putPlanillasAusencias()` recorre **grupo × alumno** y por cada alumno pide
     * sus parientes con otra consulta. No se juzga el número: se fija, que es lo
     * único que hace que suba y se note. Encoger la respuesta es contrato con
     * dieciséis copias del front ([05 §75.6]).
     */
    public function test_la_rejilla_de_ausencias_de_acudientes_cuesta_lo_que_cuesta(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $r = $this->withToken($token)->putJson('/api/acudientes/planillas-ausencias', []);
        $r->assertStatus(200);

        $grupos = $r->json()['grupos_acud'];
        $alumnos = array_sum(array_map(static fn ($g) => count($g['alumnos']), $grupos));

        // Una por grupo (sus alumnos) + una por alumno (sus parientes), más el
        // año, el listado de grupos y las de resolver quién pregunta.
        $this->assertGreaterThanOrEqual($alumnos + count($grupos), $consultas,
            'La rejilla dejó de pedir los parientes uno a uno — mídelo y actualiza el §100.');

        $this->assertLessThan(($alumnos + count($grupos)) * 2 + 20, $consultas,
            "La rejilla pasó de {$consultas} consultas para {$alumnos} alumnos en ".count($grupos).' grupos.');
    }

    /**
     * `acudientes/ultimos` son ocho, y traen la ficha entera de cada uno.
     *
     * Documento, dirección, teléfono, celular, correo y ocupación de ocho
     * acudientes, para pintar «los últimos creados» en una pantalla de
     * administración. No se juzga —es la ficha que el colegio administra— pero se
     * fija el **ocho** y se fija la lista, porque ampliar cualquiera de las dos
     * cosas es ampliar lo que ve un profesor cualquiera de familias que no son
     * las suyas.
     */
    public function test_los_ultimos_acudientes_son_ocho_y_con_la_ficha_entera(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $r = $this->withToken($token)->putJson('/api/acudientes/ultimos', []);
        $r->assertStatus(200);

        $this->assertLessThanOrEqual(8, count($r->json()),
            'El `limit 8` de `acudientes/ultimos` cambió: lo lee una pantalla que espera ocho.');

        if ($r->json() === []) {
            $this->markTestSkipped('El seed no trae acudientes con parentesco.');
        }

        foreach (['documento', 'direccion', 'telefono', 'celular', 'email', 'ocupacion'] as $campo) {
            $this->assertArrayHasKey($campo, $r->json()[0],
                "`acudientes/ultimos` dejó de traer {$campo}: es contrato con el front.");
        }
    }

    /** Las cuatro son de personal: una familia no entra por ninguna. */
    public function test_una_familia_no_entra_por_ninguna_de_las_cuatro(): void
    {
        $grupo = $this->grupoConAlumnos();
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->assertSame(403,
                $this->withToken($token)->getJson('/api/planillas/show-grupo/'.$grupo->id)->status(),
                "Un {$tipo} vio la planilla de un grupo.");

            $this->assertSame(403,
                $this->withToken($token)->getJson('/api/planillas/show-profesor/'.$profesor->id)->status(),
                "Un {$tipo} vio la planilla de un profesor.");

            $this->assertSame(403,
                $this->withToken($token)->putJson('/api/acudientes/ultimos', [])->status(),
                "Un {$tipo} vio los últimos acudientes.");

            $this->assertSame(403,
                $this->withToken($token)->putJson('/api/acudientes/planillas-ausencias', [])->status(),
                "Un {$tipo} vio la rejilla de ausencias del colegio entero.");
        }
    }
}
