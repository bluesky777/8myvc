<?php

namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **Los accesos favoritos del menú de una persona** — la lista corta que cada quien
 * ordena a mano en `app2`.
 *
 * Encargo de Joseth (18 sep 2026), tabla `2026_09_18_200000`. Hermana de
 * `informes_recientes` y **deliberadamente distinta**: aquélla es un log que se
 * escribe solo y se poda, ésta una lista que el usuario compone. El porqué de que
 * sean dos tablas está en el docblock de la migración.
 *
 * ## SON DOS RUTAS Y NO TRES, y conviene saber por qué antes de «completar» la familia
 *
 * Joseth autorizó **seis** rutas contando tres aquí. Salen **dos**, y no por recorte:
 * la tercera no tiene qué hacer.
 *
 * El motivo es `orden`. **`orden` es una propiedad de la LISTA, no de un renglón**, y
 * eso decide la forma del endpoint. Con rutas por elemento —`POST` añade, `DELETE`
 * quita, `PUT` reordena— el cliente tiene que mantener un orden total coherente a lo
 * largo de N peticiones, y **un fallo a mitad deja huecos o empates** en una columna
 * que nadie vuelve a mirar. Con un `PUT` de la lista entera, las cuatro operaciones
 * que la pantalla necesita —marcar, desmarcar, reordenar y renombrar— son **una sola
 * escritura atómica**, y `orden` no puede quedar inconsistente porque se reasigna
 * entero cada vez.
 *
 * Y entonces `DELETE` sobra de verdad: desmarcar es mandar la lista sin ese renglón,
 * y vaciar es mandarla vacía. Una ruta que no hace nada que no haga otra es una ruta
 * que hay que mantener, documentar y probar para siempre.
 *
 * *Hay precedente en este repositorio y es exactamente el mismo razonamiento: la
 * Fase 6 del [35](../../../../docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md)
 * preveía cuatro rutas y entregó dos, porque las otras dos no se podían calcar.*
 *
 * ## Lo que se paga por elegir la lista entera, dicho antes de que muerda
 *
 * **Dos pestañas abiertas se pisan: gana la última.** Si en una marcas un favorito y
 * en la otra marcas otro, la segunda en guardar deja su lista y la primera se pierde.
 * Con rutas por elemento sobrevivirían las dos.
 *
 * Se acepta porque es **el menú de una persona**, no un dato del colegio: el conflicto
 * sólo puede ser con uno mismo, se ve al instante y se arregla volviendo a marcar. A
 * cambio se quita la clase entera de fallos de orden, que no se ven y no se arreglan
 * solos. *Si algún día esto deja de ser una lista corta de una persona, esta decisión
 * es la primera que hay que volver a mirar.*
 *
 * ## `accesos_favoritos` NO lleva `year_id`, y es a propósito
 *
 * Una ruta del menú no es de un año. Su hermana sí lo lleva, porque repetir de un
 * clic un informe del año pasado saca el papel equivocado.
 *
 * ## LO QUE SE GUARDA EN `ruta` NO ES UNA PANTALLA: ES UNA DIRECCIÓN CON SUS PARÁMETROS
 *
 * Lo confirmó `myvc-front-dc` al revisar este contrato, y cambia lo que parecía:
 *
 * ```
 * /lista-alumnos                       una pantalla
 * /informes/boletines-periodo/96/3     también, y es el caso interesante
 * ```
 *
 * El botón dice «añadir la pantalla en la que estoy», y eso incluye **dónde estás dentro de
 * ella**. Dos favoritos de la misma pantalla con parámetros distintos son dos favoritos, y eso
 * es lo que se quiere — por eso el `UNIQUE` es sobre la dirección entera y no sobre un
 * identificador de pantalla.
 *
 * **Y de ahí sale el filo que hay que dejar escrito, porque se reporta en el sitio equivocado:**
 * `accesos_favoritos` **no lleva `year_id`** —una ruta del menú no es de un año— pero *ese `96`
 * es un grupo, y los grupos sí son de un año*. Un favorito guardado en 2026 y abierto en 2025
 * lleva a un grupo que no es, o a ninguno.
 *
 * **No se arregla metiendo `year_id`**, y la razón es que rompería el caso normal: `/lista-alumnos`
 * no depende de ningún año, y con `year_id` los favoritos desaparecerían al cambiar de año sin
 * que nadie entendiera por qué. Queda así a sabiendas. Si alguna vez duele, la salida barata es
 * del front —que el favorito con parámetros avise en vez de fingir— y no de esta tabla.
 *
 * *El síntoma será «me lleva al grupo equivocado» y se irá a buscar a los informes. Está aquí.*
 *
 * ## EL ÍNDICE CABE, Y SE MIDIÓ PORQUE PRODUCCIÓN NO ES ESTE DOCKER
 *
 * `UNIQUE (user_id, ruta)` sobre un `varchar(255)` en `utf8mb4` son **4 + 255×4 = 1.024 bytes**.
 * Con `ROW_FORMAT=Dynamic` el tope de InnoDB son **3.072**, así que sobra sitio; con el `COMPACT`
 * antiguo serían **767** y el índice no se podría crear. Medido en el docker: la tabla sale
 * `Dynamic` y `utf8mb4_unicode_ci`.
 *
 * **Producción corre MariaDB 10.5**, que trae `innodb_default_row_format=dynamic` de serie desde
 * la 10.2, así que el caso malo exigiría un colegio con esa variable cambiada a mano. Si alguno
 * lo estuviera, el síntoma es la migración fallando al crear el índice — **y la salida es un
 * índice con prefijo, no acortar la columna**, que perdería direcciones largas en silencio.
 *
 * ## EL TOPE DE AQUÍ ES 30 Y EL DEL FRONT ES 12, Y NO ES UNA INCOHERENCIA
 *
 * El 12 es **decisión de producto** y vive en la pantalla: al llegar avisa y **no añade**, en vez
 * de tirar el más viejo —lo que el usuario puso a mano lo quita él—. El 30 de aquí es un tope de
 * cordura del servidor, que no conoce esa regla y sólo está para que un cuerpo no sea ilimitado.
 * Que el cliente sea **más estricto que el servidor** es lo correcto en este sentido; al revés
 * sería un agujero.
 *
 * *(Y en `informes_recientes` la política es la contraria —ahí sí sale el más viejo— porque esa
 * lista la escribe el uso y no la mano.)*
 *
 * ## El filo que ya estaba asumido con el front
 *
 * Se guarda una **cadena de ruta**. El día que `app2` renombre una pantalla, los
 * favoritos de todo el mundo apuntan a nada **y no se pone nada rojo**. Está hablado:
 * el menú descarta en silencio lo que no resuelva. La alternativa —un registro de
 * pantallas con identificadores estables— cuesta más que el fallo.
 */
class AccesosFavoritosController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Cuántos favoritos caben.
     *
     * No lo pidió nadie: es un tope de cordura. Sin él, el cuerpo de un `PUT` es
     * ilimitado y una lista de diez mil renglones se escribe entera dentro de una
     * transacción. Treinta es holgado para un menú — si alguna vez estorba, es una
     * constante y una línea.
     */
    private const TOPE = 30;

    private const LARGO_RUTA = 255;

    private const LARGO_ETIQUETA = 255;

    private const LARGO_ICONO = 255;

    /**
     * `GET accesos-favoritos` — los de este usuario, en su orden.
     *
     * Desempata por `id` igual que su hermana: `orden` lo fija el cliente y **puede
     * traer empates** (dos renglones con 0 si algún día entran por otro camino). Sin
     * desempate, el menú se reordenaría solo entre dos cargas.
     */
    public function getIndex()
    {
        $user = User::fromToken();

        return DB::select(
            'SELECT id, ruta, etiqueta, icono, orden
			   FROM accesos_favoritos
			  WHERE user_id = ?
			  ORDER BY orden, id;',
            [$user->user_id]
        );
    }

    /**
     * `PUT accesos-favoritos` — deja la lista **exactamente** como llega.
     *
     * Marca, desmarca, reordena y renombra de una vez. `orden` se reasigna por
     * posición, así que la lista que se manda es la lista que se ve.
     *
     * **No borra y vuelve a insertar**, aunque sería más corto: eso perdería
     * `created_at` de los que ya estaban, y «desde cuándo lo tengo marcado» es lo
     * único que esta tabla sabe y no se puede reconstruir. Se hace upsert de lo que
     * llega y se quita lo que ya no está, dentro de una transacción.
     *
     * Los largos se comprueban aquí: el docker trunca en silencio y **MariaDB 10.5,
     * que es lo que corre en los dieciséis, aborta**.
     */
    public function putIndex()
    {
        $user = User::fromToken();

        $lista = Request::input('favoritos');

        if (! is_array($lista)) {
            abort(422, '`favoritos` hace falta y tiene que ser una lista.');
        }

        if (count($lista) > self::TOPE) {
            abort(422, 'No caben más de '.self::TOPE.' accesos favoritos.');
        }

        $limpios = [];
        $vistas = [];

        foreach (array_values($lista) as $posicion => $entrada) {
            if (! is_array($entrada)) {
                abort(422, 'Cada favorito tiene que ser un objeto con `ruta` y `etiqueta`.');
            }

            $ruta = $this->texto($entrada, 'ruta', self::LARGO_RUTA, $posicion);
            $etiqueta = $this->texto($entrada, 'etiqueta', self::LARGO_ETIQUETA, $posicion);

            // La misma ruta dos veces no significa nada en un menú, y además la
            // rechazaría el `UNIQUE (user_id, ruta)` con un 1062 —o sea un 500 con la
            // traza dentro— a mitad de la transacción. Se contesta 422 y se dice cuál.
            if (isset($vistas[$ruta])) {
                abort(422, 'La ruta `'.$ruta.'` viene dos veces en la lista.');
            }

            $vistas[$ruta] = true;

            $icono = $entrada['icono'] ?? null;

            if ($icono !== null && (! is_string($icono) || mb_strlen($icono) > self::LARGO_ICONO)) {
                abort(422, 'El `icono` del favorito '.($posicion + 1).' no vale.');
            }

            $limpios[] = [
                'ruta' => $ruta,
                'etiqueta' => $etiqueta,
                'icono' => $icono === null || trim($icono) === '' ? null : trim($icono),
                'orden' => $posicion,
            ];
        }

        DB::transaction(function () use ($user, $limpios) {
            foreach ($limpios as $f) {
                DB::insert(
                    'INSERT INTO accesos_favoritos
						 (user_id, ruta, etiqueta, icono, orden, created_at, updated_at)
					  VALUES (?, ?, ?, ?, ?, NOW(), NOW())
					  ON DUPLICATE KEY UPDATE
						 etiqueta   = VALUES(etiqueta),
						 icono      = VALUES(icono),
						 orden      = VALUES(orden),
						 updated_at = NOW();',
                    [$user->user_id, $f['ruta'], $f['etiqueta'], $f['icono'], $f['orden']]
                );
            }

            $rutas = array_column($limpios, 'ruta');

            if ($rutas === []) {
                DB::delete('DELETE FROM accesos_favoritos WHERE user_id = ?;', [$user->user_id]);

                return;
            }

            DB::delete(
                'DELETE FROM accesos_favoritos
				  WHERE user_id = ?
					AND ruta NOT IN ('.implode(',', array_fill(0, count($rutas), '?')).');',
                array_merge([$user->user_id], $rutas)
            );
        });

        return $this->getIndex();
    }

    /** Un texto obligatorio de un renglón de la lista, con su tope comprobado aquí. */
    private function texto(array $entrada, string $campo, int $largo, int $posicion): string
    {
        $valor = $entrada[$campo] ?? null;

        if (! is_string($valor) || trim($valor) === '') {
            abort(422, 'Al favorito '.($posicion + 1).' le falta `'.$campo.'`.');
        }

        $valor = trim($valor);

        if (mb_strlen($valor) > $largo) {
            abort(422, 'El `'.$campo.'` del favorito '.($posicion + 1).' pasa de '.$largo.' caracteres.');
        }

        return $valor;
    }
}
