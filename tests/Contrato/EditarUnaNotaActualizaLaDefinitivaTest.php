<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 de [10-definitivas.md](../../docs/migracion/10-definitivas.md):
 * **editar una nota actualiza la definitiva, y borrarla también.**
 *
 * Es la petición de origen —«que la definitiva se actualice al modificar la
 * nota»— y hasta hoy no ocurría: `NotasController::putUpdate` tenía en su sitio
 * un `if (Request::has('asignatura_id')) { # code... }` **vacío**. La definitiva
 * sólo se movía cuando alguien abría una pantalla que recalculaba a lo bruto, que
 * es la mitad de por qué se perdían.
 *
 * ## El criterio, que es el que pide el plan
 *
 * **El viaje de ida y vuelta, no la marca.** Ni una comprobación de
 * `nfinal_desactualizada`: se pone una nota, se pide la definitiva y se compara
 * con la aritmética hecha a mano. Un test que compruebe que una bandera vale `1`
 * pasa igual con el recálculo desconectado.
 *
 * La asignatura se monta con **dos unidades al 50% y dos subunidades al 50% cada
 * una**, para que cada nota pese exactamente un cuarto y la cuenta se pueda hacer
 * de cabeza. Es el mismo montaje que `DefinitivasDeAsignaturaTest`, a propósito:
 * lo que cambia aquí no es el cálculo, es **quién lo dispara**.
 */
class EditarUnaNotaActualizaLaDefinitivaTest extends CasoDeContrato
{
    /**
     * Editar la nota mueve la definitiva, y el número es el que sale de la cuenta.
     */
    public function test_editar_una_nota_actualiza_la_definitiva(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        // 4 notas de 20, cada una pesa un cuarto -> 20.
        $this->assertSame(20.0, $this->definitivaDe($ctx));

        // Se sube una sola nota de 20 a 40: sube un cuarto de la diferencia -> 25.
        $this->withToken($token)
            ->putJson('/api/notas/update/'.$ctx['notas'][0], ['nota' => 40])
            ->assertStatus(200);

        $this->assertSame(25.0, $this->definitivaDe($ctx),
            'La definitiva no siguió a la nota: el recálculo de `putUpdate` no ocurrió.');
    }

    /**
     * Y borrarla también, que es el mismo cambio por el otro lado.
     *
     * Importa aparte porque `deleteDestroy` hace un borrado **físico**: después
     * del `DELETE` ya no se puede saber de qué asignatura era la nota, así que si
     * el recálculo no lee el destino antes, no hay forma de arreglarlo después.
     */
    public function test_borrar_una_nota_actualiza_la_definitiva(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $this->assertSame(20.0, $this->definitivaDe($ctx));

        $this->withToken($token)
            ->deleteJson('/api/notas/destroy/'.$ctx['notas'][0])
            ->assertStatus(200);

        // Quitada una de las cuatro, el aporte de ese cuarto desaparece: 15.
        $this->assertSame(15.0, $this->definitivaDe($ctx),
            'Borrar la nota no movió la definitiva.');
    }

    /**
     * El recálculo toca **al alumno de la nota y a nadie más**.
     *
     * `recalcularPorNota` pasa `soloAlumno` a propósito: es lo que hace barato
     * llamarlo en cada nota tecleada. Si alguien lo quita «para que quede más
     * simple», esto no cae —el número del otro alumno sale igual— así que lo que
     * se comprueba es que **su fila no se reescriba**, mirando `updated_at`.
     */
    public function test_recalcular_por_una_nota_no_reescribe_la_fila_de_otro_alumno(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $otro = DB::table('notas_finales')
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->where('alumno_id', '<>', $ctx['alumno'])
            ->first();

        $this->assertNotNull($otro, 'El montaje necesita un segundo alumno con definitiva.');

        DB::table('notas_finales')->where('id', $otro->id)
            ->update(['updated_at' => '2001-01-01 00:00:00']);

        $this->withToken($token)
            ->putJson('/api/notas/update/'.$ctx['notas'][0], ['nota' => 40])
            ->assertStatus(200);

        $this->assertSame('2001-01-01 00:00:00',
            (string) DB::table('notas_finales')->where('id', $otro->id)->value('updated_at'),
            'Se reescribió la definitiva de un alumno cuya nota no tocó nadie.');
    }

    /**
     * `putSubunidad` crea la nota que faltaba **y ahora sí guarda** — §3.1.
     *
     * Es la nota rápida desde el horario del día. Su `INSERT` estaba entre
     * comillas **dobles** con la sintaxis de concatenación de las simples, así que
     * a MySQL le llegaba `'.123.'` —una cadena— donde iba un `int`: valía **0** y
     * la clave foránea lo rechazaba. Lo que lo tapaba es que el `WHERE NOT EXISTS`
     * sí iba ligado: cuando la nota ya existía no se intentaba insertar y no se
     * notaba nada.
     *
     * **Por eso el test borra la nota antes**: con la nota puesta, el método pasa
     * igual de bien roto que arreglado. El sujeto de esto es el hueco.
     */
    public function test_la_nota_rapida_del_horario_se_guarda_y_mueve_la_definitiva(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $nota = DB::table('notas')->where('id', $ctx['notas'][0])->first();
        $subunidadId = (int) $nota->subunidad_id;

        // Se quita la nota de ese alumno en esa subunidad para que haya hueco que
        // rellenar.
        //
        // **Esta cuenta cambió con la fase 0 del
        // [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md), y el
        // test es mejor ahora.** Antes decía «con `nota_default` a 40, la definitiva
        // pasa de 20 a 25»: o sea que **rellenar el hueco le regalaba un 40 al
        // alumno**, que es exactamente el fallo que esa fase viene a cerrar. Ahora la
        // casilla vuelve **vacía**, así que la definitiva se queda en **15** —los 20 de
        // partida menos los 5 que aportaba la nota borrada— y no se mueve hasta que
        // alguien la califique.
        //
        // El sujeto del test **no cambia**: sigue siendo que el `INSERT` de
        // `putSubunidad` inserta de verdad (lo comprueba el `assertSame(1, …)` de
        // abajo, que es el que cazaba el fallo de las comillas). Lo que cambia es qué
        // se espera del número, y ahora comprueba **dos** cosas donde antes comprobaba
        // una: que la casilla vuelve, y que vuelve sin nota.
        //
        // `nota_default` se sigue mandando en el cuerpo **a propósito**: el controlador
        // ya no lo mira, y este test es el que lo fija.
        //
        // El 40 no es arbitrario: con números que dan entero, lo que se comprueba es
        // el disparador, que es lo que este test mide.
        //
        // **Decía que era porque `notas_finales.nota` es `int`, y dejó de serlo el 30
        // ago 2026** —`2026_08_30_200000_notas_finales_en_decimal`, a `decimal(7,4)`—.
        // La frase sobrevivió a su migración porque **nada la comprueba**: es un
        // comentario, y el test pasa igual con el motivo equivocado. Se vio el 15 sep
        // al encontrarla **copiada literal** en un fichero nacido ese día
        // (`RepartoDeLasSubunidadesTest`), que es como se entera uno de estas cosas:
        // no cuando envejecen, sino cuando se reproducen.
        DB::table('notas')->where('id', $ctx['notas'][0])->delete();
        DB::table('subunidades')->where('id', $subunidadId)->update(['nota_default' => 40]);

        $grupoId = DB::table('asignaturas')->where('id', $ctx['asignatura'])->value('grupo_id');

        $this->withToken($token)->putJson('/api/notas/subunidad', [
            'grupo_id' => $grupoId,
            'asignatura_id' => $ctx['asignatura'],
            'subunidad' => ['id' => $subunidadId, 'nota_default' => 40],
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('notas')
            ->where('subunidad_id', $subunidadId)
            ->where('alumno_id', $ctx['alumno'])
            ->whereNull('deleted_at')->count(),
            'La nota rápida no llegó a guardarse: el INSERT sigue mandando una cadena '
            .'donde va un entero, y la clave foránea lo rechaza.');

        $this->assertNull(DB::table('notas')
            ->where('subunidad_id', $subunidadId)
            ->where('alumno_id', $ctx['alumno'])
            ->whereNull('deleted_at')->value('nota'),
            'La casilla volvió con una nota que nadie puso: la siembra sigue usando nota_default.');

        $this->assertSame(15.0, $this->definitivaDe($ctx),
            'La casilla vacía movió la definitiva, y no puede: no cuenta hasta que alguien la califique.');
    }

    /**
     * §5.1 — **crear una subunidad la crea con sus notas y con la definitiva ya
     * contándola.** Hasta hoy nacía sola.
     *
     * Las notas las creaba `Nota::verificarCrearNotas`, y sólo al abrir /notas en
     * el navegador. Entre las dos cosas la definitiva quedaba guardada **sin el
     * aporte de la subunidad nueva** — y si el profesor había bajado los
     * porcentajes de las demás para hacerle sitio, bajaba el doble. Desde Flutter,
     * que crea subunidades y nunca llama a /notas, la ventana podía durar días.
     *
     * **Lo que se comprueba es el estado inmediatamente después del alta**, sin
     * pasar por ninguna otra pantalla: si el test pidiera /notas por el camino, la
     * cerraría él mismo y no mediría nada.
     */
    public function test_crear_una_subunidad_la_crea_con_sus_notas_y_su_definitiva(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $unidadId = DB::table('subunidades')
            ->where('id', DB::table('notas')->where('id', $ctx['notas'][0])->value('subunidad_id'))
            ->value('unidad_id');

        // La quinta subunidad, con nota por defecto 40 en el cuerpo.
        //
        // **Y aquí la fase 0 del
        // [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md) cambia lo
        // que este test defiende, a mejor.** Antes: el aporte nuevo era 50 % de la
        // unidad × 50 % de la subunidad × 40 = 10 sobre los 20 que ya había, o sea
        // **30**. Dicho en voz alta: **crear un indicador le subía la nota a todo el
        // grupo**, sin que nadie hubiera evaluado nada.
        //
        // Ahora las casillas nacen vacías, así que la definitiva **no se mueve**: 20 y
        // 20. La §5.1 sigue defendida —lo que le importa es que las notas nazcan con la
        // subunidad y que el recálculo se dispare, no que el número suba— y de paso
        // esto pasa a fijar lo contrario de lo que fijaba: **añadir un indicador no
        // toca la nota de nadie**.
        $r = $this->withToken($token)->postJson('/api/subunidades', [
            'unidad_id' => $unidadId,
            'definicion' => 'SUBUNIDAD NUEVA',
            'porcentaje' => 50,
            'nota_default' => 40,
        ])->assertStatus(201);

        $nuevaId = (int) $r->json('id');

        $this->assertGreaterThan(0, DB::table('notas')
            ->where('subunidad_id', $nuevaId)->whereNull('deleted_at')->count(),
            'La subunidad nació sin notas: la ventana de la §5.1 sigue abierta y '
            .'sólo la cerraría alguien abriendo /notas.');

        $this->assertSame(20.0, $this->definitivaDe($ctx),
            'Crear una subunidad movió la definitiva: sus casillas nacieron con nota en vez de vacías.');
    }

    /**
     * La definitiva del alumno del montaje, como número.
     *
     * @param  array<string, mixed>  $ctx
     */
    /**
     * Y la definitiva **viaja en la respuesta de `putUpdate`**, para que la
     * planilla no necesite una petición más por cada nota tecleada.
     *
     * Es un campo **añadido**: la nota se sigue devolviendo con sus mismas
     * claves. Por eso el test comprueba las dos cosas a la vez — que el campo
     * nuevo está y que lo viejo no se movió—, que es lo que separa «añadir» de
     * «cambiar» para los cuatro clientes que ya leen esta respuesta.
     */
    public function test_la_respuesta_de_editar_trae_la_definitiva_recalculada(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $cuerpo = $this->withToken($token)
            ->putJson('/api/notas/update/'.$ctx['notas'][0], ['nota' => 40])
            ->assertStatus(200)
            ->json();

        // Lo de siempre sigue estando.
        $this->assertSame($ctx['notas'][0], (int) $cuerpo['id']);
        $this->assertSame(40, (int) $cuerpo['nota']);

        // Y lo nuevo: la misma definitiva que quedó guardada, sin ir a buscarla.
        $this->assertNotNull($cuerpo['definitiva'], 'La respuesta no trae la definitiva.');
        $this->assertSame(25, $cuerpo['definitiva']['nota']);
        $this->assertSame($ctx['alumno'], $cuerpo['definitiva']['alumno_id']);
        $this->assertSame($ctx['asignatura'], $cuerpo['definitiva']['asignatura_id']);
        $this->assertSame($ctx['periodo'], $cuerpo['definitiva']['periodo_id']);
        $this->assertFalse($cuerpo['definitiva']['manual']);

        $this->assertSame(25.0, $this->definitivaDe($ctx),
            'La respuesta y la tabla dicen cosas distintas.');
    }

    /**
     * Y cuando la definitiva es **manual**, la respuesta trae **la guardada, no
     * la calculada**. Es la diferencia que hace útil este campo en vez de
     * peligroso.
     *
     * El servicio respeta las filas `manual` y `recuperada`: no las reescribe.
     * Así que si esto devolviera lo calculado, la pantalla pintaría un número
     * que la base no tiene — y justo en las filas que alguien puso a mano, que
     * son las que más se miran. El profesor vería su nota cambiar sola al
     * teclear en una celda de al lado, y al recargar volvería la suya.
     */
    public function test_si_la_definitiva_es_manual_la_respuesta_trae_la_guardada(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->update(['nota' => 99, 'manual' => 1]);

        $cuerpo = $this->withToken($token)
            ->putJson('/api/notas/update/'.$ctx['notas'][0], ['nota' => 40])
            ->assertStatus(200)
            ->json();

        $this->assertSame(99, $cuerpo['definitiva']['nota'],
            'Devolvió lo calculado en vez de lo guardado: la pantalla pintaría un número que no existe.');
        $this->assertTrue($cuerpo['definitiva']['manual']);

        $this->assertSame(99.0, $this->definitivaDe($ctx),
            'Y además la escribió, que es lo que el servicio promete no hacer.');
    }

    private function definitivaDe(array $ctx): ?float
    {
        $valor = DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->value('nota');

        return $valor === null ? null : (float) $valor;
    }

    /**
     * Una asignatura con dos unidades al 50%, dos subunidades al 50% en cada una,
     * y una nota de 20 en cada subunidad para dos alumnos.
     *
     * Se monta y no se busca: el seed trae asignaturas reales con porcentajes
     * cualesquiera, y con ellos la aritmética deja de poder hacerse de cabeza —
     * que es justo lo que hace legible a este test. Es la regla del 09: **si lo
     * que falta es la fila, se monta en el test que la necesita.**
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function asignaturaConNotas(): array
    {
        // **Tiene que ser un Profesor y con el periodo abierto**, y las dos mitades
        // se aprendieron aquí: `User::pueden_editar_notas` sólo deja pasar a un
        // `Profesor` o a un superusuario —un `Usuario` llano recibe 403— y además
        // mira el interruptor del periodo (§27). Con cualquiera de las dos cosas
        // mal, este test falla por el guard y no por el recálculo, que es
        // exactamente el fallo que no enseña nada.
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $usuario = $profesor;
        $periodoId = (int) $suyo->id;

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
            WHERE a.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM matriculas m WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1', [$periodoId]);

        $this->assertNotNull($asignatura, 'El seed no tiene una asignatura con matrículas en el año del usuario.');

        $alumnos = DB::select('SELECT DISTINCT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 2',
            [$asignatura->grupo_id]);

        $this->assertCount(2, $alumnos, 'El montaje necesita dos alumnos matriculados.');

        $notas = [];

        foreach ([1, 2] as $numeroUnidad) {
            $unidadId = DB::table('unidades')->insertGetId([
                'asignatura_id' => $asignatura->id,
                'periodo_id' => $periodoId,
                'definicion' => 'UNIDAD DE PRUEBA '.$numeroUnidad,
                'porcentaje' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([1, 2] as $numeroSub) {
                $subId = DB::table('subunidades')->insertGetId([
                    'unidad_id' => $unidadId,
                    'definicion' => 'SUB '.$numeroUnidad.'.'.$numeroSub,
                    'porcentaje' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($alumnos as $alumno) {
                    $notaId = DB::table('notas')->insertGetId([
                        'subunidad_id' => $subId,
                        'alumno_id' => $alumno->alumno_id,
                        'nota' => 20,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ((int) $alumno->alumno_id === (int) $alumnos[0]->alumno_id) {
                        $notas[] = $notaId;
                    }
                }
            }
        }

        // El estado de partida lo deja el propio servicio, que es lo que hace que
        // el test mida el DISPARADOR y no el cálculo: si esto no escribiera nada,
        // los asertos de arriba caerían por la razón equivocada.
        // `$usuario` es la fila cruda de `users`, así que su clave es `id`. El
        // `user_id` de `$this->user` es otra cosa —lo aplana `ContextoDeUsuario`—
        // y confundirlos es la misma trampa que `persona_id` (CLAUDE.md).
        DefinitivasDeAsignatura::recalcular(
            (int) $asignatura->id, $periodoId, (int) $usuario->id
        );

        return [$token, [
            'asignatura' => (int) $asignatura->id,
            'periodo' => $periodoId,
            'alumno' => (int) $alumnos[0]->alumno_id,
            'notas' => $notas,
        ]];
    }
}
