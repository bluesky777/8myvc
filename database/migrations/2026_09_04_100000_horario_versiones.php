<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Horario: las tres tablas de la §5.1 y el puntero del año.
 *
 * Es el suelo del módulo de horarios (`docs/migracion/23-horarios.md`, v2 del 2
 * sep 2026): el horario se cuadra en un **programa de escritorio** y a esta API
 * le queda **guardar versiones del horario de un año y decir cuál es la
 * oficial**. Salones, disponibilidad, rejilla y timbres **no existen en el
 * servidor** (§4), y por eso el veredicto de la §6 tiene que nombrar lo que no
 * pudo comprobar.
 *
 * Ninguna tabla de aquí la lee nadie que imprima un boletín. Nada de lo que hay
 * se toca: tres `CREATE TABLE` y un `ADD COLUMN ... NULL`.
 *
 * ## La oficial es un PUNTERO, no una bandera — y es lo que decide esta forma
 *
 * `years.horario_version_id`, no `horario_versiones.oficial`. MySQL no tiene
 * índices parciales, así que una columna `oficial tinyint(1)` **no se puede atar
 * a «como mucho una por año»**: el día que haya dos en verdadero, quien lea
 * `WHERE oficial = 1 LIMIT 1` se lleva una de las dos y **no se pone nada rojo**.
 * Un puntero no admite ese estado, y «todavía no hay ninguna» es `NULL`, que es
 * un estado y no un accidente (§5.1).
 *
 * Va con `ON DELETE SET NULL` y no con cascada: borrar una versión no puede
 * llevarse el año por delante, y tampoco puede dejarlo apuntando a una fila que
 * ya no está. Que el año se quede sin oficial es exactamente lo que pasó.
 *
 * ## UN solo `Schema::table('years', …)`, y esto es de lo que se rompe
 *
 * En MySQL 8.0 un `ADD COLUMN` es `ALGORITHM=INSTANT` y no cuesta nada. **Pero
 * no todos los colegios están en 8.0** —son quince cuentas de cPanel distintas y
 * la versión de cada una no está verificada—, y en 5.7 cada sentencia `ALTER`
 * reconstruye la tabla entera bloqueando las escrituras mientras dura. Aquí sólo
 * hay una columna, así que la regla no cuesta nada de aplicar; queda escrita
 * porque **quien añada la segunda la mete en ESTE bloque** si la migración no ha
 * salido, y en un bloque único propio si ya salió. La forma y el porqué salen de
 * `2026_09_02_100000_nivelaciones_columnas`. `years` es una fila por año: aquí no
 * hay ventana que medir.
 *
 * ## Y esta migración NO se puede sacar sola: exige la de nivelación de la tanda
 *
 * La columna entra con `->after('regla_nivelacion')`, y `ADD COLUMN … AFTER x` con una
 * `x` que no existe **falla**. `regla_nivelacion` la añade
 * `2026_09_02_100000_nivelaciones_columnas`, o sea otra migración de esta misma tanda.
 * Con `php artisan migrate` no hay riesgo —corre las pendientes en orden y aquélla va
 * antes—, pero **no hay camino «sólo horario»**: quien intente sacar este módulo a un
 * colegio sin pasar la tanda entera se lleva un error de columna desconocida, no una
 * migración aditiva que no hace nada. Queda escrito aquí porque «migración aditiva» se
 * lee como «se puede sacar cuando sea», y ése es justo el atajo que alguien intenta
 * cuando quiere desplegar una cosa sola. Lo levantó `8myvc-23` el 2 sep 2026.
 *
 * ## Por qué el puntero NO se copia al año siguiente
 *
 * `YearsController::postStore` crea el año nuevo copiando el anterior columna a
 * columna, y `CentinelaDeLasColumnasDelAnioNuevoTest` exige que cada columna esté
 * **nombrada**: copiada, o excusada con su motivo. Ésta se excusa, y el motivo es
 * de dominio: copiarla dejaría al año nuevo afirmando que su horario oficial es
 * **una versión del año anterior**. Con la decisión 13 —subir y publicar valen en
 * cualquier año— eso no es teórico: es justo el estado que el puntero en `years`
 * existe para que no pase, «cada año tiene el suyo y no se pisan» (§5.2).
 *
 * ## Y la columna se reparte sola a tres respuestas vivas
 *
 * `YearsController::getIndex`, `::getColegio` y `getTrashed` leen `years` con
 * `SELECT *`, así que `horario_version_id` aparece **sin que nadie la mande** en
 * `GET years`, `GET years/colegio` y la de papelera, y mueve sus tres
 * instantáneas de muestreo. Vale `null` en todos los años, así que es lo más
 * inofensivo que se le puede mandar a los cuatro clientes — **pero es un campo
 * nuevo y se manda dicho, no descubierto** (§5.1 y el canal con el front).
 */
class HorarioVersiones extends Migration
{
    public function up()
    {
        Schema::create('horario_versiones', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('year_id');
            $tabla->string('nombre');

            // Sale del TOKEN, nunca del cuerpo (§5.2.2): un identificador de
            // persona que llega por el cuerpo y no comprueba nadie es un patrón
            // que aquí tiene herramienta propia
            // (`tools/identificadores-del-cuerpo.py`). Sin foránea y `integer`
            // pelado, como `notas.nivelada_por` y los `created_by` del esquema:
            // el rastro sobrevive a que la cuenta se borre.
            $tabla->integer('subida_por')->nullable();

            // El fichero de proyecto del escritorio, en la fila y sin comprimir.
            // **`text` NO vale**: son 65.535 bytes y la cota alta medida por el
            // front el 2 sep 2026 —el horario entero colocado, 312 de 313
            // piezas— son **128.779 bytes de fichero y 185.997 de cuerpo**. Se
            // pasaría del tope el día del primer proyecto real, no en el
            // colegio catorce. `mediumText` son 16 MB, 130 veces esa cota, y por
            // encima queda el que corta de verdad —`max_allowed_packet`, 64 MB
            // en el docker y 4 MB en el peor caso plausible de cPanel—, así que
            // subir a `longText` no compraría un byte usable (§10.2.2).
            //
            // **NOT NULL porque el blob sube siempre** (decisión 14): sin él el
            // trabajo de un mes vive en un portátil. El 422 va DELANTE, en el
            // controlador, para que el cliente reciba un mensaje y no un error
            // de SQL.
            $tabla->mediumText('proyecto');

            // El veredicto de la opción B (§6), que escribe el SERVIDOR y **no se
            // lee del cuerpo nunca**: si viaja de fuera, un cliente sube un
            // horario con «comprobado todo ✓» encima y el historial deja de
            // servir para lo único que sirve (§5.2.3). Nulo mientras no se haya
            // escrito — y en MySQL 5.7 una columna de texto no admite `DEFAULT`,
            // así que nullable no es una preferencia.
            $tabla->text('comprobaciones')->nullable();

            $tabla->timestamps();

            // Listar y derivar van siempre por el año.
            $tabla->index('year_id', 'horario_versiones_year');
            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
        });

        Schema::create('horario_lecciones', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('version_id');

            // **Una clave del CLIENTE, y por eso es texto.** El `pieza_id` lo
            // genera el escritorio en su fichero de proyecto; aquí sólo mantiene
            // juntas las filas que son la misma pieza —y con eso la
            // sincronización de la misa deja de ser una regla: una pieza está en
            // una sola casilla porque es una sola pieza (§5.1)—. Un `integer`
            // funcionaría hoy y se rompería sin aviso el día que el escritorio
            // use un uuid; agrupar por texto cuesta lo mismo.
            //
            // **CONFIRMADO POR EL FRONT el 2 sep 2026, y el motivo es más duro que
            // el de arriba.** Medido sobre el proyecto real con el horario entero
            // colocado: **313 piezas, todas únicas, longitud 7 exactos** —mínima y
            // máxima— y de la forma `a<asignatura_id>-<índice>`, o sea `a1196-0`.
            // **0 de 313 son sólo dígitos**: empiezan por `a` y llevan un guion, así
            // que un `int` no aguanta ni la primera subida. En el modelo del
            // escritorio `Pieza.id` ya es `string`.
            //
            // **Y de aquí sale un 422 que le toca al que escriba la subida: la
            // longitud se valida, no se deja truncar.** El núcleo del escritorio no
            // limita longitud ni juego de caracteres ni el vacío —el formato
            // `a{id}-{i}` es convención del llamador, no garantía del núcleo—, y el
            // truncado aquí es de la familia cara: **dos piezas que truncan a los
            // mismos 64 caracteres se fusionan en una sola**, y entonces filas que no
            // son una misa comparten `pieza_id` en una casilla y la comprobación de
            // choque de docente se calcula **sobre una pieza que no existe**. No da
            // error: da un horario equivocado, y en MySQL no estricto no dice nada.
            $tabla->string('pieza_id', 64);

            // `asignatura_id` es una clave de MyVC, y ésa es la frontera de la
            // §8: un proyecto armado sin MyVC no tiene ninguna, así que **no se
            // puede subir** y la ruta lo dice con un 422 nombrando la pieza. Ni
            // nulos —dejarían «Clases de hoy» igual de vacía que en la §2, esta
            // vez con un horario oficial encima— ni emparejar por nombres, que
            // mete las horas de «Matemáticas de 3°A» en 3°B sin dar ningún error.
            $tabla->unsignedInteger('asignatura_id');

            // Los tres números que admiten dos lecturas, declarados y no
            // deducidos (§5.2.5 y §5.2.6):
            //   `dia`      va de 0 a 6, con **0 = domingo**, que es el convenio
            //              con el que se CONSUMEN las siete columnas
            //              (`asignaturas_dia()` sobre `Carbon::dayOfWeek`), de
            //              modo que la derivación de la §7 no traduce nada. Un
            //              `dia` fuera de 0..6 se rechaza con 422 en el
            //              controlador; uno dentro pero con el convenio cambiado
            //              **no lo detecta nadie** —el horario entero se corre un
            //              día y las tres reglas de la §6 se cumplen igual—, y por
            //              eso el convenio se declara en vez de deducirse.
            //   `franja`   va en **base 1**: la 1 es la primera lección del día.
            //   `duracion` se cuenta en **casillas**, no en minutos: un bloque de
            //              dos es 2 y ocupa dos franjas seguidas del mismo día.
            //              `years.minu_hora_clase` vale 50 y está en la base, así
            //              que sin declararlo alguien lo lee en minutos.
            // El tipo no es el que valida: un 300 aquí sería un error de SQL, y
            // lo que el cliente tiene que recibir es el 422 del controlador.
            $tabla->unsignedTinyInteger('dia');
            $tabla->unsignedTinyInteger('franja');
            $tabla->unsignedTinyInteger('duracion')->default(1);

            // **Informativos, y no ascienden a regla** (§5.2.4). Viajan y se
            // guardan porque sirven para imprimir y para que el veredicto pueda
            // **nombrar el dato que le faltó** en vez de decir «no comprobado» a
            // secas. Lo que hay que dejar escrito, porque el día que alguien vea
            // la columna va a pensar que ya se puede: **con esto NO se validan
            // choques de salón**. La capacidad la elige el cliente, y comprobar
            // una regla contra un número que manda el mismo que quiere pasar la
            // comprobación no es comprobar.
            $tabla->string('salon', 120)->nullable();
            $tabla->unsignedSmallInteger('salon_capacidad_grupos')->nullable();

            // Cada elemento de `asignaciones` se explota a una fila (§5.2), así
            // que el par (pieza, asignación) es único dentro de la versión. La
            // clave no es adorno: es lo que impide que un reintento a medias deje
            // la misma asignación dos veces en la misma pieza y descuadre el
            // Σ lecciones = IH **sin explicación posible**.
            //
            // **`version_id` va DELANTE y eso es la mitad de la regla.** Los
            // `pieza_id` derivan de `asignaturas.id`, que es estable entre versiones
            // del mismo colegio: la versión 1 y la versión 2 del mismo año contienen
            // **las dos** `a1196-0`, y son filas distintas y legítimas. Un único
            // sobre `pieza_id` a secas sólo rompería **la segunda subida del año**, y
            // pasaría entero cualquier test que suba una sola vez — que es
            // exactamente el test que se escribe.
            $tabla->unique(['version_id', 'pieza_id', 'asignatura_id'], 'horario_lecciones_pieza_asignacion');
            $tabla->index('asignatura_id', 'horario_lecciones_asignatura');

            $tabla->foreign('version_id')->references('id')->on('horario_versiones')->onDelete('cascade');
            $tabla->foreign('asignatura_id')->references('id')->on('asignaturas')->onDelete('cascade');
        });

        Schema::create('horario_pieza_docente', function (Blueprint $tabla) {
            $tabla->unsignedInteger('version_id');
            // Del mismo tipo que la de `horario_lecciones` y con el mismo pendiente:
            // las dos son la misma clave y se cambian juntas o no se cambian.
            $tabla->string('pieza_id', 64);

            // **`profesores.id`, NO `users.id`** (§5.2.1). Son dos columnas
            // distintas de la misma fila y la lectura que ya usa el panel
            // devuelve las dos, así que coger la que no es sale gratis. Aquí no
            // se notaría —los 47 profesores del colegio tienen `user_id`— pero la
            // columna es NULLable, y un docente sin cuenta desaparecería de la
            // revalidación **sin ningún error**: de las que se descubren en el
            // colegio catorce. La foránea es lo que ata que sea ésta y no la otra.
            $tabla->unsignedInteger('profesor_id');

            // **Los docentes cuelgan de la PIEZA, no de la asignación**, y es el
            // caso del capellán: si la misa la da él, el titular de Religión de
            // Décimo **tiene esa hora libre**, aunque la hora salga de su
            // asignación. Leer los docentes de `asignaturas.profesor_id` daría la
            // respuesta contraria, y con ella la comprobación de «docente sin
            // choque» de la §6 sería falsa en el único caso raro que tiene el
            // colegio (§5.1).
            $tabla->primary(['version_id', 'pieza_id', 'profesor_id'], 'horario_pieza_docente_pk');
            $tabla->index('profesor_id', 'horario_pieza_docente_profesor');

            $tabla->foreign('version_id')->references('id')->on('horario_versiones')->onDelete('cascade');
            $tabla->foreign('profesor_id')->references('id')->on('profesores')->onDelete('cascade');
        });

        // La oficial. Un solo `Schema::table` por tabla; ver la cabecera.
        Schema::table('years', function (Blueprint $tabla) {
            // **Esta columna va a volver por `PUT years/guardar-cambios`, y lo que
            // hoy nos protege es cómo está escrito ese método, no una decisión.**
            // El front viejo de AngularJS (`YearsCtrl.ts:333`) coge el objeto tal
            // como se lo devolvió `GET years/colegio`, le pisa tres `*_id` y lo manda
            // **entero**; desde que exista la columna, esa pantalla nos devolverá
            // `horario_version_id` en cada guardado.
            //
            // Hoy es inerte porque `YearsController::putGuardarCambios` asigna
            // **campo a campo** —veintitantos `Request::input('x', $year->x)`
            // explícitos, cero `Request::all()`, cero `fill()` en todo el
            // controlador— y ésta no está en la lista, así que se cae.
            //
            // **El día que alguien «simplifique» ese método a asignación masiva, la
            // pantalla de colegio se convierte en un camino sin permiso para escribir
            // la versión oficial del horario** —lo que la §5.4 reserva a superusuario
            // o `Coord académico` y a `PUT horario/versiones/{id}/oficial`—. Y no
            // escribiría un valor cualquiera: escribiría **el caducado**, el que la
            // página tenía al cargarse, así que **dos pestañas abiertas revertirían la
            // oficial sin que nadie tocara el horario**. Mientras el puntero valga
            // `null` no se nota; se estrena el día que haya una oficial de verdad, que
            // es justo el día en que a nadie se le ocurre mirar esa pantalla.
            //
            // Ese método **ya fue el sitio de esta clase de fallo** y lleva la lección
            // escrita encima: *«lo que el cuerpo no trae, no se toca»*.
            $tabla->unsignedInteger('horario_version_id')->nullable()->default(null)->after('regla_nivelacion');
            $tabla->foreign('horario_version_id')->references('id')->on('horario_versiones')->onDelete('set null');
        });
    }

    /*
     * Volver atrás es que ninguna de las tres tablas exista y `years` no tenga la
     * columna, que es lo que había antes de `up()`. Se pierden las versiones
     * subidas desde el despliegue y nada más: **las siete columnas de día de
     * `asignaturas` las escribe la derivación de la §7 y esta migración no las
     * toca**, así que «Clases de hoy» sigue enseñando lo último que se derivó.
     *
     * El orden es el inverso del de arriba porque mandan las foráneas: `years`
     * apunta a `horario_versiones`, y las otras dos también.
     */
    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropForeign(['horario_version_id']);
            $tabla->dropColumn('horario_version_id');
        });

        Schema::dropIfExists('horario_pieza_docente');
        Schema::dropIfExists('horario_lecciones');
        Schema::dropIfExists('horario_versiones');
    }
}
