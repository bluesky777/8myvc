<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El muestreo de la P2: una lectura por controlador, para los que no tenía
 * ninguna.
 *
 * El P0 y el P1 fueron a lo que el colegio imprime y entrega. Esto es el resto:
 * los catálogos, los listados y las papeleras que `myvc_front` pide para llenar
 * un desplegable o pintar una tabla. Ninguno es dramático por separado; lo que
 * los hace valer es que son 66 rutas que **nadie estaba mirando**, y que la
 * migración las atraviesa igual que a las otras.
 *
 * La lista sale de medir, no de leer: `tools/cobertura-de-rutas.py` cruza las
 * rutas que la suite ejecuta de verdad contra `route:list`. Antes de esto la
 * respuesta era «96 de 539 rutas tienen su respuesta comprobada».
 *
 * Se eligen las lecturas SIN parámetro a propósito. Una ruta con `{grupo_id}`
 * necesita además que el id exista en el seed y encaje con el año del usuario,
 * y esa es la parte cara; las de aquí solo necesitan un token. Las de parámetro
 * van en el segundo bloque.
 *
 * Los tres grupos de abajo no son una clasificación cómoda, son el resultado del
 * sondeo. Separarlos es lo que impide el fallo que el P0 ya cometió una vez:
 * **una lista vacía pasa cualquier comprobación**. Si una ruta que hoy sale
 * vacía empieza a traer datos, este test falla y hay que moverla de grupo —que
 * es exactamente lo que se quiere, porque entonces sí se le puede mirar la forma.
 */
class MuestreoDeLecturasTest extends CasoDeContrato
{
    /**
     * Las que devuelven datos con el seed. Se les guarda la forma.
     *
     * @return array<string, array{string}>
     */
    public static function lecturasConDatos(): array
    {
        $rutas = [
            // Catálogos: lo que llena un desplegable. Cambia poco y se nota mucho.
            'api/areas',
            'api/asignaturas',
            'api/contratos',
            'api/escalas',
            'api/frases',
            'api/grados',
            'api/materias',
            'api/niveles_educativos',
            'api/paises',
            'api/tiposdocumento',
            'api/years',
            'api/years/colegio',

            // Personas.
            'api/alumnos',
            'api/alumnos/sin-matriculas',
            'api/profesores',
            'api/profesores/conyears',
            'api/profesores/todos',
            'api/perfiles/usernames',
            'api/perfiles/usuariosall',

            // Y ésta NO es de personas aunque lo parezca por el prefijo: el índice
            // de `perfiles` devuelve los GRUPOS del año — se ve en su propio
            // snapshot, `muestreo-perfiles.json`, que trae `grado_id` y
            // `titular_id`. Estaba clasificada entre las personas, y una lista que
            // agrupa por el nombre del recurso hereda el nombre equivocado. Es uno
            // de los seis métodos de `PerfilesController` que operan sobre grupo
            // (§130); `AutorizacionTest` ya lo dice en su lista de excepciones de
            // familia.
            'api/perfiles',

            // Comportamiento y convivencia.
            'api/comportamiento',
            'api/definiciones_comportamiento',
            'api/nota_comportamiento',

            // Listados que el profesor imprime.
            'api/planillas/listas-personalizadas',
            'api/planillas/ver-ausencias',
            'api/planillas/ver-simat',

            // Votaciones estudiantiles.
            'api/candidatos',
            'api/candidatos/conaspiraciones',
            'api/participantes/allinscritos',
            'api/votos',
            'api/votaciones/en-accion-inscrito',
            'api/eventos',

            // El resto, uno por controlador.
            'api/ChangesAsked/to-me',
            'api/auth/me',
            'api/certificados',
            'api/folios/iniciar',
            'api/grupos/con-paises-tipos-next-year',
            'api/piars-config',
            'api/publicaciones/ultimas',
            'api/roles',
            'api/roles/rolesconpermisos',
            'api/unidades/trashed',
            'api/years/trashed',
        ];

        return array_combine($rutas, array_map(fn ($r) => [$r], $rutas));
    }

    /**
     * Las que salen vacías con el seed, y por qué.
     *
     * No se les puede mirar la forma: no hay forma que mirar. Lo que se fija es
     * que **siguen vacías**, para que el día que traigan algo el test lo diga en
     * vez de seguir pasando en silencio. Ese es el fallo que el P0 cometió con
     * `myimages` y con la lista de deudores.
     *
     * @return array<string, array{string, string}>
     */
    public static function lecturasVacias(): array
    {
        $casos = [
            'api/alumnos/trashed' => 'el seed no trae alumnos borrados; los llena test_la_papelera_de_alumnos_devuelve_lo_borrado',
            'api/asignaturas/papelera' => 'ninguna asignatura borrada en el seed',
            'api/asignaturas/listasignaturas-alone' => 'asignaturas sin grupo; en el seed todas lo tienen',
            'api/editnota/trashed' => 'ninguna nota borrada',
            'api/perfiles/trashed' => 'ningún perfil borrado',
            'api/subunidades/trashed' => 'ninguna subunidad borrada',
            'api/definitivas_periodos' => 'lee `definitivas_periodos`, que el generador no copia',
            'api/definitivas_periodos/arreglar-duplicados' => 'la misma tabla',
            'api/ciudades/by-departamento' => 'sin `departamento_id` en la URL no filtra nada, y la ruta no lo lleva',
            'api/votaciones' => 'el seed no tiene votaciones del año del usuario',
            'api/votaciones/actual' => 'ninguna votación marcada como actual',
            'api/votaciones/actual-in-action' => 'sin votación actual no hay ninguna en marcha; devuelve el cuerpo vacío, no `[]`',
            'api/asistencias/datos-solo-alumnos' => 'devuelve los grupos del profesor que pregunta, y quien pregunta aquí es un Usuario',
            'api/asistencias-app/datos-solo-alumnos' => 'la misma consulta, para la app de Flutter',
        ];

        $rutas = array_keys($casos);

        // Con la clave puesta, y no como una lista: los otros dos proveedores de
        // esta clase la llevan, y el nombre del caso es lo que hace legible tanto
        // el fallo como el informe de cobertura, que anota `nameWithDataSet()`.
        return array_combine($rutas, array_map(fn ($r) => [$r, $casos[$r]], $rutas));
    }

    /**
     * Las que fallan siempre, con el error exacto.
     *
     * Se fija el mensaje, no solo el 500. Un test que solo mirara el código
     * seguiría pasando si el error cambiara de sitio, y entonces no diría nada:
     * lo que importa aquí es que **la migración no las cambió**, porque ya
     * estaban rotas antes de empezar.
     *
     * Ninguna es reciente. Las cuatro llevan rotas desde que se escribieron, y
     * están en docs/migracion/05-codigo-muerto-y-roto.md §6.5 y §8.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function lecturasRotas(): array
    {
        return [
            'editnota/detailed-notas-year usa $grupo_id sin recibirlo' => [
                'api/editnota/detailed-notas-year', 500, 'Undefined variable $grupo_id',
            ],
            'importar llama a la API de maatwebsite 2.x' => [
                'api/importar', 500, 'pathinfo(): Argument #1 ($path) must be of type string, Closure given',
            ],
            'profesores/trashed ordena por una tabla que no está en el FROM' => [
                'api/profesores/trashed', 500, "Unknown column 'p.nombres' in 'order clause'",
            ],
            'votaciones/unsignedsusers lee una columna que no existe' => [
                'api/votaciones/unsignedsusers', 500, "Unknown column 'p.user_id' in 'field list'",
            ],
        ];
    }

    #[DataProvider('lecturasConDatos')]
    public function test_la_lectura_conserva_su_forma(string $uri): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->getJson('/'.$uri);

        $r->assertStatus(200);

        $cuerpo = json_decode($r->getContent(), true);

        $this->assertNotNull($cuerpo, "'{$uri}' no devolvió JSON.");
        $this->assertTrue(
            $this->traeDatos($cuerpo),
            "'{$uri}' respondió 200 pero sin nada dentro. Un snapshot de eso pasa\n".
            'siempre. Si es lo esperado, muévela a lecturasVacias() con su motivo.'
        );

        $this->compararConInstantanea(
            'muestreo-'.str_replace(['api/', '/'], ['', '-'], $uri),
            $this->formaUnida($cuerpo)
        );
    }

    #[DataProvider('lecturasVacias')]
    public function test_la_lectura_sigue_saliendo_vacia(string $uri, string $motivo): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->getJson('/'.$uri);

        $r->assertStatus(200);

        $this->assertFalse(
            $this->traeDatos(json_decode($r->getContent(), true)),
            "'{$uri}' ya trae datos, y estaba anotada como vacía porque {$motivo}.\n".
            'Muévela a lecturasConDatos() para que se le guarde la forma.'
        );
    }

    #[DataProvider('lecturasRotas')]
    public function test_la_lectura_rota_sigue_rota(string $uri, int $codigo, string $error): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->getJson('/'.$uri);

        $r->assertStatus($codigo);

        $this->assertStringContainsString(
            $error,
            (string) (json_decode($r->getContent(), true)['message'] ?? ''),
            "'{$uri}' sigue fallando, pero por otro motivo que el que se anotó."
        );
    }

    /**
     * Las tres pantallas de informes que devuelven la cadena 'Holaa'.
     *
     * Es el `getIndex` de andamio que quedó cuando el controlador pasó a servir
     * solo sus métodos con parámetros. No molesta a nadie —`myvc_front` no las
     * llama— pero son rutas publicadas de la API, y quien las encuentre en el
     * inventario merece saber que no son un error de despliegue.
     */
    public function test_los_tres_indices_de_informes_siguen_devolviendo_el_andamio(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        foreach (['api/observador', 'api/simat', 'api/excel-docentes'] as $uri) {
            $r = $this->withToken($token)->get('/'.$uri);

            $r->assertStatus(200);
            $this->assertSame('Holaa', $r->getContent(), "'{$uri}' ya no devuelve el andamio.");
        }
    }

    /**
     * La papelera de alumnos, con un alumno borrado de verdad.
     *
     * Sale vacía con el seed, así que el test se crea el dato. Es la única
     * manera de comprobar una papelera: la consulta lleva `deleted_at IS NOT
     * NULL`, y contra una tabla sin borrados devuelve lo mismo si la escribes
     * bien que si la escribes mal.
     *
     * Y vale la pena mirarla: `ProfesoresController@getTrashed` es esta misma
     * consulta copiada, con el `order by` de una tabla que no está en el FROM.
     * La copia lleva rota desde que se escribió y nadie lo notó porque nadie la
     * llamaba.
     *
     * El borrado se deshace con la transacción del test.
     */
    public function test_la_papelera_de_alumnos_devuelve_lo_borrado(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne('SELECT a.id, a.nombres FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.grupo_id = ?
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($alumno, 'El grupo del seed no tiene alumnos.');

        DB::update('UPDATE alumnos SET deleted_at = NOW() WHERE id = ?', [$alumno->id]);

        $r = $this->withToken($token)->getJson('/api/alumnos/trashed');

        $r->assertStatus(200);

        $ids = array_column(json_decode($r->getContent(), true), 'alumno_id');

        $this->assertContains(
            $alumno->id,
            $ids,
            'La papelera de alumnos no devuelve el alumno que se acaba de borrar.'
        );

        $this->compararConInstantanea(
            'muestreo-alumnos-trashed',
            $this->formaUnida(json_decode($r->getContent(), true))
        );
    }

    /**
     * El listado de participantes exige que haya una votación en curso.
     *
     * No está roto —es una precondición del módulo, y el mensaje se lo enseña al
     * usuario `myvc_front`—, pero responde 400 con el seed y por eso no cabe en
     * ninguno de los tres grupos de arriba. Es la única lectura del controlador,
     * así que sin esto `VtParticipantesController` se queda sin nada.
     */
    public function test_los_participantes_piden_una_votacion_en_curso(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->withToken($token)->getJson('/api/participantes');

        $r->assertStatus(400);

        $this->assertSame(
            'Debe haber un evento establecido como actual.',
            json_decode($r->getContent(), true)['message'] ?? null
        );
    }

    /**
     * ¿Hay algo dentro?
     *
     * `[]` no trae datos, y `{"grupos": []}` tampoco: un objeto cuyas listas
     * están todas vacías es la misma nada con una envoltura. Es lo que devuelven
     * hoy `asistencias/datos-solo-alumnos` y `grupos/con-paises-tipos-next-year`,
     * y por eso la comprobación mira dentro en vez de quedarse en el primer nivel.
     */
    private function traeDatos($cuerpo): bool
    {
        if ($cuerpo === null || $cuerpo === [] || $cuerpo === '') {
            return false;
        }

        if (! is_array($cuerpo)) {
            return true;
        }

        foreach ($cuerpo as $valor) {
            if ($this->traeDatos($valor)) {
                return true;
            }
        }

        return false;
    }
}
