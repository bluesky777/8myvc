<?php

namespace Tests\Unit;

use App\Support\ParecidoDeNombres;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El emparejador de nombres de la F6 («notas sin internet», fase 3).
 *
 * ## Qué se comprueba aquí, y por qué no basta con el test de contrato
 *
 * El de contrato baja un libro de verdad y comprueba **el camino entero**: que la
 * tarjeta sale, que se resuelve y que la nota acaba en la fila correcta. Lo que no
 * puede comprobar es **la propiedad que hace que la tarjeta sirva**, porque
 * depende de qué nombres tenga el seed. Y son tres:
 *
 * 1. **La tilde y el orden no cuentan.** La planilla imprime `APELLIDOS, Nombres`
 *    y la gente escribe `Nombres Apellidos`, sin tildes y con una errata. Si esto
 *    fallara, la pantalla no daría error: diría «no hay nadie con ese nombre» y
 *    mandaría a secretaría a matricular a alguien que ya está matriculado.
 * 2. **Que una palabra suelta no identifique a nadie.** «Maria» contra
 *    «MARÍA FERNANDA GÓMEZ RESTREPO» es 1 en una dirección y 0,25 en la otra: si
 *    sólo se mirara la primera, en un grupo de cuarenta saldrían ocho Marías.
 * 3. **Que se devuelvan pocos y sólo los buenos.** Cinco nombres que no se parecen
 *    a nada son peor que ninguno: convierten una pregunta en una lotería.
 *
 * No hereda de `Tests\TestCase`: aquí no hay base de datos ni petición. La medida
 * del umbral —10.425 emparejamientos contra `caz_zaragoza`— está en el docblock de
 * la clase y en `docs/migracion/50-el-ensayo-y-la-escritura-de-la-planilla.md`.
 */
class ParecidoDeNombresTest extends TestCase
{
    // ── La tilde y el orden ──────────────────────────────────────────────────

    #[Test]
    public function el_caso_del_plan_la_tilde_el_orden_cambiado_y_un_apellido_de_mas(): void
    {
        // Es el ejemplo literal del §6.4: el docente escribió «Jose Luis Cardenaz» y
        // la planilla dice «CÁRDENAS PEÑA, José Luis». Hay tres cosas a la vez —tilde
        // que falta, orden cambiado y un apellido que no escribió— y las tres juntas
        // son lo normal, no el caso raro.
        $parecido = ParecidoDeNombres::entre('Jose Luis Cardenaz', 'CÁRDENAS PEÑA, José Luis');

        $this->assertGreaterThanOrEqual(ParecidoDeNombres::UMBRAL, $parecido,
            'Si este caso no pasa el umbral, la pantalla dice «no hay nadie con ese nombre» '
            .'y manda a secretaría a matricular a alguien que ya está matriculado.');
    }

    #[Test]
    public function la_tilde_sola_no_cambia_nada(): void
    {
        // Es la trampa del doc 33 al revés: la base compara con `utf8mb4_unicode_ci`
        // y **ignora la tilde**; PHP no. Emparejar en PHP sin normalizar haría que el
        // servidor contestara distinto que su propia base a la misma pregunta.
        $this->assertSame(1.0, ParecidoDeNombres::entre('MUNOZ RIOS, Jose', 'MUÑOZ RÍOS, José'));
        $this->assertSame('munoz rios jose', ParecidoDeNombres::normalizar('MUÑOZ RÍOS, José'));
    }

    #[Test]
    public function el_orden_cambiado_da_igual(): void
    {
        $this->assertGreaterThan(0.8,
            ParecidoDeNombres::entre('Camila Restrepo Velez', 'RESTREPO VÉLEZ, Camila'));
    }

    #[Test]
    public function la_cadena_entera_sola_no_veria_el_caso_del_plan(): void
    {
        // La prueba de que hacían falta **las dos medidas** y no sólo la obvia:
        // `similar_text` sobre las cadenas enteras se queda en 0,39 con el par del
        // §6.4 y no llegaría ni al umbral. La planilla imprime el apellido delante y
        // el docente escribe el nombre delante, así que una medida que sólo mire la
        // cadena entera **lee el formato de la hoja en vez de la persona**.
        $delDocente = ParecidoDeNombres::normalizar('Jose Luis Cardenaz');
        $deLaPlanilla = ParecidoDeNombres::normalizar('CÁRDENAS PEÑA, José Luis');

        $suelta = similar_text($delDocente, $deLaPlanilla) / strlen($deLaPlanilla);

        $this->assertLessThan(ParecidoDeNombres::UMBRAL, $suelta);
        $this->assertGreaterThanOrEqual(ParecidoDeNombres::UMBRAL,
            ParecidoDeNombres::entre('Jose Luis Cardenaz', 'CÁRDENAS PEÑA, José Luis'));
    }

    #[Test]
    public function la_errata_de_una_letra_sigue_casando(): void
    {
        $this->assertGreaterThanOrEqual(
            ParecidoDeNombres::UMBRAL,
            ParecidoDeNombres::entre('Ximena Muños', 'MUÑOZ CARRASQUILLA, Ximena')
        );
    }

    // ── Y lo que NO tiene que casar ──────────────────────────────────────────

    #[Test]
    public function una_palabra_suelta_no_identifica_a_nadie(): void
    {
        // Todo lo que el docente escribió está en la ficha, así que mirando sólo esa
        // dirección sería un 1. La otra dirección es la que dice que falta casi todo.
        $this->assertLessThan(
            ParecidoDeNombres::UMBRAL,
            ParecidoDeNombres::entre('Maria', 'GÓMEZ RESTREPO, María Fernanda')
        );
    }

    #[Test]
    public function dos_nombres_distintos_no_se_parecen(): void
    {
        $this->assertLessThan(
            ParecidoDeNombres::UMBRAL,
            ParecidoDeNombres::entre('Maria F. Gomez', 'RESTREPO VÉLEZ, Camila')
        );
    }

    #[Test]
    public function un_nombre_vacio_no_se_parece_a_nada(): void
    {
        $this->assertSame(0.0, ParecidoDeNombres::entre('', 'CÁRDENAS PEÑA, José Luis'));
        $this->assertSame(0.0, ParecidoDeNombres::entre('   ·  ', 'CÁRDENAS PEÑA, José Luis'));
        $this->assertSame(0.0, ParecidoDeNombres::entre(null, null));
    }

    // ── Pocos, ordenados y por encima de la raya ─────────────────────────────

    #[Test]
    public function se_devuelven_como_mucho_tres_y_el_mejor_primero(): void
    {
        $grupo = [
            11 => 'CÁRDENAS PEÑA, José Luis',
            12 => 'CÁRDENAS PEÑA, José Luis Alberto',
            13 => 'CÁRDENAS ROJAS, José',
            14 => 'CÁRDENAS ROJAS, Jose Luis',
            15 => 'CARDONA PEÑA, José Luis',
        ];

        $mejores = ParecidoDeNombres::mejores('Jose Luis Cardenaz', $grupo);

        $this->assertCount(3, $mejores, 'Tres es lo que cabe en una tarjeta que se lee de un vistazo.');
        $this->assertSame(11, $mejores[0]['clave']);

        $parecidos = array_column($mejores, 'parecido');
        $ordenados = $parecidos;
        rsort($ordenados);

        $this->assertSame($ordenados, $parecidos);
    }

    #[Test]
    public function por_debajo_del_umbral_no_sale_nadie(): void
    {
        // **Cinco nombres que no se parecen a nada son peor que ninguno**: con la
        // lista vacía la pantalla enseña el «no está, y esto es lo que se hace»; con
        // cinco caras parecidas enseña una lotería.
        $this->assertSame([], ParecidoDeNombres::mejores('Zoraida Quintanilla Wu', [
            11 => 'CÁRDENAS PEÑA, José Luis',
            12 => 'RESTREPO VÉLEZ, Camila',
        ]));
    }

    #[Test]
    public function con_empate_manda_la_llave_para_que_dos_ensayos_ensenen_lo_mismo(): void
    {
        // Un candidato que baila de sitio entre dos lecturas del mismo fichero es un
        // candidato en el que no se puede confiar. Es la lección del `ORDER BY` que
        // empata del doc 03, aplicada a un orden que se decide en PHP.
        $grupo = [77 => 'PEÑA RÍOS, Ana', 12 => 'PEÑA RÍOS, Ana'];

        $mejores = ParecidoDeNombres::mejores('Ana Peña Rios', $grupo);

        $this->assertSame($mejores[0]['parecido'], $mejores[1]['parecido']);
        $this->assertSame(12, $mejores[0]['clave']);
    }
}
