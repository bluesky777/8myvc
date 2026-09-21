<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El calendario del colegio deja de ser una lista de filas para todo el mundo.
 *
 * Es el paso 1 de la épica del calendario, y todo lo de aquí es **aditivo**:
 * con la migración puesta y sin tocar una sola fila, `calendario/this-year`
 * devuelve exactamente lo mismo que hoy. Ése es el criterio de aceptación y no
 * un adorno — esa ruta la leen la aplicación vieja de los quince colegios y
 * `myvc_flutter`, que es **una sola app para todos**.
 *
 * ## Lo que NO hay aquí, y es la mitad del diseño
 *
 * No hay ni un `DROP`. `cumple_alumno_id`, `cumple_profe_id`, `solo_profes`,
 * `type` y `url` se quedan **todas**. Los cumpleaños pasan a calcularse al
 * pedir el mes en vez de guardarse como filas, pero las 507 filas de
 * cumpleaños que hay en la base **son la red mientras la pantalla nueva no
 * funcione**: borrarlas es un paso posterior y va después de que se pueda
 * comprobar que nadie las echa de menos.
 *
 * ## `solo_profes` no es un residuo: es un espejo, y se sigue escribiendo
 *
 * `calendario_destinatarios` sustituye a `solo_profes` como fuente de la
 * verdad, pero **la columna sigue viva y se escribe en cada guardado** — 1
 * cuando las únicas filas de destinatarios son de `personal`, 0 en cualquier
 * otro caso. El motivo es de despliegue, no de diseño: `calendario/this-year`
 * la sigue leyendo desde la aplicación vieja y desde `myvc_flutter`, que están
 * desplegadas en los quince colegios y **no saben de esta tabla**. Si se deja
 * de escribir, un evento «sólo personal» se ve **público** allí, y no da
 * ningún error — la forma de fallo cara de este repo, la que no se delata.
 *
 * Está dicho también en `CalendarioController::guardarDestinatarios()`, que es
 * donde alguien tendría la tentación de «limpiarlo».
 */
class CalendarioConDestinatarios extends Migration
{
    public function up()
    {
        Schema::table('calendario', function (Blueprint $tabla) {
            /*
             * El cuerpo del evento, del editor de texto enriquecido.
             *
             * `TEXT` y no `varchar(255)` como el `title`: aquí entra HTML con
             * párrafos, listas y enlaces. Lo que se guarde pasa **siempre** por
             * `App\Support\HtmlDelEditor::limpiar`, la misma lista blanca que
             * los seis campos del PIAR, porque el front lo pinta con el mismo
             * pipe de HTML rico. La columna no puede hacer cumplir eso; lo hace
             * el controlador, y lo fija un test.
             */
            $tabla->text('descripcion')->nullable()->after(Ancla::de($tabla, 'title'));

            /*
             * Cuántos minutos antes avisar. NULL = no avisar, que es lo que
             * tienen los eventos que ya existen y por eso la columna nace
             * nullable en vez de con un default.
             *
             * La columna nace aquí y el cron que la lee es un paso muy
             * posterior. Es deliberado: son quince colegios y un despliegue por
             * colegio, así que la columna tiene que llevar tiempo puesta antes
             * de que nada dependa de ella.
             */
            $tabla->integer('recordatorio_minutos')->nullable()->after(Ancla::de($tabla, 'descripcion'));

            /*
             * Cuándo se mandó el aviso. NULL = todavía no.
             *
             * **`DATETIME` y no `TIMESTAMP`**, aunque las tres columnas de
             * fecha que ya tiene esta tabla sean `TIMESTAMP`. Un `TIMESTAMP`
             * convierte al escribir y al leer con la zona de la sesión de
             * MySQL, y `config/database.php` no la fija: es la del hosting, y
             * son quince cuentas de cPanel distintas. El argumento entero está
             * en la migración de `auditoria` y en la §1.2, y ya se pagó una vez.
             *
             * Aquí muerde poco —esta columna se lee sobre todo como «¿es
             * NULL?»— y por eso conviene decidirlo ahora y no cuando muerda: es
             * columna nueva, no hay ni una fila que convertir, y el día que el
             * cron la use para «no repetir el aviso de las 7:00» la zona
             * importa.
             */
            $tabla->dateTime('recordatorio_enviado_at')->nullable()->after(Ancla::de($tabla, 'recordatorio_minutos'));

            /*
             * El índice que no había.
             *
             * `calendario` tenía `PRIMARY KEY(id)` y la foránea de `created_by`,
             * nada más, y **la única pregunta que se le hace de verdad es por
             * rango de fechas**. Hasta hoy no importaba porque `this-year` se
             * lleva la tabla entera de un golpe; `calendario/mes` pide seis
             * semanas, y sin este índice pedir un mes cuesta lo mismo que
             * pedirlos todos.
             *
             * `deleted_at` va detrás y no delante: **todas** las consultas
             * filtran `deleted_at IS NULL`, así que es la columna menos
             * selectiva de las dos y con ella al frente el rango no podría usar
             * el índice como rango. El cron de recordatorios pregunta por otra
             * cosa —`recordatorio_minutos IS NOT NULL AND
             * recordatorio_enviado_at IS NULL`— y **no** lleva índice propio a
             * propósito: sin lector todavía, sería un índice pagado por una
             * consulta que no existe.
             */
            $tabla->index(['start', 'deleted_at'], 'calendario_start_index');
        });

        /*
         * Para quién es cada evento.
         *
         * Tres estados y hay que leer los tres, porque el de en medio es el que
         * no se ve:
         *
         *   - **Ninguna fila** = el evento es público. Es el comportamiento de
         *     hoy y **sigue siendo el defecto**: los eventos que ya existen no
         *     tienen filas aquí y no cambian de significado. (Con la excepción
         *     de `solo_profes = 1`, que sin filas sigue significando «sólo
         *     personal» — ver el controlador.)
         *   - Una fila con `grupo_id` NULL = **todos** los de ese público.
         *   - Una fila con `grupo_id` = sólo ese grupo de ese público.
         *
         * ## Por qué NO hay clave única, que es lo que sorprende al leerlo
         *
         * La forma obvia sería `unique(calendario_id, publico, grupo_id)`, y
         * **no protegería de nada**: en MySQL un índice único trata cada NULL
         * como distinto de los demás, así que dos filas
         * `(1460, 'alumnos', NULL)` —que son literalmente la misma frase, «todos
         * los alumnos»— caben las dos. Y son justo el caso que más se va a
         * repetir, porque es el que se marca con una casilla.
         *
         * Una restricción que sólo protege de los duplicados que no van a
         * ocurrir es peor que no tenerla: **se cuenta como cumplida**. Así que
         * la deduplicación es del escritor —borra todas las filas del evento e
         * inserta el conjunto nuevo, dentro de una transacción— y aquí queda
         * dicho para que nadie «arregle» la ausencia del índice.
         *
         * ## Los dos índices son dos preguntas, no una por si acaso
         *
         *   - `(calendario_id, publico, grupo_id)`: «¿a quién va este evento?»,
         *     que es lo que se pinta en el detalle del día.
         *   - `(publico, grupo_id)`: «¿este evento me toca a mí?», que es el
         *     `EXISTS` con el que `calendario/mes` filtra por el token. Es el
         *     que decide el coste del endpoint nuevo, porque corre una vez por
         *     evento del rango.
         */
        Schema::create('calendario_destinatarios', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('calendario_id');

            /*
             * `personal` | `alumnos` | `acudientes`.
             *
             * Vocabulario cerrado **en el controlador** y no con un `CHECK`:
             * MySQL 8.0.16 en adelante lo cumple y 5.7 lo acepta y lo **ignora
             * en silencio**, y los quince colegios están en cuentas de cPanel
             * distintas cuya versión no conocemos. Una restricción que se
             * cumple en unos colegios y no en otros se cuenta como cumplida en
             * los quince. Mismo argumento, misma decisión y mismo sitio que
             * `auditoria.accion`.
             */
            $tabla->string('publico', 16);

            /** NULL = todos los de ese público. Ver arriba por qué eso impide el índice único. */
            $tabla->unsignedInteger('grupo_id')->nullable();

            $tabla->timestamps();

            $tabla->index(['calendario_id', 'publico', 'grupo_id'], 'cal_dest_evento');
            $tabla->index(['publico', 'grupo_id'], 'cal_dest_publico');

            /*
             * A `calendario` sí, con `CASCADE`: una fila de destinatarios sin su
             * evento no significa nada.
             *
             * **A `grupos` no**, y es deliberado. Los grupos se borran con
             * `deleted_at` en todo este repo; un borrado de verdad sería una
             * anomalía, y con `CASCADE` esa anomalía **convertiría en silencio
             * «para el grupo 101» en «para todo el colegio»** —la fila se va, el
             * evento se queda sin destinatarios, y sin destinatarios significa
             * público—. Sin la foránea, la fila sobrevive apuntando a un grupo
             * que no está y el evento no le toca a nadie, que es el lado seguro
             * del error. Es además lo que ya hace la tabla: `calendario` tiene
             * una sola foránea, la de `created_by`.
             */
            $tabla->foreign('calendario_id')->references('id')->on('calendario')->onDelete('cascade');
        });
    }

    public function down()
    {
        /*
         * Volver atrás **pierde el reparto de destinatarios**, y hay que decirlo
         * aquí porque el efecto no es un hueco: es un cambio de significado.
         * Sin la tabla, un evento que iba a un solo grupo pasa a no tener filas,
         * y sin filas el evento es **público**. O sea que un `rollback` en un
         * colegio donde ya se hayan repartido eventos los **abre** en vez de
         * dejarlos como estaban.
         *
         * Los eventos «sólo personal» son la excepción y sobreviven al
         * `rollback`, porque su espejo vive en `solo_profes`, que esta migración
         * no toca. Ésa es la otra mitad de por qué la columna se sigue
         * escribiendo.
         */
        Schema::dropIfExists('calendario_destinatarios');

        Schema::table('calendario', function (Blueprint $tabla) {
            $tabla->dropIndex('calendario_start_index');
            $tabla->dropColumn(['descripcion', 'recordatorio_minutos', 'recordatorio_enviado_at']);
        });
    }
}
