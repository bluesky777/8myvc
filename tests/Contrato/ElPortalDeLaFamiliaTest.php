<?php

namespace Tests\Contrato;

use App\Services\Notificaciones\Publicador;
use App\Services\Notificaciones\TemasDeNotificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

/**
 * **El portal de la familia, el tablero del día y el vocabulario del paso.**
 *
 * Diez rutas. El contrato está en `docs/migracion/47-el-portal-de-la-familia.md`;
 * las pantallas, en `myvc_front/PANTALLAS-MATRICULA.md`.
 *
 * ## LO QUE ESTE FICHERO DEFIENDE DE VERDAD, y ninguna de las cuatro se ve leyendo el código
 *
 * 1. **Que corregir una observación no cierre un paso.** Era un fallo vivo:
 *    `prematriculas.ts` manda `estado: observacion.estado ?? ''` al guardar el texto,
 *    y `''` no era `falta` ni `devuelto`, así que entraba por la rama de cerrar —
 *    **con la firma de quien escribió la tilde y la hora en que la escribió**. Lo fija
 *    `test_corregir_una_observacion_no_cierra_el_paso`, y el control se vio en rojo.
 *
 * 2. **Que fijar el vocabulario no rompa las pantallas desplegadas.** La lista del 46
 *    era `Falta|Cumple|Observado|Devuelto` y **ninguno de esos cuatro es lo que
 *    escriben los dieciséis colegios**: mandan `falta`, `ya` y `n/a`, en minúscula.
 *    Rechazar por esa lista habría roto la ficha del alumno el día del despliegue.
 *    Lo fija `test_los_tres_estados_de_las_pantallas_vivas_siguen_entrando`.
 *
 * 3. **Que un código no revele el nombre de un menor.** Es la regla que fijó `GET
 *    colillas-inscripcion/{codigo}` el 20 sep, y aquí muerde más fuerte porque este
 *    portal **sí tiene que devolver la persona** a quien presente el segundo factor.
 *    Lo fija un test que busca el nombre **en el JSON entero**, no campo a campo: *un
 *    campo de más en una respuesta pública no rompe nada y no se nota hasta que
 *    importa.*
 *
 * 4. **Que el aviso al acudiente no lleve el motivo dentro.** Una notificación se lee
 *    en la pantalla bloqueada de un bus. El nombre sí va —lo permite
 *    `notificaciones.md`—, el motivo no, y el motivo es justo lo que un docente acaba
 *    de escribir sobre un menor.
 */
class ElPortalDeLaFamiliaTest extends CasoDeContrato
{
    private const PORTAL = '/api/inscripcion';

    private const ASPIRANTES = '/api/aspirantes';

    private ?string $token = null;

    private ?string $tokenAdmin = null;

    // ==================================================================
    // 1 · El vocabulario del paso, y el fallo que tapaba
    // ==================================================================

    /**
     * **EL FALLO VIVO.** Corregir una observación mandaba `estado: ''`, y eso cerraba
     * el paso y lo firmaba.
     *
     * El escenario es el de la pantalla real: hay una fila con el estado **en blanco**
     * y alguien edita **sólo el texto**.
     *
     * **Y que sea blanco y no NULL es un hallazgo de escribir este test.** La columna
     * es `varchar(255) NOT NULL DEFAULT 'Falta'`, así que el `UPDATE` roto que estuvo
     * vivo hasta el 1 sep 2026 **no pudo dejar NULL en ninguna parte**: en un servidor
     * no estricto —que es lo que corre el docker— `SET estado=NULL` sobre una columna
     * `NOT NULL` escribe **la cadena vacía** con un aviso. O sea que la fila en blanco
     * no es un caso inventado para el test: es exactamente lo que dejó aquel fallo, y
     * el que la reabre es `prematriculas.ts` mandando `observacion.estado ?? ''`.
     *
     * **Se mira la base y no la respuesta**, porque la respuesta era `'Actualizado'`
     * en los dos casos: ahí está toda la gracia del fallo.
     */
    public function test_corregir_una_observacion_no_cierra_el_paso(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Documentos', 1);
        $marca = $this->unaMarcaConEstado($alumno, 'Documentos', '');

        $this->withToken($this->tokenLlano())->postJson('/api/requisitos/alumno', [
            'requisito_alumno_id' => $marca,
            'estado' => '',
            'descripcion' => 'Falta la copia del documento del papá',
        ])->assertStatus(200);

        $fila = DB::selectOne('SELECT estado, descripcion, cerrado_por, cerrado_at
            FROM requisitos_alumno WHERE id=?', [$marca]);

        $this->assertSame('Falta la copia del documento del papá', $fila->descripcion,
            'La observación sí tenía que guardarse: es lo único que esa pantalla quería escribir.');

        $this->assertNull($fila->cerrado_at,
            'Corregir una observación CERRÓ el paso. La cadena vacía no es un estado.');
        $this->assertNull($fila->cerrado_por,
            'Corregir una observación firmó el paso con el nombre de quien escribió el texto.');
    }

    /**
     * La otra mitad: un estado vacío **tampoco escribe la columna**.
     *
     * Sin esto, la fila quedaría con `estado=''`, que `getRecorrido` lee como
     * «cumplido» —su regla es *«cualquier cosa que no sea falta»*— y entonces el paso
     * saldría hecho en la pantalla aunque `cerrado_at` estuviera limpio. **Dos formas
     * de la misma mentira, y hacen falta las dos comprobaciones.**
     */
    public function test_un_estado_vacio_no_pisa_el_que_habia(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Documentos', 1);
        $marca = $this->unaMarcaConEstado($alumno, 'Documentos', 'ya');

        $this->withToken($this->tokenLlano())->postJson('/api/requisitos/alumno', [
            'requisito_alumno_id' => $marca,
            'estado' => '',
            'descripcion' => 'una tilde',
        ])->assertStatus(200);

        $this->assertSame('ya', DB::selectOne('SELECT estado FROM requisitos_alumno WHERE id=?',
            [$marca])->estado, 'El estado vacío pisó el que ya estaba escrito.');
    }

    /**
     * **Lo que protege a los dieciséis colegios.** Los tres valores que mandan las dos
     * pantallas desplegadas siguen entrando.
     *
     * Medido en el fuente de los dos clientes y no supuesto:
     * `personaMatriculasDir.html` (v1, los dieciséis) y `persona-matriculas.ts`
     * (`app2`) tienen los dos la misma lista cerrada de tres.
     */
    public function test_los_tres_estados_de_las_pantallas_vivas_siguen_entrando(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Documentos', 1);
        $marca = $this->unaMarcaConEstado($alumno, 'Documentos', 'falta');

        foreach (['falta', 'ya', 'n/a'] as $estado) {
            $this->withToken($this->tokenLlano())->postJson('/api/requisitos/alumno', [
                'requisito_alumno_id' => $marca,
                'estado' => $estado,
            ])->assertStatus(200);

            $this->assertSame($estado,
                DB::selectOne('SELECT estado FROM requisitos_alumno WHERE id=?', [$marca])->estado,
                "«{$estado}» lo manda una pantalla desplegada en los dieciséis colegios.");
        }
    }

    /** `n/a` CIERRA el paso, y ésa es la única de las seis que hay que pensar. */
    public function test_no_aplica_cierra_el_paso(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Documentos', 1);
        $marca = $this->unaMarcaConEstado($alumno, 'Documentos', 'falta');

        $this->withToken($this->tokenLlano())->postJson('/api/requisitos/alumno',
            ['requisito_alumno_id' => $marca, 'estado' => 'n/a'])->assertStatus(200);

        $this->assertNotNull(DB::selectOne('SELECT cerrado_at FROM requisitos_alumno WHERE id=?',
            [$marca])->cerrado_at,
            'Un requisito que no le aplica a este alumno no le puede impedir pasar a la estación siguiente.');
    }

    /** Lo que no está en la lista se rechaza, en vez de guardarse. */
    public function test_un_estado_inventado_se_rechaza(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Documentos', 1);
        $marca = $this->unaMarcaConEstado($alumno, 'Documentos', 'falta');

        $this->withToken($this->tokenLlano())->postJson('/api/requisitos/alumno',
            ['requisito_alumno_id' => $marca, 'estado' => 'Entregado'])
            ->assertStatus(422);

        $this->assertSame('falta', DB::selectOne('SELECT estado FROM requisitos_alumno WHERE id=?',
            [$marca])->estado, 'Se rechazó con 422 y aun así escribió.');
    }

    // ==================================================================
    // 2 · La familia ve lo suyo
    // ==================================================================

    /**
     * **La ruta que faltaba para que el aviso signifique algo.**
     *
     * Y lo que se comprueba no es el 200: es que **lleva el motivo que la familia
     * tiene que leer y NO lleva la observación interna**. Las dos columnas existen
     * separadas precisamente para esto, y separarlas en el esquema no sirve de nada
     * si la respuesta las vuelve a juntar.
     */
    public function test_la_familia_lee_el_motivo_y_no_la_observacion_interna(): void
    {
        $alumno = $this->unAlumnoConAcudiente();
        $this->unPaso('Documentos', 1);

        $this->withToken($this->tokenLlano())->putJson('/api/estaciones/1/marcar', [
            'alumno_id' => $alumno['alumno_id'],
            'resultado' => 'devuelto',
            'motivo' => 'Falta el registro civil',
            'observacion' => 'La mamá se puso agresiva en la ventanilla',
        ])->assertStatus(200);

        $r = $this->withToken($this->tokenDe($alumno['username']))
            ->getJson('/api/requisitos/mi-recorrido/'.$alumno['alumno_id'])
            ->assertStatus(200);

        $this->assertSame('Falta el registro civil', $r->json('pasos.0.motivo_devolucion'));
        $this->assertTrue($r->json('pasos.0.devuelto'));

        $this->assertStringNotContainsString('agresiva', json_encode($r->json()),
            'La observación del personal viajó al portal de la familia.');
    }

    /**
     * **`recorrido` y `mi-recorrido` se diferencian en tres caracteres, y el error
     * que importa NO hace ruido.**
     *
     * Lo levantó `myvc-flutter-1a` al leer las dos seguidas, y medido desde aquí el
     * riesgo resultó estar **en la dirección contraria a la que él temía**:
     *
     * ```
     * familia  -> requisitos/recorrido      403 SIEMPRE            se ve
     * personal -> requisitos/mi-recorrido   200 SIEMPRE, con dos   no se ve
     *                                       campos de menos
     * ```
     *
     * `ExigirBoletinPropio` **deja pasar de largo a todo el personal** —a propósito,
     * para que secretaría pueda enseñarle el recorrido a una madre por teléfono—, así
     * que una pantalla del personal mal cableada a `mi-recorrido` **no falla nunca**:
     * devuelve la vista de la familia, sin la observación y sin quién cerró el paso.
     * *El síntoma no es un error, son dos campos que faltan.*
     *
     * > **Y `descripcion` SÍ viaja en las dos, que es la mitad que casi se fija al
     * > revés.** La propuesta que llegó era un test de que `mi-recorrido` no trae
     * > `descripcion` — y la trae: es la del **requisito**, que es pública. La que no
     * > viaja es `ra.descripcion`, que sale con el alias `observacion`. Dos columnas
     * > que se llaman igual en dos tablas, y el test las habría confundido.
     */
    public function test_el_personal_entra_en_mi_recorrido_y_pierde_dos_campos_sin_enterarse(): void
    {
        $alumno = $this->unAlumnoConAcudiente();
        $this->unPaso('Documentos', 1);

        $this->withToken($this->tokenLlano())->putJson('/api/estaciones/1/marcar', [
            'alumno_id' => $alumno['alumno_id'],
            'resultado' => 'cumple',
            'observacion' => 'Trajo el registro civil en fotocopia',
        ])->assertStatus(200);

        $delPersonal = $this->withToken($this->tokenLlano())
            ->getJson('/api/requisitos/recorrido/'.$alumno['alumno_id'])
            ->assertStatus(200);

        $this->assertSame('Trajo el registro civil en fotocopia', $delPersonal->json('pasos.0.observacion'),
            'El recorrido del personal dejó de traer la observación, que es para lo que existe.');
        $this->assertNotNull($delPersonal->json('pasos.0.cerrado_por'),
            'El recorrido del personal dejó de decir quién cerró el paso.');

        // **Y aquí se fija un defecto vivo, NO el comportamiento que uno querría.**
        // `cerrado_por_nombres` sale de `profesores`, y **0 de las 20 cuentas de tipo
        // `Usuario` de esta base tienen ficha ahí** (0 de 22 en la copia de
        // desarrollo): o sea que cuando cierra el paso un administrativo —que es
        // quien atiende la ventanilla— el id viaja y **el nombre sale vacío**. Es la
        // trampa que el `CLAUDE.md` ya tiene escrita, cometida otra vez. Se fija en
        // rojo el día que se arregle, y entonces esta línea se cambia a `assertNotNull`
        // con la decisión de Joseth delante: `users` sólo tiene `username`, así que
        // **de dónde sale el nombre de un administrativo es una pregunta abierta**.
        $this->assertNull($delPersonal->json('pasos.0.cerrado_por_nombres'),
            'Ya sale el nombre del administrativo que cerró el paso: arregla esta línea, '
            .'que fijaba el defecto.');

        // **La misma cuenta, la ruta de al lado**: esto es lo que se lleva una
        // pantalla mal cableada, y es un 200.
        $conLaDeLaFamilia = $this->withToken($this->tokenLlano())
            ->getJson('/api/requisitos/mi-recorrido/'.$alumno['alumno_id'])
            ->assertStatus(200);

        $crudo = json_encode($conLaDeLaFamilia->json());

        $this->assertStringNotContainsString('observacion', $crudo,
            'La observación interna empezó a salir por la ruta de la familia.');
        $this->assertStringNotContainsString('cerrado_por', $crudo,
            'El nombre de quien cerró el paso empezó a salir por la ruta de la familia.');

        // Y la otra mitad: `descripcion` es la del REQUISITO y sí viaja. Fijarla aquí
        // es lo que impide «arreglar» la asimetría quitándole a la familia el texto
        // que le dice qué le piden.
        $this->assertArrayHasKey('descripcion', $conLaDeLaFamilia->json('pasos.0'),
            'La familia dejó de ver qué le piden en cada paso.');
    }

    /** Un alumno no puede mirar el recorrido de un compañero. */
    public function test_un_alumno_no_ve_el_recorrido_de_otro(): void
    {
        $alumno = $this->unAlumnoConAcudiente();

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            WHERE a.id <> ? AND a.deleted_at IS NULL LIMIT 1', [$alumno['alumno_id']]);

        $this->assertNotNull($ajeno, 'El seed necesita dos alumnos.');

        $this->withToken($this->tokenDe($alumno['username']))
            ->getJson('/api/requisitos/mi-recorrido/'.$ajeno->id)
            ->assertStatus(403);
    }

    /**
     * **Y LA RAMA DEL ACUDIENTE, que es la que de verdad hace falta probar.**
     *
     * Este test se llamaba «un acudiente no ve el de otro» y usaba **un token de
     * alumno**: el nombre prometía la rama de `parentescos` y ejercitaba la de
     * `persona_id`. *Un test que dice cubrir algo y no lo cubre es peor que no
     * tenerlo, porque nadie va a ir a mirar.*
     *
     * Y es la que importa: el aviso del día de matrículas **le llega al acudiente**,
     * no al alumno, así que es su token el que va a abrir esta ruta. Se comprueba por
     * las dos direcciones — el suyo pasa, el ajeno no— porque un 403 a todo también
     * dejaría este test en verde.
     */
    public function test_un_acudiente_ve_lo_de_su_acudido_y_no_lo_de_otro(): void
    {
        $usuario = $this->usuarioDeTipo('Acudiente');

        $suyo = DB::selectOne('SELECT pa.alumno_id FROM parentescos pa
            INNER JOIN acudientes ac ON ac.id=pa.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN alumnos a ON a.id=pa.alumno_id AND a.deleted_at IS NULL
            WHERE ac.user_id=? AND pa.deleted_at IS NULL LIMIT 1', [$usuario->id]);

        $this->assertNotNull($suyo, 'El seed necesita un acudiente con al menos un acudido.');

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            WHERE a.deleted_at IS NULL AND a.id NOT IN (
                SELECT pa.alumno_id FROM parentescos pa
                INNER JOIN acudientes ac ON ac.id=pa.acudiente_id AND ac.deleted_at IS NULL
                WHERE ac.user_id=? AND pa.deleted_at IS NULL) LIMIT 1', [$usuario->id]);

        $this->assertNotNull($ajeno, 'El seed necesita un alumno que NO sea de ese acudiente.');

        $token = $this->tokenDe($usuario->username);

        $this->withToken($token)
            ->getJson('/api/requisitos/mi-recorrido/'.$suyo->alumno_id)
            ->assertStatus(200);

        $this->withToken($token)
            ->getJson('/api/requisitos/mi-recorrido/'.$ajeno->id)
            ->assertStatus(403);
    }

    /** Y el personal pasa de largo: secretaría abre la misma vista por teléfono. */
    public function test_el_personal_puede_abrir_la_vista_de_la_familia(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Documentos', 1);

        $this->withToken($this->tokenLlano())
            ->getJson('/api/requisitos/mi-recorrido/'.$alumno)
            ->assertStatus(200);
    }

    // ==================================================================
    // 3 · El tablero del día
    // ==================================================================

    /**
     * **El tapón se mide con la ESPERA y no con la cola**, y sin nadie esperando es
     * `null` y no cero.
     *
     * *«No hay tapón» y «no hay datos» no se pueden leer igual*: en una pared
     * proyectada, un cero se lee como «todo va bien».
     */
    public function test_sin_nadie_esperando_no_hay_tapon(): void
    {
        $this->unPaso('Documentos', 1);

        $r = $this->withToken($this->tokenLlano())->getJson('/api/estaciones/tablero')
            ->assertStatus(200);

        $this->assertNull($r->json('tapon'),
            'Sin cola no hay tapón, y un cero diría que la estación está despachando.');
        $this->assertSame(0, $r->json('estaciones.0.esperando'));
        $this->assertNull($r->json('estaciones.0.espera_media_min'));
    }

    /**
     * Quien entró al recorrido y no lo ha terminado sale en `sin_terminar`, **con
     * cuántos le faltan**.
     *
     * Y el que no ha empezado **no sale**: la fila que crea `AlumnosController` al
     * matricular deja `updated_by` en NULL, así que abrirle la ficha a alguien no lo
     * mete en ninguna lista.
     */
    public function test_el_que_entro_y_no_termino_sale_y_el_que_no_vino_no(): void
    {
        [$vino, $noVino] = $this->dosAlumnos();
        $this->unPaso('Recepción', 1);
        $this->unPaso('Documentos', 2);

        $this->withToken($this->tokenLlano())->putJson('/api/estaciones/1/marcar',
            ['alumno_id' => $vino, 'resultado' => 'cumple'])->assertStatus(200);

        $r = $this->withToken($this->tokenLlano())->getJson('/api/estaciones/tablero')
            ->assertStatus(200);

        $ids = array_column($r->json('sin_terminar.personas'), 'alumno_id');

        $this->assertContains($vino, $ids, 'Cerró la 1 y le falta la 2: está a medias.');
        $this->assertNotContains($noVino, $ids,
            'Un alumno al que nadie ha tocado un paso no se «fue sin terminar»: no vino.');

        $fila = collect($r->json('sin_terminar.personas'))->firstWhere('alumno_id', $vino);
        $this->assertSame(1, $fila['faltan']);
    }

    /**
     * **Las esperas del tablero son minutos ENTEROS.** `diffInMinutes` de Carbon 3 da
     * float, y la máxima salía como `67.93232834999999` en la pared del rector.
     */
    public function test_las_esperas_del_tablero_son_minutos_enteros(): void
    {
        [$alumno] = $this->dosAlumnos();
        $uno = $this->unPaso('Recepción', 1);
        $this->unPaso('Documentos', 2);

        $this->withToken($this->tokenLlano())->putJson('/api/estaciones/1/marcar',
            ['alumno_id' => $alumno, 'resultado' => 'cumple'])->assertStatus(200);

        // Llegó a la 2 hace 7 min y 33 s: con float sale 7.55.
        $hace = now('America/Bogota')->subSeconds(453)->toDateTimeString();
        DB::update('UPDATE requisitos_alumno SET cerrado_at=?, updated_at=? WHERE alumno_id=? AND requisito_id=?',
            [$hace, $hace, $alumno, $uno]);

        $r = $this->withToken($this->tokenLlano())->getJson('/api/estaciones/tablero')->assertStatus(200);
        $dos = collect($r->json('estaciones'))->firstWhere('nro', 2);

        $this->assertIsInt($dos['espera_maxima_min']);
        $this->assertIsInt($dos['espera_media_min']);
        $this->assertSame(8, $dos['espera_maxima_min']);
        $this->assertIsInt($r->json('tapon.espera_media_min'));
    }

    /**
     * **A QUIEN LE DEVUELVEN UN PASO SIGUE ESTANDO EN EL PATIO**, y el tablero tiene
     * que verlo.
     *
     * Éste es el caso que de verdad sostiene el marcador `tocado`, y **se escribió
     * después de que un control no se pusiera rojo**: el test de arriba —el que
     * separa al que vino del que no— pasa igual con el marcador quitado, porque al
     * que no vino le falta la fila entera. *Un control que no se ve en rojo no está
     * protegiendo lo que uno cree que protege, y la forma de saberlo es romper el
     * código, no leer el docblock.*
     *
     * Devolver **limpia `cerrado_at` a propósito** —el paso se sigue debiendo—, así
     * que un tablero que contara «entró» por lo cerrado haría desaparecer a esta
     * familia de todas las listas justo cuando más falta hace verla. Es la misma
     * desaparición silenciosa que el 46 §2 encontró en la cola de la primera estación.
     */
    public function test_a_quien_le_devuelven_un_paso_sigue_contando_como_que_vino(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Recepción', 1);
        $this->unPaso('Documentos', 2);

        $this->withToken($this->tokenLlano())->putJson('/api/estaciones/1/marcar', [
            'alumno_id' => $alumno,
            'resultado' => 'devuelto',
            'motivo' => 'Falta el registro civil',
        ])->assertStatus(200);

        $this->assertNull(DB::selectOne('SELECT ra.cerrado_at FROM requisitos_alumno ra
            INNER JOIN requisitos_matricula r ON r.id=ra.requisito_id
            WHERE ra.alumno_id=? AND r.orden=1', [$alumno])->cerrado_at,
            'Devolver tiene que limpiar `cerrado_at`: el paso se sigue debiendo.');

        $r = $this->withToken($this->tokenLlano())->getJson('/api/estaciones/tablero')
            ->assertStatus(200);

        $this->assertContains($alumno, array_column($r->json('sin_terminar.personas'), 'alumno_id'),
            'A quien le devolvieron un paso no le queda nada cerrado, y aun así está en el patio.');

        $this->assertSame(1, $r->json('totales.en_el_recorrido'),
            'El que está dentro del recorrido dejó de contarse por no tener nada cerrado.');
    }

    /** El salteado cuenta en el tablero, que es lo único que lee `envios_estacion`. */
    public function test_el_salteado_se_cuenta_en_el_tablero(): void
    {
        [$alumno] = $this->dosAlumnos();
        $this->unPaso('Recepción', 1);
        $this->unPaso('Documentos', 2);

        $this->withToken($this->tokenLlano())->putJson('/api/estaciones/2/enviar-a/1',
            ['alumno_id' => $alumno])->assertStatus(200);

        $r = $this->withToken($this->tokenLlano())->getJson('/api/estaciones/tablero')
            ->assertStatus(200);

        $this->assertSame([['desde' => 2, 'hacia' => 1, 'n' => 1]], $r->json('salteados'),
            'Sin esta lectura, `envios_estacion` sería una tabla que no lee nadie.');
    }

    // ==================================================================
    // 4 · El aviso al acudiente
    // ==================================================================

    /**
     * **El aviso lleva el nombre y NO lleva el motivo.**
     *
     * `notificaciones.md` permite nombrar al menor y prohíbe el contenido. El motivo
     * lo escribió un docente para que lo lea la familia, pero se lee **abriendo la
     * app**, no en la pantalla bloqueada del bus.
     *
     * Se mide con un publicador de mentira —`PublicadorDeMentira`, el mismo que usa
     * `EnviarNotificacionesTest`—: lo que hay que comprobar es **qué texto se manda**,
     * y eso no necesita a Google. Si lo necesitara, no se comprobaría nunca.
     */
    public function test_el_aviso_de_matricula_no_lleva_el_motivo_dentro(): void
    {
        $alumno = $this->unAlumnoConAcudiente();
        $this->unPaso('Documentos', 1);

        $publicador = $this->unPublicadorDeMentira();

        // La primera pasada sólo pone la marca. Es lo que impide que encender esto en
        // un colegio le mande a cada familia un aviso por cada fila del año.
        $this->correrElCron();

        // **Y se comprueba que no mandó nada**, en vez de vaciar la lista: es la
        // promesa de la primera pasada —«pone la marca y se va»— y vaciarla a mano
        // la daría por supuesta justo donde se puede medir.
        $tema = TemasDeNotificacion::deAlumnoYTipo($alumno['alumno_id'], 'matricula');

        $this->assertCount(0, $this->avisosAlTema($publicador, $tema),
            'La primera pasada avisó de lo que ya estaba en la base.');

        $this->withToken($this->tokenLlano())->putJson('/api/estaciones/1/marcar', [
            'alumno_id' => $alumno['alumno_id'],
            'resultado' => 'devuelto',
            'motivo' => 'Falta el registro civil',
        ])->assertStatus(200);

        $this->correrElCron();

        $suyos = $this->avisosAlTema($publicador, $tema);

        $this->assertCount(1, $suyos,
            'La devolución dio '.count($suyos).' avisos de matrícula en vez de uno.');

        $this->assertStringContainsString('fue devuelta en Documentos', $suyos[0]['cuerpo']);
        $this->assertStringNotContainsString('registro civil', $suyos[0]['cuerpo'],
            'El motivo viajó dentro de la notificación: eso se lee en la pantalla bloqueada de un bus.');
    }

    // ==================================================================
    // 5 · El portal de la familia
    // ==================================================================

    /** Las tres del portal son públicas: sin token contestan, no dan 401. */
    public function test_las_tres_del_portal_no_piden_token(): void
    {
        $codigo = $this->unaOrdenPagada();

        $this->getJson(self::PORTAL.'/'.$codigo)->assertStatus(200);
        // Con el documento: el nombre solo ya no se guarda, ver
        // `test_el_nombre_sin_documento_ni_fecha_no_se_guarda`.
        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'documento' => '1090123456'])->assertStatus(200);
    }

    /**
     * **El nombre sin documento ni fecha cerraría el formulario para siempre.**
     *
     * Con nombre dentro, el portal pide el documento o la fecha de nacimiento para
     * volver a abrirlo; sin ninguno de los dos no hay con qué comparar y el formulario
     * queda cerrado para la familia y para el colegio. La pantalla guarda sola mientras
     * se teclea, así que escribir el nombre primero bastaba.
     */
    public function test_el_nombre_sin_documento_ni_fecha_no_se_guarda(): void
    {
        $codigo = $this->unaOrdenPagada();

        $this->putJson(self::PORTAL.'/'.$codigo, ['nombres' => 'Laura'])->assertStatus(422);

        $this->assertNull(DB::selectOne('SELECT nombres FROM aspirantes ORDER BY id DESC LIMIT 1')->nombres ?? null,
            'Se contestó 422 y aun así escribió el nombre.');

        $r = $this->getJson(self::PORTAL.'/'.$codigo)->assertStatus(200);
        $this->assertTrue($r->json('verificado'), 'El formulario quedó cerrado sin llave con la que abrirlo.');

        // Con la fecha, en cambio, entra: es la llave del que todavía no tiene documento.
        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'fecha_nac' => '2021-03-04'])->assertStatus(200);
        $this->getJson(self::PORTAL.'/'.$codigo.'?fecha_nac=2021-03-04')
            ->assertStatus(200)->assertJsonPath('verificado', true);
    }

    /**
     * **Un dígito mal puesto en el documento se puede corregir.**
     *
     * El segundo factor y el campo se llamaban igual, así que corregir el documento
     * contestaba 403: el número bueno no casaba con el malo guardado. `llave_documento`
     * lleva el de identificarse y `documento` el que se guarda.
     */
    public function test_la_familia_corrige_su_documento_con_la_llave(): void
    {
        $codigo = $this->unaOrdenPagada();

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'documento' => '1090123465'])->assertStatus(200);

        // Sin la llave, el número nuevo no identifica a nadie.
        $this->putJson(self::PORTAL.'/'.$codigo, ['documento' => '1090123456'])->assertStatus(403);

        // Con una llave que no es, tampoco.
        $this->putJson(self::PORTAL.'/'.$codigo,
            ['llave_documento' => '999', 'documento' => '1090123456'])->assertStatus(403);

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['llave_documento' => '1090123465', 'documento' => '1090123456'])->assertStatus(200);

        $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123456')
            ->assertStatus(200)->assertJsonPath('aspirante.documento', '1090123456');
        $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123465')
            ->assertStatus(200)->assertJsonPath('verificado', false);
    }

    /**
     * **Un formulario en blanco se abre con el código solo**, porque no hay nada
     * personal que revelar todavía.
     */
    public function test_un_formulario_en_blanco_se_abre_con_el_codigo_solo(): void
    {
        $codigo = $this->unaOrdenPagada();

        $r = $this->getJson(self::PORTAL.'/'.$codigo)->assertStatus(200);

        $this->assertFalse($r->json('tiene_formulario'));
        // Los grados viajan para que `grado_id` se pueda elegir: sin ellos, el campo
        // se aceptaba y no había de dónde sacar un id.
        $this->assertNotEmpty($r->json('grados'));
        $this->assertIsInt($r->json('grados.0.id'));
        $this->assertIsString($r->json('grados.0.nombre'));
        $this->assertTrue($r->json('verificado'),
            'Sin datos dentro no hay nada que verificar: exigir una llave que nadie escribió es cerrar la puerta por dentro.');
    }

    /**
     * **EL TEST QUE MÁS IMPORTA DE ESTA FAMILIA: un código no revela el nombre de un
     * menor.**
     *
     * En cuanto el formulario lleva datos, el código solo deja de bastar. Y se busca
     * el nombre **en el JSON entero**, no en el campo donde uno lo pondría: *un campo
     * de más en una respuesta pública no rompe nada, no pone nada en rojo y no se nota
     * hasta que importa.*
     */
    public function test_el_codigo_solo_no_revela_los_datos_del_aspirante(): void
    {
        $codigo = $this->unaOrdenPagada();

        $this->putJson(self::PORTAL.'/'.$codigo, [
            'nombres' => 'Laura Sofía',
            'apellidos' => 'Gutiérrez',
            'documento' => '1090123456',
            'acu_celular' => '3001234567',
        ])->assertStatus(200);

        $r = $this->getJson(self::PORTAL.'/'.$codigo)->assertStatus(200);

        $this->assertFalse($r->json('verificado'));
        $this->assertSame('documento', $r->json('pide'),
            'La pantalla tiene que saber QUÉ pedir, y `pide` dice el campo — no el valor.');

        $json = json_encode($r->json());

        $this->assertStringNotContainsString('Laura', $json,
            'El código solo reveló el nombre del aspirante.');
        $this->assertStringNotContainsString('1090123456', $json,
            'El código solo reveló el documento del aspirante.');
        $this->assertStringNotContainsString('3001234567', $json,
            'El código solo reveló el teléfono del acudiente.');
    }

    /** Con el segundo factor sí se abre, que es para lo que existe. */
    public function test_con_el_documento_el_formulario_se_reabre(): void
    {
        $codigo = $this->unaOrdenPagada();

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'documento' => '1090123456'])->assertStatus(200);

        $r = $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123456')->assertStatus(200);

        $this->assertTrue($r->json('verificado'));
        $this->assertSame('Laura', $r->json('aspirante.nombres'));
    }

    /**
     * **Y el segundo factor cierra la ESCRITURA, no sólo la lectura.**
     *
     * Sin esto, quien se encontrara el papel podría **reescribir encima** el nombre y el
     * documento de un menor que la familia ya llenó. Es la mitad que no se ve al leer el
     * test de la lectura, y la que de verdad hace daño: leer un dato es malo, pisarlo es
     * peor —el colegio se queda con lo del desconocido y nadie se entera—.
     */
    public function test_sin_el_documento_no_se_puede_reescribir_el_formulario(): void
    {
        $codigo = $this->unaOrdenPagada();

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'documento' => '1090123456'])->assertStatus(200);

        $this->putJson(self::PORTAL.'/'.$codigo, ['nombres' => 'Otra'])->assertStatus(403);

        $this->assertSame('Laura', DB::selectOne('SELECT nombres FROM aspirantes
            ORDER BY id DESC LIMIT 1')->nombres,
            'Se contestó 403 y aun así pisó el nombre que la familia había escrito.');
    }

    /**
     * **Guardar un tramo no borra los otros.**
     *
     * La pantalla guarda sola, tramo a tramo. Un `UPDATE` que nombrara los dieciséis
     * campos siempre haría que guardar el de salud **borrara el nombre** — que es el
     * mismo fallo que este repo arregló el 1 sep en `requisitos/alumno` y volvió a
     * encontrar el 20 sep en el `estado` de esa misma ruta.
     */
    public function test_guardar_un_tramo_no_borra_los_otros(): void
    {
        $codigo = $this->unaOrdenPagada();

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'apellidos' => 'Gutiérrez', 'documento' => '1090123456'])
            ->assertStatus(200);

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['documento' => '1090123456', 'tiene_condicion_salud' => true,
                'condicion_salud' => 'Asma'])->assertStatus(200);

        $r = $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123456')->assertStatus(200);

        $this->assertSame('Laura', $r->json('aspirante.nombres'),
            'Guardar el tramo de salud borró el nombre.');
        $this->assertSame('Asma', $r->json('aspirante.condicion_salud'));
    }

    /**
     * **Un paso que no es un papel no se «sube».** En el recorrido los requisitos son
     * también las estaciones —la entrevista, tesorería—, y el portal ofrecía subirlas.
     * `pide_documento` lo dice el colegio en la pantalla 01; nace en 1 para que nada
     * cambie al desplegar.
     */
    public function test_un_paso_que_no_es_documento_no_se_sube(): void
    {
        $codigo = $this->unaOrdenPagada();
        $papel = $this->unPaso('Registro civil', 2);
        $entrevista = $this->unPaso('Entrevista', 4);

        $this->assertSame(1, (int) DB::selectOne('SELECT pide_documento p FROM requisitos_matricula WHERE id=?', [$papel])->p,
            'Sin el campo, un requisito nuevo tiene que seguir pidiéndose como documento.');

        $this->withToken($this->tokenLlano())->putJson('/api/requisitos/update', [
            'id' => $entrevista, 'requisito' => 'Entrevista', 'descripcion' => '', 'pide_documento' => false,
        ])->assertStatus(200);

        // Corregir el nombre sin mandar el campo no lo vuelve a encender.
        $this->withToken($this->tokenLlano())->putJson('/api/requisitos/update', [
            'id' => $entrevista, 'requisito' => 'Entrevista familiar', 'descripcion' => '',
        ])->assertStatus(200);
        $this->assertSame(0, (int) DB::selectOne('SELECT pide_documento p FROM requisitos_matricula WHERE id=?', [$entrevista])->p);

        $this->putJson(self::PORTAL.'/'.$codigo, ['nombres' => 'Laura', 'documento' => '1090123456'])->assertStatus(200);

        $r = $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123456')->assertStatus(200);
        $porId = collect($r->json('requisitos'))->keyBy('requisito_id');
        $this->assertTrue($porId[$papel]['pide_documento']);
        $this->assertFalse($porId[$entrevista]['pide_documento']);

        $this->postJson(self::PORTAL.'/'.$codigo.'/documento/'.$entrevista,
            ['documento' => '1090123456', 'en_papel' => true])->assertStatus(422);
        $this->postJson(self::PORTAL.'/'.$codigo.'/documento/'.$papel,
            ['documento' => '1090123456', 'en_papel' => true])->assertStatus(200);

        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM documentos_admision')->c,
            'Se contestó 422 y aun así se anotó el paso que no es un papel.');
    }

    /**
     * **«Recibido por» no sale vacío cuando recibe secretaría.** Quien recibe en
     * ventanilla no tiene ficha en `profesores`; la ficha cae al `username`, igual que
     * las estaciones.
     */
    public function test_recibido_por_lleva_la_cuenta_si_no_hay_ficha_de_profesor(): void
    {
        [$aspirante, $documento] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenAdmin())->putJson(self::ASPIRANTES.'/'.$aspirante.'/documento/'.$documento,
            ['estado' => 'RECIBIDO'])->assertStatus(200);

        $r = $this->withToken($this->tokenAdmin())->getJson(self::ASPIRANTES.'/'.$aspirante)->assertStatus(200);

        $this->assertNotEmpty($r->json('documentos.0.recibido_por_usuario'),
            'Recibió alguien sin ficha de profesor y la ficha no dice quién.');
    }

    /**
     * **El motivo de «no admitido» llega a la familia.** `putDecision` lo exige porque
     * lo lee la familia, y el portal no lo mandaba.
     */
    public function test_el_motivo_de_no_admitir_llega_al_portal(): void
    {
        [$aspirante, , $codigo] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenAdmin())->putJson(self::ASPIRANTES.'/'.$aspirante.'/decision',
            ['decision' => 'NO_ADMITIDO', 'motivo_decision' => 'No hay cupo en primero este año.'])->assertStatus(200);

        $r = $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123456')->assertStatus(200);
        $this->assertSame('NO_ADMITIDO', $r->json('estado_embudo'));
        $this->assertSame('No hay cupo en primero este año.', $r->json('motivo_decision'));

        // Y sin el segundo factor, ni el motivo ni el estado.
        $this->assertStringNotContainsString('cupo', json_encode($this->getJson(self::PORTAL.'/'.$codigo)->json()));
    }

    /**
     * **La observación de una cita reservada no viaja a cualquiera del personal.** La ven
     * quien la escribió, Orientación y los directivos; los demás ven que la cita existe y
     * que tiene algo guardado, sin el texto. Y moverle la hora sin poder leerla no la borra.
     */
    public function test_la_observacion_reservada_solo_la_leen_quienes_pueden(): void
    {
        [$aspirante] = $this->unAspiranteConDocumento();

        // El llano, sin ningún rol que lo deje leer.
        $llano = DB::selectOne('SELECT id FROM users WHERE id=(SELECT tokenable_id FROM personal_access_tokens
            WHERE id=?)', [(int) explode('|', $this->tokenLlano())[0]]);
        DB::delete('DELETE FROM role_user WHERE user_id=?', [(int) $llano->id]);

        $this->withToken($this->tokenAdmin())->putJson(self::ASPIRANTES.'/'.$aspirante.'/cita', [
            'tipo' => 'entrevista', 'observacion' => 'Duelo reciente en la familia.', 'reservada' => true,
        ])->assertStatus(200);

        $delLlano = $this->withToken($this->tokenLlano())->getJson(self::ASPIRANTES.'/'.$aspirante)->assertStatus(200);
        $this->assertCount(1, $delLlano->json('citas'), 'La cita reservada tiene que seguir contando.');
        $this->assertNull($delLlano->json('citas.0.observacion'));
        $this->assertTrue($delLlano->json('citas.0.observacion_oculta'));
        $this->assertStringNotContainsString('Duelo', json_encode($delLlano->json()));

        $delAdmin = $this->withToken($this->tokenAdmin())->getJson(self::ASPIRANTES.'/'.$aspirante)->assertStatus(200);
        $this->assertSame('Duelo reciente en la familia.', $delAdmin->json('citas.0.observacion'));
        $this->assertFalse($delAdmin->json('citas.0.observacion_oculta'));

        // El llano mueve la hora con la observación que vio —ninguna—: no la borra ni la desmarca.
        $this->withToken($this->tokenLlano())->putJson(self::ASPIRANTES.'/'.$aspirante.'/cita', [
            'tipo' => 'entrevista', 'cuando' => '2026-10-02 09:30:00', 'observacion' => null, 'reservada' => false,
        ])->assertStatus(200);

        $fila = DB::selectOne('SELECT observacion, reservada, cuando FROM citas_admision WHERE aspirante_id=?', [$aspirante]);
        $this->assertSame('Duelo reciente en la familia.', $fila->observacion, 'Mover la hora borró lo que escribió Orientación.');
        $this->assertSame(1, (int) $fila->reservada);
        $this->assertStringStartsWith('2026-10-02 09:30', (string) $fila->cuando);
    }

    /** Sin pagar no se llena: es el pecado que la prematrícula pública comete hoy. */
    public function test_un_formulario_sin_pagar_no_se_puede_llenar(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->putJson(self::PORTAL.'/'.$codigo, ['nombres' => 'Laura'])->assertStatus(409);

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM aspirantes')->c,
            'Se contestó 409 y aun así escribió.');
    }

    /** Un código con el carácter de control malo ni toca la base. */
    public function test_un_codigo_mal_escrito_da_422_y_no_404(): void
    {
        $this->getJson(self::PORTAL.'/2027-AAAAAA')->assertStatus(422);
    }

    /**
     * **Un documento pendiente por requisito**, que es el tope que no se reinicia con
     * el reloj. Un limitador protege la base; lo que protege el disco es la cuenta por
     * fila.
     */
    public function test_no_se_puede_mandar_otro_documento_con_uno_sin_revisar(): void
    {
        $codigo = $this->unaOrdenPagada();
        $requisito = $this->unPaso('Documentos', 1);

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'documento' => '1090123456'])->assertStatus(200);

        $this->postJson(self::PORTAL.'/'.$codigo.'/documento/'.$requisito,
            ['documento' => '1090123456', 'en_papel' => true])->assertStatus(200);

        $this->postJson(self::PORTAL.'/'.$codigo.'/documento/'.$requisito,
            ['documento' => '1090123456', 'en_papel' => true])->assertStatus(409);

        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM documentos_admision')->c);
    }

    // ==================================================================
    // 6 · La bandeja del colegio
    // ==================================================================

    /** Devolver un documento sin motivo no se puede: lo lee la familia. */
    public function test_devolver_un_documento_sin_motivo_se_rechaza(): void
    {
        [$aspirante, $documento] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenLlano())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/documento/'.$documento,
                ['estado' => 'DEVUELTO'])
            ->assertStatus(422);

        $this->assertSame('PAPEL', DB::selectOne('SELECT estado FROM documentos_admision WHERE id=?',
            [$documento])->estado, 'Se rechazó con 422 y aun así escribió.');
    }

    /** Y el motivo llega al portal, que es para lo que se escribe. */
    public function test_el_motivo_de_la_devolucion_llega_al_portal(): void
    {
        [$aspirante, $documento, $codigo] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenLlano())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/documento/'.$documento,
                ['estado' => 'DEVUELTO', 'motivo_devolucion' => 'La foto está borrosa'])
            ->assertStatus(200);

        $r = $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123456')->assertStatus(200);

        $this->assertSame('DEVUELTO', $r->json('documentos.0.estado'));
        $this->assertSame('La foto está borrosa', $r->json('documentos.0.motivo_devolucion'));
    }

    /**
     * **La decisión de admisión es la única con el permiso dentro.**
     *
     * `auth.personal` deja pasar a las 75 cuentas de personal, de las que 53 son
     * docentes. Admitir no es un paso reversible: es la respuesta del colegio a una
     * familia, y viaja al portal en cuanto se escribe.
     */
    public function test_un_docente_llano_no_puede_decidir_una_admision(): void
    {
        [$aspirante] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenLlano())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/decision', ['decision' => 'ADMITIDO'])
            ->assertStatus(403);

        $this->withToken($this->tokenAdmin())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/decision', ['decision' => 'ADMITIDO'])
            ->assertStatus(200);

        $fila = DB::selectOne('SELECT estado_embudo, decidido_por, decidido_at FROM aspirantes WHERE id=?',
            [$aspirante]);

        $this->assertSame('ADMITIDO', $fila->estado_embudo);
        $this->assertNotNull($fila->decidido_por, 'La decisión tiene que quedar con su nombre.');
        $this->assertNotNull($fila->decidido_at, 'La decisión tiene que quedar con su hora.');
    }

    /**
     * **El coordinador académico admite, y NO es superusuario.**
     *
     * Decisión de Joseth del 20 sep 2026: *«el coordinador académico puede admitir
     * estudiantes también.»* Es la mitad que el test de arriba no puede demostrar —
     * aquél enseña que un docente llano no pasa y que un superusuario sí, y con los dos
     * verdes `puedeDecidirAdmision` podría seguir siendo `is_superuser` a secas.
     *
     * **El sujeto es el mismo `Usuario` llano que acaba de recibir un 403**, y lo único
     * que cambia entre las dos llamadas es la fila de `role_user`. Por eso el 200 no
     * puede venir de otro sitio.
     *
     * > **Lo que este verde NO dice**, con su medición al lado: en la base de tests el
     * > rol `Coord académico` tiene **cero titulares** —lo fija `LoQueDecideUnRolTest`—
     * > así que aquí se fabrica. En la copia de desarrollo tiene **uno, y no es
     * > superusuario** (contado por `role_id` el 20 sep 2026: 12 personas admitían, 13
     * > con esto). O sea que la regla nace viva, pero eso lo dice aquella medición y no
     * > este test.
     */
    public function test_el_coordinador_academico_admite_y_no_es_superusuario(): void
    {
        [$aspirante] = $this->unAspiranteConDocumento();

        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto tiene que ser llano: con un superusuario esto pasaría por la otra rama.');

        $this->withToken($token)
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/decision', ['decision' => 'ADMITIDO'])
            ->assertStatus(403);

        $rol = DB::selectOne("SELECT id FROM roles WHERE name = 'Coord académico' AND deleted_at IS NULL");

        $this->assertNotNull($rol,
            "No está el rol `Coord académico` en la base de tests. Lo primero que hay que\n"
            .'mirar es la CADENA: lleva tilde, y un literal sin ella no casa con nada y no '
            .'falla nada — es la trampa del 33.');

        DB::insert('INSERT INTO role_user (role_id, user_id) VALUES (?, ?)', [$rol->id, $usuario->id]);

        $this->withToken($token)
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/decision', ['decision' => 'ADMITIDO'])
            ->assertStatus(200);

        $fila = DB::selectOne('SELECT estado_embudo, decidido_por FROM aspirantes WHERE id=?', [$aspirante]);

        $this->assertSame('ADMITIDO', $fila->estado_embudo);
        $this->assertSame((int) $usuario->id, (int) $fila->decidido_por,
            'La decisión queda con el nombre del coordinador, no con el de nadie más.');
    }

    /**
     * **Y `Rector` sigue fuera, que es la otra mitad de la decisión.**
     *
     * Joseth nombró **un** rol el 20 sep. `Rector` no cambiaría hoy nada medible —cero
     * titulares en la copia de desarrollo y cero en el seed— y por eso es justo el que
     * se colaría sin que nadie lo notara: *lo que nadie pidió no se concede de paso*.
     *
     * Este test es el que se pondrá rojo el día que se meta, y entonces hay que venir
     * aquí a borrarlo **con la frase de Joseth delante**, no a hacerlo pasar.
     */
    public function test_el_rector_no_admite_mientras_nadie_lo_pida(): void
    {
        [$aspirante] = $this->unAspiranteConDocumento();

        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $rol = DB::selectOne("SELECT id FROM roles WHERE name = 'Rector' AND deleted_at IS NULL");

        $this->assertNotNull($rol, 'El rol `Rector` es de 2018 y viene dentro del seed.');

        DB::insert('INSERT INTO role_user (role_id, user_id) VALUES (?, ?)', [$rol->id, $usuario->id]);

        $this->withToken($token)
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/decision', ['decision' => 'ADMITIDO'])
            ->assertStatus(403);
    }

    /** No admitir sin motivo tampoco: la familia recibe un no y nadie sabe por qué. */
    public function test_no_admitir_sin_motivo_se_rechaza(): void
    {
        [$aspirante] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenAdmin())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/decision', ['decision' => 'NO_ADMITIDO'])
            ->assertStatus(422);
    }

    /**
     * La cita es **una fila por tipo**: agendarla y resolverla escriben la misma.
     *
     * Es la razón de que sean cinco rutas y no seis — ver el docblock de `putCita`.
     */
    public function test_agendar_y_resolver_una_cita_es_la_misma_fila(): void
    {
        [$aspirante] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenLlano())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/cita',
                ['tipo' => 'entrevista', 'cuando' => '2026-10-01 09:00:00', 'donde' => 'Aula 102'])
            ->assertStatus(200);

        $this->withToken($this->tokenLlano())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/cita',
                ['tipo' => 'entrevista', 'resultado' => 'CON_COMPROMISO',
                    'observacion' => 'Compromiso académico en matemáticas'])
            ->assertStatus(200);

        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM citas_admision
            WHERE aspirante_id=? AND deleted_at IS NULL', [$aspirante])->c,
            'Resolver una cita creó una segunda fila en vez de escribir la suya.');

        $this->assertSame('CON_COMPROMISO', DB::selectOne('SELECT resultado FROM citas_admision
            WHERE aspirante_id=?', [$aspirante])->resultado);
    }

    /** Una cita reservada de Orientación no viaja al portal. */
    public function test_una_cita_reservada_no_viaja_al_portal(): void
    {
        [$aspirante, , $codigo] = $this->unAspiranteConDocumento();

        $this->withToken($this->tokenLlano())
            ->putJson(self::ASPIRANTES.'/'.$aspirante.'/cita',
                ['tipo' => 'entrevista', 'reservada' => true,
                    'observacion' => 'situación familiar delicada'])
            ->assertStatus(200);

        $r = $this->getJson(self::PORTAL.'/'.$codigo.'?documento=1090123456')->assertStatus(200);

        $this->assertSame([], $r->json('citas'),
            'Lo que Orientación marca como reservado se enseñó en el portal de la familia.');
    }

    /** La bandeja sí pide personal: no es pública. */
    public function test_la_bandeja_pide_token_de_personal(): void
    {
        $this->getJson(self::ASPIRANTES)->assertStatus(401);
        $this->withToken($this->tokenDeUnAlumno())->getJson(self::ASPIRANTES)->assertStatus(403);
    }

    // ==================================================================

    /** @return array{0:int,1:int} */
    private function dosAlumnos(): array
    {
        $alumnos = DB::select('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id=a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM","PREA")
            INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL AND g.year_id=?
            WHERE a.deleted_at IS NULL
            GROUP BY a.id ORDER BY a.id LIMIT 2', [$this->yearActual()]);

        $this->assertCount(2, $alumnos, 'El seed no tiene dos alumnos del año actual.');

        return [(int) $alumnos[0]->id, (int) $alumnos[1]->id];
    }

    /** @return array{alumno_id:int, username:string} un alumno con su propia cuenta */
    private function unAlumnoConAcudiente(): array
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id=a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM","PREA")
            INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at IS NULL AND g.year_id=?
            WHERE a.user_id=? AND a.deleted_at IS NULL LIMIT 1',
            [$this->yearActual(), $usuario->id]);

        $this->assertNotNull($alumno, 'El seed necesita un alumno con cuenta y matrícula viva.');

        return ['alumno_id' => (int) $alumno->id, 'username' => $usuario->username];
    }

    /** Crea un paso del recorrido **por la ruta**, y devuelve su id. */
    private function unPaso(string $nombre, int $orden): int
    {
        $r = $this->withToken($this->tokenLlano())->postJson('/api/requisitos/store', [
            'year_id' => $this->yearActual(),
            'requisito' => $nombre,
            'descripcion' => '',
            'orden' => $orden,
            'bloquea' => true,
        ])->assertStatus(200);

        return (int) $r->json('requisito.id');
    }

    /**
     * Una fila de `requisitos_alumno` con el estado que se pida —incluido el blanco,
     * que es el caso que el fallo necesitaba y que ninguna ruta puede escribir ya.
     */
    private function unaMarcaConEstado(int $alumnoId, string $requisito, string $estado): int
    {
        $requisitoId = DB::selectOne('SELECT id FROM requisitos_matricula
            WHERE requisito=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$requisito]);

        $this->assertNotNull($requisitoId, "No existe el requisito «{$requisito}».");

        // `INSERT` directo y no por la ruta: hace falta poder dejar el estado **en
        // blanco**, que es lo que dejó el `UPDATE` roto que estuvo vivo en los
        // dieciséis colegios hasta el 1 sep 2026 — y que ninguna ruta puede escribir
        // ya, precisamente por el arreglo que este fichero comprueba.
        DB::insert('INSERT INTO requisitos_alumno (alumno_id, requisito_id, estado, created_at, updated_at)
            VALUES (?,?,?,NOW(),NOW())', [$alumnoId, $requisitoId->id, $estado]);

        return (int) DB::getPdo()->lastInsertId();
    }

    private function unCodigoAcunado(): string
    {
        return $this->withToken($this->tokenLlano())
            ->postJson('/api/informes/formularios-inscripcion', ['modo' => 'nuevos', 'cantidad' => 1])
            ->assertStatus(200)->json('formularios.0.codigo');
    }

    /** Un formulario acuñado y cobrado, que es lo que abre el portal. */
    private function unaOrdenPagada(): string
    {
        $codigo = $this->unCodigoAcunado();

        DB::update('UPDATE ordenes_inscripcion SET estado="PAGADA" WHERE codigo=?', [$codigo]);

        return $codigo;
    }

    /** @return array{0:int,1:int,2:string} aspirante, documento y su código */
    private function unAspiranteConDocumento(): array
    {
        $codigo = $this->unaOrdenPagada();
        $requisito = $this->unPaso('Documentos', 1);

        $this->putJson(self::PORTAL.'/'.$codigo,
            ['nombres' => 'Laura', 'documento' => '1090123456'])->assertStatus(200);

        $this->postJson(self::PORTAL.'/'.$codigo.'/documento/'.$requisito,
            ['documento' => '1090123456', 'en_papel' => true])->assertStatus(200);

        $aspirante = DB::selectOne('SELECT id FROM aspirantes ORDER BY id DESC LIMIT 1');
        $documento = DB::selectOne('SELECT id FROM documentos_admision ORDER BY id DESC LIMIT 1');

        return [(int) $aspirante->id, (int) $documento->id, $codigo];
    }

    /**
     * El publicador falso, montado en el contenedor.
     *
     * **Va en un método y no en línea**, y no es estilo: larastan estrecha el tipo de
     * `$mandados` a `array{}` en cuanto ve el `new` en el mismo método —las escrituras
     * ocurren dentro de `publicar()`, que no alcanza a ver—, y entonces da por vacío el
     * filtro de después. Devolviéndolo desde aquí lee el tipo declarado. Es el mismo
     * reparto que hace `EnviarNotificacionesTest`, que lo monta en su `setUp`.
     */
    private function unPublicadorDeMentira(): PublicadorDeMentira
    {
        $publicador = new PublicadorDeMentira;
        $this->app->instance(Publicador::class, $publicador);

        return $publicador;
    }

    /**
     * Los avisos que se mandaron a un tema.
     *
     * **Va en un método y no en línea por larastan**, y el porqué es de los que hay que
     * dejar escritos: comparar la propiedad contra `[]` **le estrecha el tipo a
     * `array{}` para el resto del método**, así que el filtro de después salía marcado
     * como «llamada sin efecto» sobre un array vacío. *Una aserción puede cambiar lo
     * que el analizador cree saber, y entonces el aviso no habla del código: habla de
     * la aserción de dos líneas antes.*
     *
     * @return list<array<string, mixed>>
     */
    private function avisosAlTema(PublicadorDeMentira $publicador, string $tema): array
    {
        return array_values(array_filter($publicador->mandados, fn ($m) => $m['tema'] === $tema));
    }

    /**
     * Una pasada del cron, exigiendo que salga con 0.
     *
     * Se envuelve por lo mismo que en `EnviarNotificacionesTest`: `artisan()` devuelve
     * `PendingCommand|int` y larastan nivel 7 no deja llamar `assertExitCode()` sobre
     * esa unión.
     */
    private function correrElCron(): void
    {
        $resultado = $this->artisan('notificaciones:enviar');
        $codigo = $resultado instanceof PendingCommand ? $resultado->run() : $resultado;

        $this->assertSame(0, $codigo, 'El comando salió con código '.$codigo.'.');
    }

    private function yearActual(): int
    {
        return (int) DB::selectOne('SELECT id FROM years WHERE actual=1 AND deleted_at IS NULL')->id;
    }

    private function tokenLlano(): string
    {
        return $this->token ??= $this->tokenDelPersonalLlano();
    }

    private function tokenAdmin(): string
    {
        if ($this->tokenAdmin !== null) {
            return $this->tokenAdmin;
        }

        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser=1 AND deleted_at IS NULL AND is_active=1 LIMIT 1');

        $this->assertNotNull($super, 'El seed no tiene superusuarios: esto no mediría nada.');

        return $this->tokenAdmin = $this->tokenDe($super->username);
    }

    private function tokenDeUnAlumno(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Alumno')->username);
    }
}
