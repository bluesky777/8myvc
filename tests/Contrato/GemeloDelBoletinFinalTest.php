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
        $this->assertGreaterThan(0, $comparadas,
            'El seed no dio ningún alumno con dos asignaturas perdidas, así que este test '
            .'no comparó ni un solo par de objetos: no está midiendo nada.');
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

        $this->assertLessThanOrEqual(1, $conteo['periodos'],
            'La consulta invariante de los periodos volvió a entrar en un bucle: '
            .$conteo['periodos'].' ejecuciones sobre '.$forma->alumnos.' alumnos × '
            .$forma->asignaturas.' asignaturas. Si crece con el grupo, el memo de '
            .'`periodosDelAnio()` dejó de servir.');
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
