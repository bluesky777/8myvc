<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;

/**
 * Las dos casillas del SISBEN de la hoja de importación, que entraban en el SQL
 * sin ligar. Es la §60 de docs/migracion/05-codigo-muerto-y-roto.md.
 *
 * `ImporterFixer::verificar()` arma un trozo de SQL —`$cons`— que
 * `ImportarController` mete **dentro de la lista SET** de un `UPDATE alumnos`
 * que sí se ejecuta. Casi todo lo que concatena ahí sale de la base (los ids de
 * `ciudades`), pero `nro_sisben` y `nro_sisben_3` salían de la casilla de la
 * hoja **que sube el usuario**. Una liga y la otra no, en el mismo método: la
 * misma forma que tenía la inyección de `putOrdinales`.
 *
 * Por qué son cinco casos y no uno:
 *
 * El arreglo barato —quitar la concatenación y confiar en el `nro_sisben=?` que
 * la consulta ya liga— **regresa cuatro columnas**, y por eso no se hizo:
 * `has_sisben`, `has_sisben_3` y `nro_sisben_3` no las escribe nadie más en
 * todo `app/`, y `nro_sisben` solo va ligada por esta ruta. Las cuatro ramas del
 * fixer tienen que seguir escribiendo lo mismo que escribían, así que las cuatro
 * se comprueban, y la quinta es la inyección.
 *
 * Comprobado al revés, y el recuento no es el que parecía. Revertir el arreglo
 * entero tumba **uno solo** —el de la inyección—: los otros cuatro pasan con y
 * sin él, porque el arreglo bueno no cambia lo que se escribe. Lo que sí los
 * tumba es el **arreglo barato**, y ahí caen los **cuatro**. Cada uno mide algo
 * distinto, y hacen falta las dos reversiones para saberlo.
 *
 * De esa segunda reversión salió además un verde hueco: los dos casos de «no
 * aplica» pasaban sin medir nada porque la columna ya llegaba vacía del seed.
 * Por eso plantan el valor antes de importar.
 *
 * Lo que NO se comprueba aquí, y conviene saberlo: la otra ruta que usa el mismo
 * fragmento, `GET api/importar/modificar/{year}`, **no llega nunca al fixer** —
 * muere antes en `Excel::import()` con la firma de maatwebsite 2.x, y está
 * documentada rota en la [§13.3](../../docs/migracion/05-codigo-muerto-y-roto.md).
 * Su llamada quedó ligada igual, porque un fragmento que solo es seguro porque
 * su llamante está muerto es una trampa esperando a que alguien lo reviva.
 */
class ImportarSisbenTest extends CasoDeContrato
{
    /**
     * La casilla del SISBEN no puede escribir otra columna.
     *
     * El payload no lleva `--` **a propósito**: comentar el final descuadra el
     * `WHERE id=?` frente a las vinculaciones y PDO revienta antes, que es
     * justo por lo que esto no se había visto nunca — el intento torpe falla
     * con lo que parece una hoja mal formada. El que entra respeta el número de
     * marcas.
     */
    public function test_la_casilla_del_sisben_no_escribe_otra_columna(): void
    {
        [$token, $year] = $this->credenciales();

        $libro = $this->exportacionDeAlumnos($token);
        [$hoja, $fila, $alumno_id] = $this->primeraFilaConId($libro);

        $antes = $this->columnaDelAlumno($alumno_id, 'nombres');

        $r = $this->importar(
            $this->libroConSisben($libro, $hoja, $fila, "1, nombres='INYECTADO'"),
            $token,
            $year
        );

        // Da igual si responde 200 o 500: lo que no puede pasar es que el
        // nombre cambie. Mirar el resultado y no el código es lo que hace que
        // este test valga.
        $this->assertNotSame('INYECTADO', $this->columnaDelAlumno($alumno_id, 'nombres'),
            'La casilla del SISBEN escribió en `nombres`: el fragmento sigue concatenándose sin ligar.');

        $this->assertSame($antes, $this->columnaDelAlumno($alumno_id, 'nombres'),
            'El nombre del alumno cambió durante la importación por algo que venía en la casilla del SISBEN.');

        $r->assertStatus(200);
    }

    /** Un SISBEN con número se guarda tal cual, y enciende `has_sisben`. */
    public function test_un_sisben_con_numero_se_guarda_y_enciende_la_casilla(): void
    {
        [$token, $year] = $this->credenciales();

        $libro = $this->exportacionDeAlumnos($token);
        [$hoja, $fila, $alumno_id] = $this->primeraFilaConId($libro);

        $this->importar($this->libroConSisben($libro, $hoja, $fila, '123456789'), $token, $year)
            ->assertStatus(200);

        $this->assertSame('123456789', (string) $this->columnaDelAlumno($alumno_id, 'nro_sisben'));
        $this->assertSame(1, (int) $this->columnaDelAlumno($alumno_id, 'has_sisben'));
    }

    /**
     * «No aplica» apaga la casilla y borra el número.
     *
     * Esta rama es la que el arreglo barato se habría llevado por delante: el
     * `nro_sisben=null` del fragmento **pisa a propósito** al `nro_sisben=?`
     * que la consulta liga antes, porque va después en el SET.
     */
    public function test_un_sisben_que_no_aplica_apaga_la_casilla_y_borra_el_numero(): void
    {
        [$token, $year] = $this->credenciales();

        $libro = $this->exportacionDeAlumnos($token);
        [$hoja, $fila, $alumno_id] = $this->primeraFilaConId($libro);

        // El valor se planta antes a propósito: con la columna ya vacía en el
        // seed, este test pasaba sin medir nada — y es la sexta vez que el seed
        // vacío deja un verde hueco (09 §0.0).
        DB::update('UPDATE alumnos SET has_sisben=1, nro_sisben=? WHERE id=?', ['111222333', $alumno_id]);

        $this->importar($this->libroConSisben($libro, $hoja, $fila, 'No aplica'), $token, $year)
            ->assertStatus(200);

        $this->assertNull($this->columnaDelAlumno($alumno_id, 'nro_sisben'));
        $this->assertSame(0, (int) $this->columnaDelAlumno($alumno_id, 'has_sisben'));
    }

    /**
     * El SISBEN 3, que es la otra mitad y no la escribe nadie más.
     *
     * `nro_sisben_3` y `has_sisben_3` no aparecen en ningún otro `UPDATE` de
     * `app/`: si el fragmento deja de escribirlas, no las escribe nada.
     */
    public function test_un_sisben_3_con_numero_se_guarda_y_enciende_la_casilla(): void
    {
        [$token, $year] = $this->credenciales();

        $libro = $this->exportacionDeAlumnos($token);
        [$hoja, $fila, $alumno_id] = $this->primeraFilaConId($libro);

        $this->importar($this->libroConSisben($libro, $hoja, $fila, null, '987654321'), $token, $year)
            ->assertStatus(200);

        $this->assertSame('987654321', (string) $this->columnaDelAlumno($alumno_id, 'nro_sisben_3'));
        $this->assertSame(1, (int) $this->columnaDelAlumno($alumno_id, 'has_sisben_3'));
    }

    /** Y su «no aplica», por lo mismo. */
    public function test_un_sisben_3_que_no_aplica_apaga_la_casilla_y_borra_el_numero(): void
    {
        [$token, $year] = $this->credenciales();

        $libro = $this->exportacionDeAlumnos($token);
        [$hoja, $fila, $alumno_id] = $this->primeraFilaConId($libro);

        // Igual que el del SISBEN 1: sin plantar el valor antes, la columna ya
        // llega vacía del seed y el test pasa aunque el arreglo la rompa. Lo
        // destapó comprobar al revés con el arreglo barato: caían tres de
        // cuatro, y el cuarto era éste.
        DB::update('UPDATE alumnos SET has_sisben_3=1, nro_sisben_3=? WHERE id=?', ['444555666', $alumno_id]);

        $this->importar($this->libroConSisben($libro, $hoja, $fila, null, 'No aplica'), $token, $year)
            ->assertStatus(200);

        $this->assertNull($this->columnaDelAlumno($alumno_id, 'nro_sisben_3'));
        $this->assertSame(0, (int) $this->columnaDelAlumno($alumno_id, 'has_sisben_3'));
    }

    // ---------------------------------------------------------------- ayudas

    private function credenciales(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $year = DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');

        return [$this->tokenDe($usuario->username), (int) $year];
    }

    /** La hoja que produce el export, que es la plantilla que la gente rellena. */
    private function exportacionDeAlumnos(string $token): string
    {
        $r = $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $copia = tempnam(sys_get_temp_dir(), 'sisben').'.xlsx';
        copy($this->archivoDescargado($r), $copia);

        return $copia;
    }

    private function importar(string $archivo, string $token, int $year)
    {
        return $this->post(
            "/api/importar/algo/{$year}",
            ['file' => new UploadedFile($archivo, 'alumnos.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        );
    }

    /**
     * La primera fila que trae `id`: el importador la trata como alumno que ya
     * existe, que es el único camino donde el fragmento del fixer se usa.
     */
    private function primeraFilaConId(string $archivo): array
    {
        $libro = IOFactory::load($archivo);

        foreach ($libro->getAllSheets() as $hoja) {
            $columnas = $this->columnasDe($hoja);

            for ($fila = 3; $fila <= $hoja->getHighestDataRow(); $fila++) {
                $id = trim((string) $hoja->getCell($columnas['id'].$fila)->getValue());

                if ($id !== '') {
                    return [$hoja->getTitle(), $fila, (int) $id];
                }
            }
        }

        $this->fail('El export no trae ninguna fila con `id`; sin eso este test no comprueba nada.');
    }

    /** Copia del libro con las dos casillas del SISBEN puestas a lo que se le diga. */
    private function libroConSisben(string $archivo, string $hoja, int $fila, ?string $sisben, ?string $sisben_3 = null): string
    {
        $libro = IOFactory::load($archivo);
        $pestana = $libro->getSheetByName($hoja);
        $columnas = $this->columnasDe($pestana);

        if ($sisben !== null) {
            $pestana->setCellValueExplicit($columnas['sisben'].$fila, $sisben,
                DataType::TYPE_STRING);
        }

        if ($sisben_3 !== null) {
            $pestana->setCellValueExplicit($columnas['sisben_3'].$fila, $sisben_3,
                DataType::TYPE_STRING);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'sisben').'.xlsx';
        (new EscritorXlsx($libro))->save($ruta);

        return $ruta;
    }

    private function columnaDelAlumno(int $alumno_id, string $columna)
    {
        return DB::selectOne('SELECT '.$columna.' AS v FROM alumnos WHERE id = ?', [$alumno_id])->v;
    }

    /**
     * Qué letra de columna es cada encabezado. Los encabezados están en la fila
     * 2 y se normalizan igual que hace `WithHeadingRow`.
     */
    private function columnasDe($hoja): array
    {
        $columnas = [];

        foreach ($hoja->getRowIterator(2, 2) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $titulo = trim((string) $celda->getValue());

                if ($titulo !== '') {
                    $columnas[$this->normalizar($titulo)] = $celda->getColumn();
                }
            }
        }

        $this->assertArrayHasKey('id', $columnas, 'El export dejó de traer la columna `id`.');
        $this->assertArrayHasKey('sisben', $columnas, 'El export dejó de traer la columna `SISBEN`.');
        $this->assertArrayHasKey('sisben_3', $columnas, 'El export dejó de traer la columna `SISBEN 3`.');

        return $columnas;
    }

    private function normalizar(string $titulo): string
    {
        $sinTildes = strtr(mb_strtolower($titulo), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', $sinTildes)), '_');
    }
}
