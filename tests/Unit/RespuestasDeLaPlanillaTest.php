<?php

namespace Tests\Unit;

use App\Services\RespuestasDeLaPlanilla;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo que el docente decidió sobre el libro que sube.
 *
 * ## Qué se comprueba aquí, que no es «devuelve lo que le meto»
 *
 * Esta clase no calcula nada: traduce un JSON a decisiones. Lo que puede fallar
 * en ella —y lo que rompería la fase 2 entera— son **tres propiedades**, y son las
 * que están escritas abajo:
 *
 * 1. **Que la llave sea el VALOR y no la celda.** Una hoja con doce `4,5` tiene
 *    que ser una pregunta y no doce. Si esto se rompiera, la pantalla seguiría
 *    funcionando y sería inusable, que es la peor forma de romperse.
 * 2. **Que el defecto de cada familia no pierda trabajo.** Sin decisión, una celda
 *    rara **no se escribe**, una nota fuera de escala **no se escribe** y en un
 *    choque **manda el sistema**. Los tres defectos son «no tocar», que es la única
 *    elección que se deshace volviendo a subir el archivo.
 * 3. **Que una decisión a medias no se aplique a medias.** Un `interpretar` sin
 *    número o un `crear` sin nombre tienen que caer al defecto, no inventarse un
 *    cero ni un indicador llamado `""`.
 *
 * No hereda de `Tests\TestCase`: aquí no hay base de datos, ni `APP_KEY`, ni
 * petición. Es la misma razón por la que `FirmaDelLibroTest` sí hereda —aquélla
 * necesita la clave— y ésta no.
 */
class RespuestasDeLaPlanillaTest extends TestCase
{
    // ── La llave es el valor ─────────────────────────────────────────────────

    #[Test]
    public function una_decision_sobre_un_valor_vale_para_todas_sus_celdas(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'celdas' => [
                ['valor' => '4,5', 'decision' => 'interpretar:4'],
            ],
        ]);

        // La misma decisión contestada una vez sirve para las doce celdas donde
        // aparezca ese valor. **Es el punto de todo el módulo**, no un detalle de
        // implementación: con la celda como llave habría doce preguntas.
        $this->assertSame(
            ['decision' => 'interpretar', 'nota' => 4],
            $respuestas->queHacerConLaCelda('4,5')
        );
    }

    #[Test]
    public function el_valor_se_compara_como_texto_y_sin_espacios(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'celdas' => [['valor' => 4.5, 'decision' => 'interpretar:5']],
        ]);

        // Lo que llega del `.xlsx` puede ser `int`, `float` o `string` según por
        // dónde vino, y un `" 4.5"` y un `4.5` son el mismo problema para quien lo
        // mira. Tener que decidirlos por separado es exactamente lo que esto evita.
        $this->assertSame(5, $respuestas->queHacerConLaCelda(' 4.5')['nota']);
    }

    // ── Los defectos, que son todos «no tocar» ───────────────────────────────

    #[Test]
    public function sin_decision_una_celda_rara_no_se_escribe(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([]);

        $this->assertSame('dejar', $respuestas->queHacerConLaCelda('N/A')['decision']);
        $this->assertNull($respuestas->queHacerConLaCelda('N/A')['nota']);
    }

    #[Test]
    public function sin_decision_una_nota_fuera_de_escala_se_queda_fuera_y_no_se_topa(): void
    {
        // Topar por defecto convertiría «el docente calificó sobre 100» en «todos
        // sacaron la máxima», que es un dato falso y creíble. El defecto es no
        // escribir.
        $this->assertSame('fuera', (new RespuestasDeLaPlanilla([]))->queHacerConLaEscala('95'));
    }

    #[Test]
    public function en_un_choque_manda_el_sistema_por_defecto(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([]);

        $this->assertSame('sistema', $respuestas->defectoDeLosChoques());
        $this->assertSame('sistema', $respuestas->queHacerConElChoque('a1b2c3d4e5f60718'));
    }

    #[Test]
    public function el_defecto_global_de_los_choques_admite_excepciones_celda_a_celda(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'choques' => [
                'por_defecto' => 'archivo',
                'excepciones' => [
                    ['id' => 'a1b2c3d4e5f60718', 'decision' => 'sistema'],
                ],
            ],
        ]);

        // La opción global de la pantalla de §6.3 es **un atajo, no una venda**: «ver
        // una por una» tiene que poder decidir una por una.
        $this->assertSame('archivo', $respuestas->queHacerConElChoque('0000111122223333'));
        $this->assertSame('sistema', $respuestas->queHacerConElChoque('a1b2c3d4e5f60718'));
    }

    #[Test]
    public function sin_decision_una_columna_huerfana_se_queda_fuera(): void
    {
        $decidido = (new RespuestasDeLaPlanilla([]))->queHacerConLaColumna('3B MAT', 'F');

        $this->assertSame('fuera', $decidido['decision']);
        $this->assertNull($decidido['subunidad_id']);
    }

    // ── Una decisión a medias cae al defecto ─────────────────────────────────

    #[Test]
    public function interpretar_sin_numero_no_inventa_un_cero(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'celdas' => [['valor' => 'N/A', 'decision' => 'interpretar']],
        ]);

        // La pantalla mandó la decisión y no el valor. Aplicar un cero por defecto
        // sería regalarle al alumno la peor nota posible **por un fallo de forma**.
        $this->assertSame('dejar', $respuestas->queHacerConLaCelda('N/A')['decision']);
    }

    #[Test]
    public function crear_un_indicador_sin_nombre_no_crea_nada(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'reserva' => [['hoja' => '3B MAT', 'columna' => 'H', 'decision' => 'crear', 'nombre' => '   ']],
        ]);

        // Un indicador llamado `""` es un indicador que nadie va a reconocer en la
        // planilla de la semana que viene.
        $this->assertSame('fuera', $respuestas->queHacerConLaReserva('3B MAT', 'H')['decision']);
    }

    #[Test]
    public function mover_a_un_indicador_sin_decir_cual_se_queda_fuera(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'estructura' => [['hoja' => '3B MAT', 'columna' => 'F', 'decision' => 'mover']],
        ]);

        $this->assertSame('fuera', $respuestas->queHacerConLaColumna('3B MAT', 'F')['decision']);
    }

    #[Test]
    public function la_decision_y_su_parametro_viajan_en_la_misma_cadena(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'estructura' => [['hoja' => '3B MAT', 'columna' => 'F', 'decision' => 'mover:1204']],
        ]);

        // `mover:1204` y no `{decision: 'mover', subunidad_id: 1204}`: juntos no hay
        // forma de mandar un `mover` sin destino y que aquí parezca una decisión
        // tomada. El estado a medias deja de ser posible en vez de ser improbable.
        $this->assertSame(
            ['decision' => 'mover', 'subunidad_id' => 1204],
            $respuestas->queHacerConLaColumna('3B MAT', 'F')
        );
    }

    #[Test]
    public function la_misma_columna_de_otra_hoja_es_otra_decision(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'estructura' => [['hoja' => '3B MAT', 'columna' => 'F', 'decision' => 'mover:1204']],
        ]);

        // En un libro de doce hojas la `F` de *4A Matemáticas* y la `F` de *3B
        // Geometría* son indicadores distintos. Una decisión que se colara de una
        // hoja a otra escribiría notas en la asignatura equivocada **sin dar error**.
        $this->assertSame('fuera', $respuestas->queHacerConLaColumna('4A GEO', 'F')['decision']);
    }

    #[Test]
    public function a_revisar_no_es_lo_mismo_que_dejar_aunque_las_dos_no_escriban(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'celdas' => [['valor' => 'N/A', 'decision' => 'revisar']],
        ]);

        // Las dos dejan la casilla como está, y **son distintas para la pantalla**:
        // `revisar` significa «no lo decido todavía» y sigue siendo un problema
        // pendiente; `dejar` es un problema resuelto. Confundirlas haría que el paso
        // desapareciera de la barra con decisiones sin tomar dentro.
        $this->assertSame('revisar', $respuestas->queHacerConLaCelda('N/A')['decision']);
    }

    // ── La huella y la firma ─────────────────────────────────────────────────

    #[Test]
    public function unas_respuestas_de_otro_fichero_se_reconocen(): void
    {
        $respuestas = new RespuestasDeLaPlanilla(['huella' => 'aaa']);

        $this->assertTrue($respuestas->sonDeOtroFichero('bbb'));
        $this->assertFalse($respuestas->sonDeOtroFichero('aaa'));
    }

    #[Test]
    public function unas_respuestas_sin_huella_se_aceptan(): void
    {
        // Exigirla rompería a quien mande instrucciones a mano, y lo que se busca es
        // cazar el cambio silencioso, no imponer un formato.
        $this->assertFalse((new RespuestasDeLaPlanilla([]))->sonDeOtroFichero('bbb'));
    }

    #[Test]
    public function la_firma_rota_solo_se_confirma_diciendolo(): void
    {
        $this->assertFalse((new RespuestasDeLaPlanilla([]))->confirmaLaFirmaRota());
        $this->assertFalse((new RespuestasDeLaPlanilla(['firma' => []]))->confirmaLaFirmaRota());
        $this->assertTrue(
            (new RespuestasDeLaPlanilla(['firma' => ['decision' => 'confirmo']]))->confirmaLaFirmaRota()
        );
    }

    // ── Lo que no se aplica, se dice ─────────────────────────────────────────

    #[Test]
    public function las_secciones_de_fases_futuras_se_declaran_no_aplicadas(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'ausencias' => [['hoja' => '3B MAT', 'alumno_id' => 1055, 'ausencias' => 4]],
        ]);

        $resumen = $respuestas->resumen();
        $secciones = array_column($resumen['no_aplicadas'], 'seccion');

        sort($secciones);

        // **Una decisión que se ignora en silencio es el mismo silencio que este
        // módulo vino a quitar.** El libro ya trae las columnas de ausencias
        // rellenas y escribibles, así que la pantalla puede mandarlas sin saberlo.
        $this->assertSame(['ausencias'], $secciones);

        foreach ($resumen['no_aplicadas'] as $renglon) {
            $this->assertNotEmpty($renglon['motivo'], 'Lo que no se aplica va con su motivo al lado.');
        }
    }

    #[Test]
    public function las_filas_salieron_de_no_aplicadas_al_llegar_la_fase_3(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'filas' => [['id' => 'a1b2c3', 'decision' => 'es:1061']],
        ]);

        $resumen = $respuestas->resumen();

        // **Y no es cosmética, es lo que hace que la pantalla exista.** El front
        // borra el paso «Alumnos» entero en cuanto ve `filas` en `no_aplicadas`, en
        // vez de ofrecer tres botones que el servidor no va a obedecer. Si esta
        // sección volviera a esa lista, la F6 se serviría y no se vería.
        $this->assertSame([], array_column($resumen['no_aplicadas'], 'seccion'));
        $this->assertSame(['filas'], $resumen['aplicadas']);
    }

    // ── F6: la única llave por fila ──────────────────────────────────────────

    #[Test]
    public function la_fila_se_decide_por_su_id_y_lleva_la_persona_dentro(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'filas' => [
                ['id' => 'aaa', 'decision' => 'es:1061'],
                ['id' => 'bbb', 'decision' => 'fuera'],
            ],
        ]);

        $this->assertSame(['decision' => 'es', 'alumno_id' => 1061], $respuestas->queHacerConLaFila('aaa'));
        $this->assertSame(['decision' => 'fuera', 'alumno_id' => null], $respuestas->queHacerConLaFila('bbb'));
    }

    #[Test]
    public function una_fila_sin_contestar_no_es_lo_mismo_que_dejarla_fuera(): void
    {
        $respuestas = new RespuestasDeLaPlanilla(['filas' => [['id' => 'aaa', 'decision' => 'fuera']]]);

        // Las dos hacen lo mismo —esa fila no se escribe— y se leen distinto: una es
        // un problema que la pantalla sigue enseñando y la otra es un problema
        // resuelto. Con el defecto en `fuera`, un libro con tres nombres escritos a
        // mano y sin tocar diría que está todo decidido.
        $this->assertSame(['decision' => null, 'alumno_id' => null], $respuestas->queHacerConLaFila('zzz'));
        $this->assertNotSame(
            $respuestas->queHacerConLaFila('aaa'),
            $respuestas->queHacerConLaFila('zzz')
        );
    }

    #[Test]
    public function un_es_sin_alumno_no_se_aplica_a_medias(): void
    {
        foreach (['es', 'es:', 'es:cero', 'es:0', 'es:-3'] as $rota) {
            $this->assertSame(
                ['decision' => 'fuera', 'alumno_id' => null],
                (new RespuestasDeLaPlanilla(['filas' => [['id' => 'aaa', 'decision' => $rota]]]))
                    ->queHacerConLaFila('aaa'),
                'Un «sí, es él» sin decir quién es la peor forma de resolver esto: '.$rota
            );
        }
    }

    #[Test]
    public function aplicadas_dice_que_secciones_si_cambian_algo(): void
    {
        $respuestas = new RespuestasDeLaPlanilla([
            'celdas' => [['valor' => '4,5', 'decision' => 'dejar']],
            'choques' => ['por_defecto' => 'archivo'],
        ]);

        // Es el reverso de `no_aplicadas`, y hace falta desde que esa lista puede
        // venir vacía: sin él, «vacía» se lee igual que «no se calculó».
        $this->assertSame(['celdas', 'choques'], $respuestas->resumen()['aplicadas']);
        $this->assertTrue($respuestas->hayAlguna());
    }
}
