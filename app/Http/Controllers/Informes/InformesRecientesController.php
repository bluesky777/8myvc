<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **Los informes que este usuario sacó hace poco**, para que la pantalla nueva de
 * `/informes` de `app2` pueda ofrecer «repetir esto» sin resolver nada.
 *
 * Encargo de Joseth (18 sep 2026). La tabla entró el mismo día con
 * `2026_09_18_200000`, y **su docblock lleva el porqué de cada columna** — esto de
 * aquí sólo explica lo que decide el controlador. La especificación es la que
 * acordaron `myvc-front-dc` y `8myvc-33`, con una corrección importante anotada
 * abajo.
 *
 * ## `usuario` y `year` salen de la sesión, NUNCA del cuerpo
 *
 * Lo pidió el front explícitamente y además es lo que hace que estas rutas no
 * necesiten guard de propiedad: **no aceptan ningún identificador de persona**, así
 * que no hay forma de pedir la lista de otro. Es la misma forma que
 * `notificaciones/temas` (ver `FamiliasQueNuncaEntranTest`): no se pregunta de quién
 * es la fila, se contesta quién eres.
 *
 * ## La huella la calcula SIEMPRE el servidor
 *
 * Aunque el front tenga el mismo algoritmo escrito —y lo tiene, para su
 * `localStorage`—, la huella **no se acepta del cuerpo**. Es una clave de unicidad:
 * en cuanto un cliente la calcula, dos clientes que normalicen distinto —o el mismo
 * antes y después de un refactor— meten dos filas para el mismo informe, que es
 * justo lo que el `UNIQUE` existe para impedir.
 *
 * ## `periodo_id` y `periodo_a_calcular` NO son el mismo campo — y costó una cadena de tres sesiones
 *
 * `8myvc-33` avisó al front de que el `3` de una URL como `/boletines-periodo/96/3`
 * **parece el número del periodo y no su id**, y de que si se hashea hoy el número y
 * mañana el id, el mismo informe da dos huellas y dos filas. Ese aviso llegó aquí
 * resumido como *«`periodo_a_calcular` guarda el número»* — cierto de ese campo, y
 * **la advertencia había desaparecido justo en el punto sobre el que iba**.
 *
 * Medido por `myvc-front-dc` contra el docker antes de escribir esto, y no deducido:
 * en el año 2026 los periodos son `id 34, 40, 41, 42` → `numero 1, 2, 3, 4`. **Ni
 * siquiera son seguidos**, así que confundirlos no es un error que se disimule. Lo
 * que la pantalla manda es el **número**.
 *
 * *De aquí sale la regla, que vale para cualquier relevo: un resumen conserva el
 * dato y pierde la advertencia, porque la advertencia es la parte que no parece
 * información.*
 *
 * ## La normalización, y el filo que queda aparcado a propósito
 *
 * Pares `campo=valor` unidos por `&`, **claves ordenadas alfabéticamente**, fuera
 * las vacías, valores como texto plano, `sha256` en hexadecimal. Lo de «ordenadas»
 * no es cosmético: `{grupo:1,periodo:2}` y `{periodo:2,grupo:1}` son el mismo informe
 * y sin ordenar producen dos huellas.
 *
 * Que las vacías se caigan **también es a propósito**: `{grupo_id:96}` y
 * `{grupo_id:96, periodo_id:null}` son el mismo informe. Hoy eso importa de verdad,
 * porque el front declara `periodo_id` y `alumno_id` en su tipo y **no los manda
 * nunca** — los tres informes «de un alumno» reciben al alumno por galleta, no por
 * la dirección.
 *
 * **El filo, sabido y aparcado por los dos lados**: el separador es ambiguo si un
 * valor llegara a contener `=` o `&`. Con identificadores no pasa. El día que entre
 * texto libre en `eleccion`, los dos a JSON canónico — y ese día **las huellas viejas
 * dejan de coincidir**, que es un vaciado de la tabla y no una migración.
 *
 * ## `params` se guarda pero NO entra en la huella
 *
 * Son los textos de pintar (`['Segundo A', 'periodo 3']`). Se guardan porque el
 * renglón tiene que poder pintarse sin resolver nada. **Hashearlos haría que
 * renombrar un grupo cambiara la huella y orfanara la fila**: el mismo informe
 * pasaría a ser dos, uno inalcanzable.
 *
 * ## El recorte al tope va en el servidor, y la razón es la buena
 *
 * Tope **6** por usuario y año — es el número del front y su pantalla. Va aquí y no
 * en el cliente porque **dos pestañas a la vez pueden pasarse aunque el front
 * recorte**. El `GET` **además** limita, y no es redundante: protege de una tabla que
 * venga sucia de antes.
 */
class InformesRecientesController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Cuántos se conservan por usuario y año.
     *
     * **Manda el 6 del front**, no el «~20» que quedó escrito en el docblock de la
     * migración: es su pantalla y su número. Si algún día discrepan otra vez, gana
     * quien enseña la lista.
     */
    private const TOPE = 6;

    /** Los topes de la tabla, comprobados aquí y no dejados a la base. */
    private const LARGO_CLAVE = 64;

    private const LARGO_ETIQUETA = 255;

    private const LARGO_RUTA = 255;

    /**
     * `GET informes-recientes` — los de este usuario y su año en curso, más nuevo
     * primero.
     *
     * Ordena por `updated_at DESC, id DESC`. **El desempate por `id` hace falta**:
     * `updated_at` tiene resolución de segundo, así que dos informes guardados en el
     * mismo segundo empatan y sin desempate el orden lo decide el motor — y entonces
     * la lista se reordena sola entre dos cargas sin que nadie haya tocado nada.
     */
    public function getIndex()
    {
        $user = User::fromToken();

        $filas = DB::select(
            'SELECT id, clave, etiqueta, ruta, parametros, huella_parametros, updated_at
			   FROM informes_recientes
			  WHERE user_id = ? AND year_id = ?
			  ORDER BY updated_at DESC, id DESC
			  LIMIT '.self::TOPE.';',
            [$user->user_id, $user->year_id]
        );

        foreach ($filas as $fila) {
            $fila->params = $this->paramsDeLaFila($fila->parametros);
            unset($fila->parametros);
        }

        return $filas;
    }

    /**
     * `POST informes-recientes` — deja constancia de que se sacó este informe.
     *
     * **Idempotente por huella**: si ya está, sube la fecha y refresca los textos; no
     * inserta. Y eso NO se hace con «comprueba y luego inserta», que no es atómico:
     * dos pestañas cargando el mismo informe a la vez pasarían las dos la
     * comprobación y meterían dos filas. Se hace con `ON DUPLICATE KEY UPDATE`, que
     * resuelve el choque **dentro del motor**, contra el `UNIQUE` de la tabla.
     *
     * *Esto no es una precaución teórica en este repositorio: media §
     * [10](../../../../docs/migracion/10-definitivas.md) existe porque `notas_finales`
     * es una caché sin clave única con seis escritores de comprueba-y-luego-inserta.*
     *
     * Los largos se comprueban **aquí y no se dejan a la base**, porque los dos
     * motores no se comportan igual: el docker (MySQL 8) trunca en silencio y
     * **MariaDB 10.5, que es lo que corre en los dieciséis, aborta**. Sin esto, una
     * etiqueta larga pasaría la suite y reventaría en producción.
     */
    public function postStore()
    {
        $user = User::fromToken();

        $clave = $this->textoObligatorio('clave', self::LARGO_CLAVE);
        $ruta = $this->textoObligatorio('url', self::LARGO_RUTA);

        $params = Request::input('params', []);

        if (! is_array($params)) {
            abort(422, '`params` tiene que ser una lista de textos.');
        }

        $eleccion = Request::input('eleccion', []);

        if (! is_array($eleccion)) {
            abort(422, '`eleccion` tiene que ser un objeto de campo y valor.');
        }

        $etiqueta = $this->etiquetaDe($params, $clave);

        $huella = $this->huellaDe($eleccion);

        DB::insert(
            'INSERT INTO informes_recientes
				 (user_id, year_id, clave, etiqueta, ruta, parametros, huella_parametros, created_at, updated_at)
			  VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
			  ON DUPLICATE KEY UPDATE
				 etiqueta   = VALUES(etiqueta),
				 ruta       = VALUES(ruta),
				 parametros = VALUES(parametros),
				 updated_at = NOW();',
            [
                $user->user_id,
                $user->year_id,
                $clave,
                $etiqueta,
                $ruta,
                json_encode(array_values($params), JSON_UNESCAPED_UNICODE),
                $huella,
            ]
        );

        $this->recortarAlTope((int) $user->user_id, (int) $user->year_id);

        $fila = DB::selectOne(
            'SELECT id, clave, etiqueta, ruta, parametros, huella_parametros, updated_at
			   FROM informes_recientes
			  WHERE user_id = ? AND year_id = ? AND clave = ? AND huella_parametros = ?;',
            [$user->user_id, $user->year_id, $clave, $huella]
        );

        // Puede ser `null` si el recorte se llevó por delante la fila que se acaba de
        // escribir — pasa si llegan más de TOPE informes distintos en el mismo
        // segundo, porque entonces el desempate por `id` decide y ésta puede perder.
        // Es raro y es correcto; lo que no valdría es reventar al devolverla.
        if ($fila === null) {
            return ['guardado' => false, 'motivo' => 'recortado', 'huella_parametros' => $huella];
        }

        $fila->params = $this->paramsDeLaFila($fila->parametros);
        unset($fila->parametros);
        $fila->guardado = true;

        return $fila;
    }

    /**
     * `DELETE informes-recientes` — vacía la lista de este usuario en su año.
     *
     * **Acota a usuario + año a propósito**: no se lleva el histórico de otros años.
     * «Limpiar la lista» es limpiar lo que la pantalla enseña, y la pantalla enseña
     * el año en curso.
     *
     * Contesta **cuántas quitó** y no `OK`, por lo mismo que `deleteJefe` de
     * `AreasController`: una lista que ya estaba vacía y una que tenía seis se
     * responden igual de bien, pero no son lo mismo.
     */
    public function deleteIndex()
    {
        $user = User::fromToken();

        $quitadas = DB::delete(
            'DELETE FROM informes_recientes WHERE user_id = ? AND year_id = ?;',
            [$user->user_id, $user->year_id]
        );

        return ['quitadas' => $quitadas];
    }

    /**
     * La huella: `sha256` de los pares `campo=valor` ordenados por clave y unidos
     * por `&`, sin las vacías.
     *
     * No lleva lista blanca de campos **a propósito**. Hoy el front manda tres
     * —`grupo_id`, `profesor_id`, `periodo_a_calcular`— y declara dos más que no
     * manda. Una lista blanca aquí obligaría a tocar el backend el día que la
     * pantalla gane un filtro, y **mientras no se tocara, dos informes distintos
     * compartirían huella** — que es mucho peor que una huella de más.
     */
    private function huellaDe(array $eleccion): string
    {
        $pares = [];

        foreach ($eleccion as $campo => $valor) {
            if ($valor === null || $valor === '' || is_array($valor)) {
                continue;
            }

            if (is_bool($valor)) {
                $valor = $valor ? '1' : '0';
            }

            $pares[(string) $campo] = (string) $campo.'='.(string) $valor;
        }

        ksort($pares, SORT_STRING);

        return hash('sha256', implode('&', $pares));
    }

    /**
     * Deja la lista en TOPE, quitando las más viejas.
     *
     * Se leen los ids y se borran en PHP en vez de con una subconsulta sobre la misma
     * tabla: MySQL no deja `DELETE` con un `SELECT` de la tabla que borra sin
     * envolverlo en una derivada, y el rodeo no se comporta igual en los dos motores.
     * La lista es de seis; leerla entera no cuesta nada.
     */
    private function recortarAlTope(int $userId, int $yearId): void
    {
        $todas = DB::select(
            'SELECT id FROM informes_recientes
			  WHERE user_id = ? AND year_id = ?
			  ORDER BY updated_at DESC, id DESC;',
            [$userId, $yearId]
        );

        $sobrantes = array_slice($todas, self::TOPE);

        if ($sobrantes === []) {
            return;
        }

        $ids = array_map(static fn ($f) => (int) $f->id, $sobrantes);

        DB::delete(
            'DELETE FROM informes_recientes WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).');',
            $ids
        );
    }

    /**
     * La etiqueta que pinta el renglón.
     *
     * **El front no manda `etiqueta` hoy** —su cuerpo es `{clave, url, params, eleccion}`—
     * y la columna es `NOT NULL`, así que se compone con los textos de pintar que sí
     * manda. Si algún día quiere mandarla, se respeta la suya: por eso se mira el
     * cuerpo primero.
     */
    private function etiquetaDe(array $params, string $clave): string
    {
        $delCuerpo = Request::input('etiqueta');

        if (is_string($delCuerpo) && trim($delCuerpo) !== '') {
            return mb_substr(trim($delCuerpo), 0, self::LARGO_ETIQUETA);
        }

        $textos = [];

        foreach ($params as $p) {
            if (is_scalar($p) && (string) $p !== '') {
                $textos[] = (string) $p;
            }
        }

        $compuesta = $textos === [] ? $clave : implode(' · ', $textos);

        return mb_substr($compuesta, 0, self::LARGO_ETIQUETA);
    }

    /** Lo guardado en `parametros` vuelve a salir como lista, nunca como texto JSON. */
    private function paramsDeLaFila(?string $guardado): array
    {
        if ($guardado === null || $guardado === '') {
            return [];
        }

        $lista = json_decode($guardado, true);

        return is_array($lista) ? array_values($lista) : [];
    }

    /** Un texto obligatorio del cuerpo, con su tope comprobado aquí y no en la base. */
    private function textoObligatorio(string $campo, int $largo): string
    {
        $valor = Request::input($campo);

        if (! is_string($valor) || trim($valor) === '') {
            abort(422, '`'.$campo.'` hace falta.');
        }

        $valor = trim($valor);

        if (mb_strlen($valor) > $largo) {
            abort(422, '`'.$campo.'` no puede pasar de '.$largo.' caracteres.');
        }

        return $valor;
    }
}
