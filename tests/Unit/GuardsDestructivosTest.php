<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guardas de forma, no de instancia.
 *
 * Este PR cerró dos clases de agujero. Cada una existía en varios sitios y solo
 * se encontraron todos al buscar la FORMA en vez de la instancia concreta que se
 * había reportado. Estas pruebas impiden que vuelvan a aparecer.
 *
 * No arrancan Laravel ni tocan la base de datos: leen el código fuente. Son
 * baratas y corren en cualquier entorno.
 *
 * Si una de estas pruebas falla, la respuesta correcta casi nunca es relajar la
 * prueba: es ponerle la comprobación que falta al método nuevo.
 */
class GuardsDestructivosTest extends TestCase
{
    private const CONTROLADORES = __DIR__.'/../../app/Http/Controllers';

    /**
     * Todo forceDelete() es borrado físico, y el esquema tiene las FK en
     * ON DELETE CASCADE sin un solo RESTRICT. El alcance real medido:
     *
     *   years        59 tablas, 7 saltos
     *   profesores   31 tablas, 7 saltos
     *   grupos       27 tablas, 6 saltos (llega a notas, 1.163.307 filas)
     *   alumnos      20 tablas, 4 saltos
     *
     * Tener un token no puede bastar para eso.
     */
    public function test_todo_forcedelete_comprueba_autorizacion(): void
    {
        $marcadores = ['Autoriza::', 'pueden_editar_notas', 'is_superuser', 'isSecretario'];
        $sinGuarda = [];

        foreach ($this->metodosDeControladores() as [$archivo, $nombre, $cuerpo]) {
            if (! str_contains($cuerpo, 'forceDelete(')) {
                continue;
            }

            foreach ($marcadores as $marcador) {
                if (str_contains($cuerpo, $marcador)) {
                    continue 2;
                }
            }

            $sinGuarda[] = "$archivo::$nombre";
        }

        $this->assertSame([], $sinGuarda, implode("\n", array_merge(
            ['Hay métodos que hacen forceDelete() sin comprobar autorización:'],
            array_map(fn ($m) => "  - $m", $sinGuarda),
            ['', 'Usa App\Support\Autoriza, que tiene el criterio en un solo sitio.']
        )));
    }

    /**
     * Las dos rutas de subida guardaban con el nombre que enviaba el cliente,
     * sin validar la extensión, dentro de public/. Se podía dejar un .php bajo el
     * document root y ejecutarlo. El .htaccess no protege: el contenedor usa nginx
     * y lo ignora.
     */
    public function test_toda_escritura_de_archivo_subido_pasa_por_safeupload(): void
    {
        $escrituras = ['->move(', '->storeAs(', 'move_uploaded_file('];
        $sinValidar = [];

        foreach ($this->metodosDeControladores() as [$archivo, $nombre, $cuerpo]) {
            foreach ($escrituras as $escritura) {
                if (str_contains($cuerpo, $escritura) && ! str_contains($cuerpo, 'SafeUpload::')) {
                    $sinValidar[] = "$archivo::$nombre  ($escritura)";
                    break;
                }
            }
        }

        $this->assertSame([], $sinValidar, implode("\n", array_merge(
            ['Hay escrituras de archivos subidos que no pasan por SafeUpload:'],
            array_map(fn ($m) => "  - $m", $sinValidar),
            ['', 'Usa App\Support\SafeUpload::nombreDisponible() para validar extensión y sanear el nombre.']
        )));
    }

    /**
     * El nombre que manda el cliente no puede decidir cómo aterriza el archivo.
     *
     * Esta prueba existe por un error concreto: el primer barrido de subidas buscó
     * getClientOriginalExtension y NO getClientOriginalName, así que encontró la
     * forma que se imaginaba y no la que había. Aquí queda cerrada la de verdad.
     */
    public function test_el_nombre_del_cliente_solo_se_usa_dentro_de_safeupload(): void
    {
        $usos = [];

        foreach ($this->metodosDeControladores() as [$archivo, $nombre, $cuerpo]) {
            if (str_contains($cuerpo, 'getClientOriginalName(')) {
                $usos[] = "$archivo::$nombre";
            }
        }

        $this->assertSame([], $usos, implode("\n", array_merge(
            ['getClientOriginalName() fuera de SafeUpload:'],
            array_map(fn ($m) => "  - $m", $usos),
            ['', 'El nombre del cliente se sanea en SafeUpload; no debe usarse directo.']
        )));
    }

    /**
     * El nombre de una columna no se puede parametrizar en SQL, así que cuando se concatena hay
     * que validarlo antes. Diez endpoints armaban
     *
     *     UPDATE <tabla> SET '.$propiedad.'=:valor WHERE id=:id
     *
     * con la propiedad tal cual la mandaba el navegador. El valor iba parametrizado y eso daba
     * sensación de seguridad, pero el nombre de la columna no, y por ahí se escribía en la fila lo
     * que se quisiera. Bastaba tener sesión.
     *
     * Se buscó la FORMA y no la instancia: el primer hallazgo fue uno solo, en GuardarAlumno, y
     * mirando el patrón aparecieron nueve más en ocho archivos distintos.
     */
    public function test_toda_columna_concatenada_en_un_set_esta_validada(): void
    {
        // Métodos revisados uno a uno donde la concatenación NO la decide el cliente.
        $revisados = [
            // La interpolación está dentro de if (Request::input('propiedad') == 'is_active'),
            // así que sólo puede valer esa cadena literal.
            'ProfesoresController.php::putGuardarValor',

            // Método roto: $propiedad, $valor, $user_id y $user no se definen en ninguna parte.
            // No lee nada del cliente, así que no hay inyección; falla con 500 por su cuenta.
            'UniformesController.php::putGuardarCambios',
        ];

        $sinValidar = [];

        foreach ($this->metodosDeControladores() as [$archivo, $nombre, $cuerpo]) {
            if (! preg_match('/SET\s*[\'"]\s*\.\s*\$/', $cuerpo)) {
                continue;
            }

            if (str_contains($cuerpo, 'ColumnaSegura::')) {
                continue;
            }

            if (in_array("$archivo::$nombre", $revisados, true)) {
                continue;
            }

            $sinValidar[] = "$archivo::$nombre";
        }

        $this->assertSame([], $sinValidar, implode("\n", array_merge(
            ['Hay columnas concatenadas en un UPDATE ... SET sin validar:'],
            array_map(fn ($m) => "  - $m", $sinValidar),
            ['', 'Usa App\Support\ColumnaSegura::exigir($tabla, $columna), que valida la forma y',
                'comprueba contra el esquema real que la columna exista.']
        )));
    }

    /**
     * Quién firma una fila lo decide el servidor, nunca el cliente.
     *
     * Cuatro INSERT de `ausencias` ponían `created_by` con lo que llegaba en el
     * cuerpo de la petición. El que llama está autenticado —unos por token,
     * otros por credenciales en el cuerpo, que es la excepción del lector de
     * tardanzas— así que no es acceso abierto: es suplantación autenticada. El
     * sistema sabe quién eres y aun así firma la fila con el id que le mandes.
     *
     * No es teórico. Lo reportó la sesión de la app Flutter después de arreglar
     * el mismo fallo del lado del cliente: el logout no limpiaba el usuario y
     * las tardanzas quedaban firmadas por el docente anterior.
     */
    public function test_ninguna_columna_de_autoria_sale_de_la_peticion(): void
    {
        $columnas = ['created_by', 'updated_by', 'deleted_by'];
        $sospechosos = [];

        foreach ($this->metodosDeControladores() as [$archivo, $nombre, $cuerpo]) {
            foreach ($columnas as $columna) {
                // `':created_by' => Request::input(...)` y `->created_by = Request::input(...)`
                $patron = '/[\'"]?:?'.$columna.'[\'"]?\s*(?:=>|=)\s*[^,;\n]*Request::(input|get|all)\s*\(/';

                if (preg_match($patron, $cuerpo)) {
                    $sospechosos[] = "$archivo::$nombre -> $columna";
                }
            }
        }

        $this->assertSame([], $sospechosos, implode("\n", array_merge(
            ['Hay columnas de autoría que salen del cuerpo de la petición:'],
            array_map(fn ($m) => "  - $m", $sospechosos),
            ['', 'El servidor ya sabe quién llama. Usa el usuario resuelto:',
                '  User::fromToken()->user_id   (contexto)  o  $user->id  (modelo App\\User)']
        )));
    }

    /**
     * `$this->user()` en un controlador que no tiene ese método.
     *
     * `Illuminate\Routing\Controller` define `__call()`, así que llamar a un
     * método que no existe es sintácticamente válido y **Larastan no lo ve ni
     * en el nivel más alto**: para el análisis, la clase «podría» responder.
     * En ejecución lanza BadMethodCallException y el endpoint responde 500.
     *
     * Pasaba en `AsistenciasController::putEliminarAusencia` y `::putPonerAusencia`,
     * comprobado golpeándolos: 500 con «Method ...::user does not exist».
     *
     * El trait `Concerns\ResuelveElUsuario` expone `$this->user` como PROPIEDAD,
     * no como método, y de ahí sale la confusión.
     */
    public function test_nadie_llama_a_this_user_como_metodo(): void
    {
        $sospechosos = [];

        foreach ($this->metodosDeControladores() as [$archivo, $nombre, $cuerpo]) {
            if (! preg_match('/\$this->user\s*\(/', $cuerpo)) {
                continue;
            }

            // TSubirController sí lo define: autentica con las credenciales que
            // el lector manda en cada petición.
            $fuente = file_get_contents(self::CONTROLADORES.'/'.$archivo);

            if (preg_match('/function\s+user\s*\(/', $fuente)) {
                continue;
            }

            $sospechosos[] = "$archivo::$nombre";
        }

        $this->assertSame([], $sospechosos, implode("\n", array_merge(
            ['Hay métodos que llaman a $this->user() sin que la clase lo defina:'],
            array_map(fn ($m) => "  - $m", $sospechosos),
            ['', 'Es 500 en ejecución. Usa User::fromToken(), o el trait',
                'Concerns\\ResuelveElUsuario, que expone $this->user como propiedad.']
        )));
    }

    /**
     * Devuelve [archivoRelativo, nombreMetodo, cuerpoSinComentarios] de cada método
     * de cada controlador.
     *
     * Los comentarios se quitan con el tokenizador de PHP, no con expresiones
     * regulares: hay código muerto dentro de bloques /* *\/ que si no se descarta
     * produce falsos positivos.
     *
     * @return iterable<array{0:string,1:string,2:string}>
     */
    private function metodosDeControladores(): iterable
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::CONTROLADORES)
        );

        foreach ($iterador as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $fuente = $this->sinComentarios(file_get_contents($archivo->getPathname()));
            $relativo = str_replace(self::CONTROLADORES.'/', '', $archivo->getPathname());

            preg_match_all('/function\s+(\w+)\s*\([^)]*\)\s*\{/', $fuente, $coincidencias, PREG_OFFSET_CAPTURE);

            foreach ($coincidencias[0] as $i => [, $inicio]) {
                $cuerpo = $this->cuerpoDesde($fuente, strpos($fuente, '{', $inicio));

                if ($cuerpo !== null) {
                    yield [$relativo, $coincidencias[1][$i][0], $cuerpo];
                }
            }
        }
    }

    private function sinComentarios(string $fuente): string
    {
        $limpio = '';

        foreach (token_get_all($fuente) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $limpio .= $token[1];

                continue;
            }
            $limpio .= $token;
        }

        return $limpio;
    }

    private function cuerpoDesde(string $fuente, int $abre): ?string
    {
        $profundidad = 0;
        $largo = strlen($fuente);

        for ($i = $abre; $i < $largo; $i++) {
            if ($fuente[$i] === '{') {
                $profundidad++;
            } elseif ($fuente[$i] === '}') {
                $profundidad--;

                if ($profundidad === 0) {
                    return substr($fuente, $abre, $i - $abre + 1);
                }
            }
        }

        return null;
    }
}
