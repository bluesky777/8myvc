<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Que el navegador deje LEER el nombre del archivo que manda el servidor.
 *
 * ## El fallo, que no se parece a un fallo
 *
 * `GET api/planilla-offline/libro/{periodo_id}` contesta con
 * `Content-Disposition: attachment; filename=notas-P3-2026.xlsx`, y para un
 * periodo cerrado con `notas-P1-2026-consulta.xlsx`. Medido con `curl` el 22 sep
 * 2026 contra el docker local, y las dos salen tal cual. **La cabecera nunca
 * estuvo mal.**
 *
 * Lo que fallaba es quién la puede leer. En una petición **cruzada**, el
 * navegador sólo deja que el JavaScript vea siete cabeceras, y
 * `Content-Disposition` no es una de ellas. Así que llega, se ve en `curl`, y
 * `response.headers.get('content-disposition')` devuelve `null`.
 * `app2/src/app/comunes/descargas.ts:46` se cae entonces a un nombre de respaldo
 * y el archivo se guarda como «Libro de notas.xlsx».
 *
 * Cuándo es cruzada, medido el 22 sep 2026 y no heredado: **el front nunca**,
 * en ningún colegio —calcula la base de la API desde `location.hostname`, así
 * que sale del mismo origen del que se sirvió—, o sea que esto se ve con
 * `ng serve` y no en producción. **Pero el front no es el único que baja
 * archivos**: el escritorio del horario, la build web de Flutter y
 * `horarios.micolevirtual.com` sí cruzan, en producción, y llaman a
 * `horario/versiones/{id}/proyecto`, que es una de las once. El porqué entero
 * está en `config/cors.php`, sobre `exposed_headers`.
 *
 * **Y lo caro no es el nombre bonito: es el `-consulta`.** Ese sufijo lo decide
 * el servidor mirando si el periodo está abierto, y el front no lo puede
 * reconstruir. Sin él, el libro que NO se va a poder subir se guarda con el
 * mismo nombre que el que sí.
 *
 * ## Por qué este fichero mira DOS rutas que no se parecen en nada
 *
 * Porque lo que se arregló no es el libro: es `config/cors.php`, y eso cubre de
 * una vez las **once** rutas de `api/*` que mandan `Content-Disposition` —las
 * tres de `planilla-offline`, las seis de `Excel::download`, el PDF de
 * certificados y el proyecto del horario—. Un test que sólo mirara el libro
 * dejaría pasar «lo arreglo poniendo la cabecera en ESE controlador», que es
 * exactamente la solución que no se quería. La segunda ruta es de otro
 * controlador, otro mecanismo (`Excel::download`, no `response()->download`) y
 * otra pantalla: si las dos pasan, lo que pasa es el middleware.
 *
 * ## Lo que NO demuestra
 *
 * Que el navegador de verdad lo lea. Esto comprueba **la cabecera que sale**,
 * que es la parte que vive en este repositorio; que el `fetch` la lea es cosa
 * del navegador y ya está fijado por el estándar. Y como
 * `CorsDelEscritorioTest`, manda un `Origin` tecleado por nosotros.
 */
class CorsExponeElNombreDelArchivoTest extends CasoDeContrato
{
    /** El origen del front mientras se desarrolla: `ng serve`, otro puerto. */
    private const ORIGEN_DEL_FRONT = 'http://localhost:4200';

    /** La cabecera que el front necesita leer. */
    private const CABECERA = 'Content-Disposition';

    /**
     * Un docente del año actual con planilla de verdad en el periodo actual.
     *
     * Mismo criterio que `PlanillaOfflineTest::docenteConPlanilla()`, y por la
     * misma razón: el primer profesor del seed puede no tener nada en ese
     * periodo, y entonces la descarga sale igual de 200 sin haber mirado nada.
     * Aquí da menos igual —lo que se mira es una cabecera— pero un 422 por
     * `profesor_id` o un 404 dejaría el test en verde por el motivo equivocado
     * si alguien relajara el `assertStatus`.
     */
    private function docenteConPlanilla(): object
    {
        $fila = DB::selectOne(
            'SELECT u.username, per.id AS periodo_id
               FROM profesores p
               INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL AND u.is_active = 1
                                 AND u.tipo = "Profesor" AND u.is_superuser = 0
               INNER JOIN asignaturas a ON a.profesor_id = p.id AND a.deleted_at IS NULL
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
               INNER JOIN periodos per ON per.year_id = y.id AND per.actual = 1 AND per.deleted_at IS NULL
               INNER JOIN unidades un ON un.asignatura_id = a.id AND un.periodo_id = per.id
                                     AND un.deleted_at IS NULL AND un.alumno_id IS NULL
               INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
              WHERE p.deleted_at IS NULL
              GROUP BY p.id, u.username, per.id
              ORDER BY COUNT(DISTINCT s.id) DESC, p.id
              LIMIT 1'
        );

        $this->assertNotNull($fila,
            'El seed no tiene ningún docente con planilla en el periodo actual del año actual.');

        return $fila;
    }

    /**
     * Lo que hay que poder afirmar de cualquier respuesta que baje un archivo.
     *
     * Se comprueban **las dos** cabeceras y no sólo la de exponer, porque
     * «expuesta» sin `Content-Disposition` detrás no sirve de nada y sería un
     * verde falso el día que alguien quite el nombre del archivo.
     */
    private function exponeElNombre(TestResponse $r, string $ruta): void
    {
        $r->assertStatus(200);

        $this->assertNotEmpty((string) $r->headers->get('content-disposition'),
            "`$ruta` tiene que mandar `Content-Disposition`; si no, no hay nombre que exponer.");

        $expuestas = array_map(
            static fn ($h) => strtolower(trim($h)),
            explode(',', (string) $r->headers->get('access-control-expose-headers'))
        );

        $this->assertContains(strtolower(self::CABECERA), $expuestas,
            "`$ruta` manda el nombre del archivo pero no lo expone: en un origen cruzado el ".
            'JavaScript lo lee como `null` y guarda el archivo con un nombre inventado. '.
            'Se arregla en `config/cors.php` (`exposed_headers`), no en el controlador.');
    }

    /** El caso que lo destapó: el libro de notas. */
    #[Test]
    public function el_libro_expone_el_nombre_del_archivo(): void
    {
        $docente = $this->docenteConPlanilla();

        $r = $this->withToken($this->tokenDe($docente->username))
            ->withHeader('Origin', self::ORIGEN_DEL_FRONT)
            ->get('/api/planilla-offline/libro/'.$docente->periodo_id);

        $this->exponeElNombre($r, 'planilla-offline/libro/{periodo_id}');
    }

    /**
     * Y una descarga que no tiene nada que ver, para que el arreglo no pueda
     * volver a ser «pongo la cabecera en ESE controlador».
     *
     * `users/export` es `Excel::download`, otro mecanismo y otro controlador.
     */
    #[Test]
    public function una_exportacion_de_otra_pantalla_lo_expone_igual(): void
    {
        $r = $this->withToken($this->tokenDelPersonalLlano())
            ->withHeader('Origin', self::ORIGEN_DEL_FRONT)
            ->get('/api/users/export');

        $this->exponeElNombre($r, 'users/export');
    }

    /**
     * La política, aparte de las dos rutas: quien la pone es el middleware y
     * cubre todo `api/*`.
     *
     * Este caso es el que se pondría rojo si alguien quitara la cabecera de
     * `exposed_headers` «porque no la usa nadie»: las once rutas se caerían a la
     * vez y las dos de arriba no dirían **por qué**.
     */
    #[Test]
    public function la_politica_expone_la_cabecera_para_toda_la_api(): void
    {
        $expuestas = array_map('strtolower', (array) config('cors.exposed_headers'));

        $this->assertContains(strtolower(self::CABECERA), $expuestas,
            'Sin esto, las once rutas de `api/*` que bajan un archivo pierden su nombre '.
            'en cuanto quien las llama viene de otro origen.');

        $this->assertContains('api/*', (array) config('cors.paths'),
            'Si `paths` deja de cubrir `api/*`, `exposed_headers` no llega a ninguna descarga.');
    }
}
