<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Qué decide de verdad `years.profes_can_edit_alumnos`.
 *
 * La bandera está en la lista de decisiones del colegio desde el 20 ago 2026, y
 * lo que allí dice es que **«hoy son dos cosas escritas en dos sitios»**: borrar
 * alumnos definitivamente y resetear la contraseña de un alumno. Al ir a
 * contarlas para poder contestar, salieron **veinticinco apariciones y
 * diecinueve rutas** — el módulo de matrículas entero.
 *
 * No es un fallo: es que la pregunta que espera respuesta —*qué debe poder hacer
 * un docente con esa bandera encendida*— no se puede contestar con la lista mal.
 * De ahí este test, que hace dos cosas distintas y a propósito separadas:
 *
 * 1. **Fija la lista en una instantánea.** Si la bandera empieza a decidir una
 *    ruta más, se ve en el diff del `.json` — que es el mismo criterio con el que
 *    se vigilan los guards. Una lista escrita a mano en un documento se queda
 *    vieja sin que nadie lo note; ya pasó con ésta.
 * 2. **Comprueba que de verdad manda**, encendiéndola y apagándola contra una
 *    ruta que retira a un alumno. El documento decía «dos cosas» y nadie lo había
 *    comprobado nunca desde fuera.
 *
 * Y el dato que hace la decisión urgente o no: en la copia de desarrollo la
 * bandera está **apagada en los ocho años** que hay, así que hoy esas diecinueve
 * rutas son solo del superusuario. Encenderla se las da de golpe a los 19
 * profesores del colegio — no es un permiso fino, es un interruptor.
 *
 * **Recontado el 22 ago 2026, y la cuenta baja**: aquellas veinticinco eran un
 * `grep` del fichero entero, **comentarios incluidos**. En código son **22**, y
 * de los sitios que este test listaba **tres eran prosa** — un docblock de
 * `ChangeAskedController`, otro de `ExigirPersonal` y el `@property` generado de
 * `Year`—. El primero además colgaba una ruta,
 * `PUT api/ChangesAsked/ver-detalles`, que **no mira la bandera**: la nombra para
 * explicar otra. La lista queda en **21 sitios y 19 rutas**, y las 14 de
 * matrículas siguen siendo las mismas, que es lo que sostenía la respuesta de
 * Joseth.
 *
 * Ver docs/migracion/12-larastan-nivel-7.md §20.
 */
class BanderaProfesEditaAlumnosTest extends CasoDeContrato
{
    private const BANDERA = 'profes_can_edit_alumnos';

    /**
     * Cada sitio de `app/` que mira la bandera, con la ruta que le corresponde.
     *
     * Se lee del código y no de una lista escrita aquí, por lo de siempre: una
     * lista a mano se queda corta el día que alguien añada la comprobación en un
     * método nuevo, que es exactamente el fallo que este test existe para ver.
     *
     * @return array<string, list<string>>
     */
    private function dondeMandaLaBandera(): array
    {
        $mapa = [];
        $rutasPorAccion = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $accion = $ruta->getActionName();

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $rutasPorAccion[$accion][] = $verbo.' '.$ruta->uri();
            }
        }

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $texto = (string) file_get_contents($fichero->getPathname());

            if (! str_contains($texto, self::BANDERA)) {
                continue;
            }

            $relativa = str_replace(base_path().'/', '', $fichero->getPathname());

            preg_match('/namespace\s+([\w\\\\]+)/', $texto, $ns);
            preg_match('/class\s+(\w+)/', $texto, $clase);

            $fqcn = isset($ns[1], $clase[1]) ? $ns[1].'\\'.$clase[1] : null;

            preg_match_all('/function\s+(\w+)\s*\(/', $texto, $metodos, PREG_OFFSET_CAPTURE);

            foreach ($this->posicionesDe($texto) as $posicion) {
                $metodo = $this->metodoEn($metodos, $posicion);
                $clave = $relativa.'::'.($metodo ?? '(fuera de método)');

                $mapa[$clave] = $fqcn !== null && $metodo !== null
                    ? ($rutasPorAccion[$fqcn.'@'.$metodo] ?? ['(sin ruta propia)'])
                    : ['(sin ruta propia)'];
            }
        }

        ksort($mapa);

        return $mapa;
    }

    /**
     * Dónde aparece la bandera **en el código**, saltándose los comentarios.
     *
     * Empezó siendo un `preg_match_all` sobre el fichero entero, y el 22 ago 2026
     * eso metió en la lista un sitio que no lee nada: **un docblock que nombraba la
     * bandera para explicar un guard**. La entrada decía que
     * `asignaturasPerdidasDeAlumnoPorPeriodo` la mira —el método anterior al
     * comentario— y era mentira.
     *
     * > **Un detector que lee el fichero entero encuentra también lo que se escribió
     * > sobre él.** Y el resultado no tiene la cara de un fallo del detector: tiene
     * > la cara de un sitio nuevo, que es justo lo que este test existe para avisar.
     *
     * Se tokeniza y se descartan `T_COMMENT` y `T_DOC_COMMENT`. Lo demás cuenta:
     * la bandera aparece como propiedad (`$user->profes_can_edit_alumnos`) y dentro
     * de cadenas de SQL, y las dos son sitios de verdad.
     *
     * @return list<int>
     */
    private function posicionesDe(string $texto): array
    {
        $posiciones = [];

        foreach (token_get_all($texto) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$tipo, $valor, $linea] = $token;

            if ($tipo === T_COMMENT || $tipo === T_DOC_COMMENT) {
                continue;
            }

            if (! str_contains($valor, self::BANDERA)) {
                continue;
            }

            // El offset dentro del fichero, que es lo que `metodoEn` compara. La línea
            // no vale: `metodoEn` trabaja con las posiciones de `preg_match_all`.
            $posiciones[] = (int) strpos($texto, $valor, $this->desde($texto, $linea));
        }

        return $posiciones;
    }

    /** El offset donde empieza la línea `$linea` (1-indexada). */
    private function desde(string $texto, int $linea): int
    {
        $offset = 0;

        for ($i = 1; $i < $linea; $i++) {
            $salto = strpos($texto, "\n", $offset);

            if ($salto === false) {
                return $offset;
            }

            $offset = $salto + 1;
        }

        return $offset;
    }

    /** @param  array<int, array<int, array{0: string, 1: int}>>  $metodos */
    private function metodoEn(array $metodos, int $posicion): ?string
    {
        $ultimo = null;

        foreach ($metodos[1] as [$nombre, $inicio]) {
            if ($inicio < $posicion) {
                $ultimo = $nombre;
            }
        }

        return $ultimo;
    }

    public function test_la_lista_de_lo_que_decide_la_bandera(): void
    {
        $this->compararConInstantanea('bandera-profes-edita-alumnos', $this->dondeMandaLaBandera());
    }

    /**
     * Y que manda de verdad: la misma llamada, con la bandera apagada y encendida.
     *
     * `matriculas/retirar` pone `estado='RETI'` y la fecha de retiro, que es la
     * operación con la que este colegio da de baja a un alumno —479 filas vivas
     * con ese estado, ninguna en la papelera (§18)—. Con la bandera apagada, un
     * profesor recibe 400; con ella encendida, retira.
     *
     * El 400 es del legacy y se deja: cambiarlo a 403 es tocar lo que el front
     * lee hoy, y aquí lo que se está midiendo es quién puede, no con qué número
     * se le dice que no.
     */
    public function test_la_bandera_decide_si_un_profesor_retira_a_un_alumno(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $matricula = DB::selectOne('SELECT m.id, m.estado FROM matriculas m
            WHERE m.deleted_at IS NULL AND m.estado <> "RETI" ORDER BY m.id LIMIT 1');

        $this->assertNotNull($matricula, 'El seed necesita una matrícula que no esté retirada.');

        $cuerpo = ['matricula_id' => $matricula->id, 'fecha_retiro' => '2026-08-21'];

        DB::update('UPDATE years SET profes_can_edit_alumnos = 0');

        $this->withToken($token)->putJson('/api/matriculas/retirar', $cuerpo)
            ->assertStatus(400)
            ->assertSee('No tiene permisos para editar');

        $this->assertSame($matricula->estado,
            DB::selectOne('SELECT estado FROM matriculas WHERE id = ?', [$matricula->id])->estado,
            'Con la bandera apagada la matrícula no se toca.');

        DB::update('UPDATE years SET profes_can_edit_alumnos = 1');

        $this->withToken($token)->putJson('/api/matriculas/retirar', $cuerpo)->assertStatus(200);

        $this->assertSame('RETI',
            DB::selectOne('SELECT estado FROM matriculas WHERE id = ?', [$matricula->id])->estado,
            'Con la bandera encendida el profesor retira al alumno.');
    }
}
