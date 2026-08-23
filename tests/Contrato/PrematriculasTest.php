<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las dos listas de `prematriculas/`, que son las gemelas de las de `matriculas/`.
 *
 * `MatriculasTest` ya midió las de `matriculas/`. Éstas no las había mirado nadie, y
 * la pregunta que las hace merecer un fichero no es qué devuelven: es **en qué se
 * diferencian de sus gemelas**, porque el código es el mismo copiado.
 *
 * La respuesta, medida con un `diff` de los dos métodos: **83 líneas cada uno, y una
 * sola diferencia** — `matriculas/` filtra `deleted_at is null` al resolver el año
 * anterior y `prematriculas/` no—. Eso está en la §96 y aquí queda fijado por los
 * dos lados: que ya no diverjan, y que lo que sí comparten siga igual.
 *
 * Y el dato que decide cómo se arregla: **ningún cliente llama a las de
 * `prematriculas/`**. `myvc_front` usa las de `matriculas/` y lo dice en su propio
 * `PrematriculasApi.ts`; `myvc_front_2` y `myvc_flutter` no las nombran. Con eso, lo
 * correcto es alinear la que nadie usa con la que sí se usa, no inventar un tercer
 * comportamiento.
 */
class PrematriculasTest extends CasoDeContrato
{
    /**
     * El año anterior se busca **vivo**, igual que en la gemela de `matriculas/`.
     *
     * Sin el filtro, pedir el año anterior por su número resolvía también a un año de
     * la papelera, y la lista traía alumnos de un año que el colegio borró.
     *
     * El caso hay que **fabricarlo por los dos lados**, y ninguno de los dos se puede
     * buscar en el seed: la tercera consulta —la de «los del grado anterior que no se
     * han matriculado»— sale vacía tal como está la base, porque los 56 alumnos del
     * año anterior están todos matriculados en el actual y el `NOT IN` no deja pasar
     * a ninguno. Con esa consulta vacía, quitar o poner el filtro **da lo mismo**, y
     * un test escrito sin desmatricular a nadie pasaría con el arreglo revertido.
     */
    public function test_el_ano_anterior_no_se_busca_en_la_papelera(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $anterior = DB::selectOne('SELECT g.id, g.grado_id, y.id AS year_id, y.year FROM grupos g
            INNER JOIN years y ON y.id = g.year_id AND y.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
            WHERE g.deleted_at IS NULL
              AND y.year = (SELECT y2.year - 1 FROM grupos g2
                            INNER JOIN years y2 ON y2.id = g2.year_id WHERE g2.id = ?)
            GROUP BY g.id, g.grado_id, y.id, y.year ORDER BY COUNT(m.id) DESC LIMIT 1', [$grupo->id]);

        $this->assertNotNull($anterior, 'El seed necesita un grupo del año anterior.');

        // Se desmatricula a uno del año actual para que la tercera consulta traiga
        // algo: si sale vacía, el filtro no se puede medir.
        $alumno = DB::selectOne('SELECT m2.alumno_id, m2.id AS matricula_actual
            FROM matriculas m1
            INNER JOIN matriculas m2 ON m2.alumno_id = m1.alumno_id AND m2.grupo_id = ?
                AND m2.deleted_at IS NULL
            WHERE m1.grupo_id = ? AND m1.deleted_at IS NULL
            ORDER BY m2.alumno_id LIMIT 1', [$grupo->id, $anterior->id]);

        $this->assertNotNull($alumno, 'Ningún alumno del año anterior sigue en el grupo actual.');
        DB::table('matriculas')->where('id', $alumno->matricula_actual)->update(['deleted_at' => now()]);

        $cuerpo = [
            'grupo_actual' => ['id' => $grupo->id],
            'grado_ant_id' => $anterior->grado_id,
            'year_ant' => (int) $anterior->year,
        ];
        $pedir = fn () => $this->putJson('/api/prematriculas/alumnos-grado-anterior', $cuerpo,
            ['Authorization' => 'Bearer '.$token]);

        $conElVivo = $pedir();
        $conElVivo->assertStatus(200);
        $this->assertContains($alumno->alumno_id, array_column($conElVivo->json(), 'alumno_id'),
            'Con el año anterior vivo, el desmatriculado tiene que salir.');

        // Y ahora el año anterior se va a la papelera. El número sigue siendo el
        // mismo, así que sin el filtro la consulta lo encontraría igual.
        DB::table('years')->where('id', $anterior->year_id)->update(['deleted_at' => now()]);

        $conElBorrado = $pedir();
        $conElBorrado->assertStatus(200);
        $this->assertNotContains($alumno->alumno_id, array_column($conElBorrado->json(), 'alumno_id'),
            'Un año en la papelera no puede seguir dando alumnos al listado.');
    }

    /**
     * Y lo que **no** cambia, que es la mitad que hace fiable a la otra: las dos
     * gemelas contestan lo mismo con el mismo cuerpo.
     *
     * Es la comprobación que no se puede hacer leyendo, porque los dos métodos son el
     * mismo texto copiado y leerlos dice justo que son iguales.
     */
    public function test_las_dos_gemelas_contestan_lo_mismo(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $contexto = DB::selectOne('SELECT y.year, g.grado_id FROM grupos g
            INNER JOIN years y ON y.id = g.year_id WHERE g.id = ?', [$grupo->id]);

        $cuerpo = [
            'grupo_actual' => ['id' => $grupo->id],
            'grado_ant_id' => $contexto->grado_id,
            'year_ant' => $contexto->year - 1,
        ];

        $unaLista = fn (string $ruta) => $this->putJson($ruta, $cuerpo,
            ['Authorization' => 'Bearer '.$token]);

        $mat = $unaLista('/api/matriculas/alumnos-grado-anterior');
        $pre = $unaLista('/api/prematriculas/alumnos-grado-anterior');

        $mat->assertStatus(200);
        $pre->assertStatus(200);
        $this->assertNotEmpty($pre->json(), 'La lista salió vacía y entonces no compara nada.');
        $this->assertSame($mat->json(), $pre->json());
    }

    /**
     * Lo que se mide y **no** se toca: sin `grupo_actual` contesta **200 con el
     * cuerpo vacío**, no 422.
     *
     * Es el `return;` sin valor del principio del método, y es lo mismo que hacen sus
     * dos gemelas de `matriculas/` —ya fijado en `MatriculasTest`—. Cambiarlo aquí
     * dejaría a las cuatro rutas hermanas contestando dos cosas distintas al mismo
     * cuerpo, que es peor que el 200 vacío.
     */
    public function test_sin_grupo_contesta_vacio_como_sus_gemelas(): void
    {
        [, $token] = $this->grupoYPersonal();

        foreach (['alumnos-grado-anterior', 'alumnos-con-grado-anterior'] as $ruta) {
            $r = $this->putJson("/api/prematriculas/{$ruta}", [],
                ['Authorization' => 'Bearer '.$token]);

            $r->assertStatus(200);
            $this->assertSame('', $r->getContent(),
                "{$ruta} dejó de responder con el cuerpo vacío.");
        }
    }

    /**
     * Y lo que hay que dejar escrito aunque no se arregle: **un `grupo_actual` que no
     * es un objeto es 500**, porque el método hace `$grupo_actual['id']` sobre lo que
     * llegue.
     *
     * No se toca, y por la misma razón que el 200 vacío: las cuatro hermanas hacen lo
     * mismo y arreglar sólo estas dos crea una asimetría nueva justo debajo de la que
     * este fichero acaba de cerrar. Va anotado para que se arregle en las cuatro a la
     * vez, o en ninguna.
     */
    public function test_un_grupo_que_no_es_objeto_sigue_siendo_500(): void
    {
        [, $token] = $this->grupoYPersonal();

        foreach ([1, 'hola'] as $raro) {
            $r = $this->putJson('/api/prematriculas/alumnos-grado-anterior',
                ['grupo_actual' => $raro], ['Authorization' => 'Bearer '.$token]);

            $this->assertSame(500, $r->status(),
                'Si esto cambia, hay que cambiarlo también en las tres hermanas.');
        }
    }

    /**
     * La lista trae la ficha personal completa de cada alumno, y **de cualquier
     * grupo**, incluido uno de otro año.
     *
     * `auth.personal` y nada más: el `grupo_actual` del cuerpo no se comprueba contra
     * el año del usuario. Medido: un grupo de otro año devuelve sus 56 alumnos con
     * `fecha_nac`, `direccion`, `celular`, `religion` y `no_matricula`.
     *
     * **No se cierra**: es la misma decisión de las 44 rutas de la configuración
     * académica —el personal del colegio ve el colegio entero—, tomada por Joseth y
     * no re-litigable. Se fija para que quede escrito cuánto alcanza, que es lo que
     * hay que tener delante el día que se decida otra cosa.
     */
    public function test_alcanza_a_cualquier_grupo_del_colegio(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $otro = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
            WHERE g.deleted_at IS NULL AND g.year_id <> ?
            GROUP BY g.id ORDER BY g.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($otro, 'El seed tiene que traer un grupo de otro año.');

        $r = $this->putJson('/api/prematriculas/alumnos-grado-anterior',
            ['grupo_actual' => ['id' => $otro->id]], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);
        $this->assertNotEmpty($r->json(), 'Un grupo de otro año devuelve sus alumnos.');

        foreach (['nombres', 'apellidos', 'fecha_nac', 'direccion', 'celular', 'religion',
            'no_matricula'] as $campo) {
            $this->assertArrayHasKey($campo, $r->json()[0],
                "La ficha sale entera, y `{$campo}` es parte de lo que alcanza.");
        }
    }
}
