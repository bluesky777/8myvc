<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `acepto_perder` — la puerta que impide que publicar pierda clases en silencio
 * (§7.1 del [23](../../docs/migracion/23-horarios.md), decisión de Joseth del 2 sep 2026).
 *
 * ## Qué agujero tapa, y por qué no se veía
 *
 * La §6 comprueba que cada asignación de la versión es del año **el día que se sube**.
 * Publicar es **otro día y otra decisión** —«subir no publica», decisión 17—. Entre los
 * dos, alguien puede borrar una asignatura o mover su grupo: esas filas se caen del
 * alcance de la derivación, **su día no se escribe** y el horario pierde esas clases.
 *
 * Antes de esta puerta el número se contaba y salía en la respuesta — o sea que se
 * avisaba **después** de haberlas perdido, y en un campo de una respuesta de éxito, que
 * es donde nadie mira. La respuesta era un 200.
 *
 * ## Por qué un NÚMERO y no un `forzar: true`, que es lo que ata este fichero
 *
 * Un booleano no caza la deriva: dice «adelante pase lo que pase», así que el día que se
 * pierdan treinta en vez de las dos que el coordinador vio en pantalla, pasa igual. Y
 * acaba puesto por costumbre, porque nunca estorba.
 *
 * Un número **tiene que coincidir con el que el servidor cuenta en ese mismo instante**,
 * así que sólo lo puede acertar quien acaba de mirar. Eso da la propiedad que importa y
 * que aquí se comprueba en las **dos** direcciones: un número **de menos** rebota, y un
 * número **de más también** —incluido el caso en el que no se pierde nada—. La segunda
 * mitad es la que parece de sobra y es la que evita que `acepto_perder` se convierta en
 * el `forzar: true` que vino a evitar: una constante que siempre está y nunca estorba.
 *
 * ## Y lo que de verdad hay que atar: que un 422 no escriba
 *
 * «Nada se escribió» está en los tres mensajes de rechazo, y un mensaje no es una
 * prueba. La comprobación va **dentro** de la transacción, así que el `abort()` tiene
 * que deshacerla: por eso cada rechazo de aquí mira además **el puntero del año y las
 * siete columnas**, no sólo el código de estado.
 */
class HorarioAceptoPerderTest extends CasoDeContrato
{
    /** El año actual del seed, el que se publica. */
    private const YEAR_ACTUAL = 8;

    /**
     * Monta el escenario: una versión del año con `$conDeriva` de sus asignaturas
     * **borradas después de crearla**, que es exactamente el camino real.
     *
     * Se borra DESPUÉS de meter las lecciones y no antes: si se eligieran asignaturas ya
     * muertas, la versión nunca las habría tenido y no habría deriva que contar — se
     * estaría midiendo otra cosa que da el mismo número.
     *
     * @return array{0: int, 1: list<int>, 2: list<int>} (version_id, vivas, borradas)
     */
    private function versionConDeriva(int $conDeriva): array
    {
        $asignaturas = array_values(array_map(static fn ($f): int => (int) $f->id, DB::select(
            'SELECT a.id FROM asignaturas a
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
             WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 6',
            [self::YEAR_ACTUAL]
        )));

        $this->assertCount(6, $asignaturas,
            'El seed tiene que dar seis asignaturas vivas del año '.self::YEAR_ACTUAL.
            ' y ha dado '.count($asignaturas).'. Sin población, un 0 de deriva no distingue '.
            '«no se pierde nada» de «no había nada que perder».');

        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, null, ?, null, now(), now())',
            [self::YEAR_ACTUAL, 'v-deriva', '{}']
        );

        $versionId = (int) DB::getPdo()->lastInsertId();

        foreach ($asignaturas as $i => $asignaturaId) {
            DB::insert(
                'INSERT INTO horario_lecciones (version_id, pieza_id, asignatura_id, dia, franja, duracion)
                 VALUES (?, ?, ?, 1, ?, 1)',
                [$versionId, 'p'.$i, $asignaturaId, $i + 1]
            );
        }

        $borradas = array_slice($asignaturas, 0, $conDeriva);

        foreach ($borradas as $asignaturaId) {
            DB::update('UPDATE asignaturas SET deleted_at = now() WHERE id = ?', [$asignaturaId]);
        }

        return [$versionId, array_slice($asignaturas, $conDeriva), $borradas];
    }

    /** Publica como superusuario, que es quien puede hoy (decisión 10). */
    private function publicar(int $versionId, array $cuerpo = [])
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este test tiene que ser superusuario y no lo es: sin él, el 403 '.
            'del guard se leería como un rechazo de `acepto_perder`.');

        return $this->putJson("/api/horario/versiones/{$versionId}/oficial", $cuerpo, [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ]);
    }

    /** El puntero del año, que es lo que «publicar» significa. */
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

    /**
     * El control de todo este fichero: **un rechazo no deja rastro**.
     *
     * Se llama antes y después de cada 422 y compara las dos fotos. Sin esto, los siete
     * casos de abajo comprobarían el código de estado y **ni uno solo** que la
     * transacción se deshaga, que es la única razón por la que el mensaje puede decir
     * «Nada se escribió».
     */
    private function fotoDelAnio(): array
    {
        return ['oficial' => $this->oficialDelAnio(), 'con_algun_dia' => $this->conAlgunDia()];
    }

    #[Test]
    public function sin_deriva_se_publica_sin_declarar_nada(): void
    {
        [$versionId, $vivas] = $this->versionConDeriva(0);

        $respuesta = $this->publicar($versionId);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('derivacion.asignaciones_de_la_version_fuera_del_alcance', 0);
        $this->assertSame($versionId, $this->oficialDelAnio(),
            'Sin deriva, publicar no tiene por qué pedir permiso: el camino normal no puede '.
            'pasar por `acepto_perder` o el campo acabaría puesto siempre.');
        $this->assertSame(count($vivas), $this->conAlgunDia());
    }

    #[Test]
    public function sin_deriva_declarar_cero_tambien_vale(): void
    {
        [$versionId] = $this->versionConDeriva(0);

        $this->publicar($versionId, ['acepto_perder' => 0])->assertStatus(200);

        $this->assertSame($versionId, $this->oficialDelAnio());
    }

    #[Test]
    public function con_deriva_y_sin_declarar_nada_es_422_y_no_escribe(): void
    {
        [$versionId, , $borradas] = $this->versionConDeriva(2);

        $antes = $this->fotoDelAnio();
        $respuesta = $this->publicar($versionId);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('motivo', 'perdida-no-aceptada');
        $respuesta->assertJsonPath('asignaciones_que_se_pierden', count($borradas));

        $this->assertSame($antes, $this->fotoDelAnio(),
            'El 422 tiene que deshacer la transacción entera. Si esta foto cambió, el '.
            '«Nada se escribió» del mensaje es mentira y el año quedó a medio publicar.');
    }

    #[Test]
    public function el_mensaje_dice_el_numero_con_el_que_reintentar(): void
    {
        [$versionId, , $borradas] = $this->versionConDeriva(3);

        $respuesta = $this->publicar($versionId);

        $respuesta->assertStatus(422);
        $mensaje = (string) $respuesta->json('message');

        $this->assertStringContainsString('esas '.count($borradas).' a quien publica', $mensaje,
            'El mensaje tiene que traer el número exacto. Un «hace falta acepto_perder» '.
            'manda a adivinar, y quien adivina prueba con 1.');

        // **Y NO puede decirle al cliente que lo remande.** Lo levantó `myvc-horarios-5e`:
        // un «vuelve a llamar con acepto_perder: N» es una invitación a que el emisor
        // reintente solo con el N del error, lo cual funciona y reconstruye el
        // `forzar: true` en dos viajes. El número está para ENSEÑARLO a una persona.
        $this->assertStringNotContainsString('vuelve a llamar', mb_strtolower($mensaje),
            'El mensaje no puede instruir un reintento automático: ahí es donde la '.
            'decisión se desmonta sin que nada se ponga rojo.');
    }

    #[Test]
    public function con_deriva_y_el_numero_exacto_se_publica(): void
    {
        [$versionId, $vivas, $borradas] = $this->versionConDeriva(2);

        $respuesta = $this->publicar($versionId, ['acepto_perder' => count($borradas)]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('derivacion.asignaciones_de_la_version_fuera_del_alcance',
            count($borradas));

        $this->assertSame($versionId, $this->oficialDelAnio());
        $this->assertSame(count($vivas), $this->conAlgunDia(),
            'Las que se pierden NO tienen que quedar encendidas: se aceptó perderlas, no '.
            'conservarlas. Si esto cuenta de más, la deriva se «aceptó» y además se coló.');
    }

    /**
     * Los dos números tienen que salir **con nombre** en el 422.
     *
     * Lo pidió `myvc-horarios-5e` y el argumento es suyo: su pantalla se lo tiene que
     * explicar a un coordinador de colegio, y «esperaba 32, mandaste 28» se explica y se
     * puede comprobar contra lo que hay en pantalla; «no coinciden» sólo se puede creer.
     *
     * @param  int  $conDeriva  cuántas se borran de verdad
     * @param  int  $declarado  lo que manda el cliente
     */
    #[Test]
    #[DataProvider('numerosQueNoCoinciden')]
    public function un_numero_que_no_coincide_es_422_en_las_dos_direcciones(int $conDeriva, int $declarado): void
    {
        [$versionId] = $this->versionConDeriva($conDeriva);

        $antes = $this->fotoDelAnio();
        $respuesta = $this->publicar($versionId, ['acepto_perder' => $declarado]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('motivo', 'acepto-perder-no-coincide');
        $respuesta->assertJsonPath('acepto_perder', $declarado);
        $respuesta->assertJsonPath('asignaciones_que_se_pierden', $conDeriva);

        // **Y el mensaje NO puede mandar a releer el listado.** Lo levantó
        // `myvc-horarios-83` yendo a escribir esa relectura: no existe. `getVersiones`
        // no devuelve la deriva —su `comprobaciones` es el veredicto guardado el día de
        // la subida—, así que la única lectura fresca es este mismo 422. Mandar a buscar
        // un número que no está en ninguna pantalla es peor que no decir nada.
        $this->assertStringNotContainsString('listado',
            mb_strtolower((string) $respuesta->json('message')),
            'El mensaje manda a releer un listado que no trae esta cifra.');

        $this->assertSame($antes, $this->fotoDelAnio());
    }

    /**
     * **«De más» incluye el caso en el que no se pierde nada**, que es la fila que
     * parece de sobra: un cliente con `acepto_perder` puesto a una constante pasaría
     * todos los días que hubiera deriva de ese tamaño y ninguno más. Rebotarlo es lo que
     * impide que el campo se vuelva decorativo.
     */
    public static function numerosQueNoCoinciden(): array
    {
        return [
            'de menos' => [3, 2],
            'de más' => [2, 3],
            'declara perder cuando no se pierde nada' => [0, 1],
        ];
    }

    /**
     * `true` NO vale por 1, y este caso es el que define el campo.
     *
     * Si un booleano colara, `acepto_perder: true` sería un `forzar: true` con otro
     * nombre y toda la decisión se habría perdido sin que nada se pusiera rojo. Este
     * repositorio tiene herramienta propia para esta forma exacta
     * (`tools/verdad-laxa-que-escribe.py`): una cadena cualquiera que vale por «sí» y
     * gobierna una escritura.
     *
     * @param  mixed  $valor
     */
    #[Test]
    #[DataProvider('valoresQueNoSonUnNumero')]
    public function lo_que_no_es_un_numero_rebota($valor): void
    {
        [$versionId] = $this->versionConDeriva(2);

        $antes = $this->fotoDelAnio();
        $respuesta = $this->publicar($versionId, ['acepto_perder' => $valor]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('motivo', 'acepto-perder-no-es-un-numero');

        $this->assertSame($antes, $this->fotoDelAnio());
    }

    public static function valoresQueNoSonUnNumero(): array
    {
        return [
            'true' => [true],
            'false' => [false],
            'la cadena sí' => ['sí'],
            'el número como cadena' => ['2'],
        ];
    }
}
