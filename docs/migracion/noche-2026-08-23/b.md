# Lote B — Ordinales de disciplina y ciudades

> Sesión `8myvc-2f`, árbol `.worktrees/b`, rama `fix/lote-b-ordinales-ciudades`,
> base `simonbolivar_testing_b`. Noche del 22 al 23 de agosto de 2026.
> Secciones asignadas del [05](../05-codigo-muerto-y-roto.md): **§85–88**.

La pregunta del lote eran trece rutas repartidas entre cuatro controladores que
no se parecen en nada: un catálogo geográfico, el manual de convivencia, el
registro de auditoría y la edición de una falta. Lo que sí tienen en común es la
forma en que se encontró lo que había, y conviene decirlo antes que los
hallazgos porque **las cuatro veces fue lo mismo**: ninguna se ve leyendo su
propio controlador.

| Cómo salió | Cuántas |
|---|---|
| Ejecutando **dos rutas seguidas** en vez de leer una | §85, §88 |
| Comparando un método con **su hermana** del mismo fichero | §86 |
| **Greppeando la tabla en todo `app/`** en vez de leer el fichero del lote | §87, §88 |

---

## Lo que se arregla, y lo que se anota

| § | Qué | Qué se hizo |
|---|---|---|
| §85 | Una ciudad sin país dejaba su pantalla en **500** | **arreglado** |
| §85 | Borrar una ciudad deja las fichas apuntando a un id borrado | anotado — decisión del colegio |
| §86 | `disciplina/update` escribía y luego reventaba, por **dos** caminos | **arreglado** |
| §86 | `disciplina/store` con un alumno inexistente: 500 de la clave ajena | anotado — lo para el esquema y **no escribe** |
| §87 | Editar un ordinal **reescribe la falta ya sancionada** | anotado — decisión del colegio |
| §87 | Borrarlo deja la falta **sin el artículo que citaba** | anotado — decisión del colegio |
| §87 | Ninguna de las cinco compara el año del ordinal con el del usuario | anotado — familia de las 44 |
| §87 | Cuatro rutas contestan «Cambiado»/«Eliminado» sin tocar una fila | anotado — visible en 16 colegios |
| §88 | El borrado de una bitácora **no se leía** y **no se firmaba** | **arreglado** |
| §88 | `bitacoras/destroy` borra la de cualquiera sin preguntar de quién es | anotado — decisión del colegio |
| §88 | `postStore()` y `putUpdate()` vacíos y **sin ruta** | **borrados** |

Cuatro commits, uno por sección. **Cada arreglo se revirtió por separado** y se
contó cuántos tests caen; los números están en cada sección.

---

## §85 — Una ciudad sin país dejaba su pantalla en 500

`ciudades/guardar-ciudad` escribe `pais_id` **tal como llega**, y la columna
admite NULL. `ciudades/datosciudad/{id}` —lo que abre la pantalla de editar la
ficha de un alumno— hacía `$pais[0]->id` sin mirar si había país. O sea que
guardar una ciudad sin país deja la ficha de todo alumno nacido ahí sin poder
abrirse, con **500 «Undefined array key 0»**.

Las dos rutas están vivas y las dos son alcanzables. Leyendo `datosciudad` sola
no se ve nada: hace falta la otra para fabricar la fila. **Se midió ejecutando
las dos, en ese orden.**

Ahora devuelve **las mismas seis claves** con el país en null. No se encoge la
respuesta: eso sería contrato con dieciséis copias del front, y quien la llama ya
sabe tratar el `[]` de la ciudad que no existe.

> Revertido el arreglo, cae **exactamente 1** test.

### Lo que se midió y no se toca

**Las cuatro lecturas que llevan solo `auth.token`** —`datosciudad`,
`departamentos/{pais_id}`, `paisdeciudad`, `por-departamento`— era la pregunta
del lote: *¿qué ve un alumno por ahí?* La respuesta es **el catálogo geográfico y
nada más**: ciudad, departamento, país. Ni un dato de una persona. Es un
veredicto, no un hueco pendiente.

**Borrar una ciudad deja las fichas apuntando a un id borrado.** El borrado es
blando y la ciudad sale de las cuatro lecturas, pero los alumnos nacidos ahí
conservan su `ciudad_nac`. Y **no hay ruta que la devuelva**: `restaurar` existe
en alumnos, perfiles, académico y estructura, y no en catálogos. Es la misma
forma que borrar un grado ([§70](../05-codigo-muerto-y-roto.md)) y como aquella
espera decisión del colegio. En el seed son **10 alumnos** para la ciudad más
usada.

**`deleted_by` se queda en null** al borrar una ciudad, porque `Ciudad` borra con
el `SoftDeletes` de Eloquent, que solo escribe la fecha. No se arregla: rellenarla
aquí y no en los otros diez catálogos es un criterio que solo vale en un fichero.
Queda con test, porque la columna existe y parece llena.

**`ciudades/departamentos-by-id` sale en `identificadores-del-cuerpo.py`** como
identificador sin comprobar propiedad. Queda juzgado: **falso positivo**. Un país
no es de nadie, no hay propiedad que comprobar, y lo que la separa de una lectura
pública es el guard — que está.

---

## §86 — `disciplina/update` escribía y luego reventaba, por dos caminos

`DisciplinaTest` cubría abrir, borrar y derivar una falta. Faltaba **editar**, que
es la que más se llama de las siete. Los dos 500 que había tenían **la misma
forma, y es la que los hace caros**: el UPDATE del proceso disciplinario **ya se
había hecho** cuando el método reventaba montando la respuesta. El front recibe
un error sobre una escritura que sí ocurrió, y lo que hace un front con eso es
volver a mandarla.

1. **Sin `dependencias`**, que es un campo opcional del cuerpo: `count(null)` es
   un `TypeError` en PHP 8. **Su hermana `postStore` preguntaba `is_array()`
   desde siempre.** Esa asimetría es lo que lo escondió — quien leyó `store` dio
   por hecho que `update` haría lo mismo.
2. **Con un `alumno_id` que no existe**, o que existe **sin matrícula viva** —la
   consulta de la ficha lleva `inner join matriculas`, así que un alumno retirado
   a mitad de año entra por aquí—: `[0]` sobre una lista vacía. **500 donde tocaba
   404** ([§52](../05-codigo-muerto-y-roto.md)). Las tres rutas que comparten esa
   consulta pasan ahora por un helper.

> Revertido cada arreglo por separado: el `is_array` cubre **1** test, el 404 de
> la ficha cubre **3** — sus tres sitios menos `store`, que no llega.

### La tercera hermana contesta otra cosa, y no se toca

`disciplina/store` con ese mismo alumno inexistente **no llega a la ficha**: lo
para la **clave ajena de `dis_procesos`** tres líneas antes, con un 500 de MySQL
que trae el nombre de la restricción y el SQL entero. Es la misma forma que la
[§78](../05-codigo-muerto-y-roto.md): tres rutas hermanas y **lo que las separa no
es el código sino el esquema**.

Se mide y se anota. Lo que salva la situación es que **no escribe**, y taparlo
con una comprobación aquí y no en las otras noventa escrituras del sistema es
cambiar un 500 honesto por un criterio que solo vale en un fichero.

---

## §87 — Editar un ordinal reescribe la falta ya sancionada

`OrdinalesController` era **1 de 6 comprobadas**, el peor que quedaba. Y ya se
había leído entero la noche del 21 buscando otra cosa —de ahí salió una
inyección, la [§55](../05-codigo-muerto-y-roto.md)—. Volver con otra pregunta es
justo cuando se escapan las de esta: **medir una ruta no es haberla juzgado**, y
un fichero con anotaciones recientes parece mirado.

Lo útil no salió de leer el controlador. Salió de **greppear `dis_ordinales` en
todo `app/`**:

| Quién más lee `dis_ordinales` | Para qué |
|---|---|
| `Models\Disciplina` (3 consultas) | el artículo, el texto y la página de cada falta |
| ↳ `BoletinesController`, `Boletines2`, `Boletines3` | los **tres boletines** impresos |
| ↳ `ChangeAskedController` | la pantalla de inicio **del propio alumno** |
| `GruposController` | la rejilla de convivencia por grupo |
| `YearsController` | los **copia** al abrir el año siguiente |

O sea: `dis_ordinales` no es una lista de configuración, es de donde salen las
palabras que el colegio le imprime a un menor. Y **`dis_proceso_ordinales` guarda
solo el id**: no hay copia del texto.

Las dos consecuencias se midieron **desde donde se ven** —`ChangesAsked/to-me`,
lo que el alumno recibe— y no desde la fila:

- **Editar un ordinal reescribe la falta ya sancionada.** El alumno ve otra falta
  sin que nadie haya tocado su falta. En el test: «Artículo 12 / Vocabulario soez
  en clase» pasa a «Artículo 99 / Agresión física a un compañero» sin tocar
  `dis_procesos`.
- **Borrarlo la deja en pie y sin el artículo que citaba.** El JOIN es `LEFT`, así
  que la falta **sigue saliendo** con `ordinal`, `descrip_ord` y `pagina` en null,
  y la fila del enlace sigue viva. Misma forma que borrar un grado ([§70](../05-codigo-muerto-y-roto.md)).

**Ninguna se arregla.** Congelar el texto al sancionar es decisión del colegio, y
además hoy es lo que permite corregir una errata del manual sin rehacer las
faltas. Va a `## PARA JOSETH`.

### Y tres cosas más, medidas

**Ninguna de las cinco compara el año.** `postStore` toma el `year_id` del
cuerpo, `update` y `destroy` no lo miran, y `guardar-valor` puede **mover un
ordinal de año** —`year_id` es una columna real y `ColumnaSegura` solo prohíbe el
id y las de auditoría—. Como `YearsController` los copia año a año, tocar los de
un año cerrado reescribe faltas ya sancionadas de ese año.

Es la familia de las **44 rutas de escritura con solo `auth.personal`** que
Joseth decidió no cerrar para no dejar fuera a un coordinador sin rol. Se mide,
no se cierra.

> `ordinales/destroy` sale en `identificadores-del-cuerpo.py`. Queda juzgado:
> **no es falso positivo**, y lo que no comprueba **no es de quién es el ordinal**
> —son todos del mismo colegio— **sino de qué año**. La herramienta busca
> propiedad; aquí lo que falta es pertenencia a un periodo de tiempo, y por eso
> ninguna señal suya lo iba a nombrar bien.

#### Y sus dos gemelas salen en la mitad limpia de la tabla, que es peor

`ordinales/guardar-valor` y `ordinales/guardar-valor-config` reciben `ordinal_id`
y `config_id` por el cuerpo y **no los comprueba nadie** — está medido arriba: sin
ellos contestan «Cambiado» igual—. Pero `identificadores-del-cuerpo.py` las marca
**`prop = sí`**, o sea del lado de las que ya tienen quien las mire.

El porqué lo encontró el lote C y lo confirmó quien coordina: la señal de
propiedad del script es una regex con la raíz **`exig`**, puesta a propósito para
cazar los helpers que este repo conjuga de dos maneras (`exigirQue…` / `exigeQue…`).
Y esa raíz **se traga también `ColumnaSegura::exigir`**, que no comprueba
propiedad de nada: **valida un nombre de columna**. En estos dos métodos ese es el
único `exig` que hay.

Es la misma trampa de la [§53](../05-codigo-muerto-y-roto.md) girada del revés:
allí el detector se quedó **ciego ante un nombre nuevo**; aquí **ve un nombre que
no es**. Las dos veces la consecuencia es la misma —una ruta que nadie vuelve a
mirar— y las dos veces se descubre ejecutando la ruta, no leyendo la tabla.
**La corrección del script es del lote H**; aquí solo queda escrito que estas dos
ya están juzgadas, y que lo están **a mano**.

**Cuatro rutas confirman una escritura que no ocurrió.** Sin su identificador en
el cuerpo, `update`, `destroy`, `guardar-valor` y `guardar-valor-config`
contestan 200 con «Cambiado» o «Eliminado» y **no tocan una sola fila**: el
`WHERE id=?` compara contra null y no casa. Un cliente que pierda el campo por el
camino ve «guardado».

Es la familia de `respuestas-que-mienten.py` por un camino que la herramienta no
ve: **no hay nada que «frene» la escritura, es que el `WHERE` no encuentra a
quién escribir.** Queda escrito como una ceguera del detector, no como un fallo
suyo — la serie sigue agotada por su propia definición.

> **Lo que había que descartar, descartado con número: no es masivo.** `WHERE id
> = NULL` no casa con ninguna fila, no con todas. Y se comprobó al revés: quitando
> el `WHERE` de `guardar-valor`, el test cae. Si algún día alguien reescribe esto
> con otro `WHERE`, esos contadores lo dicen.

**Un alta sin `year_id` la para el esquema**, no el código: `NOT NULL` con clave
ajena a `years`. 500 de MySQL, y no escribe. Misma familia que la §78 y que la
tercera hermana de la §86.

---

## §88 — El borrado de una bitácora no se leía ni se firmaba

`bitacoras` guarda `descripcion`, `affected_person_name` y los valores viejo y
nuevo de lo que se tocó: es el rastro con el que un colegio contesta «¿quién
cambió esta nota?» y «¿quién intentó entrar en mi cuenta?». **El seed no trae ni
una fila**, así que los tests se fabrican las suyas.

Dos mitades, las dos vistas ejecutando **borrar y volver a listar**:

- El listado **no miraba `deleted_at`**: la fila borrada seguía saliendo en el
  mismo listado desde el que se borra. Un botón de borrar cuyo efecto no se ve en
  la pantalla que lo contiene.
- **`deleted_by` se quedaba en null** teniendo el `$user` resuelto dos líneas
  arriba. En un registro de auditoría es lo peor que puede faltar: **borrar el
  rastro no dejaba rastro.**

> Revertida cada mitad por separado, cae la suya. Van en tests distintos a
> propósito, para que al caer digan cuál de las dos se rompió.

`postStore()` y `putUpdate()` estaban **vacíos y sin ruta** —comprobado contra
`route:list`, que sigue dando **539**—. Se borran: sin ruta y roto se borra.

### Sobre qué población se cerró — que es lo que hay que escribir

`bitacoras` la leen **siete consultas en cuatro ficheros**, y **no todas filtran
igual**. Esto cierra **una**:

| Quién lee | ¿esconde las borradas? |
|---|---|
| `bitacoras/{user_id?}` | **ahora sí** |
| `ChangesAsked/to-me` · intentos de login fallidos | sí |
| `HistorialCalc::intentos_fallidos_de_usuario` | sí |
| `historiales/sesion` · las bitácoras de una sesión | sí |
| `ChangesAsked/to-me` · `cant_cambios` de cada sesión | **no** |
| `HistorialCalc::historial_sesiones_de_usuario` · `cant_cambios` | **no** |
| `historiales/nota-detalle` · **quién cambió la nota** | **no** |

Y la que no filtra es la que más importa, **y es una asimetría a favor**: borrar
la bitácora **no** borra el rastro de quién cambió una nota. Eso es lo que evita
que `bitacoras/destroy` sea un borrador de auditoría. Queda con test para que
nadie la «uniformice» sin saber lo que apagaría.

Las seis restantes son de otros lotes y **no se tocan**.

### Descartado, y era lo contrario de lo que parecía

`bitacoras/destroy` borra por id, desde cualquier cuenta del personal, sin
comprobar de quién es. Y la lectura de **intentos de login fallidos sí filtra
`deleted_at`**, o sea que se puede quitar de la vista de alguien el aviso de que
intentaron entrar en su cuenta.

Pero **el límite de intentos no se cuenta desde `bitacoras`**: usa `RateLimiter`
(`Services\Login`, 5 intentos / 900 s). **Borrar una bitácora no abre ningún
candado** — solo esconde el aviso. Se midió porque la primera lectura sugería lo
contrario, y ese es exactamente el momento de medir.

---

## PARA JOSETH

Cinco, y ninguna se decide aquí.

1. **¿El texto de un ordinal se congela al sancionar?** Hoy editar un ordinal
   reescribe las faltas ya puestas, y el alumno las ve cambiadas en su pantalla y
   en el boletín. Lo bueno de como está: permite corregir una errata del manual.
   Lo malo: permite reescribir lo que se le imputó a un menor.
2. **¿Se puede borrar un ordinal citado en una falta?** Hoy sí, y la falta se
   queda en pie sin artículo. Misma pregunta que borrar un grado (§70).
3. **¿Debe `ordinales/*` comprobar el año?** Hoy cualquiera del personal edita el
   manual de convivencia de un año cerrado. Está en la familia de las 44 que ya
   decidiste no cerrar; esta es distinta porque lo que falta no es un rol, es el
   año.
4. **¿Quién puede borrar una bitácora, y la de quién?** Hoy cualquiera del
   personal borra la de cualquiera. Lo mismo con leerla.
5. **¿`ciudades/guardar-ciudad` debe exigir país?** Hoy escribe null y la columna
   tiene `DEFAULT 1` (COLOMBIA) que nunca se aplica porque el null es explícito.
   Elegir el default por ti sería decidir a qué país pertenece una ciudad.

## PARA OTRO LOTE

- **`YearsController:212`** (lote D) — al copiar los ordinales al año nuevo, el
  `INSERT` **no escribe `created_at` ni `updated_at`**: las filas copiadas nacen
  con fecha cero. Se ve en el seed, donde los ordinales tienen `created_at` null.
- **`tools/identificadores-del-cuerpo.py`** (lote H) — `ColumnaSegura::exigir`
  dispara la señal de propiedad por la raíz `exig`. Mis dos de `guardar-valor` ya
  están juzgadas a mano; el script sigue marcándolas limpias.
- **`historiales/nota-detalle` y los dos `cant_cambios`** (sin lote) — leen
  `bitacoras` sin `deleted_at`. **No es un fallo que haya que arreglar sin
  pensar**: en `nota-detalle` es justo lo que salva la auditoría de notas. Está en
  la tabla del §88.

## Lo que se nota en un colegio

- La ficha de un alumno nacido en una ciudad sin país **vuelve a abrir**. Si
  algún colegio tenía una así, esa pantalla estaba rota y ahora no.
- Editar una falta disciplinaria **deja de dar error después de haberla
  guardado**. Los usuarios que veían el error y volvían a darle a guardar dejan de
  duplicar el intento.
- El botón de borrar del listado de bitácoras **ahora borra de la vista**. Lo que
  se había «borrado» antes y seguía saliendo desaparecerá de golpe la primera vez
  que se abra esa pantalla después del despliegue.
- Nada más cambia de forma. Ninguna respuesta pierde ni gana claves.

## Migraciones

**Ninguna.** El esquema no se toca.
