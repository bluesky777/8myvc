# 8myvc — la API del sistema escolar MyVc

Laravel 13 + PHP 8.4, ~37.000 líneas en `app/`. **No es una aplicación Laravel
idiomática, y tratarla como si lo fuera rompe cosas**: 990 consultas crudas
(`DB::select/insert/update`), Eloquent marginal, 2 validaciones en todo el proyecto.
El framework casi no se toca, que es justo lo que hizo viable la migración.

> **Antes que este fichero: [`docs/migracion/ESTADO-ACTUAL.md`](docs/migracion/ESTADO-ACTUAL.md)** —
> qué se está haciendo ahora, qué sigue y qué espera una decisión de Joseth. Se actualiza
> **en el mismo commit que el trabajo**: un commit aparte al final es el que no se hace
> cuando la sesión se corta.
>
> El plan, las mediciones y las decisiones ya tomadas viven en `docs/migracion/` y **se leen
> antes de re-litigar nada**. El relato completo de cómo se llegó a cada regla de aquí —con
> sus fechas, sus errores y sus remediciones— está en
> [`00-historial-de-claude-md.md`](docs/migracion/00-historial-de-claude-md.md).

---

## Cifras vivas

**Ninguna se hereda ni se suma: se cuenta el día que se toca, en el ÁRBOL PRINCIPAL, sobre
`main` y DESPUÉS de fundir.** Una cifra contada en un worktree describe un árbol que mañana
no existe, y sumar dos cifras ciertas da una falsa. Ninguna de éstas la comprueba ningún
test, así que va con la orden que la rehace al lado.

| Qué | Hoy (20–21 sep 2026) | Orden |
|---|---|---|
| Rutas | **664** | ver la orden debajo de la tabla |
| Ficheros de controlador | **130** (129 controladores) | `find app/Http/Controllers -name '*.php' \| wc -l` |
| Clases de controlador | **132** | `grep -rhoE '^[[:space:]]*(final )?(abstract )?class [A-Za-z_]+' app/Http/Controllers \| wc -l` |
| Rutas públicas | **16** (`RutasPreLoginTest::TOTAL_PUBLICAS`) | correr el test, no restar |
| Pint, lista curada | **501** (`PASS`) | `composer run pint:test` |
| Pint, repo entero | **738**, con **177** avisos | `pint --test` |
| Larastan | `[OK] No errors` (~708 ficheros, medido en worktree) | `composer run stan` |
| Suite completa | **2.520** pruebas | `php artisan test` |
| Colegios | **16 + `demo`** | contar `/home/micolev1/*.micolevirtual.com/8myvc` |

```bash
# Las rutas: no hay jq ni python3 en el contenedor, así que las cuenta php.
docker exec 8myvc-app-1 sh -c \
  "php artisan route:list --json | php -r 'echo count(json_decode(stream_get_contents(STDIN))), PHP_EOL;'"
```

`130 − 1 = 129` porque `Concerns/ResuelveElUsuario.php` es un trait y no declara clase;
`132 − 129 = 3` porque `Alumnos/ImportarController.php` declara cuatro.

**Poblaciones de `users`** (base de desarrollo, un colegio, 20 sep 2026):

```sql
SELECT COUNT(*) FROM users WHERE deleted_at IS NULL;                    -- 2.358
SELECT COUNT(*) FROM users WHERE deleted_at IS NULL
       AND tipo NOT IN ('Alumno','Acudiente');                          -- 75 personal
SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_superuser=1; -- 11
```

> **De las 22 cuentas de tipo `Usuario` (administrativos), NINGUNA tiene ficha en
> `profesores`.** Cualquier consulta que saque el nombre de una persona uniendo sólo contra
> `profesores` **deja sin nombre justo a secretaría**, que es quien usa media aplicación.

---

## Una ruta nueva es una decisión, no un efecto secundario

Mueve este fichero y **tres** instantáneas: `rutas.json`, `guards-por-ruta.json` y
`guard-por-familia.json`. Y a veces más:

| Además… | Se mueve también |
|---|---|
| **estrena familia**, o su familia pasa de 1 a 2 hermanas con guard | `familias-que-nunca-entran-en-el-candado.json` |
| es **pública** | `AutenticacionTest::SIN_GUARD` y `RutasPreLoginTest::TOTAL_PUBLICAS` |
| es pública **y escribe**, en familia con <2 hermanas guardadas | `FamiliasQueNuncaEntranTest` (hoy **26**) |

`familias-que-nunca-entran-en-el-candado.json` lista las familias con **menos de dos
hermanas con guard**, que el candado de consistencia descarta con un `continue` y no mira
nunca. **«1 de 1» ahí no es un agujero** —la ruta tiene su guard y su test—; **«0 de 1» sí
tiene la forma de uno**, y se acepta escribiendo por qué no lo es, **nunca regenerando y
pasando**.

Una familia nueva entra **entera en un commit**: a trozos, el censo la recogería como
«1 de 1» y la sacaría después.

Las tablas de `DESPLIEGUE.md` **no** se tocan al añadir una ruta: son lo que se midió el día
de un despliegue.

---

## Idioma y convenciones

Código nuevo, comentarios, mensajes de commit y documentación **en español**, salvo los
términos del framework (*guard*, *seeder*, *snapshot*, *middleware*). Los comentarios
explican **por qué**, no qué: casi todo lo escrito aquí es el resultado de una medición o de
una decisión, y sin el porqué se deshace solo.

**En código nuevo se usan los códigos HTTP correctos** —403, 404, 422, 429— aunque el legacy
de al lado devuelva 400 para todo.

---

## Comandos

Todo corre dentro del contenedor (`kool` sobre docker compose):

```bash
docker exec 8myvc-app-1 php artisan test                       # las tres testsuites
docker exec 8myvc-app-1 php artisan test --testsuite=Contrato  # solo contrato
docker exec 8myvc-app-1 php artisan test --filter=NotasTest    # una clase
docker exec 8myvc-app-1 composer run pint                      # ⚠️ ESCRIBE
docker exec 8myvc-app-1 composer run pint:test                 # comprueba
docker exec 8myvc-app-1 composer run stan                      # larastan nivel 7

tools/construir-bd-test.sh                                     # crea/reconstruye la BD de tests

# Varias sesiones a la vez: un árbol y una base por sesión.
tools/worktree-de-sesion.sh b fix/lo-que-toque
docker exec -w /app/.worktrees/b -e DB_TEST_DATABASE=simonbolivar_testing_b \
    8myvc-app-1 php artisan test
```

`vendor/` **no se enlaza con symlink en un worktree**: `__DIR__` resuelve los symlinks y el
árbol acaba cargando el `app/` del principal, con los tests en verde.
`tools/worktree-de-sesion.sh` lo copia con enlaces duros.

La base de tests **no** se reconstruye entre tests (cada uno corre en una transacción); sólo
al cambiar esquema o seed. Si alguna vez corres `config:cache` en local, `config:clear`
antes de los tests.

### LA SUITE ENTERA SE PAGA ANTES DE DESPLEGAR, NO ANTES DE FUNDIR

**Regla de Joseth.** La suite completa son 2.520 pruebas y ~23 minutos, y aquí trabajan ocho
sesiones sobre el mismo docker. **Fundir es barato de deshacer; desplegar no**: lo desplegado
viaja a dieciséis colegios copia a copia, y allí el rojo lo descubre una secretaría.

Lo normal es el subconjunto de lo que cambió, y lo calcula:

```bash
python3 tools/tests-que-tocan.py     # salida 0 -> imprime el --filter seguro
                                     # salida 2 -> PREGUNTAR a Joseth, no decidir solo
```

Tocar `routes/`, `database/migrations/`, `database/schema/`, `config/`, `tests/TestCase.php`
o `composer.json|lock` significa **«no hay subconjunto que cubra lo que cambiaste»** — eso es
un **hecho**, no un permiso. Correr la entera es una **decisión, y es de Joseth**: se corren
las clases del dominio tocado y se le ponen las dos cifras delante para que elija.

> Toda corrida de la suite entera lleva `COBERTURA_RUTAS=/tmp/rutas-tocadas.txt`, que es lo
> que mantiene vivo el mapa del que sale el subconjunto.

### Cuatro formas en que una suite miente

**Se comprueba ANTES de lanzar, no cuando el resultado sale raro.**

| Enfermedad | Cómo se ve | Delator |
|---|---|---|
| **Muerta** (le podan el árbol, se queda sin `vendor/`) | exit 0, `grep '⨯'` da 0 rojos | **falta la línea `Tests:`** |
| **Contaminada** (dos suites contra la misma base) | verde, con rojos sueltos por clases sin relación y `Deadlock found` | **tardó de más** |
| **Cortada por timeout** del que la lanzó | `context canceled`, sin línea `Tests:`, y el `php` sigue vivo | ídem |
| **Base desfasada** (`migrate:status` dice 0 pending y la columna NO existe) | rojos que no son del código | mirar `information_schema`, no `migrations` |

```bash
# ANTES de lanzar. La pregunta no es «cuántas hay» sino CONTRA QUÉ BASE:
docker exec 8myvc-app-1 sh -c 'for pid in $(ps -eo pid,args | grep "[p]hpunit" | awk "{print \$1}"); do
  db=$(tr "\0" "\n" < /proc/$pid/environ 2>/dev/null | grep "^DB_TEST_DATABASE=" | cut -d= -f2)
  echo "$pid -> ${db:-POR_DEFECTO}"
done'
```

Buscar por el **árbol** no vale: la contención es **por base**. Parar una suite tampoco es
matarla — matar el `docker exec` deja vivo el `php` de dentro. Para que el corte de quien la
lanza no la toque, `docker exec -d … > /tmp/<x>.txt 2>&1` y se consulta ese fichero dentro
del contenedor.

**Duraciones sanas** (contenedor compartido, o sea un techo):

| orden | tarda |
|---|---|
| `--testsuite=Contrato` | 840–1.360 s |
| `php artisan test` | ~1.359 s |
| `--filter=<una clase>` | 10–40 s |

Para una suite **que todavía corre**, se mira la CPU del **hijo** (`phpunit`), nunca del
padre (`artisan test`, que espera y parece muerto): ~0 % en `S` es atascado; 30–35 % en `R`
es normal. Esa ratio **no** dice si hay contención — el contenedor tiene 10 núcleos sin
cuota. Para la carga, `/proc`, no `docker stats`, que salta entre 982 % y 261 % en muestras
consecutivas.

---

## Herramientas de medición (`tools/`)

Ninguna se ejecuta sola; todas contestan una pregunta que no se puede contestar leyendo el
código. Cada una lleva su uso en la cabecera.

| Herramienta | Contesta |
|---|---|
| `cobertura-de-rutas.py` | qué rutas tienen la respuesta comprobada por algún test |
| `indices-que-faltan.php` | qué consultas recorren una tabla sin índice aplicable |
| `consultas-lentas.py` | qué consultas se llevan el tiempo en producción |
| `columnas-en-los-modelos.php` | reescribe las `@property` de los modelos desde el esquema real |
| `route-inventory.php` · `route-match-check.php` | la tabla de rutas, comparable 1:1 |
| `inventario-autorizacion.py` · `auditar-autenticacion.php` | qué guard cubre cada ruta |
| `respuestas-que-mienten.py` | qué métodos frenan la escritura y responden 200 igual |
| `interruptores-que-nadie-lee.py` | qué columnas `tinyint(1)` no decide nadie |
| `identificadores-del-cuerpo.py` | qué rutas reciben un id por el cuerpo que no comprueba nadie |
| `escrituras-en-las-notas.py` | qué métodos escriben notas sin mirar el interruptor del periodo |
| `coste-del-recalculo.php` | qué cuesta recalcular una definitiva |
| `secciones-citadas.py` | qué §§ cita el código y ya no existen — **después de cada renumerado** |
| `consultas-en-bucle.py` | en qué profundidad de bucle vive cada consulta — ordena candidatos, no mide coste |
| `guardas-sin-respaldo.py` | qué métodos dependen enteros del middleware de su ruta — **cada fila se lee** |
| `verdad-laxa-que-escribe.py` | dónde una cadena cualquiera vale por «sí» y gobierna una escritura |
| `prevuelo-del-horario.php` | si los datos de un colegio sirven para cuadrar un horario (`--lecciones`) |
| `deriva-del-horario.php` | si las columnas de día cuadran con la versión oficial — sin versión sale `2`, NO MEDIDO |
| `ensayo-de-la-tanda.sh` | si la tanda de migraciones corre entera sobre una copia real, y cuánto tarda |
| `comprobar-el-horario.php` | si el módulo de horario **llegó** a un colegio (200 con 0 ≠ 404 ≠ 500) |
| `imports-de-facades.php` | qué `use` resuelven por alias — **`--dry-run` NO es opcional; sin él ESCRIBE** |
| `requisitos-de-matricula.php` | cómo usa un colegio de verdad los requisitos |
| `lo-que-reparte-una-columna.py` | qué instantáneas se mueven el día que una tabla gane una columna |
| `tests-que-tocan.py` | qué tests hay que correr para lo que cambió, y cuándo NO basta un subconjunto |
| `ensayo-del-alter-en-maria.sh` | si el `ALTER` bloquea el guardado de notas en **MariaDB** — la señal es la LATENCIA |
| `correo-de-los-colegios.sh` | qué instalaciones no pueden mandar correo — `lal` queda fuera: sale `2`, nunca verde |
| `zona-de-los-colegios.sh` | qué hora cree que es el MySQL de cada colegio — `SYSTEM` no es respuesta: la cifra es el DESFASE |

Y una que **no** está en `tools/` y contesta la pregunta contraria:
`tests/Barrido/SuperficieDeUnTokenTest.php` golpea la API entera con un token y mira **el
resultado** —qué datos personales salen y qué filas se escriben— en vez de la petición.

```bash
docker exec -e BARRIDO_TIPO=Alumno 8myvc-app-1 php artisan test --group=barrido
```

### Cómo mienten los detectores (las cuatro, vividas)

1. **Ninguna imprime `OK` sin decir su población.** Un «0 encontrados» no distingue *«revisé
   466 y ninguno lo era»* de *«no revisé nada»*. **El primer sitio donde mirar cuando el
   número sale raro es el detector**, no el código.
2. **Puede contar bien un síntoma y no estar contando la causa.** Repetir la medición da lo
   mismo otra vez; hay que comprobar que **detecta lo que dice su nombre**.
3. **Puede contestar bien a la pregunta equivocada.** *Ser ancestro* es una relación del
   grafo; *estar en el árbol cuando se midió* es una relación con el reloj. De ahí: **una
   medición se anota con el hash exacto y su hora**, nunca con el nombre de una rama.
4. **Puede salir truncado y plausible.** `| head` no avisa. **Un número se cuenta con
   `wc -l`, y la lista se enseña aparte.** Nunca al revés.

Y una regla hermana: **una cifra se publica con la orden que la produjo**
—`Tests: 1948 (--testsuite=Contrato)`—, porque el número no lleva dentro de qué habla.

> Antes de pasarle Pint a un fichero de `tools/`, correr `stan` detrás: esa carpeta no está
> en el script `pint` y ninguna suite la ejecuta, así que ahí Pint puede romper en ejecución
> sin poner nada en rojo.

---

## Arquitectura

### El objeto `$this->user` NO es un modelo

`User::fromToken()` devuelve un **`stdClass`**: persona + grupo + año + periodo +
configuración del colegio + roles + permisos, aplanado en un objeto con ~40 columnas de un
`switch` de cuatro ramas (Profesor / Alumno / Acudiente / Usuario). Lo monta
`App\Services\ContextoDeUsuario`; el token lo valida `App\Services\Sesion`, y ninguno sabe
del otro.

- En los controladores llega por el trait `Concerns\ResuelveElUsuario`, que lo resuelve **en
  la primera lectura**, no en el constructor. Un constructor que resuelva al usuario rompe
  `route:list` y `route:cache` — hay un test que lo impide.
- `$user->user_id` es el id de `users`; `$user->persona_id` es el de la ficha. **No son lo
  mismo.**

### Rutas y autorización

`routes/api/*.php`, un fichero por dominio. **El guard va por defecto a toda la API** y las
excepciones públicas se marcan una a una.

| Guard | Qué exige |
|---|---|
| `auth.token` | sesión válida |
| `auth.personal` | que sea personal del colegio, no alumno ni acudiente |
| `boletin.propio` | que el boletín pedido sea suyo o de un acudido |
| `persona.propia` | que el id del cuerpo o de la URL sea suyo |

**Regla de negocio, confirmada y no re-litigable: un alumno solo ve lo suyo; un acudiente, lo
suyo y lo completo de sus acudidos.** Los métodos conservan sus nombres viejos (`getIndex`,
`putGuardarValor`): renombrarlos es cosmético y va después.

**Las públicas no se cuentan con un `grep`.** Hay ~21 rutas `api/` sin `auth.token` y varias
contestan 401 igual porque se defienden dentro del método: **quitarle el guard a una ruta no
la hace pública**. El número sale del **test**, y la resta sólo sirve para comprobarlo
después.

> **`auth.personal` deja pasar a 75 cuentas, de las que 53 son docentes.** Cuando lo que
> decide la ruta es *de quién es un cobro*, *qué política aplica al colegio entero* o *si a
> un alumno le cuentan como cero las casillas que su profesor no calificó*, el permiso va
> **partido**: `auth.personal` en la ruta y el permiso concreto **dentro del método**. Quien
> lea dos familias seguidas tiene que poder distinguir una decisión de un olvido.

### El esquema vive en un volcado, no en migraciones

`database/schema/mysql-schema.sql` es la verdad: 90 tablas congeladas desde producción. Las 3
migraciones viejas están archivadas en `legacy/`.

- **Ningún cambio de esquema a mano en phpMyAdmin: migración o no existe.**
- **Una migración que pise un valor que ya existía anota antes lo que había**, con
  `App\Support\RastroDeLaMigracion::anotar()`: una sentencia con el mismo `WHERE` del
  `UPDATE`, corrida delante, y lo que devuelve se imprime. De 49 migraciones sólo **2**
  sobrescriben algo —las otras 5 que tocan datos son aditivas—, así que no es trabajo de
  cada despliegue: es el trabajo del día que se destruye algo. La que se saltó esta regla
  se llama `la_casilla_vacia` y costó una noche averiguar si 20.655 notas eran
  recuperables.
- Las columnas de los modelos se generan desde ese volcado, no se escriben a mano.
- `migrate:fresh` no sirve aquí: un colegio nuevo se crea **copiando la base de otro**.

> **Una columna sin pantalla no la escribe nadie.** Es el caso `profesores.tono`, cometido
> cinco veces en un mes: la columna existe, alguien la lee, y **en toda la API no hay una
> sola escritura**, así que sale vacía en los diecisiete para siempre. Se comprueba antes de
> añadirla, y **al darle dueño a una columna se repasan TODOS los caminos que escriben esa
> tabla**, no sólo el que se está tocando.
>
> Y **antes de escribirla** se mide lo que reparte: `tools/lo-que-reparte-una-columna.py`
> dice qué instantáneas se mueven, porque `SELECT *` la lleva dentro de boletines, años e
> informes.

### Tests de contrato

No comprueban que el código esté bien: comprueban que **la respuesta no ha cambiado**.
`tests/Contrato/` con snapshots en `Snapshots/`. Lo que los hace encontrar cosas es **mirar
el resultado y no el estado**: el píxel en vez del 200, la forma de la hoja de Excel en vez
de los bytes, el viaje de ida y vuelta en vez de una llamada. Cómo se usan y qué no cubren:
`docs/migracion/03-tests.md`.

> **El orden de registro de las rutas.** Laravel sirve **la primera que casa**, así que un
> comodín declarado antes que una ruta literal se la traga (`…/{lote}` comiéndose `…/campos`,
> `…/{codigo}` comiéndose `…/pendientes`) **sin cambiar el conjunto de rutas**: `rutas.json`
> queda byte a byte igual. Lo caza `test_ninguna_ruta_literal_la_atiende_un_comodin`, que le
> pregunta al router con `getRoutes()->match()`. **`route:list` ordena alfabéticamente y no
> por orden de registro**, así que la forma natural de comprobarlo miente.
>
> La lección es del candado, no del router: *un detector puede contar bien un síntoma sin
> contar la causa*, y éste llevaba el nombre de la causa escrito en su docblock. **Cuando un
> test dice que protege algo, la forma de saberlo es romper ese algo y verlo en rojo.**

---

## Calidad

**Pint** solo sobre lo que escribió la migración (la lista curada de `composer.json`).
Reformatear los ficheros de legado de golpe sería un diff ilegible: **se formatea el día que
se toca cada fichero**, y si no se hace, se dice con su motivo para que no se lea como un
olvido. Los 177 avisos del repo entero son eso: `app/Http` 106 y `app/Models` 48, el 86 %.

| | |
|---|---|
| `composer run pint:test` | **comprueba**, no toca nada — **es el que dice si el repo está verde** |
| `composer run pint` | **ESCRIBE** |
| `pint --test` a secas | mide **todo el repo**, incluido lo que no se formatea a propósito |

> ### ⚠️ Pint deja `use Log;` en los controladores viejos, y eso pone la suite en rojo
>
> Visto tres veces en tres ficheros. El fichero viejo usa `Log::info(...)` resolviendo por el
> array `aliases` de `config/app.php`; Pint ordena los `use` y **añade `use Log;`**, que
> sigue resolviendo por alias. **No rompe en ejecución**, así que lo único que se pone rojo
> es `AliasDeFacadesTest`, **en la testsuite `Unit`** — justo la que no corre quien publica
> con `--testsuite=Contrato`.
>
> ```bash
> php tools/imports-de-facades.php --dry-run   # dice qué resolvería por el alias
> php tools/imports-de-facades.php             # ⚠️ SIN EL FLAG, ESCRIBE
> ```
>
> **Y no sustituye a `pint:test`:** arregla el alias **en el mismo sitio** y no mira el
> orden, así que deja el `use` donde Pint no lo quiere. Las dos son ciertas y ninguna cubre a
> la otra. **Después de esta herramienta, `pint:test`. Siempre.**

> ### Un test que lee un fichero como fuente se rompe al formatearlo
>
> `PoblacionDePerfilesTest` buscaba `/\n\tpublic function/` **con un tabulador**, así que
> pintar el controlador lo puso rojo diciendo que habían cambiado los métodos. No cambió
> ninguno: cambió la indentación. Antes de pintar un fichero:
>
> ```bash
> grep -rl "<NombreDelFichero>" tests/
> ```

- **Larastan nivel 7**, y no baja. El **6 se salta a propósito**: sus 1.940 errores son
  anotación pura y ninguno señala código que pueda fallar
  (`docs/migracion/12-larastan-nivel-7.md`). Lo que no se puede arreglar va en `phpstan.neon`
  **con nombre, motivo y `count`** — nunca en un baseline generado, que los escondería.
- **Rector** está configurado y sin correr: por carpeta y revisando cada diff.

**La regla para el código roto: sin ruta y roto se borra; con ruta y roto se documenta.**
Borrar un endpoint enrutado convierte un 500 en un 404 sin decirle a nadie qué pretendía
hacer esa pantalla. Los rotos a propósito están en
`docs/migracion/05-codigo-muerto-y-roto.md` con su test fijando el error exacto.

---

## Despliegue: lo copiado y lo compartido van al revés de lo que parece

**Dieciséis colegios más `demo`**, cada uno con su subdominio, su base de datos y su copia.
**El bucle de despliegue alcanza a los dieciséis y a `demo`**, así que quien despliegue
contando quince verá un colegio de más y tendrá que decidir a las tres de la mañana si es
legítimo. Lo es.

- `app/`, `routes/`, `config/`, `.env`: **copia real en cada colegio**. Un arreglo fusionado
  **no está desplegado**; llega colegio a colegio.
- `vendor/`: **compartido por symlink**. Un `composer install` dentro de un colegio sigue el
  symlink y cambia todos los que cuelguen de esa carpeta.
- `storage/`: propia de cada colegio.
- **Producción corre MariaDB 10.5.25, no MySQL 8.** El docker corre MySQL 8.0.42. Un
  `JSON_TABLE`, un `LATERAL` o un `->>` pasan la suite entera y revientan en los dieciséis.
  Lo que sí hay: columnas al instante desde la 10.4.

Hay **cuatro clientes**, no uno: `myvc_front` (AngularJS, uno por colegio), `myvc_front_2`
(Angular, solo el PIAR), `myvc_flutter` (**una sola app para todos** — lo que la rompa los
rompe a todos) y esta API. Un arreglo del front que exponga un endpoint no se publica hasta
que el guard del backend esté **desplegado**, no solo fusionado.

> Y por lo mismo, **un endpoint no aprende a hacer algo nuevo si una versión vieja de la app
> convive meses con la nueva**: si `notas/update` aprendiera a nivelar, un 95 tecleado desde
> un móvil sin actualizar se guardaría topado. Ahí va ruta nueva
> (`docs/migracion/22-nivelaciones.md`).

Los comandos están en `docs/DESPLIEGUE.md` y el porqué en `docs/DESPLIEGUE-REFERENCIA.md`.

---

## Rendimiento

Medido, no supuesto: `docs/migracion/02-plan-rendimiento.md` lleva la cuenta de qué se probó
y qué resultó ser ruido. Dos interruptores, los dos apagados de serie:
`CONSULTAS_LENTAS_MS` (registro de consultas lentas, porque en cPanel no hay acceso al
`slow_query_log`) y `CONTEXTO_SEGUNDOS` (caché del contexto de usuario, que medida ahorra
0,75 ms y por eso no se enciende).

**Antes de optimizar algo: medirlo. Antes de crear un índice: `EXPLAIN`.**
