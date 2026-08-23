<?php

namespace Tests\Barrido;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * **La otra mitad de la regla, comprobada desde el resultado.**
 *
 * La regla de negocio del repo —confirmada y no re-litigable— es que **un alumno solo
 * ve lo suyo y un acudiente, lo suyo y lo completo de sus acudidos**. El barrido por
 * tipo de token midió qué alcanza un acudiente y salieron 10 rutas, todas juzgadas
 * contra **su propio acudido**. Lo que nadie había comprobado desde el resultado es la
 * otra mitad: **que sobre un alumno ajeno no alcance nada.**
 *
 * Son 41 rutas las que llevan uno de los dos guards que implementan esa regla —17 con
 * `boletin.propio` y 24 con `persona.propia`—, y este fichero golpea las que un
 * acudiente puede pedir con un alumno concreto.
 *
 * ## Lo que hace falta montar, y por qué sin ello el cero no vale
 *
 * Un 403 no prueba nada por sí solo: puede venir del guard o de que no había nada que
 * pedir. Por eso cada caso lleva **su control, y un control que no comparte el sesgo**:
 * la misma petición con **el acudiente de verdad de ese alumno**. Si ahí sale el dato,
 * el 403 del otro es del guard; si ahí tampoco sale, el 403 no dice nada y hay que
 * decirlo.
 *
 * Es la lección que costó el `{grupo_id}` de esta misma noche: el control del barrido
 * grande estaba en el mismo año que el sujeto y **veía el mismo vacío por la misma
 * razón**. Un control que comparte el sesgo del sujeto confirma el sesgo.
 *
 * El seed trae el escenario sin fabricar nada: acudiente 11 (usuario 488) con el alumno
 * 460, y el alumno 634 **en el mismo grupo** con su propio acudiente 531. **Los dos a
 * paz y salvo**, para que esa rama del guard no confunda el resultado.
 *
 * Va en el grupo `barrido` por lo mismo que sus hermanos: mide e imprime.
 */
#[Group('barrido')]
class AcudienteSobreUnAjenoTest extends CasoDeContrato
{
    private const PERSONALES = [
        'num_doc', 'documento', 'telefono', 'celular', 'direccion', 'fecha_nac',
        'email', 'barrio',
    ];

    public function test_que_alcanza_un_acudiente_sobre_un_alumno_que_no_es_suyo(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $mio = DB::selectOne('SELECT p.acudiente_id, p.alumno_id, ac.user_id, u.username,
                g.id AS grupo_id, g.year_id
            FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1
            WHERE p.deleted_at IS NULL ORDER BY p.acudiente_id LIMIT 1');

        $this->assertNotNull($mio, 'El seed necesita un acudiente con acudido en el año actual.');

        $ajeno = DB::selectOne('SELECT p.alumno_id, p.acudiente_id, u.username
            FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
                AND m.grupo_id = ?
            WHERE p.deleted_at IS NULL AND p.acudiente_id <> ? AND p.alumno_id <> ?
            ORDER BY p.alumno_id LIMIT 1', [$mio->grupo_id, $mio->acudiente_id, $mio->alumno_id]);

        $this->assertNotNull($ajeno,
            'El seed necesita un alumno del MISMO grupo con otro acudiente: si fuera de otro '
            .'grupo o de otro año, un vacío lo explicaría eso y no el guard.');

        $salida = [];
        $salida[] = "Acudiente {$mio->username} (acudiente_id {$mio->acudiente_id}), "
            ."acudido {$mio->alumno_id}, grupo {$mio->grupo_id}.";
        $salida[] = "Alumno AJENO del mismo grupo: {$ajeno->alumno_id}, cuyo acudiente es "
            ."{$ajeno->acudiente_id} ({$ajeno->username}).";
        $salida[] = '';

        // Cada caso: [nombre, verbo, uri con {alumno}, cuerpo con {alumno}]
        $casos = [
            ['boletín por requested_alumnos', 'PUT',
                "api/boletines/detailed-notas/{$mio->grupo_id}",
                ['requested_alumnos' => [['alumno_id' => '{alumno}']]]],
            ['boletín 2', 'PUT',
                "api/boletines2/detailed-notas/{$mio->grupo_id}",
                ['requested_alumnos' => [['alumno_id' => '{alumno}']]]],
            ['boletín 3', 'PUT',
                "api/boletines3/detailed-notas/{$mio->grupo_id}",
                ['requested_alumnos' => [['alumno_id' => '{alumno}']]]],
            ['boletín final', 'PUT',
                "api/bolfinales/detailed-notas-year/{$mio->grupo_id}",
                ['requested_alumnos' => [['alumno_id' => '{alumno}']]]],
            ['notas del alumno (id por la URL)', 'GET',
                "api/notas/alumno/{alumno}/{$mio->grupo_id}", []],
            ['notas actuales', 'PUT',
                "api/notas-actuales-alumnos/{$mio->grupo_id}",
                ['requested_alumnos' => [['alumno_id' => '{alumno}']]]],
            ['certificado de persona', 'PUT', 'api/certificados-persona',
                ['alumno_id' => '{alumno}']],
            ['prematricular (ESCRIBE)', 'PUT', 'api/matriculas/prematricular',
                ['alumno_id' => '{alumno}', 'grupo_id' => $mio->grupo_id]],
            ['detalles del alumno', 'PUT', 'api/detalles/alumno',
                ['alumno_id' => '{alumno}']],
            ['sus actividades', 'PUT', 'api/mis-actividades/datos',
                ['alumno_id' => '{alumno}']],
            ['enfermería', 'PUT', 'api/enfermeria/datos',
                ['alumno_id' => '{alumno}', 'persona_id' => '{alumno}']],
            ['años con notas', 'PUT', 'api/alumnos/years-con-notas',
                ['alumno_id' => '{alumno}', 'persona_id' => '{alumno}']],
        ];

        $pedir = function (string $token, array $caso, int $alumno) {
            [, $verbo, $uri, $cuerpo] = $caso;
            $uri = str_replace('{alumno}', (string) $alumno, $uri);
            $json = str_replace('"{alumno}"', (string) $alumno, json_encode($cuerpo));

            return $this->withToken($token)->json($verbo, '/'.$uri, json_decode($json, true));
        };

        $tokenMio = $this->tokenDe($mio->username);
        $tokenDelAjeno = $this->tokenDe($ajeno->username);

        $salida[] = sprintf('  %-34s %-8s %-8s %s', 'caso', 'ajeno', 'control', 'qué sale en el control');
        $salida[] = '  '.str_repeat('-', 96);

        $alcanza = [];

        foreach ($casos as $caso) {
            $r1 = $pedir($tokenMio, $caso, (int) $ajeno->alumno_id);

            // El control NO comparte el sesgo: el mismo alumno, pero pedido por SU
            // acudiente. Si aquí no sale nada, el 403 de arriba no prueba nada.
            $r2 = $pedir($tokenDelAjeno, $caso, (int) $ajeno->alumno_id);

            $c2 = (string) $r2->getContent();
            $personales = [];

            foreach (self::PERSONALES as $c) {
                if (preg_match('/"'.$c.'":\s*"[^"]{1,}"/', $c2)) {
                    $personales[] = $c;
                }
            }

            $marca = $r1->getStatusCode() < 400 && strlen((string) $r1->getContent()) > 40
                ? ' <<< ALCANZA' : '';

            $salida[] = sprintf('  %-34s %-8s %-8s %s%s', $caso[0],
                $r1->getStatusCode(), $r2->getStatusCode(),
                strlen($c2).' b'.($personales === [] ? '' : ' · '.implode(',', $personales)),
                $marca);

            if ($marca !== '') {
                $alcanza[] = $caso[0].'  ->  '.substr(preg_replace('/\s+/', ' ',
                    (string) $r1->getContent()), 0, 150);
            }
        }

        if ($alcanza !== []) {
            $salida[] = '';
            $salida[] = 'LO QUE ALCANZA SOBRE UN ALUMNO QUE NO ES SUYO:';

            foreach ($alcanza as $l) {
                $salida[] = '  '.$l;
            }
        }

        echo "\n".implode("\n", $salida)."\n";

        // Lo único que se afirma, y es lo que impide el cero hueco: el escenario existe
        // y el alumno ajeno es de verdad ajeno.
        $this->assertSame(0, DB::table('parentescos')
            ->where('acudiente_id', $mio->acudiente_id)
            ->where('alumno_id', $ajeno->alumno_id)
            ->whereNull('deleted_at')->count(),
            'El alumno «ajeno» tiene que serlo, o todo este fichero mide otra cosa.');
    }
}
