<?php

namespace Tests\Contrato;

use App\Exports\HojaDeAsignatura;
use App\Exports\LibroDeNotas;
use App\Models\Role;
use App\Support\Autoriza;
use App\Support\EscalaDeNotas;
use App\Support\FirmaDelLibro;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PHPUnit\Framework\Attributes\Test;

/**
 * **La planilla de notas sin internet, fase 1: la descarga.**
 *
 * Las tres rutas de `planilla-offline/*` y el `.xlsx` que producen. El plan está
 * en `myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y lo construido en
 * `docs/migracion/49-la-planilla-sin-internet.md`.
 *
 * ## Qué se mira, y por qué así
 *
 * **La FORMA de la hoja, no los bytes.** Es la lección de `ExcelTest`:
 * PhpSpreadsheet escribe la fecha dentro del `.xlsx`, así que dos exportaciones
 * idénticas dan ficheros distintos. Lo que se comprueba es lo que la secretaría
 * y el docente notan al abrirlo — cuántas hojas, cómo se llaman, si `_myvc` está
 * oculta, si la protección está puesta y si las casillas de nota se pueden
 * escribir— y eso se hace **volviendo a abrir el fichero con PhpSpreadsheet**, o
 * sea el viaje de ida y vuelta.
 *
 * **Y el estado de la base, no la respuesta.** La §3.7 del plan dice que
 * `notas/detailed` siembra filas y recalcula definitivas, y que **esta familia no
 * puede hacer ninguna de las dos cosas**. Un 200 no distingue una descarga limpia
 * de una que acaba de sembrar cuatrocientas filas en un colegio de producción, así
 * que se cuentan las filas antes y después.
 */
class PlanillaOfflineTest extends CasoDeContrato
{
    /**
     * Un docente del año actual con planilla de verdad en el periodo actual.
     *
     * **No vale «el primer profesor del seed»**, que es la trampa que ya dejó
     * escrita `usuarioDeTipo()`: un docente sin unidades en ese periodo devuelve
     * 200 con la lista vacía y todas las comprobaciones de debajo pasan sin haber
     * mirado nada. Se pide el que más indicadores tiene, y se ordena por id para
     * que sea el mismo en cada corrida.
     */
    private function docenteConPlanilla(bool $superusuario = false): object
    {
        $fila = DB::selectOne(
            'SELECT p.id AS profesor_id, u.id AS user_id, u.username,
                    per.id AS periodo_id, per.year_id, COUNT(DISTINCT s.id) AS indicadores
               FROM profesores p
               INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL AND u.is_active = 1
                                 AND u.tipo = "Profesor" AND u.is_superuser = ?
               INNER JOIN asignaturas a ON a.profesor_id = p.id AND a.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
               INNER JOIN periodos per ON per.year_id = y.id AND per.actual = 1 AND per.deleted_at IS NULL
               INNER JOIN unidades un ON un.asignatura_id = a.id AND un.periodo_id = per.id
                                     AND un.deleted_at IS NULL AND un.alumno_id IS NULL
               INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
               INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                                      AND m.estado IN ("MATR","ASIS","PREM")
              WHERE p.deleted_at IS NULL
              GROUP BY p.id, u.id, u.username, per.id, per.year_id
              ORDER BY indicadores DESC, p.id
              LIMIT 1',
            [$superusuario ? 1 : 0]
        );

        $this->assertNotNull($fila,
            'El seed no tiene ningún docente con planilla en el periodo actual del año actual. '
            .'Sin eso estos tests pasarían sobre respuestas vacías.');

        return $fila;
    }

    /** Alguien del personal que **sí** puede pedir la planilla de otro: un superusuario. */
    private function superusuario(): object
    {
        $fila = DB::selectOne(
            'SELECT u.id, u.username FROM users u
               INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
              WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
                AND u.deleted_at IS NULL
              ORDER BY u.id LIMIT 1'
        );

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario de tipo Usuario.');

        return $fila;
    }

    /** Otro docente cualquiera, distinto del primero. Para el 403. */
    private function otroDocente(int $distintoDe): object
    {
        $fila = DB::selectOne(
            'SELECT p.id AS profesor_id FROM profesores p
              WHERE p.deleted_at IS NULL AND p.id <> ?
              ORDER BY p.id LIMIT 1',
            [$distintoDe]
        );

        $this->assertNotNull($fila, 'El seed no tiene un segundo docente.');

        return $fila;
    }

    // ── La respuesta de `periodos` ────────────────────────────────────────────

    #[Test]
    public function la_forma_de_la_respuesta_de_periodos(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))->getJson('/api/planilla-offline/periodos');

        $r->assertStatus(200);

        $cuerpo = $r->json();

        // **Antes de mirar la forma, que no venga vacía.** Es la trampa que
        // `NotasTest` ya documentó: `unidades: []` pasa todas las comprobaciones
        // de debajo sin haber tocado nada.
        $this->assertNotEmpty($cuerpo['periodos'], 'El año no trajo ningún periodo.');

        $conAsignaturas = array_filter($cuerpo['periodos'], fn ($p) => $p['asignaturas'] !== []);
        $this->assertNotEmpty($conAsignaturas, 'Ningún periodo trajo asignaturas: la respuesta describe la nada.');

        $this->compararConInstantanea('planilla-offline-periodos', $this->formaUnida($cuerpo));
    }

    #[Test]
    public function la_escala_y_la_nota_minima_salen_del_ano_y_no_de_un_cien_inventado(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))->getJson('/api/planilla-offline/periodos');

        $esperado = DB::selectOne(
            'SELECT y.nota_minima_aceptada,
                    (SELECT MAX(porc_final) FROM escalas_de_valoracion WHERE year_id = y.id AND deleted_at IS NULL) AS mx,
                    (SELECT MIN(porc_inicial) FROM escalas_de_valoracion WHERE year_id = y.id AND deleted_at IS NULL) AS mn
               FROM years y WHERE y.id = ?',
            [$docente->year_id]
        );

        // `nota_minima_aceptada` es **`varchar(3)`** en el esquema. Sale casteada
        // para que el front no tenga que decidir si `'30'` es un número.
        $this->assertSame((int) $esperado->nota_minima_aceptada, $r->json('nota_minima'));
        $this->assertSame((int) $esperado->mx, $r->json('escala_maxima'));
        $this->assertSame((int) $esperado->mn, $r->json('escala_minima'));
        $this->assertSame((int) $docente->year_id, $r->json('year_id'));
    }

    #[Test]
    public function sin_pasar_son_las_casillas_vacias_y_no_el_contador_de_notas(): void
    {
        $docente = $this->docenteConPlanilla();
        $token = $this->tokenDe($docente->username);

        $antes = $this->sinPasarDeLaRespuesta($token, $docente->periodo_id);
        $this->assertNotEmpty($antes, 'El docente no trajo ninguna asignatura en su periodo.');

        // El número que la portada enseña se comprueba contra la resta escrita a
        // mano aquí, no contra el servicio: si los dos se equivocaran igual, no lo
        // sabríamos.
        foreach ($antes as $asignaturaId => $datos) {
            $esperado = max(0, $datos['alumnos'] * $datos['indicadores'] - $this->notasConValor(
                (int) $asignaturaId, (int) $docente->periodo_id
            ));

            $this->assertSame($esperado, $datos['sin_pasar'],
                "sin_pasar de la asignatura {$asignaturaId} no cuadra con alumnos × indicadores − notas con valor.");
        }

        // Y ahora la mitad que ningún contador de `notas` sabría: vaciar UNA nota
        // tiene que subir el hueco en uno. `subunidades.cantNotas` no se movería —
        // la fila sigue existiendo—, que es por lo que no se usa (§ del servicio).
        $asignaturaId = (int) array_key_first($antes);
        $nota = $this->unaNotaConValor($asignaturaId, (int) $docente->periodo_id);
        $this->assertNotNull($nota, 'La asignatura elegida no tiene ninguna nota puesta que vaciar.');

        DB::update('UPDATE notas SET nota = NULL WHERE id = ?', [$nota->id]);

        $despues = $this->sinPasarDeLaRespuesta($token, $docente->periodo_id);

        $this->assertSame(
            $antes[$asignaturaId]['sin_pasar'] + 1,
            $despues[$asignaturaId]['sin_pasar'],
            'Vaciar una nota tiene que sumar una casilla por pasar.'
        );
    }

    /** @return array<int, array{alumnos:int, indicadores:int, sin_pasar:int}> */
    private function sinPasarDeLaRespuesta(string $token, int $periodoId): array
    {
        $r = $this->withToken($token)->getJson('/api/planilla-offline/periodos');
        $r->assertStatus(200);

        foreach ($r->json('periodos') as $periodo) {
            if ((int) $periodo['id'] !== $periodoId) {
                continue;
            }

            $salida = [];

            foreach ($periodo['asignaturas'] as $asignatura) {
                $salida[(int) $asignatura['asignatura_id']] = [
                    'alumnos' => (int) $asignatura['alumnos'],
                    'indicadores' => (int) $asignatura['indicadores'],
                    'sin_pasar' => (int) $asignatura['sin_pasar'],
                ];
            }

            return $salida;
        }

        return [];
    }

    private function notasConValor(int $asignaturaId, int $periodoId): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(DISTINCT n.id) AS cuantas
               FROM unidades u
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL AND n.nota IS NOT NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
               INNER JOIN matriculas m ON m.alumno_id = n.alumno_id AND m.grupo_id = a.grupo_id
                                      AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
               LEFT JOIN bol_ind_periodos bip ON bip.alumno_id = n.alumno_id AND bip.periodo_id = u.periodo_id
              WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL AND u.alumno_id IS NULL
                AND COALESCE(bip.aplica, 0) = 0',
            [$asignaturaId, $periodoId]
        )->cuantas;
    }

    private function unaNotaConValor(int $asignaturaId, int $periodoId): ?object
    {
        return DB::selectOne(
            'SELECT n.id
               FROM unidades u
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
               INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL AND n.nota IS NOT NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
               INNER JOIN matriculas m ON m.alumno_id = n.alumno_id AND m.grupo_id = a.grupo_id
                                      AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
              WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL AND u.alumno_id IS NULL
              ORDER BY n.id LIMIT 1',
            [$asignaturaId, $periodoId]
        );
    }

    // ── Autorización ──────────────────────────────────────────────────────────

    #[Test]
    public function un_docente_no_puede_pedir_la_planilla_de_otro(): void
    {
        $docente = $this->docenteConPlanilla();
        $otro = $this->otroDocente((int) $docente->profesor_id);
        $token = $this->tokenDe($docente->username);

        // Las tres rutas, porque la comprobación vive en el método y no en el
        // middleware: `auth.personal` deja pasar a los 53 docentes.
        $this->withToken($token)
            ->getJson('/api/planilla-offline/periodos?profesor_id='.$otro->profesor_id)
            ->assertStatus(403);

        $this->withToken($token)
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id.'?profesor_id='.$otro->profesor_id)
            ->assertStatus(403);
    }

    #[Test]
    public function un_docente_no_puede_pedir_la_hoja_de_una_asignatura_ajena(): void
    {
        $docente = $this->docenteConPlanilla();

        $ajena = DB::selectOne(
            'SELECT a.id FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
              WHERE a.deleted_at IS NULL AND (a.profesor_id IS NULL OR a.profesor_id <> ?)
              ORDER BY a.id LIMIT 1',
            [$docente->year_id, $docente->profesor_id]
        );

        $this->assertNotNull($ajena, 'El seed no tiene ninguna asignatura de otro docente en ese año.');

        $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/planilla/'.$ajena->id.'/'.$docente->periodo_id)
            ->assertStatus(403);
    }

    #[Test]
    public function pedir_asignaturas_que_no_son_suyas_da_403_y_no_un_libro_mas_corto(): void
    {
        $docente = $this->docenteConPlanilla();

        $ajena = DB::selectOne(
            'SELECT a.id FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
              WHERE a.deleted_at IS NULL AND (a.profesor_id IS NULL OR a.profesor_id <> ?)
              ORDER BY a.id LIMIT 1',
            [$docente->year_id, $docente->profesor_id]
        );

        $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id.'?asignaturas='.$ajena->id)
            ->assertStatus(403);
    }

    #[Test]
    public function un_superusuario_si_puede_pedir_la_planilla_de_otro(): void
    {
        $docente = $this->docenteConPlanilla();

        // Es la D4 y **es un camino de autorización nuevo**: `User::pueden_editar_notas`
        // deja pasar sólo al tipo `Profesor` y al superusuario, así que coordinación
        // no cabía por ahí (§3.4 del plan).
        $r = $this->withToken($this->tokenDe($this->superusuario()->username))
            ->getJson('/api/planilla-offline/periodos?profesor_id='.$docente->profesor_id);

        $r->assertStatus(200);
        $this->assertSame((int) $docente->profesor_id, $r->json('profesor.id'));
    }

    // ── Lo que `periodos` le contesta a quien NO es docente ────────────────────
    //
    // El contrato entero cabe en una frase: **`periodos` es la primera llamada de
    // la pantalla**, así que tiene que poder contestar «¿soy docente? ¿puedo bajar
    // la de otro?» **antes** de saber a quién pedirle nada. Hasta el 21 sep 2026 le
    // contestaba 422 a un no-docente sin `profesor_id`, y la pantalla acababa
    // deduciendo los dos permisos por su cuenta en el front — la regla de la D4
    // escrita dos veces, en dos lenguajes, y una de ellas sin tests.
    //
    // Las otras dos rutas siguen dando 422 y eso lo ata el último test del bloque:
    // ahí sí hace falta un docente, porque hay que **generar un fichero suyo**.
    //
    // ## EL CASO POSITIVO VA TRES VECES, Y ÉSA ES LA PARTE QUE IMPORTA
    //
    // `puedeDescargarLaPlanillaDeOtro` es `esAdministrativo || puedeEditarPlantillaNotas`,
    // o sea **tres** ramas: superusuario, `Secretario` y `can_edit_plantilla_notas`.
    // Y el superusuario **las enciende todas a la vez**: está dentro de
    // `esAdministrativo` y dentro de `puedeEditarPlantillaNotas`. Así que un único
    // test positivo hecho con él da verde aunque alguien borre entera la rama de
    // `can_edit_plantilla_notas` — que es justo la de la D4, la que los colegios
    // reparten: «coordinación puede con el libro de cualquier docente». El
    // superusuario no es el caso real; la coordinadora del colegio no lo es.
    //
    // Por eso hay un test por rama y cada sujeto lleva **esa y sólo esa**. Los dos
    // que el seed no trae se montan aquí —el seed hace `TRUNCATE` de `roles`,
    // `permissions`, `permission_role` y `role_user`, así que ni el rol `Secretario`
    // ni el permiso sobreviven a construir la base— y se montan **por la vía real**,
    // permiso → rol → usuario, que es como llegan a `perms` del contexto.

    /**
     * Alguien del personal que **no** puede bajar la planilla de otro: ni
     * superusuario, ni `Secretario`, ni `can_edit_plantilla_notas`.
     *
     * No vale `usuarioLlanoDelPersonal()`: aquél sólo exige `is_superuser = 0`, y
     * la regla de la D4 tiene **tres** ramas. Con un `Secretario` dentro este test
     * diría «sin permiso» y estaría midiendo a alguien que sí lo tiene — y saldría
     * verde el día que el 200 empezara a contestar `true` a todo el mundo.
     */
    private function personalSinNingunPermisoDeLaD4(): object
    {
        $fila = DB::selectOne(
            'SELECT u.id, u.username, u.is_superuser FROM users u
               INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
               INNER JOIN years y ON y.id = p.year_id AND y.actual = 1 AND y.deleted_at IS NULL
              WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
                AND u.is_superuser = 0
                AND NOT EXISTS (SELECT 1 FROM role_user ru
                                  INNER JOIN roles r ON r.id = ru.role_id AND r.deleted_at IS NULL
                                 WHERE ru.user_id = u.id AND r.name = "Secretario")
                AND NOT EXISTS (SELECT 1 FROM role_user ru
                                  INNER JOIN permission_role pr ON pr.role_id = ru.role_id
                                  INNER JOIN permissions pm ON pm.id = pr.permission_id
                                 WHERE ru.user_id = u.id AND pm.name = "can_edit_plantilla_notas")
              ORDER BY u.id LIMIT 1'
        );

        $this->assertNotNull($fila,
            'El seed no tiene ningún Usuario del año actual sin las tres ramas de la D4. '
            .'Sin él, «no puede bajar la de otro» no se puede comprobar con nadie.');

        return $fila;
    }

    /**
     * `can_edit_plantilla_notas` por la vía real: permiso → rol → usuario.
     *
     * Calcado de `PlanillaOfflineImportarTest::darPermisoDeLaPlantilla` —mismo
     * nombre de rol a propósito, para que la base no acabe con dos roles de prueba
     * que dicen lo mismo— y por el mismo motivo: `database/dumps/test-seed.sql`
     * hace `TRUNCATE` de `permissions`, y las migraciones corren **antes** del
     * seed, así que lo que siembra la migración del permiso **no sobrevive a
     * construir la base**. Un test que lo diera por sembrado comprobaría el seed.
     *
     * Y va por rol y no inventando una columna porque así es como el permiso llega
     * a `perms` del contexto, que es lo único que `Autoriza` mira.
     */
    private function darPermisoDeLaPlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'CoordinacionDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'CoordinacionDePrueba',
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

    /**
     * El rol `Secretario`, **y sin ningún permiso colgando**.
     *
     * Se crea aquí igual que en `FichaDelPersonalTest`: el seed hace `TRUNCATE` de
     * `roles` y esa fila no existe en la base de tests —`LoQueDecideUnRolTest`
     * tiene su propio caso fijándolo—. Que no lleve `permission_role` no es
     * descuido: es lo que hace que este sujeto aísle la rama de `esAdministrativo`.
     * Con el permiso colgando, el test diría «`Secretario` puede» midiendo la otra.
     *
     * **La tilde no entra en juego aquí, pero conviene saber por qué**: `hasRole()`
     * compara el nombre en PHP, así que un rol escrito de otra forma existiría y el
     * método devolvería `false` sin fallar nada (doc 33). Se escribe literal.
     */
    private function darRolDeSecretario(int $userId): void
    {
        $rol = DB::table('roles')->where('name', 'Secretario')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Secretario',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        if (! DB::table('role_user')->where('user_id', $userId)->where('role_id', $rol)->exists()) {
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rol]);
        }
    }

    /** Si ese usuario tiene `can_edit_plantilla_notas` por alguno de sus roles. */
    private function tieneElPermisoDeLaPlantilla(int $userId): bool
    {
        return DB::selectOne(
            'SELECT 1 AS si FROM role_user ru
               INNER JOIN permission_role pr ON pr.role_id = ru.role_id
               INNER JOIN permissions pm ON pm.id = pr.permission_id
              WHERE ru.user_id = ? AND pm.name = ?',
            [$userId, Autoriza::PERMISO_PLANTILLA_NOTAS]
        ) !== null;
    }

    #[Test]
    public function un_no_docente_superusuario_pide_periodos_sin_profesor_id_y_recibe_200(): void
    {
        $r = $this->withToken($this->tokenDe($this->superusuario()->username))
            ->getJson('/api/planilla-offline/periodos');

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertNull($cuerpo['profesor'], 'No hay «el suyo», así que `profesor` tiene que ser null.');
        $this->assertSame([], $cuerpo['periodos'], 'Sin docente no hay nada que listar.');
        $this->assertSame(true, $cuerpo['puede_bajar_la_de_otro']);
        $this->assertSame(false, $cuerpo['soy_docente']);

        // **Y el año sale entero.** Es la mitad del contrato que no se ve en el
        // 200: la pantalla pinta la escala y los nombres de las columnas con esto,
        // así que un 200 con el año en nulo sería el mismo error con otra cara.
        foreach ([
            'year_id', 'periodo_actual_id', 'nota_minima', 'escala_maxima', 'escala_minima',
            'unidad_displayname', 'unidades_displayname',
            'subunidad_displayname', 'subunidades_displayname', 'reparto',
        ] as $campo) {
            $this->assertNotNull($cuerpo[$campo], "`{$campo}` salió nulo: el año no viaja completo.");
        }
    }

    /**
     * **La rama de la D4, sola: `can_edit_plantilla_notas` y nada más.**
     *
     * Es el caso que el superusuario tapa —él enciende las tres ramas a la vez— y
     * el único que se parece al colegio: la coordinadora académica no es
     * superusuaria ni está en secretaría. Sin este test, borrar entera la rama de
     * `puedeEditarPlantillaNotas` deja la suite en verde.
     *
     * El sujeto es el mismo de `un_no_docente_sin_permiso...`, que sale `false`, así
     * que entre los dos tests **lo único que cambia es la fila de `role_user`**.
     */
    #[Test]
    public function la_rama_de_can_edit_plantilla_notas_sola_ya_deja_bajar_la_de_otro(): void
    {
        $usuario = $this->personalSinNingunPermisoDeLaD4();

        $this->darPermisoDeLaPlantilla((int) $usuario->id);

        // **Las otras dos ramas apagadas, dicho aquí y no sólo en el `WHERE`.** Con
        // la columna puesta —o con el rol— el `true` de abajo saldría igual y este
        // test no demostraría nada de lo que dice su nombre.
        $this->assertSame(0, (int) $usuario->is_superuser);
        $this->assertFalse(Role::isSecretario((int) $usuario->id));
        $this->assertTrue($this->tieneElPermisoDeLaPlantilla((int) $usuario->id),
            'El permiso no quedó puesto: este test estaría midiendo al de al lado.');

        $cuerpo = $this->withToken($this->tokenDe($usuario->username))
            ->getJson('/api/planilla-offline/periodos')
            ->assertStatus(200)
            ->json();

        $this->assertSame(true, $cuerpo['puede_bajar_la_de_otro']);
        $this->assertSame(false, $cuerpo['soy_docente']);
        $this->assertNull($cuerpo['profesor']);
        $this->assertSame([], $cuerpo['periodos']);
    }

    /**
     * **La otra rama, sola: `Secretario` sin el permiso.**
     *
     * Es la rama ancha de `esAdministrativo`, y el plan la justifica: lo que sale
     * por estas rutas es la planilla que esa persona **ya ve por la web**, sólo que
     * en un `.xlsx`. Secretaría **baja y no sube** — la asimetría la ata
     * `PlanillaOfflineImportarTest::d4_un_secretario_baja_el_libro_y_no_puede_subirlo`.
     *
     * El rol se crea **sin permisos colgando**, que es lo que lo convierte en la
     * prueba de esta rama y no de la otra.
     */
    #[Test]
    public function la_rama_de_secretario_sola_tambien_deja_bajar_la_de_otro(): void
    {
        $usuario = $this->personalSinNingunPermisoDeLaD4();

        $this->darRolDeSecretario((int) $usuario->id);

        $this->assertSame(0, (int) $usuario->is_superuser);
        $this->assertTrue(Role::isSecretario((int) $usuario->id), 'El rol no quedó puesto.');
        $this->assertFalse($this->tieneElPermisoDeLaPlantilla((int) $usuario->id),
            'El rol `Secretario` traía `can_edit_plantilla_notas` colgando: así este test mide '
            .'la rama de coordinación y no la suya, y las dos saldrían verdes con una sola viva.');

        $cuerpo = $this->withToken($this->tokenDe($usuario->username))
            ->getJson('/api/planilla-offline/periodos')
            ->assertStatus(200)
            ->json();

        $this->assertSame(true, $cuerpo['puede_bajar_la_de_otro']);
        $this->assertSame(false, $cuerpo['soy_docente']);
    }

    #[Test]
    public function un_no_docente_sin_permiso_tambien_recibe_200_pero_con_el_permiso_en_falso(): void
    {
        $r = $this->withToken($this->tokenDe($this->personalSinNingunPermisoDeLaD4()->username))
            ->getJson('/api/planilla-offline/periodos');

        $r->assertStatus(200);

        $cuerpo = $r->json();

        // **200 y no 403.** No pedir nada de nadie no es un intento: es la pantalla
        // preguntando qué puede hacer. Lo que cambia es la respuesta, no el estado.
        $this->assertSame(false, $cuerpo['puede_bajar_la_de_otro']);
        $this->assertSame(false, $cuerpo['soy_docente']);
        $this->assertNull($cuerpo['profesor']);
        $this->assertSame([], $cuerpo['periodos']);
    }

    #[Test]
    public function un_docente_recibe_los_dos_campos_y_su_planilla_de_siempre(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->getJson('/api/planilla-offline/periodos');

        $r->assertStatus(200);

        $cuerpo = $r->json();

        $this->assertSame(true, $cuerpo['soy_docente']);
        $this->assertSame((int) $docente->profesor_id, $cuerpo['profesor']['id']);
        $this->assertNotEmpty($cuerpo['periodos'], 'Al docente con planilla no le puede salir la lista vacía.');
        $this->assertNotEmpty(
            array_filter($cuerpo['periodos'], fn ($p) => $p['asignaturas'] !== []),
            'Ningún periodo trajo asignaturas: la respuesta describe la nada.'
        );

        // **«Lo que le toque» es `false`, y aquí se dice por qué.** Ningún docente
        // del seed es superusuario ni tiene `can_edit_plantilla_notas` ni el rol
        // `Secretario` —medido el 21 sep 2026, cero de 53—, que es lo coherente con
        // la D4: el permiso es de coordinación. Si el seed cambiara, esta línea
        // falla con nombre en vez de que el `false` de abajo se vuelva mentira.
        $conPermiso = DB::selectOne(
            'SELECT u.id FROM users u
              WHERE u.id = ? AND (u.is_superuser = 1
                OR EXISTS (SELECT 1 FROM role_user ru
                             INNER JOIN roles r ON r.id = ru.role_id AND r.deleted_at IS NULL
                            WHERE ru.user_id = u.id AND r.name = "Secretario")
                OR EXISTS (SELECT 1 FROM role_user ru
                             INNER JOIN permission_role pr ON pr.role_id = ru.role_id
                             INNER JOIN permissions pm ON pm.id = pr.permission_id
                            WHERE ru.user_id = u.id AND pm.name = "can_edit_plantilla_notas"))',
            [$docente->user_id]
        );

        $this->assertNull($conPermiso,
            'El docente elegido SÍ tiene el permiso de la D4: este test estaba midiendo otra cosa.');

        $this->assertSame(false, $cuerpo['puede_bajar_la_de_otro']);
    }

    #[Test]
    public function los_dos_permisos_salen_como_booleanos_de_json_y_no_como_uno_y_cero(): void
    {
        // **`assertTrue` no vale aquí y ése es todo el test.** `assertTrue(1)`
        // pasa, y un `1` en el JSON rompe el `=== true` de TypeScript sin que nada
        // en PHP se queje: la pantalla dejaría de pintar el desplegable de «la
        // planilla de otro» y el back seguiría verde. Se compara con `assertSame`
        // sobre el JSON **decodificado**, que es donde un `0`/`1` se distingue de
        // un `false`/`true`.
        $superusuario = $this->withToken($this->tokenDe($this->superusuario()->username))
            ->getJson('/api/planilla-offline/periodos')->json();

        $this->assertSame(true, $superusuario['puede_bajar_la_de_otro']);
        $this->assertSame(false, $superusuario['soy_docente']);

        // El mismo par de campos con los valores al revés, para que ninguno de los
        // dos pueda estar clavado a una constante.
        $docente = $this->withToken($this->tokenDe($this->docenteConPlanilla()->username))
            ->getJson('/api/planilla-offline/periodos')->json();

        $this->assertSame(true, $docente['soy_docente']);
        $this->assertSame(false, $docente['puede_bajar_la_de_otro']);
    }

    #[Test]
    public function el_libro_sin_profesor_id_y_sin_ser_docente_sigue_dando_422(): void
    {
        $docente = $this->docenteConPlanilla();

        // **Lo que NO cambia del contrato.** `periodos` se volvió tolerante porque
        // contesta quién eres; aquí hay que **generar el libro de alguien**, y sin
        // `profesor_id` no hay alguien. Es 422 —«falta un dato»— y no 403: al
        // superusuario no le falta permiso, le falta decir de quién.
        $this->withToken($this->tokenDe($this->superusuario()->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id)
            ->assertStatus(422);
    }

    #[Test]
    public function un_alumno_no_pasa_de_la_puerta(): void
    {
        $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->getJson('/api/planilla-offline/periodos')
            ->assertStatus(403);
    }

    // ── La descarga ───────────────────────────────────────────────────────────

    #[Test]
    public function un_periodo_cerrado_si_se_descarga_y_sale_como_copia_de_consulta(): void
    {
        $docente = $this->docenteConPlanilla();

        // **Es la D1 y es lo primero que se rompería copiando la guarda de al lado.**
        // Bajar una planilla no puede exigir el periodo abierto: el docente quiere
        // guardarse el año entero, y los periodos pasados están cerrados.
        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 0 WHERE id = ?', [$docente->periodo_id]);

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $r->assertStatus(200);

        $this->assertStringContainsString(
            '-consulta.xlsx',
            (string) $r->headers->get('content-disposition'),
            'Un libro de un periodo cerrado tiene que llamarse distinto: es el que no se va a poder subir.'
        );

        $libro = IOFactory::load($this->archivoDescargado($r));
        $portada = $libro->getSheetByName(LibroDeNotas::PORTADA);

        $this->assertStringContainsString(
            'COPIA DE CONSULTA',
            (string) $portada->getCell('A1')->getValue(),
            'Y la portada tiene que decirlo arriba del todo, antes que el nombre del colegio.'
        );
    }

    /**
     * **El paso 5 de la portada manda al docente a un sitio que existe.**
     *
     * Decía *«súbalo en Notas → Subir planilla»* y en el menú **no hay ninguna de las
     * dos cosas**: no hay sección «Notas» —se fusionó en «Académico»— ni entrada
     * «Subir planilla». Hay `Académico → Trabajar sin internet`, y dentro un botón
     * «Subir una planilla», que es a donde esto tiene que apuntar.
     *
     * **Los rótulos se comprueban contra el menú del front y no contra la memoria de
     * nadie**: viven en `app2/src/app/cascara/menu/menu.ts` —la sección en `:574` y
     * la entrada en `:613`— y el botón en
     * `app2/src/app/paginas/notas-sin-internet/notas-sin-internet.html:29`. Este test
     * no puede leer ese repo, así que lo que sujeta es la otra mitad: que la frase no
     * vuelva a la que se sabe falsa, y que quien la cambie pase por aquí y vuelva a
     * mirarlos.
     */
    #[Test]
    public function el_paso_5_de_la_portada_nombra_la_ruta_que_el_menu_tiene_hoy(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $r->assertStatus(200);

        $libro = IOFactory::load($this->archivoDescargado($r));
        $portada = $libro->getSheetByName(LibroDeNotas::PORTADA);

        $texto = '';

        foreach ($portada->getRowIterator() as $fila) {
            foreach (['A', 'B'] as $columna) {
                $texto .= (string) $portada->getCell($columna.$fila->getRowIndex())->getValue()."\n";
            }
        }

        $libro->disconnectWorksheets();

        $this->assertStringContainsString(
            'Académico → Trabajar sin internet → Subir una planilla',
            $texto,
            'La portada es lo único que el docente tiene delante cuando termina de pasar las notas. '
            .'Si nombra un menú que no existe, el libro se queda en su carpeta.'
        );

        $this->assertStringNotContainsString('Notas → Subir planilla', $texto,
            'Ni la sección «Notas» ni la entrada «Subir planilla» existen en el menú de app2.');
    }

    #[Test]
    public function el_nombre_del_archivo_de_un_periodo_abierto_no_lleva_consulta(): void
    {
        $docente = $this->docenteConPlanilla();

        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 1 WHERE id = ?', [$docente->periodo_id]);

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $r->assertStatus(200);

        $disposicion = (string) $r->headers->get('content-disposition');

        $this->assertStringContainsString('.xlsx', $disposicion);
        $this->assertStringNotContainsString('-consulta', $disposicion);
    }

    #[Test]
    public function la_descarga_no_escribe_ni_una_nota_ni_una_definitiva(): void
    {
        $docente = $this->docenteConPlanilla();

        // Es la §3.7 del plan: `putDetailed` siembra filas con
        // `Nota::verificarCrearNotas`, arregla el `orden` y recalcula definitivas.
        // **Un 200 no distingue una descarga limpia de una que acaba de hacer las
        // tres cosas en un colegio de producción**, así que se cuenta el estado.
        $antes = $this->huellaDeLaBase();

        $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id)
            ->assertStatus(200);

        $this->assertSame($antes, $this->huellaDeLaBase(),
            'La descarga movió filas de notas, notas_finales, unidades o subunidades. No puede escribir nada.');
    }

    /** @return array<string, mixed> */
    private function huellaDeLaBase(): array
    {
        $huella = [];

        foreach (['notas', 'notas_finales', 'unidades', 'subunidades'] as $tabla) {
            $fila = DB::selectOne(
                "SELECT COUNT(*) AS filas, COALESCE(MAX(updated_at), '') AS ultimo FROM {$tabla}"
            );

            $huella[$tabla] = ['filas' => (int) $fila->filas, 'ultimo' => (string) $fila->ultimo];
        }

        // El `orden` de las unidades del periodo, que es lo tercero que reescribe
        // `putDetailed` y lo que ningún `COUNT` vería.
        $huella['orden_unidades'] = (string) DB::selectOne(
            'SELECT COALESCE(GROUP_CONCAT(CONCAT(id, ":", COALESCE(orden, "-")) ORDER BY id), "") AS o
               FROM unidades WHERE deleted_at IS NULL'
        )->o;

        return $huella;
    }

    #[Test]
    public function la_descarga_queda_anotada_con_su_huella(): void
    {
        $docente = $this->docenteConPlanilla();

        $antes = (int) DB::selectOne('SELECT COUNT(*) AS c FROM descargas_de_planilla')->c;

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $r->assertStatus(200);

        $fila = DB::selectOne('SELECT * FROM descargas_de_planilla ORDER BY id DESC LIMIT 1');

        $this->assertSame($antes + 1, (int) DB::selectOne('SELECT COUNT(*) AS c FROM descargas_de_planilla')->c);
        $this->assertSame((int) $docente->profesor_id, (int) $fila->profesor_id);
        $this->assertSame((int) $docente->periodo_id, (int) $fila->periodo_id);
        $this->assertGreaterThan(0, (int) $fila->hojas);

        // La huella es la del fichero **tal y como se entregó**: es lo que permite
        // a la fase 2 reconocer un libro sin abrirlo.
        $this->assertSame(hash_file('sha256', $this->archivoDescargado($r)), $fila->huella);
        $this->assertSame((int) filesize($this->archivoDescargado($r)), (int) $fila->bytes);
    }

    // ── El fichero ────────────────────────────────────────────────────────────

    #[Test]
    public function el_libro_se_vuelve_a_abrir_y_tiene_la_forma_esperada(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $r->assertStatus(200);

        $libro = IOFactory::load($this->archivoDescargado($r));
        $nombres = $libro->getSheetNames();

        // La portada es la primera y la que se abre sola; `_myvc` la última.
        $this->assertSame(LibroDeNotas::PORTADA, $nombres[0]);
        $this->assertSame(LibroDeNotas::METADATOS, $nombres[count($nombres) - 1]);
        $this->assertGreaterThan(2, count($nombres), 'Un libro sin ninguna hoja de asignatura no prueba nada.');

        foreach ($nombres as $nombre) {
            $this->assertLessThanOrEqual(31, mb_strlen($nombre),
                "El nombre de hoja «{$nombre}» pasa de 31 caracteres: Excel no abre el fichero.");

            $this->assertTrue($libro->getSheetByName($nombre)->getProtection()->getSheet(),
                "La hoja «{$nombre}» salió sin protección.");
        }

        $meta = $libro->getSheetByName(LibroDeNotas::METADATOS);

        $this->assertSame('hidden', $meta->getSheetState(), 'La hoja `_myvc` tiene que ir oculta.');
        $this->assertSame('myvc', $meta->getCell('A1')->getValue());
        $this->assertSame('libro', $meta->getCell('A2')->getValue());
        $this->assertSame('firma', $meta->getCell('A3')->getValue());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $meta->getCell('B3')->getValue());

        $cabecera = json_decode((string) $meta->getCell('B2')->getValue(), true);

        $this->assertSame((int) $docente->periodo_id, $cabecera['periodo_id']);
        $this->assertSame((int) $docente->profesor_id, $cabecera['profesor_id']);
    }

    #[Test]
    public function la_hoja_de_una_asignatura_tiene_la_rejilla_de_la_d11_y_la_d12(): void
    {
        $docente = $this->docenteConPlanilla();

        $asignatura = DB::selectOne(
            'SELECT a.id, a.grupo_id FROM asignaturas a
               INNER JOIN unidades u ON u.asignatura_id = a.id AND u.periodo_id = ?
                                    AND u.deleted_at IS NULL AND u.alumno_id IS NULL
               INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
              WHERE a.profesor_id = ? AND a.deleted_at IS NULL
              GROUP BY a.id, a.grupo_id ORDER BY a.id LIMIT 1',
            [$docente->periodo_id, $docente->profesor_id]
        );

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/planilla/'.$asignatura->id.'/'.$docente->periodo_id);

        $r->assertStatus(200);

        $libro = IOFactory::load($this->archivoDescargado($r));

        // Una sola hoja de asignatura: el mismo libro con `n = 1`.
        $this->assertCount(3, $libro->getSheetNames(),
            'La ruta de una sola planilla tiene que traer portada + una hoja + `_myvc`.');

        $hoja = $libro->getSheet(1);

        // **D11**: el enlace de vuelta en A1, el título en A2:B2 y el rótulo `ID` en C2.
        $this->assertStringContainsString('portada', (string) $hoja->getCell('A1')->getValue());
        $this->assertStringContainsString(
            "sheet://'".LibroDeNotas::PORTADA."'!A1",
            $hoja->getCell('A1')->getHyperlink()->getUrl()
        );
        $this->assertSame('ID', $hoja->getCell('C2')->getValue());
        $this->assertSame('D3', $hoja->getFreezePane(), 'El panel se congela en D3 (D11).');

        // **D12**: las tres primeras unidades ocupan 5 columnas y el resto 3, existan
        // o no los indicadores. La cuenta sale de las unidades reales del periodo.
        $unidades = DB::select(
            'SELECT u.id, (SELECT COUNT(*) FROM subunidades s WHERE s.unidad_id = u.id AND s.deleted_at IS NULL) AS cuantas
               FROM unidades u
              WHERE u.asignatura_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL AND u.alumno_id IS NULL
              ORDER BY u.orden, u.id',
            [$asignatura->id, $docente->periodo_id]
        );

        $esperadas = 0;

        foreach ($unidades as $i => $unidad) {
            $reserva = $i < HojaDeAsignatura::UNIDADES_ANCHAS
                ? HojaDeAsignatura::RESERVA_PRIMERAS
                : HojaDeAsignatura::RESERVA_RESTO;

            $esperadas += max($reserva, (int) $unidad->cuantas);
        }

        // 3 columnas de identidad (A, B, C) + las de nota + Def, Aus y Tar.
        $this->assertSame(
            3 + $esperadas + 3,
            Coordinate::columnIndexFromString($hoja->getHighestColumn()),
            'El ancho de la hoja no cuadra con la reserva de la D12.'
        );

        // Las casillas de nota se escriben; el ID y la Def, no.
        $this->assertSame('unprotected', $hoja->getStyle('D3')->getProtection()->getLocked());
        $this->assertNotSame('unprotected', $hoja->getStyle('C3')->getProtection()->getLocked());

        // La validación es la de la §3.1 **con** la D9: entero, y el guion pasa.
        $validacion = $hoja->getCell('D3')->getDataValidation();
        $this->assertSame('custom', $validacion->getType());
        $this->assertSame('stop', $validacion->getErrorStyle());
        $this->assertStringContainsString('INT(', $validacion->getFormula1());
        $this->assertStringContainsString('"-"', $validacion->getFormula1());
        $this->assertStringContainsString('guion', $validacion->getPrompt());
    }

    #[Test]
    public function el_bloque_de_alumnos_nuevos_esta_desbloqueado_y_son_tres_filas(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $libro = IOFactory::load($this->archivoDescargado($r));
        $hoja = $libro->getSheet(1);

        $rotulo = null;

        foreach ($hoja->getRowIterator() as $fila) {
            if (str_contains((string) $hoja->getCell('A'.$fila->getRowIndex())->getValue(), 'NO APARECEN EN LA LISTA')) {
                $rotulo = $fila->getRowIndex();

                break;
            }
        }

        $this->assertNotNull($rotulo, 'La hoja no trae el bloque de alumnos que no aparecen en la lista.');

        // Rótulo, frase y **tres** filas: treinta invitarían a pegar una lista
        // entera, y esto no crea a nadie.
        $primera = $rotulo + 2;

        for ($i = 0; $i < HojaDeAsignatura::FILAS_NUEVAS; $i++) {
            $this->assertSame('unprotected',
                $hoja->getStyle('B'.($primera + $i))->getProtection()->getLocked(),
                'Las filas de alumnos nuevos tienen que poder escribirse.');

            $this->assertNull($hoja->getCell('C'.($primera + $i))->getValue(),
                'El ID de una fila de alumno nuevo va vacío.');
        }

        $this->assertNotSame('unprotected',
            $hoja->getStyle('B'.($primera + HojaDeAsignatura::FILAS_NUEVAS))->getProtection()->getLocked(),
            'Son tres filas, no cuatro.');
    }

    #[Test]
    public function la_firma_se_puede_recalcular_desde_lo_que_el_libro_lleva_escrito(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $libro = IOFactory::load($this->archivoDescargado($r));
        $meta = $libro->getSheetByName(LibroDeNotas::METADATOS);

        /*
         * **Esto es lo que de verdad prueba que la firma sirve para algo.** Un test
         * que firmara y comprobara en memoria pasaría igual de bien aunque lo escrito
         * en el libro no bastara para reconstruir lo firmado — y entonces la fase 2,
         * que sólo tiene el fichero, no podría comprobar nada y degradaría todos los
         * libros al peldaño 2 de la §4.7 sin que aquí se pusiera nada rojo.
         *
         * Así que se rehace la estructura **leyendo únicamente las celdas** y se
         * vuelve a firmar.
         */
        $cabecera = json_decode((string) $meta->getCell('B2')->getValue(), true);
        $firma = (string) $meta->getCell('B3')->getValue();

        $hojas = [];
        $orden = [];

        foreach ($meta->getRowIterator() as $fila) {
            $f = $fila->getRowIndex();
            $clave = (string) $meta->getCell('A'.$f)->getValue();

            if ($clave === 'hoja') {
                $nombre = (string) $meta->getCell('B'.$f)->getValue();
                $hojas[$nombre] = json_decode((string) $meta->getCell('C'.$f)->getValue(), true);
                $hojas[$nombre]['espejo'] = [];
                $orden[] = $nombre;
            } elseif ($clave === 'espejo') {
                $nombre = (string) $meta->getCell('B'.$f)->getValue();
                $alumno = (string) $meta->getCell('C'.$f)->getValue();
                $hojas[$nombre]['espejo'][$alumno] = json_decode((string) $meta->getCell('D'.$f)->getValue(), true);
            }
        }

        $rehecho = ['libro' => $cabecera, 'hojas' => array_map(fn ($n) => $hojas[$n], $orden)];

        $this->assertTrue(
            FirmaDelLibro::comprobar($rehecho, $firma),
            'La firma no se puede recalcular desde el propio libro: la fase 2 no podría fiarse del espejo.'
        );

        // Y la otra mitad: tocar el espejo dentro del fichero tiene que notarse.
        $primera = $orden[0];
        $alumno = (string) array_key_first($rehecho['hojas'][0]['espejo']);
        $columna = (string) array_key_first($rehecho['hojas'][0]['espejo'][$alumno]);
        $rehecho['hojas'][0]['espejo'][$alumno][$columna] = 999;

        $this->assertFalse(FirmaDelLibro::comprobar($rehecho, $firma));
        unset($primera);
    }

    #[Test]
    public function en_modo_promedio_la_cabecera_no_imprime_porcentaje(): void
    {
        $docente = $this->docenteConPlanilla();

        // §3.5 del plan: en `promedio` el valor de `subunidades.porcentaje` **se
        // ignora** y cada indicador vale `100/n`. Un «20 %» impreso en la cabecera
        // sería un número que no gobierna nada, y eso es peor que ninguno porque es
        // creíble.
        DB::update('UPDATE years SET reparto_subunidades = "promedio" WHERE id = ?', [$docente->year_id]);

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $r->assertStatus(200);

        $hoja = IOFactory::load($this->archivoDescargado($r))->getSheet(1);
        $cabecera = $hoja->getCell('D2')->getValue();
        $texto = $cabecera instanceof RichText
            ? $cabecera->getPlainText()
            : (string) $cabecera;

        $this->assertStringContainsString('prom.', $texto);
        $this->assertStringNotContainsString('%', $texto);
    }

    #[Test]
    public function un_ano_sin_escala_sale_sin_tope_y_no_con_un_cien_inventado(): void
    {
        $docente = $this->docenteConPlanilla();

        // Es la decisión escrita en `EscalaDeNotas`: si no se sabe la escala **no se
        // bloquea nada**, y sobre todo **no se inventa un 100** — en un colegio de 0
        // a 50 eso afloja el límite al doble y encima parece una comprobación.
        DB::update('UPDATE escalas_de_valoracion SET deleted_at = NOW() WHERE year_id = ?', [$docente->year_id]);
        EscalaDeNotas::olvidar();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->getJson('/api/planilla-offline/periodos');

        $r->assertStatus(200);
        $this->assertNull($r->json('escala_maxima'), 'Sin escala configurada, `escala_maxima` es null y no 100.');

        $descarga = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $descarga->assertStatus(200);

        $hoja = IOFactory::load($this->archivoDescargado($descarga))->getSheet(1);
        $formula = $hoja->getCell('D3')->getDataValidation()->getFormula1();

        $this->assertStringContainsString('INT(', $formula, 'La regla de número entero se queda: `notas.nota` es int.');
        $this->assertStringNotContainsString('<=', $formula, 'Sin escala no puede haber tope.');
        $this->assertSame([], $hoja->getConditionalStyles('D3'),
            'Sin bandas no hay formato condicional que pintar.');
    }

    #[Test]
    public function las_hojas_del_libro_son_las_asignaturas_que_lista_periodos(): void
    {
        $docente = $this->docenteConPlanilla();
        $token = $this->tokenDe($docente->username);

        /*
         * **Este test nace de una discrepancia real, no de una hipótesis.** Conduciendo
         * las tres rutas contra el docker el 21 sep 2026, `/periodos` decía **21
         * asignaturas** para el docente 3 en el periodo 39 y `libro/39` bajaba un
         * `.xlsx` con **26 hojas de asignatura**: la pantalla pintaba «Descargar el
         * libro (21 hojas)» y bajaban 26, y la tabla de la portada del propio libro no
         * cuadraba con sus pestañas. La causa era que la lista filtraba por «tiene
         * indicadores en ese periodo» y el generador no.
         *
         * **Nada sujetaba ese pareado**, que es la clase de diferencia que nadie vuelve
         * a mirar. Esto lo sujeta, y además fabrica el caso: le quita las unidades a una
         * de las asignaturas del docente para que tenga cero indicadores, que es
         * exactamente la fila que antes se caía de la lista y seguía trayendo hoja.
         */
        $asignaturas = $this->sinPasarDeLaRespuesta($token, (int) $docente->periodo_id);
        $this->assertNotEmpty($asignaturas);

        $vaciada = (int) array_key_first($asignaturas);

        DB::update(
            'UPDATE unidades SET deleted_at = NOW()
              WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL AND alumno_id IS NULL',
            [$vaciada, $docente->periodo_id]
        );

        $lista = $this->sinPasarDeLaRespuesta($token, (int) $docente->periodo_id);

        // Sigue en la lista, con cero. Es la D12: una asignatura sin indicadores es
        // justo el caso para el que existen las columnas de reserva.
        $this->assertArrayHasKey($vaciada, $lista,
            'Una asignatura sin indicadores tiene que seguir listada: el libro le hace hoja igual.');
        $this->assertSame(0, $lista[$vaciada]['indicadores']);
        $this->assertSame(0, $lista[$vaciada]['sin_pasar']);

        $r = $this->withToken($token)->get('/api/planilla-offline/libro/'.$docente->periodo_id);
        $r->assertStatus(200);

        $libro = IOFactory::load($this->archivoDescargado($r));
        $meta = $libro->getSheetByName(LibroDeNotas::METADATOS);

        $enElLibro = [];

        foreach ($meta->getRowIterator() as $fila) {
            $f = $fila->getRowIndex();

            if ((string) $meta->getCell('A'.$f)->getValue() !== 'hoja') {
                continue;
            }

            $mapa = json_decode((string) $meta->getCell('C'.$f)->getValue(), true);
            $enElLibro[] = (int) $mapa['asignatura_id'];
        }

        // Las dos cuentas, y **los mismos identificadores**: un número igual por
        // casualidad con asignaturas distintas sería el mismo fallo con otra cara.
        $this->assertSame(count($lista), count($libro->getSheetNames()) - 2,
            'El libro trae un número de hojas distinto del que la pantalla promete.');

        sort($enElLibro);
        $esperados = array_keys($lista);
        sort($esperados);

        $this->assertSame($esperados, $enElLibro,
            'Las hojas del libro no son las asignaturas que lista `/periodos`.');
    }

    #[Test]
    public function un_periodo_sin_ninguna_unidad_montada_sale_como_libro_vacio_y_no_como_500(): void
    {
        $docente = $this->docenteConPlanilla();

        /*
         * **No es un caso de laboratorio: es el estado del docker hoy.** En
         * `caz_zaragoza` el periodo marcado como `actual` tiene **0 subunidades** —los
         * datos están en los del año anterior—, así que la primera vez que alguien
         * conduzca la pantalla de descarga contra el docker va a pedir exactamente
         * esto. Un 500 aquí se leería como «la función no funciona».
         */
        $vacio = DB::selectOne(
            'SELECT p.id FROM periodos p
              WHERE p.year_id = ? AND p.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM unidades u
                     INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.profesor_id = ?
                     WHERE u.periodo_id = p.id AND u.deleted_at IS NULL AND u.alumno_id IS NULL
                )
              ORDER BY p.numero LIMIT 1',
            [$docente->year_id, $docente->profesor_id]
        );

        if ($vacio === null) {
            // Se dice por qué no se comprobó, en vez de pasar en silencio: un test que
            // se salta sin motivo escrito es un test que nadie vuelve a mirar.
            $this->markTestSkipped('El seed no tiene ningún periodo sin unidades para este docente.');
        }

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$vacio->id);

        $r->assertStatus(200);

        $libro = IOFactory::load($this->archivoDescargado($r));

        $this->assertSame(LibroDeNotas::PORTADA, $libro->getSheetNames()[0]);
        $this->assertNotNull($libro->getSheetByName(LibroDeNotas::METADATOS));
    }

    #[Test]
    public function el_espejo_de_myvc_trae_una_fila_por_alumno_y_hoja(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $libro = IOFactory::load($this->archivoDescargado($r));
        $meta = $libro->getSheetByName(LibroDeNotas::METADATOS);

        $hojas = 0;
        $espejos = 0;

        foreach ($meta->getRowIterator() as $fila) {
            $clave = (string) $meta->getCell('A'.$fila->getRowIndex())->getValue();

            if ($clave === 'hoja') {
                $hojas++;
            } elseif ($clave === 'espejo') {
                $espejos++;
            }
        }

        $this->assertSame(count($libro->getSheetNames()) - 2, $hojas,
            'Tiene que haber una fila `hoja` por cada hoja de asignatura.');

        $alumnos = 0;

        foreach ($libro->getSheetNames() as $indice => $nombre) {
            if ($indice === 0 || $nombre === LibroDeNotas::METADATOS) {
                continue;
            }

            $mapa = null;

            foreach ($meta->getRowIterator() as $fila) {
                $f = $fila->getRowIndex();

                if ((string) $meta->getCell('A'.$f)->getValue() === 'hoja'
                    && (string) $meta->getCell('B'.$f)->getValue() === $nombre) {
                    $mapa = json_decode((string) $meta->getCell('C'.$f)->getValue(), true);

                    break;
                }
            }

            $this->assertNotNull($mapa, "La hoja «{$nombre}» no tiene mapa en `_myvc`.");
            $this->assertNotEmpty($mapa['columnas'], 'El mapa de columnas no puede venir vacío.');

            $alumnos += count($mapa['filas']);
        }

        $this->assertSame($alumnos, $espejos,
            'El espejo tiene que traer una fila por alumno y hoja: es lo que hace posible la D3.');
    }
}
