<?php

namespace Tests\Unit;

use App\Support\HtmlDelEditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Qué sobrevive al saneador y qué no.
 *
 * Los dos lados importan por igual y por eso están en el mismo archivo. Un
 * saneador que se coma el color deja de usarse a los dos días —lo desactivan y
 * vuelve el agujero—, y uno que deje pasar el `onerror` no sirve de nada. Lo que
 * fija este test es la frontera exacta entre las dos cosas.
 *
 * Los casos de ataque no son inventados: son las formas con las que se comprobó
 * a mano que el `bypassSecurityTrustHtml` del Angular las ejecutaba.
 */
class HtmlDelEditorTest extends TestCase
{
    /** Lo que la barra de herramientas del PIAR sabe generar. */
    public static function formatoQueDebeSobrevivir(): array
    {
        return [
            'negrita' => ['<p><strong>a</strong></p>', '<strong>'],
            'cursiva' => ['<p><em>a</em></p>', '<em>'],
            'subrayado' => ['<p><u>a</u></p>', '<u>'],
            'color de texto' => ['<p><span style="color:#e11d48;">a</span></p>', 'color:#e11d48'],
            'color de fondo' => ['<p><span style="background-color:#fde047;">a</span></p>', 'background-color:#fde047'],
            'alineación' => ['<p style="text-align:center">a</p>', 'text-align:center'],
            'lista con viñetas' => ['<ul><li>a</li></ul>', '<li>a</li>'],
            'lista numerada' => ['<ol><li>a</li></ol>', '<li>a</li>'],
            'título' => ['<h2>a</h2>', '<h2>a</h2>'],
            'línea horizontal' => ['<p>a</p><hr />', '<hr'],
            'enlace' => ['<a href="https://x.com">a</a>', 'href="https://x.com"'],
        ];
    }

    #[DataProvider('formatoQueDebeSobrevivir')]
    public function test_conserva_el_formato_del_editor(string $entrada, string $esperado): void
    {
        $this->assertStringContainsString($esperado, (string) HtmlDelEditor::limpiar($entrada));
    }

    /**
     * Lo que no puede salir vivo.
     *
     * Se comprueba por lo que NO aparece, no comparando con una cadena exacta:
     * lo que importa es que no quede nada ejecutable, no cómo quede reescrito
     * lo demás.
     */
    public static function ataquesQueDebenCaer(): array
    {
        return [
            'script' => ['<p>hola</p><script>alert(1)</script>', ['<script', 'alert']],
            'img onerror' => ['<img src=x onerror="alert(1)" alt="" />', ['onerror', 'alert']],
            'href javascript' => ['<a href="javascript:alert(1)">a</a>', ['javascript:']],
            'svg onload' => ['<svg/onload=alert(1)>', ['<svg', 'onload']],
            'iframe' => ['<iframe src="//evil.com"></iframe>', ['<iframe']],
            'formulario' => ['<form action="//evil"><input name="pw" /></form>', ['<form', '<input']],
            'etiqueta style' => ['<style>body{display:none}</style><p>a</p>', ['<style']],
            'onclick en párrafo' => ['<p onclick="alert(1)">a</p>', ['onclick']],
            'superposición css' => ['<p style="position:fixed;top:0;width:100vw">a</p>', ['position', 'fixed']],
            'exfiltración por url()' => ['<span style="background-image:url(//evil/p.png)">a</span>', ['url(', 'evil']],
            'expression de ie' => ['<p style="width:expression(alert(1))">a</p>', ['expression']],
            'objeto embebido' => ['<object data="//evil"></object>', ['<object']],
        ];
    }

    /**
     * @param  string[]  $prohibidos
     */
    #[DataProvider('ataquesQueDebenCaer')]
    public function test_elimina_lo_ejecutable(string $entrada, array $prohibidos): void
    {
        $limpio = (string) HtmlDelEditor::limpiar($entrada);

        foreach ($prohibidos as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $limpio,
                "Sobrevivió '{$prohibido}' a partir de: {$entrada}");
        }
    }

    /**
     * `null` no puede convertirse en cadena vacía.
     *
     * El informe pedagógico distingue las dos cosas: con `reporte` en `null`
     * pone el texto por defecto de la configuración del colegio, y con cadena
     * vacía deja el campo vacío a propósito.
     */
    public function test_null_sigue_siendo_null(): void
    {
        $this->assertNull(HtmlDelEditor::limpiar(null));
        $this->assertSame('', HtmlDelEditor::limpiar(''));
    }

    /**
     * Sanear dos veces tiene que dar lo mismo que sanear una.
     *
     * Es lo que permite que `piar:limpiar-html` decida por comparación si una
     * fila cambia, y con eso no tocar el `updated_at` de las que ya están
     * limpias. Sin esto reescribiría las seis mil filas en cada pasada.
     */
    public function test_es_idempotente(): void
    {
        $entrada = '<p style="text-align:center"><span style="color:#00f;">a</span>'
            .'<script>alert(1)</script><b>b</b></p>';

        $unaVez = HtmlDelEditor::limpiar($entrada);

        $this->assertSame($unaVez, HtmlDelEditor::limpiar($unaVez));
    }
}
