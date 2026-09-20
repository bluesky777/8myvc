# El día de matrículas: las estaciones, y lo que decide el colegio

La **fase 1** del proceso de admisión: el recorrido del día de matrículas, con estaciones que el
colegio configura y un aviso automático de *«devuélvase a la estación 3»*. Sin portal público, sin
pasarela y sin tocar dinero — eso son las fases 2 a 4.

El análisis y las quince pantallas están en
[`myvc_front/PANTALLAS-MATRICULA.md`](../../../myvc_front/PANTALLAS-MATRICULA.md); esto es lo
decidido, lo medido y lo que falta para poder escribirlo.

---

## 1. El relato de Joseth, que es el requisito

> *«Vende el formulario en tesorería físicamente. Los días de matrículas ya tienen configurado mi
> sistema con requerimientos, los cuales son marcados en cada estación. El acudiente entra al
> colegio y la primera estación le entrega carpeta del estudiante si es antiguo o le revisa los
> documentos para que no pierda el tiempo. Las siguientes estaciones están marcadas con números
> impresos. Cuando termina con la estación documentos, el profesor que atendió busca al alumno en
> el sistema y chulea el requisito "Documentos"… Si una estación busca al estudiante y ve que el
> requisito 2 no está marcado y esta es la estación 4, entonces le dice que se devuelva a la
> estación 3. Pero todo esto es personalizado por el colegio.»*

**Esto no se construye de cero**: `requisitos_matricula` y `requisitos_alumno` existen desde el
esquema congelado, con seis rutas vivas (`RequisitosController`). Lo que hace falta es
**ensancharlas**.

## 2. Decidido por Joseth el 20 sep 2026

| | |
|---|---|
| **Cuántas estaciones** | **las que el colegio quiera.** No hay número fijo ni plantilla obligatoria: es un editor de pasos, no un diagrama grabado en el código |
| **Si una estación frena** | **cada una es «obligatoria antes de continuar» u «opcional»** |

### Y la segunda decisión COLAPSA algo que la propuesta separaba — va dicho, no corregido

`PANTALLAS-MATRICULA.md` §3 distinguía **dos** propiedades:

> *«**Obligatorio ≠ bloqueante.** Un paso obligatorio hay que cumplirlo para matricular; uno
> bloqueante impide pasar al siguiente. La entrevista de orientación suele ser obligatoria y no
> bloqueante; los documentos, las dos cosas.»*

**Joseth describió una sola**: obligatoria *antes de continuar*, u opcional. Eso es **bloqueante o
nada**, y es más simple — una columna en vez de dos, y un interruptor en la pantalla en vez de dos
casillas que nadie sabe distinguir.

**Lo que se pierde, dicho una vez y sin re-litigar**: el caso de *«hay que hacerlo, pero no aquí ni
ahora»*. La entrevista de orientación es el ejemplo: es obligatoria para matricular y **no debería
impedir que la familia pase a tesorería mientras espera turno**. Con un solo interruptor, esa
estación sólo puede ser **opcional** —y entonces nada recuerda que falta— o **bloqueante** —y
entonces la cola de orientación para el día entero.

**Se construye con un interruptor, que es lo que él decidió.** Queda escrito porque el día que un
colegio pida «que no frene pero que no se me olvide», la respuesta no es un parche: es esta
decisión, revisada con el caso delante.

## 3. Lo medido, y por qué no alcanza todavía

**En la copia de desarrollo (UN colegio) esto no se usa**: 1 fila en `requisitos_matricula` en
todos los años, **0 en el año actual**, 12 marcas y las 12 en «falta». Y la única fila se llama
`Fotocopia del documento (verificacion 234754)`, del 22 ago 2026 — un dato de prueba.

**Leído sin denominador eso dice «funcionalidad muerta», y es falso.** Joseth nombró el colegio que
sí la usa: **`lalvirtual`**. La cifra de desarrollo no estaba mal — *contestaba bien a otra
pregunta*.

La medición está escrita y probada, y **falta correrla**:

```bash
php tools/requisitos-de-matricula.php micolev1_lal_db
php tools/requisitos-de-matricula.php --csv BASE [BASE…]
```

## 4. LA PREGUNTA QUE DECIDE SI HAY COLUMNA NUEVA, Y QUE SÓLO CONTESTA `lal`

**¿El número de estación ES `requisitos_matricula.orden`, o son dos cosas distintas?**

No es una duda de estilo: decide si esto es **una migración o ninguna**.

- Si son la misma, `orden` ya lo guarda y **no hace falta columna**.
- Si son dos —el orden en que se configuran y el número impreso en la cartulina—, hace falta
  `estacion_nro`.

**El relato admite las dos lecturas**: *«ve que el **requisito 2** no está marcado y esta es la
**estación 4**»* puede ser lenguaje suelto, o puede ser exactamente lo que dice — que el requisito
2 se atiende en la estación 3, y entonces son numeraciones distintas.

Y lo que lo contesta no es discutirlo: es **mirar qué tiene `lal` en `orden`**. Si están todos en
cero, `orden` nunca se usó como recorrido y el número de estación es un concepto nuevo; si están
numerados y coinciden con lo que el colegio imprime, no hace falta nada.

*Una columna que se añade sin saber esto es `profesores.tono` otra vez: la escribe la migración y
no la lee nadie, o la lee la pantalla y no la escribe nadie.*

## 5. Lo que sigue abierto, y quién lo contesta

| | quién |
|---|---|
| ¿`orden` es el número de estación, o son dos cosas? | lo contesta **el dato de `lal`** (§4) |
| ¿«falta» significa que la familia no entregó, o que nadie lo marcó? | **Joseth** — desde la base son indistinguibles, y decide si la fase 1 arregla un problema de datos o uno de proceso |
| ¿Quién cierra cada estación: un rol, o cualquiera del personal? | **el dato de `lal`** primero: si `editable_por_profe_id` está siempre en NULL, `rol_id` no ensancha nada, **inventa** un concepto — y eso se decide, no se deduce |

## 6. Lo que NO entra en la fase 1, para que nadie lo suponga

- **Nada de portal público, aspirantes, pagos ni firma.** Son las fases 2 a 4.
- **Nada de `matriculas`**: la fase 1 lee el embudo, no lo escribe. Esa tabla tiene **ocho
  escritores** en `app/` y ninguno sería éste — la misma razón por la que el informe de campaña del
  formulario cuenta con un `JOIN` vivo y no con una columna propia ([41 §9](41-el-formulario-de-inscripcion.md)).
- **El aviso al celular del acudiente** de la pantalla 09. Depende de push, que existe, pero es una
  decisión aparte y no es lo que hace útil el día de matrículas: lo que lo hace útil es que **la
  pantalla diga «devuélvala a la estación 3» antes de que el profesor abra la boca**.
