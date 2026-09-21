<?php

namespace Tests\Unit;

use App\Support\FirmaDelLibro;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La firma de la hoja `_myvc` del libro de «notas sin internet».
 *
 * ## Qué se comprueba aquí, y por qué no basta con «firma y verifica»
 *
 * Un test que firmara y comprobara con la misma clase pasaría igual de bien
 * firmando una cadena vacía, o firmando **sólo la primera clave** del array. Lo
 * que hace falta comprobar son las dos propiedades de las que depende la D3 del
 * plan —«sólo entra lo que el docente cambió»— y ninguna es «devuelve una cadena
 * de 64 caracteres»:
 *
 *  1. **Que un cambio en el espejo se note.** Si no, la comparación de tres puntas
 *     de la fase 2 seguiría funcionando y mintiendo, que es el único fallo que la
 *     firma existe para evitar.
 *  2. **Que un libro correcto NO dé la alarma.** Una firma que se rompa sola —al
 *     reordenar una consulta, o porque PDO devolvió `"301"` en vez de `301`—
 *     convierte el aviso en ruido, y un aviso que salta siempre deja de leerse.
 *
 * Hereda de `Tests\TestCase` y no de la de PHPUnit **porque la clave sale de
 * `APP_KEY`**, y eso necesita la configuración del framework cargada. Es el mismo
 * caso que `HtmlDelEditorTest`, el otro de esta carpeta que la necesita.
 *
 * Ver `myvc_front/PLAN-NOTAS-SIN-INTERNET.md` §4.6 y §4.7.
 */
class FirmaDelLibroTest extends TestCase
{
    /** Un libro cualquiera, con la forma que de verdad va en la hoja `_myvc`. */
    private function libro(): array
    {
        return [
            'libro' => [
                'formato' => 1,
                'year_id' => 8,
                'periodo_id' => 31,
                'profesor_id' => 12,
                'periodo_abierto' => true,
            ],
            'hojas' => [
                [
                    'hoja' => '3B Matematicas',
                    'asignatura_id' => 301,
                    'grupo_id' => 22,
                    'columnas' => ['D' => 1201, 'E' => 1202],
                    'filas' => [3 => 1055, 4 => 1057],
                    'espejo' => [
                        '1055' => ['1201' => 45, '1202' => null],
                        '1057' => ['1201' => 38, '1202' => 29],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function la_firma_de_un_libro_se_comprueba_consigo_misma(): void
    {
        $libro = $this->libro();

        $this->assertTrue(FirmaDelLibro::comprobar($libro, FirmaDelLibro::firmar($libro)));
    }

    #[Test]
    public function es_un_hmac_sha256_en_hexadecimal(): void
    {
        // No es cosmética: la columna `huella` de `descargas_de_planilla` y el
        // hueco de la celda `B3` están dimensionados para 64 caracteres hex, y una
        // firma en base64 cabría igual y rompería el índice sin fallar nada.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', FirmaDelLibro::firmar($this->libro()));
    }

    #[Test]
    public function una_nota_cambiada_en_el_espejo_rompe_la_firma(): void
    {
        $libro = $this->libro();
        $firma = FirmaDelLibro::firmar($libro);

        $libro['hojas'][0]['espejo']['1055']['1201'] = 46;

        $this->assertFalse(
            FirmaDelLibro::comprobar($libro, $firma),
            'Un 45 convertido en 46 dentro del espejo tiene que notarse: si no, la D3 compara contra '
            .'un pasado inventado y da por «no tocada» una celda que sí se tocó.'
        );
    }

    #[Test]
    public function una_casilla_vacia_no_firma_igual_que_un_cero(): void
    {
        // Desde `2026_09_19_500000_la_casilla_vacia` una nota sin calificar es NULL
        // y **no vale cero**. Si `null` y `0` firmaran igual, alguien podría
        // escribir ceros en el espejo de las casillas vacías y el libro seguiría
        // validando — y al subirlo, esas casillas contarían como «ya estaban en 0».
        $conNulo = $this->libro();
        $conCero = $this->libro();
        $conCero['hojas'][0]['espejo']['1055']['1202'] = 0;

        $this->assertNotSame(FirmaDelLibro::firmar($conNulo), FirmaDelLibro::firmar($conCero));
    }

    #[Test]
    public function el_mapa_de_columnas_tambien_va_firmado(): void
    {
        // Mover la columna `D` a otro indicador sin tocar las notas dejaría el
        // espejo intacto y **las notas puestas en la asignatura equivocada**.
        $libro = $this->libro();
        $firma = FirmaDelLibro::firmar($libro);

        $libro['hojas'][0]['columnas']['D'] = 9999;

        $this->assertFalse(FirmaDelLibro::comprobar($libro, $firma));
    }

    #[Test]
    public function el_orden_de_las_claves_no_cambia_la_firma(): void
    {
        // Ésta es la mitad que evita las alarmas falsas. Un `SELECT` reordenado, o
        // un `array_merge` que ponga las claves en otro sitio, producen el mismo
        // libro con las claves en otro orden — y si eso rompiera la firma, los
        // libros empezarían a «estar manipulados» sin que nadie los tocara.
        $uno = $this->libro();

        $dos = [
            'hojas' => $uno['hojas'],
            'libro' => array_reverse($uno['libro'], true),
        ];

        $this->assertSame(FirmaDelLibro::firmar($uno), FirmaDelLibro::firmar($dos));
    }

    #[Test]
    public function un_entero_que_viene_como_cadena_firma_igual(): void
    {
        // PDO devuelve los enteros de MySQL **como cadenas**, así que el mismo dato
        // llega como `301` o como `"301"` según por qué consulta vino. Sin
        // normalizar, el mismo libro firmaría distinto según el camino, y la fase 2
        // vería firmas rotas sin manipulación ninguna.
        $enteros = $this->libro();

        $cadenas = $enteros;
        $cadenas['libro']['periodo_id'] = '31';
        $cadenas['hojas'][0]['columnas']['D'] = '1201';
        $cadenas['hojas'][0]['espejo']['1057']['1201'] = '38';

        $this->assertSame(FirmaDelLibro::firmar($enteros), FirmaDelLibro::firmar($cadenas));
    }

    #[Test]
    public function el_orden_de_una_lista_si_cambia_la_firma(): void
    {
        // La contraria de la anterior, y las dos tienen que ser ciertas a la vez:
        // en una **lista** el orden es dato —las hojas van en el orden del libro—,
        // así que ordenarla sería borrar información. Sólo se ordenan los mapas.
        $uno = $this->libro();
        $uno['hojas'][] = ['hoja' => '4A Estadistica', 'asignatura_id' => 302, 'columnas' => [], 'espejo' => []];

        $dos = $uno;
        $dos['hojas'] = array_reverse($dos['hojas']);

        $this->assertNotSame(FirmaDelLibro::firmar($uno), FirmaDelLibro::firmar($dos));
    }
}
