<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `PUT horario/docentes/{profesor_id}/tono` — **la única escritura de
 * `profesores.tono` que existe** (§9.bis del [23](../../docs/migracion/23-horarios.md)).
 *
 * ## Qué existe esto para cazar, y ninguno de los tres da error solo
 *
 * **1. Un color que no vale, guardado.** El cliente
 * (`comunes/tono-docente/tono-docente.ts:353`) acepta `#rgb` y `#rrggbb` y **rechaza
 * los nombres de CSS y `rgb(...)`**; cuando rechaza, `marcaDeDocente` **se cae al
 * color automático**. O sea que un `rebeccapurple` guardado sin comprobar **se da por
 * guardado, no se pinta nunca y nadie se entera**: el filtro del cliente sabe *no
 * pintar*, no sabe *avisar*. El 422 de aquí es lo único que convierte «no se ve» en
 * «no se pudo guardar», y por eso el caso de abajo es una tabla y no un ejemplo.
 *
 * **2. Cuatro formas de escribir el mismo color.** `#0AF`, `0af`, `#00aaff` y
 * `00AAFF` son el mismo color, y si la base guardara las cuatro, **dos docentes del
 * mismo color se leerían como distintos** en cualquier comparación de cadenas — que es
 * exactamente lo que el reparto de colores existe para evitar. Se normaliza al
 * escribir, y el viaje de ida y vuelta es lo que lo ata.
 *
 * **3. La clave ausente tomada por un borrado.** Un cuerpo sin `tono` es un cuerpo mal
 * formado. Si valiera por «borra», **cualquier petición a medias apagaría un color sin
 * que nadie lo pidiera**, y el síntoma sería una rejilla que se despinta sola.
 *
 * ## Y el permiso, que es de lo que va la mitad de esta ruta
 *
 * `puedePublicarHorario` —superusuario **o** `Coord académico`—, el mismo que marcar la
 * oficial y **no** el de la ficha del docente. Joseth decidió el 4 sep 2026 que el color
 * lo elijan **también los coordinadores**, y `ProfesoresController::putUpdate` exige
 * `esSuperusuario`: por ahí lo elegirían once personas y ningún coordinador.
 */
class HorarioTonoTest extends CasoDeContrato
{
    /**
     * Colores que valen, con lo que tiene que quedar guardado.
     *
     * Las cuatro escrituras del mismo color van juntas a propósito: es el caso que
     * demuestra que se normaliza, y separarlas dejaría pasar una normalización que
     * sólo funcione con la forma larga.
     */
    public static function coloresQueValen(): array
    {
        return [
            'seis con almohadilla' => ['#00aaff', '#00aaff'],
            'seis sin almohadilla' => ['00aaff',  '#00aaff'],
            'seis en mayúsculas' => ['#00AAFF', '#00aaff'],
            'tres con almohadilla' => ['#0af',    '#00aaff'],
            'tres sin almohadilla' => ['0af',     '#00aaff'],
            'tres en mayúsculas' => ['#0AF',    '#00aaff'],
            'con espacios alrededor' => ['  #0af ', '#00aaff'],
            'negro, que no es vacío' => ['#000000', '#000000'],
            'blanco' => ['#FFF',    '#ffffff'],
        ];
    }

    /**
     * Lo que tiene que rebotar con 422.
     *
     * `rebeccapurple` va primero porque es el caso real que midió el front: un nombre
     * de CSS **perfectamente válido en un navegador** del que no se puede sacar la
     * luminancia sin uno delante.
     */
    public static function coloresQueNoValen(): array
    {
        return [
            'nombre de CSS' => ['rebeccapurple'],
            'nombre de CSS corto' => ['red'],
            'rgb()' => ['rgb(0, 170, 255)'],
            'hsl()' => ['hsl(200, 100%, 50%)'],
            'cuatro dígitos' => ['#00af'],
            'siete dígitos' => ['#00aaff0'],
            'fuera del hexadecimal' => ['#00ggff'],
            'dos almohadillas' => ['##00aaff'],
            'con punto y coma' => ['#00aaff;'],
            'un número' => [123456],
            'una lista' => [['#00aaff']],
        ];
    }

    #[Test]
    #[DataProvider('coloresQueValen')]
    public function test_un_color_valido_se_guarda_normalizado(string $mandado, string $guardado): void
    {
        $profesor = $this->unDocente();

        $r = $this->cambiarTono($profesor->id, $mandado);

        $r->assertStatus(200);
        $this->assertSame($guardado, $r->json('tono'),
            "Mandando '{$mandado}' la respuesta tenía que devolver '{$guardado}'.");

        /*
         * **El viaje de ida y vuelta, no el 200.** Lo que se comprueba es lo que quedó
         * EN LA TABLA: una respuesta que devuelva el color normalizado y guarde el crudo
         * pasaría el `assertSame` de arriba y rompería la comparación de colores del
         * cliente, que es justo lo que esto existe para impedir.
         */
        $this->assertSame($guardado, $this->tonoEnLaBase($profesor->id),
            'La respuesta dijo una cosa y la tabla guardó otra.');
    }

    #[Test]
    #[DataProvider('coloresQueNoValen')]
    public function test_un_color_que_no_vale_es_422_y_no_escribe(mixed $mandado): void
    {
        $profesor = $this->unDocente();

        DB::update('UPDATE profesores SET tono = ? WHERE id = ?', ['#123456', $profesor->id]);

        $r = $this->cambiarTono($profesor->id, $mandado);

        $r->assertStatus(422);

        /*
         * **El 422 no basta: hay que comprobar que NO escribió.** Una implementación que
         * guardara y luego validara daría 422 con el color roto ya dentro, y el síntoma
         * sería un docente que se despinta cuando alguien se equivoca al teclear.
         */
        $this->assertSame('#123456', $this->tonoEnLaBase($profesor->id),
            'Rechazó con 422 pero se llevó por delante el color que había.');
    }

    #[Test]
    public function test_el_422_dice_que_recibio_y_por_que(): void
    {
        $profesor = $this->unDocente();

        $r = $this->cambiarTono($profesor->id, 'rebeccapurple');

        $r->assertStatus(422);
        $this->assertSame('tono', $r->json('campo'));
        $this->assertSame('rebeccapurple', $r->json('recibido'),
            'Sin el valor recibido, la pantalla sólo puede decir «no vale» y no «rebeccapurple no vale».');
        $this->assertNotEmpty($r->json('motivo'),
            'Un error sin motivo obliga al cliente a leer el `message` con expresiones regulares.');
    }

    #[Test]
    public function test_el_nulo_borra_el_color(): void
    {
        $profesor = $this->unDocente();

        $this->cambiarTono($profesor->id, '#00aaff')->assertStatus(200);
        $this->assertSame('#00aaff', $this->tonoEnLaBase($profesor->id));

        $r = $this->cambiarTono($profesor->id, null);

        $r->assertStatus(200);
        $this->assertNull($r->json('tono'));
        $this->assertNull($this->tonoEnLaBase($profesor->id),
            'El nulo tiene que devolver al docente a su color automático: `tono` nace nulo '.
            'en los diecisiete, así que sin esto un colegio no tiene marcha atrás.');
    }

    #[Test]
    public function test_la_cadena_vacia_tambien_borra(): void
    {
        $profesor = $this->unDocente();

        $this->cambiarTono($profesor->id, '#00aaff')->assertStatus(200);

        $this->cambiarTono($profesor->id, '')->assertStatus(200);

        $this->assertNull($this->tonoEnLaBase($profesor->id),
            'Un `<input>` vaciado a mano manda cadena vacía, y exigirle al cliente que '.
            'distinga `""` de `null` es pedirle que acierte en algo que su formulario no distingue.');
    }

    #[Test]
    public function test_la_clave_ausente_n_o_es_un_borrado(): void
    {
        $profesor = $this->unDocente();

        DB::update('UPDATE profesores SET tono = ? WHERE id = ?', ['#00aaff', $profesor->id]);

        $usuario = $this->superusuario();

        $r = $this->putJson("/api/horario/docentes/{$profesor->id}/tono", [], [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ]);

        $r->assertStatus(422);
        $this->assertSame('ausente', $r->json('motivo'));
        $this->assertSame('#00aaff', $this->tonoEnLaBase($profesor->id),
            'Un cuerpo sin `tono` apagó un color. Si la clave ausente valiera por «borra», '.
            'cualquier petición a medias despintaría la rejilla sin que nadie lo pidiera.');
    }

    #[Test]
    public function test_un_docente_que_no_existe_es_404(): void
    {
        $usuario = $this->superusuario();

        $this->putJson('/api/horario/docentes/99999999/tono', ['tono' => '#00aaff'], [
            'Authorization' => 'Bearer '.$this->tokenDe($usuario->username),
        ])->assertStatus(404);
    }

    #[Test]
    public function test_un_docente_de_la_papelera_es_404(): void
    {
        $profesor = $this->unDocente();

        DB::update('UPDATE profesores SET deleted_at = NOW() WHERE id = ?', [$profesor->id]);

        $this->cambiarTono($profesor->id, '#00aaff')->assertStatus(404);
    }

    #[Test]
    public function test_un_docente_sin_asignaturas_este_anio_tambien_tiene_color(): void
    {
        /*
         * **El docente se comprueba contra `profesores`, no contra los que dan clase.**
         * Atarlo al año convertiría el color en un 404 que cambia solo en enero, y
         * repartir colores por adelantado es justo lo que hace un coordinador antes de
         * empezar el año.
         */
        $huerfano = DB::select(
            'SELECT p.id FROM profesores p
              WHERE p.deleted_at IS NULL
                AND p.id NOT IN (SELECT a.profesor_id FROM asignaturas a WHERE a.profesor_id IS NOT NULL)
              LIMIT 1'
        );

        if ($huerfano === []) {
            $this->markTestSkipped('El seed no tiene ningún docente sin asignaturas; sin él este caso no se puede montar.');
        }

        $this->cambiarTono((int) $huerfano[0]->id, '#00aaff')->assertStatus(200);
    }

    #[Test]
    public function test_un_docente_llano_no_puede_cambiar_colores(): void
    {
        $profesor = $this->unDocente();

        /*
         * **`auth.personal` deja pasar a cualquier docente**: cierra la puerta a alumnos y
         * acudientes, no a un profesor. El criterio que lo para es `puedePublicarHorario`,
         * y va DENTRO del método — igual que en `putOficial`. Sin este caso, quitarlo del
         * controlador no pondría nada en rojo.
         */
        $r = $this->putJson("/api/horario/docentes/{$profesor->id}/tono", ['tono' => '#00aaff'], [
            'Authorization' => 'Bearer '.$this->tokenDelPersonalLlano(),
        ]);

        $r->assertStatus(403);
        $this->assertNull($this->tonoEnLaBase($profesor->id),
            'Contestó 403 y escribió igual: el guard frena la respuesta pero no la escritura.');
    }

    /** Un docente cualquiera del seed, con su color a nulo para partir de lo que hay en producción. */
    private function unDocente(): object
    {
        $fila = DB::select('SELECT p.id FROM profesores p WHERE p.deleted_at IS NULL ORDER BY p.id LIMIT 1');

        $this->assertNotSame([], $fila, 'El seed no tiene docentes y sin ellos este fichero no mide nada.');

        DB::update('UPDATE profesores SET tono = NULL WHERE id = ?', [(int) $fila[0]->id]);

        return (object) ['id' => (int) $fila[0]->id];
    }

    private function superusuario(): object
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este test tiene que ser superusuario: sin él, el 403 del criterio '.
            'se leería como un fallo de la validación.');

        return $usuario;
    }

    private function cambiarTono(int $profesorId, mixed $tono)
    {
        return $this->putJson("/api/horario/docentes/{$profesorId}/tono", ['tono' => $tono], [
            'Authorization' => 'Bearer '.$this->tokenDe($this->superusuario()->username),
        ]);
    }

    private function tonoEnLaBase(int $profesorId): ?string
    {
        return DB::select('SELECT tono FROM profesores WHERE id = ?', [$profesorId])[0]->tono;
    }
}
