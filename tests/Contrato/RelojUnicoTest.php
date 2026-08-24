<?php

namespace Tests\Contrato;

use App\Support\Reloj;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Que no vuelvan los dos relojes.
 *
 * `bitacoras.created_at` llegó a tener **12 filas escritas en UTC y 74 en
 * Bogotá** —medido el 24 ago 2026 con `tools/salud-de-la-bitacora.php`—, cinco
 * horas de diferencia dentro de la misma columna y nada en la fila que diga cuál
 * es cuál. Ordenar por ella no da una línea de tiempo. La fase 1 de
 * [docs/migracion/18-auditoria.md](../../docs/migracion/18-auditoria.md) lo
 * arregla con `App\Support\Reloj`, y esto es lo que impide que se deshaga.
 *
 * **Una regla sin test se deshace sola**, y ésta se deshace de la peor manera: un
 * `now()` nuevo en un sitio que escribe una fecha no rompe nada, no falla ningún
 * test, y mete una fila cinco horas movida que **nadie va a poder distinguir
 * después**. No hay forma de repararlo a posteriori porque no hay marca. Por eso
 * el centinela va sobre el código y no sobre el resultado: cuando el síntoma se
 * puede ver ya es tarde.
 */
class RelojUnicoTest extends TestCase
{
    /**
     * Los usos de reloj SIN zona que quedan, y por qué cada uno puede quedarse.
     *
     * Fichero => cuántas llamadas. Se cuenta por fichero y no por línea a
     * propósito: las líneas se mueven al editar y un centinela que salte por un
     * cambio de formato se acaba desactivando, que es peor que no tenerlo.
     *
     * **Todos los de esta lista tienen algo en común: no guardan una fecha que
     * alguien vaya a leer.** O se restan consigo mismos, o son un TTL relativo.
     * Ése es el criterio para entrar aquí, y el único.
     *
     * @var array<string, int>
     */
    private const PERMITIDOS = [
        // Diferencia contra `expires_at`, que se escribe con este mismo reloj.
        // Las dos puntas en UTC: la resta sale bien y no se guarda nada.
        'app/Models/TokenDeSesion.php' => 1,

        // Las cinco gobiernan la vida de los tokens (`expires_at`,
        // `last_used_at`, la gracia del refresco y el barrido de caducados).
        // Sólo se comparan con columnas escritas por ellas mismas, y ninguna
        // sale por pantalla. La sexta de este fichero SÍ se movió: escribía en
        // `bitacoras.created_at`, que es de todos.
        'app/Services/Sesion.php' => 5,

        // El TTL de la caché del token de FCM. Relativo (55 minutos desde
        // ahora), nunca guardado ni mostrado.
        'app/Services/Notificaciones/EnvioFcm.php' => 1,

        // El corte de antigüedad para limpiar sesiones, contra `expires_at`,
        // que está en UTC. Cambiarlo a Bogotá sin cambiar la columna es lo que
        // rompería la comparación.
        'app/Console/Commands/LimpiarSesiones.php' => 1,

        // LA EXCEPCIÓN, y la única que sí guarda fechas. Se deja fuera de la
        // fase 1 a sabiendas y con la cuenta hecha:
        //
        // `importaciones.inicio`/`fin` se escriben con `now()` (UTC) y su propia
        // cabecera lo documenta desde antes: sólo se restan entre sí, nunca se
        // comparan con otra tabla, así que unificar la zona «no cambia ningún
        // resultado — sólo desplaza cinco horas lo que se lee en pantalla».
        //
        // O sea que moverlo ARREGLA la pantalla y a cambio deja la tabla con dos
        // relojes en su historia, que es la enfermedad que la fase 1 viene a
        // curar. Cambiar esto es elegir entre las dos cosas, y esa elección no
        // es de la fase 1: es de quien lleve las importaciones. Anotado aquí para
        // que se encuentre, no escondido.
        'app/Services/PuntoDeControlDeImportacion.php' => 8,
    ];

    #[Test]
    public function el_reloj_guarda_en_bogota(): void
    {
        $this->assertSame('America/Bogota', Reloj::ZONA);
        $this->assertSame('America/Bogota', Reloj::ahora()->timezoneName);

        // Y que de verdad difiere de UTC, que es lo que se está previniendo. Sin
        // esto, un `config/app.php` puesto en Bogotá haría pasar el test de
        // arriba sin que `Reloj` hiciera nada.
        $this->assertNotSame(
            Carbon::now()->format('Y-m-d H:i'),
            Reloj::ahora()->format('Y-m-d H:i'),
            'El reloj de la aplicación y el de Bogotá dan la misma hora. O se '.
            'cambió `config/app.php` —y entonces hay que revisar la decisión 2 '.
            'del 18— o `Reloj` dejó de aplicar la zona.'
        );
    }

    #[Test]
    public function el_texto_lleva_milisegundos(): void
    {
        // `auditoria.ocurrido_en` es DATETIME(3): dos notas tecleadas en el mismo
        // segundo son dos líneas distintas del historial, y con precisión de
        // segundo no se sabe cuál fue primero.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/',
            Reloj::ahoraTexto()
        );
    }

    /**
     * El camino de vuelta, que es el que faltaba.
     *
     * Lo encontró `8myvc-39` con **17.999 segundos** de diferencia —las cinco
     * horas al segundo— leyendo `auditoria.ocurrido_en` con `strtotime()`. Una
     * cadena `DATETIME` **no lleva la zona dentro** y `config/app.php` está en
     * UTC, así que quien la lea sin decirla la mueve cinco horas y devuelve algo
     * que parece correcto.
     */
    #[Test]
    public function el_texto_vuelve_en_la_misma_hora_en_la_que_salio(): void
    {
        $salida = Reloj::ahoraTexto();
        $vuelta = Reloj::desdeTexto($salida);

        $this->assertNotNull($vuelta);
        $this->assertSame(Reloj::ZONA, $vuelta->timezoneName);
        $this->assertSame($salida, $vuelta->format('Y-m-d H:i:s.v'),
            'La ida y la vuelta no dan la misma hora: el viaje redondo está roto.');

        // Y la comparación que de verdad muerde: leerlo mal mueve cinco horas.
        //
        // El signo importa y me lo comí a la primera: Bogotá es **UTC−5**, así que
        // la misma hora de pared leída como UTC es un instante ANTERIOR, no
        // posterior. `$vuelta` (bien) va 18.000 segundos por delante de `$mal`.
        $mal = Carbon::createFromFormat('Y-m-d H:i:s.v', $salida);   // sin zona -> UTC
        $this->assertSame(
            5 * 3600,
            (int) round($vuelta->getTimestamp() - $mal->getTimestamp()),
            'Si esto deja de ser 18.000 segundos, o cambió la zona o cambió '.
            '`config/app.php`, y las dos cosas obligan a revisar el 18.'
        );
    }

    /** Una columna vacía o corrupta no se convierte en una hora plausible. */
    #[Test]
    public function un_texto_que_no_es_una_fecha_devuelve_null(): void
    {
        $this->assertNull(Reloj::desdeTexto(null));
        $this->assertNull(Reloj::desdeTexto(''));
        $this->assertNull(Reloj::desdeTexto('vete a saber'));
    }

    /** Y las columnas viejas, que son DATETIME sin milisegundos. */
    #[Test]
    public function tambien_lee_las_columnas_sin_milisegundos(): void
    {
        $fecha = Reloj::desdeTexto('2026-08-24 03:51:13');

        $this->assertNotNull($fecha);
        $this->assertSame('2026-08-24 03:51:13', $fecha->format('Y-m-d H:i:s'));
        $this->assertSame(Reloj::ZONA, $fecha->timezoneName);
    }

    #[Test]
    public function no_hay_relojes_sin_zona_nuevos(): void
    {
        $encontrados = $this->relojesSinZona();
        $esperados = self::PERMITIDOS;

        ksort($encontrados);
        ksort($esperados);

        $this->assertSame(
            $esperados,
            $encontrados,
            "Ha cambiado el reparto de `now()` / `Carbon::now()` sin zona en `app/`.\n\n".
            "Si has AÑADIDO uno: mira si lo que escribes acaba en una columna de la base.\n".
            "  - Si acaba en la base            -> usa App\\Support\\Reloj::ahora().\n".
            "  - Si sólo se compara consigo mismo o es un TTL relativo -> añádelo a\n".
            "    PERMITIDOS de este test CON EL MOTIVO, que es lo que se revisa.\n\n".
            "Por qué importa: un `now()` en un sitio que guarda una fecha no rompe nada,\n".
            "no falla ningún test, y mete una fila cinco horas movida que después NADIE\n".
            "puede distinguir de una buena. `bitacoras` ya tiene 12 así. Ver 18 §1.1.\n\n".
            'Encontrado ahora: '.json_encode($encontrados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function los_tres_escritores_de_bitacora_usan_el_reloj(): void
    {
        // El corazón de la fase 1: los tres que escribían `bitacoras.created_at`
        // con el reloj equivocado. Comprobar el reparto global no basta —alguien
        // podría devolverlos a `now()` y ajustar PERMITIDOS de paso—, así que
        // estos tres se nombran uno a uno.
        $movidos = [
            'app/Http/Middleware/ExigirPersonaPropia.php',
            'app/Http/Middleware/ExigirBoletinPropio.php',
            'app/Services/Sesion.php',
        ];

        foreach ($movidos as $fichero) {
            $codigo = (string) file_get_contents(base_path($fichero));

            $this->assertStringContainsString(
                'Reloj::ahora()',
                $codigo,
                "{$fichero} ya no usa `Reloj::ahora()`. Escribe en `bitacoras.created_at`, ".
                'que es una columna compartida con otros siete escritores en hora de Bogotá.'
            );
        }
    }

    /**
     * Los `now()` / `Carbon::now()` sin zona que hay en `app/`, por fichero.
     *
     * Cuenta llamadas y no líneas: `PuntoDeControlDeImportacion` tiene una línea
     * con tres. Y descarta comentarios, porque este repo explica sus decisiones
     * de zona por escrito y media docena de cabeceras mencionan `now()` — la
     * primera versión de este contador las incluyó y dio de más.
     *
     * @return array<string, int>
     */
    private function relojesSinZona(): array
    {
        $encontrados = [];

        /** @var iterable<\SplFileInfo> $ficheros */
        $ficheros = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'))
        );

        foreach ($ficheros as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $cuantos = 0;

            foreach (file($fichero->getPathname()) ?: [] as $linea) {
                $limpia = ltrim($linea);

                if (str_starts_with($limpia, '*') || str_starts_with($limpia, '//') || str_starts_with($limpia, '/*')) {
                    continue;
                }

                $cuantos += preg_match_all('/Carbon::now\(\s*\)|(?<![>$\w:\'"-])now\(\s*\)/', $linea);
            }

            if ($cuantos > 0) {
                $encontrados[str_replace(base_path().'/', '', $fichero->getPathname())] = $cuantos;
            }
        }

        return $encontrados;
    }
}
