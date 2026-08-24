# PUB-1 — tres números para las mismas rutas públicas

**Rama:** `fix/inventario-de-rutas-publicas` · **Sesión:** `8myvc-e0` ·
**Noche del 25 ago 2026**

Tres sitios decían cuántas rutas de la API se pueden llamar sin token y decían
tres cosas distintas: **quince**, **siete** y **diecinueve**. El lote era
contarlas. Lo que salió al contar cambia cuál es la cura.

---

## 1. El hallazgo: **ninguno de los tres envejeció. Los tres nacieron mal.**

La hipótesis natural —y la que da por buena el enunciado— es que los números
fueron ciertos y divergieron según se tocaban las rutas. **Medido, no fue eso.**

`git log` sobre el propio test, contando en **cada** commit las entradas de
`PRE_LOGIN` y comparándolas con el número que su docblock decía en ese mismo
commit:

| commit | fecha | entradas de la lista | dice el docblock |
|---|---|---|---|
| `4992054` | 18 ago | 8 | — |
| `cb7404d` | 18 ago | **9** | **7** |
| `918a8cd` … `7abe18b` | 18 ago | 9 | 7 |
| `0ab5e3d` | 19 ago | **11** | **7** |
| `78cb073` · `58411fc` | 19–20 ago | 11 | 7 |
| `7d758e5` | 23 ago | 11 | 7 |

**El «7» nunca coincidió con la lista.** Nació el 18 de agosto —en el commit que
presume de *«fijar el invariante»`— con **dos de diferencia**, y llegó a **cuatro**
cuando la lista creció a 11 sin que nadie tocara el número.

Y el **«quince»** de `CLAUDE.md` se escribió el 20 de agosto (`53c4efe`, el commit
que creó el fichero). **Ese día ya había 18 rutas sin guard y 11 en la lista.** No
corresponde a ninguna de las dos, ni a las URIs distintas de ninguna de las dos.

### El dato que lo cierra: **no había nada que sincronizar**

Las 18 rutas sin guard de `53c4efe` (20 ago), **listadas una a una**, son
**exactamente las mismas 18 de hoy**. Cero altas, cero bajas en cinco días.

Así que la historia cómoda —*«alguien contó bien y luego el código se movió»*—
**no puede ser cierta: el código no se movió.**

### Y yendo más atrás, el «7» no tuvo nunca un referente

El **18 de agosto el modelo era el contrario**: el guard se ponía **una a una**
(`->middleware('auth.token')` en cada ruta) y `withoutMiddleware` **no existía en
`routes/`**. Ese día, rutas sin el guard: **446 de 534**.

O sea que cuando se escribió «7», la pregunta *«¿cuántas son públicas?»* **no
tenía un conjunto que contar en el código**: sólo existía la lista escrita a mano,
que tenía 9. **El 7 no es un conteo mal hecho — es un número sin referente.** El
modelo «guard por defecto y excepciones marcadas una a una» llegó entre el 18 y el
20 de agosto.

> **Esto cambia la cura.** Un número que envejece se arregla actualizándolo. Un
> número que **nace mal** dice que el problema es escribirlo a mano — y entonces
> corregir los tres deja el mecanismo intacto y sólo reinicia el reloj.

### De dónde salieron el 7 y el 15: **no se pudo reconstruir**

Dos aritméticas dan **15** exacto —18 menos las tres de `auth/*`, y 18 menos las
tres de `tardanzas/subir`— y **no hay forma de distinguirlas**; ninguna tiene apoyo
documental. Queda como **no reconstruible**, y las dos aritméticas anotadas como lo
que son: **coincidencias**. *Cuadrar no es proceder de*, y una historia bonita aquí
costaría más que el hueco.

**Y ojo con el segundo siete**: 18 − 11 = 7, que es el número de las sin-guard que
no están en la lista (§2). **No es el mismo siete**: el del docblock existía cuando
la lista tenía 9 y el modelo de rutas era otro. *Dos sietes que no son el mismo
siete es exactamente la clase de cosa que hace archivar el asunto.*

---

## 2. Qué son de verdad, con denominador

Medido en este árbol, hoy:

| pregunta | respuesta |
|---|---|
| líneas que casan `grep -rn withoutMiddleware routes/` | **19** |
| …de ellas, **líneas de comentario** | **1** (`routes/api.php:33`) |
| rutas `Route::…->withoutMiddleware('auth.token')` | **18** pares verbo+uri |
| …URIs distintas | **17** (`publicaciones/ultimas` tiene GET y PUT) |
| entradas de `PRE_LOGIN` | **11** pares verbo+uri, **10** URIs |

**El 19 de la ficha era el grep contándose a sí mismo**: una de las diecinueve
líneas es la que documenta la convención dentro de `routes/api.php`. *El primer
sitio donde mirar cuando el número sale raro es el detector.*

### Y las 7 que están sin guard y no están en la lista

18 − 11 = **7**, y tienen nombre:

    POST  auth/refresh
    POST  tardanzas/login
    POST  tardanzas/login/traer-datos
    POST  tardanzas/login/traer-datos-ausencias
    POST  tardanzas/subir
    PUT   tardanzas/subir/eliminar-ausencia
    PUT   tardanzas/subir/poner-ausencia

**Que ese 7 coincida con el «7» del docblock es una coincidencia, y conviene
decirlo en voz alta**: el docblock decía 7 desde el 18 de agosto, cuando la lista
tenía 9 y la diferencia con las sin-guard de entonces no era 7. *Dos sietes que no
son el mismo siete es exactamente la clase de cosa que hace archivar el asunto.*

`auth/refresh` está fuera **a propósito y documentado**: sin token responde 401 y
debe hacerlo — no es una pantalla previa al login, es la renovación de una sesión
que ya existe. Las seis de tardanzas no llevan guard **y no están en la lista**:
qué contestan de verdad es la medición del §3.

---

## 3. Lo que contestan de verdad, y por qué el grep **no podía** acertar nunca

Llamando a las **18** sin guard **sin cabecera**, y mirando el código que devuelven:

| ruta | sin token | ¿en `PRE_LOGIN`? |
|---|---|---|
| `GET  api/publicaciones/ultimas` | **200** | sí |
| `PUT  api/publicaciones/ultimas` | **200** | sí |
| `POST api/login` | **200** | sí |
| `PUT  api/login/logout` | **200** | sí |
| `POST api/auth/logout` | **200** | sí |
| `POST api/auth/login` | 422 | sí |
| `POST api/login/recuperar-clave` | 422 | sí |
| `POST api/login/ver-pass` | 422 | sí |
| `PUT  api/login/reset-password` | 422 | sí |
| `POST api/login/credentials` | 400 | sí |
| `PUT  api/login/crear-prematricula` | **500** | sí |
| `POST api/auth/refresh` | **401** | no |
| `POST api/tardanzas/login` | **401** | no |
| `POST api/tardanzas/login/traer-datos` | **401** | no |
| `POST api/tardanzas/login/traer-datos-ausencias` | **401** | no |
| `POST api/tardanzas/subir` | **401** | no |
| `PUT  api/tardanzas/subir/eliminar-ausencia` | **401** | no |
| `PUT  api/tardanzas/subir/poner-ausencia` | **401** | no |

**No hay ninguna ruta pública que nadie supiera que lo era** — el punto 3 de la
ficha no se dispara. Las siete que no están en la lista **contestan 401 igual**:
se defienden en el método, donde `User::fromToken()` aborta.

> **Y ahí está por qué el `grep` no podía dar el número aunque contara bien.**
> Quitarle el guard a una ruta **no la hace pública**: de las 18 sin
> `auth.token`, **siete siguen cerradas**. El grep mide **el mecanismo** y la
> pregunta —«cuántas se pueden llamar sin token»— es sobre **el resultado**. Aunque
> hubiera dado 18 en vez de 19, seguiría sin ser 11. *El instrumento correcto
> sobre el objeto equivocado no se ve mirando el resultado, porque el resultado es
> correcto: sólo se ve preguntando sobre qué se midió.*

### Un cero falso por el camino, y lo dice el detector

La primera versión de esta medición preguntaba
`in_array('auth.token', $ruta->gatherMiddleware())` e imprimió **`POBLACION: 0 sin
guard`**. Cero es imposible habiendo dieciocho, y por eso se vio: **`withoutMiddleware()`
no quita nada de `gatherMiddleware()`** — registra la exclusión aparte, en
`excludedMiddleware()`, y el pipeline la aplica después. Con las dos consultadas,
salen las 18.

*El primer sitio donde mirar cuando el número sale raro es el detector.* Y si el
detector hubiera dicho **17** en vez de 0, no habría saltado nada: **este cero se
cazó por absurdo, no por diligencia.**

**Y lo que lo empeora, que es la parte que me toca:** esto ya estaba resuelto
**en el fichero de al lado**. `CasoDeContrato::exigeToken()` (línea 361) pregunta
por las dos cosas —`middleware()` **y** `excludedMiddleware()`— y lleva el porqué
escrito encima desde que se escribió. **Escribí una medición nueva en vez de usar
la que había, y la escribí mal.** La lección no es sobre Laravel: *antes de
escribir un detector, mirar si el repositorio ya tiene uno* — sobre todo aquí,
donde casi todo lo que hay escrito es el resultado de que algo costara.

*(Y de paso: el comentario de ese helper dice «diría que sí en las **533**». Es
otro número escrito a mano dentro de un comentario, del mismo tipo que los tres
que motivan este lote. No lo toco —el fichero es de contrato y no es mi lote—
pero queda anotado: `CLAUDE.md` dice 542 rutas.)*

### Un 500 anotado y no tocado

`PUT api/login/crear-prematricula` contesta **500** sin cuerpo. Cuenta como pública
—no rechaza por sesión— y **está bien que esté en la lista**, pero un 500 no es
una respuesta: es el formulario público de prematrícula reventando ante un cuerpo
vacío. **No es de este lote** —aquí no se abre ni se cierra ni se arregla ninguna
ruta— y queda anotado para quien lleve el registro.

---

## 4. La cura: **que ningún número se escriba a mano**

Corregir «quince» por el número de hoy deja intacto lo que falló. Lo que entra:

1. **`RutasPreLoginTest::TOTAL_PUBLICAS`** — la única cifra escrita, y la que
   `CLAUDE.md` cita.
2. **`test_el_numero_publicado_sale_de_la_lista_y_no_de_la_memoria`** — falla si
   `TOTAL_PUBLICAS` y `PRE_LOGIN` dejan de coincidir. Es el eslabón que ata el
   número publicado a la lista.
3. **`test_el_inventario_de_publicas_no_tiene_de_mas_ni_de_menos`** — falla si
   `PRE_LOGIN` deja de coincidir con lo que el router hace de verdad, **por las
   dos direcciones**.

El invariante viejo sólo miraba **de más** —que ninguna ruta fuera de la lista
contestara 2xx—, así que `PRE_LOGIN` podía envejecer **de menos** sin que nada
saltara: una entrada que dejara de ser pública seguía figurando, y el número que
se cita salía de contarla. **Que es, literalmente, lo que pasó.**

Encadenados, los tres hacen que el número de `CLAUDE.md` **no pueda envejecer en
silencio**.

### Y los dos controles, porque un verde a la primera no dice nada

El inventario **pasó a la primera**, que es exactamente cuando hay que desconfiar:
un bucle mal escrito —una excepción tragada, un filtro que no casa— pasa igual de
verde que uno que mide. Así que se rompió a propósito, en las **dos** direcciones:

| control | qué se hizo | qué dijo |
|---|---|---|
| **A** | quitar `POST login/credentials` de `PRE_LOGIN` | `POST api/login/credentials` **de más**, por su nombre. Y cae **también** el test del número (11 ≠ 10) |
| **B** | añadir `GET ciudades` —existe y exige token— | `GET api/ciudades` **de menos**: *«está en `PRE_LOGIN` pero YA NO contesta sin token»* |

Cada control hace caer **el mensaje que le toca**, no un fallo genérico. Y el A
hace caer los dos tests por razones distintas, que es la señal de que están
encadenados y no duplicados.

---

## 5. Lo que NO entra

- **No se abre ni se cierra ninguna ruta.** Este lote cuenta y hace que el
  guardián cuente solo.
- **`CLAUDE.md` no lo toco yo.** El briefing lo reserva a `8myvc-94`, y el número
  medido va en el parte para que lo escriba quien lleva ese fichero.

---

## 6. Cómo queda

- **11 tests, 50 assertions** en `RutasPreLoginTest`.
- **`--testsuite=Contrato` entero: 1.336 tests, 9.946 assertions**, verde, 871 s,
  en `simonbolivar_testing_e0` (94 tablas, 2.351 usuarios).
- **Pint: `PASS`, 287 ficheros.** **Larastan nivel 7 sobre el fichero tocado:
  `[OK] No errors`.**
- **Ninguna ruta se abre ni se cierra.** Cero cambios en `routes/`, cero en
  `app/`. Para un cliente **no cambia nada**: este lote cuenta y pone quien
  cuente.
- **El número para `CLAUDE.md` es 11**, y va en el parte: lo escribe `8myvc-94`,
  que es quien lleva ese fichero.

---
