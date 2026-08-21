# Plan de migración — 8myvc (Laravel 8 → Laravel 13)

> Rama: `chore/migracion-laravel-13`
> Documentos hermanos: [01-plan-seguridad.md](01-plan-seguridad.md) · [02-plan-rendimiento.md](02-plan-rendimiento.md) · [08-revision-idor.md](08-revision-idor.md)
> Evidencia generada: [rutas-actuales.csv](rutas-actuales.csv) (538 rutas) · [`tools/route-inventory.php`](../../tools/route-inventory.php)

---

## 0. Resumen ejecutivo

El proyecto **no es difícil de migrar por el framework**, es difícil por tres cosas concretas:

1. **No hay red de seguridad.** 0 tests reales, y `php artisan route:list` **está roto hoy** (los constructores llaman `User::fromToken()`, que hace `abort(401)` sin token). Sin poder ni listar rutas, "que no se dañe nada" no es verificable — es una esperanza. *(Al 18 ago 2026: hay 57 tests de contrato y `route:list` funciona.)*
2. **El esquema real de la BD no existe en el repo.** 90 tablas vivas contra 3 archivos de migración. La tabla `migrations` tiene 47 filas. La estructura solo existe en producción.
3. **La autenticación está hecha a mano y es opcional.** No hay middleware `auth` en ninguna ruta; cada controlador decide si autentica. 35 controladores nunca lo hacen — entre ellos `RolesController` y `PermissionsController`, que están ruteados y permiten **asignarse roles y permisos sin token**. *(Al 18 ago 2026: el guard va por defecto en toda la API, con quince excepciones enumeradas y testeadas.)*

La buena noticia: el código casi no usa superficie del framework. 990 llamadas a `DB::select/insert/update`, cero jobs, cero events, cero listeners, cero broadcasting, 2 usos de validación. **Los breaking changes de Laravel 9→13 casi no lo tocan.** El riesgo real está en los paquetes, no en el framework.

**Orden recomendado (no negociable en su secuencia):**

| # | Fase | Qué desbloquea | Esfuerzo |
|---|---|---|---|
| 0 | Red de seguridad (tests de contrato + baseline de BD + CI) | Todo lo demás | 4–6 días · **completa: P0 y P1 el 19 ago 2026, P2 el 20** |
| 1 | Eliminar `AdvancedRoute` (sin tocar el framework) | Rutas cacheables, `route:list` funcional | 1–2 días |
| 2 | Organizar rutas + middleware `auth` real | Cierra el agujero de roles/permisos | 2–3 días |
| 3 | Reemplazar `tymon/jwt-auth` (back + front + Flutter) | Desbloquea el salto de framework | 4–6 días · **backend hecho 19 ago 2026** |
| 4 | Salto 8 → 13 (~~cinco majors~~ tres saltos) | El objetivo | **hecho 19 ago 2026** |
| 5 | ~~Migraciones al día contra la BD real~~ | ~~Entornos reproducibles~~ — **recortada**: un colegio nuevo se crea copiando la base de otro | — |
| 6 | Modelos y limpieza (gradual, opcional) | Mantenibilidad | herramientas y barrido **hechos 19 ago 2026** · el resto, continuo |

Total realista para las fases 0–5: **~4 semanas de trabajo enfocado.**

---

## 1. Radiografía (todo medido, nada asumido)

| Cosa | Dato |
|---|---|
| Framework | `laravel/framework` v8.83.29 (soporte de seguridad terminado en enero 2023) |
| PHP | `kooldev/php:8.0-nginx` en Docker · PHP 8.2.28 en el CLI local |
| Archivos PHP en `app/` | 197 · **32.477 LOC** |
| Controladores | 129 (el más grande: `ChangeAskedController` con 1.087 líneas) |
| Rutas | **96** `AdvancedRoute::controller()` → **538 rutas** generadas implícitamente |
| Archivos de rutas | 1 (`routes/api.php`, 137 líneas) |
| Llamadas SQL crudas | **990** (`DB::select` / `insert` / `update` / `delete` / `statement`) |
| Modelos Eloquent | 47 definidos, usados marginalmente |
| Llamadas a `User::fromToken()` | **336** |
| Validación (`validate()` / `Validator::make`) | **2** en todo el proyecto |
| FormRequests | 1 |
| Tests reales | **0** (solo los 2 stubs de `laravel new`) |
| Migraciones | **3 archivos** vs **90 tablas** vivas vs **47 filas** en `migrations` |
| Tablas más grandes | `notas` 1.163.307 · `notas_finales` 127.810 · `ausencias` 52.118 · `subunidades` 37.197 |
| Jobs / Events / Listeners | ninguno · `QUEUE_CONNECTION=sync` |
| `php artisan route:list` | **falla** (`No existe Token`, `app/User.php:85`) — *arreglado el 18 ago 2026* |

### Los tres hallazgos que definen el plan

**A. `AdvancedRoute` registra cada ruta DOS veces.**
En [`AdvancedRoute.php:73`](../../vendor/lesichkovm/laravel-advanced-route/src/AdvancedRoute.php#L73) hace `Route::$httpMethod(...)` y en la línea 79 la vuelve a registrar solo para ponerle nombre. La tabla de rutas real tiene **~1.076 entradas en vez de 538**. Es puro peso muerto en cada arranque.

**B. La sustitución de `AdvancedRoute` es 100% mecánica y verificable.**
Ya generé el inventario completo por reflexión: **538 rutas, 0 colisiones de verbo+URI, 0 controladores irresolubles, 0 métodos heredados de clase padre**. Eso significa que el reemplazo se puede generar por script y comparar 1:1 contra el original. Riesgo ≈ 0 si se hace en un paso aislado.

**C. Los constructores autentican, y eso rompe el framework.**
```php
// app/Http/Controllers/AlumnosController.php:36
public function __construct()
{
    $this->user = User::fromToken();   // abort(401) si no hay token
}
```
Laravel instancia el controlador para leer su middleware. Por eso `route:list` explota, por eso `route:cache` no se puede usar, y por eso cualquier comando de artisan que toque rutas falla. **Esto se arregla en la Fase 2 y es prerrequisito del caché de rutas.**

> **Arreglado el 18 ago 2026.** `$this->user` lo resuelve ahora la primera
> lectura, con el trait `Concerns\ResuelveElUsuario`. `route:list` imprime las
> 533 rutas y `route:cache` funciona.

---

## 2. Decisiones de arquitectura

### 2.1 Framework objetivo: **Laravel 13 + PHP 8.4**

Verificado contra Packagist y la política de versiones oficial el 2026-08-17:

| Versión | Publicada | Bug fixes hasta | Seguridad hasta | PHP |
|---|---|---|---|---|
| Laravel 8 (**actual**) | 2020-09-08 | — | **terminado en enero 2023** | 7.3–8.1 |
| Laravel 11 | 2024-03-12 | 2025-09-03 | **terminado 2026-03-12** | 8.2–8.4 |
| Laravel 12 | 2025-02-24 | **2026-08-13 ← hace 4 días** | 2027-02-24 | 8.2–8.5 |
| **Laravel 13** | 2026-03-17 | **Q3 2027** | **2028-03-17** | **8.3–8.5** |

Última estable: **`laravel/framework` v13.25.0** (2026-08-11).

**Laravel 12 dejó de recibir correcciones de bugs el 13 de agosto de 2026** — cuatro días antes de escribir esto. Migrar a 12 sería aterrizar en una versión que ya solo recibe parches de seguridad. **El objetivo es 13.**

**PHP 8.4**, no 8.3. Verificado en endoflife.date:

| PHP | Soporte activo hasta | Seguridad hasta |
|---|---|---|
| 8.3 | **terminado 2025-12-31** | 2027-12-31 |
| **8.4** | **2026-12-31** | **2028-12-31** |
| 8.5 | 2027-12-31 | 2029-12-31 |

PHP 8.3 ya está en modo solo-seguridad. **8.4** es la versión con soporte activo y la que tienes instalada por Homebrew (8.4.6). PHP 8.5 es opción si quieres estirar el horizonte, pero 8.4 tiene mejor cobertura de paquetes hoy.

**El camino son cinco majors: 8 → 9 → 10 → 11 → 12 → 13.** Suena peor de lo que es: como el código apenas usa superficie del framework, la mayoría de los saltos son solo `composer.json`.

### 2.2 Estrategia: actualización in-place, un major a la vez

Descarté "esqueleto nuevo + copiar `app/`" aunque sea tentador (la config es 99% stock). Razón: el reestructurado del esqueleto de Laravel 11 (`bootstrap/app.php`, sin `Http/Kernel`, sin `Console/Kernel`) mezcla dos tipos de cambio en un solo commit, y sin tests no se puede saber cuál rompió qué.

**In-place, un major por commit, con la suite de contrato verde en cada salto.** Cada salto es principalmente `composer.json` + retoques mínimos, porque el código apenas usa el framework.

El reestructurado a esqueleto slim (L11) se hace como **su propio commit**, después de llegar a L11 funcionando con el esqueleto viejo (Laravel 11 sigue arrancando con `Http/Kernel.php`; el esqueleto nuevo es opcional).

### 2.3 Reemplazo de `AdvancedRoute`: rutas explícitas generadas, sin renombrar métodos

Tres opciones evaluadas:

| Opción | Veredicto |
|---|---|
| Reimplementar `AdvancedRoute` dentro del repo | ❌ El objetivo es quitarlo, no mudarlo |
| Generar 538 `Route::get(...)` explícitas **conservando** los nombres de método (`getIndex`, `putGuardarValor`) | ✅ **Elegida.** Diff verificable línea a línea, cero cambio de comportamiento |
| Generar rutas explícitas **y** renombrar métodos a `index`/`store`/`update` | ❌ para la Fase 1. Mezcla el cambio mecánico con uno semántico de 538 puntos |

El renombrado de métodos (`getIndex` → `index`) es deseable pero va **después**, por dominio, controlador por controlador, cuando ya haya tests. Es cosmético; el sistema funciona igual.

Además hay que convertir la sintaxis de acción en string, que deja de existir en Laravel 9:

```php
// antes (rompe en L9+ al quitarse el $namespace del RouteServiceProvider)
Route::resource('tiposdocumento', 'TipoDocumentoController');

// después
Route::resource('tiposdocumento', TipoDocumentoController::class);
```

### 2.4 Autenticación: Sanctum con tokens Bearer

`tymon/jwt-auth` está instalado como **`dev-develop`** (con `minimum-stability: dev` en el `composer.json`) y solo declara soporte hasta Laravel 9. **Es el bloqueante duro del salto de framework.**

| Opción | Versión verificada | Pro | Contra |
|---|---|---|---|
| `php-open-source-saver/jwt-auth` (fork mantenido) | **2.9.2** (2026-05-07), soporta `illuminate ^12\|^13` | Cambio mínimo — es un reemplazo casi directo de `tymon`. Sigue siendo JWT stateless (0 consultas para validar) | El logout real exige blacklist en caché (Redis). Arrastra el diseño actual. Refresh manual |
| **Laravel Sanctum, tokens personales** | **4.3.3** (2026-06-23), soporta `illuminate ^11\|^12\|^13` | First-party, **logout real** (`$token->delete()`), `expires_at` nativo, un solo `SELECT` indexado por request | Invalida las sesiones vivas una vez (todos re-loguean) |
| Sanctum SPA con cookie httpOnly | idem | Lo más seguro (inmune a robo por XSS) | La app Flutter (`myvc_flutter`) y la app móvil no encajan bien con cookies |

> El fork de JWT **sí** es una opción viable hoy (no lo era con `tymon`, que se quedó en Laravel 9). Si el objetivo fuera solo desbloquear el salto de framework con el mínimo cambio posible, sería la respuesta correcta: `composer remove tymon/jwt-auth && composer require php-open-source-saver/jwt-auth` y poco más.
>
> Elijo Sanctum igual porque **pediste dos cosas que el fork no te da de regalo**: logout que de verdad invalide el token, y refresh. Con el fork tendrías que construir la blacklist y la rotación a mano. Con Sanctum vienen puestas.

**Elegida: Sanctum con tokens Bearer**, un solo mecanismo para web, móvil y `Tardanzas`. Resuelve exactamente lo que pediste:

- **Logout que sí funciona:** hoy `LoginController::putLogout` solo hace un `UPDATE historiales SET logout_at`. El token JWT sigue siendo válido 24 horas después de "cerrar sesión". Con Sanctum, se borra la fila y el token muere en el acto.
- **Refresh:** hoy no existe. El token dura 24 h (`JWT_TTL=1440`) y al expirar el usuario simplemente sale expulsado. Con Sanctum: access token corto (30–60 min) + refresh token largo con rotación.

**Endpoints nuevos:**

```
POST   /api/auth/login      → { access_token, refresh_token, expires_in, user }
POST   /api/auth/refresh    → rota el refresh token, emite access token nuevo
POST   /api/auth/logout     → borra el token actual
POST   /api/auth/logout-all → borra todos los tokens del usuario
GET    /api/auth/me         → el contexto de usuario (lo que hoy devuelve POST /api/login)
```

**Compatibilidad durante la transición — clave para no romper nada:**

`User::fromToken()` se llama en 336 sitios. No se tocan los 336. Se convierte en un *shim* que delega en el contexto ya resuelto por el middleware:

```php
// app/User.php — durante la transición
public static function fromToken($already_parsed = false, $request = false)
{
    return app(AuthenticatedUserContext::class)->get();   // ya resuelto, cacheado, 0 queries extra
}
```

Los 336 call sites siguen compilando y devolviendo el mismo objeto. Se van eliminando después, sin prisa. **Esto convierte el cambio de auth de "336 ediciones" a "1 edición".**

### 2.5 Rutas: por dominio, en carpeta

`routes/api.php` (137 líneas) se parte siguiendo las carpetas que ya existen en `app/Http/Controllers/`:

```
routes/
├── api.php                 # solo requires + grupos de middleware
└── api/
    ├── auth.php            # público: login, refresh, password reset
    ├── academico.php       # notas, asignaturas, areas, materias, unidades, subunidades, escalas
    ├── alumnos.php         # alumnos, acudientes, folios, importar, matriculas, prematriculas
    ├── informes.php        # 18 controladores de Informes/ + boletines + certificados
    ├── disciplina.php      # comportamiento, ordinales, disciplina, ausencias
    ├── piars.php           # 5 controladores de Piars/
    ├── perfiles.php        # perfiles, imágenes, publicaciones, calendario
    ├── votaciones.php      # vt_*
    ├── actividades.php     # actividades, preguntas, opciones, respuestas
    ├── tardanzas.php       # tardanzas/login, tardanzas/subir, asistencias
    ├── movil.php           # AppMobile/*
    └── admin.php           # roles, permissions, users, years, periodos, config
```

Con la estructura de grupos:

```php
// routes/api.php
Route::middleware('guest.api')->group(base_path('routes/api/auth.php'));

Route::middleware(['auth:sanctum', 'user.context'])->group(function () {
    foreach (glob(base_path('routes/api/*.php')) as $file) {
        if (! str_ends_with($file, 'auth.php')) require $file;
    }
});
```

**El default pasa a ser "autenticado".** Lo público es la excepción explícita y se lee en un solo archivo. Ese solo cambio cierra el agujero de `roles` y `permissions`.

---

## 3. Fases en detalle

### Fase 0 — Red de seguridad · 4–6 días · **BLOQUEANTE**

Nada de lo demás se toca antes de esto. Es lo que convierte "espero que no se rompa" en "sé que no se rompió".

> **Estado (20 ago 2026): la Fase 0 está completa.** Hecho: 0.1 baseline del
> esquema, 0.3 entorno reproducible, 0.4 CI, y **las tres prioridades de 0.2**:
> login (6), enrutado, notas, Excel, imágenes, boletines, observador, acta de
> evaluación, matrículas y grupos en el P0 y el P1; el muestreo del resto de la
> API en el P2. **389 tests de contrato.** Cómo se usa todo esto:
> [03-tests.md](03-tests.md).
>
> **El P2 (20 ago 2026)** cierra la pregunta que quedaba abierta, que era cuánto
> falta por cubrir. Ahora se mide —`tools/cobertura-de-rutas.py`— en vez de
> estimarse: **de 96 rutas con la respuesta comprobada a 208, y de 35
> controladores a 90 de 97**. Los siete que faltan no tienen ninguna lectura que
> mirar: son escrituras, o los lectores de tardanzas.
>
> Trajo además el seed de **dos años**, que era lo que faltaba para que se
> ejecutaran las consultas del grado anterior, y **cuatro endpoints que fallan
> siempre** —tres son SQL contra columnas que no existen, y larastan había pasado
> por esos ficheros en la Fase 6 sin ver ninguna—. Están en
> [05-codigo-muerto-y-roto.md §8](05-codigo-muerto-y-roto.md).
>
> **El P1 encontró cuatro cosas más, y las cuatro quedaron arregladas** (19 ago
> 2026). Al contrario que en el P0, aquí no se dejó nada abierto:
>
> - **`PUT api/matriculas/prematricular` no miraba de quién era el `alumno_id`.**
>   La ruta está abierta a Alumno y Acudiente a propósito —la prematrícula del año
>   siguiente la hace la familia desde su cuenta— pero el id llega en el cuerpo:
>   con token de alumno se le cambiaba el estado y el grupo a cualquier compañero.
>   Era el IDOR de notas del P0, de ESCRITURA. Cerrado con
>   `boletin.propio:sin-paz-y-salvo`, el middleware que ya hacía esta misma
>   comprobación para los boletines. **Joseth confirmó la regla: un acudiente solo
>   puede prematricular a sus acudidos.** Sin paz y salvo: retener el boletín de
>   quien debe es una cosa e impedirle matricularse el año siguiente es otra.
> - **El acumulado del año de los boletines salía entero en ceros** si la URL no
>   traía `de_usuario` o `todos`. `Periodo::hastaPeriodoN` toma un número y
>   `Periodo::hastaPeriodo` una cadena, y el default `10` de la primera acababa en
>   la segunda; ninguna rama del `if` casaba, y el TypeError del `count()` sobre el
>   `stdClass` inicial lo absorbía un `try/catch`. 200, informe en blanco, sin log.
>   El default pasa a `de_usuario` en los tres controladores.
> - **`GET api/grupos/listado/{grupo}` nunca devolvía la dirección**: la consulta
>   usaba `+` en vez de `CONCAT`, y en MySQL eso es una suma. Salía `0`, o `null` si
>   faltaba el barrio. Arreglado con `CONCAT_WS` —no `CONCAT`, que se traga la
>   dirección entera si falta el barrio.
> - **La tabla `llevo_formulario` no existía** —ni en el volcado de producción ni
>   en desarrollo— y `PUT api/prematriculas/llevo-formulario` empieza borrando de
>   ella: 500 seguro desde siempre, como `failed_jobs`.
>
>   **La ruta se borró en vez de crearle la tabla.** Joseth confirmó que quién
>   llevó el formulario es `matriculas.estado = 'FORM'`: lo pone
>   `AlumnosController` al crear el alumno, lo pone y lo cambia
>   `matriculas/prematricular` con `estado=FORM` —que es por donde lo mueve el
>   administrador— y lo lee `AlumnosFormularios`. Había dos mecanismos para el
>   mismo dato, uno vivo y otro que nunca llegó a escribir una fila. De paso, el
>   INSERT muerto pasaba los cinco valores corridos: a la columna
>   `llevo_formulario` le habría llegado una fecha aunque la tabla existiera.

**0.1 Baseline del esquema real de la BD**

La BD viva es la única fuente de verdad. Congélala en el repo:

```bash
# Estructura sin datos, desde la BD real
docker exec 8myvc-database-1 mysqldump -uroot -p"$DB_PASSWORD" \
  --no-data --routines --skip-add-drop-table --skip-comments \
  simonbolivar > database/schema/mysql-schema.sql
```

Laravel carga `database/schema/mysql-schema.sql` automáticamente en `migrate` cuando existe (squashed schema). A partir de ahí:

- Las 3 migraciones actuales se archivan en `database/migrations/legacy/` (no se ejecutan).
- Toda tabla nueva o cambio de columna, de aquí en adelante, va en una migración normal.
- El desfase (47 filas vs 3 archivos) deja de importar: la baseline reemplaza la historia.

Complemento: instalar `kitloong/laravel-migrations-generator` para producir migraciones legibles por tabla y guardarlas como **documentación** en `docs/db/` (no para ejecutarlas). Sirve para entender los 90 esquemas sin abrir MySQL.

**0.2 Tests de contrato (lo más importante del plan)**

No tests unitarios. **Tests de caracterización**: golpean los endpoints reales contra una BD sembrada y guardan snapshots de la respuesta JSON. Su único trabajo es gritar cuando una respuesta cambia.

Cobertura mínima, priorizada por lo que más duele si se rompe:

| Prioridad | Qué | Endpoints aprox. |
|---|---|---|
| P0 | Login, `/api/login` (contexto de usuario) por cada `tipo` (Alumno / Profesor / Acudiente / Usuario) | 6 |
| P0 | Notas: listar, guardar, definitivas por periodo | ~15 |
| P0 | **Excel**: importar alumnos, exportar acudientes, deudores, SIMAT, listado docentes | 7 |
| P0 | **Imágenes**: subir, recortar, rotar, foto de perfil | 6 |
| P1 | Boletines / bolfinales / observador / actas (los Blade que generan PDF-HTML) | ~12 · **hecho, 35 tests** |
| P1 | Matrículas, prematrículas, grupos, promovidos | ~15 · **hecho, 31 tests** |
| P2 | El resto, por muestreo (~~1 GET~~ **1 LECTURA** por controlador) | ~100 · **hecho, 78 tests** |

> **«1 GET por controlador» estaba mal escrito, y se vio al hacerlo.** Solo 62 de
> los 97 controladores tienen algún GET: en este proyecto se lee con `PUT` y el
> filtro va en el cuerpo, así que el criterio del verbo dejaba fuera a veinte
> controladores enteros. Lo que se muestrea es una lectura, venga por donde venga.

Para los binarios (Excel, imágenes) el snapshot no es del byte-stream: es **hash del contenido normalizado** (para XLSX: número de hojas + headers + N filas + checksum de celdas; para imágenes: dimensiones + tipo MIME + tamaño ±5%). Comparar bytes crudos daría falsos positivos por metadatos de fecha.

**0.3 Entorno reproducible**

- Fijar un dump de datos anonimizado y pequeño para los tests (`database/dumps/test-seed.sql`), no la BD de producción.
- `phpunit.xml` con conexión `mysql_testing` apuntando a una BD separada.

**0.4 CI mínimo (GitHub Actions)**

```yaml
# .github/workflows/ci.yml
- composer install
- php artisan route:list          # debe funcionar → detecta regresiones de rutas
- php tools/route-inventory.php   # el conteo debe seguir dando 538 hasta la Fase 1
- php artisan test
- ./vendor/bin/pint --test
- ./vendor/bin/phpstan analyse --level=0
```

PHPStan en **nivel 0** al principio. Con 990 queries crudas y `stdClass` volando por todos lados, nivel 5 daría 4.000 errores y nadie lo miraría. Nivel 0 atrapa lo que importa hoy: métodos y clases inexistentes.

---

### Fase 1 — Quitar `AdvancedRoute` · 1–2 días

**Todavía en Laravel 8.** Un cambio, aislado, verificable.

1. Ejecutar `php tools/route-inventory.php docs/migracion/rutas-actuales.csv` → ya hecho, **538 rutas**.
2. Extender el script para que emita PHP en vez de CSV (`--emit`), agrupado por controlador y respetando el orden de AdvancedRoute (rutas sin `{param}` primero — es lo que evita que `puestos/{id}` tape a `puestos/detailed`).
3. Reemplazar el contenido de `routes/api.php` por la salida generada.
4. **Verificar:** volver a correr el inventario contra la tabla real de Laravel y hacer `diff`. Verbo + URI + acción deben coincidir en las 538. Ahora `route:list` sirve para esto (una vez arreglada la Fase 2; antes, comparar contra el CSV).
5. `composer remove lesichkovm/laravel-advanced-route`.

**Nombres de ruta:** AdvancedRoute generaba nombres tipo `alumnos.get.index`. Hay que revisar si algo los usa (`route('...')`) antes de descartarlos:

```bash
grep -rn "route(" app/ resources/ --include="*.php" | grep -v "Route::"
```

Si no se usan (muy probable — es una API pura consumida por AngularJS con URLs literales), se omiten.

**Bonus gratis:** desaparece el doble registro de rutas del punto 1.A. La tabla pasa de ~1.076 a 538 entradas.

---

### Fase 2 — Rutas organizadas + middleware de auth real · 2–3 días

> **Estado: terminada el 18 ago 2026.** Los cinco puntos están hechos, con dos
> diferencias respecto a lo que dice abajo:
>
> - El punto 2 no acabó siendo un middleware `EnsureUserContext` que mete al
>   usuario en el contenedor, sino el trait `Concerns\ResuelveElUsuario`, que lo
>   resuelve en la primera lectura de `$this->user`, más una memoria en la propia
>   petición dentro de `User::fromToken()`. Sale igual de barato y no obliga a
>   tocar los ~300 sitios que leen `$this->user`.
> - El punto 4 se hizo al revés de como está escrito: en vez de aplicar el guard
>   ruta por ruta y dejar públicas las demás, va **por defecto a toda la API** y
>   las excepciones se marcan una a una. Son quince, y son un test.
>
> El aviso del riesgo de abajo —"correr una semana con un middleware que solo
> registra"— se descartó por lo que explica
> [04-auditoria-autenticacion.md](04-auditoria-autenticacion.md): hay semanas en
> que los colegios no usan el sistema, así que la ausencia de registros no
> distingue "nadie llama a esta ruta" de "nadie entró esa semana". La lista real
> la dio la sesión de `myvc_front` leyendo el frontend.
>
> Salió además una auditoría que el plan no preveía: cuatro guards de
> autorización escritos que no se ejecutaban nunca. Está en
> [06-autorizacion.md](06-autorizacion.md), con el paso siguiente sobre roles y
> permisos.

1. Partir el archivo generado en `routes/api/*.php` según §2.5.
2. Crear el middleware `EnsureUserContext` que resuelve el usuario **una vez por request** y lo mete en el contenedor.
3. **Sacar `User::fromToken()` de los constructores.** Los 33 constructores pasan de:
   ```php
   public function __construct() { $this->user = User::fromToken(); }
   ```
   a que `$this->user` se resuelva perezosamente desde el contexto. Esto es lo que arregla `route:list` y habilita `route:cache`.
4. Aplicar `auth` a todo por defecto; mover a un grupo público solo: `login/*`, `password/*`, `tardanzas/login`, y lo que el inventario demuestre que se consume sin token.
5. **Auditar los 35 controladores sin autenticación** (lista completa en el [plan de seguridad](01-plan-seguridad.md)) y decidir uno por uno: ¿público a propósito, o agujero?

> ⚠️ Riesgo real: si algún endpoint hoy se consume sin token desde el front y le pones `auth`, se rompe. Mitigación: antes de aplicar el middleware, correr una semana con un middleware que **solo registra** (`Log::info`) qué rutas llegan sin token. Eso da la lista exacta de lo que de verdad es público.

---

### Fase 3 — Reemplazo de autenticación · 4–6 días

> **El backend está hecho (19 ago 2026).** Lo que se hizo, el contrato con los
> clientes y las decisiones que se tomaron por el camino están en
> [07-sesion.md](07-sesion.md). Cambió una cosa respecto a lo planeado aquí:
> el paquete es **Sanctum 2.15.1**, no 4.3.3, porque 4.x pide `illuminate ^11` y
> aquí todavía corre Laravel 8. Sube a 4.x con la Fase 4, y la migración de este
> repo ya trae la columna `expires_at` que 4.x añade de serie.
>
> Lo demás salió como estaba escrito, incluido lo importante: `User::fromToken()`
> se convirtió en un shim y **no se tocó ninguno de los 325 call sites**.

**Backend (2–3 días)** — hecho

1. `composer require laravel/sanctum`, publicar migración de `personal_access_tokens`.
2. `config/auth.php`: guard `api` de `jwt` a `sanctum`. Quitar `'hash' => false`.
3. Nuevo `App\Http\Controllers\Auth\AuthController` con los 5 endpoints de §2.4.
4. `App\Services\AuthenticatedUserContext`: encapsula la consulta monstruo de `User::fromToken()` (el `switch` de 4 ramas con joins de 40 columnas) y la cachea por `user_id + periodo_id`.
5. `User::fromToken()` → shim que delega (§2.4). **Los 336 call sites no se tocan.**
6. Mantener `LoginController::postCredentials` y `postIndex` como alias de los nuevos, devolviendo la **misma forma de respuesta** (`{ el_token, cambia_anio }`), para poder desplegar el back antes que el front.
7. `composer remove tymon/jwt-auth`, quitar `"minimum-stability": "dev"` del `composer.json`.

**Frontend `myvc_front` (2 días)**

Archivos a tocar (AngularJS 1.8, ya migrado a Vite — el `MIGRATION.md` de ese repo está muy bien hecho):

| Archivo | Cambio |
|---|---|
| [`app/scripts/services/AuthService.js`](../../../myvc_front/app/scripts/services/AuthService.js) | `login_credentials` guarda ambos tokens; `logout` llama `POST ::auth/logout` y **espera la respuesta** antes de limpiar (hoy es fire-and-forget y por eso "no funciona"); `login_from_token` → `GET ::auth/me` |
| Nuevo `app/scripts/services/TokenInterceptor.js` | Interceptor `$http`: en `401`, intenta `POST ::auth/refresh` una sola vez, reencola la request original, y si falla manda a `login`. Hoy no existe nada de esto |
| [`app/scripts/main/Configuracion.js:14-19`](../../../myvc_front/app/scripts/main/Configuracion.js#L14) | Quitar la config CSRF heredada de Django (`csrftoken` / `X-CSRFToken`) — no la usa nadie en un backend Laravel con Bearer |
| `app/scripts/login/LogoutCtrl.js` | Que espere la promesa del logout |

**Detalle del bug de logout que describiste**, para que quede escrito:

```js
// AuthService.js:199 — hoy
authService.logout = function(){
  const login = $http.put('::login/logout', {user_id: ...}).then(...);  // ← no se espera
  Perfil.deleteUser();
  authService.borrarToken();          // ← borra el token del navegador
  return $state.transitionTo('login');
};
```
El backend solo escribe `logout_at` en `historiales`. El JWT **sigue siendo válido 24 horas**. Si alguien copió ese token (o quedó en el `localStorage` de un equipo compartido), sigue entrando. Cerrar sesión hoy es cosmético.

**App Flutter / móvil (1 día, verificar)**

`myvc_flutter` existe al lado, y en el back hay `AppMobile/LoginAppController` y `Tardanzas/TLoginController`. Hay que inventariar cómo autentican antes de tocar nada, y desplegar el back con ambos mecanismos activos durante una ventana de transición.

---

### Fase 4 — Salto de framework 8 → 13 · 4–6 días

> **Hecha el 19 ago 2026, y no como estaba escrita aquí.** El plan decía un major
> por commit, 8→9→10→11→12→13. Fueron **dos saltos**: 8→9 y 9→13.
>
> **Laravel 10 y 11 no se pueden instalar.** Composer los bloquea: todas sus
> versiones arrastran avisos de seguridad sin parchear —entre ellos
> CVE-2026-48019, inyección CRLF en la regla de validación `email`, de severidad
> alta— porque las dos ramas salieron de soporte antes de que llegara la
> corrección. Solo `>=12.60.0` y `>=13.10.0` están limpias. Saltárselas no fue
> una elección de ritmo: no había forma de pasar por ellas sin apagar la
> comprobación de seguridad de composer y dejarlo escrito en el repo.
>
> Se pierde con ello la atribución fina de "cuál de los cinco rompió qué", que era
> lo que el plan buscaba. El detector siguió siendo el mismo, la suite, y lo que
> rompió fueron **dos cosas**, las dos localizadas y arregladas en commits
> separados: 46 `DB::raw()` que envolvían consultas enteras (Laravel 10 dejó de
> convertir `Expression` a cadena) y los proveedores de datos de PHPUnit, que
> desde la 10 tienen que ser `static`.
>
> Lo que el plan sí acertó de lleno: **el código apenas usa superficie del
> framework, así que apenas le afectan sus cambios.** El esqueleto viejo
> (`Http/Kernel`, `Console/Kernel`, los `config/*.php`) arranca sin tocar nada en
> Laravel 13.
>
> Los comandos están en [DESPLIEGUE.md](../DESPLIEGUE.md) y el porqué en
> [DESPLIEGUE-REFERENCIA.md](../DESPLIEGUE-REFERENCIA.md). En corto: **subir PHP
> a 8.4 antes, y eso sube para todos los colegios de la cuenta de cPanel a la
> vez.**

Un major por commit (8→9→10→11→12→13), con la suite de contrato verde en cada uno.

> **⚠️ Bloqueante de despliegue, no de código: `vendor/` está compartido entre
> colegios por symlink.** Hay una carpeta real y los demás colegios la apuntan
> (Joseth, 18 ago 2026; ver [`docs/DESPLIEGUE-REFERENCIA.md`](../DESPLIEGUE-REFERENCIA.md)). `app/` sí
> es copia real en cada uno.
>
> O sea que **subir el framework lo sube para todos los colegios en el mismo
> instante**, mientras que el `app/` adaptado llega colegio a colegio. Esta fase
> no se puede escalonar como las demás: o se rompen los colegios que aún no
> tienen el código nuevo, o hay que desplegar todos a la vez y sin marcha atrás
> por colegio.
>
> **Antes de empezar la Fase 4 hay que dejar de compartir `vendor/`**: una
> carpeta real por colegio. Con eso el salto se despliega como todo lo demás —
> uno por uno, y se puede volver atrás en el que falle. Sin eso, esta fase es un
> big-bang de los tres clientes a la vez, que es justo lo que el plan evita en
> todas las demás.
>
> Falta confirmar si hay algo más compartido (`storage/`, `bootstrap/cache/`).
> `bootstrap/cache/` importa desde la Fase 2: es donde caen `route:cache` y
> `config:cache`, y compartida serviría las rutas de un colegio en otro.

**Tabla de dependencias — versiones y restricciones consultadas en Packagist el 2026-08-17:**

| Paquete | Hoy | Última estable | ¿Soporta L13? | Acción | Riesgo |
|---|---|---|---|---|---|
| `maatwebsite/excel` | 3.1.64 | 3.1.70 · **4.0.0** (2026-08-13) | ✅ **3.1.70 declara `^13.0`** | **Subir dentro de 3.1.x.** Nada más | 🟢 nulo |
| `phpoffice/phpspreadsheet` | 1.29.10 | 5.9.0 | (indirecta) | **Dejarla en 1.30.x.** Excel 3.1.70 la pinea ahí | 🟢 nulo |
| `laravel/tinker` | 2.x | 3.0.2 | ✅ `^13.0` | Subir | 🟢 trivial |
| `intervention/image` | 2.7.2 (**2022**) | 4.2.1 + `intervention/image-laravel` 4.1.1 | ⚠️ v2 instala, pero lleva 4 años sin release | **Migrar a v4.** Solo hay 3 `Image::make()` | 🟡 bajo |
| `hisorange/browser-detect` | 4.5.4 | 5.0.3 (**2024-02**) | ✅ solo pide `php ^8.1` | Subir a v5, o **eliminarlo** (solo llena `historiales`) | 🟡 bajo |
| `fruitcake/laravel-cors` | 2.2.0 | — | ❌ hasta `^9` | **Eliminar.** L9+ trae `Illuminate\Http\Middleware\HandleCors` | 🟢 trivial |
| `fideloper/proxy` | 4.4.2 | — | ❌ hasta `^9` | **Eliminar.** Reemplazado por `TrustProxies` nativo | 🟢 trivial |
| `facade/ignition` | 2.x | abandonado | ❌ | → `spatie/laravel-ignition` 2.12.0 (`^13`) | 🟢 trivial |
| `tymon/jwt-auth` | **`dev-develop`** | — | ❌ hasta `^9` | **Eliminado en Fase 3** → Sanctum 4.3.3 | 🔴 bloqueante |
| `lesichkovm/laravel-advanced-route` | 1.8 | — | — | **Eliminado en Fase 1** | 🟢 resuelto |
| `nunomaduro/collision` | 5.x | 8.9.5 | ✅ | Subir con el framework | 🟢 trivial |
| `laravel/sail` | 1.x | 1.66.0 | ✅ `^13.0` | Decidir junto con Docker (§4) | — |
| `larastan/larastan` | — | 3.10.0 | ✅ `^13` | Añadir en Fase 0 | 🟢 nuevo |
| `kitloong/laravel-migrations-generator` | — | 7.4.0 | ✅ `^13.0` | Añadir en Fase 0 | 🟢 nuevo |

**Tres hallazgos que cambian el plan respecto al primer borrador:**

**Excel es un no-problema, confirmado.** `maatwebsite/excel` **3.1.70** (publicada el 2026-08-13) declara `illuminate/support: …^12.0||^13.0`. Basta con subir dentro de la 3.1.x. Existe una **4.0.0** publicada el mismo día, pero exige `phpspreadsheet ^5.8` — es decir, arrastra el salto de PhpSpreadsheet 1.x → 5.x, cuatro majors de cambios en el formato de celdas y estilos. **No la toques en esta migración.** Los 6 exports y 2 importers se quedan como están.

**Intervention Image sí entra al alcance, y es más fácil de lo que parecía.** Lo probé de verdad, con PHP 8.4.6:

```
$ php8.4 -r 'require "vendor/autoload.php"; $m = new Intervention\Image\ImageManager(["driver"=>"gd"]);
             $img = $m->canvas(400,300,"#336699"); $img->fit(200); $img->encode("png"); ...'

intervention/image v2 en PHP 8.4: FUNCIONA -> 200x200, 615 bytes
```

**v2.7.2 funciona en PHP 8.4** — pero escupe **16 avisos de deprecación** (parámetros implícitamente nullable). Son E_DEPRECATED, silenciables, pero inundarían los logs. Y la versión es de **mayo de 2022**: cuatro años sin mantenimiento.

La buena noticia es la superficie real de uso — **3 llamadas a `Image::make()` en total**:

| Archivo | Línea | Uso |
|---|---|---|
| [`Perfiles/ImagesController.php`](../../app/Http/Controllers/Perfiles/ImagesController.php#L121) | 121–126 | `Image::make(...)->orientate()`, `->fit(200)`, `->save()` |
| [`Perfiles/ImagesUsuariosController.php`](../../app/Http/Controllers/Perfiles/ImagesUsuariosController.php#L48) | 48 | `Image::make(...)->rotate(-90)` |
| [`Perfiles/ImagesUsuariosController.php`](../../app/Http/Controllers/Perfiles/ImagesUsuariosController.php#L64) | 64–68 | `Image::make(...)`, `->rotate(90)`, `->save()` |

Con `intervention/image` **4.2.1** + el puente oficial **`intervention/image-laravel` 4.1.1** (que restituye la facade `Image`), la traducción es:

| v2 | v4 |
|---|---|
| `Image::make($ruta)` | `Image::read($ruta)` |
| `->orientate()` | automático al leer (o `->orient()`) |
| `->fit(200)` | `->cover(200, 200)` |
| `->rotate(-90)` | `->rotate(-90)` (igual) |
| `->save()` | `->save()` (igual) |

**Es una hora de trabajo, no un proyecto.** Hazlo dentro de la Fase 4 y quítate de encima una dependencia muerta.

**`hisorange/browser-detect` instala, pero está estancado.** La 5.0.3 es de **febrero de 2024** y solo pide `php ^8.1`, sin restricción de `illuminate` — así que entra en L13 sin quejarse. Pero arrastra 5 dependencias de parseo de user-agent para llenar 8 columnas de la tabla `historiales` en el login. Yo lo **eliminaría** y usaría `matomo/device-detector` directamente (que ya es dependencia suya) o simplemente guardaría el user-agent crudo. Decisión tuya; no bloquea nada.

**Cambios de código previstos (pocos, porque el código apenas usa el framework):**

- `App\User` → `App\Models\User` (Laravel 8 ya lo esperaba ahí; sigue en la raíz). 336 referencias, pero es un `use` — búsqueda y reemplazo.
- `$this->namespace` en `RouteServiceProvider` desaparece en L9 → resuelto en Fase 1 al usar `Controller::class`.
- ~~Los facades con alias de raíz (`use Request;`, `use DB;`, `use Image;`, `use File;`, `use Hash;`) siguen funcionando en L13 vía `config/app.php` aliases. **No hay que tocarlos** — pero conviene migrarlos a `Illuminate\Support\Facades\*` con Rector, aparte.~~ **Hecho el 19 ago 2026**, y no con Rector — ver la nota de la Fase 6.
- `Illuminate\Support\Facades\Input` — verificar; ya no existe. Aparece comentado en `LoginController.php:11`, y hay un uso vivo en `LoginController.php:53` dentro de un `catch` (`Input::all()`) que **explotaría** si ese catch se llegara a ejecutar. Es un bug latente hoy.
- L11: reestructurado a esqueleto slim (`bootstrap/app.php`) como **commit aparte y opcional**. Laravel 11 y 12 arrancan perfectamente con `app/Http/Kernel.php`. Hazlo solo si quieres el esqueleto moderno; no es requisito.

**Herramienta:** Rector con `LevelSetList::UP_TO_PHP_84` + los sets de Laravel. Correrlo **por carpeta**, revisando cada diff. No de golpe sobre 32k líneas.

---

### Fase 5 — Migraciones al día · 2–3 días

> **Recortada el 19 ago 2026, y por un dato que invalidaba media fase.** Joseth:
> **un colegio nuevo no se crea desde cero — se copia la base de datos de otro**,
> porque hace falta que venga con ciertos datos básicos ya dentro.
>
> Eso tumba el punto 5 y con él la razón principal de la fase. `migrate:fresh`
> produciría un esquema vacío y correcto que **nadie va a usar nunca**: no es así
> como nace un colegio aquí. Reconstruir hacia atrás la historia de 90 tablas
> —que además nunca existió, se construyeron a mano durante años— era la parte
> cara, y resulta que era también la inútil.
>
> **Lo que sí sigue haciendo falta, y ya funciona:** una forma repetible de
> aplicar un cambio de esquema a las 16 bases. Eso no es "migraciones al día", es
> el punto 4 —*ningún cambio a mano en phpMyAdmin: migración o no existe*— y está
> en pie desde la Fase 3, donde `personal_access_tokens` llegó a cada colegio con
> un `artisan migrate` y no con un formulario. La convención está escrita en
> `database/migrations/legacy/README.md`.
>
> **Lo que queda vivo de la fase**, por si algún día compensa: el punto 3,
> documentar las divergencias entre la base real y lo que el código asume. Es el
> que produjo hallazgos de verdad (`years.id` en vez de `year_id`). El 1 y el 2
> son documentación.

Ya sembrado en la Fase 0. Aquí se cierra:

1. La baseline `database/schema/mysql-schema.sql` es la verdad.
2. `docs/db/` con las 90 tablas documentadas (generadas, luego revisadas a mano).
3. Detectar y **documentar** las divergencias entre la BD real y lo que el código asume. Ejemplo del historial reciente de commits: `fix: corregir columna del contador de certificados (years.id, no year_id)` — eso es exactamente el síntoma de no tener el esquema versionado.
4. Regla de aquí en adelante: **ningún cambio de esquema a mano en phpMyAdmin.** Migración o no existe.
5. Verificación: `php artisan migrate:fresh --seed` en limpio debe producir una BD sobre la que la suite de contrato pasa.

---

### Fase 6 — Modelos, limpieza y calidad · continuo

> **Cerrada la parte que se cierra, el 19 ago 2026.** Esta fase estaba escrita
> como «continuo», y la mitad lo sigue siendo. Lo que sí tenía final —montar las
> herramientas y barrer lo que ya no se ejecuta— está hecho, y produjo bastante
> más de lo previsto.
>
> **Larastan en nivel 0 encontró 207 errores.** El nivel 0 solo se queja de lo
> que no puede funcionar: clases que no existen, variables sin definir,
> propiedades sin declarar. Que salgan 207 en ese nivel, sobre 32.000 líneas, es
> el dato de la fase. Están en
> [05-codigo-muerto-y-roto.md §6](05-codigo-muerto-y-roto.md), y el resumen es:
>
> - **61 sitios con el nombre de clase sin barra** dentro de un `namespace`:
>   42 `catch (Exception $e)` que no capturan nada y 19 `App::abort(400, ...)`
>   que no abortan sino que lanzan «Class not found». Es la misma forma del
>   `catch (Tymon\JWTAuth\...)` que ya teníamos documentado, sesenta veces más.
> - **Seis reservas contra la división por cero que no reservaban**: en PHP 8
>   eso es un `DivisionByZeroError`, que es un `Error` y no un `Exception`, así
>   que hacía falta `\Throwable`.
> - **La pantalla de roles y permisos, rota entera** desde hace años: el modelo
>   `Permission` extendía una clase de Entrust, y Entrust no está instalado ni
>   aparece en el `composer.lock`.
> - **Un nombre de clase declarado en dos ficheros** (`ImportarController`), con
>   el classmap de composer eligiendo uno por orden de escaneo.
> - **1.500 líneas sin ruta y sin referencia**, borradas.
>
> **Lo que no se arregló, y por qué.** Seis endpoints están enrutados y
> responden 500 desde que se escribieron. Arreglarlos no es limpieza: hay que
> decidir de dónde sale cada variable, y en dos de ellos si la operación debe
> existir siquiera. Se dejan **anotados en `phpstan.neon` con nombre, motivo y
> `count`** —no en un baseline generado, que los escondería— para que uno nuevo
> rompa el análisis. La lista está en la §6.5 del otro documento.
>
> **La regla que salió de aquí:** sin ruta y roto se borra; con ruta y roto se
> documenta. Borrar un endpoint enrutado convierte un 500 en un 404 sin decirle
> a nadie qué pretendía hacer esa pantalla.
>
> **Y lo que no cambia:** las 990 consultas crudas, los tres `Boletines` y los
> dos `Bolfinales`. Están en la lista de «candidatos a consolidar» de abajo,
> pero los cinco están enrutados y vivos, y fusionarlos es tocar el cálculo de
> notas sin nadie que lo especifique. Sigue siendo lo que el §5 protege.

**Lo que quedó montado:**

| Herramienta | Alcance | Dónde |
|---|---|---|
| **Pint** | solo lo que escribió esta migración: `routes/`, `tests/`, `app/Services`, `app/Support`, `app/Http/Middleware`, `app/Console`, los `Concerns` | `composer run pint` · CI |
| **Larastan** | **nivel 3** sobre `app/`, `config/`, `database/`, `routes/`, `tests/`, `tools/` | `composer run stan` · CI |
| **Rector** | configurado y **sin correr**: por carpeta y revisando cada diff | `rector.php` |
| **`tools/imports-de-facades.php`** | los imports por alias de raíz, una línea por import | corrido el 19 ago 2026 · lo vigila `AliasDeFacadesTest` |

Pint no toca el legacy a propósito: reformatear 129 controladores sería un diff
ilegible encima de la migración, y el `.styleci.yml` de 2021 ya avisaba de por
dónde duele —tenía `no_unused_imports` desactivado—. Se formatea el día que se
toque cada fichero.

**Los imports por alias de raíz — cerrado el 19 ago 2026.** Era lo único que le
quedaba a la Fase 4: `use DB;` funciona porque `config/app.php` mantiene un
array `aliases` que registra un `class_alias` global por cada uno. Laravel 13 ya
no genera ese array en las aplicaciones nuevas, y el día que desaparezca —o el
día que alguien modernice ese fichero copiando uno reciente— dejan de resolver
todos a la vez: «Class DB not found» en cada petición que pase por ahí.

Medido con el tokenizador, no con grep: **309 referencias en 145 ficheros**. El
grep se equivocaba en los dos sentidos —contaba seis `Auth::` escritos dentro de
comentarios, y no veía los `\DB::` de los tests que escribió esta misma
migración—. El desglose: 140 `use DB;`, 102 `use Request;`, 24 `use Hash;`, y
una cola de `File`, `Excel`, `Image`, `Auth`, `View`, `Log`, `Browser`, `App`.

**No se hizo con Rector**, aunque estuviera configurado para ello. El set
`LARAVEL_FACADE_ALIASES_TO_FULL_NAMES` escribe el nombre completo en cada
llamada, y aquí hay 990 `DB::`; con `withImportNames()` sí pone el import, pero
de paso colapsa cualquier otro nombre completo que encuentre y tocaba diez
ficheros que no tenían el problema. `tools/imports-de-facades.php` cambia una
línea por import y ninguna más: **293 líneas de diff en 141 ficheros**, y ni una
llamada tocada.

Dos cosas que un reemplazo ciego habría roto, y que salieron de leer el mapa de
`config/app.php` en vez de escribir la lista a mano:

- **`Browser` apunta a `hisorange\BrowserDetect\Facade`**, cuyo nombre corto no
  es `Browser`. Necesita `use ... as Browser;` o las llamadas dejan de resolver.
  Lo mismo con `Eloquent`, que apunta a `Eloquent\Model`.
- **Las vistas Blade no se arreglan importando.** Una plantilla se compila a PHP
  sin namespace, así que el `Route::has()` de `welcome.blade.php` resuelve al
  nombre global igual. Ahí hay que escribir el nombre completo en la llamada.

**El array se queda**, y a propósito. Ya nada de este repo depende de él —lo
comprueban los dos tests de `AliasDeFacadesTest`—, pero cada colegio tiene su
copia de la aplicación, y una vista propia que no esté en este repo seguiría
usando `Route::`. Borrarlo es una decisión que se toma mirando los servidores.

**Larastan, del nivel 0 al 1 — 19 ago 2026.** El 0 solo mira lo que no puede
existir; el 1 añade métodos y propiedades que no existen en una clase que sí.
341 errores, y **320 eran uno solo**: el `$this->user` de 129 controladores, que
sirve un `__get` del trait `ResuelveElUsuario` y que phpstan no puede adivinar.
Una anotación `@property` en el trait y desaparecen los 320, sin tocar código.

De los 21 que quedaban, **uno se arregló** —`GET perfiles/comprobarusername`,
que llamaba a `User::withTrashed()` sin que `User` use SoftDeletes: 500 desde
que se escribió, y el arreglo no tiene decisión detrás— y **diez se documentan**
con su `count` en `phpstan.neon`, con lo que falta decidir en cada uno. Están en
[05 §7](05-codigo-muerto-y-roto.md). Dos de ellos son `Excel::create()`, la API
de maatwebsite 2.x, en informes que el §5 deja fuera de alcance.

Es el mismo punto ciego de la Fase 6 —`__call` hace que para el análisis la
clase «podría» responder— visto una capa más arriba. Y el mismo patrón: ninguno
de los once salió de leer el código con una lista delante.

**No es un big-bang.** Con 990 queries crudas, reescribirlas todas a Eloquent sería meses de trabajo y meses de bugs nuevos, sin ganancia funcional. Enfoque:

- **Modelos:** completar los que faltan de las 90 tablas, pero solo cuando se toque esa área. Los 47 que hay ya cubren el núcleo.
- **Queries crudas:** convertir **solo** las que aparezcan en el perfilado como lentas (ver [plan de rendimiento](02-plan-rendimiento.md)). Una query cruda parametrizada no es un bug; es solo verbosa.
- **Duplicación:** los candidatos, con lo que se hizo con cada uno el 19 ago 2026 —
  - ~~`ComportamientoController` en la raíz y en `Disciplina/`~~ · **borrado** el de la raíz: sin ruta y sin referencia
  - ~~`Informes/PuestosAnualesController`~~ · **borrado**: ni ruta ni referencia
  - `BolfinalesController` existe **dos veces**. El de la raíz no está enrutado, pero **sí lo instancia** `CertificadosEstudioController` —sin `use`, así que resuelve al de la raíz—. Se queda: no es código muerto, es código vivo dentro de un camino que responde 500 por otra razón (§6.5 del informe)
  - `Boletines`, `Boletines2`, `Boletines3` (520 + 498 + 494 líneas). **No se tocan**: los tres están enrutados y sirven formatos distintos de boletín. Fusionarlos es tocar el cálculo de notas
  - `ImporterFixer` · se quedó, con las dos propiedades que le faltaban declaradas
  - modelo `Debugging` (9.553 filas en producción) · se quedó: lo usan `ChangeAskedController`, `Grupo`, `Nota`, `Unidad` y `NotaFinal`
- **Herramientas:** ~~Pint (formato), Larastan (subir de nivel 0 a 3 gradualmente), Rector.~~ **Montadas** — ver arriba. El nivel 1 se subió el 19 ago 2026 y encontró once endpoints rotos más ([05 §7](05-codigo-muerto-y-roto.md)). Sigue siendo continuo llegar al 3.
- **Validación:** hoy hay **2** validaciones en todo el proyecto. Cada endpoint que se toque estrena su FormRequest. No se hace de golpe.

---

## 4. Sobre kool y Docker

**Respuesta corta: kool no está mal, pero la imagen sí.**

kool 3.4.0 es un envoltorio delgado sobre `docker compose`. `kool run artisan` es literalmente `docker compose exec app php artisan`. Funciona, y tu `kool.yml` es razonable. Su único costo real es una herramienta más que instalar y un proyecto con comunidad pequeña (riesgo de bus-factor, no de corrección).

Lo que **sí** hay que cambiar:

| Cosa | Hoy | Problema |
|---|---|---|
| Imagen PHP | ~~`kooldev/php:8.0-nginx`~~ · **`8.4-nginx`** | ✅ Fase 4 |
| ~~`version: "3.7"` en `docker-compose.yml`~~ | quitado | ✅ 19 ago 2026 |
| ~~Puerto MySQL `${KOOL_DATABASE_PORT:-3307}:3307`~~ · **`:3306`** | ✅ 19 ago 2026 | Medido antes de tocarlo: MySQL escucha en 3306 y en el 3307 no había nada, así que el puerto publicado no llevaba a ningún sitio. Ahora `mysql -h 127.0.0.1 -P 3307` desde el host responde |

**Recomendación, por orden de esfuerzo:**

1. ~~**Mínimo (recomendado ahora):** quedarse con kool, cambiar la imagen a PHP 8.4, quitar `version:`, arreglar el puerto. 1 hora.~~ **Hecho.** Se sigue con kool.
2. **Estándar hoy:** `laravel/sail` — ya está en tus `require-dev`. Es el docker-compose oficial de Laravel, con `sail up`, `sail artisan`, etc. Es lo que cualquier dev de Laravel espera encontrar.
3. **Producción moderna:** **FrankenPHP** (`dunglas/frankenphp`) con Laravel Octane. Es la historia oficial de rendimiento de Laravel hoy: un binario, sin nginx+fpm, worker mode. Eso sí — Octane mantiene la app en memoria entre requests, y este código tenía cinco propiedades estáticas mutables que **se filtrarían entre usuarios**. El 19 ago 2026 quedó una: las tres de rutas de imágenes estaban muertas y `$intentoLogueoPorActive` pasó a ser estado de la petición (con test). Sigue `User::$nota_minima_aceptada`, que leen 26 sitios del cálculo de notas — sacarla de ahí es tocar lo que el §5 protege. **Y el 20 ago 2026 apareció una sexta de la misma familia, que no es una propiedad estática y por eso no salía en esa cuenta:** `ResuelveElUsuario` memoriza `$this->user` en la instancia del controlador, e `Illuminate\Routing\Route::getController()` guarda esa instancia **dentro de la ruta**, que vive en el router, que es un singleton. Con php-fpm da igual; con Octane **la segunda persona que pida esa ruta se ejecuta con la identidad de la primera**. Se vio dentro de los tests, que sí comparten proceso, y ahí está cerrado (ver [03-tests.md](03-tests.md)); para Octane el arreglo es otro y va antes, no después.

**Mi recomendación concreta: opción 1 ahora, evaluar Sail en la Fase 4, FrankenPHP/Octane nunca antes de limpiar el estado estático.**

---

## 5. Lo que NO se toca

Explícitamente fuera de alcance, para proteger lo que ya funciona:

| Área | Por qué |
|---|---|
| **Excel** (`maatwebsite/excel` → 3.1.70) | Verificado: 3.1.70 declara `^13.0`. Los 6 exports y 2 importers no se tocan. **No subir a 4.0** (arrastra PhpSpreadsheet 1.x→5.x) |
| ~~**Imágenes** (`intervention/image` v2)~~ | **Corregido: sí entra al alcance.** Son solo 3 `Image::make()`; v2 es de 2022 y deprecada en PHP 8.4. Ver Fase 4 |
| **Vistas Blade de informes** (`observador`, `simat`, `deudores`, `boletines`…) | Blade no cambia de forma incompatible entre L8 y L13. Riesgo mínimo |
| **Lógica de negocio** (cálculo de notas, definitivas, puestos, PIAR) | Intocable. Es el corazón del sistema y no hay quien lo especifique |
| **Nombres de métodos de controlador** (`getIndex`, `putGuardarValor`) | Se conservan en las fases 1–4. Renombrar es cosmético y arriesgado sin tests |
| **Las 990 queries crudas** | Se convierten solo donde el perfilado lo justifique |

---

## 6. Riesgos y mitigación

| Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|
| Un endpoint público hoy se rompe al ponerle `auth` | **Alta** | Alto | Middleware de solo-registro durante una semana antes de aplicar (Fase 2) |
| Cambio de auth invalida sesiones vivas | Certeza | Medio | Desplegar en horario de baja carga + aviso. Es de una vez |
| La app Flutter deja de autenticar | Media | Alto | Inventariar `myvc_flutter` **antes** de la Fase 3; ventana con ambos mecanismos activos |
| El esquema real difiere de lo que el código asume, y sale en producción | **Alta** | Medio | Fase 0.1 congela el esquema; los tests de contrato corren contra él |
| Un export de Excel cambia sutilmente (formato de celda, orden de columnas) | Media | Alto | Tests de contrato con hash de contenido normalizado, no de bytes |
| Rector reescribe algo que rompe lógica de negocio | Media | Alto | Correr por carpeta, revisar cada diff, nunca en bloque |
| El desfase de migraciones esconde columnas que solo existen en producción | Media | Medio | Dump del esquema **desde producción**, no desde el docker local |

---

## 7. Recomendaciones adicionales

Cosas que no pediste pero que valen mucho más de lo que cuestan:

1. **Un `CLAUDE.md` / `CONTRIBUTING.md`** con las convenciones que salgan de esta migración. El próximo junior necesita saber que las rutas van en `routes/api/`, que el esquema se cambia con migraciones, y que `fromToken` está deprecado.
2. **Rate limiting real.** Hoy es `Limit::perMinute(60)` global para todo — incluido `/api/login`. 60 intentos de contraseña por minuto es una invitación a fuerza bruta (ver plan de seguridad).
3. **`QUEUE_CONNECTION=sync`.** Los imports de Excel y los boletines corren en el request HTTP. Un import grande = timeout. Mover a cola `database` es media tarde de trabajo y elimina una clase entera de incidentes.
4. **Logs estructurados.** `Log::info($token)` en `LoginController.php:121` **escribe el token JWT en el log**. Eso es una credencial en texto plano en disco. Hay que barrer todos los `Log::` antes de producción.
5. **Sentry o similar.** Con 0 tests y 0 observabilidad, los bugs los reporta el usuario por WhatsApp. Es lo más barato que puedes añadir.
6. **Borrar `myvc_front_2` y `myvc_dist` del flujo de trabajo** o documentar qué son. Tres copias del frontend al lado es una fuente garantizada de "arreglé el bug y volvió".

---

## 8. Qué hacer mañana

> **Desactualizado.** Los cuatro puntos de abajo están hechos, salvo ampliar los
> tests de contrato más allá del login. El estado real está en la nota de la
> Fase 0.

En orden, sin saltarse pasos:

```bash
# 1. El objetivo ya está confirmado: Laravel 13.25.0 + PHP 8.4
#    (Laravel 12 salió de soporte de bugs el 2026-08-13)

# 2. Congelar el esquema real (desde PRODUCCIÓN, no desde el docker local)
mysqldump --no-data --routines --skip-comments <prod> > database/schema/mysql-schema.sql

# 3. Confirmar que el inventario de rutas es reproducible
php tools/route-inventory.php docs/migracion/rutas-actuales.csv   # → 538, 0 colisiones

# 4. Escribir los 6 tests P0 de login (uno por tipo de usuario)
#    Ese es el commit que arranca de verdad la migración.
```

**Lo primero que quiero que leas después de esto:** [01-plan-seguridad.md](01-plan-seguridad.md). Hay dos hallazgos que no pueden esperar a la migración.
