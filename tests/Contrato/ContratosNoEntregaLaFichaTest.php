<?php

namespace Tests\Contrato;

/**
 * `GET api/contratos` **no entrega la ficha personal del docente**.
 *
 * La ruta no pide más que un token —`routes/api/estructura.php:79`—, así que su
 * respuesta la ve **un alumno de noveno con la app que ya tiene instalada**. Y
 * hasta el 24 ago 2026 traía, de cada uno de los dieciséis docentes: documento de
 * identidad, fecha de nacimiento, estado civil, barrio, **domicilio, teléfono
 * fijo y móvil**, correo, y el `is_superuser` de cada uno.
 *
 * En una sola llamada y **sin tener que nombrar a nadie**: la lista viene entera.
 * Eso es lo que lo separa de las otras fugas del mismo día — las demás necesitan
 * saber a quién pedir, o una segunda llamada, o una cuenta del personal.
 *
 * Lo midió `myvc-front-ce` entrando con un token de alumno de verdad, que es la
 * primera vez que esta ruta se probaba así. Con la cuenta de `administrador` —la
 * única con contraseña conocida hasta ese día— no se distinguía de lo correcto,
 * porque el administrador puede verlo todo: **verificar con el usuario más
 * poderoso es verificar la mitad.**
 *
 * ## Por qué este test enumera los campos prohibidos uno a uno
 *
 * Porque **la forma de volver a romperlo es añadir una columna al `SELECT`**, no
 * quitar la guarda. Un test que sólo mirase el código, o que comprobara que los
 * campos buenos siguen ahí, seguiría verde con `p.direccion` reintroducido «para
 * la pantalla de administración» — que es exactamente el gesto que lo escribió la
 * primera vez.
 *
 * Y comprueba **las dos mitades**: que lo personal no está **y** que lo que los
 * once consumidores sí leen sigue estando. Sin la segunda, este test se cumple
 * devolviendo un array vacío.
 */
class ContratosNoEntregaLaFichaTest extends CasoDeContrato
{
    /** Lo que ningún cliente lee y ningún alumno debe recibir. */
    private const PROHIBIDOS = [
        'tipo_doc', 'num_doc', 'ciudad_doc', 'fecha_nac', 'ciudad_nac', 'titulo',
        'estado_civil', 'barrio', 'direccion', 'telefono', 'celular', 'facebook',
        'email', 'username', 'email_usu', 'is_superuser', 'imagen_id',
    ];

    /** Lo que los once consumidores sí leen — `myvc_front` (6), Flutter (4) y `NotasPerdidas`. */
    private const NECESARIOS = [
        'profesor_id', 'nombres', 'apellidos', 'nombre_completo', 'foto_nombre', 'user_id',
    ];

    private function contratosCon(string $token): array
    {
        $cuerpo = $this->withToken($token)->getJson('/api/contratos')->assertStatus(200)->json();

        $this->assertNotEmpty($cuerpo,
            'El seed no tiene contratos en el año del usuario: sin filas este test no comprueba nada.');

        return (array) $cuerpo[0];
    }

    public function test_un_alumno_no_recibe_los_datos_personales_del_docente(): void
    {
        $fila = $this->contratosCon($this->tokenDe($this->usuarioDeTipo('Alumno')->username));

        foreach (self::PROHIBIDOS as $campo) {
            $this->assertArrayNotHasKey($campo, $fila,
                "`GET contratos` volvió a entregar `{$campo}` de los docentes, y lo ve un alumno. "
                .'Si se añadió para una pantalla de administración, esa pantalla tiene que pedirlo '
                .'por una ruta con `auth.personal`, no por ésta.');
        }
    }

    /** Y tampoco un acudiente, que llega por la misma puerta. */
    public function test_un_acudiente_tampoco(): void
    {
        $fila = $this->contratosCon($this->tokenDe($this->usuarioDeTipo('Acudiente')->username));

        foreach (self::PROHIBIDOS as $campo) {
            $this->assertArrayNotHasKey($campo, $fila);
        }
    }

    /**
     * La otra mitad: lo que sí se usa sigue llegando.
     *
     * Se comprueba **con el personal**, que es quien llena los desplegables de
     * titular de grupo y de disciplina — si esto se rompiera, esas pantallas se
     * quedarían sin nombres y el recorte habría costado algo.
     */
    public function test_lo_que_los_clientes_si_leen_sigue_llegando(): void
    {
        $fila = $this->contratosCon($this->tokenDelPersonalLlano());

        foreach (self::NECESARIOS as $campo) {
            $this->assertArrayHasKey($campo, $fila,
                "El recorte se llevó `{$campo}`, que sí lo leen los clientes.");
        }

        $this->assertNotSame('', trim((string) $fila['nombre_completo']));
    }
}
