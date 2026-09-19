<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Las dos tablas de la pasarela: **con qué cobra este colegio** y **qué se cobró**.
 *
 * Son la mitad que faltaba de `docs/migracion/41-el-formulario-de-inscripcion.md`
 * §7 —el checkout y el webhook— y siguen las conclusiones del
 * `40-pagos-en-linea.md` §4 y §5 **con una corrección medida**, que es lo que
 * explica la forma de esta tabla y va antes que nada.
 *
 * ## LA CORRECCIÓN: EL DOC 40 §4 SE EQUIVOCA EN LA LLAVE, Y ESO CAMBIA EL PRECIO
 *
 * El doc 40 §4 dice que al recibir un webhook hay que volver a preguntarle a la
 * pasarela el estado de la transacción *«autenticado **con la llave pública**»*, y
 * concluye que con eso el secreto de eventos filtrado no sirve para nada. La regla
 * es buena; **la llave está mal**, comprobado el 19 sep 2026 contra la
 * documentación de Wompi:
 *
 *   - `GET /v1/transactions/{id}` — *«only available via **Private Key (`prv_*`)**
 *     from your server/backend»* (`docs.wompi.co/en/docs/colombia/seguimiento-de-transacciones/`).
 *   - Y lo que Wompi **sí** recomienda para validar un evento es exactamente lo que
 *     el doc 40 descartaba: **la firma del evento**, `SHA256(valores + timestamp +
 *     secreto_de_eventos)` (`…/docs/colombia/eventos/`).
 *
 * O sea que la reconsulta **no es gratis**: exige guardar la llave privada del
 * colegio. Y eso rompe el otro argumento del doc 40 §4 —*«la llave que va al
 * navegador es pública por diseño… robarla no mueve un peso»*—, que es cierto de
 * la pública y falso de la privada. **El documento tomó una decisión creyendo que
 * no costaba nada, y cuesta.**
 *
 * ## DOS CERRADURAS, Y SÓLO UNA ES OBLIGATORIA
 *
 * Las consecuencias de perder cada secreto no son del mismo tamaño, así que no se
 * les puede exigir lo mismo:
 *
 *   `secreto_eventos`  filtrado, se puede **forjar un «pagado»** → un formulario
 *                      de inscripción gratis. Caro, acotado y auditable.
 *   `llave_privada`    filtrada, se toca **la cuenta de la pasarela del colegio**.
 *                      Otro orden de magnitud, y no es dinero nuestro.
 *
 * Por eso:
 *
 *   - **La firma del evento es obligatoria.** Sin `secreto_eventos` configurado, el
 *     webhook no admite nada. Es la recomendación de Wompi y el secreto barato.
 *   - **La reconsulta es opcional.** Con `llave_privada` puesta se pregunta y manda
 *     lo que conteste la pasarela, que es la regla del doc 40 §4 tal y como se
 *     quería. Sin ella, decide la firma.
 *
 * **Y el modo débil no puede ser invisible**, que es como una seguridad opcional
 * acaba apagada en los diecisiete sin que nadie lo sepa: cada pago guarda en
 * `verificado_por` **cómo** se admitió, `reconsulta` o `firma`. Así la pregunta
 * *«¿esto se comprobó de verdad?»* se contesta mirando la fila y no suponiendo.
 *
 * ## POR QUÉ LAS CREDENCIALES VIVEN EN LA BASE Y NO EN EL `.env`
 *
 *   1. **Para cambiarlas hay que no desplegar.** Un colegio que rota su llave o que
 *      pasa de pruebas a producción no puede depender de que alguien edite
 *      diecisiete ficheros por SSH a las once de la noche.
 *   2. **`app/` y `.env` son copia real por colegio, pero `vendor/` es un symlink
 *      compartido.** Lo que se pueda resolver sin tocar el sistema de ficheros del
 *      servidor se resuelve sin tocarlo.
 *
 * ## `activa` SÓLO PUEDE APAGAR
 *
 * El doc 40 §5 lo deja dicho: **el criterio de encendido no es el interruptor, son
 * las credenciales.** Un colegio sin llave pública y sin secreto de integridad está
 * apagado aunque `activa` valga 1, y por eso el servicio comprueba las dos cosas y
 * no ésta sola. Lo que `activa` añade es poder apagar **sin borrar** las
 * credenciales, que es lo que hace falta el día que un colegio suspende los pagos
 * una semana.
 *
 * Dicho al revés, que es como se lee sin equivocarse: *un interruptor que sólo
 * puede apagar*. Si alguna vez `activa=1` enciende algo por sí solo, esta tabla
 * dejó de significar lo que dice aquí.
 *
 * ## UNA FILA POR PROVEEDOR, NO UNA FILA Y YA
 *
 * `unique(proveedor)` en vez de una tabla de una sola fila. Cuesta lo mismo y deja
 * que un colegio guarde las credenciales de Wompi y las del convenio del banco a la
 * vez, encendiendo una — que es exactamente el camino que el doc 40 §3 prevé para
 * el colegio grande: *«migrar a convenio bancario sin tocar código»*. Atarse a una
 * pasarela con el nombre de las columnas era el error fácil.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('config_pasarela', function (Blueprint $tabla) {
            $tabla->increments('id');

            // `wompi` hoy. Es la columna que impide que esto sea «la tabla de
            // Wompi» — ver la cabecera.
            $tabla->string('proveedor', 20);

            // `pruebas` | `produccion`. Cambia el dominio al que se pregunta, y es
            // lo primero que hay que poder mover sin desplegar: un colegio prueba
            // con su llave de sandbox antes de cobrarle a una familia de verdad.
            //
            // Wompi ata las llaves al ambiente —`pub_test_` contra sandbox y
            // `pub_prod_` contra producción—, así que una llave de pruebas con
            // `ambiente=produccion` no es media configuración: no funciona.
            $tabla->string('ambiente', 12)->default('pruebas');

            // `pub_...`. **Pública por diseño**: viaja al navegador. Que esté
            // guardada aquí no la convierte en un secreto.
            $tabla->string('llave_publica', 120)->nullable();

            // Con esto se firma el checkout para que el navegador no pueda cambiar
            // el importe. **Es un secreto** y no sale nunca en ninguna respuesta.
            $tabla->string('secreto_integridad', 120)->nullable();

            // Con esto se comprueba la firma de cada webhook. **Es obligatorio**:
            // sin él no se admite ningún evento. Ver la cabecera.
            $tabla->string('secreto_eventos', 120)->nullable();

            // `prv_...`. **La única credencial de esta tabla que toca dinero**, y
            // por eso es la única opcional: con ella se reconsulta la transacción
            // —la regla del doc 40 §4— y sin ella decide la firma del evento. Un
            // colegio que no quiera dárnosla sigue cobrando.
            $tabla->string('llave_privada', 120)->nullable();

            // A dónde vuelve la familia cuando termina de pagar. Es del colegio
            // porque cada uno tiene su dominio.
            $tabla->string('url_retorno', 255)->nullable();

            // Ver la cabecera: **sólo puede apagar**.
            $tabla->boolean('activa')->default(false);

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->unsignedInteger('updated_by')->nullable();
            $tabla->timestamps();

            $tabla->unique('proveedor', 'config_pasarela_proveedor');
        });

        Schema::create('pagos_inscripcion', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('orden_id');

            // Se copia del `config_pasarela` del momento en vez de deducirse al
            // leer: un pago cobrado por Wompi en 2027 se cobró por Wompi para
            // siempre, aunque el colegio se pase al banco en 2028.
            $tabla->string('proveedor', 20);

            // **La nuestra**, la que viaja a la pasarela y vuelve en el webhook. Es
            // la llave por la que se reconoce un evento, así que es `unique`: dos
            // filas con la misma referencia harían que un pago aprobado tocara una
            // fila cualquiera de las dos.
            //
            // Lleva el código del formulario dentro **a propósito**: el día que haya
            // que cuadrar un extracto del banco a mano, la referencia dice de qué
            // papel habla sin consultar nada.
            $tabla->string('referencia', 64);

            // La de la pasarela. Llega con el webhook, así que nace en NULL — y por
            // eso no puede ser la llave de esta tabla aunque parezca la natural.
            $tabla->string('transaccion_id', 64)->nullable();

            // **En centavos y no en pesos**, que es como lo pide la pasarela y como
            // hay que firmarlo. Guardar aquí lo mismo que se firmó es lo que permite
            // comparar de vuelta: si el importe que confirma la pasarela no es éste,
            // el pago no vale aunque esté aprobado.
            $tabla->unsignedInteger('monto_centavos');

            // `varchar` y no `char`: MySQL rellena un `char` con espacios y los quita
            // al leer, y esto se COMPARA contra lo que devuelve la pasarela.
            $tabla->string('moneda', 3)->default('COP');

            // CREADO | APROBADO | RECHAZADO | ERROR
            //
            // Es **nuestro** estado, no el de la pasarela: sólo pasa a APROBADO
            // cuando la comprobación lo confirma.
            $tabla->string('estado', 12)->default('CREADO');

            // El de la pasarela, tal cual lo dijo. Se guarda aparte porque sus
            // valores son suyos y pueden crecer sin avisar; mezclarlos con los
            // nuestros haría que un estado nuevo de Wompi se leyera como uno de los
            // nuestros.
            $tabla->string('estado_pasarela', 30)->nullable();

            // `reconsulta` | `firma`. **Cómo** se admitió este pago, no si se
            // admitió. Existe porque la reconsulta es opcional (ver la cabecera) y
            // una comprobación opcional sin rastro es una que nadie sabe si está
            // encendida: con esta columna, *«¿esto se comprobó de verdad?»* se
            // contesta mirando la fila.
            $tabla->string('verificado_por', 12)->nullable();

            $tabla->timestamp('verificado_at')->nullable();

            // La respuesta de la pasarela, entera. Es lo único que queda para cuadrar
            // con el extracto el día que una familia diga que pagó y aquí no conste:
            // sin esto, la discusión es su captura de pantalla contra nuestra palabra.
            $tabla->text('respuesta')->nullable();

            // De dónde se abrió el checkout. La ruta es pública y no identifica a
            // nadie: es lo único que queda para reconstruir un abuso.
            $tabla->string('creada_ip', 45)->nullable();

            $tabla->timestamps();

            $tabla->unique('referencia', 'pagos_inscripcion_referencia');
            $tabla->index('orden_id', 'pagos_inscripcion_orden');
            $tabla->index('transaccion_id', 'pagos_inscripcion_transaccion');

            $tabla->foreign('orden_id')->references('id')
                ->on('ordenes_inscripcion')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pagos_inscripcion');
        Schema::dropIfExists('config_pasarela');
    }
};
