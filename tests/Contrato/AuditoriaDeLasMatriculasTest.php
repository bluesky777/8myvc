<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Las once escrituras de `matriculas`, ahora en `auditoria`.
 *
 * Es la tabla que pidió el front para la columna «Historial» de la pantalla de
 * matrículas: hasta hoy la celda enseñaba la fecha de `updated_at` y el modal
 * abría vacío, porque `auditoria` no tenía **ni una sola línea** de entidad
 * `matricula` —censo del 22 sep 2026 en `caz_zaragoza`: `nota 7339 ·
 * subunidad 496 · comportamiento 255 · …`, y ni `matricula` ni `alumno`—.
 *
 * El criterio es el de sus hermanos y no se relaja: **se mira la fila que queda,
 * no el 200**. `Auditoria::guardar()` se traga cualquier excepción a propósito
 * (18 §4.3), así que una entidad mal escrita, una columna que no existe o un
 * tipo que no cuadra **devuelven `null` y dejan la respuesta en 200**. Un caso
 * que comprobara el código de respuesta pasaría con el rastro entero perdido.
 *
 * Y todos van por el viaje de ida y vuelta —la API de verdad, con su token—, que
 * es la única forma de comprobar que la llamada está **dentro** del método y
 * detrás de la guarda, y no en un sitio donde el permiso ya no la protege.
 *
 * Lo que estos casos NO cubren, dicho aquí para que nadie lo lea como cubierto:
 * `matriculas` la escriben también `ChangeAskedController::putAceptarAlumno`,
 * `DetallesController::putEliminarMatriculaDestroy`,
 * `LoginController::putCrearPrematricula`, `FormulariosInscripcionController`,
 * `FusionDeAlumnos::fusionar` y el importador de alumnos. **Esos caminos siguen
 * sin rastro**, y el modal de una matrícula tocada por ellos saldrá vacío o
 * incompleto. Van en su propio lote.
 */
class AuditoriaDeLasMatriculasTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** Las líneas de una matrícula, más recientes primero. */
    private function lineasDe(int $matriculaId): array
    {
        return DB::select('SELECT * FROM auditoria WHERE entidad = ? AND entidad_id = ? ORDER BY id DESC',
            ['matricula', $matriculaId]);
    }

    /** Una matrícula viva del seed, con las columnas que estos casos comparan. */
    private function unaMatricula(): object
    {
        $fila = DB::selectOne('SELECT id, alumno_id, grupo_id, estado, nuevo, promovido,
                   fecha_retiro, fecha_matricula
              FROM matriculas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita al menos una matrícula viva.');

        return $fila;
    }

    /**
     * Retirar a un alumno deja **una** línea, con el estado de antes y el de después.
     *
     * `RETI` toca dos columnas a la vez —`estado` y `fecha_retiro`—, así que el
     * valor anterior se lee de `getOriginal()` **antes** del `save()`: después
     * Eloquent sincroniza el original y la línea diría «de RETI a RETI» sin
     * fallar por ello, que es el modo de error que no se ve.
     */
    public function test_retirar_deja_una_linea_con_los_dos_estados(): void
    {
        $token = $this->tokenDeSuperusuario();
        $matricula = $this->unaMatricula();

        $this->withToken($token)->putJson('/api/matriculas/retirar', [
            'matricula_id' => $matricula->id,
            'fecha_retiro' => '2026-03-15',
        ])->assertStatus(200);

        $lineas = $this->lineasDe((int) $matricula->id);

        $this->assertCount(1, $lineas, 'Retirar no dejó exactamente una línea de auditoría.');

        $linea = $lineas[0];
        $anterior = json_decode((string) $linea->valor_anterior, true);
        $nuevo = json_decode((string) $linea->valor_nuevo, true);

        $this->assertSame(Auditoria::EDITAR, $linea->accion);
        $this->assertSame($matricula->estado, $anterior['estado'],
            'La línea no guarda el estado que tenía la fila antes de pisarla.');
        $this->assertSame('RETI', $nuevo['estado']);
        $this->assertEquals($matricula->alumno_id, $linea->alumno_id,
            'La línea no dice de qué alumno era la matrícula.');
        $this->assertNotNull($linea->actor_user_id, 'La línea no dice quién retiró.');
        $this->assertSame('PUT matriculas/retirar', $linea->ruta,
            'La ruta se guarda con su patrón, no con la URL resuelta (18 §5).');
    }

    /**
     * Mandar la matrícula a la papelera se anota como **borrado**, no como edición.
     *
     * El método pone `RETI` y acto seguido llama a `delete()`. Visto desde fuera
     * ocurrió un borrado y el `RETI` es cómo está implementado; anotarlo como
     * edición contaría el mecanismo en vez del acto.
     */
    public function test_la_papelera_se_anota_como_borrado(): void
    {
        $token = $this->tokenDeSuperusuario();
        $matricula = $this->unaMatricula();

        $this->withToken($token)->deleteJson('/api/matriculas/destroy/'.$matricula->id)
            ->assertStatus(200);

        $lineas = $this->lineasDe((int) $matricula->id);

        $this->assertCount(1, $lineas, 'El borrado no dejó exactamente una línea.');
        $this->assertSame(Auditoria::BORRAR, $lineas[0]->accion,
            'Se anotó como edición: eso cuenta el mecanismo (el RETI) y no el acto (el borrado).');

        $anterior = json_decode((string) $lineas[0]->valor_anterior, true);

        $this->assertSame($matricula->estado, $anterior['estado'],
            'El valor anterior es el RETI que el propio método acaba de escribir, no el que había.');
    }

    /**
     * Cambiar la fecha de matrícula deja los dos valores, y el anterior es el de la fila.
     *
     * Este caso existe por separado de `retirar` porque toca **una sola** columna
     * y usa `getOriginal('fecha_matricula')` en vez de la fila entera: son dos
     * caminos distintos en el código y uno puede romperse con el otro en verde.
     */
    public function test_cambiar_la_fecha_de_matricula_deja_los_dos_valores(): void
    {
        $token = $this->tokenDeSuperusuario();
        $matricula = $this->unaMatricula();

        $this->withToken($token)->putJson('/api/matriculas/cambiar-fecha-matricula', [
            'matricula_id' => $matricula->id,
            'fecha_matricula' => '2026-02-01',
        ])->assertStatus(200);

        $lineas = $this->lineasDe((int) $matricula->id);

        $this->assertCount(1, $lineas, 'Cambiar la fecha no dejó exactamente una línea.');

        $anterior = json_decode((string) $lineas[0]->valor_anterior, true);
        $nuevo = json_decode((string) $lineas[0]->valor_nuevo, true);

        $this->assertSame(Auditoria::EDITAR, $lineas[0]->accion);
        $this->assertArrayHasKey('fecha_matricula', $anterior);
        $this->assertArrayHasKey('fecha_matricula', $nuevo);
        $this->assertStringStartsWith('2026-02-01', (string) $nuevo['fecha_matricula']);
        $this->assertNotEquals($anterior['fecha_matricula'], $nuevo['fecha_matricula'],
            'El valor anterior y el nuevo salieron iguales: el original se leyó después del save().');
    }

    /**
     * Marcar «nuevo» deja rastro aunque el cambio sea de un solo bit.
     *
     * Un interruptor es justo lo que se cuela sin auditar —«total, es un
     * `tinyint`»— y a la vez lo que nadie sabe explicar seis meses después.
     */
    public function test_marcar_nuevo_deja_rastro(): void
    {
        $token = $this->tokenDeSuperusuario();
        $matricula = $this->unaMatricula();

        $destino = ((int) $matricula->nuevo) === 1 ? 0 : 1;

        $this->withToken($token)->putJson('/api/matriculas/toggle-nuevo', [
            'matricula_id' => $matricula->id,
            'is_nuevo' => $destino,
        ])->assertStatus(200);

        $lineas = $this->lineasDe((int) $matricula->id);

        $this->assertCount(1, $lineas, 'El interruptor no dejó línea de auditoría.');

        $nuevo = json_decode((string) $lineas[0]->valor_nuevo, true);

        $this->assertEquals($destino, $nuevo['nuevo']);
        $this->assertNotNull($lineas[0]->resumen, 'La línea no trae una frase legible.');
    }

    /**
     * Matricular a un alumno deja **una** línea para las cuatro ramas de
     * `Matricula::matricularUno()`.
     *
     * Ese método restaura de la papelera, mueve de grupo, crea nueva o cae en su
     * `catch` de rescate, y **el acto es siempre el mismo**. La línea se escribe
     * en el controlador por eso: auditar rama por rama serían cuatro líneas
     * diciendo lo mismo con distinto nombre.
     */
    public function test_matricular_deja_una_sola_linea_venga_de_la_rama_que_venga(): void
    {
        $token = $this->tokenDeSuperusuario();

        $sitio = DB::selectOne('SELECT m.alumno_id, m.grupo_id, g.year_id
              FROM matriculas m
             INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
             WHERE m.deleted_at IS NULL ORDER BY m.id LIMIT 1');

        $this->assertNotNull($sitio, 'El seed necesita una matrícula con su grupo.');

        $antes = (int) DB::selectOne('SELECT COUNT(*) AS n FROM auditoria WHERE entidad = ?',
            ['matricula'])->n;

        $this->withToken($token)->postJson('/api/matriculas/matricularuno', [
            'alumno_id' => $sitio->alumno_id,
            'grupo_id' => $sitio->grupo_id,
            'year_id' => $sitio->year_id,
        ])->assertStatus(200);

        $despues = (int) DB::selectOne('SELECT COUNT(*) AS n FROM auditoria WHERE entidad = ?',
            ['matricula'])->n;

        $this->assertSame($antes + 1, $despues,
            'Matricular dejó '.($despues - $antes).' líneas: el acto es uno y la línea también.');

        $linea = DB::selectOne('SELECT * FROM auditoria WHERE entidad = ? ORDER BY id DESC LIMIT 1',
            ['matricula']);

        $this->assertContains($linea->accion, [Auditoria::CREAR, Auditoria::EDITAR]);
        $this->assertEquals($sitio->alumno_id, $linea->alumno_id);
        $this->assertNotNull($linea->actor_user_id);
    }

    /**
     * Las dos fechas de una línea se escriben **con la misma forma**.
     *
     * Lo trajo `myvc-front-89` con el ejemplo dentro de una sola fila: el mismo
     * día como `"2026-01-26"` en `valor_anterior` y como
     * `"2026-01-26T00:00:00.000000Z"` en `valor_nuevo`, porque uno sale del valor
     * crudo de la columna y el otro del atributo casteado por Eloquent.
     *
     * **Lo que rompe no es la estética**: encadenar las líneas de una entidad —ver
     * si un cambio se revirtió, detectar un hueco, pintar «de X a Y»— compara el
     * `valor_nuevo` de una con el `valor_anterior` de la siguiente, y con dos
     * formas nunca coinciden aunque el dato sea idéntico. Falla enseñando dos
     * valores que parecen distintos, que es el error que nadie persigue.
     *
     * Este caso pide lo fuerte a propósito: **escribir la fecha que ya estaba**
     * tiene que dejar las dos puntas **iguales como cadena**. Una comprobación de
     * formato las daría por buenas escribiéndolas en dos husos distintos.
     */
    public function test_las_dos_fechas_de_una_linea_van_en_la_misma_forma(): void
    {
        $token = $this->tokenDeSuperusuario();
        $matricula = $this->unaMatricula();

        $mismoDia = substr((string) $matricula->fecha_matricula, 0, 10);

        $this->assertNotSame('', $mismoDia, 'El seed necesita una matrícula con fecha.');

        $this->withToken($token)->putJson('/api/matriculas/cambiar-fecha-matricula', [
            'matricula_id' => $matricula->id,
            'fecha_matricula' => $mismoDia,
        ])->assertStatus(200);

        $linea = $this->lineasDe((int) $matricula->id)[0];

        $anterior = json_decode((string) $linea->valor_anterior, true)['fecha_matricula'];
        $nuevo = json_decode((string) $linea->valor_nuevo, true)['fecha_matricula'];

        $this->assertSame($anterior, $nuevo,
            'Reguardar la MISMA fecha dejó dos cadenas distintas: el valor crudo de la '
            .'columna y el `Carbon` del modelo se serializan de dos formas, y encadenar '
            .'las líneas deja de funcionar.');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $nuevo,
            'La forma canónica es «Y-m-d H:i:s», sin la «Z» que diría UTC de un dato de Bogotá.');
    }

    /**
     * Las dos rutas que leen el historial lo devuelven **en el mismo orden**.
     *
     * Lo encontró `myvc-front-89` pintando el modal: las mismas tres líneas salían
     * `8305, 8306, 8307` por `auditoria/entidad/matricula/{id}` y `8307, 8306, 8305`
     * por `auditoria/alumno/{id}`. Una ordenaba `a.id ASC` y la otra `a.id DESC`, y
     * no lo había decidido nadie.
     *
     * **Dos razones por las que esto no es cosmético.** La primera es el `LIMIT 300`:
     * con tope, el orden decide **qué líneas se pierden**, y con `ASC` el recorte se
     * llevaba las más recientes — las que se consultan cuando alguien reclama. La
     * segunda es cómo falla en el cliente: un historial del revés **se lee
     * perfectamente bien** y es mentira. No da error; sólo dice que un cambio ocurrió
     * antes que otro.
     *
     * El caso compara las dos listas **por la misma matrícula**, y por eso hacen falta
     * al menos dos líneas: con una sola, cualquier orden coincide consigo mismo y el
     * caso pasaría sin comprobar nada.
     */
    public function test_las_dos_rutas_del_historial_coinciden_en_el_orden(): void
    {
        $token = $this->tokenDeSuperusuario();
        $matricula = $this->unaMatricula();

        $this->withToken($token)->putJson('/api/matriculas/toggle-nuevo',
            ['matricula_id' => $matricula->id, 'is_nuevo' => ((int) $matricula->nuevo) === 1 ? 0 : 1])
            ->assertStatus(200);

        $this->withToken($token)->putJson('/api/matriculas/cambiar-fecha-retiro',
            ['matricula_id' => $matricula->id, 'fecha_retiro' => '2026-04-01'])
            ->assertStatus(200);

        $porEntidad = $this->withToken($token)
            ->getJson('/api/auditoria/entidad/matricula/'.$matricula->id)
            ->assertStatus(200)->json('acciones');

        $porAlumno = array_values(array_filter(
            $this->withToken($token)->getJson('/api/auditoria/alumno/'.$matricula->alumno_id)
                ->assertStatus(200)->json('acciones'),
            fn (array $l): bool => $l['entidad'] === 'matricula'
                && (int) $l['entidad_id'] === (int) $matricula->id));

        $ids = fn (array $lineas): array => array_map(fn (array $l): int => (int) $l['id'], $lineas);

        $this->assertGreaterThanOrEqual(2, count($porEntidad),
            'Con una sola línea cualquier orden coincide consigo mismo y este caso no mide nada.');

        $this->assertSame($ids($porEntidad), $ids($porAlumno),
            'Las mismas líneas salen en orden distinto según la ruta: con LIMIT, eso además '
            .'cambia cuáles se pierden.');

        $descendente = $ids($porEntidad);
        rsort($descendente);

        $this->assertSame($descendente, $ids($porEntidad),
            'El historial no viene de lo más reciente a lo más antiguo, que es lo que el '
            .'tope de 300 tiene que conservar.');
    }

    /**
     * **Y el control que dice que estos casos miden algo**: sin tocar nada, la
     * matrícula no tiene líneas.
     *
     * `auditoria` llega vacía en el seed. Sin este caso, todos los de arriba
     * pasarían igual si `lineasDe()` estuviera mirando la tabla equivocada — un
     * `assertCount(1)` sobre una consulta rota falla, pero uno sobre una consulta
     * que devuelve de más no distingue «lo escribí yo» de «ya estaba».
     */
    public function test_sin_tocar_nada_no_hay_lineas(): void
    {
        $this->assertSame([], $this->lineasDe((int) $this->unaMatricula()->id),
            'La matrícula del seed ya traía líneas de auditoría: los demás casos no distinguen.');
    }
}
