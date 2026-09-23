<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\PlantillaDelCompromiso;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **La plantilla del compromiso académico**: lo que cada colegio adapta, guardado por año.
 *
 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §8, y el porqué de cada columna
 * en el docblock de la migración `2026_09_22_100000_la_plantilla_del_compromiso`.
 * Aquí va sólo lo que decide este controlador.
 *
 * El encargo fue literal —*«que no tengan que estar seleccionando y editando las
 * secciones cada periodo»*—, así que esto es **por año y no por periodo**, y la otra
 * mitad de la respuesta no está aquí: está en
 * `YearsController::copiarLaPlantillaDelCompromiso()`, que es lo que impide que cada
 * enero el colegio vuelva a configurarlo todo sin enterarse.
 *
 * ## Tres rutas y dos tablas, y la partición no es por comodidad
 *
 *     GET  compromisos/config    las dos tablas de una vez, con los defectos puestos
 *     PUT  compromisos/config    los 21 valores de `config_compromiso`
 *     PUT  compromisos/bloques   los textos de `compromiso_bloques`
 *
 * El `GET` es uno solo porque **la pantalla no puede pintar media plantilla**: los
 * bloques se leen con los marcadores al lado y la regla decide qué dice `{unidad}`.
 * Los `PUT` son dos porque son **dos formas distintas** —una fila de columnas contra
 * una lista ordenada de textos largos— y porque guardar un bloque no debería tener
 * que remandar el corte y los firmantes enteros.
 *
 * ## Ausencia significa «los defectos», nunca «un documento en blanco»
 *
 * Es la regla del módulo y se cumple en los dos sentidos:
 *
 *   - Sin fila en `config_compromiso`, el `GET` devuelve **los defectos de la
 *     migración**, no nulos. Una pantalla que recibe `null` en `corte` tiene que
 *     inventarse un número, y el que se invente no será el 3 que decidió Joseth.
 *   - Sin filas en `compromiso_bloques`, el `GET` devuelve **el catálogo entero**
 *     de `PlantillaDelCompromiso`. Las filas sólo aparecen cuando el colegio guarda,
 *     que es lo que mantiene la migración aditiva pura (§8.4 del diseño).
 *
 * Por eso `guardada` viaja aparte: la pantalla necesita poder distinguir «esto es lo
 * que eligió el colegio» de «esto es lo que traía puesto», y por el valor no se
 * distingue — un colegio puede haber guardado exactamente los defectos.
 *
 * ## SQL crudo y ningún modelo nuevo
 *
 * Mismo camino que `Informes\FormulariosInscripcionController::putCampos`, que es la
 * familia de esta tabla: `DB::selectOne`/`DB::insert` con
 * `INSERT ... ON DUPLICATE KEY UPDATE` sobre el `UNIQUE`, que es sintaxis válida en
 * **MariaDB 10.5** —lo que corre en los dieciséis colegios— y no sólo en el MySQL 8
 * del docker. El get-or-create lo resuelve la base y no un `if`: escrito como
 * comprueba-y-luego-inserta, dos pestañas guardando a la vez dejan dos
 * configuraciones para el mismo año y el código tendría que elegir una.
 *
 * Y la hora sale de `Reloj::ahoraTexto()` y **no de `NOW()`**: `config/database.php`
 * no fija la zona de la sesión, así que `NOW()` es el reloj del servidor —dieciséis
 * cuentas de cPanel distintas— y estas columnas acabarían con una hora distinta en
 * cada colegio sin nada en la fila que lo dijera. Ver el 53 §1.
 *
 * ## Lo que NO lleva, y va escrito para que no se lea como un olvido
 *
 * **No lleva `Autoriza::exigirEscrituraEnElAnio`**, que sí llevan la escala y la
 * frase. Allí hace falta porque lo que se toca **se lee vivo en cada cálculo**, así
 * que cambiarlo reescribe definitivas de un año cerrado. Aquí no puede pasar: el
 * compromiso **congela su texto al crearse** (`compromisos.texto`, §3.1 del diseño),
 * de modo que reescribir esta plantilla no cambia ni una coma de un papel ya firmado.
 * Un guard que no protege nada es peor que no ponerlo, porque el día que alguien lo
 * lea creerá que hay algo protegido ahí.
 */
class CompromisosConfigController extends Controller
{
    use ResuelveElUsuario;

    /**
     * Cuántos renglones de firma caben en el pie, y cuánto mide un rótulo.
     *
     * No es una restricción de negocio: es el alto de la hoja. Con seis renglones el
     * pie del formato del Bethel ya ocupa un tercio de la página, y el catálogo con
     * el que nace son cuatro.
     */
    private const MAXIMO_FIRMANTES = 6;

    private const LARGO_FIRMANTE = 80;

    /**
     * Los topes del encabezado, que son los de las columnas: `titulo` y `subtitulo`
     * son `varchar(160)` y `plazo_label` `varchar(120)`. Van repetidos aquí porque
     * **MySQL con el `sql_mode` de estos servidores trunca en vez de lanzar**, y una
     * plantilla que se guarda cortada por la mitad es peor que un 422.
     */
    private const LARGO_TITULO = 160;

    private const LARGO_PLAZO_LABEL = 120;

    /**
     * El tope del cuerpo de un bloque, **en caracteres**.
     *
     * La columna es `text` y aguanta 65.535 **bytes**, que no es lo mismo: en español
     * cada tilde y cada «ñ» gastan dos. Veinte mil caracteres no pasan de unos 25.000
     * bytes ni en el peor caso real, así que el margen sobra. Y el tope existe porque
     * el bloque más largo del formato del Bethel —los artículos del SIEE con su
     * parágrafo— pasa de 1.200 caracteres él solo: hay que dejar sitio de sobra para
     * un SIEE más hablador, pero no hasta el punto de que quepa un fichero en una
     * columna que nadie vuelve a mirar.
     */
    private const LARGO_CUERPO = 20000;

    private const LARGO_TITULO_BLOQUE = 160;

    /** `compromiso_bloques.orden` es `unsignedSmallInteger`; nueve bloques no llegan ni de lejos. */
    private const MAXIMO_ORDEN = 255;

    /**
     * Las dos reglas posibles. Viven aquí y no en un `enum` de MySQL por lo mismo que
     * `CierreDeLoNoCalificado`: con el `sql_mode` de estos servidores un `enum` no
     * lanza ante un valor raro, **guarda la cadena vacía y devuelve 200**.
     */
    private const REGLAS = ['area', 'asignatura'];

    /**
     * Los once interruptores **con el valor con el que nacen**, copiados de la
     * migración.
     *
     * Están en un solo sitio a propósito: el `GET` los usa como defecto y el `PUT` usa
     * sus nombres como lista de lo que hay que normalizar. En dos listas separadas,
     * añadir el duodécimo y olvidarse de una da una pantalla que guarda un campo que
     * no vuelve a leer, y eso no falla — simplemente no hace nada.
     *
     * **`canal_correo` es el único apagado**, y ésa es la decisión medida del 22 sep
     * 2026: de 1.085 acudientes de `simonbolivar`, **100 tienen correo y 1.020
     * celular**. Un canal encendido por defecto que alcanza al 9 % es peor que uno
     * apagado, porque el colegio cree que avisó.
     *
     * @var array<string, bool>
     */
    private const INTERRUPTORES = [
        'primaria_activa' => false,
        'muestra_escudo' => true,
        'muestra_foto' => true,
        'muestra_resolucion' => true,
        'col_periodos' => true,
        'col_falta' => true,
        'canal_papel' => true,
        'canal_push' => true,
        'canal_correo' => false,
        'firma_digital' => true,
        'pide_segunda_firma' => true,
    ];

    /**
     * **La plantilla entera de un año, con los defectos ya puestos.**
     *
     * No lleva permiso dentro y eso es una decisión, no un olvido. `auth.personal`
     * cierra la puerta a alumnos y acudientes antes de llegar aquí, y de las cuentas
     * que quedan, **leer la plantilla no decide nada**: el docente que va a entregar
     * el papel a una familia tiene que poder ver qué dice antes de firmarlo. Lo que
     * se estrecha es escribirla, y eso está en los dos `PUT`.
     */
    public function getConfig()
    {
        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));

        $fila = DB::selectOne('SELECT * FROM config_compromiso WHERE year_id=?', [$year_id]);

        return [
            'year_id' => $year_id,
            // **`guardada` es lo que la pantalla no puede deducir del contenido**: un
            // colegio que guardó los defectos tal cual devuelve exactamente lo mismo que
            // uno que no ha entrado nunca, y el botón «volver al defecto» necesita saber
            // cuál de los dos es.
            'guardada' => $fila !== null,
            'config' => $this->pintarConfig($fila),
            'bloques' => $this->pintarBloques($year_id),
            // `MARCADORES` es un mapa `{marcador: que_es}`, y un mapa de PHP con claves
            // de texto sale de `json_encode` como objeto: el orden se conserva hoy por
            // cómo lo serializa PHP, no por contrato. La pantalla los pinta como una
            // tabla de ayuda, así que se manda **como lista** y el orden deja de
            // depender de nadie.
            'marcadores' => $this->pintarMarcadores(),
        ];
    }

    /**
     * **Los 21 valores que deciden a quién se le propone compromiso y qué dice el papel.**
     *
     * ## El permiso va DENTRO, y el criterio es el de lo académico
     *
     * `auth.personal` en la ruta y `puedeConfigurarElCompromiso()` aquí, que es el
     * mismo conjunto que `Autoriza::puedeCambiarLaNotaNumerica` —**superusuario,
     * Secretario, Coordinación Académica y Rector**, 12 personas de las 74 que tiene
     * el personal en la copia de desarrollo—.
     *
     * Se eligió ése y no `esAdministrativo()` por lo que decide esta pantalla. `regla`
     * y `corte` deciden **a qué alumnos les llega un compromiso académico**, y los
     * textos son los que salen impresos en un papel que firman el acudiente y el
     * estudiante. Eso es lo académico, no la administración del colegio: es
     * exactamente la frontera que Joseth trazó el 17 sep 2026 entre
     * `toggle-mostrar-nota-numerica` (Coord académico dentro) y
     * `boletin-independiente` (Admin dentro), dos frases que suenan igual leídas en
     * voz alta y admiten a gente distinta. Un coordinador académico que no pudiera
     * escribir el SIEE en su propio compromiso sería la puerta puesta al revés.
     *
     * Y `esAdministrativo()` deja fuera **al coordinador y al rector**, que son
     * justamente los dos que firman este papel.
     */
    public function putConfig()
    {
        $user = $this->user;

        Autoriza::exigir(
            $this->puedeConfigurarElCompromiso($user),
            'Sólo un superusuario, secretario, coordinador académico o rector puede '
                .'configurar la plantilla del compromiso académico.'
        );

        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));
        $valores = $this->configValidada();

        $ahora = Reloj::ahoraTexto();

        // `INSERT ... ON DUPLICATE KEY UPDATE` sobre el `UNIQUE (year_id)` y no
        // comprueba-y-luego-inserta: el índice es lo que garantiza que un año tenga una
        // sola configuración, y dos secretarías guardando a la vez no pueden dejar dos
        // filas. Mismo camino que `config_formulario_inscripcion`, que es su hermana.
        //
        // `created_by` se manda en el `VALUES` y **no se toca en el `UPDATE`**: quien
        // configuró esto por primera vez —o el enero que lo heredó— no cambia porque
        // alguien corrija hoy una errata del subtítulo.
        DB::insert('INSERT INTO config_compromiso
                (year_id, regla, corte, primaria_activa, primaria_materia_1_id, primaria_materia_2_id,
                 plazo_label, plazo_dias, dias_reclamacion, titulo, subtitulo,
                 muestra_escudo, muestra_foto, muestra_resolucion, col_periodos, col_falta, firmantes,
                 canal_papel, canal_push, canal_correo, firma_digital, pide_segunda_firma,
                 created_by, updated_by, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE regla=VALUES(regla), corte=VALUES(corte),
                primaria_activa=VALUES(primaria_activa),
                primaria_materia_1_id=VALUES(primaria_materia_1_id),
                primaria_materia_2_id=VALUES(primaria_materia_2_id),
                plazo_label=VALUES(plazo_label), plazo_dias=VALUES(plazo_dias),
                dias_reclamacion=VALUES(dias_reclamacion),
                titulo=VALUES(titulo), subtitulo=VALUES(subtitulo),
                muestra_escudo=VALUES(muestra_escudo), muestra_foto=VALUES(muestra_foto),
                muestra_resolucion=VALUES(muestra_resolucion),
                col_periodos=VALUES(col_periodos), col_falta=VALUES(col_falta),
                firmantes=VALUES(firmantes),
                canal_papel=VALUES(canal_papel), canal_push=VALUES(canal_push),
                canal_correo=VALUES(canal_correo), firma_digital=VALUES(firma_digital),
                pide_segunda_firma=VALUES(pide_segunda_firma),
                updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)',
            [
                $year_id, $valores['regla'], $valores['corte'], $valores['primaria_activa'],
                $valores['primaria_materia_1_id'], $valores['primaria_materia_2_id'],
                $valores['plazo_label'], $valores['plazo_dias'], $valores['dias_reclamacion'],
                $valores['titulo'], $valores['subtitulo'],
                $valores['muestra_escudo'], $valores['muestra_foto'], $valores['muestra_resolucion'],
                $valores['col_periodos'], $valores['col_falta'], $valores['firmantes'],
                $valores['canal_papel'], $valores['canal_push'], $valores['canal_correo'],
                $valores['firma_digital'], $valores['pide_segunda_firma'],
                $user->user_id, $user->user_id, $ahora, $ahora,
            ]);

        return 'Guardado';
    }

    /**
     * **Los textos del papel**, con su orden y su interruptor.
     *
     * Mismo permiso que `putConfig` y por lo mismo: esto es lo que sale impreso y lo
     * que se firma. El bloque `considerando_siee` son **los artículos del SIEE del
     * colegio copiados literalmente**, y citar mal la norma interna es justo por lo
     * que la T-646/11 condenó a un colegio.
     *
     * ## Va en transacción, y no por elegancia
     *
     * Los bloques son **un documento**, no nueve ajustes sueltos. Si el tercero falla
     * a medio guardar, lo que queda en la base no es «ocho bien y uno mal»: es un
     * compromiso que mezcla el texto nuevo con el viejo, y eso se imprime igual de bien
     * y nadie lo nota hasta que está firmado.
     *
     * ## Lo que NO hace: borrar las claves que no vengan
     *
     * La pantalla manda siempre el catálogo entero, así que un `DELETE` de lo que
     * falte sería hoy una operación vacía. Se deja sin escribir a propósito: el día que
     * un cliente mande seis —una versión más vieja, un guardado parcial, un `PATCH` que
     * a alguien le parezca buena idea— ese `DELETE` **tiraría el texto que el colegio
     * escribió** en los que faltan, y ese texto no se reconstruye (los defectos de
     * `PlantillaDelCompromiso` son los genéricos, no los suyos). Una clave que sobra
     * cuesta una fila; una clave borrada cuesta el trabajo de una tarde de rectoría.
     */
    public function putBloques()
    {
        $user = $this->user;

        Autoriza::exigir(
            $this->puedeConfigurarElCompromiso($user),
            'Sólo un superusuario, secretario, coordinador académico o rector puede '
                .'escribir los textos del compromiso académico.'
        );

        $year_id = $this->anioDeLaPeticion(Request::input('year_id'));
        $bloques = $this->bloquesValidados();

        $ahora = Reloj::ahoraTexto();

        DB::transaction(function () use ($bloques, $year_id, $user, $ahora) {
            foreach ($bloques as $bloque) {
                // Sobre el `UNIQUE (year_id, clave)`, igual que la configuración de arriba:
                // un bloque no puede estar dos veces en el mismo año, y quién lo escribió
                // primero no cambia porque hoy alguien le corrija una coma.
                DB::insert('INSERT INTO compromiso_bloques
                        (year_id, clave, orden, activo, titulo, cuerpo,
                         created_by, updated_by, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE orden=VALUES(orden), activo=VALUES(activo),
                        titulo=VALUES(titulo), cuerpo=VALUES(cuerpo),
                        updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)',
                    [
                        $year_id, $bloque['clave'], $bloque['orden'], $bloque['activo'],
                        $bloque['titulo'], $bloque['cuerpo'],
                        $user->user_id, $user->user_id, $ahora, $ahora,
                    ]);
            }
        });

        return 'Guardado';
    }

    /**
     * Quién puede escribir la plantilla del compromiso.
     *
     * **Se escribe con nombre propio y no llamando a `Autoriza::puedeCambiarLaNotaNumerica`
     * desde los dos métodos de arriba**, por la regla que esa clase ya tiene escrita:
     * *un criterio con nombre propio se puede mover sin perseguir sus copias*. Hoy es
     * el mismo conjunto por la misma razón —decide lo académico y sale impreso en un
     * papel firmado—, pero son dos preguntas distintas, y el día que un colegio pida
     * que esto lo toque también el psicoorientador, se cambia aquí y no se arrastra
     * consigo el boletín de los otros quince.
     *
     * Vive en este controlador y no en `Autoriza` porque tiene **un solo llamante**.
     * El día que aparezca el segundo —la pantalla que crea el compromiso del alumno
     * tendrá que preguntar algo parecido, y no será esto mismo— es cuando le toca
     * mudarse allí, que es donde vive el criterio compartido.
     */
    private function puedeConfigurarElCompromiso($user): bool
    {
        return Autoriza::puedeCambiarLaNotaNumerica($user);
    }

    /**
     * El año de la petición, comprobado.
     *
     * Por defecto el de la sesión: la pantalla está mirando un año concreto y no tiene
     * por qué repetirlo. Un año que no existe —o que está en la papelera— es **404** y
     * no un 200 que no escribió nada.
     *
     * Es la misma lógica que `FormulariosInscripcionController::anioDeLaPeticion`, y
     * está copiada y no heredada porque allí es `private`: subirla a `Controller`
     * sería meter una consulta a `years` en la clase base de los 96 controladores para
     * que la usen dos. El día que haya un tercero, ése es el momento de hacer el
     * `trait`, no antes.
     */
    private function anioDeLaPeticion(mixed $year_id): int
    {
        if ($year_id === null || $year_id === '') {
            return (int) $this->user->year_id;
        }

        if (! is_numeric($year_id)) {
            abort(422, 'El año no es válido.');
        }

        $anio = DB::selectOne('SELECT id FROM years WHERE id=? AND deleted_at IS NULL',
            [(int) $year_id]);

        if (! $anio) {
            abort(404, 'Ese año lectivo no existe.');
        }

        return (int) $anio->id;
    }

    /**
     * La configuración tal como la pinta la pantalla: **con los defectos puestos y con
     * los tipos de JSON de verdad**.
     *
     * Los defectos son los de la migración, repetidos aquí. No se leen de
     * `information_schema` —que sería «la única fuente»— porque eso es una consulta
     * por petición para contestar algo que no cambia, y porque el defecto de una
     * columna que acaba de nacer y el que quiere la pantalla no tienen por qué ser lo
     * mismo para siempre.
     *
     * **Y los tipos importan tanto como los valores.** PDO devuelve los `tinyint(1)`
     * como `"1"` y `"0"` —cadenas—, y `"0"` es *verdadero* para el front en cuanto
     * alguien escriba `if (config.canal_correo)` en TypeScript sin `=== true`. Aquí se
     * castean uno a uno, que es lo que evita esa familia entera de fallos.
     */
    private function pintarConfig(?object $fila): array
    {
        $pintado = [
            'regla' => $fila->regla ?? 'area',
            'corte' => (int) ($fila->corte ?? 3),
            'primaria_materia_1_id' => isset($fila->primaria_materia_1_id)
                ? (int) $fila->primaria_materia_1_id : null,
            'primaria_materia_2_id' => isset($fila->primaria_materia_2_id)
                ? (int) $fila->primaria_materia_2_id : null,
            'plazo_label' => $fila->plazo_label ?? 'Semana de nivelaciones',
            'plazo_dias' => (int) ($fila->plazo_dias ?? 5),
            'dias_reclamacion' => (int) ($fila->dias_reclamacion ?? 5),
            'titulo' => $fila->titulo ?? null,
            'subtitulo' => $fila->subtitulo ?? null,
            'firmantes' => $this->firmantesDeLaFila($fila->firmantes ?? null),
        ];

        foreach (self::INTERRUPTORES as $columna => $defecto) {
            $pintado[$columna] = isset($fila->$columna) ? (bool) (int) $fila->$columna : $defecto;
        }

        return $pintado;
    }

    /**
     * Los rótulos de firma, que viven como JSON dentro de una columna `text`.
     *
     * Es la única columna con JSON de las dos tablas, y es así porque lo mismo hace
     * `years.firmantes_acta`: en este proyecto no se usa el tipo `json` de MySQL en
     * ningún sitio, y esta familia no lo estrena. No se filtra por ella, así que no
     * hace falta que sea consultable.
     *
     * **Vacío y corrupto se tratan igual, y a propósito.** Un `json_decode` que
     * devuelve `null` puede ser una columna nula, una cadena vacía o un texto que
     * alguien editó a mano en phpMyAdmin. En los tres casos la respuesta correcta es
     * la misma —los cargos con los que nace el papel— porque la alternativa es un pie
     * de página **sin renglones para firmar**, y de eso el colegio no se entera hasta
     * que tiene el papel impreso delante de una familia.
     *
     * @return list<string>
     */
    private function firmantesDeLaFila(?string $json): array
    {
        $lista = $json === null ? null : json_decode($json, true);

        if (! is_array($lista) || count($lista) === 0) {
            return PlantillaDelCompromiso::firmantesPorDefecto();
        }

        return array_values(array_map(
            static fn ($rotulo): string => (string) $rotulo,
            $lista
        ));
    }

    /**
     * Los bloques, **siempre el catálogo entero**, con el texto efectivo y el del catálogo.
     *
     * **Y son NUEVE, no ocho**, aunque el diseño (§8.4) y la cabecera de la migración
     * digan ocho: el `considerando` se partió en dos —`considerando_siee`, que es del
     * colegio y nace vacío, y `considerando_norma`, que es el Decreto 1290 y vale para
     * los dieciséis— y los dos textos que quedaron no se parecen en nada. Por eso este
     * método **cuenta el catálogo y no un número escrito a mano**: el noveno ya entró
     * sin que nadie tocara nada, y el décimo entrará igual.
     *
     * ## Por qué salen todos aunque no haya ni una fila
     *
     * Porque la ausencia de fila significa «el defecto», no «este bloque no existe».
     * Una pantalla que recibiera sólo lo guardado tendría que conocer el catálogo para
     * dibujar el resto, y entonces habría dos catálogos —uno en PHP y otro en
     * TypeScript— que se separarían el día que se añada el noveno.
     *
     * ## Por qué viaja también el defecto de cada uno
     *
     * `titulo_defecto` y `cuerpo_defecto` son lo que necesita el botón **«volver al
     * defecto»**, y no se pueden calcular en el front por lo mismo de arriba.
     * `personalizado` dice si hay fila, que es lo único que distingue «el colegio
     * escribió exactamente esto» de «esto es lo que venía puesto» — por el texto no se
     * distingue.
     *
     * ## Una clave guardada que ya no esté en el catálogo se ignora
     *
     * El recorrido es **por el catálogo** y no por las filas, así que un bloque
     * retirado del catálogo deja de salir sin romper nada y sin perder su fila: sigue
     * en la base por si el retiro fue un error. Es la otra mitad de la regla que dejó
     * escrita `PlantillaDelCompromiso::catalogo()` —*añadir una entrada nueva al final
     * es seguro; renombrar una existente no lo es*—.
     */
    private function pintarBloques(int $year_id): array
    {
        $filas = DB::select('SELECT clave, orden, activo, titulo, cuerpo FROM compromiso_bloques
            WHERE year_id=? ORDER BY orden, id', [$year_id]);

        $porClave = [];

        foreach ($filas as $fila) {
            $porClave[$fila->clave] = $fila;
        }

        $bloques = [];

        foreach (PlantillaDelCompromiso::catalogo() as $puesto => $defecto) {
            $fila = $porClave[$defecto['clave']] ?? null;

            $bloques[] = [
                'clave' => $defecto['clave'],
                'titulo' => $fila !== null ? $fila->titulo : $defecto['titulo'],
                // El cuerpo sale **siempre como texto**: la columna es `nullable` y dos de
                // ellos nacen vacíos, así que un `null` aquí sería un `textarea` que
                // imprime la palabra «null» en cuanto alguien lo concatene.
                'cuerpo' => $fila !== null ? (string) $fila->cuerpo : (string) $defecto['cuerpo'],
                'activo' => $fila !== null ? (bool) (int) $fila->activo : (bool) $defecto['activo'],
                // Sin fila, el orden es **el puesto del catálogo**: es el orden en que nace
                // el documento, y es el que el `ORDER BY` de abajo tiene que poder usar.
                'orden' => $fila !== null ? (int) $fila->orden : $puesto,
                'del_colegio' => (bool) $defecto['del_colegio'],
                'ayuda' => $defecto['ayuda'],
                'titulo_defecto' => $defecto['titulo'],
                'cuerpo_defecto' => (string) $defecto['cuerpo'],
                'personalizado' => $fila !== null,
                // No viaja: es el desempate del orden y sólo sirve para la línea de abajo.
                'puesto' => $puesto,
            ];
        }

        // **Por `orden` y, empatados, por el puesto del catálogo.** El desempate no es
        // un detalle: `usort` no es estable en PHP < 8.0 y aquí sí lo es, pero los
        // bloques recién heredados comparten `orden = 0` hasta que alguien los arrastre,
        // y sin el segundo criterio el documento saldría en el orden en que la base
        // devolviera las filas — que es el orden en que el colegio las creó, no el del
        // papel.
        usort($bloques, static fn (array $a, array $b): int => ($a['orden'] <=> $b['orden'])
            ?: ($a['puesto'] <=> $b['puesto']));

        return array_map(static function (array $bloque): array {
            unset($bloque['puesto']);

            return $bloque;
        }, $bloques);
    }

    /**
     * Los marcadores, **como lista y no como mapa**.
     *
     * `PlantillaDelCompromiso::MARCADORES` es un array asociativo, y `json_encode` lo
     * saca como objeto: el orden de las claves de un objeto JSON no está garantizado
     * por el formato, así que la tabla de ayuda de la pantalla dependería de cómo
     * decida recorrerlo cada cliente. Como lista, el orden es el del catálogo y lo es
     * en los tres clientes.
     */
    private function pintarMarcadores(): array
    {
        $marcadores = [];

        foreach (PlantillaDelCompromiso::MARCADORES as $marcador => $que_es) {
            $marcadores[] = ['marcador' => $marcador, 'que_es' => $que_es];
        }

        return $marcadores;
    }

    /**
     * Los 21 valores que llegan de la pantalla, comprobados uno a uno.
     *
     * **Validación a mano y no `Validator::make`**, que es lo que hace el resto de esta
     * familia: los mensajes salen en la pantalla de una secretaria y tienen que decir
     * qué hacer, no qué regla se incumplió.
     *
     * @return array<string, mixed>
     */
    private function configValidada(): array
    {
        $regla = Request::input('regla');

        if (! is_string($regla) || ! in_array($regla, self::REGLAS, true)) {
            abort(422, 'La regla tiene que ser `'.implode('` o `', self::REGLAS).'`.');
        }

        // **El corte NO es el del año**, y es el error que más caro sale: `corte` dice
        // cuántas perdidas disparan un compromiso, y `years.cant_areas_pierde_year` dice
        // cuántas reprueban. Un compromiso que llegue al segundo número llega cuando el
        // alumno ya está en el límite. El tope de 20 es el de la columna en la práctica
        // —ningún colegio tiene veinte áreas— y está para que un teclazo no guarde 200.
        $corte = $this->enteroEntre(Request::input('corte'), 1, 20,
            'El corte tiene que ser un número entre 1 y 20 (cuántas perdidas disparan el compromiso).');

        $plazo_dias = $this->enteroEntre(Request::input('plazo_dias'), 1, 60,
            'El plazo tiene que ser un número de días entre 1 y 60.');

        $dias_reclamacion = $this->enteroEntre(Request::input('dias_reclamacion'), 1, 60,
            'Los días para reclamar tienen que ser un número entre 1 y 60.');

        // `plazo_label` sale impreso dentro del marcador `{plazo}`, así que no puede
        // quedar vacío: el papel diría *«durante ␣»*.
        $plazo_label = trim((string) Request::input('plazo_label'));

        if ($plazo_label === '') {
            abort(422, 'Hay que decir cómo se llama el plazo; es lo que sale impreso en el papel.');
        }

        if (mb_strlen($plazo_label) > self::LARGO_PLAZO_LABEL) {
            abort(422, 'El nombre del plazo no puede pasar de '.self::LARGO_PLAZO_LABEL.' caracteres.');
        }

        $valores = [
            'regla' => $regla,
            'corte' => $corte,
            'plazo_label' => $plazo_label,
            'plazo_dias' => $plazo_dias,
            'dias_reclamacion' => $dias_reclamacion,
            'titulo' => $this->textoCorto(Request::input('titulo'), 'El título'),
            'subtitulo' => $this->textoCorto(Request::input('subtitulo'), 'El subtítulo'),
            'firmantes' => json_encode($this->firmantesValidados(), JSON_UNESCAPED_UNICODE),
        ];

        // **`(int) (bool)` y no una lista blanca de valores**, que es lo que hacen los
        // doce interruptores del año. Arrastra su misma laxitud —la cadena `"false"` es
        // verdadera en PHP— y se acepta por lo mismo: los tres clientes mandan booleanos
        // de JSON de verdad, y un 422 aquí rompería a quien mande `1` en vez de `true`.
        // Las columnas son `tinyint(1)`, así que lo que entra es 0 o 1 y nunca un `false`
        // de PHP, que es la familia de fallos del 05 §13.
        foreach (array_keys(self::INTERRUPTORES) as $interruptor) {
            $valores[$interruptor] = (int) (bool) Request::input($interruptor);
        }

        $valores += $this->materiasDePrimariaValidadas((bool) $valores['primaria_activa']);

        return $valores;
    }

    /**
     * Las dos materias del parágrafo de primaria.
     *
     * Apuntan a `materias` y no a `asignaturas` porque la asignatura es de un grupo
     * concreto y el parágrafo vale para tres grados enteros. Y `materias` no lleva
     * `year_id` —es del colegio, no del año—, que es lo que hace que el id siga
     * valiendo cuando enero copia esta fila.
     *
     * ## Sólo se comprueban si el parágrafo está encendido
     *
     * Apagado, los dos ids viajan tal cual —incluso apuntando a nada— porque **no
     * deciden nada mientras el interruptor esté abajo** y el colegio que lo apagó por
     * una temporada espera encontrarse su selección al volver a encenderlo.
     *
     * ## Y NO se filtra `deleted_at`, que era la otra salida
     *
     * Una materia en la papelera sigue existiendo para la clave ajena, y filtrarla
     * aquí convertiría **volver a guardar una configuración heredada** en un 422 sobre
     * un campo que quien guarda ni ha tocado: basta con que el colegio haya mandado
     * esa materia a la papelera en enero. Es la misma decisión que tomó
     * `copiarLaPlantillaDelCompromiso()`, que tampoco filtra y deja el aviso en el log
     * en vez de decidir por el colegio.
     *
     * @return array<string, ?int>
     */
    private function materiasDePrimariaValidadas(bool $activa): array
    {
        $uno = $this->idOpcional(Request::input('primaria_materia_1_id'), 'La primera materia de primaria');
        $dos = $this->idOpcional(Request::input('primaria_materia_2_id'), 'La segunda materia de primaria');

        if (! $activa) {
            return ['primaria_materia_1_id' => $uno, 'primaria_materia_2_id' => $dos];
        }

        if ($uno === null || $dos === null) {
            abort(422, 'Con el parágrafo de primaria encendido hay que elegir las dos materias.');
        }

        // **Distintas**, porque «la promoción se circunscribe a dos asignaturas» con la
        // misma dos veces es una sola, y el papel diría dos veces lo mismo.
        if ($uno === $dos) {
            abort(422, 'Las dos materias del parágrafo de primaria tienen que ser distintas.');
        }

        $cuantas = DB::selectOne('SELECT COUNT(*) AS cuantas FROM materias WHERE id IN (?,?)', [$uno, $dos]);

        if ((int) $cuantas->cuantas < 2) {
            abort(422, 'Alguna de las dos materias del parágrafo de primaria ya no existe.');
        }

        return ['primaria_materia_1_id' => $uno, 'primaria_materia_2_id' => $dos];
    }

    /**
     * Los rótulos de firma que llegan de la pantalla.
     *
     * **Son cargos y no personas**, y de ahí sale el tope corto: «Coordinación
     * Académica» son 22 caracteres y el más largo que se le ocurra a un colegio no
     * llega a 80. Los nombres de quienes firman salen de `Year::datos()`, que se
     * confirma cada año por su lado; esto son los renglones.
     *
     * Una lista vacía se acepta y se guarda como `[]`: el `GET` la lee entonces como
     * «los de siempre», que es la regla de ausencia del módulo y la respuesta correcta
     * —un papel sin ningún renglón para firmar no es una configuración, es un error
     * que nadie ve hasta tenerlo impreso—.
     *
     * @return list<string>
     */
    private function firmantesValidados(): array
    {
        $firmantes = Request::input('firmantes');

        if (! is_array($firmantes)) {
            abort(422, 'Los firmantes tienen que venir como una lista.');
        }

        if (count($firmantes) > self::MAXIMO_FIRMANTES) {
            abort(422, 'No caben más de '.self::MAXIMO_FIRMANTES.' renglones de firma en el pie del papel.');
        }

        $limpios = [];

        foreach ($firmantes as $rotulo) {
            if (! is_string($rotulo)) {
                abort(422, 'Cada firmante tiene que ser un texto.');
            }

            $rotulo = trim($rotulo);

            if ($rotulo === '') {
                abort(422, 'Un renglón de firma no puede quedar en blanco; quítelo si no lo quiere.');
            }

            if (mb_strlen($rotulo) > self::LARGO_FIRMANTE) {
                abort(422, 'Un firmante no puede pasar de '.self::LARGO_FIRMANTE.' caracteres: es un cargo, no un nombre con cédula.');
            }

            $limpios[] = $rotulo;
        }

        return $limpios;
    }

    /**
     * Los bloques que llegan de la pantalla, comprobados uno a uno.
     *
     * La comprobación que de verdad importa es **la clave contra el catálogo**: una
     * clave inventada no dispara ningún error de base —la columna es `varchar(40)`—,
     * se guardaría tan ricamente y **no saldría nunca en el `GET`**, porque el
     * recorrido de `pintarBloques()` es por el catálogo. O sea que el colegio
     * escribiría un bloque entero, vería «Guardado» y no volvería a verlo jamás. Por
     * eso es 422 y no un silencio.
     *
     * @return list<array<string, mixed>>
     */
    private function bloquesValidados(): array
    {
        $bloques = Request::input('bloques');

        if (! is_array($bloques)) {
            abort(422, 'Los bloques tienen que venir como una lista.');
        }

        $claves = PlantillaDelCompromiso::claves();
        $vistas = [];
        $limpios = [];

        foreach ($bloques as $bloque) {
            if (! is_array($bloque)) {
                abort(422, 'Cada bloque tiene que venir como un objeto con `clave`, `titulo`, `cuerpo`, `activo` y `orden`.');
            }

            $clave = $bloque['clave'] ?? null;

            if (! is_string($clave) || ! in_array($clave, $claves, true)) {
                abort(422, 'El bloque `'.(is_string($clave) ? $clave : '?').'` no está en la plantilla del compromiso.');
            }

            // Dos veces la misma clave son dos respuestas a «qué dice este bloque», y el
            // `ON DUPLICATE KEY UPDATE` guardaría la última sin decir nada: quien mandó
            // las dos creería que guardó la primera.
            if (isset($vistas[$clave])) {
                abort(422, 'El bloque `'.$clave.'` viene dos veces.');
            }

            $vistas[$clave] = true;

            $cuerpo = $bloque['cuerpo'] ?? null;

            if ($cuerpo !== null && ! is_string($cuerpo)) {
                abort(422, 'El texto del bloque `'.$clave.'` tiene que ser un texto.');
            }

            $cuerpo = (string) $cuerpo;

            if (mb_strlen($cuerpo) > self::LARGO_CUERPO) {
                abort(422, 'El texto del bloque `'.$clave.'` pasa de '
                    .number_format(self::LARGO_CUERPO, 0, ',', '.').' caracteres.');
            }

            $limpios[] = [
                'clave' => $clave,
                'titulo' => $this->textoCorto($bloque['titulo'] ?? null,
                    'El encabezado del bloque `'.$clave.'`', self::LARGO_TITULO_BLOQUE),
                'cuerpo' => $cuerpo,
                'activo' => (int) (bool) ($bloque['activo'] ?? false),
                'orden' => $this->enteroEntre($bloque['orden'] ?? null, 0, self::MAXIMO_ORDEN,
                    'El orden del bloque `'.$clave.'` tiene que ser un número entre 0 y '.self::MAXIMO_ORDEN.'.'),
            ];
        }

        return $limpios;
    }

    /**
     * Un entero dentro de un rango, o 422 con el mensaje que le toque.
     *
     * `is_numeric` y **no `(int)` a secas**: `(int) 'tres'` es 0, y un 0 que entra por
     * la puerta de atrás en `corte` es un compromiso para todo el colegio. La
     * comparación con `!=` suelto es a propósito —`'3' != 3` es falso— para que el
     * `"3"` que manda un formulario pase y el `3.5` no.
     */
    private function enteroEntre(mixed $valor, int $minimo, int $maximo, string $mensaje): int
    {
        if (! is_numeric($valor) || (float) $valor != (int) $valor) {
            abort(422, $mensaje);
        }

        $valor = (int) $valor;

        if ($valor < $minimo || $valor > $maximo) {
            abort(422, $mensaje);
        }

        return $valor;
    }

    /**
     * Un texto de encabezado, opcional.
     *
     * **La cadena vacía se guarda como `NULL`**, que es lo que la pantalla quiere
     * decir cuando borra el título: sin título, el papel usa el del colegio. Guardar
     * `''` dejaría dos formas de decir lo mismo en la columna y un `??` que no acierta.
     */
    private function textoCorto(mixed $valor, string $que, int $largo = self::LARGO_TITULO): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (! is_string($valor)) {
            abort(422, $que.' tiene que ser un texto.');
        }

        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        if (mb_strlen($valor) > $largo) {
            abort(422, $que.' no puede pasar de '.$largo.' caracteres.');
        }

        return $valor;
    }

    /**
     * Un id que puede no venir. Vacío es `null` y basura es 422, que no es lo mismo:
     * «no he elegido materia» y «he mandado un id roto» se arreglan de forma distinta.
     */
    private function idOpcional(mixed $valor, string $que): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_numeric($valor) || (int) $valor <= 0) {
            abort(422, $que.' no es válida.');
        }

        return (int) $valor;
    }
}
