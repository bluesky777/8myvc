<?php

namespace Tests\Unit;

use App\Support\ColumnaSegura;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nombres de columna que llegan del cliente.
 *
 * Diez endpoints armaban `UPDATE <tabla> SET '.$propiedad.'=:valor` con la propiedad tal cual la
 * mandaba el navegador. El valor iba parametrizado, pero el nombre de la columna no se puede
 * parametrizar en SQL, así que la única defensa es validarlo -- y no se validaba.
 *
 * Aquí se fija la parte pura: la forma del nombre. La existencia en la tabla la comprueba
 * ColumnaSegura contra el esquema real, que necesita base de datos y se prueba en otro sitio.
 */
class ColumnaSeguraTest extends TestCase
{
    #[DataProvider('nombresLegitimos')]
    public function test_acepta_nombres_de_columna_normales(string $columna): void
    {
        $this->assertTrue(ColumnaSegura::nombreValido($columna), "debería aceptar: $columna");
    }

    public static function nombresLegitimos(): array
    {
        // Propiedades que las pantallas guardan de verdad hoy. Si el arreglo rechazara una de
        // estas, el arreglo de seguridad sería una avería.
        return array_map(fn ($c) => [$c], [
            'nombres', 'apellidos', 'sexo', 'fecha_nac', 'razon_retiro', 'fecha_matricula',
            'fecha_retiro', 'estrato', 'religion', 'no_matricula', 'nee_descripcion',
            'has_sisben_3', 'nro_sisben_3', 'is_urbana', 'tipo_doc', 'promovido', 'nro_folio',
            'descripcion_recomendacion', 'cant_areas_pierde_year', 'presencial', 'repitente',
        ]);
    }

    #[DataProvider('intentosDeInyeccion')]
    public function test_rechaza_lo_que_no_es_un_nombre_de_columna($columna): void
    {
        $this->assertFalse(ColumnaSegura::nombreValido($columna));
    }

    public static function intentosDeInyeccion(): array
    {
        return [
            'cierra la asignación y añade otra' => ['nombres=:valor, is_superuser=1, x'],
            'comilla para salir del nombre' => ['nombres`=1, `apellidos'],
            'comentario que corta el resto' => ['nombres=1 -- '],
            'punto y coma y otra sentencia' => ['nombres=1; DROP TABLE alumnos'],
            'subconsulta' => ['nombres=(SELECT 1)'],
            'espacios' => ['nombres apellidos'],
            'tabla ajena con punto' => ['users.is_superuser'],
            'paréntesis' => ['count(*)'],
            'coma' => ['nombres,apellidos'],
            'vacío' => [''],
            'sólo espacios' => ['   '],
            'nulo' => [null],
            'arreglo' => [['nombres']],
            'número' => [123],
            'empieza por número' => ['1nombres'],
            'empieza por guión bajo y símbolo' => ['$nombres'],
        ];
    }

    /**
     * Aunque existan, estas columnas no las escribe un "guardar un campo": id y las de auditoría
     * las pone el sistema, y dejar escribir deleted_at convierte un guardado en un borrado lógico
     * -- o en resucitar una fila borrada.
     */
    public function test_rechaza_las_columnas_de_control(): void
    {
        foreach (ColumnaSegura::PROHIBIDAS as $prohibida) {
            $this->assertFalse(ColumnaSegura::nombreValido($prohibida), "no debería aceptar: $prohibida");
            $this->assertFalse(ColumnaSegura::nombreValido(strtoupper($prohibida)), 'ni en mayúsculas');
        }
    }
}
