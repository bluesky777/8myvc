<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El boletín 2 con `years.show_fortaleza_bol = 1`.
 *
 * **Este test existe por el INTERRUPTOR, no por la consulta.** No es un duplicado
 * de `BoletinesTest`: es la misma ruta con **la otra rama** de un `if` que decide
 * un interruptor por colegio, y **esa rama no la ejercía ningún test**. Si alguien
 * lo lee sin esta cabecera lo va a mover a `BoletinesTest` o lo va a borrar por
 * repetido, y con eso vuelve el agujero.
 *
 * ## Cómo se descubrió el agujero, que es lo que lo justifica
 *
 * El 24 ago 2026, el `ALTER TABLE` de `unidades.alumno_id` rompió cuatro
 * predicados SQL con `1052 … alumno_id … is ambiguous`
 * ([bi-1.md §5.bis](../../docs/migracion/noche-2026-08-24/bi-1.md)). Al correr la
 * suite con el esquema nuevo y el código viejo —para medir qué rompe la migración
 * a solas— **la suite ejerció dos de los cuatro**. De los otros dos, uno era
 * código muerto y **éste estaba vivo y detrás de este interruptor**:
 *
 *     Boletines2Controller:225   if ($show_fortaleza_bol == 0) { … } else { … }
 *
 * La base de test tiene **1 de 8 años con el interruptor encendido**, y **ningún
 * test usaba ese año**. O sea:
 *
 *     Un colegio con `show_fortaleza_bol = 1` recibía un 500 que la suite no veía.
 *
 * Y el patrón general, que es lo reutilizable: **lo que un interruptor apaga, la
 * suite no lo prueba** — y hay dieciséis colegios con dieciséis combinaciones. Sus
 * hermanos conocidos son `mostrar_puesto_boletin` (1 de 8 años a 0) y la excepción
 * por colegio de `pintaNee` en el front.
 *
 * ## Por qué el test ENCIENDE el interruptor en vez de buscar el año que lo tiene
 *
 * Porque si dependiera del seed, **dejaría de comprobar el día que alguien
 * regenere el seed** y no habría ninguna señal: seguiría verde ejerciendo la rama
 * de siempre. Encendiéndolo aquí, la cobertura no depende de la configuración de
 * ningún colegio. `DatabaseTransactions` lo deshace al terminar.
 *
 * ## Lo que mira, y por qué no compara números
 *
 * ### El discriminador, y el que estuvo a punto de colarse
 *
 * Lo primero que escribí fue *«con el interruptor encendido trae `desempenio`»*,
 * **y era falso**: la otra rama hace `SELECT *` sobre un `left join` con
 * `escalas_de_valoracion`, que **tiene su propia columna `desempenio`**. Las dos
 * ramas lo traen, así que ese test habría pasado **ejerciendo la rama de siempre**
 * — el mismo fallo que viene a cubrir, cometido al cubrirlo. Se vio leyendo
 * `Snapshots/boletines2-detailed-notas.json`, no el código.
 *
 * El discriminador de verdad es **estructural**: la rama de `fortaleza_debilidad`
 * **no une `escalas_de_valoracion`**, así que devuelve exactamente ocho claves y
 * **`valoracion` no está entre ellas**. La otra sí la trae. Y además su
 * `desempenio` es literal —`IF(… < :nota_minima, "Debilidad", "Fortaleza")` dentro
 * del SQL—, no un texto de la escala.
 *
 * Así que se comprueban las dos cosas:
 *
 *   1. **200 y con unidades dentro.** Con el predicado ambiguo era un 500; y con
 *      `unidades: []` el test pasaría sin ejercer nada;
 *   2. **`valoracion` ausente y `desempenio` ∈ {Debilidad, Fortaleza}.** Eso sólo
 *      lo puede producir esta rama.
 *
 * Los números no se comparan: son cálculo de notas y el §5 los declara
 * intocables.
 */
class BoletinFortalezaDebilidadTest extends CasoDeContrato
{
    /**
     * Un alumno del grupo, en el formato que manda el frontend.
     *
     * Copiado de `BoletinesTest`, donde es `private`. **No se comparte subiéndolo
     * a `CasoDeContrato`** a propósito: mover un helper de otra clase de test es
     * tocar los tests de otra sesión, y esta noche hay varias.
     */
    private function unAlumnoDe(int $grupoId): array
    {
        $fila = DB::selectOne('SELECT a.id alumno_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE m.grupo_id = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, "El grupo {$grupoId} no tiene alumnos matriculados.");

        return ['alumno_id' => $fila->alumno_id, 'grupo_id' => $grupoId];
    }

    /** El boletín del alumno, con el interruptor del año en el valor que se pida. */
    private function boletinCon(int $interruptor): array
    {
        [$grupo, $token] = $this->grupoYPersonal();

        DB::update('UPDATE years SET show_fortaleza_bol = ? WHERE id = ?',
            [$interruptor, $grupo->year_id]);

        $r = $this->putJson("/api/boletines2/detailed-notas/{$grupo->id}", [
            'requested_alumnos' => [$this->unAlumnoDe($grupo->id)],
        ], ['Authorization' => 'Bearer '.$token]);

        // **`assertStatus()` recibe UN parámetro, no dos.** El mensaje que había aquí
        // como segundo argumento no lo leía nadie: PHP lo aceptaba, el test pasaba
        // en verde y el día que este 200 se rompiera el fallo habría salido pelado,
        // sin la línea que dice qué buscar. Lo cazó larastan al fundir, y es el
        // mismo modo de fallo que la noche del 24 llamó «el instrumento correcto
        // sobre el objeto equivocado»: aquí la aserción era correcta y el mensaje
        // caía al suelo.
        $this->assertSame(200, $r->getStatusCode(),
            "boletines2 con show_fortaleza_bol={$interruptor} no contestó 200. "
            .'Con el predicado `alumno_id` sin alias esto era un 500 (1052 ambiguous).');

        return (array) $r->json();
    }

    /**
     * Las unidades del primer alumno que tenga alguna, o [] si ninguna.
     *
     * **Busca en profundidad a propósito.** La respuesta de esta familia es un
     * array POSICIONAL —`array($grupo, $year, $alumnos, $escalas)`, así que los
     * alumnos son el índice **2** y no la clave `alumnos`— y dentro, `boletines2`
     * mete un nivel de **`areas`** entre el alumno y sus asignaturas, que
     * `boletines` no tiene. Escribir la ruta a mano ata el test a una de las tres
     * familias y lo rompe el día que cambie el anidamiento; y el primer intento de
     * este fichero se escribió contra `['alumnos'][…]['asignaturas']`, que no
     * existe en ninguna de las dos formas.
     */
    private function unidadesDe(array $cuerpo): array
    {
        $this->assertNotEmpty($cuerpo[2] ?? [],
            'El boletín salió sin alumnos (índice 2): este test no comprobaría nada.');

        return $this->primerasUnidades($cuerpo[2]);
    }

    /** @param mixed $nodo */
    private function primerasUnidades($nodo): array
    {
        foreach ((array) $nodo as $clave => $valor) {
            if ($clave === 'unidades' && ! empty($valor)) {
                return (array) $valor;
            }
            if (is_array($valor) || is_object($valor)) {
                $halladas = $this->primerasUnidades($valor);
                if ($halladas !== []) {
                    return $halladas;
                }
            }
        }

        return [];
    }

    public function test_con_el_interruptor_encendido_corre_la_rama_de_fortaleza(): void
    {
        $unidades = $this->unidadesDe($this->boletinCon(1));

        $this->assertNotEmpty($unidades,
            'Con `show_fortaleza_bol = 1` el boletín no trajo ni una unidad. Con la rama '
            .'reventando en 500 esto no llegaría aquí, pero con `unidades: []` el test '
            .'pasaría sin ejercer la rama — que es el agujero que viene a tapar.');

        // `valoracion` sale de `escalas_de_valoracion`, y esta rama NO une esa
        // tabla. Es lo único estructural que la distingue de la otra.
        $this->assertArrayNotHasKey('valoracion', $unidades[0],
            'La rama `fortaleza_debilidad` no une `escalas_de_valoracion`, así que no puede '
            .'traer `valoracion`. Si viene, corrió la rama `con_desempenio` y este test '
            .'estaría pasando por la rama equivocada.');

        $this->assertContains($unidades[0]['desempenio'] ?? null, ['Debilidad', 'Fortaleza'],
            'Su `desempenio` es literal, del `IF(... , "Debilidad", "Fortaleza")` del SQL. '
            .'Un texto distinto viene de la escala, o sea de la otra rama.');
    }

    /**
     * Y la otra rama sigue en su sitio: apagado, **sí** trae `valoracion`.
     *
     * Sin esta mitad, el test de arriba se cumpliría igual si alguien hiciera que
     * las dos ramas devolvieran lo mismo — y entonces el interruptor sería
     * decoración sin que ningún test lo notara. **Es la comprobación en negativo
     * del discriminador**, no una repetición.
     */
    public function test_apagado_corre_la_rama_de_la_escala_de_valoracion(): void
    {
        $unidades = $this->unidadesDe($this->boletinCon(0));

        $this->assertNotEmpty($unidades, 'Con el interruptor apagado tampoco hay unidades.');

        $this->assertArrayHasKey('valoracion', $unidades[0],
            'Con `show_fortaleza_bol = 0` la rama `con_desempenio` une '
            .'`escalas_de_valoracion` y trae `valoracion`. Si no viene, el interruptor no '
            .'está decidiendo nada y el test de arriba no prueba lo que dice.');
    }
}
