<?php

namespace Tests\Contrato;

use App\Http\Controllers\BolfinalesController;
use App\User;
use Illuminate\Http\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

/**
 * El gemelo de la raíz: que el desanidado no le mueva el resultado.
 *
 * `app/Http/Controllers/BolfinalesController.php` **no tiene ruta propia**: las
 * cuatro de `bolfinales/*` van al de `Informes/`, que es otro fichero. A éste se
 * llega **sólo** por `new BolfinalesController` desde
 * `CertificadosEstudioController`, y por eso este test lo instancia igual que él
 * en vez de pedir una URL.
 *
 * ## Por qué el test entra por debajo y no por HTTP
 *
 * Porque **por HTTP no se puede ver el resultado**: las dos rutas que llegan aquí
 * dan **500 en el 100% de las llamadas** y no por un caso raro, sino por dos
 * causas apiladas, cualquiera de las cuales bastaría —la vista
 * `certificados.estudio` no está en el repositorio, y `App::make('dompdf.wrapper')`
 * pide un binding que **nunca existió**: no hay ningún paquete de PDF en
 * `composer.json`, en `composer.lock`, en `vendor/` ni en `config/`—.
 *
 * O sea que `detailedNotasGrupo()` **corre entero y su resultado no lo ve nadie**.
 * Un test que mirase el código de respuesta vería 500 antes y 500 después y daría
 * verde con el resultado cambiado dentro. **Lo que hay que mirar es el objeto que
 * devuelve el método**, que es lo único que el arreglo puede romper.
 *
 * ## Lo que fija este fichero, y lo que NO
 *
 * Aquí van las dos comprobaciones **baratas** —la identidad de los objetos y la
 * cota de la invariante—, que pueden correr con la suite entera.
 *
 * La comprobación **cara** —recalcular las 1.480 consultas de una en una con el
 * SQL original y comparar valor a valor— vive en
 * `tests/Barrido/EquivalenciaDelGemeloTest.php`, en el grupo `barrido`, porque
 * ejecuta un boletín final completo dos veces. **Es la que de verdad prueba que
 * los números no se movieron**; ésta sólo prueba que la forma no se movió.
 */
class GemeloDelBoletinFinalTest extends CasoDeContrato
{
    /**
     * «Los periodos del año», en la forma que genera Eloquent.
     *
     * **Copiado de `BoletinFinalConsultaInvarianteTest`, donde está el porqué de
     * cada una de las tres condiciones.** Se copia y no se comparte por la misma
     * razón que se dijo allí: sacarlo a un sitio común lo pondría a merced de
     * cualquiera. **Si aquél cambia, éste hay que cambiarlo a mano.**
     *
     * La que decide es la tercera: la invariante **no une con nada**. Sin ella, la
     * consulta de las definitivas —que hace `inner join periodos p`— entraría en el
     * conteo y la cota mediría otra cosa.
     */
    private static function esDeLosPeriodosDelAnio(string $sql): bool
    {
        return preg_match('~\bfrom\s+`?periodos`?\b~i', $sql) === 1
            && stripos($sql, 'year_id') !== false
            && stripos($sql, 'join') === false;
    }

    /**
     * El contexto que este controlador tiene cuando lo llaman de verdad.
     *
     * `CertificadosEstudioController` hace `User::fromToken()` y luego
     * `new BolfinalesController`, así que el memo de `periodosDelAnio()` —que vive
     * en los `attributes` de la petición— tiene que resolverse contra **una**
     * petición. Se construye una y se mete en el contenedor, que es lo que
     * `Request::instance()` acaba leyendo.
     *
     * `Facade::clearResolvedInstance('request')` no es adorno: la fachada memoriza
     * la instancia resuelta, y sin limpiarla `User::fromToken()` seguiría mirando
     * la petición anterior del test.
     *
     * @return array{0: object, 1: BolfinalesController}
     */
    private function contextoYControlador(object $grupo): array
    {
        $token = $this->tokenDelPersonalDe((int) $grupo->year_id);

        $peticion = PeticionHttp::create('/api/certificados-estudio/certificado-grupo/'.$grupo->id, 'GET');
        $peticion->headers->set('Authorization', 'Bearer '.$token);

        $this->app->instance('request', $peticion);
        Facade::clearResolvedInstance('request');

        $user = User::fromToken();

        $this->assertNotNull($user,
            'No se resolvió el contexto de usuario: sin él este test no mide el camino real.');

        return [$user, new BolfinalesController];
    }

    /**
     * Cada asignatura perdida conserva **sus propios** objetos de periodo.
     *
     * Es la trampa que el hermano ya pagó, y la razón de que
     * `periodosDelAnio()` devuelva `->map(clone)` en vez del array memoizado tal
     * cual: el bucle de `asignaturasPerdidasDeAlumno` escribe
     * `$periodo->cantNotasPerdidas` **dentro** de cada periodo. Antes eso era
     * inofensivo porque cada asignatura hacía su propio
     * `Periodo::where(...)->get()` y recibía objetos nuevos; al sacar la consulta
     * del bucle, **compartir los objetos haría que todas las asignaturas mostraran
     * la cuenta de la última**.
     *
     * ## Y por qué el aserto es de identidad y no de valores
     *
     * Porque **la identidad no depende del seed**. Un aserto del tipo «estas dos
     * asignaturas tienen cuentas distintas» daría verde por casualidad el día que
     * el seed las tuviera iguales, y entonces el test estaría midiendo el seed y no
     * el arreglo. Si los objetos son el mismo, el fallo existe **haya o no
     * diferencia de valores hoy**.
     *
     * **Ninguna cota de consultas ve esto**: mismo número de consultas, resultado
     * distinto. Por eso está escrito aparte.
     */
    public function test_cada_asignatura_perdida_conserva_sus_propios_periodos(): void
    {
        $grupo = $this->grupoConAlumnos();

        [$user, $bol] = $this->contextoYControlador($grupo);

        $datos = $bol->detailedNotasGrupo($grupo->id, $user);

        [, $year, $alumnos] = $datos;

        $comparadas = 0;

        foreach ($alumnos as $alumno) {
            if (! isset($alumno->asignaturas_perdidas) || count($alumno->asignaturas_perdidas) < 2) {
                continue;
            }

            $vistos = [];

            foreach ($alumno->asignaturas_perdidas as $asignatura) {
                foreach ($asignatura->periodos as $periodo) {
                    $huella = spl_object_id($periodo);

                    $this->assertArrayNotHasKey($huella, $vistos,
                        'Dos asignaturas perdidas del alumno '.$alumno->alumno_id.' comparten el MISMO '
                        .'objeto de periodo (id '.$periodo->id.', ya visto en la asignatura '
                        .($vistos[$huella] ?? '?').'). El bucle escribe `cantNotasPerdidas` dentro del '
                        .'periodo, así que compartirlo hace que todas las asignaturas muestren la cuenta '
                        .'de la última. Falta el `clone` en `periodosDelAnio()`.');

                    $vistos[$huella] = $asignatura->asignatura_id;
                    $comparadas++;
                }
            }

            // Y tampoco pueden ser los del año, que se serializan aparte y limpios.
            foreach ($year->periodos as $delAnio) {
                $this->assertArrayNotHasKey(spl_object_id($delAnio), $vistos,
                    'Una asignatura perdida está usando el MISMO objeto que `$year->periodos`, '
                    .'así que el informe del año sale con `cantNotasPerdidas` escrito encima.');
            }
        }

        // **La mitad que impide el falso verde.** Sin alumnos con dos asignaturas
        // perdidas no se comparó nada, y «0 comparaciones y ningún fallo» se lee
        // igual que «todo bien». Es la trampa de la §186.1.
        //
        // **Y el mensaje separa las dos causas a propósito, porque salían por la
        // misma puerta y una de ellas mandaba a mirar al sitio equivocado.** Medido
        // rompiendo el arreglo: al quitar el `clone`, las asignaturas comparten la
        // Collection, el `unset` de la primera se la vacía a las siguientes y **las
        // asignaturas se caen del resultado**, así que ningún alumno llega a tener
        // dos y este aserto salta. Cazaba el fallo y lo diagnosticaba como «el seed
        // no da material»: un rojo verdadero con la explicación equivocada, que es
        // tan caro como un falso rojo porque manda a revisar el seed en vez del
        // `clone`.
        $this->assertGreaterThan(0, $comparadas,
            "Este test no comparó ni un solo par de objetos, y hay DOS causas posibles:\n"
            ."  (a) el arreglo está roto: sin el `clone` en `periodosDelAnio()` las asignaturas\n"
            ."      comparten la Collection, el `unset` de una vacía la de las demás y las\n"
            ."      asignaturas se caen del resultado. MIRA ESTO PRIMERO.\n"
            ."  (b) el seed no tiene ningún alumno con dos asignaturas perdidas.\n"
            .'Alumnos en el informe: '.count($alumnos).'; con `asignaturas_perdidas`: '
            .count(array_filter($alumnos, fn ($a) => isset($a->asignaturas_perdidas))).'. '
            .'Si el segundo número es alto y aun así no se comparó nada, es (a).');
    }

    /**
     * La invariante se pide **una vez**, y no una por alumno × asignatura.
     *
     * La cota es **1** y no 3 como en el hermano, y la diferencia está medida, no
     * elegida: allí hay dos ramas según venga `periodo_a_calcular` en el cuerpo, y
     * aquí **ese parámetro no existe** — este controlador siempre pide todos los
     * periodos del año. Una sola forma de consulta, un solo memo, una consulta.
     *
     * Lo que la cota afirma es lo único que importa: **que el número no crezca con
     * los alumnos ni con las asignaturas.** Eran 408 en 37 × 10 (05 §224).
     */
    public function test_la_invariante_de_periodos_no_crece_con_el_tamano_del_grupo(): void
    {
        $grupo = $this->grupoConAlumnos();

        [$user, $bol] = $this->contextoYControlador($grupo);

        $forma = DB::selectOne(
            'SELECT
                (SELECT COUNT(DISTINCT m.alumno_id) FROM matriculas m
                  WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                    AND m.estado IN ("MATR","ASIS","PREM")) AS alumnos,
                (SELECT COUNT(*) FROM asignaturas a
                  WHERE a.grupo_id = ? AND a.deleted_at IS NULL) AS asignaturas',
            [$grupo->id, $grupo->id]
        );

        $conteo = ['total' => 0, 'periodos' => 0];

        DB::listen(function ($consulta) use (&$conteo) {
            $conteo['total']++;

            if (self::esDeLosPeriodosDelAnio($consulta->sql)) {
                $conteo['periodos']++;
            }
        });

        $bol->detailedNotasGrupo($grupo->id, $user);

        // **Las dos mitades del aserto.** Un `DB::listen` que no se engancha cuenta
        // cero, y cero es el mejor número posible en una cota del tipo «no más de
        // N»: el test pasaría igual sin medir nada.
        $this->assertGreaterThan(0, $conteo['total'],
            'El oyente no contó ninguna consulta: la cota de abajo no mide nada.');

        // **Exactamente 1, y no «no más de 1», que es lo que pedía el reflejo.**
        // Una cota `<=` dejaría pasar el CERO, y cero aquí no es «mejor todavía»:
        // significaría que el memo **sobrevivió a la petición anterior** y que esta
        // llamada está sirviendo los periodos que leyó otra. Es el fallo que cazó al
        // hermano —daba 0 donde tenía que haber 1— y el que hizo que el memo se
        // mudara de una propiedad de la clase a los `attributes` de la petición.
        //
        // Con `assertSame` el aserto vigila **las dos direcciones**: que no crezca
        // con el grupo, y que siga preguntando una vez.
        $this->assertSame(1, $conteo['periodos'],
            'La invariante de los periodos no se pidió exactamente una vez: '
            .$conteo['periodos'].' ejecuciones sobre '.$forma->alumnos.' alumnos × '
            .$forma->asignaturas.' asignaturas. Si es MÁS, volvió a entrar en un bucle. '
            .'Si es CERO, el memo de `periodosDelAnio()` cruzó la frontera de la petición '
            .'y esta llamada está leyendo lo que preguntó otra.');
    }

    /**
     * Un alumno en **`ASIS`** sigue contando sus notas perdidas.
     *
     * ## Este test existe porque el seed NO puede probar esto solo
     *
     * Medido el 25: **la base de tests tiene CERO matrículas en `ASIS`** —cero en
     * toda la base, no cero en este grupo—. El grupo 98 tiene 37 en `MATR` y 31 en
     * `RETI`, y nada más.
     *
     * Eso hace que `(m.estado="MATR" OR m.estado="ASIS")` y `m.estado="MATR"`
     * **devuelvan exactamente lo mismo** sobre este seed, y por tanto que
     * **ningún** test que se limite a leerlo pueda distinguirlos. Se comprobó
     * rompiendo el arreglo a propósito: copiando el `m.estado = "MATR"` del hermano
     * sobre `perdidasPorAlumnoDelGrupo()`, `EquivalenciaDelGemeloTest`
     * **pasaba en verde**, con sus nueve asertos y su recálculo de 2.960 consultas.
     *
     * O sea que la diferencia entre los gemelos —la que más trabajo dio de
     * respetar— **no la sostenía ninguna red: sólo la sostenía haberla escrito
     * bien**. El día que alguien «limpie» esto copiando el SQL del hermano, sin
     * este test no se pone nada en rojo y **los alumnos en `ASIS` desaparecen del
     * informe**.
     *
     * ## Por eso fabrica la fila, en vez de buscarla
     *
     * `DatabaseTransactions` hace que el `UPDATE` no sobreviva al test, así que es
     * inocuo. **Fabricar el caso que el seed no tiene es lo único que puede cerrar
     * este agujero**, y es más honesto que un `markTestSkipped`: un test que se
     * salta a sí mismo cuenta como verde en el resumen.
     *
     * El aserto mira **el resultado**: que el alumno siga en el informe con sus
     * asignaturas perdidas y **con las mismas cuentas** que tenía en `MATR`. Con
     * `MATR` a secas su mapa queda vacío, sus asignaturas se caen con el `unset` y
     * **el alumno entero sale del informe**.
     */
    public function test_un_alumno_en_asis_sigue_contando_sus_notas_perdidas(): void
    {
        $grupo = $this->grupoConAlumnos();

        [$user, $bol] = $this->contextoYControlador($grupo);

        [, , $antes] = $bol->detailedNotasGrupo($grupo->id, $user);

        // Un alumno que HOY tenga asignaturas perdidas: si eligiéramos uno sin
        // ellas, el test daría verde con el fallo puesto (no habría nada que perder).
        $conPerdidas = array_values(array_filter($antes, fn ($a) => isset($a->asignaturas_perdidas)));

        $this->assertNotSame([], $conPerdidas,
            'Ningún alumno del grupo tiene asignaturas perdidas, así que pasar uno a ASIS '
            .'no puede demostrar nada. Este test no está midiendo.');

        $elegido = $conPerdidas[0];
        $cuentaAntes = $this->cuentasPorAsignaturaYPeriodo($elegido);

        $this->assertNotSame([], $cuentaAntes,
            'El alumno elegido tiene `asignaturas_perdidas` pero ninguna cuenta dentro.');

        // La fila que el seed no tiene. Vive dentro de la transacción del test.
        //
        // **SIN `grupo_id`, y esto costó una vuelta.** La primera versión sólo pasaba
        // a ASIS las matrículas de ESTE grupo, y con eso **el sabotaje seguía sin
        // caer**: la consulta une `matriculas` por `m.alumno_id = n.alumno_id` **y no
        // filtra el grupo**, así que a un alumno con una matrícula viva en `MATR` en
        // cualquier otro grupo —otro año, por ejemplo— el `JOIN` le sigue valiendo y
        // el `MATR` a secas no se nota. Es la consulta original, que tampoco filtraba
        // el grupo; el arreglo la conserva tal cual y por eso el test tiene que
        // vaciar **todas** sus matrículas vivas, no las de aquí.
        $tocadas = DB::update(
            'UPDATE matriculas SET estado = "ASIS" WHERE alumno_id = ? AND deleted_at IS NULL',
            [$elegido->alumno_id]
        );

        $this->assertGreaterThan(0, $tocadas,
            'No se pasó a ASIS ninguna matrícula: sin eso el test vuelve a medir el caso MATR.');

        // **Y se comprueba que la fabricación de verdad quitó todos los `MATR`.**
        // Sin esto, un alumno con una matrícula viva en otro grupo dejaría el test
        // en verde con el fallo puesto — que es exactamente lo que pasó la primera vez.
        $quedan = DB::selectOne(
            'SELECT COUNT(*) n FROM matriculas WHERE alumno_id = ? AND deleted_at IS NULL AND estado = "MATR"',
            [$elegido->alumno_id]
        );

        $this->assertSame(0, (int) $quedan->n,
            'Al alumno '.$elegido->alumno_id.' le quedan '.$quedan->n.' matrículas vivas en MATR, '
            .'así que el `JOIN` con `matriculas` le sigue valiendo por ahí y este test NO puede '
            .'ver la diferencia entre `MATR` y `(MATR or ASIS)`. No está midiendo lo que dice.');

        // Y el controlador se pregunta otra vez, con una petición nueva: el memo de
        // `periodosDelAnio()` vive en los `attributes` de la anterior, y reutilizarla
        // mediría la respuesta vieja.
        [$user2, $bol2] = $this->contextoYControlador($grupo);
        [, , $despues] = $bol2->detailedNotasGrupo($grupo->id, $user2);

        $mismo = null;
        foreach ($despues as $alumno) {
            if ((int) $alumno->alumno_id === (int) $elegido->alumno_id) {
                $mismo = $alumno;
                break;
            }
        }

        $this->assertNotNull($mismo,
            'El alumno '.$elegido->alumno_id.' desapareció del informe al pasar a ASIS. '
            .'`Grupo::alumnos()` trae MATR, ASIS y PREM, así que esto no es el listado: '
            .'es que algo de abajo dejó de reconocer ASIS.');

        $this->assertTrue(isset($mismo->asignaturas_perdidas),
            'El alumno '.$elegido->alumno_id.' se quedó SIN `asignaturas_perdidas` sólo por '
            .'pasar a ASIS. Es el síntoma exacto de haber copiado el `m.estado = "MATR"` del '
            .'hermano sobre `perdidasPorAlumnoDelGrupo()`: el mapa se queda vacío para él, sus '
            .'asignaturas se caen con el `unset` y el alumno sale del informe entero.');

        $this->assertSame($cuentaAntes, $this->cuentasPorAsignaturaYPeriodo($mismo),
            'Las notas perdidas del alumno '.$elegido->alumno_id.' cambiaron al pasarlo a ASIS, '
            .'y no deberían: la consulta original cuenta MATR y ASIS por igual.');
    }

    /**
     * `[asignatura_id][periodo_id] => cantNotasPerdidas` de un alumno del informe.
     *
     * @return array<int, array<int, int>>
     */
    private function cuentasPorAsignaturaYPeriodo(object $alumno): array
    {
        $cuentas = [];

        foreach ($alumno->asignaturas_perdidas ?? [] as $asignatura) {
            foreach ($asignatura->periodos as $periodo) {
                $cuentas[(int) $asignatura->asignatura_id][(int) $periodo->id]
                    = (int) $periodo->cantNotasPerdidas;
            }
        }

        ksort($cuentas);
        foreach ($cuentas as $k => $v) {
            ksort($v);
            $cuentas[$k] = $v;
        }

        return $cuentas;
    }

    /**
     * Y el predicado del conteo se ejerce, porque si no la cota de arriba es aire.
     *
     * **Un predicado que nadie comprueba es media medición**: el día que alguien lo
     * estreche, la cota sigue en verde y el número cambia sin que nada avise. Aquí
     * la forma que importa es **la de Eloquent**, porque es la que este fichero
     * genera — el hermano genera SQL crudo y no sirve de control.
     */
    public function test_el_predicado_reconoce_la_forma_de_eloquent_y_rechaza_la_del_join(): void
    {
        $this->assertTrue(self::esDeLosPeriodosDelAnio(
            'select * from `periodos` where `year_id` = ? and `periodos`.`deleted_at` is null'),
            'Dejó de reconocer la forma de Eloquent, que es la ÚNICA que genera este controlador: '
            .'`Periodo::where(...)->get()` con SoftDeletes.');

        $this->assertFalse(self::esDeLosPeriodosDelAnio(
            'SELECT n.alumno_id, a.id as asignatura_id FROM asignaturas a '
            .'inner join periodos p on p.year_id=:year_id and p.id=u.periodo_id'),
            'Volvió a contar la consulta de las definitivas, que pasa por `periodos` con un '
            .'`join` sin ser «los periodos del año».');
    }
}
