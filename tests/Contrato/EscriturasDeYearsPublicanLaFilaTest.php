<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las tres escrituras de `years` que devuelven la fila ENTERA y no las miraba nadie.
 *
 * `YearsController` publica la fila completa de `years` por **nueve** caminos
 * —`docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md` §1.3— y hasta el
 * 13 sep 2026 sólo tres tenían instantánea: las tres lecturas (`GET years`,
 * `GET years/colegio`, `GET years/trashed`). Los otros seis **ganan una columna
 * nueva sin que se ponga rojo nada**, porque sus tests comprueban el
 * `assertStatus` y claves sueltas, no la forma de la respuesta.
 *
 * Este fichero cubre **los tres de más público**, que son los que se eligieron
 * por eso y no por ser los primeros de la lista:
 *
 *     POST   years/store              auth.personal  -> 74 cuentas
 *     PUT    years/guardar-cambios    auth.personal  -> 74 cuentas
 *     DELETE years/delete/{id}        auth.personal  -> 74 cuentas
 *
 * Las otras tres —`destroy`, `restore` y `myimages/cambiarlogocolegio`— llevan
 * `esSuperusuario` o `esAdministrativo` **dentro del método**, o sea 11 personas,
 * y quedan fichadas en el 35 sin cubrir. *Lo que menos se mira no es lo más
 * escondido: es lo que parece rutinario* — los dos caminos que alguien encontró
 * primero fueron justo los dos mejor cerrados.
 *
 * ## Qué guarda, y por qué la FORMA y no el cuerpo
 *
 * `formaUnida()` sustituye cada valor por su tipo, así que la instantánea es el
 * **juego de claves** y no los datos. Es lo que hace falta aquí: lo que se vigila
 * es que una columna nueva de `years` no se reparta sola a estas tres respuestas
 * sin que nadie lo decida. Guardar los valores haría fallar el test por un `id`
 * autoincremental y no diría nada de lo que se vino a mirar.
 *
 * ## Lo que este fichero NO hace
 *
 * **No decide si esas columnas deben viajar.** Eso es una decisión del colegio y
 * está planteada en el 35: puede ser que las tres tengan que pasar a columnas
 * nombradas. Lo que hace esto es que el día que se decida **se vea**, en vez de
 * enterarse en el cliente. Es la diferencia entre una instantánea y una regla.
 *
 * Nació midiendo la Fase 1 del modelo de evaluación, que añadió cuatro columnas a
 * `years`: se movieron las tres instantáneas de lectura y **estas tres respuestas
 * cambiaron sin dejar rastro**.
 */
class EscriturasDeYearsPublicanLaFilaTest extends CasoDeContrato
{
    /**
     * Una columna que sólo puede estar ahí si viaja la fila entera.
     *
     * No vale mirar `nombre_colegio` ni `year`: los lleva cualquier proyección de
     * las que este mismo controlador devuelve en otras rutas. Ésta no la nombra
     * ninguna consulta del proyecto, así que su presencia **demuestra el `SELECT *`**
     * en vez de sugerirlo.
     */
    private const COLUMNA_QUE_DELATA = 'si_recupera_materia_recup_indicador';

    public function test_store_devuelve_la_fila_entera_del_ano_nuevo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $siguiente = ((int) DB::table('years')->max('year')) + 1;

        $r = $this->withToken($token)->postJson('/api/years/store', $this->cuerpoMinimo($siguiente));

        $r->assertStatus(200);

        $cuerpo = json_decode($r->getContent(), true);

        $this->assertArrayHasKey(self::COLUMNA_QUE_DELATA, $cuerpo,
            'La respuesta ya no trae la fila entera. Si es a propósito —porque se pasó a columnas '
            .'nombradas— bórrese la instantánea y anótese la decisión en el doc 35 §1.3.');

        $this->compararConInstantanea('years-store', $this->formaUnida($cuerpo));
    }

    public function test_guardar_cambios_devuelve_la_fila_entera(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $year = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        // El objeto entero, que es como lo manda el único cliente que llama aquí
        // (`YearsCtrl.guardar_cambios`). Mandar sólo `{id}` es legítimo y conserva
        // el resto —§93—, pero entonces la respuesta no sería la del uso real.
        $r = $this->withToken($token)->putJson('/api/years/guardar-cambios', (array) $year);

        $r->assertStatus(200);

        $cuerpo = json_decode($r->getContent(), true);

        $this->assertArrayHasKey(self::COLUMNA_QUE_DELATA, $cuerpo,
            'La respuesta ya no trae la fila entera. Es la ruta que escribe por lista blanca '
            .'—veintiuna columnas nombradas— y publica por comodín: las dos mitades del mismo '
            .'método contando poblaciones distintas.');

        $this->compararConInstantanea('years-guardar-cambios', $this->formaUnida($cuerpo));
    }

    public function test_delete_devuelve_la_fila_entera_del_ano_que_manda_a_la_papelera(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        // Uno que NO sea el actual: borrar el actual tiene consecuencias propias
        // —las cubre `YearsTest`— y aquí se vino a mirar la forma de la respuesta.
        $year = DB::selectOne('SELECT * FROM years
            WHERE deleted_at IS NULL AND actual = 0 ORDER BY id LIMIT 1');

        $this->assertNotNull($year, 'El seed no tiene ningún año vivo que no sea el actual.');

        $r = $this->withToken($token)->deleteJson('/api/years/delete/'.$year->id);

        $r->assertStatus(200);

        $cuerpo = json_decode($r->getContent(), true);

        $this->assertArrayHasKey(self::COLUMNA_QUE_DELATA, $cuerpo,
            'La respuesta ya no trae la fila entera.');

        $this->compararConInstantanea('years-delete', $this->formaUnida($cuerpo));
    }

    /**
     * Lo mínimo que `postStore` necesita, copiado del año más reciente.
     *
     * Se copia en vez de inventarse porque crear un año **copia nueve tablas** del
     * anterior, y un cuerpo inventado deja la copia haciendo cosas raras sin que
     * este test —que mira la forma de la respuesta— se entere.
     */
    private function cuerpoMinimo(int $year): array
    {
        $ultimo = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

        return [
            'year' => $year,
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

            // Los seis nombres de las capas **no son opcionales**, y no por el
            // contrato sino por el esquema: `postStore` los escribe tal como
            // vienen y las seis columnas son `NOT NULL`, así que un cuerpo sin
            // ellos revienta con un 1048 en vez de con un 422. Medido al escribir
            // este fichero, que nació sin ellos.
            'unidad_displayname' => $ultimo->unidad_displayname,
            'unidades_displayname' => $ultimo->unidades_displayname,
            'genero_unidad' => $ultimo->genero_unidad,
            'subunidad_displayname' => $ultimo->subunidad_displayname,
            'subunidades_displayname' => $ultimo->subunidades_displayname,
            'genero_subunidad' => $ultimo->genero_subunidad,
            'encabezado_certificado' => $ultimo->encabezado_certificado,
        ];
    }
}
