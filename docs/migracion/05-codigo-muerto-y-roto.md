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
| `RemindersController` (4 métodos) | ~~Vivo y roto~~ · **sus 4 rutas borradas** el 18 ago 2026; el controlador sigue |
| `EstadosCivilesController::store` y `::update` | ~~Enrutados y rotos~~ · **controlador borrado** el 18 ago 2026 |
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

> **Rutas borradas el 18 ago 2026.** El controlador sigue en el repo; quitarlo es
> limpieza aparte. Lo de abajo es cómo estaba.

Los 4 endpoints de `password/*` respondían **500**:

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

> **Borrado entero el 18 ago 2026**, rutas y controlador. Lo de abajo es por qué.

El front **no llama a este recurso de ninguna forma**: la lista está escrita a
mano en `ProfesoresNewCtrl:14` y `ProfesoresEditCtrl:16` (`Soltero`, `Casado`,
`Divorciado`, `Viudo`).

Además `store` y `update` usan `Input::`, así que **responderían 500** si alguien
las llamara. Solo `index` y `destroy` funcionarían.

Tenía 8 rutas: 3 de andamiaje vacío y 5 con código. Ninguna tenía cliente.

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

## 5. Encontrado al sacar la autenticación de los constructores (18 ago 2026)

### `fromToken()` se llamaba a sí misma y tiraba el resultado

Cuando el `periodo_id` del usuario es de otro año, `User::fromToken()` lo corrige
en la base y vuelve a resolver. Pero hacía `USER::fromToken($already_parsed);` y
después `return;` a secas, así que **devolvía null**.

El síntoma: el usuario entra y recibe **200 con el cuerpo vacío**. Al segundo
intento funciona, porque el `UPDATE` de la primera vez ya dejó el periodo
arreglado. O sea que parece cosa de una vez y nadie lo reporta.

Se llega ahí de forma natural al pasar de año, que es justo cuando más gente
entra a la vez. Arreglado, y con test:
`ContextoDeUsuarioTest::test_un_periodo_de_otro_anio_se_corrige_y_devuelve_el_contexto`.

### Un `BolfinalesController` duplicado, sin rutas

Hay dos: `app/Http/Controllers/BolfinalesController.php` (610 líneas) y
`app/Http/Controllers/Informes/BolfinalesController.php`. **El de la raíz no está
enrutado** y nadie lo instancia; el que se usa es el de `Informes/`.

No es urgente —código muerto no hace daño— pero sí es una trampa: quien arregle
un boletín final en el archivo equivocado no verá ningún efecto, y no tendrá
forma de saber por qué. Igual que pasó con `getUltimas`/`putUltimas`.

### Cuatro guardas de autorización que no se ejecutaban

Están en [06-autorizacion.md](06-autorizacion.md), porque son un agujero de
seguridad y no solo código muerto. Comparten la forma de esta lista: llevaban
años a la vista, el sistema respondía 200 y nadie miró.

---

## La lección para la Fase 4

Todos los casos de esta lista comparten forma: **algo dejó de funcionar, o nunca
funcionó, y el sistema siguió respondiendo lo bastante bien como para que nadie
mirara.** Unos se rompieron al cambiar de versión de PHP; otros —las guardas de
la sección 5— nacieron rotos y devolvían 200 con datos, que es la forma más
difícil de notar.

El salto de PHP 8.0 a 8.4 y de Laravel 8 a 13 va a producir más. Lo que los
detecta no es leer el código —estos llevaban años a la vista— sino golpear los
endpoints y comparar la respuesta. Es exactamente lo que hacen los tests de
contrato de la Fase 0, y por eso conviene terminarlos antes.
