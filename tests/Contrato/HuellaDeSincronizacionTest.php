<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `GET sincronizacion/huella`: la pregunta barata de la app de escritorio.
 *
 * `myvc_horarios` pregunta cada minuto si el colegio ha cambiado, y hasta hoy eso
 * eran cinco viajes y 121.183 bytes. Esta ruta contesta lo mismo en un viaje y
 * unos bytes. El contrato entero está en
 * `docs/migracion/34-la-huella-de-sincronizacion.md`.
 *
 * ## Lo que estos casos protegen, que no es «que devuelva 200»
 *
 * **Que la huella siga midiendo LO QUE DEVUELVEN LAS CINCO LECTURAS.** Es la
 * única propiedad de la que depende todo: una huella que cuente la tabla entera
 * se mueve con filas que el cliente nunca ve —y, peor, **puede no moverse con
 * filas que sí ve**—. Y no es teórico: en el seed, `asignaturas` tiene 1.459
 * filas y la lectura devuelve 134.
 *
 * Por eso el caso principal **no comprueba un número escrito a mano**: compara la
 * huella contra **la respuesta de verdad de cada lectura**, contando los
 * elementos que devuelve. Si alguien cambia un `WHERE` en un `getIndex` y no lo
 * cambia aquí, esto se pone rojo — que es exactamente el día en que hay que
 * enterarse.
 */
class HuellaDeSincronizacionTest extends CasoDeContrato
{
    private const RUTA = '/api/sincronizacion/huella';

    private function cab(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * **El caso que sostiene el endpoint.** Para cada lectura, `filas` tiene que
     * ser exactamente lo que devuelve esa lectura.
     *
     * Se llama a las dos cosas con **el mismo token**: si se usaran sujetos
     * distintos, el año podría no coincidir y las dos cifras dejarían de ser
     * comparables sin que nada fallara.
     */
    public function test_las_filas_son_las_que_devuelve_cada_lectura(): void
    {
        $token = $this->tokenDelPersonalDe(8);

        $huella = $this->getJson(self::RUTA, $this->cab($token))
            ->assertStatus(200)
            ->json('huellas');

        foreach (['years', 'grados', 'grupos', 'asignaturas', 'profesores'] as $lectura) {
            $devueltas = $this->getJson('/api/'.$lectura, $this->cab($token))
                ->assertStatus(200)
                ->json();

            $this->assertIsArray($devueltas, "`GET {$lectura}` no devolvió una lista.");

            $this->assertSame(
                count($devueltas),
                $huella[$lectura]['filas'],
                "La huella de `{$lectura}` dice ".$huella[$lectura]['filas'].' filas y '.
                "`GET {$lectura}` devuelve ".count($devueltas).".\n".
                'Alguien cambió el filtro de una de las dos y no de la otra. La huella '.
                'tiene que medir LO QUE DEVUELVE LA LECTURA, no la tabla: si mide la tabla, '.
                'se moverá con filas que el cliente no ve y podrá no moverse con las que sí.'
            );
        }
    }

    /**
     * **Las dos cifras, y por qué son dos.** Un borrado no mueve `updated_at` —la
     * fila que se va no deja timbre— así que sin `filas` sería invisible.
     *
     * El caso borra una asignatura de las que la lectura devuelve y comprueba que
     * la huella se mueve. Sin la mitad del conteo, esto pasaría igual y el
     * escritorio se quedaría con un dato que ya no existe.
     */
    public function test_un_borrado_mueve_la_huella_aunque_no_mueva_la_fecha(): void
    {
        $token = $this->tokenDelPersonalDe(8);

        $antes = $this->getJson(self::RUTA, $this->cab($token))->json('huellas.asignaturas');

        $id = DB::selectOne(
            'SELECT a.id FROM asignaturas a
             INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
             INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = 8 AND g.deleted_at IS NULL
             WHERE a.deleted_at IS NULL LIMIT 1'
        );
        $this->assertNotNull($id, 'El seed no tiene asignaturas en el año 8; el caso no probaría nada.');

        // Un borrado lógico, que es como borra esta base. `updated_at` NO se toca a
        // propósito: es justo el caso que la fecha no puede ver.
        DB::update('UPDATE asignaturas SET deleted_at = ? WHERE id = ?', ['2026-09-07 00:00:00', $id->id]);

        $despues = $this->getJson(self::RUTA, $this->cab($token))->json('huellas.asignaturas');

        $this->assertSame($antes['filas'] - 1, $despues['filas'],
            'Borrar una asignatura tiene que bajar el conteo de la huella.');

        $this->assertSame($antes['ultimo_cambio'], $despues['ultimo_cambio'],
            'Y la FECHA no se mueve, que es la razón por la que hacen falta las dos cifras. '.
            'Si esta aserción falla, alguien tocó `updated_at` en el borrado y el caso ya no '.
            'demuestra lo que dice — no es que esté mal: es que dejó de probar el punto.');
    }

    /**
     * **El bloque de `profesores` sólo para quien puede leer `profesores`.**
     *
     * Medido el 7 sep 2026 sobre `simonbolivar`: 2.328 cuentas pasan `auth.token`,
     * **45** pasan `auth.personal` y **10** pasan `esAdministrativo`, que es lo que
     * `GET profesores` exige dentro del método. Una huella que contara el
     * movimiento del personal a las 45 se lo estaría contando a 35 que no pueden
     * leer esa tabla.
     */
    public function test_quien_no_puede_leer_profesores_no_recibe_su_huella(): void
    {
        $llano = $this->tokenDelPersonalLlanoDe(8);

        // El control: que este sujeto de verdad NO pueda leer la lectura real.
        // Sin esto, el test pasaría igual con un sujeto que sí puede y no
        // demostraría nada.
        $this->getJson('/api/profesores', $this->cab($llano))->assertStatus(403);

        $r = $this->getJson(self::RUTA, $this->cab($llano))->assertStatus(200);

        $this->assertArrayHasKey('profesores', $r->json('huellas'),
            'LA CLAVE NO DESAPARECE. La forma de la respuesta no puede depender de quién '.
            'pregunta: un lector que exija la clave se rompería con el usuario raso y no con '.
            'el administrativo, que es el fallo que sólo sale en producción y en la mitad de '.
            'las cuentas. Es la decisión 7 del `inadecuado`, sobre este mismo cliente.');

        $this->assertNull($r->json('huellas.profesores'),
            'Está la clave, y no trae nada. «El personal cambió hace un minuto» es '.
            'información sobre personas, y este sujeto no puede leer esa tabla.');

        // Y las otras cuatro sí, porque esas las lee cualquiera con token.
        foreach (['years', 'grados', 'grupos', 'asignaturas'] as $lectura) {
            $this->assertArrayHasKey($lectura, $r->json('huellas'),
                "`{$lectura}` la lee cualquier cuenta con token; quitársela aquí sería ".
                'apretar más que la lectura que resume.');
        }
    }

    /**
     * **Y la omisión se DICE.** Un bloque ausente y un bloque que no se movió se
     * leen igual desde el cliente, y de las dos lecturas la falsa —«no hay
     * cambios»— es la que deja al escritorio con datos viejos y sin saberlo.
     */
    public function test_la_omision_se_anuncia_en_vez_de_faltar_en_silencio(): void
    {
        $r = $this->getJson(self::RUTA, $this->cab($this->tokenDelPersonalLlanoDe(8)))
            ->assertStatus(200);

        $this->assertArrayHasKey('profesores', (array) $r->json('omitidas'),
            'Que falte el bloque no basta: hay que decir POR QUÉ falta, o el cliente '.
            'no distingue «no puedes verlo» de «no ha cambiado».');
    }

    /** Y quien sí puede, lo recibe. Es el control del caso anterior. */
    public function test_quien_puede_leer_profesores_si_recibe_su_huella(): void
    {
        $r = $this->getJson(self::RUTA, $this->cab($this->tokenDelPersonalDe(8)))
            ->assertStatus(200);

        $this->assertArrayHasKey('profesores', $r->json('huellas'),
            'Un administrativo sí puede leer `GET profesores`, así que su huella le toca.');

        $this->assertSame([], (array) $r->json('omitidas'),
            'Y no se le omite nada.');
    }

    /**
     * La forma, que es lo que el cliente va a parsear: dos cifras por lectura y
     * `ultimo_cambio` anulable.
     *
     * `null` significa **«ninguna de las filas que se devuelven tiene fecha»**, no
     * «no hay filas»: eso lo dice `filas`. Se fija porque es la diferencia entre
     * que el cliente reintente y que se quede parado.
     */
    #[DataProvider('lasCincoLecturas')]
    public function test_cada_huella_trae_las_dos_cifras(string $lectura): void
    {
        $h = $this->getJson(self::RUTA, $this->cab($this->tokenDelPersonalDe(8)))
            ->assertStatus(200)
            ->json("huellas.{$lectura}");

        $this->assertIsArray($h, "Falta la huella de `{$lectura}`. Las cinco claves están "
            .'SIEMPRE; lo que cambia es si traen algo.');
        $this->assertArrayHasKey('filas', $h);
        $this->assertArrayHasKey('ultimo_cambio', $h);
        $this->assertIsInt($h['filas'], '`filas` es un entero, no una cadena: el cliente compara.');
        $this->assertTrue($h['ultimo_cambio'] === null || is_string($h['ultimo_cambio']),
            '`ultimo_cambio` es una fecha en texto o null.');
    }

    /**
     * **Un alumno no entra — y el código que sale no es el que uno escribiría.**
     *
     * Sale **400**, no 403, y el motivo importa: en la base de tests **ningún
     * alumno ni acudiente llega a construir su contexto** —`User::fromToken()`
     * aborta con `user_inactivo_por_falta_periodos`—, así que la petición muere
     * **antes de que `auth.personal` llegue a mirarla**. Comprobado el 7 sep 2026
     * con los cinco primeros alumnos del año 8 y los cuatro primeros acudientes:
     * los nueve dan 400.
     *
     * Así que este caso demuestra **«no entra»** y **no** demuestra «lo para
     * `auth.personal`»: eso vive en `AutorizacionTest`, que es donde se prueba el
     * guard en sí. Se deja escrito porque un 400 aquí parece un fallo del endpoint
     * y no lo es, y porque el día que el seed gane alumnos con periodo **este caso
     * empezará a devolver 403 y habrá que actualizar el número, no asustarse**.
     *
     * Se afirma sobre lo único que es cierto de las dos formas: **que no es 200**.
     */
    public function test_un_alumno_no_entra(): void
    {
        $alumno = DB::selectOne('SELECT username FROM users
            WHERE tipo = "Alumno" AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene ningún alumno activo; este caso no probaría nada.');

        $r = $this->getJson(self::RUTA, $this->cab($this->tokenDe($alumno->username)));

        $this->assertContains($r->getStatusCode(), [400, 403],
            'Un alumno no puede recibir la huella. Hoy sale 400 porque su contexto no se '.
            'construye en el seed y muere antes del guard; con un seed que sí lo construya '.
            'saldría 403 por `auth.personal`. Lo que no puede salir nunca es 200.');

        $this->assertNotSame(200, $r->getStatusCode(),
            'Un alumno recibiendo la huella del colegio es el fallo que este caso existe para cazar.');
    }

    /** Sin token, 401. */
    public function test_sin_token_no_entra(): void
    {
        $this->getJson(self::RUTA)->assertStatus(401);
    }

    /**
     * **La forma no depende de quién pregunta.** Las cinco claves de `huellas` salen
     * para los dos sujetos, y en el mismo orden.
     *
     * Es el caso que ata la decisión: si mañana alguien «limpia» la respuesta
     * quitando la clave que vale `null`, esto se pone rojo. Y el fallo que evita
     * **no se vería en pruebas**, porque el escritorio lo usan administrativos y
     * con ellos salen siempre las cinco.
     */
    public function test_las_cinco_claves_salen_pregunte_quien_pregunte(): void
    {
        $conPermiso = $this->getJson(self::RUTA, $this->cab($this->tokenDelPersonalDe(8)))
            ->assertStatus(200)->json('huellas');

        $sinPermiso = $this->getJson(self::RUTA, $this->cab($this->tokenDelPersonalLlanoDe(8)))
            ->assertStatus(200)->json('huellas');

        $this->assertSame(
            array_keys($conPermiso),
            array_keys($sinPermiso),
            'Las claves de `huellas` tienen que ser las mismas para los dos. Si divergen, '.
            'el cliente que las lea se rompe sólo con una parte de las cuentas — y esta API '.
            'ya tomó esa decisión al revés una vez (decisión 7 del `inadecuado`).'
        );

        $this->assertCount(5, $sinPermiso, 'Son cinco lecturas y cinco claves, siempre.');
    }

    /** @return array<string, array{string}> */
    public static function lasCincoLecturas(): array
    {
        return [
            'years' => ['years'],
            'grados' => ['grados'],
            'grupos' => ['grupos'],
            'asignaturas' => ['asignaturas'],
            'profesores' => ['profesores'],
        ];
    }
}
