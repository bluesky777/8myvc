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
     * Y `GET api/contratos` **dejó de devolver el expediente el 24 ago 2026**.
     *
     * Este test fijaba lo contrario —que seguía devolviéndolo— *«para que el día
     * que se decida se note que se decidió y no que se arrastró»*. Ese día llegó,
     * así que ahora fija el recorte.
     *
     * Lo que lo desbloqueó fue medir el coste que la decisión daba por
     * desconocido: **los once consumidores leen id, nombre, foto y `user_id`**, y
     * ninguno toca lo personal. Está en el [09 §10](../../docs/migracion/09-pendientes.md).
     *
     * El detalle de qué se quita y por qué vive en `ContratosNoEntregaLaFichaTest`,
     * que lo comprueba con token de **alumno** y de **acudiente** — que es quien
     * lo veía y a quien este test, que usa personal, no puede representar.
     */
    public function test_contratos_ya_no_devuelve_el_expediente(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->getJson('/api/contratos');
        $r->assertStatus(200);

        $fila = $r->json();
        $fila = isset($fila[0]) ? $fila[0] : $fila;

        $this->assertArrayNotHasKey('num_doc', $fila,
            'Volvió el expediente a `contratos()`. Esa ruta sólo pide un token, así que '
            .'lo que se añada ahí lo ve un alumno — ver 09 §10.');
        $this->assertArrayHasKey('nombre_completo', $fila,
            'El recorte se llevó el nombre, que es justo lo único que los clientes piden.');
    }
}
