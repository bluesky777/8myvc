<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El observador del alumno: la hoja en blanco que el colegio imprime y rellena a mano.
 *
 * Es el único informe del P1 que **no devuelve JSON sino HTML ya maquetado** —
 * `View::make('observador')->render()`—, y eso cambia lo que hay que proteger.
 * Aquí no hay claves que el frontend lea: hay una hoja que sale por la impresora,
 * y lo que la rompe es perder una clase de maqueta, una cabecera de tabla o el
 * número de filas en blanco. Nada de eso mueve el código de estado.
 *
 * Por eso el snapshot no es del HTML —que trae nombres y fotos, y cambia con
 * cada seed— sino de su ESQUELETO: qué etiquetas con qué clases aparecen y qué
 * rótulos fijos lleva impresos. Es la misma decisión que en `ExcelTest`, donde
 * se compara la forma de la hoja y no los bytes.
 */
class ObservadorTest extends CasoDeContrato
{
    /**
     * El esqueleto de la hoja: el conjunto de `etiqueta.clase` que aparece en ella.
     *
     * **Conjunto, no conteo.** El conteo dependería del número de alumnos del
     * seed, y el seed se puede regenerar desde la base real con otro grupo; un
     * snapshot que fallara al regenerarlo no protegería nada, enseñaría a
     * borrarlo. Lo que sí tiene que seguir estando es cada bloque de la maqueta,
     * y eso es lo que dice el conjunto. Las cantidades que sí importan —páginas
     * por alumno, filas por tabla— van medidas aparte, donde se pueden leer.
     */
    private function esqueleto(string $html): array
    {
        preg_match_all('/<([a-z][a-z0-9]*)\b([^>]*)>/i', $html, $etiquetas, PREG_SET_ORDER);

        $vistas = [];

        foreach ($etiquetas as $etiqueta) {
            $clase = preg_match('/class="([^"]*)"/i', $etiqueta[2], $c)
                ? trim(preg_replace('/\s+/', ' ', $c[1]))
                : '';

            $vistas[strtolower($etiqueta[1]).($clase === '' ? '' : '.'.$clase)] = true;
        }

        $claves = array_keys($vistas);
        sort($claves);

        return $claves;
    }

    /**
     * Las cabeceras de las tablas: «FECHA», «OBSERVACIONES SIGNIFICATIVAS», «FIRMA».
     *
     * Si un cambio de Blade deja de pintar una cabecera, el esqueleto sigue
     * idéntico —el `<th>` está ahí— y la hoja sale con columnas sin nombre.
     *
     * Se leen solo de los `<th>` y a propósito. El primer intento sacaba todo el
     * texto en mayúsculas de la página, y ahí dentro venía «COLEGIO ADVENTISTA
     * SIMÓN BOLIVAR», que no es un rótulo de la plantilla sino
     * `$year->nombre_colegio`: un dato de la base metido en un snapshot, que
     * habría fallado al regenerar el seed y —peor— habría metido el nombre del
     * colegio en un fichero que solo debe guardar formas. En los `<th>` de esta
     * plantilla no hay una sola interpolación.
     */
    private function cabeceras(string $html): array
    {
        preg_match_all('/<th\b[^>]*>(.*?)<\/th>/is', $html, $celdas);

        $textos = array_map(
            fn ($t) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'))),
            $celdas[1]
        );

        $cabeceras = array_values(array_unique(array_filter($textos, fn ($t) => $t !== '')));
        sort($cabeceras);

        return $cabeceras;
    }

    /**
     * Los rótulos de los campos de la ficha, escritos a mano y a propósito.
     *
     * Van literales en vez de en el snapshot porque el otro texto con dos puntos
     * de la hoja es `{{ $acudiente->parentesco }}:`, que sí sale de la base:
     * recogerlos por patrón habría guardado los parentescos del seed. Esta lista
     * es la ficha del alumno tal como se imprime, y perder una línea de aquí es
     * entregar el observador sin el documento o sin el teléfono.
     */
    private const ROTULOS_DE_LA_FICHA = [
        'NOMBRE:', 'NACIMIENTO:', 'GRUPO:', 'DOCUMENTO:', 'RH:', 'DIRECCIÓN:', 'TEL:',
    ];

    /** Cuántos alumnos matriculados tiene el grupo, que es lo que marca el largo de la hoja. */
    private function cuantosAlumnos(int $grupoId): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) n FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM")', [$grupoId])->n;
    }

    // ---------------------------------------------------------------- La hoja

    public function test_la_forma_de_la_hoja_del_observador(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->get("/api/observador/vertical/{$grupo->id}/carta",
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $html = $r->getContent();

        $this->assertNotEmpty($html, 'El observador salió vacío.');

        foreach (self::ROTULOS_DE_LA_FICHA as $rotulo) {
            $this->assertStringContainsString($rotulo, $html,
                "La ficha del observador se imprime sin el rótulo «{$rotulo}».");
        }

        $this->compararConInstantanea('observador-vertical', [
            'esqueleto' => $this->esqueleto($html),
            'cabeceras' => $this->cabeceras($html),
        ]);
    }

    /**
     * Dos páginas por alumno, no una.
     *
     * La plantilla pinta la ficha con la tabla de observaciones significativas y,
     * a continuación, la de observaciones por periodo. Son dos `page-vertical`
     * por alumno y así se encuaderna. Contarlas es lo que distingue «la hoja
     * salió» de «la hoja salió entera»: un `@foreach` que se cierre donde no debe
     * deja el HTML válido y la mitad del observador sin imprimir.
     */
    public function test_el_observador_saca_dos_paginas_por_alumno(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $html = $this->get("/api/observador/vertical/{$grupo->id}/carta",
            ['Authorization' => 'Bearer '.$token])->getContent();

        $alumnos = $this->cuantosAlumnos((int) $grupo->id);

        $this->assertGreaterThan(0, $alumnos);
        $this->assertSame($alumnos * 2, substr_count($html, 'class="page-vertical'),
            "El grupo tiene {$alumnos} alumnos y deberían salir ".($alumnos * 2).' páginas.');
    }

    /**
     * El tamaño de papel decide cuántas filas en blanco se imprimen.
     *
     * Es la única lógica propia que tiene este controlador y va escrita como dos
     * arrays literales de 26 y 33 números. Un renglón de más no cabe en la hoja
     * y parte la tabla en dos páginas, que es de las averías que solo se ven
     * cuando ya está impreso.
     *
     * `carta` y cualquier otra cosa: el `if` no comprueba `oficio`, comprueba
     * `== 'carta'`, así que el segundo caso del proveedor podría ser cualquier
     * palabra. Se usa `oficio` porque es la que manda la pantalla.
     */
    public function test_el_tamanio_de_papel_cambia_las_filas_en_blanco(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $alumnos = $this->cuantosAlumnos((int) $grupo->id);
        $periodos = (int) DB::selectOne('SELECT COUNT(*) n FROM periodos
            WHERE year_id = ? AND deleted_at IS NULL', [$grupo->year_id])->n;

        // [filas de la tabla de observaciones, filas de cada tabla por periodo]
        $esperado = ['carta' => [26, 5], 'oficio' => [33, 6]];

        foreach ($esperado as $tamanio => [$filas, $filasPorPeriodo]) {
            $html = $this->get("/api/observador/vertical/{$grupo->id}/{$tamanio}", $cab)->getContent();

            // Las tres tablas de la hoja usan <td height="30"> salvo la de la
            // escala del comportamiento, que usa 70. Contar por la altura separa
            // las filas rellenables de las demás sin depender de la maqueta.
            $this->assertSame(
                $alumnos * ($filas * 3 + $periodos * $filasPorPeriodo * 2),
                substr_count($html, '<td height="30">'),
                "Con papel {$tamanio} cambió el número de filas en blanco del observador."
            );
        }
    }

    /**
     * `vertical-todos` recibe el tamaño por la query, no por la URL.
     *
     * Es la ruta que el §6.6 del documento de código muerto marcó como rota:
     * `getVerticalTodos()` usaba `$tamanio` sin recibirlo y su ruta no lleva el
     * segmento. Ahora lo lee de `Request::input`, y este test es lo que impide
     * que se vuelva a perder — sin él, la ruta responde 200 con la hoja en
     * tamaño oficio y nadie se entera de que el parámetro se ignora.
     */
    public function test_vertical_todos_hace_caso_al_tamanio_de_la_query(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();
        $cab = ['Authorization' => 'Bearer '.$token];

        $carta = $this->get('/api/observador/vertical-todos?tamanio=carta', $cab);
        $oficio = $this->get('/api/observador/vertical-todos?tamanio=oficio', $cab);

        $carta->assertStatus(200);
        $oficio->assertStatus(200);

        $this->assertLessThan(
            substr_count($oficio->getContent(), '<td height="30">'),
            substr_count($carta->getContent(), '<td height="30">'),
            'vertical-todos ignora `tamanio`: carta y oficio traen las mismas filas.'
        );
    }

    /**
     * El horizontal es el raro de la familia: devuelve JSON, no HTML.
     *
     * Y devuelve JSON porque tiene un `return` antes del `View::make(...)`, con
     * el render del Blade escrito debajo y muerto. La pantalla que lo consume
     * maqueta en el navegador. No se toca —funciona— pero el test fija que lo que
     * sale es `{grupo, imagenes}` y no una hoja, que es lo que su nombre sugiere.
     */
    public function test_el_observador_horizontal_devuelve_el_grupo_y_las_imagenes(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson("/api/observador-horizontal/horizontal/{$grupo->id}", [],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertNotEmpty($r->json('grupo.alumnos'), 'El grupo salió sin alumnos.');

        // formaUnida por lo mismo que en BoletinesTest: la lista de alumnos viene
        // ordenada por apellidos y nombres, y el seed los repite.
        $this->compararConInstantanea('observador-horizontal', $this->formaUnida($r->json()));
    }
}
