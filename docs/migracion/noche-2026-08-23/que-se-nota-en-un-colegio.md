# Qué se nota en un colegio — la tanda de la noche del 22 al 23 de agosto

> Montado por `8myvc-2f` a petición de quien coordina, **para copiar a
> `docs/DESPLIEGUE.md`**. No sustituye a ese documento: es la parte que hay que
> tener delante para decidir **el orden de la tanda** y **qué avisar antes**.
>
> **Cada fila sale de un documento de lote o de un `git diff` contra `main`.**
> Lo que no encontré escrito está marcado como hueco con el nombre del lote — no
> está deducido. **Una tabla de despliegue con una fila inventada es peor que una
> fila que falta**, y aquí lo que se despliega a partir de ella son dieciséis
> colegios.

## Lo primero, porque cambia el orden entero de la tanda

| | |
|---|---|
| **Migraciones nuevas** | **ninguna**, en los trece lotes |
| **Rutas** | **539 antes y 539 después**. Ninguna nace, ninguna muere |
| **Formas de respuesta** | ninguna respuesta pierde ni gana claves |
| **Capacidades que se quitan** | **una sola**, y es del lote E |
| **Cosas que se encienden** | **una sola**, y es del lote B |

> **La tanda es casi toda «deja de pasar».** De trece lotes, **siete no tocan
> `app/`** y los seis que lo tocan cambian, casi siempre, un 500 o un guardado
> silencioso por un código honesto. Eso significa que **el orden dentro de la
> tanda es libre**: no hay dependencia entre lotes, ni orden obligatorio como el
> de `password_reminders` de la tanda anterior.

---

## 1. Qué deja de pasar

Ordenado por lo que un colegio notaría antes.

| Lote | Deja de pasar | Ruta |
|---|---|---|
| **D** | Que una petición a medias deje el colegio **sin nombre, sin año y sin los nombres de unidad que se imprimen en todos los boletines**, contestando 200 | `PUT years/guardar-cambios` |
| **D** | Que aparezcan **dos años actuales** y todo el colegio entre en 2018 al siguiente inicio de sesión | `PUT years/toggle-cambiar-valor` |
| **D** | Que **corregirle la redacción a un logro cambie la nota del boletín**, borrando el peso de la unidad | `PUT unidades/update/{id}` |
| **D** | Que un usuario quede **aparcado en un periodo de la papelera**, con las pantallas vacías en 200 y sin forma de volver desde la interfaz | `PUT periodos/useractive/{id}` |
| **D** | Que editar una asignatura **borre sus créditos y su orden**; y que el conmutador de horario escriba en asignaturas de la papelera | `PUT asignaturas/update/{id}`, `toggle-dia` |
| **E** | Que **editar un grupo lo mueva al año de quien lo edita**, con sus matrículas dentro (56 en la medición) | `PUT grupos/update` |
| **E** | Que un profesor se lleve **la imagen privada de un alumno** y el alumno la pierda | `images-users/cambiar-*` |
| **E** | Que un cuerpo parcial vacíe **22 columnas de una ficha de perfil**, ninguna a salvo | `PerfilesController::putUpdate` |
| **E** | Que un grupo sin titular conteste **200 por una puerta y 404 por la otra** | dos copias del mismo método |
| **C** | Que un cuerpo sin `porcentaje` lo deje en `null` y **recalcule las definitivas con el peso perdido** | `PUT subunidades/update/{id}` |
| **A** | Que editar cualquiera de **seis catálogos** con un cuerpo parcial deje el nombre en `''` y el resto en null, contestando 200 | `areas`, `frases`, `niveles_educativos`, `tiposdocumento`, `grados`, `materias` |
| **B** | Que **editar una falta disciplinaria dé error después de haberla guardado** — el usuario volvía a darle a guardar y duplicaba el intento | `PUT disciplina/update` |
| **B** | Que **borrar el rastro no deje rastro**: el borrado de una bitácora no se firmaba | `DELETE bitacoras/destroy/{id}` |
| **K** | Que el cierre de un pedido de cambio se guarde con **la hora escrita dos veces** (`hora:hora:minutos`) | `PUT ChangesAsked/rechazar` y `aceptar-alumno` |
| **K** | Que aceptar o rechazar un pedido escriba **hasta cinco filas de depuración** en `debugging`, una con el texto `ENTROOOOO` | idem |
| **L** *(sin fundir)* | Lo mismo de la hora, en **cada ausencia que sube el lector de tardanzas** — el camino de más volumen de los dos | `POST tardanzas/subir` |

**Además**, en **cinco lotes** hay 500 que pasan a ser 404 o 422. No son un cambio
de capacidad: un id que no lleva a ninguna fila **deja de devolver una traza de
PHP** y pasa a decir que no existe. Códigos añadidos, contados en el diff:

| Lote | `abort()` añadidos |
|---|---|
| E | 5 × 404 · **1 × 403** |
| D | 5 × 404 · 1 × 422 |
| A | 1 × 404 |
| B | 1 × 404 |

---

## 2. Qué capacidad quita, y a quién

**Una sola en toda la tanda**, y hay que avisarla antes de desplegar.

| Lote | Quién podía | Qué podía | Y ahora |
|---|---|---|---|
| **E** | **cualquiera de los 51 profesores** | mandar **la ficha de un profesor a la papelera** (`DELETE profesores/destroy`), y contestaba 200 | **403**: solo superusuario |

> Lo que hace defendible el cambio es la asimetría que lo destapó: **las otras
> tres operaciones de esa misma ficha ya pedían superusuario** —`update` (§37),
> `restore` (§76) y `forcedelete` (§28.4)—. La que borraba era la única que no
> pedía nada.

**Y una que NO quita capacidad aunque lo parezca**, porque conviene decirlo antes
de que alguien lo lea al revés:

| Lote | Qué |
|---|---|
| **C** | `DELETE boletines2/destroy/{id}` y `boletines3/destroy/{id}` pasan de 200 a **403**. **No lo nota nadie**: esas dos rutas no las llama ninguna pantalla, y lo que hacían no era borrar un boletín sino **mandar un alumno a la papelera** |

---

## 3. Qué enciende que hoy no funciona

**La columna que casi nunca llega escrita, y esta noche está casi vacía — que es
un resultado, no un descuido.**

| Lote | Qué vuelve a funcionar | Para quién |
|---|---|---|
| **B** | **La ficha de un alumno nacido en una ciudad sin país vuelve a abrir.** `ciudades/datosciudad` daba 500 y ahora contesta 200 con el país en null | secretaría, en los colegios que tengan alguna ciudad guardada sin país |

Nada más enciende. **Los demás arreglos previenen, no restauran**: impiden que
vuelva a pasar, y no deshacen lo ya escrito. Eso importa para el aviso al colegio:

| Lo que **no** arregla el despliegue |
|---|
| Las filas de `change_asked.deleted_at` y de `ausencias.created_at` **ya escritas con la hora mal** siguen mal (K, L) |
| Las **14 de 17** filas de `dis_ordinales` con `created_at` nulo siguen nulas (D) |
| Los catálogos y fichas **ya vaciados** por un guardado parcial siguen vacíos (A, C, D, E) |
| Las filas de `debugging` **ya escritas** siguen ahí, y siguen siendo el único rastro de las importaciones viejas (L) |

---

## 4. Lo que se nota de golpe el primer día

Un cambio que no es ni «deja de pasar» ni «quita»: **lo que se ve distinto la
primera vez que se abre esa pantalla**.

| Lote | Qué |
|---|---|
| **B** | **El listado de bitácoras encogerá de golpe.** El botón de borrar marcaba la fila y el listado no miraba `deleted_at`, así que lo «borrado» seguía saliendo. Al desplegar, todo eso desaparece a la vez. Nadie ha perdido nada — estaba borrado desde el día que le dieron al botón— pero conviene que quien mire esa pantalla lo sepa |

---

## 5. Los lotes que no tocan `app/`

Siete de trece. **No hay nada que avisar de ellos**, y por eso pueden ir en
cualquier punto de la tanda o quedarse fuera sin coste:

| Lote | Qué dejó |
|---|---|
| **F** | 30 tests. Dos rutas cuyo 500 queda escrito con su mensaje exacto |
| **G** | La medición de los 44 interruptores contra los cuatro clientes |
| **H** | `tools/identificadores-del-cuerpo.py` arreglado: **28 familias, no 29** |
| **I** | El barrido por tipo de token |
| **J** | Las rutas cubiertas sin juzgar |
| **M** | 7 tests que impiden «arreglar» dos modelos con un `?? null` |
| **O / P** | *(sin fundir al montar esto — ver hueco abajo)* |

---

## Huecos: lo que no pude poner sin inventarlo

**Se dejan como huecos a propósito.** Cada uno necesita una línea de su sesión.

| Lote | Qué falta |
|---|---|
| **G** | Su documento cierra con las cuatro veces que mintió un instrumento, y **no trae una sección de «qué se nota»**. Como no toca `app/`, lo he puesto en la tabla de los que no tocan nada — **confírmalo con su sesión** antes de darlo por bueno |
| **I**, **J** | Igual: sus documentos son de leer y reportar y sus ramas no tocan `app/` (comprobado con `git diff`), pero **ninguno tiene sección de despliegue escrita** |
| **O**, **P** | Abiertos al montar esta tabla. Sus ramas **no tocaban `app/`** en ese momento; si eso cambia, hay que añadirlos |
| **E** | Su §100 dice que `perfiles/destroy` **borra un grupo** y que el front lo llama con un `user_id`. Está «documentado; su autorización, cerrada». **No sé si eso cambia lo que ve alguien** — su sesión tiene que decir si hay que avisar |
| **A** | `definiciones_comportamiento/destroy` y `contratos/destroy` contestaban **200 con `No se encontró` y con `0`**. El diff de A añade un solo `abort(404)`, así que **no las dos**. Su sesión tiene que decir cuál se arregló y qué contesta la otra hoy |

---

## Y una advertencia que no es un cambio, del lote C

No entra en la tanda, pero **decide cuándo se puede desplegar el día que se
decida**:

> `definitivas_periodos/calcular-grupo-periodo` **sigue reescribiendo la rejilla
> de un periodo cerrado**. No se ha tocado. Si se decide cerrarla, ese cambio
> **sí apaga algo** —abrir el boletín de un grupo desactualizado en periodo
> cerrado— y entonces **hay que desplegarlo mirando el calendario del colegio**,
> no en cualquier momento.
