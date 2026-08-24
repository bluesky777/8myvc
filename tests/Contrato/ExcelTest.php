<?php

namespace Tests\Contrato;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Las hojas de cálculo: lo que se exporta y lo que se importa.
 *
 * El otro punto P0 que la Fase 0.2 dejó sin escribir. El plan dio por resuelto
 * el riesgo de Excel al comprobar que `maatwebsite/excel` 3.1.70 declara
 * soporte para Laravel 13, y eso zanja que el paquete *instale*; no dice nada de
 * que los seis exports sigan produciendo las mismas hojas. Entre medias, PHP
 * subió de 8.0 a 8.4 y el framework cinco versiones.
 *
 * Lo que se guarda no son los bytes del archivo: dos XLSX del mismo contenido
 * se diferencian en la fecha de creación que PhpSpreadsheet mete dentro. Se
 * guarda la FORMA de la hoja —cuántas hojas, cómo se llaman, qué encabezados
 * tienen y cuántas filas traen—, que es lo que rompe una migración y lo que la
 * secretaría nota al abrir el archivo.
 */
class ExcelTest extends CasoDeContrato
{
    /**
     * Los cuatro exports que están enrutados y funcionan.
     *
     * Los cuatro usan la API 3.x (`Excel::download(new AlgoExport)`).
     */
    public static function exportsQueFuncionan(): array
    {
        return [
            'deudores' => ['api/cartera/exportar-solo-deudores', 'Usuario', 'deudores'],
            'alumnos por grupo' => ['api/users/export', 'Usuario', 'alumnos'],
            'acudientes' => ['api/acudientes-export/acudientes', 'Usuario', 'acudientes'],
            'simat' => ['api/simat/alumnos-exportar', 'Usuario', 'simat-alumnos-exportar'],
        ];
    }

    #[DataProvider('exportsQueFuncionan')]
    public function test_la_forma_de_la_hoja_exportada(string $ruta, string $tipo, string $instantanea): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

        $this->haciendoQueHayaDeudores();

        $r = $this->get('/'.$ruta, ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->compararConInstantanea('excel-'.$instantanea, $this->formaDeLaHoja($r));
    }

    /**
     * Las tres exportaciones salen — y dos de ellas no salían hasta el 24 ago 2026.
     *
     * **Aquí había tres tests que fijaban el 500 como contrato**, y estaban bien
     * puestos: `Excel::create()` es la API de `maatwebsite/excel` 2.x, la 3.x la
     * quitó, y el plan había dejado los exports fuera de alcance con el motivo
     * escrito en `phpstan.neon` — *«reescribir estos dos a la API nueva es rehacer
     * el informe, no arreglarlo»*.
     *
     * **Ese motivo resultó ser falso, y es lo único que cambió.** No hubo que
     * rehacer ningún informe: `simat/alumnos` monta exactamente lo que
     * `App\Exports\AlumnosExport` ya montaba —los grupos del año, sus alumnos con
     * acudientes, la vista `simat`— así que **se reutiliza tal cual**; y el
     * listado de docentes son sesenta líneas copiando la consulta y la vista que
     * ya existían. Lo caro que el plan supuso no estaba.
     *
     * Lo que sí se pierde son los bordes y los anchos de columna, y va dicho en
     * el doc del lote: estas hojas llevaban años sin salir, así que nadie tiene un
     * fichero con esos bordes al que comparar.
     *
     * Ver docs/migracion/noche-2026-08-24/exp-1.md.
     */
    public static function exportsArreglados(): array
    {
        return [
            'simat, listado de alumnos' => ['api/simat/alumnos'],
            'listado de docentes' => ['api/excel-docentes/docentes/2025/8'],
            'simat, alumnos a importar' => ['api/simat/alumnos-exportar'],
        ];
    }

    /**
     * **Se mira dentro del fichero, no el 200**, que es lo que este fichero venía
     * haciendo con `compararConInstantanea` y lo que los tres tests de 500 no
     * podían hacer. Un `.xlsx` es un zip: empieza por `PK`. Y un libro con una
     * tabla dentro no baja de unos pocos kB, que es lo que separa «salió el
     * fichero» de «salió un libro vacío».
     */
    #[DataProvider('exportsArreglados')]
    public function test_los_exports_de_la_api_vieja_ya_salen(string $ruta): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $r = $this->get('/'.$ruta, ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $bytes = file_get_contents($this->archivoDescargado($r));

        $this->assertSame('PK', substr($bytes, 0, 2),
            "Lo que devuelve {$ruta} no es un .xlsx: un zip empieza por PK.");

        $this->assertGreaterThan(3000, strlen($bytes),
            "El libro de {$ruta} pesa menos que una hoja con una tabla dentro.");
    }

    /**
     * El centinela: que no vuelva a entrar la API 2.x en `app/`.
     *
     * Sin esto el arreglo dura hasta que alguien copie uno de los ejemplos viejos
     * que siguen circulando. Dos cosas que costaron un rojo cada una y valen más
     * que el test:
     *
     * **Se quitan los comentarios antes de buscar.** La primera versión hacía
     * `str_contains` sobre el fichero entero y encontró tres — y las tres eran
     * los comentarios que explican el arreglo, uno de ellos en este mismo
     * fichero. Un detector que suma la mención a la llamada no detecta lo que
     * dice su nombre. Es el mismo `sinComentarios()` que ya usan
     * `GuardsDestructivosTest` y `UsuarioPerezosoTest`.
     *
     * **Y se afirma la población antes del cero.** Un «0 encontrados» sin decir
     * sobre cuántos ficheros significa a la vez «no hay» y «el iterador no
     * encontró la carpeta», y de las dos lecturas la falsa es la que archiva el
     * asunto.
     */
    public function test_no_queda_ni_una_llamada_a_la_api_vieja(): void
    {
        $encontradas = [];
        $ficheros = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }

            $ficheros++;
            $codigo = '';

            foreach (token_get_all(file_get_contents($f->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $codigo .= is_array($token) ? $token[1] : $token;
            }

            foreach (['Excel::create', 'Excel::load'] as $patron) {
                if (str_contains($codigo, $patron)) {
                    $encontradas[] = basename($f->getPathname()).': '.$patron;
                }
            }
        }

        // 220 el 24 ago 2026 (`find app -name '*.php' | wc -l`). El umbral va
        // holgado: detecta «el iterador no encontró la carpeta», no cuenta
        // ficheros. Lo puse en 300 la primera vez y saltó — el umbral estaba mal,
        // no el iterador, y sólo se supo porque el guardián obligó a ir a
        // contarlos.
        $this->assertGreaterThan(150, $ficheros,
            "Sólo se revisaron {$ficheros} ficheros de app/: el iterador no está mirando donde cree.");

        $this->assertSame([], $encontradas,
            'Volvió a entrar la API 2.x de maatwebsite/excel, que en la 3.x es un 500.');
    }

    /**
     * El importador de cartera está roto por lo mismo que los exports de la 2.x,
     * y nadie lo sabía.
     *
     * `POST api/importar/cartera` hace `Excel::import($ruta, function($reader){…})`,
     * que es la firma de maatwebsite/excel **2.x**. En la 3.x el primer
     * argumento es el objeto de importación y el segundo la ruta, así que el
     * closure llega donde se espera una ruta y `pathinfo()` revienta — el mismo
     * error exacto que `GET api/importar` (§8 de 05-codigo-muerto-y-roto.md).
     *
     * **No salió en el muestreo de la P2 porque aquello solo golpeaba lecturas
     * sin parámetro**, y esta es un POST con un archivo dentro. Es la lección de
     * la P2 otra vez, en el sitio donde todavía no se había mirado: lo que no se
     * golpea no se sabe si funciona, y aquí ni el análisis estático ni las 66
     * lecturas llegaban.
     *
     * Importa más allá del endpoint: docs/migracion/09-pendientes.md daba por
     * vivos «los dos importadores» al planear la importación reanudable. Vivo
     * hay uno.
     *
     * Se deja roto con la regla de siempre —con ruta y roto se documenta— y
     * este test fija el error para que arreglarlo sea una decisión y no un
     * accidente.
     */
    public function test_el_importador_de_cartera_usa_la_api_vieja_y_falla(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $this->haciendoQueHayaDeudores();

        $hoja = $this->archivoDescargado(
            $this->get('/api/cartera/exportar-solo-deudores', ['Authorization' => 'Bearer '.$token])->assertStatus(200)
        );

        $this->post('/api/importar/cartera',
            ['file' => new UploadedFile($hoja, 'cartera.xlsx', null, null, true)],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(500);
    }

    /**
     * Y el tercero de la misma familia, que no estaba en ninguna lista.
     *
     * `GET api/importar/modificar/{year}` hace el mismo `Excel::import($ruta,
     * function ($reader) { … })` de la 2.x que los otros dos. No salió antes por
     * lo mismo que la cartera —el muestreo de la P2 golpeaba lecturas **sin
     * parámetro**, y esta lleva `{year}`—; lo destapó el nivel 5 de larastan, que
     * no golpea nada: lee la firma del método y compara. Es la contraria de la
     * lección de la §8, y por eso merece quedar escrita.
     *
     * **Ojo con el error que se ve aquí, porque no es el de producción.** El
     * método empieza por `apache_request_headers()`, que existe bajo FPM
     * —comprobado sirviendo por HTTP en el contenedor— y **no existe en el PHP
     * de línea de comandos** donde corre phpunit. Así que aquí revienta antes,
     * en la línea 639, y en producción llega hasta el `Excel::import` y muere
     * con el `pathinfo(): Argument #1 ($path) must be of type string, Closure
     * given` de sus dos hermanos. Los dos caminos son 500; el test fija eso, que
     * es lo único que se ve desde fuera y lo único que no depende del SAPI.
     *
     * Y un detalle que cambia lo que es este endpoint: el archivo que lee
     * —`app/Http/Controllers/Alumnos/archivos/alumnos-modificar-{year}.xlsx`—
     * **no está en el repo, ni el directorio que lo contiene**. Junto con
     * `GET api/importar`, que lee `archivos/alumnos.xls` del mismo sitio, no son
     * pantallas del colegio: son la herramienta manual de alguien, que dejaba el
     * archivo en la carpeta y llamaba a la URL. Eso es parte de la decisión de
     * si la operación debe existir.
     */
    public function test_el_importador_de_modificar_usa_la_api_vieja_y_falla(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $this->get('/api/importar/modificar/2025', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(500);
    }

    /**
     * Lo que se exporta se puede volver a importar sin que cambie nada.
     *
     * Es el uso real: la secretaría descarga la hoja, corrige unas celdas y la
     * vuelve a subir. Por eso el test no lleva un archivo de ejemplo — usa el
     * que produce el propio export, que es la plantilla que la gente rellena.
     *
     * Y por eso es la prueba fuerte del importador: recorre `ExcelUtils`, el
     * `ImporterFixer` y los dos caminos de acudientes con datos que salieron de
     * la base, no inventados. Si el viaje de ida y vuelta cambia una fila, la
     * importación está corrompiendo datos de alumnos reales cada vez que se usa.
     */
    public function test_reimportar_lo_exportado_no_cambia_a_los_alumnos(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $year = DB::table('periodos')
            ->join('years', 'years.id', '=', 'periodos.year_id')
            ->where('periodos.id', $usuario->periodo_id)
            ->value('years.year');

        $antes = $this->alumnosDelGrupo();
        $this->assertNotEmpty($antes, 'Sin alumnos en el seed esto no comprueba nada.');

        $exportado = $this->archivoDescargado(
            $this->get('/api/users/export', ['Authorization' => 'Bearer '.$token])->assertStatus(200)
        );

        $subida = new UploadedFile($exportado, 'alumnos.xlsx', null, null, true);

        $r = $this->post("/api/importar/algo/{$year}", ['file' => $subida],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        // Devuelve la cadena pelada, no JSON: el controlador hace `return
        // 'Importados.';` y Laravel la sirve como texto. Es lo que lee el
        // frontend hoy.
        $this->assertSame('Importados.', $r->getContent());

        $this->assertEquals($antes, $this->alumnosDelGrupo(),
            'Reimportar la hoja recién exportada cambió los datos de algún alumno.');
    }

    /** Los campos de alumno que toca la importación, para compararlos antes y después. */
    private function alumnosDelGrupo(): array
    {
        return DB::table('alumnos')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'nombres', 'apellidos', 'sexo', 'fecha_nac', 'tipo_doc', 'documento',
                'no_matricula', 'direccion', 'barrio', 'telefono', 'celular', 'estrato',
                'tipo_sangre', 'eps', 'religion', 'nro_sisben'])
            ->map(fn ($fila) => (array) $fila)
            ->all();
    }

    // ---------------------------------------------------------------- Apoyos

    /**
     * El export de deudores salía con cero filas, y una hoja vacía no comprueba
     * nada: pasaría igual con la consulta rota.
     *
     * La razón es del seed y no del código: sus cuatro alumnos con `pazysalvo=0`
     * están todos retirados (`estado = RETI`), y la consulta solo mira ASIS,
     * MATR y PREM. Se les quita el paz y salvo a tres matriculados dentro de la
     * transacción del test, que se deshace al terminar.
     */
    private function haciendoQueHayaDeudores(): void
    {
        // `distinct()` no es adorno: el join da una fila por MATRÍCULA, y desde
        // que el seed tiene dos años el mismo alumno aparece dos veces. Sin él,
        // «los tres primeros» eran tres filas de dos alumnos, el `whereIn`
        // marcaba dos deudores, y la hoja salía con una fila menos sin que nada
        // avisara: `assertCount(3)` seguía pasando porque contaba filas.
        $ids = DB::table('alumnos')
            ->join('matriculas', 'matriculas.alumno_id', '=', 'alumnos.id')
            ->whereIn('matriculas.estado', ['ASIS', 'MATR', 'PREM'])
            ->whereNull('alumnos.deleted_at')
            ->whereNull('matriculas.deleted_at')
            ->distinct()
            ->orderBy('alumnos.id')
            ->limit(3)
            ->pluck('alumnos.id');

        $this->assertCount(3, $ids, 'El seed necesita al menos tres alumnos matriculados.');

        DB::table('alumnos')->whereIn('id', $ids)->update(['pazysalvo' => 0]);
    }

    /**
     * Reduce un XLSX descargado a lo que no puede cambiar: cuántas hojas, cómo
     * se llaman, qué encabezados tienen y cuántas filas de datos traen.
     *
     * No se compara el archivo byte a byte a propósito. PhpSpreadsheet escribe
     * dentro la fecha de creación, así que dos exports idénticos dan archivos
     * distintos; y tampoco se comparan los valores, porque el seed se puede
     * regenerar (tools/generar-seed-test.php) y eso invalidaría el snapshot sin
     * que nada se hubiera roto.
     */
    private function formaDeLaHoja(TestResponse $r): array
    {
        $libro = IOFactory::load($this->archivoDescargado($r));

        $hojas = [];

        foreach ($libro->getAllSheets() as $hoja) {
            $filas = $hoja->toArray(null, true, false, false);
            $encabezado = $this->filaDeEncabezados($filas);

            $hojas[] = [
                'titulo' => $this->sinNumeros($hoja->getTitle()),
                'fila_de_encabezados' => $encabezado,
                'encabezados' => array_values(array_filter(
                    array_map(fn ($celda) => $celda === null ? null : trim((string) $celda), $filas[$encabezado] ?? []),
                    fn ($celda) => $celda !== null && $celda !== ''
                )),
                'columnas' => count($filas[0] ?? []),
                'filas_de_datos' => max(0, count($filas) - $encabezado - 1),
            ];
        }

        return ['hojas' => count($hojas), 'detalle' => $hojas];
    }

    /**
     * En qué fila están los encabezados de verdad.
     *
     * Cuatro de las seis hojas abren con un banner —«Cuarto - <titular>»— en una
     * celda suelta de la primera fila, así que tomar la fila 0 como encabezados
     * mete en el snapshot el NOMBRE de una persona del seed: se rompería al
     * regenerarlo, y además no describe nada. La primera fila con más de dos
     * celdas escritas es la de las etiquetas, que sí son fijas.
     */
    private function filaDeEncabezados(array $filas): int
    {
        foreach ($filas as $i => $fila) {
            $escritas = array_filter($fila, fn ($celda) => $celda !== null && trim((string) $celda) !== '');

            if (count($escritas) > 2) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Los títulos de hoja llevan el nombre del grupo, que sale del seed. Se
     * dejan tal cual salvo los dígitos, para que regenerar el seed con otro
     * grupo no rompa el snapshot por un número.
     */
    private function sinNumeros(string $titulo): string
    {
        return preg_replace('/\d+/', '#', $titulo);
    }
}
