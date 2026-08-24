# VERBOS-1 — los `DB::select` que escriben

**Sesión `8myvc-12`** · rama `fix/boletin-independiente-alcance` (la misma; el lote
anterior cerró en ella) · sobre `main` de la noche del 25.

---

## §1. No son seis: son ocho

El [05 §191](../05-codigo-muerto-y-roto.md) censó **seis**. Los seis están, y hay **dos
más que ese censo no lista** — y **los dos escriben `notas_finales`**:

| sitio | sentencia | estado |
|---|---|---|
| `Services/ContextoDeUsuario:244` | `UPDATE users SET periodo_id` | corre en **cada petición** (rama de reparación) |
| `Matriculas/Enfermeria:48` | `INSERT INTO antecedentes` | |
| `Matriculas/Enfermeria:109` | `INSERT INTO registros_enfermeria` | |
| `Matriculas/Requisitos:64` | `UPDATE requisitos_matricula` | |
| `Matriculas/Requisitos:81` | `UPDATE requisitos_alumno` | |
| `RolesController:98` | `INSERT INTO role_user` | |
| **`DefinitivasPeriodos:154`** | **`INSERT INTO notas_finales`** | **no estaba en el censo · VIVO** |
| **`Models/NotaFinal:315`** | **`INSERT INTO notas_finales`** | **no estaba en el censo · sin camino** |

> **El censo no falló en un sitio cualquiera: falló en la tabla de la que va la mitad del
> plan.** Y uno de los dos está desplegado en los dieciséis y corre cada vez que alguien
> pulsa «calcular grupo/periodo».

**Siete se tocan, ocho se cuentan.** `NotaFinal:315` se queda con la palabra vieja porque
su método **no lo llama nadie** —cambiarla sería mover código muerto, y lo que no tiene
ruta lo decide Joseth con los otros 34—. Lleva ahora la única línea que se le debe: **es
copia palabra por palabra del vivo, que ya se arregló**; quien lo resucite tiene que
traerse la palabra de allí o el agujero vuelve.

### Y mi primer número fue 94

El detector marcaba `$consulta` como «escribe» al ver el primer `INSERT` **y no borraba la
marca al reasignarla**. En este repo `$consulta` se reutiliza decenas de veces por método:
`GruposController` aportaba **dieciséis**, todos apuntando a la misma sentencia. Corregido
a *«la ÚLTIMA asignación de esa variable escribe»*, salen ocho.

**94 → 8 no es afinar: el 94 medía otra cosa.** Y es el cuarto de la noche con el mismo
apellido — ver [§4](#4-cuatro-detectores-un-solo-apellido).

---

## §2. El lote sólo es neutro si nadie mira lo que vuelve

`DB::select` devuelve **filas**; `DB::update` y `DB::insert` devuelven **un entero o un
bool**. Así que cambiar la palabra **cambia el valor de retorno**, y el lote sólo es neutro
si ese valor está muerto. **Comprobado llamador a llamador, no supuesto:**

    ContextoDeUsuario:244    $periodos = …  y la línea siguiente es `return`. Muerta.
    Enfermeria:48            $antecedentes pisado por el SELECT de la línea siguiente.
    Enfermeria:109           $antecedentes no se lee hasta que :135 lo reasigna.
    Requisitos:64, :81       sin asignación.
    Roles:98                 $roles = …  y el método devuelve $user. Nunca se lee.
    DefinitivasPeriodos:154  sin asignación.

**Las cuatro asignaciones muertas se quitan**, y en un caso eso es lo correcto y no lo
mínimo: `ContextoDeUsuario` hacía `$periodos = DB::select(UPDATE…)`, o sea **pisaba el
array de periodos con el resultado de un UPDATE**. *El nombre de la variable mentía sobre
lo que contenía.*

### La asimetría dentro del fichero, otra vez

**En dos de los siete el patrón correcto ya estaba al lado**: `Enfermeria:135` usa
`DB::update` y `Roles:128` usa `DB::delete`, **en los mismos ficheros**.

> **Cuando algo parece mal escrito, mirar si su hermano bueno está en el mismo fichero
> contesta a la vez «¿es un fallo?» y «¿cuál es la forma correcta aquí?».**

---

## §3. No hay «rojo antes», y eso era un error del enunciado

La ficha pedía *«un test que falle antes y pase después»*. **No se puede, y la razón es la
premisa del lote**: `DB::select` con un `INSERT` escribe exactamente igual que
`DB::insert`. La palabra no cambia la conducta — ésa es toda la seguridad de VERBOS-1, y
es lo que hizo que este lote **no** se cruzara con la acotada de `DefinitivasPeriodos:108`.
Un test sobre el resultado **pasa en verde antes y después**; uno que fallara antes estaría
midiendo otra cosa.

**Y pedir un rojo imposible es pedir que alguien lo fabrique**: la salida cómoda era mirar
algo tangencial, verlo caer y llamarlo red.

> **La red no es «rojo antes»: es «rojo si la escritura deja de ocurrir».**

Verificado, no supuesto:

    los seis tests contra el código de ANTES        ->  6 verdes
    las seis escrituras rotas a propósito           ->  6 ROJOS
    restaurado, y con la palabra nueva              ->  6 verdes

Las seis se rompieron **sin cambiar el número de parámetros** —`WHERE id=? AND FALSE`,
`… SELECT ?,?,… FROM DUAL WHERE FALSE`, `LIMIT 1` → `LIMIT 0`— para que cayeran **por lo
suyo y no por un error de binding**. *Un fallo de parámetros también las pondría rojas, y
entonces el control no probaría nada.*

**Un test de caracterización que nunca se ha visto caer no distingue «la escritura ocurre»
de «mi test no la mira».** Es el «0 encontrados» de las herramientas de `tools/`, en forma
de aserción.

### Y un aserto que se ganó el sitio

El de `role_user` cayó en la primera pasada, **diciendo por qué**: *«el token usado no puede
administrar usuarios, así que este test no llega al INSERT»*. `exigirAdminUsuarios()` corta
con 403 a quien no sea superusuario. **Sin esa línea habría sido un verde que no miraba la
escritura** — el 403 habría pasado por «respondió bien».

---

## §4. Cuatro detectores, un solo apellido

    BI-2   el filtro más grueso           -> 5 lecturas etiquetadas «sin alcance» y acotadas
    VERBOS el nombre del método            -> los 8 de este lote, en el cajón de lectura
    AUD    `escrituras-sin-auditoria`      -> no ve Eloquent: 32 contra 52
    VERBOS la variable reasignada          -> 94 donde había 8

> **El predicado decide por lo que ve primero y no por lo que gobierna la llamada.**

Los cuatro contaban bien lo que su patrón reconocía. **Ninguno estaba roto.**

---

## §5. Cierre

    8 sitios · 7 con la palabra cambiada · 1 anotado y sin tocar (sin camino)
    6 tests de caracterización, con su control verificado
    --testsuite=Contrato  1.336 verdes · 9.966 aserciones · 0 fallos
    pint PASS (292) · larastan nivel 7 [OK] · Snapshots/ sin tocar

**Lo que queda pendiente en el mismo método que uno de los siete:**
`DefinitivasPeriodos:108`, la lectura de `unidades` sin alcance que salió de BI-2 con ciclo
propio. **Está dicho en el código**, para que quien abra el fichero no crea que se revisó
entero.
