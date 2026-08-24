# MUERTO-1 — qué métodos de controlador no alcanza ninguna ruta

**Sesión `8myvc-9e`, noche del 24 ago 2026.** Herramienta:
`tools/metodos-sin-camino.py`. **Lectura pura: no toca la base ni el contenedor.**

Sale de que `8myvc-ad` encontrara un controlador de **510 líneas con una docena
alcanzables**, y de que su herramienta —que va **de la ruta hacia abajo**— no
pueda verlo por construcción: *un método que nadie llama no aparece, y eso se lee
igual que «ahí no hay problema»*.

---

## §1 — La pregunta, y por qué el ámbito son los controladores

**«Sin ruta» NO es «muerto».** El contraejemplo lo trajo `ad` y es de los que
deciden un criterio: `App\Services\ContextoDeUsuario::construir` **no tiene
ninguna ruta** y está en el camino de **toda petición autenticada**
(`construir ← para ← User::fromToken()`). Con el criterio «ningún `Route::` lo
nombra», **los servicios salen todos y son lo más vivo del proyecto**.

Así que la pregunta medida no es «¿tiene ruta?» sino:

> **¿Existe un camino de llamadas desde algún método enrutado hasta aquí?**

Y el ámbito es **`app/Http/Controllers/` y nada más**, porque ahí la respuesta
significa algo: **un método público de controlador existe para ser enrutado.** En
un servicio o un modelo no significa nada.

---

## §2 — La población, antes de cualquier resultado

| | |
|---|---|
| ficheros de controlador | **112** |
| clases | **114** (113 nombres cortos: una se repite en dos namespaces) |
| métodos, todos | **722** |
| métodos **públicos** | **657** |
| pares (clase, método) nombrados en `routes/` | **539** |
| **rutas a métodos que no existen** | **0** |
| alcanzables por **cierre transitivo** | **678** |
| **públicos sin ningún camino** | **41** (1.129 líneas) |
| de ésos, implementan una interfaz de `vendor/` | **7** — los llama la librería |
| **CANDIDATOS** | **34** (1.019 líneas) |

**No es una lista de borrados: es una lista de candidatos.** La regla de la casa
pide comprobar que ningún cliente resucite el camino, y esta misma noche apareció
un método muerto con el nombre **casi idéntico** a uno vivo.

---

## §3 — El entregable: qué arrastran los 34

Era la pregunta de fondo — *si nuestros inventarios cuentan código que nadie
ejecuta, algunos cuentan de más*:

| | de 34 |
|---|---|
| leen `unidades`/`subunidades` | **2** |
| escriben en SQL crudo | **2** |
| escriben por Eloquent | **3** |
| tocan `notas_finales` | **3** |
| llevan un `SELECT *` | **3** |
| **llaman a una guarda** | **0** |

### Y el cruce con mi propio inventario: **cuenta de más, y por cuánto**

| | |
|---|---|
| lecturas de `unidades`/`subunidades` publicadas en [bi-1.md §5](bi-1.md) | **144** (74 + 70) |
| **dentro de un método inalcanzable** | **4** |
| **que de verdad se ejecutan** | **140** → **72 + 68** |

Las cuatro son de `Informes/CertificadosPersonaController`:
`definitivasMateriasXPeriodo` (:306) y `asignaturasPerdidasDeAlumno` (:427), dos
referencias cada una. **Es la tercera corrección a esa cifra en una noche** —74/70
→ 75/71 → 74/70 → **72/68**— y las tres veces por una definición distinta de qué
se cuenta, no por un cambio en `app/`.

**Y las 57 «hay que acotarlas» no se mueven**: ninguna de las cuatro estaba en esa
lista, porque las dos llegan caminando desde una nota con el alumno fijado.

### Lo que confirma de otros

- **`Alumnos/Definitivas::calcular_notas_finales_asignatura` y su hermano de
  periodo** escriben en `notas_finales` **y son inalcanzables**. `ESTADO-ACTUAL`
  ya lo afirmaba —*«sin guarda pero inalcanzables… la fase 5 borra la clase
  entera»*— y aquí queda **medido por un camino que no comparte supuesto** con
  aquella auditoría.
- **Ninguno de los 34 llama a una guarda.** O sea que los inventarios de
  autorización de esta noche **no** están contando código muerto.

---

## §4 — El bloque grande, y por qué duele

**`Informes/CertificadosPersonaController` concentra 445 de las 1.019 líneas** en
cuatro métodos inalcanzables —`definitivasMateriasXPeriodo` (178),
`detailedNotasGrupo` (150), `asignaturasPerdidasDeAlumno` (68) y
`asignaturasPerdidasDeAlumnoPorPeriodo` (33)—. **Coincide con lo que `ad` midió
por su lado** («510 líneas, unas 12 alcanzables»), y las dos medidas son
independientes: una parte del enrutado, la otra del método.

Su frase es la que resume el riesgo:

> **Dos ficheros pueden ser copia uno del otro y no ser el mismo problema, porque
> lo que decide no es el contenido sino qué los alcanza.**

Y el patrón repetido: **`asignaturasPerdidasDeAlumnoPorPeriodo` está definido en 8
controladores y sin camino en 6**, y **`periodosPerdidosDeAlumno` en 5 y sin
camino en los 5**. El segundo es el único llamante del primero donde existe: **un
subárbol entero, no hojas sueltas.**

---

## §5 — Las cinco trampas, y las cinco eran del detector

Ninguna salió de leer el código: **las cinco salieron de que el número parecía
raro.**

| | Trampa | Qué decía el número mal |
|---|---|---|
| 1 | **un fichero con cuatro clases** (`Alumnos/ImportarController.php`) | atribuía los métodos a la clase equivocada e **inventaba «4 rutas a métodos que no existen»**. Son **0** |
| 2 | **dos clases con el mismo nombre corto** (`BolfinalesController`, raíz e `Informes/`) | la clave por nombre corto **colapsaba** una y se llevaba sus métodos: **646** en vez de **657** |
| 3 | **`$this->x()` es por clase, no global** | el repo copia el mismo método en ocho controladores, así que el nombre «aparece llamado» **porque lo llama otra clase**: **15** huérfanos en vez de 48 |
| 4 | **`new C()` y luego `$v->m()`** | `GuardarAlumno::valor` —el método del plan, en el camino de `PUT alumnos/guardar-valor`— salía **inalcanzable**. Con esto: **41** en vez de 48 |
| 5 | **las interfaces de `vendor/`** | `collection`, `headingRow`, `sheets`… los llama **la librería de Excel**: **7** falsos muertos |

**La 4 es la que más asusta**, porque el falso positivo era **precisamente el
método que este proyecto lleva toda la noche discutiendo**. Por eso se resuelve
**grueso a propósito**: si una clase se instancia con `new` en código alcanzable,
sus métodos llamados con `->m(` en cualquier parte pasan a alcanzables. En una
lista de candidatos a borrar, **equivocarse hacia «vivo» cuesta una revisión y
hacia «muerto» cuesta un endpoint.**

Y el recorrido de la cifra, que es la lección: **15 → 48 → 67 → 41 → 34**, sin que
cambiara una línea de `app/`. Como el 4 → 6 → 18 de las escrituras: **cinco
definiciones, cinco números.**

### Y un centinela que se disparaba solo

El aviso de «llamadas dinámicas invalidarían el cierre» contaba los `$fila->{...}`
de **acceso a propiedades** —seis en el proyecto— y por tanto **saltaba siempre**.
Ajustado a exigir el paréntesis (`->{...}(`), da **0**: no hay despacho dinámico de
método y **el cierre vale**. Es la misma falta que contar la línea `Duration:` como
un test lento: **un centinela que se dispara solo enseña a ignorarlo.**

---

## §6 — Lo que este detector NO contesta

- **Sigue llamadas, no ramas.** Un método invocado sólo dentro de un `if` que nunca
  se cumple cuenta como alcanzable. La pregunta de `ad` —*sus 18 «escriben por un
  ayudante» dan por hecho que la línea que llama al ayudante se ejecuta*— es
  **de dentro** de un método; ésta es **entre** métodos. **No la contesta, y no la
  contesta ni de paso.**
- **No mira servicios, modelos ni comandos** (§1). Un método de controlador
  llamado desde un `Command` saldría inalcanzable; comprobado que ninguno de los
  34 lo está, pero el detector no lo garantiza por construcción.
- **No prueba que nadie los llame desde fuera del repo.** Los cuatro clientes no
  llaman métodos PHP, pero un `Route::` de otra rama sin fundir sí podría.

---

## §7 — El cruce pedido, contestado

> *Que tu lista no marque como inalcanzable nada que esté en un camino desde
> `routes/`.*

**No lo hace, y por construcción: las semillas del cierre SON los pares de
`routes/`.** Lo que podía fallar era **una arista que faltara**, y falló: era la
trampa 4, y por eso `GuardarAlumno::valor` salía en la lista. Corregida, **los 34
no incluyen ningún método al que llegue un camino conocido**.

**Y no contradice ninguna de las listas de `ad`.** Las suyas que parten de
`routes/` no pueden contener código muerto —su punto de entrada es una ruta— y la
única que barría `app/` entero ya la comprobó una a una. **Los dos inventarios
coinciden donde se tocan** (`CertificadosPersonaController`), por caminos que no
comparten supuesto.
