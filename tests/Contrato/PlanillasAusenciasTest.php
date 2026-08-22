<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La planilla de control de entrada, y lo que cuesta imprimirla.
 *
 * `putTardanzaEntrada` monta el año entero: todos los grupos, todos sus alumnos
 * y, por cada alumno, una consulta más. Es la que alimenta las dos planillas de
 * papel que el colegio cuelga en la puerta —«Control entrada» y «Control
 * asistencia a clases»—, y era la única ruta de su controlador sin comprobar.
 *
 * No es un fallo de autorización: quien llega es personal. Lo que este test fija
 * es **lo que cuesta y lo que lleva dentro**, que es lo que no se ve mirando la
 * pantalla porque la pantalla sale bien.
 */
class PlanillasAusenciasTest extends CasoDeContrato
{
    /**
     * La planilla cuesta **una consulta por alumno**, y esa consulta añade una sola columna.
     *
     * `Grupo::alumnos()` ya devuelve del alumno el `user_id`, el `username`, el
     * `sexo`, la `fecha_nac`, la imagen y la foto. Encima de eso,
     * `Alumno::userData()` se llama **una vez por alumno** y trae exactamente lo
     * mismo más el **correo**. O sea que de las consultas de la petición, todas
     * las de alumno menos ninguna existen para añadir un campo.
     *
     * Medido el 22 ago 2026 contra la copia de desarrollo, con 13 grupos y 378
     * matriculados en el año actual: **392 consultas en una petición** —1 de
     * grupos, 13 de alumnos por grupo y 378 de `userData`—. Un colegio grande de
     * los dieciséis lo multiplica.
     *
     * Y las dos planillas que la consumen leen del alumno **`nombres`,
     * `apellidos` y `estado`**, nada más: `userData` no lo mira ninguna de las
     * dos, ni ninguna otra vista del front. Es decir que el correo de cada alumno
     * viaja hasta una hoja para imprimir donde no sale.
     *
     * Se fija y no se arregla: quitar `userData` encoge la respuesta, y la
     * respuesta es contrato con dieciséis copias del front que no se pueden
     * grepear desde aquí. Cuando se decida, este test es el que dice si el
     * arreglo funcionó — el número tiene que bajar a dos por grupo.
     */
    public function test_la_planilla_de_entrada_cuesta_una_consulta_por_alumno(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $consultas = [];
        DB::listen(function ($c) use (&$consultas) {
            $consultas[] = $c->sql;
        });

        $r = $this->withToken($token)->putJson('/api/planillas-ausencias/tardanza-entrada', []);
        $r->assertStatus(200);

        $cuerpo = $r->json();

        // La respuesta es el año **dentro de una lista de uno**, y es el contrato:
        // el front hace `grupos[0].grupos`. Un día que alguien devuelva el objeto
        // pelado, la planilla sale en blanco sin ningún error.
        $this->assertCount(1, $cuerpo, 'La planilla llega envuelta en una lista de uno, y el front la desenvuelve así.');

        $grupos = $cuerpo[0]['grupos'] ?? [];
        $this->assertNotEmpty($grupos, 'El año del usuario llegó sin grupos: el test no mediría nada.');

        $alumnos = [];
        foreach ($grupos as $grupo) {
            $alumnos = array_merge($alumnos, $grupo['alumnos'] ?? []);
        }

        $this->assertNotEmpty($alumnos, 'Los grupos llegaron sin alumnos: el test no mediría nada.');

        foreach (['nombres', 'apellidos', 'estado'] as $clave) {
            $this->assertArrayHasKey($clave, $alumnos[0],
                'Es uno de los tres campos que las dos planillas de papel leen del alumno.');
        }

        // Una consulta de `userData` por alumno, contadas por su forma y no por el
        // total: el total lo mueven el contexto y el token, y un test que lo fije
        // se rompe cada vez que se toca el arranque de una petición sin que este
        // problema haya cambiado.
        $porAlumno = count(array_filter($consultas,
            fn ($sql) => str_contains($sql, 'from alumnos a') && str_contains($sql, 'inner join users u')));

        $this->assertSame(count($alumnos), $porAlumno,
            'La planilla ha dejado de costar una consulta por alumno. Si es porque se '
            .'arregló, este número tiene que ser 0 y hay que cambiarlo aquí; si es porque '
            .'llegan menos alumnos, el test dejó de medir.');

        // Y lo que esa consulta añade, que es lo que hay que decidir si se quita.
        $this->assertArrayHasKey('userData', $alumnos[0]);
        $this->assertArrayHasKey('email', (array) $alumnos[0]['userData'],
            'El correo del alumno viaja hasta una planilla de papel que no lo imprime. '
            .'Está medido en la cabecera de este test; quitarlo es una decisión del colegio.');
    }
}
