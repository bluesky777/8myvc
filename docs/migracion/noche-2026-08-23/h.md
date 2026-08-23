# Lote H — Los 230 identificadores del cuerpo

> Sesión `8myvc-2f`, árbol `.worktrees/h`, rama `fix/lote-h-identificadores`,
> base `simonbolivar_testing_h`. Noche del 22 al 23 de agosto de 2026.
> Secciones asignadas del [05](../05-codigo-muerto-y-roto.md): **§108–110**.
> Esta sesión cerró antes los lotes [B](b.md), [F](f.md) y [K](k.md).

El lote es «lee y reporta», y lo primero que hubo que reportar fue que **la lista
estaba mal en las dos direcciones**.

| | rutas sin comprobación | identificadores marcados | familias |
|---|---|---|---|
| original | 143 | 148 | **29** |
| tras quitar `ColumnaSegura::exigir` y los comentarios | 148 | 153 | 31 |
| **tras cruzar con el guard `persona.propia`** | **139** | **143** | **28** |

> **Las dos correcciones van en sentidos contrarios, y por eso hacían falta las
> dos.** La primera **marcó cinco rutas más** —huecos reales que salían del lado
> limpio— y la segunda **desmarcó nueve** —comprobaciones que existen y el
> detector no podía ver—. Con solo la primera, nueve rutas bien guardadas mandando
> a alguien a leerlas; con solo la segunda, cinco huecos escondidos.

El **29** de la tabla original es el que llevan escrito los documentos de la
migración. Ahora son **28**, y la familia que sale **no era un fallo arreglado:
era un fallo que no existía**.

---

## §108 — Las cinco formas de la misma ceguera

`identificadores-del-cuerpo.py` contesta «¿quién comprueba este identificador?».
La respuesta era un `sí`/`NO` que **afirmaba**. Ahora dice **qué señal la
disparó**, y con eso deja de poder mentir en silencio: un `sí` hay que creérselo,
un `Autoriza::exigir` se comprueba de un vistazo.

Las cinco formas, en el orden en que se encontraron:

### 1. Ciego ante un nombre nuevo — ya medida (§53)

`exigeQueLaPublicacionSeaSuya` frente a `exigirQueLaResueltaSeaSuya`. **El mismo
verbo conjugado de dos maneras en dos controladores.** De ahí sale la raíz `exig`
en la señal.

### 2. Ve un nombre que no es — **cinco rutas**

Esa raíz se traga **`ColumnaSegura::exigir`**, que valida **un nombre de columna,
no de quién es la fila**: es la defensa contra la inyección de las pantallas que
guardan un campo suelto mandando `{propiedad, valor}`.

```
PUT api/asignaturas/toggle-dia
PUT api/nota_comportamiento/guardar-libro
PUT api/ordinales/guardar-valor
PUT api/ordinales/guardar-valor-config
PUT api/years/toggle-cambiar-valor
```

**Son cinco, y no la familia `guardar-valor` entera**, que es lo que parecía antes
de contarlas: `alumnos/guardar-valor`, `acudientes/guardar-valor`,
`profesores/guardar-valor`, las de enfermería y `uniformes/actualizar` tienen
**además** una comprobación de verdad, así que su `sí` es correcto. Contarlo es lo
que separa «el detector miente» de «el detector miente en cinco sitios, y aquí
están».

> **Ensanchar una señal para no perder nada la hace tragar de más.** La forma 1 y
> la forma 2 son la misma decisión mirada desde los dos lados, y las dos se pagan
> en el mismo sitio: **una ruta que sale del lado limpio no la vuelve a mirar
> nadie.**

### 3. Lee la prosa — **cazada por la columna nueva en su primera ejecución**

`definitivas_periodos/update-recuperacion` salía comprobada por un token que era
**`exigen`** — la palabra, dentro del comentario «se exigen abiertos **todos** los
periodos». Un método con un docblock que hable de exigir salía del lado limpio.

Es la misma ceguera ya medida en `escrituras-en-las-notas.py`, que también leía
prosa de los docblocks. **Que dos herramientas distintas caigan en lo mismo lo
convierte en regla**: un detector que busca una palabra tiene que mirar solo el
código.

> **Y aquí hay un matiz que no se puede escribir junto con el anterior**: quitar
> los comentarios **no movió ni una ruta de lado** —148 antes y 148 después—. Lo
> que cambió es que cuatro filas pasan a nombrar la señal correcta;
> `update-recuperacion` sí comprueba, con `User::pueden_modificar_definitivas`.
> **El primer arreglo cambió veredictos; el tercero, evidencia.**

### 4. La comprobación no está dentro del método — **nueve rutas**

Para nueve de estas rutas la hace **el guard**: `ExigirPersonaPropia` recoge del
cuerpo y de la URL los identificadores que nombran a una persona y comprueba que
sean del que pregunta. El detector solo miraba el cuerpo del método, así que las
marcaba «sin comprobar» teniéndola puesta desde la revisión de IDOR.

La lista de nombres **se lee del propio middleware**, no se copia: lleva **tres
nombres para una sola cosa** —`imagen_id`, `img_id`, `foto_id`— porque cada
endpoint que inventó el suyo dejó al guard ciego (§15, §53). Una copia a mano se
desincronizaría en el siguiente nombre nuevo, **que es el fallo que esta
herramienta persigue**.

### 5. La autenticación tampoco está en el middleware — **tres rutas, no arreglada**

`tardanzas/subir/*` sale con el guard en `—`, que se lee como «sin guard», y no lo
está: **se autentica dentro del método** —usuario y contraseña en el cuerpo,
`Credenciales::verificar`, `abort(400/401)` si fallan, y además exige `Profesor` o
superusuario—.

No se toca: la columna `guard` describe el middleware, y ahí no hay ninguno.
Arreglarlo sería meter un caso especial más en la herramienta. Queda escrito para
que **quien lea `—` no concluya lo que no es**.

---

## §109 — De los 143, cuál pesa: `acudientes/seleccionar-parentesco`

De las 139 rutas que quedan marcadas, **23 reciben del cuerpo un identificador
que nombra a una persona** —`alumno_id`, `user_id`, `persona_id`, `profesor_id`,
`matricula_id`, `parentesco_id`— y no lo comprueba nadie dentro del método.

**Las 23 llevan `auth.personal`**, así que ninguna familia llega. La pregunta que
queda no es «¿entra un alumno?» sino **«¿puede alguien del personal actuar sobre
quien no es suyo?»** — y eso es la decisión ya tomada de **las 44 rutas de
escritura con solo `auth.personal`**, abiertas a propósito para no dejar fuera a
un coordinador sin rol.

**Salvo una.**

`acudientes/seleccionar-parentesco` toma `acudiente_id` y `alumno_id` del cuerpo y
escribe la fila de `parentescos` que los une. **Esa fila no es un dato más: es la
que decide quién puede ver a quién.** La regla de negocio del sistema —«un
acudiente ve lo suyo y lo completo de sus acudidos»— se resuelve mirando
`parentescos`, así que escribir ahí **reparte acceso a los datos de un menor**.

Es **la única de las 143** que hace eso, y eso no se ve en una tabla ordenada por
número de identificadores: `seleccionar-parentesco` está a media lista, con dos.

Medido **desde donde se ve** y no desde la fila:

| Momento | Lo que recibe el acudiente al pedir esa ficha |
|---|---|
| antes | **403** |
| después de una llamada de cualquiera del personal | **200** |

Su hermana `quitar-parentesco-alumno` lo cierra otra vez con **un solo id del
cuerpo**. Esa sí deja firma (`deleted_by`), que es lo que le faltaba a las
bitácoras (§88).

**No se cierra**: repartir acudientes es trabajo del colegio, y quién del personal
puede hacerlo es la misma decisión de las 44. Lo que faltaba era **saber que esta
ruta no es como las otras 22**.

---

## §110 — La gemela que ya estaba medida: `historiales/de-usuario`

Mismo `user_id` del cuerpo sin comprobar, mismo `auth.personal`, y **la misma
consulta detrás** que `bitacoras/{user_id?}`: `HistorialCalc`, que es también lo
que lee `ChangesAsked/to-me`.

Lo que cerró la [§88](b.md) fue **el borrado** de una bitácora. **Quién puede leer
el rastro de quién sigue abierto en las dos**, y ahora está medido en las dos —
que es la diferencia entre «una ruta sin juzgar» y «una ruta juzgada y esperando
decisión».

Es el ejemplo más limpio de la regla del lote: **medir una ruta no es haberla
juzgado**, y **cerrar una serie no es cerrar la operación**.

---

## Los veredictos que deja este lote, uno por uno

Los que otras sesiones pidieron y los que salieron de aquí:

| Ruta | Veredicto |
|---|---|
| `ordinales/guardar-valor`, `guardar-valor-config` | `prop = sí` era **falso**: `ColumnaSegura::exigir`. Ya juzgadas a mano en el [lote B](b.md) |
| `ordinales/destroy` | **No es falso positivo**, pero lo que no comprueba **no es de quién es el ordinal** —son todos del mismo colegio— **sino de qué año** |
| `ciudades/departamentos-by-id` | **Falso positivo**: un país no es de nadie |
| `definitivas_periodos/update-recuperacion` | Sí comprueba: `User::pueden_modificar_definitivas`. La señal era prosa |
| Las 9 de `persona.propia` | **Comprobadas por el guard**, no por el método |
| `tardanzas/subir/*` | **Autenticadas dentro del método**, no sin guard |
| `acudientes/seleccionar-parentesco` | **La que más pesa de las 143** — reparte acceso a los datos de un menor |
| `historiales/de-usuario` | Gemela de `bitacoras/{user_id?}`; la §88 cerró el borrado, no la lectura |

---

## PARA JOSETH

1. **¿Quién del personal puede repartir acudientes?** Hoy cualquiera de los 51
   escribe la fila que le da a un adulto acceso a los datos de un menor. Es la
   misma pregunta de las 44, pero **esta es la única de la lista cuya consecuencia
   es dar acceso a los datos de otra persona**. (§109)
2. **¿Quién puede leer el rastro de quién?** `bitacoras/{user_id?}` e
   `historiales/de-usuario` son la misma pregunta con dos rutas. (§110)

## PARA OTRO LOTE

- **Las 22 restantes con un identificador de persona sin comprobar** caen en la
  decisión de las 44 y **no necesitan lote**: están medidas y clasificadas aquí.
- **`tardanzas/subir/*`** (lote L, con las sobras huérfanas) — tres rutas que se
  autentican dentro del método. No es un fallo; es que ninguna herramienta lo ve.

## Lo que se nota en un colegio

**Nada.** Este lote no toca `app/`: lo único que cambia es
`tools/identificadores-del-cuerpo.py` y cuatro tests nuevos.

Lo que sí cambia es el número con el que se trabaja mañana: **28 familias, no 29**,
y **139 rutas, no 143** — con las cinco que faltaban dentro y las nueve que
sobraban fuera.

## Migraciones

**Ninguna.**
