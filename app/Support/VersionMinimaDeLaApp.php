<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * El campo `version_minima_app` que viaja en la respuesta del login.
 *
 * `myvc_flutter` **ya tiene escrito y probado** el bloqueo por versión mínima;
 * lo que faltaba era que el backend mandara el número. El contrato lo escribió
 * la app y **no se reinventa aquí**:
 *
 * - el campo se llama **`version_minima_app`**;
 * - el valor es el **`versionCode`** (el `+N` de `pubspec.yaml`), no la versión
 *   con puntos;
 * - la app lo lee de forma tolerante —`"12"` como cadena también le vale—, así
 *   que se manda un entero, que es lo que menos se puede malinterpretar.
 *
 * > **El plazo, y por qué está escrito aquí:** si alguna vez se prefiere otro
 * > nombre para el campo, hay que decirlo **antes de que se publique una versión
 * > de la app leyendo éste**. Después, cambiarlo obliga a mandar **los dos**
 * > durante toda la transición, porque la app es una sola para los dieciséis
 * > colegios y no se puede actualizar a todos a la vez.
 *
 * ## Sin configurar, el campo NO viaja
 *
 * No viaja como `null`: **no está la clave**. Es lo que hace que este cambio sea
 * inerte al desplegarlo —la forma de la respuesta no cambia, y los snapshots de
 * contrato siguen valiendo— y lo que deja la decisión de bloquear en manos de
 * quien rellena el `.env`, que es donde tiene que estar.
 *
 * ## Un valor que no es un número se ignora, y se dice
 *
 * `.env` es texto, y `APP_MOVIL_VERSION_MINIMA=v1.4` o un `12 # el de agosto`
 * son errores plausibles. Ahí se falla **hacia el lado que no deja a nadie
 * fuera** —el campo no se manda— pero **no en silencio**: va al log con el valor
 * dentro.
 *
 * Las dos mitades importan. Sin la primera, un `(int)` silencioso convertiría
 * `"v1.4"` en `0` y `"abc"` en `0`, que la app leería como «sin mínimo» — que da
 * la casualidad de que es correcto, pero por accidente. Y sin la segunda, **desde
 * el cliente no se distingue un `.env` mal puesto de un colegio que no exige
 * nada**, y son dieciséis `.env` distintos: el que lo escribió mal no se entera
 * nunca.
 */
final class VersionMinimaDeLaApp
{
    /**
     * El `versionCode` mínimo configurado, o `null` si este colegio no exige
     * ninguno (o si lo que hay puesto no es un número).
     */
    public static function valor(): ?int
    {
        // `config()` y **no `env()`**, aunque el valor venga del `.env`. Con
        // `php artisan config:cache` puesto —que es lo razonable en producción—
        // `env()` fuera de `config/` devuelve `null` siempre, así que un
        // `env('APP_MOVIL_VERSION_MINIMA')` aquí haría que el campo dejara de
        // viajar **el día que alguien cachee la configuración**, sin tocar nada y
        // sin ningún error. La única llamada a `env()` está en
        // `config/aplicacion-movil.php`, que es donde Laravel la resuelve antes
        // de congelarla.
        $puesto = config('aplicacion-movil.version_minima');

        // `null` es «no está en el .env»; `''` es «está y vacío», que es como
        // queda al copiar `.env.example`. Los dos significan lo mismo y ninguno
        // es un error que haya que avisar.
        if ($puesto === null || $puesto === '') {
            return null;
        }

        if (! is_numeric($puesto)) {
            Log::warning('APP_MOVIL_VERSION_MINIMA no es un número; no se manda `version_minima_app`.', [
                'valor' => $puesto,
                'esperado' => 'el versionCode entero de una versión publicada, p. ej. 1',
            ]);

            return null;
        }

        return (int) $puesto;
    }

    /**
     * Añade el campo a una respuesta de login, si hay algo que añadir.
     *
     * Se pasa la respuesta entera y se devuelve entera —en vez de que cada
     * llamante haga su propio `if`— porque son **cuatro** los sitios por los que
     * un cliente ve un login, y cuatro `if` son cuatro sitios donde olvidarse de
     * uno. Es el mismo motivo por el que la auditoría tiene un escritor único.
     *
     * @template TRespuesta of array<string, mixed>
     *
     * @param  TRespuesta  $respuesta
     * @return TRespuesta
     */
    public static function adjuntarA(array $respuesta): array
    {
        $minima = self::valor();

        if ($minima === null) {
            return $respuesta;
        }

        $respuesta['version_minima_app'] = $minima;

        return $respuesta;
    }
}
