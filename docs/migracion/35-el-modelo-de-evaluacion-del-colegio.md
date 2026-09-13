# 35 · El modelo de evaluación es una elección del colegio — el plan del backend

> **Qué es esto.** Las 22 decisiones que Joseth tomó el 13 sep 2026
> (`myvc_front/DECISIONES-MODELO-DE-EVALUACION.md`) cierran las nueve que quedaban
> abiertas entre [28](28-competencias-e-indicadores.md) §7 y la investigación del
> front. Este documento **no vuelve a decidir nada de eso**: traza **en qué orden se
> construye**, qué cuesta cada trozo, qué test lo sujeta y **qué falta todavía por
> decidir** para poder escribir la fase que lo necesita.
>
> **Nada de esto está construido.** No hay código nuevo en este commit.

> ## Lo que comprobé y lo que NO pude comprobar
>
> Todo lo de abajo está medido el **13 sep 2026** sobre `main` en `c0ed278`, en el
> árbol principal. Las dos cosas que **no** pude medir, dichas antes que las que sí:
>
> 1. **No pude correr `route:list --json`: el contenedor no está levantado**
>    (`docker ps` vacío). Así que la cifra de rutas de abajo **no es un conteo, es
>    una cota inferior razonada** — y por eso el plan la manda contar el día que las
>    rutas entren, que es lo que ya exige `CLAUDE.md`.
> 2. **No pude correr la suite.** Las 1.926 de la rama del `ALTER` las leí **del
>    mensaje del commit**, no de una ejecución mía. Están abajo con lo que ese
>    mensaje dice y con el árbol contra el que corrieron.

---

## 1. Siete correcciones al encargo, medidas

Ninguna cambia una decisión de Joseth. Cinco cambian un número o un paso del plan, y
dos —la 4 y la 5— **bloquean una fase** hasta que alguien decida.

### 1.1 · La base de rutas no es 577: `main` ya lleva dos más

`DECISIONES-MODELO-DE-EVALUACION.md` §7 y el [28](28-competencias-e-indicadores.md)
§6 dicen *«hoy hay **577** (4 sep 2026)»*. Era cierto el 4 de septiembre. Desde
entonces entraron en `main` dos rutas, cada una con su commit y su documento:

| commit | ruta | documento |
|---|---|---|
| `09cfd2b` | `GET horario/versiones/{id}/proyecto` | [23](23-horarios.md) §9 |
| `46c6660` | `GET sincronizacion/huella` | [34](34-la-huella-de-sincronizacion.md) |

`CLAUDE.md` dice **579**, contadas con `route:list --json` en `.worktrees/e5` el 7 sep
— y ese worktree **ya no existe**, así que el número está heredado, que es justo lo
que ese recuadro prohíbe. **La base de este plan es «579, por confirmar el día que se
toque»**, y el 577 del doc 28 §6 hay que corregirlo cuando se abra ese fichero.

> Es la tercera vez que esta cifra se mueve hacia abajo por herencia. No cuesta nada
> arreglarlo: se cuenta en el árbol donde se escribe, y se dice el árbol.

### 1.2 · Son dieciséis colegios y diecisiete carpetas, no quince

El documento de decisiones dice «los quince colegios» siete veces. Son **dieciséis
desde el 30 ago 2026** (entró `lal`), y el bucle de despliegue alcanza **diecisiete
carpetas** porque `demo` cuelga del mismo glob (`CLAUDE.md`, y el barrido del 2 sep
en `docs/DESPLIEGUE.md`). Importa exactamente en un sitio de este plan: **los dos
censos del día del despliegue** (§7). Un censo que dice «en los quince» y recorre
diecisiete carpetas deja al lector decidiendo a las tres de la mañana si el colegio
de más es legítimo. **Lo es.**

### 1.3 · Una columna nueva de `years` mueve ~30 instantáneas — «la misma respuesta que hoy» es falso en la forma

Ésta es la corrección que más mueve el plan, y sale de leer los dos caminos que
publican `years`:

```
YearsController::getIndex   →  'SELECT y.*, i.nombre as logo FROM years y …'
YearsController::getColegio →  'SELECT * FROM years WHERE deleted_at is null'
```

O sea que **`modelo_evaluacion` y los tres `displayname` del desempeño se reparten
solos** a todo lo que cuelga de ahí. Contadas hoy: **30 de las 125 instantáneas de
contrato** llevan columnas de `years` dentro — los tres boletines, los dos contextos
de login, las actas, los puestos, `muestreo-years`.

`DECISIONES-MODELO-DE-EVALUACION.md` §7 dice: *«con el enum en `ponderado` y las
tablas vacías, los quince colegios dan la misma respuesta que hoy»*. **En el
comportamiento, sí. En los bytes, no**: aparece una clave nueva en el objeto `year`
de treinta respuestas. No es un problema —es una regeneración con su diff revisado—
pero **deja de servir como argumento de seguridad**, y era el que sostenía la fase.
Es literalmente el caso de [30](30-lo-que-reparte-una-columna-nueva.md), escrito por
`profesores.tono` hace nueve días.

> Lo que **sí** se conserva byte a byte es lo otro que promete esa frase, y ése es el
> test que hay que escribir: **con el enum en `ponderado` y las tablas vacías, las
> unidades, las notas, las definitivas y los tres boletines no cambian en nada.**

### 1.4 · Nadie podría *escribir* `modelo_evaluacion` — y eso es `profesores.tono` otra vez

`YearsController::putGuardarCambios` escribe **veintiuna columnas nombradas una a
una** (líneas 605-629). Una columna nueva de `years` que no se añada ahí **no la puede
poner nadie**, ni el superusuario: se leería en treinta respuestas y saldría
`'ponderado'` en los dieciséis para siempre.

Es exactamente el hueco de `profesores.tono` del 4 sep —columna leída en todas partes
y escrita en ninguna—, y esta vez se ve **antes** de cometerlo, que es lo que pedía la
§5.7.a del doc 28 al hablar del alcance de la plantilla.

Y al mirar el camino de escritura aparece la pregunta que el documento de decisiones
no se hizo: **`PUT years/guardar-cambios` va con `auth.personal`, o sea que lo llama
cualquier docente.** Los dieciséis `years/*` de escritura son `auth.personal`, sin
excepción. Meter ahí `modelo_evaluacion` es dejar que **cualquier docente del colegio
cambie el modelo de evaluación del año** desde un `PUT` de dos campos.

**La propuesta, y es una decisión de Joseth** (§6, D-A):

- los **tres `displayname`** del desempeño van en `putGuardarCambios`, al lado de los
  seis que ya están, con su mismo guard: son rótulos, y el que puede renombrar
  «Subunidad» puede renombrar «Desempeño». **Cero rutas.**
- **`modelo_evaluacion` va en una ruta propia**, `PUT years/modelo-evaluacion`, con
  `auth.personal` en la ruta y `Autoriza::puedeEditarPlantillaNotas` **dentro** — la
  forma de `PlantillaNotasController`, y por el mismo motivo: lo que configura el
  colegio, el docente no lo toca. **Una ruta**, que el documento de decisiones no
  contaba.

### 1.5 · La rejilla premarcada NO se puede escribir todavía: falta qué premarca

**Es el hallazgo que bloquea una fase entera, y sale de cruzar D5 con D6.**

D6 dice que la rejilla *«se abre con las casillas ya marcadas según
`escalas_de_valoracion`»*, y señala la máquina que ya existe. La comprobé, y el
mensaje del front es correcto en todo lo que afirma:
`Unidad::deAsignaturaCalculada` (`app/Models/Unidad.php:141`) tiene los dos modos,
`fortaleza_debilidad` e `con_desempenio`, los dos derivan al imprimir y ninguno
guarda nada.

**Y aun así la regla no cierra**, porque las dos piezas no encajan:

| lo que da la máquina del rango | lo que pide la rejilla |
|---|---|
| un **nivel** por alumno — `escalas_de_valoracion.desempenio`: «Superior», «Alto», «Básico», «Bajo» | un **sí/no por cada texto**: ¿este alumno alcanzó *«Identifica los tipos de triángulo»*? |

`escalas_de_valoracion` son `(desempenio, valoracion, porc_inicial, porc_final,
descripcion, orden, perdido, year_id)` — comprobado en el volcado, línea 1031. **No
tiene ninguna relación con un catálogo de textos**, y la fila de `desempenos` que
propone D5 —`competencia_id NULL, tipo NULL, definicion, orden, por_defecto`— **no
lleva nivel**. Así que no hay ninguna función que lleve de «este alumno sacó Alto» a
«se le premarcan estos cuatro textos y estos seis no».

Las salidas son dos, y **son dos decisiones distintas, no dos formas de la misma**:

| regla | qué cuesta | qué significa de verdad |
|---|---|---|
| **A · «alcanza quien aprobó»** — se premarcan **todos** los desempeños del alumno cuya nota cae en una escala con `perdido = 0` | cero esquema; una consulta a `notas_finales` por grupo y periodo | el docente abre la rejilla con **todo marcado para los que aprobaron** y su trabajo es **desmarcar** |
| **B · el desempeño lleva su nivel** — una columna `escala_id` en `desempenos` y `desempenos_por_defecto`, y se premarca el texto cuyo nivel coincide | una columna más en las dos tablas, y el colegio escribe **un texto por nivel** | es lo que hacen SINAI y WebColegios — y es **justo lo que D8 descartó** («multiplica por cuatro el trabajo de escritura») |

**Hay que decir lo incómodo de A con todas las letras, porque es lo que hace que la
decisión valga**: A es, en el resultado, *«todos alcanzan y el docente quita»* aplicado
a los aprobados — que es lo que la decisión 10 del doc 28 descartó. Lo único que la
separa es que **no escribe hasta que el docente guarda**, y eso es una defensa real
pero más fina de lo que parece: un docente que pulse «guardar» sin mirar produce
exactamente el boletín que la decisión 10 quería impedir.

**Recomiendo A**, por dos razones y no por la barata: (1) es la única que no reabre
D8, y (2) la afirmación que hace es honesta si se escribe en la pantalla —*«marcados
los que aprobaron; quite los que no»*— mientras que B promete una precisión que el
colegio tendría que escribir cuatro veces para tener. Pero **es de Joseth**, porque
cambia lo que un boletín afirma.

> Y hay una tercera cosa que **no** es una salida y conviene descartar por escrito:
> premarcar con la nota de la **unidad** en vez de la definitiva. Cambia de qué nota
> sale el nivel y no contesta la pregunta — sigue sin haber texto que ese nivel
> escoja.

### 1.6 · `desempenos_por_defecto` hereda el problema de precedencia que la plantilla ya resolvió

D5 pone `grado_id NULL` = «para todos los grados». Es la misma forma que
`unidades_por_defecto.nivel_educativo_id`, y ahí ya se aprendió lo que cuesta: la
§5.7.a del doc 28 tuvo que inventar **cuatro gradas ordenadas** y aplicar *«la grada
entera, nunca una mezcla»*, porque mezclar dos daba una plantilla que nadie escribió.

Aquí la pregunta vuelve con dos ejes —`materia_id` y `grado_id NULL`— y **el
documento de decisiones no la contesta**. La diferencia con la plantilla es que aquí
**es menos peligrosa**: los textos no suman 100, así que «se aplican las dos» es
defendible y no produce ningún número raro. Pero se decide, no se deja al `ORDER BY`.

**Propuesta** (§6, D-B): **acumulan, no compiten** — un desempeño de «Matemáticas,
todos los grados» y otro de «Matemáticas, 6.º» se siembran **los dos**, ordenados por
`orden`. Y **no se reutiliza `App\Support\AlcanceDeLaPlantilla`**: sus gradas existen
para no mezclar repartos que suman 100, y aplicarlas aquí escondería textos que el
colegio escribió.

### 1.7 · La rama del `ALTER` va 169 commits por detrás: 1.926 es cierto, y no es de este árbol

La rama `fix/frases-asignatura-text` existe, está sin fusionar y es exactamente lo que
dice el mensaje del front: **tres commits, dos ficheros** —la migración
`2026_09_05_100000_frase_del_boletin_en_text` y `tests/Contrato/FraseLargaEnElBoletinTest`—
y **ninguna instantánea tocada** (`git diff --stat` sobre la fusión: 209 líneas, dos
ficheros nuevos). Y `frases_asignatura.frase` **sigue siendo `varchar(255)`** en
`database/schema/mysql-schema.sql:1078`.

Lo que el mensaje del front no dice, y cambia el paso 1 del plan:

```
base de la rama:  ab23e2d
main hoy:         c0ed278   →  169 commits por delante
migraciones que entraron en medio:  7
```

El commit `50399f6` publica su cifra **bien**, con la orden y con el aviso de
población: `Tests: 1926 passed (17317 assertions)`, 954 s, y de su puño *«1.926 no se
compara con las 1.941 de `f5` … son poblaciones distintas»*. **Es una medición de
`ab23e2d`**, no de `main`. Siete migraciones después —entre ellas el alcance de la
plantilla y `grupos_con_ih`— esa cifra no dice nada del árbol fusionado.

**Consecuencia para el plan**: la Fase 0 no es *«fusionar»*, es **rebasar, migrar y
volver a correr la suite entera**, y publicar la cifra nueva con la orden que la
produjo y el hash contra el que corrió. Es la regla de `CLAUDE.md` —una cifra de
pruebas se publica con su orden, y una medición se anota con su hash— y aquí muerde
por el sitio de siempre: un `ALTER` de tipo se corre entero después de migrar.

### 1.8 · Lo que el encargo dice y comprobé que es cierto

Para que no haya que volver a mirarlo:

- **`can_edit_plantilla_notas` existe** y es `Autoriza::PERMISO_PLANTILLA_NOTAS`
  (`app/Support/Autoriza.php:47`), creado por `2026_09_05_300000`. ✔
- **El rol «jefe de área» no existe**: cero apariciones en `app/`, `routes/` y
  `database/`. ✔
- **Los tres boletines son 629 + 605 + 586 líneas**, y viven en
  `app/Http/Controllers/Informes/`. ✔
- **`FrasesAsignaturaController::postStore` sigue sin comprobar** que la asignatura sea
  del profesor ni que el alumno esté en ella — hoy, 13 sep. Guarda una frase por
  llamada y fija `periodo_id = $user->periodo_id`. ✔
- **La definitiva del alumno está en `notas_finales`** (alumno + asignatura + periodo),
  así que el premarcado de la rejilla es **una consulta por grupo y periodo**, no una
  por alumno. No hay tabla `definitivas`.
- **La cadena para sembrar existe**: `asignaturas.grupo_id` → `grupos.grado_id` →
  `grados.nivel_educativo_id`. ✔

---

## 2. El orden: siete fases, cada una desplegable sola

La regla de corte es la misma de `horario/` y de la Entrega 1: **una fase se despliega
sola si, desplegada sola, el colegio puede hacer algo entero con ella.** Lo que no
cumple eso va junto aunque sea más trabajo.

| fase | qué entrega | rutas | migraciones | instantáneas |
|---|---|---|---|---|
| **0** | el `ALTER` a `text`, remedido | 0 | 1 (ya escrita) | 0 |
| **1** | el colegio elige su modelo y le pone nombre | **1** | 1 | **~30** |
| **2** | competencias, con el catálogo del MEN | 7 | 1 | 0 |
| **3** | desempeños: catálogo, siembra y los propios del docente | 8 | 1 | 0 |
| **4** | la rejilla premarcada | 2 | 1 | 0 |
| **5** | qué comparten los tres boletines *(medición, sin código)* | 0 | 0 | 0 |
| **6** | el boletín nuevo | 4 | 0 | 0 |

**Total: 22 rutas**, no 20 — las 20 del documento de decisiones más
`PUT years/modelo-evaluacion` (§1.4) y menos ninguna. Se cuentan el día que entren,
con `route:list --json`, **en el árbol donde se escriban** (§3).

---

### Fase 0 · El `ALTER`, rebasado y remedido

**No es opinable y va primero**: todo lo que escriben las fases 3 y 4 acaba en
`frases_asignatura.frase`, y hay 626 frases ya cortadas en la copia de desarrollo
(doc 28 §1.ter).

1. Rebasar `fix/frases-asignatura-text` sobre `main` (169 commits, 7 migraciones).
2. `tools/construir-bd-test.sh` y **la suite entera**, no sólo Contrato: un `ALTER`
   de tipo lo lee más de un informe.
3. Publicar `Tests: N (php artisan test)` **con el hash** contra el que corrió.
4. Comprobar que el árbol queda limpio — que es la comprobación que la cifra no da.

**Lo que no hace, y ya está decidido**: no repara hacia atrás y no se avisa a nadie
(decisión 12 del doc 28). Cada reimpresión de un boletín viejo seguirá saliendo
cortada.

---

### Fase 1 · El colegio elige su modelo

```
2026_09_XX_100000_modelo_de_evaluacion_del_anio

years + modelo_evaluacion  enum('ponderado','competencias') NOT NULL DEFAULT 'ponderado'
      + desempeno_displayname   varchar(255) NOT NULL DEFAULT 'Desempeño'
      + desempenos_displayname  varchar(255) NOT NULL DEFAULT 'Desempeños'
      + genero_desempeno        varchar(1)   NOT NULL DEFAULT 'M'
```

**`PUT years/modelo-evaluacion`** — `auth.personal` en la ruta,
`Autoriza::puedeEditarPlantillaNotas` dentro (§1.4). Los tres `displayname` entran en
`putGuardarCambios`, sin ruta nueva.

**Tres cosas que hay que tocar y que nadie pediría solas:**

1. **`YearsController::postStore` copia las cuatro al crear el año siguiente.** Lo caza
   `CentinelaDeLasColumnasDelAnioNuevoTest`, que vigila que no se deje ninguna columna
   de `years` — es la única de las tres que tiene ya quien la vigile.
2. **`subunidad_displayname` deja de sugerir «Indicador»** en la pantalla de
   configuración (D15) **sin tocar el valor que cada colegio guardó**. Es un cambio del
   front; aquí sólo se anota para que nadie lo "arregle" en la base.
3. **El enum no toca ni un cálculo.** Es la propiedad que no hay que perder: volver
   atrás es cambiar el enum.

**Tests de contrato:**

- **El que sostiene la fase**: con `modelo_evaluacion = 'ponderado'`, la respuesta de
  `unidades/de-asignatura-periodo`, la definitiva de una asignatura y los **tres**
  boletines salen **idénticos** a antes de la migración. Se ve rojo poniendo el enum
  en `competencias` y comprobando que **tampoco** cambia — porque no debe cambiar
  ningún cálculo en ninguno de los dos modos (D3).
- Las ~30 instantáneas se regeneran **en un commit aparte y con el diff leído**: lo
  único que puede aparecer son las cuatro claves nuevas. Cualquier otra cosa en ese
  diff es un hallazgo, no ruido.
- Un docente sin `can_edit_plantilla_notas` recibe **403** en
  `PUT years/modelo-evaluacion`, y **200** en `years/guardar-cambios` con los
  displayname — que es lo que demuestra que la línea se trazó donde se quería.
- Un año creado a partir de otro **hereda las cuatro columnas**.

---

### Fase 2 · Competencias, y el catálogo del MEN que las siembra

```
2026_09_XX_200000_competencias

competencias
  id, year_id, materia_id, grado_id NULL, alumno_id NULL,
  definicion text, orden int,
  created_by/updated_by/deleted_by/deleted_at/created_at/updated_at
  KEY (year_id, materia_id, grado_id, alumno_id)

  -- sin padre, sin porcentaje y sin nota: sus hijos son los desempeños,
  -- que la apuntan con `competencia_id` desde la Fase 3.
```

`alumno_id NULL` **se queda** (Decreto 1421/2017, informe anual de competencias del
alumno con PIAR) y se lee con `<=>` a través de
`App\Services\BoletinIndependiente::alcance()`, igual que `unidades`. **Con
`alumno_id = null` se seleccionan exactamente las filas de antes.**

**7 rutas**: las 6 de la §5.2 del doc 28 —`GET`, `POST`, `PUT {id}`, `DELETE {id}`,
`PUT orden`, `PUT copiar`— más **`GET competencias/catalogo-men`** (D11).

> **El catálogo del MEN va DENTRO de la familia `competencias/`, y no es cosmético.**
> Una ruta sola en una familia propia entra en
> `familias-que-nunca-entran-en-el-candado.json` como **«1 de 1»**, que es la forma que
> tendría un agujero y hay que defender por escrito. Colgada de `competencias/` —que
> tendrá siete con guard— **ese censo no se mueve**. Es gratis elegir bien el nombre.

- Los **Estándares Básicos** y los **DBA** viajan **como fichero de datos con el
  código**, no como tabla sembrada en cada colegio: se actualizan con el despliegue y
  no hay que migrar dieciséis bases para corregir una errata del MEN.
- **Adoptar copia**; el catálogo no manda sobre nada (regla 1 de la §4 del doc 28).
- **Lenguaje, Matemáticas, Ciencias Naturales, Ciencias Sociales, Competencias
  Ciudadanas e Inglés** tienen contenido. **Religión, Artes, Ed. Física y Tecnología
  nacen vacías** y hay que decirlo en la pantalla, no dejar que el colegio lo
  descubra.

**Tests de contrato:**

- Un año **sin ninguna competencia** da la respuesta de boletín idéntica a hoy.
- `GET competencias/catalogo-men` de una materia que el MEN no cubre devuelve **la
  lista vacía y dice por qué**, no un 404: población, no `OK`.
- Adoptar dos veces no duplica.
- Un alumno con competencia propia la recibe; **uno normal recibe las de su grado** —
  el caso que `=` en vez de `<=>` deja mudo y vacío.
- Sin `can_edit_plantilla_notas`: **403** en las seis de escritura, **200** en el `GET`.

---

### Fase 3 · Los desempeños: catálogo, siembra y los del docente

```
2026_09_XX_300000_desempenos

desempenos_por_defecto
  id, year_id, materia_id, grado_id NULL, periodo_id,
  competencia_id NULL, tipo NULL, definicion text, orden int, …

desempenos
  id, asignatura_id, periodo_id, alumno_id NULL,
  competencia_id NULL, tipo NULL, definicion text, orden int,
  por_defecto tinyint(1) NOT NULL DEFAULT 0, …
```

Es el par que ya funciona en este sistema: `unidades_por_defecto` → `unidades`. Y
`por_defecto` significa aquí **lo mismo** que en `unidades`: «esta fila la sembró el
colegio» — que es lo que hace cumplir D14 sin inventar nada.

**8 rutas**: `GET`/`POST`/`PUT {id}`/`DELETE {id}`/`PUT orden`/`PUT copiar` sobre el
catálogo, **`PUT desempenos/sembrar`** y `GET desempenos` de una asignatura+periodo
(lo que lee la planilla).

> **`PUT desempenos/sembrar` es explícito y no cuelga de un `GET`.** El `GET` que
> escribe —`UnidadesController::getDeAsignaturaPeriodo`— está fichado en
> [05](05-codigo-muerto-y-roto.md) §16 y **no se repite en código nuevo**. Que el doc
> 28 §8 diga que no se le quita al viejo no es permiso para escribir otro.

**El contrato de `sembrar`**, calcado del de la plantilla (`PlantillaNotasController::putSembrar`),
que ya aprendió tres cosas por las malas:

- **devuelve la población, no `OK`** — `{revisadas, sembradas, saltadas_por_estructura,
  saltadas_por_periodo_cerrado, saltadas_sin_catalogo, independientes_respetadas}`.
  Un «0 sembradas» tiene que poder distinguirse de «no revisé nada», y
  `saltadas_sin_catalogo` es el que **delata un catálogo mal dirigido**;
- **sólo periodos abiertos** (regla 2 de la §4) y **nunca encima de lo que ya tiene
  desempeños propios**;
- **no toca filas con `alumno_id IS NOT NULL`**, y el contador que lo demuestra es
  `independientes_respetadas` —sube cuando había filas con dueño y se dejaron—, no uno
  que valdría cero siempre;
- **se registra con `Auditoria`**: es una escritura masiva sobre el colegio entero;
- **acumula por grado, no compite** (§1.6).

**El candado del docente** es la marca `por_defecto`, y **son cuatro caminos, no dos**:
`update`, `destroy`, `forcedelete` y `orden`. Poner el candado sólo en `update` lo
deja decorativo por el rodeo de siempre —borrar y volver a crear—, que es lo que la
§5.1.e del doc 28 midió con los nueve caminos de `unidades`. Y compara **valores, no
presencia del campo**: los clientes mandan el objeto entero.

**Y lo que el docente sí puede** (D14): **añadir los suyos**. Un desempeño con
`por_defecto = 0` y su `asignatura_id` se edita y se borra sin permiso ninguno.

> **El centinela que falta, y que esta fase obliga a escribir.** `competencias` y
> `desempenos_por_defecto` son **tablas por año**, así que `YearsController` tiene que
> copiarlas al crear el año siguiente o el colegio reescribe su plan de área cada
> enero. El `CentinelaDeLasColumnasDelAnioNuevoTest` **no puede cazar esto**: vigila
> columnas, no tablas hijas — es exactamente el fallo de la §1.bis del doc 28, que
> dejó las subunidades por defecto sin copiar durante años y sin un error en el log.
> El doc 28 §5.0 ya dijo que ese centinela «no está escrito». **Esta fase lo escribe**,
> con su lista de excepciones y el motivo al lado de cada una.

**Tests de contrato:**

- **Con las dos tablas vacías, la respuesta de la planilla y de los tres boletines es
  la de hoy, byte a byte.**
- `sembrar` con el periodo cerrado **no escribe nada**; con una asignatura que ya tiene
  desempeños propios, la deja y la reporta.
- **El control que hace valer el candado**: apagarlo tiene que poner rojos **los
  cuatro** caminos. Si sólo cae `update`, el rodeo sigue abierto.
- Guardar sin cambiar nada sigue dando **200** (trampa 1 de la §5.1.e).
- **Un año nuevo hereda competencias y desempeños por defecto** — el test hermano del
  de la §1.bis, y va al lado del suyo, que es donde alguien vendrá a mirar.
- El alumno del boletín independiente recibe **los suyos**; el normal, los del grupo.

---

### Fase 4 · La rejilla premarcada · **BLOQUEADA por la decisión D-C (§6)**

```
GET  desempenos/rejilla?asignatura_id=&periodo_id=
PUT  desempenos/rejilla
     { asignatura_id, periodo_id,
       marcas: [ {alumno_id, desempeno_id, si: true|false}, … ] }
```

```
2026_09_XX_400000_marca_del_desempeno
frases_asignatura + desempeno_id int NULL
```

**Dos rutas y dos usos** —desempeños y preescolar (§5.7.c del doc 28)—, que es lo que
hace que la decisión 10 y la Entrega 7 compartan pantalla en vez de duplicarla.

- Escribe en **`frases_asignatura`**, que es lo que el boletín ya lee: **no añade ni
  una consulta** al camino que tarda 24-63 s.
- **El texto se sigue copiando en `frase`** — eso es lo que protege los boletines
  viejos (regla 1 de la §4). `desempeno_id` sirve **sólo para saber de qué casilla
  salió**.
- **Una llamada, no ~300.** Es la medición que justifica la ruta:
  `FrasesAsignaturaController::postStore` guarda una frase por petición.
- **Devuelve la población**: `{recibidas, marcadas, quitadas,
  saltadas_por_periodo_cerrado, saltadas_por_no_ser_del_grupo}`.
- **La comprobación que hoy falta entra aquí**: que el alumno esté en el grupo de la
  asignatura, que es justo lo que `postStore` no mira (§1.8). Código nuevo → **403 y
  422**, no 400.
- **No escribe nada al abrir.** El `GET` premarca en memoria; la fila sólo existe
  cuando el docente guarda.

**Tests de contrato:**

- El `GET` **no escribe**: contar filas de `frases_asignatura` antes y después. Es el
  test que hace que «premarcada» no se convierta en «marcada de oficio» por un
  descuido, y es el que sujeta la decisión 10 entera.
- Marcar en un **periodo cerrado** no escribe nada.
- Marcar a un alumno **que no es del grupo** → 422, y no escribe.
- Un desempeño marcado y luego **corregido en el catálogo** no cambia el texto ya
  impreso — `frase` se copió.
- El texto de un desempeño de **388 caracteres** viaja entero de ida y vuelta: es
  `FraseLargaEnElBoletinTest` aplicado a este camino, y **sin la Fase 0 se ve rojo**.

---

### Fase 5 · Qué comparten los tres boletines *(medición, sin código)*

**629 + 605 + 586 líneas**, comprobadas. El documento de decisiones tiene razón en el
argumento y el número lo respalda: escribir el cuarto a ciegas deja el problema **un
33 % peor**, porque el próximo arreglo habrá que escribirlo cuatro veces.

Lo que esa medición tiene que contestar, y no es «cuántas líneas se repiten»:

1. **Qué consulta hace cada uno y en qué se diferencian** — los tres pasan por
   `Unidad::deAsignaturaCalculada`, y son **cuatro** los consumidores, no tres:
   `Informes/NotasActualesAlumnosController:187` también.
2. **Dónde divergieron de verdad**: qué hace uno que los otros no, y si alguna de esas
   diferencias es un fallo en vez de una intención.
3. **Qué parte es la maqueta y qué parte es el dato.** El boletín nuevo nace del dato
   común; la maqueta es suya.
4. **Cuál de las doce instantáneas de boletín cubre cada rama**, porque lo que no esté
   cubierto es lo que se romperá al extraer.

Es **una noche**, no un mes, y no entrega nada al colegio — por eso va aquí y no
antes: las fases 1 a 4 sí entregan.

---

### Fase 6 · El boletín nuevo

**4 rutas**, calcadas de `boletines3`. Se elige **llamando a su ruta**, como ya pasa
con `boletines2` y `boletines3`: **no hay interruptor** (decisión 6 del doc 28, y por
eso `show_competencias_bol` se retiró).

- Imprime **nota + nivel + los textos del alumno**.
- **Honra `grupos.caritas` imprimiendo el desempeño en TEXTO** (D17): la columna ya
  existe, ya es por grupo, ya se copia al año siguiente (`YearsController:382`) y ya
  viaja al front — **lo único que falta es que el backend la lea**. El icono queda de
  adorno y nunca solo: Decreto 2247 art. 10 y 1411/2022 piden *«informes descriptivos
  … de corte cualitativo»*, y una carita no lo es.
- **No se tocan `BoletinesController:611` ni `Boletines2Controller:585`.** La regla
  nace en la maqueta nueva, y así no se mueve ninguna instantánea publicada. Meterlo
  en los de siempre es **entrega propia** y sólo si un colegio lo pide (D16 cierra la
  decisión 19 por omisión).

> **`caritas` se toca con el guante puesto**: es la columna de la §153 de
> `GruposController` —tenía defecto `false` y ese defecto la apagaba, así que
> corregirle el nombre a un grupo de preescolar le cambiaba la forma de evaluar—.
> Todo endpoint nuevo que la lea hereda ese aviso.

---

## 3. La cuenta de rutas, y cómo se cuenta

**22**, no 20: las 20 del documento de decisiones, más `PUT years/modelo-evaluacion`
(§1.4) y más el `GET desempenos` de la planilla, que la §5.3 del doc 28 daba por
incluido en «6 calcadas» y no lo está.

Sobre una base que **hay que contar**, no heredar: `CLAUDE.md` dice 579 y el doc 28
dice 577 (§1.1).

**Lo que mueve cada tanda**, y son **tres instantáneas, a veces cuatro**:

| fichero | se mueve |
|---|---|
| `rutas.json` | siempre |
| `guards-por-ruta.json` | siempre — las 22 llevan guard |
| `guard-por-familia.json` | siempre; estrena `competencias` y `desempenos` |
| `familias-que-nunca-entran-en-el-candado.json` | **sólo si alguna familia nueva se queda con menos de dos rutas con guard** — que es justo lo que se evita colgando el catálogo del MEN de `competencias/` (§2, Fase 2) |

`RutasPreLoginTest::TOTAL_PUBLICAS` **sigue en doce** y `AutenticacionTest::SIN_GUARD`
no se mueve: **ninguna de las 22 es pública ni debe serlo.**

---

## 4. Lo que este plan NO toca

Se repite entero porque es lo que hace que todo lo de arriba sea seguro, y porque la
lista es el contrato:

`unidades` · `subunidades` · `notas` · **la fórmula de la definitiva** · los **tres
boletines de hoy** · `frases` y su pantalla · `frases_preescolar` y sus tres rutas ·
**la rejilla de notas del boletín independiente** (`unidades.alumno_id`,
`BoletinIndependiente::alcance()`, la marca para todas las asignaturas y el
interruptor por periodo).

Y **las cinco reglas de la §4 del doc 28 las hereda todo lo que se escriba aquí**: la
plantilla siembra y no manda; nada se siembra en un periodo cerrado; la fórmula no
cambia; nada se siembra encima de lo que ya tiene notas; y `alumno_id IS NULL` en todo
lo que hable del reparto del curso.

---

## 5. Lo que queda fuera de estas fases, y no es un olvido

- **El candado del docente sobre `unidades`/`subunidades`** (decisión 14 del doc 28).
  Espera al censo de `por_defecto = 1`, que **no se puede correr desde una sesión de
  desarrollo**. No lo bloquea nada de este plan y este plan no lo desbloquea.
- **La Entrega 4 del doc 28** —tercer origen `{tipo:"plantilla"}` en `copiar` y sembrar
  al marcar— está aprobada (D18) y **es independiente de estas siete fases**: cero
  rutas nuevas. Con D5 encima, al marcar se siembran **también los desempeños** del
  grupo a nombre del alumno, así que su sitio natural es **detrás de la Fase 3**.
- **La fase 0 de la Entrega 5** —sacar `nota × % / 100` de sus **18 sitios en 9
  ficheros** a un punto único— está aprobada y **se despliega sola** (D19). No cambia
  ni un resultado y se verifica con las instantáneas tal como están. **No depende de
  nada de este documento y nada de este documento depende de ella**, así que puede ir
  en paralelo en otro árbol.

---

## 6. Lo que necesito de Joseth, y qué se bloquea sin cada cosa

Tres, y sólo la tercera para algo que no se puede escribir sin ella.

**D-A · ¿Quién puede cambiar el modelo de evaluación del año?** (§1.4)
Hoy los dieciséis `years/*` de escritura son `auth.personal`, o sea **cualquier
docente**. Propongo ruta propia con `can_edit_plantilla_notas` dentro — una ruta más.
La alternativa es meterlo en `years/guardar-cambios`: cero rutas, y cualquier docente
puede cambiarle el modelo al colegio.
*Bloquea:* nada — se puede escribir la Fase 1 con la propuesta y cambiar el guard
después, pero cambiarlo después es un 403 nuevo sobre un endpoint vivo.

**D-B · ¿Los desempeños de «todos los grados» y los de 6.º acumulan o compiten?** (§1.6)
Propongo **acumulan**: los textos no suman 100, así que no producen ningún número raro,
y las gradas de la plantilla existen para otra cosa.
*Bloquea:* nada — pero si se decide después de sembrar en un colegio, cambiarlo mueve
los textos ya sembrados.

**D-C · ¿Qué premarca la rejilla?** (§1.5) — **ésta sí bloquea.**
La máquina del rango da un **nivel por alumno**; la rejilla necesita un **sí/no por
texto**, y no hay nada que lleve de lo uno a lo otro. Regla **A** («se premarcan todos
los del que aprobó», cero esquema, y hay que decir que el trabajo del docente es
**desmarcar**) o regla **B** (una columna de nivel en los desempeños, que es lo que D8
descartó). Recomiendo **A**.
*Bloquea:* **la Fase 4 entera.** Las fases 0 a 3 se pueden escribir y desplegar sin
esta decisión, y por eso la rejilla va la cuarta y no antes.

Y dos que **no** bloquean código, porque son del día del despliegue (§7): el nombre y
la maqueta del boletín nuevo, y la escala del alumno con PIAR dentro de un grupo
numérico.

---

## 7. Lo que se cuenta el día del despliegue

Con el bucle de [DESPLIEGUE.md](../DESPLIEGUE.md) sobre
`/home/micolev1/*.micolevirtual.com/8myvc` — **diecisiete carpetas: dieciséis colegios
y `demo`** (§1.2). Los tres son de sólo lectura y se escriben **con el denominador
delante**: «X de 17, N de M».

1. **`por_defecto = 1` en `unidades` y `subunidades`** (doc 28 §5.1.e). Son literalmente
   las filas que el día del candado dejan de poder tocarse. En `simonbolivar`: **cero
   de 51.519**.
2. **`default_unidades` / `default_subunidades`** (D21). No las lee nadie en `app/`,
   y nadie sabe qué hay dentro en producción. **Contar antes de tocar.**
3. **Las frases cortadas** — `SUM(CHAR_LENGTH(frase) = 255)` — queda escrita en el doc
   28 §1.ter **por si algún día se quiere contar**: Joseth ya decidió que no se avisa
   (decisión 12). Se cuenta sólo si se pide.

---

## 8. De dónde sale cada cosa

- `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md` — las 22 decisiones del 13 sep 2026.
- `myvc_front/INVESTIGACION-COMPETENCIAS-Y-DESEMPENOS.md` — cuatro decretos, catorce
  SIEE, trece programas.
- [28-competencias-e-indicadores.md](28-competencias-e-indicadores.md) — las siete
  entregas y su precio. Lo que aquí se traza es **el orden**, no otra propuesta.
- [30-lo-que-reparte-una-columna-nueva.md](30-lo-que-reparte-una-columna-nueva.md) —
  por qué §1.3 cuenta treinta instantáneas y no cero.
- Medido el **13 sep 2026** sobre `main` en **`c0ed278`**, árbol principal, **sin
  contenedor levantado**: `frases_asignatura.frase` todavía `varchar(255)`
  (`mysql-schema.sql:1078`); `fix/frases-asignatura-text` en `50399f6`, base `ab23e2d`,
  **169 commits** por detrás y 7 migraciones en medio; `Unidad::deAsignaturaCalculada`
  en la línea **141** con sus dos modos de rango; `escalas_de_valoracion` sin ninguna
  relación con un catálogo de textos (`mysql-schema.sql:1031`);
  `Autoriza::PERMISO_PLANTILLA_NOTAS` en `app/Support/Autoriza.php:47`; **cero**
  apariciones de «jefe de área»; **30 de 125** instantáneas con columnas de `years`
  dentro; `putGuardarCambios` con **21** columnas nombradas; los tres boletines en
  **629 + 605 + 586** líneas.
