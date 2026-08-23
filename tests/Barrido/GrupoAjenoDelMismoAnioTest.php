<?php

namespace Tests\Barrido;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * **Las siete que el barrido grande no puede medir, medidas aparte.**
 *
 * `SuperficieDeUnTokenTest` elige como `{grupo_id}` ajeno el único grupo que no es del
 * sujeto, y en este seed **eso es también «de otro año»**: hay dos grupos vivos, uno
 * por año. Para las rutas que acotan por `$user->year_id`, un vacío no distingue «el
 * guard lo impidió» de «el filtro de año no encontró nada» — y **la segunda pasada de
 * control tampoco lo separa**, porque su superusuario está en el mismo año que el
 * sujeto y ve el mismo vacío por la misma razón. Ver
 * [noche-2026-08-23/i.md](../../docs/migracion/noche-2026-08-23/i.md).
 *
 * De las 28 rutas con grupo en la URL, 19 no miran el año y dos salieron como hallazgo
 * igual. **Quedan siete**, y son las de aquí abajo.
 *
 * Este fichero no cambia el barrido grande —tocarlo movería los cinco números que son
 * la entrega del lote—: monta el caso que allí no existe y golpea **sólo esas siete**.
 *
 * ## Lo que hay que fabricar, y por qué eso no es hacer trampa
 *
 * Un grupo ajeno **del mismo año** no se puede sacar del seed: no hay ninguno. Y uno
 * vacío tampoco sirve —los boletines de un grupo sin alumnos salen vacíos por no tener
 * a quién calcular, que es otra vez el vacío que no prueba nada—, así que hace falta
 * un grupo del año del sujeto **con un alumno matriculado dentro**.
 *
 * Es la misma línea que el barrido grande ya tiene escrita para las papeleras:
 * **preparar el sujeto no es fabricar el efecto**. Matricular a un alumno en un grupo
 * es elegir a quién se le pregunta; montar la fila que la ruta escribiría, no. Y lo
 * deshace la transacción del test.
 *
 * Va en el grupo `barrido` por lo mismo que su hermano: tarda, no afirma casi nada e
 * imprime.
 */
#[Group('barrido')]
class GrupoAjenoDelMismoAnioTest extends CasoDeContrato
{
    /** Las siete cuyo silencio el barrido grande no puede explicar. */
    private const LAS_SIETE = [
        ['GET', 'api/boletines/detailed-notas-year/{grupo}/1'],
        ['GET', 'api/boletines2/detailed-notas-year/{grupo}/1'],
        ['GET', 'api/boletines3/detailed-notas-year/{grupo}/1'],
        ['GET', 'api/observador/vertical/{grupo}/10'],
        ['GET', 'api/piars-asignaturas/asignaturas/{grupo}/{alumno}'],
        ['PUT', 'api/observador-horizontal/horizontal/{grupo}'],
        ['PUT', 'api/puestos/detailed-notas-periodo/{grupo}'],
    ];

    /** Columnas que, si salen con valor, son el dato personal de alguien. */
    private const PERSONALES = [
        'num_doc', 'documento', 'telefono', 'celular', 'direccion', 'fecha_nac',
        'email', 'barrio', 'estado_civil',
    ];

    public function test_que_ve_un_profesor_de_un_grupo_ajeno_de_su_mismo_anio(): void
    {
        $this->medirCon($this->usuarioDeTipo('Profesor'), 'Profesor');
    }

    /**
     * Y lo mismo con un administrativo **sin ningún rol**, para que la corrección sea
     * simétrica: si el barrido grande estaba contando de menos, lo estaba haciendo con
     * los dos sujetos, y las dos cifras —164 y 145— se corrigen igual.
     *
     * El sujeto se exige `is_superuser = 0` **y sin roles**, por lo mismo que en el
     * barrido: `usuarioDeTipo('Usuario')` devuelve el usuario 1, que es superusuario, y
     * medirlo sería medir el control. Ver §111.
     */
    public function test_que_ve_un_administrativo_sin_rol_del_mismo_grupo_ajeno(): void
    {
        $llano = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.is_superuser = 0
              AND NOT EXISTS (SELECT 1 FROM role_user ru WHERE ru.user_id = u.id)
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($llano,
            'El seed no tiene ningún Usuario sin superusuario y sin roles.');

        $this->medirCon($llano, 'Usuario sin rol');
    }

    private function medirCon(object $profe, string $etiqueta): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $token = $this->tokenDe($profe->username);

        // El año se lee DESPUÉS de entrar: `Services\Login` reescribe `periodo_id`.
        // El año se lee DESPUÉS de entrar: `Services\Login` reescribe `periodo_id`.
        $suyo = DB::selectOne('SELECT p.year_id, p.id AS periodo_id, p.numero FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?', [$profe->id]);

        $ajeno = $this->grupoAjenoDelMismoAnio((int) $suyo->year_id);

        // Un alumno dentro, o el vacío vuelve a no probar nada. Se coge uno que ya
        // existe y se le añade una matrícula en el grupo nuevo; la transacción del
        // test la deshace.
        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ?
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suyo->year_id]);

        $this->assertNotNull($alumno, 'El seed no tiene alumnos en el año del sujeto.');

        DB::table('matriculas')->insert([
            'alumno_id' => $alumno->id,
            'grupo_id' => $ajeno->grupo_id,
            'estado' => 'MATR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profesorId = DB::table('profesores')->where('user_id', $profe->id)->value('id');
        $suyas = DB::table('asignaturas')->where('grupo_id', $ajeno->grupo_id)
            ->where('profesor_id', $profesorId)->count();

        $salida = [];
        $salida[] = "{$etiqueta}: {$profe->username} (profesor_id "
            .var_export($profesorId, true)."), año {$suyo->year_id}.";
        $salida[] = "Grupo ajeno del MISMO año: {$ajeno->grupo_id}, con 1 alumno dentro "
            ."y {$suyas} asignaturas suyas.";
        $salida[] = '';

        foreach (self::LAS_SIETE as [$verbo, $plantilla]) {
            $uri = str_replace(['{grupo}', '{alumno}'],
                [(string) $ajeno->grupo_id, (string) $alumno->id], $plantilla);

            $r = $this->withToken($token)->json($verbo, '/'.$uri, [
                'grupo_id' => $ajeno->grupo_id,
                'periodo_id' => $suyo->periodo_id,
                'num_periodo' => $suyo->numero,
                'periodo_a_calcular' => $suyo->numero,
                'alumno_id' => $alumno->id,
            ]);

            $cuerpo = (string) $r->getContent();
            $personales = [];

            foreach (self::PERSONALES as $c) {
                if (preg_match('/"'.$c.'":\s*"[^"]{1,}"/', $cuerpo)) {
                    $personales[] = $c;
                }
            }

            $salida[] = sprintf('  %-4s %-58s %3d  %7d b  %s', $verbo, $uri, $r->getStatusCode(),
                strlen($cuerpo), $personales === [] ? '' : 'PERSONALES: '.implode(',', $personales));
        }

        echo "\n".implode("\n", $salida)."\n";

        // Lo único que se afirma: que el caso llegó a montarse. Sin esto, una lista de
        // ceros se leería como «todas cerradas» y sería otra vez el vacío que no prueba
        // nada — que es justo el fallo que este fichero existe para no repetir.
        $this->assertSame(1, DB::table('matriculas')->where('grupo_id', $ajeno->grupo_id)
            ->whereNull('deleted_at')->count(),
            'El grupo ajeno tiene que tener un alumno dentro para que esto mida algo.');
    }
}
