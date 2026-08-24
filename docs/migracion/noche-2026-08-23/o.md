# Lote O — completar la población de `PerfilesController`

> Sesión `8myvc-1e`, noche del 22 al 23 de agosto de 2026. Árbol
> `.worktrees/o`, rama `fix/lote-o-perfiles-poblacion`, base
> `simonbolivar_testing_o`, arrancada de `2dc6462` — o sea con la segunda tanda
> del lote E ya dentro.
>
> Secciones asignadas del 05: **§130–132**.

**Este lote se cierra como resultado, no como arreglo.** La población está
completa, los seis miembros tienen veredicto y las gemelas coinciden. Lo único
que se ha escrito es lo que faltaba para poder decirlo: **la población era de
seis y todo el mundo la tenía por cinco.**

---

## §130. La población es de seis, y la sexta es el índice del recurso

La cabecera de `PerfilesApi.ts` del front avisa, con mayúsculas, de que **cinco**
métodos de `PerfilesController` operan sobre **GRUPO** y no sobre persona:
`show`, `destroy`, `forcedelete`, `restore` y `trashed`. Esa lista es la que ha
guiado los arreglos —el lote E midió y cerró las cinco— y es la que la ficha de
este lote daba por población.

Medida en el código en vez de leída de la ficha, **hay ocho métodos que nombran
`grupos`**, y leídos uno a uno **seis operan sobre un grupo**:

| Método | Ruta | Qué es |
|---|---|---|
| `getIndex` | `GET api/perfiles` | **la que faltaba**: devuelve los grupos del año |
| `getShow` | `GET api/perfiles/show/{id}` | devuelve el grupo — §104 |
| `deleteDestroy` | `DELETE api/perfiles/destroy/{id}` | manda un GRUPO a la papelera — §100 |
| `deleteForcedelete` | `DELETE api/perfiles/forcedelete/{id}` | la tercera puerta al mismo grupo — §100 |
| `putRestore` | `PUT api/perfiles/restore/{id}` | restaura un grupo |
| `getTrashed` | `GET api/perfiles/trashed` | la papelera de GRUPOS |

Los otros dos —`getUsername` y `getUsuariosall`— **cruzan** `grupos` para traer el
grupo del alumno que buscan, pero operan sobre personas. **Esa diferencia no la ve
un grep**, y por eso los ocho se leyeron uno a uno.

### 130.1 Por qué la lista del front no podía tener la sexta

`PerfilesApi.ts` es el fichero donde el front declara **lo que llama**. Y el front
**no llama al índice**: no hay `listar()` en esa factoría.

Comprobado, con control:

| Corpus | Resultado |
|---|---|
| Las **23 ramas** de `myvc_front` | ninguna llama a `GET perfiles`. Control: `perfiles/usuariosall` aparece en 3 ficheros en `main` y en 7 por rama |
| `myvc_front_2`, `myvc_flutter` | no nombran `perfiles` |
| **El bundle desplegado** (`myvc_dist`) | la factoría se lee entera y **no tiene `listar()`** — y la de al lado, `PeriodosApi`, **sí** tiene `listar(){return i.get(s)}` |

Ese último es el control que decide: la forma que se buscaba existe **dos líneas
más allá** en el mismo fichero minificado, así que si `PerfilesApi` la tuviera, se
habría visto.

> **Una población leída del fichero de un cliente está acotada por lo que ese
> cliente llama.** No es una lista de lo que hay: es una lista de lo que se usa.

Y el matiz que lo hace difícil de ver, que es lo que separa esto de la §89: **la
lista del front no estaba mal.** Estaba completa para lo que era. El error no es
de quien la escribió; es de haberla leído como si fuera el censo.

### 130.2 Qué se hace con la sexta: nada, y ya estaba decidido

`GET api/perfiles` **ya tiene veredicto**, en la lista de excepciones de familia de
`AutorizacionTest`, con estas palabras: *«no devuelve perfiles: devuelve los GRUPOS
del año. Es un catálogo con el nombre cambiado»*. Va con las lecturas de catálogo
que esperan la decisión del [08](../08-revision-idor.md).

Y **no hay asimetría que cerrar**: su gemela `GET api/grupos` lleva el mismo guard
—ninguno más que `auth.token`— y la misma consulta con más columnas. La de
`perfiles` es un subconjunto de la de `grupos`.

O sea que la sexta **no estaba sin juzgar: estaba juzgada en otro sitio**, y lo que
faltaba era que las dos cuentas —la del front y la del test de autorización—
supieran la una de la otra.

---

## §131. Lo que ya estaba hecho, comprobado antes de repetirlo

Lo primero de este lote fue **no volver a medir lo medido**. Los cinco de la lista
del front están cerrados y con test **en `main`**, comprobado en el código y no en
lo que decía la ficha —que es la lección que costó el lote M unas horas antes—:

| Ruta | Cómo está en `main` | Test |
|---|---|---|
| `perfiles/destroy/{id}` | cerrada, §100 | `BorrarUnPerfilBorraUnGrupoTest` |
| `perfiles/forcedelete/{id}` | cerrada, §100 | ídem |
| `perfiles/trashed` | fijada como copia fiel de `grupos/trashed` | ídem |
| `perfiles/restore/{id}` | `Autoriza::esSuperusuario` | `PapeleraRestaurarTest` |
| `perfiles/show/{id}` | `Profesor::find`, alineada con su gemela, §104 | `BorrarUnPerfilBorraUnGrupoTest` |

**Y las gemelas coinciden**, que era la otra mitad de la pregunta del lote:
`GruposController::putRestore` exige `esSuperusuario` igual que la de perfiles, y
`GruposController::getTrashed` es la misma línea. No hay ninguna cerrada más que su
copia.

> Que las cinco salgan bien **es el resultado**, no un hueco. Se dice entero para
> que nadie las vuelva a medir: son cinco rutas, cinco veredictos y tres tests.

---

## §132. Lo que se ha escrito, que es una cuenta y una etiqueta

**1. `PoblacionDePerfilesTest`** — dos casos, y ninguno arregla nada: fijan **el
tamaño de la población**, que es lo que nadie tenía.

- `son ocho los metodos que nombran grupos`: lee el controlador —sin comentarios,
  que es el arreglo que la §72.5 le hizo a su propio detector— y compara con los
  ocho conocidos. **Comprobado al revés**: añadiendo un séptimo método que nombre
  `grupos`, el caso cae y lo nombra.
- `seis de los ocho operan sobre un grupo`: fija la parte que **no** se midió sino
  que se leyó, con el veredicto de cada uno al lado.

Un séptimo miembro se ve **el día que se escribe**, y no cuando alguien lo
tropiece.

**2. Una etiqueta corregida en `MuestreoDeLecturasTest`.** `api/perfiles` estaba
clasificada bajo **«Personas»**, entre `api/profesores` y `api/perfiles/usernames`.
Devuelve grupos —se ve en su propio snapshot, `muestreo-perfiles.json`, que trae
`grado_id` y `titular_id`—. No cambia ningún comportamiento ni ninguna aserción:
cambia dónde está escrita, y por qué.

> **Una lista que agrupa por el nombre del recurso hereda el nombre equivocado.**
> Es la cuarta forma de la misma cosa esta noche, después de una tabla de
> veredictos ([§90](c.md)), la cabecera de una herramienta ([§105](g.md)) y una
> columna calculada ([§107.1](g.md)): lo que apaga la pregunta no es un detector
> callado, es **un renglón que dice otra cosa** — y aquí ni siquiera dice «ya
> está», dice «esto es de personas», que basta.

---

## Lo que se nota en un colegio (para DESPLIEGUE.md)

**Nada.** Este lote no cambia una línea de `app/` ni de `routes/`: dos tests y un
documento.

---

# Apéndice — `escrituras-en-las-notas.py`: cuatro fronteras, y el detector se iba quedando ciego solo

> **Esto no es el lote O.** Es el encargo que coordinación repartió al salir de la
> §92 del [lote C](c.md): ampliar el detector con `nota_comportamiento` y volver a
> correrlo guardando el antes y el después. Va en esta rama por no montar un árbol
> para tres ficheros; el controlador de ese hallazgo es del lote P.

## El antes y el después

```
antes:    14 métodos escriben en las notas   ·  sin preguntar: 3 de 14
después:  18 métodos escriben en las notas   ·  sin preguntar: 5 de 18
          + una sección nueva con 4 métodos de MODELO y quién los llama
```

## Las cuatro fronteras, y sólo una era un bug

Ampliar `TABLAS` era el encargo, y resultó ser **la primera de cuatro**. Las cuatro
estaban en el mismo recorte y ninguna daba error: **el detector contestaba menos y
con la misma cara.**

| # | Frontera | Qué escondía | Qué es |
|---|---|---|---|
| 1 | `TABLAS` sin `nota_comportamiento` | los dos escritores directos de esa tabla | **una decisión**, tomada el 22 sobre un candado decidido el 21 |
| 2 | `RAIZ = 'app/Http/Controllers'` | todo lo que escribe desde un modelo | **una decisión**: mirar dónde se pide el candado, no dónde se escribe |
| 3 | el recorte no aceptaba `static` | casi todos los métodos de modelo | sintaxis |
| 4 | el recorte sólo aceptaba **tabuladores** | los ficheros formateados con pint | sintaxis, **y la peor** |

### La cuarta es la que hay que contar

El recorte de métodos empezaba por `\n\t*`: **sólo veía la indentación con
tabuladores**. `app/Models/NotaFinal.php` va con cuatro espacios porque lo formateó
pint, y por eso `alumnos_grupo_nota_final` —que hace `DELETE FROM notas_finales`—
no aparecía.

Y pint, en este repo, **se pasa fichero a fichero el día que se toca cada uno**
(CLAUDE.md). O sea:

> **El detector se iba quedando ciego solo, al ritmo de la migración, y
> justamente sobre los ficheros recién tocados** — los que más falta hace mirar.

No es un instrumento que mienta: es uno que **encoge**, sin avisar y en la
dirección tranquilizadora. La lista se hacía más corta según avanzaba el trabajo.

## Lo que apareció al arreglar las cuatro

**Dos escritores nuevos en controladores, los dos sin preguntar:**

- `Alumnos\Definitivas::calcular_notas_finales_asignatura` y `…_periodo`. Es el
  cuerpo de la ruta que la [§71](../05-codigo-muerto-y-roto.md) cortó con un 410.
  **No los llama nadie** —comprobado: sus únicas apariciones en `app/` son sus
  propias definiciones y un docblock—, así que hoy son inertes. Se anotan porque
  el código sigue ahí y borrar el cuerpo de una ruta cortada es una decisión.

**Y la sección nueva de modelos, que es donde estaba lo que se buscaba:**

| Modelo | Llamante | ¿Pregunta? |
|---|---|---|
| `Nota::verificarCrearNotas` | `NotasController::putDetailed` | **NO** — ya documentado y congelado (10 §3) |
| `NotaComportamiento::crearVerifNota` | `NotaComportamientoController::getDetailed` | **NO** — el caso que abrió esto; es del lote P |
| **`NotaFinal::alumnos_grupo_nota_final`** | **`DefinitivasPeriodosController::getIndex`** | **NO** |
| `NotaFinal::calcularAsignaturaPeriodo` | ~~`Subunidades`/`Unidades` `putUpdate` y `deleteDestroy`~~ · **hoy: ninguno** | ~~**sí, los cuatro**~~ · *las cuatro llamadas se quitaron al cablear el recalculador único (fase 3). Comprobado el 25 ago: cero llamadas en `app/`. Ver [10 §0](../10-definitivas.md)* |

La tercera fila es nueva y no es de nadie: **`GET api/definitivas_periodos`** —el
índice, con `auth.token` y nada más— llama a `alumnos_grupo_nota_final`, que hace
un `DELETE FROM notas_finales` por alumno y vuelve a insertar. Es **el segundo GET
que escribe en la rejilla** después de `arreglar-duplicados`, y a diferencia de
aquél **no pregunta por el candado**. Está en la lista de excepciones de
`AutorizacionTest` como «rota: `$profe_id` no se define para este tipo de usuario»
— o sea que se juzgó **por lo que falla**, no por lo que escribe cuando no falla.
Pasado a coordinación.

Y la cuarta fila es la buena noticia que también hay que decir: `calcularAsignatura
Periodo`, uno de los seis escritores de `notas_finales` del
[10 §0](../10-definitivas.md), **lo llaman cuatro sitios y los cuatro preguntan**.

## Lo que queda montado

No es la línea nueva de `TABLAS`: es la costumbre. **Cuando una decisión mete una
tabla bajo un candado, hay que releer la definición de los detectores que miran ese
candado.** Y cuando un detector recorta código con una expresión regular, esa
expresión es una frontera más — que aquí, además, **se movía sola**.
