<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Que el log por defecto no vuelva a crecer sin fin.
 *
 * Es una línea de configuración y por eso lleva test: se deshace sola el día
 * que alguien copie un `config/logging.php` de un Laravel recién instalado,
 * donde `stack` apunta a `single`. `single` escribe siempre en el mismo fichero
 * y no lo trunca nunca — en el docker de desarrollo pesaba 48 MB, y en los
 * colegios el disco es el motivo por el que `vendor/` va compartido por symlink.
 *
 * No se comprueba el nombre del canal sino la propiedad que importa: que lo que
 * escriba el canal por defecto caduque.
 */
class RotacionDeLogsTest extends TestCase
{
    public function test_el_canal_por_defecto_no_escribe_en_un_fichero_eterno(): void
    {
        $canales = config('logging.channels');

        foreach ($this->canalesQueUsaLaAplicacion() as $nombre) {
            $canal = $canales[$nombre] ?? [];

            $this->assertNotSame('single', $canal['driver'] ?? null,
                "El canal '{$nombre}' escribe con 'single', que no rota nunca. Debe ser 'daily'.");

            if (($canal['driver'] ?? null) === 'daily') {
                $this->assertGreaterThan(0, $canal['days'] ?? 0,
                    "El canal '{$nombre}' rota pero no borra nada: sin 'days' el disco crece igual.");
            }
        }
    }

    /** El canal por defecto, y los que apile si es un `stack`. */
    private function canalesQueUsaLaAplicacion(): array
    {
        $defecto = config('logging.default');
        $canal = config("logging.channels.{$defecto}", []);

        return ($canal['driver'] ?? null) === 'stack'
            ? $canal['channels']
            : [$defecto];
    }
}
