<?php

namespace Tests\Contrato;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Los buscadores de personas son del personal del colegio, no de las familias.
 *
 * La regla la fijó Joseth el 20 ago 2026, y es la misma que ya rige el resto de
 * la API: **un compañero no puede ver datos personales de otro.** Un profesor sí
 * puede buscar en todo el plantel y de cualquier año — eso también está decidido,
 * y es la razón de que `putPersonasCheck` no filtre por año.
 *
 * Hasta hoy las dos rutas iban sin más guard que `auth.token`, o sea que
 * cualquier sesión válida servía. Medido antes de tocarlas, con el token de un
 * alumno del seed y `texto` de una sola letra:
 *
 * | Ruta | Lo que devolvía a un alumno |
 * |---|---|
 * | `alumnos/personas-check` | 61 compañeros: nombres, apellidos, foto y `alumno_id` |
 * | `alumnos/documento-check` | 51 compañeros **con su número de documento** |
 *
 * Un acudiente recibía lo mismo. Y el `alumno_id` no es un dato más: es la llave
 * de toda la superficie que fija `SuperficieDeUnAlumnoTest` — el buscador era el
 * paso previo, el que te dice qué números pedir.
 *
 * `alumnos/eps-check` y `acudientes/ocupaciones-check` se quedan como estaban:
 * devuelven `DISTINCT eps` y `DISTINCT ocupacion`, valores sueltos de un
 * catálogo, sin ninguna persona detrás.
 *
 * **La otra mitad es del front y no está aquí:** el buscador del `sidebarMenu`
 * se pinta sin `ng-if`, así que un alumno lo ve y puede teclear en él. Con este
 * guard no obtiene nada; esconderlo es trabajo de `myvc_front`.
 */
class BuscadoresDePersonasTest extends CasoDeContrato
{
    public static function buscadores(): array
    {
        return [
            // Cada uno con un texto que sí encuentre algo: `documento` es
            // numérico y buscar una letra ahí no devuelve nada, con lo que el
            // caso del personal pasaría por el motivo equivocado.
            'personas-check por nombre' => ['alumnos/personas-check', 'a'],
            'documento-check por documento' => ['alumnos/documento-check', '1'],
        ];
    }

    #[DataProvider('buscadores')]
    public function test_un_alumno_no_puede_buscar_a_sus_companeros(string $ruta, string $texto): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->putJson("/api/{$ruta}", ['texto' => $texto],
            ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }

    #[DataProvider('buscadores')]
    public function test_un_acudiente_tampoco(string $ruta, string $texto): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Acudiente')->username);

        $this->putJson("/api/{$ruta}", ['texto' => $texto],
            ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }

    #[DataProvider('buscadores')]
    public function test_el_personal_del_colegio_sigue_buscando(string $ruta, string $texto): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $respuesta = $this->putJson("/api/{$ruta}", ['texto' => $texto],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200)->json();

        $this->assertNotEmpty($respuesta['personas'],
            'El guard ha dejado fuera también al personal, que sí debe buscar.');
    }
}
