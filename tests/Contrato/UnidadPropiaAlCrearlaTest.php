<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * **`POST unidades` tiene que aceptar `alumno_id`** — §8 del
 * [19](../../docs/migracion/19-boletin-independiente.md), y hasta el 1 sep 2026 **no lo
 * leía**.
 *
 * ## El daño, medido por el front ejecutando y reproducido aquí
 *
 * El plan prometía que el front **no construye un editor nuevo** porque *«son los mismos
 * endpoints de `unidades` y `subunidades`, con `alumno_id` en el cuerpo al crear la
 * unidad»*. Eso nunca se escribió en el controlador: `postIndex` no miraba ese campo.
 *
 * Y lo que pasa al mandarlo **es peor que un campo que se ignora**: la unidad nace **del
 * grupo**, se le pone **a todo el curso**, y el reparto de la asignatura **deja de sumar
 * 100** — sin un error, sin un aviso y sin que nada lo diga. El front lo midió en la
 * asignatura 1235: una unidad al 10 %, 51 estudiantes, y el curso al **110 %**.
 *
 * O sea que **un docente que intente montarle unidades propias a un independiente le
 * desordena la asignatura a los otros treinta**, y la única pista es que los porcentajes
 * dejan de cuadrar.
 *
 * ## Las tres cosas que este fichero fija, y las tres se comprobaron en rojo
 *
 * 1. **La unidad nace con dueño** — hoy nacía sin él.
 * 2. **El reparto del grupo no se mueve** — hoy subía, y es el síntoma que ve el colegio.
 * 3. **No le aparece a los demás, y al dueño sí** — las dos direcciones, porque la
 *    primera sola se cumpliría escondiéndosela a todo el mundo.
 *
 * ## Y las dos guardas, que son decisión y no mecánica
 *
 * - **El alumno tiene que estar matriculado en el grupo de esa asignatura.** La clave
 *   foránea sólo obliga a que exista; es la familia de
 *   `tools/identificadores-del-cuerpo.py` y la misma guarda que tuvo que añadir el lote D.
 * - **El alumno tiene que ir aparte EN ESE PERIODO.** Crear una unidad con dueño para
 *   quien va con el grupo deja una fila **que no le cuenta a nadie**: su dueño lee las
 *   del grupo —la marca ausente es «va con el grupo», decisión 7— y los demás tampoco la
 *   ven. Es la §9.1 al revés, y en silencio.
 *
 *   > **Esto NO prohíbe el estado «tiene unidades propias y no está marcado»**, que es
 *   > legítimo y está decidido: apagar la marca **no borra nada** y esos datos quedan
 *   > ignorados a la espera. Lo que se prohíbe es **crear** una fila así desde cero, que
 *   > nace muerta. Un residuo tiene historia; una fila nueva sin dueño efectivo, no.
 */
class UnidadPropiaAlCrearlaTest extends CasoDeContrato
{
    private const DEFINICION = 'UNIDAD PROPIA DEL INDEPENDIENTE';

    protected function setUp(): void
    {
        parent::setUp();

        BoletinIndependiente::olvidar();
    }

    /**
     * Asignatura con unidades del grupo **en el periodo del token** y dos alumnos.
     *
     * El periodo del token no es un detalle: `postIndex` crea la unidad con
     * `periodo_id = $user->periodo_id`, así que la marca tiene que ir a ese mismo
     * periodo o el escenario mide otra cosa.
     *
     * @return array{token: string, periodo: int, asignatura: int, grupo: int, marcado: int, companero: int}
     */
    private function escenario(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $fila = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id
               FROM asignaturas a
              INNER JOIN unidades u ON u.asignatura_id = a.id AND u.deleted_at IS NULL
                    AND u.alumno_id IS NULL AND u.periodo_id = ?
              INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                    AND m.estado IN ("MATR", "ASIS")
              WHERE a.deleted_at IS NULL
              GROUP BY a.id, a.grupo_id
             HAVING COUNT(DISTINCT m.alumno_id) >= 2
              ORDER BY a.id LIMIT 1', [$usuario->periodo_id]);

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades del grupo EN EL PERIODO DEL TOKEN y dos '
            .'alumnos matriculados: con uno solo «lo suyo» y «lo de todos» no se distinguen.');

        $alumnos = DB::select(
            'SELECT alumno_id FROM matriculas
              WHERE grupo_id = ? AND deleted_at IS NULL AND estado IN ("MATR", "ASIS")
              ORDER BY alumno_id LIMIT 2', [$fila->grupo_id]);

        return [
            'token' => $this->tokenDe($usuario->username),
            'periodo' => (int) $usuario->periodo_id,
            'asignatura' => (int) $fila->asignatura_id,
            'grupo' => (int) $fila->grupo_id,
            'marcado' => (int) $alumnos[0]->alumno_id,
            'companero' => (int) $alumnos[1]->alumno_id,
        ];
    }

    /** Lo que suman hoy los porcentajes de las unidades DEL GRUPO de esa asignatura. */
    private function repartoDelGrupo(array $e): int
    {
        return (int) DB::selectOne(
            'SELECT COALESCE(SUM(porcentaje), 0) AS s FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [$e['asignatura'], $e['periodo']])->s;
    }

    private function crearUnidad(array $e, ?int $alumnoId, int $porcentaje = 10)
    {
        $cuerpo = [
            'asignatura_id' => $e['asignatura'],
            'definicion' => self::DEFINICION,
            'porcentaje' => $porcentaje,
        ];

        if ($alumnoId !== null) {
            $cuerpo['alumno_id'] = $alumnoId;
        }

        return $this->withToken($e['token'])->postJson('/api/unidades', $cuerpo);
    }

    /** Las definiciones de unidad que ve un alumno en su rejilla de notas. @return list<string> */
    private function unidadesQueVe(array $e, int $alumnoId): array
    {
        $cuerpo = $this->withToken($e['token'])->putJson("/api/editnota/detailed-notas/{$e['grupo']}", [
            'requested_alumnos' => [['alumno_id' => $alumnoId]],
            'periodos_a_calcular' => 'de_colegio',
        ])->assertStatus(200)->json();

        $definiciones = [];

        foreach ($cuerpo[2] ?? [] as $alumno) {
            foreach ($alumno['asignaturas'] ?? [] as $asignatura) {
                foreach ($asignatura['unidades'] ?? [] as $unidad) {
                    $definiciones[] = (string) ($unidad['definicion_unidad'] ?? '');
                }
            }
        }

        return $definiciones;
    }

    /**
     * **El daño del front, reproducido y cerrado.** Los tres efectos en una llamada,
     * porque los tres salen de la misma línea que faltaba.
     */
    public function test_la_unidad_con_alumno_id_nace_con_dueno_y_no_toca_al_curso(): void
    {
        $e = $this->escenario();

        $this->marcarIndependiente($e['marcado'], $e['periodo']);

        $antes = $this->repartoDelGrupo($e);
        $this->assertGreaterThan(0, $antes, 'La asignatura no tiene reparto que mover: el test no mide nada.');

        $r = $this->crearUnidad($e, $e['marcado']);
        $r->assertStatus(201);

        $unidadId = (int) $r->json('id');

        // 1 · nace con dueño
        //
        // El valor se compara **sin castear**: `unidades.alumno_id` es nullable, y
        // `(int) null` vale 0, así que un `assertSame((int) $fila->alumno_id, ...)` diría
        // «0 no es 460» cuando lo que hay es NULL. Son cosas distintas —«del grupo» y «de
        // un alumno con id 0», que no existe— y el mensaje del fallo tiene que decir cuál.
        $dueno = DB::selectOne('SELECT alumno_id FROM unidades WHERE id = ?', [$unidadId])->alumno_id;

        $this->assertNotNull($dueno,
            '`POST unidades` ignoró `alumno_id` y creó una unidad DEL GRUPO (`alumno_id` NULL). Es '
            .'lo que el §8 del plan promete que funciona y nunca se escribió en el controlador.');

        $this->assertSame($e['marcado'], (int) $dueno,
            'La unidad nació con dueño, pero con OTRO alumno.');

        // 2 · el reparto del curso no se mueve — el síntoma que ve el colegio
        $this->assertSame($antes, $this->repartoDelGrupo($e),
            'El reparto del grupo cambió al crear una unidad propia. Ése es el daño que midió el '
            .'front: la asignatura pasó de 100 % a 110 % sin un error y sin un aviso.');

        // 3 · las dos direcciones
        $this->assertContains(self::DEFINICION, $this->unidadesQueVe($e, $e['marcado']),
            'Al independiente no le llega su propia unidad: se la creó y no la ve nadie.');

        $this->assertNotContains(self::DEFINICION, $this->unidadesQueVe($e, $e['companero']),
            'La unidad propia del independiente le aparece a su compañero. Es el daño entero: un '
            .'docente montándole el boletín a uno le desordena la asignatura a los otros treinta.');
    }

    /**
     * **Sin `alumno_id` no cambia nada**, que es el caso de los quince colegios de hoy.
     *
     * Sin este control, «la unidad nace con dueño» se cumpliría también poniéndole dueño
     * a todas — y eso dejaría al curso entero sin estructura.
     */
    public function test_sin_alumno_id_la_unidad_sigue_siendo_del_grupo(): void
    {
        $e = $this->escenario();

        $r = $this->crearUnidad($e, null);
        $r->assertStatus(201);

        $this->assertNull(DB::selectOne('SELECT alumno_id FROM unidades WHERE id = ?', [(int) $r->json('id')])->alumno_id,
            'Una unidad creada sin `alumno_id` tiene que ser del grupo: es lo que hacen hoy los '
            .'quince colegios y no puede cambiar.');

        $this->assertContains(self::DEFINICION, $this->unidadesQueVe($e, $e['companero']),
            'Y le tiene que llegar a todo el curso, como siempre.');
    }

    /**
     * **Un alumno que NO va aparte en ese periodo: 422.**
     *
     * Crear una unidad con dueño para quien va con el grupo deja una fila que **no le
     * cuenta a nadie**: su dueño lee las del grupo —la marca ausente es «va con el
     * grupo»— y los demás tampoco la ven. Nace muerta y en silencio.
     */
    public function test_un_alumno_sin_marcar_en_ese_periodo_es_422(): void
    {
        $e = $this->escenario();

        $this->crearUnidad($e, $e['companero'])->assertStatus(422);

        $this->assertSame(0, (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM unidades WHERE definicion = ?', [self::DEFINICION])->n,
            'El 422 tiene que llegar ANTES de escribir: si la fila se crea y luego se rechaza, '
            .'queda una unidad muerta con el reparto ya tocado.');
    }

    /**
     * **Y un alumno que no está matriculado en el grupo de esa asignatura: 422 también**,
     * aunque exista y aunque esté marcado.
     *
     * ## El caso se CONSTRUYE, y por una razón medida
     *
     * En esta base hay **68 alumnos y los 68 están en el mismo grupo**, así que «un alumno
     * de otro grupo» **no existe**: buscarlo devolvía `null` por población, no por la
     * consulta. Costó tres vueltas de fixture, y las tres fueron el detector y no el
     * código —`grupo_id != ?` incluía a quien también está en éste; `NOT IN` con una fila
     * NULL no devuelve a nadie; y al final resultó que no había a quién devolver—.
     *
     * Así que se le da la vuelta: se crea un **grupo ajeno con su asignatura** y se
     * intenta colgarle una unidad **de un alumno de nuestro grupo**, que sí está marcado.
     * Con eso la condición 2 está satisfecha y **lo único que puede rechazar es la 1**,
     * que es lo que este caso mide.
     */
    public function test_un_alumno_que_no_esta_en_el_grupo_de_la_asignatura_es_422(): void
    {
        $e = $this->escenario();

        // Marcado: así la guarda del boletín independiente pasa y el 422 sólo puede venir
        // de la matrícula. Sin esto, el caso se cumpliría por la razón equivocada.
        $this->marcarIndependiente($e['marcado'], $e['periodo']);

        $grupoAjeno = $this->grupoAjenoDelMismoAnio((int) DB::selectOne(
            'SELECT year_id FROM grupos WHERE id = ?', [$e['grupo']])->year_id);

        $r = $this->withToken($e['token'])->postJson('/api/unidades', [
            'asignatura_id' => $grupoAjeno->asignatura_id,
            'definicion' => self::DEFINICION,
            'porcentaje' => 10,
            'alumno_id' => $e['marcado'],
        ]);

        $r->assertStatus(422);

        $this->assertSame(0, (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM unidades WHERE definicion = ?', [self::DEFINICION])->n,
            'La clave foránea sólo obliga a que el alumno exista, no a que tenga que ver con esta '
            .'asignatura: sin esta guarda se le cuelga una unidad a alguien de otro curso, y el '
            .'422 tiene que llegar ANTES de escribir.');
    }
}
