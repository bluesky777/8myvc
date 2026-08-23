# Lote L — Las sobras huérfanas

> Sesión `8myvc-2f`, árbol `.worktrees/l`, rama `fix/lote-l-sobras`,
> base `simonbolivar_testing_l`. Noche del 22 al 23 de agosto de 2026.
> Secciones asignadas del [05](../05-codigo-muerto-y-roto.md): **§123–124**.
> Esta sesión cerró antes los lotes [B](b.md), [F](f.md), [K](k.md) y [H](h.md).

Este lote existe porque los demás anotaron cosas en ficheros que no eran de nadie.
Las dos que traía **venían con la población mal contada**, y las dos veces el
error era el mismo:

> **Un `grep` no sabe leer.** No sabe que una línea está dentro de un `/* */`, ni
> que una llamada idéntica es basura en un método y mecanismo en otro. Las dos
> notas que este lote heredó las escribí yo mismo, en el lote K, una hora antes —
> y las dos ordenaban mal el trabajo.

| § | Lo que decía la nota | Lo que era |
|---|---|---|
| §123 | tres sitios con `G:H:i`, **uno lo LEE** | dos escrituras y **ningún lector**: el tercero está comentado |
| §124 | «quitados cinco pines, greppea el resto» | ocho vivos: **cinco basura y tres que hay que dejar** |

---

## §123 — El que leía estaba comentado

`G` y `H` son las dos la hora del día —una sin cero delante, la otra con él—, así
que `'Y-m-d G:H:i'` es `hora:hora:minutos` y **los segundos no llegan nunca**: las
21:07:33 se guardan como **21:21:07**.

| Sitio | Qué hace |
|---|---|
| `ChangeAskedController:947` | escribe — arreglado en el [lote K](k.md#§121) |
| `Tardanzas/TSubirController:103` | **escribe** — arreglado aquí |
| `AusenciasController:177` | **está dentro de un `/* */`**. No lee nadie |

**La conclusión que colgaba de la nota era la contraria**: «arreglar al que escribe
rompe al que lee, así que mira dos veces». No hay a quién romper, y por eso el
arreglo es seguro.

Y es la misma ceguera que el [lote H](h.md#3-lee-la-prosa) acababa de quitarle a
`identificadores-del-cuerpo.py` —«un detector que busca una palabra tiene que
mirar solo el código»— **aplicada a la nota de uno mismo, con una hora de
diferencia**. Que la lección estuviera escrita no impidió repetirla: lo que la
impide es ejecutar el `grep` con los cinco renglones de alrededor delante.

### El de las tardanzas es el de más volumen de los dos

`TSubirController` escribe `created_at` y `updated_at` de **cada ausencia que sube
el lector de tardanzas**, no la de un pedido de cambio que se cierra de vez en
cuando.

**El daño ya hecho no se puede medir desde aquí**: el seed no trae ni una fila con
`uploaded = 'created'`, o sea que este camino no se ha usado en la copia de este
colegio. Lo que queda es el arreglo y su test.

### Y dos cosas más de esas tres rutas, que quedan fijadas

`identificadores-del-cuerpo.py` las enseña con el guard en **`—`**, que se lee
como «sin guard». **No lo están**:

- Se autentican **dentro del método**: usuario y contraseña en el cuerpo,
  `Credenciales::verificar`, y exigen `Profesor` o superusuario. Un alumno con su
  contraseña buena recibe **400**. Es la quinta ceguera del [§108](h.md), ahora con
  un test que la demuestra.
- **La misma contraseña equivocada da 401 o 400** según entre dentro de
  `loginData` o suelta en la raíz, porque el `else if` de después mira solo las de
  la raíz. **No se toca**: los dos caminos rechazan, que es lo que importa, y
  unificarlo cambia el código que lee el lector de tardanzas.

---

## §124 — Ocho pines vivos, y tres son mecanismo

`Debugging::pin()` no es un comentario ni un log: hace `new Debugging` y `save()`,
o sea **una fila de verdad** en la tabla `debugging`.

| Dónde | Cuántos | Qué son |
|---|---|---|
| `ChangeAskedController` | 5 | depuración pura — quitados en el [lote K](k.md#§121) |
| `Alumnos/ImportarController` | **3** | **deliberados. No se tocan** |
| el resto de `app/` | 12 | ya estaban comentados |

### Por qué los tres del importador se quedan

Llevan su decisión escrita al lado, y **sigue siendo cierta**:

- **La del import principal** dice que **ya no hace ese trabajo** —lo hace
  `importaciones`, con `PuntoDeControlDeImportacion`— y que se queda porque es
  **el único rastro de las importaciones anteriores a hoy en las dieciséis
  bases**. Es una decisión tomada, no un olvido.
- **Las otras dos** llevan `//No eliminar para continuar si se cae el servidor!!`
  y están en `postCartera()` y `getModificar()`, **que no son el import principal
  y no usan `PuntoDeControlDeImportacion`**: para esos dos, el pin puede seguir
  siendo el único punto de control que hay. Quitarlos sin comprobar qué hace cada
  método al reanudar es justo lo que el aviso pide que no se haga.

> **Ocho coincidencias de un `grep` no son ocho fallos.** Cinco eran basura y tres
> son mecanismo, y **lo que las separa no está en la llamada sino en el método
> donde vive**.

### Lo que sí queda medido

El comentario del importador afirmaba que «`debugging` crece una fila por alumno
importado y no se limpia nunca». Era una afirmación; ahora es un número: se
importa la hoja que produce el propio export y **se cuenta antes y después**. Una
fila por alumno, y la tabla **no tiene borrado ni límite**, así que ese número se
suma al de todas las importaciones anteriores en cada uno de los dieciséis
colegios.

Y el otro número, medido aquí y arreglado allí: **cerrar un pedido de cambio
escribía dos filas**. Esa mitad la cierra el lote K y su test vive en esa rama;
aquí queda el número, que es lo que dice cuánto valía quitarlas.

---

## PARA JOSETH

1. **¿Se limpia `debugging`?** Crece una fila por alumno importado, en dieciséis
   colegios, desde siempre y sin borrado. Limpiarla borra **el único rastro de las
   importaciones anteriores a agosto de 2026**, así que la pregunta no es técnica.
   (§124)
2. **La hora mal escrita está en filas ya guardadas**, aquí y en `change_asked`
   (§121). Los `created_at`/`updated_at` de las ausencias subidas por el lector
   antes de este despliegue llevan `hora:hora:minutos`. ¿Migración o nota? (§123)

## PARA OTRO LOTE

- **`ImportarController::postCartera()` y `::getModificar()`** — no usan
  `PuntoDeControlDeImportacion`, que es el mecanismo de reanudación del import
  principal. Si alguna vez se migran, **entonces** sus dos `Debugging::pin` dejan
  de ser mecanismo y se pueden quitar. Antes no.

## Lo que se nota en un colegio

- **Las ausencias que sube el lector de tardanzas quedan con la hora de verdad.**
  Las anteriores al despliegue siguen mal.
- Nada más. Ni una respuesta cambia de forma.

## Migraciones

**Ninguna.**
