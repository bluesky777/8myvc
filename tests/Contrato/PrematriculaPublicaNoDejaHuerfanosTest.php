<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT login/crear-prematricula` — la única de las once rutas públicas que
 * **escribe**, y la llama alguien **sin cuenta**: un padre rellenando el
 * formulario de prematrícula de su hijo.
 *
 * Escribe en cuatro sitios y no había transacción: `alumnos`, `matriculas`,
 * `users` y la vuelta de `alumnos.user_id`. Con un `grupo_id` que falta o que no
 * existe, **el `INSERT` de `alumnos` ya pasó** y revienta el de `matriculas` —la
 * columna es `NOT NULL` y tiene clave foránea a `grupos`—, así que quedaba
 * escrita la ficha de un menor **con nombres, apellidos, documento y celular**,
 * sin matrícula y sin usuario.
 *
 * **Y el reintento era peor que el fallo.** El segundo intento no daba otro 500:
 * daba un **200 que mentía**. La primera consulta del método encuentra la ficha
 * huérfana y contesta *«Ya existe el alumno. Entre con su cuenta»* — y esa cuenta
 * **nunca se creó**, porque el `INSERT` de `users` va después del que reventó. El
 * padre quedaba fuera del formulario para siempre para ese hijo, mandado a una
 * puerta que no existe y **sin ningún error que reportar**.
 *
 * Aquí se fija el arreglo por los dos lados: **la transacción** —que es lo que
 * hace que no haya huérfano— y **la comprobación del grupo delante de todo**, que
 * es lo que convierte un 500 en un 422 con mensaje. Los dos importan por separado:
 * la transacción cubre cualquier fallo de los cuatro `INSERT`, no sólo el del
 * grupo; el 422 cierra el camino público al 500, que con `APP_DEBUG=true` trae
 * `Host`, `Port` y `Database` en el cuerpo.
 *
 * > **Lo que este arreglo NO hace, y va medido abajo a propósito**: los huérfanos
 * > **ya escritos** en los quince colegios siguen ahí, y para ellos el 200
 * > mentiroso sigue siendo el que sale. Qué hacer con ellos —adoptarlos, crearles
 * > la cuenta, borrarlos— es del colegio, no de una sesión. Contarlos:
 * > `SELECT COUNT(*) FROM alumnos a LEFT JOIN matriculas m ON m.alumno_id=a.id
 * >  WHERE m.id IS NULL AND a.deleted_at IS NULL AND a.user_id IS NULL`.
 *
 * Ver `docs/migracion/05-codigo-muerto-y-roto.md` §236.
 */
class PrematriculaPublicaNoDejaHuerfanosTest extends CasoDeContrato
{
    /** Un nombre que no exista en el seed: la primera consulta busca por los tres campos. */
    private function cuerpo(array $cambios = []): array
    {
        return array_merge([
            'nombres' => 'Prematricula',
            'apellidos' => 'DePrueba'.random_int(100000, 999999),
            'sexo' => 'M',
            'documento' => (string) random_int(1000000000, 1999999999),
            'celular' => '3001234567',
            'grupo_id' => $this->grupoVivo(),
            'year' => (int) DB::table('years')->where('actual', true)->whereNull('deleted_at')->value('year'),
        ], $cambios);
    }

    private function grupoVivo(): int
    {
        $grupo = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN years y ON y.id = g.year_id AND y.deleted_at IS NULL AND y.actual = 1
            WHERE g.deleted_at IS NULL ORDER BY g.id LIMIT 1');

        $this->assertNotNull($grupo, 'El seed necesita un grupo vivo del año actual.');

        return (int) $grupo->id;
    }

    /** Las filas de `alumnos` que este endpoint podría haber creado. */
    private function fichasDe(array $cuerpo): array
    {
        return DB::select('SELECT id, user_id FROM alumnos WHERE nombres=? AND apellidos=? AND documento=?',
            [$cuerpo['nombres'], $cuerpo['apellidos'], $cuerpo['documento']]);
    }

    /**
     * El fallo, tal y como lo produce el formulario: un grupo que no existe.
     *
     * Lo que se comprueba **no es el código de estado**: es que no queda escrita
     * la ficha del menor. El 422 va aparte porque es una decisión distinta.
     */
    public function test_un_grupo_que_no_existe_no_deja_escrita_la_ficha_del_menor(): void
    {
        $cuerpo = $this->cuerpo(['grupo_id' => ((int) DB::table('grupos')->max('id')) + 1000]);

        $this->putJson('/api/login/crear-prematricula', $cuerpo)->assertStatus(422);

        $this->assertSame([], $this->fichasDe($cuerpo),
            'Quedó escrita la ficha de un menor sin matrícula y sin usuario.');
    }

    /** Y el `grupo_id` que no viene, que es la otra mitad de la misma fila de la matriz. */
    public function test_sin_grupo_id_tampoco_escribe(): void
    {
        $cuerpo = $this->cuerpo();
        unset($cuerpo['grupo_id']);

        $this->putJson('/api/login/crear-prematricula', $cuerpo)->assertStatus(422);

        $this->assertSame([], $this->fichasDe($cuerpo));
    }

    /**
     * El reintento, que era la parte peor: un 200 diciendo *«entre con su
     * cuenta»* por una cuenta que no existe.
     *
     * Sin huérfano que encontrar, el segundo intento con el grupo bueno hace lo
     * que el padre pidió la primera vez.
     */
    public function test_el_reintento_con_el_grupo_bueno_crea_de_verdad(): void
    {
        $cuerpo = $this->cuerpo(['grupo_id' => ((int) DB::table('grupos')->max('id')) + 1000]);
        $this->putJson('/api/login/crear-prematricula', $cuerpo);

        $r = $this->putJson('/api/login/crear-prematricula', $this->cuerpo([
            'nombres' => $cuerpo['nombres'],
            'apellidos' => $cuerpo['apellidos'],
            'documento' => $cuerpo['documento'],
        ]));

        $r->assertStatus(200);
        $this->assertStringContainsString('creados', (string) $r->json('estado'),
            'El reintento tiene que crear, no contestar que ya existe: la ficha huérfana ya no está.');

        $fichas = $this->fichasDe($cuerpo);
        $this->assertCount(1, $fichas);
        $this->assertNotNull($fichas[0]->user_id, 'La cuenta a la que el mensaje manda al padre tiene que existir.');
    }

    /**
     * El camino bueno entero, que es lo que la transacción no puede romper:
     * ficha, matrícula `PREA`, usuario activo con rol de Alumno, y la vuelta de
     * `alumnos.user_id`.
     */
    public function test_el_camino_bueno_escribe_las_cuatro_cosas(): void
    {
        $cuerpo = $this->cuerpo();

        $r = $this->putJson('/api/login/crear-prematricula', $cuerpo);
        $r->assertStatus(200);

        $fichas = $this->fichasDe($cuerpo);
        $this->assertCount(1, $fichas, 'Una ficha, y sólo una.');
        $alumno = $fichas[0];

        $matricula = DB::selectOne('SELECT grupo_id, estado, nuevo FROM matriculas WHERE alumno_id=?', [$alumno->id]);
        $this->assertNotNull($matricula, 'Sin matrícula, la ficha es justo el huérfano que este test persigue.');
        $this->assertSame($cuerpo['grupo_id'], (int) $matricula->grupo_id);
        $this->assertSame('PREA', $matricula->estado);

        $this->assertNotNull($alumno->user_id);
        $usuario = DB::selectOne('SELECT username, tipo, is_active FROM users WHERE id=?', [$alumno->user_id]);
        $this->assertSame('Alumno', $usuario->tipo);
        $this->assertSame(1, (int) $usuario->is_active);
        $this->assertSame(1, (int) DB::table('role_user')->where('user_id', $alumno->user_id)->count(),
            'Sin el rol, la cuenta recién creada no resuelve contexto y no entra.');

        // La contraseña se devuelve en el mensaje porque no se vuelve a mostrar.
        $this->assertStringContainsString($usuario->username, (string) $r->json('estado'));
    }

    /**
     * **El control, y sin él este fichero no prueba la transacción.**
     *
     * Los dos primeros tests pasarían sólo con la comprobación del grupo, que
     * frena antes del primer `INSERT`: un verde ahí no distingue *«la transacción
     * funciona»* de *«no se llegó a escribir nada»*. Hace falta un fallo que ocurra
     * **después** del `INSERT` de `alumnos`, y éste lo es: sin el rol `Alumno`, el
     * `$role[0]['id']` revienta cuando ya están escritos la ficha, la matrícula y
     * el usuario.
     *
     * No es un caso rebuscado: es el estado en el que quedó un colegio si alguien
     * renombró el rol. Y da igual el porqué — lo que fija este test es que **la
     * ficha del menor no sobrevive a un fallo a mitad**, venga de donde venga.
     */
    public function test_un_fallo_a_media_escritura_tampoco_deja_la_ficha(): void
    {
        $cuerpo = $this->cuerpo();

        // Dentro de la transacción del test: se deshace al terminar.
        DB::table('roles')->where('name', 'Alumno')->update(['name' => 'AlumnoRenombrado']);

        try {
            $this->putJson('/api/login/crear-prematricula', $cuerpo);
        } catch (\Throwable $e) {
            // El 500 es el punto de partida del control, no lo que se mide.
        }

        $this->assertSame([], $this->fichasDe($cuerpo),
            'Reventó después del INSERT de `alumnos` y la ficha se quedó: la transacción no está haciendo su trabajo.');
    }

    /**
     * Un grupo **en la papelera** también se para, y es el único estrechamiento
     * que trae el arreglo: antes pasaba —la clave foránea sólo mira que el id
     * exista— y dejaba la prematrícula colgada de un grupo borrado, donde no la ve
     * nadie. Se fija aquí para que se vea que es a propósito.
     */
    public function test_un_grupo_en_la_papelera_tambien_se_para(): void
    {
        $cuerpo = $this->cuerpo();

        DB::table('grupos')->where('id', $cuerpo['grupo_id'])->update(['deleted_at' => now()]);

        $this->putJson('/api/login/crear-prematricula', $cuerpo)->assertStatus(422);

        $this->assertSame([], $this->fichasDe($cuerpo));
    }

    /**
     * Y la fila de la matriz que ya estaba bien, medida para que se vea que **el
     * daño no era «cuerpo incompleto»**: sin `nombres` la columna es `NOT NULL` y
     * el primer `INSERT` no llega a pasar, así que nunca hubo huérfano por ahí.
     */
    public function test_sin_nombres_no_escribia_nada_ni_antes(): void
    {
        $cuerpo = $this->cuerpo();
        unset($cuerpo['nombres']);

        $this->putJson('/api/login/crear-prematricula', $cuerpo);

        $this->assertSame(0, (int) DB::table('alumnos')->where('documento', $cuerpo['documento'])->count());
    }
}
