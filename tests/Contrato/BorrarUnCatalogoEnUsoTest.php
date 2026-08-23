<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §70 y lote B — borrar un catálogo al que otra fila apunta se impide, y el
 * aviso dice cuántas dependen.
 *
 * **Decisión de Joseth, 23 ago 2026.** Las dos que se cierran son las dos que
 * tenían el daño medido:
 *
 *   grados        un clic en «eliminar» apagaba la planilla de todos los profesores
 *                 del grado —`Profesor::asignaturas` une por `inner join`— y desde
 *                 administración no se veía nada raro, porque la rejilla de grupos
 *                 une por el mismo grado sin ese filtro. Y no hay `restore` (§70.3).
 *
 *   dis_ordinales la falta seguía en el observador **sin el artículo que dice qué se
 *                 incumplió**: `left join` deja hueco, y aquí el hueco es el contenido.
 *
 * **Lo que este test afirma es la pareja completa**, y por eso las dos mitades van
 * en el mismo caso: que **con** dependencias corta con 422 *y no escribe*, y que
 * **sin** dependencias sigue borrando igual. La primera sola dejaría pasar un
 * candado que bloquea siempre, que es la forma más fácil de «arreglar» esto y la
 * peor: catorce grados que no se pueden retirar nunca.
 */
class BorrarUnCatalogoEnUsoTest extends CasoDeContrato
{
    /**
     * Copia privada, como en las otras cuatro clases que la tienen.
     *
     * Subirla a `CasoDeContrato` sería la quinta copia pidiendo a gritos un
     * ayudante compartido — y es justo lo que midió el §157: **el ayudante que
     * heredan los 130 ficheros de contrato no devuelve lo que promete su
     * nombre**. Mover ésta es una decisión de esa familia, no de este test.
     */
    private function tokenDelSuperusuario(): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario activo.');

        return $this->tokenDe($fila->username);
    }

    /**
     * Un grado con grupos vivos no se va a la papelera — 422, y sigue vivo.
     */
    public function test_un_grado_con_grupos_no_se_puede_eliminar(): void
    {
        $token = $this->tokenDelSuperusuario();

        $grado = DB::selectOne('SELECT g.id FROM grados g
            WHERE g.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM grupos x WHERE x.grado_id = g.id AND x.deleted_at IS NULL
            ) ORDER BY g.id LIMIT 1');

        $this->assertNotNull($grado, 'El seed no tiene ningún grado con grupos vivos.');

        $this->withToken($token)->deleteJson('/api/grados/destroy/'.$grado->id)
            ->assertStatus(422);

        $this->assertNull(DB::table('grados')->where('id', $grado->id)->value('deleted_at'),
            'Contestó 422 y aun así mandó el grado a la papelera.');
    }

    /**
     * Y uno sin grupos se sigue borrando: el candado no es un «siempre no».
     *
     * El grado se crea aquí en vez de buscarlo porque en la copia de producción
     * **trece de los catorce tienen grupos**, así que buscar uno libre depende de
     * qué colegio se haya usado para el seed. Creado, la mitad de la pareja se
     * comprueba siempre.
     */
    public function test_un_grado_sin_grupos_se_sigue_eliminando(): void
    {
        $token = $this->tokenDelSuperusuario();

        $nivel = DB::selectOne('SELECT id FROM niveles_educativos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($nivel, 'El seed no tiene niveles educativos.');

        $id = DB::table('grados')->insertGetId([
            'nombre' => 'GRADO DE PRUEBA SIN GRUPOS',
            'abrev' => 'GP',
            'nivel_educativo_id' => $nivel->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->deleteJson('/api/grados/destroy/'.$id)
            ->assertStatus(200);

        $this->assertNotNull(DB::table('grados')->where('id', $id)->value('deleted_at'),
            'Un grado sin grupos dejó de poder eliminarse: el candado bloquea siempre.');
    }

    /**
     * Un ordinal citado por alguna falta no se borra — 422, y sigue vivo.
     *
     * **La falta se monta aquí y no se busca en el seed**, porque `dis_procesos` y
     * `dis_proceso_ordinales` llegan **vacías**: el generador copia un grupo y sus
     * datos, y todo lo que un colegio acumula alrededor —papeleras, deudas,
     * procesos disciplinarios— llega sin filas. Es la regla que dejó escrita el
     * 09: **si lo que falta es el estado de una fila que ya existe, lo prepara
     * quien mide; si lo que falta es la fila, se monta en el test que la
     * necesita.** Sin esto, el test pasaría por no encontrar sujeto — que es
     * pasar sin comprobar nada.
     */
    public function test_un_ordinal_con_faltas_no_se_puede_eliminar(): void
    {
        $token = $this->tokenDelPersonalLlano();
        $usuario = $this->usuarioLlanoDelPersonal();
        $yearId = DB::table('periodos')->where('id', $usuario->periodo_id)->value('year_id');

        $ordinal = DB::selectOne('SELECT id FROM dis_ordinales
            WHERE year_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$yearId]);
        $this->assertNotNull($ordinal, 'El seed no tiene ordinales en el año actual.');

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $procesoId = DB::table('dis_procesos')->insertGetId([
            'descripcion' => 'FALTA DE PRUEBA',
            'alumno_id' => $alumno->id,
            'year_id' => $yearId,
            'periodo_id' => $usuario->periodo_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dis_proceso_ordinales')->insert([
            'ordinal_id' => $ordinal->id,
            'proceso_id' => $procesoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->putJson('/api/ordinales/destroy', ['ordinal_id' => $ordinal->id])
            ->assertStatus(422);

        $this->assertNull(DB::table('dis_ordinales')->where('id', $ordinal->id)->value('deleted_at'),
            'Contestó 422 y aun así borró el ordinal.');
    }

    /**
     * Y uno que no cita nadie se sigue borrando.
     */
    public function test_un_ordinal_sin_faltas_se_sigue_eliminando(): void
    {
        $token = $this->tokenDelPersonalLlano();

        // `usuarioLlanoDelPersonal()` devuelve `users.*`, y **el año no está ahí**:
        // cuelga del periodo. Es la misma confusión que separa `$user->user_id` de
        // `$user->persona_id`, y sale igual de barata leyendo la tabla.
        $usuario = $this->usuarioLlanoDelPersonal();
        $yearId = DB::table('periodos')->where('id', $usuario->periodo_id)->value('year_id');

        $id = DB::table('dis_ordinales')->insertGetId([
            'year_id' => $yearId,
            'ordinal' => 'ZZ',
            'tipo' => 'Tipo I',
            'descripcion' => 'ORDINAL DE PRUEBA SIN FALTAS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->putJson('/api/ordinales/destroy', ['ordinal_id' => $id])
            ->assertStatus(200);

        $this->assertNotNull(DB::table('dis_ordinales')->where('id', $id)->value('deleted_at'),
            'Un ordinal que no cita nadie dejó de poder borrarse.');
    }

    /**
     * Un área con materias y una materia con asignaturas, igual — 422 las dos.
     *
     * Se cierran el 24 ago 2026, después de ver el número: areas bloquea en 20 de
     * 22 y materias en 27 de 35. **`niveles_educativos` se queda fuera** porque
     * bloquearía **4 de 4** — una ruta enrutada que siempre contesta 422 es peor
     * que la que no existe, porque no dice qué pretendía hacer la pantalla.
     */
    public function test_un_area_con_materias_y_una_materia_con_asignaturas_no_se_borran(): void
    {
        $token = $this->tokenDelSuperusuario();

        $area = DB::selectOne('SELECT a.id FROM areas a
            WHERE a.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM materias m WHERE m.area_id = a.id AND m.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1');
        $this->assertNotNull($area, 'El seed no tiene ningún área con materias vivas.');

        $this->withToken($token)->deleteJson('/api/areas/destroy/'.$area->id)->assertStatus(422);
        $this->assertNull(DB::table('areas')->where('id', $area->id)->value('deleted_at'),
            'Contestó 422 y aun así mandó el área a la papelera.');

        $this->olvidarControladores();

        $materia = DB::selectOne('SELECT m.id FROM materias m
            WHERE m.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM asignaturas x WHERE x.materia_id = m.id AND x.deleted_at IS NULL
            ) ORDER BY m.id LIMIT 1');
        $this->assertNotNull($materia, 'El seed no tiene ninguna materia con asignaturas vivas.');

        $this->withToken($token)->deleteJson('/api/materias/destroy/'.$materia->id)->assertStatus(422);
        $this->assertNull(DB::table('materias')->where('id', $materia->id)->value('deleted_at'),
            'Contestó 422 y aun así mandó la materia a la papelera.');
    }

    /**
     * Y un nivel educativo con grados **sí se sigue borrando**: es la decisión.
     *
     * Tiene la misma forma que `grados` y **se dejó fuera por el número**:
     * bloquearía 4 de 4, o sea que esa ruta no volvería a servir para nada. Si
     * alguien la cierra «por coherencia», este test cae y le enseña la cuenta.
     */
    public function test_un_nivel_educativo_con_grados_se_sigue_borrando(): void
    {
        $token = $this->tokenDelSuperusuario();

        $nivel = DB::selectOne('SELECT n.id FROM niveles_educativos n
            WHERE n.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM grados g WHERE g.nivel_educativo_id = n.id AND g.deleted_at IS NULL
            ) ORDER BY n.id LIMIT 1');
        $this->assertNotNull($nivel, 'El seed no tiene ningún nivel educativo con grados.');

        $this->withToken($token)->deleteJson('/api/niveles_educativos/destroy/'.$nivel->id)
            ->assertStatus(200);

        $this->assertNotNull(DB::table('niveles_educativos')->where('id', $nivel->id)->value('deleted_at'),
            'Se cerró `niveles_educativos` por coherencia con `areas` y `materias`. '
            .'Bloquearía 4 de 4: esa ruta dejaría de servir para nada.');
    }

    /**
     * Los tres catálogos que NO se cierran siguen borrándose, y eso es la decisión.
     *
     * `frases` es el caso que enseña el criterio: `definiciones_comportamiento`
     * tiene `frase_id` **y `frase`**, con el texto ya copiado, así que retirar una
     * frase del banco no le quita nada a ninguna definición. Bloquear ahí dejaría
     * **235 de 426 frases** sin poder retirar a cambio de nada.
     *
     * Si alguien amplía `CatalogoEnUso` «a todos los catálogos» porque estaba
     * mirando `grados`, este test cae — que es exactamente la forma de fallo que
     * más veces salió la noche del 22 al 23: **cerrar de más también rompe.**
     */
    public function test_borrar_una_frase_citada_por_una_definicion_se_sigue_pudiendo(): void
    {
        $token = $this->tokenDelSuperusuario();

        $frase = DB::selectOne('SELECT f.id FROM frases f
            WHERE f.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM definiciones_comportamiento d
                WHERE d.frase_id = f.id AND d.deleted_at IS NULL
            ) ORDER BY f.id LIMIT 1');

        $this->assertNotNull($frase, 'El seed no tiene ninguna frase citada por una definición.');

        $this->withToken($token)->deleteJson('/api/frases/destroy/'.$frase->id)
            ->assertStatus(200);

        $this->assertNotNull(DB::table('frases')->where('id', $frase->id)->value('deleted_at'),
            'Se cerró `frases` por analogía con `grados`. El texto ya está copiado '
            .'en `definiciones_comportamiento.frase`: la definición no pierde nada.');
    }
}
