<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las dos rutas de la rejilla — la **Fase 4** de
 * [35](../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md), con **D6**
 * y **D23**: *la casilla de la rejilla ES el nivel*, se abre premarcada y **no
 * escribe hasta que alguien guarda**.
 *
 * ## Qué existe esto para cazar, y ninguno de los seis da error solo
 *
 * **1. Que «premarcada» se convierta en «marcada de oficio».** Es una línea de
 * distancia: basta con que el `GET` escriba lo que calcula. **La respuesta sería
 * idéntica** —los mismos niveles, los mismos alumnos— y lo único distinto estaría
 * en la tabla. Por eso el caso no mira la respuesta: **cuenta las filas antes y
 * después**. Sujeta la decisión 10 del doc 28 entera: *ningún boletín afirma un
 * nivel que nadie miró*.
 *
 * **2. Que «no tener nota» y «sacar 0» acaben impresos igual.** Un alumno sin
 * definitiva que abriera la celda en el nivel más bajo saldría en el boletín
 * diciendo que alcanzó el desempeño en «Bajo» cuando lo que pasa es que **nadie lo
 * ha calificado**. Nada falla: es un 200 con un nivel dentro.
 *
 * **3. Que una escala con agujeros se calle.** Las bandas de un año **no tienen
 * por qué cubrir la recta**, y desde `2026_08_30_200000_notas_finales_en_decimal`
 * —que ya está en `main`— **la nota es `DECIMAL(7,4)` y las bandas siguen siendo
 * `int`**, así que un 29,5 entre 0-29 y 30-39 no casa con ninguna. Medido contra
 * el docker el 13 sep 2026: de las 127.748 definitivas de `simonbolivar`, **13 no
 * casan**, y **cuatro son por el decimal** (`45.5`, `45.005`, `45.05`, `39.3`).
 * Esa celda sale vacía y **la respuesta lo cuenta**, porque ésta es la única
 * pantalla donde se ve antes de salir impresa.
 *
 * **4. Que renombrar un nivel reescriba un boletín viejo.**
 * `escalas_de_valoracion` es **por año y editable**: `EscalasDeValoracionController`
 * hace `UPDATE … SET desempenio = …` sobre la fila viva. Una celda que guardara
 * sólo `escala_id` cambiaría de texto sola. Es el mismo argumento que ya hizo D9
 * con `frase`, y es el caso que justifica la tercera columna — **sin él, alguien la
 * quita por redundante**.
 *
 * **5. Que la rejilla se coma las frases escritas a mano.** `frases_asignatura`
 * tiene 12.294 filas en `simonbolivar` y **ninguna** salió de una rejilla. Si la
 * rejilla las leyera como celdas, el `PUT` las borraría con un `escala_id: null`
 * que el front manda sin saberlo — y el acudiente recibiría el boletín sin la
 * frase que el docente le escribió.
 *
 * **6. Que un 200 haya guardado media rejilla.** Un periodo cerrado o un alumno
 * que no es del grupo cortan la llamada **entera**, y eso hay que comprobarlo
 * **en la tabla**: un 403 que ya había escrito veinte filas contesta igual que uno
 * que no escribió ninguna.
 *
 * ## Los niveles se montan aquí y no se cogen del seed
 *
 * `escalas_de_valoracion.desempenio` es **texto libre y de cada colegio**, así que
 * un test que diera por buenos los cuatro nombres del seed —BAJO, BÁSICO, ALTO,
 * SUPERIOR— estaría comprobando *el seed* y no *el contrato*. Cada caso monta la
 * escala que necesita con `conLaEscala()`, y un par de ellos la montan con **tres
 * bandas y nombres que no son ésos** justamente para que se vea que nada del
 * código supone cuatro ni supone los nombres.
 */
class RejillaPremarcadaTest extends CasoDeContrato
{
    /** El token del sujeto, que pone `unCaso()` y usa `pedir()`. */
    private string $token = '';

    // ─────────────────────────────────────────────────────────────────────
    // Premarcar no es escribir
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **El caso que sostiene la fase entera.** Se ve rojo con un `GET` que guarde
     * lo que propone, que es el descuido de una línea que convierte D6 en lo
     * contrario de D6.
     */
    #[Test]
    public function test_abrir_la_rejilla_no_escribe_ni_una_fila(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['Insuficiente', 0, 59], ['Aceptable', 60, 100]]);
        $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 75);

        $antes = $this->cuantasFrases();

        $r = $this->pedir('getJson', $this->ruta($caso));
        $r->assertStatus(200);

        $this->assertGreaterThan(0, $r->json('poblacion.celdas_propuestas'),
            'Si no propuso ni una celda, este caso no demuestra nada: no habría nada que escribir.');

        $this->assertSame($antes, $this->cuantasFrases(),
            'El GET escribió en `frases_asignatura`. Premarcar NO es escribir (D6/D23): '
            .'la propuesta se calcula y se devuelve, y la fila sólo existe cuando el docente guarda. '
            .'Con esto en rojo, «rejilla premarcada» es «marcada de oficio» y la decisión 10 se cae.');
    }

    /** La celda se abre con el nivel que al alumno le toca por su definitiva. */
    #[Test]
    public function test_la_celda_se_abre_en_el_nivel_que_le_toca_por_su_nota(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [
            ['Por mejorar', 0, 59],
            ['Lo logra', 60, 89],
            ['Lo supera', 90, 100],
        ]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 95);

        $celda = $this->celda($this->rejilla($caso), $caso->alumnos[0], $desempeno);

        $this->assertSame('propuesto', $celda['estado']);
        $this->assertNull($celda['motivo']);
        $this->assertSame($niveles['Lo supera'], $celda['escala_id'],
            'El 95 cae en la tercera banda y la celda tendría que abrirse en ella.');
        $this->assertSame('Lo supera', $celda['nivel'],
            'El nombre del nivel es el que puso el colegio, no uno de los cuatro del seed.');
        $this->assertNull($celda['frase_asignatura_id'],
            'Una celda propuesta no tiene fila, así que no puede tener id: '
            .'un id aquí es exactamente lo que el front leería como «ya está guardado».');
    }

    /**
     * **Sin definitiva, la celda sale VACÍA y no en el nivel más bajo** (D23, punto
     * 2). No tener nota y sacar 0 acaban impresos distinto.
     *
     * Se ve rojo con un `?? 0` en el premarcado, que es el atajo natural.
     */
    #[Test]
    public function test_sin_definitiva_la_celda_sale_vacia_y_no_en_el_nivel_mas_bajo(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Por mejorar', 0, 59], ['Lo logra', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);

        // El primero con nota, el segundo sin ninguna: con los dos en la misma
        // llamada se ve que la diferencia es el alumno y no la escala.
        $this->conDefinitiva($caso, $caso->alumnos[0], 0);

        $rejilla = $this->rejilla($caso);

        $conCero = $this->celda($rejilla, $caso->alumnos[0], $desempeno);
        $sinNota = $this->celda($rejilla, $caso->alumnos[1], $desempeno);

        $this->assertSame('propuesto', $conCero['estado'],
            'Sacar 0 SÍ es una nota, y cae en la primera banda.');
        $this->assertSame($niveles['Por mejorar'], $conCero['escala_id']);

        $this->assertSame('vacia', $sinNota['estado'],
            'El alumno sin definitiva abre la celda VACÍA. Premarcarlo en el nivel más bajo '
            .'imprime en su boletín que no alcanzó el desempeño cuando lo que pasa es que '
            .'nadie lo ha calificado.');
        $this->assertSame('sin_definitiva', $sinNota['motivo'],
            'Y dice por qué está vacía: «no tiene nota» no es lo mismo que «su nota no casa».');
        $this->assertNull($sinNota['escala_id']);
        $this->assertNull($sinNota['nivel']);
    }

    /**
     * **Una nota en el hueco entre dos bandas sale vacía, y se cuenta.**
     *
     * La escala de este caso deja 59-61 sin cubrir a propósito: es el ejemplo
     * literal de D23 —*«con 0-59 y 61-100, un 60 no casa con ninguna»*— y es
     * también, con otros números, lo que el `DECIMAL(7,4)` de
     * `2026_08_30_200000` le hace a cualquier escala de bandas enteras.
     */
    #[Test]
    public function test_una_nota_en_el_hueco_entre_dos_bandas_sale_vacia_y_contada(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 61, 100]]);
        $desempeno = $this->unDesempeno($caso);

        // **Los TRES con nota**, y eso no es adorno: el caso afirma que
        // `alumnos_sin_definitiva` se queda en cero, así que si alguno se quedara
        // sin definitiva el cero sería mentira y el caso dejaría de distinguir los
        // dos motivos, que es justo lo que viene a demostrar.
        $this->conDefinitiva($caso, $caso->alumnos[0], 60);
        $this->conDefinitiva($caso, $caso->alumnos[1], 70);
        $this->conDefinitiva($caso, $caso->alumnos[2], 30);

        $rejilla = $this->rejilla($caso);
        $enElHueco = $this->celda($rejilla, $caso->alumnos[0], $desempeno);

        $this->assertSame('vacia', $enElHueco['estado'],
            'El 60 no cae en 0-59 ni en 61-100, así que no hay nivel que proponer.');
        $this->assertSame('sin_banda', $enElHueco['motivo'],
            'Y NO `sin_definitiva`: este alumno sí tiene nota. La diferencia es lo que '
            .'distingue «falta calificarlo» de «la escala del colegio tiene un agujero».');

        $this->assertSame(1, $rejilla['poblacion']['alumnos_sin_banda'],
            'La respuesta tiene que CONTAR los que se quedan fuera de la escala: es la única '
            .'pantalla donde una escala mal montada se ve antes de salir impresa.');
        $this->assertSame(0, $rejilla['poblacion']['alumnos_sin_definitiva'],
            'Y no puede mezclarlos con los que no tienen nota.');
    }

    /**
     * **Y una nota por encima del techo de la escala también.** Es la otra mitad
     * del mismo contador y no es hipotética: nueve de las trece definitivas que hoy
     * no casan en `simonbolivar` son de esta forma —51 a 56 con la escala acabando
     * en 50—.
     */
    #[Test]
    public function test_una_nota_por_encima_del_techo_de_la_escala_tambien_sale_vacia(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['Bajo', 0, 29], ['Alto', 30, 50]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 55);

        $celda = $this->celda($this->rejilla($caso), $caso->alumnos[0], $desempeno);

        $this->assertSame('vacia', $celda['estado']);
        $this->assertSame('sin_banda', $celda['motivo']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Guardar
    // ─────────────────────────────────────────────────────────────────────

    /** Guardar escribe la celda, y la rejilla la vuelve a traer como **guardada**. */
    #[Test]
    public function test_guardar_escribe_la_celda_y_vuelve_como_guardada(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso, 'Identifica los tipos de triángulo por sus lados');
        $this->conDefinitiva($caso, $caso->alumnos[0], 40);

        $r = $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]));

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('escritas'));
        $this->assertSame(1, $r->json('recibidas'));

        $fila = DB::selectOne(
            'SELECT id, frase, frase_id, desempeno_id, escala_id, nivel FROM frases_asignatura
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$caso->alumnos[0], $caso->asignatura_id, $caso->periodo_id]
        );

        $this->assertNotNull($fila, 'Guardar la rejilla no dejó la fila en `frases_asignatura`, '
            .'que es la tabla que los tres boletines ya leen.');
        $this->assertSame('Identifica los tipos de triángulo por sus lados', $fila->frase,
            'El TEXTO se copia (D9): es lo que se imprime y lo que protege los boletines viejos.');
        $this->assertNull($fila->frase_id,
            '`frase_id` tiene que ir a null: `FraseAsignatura::deAlumno` hace '
            .'`IFNULL(f.frase, fa.frase)`, así que con un `frase_id` el boletín imprimiría '
            .'la frase del catálogo en lugar del desempeño.');
        $this->assertSame($desempeno, (int) $fila->desempeno_id);
        $this->assertSame($niveles['Alto'], (int) $fila->escala_id);
        $this->assertSame('Alto', $fila->nivel);

        $celda = $this->celda($this->rejilla($caso), $caso->alumnos[0], $desempeno);

        $this->assertSame('guardado', $celda['estado'],
            'Ya hay fila, así que la celda deja de ser una propuesta.');
        $this->assertSame((int) $fila->id, $celda['frase_asignatura_id'],
            'Y viaja con su id, que es lo que el front necesita para no volver a crearla.');
    }

    /**
     * **El docente le corrige el nivel a UN alumno, y eso es la mitad de D6.**
     *
     * Incluido el que no tiene definitiva: la celda vacía se puede rellenar a mano,
     * y entonces gana lo guardado sobre lo propuesto.
     */
    #[Test]
    public function test_lo_guardado_gana_sobre_lo_propuesto_incluso_sin_definitiva(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);

        $this->conDefinitiva($caso, $caso->alumnos[0], 90);   // propondría «Alto»
        // El segundo se queda sin definitiva a propósito.

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Bajo']],
            [$caso->alumnos[1], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);

        $rejilla = $this->rejilla($caso);

        $corregido = $this->celda($rejilla, $caso->alumnos[0], $desempeno);
        $this->assertSame('guardado', $corregido['estado']);
        $this->assertSame($niveles['Bajo'], $corregido['escala_id'],
            'La rejilla volvió a proponer «Alto» por la nota y pisó lo que el docente guardó. '
            .'Si el premarcado gana sobre lo guardado, corregirle el nivel a un alumno no sirve de nada.');

        $sinNota = $this->celda($rejilla, $caso->alumnos[1], $desempeno);
        $this->assertSame('guardado', $sinNota['estado'],
            'Un alumno sin definitiva PUEDE tener celda guardada: la pone el docente a mano.');
        $this->assertSame($niveles['Alto'], $sinNota['escala_id']);
        $this->assertNull($sinNota['motivo'],
            '`motivo` es de las celdas vacías; una guardada no tiene por qué explicar nada.');
    }

    /** Vaciar una casilla borra la fila, y la celda vuelve a ser una propuesta. */
    #[Test]
    public function test_vaciar_una_casilla_borra_la_fila_y_vuelve_a_proponer(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Bajo']],
        ]))->assertStatus(200);

        $r = $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, null],
        ]));

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('borradas'));

        $this->assertSame(0, (int) DB::table('frases_asignatura')
            ->where('alumno_id', $caso->alumnos[0])
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->count(),
            'Vaciar la casilla tiene que dejar la fila borrada: si sigue viva, el boletín '
            .'sigue imprimiendo un nivel que el docente acaba de quitar.');

        $celda = $this->celda($this->rejilla($caso), $caso->alumnos[0], $desempeno);
        $this->assertSame('propuesto', $celda['estado'],
            'Sin fila, la celda vuelve a ser lo que era: una propuesta.');
        $this->assertSame($niveles['Alto'], $celda['escala_id']);
    }

    /**
     * Volver a guardar lo mismo **no cuenta como cambio**, y eso no es cosmético:
     * es lo que separa *«se guardó y no cambió nada»* de *«no se guardó»*, que es
     * exactamente lo que un docente pregunta cuando cree que perdió el trabajo.
     */
    #[Test]
    public function test_volver_a_guardar_lo_mismo_lo_dice_en_los_contadores(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $cuerpo = $this->cuerpo($caso, [[$caso->alumnos[0], $desempeno, $niveles['Alto']]]);

        $this->pedir('putJson', 'desempenos/rejilla', $cuerpo)->assertStatus(200);
        $segunda = $this->pedir('putJson', 'desempenos/rejilla', $cuerpo);

        $segunda->assertStatus(200);
        $this->assertSame(0, $segunda->json('escritas'));
        $this->assertSame(0, $segunda->json('cambiadas'));
        $this->assertSame(1, $segunda->json('sin_cambio'));

        $this->assertSame(1, (int) DB::table('frases_asignatura')
            ->where('alumno_id', $caso->alumnos[0])
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->count(),
            'Guardar dos veces dejó dos filas: el boletín imprimiría el desempeño repetido.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que protege los boletines viejos
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **El caso que justifica la tercera columna.** Sin él, alguien mira
     * `escala_id` y `nivel` juntos, ve la redundancia y quita la segunda.
     *
     * `EscalasDeValoracionController` hace `UPDATE … SET desempenio = :desemp …
     * WHERE id = :id` **sobre la fila viva**, la misma que un boletín de 2026
     * leería por su id. Se ve rojo guardando sólo el id y resolviendo el nombre al
     * leer.
     */
    #[Test]
    public function test_renombrar_la_escala_no_cambia_un_boletin_ya_puesto(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Básico', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Básico']],
        ]))->assertStatus(200);

        // El colegio le cambia el nombre a ese nivel, dos años después.
        DB::table('escalas_de_valoracion')->where('id', $niveles['Básico'])
            ->update(['desempenio' => 'Satisfactorio']);

        $this->assertSame('Básico', DB::table('frases_asignatura')
            ->where('alumno_id', $caso->alumnos[0])
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->value('nivel'),
            'Renombrar el nivel le cambió el texto a una celda ya guardada. Es lo que pasa '
            .'cuando la celda guarda sólo `escala_id`: un boletín impreso en 2026 empieza a '
            .'decir otra cosa en 2028. Por eso el nivel gasta DOS columnas (D23).');

        $celda = $this->celda($this->rejilla($caso), $caso->alumnos[0], $desempeno);
        $this->assertSame('Básico', $celda['nivel'],
            'Y la rejilla devuelve el texto congelado, que es lo que se imprime.');
    }

    /**
     * Corregir el desempeño **no cambia el texto ya guardado** — es la regla 1 de la
     * §4 del doc 28 aplicada a este camino: *el catálogo siembra, no manda*.
     */
    #[Test]
    public function test_corregir_el_desempeno_no_cambia_el_texto_ya_guardado(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso, 'Identifica los tipos de triángulo');
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);

        DB::table('desempenos')->where('id', $desempeno)
            ->update(['definicion' => 'Clasifica los triángulos según sus lados y sus ángulos']);

        $this->assertSame('Identifica los tipos de triángulo', DB::table('frases_asignatura')
            ->where('alumno_id', $caso->alumnos[0])
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->value('frase'),
            'El texto se COPIA al guardar. Enlazarlo en vez de copiarlo hace que corregir una '
            .'errata en 2028 cambie un boletín impreso en 2026.');
    }

    /**
     * **Y al volver a guardar esa celda, el texto SÍ se rehace.** Es la otra mitad
     * del caso de arriba, y va escrita porque sin ella el anterior promete de más:
     * el docente que corrige una errata y guarda espera verla corregida.
     *
     * Lo que de verdad protege un boletín de un año pasado **no es la copia, es que
     * su periodo esté cerrado** —`test_guardar_en_un_periodo_cerrado_no_escribe_nada`—.
     * Los dos casos juntos dicen la regla entera: *sin guardado nuevo no se mueve una
     * letra, y en un periodo cerrado no hay guardado nuevo*.
     */
    #[Test]
    public function test_y_al_volver_a_guardar_la_celda_el_texto_se_rehace(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso, 'Identifica los tipos de triángulo');
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $cuerpo = $this->cuerpo($caso, [[$caso->alumnos[0], $desempeno, $niveles['Alto']]]);
        $this->pedir('putJson', 'desempenos/rejilla', $cuerpo)->assertStatus(200);

        DB::table('desempenos')->where('id', $desempeno)
            ->update(['definicion' => 'Clasifica los triángulos según sus lados']);

        $r = $this->pedir('putJson', 'desempenos/rejilla', $cuerpo);

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('cambiadas'),
            'Guardar con el texto del desempeño cambiado tiene que contarse como un cambio: '
            .'el contador es lo único que le dice al docente que su corrección entró.');

        $this->assertSame('Clasifica los triángulos según sus lados', DB::table('frases_asignatura')
            ->where('alumno_id', $caso->alumnos[0])
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->value('frase'));
    }

    /**
     * **La CUARTA columna: cómo se llamaba su COMPETENCIA.**
     *
     * Es el argumento de `nivel` un piso más arriba, y el que se le escapó a la Fase 4
     * porque la competencia está a **dos** saltos: `frases_asignatura` guarda de qué
     * desempeño salió la celda, y a la competencia se llega por
     * `desempenos.competencia_id`. `CompetenciasController::putUpdate` hace
     * `UPDATE … SET definicion = …` **sobre la fila viva**, la misma que un boletín de
     * 2026 alcanzaría por ese salto, así que sin la copia **renombrar una competencia en
     * 2028 cambia la cabecera de un boletín de 2026**.
     *
     * Se ve rojo quitando `'competencia' => $competencia` de `$valores` en `putRejilla`.
     */
    #[Test]
    public function test_renombrar_la_competencia_no_cambia_una_celda_ya_puesta(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $competencia = $this->unaCompetencia($caso, 'Resuelve problemas con números racionales');
        $desempeno = $this->unDesempeno($caso, 'Identifica fracciones equivalentes', null, $competencia);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);

        // El colegio le corrige el texto a la competencia, dos años después.
        DB::table('competencias')->where('id', $competencia)
            ->update(['definicion' => 'Interpreta y resuelve situaciones con números racionales']);

        $this->assertSame('Resuelve problemas con números racionales',
            $this->competenciaGuardada($caso, $caso->alumnos[0]),
            'La celda tiene que llevar copiado el texto de la competencia del día que se guardó. '
            .'Sin esa copia no hay de dónde sacar lo que decía la cabecera de un boletín ya impreso, '
            .'y lo que se pierde no se recupera: la columna sólo sirve si está ANTES del renombrado.');
    }

    /**
     * **Un desempeño SIN competencia deja la columna a `null`** — ni `''`, ni el texto de
     * otra, ni una fila a medias.
     *
     * El desempeño suelto es legal (**D10**) y es el que la §4.3 del plan del front
     * imprime al final, debajo de los bloques. `''` sería peor que `null` y no es lo
     * mismo: el boletín distingue *«esta celda no congeló ninguna competencia»* de
     * *«se llamaba así»*, y una cadena vacía se cuela por el lado equivocado de esa
     * pregunta y estampa una cabecera en blanco sobre el texto vivo.
     */
    #[Test]
    public function test_un_desempeno_sin_competencia_deja_la_columna_a_null(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso, 'Entrega sus trabajos a tiempo');
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);

        $this->assertNull($this->competenciaGuardada($caso, $caso->alumnos[0]),
            'Un desempeño suelto no tiene competencia que congelar, y `null` es lo que dice eso. '
            .'Cualquier otra cosa se imprime.');

        // Y la celda entra igual: lo que no tiene competencia no es una celda rota.
        $this->assertSame('Entrega sus trabajos a tiempo', DB::table('frases_asignatura')
            ->where('alumno_id', $caso->alumnos[0])
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->value('frase'));
    }

    /**
     * **Y al volver a guardar, la copia de la competencia también se rehace.**
     *
     * Es el gemelo de `test_y_al_volver_a_guardar_la_celda_el_texto_se_rehace` y va
     * escrito por lo mismo: el docente que corrige el texto del plan de área y guarda
     * espera verlo corregido. Lo que protege un boletín de un año pasado no es la copia,
     * es que su periodo esté cerrado.
     *
     * **Se ve rojo dejando `competencia` fuera de la comparación de `sin_cambio`**: la
     * llamada contestaría `sin_cambio: 1`, la copia vieja se quedaría dentro y el
     * contador —que es lo único que le dice al docente que su corrección entró— diría
     * que no había nada que cambiar.
     */
    #[Test]
    public function test_renombrar_la_competencia_y_reguardar_rehace_la_copia(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $competencia = $this->unaCompetencia($caso, 'Resulve problemas con racionales');
        $desempeno = $this->unDesempeno($caso, 'Identifica fracciones equivalentes', null, $competencia);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $cuerpo = $this->cuerpo($caso, [[$caso->alumnos[0], $desempeno, $niveles['Alto']]]);
        $this->pedir('putJson', 'desempenos/rejilla', $cuerpo)->assertStatus(200);

        DB::table('competencias')->where('id', $competencia)
            ->update(['definicion' => 'Resuelve problemas con racionales']);

        $r = $this->pedir('putJson', 'desempenos/rejilla', $cuerpo);

        $r->assertStatus(200);
        $this->assertSame(1, $r->json('cambiadas'),
            'Guardar con el texto de la competencia cambiado tiene que contarse como un cambio.');

        $this->assertSame('Resuelve problemas con racionales',
            $this->competenciaGuardada($caso, $caso->alumnos[0]));
    }

    /**
     * **Las frases escritas a mano no son celdas, y la rejilla no las toca.**
     *
     * Es la línea que deja a las dos pantallas escribir en la misma tabla. Se ve
     * roja quitando el `desempeno_id IS NOT NULL` de `celdasGuardadas()`.
     */
    #[Test]
    public function test_la_rejilla_no_toca_las_frases_escritas_a_mano(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $aMano = (int) DB::table('frases_asignatura')->insertGetId([
            'alumno_id' => $caso->alumnos[0],
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'frase' => 'Mejoró mucho en el segundo periodo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rejilla = $this->rejilla($caso);

        foreach ($rejilla['celdas'] as $celda) {
            $this->assertNotSame($aMano, $celda['frase_asignatura_id'],
                'La rejilla se trajo una frase escrita a mano como si fuera una celda. '
                .'Desde ahí, un `escala_id: null` del front la borra del boletín.');
        }

        // Y guardar y vaciar la rejilla entera la deja donde estaba.
        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, null],
        ]))->assertStatus(200);

        $this->assertSame('Mejoró mucho en el segundo periodo',
            DB::table('frases_asignatura')->where('id', $aMano)->whereNull('deleted_at')->value('frase'),
            'La frase escrita a mano desapareció al vaciar la rejilla.');
    }

    /**
     * El texto de un desempeño de **388 caracteres** viaja entero de ida y vuelta.
     *
     * Es `FraseLargaEnElBoletinTest` aplicado a este camino, y **sin la Fase 0 se ve
     * rojo**: `frases_asignatura.frase` era `varchar(255)` y MySQL, sin
     * `STRICT_TRANS_TABLES`, corta y devuelve 200.
     */
    #[Test]
    public function test_un_desempeno_de_388_caracteres_viaja_entero(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);

        // Cortado a 388 y no compuesto a mano: el número es el del caso, no una suma
        // que hay que rehacer si alguien le cambia una palabra a la frase.
        $largo = mb_substr(str_repeat('Identifica y clasifica los polígonos regulares según sus lados. ', 10), 0, 388);
        $this->assertSame(388, mb_strlen($largo), 'El texto de este caso tiene que medir 388.');

        $desempeno = $this->unDesempeno($caso, $largo);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);

        $enLaBase = (int) DB::selectOne(
            'SELECT CHAR_LENGTH(frase) n FROM frases_asignatura
              WHERE alumno_id = ? AND asignatura_id = ? AND desempeno_id IS NOT NULL
                AND deleted_at IS NULL',
            [$caso->alumnos[0], $caso->asignatura_id]
        )->n;

        $this->assertSame(388, $enLaBase,
            "El desempeño se guardó cortado a {$enLaBase} caracteres. Es el corte silencioso de "
            .'la §1.ter: MySQL no está en modo estricto, así que devuelve 200 y el acudiente '
            .'recibe el boletín cortado a mitad de palabra.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // O entra todo o no entra nada
    // ─────────────────────────────────────────────────────────────────────

    /** Guardar en un periodo cerrado es 403 **y no escribe nada**. */
    #[Test]
    public function test_guardar_en_un_periodo_cerrado_no_escribe_nada(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        DB::table('periodos')->where('id', $caso->periodo_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $antes = $this->cuantasFrases();

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(403);

        $this->assertSame($antes, $this->cuantasFrases(),
            'Contestó 403 y escribió igual: el criterio frena la respuesta pero no la escritura.');
    }

    /**
     * **Pero el `GET` sí funciona con el periodo cerrado, y lo dice.** Abrir la
     * rejilla de un periodo cerrado es lo que hace cualquiera que quiera mirar lo
     * que se puso; lo que no se puede es escribir.
     */
    #[Test]
    public function test_con_el_periodo_cerrado_la_rejilla_se_lee_y_avisa(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $this->unDesempeno($caso);

        DB::table('periodos')->where('id', $caso->periodo_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $rejilla = $this->rejilla($caso);

        $this->assertFalse($rejilla['periodo_abierto'],
            'El front tiene que poder saber que el PUT le va a contestar 403 ANTES de dejar '
            .'tocar la rejilla: un candado que sólo se conoce por el error se descubre '
            .'perdiendo lo escrito.');
    }

    /**
     * **La comprobación que hoy falta** (§1.8): `FrasesAsignaturaController::postStore`
     * no mira que el alumno esté en el grupo de la asignatura, así que por su ruta se
     * le puede poner una frase de boletín a cualquier alumno del colegio. Aquí es
     * **422 y no escribe**.
     */
    #[Test]
    public function test_un_alumno_que_no_es_del_grupo_es_422_y_no_escribe(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $ajeno = (int) DB::selectOne(
            'SELECT a.id FROM alumnos a WHERE a.deleted_at IS NULL AND a.id NOT IN (?, ?, ?)
              ORDER BY a.id LIMIT 1',
            $caso->alumnos
        )->id;

        $antes = $this->cuantasFrases();

        // La celda buena va delante y la mala detrás **a propósito**: con las
        // comprobaciones dentro del bucle, la primera ya estaría escrita cuando la
        // segunda aborta, y `DatabaseTransactions` no salva a nadie en producción.
        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
            [$ajeno, $desempeno, $niveles['Alto']],
        ]))->assertStatus(422);

        $this->assertSame($antes, $this->cuantasFrases(),
            'Abortó con 422 después de escribir la primera celda. O entra todo o no entra nada: '
            .'un 200 a medias es peor, pero un 422 a medias es lo mismo sin decirlo.');
    }

    /**
     * **`escala_id` ausente no es `escala_id: null`.** Con un `??` a null, un front
     * que se dejara el campo borraría la rejilla entera con un 200 delante.
     */
    #[Test]
    public function test_una_casilla_sin_escala_id_es_422_y_no_borra(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);

        $this->pedir('putJson', 'desempenos/rejilla', [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'celdas' => [['alumno_id' => $caso->alumnos[0], 'desempeno_id' => $desempeno]],
        ])->assertStatus(422);

        $this->assertSame(1, (int) DB::table('frases_asignatura')
            ->where('alumno_id', $caso->alumnos[0])
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNull('deleted_at')->count(),
            'Una casilla sin `escala_id` borró la celda. Aquí lo ambiguo borra, '
            .'así que lo ambiguo se rechaza.');
    }

    /** La misma casilla dos veces en el mismo cuerpo es 422. */
    #[Test]
    public function test_la_misma_casilla_dos_veces_es_422(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
            [$caso->alumnos[0], $desempeno, $niveles['Bajo']],
        ]))->assertStatus(422);
    }

    /** Un `escala_id` que no es de la escala de ese año es 422. */
    #[Test]
    public function test_un_nivel_de_otro_ano_es_422(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);

        $deOtroAnio = (int) DB::table('escalas_de_valoracion')
            ->where('year_id', '!=', $caso->year_id)->whereNull('deleted_at')
            ->orderBy('id')->value('id');

        $this->assertGreaterThan(0, $deOtroAnio, 'El seed no tiene escalas de otro año.');

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $deOtroAnio],
        ]))->assertStatus(422);
    }

    /** Un periodo que no es del año de la asignatura es 422, y no una rejilla vacía. */
    #[Test]
    public function test_un_periodo_de_otro_ano_es_422_y_no_una_rejilla_vacia(): void
    {
        $caso = $this->unCaso();

        $deOtroAnio = (int) DB::table('periodos')
            ->where('year_id', '!=', $caso->year_id)->whereNull('deleted_at')
            ->orderBy('id')->value('id');

        $this->assertGreaterThan(0, $deOtroAnio, 'El seed no tiene periodos de otro año.');

        $this->pedir('getJson', 'desempenos/rejilla?asignatura_id='
            .$caso->asignatura_id.'&periodo_id='.$deOtroAnio)->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Quién, y con qué alcance
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Un docente llano abre y guarda la rejilla sin `can_edit_plantilla_notas`**.
     * Es la línea que separa la Fase 4 de las siete del catálogo: lo que configura
     * el colegio el docente no lo toca, pero la rejilla es suya.
     */
    #[Test]
    public function test_un_docente_llano_abre_y_guarda_la_rejilla(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);

        // El mismo sujeto que usa todo este fichero: `usuarioLlanoDelPersonal()`,
        // que es `is_superuser = 0` y no tiene el permiso de la plantilla.
        $this->assertFalse(
            DB::table('role_user')->where('user_id', $caso->user_id)
                ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.name', Autoriza::PERMISO_PLANTILLA_NOTAS)
                ->exists(),
            'El sujeto de este caso tiene el permiso de la plantilla, así que no demuestra '
            .'lo que dice su nombre.'
        );

        $this->pedir('getJson', $this->ruta($caso))->assertStatus(200);
        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
        ]))->assertStatus(200);
    }

    /**
     * **El `<=>` del boletín independiente, en la rejilla.**
     *
     * El alumno marcado recibe **sus** desempeños y no los del curso; los demás
     * siguen con los del curso. Se ve rojo con un `=` a secas, y el síntoma sería
     * **una fila vacía en 200**, no un error.
     */
    #[Test]
    public function test_el_alumno_independiente_recibe_los_suyos_y_los_demas_los_del_curso(): void
    {
        $caso = $this->unCaso();
        $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);

        $delCurso = $this->unDesempeno($caso, 'El del curso');
        $suyo = $this->unDesempeno($caso, 'El suyo', $caso->alumnos[0]);

        $this->marcarIndependiente($caso->alumnos[0], $caso->periodo_id);

        $rejilla = $this->rejilla($caso);

        $suyas = $this->celdasDe($rejilla, $caso->alumnos[0]);
        $normales = $this->celdasDe($rejilla, $caso->alumnos[1]);

        $this->assertSame([$suyo], $suyas,
            'El alumno marcado tiene que recibir SUS desempeños y sólo ésos. Con la lista vacía, '
            .'el `<=>` se convirtió en `=` y su boletín sale mudo, en 200 y sin error.');
        $this->assertSame([$delCurso], $normales,
            'Y el alumno normal sigue con los del curso: un desempeño con dueño no es columna '
            .'de todo el mundo.');

        foreach ($rejilla['alumnos'] as $alumno) {
            if ($alumno['alumno_id'] === $caso->alumnos[0]) {
                $this->assertTrue($alumno['independiente'],
                    'La respuesta tiene que decir con qué alcance leyó, para que una fila '
                    .'distinta se pueda explicar sin mirar la base.');
                $this->assertSame($caso->alumnos[0], $alumno['alcance']);
            }
        }
    }

    /** Y ponerle a un alumno un desempeño que no es suyo es 422. */
    #[Test]
    public function test_ponerle_a_un_alumno_el_desempeno_de_otro_es_422(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);

        $suyo = $this->unDesempeno($caso, 'El suyo', $caso->alumnos[0]);
        $this->marcarIndependiente($caso->alumnos[0], $caso->periodo_id);

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[1], $suyo, $niveles['Alto']],
        ]))->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que deja escrito, y lo que cuesta leerla
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Una línea de auditoría y no trescientas**, y se escribe también cuando no se
     * guardó nada: «alguien le dio a guardar y no pasó nada» es exactamente el
     * suceso que se va a investigar dentro de un año.
     */
    #[Test]
    public function test_guardar_deja_una_linea_de_auditoria_y_solo_una(): void
    {
        $caso = $this->unCaso();
        $niveles = $this->conLaEscala($caso, [['Bajo', 0, 59], ['Alto', 60, 100]]);
        $desempeno = $this->unDesempeno($caso);
        $this->conDefinitiva($caso, $caso->alumnos[0], 90);
        $this->conDefinitiva($caso, $caso->alumnos[1], 20);

        $antes = (int) DB::table('auditoria')->where('entidad', 'frase_asignatura')->count();

        $this->pedir('putJson', 'desempenos/rejilla', $this->cuerpo($caso, [
            [$caso->alumnos[0], $desempeno, $niveles['Alto']],
            [$caso->alumnos[1], $desempeno, $niveles['Bajo']],
        ]))->assertStatus(200);

        $lineas = DB::select(
            'SELECT accion, asignatura_id, periodo_id, grupo_id, year_id, valor_nuevo
               FROM auditoria WHERE entidad = ? ORDER BY id DESC LIMIT 5',
            ['frase_asignatura']
        );

        $this->assertSame($antes + 1,
            (int) DB::table('auditoria')->where('entidad', 'frase_asignatura')->count(),
            'Dos celdas dejaron más de una línea. Una rejilla son 300 celdas: con una línea '
            .'por celda, la pantalla de auditoría deja de leerse.');

        $this->assertSame(Auditoria::EDITAR, $lineas[0]->accion);
        $this->assertSame($caso->asignatura_id, (int) $lineas[0]->asignatura_id);
        $this->assertSame($caso->grupo_id, (int) $lineas[0]->grupo_id);

        $valores = json_decode((string) $lineas[0]->valor_nuevo, true);
        $this->assertSame(2, $valores['recibidas'],
            'La línea tiene que llevar dentro la población: sin ella dice que alguien guardó '
            .'y no dice qué guardó.');
    }

    /**
     * **La consulta de las celdas guardadas no recorre la tabla.** Es la forma de
     * `IndicesTest`, y vive aquí porque la consulta vive en el controlador de esta
     * fase: si allí cambia y aquí no, esto deja de proteger nada.
     *
     * ## Lo que se mide, y por qué `possible_keys` solo no bastaba
     *
     * `IndicesTest` comprueba `possible_keys` vacío, que es *«no existe ningún
     * índice que MySQL pudiera considerar»*. Aquí se mira **además `type`**, y las
     * dos comprobaciones se ganaron a pulso, cada una cazando un verde falso de la
     * versión anterior de este mismo caso:
     *
     *   1. Con **sólo `possible_keys`**, el caso pasaba **quitando el acotado por
     *      alumno**, porque el `desempeno_id IS NOT NULL` deja aplicable el índice
     *      que estrena esta fase. El verde era correcto; el **mensaje**, no.
     *   2. Con **sólo `type !== 'ALL'`**, pasaba quitando **las dos** cláusulas:
     *      con el `ORDER BY fa.id` delante, MySQL recorre la tabla entera **por la
     *      PRIMARY** y eso es `type = index`, no `ALL`. **Un recorrido completo con
     *      otro nombre.**
     *
     * ## El plan de la consulta de verdad —con su `ORDER BY`—, medido el 13 sep 2026
     *
     * | consulta | `type` | `possible_keys` | filas |
     * |---|---|---|---|
     * | `asignatura + periodo`, `simonbolivar` **sin migrar** (12.294 filas) | **`ALL`** | ninguna | **13.218** |
     * | `asignatura + periodo`, base de test **ya migrada** | **`index`** (PRIMARY) | ninguna | **167** |
     * | `+ alumno_id IN (…)` | `range` | `…_alumno_asig_periodo_index` | 3 |
     * | `+ desempeno_id IS NOT NULL` | `range` | `…_desempeno_index` | 1 |
     *
     * O sea: **`WHERE asignatura_id = ? AND periodo_id = ?` a secas es un recorrido
     * completo**, con `ALL` o con `index` según lo que haya en la tabla, y lo salvan
     * **dos** cláusulas distintas, cada una por su índice. Las dos están escritas a
     * propósito: el día que alguien quite una —y el `desempeno_id IS NOT NULL` es
     * justo el que se quita al «simplificar»— la otra sigue. Quitar las dos deja la
     * rejilla recorriendo la tabla entera de un colegio **en cada apertura**, y eso
     * es lo que este caso se ha visto poner rojo.
     */
    #[Test]
    public function test_la_consulta_de_la_rejilla_no_recorre_la_tabla(): void
    {
        $plan = DB::select(
            'EXPLAIN SELECT fa.id, fa.alumno_id, fa.desempeno_id, fa.escala_id, fa.nivel, fa.frase
                       FROM frases_asignatura fa
                      WHERE fa.alumno_id IN (?, ?, ?)
                        AND fa.asignatura_id = ? AND fa.periodo_id = ?
                        AND fa.desempeno_id IS NOT NULL
                        AND fa.deleted_at IS NULL
                      ORDER BY fa.id',
            [1, 2, 3, 1, 1]
        );

        $aviso = 'La consulta de las celdas guardadas volvió a recorrer `frases_asignatura` entera. '
            .'La salvan dos cláusulas y hacen falta las dos escritas: `alumno_id IN (…)` '
            .'—los alumnos del grupo, que la rejilla ya tiene— y `desempeno_id IS NOT NULL`. '
            .'Medido: sin ninguna de las dos, `ALL` y 13.218 filas en `simonbolivar`, o `index` '
            .'por la PRIMARY, que es lo mismo con otro nombre.';

        foreach ($plan as $paso) {
            if (str_starts_with((string) $paso->table, '<')) {
                continue;
            }

            // **`index` cuenta como recorrido completo**, y ésa es la mitad que no
            // se ve: con el `ORDER BY` delante MySQL recorre la tabla por la PRIMARY
            // y el `type` deja de decir `ALL` sin que se haya leído una fila menos.
            $this->assertNotContains($paso->type, ['ALL', 'index'], $aviso);

            $this->assertNotEmpty($paso->possible_keys, $aviso);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // El escenario
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un grupo nuevo con su asignatura y tres alumnos, en el año del sujeto.
     *
     * Se monta y no se busca en el seed **por la misma razón que
     * `grupoAjenoDelMismoAnio()`**: «un grupo con desempeños y sin frases» no se
     * saca con un `WHERE`, y creerlo ya ha costado cuatro veces lo mismo en este
     * repositorio. Montándolo, cada caso sabe exactamente qué hay dentro.
     */
    private function unCaso(): object
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $this->token = $this->tokenDe($usuario->username);

        // **Después del login y no antes**: `Services\Login` reescribe
        // `users.periodo_id` al periodo `actual` en cada inicio de sesión, así que
        // el año de antes del login no tiene por qué ser el de después.
        $yearId = (int) DB::selectOne(
            'SELECT p.year_id FROM users u
               INNER JOIN periodos p ON p.id = u.periodo_id
              WHERE u.id = ?',
            [$usuario->id]
        )->year_id;

        $molde = DB::selectOne('SELECT grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
                                ORDER BY id LIMIT 1', [$yearId]);
        $this->assertNotNull($molde, "El seed no tiene ningún grupo en el año {$yearId}.");

        $grupoId = (int) DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo de la rejilla', 'abrev' => 'REJ', 'year_id' => $yearId,
            'grado_id' => $molde->grado_id, 'orden' => 96, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $materia = (int) DB::table('materias')->whereNull('deleted_at')->orderBy('id')->value('id');
        $profesor = (int) DB::table('profesores')->whereNull('deleted_at')->orderBy('id')->value('id');

        $asignaturaId = (int) DB::table('asignaturas')->insertGetId([
            'materia_id' => $materia, 'grupo_id' => $grupoId, 'profesor_id' => $profesor,
            'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('periodos')->where('year_id', $yearId)->update(['profes_pueden_editar_notas' => 1]);

        $periodoId = (int) DB::table('periodos')->where('year_id', $yearId)
            ->whereNull('deleted_at')->orderBy('numero')->orderBy('id')->value('id');

        // Tres alumnos del seed, matriculados en el grupo nuevo. `ORDER BY a.id` es
        // determinista porque `alumnos.id` es único — la trampa del `LIMIT` sobre un
        // join que empata (03 §«ORDER BY que empata») no aplica aquí.
        $alumnos = array_map(
            fn ($fila) => (int) $fila->id,
            DB::select('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 3')
        );

        $this->assertCount(3, $alumnos, 'El seed no tiene tres alumnos.');

        foreach ($alumnos as $alumnoId) {
            DB::table('matriculas')->insert([
                'alumno_id' => $alumnoId, 'grupo_id' => $grupoId, 'estado' => 'MATR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (object) [
            'user_id' => (int) $usuario->id,
            'year_id' => $yearId,
            'grupo_id' => $grupoId,
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoId,
            'alumnos' => $alumnos,
        ];
    }

    /**
     * La escala del año, **puesta por este caso**.
     *
     * Retira las del seed en lógico y pone las que se le pidan, para que ningún caso
     * dependa de que el colegio de prueba tenga cuatro bandas llamadas BAJO, BÁSICO,
     * ALTO y SUPERIOR. `escalas_de_valoracion.desempenio` es texto libre.
     *
     * @param  list<array{string, int, int}>  $bandas  nombre, porc_inicial, porc_final
     * @return array<string, int> nombre => id
     */
    private function conLaEscala(object $caso, array $bandas): array
    {
        DB::table('escalas_de_valoracion')->where('year_id', $caso->year_id)
            ->whereNull('deleted_at')->update(['deleted_at' => now()]);

        $ids = [];
        $orden = 0;

        foreach ($bandas as [$nombre, $inicial, $final]) {
            $ids[$nombre] = (int) DB::table('escalas_de_valoracion')->insertGetId([
                'desempenio' => $nombre,
                'valoracion' => mb_substr($nombre, 0, 2),
                'porc_inicial' => $inicial,
                'porc_final' => $final,
                'orden' => $orden++,
                'perdido' => $orden === 1 ? 1 : 0,
                'year_id' => $caso->year_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * Un desempeño de la asignatura; con `$dueno`, del boletín independiente de ése; con
     * `$competencia`, colgado de ella. **Sin `$competencia` queda suelto, y eso es legal**
     * (D10): es el caso que el boletín imprime al final.
     */
    private function unDesempeno(object $caso, string $texto = 'Desempeño de prueba', ?int $dueno = null, ?int $competencia = null): int
    {
        return (int) DB::table('desempenos')->insertGetId([
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'alumno_id' => $dueno,
            'competencia_id' => $competencia,
            'definicion' => $texto,
            'orden' => 0,
            'por_defecto' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Una competencia del año, **de la materia y el grado de la asignatura del caso**.
     *
     * Las dos columnas no son adorno: `CompetenciasController` sólo acepta colgar un
     * desempeño de una competencia de su mismo año, y el reparto del boletín se hace por
     * el id, así que una competencia de otra materia daría un caso que pasa por motivos
     * que no son el que se quiere comprobar.
     */
    private function unaCompetencia(object $caso, string $texto): int
    {
        $de = DB::selectOne('SELECT a.materia_id, g.grado_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id WHERE a.id = ?', [$caso->asignatura_id]);

        return (int) DB::table('competencias')->insertGetId([
            'year_id' => $caso->year_id,
            'materia_id' => $de->materia_id,
            'grado_id' => $de->grado_id,
            'definicion' => $texto,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** El texto congelado de la competencia en la celda viva de ese alumno. */
    private function competenciaGuardada(object $caso, int $alumnoId): ?string
    {
        $valor = DB::table('frases_asignatura')
            ->where('alumno_id', $alumnoId)
            ->where('asignatura_id', $caso->asignatura_id)
            ->whereNotNull('desempeno_id')
            ->whereNull('deleted_at')
            ->value('competencia');

        return $valor === null ? null : (string) $valor;
    }

    private function conDefinitiva(object $caso, int $alumnoId, float $nota): void
    {
        DB::table('notas_finales')->insert([
            'alumno_id' => $alumnoId,
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'nota' => $nota,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ruta(object $caso): string
    {
        return 'desempenos/rejilla?asignatura_id='.$caso->asignatura_id
            .'&periodo_id='.$caso->periodo_id;
    }

    /** @return array<string, mixed> */
    private function rejilla(object $caso): array
    {
        $r = $this->pedir('getJson', $this->ruta($caso));
        $r->assertStatus(200);

        return $r->json();
    }

    /**
     * @param  array<string, mixed>  $rejilla
     * @return array<string, mixed>
     */
    private function celda(array $rejilla, int $alumnoId, int $desempenoId): array
    {
        foreach ($rejilla['celdas'] as $celda) {
            if ($celda['alumno_id'] === $alumnoId && $celda['desempeno_id'] === $desempenoId) {
                return $celda;
            }
        }

        $this->fail("La rejilla no trae la celda del alumno {$alumnoId} y el desempeño {$desempenoId}. "
            .'Una celda que falta es una casilla que el docente no puede tocar, y no da ningún error.');
    }

    /**
     * Los desempeños que son celda de un alumno.
     *
     * @param  array<string, mixed>  $rejilla
     * @return list<int>
     */
    private function celdasDe(array $rejilla, int $alumnoId): array
    {
        $ids = [];

        foreach ($rejilla['celdas'] as $celda) {
            if ($celda['alumno_id'] === $alumnoId) {
                $ids[] = $celda['desempeno_id'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param  list<array{int, int, ?int}>  $celdas  alumno, desempeño, nivel
     * @return array<string, mixed>
     */
    private function cuerpo(object $caso, array $celdas): array
    {
        return [
            'asignatura_id' => $caso->asignatura_id,
            'periodo_id' => $caso->periodo_id,
            'celdas' => array_map(fn ($c) => [
                'alumno_id' => $c[0], 'desempeno_id' => $c[1], 'escala_id' => $c[2],
            ], $celdas),
        ];
    }

    /** Lo que hay que comparar después de un GET: no qué contestó, **si escribió**. */
    private function cuantasFrases(): int
    {
        return (int) DB::table('frases_asignatura')->whereNull('deleted_at')->count();
    }

    private function pedir(string $verbo, string $ruta, array $cuerpo = [])
    {
        $cabeceras = ['Authorization' => 'Bearer '.$this->token];

        if ($verbo === 'getJson') {
            return $this->getJson("/api/{$ruta}", $cabeceras);
        }

        return $this->{$verbo}("/api/{$ruta}", $cuerpo, $cabeceras);
    }
}
