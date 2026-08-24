# MED-1 — lo que nadie había medido

**Sesión `ad`**, rama `medicion/lote-y-cobertura`, árbol `.worktrees/ad`, base
`simonbolivar_testing_ad` (y `_adx` para el cronómetro). Noche del 24 ago 2026.

Tres preguntas, y las tres tenían la misma forma: **un número que se citaba sin
que nadie lo hubiera tomado.**

| | La pregunta | La respuesta |
|---|---|---|
| **1** | ¿cuánto tarda de verdad `PUT notas/lote`? | **entre 3,8× y 5,9× más rápido** que N peticiones sueltas, y **717 → 220 consultas** |
| **2** | ¿cuáles son las rutas sin comprobar? | **cuatro, no siete** — y las cuatro ya tenían nombre en el 09. Cerradas: **542/542** |
| **3** | ¿qué §§ cita el código y ya no existen? | **cero**, sobre 1.304 citas. Y comprobé la dirección que la herramienta no mira |

---

## §1 — El cronómetro de `PUT notas/lote`

### Por qué existía el hueco

La [§2 del plan 20](../20-pantalla-de-notas.md) lleva su tabla marcada como
**«estimado»**, y su §7.c dice por qué: el endpoint tiene **trece tests y ninguna
medición**. La tabla estaba compuesta a partir de piezas medidas en otros sitios
—los ~28 ms de arranque de la [§1 del 02](../02-plan-rendimiento.md), los
~40–80 ms de resolver quién pregunta de su §4, los ~1,7 ms del agregado de
[`coste-del-recalculo.php`](../../../tools/coste-del-recalculo.php)— sin que nadie
cronometrara el endpoint entero.

La medición vive en
[`tests/Barrido/CosteDelLoteDeNotasTest.php`](../../../tests/Barrido/CosteDelLoteDeNotasTest.php),
grupo `barrido`, que `phpunit.xml` excluye de la corrida normal:

```bash
docker exec -w /app/.worktrees/<sufijo> -e DB_TEST_DATABASE=simonbolivar_testing_<sufijo> \
    8myvc-app-1 php artisan test --group=barrido --filter=CosteDelLoteDeNotasTest
```

**Vive en `tests/` y no en `tools/` por la razón que CLAUDE.md ya fija para
`SuperficieDeUnTokenTest`: cronometrar una escritura es ejecutarla**, y la
transacción que envuelve cada test es lo único que hace eso inocuo. Un script en
`tools/` tendría que elegir entre no medir el camino real o dejar notas escritas.

### La población y la máquina

- **45 notas** = una subunidad × 45 alumnos. Grupo `Cuarto` (id 98, **68**
  matriculados), asignatura 1239, periodo 31, **180 notas en la asignatura** (4
  subunidades, para que el agregado del recálculo lea lo que leería de verdad).
- Base `simonbolivar_testing_adx`, propia de esta sesión.
- Contenedor `8myvc-app-1`, `aarch64`, **PHP 8.4.24**, **MySQL 8.0.42** en **otro
  contenedor** (cada consulta paga un salto de red local que en el servidor es un
  socket), 10 núcleos.
- **11 pasadas medidas + 1 descartada**, cuatro bloques **rotando de orden** en la
  misma ventana, **mediana**.

### Las dos corridas

|  | corrida 1 | corrida 2 |
|---|---|---|
| 45 × `PUT notas/update` | 3.845 ms | 8.729 ms |
| 1 × `PUT notas/lote` | 1.018 ms | 1.928 ms |
| **razón (mediana)** | **3,8×** | **4,5×** |
| **razón (mejores pasadas)** | **7,2×** | **5,9×** |
| **consultas** | **717 → 220** | **717 → 220** |

### La ventana en la que se tomó, con nombre

Esto es lo que hace releíble el número dentro de seis meses, y por eso va aquí y
no en una nota al pie:

```
corrida 1   antes: load 28,85 · swap 23.603 MB de 24.576
            después: load 22,40 · swap 26.466 MB de 27.648
corrida 2   antes: load 30,41 · swap 23.915 MB de 26.624
            después: load 28,44 · swap 27.765 MB de 28.672
```

Y lo que había dentro de esa ventana, que se supo **después** de medir y que hay
que nombrar entero:

- **cinco `ng test` vivos a la vez** de un carril de `myvc_front` — cada vez que
  uno se quedaba en «Building…» lanzaban otro y el anterior seguía vivo—, más
  seis `ng serve` de horas. Lo encontró `myvc-front-98`;
- **`8myvc-database-1` acabó muriendo por OOM** (`Exited (137)`) poco después, y
  se volvió a morir tras el primer arranque;
- y **tres peticiones colgadas de `bolfinales/detailed-notas-year-group`** que
  dejaron al backend sirviendo **500 a todo** durante unos minutos, con un grupo
  que había contestado en 7 s quedándose 133 s sin contestar.

**Los milisegundos absolutos de arriba son de esta máquina con cinco suites de
otro repositorio encima y un MySQL a punto de morir; no son de esta máquina.**
Casi con seguridad es también la explicación del factor de dos entre las dos
corridas: no midieron el mismo mundo.

> **Y la lección de turnos que sale de ahí, que vale para todo lo que venga:
> una medición contra el docker compite con las suites y con la base aunque no
> sea una suite.** Toda la noche estuvimos pisándonos sin saberlo, cada uno
> mirando el swap y diciéndole al otro que mirara el suyo — **y el swap éramos
> nosotros**.

> **Y aun así no hay que repetir la medición, por una razón que conviene que
> sobreviva a este documento: la carga no exagera esta ventaja, la esconde.** Se
> suma casi igual a los dos lados —una petición lenta y un lote lento— y **una
> suma igual a los dos lados acerca cualquier razón a 1**. Se ve en los propios
> datos: la razón de las **mejores** pasadas, las de la máquina menos ocupada, es
> mayor (7,2× y 5,9×) que la de la mediana (3,8× y 4,5×). Con la máquina limpia
> esto sale **más alto**, no más bajo.
>
> Es también la razón de que el diseño mida **cuatro bloques alternados en la
> misma ventana** en vez de un tiempo suelto: lo que el plan 20 afirma es una
> **comparación**, y una comparación sobrevive a la carga.

### De dónde sale el coste, que es la pregunta de verdad

«El lote es más rápido» no hacía falta medirlo. Lo que había que confirmar o
tumbar es **de qué está hecho** el coste de guardar una nota. Por eso el montaje
tiene **dos bloques de referencia** que no son adorno:

| Bloque | Qué es | Para qué |
|---|---|---|
| `control` | 45 × `GET periodos` | el **precio de entrar**: la misma ruta con la que el 02 §4 midió la resolución del token |
| `recalculo` | 45 × `recalcularPorNota()` **sin HTTP** | lo que cuesta el recálculo **solo** |

Sin los dos, lo que cuesta una nota por encima del camino común sale por
**resta** — y una resta no dice de qué está hecha: un número grande no distingue
*«resolver al usuario es caro»* de *«recalcular es caro»* de *«escribir es
caro»*, y las tres lecturas mandan a optimizar sitios distintos.

**En consultas por petición**, que es lo que **no depende de la carga**:

```
GET periodos            6 consultas   ← el camino común
recalcularPorNota()     6 consultas   ← el recálculo de una nota
PUT notas/update       16 consultas   ← las dos anteriores + 4 suyas
```

De las **497 consultas que el lote ahorra** (717 → 220): **~264 son el camino
común** (44 × 6) y **~260 el recálculo por nota** (44 × 6). **Mitad y mitad.**

### Lo que esto le corrige al plan 20

La §2 del 20 dice que lo caro **no** es la agregación del recálculo (~1,7 ms) sino
el coste fijo de resolver quién pregunta.

- **Confirmado en lo esencial**: el coste fijo es el término grande — el **47%**
  (corrida 1) y el **69%** (corrida 2) del tiempo de una `notas/update`.
- **Corregido en la mitad que no es la agregación**: `recalcularPorNota` **no es
  sólo** la agregación. Son **seis consultas por nota** —la agregada, el sello, el
  UPSERT, el porcentaje—, y cronometrado a pelo da **42,9 ms por nota** en esta
  máquina. La frase *«lo caro es el coste fijo, el recálculo son 1,7 ms»* es
  cierta de `calcular()` y **falsa del recálculo entero**.

No cambia ninguna decisión de hoy —el lote se lleva las dos cosas— pero **una
frase que sobrevive mal manda a alguien al sitio equivocado dentro de seis
meses**: quien quiera ahorrar consultas leyendo esa frase mirará sólo a la
autenticación y dejará la mitad del ahorro sin ver.

> Lo funde quien coordina en el 20 y en el 02: los dos están cogidos.

### Y lo que NO está dentro del número, que es lo que lo hace una cota inferior

- **el arranque del framework por petición** (~28 ms, 02 §1). `$this->putJson()`
  reutiliza la aplicación ya construida: en producción son **45 veces** en la
  columna suelta y **una** en el lote;
- **php-fpm, nginx y la red**. Aquí no hay proceso nuevo ni socket;
- **OPcache está apagado en el CLI** (`opcache.enable_cli=Off`) y **encendido en
  fpm**, así que ni siquiera es el mismo PHP el que atiende en producción;
- **la simultaneidad**, que es lo que de verdad llena `Entry Processes`. Esto mide
  **tiempo**; el contador cuenta **peticiones dentro de PHP a la vez**. Un solo
  proceso midiendo en serie no puede ver eso.

### El 429 de la §1 del 20: era una sospecha y ya es un hecho

**La petición número 121 de 135 devolvió 429.** Tres columnas de 45 notas son 135
peticiones contra el cubo de **120/min por usuario** de `throttle:api`. Es
exactamente el error que reportan los docentes «cuando lleva muchos envíos a la
vez». **En lote esas tres columnas son tres peticiones.**

> **El limitador se apaga para cronometrar y se mide aparte, y eso es la parte
> del método que hay que leer.** Con el limitador puesto, cronometrar 45
> peticiones once veces daría 429 a partir de la tercera pasada — **y un 429 es
> rapidísimo**: la medición saldría *mejor* cuanto más roto estuviera el caso.
> Es el instrumento que miente con la cara del resultado, cazado **antes** de que
> mintiera. Por lo mismo, **cada respuesta del bloque suelto se comprueba 200** y
> el bloque aborta si alguna no lo es.

---

## §2 — Las rutas sin comprobar: son **cuatro**, no siete

### El número que no cuadraba

`ESTADO-ACTUAL.md` dice **535/542**, o sea siete sin comprobar. Medido esta noche
sobre `0dc21d7`, con la suite entera y mi propio `COBERTURA_RUTAS`:

```
Rutas: 538/542 con la respuesta comprobada (99%)
Controladores: 98/98 con alguna comprobada
```

**Cuatro sin comprobar, no siete.** Un número que se mueve solo hay que
explicarlo antes de publicarlo, así que:

| | |
|---|---|
| **suite entera** | **538/542** — 4 sin comprobar |
| **sólo `Contrato`** | **537/542** — las mismas 4 **más `GET /`**, el stub de `laravel new`, que con `--testsuite=Contrato` cae siempre del lado de las no comprobadas |

De los dos que faltan hasta siete, **uno se reconcilia exactamente**:
`SecretarioTest`, añadido después de que se escribiera el 535, es hoy el único
que comprueba `PUT api/alumnos/guardar-valor`. Quitando los tres tests nuevos de
`6319ab0..0dc21d7` la cuenta sube a seis, no a siete.

**El séptimo no lo puedo reconciliar desde aquí** y no voy a inventarle una
explicación. Las dos candidatas, y las dos son plausibles:

1. el 535 se midió sobre un árbol anterior al que tengo;
2. **el 535 se midió con el `/tmp` compartido**, que es el modo de fallo que este
   repositorio ya pagó una vez —86 de 539 cuando eran 346— y que con catorce
   sesiones vivas está más cerca que nunca.

> **Y el dato que cierra la discusión sin necesitar ninguna de las dos**: el
> [09](../09-pendientes.md), en «*lo que queda sin comprobar tiene nombre: tres
> métodos y un verbo*», **ya las tenía con nombre y ya decía cuatro**, medido
> sobre la suite entera. O sea que las «siete» nunca fueron siete rutas
> distintas: eran cuatro más `GET /` más el desfase de la corrida.

### Las cuatro, y las cuatro cerradas

Están en
[`tests/Contrato/TresMetodosYUnVerboTest.php`](../../../tests/Contrato/TresMetodosYUnVerboTest.php),
que toma su nombre del título del 09. Ninguna toca un fichero de otra sesión.

| ruta | qué se fija |
|---|---|
| `POST api/escalas/store` | **ignora el cuerpo entero** y escribe siempre la misma plantilla (`SUPERIOR`, orden 5, `S`, 91–100, `perdido = 0`) en el año del que pregunta |
| `POST api/definiciones_comportamiento/store-escrita` | el texto cae en `frase` y **no** en `frase_id`; con el cuerpo vacío **no deja fila** (500); y contesta **201**, no 200 |
| `POST api/frases_asignatura/store/{frase_id?}` | **las dos puertas**: sin parámetro guarda el texto, con parámetro engancha el catálogo **y descarta el texto del cuerpo**; el `periodo_id` es el del usuario (§27); con el periodo cerrado no escribe |
| `PATCH api/tiposdocumento/{tiposdocumento}` | que **el verbo llega** al mismo `update` que el `PUT`, con **cuerpo parcial** |

Tres cosas que salieron al escribirlos y que no estaban escritas en ninguna parte:

- **`escalas/store` no crea la escala que le mandas.** Es un «añadir renglón» de
  rejilla: crea la plantilla y la pantalla la edita después con `escalas/update`.
  Se comprueba mandando un cuerpo **contrario** a la plantilla y no uno vacío —
  con el vacío, «no lee el cuerpo» y «el cuerpo venía vacío» dan el mismo
  resultado y el test no distinguiría cuál es cierta. **El día que alguien le
  ponga validación al cuerpo, esta ruta deja de funcionar sin que el cuerpo haya
  cambiado.**
- **`store-escrita` contesta 201** y sus hermanas de catálogo 200. No es la
  decisión de nadie: devuelve el modelo de Eloquent recién guardado y Laravel
  traduce eso a *Created*. **Un front que compare `=== 200` ya está tratando este
  éxito como un fallo.**
- **El `PATCH` de `tiposdocumento` se prueba con cuerpo parcial y no repitiendo el
  `PUT`.** Es lo único que el `PUT` no dice: por el `PUT` el front manda la fila
  entera, así que si alguien reescribe `update` leyendo el cuerpo a secas —sin
  `CamposQueVinieron`— **se rompería sólo por el `PATCH`** y sólo para quien lo
  use.

> El del `PATCH` **no** comprueba lo que hace `update`: eso ya tiene tests, y
> repetirlo sería escribir un test para código que ya tiene uno. La distinción es
> la que el propio 09 pide que se haga al leer el resto.

### Y con las cuatro dentro: **542 de 542**

Corrida entera en máquina ya libre —`848 s` contra los `2.132 s` de la de antes,
que es otra medida de lo que era aquella ventana—, base propia y
`COBERTURA_RUTAS` propio:

```
Rutas: 542/542 con la respuesta comprobada (100%)
Controladores: 98/98 con alguna comprobada
--- 0 controladores donde nadie mira ninguna respuesta ---
--- 0 controladores a medias ---

Tests: 1362 passed (9223 assertions)
```

**Los dos barridos siguen sin contar como comprobar**, que es lo que hace que el
100% signifique algo: `AutenticacionTest` (523 rutas en una ejecución) y
`RutasPreLoginTest` (530) hacen pasar casi la API entera por el router para sus
snapshots de guards, y un test así dice que la ruta existe y que su guard es el
que era — **no mira lo que devuelve**. El 542 es de tests que sí lo miran.

> Y una salvedad que conviene dejar puesta para que nadie lea el 100% como «ya
> está»: **`GET /` cuenta aquí porque la corrida es la suite entera.** Con
> `--testsuite=Contrato` sigue cayendo del lado de las no comprobadas, y quien
> mida así verá 541. El número que hay que citar es el de la suite entera, y
> **con qué suite se midió va siempre al lado del número.**

---

## §3 — `secciones-citadas.py`: cero, y por qué me lo creo

```
§§ declaradas en docs/ ........ 427
§§ distintas citadas del código . 230
citas totales ................... 1304
huérfanas ....................... 0
```

La autoprueba (`--autoprueba`) pasa sus ocho trampas con el número esperado en
cada una.

**Pero un cero es exactamente el número que hace archivar el asunto**, así que
antes de creérmelo miré el detector, que es la mitad de la regla que muerde. Su
población es `app/ tests/ tools/ routes/ config/ database/` — **`docs/` no
está**. Y `docs/` se cita a sí mismo todo el rato: la razón de existir de esta
herramienta —*«renumerar deja atrás a quien citaba»*— vale igual ahí.

Medí esa dirección a mano, con las funciones de la propia herramienta:

```
ficheros .md recorridos ......... 50
§§ declaradas en docs/ .......... 427
§§ distintas citadas DESDE docs/  262
citas totales desde docs/ ....... 1496
huérfanas ....................... 6
```

**Y las seis son falsas**, cada una por un motivo que ya estaba escrito:

| | |
|---|---|
| `§117`, `§128`, `§138`, `§160` | son **huecos** —números que nadie usó al abrir lotes sobre la marcha— y los documentos los citan **como huecos**, para que nadie los vaya a buscar |
| `§999` | es el número inventado con el que se prueba que **la alarma suena** |
| `§212` | es de un documento de **`myvc_front`**, no de `docs/` |

### Y por eso NO le pongo un `--docs` a la herramienta

Sería una línea. Hoy imprimiría **seis falsos**, y **un detector que grita en
falso deja de leerse** — y entonces no protege nada, que es peor que no tenerlo.
Para que un `--docs` valiera habría que enseñarle antes las tres familias de
arriba: los huecos declarados como huecos, el número de prueba, y la cita a otro
repositorio. Queda escrito aquí para que quien lo intente sepa contra qué.

**Conclusión de las dos direcciones: limpio.** Y el trabajo no es el cero: es
haber medido la dirección que la herramienta no cubre.

---

## §4 — Lo que esta sesión NO hizo

- **No tocó** `Reloj.php`, `EscalaDeNotas.php`, `Sesion.php`, los dos
  middlewares, `DefinitivasPeriodosController.php` ni `NotasController.php`.
  El cronómetro **llama** a `notas/update` y a `notas/lote`; no los edita.
- **No escribió** en `ESTADO-ACTUAL.md`, ni en el 05, ni en el 09, ni en
  `DESPLIEGUE.md`, ni en el 20 ni en el 02. Los números van aquí y los funde quien
  coordina.
- **No corrió nada contra producción.**
- **No cambió ningún cuerpo, campo ni ruta**, así que no hay nada que decirle al
  buzón del front por este lado. Lo único con destinatario es el número del §1,
  que ya salió suelto.

## §4.b — Un `larastan` en rojo que era la caché, y cómo se cazó

Va escrito **con el error de diagnóstico dentro**, porque el error es la parte
útil.

Corriendo `composer run stan` sobre lo que escribí salieron **dos** errores:

1. un `match` sin `default` en el cronómetro. **Mío**, arreglado. Y el `default`
   no es ceremonia de larastan: un bloque nuevo en `$orden` sin su rama saldría
   en la tabla con **tiempo cero**, que es un número plausible y falso;
2. un `ignore.unmatched` en `phpstan.neon:334-337`, sobre
   `AppMobile/AsistenciasAppController.php` — un fichero que **no toqué**.

El segundo se veía muy real, y por dos motivos que sonaban a hallazgo: `diff`
contra su gemelo `Tardanzas/AsistenciasController.php` da **cuatro diferencias**
—el `namespace`, el nombre de la clase y un `a.created_at` en cuatro `SELECT`— y
**la línea de la que sale el error es byte a byte la misma**; y la entrada gemela
de `phpstan.neon`, idéntica salvo la ruta, **sí casaba**. Dos ficheros iguales con
resultados distintos.

**Y era la caché.** Con `/tmp/stan-ad` **borrado de verdad** —`rm -rf` y volver a
crearlo— y el `match` arreglado:

```
 [OK] No errors
```

### Lo que fallaba en mi comprobación, que es lo que hay que leer

Yo ya había «descartado la caché», y lo había hecho mal. Mi razonamiento fue *«la
primera corrida tenía el directorio recién creado y la segunda lo tenía caliente,
y las dos dan el error, luego no es la caché»*. **Las dos corridas compartían la
misma caché a medio llenar**, que no es lo mismo que una caché fría: la primera la
pobló mientras había un error mío dentro, y `ignore.unmatched` compara los
patrones contra **los errores reportados**, o sea contra un conjunto que en una
corrida mixta —parte de disco, parte de caché— no es el del proyecto entero.

O sea que **repetir la medición dio el mismo número dos veces y el número era el
equivocado**, que es exactamente la segunda mitad de la regla de CLAUDE.md: *un
detector puede contar bien un síntoma y no estar contando la causa; repetirlo da
lo mismo otra vez*. Aquí el detector era el mío.

> **La lección, y va en la lista del §5:** «lo comprobé dos veces» no es
> «comprobé la caché». Vaciar un directorio y **crear** un directorio vacío se
> parecen mucho en el `ls` y no son lo mismo; el que vale es el `rm -rf`.

Lo que sí queda establecido y no era mío: **el `[OK]` de `ESTADO-ACTUAL.md`
estaba viejo** — `main` tiene el nivel 7 en rojo por otra cosa
(`ProfesoresController:473`, un *«Negated boolean expression is always true»* que
llegó dentro del commit que arrastró trabajo de cinco sesiones, o sea **sin su
pasada de larastan**). Eso lo encontró otra sesión y lo lleva quien tiene el
fichero. **Y ninguna entrada de `phpstan.neon` se toca**: no había nada que
arreglar ahí.

## §5 — Lo que se lleva de método

1. **Un cronómetro que no puede distinguir el caso roto del caso rápido no es un
   cronómetro.** Un 429 es más rápido que guardar bien; sin comprobar el 200 de
   cada respuesta, la medición premia el fallo.
2. **La carga no exagera una razón: la esconde.** Se suma igual a los dos lados y
   acerca cualquier razón a 1. Por eso una comparación alternada en la misma
   ventana sobrevive a una máquina al 97% de swap y un tiempo suelto no.
3. **Una resta no dice de qué está hecha.** Si lo que se quiere saber es de dónde
   sale un coste, hay que medir los sumandos, no restarlos.
4. **Un número que se mueve solo se explica antes de publicarse.** El 535 → 538
   se reconcilia en uno; el otro no, y decirlo vale más que rellenarlo.
5. **Un cero se comprueba mirando la población, no repitiendo el comando.** Y
   cuando la herramienta no cubre una dirección, la dirección se mide a mano antes
   de dar el cero por bueno.
6. **`| head` en un comando que termina en un número lo corta.** Me lo cobró esta
   misma noche: un `| head` mató el lanzamiento de la suite entera y el fichero
   de salida no llegó a existir. Es la regla del briefing, y no es teórica.
7. **«Lo comprobé dos veces» no es «comprobé la caché».** Dos corridas seguidas
   comparten la caché que pobló la primera, así que el segundo número no es
   independiente del primero. **Vaciar** un directorio y **crear** un directorio
   vacío se parecen en el `ls` y no son lo mismo. Me costó un hallazgo entero
   (§4.b): repetí la medición, salió igual, y estaba mal las dos veces.
8. **Para saber si tienes una suite viva, `ps` no vale: se llaman todas igual.**
   Ocho `php artisan test` en el mismo contenedor y ninguno dice de quién es. El
   entorno sí:

   ```bash
   docker exec -u 0 8myvc-app-1 sh -c 'for p in /proc/[0-9]*; do
     tr "\0" "\n" < $p/environ 2>/dev/null | grep -q "^DB_TEST_DATABASE=simonbolivar_testing_<sufijo>$" \
       && echo "VIVA ${p#/proc/}"; done'
   ```

   Es lo que permite matar **la tuya** sin tocar las de las otras cinco sesiones,
   que es la única forma de que la regla «no lances una suite con otra viva» se
   pueda cumplir de verdad.
