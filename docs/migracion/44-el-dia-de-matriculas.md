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
| **El número impreso en la cartulina** | **es `requisitos_matricula.orden`, que ya existe.** El paso 3 se atiende en la estación 3 — así que **no hace falta `estacion_nro`** |
| **Qué significa «falta» hoy** | **las dos cosas, según la estación**: en unas la familia no entregó, en otras nadie lo marcó |
| **Quién cierra un paso** | **cualquiera del personal, pero queda con su nombre y su hora** — no hace falta `rol_id` |

### La respuesta que parecía la más floja es la que hace esto desplegable

*«Las dos cosas, según la estación»* no es una respuesta tibia: es **la que convierte `bloquea` de
lujo en necesidad**. Significa que **el bloqueo no se puede encender de golpe**.

Donde el dato es fiable el colegio enciende el interruptor y la estación frena de verdad; donde
nadie marca lo deja apagado, y el paso **sale igualmente en la lista de pendientes, informando**.
Un bloqueo global —o un interruptor de colegio— habría mandado de vuelta a familias que sí
entregaron, **el primer día y en la cola**.

Por eso la columna nace en **0**: un colegio que actualiza no puede encontrarse el lunes con un
recorrido que frena donde antes no frenaba.

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

## 3. Lo medido — y por qué la medición sigue haciendo falta AUNQUE ya no bloquee

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

> **Ya no bloquea, porque Joseth contestó a mano las tres preguntas que dependían de ella** (§2).
> Pero sigue haciendo falta, y para algo distinto de lo que se pensó: **saber qué encuentra un
> colegio el día que despliegue esto**.
>
> `bloquea` nace en 0, así que nadie se rompe — pero si `lal` tiene doce pasos con `orden` en cero,
> su recorrido sale **sin numerar** y la pantalla de configuración tiene un trabajo de media hora
> antes de que el módulo sirva. Eso no cambia el código: **cambia lo que hay que decirle al colegio
> antes de encenderlo**.

## 4. ~~La pregunta que decidía si había columna nueva~~ — CONTESTADA

**¿El número de estación ES `requisitos_matricula.orden`, o son dos cosas?** Se le puso delante con
las dos lecturas del relato —*«ve que el **requisito 2** no está marcado y esta es la **estación
4**»*— y **contestó que es el mismo número**. Así que `orden` ya lo guarda y **no hay
`estacion_nro`**.

*Una columna que se añade sin saber esto es `profesores.tono` otra vez: la escribe la migración y
no la lee nadie, o la lee la pantalla y no la escribe nadie.*

## 5. ENTREGADO el 20 sep 2026

    GET requisitos/recorrido/{alumno_id}      auth.personal      NO escribe

**Router 621**, contado con `route:list --json` en el árbol principal sobre `main` y después de
fundir (`939ec20`), coincidiendo con las 621 del worktree. **`php artisan test` (las tres
testsuites): 2.475 passed, 1 skipped**, sobre `1b2368d`. Migración `2026_09_20_300000`, tres columnas:

```
requisitos_matricula.bloquea       obligatoria antes de continuar (1) u opcional (0)
requisitos_alumno.cerrado_por      quién lo chuleó
requisitos_alumno.cerrado_at       cuándo
```

### `cerrado_por` no es `updated_by`, y por eso son dos

`updated_by` cambia cada vez que alguien toca la fila —una observación, una tilde, desmarcar— así
que al final del día dice **quién pasó por aquí el último**. Lo que la trazabilidad del día
necesita es **quién lo cerró**. Se escribe con `COALESCE`, así que corregir una observación
después no reescribe la firma.

### UN FALLO VISTO ANTES DE COMETERLO: `users.profesor_id` ESTÁ VACÍA

El `JOIN` natural para sacar el nombre de quien cerró es `profesores p ON p.id=u.profesor_id`. Las
dos columnas existen, y medido en la base de tests:

```
users con profesor_id       0
profesores con user_id     47
```

Escrito al revés, el renglón «cerrado por» **habría salido en blanco en los diecisiete sin que
nada fallara**. Va por `p.user_id=u.id`, comprobado devolviendo nombres.

### Y lo que la pantalla vieja no puede romper

`putUpdate` escribe `orden` y `bloquea` **sólo si vienen**. Esa pantalla está desplegada en los
dieciséis colegios y manda `requisito` y `descripcion` y nada más: escritos incondicionalmente,
**corregir una tilde en el nombre de un paso desharía el recorrido del día**, sin error y sin que
nadie lo note hasta la cola.

Es el mismo caso que el `valor` del formulario de inscripción ([41 §5.ter](41-el-formulario-de-inscripcion.md)),
donde la pantalla vieja *«no revienta: apaga el cobro sin querer»*. **Allí se avisó; aquí se
impide**, y lo fija un test.

### Ninguna instantánea se movió, y lo que eso significa NO es lo que parece

`RequisitosController::putIndex` hace `SELECT *`, así que una columna nueva se reparte sola a la
respuesta. Medido con `tools/lo-que-reparte-una-columna.py` sobre las 129 instantáneas: **0 para
`requisitos_matricula` y 0 para `requisitos_alumno`**.

**Eso no quiere decir «no hay riesgo»: quiere decir que esas rutas no tenían NINGUNA instantánea de
contrato.** El riesgo no estaba tapado — estaba sin medir. Por eso la fase 1 trae los suyos: diez
tests, con **control visto en rojo cinco veces**.

| lo que se rompió | qué cayó |
|---|---|
| todo bloquea (se ignora el interruptor) | el test que lo nombra, y sólo ése |
| `INNER JOIN` en vez de `LEFT` | tres — es el recorrido de quien acaba de llegar |
| devolver al último que bloquea, no al primero | el que lo nombra |
| pisar `cerrado_por` en cada escritura | el que lo nombra |
| `putUpdate` escribiendo siempre | el que lo nombra |

## 6. Lo que NO entra en la fase 1, para que nadie lo suponga

- **Nada de portal público, aspirantes, pagos ni firma.** Son las fases 2 a 4.
- **Nada de `matriculas`**: la fase 1 lee el embudo, no lo escribe. Esa tabla tiene **ocho
  escritores** en `app/` y ninguno sería éste — la misma razón por la que el informe de campaña del
  formulario cuenta con un `JOIN` vivo y no con una columna propia ([41 §9](41-el-formulario-de-inscripcion.md)).
- **El aviso al celular del acudiente** de la pantalla 09. Depende de push, que existe, pero es una
  decisión aparte y no es lo que hace útil el día de matrículas: lo que lo hace útil es que **la
  pantalla diga «devuélvala a la estación 3» antes de que el profesor abra la boca**.

## 7. Lo que sigue abierto

| | quién |
|---|---|
| Correr `tools/requisitos-de-matricula.php` en `lal` | **Joseth** — ya no bloquea el código, pero dice qué encuentra un colegio al desplegar (§3) |
| Las pantallas: el editor de pasos y la de estación | **`myvc_front`** — el backend está, y sin ellas no lo usa nadie |
| El aviso al celular del acudiente (pantalla 09) | decisión aparte: push existe, pero **no es lo que hace útil el día** |
| Las fases 2, 3 y 4 | portal de la familia, venta por los dos canales, firma y pago |

## 8. Y una cosa que este módulo NO resuelve, dicha para que no se prometa

**El recorrido dice qué falta; no dice si lo que falta se entregó.** Mientras «falta» siga
significando dos cosas según la estación —que es lo que Joseth contestó—, el módulo **traslada** el
problema a una decisión del colegio: encender `bloquea` sólo donde marcan de verdad.

Lo que lo arreglaría de raíz no es una columna: es que marcar sea **más fácil que no marcar** en la
estación. Eso es de la pantalla, no de aquí.
