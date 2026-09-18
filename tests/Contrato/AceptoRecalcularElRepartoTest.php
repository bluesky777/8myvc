<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **El aviso donde duele al encender el reparto `promedio`** — doc 28 §5.5.
 *
 * Cambiar `years.reparto_subunidades` cambia las definitivas guardadas del año. El
 * plan pedía calcular **antes de escribir** cuántas cambian y de cuánto es el salto
 * mayor, enseñarlo, y exigir `acepto_recalcular`: el patrón de `acepto_perder` y
 * `acepto_desviacion`.
 *
 * **No estaba construido, y no era una decisión de nadie.** No aparecía en el
 * código, ni en la lista de cinco pendientes que el commit de la D30 dejó escrita, ni
 * en ningún commit, ni en ninguna parte. Se cayó del encargo — como se cayeron las
 * tres altas del año cerrado y como se cayó la línea de `postStore` que copia la
 * columna al año nuevo. Lo levantó `myvc_front` preguntando **con qué forma escribía
 * su pestaña**, que es la tercera vez en dos días que el hueco lo ve quien está al
 * otro lado del contrato y no quien escribe el código.
 *
 * ## Lo que este fichero comprueba, y por qué no basta con el 422
 *
 * Un test que sólo mire «contesta 422 sin la llave y 200 con ella» dejaría pasar la
 * peor forma de fallar: **que el número del aviso no sea el número real**. Una cifra
 * que exagera se aprende a ignorar y entonces el aviso deja de avisar — es lo que
 * dice `EscalasDeValoracionController::avisarDeLoQueArrastra` de su propia cuenta.
 *
 * Por eso el caso central **acepta el cambio y cuenta las definitivas que de verdad
 * se movieron**, recalculando después, y las compara con lo que el 422 había
 * prometido. Si alguien simplifica la consulta —quitarle el alcance del boletín
 * independiente, o dejar dentro las `manual`— ese caso cae y los demás no.
 */
class AceptoRecalcularElRepartoTest extends CasoDeContrato
{
    /** Cuatro subunidades con pesos desiguales: es lo que hace que los modos difieran. */
    private const PESOS = [40, 30, 20, 10];

    private const NOTAS = [40, 40, 20, 20];

    // ── El aviso ─────────────────────────────────────────────────────────────

    /**
     * **Sin la llave: 422, con los números, y la columna intacta.**
     *
     * Se mira la fila después, que es la regla de esta casa: un 422 que ya escribió
     * sería peor que un 200 honesto y los dos se ven igual mirando el status.
     */
    #[Test]
    public function encender_el_promedio_avisa_de_cuantas_definitivas_recalcula(): void
    {
        $ctx = $this->anioConDefinitivasGuardadas();

        $r = $this->pedirElReparto($ctx['year'], RepartoDeLaNota::PROMEDIO);

        $r->assertStatus(422);

        // **La cuenta es de TODO el año**, no del montaje: en la base de tests son
        // **412** con el seed de hoy. Se afirma un suelo y no el 412 exacto porque el
        // seed se regenera y esa cifra se movería sin que nada estuviera mal — pero el
        // suelo sí importa: con 1 el caso pasaría por casualidad, porque casi
        // cualquier consulta equivocada devuelve 1.
        $this->assertGreaterThan(1, (int) $r->json('definitivas'),
            'El aviso salió con menos de dos definitivas: con una sola, este caso pasaría por '
            .'casualidad y no comprobaría que la cuenta cuenta.');

        $this->assertGreaterThan(0, (float) $r->json('salto_mayor'),
            'Dice que cambian definitivas y que ninguna se mueve: una de las dos cifras está mal.');

        $this->assertSame('porcentaje', $r->json('de'));
        $this->assertSame('promedio', $r->json('a'));

        $this->assertSame('porcentaje',
            DB::table('years')->where('id', $ctx['year'])->value('reparto_subunidades'),
            'Contestó 422 y cambió el reparto igual: el aviso frena la respuesta pero no la escritura.');
    }

    /**
     * **El número del aviso es el número real**, y esto es el caso que de verdad
     * defiende la cuenta.
     *
     * Se acepta el cambio, se recalculan las asignaturas del año y se cuenta cuántas
     * definitivas quedaron con otro valor. Tiene que ser **exactamente** lo que el
     * 422 había prometido — y el salto mayor, el mismo.
     *
     * Sin esto, la consulta del aviso puede simplificarse «sin que falle nada»:
     * quitarle el alcance del boletín independiente, dejar dentro las `manual` que
     * el servicio no reescribe, o comparar los dos modos entre sí en vez de comparar
     * contra lo guardado. Las tres dan un número distinto y **ninguna da error**.
     */
    #[Test]
    public function lo_que_el_aviso_promete_es_lo_que_luego_cambia(): void
    {
        $ctx = $this->anioConDefinitivasGuardadas();

        $prometido = $this->pedirElReparto($ctx['year'], RepartoDeLaNota::PROMEDIO);
        $prometido->assertStatus(422);

        $cuantas = (int) $prometido->json('definitivas');
        $salto = (float) $prometido->json('salto_mayor');

        $antes = $this->definitivasDelAnio($ctx['year']);

        $this->olvidarControladores();

        $this->pedirElReparto($ctx['year'], RepartoDeLaNota::PROMEDIO, ['acepto_recalcular' => true])
            ->assertStatus(200);

        // **Desde el 17 sep 2026 el cambio de modo YA recalcula por sí solo**, así que
        // este bucle es idempotente y no mueve nada. Se conserva a propósito y no se
        // borra: es lo que hace que este test siga midiendo **el aviso** —que la cifra
        // prometida coincida con las que se mueven— y no el momento en que se mueven.
        //
        // Decía «el cambio de modo NO recalcula por sí solo, este método guarda el año
        // y se va», y era cierto: el 422 contaba las definitivas que iban a cambiar y
        // **nadie las cambiaba**, así que lo que producía era deriva. Ese agujero se
        // cerró con el recorrido del [10](../../docs/migracion/10-definitivas.md), y
        // quien lo fije es `DefinitivasQueSeCalculanSolasTest`.
        //
        // Que el bucle sobre aquí **no es ruido, es el control**: si algún día el
        // recálculo del año dejara de alcanzar a alguna asignatura, esto lo taparía
        // recalculándola a mano y la cuenta seguiría cuadrando. Por eso el caso que
        // comprueba que el endpoint recalcula vive en el otro fichero y **sin** bucle.
        foreach ($this->asignaturasYPeriodosDelAnio($ctx['year']) as $par) {
            DefinitivasDeAsignatura::recalcular((int) $par->asignatura_id, (int) $par->periodo_id, $ctx['user']);
        }

        $despues = $this->definitivasDelAnio($ctx['year']);

        $movidas = 0;
        $mayor = 0.0;

        foreach ($despues as $clave => $valor) {
            if (! isset($antes[$clave])) {
                continue;
            }

            $dif = abs($valor - $antes[$clave]);

            if ($dif > 0.00005) {
                $movidas++;
                $mayor = max($mayor, $dif);
            }
        }

        $this->assertSame($cuantas, $movidas,
            "El aviso prometió {$cuantas} definitivas y se movieron {$movidas}.\n"
            .'Una cifra que exagera se aprende a ignorar, y entonces el aviso deja de avisar.');

        $this->assertEqualsWithDelta($salto, $mayor, 0.01,
            "El aviso prometió un salto mayor de {$salto} y el real fue {$mayor}.");

        // **Y la `manual` no se movió**, que es la premisa de la que vive la cuenta.
        // El aserto de arriba ya caería si el aviso la hubiera contado —prometería una
        // de más—, pero esto dice **por qué** en vez de dejarlo deducir: si algún día
        // `DefinitivasDeAsignatura` dejara de respetarlas, el fallo saldría aquí
        // señalando al servicio y no a la consulta del aviso, que es donde no está.
        $clave = $ctx['manual'].':'.$ctx['asignatura'].':'.$ctx['periodo'];

        $this->assertArrayHasKey($clave, $despues, 'La definitiva `manual` desapareció del año.');
        $this->assertEqualsWithDelta($antes[$clave], $despues[$clave], 0.00005,
            'La definitiva marcada `manual` se movió al recalcular. El servicio promete no '
            .'tocarlas (`DefinitivasDeAsignatura:363`) y el aviso cuenta con ello para no '
            .'prometer un cambio que no ocurre.');
    }

    /**
     * **Con la llave pasa, y escribe.**
     *
     * Éste es el que impide que el aviso se convierta en una prohibición: sin él,
     * alguien que cambiara el 422 por un 403 vería el fichero en verde salvo aquí.
     */
    #[Test]
    public function con_acepto_recalcular_el_reparto_cambia(): void
    {
        $ctx = $this->anioConDefinitivasGuardadas();

        $this->pedirElReparto($ctx['year'], RepartoDeLaNota::PROMEDIO, ['acepto_recalcular' => true])
            ->assertStatus(200);

        $this->assertSame('promedio',
            DB::table('years')->where('id', $ctx['year'])->value('reparto_subunidades'));
    }

    /**
     * **Reenviar el mismo modo no es un cambio y no pide confirmación.**
     *
     * La pantalla que guarda la configuración del año manda la fila entera; si
     * `reparto_subunidades` pidiera aceptación por venir con el valor que ya tiene,
     * el colegio vería el aviso al tocar cualquier otra cosa — y un aviso que sale
     * cuando no pasa nada es la forma más rápida de que se deje de leer.
     */
    #[Test]
    public function reenviar_el_mismo_reparto_no_pide_nada(): void
    {
        $ctx = $this->anioConDefinitivasGuardadas();

        $this->pedirElReparto($ctx['year'], RepartoDeLaNota::PORCENTAJE)->assertStatus(200);
    }

    /**
     * **Y sin nada que recalcular tampoco hay aviso.**
     *
     * Un año sin definitivas guardadas no tiene de qué avisar. Se comprueba sobre un
     * año que **sí existe y sí tiene periodos** —no sobre uno inventado— para que el
     * verde no venga de un 404.
     */
    #[Test]
    public function un_anio_sin_definitivas_no_pide_nada(): void
    {
        $year = DB::selectOne('SELECT y.id FROM years y
            WHERE y.deleted_at IS NULL
              AND NOT EXISTS (
                SELECT 1 FROM notas_finales nf
                 INNER JOIN periodos p ON p.id = nf.periodo_id
                 WHERE p.year_id = y.id)
            ORDER BY y.id LIMIT 1');

        if ($year === null) {
            $this->markTestSkipped('Todos los años del seed tienen definitivas.');
        }

        $this->pedirElReparto((int) $year->id, RepartoDeLaNota::PROMEDIO)->assertStatus(200);

        $this->assertSame('promedio',
            DB::table('years')->where('id', $year->id)->value('reparto_subunidades'));
    }

    /**
     * **`acepto_recalcular` no se abre con cualquier cadena.**
     *
     * Es `tools/verdad-laxa-que-escribe.py`: sin `FILTER_NULL_ON_FAILURE`, un
     * `"no"` valdría por «sí» y gobernaría el recálculo de un año entero. Se
     * comprueba con las dos formas que más engañan —la que parece un no y la que no
     * es ninguna de las dos—.
     */
    #[Test]
    public function la_llave_no_se_abre_con_cualquier_cosa(): void
    {
        $ctx = $this->anioConDefinitivasGuardadas();

        // `"false"` es un NO explícito: tiene que seguir saliendo el aviso, no un 200.
        $this->pedirElReparto($ctx['year'], RepartoDeLaNota::PROMEDIO, ['acepto_recalcular' => 'false'])
            ->assertStatus(422);

        $this->assertSame('porcentaje',
            DB::table('years')->where('id', $ctx['year'])->value('reparto_subunidades'),
            'Un `"false"` valió por «sí» y cambió el reparto del año.');

        $this->olvidarControladores();

        // Y una cadena que no es ni sí ni no se rechaza diciéndolo, en vez de
        // adivinar. Con la verdad laxa de PHP, `"quizá"` es `true`.
        $this->pedirElReparto($ctx['year'], RepartoDeLaNota::PROMEDIO, ['acepto_recalcular' => 'quizá'])
            ->assertStatus(422);

        $this->assertSame('porcentaje',
            DB::table('years')->where('id', $ctx['year'])->value('reparto_subunidades'),
            'Una cadena cualquiera valió por «sí» y cambió el reparto del año.');
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /** Con el token de un superusuario, que es quien puede el primer día. */
    private function pedirElReparto(int $yearId, string $modo, array $extra = [])
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser);

        return $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion',
                array_merge(['year_id' => $yearId, 'reparto_subunidades' => $modo], $extra));
    }

    /** Las definitivas del año, por (alumno, asignatura, periodo). */
    private function definitivasDelAnio(int $yearId): array
    {
        $filas = DB::select('SELECT nf.alumno_id, nf.asignatura_id, nf.periodo_id, nf.nota
            FROM notas_finales nf
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            WHERE p.year_id = ?', [$yearId]);

        $salida = [];

        foreach ($filas as $fila) {
            $salida[$fila->alumno_id.':'.$fila->asignatura_id.':'.$fila->periodo_id] = (float) $fila->nota;
        }

        return $salida;
    }

    /**
     * Los pares (asignatura, periodo) del año que tienen definitivas.
     *
     * `array` y no `list<object>`: `DB::select` devuelve `array` a secas y larastan
     * nivel 7 no da por supuesto que las claves vayan de 0 en adelante. Aquí sólo se
     * recorre con `foreach`, así que da igual — pero la anotación se ajusta a lo que
     * es en vez de callarse el aviso.
     *
     * @return array<int, object>
     */
    private function asignaturasYPeriodosDelAnio(int $yearId): array
    {
        return DB::select('SELECT DISTINCT nf.asignatura_id, nf.periodo_id
            FROM notas_finales nf
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            WHERE p.year_id = ?', [$yearId]);
    }

    /**
     * Un año con una asignatura montada de **pesos desiguales** y sus definitivas ya
     * guardadas en modo `porcentaje`.
     *
     * Se monta en vez de buscarse por lo de siempre: con los porcentajes del seed la
     * aritmética deja de poder leerse. Pero aquí hay una razón más y es la que
     * decide el fichero — **el seed podría no tener ni una unidad con pesos
     * desiguales**, y entonces los dos modos darían el mismo número, no cambiaría
     * ninguna definitiva y **todos estos casos pasarían en verde sin aviso ninguno**.
     * Lo que se monta no es comodidad: es la premisa.
     *
     * @return array<string, mixed>
     */
    private function anioConDefinitivasGuardadas(): array
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, u.id AS user_id, un.periodo_id, g.year_id
            FROM asignaturas a
            INNER JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
            INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR", "ASIS", "PREM")
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene una asignatura con profesor, unidades y matrículas.');

        // **TRES alumnos con notas, y no uno.** Con uno solo el aviso diría «1» y una
        // consulta equivocada también podría decir «1»: el caso pasaría sin haber
        // comprobado que la cuenta cuenta. Con tres —y uno de ellos `manual`— el
        // número correcto es 2, que no sale por casualidad de ninguna simplificación.
        $alumnos = DB::select('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR", "ASIS", "PREM") ORDER BY m.id LIMIT 3', [$fila->grupo_id]);

        $this->assertCount(3, $alumnos, 'El montaje necesita tres alumnos matriculados.');

        DB::table('unidades')
            ->where('asignatura_id', $fila->asignatura_id)
            ->where('periodo_id', $fila->periodo_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $fila->asignatura_id,
            'periodo_id' => $fila->periodo_id,
            'definicion' => 'UNIDAD DEL AVISO',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::PESOS as $i => $peso) {
            $subId = DB::table('subunidades')->insertGetId([
                'unidad_id' => $unidadId,
                'definicion' => 'SUB '.($i + 1),
                'porcentaje' => $peso,
                'orden' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($alumnos as $alumno) {
                DB::table('notas')->insert([
                    'subunidad_id' => $subId,
                    'alumno_id' => $alumno->alumno_id,
                    'nota' => self::NOTAS[$i],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // El año arranca en `porcentaje` —el defecto— y con las definitivas ya
        // escritas: sin esto no habría nada guardado con lo que comparar y el aviso
        // diría cero.
        DB::table('years')->where('id', $fila->year_id)->update(['reparto_subunidades' => 'porcentaje']);

        // **Y el SEGUNDO va por boletín independiente, con su propia unidad.**
        //
        // Sin esto, el alcance que la consulta del aviso lleva dentro
        // —`BoletinIndependiente::alcanceCorrelacionado`, el mismo que usa el servicio
        // que escribe— **no lo comprobaría nada**: se midió el 15 sep quitándolo y los
        // seis casos seguían en verde, porque el seed no tiene ni un alumno marcado en
        // ese año. Un alcance sin test es un alcance que el siguiente simplifica.
        //
        // Con el alumno marcado, su definitiva sale de SU unidad y no de la del grupo.
        // Si la consulta se olvida del `<=>`, la derivada le devuelve las dos filas
        // —la suya y la del grupo— y la cuenta deja de cuadrar con lo que se mueve.
        $this->marcarIndependiente((int) $alumnos[1]->alumno_id, (int) $fila->periodo_id);

        $suya = DB::table('unidades')->insertGetId([
            'asignatura_id' => $fila->asignatura_id,
            'periodo_id' => $fila->periodo_id,
            'alumno_id' => $alumnos[1]->alumno_id,
            'definicion' => 'UNIDAD PROPIA DEL INDEPENDIENTE',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pesos desiguales también aquí, y distintos de los del grupo: así su
        // definitiva se mueve al girar el interruptor **y** con otro valor que la de
        // sus compañeros, que es lo que distingue haber aplicado el alcance de no.
        foreach ([60, 25, 15] as $i => $peso) {
            $subSuya = DB::table('subunidades')->insertGetId([
                'unidad_id' => $suya,
                'definicion' => 'SUB PROPIA '.($i + 1),
                'porcentaje' => $peso,
                'orden' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('notas')->insert([
                'subunidad_id' => $subSuya,
                'alumno_id' => $alumnos[1]->alumno_id,
                'nota' => [45, 25, 10][$i],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DefinitivasDeAsignatura::recalcular(
            (int) $fila->asignatura_id, (int) $fila->periodo_id, (int) $fila->user_id
        );

        // **Y el tercero se marca `manual` DESPUÉS de recalcular**, que es como llega
        // a estarlo en la vida real: alguien la puso a mano sobre una calculada. El
        // servicio no la reescribe (`DefinitivasDeAsignatura:363`), así que el aviso
        // no puede prometer que cambie — y si la cuenta se olvida de excluirla, dirá
        // 3 donde se mueven 2.
        DB::table('notas_finales')
            ->where('alumno_id', $alumnos[2]->alumno_id)
            ->where('asignatura_id', $fila->asignatura_id)
            ->where('periodo_id', $fila->periodo_id)
            ->update(['manual' => 1]);

        return [
            'year' => (int) $fila->year_id,
            'user' => (int) $fila->user_id,
            'asignatura' => (int) $fila->asignatura_id,
            'periodo' => (int) $fila->periodo_id,
            'alumno' => (int) $alumnos[0]->alumno_id,
            'manual' => (int) $alumnos[2]->alumno_id,
        ];
    }
}
