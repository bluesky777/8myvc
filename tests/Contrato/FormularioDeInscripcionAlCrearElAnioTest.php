<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **Las dos tablas del formulario de inscripción que tienen `year_id`, decididas al
 * revés, comprobadas en el mismo fichero.**
 *
 * `CentinelaDeLasTablasDelAnioNuevoTest` ya obliga a **declarar** qué se hace con
 * cada tabla por año, y eso es lo que impidió que estas dos entraran calladas el
 * 19 sep 2026 — cantó en cuanto se creó la migración. Pero el centinela comprueba
 * la **declaración**, no el **resultado**: que `postStore` nombre la tabla no
 * demuestra que la fila llegue al año nuevo, ni que la otra se quede fuera.
 *
 * Eso es exactamente la distinción que este repositorio lleva pagando en otros
 * sitios —mirar el resultado y no el estado— así que aquí se mira la base después
 * de crear el año de verdad:
 *
 *     config_formulario_inscripcion   SE COPIA   Lo que el colegio eligió que pida
 *                                                su formulario. Sin copiarla, cada
 *                                                enero vuelve al defecto y hay que
 *                                                reconfigurarla.
 *
 *     ordenes_inscripcion             NO SE      Cada fila es un PAPEL IMPRESO de
 *                                     COPIA      esa campaña, con su cobro y quién
 *                                                lo vendió.
 *
 * **El caso de abajo es el que de verdad hay que blindar**, y no por simetría:
 * copiar una orden no sólo fabricaría un papel que nadie imprimió — le daría al
 * alumno un código del año nuevo **antes de que nadie se lo entregue**, y entonces
 * la primera reimpresión de verdad reusaría —por el `UNIQUE (year_campana, alumno_id)`
 * que sostiene el get-or-create— un código que jamás salió de la impresora.
 */
class FormularioDeInscripcionAlCrearElAnioTest extends CasoDeContrato
{
    public function test_la_seleccion_de_campos_viaja_al_anio_nuevo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $ultimo = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        $elegidos = '["nombres","apellidos","documento","eps","ac_celular"]';

        DB::insert('INSERT INTO config_formulario_inscripcion(year_id, campos, created_at, updated_at)
            VALUES(?,?,NOW(),NOW())', [$ultimo->id, $elegidos]);

        $nuevo = $this->crearElAnioSiguiente($token, $ultimo);

        $copiada = DB::selectOne('SELECT campos FROM config_formulario_inscripcion WHERE year_id=?', [$nuevo]);

        $this->assertNotNull($copiada,
            'El año nuevo nació sin configuración del formulario: el colegio tendría que volver a '
            .'elegir sus campos cada enero, que es el fallo que pagó `desempenos_por_defecto`.');

        $this->assertSame($elegidos, $copiada->campos,
            'La selección llegó al año nuevo cambiada.');
    }

    public function test_los_formularios_impresos_no_viajan(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $ultimo = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL LIMIT 1');

        // Una de cada modo: la del alumno es la que el `UNIQUE (year_campana, alumno_id)`
        // vigila, y la suelta es el formulario en blanco que se vendió en ventanilla.
        $campana = ((int) $ultimo->year) + 1;

        DB::insert('INSERT INTO ordenes_inscripcion(codigo, year_id, year_campana, lote_id, modo, alumno_id, valor, created_at, updated_at)
            VALUES(?,?,?,?,?,?,?,NOW(),NOW())', ['TST-AAAAA', $ultimo->id, $campana, 'lote-de-prueba', 'antiguos', $alumno->id, 100000]);
        DB::insert('INSERT INTO ordenes_inscripcion(codigo, year_id, year_campana, lote_id, modo, alumno_id, valor, created_at, updated_at)
            VALUES(?,?,?,?,?,?,?,NOW(),NOW())', ['TST-BBBBB', $ultimo->id, $campana, 'lote-de-prueba', 'nuevos', null, 100000]);

        $nuevo = $this->crearElAnioSiguiente($token, $ultimo);

        $cuantas = DB::selectOne('SELECT COUNT(*) c FROM ordenes_inscripcion WHERE year_id=?', [$nuevo])->c;

        $this->assertSame(0, (int) $cuantas,
            'El año nuevo nació con formularios ya impresos. Eso fabrica papeles que nadie imprimió '
            .'y cobros que nadie hizo, y además quema el código del alumno antes de entregárselo.');
    }

    /**
     * Crea el año siguiente por la ruta de verdad y devuelve su id.
     *
     * Los seis nombres de las capas van sí o sí: las columnas son `NOT NULL` y
     * `postStore` las escribe tal como llegan, así que sin ellos revienta con un
     * 1048 y el test fallaría por el motivo equivocado.
     */
    private function crearElAnioSiguiente(string $token, object $ultimo): int
    {
        // **`max(year)` NO sirve aquí, y costó ver este test en rojo por el motivo
        // equivocado.** En la base de tests hay un `2026` **borrado** (id 9) además
        // del `2025` vivo, así que `max(year)` da 2026 y el año nuevo sería 2027 —
        // cuyo anterior, 2026, está en la papelera. `postStore` busca el anterior con
        // `Year::where('year', ...)->first()`, que **no ve los borrados**, así que
        // `$pasado` saldría nulo y **no se copiaría nada de nada**: ni los campos, ni
        // el rector, ni las unidades. El test habría dicho «la copia no funciona»
        // cuando lo que pasaba es que no había de dónde copiar. Es la §28 del
        // repositorio —dos filas del mismo año, una viva y una borrada— alcanzada por
        // otra puerta.
        //
        // Por eso el año nuevo se calcula desde el último **vivo**, y por eso la
        // condición se comprueba en vez de suponerse: sin la comprobación, el día que
        // el seed cambie este test volvería a pasar o fallar sin decir por qué.
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
