<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ocho rechazos de permiso que contestaban con el código de otra cosa.
 *
 * Salieron del hueco de cobertura del 21 ago 2026: veintidós rutas que ningún
 * test había mirado y que alcanza cualquiera con token. Golpeadas todas con un
 * token de alumno, ninguna dejaba pasar nada —el arreglo no es de autorización—
 * pero **la mitad contestaba con un código que dice otra cosa que el mensaje**.
 *
 * Los dos grupos no pesan igual, y la diferencia es lo que hace que esto no sea
 * cosmética:
 *
 * - **`enfermeria/*` respondía 401**, y un 401 no es un código mal elegido aquí:
 *   es una orden al frontend. `Sesion.ts` intercepta todo 401 que no venga de
 *   una ruta de sesión, **rota los tokens** y reenvía; si la renovación falla,
 *   `expirar('token')` borra la sesión, avisa con «La sesión ha expirado» y manda
 *   al login. A quien no tiene el permiso se le rotaba la sesión en cada intento,
 *   y en la carrera que el propio front documenta se le echaba de la plataforma.
 * - **`calendario/*` respondía `404, 'No tienes permiso'`**: el código y el
 *   mensaje dicen cosas distintas.
 *
 * Los ocho pasan a 403. Ningún cliente los leía para otra cosa: el front pinta
 * el mensaje del cuerpo con `toastr.error` en las ocho.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §54.
 */
class RechazosQueMientenTest extends CasoDeContrato
{
    /** @return array<string, array{0:string,1:string,2:array<string,mixed>}> */
    public static function rechazosDePermiso(): array
    {
        return [
            'antecedentes médicos' => ['PUT', 'enfermeria/guardar-valor',
                ['antec_id' => 1, 'propiedad' => 'alergias', 'valor' => 'x']],
            'crear un suceso de enfermería' => ['POST', 'enfermeria/crear-suceso',
                ['alumno_id' => 1, 'fecha_suceso' => '2026-08-21']],
            'editar un suceso de enfermería' => ['PUT', 'enfermeria/guardar-valor-suceso',
                ['suceso_id' => 1, 'propiedad' => 'descripcion_suceso', 'valor' => 'x']],
            'borrar un suceso de enfermería' => ['DELETE', 'enfermeria/destroy/1', []],
            'crear un evento' => ['PUT', 'calendario/crear-evento',
                ['title' => 'sonda', 'start' => '2026-09-01']],
            'guardar un evento' => ['PUT', 'calendario/guardar-evento',
                ['id' => 1, 'title' => 'sonda']],
            'eliminar un evento' => ['PUT', 'calendario/eliminar-evento', ['id' => 1]],
            'sincronizar los cumpleaños' => ['PUT', 'calendario/sincronizar-cumples', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    #[DataProvider('rechazosDePermiso')]
    public function test_un_rechazo_de_permiso_es_403(string $verbo, string $ruta, array $cuerpo): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $r = $this->json($verbo, '/api/'.$ruta, $cuerpo, ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(403);

        // El 401 es el que más pesa y el que menos se ve, así que se dice aparte:
        // con él, el front rota la sesión del que llama en vez de enseñarle el
        // mensaje.
        $this->assertNotSame(401, $r->getStatusCode(),
            "'{$ruta}' responde 401, y el front lo lee como «sesión caducada».");
    }

    /**
     * Y el mensaje de `alumnos/update`, que hablaba de otra operación.
     *
     * Decía «No tienes permiso para eliminar alumnos definitivamente», copiado
     * del `forcedelete` de más abajo en el mismo controlador. El código era el
     * correcto; el que mentía era el texto, que es lo que se enseña y lo que
     * queda en el log de un colegio. El criterio de la ruta es
     * `puedeEditarAlumnos`.
     */
    public function test_el_rechazo_de_editar_un_alumno_no_habla_de_borrarlo(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);
        $otro = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->putJson('/api/alumnos/update/'.$otro->id, ['nombres' => 'Sonda'],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para editar alumnos.');
    }

    /**
     * Y quien sí tiene el permiso sigue pudiendo: se corrige el código, no el criterio.
     *
     * Se comprueba con el calendario porque su criterio —`Profesor` o
     * superusuario— se cumple con un usuario del seed sin montar nada. El de
     * enfermería pide el rol `Enfermero`, que el seed no reparte.
     */
    public function test_un_profesor_sigue_creando_eventos(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $this->putJson('/api/calendario/crear-evento',
            ['title' => 'Reunión de área', 'start' => '2026-09-01'],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonStructure(['evento_id']);

        $this->assertSame(1, DB::table('calendario')->where('title', 'Reunión de área')->count());
    }
}
