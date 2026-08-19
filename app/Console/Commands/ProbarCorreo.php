<?php

namespace App\Console\Commands;

use App\Mail\ResetPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Diagnóstico del envío de correo.
 *
 * Existe porque el reseteo de contraseña pasó de la función mail() de PHP, que
 * falla en silencio, al Mail de Laravel, que lanza excepción. Si el .env de un
 * colegio no tiene el transporte bien configurado, el reseteo devuelve 500.
 *
 * Este comando permite comprobarlo en un servidor recién desplegado sin tener
 * que provocar un reseteo real ni leer los logs.
 */
class ProbarCorreo extends Command
{
    protected $signature = 'correo:probar {destinatario : Dirección a la que enviar la prueba}';

    protected $description = 'Envía un correo de prueba y dice exactamente qué falla si no sale';

    public function handle()
    {
        $destinatario = $this->argument('destinatario');

        $this->line('');
        $this->line('  transporte .......... '.config('mail.default'));

        if (config('mail.default') === 'smtp') {
            $this->line('  host ................ '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        }

        if (config('mail.default') === 'sendmail') {
            $ruta = config('mail.mailers.sendmail.path');
            $binario = explode(' ', trim($ruta))[0];
            $this->line('  ruta ................ '.$ruta);
            $this->line('  binario existe ...... '.(is_executable($binario) ? 'sí' : 'NO  <-- esto es el problema'));
            $this->line('  sendmail_path de PHP  '.(ini_get('sendmail_path') ?: '(sin definir)'));
        }

        $remitente = config('mail.from.address');
        $this->line('  remitente ........... '.($remitente ?: 'SIN DEFINIR  <-- MAIL_FROM_ADDRESS'));
        $this->line('  destinatario ........ '.$destinatario);
        $this->line('');

        if (! $remitente) {
            $this->error('  MAIL_FROM_ADDRESS está vacío. Laravel rechaza el envío antes de intentarlo.');

            return 1;
        }

        try {
            Mail::to($destinatario)->send(
                new ResetPassword('usuario-de-prueba', config('app.url').'/#!/reset-password/prueba/usuario-de-prueba')
            );
        } catch (\Throwable $e) {
            $this->error('  FALLÓ: '.$e->getMessage());
            $this->line('');
            $this->line('  Si el transporte es sendmail y el binario no existe, busca el real con');
            $this->line('  "which sendmail" y ponlo en MAIL_SENDMAIL_PATH del .env.');
            $this->line('  Si es smtp, revisa MAIL_HOST, MAIL_PORT y las credenciales.');

            return 1;
        }

        $this->info('  Enviado sin errores. Revisa la bandeja de '.$destinatario.'.');
        $this->line('');
        $this->line('  Ojo: "sin errores" significa que el transporte lo aceptó, no que haya');
        $this->line('  llegado. Si no aparece, el problema está aguas abajo (SPF, spam, cola).');

        return 0;
    }
}
