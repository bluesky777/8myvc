# INT-1 — los cuarenta y nueve interruptores, y qué son

> **Sesión `8myvc-39`, noche del 24 ago 2026.** Viene de [med-2.md](med-2.md), que
> midió cuántos son. Esto dice **qué son**, que es lo que ninguna herramienta puede
> hacer. Cero suites, sólo lecturas y consultas de sólo lectura.

---

## 0. La población, primero

| | |
|---|---|
| Columnas `tinyint(1)` distintas en el esquema | **157** |
| No las mira nadie — **medidas** | **49** |
| No las mira nadie — **leída a mano** | **1** (`ws_actividades_resueltas.timeout`) |
| Tablas implicadas | **16** |
| Pares columna/tabla | **53** (hay columnas en dos tablas) |

**Cuarenta y nueve y no cincuenta, y la herramienta hace bien en no sumarlos**: lo
dice ella misma —*«un número medido y uno leído no se suman»*—. `timeout` cayó
fuera del barrido porque es una palabra corriente que casa con `$timeout` de
Angular; se leyó a mano y se cuenta aparte. **Aquí se respeta esa separación**: lo
que sigue clasifica las 49.

«No las mira nadie» quiere decir las dos cosas a la vez: **ni el backend ni
ninguno de los tres clientes** (`myvc_front`, `myvc_front_2`, `myvc_flutter`).

---

## 1. La pregunta que se pidió primero: ¿hay alguna encendida a mano?

**No. Ninguna.** Y la respuesta necesitó dos pasos, porque el primero engañaba.

Consultadas las 53 en la base de tests:

| | |
|---|---|
| Pares con **alguna fila en 1** | **1** |
| Pares con filas y **todas en 0** | **5** |
| Pares sobre **tabla vacía** en esta base | **47** |

El único con filas en 1 es **`users.can_ask`: 2.351 de 2.351.** Eso parece una
columna encendida para todo el colegio… y no lo es:

```sql
`can_ask` tinyint(1) NOT NULL DEFAULT '1',
```

**Es el valor de serie.** Nadie la encendió: nació encendida y nadie la ha tocado
nunca — ni podría, porque **ningún cliente la nombra**, así que no hay pantalla que
la apague. Sin mirar el `DEFAULT`, este número se habría reportado como *«alguien
encendió un permiso que no hace nada»*, que es otra cosa y habría mandado a buscar
a otro sitio.

Los cinco con filas y todas en 0: `matriculas.profes_editar_notas` (124 filas),
`unidades.por_defecto` (309), `subunidades.por_defecto` (530), y las dos de
`config_certificados` (1 fila cada una).

> **Y el aviso que hay que dar con esto, porque limita todo lo demás: 44 de las 49
> caen sobre tablas que están VACÍAS en esta base.** El seed es una rebanada de dos
> años de **un** colegio, así que «vacía aquí» no significa «vacía en producción».
> **De esas 44 no se puede decir si algún colegio las encendió**, y sólo se
> contesta con el mismo `for` de los dieciséis de las otras fases 0. Queda
> propuesto, no medido.

---

## 2. El reparto

### 2.1 Cajón «columna muerta» — **43 de 49**

Las 43 que **no se nombran en ningún sitio**: ni en `app/`, ni en `routes/`, ni en
`config/`, ni en `database/`, ni en los tres clientes. El código **no puede** ni
leerlas ni escribirlas.

> **Comprobado al revés antes de creérselo, y esta vez la comprobación confirmó a
> la herramienta en vez de cazarla.** Un `grep` de `barrio_accepted` sobre `app/`
> **sí** devuelve un fichero —`app/Models/ChangeAskedDetails.php`—, o sea que
> parecía que la herramienta contaba de menos. Está dentro del bloque
> `@property` **generado** por `tools/columnas-en-los-modelos.php`, o sea un
> comentario. La herramienta mira sólo código; mi `grep` no. Otra vez el instrumento
> correcto sobre el objeto equivocado, y esta vez el equivocado era el mío.

| Familia | Cuántas | Dónde |
|---|---|---|
| `*_accepted` | **19** | `change_asked_data` (15), `change_asked_assignment` (4) |
| `per1..4_manual` · `per1..4_recuperada` · `per1..4_recuperado` · `year_recuperada` | **13** | `df_asignaturas`, `df_alumnos` |
| `cumplida`, `deriva_de_tipos1`, `deriva_de_tipos2`, `firma_acudiente`, `firma_alumno` | **5** | `dis_procesos`, `dis_acciones_restaurativas` |
| `can_change_definicion`, `can_change_orden`, `can_change_porcentaje`, `show_definicion` | **4** | `default_unidades`, `default_subunidades` |
| `can_ask` | 1 | `users` |
| `profes_editar_notas` | 1 | `matriculas` |

#### Las 19 `*_accepted`: muertas **por las dos mitades**, y eso hubo que comprobarlo

Es el bloque grande y el que más fácil se malinterpreta, porque **el mecanismo de
aceptación por campo SÍ está vivo**: `ChangeAskedController` usa
`nombres_accepted`, `apellidos_accepted`, `fecha_nac_accepted`, `foto_id_accepted`
y una docena más. O sea que la primera lectura razonable es *«hay campos que se
pueden pedir y no se pueden aceptar»* — una promesa incumplida.

**No lo es.** Cada campo de ese flujo tiene **dos** columnas, `X_new` y
`X_accepted`, y en las 19 **ninguna de las dos se toca**:

| campo | `X_accepted` | `X_new` | en los fronts |
|---|---|---|---|
| barrio, celular, ciudad_doc, ciudad_nac, ciudad_resid, direccion, documento, email, eps, estrato, facebook, religion, telefono, tipo_doc, tipo_sangre | 0 | **0** | 0 |
| defini_comport, frase_asignat, nota, nota_comport | 0 | **0** | 0 |
| **contraste — los hermanos vivos**: nombres, apellidos, fecha_nac, foto_id | 1 | **1** | — |

O sea: **esos campos nunca entraron en el flujo.** No hay peticiones que se queden
sin aceptar, porque no hay forma de pedirlas. Es columna muerta, no promesa
incumplida — y la diferencia sólo se ve mirando la otra mitad del par.

> **Y `nota_new` estuvo a punto de colarse como la excepción.** El barrido dio «1
> fichero» para él, o sea el único con asimetría. Leído: es
> `$nota_new = new Nota;` en `PeriodosController`, **una variable local de PHP**
> copiando notas entre periodos, sin ninguna relación con la columna. Sexta vez esta
> noche que un `grep` mío casa con **el nombre** y no con **la cosa**.

#### Los 13 `df_*`: no son 13 columnas muertas, son **seis tablas muertas**

`df_alumnos`, `df_asignaturas`, `df_grupos`, `df_notas_finales`, `df_subunidades`,
`df_unidades`: **ninguna de las seis la nombra un solo fichero de `app/` ni de
`routes/`**, y las seis están vacías aquí. Están en el esquema congelado, o sea que
existen en los dieciséis colegios.

Eso cambia el tamaño del hallazgo: clasificar sus columnas una a una es mirar las
hojas. **La pregunta es si esas seis tablas se borran**, y por la regla de la casa
—*sin ruta y roto se borra*— parecen candidatas claras. Pero **no se propone aquí**:
seis tablas en dieciséis producciones no es limpieza, es una migración destructiva,
y antes hay que saber si tienen filas allí (§1).

#### `matriculas.profes_editar_notas`: muerta **y servida a un cliente**

Es el ejemplo concreto del aviso que la herramienta da en abstracto —*«si llegan al
cliente, es por un `SELECT *`»*—:

```
tests/Contrato/Snapshots/actas-evaluacion-detalle.json:  "profes_editar_notas": "null"
```

**Viaja en la respuesta del detalle del acta de evaluación** y no la lee nadie. Y
tiene un hermano vivo que se parece mucho: **`years.profes_can_edit_alumnos`**, que
se lee en **doce ficheros de `app/`** y tiene su propio test
(`BanderaProfesEditaAlumnosTest`). O sea que existe la bandera **por año**, que
funciona, y una **por matrícula**, que no. Quien vea el nombre en la respuesta puede
creer razonablemente que hay un permiso por alumno. **No lo hay.**

### 2.2 Cajón «interruptor que espera una decisión» — **6 de 49**

Éstas **sí se escriben y se sirven**, y ningún `if` las mira. Son las que un
colegio puede marcar en una pantalla y no pasa nada:

| Columna | Tabla | Por qué importa |
|---|---|---|
| `encabezado_solo_primera_pagina` | `config_certificados` | **sale impreso**: el colegio pide el encabezado sólo en la primera página y el certificado lo ignora |
| `piepagina_solo_ultima_pagina` | `config_certificados` | igual, con el pie |
| `por_defecto` | `unidades`, `subunidades` | 309 y 530 filas, todas en 0 |
| `aleatorias` | `ws_preguntas` | «las preguntas salen en orden aleatorio» — el examen no lo mira |
| `is_cuadricula` | `ws_contenidos_preg` | forma de la pregunta |
| `is_puntaje_manual` | `ws_actividades_resueltas` | «el puntaje lo pone el docente a mano» |

**Las dos de `config_certificados` son las de más consecuencia visible** y las que
propondría llevar primero: lo que se ignora es una opción de maquetación **de un
documento que el colegio entrega firmado**, y el que la marcó no tiene forma de
saber que no se aplicó — el certificado sale, sólo sale distinto de lo que pidió.

**Las tres `ws_*` van juntas y con una advertencia**: el módulo de actividades
está **vacío en la base de desarrollo** (0 actividades con preguntas, 0 resueltas,
ya anotado en `phpstan.neon`), así que no se puede medir cuánto pesan. Encender
`aleatorias` sin saber si alguien usa el módulo es cambiar el comportamiento de un
examen a ciegas.

### 2.3 Cajón «no se sabe» — y qué es lo que no se sabe

No es un cajón de columnas: es **una pregunta abierta sobre 44 de las 49**.

- **Si algún colegio las tiene encendidas.** 44 caen sobre tablas vacías **aquí**, y
  el seed es un colegio de dieciséis. Se contesta con un `for` de sólo lectura, como
  las fases 0 de las otras.
- **Y dos de las 43 «muertas» son las que menos me atrevo a dar por muertas**:
  **`dis_procesos.firma_alumno` y `firma_acudiente`**. Disciplina **es un módulo
  vivo** (15 escrituras, `DisciplinaController` nombra `dis_procesos` nueve veces),
  y lo que esas dos columnas dicen es **si el alumno y su acudiente firmaron el
  proceso**. Una firma es exactamente el dato que hace falta meses después, cuando
  alguien reclama. Que no la lea nadie significa que **hoy el sistema no puede
  contestar si un proceso disciplinario se firmó**. No sé si eso es una función que
  se abandonó o una que nunca se terminó, y **no lo voy a adivinar**: va a la lista
  con esa pregunta escrita.

---

## 3. Lo que este lote propone, y a quién

**Nada se toca.** El entregable es el reparto:

| A decidir | Qué |
|---|---|
| **Joseth** | las dos de `config_certificados` — se marca y no se aplica, en un documento impreso |
| **Joseth** | `dis_procesos.firma_alumno` / `firma_acudiente` — ¿función abandonada o sin terminar? Hoy no se puede saber si un proceso se firmó |
| **Joseth**, con medición antes | las seis tablas `df_*`: cero referencias en el código y vacías aquí. Borrarlas es una migración destructiva en dieciséis producciones |
| **Servidor** | el `for` de sólo lectura sobre los dieciséis: ¿alguna de las 44 está encendida en algún colegio? |
| **Se puede archivar** | las 19 `*_accepted`, muertas por las dos mitades y sin cliente; `users.can_ask`; `matriculas.profes_editar_notas` (avisando de que **viaja** en `actas-evaluacion-detalle`) |
| **Esperar al módulo** | las tres `ws_*`: no se pueden medir con el módulo vacío |

## 4. Lo que este lote NO hace

- **No borra ninguna columna ni ninguna tabla.** Ni las 19, ni las `df_*`. La regla
  de la casa distingue *sin ruta y roto se borra* de *con ruta y roto se documenta*,
  y aquí lo que hay no son rutas: son **columnas en dieciséis producciones**, que es
  un caso que la regla no cubre y que necesita su propia decisión.
- **No corre el `for` de los dieciséis.** Necesita el servidor.
- **No toca `interruptores-que-nadie-lee.py`.** Su medición es correcta y su
  separación de «49 medidas + 1 leída» es más cuidadosa que el encargo que recibí.
