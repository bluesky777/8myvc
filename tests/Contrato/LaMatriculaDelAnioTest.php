<?php

namespace Tests\Contrato;

use App\Models\Matricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * **Cuál es «la matrícula del año»** — la §9.5 del
 * [plan](../../docs/migracion/19-boletin-independiente.md), que llevaba abierta desde
 * el 24 ago 2026.
 *
 * `matriculas` **no tiene clave única sobre (alumno, año)**, y la ficha y el guardado
 * no elegían la misma fila:
 *
 * | | Consulta | `m.deleted_at` | `g.deleted_at` | `ORDER BY` | Se queda con |
 * |---|---|---|---|---|---|
 * | **escribe** | `Alumnos\GuardarAlumno::valor` | **no filtraba** | **no filtraba** | **ninguno** | `[0]` |
 * | **lee** | `AlumnosController::putShow` | filtra | filtra | `a.apellidos, a.nombres` | `[0]` |
 *
 * Y el `ORDER BY` de la lectura **no desempata nada**: para un solo alumno, ordenar por
 * su apellido y su nombre es un empate total. Las dos se quedaban con «la primera que
 * devuelva MySQL» y nada garantizaba que fuera la misma.
 *
 * ## Son TRES columnas, no cuatro
 *
 * `repitente`, `promovido` y `nro_folio`. **La marca del boletín independiente ya no
 * está aquí**: se fue a `bol_ind_periodos` el 31 ago 2026, que cuelga de
 * `(alumno_id, periodo_id)` con clave única, así que ahí no hay dos filas entre las que
 * equivocarse. Contarla sería contar un sitio que ya no existe.
 *
 * ## Por qué el caso se construye
 *
 * Porque **con una sola matrícula por alumno las dos formas dan el mismo verde**, igual
 * que todo lo demás de esta noche. En la copia de `simonbolivar` del 1 sep 2026 hay
 * **3.578** pares (alumno, año) con matrícula viva y **uno solo** con dos —el alumno
 * 1097 en el año 7, con `promovido` y `nro_folio` distintos en las dos filas—, y **cero
 * matrículas borradas en toda la tabla**. O sea que un test escrito sobre el seed tal
 * cual no toca ninguno de los dos casos.
 *
 * ## Lo que este fichero cubre y lo que NO
 *
 * Cubre **el escritor**. El lector (`putShow`) se cambia después y con el visto bueno
 * de la coordinación, porque la regla nueva **cambia lo que la ficha devuelve hoy** en
 * el caso de las dos vivas, y eso es contrato.
 */
class LaMatriculaDelAnioTest extends CasoDeContrato
{
    /**
     * Un alumno con **una** matrícula viva en el año, y un segundo grupo del mismo año
     * donde ponerle la segunda.
     *
     * @return array{token: string, year_id: int, alumno: int, vieja: int, grupo_ajeno: int}
     */
    private function escenario(): array
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $fila = DB::selectOne('SELECT m.id, m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($fila, 'El grupo del seed no tiene matrículas vivas.');

        $otras = DB::selectOne('SELECT COUNT(*) AS n FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ?
            WHERE m.alumno_id = ?', [$grupo->year_id, $fila->alumno_id]);

        $this->assertSame(1, (int) $otras->n,
            'Este alumno ya tiene más de una matrícula en el año, así que el escenario no '
            .'empieza donde dice. El caso se construye aquí, no se busca en el seed.');

        return [
            'token' => $token,
            'year_id' => (int) $grupo->year_id,
            'alumno' => (int) $fila->alumno_id,
            'vieja' => (int) $fila->id,
            'grupo_ajeno' => (int) $this->grupoAjenoDelMismoAnio((int) $grupo->year_id)->grupo_id,
        ];
    }

    /** Una matrícula más para el mismo alumno en el mismo año, creada DESPUÉS. */
    private function segundaMatricula(array $e, string $estado = 'MATR'): int
    {
        DB::insert(
            'INSERT INTO matriculas (alumno_id, grupo_id, estado, repitente, promovido, nro_folio, created_at, updated_at)
             VALUES (?, ?, ?, 0, "Automático", "FOLIO-NUEVO", DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())',
            [$e['alumno'], $e['grupo_ajeno'], $estado]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /** El `repitente` de una matrícula concreta. */
    private function repitenteDe(int $matriculaId): int
    {
        return (int) DB::selectOne('SELECT repitente FROM matriculas WHERE id = ?', [$matriculaId])->repitente;
    }

    /**
     * **`getContent()` y no `json()`**: en la rama buena este endpoint devuelve la
     * cadena suelta `Guardado`, que Laravel manda como texto plano. `json()` revienta
     * ahí con «Invalid JSON was returned from the route» — que es un rojo del test y no
     * del código. En la rama del 400 sí hay JSON.
     */
    private function guardar(array $e, string $propiedad, $valor): TestResponse
    {
        return $this->withToken($e['token'])->putJson('/api/alumnos/guardar-valor', [
            'alumno_id' => $e['alumno'],
            'year_id' => $e['year_id'],
            'propiedad' => $propiedad,
            'valor' => $valor,
        ]);
    }

    /**
     * **El escritor NO escribe en una matrícula borrada.**
     *
     * Es la mitad más cara de la §9.5 y la que no da ninguna señal: el guardado no
     * filtraba `deleted_at`, así que con la fila vieja en la papelera escribía **ahí**,
     * y la ficha —que sí filtra— seguía enseñando la viva sin el cambio. El colegio
     * teclea `repitente`, la pantalla dice «Guardado» y al recargar sigue como estaba.
     */
    public function test_no_se_escribe_en_una_matricula_borrada(): void
    {
        $e = $this->escenario();

        $nueva = $this->segundaMatricula($e);

        // La vieja a la papelera: queda con el id MÁS BAJO, que es justo la que el
        // `[0]` sin `ORDER BY` del código anterior escogía.
        DB::update('UPDATE matriculas SET deleted_at = NOW() WHERE id = ?', [$e['vieja']]);

        $this->assertSame('Guardado', $this->guardar($e, 'repitente', 1)->getContent(),
            'El guardado no llegó a escribir: el test no mediría dónde escribe.');

        $this->assertSame(1, $this->repitenteDe($nueva),
            'El cambio no llegó a la matrícula VIVA del año. Es la §9.5: se escribió en otra '
            .'fila y la ficha seguirá enseñando el valor de antes, con un «Guardado» en pantalla.');

        $this->assertSame(0, $this->repitenteDe($e['vieja']),
            'El cambio se escribió en la matrícula BORRADA. Nadie lo verá nunca: la ficha filtra '
            .'`deleted_at`, así que el dato queda en la papelera y la pantalla dice que se guardó.');
    }

    /**
     * **Entre dos matrículas vivas del mismo año gana la MÁS RECIENTE.**
     *
     * Ésta es la decisión, y viene de lo que ya hace `Matricula::matricularUno()`: al
     * encontrar varias del mismo año **activa una y borra las demás**, o sea que una
     * segunda fila viva sólo aparece si alguien volvió a matricular — y el acto
     * posterior sustituye al anterior.
     *
     * Sin `ORDER BY`, MySQL devolvía la de id más bajo y el guardado escribía en la
     * **anterior**.
     */
    public function test_entre_dos_vivas_gana_la_mas_reciente(): void
    {
        $e = $this->escenario();

        $nueva = $this->segundaMatricula($e);

        $this->assertSame('Guardado', $this->guardar($e, 'repitente', 1)->getContent());

        $this->assertSame(1, $this->repitenteDe($nueva),
            'Con dos matrículas vivas, el guardado no fue a la más reciente.');

        $this->assertSame(0, $this->repitenteDe($e['vieja']),
            'El guardado fue a la matrícula anterior. Con dos vivas, «la del año» es la que se '
            .'creó después: la segunda fila sólo existe porque alguien volvió a matricular.');
    }

    /**
     * **Y el modelo contesta lo mismo que escribe el escritor.** Es el punto entero de
     * la §9.5: una sola regla, no dos consultas que se parecen.
     *
     * Sin esto, los dos casos de arriba se cumplirían igual con la regla copiada a mano
     * en el escritor — y copiada a mano es exactamente como nació el problema.
     */
    public function test_el_modelo_y_el_escritor_eligen_la_misma_fila(): void
    {
        $e = $this->escenario();

        $nueva = $this->segundaMatricula($e);

        $delModelo = Matricula::laDelAnio($e['alumno'], $e['year_id']);

        $this->assertNotNull($delModelo, '`Matricula::laDelAnio` no encontró ninguna.');
        $this->assertSame($nueva, (int) $delModelo->id,
            '`Matricula::laDelAnio` elige una fila distinta de la que dice su propia regla.');

        $this->guardar($e, 'repitente', 1);

        $this->assertSame(1, $this->repitenteDe((int) $delModelo->id),
            'El escritor escribió en una fila distinta de la que dice `Matricula::laDelAnio`. '
            .'Que la regla esté escrita en un sitio no sirve de nada si el escritor lleva la suya.');
    }

    /**
     * El caso de todos los días **no se mueve**: con una sola matrícula viva, se
     * escribe en ella.
     *
     * Es el control que dice que esto **no le cambia nada a los 3.578 pares medidos**
     * — sin él, «se escribe en la que toca» se cumpliría también rompiendo el caso
     * normal.
     */
    public function test_con_una_sola_matricula_se_escribe_en_ella(): void
    {
        $e = $this->escenario();

        $this->assertSame('Guardado', $this->guardar($e, 'repitente', 1)->getContent());

        $this->assertSame(1, $this->repitenteDe($e['vieja']),
            'Con una sola matrícula viva en el año, el guardado tiene que ir a ella.');
    }

    /**
     * Y un alumno **sin matrícula en ese año** sigue contestando lo de antes.
     *
     * El 400 con `'No encontrado' => false` es feo y no es de este cambio: lo esperan
     * los cinco llamadores de `AlumnosController`. Se fija para que reescribirlo sea
     * una decisión y no un efecto secundario.
     */
    public function test_sin_matricula_del_anio_contesta_lo_de_antes(): void
    {
        $e = $this->escenario();

        DB::update('UPDATE matriculas SET deleted_at = NOW() WHERE id = ?', [$e['vieja']]);

        $r = $this->guardar($e, 'repitente', 1);

        $r->assertStatus(400);
        $this->assertSame('Alumno no encontrado', $r->json('msg'));
    }
}
