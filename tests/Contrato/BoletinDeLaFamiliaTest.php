<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * §140 — Una familia recibía 500 en vez del boletín, en dos de las tres maquetas.
 *
 * Medido el 23 ago 2026 con un acudiente pidiendo el boletín **de su acudido**:
 *
 *     PUT api/boletines/detailed-notas/{grupo}    ->  200
 *     PUT api/boletines2/detailed-notas/{grupo}   ->  500  Undefined property:
 *     PUT api/boletines3/detailed-notas/{grupo}   ->  500    stdClass::$year_pasado_en_bol
 *
 * Es el único hallazgo de la noche que **le está pasando a un colegio ahora
 * mismo**: la familia entra, pide el boletín y recibe un error del servidor.
 *
 * ## La causa, que no está en los boletines
 *
 * `year_pasado_en_bol` es una columna de `years` —dice si el boletín arrastra el
 * año pasado— y `ContextoDeUsuario` la selecciona en **tres de sus cuatro
 * ramas**: `Profesor`, `Alumno` y `Usuario`. En `Acudiente` no.
 *
 * O sea que **no es una configuración del tipo de usuario, es del año**, y estaba
 * puesta por tipo de usuario. Un acudiente mira el boletín del mismo año que su
 * acudido y necesita exactamente la misma configuración.
 *
 * ## Por qué la maqueta 1 sí funcionaba, que es lo que lo mantuvo escondido
 *
 * Las tres hacen lo mismo y una lo hace con red:
 *
 *     BoletinesController.php:224    if (isset($this->user->year_pasado_en_bol)) {
 *     Boletines2Controller.php:155   if ($this->user->year_pasado_en_bol) {
 *     Boletines3Controller.php:157   if ($this->user->year_pasado_en_bol) {
 *
 * El `isset` de la primera **tapaba el agujero del contexto** en la única maqueta
 * que alguien probaba con una familia. No es que la maqueta 1 esté bien: es que
 * se defiende de un dato que no debería faltar.
 *
 * ## Y por qué no lo cazó ningún test, que es lo que hay que arreglar de verdad
 *
 * `AutorizacionTest` prueba al **alumno** contra las tres maquetas —tiene un
 * `#[DataProvider]`— y al **acudiente** contra `boletines` a secas. La cobertura
 * decía que las tres estaban comprobadas, y lo estaban: **para el otro tipo de
 * usuario**. Es la §115 del lote J en su forma más cara:
 *
 * > Un test verde no dice que la ruta esté bien: dice que alguien miró otra cosa.
 *
 * Por eso este test lleva `#[DataProvider]` con las tres maquetas desde el
 * principio: el arreglo de una línea sin esto se vuelve a perder a la primera
 * maqueta que alguien añada.
 */
class BoletinDeLaFamiliaTest extends CasoDeContrato
{
    /** @return array<string, array{string}> */
    public static function lasTresMaquetas(): array
    {
        return ['boletines' => ['boletines'], 'boletines2' => ['boletines2'], 'boletines3' => ['boletines3']];
    }

    /**
     * Un acudiente recibe el boletín de su acudido en las tres maquetas.
     *
     * Se afirma `200` **y** que el cuerpo trae el grupo pedido, no sólo el código:
     * un 200 con el cuerpo vacío sería exactamente lo que este test tendría que
     * cazar, y es lo que dejaría un `try/catch` puesto para tapar el 500.
     */
    #[DataProvider('lasTresMaquetas')]
    public function test_una_familia_recibe_el_boletin_de_su_acudido(string $familia): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        // El paz y salvo decide desde el 18 ago 2026 (05, decisión de Joseth), y
        // sin esto el 403 de la deuda taparía el 500 que se está midiendo.
        DB::update('UPDATE alumnos SET pazysalvo = 1 WHERE id = ?', [$suyo->alumno_id]);

        $r = $this->withToken($token)->putJson("/api/{$familia}/detailed-notas/{$suyo->grupo_id}",
            ['requested_alumnos' => [[
                'alumno_id' => $suyo->alumno_id,
                'matricula_id' => $suyo->matricula_id,
            ]]]);

        $this->assertSame(200, $r->status(),
            "`{$familia}/detailed-notas` no le contesta a una familia por el boletín de su acudido. "
            .'Si vuelve a ser 500 con «Undefined property: stdClass::$year_pasado_en_bol», es que la '
            .'rama `Acudiente` de ContextoDeUsuario perdió otra vez esa columna (§140).');

        $cuerpo = $r->json();

        $this->assertNotEmpty($cuerpo,
            "`{$familia}/detailed-notas` contestó 200 con el cuerpo vacío. Un 200 hueco es peor que "
            .'el 500: la familia ve una pantalla en blanco y nadie se entera.');

        $this->assertContains($suyo->grupo_id, array_column($cuerpo, 'grupo_id'),
            "`{$familia}/detailed-notas` contestó 200 pero sin el grupo que se pidió.");
    }

    /**
     * Y la causa, afirmada donde está: el contexto de un acudiente trae la columna.
     *
     * Sin este test, el de arriba se puede volver a poner verde tapando el
     * síntoma con un `isset` en las dos maquetas —que es lo que hace la primera—
     * y el contexto seguiría incompleto para el siguiente que lo lea.
     *
     * Se comprueba **por HTTP y no llamando al servicio**, porque
     * `aplicacion-descargas/detailed` devuelve el `$user` del token entero
     * (05 §12) y es lo que de verdad le llega a un cliente.
     */
    public function test_el_contexto_de_un_acudiente_trae_la_configuracion_del_ano(): void
    {
        [$token] = $this->acudienteConAcudido();

        $user = $this->withToken($token)->putJson('/api/aplicacion-descargas/detailed')
            ->assertStatus(200)->json();

        $this->assertArrayHasKey('year_pasado_en_bol', $user,
            'La rama `Acudiente` de ContextoDeUsuario volvió a quedarse sin `year_pasado_en_bol`. '
            .'Es una columna de `years`, no del tipo de usuario: la tienen las otras tres ramas.');
    }

    /**
     * El mismo acudiente y su acudido, con parentesco y matrícula.
     *
     * Copiado de `AutorizacionTest` a propósito y no extraído a la clase base:
     * ese fichero lo están tocando varias sesiones esta noche, y mover un método
     * privado suyo a `CasoDeContrato` es la clase de cambio que se lleva por
     * delante el trabajo de otro al fundir.
     *
     * @return array{0: string, 1: object}
     */
    private function acudienteConAcudido(): array
    {
        $fila = DB::selectOne('SELECT u.username, ac.id acudiente_id, p.alumno_id, m.grupo_id, m.id matricula_id
            FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = u.periodo_id
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún acudiente con parentesco y matrícula.');

        return [$this->tokenDe($fila->username), $fila];
    }
}
