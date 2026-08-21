<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las frases del boletín de preescolar.
 *
 * Segundo hueco de `routes/api/informes.php`: la cobertura daba **2 de 5** para
 * `BolfinalesPreescolarController`, y las tres sin comprobar son justo las que
 * escriben — `crear-frase`, `guardar-frase` y `eliminar-frase`.
 *
 * En preescolar el boletín no lleva notas: lleva **frases**. Lo que se escribe
 * aquí es el texto que sale impreso en el informe de un niño de cinco años, y lo
 * redacta el profesor por asignatura.
 *
 * Las tres llevan `auth.personal`, o sea los 51 profesores. Ninguna de las tres
 * comprueba de quién es la asignatura ni si la frase existe.
 *
 * `frases_preescolar` está vacía en el seed, así que las frases las monta el
 * test y la transacción las deshace.
 */
class FrasesPreescolarTest extends CasoDeContrato
{
    private function personal(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene personal en el año {$grupo->year_id}.");

        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL
                                     ORDER BY id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($asignatura, 'El grupo del seed no tiene asignaturas.');

        return (object) [
            'user_id' => (int) $usuario->id,
            'asignatura_id' => (int) $asignatura->id,
            'grupo_id' => (int) $grupo->id,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    private function unaFrase(int $asignaturaId, string $texto = 'Comparte con sus compañeros'): int
    {
        return DB::table('frases_preescolar')->insertGetId([
            'asignatura_id' => $asignaturaId,
            'definicion' => $texto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Crear devuelve la fila recién hecha, con la definición vacía para que la escriba la pantalla. */
    public function test_crear_devuelve_la_frase_vacia(): void
    {
        $personal = $this->personal();

        $frase = $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/crear-frase', ['asignatura_id' => $personal->asignatura_id])
            ->assertStatus(200)
            ->json();

        $this->assertSame($personal->asignatura_id, (int) $frase['asignatura_id']);
        $this->assertSame('', $frase['definicion']);
        $this->assertSame(1, DB::table('frases_preescolar')->where('id', $frase['id'])->count());
    }

    /**
     * **«Cambiada» sobre una frase que no existe**, y no cambia nada.
     *
     * `putGuardarFrase()` hace un `UPDATE ... WHERE id=?` a pelo y devuelve la
     * cadena `'Cambiada'` pase lo que pase. Con un id que no existe, MySQL
     * actualiza cero filas y el cliente recibe un 200 diciendo que sí.
     *
     * Es la familia de «una respuesta que miente» —la de
     * `tools/respuestas-que-mienten.py`— pero por una vía que ese detector no
     * mira: **no hay ningún `if` de permiso aquí**. No es que la comprobación
     * falte y el método siga; es que **nunca se mira el resultado de la
     * escritura**. `DB::update()` devuelve el número de filas afectadas y nadie
     * lo lee.
     *
     * Ver 14-certificados.md §7.
     */
    public function test_guardar_una_frase_que_no_existe_dice_cambiada(): void
    {
        $personal = $this->personal();

        $inventada = ((int) DB::table('frases_preescolar')->max('id')) + 1000;

        $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/guardar-frase', [
                'id' => $inventada,
                'asignatura_id' => $personal->asignatura_id,
                'definicion' => 'Texto que no se guarda en ninguna parte',
            ])
            ->assertStatus(200)
            ->assertSee('Cambiada');

        $this->assertSame(0, DB::table('frases_preescolar')->where('id', $inventada)->count(),
            'No se creó ni se cambió nada, y respondió que sí.');
    }

    /** Y «ELIMINADA» sobre una que no existe, por lo mismo. */
    public function test_eliminar_una_frase_que_no_existe_dice_eliminada(): void
    {
        $personal = $this->personal();

        $inventada = ((int) DB::table('frases_preescolar')->max('id')) + 1000;
        $antes = DB::table('frases_preescolar')->count();

        $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/eliminar-frase', ['id' => $inventada])
            ->assertStatus(200)
            ->assertSee('ELIMINADA');

        $this->assertSame($antes, DB::table('frases_preescolar')->count());
    }

    /**
     * El borrado es **físico**, y esta tabla no tiene papelera.
     *
     * `DELETE FROM frases_preescolar WHERE id=?`, sin `deleted_at` — y la columna
     * no existe en el esquema, así que **no es un descuido del controlador sino
     * de la tabla**: se diseñó sin papelera.
     *
     * Se fija porque en este proyecto **casi todo lo demás va a la papelera**, y
     * lo que se borra aquí es texto que escribió un profesor. Quien pulse
     * «eliminar» por error no tiene de dónde recuperarlo, y el resto de pantallas
     * del sistema le han enseñado que sí.
     *
     * No se cambia: añadir `deleted_at` es una migración y una decisión, no un
     * arreglo. Lo que hacía falta es que estuviera escrito.
     */
    public function test_el_borrado_es_fisico_y_no_hay_papelera(): void
    {
        $personal = $this->personal();
        $id = $this->unaFrase($personal->asignatura_id);

        $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/eliminar-frase', ['id' => $id])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('frases_preescolar')->where('id', $id)->count(),
            'La fila ya no está: no queda en la papelera porque no hay papelera.');

        $columnas = DB::select('SHOW COLUMNS FROM frases_preescolar LIKE "deleted_at"');

        $this->assertEmpty($columnas,
            'Si alguien añadió `deleted_at`, este test sobra y hay que mirar el controlador.');
    }

    /**
     * Guardar mueve la frase a la asignatura que diga el cuerpo.
     *
     * `putGuardarFrase()` escribe `asignatura_id` además de la definición, así que
     * la misma llamada que corrige una errata **puede reasignar la frase a otra
     * asignatura** — la de otro profesor, la de otro grupo, la de otro año.
     *
     * Nadie comprueba de quién es ninguna de las dos. Es la §2 de
     * [13-actividades.md](13-actividades.md) otra vez, y va con ella: **de quién
     * es** se puede saber y **quién puede tocarlo** no está decidido.
     */
    public function test_guardar_mueve_la_frase_a_otra_asignatura(): void
    {
        $personal = $this->personal();
        $id = $this->unaFrase($personal->asignatura_id);

        $otra = $this->grupoAjenoDelMismoAnio(
            (int) DB::table('grupos')->where('id', $personal->grupo_id)->value('year_id')
        );

        $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/guardar-frase', [
                'id' => $id,
                'asignatura_id' => $otra->asignatura_id,
                'definicion' => 'Sigue siendo la misma frase',
            ])
            ->assertStatus(200);

        $this->assertSame(
            $otra->asignatura_id,
            (int) DB::table('frases_preescolar')->where('id', $id)->value('asignatura_id'),
            'La frase acabó colgando de una asignatura que no era la suya.'
        );
    }

    /**
     * Crear con una asignatura que no existe es 500, y lo para el esquema.
     *
     * `frases_preescolar` sí lleva `FOREIGN KEY` a `asignaturas`. El controlador
     * no comprueba nada; quien dice que no es la base.
     *
     * Es el mismo reparto que en [13-actividades.md §4](13-actividades.md), y en
     * este dominio hace de contraste con la §1 de
     * [14-certificados.md](14-certificados.md), donde `years` **no** lleva la
     * clave y por eso un año puede quedar apuntando a un membrete inexistente.
     * **Dos tablas del mismo módulo, una con red y otra sin ella.**
     */
    public function test_crear_con_una_asignatura_que_no_existe_es_500(): void
    {
        $personal = $this->personal();

        $inventada = ((int) DB::table('asignaturas')->max('id')) + 1000;

        $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/crear-frase', ['asignatura_id' => $inventada])
            ->assertStatus(500);
    }

    /**
     * Y `updated_by` no la escribe nadie.
     *
     * La columna existe en `frases_preescolar` y los tres métodos resuelven el
     * usuario —`$user = User::fromToken()`— y no la usan para nada. Así que del
     * texto que sale impreso en el boletín de un niño **no queda registro de quién
     * lo escribió**.
     *
     * Es la cuarta columna de la serie que no guarda lo que dice su nombre, y la
     * primera que no guarda **nada**. Ver 14-certificados.md §5.
     */
    public function test_updated_by_se_queda_vacia(): void
    {
        $personal = $this->personal();

        $frase = $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/crear-frase', ['asignatura_id' => $personal->asignatura_id])
            ->assertStatus(200)
            ->json();

        $this->withToken($personal->token)
            ->putJson('/api/bolfinales-preescolar/guardar-frase', [
                'id' => $frase['id'],
                'asignatura_id' => $personal->asignatura_id,
                'definicion' => 'Escrita por alguien que no queda anotado',
            ])
            ->assertStatus(200);

        $this->assertNull(
            DB::table('frases_preescolar')->where('id', $frase['id'])->value('updated_by'),
            'Ni al crear ni al guardar se anota quién fue.'
        );
    }
}
