<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `GET horario/versiones/{id}/lecciones` — la cuarta ruta, la que se pinta.
 *
 * §9.bis de [23-horarios.md](../../docs/migracion/23-horarios.md). Joseth decidió el
 * 3 sep 2026 que el horario cuadrado en el escritorio se tiene que poder **mirar** en
 * la web, y el 4 cerró la forma con lo medido en los dos repositorios.
 *
 * ## Lo que este fichero defiende, y por qué no es «que devuelva 200»
 *
 * `myvc-horarios-90` midió **144 corridas** —3 colegios × 6 mutilaciones × 8
 * informes— y el reparto es el que manda aquí: **55 salen distintas sin ningún aviso
 * y a 8 se les APAGA un aviso que estaba encendido**. O sea que el modo de fallo de
 * esta ruta no es un error: es **una hoja bien maquetada y falsa**. Contra eso un
 * `assertStatus(200)` no defiende nada, así que lo que se ata aquí es:
 *
 *   1. **el juego exacto de claves** del sobre y de una lección — que un campo se
 *      caiga es lo que convierte «no lo sé» en «no tiene»;
 *   2. **que cada catálogo diga su estado y su población**, y que `vacio` y
 *      `sin_catalogo` **no se confundan** — es la distinción que sostiene la
 *      restricción de Joseth de que el horario sea opcional;
 *   3. **que el fichero de proyecto no salga por ninguna puerta**, con su control;
 *   4. **que la ruta no lea las siete columnas de día**, que es la garantía del
 *      §9.bis.4 y la única que no se ve mirando la respuesta;
 *   5. desde el 5 sep 2026, **que las cuatro listas de la decisión 38 —plantilla,
 *      jornadas, disponibilidad y piezas sin colocar— salgan del fichero con su forma
 *      comprobada**, con la marca metida DENTRO de cada una, y que el `tono` se mida
 *      sobre la población que viaja.
 *
 * ## Lo que NO fija
 *
 * El 403 de alumnos y acudientes es de `HorarioAutorizacionTest`, que desde el 4 sep
 * 2026 cubre las **cuatro** rutas. Aquí se entra como **personal llano**, que es el
 * sujeto más pequeño que puede llamar a esta ruta: con un superusuario el verde diría
 * menos de lo que parece.
 *
 * **Con UNA excepción desde el 6 sep 2026, y es a propósito**: la decisión 3 hace que el
 * autor de cada pega de disponibilidad viaje **sólo para quien puede publicar**, así que
 * ese hecho tiene dos casos y el segundo entra con superusuario
 * (`quien_puede_publicar_si_ve_de_quien_es_cada_pega`). Sin él, tachar el `profesor_id`
 * **para todo el mundo** saldría verde — *un permiso que no deja pasar a nadie se ve igual
 * que uno que funciona, si sólo se prueba el lado que se cierra*.
 */
class HorarioLeccionesTest extends CasoDeContrato
{
    /** Un trozo de texto que no puede salir de ningún otro sitio que del blob. */
    private const MARCA_DEL_BLOB = 'MARCA-QUE-SOLO-VIVE-EN-EL-PROYECTO-4c1d';

    /** Las claves del envoltorio, y ninguna más. */
    private const CLAVES_DEL_SOBRE = [
        'version', 'ejes', 'catalogos', 'lecciones', 'total_lecciones',
        // Las cuatro de la decisión 38, detrás de `catalogos` y nunca sin su renglón.
        'plantilla', 'jornadas', 'disponibilidad', 'sin_colocar',
    ];

    /** Las claves de un docente de la plantilla, y ninguna más. */
    private const CLAVES_DE_UN_DOCENTE_DE_LA_PLANTILLA = ['id', 'nombres', 'apellidos', 'tono', 'con_leccion'];

    /** Las claves de una jornada, tal como la declara el escritorio. */
    private const CLAVES_DE_UNA_JORNADA = ['dias', 'franjas', 'descansos_tras', 'timbres'];

    /** Las claves de una pieza sin colocar, y ninguna más. */
    private const CLAVES_DE_UNA_PIEZA_SIN_COLOCAR = ['pieza_id', 'duracion', 'asignaturas', 'docentes'];

    /** Las claves de cada lección, y ninguna más. */
    private const CLAVES_DE_UNA_LECCION = [
        'id', 'pieza_id', 'dia', 'franja', 'duracion',
        'asignatura_id', 'ih', 'materia', 'alias_materia',
        'grupo_id', 'nombre_grupo', 'abrev_grupo',
        'nombre_salon', 'salon_capacidad_grupos', 'docentes',
    ];

    /** Las claves de los ejes, y ninguna más. */
    private const CLAVES_DE_LOS_EJES = [
        'convenio_dia', 'dias', 'franjas', 'minutos_por_leccion', 'timbres', 'descansos_tras',
    ];

    /** Los catálogos que la respuesta declara, y ninguno menos. */
    private const CATALOGOS = [
        'grupos', 'asignaciones', 'docentes', 'plantilla', 'tono',
        'salones', 'jornadas', 'timbres', 'disponibilidad', 'sin_colocar', 'restricciones',
    ];

    /** El año del token: el del personal llano. */
    private function anioDelSujeto(): int
    {
        return (int) DB::selectOne(
            'SELECT p.year_id FROM users u JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?',
            [$this->usuarioLlanoDelPersonal()->id]
        )->year_id;
    }

    /**
     * Una asignación viva del año, con su grupo y su materia.
     *
     * Se **busca**, no se cablea: el seed se regenera y un id fijo se rompería sin
     * decir por qué. Si no hubiera ninguna, el test tiene que morir aquí con su
     * motivo y no doce líneas después con un `null`.
     */
    private function asignacionDe(int $yearId): object
    {
        $fila = DB::selectOne(
            'SELECT a.id, a.grupo_id, a.profesor_id, a.creditos
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL
              ORDER BY a.id LIMIT 1',
            [$yearId]
        );

        $this->assertNotNull($fila, "El año {$yearId} no tiene ni una asignación viva en la base de tests, "
            .'así que nada de este fichero estaría comprobando lo que dice.');

        return $fila;
    }

    /**
     * Mete una versión y devuelve su id. El blob va siempre: la columna es `NOT NULL`.
     *
     * **El blob por defecto es un proyecto LEGIBLE**, y desde el 4 sep 2026 eso importa:
     * `catalogos` distingue `sin_catalogo` de `ilegible`, así que un blob de mentira
     * —antes `{"proyecto":"<marca>"}`, con `proyecto` como cadena— haría que **todos los
     * casos de este fichero corrieran contra un colegio cuyo proyecto no se puede leer**.
     * Verde igual, midiendo otra cosa. La marca sigue dentro, en `programa`, que es donde
     * la busca el control de la fuga. Y desde el 5 sep 2026 es un proyecto **entero y
     * vacío** (`proyectoCompleto()`), por la misma razón un piso más abajo: con las cuatro
     * listas de la decisión 38, un proyecto sin las claves las deja en `ilegible`.
     */
    private function versionEn(int $yearId, string $nombre = 'Versión para pintar'): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, NULL, ?, NULL, ?, ?)',
            [$yearId, $nombre, $this->proyectoCompleto(),
                '2026-09-04 10:00:00', '2026-09-04 10:00:00']
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Una versión con el blob que se le diga, para ejercer la jornada.
     *
     * **La marca va dentro siempre**, aunque el blob lo escriba el caso: desde el
     * 4 sep 2026 esta ruta **carga el proyecto en memoria** para sacarle los
     * descansos, así que la fuga dejó de ser imposible por construcción y pasó a
     * depender de que nadie lo eche a la respuesta. Un blob de prueba sin marca
     * dejaría ese riesgo sin vigilar justo en los casos que lo estrenan.
     */
    private function versionConProyecto(int $yearId, string $blob, string $nombre = 'Versión con jornada'): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, NULL, ?, NULL, ?, ?)',
            [$yearId, $nombre, $blob, '2026-09-04 10:00:00', '2026-09-04 10:00:00']
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /** Un proyecto con la jornada que se le pase, y la marca dentro. */
    private function proyectoCon(string $jornada): string
    {
        return '{"formato":1,"programa":"'.self::MARCA_DEL_BLOB.'","proyecto":{"anio":2025,"jornadaPorDefecto":'.$jornada.'}}';
    }

    /** Lee `ejes.descansos_tras` de una versión cuyo proyecto trae esa jornada. */
    private function descansosCon(string $jornada): mixed
    {
        $anio = $this->anioDelSujeto();
        $version = $this->versionConProyecto($anio, $this->proyectoCon($jornada));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leer($version)->assertStatus(200);

        $this->assertStringNotContainsString(self::MARCA_DEL_BLOB, $r->getContent(),
            'Leer el proyecto para sacar los descansos ha empezado a filtrarlo en la respuesta.');

        return $r->json('ejes.descansos_tras');
    }

    /** Una lección colocada. `dia` en el convenio de la §5.2.5 y `franja` en base 1. */
    private function leccionEn(int $versionId, int $asignaturaId, string $piezaId, int $dia, int $franja, int $duracion = 1, ?string $salon = null): int
    {
        DB::insert(
            'INSERT INTO horario_lecciones (version_id, pieza_id, asignatura_id, dia, franja, duracion, salon, salon_capacidad_grupos)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL)',
            [$versionId, $piezaId, $asignaturaId, $dia, $franja, $duracion, $salon]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    private function docenteEnLaPieza(int $versionId, string $piezaId, int $profesorId): void
    {
        DB::insert(
            'INSERT INTO horario_pieza_docente (version_id, pieza_id, profesor_id) VALUES (?, ?, ?)',
            [$versionId, $piezaId, $profesorId]
        );
    }

    /** Dos profesores vivos, para el caso del capellán. */
    private function dosProfesores(): array
    {
        $filas = DB::select('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 2');

        $this->assertCount(2, $filas, 'Hacen falta dos profesores vivos para ejercer la pieza de varios docentes.');

        return [(int) $filas[0]->id, (int) $filas[1]->id];
    }

    /** `n` profesores vivos, por id. */
    private function profesores(int $n): array
    {
        $filas = DB::select('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT '.$n);

        $this->assertCount($n, $filas, "Hacen falta {$n} profesores vivos para este caso.");

        return array_map(fn ($f) => (int) $f->id, $filas);
    }

    /** `n` asignaciones vivas del año, distintas, con su grupo y su materia. */
    private function asignacionesDe(int $yearId, int $n): array
    {
        $filas = DB::select(
            'SELECT a.id, a.grupo_id, a.profesor_id, a.creditos
               FROM asignaturas a
               JOIN grupos g ON g.id = a.grupo_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL
              ORDER BY a.id LIMIT '.$n,
            [$yearId]
        );

        $this->assertCount($n, $filas, "El año {$yearId} no tiene {$n} asignaciones vivas en la base de tests.");

        return $filas;
    }

    /**
     * Un proyecto VACÍO pero ENTERO, con la marca dentro: lo que `nuevoProyecto()` del
     * escritorio produce, con las ocho claves y las listas en `[]`.
     *
     * Es el blob por defecto desde el 5 sep 2026, y la diferencia con el de antes
     * —`{"proyecto":{"anio":2025}}`— no es de aseo: con las cuatro listas de la decisión
     * 38, un proyecto sin las claves deja sus renglones en `ilegible`, y entonces cada
     * caso de este fichero correría contra un colegio cuyo fichero no se entiende. Verde
     * igual, midiendo otra cosa. Las partes que se pasen sustituyen a la clave entera.
     */
    private function proyectoCompleto(array $partes = []): string
    {
        $base = [
            'anio' => 2025,
            'jornadaPorDefecto' => ['dias' => [1, 2, 3, 4, 5], 'franjas' => 7, 'descansosTras' => [3, 5], 'timbres' => null],
            'niveles' => [],
            'grupos' => [],
            'docentes' => [],
            'salones' => [],
            'asignaciones' => [],
            'piezas' => [],
            'colocaciones' => [],
        ];

        return (string) json_encode(
            ['formato' => 1, 'programa' => self::MARCA_DEL_BLOB, 'proyecto' => array_replace($base, $partes)],
            JSON_UNESCAPED_UNICODE
        );
    }

    /** Lee la versión, exige 200 y exige que la marca del blob no haya salido por ninguna clave. */
    private function leerSinFuga(int $versionId)
    {
        $r = $this->leer($versionId)->assertStatus(200);

        $this->assertStringNotContainsString(self::MARCA_DEL_BLOB, $r->getContent(),
            'El fichero de proyecto ha salido en la respuesta por alguna de las listas nuevas.');

        return $r;
    }

    private function leer(int $versionId, ?string $token = null)
    {
        return $this->getJson("/api/horario/versiones/{$versionId}/lecciones", [
            'Authorization' => 'Bearer '.($token ?? $this->tokenDelPersonalLlano()),
        ]);
    }

    /**
     * El sobre y la lección traen exactamente las claves del contrato.
     *
     * Por los **dos** lados: ni de menos —una clave que se cae convierte «no lo sé»
     * en «no tiene», que es el fallo de las 75 casillas que pierden el salón— ni de
     * más, que es como se filtra lo que nadie decidió mandar.
     */
    #[Test]
    public function el_sobre_y_la_leccion_traen_las_claves_del_contrato(): void
    {
        $anio = $this->anioDelSujeto();
        $asignacion = $this->asignacionDe($anio);
        $version = $this->versionEn($anio);
        $this->leccionEn($version, (int) $asignacion->id, 'a1-0', 4, 2);

        $r = $this->leer($version)->assertStatus(200);

        $this->assertSame(self::CLAVES_DEL_SOBRE, array_keys($r->json()));
        $this->assertSame(self::CLAVES_DE_UNA_LECCION, array_keys($r->json('lecciones.0')));
        $this->assertSame(self::CATALOGOS, array_keys($r->json('catalogos')),
            'Un catálogo sin su renglón en `catalogos` es un error del servidor, no un catálogo vacío: '
            .'es lo único que impide que una lista corta se lea como una lista completa.');
        $this->assertSame(1, $r->json('total_lecciones'));
    }

    /**
     * El fichero de proyecto **no sale**, y esta ruta es donde más apetecería.
     *
     * `getVersiones` ya lo protege porque *listar no es descargar* (decisión 12);
     * aquí la regla se extiende a *mirar no es llevarse* (§9.bis). Llevarse el
     * proyecto a otro computador es otra ruta y otro permiso.
     */
    #[Test]
    public function el_proyecto_no_viaja_en_las_lecciones(): void
    {
        $anio = $this->anioDelSujeto();
        $version = $this->versionEn($anio);
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leer($version)->assertStatus(200);

        $this->assertStringNotContainsString(self::MARCA_DEL_BLOB, $r->getContent(),
            'El fichero de proyecto del colegio ha salido en la respuesta, da igual bajo qué clave.');
    }

    /**
     * Y su control: el blob **está** en la base y es alcanzable.
     *
     * Sin esto, el verde de arriba no distingue «no se filtra» de «la marca nunca
     * llegó a guardarse», que es el «0 encontrados» sin población.
     */
    #[Test]
    public function el_control_de_la_fuga_sabe_ponerse_rojo(): void
    {
        $version = $this->versionEn($this->anioDelSujeto());

        $fila = DB::selectOne('SELECT hv.* FROM horario_versiones hv WHERE hv.id = ?', [$version]);

        $this->assertStringContainsString(self::MARCA_DEL_BLOB, (string) $fila->proyecto);
    }

    /**
     * Una versión de otro año da **404**, no 403 y no 200.
     *
     * El año sale del token y va en el `WHERE` junto al id: «no existe» y «no es de
     * tu año» tienen que ser la misma respuesta, o preguntando por ids se averigua
     * qué versiones tienen los otros años.
     */
    #[Test]
    public function una_version_de_otro_anio_no_se_puede_mirar(): void
    {
        $anio = $this->anioDelSujeto();

        $otro = (int) DB::selectOne('SELECT id FROM years WHERE id <> ? ORDER BY id LIMIT 1', [$anio])->id;
        $ajena = $this->versionEn($otro, 'La de otro año');

        $this->leer($ajena)->assertStatus(404);

        // El control: la fila existe de verdad, así que el 404 es del año y no de que
        // no hubiera nada que encontrar.
        $this->assertNotNull(DB::selectOne('SELECT id FROM horario_versiones WHERE id = ?', [$ajena]));
    }

    /**
     * Una lección sin docentes llega con `docentes: []`, y **es el caso normal**.
     *
     * Medido el 4 sep 2026 sobre la única versión real que existe: **22 de 312**
     * piezas no tienen ni una fila en `horario_pieza_docente`.
     */
    #[Test]
    public function una_leccion_sin_docentes_llega_con_la_lista_vacia(): void
    {
        $anio = $this->anioDelSujeto();
        $version = $this->versionEn($anio);
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 2, 3);

        $r = $this->leer($version)->assertStatus(200);

        $this->assertSame([], $r->json('lecciones.0.docentes'));
        $this->assertSame(1, $r->json('catalogos.docentes.lecciones_sin_docente'),
            'La respuesta tiene que CONTAR las lecciones sin docente, no sólo dejarlas vacías: '
            .'un hueco que no se cuenta no se distingue de un hueco que no existe.');
    }

    /**
     * Los docentes van en LISTA, y aquí está el porqué ejercido: **la misa**.
     *
     * Si la misa la da el capellán, el titular de Religión tiene esa hora libre
     * aunque la hora salga de su asignación (§5.1). Con `profesor_id` escalar —que
     * es lo que pidió el front— el segundo docente **se cae sin ningún error**.
     * Hoy no pasaría: 0 de 312 piezas tienen dos. Este test es lo que hace que siga
     * sin pasar el día que la misa exista.
     */
    #[Test]
    public function una_pieza_con_dos_docentes_los_devuelve_los_dos(): void
    {
        $anio = $this->anioDelSujeto();
        $version = $this->versionEn($anio);
        [$uno, $otro] = $this->dosProfesores();

        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 3, 1);
        $this->docenteEnLaPieza($version, 'a1-0', $uno);
        $this->docenteEnLaPieza($version, 'a1-0', $otro);

        $docentes = $this->leer($version)->assertStatus(200)->json('lecciones.0.docentes');

        $this->assertCount(2, $docentes, 'La pieza de varios docentes ha perdido uno por el camino.');
        $this->assertEqualsCanonicalizing([$uno, $otro], array_column($docentes, 'id'));
        $this->assertSame(['id', 'nombres', 'apellidos', 'tono'], array_keys($docentes[0]));
    }

    /**
     * Los salones **a medias** dicen su población, que es el caso que ocurre de verdad.
     *
     * Y es el que hace MENOS ruido: fuera del todo, un informe sale con cero hojas y
     * alguien pregunta; a medias, seis hojas se quedan en tres y **cero avisos**.
     * Medido en la versión real: 87 de 312 con salón y 3 nombres distintos.
     */
    #[Test]
    public function los_salones_a_medias_dicen_su_poblacion(): void
    {
        $anio = $this->anioDelSujeto();
        $asignacion = $this->asignacionDe($anio);
        $version = $this->versionEn($anio);

        $this->leccionEn($version, (int) $asignacion->id, 'a1-0', 1, 1, 1, 'Laboratorio');
        $this->leccionEn($version, (int) $asignacion->id, 'a1-1', 1, 2);

        $salones = $this->leer($version)->assertStatus(200)->json('catalogos.salones');

        $this->assertSame('parcial', $salones['estado']);
        $this->assertSame(1, $salones['con_salon']);
        $this->assertSame(2, $salones['de']);
        $this->assertSame(1, $salones['distintos']);
        $this->assertFalse($salones['hay_ids'], 'No hay tabla de salones: prometer ids sería prometer lo que no existe.');
    }

    /**
     * **La invariante de Joseth: el horario es OPCIONAL.**
     *
     * Un colegio que sólo tiene asignaturas con IH —sin un solo salón, sin ninguna
     * lección doble y sin colores repartidos— recibe **200** y sus catálogos en
     * `vacio`. Nunca un 422 y nunca una lista corta que no diga que es corta.
     *
     * Y la distinción que sostiene todo esto: **`vacio` no es `sin_catalogo`**. El
     * primero dice «el colegio no creó ninguno, y es legítimo»; el segundo, «esta API
     * no puede saberlo». Si se confundieran, la única forma de que la pantalla no
     * mintiera sería exigirle al colegio que rellene salones y timbres — o sea,
     * volver obligatorio por la puerta de atrás lo que él dejó opcional.
     *
     * Desde el 5 sep 2026 los timbres **se leen del fichero** y por eso ya no son
     * `sin_catalogo`: que el colegio no los haya dado es `vacio`, legítimo y sin llamada
     * a la acción. `restricciones` es lo único que sigue sin viajar por diseño.
     */
    #[Test]
    public function una_version_sin_salones_sin_dobles_y_sin_colores_es_legitima(): void
    {
        $anio = $this->anioDelSujeto();
        $version = $this->versionEn($anio);
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 5, 1);

        $catalogos = $this->leer($version)->assertStatus(200)->json('catalogos');

        $this->assertSame('vacio', $catalogos['salones']['estado'],
            'Sin salones el estado es `vacio` —el colegio no creó ninguno— y no `sin_catalogo`.');
        $this->assertSame('vacio', $catalogos['tono']['estado']);
        $this->assertSame('vacio', $catalogos['timbres']['estado'],
            'El colegio no dio las horas de reloj: eso es `vacio`, no «esta API no puede saberlo».');

        // Y lo que la API estructuralmente no tiene, dicho como tal y no como vacío.
        $this->assertSame('sin_catalogo', $catalogos['restricciones']['estado'],
            '`restricciones` no lo parsea esta ruta (§4): mandarlo vacío sería decir que el colegio no lo tiene.');
        $this->assertNotNull($catalogos['restricciones']['motivo'], 'Un `sin_catalogo` sin motivo no se puede leer dentro de seis meses.');
    }

    /**
     * El `tono` viaja y dice su población, **y la población es la que viaja**.
     *
     * Hasta el 5 sep 2026 se contaba sobre «con asignación viva en el año» y decía
     * `completo · 12 de 12` con 47 docentes vivos y 35 sin color: coherente consigo mismo
     * y sobre la población que no era, y el lector del front **no tenía con qué dudar**.
     * Ahora `de` es el número de docentes distintos que van en esta misma respuesta
     * —plantilla, lecciones y piezas sin colocar—, así que el consumidor puede recontar
     * lo que recibió y comprobar que cuadra. Aquí se hace exactamente eso.
     *
     * La columna la decidió Joseth el 4 sep 2026 y **nace vacía en los diecisiete**:
     * por eso el contrato dice `string | null` y el nulo es el caso normal.
     */
    #[Test]
    public function el_tono_dice_cuantos_docentes_lo_tienen(): void
    {
        $anio = $this->anioDelSujeto();
        $asignacion = $this->asignacionDe($anio);
        [$conLeccion, $sinLeccion] = $this->profesores(2);
        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [['profesorId' => $conLeccion, 'nombre' => 'x'], ['profesorId' => $sinLeccion, 'nombre' => 'x']],
        ]));
        $this->leccionEn($version, (int) $asignacion->id, 'a1-0', 1, 1);
        $this->docenteEnLaPieza($version, 'a1-0', $conLeccion);

        $sinColores = $this->leer($version)->assertStatus(200)->json('catalogos.tono');
        $this->assertSame('vacio', $sinColores['estado']);
        $this->assertSame(0, $sinColores['con_tono']);
        $this->assertSame(2, $sinColores['de'], 'La población es la plantilla que viaja, no los docentes con lección.');

        // Un color: `parcial`, que es lo que el escritorio necesita saber —seis de sus
        // ocho informes pintan distinto sin él y nada se pone rojo—.
        DB::update('UPDATE profesores SET tono = ? WHERE id = ?', ['#3366cc', $conLeccion]);

        $r = $this->leer($version)->assertStatus(200);
        $conUno = $r->json('catalogos.tono');
        $this->assertSame('parcial', $conUno['estado']);
        $this->assertSame(1, $conUno['con_tono']);

        // La comprobación que el front no podía escribir: el `de` es lo que recibió.
        $ids = array_column($r->json('plantilla'), 'id');
        foreach ($r->json('lecciones') as $l) {
            $ids = [...$ids, ...array_column($l['docentes'], 'id')];
        }
        $this->assertSame(count(array_unique($ids)), $conUno['de'],
            'Una cuenta que cuadra sobre la población equivocada no falla, y por eso no se investiga: '
            .'el denominador tiene que ser recontable desde la propia respuesta.');

        // Y el segundo color, sin lección, cuenta igual: es de la plantilla.
        DB::update('UPDATE profesores SET tono = ? WHERE id = ?', ['#cc3366', $sinLeccion]);
        $this->assertSame('completo', $this->leer($version)->assertStatus(200)->json('catalogos.tono.estado'));
    }

    /**
     * Los ejes salen **de las lecciones**, y los timbres van `null` a propósito.
     *
     * La rejilla del colegio vive en el fichero de proyecto (§4), así que devolver
     * una jornada por defecto no sería un valor razonable: **le apaga al escritorio
     * el aviso «sin horas: el colegio todavía no ha dado los timbres» a 15 hojas**,
     * que entonces imprimen un horario que ese nivel nunca dio.
     */
    #[Test]
    public function los_ejes_salen_de_las_lecciones_y_los_timbres_van_nulos(): void
    {
        $anio = $this->anioDelSujeto();
        $asignacion = $this->asignacionDe($anio);
        $version = $this->versionEn($anio);

        $this->leccionEn($version, (int) $asignacion->id, 'a1-0', 3, 5);
        $this->leccionEn($version, (int) $asignacion->id, 'a1-1', 1, 2);

        $ejes = $this->leer($version)->assertStatus(200)->json('ejes');

        $this->assertSame(self::CLAVES_DE_LOS_EJES, array_keys($ejes),
            'Los ejes traen exactamente estas claves: una que se caiga —`descansos_tras`, por ejemplo— '
            .'no da error, deja la parrilla corrida y se lee como que el colegio no descansa.');
        $this->assertSame([1, 3], $ejes['dias'], 'Los días son los que la versión usa, no una rejilla inventada.');
        $this->assertSame([2, 5], $ejes['franjas']);
        $this->assertNull($ejes['timbres']);
        $this->assertStringContainsString('0=domingo', $ejes['convenio_dia'],
            'El convenio del día se DECLARA: un horario corrido un día cumple todas las reglas de la §6 '
            .'y no lo detecta nadie.');
    }

    /**
     * Los descansos salen del proyecto, y **los tres estados son tres cosas distintas**.
     *
     * El dato lleva desde el 2 sep 2026 en el blob que esta ruta ya leía —a un
     * `json_decode` de distancia— y nadie lo pidió porque el renglón `timbres` de
     * `catalogos` decía que la jornada *«vive en el fichero de proyecto»*: verdad, y
     * el fichero está en la columna de al lado. Lo levantó `myvc-front-c0` abriendo el
     * blob, no leyendo código.
     *
     * **Base 1 y «tras»**: un `3` es *después de la tercera lección*, no *en la tercera*.
     */
    #[Test]
    public function los_descansos_salen_del_proyecto(): void
    {
        $this->assertSame([3, 5], $this->descansosCon('{"dias":[1,2,3,4,5],"franjas":7,"timbres":null,"descansosTras":[3,5]}'),
            'Es el valor exacto que traen los siete `horario_versiones` reales de `simonbolivar`.');
    }

    /**
     * **La lista vacía es un dato: el colegio no descansa.** Y `null` es no saberlo.
     *
     * Es la distinción entera de este lote y la misma que sostiene `vacio` contra
     * `sin_catalogo` en los catálogos. Si «no lo sé» saliera como `[]`, el front
     * pintaría una parrilla corrida **con toda la confianza del mundo** y nadie
     * recibiría ningún error: la hoja bien maquetada y falsa.
     *
     * Los dos casos van en el mismo test **a propósito**: separados, cada mitad puede
     * pasar con la otra rota, y lo que hay que defender es que **no se confundan**.
     */
    #[Test]
    public function la_lista_vacia_y_el_nulo_no_son_lo_mismo(): void
    {
        $noDescansa = $this->descansosCon('{"dias":[1,2,3,4,5],"franjas":7,"descansosTras":[]}');
        $noSeSabe = $this->descansosCon('{"dias":[1,2,3,4,5],"franjas":7,"timbres":null}');

        $this->assertSame([], $noDescansa,
            'El proyecto dice que no hay descansos: eso es `[]`, un dato, y no un hueco.');
        $this->assertNull($noSeSabe,
            'Sin la clave `descansosTras` no se sabe, y «no lo sé» NO se aplasta a «no hay».');
        $this->assertNotSame($noDescansa, $noSeSabe,
            'Los dos estados se han confundido: es exactamente lo que este caso existe para impedir.');
    }

    /**
     * **Un proyecto que no parsea no revienta la ruta**: cae a `null`.
     *
     * No es un caso de laboratorio. El blob es texto libre —129.550 bytes en el mayor
     * de los siete reales— que sube un programa de escritorio, así que un fichero a
     * medias es de las cosas que pasan; y desde este lote la ruta lo **decodifica**, o
     * sea que antes daba igual y ahora no. Las tres formas se rompen de verdad, no con
     * un mock: JSON inválido, JSON válido que no es un objeto, y el `proyecto` como
     * cadena — que es la forma que ya usaba el resto de este fichero sin saberlo.
     */
    #[Test]
    public function un_proyecto_ilegible_no_revienta_la_ruta(): void
    {
        $anio = $this->anioDelSujeto();

        $rotos = [
            'JSON inválido' => '{"proyecto":{"jornadaPorDefecto":{"descansosTras":[3,',
            'JSON válido que no es un objeto' => '"'.self::MARCA_DEL_BLOB.'"',
            '`proyecto` es una cadena' => '{"proyecto":"'.self::MARCA_DEL_BLOB.'"}',
            'la jornada no es un objeto' => '{"proyecto":{"jornadaPorDefecto":7}}',
        ];

        foreach ($rotos as $forma => $blob) {
            $version = $this->versionConProyecto($anio, $blob, 'Proyecto roto');
            $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

            $r = $this->leer($version)->assertStatus(200, "Con el proyecto roto por «{$forma}» la ruta tiene que seguir contestando.");

            $this->assertNull($r->json('ejes.descansos_tras'), "«{$forma}» tiene que dar `null`, no `[]` ni un 500.");
            $this->assertSame(1, $r->json('total_lecciones'), "«{$forma}» no puede llevarse por delante el resto de la respuesta.");
        }
    }

    /**
     * Una lista con algo que no es un entero sale **`null` entera**, no filtrada.
     *
     * Filtrar dejaría las líneas buenas y borraría la mala **sin decirlo**, y una línea
     * gruesa en el sitio equivocado es indistinguible de una correcta — la misma
     * familia de fallo por la que el convenio `0 = domingo` se declara en vez de
     * deducirse. *Un dato que no se entiende entero no se entiende.*
     */
    #[Test]
    public function una_lista_con_basura_no_se_filtra_a_medias(): void
    {
        $this->assertNull($this->descansosCon('{"descansosTras":[3,"cinco"]}'),
            'Devolver `[3]` habría pintado una parrilla creíble a la que le falta una línea.');
        $this->assertNull($this->descansosCon('{"descansosTras":{"manana":3}}'),
            'Un objeto JSON llega como array de PHP, y `{"manana":3}` no es una lista de franjas.');
    }

    /**
     * **El quinto estado: `ilegible` no es `sin_catalogo`, y los del blob cambian juntos.**
     *
     * Decisión de Joseth del 4 sep 2026. `sin_catalogo` afirma *«esta API no puede saberlo
     * **por diseño**»* —una frase sobre el producto, igual en los dieciséis colegios y que no
     * arregla nadie desde la web—; `ilegible` dice *«lo tenemos guardado y no se deja leer»*,
     * que es sobre **ese colegio y esa subida** y **tiene arreglo: volver a subirlo**. De los
     * cinco estados es el único que cambia lo que la persona debería hacer.
     *
     * **Todos a la vez, y eso es la mitad del caso**: si el fichero no se lee, marcar sólo
     * `timbres` dejaría a los demás diciendo «no viaja» o «vacío» —el diseño de la API, o un
     * dato— cuando lo cierto es «no se pudo leer» —ese colegio—. Desde el 5 sep 2026 son
     * **seis** renglones y **cuatro listas**, y las cuatro salen `null`.
     *
     * **Y ninguna de estas formas sale de un volcado real**: las ocho versiones de
     * `simonbolivar` parsean. Esta rama está comprobada contra un caso fabricado y **no se ha
     * visto nunca funcionando con datos de verdad**, que no es lo mismo que estar comprobada.
     */
    #[Test]
    public function un_proyecto_ilegible_marca_los_catalogos_del_blob(): void
    {
        $anio = $this->anioDelSujeto();
        $delBlob = ['plantilla', 'jornadas', 'timbres', 'disponibilidad', 'sin_colocar', 'restricciones'];

        $legible = $this->versionEn($anio);
        $this->leccionEn($legible, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);
        $conProyecto = $this->leer($legible)->assertStatus(200)->json('catalogos');

        $roto = $this->versionConProyecto($anio, '{"proyecto":{"jornadaPorDefecto":[3,', 'Proyecto roto');
        $this->leccionEn($roto, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);
        $r = $this->leer($roto)->assertStatus(200);
        $sinProyecto = $r->json('catalogos');

        foreach ($delBlob as $cual) {
            $this->assertNotSame('ilegible', $conProyecto[$cual]['estado'],
                "Con el proyecto legible, `{$cual}` es un dato o un diseño, nunca «no se pudo leer».");
            $this->assertSame('ilegible', $sinProyecto[$cual]['estado'],
                "Con el proyecto ilegible, `{$cual}` no es «no lo tenemos»: es «lo tenemos y no se deja leer», "
                .'que es lo único de los cinco estados que alguien puede arreglar.');
        }

        $this->assertSame('sin_catalogo', $conProyecto['restricciones']['estado'],
            '`restricciones` sigue sin viajar POR DISEÑO cuando el fichero se lee.');
        $this->assertNotSame($conProyecto['timbres']['motivo'], $sinProyecto['timbres']['motivo'],
            'El motivo tiene que cambiar con el estado: uno explica el dato y el otro acusa a una subida.');

        foreach (['plantilla', 'jornadas', 'disponibilidad', 'sin_colocar'] as $lista) {
            $this->assertNull($r->json($lista), "`{$lista}` tiene que ser `null`, no `[]`: «no se pudo leer» no es «no hay».");
        }
    }

    /**
     * Y lo que el quinto estado **no** contagia: los catálogos que no salen del blob.
     *
     * `salones`, `tono`, `grupos`, `asignaciones` y `docentes` salen de tablas, así que un
     * proyecto ilegible **no los toca**. Sin este caso, marcarlos todos por descuido pasaría
     * inadvertido y la pantalla diría que no se sabe nada de un colegio del que se sabe casi
     * todo.
     */
    #[Test]
    public function un_proyecto_ilegible_no_contagia_a_los_catalogos_de_tabla(): void
    {
        $anio = $this->anioDelSujeto();
        $roto = $this->versionConProyecto($anio, 'esto no es json', 'Proyecto roto');
        $this->leccionEn($roto, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $catalogos = $this->leer($roto)->assertStatus(200)->json('catalogos');

        foreach (['grupos', 'asignaciones', 'docentes', 'tono', 'salones'] as $cual) {
            $this->assertNotSame('ilegible', $catalogos[$cual]['estado'],
                "`{$cual}` sale de una tabla, no del blob: que el proyecto no se lea no dice nada de él.");
        }
    }

    /**
     * **Y comprobar la forma es además lo que impide una fuga**, que no era el motivo.
     *
     * Medido el 4 sep 2026 sustituyendo el método por la cadena ingenua
     * `$blob['proyecto']['jornadaPorDefecto']['descansosTras'] ?? null`: **no revienta
     * la ruta** —el `??` sobre un offset de cadena devuelve `null` y ya está, así que
     * ése no es el argumento— pero **devuelve tal cual lo que hubiera ahí**. Y ahí
     * puede haber cualquier cosa: el blob lo escribe un programa de escritorio y esta
     * ruta tiene por contrato que **el proyecto no sale** (decisión 12, §9.bis).
     *
     * O sea que el campo se convertiría en **una puerta de salida del fichero de
     * proyecto con nombre de dato**, y `el_proyecto_no_viaja_en_las_lecciones` no la
     * vería: aquella marca vive en `programa`, no dentro de `descansosTras`. Aquí la
     * marca se mete **dentro del propio valor**, que es el único sitio donde la cadena
     * ingenua la dejaría salir.
     */
    #[Test]
    public function la_comprobacion_de_forma_tambien_cierra_la_fuga(): void
    {
        $this->assertNull($this->descansosCon('{"descansosTras":["'.self::MARCA_DEL_BLOB.'"]}'),
            'Una lista de cadenas no es una lista de franjas, y devolverla sacaría texto del proyecto '
            .'por un campo que dice llamarse `descansos_tras`.');
    }

    /**
     * **La garantía del §9.bis.4: esta ruta NO lee las siete columnas de día.**
     *
     * Hay dos escritores de esas columnas —`toggleDia` de la pantalla de asignaturas
     * y `putOficial`—, y Joseth cerró el 4 sep 2026 que los booleanos son del panel
     * del docente y **no alimentan el horario**. Es la única de las cuatro garantías
     * de este fichero que **no se ve mirando la respuesta**: aquí se conmutan las
     * siete a mano y se exige que la rejilla no se mueva ni un campo.
     */
    #[Test]
    public function no_lee_las_siete_columnas_de_dia(): void
    {
        $anio = $this->anioDelSujeto();
        $asignacion = $this->asignacionDe($anio);
        $version = $this->versionEn($anio);
        $this->leccionEn($version, (int) $asignacion->id, 'a1-0', 4, 2);

        $antes = $this->leer($version)->assertStatus(200)->json('lecciones');

        DB::update(
            'UPDATE asignaturas SET domingo = 1, lunes = 1, martes = 1, miercoles = 1,
                                    jueves = 0, viernes = 1, sabado = 1
              WHERE id = ?',
            [(int) $asignacion->id]
        );

        $despues = $this->leer($version)->assertStatus(200)->json('lecciones');

        $this->assertSame($antes, $despues,
            'La rejilla ha cambiado al conmutar las columnas de día de `asignaturas`. Esas columnas son '
            .'el derivado para «Clases de hoy» y tienen OTRO escritor: si esta ruta las leyera, dos '
            .'pantallas dirían cosas distintas del mismo día sin que nada fallara.');
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // Las cuatro listas de la decisión 38 — 5 sep 2026. Cada caso mete la MARCA
    // DENTRO de la lista que ejerce, porque `el_proyecto_no_viaja_en_las_lecciones`
    // la busca en `programa` y no vería ninguna de las cuatro.
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * La plantilla ENTERA viaja, con quién tiene lección y quién no.
     *
     * En el colegio medido son **47 docentes y sólo 12 con lección**: por las lecciones
     * los otros 35 desaparecían de un informe que existe para nombrarlos —los que ese
     * día no vinieron— y su cuenta `enHueco + ocupados + sinClases === docentes` no se
     * podía cuadrar. **Y los nombres salen de `profesores`, no del fichero**: el `nombre`
     * del blob es la marca, y no puede aparecer.
     */
    #[Test]
    public function la_plantilla_entera_viaja_aunque_no_tenga_leccion(): void
    {
        $anio = $this->anioDelSujeto();
        [$uno, $dos, $tres] = $this->profesores(3);
        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [
                ['profesorId' => $uno, 'nombre' => self::MARCA_DEL_BLOB, 'disponibilidad' => ['marcas' => []]],
                ['profesorId' => $dos, 'nombre' => self::MARCA_DEL_BLOB, 'disponibilidad' => ['marcas' => []]],
                ['profesorId' => $tres, 'nombre' => self::MARCA_DEL_BLOB],
            ],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);
        $this->docenteEnLaPieza($version, 'a1-0', $uno);

        $r = $this->leerSinFuga($version);
        $plantilla = $r->json('plantilla');
        $renglon = $r->json('catalogos.plantilla');

        $this->assertCount(3, $plantilla, 'Han viajado sólo los docentes con lección: es el informe de 12 de 47 otra vez.');
        $this->assertSame(self::CLAVES_DE_UN_DOCENTE_DE_LA_PLANTILLA, array_keys($plantilla[0]));
        $this->assertEqualsCanonicalizing([$uno, $dos, $tres], array_column($plantilla, 'id'));

        $porId = array_column($plantilla, null, 'id');
        $this->assertTrue($porId[$uno]['con_leccion']);
        $this->assertFalse($porId[$dos]['con_leccion']);
        $this->assertFalse($porId[$tres]['con_leccion'], 'Sin la clave `disponibilidad` el docente sigue siendo de la plantilla.');

        $ficha = DB::selectOne('SELECT nombres, apellidos FROM profesores WHERE id = ?', [$uno]);
        $this->assertSame($ficha->nombres, $porId[$uno]['nombres'], 'El nombre tiene que ser el de `profesores`, no el que trae el fichero.');
        $this->assertSame($ficha->apellidos, $porId[$uno]['apellidos']);

        $this->assertSame('completo', $renglon['estado']);
        $this->assertSame(3, $renglon['total']);
        $this->assertSame(1, $renglon['con_leccion']);
        $this->assertSame(2, $renglon['sin_leccion']);
        $this->assertSame(0, $renglon['fuera_de_la_plantilla']);
        $this->assertSame($renglon['total'], count($plantilla), 'La población del renglón tiene que ser la de la lista que viaja.');
    }

    /**
     * Un docente con lección que la plantilla no declara deja el renglón en `parcial`.
     *
     * Es la única forma en que la cuenta del informe puede dejar de cuadrar: alguien
     * ocupado a quien el total no cuenta. Hoy es 0 de 47; este caso es lo que lo dice
     * el día que deje de serlo.
     */
    #[Test]
    public function un_docente_con_leccion_fuera_de_la_plantilla_la_deja_parcial(): void
    {
        $anio = $this->anioDelSujeto();
        [$declarado, $intruso] = $this->profesores(2);
        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [['profesorId' => $declarado, 'nombre' => 'x']],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);
        $this->docenteEnLaPieza($version, 'a1-0', $intruso);

        $renglon = $this->leerSinFuga($version)->json('catalogos.plantilla');

        $this->assertSame('parcial', $renglon['estado']);
        $this->assertSame(1, $renglon['total']);
        $this->assertSame(1, $renglon['fuera_de_la_plantilla']);
    }

    /**
     * **Y el caso que decide el orden del ternario: plantilla VACÍA con docentes dando clase.**
     *
     * `vacio` significa *«el colegio no declaró ninguno, y es legítimo»* y **no lleva llamada a
     * la acción**; `parcial` sí. Con el ternario escrito al derecho —mirando primero si el total
     * es cero— este caso salía `vacio`, o sea **un vacío que no es vacío**: hay gente dando clase
     * a la que el total no cuenta, que es exactamente lo que rompe la cuenta del informe y lo que
     * nadie iría a mirar. Es la misma familia que `[]` contra `null`, con las dos palabras que
     * este módulo ya tenía.
     */
    #[Test]
    public function una_plantilla_vacia_con_docentes_dando_clase_no_es_vacio(): void
    {
        $anio = $this->anioDelSujeto();
        [$intruso] = $this->profesores(1);
        $version = $this->versionConProyecto($anio, $this->proyectoCompleto(['docentes' => []]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);
        $this->docenteEnLaPieza($version, 'a1-0', $intruso);

        $renglon = $this->leerSinFuga($version)->json('catalogos.plantilla');

        $this->assertSame(0, $renglon['total']);
        $this->assertSame(1, $renglon['fuera_de_la_plantilla']);
        $this->assertSame('parcial', $renglon['estado'],
            'Una plantilla de cero con alguien dando clase NO es «el colegio no declaró ninguno»: '
            .'es una plantilla a la que le falta quien está ocupado, y `vacio` no lo hace mirar a nadie.');
    }

    /**
     * Una plantilla con una entrada que no se entiende sale `null` ENTERA, no filtrada.
     *
     * La misma regla que los descansos: filtrar dejaría 46 docentes creíbles y borraría
     * uno sin decirlo, y en un informe de «quién falta» eso es exactamente un docente que
     * deja de faltar. Y la marca va en el `profesorId`, que es por donde la versión
     * ingenua la dejaría salir.
     */
    #[Test]
    public function una_plantilla_rota_no_se_filtra_a_medias(): void
    {
        $anio = $this->anioDelSujeto();
        [$uno] = $this->profesores(1);
        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [['profesorId' => $uno, 'nombre' => 'x'], ['profesorId' => self::MARCA_DEL_BLOB, 'nombre' => 'x']],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leerSinFuga($version);

        $this->assertNull($r->json('plantilla'), 'Devolver la lista con un docente menos es la hoja bien maquetada y falsa.');
        $this->assertNull($r->json('disponibilidad'), 'La disponibilidad sale de la misma lista: si ésa no se entiende, tampoco.');
        $this->assertSame('ilegible', $r->json('catalogos.plantilla.estado'));
        $this->assertStringContainsString('docentes', $r->json('catalogos.plantilla.motivo'),
            'El motivo tiene que nombrar la parte del fichero que no se entendió: no es el fichero entero.');
        $this->assertSame('completo', $r->json('catalogos.grupos.estado'), 'Lo de tabla no se contagia.');
    }

    /**
     * Las jornadas viajan por NIVEL —cuatro, no trece— y cada grupo dice de cuál cuelga **y por qué**.
     *
     * El `porque` se calcula igual que `jornadaDelGrupo()` del escritorio, y sin él un
     * grupo cuyo nivel no resuelve se pinta con la jornada por defecto **sin decirlo**: en
     * pantalla se aclara con un aviso al lado; en papel no hay dónde ponerlo después. Los
     * cuatro porqués salen aquí a la vez. Y el nombre del nivel es el de
     * `niveles_educativos` —el del blob es la marca— y el `grado` de `sin-resolver`, que es
     * texto libre, no sale.
     */
    #[Test]
    public function las_jornadas_viajan_por_nivel_con_el_porque_de_cada_grupo(): void
    {
        $anio = $this->anioDelSujeto();
        $nivel = DB::selectOne('SELECT id, nombre FROM niveles_educativos ORDER BY id LIMIT 1');
        $this->assertNotNull($nivel, 'Sin un nivel educativo en la base no se puede resolver ningún nombre.');
        $jornada = ['dias' => [1, 2, 3], 'franjas' => 4, 'descansosTras' => [2], 'timbres' => null];

        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'niveles' => [['id' => (int) $nivel->id, 'nombre' => self::MARCA_DEL_BLOB, 'jornada' => $jornada]],
            'grupos' => [
                ['id' => 10, 'nombre' => 'x', 'nivel' => ['estado' => 'resuelto', 'nivelId' => (int) $nivel->id]],
                ['id' => 11, 'nombre' => 'x', 'nivel' => ['estado' => 'sin-nivel']],
                ['id' => 12, 'nombre' => 'x', 'nivel' => ['estado' => 'sin-resolver', 'grado' => self::MARCA_DEL_BLOB]],
                ['id' => 13, 'nombre' => 'x', 'nivel' => ['estado' => 'resuelto', 'nivelId' => 987654]],
            ],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leerSinFuga($version);
        $jornadas = $r->json('jornadas');

        $this->assertSame(['por_defecto', 'niveles', 'grupos'], array_keys($jornadas));
        $this->assertSame(self::CLAVES_DE_UNA_JORNADA, array_keys($jornadas['por_defecto']));
        $this->assertSame([3, 5], $jornadas['por_defecto']['descansos_tras']);
        $this->assertSame($jornadas['por_defecto']['descansos_tras'], $r->json('ejes.descansos_tras'),
            'Los dos salen del mismo sitio y no pueden discrepar.');

        $this->assertCount(1, $jornadas['niveles']);
        $this->assertSame($nivel->nombre, $jornadas['niveles'][0]['nombre'], 'El nombre del nivel es el de la tabla, no el del fichero.');
        $this->assertSame([1, 2, 3], $jornadas['niveles'][0]['jornada']['dias']);
        $this->assertSame(4, $jornadas['niveles'][0]['jornada']['franjas']);

        $this->assertSame([
            ['grupo_id' => 10, 'nivel_id' => (int) $nivel->id, 'porque' => 'nivel'],
            ['grupo_id' => 11, 'nivel_id' => null, 'porque' => 'sin-nivel'],
            ['grupo_id' => 12, 'nivel_id' => null, 'porque' => 'sin-resolver'],
            ['grupo_id' => 13, 'nivel_id' => 987654, 'porque' => 'nivel-desconocido'],
        ], $jornadas['grupos']);

        $renglon = $r->json('catalogos.jornadas');
        $this->assertSame('parcial', $renglon['estado'], 'Dos grupos se pintan con una jornada que no es la suya: eso es parcial.');
        $this->assertSame(4, $renglon['grupos']);
        $this->assertSame(2, $renglon['con_jornada_propia']);
        $this->assertSame(2, $renglon['con_jornada_prestada']);
    }

    /**
     * Los timbres viajan DENTRO de cada jornada, y con eso `timbres` deja de ser `sin_catalogo`.
     *
     * Decía *«no viaja por aquí»* y pasó a ser falso el día que las jornadas viajaron: es
     * la familia de cadena que ya costó tres correcciones en dos días. Lo que sigue
     * siendo cierto es que el colegio no los ha dado, y eso es `vacio`. Y el `HH:MM` va
     * atado a una expresión regular **porque es una cadena**: sin ella sería un canal de
     * texto libre del blob con nombre de hora.
     */
    #[Test]
    public function los_timbres_viajan_dentro_de_cada_jornada_y_llenan_su_renglon(): void
    {
        $anio = $this->anioDelSujeto();
        $conHoras = ['dias' => [1, 2], 'franjas' => 2, 'descansosTras' => [], 'timbres' => [['inicio' => '06:45', 'fin' => '07:35'], ['inicio' => '07:35', 'fin' => '08:25']]];

        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'niveles' => [['id' => 1, 'nombre' => 'x', 'jornada' => $conHoras]],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leerSinFuga($version);
        $this->assertSame($conHoras['timbres'], $r->json('jornadas.niveles.0.jornada.timbres'));
        $this->assertNull($r->json('jornadas.por_defecto.timbres'));
        $this->assertNull($r->json('ejes.timbres'), '`ejes.timbres` sigue nulo a propósito: lo declarado va en `jornadas`, con su porqué.');

        $renglon = $r->json('catalogos.timbres');
        $this->assertSame('parcial', $renglon['estado']);
        $this->assertSame(1, $renglon['con_timbres']);
        $this->assertSame(2, $renglon['de']);

        // La fuga por la hora, y la cuenta que no cuadra: las dos tiran las jornadas enteras.
        foreach ([
            'una hora que no es HH:MM' => [['inicio' => self::MARCA_DEL_BLOB, 'fin' => '07:35'], ['inicio' => '07:35', 'fin' => '08:25']],
            'menos timbres que franjas' => [['inicio' => '06:45', 'fin' => '07:35']],
        ] as $forma => $timbres) {
            $rota = $this->versionConProyecto($anio, $this->proyectoCompleto([
                'niveles' => [['id' => 1, 'nombre' => 'x', 'jornada' => ['timbres' => $timbres] + $conHoras]],
            ]));
            $this->leccionEn($rota, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

            $r = $this->leerSinFuga($rota);
            $this->assertNull($r->json('jornadas'), "Con «{$forma}» las jornadas no se entienden enteras.");
            $this->assertSame('ilegible', $r->json('catalogos.timbres.estado'));
            $this->assertSame('ilegible', $r->json('catalogos.jornadas.estado'));
        }
    }

    /**
     * La disponibilidad viaja **SIN** quién la declaró para el personal llano — decisión 3,
     * contestada por Joseth el 6 sep 2026.
     *
     * **Este caso comprobaba lo contrario hasta ese día**, y con este mismo sujeto: la lista
     * salía con el `profesor_id` de cada pega **para cualquiera de los 53 docentes**, porque
     * `getLecciones` lleva `auth.personal` y ningún permiso dentro. O sea *«a qué hora le
     * viene mal a cada compañero»* repartido a la sala de profesores entera, y **una pega
     * con nombre se rebate peor de lo que se reparte**.
     *
     * Lo que se tacha es **el autor y no la marca**: día, franja y `estado` siguen viajando,
     * así que la rejilla se pinta igual y las cuentas del renglón cuadran con lo recibido.
     * *Los demás ven que hay una pega y no de quién; quien cuadra el horario sabe a quién
     * preguntarle* — y eso lo fija el caso de al lado.
     */
    #[Test]
    public function la_disponibilidad_no_dice_de_quien_es_cada_pega_al_personal_llano(): void
    {
        $anio = $this->anioDelSujeto();
        [$uno, $dos, $tres] = $this->profesores(3);
        $marcas = [['dia' => 1, 'franja' => 1, 'estado' => 'condicional'], ['dia' => 5, 'franja' => 7, 'estado' => 'inadecuado']];

        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [
                ['profesorId' => $tres, 'nombre' => 'x'],
                ['profesorId' => $uno, 'nombre' => 'x', 'disponibilidad' => ['marcas' => $marcas]],
                ['profesorId' => $dos, 'nombre' => 'x', 'disponibilidad' => ['marcas' => []]],
            ],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leerSinFuga($version);
        $disponibilidad = $r->json('disponibilidad');

        $this->assertSame([
            ['profesor_id' => null, 'marcas' => $marcas],
            ['profesor_id' => null, 'marcas' => []],
            ['profesor_id' => null, 'marcas' => []],
        ], $disponibilidad,
            'El personal llano se está llevando el `profesor_id` de cada pega. La marca sí '.
            'viaja —la rejilla se pinta igual—, el dueño no.');

        // Y ningún id de docente se ha colado por el camino: `assertSame` de arriba lo
        // cubre para esta lista, pero el renglón que lo DECLARA es lo que impide que un
        // nulo se lea como «este dato no está».
        $renglon = $r->json('catalogos.disponibilidad');

        $this->assertSame('reservado', $renglon['autor'],
            'Sin este renglón, «no te toca saberlo» y «no se pudo leer» se ven igual desde '.
            'la pantalla, que es la confusión que este módulo lleva cinco secciones evitando.');
        $this->assertSame(2, $renglon['marcas'],
            'Las cuentas se dan enteras aunque el autor no: ver que hay pegas es el punto.');
        $this->assertSame(1, $renglon['condicional']);
        $this->assertSame(1, $renglon['inadecuado']);
    }

    /**
     * Y quien puede publicar **sí** se lleva el autor de cada pega — la otra mitad de la
     * decisión 3.
     *
     * Sin este caso, tachar el `profesor_id` **para todo el mundo** pasaría el test de
     * arriba y nadie lo notaría hasta que un coordinador intentara cuadrar el horario y no
     * supiera a quién preguntarle. *Un permiso que no deja pasar a nadie se ve igual de
     * verde que uno que funciona, si sólo se prueba el lado que se cierra.*
     */
    #[Test]
    public function quien_puede_publicar_si_ve_de_quien_es_cada_pega(): void
    {
        $anio = $this->anioDelSujeto();
        [$uno, $dos, $tres] = $this->profesores(3);
        $marcas = [['dia' => 1, 'franja' => 1, 'estado' => 'condicional'], ['dia' => 5, 'franja' => 7, 'estado' => 'inadecuado']];

        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [
                ['profesorId' => $tres, 'nombre' => 'x'],
                ['profesorId' => $uno, 'nombre' => 'x', 'disponibilidad' => ['marcas' => $marcas]],
                ['profesorId' => $dos, 'nombre' => 'x', 'disponibilidad' => ['marcas' => []]],
            ],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $sujeto = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $sujeto->is_superuser,
            'El sujeto tiene que poder publicar: si no, el `reservado` se leería como un acierto.');

        $r = $this->leer($version, $this->tokenDe($sujeto->username))->assertStatus(200);

        $this->assertSame([
            ['profesor_id' => $uno, 'marcas' => $marcas],
            ['profesor_id' => $dos, 'marcas' => []],
            ['profesor_id' => $tres, 'marcas' => []],
        ], $r->json('disponibilidad'),
            'Una entrada por docente, ordenadas por id, y cada marca con su dueño al lado.');

        $this->assertSame('visible', $r->json('catalogos.disponibilidad.autor'));

        $renglon = $r->json('catalogos.disponibilidad');
        $this->assertSame('completo', $renglon['estado']);
        $this->assertSame(1, $renglon['con_marcas']);
        $this->assertSame(3, $renglon['de']);
        $this->assertSame(2, $renglon['marcas']);
        $this->assertSame(1, $renglon['condicional']);
        $this->assertSame(1, $renglon['inadecuado']);
        $this->assertSame($renglon['de'], $r->json('catalogos.plantilla.total'), 'La disponibilidad se cuenta sobre la plantilla.');
    }

    /**
     * Una marca que no se entiende tira la disponibilidad entera, y NO la plantilla.
     *
     * Son dos lecturas de la misma lista y se separan a propósito: un proyecto cuyas
     * marcas no se entienden sigue teniendo una plantilla perfectamente legible, y
     * marcar las dos como ilegibles sería afirmar de una lo que sólo se sabe de la otra.
     * La marca va en el `estado`, que es la cadena por la que saldría.
     */
    #[Test]
    public function una_marca_rota_tira_la_disponibilidad_pero_no_la_plantilla(): void
    {
        $anio = $this->anioDelSujeto();
        [$uno] = $this->profesores(1);
        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [['profesorId' => $uno, 'nombre' => 'x', 'disponibilidad' => ['marcas' => [['dia' => 1, 'franja' => 1, 'estado' => self::MARCA_DEL_BLOB]]]]],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leerSinFuga($version);

        $this->assertNull($r->json('disponibilidad'));
        $this->assertSame('ilegible', $r->json('catalogos.disponibilidad.estado'));
        $this->assertCount(1, $r->json('plantilla'), 'La plantilla se lee aunque las marcas no.');
        $this->assertSame('completo', $r->json('catalogos.plantilla.estado'));
    }

    /**
     * Las piezas sin colocar viajan identificadas, y se CONFRONTAN con las incompletas — no se cuadran.
     *
     * `sin_colocar ⊆ incompletas` y nunca `===`: una pieza sin colocar siempre deja su
     * asignación corta, pero colocar una pieza de varios grupos **tira** las que estorban
     * y una pieza tirada no va a la bandeja — desaparece. Medido en la versión 8 real:
     * **1 contra 3**. La igualdad se cumplió en siete versiones seguidas y era
     * coincidencia; aquí la asignación `sinPieza` es la tirada.
     */
    #[Test]
    public function las_piezas_sin_colocar_se_confrontan_con_las_incompletas_y_no_se_cuadran(): void
    {
        $anio = $this->anioDelSujeto();
        [$a, $b, $sinPieza] = $this->asignacionesDe($anio, 3);
        [$uno] = $this->profesores(1);

        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'asignaciones' => [
                ['id' => (int) $a->id, 'grupoId' => 1, 'profesorId' => null, 'materia' => 'x', 'ih' => 3, 'distribucion' => [1, 1, 1]],
                ['id' => (int) $b->id, 'grupoId' => 1, 'profesorId' => null, 'materia' => 'x', 'ih' => 2, 'distribucion' => [2]],
                ['id' => (int) $sinPieza->id, 'grupoId' => 1, 'profesorId' => null, 'materia' => 'x', 'ih' => 2, 'distribucion' => [1, 1]],
            ],
            'piezas' => [
                ['id' => 'a-0', 'duracion' => 1, 'lecciones' => [['asignacionId' => (int) $a->id]], 'docentes' => [$uno], 'salonId' => null, 'salonesPermitidos' => null],
                ['id' => 'a-1', 'duracion' => 1, 'lecciones' => [['asignacionId' => (int) $a->id]], 'docentes' => [$uno], 'salonId' => null, 'salonesPermitidos' => null],
                ['id' => 'misa-religion', 'duracion' => 2, 'lecciones' => [['asignacionId' => (int) $b->id]], 'docentes' => [], 'salonId' => null, 'salonesPermitidos' => null],
            ],
            'colocaciones' => [['piezaId' => 'a-0', 'dia' => 1, 'franja' => 1], ['piezaId' => 'misa-religion', 'dia' => 2, 'franja' => 1]],
        ]));
        $this->leccionEn($version, (int) $a->id, 'a-0', 1, 1);

        $r = $this->leerSinFuga($version);
        $sinColocar = $r->json('sin_colocar');

        $this->assertCount(1, $sinColocar);
        $this->assertSame(self::CLAVES_DE_UNA_PIEZA_SIN_COLOCAR, array_keys($sinColocar[0]));
        $this->assertSame('a-1', $sinColocar[0]['pieza_id']);
        $this->assertSame((int) $a->id, $sinColocar[0]['asignaturas'][0]['asignatura_id']);
        $this->assertSame(
            ['asignatura_id', 'ih', 'materia', 'alias_materia', 'grupo_id', 'nombre_grupo', 'abrev_grupo'],
            array_keys($sinColocar[0]['asignaturas'][0]),
            'La asignación de la bandeja se pinta con las mismas claves que una lección.'
        );
        $this->assertNotNull($sinColocar[0]['asignaturas'][0]['materia'], 'La materia sale de `materias`, no del fichero.');
        $this->assertSame([$uno], array_column($sinColocar[0]['docentes'], 'id'));

        $renglon = $r->json('catalogos.sin_colocar');
        $this->assertSame('completo', $renglon['estado']);
        $this->assertSame(1, $renglon['total']);
        $this->assertSame(3, $renglon['piezas']);
        $this->assertSame(2, $renglon['colocadas']);
        $this->assertSame(2, $renglon['incompletas'], '`a` va 1 de 3 y `sinPieza` 0 de 2; `misa-religion` cubre a `b` entera.');
        $this->assertLessThanOrEqual($renglon['incompletas'], $renglon['total'], 'sin_colocar ⊆ incompletas.');
        $this->assertNotSame($renglon['incompletas'], $renglon['total'],
            'Si estos dos coinciden, el caso ya no demuestra que no son lo mismo: la asignación tirada es la que los separa.');
    }

    /**
     * Una colocación que apunta a una pieza que no existe, o un `pieza_id` que no es un
     * identificador, tiran la lectura entera: **no se filtra a medias**.
     *
     * Y el `pieza_id` es el único texto del fichero que sale por esta ruta: va acotado a
     * la forma que su columna ya acepta, así que una cadena con espacios o llaves no es
     * una pieza y no viaja.
     */
    #[Test]
    public function unas_piezas_que_no_se_entienden_no_viajan_a_medias(): void
    {
        $anio = $this->anioDelSujeto();
        $a = $this->asignacionDe($anio);
        $pieza = fn (string $id) => ['id' => $id, 'duracion' => 1, 'lecciones' => [['asignacionId' => (int) $a->id]], 'docentes' => [], 'salonId' => null, 'salonesPermitidos' => null];

        foreach ([
            'una colocación de una pieza que no existe' => ['piezas' => [$pieza('a-0')], 'colocaciones' => [['piezaId' => 'no-existe', 'dia' => 1, 'franja' => 1]]],
            'un id que no es un identificador' => ['piezas' => [$pieza('con espacio {'.self::MARCA_DEL_BLOB.'}')], 'colocaciones' => []],
            'un docente que no es un entero' => ['piezas' => [['docentes' => [self::MARCA_DEL_BLOB]] + $pieza('a-0')], 'colocaciones' => []],
        ] as $forma => $partes) {
            $version = $this->versionConProyecto($anio, $this->proyectoCompleto($partes + ['asignaciones' => [['id' => (int) $a->id, 'ih' => 1]]]));
            $this->leccionEn($version, (int) $a->id, 'a-0', 1, 1);

            $r = $this->leerSinFuga($version);
            $this->assertNull($r->json('sin_colocar'), "Con «{$forma}» las piezas no se entienden enteras.");
            $this->assertSame('ilegible', $r->json('catalogos.sin_colocar.estado'));
            $this->assertSame(1, $r->json('total_lecciones'), 'Las lecciones salen de la tabla y no se contagian.');
        }
    }

    /**
     * El fichero de proyecto vacío pero ENTERO —lo que `nuevoProyecto()` del escritorio
     * produce— deja las cuatro listas en `[]` y sus renglones en `vacio`, no en `ilegible`.
     *
     * Es la otra mitad de `una_version_sin_salones_sin_dobles_y_sin_colores_es_legitima`:
     * un colegio que subió un proyecto sin declarar nada recibe cuatro listas vacías, que
     * es un dato, y ningún aviso de que algo no se pudo leer.
     */
    #[Test]
    public function un_proyecto_vacio_pero_entero_deja_las_cuatro_listas_en_vacio(): void
    {
        $anio = $this->anioDelSujeto();
        $version = $this->versionEn($anio);
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $r = $this->leerSinFuga($version);

        $this->assertSame([], $r->json('plantilla'));
        $this->assertSame([], $r->json('disponibilidad'));
        $this->assertSame([], $r->json('sin_colocar'));
        $this->assertSame([], $r->json('jornadas.niveles'));
        $this->assertSame([], $r->json('jornadas.grupos'));

        foreach (['plantilla', 'disponibilidad', 'sin_colocar', 'jornadas', 'timbres'] as $cual) {
            $this->assertSame('vacio', $r->json("catalogos.{$cual}.estado"), "`{$cual}` vacío es legítimo y no es «no se pudo leer».");
        }
    }

    /**
     * **EL REQUISITO DURO: todo renglón de `catalogos` dice su población y con qué criterio.**
     *
     * No es cosmético y no se puede añadir después. Sin la población dentro del renglón, el
     * consumidor **no puede escribir la comprobación de que lo que recibió es lo que el
     * renglón dice** — y entonces un `completo` no se investiga nunca. Es lo que pasó con
     * `tono` diciendo `completo · 12 de 12` con 47 docentes vivos: coherente consigo mismo,
     * contando sobre la población que no era, y **el lector del front lo tenía apuntado como
     * algo que no podía comprobar porque el sobre no le daba la otra población**.
     *
     * Este caso es la valla para el catálogo que se añada mañana: un renglón nuevo con sólo
     * un `estado` lo pone rojo. La regla, en una línea: *una cuenta que cuadra sobre la
     * población equivocada no falla, y por eso no se investiga.*
     *
     * `sin_catalogo` e `ilegible` son la excepción y llevan `motivo` en vez de cifras: ahí
     * **no hay población que dar**, y decir `0` sería exactamente la confusión que los cinco
     * estados existen para evitar.
     */
    #[Test]
    public function todo_renglon_de_catalogos_dice_su_poblacion_y_su_criterio(): void
    {
        $anio = $this->anioDelSujeto();
        [$uno] = $this->profesores(1);
        $version = $this->versionConProyecto($anio, $this->proyectoCompleto([
            'docentes' => [['profesorId' => $uno, 'nombre' => 'x']],
            'niveles' => [['id' => 1, 'nombre' => 'x', 'jornada' => ['dias' => [1], 'franjas' => 1, 'descansosTras' => [], 'timbres' => null]]],
        ]));
        $this->leccionEn($version, (int) $this->asignacionDe($anio)->id, 'a1-0', 1, 1);

        $catalogos = $this->leerSinFuga($version)->json('catalogos');

        $this->assertSame(self::CATALOGOS, array_keys($catalogos), 'Un catálogo sin renglón es un error del servidor.');

        foreach ($catalogos as $cual => $renglon) {
            $this->assertArrayHasKey('estado', $renglon, "`{$cual}` no dice su estado.");

            if (in_array($renglon['estado'], ['sin_catalogo', 'ilegible'], true)) {
                $this->assertNotNull($renglon['motivo'] ?? null,
                    "`{$cual}` está en `{$renglon['estado']}` y no dice por qué: eso no se puede leer dentro de seis meses.");

                continue;
            }

            $this->assertArrayHasKey('criterio', $renglon,
                "`{$cual}` no dice CONTRA QUÉ cuenta. Sin criterio, su población admite dos lecturas y el "
                .'consumidor no puede recontarla: es el `completo · 12 de 12` sobre 47 docentes otra vez.');

            $cifras = array_filter($renglon, fn ($v, $k) => is_int($v) && ! in_array($k, ['estado', 'criterio', 'motivo'], true), ARRAY_FILTER_USE_BOTH);
            $this->assertNotSame([], $cifras,
                "`{$cual}` dice un estado y ninguna cifra. Un estado sin población no se puede comprobar, "
                .'que es lo único que hace que se investigue cuando está mal.');
        }
    }
}
