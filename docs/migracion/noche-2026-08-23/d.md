# Lote D — La configuración del año

> Sesión `8myvc-ec`, noche del 22 al 23 de agosto de 2026. Rama
> `fix/lote-d-configuracion-year`, árbol `.worktrees/d`, base
> `simonbolivar_testing_d`.
>
> Controladores: `Years`, `Periodos`, `Asignaturas`, `Unidades`, `Prematriculas`.
> Secciones asignadas: **§93–96**. Siete commits.

Las diez rutas del lote están las diez comprobadas, y de ellas salieron **siete
arreglos y seis cosas medidas que no se tocan**. Lo que sigue está ordenado por lo
que se nota en un colegio, no por controlador.

---

## 1. Lo que cambia para un colegio

Ninguna pantalla que funcione hoy cambia. **Los cuatro clientes mandan el cuerpo
entero en todas las rutas que se tocan**, comprobado repo a repo y no deducido.
Lo que deja de poder pasar:

| Deja de pasar | Ruta | §|
|---|---|---|
| Que una petición a medias deje el colegio **sin nombre, sin año y sin los nombres de unidad que se imprimen en todos los boletines**, contestando 200 | `PUT years/guardar-cambios` | §93 |
| Que aparezcan **dos años actuales** y todo el colegio entre en 2018 al siguiente inicio de sesión | `PUT years/toggle-cambiar-valor` | §94 |
| Que un usuario quede **aparcado en un periodo de la papelera**, con las pantallas vacías en 200 y sin forma de volver desde la interfaz | `PUT periodos/useractive/{id}` | §95 |
| Que **corregirle la redacción a un logro cambie la nota del boletín**, borrando el peso de la unidad | `PUT unidades/update/{id}` | §96 |
| Que el conmutador de horario **escriba en asignaturas de la papelera** y conteste «Cambiado» sin id | `PUT asignaturas/toggle-dia` | §96 |
| Que editar una asignatura **borre sus créditos y su orden** | `PUT asignaturas/update/{id}` | §96 |
| Cinco **500 con un aviso de PHP dentro**, todos alcanzables sin inventarse un id: basta una fila de la papelera | varias | §96 |

**Lo único que enciende algo que hoy no funciona**: editar o crear una asignatura
**sin profesor** deja de dar 500. La columna es nulable desde siempre y la pantalla
llega ahí sola (`AsignaturasCtrl.editar` no resuelve `row.profesor` cuando no hay
ninguno). **En el seed hay 0 asignaturas sin profesor**, así que la población en
producción está sin medir: si hay muchas, esto desatasca una pantalla; si hay cero,
era una mina. Está en la lista de Joseth.

**Sin migraciones.** Ninguna.

---

## 2. §93 · `years/guardar-cambios` no conservaba nada

`PUT /api/years/guardar-cambios {"id": 1}` —una línea, alcanzable por cualquiera de
los 51 profesores con `auth.personal`— contestaba **200** y dejaba la fila así:

```
year                  2018  ->  0
nombre_colegio        'COLEGIO ADVENTISTA SIMÓN BOLIVAR'  ->  ''
unidad_displayname    'Desempeño'  ->  ''
alumnos_can_see_notas 1  ->  0
abrev_colegio, telefono, resolucion, codigo_dane,
  rector_id, secretario_id, website, msg_when_students_blocked  ->  NULL
```

### 2.1 Lo que lo dejaba pasar no es el código

`config/database.php` pone `'strict' => false` en las dos conexiones, o sea que la
sesión corre con `sql_mode = NO_ENGINE_SUBSTITUTION`. **Es el mismo modo en
producción**, porque ese valor lo fija Laravel al conectar y no el servidor. Y en
ese modo:

```
UPDATE t SET nombre=NULL WHERE id=1   ->  Warning 1048, la fila queda con ''
INSERT INTO t (nombre) VALUES (NULL)  ->  ERROR 1048, rechazado
```

**El `NOT NULL` frena un INSERT y no frena un UPDATE.** Es el reverso exacto de la
[§78](../05-codigo-muerto-y-roto.md): allí el esquema salvaba a ocho de nueve altas,
y aquí el mismo esquema no salva ninguna edición. Por eso la ausencia de
validaciones en este proyecto casi nunca se nota al crear y sí al editar.

### 2.2 Y la respuesta contradice a la fila

El método devuelve el modelo **en memoria**, así que la respuesta dice
`nombre_colegio: null` donde la tabla tiene `''`. Se deja fijado y no se toca:
releer la fila antes de responder es un cambio de contrato para el único cliente
que la llama, y encender el modo estricto es una decisión sobre las 990 consultas
crudas del proyecto. Va a la lista de Joseth.

### 2.3 Por qué conservar y no 422

Se miró, no se decidió a ojo. Los tres repos de cliente están al lado y **sólo uno
llama a esta ruta**: `YearsCtrl.guardar_cambios` en `myvc_front`, con el objeto
`year` entero que vino de `years/colegio`. Hay **dieciséis copias de ese front con
distinta antigüedad**: un 422 rompería a la que mande veinte de los veintiún
campos, y conservar los ausentes no puede romper a nadie, porque quien los manda
todos escribe exactamente lo mismo que antes.

### 2.4 La herramienta, y por qué aquí no es la de la §68

Aquí se usa el defecto de `Request::input()` y **no** `CamposQueVinieron`. Esa clase
existe porque en `Profesores` y `Alumnos` hay `sanarInput*` haciendo
`Request::merge()` **antes** de que el controlador lea, y a esa altura `has()` ya no
distingue lo que mandó el cliente de lo que se rellenó solo.

`putGuardarCambios` no tiene ningún `merge` —comprobado también en los middlewares
y los providers—, así que el defecto mide exactamente lo mismo con menos código.
**En `AsignaturasController` sí hace falta la clase**, porque `fixInputs()` hace tres
`merge()`. Mismo fallo, dos herramientas, y el discriminador es **si hay un `merge`
delante**.

---

## 3. §94 · El conmutador genérico encendía un segundo año actual

`years/toggle-cambiar-valor` recibe `{year_id, campo, valor}` y escribe la columna
que le digan. `ColumnaSegura` impide la inyección pero **no limita cuál**, y entre
las columnas de `years` está `actual`, que tiene invariante —uno solo— y ruta propia
que lo mantiene (`years/set-actual`, que apaga a los demás).

Medido: con 2025 encendido, `{campo: 'actual', valor: 1}` sobre 2018 deja **los dos
encendidos**. Y eso no se queda en la fila: `Services\Login::ponerEnElPeriodoActual`
hace `SELECT ... WHERE actual=1` y se queda con **el primero, sin `ORDER BY`**, o sea
el de id más bajo. El siguiente inicio de sesión muda a **todo el colegio** a 2018.

Es la [§28](../05-codigo-muerto-y-roto.md) alcanzada por otra puerta. Se cierra sólo
`actual`, y se deja fijado con su propio test que la ruta **sigue escribiendo
cualquier otra columna**: no es lo que promete su nombre, y el día que alguien apoye
un permiso en «esta ruta sólo toca el horario» se llevará una sorpresa.

---

## 4. §95 · `periodos/useractive` aparcaba al usuario donde no podía volver

Dos salidas malas, y **la barata no es la que parece**:

- id que **no existe** → 500 con el `SQLSTATE` de la clave ajena dentro;
- id de la **papelera** → **200**, porque la clave ajena no filtra `deleted_at`.

La segunda es la cara, y sólo se ve **mirando el resultado y no el estado**. Con el
**mismo token**, sin volver a entrar: el usuario queda en el periodo borrado y sus
pantallas se vacían —**0 grupos, 0 asignaturas, en 200**— sin ningún error. El
periodo borrado no sale en ningún selector, así que desde la interfaz no hay forma
de volver: lo deshace `Login::ponerEnElPeriodoActual` **al volver a entrar**, que es
justo lo que nadie adivina delante de una pantalla vacía.

> **La primera medición de esto salió mal por eso mismo.** Al releer con un token
> nuevo, el login ya había devuelto al usuario a su sitio y parecía que no pasaba
> nada. El instrumento —volver a pedir el token— borraba el efecto que medía.

Se cierra con un `findOrFail` que respeta el borrado suave, y **se fija con su test
lo que sigue valiendo**: mudarse a un periodo vivo de otro año, que es la función de
la ruta y lo que llaman la barra del front y `ContextoAcademico.cambiarPeriodo` de
Flutter. Con su precio escrito, que es contrato y no fallo: los listados del año
viejo salen vacíos en 200, y es lo que hace indistinguible el caso del borrado.

---

## 5. §96 · `unidades/update` borraba un peso de la nota final

El que más pesa del lote, y el que menos lo parecía: **dos columnas**, contra las
veintiuna de `years`.

`porcentaje` no es un dato descriptivo. La definitiva sale de
`(u.porcentaje/100) * ((s.porcentaje/100) * n.nota)` y
`NotaFinal::calcularAsignaturaPeriodo` se llama **en el mismo método**, diez líneas
después de guardar. Un cuerpo que sólo traía `definicion` dejaba el peso en null y
**cambiaba la nota que va al boletín**, en 200. Medido: 10 → NULL. Y el de la unidad
es el factor de fuera: se lleva todas las subunidades que cuelgan de ella a la vez.

### 5.1 El `??` que parecía equivalente pasó los 23 tests

El primer caso al revés que se escribió —«un `0` que se manda es un 0»— **no
distinguía** `Request::input('x', $def)` de `Request::input('x') ?? $def`:

| Cuerpo | `input('x', $def)` | `input('x') ?? $def` |
|---|---|---|
| sin la clave | `$def` | `$def` |
| `0` | `0` | `0` |
| **`null`** | **`null`** | **`$def`** |

`0 ?? 50` es `0`, porque `??` sólo mira `null`. Las dos versiones pasaban en verde.
**El que distingue es mandar `null`**, y salió de revertir a la solución equivocada
y ver que **no caía nada** — la regla 4 en su forma difícil, invisible sin revertir.

Y la decisión que va dentro del caso, no sólo el caso: **no mandar un campo y
mandarlo vacío no son la misma petición.** Lo primero es un cliente que sólo manda
lo que cambió; lo segundo es un cliente diciendo «quítalo». Tratarlos igual
convierte el arreglo de la §68 en **un campo que ya no se puede vaciar nunca**: un
fallo nuevo con mejor pinta que el viejo.

---

## 6. §96 · Cinco 500 que eran un id que no lleva a ninguna fila

Todos con la misma forma —`[0]` o `->prop` sobre una consulta que puede no traer
nada, que PHP avisa y Laravel sube a excepción— y **a todos se llega sin inventarse
un id: basta una fila de la papelera**, que es el detalle por el que se le escapan a
todo el mundo.

| Ruta | Qué lo provocaba |
|---|---|
| `PUT unidades/de-asignatura-periodo/{a}/{p}` | `DB::select(...)[0]`; y esta ruta **escribe** |
| `PUT unidades/de-profesor` | `Profesor::detallado` → `return $profesor[0];` |
| `GET asignaturas/listasignaturas/{persona}` | el mismo |
| `GET asignaturas/list-asignaturas-year/{p}/{periodo}` | `Year::de_un_periodo` → `Periodo::find(...)->year_id` |
| `POST asignaturas` / `PUT asignaturas/update` | `Request::input('profesor')['profesor_id']` sobre null (§69) |

**Los modelos no se tocan.** `App\Models\Profesor::detallado` lo comparten seis
llamantes de tres dominios: un `?? null` allí convertiría seis 500 en seis
comportamientos distintos sin haber medido cuál es el correcto en cada pantalla, que
no es arreglar la operación sino **mover el fallo a donde no lo vea un test**. Se
para en cada llamante, que es lo que eligió el lote E en el suyo.

`Year::de_un_periodo` tiene hoy **un solo llamante** y aun así se para igual en el
controlador: el 404 es una decisión de la ruta, y el modelo sólo sabe de años.

---

## 7. §96 · Las dos gemelas que contestaban distinto

`prematriculas/alumnos-grado-anterior` y `matriculas/alumnos-grado-anterior` son el
mismo método copiado: **83 líneas cada uno y una sola diferencia**, medida con un
diff y no leída.

```diff
- $sqlYearAnt = 'SELECT id from years where year=:year_ant and deleted_at is null;';   matriculas
+ $sqlYearAnt = 'SELECT id from years where year=:year_ant';                           prematriculas
```

Sin el filtro, pedir el año anterior por su número resolvía también a un año **de la
papelera**. Peor con dos filas del mismo número —una viva y una borrada, que es
justo lo que hay en la base—: `[0]` se queda con la de id más bajo. **La misma
petición contestaba distinto en los dos gemelos, y el que se equivocaba era el que
no llama nadie.** Viene del import inicial (`04645fd`): nadie la introdujo, nadie la
vio.

Se alinea con la que sí se usa, y eso lo decide un dato: **ningún cliente llama a
las de `prematriculas/`**, y `myvc_front` lo dice en su propio `PrematriculasApi.ts`.

### 7.1 El test hay que fabricarlo por los dos lados

La tercera consulta —«los del grado anterior que no se han matriculado»— **sale
vacía tal como está el seed**, porque los 56 alumnos del año anterior están todos
matriculados en el actual y el `NOT IN` no deja pasar a ninguno. Con esa consulta
vacía, poner o quitar el filtro **da exactamente lo mismo**: un test escrito sin
desmatricular a nadie pasa con el arreglo revertido y no mide nada. Hay que
desmatricular a uno **y luego** mandar el año a la papelera.

---

## 8. El barrido: 28 métodos, 224 columnas — y sus cuatro límites

De `years/guardar-cambios` salió la pregunta «¿está copiado?». Detector: método que
resuelve una fila que **ya existe** (`find/findOrFail/first/onlyTrashed`) y le asigna
**dos o más** columnas con `Request::input('x')` **sin defecto**, excluyendo los
`new X` sin `find` —nacer con null es correcto, es el discriminador de la §68—.

**28 métodos, 224 columnas.** La tabla entera está en el mensaje que recibió quien
coordina; se repartió a los seis lotes y de los que no eran de nadie salió el lote K.

**Lo que hace fiable a esa tabla no es el número: son los cuatro límites**, y los
cuatro los encontraron otros lotes al usarla.

1. **No ve los `DB::update` crudos.** Con 990 consultas crudas en el proyecto, esa
   ceguera es grande: **la propia §94 de este lote no sale en la lista**.
2. **`has()` da falsos positivos.** `NotaComportamientoController::putUpdate` sale y
   no es: sus tres asignaciones van cada una dentro de su `if (Request::has(...))`.
   El detector cuenta la asignación y no la línea de antes. (Medido por el lote C.)
3. **`new X` + `find()` da falsos positivos.** `ProfesoresController::postStore` sale
   con 19 y es un alta: tiene un `Grupo::find()` sesenta líneas más abajo que lo
   coló. En un alta no hay nada que pisar. (Lote E.)
4. **La población de partida no era `app/`, era «lo que ese patrón alcanza».**
   `EscalasDeValoracionController::putUpdate` no aparece ni como candidato: comprueba
   con un `SELECT` en un helper privado y escribe nueve columnas con un `DB::update`
   crudo. (Lote A.)
5. **«Tiene defecto» no es «está a salvo».** `GruposController:650` es
   `Request::input('caritas', false)`: la única con defecto de las diez, y ese
   defecto **la apaga**. La columna «a salvo» marca **sospechosas, no
   descartables**. (Lote E.)

Y una que salió de este mismo lote: **el tamaño de la fila no dice nada sobre el peso
de la fila.** `UnidadesController::putUpdate` estaba abajo del todo con dos columnas,
y era el más caro de los cinco porque una de las dos es un factor de la definitiva.
Priorizar por la cantidad de columnas habría llegado a él el último.

---

## 9. Lo que se midió y **no** se tocó

| Qué | Por qué no |
|---|---|
| El 200 con cuerpo `''` de las cuatro rutas de grado anterior | Ya fijado en `MatriculasTest`. Cambiar sólo dos crea una asimetría nueva justo debajo de la que se acaba de cerrar |
| El **500** con `grupo_actual` escalar, en las mismas cuatro | Igual: se arreglan las cuatro a la vez o ninguna |
| Los **500 con `SQLSTATE`** de `POST asignaturas` | §78.3: el front pinta el mensaje del cuerpo, y pasar de enseñar el `SQLSTATE` a «Datos incorrectos» **se decide, no se arregla de paso**. Lo que sí se afirma es que ninguna deja fila detrás |
| `detalle-asignatura` no distingue «no existe» de «sin unidades» | Contesta `{"unidades": [], "cantidad_notas": 0}` a un id inventado, a uno de la papelera y a ningún id. Sin consecuencia medible; queda fijado para que nadie apoye nada en ese vacío |
| `periodos/update` y sus 6 columnas del barrido | **No llega a escribir nunca** —asigna `$periodo->year`, que no es columna—, y ya estaba fijado. De los cuatro sitios míos, éste era uno donde ya se había mirado |
| Escribir asignaturas de **otros años** con `auth.personal` | Las 44 rutas de escritura de la configuración académica están abiertas **por decisión de Joseth**: cerrarlas dejaría fuera a un coordinador que hoy configura y no tiene el rol |
| Que las listas de grado anterior alcancen **cualquier grupo del colegio** con la ficha personal entera | La misma decisión. Queda escrito **cuánto alcanza**, que es lo que hay que tener delante el día que se decida otra cosa |
| Las **14 de 17** filas de `dis_ordinales` con `created_at` nulo ya escritas | Son un dato para quien lea la auditoría, no un `INSERT` que corregir. El arreglo es para las que vengan |

---

## 10. Para Joseth

1. **`config/database.php` pone `'strict' => false` en las dos conexiones.** Es lo
   que deja que un UPDATE escriba `''` y `0` en columnas `NOT NULL` sin fallar, y lo
   que dejaba pasar el §93 y deja pasar a los otros 27 métodos del barrido.
   Encenderlo estricto **no es una línea de config**: cambia lo que hacen las 990
   consultas crudas del proyecto y hay que medirlo antes.
2. **Cuántas asignaturas sin profesor hay en producción.** No se puede medir desde
   aquí; en el seed hay 0. Decide si el arreglo del §96.3 desatasca una pantalla o
   cerraba una mina.
3. **Crear un año cuyo anterior está en la papelera lo deja vacío** —sin disciplina,
   sin grupos, sin asignaturas, sin escalas— y contesta 200. ¿Copiar desde un año
   borrado, avisar, o dejarlo como está? Está fijado con su test para que se decida
   sobre un dato.

---

## 11. Comprobado al revés, uno por uno

Cada arreglo se revirtió por separado y se contó cuántos tests caen. Ninguno tapa
dos caminos con un test.

| Arreglo | Caen |
|---|---|
| §93 los veintiún defectos | 3 |
| §93 sólo la mitad de `compromiso_familiar_label` | 1 — y **no medía nada** hasta ponerle un valor antes: la columna viene nula del seed y null no distingue «se conservó» de «se pisó» |
| §93 contra la solución equivocada `?? ` | 1 |
| §94 el candado de `actual` | 1 |
| §95 el `findOrFail` del periodo | 2 |
| §96 el porcentaje de la unidad | 1 |
| §96 el `?? ` en el porcentaje | 1 — **y sólo después de arreglar el test**, que antes daba verde con las dos versiones |
| §96 los dos 404 de unidades | 3 |
| §96 el 404 de `toggle-dia` | 2 |
| §96 `CamposQueVinieron` en asignaturas | 2 |
| §96 la notación con puntos de `fixInputs` | 2 + 4 |
| §96 los dos 404 de periodo y profesor | 2 |
| §96 el `deleted_at` de la gemela | 1 |
| las fechas de los dos `INSERT` de disciplina | 1 |
