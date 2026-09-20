<?php

namespace Tests\Contrato;

use App\Services\CodigoDeInscripcion;
use Illuminate\Support\Facades\DB;

/**
 * **`GET colillas-inscripcion/{codigo}` — la familia pregunta cómo va lo suyo.**
 *
 * La decimosexta ruta pública, y **la primera de LECTURA de todo este módulo**.
 * Hasta el 20 sep 2026 las tres públicas del formulario eran las tres de escritura,
 * así que la familia mandaba su comprobante y **no tenía forma de saber si se lo
 * aprobaron, se lo rechazaron ni por qué**.
 *
 * Lo que este fichero defiende son dos cosas, y la segunda pesa más que la primera:
 *
 *   1. **Que conteste lo que la familia necesita** — el estado, el motivo del
 *      rechazo, y si puede mandar otro comprobante.
 *   2. **Que NO conteste nada más.** Es pública, la llave es un código que se dicta
 *      por teléfono y viaja en un papel que pasa de mano en mano, así que la
 *      pregunta de cada campo no es «¿le sirve?» sino **«¿qué pasa si esto lo lee
 *      quien se encontró el papel?»**. Un código no puede revelar el nombre de un
 *      menor.
 *
 * Por eso aquí hay más aserciones de lo que **no** sale que de lo que sale: un campo
 * de más en una respuesta pública no rompe nada, no pone nada en rojo y no se nota
 * hasta que importa.
 */
class LaFamiliaPreguntaTest extends CasoDeContrato
{
    private const RUTA = '/api/colillas-inscripcion';

    private ?string $token = null;

    // ─────────────────────────────────────────────────────────────────────────
    // 1 · Lo que contesta
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sin_token_la_familia_ve_como_va_su_formulario(): void
    {
        $codigo = $this->unCodigoAcunado();

        $r = $this->getJson(self::RUTA.'/'.$codigo);

        $r->assertStatus(200);
        $this->assertSame($codigo, $r->json('codigo'));
        $this->assertSame('IMPRESA', $r->json('estado'));
        $this->assertFalse($r->json('pagado'));
        $this->assertSame([], $r->json('comprobantes'));
        $this->assertTrue($r->json('puede_enviar_otro'));
        $this->assertSame(3, $r->json('comprobantes_restantes'));
    }

    /**
     * **El motivo del rechazo llega a quien tiene que corregirlo.**
     *
     * Es la razón de que esta ruta exista: `putRechazar` exige un motivo desde el 19
     * sep —sin texto no se puede rechazar— y hasta hoy **sólo lo veía el personal**.
     * La familia sabía que algo pasaba y no qué.
     */
    public function test_el_motivo_del_rechazo_llega_a_la_familia(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-100'])->assertStatus(200);

        $colilla = DB::selectOne('SELECT id FROM colillas_inscripcion ORDER BY id DESC');

        $this->withToken($this->tokenDelTesorero())
            ->putJson(self::RUTA.'/'.$colilla->id.'/rechazar',
                ['motivo' => 'La foto está borrosa, no se lee el valor.'])
            ->assertStatus(200);

        $r = $this->getJson(self::RUTA.'/'.$codigo)->assertStatus(200);

        $this->assertSame('RECHAZADA', $r->json('comprobantes.0.estado'));
        $this->assertSame('La foto está borrosa, no se lee el valor.', $r->json('comprobantes.0.motivo'),
            'El motivo no llega a la familia, así que no sabe qué corregir y volverá a mandar lo mismo.');

        $this->assertTrue($r->json('puede_enviar_otro'),
            'Le rechazaron el comprobante y la ruta no le dice que puede mandar otro.');
    }

    /**
     * En una aprobación no hay nada que corregir, y el texto del tesorero es suyo.
     */
    public function test_el_motivo_de_una_aprobada_no_viaja(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-101'])->assertStatus(200);

        $colilla = DB::selectOne('SELECT id FROM colillas_inscripcion ORDER BY id DESC');

        $this->withToken($this->tokenDelTesorero())
            ->putJson(self::RUTA.'/'.$colilla->id.'/aprobar', ['motivo' => 'nota interna del tesorero'])
            ->assertStatus(200);

        $r = $this->getJson(self::RUTA.'/'.$codigo)->assertStatus(200);

        $this->assertSame('APROBADA', $r->json('comprobantes.0.estado'));
        $this->assertNull($r->json('comprobantes.0.motivo'),
            'Un texto interno pegado a un «aprobado» es información que nadie decidió enseñar.');

        $this->assertTrue($r->json('pagado'));
        $this->assertSame('PAGADA', $r->json('estado'));
    }

    /**
     * **Las dos condiciones que `postSubir` comprueba, dichas ANTES de subir.**
     *
     * Sin esto la familia se entera con un 429 después de elegir la foto, que es el
     * peor momento para enterarse.
     */
    public function test_con_uno_pendiente_dice_que_no_puede_enviar_otro(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-102'])->assertStatus(200);

        $r = $this->getJson(self::RUTA.'/'.$codigo)->assertStatus(200);

        $this->assertSame('PENDIENTE', $r->json('comprobantes.0.estado'));
        $this->assertFalse($r->json('puede_enviar_otro'),
            'Dice que puede mandar otro y `postSubir` le va a contestar 429.');

        // Y la ruta de subir tiene que estar de acuerdo, o esto miente.
        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-103'])->assertStatus(429);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 · Lo que NO contesta — pesa más, porque un campo de más no pone nada en rojo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **Un código no puede revelar el nombre de un menor.**
     *
     * El formulario está atado a un alumno de verdad y la respuesta **sigue sin
     * decir quién es**. Se comprueba sobre el JSON entero y no campo a campo: lo que
     * hay que impedir es que el dato aparezca, esté donde esté.
     */
    public function test_un_codigo_no_revela_de_quien_es_el_formulario(): void
    {
        $codigo = $this->unCodigoAcunado();
        $alumno = DB::selectOne('SELECT a.id, a.nombres, a.apellidos, a.documento, a.celular
            FROM alumnos a WHERE a.deleted_at IS NULL AND a.documento IS NOT NULL
              AND a.documento <> "" ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene alumnos con documento: esto no mediría nada.');

        $this->withToken($this->tokenQuePuedeAtar())
            ->putJson('/api/informes/formularios-inscripcion/codigo/'.$codigo.'/alumno',
                ['alumno_id' => $alumno->id])->assertStatus(200);

        $cuerpo = $this->getJson(self::RUTA.'/'.$codigo)->assertStatus(200)->getContent();

        foreach (['nombres' => $alumno->nombres, 'apellidos' => $alumno->apellidos,
            'documento' => $alumno->documento] as $que => $valor) {
            $this->assertStringNotContainsString((string) $valor, $cuerpo,
                "La respuesta pública lleva el/la {$que} del alumno. Quien se encuentre el papel "
                .'sabe de quién es el formulario.');
        }

        $this->assertArrayNotHasKey('alumno', $this->getJson(self::RUTA.'/'.$codigo)->json());
    }

    /**
     * **El fichero del recibo no viaja: la URL es la llave** (41 §5).
     *
     * Saber un código —que la familia dicta por teléfono— no puede dar el recibo que
     * subió otro. Por eso el nombre del fichero es aleatorio, y por eso tampoco sale
     * de aquí.
     */
    public function test_no_sale_el_fichero_del_recibo_ni_quien_lo_resolvio(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-104'])->assertStatus(200);

        $colilla = DB::selectOne('SELECT id FROM colillas_inscripcion ORDER BY id DESC');

        $this->withToken($this->tokenDelTesorero())
            ->putJson(self::RUTA.'/'.$colilla->id.'/aprobar')->assertStatus(200);

        $fila = $this->getJson(self::RUTA.'/'.$codigo)->assertStatus(200)->json('comprobantes.0');

        foreach (['archivo', 'tipo', 'bytes', 'subida_ip', 'resuelta_por', 'referencia', 'id'] as $prohibido) {
            $this->assertArrayNotHasKey($prohibido, $fila,
                "«{$prohibido}» viaja en una respuesta pública y nadie decidió enseñarlo.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 · El agujero que esto cerró de camino, y que era de ayer
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **EL PAPEL VIEJO SIGUE SIRVIENDO PARA PAGAR.**
     *
     * Al entrar la corrección de códigos el 20 sep, sólo la ruta del personal
     * aprendió a buscar por `codigo_anterior`. **Las dos públicas —las que usa la
     * familia— seguían con `WHERE codigo=?`**, así que corregir un código dejaba a
     * la familia sin poder subir su comprobante: el papel que tiene en la mano lleva
     * el viejo, y recibía «No encontramos ese formulario».
     *
     * Lo peor era que **es silencioso para las dos partes**: secretaría corrige
     * creyendo que es inocuo, y la familia se estrella contra un 404 que no tiene a
     * quién reportar, porque no tiene cuenta.
     */
    public function test_tras_corregir_el_codigo_el_papel_viejo_sigue_sirviendo(): void
    {
        $viejo = $this->unCodigoAcunado();

        $nuevo = $this->withToken($this->tokenQuePuedeAtar())
            ->putJson('/api/informes/formularios-inscripcion/codigo/'.$viejo, ['sufijo' => 'ABCDE'])
            ->assertStatus(200)->json('codigo');

        $this->assertNotSame($viejo, $nuevo);

        // 1 · Preguntar con el papel viejo funciona, Y DICE CUÁL ES EL BUENO.
        $r = $this->getJson(self::RUTA.'/'.$viejo)->assertStatus(200);
        $this->assertSame($nuevo, $r->json('codigo'));
        $this->assertSame('codigo_anterior', $r->json('encontrado_por'),
            'No avisa de que ese papel lleva el código viejo, así que nadie se lo va a decir.');

        // 2 · Y SUBIR el comprobante con el papel viejo también, que es lo que
        //     estaba roto.
        $this->postJson(self::RUTA.'/'.$viejo, ['referencia' => 'REC-105'])->assertStatus(200);

        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM colillas_inscripcion
            WHERE orden_id=(SELECT id FROM ordenes_inscripcion WHERE codigo=?)', [$nuevo])->c,
            'El comprobante mandado con el papel viejo no llegó a la orden buena.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 · El router, que se vio roto
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **`{codigo}` es un comodín y se traga `pendientes` si va delante.**
     *
     * Esto se vio ROTO: registrada antes, `GET colillas-inscripcion/pendientes`
     * —**la bandeja del tesorero**— entraba por `getEstado` y contestaba 422 «ese
     * código no es válido». Y `route:list` **no lo enseña**, porque ordena
     * alfabéticamente y no por orden de registro, así que la forma natural de
     * comprobarlo miente.
     *
     * Es la misma trampa que `…/campos` antes que `…/{lote}`, cometida otra vez en
     * la familia de al lado y al día siguiente. *Un aviso escrito no protege solo.*
     */
    public function test_pendientes_no_se_la_traga_el_comodin_del_codigo(): void
    {
        // Sin token: si la atiende `getPendientes` da 401 (la ruta exige sesión); si
        // se la tragara `getEstado` daría 422, porque «pendientes» no es un código.
        $this->getJson(self::RUTA.'/pendientes')->assertStatus(401);

        // Y con token del tesorero tiene que contestar la bandeja de verdad.
        $this->withToken($this->tokenDelTesorero())
            ->getJson(self::RUTA.'/pendientes')->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5 · Códigos que no son
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_codigo_sin_forma_es_422_y_uno_que_no_existe_es_404(): void
    {
        $this->getJson(self::RUTA.'/no-es-un-codigo')->assertStatus(422);
        $this->getJson(self::RUTA.'/'.CodigoDeInscripcion::componer(2026, 'ZZZZZ'))->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6 · PREGUNTAR NO PUEDE GASTAR SUBIDAS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * **Refrescar «¿ya me aprobaron?» no puede dejar a la familia sin poder subir
     * el recibo.**
     *
     * Lo encontró `8myvc-dd` revisando esta ruta, y no es un número mal puesto: es
     * cómo Laravel construye la clave de un limitador con nombre.
     * `ThrottleRequests::handleRequestUsingNamedLimiter` hace
     *
     *     'key' => md5($limiterName.$limit->key)
     *
     * **sin el verbo y sin la ruta.** Como el `GET` y el `POST` comparten
     * `throttle:colilla` y los mismos `by('ip:…')` y `by('cod:…')`, **son un solo
     * cubo de diez por hora para las dos**.
     *
     * Y el reparto sale justo al revés de lo que conviene: **preguntar es la acción
     * barata que una familia repite** —refrescando— y **subir es la cara y rara**.
     *
     * ## Lo que lo hace peor que un tope estrecho: rompe la promesa de esta ruta
     *
     * `puede_enviar_otro` diría **`true`** —y sería cierto, el tope de tres por orden
     * está libre— y el `POST` rebotaría igual, con el **429 genérico de Laravel** en
     * vez del mensaje que explica qué pasa. **La familia lee «puede mandar otro» y
     * recibe «demasiados intentos».**
     */
    public function test_preguntar_muchas_veces_no_gasta_las_subidas(): void
    {
        $codigo = $this->unCodigoAcunado();

        // Once consultas: una familia refrescando. El tope de `colilla` son diez.
        for ($i = 0; $i < 11; $i++) {
            $this->getJson(self::RUTA.'/'.$codigo)->assertStatus(200);
        }

        // Y ahora sube su recibo POR PRIMERA VEZ.
        $r = $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-200']);

        $this->assertSame(200, $r->status(),
            'Preguntar gastó el saldo de subir: la familia refrescó la pantalla y ahora no puede '
            .'mandar su comprobante. El limitador de la lectura y el de la escritura son el mismo '
            .'cubo, porque la clave de un limitador con nombre no lleva el verbo ni la ruta.');

        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM colillas_inscripcion')->c);
    }

    /**
     * Y el contrario, que es la mitad que no se puede perder al arreglarlo: **el
     * limitador de SUBIR sigue en diez por hora**. Aflojar el de la lectura no puede
     * aflojar el de la escritura, que es el que protege el disco.
     *
     * ## Este test estuvo escrito de forma que NO medía eso, y se vio
     *
     * La primera versión subía dos comprobantes a la **misma orden** y esperaba 429
     * en el segundo. Eso pasa siempre — pero **por el tope de «una pendiente por
     * orden», no por el limitador** —, así que pasaba **también con el `POST` mal
     * configurado**: durante un control lo dejé apuntando por error al limitador de
     * la lectura, de sesenta, y este test siguió en verde.
     *
     * Es el detector contando bien un síntoma **sin estar contando la causa**. Para
     * medir el limitador de verdad hay que gastarlo **por IP**, que es lo único que
     * el tope por orden no tapa: once órdenes distintas, una subida en cada una.
     */
    public function test_subir_sigue_topado_en_diez_por_hora(): void
    {
        // Once códigos distintos: así el tope de «una pendiente por orden» nunca
        // entra en juego y lo único que puede frenar es el limitador por IP.
        $codigos = array_column($this->withToken($this->tokenLlano())
            ->postJson('/api/informes/formularios-inscripcion', ['modo' => 'nuevos', 'cantidad' => 11])
            ->assertStatus(200)->json('formularios'), 'codigo');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::RUTA.'/'.$codigos[$i], ['referencia' => 'REC-3'.$i])
                ->assertStatus(200);
        }

        $this->postJson(self::RUTA.'/'.$codigos[10], ['referencia' => 'REC-310'])
            ->assertStatus(429);

        $this->assertSame(10, (int) DB::selectOne('SELECT COUNT(*) c FROM colillas_inscripcion')->c,
            'El limitador de subir dejó pasar más de diez por hora desde la misma IP.');
    }

    /** Y el tope por orden, que es el que de verdad protege el disco, sigue en pie. */
    public function test_una_sola_pendiente_por_orden(): void
    {
        $codigo = $this->unCodigoAcunado();

        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-201'])->assertStatus(200);
        $this->postJson(self::RUTA.'/'.$codigo, ['referencia' => 'REC-202'])->assertStatus(429);

        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM colillas_inscripcion')->c);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function unCodigoAcunado(): string
    {
        return $this->withToken($this->tokenLlano())
            ->postJson('/api/informes/formularios-inscripcion', ['modo' => 'nuevos', 'cantidad' => 1])
            ->assertStatus(200)->json('formularios.0.codigo');
    }

    private function tokenLlano(): string
    {
        return $this->token ??= $this->tokenDelPersonalLlano();
    }

    /**
     * Superusuario, por lo mismo que en `ColillasInscripcionTest`: `esAdministrativo`
     * es la mitad de `puedeResolverColillas` que SIEMPRE existe —`years.tesorero_id`
     * está en NULL en los cuatro años del seed— y es también `puedeAtarFormularios`.
     */
    private function tokenDelTesorero(): string
    {
        return $this->tokenQuePuedeAtar();
    }

    private ?string $tokenAdmin = null;

    private function tokenQuePuedeAtar(): string
    {
        if ($this->tokenAdmin !== null) {
            return $this->tokenAdmin;
        }

        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser=1 AND deleted_at IS NULL AND is_active=1 LIMIT 1');

        $this->assertNotNull($super, 'El seed no tiene superusuarios: esto no mediría nada.');

        return $this->tokenAdmin = $this->tokenDe($super->username);
    }
}
