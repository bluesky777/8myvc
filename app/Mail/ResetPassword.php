<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo con el enlace de reseteo de contraseña.
 *
 * Antes se enviaba con la función mail() de PHP, con el HTML incrustado en el
 * controlador y las cabeceras construidas a mano a partir de un destinatario sin
 * validar, lo que permitía inyectar cabeceras.
 */
class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public $username;

    public $enlace;

    public function __construct($username, $enlace)
    {
        $this->username = $username;
        $this->enlace = $enlace;
    }

    public function build()
    {
        return $this->subject('Ver contraseña Mi Colegio Virtual')
                    ->view('emails.reset-password');
    }
}
