# Código que ya no funciona y nadie ha notado

Hallazgos de la auditoría de rutas (18 ago 2026). Ninguno lo provocó esta
migración: son cosas que **se rompieron solas** al subir de versión de PHP o de
Laravel, sin que nadie tocara el fichero, y que no se notaron porque nadie mira
lo que no falla en pantalla.

Están aquí porque el salto de la Fase 4 (Laravel 8 → 13, PHP 8.0 → 8.4) va a
producir más de lo mismo, y porque son el argumento más concreto a favor de
terminar los tests de contrato antes de darlo.

---

## 1. `Input::` — eliminada en Laravel 5.2, todavía en 7 controladores

La clase `Input` desapareció de Laravel en la 5.2. No hay alias en
`config/app.php` y ningún controlador la importa, así que cada `Input::` que se
ejecute lanza **"Class App\Http\Controllers\Input not found"** y responde 500.

Comprobado: `class_exists('Input')` es `false`.

| Dónde | Estado |
|---|---|
| `RemindersController` (4 métodos) | **Vivo y roto.** Sus 4 rutas responden 500 |
| `EstadosCivilesController::store` y `::update` | **Enrutados y rotos.** 500 al llamarlos |
| `UsersController::store` y `::update` | Rotos, pero **sin ruta**: nadie los alcanza |
| `RolesController`, `CertificadosEstudioController` | Comentados, inertes |
| `LoginController::postIndex`, `LoginAppController::postIndex` | **Inalcanzable** — ver abajo |

### Por qué el de `postIndex` no llega a ejecutarse

Está dentro de un `catch` que nunca casa:

```php
catch(Tymon\JWTAuth\Exceptions\TokenExpiredException $e)   // sin barra inicial
```

El fichero está en `namespace App\Http\Controllers`, así que eso resuelve a
`App\Http\Controllers\Tymon\JWTAuth\Exceptions\TokenExpiredException`, una clase
que no existe. Un `catch` con un tipo inexistente no lanza error: simplemente
no captura nunca.

Y aunque casara, tampoco llegaría: `User::fromToken()` atrapa la excepción por
dentro y aborta con 401 antes de devolver el control.

**Verificado en vivo**, no deducido: `POST /api/login` con un token caducado de
verdad devuelve `401 Token ha expirado.`, que es lo correcto.

O sea que un fallo (la barra que falta) tapa a otro (`Input`). Al arreglar
cualquiera de los dos por separado, aparece el otro.

---

## 2. `RemindersController` — andamiaje de Laravel 4, sin cliente

Los 4 endpoints de `password/*` responden **500**:

```
GET  api/password/remind        500
POST api/password/remind        500
GET  api/password/reset/{token} 500
POST api/password/reset         500
```

Usa `Input`, `Password::remind()` (que ya no existe; hoy es `sendResetLink`) y
`View::make('password.remind')` sobre vistas que **no están en el repo**.

**No es una segunda vía de reseteo de contraseñas.** No puede cambiar nada: falla
antes de tocar la base. La recuperación real es
`login/recuperar-clave` → `login/reset-password`.

Confirmado por la sesión de `myvc_front` y verificado leyendo los dos repos: ni
el front web ni la app Flutter llaman a ninguna de las cuatro.

---

## 3. `estados_civiles` — recurso completo sin cliente, y roto a medias

El front **no llama a este recurso de ninguna forma**: la lista está escrita a
mano en `ProfesoresNewCtrl:14` y `ProfesoresEditCtrl:16` (`Soltero`, `Casado`,
`Divorciado`, `Viudo`).

Además `store` y `update` usan `Input::`, así que **responderían 500** si alguien
las llamara. Solo `index` y `destroy` funcionarían.

Sus 3 rutas de andamiaje vacío ya se borraron. Quedan 5.

---

## 4. Ya arreglados en el PR #7

- **`login/logout` devolvía 500 siempre**, desde el import de 2021.
  `DB::update()` devuelve un entero y el código le aplicaba `[0]`. Hasta PHP 7.3
  indexar un entero devolvía `null` en silencio; desde 7.4 es un warning y
  Laravel lo convierte en excepción. Se rompió solo al subir de versión.
  Nadie lo notó porque el front lanza la llamada sin esperar la respuesta.

- **3 rutas de `tiposdocumento`** (`create`, `show`, `edit`) apuntaban a métodos
  que no existen: 500. Borradas.

- **10 rutas con el método vacío**: devolvían 200 y nada. Borradas.

---

## La lección para la Fase 4

Los cuatro casos comparten forma: **algo dejó de funcionar al cambiar de versión,
y el sistema siguió respondiendo lo bastante bien como para que nadie mirara.**

El salto de PHP 8.0 a 8.4 y de Laravel 8 a 13 va a producir más. Lo que los
detecta no es leer el código —estos llevaban años a la vista— sino golpear los
endpoints y comparar la respuesta. Es exactamente lo que hacen los tests de
contrato de la Fase 0, y por eso conviene terminarlos antes.
