# Aviso a los cuatro clientes: `profesores.tono` aparece en trece respuestas

**4 sep 2026.** Decisión de Joseth: **se avisa y no se toca nada.** Este documento es el
aviso. Está en `docs/` y no en `docs/migracion/` porque **no es para nosotros**: es para quien
mantiene `myvc_front`, `myvc_front_2`, `myvc_flutter` y cualquier integración.

---

## Lo que cambia, en una frase

La migración `2026_09_04_200000_tono_del_docente` añade la columna **`tono`** (color del
docente, `varchar(32) NULL`) a la tabla `profesores`. **Trece respuestas de la API la incluyen
sin que nadie las haya tocado**, porque leen la fila entera del docente.

## Lo que NO tenéis que hacer hoy

**Nada se rompe.** `tono` es **aditivo** —un campo más, ninguno se va ni cambia de tipo— y
vale **`null` en los diecisiete colegios**, porque todavía no hay ninguna ruta que lo escriba.
Un cliente que ignore campos desconocidos no se entera. **No hay que publicar nada corriendo.**

Lo único que importa: **si algún cliente valida la forma de estas respuestas de forma
estricta** —un esquema cerrado, un `Codable`/`fromJson` que rechace claves de más, un test de
igualdad exacta— **ahí sí hay que añadir `tono` antes de que la migración llegue a su
colegio**. Y el tipo es **`string | null`**, no `string`: hoy es `null` en todos.

## Las trece rutas

| # | Ruta | Dónde aparece `tono` |
|---|---|---|
| 1 | `GET grupos/{id}` | dentro de `titular` |
| 2 | `POST profesores/store` | el docente devuelto |
| 3 | `PUT profesores/update/{id}` | el docente devuelto |
| 4 | `DELETE profesores/destroy/{id}` | el docente devuelto |
| 5 | `DELETE profesores/forcedelete/{id}` | el docente devuelto |
| 6 | `PUT profesores/restore/{id}` | el docente devuelto |
| 7 | `GET perfiles/show/{id}` | dentro de `titular` — es la gemela de la 1 |
| 8 | `PUT perfiles/update/{id}` | el perfil devuelto, **sólo cuando `tipo=Profesor`** |
| 9 | `PUT perfiles/cambiarimgunprofe/{id}` | el docente devuelto |
| 10 | `PUT images-users/cambiar-foto-un-usuario/{id}` | la persona devuelta, **sólo si es docente** |
| 11 | `PUT images-users/cambiar-firma-un-profe/{id}` | el docente devuelto |
| 12 | `PUT profesores/listado` | cada elemento de `profesores` |
| 13 | `PUT participantes/profesores` | cada elemento de `participantes` |

> **De qué es este trece, dicho exacto porque cambia dónde miráis: son respuestas que GANAN
> el campo, no pantallas que lo van a ver.** La lista sale de qué devuelve cada método, no de
> quién lo llama. **Y una de las trece está documentada como que no la llama ningún cliente**:
> `GET perfiles/show/{id}`, en su propio docblock. Las otras doce **no tienen esa nota, que no
> es lo mismo que estar confirmadas en uso** — de eso no hay medición de nuestro lado, y el
> único que puede hacerla sois vosotros. *Si alguna os sale muerta, decidlo y la anotamos.*

> **Si vais a probar las trece, dos necesitan una ficha de docente para reproducirse.**
> `PUT perfiles/update/{id}` y `PUT images-users/cambiar-foto-un-usuario/{id}` sólo reparten
> `tono` **cuando la persona es un Profesor** —las otras once, siempre—. Contra un alumno o un
> acudiente esas dos no lo traen, y **sin este párrafo eso se lee como que el aviso falla**.

**Dónde NO aparece, por si lo buscáis:** `GET profesores/show/{id}` **no lo trae** —usa una
consulta que nombra sus columnas—, y el **Excel de listado de docentes tampoco**: su plantilla
nombra las 17 columnas que imprime.

## Y lo que de verdad conviene que sepáis

**Doce de esas trece no tienen nada que vigile su forma.** Sólo `GET grupos/{id}` tiene
instantánea de contrato, y es la única por la que nos enteramos. Las otras doce **sí tienen
tests** —hasta cinco en una— y ninguno mira la forma de la respuesta:

> **Que una ruta esté probada no es que su forma esté vigilada.** Un campo de más las deja
> verdes a todas.

O sea que **la próxima columna que se añada a `profesores` puede llegaros sin aviso**, porque
no hay nada que la detecte del lado del backend. Si algún cliente vuestro es sensible a campos
nuevos, decidlo y se le pone instantánea a esa ruta: **es barato y es lo único que lo caza.**

## Y una catorce, que es la bien hecha

`GET horario/versiones/{id}/lecciones` **también trae `tono`**, pero **a propósito y por su
nombre**, dentro de `docentes: [{id, nombres, apellidos, tono}]`. No entra en las trece porque
ahí una columna nueva **no se puede colar**: la consulta nombra sus columnas, el array se
construye clave por clave, y hay un test que asevera el **juego exacto de claves**. Tres
defensas independientes.

`myvc_front_2` ya tiene esa forma copiada y tipada, con `tono: string | null`. **Compromiso por
nuestra parte: cualquier cambio de forma en ese `docentes[]` —un campo que se va, `tono`
dejando de ser nullable, o `nombres`/`apellidos` compuestos en el servidor— se avisa antes.**

El censo completo, con los descartes y con lo que el instrumento no ve, está en
[`docs/migracion/30-lo-que-reparte-una-columna-nueva.md`](migracion/30-lo-que-reparte-una-columna-nueva.md).
