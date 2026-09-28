<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `GET horario/anterior/proyecto` — el proyecto de la versión oficial del año pasado,
 * para que el programa de horarios herede de él al importar el año nuevo.
 *
 * ## Qué existe esto para cazar
 *
 * **1. Que devuelva la versión de OTRO año.** El año sale del token y el anterior es
 * `year - 1`; un `- 2`, o perder el filtro por `year`, seguiría contestando 200 con un
 * `.myvch` perfectamente válido, y el programa heredaría las colocaciones de un horario
 * que ya no es el del año pasado. Nadie lo notaría hasta que un docente se quejara.
 *
 * **2. Que se cuele por el permiso de mirar.** Es el mismo fichero que
 * `versiones/{id}/proyecto` —con las disponibilidades de los docentes dentro— y lo
 * cierra `puedePublicarHorario` dentro del método, no el guard.
 *
 * **3. Que «no hay oficial» se vuelva un error.** El primer año de un colegio no hay
 * nada que heredar, y eso es lo normal: 200 con `proyecto` a null y un `motivo`.
 */
class HorarioProyectoAnteriorTest extends CasoDeContrato
{
    private const RUTA = '/api/horario/anterior/proyecto';

    /** El sujeto: superusuario, que es quien puede hoy (el rol de coordinación está vacío). */
    private function sujeto(): object
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto tiene que ser superusuario: si no, el 403 de `puedePublicarHorario` '.
            'se leería como un fallo de la ruta.');

        return $usuario;
    }

    /** El `years.year` del token del sujeto. */
    private function anioDelSujeto(): int
    {
        return (int) DB::selectOne(
            'SELECT y.year FROM users u
               JOIN periodos p ON p.id = u.periodo_id
               JOIN years y ON y.id = p.year_id
              WHERE u.id = ?',
            [$this->sujeto()->id]
        )->year;
    }

    /** El id de la fila viva de `years` con ese año, o falla si el volcado no la trae. */
    private function idDelAnio(int $anio): int
    {
        $fila = DB::selectOne('SELECT id FROM years WHERE year = ? AND deleted_at IS NULL', [$anio]);

        $this->assertNotNull($fila, "La base de tests tiene que traer vivo el año {$anio}.");

        return (int) $fila->id;
    }

    /** Un proyecto con tabuladores, comillas y acentos: lo que el JSON tiene que escapar y deshacer. */
    private function proyectoCrudo(string $marca): string
    {
        return "{\n\t\"marca\": \"{$marca}\",\n\t\"colegio\": \"COLEGIO ADVENTISTA SIMÓN BOLIVAR\",\n".
               "\t\"nota\": \"comillas \\\"dentro\\\"\"\n}\n";
    }

    /** Sube una versión al año dado y la marca como su oficial. */
    private function oficialEn(int $yearId, string $marca): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, NULL, ?, NULL, ?, ?)',
            [$yearId, "Oficial {$marca}", $this->proyectoCrudo($marca), '2026-09-28 10:00:00', '2026-09-28 10:00:00']
        );
        $id = (int) DB::getPdo()->lastInsertId();

        DB::update('UPDATE years SET horario_version_id = ? WHERE id = ?', [$id, $yearId]);

        return $id;
    }

    private function pedir()
    {
        return $this->getJson(self::RUTA, [
            'Authorization' => 'Bearer '.$this->tokenDe($this->sujeto()->username),
        ]);
    }

    #[Test]
    public function devuelve_el_proyecto_oficial_del_anio_anterior_y_su_anio(): void
    {
        $anterior = $this->anioDelSujeto() - 1;
        $version = $this->oficialEn($this->idDelAnio($anterior), 'anterior');

        $r = $this->pedir()->assertStatus(200);

        $this->assertSame([
            'anio' => $anterior,
            'version_id' => $version,
            'nombre' => 'Oficial anterior',
            'proyecto' => $this->proyectoCrudo('anterior'),
            'motivo' => null,
        ], $r->json(),
            'La forma entera: el año, la versión y el fichero tal cual se subió, ya '.
            'des-escapado por el JSON.');
    }

    #[Test]
    public function sin_version_oficial_devuelve_proyecto_null_y_el_motivo(): void
    {
        $anterior = $this->anioDelSujeto() - 1;
        DB::update('UPDATE years SET horario_version_id = NULL WHERE id = ?', [$this->idDelAnio($anterior)]);

        $this->pedir()->assertStatus(200)->assertExactJson([
            'anio' => $anterior,
            'version_id' => null,
            'nombre' => null,
            'proyecto' => null,
            'motivo' => "El año {$anterior} no tiene horario oficial.",
        ]);
    }

    #[Test]
    public function sin_anio_anterior_vivo_devuelve_proyecto_null_y_el_motivo(): void
    {
        $anterior = $this->anioDelSujeto() - 1;
        DB::update('UPDATE years SET deleted_at = ? WHERE year = ?', ['2026-09-28 10:00:00', $anterior]);

        $this->pedir()->assertStatus(200)->assertExactJson([
            'anio' => $anterior,
            'version_id' => null,
            'nombre' => null,
            'proyecto' => null,
            'motivo' => "No existe el año {$anterior} en este colegio.",
        ]);
    }

    #[Test]
    public function el_personal_llano_no_puede_llevarselo(): void
    {
        $this->oficialEn($this->idDelAnio($this->anioDelSujeto() - 1), 'anterior');

        // Pasa el guard `auth.personal` y aun así no entra: lo cierra el criterio fino.
        $this->getJson(self::RUTA, [
            'Authorization' => 'Bearer '.$this->tokenDelPersonalLlano(),
        ])->assertStatus(403);
    }

    #[Test]
    public function la_oficial_de_otro_anio_no_se_devuelve(): void
    {
        $anterior = $this->anioDelSujeto() - 1;
        DB::update('UPDATE years SET horario_version_id = NULL WHERE id = ?', [$this->idDelAnio($anterior)]);
        $otra = $this->oficialEn($this->idDelAnio($anterior - 1), 'dos-atras');

        $r = $this->pedir()->assertStatus(200);

        $this->assertNull($r->json('proyecto'),
            'La oficial de hace dos años no es la del año pasado: heredarla colocaría '.
            'las clases de un horario que ya no rige.');
        $this->assertSame($anterior, $r->json('anio'));

        // El control: esa oficial existe, así que el null es del filtro por año y no de
        // que no hubiera nada que encontrar.
        $this->assertSame($otra, (int) DB::selectOne(
            'SELECT horario_version_id FROM years WHERE id = ?', [$this->idDelAnio($anterior - 1)]
        )->horario_version_id);
    }
}
