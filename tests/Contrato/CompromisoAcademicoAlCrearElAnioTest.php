<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Las cuatro tablas del compromiso académico que se deciden en enero, y dos de
 * ellas al revés que las otras dos.**
 *
 * Es el hermano exacto de {@see FormularioDeInscripcionAlCrearElAnioTest}, y existe
 * por el mismo motivo: `CentinelaDeLasTablasDelAnioNuevoTest` obliga a **declarar**
 * qué se hace con cada tabla que lleva `year_id`, y eso es lo que impidió que estas
 * dos entraran calladas — cantó en cuanto se creó la migración. Pero el centinela
 * comprueba la **declaración**, no el **resultado**: que `postStore` nombre la tabla
 * no demuestra que la fila llegue al año nuevo, ni que la otra se quede fuera.
 *
 * Aquí se crea el año **por la ruta de verdad** y después se mira la base:
 *
 *     config_compromiso     SE COPIA    Lo que el colegio escribió para decir cómo
 *     compromiso_bloques                trabaja: la regla, el corte y los nueve
 *                                       textos que salen impresos en el papel. Sin
 *                                       copiarlas, cada enero vuelve a los defectos
 *                                       y el colegio lo reconfigura sin enterarse.
 *
 *     compromisos           NO SE       Lo que ocurrió porque ese año se vivió: un
 *     compromiso_items      COPIA       expediente con la nota congelada del
 *                                       periodo y las dos firmas del acudiente.
 *
 * La regla que separa las dos columnas ya estaba escrita, la dejó el bloque de
 * `config_formulario_inscripcion` (`YearsController.php:440-460`) y la cita
 * `COMPROMISOS-ACADEMICOS.md` §8.3: **lo que el colegio escribió para decir cómo
 * trabaja se copia; lo que ocurrió porque ese año se vivió, no.**
 *
 * ## EL CASO DE ABAJO ES EL QUE DE VERDAD HAY QUE BLINDAR, Y NO POR SIMETRÍA
 *
 * Copiar un compromiso no fabricaría sólo un papel que nadie imprimió. Lo fabricaría
 * **con la nota de otro año congelada dentro** —`nota_al_crear` es la definitiva del
 * periodo que el padre leyó— y con las columnas de las dos firmas puestas a cero,
 * o sea un expediente abierto contra un alumno de enero por unas asignaturas que
 * perdió el año pasado. Es el `porcentaje_ano` del papel señalando a un año que
 * todavía no ha empezado.
 *
 * ## Y LA EXCEPCIÓN QUE PARECE UN ERROR Y NO LO ES (§8.3)
 *
 * `config_compromiso.firmantes` **sí** se hereda y `years.firmantes_acta` **no**,
 * y están decididos al contrario a propósito. Se comprueban **en el mismo test**
 * porque leídos por separado cada uno parece el descuido del otro:
 *
 *   - `years.firmantes_acta` guarda **personas** —nombre, cargo y cédula— y por eso
 *     se reconfirma cada año (decisión de Joseth, 31 ago 2026: *un acta firmada por
 *     quien ya no está es peor que un acta sin firmantes*).
 *   - `config_compromiso.firmantes` guarda **rótulos de cargo**: «Coordinación
 *     Académica», «Acudiente». Un cargo no se va del colegio en diciembre.
 */
class CompromisoAcademicoAlCrearElAnioTest extends CasoDeContrato
{
    /**
     * La configuración del papel —regla, corte, parágrafo y canales— viaja entera.
     *
     * Se escriben valores **distintos de los defectos de la migración** a propósito:
     * con los defectos puestos, una copia que no copiara nada daría exactamente el
     * mismo resultado que una que copia bien, y el test pasaría sin medir nada.
     */
    #[Test]
    public function test_la_configuracion_del_compromiso_viaja_al_anio_nuevo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $ultimo = $this->ultimoAnioVivo();

        $this->configDelColegio($ultimo->id, [
            'regla' => 'asignatura',   // el defecto de la migración es `area`
            'corte' => 2,              // el defecto es 3
            'dias_reclamacion' => 9,   // el defecto es 5
            'plazo_label' => 'Semana de nivelaciones de este colegio',
        ]);

        $nuevo = $this->crearElAnioSiguiente($token, $ultimo);

        $copiada = DB::selectOne('SELECT * FROM config_compromiso WHERE year_id=?', [$nuevo]);

        $this->assertNotNull($copiada,
            'El año nuevo nació sin configuración del compromiso: el colegio volvería a los '
            .'defectos cada enero —regla `area` y corte 3— sin que nadie se lo dijera, y el '
            .'primer papel del primer periodo saldría con la plantilla equivocada.');

        $this->assertSame('asignatura', $copiada->regla, 'La regla no llegó al año nuevo.');
        $this->assertSame(2, (int) $copiada->corte, 'El corte no llegó al año nuevo.');
        $this->assertSame(9, (int) $copiada->dias_reclamacion,
            'Los días de reclamación no llegaron al año nuevo, y de ese número cuelga la '
            .'fecha que el papel imprime como plazo para reclamar.');
        $this->assertSame('Semana de nivelaciones de este colegio', $copiada->plazo_label,
            'El rótulo del plazo no llegó al año nuevo.');
    }

    /**
     * Los nueve bloques del papel viajan con su `orden` y su `activo`.
     *
     * El `orden` importa tanto como el texto: es el que decide si el «considerando»
     * va antes o después del «determina», y un documento con los párrafos cambiados
     * de sitio es un documento distinto aunque diga las mismas palabras.
     *
     * Y `activo` importa igual: los dos bloques que nacen apagados —`cita` y
     * `considerando_siee`— lo hacen porque un defecto ahí sería **un papel firmado
     * que cita mal la norma interna del colegio** (§8.4). Si el interruptor no viaja,
     * en enero se encienden solos.
     */
    #[Test]
    public function test_los_bloques_del_papel_viajan_con_su_orden_y_su_interruptor(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $ultimo = $this->ultimoAnioVivo();

        DB::insert('INSERT INTO compromiso_bloques(year_id, clave, orden, activo, titulo, cuerpo, created_at, updated_at)
            VALUES(?,?,?,?,?,?,NOW(),NOW())',
            [$ultimo->id, 'cita', 7, 1, 'La cita de apertura', 'El texto que eligió este colegio.']);

        DB::insert('INSERT INTO compromiso_bloques(year_id, clave, orden, activo, titulo, cuerpo, created_at, updated_at)
            VALUES(?,?,?,?,?,?,NOW(),NOW())',
            [$ultimo->id, 'considerando_siee', 3, 0, 'Los artículos del SIEE', 'Artículo 41 del SIEP.']);

        $nuevo = $this->crearElAnioSiguiente($token, $ultimo);

        $copiados = DB::select('SELECT clave, orden, activo, titulo, cuerpo
            FROM compromiso_bloques WHERE year_id=? ORDER BY clave', [$nuevo]);

        $this->assertCount(2, $copiados,
            'Los bloques que el colegio escribió no llegaron al año nuevo: en enero el papel '
            .'saldría con los defectos de `PlantillaDelCompromiso`, y los dos que nacen vacíos '
            .'—la cita y los artículos del SIEE— saldrían en blanco.');

        [$cita, $siee] = $copiados;

        $this->assertSame('cita', $cita->clave);
        $this->assertSame(7, (int) $cita->orden, 'El `orden` del bloque no viajó, y el orden ES el documento.');
        $this->assertSame(1, (int) $cita->activo);
        $this->assertSame('El texto que eligió este colegio.', $cita->cuerpo);

        $this->assertSame('considerando_siee', $siee->clave);
        $this->assertSame(3, (int) $siee->orden);
        $this->assertSame(0, (int) $siee->activo,
            'Un bloque apagado llegó encendido al año nuevo. `considerando_siee` apagado '
            .'significa «este colegio todavía no ha escrito sus artículos»; encendido y vacío, '
            .'un papel que se firma con un hueco donde va la norma interna.');
    }

    /**
     * **Los compromisos de los alumnos no viajan**, ni sus renglones.
     *
     * `compromisos` ya está declarada en `DATOS_DEL_ANIO` de
     * `CentinelaDeLasTablasDelAnioNuevoTest`, o sea que la **intención** está escrita.
     * Esto mira el **resultado**, que es otra cosa: la declaración se cumple porque
     * `copiarLaPlantillaDelCompromiso()` no la nombra, y «no la nombra» es justo la
     * clase de cosa que un merge deshace sin que nada avise.
     *
     * (`compromiso_items` no sale en aquel censo y no es un olvido: no tiene
     * `year_id`, cuelga de `compromiso_id`. Por eso aquí se cuenta **el total de la
     * tabla**, que es lo único que ve un item huérfano copiado por la puerta de atrás.)
     */
    #[Test]
    public function test_los_compromisos_de_los_alumnos_no_viajan(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $ultimo = $this->ultimoAnioVivo();

        $matricula = DB::selectOne('SELECT m.id, g.year_id
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM") AND g.year_id = ?
            ORDER BY m.id LIMIT 1', [$ultimo->id]);

        $this->assertNotNull($matricula,
            "El seed no tiene ninguna matrícula viva en el año {$ultimo->id}, así que este test "
            .'no podría crear el compromiso que tiene que NO viajar.');

        DB::insert('INSERT INTO compromisos
                (matricula_id, year_id, periodo, regla, cantidad_perdidas, porcentaje_ano,
                 texto, estado, creado_por, created_at, updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())',
            [$matricula->id, $ultimo->id, 3, 'asignatura', 3, 75,
                'El papel congelado del año que se acaba.', 'cerrado', 1]);

        $compromiso_id = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO compromiso_items(compromiso_id, nota_al_crear, created_at, updated_at)
            VALUES(?,?,NOW(),NOW())', [$compromiso_id, 24.5]);

        $itemsAntes = (int) DB::selectOne('SELECT COUNT(*) c FROM compromiso_items')->c;

        $nuevo = $this->crearElAnioSiguiente($token, $ultimo);

        $cuantos = (int) DB::selectOne('SELECT COUNT(*) c FROM compromisos WHERE year_id=?', [$nuevo])->c;

        $this->assertSame(0, $cuantos,
            'El año nuevo nació con compromisos ya abiertos. Eso fabrica expedientes que nadie '
            .'propuso, contra alumnos de enero, por asignaturas que perdieron el año pasado — y '
            .'con la nota de aquel periodo congelada dentro y las dos firmas del acudiente a cero.');

        $this->assertSame($itemsAntes, (int) DB::selectOne('SELECT COUNT(*) c FROM compromiso_items')->c,
            'Aparecieron renglones de compromiso al crear el año. `compromiso_items` no lleva '
            .'`year_id` —cuelga de `compromiso_id`— así que el centinela de tablas por año no la '
            .'ve, y un renglón copiado por la puerta de atrás no lo delataría nadie más.');
    }

    /**
     * **La excepción que parece un error: los firmantes de AQUÍ sí se heredan y los
     * del acta no.**
     *
     * Las dos mitades van en el mismo test a propósito. Por separado, cada una se lee
     * como el descuido de la otra —«si se copian los unos, por qué no los otros»— y
     * quien pase por aquí dentro de un año «arreglaría» el que le pareciera raro. La
     * diferencia es qué guarda cada columna, no un olvido:
     *
     *     config_compromiso.firmantes   RÓTULOS DE CARGO   «Coordinación Académica»
     *                                                      Un cargo no se va del
     *                                                      colegio en diciembre.
     *
     *     years.firmantes_acta          PERSONAS           Nombre, cargo y cédula.
     *                                                      Un acta firmada por quien
     *                                                      ya no está es peor que un
     *                                                      acta sin firmantes.
     */
    #[Test]
    public function test_los_firmantes_del_compromiso_se_heredan_y_los_del_acta_no(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $ultimo = $this->ultimoAnioVivo();

        $cargos = '["Coordinación Académica","Titular de grupo","Acudiente","Estudiante"]';
        $personas = '[{"nombre":"Quien firmó este año","cargo":"Rector","cedula":"1"}]';

        $this->configDelColegio($ultimo->id, ['firmantes' => $cargos]);

        DB::update('UPDATE years SET firmantes_acta=? WHERE id=?', [$personas, $ultimo->id]);

        $nuevo = $this->crearElAnioSiguiente($token, $ultimo);

        $this->assertSame($cargos,
            DB::selectOne('SELECT firmantes FROM config_compromiso WHERE year_id=?', [$nuevo])?->firmantes,
            'Los rótulos de firma del compromiso no se heredaron. Son CARGOS —«Coordinación '
            .'Académica», «Acudiente»— y un cargo no cambia en diciembre: volverlos a teclear '
            .'cada enero es exactamente lo que el encargo pedía quitar.');

        $this->assertNull(
            DB::selectOne('SELECT firmantes_acta FROM years WHERE id=?', [$nuevo])?->firmantes_acta,
            'Los firmantes del acta SÍ viajaron al año nuevo, y ésos no deben viajar: guardan '
            .'PERSONAS con nombre y cédula, y el acta de 2027 saldría firmada por el rector de '
            .'2026 (decisión de Joseth, 31 ago 2026). No es simétrico con el de arriba y no '
            .'tiene que serlo: uno guarda cargos y el otro personas.');
    }

    /* ══════════════════════════════════════════════════════════════════════════════
     * ANDAMIAJE
     * ══════════════════════════════════════════════════════════════════════════════ */

    /**
     * El último año **vivo**, que no es el de `max(year)`.
     *
     * En la base de tests hay un `2026` **borrado** (id 9) además del `2025` vivo, y
     * `postStore` busca el anterior con `Year::where('year', …)->first()`, que no ve
     * los borrados. Anclarse en `max(year)` dejaría `$pasado` nulo y **no se copiaría
     * nada de nada**: el test diría «la herencia no funciona» cuando lo que pasa es
     * que no había de dónde copiar. Es la misma nota que dejó escrita
     * `FormularioDeInscripcionAlCrearElAnioTest`, y se repite porque el que la
     * ignore va a pagar lo mismo.
     */
    private function ultimoAnioVivo(): object
    {
        $ultimo = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        $this->assertNotNull($ultimo, 'El seed no tiene ningún año vivo.');

        return $ultimo;
    }

    /**
     * La fila de `config_compromiso` del año, creada o retocada.
     *
     * Perezosa como en producción: el colegio que no ha abierto la pantalla no tiene
     * fila, y su ausencia significa «los defectos», no «un documento en blanco».
     *
     * @param  array<string, mixed>  $valores
     */
    private function configDelColegio(int $year_id, array $valores): void
    {
        if (DB::selectOne('SELECT id FROM config_compromiso WHERE year_id=?', [$year_id]) === null) {
            DB::insert('INSERT INTO config_compromiso(year_id, created_at, updated_at) VALUES(?,NOW(),NOW())',
                [$year_id]);
        }

        foreach ($valores as $columna => $valor) {
            // Los nombres de columna salen de este fichero y de ningún sitio más; no hay
            // entrada de usuario que pueda llegar hasta aquí.
            DB::update('UPDATE config_compromiso SET `'.$columna.'`=? WHERE year_id=?', [$valor, $year_id]);
        }
    }

    /**
     * Crea el año siguiente por la ruta de verdad y devuelve su id.
     *
     * Copiado de `FormularioDeInscripcionAlCrearElAnioTest` con sus comprobaciones:
     * los seis nombres de las capas van sí o sí —las columnas son `NOT NULL` y
     * `postStore` las escribe tal como llegan, así que sin ellos revienta con un 1048
     * y el test fallaría por el motivo equivocado—, y el año anterior se comprueba en
     * vez de suponerse, porque sin él `postStore` se salta el bloque de copia entero
     * y la medición diría «no se copia» sobre un año que no tenía padre.
     */
    private function crearElAnioSiguiente(string $token, object $ultimo): int
    {
        $siguiente = ((int) $ultimo->year) + 1;

        $anterior = DB::selectOne('SELECT id FROM years WHERE year=? AND deleted_at IS NULL',
            [$siguiente - 1]);

        $this->assertNotNull($anterior,
            "El año {$siguiente} no tiene un año anterior VIVO del que copiar, así que este test "
            .'no puede medir nada: `postStore` se saltaría el bloque entero.');

        $this->assertSame((int) $ultimo->id, (int) $anterior->id,
            'El anterior que va a encontrar `postStore` no es del que este test escribió la '
            .'configuración, así que la comparación no diría nada.');

        $r = $this->withToken($token)->postJson('/api/years/store', [
            'year' => $siguiente,
            'actual' => false,
            'nombre_colegio' => $ultimo->nombre_colegio,
            'abrev_colegio' => $ultimo->abrev_colegio,
            'nota_minima_aceptada' => $ultimo->nota_minima_aceptada,
            'resolucion' => $ultimo->resolucion,
            'codigo_dane' => $ultimo->codigo_dane,
            'telefono' => $ultimo->telefono,
            'celular' => $ultimo->celular,
            'website' => $ultimo->website,
            'website_myvc' => $ultimo->website_myvc,
            'alumnos_can_see_notas' => $ultimo->alumnos_can_see_notas,
            'unidad_displayname' => $ultimo->unidad_displayname,
            'unidades_displayname' => $ultimo->unidades_displayname,
            'genero_unidad' => $ultimo->genero_unidad,
            'subunidad_displayname' => $ultimo->subunidad_displayname,
            'subunidades_displayname' => $ultimo->subunidades_displayname,
            'genero_subunidad' => $ultimo->genero_subunidad,
            'encabezado_certificado' => $ultimo->encabezado_certificado,
        ]);

        $r->assertStatus(200);

        $id = DB::selectOne('SELECT id FROM years WHERE year=? AND deleted_at IS NULL', [$siguiente]);
        $this->assertNotNull($id, 'No se creó el año siguiente.');

        return (int) $id->id;
    }
}
