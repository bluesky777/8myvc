<?php

namespace Tests\Contrato;

use App\Support\AnioCerrado;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Las dos de disciplina** — `PUT nota_comportamiento/guardar-libro` y
 * `PUT disciplina/cambiar-situacion-derivante`, doc 37 §2.3.
 *
 * Decisión de Joseth del 15 sep 2026: se cierran, y la lista del año cerrado sube de
 * **trece a quince**. Es el argumento que ya cerró `ordinales` un piso más abajo:
 * allí se protege el **artículo del manual de convivencia**, y aquí **la anotación
 * que lo cita** — el registro disciplinario de un menor.
 *
 * ## La población, que es la que decidió
 *
 * Medida en la copia de desarrollo el 15 sep 2026, y va delante del veredicto porque
 * es lo que separa «esto podría pasar» de «esto es lo normal»:
 *
 * | | en años cerrados | total |
 * |---|---|---|
 * | `dis_libro_rojo` | **1.593** | 2.047 |
 * | `dis_procesos` | **316** | 327 |
 *
 * El 97 % de `dis_procesos` vive en años cerrados. O sea que esa ruta trabajaba
 * **casi siempre** sobre años que ya nadie debería tocar, y con `auth.personal` como
 * única puerta: los 74 del personal.
 *
 * ## El rastro de `cambiar-situacion-derivante`, que entra en la misma decisión
 *
 * Esa ruta llevaba escrito `// No creo que sea chévere poner la fecha y modificador`
 * y **no escribía `updated_by` ni `updated_at`**. Era una decisión de quien lo
 * escribió, pero dejaba la fila **sin un solo autor**: la línea de `bitacoras` era
 * todo el rastro de quién cambió de qué falta deriva una situación. Desde hoy
 * escribe las dos columnas —ya existían en el esquema, sin migración— y el rastro
 * deja de depender de que esa tabla sobreviva a una limpieza.
 */
class DisciplinaDeUnAnioCerradoTest extends CasoDeContrato
{
    /**
     * **Las dos, para quien no es superusuario: 403 y la fila intacta.**
     *
     * Se mira la fila después y no sólo el código: un 403 con el registro ya
     * reescrito sería peor que un 200 honesto, y los dos se ven igual mirando el
     * status.
     */
    #[Test]
    public function las_dos_de_disciplina_son_403_en_un_anio_cerrado(): void
    {
        $ctx = $this->filasDeUnAnioCerrado();

        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        $colados = [];

        foreach ($this->lasDosEscrituras($ctx) as $nombre => $escritura) {
            $antes = $this->huellaDe($escritura['tabla'], $escritura['id']);

            $r = $this->withToken($token)->json('PUT', $escritura['url'], $escritura['cuerpo']);

            if ($r->status() !== 403) {
                $colados[] = "{$nombre} -> ".$r->status().' y no 403';
            }

            if ($antes !== $this->huellaDe($escritura['tabla'], $escritura['id'])) {
                $colados[] = "{$nombre} -> escribió igual en el año cerrado";
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $colados,
            "Alguna de las dos sigue reescribiendo el registro disciplinario de un año cerrado.\n"
            .'Son 1.593 filas de `dis_libro_rojo` y 316 de `dis_procesos` en la copia de '
            ."desarrollo, y la puerta era `auth.personal`: los 74 del personal.\n  "
            .implode("\n  ", $colados));
    }

    /**
     * Y el superusuario sigue llegando a las dos.
     *
     * Sin este caso, alguien que endureciera el criterio —de superusuario a nadie—
     * vería el fichero entero en verde: el de arriba sólo afirma que el personal
     * llano NO puede. Es la 05 §27.4.
     */
    #[Test]
    public function un_superusuario_sigue_escribiendo_la_disciplina_de_un_anio_cerrado(): void
    {
        $ctx = $this->filasDeUnAnioCerrado();

        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este caso no es superusuario, así que no demuestra lo que dice su nombre.');

        $token = $this->tokenDe($usuario->username);

        foreach ($this->lasDosEscrituras($ctx) as $nombre => $escritura) {
            $this->withToken($token)->json('PUT', $escritura['url'], $escritura['cuerpo'])
                ->assertStatus(200);

            $this->olvidarControladores();
        }

        $this->assertSame((int) $ctx['become'],
            (int) DB::table('dis_procesos')->where('id', $ctx['proceso'])->value('become_id'),
            'El superusuario recibió 200 y el proceso no se movió.');
    }

    /**
     * **Y en el año corriente las dos siguen abiertas para el personal llano.**
     *
     * El caso que más se nota si falla y el menos visible desde el código: un
     * candado que muerde el año en curso deja al colegio sin poder anotar una falta,
     * y el 403 no dice «te equivocaste de año», dice «no puedes».
     */
    #[Test]
    public function en_el_anio_corriente_las_dos_siguen_abiertas(): void
    {
        $ctx = $this->filasDeUnAnioCerrado(true);

        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        foreach ($this->lasDosEscrituras($ctx) as $nombre => $escritura) {
            $this->withToken($token)->json('PUT', $escritura['url'], $escritura['cuerpo'])
                ->assertStatus(200);

            $this->olvidarControladores();
        }
    }

    /**
     * **`cambiar-situacion-derivante` deja rastro en la propia fila.**
     *
     * Hasta el 15 sep 2026 no escribía `updated_by` ni `updated_at` —estaba
     * comentado a propósito— así que la única huella de quién cambió de qué falta
     * deriva una situación era la línea de `bitacoras`. Con las dos columnas el
     * rastro está en los dos sitios.
     *
     * Se comprueba **quién** y no sólo que la fecha cambió: un `updated_at` sin
     * `updated_by` dice que alguien lo tocó y no dice quién, que es justo la mitad
     * que faltaba.
     */
    #[Test]
    public function cambiar_la_situacion_derivante_deja_autor_en_la_fila(): void
    {
        $ctx = $this->filasDeUnAnioCerrado(true);

        $usuario = $this->usuarioLlanoDelPersonal();

        DB::table('dis_procesos')->where('id', $ctx['proceso'])
            ->update(['updated_by' => null, 'updated_at' => '2001-01-01 00:00:00']);

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/disciplina/cambiar-situacion-derivante', [
                'id' => $ctx['proceso'],
                'become_id' => $ctx['become'],
            ])->assertStatus(200);

        $fila = DB::table('dis_procesos')->where('id', $ctx['proceso'])->first();

        $this->assertSame((int) $usuario->id, (int) $fila->updated_by,
            'La fila no dice quién la cambió. La línea de auditoría volvería a ser el único '
            .'rastro de un cambio en el registro disciplinario de un menor.');

        $this->assertNotSame('2001-01-01 00:00:00', (string) $fila->updated_at,
            'La fila no dice cuándo la cambiaron.');
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /**
     * Un libro rojo y un proceso **del mismo año**, cerrado o corriente.
     *
     * Se montan y no se buscan: el seed no tiene por qué traer las dos tablas
     * pobladas en el mismo año, y con una de las dos vacía el bucle de dos casos
     * mediría uno — que es la forma que tiene un agujero de pasar desapercibido.
     *
     * @return array<string, mixed>
     */
    private function filasDeUnAnioCerrado(bool $corriente = false): array
    {
        $numero = AnioCerrado::anioCorriente();

        $this->assertNotNull($numero, 'El seed no tiene ningún año con `actual = 1`.');

        $year = $corriente
            ? DB::selectOne('SELECT id, year FROM years WHERE year = ? AND deleted_at IS NULL', [$numero])
            : DB::selectOne('SELECT id, year FROM years WHERE year < ? AND deleted_at IS NULL
                ORDER BY year DESC LIMIT 1', [$numero]);

        $this->assertNotNull($year, 'No hay año del que hablar.');
        $this->assertSame(! $corriente, AnioCerrado::estaCerrado($year->id),
            "El año {$year->year} no está en el estado que este caso necesita.");

        $periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL
            ORDER BY numero LIMIT 1', [$year->id]);

        $this->assertNotNull($periodo, "El año {$year->year} no tiene periodos.");

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene alumnos.');

        $libro = DB::table('dis_libro_rojo')->insertGetId([
            'alumno_id' => $alumno->id,
            'year_id' => $year->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $proceso = DB::table('dis_procesos')->insertGetId([
            'alumno_id' => $alumno->id,
            'year_id' => $year->id,
            'periodo_id' => $periodo->id,
            'descripcion' => 'PROCESO DE PRUEBA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $become = DB::table('dis_procesos')->insertGetId([
            'alumno_id' => $alumno->id,
            'year_id' => $year->id,
            'periodo_id' => $periodo->id,
            'descripcion' => 'LA FALTA DE LA QUE DERIVA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['libro' => $libro, 'proceso' => $proceso, 'become' => $become, 'year' => (int) $year->id];
    }

    /**
     * Las dos escrituras, con la tabla y la fila en la que mirar el después.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, array<string, mixed>>
     */
    private function lasDosEscrituras(array $ctx): array
    {
        return [
            'nota_comportamiento/guardar-libro' => [
                'url' => '/api/nota_comportamiento/guardar-libro',
                'cuerpo' => ['libro_id' => $ctx['libro'], 'campo' => 'per1_col1', 'valor' => 'PISADO'],
                'tabla' => 'dis_libro_rojo', 'id' => $ctx['libro'],
            ],
            'disciplina/cambiar-situacion-derivante' => [
                'url' => '/api/disciplina/cambiar-situacion-derivante',
                'cuerpo' => ['id' => $ctx['proceso'], 'become_id' => $ctx['become']],
                'tabla' => 'dis_procesos', 'id' => $ctx['proceso'],
            ],
        ];
    }

    /**
     * La fila entera como cadena, para comparar el antes con el después.
     *
     * `JSON_THROW_ON_ERROR` y no un `?:`: un `false` convertido a `''` haría que dos
     * filas distintas tuvieran la misma huella, o sea que «no cambió» saldría cierto
     * por no haber podido mirarla.
     */
    private function huellaDe(string $tabla, $id): ?string
    {
        $fila = DB::selectOne("SELECT * FROM {$tabla} WHERE id = ?", [$id]);

        return $fila === null ? null : json_encode($fila, JSON_THROW_ON_ERROR);
    }
}
