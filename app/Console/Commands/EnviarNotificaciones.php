<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\Publicador;
use App\Services\Notificaciones\TemasDeNotificacion;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Avisa a las familias de lo que ha pasado desde la última pasada.
 *
 * El plan entero está en `myvc_flutter/docs/notificaciones.md`. Lo que hay que
 * saber para tocar esto:
 *
 * ## Por qué esto es un comando y no pasa dentro de la petición
 *
 * Dos cosas que **no** se pueden hacer, y las dos por la misma razón:
 *
 * - **No enviar dentro de la petición del docente.** Si al guardar una nota el
 *   servidor llamara a Google, el docente esperaría a que Google contestara.
 *   Pasar una columna de treinta notas serían treinta llamadas a un tercero
 *   metidas en el camino crítico.
 * - **No usar colas.** `QUEUE_CONNECTION` está en `sync`, que significa
 *   «ejecuta ahora mismo, aquí» — o sea el problema de arriba con otro nombre. Y
 *   una cola de verdad necesita un proceso vivo escuchando, que en hosting
 *   compartido no lo hay.
 *
 * Queda el cron, que sí hay. **Y no hace falta uno nuevo**: este proyecto ya
 * decidió *un solo cron por colegio* —`schedule:run` cada minuto— y lo que corre
 * se decide en `app/Console/Kernel.php`, que viaja con el `app/`. Añadir esto
 * fue una línea allí, no dieciséis visitas a paneles de cPanel.
 *
 * ## Agrupar es lo que lo hace viable, y de paso lo hace mejor
 *
 * Un docente que pasa una columna genera treinta cambios en dos minutos. Sin
 * agrupar son treinta avisos y el acudiente apaga las notificaciones para
 * siempre. Agrupado por alumno y asignatura es uno: «Laura tiene 4 notas nuevas
 * en Matemáticas». Menos peticiones y menos molestia, la misma decisión.
 *
 * ## Ningún aviso lleva el dato dentro
 *
 * «4 notas nuevas en Matemáticas», nunca «sacó 45». Una notificación se ve en la
 * pantalla bloqueada, en el bus, con gente al lado, y una nota de un menor no es
 * algo que deba aparecer ahí. Además evita el caso feo: el aviso llega aunque el
 * colegio tenga las notas bloqueadas (`alumnos_can_see_notas = 0`), y sería
 * absurdo que la notificación enseñara lo que la app niega.
 *
 * ## La marca, y por qué la primera pasada no manda nada
 *
 * Se guarda por dónde iba cada fuente —el último `id` procesado— en el caché de
 * fichero, que es propio de cada colegio. **La primera vez no hay marca, y
 * entonces esto NO manda nada: la pone y se va.** Sin eso, encender las
 * notificaciones en un colegio le mandaría a cada familia un aviso por cada nota
 * del año, que es la peor primera impresión posible de una función nueva.
 *
 * ## Y la marca se guarda DESPUÉS de publicar
 *
 * A propósito. Si el proceso se cae entre publicar y guardar, la pasada
 * siguiente repite el aviso; si se guardara antes, lo perdería. **Un aviso
 * repetido es una molestia y uno perdido es la función sin cumplir**, así que
 * ante la duda se repite.
 */
class EnviarNotificaciones extends Command
{
    protected $signature = 'notificaciones:enviar
                            {--seco : No manda nada y no mueve la marca: sólo dice qué mandaría}';

    protected $description = 'Avisa por push de las notas, ausencias, situaciones y publicaciones nuevas';

    /**
     * El tope por fuente y pasada.
     *
     * No es una regla de negocio: es lo que impide que la primera pasada de un
     * colegio con la marca perdida —el caché de fichero se borra con un
     * `cache:clear`— intente publicar miles de avisos de golpe contra un tercero.
     * Con `cada_minutos = 15` y una jornada normal, lo normal es cero o unos
     * pocos.
     */
    private const TOPE_POR_FUENTE = 300;

    public function handle(Publicador $publicador): int
    {
        if (! TemasDeNotificacion::hayComoDerivar()) {
            $this->warn('Sin `APP_KEY` ni `NOTIFICACIONES_SECRETO`: no hay con qué derivar los temas.');

            return 0;
        }

        $seco = (bool) $this->option('seco');

        // **Sin credenciales no es un error.** Al principio esto va a ser el caso
        // en los dieciséis colegios, y el comando lo corre un cron cada quince
        // minutos: devolver un código de error sería llenar el registro de un
        // fallo que no lo es. Se dice y se sale con 0.
        if (! $seco && ! $publicador->estaConfigurado()) {
            $this->line('Firebase no está configurado en este colegio: no se manda nada.');
            $this->line('Hace falta FCM_PROYECTO y el JSON de la cuenta de servicio (ver config/notificaciones.php).');

            return 0;
        }

        $mandados = 0;

        $mandados += $this->porFuente('notas', fn ($desde) => $this->avisosDeNotas($desde), $publicador, $seco);
        $mandados += $this->porFuente('asistencia', fn ($desde) => $this->avisosDeAsistencia($desde), $publicador, $seco);
        $mandados += $this->porFuente('disciplina', fn ($desde) => $this->avisosDeDisciplina($desde), $publicador, $seco);
        $mandados += $this->porFuente('muro', fn ($desde) => $this->avisosDelMuro($desde), $publicador, $seco);
        $mandados += $this->porFuente('matricula', fn ($desde) => $this->avisosDeMatricula($desde), $publicador, $seco);

        $this->info(($seco ? 'Se mandarían ' : 'Mandados ').$mandados.' avisos.');

        return 0;
    }

    /**
     * Una fuente: lee la marca, pide los avisos, los publica y adelanta la marca.
     *
     * @param  callable(int): array{avisos: array<int, array<string, mixed>>, hasta: int}  $buscar
     */
    private function porFuente(string $fuente, callable $buscar, Publicador $publicador, bool $seco): int
    {
        $clave = 'notificaciones.marca.'.$fuente;
        $marca = Cache::get($clave);

        $resultado = $buscar(is_numeric($marca) ? (int) $marca : 0);

        // Primera pasada: se pone la marca y no se manda nada. Ver la nota de la
        // clase — sin esto, encender el push le manda a cada familia un aviso por
        // cada fila del año.
        if ($marca === null) {
            if (! $seco) {
                Cache::forever($clave, $resultado['hasta']);
            }

            $this->line("[$fuente] primera pasada: marca puesta en {$resultado['hasta']}, sin avisar de lo viejo.");

            return 0;
        }

        // **Un tope que recorta tiene que decirlo.** Si se alcanza, la marca avanza
        // igual hasta el final del tramo, así que lo que quedó fuera no se avisa
        // nunca — y sin esta línea el comando diría «mandados 300» y parecería que
        // los mandó todos. Con quince minutos entre pasadas no debería pasar; si
        // pasa, es que la marca se perdió (un `cache:clear`) y hay que mirarlo.
        if (count($resultado['avisos']) >= self::TOPE_POR_FUENTE) {
            $this->warn("[$fuente] se alcanzó el tope de ".self::TOPE_POR_FUENTE
                .' avisos: lo que pasara de ahí NO se avisa y la marca avanza igual.');
        }

        $mandados = 0;

        foreach ($resultado['avisos'] as $aviso) {
            if ($seco) {
                $this->line("[$fuente] {$aviso['tema']} :: {$aviso['titulo']} — {$aviso['cuerpo']}");
                $mandados++;

                continue;
            }

            if ($publicador->publicar($aviso['tema'], $aviso['titulo'], $aviso['cuerpo'], $aviso['datos'] ?? [])) {
                $mandados++;
            }
        }

        // Después de publicar, nunca antes. Ver la nota de la clase.
        if (! $seco) {
            Cache::forever($clave, $resultado['hasta']);
        }

        return $mandados;
    }

    /**
     * Notas nuevas o cambiadas, **agrupadas por alumno y asignatura**.
     *
     * Sale de `bitacoras`, que ya registra cada `PUT notas/update/{id}` y cada
     * nota de un lote con `affected_element_type = 'Nota'`. No hace falta tabla
     * nueva ni columna nueva: el rastro que el colegio mira cuando alguien
     * reclama es el mismo que dice qué avisar.
     *
     * El `JOIN` hasta `materias` es lo que permite decir «en Matemáticas» en vez
     * de «hay notas nuevas», y es la diferencia entre un aviso que sirve y uno
     * que obliga a abrir la app para saber de qué va. **El nombre está en
     * `materias` y no en `asignaturas`**: una asignatura es una materia dictada a
     * un grupo, y sólo lleva las claves.
     *
     * ## Sale en el detector del boletín independiente y **NO se acota**
     *
     * `tools/unidades-sin-alcance.py` la marca porque une `unidades` sin comparar
     * `u.alumno_id`. Es cierto, y aquí **no hay nada que acotar**: el camino
     * `nota -> subunidad -> unidad -> asignatura -> materia` arranca de UNA nota ya
     * identificada por `b.affected_element_id` y sube por claves ajenas. No
     * selecciona un conjunto: **resuelve un nombre**. Una condición sobre
     * `u.alumno_id` no podría quitar filas de más — sólo quitar la única que hay, y
     * eso es una familia que se queda sin el aviso.
     *
     * Y tendría un caso donde lo haría de verdad: marcar a un alumno **no le borra
     * las notas que ya tiene en las subunidades del grupo** (§1 del plan). Un
     * alcance correlacionado le daría a esas notas dueño = el alumno y a la unidad
     * `alumno_id` NULL, no emparejarían, y el aviso de un cambio real se perdería
     * en silencio. La distinción con `grupos_desactualizados` —que tampoco se
     * acota— es que aquélla agrega sobre muchas notas y ésta sube desde una sola.
     *
     * @return array{avisos: array<int, array<string, mixed>>, hasta: int}
     */
    private function avisosDeNotas(int $desde): array
    {
        $tope = (int) (DB::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM bitacoras')->m ?? 0);

        $filas = DB::select(
            'SELECT b.affected_user_id AS alumno_id,
                    COALESCE(NULLIF(mat.alias, ""), mat.materia) AS asignatura,
                    COUNT(*) AS cuantas
               FROM bitacoras b
               INNER JOIN notas n ON n.id = b.affected_element_id
               INNER JOIN subunidades s ON s.id = n.subunidad_id
               INNER JOIN unidades u ON u.id = s.unidad_id
               INNER JOIN asignaturas asig ON asig.id = u.asignatura_id
               INNER JOIN materias mat ON mat.id = asig.materia_id
              WHERE b.id > ? AND b.id <= ?
                AND b.affected_element_type = "Nota"
                AND b.affected_user_id IS NOT NULL
                AND b.deleted_at IS NULL
              GROUP BY b.affected_user_id, asignatura
              ORDER BY b.affected_user_id
              LIMIT '.self::TOPE_POR_FUENTE,
            [$desde, $tope]
        );

        $avisos = [];

        foreach ($filas as $fila) {
            $cuantas = (int) $fila->cuantas;

            $avisos[] = [
                'tema' => TemasDeNotificacion::deAlumnoYTipo((int) $fila->alumno_id, 'notas'),
                'titulo' => 'Notas nuevas',
                'cuerpo' => $cuantas === 1
                    ? 'Hay 1 nota nueva en '.$fila->asignatura.'.'
                    : 'Hay '.$cuantas.' notas nuevas en '.$fila->asignatura.'.',
                'datos' => ['pantalla' => 'notas'],
            ];
        }

        return ['avisos' => $avisos, 'hasta' => $tope];
    }

    /**
     * Ausencias y tardanzas registradas, agrupadas por alumno.
     *
     * **No se distingue tardanza de ausencia en el texto** aunque la columna
     * `tipo` lo diga: `cantidad_tardanza` y `cantidad_ausencia` pueden venir las
     * dos en la misma fila, así que un aviso que afirmara una de las dos se
     * equivocaría la mitad de las veces. «Se registró asistencia» y que la
     * familia abra.
     *
     * @return array{avisos: array<int, array<string, mixed>>, hasta: int}
     */
    private function avisosDeAsistencia(int $desde): array
    {
        $tope = (int) (DB::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM ausencias')->m ?? 0);

        $filas = DB::select(
            'SELECT alumno_id, COUNT(*) AS cuantas
               FROM ausencias
              WHERE id > ? AND id <= ? AND alumno_id IS NOT NULL AND deleted_at IS NULL
              GROUP BY alumno_id
              LIMIT '.self::TOPE_POR_FUENTE,
            [$desde, $tope]
        );

        $avisos = [];

        foreach ($filas as $fila) {
            $cuantas = (int) $fila->cuantas;

            $avisos[] = [
                'tema' => TemasDeNotificacion::deAlumnoYTipo((int) $fila->alumno_id, 'asistencia'),
                'titulo' => 'Asistencia',
                'cuerpo' => $cuantas === 1
                    ? 'Se registró una novedad de asistencia.'
                    : 'Se registraron '.$cuantas.' novedades de asistencia.',
                'datos' => ['pantalla' => 'asistencia'],
            ];
        }

        return ['avisos' => $avisos, 'hasta' => $tope];
    }

    /**
     * Situaciones anotadas en el observador, agrupadas por alumno.
     *
     * @return array{avisos: array<int, array<string, mixed>>, hasta: int}
     */
    private function avisosDeDisciplina(int $desde): array
    {
        $tope = (int) (DB::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM dis_procesos')->m ?? 0);

        $filas = DB::select(
            'SELECT alumno_id, COUNT(*) AS cuantas
               FROM dis_procesos
              WHERE id > ? AND id <= ? AND alumno_id IS NOT NULL AND deleted_at IS NULL
              GROUP BY alumno_id
              LIMIT '.self::TOPE_POR_FUENTE,
            [$desde, $tope]
        );

        $avisos = [];

        foreach ($filas as $fila) {
            $cuantas = (int) $fila->cuantas;

            $avisos[] = [
                'tema' => TemasDeNotificacion::deAlumnoYTipo((int) $fila->alumno_id, 'disciplina'),
                'titulo' => 'Observador',
                // Sin decir de qué tipo ni qué pasó: eso se lee dentro, y lo que
                // llega al bolsillo no puede contarlo delante de nadie.
                'cuerpo' => $cuantas === 1
                    ? 'Se anotó una situación. Ábrela para verla.'
                    : 'Se anotaron '.$cuantas.' situaciones. Ábrelas para verlas.',
                'datos' => ['pantalla' => 'disciplina'],
            ];
        }

        return ['avisos' => $avisos, 'hasta' => $tope];
    }

    /**
     * Publicaciones nuevas del muro: **un solo aviso para todo el colegio**.
     *
     * No se agrupa por alumno porque no es de nadie, y **su contenido sigue siendo
     * público a propósito**: «hay 3 publicaciones nuevas» no dice nada de ningún
     * menor.
     *
     * **Pero el tema sí lleva HMAC, y hasta el 26 ago 2026 no lo llevaba.** Este
     * docblock decía que no hacía falta, y razonaba una sola de las dos cosas que
     * hace ese hash: esconder de quién es —que aquí no hace falta— y **separar un
     * colegio de otro** —que sí—. El proyecto de Firebase es uno para los quince,
     * así que `colegio_muro` a secas era el mismo tema para todos. Lo encontró
     * `myvc_flutter` leyendo el contrato antes de cablearlo. Ver
     * `TemasDeNotificacion::delColegio()`.
     *
     * **Sólo las que son para alumnos o acudientes.** El muro tiene interruptores
     * por destinatario, y avisar a las familias de una publicación marcada sólo
     * `para_profes` sería enseñarles que existe.
     *
     * @return array{avisos: array<int, array<string, mixed>>, hasta: int}
     */
    /**
     * **El día de matrículas: dónde va la familia.**
     *
     * Decidido por Joseth el 20 sep 2026 —*«al acudiente se le avisa en CADA
     * estación»*, `myvc_flutter/docs/estaciones.md` §2.2 bis— contra la otra opción
     * que se planteó, avisar sólo al devolver. La familia sabe en todo momento dónde
     * va y a dónde sigue **sin preguntarle a nadie**, que es lo que hace la fila más
     * corta.
     *
     * ## POR QUÉ ESTO ES UNA FUENTE DEL CRON Y NO UN PUSH AL CERRAR EL PASO
     *
     * El 46 §5.3 pedía publicar **dentro** de la petición que cierra el paso. Se le
     * puso delante a Joseth la contradicción con este mismo fichero y **rectificó el
     * mismo día**: *«que llegue cuando tenga que llegar, no me voy a complicar con
     * que le llegue de inmediato»*.
     *
     * Y la prohibición de la cabecera de esta clase aquí **aplica más fuerte, no
     * menos**: de sus dos motivos, el de *volumen* no vale —cerrar un paso es una
     * acción por familia, no treinta— pero el de *latencia* sí, porque **quien
     * atiende tiene una fila delante y está en un patio con mala señal**. Un docente
     * esperando a que Google conteste con doce personas mirándolo es peor aquí que en
     * la planilla de notas.
     *
     * ## LA MARCA ES UN `YmdHis`, Y ESO NO ES INGENIO: ES EL PROBLEMA DE LOS DOS RELOJES
     *
     * Las otras cuatro fuentes marcan por `id`, porque miran tablas donde **cada
     * evento es una fila nueva**. Aquí no: `requisitos_alumno` se rellena de forma
     * perezosa y después **se actualiza**, así que su `id` no se mueve cuando una
     * estación cierra el paso. Lo que se mueve es `cerrado_at`.
     *
     * Y un sello no se puede convertir a segundos de época para compararlo:
     * `cerrado_at` lo escribe la aplicación con `Carbon::now('America/Bogota')` —un
     * reloj de pared— y `FROM_UNIXTIME()` lo leería con **la zona de la sesión de
     * MySQL**, que en el docker es UTC. Son cinco horas, y el fallo saldría como
     * *«los avisos llegan con retraso»* sin que nada se ponga rojo. Es el caso de
     * `importaciones` otra vez: *dos relojes en el mismo sitio y el que se lee no
     * lleva escrito cuál es.*
     *
     * Por eso la marca es el propio sello **como número** —`20260920170600`—,
     * comparado contra `DATE_FORMAT(...)`: los dos lados son la misma cadena de
     * pared, así que no hay ninguna zona de por medio. Sigue siendo un entero
     * creciente, que es lo único que `porFuente` pide.
     *
     * ## Y EL BORDE VA CON `>=`, QUE NO ES UN DESCUIDO: ES LA DOCTRINA DE ESTA CLASE
     *
     * `timestamp` tiene precisión de **segundo**, así que un paso cerrado en el mismo
     * segundo en que corrió la pasada anterior cae justo en la marca. Con `>` **se
     * pierde para siempre**: la marca ya avanzó y ese sello nunca vuelve a entrar en
     * ninguna ventana.
     *
     * Con `>=` se repite **una vez** —sólo los de ese segundo exacto, y sólo en la
     * pasada siguiente—, que es exactamente lo que la cabecera de esta clase decide
     * para el otro borde: *«un aviso repetido es una molestia y uno perdido es la
     * función sin cumplir, así que ante la duda se repite»*.
     *
     * Es la misma medicina que el 46 §4 tuvo que recetarle a la huella de las
     * estaciones, y por el mismo motivo: *cuando el reloj no tiene resolución
     * suficiente, se elige de qué lado se falla.* Lo destapó el test, que corre entero
     * dentro de un segundo y por tanto **vive siempre en ese borde**.
     *
     * ## UNO POR FAMILIA Y POR PASADA, Y NO UNO POR ESTACIÓN — se dice
     *
     * La decisión fue *«en cada estación»* y esto manda **como mucho uno por pasada**,
     * nombrando **dónde está ahora**. No es un recorte: el cron va cada quince
     * minutos y agrupar es lo que hace viable esta clase entera. Dos pasos cerrados
     * dentro de la misma ventana son **un** aviso con la estación buena, en vez de dos
     * de los que el primero ya es mentira al llegar.
     *
     * Y lo que la decisión protegía se conserva entero: el riesgo que ella misma
     * nombra es que *«la familia aprenda a ignorarlos»*, y agrupar va en esa
     * dirección, no en contra.
     *
     * ## EL NOMBRE SÍ, EL MOTIVO NO
     *
     * `notificaciones.md` permite nombrar al menor y prohíbe el contenido. Aquí eso
     * es *«Laura pasó a Tesorería»* y *«Laura fue devuelta en Documentos»* **sin el
     * motivo dentro**: el motivo lo escribió un docente para que lo lea la familia,
     * pero se lee **abriendo la app** —`GET requisitos/mi-recorrido/{alumno_id}`—, no
     * en la pantalla bloqueada de un bus.
     */
    private function avisosDeMatricula(int $desde): array
    {
        $tope = (int) Carbon::now('America/Bogota')->format('YmdHis');

        // El último movimiento de cada alumno dentro de la ventana, y si fue una
        // devolución. **`cerrado_at` para avanzar y `updated_at` para devolver**,
        // porque devolver limpia `cerrado_at` a propósito (el paso se sigue debiendo)
        // y sin la segunda mitad la devolución no movería nada que esto pueda ver.
        $filas = DB::select(
            'SELECT ra.alumno_id,
                    MAX(CASE WHEN LOWER(TRIM(ra.estado)) = "devuelto" THEN 1 ELSE 0 END) AS devuelto,
                    MAX(r.orden) AS orden
               FROM requisitos_alumno ra
               INNER JOIN requisitos_matricula r ON r.id = ra.requisito_id AND r.deleted_at IS NULL
               INNER JOIN alumnos a ON a.id = ra.alumno_id AND a.deleted_at IS NULL
              WHERE (
                      (ra.cerrado_at IS NOT NULL
                       AND DATE_FORMAT(ra.cerrado_at, "%Y%m%d%H%i%s") + 0 >= ?
                       AND DATE_FORMAT(ra.cerrado_at, "%Y%m%d%H%i%s") + 0 <= ?)
                   OR (LOWER(TRIM(ra.estado)) = "devuelto"
                       AND DATE_FORMAT(ra.updated_at, "%Y%m%d%H%i%s") + 0 >= ?
                       AND DATE_FORMAT(ra.updated_at, "%Y%m%d%H%i%s") + 0 <= ?)
                    )
              GROUP BY ra.alumno_id
              ORDER BY ra.alumno_id
              LIMIT '.self::TOPE_POR_FUENTE,
            [$desde, $tope, $desde, $tope]
        );

        $avisos = [];

        foreach ($filas as $fila) {
            $alumnoId = (int) $fila->alumno_id;
            $nombre = $this->primerNombreDe($alumnoId);
            $donde = $this->dondeVa($alumnoId, (int) $fila->orden, (bool) $fila->devuelto);

            if ($donde === null) {
                // Cerró el último paso que le quedaba: el recorrido está completo. Es
                // la única buena noticia del día y no tiene estación que nombrar.
                $avisos[] = [
                    'tema' => TemasDeNotificacion::deAlumnoYTipo($alumnoId, 'matricula'),
                    'titulo' => 'Matrícula',
                    'cuerpo' => $nombre.' terminó el recorrido de matrícula.',
                    'datos' => ['pantalla' => 'matricula', 'alumno_id' => (string) $alumnoId],
                ];

                continue;
            }

            $avisos[] = [
                'tema' => TemasDeNotificacion::deAlumnoYTipo($alumnoId, 'matricula'),
                'titulo' => 'Matrícula',
                'cuerpo' => $fila->devuelto
                    ? $nombre.' fue devuelta en '.$donde.'. Abre la app para ver por qué.'
                    : $nombre.' pasó a '.$donde.'.',
                'datos' => ['pantalla' => 'matricula', 'alumno_id' => (string) $alumnoId],
            ];
        }

        return ['avisos' => $avisos, 'hasta' => $tope];
    }

    /**
     * El nombre con el que se le habla a la familia. **El primero y no la ficha
     * entera**: un aviso que dice «Laura» se lee de un vistazo, y uno que dice
     * «LAURA SOFÍA GUTIÉRREZ PÉREZ» ocupa la notificación sin decir nada más.
     */
    private function primerNombreDe(int $alumnoId): string
    {
        $fila = DB::selectOne('SELECT nombres FROM alumnos WHERE id=? AND deleted_at IS NULL',
            [$alumnoId]);

        $nombres = trim((string) ($fila->nombres ?? ''));

        if ($nombres === '') {
            return 'Tu hijo';
        }

        return explode(' ', $nombres)[0];
    }

    /**
     * **La estación en la que está ahora**, o `null` si ya no le queda ninguna.
     *
     * Una «estación» es el grupo de requisitos que comparten `orden`, así que está
     * cerrada cuando **todos** los suyos lo están — contando sobre el catálogo y no
     * sobre las filas que existan, porque `requisitos_alumno` se rellena perezosamente
     * y contar lo existente daría por hecha una estación de dos con un solo paso
     * cerrado.
     *
     * **Esa regla también vive en `EstacionesController::cerrada()`, y aquí es una
     * consulta y no una llamada.** Va dicho en vez de tapado: aquel método trabaja
     * sobre el mapa que ya tiene cargado en memoria para pintar las colas, y esto
     * necesita la misma pregunta para un alumno suelto desde un comando que no monta
     * ese mapa. *El día que una tercera cosa la necesite, lo que toca es sacarla a un
     * `Support` — no copiarla otra vez.*
     *
     * Al **devolver**, la estación es la del paso devuelto: la familia tiene que
     * volver ahí, y `cerrado_at` ya se limpió, así que sale sola por ser la primera
     * abierta.
     */
    private function dondeVa(int $alumnoId, int $ordenDelEvento, bool $devuelto): ?string
    {
        $fila = DB::selectOne(
            'SELECT r.orden, MIN(r.requisito) AS nombre
               FROM requisitos_matricula r
              INNER JOIN years y ON y.id = r.year_id AND y.deleted_at IS NULL AND y.actual = 1
              LEFT JOIN requisitos_alumno ra ON ra.requisito_id = r.id AND ra.alumno_id = ?
              WHERE r.deleted_at IS NULL
              GROUP BY r.orden
             HAVING SUM(CASE WHEN ra.cerrado_at IS NOT NULL THEN 1 ELSE 0 END) < COUNT(r.id)
              ORDER BY r.orden
              LIMIT 1',
            [$alumnoId]
        );

        if (! $fila) {
            return null;
        }

        // Si lo que pasó fue una devolución, el aviso nombra la estación del paso
        // devuelto aunque el recorrido tenga otra abierta antes. Es a donde tiene que
        // volver, y es lo único que la familia necesita saber.
        if ($devuelto && (int) $fila->orden !== $ordenDelEvento) {
            $suya = DB::selectOne(
                'SELECT MIN(requisito) AS nombre FROM requisitos_matricula r
                  INNER JOIN years y ON y.id = r.year_id AND y.deleted_at IS NULL AND y.actual = 1
                  WHERE r.orden = ? AND r.deleted_at IS NULL',
                [$ordenDelEvento]
            );

            if ($suya && $suya->nombre !== null) {
                return (string) $suya->nombre;
            }
        }

        return (string) $fila->nombre;
    }

    private function avisosDelMuro(int $desde): array
    {
        $tope = (int) (DB::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM publicaciones')->m ?? 0);

        $cuantas = (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM publicaciones
              WHERE id > ? AND id <= ? AND deleted_at IS NULL
                AND (para_todos = 1 OR para_alumnos = 1 OR para_acudientes = 1)',
            [$desde, $tope]
        )->c ?? 0);

        $avisos = [];

        if ($cuantas > 0) {
            $avisos[] = [
                'tema' => TemasDeNotificacion::delColegio('colegio_muro'),
                'titulo' => 'Novedades del colegio',
                'cuerpo' => $cuantas === 1
                    ? 'Hay una publicación nueva.'
                    : 'Hay '.$cuantas.' publicaciones nuevas.',
                'datos' => ['pantalla' => 'muro'],
            ];
        }

        return ['avisos' => $avisos, 'hasta' => $tope];
    }
}
