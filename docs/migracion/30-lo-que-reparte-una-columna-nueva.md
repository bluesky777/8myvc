# A cuántas respuestas se reparte sola una columna nueva de `profesores`

**4 sep 2026.** `2026_09_04_200000_tono_del_docente` añade `tono` a `profesores`, y su
propio docblock avisa de que **se reparte sola a seis respuestas** que nadie tocó. Ese censo
lo hizo `8myvc-e0` **leyendo los `return` uno a uno** de `ProfesoresController` y
`GruposController`, y es correcto en lo que mira.

Este documento contesta la pregunta siguiente, que es la que decide si el aviso al front está
completo: **¿a qué más se reparte, por caminos que ese método no podía ver?**

> **Respuesta corta: a trece, no a seis.** Cinco más por Eloquent en controladores que no se
> miraron, y **dos por SQL crudo**, que es un camino que leer `return` de Eloquent **no puede
> ver por construcción**. Y **sólo una de las trece tiene una instantánea que la vigile** —
> justo la que dio el aviso.

---

## 0. Población, y por qué van tres instrumentos y no uno

| | |
|---|---|
| Ficheros de controlador | **115** |
| Rutas en el router | **566** (`routes/api/*.php`) |
| Consultas crudas en `app/` | **1.170** |
| Literales de cadena inspeccionados por el detector | **10.188** |
| … que son SQL y nombran `profesores` | **112** |
| Ficheros que tocan el modelo `Profesor` | **25** — el censo anterior miró **2** |
| Ficheros de test | 266 · **instantáneas: 125** |

**Tres caminos, porque cada uno es ciego a lo que ve el otro:**

1. **Eloquent** — un modelo devuelto entero se serializa con todas sus columnas.
2. **SQL crudo con comodín** — `SELECT *` o `p.*` sobre `profesores`. *Ningún `return` de
   Eloquent enseña esto*, y aquí hay 1.170 consultas crudas.
3. **Query Builder** — `DB::table('profesores')->get()` sin `select()`. **Medido: cero
   apariciones.** Se dice porque «cero» sólo vale si alguien miró.

---

## 1. Las trece, con su ruta y con qué la vigila

**Instantánea** es la columna que decide: un test que llama a la ruta y mira el código de
estado **no ve un campo nuevo**. Sólo la instantánea lo pone rojo.

| # | Ruta | Cómo se cuela | Tests que la tocan | Instantánea |
|---|---|---|---|---|
| 1 | `GET grupos/{id}` | Eloquent: `$grupo->titular = Profesor::find(...)` | 2 | ✅ **`grupos-show.json`** |
| 2 | `POST profesores/store` | Eloquent, modelo entero | 3 | ❌ |
| 3 | `PUT profesores/update/{id}` | Eloquent, modelo entero | 5 | ❌ |
| 4 | `DELETE profesores/destroy/{id}` | Eloquent, modelo entero | 1 | ❌ |
| 5 | `DELETE profesores/forcedelete/{id}` | Eloquent, modelo entero | 2 | ❌ |
| 6 | `PUT profesores/restore/{id}` | Eloquent, modelo entero | 2 | ❌ |
| 7 | **`GET perfiles/show/{id}`** | Eloquent: `$grupo->titular = $profesor; return $grupo` | 2 | ❌ |
| 8 | **`PUT perfiles/update/{id}`** | Eloquent: `return $perfil` (rama `Profesor`) | 4 | ❌ |
| 9 | **`PUT perfiles/cambiarimgunprofe/{id}`** | Eloquent: `return $profesor` | 2 | ❌ |
| 10 | **`PUT images-users/cambiar-foto-un-usuario/{id}`** | Eloquent: `return $persona` (rama `Profesor` del `match`) | 3 | ❌ |
| 11 | **`PUT images-users/cambiar-firma-un-profe/{id}`** | Eloquent: `return $profesor` | 3 | ❌ |
| 12 | **`PUT profesores/listado`** | **SQL crudo**: `SELECT p.*` → `return ['profesores'=>…]` | 1 | ❌ |
| 13 | **`PUT participantes/profesores`** | **SQL crudo**: `SELECT * FROM profesores p INNER JOIN contratos c` → `return ['participantes'=>…]` | 1 | ❌ |

**Las siete en negrita son nuevas.** Las 1–6 son el censo de `e0`.

> **La 7 es la gemela exacta de la 1**, y su propio docblock ya lo dice: *«`Profesor::findOrFail`
> donde su gemela `GruposController::getShow` hace `Profesor::find()`»*. Dos copias del mismo
> método, y **una tiene instantánea y la otra no**. Por eso el aviso llegó por una sola.
>
> **Y las 12 y 13 son la mitad que faltaba entera**: no se pueden encontrar leyendo `return` de
> Eloquent porque **no hay ningún modelo por medio**. Son filas crudas de `DB::select`.

### Cobertura: **1 de 13**

Doce rutas tienen tests que las tocan —**hasta cinco en una**— y **ninguna instantánea**.
*Que una ruta esté probada no significa que su forma esté vigilada*: los tests que la llaman
comprueban permisos, códigos y efectos, y **un campo de más los deja verdes a todos**.

---

## 2. Lo que se descartó, y por qué se escribe

Un barrido que sólo publica sus hallazgos no deja comprobar su criterio.

| Candidato | Por qué NO es una fuga |
|---|---|
| `GET excel-listado-docentes` (`DocentesExport`) | Hace `SELECT p.*`, **pero la vista Blade nombra sus 17 columnas** y `tono` no está entre ellas. **Dos defensas independientes y basta una**: la consulta es ancha y la salida estrecha |
| `PerfilesController:129`, `:810`, `VtParticipante:79` | `SELECT * FROM ( SELECT p.id, p.nombres, … )` — el comodín cubre **una subconsulta que nombra sus columnas**. Falsos positivos de mi detector |
| `ChangeAskedController::cambiarOficialProfesor` | Hace `return $prof` con el modelo entero, **pero su único llamante descarta el valor** (`$this->cambiarOficialProfesor($pedido);`, sin asignar) |
| `PerfilesController::putCambiarfirmaunprofe` | Devuelve `$img`, no el profesor |
| `Profesor::all()` (`PerfilesController:443`), `ImageModel:158,163`, `ImagesUsuarios:327`, `VtParticipantes:218` | Se cargan y se mutan **dentro** del método; no llegan a ninguna respuesta |
| Las cinco estáticas del modelo — `detallado`, `asignaturas`, `fromyear`, `paraElegirEnAsignaturas`, `contratos` | **Nombran sus columnas una a una.** Comprobadas las cinco leyendo su `SELECT`. Por eso `GET profesores/show/{id}`, que usa `detallado()`, **no** reparte nada |

---

## 3. Lo que MI instrumento no ve, que es la mitad que importa

El barrido de los `return` tenía un punto ciego —el SQL crudo— y por eso faltaban dos. **El
mío tiene los suyos, y sin nombrarlos un «cero hallazgos» de aquí se leería dentro de seis
meses como «cero problemas».**

1. **Sólo lee literales de cadena completos.** Una consulta armada por trozos
   —`$q = 'SELECT '; $q .= $columnas;`— **es invisible**. No se ha medido cuántas hay.
2. **Sólo barrió `app/`.** Fuera quedan `database/`, `tools/`, `routes/` y `resources/`.
3. **No mira las vistas.** Un Blade que imprima `$profe->tono` filtra aunque la consulta sea
   estrecha. *Se revisó **una** a mano —la del Excel— porque su consulta era ancha; las demás
   no.*
4. **No resuelve vistas de base de datos.** Un `SELECT *` sobre una vista de MySQL que
   incluya `profesores` no lo detecta.
5. **La parte de Eloquent está leída a mano, no detectada.** Se leyeron los 65 usos en los 25
   ficheros; **un relación cargada con `with()`/`load()` y serializada lejos del `find` se me
   escaparía**. No se encontró ninguna, pero *no encontrar* no es *no haber*.
6. **No prueba alcanzabilidad.** Confirma que el campo sale por la respuesta, no que alguien
   la pida. La **7** dice en su propio docblock que **no la llama ningún cliente**, y aun así
   cuenta: una ruta viva es una ruta que alguien puede llamar mañana.

---

## 3.bis Tres de esos seis puntos ciegos ya están medidos, y los tres salen limpios

Nombrarlos no basta si se pueden cerrar barato. Medidos el 4 sep 2026:

| punto ciego | medición | resultado |
|---|---|---|
| **1 · consultas armadas por trozos** | **6** líneas en todo `app/` concatenan sobre una consulta (`$consulta .=`, `$sql .=`, `$query .=`) — y **ninguna vive en un fichero que nombre `profesores`** | **cerrado**: no puede esconder una fuga aquí |
| **2 · fuera de `app/`** | `database/` 4 ficheros nombran `profesores` y **0** con comodín; `routes/` 3 y **0**; `resources/` 1 y **0**; `tools/` 6 y **1**, que resulta ser **un comentario** | **cerrado**: nada fuera de `app/` reparte |
| **3 · vistas Blade** | hay **9** vistas en total; **1** nombra a un profesor (la del Excel, ya leída) y **ninguna** vuelca propiedades a ciegas —cero `@foreach ($x as $k => $v)`— | **cerrado**: la única que importaba era la que se miró |

**Quedan abiertos los tres que no se pueden cerrar leyendo**: vistas de base de datos, la
parte de Eloquent (leída a mano, no detectada) y la alcanzabilidad.

---

## 3.ter Y esta herramienta ya existía: `tools/filas-enteras-al-cliente.php`

**Debí buscarla antes de escribir un detector.** Contesta literalmente esta pregunta —*«qué
consultas leen una fila entera de una tabla del dominio y esa fila viaja al cliente, o sea
dónde una columna nueva se publica sola»*— y **acepta `--tablas=profesores`**. Nació el 2 sep
2026, cuando la migración de nivelaciones movió **siete instantáneas** sin que nadie tocara un
método, y **dos de aquellos ocho sitios no los encontró ninguna sesión leyendo: los encontró la
suite**.

*No está en la tabla de herramientas de `CLAUDE.md`* — por eso no apareció al buscar. Moverlo
allí es de Joseth.

**Lo que sale de todos modos, y por eso este documento no sobra:** la herramienta declara en su
cabecera que **no ve Eloquent encadenado en varias líneas** y que **no mira `resources/`**. Esos
son exactamente los dos huecos por los que salieron **cinco de mis siete hallazgos nuevos** —los
de `PerfilesController` e `ImagesUsuariosController` son todos Eloquent— y el que se cerró
mirando las vistas. **Los dos instrumentos son complementarios, no redundantes.**

> **La comprobación pendiente, y necesita los contenedores en pie:**
> `php tools/filas-enteras-al-cliente.php --tablas=profesores`. Si sale con **las dos de SQL
> crudo** (`profesores/listado` y `participantes/profesores`), mi barrido queda confirmado por
> un segundo instrumento independiente. **Si sale con alguna que yo no tengo, ésa es la
> noticia** — y sería del tipo que este documento avisa: mi detector también tiene huecos.

---

## 3.quater CORRIDA: la herramienta da **1** donde el barrido a mano da **13**, y el hueco es suyo

Corrida el 4 sep 2026 con los contenedores ya en pie:

```
$ php tools/filas-enteras-al-cliente.php --tablas=profesores
ficheros de app/ revisados ....... 235
sitios que leen la fila entera ... 1
  app/Http/Controllers/VtParticipantesController.php:117   SELECT *
```

**Una de trece.** Con `--todas`, la misma. Y las dos causas están medidas, no supuestas:

1. **Trabaja línea a línea.** Sus dos `preg_match` (líneas 94-95) exigen el comodín **y** el
   `FROM profesores` **en la misma línea**. `VtParticipantes:117` los tiene juntos y sale;
   `ProfesoresController:116` tiene `SELECT p.*` en la 116 y `FROM profesores p` en la **118**,
   así que **se le escapa una consulta que literalmente empieza por `SELECT p.*`**. *No es que
   no reconozca el comodín cualificado —su regex lo contempla—: es que la consulta está partida.*
2. **`--tablas=` es un interruptor a medias, y no lo dice.** Cambia `$tablas`, pero **`$modelos`
   está fijo** a las seis tablas del dominio de notas y **no incluye `profesores`**. Sin ese
   mapeo, `$modelo` es `null` y **la mitad de Eloquent no se ejecuta nunca**. Ahí van **once de
   las trece**.

> **Esto no desmiente su cabecera: la confirma.** El fichero avisa de que «no ve Eloquent
> encadenado» y de que «esto ORDENA candidatos, no da una lista de fallos». Lo que no avisa es
> que **`--tablas=` con una tabla fuera de las seis apaga la mitad del detector en silencio** —
> y ahí un `1` se lee igual que un `1` completo. *Es la regla de la casa aplicada a la
> herramienta que existe para aplicarla: **el primer sitio donde mirar cuando el número sale
> raro es el detector**.*

### ARREGLADA el 4 sep 2026, con permiso de Joseth

| | antes | después |
|---|---|---|
| `--tablas=profesores` | **1** | **12** |
| tablas por defecto (las seis) | **10** | **34** |
| autoprueba | 4 casos | **9 casos** |
| larastan | — | **`[OK] No errors`** |

**Cuatro cambios, y los dos últimos son fallos que introduje yo y cazó su propia autoprueba:**

1. **El mapa tabla→modelo se deriva**, leyendo `protected $table` de `app/Models/*.php`; para
   los quince modelos que no lo declaran se usa la convención de Laravel **pero sólo se acepta
   si esa tabla existe en `database/schema/mysql-schema.sql`**. Así una singularización mal
   hecha no sobrevive. *Y salió una entrada muerta de la lista fija vieja:*
   **`recuperacion_final` => `RecuperacionFinal`, un modelo que no existe** — nunca casó nada
   y nadie se enteró, porque un mapeo que no encuentra se lee igual que una tabla sin usos.
2. **Se avisa cuando una tabla pedida no tiene modelo**, que era el arreglo de la familia:
   ahora dice *«de esas tablas SÓLO se está mirando el SQL crudo»* en vez de callarse.
3. **Los literales se unen entre líneas**, así que una consulta partida deja de ser invisible —
   `ProfesoresController:116` era exactamente eso. *Mi primera versión unía desde cualquier
   línea, incluida una que ya estaba en mitad de otra cadena, y se colaba hasta la sentencia
   siguiente: dos falsos positivos, `VtParticipantes:98` y `YearsController:347`. Acotado a las
   líneas que abren un `SELECT`.*
4. **El alias se resuelve**: `p.*` sólo cuenta si `p` es el alias de esa tabla en esa misma
   consulta, un `SELECT *` sobre una subconsulta no cuenta, y `count(*)` tampoco. *Sin esto
   salían cinco falsos en `Publicaciones.php` —donde `p` es `publicaciones`— y un docblock.
   Lo avisó `8myvc-cd`, a quien le pasó lo mismo con su propio troceo el mismo día.*

**Y `findOrFail`/`firstOrFail`**, que devuelven la fila entera igual que `find` y no se miraban.
`onlyTrashed()->findOrFail()` **sigue fuera y queda declarado**: la cadena separa la llamada del
nombre del modelo.

> **La autoprueba pasó de 4 casos a 9**, y no es adorno: **cazó dos regresiones mías** durante
> este mismo arreglo. La primera, `Nota::find()` dejando de verse cuando cambié el mapa —porque
> `Nota` no declara `$table`—. *Un control que sólo cubre lo que ya funcionaba no habría dicho
> nada.*

**Lo que sigue sin ver, después del arreglo:** `onlyTrashed()->findOrFail()` y cualquier Eloquent
encadenado que parta el nombre del modelo de la llamada; consultas en comillas dobles o de más
de 20 líneas; y `resources/`, `routes/`, `database/` y `tools/`, que sigue sin mirar.

**Los dos arreglos eran pequeños y ya están hechos; lo que sigue sin hacerse** —tocar una herramienta compartida mientras
otras sesiones pueden estar usándola no lo decide ésta—:

- añadir `'profesores' => 'Profesor'` a `$modelos`, y mejor aún **derivar el modelo o avisar**
  cuando `--tablas=` traiga una tabla sin mapear;
- unir las líneas de cada literal antes de casar, que es lo que hace el detector de este
  documento y por lo que ve las multilínea.

**Y el saldo del cruce, que es lo que importa:** los dos instrumentos coinciden en la única que
ambos pueden ver, y **cada uno ve lo que al otro se le escapa**. El mío no ve consultas por
trozos ni vistas de base de datos; el suyo no ve multilínea ni Eloquent sin mapear. *Ninguno de
los dos, solo, habría dado trece.*

---

## 3.quinquies La catorce, que la trajo `myvc_front_2` — y por qué NO cambia el «1 de 13»

`myvc-front-0c` contestó al aviso con un dato que no estaba en el censo: **hay una respuesta
más que contiene `tono`**, `GET horario/versiones/{id}/lecciones`, que lo saca dentro de
`docentes: [{id, nombres, apellidos, tono}]`. **Reproducido aquí antes de escribirlo**, porque
venía de otro repositorio:

- `HorarioController::docentesDeLaVersion` (línea 1143) hace
  `SELECT hpd.pieza_id, p.id, p.nombres, p.apellidos, p.tono` — **columnas nombradas**.
- Y además **construye el array clave por clave**, no vuelca la fila.
- `tests/Contrato/HorarioLeccionesTest.php:271` hace
  `assertSame(['id','nombres','apellidos','tono'], array_keys($docentes[0]))`.

**Es de otra especie y por eso no entra en las trece.** Las trece son respuestas donde una
columna nueva **se cuela sola**. Ésta **no puede**: expone `tono` **a propósito y por su
nombre**, y una columna nueva de `profesores` no llegaría nunca a ese bloque, ni por la
consulta ni por el array. *`myvc-front-0c` dijo que ahí una columna nueva «os saldría roja»;
es cierto que el control existe, pero **no puede dispararse por esa causa**: no hay por dónde
colarse.*

> **Cuenta igual, y mucho: es el contraejemplo.** Es el único sitio del censo con **tres
> defensas independientes** —consulta que nombra, array que nombra, y una aserción sobre el
> **juego exacto de claves**— y es exactamente lo que las otras doce no tienen. *La diferencia
> entre esta ruta y las doce no es la suerte: es que ésta se escribió sabiendo qué se publicaba.*

**El compromiso que pidió `myvc_front_2`, y que queda escrito porque es contrato:** tienen
`docentes[]` **copiado y tipado** de su lado, con `strict` y `noUncheckedIndexedAccess`. Un
campo **de más** no les molesta; **uno de menos, o `tono` dejando de ser nullable, o
`nombres`/`apellidos` compuestos en el servidor, les rompe la compilación** — que es lo que
quieren. **Cualquier cambio de forma en `docentes[]` de esa ruta se avisa.**

---

## 3.sexies Verificadas por un segundo barrido independiente, y de dónde salía el «seis»

`8myvc-cd`, que había escrito un aviso propio enumerando **seis**, verificó las trece una a una
y **retiró su cifra en favor de ésta** (`2ca4191`). Coincidimos además en **los descartes sin
habernos hablado**: `Profesor::all()`, el `findOrFail` de `postInscribirProfesores`, el `->get()`
del borrado de imágenes, `putCambiarfirmaunprofe` —que devuelve `$img`— y
`ChangeAskedController::cambiarOficialProfesor`, **que sí hace `return $prof` pero cuyo único
llamante descarta el retorno**. *Dos instrumentos distintos y dos criterios que no se hablaron
llegando a la misma lista es lo más parecido a una prueba que hay aquí.*

### Y su explicación de por qué su seis era seis, que vale más que la corrección

No fue un descuido, y lo dejó escrito: **contó el alcance del encargo en vez del de la
pregunta** —«qué respuestas Eloquent de `ProfesoresController` y `GruposController`» en lugar de
«qué respuestas reparten `tono`»—. Y cuando se le avisó, **ensanchó un solo eje**: barrió el SQL
crudo, encontró lo de `DocentesExport`, y firmó *«seis sigue siendo seis, y ahora dice por dónde
se buscó»*. El otro eje —**qué controladores**— no lo tocó.

> **Ensanchar por un eje y dar el número por confirmado sale más caro que no ensanchar**, porque
> la frase *«ahora dice por dónde se buscó»* es exactamente la que hace que nadie vuelva a
> mirar. Un número con su método declarado **parece** auditado, y sólo lo está por el eje que se
> movió.

---

## 3.septies Un tercer barrido llegó a trece por su cuenta — y mi diagnóstico de su hueco era falso

`8myvc-9c`, que contaba **siete**, rederivó el censo y **llegó a trece de forma independiente**
(`10b107a`). Las once de Eloquent y las dos crudas son las mismas. **Escrito en su documento
como suyo y sin citar éste**, que es como se pidió: dos derivaciones independientes valen más
que una citando a la otra.

**Y me equivoqué al diagnosticarle el hueco.** Le dije que su barrido probablemente no veía
`putListado` porque el `SELECT p.*` y el `FROM` están en líneas distintas — el hueco que sí
tenía la herramienta. **Falso**: su detector une el literal completo, y `putListado` **sí le
salió** en la lista de seis, con el alias resuelto. Lo perdió **un escalón después**: comprobó
sitio a sitio **cuatro de las seis** y dejó dos fuera sin decirlo.

> **Y esa es la lección de la ronda, que es suya y vale para las tres sesiones:** *un detector
> correcto no protege de una lista que se estrecha entre un paso y el siguiente.* La cifra final
> no la produjo el patrón — la produjo una comprobación manual sobre una lista recortada a mano.
> **La población hay que arrastrarla hasta la última línea, no sólo imprimirla en la primera.**
>
> Su otro fallo fue cortar el barrido de `Profesor::` con `| head -30`, que le hizo perder
> `PerfilesController` e `ImagesUsuariosController` **enteros** — *treinta líneas de resultados
> es una salida que parece completa*. Es la regla del corte antes de contar, incumplida **en la
> terminal**, que es donde no la vigila ningún test.

**Confirmó por separado los tres descartes** —los tres falsos del `SELECT *` sobre subconsulta,
`DocentesExport` (comprobado por el otro extremo: `grep tono resources/views/` da cero) y
`cambiarOficialProfesor`, cuyo `return` muere en `:707`—. **Tres instrumentos distintos, tres
criterios que no se hablaron, la misma lista.**

### Y su aviso sobre la alcanzabilidad, que sí cambió el aviso al front

Señaló de qué es el trece, y tenía razón en que no estaba dicho: **son respuestas que ganan el
campo, no pantallas que lo van a ver.** Medido a raíz de eso: **una de las trece
—`GET perfiles/show/{id}`— está documentada en su propio docblock como que no la llama ningún
cliente**. Las otras doce **no llevan esa nota, y eso no es estar confirmadas en uso**: de
nuestro lado nadie lo ha medido, y sólo pueden medirlo los clientes. Escrito ya en el aviso, con
esas dos precisiones separadas.

---

## 4. Lo que hay que decidir, y no lo decide una sesión

**Nada de esto está roto**: `tono` es `null` en los diecisiete y es aditivo. Lo que cambia es
el tamaño del aviso al front — **trece respuestas, no una**, y **cuatro clientes**.

1. **¿Se avisa de las trece o se recorta el campo en alguna?** Recortar significa nombrar
   columnas donde hoy hay comodín, y eso **cambia la forma de esas respuestas** para todo lo
   demás que viaje en ellas hoy.
2. **¿Se le pone instantánea a alguna de las doce que no tienen?** *La 7 es la candidata
   obvia: es la gemela de la única que sí la tiene, y la asimetría entre las dos es
   precisamente por qué este documento existe.*

**CONTESTADAS por Joseth el 4 sep 2026: «avisar a los cuatro clientes y no tocar nada».**

- **No se recorta el campo en ninguna de las trece.** El argumento que lo decidió es el medido
  aquí: recortar significa **nombrar columnas donde hoy hay comodín**, y eso cambia la forma de
  esas respuestas **para todo lo demás que viaja hoy en ellas** — más riesgo del que quita.
- **No se le pone instantánea a la nº 7**, descartado explícitamente.
- **Lo que sí se hace es el aviso**, y de las trece y no de una:
  [`docs/AVISO-A-LOS-CLIENTES-tono.md`](../AVISO-A-LOS-CLIENTES-tono.md).

> **Lo que queda vivo después de la decisión**, y va en el aviso porque es lo que evita el
> próximo susto: **doce de las trece siguen sin nada que vigile su forma**, así que **la próxima
> columna que se añada a `profesores` puede llegar a los clientes sin que nada se ponga rojo**.
> *Que una ruta esté probada no es que su forma esté vigilada.*
