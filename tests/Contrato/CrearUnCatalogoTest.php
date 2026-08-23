<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Crear un catálogo del colegio: nueve rutas, el mismo gesto, cuatro respuestas.
 *
 * La [§70](../../docs/migracion/05-codigo-muerto-y-roto.md) midió **borrar** un
 * catálogo —qué se lleva por delante y quién puede llamarlo— y ahí se quedó. La
 * otra mitad de cada pareja, crear y editar, no la había mirado nadie: son veinte
 * rutas repartidas en trece controladores, y la pregunta que las junta es la más
 * simple que hay. **¿Qué contesta un catálogo cuando le mandas el cuerpo vacío?**
 *
 * La respuesta es que contesta de cuatro maneras distintas, y lo que las separa
 * **no es el código de los controladores** —los nueve son igual de crédulos: leen
 * `Request::input(...)` y llaman a `save()`, sin una sola validación— **sino el
 * esquema**:
 *
 *   - cinco dan **422** porque su `try/catch` traduce el fallo del `INSERT`;
 *   - tres dan **500** con el error de integridad de MySQL, por no tener ese
 *     `try/catch`;
 *   - uno da **500** por un error de PHP antes de llegar a la base;
 *   - y `contratos` **escribía la fila y contestaba 200**, porque es el único de
 *     los nueve cuya tabla no tiene ninguna columna `NOT NULL`.
 *
 * > **Lo que impide que ocho de los nueve escriban basura no es el código: es el
 * > esquema.** Es la misma forma que `SubunidadCreaLasNotasQueFaltanTest`, y la razón por la
 * > que la ausencia de validaciones no se nota — hasta que una tabla se define
 * > toda nulable.
 *
 * Este test no unifica nada: fija la tabla tal como está, para que unificarla sea
 * una decisión y no un efecto. Va al §5 de 09.
 */
class CrearUnCatalogoTest extends CasoDeContrato
{
    /**
     * Nadie escribe una fila con el cuerpo vacío, y cada uno lo dice a su manera.
     *
     * Lo que se afirma de verdad es la **última columna**: que ninguna de las nueve
     * deje una fila detrás. El código de estado se fija al lado porque es lo que
     * llega al front, y porque un 500 y un 422 no se leen igual: el front pinta el
     * mensaje del cuerpo, y el de un 500 de MySQL es el `SQLSTATE` entero.
     */
    public function test_ningun_catalogo_escribe_una_fila_con_el_cuerpo_vacio(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        // Medido el 22 ago 2026. El estado no es lo que debería ser, es lo que es:
        // cambiar cualquiera de estos números es cambiarle la respuesta a un front
        // que ya está desplegado en dieciséis colegios.
        $catalogos = [
            'areas' => ['POST', 'areas', 422],
            'grados' => ['POST', 'grados/store', 422],
            'niveles_educativos' => ['POST', 'niveles_educativos/store', 422],
            'tipos_documentos' => ['POST', 'tiposdocumento', 422],
            'ciudades' => ['POST', 'ciudades/guardar-ciudad', 422],
            'paises' => ['POST', 'paises/store', 500],
            'frases' => ['POST', 'frases/store', 500],
            'definiciones_comportamiento' => ['POST', 'definiciones_comportamiento/store', 500],
            'materias' => ['POST', 'materias', 500],
            'contratos' => ['POST', 'contratos', 422],
        ];

        foreach ($catalogos as $tabla => [$verbo, $ruta, $esperado]) {
            $antes = $this->filasDe($tabla);

            $r = $this->withToken($token)->json($verbo, '/api/'.$ruta, []);

            $this->assertSame($esperado, $r->status(),
                "`{$ruta}` con el cuerpo vacío ha cambiado de respuesta. Si es porque se le puso "
                .'una validación, hay que cambiarlo aquí y mirar qué hace el front con el código '
                .'nuevo; si no, es una regresión.');

            $this->assertSame($antes, $this->filasDe($tabla),
                "`{$ruta}` escribió una fila con el cuerpo vacío.");
        }
    }

    /**
     * Contratar a un profesor que no existe no deja un contrato huérfano.
     *
     * Era el único de los nueve que escribía, y el porqué está en el esquema:
     * `contratos` no tiene ninguna columna `NOT NULL`, así que el `INSERT` con todo
     * a nulo entraba. El `SELECT` de después une por `profesores` y no encontraba
     * nada, o sea **200 con `[]`** — y `ProfesoresCtrl` trata esa respuesta
     * enseñando «contratado para este año» y no tocando las rejillas, con un
     * comentario que dice que sería «un backend distinto del documentado».
     *
     * La pantalla decía que sí y aquí quedaba una fila sin profesor, invisible
     * desde cualquier pantalla y por tanto imposible de quitar.
     *
     * En producción hay **cero huérfanos de 164 contratos**, así que era una mina:
     * el front siempre manda un id bueno. Se comprueba con un id que no existe y no
     * con el cuerpo vacío, porque es el caso que un cliente puede provocar de
     * verdad — una rejilla desactualizada apuntando a un profesor ya borrado.
     */
    public function test_contratar_a_un_profesor_que_no_existe_no_deja_fila(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $inexistente = (int) DB::selectOne('SELECT IFNULL(MAX(id),0) + 1000 n FROM profesores')->n;
        $antes = $this->filasDe('contratos');

        $this->withToken($token)->postJson('/api/contratos', ['profesor_id' => $inexistente])
            ->assertStatus(422);

        $this->assertSame($antes, $this->filasDe('contratos'),
            'Quedó un contrato sin profesor: no se ve desde ninguna pantalla, así que tampoco se puede quitar.');
    }

    /**
     * Y contratar a uno que sí existe sigue funcionando, con su forma de siempre.
     *
     * La otra mitad, y no es de adorno: el arreglo de arriba consulta `profesores`
     * antes de escribir, y una consulta mal escrita ahí apagaría el botón
     * «Contratar» en dieciséis colegios. Se comprueba además **la forma** —un array
     * de un elemento con `contrato_id` dentro— porque es lo que `ProfesoresCtrl`
     * mete en la rejilla: `r[0].contrato_id`.
     */
    public function test_contratar_a_un_profesor_de_verdad_sigue_devolviendo_su_fila(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $year = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id])->year_id;

        // Uno sin contrato en ese año: con contrato la ruta contesta 400 y el test
        // mediría el rechazo en vez del alta.
        $libre = DB::selectOne('SELECT p.id FROM profesores p
            WHERE p.deleted_at IS NULL AND p.id NOT IN (
                SELECT c.profesor_id FROM contratos c
                WHERE c.year_id = ? AND c.profesor_id IS NOT NULL AND c.deleted_at IS NULL)
            ORDER BY p.id LIMIT 1', [$year]);

        $this->assertNotNull($libre, 'El seed necesita un profesor sin contratar en el año del usuario.');

        $r = $this->withToken($token)->postJson('/api/contratos', ['profesor_id' => $libre->id]);

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertCount(1, $cuerpo, 'La ruta devuelve un array de un elemento y el front lee `r[0]`.');
        $this->assertSame((int) $libre->id, (int) $cuerpo[0]['profesor_id']);
        $this->assertArrayHasKey('contrato_id', $cuerpo[0],
            'El front mete este valor en la rejilla de contratados; sin él la fila entra rota.');
        $this->assertSame((int) $year, (int) $cuerpo[0]['year_id'],
            'El contrato se guardó en un año que no es el del token.');
    }

    private function filasDe(string $tabla): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) n FROM {$tabla}")->n;
    }
}
