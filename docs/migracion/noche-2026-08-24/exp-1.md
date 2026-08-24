# EXP-1 — La exportación que llamaba a una API que no existe

> Lote de `8myvc-d2`, noche del 24 ago 2026. La medición de partida no es mía:
> viene de `myvc-front-98` vía `8myvc-34`, escrita en
> `~/DESARROLLOS/myvc_front-fase11/MIGRATION.md`, «Parte B». **Lo que sigue es lo
> que salió al comprobarla, y no coincide del todo con lo que decía.**

## §0. Lo que decía el aviso, y lo que resultó

| | El aviso | Medido |
|---|---|---|
| Sitios con la API 2.x | uno (`ExcelListadoDocentesController`) | **3 llamadas en 2 ficheros** |
| Vivos y rotos | uno | **dos** |
| Código muerto | «la de `SimatController` está detrás de un `return`» | cierto, **pero en `SimatController` hay DOS y sólo una lo está** |

El aviso era correcto en todo lo que afirmaba y **estaba incompleto en lo que no
miró**. La diferencia salió de la instrucción del lote —*«barre el patrón, no la
pantalla»*—: buscar `Excel::create` en `app/` en vez de abrir el fichero
señalado.

## §1. El fallo, confirmado y no supuesto

`maatwebsite/excel` **3.1.70** (comprobado en `composer.lock`). Los métodos que
existen de verdad, leídos de `vendor/maatwebsite/excel/src/Excel.php`:

```
download · store · queue · raw · import · toArray · toCollection · queueImport
```

**No hay `create`, y no hay `__call` ni `__callStatic`** que la rescate —
comprobado también en la fachada. Así que `Excel::create(...)` es un
`BadMethodCallException` seco: **500**.

Es la API **2.x**. Estas llamadas llevan rotas desde el salto de versión mayor.

## §2. Los tres sitios, y cuál duele

| Sitio | Ruta | ¿Vivo? |
|---|---|---|
| `ExcelListadoDocentesController::getDocentes` | `excel-docentes/docentes/{year}/{year_id}` | **500** |
| `SimatController::getAlumnos` | `simat/alumnos` | **500** |
| `SimatController::getAlumnosExportar` | `simat/alumnos-exportar` | funciona: hay un `return Excel::download(...)` delante |

**Los dos rotos tienen botón en el `myvc_front` que corre hoy en los dieciséis
colegios**: `InformesCtrl.ts:626` y `InformesCtrl.ts:700`, los dos por
`DownloadServ.download`. Son dos botones de la pantalla de Informes que llevan
años sin dar un fichero.

> **Y nadie lo ha reportado**, que es el dato que ordena la prioridad y no la
> conclusión. Es el mismo patrón que `boletines/detailed-notas`, que estuvo
> **cinco años** dando 500 a alumnos y acudientes porque al personal sí le
> respondía: **lo que no usa quien puede quejarse, no se reporta.**

### El alcance, dicho como lo que se sabe

`vendor/` es **compartido por symlink**, así que la 3.1.70 es la de todos los
colegios. `app/` es **copia real por colegio**, y esto se midió en la copia local.
Lo que se puede afirmar: *el código que hay en este árbol está roto contra la
versión que todos comparten*. Lo que **no** se puede afirmar sin mirar los
dieciséis: que los dieciséis tengan exactamente este `app/`.

## §3. El arreglo: copiar el patrón que funciona

Tres exportaciones del repo ya usan la 3.x —`cartera/exportar-solo-deudores`,
`simat/alumnos-exportar` y `acudientes-export/acudientes`—: `Excel::download(new
XExport, 'x.xlsx')` con hojas `FromView` en `app/Exports/`. Se copia ése.

- **`getDocentes`** → `App\Exports\DocentesExport`, nuevo. `FromView` directo y no
  `WithMultipleSheets`, porque el original tenía **una sola** `->sheet('Docentes',
  …)`; sus vecinos llevan la pareja Export+Sheet porque hacen una hoja por grupo.
  La consulta y la vista (`listado-docentes`) se copian **sin tocar una coma**: es
  un arreglo de la llamada, no de lo que sale.
- **`getAlumnos`** → reutiliza **`AlumnosExport`, que ya existía**. Monta
  exactamente lo mismo que este método montaba: los grupos del año, sus alumnos
  con acudientes, una hoja por grupo titulada con su `abrev`, y la vista `simat`.
  Escribir un export nuevo habría sido una segunda copia de la misma consulta.
  Sólo cambia el nombre del fichero, que es lo que distinguía las dos rutas.

### Lo que se pierde, dicho en voz alta

El `setBorder`, el `setWidth`, el `setHeight` y las notas de ayuda. En 3.x eso se
hace con `WithStyles`/`WithEvents` y **no se añade aquí a propósito**: estas hojas
llevan años sin salir, así que nadie tiene un fichero con esos bordes al que
comparar, y el estilo es una decisión de quien la use. Los dos vecinos que
funcionan tampoco lo llevan.

También se va el `$extension` que elegía entre `xls` y `xlsx` **mirando el
`referer`**: eso no es una propiedad del fichero sino del sitio desde el que se
pide. Sale `.xlsx` siempre.

### Y 123 líneas muertas que se van con ello

Detrás del `return` de `getAlumnosExportar` había **80 líneas** inalcanzables —la
tercera llamada 2.x— y su único llamante era el helper privado `Comentarios()`,
**43 líneas más** que quedaron sin nadie. Los dos se borran.

**No contradice la regla del repo** («sin ruta y roto se borra; con ruta y roto se
documenta»): esa regla protege el **endpoint**, y el endpoint sigue aquí y
funcionando. Lo que se borra es código que no puede ejecutarse nunca. Y hay un
motivo operativo: dejarlo obliga al centinela a llevar una excepción, y **un
centinela con excepciones no es un centinela**.

`SimatController` pasa de **187 a 85 líneas**.

## §4. Los tests, y los dos que se equivocaron primero

`tests/Contrato/ExportacionesExcelTest.php`, 4 casos. Dos merecen quedar escritos
porque **los dos fallos los cometí dentro del test que venía a cazarlos**:

**1. El primer test daba verde sin mirar el fichero.** Comprobaba que
`archivoDescargado()` no devolviera cadena vacía — y ese helper devuelve **la
ruta**, no los bytes. Estaba comprobando que una ruta de 88 caracteres no estaba
vacía. Es *el estado en vez del resultado*, en el fichero que existe para lo
contrario. Ahora lee el fichero: **empieza por `PK`** (un `.xlsx` es un zip) **y
pesa más de 3 kB**, que es lo que separa «salió el fichero» de «salió un libro
vacío».

**2. El centinela contaba menciones y no llamadas.** Buscaba `Excel::create` con
`str_contains` sobre el fichero entero y encontró tres — **las tres eran los
comentarios que explican este arreglo**, uno de ellos en el propio test. Ahora
quita los comentarios con `token_get_all` antes de buscar, como ya hacen
`GuardsDestructivosTest` y `UsuarioPerezosoTest` con su `sinComentarios()`.

> Es la regla del CLAUDE.md en las dos direcciones el mismo día: **el primer sitio
> donde mirar cuando el número sale raro es el detector**, y **un detector puede
> contar bien un síntoma sin contar la causa**.

**3. Y el guardián de población hizo su trabajo contra mí.** El centinela exige
haber revisado un mínimo de ficheros antes de creerse un «0 encontrados». Lo puse
en 300 y saltó: **son 220**. El umbral estaba mal, no el iterador — pero eso sólo
se supo porque el guardián obligó a ir a contarlos (`find app -name '*.php' | wc
-l`). Queda en 150, holgado a propósito: detecta «el iterador no encontró la
carpeta», no cuenta ficheros.

## §4b. Lo que el proyecto ya sabía — y las dos cosas que casi rompo

**Esto no era un hallazgo nuevo. Estaba medido desde el 19 ago 2026** y lo dijo
larastan, no yo: al arreglarlo, tres anotaciones de `phpstan.neon` dejaron de
corresponder a nada y el análisis falló con *«Ignored error pattern … was not
matched»*. Ésa es la señal que llevó a leerlas.

### El motivo del aplazamiento era falso, y no llevaba fecha de revisión

La anotación decía, textual:

> *«Los exports están fuera de alcance por decisión del plan (§5): reescribir
> estos dos a la API nueva es rehacer el informe, no arreglarlo.»*

**No hubo que rehacer ningún informe.** `simat/alumnos` reutiliza un export que ya
existía; el listado de docentes son sesenta líneas copiando la consulta y la vista
de siempre. La razón para aplazar era razonable cuando se escribió y **había
caducado sin que nadie volviera a mirarla**. Es lo que hay que llevarse de este
lote más que los dos endpoints: *una razón para aplazar también caduca*.

### Y había un `ExcelTest` que fijaba los 500 como contrato

Tres casos que afirmaban que estas rutas responden 500, puestos a propósito
—es lo que este repositorio hace con lo que está roto y se documenta en vez de
arreglarse—. Se han invertido dentro de ese mismo fichero en vez de crear uno
paralelo, y el fichero nuevo que llegué a escribir se borró: dos ficheros de test
golpeando las mismas tres rutas es la duplicación que aquí se evita.

### Lo que casi rompo, y es lo más importante de todo el lote

Al borrar el bloque muerto, el helper privado `Comentarios()` se quedó sin
llamantes y **lo borré**: privado, cero usos, borrado limpio, ningún test en rojo.

**Estaba mal, y ningún test lo habría dicho.** Otra anotación de `phpstan.neon`
—la del *«Unreachable statement»*, que fui a quitar después— explicaba por qué ese
método seguía ahí: lo que escribe son las instrucciones de cada columna de la
plantilla del SIMAT («Coloque: MATR, ASIS, RETI, DESE», «¿Es urbano? SI o NO»),
o sea **la especificación que `ImporterFixer` —que sí está vivo— lee de vuelta de
la hoja que devuelve la secretaría**. `AlumnosSheet` no las escribe, así que ese
método es **el único sitio del repositorio donde están**.

Restaurado, con el porqué movido a su propio docblock —donde lo leerá quien vaya a
borrarlo— y anotado en `phpstan.neon` como método sin usar que se conserva a
propósito.

> Es la forma de fallo de la casa en el sentido contrario al de siempre: **el
> detector tenía razón —era código muerto— y aun así la acción era la
> equivocada**, porque la razón para conservarlo no vivía en el código sino
> anotada al lado. *Un «esto no lo usa nadie» no es todavía un «esto se puede
> borrar».*

**Lo que habría que hacer algún día y no es este lote:** llevar esas ayudas al
`AlumnosSheet` con `WithEvents`/`AfterSheet`, para que la plantilla vuelva a salir
con ellas y la especificación viva donde se usa. Hoy la plantilla sale sin
instrucciones, y eso ya era cierto antes de este lote.

## §5. Lo que NO se tocó

- El resto de la Parte B —dompdf contra navegador sin cabeza, el endpoint genérico
  de exportación—: decidido para después de la fase 11. Cada controlador nuevo son
  dieciséis despliegues.
- La Parte A, que es del front.
- **Ningún endpoint que use `myvc_flutter`**: comprobado, la app no llama a
  ninguna de las tres.
- **Los tres importadores que usan `Excel::import($ruta, $closure)`**, que es la
  otra firma 2.x y siguen rotos con su anotación y sus tests. Son otro lote: el
  arreglo ahí no es cambiar la llamada sino escribir clases de importación, y uno
  de ellos decide qué se escribe en las matrículas.

## §6. Lo que hay que decirle al front

**Nada que cambie un cuerpo, un campo ni una ruta.** Las tres rutas siguen igual y
siguen devolviendo un fichero por `DownloadServ.download`. Lo único que cambia es
que **dos de ellas ahora devuelven uno**.

Un detalle menor por si alguien compara: `excel-docentes/docentes` sale ahora como
`.xlsx` y el front pide el fichero con nombre `.xls` en
`InformesCtrl.ts:626`. No rompe la descarga —el nombre lo pone el cliente— pero el
fichero que se guarda tendrá extensión `.xls` con contenido `xlsx`. **Excel lo
abre igual**; si molesta, es una línea del front y no del backend.
