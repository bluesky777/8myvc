<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use App\Support\Reloj;
use App\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * La tabla `auditoria` y su escritor único.
 *
 * Es la fase 3 de [18-auditoria.md](../../docs/migracion/18-auditoria.md), y estos
 * tests miran **la fila que queda escrita, con su hora**, no el resultado de la
 * llamada. Es la regla que ha encontrado todo lo que se ha encontrado en este
 * repositorio, y aquí importa el doble: un escritor de auditoría que devuelva
 * «bien» sin haber escrito nada es exactamente el instrumento que tranquiliza sin
 * medir.
 *
 * Los que fijan una regla que se deshace sola —el append-only, el vocabulario
 * cerrado, la clave del contexto— dicen dentro cuántas cosas revisaron: un «0
 * encontrados» no distingue *«revisé 164 ficheros y no hay»* de *«no revisé
 * nada»*, y de las dos lecturas la falsa es la que hace archivar el asunto.
 */
class AuditoriaEscritorUnicoTest extends CasoDeContrato
{
    // ─────────────────────────────────────────────────────────────────────
    // La tabla
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Append-only por esquema, que es la mitad que no depende de que nadie se
     * acuerde.
     *
     * Sin `updated_at` no hay dónde anotar una edición y sin `deleted_at` no hay
     * borrado lógico que esconder la fila. Es el cuarto problema de `bitacoras`
     * (§3): hoy `DELETE bitacoras/destroy/{id}` va con `auth.personal`, o sea que
     * **cualquiera del personal puede borrar el registro que lo vigila, incluido
     * el suyo**, y `getIndex` filtra `deleted_at is null`, así que desaparece de
     * la vista sin dejar rastro.
     */
    public function test_la_tabla_no_tiene_donde_editarse_ni_borrarse(): void
    {
        $this->assertTrue(Schema::hasTable('auditoria'),
            "La tabla `auditoria` no existe en la base de tests.\n".
            'Reconstrúyela: DB_TEST_DATABASE=<la tuya> tools/construir-bd-test.sh');

        foreach (['updated_at', 'deleted_at'] as $columna) {
            $this->assertFalse(Schema::hasColumn('auditoria', $columna),
                "`auditoria` no puede tener `{$columna}`: una línea de auditoría no se edita ".
                'y no se borra (18 §4.4). La retención se resuelve archivando por fecha, fase 6.');
        }

        $this->assertTrue(Schema::hasColumn('auditoria', 'ocurrido_en'),
            'Una sola columna de tiempo, y es `ocurrido_en`.');
    }

    /**
     * `ocurrido_en` es `DATETIME(3)` y **no** `TIMESTAMP`, que es la mitad del
     * arreglo de las horas raras que el código solo no puede dar.
     *
     * Un `TIMESTAMP` convierte al escribir y al leer con la zona de la sesión de
     * MySQL, y aquí nadie la fija: `@@session.time_zone = SYSTEM`, o sea la del
     * hosting, y son dieciséis cuentas de cPanel distintas. Con `TIMESTAMP` la
     * misma fila se lee con una hora distinta en dos colegios, y si un hosting
     * cambia su zona **todas las filas históricas se desplazan a la vez** sin que
     * nadie toque la base.
     *
     * Las milésimas tampoco son adorno: dos notas tecleadas en el mismo segundo
     * son dos líneas distintas del historial, y con precisión de segundo no se
     * sabe cuál fue primero.
     */
    public function test_la_hora_no_la_convierte_el_hosting(): void
    {
        $tipo = strtolower($this->tipoDeColumna('auditoria', 'ocurrido_en'));

        $this->assertSame('datetime(3)', $tipo,
            "`auditoria.ocurrido_en` es '{$tipo}' y tiene que ser datetime(3).\n".
            'Con TIMESTAMP la zona la pone el hosting de cada colegio (18 §1.2) y el Reloj '.
            'deja de garantizar nada; sin las milésimas dos notas del mismo segundo no se '.
            'pueden ordenar.');
    }

    /**
     * `actor_user_id` NULLABLE, y esto es un test y no un comentario porque el
     * plan lo tuvo mal escrito hasta que el front lo destapó.
     *
     * Un `intento_login` fallido **no tiene actor autenticado** — son 52 de las 85
     * filas del seed, y `mis-sesiones` las pinta. Con la columna NOT NULL o no
     * caben, o vuelven a entrar con el `created_by = 0` que hoy les pone
     * `Login.php`: un id que no existe disfrazado de id que sí.
     */
    public function test_un_intento_de_login_cabe_sin_actor(): void
    {
        $id = Auditoria::registrar()
            ->denegado('intento_login')
            ->sinActor('jperez')
            ->guardar();

        $this->assertNotNull($id, 'Una denegación sin actor tiene que poder escribirse.');

        $fila = $this->fila($id);

        $this->assertNull($fila->actor_user_id,
            'Un login fallido no tiene actor. Un 0 aquí sería un id que no existe con forma de id.');
        $this->assertSame('jperez', $fila->actor_intentado);
        $this->assertNull($fila->actor_nombre,
            '`actor_nombre` dice quién fue, y en un intento fallido no se sabe: el username '.
            'tecleado va en `actor_intentado` y no aquí.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Append-only: lo que ningún esquema puede fijar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Que **este código** no edite ni borre una línea de auditoría.
     *
     * El detector recorre `app/` entero y dice cuántos ficheros revisó, porque un
     * «0 encontrados» sin población no distingue *«no hay»* de *«no miré»*.
     *
     * Y se comprueba **al revés antes de creerle**: se le pasan cuatro cadenas que
     * sí son lo que busca, y si no las reconociera, su cero de arriba no querría
     * decir nada. Es la segunda mitad de la regla de CLAUDE.md — el primer sitio
     * donde mirar cuando el número sale raro es el detector.
     *
     * **Lo que este test NO promete**: no impide un `UPDATE` a mano en phpMyAdmin.
     * Eso sólo se cierra quitándole al usuario de MySQL los permisos de `UPDATE` y
     * `DELETE` sobre esta tabla, que es una decisión de los dieciséis hostings.
     * Queda dicho para que nadie lo cuente como cerrado.
     */
    public function test_ningun_sitio_de_app_edita_ni_borra_la_auditoria(): void
    {
        $patron = '/\b(update|delete\s+from)\s+`?auditoria`?\b|'.
                  '::table\([\'"]auditoria[\'"]\)\s*(->[a-zA-Z]+\([^)]*\)\s*)*->\s*(update|delete)\b/i';

        foreach ([
            "DB::update('update auditoria set resumen = ? where id = ?', [1, 2]);",
            'DB::delete("delete from auditoria where id = ?", [1]);',
            "DB::table('auditoria')->where('id', 1)->delete();",
            "DB::table('auditoria')->where('id', 1)->update(['resumen' => 'x']);",
        ] as $ejemplo) {
            $this->assertMatchesRegularExpression($patron, $ejemplo,
                "El detector no reconoce una edición que sí lo es:\n  {$ejemplo}\n".
                'Mientras no la reconozca, su «0 encontrados» de abajo no dice nada.');
        }

        $revisados = 0;
        $culpables = [];

        foreach ($this->ficherosPhpDe(base_path('app')) as $fichero) {
            $revisados++;

            if (preg_match($patron, (string) file_get_contents($fichero))) {
                $culpables[] = str_replace(base_path().'/', '', $fichero);
            }
        }

        $this->assertGreaterThan(100, $revisados,
            "El detector sólo revisó {$revisados} ficheros de `app/`, y ahí hay más de cien. ".
            'Su resultado no vale: el primer sitio donde mirar es el detector.');

        $this->assertSame([], $culpables,
            "Revisados {$revisados} ficheros de `app/`. La auditoría es append-only (18 §4.4): ".
            "nadie la edita y nadie la borra.\nCulpables: ".implode(', ', $culpables));
    }

    /**
     * Que el escritor **no tenga dónde recibir «cuántas filas salieron»**, que es
     * la primera regla de la §4.3 puesta en la forma de la clase.
     *
     * `DB::update` devuelve filas **afectadas**, y MySQL devuelve 0 cuando el
     * valor no cambia. Un parámetro `$filas` o `$ok` en esta API sería la
     * invitación a colgar la auditoría de un `if ($res)`, y con eso un guardado
     * correcto —85 encima de 85— quedaría registrado como fallido. Está medido:
     * cuatro sitios y seis rutas contestan hoy 'No guardado' con 200 por
     * exactamente ese motivo (09 §13).
     */
    public function test_el_escritor_no_tiene_donde_meter_las_filas_afectadas(): void
    {
        $prohibidos = ['filas', 'afectadas', 'resultado', 'ok', 'exito', 'guardado', 'res'];
        $metodos = (new ReflectionClass(Auditoria::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        $this->assertGreaterThan(10, count($metodos),
            'Se esperaban los métodos de la API fluida; si son menos, este detector no revisó nada.');

        foreach ($metodos as $metodo) {
            foreach ($metodo->getParameters() as $parametro) {
                $this->assertNotContains(strtolower($parametro->getName()), $prohibidos,
                    "`Auditoria::{$metodo->getName()}()` recibe \${$parametro->getName()}.\n".
                    'La escritura ocurrió porque no hubo excepción, nunca porque haya filas '.
                    '(18 §4.3). Esta clase no tiene dónde recibir eso, y no es un olvido.');
            }
        }
    }

    /**
     * Y la misma regla comprobada por el resultado, que es lo que de verdad
     * importa: **un reguardado sin cambio sí se registra.**
     *
     * Se hace el `UPDATE` de verdad, con el mismo valor que ya estaba, para que
     * MySQL devuelva **0 filas afectadas** sin que el guardado tenga nada de malo.
     * Alguien tocó esa nota, y «quién la tocó» es la pregunta que la tabla existe
     * para contestar.
     *
     * Se reconoce solo, sin columna nueva: `valor_anterior` y `valor_nuevo` quedan
     * iguales y la pantalla puede filtrarlos.
     */
    public function test_un_reguardado_sin_cambio_se_registra_igual(): void
    {
        $nota = DB::selectOne('SELECT id, nota FROM notas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($nota, 'El seed no tiene ninguna nota viva.');

        $afectadas = DB::update('UPDATE notas SET nota = ? WHERE id = ?', [$nota->nota, $nota->id]);

        $this->assertSame(0, $afectadas,
            'Este test se apoya en que MySQL devuelve 0 filas cuando el UPDATE no cambia nada. '.
            'Si devuelve 1, la conexión lleva CLIENT_FOUND_ROWS y lo que este test mide ya no es eso.');

        $id = Auditoria::registrar()
            ->editar('nota', (int) $nota->id)
            ->de($nota->nota)->a($nota->nota)
            ->guardar();

        $this->assertNotNull($id,
            'Un reguardado sin cambio es una escritura correcta con 0 filas, y tiene que dejar línea.');

        $fila = $this->fila($id);

        $this->assertSame($fila->valor_anterior, $fila->valor_nuevo,
            'Un reguardado sin cambio se reconoce porque los dos valores son iguales. '.
            'No hace falta columna nueva.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // El vocabulario cerrado
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cada `entidad` del vocabulario nombra una tabla que existe — o dice a
     * propósito que no es una fila.
     *
     * `affected_element_type` de `bitacoras` es texto libre y hoy tiene `Nota`,
     * `NF_UPDATE`, `Nueva subunidad` y `AlumnoPideAjeno:user_id` conviviendo: tres
     * convenciones en diez escrituras. Una pantalla que agrupe por tipo tiene que
     * conocer la lista de memoria, y un tipo mal escrito se pierde en silencio.
     *
     * Los `null` son los cuatro que no son filas de nada —dos sucesos de seguridad
     * y los dos recursos que protegen los middlewares—, y están declarados en vez
     * de omitidos precisamente para que este test distinga *«no tiene tabla a
     * propósito»* de *«se me olvidó»*.
     */
    public function test_cada_entidad_nombra_una_tabla_que_existe(): void
    {
        $conTabla = array_filter(Auditoria::ENTIDADES);

        $this->assertGreaterThan(10, count($conTabla),
            'Se esperaba el vocabulario de los siete dominios de la fase 4; con menos, '.
            'este test no está revisando lo que dice.');

        foreach ($conTabla as $entidad => $tabla) {
            $this->assertTrue(Schema::hasTable($tabla),
                "La entidad '{$entidad}' dice representar la tabla `{$tabla}`, que no existe. ".
                'O el nombre está mal escrito, o la tabla se llama de otra forma.');
        }

        foreach (array_keys(Auditoria::ENTIDADES) as $entidad) {
            $this->assertLessThanOrEqual(40, strlen((string) $entidad),
                "La entidad '{$entidad}' no cabe en `auditoria.entidad`, que es varchar(40).");
        }
    }

    /** Una acción que no está en la lista no escribe nada, y lo dice en el log. */
    public function test_una_accion_desconocida_no_escribe_nada(): void
    {
        Log::spy();

        $antes = DB::table('auditoria')->count();

        $id = (new ReflectionClass(Auditoria::class));
        $constructor = $id->getMethod('accion');
        $constructor->setAccessible(true);

        $linea = Auditoria::registrar();
        $constructor->invoke($linea, 'inventada', 'nota', 1);

        $this->assertNull($linea->guardar(),
            'Una acción fuera del vocabulario no puede escribir una línea.');

        $this->assertSame($antes, DB::table('auditoria')->count(),
            'No se escribió nada, y eso incluye no escribir una línea a medias.');

        Log::shouldHaveReceived('error')->once();
    }

    /** Y una entidad que no está en la lista, igual: no escribe y no revienta la petición. */
    public function test_una_entidad_desconocida_no_escribe_nada(): void
    {
        Log::spy();

        $antes = DB::table('auditoria')->count();

        $this->assertNull(
            Auditoria::registrar()->editar('cosa_inventada', 1)->guardar(),
            'Una entidad fuera del vocabulario no puede escribir una línea.'
        );

        $this->assertSame($antes, DB::table('auditoria')->count());

        Log::shouldHaveReceived('error')->once();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Quién, y de qué sesión
    // ─────────────────────────────────────────────────────────────────────

    /**
     * El acoplamiento con `App\User`, comprobado por reflexión.
     *
     * `Auditoria` lee el contexto de los atributos de la petición bajo la misma
     * clave con la que lo memoriza `User::fromToken()`, que allí es una constante
     * **privada**. No se llama a `fromToken()` porque **aborta con 401 cuando no
     * hay token**, y hay dos casos que tienen que poder escribir sin él: un
     * `intento_login` fallido y el comando de consola.
     *
     * Si esa constante se renombra allí y aquí no, **todas las líneas se
     * escribirían sin actor y sin ningún error visible** — la forma exacta de
     * fallo que este trabajo viene a cerrar. Por eso es un test y no un
     * comentario.
     */
    public function test_la_clave_del_contexto_es_la_misma_que_la_de_user(): void
    {
        $constantes = (new ReflectionClass(User::class))->getConstants();

        $this->assertArrayHasKey('CONTEXTO', $constantes,
            'App\User ya no tiene la constante CONTEXTO. `Auditoria` la necesita para '.
            'sacar al actor de la petición sin forzar una resolución nueva.');

        $this->assertSame($constantes['CONTEXTO'], Auditoria::CLAVE_DEL_CONTEXTO,
            "La clave del contexto cambió en App\\User y no aquí.\n".
            'Sin arreglarlo, todas las líneas de auditoría se escriben sin actor y nada falla.');
    }

    /** Con el contexto puesto en la petición, el actor sale solo: nadie lo decide desde fuera. */
    public function test_el_actor_sale_del_contexto_de_la_peticion(): void
    {
        Request::instance()->attributes->set(Auditoria::CLAVE_DEL_CONTEXTO, (object) [
            'user_id' => 41,
            'persona_id' => 7,
            'tipo' => 'Profesor',
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'year_id' => 8,
            'grupo_id' => 'N/A',
        ]);

        $fila = $this->fila(Auditoria::registrar()->editar('nota', 55)->guardar());

        $this->assertSame(41, (int) $fila->actor_user_id);
        $this->assertSame(7, (int) $fila->actor_persona_id);
        $this->assertSame('Profesor', $fila->actor_tipo);
        $this->assertSame('Ana Pérez', $fila->actor_nombre);
        $this->assertSame(8, (int) $fila->year_id);

        $this->assertNull($fila->grupo_id,
            'El contexto trae `grupo_id = "N/A"` para tres de los cuatro tipos de usuario. '.
            'Eso no es un id, y meterlo como si lo fuera dejaría filas apuntando a un grupo '.
            'que no existe.');
    }

    /**
     * **La atribución es `aproximada` mientras la sesión no se sepa, y se escribe
     * NULL en vez de adivinarla.**
     *
     * Es el corazón de la §2. Hoy los nueve sitios que escriben `historial_id` lo
     * resuelven con `order by id desc limit 1` sobre `historiales`, o sea **el
     * último login de esa persona, no la sesión que hizo el cambio**. Y no hace
     * falta el caso raro de dos aparatos: el refresco vive 14 días y rota en cada
     * uso, así que quien entre a diario puede llevar **meses** sin teclear la
     * contraseña, y todas sus escrituras de esos meses colgarían del mismo ingreso
     * de hace meses. La pantalla mostraría una lista falsa sin ningún error
     * visible.
     *
     * Un NULL dice «no se sabe». La adivinanza dice «fue ése» y se equivoca sin
     * avisar.
     */
    public function test_sin_sesion_conocida_no_se_adivina_el_ingreso(): void
    {
        Request::instance()->attributes->set(Auditoria::CLAVE_DEL_CONTEXTO, (object) [
            'user_id' => 41, 'persona_id' => 7, 'tipo' => 'Profesor',
        ]);

        $fila = $this->fila(Auditoria::registrar()->editar('nota', 55)->guardar());

        $this->assertNull($fila->sesion_id);
        $this->assertNull($fila->historial_id);
        $this->assertSame('aproximada', $fila->atribucion,
            'La pantalla tiene que poder avisar de que la atribución no es cierta, y no lo '.
            'puede deducir: el navegador no sabe qué día se desplegó la fase 2 en su colegio.');
    }

    /**
     * Y cuando la fase 2 la traiga, la atribución pasa a `sesion` sola.
     *
     * El contexto es lo único que cambia; aquí no hay nada que tocar el día del
     * despliegue. Este test simula esa fase adelantando las dos propiedades que
     * `ContextoDeUsuario` va a exponer, para que el día que aparezcan **ya esté
     * comprobado** que se usan y no se ignoran.
     */
    public function test_con_la_sesion_conocida_la_atribucion_es_cierta(): void
    {
        Request::instance()->attributes->set(Auditoria::CLAVE_DEL_CONTEXTO, (object) [
            'user_id' => 41, 'persona_id' => 7, 'tipo' => 'Profesor',
            'sesion_id' => 903, 'historial_id' => 412,
        ]);

        $fila = $this->fila(Auditoria::registrar()->editar('nota', 55)->guardar());

        $this->assertSame(903, (int) $fila->sesion_id);
        $this->assertSame(412, (int) $fila->historial_id);
        $this->assertSame('sesion', $fila->atribucion);
    }

    /**
     * Lo que hace el sistema no se atribuye a una persona.
     *
     * La definitiva que un profesor **teclea** y la que el recalculador único
     * **recalcula** no son la misma cosa. Si las dos entran como `editar` de
     * alguien, la pantalla se llena de ruido automático y deja de leerse — y el
     * recálculo ocurre en siete disparadores distintos desde la fase 3 del
     * [10](../../docs/migracion/10-definitivas.md).
     */
    public function test_lo_que_hace_el_sistema_no_lleva_persona_detras(): void
    {
        Request::instance()->attributes->set(Auditoria::CLAVE_DEL_CONTEXTO, (object) [
            'user_id' => 41, 'persona_id' => 7, 'tipo' => 'Profesor', 'nombres' => 'Ana',
        ]);

        $fila = $this->fila(
            Auditoria::registrar()->editar('nota_final', 9)->porElSistema()->guardar()
        );

        $this->assertSame('sistema', $fila->actor_tipo);
        $this->assertNull($fila->actor_user_id,
            'Con un usuario en la petición, `porElSistema()` tiene que ganarle: si no, un '.
            'recálculo automático queda firmado por quien pasaba por ahí.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // La hora, la ruta y lo que se guarda
    // ─────────────────────────────────────────────────────────────────────

    /**
     * La hora que queda escrita es la del `Reloj`, en Bogotá y con milésimas.
     *
     * Se mira **la fila**, no la llamada: leída de vuelta de MySQL, que es donde
     * un `TIMESTAMP` habría convertido y un `DATETIME` no.
     */
    public function test_la_hora_escrita_es_la_del_reloj(): void
    {
        $antes = Reloj::ahora();
        $fila = $this->fila(Auditoria::registrar()->crear('nota', 1)->guardar());
        $despues = Reloj::ahora();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/',
            $fila->ocurrido_en,
            "`ocurrido_en` salió como '{$fila->ocurrido_en}'. Sin las milésimas, dos notas ".
            'tecleadas en el mismo segundo no se pueden ordenar.');

        $escrita = strtotime($fila->ocurrido_en);

        $this->assertGreaterThanOrEqual($antes->getTimestamp() - 1, $escrita);
        $this->assertLessThanOrEqual($despues->getTimestamp() + 1, $escrita,
            'La hora guardada no está entre las dos lecturas del Reloj que la rodean. '.
            'Si va cinco horas movida, la columna es TIMESTAMP o alguien no pasó por el Reloj.');
    }

    /**
     * La ruta se guarda **con su patrón**, no con la URL resuelta.
     *
     * `PUT notas/update/{id}` y no `PUT notas/update/8412`: el id concreto ya está
     * en `entidad_id`, y el patrón es lo que permite agrupar por endpoint cuando
     * se reconstruye un incidente. Y sin el prefijo `api/`, que lo llevan las 542.
     */
    public function test_la_ruta_se_guarda_con_su_patron_y_sin_el_prefijo(): void
    {
        Request::instance()->setRouteResolver(
            fn () => new Route(['PUT'], 'api/notas/update/{id}', [])
        );

        $fila = $this->fila(Auditoria::registrar()->editar('nota', 8412)->guardar());

        $this->assertSame('GET notas/update/{id}', $fila->ruta,
            'El método sale de la petición del test (GET) y el patrón de la ruta resuelta.');
    }

    /**
     * Un nombre más largo que la columna se recorta; la línea no se pierde.
     *
     * Con el modo estricto de MySQL, 130 caracteres en un `varchar(120)` son una
     * excepción, y esta clase se la traga — así que sin recortar **la línea no se
     * escribiría en absoluto**. Entre una línea recortada y ninguna, la recortada:
     * el rastro de quién tocó qué sigue estando.
     */
    public function test_un_valor_largo_recorta_la_linea_en_vez_de_perderla(): void
    {
        $nombre = str_repeat('á', 400);

        $fila = $this->fila(
            Auditoria::registrar()->editar('nota', 1)->deAlumno(9, $nombre)->guardar()
        );

        $this->assertSame(120, mb_strlen($fila->alumno_nombre));
        $this->assertLessThanOrEqual(255, mb_strlen($fila->resumen));
    }

    /**
     * Un `null` se guarda como NULL de SQL, no como el `null` de JSON.
     *
     * `json_encode(null)` da la cadena `'null'`, que en una columna `json` es un
     * valor y no la ausencia de valor: `valor_anterior IS NULL` dejaría de
     * encontrarlo. Son dos cosas distintas y la pantalla filtra por la segunda —
     * «se creó, no había valor antes» contra «el valor de antes era null».
     */
    public function test_un_valor_ausente_es_null_de_sql_y_no_de_json(): void
    {
        $fila = $this->fila(Auditoria::registrar()->crear('nota', 1)->a(85)->guardar());

        $this->assertNull($fila->valor_anterior);
        $this->assertSame('85', $fila->valor_nuevo);

        $this->assertSame(1, DB::table('auditoria')
            ->where('id', $fila->id)->whereNull('valor_anterior')->count(),
            "`valor_anterior IS NULL` tiene que encontrar la fila. Si guarda el texto 'null', ".
            'la consulta de la pantalla la deja fuera.');
    }

    /**
     * La línea entra en la transacción del cambio: si el cambio se deshace, la
     * línea también.
     *
     * Esta clase no abre transacción propia a propósito. Si el cambio no se
     * guardó, no puede quedar una línea diciendo que sí — es lo que hoy le pasa a
     * la bitácora de `putUpdate`, que está dentro del `try` con el `UPDATE` pero
     * **sin transacción**.
     */
    public function test_si_el_cambio_se_deshace_la_linea_tambien(): void
    {
        $antes = DB::table('auditoria')->count();

        try {
            DB::transaction(function () {
                Auditoria::registrar()->editar('nota', 1)->guardar();

                throw new RuntimeException('el cambio falló después de auditarlo');
            });
        } catch (RuntimeException) {
            // Lo que se mira es la fila, no la excepción.
        }

        $this->assertSame($antes, DB::table('auditoria')->count(),
            'La línea sobrevivió a un cambio que se deshizo. Auditoría no puede abrir su '.
            'propia transacción: tiene que ir en la del llamante.');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function fila(?int $id): object
    {
        $this->assertNotNull($id, 'No se escribió la línea de auditoría.');

        $fila = DB::selectOne('SELECT * FROM auditoria WHERE id = ?', [$id]);

        $this->assertNotNull($fila, "La línea {$id} no está en la tabla.");

        return $fila;
    }

    private function tipoDeColumna(string $tabla, string $columna): string
    {
        $fila = DB::selectOne(
            'SELECT COLUMN_TYPE AS tipo FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );

        $this->assertNotNull($fila, "`{$tabla}.{$columna}` no existe.");

        return (string) $fila->tipo;
    }

    /** @return list<string> */
    private function ficherosPhpDe(string $carpeta): array
    {
        $ficheros = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($carpeta, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $fichero) {
            if ($fichero->isFile() && $fichero->getExtension() === 'php') {
                $ficheros[] = $fichero->getPathname();
            }
        }

        return $ficheros;
    }
}
