<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El catálogo de ciudades: lo que abre la ficha de un alumno (05 §85).
 *
 * `CiudadesController` estaba en 5 de 11 rutas comprobadas. Las seis que
 * faltaban son las cuatro lecturas que llevan **solo `auth.token`** —o sea que
 * un alumno llega—, el borrado, y la que pide los departamentos por el cuerpo.
 *
 * De aquí salieron dos cosas, y las dos por ejecutar dos rutas seguidas en vez
 * de leer una: **una ciudad sin país deja su pantalla en 500**, y **borrar una
 * ciudad deja las fichas apuntando a un id que ya no está, sin forma de
 * deshacerlo desde la API**.
 */
class CiudadesTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    private function ciudadDelSeed(): object
    {
        $ciudad = DB::table('ciudades')->whereNull('deleted_at')->orderBy('id')->first();
        $this->assertNotNull($ciudad, 'El seed no tiene ciudades.');

        return $ciudad;
    }

    /**
     * Lo que ve un alumno por las cuatro lecturas que solo piden token.
     *
     * La pregunta del lote era esa, y la respuesta es que **sale el catálogo
     * geográfico entero y nada más**: ciudad, departamento, país. No hay un solo
     * dato de una persona, así que las cuatro se quedan como están — el veredicto
     * es «medido y no es fuga», no «no se miró».
     */
    public function test_un_alumno_solo_ve_el_catalogo_geografico(): void
    {
        $ciudad = $this->ciudadDelSeed();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $r = $this->withToken($token)->getJson('/api/ciudades/datosciudad/'.$ciudad->id);
            $r->assertStatus(200);

            // Las seis claves son el contrato de la pantalla de editar la ficha:
            // la ciudad, las de su departamento, el departamento, los del país, el
            // país y todos los países. Ninguna trae a nadie.
            $this->assertSame(
                ['ciudad', 'ciudades', 'departamento', 'departamentos', 'pais', 'paises'],
                array_keys($r->json()));

            $this->assertSame(
                ['id', 'ciudad', 'departamento', 'pais_id', 'created_by', 'updated_by',
                    'deleted_by', 'deleted_at', 'created_at', 'updated_at'],
                array_keys($r->json('ciudad')));

            $this->withToken($token)->getJson('/api/ciudades/departamentos/'.$ciudad->pais_id)
                ->assertStatus(200)->assertJsonStructure([['departamento']]);

            $this->withToken($token)->getJson('/api/ciudades/paisdeciudad/'.$ciudad->id)
                ->assertStatus(200)->assertJsonStructure([['id', 'pais', 'abrev']]);

            $this->withToken($token)->getJson('/api/ciudades/por-departamento/'.rawurlencode($ciudad->departamento))
                ->assertStatus(200)->assertJsonStructure([['id', 'ciudad', 'departamento']]);
        }
    }

    /**
     * Y las dos que escriben, o que dan la lista completa, sí piden personal.
     *
     * `departamentos-by-id` recibe un `pais_id` **por el cuerpo** y sale en
     * `identificadores-del-cuerpo.py` como identificador sin comprobar propiedad.
     * Aquí queda juzgado y es un **falso positivo de la herramienta**: un país no
     * es de nadie, y no hay propiedad que comprobar. Lo que la separa de una
     * lectura pública es el guard, y el guard está.
     */
    public function test_una_familia_no_llega_ni_a_los_departamentos_por_el_cuerpo(): void
    {
        $ciudad = $this->ciudadDelSeed();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/ciudades/departamentos-by-id', ['pais_id' => $ciudad->pais_id])
                ->assertStatus(403);
            $this->withToken($token)->deleteJson('/api/ciudades/destroy/'.$ciudad->id)
                ->assertStatus(403);
        }

        $this->assertNull(DB::table('ciudades')->where('id', $ciudad->id)->value('deleted_at'));
    }

    /** El personal sí, y sin `pais_id` la lista vuelve vacía en 200. */
    public function test_los_departamentos_por_el_cuerpo(): void
    {
        $token = $this->tokenDelPersonal();
        $ciudad = $this->ciudadDelSeed();

        $r = $this->withToken($token)->putJson('/api/ciudades/departamentos-by-id', ['pais_id' => $ciudad->pais_id]);
        $r->assertStatus(200);
        $this->assertGreaterThan(0, count($r->json('departamentos')));

        // 200 con la lista vacía y no 422: sin `pais_id` la consulta compara contra
        // null y no casa con nada. No se juzgó si debería quejarse; queda fijado.
        $this->assertSame(['departamentos' => []],
            $this->withToken($token)->putJson('/api/ciudades/departamentos-by-id', [])->json());
    }

    /**
     * §85 — Una ciudad sin país dejaba su pantalla en 500.
     *
     * Dos rutas, ida y vuelta, las dos vivas y las dos alcanzables: `guardar-ciudad`
     * escribe `pais_id` tal como llega y la columna admite NULL, así que una ciudad
     * guardada sin país hacía que `datosciudad` —lo que abre la ficha de un alumno—
     * respondiera **500 «Undefined array key 0»**. Leyendo `datosciudad` sola no se
     * ve: hace falta la otra ruta para fabricar la fila.
     *
     * Ahora contesta 200 con **las mismas seis claves** y el país en null. No se
     * encoge la respuesta: eso sería contrato con dieciséis copias del front.
     */
    public function test_una_ciudad_sin_pais_ya_no_revienta_su_pantalla(): void
    {
        $token = $this->tokenDelPersonal();

        $creada = $this->withToken($token)->postJson('/api/ciudades/guardar-ciudad',
            ['ciudad' => 'Villa Sin País', 'departamento' => 'Departamento De Prueba']);

        // 201 y no 200: `postGuardarCiudad` devuelve un modelo Eloquent recién
        // creado, y eso es lo que hace que Laravel ponga el 201. Contrasta con las
        // rutas de al lado, que arman arrays a mano y salen en 200.
        $creada->assertStatus(201);
        $this->assertNull($creada->json('pais_id'), 'El `pais_id` se escribe tal como llega, y no llegó.');

        $r = $this->withToken($token)->getJson('/api/ciudades/datosciudad/'.$creada->json('id'));

        $r->assertStatus(200);
        $this->assertSame(
            ['ciudad', 'ciudades', 'departamento', 'departamentos', 'pais', 'paises'],
            array_keys($r->json()));
        $this->assertNull($r->json('pais'), 'Sin país, el país es null — no un 500.');
        $this->assertSame([], $r->json('departamentos'),
            'Los departamentos se piden por país; sin país no hay lista que pedir.');
        $this->assertGreaterThan(0, count($r->json('paises')),
            'Los países sí siguen viniendo: son los del desplegable, no los de esta ciudad.');
    }

    /** Y su hermana pequeña ya devolvía [] en vez de reventar: se queda igual. */
    public function test_el_pais_de_una_ciudad_sin_pais_es_una_lista_vacia(): void
    {
        $token = $this->tokenDelPersonal();

        $creada = $this->withToken($token)->postJson('/api/ciudades/guardar-ciudad',
            ['ciudad' => 'Villa Sin País', 'departamento' => 'Departamento De Prueba']);

        $this->assertSame([],
            $this->withToken($token)->getJson('/api/ciudades/paisdeciudad/'.$creada->json('id'))->json());
    }

    /** Un id que no existe: 200 con [] en las lecturas, 404 solo en el borrado. */
    public function test_los_identificadores_que_no_existen(): void
    {
        $token = $this->tokenDelPersonal();
        $inexistente = ((int) DB::table('ciudades')->max('id')) + 1000;

        // 200 con [] y no 404 en las tres lecturas: es lo que devuelven desde
        // siempre y lo que el front distingue de una respuesta con datos. No se
        // juzgó si debería ser 404; queda fijado para que no cambie sin querer.
        $this->assertSame([], $this->withToken($token)->getJson('/api/ciudades/datosciudad/'.$inexistente)->json());
        $this->assertSame([], $this->withToken($token)->getJson('/api/ciudades/paisdeciudad/'.$inexistente)->json());
        $this->assertSame([], $this->withToken($token)->getJson('/api/ciudades/departamentos/'.$inexistente)->json());
        $this->assertSame([], $this->withToken($token)->getJson('/api/ciudades/por-departamento/NoExisteEsteDepartamento')->json());

        // El borrado sí: `findOrFail`. La asimetría es real y está medida.
        $this->withToken($token)->deleteJson('/api/ciudades/destroy/'.$inexistente)->assertStatus(404);
    }

    /**
     * §85 — Borrar una ciudad la saca del catálogo y deja las fichas colgando.
     *
     * El borrado es blando y responde con la ciudad borrada, pero **no toca a
     * quien la estaba usando**: los alumnos nacidos ahí conservan el `ciudad_nac`
     * apuntando a una fila que ya no sale por ninguna de las cuatro lecturas. Y
     * **no hay ruta que la devuelva**: `restaurar` existe en alumnos, perfiles,
     * académico y estructura, y no en catálogos.
     *
     * Es la misma forma que borrar un grado (05 §70) y como aquella **espera
     * decisión del colegio**: aquí solo se mide y se fija.
     */
    public function test_borrar_una_ciudad_deja_las_fichas_apuntando_a_un_id_borrado(): void
    {
        $token = $this->tokenDelPersonal();

        $enUso = DB::selectOne('SELECT ciudad_nac AS id, COUNT(*) AS cuantos FROM alumnos
            WHERE ciudad_nac IS NOT NULL AND deleted_at IS NULL
            GROUP BY ciudad_nac ORDER BY cuantos DESC, ciudad_nac LIMIT 1');
        $this->assertNotNull($enUso, 'El seed no tiene ningún alumno con ciudad de nacimiento.');

        $departamento = DB::table('ciudades')->where('id', $enUso->id)->value('departamento');
        $antes = count($this->withToken($token)
            ->getJson('/api/ciudades/por-departamento/'.rawurlencode($departamento))->json());

        $r = $this->withToken($token)->deleteJson('/api/ciudades/destroy/'.$enUso->id);

        // 200 con la ciudad ya marcada dentro: la respuesta es el modelo después
        // del `delete()`, así que trae el `deleted_at` recién puesto.
        $r->assertStatus(200);
        $this->assertNotNull($r->json('deleted_at'));

        $this->assertSame($antes - 1, count($this->withToken($token)
            ->getJson('/api/ciudades/por-departamento/'.rawurlencode($departamento))->json()),
            'La ciudad borrada sale del catálogo: una menos en su departamento.');

        $this->assertSame([], $this->withToken($token)->getJson('/api/ciudades/datosciudad/'.$enUso->id)->json(),
            'Y su pantalla pasa a contestar lo mismo que una ciudad que no existe.');

        // Lo que no cambia, que es el hallazgo: las fichas siguen apuntando ahí.
        $this->assertSame((int) $enUso->cuantos,
            DB::table('alumnos')->where('ciudad_nac', $enUso->id)->whereNull('deleted_at')->count(),
            'Borrar la ciudad no avisa a nadie: los alumnos nacidos ahí siguen apuntando a la fila borrada.');

        // Y no hay vuelta atrás por la API: el borrado de catálogos no tiene restore.
        $this->assertNotNull(DB::table('ciudades')->where('id', $enUso->id)->value('deleted_at'));
    }

    /** El borrado también deja firma, que es lo que separa un catálogo de un rastro. */
    public function test_borrar_una_ciudad_no_deja_firma(): void
    {
        $token = $this->tokenDelPersonal();
        $ciudad = $this->ciudadDelSeed();

        $this->withToken($token)->deleteJson('/api/ciudades/destroy/'.$ciudad->id)->assertStatus(200);

        $fila = DB::table('ciudades')->where('id', $ciudad->id)->first();
        $this->assertNotNull($fila->deleted_at);

        // `deleted_by` se queda en null: `Ciudad` borra con el `SoftDeletes` de
        // Eloquent, que solo escribe la fecha. Queda escrito porque la columna
        // existe y parece llena, y no lo está. Se anota, no se arregla: rellenarla
        // aquí y no en los otros diez catálogos sería peor que dejarla vacía.
        $this->assertNull($fila->deleted_by,
            'Hoy nadie firma el borrado de una ciudad. Si algún día se firma, este test cae y es a propósito.');
    }
}
