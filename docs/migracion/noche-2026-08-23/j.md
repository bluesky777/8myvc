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

De esas 52, **51 tienen algún test que no es de barrido** —medido con el mapa
de cobertura de la tanda entera de este mismo árbol, no del de al lado; ver
§116—. Y de las 51, en **43** algún test suyo sí pregunta de quién es la fila.

Quedan **8 cubiertas y no juzgadas**, que se leyeron una a una:

| ruta | veredicto |
|---|---|
| **`PUT api/calendario/this-year`** | **mal, y es la §115.1 de abajo**: decide qué enseña con una bandera que manda el cliente |
| `GET api/myimages` | **bien**: `WHERE user_id = :user_id` del token, y los bloques extra tras `is_superuser \|\| Profesor`. **No acepta ningún identificador**, así que no hay nada que comprobar |
| `POST api/myimages/store-firma`, `store-intacta-privada` | **bien**: crean, y el dueño sale del token |
| `PUT api/publicaciones/store` | **bien**: es un `INSERT` con `persona_id` del token. Sus cuatro hermanas que editan y borran sí llaman `exigeQueLaPublicacionSeaSuya()` |
| `GET api/paises`, `GET api/ciudades/by-departamento`, `GET api/piars-config` | catálogo, sin identificador de persona |

**Siete de las ocho están bien.** Entre los dos candados, las dos listas de
excepciones y la de pre-login, la pregunta de la propiedad está cubierta casi del
todo — y la que faltaba no era una ruta sin test, era una **con** test.

---

## §115.1 — El calendario decide qué te enseña con una bandera que mandas tú

`CalendarioController::putThisYear()`, entero:

```php
$is_prof_admin = Request::input('is_prof_admin');
if ($is_prof_admin == 'true') {
    $eventos = DB::select('SELECT * FROM calendario WHERE deleted_at is null');
} else {
    $eventos = DB::select('SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null');
}
```

`solo_profes` es el interruptor con el que el colegio marca un evento como
interno, y **quien decide si se aplica es el que pregunta**. La ruta lleva
`auth.token` a secas.

Medido con token de alumno: mandando `is_prof_admin=true` recibe **exactamente
los 37 eventos** marcados `solo_profes=1` de los 630 del año. El test no compara
contra un número fijo —eso mediría el seed— sino **las dos respuestas del mismo
token**, y comprueba además que los que aparecen de más **son exactamente esos
ids**.

### La otra mitad, que cambia cuál es el arreglo

**Sin la bandera, el interruptor sí se respeta.** No es la
[§74](../05-codigo-muerto-y-roto.md), donde `para_alumnos` no lo leía nadie: aquí
la columna funciona exactamente como debe. Lo único que falla es **de dónde sale
el booleano que decide aplicarla**.

> No hay que enseñar a nadie a leer una columna: hay que mover de sitio un dato.

### Por qué es exactamente el lote J

La ruta **ya estaba cubierta** por
`MuestreoDeLecturasConContextoTest::test_el_calendario_del_year`, verde, fijando
la forma de la respuesta. Nadie preguntó de quién son los eventos.

Y no la ve ninguno de los dos candados, **por el motivo simétrico del §114**:
`calendario/*` tiene 1 de 6 con guard, o sea `$conGuard < 2`, así que **nunca
entró** en el candado de familia. Es el otro lado del mismo `if`: unas familias
**se salen** por tener demasiadas abiertas, y otras **nunca entran** por tener
demasiadas pocas cerradas. El test del §114 declara las primeras y **no cubre las
segundas** — hueco dejado a sabiendas y escrito aquí.

**Se fija, no se arregla**: el arreglo es de una línea, pero cambia lo que reciben
los cuatro clientes en una pantalla que todos abren, y `calendario/*` no es de
ningún lote de esta noche.

`CalendarioSoloProfesTest` · commit `fd1b4e5`

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

### 1.b Y tenía una segunda ceguera, en el sentido contrario

Con el detector arreglado y el mapa fresco salían **14**, y entre ellas las cuatro
lecturas de `ciudades`. Al ir a leerlas:
`CiudadesTest::test_un_alumno_solo_ve_el_catalogo_geografico` **sí las juzga** —
las golpea con token de Alumno **y** de Acudiente y afirma las claves exactas que
devuelven, con el comentario «ninguna trae a nadie» al lado.

El detector solo reconocía el juicio expresado como **rechazo** —`403`, «ajeno»,
«propia»— y se perdía el expresado como **«éstas son las únicas claves que
salen»**.

> Reconocía la forma **débil** del juicio y se perdía la **fuerte** — que además
> es la buena, porque mira el resultado y no el estado.

Con las dos formas reconocidas: **8**.

La cuenta de las tres versiones del mismo hueco es **16 → 10 → 8**, y las tres
correcciones salieron de auditar la herramienta, no de que nadie las discutiera.
**Las tres veces el error fue hacia el lado alarmista.**

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

## Vuelto a medir contra `main`, porque este árbol no lo es

Al contestar una pregunta sobre el lote A se descubrió que **`.worktrees/j` no
desciende del merge de A**:

```
git merge-base --is-ancestor 8ef89ff HEAD   ->  NO
main entonces: 4dd4169 (lote L fusionado), nueve merges por delante
```

Salió de un `main` que tenía **B y C y nada más**, así que todas las cifras de
arriba estaban medidas contra un árbol viejo. Es la §116 otra vez, y esta vez no
en el mapa de cobertura sino en **el árbol entero**.

**Recalculado contra `main` en `4dd4169`** —`route:list` desde el árbol raíz, que
es sólo lectura, y `AutorizacionTest.php` con `git show main:`—:

| | árbol J | main `4dd4169` |
|---|---|---|
| abiertas sin guard de propiedad | 118 | **118** |
| las mira algún candado | 48 | **48** |
| declaradas en las dos listas | 48 | **48** |
| pre-login | 11 | **11** |
| `tardanzas/*` y `auth/*` | 7 | **7** |
| **abiertas, sin candado y sin declarar** | 52 | **52** |
| familias que el umbral apaga | 7 | **7**, las mismas |
| familias a una ruta de apagarse | 6 | **6**, las mismas |

**Ni una cifra se movió**, y tiene explicación: los otros lotes añadieron *tests*,
no *guards*, así que la topología de la autorización es la misma. El §114 se
sostiene tal cual contra `main`.

### Lo que sí puede haberse movido, y no se cierra aquí

El **8** del §115 depende de los tests, y ésos sí cambiaron: `main` tiene **152
ficheros de test de contrato** y este árbol **126**. Tres de las ocho aparecen en
tests que `main` tiene y J no, y de una está comprobado:

> `ImagenDeOtroEnLaFichaTest` (lote E) golpea `GET /api/myimages` con token de
> Alumno **y** de Profesor y afirma 403 en sus hermanas. **`GET api/myimages` ya
> está juzgada en `main`.**

Las otras dos —`GET api/paises` y `GET api/piars-config`— las tocan
`ColumnasQuePisaElExamenTest`, `ActividadesParaQuienTest` y `PiarEscriturasTest`,
y si además las **juzgan** sólo lo dice la medición de cobertura del cierre.

Así que **el 8 es un techo, no un número**: contra `main` es como mucho 7, y la
tanda de cierre lo dejará fijo. Se escribe así a propósito — un techo declarado
vale, y un número que se presenta como exacto sin serlo es lo que esta misma
sección persigue.

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
- **Quien coordina / lote L**: `PUT api/calendario/this-year` (§115.1). El arreglo es de una línea —que `is_prof_admin` salga del token— pero toca una pantalla que abren los cuatro clientes.
- **Lote H (cerrado)**: `PUT api/publicaciones/store` acepta `imagen.id` del
  cuerpo sin comprobar de quién es. Candidato, no medido.

### Nada de esto

- Ninguna migración.
- Ningún controlador tocado: J es de leer y reportar.
- Ninguna suite entera lanzada mientras había tres o más `phpunit` vivos.
