<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `GET horario/versiones/{id}/proyecto` — **la sexta ruta**, y la única de la familia
 * que devuelve un fichero (§10.2 decisión 3 del
 * [23](../../docs/migracion/23-horarios.md)).
 *
 * ## Qué existe esto para cazar, y los tres fallan en silencio
 *
 * **1. Que se cuele por el permiso de mirar.** Esta ruta es la tercera línea de una
 * escalera que el módulo trazó en tres pasos —*«listar no es descargar»*, *«mirar no es
 * llevarse»*, y ésta—, y **es la única que saca el fichero de la casa**: dentro van las
 * disponibilidades declaradas de los 47 docentes. El guard `auth.personal` la deja
 * abierta a los 53 docentes del colegio; lo que la cierra es `puedePublicarHorario`
 * **dentro del método**. Si ese `exigir` desapareciera, la ruta seguiría contestando
 * 200 a todo el mundo y **ningún test de guard se pondría rojo**, porque el guard es el
 * correcto: lo que falta es el criterio fino.
 *
 * **2. Que el fichero salga escapado.** El cuerpo tiene que ser **byte a byte** el que
 * se subió. Meterlo en un JSON duplica cada tabulador y cada comilla y multiplica el
 * tamaño por **1,41 vacío y 1,795 lleno** (§10.2) — y lo peor no es el tamaño: un
 * `.myvch` escapado **se descarga sin error y no lo abre el escritorio**. El viaje de
 * ida y vuelta es lo único que distingue las dos cosas.
 *
 * **3. Que el nombre del fichero lo elija quien sube.** `horario_versiones.nombre` lo
 * escribe el cliente y en esta base hay uno que se llama, literal,
 * *«prueba `servidor` 2026-09-02 · punta a punta»*. Ponerlo en `Content-Disposition`
 * sería dejar que el que sube elija una cabecera del que descarga.
 */
class HorarioProyectoTest extends CasoDeContrato
{
    /** El año del token con el que se prueba. */
    private function anioDelSujeto(): int
    {
        return (int) DB::selectOne(
            'SELECT p.year_id FROM users u JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?',
            [$this->usuarioLlanoDelPersonal()->id]
        )->year_id;
    }

    /**
     * Un proyecto con **tabuladores, comillas y acentos dentro a propósito**.
     *
     * Los tres son lo que el escapado de JSON toca, así que un blob «limpio» dejaría
     * pasar exactamente el fallo que este fichero viene a cazar.
     */
    private function proyectoCrudo(): string
    {
        return "{\n\t\"anio\": 2025,\n\t\"colegio\": \"COLEGIO ADVENTISTA SIMÓN BOLIVAR\",\n".
               "\t\"nota\": \"comillas \\\"dentro\\\" y una barra \\\\ suelta\",\n\t\"docentes\": []\n}\n";
    }

    private function versionEn(int $yearId, string $nombre = 'Para descargar'): int
    {
        DB::insert(
            'INSERT INTO horario_versiones (year_id, nombre, subida_por, proyecto, comprobaciones, created_at, updated_at)
             VALUES (?, ?, NULL, ?, NULL, ?, ?)',
            [$yearId, $nombre, $this->proyectoCrudo(), '2026-09-05 10:00:00', '2026-09-05 10:00:00']
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /** Descarga como superusuario, que es quien puede hoy: el rol de coordinación está vacío. */
    private function descargar(int $versionId)
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto tiene que ser superusuario: si no, el 403 de `puedePublicarHorario` '.
            'se leería como un fallo de la ruta.');

        return $this->getJson("/api/horario/versiones/{$versionId}/proyecto", [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ]);
    }

    #[Test]
    public function devuelve_el_proyecto_byt_e_a_byt_e_como_se_subio(): void
    {
        $version = $this->versionEn($this->anioDelSujeto());

        $r = $this->descargar($version)->assertStatus(200);

        $guardado = (string) DB::selectOne(
            'SELECT proyecto FROM horario_versiones WHERE id = ?', [$version]
        )->proyecto;

        // `getContent()` y no `assertJson`: lo que sale NO es JSON de esta API, es el
        // fichero del escritorio. Compararlo como JSON pasaría con el blob escapado.
        $this->assertSame($guardado, $r->getContent(),
            'El cuerpo tiene que ser el fichero tal cual. Si difiere, o se escapó al '.
            'meterlo en un sobre o se recodificó por el camino: las dos se descargan '.
            'sin error y ninguna la abre el escritorio.');

        $this->assertSame($this->proyectoCrudo(), $r->getContent(),
            'Y el control por el otro extremo: idéntico a lo que se insertó, con sus '.
            'tabuladores, sus comillas escapadas y sus acentos.');
    }

    #[Test]
    public function las_cabeceras_dicen_fichero_y_no_json(): void
    {
        $version = $this->versionEn($this->anioDelSujeto());

        $r = $this->descargar($version)->assertStatus(200);

        $this->assertSame('application/octet-stream', $r->headers->get('Content-Type'),
            'Con `application/json` el navegador lo enseña en pantalla en vez de guardarlo.');

        $this->assertSame((string) strlen($this->proyectoCrudo()), $r->headers->get('Content-Length'),
            '`Content-Length` va en BYTES: con `mb_strlen` los acentos lo dejarían corto '.
            'y la descarga se cortaría por donde nadie mira.');
    }

    #[Test]
    public function el_nombre_del_fichero_n_o_lo_elige_quien_subio_la_version(): void
    {
        $anio = $this->anioDelSujeto();
        $version = $this->versionEn($anio, 'prueba `servidor` · "comillas" y / barras');

        $r = $this->descargar($version)->assertStatus(200);
        $disposicion = (string) $r->headers->get('Content-Disposition');

        $this->assertStringNotContainsString('comillas', $disposicion,
            'El nombre de la versión lo escribe el cliente. Si viaja a la cabecera, el '.
            'que sube elige la cabecera del que descarga.');

        $anioLectivo = (int) DB::selectOne('SELECT year FROM years WHERE id = ?', [$anio])->year;

        $this->assertStringContainsString("horario-{$anioLectivo}-v{$version}.myvch", $disposicion,
            'Se construye aquí, con el año lectivo y el id: los dos son del servidor.');
    }

    #[Test]
    public function el_personal_llano_no_puede_descargarlo(): void
    {
        $version = $this->versionEn($this->anioDelSujeto());

        // Pasa el guard `auth.personal` —no es alumno ni acudiente— y aun así no entra.
        // Ése es todo el punto de la ruta: el guard no la cierra, el criterio sí.
        $this->getJson("/api/horario/versiones/{$version}/proyecto", [
            'Authorization' => 'Bearer '.$this->tokenDelPersonalLlano(),
        ])->assertStatus(403);
    }

    #[Test]
    public function un_alumno_no_pasa_ni_del_guard(): void
    {
        $version = $this->versionEn($this->anioDelSujeto());
        $alumno = $this->usuarioDeTipo('Alumno');

        $this->getJson("/api/horario/versiones/{$version}/proyecto", [
            'Authorization' => 'Bearer '.$this->tokenDe($alumno->username),
        ])->assertStatus(403);
    }

    #[Test]
    public function sin_token_no_se_descarga(): void
    {
        $version = $this->versionEn($this->anioDelSujeto());

        $this->getJson("/api/horario/versiones/{$version}/proyecto")->assertStatus(401);
    }

    #[Test]
    public function una_version_de_otro_anio_da_404_y_no_403(): void
    {
        $anio = $this->anioDelSujeto();
        $otro = (int) DB::selectOne('SELECT id FROM years WHERE id <> ? ORDER BY id LIMIT 1', [$anio])->id;
        $ajena = $this->versionEn($otro, 'La de otro año');

        $this->descargar($ajena)->assertStatus(404);

        // El control: la fila existe, así que el 404 es del año y no de que no hubiera
        // nada que encontrar — que es la diferencia entre comprobar el id y no hacerlo.
        $this->assertNotNull(DB::selectOne('SELECT id FROM horario_versiones WHERE id = ?', [$ajena]));
    }

    #[Test]
    public function una_version_que_no_existe_da_404(): void
    {
        $this->descargar(99999999)->assertStatus(404);
    }
}
