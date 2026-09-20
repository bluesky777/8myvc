<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **El portal de la familia: el aspirante, sus documentos y sus citas.**
 *
 * Fase 2/3 del proceso de admisión, autorizada por Joseth el 20 sep 2026 con el
 * alcance delante. El contrato y los porqués están en
 * `docs/migracion/47-el-portal-de-la-familia.md`; las pantallas 02, 04, 05 y 06
 * en `myvc_front/PANTALLAS-MATRICULA.md`.
 *
 * ## LAS TRES TABLAS CUELGAN DE `ordenes_inscripcion`, QUE YA EXISTE
 *
 * Nada de esto empieza de cero. El papel —el código `2027-4K7M2X`, su cobro, su
 * lote y su estado— lo guarda `ordenes_inscripcion` desde el 19 sep
 * ([41](../../docs/migracion/41-el-formulario-de-inscripcion.md)). Lo que no
 * existía es **quién es la persona que ese papel representa**, y ése es el hueco
 * que el 46 midió al descartar `aspirante_id`:
 *
 *     «esa fila no tiene nombre: sus columnas son codigo, year_id, year_campana,
 *      lote_id, modo, alumno_id, grupo_id, grado_id, cierra, valor, vendida_por,
 *      vendida_at, estado, matricula_id, codigo_anterior — ni nombres, ni
 *      apellidos, ni documento.»
 *
 * `aspirantes` es exactamente ese nombre que faltaba. **Y por eso esta tanda es
 * la que desbloquea `requisitos_alumno.aspirante_id`**, que aquella dejó escrito
 * como «barato el día que exista quien llene el hueco». Hoy existe — pero **esa
 * columna sigue sin entrar**, y el motivo está en el 47 §4: es un `ALTER` que
 * reescribe `requisitos_alumno` para anular una clave ajena, y el recorrido de un
 * aspirante todavía no lo pide ninguna pantalla. *Lo que cambia es que ya no está
 * descartada: está esperando a su primera pantalla.*
 *
 * ## `aspirantes` ES TABLA APARTE Y NO UN ALUMNO MÁS, Y ESO NO ES ESTILO
 *
 * La razón dura la dejó escrita `INVESTIGACION-MATRICULAS.md` §7 y está medida en
 * este repo: **medio informe del colegio asume que todo alumno tiene matrícula**,
 * y la prematrícula pública ya dejó huérfanos por saltárselo —hasta el punto de
 * que existe un comando, `MatriculasHuerfanas`, para ir a buscarlos—.
 *
 * Crear el `alumno` al admitir, y no al inscribir, es lo que impide que un
 * aspirante que nunca vuelve aparezca en el listado de un grupo, en un boletín o
 * en una constancia.
 *
 * ## `documento` NO ES ÚNICO, Y ESO ESTÁ MEDIDO
 *
 * La tentación es un `UNIQUE (year_campana, documento)`: un aspirante, un
 * documento. **No se puede**, y el motivo no es teórico:
 *
 * 1. El documento **lo teclea la familia** desde una ruta pública. Un `UNIQUE`
 *    convierte un dedo torpe en un 500, y convierte el formulario del hermano en
 *    «ese documento ya existe» cuando lo que pasó es que se copió mal.
 * 2. **Un aspirante puede no tener documento todavía.** En preescolar el registro
 *    civil llega tarde, y el colegio inscribe igual.
 *
 * Lo que sí entra es un **índice normal** para que secretaría pueda buscar por
 * documento, y la comprobación de duplicados vive en la lectura —se enseña «ya
 * hay otro aspirante con este documento» y se deja decidir a la persona—, que es
 * donde un humano puede distinguir un hermano de un error.
 *
 * ## EL SEGUNDO FACTOR VIVE EN `aspirantes.documento`, Y ES LO QUE HACE PÚBLICA
 * ## A ESTA FAMILIA SIN ROMPER LA REGLA DE LOS MENORES
 *
 * `GET colillas-inscripcion/{codigo}` fijó la regla el 20 sep: **un código no
 * puede revelar el nombre de un menor**, porque se dicta por teléfono y viaja en
 * un papel que pasa de mano en mano. Esa ruta devuelve el trámite y no la persona.
 *
 * El portal **necesita** devolver la persona: la familia entra a seguir llenando
 * lo que dejó a medias. Así que el código solo no basta, y el segundo factor es
 * el dato que ya está en el formulario: **el documento del aspirante**.
 *
 * Y la parte que lo hace funcionar sin un huevo y una gallina: **hasta la primera
 * escritura no hay nada personal que revelar**. El código solo abre un formulario
 * en blanco; en cuanto lleva nombre dentro, pide el documento para volver a
 * abrirlo. La llave la elige la familia y nadie tiene que entregársela.
 *
 * ## LAS DOS COLUMNAS DE DUEÑO Y POR QUÉ NO HAY `CHECK`
 *
 * `documentos_admision` y `citas_admision` cuelgan **o** de un aspirante **o** de
 * un alumno: la familia que renueva ya es alumno y sube los mismos papeles. Lo
 * correcto sería un `CHECK` de que exactamente una está puesta.
 *
 * **No entra, y no por pereza: producción es MariaDB 10.5** (CLAUDE.md), y esta
 * casa ya se ha quemado una vez dando por hecho que el docker y producción
 * validan igual —`docker-no-es-estricto-produccion-si`—. Un `CHECK` que el docker
 * respeta y producción interpreta de otra forma es peor que no tenerlo, porque se
 * prueba verde. Lo hace cumplir el controlador, y lo fija un test.
 *
 * ## EN MariaDB 10.5 ESTO ENTRA AL INSTANTE
 *
 * Tres tablas que **nacen vacías**. No hay `ALTER` sobre ninguna tabla con datos,
 * así que no aplica la medida de `tools/ensayo-del-alter-en-maria.sh`: no hay nada
 * que bloquear.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('aspirantes')) {
            Schema::create('aspirantes', function (Blueprint $tabla) {
                $tabla->increments('id');

                // El papel. **Uno por orden**, y el `UNIQUE` es la regla entera: un
                // formulario comprado es un aspirante, y recargar la pantalla del
                // portal no puede crear el segundo.
                //
                // Es la misma decisión que `ordenes_inscripcion_alumno_campana`
                // tomó para la renovación, por el mismo motivo: lo que impide el
                // duplicado es el índice, no que la pantalla se acuerde.
                $tabla->unsignedInteger('orden_id');

                // Copiado de la orden **a propósito**, y no leído por `JOIN`. Las
                // listas de la bandeja filtran por campaña y ordenan por apellido:
                // con el año al otro lado de una unión, esa consulta no puede usar
                // un índice compuesto. Es un dato que no cambia nunca —el año de un
                // papel ya impreso—, así que copiarlo no puede desincronizarse.
                $tabla->unsignedSmallInteger('year_campana');

                $tabla->string('nombres', 120)->nullable();
                $tabla->string('apellidos', 120)->nullable();

                // **Los tres del SIMAT.** La pantalla 05 los captura porque son lo
                // que exige el reporte de enero, no porque los pida el colegio: sin
                // el lugar de expedición, el reporte se rehace a mano en enero con
                // la familia ya en su casa.
                $tabla->string('tipo_doc', 10)->nullable();
                $tabla->string('documento', 30)->nullable();
                $tabla->string('exp_lugar', 120)->nullable();

                $tabla->date('fecha_nac')->nullable();

                // El grado al que aspira. Anulable porque la orden **ya puede
                // traerlo** (`ordenes_inscripcion.grado_id`, que secretaría pone al
                // vender): aquí se guarda sólo si la familia lo cambia o si el papel
                // salió en blanco.
                $tabla->unsignedInteger('grado_id')->nullable();
                $tabla->string('colegio_anterior', 160)->nullable();

                // El acudiente, **plano y no una clave ajena a `acudientes`**: quien
                // llena esto no tiene fila en ninguna tabla del colegio todavía, y
                // crearle una antes de que lo admitan es exactamente el huérfano que
                // esta tanda existe para no repetir. Al admitir se convierte.
                $tabla->string('acu_nombres', 120)->nullable();
                $tabla->string('acu_apellidos', 120)->nullable();
                $tabla->string('acu_documento', 30)->nullable();
                $tabla->string('acu_celular', 40)->nullable();
                $tabla->string('acu_email', 120)->nullable();
                $tabla->string('acu_parentesco', 40)->nullable();

                // El tramo condicional de la pantalla 05: quien contesta que hay una
                // condición de salud ve tres campos más, y el resto no los ve nunca.
                $tabla->boolean('tiene_condicion_salud')->default(false);
                $tabla->text('condicion_salud')->nullable();

                // FORMULARIO | DOCUMENTOS | ENTREVISTA | ADMITIDO | NO_ADMITIDO | MATRICULADO
                //
                // El embudo del que **todavía no es alumno**. El de los que ya lo son
                // sigue viviendo en `matriculas.estado`, y el tablero suma las dos
                // (`INVESTIGACION-MATRICULAS.md` §7).
                $tabla->string('estado_embudo', 12)->default('FORMULARIO');

                // **AQUÍ NO HAY `alumno_id`, Y ESO SE DECIDIÓ MIRANDO QUIÉN LO ESCRIBIRÍA.**
                //
                // El reflejo es ponerlo: «cuando lo admiten, se convierte en alumno».
                // Pero el enlace del papel al alumno **ya existe y ya se escribe**:
                // `ordenes_inscripcion` tiene `alumno_id` y `matricula_id`, y lo ata
                // `PUT informes/formularios-inscripcion/codigo/{codigo}/alumno`,
                // entregada el 20 sep con su permiso, su 409 de «ese alumno ya tiene
                // formulario» y su test (41 §9).
                //
                // Como `aspirantes.orden_id` apunta a esa misma fila, una columna aquí
                // sería **el mismo dato en dos sitios**: o la escribe la ruta de al
                // lado —y entonces son dos escrituras que pueden discrepar— o no la
                // escribe nadie, y es `profesores.tono` por séptima vez en un mes.
                //
                // *La ficha lo lee por la orden. Un dato con dueño no se copia: se
                // sigue.*
                $tabla->unsignedInteger('decidido_por')->nullable();
                $tabla->timestamp('decidido_at')->nullable();
                $tabla->text('motivo_decision')->nullable();

                // **Anulables las dos, y eso es la mitad del diseño**: la familia
                // escribe aquí sin tener cuenta. `created_by` sólo se llena cuando
                // quien escribe es del colegio.
                $tabla->unsignedInteger('created_by')->nullable();
                $tabla->unsignedInteger('updated_by')->nullable();
                $tabla->timestamps();
                $tabla->softDeletes();

                $tabla->unique('orden_id', 'aspirantes_orden');

                // La bandeja: «los de esta campaña en este paso del embudo».
                $tabla->index(['year_campana', 'estado_embudo'], 'aspirantes_campana_embudo');

                // Buscar por documento desde secretaría, y enseñar el posible
                // duplicado. **Índice y no `UNIQUE`** — ver la cabecera.
                $tabla->index('documento', 'aspirantes_documento');

                $tabla->foreign('orden_id')->references('id')->on('ordenes_inscripcion')->onDelete('cascade');
            });
        } else {
            echo "  aspirantes: ya existe, no se toca.\n";
        }

        if (! Schema::hasTable('documentos_admision')) {
            Schema::create('documentos_admision', function (Blueprint $tabla) {
                $tabla->increments('id');

                // Qué documento es: **un paso del recorrido**, no un catálogo aparte.
                // Es la misma fila que chulea la estación 2, así que lo que la
                // familia sube por la pantalla 06 y lo que el colegio marca en el
                // patio son **las dos caras de un solo paso**. Un catálogo propio
                // haría que cerrar el paso y tener el papel fueran dos verdades
                // distintas.
                $tabla->unsignedInteger('requisito_id');

                // Uno de los dos, nunca los dos. Ver la cabecera: sin `CHECK`, y lo
                // hace cumplir el controlador.
                $tabla->unsignedInteger('aspirante_id')->nullable();
                $tabla->unsignedInteger('alumno_id')->nullable();

                // La ruta relativa dentro de `storage`, o la cadena `FISICO` cuando
                // llega en papel. **Una sola columna y no dos**, porque «cómo llegó»
                // y «dónde está» son la misma pregunta: un documento en papel no
                // tiene sitio en el disco y uno subido no tiene sitio en el archivador.
                $tabla->string('archivo', 255)->nullable();
                $tabla->string('nombre_original', 160)->nullable();

                // PAPEL    la familia avisa de que lo lleva en papel (pantalla 06)
                // SUBIDO   está el fichero, nadie lo ha mirado — «en revisión»
                // RECIBIDO el colegio lo da por bueno
                // DEVUELTO con motivo, y el motivo lo lee la familia
                //
                // **Subir no cierra el paso**, y por eso `SUBIDO` no es `RECIBIDO`:
                // decirle a la familia que ya está y luego devolvérselo en el patio
                // es el viaje que este módulo existe para ahorrar.
                $tabla->string('estado', 10)->default('SUBIDO');

                // **Columna propia, igual que en `requisitos_alumno`**, y por el
                // mismo invariante: esto lo lee la familia. La observación interna no
                // comparte sitio con ella, ni aquí ni allí.
                $tabla->text('motivo_devolucion')->nullable();

                // La única pregunta que hace el mostrador al recibir un papel:
                // ¿se devuelve el original? (pantalla 08).
                $tabla->boolean('devuelto_original')->default(false);

                $tabla->unsignedInteger('recibido_por')->nullable();
                $tabla->timestamp('recibido_at')->nullable();

                $tabla->timestamps();
                $tabla->softDeletes();

                // Las dos lecturas: «los documentos de esta persona» —la pantalla 06
                // y la ficha— y «lo que falta por revisar de este paso» —la bandeja—.
                $tabla->index(['aspirante_id', 'deleted_at'], 'documentos_admision_aspirante');
                $tabla->index(['alumno_id', 'deleted_at'], 'documentos_admision_alumno');
                $tabla->index(['requisito_id', 'estado'], 'documentos_admision_requisito');

                $tabla->foreign('requisito_id')->references('id')->on('requisitos_matricula')->onDelete('cascade');
                $tabla->foreign('aspirante_id')->references('id')->on('aspirantes')->onDelete('cascade');
                $tabla->foreign('alumno_id')->references('id')->on('alumnos')->onDelete('cascade');
            });
        } else {
            echo "  documentos_admision: ya existe, no se toca.\n";
        }

        if (! Schema::hasTable('citas_admision')) {
            Schema::create('citas_admision', function (Blueprint $tabla) {
                $tabla->increments('id');

                $tabla->unsignedInteger('aspirante_id')->nullable();
                $tabla->unsignedInteger('alumno_id')->nullable();

                // entrevista | prueba | taller
                $tabla->string('tipo', 12)->default('entrevista');

                // El paso del recorrido al que pertenece, si el colegio lo configuró
                // como estación. Anulable: se puede citar a una familia sin que la
                // entrevista sea un paso numerado del día.
                $tabla->unsignedInteger('requisito_id')->nullable();

                $tabla->dateTime('cuando')->nullable();
                $tabla->string('donde', 120)->nullable();
                $tabla->unsignedInteger('con_quien')->nullable();

                // PENDIENTE | APROBADO | CON_COMPROMISO | NO_APROBADO
                //
                // Los tres botones de la pantalla 10. `CON_COMPROMISO` es uno de
                // ellos y no una observación suelta, **porque tiene que sobrevivir a
                // febrero**: el compromiso se le enseña al docente cuando abre su
                // planilla, y un texto libre no se puede buscar.
                $tabla->string('resultado', 16)->default('PENDIENTE');
                $tabla->text('observacion')->nullable();

                // Lo de Orientación (pantalla 11). **Cuenta y no se enseña**, que es
                // exactamente la regla que ya fijó `notas_estacion.reservada`:
                // esconder que algo existe es peor que esconder su contenido.
                $tabla->boolean('reservada')->default(false);

                $tabla->unsignedInteger('creada_por')->nullable();
                $tabla->timestamps();
                $tabla->softDeletes();

                $tabla->index(['aspirante_id', 'deleted_at'], 'citas_admision_aspirante');
                $tabla->index(['alumno_id', 'deleted_at'], 'citas_admision_alumno');

                // La agenda del día de quien entrevista.
                $tabla->index(['cuando', 'resultado'], 'citas_admision_agenda');

                $tabla->foreign('aspirante_id')->references('id')->on('aspirantes')->onDelete('cascade');
                $tabla->foreign('alumno_id')->references('id')->on('alumnos')->onDelete('cascade');
            });
        } else {
            echo "  citas_admision: ya existe, no se toca.\n";
        }
    }

    public function down()
    {
        Schema::dropIfExists('citas_admision');
        Schema::dropIfExists('documentos_admision');
        Schema::dropIfExists('aspirantes');
    }
};
