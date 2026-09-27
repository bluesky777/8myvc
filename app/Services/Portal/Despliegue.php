<?php

namespace App\Services\Portal;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El bloque `despliegue` del cuerpo (§2.1.4): qué está sirviendo este colegio.
 *
 * Es lo que convierte «un colegio dejó de aparecer» en «un colegio dejó de
 * desplegar». Nada de esto puede tumbar el envío: cada dato que no se pueda leer
 * va a `null` y el resumen sale igual.
 *
 * **El commit se lee de `.git/` a mano, sin `exec`.** Cada colegio es un checkout
 * de git (`tools/desplegar.sh` hace `git pull --ff-only` y `tools/censo.sh` lee
 * `rev-parse` por SSH), pero en cPanel compartido `exec`/`shell_exec` pueden estar
 * deshabilitados y no se sabe en cuáles. Leer dos ficheros funciona en todos.
 */
final class Despliegue
{
    /** @return array{commit_desplegado:string|null, migraciones_ran:int|null, php_version:string, config_cacheado:bool, rutas_cacheadas:bool} */
    public static function parte(): array
    {
        try {
            $migraciones = (int) DB::selectOne('SELECT COUNT(*) AS n FROM migrations')->n;
        } catch (Throwable) {
            $migraciones = null;
        }

        return [
            'commit_desplegado' => self::commit(base_path()),
            'migraciones_ran' => $migraciones,
            'php_version' => PHP_VERSION,
            'config_cacheado' => app()->configurationIsCached(),
            'rutas_cacheadas' => app()->routesAreCached(),
        ];
    }

    /**
     * El hash corto de HEAD, o `null` si no hay git legible.
     *
     * Contempla el worktree (`.git` es un fichero `gitdir: …`, y las ramas viven
     * en el `commondir`) y las refs empaquetadas.
     */
    public static function commit(string $raiz): ?string
    {
        try {
            $git = $raiz.'/.git';
            if (is_file($git)) {
                $linea = trim((string) file_get_contents($git));
                if (! str_starts_with($linea, 'gitdir:')) {
                    return null;
                }
                $git = trim(substr($linea, 7));
                if (! str_starts_with($git, '/')) {
                    $git = $raiz.'/'.$git;
                }
            }
            if (! is_file($git.'/HEAD')) {
                return null;
            }

            $comun = $git;
            if (is_file($git.'/commondir')) {
                $rel = trim((string) file_get_contents($git.'/commondir'));
                $comun = str_starts_with($rel, '/') ? $rel : $git.'/'.$rel;
            }

            $head = trim((string) file_get_contents($git.'/HEAD'));
            if (! str_starts_with($head, 'ref:')) {
                return self::corto($head);
            }

            $ref = trim(substr($head, 4));
            foreach ([$git.'/'.$ref, $comun.'/'.$ref] as $fichero) {
                if (is_file($fichero)) {
                    return self::corto(trim((string) file_get_contents($fichero)));
                }
            }
            if (is_file($comun.'/packed-refs')) {
                foreach (file($comun.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                    if (str_ends_with($l, ' '.$ref)) {
                        return self::corto(substr($l, 0, 40));
                    }
                }
            }
        } catch (Throwable) {
            // Un .git ilegible no es motivo para no mandar el resumen.
        }

        return null;
    }

    private static function corto(string $hash): ?string
    {
        return preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? substr($hash, 0, 7) : null;
    }
}
