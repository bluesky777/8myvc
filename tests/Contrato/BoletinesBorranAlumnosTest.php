<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * **`boletines2/destroy` y `boletines3/destroy` no borran ningún boletín: mandan
 * un ALUMNO a la papelera.** Son las dos copias que la §72 no miró.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §89.
 *
 * ## Por qué esto es la §72 otra vez, y no un hallazgo nuevo
 *
 * La [§72](../../docs/migracion/05-codigo-muerto-y-roto.md) cerró las dos rutas de
 * `EditnotaController` que mandaban un alumno a la papelera, y dejó escrita la
 * lección al hacerlo:
 *
 * > Cerrar una de tres es lo que pasa cuando se arregla **el sitio que se está
 * > mirando y no la operación**.
 *
 * La operación es `Alumno::find($id)` + `->delete()`, y en `app/` está **cuatro
 * veces**: `AlumnosController` (con criterio desde siempre), `EditnotaController`
 * (cerrada por la §72) y `Informes\Boletines2Controller` y
 * `Informes\Boletines3Controller`, que son la misma copia byte a byte. La §72 se
 * cerró sobre la población «este controlador» — así que las dos de boletines
 * siguieron abiertas **el mismo día en que se escribía que eso no debía volver a
 * pasar**.
 *
 * | Ruta | Guard antes | Qué borra de verdad |
 * |---|---|---|
 * | `DELETE api/alumnos/destroy/{id}` | `puedeEditarAlumnos` | alumno |
 * | `DELETE api/editnota/destroy/{id}` | `puedeEditarAlumnos` (§72) | alumno |
 * | `DELETE api/boletines2/destroy/{id}` | **nada, solo `auth.personal`** | alumno |
 * | `DELETE api/boletines3/destroy/{id}` | **nada, solo `auth.personal`** | alumno |
 *
 * ## El hueco es el mismo que midió la §72.1, y por la misma bandera
 *
 * `puedeEditarAlumnos` es superusuario **o** profesor con `profes_can_edit_alumnos`,
 * apagada en los dieciséis colegios. Medido aquí antes de tocar nada, con un token
 * de profesor y la bandera apagada: `alumnos/destroy` contestaba **403** y
 * `boletines2/destroy` **200**, con el alumno en la papelera.
 *
 * ## Y no apaga ninguna pantalla
 *
 * El único cliente que nombra estos dos controladores es `myvc_front`, en
 * `BoletinesApi.ts`, y declara **dos** rutas de cada uno —`detailed-notas` y
 * `detailed-notas-group`—; `destroy` no aparece. `myvc_front_2` y `myvc_flutter`
 * no nombran `boletines2` ni `boletines3` en ningún fichero. Es el mismo
 * argumento de la §72.3 sobre la población que faltaba.
 */
class BoletinesBorranAlumnosTest extends CasoDeContrato
{
    /**
     * Un profesor con la bandera **apagada aquí mismo**, igual que en
     * `EditnotaBorraAlumnosTest`: si el seed la trajera encendida el profesor
     * pasaría el criterio de verdad y el caso quedaría verde sin medir el guard.
     */
    private function tokenDeProfesorSinBandera(): string
    {
        DB::update('UPDATE years SET profes_can_edit_alumnos = 0');

        return $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
    }

    private function alumnoVivo(): object
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($alumno, 'El seed necesita un alumno.');

        return $alumno;
    }

    private function estaEnLaPapelera(int $alumnoId): bool
    {
        return DB::selectOne('SELECT deleted_at FROM alumnos WHERE id = ?', [$alumnoId])->deleted_at !== null;
    }

    /** @return array<string, array{string}> */
    public static function lasDosCopias(): array
    {
        return [
            'boletines2' => ['boletines2'],
            'boletines3' => ['boletines3'],
        ];
    }

    /**
     * Un profesor sin la bandera no manda un alumno a la papelera por la ruta de
     * boletines, que es lo que ya decían sus otras dos hermanas.
     *
     * Se mira **el resultado y no el código**: un 403 que además borrara sería el
     * mismo fallo con mejor pinta.
     */
    #[DataProvider('lasDosCopias')]
    public function test_un_profesor_no_manda_un_alumno_a_la_papelera_por_boletines(string $recurso): void
    {
        $token = $this->tokenDeProfesorSinBandera();
        $alumno = $this->alumnoVivo();

        $this->withToken($token)->deleteJson('/api/'.$recurso.'/destroy/'.$alumno->id)
            ->assertStatus(403);

        $this->assertFalse($this->estaEnLaPapelera($alumno->id),
            "El alumno acabó en la papelera por {$recurso}/destroy pese al rechazo.");
    }

    /**
     * El otro lado, sin el cual esto sería cerrar la puerta con la casa dentro:
     * **quien sí tiene el criterio sigue pudiendo**, y por las dos copias.
     */
    #[DataProvider('lasDosCopias')]
    public function test_un_superusuario_sigue_mandando_el_alumno_a_la_papelera(string $recurso): void
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($super, 'El seed necesita un superusuario.');

        $alumno = $this->alumnoVivo();

        $this->withToken($this->tokenDe($super->username))
            ->deleteJson('/api/'.$recurso.'/destroy/'.$alumno->id)
            ->assertStatus(200);

        $this->assertTrue($this->estaEnLaPapelera($alumno->id),
            "El superusuario dejó de poder borrar por {$recurso}/destroy: se cerró de más.");
    }

    /**
     * La asimetría que señaló esto, fijada para que no se pueda volver a cerrar una
     * de cuatro: **ninguna de las cuatro puertas de la misma operación manda al
     * alumno a la papelera** con el mismo token.
     *
     * Los códigos **no** coinciden y se fijan tal cual, con el porqué al lado:
     *
     * | Ruta | Código | Por qué es ése |
     * |---|---|---|
     * | `alumnos/destroy` | 400 | legacy: `abort(400, 'No tiene permisos')` escrito a mano en el controlador. No se toca aquí — es de otro lote |
     * | `editnota/destroy` | 403 | `Autoriza::exigir`, puesto por la §72, que es código nuevo |
     * | `boletines2/destroy` | 403 | el mismo `Autoriza::exigir`, puesto aquí |
     * | `boletines3/destroy` | 403 | ídem |
     *
     * O sea que lo que tienen en común es **el resultado**, no el código, y es el
     * resultado lo que decide si un alumno está en la papelera. Unificar el 400 de
     * `alumnos/destroy` es un cambio de contrato de una ruta que sí llaman los
     * clientes, y se anota en vez de hacerse.
     */
    public function test_ninguna_de_las_cuatro_puertas_manda_al_alumno_a_la_papelera(): void
    {
        $token = $this->tokenDeProfesorSinBandera();

        $codigos = [];

        foreach (['alumnos', 'editnota', 'boletines2', 'boletines3'] as $recurso) {
            $alumno = $this->alumnoVivo();

            $this->olvidarControladores();

            $codigos[$recurso.'/destroy'] = $this->withToken($token)
                ->deleteJson('/api/'.$recurso.'/destroy/'.$alumno->id)->status();

            $this->assertFalse($this->estaEnLaPapelera($alumno->id),
                "El alumno acabó en la papelera por {$recurso}/destroy: la operación tiene una puerta sin llave.");
        }

        $this->assertSame(
            [
                'alumnos/destroy' => 400,
                'editnota/destroy' => 403,
                'boletines2/destroy' => 403,
                'boletines3/destroy' => 403,
            ],
            $codigos,
            'Cambió alguno de los cuatro. Si es el 400 de alumnos/destroy, alguien decidió unificar los códigos y hay que anotarlo; si es un 200, se volvió a abrir la puerta.'
        );
    }
}
