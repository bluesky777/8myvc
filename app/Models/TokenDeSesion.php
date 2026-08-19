<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Una fila de `personal_access_tokens`: un token de acceso o uno de refresco.
 *
 * Extiende el modelo de Sanctum solo para añadir las dos columnas que su tabla
 * no trae y esta sí —`expires_at` y `reemplazado_por`—. Ver la migración para
 * el porqué de cada una.
 *
 * Se registra como el modelo de Sanctum en AppServiceProvider, así que
 * `$user->tokens()` y `$user->createToken()` devuelven esto.
 */
class TokenDeSesion extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** La habilidad del token que abre puertas: el que viaja en cada petición. */
    public const ACCESO = 'acceso';

    /** La habilidad del token que solo sirve para pedir otro par. */
    public const REFRESCO = 'refrescar';

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'reemplazado_por',
    ];

    protected $casts = [
        'abilities'    => 'json',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function haCaducado(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** ¿Ya se usó para rotar? Entonces presentarlo otra vez es reutilización. */
    public function fueRotado(): bool
    {
        return $this->reemplazado_por !== null;
    }

    public function esDeAcceso(): bool
    {
        return $this->can(self::ACCESO);
    }

    public function esDeRefresco(): bool
    {
        return $this->can(self::REFRESCO);
    }

    /** Cuántos segundos le quedan de vida. Es el `expira_en` de la respuesta. */
    public function segundosDeVida(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, Carbon::now()->diffInSeconds($this->expires_at, false));
    }
}
