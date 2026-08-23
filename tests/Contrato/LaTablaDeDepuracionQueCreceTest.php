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
 * - **Línea 208**, la del import principal: su propio comentario dice que **ya no
 *   hace ese trabajo** —lo hace `importaciones`, con
 *   `PuntoDeControlDeImportacion`— y que se queda porque es **el único rastro de
 *   las importaciones anteriores a hoy en las dieciséis bases**. Es una decisión
 *   tomada, no un olvido.
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
 * El comentario de la línea 208 dice que «`debugging` crece una fila por alumno
 * importado y no se limpia nunca». Eso es una afirmación, y este test la convierte
 * en un número: importa la hoja que produce el propio export y **cuenta**.
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
     * §124 — Una importación deja **una fila de depuración por alumno**.
     *
     * No es una estimación: se cuenta antes y después de subir la hoja, y se
     * compara con los alumnos que traía. La tabla no tiene ni borrado ni límite,
     * así que ese número se suma al de todas las importaciones anteriores, en cada
     * uno de los dieciséis colegios.
     *
     * **Se mide y no se toca**: la limpieza es una decisión —hay que decidir si se
     * conserva el rastro de las importaciones viejas— y quitar el pin del import
     * principal sin la decisión borraría lo único que queda de ellas.
     */
    public function test_importar_deja_una_fila_de_depuracion_por_alumno(): void
    {
        [$token, $year] = $this->credenciales();

        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200);
        $archivo = tempnam(sys_get_temp_dir(), 'depuracion').'.xlsx';
        copy($this->archivoDescargado($r), $archivo);

        $this->olvidarControladores();

        $antesDebug = DB::table('debugging')->count();
        $antesAlumnos = DB::table('alumnos')->whereNull('deleted_at')->count();

        $this->post("/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $escritas = DB::table('debugging')->count() - $antesDebug;

        $this->assertGreaterThan(0, $escritas,
            'Importar escribe en `debugging`. Si esto deja de ser cierto, alguien quitó el pin '
            .'de `ImportarController` — y con él el único rastro de las importaciones viejas.');

        // El número, que es lo que faltaba: una fila por alumno de la hoja, y la
        // hoja es la exportación de los alumnos que ya hay.
        $this->assertLessThanOrEqual($antesAlumnos, $escritas,
            'Una por alumno importado, no más.');

        // Y ninguna de ellas se limpia: la tabla no tiene `deleted_at` ni nadie
        // que borre. Lo que entra se queda.
        $this->assertSame($escritas + $antesDebug, DB::table('debugging')->count());

        @unlink($archivo);
    }
}
