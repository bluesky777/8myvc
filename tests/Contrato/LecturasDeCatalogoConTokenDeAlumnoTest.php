<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §83 — Qué alcanza un alumno por las lecturas de catálogo, y qué sale por ahí.
 *
 * La pregunta del lote A era **«de los `GET .../show/{id}` que sólo piden
 * `auth.token`, ¿qué ve por ahí un alumno?»**. Los dos que caen en este lote son
 * `niveles_educativos/show/{id}` y `grados/show/{id}`, y la respuesta corta es
 * que **no ven nada que no vieran ya**: las dos devuelven una fila de catálogo
 * —nombre, abreviatura, orden— que el listado hermano, también `auth.token`, ya
 * les daba entera. Eso es un resultado, no un hueco.
 *
 * Lo que sí valía la pena era la pregunta de al lado, porque es la que un
 * `show/{id}` solo no contesta: **de las once lecturas del lote, ¿cuántas sacan
 * datos de una persona?** Se barren las once con un token de alumno y se mira el
 * cuerpo, no el código de estado.
 *
 * ## La respuesta: una de once, y ya estaba anotada
 *
 * `GET api/contratos` devuelve, de cada docente contratado del año: `num_doc`,
 * `fecha_nac`, `direccion`, `barrio`, `telefono`, `celular`, `email`,
 * `estado_civil`, `facebook`, `username` y `is_superuser`. Sale de
 * `Profesor::contratos()`, y la ruta lleva `auth.token` a secas mientras sus dos
 * hermanas del mismo controlador —`POST` y `DELETE`— llevan `auth.personal`.
 *
 * **No se cierra aquí, y no por falta de ganas**: está medida y decidida en la
 * [§14.4](../../docs/migracion/05-codigo-muerto-y-roto.md) y esperando en la
 * tabla del §5 de [09-pendientes.md](../../docs/migracion/09-pendientes.md). La
 * app de Flutter la llama desde pantallas de alumno y de acudiente y **sólo la
 * usa para pasar de un id a un nombre**, así que lo que hay que recortar es la
 * respuesta y no la puerta — y la app es una sola para los dieciséis colegios.
 * Ponerle un guard por iniciativa propia deja a las familias sin nombres en
 * dieciséis colegios a la vez.
 *
 * ## Para qué sirve entonces este test
 *
 * Para que la afirmación sea **«exactamente una de once»** y no «que se sepa,
 * una». Hoy hay un párrafo que dice que contratos filtra; lo que no había es
 * nada que se entere el día que empiece a filtrar una segunda, ni nada que se
 * entere el día que se recorten las columnas de contratos y quede sin fijar cuál
 * era la lista. Las dos cosas son la misma: **un hallazgo escrito en prosa no
 * vigila nada.**
 *
 * Si algún día se recorta `contratos` —que es lo que espera decisión—, este test
 * cae, y lo que hay que hacer es quitar `contratos` de la lista de esperados. Que
 * caiga es lo correcto: es el aviso de que el contrato de los cuatro clientes
 * cambió.
 */
class LecturasDeCatalogoConTokenDeAlumnoTest extends CasoDeContrato
{
    /**
     * Las columnas que hacen que una respuesta deje de ser un catálogo.
     *
     * Se buscan por el **nombre de la clave en el JSON** y no por el valor: un
     * valor puede venir vacío en el seed y volver lleno en producción, así que
     * mirar valores mediría el seed en vez de la respuesta. Es la misma razón por
     * la que estos tests miran la forma y no el contenido.
     *
     * @var list<string>
     */
    private const DE_UNA_PERSONA = [
        'num_doc', 'documento', 'tipo_doc', 'fecha_nac', 'direccion', 'barrio',
        'telefono', 'celular', 'email', 'email_usu', 'estado_civil', 'facebook',
        'username', 'is_superuser', 'ciudad_nac', 'ciudad_doc',
    ];

    /**
     * De las once lecturas del lote, exactamente una saca datos de una persona.
     *
     * El alumno es el token que menos puede de los cuatro tipos, así que lo que
     * alcanza él lo alcanzan los otros tres. Se comprueba de paso que las once
     * contestan 200: si alguna empezara a dar 403 sería porque le pusieron un
     * guard, y eso también hay que verlo — apagaría una pantalla.
     */
    public function test_solo_contratos_saca_datos_de_una_persona(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $nivel = (int) DB::selectOne('SELECT id FROM niveles_educativos WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->id;
        $grado = (int) DB::selectOne('SELECT id FROM grados WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->id;

        $lecturas = [
            'areas' => 'areas',
            'contratos' => 'contratos',
            'definiciones_comportamiento' => 'definiciones_comportamiento',
            'escalas' => 'escalas',
            'frases' => 'frases',
            'grados' => 'grados',
            'grados/show' => 'grados/show/'.$grado,
            'materias' => 'materias',
            'niveles_educativos' => 'niveles_educativos',
            'niveles_educativos/show' => 'niveles_educativos/show/'.$nivel,
            'tiposdocumento' => 'tiposdocumento',
        ];

        $filtran = [];
        $noContestan = [];

        foreach ($lecturas as $nombre => $ruta) {
            $r = $this->withToken($token)->json('GET', '/api/'.$ruta);

            if ($r->status() !== 200) {
                $noContestan[] = "{$nombre} -> {$r->status()}";
                $this->olvidarControladores();

                continue;
            }

            $encontradas = $this->columnasDePersona($r->json());

            if ($encontradas !== []) {
                $filtran[$nombre] = $encontradas;
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $noContestan,
            'Alguna lectura del lote dejó de contestar 200 a un alumno. Si le pusieron un guard, hay '
            ."que mirar qué pantalla se apaga.\n  ".implode("\n  ", $noContestan));

        $this->assertSame(['contratos'], array_keys($filtran),
            "Cambió qué lecturas del lote A sacan datos de una persona con un token de alumno.\n"
            .'Lo medido el 22 ago 2026 es exactamente una, `GET api/contratos`, y está esperando '
            .'decisión en el §5 de 09-pendientes (05 §14.4): la app de Flutter la usa sólo para pasar '
            ."de un id a un nombre.\nLo que hay ahora:\n  "
            .implode("\n  ", array_map(
                fn ($k, $v) => $k.': '.implode(', ', $v),
                array_keys($filtran), $filtran)));
    }

    /**
     * Y la lista de columnas de `contratos` se fija entera.
     *
     * Sin esto, «contratos filtra» es una etiqueta que sobrevive a que le
     * recorten la mitad de las columnas. Lo que espera decisión es **cuáles se
     * recortan**, así que lo útil es tener escrito cuáles hay hoy: el día que se
     * decida, este test dice exactamente qué se movió.
     *
     * Es la misma forma que `test_contratos_sigue_devolviendo_el_expediente`
     * (§14.4) pero desde la otra punta: aquél mira que la pantalla siga
     * recibiendo lo suyo, éste mira **qué de más le llega a quien no debería**.
     */
    public function test_que_le_llega_exactamente_a_un_alumno_por_contratos(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $r = $this->withToken($token)->json('GET', '/api/contratos')->assertStatus(200);

        $filas = $r->json();
        $this->assertNotEmpty($filas,
            'El seed no devuelve ningún contrato para el año del alumno: sin filas este test no mide nada.');

        $this->assertSame(
            ['barrio', 'celular', 'ciudad_doc', 'ciudad_nac', 'direccion', 'email', 'email_usu',
                'estado_civil', 'facebook', 'fecha_nac', 'is_superuser', 'num_doc', 'telefono',
                'tipo_doc', 'username'],
            $this->columnasDePersona($filas),
            'Cambió el expediente que `GET api/contratos` le manda a un alumno. Si es porque se '
            .'recortó —que es lo que espera decisión en el §5 de 09—, hay que actualizar esta lista '
            .'y avisar: la app de Flutter es una sola para los dieciséis colegios.'
        );
    }

    /**
     * Los dos `show/{id}` no dan más que su listado hermano.
     *
     * Es la respuesta literal a la pregunta del lote, y se comprueba comparando
     * **las claves de las dos respuestas** en vez de afirmar «no hay nada
     * personal»: una lista de columnas prohibidas sólo sabe de las que alguien
     * pensó en escribir, y la comparación con el hermano no necesita esa lista.
     *
     * `niveles_educativos/show` no trae ni una clave de más. `grados/show` trae
     * **cinco**, y las cinco por el mismo motivo: `getIndex` de grados es un
     * `SELECT` a mano con las columnas nombradas una a una, mientras `getShow`
     * devuelve el modelo Eloquent entero. Las cinco son
     * `created_by`, `updated_by`, `deleted_by`, `deleted_at` y `nivel`.
     *
     * Que sean cinco y no una es la corrección de esta sección: al escribirla se
     * dio por hecho que la de más era `nivel`, porque es la única que el método
     * añade a mano y por tanto la única que se ve leyéndolo. Las otras cuatro no
     * las pone nadie — **están porque el hermano las quita**, y eso no se ve en
     * `getShow` por mucho que se lea. Es la misma forma que el resto de la noche:
     * la asimetría no vive en el método que se está mirando.
     *
     * Ninguna de las cinco es un dato de nadie: `nivel` es el mismo catálogo que
     * `GET api/niveles_educativos` ya le da al alumno, y las tres `*_by` son ids
     * de `users` que en la copia de producción están **a null en las dieciséis
     * filas de `grados`** — la auditoría de estas tablas no se ha usado nunca. Se
     * dejan escritas para que se vea que se miraron.
     */
    public function test_los_dos_show_no_ensenan_mas_que_su_listado(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $nivel = (int) DB::selectOne('SELECT id FROM niveles_educativos WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->id;

        $unoSolo = $this->withToken($token)->json('GET', '/api/niveles_educativos/show/'.$nivel)->json();
        $this->olvidarControladores();
        $listado = $this->withToken($token)->json('GET', '/api/niveles_educativos')->json();

        $this->assertSame([], array_diff(array_keys($unoSolo), array_keys($listado[0])),
            '`niveles_educativos/show/{id}` enseña claves que el listado no da, y las dos rutas '
            .'llevan el mismo `auth.token`.');
        $this->olvidarControladores();

        $grado = (int) DB::selectOne('SELECT id FROM grados WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->id;

        $unoSolo = $this->withToken($token)->json('GET', '/api/grados/show/'.$grado)->json();
        $this->olvidarControladores();
        $listado = $this->withToken($token)->json('GET', '/api/grados')->json();

        $this->assertSame(
            ['created_by', 'updated_by', 'deleted_by', 'deleted_at', 'nivel'],
            array_values(array_diff(array_keys($unoSolo), array_keys($listado[0]))),
            '`grados/show/{id}` cambió lo que enseña de más respecto a su listado. Lo medido el 22 ago '
            .'2026 son cinco claves, y salen de que `getIndex` nombra sus columnas a mano mientras '
            .'`getShow` devuelve el modelo entero: no las añade nadie, las quita el hermano.');
    }

    /**
     * Las claves de esta respuesta que nombran a una persona.
     *
     * @param  mixed  $cuerpo
     * @return list<string>
     */
    private function columnasDePersona($cuerpo): array
    {
        $claves = [];
        $this->recogerClaves($cuerpo, $claves);

        $encontradas = array_values(array_intersect(self::DE_UNA_PERSONA, array_unique($claves)));
        sort($encontradas);

        return $encontradas;
    }

    /**
     * @param  mixed  $valor
     * @param  list<string>  $claves
     */
    private function recogerClaves($valor, array &$claves): void
    {
        if (! is_array($valor)) {
            return;
        }

        foreach ($valor as $clave => $dentro) {
            if (is_string($clave)) {
                $claves[] = $clave;
            }
            $this->recogerClaves($dentro, $claves);
        }
    }
}
