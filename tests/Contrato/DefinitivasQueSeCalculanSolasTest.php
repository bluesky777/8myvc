<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * Los dos agujeros por los que una definitiva dejaba de calcularse sola, y la
 * regla que decide qué hace un informe cuando se descubre por detrás.
 *
 * Encargo de Joseth del 17 sep 2026 por la sesión de `myvc_front`: *«que las
 * definitivas se calculen solas para poder quitar los botones de la UI»*. El
 * recorrido está en la §«El recorrido para quitar los botones» de
 * [10-definitivas.md](../../docs/migracion/10-definitivas.md), y su resultado fue
 * que **las once escrituras de notas ya recalculaban desde la fase 3**: lo que
 * quedaba eran dos escrituras que cambian el resultado **sin tocar nada de lo que
 * el sello vigila**, y por eso ni el recálculo ansioso ni el perezoso las veían.
 *
 * | Agujero | Por qué el sello no lo veía |
 * |---|---|
 * | desmarcar `manual` | escribe `updated_at = NOW()`, así que la fila queda **por delante** del sello |
 * | cambiar `reparto_subunidades` | `selloDeVersion()` mira notas, unidades, subunidades y matrículas — **no mira `years`** |
 *
 * **Y los dos eran exactamente el trabajo que le quedaba al botón «Calcular
 * definitivas per N»**: es lo único que los reparaba. Cerrados, el botón deja de
 * tener ninguna función que no tenga ya otro camino, que es la condición que la
 * fase 5 pedía y nunca se había escrito en términos comprobables.
 *
 * ## El criterio de estos tests es el del plan: el viaje de ida y vuelta
 *
 * Ninguno comprueba una bandera. Se guarda un número equivocado, se dispara lo
 * que tiene que repararlo y **se compara lo que quedó escrito con lo que el
 * cálculo da**. Un test que mirara `nfinal_desactualizada` habría pasado en verde
 * con los dos agujeros dentro — de hecho eso es literalmente lo que pasaba: el
 * detector decía «al día» y el número era otro.
 */
class DefinitivasQueSeCalculanSolasTest extends CasoDeContrato
{
    /**
     * Una definitiva automática, ya recalculada, con su año en modo editable.
     *
     * Se recalcula **antes** de medir a propósito: lo que estos tests comparan es
     * el valor que produce el servicio, y partir de una fila del seed sin más
     * dejaría que un seed con definitivas viejas hiciera pasar el test por el
     * motivo equivocado.
     */
    private function escenario(): object
    {
        $profesor = DB::selectOne('SELECT u.id AS user_id, u.username FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($profesor, 'El seed necesita un Profesor activo.');

        // El token primero: `Services\Login` reescribe `users.periodo_id` al entrar,
        // así que preguntarlo antes daría el periodo de antes de la sesión.
        $token = $this->tokenDe($profesor->username);

        /*
         * **El periodo sale de la SESIÓN, y la fila se busca dentro de él.**
         *
         * Escrito al revés —primero una definitiva cualquiera, después un profesor de
         * su año— y **el caso del boletín pasaba por el motivo equivocado**: los
         * informes imprimen `$user->periodo_id`, no el periodo de la fila que el test
         * ensuciara. Con los dos distintos, `putDetailedNotas` reparaba el periodo de
         * la sesión —correctamente— y la fila del test se quedaba en 1,0 sin que nadie
         * la hubiera mirado.
         *
         * Lo cazó `test_el_boletin_por_competencias_repara_el_periodo_abierto`, que es
         * el que compara **el número escrito**. Su gemelo de periodo cerrado seguía
         * verde, y ahí está lo incómodo: **afirmaba «no escribió» sobre una fila que el
         * controlador ni siquiera miraba**, o sea el falso verde que un test de
         * «no pasa nada» da gratis. Por eso el par se escribe siempre junto — el que
         * repara es el que demuestra que el otro apunta a algo.
         */
        $sesion = DB::selectOne('SELECT periodo_id FROM users WHERE id = ?', [$profesor->user_id]);

        if ($sesion === null || $sesion->periodo_id === null) {
            $this->markTestSkipped('El Profesor del seed no tiene periodo activo.');
        }

        $fila = DB::selectOne('SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id,
                    a.grupo_id, p.year_id, p.numero
                FROM notas_finales nf
                INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.deleted_at IS NULL
                INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
                INNER JOIN unidades u ON u.asignatura_id = a.id AND u.periodo_id = p.id
                     AND u.deleted_at IS NULL
                INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
                INNER JOIN notas n ON n.subunidad_id = s.id AND n.alumno_id = nf.alumno_id
                     AND n.deleted_at IS NULL
                WHERE nf.periodo_id = ?
                  AND (nf.manual IS NULL OR nf.manual = 0)
                  AND (nf.recuperada IS NULL OR nf.recuperada = 0)
                GROUP BY nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id, a.grupo_id,
                         p.year_id, p.numero
                ORDER BY COUNT(n.id) DESC, nf.id
                LIMIT 1', [$sesion->periodo_id]);

        if ($fila === null) {
            $this->markTestSkipped('El seed no tiene definitivas con notas en el periodo activo del Profesor.');
        }

        $fila->token = $token;

        // **Los dos interruptores, y no sólo el de editar notas.**
        // `pueden_modificar_definitivas()` mira `profes_pueden_nivelar` —no el de
        // editar—, así que abrir sólo uno deja la petición en 400 «No tienes
        // permiso» y el test falla por el motivo equivocado: parece que el
        // recálculo no ocurrió cuando lo que no ocurrió fue la llamada.
        DB::table('periodos')->where('year_id', $fila->year_id)
            ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);

        DefinitivasDeAsignatura::recalcular(
            (int) $fila->asignatura_id, (int) $fila->periodo_id, null, (int) $fila->alumno_id
        );

        $fila->correcta = (float) $this->notaDe((int) $fila->id);

        return $fila;
    }

    private function notaDe(int $nfId): string
    {
        return (string) DB::table('notas_finales')->where('id', $nfId)->value('nota');
    }

    /**
     * **El agujero (a): desmarcar `manual` no recalculaba.**
     *
     * Medido el 17 sep 2026 en la copia de desarrollo antes del arreglo: tras
     * desmarcar quedaban `nota = 1.0000` y `estaDesactualizada() = false`, con el
     * cálculo dando `48.15`. No se recalculaba tarde — **no se recalculaba nunca**.
     *
     * Aquí se reproduce con el valor absurdo puesto a mano y se comprueba que al
     * desmarcar vuelve **el número que el cálculo produce**, no uno cualquiera.
     */
    public function test_desmarcar_manual_devuelve_la_definitiva_calculada(): void
    {
        $e = $this->escenario();

        // El profesor la pone a mano con un valor que el cálculo no puede dar.
        DB::table('notas_finales')->where('id', $e->id)
            ->update(['nota' => 1, 'manual' => 1, 'updated_at' => now()]);

        $this->assertSame(1.0, (float) $this->notaDe((int) $e->id));

        $r = $this->withToken($e->token)->putJson('/api/definitivas_periodos/toggle-manual', [
            'nf_id' => $e->id,
            'manual' => 0,
            'num_periodo' => $e->numero,
        ]);

        $r->assertStatus(200);

        $this->assertSame(
            $e->correcta,
            (float) $this->notaDe((int) $e->id),
            'Al desmarcar `manual` la definitiva tiene que volver a la calculada.'
        );

        // Y la fila queda de verdad en automático, no sólo con el número bueno.
        $despues = DB::table('notas_finales')->where('id', $e->id)->first();
        $this->assertSame(0, (int) $despues->manual);
        $this->assertSame(0, (int) $despues->recuperada);
    }

    /**
     * El control en negativo, y es el que impide «arreglarlo» de más.
     *
     * **Marcar** `manual` no puede recalcular: recalcular ahí pisaría justo el
     * número que la marca existe para proteger. Sin este caso, un recálculo puesto
     * en las dos ramas pasaría el test de arriba y se comería la nota a mano — que
     * es peor que el problema que se venía a arreglar.
     */
    public function test_marcar_manual_conserva_el_numero_tecleado(): void
    {
        $e = $this->escenario();

        DB::table('notas_finales')->where('id', $e->id)->update(['nota' => 1]);

        $r = $this->withToken($e->token)->putJson('/api/definitivas_periodos/toggle-manual', [
            'nf_id' => $e->id,
            'manual' => 1,
            'num_periodo' => $e->numero,
        ]);

        $r->assertStatus(200);

        $this->assertSame(
            1.0,
            (float) $this->notaDe((int) $e->id),
            'Marcar `manual` no puede recalcular: es la marca que protege lo tecleado.'
        );
    }

    /**
     * La respuesta trae la definitiva recalculada, para que el front repinte la
     * celda sin pedir la pantalla entera.
     *
     * Es la misma forma que `putUpdate` con su clave `definitiva`. Antes este
     * método devolvía la cadena `'Cambiada'`; **ningún cliente la leía** —los
     * cuatro llamantes son `.then(function(){…})` sin argumento—, así que ampliarla
     * no rompe a nadie.
     */
    public function test_la_respuesta_trae_la_definitiva_recalculada(): void
    {
        $e = $this->escenario();

        DB::table('notas_finales')->where('id', $e->id)
            ->update(['nota' => 1, 'manual' => 1, 'updated_at' => now()]);

        $r = $this->withToken($e->token)->putJson('/api/definitivas_periodos/toggle-manual', [
            'nf_id' => $e->id,
            'manual' => 0,
            'num_periodo' => $e->numero,
        ]);

        $r->assertStatus(200);
        $this->assertFalse($r->json('manual'));

        // `definitiva` es el array de `recalcular()` —`{alumno_id, asignatura_id,
        // periodo_id, nota, manual, recuperada}`—, la misma forma que devuelve
        // `putUpdate`. Se lee por `definitiva.nota` y no con un `(float)` encima del
        // array: en PHP eso da **1.0** para cualquier array no vacío, o sea un número
        // plausible que no viene de ningún cálculo. Costó un rojo que parecía decir
        // «no recalculó» cuando había recalculado bien.
        $this->assertSame($e->correcta, (float) $r->json('definitiva.nota'));
        $this->assertFalse($r->json('definitiva.manual'));
    }

    /**
     * **La decisión de Joseth del 17 sep: el periodo cerrado se avisa, no se
     * repara.** Un boletín de un periodo que pasó es una lectura, y enseña lo que
     * quedó guardado.
     *
     * Se comprueba mirando **la tabla** y no lo que devuelve el servicio: una
     * escritura que no debía ocurrir no se ve en la respuesta, que es la misma
     * lección de `test_recalcular_dos_veces_no_duplica`.
     */
    public function test_con_el_periodo_cerrado_un_informe_no_escribe(): void
    {
        $e = $this->escenario();

        DB::table('notas_finales')->where('id', $e->id)
            ->update(['nota' => 1, 'updated_at' => '2000-01-01 00:00:00']);

        DB::table('periodos')->where('id', $e->periodo_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $estado = DefinitivasDeAsignatura::ponerAlDiaUnInforme(
            (int) $e->grupo_id, (int) $e->periodo_id, null, (int) $e->alumno_id
        );

        $this->assertFalse($estado['periodo_abierto']);
        $this->assertSame(0, $estado['reparadas']);

        $this->assertSame(
            1.0,
            (float) $this->notaDe((int) $e->id),
            'Con el periodo cerrado la definitiva no se toca.'
        );

        // Y lo dice: el informe tiene con qué marcar la asignatura que está por
        // detrás en vez de callarse, que es la mitad de la decisión.
        $this->assertNotEmpty(
            $estado['asignaturas'],
            'El periodo cerrado no se repara, pero sí se denuncia.'
        );
    }

    /**
     * Y con el periodo abierto sí se repara — la otra mitad de la misma decisión.
     *
     * El par de casos importa más que cada uno: un servicio que no escribiera nunca
     * pasaría el de arriba.
     */
    public function test_con_el_periodo_abierto_un_informe_repara(): void
    {
        $e = $this->escenario();

        DB::table('notas_finales')->where('id', $e->id)
            ->update(['nota' => 1, 'updated_at' => '2000-01-01 00:00:00']);

        DB::table('periodos')->where('id', $e->periodo_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $estado = DefinitivasDeAsignatura::ponerAlDiaUnInforme(
            (int) $e->grupo_id, (int) $e->periodo_id, null, (int) $e->alumno_id
        );

        $this->assertTrue($estado['periodo_abierto']);

        $this->assertSame(
            $e->correcta,
            (float) $this->notaDe((int) $e->id),
            'Con el periodo abierto la definitiva se pone al día antes de imprimir.'
        );
    }

    /**
     * **El agujero (b): cambiar `reparto_subunidades` recalcula el año.**
     *
     * El 422 de `avisarDeLoQueRecalcula` contaba cuántas definitivas cambian desde
     * el 15 sep y **nadie las recalculaba**: el método guardaba el año y se iba. Lo
     * que producía no era un salto sino deriva, que se descubre en junio.
     *
     * Aquí se ensucia una definitiva a mano y se comprueba que girar el interruptor
     * la deja en su sitio — o sea que el recálculo del año **alcanzó a esa fila**.
     */
    public function test_cambiar_el_reparto_de_subunidades_recalcula_el_anio(): void
    {
        $e = $this->escenario();

        $year = DB::table('years')->where('id', $e->year_id)->first();
        $otro = $year->reparto_subunidades === 'promedio' ? 'porcentaje' : 'promedio';

        DB::table('notas_finales')->where('id', $e->id)
            ->update(['nota' => 1, 'updated_at' => now()]);

        $r = $this->comoSuperusuario([
            'year_id' => $e->year_id,
            'reparto_subunidades' => $otro,
            'acepto_recalcular' => true,
        ]);

        $r->assertStatus(200);

        $this->assertNotNull($r->json('recalculadas'), 'Cambiar el reparto tiene que recalcular.');
        $this->assertGreaterThan(0, $r->json('recalculadas.escritas'));

        $this->assertNotSame(
            1.0,
            (float) $this->notaDe((int) $e->id),
            'La definitiva ensuciada tenía que quedar recalculada con el reparto nuevo.'
        );
    }

    /**
     * Y reenviar el mismo reparto **no** recalcula: no es un cambio.
     *
     * Sin este caso, la pantalla que guarda la configuración entera recalcularía el
     * año cada vez que alguien toca cualquier otro campo — 536 pares y 2,5 s por un
     * botón que no cambió nada.
     */
    public function test_reenviar_el_mismo_reparto_no_recalcula(): void
    {
        $e = $this->escenario();

        $year = DB::table('years')->where('id', $e->year_id)->first();

        $r = $this->comoSuperusuario([
            'year_id' => $e->year_id,
            'reparto_subunidades' => $year->reparto_subunidades,
        ]);

        $r->assertStatus(200);
        $this->assertNull($r->json('recalculadas'));
    }

    /**
     * **El boletín por competencias avisa de lo que está por detrás**, que es lo que
     * `myvc_front` necesita para pintar la pantalla que está construyendo.
     *
     * Nació imprimiendo lo que hubiera, como los otros doce informes de la §5: una
     * familia nueva no hereda lo que no tiene test. Aquí se fija **la forma del
     * aviso**, no sólo que exista, porque es contra esa forma contra la que se pinta.
     *
     * Las tres cuentas van **dentro de `poblacion`** y separadas a propósito:
     * `atrasadas` se repara sola y `faltan` no la arregla nadie hasta la fase 2, así
     * que sumarlas haría que el coordinador pulsara el botón esperando que bajara.
     */
    public function test_el_boletin_por_competencias_dice_lo_que_esta_por_detras(): void
    {
        $e = $this->escenario();

        DB::table('notas_finales')->where('id', $e->id)
            ->update(['nota' => 1, 'updated_at' => '2000-01-01 00:00:00']);

        DB::table('periodos')->where('id', $e->periodo_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $r = $this->withToken($e->token)
            ->putJson('/api/boletines-competencias/detailed-notas/'.$e->grupo_id, [
                'requested_alumnos' => [['alumno_id' => $e->alumno_id]],
            ]);

        $r->assertStatus(200);

        // Las tres claves existen siempre, aunque salgan en cero: un «0 por detrás»
        // sin población no distingue «miré y no había» de «no miré».
        $poblacion = $r->json('4');

        $this->assertArrayHasKey('definitivas_reparadas', $poblacion);
        $this->assertArrayHasKey('definitivas_atrasadas', $poblacion);
        $this->assertArrayHasKey('definitivas_que_faltan', $poblacion);

        // Periodo cerrado: no repara, y lo dice.
        $this->assertSame(0, $poblacion['definitivas_reparadas']);
        $this->assertGreaterThan(0, $poblacion['definitivas_atrasadas']);

        $this->assertSame(
            1.0,
            (float) $this->notaDe((int) $e->id),
            'Imprimir un boletín de un periodo cerrado no puede reescribir la definitiva.'
        );
    }

    /**
     * Y con el periodo abierto lo repara antes de imprimir, en vez de avisar.
     *
     * El par importa más que cada uno: un controlador que nunca reparase pasaría el
     * de arriba, y uno que reparase siempre pasaría éste.
     */
    public function test_el_boletin_por_competencias_repara_el_periodo_abierto(): void
    {
        $e = $this->escenario();

        DB::table('notas_finales')->where('id', $e->id)
            ->update(['nota' => 1, 'updated_at' => '2000-01-01 00:00:00']);

        $r = $this->withToken($e->token)
            ->putJson('/api/boletines-competencias/detailed-notas/'.$e->grupo_id, [
                'requested_alumnos' => [['alumno_id' => $e->alumno_id]],
            ]);

        $r->assertStatus(200);

        $this->assertSame(
            $e->correcta,
            (float) $this->notaDe((int) $e->id),
            'Con el periodo abierto el boletín pone al día antes de imprimir.'
        );

        $this->assertGreaterThan(0, $r->json('4.definitivas_reparadas'));
    }

    /**
     * `years/modelo-evaluacion` con el token de un superusuario.
     *
     * Es quien puede escribir en un año que no es el actual
     * (`Autoriza::exigirEscrituraEnElAnio`), y el mismo camino que usa
     * `AceptoRecalcularElRepartoTest`. Con el personal llano esto sale 403 y el
     * caso se saltaría sin medir nada.
     */
    private function comoSuperusuario(array $cuerpo)
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser);

        return $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', $cuerpo);
    }
}
