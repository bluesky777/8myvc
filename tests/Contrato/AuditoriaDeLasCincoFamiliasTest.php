<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Las cinco familias que hasta hoy **no grababan nada**: asistencia,
 * comportamiento, disciplina, situaciones y frases.
 *
 * Es la segunda mitad de la fase 4 de
 * [18-auditoria.md](../../docs/migracion/18-auditoria.md), y la que hace que la
 * pantalla de auditoría que pidió el colegio tenga filas que enseñar. Los diez
 * escritores viejos son notas, definitivas y configuración del año; sin estas
 * cinco, un observador reescrito, una falta borrada o una frase quitada del
 * boletín de un niño **no dejan ningún rastro en ninguna parte**.
 *
 * ## Por qué estos casos miran la fila y no el código de respuesta
 *
 * `Auditoria::guardar()` se traga cualquier excepción a propósito (18 §4.3):
 * devuelve `null`, deja la fila en el log y **la petición sale 200 igual**. Una
 * entidad mal escrita, una columna que no existe o un `int` donde iba un string
 * no rompen nada — sólo hacen desaparecer el rastro en silencio. Un caso que
 * comprobara el 200 pasaría con la auditoría entera perdida, que es exactamente
 * el instrumento que tranquiliza sin medir.
 *
 * ## Lo que se comprueba en cada uno, y por qué es siempre lo mismo
 *
 * De las cuatro acciones, **`borrar` es la que importa**: es la única en la que
 * el dato deja de existir en su tabla, así que si la línea no se lleva el valor
 * viejo dentro, no queda en ningún sitio. Por eso casi todos los casos de aquí
 * son de borrado, y todos comprueban `valor_anterior`.
 */
class AuditoriaDeLasCincoFamiliasTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** Crea una falta disciplinaria por la API y devuelve su id. */
    private function crearFalta(string $token, string $descripcion): int
    {
        $ctx = DB::selectOne('SELECT m.alumno_id, g.year_id
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1');

        $this->assertNotNull($ctx, 'El seed necesita un alumno matriculado.');

        $this->withToken($token)->postJson('/api/disciplina/store', [
            'alumno_id' => $ctx->alumno_id,
            'year_id' => $ctx->year_id,
            'descripcion' => $descripcion,
            'tipo_situacion' => 'I',
            'fecha_hora_aprox' => '2026-08-25 07:15:00',
        ])->assertStatus(200);

        $fila = DB::selectOne('SELECT id FROM dis_procesos WHERE descripcion = ? ORDER BY id DESC LIMIT 1',
            [$descripcion]);

        $this->assertNotNull($fila, 'No se creó la falta «'.$descripcion.'».');

        return (int) $fila->id;
    }

    /** @return array<int, object> */
    private function lineasDe(string $entidad, ?int $id = null): array
    {
        $sql = 'SELECT * FROM auditoria WHERE entidad = ?';
        $par = [$entidad];

        if ($id !== null) {
            $sql .= ' AND entidad_id = ?';
            $par[] = $id;
        }

        return DB::select($sql.' ORDER BY id DESC', $par);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1 — Asistencia
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Anotar una falta y borrarla dejan **dos líneas**, y la del borrado se lleva
     * dentro lo que la falta era.
     *
     * Es la familia donde esto pesa más y está escrito en el propio controlador:
     * borrar una falta lo puede hacer **cualquiera del personal** —decidido por
     * Joseth el 22 ago 2026, con la medición delante, porque cerrarlo dejaría a
     * 51 profesores sin poder corregir en dieciséis colegios—. Si el permiso no
     * cierra, el rastro es lo único que queda; y en la copia de producción de ese
     * día había **5.689 ausencias borradas y 5.684 sin autor**.
     */
    public function test_anotar_y_borrar_una_falta_dejan_las_dos_lineas(): void
    {
        $token = $this->tokenDeSuperusuario();

        $ctx = DB::selectOne('SELECT m.alumno_id, a.id AS asignatura_id
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1');
        $this->assertNotNull($ctx, 'El seed necesita un alumno matriculado con asignatura.');

        $r = $this->withToken($token)->postJson('/api/ausencias/store', [
            'alumno_id' => $ctx->alumno_id,
            'asignatura_id' => $ctx->asignatura_id,
            'cantidad_ausencia' => 2,
            'fecha_hora' => '2026-08-25 07:00:00',
        ]);
        $r->assertStatus(201);

        $id = (int) $r->json('id');

        $alta = $this->lineasDe('ausencia', $id);
        $this->assertCount(1, $alta, 'Anotar la falta no dejó línea.');
        $this->assertSame(Auditoria::CREAR, $alta[0]->accion);
        $this->assertEquals($ctx->alumno_id, $alta[0]->alumno_id, 'La línea no dice de qué alumno es la falta.');
        $this->assertSame('ausencia', json_decode((string) $alta[0]->valor_nuevo, true)['tipo']);

        $this->olvidarControladores();

        $this->withToken($token)->deleteJson('/api/ausencias/destroy/'.$id)->assertStatus(200);

        $lineas = $this->lineasDe('ausencia', $id);
        $this->assertCount(2, $lineas, 'Borrar la falta no dejó su propia línea.');

        $borrado = $lineas[0];

        $this->assertSame(Auditoria::BORRAR, $borrado->accion);
        $this->assertNotNull($borrado->valor_anterior,
            'La línea del borrado no se llevó dentro lo que la falta era. Es el único sitio '.
            'donde queda: la fila de `ausencias` ya está en la papelera.');
        $this->assertSame('ausencia', json_decode((string) $borrado->valor_anterior, true)['tipo']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — Comportamiento
    // ─────────────────────────────────────────────────────────────────────

    /** Cambiar la nota de comportamiento deja línea con los dos valores. */
    public function test_cambiar_el_comportamiento_deja_los_dos_valores(): void
    {
        $token = $this->tokenDeSuperusuario();

        $nc = DB::selectOne('SELECT nc.id, nc.nota, nc.alumno_id FROM nota_comportamiento nc
            INNER JOIN periodos p ON p.id = nc.periodo_id AND p.deleted_at IS NULL
            WHERE nc.deleted_at IS NULL ORDER BY nc.id LIMIT 1');
        $this->assertNotNull($nc, 'El seed necesita una nota de comportamiento con periodo.');

        $nueva = (float) $nc->nota == 40.0 ? 30.0 : 40.0;

        $this->withToken($token)->putJson('/api/nota_comportamiento/update/'.$nc->id, ['nota' => $nueva])
            ->assertStatus(200);

        $lineas = $this->lineasDe('comportamiento', (int) $nc->id);

        $this->assertCount(1, $lineas, 'Cambiar el comportamiento no dejó línea.');
        $this->assertSame(Auditoria::EDITAR, $lineas[0]->accion);

        $antes = json_decode((string) $lineas[0]->valor_anterior, true);
        $despues = json_decode((string) $lineas[0]->valor_nuevo, true);

        $this->assertEquals($nc->nota, $antes['nota'],
            'La línea no guardó el valor de antes: se capturó después de pisarlo.');
        $this->assertEquals($nueva, $despues['nota']);
    }

    /**
     * El libro rojo que **nace solo** al abrir la pantalla se registra como del
     * sistema, no de quien la abrió.
     *
     * `getDetailed` es un GET y escribe: crea el libro rojo del alumno que no lo
     * tiene. Quien abrió la pantalla no decidió crear nada, y anotarlo como suyo
     * pondría «Fulano creó el libro rojo de cuarenta alumnos» en la pantalla de la
     * fase 5 — la clase de ruido automático que hace que un historial deje de
     * leerse, y el mismo motivo por el que la definitiva que **recalcula** el
     * sistema no entra como la que un profesor **teclea**.
     */
    public function test_el_libro_rojo_que_nace_solo_es_del_sistema(): void
    {
        $token = $this->tokenDeSuperusuario();

        $grupo = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE g.deleted_at IS NULL GROUP BY g.id ORDER BY g.id LIMIT 1');
        $this->assertNotNull($grupo, 'El seed necesita un grupo con alumnos.');

        DB::delete('DELETE FROM dis_libro_rojo');

        $this->withToken($token)->getJson('/api/nota_comportamiento/detailed/'.$grupo->id)
            ->assertStatus(200);

        $lineas = $this->lineasDe('dis_libro_rojo');

        $this->assertNotCount(0, $lineas, 'El libro rojo nació y no dejó línea.');

        foreach ($lineas as $linea) {
            $this->assertSame(Auditoria::CREAR, $linea->accion);
            $this->assertSame('sistema', $linea->actor_tipo,
                'El libro rojo automático se anotó a nombre de quien abrió la pantalla.');
            $this->assertNull($linea->actor_user_id,
                'Lo que hace el sistema no lleva una persona detrás.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 y 4 — Disciplina y situaciones
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Crear una falta disciplinaria y borrarla dejan las dos líneas, y la del
     * borrado se lleva la descripción dentro.
     *
     * Es un registro disciplinario de un menor, y `deleted_by` dice **quién** pero
     * no dice **qué**. Sin la línea, lo que decía la falta desaparece de la ficha
     * sin que quede en ninguna parte.
     */
    public function test_crear_y_borrar_una_falta_disciplinaria_dejan_las_dos_lineas(): void
    {
        $token = $this->tokenDeSuperusuario();

        $ctx = DB::selectOne('SELECT m.alumno_id, g.year_id, u.periodo_id
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN users u ON u.id = (SELECT id FROM users WHERE is_superuser = 1
                AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1)
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1');
        $this->assertNotNull($ctx, 'El seed necesita un alumno matriculado.');

        $this->withToken($token)->postJson('/api/disciplina/store', [
            'alumno_id' => $ctx->alumno_id,
            'year_id' => $ctx->year_id,
            'periodo_id' => $ctx->periodo_id,
            'descripcion' => 'Llegó tarde tres veces seguidas',
            'tipo_situacion' => 'I',
            'testigos' => 'El coordinador',
            'descargo' => 'Dice que se le dañó la ruta',
            'fecha_hora_aprox' => '2026-08-25 07:15:00',
        ])->assertStatus(200);

        $alta = $this->lineasDe('dis_proceso');

        $this->assertNotCount(0, $alta, 'Crear la falta disciplinaria no dejó línea.');
        $this->assertSame(Auditoria::CREAR, $alta[0]->accion);
        $this->assertSame('Llegó tarde tres veces seguidas',
            json_decode((string) $alta[0]->valor_nuevo, true)['descripcion']);
        $this->assertEquals($ctx->alumno_id, $alta[0]->alumno_id);

        $procesoId = (int) $alta[0]->entidad_id;

        $this->olvidarControladores();

        $this->withToken($token)->putJson('/api/disciplina/destroy', [
            'proceso_id' => $procesoId,
            'alumno_id' => $ctx->alumno_id,
        ])->assertStatus(200);

        $borradas = array_values(array_filter($this->lineasDe('dis_proceso', $procesoId),
            fn ($l) => $l->accion === Auditoria::BORRAR));

        $this->assertCount(1, $borradas, 'Borrar la falta disciplinaria no dejó su línea.');
        $this->assertSame('Llegó tarde tres veces seguidas',
            json_decode((string) $borradas[0]->valor_anterior, true)['descripcion'],
            'La línea del borrado no se llevó dentro lo que la falta decía.');
    }

    /**
     * Cambiar de qué falta deriva una situación queda anotado — y **es la ruta que
     * no deja ningún otro rastro**.
     *
     * `putCambiarSituacionDerivante` no escribe `updated_by` ni `updated_at`; lo
     * dice el comentario que quien lo escribió dejó al lado del UPDATE. Así que
     * hasta hoy esta relación entre dos faltas disciplinarias de un menor se podía
     * cambiar sin que quedara ni el autor.
     */
    public function test_cambiar_la_situacion_derivante_queda_anotado(): void
    {
        $token = $this->tokenDeSuperusuario();

        // Las dos faltas se crean **por la API**, no se buscan en el seed: `dis_procesos`
        // llega vacía, y un caso que las buscara se saltaría a sí mismo en verde —
        // que es la trampa que este repositorio ya lleva doce veces contadas.
        $ids = [$this->crearFalta($token, 'La primera'), $this->crearFalta($token, 'La segunda')];

        $this->olvidarControladores();

        $this->withToken($token)->putJson('/api/disciplina/cambiar-situacion-derivante', [
            'id' => $ids[0],
            'become_id' => $ids[1],
        ])->assertStatus(200);

        $lineas = array_values(array_filter($this->lineasDe('dis_proceso', $ids[0]),
            fn ($l) => $l->accion === Auditoria::EDITAR));

        $this->assertCount(1, $lineas, 'Cambiar la situación derivante no dejó línea.');
        $this->assertEquals($ids[1],
            json_decode((string) $lineas[0]->valor_nuevo, true)['become_id']);
        $this->assertNotNull($lineas[0]->actor_user_id,
            'Es la única ruta de disciplina que no escribe autor en su propia fila: '.
            'si la línea tampoco lo trae, no queda en ninguna parte.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5 — Frases
    // ─────────────────────────────────────────────────────────────────────

    /**
     * La frase que se le pone a **un alumno** en su boletín deja línea con su
     * nombre, y quitarla deja el texto dentro.
     *
     * De las tres familias de frases ésta es la que va pegada a una persona: sale
     * impresa en el boletín. Quitarla es el cambio que un acudiente nota, y hasta
     * hoy no quedaba de él ningún rastro.
     */
    public function test_poner_y_quitar_una_frase_del_boletin_dejan_las_dos_lineas(): void
    {
        $token = $this->tokenDeSuperusuario();

        $ctx = DB::selectOne('SELECT m.alumno_id, a.id AS asignatura_id
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1');
        $this->assertNotNull($ctx, 'El seed necesita un alumno matriculado con asignatura.');

        $r = $this->withToken($token)->postJson('/api/frases_asignatura/store', [
            'alumno_id' => $ctx->alumno_id,
            'asignatura_id' => $ctx->asignatura_id,
            'frase' => 'Mejoró mucho en el segundo periodo',
        ]);
        $r->assertStatus(200);

        $alta = $this->lineasDe('frase_asignatura');

        $this->assertNotCount(0, $alta, 'Poner la frase no dejó línea.');
        $this->assertSame(Auditoria::CREAR, $alta[0]->accion);
        $this->assertEquals($ctx->alumno_id, $alta[0]->alumno_id,
            'La frase del boletín no dice de qué alumno es.');

        $fraseId = (int) $alta[0]->entidad_id;

        $this->olvidarControladores();

        $this->withToken($token)->deleteJson('/api/frases_asignatura/destroy/'.$fraseId)
            ->assertStatus(200);

        $borradas = array_values(array_filter($this->lineasDe('frase_asignatura', $fraseId),
            fn ($l) => $l->accion === Auditoria::BORRAR));

        $this->assertCount(1, $borradas, 'Quitar la frase no dejó su línea.');
        $this->assertSame('Mejoró mucho en el segundo periodo',
            json_decode((string) $borradas[0]->valor_anterior, true)['frase'],
            'La línea del borrado no se llevó el texto dentro.');
    }

    /** Y la frase del catálogo del colegio, que son 426 escritas a mano. */
    public function test_borrar_una_frase_del_catalogo_se_lleva_el_texto_dentro(): void
    {
        $token = $this->tokenDeSuperusuario();

        $frase = DB::selectOne('SELECT id, frase FROM frases WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($frase, 'El seed necesita una frase.');

        $this->withToken($token)->deleteJson('/api/frases/destroy/'.$frase->id)->assertStatus(200);

        $lineas = $this->lineasDe('frase', (int) $frase->id);

        $this->assertCount(1, $lineas, 'Borrar la frase no dejó línea.');
        $this->assertSame(Auditoria::BORRAR, $lineas[0]->accion);
        $this->assertSame($frase->frase, json_decode((string) $lineas[0]->valor_anterior, true)['frase'],
            'El texto de la frase no quedó en la línea, y en `frases` ya está en la papelera: '.
            'no queda en ninguna parte.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que vale para las cinco
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Ninguna línea sale sin una descripción que se pueda leer.**
     *
     * Es un criterio de aceptación y no una comprobación de cortesía. Medido en
     * Chrome contra el cuerpo crudo el 25 ago 2026: `GET /api/bitacoras` manda
     * `descripcion: null` en las 22 filas, porque de los diez escritores viejos
     * **sólo dos** escriben esa columna. Si las cinco familias nuevas grabaran
     * igual, AUD-4 entregaría mucho volumen y ninguna información — la pantalla
     * seguiría sin poder enseñar nada, sólo que con diez veces más filas.
     *
     * Se comprueban las dos mitades, porque «no null» no es «legible»:
     *
     * 1. que `resumen` venga siempre —lo garantiza la forma de `guardar()`—, y
     * 2. que **cuando la línea es de un alumno, el alumno salga por su nombre**.
     *    Sin eso la frase de serie dice «Fulano borró ausencia 4821»: un verbo, el
     *    nombre de la entidad y un id, que es justo rellenar con el nombre de la
     *    tabla.
     */
    public function test_ninguna_linea_sale_sin_una_descripcion_legible(): void
    {
        $token = $this->tokenDeSuperusuario();

        $ctx = DB::selectOne('SELECT m.alumno_id, a.id AS asignatura_id, al.nombres, al.apellidos
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            INNER JOIN alumnos al ON al.id = m.alumno_id
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1');
        $this->assertNotNull($ctx, 'El seed necesita un alumno matriculado con asignatura.');

        $this->withToken($token)->postJson('/api/ausencias/store', [
            'alumno_id' => $ctx->alumno_id,
            'asignatura_id' => $ctx->asignatura_id,
            'cantidad_ausencia' => 1,
            'fecha_hora' => '2026-08-25 09:00:00',
        ])->assertStatus(201);

        $lineas = DB::select('SELECT * FROM auditoria');

        $this->assertNotCount(0, $lineas, 'No se escribió ninguna línea: el caso no mide nada.');

        foreach ($lineas as $linea) {
            $this->assertNotNull($linea->resumen,
                '`auditoria` repitió el fallo de `bitacoras`: una línea sin descripción. '.
                '`Auditoria::guardar()` rellena `resumen` siempre, así que si esto falla '.
                'es que la fila no pasó por el servicio.');
            $this->assertNotSame('', trim((string) $linea->resumen));
        }

        $deLaFalta = array_values(array_filter($lineas, fn ($l) => $l->entidad === 'ausencia'));

        $this->assertCount(1, $deLaFalta);

        $esperado = trim($ctx->nombres.' '.$ctx->apellidos);

        $this->assertSame($esperado, $deLaFalta[0]->alumno_nombre,
            'La línea no congeló el nombre del alumno. Con `alumno_id` a secas la '.
            'descripción dice «… borró ausencia 4821» y deja de poder leerse — y el '.
            'nombre va COPIADO en la fila, no unido al leer, porque `auditoria` no '.
            'tiene claves foráneas: la línea se tiene que poder leer aunque el alumno '.
            'ya no exista (18 §2.4).');

        $this->assertStringContainsString($esperado, (string) $deLaFalta[0]->resumen,
            'El nombre está en su columna pero la frase de serie no lo usa.');
    }

    /**
     * **Ninguna de las cinco familias abre una transacción propia para el
     * rastro**: si el cambio se deshace, la línea se deshace con él.
     *
     * Es la propiedad que `Auditoria` tiene por hacer sólo un `INSERT` (18 §4.2),
     * y la que hay que comprobar **desde un llamante de verdad** y no desde el
     * servicio: lo que se rompe al instrumentar no es el servicio, es meter la
     * llamada donde la transacción no la alcanza. Una línea que sobreviva a un
     * cambio deshecho afirma que pasó algo que no pasó, y eso es peor que no
     * tener línea.
     */
    public function test_si_el_cambio_se_deshace_la_linea_de_la_familia_tambien(): void
    {
        $token = $this->tokenDeSuperusuario();

        $frase = DB::selectOne('SELECT id FROM frases WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($frase, 'El seed necesita una frase.');

        $antes = DB::table('auditoria')->count();

        try {
            DB::transaction(function () use ($token, $frase) {
                $this->withToken($token)->deleteJson('/api/frases/destroy/'.$frase->id)->assertStatus(200);

                throw new \RuntimeException('deshacer');
            });
        } catch (\RuntimeException $e) {
            // Es el que se lanza a propósito.
        }

        $this->assertSame($antes, DB::table('auditoria')->count(),
            'La línea sobrevivió a un cambio que se deshizo: `Auditoria` está escribiendo '.
            'fuera de la transacción del llamante y la tabla afirma algo que no pasó.');
    }
}
