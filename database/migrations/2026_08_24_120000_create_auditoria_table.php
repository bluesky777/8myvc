<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `auditoria`: dónde se escribe el rastro, ahora que se sabe por qué el viejo no
 * servía.
 *
 * Es la fase 3 de docs/migracion/18-auditoria.md, y esta migración es sólo la
 * mitad de dónde: la otra mitad es App\Services\Auditoria, el único que escribe
 * aquí. La tabla sin el escritor volvería a tener diez criterios distintos, que
 * es de lo que se viene.
 *
 * ## Tabla nueva, y `bitacoras` NO se migra (§4.1)
 *
 * No hay `ALTER TABLE bitacoras` en ninguna parte de este fichero, a propósito.
 * `bitacoras` tiene historia que los colegios consultan, hay dieciséis bases
 * detrás, y sus columnas viejas **no se pueden reinterpretar sin adivinar**:
 * medido el 24 ago 2026, `created_at` tiene 12 filas escritas en UTC contra 74
 * en Bogotá y **nada en la fila dice cuál es cuál**. Una migración de datos
 * sobre eso sería mover 74 filas o 12 según una suposición.
 *
 * Así que `bitacoras` se congela —se deja de escribir en ella cuando el escritor
 * nuevo esté desplegado, y no antes— y sus pantallas siguen leyéndola. Esta
 * tabla nace al lado y vacía.
 *
 * ## Las cuatro cosas que el esquema viejo no podía hacer, y cómo se cierran
 *
 * 1. **`affected_element_type` es texto libre**, con tres convenciones de nombre
 *    en diez escrituras (`Nota`, `NF_UPDATE`, `Nueva subunidad`,
 *    `AlumnoPideAjeno:user_id`). Aquí `accion` y `entidad` son vocabulario
 *    cerrado — cerrado **en el servicio**, ver abajo.
 * 2. **El valor viejo y el nuevo estaban partidos en `_int` y `_string`**, y
 *    quien leía tenía que saber cuál mirar. Aquí son un `json` cada uno: una
 *    nota, una asistencia («presente» → «tarde») y una frase caben en el mismo
 *    sitio.
 * 3. **No había índices**: `PRIMARY KEY(id)` y la foránea, nada más. Aquí hay
 *    cinco y salen de las cinco preguntas que la pantalla hace, no de la
 *    intuición.
 * 4. **La auditoría se podía borrar** (`DELETE bitacoras/destroy/{id}` con
 *    `auth.personal`: cualquiera del personal borraba el registro que lo vigila,
 *    incluido el suyo). Aquí no hay `deleted_at` ni `updated_at` — §4.4.
 *
 * ## Por qué NO hay claves foráneas, que es lo que más sorprende al leerlo
 *
 * Ni a `users`, ni a `alumnos`, ni a `notas`, ni a `historiales`. `bitacoras` sí
 * tiene una a `historiales` **con `ON DELETE CASCADE`**, y eso convierte borrar
 * el ingreso en borrar su auditoría: exactamente lo contrario de lo que una
 * tabla de auditoría existe para hacer.
 *
 * Por lo mismo se copian los nombres dentro de la fila (`actor_nombre`,
 * `alumno_nombre`): la línea se tiene que poder leer dentro de tres años aunque
 * la nota, la subunidad, la asignatura y hasta el alumno se hayan borrado. Una
 * auditoría cuyo significado depende de datos que sí cambian no es una
 * auditoría, y hoy la vieja necesita salir a seis tablas para construir una
 * frase.
 *
 * ## Y por qué NO hay CHECK sobre `accion` ni sobre `entidad`
 *
 * MySQL 8.0.16 en adelante **sí** cumple un `CHECK`; 5.7 lo acepta y lo
 * **ignora en silencio**. Los dieciséis colegios están en cuentas de cPanel
 * distintas y este repo ya se ha comido una vez que la garantía dependa del
 * hosting: es la §1.2 entera —`@@session.time_zone = SYSTEM`, la misma fila leída
 * con una hora distinta en dos colegios—. Una restricción que se cumple en unos
 * colegios y no en otros es peor que no tenerla, porque se cuenta como cumplida.
 *
 * El vocabulario se cierra donde sí se cumple igual en los dieciséis: en
 * App\Services\Auditoria, que es el único que escribe, con constantes y una
 * excepción si el valor no está en la lista. Lo fija AuditoriaEscritorUnicoTest.
 */
class CreateAuditoriaTable extends Migration
{
    public function up()
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * Quién, y desde dónde.
             *
             * `sesion_id` es **real, no adivinado**, y ésa es la diferencia
             * entera con `bitacoras.historial_id`. Hoy los nueve sitios que
             * escriben ese campo lo resuelven con
             *
             *     select * from historiales where user_id=? order by id desc limit 1
             *
             * o sea **el último login de esa persona, no la sesión que hizo el
             * cambio**. Y no es el caso raro de dos aparatos: el token de
             * refresco vive 14 días y rota en cada uso, así que quien entre a
             * diario puede llevar **meses** sin teclear la contraseña —
             * `historiales` no crece y todas sus escrituras de esos meses cuelgan
             * del ingreso de hace meses. La pantalla «qué hizo en este ingreso»
             * mostraría una lista falsa sin ningún error visible.
             *
             * Los dos son nullable porque atarlos de verdad es la fase 2 y va en
             * su propio despliegue: hasta entonces el servicio escribe NULL y lo
             * dice en `atribucion`. **NULL honesto, no adivinanza.**
             */
            $table->unsignedBigInteger('sesion_id')->nullable();
            $table->unsignedInteger('historial_id')->nullable();

            /*
             * `actor_user_id` NULLABLE, y no es un descuido: **un `intento_login`
             * fallido no tiene actor autenticado.**
             *
             * Lo destapó el front el 24 ago al recordar que `mis-sesiones` pinta
             * esos intentos — son 52 de las 85 filas del seed. Hoy `Login.php`
             * les pone `created_by = 0`, que es un id que no existe disfrazado de
             * id que sí, y con la columna NOT NULL o no cabrían o volverían a
             * entrar con el 0 mentiroso dentro.
             *
             * El username **tecleado** en ese intento va en `actor_intentado`, que
             * es lo único que se sabe de quien lo hizo. No se pone en
             * `actor_nombre` a propósito: ese campo dice quién fue, y aquí no se
             * sabe.
             */
            $table->unsignedInteger('actor_user_id')->nullable();
            $table->unsignedInteger('actor_persona_id')->nullable();

            /** Profesor|Alumno|Acudiente|Usuario|sistema — las cuatro ramas del contexto, más una. */
            $table->string('actor_tipo', 20)->nullable();

            /** Congelado en la fila: la persona se puede borrar y la línea tiene que seguir leyéndose. */
            $table->string('actor_nombre', 120)->nullable();

            /** El username que se tecleó en un login fallido. Sin actor detrás. */
            $table->string('actor_intentado', 120)->nullable();

            /*
             * Qué. Los dos de vocabulario cerrado (constantes en el servicio).
             *
             * `crear|editar|borrar|restaurar` son escrituras. **`denegado` no lo
             * es**: es un intento rechazado, y lo graban los dos middlewares
             * —`ExigirPersonaPropia` y `ExigirBoletinPropio`— cuando le dicen que
             * no a alguien. No lleva `entidad_id` ni valores, porque no se
             * escribió nada.
             *
             * Es el quinto valor y costó encontrarlo: el barrido de los nueve
             * sitios que adivinan el ingreso los contó como nueve iguales, y son
             * **siete controladores y dos middlewares**. El arreglo es el mismo
             * para los nueve; la **fila** no. Sin este quinto valor, dos
             * denegaciones entrarían disfrazadas de ediciones que nunca
             * ocurrieron, justo en la pantalla que se pidió para saber qué pasó.
             */
            $table->string('accion', 20);
            $table->string('entidad', 40);
            $table->unsignedBigInteger('entidad_id')->nullable();

            /*
             * Sobre quién y en qué contexto. **Denormalizado a propósito**, ver la
             * cabecera: sin esto, una nota borrada deja su línea de auditoría
             * ilegible, que es justo el caso que más se reclama.
             */
            $table->unsignedInteger('alumno_id')->nullable();
            $table->string('alumno_nombre', 120)->nullable();
            $table->unsignedInteger('grupo_id')->nullable();
            $table->unsignedInteger('asignatura_id')->nullable();
            $table->unsignedInteger('periodo_id')->nullable();
            $table->unsignedInteger('year_id')->nullable();

            /*
             * El cambio.
             *
             * `json` y no dos pares `_int`/`_string`. Hoy quien lee `bitacoras`
             * tiene que saber cuál de las cuatro columnas mirar **según el tipo**,
             * y una asistencia o una frase no caben en ninguna de las dos formas
             * sin convenio.
             *
             * Un reguardado sin cambio deja los dos **iguales**, y eso es a
             * propósito: se reconoce solo, sin columna nueva. Ver el servicio.
             */
            $table->json('valor_anterior')->nullable();
            $table->json('valor_nuevo')->nullable();

            /** La frase ya construida, para que la pantalla no tenga que saber de dominios. */
            $table->string('resumen', 255)->nullable();

            /** Desde dónde llegó, para poder reconstruir un incidente. */
            $table->string('ip', 45)->nullable();
            $table->string('ruta', 120)->nullable();   // 'PUT notas/update/{id}'

            /*
             * Cómo se supo de qué sesión salió esto.
             *
             *   'sesion'     — el token lo dijo. Cierto.
             *   'aproximada' — NO cierto. O se adivinó con el último login (la
             *                  bitácora vieja), o no se sabe (este servicio, antes
             *                  de que la fase 2 esté desplegada: escribe NULL en
             *                  vez de adivinar).
             *
             * **Es una columna y no un cálculo porque la pantalla no lo puede
             * deducir**: el navegador no sabe qué día se desplegó la fase 2 en su
             * colegio. Viaja en el cuerpo de la respuesta (§4.6, regla 4). Sin
             * ella el aviso es impintable, y una atribución falsa sin aviso es
             * peor que no tenerla.
             *
             * ## El DEFAULT va al revés de como lo escribía el plan, y es a propósito
             *
             * El §4.2 lo dibujaba `DEFAULT 'sesion'`. Aquí es `'aproximada'`.
             * El valor por defecto es lo que recibe **la fila de quien se olvidó
             * de ponerlo**, y quien se olvidó es exactamente aquel cuya atribución
             * no hay que creerse. `DEFAULT 'sesion'` es un instrumento que falla
             * hacia el lado que tranquiliza —la familia de fallo que este repo
             * lleva catalogada en CLAUDE.md—, y aquí el lado que tranquiliza
             * significa afirmar «esto lo hizo esa sesión» sin que nadie lo haya
             * comprobado.
             *
             * No cambia nada para el escritor: App\Services\Auditoria **siempre**
             * escribe la columna explícitamente. Sólo cambia qué pasa cuando
             * alguien la escriba desde fuera, que es el único caso en que un
             * DEFAULT decide algo.
             */
            $table->string('atribucion', 12)->default('aproximada');

            /*
             * `DATETIME(3)`, y las dos mitades importan.
             *
             * **`DATETIME` y no `TIMESTAMP`** porque un `TIMESTAMP` convierte al
             * escribir y al leer con la zona de la sesión de MySQL, y
             * `config/database.php` no la fija: `@@session.time_zone = SYSTEM`,
             * o sea la del hosting, y son dieciséis cuentas de cPanel. Con
             * `TIMESTAMP` la misma fila se lee con una hora distinta en dos
             * colegios, y si el hosting cambia su zona **todas las filas
             * históricas se desplazan a la vez** sin que nadie toque la base. Un
             * `DATETIME` no convierte: lo que escribe App\Support\Reloj es lo que
             * se lee, en phpMyAdmin y en la pantalla.
             *
             * **Los milisegundos tampoco son adorno**: dos notas tecleadas en el
             * mismo segundo son dos líneas distintas del historial, y con
             * precisión de segundo no se sabe cuál fue primero. Por eso
             * `Reloj::ahoraTexto()` formatea con `.v`.
             *
             * **Una sola columna de tiempo.** No hay `updated_at` porque una
             * línea no se edita, ni `deleted_at` porque no se borra (§4.4).
             */
            $table->dateTime('ocurrido_en', 3);

            /*
             * Los cinco índices son las cinco preguntas de la pantalla, una a una.
             * No son intuición y no son «por si acaso»: si alguna pregunta deja de
             * hacerse, sobra su índice.
             *
             * Todos llevan un segundo campo para que el orden salga del índice y
             * no de un `filesort`: `id` donde el orden es de inserción, y
             * `ocurrido_en` donde la pantalla filtra además por rango de fechas.
             */
            $table->index(['sesion_id', 'id'], 'aud_sesion');                  // «qué hizo en este ingreso»
            $table->index(['actor_user_id', 'ocurrido_en'], 'aud_actor');      // «qué ha hecho este profe»
            $table->index(['alumno_id', 'ocurrido_en'], 'aud_alumno');         // «qué le han hecho a este alumno»
            $table->index(['entidad', 'entidad_id', 'id'], 'aud_entidad');     // «quién cambió esta nota»
            $table->index('ocurrido_en', 'aud_fecha');                         // barrido por rango (retención, fase 6)
        });
    }

    /*
     * El `down` borra la tabla, y eso sólo es inocuo mientras esté vacía.
     *
     * Se deja escrito porque en cuanto la fase 4 empiece a instrumentar dominios
     * esto pasa a ser **borrar la auditoría entera**, que es lo que la §4.4
     * prohíbe por la puerta de delante. Un `rollback` en un colegio con rastro
     * dentro no es reversible: la tabla no tiene de dónde reconstruirse, porque
     * justamente no depende de ninguna otra.
     */
    public function down()
    {
        Schema::dropIfExists('auditoria');
    }
}
