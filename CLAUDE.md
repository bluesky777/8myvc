# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

`8myvc` es la API del sistema escolar MyVc: Laravel 13 + PHP 8.4, ~37.000 líneas
en `app/`, 113 clases de controlador y **564 rutas** (contadas con
`route:list --json` el 2 sep 2026; el 24 ago el de rutas se movió por primera vez,
de 539 a 542, con los tres endpoints que pidió `myvc_flutter`, el 28 a 543 con
`PUT users/mi-docente`, que pidió Joseth para el panel de `app2`, el 31 a 544 con
`GET grupos/{grupo_id}/alumnos-de/{que}`, que pidió el front para el modal de
«Alumnos por grupo» del mismo panel, y el 1 sep a 549: **545–547** con las tres del
boletín independiente —`periodo`, `planilla` y `copiar`— y **548–549** con
`boletin-independiente/marcados` y `/alumno`, las dos lecturas de la pantalla por
estudiante que autorizó Joseth ese día, y **550** con `GET colegio/logo`, la
pública que deja a la pantalla de login pedir el logo del colegio sin token, también
decidida por Joseth ese día, y el 2 sep a **564** de una vez, con las dos épicas
de esa noche: **las cuatro de nivelar** —`PUT`/`DELETE notas/nivelar/{id}`,
`PUT notas/nivelar/lote` y `PUT definitivas_periodos/nivelar`—, que son **endpoints
nuevos por diseño**, porque `notas/update` y `notas/lote` no pueden aprender a nivelar:
`myvc_flutter` es una sola app para los quince y una versión vieja convive meses, así que
un 95 tecleado desde el móvil se guardaría topado
(`docs/migracion/22-nivelaciones.md`); y **las diez de `rubricas/`**, la familia entera de
la decisión 4 de Joseth de ese día —la rúbrica produce la nota—, contrato en
`docs/migracion/26-rubricas.md`. **Ese salto lo trajo una fusión de tres ramas y por eso
el número se contó entero, no se sumaron los dos tramos** — «el de rutas no se mueve»
sigue siendo la
regla: una ruta nueva es una decisión, no un efecto secundario, y mueve este
documento y **tres** snapshots, no dos: `rutas.json`, `guards-por-ruta.json` y
`guard-por-familia.json`, que cuenta cuántas rutas tiene cada familia y cuántas
llevan guard).

> **Y esta cifra se cuenta, no se hereda.** El 1 sep 2026 este párrafo decía **544**
> con el router en **547**: las tres rutas del boletín independiente entraron con su
> decisión y su documento, y nadie movió el número de aquí. Iba en la dirección que
> no se nota —**hacia abajo**, o sea contando de menos—, así que no había ningún
> rojo que lo delatara: los tres snapshots sí se actualizaron, porque los mueve un
> test. **El número de este fichero no lo comprueba nadie**, y por eso se cuenta con
> `route:list` el día que se toca en vez de sumarle uno al que había.

Las tablas de `DESPLIEGUE.md` **no** se tocan al añadir una ruta: son lo que se midió
el día de un despliegue, y se remiden el día del siguiente. El plan, las mediciones y
las decisiones ya tomadas viven en `docs/migracion/` y **se leen antes de re-litigar
nada**.

> **Lo primero, antes que este fichero: `docs/migracion/ESTADO-ACTUAL.md`.** Dice
> qué se está haciendo ahora mismo, qué es lo siguiente y qué espera una decisión
> de Joseth. Existe para que una sesión nueva continúe **sin que nadie le dé
> contexto**, así que **se actualiza en el mismo commit que el trabajo** — un
> commit aparte al final es el que no se hace cuando la sesión se corta.

## Idioma y convenciones de escritura

El código nuevo, los comentarios, los mensajes de commit y la documentación van
**en español**, salvo los términos del framework, que se dejan en inglés
(*guard*, *seeder*, *snapshot*, *middleware*). Los comentarios explican **por
qué**, no qué: casi todo lo que hay escrito en este repo es el resultado de una
medición o de una decisión, y sin el porqué se deshace solo.

## Comandos

Todo corre dentro del contenedor (`kool` sobre docker compose):

```bash
docker exec 8myvc-app-1 php artisan test                       # 1.006 el 22 ago; 903 son de Contrato
docker exec 8myvc-app-1 php artisan test --testsuite=Contrato  # solo contrato (necesita BD)
docker exec 8myvc-app-1 php artisan test --filter=NotasTest    # una clase

# Qué alcanza de verdad un token. No corre con los demás: mide e imprime.
docker exec -e BARRIDO_TIPO=Alumno 8myvc-app-1 php artisan test --group=barrido
docker exec 8myvc-app-1 composer run pint                      # formato
docker exec 8myvc-app-1 composer run stan                      # larastan nivel 7

tools/construir-bd-test.sh                                     # crea/reconstruye la BD de tests

# Varias sesiones a la vez: un árbol y una base por sesión. Monta las dos, y
# comprueba el aislamiento imprimiendo desde dónde carga las clases.
tools/worktree-de-sesion.sh b fix/lo-que-toque
docker exec -w /app/.worktrees/b -e DB_TEST_DATABASE=simonbolivar_testing_b \
    8myvc-app-1 php artisan test

# Solo la base, si de verdad se comparte el árbol (dos suites contra la misma
# base dan deadlocks). El sufijo es libre mientras lleve _testing dentro.
DB_TEST_DATABASE=simonbolivar_testing_b tools/construir-bd-test.sh
```

> **`vendor/` no se enlaza con symlink en un worktree**, aunque sea lo que hace
> el despliegue: `__DIR__` resuelve los symlinks y el árbol acaba cargando el
> `app/` del principal —con los tests en verde—. Lo copia con enlaces duros
> `tools/worktree-de-sesion.sh`, que lleva la medición en la cabecera. El reparto
> y las reglas de una noche en paralelo están en
> `docs/migracion/15-la-noche-en-paralelo.md`.

La base de tests **no** se reconstruye entre tests: cada uno corre dentro de una
transacción. Solo hace falta reconstruirla al cambiar el esquema o el seed.

> Si alguna vez corres `php artisan config:cache` en local, bórralo antes de los
> tests (`config:clear`): el config cacheado congela el `.env` y `phpunit.xml`
> deja de poder apuntar a la base de tests.

### Herramientas de medición (`tools/`)

Ninguna se ejecuta sola; todas contestan una pregunta que no se puede contestar
leyendo el código. Cada una lleva su uso en la cabecera.

> **Ninguna imprime `OK` sin decir su población.** Un «0 encontrados» no
> distingue *«revisé 466 y ninguno lo era»* de *«no revisé nada»*, y de las dos
> lecturas la falsa es la que hace archivar el asunto. Pasó dos veces el 24 ago
> 2026 en direcciones opuestas: una herramienta contaba duplicados **vivos**
> cuando el índice que iba a rechazarlos mira la tabla entera, y un detector del
> front afirmaba cubrir **385** llamadas cuando había **411**. La segunda mitad de
> la regla es la que muerde: **el primer sitio donde mirar cuando el número sale
> raro es el detector**, no el código.
>
> Y una segunda forma, que no se arregla repitiendo la medición: **un detector
> puede contar bien un síntoma y no estar contando la causa.** El barrido de la
> [§142](noche-2026-08-23/r.md) dio **nueve** sitios y los nueve eran ciertos —
> pero se leyeron como «nueve sin guarda» y **ocho la tenían**. Repetirlo da nueve
> otra vez. Ahí lo que hay que comprobar es que **el detector detecta lo que dice
> su nombre**.

| Herramienta | Contesta |
|---|---|
| `cobertura-de-rutas.py` | qué rutas tienen la respuesta comprobada por algún test |
| `indices-que-faltan.php` | qué consultas recorren una tabla sin índice aplicable |
| `consultas-lentas.py` | qué consultas se llevan el tiempo en producción |
| `columnas-en-los-modelos.php` | reescribe las `@property` de los modelos desde el esquema real |
| `route-inventory.php` · `route-match-check.php` | la tabla de rutas, comparable 1:1 |
| `inventario-autorizacion.py` · `auditar-autenticacion.php` | qué guard cubre cada ruta |
| `respuestas-que-mienten.py` | qué métodos frenan la escritura y responden 200 igual |
| `interruptores-que-nadie-lee.py` | qué columnas `tinyint(1)` no decide nadie — con `--clientes`, tampoco los cuatro fronts |
| `identificadores-del-cuerpo.py` | qué rutas reciben un id por el cuerpo que no comprueba nadie |
| `escrituras-en-las-notas.py` | qué métodos escriben en las notas sin preguntar por el interruptor del periodo |
| `coste-del-recalculo.php` | qué cuesta recalcular una definitiva, sobre las asignaturas reales |
| `secciones-citadas.py` | qué §§ cita el código y ya no existen en `docs/` — se corre **después de cada renumerado** |
| `consultas-en-bucle.py` | en qué profundidad de bucle vive cada consulta — **ordena candidatos, no mide coste**; trae su propio control (`--control`) |
| `guardas-sin-respaldo.py` | qué métodos dependen enteros del middleware de su ruta — **ordena candidatos, y se equivocó en las dos direcciones**: cada fila se lee |
| `verdad-laxa-que-escribe.py` | dónde una cadena cualquiera del cliente vale por «sí» **y gobierna una escritura** — 21 de 980 `if`, tres con consecuencia |

Y una que **no** está en `tools/` y contesta la pregunta contraria:
`tests/Barrido/SuperficieDeUnTokenTest.php` golpea la API entera con un token y
mira **el resultado** —qué datos personales salen y qué filas se escriben de
verdad— en vez de la petición. Vive en `tests/` porque barrer las escrituras es
ejecutarlas, y la transacción de cada test es lo único que hace eso inocuo. De
ahí salieron las §14 y §15 de `docs/migracion/05-codigo-muerto-y-roto.md`.

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
API** y las excepciones públicas se marcan una a una — son
**`RutasPreLoginTest::TOTAL_PUBLICAS`, hoy doce**, y son un test que las ata por las
dos direcciones: que la lista no tenga de más y que el router no tenga de menos.
La duodécima entró el 1 sep 2026 y es la única que no va del login ni del logout:
`GET colegio/logo`, para que la puerta de entrada del colegio pueda enseñar el logo
que se cambió dentro. La exposición se midió antes de proponerla y está en la §245
del 05 — el fichero ya se descargaba sin sesión.

> **Y una pública mueve dos sitios más que una normal**, que es lo que nadie previó el
> día que entró la duodécima y cantó la suite entera: `AutenticacionTest::SIN_GUARD`
> —la lista de las que no exigen token, **con el motivo al lado**— y el censo
> `familias-que-nunca-entran-en-el-candado.json`, donde una familia nueva de una sola
> ruta sin guard entra como «0 de 1». Ese renglón **es la forma que tendría un agujero
> nuevo**, así que se acepta escribiendo por qué no lo es, nunca regenerando y pasando.
> En cambio `guards-por-ruta.json` **no** se mueve: lista las que llevan guard.

> **Ese número no se cuenta con un `grep`, y aquí está el porqué porque ya costó
> tres cifras.** Hay **19** rutas sin `auth.token` en `routes/`, y **siete
> contestan 401 igual** porque se defienden dentro del método: **quitarle el guard
> a una ruta no la hace pública**. Doce es del **resultado** —quién recibe datos sin
> presentar token—, no del mecanismo, y por eso el día que entró la duodécima se
> **corrió el test** en vez de restar 19 − 7.
>
> Este fichero decía **quince**, el docblock del test decía **siete** y `grep` daba
> **diecinueve** (una era un comentario; hoy da veinte, con la duodécima dentro).
> Ninguno era una cifra que hubiera envejecido: **los tres nacieron mal**, y se
> demuestra con que las 18 sin guard de aquel día **eran exactamente las mismas
> cinco días después** — el código no se movió. El
> «siete» se escribió cuando el modelo era el contrario (el guard se ponía ruta a
> ruta y `withoutMiddleware` no existía), así que **la pregunta no tenía todavía un
> conjunto que contar**. Medido y desglosado commit a commit en
> [`noche-2026-08-25/pub-1.md`](docs/migracion/noche-2026-08-25/pub-1.md).

Middlewares propios en `app/Http/Middleware/`:

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
  Reformatear los 113 de golpe sería un diff ilegible; se formatea el día
  que se toca cada fichero.
- **Larastan nivel 7**, y no baja. El **6 se salta a propósito**: sus 1.940
  errores son anotación pura, ninguno señala código que pueda fallar y el 68% cae
  en los controladores — se paga fichero a fichero, como el formato. El porqué
  está medido en `docs/migracion/12-larastan-nivel-7.md` y resumido en el propio
  `phpstan.neon`. Lo que no se puede arreglar va anotado en
  `phpstan.neon` **con nombre, motivo y `count`** — nunca en un baseline
  generado, que los escondería.
- **Rector** está configurado y sin correr: por carpeta y revisando cada diff.

**La regla para el código roto: sin ruta y roto se borra; con ruta y roto se
documenta.** Borrar un endpoint enrutado convierte un 500 en un 404 sin decirle
a nadie qué pretendía hacer esa pantalla. Los que están rotos a propósito
—porque arreglarlos exige una decisión del colegio— están en
`docs/migracion/05-codigo-muerto-y-roto.md` con su test fijando el error exacto.

## Despliegue: lo copiado y lo compartido van al revés de lo que parece

Quince colegios, cada uno con su subdominio, su base de datos y su copia.
(Eran dieciséis hasta el 25 ago 2026, cuando uno se dio de baja y se borró entero
del servidor. **Las cifras fechadas antes de ese día dicen dieciséis y así se quedan**,
porque se midieron sobre dieciséis: lo que se actualizó fue lo que sigue vivo.)

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
