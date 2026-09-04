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
 *      §9.bis.4 y la única que no se ve mirando la respuesta.
 *
 * ## Lo que NO fija
 *
 * El 403 de alumnos y acudientes es de `HorarioAutorizacionTest`, que desde el 4 sep
 * 2026 cubre las **cuatro** rutas. Aquí se entra siempre como **personal llano**, que
 * es el sujeto más pequeño que puede llamar a esta ruta: con un superusuario el verde
 * diría menos de lo que parece.
 */
class HorarioLeccionesTest extends CasoDeContrato
{
    /** Un trozo de texto que no puede salir de ningún otro sitio que del blob. */
    private const MARCA_DEL_BLOB = 'MARCA-QUE-SOLO-VIVE-EN-EL-PROYECTO-4c1d';

    /** Las claves del envoltorio, y ninguna más. */
    private const CLAVES_DEL_SOBRE = ['version', 'ejes', 'catalogos', 'lecciones', 'total_lecciones'];

    /** Las claves de cada lección, y ninguna más. */
    private const CLAVES_DE_UNA_LECCION = [
        'id', 'pieza_id', 'dia', 'franja', 'duracion',
        'asignatura_id', 'ih', 'materia', 'alias_materia',
        'grupo_id', 'nombre_grupo', 'abrev_grupo',
        'nombre_salon', 'salon_capacidad_grupos', 'docentes',
    ];

    /** Los catálogos que la respuesta declara, y ninguno menos. */
    private const CATALOGOS = [
        'grupos', 'asignaciones', 'docentes', 'tono',
        'salones', 'timbres', 'disponibilidad', 'restricciones',
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

    /** Mete una versión y devuelve su id. El blob va siempre: la columna es `NOT NULL`. */
    private function versionEn(int $yearId, string $nombre = 'Versión para pintar'): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, NULL, ?, NULL, ?, ?)',
            [$yearId, $nombre, '{"proyecto":"'.self::MARCA_DEL_BLOB.'"}', '2026-09-04 10:00:00', '2026-09-04 10:00:00']
        );

        return (int) DB::getPdo()->lastInsertId();
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

        // Y lo que la API estructuralmente no tiene, dicho como tal y no como vacío.
        foreach (['timbres', 'disponibilidad', 'restricciones'] as $cual) {
            $this->assertSame('sin_catalogo', $catalogos[$cual]['estado'],
                "`{$cual}` no lo guarda el servidor (§4): mandarlo vacío sería decir que el colegio no lo tiene.");
            $this->assertNotNull($catalogos[$cual]['motivo'], 'Un `sin_catalogo` sin motivo no se puede leer dentro de seis meses.');
        }
    }

    /**
     * El `tono` viaja y dice su población: `vacio` mientras nadie reparta colores.
     *
     * La columna la decidió Joseth el 4 sep 2026 y **nace vacía en los diecisiete**.
     * Por eso el contrato dice `string | null` y no `string`: el nulo es el caso
     * normal, no el raro.
     */
    #[Test]
    public function el_tono_dice_cuantos_docentes_lo_tienen(): void
    {
        $anio = $this->anioDelSujeto();
        $asignacion = $this->asignacionDe($anio);
        $version = $this->versionEn($anio);
        $this->leccionEn($version, (int) $asignacion->id, 'a1-0', 1, 1);

        $sinColores = $this->leer($version)->assertStatus(200)->json('catalogos.tono');
        $this->assertSame('vacio', $sinColores['estado']);
        $this->assertSame(0, $sinColores['con_tono']);
        $this->assertGreaterThan(0, $sinColores['de'], 'Si no hay docentes que contar, este test pasa sin comprobar nada.');

        // Y con un color repartido pasa a `parcial`, que es lo que el escritorio
        // necesita saber: seis de sus ocho informes pintan distinto sin él y nada se
        // pone rojo.
        DB::update('UPDATE profesores SET tono = ? WHERE id = ?', ['#3366cc', (int) $asignacion->profesor_id]);

        $conUno = $this->leer($version)->assertStatus(200)->json('catalogos.tono');
        $this->assertContains($conUno['estado'], ['parcial', 'completo']);
        $this->assertSame(1, $conUno['con_tono']);
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

        $this->assertSame([1, 3], $ejes['dias'], 'Los días son los que la versión usa, no una rejilla inventada.');
        $this->assertSame([2, 5], $ejes['franjas']);
        $this->assertNull($ejes['timbres']);
        $this->assertStringContainsString('0=domingo', $ejes['convenio_dia'],
            'El convenio del día se DECLARA: un horario corrido un día cumple todas las reglas de la §6 '
            .'y no lo detecta nadie.');
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
}
