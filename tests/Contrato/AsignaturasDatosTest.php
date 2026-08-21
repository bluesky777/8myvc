<?php

namespace Tests\Contrato;

/**
 * La pantalla de asignar materias a grupos.
 *
 * De la lista de cobertura: `AsignaturasController` tenía seis rutas sin ninguna
 * respuesta comprobada. `datos-asignaturas` es la que llena esa pantalla, y
 * devolvía **el expediente entero de cada docente del colegio**. Ver 05 §51.
 */
class AsignaturasDatosTest extends CasoDeContrato
{
    /** Columnas que no pinta esa pantalla y no tienen por qué viajar. */
    private const NO_DEBEN_VIAJAR = [
        'num_doc', 'tipo_doc', 'ciudad_doc', 'fecha_nac', 'ciudad_nac',
        'estado_civil', 'barrio', 'direccion', 'telefono', 'celular',
        'facebook', 'email', 'email_usu', 'username', 'is_superuser',
    ];

    public function test_la_pantalla_recibe_lo_que_pinta(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->putJson('/api/asignaturas/datos-asignaturas', []);

        $r->assertStatus(200);
        $this->assertIsArray($r->json('materias'));
        $this->assertIsArray($r->json('grupos'));

        $profesores = $r->json('profesores');
        $this->assertNotEmpty($profesores, 'Sin docentes con contrato el test no compara nada.');

        // Los cuatro que usan `AsignaturasCtrl.ts` y su plantilla.
        foreach (['profesor_id', 'nombres', 'apellidos', 'foto_nombre'] as $campo) {
            $this->assertArrayHasKey($campo, $profesores[0], "Falta {$campo}, que la pantalla sí pinta.");
        }
    }

    /**
     * El hallazgo: `Profesor::contratos()` devuelve `num_doc`, `fecha_nac`,
     * `estado_civil`, `barrio`, `direccion`, `telefono`, `celular`, `facebook`,
     * `email` y el `is_superuser` de **cada docente del colegio**, y esta pantalla
     * —que solo necesita un desplegable con nombres— lo entregaba entero a
     * cualquiera de los 71 que pasan `auth.personal`.
     */
    public function test_el_expediente_del_docente_no_viaja_a_esa_pantalla(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->putJson('/api/asignaturas/datos-asignaturas', []);
        $profesores = $r->json('profesores');

        $this->assertNotEmpty($profesores);

        foreach (self::NO_DEBEN_VIAJAR as $campo) {
            $this->assertArrayNotHasKey($campo, $profesores[0],
                "«{$campo}» de todos los docentes sale por una pantalla de asignaturas.");
        }
    }

    /**
     * Y `GET api/contratos` se queda como está a propósito: su recorte es una
     * decisión del colegio pendiente (09 §5) porque lo lee la app de Flutter desde
     * pantallas de familia. Este test fija que **sigue** devolviéndolo, para que
     * el día que se decida se note que se decidió y no que se arrastró.
     */
    public function test_contratos_sigue_devolviendo_el_expediente(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->getJson('/api/contratos');
        $r->assertStatus(200);

        $fila = $r->json();
        $fila = isset($fila[0]) ? $fila[0] : $fila;

        $this->assertArrayHasKey('num_doc', $fila,
            'Si esto falla, alguien recortó `contratos()` sin pasar por la decisión del §5.');
    }
}
