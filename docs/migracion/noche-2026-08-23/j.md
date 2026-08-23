# Lote J — Las rutas ya cubiertas que nadie ha juzgado (§114–116)

> Sesión `8myvc-06`, árbol `.worktrees/j`, rama `fix/lote-j-cubiertas-sin-juzgar`,
> base `simonbolivar_testing_j`. Noche del 22 al 23 de agosto de 2026.
>
> Es un lote de **leer y reportar**: no es dueño de ningún controlador. Lo único
> que edita es `tests/Contrato/AutorizacionTest.php`, que quien coordina le
> asignó.

La pregunta era: **el test fija un 200 y nadie preguntó de quién es la fila.**
Al tirar del hilo, la respuesta buena no estaba en las rutas sino en el propio
candado que las vigila.

---

## §114 — El candado de familia se apaga justo cuando empeora lo que vigila

`AutorizacionTest::test_ninguna_ruta_se_queda_sola_sin_el_guard_de_su_familia`
salta una familia entera cuando las abiertas dejan de ser minoría:

```php
if ($conGuard < 2 || $sinGuard > max(2, intdiv(count($rutas), 4))) continue;
```

**Está bien que lo haga**, y su docblock lo explica: una familia mayoritariamente
abierta es otra pregunta, y meterla en ese `assert` daría sesenta líneas de ruido
que taparían las cinco que importan. Esa mitad se manda al snapshot de cuentas.

Lo que no dice, y es lo que mide esta sección, es **cuál de las dos ramas se toma
en cada familia**.

### 1. `$sinGuard` está en el lado malo de la comparación

Añadir una ruta **sin** guard no rompe el test. Pasado el umbral, hace que el
test **deje de mirar la familia entera** — con sus excepciones ya declaradas
dentro. Medido el 23 ago 2026, **seis familias están a una sola ruta** de que eso
pase:

| familia | hoy | a cuántas rutas de apagarse |
|---|---|---|
| `asignaturas` | 11 / 14 | 1 |
| `definitivas_periodos` | 8 / 10 | 1 |
| `grados` | 3 / 5 | 1 |
| `niveles_educativos` | 3 / 5 | 1 |
| **`perfiles`** | **17 / 22** | **1** |
| `votos` | 3 / 5 | 1 |

`perfiles/*` es la que más duele: pasó a 17 de 22 con guard el 21 de agosto y
tiene **cinco excepciones escritas una a una**. Una ruta nueva sin guard y las
veintidós dejan de comprobarse, sin que falle nada.

### 2. Y el docblock afirma una completitud que no tiene

Dice, literal:

> Una ruta nueva que nazca sin el guard de sus hermanas rompe el test el mismo
> día, que es lo único que impide que la lista vuelva a crecer sin que nadie lo
> note.

Para **siete familias** eso es falso hoy: son **46 rutas abiertas** que ese
`assert` no mira. El candado de hermanas de operación rescata 9, así que **37 no
las mira ninguno de los dos**.

| familia | abiertas / total |
|---|---|
| `matriculas` | 13 / 16 |
| `alumnos` | 9 / 17 |
| `ciudades` | 6 / 11 |
| `acudientes` | 5 / 14 |
| `myimages` | 5 / 10 |
| `votaciones` | 5 / 15 |
| `mis-actividades` | 3 / 5 |

`PUT api/acudientes/guardar-valor` —la que dio el hilo— está aquí: `acudientes`
tiene 5 abiertas contra un umbral de 3, así que **no se mira ninguna de las
catorce**.

### La comprobación al revés, que es la sección entera

Se le quitó `auth.personal` a `perfiles/trashed` y se corrieron los cuatro tests:

```
✓ ninguna ruta se queda sola sin el guard de su familia      <-- PASA
⨯ ninguna familia mas se sale del candado en silencio            (el nuevo)
⨯ cuantas de cada familia llevan guard                           (el snapshot)
⨯ ninguna ruta se queda sola entre sus hermanas de operacion
```

> **El test escrito para cazar exactamente ese caso no lo caza**, porque la ruta
> que le quita el guard es la misma que apaga la familia.

Conviene decir la otra mitad: el candado de **hermanas de operación** sí llegó
(`@getTrashed: 7 de 8 con guard`), y el snapshot de cuentas también. Los dos
candados se solapan, así que **esto no es ceguera total** — es un candado que se
calla en el momento en que más falta hace, con otro detrás que a veces llega.

### Qué se hizo, que es lo único que se puede hacer sin decidir

No se cambió el umbral ni se cerró nada: **se declara la lista**, con el
mecanismo del `count` de `phpstan.neon` — lo que no se puede arreglar se escribe
con nombre y número, nunca en un baseline generado que lo esconda.

`AutorizacionTest::test_ninguna_familia_mas_se_sale_del_candado_en_silencio`
falla si una familia se apaga **y** si una vuelve al candado, para que la lista
no pueda solo crecer. La condición se copia **literal** del candado y no
parecida: si allí cambia el umbral y aquí no, esta lista pasa a describir otra
cosa.

Commit `d5c8010`.

---

## §115 — Y entonces, ¿cuántas están cubiertas sin juzgar?

La pregunta del lote, contestada restando **todo lo que sí está juzgado en algún
sitio**. El número a secas no dice nada; lo que dice algo es de dónde sale.

| | |
|---|---|
| rutas `api/` sin guard de propiedad | **118** |
| las mira alguno de los dos candados | 48 |
| declaradas en `EXCEPCIONES_DE_FAMILIA` o `_HERMANAS` | 48 |
| pre-login, declaradas en `RutasPreLoginTest` | 11 |
| `tardanzas/*` (se autentica solo) y `auth/*` (sale del token) | 7 |
| **abiertas, sin candado y sin declarar en ningún sitio** | **52** |

De esas 52, **47 tienen algún test que no es de barrido**. Y de las 47, en **37**
alguno de sus tests sí pregunta de quién es la fila. Quedan **10 cubiertas y no
juzgadas**, que se leyeron una a una:

| ruta | veredicto |
|---|---|
| `GET api/myimages` | **bien**: `WHERE user_id = :user_id` del token, y los bloques extra tras `is_superuser \|\| Profesor`. **No acepta ningún identificador**, así que no hay nada que comprobar |
| `POST api/myimages/store-firma`, `store-intacta-privada` | **bien**: crean, y el dueño sale del token |
| `PUT api/publicaciones/store` | **bien**: es un `INSERT` con `persona_id` del token. Sus cuatro hermanas que editan y borran sí llaman `exigeQueLaPublicacionSeaSuya()` |
| `GET api/paises`, `GET api/ciudades/by-departamento`, `GET api/piars-config` | catálogo, sin identificador de persona |
| `PUT api/calendario/this-year` | lectura del calendario del colegio |
| `PUT api/alumnos/eps-check`, `PUT api/acudientes/ocupaciones-check` | ya medidas y documentadas en `EscriturasConSoloTokenTest` |

**Nueve de las diez están bien**, y eso es el resultado: entre los dos candados,
las dos listas de excepciones y la de pre-login, la pregunta de la propiedad está
cubierta casi del todo. El agujero de esta noche no era una pila de rutas — era
el candado.

### Un candidato anotado y NO medido

`PUT api/publicaciones/store` acepta `imagen.id` **por el cuerpo** y lo guarda sin
comprobar de quién es la imagen. Es de la familia del lote H. **No se afirma que
filtre: se afirma que no se ha mirado**, y hacerlo necesita la base, que estaba
con seis `phpunit` vivos y carga 30.

---

## §116 — Dos cosas del método, y las dos son fallos propios

### 1. Mi detector estaba ciego en 13 de 23, y hacia el lado alarmista

Para saber si un test pregunta de quién es la fila hay que leer **el cuerpo del
método**, y el registro de cobertura da nombres así:

```
Contrato\RechazosQueMientenTest::test_un_rechazo_de_permiso_es_403 with data set "crear un evento"
```

El detector cortaba el nombre por `« con data set »`. **PHPUnit lo escribe en
inglés, `« with data set »`**, así que el sufijo se quedaba pegado, el método no
se encontraba, y un método que no se encuentra contaba como **«nadie lo juzgó»**.

Salieron **16 rutas cubiertas y no juzgadas**. Con el detector arreglado son
**10**. Las seis de más eran todas de tests con proveedor de datos — o sea, los
más completos, que son justo los que un detector así descarta primero.

> Un detector que falla en silencio **hacia el lado alarmista** es peor que no
> tenerlo: da trabajo que no existe y, cuando se descubre, quema la confianza en
> los que sí existen.

Se arregló auditándolo antes de reportar nada: se listaron los 23 métodos
cubridores y se comprobó cuántos se sabían leer. Esa comprobación —**contar
cuántas entradas tu propia herramienta no supo procesar**— es lo que ninguna de
las dos versiones del número decía por sí sola.

### 2. Un mapa de cobertura caduca en cuanto otra sesión fusiona

El mapa `test → ruta` de este lote salió de la tanda entera del **lote A**, que
corrió sobre `main@c2c2a04`. Cuando se midió, el árbol de J ya tenía fusionados
**B y C**.

Con el mapa viejo, cinco de las 52 salían **sin ningún test**. Comprobado con una
tanda dirigida a las clases nuevas: **cuatro ya las cubre `CiudadesTest`, del
lote B**. Queda una, `GET api/votaciones/show/{id}`, y es del lote F, que sigue
en marcha.

> En una noche de seis sesiones, **una medición de cobertura envejece en
> minutos**. Lo que hay que escribir al lado de un número no es la hora: es
> **contra qué commit se midió**.

---

## Lo que queda anotado y no se tocó

### Para Joseth / el colegio

- **`matriculas/*`, 3 de 16 con guard.** Es el módulo de administración que la
  §17 dejó sin repasar y el que más lejos está del umbral. No es una ruta suelta:
  es una decisión de si ese módulo entero debe cerrarse.
- **`alumnos/*`, 8 de 17**, con `eps-check`, `guardar-valor`,
  `guardar-valor-varios` y `show` sin mirar por ningún candado.

### Para otros lotes

- **Lote F**: `GET api/votaciones/show/{id}` es la única de las 52 sin ningún
  test, medido contra un árbol con B y C fusionados.
- **Lote H (cerrado)**: `PUT api/publicaciones/store` acepta `imagen.id` del
  cuerpo sin comprobar de quién es. Candidato, no medido.

### Nada de esto

- Ninguna migración.
- Ningún controlador tocado: J es de leer y reportar.
- Ninguna suite entera lanzada mientras había tres o más `phpunit` vivos.
