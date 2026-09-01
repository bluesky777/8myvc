<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * **«No guardado» con 200 cuando sí se guardó** — [09 §13](../../docs/migracion/09-pendientes.md),
 * **opción A**, decidida por Joseth el 1 sep 2026 con la medición del lote F delante
 * ([f.md §9](../../docs/migracion/noche-2026-08-31/f.md)).
 *
 * ## Lo que estaba mal, en una frase
 *
 * > `DB::update` devuelve **filas afectadas**, y MySQL da **0 cuando el UPDATE no cambia
 * > ningún valor** — no cuando no encuentra la fila.
 *
 * Así que `if ($res) 'Guardado' else 'No guardado'` **juntaba dos cosas que no tienen
 * nada que ver**, y **guardar dos veces lo mismo contestaba «No guardado» con 200 y el
 * estado correcto**.
 *
 * ## Por eso este fichero separa los dos casos, y es TODO el asunto
 *
 * Con el código de antes **los dos daban exactamente la misma respuesta**, así que un
 * test que probara sólo uno no distinguiría el arreglo de su ausencia. Aquí van
 * emparejados en cada ruta:
 *
 * | Caso | Antes | Ahora |
 * |---|---|---|
 * | el valor ya era ése | 200 `'No guardado'` | **200 `'Guardado'`** |
 * | la fila no existe | 200 `'No guardado'` | **404** |
 *
 * ## Lo que NO se toca, y por qué
 *
 * - **`ImporterFixer::valorAcudiente`**, el cuarto sitio de la lista del 09 §13: **no lo
 *   llama nadie**. `grep valorAcudiente` sobre `app/ routes/ tests/ database/` da tres
 *   líneas —la llamada de `AcudientesController`, que va a `GuardarAlumno`, y las dos
 *   declaraciones— y de `ImporterFixer` sólo se usa `verificar()`. Es una copia muerta.
 *   **El front llegó a lo mismo por su cuenta** en `check-no-guardado.mjs`; dos fuentes
 *   independientes es lo que permite no tocarlo sin miedo.
 * - **`years/toggle-cambiar-valor`** va en su **propio commit**: es la única de las
 *   cuatro rutas donde el arreglo llega **antes que la pantalla** —ningún cliente la
 *   llama hoy— y se quiere poder contar suelta.
 *
 * ## Y lo que esto le hace al front, medido
 *
 * `app2` lee la palabra en **18 ficheros a propósito** y **sabe que no es un fallo**: la
 * convierte en rechazo para que **la celda de la rejilla vuelva atrás**. Con A puesta
 * **mejora sin tocar nada** —deja de revertir cuando el valor ya era ése y sigue
 * revirtiendo ante un 404 real—. Retirar su interpretación es la opción B, es del front
 * y va después. La app vieja no mira el cuerpo.
 */
class ElNoGuardadoQueMentiaTest extends CasoDeContrato
{
    /** @return array{string, int, int} token, year_id, alumno_id */
    private function alumno(): array
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $fila = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($fila, 'El grupo del seed no tiene alumnos.');

        return [$token, (int) $grupo->year_id, (int) $fila->alumno_id];
    }

    /** Un id que seguro no existe en esa tabla. */
    private function idInexistente(string $tabla): int
    {
        return (int) DB::selectOne('SELECT COALESCE(MAX(id), 0) + 1000 AS n FROM `'.$tabla.'`')->n;
    }

    /**
     * Las tres ramas de `GuardarAlumno::valor`, con una propiedad de cada una.
     *
     * Van las tres porque **cada una escribe en una tabla distinta** —`users`,
     * `matriculas`, `alumnos`— y el arreglo es una comprobación por rama: probar sólo
     * una dejaría las otras dos sin medir y con la misma cara.
     *
     * @return array<string, array{string, mixed}>
     */
    public static function lasTresRamasDeAlumno(): array
    {
        return [
            'rama alumnos (default)' => ['direccion', 'Calle de la prueba 123'],
            'rama matriculas' => ['repitente', 1],
            'rama users' => ['is_active', 1],
        ];
    }

    /**
     * **Guardar dos veces lo mismo contesta «Guardado» las dos veces.**
     *
     * La segunda llamada es la que mentía: el `UPDATE` encuentra la fila, no cambia nada,
     * MySQL devuelve 0 filas afectadas y el método lo leía como fallo.
     */
    #[DataProvider('lasTresRamasDeAlumno')]
    public function test_guardar_dos_veces_lo_mismo_sigue_siendo_guardado(string $propiedad, $valor): void
    {
        [$token, $yearId, $alumnoId] = $this->alumno();

        $usuario = DB::selectOne('SELECT user_id FROM alumnos WHERE id = ?', [$alumnoId]);

        $cuerpo = [
            'alumno_id' => $alumnoId,
            'year_id' => $yearId,
            'propiedad' => $propiedad,
            'valor' => $valor,
            'user_id' => $usuario->user_id,
        ];

        $primera = $this->withToken($token)->putJson('/api/alumnos/guardar-valor', $cuerpo);
        $primera->assertStatus(200);

        $segunda = $this->withToken($token)->putJson('/api/alumnos/guardar-valor', $cuerpo);

        $segunda->assertStatus(200);

        $this->assertSame('Guardado', $segunda->getContent(),
            "Guardando `{$propiedad}` con el MISMO valor por segunda vez, la respuesta no es "
            .'«Guardado». `DB::update` devuelve filas afectadas y MySQL da 0 cuando nada cambia: '
            .'eso no es un fallo, y el estado en la base es exactamente el que pidió quien llamó.');
    }

    /**
     * **Y un alumno que no existe contesta 404**, que es la otra mitad.
     *
     * Sin este caso, «siempre Guardado» se cumpliría también borrando la única señal que
     * queda de que un UPDATE no encontró nada — que es justo lo que el 09 §13 avisa que
     * no puede pasar: *«el día que un UPDATE falle de verdad, nadie se entera»*.
     */
    public function test_un_alumno_que_no_existe_contesta_404(): void
    {
        [$token, $yearId] = $this->alumno();

        $r = $this->withToken($token)->putJson('/api/alumnos/guardar-valor', [
            'alumno_id' => $this->idInexistente('alumnos'),
            'year_id' => $yearId,
            'propiedad' => 'direccion',
            'valor' => 'Calle de la prueba 123',
        ]);

        $r->assertStatus(404);
    }

    /**
     * Y un alumno **que existe pero no tiene matrícula en ese año**, para la rama de
     * `matriculas`: también 404, y **ya no el 400 que puso la §9.5**.
     *
     * Se unifica porque **una misma ruta contestando dos códigos para la misma condición
     * es peor que cualquiera de los dos**: el cliente tendría que aprenderse cuál toca
     * según la propiedad que mande.
     */
    public function test_sin_matricula_del_anio_contesta_404(): void
    {
        [$token, $yearId, $alumnoId] = $this->alumno();

        DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ?
                    SET m.deleted_at = NOW() WHERE m.alumno_id = ?', [$yearId, $alumnoId]);

        $this->withToken($token)->putJson('/api/alumnos/guardar-valor', [
            'alumno_id' => $alumnoId,
            'year_id' => $yearId,
            'propiedad' => 'repitente',
            'valor' => 1,
        ])->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // acudientes/guardar-valor — el segundo sitio vivo
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{string, int, int} token, acudiente_id, parentesco_id */
    private function acudiente(): array
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $fila = DB::selectOne('SELECT p.acudiente_id, p.id AS parentesco_id FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            WHERE p.deleted_at IS NULL ORDER BY p.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún acudiente con parentesco.');

        return [$token, (int) $fila->acudiente_id, (int) $fila->parentesco_id];
    }

    /** Las dos ramas que un cliente alcanza hoy. @return array<string, array{string, string}> */
    public static function lasRamasDeAcudiente(): array
    {
        return [
            'rama acudientes (default)' => ['direccion', 'Calle de la prueba 123'],
            'rama parentescos' => ['parentesco', 'Tío'],
        ];
    }

    #[DataProvider('lasRamasDeAcudiente')]
    public function test_guardar_dos_veces_lo_mismo_en_un_acudiente_sigue_siendo_guardado(string $propiedad, string $valor): void
    {
        [$token, $acudienteId, $parentescoId] = $this->acudiente();

        $cuerpo = [
            'acudiente_id' => $acudienteId,
            'parentesco_id' => $parentescoId,
            'propiedad' => $propiedad,
            'valor' => $valor,
        ];

        $this->withToken($token)->putJson('/api/acudientes/guardar-valor', $cuerpo)->assertStatus(200);

        $segunda = $this->withToken($token)->putJson('/api/acudientes/guardar-valor', $cuerpo);

        $segunda->assertStatus(200);

        $this->assertSame('Guardado', $segunda->getContent(),
            "Guardando `{$propiedad}` de un acudiente con el MISMO valor por segunda vez, la "
            .'respuesta no es «Guardado». Es el mismo mecanismo que en la ficha del alumno.');
    }

    public function test_un_acudiente_que_no_existe_contesta_404(): void
    {
        [$token, , $parentescoId] = $this->acudiente();

        $this->withToken($token)->putJson('/api/acudientes/guardar-valor', [
            'acudiente_id' => $this->idInexistente('acudientes'),
            'parentesco_id' => $parentescoId,
            'propiedad' => 'direccion',
            'valor' => 'Calle de la prueba 123',
        ])->assertStatus(404);
    }

    public function test_un_parentesco_que_no_existe_contesta_404(): void
    {
        [$token, $acudienteId] = $this->acudiente();

        $this->withToken($token)->putJson('/api/acudientes/guardar-valor', [
            'acudiente_id' => $acudienteId,
            'parentesco_id' => $this->idInexistente('parentescos'),
            'propiedad' => 'parentesco',
            'valor' => 'Tío',
        ])->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // years/toggle-cambiar-valor — el tercer sitio, y el único donde el arreglo
    // llega ANTES que la pantalla: medido el 1 sep 2026, **ningún cliente llama a
    // esta ruta** (cero ficheros de código en los tres fronts), y los cinco
    // interruptores hermanos del año tienen ruta propia y ninguno tiene el defecto.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Apagar un interruptor que **ya estaba apagado** sigue siendo «Guardado».
     *
     * Es el caso que el plan describía con el rector y el interruptor de puestos. No lo
     * puede vivir nadie hoy —esta ruta no la llama ningún cliente— y por eso este
     * arreglo es el barato: **impide que la pantalla nazca rota** en vez de arreglar una
     * pantalla rota.
     *
     * Se usa `mostrar_nota_comport_boletin`, que es un interruptor de verdad de `years` y
     * **no** `puestos_con_bol_independiente`: lo que se prueba es el mecanismo de la
     * ruta, no una columna concreta, y atarlo a la del boletín independiente haría que
     * este test dependiera de una migración que no tiene nada que ver.
     */
    public function test_apagar_lo_que_ya_estaba_apagado_sigue_siendo_guardado(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $cuerpo = [
            'year_id' => $grupo->year_id,
            'campo' => 'mostrar_nota_comport_boletin',
            'valor' => 0,
        ];

        $this->withToken($token)->putJson('/api/years/toggle-cambiar-valor', $cuerpo)->assertStatus(200);

        $segunda = $this->withToken($token)->putJson('/api/years/toggle-cambiar-valor', $cuerpo);

        $segunda->assertStatus(200);

        $this->assertSame('Guardado', $segunda->getContent(),
            'Poniendo el mismo valor por segunda vez, la rejilla de configuración del colegio '
            .'contesta que no guardó. El estado en la base es el que se pidió: `DB::update` '
            .'devuelve filas afectadas y MySQL da 0 cuando nada cambia.');

        $this->assertSame(0, (int) DB::selectOne(
            'SELECT mostrar_nota_comport_boletin AS v FROM years WHERE id = ?', [$grupo->year_id])->v,
            'Y el valor tiene que haber quedado escrito de verdad: sin esto, «siempre Guardado» '
            .'se cumpliría también no escribiendo nada.');
    }

    /** Y un año que no existe contesta 404 en vez de «No guardado» con 200. */
    public function test_un_year_que_no_existe_contesta_404(): void
    {
        $grupo = $this->grupoConAlumnos();
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $this->withToken($token)->putJson('/api/years/toggle-cambiar-valor', [
            'year_id' => $this->idInexistente('years'),
            'campo' => 'mostrar_nota_comport_boletin',
            'valor' => 0,
        ])->assertStatus(404);
    }
}
