<?php

namespace Tests\Contrato;

use App\Http\Controllers\Perfiles\CalendarioController;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * El calendario que se lee es **el del año en curso**.
 *
 * Decidido por Joseth el 20 sep 2026 sabiendo lo que apaga. Lo medido: la tabla
 * tenía **593 filas visibles de 2019 a 2025 y ninguna de 2026**, y las seis
 * consultas que la leían **no filtraban ni por año ni por fecha** — 128 KB en
 * `ChangesAsked/to-me` y 215,5 en `calendario/this-year`, en cada apertura.
 *
 * ## Los cuatro fallos que esta clase existe para cazar
 *
 * **1. Que el filtro se caiga y vuelva el calendario entero.** Es el que se
 * deshace solo: nadie lo reporta, porque la pantalla se ve **mejor** con más
 * eventos. Lo único que lo delata es la factura del hosting.
 *
 * **2. Que alguien lo reescriba como `YEAR(start) = ?`.** Parece lo mismo y no
 * lo es: un evento que empieza el 20 de diciembre y acaba en enero
 * **desaparecería del año en el que termina**. El caso del cruce lo fija, y es
 * el que hay que ver en rojo antes de fiarse del resto.
 *
 * **3. Que `calendario/this-year` pierda columnas.** Devuelve **diecisiete** a
 * propósito —las que tenía la tabla antes de la migración del calendario, para
 * que su respuesta no se mueva ni una clave— mientras `to-me` manda diez. Que
 * las dos compartan el filtro **no puede** acabar igualándoles la forma.
 *
 * **4. Que el filtro nuevo se lleve por delante el viejo.** `solo_profes` es de
 * quien es: una familia no ve los eventos internos del colegio, y eso ya
 * funcionaba antes de esto.
 */
class ElCalendarioEsDelAnioTest extends CasoDeContrato
{
    /**
     * Un docente, su token y **el año que ese token resuelve de verdad**.
     *
     * **El año NO se lee de `users.periodo_id`, y ésa es la trampa de este
     * fichero.** Leído de la fila, el primer profesor del seed sale en **2021**;
     * pedido al contexto después de entrar, sale **2025**, porque
     * `login/credentials` mueve a la persona al periodo actual. Escribir el test
     * contra el año de la fila lo pone rojo con el código bien — me pasó, y el
     * síntoma era «el filtro no filtra» cuando lo que fallaba era el número con
     * el que yo comparaba.
     *
     * Se pide a `POST /api/login`, que es de donde lo saca el propio
     * controlador: **la única fuente que no puede discrepar de la que usa el
     * código bajo prueba**.
     */
    private function docente(): object
    {
        $fila = DB::selectOne('SELECT u.* FROM users u
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ni un profesor activo.');

        $token = $this->tokenDe($fila->username);

        $contexto = $this->postJson('/api/login', [], ['Authorization' => 'Bearer '.$token]);
        $contexto->assertStatus(200);

        $fila->token = $token;
        $fila->year = (int) $contexto->json('year');

        $this->assertGreaterThan(2000, $fila->year, 'El contexto no trajo el año lectivo.');

        return $fila;
    }

    /** Un evento suelto, con las fechas que le pidan. */
    private function evento(string $titulo, string $start, ?string $end = null, int $soloProfes = 0): int
    {
        return DB::table('calendario')->insertGetId([
            'title' => $titulo,
            'start' => $start,
            'end' => $end,
            'allDay' => 1,
            'solo_profes' => $soloProfes,
            'created_at' => now(),
        ]);
    }

    /** @return array<int, int> los ids que trae la respuesta */
    private function idsDe(array $eventos): array
    {
        return array_map(static fn ($e) => (int) $e['id'], $eventos);
    }

    #[Test]
    public function test_el_muro_no_trae_el_calendario_de_hace_siete_anios(): void
    {
        $d = $this->docente();

        $viejo = $this->evento('Semana cultural de 2019', '2019-03-21 05:00:00');
        $deEsteAnio = $this->evento('Algo de este año', $d->year.'-05-10 05:00:00');

        $r = $this->withToken($d->token)->getJson('/api/ChangesAsked/to-me');
        $r->assertStatus(200);

        $ids = $this->idsDe($r->json('eventos'));

        $this->assertContains($deEsteAnio, $ids, 'Se llevó por delante el año en curso, que es lo único que había que dejar.');
        $this->assertNotContains($viejo, $ids,
            'Volvió el calendario entero. Son 128 KB en cada apertura de la app y del panel, '.
            'y nadie lo reporta porque la pantalla se ve MEJOR con más eventos.');
    }

    /**
     * **El caso que separa el solape de un `YEAR(start)`.**
     *
     * Un evento que empieza en diciembre y termina en enero pertenece a los dos
     * años. Con `YEAR(start) = ?` sale en el primero y **desaparece del
     * segundo**, que es justo el año en el que la gente lo está viviendo.
     */
    #[Test]
    public function test_un_evento_que_cruza_el_fin_de_anio_sale_en_los_dos(): void
    {
        $d = $this->docente();

        $cruza = $this->evento(
            'Vacaciones que cruzan el año',
            ($d->year - 1).'-12-20 05:00:00',
            $d->year.'-01-15 05:00:00'
        );

        $r = $this->withToken($d->token)->getJson('/api/ChangesAsked/to-me');

        $this->assertContains($cruza, $this->idsDe($r->json('eventos')),
            'Desapareció un evento que TERMINA en el año en curso. El filtro está mirando `start` '.
            'a secas en vez del solape del rango.');
    }

    #[Test]
    public function test_this_year_filtra_igual_y_conserva_sus_diecisiete_columnas(): void
    {
        $d = $this->docente();

        $viejo = $this->evento('Otro de 2019', '2019-04-02 05:00:00');
        $deEsteAnio = $this->evento('Otro de este año', $d->year.'-06-01 05:00:00');

        $r = $this->withToken($d->token)->putJson('/api/calendario/this-year', []);
        $r->assertStatus(200);

        $ids = $this->idsDe($r->json());
        $this->assertContains($deEsteAnio, $ids);
        $this->assertNotContains($viejo, $ids,
            'El botón «Actualizar» del panel sigue trayendo el calendario entero: 215,5 KB.');

        $esperadas = array_map('trim', explode(',', CalendarioController::COLUMNAS));
        $llegan = array_keys($r->json()[0]);

        $this->assertSame($esperadas, $llegan,
            'La forma de `this-year` se movió. Sus diecisiete columnas son las que tenía la tabla '.
            'antes de la migración del calendario, elegidas para que esta respuesta no cambiara ni '.
            'una clave: compartir el filtro con `to-me` no puede igualarles también las columnas.');
    }

    /**
     * El filtro viejo sigue en pie: `solo_profes` es de quien es.
     *
     * Va aquí y no en su propia clase porque **es lo que el cambio de hoy
     * podía romper sin querer**: las dos condiciones viven ahora en la misma
     * consulta.
     */
    #[Test]
    public function test_una_familia_sigue_sin_ver_los_eventos_internos(): void
    {
        /*
         * **Un ALUMNO y no un acudiente, y no es indiferente.** Los 76 acudientes
         * del seed no tienen acudidos en el año en curso, así que su `POST /login`
         * contesta **400** —«no da contexto»— y el caso no llegaría ni a mirar el
         * calendario. Es el mismo hueco del seed que obligó a fabricar el vínculo
         * en `MuroParaLaAppTest`.
         *
         * Para lo que aquí se mide da igual cuál de los dos: la regla es «los
         * eventos internos no son para las familias», y un alumno está del mismo
         * lado de esa línea. Lo que no vale es un docente.
         */
        $fila = $this->usuarioDeTipo('Alumno');

        $token = $this->tokenDe($fila->username);
        $contexto = $this->postJson('/api/login', [], ['Authorization' => 'Bearer '.$token]);
        $contexto->assertStatus(200);
        $anio = (int) $contexto->json('year');

        $interno = $this->evento('Reunión de profesores', $anio.'-07-01 05:00:00', null, soloProfes: 1);
        $publico = $this->evento('Izada de bandera', $anio.'-07-02 05:00:00');

        $r = $this->withToken($token)->getJson('/api/ChangesAsked/to-me');
        $r->assertStatus(200);

        $ids = $this->idsDe($r->json('eventos'));

        $this->assertContains($publico, $ids,
            'No llegó ni el evento público: el filtro del año se llevó también lo que sí le toca.');
        $this->assertNotContains($interno, $ids,
            'Una familia está viendo los eventos internos del colegio.');
    }
}
