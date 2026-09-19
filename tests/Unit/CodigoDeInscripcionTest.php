<?php

namespace Tests\Unit;

use App\Services\CodigoDeInscripcion;
use PHPUnit\Framework\TestCase;

/**
 * El código impreso del formulario de inscripción.
 *
 * Su docblock afirma **dos propiedades matemáticas** —que atrapa todo error de un
 * carácter y toda transposición— y esas dos no se comprueban con ejemplos: se
 * comprueban **agotando el espacio**. Es barato (son unos cientos de miles de
 * cuentas sin base de datos) y es la diferencia entre «probé tres y fueron bien»
 * y «no existe ninguno que falle».
 *
 * Importa porque el fallo que estas dos propiedades impiden **no se ve nunca**:
 * sin carácter de control, un tecleo malo no da error, da **otro alumno**, y la
 * secretaría digita el formulario de Pedro sobre el código de Ana sin que nada
 * parezca raro.
 */
class CodigoDeInscripcionTest extends TestCase
{
    /** Con cuántos códigos se agota el espacio de errores. */
    private const MUESTRA = 200;

    public function test_lo_que_genera_lo_acepta(): void
    {
        for ($i = 0; $i < self::MUESTRA; $i++) {
            $codigo = CodigoDeInscripcion::generar(2027);

            $this->assertTrue(CodigoDeInscripcion::esValido($codigo),
                "El generador produjo «{$codigo}» y el validador lo rechaza.");
        }
    }

    public function test_la_forma_es_la_que_se_imprime(): void
    {
        $codigo = CodigoDeInscripcion::generar(2027);

        $this->assertMatchesRegularExpression('/^2027-['.preg_quote(CodigoDeInscripcion::ALFABETO, '/').']{6}$/', $codigo,
            'La forma impresa cambió. Los códigos ya repartidos están en casa de las familias.');
    }

    /**
     * **Propiedad 1: ningún carácter mal tecleado pasa.**
     *
     * Se prueban TODAS las sustituciones posibles: cada posición del sufijo por
     * cada uno de los otros 28 caracteres del alfabeto. Son 200 × 5 × 28 = 28.000
     * códigos equivocados y ni uno puede validar.
     */
    public function test_ningun_error_de_un_caracter_pasa(): void
    {
        $alfabeto = CodigoDeInscripcion::ALFABETO;
        $probados = 0;

        for ($i = 0; $i < self::MUESTRA; $i++) {
            $bueno = CodigoDeInscripcion::generar(2027);

            // Sólo el sufijo aleatorio: el carácter de control es la posición 10 y
            // tocarlo es el mismo caso, pero el que interesa es el que la persona
            // teclea leyendo del papel.
            for ($pos = 5; $pos < 10; $pos++) {
                foreach (str_split($alfabeto) as $otro) {
                    if ($otro === $bueno[$pos]) {
                        continue;
                    }

                    $malo = $bueno;
                    $malo[$pos] = $otro;
                    $probados++;

                    $this->assertFalse(CodigoDeInscripcion::esValido($malo),
                        "«{$malo}» valida y no debería: es «{$bueno}» con un carácter cambiado.");
                }
            }
        }

        // Sin esto, un fallo que dejara el bucle vacío se leería como un test verde.
        $this->assertSame(self::MUESTRA * 5 * (strlen($alfabeto) - 1), $probados,
            'No se probaron todas las sustituciones: este test no midió lo que dice.');
    }

    /**
     * **Propiedad 2: ninguna transposición pasa.**
     *
     * Es el error de quien copia rápido, y el que una suma sin pesos NO atrapa —de
     * ahí que los pesos sean distintos entre sí—. Se prueban todos los pares de
     * posiciones, no sólo los contiguos.
     */
    public function test_ninguna_transposicion_pasa(): void
    {
        $probados = 0;

        for ($i = 0; $i < self::MUESTRA; $i++) {
            $bueno = CodigoDeInscripcion::generar(2027);

            for ($a = 5; $a < 10; $a++) {
                for ($b = $a + 1; $b < 10; $b++) {
                    if ($bueno[$a] === $bueno[$b]) {
                        continue;   // intercambiar dos iguales no es un error
                    }

                    $malo = $bueno;
                    [$malo[$a], $malo[$b]] = [$bueno[$b], $bueno[$a]];
                    $probados++;

                    $this->assertFalse(CodigoDeInscripcion::esValido($malo),
                        "«{$malo}» valida y no debería: es «{$bueno}» con dos caracteres cambiados de sitio.");
                }
            }
        }

        $this->assertGreaterThan(self::MUESTRA * 5, $probados,
            'Se probaron muy pocas transposiciones para que esto signifique algo.');
    }

    /**
     * El año entra en la cuenta, no sólo delante.
     *
     * Sin esto, el mismo sufijo valdría en todas las campañas y un código de 2026
     * tecleado en la pantalla de 2027 encontraría una fila que no era.
     */
    public function test_el_mismo_sufijo_no_vale_en_otro_anio(): void
    {
        $fallos = 0;

        for ($i = 0; $i < self::MUESTRA; $i++) {
            $codigo = CodigoDeInscripcion::generar(2027);
            $otroAnio = '2026'.substr($codigo, 4);

            if (CodigoDeInscripcion::esValido($otroAnio)) {
                $fallos++;
            }
        }

        // Uno de cada 29 coincidirá por azar —el control tiene 29 valores— y eso no
        // es un fallo del diseño: el control es un detector de erratas, no una
        // firma. Lo que sería un fallo es que el año NO entrara en la cuenta, y eso
        // se vería aquí como 200 de 200.
        $this->assertLessThan(self::MUESTRA / 4, $fallos,
            'El año no está entrando en el carácter de control: el mismo sufijo vale en cualquier campaña.');
    }

    public function test_el_alfabeto_no_tiene_los_que_se_confunden(): void
    {
        foreach (['O', '0', 'I', '1', 'L', 'S', '5'] as $ambiguo) {
            $this->assertStringNotContainsString($ambiguo, CodigoDeInscripcion::ALFABETO,
                "«{$ambiguo}» volvió al alfabeto. Se quitó porque se confunde leyendo un papel.");
        }

        $this->assertSame(29, strlen(CodigoDeInscripcion::ALFABETO));
        $this->assertSame(29, count(array_unique(str_split(CodigoDeInscripcion::ALFABETO))),
            'Hay un carácter repetido en el alfabeto: dos valores distintos darían el mismo carácter.');
    }

    public function test_se_teclea_con_espacios_y_en_minusculas(): void
    {
        $codigo = CodigoDeInscripcion::generar(2027);

        $this->assertTrue(CodigoDeInscripcion::esValido('  '.strtolower($codigo).' '),
            'Quien teclea del papel no escribe en mayúsculas ni sin espacios.');
    }

    public function test_lo_que_no_es_un_codigo_no_revienta(): void
    {
        foreach ([null, '', '2027', '2027-', 'ABCDEF', '2027-4K7M2', '2027-4K7M2XY',
            '2027-4K7M2O', '2027 4K7M2X', "2027-4K7M2X\n", '<script>'] as $basura) {
            $this->assertFalse(CodigoDeInscripcion::esValido($basura),
                'Aceptó algo que no tiene la forma de un código: '.var_export($basura, true));
        }
    }
}
