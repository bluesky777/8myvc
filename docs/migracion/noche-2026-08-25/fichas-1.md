# FICHAS-1 — quién ve la ficha de quién

**Rama:** `fix/fichas-sin-mirar-el-rol` · **Sesión:** `8myvc-e0` ·
**Noche del 25 ago 2026**

**Lote de censo. No se recortó ninguna respuesta y no se cerró ninguna ruta.**

La pregunta no era «¿arreglo `GET profesores`?» sino **cuántos endpoints devuelven
fichas de personas sin mirar el rol de quien pregunta**. Lo que sale no es una
lista de rutas: es **una lista de decisiones**, y son diecisiete.

---

## 1. La tabla, con población

Medido en `simonbolivar_testing_e0` (94 tablas, 2.351 usuarios), un token por rol,
la API entera.

| rol | contestaron / dieron 200 | escrituras | campos personales | **proyecciones** |
|---|---|---|---|---|
| `Alumno` | 1.075 / 99 | 2 | **5** | **2** |
| `Acudiente` | 1.073 / 97 | 2 | **7** | **3** |
| `Profesor` | 947 / 390 | **91** | **52** | **17** |
| **`Usuario` sin ningún rol** | 953 / 381 | **85** | **52** | **17** |

---

## 2. **El rol no cambia nada de lo que se ve**

`Profesor` y `Usuario` sin un solo rol tienen **las mismas 52 rutas y las mismas
17 proyecciones — idénticas línea por línea**. Las escrituras difieren (91 vs 85);
**los datos personales, en nada.**

[bar-1](../noche-2026-08-24/bar-1.md) ya lo dijo de las escrituras —*«tener el rol
de profesor no es lo que abre la API»*— con **seis** rutas de diferencia. **Sobre
la lectura de fichas la diferencia es cero**, que es más fuerte y más fácil de
contestar: no hay nada que el rol de profesor abra en esta dimensión.

---

## 3. Lo que el lote venía a buscar: **`GET contratos` tiene siete hermanas**

La proyección más rica son **ocho campos** —`barrio, celular, direccion, email,
estado_civil, fecha_nac, num_doc, telefono`— y es **la ficha del profesorado**.
Sale por **siete rutas**:

    x6   GET  api/profesores                              <- la que trajo el front
         GET  api/profesores/todos
         GET  api/profesores/show/{id}
         PUT  api/participantes/profesores
         PUT  api/unidades/de-profesor
         GET  api/asignaturas/listasignaturas/{persona_id?}
    x1   PUT  api/profesores/listado                      (la misma, sin `telefono`)

**`GET contratos` se recortó sobre `GET contratos`** ([05 §14.4](../05-codigo-muerto-y-roto.md),
`c47ab50`) **y las siete siguen entregando lo mismo.** La ficha del lote lo decía
antes de medirlo: *se cura donde se vio el síntoma y nadie censó la familia.*

> **Y esto es lo que hace el lote abarcable: la decisión es UNA** —qué campos lleva
> la ficha de un empleado— **no siete, y desde luego no cincuenta y dos.**

---

## 4. Las diecisiete decisiones

Agrupadas por proyección y ordenadas por cuántas rutas las repiten, que es el
orden en que conviene mirarlas: **la de arriba se arregla una vez y arregla nueve.**

| rutas | campos | de quién |
|---|---|---|
| **x9** | `celular, direccion, documento, fecha_nac` | alumnos |
| **x7** | `barrio, celular, direccion, documento, email, fecha_nac, telefono` | alumnos, ficha ampliada |
| **x7** | `barrio, celular, direccion, email, estado_civil, fecha_nac, num_doc(, telefono)` | **el profesorado** — §3 |
| **x5** | `celular, direccion, fecha_nac` | alumnos |
| x4 | `fecha_nac` | **la propia sesión** — ver §5 |
| x3 | `barrio, celular, direccion, documento, fecha_nac` | acudientes |
| x3 | `telefono` | **el colegio** — ver §5 |
| x3 | `celular, direccion, documento, email, fecha_nac` | alumnos (asistencias) |
| x2 | `celular, documento, fecha_nac` | acudientes |
| x2 | `email, fecha_nac` | alumnos / usuarios |
| x2 | `barrio, direccion, documento, email, fecha_nac` | alumnos (planillas, actas) |
| x1 | `documento` | `alumnos/documento-check` |
| x1 | `direccion, fecha_nac` | `grupos/listado/{grupo_id}` |
| x1 | `barrio, celular, direccion, email, fecha_nac, num_doc` | `grupos/show/{id}` |
| x1 | `email` | `profesores/conyears` |
| x1 | `email_persona, email_restore, fecha_nac` | `perfiles/username/{username}` |

La lista completa con sus rutas la imprime el propio barrido (bloque `2b`).

---

## 5. **El ruido, medido y no acotado** — y por eso 52 no es un censo

El calibrador es el `Alumno`, porque ahí **sabemos la respuesta**: su superficie
está fijada como cerrada (`SuperficieDeUnAlumnoTest`) y el front midió **7 de 8
puertas en 403**. Sus cinco:

    GET  api/auth/me                        fecha_nac     <- su propia sesion
    POST api/login                          fecha_nac     <- su propia sesion
    PUT  api/aplicacion-descargas/detailed  fecha_nac     <- su propia sesion
    GET  api/years                          telefono      <- el telefono del COLEGIO
    GET  api/years/colegio                  telefono      <- idem

**Tres son su propia sesión y dos no son de nadie. Cero de terceros: el ruido es
del 100%**, y por **dos** mecanismos distintos.

Y el `Acudiente` confirma el marco: sus **siete son las cinco del alumno más dos**
—`PUT acudientes/mis-acudidos` y `GET ChangesAsked/to-me`—, las dos **acotadas por
`INNER JOIN parentescos ... acudiente_id = $user->persona_id`**, o sea sus
acudidos y nadie más. Correcto por diseño.

### Los cuatro sesgos del número, que no se cancelan entre sí

| | qué hace | causa |
|---|---|---|
| **cota baja** | no mira las rutas que escriben | `$personales` sólo se calcula si `$escribio === []` |
| **cota baja** | no ve `username`, que es **con lo que se entra** | no está en la constante `PERSONALES` |
| **cota alta** | cuenta lo tuyo como ajeno | marca por **nombre de campo**, no por dueño |
| **cota alta** | cuenta lo que no es de nadie | **dato institucional**: el `telefono` del colegio |

**No es un intervalo y no se le puede coger el centro**: son cuatro causas
distintas con magnitudes desconocidas. **52 es una lista de sitios donde mirar;
17 es la lista de decisiones.**

---

## 6. Lo que se le hizo al instrumento, que es la mitad duradera

El barrido imprimía **dos preguntas** y su resumen daba **un solo número**
—*«N rutas pasaron de largo con algo dentro»*—. Por eso [bar-1](../noche-2026-08-24/bar-1.md)
tabuló las escrituras y **la columna `PERSONALES` quedó en la salida sin leer**.

1. **Resumen por pregunta**, con población, **impreso siempre** — también cuando la
   cuenta es 0, y con esas palabras: *«se golpearon X rutas, Y contestaron 200, y
   ninguna trajo campos de la lista `PERSONALES`»*. Un bloque ausente se lee como
   un cero, y un cero sin población no distingue «revisé y no hay» de «no miré».
2. **Bloque `2b`: cuántas COSAS son esas rutas.** Agrupa por combinación de campos
   y ordena por repetición. En un repositorio con gemelos, un número de rutas no es
   accionable y uno de proyecciones sí.
3. **Un aserto interno**: `escrituras + personales == rutas con hallazgo`. Caza un
   contador roto —que daría **dos números pequeños y creíbles, que es peor que un
   fallo**— y **su mensaje dice cómo leerlo si algún día salta por lo otro**: que
   los conjuntos dejaron de ser disjuntos es una **mejora**, no un bug, y entonces
   lo que hay que cambiar es el aserto. *Un guardián que salta y se lee como bug es
   cómo se revierte un cambio bueno.*
4. **Los cuatro sesgos escritos en el docblock**, juntos: por separado cada uno
   parece un descargo.

### Y la forma que tenía el fallo

La columna de al lado **ya se había curado de lo mismo** —`EJECUTA` en vez de
`ESCRIBE`, porque `DB::listen` ve la sentencia y no las filas— **con su porqué
escrito, nueve líneas más arriba**. Se curó donde se vio el síntoma y **no se miró
la vecina**. Es la misma forma que el §3, dentro del mismo fichero.

> Por qué el informe de anoche se saltó ésa y no la otra **no se sabe**. Hubo una
> explicación a mano —que el nombre preciso se lee y el ambiguo se pasa por alto— y
> **se falsó**: de los cuatro documentos que citan alguna columna del barrido, tres
> citan `PERSONALES` (diez veces sólo en el 05) y `bar-1` es el único que cita
> escrituras sin ella. **La explicación predecía lo contrario.** `bar-1` iba de
> escrituras: *una salida correcta leída para otra pregunta*, y no hacía falta
> mecanismo.

---

## 7. Lo que se predijo antes de medir, y qué falló

Las predicciones se escribieron **antes** de la corrida, para que fuera una
comprobación y no un descubrimiento.

- **La nominal del alumno: 0 de 5.** Predije los tres boletines, `mis-acudidos` y
  `alumnos/show`. **Ninguna.** El motivo: **predije por «qué devuelve la ruta» sin
  comprobar que el alumno la alcance** — los boletines se cierran con
  `boletin.propio`, `alumnos/show` con `persona.propia`. **El guard decide, y yo
  miré la proyección.**
- **La estructural: acertada.** *«Los 7 del acudiente = los 5 del alumno + algo
  explicable por el acudido»* — exacto. La **exclusión** declarada —*si
  `ChangesAsked/to-me` sale en el alumno, mi lectura de sus cuatro ramas está
  mal*— **se cumple**. Y `mis-acudidos` + `to-me` comparten proyección, como se
  predijo.

> **Una predicción nominal que falla y una estructural que acierta, las dos
> escritas antes, dicen dónde está el conocimiento y dónde no.** Acertar las cinco
> por casualidad no habría dicho ninguna de las dos cosas.

---

## 8. Una cifra de anoche que se movió: **91, no 93**

`bar-1` dio **93** escrituras para `Profesor`; hoy salen **91**. **No lo explico
con seguridad y no lo persigo**: `main` ha fundido muchas ramas desde entonces,
y saber qué dos rutas dejaron de escribir costaría una bisección de seis merges.

**Hay dos candidatas concretas y son trabajo bueno de esta noche**: los dos
`PUT bolfinales/cambiar-contador-*` de [CERT-1](cert-1.md), que ahora **abortan con
422 antes de escribir** cuando el cuerpo no es un consecutivo — y el barrido no
manda uno. **No aparecen en la corrida de hoy.** Lo que **no** puedo comprobar es
que aparecieran antes: `bar-1` no lista sus escrituras una a una. **AUD-4 también
tocó escritores**, así que la explicación no es única y queda como candidata.

**Lo que sí dice algo: el 52 coincide exacto** —derivado de `bar-1` como 145 − 93 y
medido hoy— **mientras el denominador se movía debajo.**

---

## 9. Lo que NO entra, y lo que queda como pregunta del colegio

- **No se recortó ninguna respuesta ni se cerró ninguna ruta.** Quitar campos toca
  cuatro clientes y lo decide Joseth — y con el censo de consumidores hecho
  **mirando los sitios de llamada, no la lista de clientes**, que es el precio que
  ya enseñó el recorte de `GET contratos`.
- **`GET alumnos/sin-matriculas`** (sale en la proyección `x5`) va **como pregunta**:
  si un docente debe poder listar los alumnos sin matrícula del colegio es del
  colegio, no nuestro.
- **`username` en la constante `PERSONALES`**: candidato a ampliación deliberada,
  **con su coste dicho** — el día que se añada hay que **volver a correr los cuatro
  roles y marcar los informes viejos como no comparables**. Un coste sin dueño no
  se paga.
- **El `telefono` institucional**: se puede quitar del ruido excluyendo `years`,
  pero eso es tocar la definición del instrumento y mueve las cifras anteriores.
