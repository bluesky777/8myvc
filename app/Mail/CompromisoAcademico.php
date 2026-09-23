<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El correo del compromiso académico, en sus dos momentos.
 *
 * # ESTO NO ES EL CANAL. Léase antes de darle importancia que no tiene.
 *
 * **Medido el 22 sep 2026 sobre el volcado de `simonbolivar`**: de **1.085**
 * acudientes no borrados, **100 tienen correo (9,2 %)** y **1.020 tienen celular
 * (94 %)**. Y el dato hermano ya estaba medido en el repositorio: de 853 alumnos
 * alcanzables, **655 llevan un `@gmail.com` fabricado** y sólo 196 tienen correo
 * real (`App\Support\CorreoDeLaCuenta`, cabecera).
 *
 * Es decir: **notificar un compromiso por correo llega a uno de cada once
 * acudientes.** No es un problema de infraestructura —el correo sale— es que el
 * dato no está. Por eso `config_compromiso.canal_correo` **nace apagado y es el
 * único de los tres canales que lo hace** (`canal_papel` y `canal_push` nacen
 * encendidos, migración `2026_09_22_100000_la_plantilla_del_compromiso`).
 *
 * **Esta clase existe porque algún colegio lo encenderá, no porque sea el camino
 * principal.** El camino principal son el push —que llega al 94 %— y el papel
 * firmado el día de entrega de boletines, que además es la prueba más sólida.
 * Diseño: `myvc_front/COMPROMISOS-ACADEMICOS.md` §4 y §4.2.
 *
 * > Y un canal encendido que alcanza al 9 % es **peor** que uno apagado, porque
 * > el colegio cree que avisó. Si alguna pantalla ofrece encenderlo, tiene que
 * > decir a cuántos acudientes de ese grupo les falta el correo **antes** de que
 * > el coordinador pulse.
 *
 * ## LO QUE VA DENTRO: el mismo criterio que el push, y no por inercia
 *
 * **Ni una asignatura, ni un número de perdidas, ni una nota, ni el veredicto.**
 * §4.4 del diseño: un compromiso lleva datos de un menor y de su rendimiento, y
 * la Ley 1581 de 2012 —que el propio formato en papel cita en su última página—
 * es la razón por la que el contenido se lee **entrando**, no en la bandeja.
 *
 * El correo no se ve en una pantalla bloqueada, pero llega a buzones compartidos,
 * se reenvía y se queda en el servidor de un tercero durante años. Y **un
 * compromiso que hoy se manda por correo se manda porque el colegio no tiene
 * celular del acudiente**, que es justo el caso en el que menos se sabe quién
 * está al otro lado.
 *
 * Así que esto dice **que hay un documento, de quién es y dónde verlo**. El
 * nombre del alumno sí —sin él la familia con tres hijos no sabe de cuál le
 * hablan, y es lo que ya permite `myvc_flutter/docs/notificaciones.md`—; el
 * motivo, no.
 *
 * ## LOS DOS MOMENTOS SON UNA CLASE Y NO DOS
 *
 * La entrega (§5, paso 4) y el resultado (§5, paso 10) son dos correos distintos:
 * distinto asunto, distinto párrafo, y **el segundo arranca el plazo de
 * reclamación** (`config_compromiso.dias_reclamacion`, contado desde
 * `compromisos.resultado_entregado_at`). Pero la plantilla, el remitente, la
 * firma y el aviso de privacidad son los mismos, y dos clases serían la misma
 * maqueta copiada — que es como se consiguen dos correos que dejan de parecerse
 * al tercer arreglo. La diferencia viaja en `$momento` y la resuelve la vista.
 *
 * ## LA FORMA, COPIADA DEL PRIMERO
 *
 * **Éste es el segundo `Mailable` del proyecto**; el primero es `ResetPassword`,
 * y de él se copia todo lo que no hay motivo para cambiar:
 *
 *   - `Queueable` y `SerializesModels` en el `use`, y **envío directo con
 *     `Mail::to(...)->send(...)`, no `queue()`**: `config/queue.php` está en
 *     `sync`, así que encolar es ejecutar en el mismo sitio con un nombre más
 *     largo. En hosting compartido no hay ningún proceso vivo escuchando. El
 *     `Queueable` se queda porque es lo que hace el primero y porque el día que
 *     haya cola no hay que volver aquí.
 *   - `build()` con `->subject()->view()`, propiedades públicas y nada más.
 *   - **Se prueba con `Mail::fake()` y `Mail::assertSent()`**, como
 *     `tests/Contrato/ResetCorreoCompartidoTest.php:61-72`. `phpunit.xml` pone
 *     `MAIL_MAILER=array`, así que ninguna prueba manda nada de verdad.
 *
 * Y lo que **no** se copia: la cabecera del correo de reseteo es una imagen
 * servida desde `lalvirtual.edu.co`, o sea el dominio de **un** colegio, en un
 * correo que mandan los dieciséis. Aquí el encabezado es el nombre del colegio en
 * texto. El logo de verdad vive en `years.logo`, es una fila de la base y no una
 * URL pública, así que incrustarlo sería adjuntarlo — y un adjunto en un aviso
 * que sólo quiere que abras la app es lo que lo manda a spam.
 *
 * ## DÓNDE SE MANDA, que todavía no existe
 *
 * En `PUT compromisos/{id}/entregar` y `PUT compromisos/{id}/entregar-resultado`
 * (§3.3 del diseño), y **sólo si `config_compromiso.canal_correo` está
 * encendido** y el acudiente tiene un correo que pase `CorreoDeLaCuenta::oNada()`
 * —el `@gmail.com` fabricado no es una dirección—. Esas dos rutas no están
 * escritas a 22 sep 2026.
 *
 * Y va **dentro de un `try`**, como el del reseteo (`LoginController:314-322`):
 * `mail()` fallaba en silencio y `Mail` lanza. Aquí eso importa el doble, porque
 * **el correo de esta API está en rojo en al menos un colegio desde el 2 sep**
 * —`lalvirtual.com` no está registrado—, y un compromiso cuya entrega se cae
 * entera porque el SMTP no contestó es una entrega perdida por el canal que menos
 * vale. Se registra el fallo y la entrega sigue.
 */
class CompromisoAcademico extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * El compromiso se entregó y hay que leerlo y firmarlo. §5, paso 4.
     */
    public const ENTREGA = 'entrega';

    /**
     * El resultado volvió, y con él arranca el plazo de reclamación. §5, paso 10.
     */
    public const RESULTADO = 'resultado';

    /** @var string El nombre del alumno, tal y como lo va a leer la familia. */
    public $alumno;

    /** @var string El colegio que escribe. Va en el asunto y en el encabezado. */
    public $colegio;

    /** @var string A dónde entrar a ver el documento. */
    public $enlace;

    /** @var string `entrega` o `resultado`. */
    public $momento;

    /**
     * @throws \InvalidArgumentException si el momento no es uno de los dos.
     *
     * Reventar y no elegir un defecto, que es la misma doctrina de
     * `TemasDeNotificacion::deAlumnoYTipo()`: un momento mal escrito mandaría un
     * correo **válido y equivocado** —el de la entrega en lugar del del
     * resultado—, y eso no se ve en ningún registro. Un 500 sí.
     */
    public function __construct(string $alumno, string $colegio, string $enlace, string $momento = self::ENTREGA)
    {
        if (! in_array($momento, [self::ENTREGA, self::RESULTADO], true)) {
            throw new \InvalidArgumentException("Momento de compromiso desconocido: {$momento}");
        }

        $this->alumno = $alumno;
        $this->colegio = $colegio;
        $this->enlace = $enlace;
        $this->momento = $momento;
    }

    public function build()
    {
        // El colegio va en el asunto porque el remitente no lo dice: cada
        // instalación tiene su `.env`, y varios apuntan al mismo buzón del
        // hosting. Sin esto, la familia con hijos en dos colegios ve dos correos
        // idénticos y sólo puede distinguirlos abriéndolos.
        $asunto = $this->momento === self::RESULTADO
            ? 'Resultado del compromiso académico'
            : 'Compromiso académico';

        return $this->subject($asunto.' — '.$this->colegio)
            ->view('emails.compromiso-academico');
    }
}
