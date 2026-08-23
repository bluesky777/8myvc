<?php

namespace App\Services\Notificaciones;

/**
 * Publicar un aviso en un tema.
 *
 * Es una interfaz por una razón concreta y no por gusto: **el comando tiene que
 * poder probarse sin hablar con Google.** `notificaciones:enviar` agrupa cuatro
 * consultas, decide qué avisar y adelanta una marca, y todo eso es lo que hay
 * que comprobar; si para hacerlo hiciera falta una credencial de Firebase, no se
 * comprobaría nunca.
 */
interface Publicador
{
    /**
     * Si hay credenciales con las que hablar. Sin ellas el comando no es un
     * error: es un colegio al que todavía no se le ha puesto el push.
     */
    public function estaConfigurado(): bool;

    /**
     * Manda un aviso a un tema. Devuelve si salió.
     *
     * `$datos` viaja aparte del título y el cuerpo: es lo que la app usa para
     * abrir la pantalla que toca al tocar el aviso, y **nunca lleva el dato**
     * —ni la nota, ni el texto de la situación—, sólo a dónde ir.
     *
     * @param  array<string, string>  $datos
     */
    public function publicar(string $tema, string $titulo, string $cuerpo, array $datos = []): bool;
}
