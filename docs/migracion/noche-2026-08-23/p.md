# Lote P — Las que escriben sin decirlo · §133–§136

> Sesión `8myvc-4f`, noche del 22 al 23 de agosto de 2026. Rama
> `fix/lote-p-las-seis-que-preguntan`, árbol `.worktrees/p`.
>
> La pregunta del lote era **las seis que quedan fuera de la decisión de las 44**.
> Al medirlas se convirtió en otra, más nítida y más fácil de barrer: **qué rutas
> escriben sin que su verbo lo diga.**

## Lo primero: el lote encoge

`profesores/destroy` estaba en la lista y **ya está cerrada en `main`** — la cerró
el §97 de esta misma sesión unas horas antes. Comprobado en el árbol que sale de
`main`, no de memoria. **Sale del lote como resultado**, y no se vuelve a medir.

Un lote que encoge con la población delante vale más que uno que se completa
volviendo a medir lo medido.

## El barrido: 122 GET, 10 candidatos, 6 de verdad

Un `GET` que muta es una mina **por su forma**: es el verbo que los navegadores
reintentan, precargan y cachean, y el único que nadie mira dos veces al leer una
tabla de rutas. Barridas las **122 rutas GET** de la API contra el cuerpo de su
método:

| | |
|---|---|
| Candidatos | **10** |
| Falsos positivos | **4** |
| Escriben de verdad | **6** |

**Los cuatro falsos positivos importan tanto como los seis.** Tres son
`Excel::create()`, que no es la base de datos; **y el cuarto era de mi propio
extractor**, que al recortar el cuerpo del método se pasaba hasta el siguiente y
me marcó `perfiles/trashed`, que solo hace un `get()`. Un detector escrito sobre
la marcha miente al menos una vez: lo que lo salva no es escribirlo mejor, es
**auditar sus resultados antes de mandar el número**.

Las seis:

| Ruta | Escribe | Guard | Estado |
|---|---|---|---|
| `GET definitivas_periodos/arreglar-duplicados` | `DB::delete` | ninguno | ya juzgada; defendida por dentro |
| `GET folios/iniciar` | `DB::update` masivo | `auth.personal` | **§134**, medida aquí |
| `GET importar` | `->save()` | `auth.personal` | anotada |
| `GET importar/modificar/{year}` | `DB::update` | `auth.personal` | anotada |
| `GET nota_comportamiento/detailed/{grupo_id}` | `DB::insert` **en dos tablas** | `auth.personal` | **§133** |
| `GET unidades/de-asignatura-periodo/{...}` | `DB::insert` | `auth.personal` | el 15 ya avisa: «no es una lectura» |

---

## §133 — El GET que crea notas, y la única de su controlador que no pregunta

`nota_comportamiento/detailed/{grupo_id}` no lee la rejilla de comportamiento: la
**fabrica**. Por cada alumno del grupo crea, si no existe:

- su **nota de comportamiento** del periodo, con la **nota máxima** de la escala;
- su fila de **`dis_libro_rojo`**, el libro rojo de disciplina del año — y ésa no
  la nombra el endpoint por ningún lado.

Lo que lo convierte en hallazgo no es que escriba. Es esto:

```
NotaComportamientoController::postStore      -> User::pueden_editar_notas()
                             ::putUpdate     -> User::pueden_editar_notas()
                             ::putCrear      -> User::pueden_editar_notas()
                             ::deleteDestroy -> User::pueden_editar_notas()
                             ::getDetailed   -> NADA, y escribe
```

**Cuatro de cinco preguntan por el interruptor del periodo y la que no es la que
escribe una fila por alumno.** Medido desde el resultado, con el interruptor
apagado y en la misma petición de test:

```
POST nota_comportamiento/store     ->  400   (frena, como debe)
GET  nota_comportamiento/detailed  ->  200   y escribe las filas
```

### Por qué no lo vio la herramienta, que es la parte reutilizable

`tools/escrituras-en-las-notas.py` **no la reporta**. No es un fallo nuevo: es
**la tercera ceguera que la herramienta lleva escrita en su propia cabecera**
—solo mira SQL crudo— y aquí la escritura es un `->save()` de Eloquent **dentro
de un modelo** (`NotaComportamiento::crearVerifNota`), a dos saltos del
controlador.

> La ceguera estaba documentada y la ruta seguía sin verse. **Documentar una
> ceguera no la cierra.**

### Lo que se puede afirmar sin decidir nada, y está medido

- **Crea lo que falta y no toca lo que hay.** Si además pisara las notas ya
  puestas, abrir la rejilla con el periodo cerrado **le borraría el trabajo al
  profesor**, y eso sí sería un incidente y no una mina. No lo hace, y hay test.
- **La nota con la que nace es la máxima de la escala**: el alumno empieza el
  periodo con el comportamiento entero y se le baja. Ese valor es una decisión
  del colegio escrita en el código y queda fijado.

### El arreglo evidente era peor que el fallo — y el bueno ya estaba decidido

Meterle `pueden_editar_notas()` al GET habría hecho que **leer** la rejilla diera
400 con el periodo cerrado: rompería la lectura para arreglar la escritura. Y con
el periodo cerrado es **justo la rejilla que un profesor va a querer consultar**.

Empezó como una decisión para Joseth. **No lo era: ya la había tomado**, y la
respuesta estaba a una ruta de distancia — en la sexta de mi propia lista.
`unidades/de-asignatura-periodo` es **la misma forma exacta** (un GET que lee y
de paso crea) y la §47.2 la resolvió así, con esta frase dentro del código:

> *«Esta ruta lee y de paso escribe, así que no puede llevar el `abort()` de sus
> hermanas: sería apagarle al profesor la vista de un periodo cerrado, que es
> justo la que va a querer consultar cuando esté cerrado. Decidido por Joseth:
> **enseña lo que hay y no crea nada**.»*

Por eso el arreglo usa `User::permiteEditarNotas()` —**booleana, sin `abort()`**—
y no su hermana que corta. Es la misma pieza que ya existía para esto.

**Y el contrato tampoco era una incógnita: el front ya distingue el caso.**
`NotasAlumnoCtrl` y `PromocionarNotasCtrl` hacen
`if (nota.id) { actualizar() } else { crear() }`. Sin `id` toman la rama de crear,
que con el periodo cerrado recibe su 400 de `putCrear` — **el mismo aviso que
recibían antes al intentar guardar, pero sin haber escrito nada por el camino**.

O sea que lo que parecía «esto necesita una decisión del colegio» eran **dos
comprobaciones**: buscar el precedente y leer el cliente. Las dos costaban diez
minutos y las dos dijeron que no hacía falta decidir nada.

### Lo que se deja escrito y no se toca

El segundo `INSERT`, el de **`dis_libro_rojo`**, sigue ocurriendo en el GET. No se
toca a propósito: el interruptor `profes_pueden_editar_notas` gobierna **las
notas**, y el libro rojo es disciplina —Joseth ya separó una vez esas dos cosas al
decidir que el interruptor cierra las notas y no la asistencia—. Meterlo en el
mismo `if` sería colar una decisión dentro de un arreglo. Queda medido y anotado.

## §134 — Un GET que renumera las matrículas del colegio

`folios/iniciar` lanza:

```sql
UPDATE matriculas m
  INNER JOIN grupos g ON ... AND (m.nro_folio is null OR m.nro_folio="") AND g.year_id=?
  SET m.nro_folio = CONCAT(y.year, "-", m.alumno_id)
```

Sobre **todas** las matrículas del año en curso a las que les falte el folio, con
`auth.personal` —cualquiera de los 51 profesores— y por un verbo que un navegador
puede repetir solo.

Lo que la salva de ser destructiva es la condición: **solo toca las vacías**. O
sea que es **idempotente por la condición, no por el diseño**, y eso es
exactamente lo que se pierde en la primera reescritura y nadie echa de menos
hasta que alguien renumera folios de matrículas que ya estaban puestos. Queda
fijado con test por los dos lados: rellena la vacía y **no toca la que ya tenía**.

Y medido: **no la llama ningún cliente**. Hoy es una mina, no un fallo vivo — la
misma categoría que el `year_id` de los grupos del §102.
