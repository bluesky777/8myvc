<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use App\Support\CandadoDeLaPlantilla;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **P6: lo que el colegio puso en la plantilla no lo cambia un docente.**
 *
 * Decidido por Joseth el 19 sep 2026, y es la pieza más delicada del modelo de
 * evaluación porque **es la única que le quita a un docente algo que hoy hace**
 * en los dieciséis colegios. El agujero estaba medido: `PUT unidades/update/{id}`
 * lleva sólo `auth.personal`, así que cualquier docente podía renombrar y
 * recambiar el porcentaje de una fila sembrada desde `unidades_por_defecto`.
 *
 * ## Qué existe esto para cazar, y ninguno de los cuatro da error solo
 *
 * **1. Que se quite el candado del controlador.** Es la lección de
 * `putTonoDocente` y de `PlantillaNotasTest`: el criterio vive **dentro** del
 * método, no en la ruta, así que borrarlo no pone nada en rojo por sí mismo.
 *
 * **2. Que el candado se vuelva un muro.** Añadir subunidades dentro de una
 * unidad del colegio **es el trabajo del docente** (D14). Un candado que también
 * frene eso deja la pantalla inservible y el síntoma en el colegio es *«no puedo
 * poner mis logros»* — peor que el agujero que viene a tapar.
 *
 * **3. Que el candado mire la PRESENCIA del campo en vez de su VALOR.** El front
 * reenvía el formulario entero, así que un candado que salte porque *«vino
 * `porcentaje`»* contesta 403 a quien no cambió nada. Se vería como una avería, y
 * el arreglo evidente —quitar el candado— reabre el agujero.
 *
 * **4. Que se cuele por la puerta de al lado.** `unidades/update-orden` no escribe
 * ni nombre ni porcentaje: lo único que puede hacer con una fila del colegio es
 * **moverla de sitio**. P6 nombra esa ruta, así que se frena eso — y aquí queda
 * fijado, porque es una interpretación de P6 y no una lectura literal.
 *
 * ## Lo que este test NO cubre, dicho para que no se lea de más
 *
 * El «volver a aplicar» de P6 **no está hecho**: Joseth lo dejó fuera del alcance
 * el 19 sep. Y la decisión de qué hacer con las asignaturas que ya tienen notas
 * —actualizar nombre y porcentaje igual— está tomada y escrita, pero no
 * implementada, así que aquí no hay nada suyo que fijar.
 */
class CandadoDeLaPlantillaTest extends CasoDeContrato
{
    /**
     * Con el candado suspendido estos casos no miden nada, y borrarlos sería
     * tener que volver a escribirlos.
     *
     * Se saltan **leyendo el interruptor**, no a mano: el día que
     * `CandadoDeLaPlantilla::SUSPENDIDO` vuelva a `false`, vuelven solos. El
     * porqué de la suspensión está en la cabecera de esa constante.
     */
    protected function setUp(): void
    {
        parent::setUp();

    }

    /**
     * Un docente llano, su periodo abierto y una asignatura suya.
     *
     * El periodo se abre **a propósito**: si estuviera cerrado, todos los 403 de
     * aquí abajo serían de `pueden_editar_notas` y el test pasaría entero con el
     * candado borrado. Es el mismo cuidado que pide `PlantillaNotasTest` con
     * `is_superuser`.
     */
    private function escenario(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');

        $this->assertSame(0, (int) $prof->is_superuser,
            'El sujeto de este test NO puede ser superusuario: con la columna puesta, '.
            'el candado lo dejaría pasar y el rojo no llegaría nunca.');

        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suyo->year_id]);

        $this->assertNotNull($asignatura, 'El seed no tiene asignaturas en el año del profesor.');

        return (object) [
            'usuario' => $prof,
            'token' => $token,
            'periodo' => (int) $suyo->id,
            'year_id' => (int) $suyo->year_id,
            'asignatura' => (int) $asignatura->id,
        ];
    }

    /** Una unidad en la asignatura. `$delColegio` es lo que decide `por_defecto`. */
    private function unidad(object $e, bool $delColegio, array $campos = []): int
    {
        return (int) DB::table('unidades')->insertGetId($campos + [
            'definicion' => $delColegio ? 'Cognitivo (del colegio)' : 'Mi unidad',
            'porcentaje' => 60,
            'periodo_id' => $e->periodo,
            'asignatura_id' => $e->asignatura,
            'orden' => 0,
            'por_defecto' => $delColegio ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function subunidad(int $unidadId, bool $delColegio): int
    {
        return (int) DB::table('subunidades')->insertGetId([
            'definicion' => $delColegio ? 'Taller (del colegio)' : 'Mi logro',
            'porcentaje' => 100,
            'unidad_id' => $unidadId,
            'nota_default' => 0,
            'orden' => 0,
            'por_defecto' => $delColegio ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function filaDeUnidad(int $id): object
    {
        return DB::selectOne('SELECT definicion, porcentaje, orden FROM unidades WHERE id = ?', [$id]);
    }

    // ─── Lo que el candado frena ────────────────────────────────────────────────

    #[Test]
    public function test_un_docente_no_le_cambia_el_nombre_a_una_unidad_del_colegio(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['definicion' => 'Como a mí me gusta'])
            ->assertStatus(403);

        $this->assertSame('Cognitivo (del colegio)', $this->filaDeUnidad($id)->definicion,
            'Contestó 403 y escribió igual: el candado frena la respuesta pero no la escritura.');
    }

    /**
     * **El caso que más duele de los cuatro**, porque no se ve: el porcentaje de la
     * unidad es el factor de fuera de la definitiva —`(u.porcentaje/100) * …`— y
     * cambiarlo **recalcula la asignatura entera** diez líneas más abajo, en el
     * mismo método. Un docente moviendo el 60 al 90 movía las notas de su curso.
     */
    #[Test]
    public function test_un_docente_no_le_cambia_el_porcentaje_a_una_unidad_del_colegio(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['porcentaje' => 90])
            ->assertStatus(403);

        $this->assertSame(60, (int) $this->filaDeUnidad($id)->porcentaje);
    }

    #[Test]
    public function test_el_candado_vale_igual_para_una_subunidad_del_colegio(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e, delColegio: true);
        $sub = $this->subunidad($unidad, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/subunidades/update/'.$sub, ['definicion' => 'Otro nombre'])
            ->assertStatus(403);

        $this->assertSame('Taller (del colegio)',
            DB::table('subunidades')->where('id', $sub)->value('definicion'));
    }

    /**
     * La puerta de al lado. Ver el punto 4 del docblock de arriba: es la única
     * cosa que `update-orden` puede hacerle a una fila del colegio.
     */
    #[Test]
    public function test_un_docente_no_mueve_de_sitio_una_unidad_del_colegio(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true, campos: ['orden' => 0]);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update-orden', ['sortHash' => [[$id => 5]]])
            ->assertStatus(403);

        $this->assertSame(0, (int) $this->filaDeUnidad($id)->orden);
    }

    /**
     * **El lote no se escribe a medias**, y este caso es el que lo dice.
     *
     * Lo encontró `8myvc-47` comparando esta clase contra su propia implementación
     * de lo mismo, escrita en paralelo y sin vernos. La primera versión preguntaba
     * **dentro** del bucle, justo antes de cada `save()`: un lote que llevara
     * primero una unidad del docente y después una del colegio dejaba **la primera
     * ya guardada** y abortaba con 403 en la segunda.
     *
     * Es el invariante que `putUpdateOrden` ya respetaba a propósito para el
     * periodo —`pueden_editar_notas` está FUERA del bucle— y que su gemelo de
     * subunidades deja escrito: *«basta que una esté en periodo cerrado para que no
     * pase ninguna: escribir la mitad de un reordenado es peor que no escribir
     * nada»* (§27).
     *
     * **Lo que hay que mirar cuando esto se ponga rojo no es el 403** —ése es
     * fácil— **sino la última aserción**: que la unidad del docente NO se movió.
     */
    #[Test]
    public function test_un_lote_rechazado_no_deja_movida_la_que_iba_delante(): void
    {
        $e = $this->escenario();
        $mia = $this->unidad($e, delColegio: false, campos: ['orden' => 1]);
        $delColegio = $this->unidad($e, delColegio: true, campos: ['orden' => 2]);

        // La del docente va PRIMERA a propósito: con la comprobación dentro del
        // bucle, para cuando se mira la del colegio ésta ya está guardada.
        $this->withToken($e->token)
            ->putJson('/api/unidades/update-orden', ['sortHash' => [[$mia => 6], [$delColegio => 7]]])
            ->assertStatus(403);

        $this->assertSame(2, (int) $this->filaDeUnidad($delColegio)->orden,
            'Movió la del colegio, que es lo que el candado venía a impedir.');

        $this->assertSame(1, (int) $this->filaDeUnidad($mia)->orden,
            'El lote se escribió A MEDIAS: la del docente se movió y la del colegio no. '.
            'La comprobación está DENTRO del bucle; tiene que ir antes.');
    }

    /**
     * **Un `null` explícito no es «no vino»: es borrar.**
     *
     * Medido en el contenedor con un cuerpo `{"porcentaje": null}`:
     *
     *     Request::input('porcentaje', 70)  ->  NULL
     *     array_key_exists('porcentaje')    ->  true
     *
     * O sea que el defecto de `input()` **no tapa el null que llega escrito**, y un
     * candado que lo tratara como «no cambia» dejaría pasar justo la escritura más
     * destructiva de las tres que puede recibir el campo. El segundo hallazgo de
     * `8myvc-47`, y el que más duele: el porcentaje de la unidad es el factor de
     * fuera de la definitiva, que este mismo método recalcula unas líneas después.
     */
    #[Test]
    public function test_un_null_explicito_no_se_cuela_por_el_candado(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['porcentaje' => null])
            ->assertStatus(403);

        $this->assertSame(60, (int) $this->filaDeUnidad($id)->porcentaje,
            'El null explícito pasó el candado y borró el peso de una unidad del colegio.');
    }

    /**
     * **Y la otra cara, que es la que casi me lleva por delante una decisión.**
     *
     * Aquí la unidad es **del docente**, así que el candado ni la mira: mandar
     * `null` **vacía el porcentaje y contesta 200**, y eso NO es un agujero — es una
     * decisión escrita y fijada por otros dos tests
     * (`PorcentajeQueSePisaTest::test_mandar_null_a_proposito_si_borra_el_porcentaje`
     * y `UnidadesTest::test_un_cero_es_un_cero_y_un_null_es_un_null`), con su tabla
     * de las dos formas al lado: **no mandar un campo y mandarlo vacío no son la
     * misma petición**; lo segundo es un cliente diciendo «quítalo».
     *
     * El 19 sep 2026 se propuso «arreglarlo» cambiando el defecto de `input()` por
     * un `??` en los dos controladores, creyendo que era el mismo agujero que el del
     * candado. **Esos dos tests lo pararon**, que es literalmente para lo que
     * existen. Este caso se queda aquí, al lado del que sí frena, para que el
     * contraste esté escrito donde alguien lo vuelva a ver: **el candado no está
     * para impedir que se vacíe un campo, está para impedir que se toque lo que es
     * del colegio.**
     */
    #[Test]
    public function test_el_docente_si_puede_vaciar_el_peso_de_lo_suyo(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: false);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['porcentaje' => null])
            ->assertStatus(200);

        $this->assertNull($this->filaDeUnidad($id)->porcentaje,
            'Mandar null sobre lo suyo es pedir que se quite el peso, y tiene que quitarse.');
    }

    /**
     * **`"70.00"` y `70` son el mismo peso**, y compararlos como texto daba un 403
     * a quien no había cambiado nada — que es el modo de fallo que esta clase
     * entera intenta evitar. Lo apuntó `8myvc-47` de paso.
     */
    #[Test]
    public function test_el_mismo_numero_escrito_distinto_no_es_un_cambio(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['porcentaje' => '60.00'])
            ->assertStatus(200);
    }

    // ─── Lo que el candado NO frena, que es la mitad que lo hace usable ─────────

    /**
     * **Reenviar el mismo valor no es cambiarlo.**
     *
     * El front manda el formulario entero, así que esto es lo que pasa cada vez
     * que el docente guarda cualquier otra cosa de la fila. Con un candado que
     * mirase la presencia del campo, esto sería 403 y la pantalla parecería rota.
     */
    #[Test]
    public function test_reenviar_los_mismos_valores_no_es_un_403(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: true);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, [
                'definicion' => 'Cognitivo (del colegio)',
                'porcentaje' => 60,
            ])
            ->assertStatus(200);
    }

    /**
     * Y lo mismo con el orden: el cliente manda la rejilla **entera** cuando el
     * docente mueve una fila suya, así que la del colegio viaja en la lista sin
     * moverse. Si eso fuera 403, reordenar lo propio sería imposible.
     */
    #[Test]
    public function test_reordenar_lo_propio_manda_la_rejilla_entera_y_pasa(): void
    {
        $e = $this->escenario();
        $delColegio = $this->unidad($e, delColegio: true, campos: ['orden' => 0]);
        $mia = $this->unidad($e, delColegio: false, campos: ['orden' => 1]);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update-orden', ['sortHash' => [[$delColegio => 0, $mia => 2]]])
            ->assertStatus(200);

        $this->assertSame(0, (int) $this->filaDeUnidad($delColegio)->orden);
        $this->assertSame(2, (int) $this->filaDeUnidad($mia)->orden);
    }

    #[Test]
    public function test_su_propia_unidad_la_cambia_como_siempre(): void
    {
        $e = $this->escenario();
        $id = $this->unidad($e, delColegio: false);

        $this->withToken($e->token)
            ->putJson('/api/unidades/update/'.$id, ['definicion' => 'Renombrada', 'porcentaje' => 40])
            ->assertStatus(200);

        $fila = $this->filaDeUnidad($id);
        $this->assertSame('Renombrada', $fila->definicion);
        $this->assertSame(40, (int) $fila->porcentaje);
    }

    /**
     * **D14, y es la razón de que el candado sea de dos campos y no de la fila.**
     * El colegio pone las unidades; el docente cuelga sus logros dentro. Si esto
     * fuera 403, el candado habría cambiado un agujero por una pantalla muerta.
     */
    #[Test]
    public function test_anadir_una_subunidad_dentro_de_una_unidad_del_colegio_sigue_siendo_suyo(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e, delColegio: true);

        $r = $this->withToken($e->token)->postJson('/api/subunidades', [
            'unidad_id' => $unidad,
            'definicion' => 'Logro que pongo yo',
            'porcentaje' => 50,
        ]);

        $this->assertNotSame(403, $r->getStatusCode(),
            'El docente tiene que poder colgar sus logros de una unidad del colegio: es D14.');
    }

    /**
     * **El caso que demuestra que el permiso se lee de verdad**, y va sin
     * `is_superuser` por lo mismo que en `PlantillaNotasTest`: con la columna
     * puesta pasaría aunque el candado mirase cualquier otra cosa.
     *
     * El permiso se da **por rol**, que es como llega al contexto de verdad, y el
     * token se pide **después** — `ContextoDeUsuario` resuelve `perms` una vez por
     * proceso, así que pedirlo antes mediría el `perms` vacío.
     */
    #[Test]
    public function test_quien_manda_en_la_plantilla_si_la_cambia(): void
    {
        $prof = $this->usuarioDeTipo('Profesor');

        $this->assertSame(0, (int) $prof->is_superuser);

        /*
         * **El orden de estas tres líneas es el test.** El permiso va ANTES del
         * token porque `ContextoDeUsuario` resuelve `perms` una vez por proceso; y
         * el periodo se lee DESPUÉS del token porque `Services\Login` reescribe
         * `users.periodo_id` al entrar. Leerlo antes deja `$suyo` en null —el
         * `INNER JOIN` no casa— y el test revienta en un sitio que no tiene nada
         * que ver con el candado. Es la misma nota que lleva `UnidadesTest`.
         */
        $this->darPermisoDePlantilla((int) $prof->id);
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suyo->year_id]);

        $id = (int) DB::table('unidades')->insertGetId([
            'definicion' => 'Cognitivo (del colegio)', 'porcentaje' => 60,
            'periodo_id' => (int) $suyo->id, 'asignatura_id' => (int) $asignatura->id,
            'orden' => 0, 'por_defecto' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->putJson('/api/unidades/update/'.$id, ['definicion' => 'Corregido por coordinación'])
            ->assertStatus(200);

        $this->assertSame('Corregido por coordinación', $this->filaDeUnidad($id)->definicion);
    }

    /**
     * El permiso por rol. Calcado de `PlantillaNotasTest::darPermisoDePlantilla`, y
     * por el mismo motivo: `test-seed.sql` hace `TRUNCATE` de `permissions`, así
     * que lo que siembre la migración **no sobrevive a construir la base**.
     */
    private function darPermisoDePlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'JefeDeAreaDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'JefeDeAreaDePrueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        if (! DB::table('permission_role')->where('permission_id', $permiso)->where('role_id', $rol)->exists()) {
            DB::table('permission_role')->insert(['permission_id' => $permiso, 'role_id' => $rol]);
        }

        if (! DB::table('role_user')->where('user_id', $userId)->where('role_id', $rol)->exists()) {
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rol]);
        }
    }
}
