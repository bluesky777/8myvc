<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * §124 — `Debugging::pin` en producción: **ocho sitios vivos, y tres de ellos hay
 * que dejarlos**.
 *
 * `Debugging::pin()` no es un comentario ni un log: hace `new Debugging` y
 * `save()`, o sea **una fila de verdad** en la tabla `debugging`. El lote K quitó
 * cinco de `ChangeAskedController`, que eran depuración pura —uno con el texto
 * `'ENTROOOOO'` dentro—. Barrida la población entera de `app/`:
 *
 * | Dónde | Cuántos | Qué son |
 * |---|---|---|
 * | `ChangeAskedController` | 5 | depuración pura — **quitados en el lote K (§121)** |
 * | `Alumnos/ImportarController` | 3 | **deliberados. No se tocan** |
 * | el resto de `app/` | 12 | ya estaban comentados |
 *
 * ## Por qué los tres del importador no se tocan
 *
 * Los tres llevan su decisión escrita al lado, y **dos de ellas siguen siendo
 * ciertas**:
 *
 * - **Línea 208**, la del import principal: su propio comentario decía que **ya no
 *   hacía ese trabajo** —lo hace `importaciones`, con
 *   `PuntoDeControlDeImportacion`— y que se quedaba porque era **el único rastro de
 *   las importaciones anteriores a hoy en las dieciséis bases**.
 *   **RETIRADO el 20 sep 2026 en `c52627b`, con la decisión de Joseth de hacer la
 *   importación rápida y reanudable**, y con su precio medido al lado del hueco que
 *   dejó: una consulta por fila, el 15 % de las del importador, y 17.457 filas
 *   acumuladas sólo en la copia de desarrollo. *Lo que para es que la tabla siga
 *   creciendo por un trabajo que ya hace otro; lo escrito no se borra.*
 * - **Líneas 559 y 685**, con `//No eliminar para continuar si se cae el
 *   servidor!!` al lado. Están en `postCartera()` y en `getModificar()`, **que no
 *   son el import principal y no usan `PuntoDeControlDeImportacion`**: para esos
 *   dos, el pin puede seguir siendo el único punto de control que hay. Quitarlos
 *   sin comprobar qué hace cada uno al reanudar es justo lo que el aviso pide que
 *   no se haga.
 *
 * > **Es la lección de la noche aplicada a un `grep`**: ocho coincidencias no son
 * > ocho fallos. Cinco eran basura y tres son mecanismo, y **lo que las separa no
 * > está en la llamada sino en el método donde vive**.
 *
 * ## Lo que sí queda medido
 *
 * El comentario de la línea 208 decía que «`debugging` crece una fila por alumno
 * importado y no se limpia nunca». Eso era una afirmación, y este test la convirtió
 * en un número: importa la hoja que produce el propio export y **cuenta**.
 *
 * **Desde el 20 sep 2026 ese número es cero, y el test se da la vuelta sin
 * borrarse.** Medir «ya no escribe» es lo que impide que el pin vuelva de
 * contrabando en un `merge`, que es exactamente el riesgo de una línea que estuvo
 * ocho meses ahí. Y la segunda mitad —**lo ya escrito sigue estando**— no cambia:
 * la tabla no tiene borrado ni límite, y vaciarla es otra decisión.
 *
 * Y el otro número, medido aquí y arreglado allí: **cerrar un pedido de cambio
 * escribía dos filas** —`Debugging::pin('Pedido')` y la del `'ENTROOOOO'`—. Esa
 * mitad la cierra el lote K y su test vive en esa rama; aquí se deja el número
 * porque es lo que dice cuánto valía quitarlas.
 */
class LaTablaDeDepuracionQueCreceTest extends CasoDeContrato
{
    /** @return array{0:string,1:int} token y año */
    private function credenciales(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $year = DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');

        return [$this->tokenDe($usuario->username), (int) $year];
    }

    /**
     * §124 — Una importación **ya no deja ni una fila** de depuración.
     *
     * Hasta el 20 sep 2026 este caso afirmaba lo contrario —una fila por alumno— y
     * lo contaba. El pin se retiró en `c52627b` con la decisión de Joseth, así que
     * **la afirmación que hay que sostener es la nueva**, y se sostiene con el
     * mismo experimento: se cuenta antes y después de subir la hoja.
     *
     * Las dos mitades, que no son la misma:
     *
     * - **La importación no escribe**: cero filas nuevas. Si esto vuelve a ser
     *   mayor que cero, alguien devolvió el pin —probablemente sin querer, en un
     *   `merge`— y con él la consulta por fila que costaba el 15 % del importador.
     * - **Lo ya escrito sigue ahí**: el total no baja. La tabla no tiene `deleted_at`
     *   ni nadie que borre, y las 17.457 filas de las importaciones viejas son el
     *   único rastro que queda de ellas en las dieciséis bases.
     *
     * *Los otros dos pines vivos —`postCartera()` y `getModificar()`, líneas 1432 y
     * 1544— no los toca esta ruta y siguen donde estaban: no son el import
     * principal y no usan `PuntoDeControlDeImportacion`.*
     */
    public function test_importar_ya_no_deja_filas_de_depuracion(): void
    {
        [$token, $year] = $this->credenciales();

        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200);
        $archivo = tempnam(sys_get_temp_dir(), 'depuracion').'.xlsx';
        copy($this->archivoDescargado($r), $archivo);

        $this->olvidarControladores();

        $antesDebug = DB::table('debugging')->count();

        $this->post("/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $despues = DB::table('debugging')->count();

        $this->assertSame(0, $despues - $antesDebug,
            'Importar volvió a escribir en `debugging`. El pin del import principal se retiró el '
            .'20 sep 2026 (`c52627b`): costaba una consulta por fila, el 15 % de las del '
            .'importador, y el punto de control lo hace `importaciones`.');

        // Y lo que ya estaba escrito no se va: quitar el pin para de escribir, no
        // limpia. Vaciar la tabla es otra decisión, y no la ha tomado nadie.
        $this->assertGreaterThanOrEqual($antesDebug, $despues,
            'Lo ya escrito en `debugging` es el único rastro de las importaciones viejas.');

        @unlink($archivo);
    }
}
