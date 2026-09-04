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

## 4. Lo que hay que decidir, y no lo decide una sesión

**Nada de esto está roto**: `tono` es `null` en los diecisiete y es aditivo. Lo que cambia es
el tamaño del aviso al front — **trece respuestas, no una**, y **cuatro clientes**.

1. **¿Se avisa de las trece o se recorta el campo en alguna?** Recortar significa nombrar
   columnas donde hoy hay comodín, y eso **cambia la forma de esas respuestas** para todo lo
   demás que viaje en ellas hoy.
2. **¿Se le pone instantánea a alguna de las doce que no tienen?** *La 7 es la candidata
   obvia: es la gemela de la única que sí la tiene, y la asimetría entre las dos es
   precisamente por qué este documento existe.*

**Las dos son de Joseth.** Aquí sólo está medido.
