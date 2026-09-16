<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **`PUT definitivas_periodos/calcular-grupo-periodo` reparte con el modo del
 * periodo que recalcula, no con el de la barra de año.**
 *
 * El método recibe `grupo_id` y `periodo_id` **por el cuerpo**, y con ellos hace el
 * `DELETE` de `notas_finales` y el `SELECT` que las repone. El modo, en cambio,
 * salía de `$this->user->year_id` — y en las ochenta líneas del método **no hay una
 * sola comprobación** de que ese periodo sea del año de la sesión. O sea que un
 * recálculo lanzado con la barra en un año y el periodo de otro **guardaba notas con
 * el reparto equivocado**.
 *
 * ## La regla ya estaba escrita, en el otro escritor
 *
 * `DefinitivasDeAsignatura::calcular` lleva esto en un comentario:
 *
 * > *«Éste es el único sitio que ESCRIBE una definitiva, así que aquí el modo no
 * > puede salir de la sesión: sale del periodo que se está calculando. Un recálculo
 * > lanzado desde el contexto de otro año guardaría con el reparto equivocado, y lo
 * > que queda escrito es una nota.»*
 *
 * **Y esa frase se equivoca en su propia premisa**: no es el único que escribe. Éste
 * es otro de los seis que la fase 3 del [10](../../docs/migracion/10-definitivas.md)
 * sustituye, **sigue desplegado en los dieciséis colegios**, y hacía justo lo que la
 * frase prohíbe. *La regla existía; lo que faltaba era aplicarla al vecino.*
 *
 * Lo encontró `myvc_front`, que había tropezado con la misma forma en una pantalla
 * suya y la formuló como patrón: **no es el cálculo, es de qué año se pregunta el
 * modo.** Con esa frase el barrido de este lado dio nueve sitios y éste fue el único
 * que fallaba.
 *
 * ## Por la interfaz no se llegaba, y eso cambia la urgencia y no la corrección
 *
 * Medido por `myvc_front` en los tres clientes: el catálogo de periodos del tablero
 * sale de `informes/datos`, que liga `year_id = $user->year_id` en las dos consultas
 * que lo construyen (`Informes\InformesController:65` y `:125`), y los dos fronts
 * recrean la pantalla al cambiar de año. **Lo que protege una nota no puede ser que
 * el cliente no sepa pedirlo**, así que se arregla igual.
 */
class ElRecalculoUsaElModoDeSuPeriodoTest extends CasoDeContrato
{
    private const PESOS = [40, 30, 20, 10];

    private const NOTAS = [40, 40, 20, 20];

    /** Con pesos 40/30/20/10 y notas 40/40/20/20 sobre una unidad al 100 %. */
    private const EN_PORCENTAJE = 34.0;

    private const EN_PROMEDIO = 30.0;

    /**
     * **La barra en un año y el periodo de otro: manda el periodo.**
     *
     * El montaje pone al usuario en un año que **no** es el del grupo, y los dos años
     * en modos distintos. Sin el arreglo, la definitiva sale con el reparto del año de
     * la sesión —el equivocado— y queda escrita así.
     */
    #[Test]
    public function el_recalculo_reparte_con_el_modo_del_periodo_y_no_con_el_de_la_sesion(): void
    {
        $ctx = $this->grupoDeUnAnioYSesionEnOtro();

        $this->withToken($ctx['token'])->putJson('/api/definitivas_periodos/calcular-grupo-periodo', [
            'grupo_id' => $ctx['grupo'],
            'periodo_id' => $ctx['periodo'],
            'num_periodo' => $ctx['numero'],
        ])->assertStatus(200);

        $guardada = (float) DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->value('nota');

        $this->assertEqualsWithDelta(self::EN_PROMEDIO, $guardada, 0.005,
            "El año del GRUPO está en `promedio` y el de la SESIÓN en `porcentaje`.\n"
            .'Se guardó '.$guardada.' y tenía que ser '.self::EN_PROMEDIO.': si sale '
            .self::EN_PORCENTAJE.", el modo se resolvió desde la barra y lo que queda escrito es una nota.\n"
            .'La regla está en el comentario de `DefinitivasDeAsignatura::calcular` desde que se '
            .'escribió — y decía ser el único escritor, que es por lo que nadie miró éste.');
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /**
     * Un grupo con su rejilla montada en un año **y el usuario en otro**, con los dos
     * años en modos contrarios.
     *
     * Las dos mitades son el caso: si los dos años tuvieran el mismo modo, el fallo
     * daría el mismo número que el acierto y el test pasaría con el bug dentro. Y si
     * la sesión estuviera en el año del grupo —que es lo que hacen los demás tests de
     * esta familia— no habría de qué hablar.
     *
     * @return array<string, mixed>
     */
    private function grupoDeUnAnioYSesionEnOtro(): array
    {
        // El sujeto es superusuario: `putCalcularGrupoPeriodo` exige `Profesor` o
        // superusuario, y con un profesor habría que además cuadrarle la asignatura.
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser);

        $suYear = DB::selectOne('SELECT p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?', [$usuario->id]);

        $this->assertNotNull($suYear, 'El usuario del seed se quedó sin periodo.');

        // Un grupo de OTRO año, con asignatura y alumnos.
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, g.year_id, per.id AS periodo_id, per.numero
            FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos per ON per.year_id = g.year_id AND per.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND g.year_id <> ?
              AND EXISTS (SELECT 1 FROM matriculas m WHERE m.grupo_id = a.grupo_id
                            AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM"))
            ORDER BY a.id LIMIT 1', [$suYear->year_id]);

        $this->assertNotNull($fila,
            'El seed no tiene ninguna asignatura con alumnos en un año distinto al del usuario: '
            .'sin eso no hay dos años de los que hablar y este caso no mide nada.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM") ORDER BY m.id LIMIT 1', [$fila->grupo_id]);

        $this->assertNotNull($alumno, 'El grupo elegido se quedó sin alumnos.');

        // **Los dos años en modos contrarios**: el del grupo en promedio, el de la
        // sesión en porcentaje. Es lo único que hace distinguibles los dos caminos.
        DB::table('years')->where('id', $fila->year_id)->update(['reparto_subunidades' => 'promedio']);
        DB::table('years')->where('id', $suYear->year_id)->update(['reparto_subunidades' => 'porcentaje']);

        DB::table('unidades')
            ->where('asignatura_id', $fila->asignatura_id)
            ->where('periodo_id', $fila->periodo_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $fila->asignatura_id,
            'periodo_id' => $fila->periodo_id,
            'definicion' => 'UNIDAD DEL RECÁLCULO',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::PESOS as $i => $peso) {
            $subId = DB::table('subunidades')->insertGetId([
                'unidad_id' => $unidadId,
                'definicion' => 'SUB '.($i + 1),
                'porcentaje' => $peso,
                'orden' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('notas')->insert([
                'subunidad_id' => $subId,
                'alumno_id' => $alumno->alumno_id,
                'nota' => self::NOTAS[$i],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'token' => $this->tokenDe($usuario->username),
            'grupo' => (int) $fila->grupo_id,
            'asignatura' => (int) $fila->asignatura_id,
            'periodo' => (int) $fila->periodo_id,
            'numero' => (int) $fila->numero,
            'alumno' => (int) $alumno->alumno_id,
        ];
    }
}
