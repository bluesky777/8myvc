<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Los interruptores que le faltaban a una elección del colegio.**
 *
 * Primera de las cinco migraciones que rehacen el módulo de votaciones. Lo que
 * el módulo hacía hasta hoy —y por qué casi nada de ello se puede dejar como
 * estaba— está en `docs/migracion/11-votaciones.md`. Esta trae la
 * configuración, que eran tres columnas (`votan_profes`, `votan_acudientes`,
 * `can_see_results`) y una tabla de censo que miente con el nombre
 * (`vt_participantes`, que se tira en la siguiente).
 *
 * ## TODAS NOT NULL Y CON DEFECTO, y eso no es un detalle
 *
 * Hay diecisiete filas de `vt_votaciones` vivas en `simonbolivar` y no las va a
 * rellenar nadie: son elecciones de años pasados que el colegio no vuelve a
 * mirar. Una columna anulable dejaría al código nuevo preguntando
 * `if ($votacion->votan_estudiantes)` sobre un `NULL`, que en PHP es `false`, y
 * entonces **una elección vieja reabierta no dejaría votar a nadie** sin dar
 * error. Con defecto, la fila vieja se comporta como se comportaba.
 *
 * Y el defecto de cada una está elegido para que **desplegar esto no cambie
 * nada**: los alumnos votaban (`votan_estudiantes = 1`), el resto del personal
 * no (`votan_administrativos = 0`), y no había mesas ni doble llave
 * (`solo_en_mesa = 0`, `doble_llave = 0`).
 *
 * ## POR QUÉ UN SOLO FLAG DE PERSONAL Y NO TRES
 *
 * El encargo pedía flags por estamento —administrativos, personal de apoyo
 * (cafetería, aseo, mantenimiento)—. **La base no sabe separarlos.** Medido el
 * 22 sep 2026 contra el docker, en `simonbolivar` y `caz_zaragoza`:
 *
 *   - `users.tipo` toma cuatro valores: `Alumno` (1.286), `Acudiente` (1.000),
 *     `Profesor` (53) y `Usuario` (22). No hay un quinto.
 *   - `roles` tiene doce nombres, y son los mismos doce en los dos colegios:
 *     Admin, Profesor, Alumno, Acudiente, Manager, Asistente, Enfermero, Coord
 *     disciplinario, Coord académico, Rector, Psicólogo, Secretario. **Ninguno
 *     es cafetería, aseo ni mantenimiento.**
 *   - `profesores.tipo_profesor` vale `Catedrático` o `Tiempo completo`
 *     (`ProfesoresController.php:175`), y está en `NULL` en 42 de 47 filas
 *     (`HorarioController.php:1772`): es la dedicación, no el estamento.
 *   - `contratos` es `(profesor_id, year_id)` y **ninguna columna más**: dice si
 *     está contratado este año, no de qué trabaja.
 *
 * O sea que **el personal de apoyo no existe en el sistema** — lo normal es que
 * ni tenga cuenta. Un flag `votan_personal_apoyo` sería una casilla que no
 * puede seleccionar a nadie: se enciende, no vota ningún conserje, y el colegio
 * cree que sí. Mejor un flag menos que uno que no se puede consultar.
 *
 * Queda uno solo, `votan_administrativos`, y significa exactamente lo que la
 * base puede contestar: **el personal del colegio con cuenta que no es
 * docente**, o sea `users.tipo = 'Usuario'` — las ~21 cuentas de secretaría,
 * coordinación y rectoría que `ExigirPersonal` deja pasar y que no tienen ficha
 * en `profesores`. *No* es `Autoriza::esAdministrativo()`, que es otra pregunta
 * («¿manda éste?», `is_superuser || rol Secretario`) y sólo alcanza a diez.
 *
 * Con los dos que ya estaban y el nuevo `votan_estudiantes`, son cuatro
 * estamentos y los cuatro son consultables.
 *
 * ## `titulares_conducen`, y por qué nace en 1
 *
 * El titular conduce la mesa de su propio grupo **sin configurar nada**. Es lo
 * que va a pasar en casi todos los colegios, y obligar a crear una mesa por
 * salón convertiría una elección de una mañana en una tarde de configuración.
 *
 * Ojo al leerlo: `grupos.titular_id` apunta a `profesores.id`, **no a
 * `users.id`**, así que quien resuelva «¿conduce éste?» tiene que pasar por
 * `profesores.user_id`.
 *
 * ## `clave_doble_llave` guarda un HASH
 *
 * `varchar(255)` porque eso es lo que ocupa un `bcrypt`, no porque la clave sea
 * larga. **Nunca se escribe en claro**: es la misma regla que `users.password`,
 * y por lo mismo —quien pueda mirar la tabla no tiene por qué poder abrir la
 * urna—. Anulable porque sin `doble_llave` no hay clave que guardar, y un `''`
 * sería una clave vacía válida, que es peor que ninguna.
 *
 * ## Volver atrás
 *
 * Aditiva pura: siete columnas nuevas a las que no apunta nada. `down()` las
 * tira y no se pierde ni un voto. Lo que se pierde es cómo configuró cada
 * colegio su elección, y con el código viejo puesto eso no lo lee nadie.
 */
class LaConfiguracionDeLaVotacion extends Migration
{
    public function up()
    {
        Schema::table('vt_votaciones', function (Blueprint $tabla) {
            /*
             * El estamento que faltaba. Los otros dos ya tenían columna; los
             * alumnos no, porque el código viejo daba por hecho que votaban
             * ellos y punto. Nace en 1: eso es lo que hace hoy.
             */
            if (! Schema::hasColumn('vt_votaciones', 'votan_estudiantes')) {
                $tabla->boolean('votan_estudiantes')
                    ->default(true)
                    ->after(Ancla::de($tabla, 'nombre'));
            }

            /*
             * Todo el personal con cuenta que no es docente. Ver la cabecera: no
             * son tres flags porque la base no distingue tres estamentos. Nace
             * en 0 —hoy no vota— y encenderlo es una decisión del rector.
             */
            if (! Schema::hasColumn('vt_votaciones', 'votan_administrativos')) {
                $tabla->boolean('votan_administrativos')
                    ->default(false)
                    ->after(Ancla::de($tabla, 'votan_acudientes'));
            }

            // El titular conduce la mesa de su grupo sin que nadie la cree.
            if (! Schema::hasColumn('vt_votaciones', 'titulares_conducen')) {
                $tabla->boolean('titulares_conducen')
                    ->default(true)
                    ->after(Ancla::de($tabla, 'votan_administrativos'));
            }

            /*
             * Segundos de cuenta atrás antes de enseñar el tarjetón: el rato en
             * que el de la mesa aparta la vista de la pantalla.
             * `unsignedSmallInteger` y no `tinyInteger` porque un colegio puede
             * querer quince o treinta, y no tiene sentido que sea negativo.
             */
            if (! Schema::hasColumn('vt_votaciones', 'cuenta_atras')) {
                $tabla->unsignedSmallInteger('cuenta_atras')
                    ->default(5)
                    ->after(Ancla::de($tabla, 'titulares_conducen'));
            }

            // La urna que hace falta abrir entre dos. Apagada de fábrica.
            if (! Schema::hasColumn('vt_votaciones', 'doble_llave')) {
                $tabla->boolean('doble_llave')
                    ->default(false)
                    ->after(Ancla::de($tabla, 'cuenta_atras'));
            }

            // Un HASH, nunca la clave. Ver la cabecera.
            if (! Schema::hasColumn('vt_votaciones', 'clave_doble_llave')) {
                $tabla->string('clave_doble_llave', 255)
                    ->nullable()
                    ->after(Ancla::de($tabla, 'doble_llave'));
            }

            /*
             * Con esto en 1, un grupo en modo mesa **sólo** vota en su mesa: el
             * alumno que abra la pantalla por su cuenta no puede emitir. Es lo
             * que convierte la mesa en una mesa de verdad y no en un atajo.
             *
             * Nace en 0 porque hoy no hay mesas. Con cero mesas creadas da igual
             * cómo esté; el día que se cree la primera, es lo que decide si el
             * grupo de esa mesa sigue pudiendo votar por su cuenta.
             */
            if (! Schema::hasColumn('vt_votaciones', 'solo_en_mesa')) {
                $tabla->boolean('solo_en_mesa')
                    ->default(false)
                    ->after(Ancla::de($tabla, 'clave_doble_llave'));
            }
        });
    }

    public function down()
    {
        Schema::table('vt_votaciones', function (Blueprint $tabla) {
            foreach ([
                'solo_en_mesa',
                'clave_doble_llave',
                'doble_llave',
                'cuenta_atras',
                'titulares_conducen',
                'votan_administrativos',
                'votan_estudiantes',
            ] as $columna) {
                if (Schema::hasColumn('vt_votaciones', $columna)) {
                    $tabla->dropColumn($columna);
                }
            }
        });
    }
}
