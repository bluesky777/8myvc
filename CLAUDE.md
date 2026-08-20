# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

`8myvc` es la API del sistema escolar MyVc: Laravel 13 + PHP 8.4, ~32.000 líneas
en `app/`, 129 controladores y 539 rutas. Está en migración desde Laravel 8; el
plan, las mediciones y las decisiones ya tomadas viven en `docs/migracion/` y
**se leen antes de re-litigar nada**.

## Idioma y convenciones de escritura

El código nuevo, los comentarios, los mensajes de commit y la documentación van
**en español**, salvo los términos del framework, que se dejan en inglés
(*guard*, *seeder*, *snapshot*, *middleware*). Los comentarios explican **por
qué**, no qué: casi todo lo que hay escrito en este repo es el resultado de una
medición o de una decisión, y sin el porqué se deshace solo.

## Comandos

Todo corre dentro del contenedor (`kool` sobre docker compose):

```bash
docker exec 8myvc-app-1 php artisan test                       # los 438
docker exec 8myvc-app-1 php artisan test --testsuite=Contrato  # solo contrato (necesita BD)
docker exec 8myvc-app-1 php artisan test --filter=NotasTest    # una clase
docker exec 8myvc-app-1 composer run pint                      # formato
docker exec 8myvc-app-1 composer run stan                      # larastan nivel 5

tools/construir-bd-test.sh                                     # crea/reconstruye la BD de tests
```

La base de tests **no** se reconstruye entre tests: cada uno corre dentro de una
transacción. Solo hace falta reconstruirla al cambiar el esquema o el seed.

> Si alguna vez corres `php artisan config:cache` en local, bórralo antes de los
> tests (`config:clear`): el config cacheado congela el `.env` y `phpunit.xml`
> deja de poder apuntar a la base de tests.

### Herramientas de medición (`tools/`)

Ninguna se ejecuta sola; todas contestan una pregunta que no se puede contestar
leyendo el código. Cada una lleva su uso en la cabecera.

| Herramienta | Contesta |
|---|---|
| `cobertura-de-rutas.py` | qué rutas tienen la respuesta comprobada por algún test |
| `indices-que-faltan.php` | qué consultas recorren una tabla sin índice aplicable |
| `consultas-lentas.py` | qué consultas se llevan el tiempo en producción |
| `columnas-en-los-modelos.php` | reescribe las `@property` de los modelos desde el esquema real |
| `route-inventory.php` · `route-match-check.php` | la tabla de rutas, comparable 1:1 |
| `inventario-autorizacion.py` · `auditar-autenticacion.php` | qué guard cubre cada ruta |

## Arquitectura

**No es una aplicación Laravel idiomática, y tratarla como si lo fuera rompe
cosas.** Hay 990 consultas crudas (`DB::select/insert/update`), los modelos
Eloquent se usan marginalmente y hay 2 validaciones en todo el proyecto. El
framework casi no se toca, que es justo lo que hizo viable la migración.

### El objeto `$this->user` NO es un modelo

`User::fromToken()` devuelve un **`stdClass`**: persona + grupo + año + periodo +
configuración del colegio + roles + permisos, aplanado en un objeto con ~40
columnas de un `switch` de cuatro ramas (Profesor / Alumno / Acudiente /
Usuario). Lo monta `App\Services\ContextoDeUsuario`; el token lo valida
`App\Services\Sesion`, y ninguno sabe del otro.

- En los controladores llega por el trait `Concerns\ResuelveElUsuario`, que lo
  resuelve **en la primera lectura**, no en el constructor. Un constructor que
  resuelva al usuario rompe `route:list` y `route:cache` — hay un test que lo
  impide.
- `$user->user_id` es el id de `users`; `$user->persona_id` es el de la ficha.
  No son lo mismo.

### Rutas y autorización

`routes/api/*.php`, un fichero por dominio. **El guard va por defecto a toda la
API** y las excepciones públicas se marcan una a una — son quince y son un test
(`RutasPreLoginTest`). Middlewares propios en `app/Http/Middleware/`:

| Guard | Qué exige |
|---|---|
| `auth.token` | sesión válida |
| `auth.personal` | que sea personal del colegio, no alumno ni acudiente |
| `boletin.propio` | que el boletín pedido sea suyo o de un acudido |
| `persona.propia` | que el id del cuerpo o de la URL sea suyo |

La regla de negocio, confirmada y no re-litigable: **un alumno solo ve lo suyo;
un acudiente, lo suyo y lo completo de sus acudidos.** Los métodos conservan sus
nombres viejos (`getIndex`, `putGuardarValor`): renombrarlos es cosmético y va
después.

**En código nuevo se usan los códigos HTTP correctos** —403, 404, 422, 429—
aunque el legacy de al lado devuelva 400 para todo.

### El esquema vive en un volcado, no en migraciones

`database/schema/mysql-schema.sql` es la verdad: 90 tablas congeladas desde
producción. Las 3 migraciones viejas están archivadas en `legacy/`.

- **Ningún cambio de esquema a mano en phpMyAdmin: migración o no existe.**
- Las columnas de los modelos se generan desde ese volcado
  (`tools/columnas-en-los-modelos.php`), no se escriben a mano.
- `migrate:fresh` no sirve para nada aquí: un colegio nuevo se crea **copiando la
  base de otro**, porque necesita datos básicos dentro.

### Tests de contrato

No comprueban que el código esté bien: comprueban que **la respuesta no ha
cambiado**. `tests/Contrato/` con snapshots en `Snapshots/`. Lo que los hace
encontrar cosas es **mirar el resultado y no el estado**: el píxel en vez del
200, la forma de la hoja de Excel en vez de los bytes, el viaje de ida y vuelta
en vez de una llamada. Ese criterio ha encontrado todo lo que se ha encontrado.

Cómo se usan, cómo se regenera el seed y qué no cubre: `docs/migracion/03-tests.md`.

### Calidad

- **Pint** solo sobre lo que escribió la migración (ver `composer.json`).
  Reformatear los 129 controladores sería un diff ilegible; se formatea el día
  que se toca cada fichero.
- **Larastan nivel 5**, y no baja. Lo que no se puede arreglar va anotado en
  `phpstan.neon` **con nombre, motivo y `count`** — nunca en un baseline
  generado, que los escondería.
- **Rector** está configurado y sin correr: por carpeta y revisando cada diff.

**La regla para el código roto: sin ruta y roto se borra; con ruta y roto se
documenta.** Borrar un endpoint enrutado convierte un 500 en un 404 sin decirle
a nadie qué pretendía hacer esa pantalla. Los que están rotos a propósito
—porque arreglarlos exige una decisión del colegio— están en
`docs/migracion/05-codigo-muerto-y-roto.md` con su test fijando el error exacto.

## Despliegue: lo copiado y lo compartido van al revés de lo que parece

Dieciséis colegios, cada uno con su subdominio, su base de datos y su copia.

- `app/`, `routes/`, `config/`, `.env`: **copia real en cada colegio**. Un
  arreglo fusionado **no está desplegado**; llega colegio a colegio.
- `vendor/`: **compartido por symlink**. Un `composer install` dentro de un
  colegio sigue el symlink y cambia todos los que cuelguen de esa carpeta.
- `storage/`: propia de cada colegio.

Hay **cuatro clientes**, no uno: `myvc_front` (AngularJS, uno por colegio),
`myvc_front_2` (Angular, solo el PIAR), `myvc_flutter` (**una sola app para
todos** — lo que la rompa los rompe a todos) y esta API. Un arreglo del front que
exponga un endpoint no se publica hasta que el guard del backend esté
**desplegado**, no solo fusionado.

Los comandos están en `docs/DESPLIEGUE.md` y el porqué en
`docs/DESPLIEGUE-REFERENCIA.md`.

## Rendimiento

Medido, no supuesto: `docs/migracion/02-plan-rendimiento.md` lleva la cuenta de
qué se probó y qué resultó ser ruido. Dos interruptores, los dos apagados de
serie: `CONSULTAS_LENTAS_MS` (registro de consultas lentas, porque en cPanel no
hay acceso al `slow_query_log` de MySQL) y `CONTEXTO_SEGUNDOS` (caché del
contexto de usuario, que medida ahorra 0,75 ms y por eso no se enciende).

Antes de optimizar algo: medirlo. Antes de crear un índice: `EXPLAIN`.
