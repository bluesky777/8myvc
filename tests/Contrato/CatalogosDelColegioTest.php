<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los catálogos del colegio: ordinales de disciplina, niveles, grados y tipos de documento.
 *
 * Hueco de cobertura elegido por dominio y no por número: de las 32 rutas de
 * `CiudadesController`, `Disciplina\OrdinalesController`, `NivelesEducativosController`,
 * `TipoDocumentoController` y `GradosController`, casi todas las de escritura llevan
 * `auth.personal` a secas.
 *
 * **Que lleven `auth.personal` a secas no es lo que se comprueba aquí.** Joseth decidió el
 * 21 ago 2026 no cerrar las 44 rutas de escritura de la configuración del colegio, porque
 * cerrarlas puede dejar fuera a un coordinador que hoy configura y no tiene el rol
 * (09-pendientes.md, «Y una cosa que no encaja con lo que se dio por hecho»). Es una
 * decisión tomada. Lo que faltaba de estas rutas es lo otro: **qué responden**.
 */
class CatalogosDelColegioTest extends CasoDeContrato
{
    /**
     * El `year_id` del cuerpo entra crudo en el SQL de los ordinales.
     *
     * `putOrdinales()` arma la primera de sus tres consultas concatenando:
     *
     *     'SELECT * FROM dis_ordinales WHERE year_id='.$year_id.' and deleted_at is null ...'
     *
     * y `$year_id` es `Request::input('year_id', $user->year_id)`, o sea el cuerpo. Las
     * otras dos consultas del MISMO método ligan el parámetro (`:year_id`). La asimetría
     * es lo que hace el test barato de leer: se manda un `year_id` con SQL dentro y
     * `ordinales` obedece al SQL mientras `tipos` sigue contestando por el año de verdad.
     *
     * `and` liga más fuerte que `or`, así que `2 OR 1=1` se lee como
     * `year_id=2 OR (1=1 and deleted_at is null)`: salen los ordinales de **todos los
     * años del colegio**, no los del año pedido.
     *
     * No es de la familia de `ColumnaSegura`, y por eso no lo tapó: allí lo que se
     * concatena es el NOMBRE de la columna y el valor va ligado. Aquí es el valor.
     *
     * Y la ruta **ya estaba cubierta** —`MuestreoDeLecturasConContextoTest` la golpea con
     * un `year_id` legítimo y compara la instantánea—, que es la lección que ya va por la
     * tercera vez en dos días: un test que fija lo que hay deja fijado también lo que
     * estaba mal, y a partir de ahí hay un verde que dice que es así.
     */
    public function test_el_year_de_los_ordinales_no_admite_sql_en_el_cuerpo(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe($grupo->year_id);

        $suyos = (int) DB::selectOne('SELECT COUNT(*) n FROM dis_ordinales
            WHERE year_id = ? AND deleted_at IS NULL', [$grupo->year_id])->n;

        $deTodoElColegio = (int) DB::selectOne('SELECT COUNT(*) n FROM dis_ordinales
            WHERE deleted_at IS NULL')->n;

        $this->assertGreaterThan(0, $suyos, 'El año del grupo no tiene ordinales: el test no mediría nada.');
        $this->assertGreaterThan($suyos, $deTodoElColegio,
            'El seed necesita ordinales de más de un año para que la fuga se note.');

        $cuerpo = $this->withToken($token)
            ->putJson('/api/ordinales/ordinales', ['year_id' => $grupo->year_id.' OR 1=1'])
            ->assertStatus(200)
            ->json();

        $this->assertCount($suyos, $cuerpo['ordinales'],
            'El `year_id` del cuerpo se concatena en el SQL: con `OR 1=1` salen los '.
            'ordinales de todos los años del colegio en vez de los del año pedido.');
    }
}
