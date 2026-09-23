<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Los grupos dejan de ser un censo y pasan a ser excepciones.**
 *
 * Segunda de las cinco. Crea `vt_grupos_votacion` y **tira
 * `vt_participantes`**, que es la tabla que sostenía el modelo viejo.
 *
 * ## QUÉ ERA `vt_participantes`, y por qué no se arregla
 *
 * Una fila por grupo inscrito en la elección. Su columna se llama
 * `grupo_profes_acudientes` y es **un id de `grupos` guardado en un
 * `varchar(255)`**: el nombre dice tres cosas y guarda una sola, y ni siquiera
 * con el tipo que le toca. Medido el 22 sep 2026 en `simonbolivar`: 57 filas,
 * valores `41`, `42`, `43`… — ids de grupo en texto.
 *
 * Lo que eso ha ido dejando está contado en
 * `docs/migracion/05-codigo-muerto-y-roto.md` y
 * `docs/migracion/11-votaciones.md`:
 *
 *   - `GET votaciones/unsignedsusers` responde 500 **desde antes de la
 *     migración**, porque hace `select p.user_id from vt_participantes p` y esa
 *     columna no existe (05 §18.4, y la tabla de la §5 del mismo).
 *   - `participantes/destroy/{id}` responde 500 siempre: es la única de las
 *     cinco tablas `vt_*` **sin `deleted_at`**, y el modelo lleva `SoftDeletes`
 *     dentro (05 §58.2).
 *   - `VtParticipante::one()`, `participanteDeAspiracion()` e `isSigned()`
 *     filtran por `vt_participantes.user_id`, que tampoco existe: las tres
 *     están rotas de raíz, no desafinadas.
 *
 * O sea que la tabla no tiene una forma que se pueda corregir con un `RENAME`:
 * **tiene dos formas a la vez** —la que el código de 2014 creía (una fila por
 * persona inscrita) y la que la tabla acabó teniendo (una fila por grupo)— y
 * media docena de consultas escritas contra cada una.
 *
 * ## LA SEMÁNTICA NUEVA, que es lo que hay que leer de aquí
 *
 * > **Sin filas en `vt_grupos_votacion`, participan TODOS los grupos del año de
 * > la votación.** Las filas son **excepciones**, no inscripciones.
 *
 * Una fila dice una de dos cosas, o las dos:
 *
 *   - `participa = 0` → **este grupo queda fuera** de esta elección.
 *   - `modo = 'mesa'` → este grupo vota **en mesa**, no por su cuenta. Con
 *     `vt_votaciones.solo_en_mesa = 1`, además, no puede votar de otra forma.
 *
 * Es el giro entero del módulo, y el motivo es el que el colegio vive cada año:
 * con el censo, una elección general **no arrancaba hasta que alguien inscribía
 * los veinte grupos uno a uno**, y el grupo que se olvidaba no votaba sin que
 * nadie se enterara — el sistema no distingue «no inscrito» de «inscrito y sin
 * votar». Al revés, el olvido tiene el signo correcto: **quien no se toca,
 * vota.**
 *
 * La consecuencia de diseño que hay que aceptar a cambio: un grupo creado
 * después de configurar la elección **entra solo**. Eso es lo que se quiere —un
 * curso que se abre en marzo vota— y es justo lo contrario de lo que hacía la
 * tabla vieja.
 *
 * ## POR QUÉ `grupo_id` ES `unsignedInteger` Y CON CLAVE AJENA
 *
 * Porque lo era desde el principio y nadie lo escribió. `grupos.id` es
 * `int unsigned`, y guardarlo en un `varchar(255)` es lo que permitía que la
 * fila apuntara a un grupo borrado, a un grupo de otro año o a `'N/A'`. Con la
 * clave ajena en `CASCADE`, borrar un grupo se lleva su excepción, que es lo
 * correcto: la excepción no significa nada sin el grupo.
 *
 * ## EL ÚNICO EN (votacion_id, grupo_id)
 *
 * Es la regla, no una optimización. Dos filas del mismo grupo en la misma
 * elección son dos respuestas a «¿participa este grupo?», y el código tendría
 * que elegir una — que es exactamente el fallo de `matriculas` sin clave única
 * descrito en `Matricula::FILTRO_DEL_ANIO`. Aquí se cierra antes de que exista.
 *
 * ## `modo` ES `varchar(4)`, Y ESO ES A PROPÓSITO
 *
 * `'solo'` | `'mesa'`. Cuatro caracteres porque las dos palabras miden cuatro y
 * el tope de la columna es la primera puerta contra un valor inventado — la
 * misma forma que `matriculas.estado` y `EstadosDeMatricula::LONGITUD`. No se
 * usa `enum` porque este proyecto no tiene ni uno y producción es MariaDB 10.5:
 * un `enum` obliga a un `ALTER` en dieciséis colegios para añadir un tercer
 * modo.
 *
 * ## VOLVER ATRÁS: aquí SÍ se pierde algo, y está autorizado
 *
 * `down()` recrea `vt_participantes` **vacía**, con la forma que tiene hoy
 * (incluido el `varchar` y la falta de `deleted_at`: se recrea lo que había, no
 * lo que debió ser), y tira `vt_grupos_votacion`.
 *
 * Lo que no vuelve son **las 57 filas del censo viejo**. Se pierden a sabiendas,
 * con permiso explícito de Joseth del 22 sep 2026: *«Las votaciones se pueden
 * ignorar, y si las llegan a necesitar las sacamos de un backup; ellos nunca
 * miran las votaciones de años pasados.»* No se toca ninguna otra tabla del
 * sistema.
 */
class LosGruposDejanDeSerUnCenso extends Migration
{
    public function up()
    {
        /*
         * Primero la tabla nueva y después el `DROP`, para que un `migrate` que
         * se pare a la mitad deje el colegio con las dos y no con ninguna. Es el
         * orden que sobrevive a la caída que documenta `App\Support\Ancla`.
         */
        if (Schema::hasTable('vt_grupos_votacion')) {
            echo "  vt_grupos_votacion: ya existe, no se toca.\n";
        } else {
            Schema::create('vt_grupos_votacion', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('votacion_id');
                $tabla->unsignedInteger('grupo_id');

                /*
                 * `1` es redundante con no tener fila, y eso está bien: la
                 * pantalla escribe la fila al tocar cualquiera de los dos
                 * interruptores y no tiene que borrarla al devolver uno a su
                 * sitio.
                 */
                $tabla->boolean('participa')->default(true);

                // 'solo' (vota cada uno desde donde esté) | 'mesa'.
                $tabla->string('modo', 4)->default('solo');

                $tabla->timestamps();

                // Una respuesta por grupo y elección. Ver la cabecera.
                $tabla->unique(['votacion_id', 'grupo_id'], 'vt_grupos_votacion_unico');

                $tabla->foreign('votacion_id')->references('id')->on('vt_votaciones')->onDelete('cascade');
                $tabla->foreign('grupo_id')->references('id')->on('grupos')->onDelete('cascade');
            });
        }

        /*
         * Y ahora la tabla vieja. `dropIfExists` porque varias sesiones corren
         * sobre el mismo docker y puede que ya no esté.
         *
         * Se lleva por delante su clave ajena a `vt_votaciones`; no hay ninguna
         * apuntando a ella —lo comprobado en el docker: `vt_votos` cuelga de
         * `vt_candidatos` y de `users`, nunca de `vt_participantes`, aunque tres
         * consultas del código digan `vv.participante_id`, que es una columna
         * que **no existe** (05 §18)—.
         */
        Schema::dropIfExists('vt_participantes');
    }

    public function down()
    {
        /*
         * Se recrea VACÍA y con la forma exacta que tenía, tal como está en
         * `database/schema/mysql-schema.sql`: `grupo_profes_acudientes` en
         * `varchar(255)`, `locked` sin defecto y **sin `deleted_at`**.
         *
         * Recrearla «bien» sería peor: el código viejo que esta migración
         * devuelve al sitio es el que espera esta forma, fallos incluidos —y su
         * `participantes/destroy` tiene que seguir respondiendo 500, que es lo
         * que fija `VotacionesBorradoTest`.
         */
        if (! Schema::hasTable('vt_participantes')) {
            Schema::create('vt_participantes', function (Blueprint $tabla) {
                $tabla->increments('id');
                $tabla->string('grupo_profes_acudientes', 255)->nullable();
                $tabla->unsignedInteger('votacion_id');
                $tabla->boolean('locked');
                $tabla->integer('intentos')->default(0);
                $tabla->timestamps();

                $tabla->foreign('votacion_id')->references('id')->on('vt_votaciones')->onDelete('cascade');
            });
        }

        Schema::dropIfExists('vt_grupos_votacion');
    }
}
