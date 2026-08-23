# Lote C — La rejilla: quién escribe una definitiva y con qué candado

> Sesión `8myvc-1e`, noche del 22 al 23 de agosto de 2026. Árbol
> `.worktrees/c`, rama `fix/lote-c-rejilla-definitivas`, base
> `simonbolivar_testing_c`.
>
> Secciones asignadas del 05: **§89–92**. Están escritas con ese número desde el
> principio para que quien coordina no tenga que renumerar al fundir.

La pregunta del lote, tal como la dejó escrita
[15 §5](../15-la-noche-en-paralelo.md): **¿cuál de estas trece rutas escribe o
borra en la rejilla sin preguntar por el interruptor del periodo?**

La respuesta corta, medida y no leída: **de las trece, ninguna escribe en las
notas.** Lo que salió al mirarlas una a una es otra cosa, y es peor.

---

## §89. `boletines2/destroy` y `boletines3/destroy` no borran un boletín: borran un ALUMNO

Y son **las dos copias que la §72 no miró** — la sección que, el mismo día,
dejó escrita la lección de por qué eso pasa.

### 89.1 La operación tiene cuatro puertas y la §72 cerró dos

`Alumno::find($id)` seguido de `->delete()` está **cuatro veces** en `app/`:

| Ruta | Guard antes de esta noche | Sobre qué tabla opera |
|---|---|---|
| `DELETE api/alumnos/destroy/{id}` | `Autoriza::puedeEditarAlumnos` | `alumnos` |
| `DELETE api/editnota/destroy/{id}` | `Autoriza::puedeEditarAlumnos` (§72) | `alumnos` |
| `DELETE api/boletines2/destroy/{id}` | **nada; solo `auth.personal`** | `alumnos` |
| `DELETE api/boletines3/destroy/{id}` | **nada; solo `auth.personal`** | `alumnos` |

La [§72](../05-codigo-muerto-y-roto.md) cerró la de `editnota` y escribió al
hacerlo:

> Cerrar una de tres es lo que pasa cuando se arregla **el sitio que se está
> mirando y no la operación**.

Y se cerró sobre la población «`EditnotaController`», que es un controlador y no
una operación. Las dos de boletines son la misma copia byte a byte y siguieron
abiertas. **La sección que escribió la regla la incumplió en el mismo commit**,
lo cual es exactamente lo que la regla predice: quien cierra mira el fichero que
tiene abierto.

Que `boletines2/destroy` opera sobre `alumnos` **ya estaba medido** —está en la
`TABLA_DE_ID` del barrido, [§21.1](../05-codigo-muerto-y-roto.md), y en el
[09](../09-pendientes.md)—, y por eso este caso vale doble: es la
[§53](../05-codigo-muerto-y-roto.md) otra vez. **Medir una ruta no es haberla
juzgado.** El dato existía, se usó para que el barrido apuntara al id correcto, y
nadie preguntó si la ruta debía poder hacer eso.

### 89.2 El hueco era real, y es el mismo que midió la §72.1

`puedeEditarAlumnos` es superusuario **o** profesor con `profes_can_edit_alumnos`,
apagada en los dieciséis colegios. Medido antes de tocar nada, con un token de
profesor y la bandera apagada:

| Ruta | Antes | Alumno en la papelera |
|---|---|---|
| `alumnos/destroy` | 400 | no |
| `editnota/destroy` | 403 | no |
| `boletines2/destroy` | **200** | **sí** |
| `boletines3/destroy` | **200** | **sí** |

### 89.3 Cómo queda, y por qué no apaga ninguna pantalla

Las dos pasan a exigir `Autoriza::puedeEditarAlumnos`, que **no es un criterio
nuevo**: es el que ya decidieron sus dos hermanas. Y el único cliente que nombra
estos controladores es `myvc_front`, en `BoletinesApi.ts`, que declara
`detailed-notas` y `detailed-notas-group` — no `destroy`. `myvc_front_2` y
`myvc_flutter` no nombran `boletines2` ni `boletines3` en **ningún** fichero
(buscado sobre los 555 y 411 ficheros de cada repo, no sobre los `.ts`).

Lo fija `BoletinesBorranAlumnosTest`, con las cuatro puertas en un mismo caso y
el viaje de ida del superusuario para que se vea que se cerró la puerta y no la
casa.

**Los cuatro códigos no coinciden y se fijan tal cual**: `alumnos/destroy`
contesta 400 porque lo tiene escrito a mano en el controlador (legacy), y las
otras tres 403 porque las cerró `Autoriza::exigir`, que es código nuevo. Lo que
tienen en común es **el resultado**, que es lo que decide si un alumno está en la
papelera. Unificar el 400 cambia el contrato de una ruta que los clientes sí
llaman: **se anota, no se hace**, y no es de este lote.

### 89.4 Comprobado al revés

Quitando **solo** el guard de `boletines3` caen exactamente dos casos: el suyo del
proveedor de datos y el de las cuatro puertas. O sea que los dos caminos están
cubiertos por separado y no hay uno tapando al otro.
