<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `acepto_vaciar` — la puerta que impide que publicar deje al colegio sin horario en
 * silencio (decisión 8 de la §10.2 del [23](../../docs/migracion/23-horarios.md),
 * contestada por Joseth el 7 sep 2026).
 *
 * ## Qué agujero tapa, medido antes de taparlo
 *
 * Publicar una versión con **cero lecciones** contestaba `200` y ponía a cero las siete
 * columnas de día de **las 134 asignaturas del año**: «Clases de hoy» en blanco para el
 * colegio entero, que es el problema de la §2 que este módulo vino a resolver. La respuesta
 * lo declaraba —`asignaciones_con_algun_dia: 0`, los siete días a `0`— pero **informar bien
 * y dejar publicar son dos cosas distintas**.
 *
 * ## Por qué no se prohíbe, que es la mitad que no se deduce del código
 *
 * **Porque hoy es la única forma de despublicar.** La única escritura de
 * `years.horario_version_id` pone un id, **ninguna lo pone a `NULL`**, y no hay ruta que
 * borre una versión. Un colegio que publicó un horario equivocado y prefiere no enseñar
 * ninguno sólo tiene esta palanca; un 422 seco se la quitaría **sin sustituto**. Por eso el
 * caso legítimo tiene su propio test —`con_el_numero_exacto_se_publica_y_deja_el_anio_sin_horario`—
 * y si algún día ése se pone rojo, lo roto no es el test.
 *
 * ## Un NÚMERO y no un `forzar: true`, por lo mismo que `acepto_perder`
 *
 * Es **la misma forma a propósito**, no una parecida: un booleano *acaba puesto por
 * costumbre, porque nunca estorba*. La simetría es exacta — **publicar no puede quitarle el
 * horario a 134 asignaciones en silencio, por lo mismo que no puede perder 32.**
 *
 * ## Lo que este fichero vigila y no es el 422
 *
 * **Que la puerta no se abra cuando no toca.** Publicar una versión normal **no puede
 * empezar a pedir un campo nuevo**: eso rompería a todo cliente desplegado el día de la
 * tanda, que es el fallo que este módulo lleva semanas evitando. Ese caso
 * —`publicar_una_version_con_clases_no_pide_nada`— vale más que los tres rechazos juntos.
 *
 * Y como en `acepto_perder`, cada rechazo mira **el puntero del año y las siete columnas**,
 * no sólo el código: «Nada se escribió» es una promesa hasta que alguien la comprueba.
 */
class HorarioAceptoVaciarTest extends CasoDeContrato
{
    /** El año actual del seed, el que se publica. */
    private const YEAR_ACTUAL = 8;

    /** Las asignaturas vivas del año, que son el alcance de la derivación. */
    private function asignaturasDelAnio(int $limite = 6): array
    {
        $filas = array_values(array_map(static fn ($f): int => (int) $f->id, DB::select(
            'SELECT a.id FROM asignaturas a
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
             WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT '.$limite,
            [self::YEAR_ACTUAL]
        )));

        $this->assertCount($limite, $filas,
            "El seed tiene que dar {$limite} asignaturas vivas del año ".self::YEAR_ACTUAL.
            ' y ha dado '.count($filas).'. Sin población, un 0 no distingue «no se vacía nada» '.
            'de «no había nada que vaciar».');

        return $filas;
    }

    /** Una versión del año, con las lecciones que se le pasen (ninguna = versión vacía). */
    private function version(array $asignaturas = [], string $nombre = 'v'): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, null, ?, null, now(), now())',
            [self::YEAR_ACTUAL, $nombre, '{}']
        );

        $versionId = (int) DB::getPdo()->lastInsertId();

        foreach (array_values($asignaturas) as $i => $asignaturaId) {
            DB::insert(
                'INSERT INTO horario_lecciones (version_id, pieza_id, asignatura_id, dia, franja, duracion)
                 VALUES (?, ?, ?, 1, ?, 1)',
                [$versionId, 'p'.$i, $asignaturaId, $i + 1]
            );
        }

        return $versionId;
    }

    /**
     * Le da horario al año, que es lo que hace que vaciarlo signifique algo.
     *
     * Escribe la columna directamente en vez de publicar una versión: **derivar es de
     * `HorarioOficialTest`**, y montarlo aquí por la ruta ataría estos casos a que aquella
     * siga funcionando. Lo que aquí importa es el estado de partida, no cómo se llegó.
     */
    private function darHorarioA(array $asignaturas): void
    {
        DB::update('UPDATE asignaturas SET lunes = 1 WHERE id IN ('
            .implode(',', array_fill(0, count($asignaturas), '?')).')', $asignaturas);
    }

    /** Publica como superusuario, que es quien puede hoy (decisión 10). */
    private function publicar(int $versionId, array $cuerpo = [])
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este test tiene que ser superusuario y no lo es: sin él, el 403 '.
            'del guard se leería como un rechazo de `acepto_vaciar`.');

        return $this->putJson("/api/horario/versiones/{$versionId}/oficial", $cuerpo, [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ]);
    }

    private function oficialDelAnio(): ?int
    {
        $v = DB::select('SELECT horario_version_id FROM years WHERE id = ?', [self::YEAR_ACTUAL])[0]
            ->horario_version_id;

        return $v === null ? null : (int) $v;
    }

    /** Cuántas asignaturas del año tienen algún día encendido. */
    private function conAlgunDia(): int
    {
        return (int) DB::select(
            'SELECT count(*) AS c FROM asignaturas a
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
             WHERE a.deleted_at IS NULL
               AND (a.domingo = 1 OR a.lunes = 1 OR a.martes = 1 OR a.miercoles = 1
                    OR a.jueves = 1 OR a.viernes = 1 OR a.sabado = 1)',
            [self::YEAR_ACTUAL]
        )[0]->c;
    }

    /** El control de todo el fichero: un rechazo no deja rastro. */
    private function fotoDelAnio(): array
    {
        return ['oficial' => $this->oficialDelAnio(), 'con_algun_dia' => $this->conAlgunDia()];
    }

    // ───────────────────────────────────────── la puerta NO se abre cuando no toca

    /**
     * **El caso que vale más que los tres rechazos juntos**: publicar una versión con clases
     * sigue sin pedir nada.
     *
     * Si esto se pone rojo, el despliegue rompe a **todos** los clientes vivos a la vez, que
     * es el fallo que este módulo lleva semanas evitando. Va el primero a propósito.
     */
    #[Test]
    public function publicar_una_version_con_clases_no_pide_nada(): void
    {
        $asignaturas = $this->asignaturasDelAnio();
        $this->darHorarioA($asignaturas);

        $version = $this->version($asignaturas, 'con clases');

        $this->publicar($version)->assertStatus(200);

        $this->assertSame($version, $this->oficialDelAnio());
    }

    /**
     * Y una versión vacía sobre un año **que todavía no tiene horario** tampoco pide nada.
     *
     * La puerta se abre por **lo que se pierde**, no por lo que la versión es — el mismo
     * criterio que `acepto_perder`, que tampoco salta cuando no se pierde nada. Es el caso del
     * primer despliegue en los dieciséis: allí las siete columnas están vacías y publicar una
     * versión sin clases no le quita el horario a nadie.
     */
    #[Test]
    public function una_version_vacia_sobre_un_anio_sin_horario_no_pide_nada(): void
    {
        DB::update('UPDATE asignaturas a INNER JOIN grupos g ON g.id = a.grupo_id
                       SET a.domingo = 0, a.lunes = 0, a.martes = 0, a.miercoles = 0,
                           a.jueves = 0, a.viernes = 0, a.sabado = 0
                     WHERE g.year_id = ?', [self::YEAR_ACTUAL]);

        $this->assertSame(0, $this->conAlgunDia(), 'El escenario no ha quedado sin horario.');

        $version = $this->version([], 'vacía sobre año sin horario');

        $this->publicar($version)->assertStatus(200);

        $this->assertSame($version, $this->oficialDelAnio());
    }

    // ───────────────────────────────────────────────────────── la puerta SÍ se abre

    #[Test]
    public function una_version_vacia_sin_declarar_nada_es_422_y_no_escribe(): void
    {
        $this->darHorarioA($this->asignaturasDelAnio());
        $version = $this->version([], 'vacía');

        $antes = $this->fotoDelAnio();
        $this->assertGreaterThan(0, $antes['con_algun_dia'], 'Sin horario que vaciar esto no prueba nada.');

        $r = $this->publicar($version)->assertStatus(422);

        $this->assertSame('vaciado-no-aceptado', $r->json('motivo'));
        $this->assertSame($antes['con_algun_dia'], $r->json('asignaciones_que_se_quedan_sin_horario'),
            'El rechazo tiene que decir CUÁNTAS se quedan sin horario: es la cifra que hay que '.
            'enseñarle a quien publica, y no la da ninguna otra pantalla.');

        $this->assertSame($antes, $this->fotoDelAnio(),
            'El 422 dice «Nada se escribió» y algo cambió: el puntero o las siete columnas. '.
            'La comprobación va dentro de la transacción y el `abort()` tiene que deshacerla.');
    }

    /**
     * **El caso legítimo, y es el que justifica que esto sea una puerta y no una prohibición.**
     *
     * Un colegio que publicó un horario equivocado y prefiere no enseñar ninguno **tiene que
     * poder seguir haciéndolo**, mandando la cifra. Hoy es la única forma de despublicar: no
     * hay ruta que ponga `years.horario_version_id` a `NULL`. *Si este test se pone rojo, lo
     * roto no es el test: es que le hemos quitado a un colegio lo único que podía hacer.*
     */
    #[Test]
    public function con_el_numero_exacto_se_publica_y_deja_el_anio_sin_horario(): void
    {
        $asignaturas = $this->asignaturasDelAnio();
        $this->darHorarioA($asignaturas);
        $version = $this->version([], 'vacía');

        $cuantas = $this->conAlgunDia();

        $r = $this->publicar($version, ['acepto_vaciar' => $cuantas])->assertStatus(200);

        $this->assertSame($version, $this->oficialDelAnio(), 'La versión vacía tiene que quedar publicada.');
        $this->assertSame(0, $this->conAlgunDia(), 'Después de vaciar no puede quedar ningún día encendido.');
        $this->assertSame(0, $r->json('derivacion.asignaciones_con_algun_dia'));
    }

    #[Test]
    #[DataProvider('numerosQueNoCoinciden')]
    public function un_numero_que_no_coincide_rebota_en_las_dos_direcciones(int $delta): void
    {
        $this->darHorarioA($this->asignaturasDelAnio());
        $version = $this->version([], 'vacía');

        $antes = $this->fotoDelAnio();

        $r = $this->publicar($version, ['acepto_vaciar' => $antes['con_algun_dia'] + $delta])
            ->assertStatus(422);

        $this->assertSame('acepto-vaciar-no-coincide', $r->json('motivo'));
        $this->assertSame($antes, $this->fotoDelAnio(), 'Un rechazo no puede dejar rastro.');
    }

    public static function numerosQueNoCoinciden(): array
    {
        // Las dos direcciones: de menos y de más. La de más es la que parece de sobra y es
        // la que impide que el campo acabe puesto por costumbre con una constante dentro.
        return ['de menos' => [-1], 'de más' => [+1]];
    }

    /**
     * **Declarar el número cuando NO hace falta también rebota**, y ésta es la mitad que
     * sostiene todo lo demás.
     *
     * Si un cliente puede mandar `acepto_vaciar` siempre «por si acaso», es un `forzar: true`
     * con otro nombre: nunca estorba, así que se queda puesto, y el día que la versión sí
     * vacíe pasará sin que nadie lo mire.
     */
    #[Test]
    public function declarar_el_numero_cuando_la_version_tiene_clases_rebota(): void
    {
        $asignaturas = $this->asignaturasDelAnio();
        $this->darHorarioA($asignaturas);
        $version = $this->version($asignaturas, 'con clases');

        $antes = $this->fotoDelAnio();

        $r = $this->publicar($version, ['acepto_vaciar' => $antes['con_algun_dia']])
            ->assertStatus(422);

        $this->assertSame('acepto-vaciar-no-coincide', $r->json('motivo'));
        $this->assertSame(0, $r->json('asignaciones_que_se_quedan_sin_horario'),
            'Una versión con clases no vacía nada, así que el servidor cuenta 0.');
        $this->assertSame($antes, $this->fotoDelAnio());
    }

    #[Test]
    #[DataProvider('loQueNoEsUnNumero')]
    public function lo_que_no_es_un_numero_rebota(mixed $valor): void
    {
        $this->darHorarioA($this->asignaturasDelAnio());
        $version = $this->version([], 'vacía');

        $antes = $this->fotoDelAnio();

        $r = $this->publicar($version, ['acepto_vaciar' => $valor])->assertStatus(422);

        $this->assertSame('acepto-vaciar-no-es-un-numero', $r->json('motivo'));
        $this->assertSame($antes, $this->fotoDelAnio());
    }

    public static function loQueNoEsUnNumero(): array
    {
        // `true` es el caso que da nombre a la decisión: si valiera por 1 —o por «sí»—, la
        // puerta sería el `forzar: true` que se descartó. `"6"` cubre la otra mitad: la regla
        // `integer` de Laravel lo aceptaría, y por eso el tipo se comprueba a mano.
        return ['true' => [true], 'cadena con el numero dentro' => ['6'], 'texto' => ['sí']];
    }
}
