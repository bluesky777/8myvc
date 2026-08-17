# Plan de seguridad — 8myvc

> Documento hermano de [00-plan-migracion.md](00-plan-migracion.md) y [02-plan-rendimiento.md](02-plan-rendimiento.md).
> **Sí es mucho, y sí merece su propio plan.** Pero tres de estos hallazgos no deberían esperar a la migración.

---

## Resumen

| Sev. | Hallazgo | ¿Espera a la migración? |
|---|---|---|
| 🔴 **Crítico** | Toma de cuenta de cualquier usuario vía reset de contraseña | **NO. Hoy.** |
| 🔴 **Crítico** | Escalada de privilegios sin autenticación (`roles`, `permissions`) | **NO. Hoy.** |
| 🔴 **Crítico** | Ejecución remota de código vía subida de archivos | **NO. Hoy.** |
| 🟠 Alto | Creación de usuarios sin autenticación, con contraseña `123456` | Semana 1 |
| 🟠 Alto | No existe middleware de autenticación; 35 controladores sin verificación | Fase 2 |
| 🟠 Alto | Autorización decidida en el cliente, no en el servidor | Fase 3–6 |
| 🟠 Alto | Enlace de reseteo construido con un dominio que envía el atacante | Semana 1 |
| 🟡 Medio | Token JWT escrito en texto plano en los logs | Hoy (1 línea) |
| 🟡 Medio | CORS abierto a `*` con todos los métodos y cabeceras | Semana 1 |
| 🟡 Medio | Tokens de reset generados con `rand()`, guardados en claro, sin limpiar | Semana 2 |
| 🟡 Medio | Inyección de cabeceras en `mail()` | Semana 2 |
| 🟡 Medio | Rate limit de 60/min aplicado también al login | Semana 1 |
| 🟡 Medio | `tymon/jwt-auth` fijado a `dev-develop` + `minimum-stability: dev` | Fase 3 |
| 🟢 Bajo | `'hash' => false` en el guard, `APP_DEBUG`, permisos 0777, sin validación | Continuo |

**Lo que NO encontré (buenas noticias):** de las 990 consultas crudas, **prácticamente todas están parametrizadas**. Solo hay 3 casos de concatenación con entrada de usuario y ninguno llega a SQL. **No hay inyección SQL clásica.** El junior hizo mal muchas cosas, pero esa la hizo bien.

---

## 🔴 CRÍTICO-1 · Toma de cuenta de cualquier usuario vía reset de contraseña

**Dónde:** [`app/Http/Controllers/LoginController.php:329-360`](../../app/Http/Controllers/LoginController.php#L329)

```php
public function putResetPassword(Request $request){
    $numero   = $request->input('numero');
    $pass1    = Hash::make($request->input('password1'));
    $username = $request->input('username');            // ← lo elige el atacante

    $consulta = 'SELECT * FROM password_reminders WHERE token=? and created_at > ?';
    $reminder = DB::select($consulta, [ $numero, $hora ]);

    if (count($reminder) > 0) {
        $reminder = $reminder[0];                        // ← se lee, y NO se usa jamás

        $consulta = 'UPDATE users SET password=? WHERE username = ?';
        DB::update($consulta, [ $pass1, $username ]);    // ← sin relación con el token
```

**El fallo:** el token se valida contra la tabla, se carga la fila… **y se descarta**. El `UPDATE` usa el `username` que viene del request. El token nunca se ata al usuario que lo pidió.

**Explotación completa, sin herramientas:**

1. El atacante pide un reset para **su propio correo**: `POST /api/password/ver-pass` con `{email: "atacante@gmail.com"}`.
2. Recibe en su correo un token válido (`numero`).
3. Llama `PUT /api/login/reset-password` con `{numero: <su token>, username: "<el superusuario>", password1: "loquesea"}`.
4. Ya es superusuario.

No requiere autenticación previa. Funciona con cualquier cuenta de las 2.318 en `users`. El endpoint es público.

**Arreglo (mínimo, se puede desplegar hoy):**

```php
$reminder = DB::select(
    'SELECT email FROM password_reminders WHERE token = ? AND created_at > ?',
    [$numero, $hora]
);

if (! $reminder) {
    return response()->json(['error' => 'Token inválido'], 400);
}

// El UPDATE se ata al EMAIL del token, no al username del request.
$afectados = DB::update(
    'UPDATE users SET password = ? WHERE email = ? AND deleted_at IS NULL AND is_active = 1',
    [$pass1, $reminder[0]->email]
);

DB::delete('DELETE FROM password_reminders WHERE token = ?', [$numero]);
```

**Ojo:** la lógica de `postVerPass` busca el correo en cuatro tablas (`users`, `alumnos`, `profesores`, `acudientes`), así que el arreglo definitivo debe resolver el `user_id` en ese momento y **guardarlo en la fila del reminder** (`ALTER TABLE password_reminders ADD COLUMN user_id INT`). Esa es la versión correcta; la de arriba es el parche de emergencia.

**Arreglo definitivo (Fase 3):** eliminar toda esta lógica y usar el `Password` broker nativo de Laravel, que ya hace token hasheado, expiración, atadura al usuario y un solo uso.

---

## 🔴 CRÍTICO-2 · Escalada de privilegios sin autenticación

**Dónde:** [`routes/api.php:66-67`](../../routes/api.php#L66) + [`app/Http/Controllers/RolesController.php`](../../app/Http/Controllers/RolesController.php) + [`PermissionsController.php`](../../app/Http/Controllers/PermissionsController.php)

```php
AdvancedRoute::controller('roles', 'RolesController');
AdvancedRoute::controller('permissions', 'PermissionsController');
```

Ninguno de los dos controladores llama a `User::fromToken()` **en ningún método**. Y el grupo de middleware `api` en [`app/Http/Kernel.php`](../../app/Http/Kernel.php) es solo:

```php
'api' => [
    'throttle:api',
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
],
```

**No hay `auth` en ninguna parte del proyecto.** Resultado: estas rutas son públicas.

```
PUT    /api/roles/addroletouser/{role_id}      ← asignarle cualquier rol a cualquier usuario
PUT    /api/roles/addpermission/{id}           ← añadir permisos a un rol
PUT    /api/roles/removeroletouser/{role_id}
POST   /api/permissions                        ← crear permisos
PUT    /api/permissions/update/{id}
DELETE /api/permissions/destroy/{id}           ← borrar permisos
```

Cualquiera en internet, sin token, puede asignarse el rol de administrador o borrar el sistema de permisos completo.

**Verificación (hazla tú mismo, en local, contra la copia de desarrollo):**

```bash
curl -i -X GET http://localhost/api/roles          # debería exigir token; hoy responde 200
```

**Arreglo inmediato:** añadir el middleware `auth` al grupo `api` y marcar explícitamente las excepciones públicas. Si eso es demasiado invasivo para un hotfix, un `User::fromToken()` + verificación de `is_superuser` al inicio de cada método de esos dos controladores es un parche de 20 líneas.

**Arreglo definitivo:** Fase 2 del plan de migración — `auth` por defecto, público por excepción.

---

## 🔴 CRÍTICO-3 · Ejecución remota de código vía subida de archivos

**Dónde:**
- [`app/Http/Controllers/Piars/Utils/UploadDocuments.php:19-30`](../../app/Http/Controllers/Piars/Utils/UploadDocuments.php#L19)
- [`app/Http/Controllers/Perfiles/ImagesController.php:185-196`](../../app/Http/Controllers/Perfiles/ImagesController.php#L185)

```php
$folder = 'uploads/'.$folderName;                       // → public/uploads/user_N
File::makeDirectory($folder, $mode = 0777, true, true); // world-writable

$file = Request::file("file");
$fullFileName = $file->getClientOriginalName();         // nombre elegido por el cliente
// ...
$file->move($folder, $fullFileName);                    // sin validar extensión ni MIME
```

**No hay validación de extensión, ni de tipo MIME, ni de tamaño.** El archivo aterriza en `public/uploads/`, que está bajo el document root y es servido directamente por el servidor web.

**Explotación:** subir `shell.php` y luego visitar `https://<host>/8myvc/public/uploads/user_5/shell.php`. Control total del servidor.

**Agravante:** el contenedor usa **nginx** (`kooldev/php:8.0-nginx`). El `public/.htaccess` es un archivo de Apache — **nginx lo ignora por completo**. Cualquier mitigación que creas tener vía `.htaccess` no existe en este entorno. Hay que verificar qué servidor corre en producción.

**Segundo agravante:** basta con ser un alumno autenticado. Los controladores de PIAR sí verifican token, pero cualquiera de los 2.318 usuarios sirve.

**Arreglo inmediato (3 capas, ponlas las tres):**

1. **Lista blanca de extensiones y validación de MIME real** (no el `Content-Type` que manda el cliente):
   ```php
   $request->validate([
       'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
   ]);
   ```
2. **Nombre generado por el servidor**, nunca el del cliente:
   ```php
   $nombre = Str::uuid().'.'.$file->extension();   // extension() usa el MIME real, no el nombre
   ```
3. **Sacar los archivos del document root.** Guardar en `storage/app/uploads/` y servirlos por una ruta autenticada que haga `Storage::download()`. Esto además arregla el control de acceso: hoy, si adivinas la URL, lees el diagnóstico médico de cualquier alumno de la carpeta PIAR — sin token.

**Parche de contención si el punto 3 tarda** (nginx):
```nginx
location ~* ^/uploads/.*\.(php|phtml|phar|php\d)$ { deny all; }
location ~* ^/images/.*\.(php|phtml|phar|php\d)$  { deny all; }
```

**Y revisa lo que ya está subido.** `public/uploads/` y `public/images/` llevan años recibiendo archivos sin filtro:
```bash
find public/uploads public/images -type f \( -name "*.php*" -o -name "*.phtml" -o -name "*.htaccess" \)
```

---

## 🟠 ALTO-4 · Creación de usuarios sin autenticación, con contraseña conocida

**Dónde:** [`app/Http/Controllers/LoginController.php:366-465`](../../app/Http/Controllers/LoginController.php#L366) — ruta `PUT /api/login/crear-prematricula`, pública.

```php
$usuario = new User;
$usuario->username     = $alumno->nombres . rand(99, 999);
$usuario->password     = Hash::make('123456');           // ← contraseña fija
$usuario->is_active    = true;
$usuario->save();

$role = Role::where('name', 'Alumno')->get();
$usuario->roles()->attach($role[0]['id']);
```

Sin token, cualquiera puede crear alumnos, matrículas y **usuarios activos con contraseña `123456`**. El `username` es predecible (`<nombres>` + 3 dígitos), así que el atacante conoce las credenciales de la cuenta que acaba de crear.

**Bug latente extra:** la línea 437 usa `$this->user->user_id`, pero `LoginController` **no tiene** `$this->user` (no hay constructor que lo asigne). Si ese `if` se cumple, es un error fatal. Es una rama que probablemente nunca se ha ejecutado.

**Arreglo:**
- Contraseña inicial aleatoria (`Str::password(16)`) enviada por correo, o forzar cambio en el primer login.
- Rate limit agresivo en el endpoint (5/hora por IP) y CAPTCHA si de verdad debe ser público.
- Arreglar el `$this->user` inexistente.

---

## 🟠 ALTO-5 · No existe middleware de autenticación

Ya cubierto en CRÍTICO-2, pero el alcance completo importa. **35 de 129 controladores nunca llaman a `User::fromToken()`.** De esos, los que están ruteados:

| Controlador | Ruta | ¿Público a propósito? |
|---|---|---|
| `RolesController` | `roles` | ❌ **No** — crítico |
| `PermissionsController` | `permissions` | ❌ **No** — crítico |
| `Alumnos/ImportarController` | `importar` | ❌ **No** — importa alumnos masivamente |
| `Alumnos/FoliosController` | `folios` | ❌ Revisar |
| `TipoDocumentoController` | `tiposdocumento` | ⚠️ Catálogo, probablemente sí |
| `EstadosCivilesController` | `estados_civiles` | ⚠️ Catálogo |
| `PaisesController` | `paises` | ⚠️ Catálogo |
| `ParentescosController` | `parentescos` | ⚠️ Catálogo |
| `NivelesEducativosController` | `niveles_educativos` | ⚠️ Catálogo |
| `DefinicionesComportamientoController` | `definiciones_comportamiento` | ❌ Revisar — 13.409 filas de datos de menores |
| `RemindersController` | `password` | ✅ Debe ser público |
| `Tardanzas/TLoginController` | `tardanzas/login` | ✅ Debe ser público |
| `Tardanzas/TSubirController` | `tardanzas/subir` | ❌ **Revisar** — sube datos sin token |

Los catálogos (`paises`, `parentescos`, `tiposdocumento`…) son de bajo riesgo aunque queden públicos. Los demás no.

**Método para no romper nada al cerrarlos** (repetido del plan de migración porque es importante): antes de aplicar `auth`, desplegar un middleware que **solo registre** las peticiones que llegan sin token, dejarlo una semana, y usar ese log como la lista real de rutas públicas.

---

## 🟠 ALTO-6 · La autorización vive en el cliente

`User::fromToken()` devuelve `$usuario->perms` y `$usuario->roles`, y el frontend decide con [`AuthService.isAuthorized()`](../../../myvc_front/app/scripts/services/AuthService.js#L216) qué se muestra.

En el backend, solo **33 de 129 controladores** mencionan `is_superuser` o `perms`. Los otros 96 ejecutan lo que se les pida al usuario que sea.

Esto significa que un alumno autenticado que sepa (o adivine) una URL puede llamar endpoints de profesor o de administración. Con 538 rutas de nombres predecibles (`alumnos/eliminar`, `notas/guardar`…), adivinar no es difícil.

**Arreglo (gradual, Fase 3–6):**
- Policies de Laravel + `authorize()` en cada endpoint que se toque.
- O, más rápido para este código: un middleware `can:<permiso>` aplicado a nivel de **grupo de rutas** en `routes/api/*.php`. Con las rutas ya organizadas por dominio (Fase 2), esto es una línea por grupo, no 538 ediciones.
- Reemplazar la tabla `permissions` artesanal por `spatie/laravel-permission` es tentador, pero **no lo haría**: hay 19 permisos, 11 roles y 2.346 filas en `role_user` funcionando. Migrar eso es riesgo sin ganancia. Conserva el modelo, añade la aplicación en servidor.

---

## 🟠 ALTO-7 · El enlace de reseteo lo construye el atacante

**Dónde:** [`app/Http/Controllers/LoginController.php:261`](../../app/Http/Controllers/LoginController.php#L261)

```php
$ruta = $request->input('ruta') . '#!/reset-password/'.$numero.'/'.$username;
```

El dominio del enlace viene del request. Un atacante llama a `POST /api/password/ver-pass` con `{email: "<víctima>", ruta: "https://sitio-falso.com/"}`, y **tu servidor envía a la víctima un correo legítimo con un enlace al sitio del atacante** — con el token de reseteo dentro de la URL.

**Arreglo:** construir la URL desde `config('app.frontend_url')`. Nunca desde el request. Si necesitas soportar varios colegios/dominios, usa una lista blanca en config.

---

## 🟡 MEDIO-8 · Token JWT en los logs

**Dónde:** [`app/Http/Controllers/LoginController.php:121`](../../app/Http/Controllers/LoginController.php#L121)

```php
Log::info($token);   // ← escribe el JWT completo en storage/logs/laravel.log
```

Cada login exitoso deja una credencial válida por 24 h en texto plano en disco. Cualquiera con acceso a los logs (o a un backup de logs, o a un servicio de agregación) puede suplantar a cualquier usuario.

**Arreglo:** borrar la línea. Es un `Log::info` de depuración que se quedó. Y barrer el resto:
```bash
grep -rn "Log::info\|Log::debug" app/ --include="*.php"
```

---

## 🟡 MEDIO-9 · CORS abierto

**Dónde:** [`config/cors.php`](../../config/cors.php)

```php
'paths'           => ['api/*'],
'allowed_methods' => ['*'],
'allowed_origins' => ['*'],
'allowed_headers' => ['*'],
```

Cualquier sitio web puede hacer peticiones a tu API desde el navegador de un usuario. Como el token va en `localStorage` y se envía en la cabecera `Authorization` (no en cookie), un `Origin: *` no es explotable por sí solo — pero es una defensa gratis que no estás usando, y se vuelve crítica si algún día pasas a autenticación por cookie.

**Arreglo:**
```php
'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '')),
'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],
```

---

## 🟡 MEDIO-10 · Tokens de reseteo débiles y persistentes

**Dónde:** [`app/Http/Controllers/LoginController.php:213`](../../app/Http/Controllers/LoginController.php#L213)

```php
$numero = rand(100000, 9999999999999999);
```

- `rand()` no es criptográficamente seguro. El generador Mersenne Twister de PHP es predecible con suficientes muestras.
- El token se guarda **en texto plano** en `password_reminders`.
- La tabla tiene **1.620 filas acumuladas** y nadie la limpia. Los tokens viejos siguen ahí (aunque el `WHERE created_at > $hora` los invalida en el uso, siguen expuestos en cualquier volcado de BD).
- La fila se inserta **antes** de comprobar si el correo existe, así que la tabla crece con basura.

**Arreglo:** usar el broker nativo de Laravel (`password_reset_tokens`, token hasheado, expiración configurable, un solo uso). Mientras tanto: `Str::random(64)` + `hash('sha256', ...)` al guardar + un `php artisan schedule` que limpie lo caducado.

---

## 🟡 MEDIO-11 · Inyección de cabeceras en `mail()`

**Dónde:** [`app/Http/Controllers/LoginController.php:319`](../../app/Http/Controllers/LoginController.php#L319)

```php
$destinatario = $request->input('email');   // sin validar
// ...
mail($destinatario, $asunto, $cuerpo, $headers);
```

Se usa la función `mail()` de PHP directamente, con un destinatario sin validar. Un valor con saltos de línea puede inyectar cabeceras (`Bcc:`, `Content-Type:`) y convertir tu servidor en relay de spam — y los correos saldrían con tu dominio en el `From`.

**Arreglo:** usar el `Mail` de Laravel (que ya está configurado, `MAIL_MAILER=smtp`) con un Mailable y una plantilla Blade. Ya de paso, el HTML de 60 líneas incrustado en el controlador sale de ahí. Y validar: `'email' => 'required|email'`.

---

## 🟡 MEDIO-12 · Rate limit uniforme, incluido el login

**Dónde:** [`app/Providers/RouteServiceProvider.php`](../../app/Providers/RouteServiceProvider.php)

```php
RateLimiter::for('api', fn ($request) => Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip()));
```

60 peticiones por minuto para todo, incluyendo `POST /api/login/credentials`. Son 86.400 intentos de contraseña al día por IP. Contra 2.318 cuentas con contraseñas de colegio (y al menos las de prematrícula con `123456`), eso se rompe.

**Arreglo:**
```php
RateLimiter::for('login', fn ($r) => [
    Limit::perMinute(5)->by($r->ip()),
    Limit::perMinute(5)->by($r->input('username')),
]);
RateLimiter::for('api', fn ($r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
```

Y bloqueo progresivo tras N fallos. La tabla `bitacoras` ya registra los intentos fallidos ([`LoginController.php:114`](../../app/Http/Controllers/LoginController.php#L114)) — solo falta actuar sobre ellos.

---

## 🟡 MEDIO-13 · Cadena de suministro

[`composer.json`](../../composer.json):

```json
"minimum-stability": "dev",
"tymon/jwt-auth": "^1.0.2"     →  instalado: dev-develop
```

`dev-develop` significa "lo que estuviera en la rama `develop` de GitHub el día que corriste `composer update`". No es una versión; es un puntero móvil sin firmar. Si esa cuenta de GitHub se compromete, tu próximo `composer update` instala lo que el atacante quiera. Y `minimum-stability: dev` abre esa puerta para **todas** las dependencias.

**Arreglo:** se resuelve solo en la Fase 3 al eliminar `tymon/jwt-auth`. Quitar también `"minimum-stability": "dev"`. Añadir `composer audit` al CI.

---

## 🟢 BAJO — pero arréglalos cuando pases por ahí

| Hallazgo | Dónde | Nota |
|---|---|---|
| `'hash' => false` en el guard `api` | [`config/auth.php`](../../config/auth.php) | Desactiva el hasheo del token del guard |
| `APP_DEBUG=true` | `.env` | Es `local`, correcto. **Verificar producción** — con debug on, un error filtra el `.env` entero |
| `File::makeDirectory($folder, 0777, ...)` | `UploadDocuments.php:15` | Debería ser 0755 |
| 2 validaciones en 32.477 líneas | todo el proyecto | Cada endpoint que toques, estrena FormRequest |
| Estado estático en `User` | `User::$nota_minima_aceptada`, `$images`, `$intentoLogueoPorActive` | Fuga entre peticiones si algún día usas Octane/Swoole |
| `password_reminders` sin purgar | 1.620 filas | Tarea programada |
| `debugging` con 9.553 filas | `Debugging::pin()` en `ChangeAskedController` | Depuración dejada activa en producción |
| Correos auto-generados `username@myvc.com` | `AlumnosController:418`, `ProfesoresController:194` | Colisiones y reseteos cruzados si dos usuarios comparten correo |

---

## Orden de ejecución sugerido

**Hoy (hotfix sobre `main`, sin esperar la migración):**
1. CRÍTICO-1 — atar el token de reseteo al usuario · ~10 líneas
2. CRÍTICO-2 — cerrar `roles` y `permissions` · ~20 líneas
3. CRÍTICO-3 — lista blanca de extensiones + nombre generado por el servidor · ~15 líneas
4. MEDIO-8 — borrar el `Log::info($token)` · 1 línea

Los cuatro caben en un solo PR pequeño y revisable. No dependen de la migración.

**Semana 1:** ALTO-4, ALTO-7, MEDIO-9, MEDIO-12
**Semana 2:** MEDIO-10, MEDIO-11 + auditoría de lo ya subido a `public/uploads`
**Fase 2 de la migración:** ALTO-5 (middleware `auth` global)
**Fase 3:** MEDIO-13 (eliminar jwt-auth), broker de contraseñas nativo
**Fase 3–6:** ALTO-6 (autorización en servidor), y los 🟢 según se toquen

---

## Una nota sobre el alcance

Este no es un pentest. Es lo que salió de leer el código durante el análisis de migración, y me detuve donde empezaba a repetirse el patrón. Un repaso dedicado casi seguro encuentra más — en particular en los 129 controladores que no leí completos, en IDOR (¿puede el alumno A pedir las notas del alumno B cambiando el `{id}` de la URL?), y en la app Flutter.

**IDOR es mi principal sospecha de lo no revisado.** Con 538 rutas, la mayoría tomando IDs por URL y sin verificación de propiedad en el servidor, la probabilidad de que un alumno pueda leer datos de otro es alta. Vale la pena una revisión específica de eso, aparte.
