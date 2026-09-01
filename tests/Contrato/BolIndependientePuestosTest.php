<?php

namespace Tests\Contrato;

use App\Models\Nota;
use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * El interruptor de los puestos, en sus dos valores — **fase 6** del
 * [19](../../docs/migracion/19-boletin-independiente.md), §7.
 *
 * ## Lo que este fichero comprueba y ningún otro puede
 *
 * **Que el puesto de un TERCERO cambia.** Es el efecto que nadie espera y el único
 * que demuestra que el interruptor hace algo: un puesto no es una nota, es una
 * posición relativa, así que sacar a UN alumno del recuento **le cambia el número a
 * los treinta de detrás**, en pantalla y en el papel impreso. Quien lea «el
 * independiente no cuenta» y no piense en los otros treinta va a escribir el arreglo
 * que sólo pone a `null` el puesto del marcado — y ese arreglo pasa cualquier test
 * que sólo mire al marcado.
 *
 * ## Y por qué está escrito así, con la mitad por HTTP
 *
 * `test_apagado_los_demas_suben_un_puesto_en_el_boletin_impreso` va contra
 * `PUT boletines/detailed-notas/{grupo}` y **es el único de la noche que se puede
 * correr contra el código viejo**: los otros llaman a `ponerPuestos()`, que antes no
 * existía. Contra el código de antes de este lote ese test se pone **rojo**, porque
 * el `foreach` que había contaba sobre `$alumnos` entero y el interruptor no existía.
 * Comprobado, y no como formalidad: con nadie marcado la forma correcta y la
 * incorrecta dan el mismo verde, que es la regla de la noche.
 *
 * ## La población, dicha y no supuesta
 *
 * Los tres tests que miden el efecto **fallan con un mensaje explícito si el grupo
 * del seed tiene los promedios empatados o un solo alumno**: un grupo donde todos
 * van primeros cuadra con y sin interruptor, y un test que pasa sobre él no está
 * comprobando nada. Es la regla del `CLAUDE.md` — ninguna medición dice OK sin decir
 * sobre cuántos.
 */
class BolIndependientePuestosTest extends CasoDeContrato
{
    protected function setUp(): void
    {
        parent::setUp();

        // La memoria del servicio es por petición y una suite es un proceso. Aquí
        // hay DOS memorias que olvidar —el alcance y el interruptor— y la segunda es
        // la que muerde: un test que lo apaga le dejaría la respuesta cacheada al
        // siguiente, y el resultado dependería del orden de la suite.
        BoletinIndependiente::olvidar();
    }

    /** Apaga el interruptor de un año. Es lo que haría el colegio desde sus ajustes. */
    private function apagarLosPuestos(int $yearId): void
    {
        DB::update('UPDATE years SET puestos_con_bol_independiente = 0 WHERE id = ?', [$yearId]);

        BoletinIndependiente::olvidar();
    }

    /**
     * Una lista de alumnos de mentira con promedios distintos, sobre alumnos de
     * verdad.
     *
     * Los `alumno_id` tienen que ser reales porque `bol_ind_periodos` tiene clave
     * foránea contra `alumnos`; los promedios no, porque `ponerPuestos` sólo lee esas
     * dos propiedades. Fabricarlos es lo que permite fijar **quién va primero** sin
     * depender de las notas que el seed tenga ese día.
     *
     * @return list<object>
     */
    private function tresAlumnosConPromediosDistintos(int $grupoId): array
    {
        $filas = DB::select(
            'SELECT alumno_id FROM matriculas
              WHERE grupo_id = ? AND estado IN ("MATR","ASIS") AND deleted_at IS NULL
              ORDER BY alumno_id LIMIT 3',
            [$grupoId]
        );

        $this->assertCount(3, $filas,
            'Este test necesita TRES alumnos matriculados en el grupo '.$grupoId.'. '
            .'Con menos no hay «un tercero» a quien cambiarle el puesto, que es justo lo que mide.');

        // 90, 80 y 70: el primero gana a los otros dos, y sacarlo del recuento tiene
        // que subir a los dos de detrás. Empatarlos escondería el efecto.
        return [
            (object) ['alumno_id' => (int) $filas[0]->alumno_id, 'promedio' => 90.0],
            (object) ['alumno_id' => (int) $filas[1]->alumno_id, 'promedio' => 80.0],
            (object) ['alumno_id' => (int) $filas[2]->alumno_id, 'promedio' => 70.0],
        ];
    }

    /**
     * El default es 1 y eso es «lo de hoy».
     *
     * Si esto falla, la migración nació con el sentido cambiado y **los quince
     * colegios estrenan el despliegue con los puestos movidos sin haberlo pedido**.
     * Es el mismo modo de fallo que el `COALESCE(bip.aplica, 1)` de la decisión 7:
     * un carácter que cambia el significado entero y no da ningún error.
     */
    public function test_el_interruptor_nace_encendido_en_todos_los_anios(): void
    {
        $apagados = DB::selectOne(
            'SELECT COUNT(*) AS n FROM years WHERE puestos_con_bol_independiente <> 1'
        )->n;

        $total = DB::selectOne('SELECT COUNT(*) AS n FROM years')->n;

        $this->assertGreaterThan(0, (int) $total, 'El seed no tiene años: esta comprobación no mide nada.');

        $this->assertSame(0, (int) $apagados,
            "La columna nace en 1 por `DEFAULT 1`. Hay {$apagados} de {$total} años con otro valor: "
            .'con eso, el despliegue le cambiaría el puesto impreso a colegios que no lo han pedido.');
    }

    /**
     * Un año que no existe cuenta como «lo de hoy», y es deliberado.
     *
     * `selectOne` sin fila devuelve `null`; devolver `false` ahí apagaría los puestos
     * de un informe entero por un `year_id` malo, **y nadie mira un puesto y piensa
     * «esto es que el año no existe»**.
     */
    public function test_un_anio_que_no_existe_cuenta_como_encendido(): void
    {
        $this->assertTrue(BoletinIndependiente::puestosCuentanIndependientes(999999));
    }

    /**
     * Encendido —el default— el puesto es **exactamente** el de antes de este lote.
     *
     * Es el criterio de aceptación de la §4 aplicado a este lote: con la migración
     * puesta y el interruptor donde nace, `ponerPuestos()` tiene que dar fila por fila
     * lo mismo que daba el `foreach` que había, **incluso con alguien marcado**.
     */
    public function test_encendido_el_marcado_cuenta_como_cualquiera(): void
    {
        $grupo = $this->grupoConAlumnos();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);
        $alumnos = $this->tresAlumnosConPromediosDistintos((int) $grupo->id);

        $this->marcarIndependiente($alumnos[0]->alumno_id, $periodos[0]);

        BoletinIndependiente::ponerPuestos($alumnos, [$periodos[0]], (int) $grupo->year_id);

        foreach ($alumnos as $alumno) {
            $this->assertSame(
                Nota::puestoAlumno($alumno->promedio, $alumnos),
                $alumno->puesto,
                'Con el interruptor encendido el puesto tiene que ser el que da `Nota::puestoAlumno` '
                .'sobre la lista entera. Si difiere, este lote cambió lo de hoy en los quince colegios.'
            );
        }

        $this->assertSame([1, 2, 3], array_column($alumnos, 'puesto'));
    }

    /**
     * **EL TEST DE ESTE LOTE: apagado, al TERCERO le cambia el puesto.**
     *
     * El marcado va primero. Al sacarlo del recuento, los dos de detrás **suben uno**
     * — y ninguno de los dos está marcado ni tiene nada que ver con el boletín
     * independiente. Ése es el efecto que la §7 pide decir en voz alta y el que hace
     * que cambiar el interruptor sea una decisión del colegio y no un ajuste de
     * pantalla.
     *
     * Un arreglo que sólo pusiera a `null` el puesto del marcado dejaría a estos dos
     * en 2 y 3, y pasaría cualquier test que mirase únicamente al marcado.
     */
    public function test_apagado_el_puesto_de_un_tercero_sube(): void
    {
        $grupo = $this->grupoConAlumnos();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);
        $alumnos = $this->tresAlumnosConPromediosDistintos((int) $grupo->id);

        $this->marcarIndependiente($alumnos[0]->alumno_id, $periodos[0]);
        $this->apagarLosPuestos((int) $grupo->year_id);

        BoletinIndependiente::ponerPuestos($alumnos, [$periodos[0]], (int) $grupo->year_id);

        $this->assertNull($alumnos[0]->puesto,
            'El boletín de un independiente lleva `puesto: null`, que el front pinta `—` (decisión 6). '
            .'Calcularle un puesto contra una lista de la que se le acaba de sacar sería inventarlo.');

        $this->assertSame(1, $alumnos[1]->puesto,
            'ÉSTE es el efecto que este lote existe para demostrar: el segundo era segundo con el '
            .'interruptor encendido y pasa a primero al apagarlo, sin estar marcado y sin que nadie '
            .'le haya tocado una nota.');

        $this->assertSame(2, $alumnos[2]->puesto,
            'Y el tercero pasa a segundo. Si sigue en 3, el arreglo sólo puso a null el puesto del '
            .'marcado y dejó al independiente contando para los demás — que es la mitad que se ve '
            .'en el papel impreso.');
    }

    /**
     * La marca es POR PERIODO también aquí, y esto lo separa del resto.
     *
     * Un informe de un periodo pregunta por **ese** periodo. Quien fue independiente
     * en el segundo cuenta con normalidad en el tercero: es la decisión 7 —«tuvo un
     * periodo normal y en el segundo un accidente, tienen que convivir»— aplicada al
     * puesto. Sin este test, leer la marca por año pasaría desapercibido porque el
     * test de arriba marca y pregunta por el mismo periodo.
     */
    public function test_apagado_la_marca_de_otro_periodo_no_cambia_este(): void
    {
        $grupo = $this->grupoConAlumnos();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);

        $this->assertGreaterThanOrEqual(2, count($periodos),
            'El año del grupo '.$grupo->id.' necesita al menos dos periodos para que «otro periodo» exista.');

        $alumnos = $this->tresAlumnosConPromediosDistintos((int) $grupo->id);

        // Marcado en el SEGUNDO periodo; el informe es del PRIMERO.
        $this->marcarIndependiente($alumnos[0]->alumno_id, $periodos[1]);
        $this->apagarLosPuestos((int) $grupo->year_id);

        BoletinIndependiente::ponerPuestos($alumnos, [$periodos[0]], (int) $grupo->year_id);

        $this->assertSame([1, 2, 3], array_column($alumnos, 'puesto'),
            'Con el interruptor apagado pero la marca en OTRO periodo, el puesto del primer periodo '
            .'no cambia. Si cambia, la marca se está leyendo por año y la decisión 7 quedó muerta.');
    }

    /**
     * Y el informe que promedia VARIOS periodos sí la ve.
     *
     * El de promoción y el de certificados promedian el año: la definitiva del periodo
     * que el alumno pasó aparte **no se calculó sobre el reparto del grupo**, así que
     * su promedio anual no es comparable aunque hoy vuelva a ir con el grupo.
     */
    public function test_apagado_un_informe_de_varios_periodos_ve_la_marca_de_cualquiera(): void
    {
        $grupo = $this->grupoConAlumnos();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);
        $alumnos = $this->tresAlumnosConPromediosDistintos((int) $grupo->id);

        $this->marcarIndependiente($alumnos[0]->alumno_id, $periodos[1]);
        $this->apagarLosPuestos((int) $grupo->year_id);

        BoletinIndependiente::ponerPuestos($alumnos, $periodos, (int) $grupo->year_id);

        $this->assertNull($alumnos[0]->puesto);
        $this->assertSame(1, $alumnos[1]->puesto);
        $this->assertSame(2, $alumnos[2]->puesto);
    }

    /**
     * El grupo, el token de personal de su año y el periodo de ese usuario.
     *
     * El periodo tiene que ser **el del usuario** y no el primero del año: los tres
     * boletines calculan contra `$user->periodo_id`, así que marcar en otro dejaría el
     * escenario montado en un periodo que la respuesta no mira — pasaría con el
     * código viejo y con el nuevo, sin medir nada.
     *
     * @return array{object, string, int}
     */
    private function escenarioDeBoletin(): array
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.username, u.periodo_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene ningún Usuario en el año {$grupo->year_id}.");

        return [$grupo, $this->tokenDe($usuario->username), (int) $usuario->periodo_id];
    }

    /** @return array<int, ?int> alumno_id => puesto, tal como sale del boletín */
    private function puestosDelBoletin(object $grupo, string $token): array
    {
        $r = $this->withToken($token)->putJson("/api/boletines/detailed-notas/{$grupo->id}");

        $this->assertSame(200, $r->status(), 'El boletín de grupo tiene que contestar 200.');

        $cuerpo = $r->json();

        // `detailedNotasGrupo` devuelve `array($grupo, $year, $alumnos, $escalas)`, así
        // que los alumnos son el índice 2. No es una forma bonita y no se cambia aquí:
        // es la que el front lleva leyendo desde siempre.
        $this->assertArrayHasKey(2, $cuerpo, 'El boletín no trae la lista de alumnos donde la trae siempre.');

        $puestos = [];
        foreach ($cuerpo[2] as $alumno) {
            $puestos[(int) $alumno['alumno_id']] = $alumno['puesto'] === null ? null : (int) $alumno['puesto'];
        }

        return $puestos;
    }

    /**
     * **EL MISMO EFECTO, PERO EN EL PAPEL IMPRESO Y POR HTTP.**
     *
     * Éste es el que se puede correr contra el código de antes de este lote, y por eso
     * existe además del de arriba: los otros llaman a `ponerPuestos()`, que antes no
     * estaba, así que no distinguen «el arreglo funciona» de «el arreglo existe».
     *
     * Se marca al alumno que va **primero** con el interruptor encendido, y se
     * comprueba que al apagarlo los demás bajan su número exactamente en uno **si él
     * les ganaba**. La fórmula, y no un `-1` a secas, porque `puestoAlumno` cuenta
     * `1 + cuántos me ganan`: si alguien tenía el mismo promedio o más, no se mueve.
     */
    public function test_apagado_los_demas_suben_un_puesto_en_el_boletin_impreso(): void
    {
        [$grupo, $token, $periodoId] = $this->escenarioDeBoletin();

        $antes = $this->puestosDelBoletin($grupo, $token);

        $this->assertGreaterThanOrEqual(2, count($antes),
            'El boletín del grupo '.$grupo->id.' devolvió '.count($antes).' alumno(s). '
            .'Con menos de dos no hay «los demás» a quien cambiarle el puesto.');

        // El que va primero: el que más puestos reparte al irse.
        $primeros = array_keys($antes, 1, true);
        $this->assertNotEmpty($primeros, 'Nadie va primero: la respuesta no trae puestos.');
        $marcado = $primeros[0];

        $detras = count(array_filter($antes, static fn ($p) => $p !== null && $p > 1));
        $this->assertGreaterThan(0, $detras,
            'Los '.count($antes).' alumnos del grupo van todos primeros —promedios empatados en el '
            .'seed—, así que sacar a uno no le cambia el puesto a nadie y este test no mediría nada. '
            .'Población hoy: '.count($antes).' alumnos, '.$detras.' por detrás del primero.');

        $this->marcarIndependiente($marcado, $periodoId);
        $this->apagarLosPuestos((int) $grupo->year_id);

        $despues = $this->puestosDelBoletin($grupo, $token);

        $this->assertNull($despues[$marcado],
            'El independiente que no cuenta lleva `puesto: null` — el front pinta `—` (decisión 6).');

        $movidos = 0;
        foreach ($antes as $alumnoId => $puesto) {
            if ($alumnoId === $marcado) {
                continue;
            }

            // Le ganaba si el marcado tenía mejor puesto que él, o sea uno más bajo.
            $esperado = $puesto > $antes[$marcado] ? $puesto - 1 : $puesto;

            $this->assertSame($esperado, $despues[$alumnoId],
                "Al alumno {$alumnoId}, que NO está marcado, le tenía que quedar el puesto {$esperado} "
                .'al sacar del recuento a quien le ganaba. Si se queda como estaba, el interruptor '
                .'sólo anuló el puesto del marcado y lo dejó contando para los demás.');

            $movidos += $esperado === $puesto ? 0 : 1;
        }

        $this->assertGreaterThan(0, $movidos,
            'Ningún tercero cambió de puesto. Sobre '.count($antes).' alumnos, este test no está '
            .'midiendo el efecto que dice medir.');
    }

    /**
     * El interruptor viaja en la respuesta de los DOS informes de puestos.
     *
     * Desde el front son cuatro pantallas colgando de estas dos rutas. Si el
     * interruptor lo preguntara cada una por su cuenta, basta con que una se olvide
     * para que **las otras tres mientan** — enseñarían una tabla sin decir que falta
     * gente (§7).
     */
    public function test_el_interruptor_viaja_en_los_dos_informes_de_puestos(): void
    {
        [$grupo, $token] = $this->escenarioDeBoletin();

        foreach ($this->respuestasDePuestos($grupo, $token) as $ruta => $cuerpo) {
            $this->assertArrayHasKey('puestos_con_bol_independiente', $cuerpo,
                "`{$ruta}` no manda el interruptor. La pantalla no puede explicar por qué falta alguien.");

            $this->assertTrue($cuerpo['puestos_con_bol_independiente'],
                "`{$ruta}` tiene que mandarlo encendido mientras el año lo esté, que es el default.");
        }
    }

    /**
     * Apagado, el independiente **no sale** en la tabla de puestos — y sale con la
     * lista más corta, no con una fila marcada.
     *
     * Aquí el puesto lo cuenta el front, **sobre el array que le llega** (el filtro
     * `puestoAlumno` de `myvc_front`). Mandarlo y esperar que el navegador lo descarte
     * sería la misma regla escrita en dos sitios, que es de lo que salió el
     * recalculador único de las definitivas.
     */
    public function test_apagado_el_independiente_no_sale_en_la_tabla_de_puestos(): void
    {
        [$grupo, $token, $periodoId] = $this->escenarioDeBoletin();

        $marcado = (int) DB::selectOne(
            'SELECT alumno_id FROM matriculas
              WHERE grupo_id = ? AND estado IN ("MATR","ASIS") AND deleted_at IS NULL
              ORDER BY alumno_id LIMIT 1',
            [$grupo->id]
        )->alumno_id;

        $antes = $this->respuestasDePuestos($grupo, $token);

        foreach ($antes as $ruta => $cuerpo) {
            $this->assertContains($marcado, array_column($cuerpo['alumnos'], 'alumno_id'),
                "Con el interruptor encendido, `{$ruta}` tiene que seguir trayendo a todo el mundo.");
        }

        $this->marcarIndependiente($marcado, $periodoId);
        $this->apagarLosPuestos((int) $grupo->year_id);

        foreach ($this->respuestasDePuestos($grupo, $token) as $ruta => $cuerpo) {
            $this->assertFalse($cuerpo['puestos_con_bol_independiente'],
                "`{$ruta}` sigue diciendo que el interruptor está encendido después de apagarlo.");

            $this->assertNotContains($marcado, array_column($cuerpo['alumnos'], 'alumno_id'),
                "`{$ruta}` sigue mandando al independiente con el interruptor apagado. El front cuenta "
                .'el puesto sobre el array que recibe, así que mandarlo es dejarlo contando.');

            $this->assertCount(count($antes[$ruta]['alumnos']) - 1, $cuerpo['alumnos'],
                "`{$ruta}` tiene que traer exactamente un alumno menos, no cero ni la lista entera.");
        }
    }

    /**
     * Las dos rutas de puestos, con su nombre, para que el mensaje de un fallo diga
     * cuál de las dos fue.
     *
     * @return array<string, array<string, mixed>>
     */
    private function respuestasDePuestos(object $grupo, string $token): array
    {
        $salida = [];

        $r = $this->withToken($token)->putJson("/api/puestos/detailed-notas-periodo/{$grupo->id}");
        $this->assertSame(200, $r->status(), '`puestos/detailed-notas-periodo` tiene que contestar 200.');
        $salida['puestos/detailed-notas-periodo'] = $r->json();

        $r = $this->withToken($token)->putJson('/api/puestos/detailed-notas-year', ['grupo_id' => $grupo->id]);
        $this->assertSame(200, $r->status(), '`puestos/detailed-notas-year` tiene que contestar 200.');
        $salida['puestos/detailed-notas-year'] = $r->json();

        return $salida;
    }
}
