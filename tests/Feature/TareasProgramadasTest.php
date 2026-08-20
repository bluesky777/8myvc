<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Lo que tiene que correr solo.
 *
 * Existe porque una tarea programada falla de la peor manera posible: **en
 * silencio**. El cron manda su salida a `/dev/null` —tiene que hacerlo, o son
 * 1.440 correos al día por colegio—, así que si alguien quita una línea del
 * scheduler nadie se entera hasta que la tabla ha crecido un año.
 *
 * No comprueba que el cron esté puesto en el servidor, que eso no se puede ver
 * desde aquí; comprueba que la aplicación sigue pidiendo lo que hay que pedir.
 */
class TareasProgramadasTest extends TestCase
{
    public function test_los_tokens_caducados_se_siguen_limpiando_solos(): void
    {
        $comandos = collect(app(Schedule::class)->events())
            ->map(fn ($evento) => $evento->command)
            ->filter();

        $this->assertTrue(
            $comandos->contains(fn ($comando) => str_contains($comando, 'sesion:limpiar')),
            "`sesion:limpiar` ya no está en el scheduler.\n".
            "Es lo único que recoge los tokens de quien no vuelve a entrar —el alumno que se\n".
            'gradúa, el profesor que se va—, y sin él `personal_access_tokens` crece sin techo.'
        );
    }
}
