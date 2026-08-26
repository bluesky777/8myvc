# CERT-2 — el permiso y el rastro del consecutivo

**26 ago 2026** · Cierra lo que [CERT-1](../noche-2026-08-25/cert-1.md) dejó escrito
como «un sí o un no» y lo que la [05 §231](../05-codigo-muerto-y-roto.md) dejó medido
y sin dueño. Es el **punto 1 de la lista de la mañana del 25** y **ya no queda nada
suyo esperando**, salvo lo que Joseth apartó a propósito y está en el §5.

---

## 0. Lo primero, porque la lista estaba vieja y eso decide qué se hace

`ESTADO-ACTUAL.md` enumeraba los puntos **1** y **2** —la carrera del consecutivo y
los dos `cambiar-contador-*` sin validación— como si estuvieran enteros por hacer.
**No lo estaban**: la carrera, la validación y la quema laxa entraron la noche del
25 en `CERT-1`, y sus tests están en verde dentro de la suite desde entonces.

> **La lista era de la mañana del 25 y el trabajo es de esa misma noche**, así que no
> es una cifra que envejeciera: es una lista que nunca se releyó después de que su
> propio punto se hiciera. **Lo barato aquí es abrir el test antes que el documento**
> — `ConsecutivoDeCertificadosTest` decía en su primera línea que el arreglo ya había
> entrado, y decirlo era su docblock, no un comentario suelto.

Lo que de verdad faltaba eran **dos cosas**, y las dos eran decisión de Joseth:

| | qué | dónde estaba escrita |
|---|---|---|
| **el permiso** | los dos endpoints llevaban sólo `auth.personal`, o sea los 51 docentes | [CERT-1 §5](../noche-2026-08-25/cert-1.md) |
| **el rastro** | mover el contador **no dejaba nada escrito en ninguna parte** | [05 §231](../05-codigo-muerto-y-roto.md) |

---

## 1. Lo que Joseth contestó, el 26 ago por la mañana

| pregunta | respuesta |
|---|---|
| ¿Quién puede fijar el consecutivo? | **validar el entero + `esAdministrativo`** |
| ¿Dónde va la cura de la quema laxa? | **backend estricto + avisar al front** |
| ¿Y el registro de certificados emitidos? | **bitácora en los contadores, ya** — la tabla de emitidos, no por ahora |

La segunda venía **medio hecha sin saberlo**: el `FILTER_VALIDATE_BOOLEAN` del
backend es de CERT-1. De esa respuesta lo que queda vivo es **la otra mitad, avisar
al front**, y está en el §6.

---

## 2. El permiso: una línea, y **por qué va en el método y no en la ruta**

```php
Autoriza::exigir(Autoriza::esAdministrativo(User::fromToken()),
    'Fijar el consecutivo del colegio es de secretaria.');
```

Primera línea de `consecutivoValidado()`, en
`app/Http/Controllers/Informes/BolfinalesController.php`.

**Va ahí y no en `routes/api/academico.php` porque cubre los dos endpoints a la
vez.** Los dos pasan por ese método, así que no se puede arreglar uno y olvidarse
del otro — que es **exactamente** cómo `cambiar-contador-folios` se quedó sin
nombrar hasta la §225: la lista de la mañana habla sólo del de certificados.

No hay guard nuevo ni permiso nuevo. `Autoriza::esAdministrativo()` —superusuario o
rol `Secretario`— ya es literalmente *«la secretaria administra la estructura del
colegio»*, y **no se aprovechó para mover ninguna otra de sus llamadas**: crear un
rol no regala permisos.

### A quién se le nota, medido y con la población delante

Se buscó `cambiar-contador` en los **siete árboles de cliente** que hay en
`~/DESARROLLOS` —`myvc_front` (1.745 ficheros), `myvc_front_2` (468),
`myvc_flutter` (1.368), `myvc_dist` (4), `tardanzasMyvc-old` (80), `landingLAL` (8)
y `arc` (99)—, no sólo en el que se esperaba:

| endpoint | quién lo llama de verdad |
|---|---|
| `cambiar-contador-certificados` | **dos pantallas, las dos de `myvc_front`**: la vieja (`certificadoEstudioDir.html`, un `<input>` con `ng-change`) y `app2` (`certificados-estudio.ts:207`) |
| `cambiar-contador-folios` | **nadie vivo** |

**Las dos pantallas enseñan el control sin mirar el rol**, así que un docente que
hoy escriba en esa casilla verá «Contador no guardado» en lugar de escribir. Eso es
la decisión funcionando, no un efecto colateral —Joseth la tomó con esa frase
delante— pero **es un cambio de quién puede llamar una ruta y el front tiene que
enterarse**: §6.

> **Y el hallazgo del `folios`, que estrecha el riesgo a la mitad:** el «Folio» de la
> pantalla vieja **no** llama a `cambiar-contador-folios`. Escribe
> `alumnos/guardar-valor` sobre `nro_folio`, que es **otra columna y otra tabla**. La
> única referencia viva al endpoint es el envoltorio de `BolfinalesApi.ts`, que lo
> admite como parámetro y no lo usa nadie, y una llamada en un `.spec`. O sea que
> **de los dos endpoints restringidos, uno no le quita la pantalla a nadie porque no
> tiene pantalla.**

---

## 3. El rastro: dos sitios, y **no son el mismo suceso**

La [§231](../05-codigo-muerto-y-roto.md) fue a restar *contador − certificados
emitidos* y **no encontró minuendo**: ninguna tabla guarda un certificado emitido.
De ahí salían tres cosas, y ésta cierra **la primera mitad de las tres**:

1. un número quemado por abrir la pantalla era **indistinguible** de uno emitido;
2. dos certificados con el mismo número **no se detectaban después**;
3. *«¿cuántos emitimos este año y a quién?»* **no tenía respuesta ni con acceso
   total a la base**.

Se instrumentan **los dos sitios que mueven el contador**, y van con resúmenes
distintos a propósito — *«lo quemó abriendo»* y *«lo fijó a mano»* no son lo mismo y
una pantalla que los mezcle no sirve para nada:

| sitio | acción | resumen |
|---|---|---|
| `detailed-notas-year-group` (abrir el certificado) | `editar year_config` | `Quemo un consecutivo de certificado: 143 -> 144` |
| `cambiar-contador-certificados` / `-folios` | `editar year_config` | `Fijo a mano el contador de certificados: 143 -> 815` |

Van por `App\Services\Auditoria` y no por `bitacoras` porque es **el único escritor**
del rastro nuevo ([18 §4](../18-auditoria.md)) y porque los cinco campos que aquí
importan —quién, cuándo, desde dónde, qué ruta y de qué valor a cuál— los resuelve él.
`year_config` **ya estaba** en `Auditoria::ENTIDADES`: no hace falta tocar el
vocabulario.

### Tres decisiones pequeñas que van escritas porque no se ven en el diff

- **La línea de la quema va DENTRO de la transacción del incremento.** `Auditoria` no
  abre ninguna suya, así que entra en la que ya hay. Si el incremento se deshace, el
  rastro se deshace con él: **un rastro de algo que no ocurrió es peor que ninguno.**
- **`valor_anterior` va crudo y `valor_nuevo` va como entero** en la quema, que es
  exactamente lo que pasa: la columna es `VARCHAR` y el `(int)` **pierde el relleno de
  ceros** (`'007'` → `8`). Es la [§6.1 de CERT-1](../noche-2026-08-25/cert-1.md), que
  sigue sin tocarse porque es formato del papel y no un fallo. **El rastro lo deja ver
  en vez de taparlo** escribiendo los dos iguales.
- **Sin year `actual=1` no se anota nada**, y es correcto: el `UPDATE` tampoco
  escribió ninguna fila.

### El límite, que va pegado al número

Esto **no** contesta *«¿cuántos emitimos y a quién?»*. Para eso hace falta la tabla de
emitidos, que Joseth apartó (§5). Lo que hace es **separar las dos mitades de la
pregunta**: desde hoy la mano se ve y la quema se ve; a quién se le entregó el papel,
sigue sin verse. Y **lo de antes no se puede reconstruir**: el rastro empieza el día
que se despliegue, no antes.

---

## 4. Los controles: **la red se probó al revés, los tres**

Un test verde no dice nada hasta que se ha visto ponerse rojo. Se revirtió cada
mitad por separado, sobre el árbol de verdad:

| control | qué se quitó | qué cayó |
|---|---|---|
| **1** | la línea de `Autoriza::exigir` | `test_un_docente_corriente_ya_no_fija_el_consecutivo` → *«contestó 200 a alguien del personal que no es administrativo»*. **El del Secretario siguió verde**, que es lo correcto: es la mitad positiva |
| **2** | `anotarElConsecutivo()` en los dos endpoints | `test_fijar_el_consecutivo_a_mano_deja_rastro` |
| **3** | la anotación de la quema | `test_quemar_un_folio_deja_rastro` → *«dejó 0 líneas»* |

El control 1 es el que importaba: **`abort(403)` a secas también habría pasado el
test del docente**, y habría cerrado la pantalla a secretaría, que es justo a quien
se le abre. Por eso el positivo y el negativo son **la misma persona** —el mismo
`Usuario` sin `is_superuser`— y lo único que cambia entre el 403 y el 200 es la fila
de `role_user`.

`ConsecutivoDeCertificadosTest`: **11 tests**, los cuatro nuevos incluidos.

---

## 5. Lo que NO entra, con su motivo

1. **La tabla de certificados emitidos.** Joseth eligió la bitácora y apartó la tabla.
   Es una migración en quince producciones **y tiene una pregunta dentro que no se ha
   contestado**: qué se hace con el histórico, que no existe y **no se puede
   reconstruir** (§3). Sigue siendo el candidato natural de **AUD-4**.
2. **El relleno de ceros** ([CERT-1 §6.1](../noche-2026-08-25/cert-1.md)). Formato del
   número impreso, no corrección de un fallo. Una línea (`str_pad`) y una decisión de
   colegio.
3. **Bajar el guard de la ruta.** El `auth.personal` de
   `routes/api/academico.php:158-159` **se queda**: la comprobación de secretaría vive
   en el método porque cubre los dos, y quitar el guard de la ruta no haría más
   estricta ninguna de las dos cosas. Los snapshots de rutas y guards **no se mueven**.

---

## 6. Lo que hay que decirle al front, y **no se entera solo**

Es la mitad que queda de la segunda respuesta de Joseth, y las dos son de las de
*«quién puede llamarla»*:

1. **`PUT bolfinales/cambiar-contador-certificados` y `-folios` pasan a exigir
   `esAdministrativo`** (superusuario o rol `Secretario`) y contestan **403** al resto
   del personal. Las dos pantallas que lo llaman **enseñan el control sin mirar el
   rol**: `certificadoEstudioDir.html` (el `<input>` con `ng-change`) y
   `certificados-estudio.ts` de `app2`. Lo que toca allí es **esconder el control**
   cuando no sea administrativo, no cambiar la llamada — el 403 ya está bien tratado
   por los dos (`toastr.error` y `avisos.open`).
2. **La cura de la quema sigue siendo suya**, y no la sustituye el backend:
   `aumentar_contador` hay que **OMITIRLO**, no mandar `false`. La cadena `"false"` ya
   no quema desde CERT-1, pero **las copias de `myvc_front` desplegadas en los quince
   colegios van a versiones distintas** y esta medición no las ve.

**Y va cuando se despliegue, no cuando se funda**: `app/` es copia por colegio.

> **Los dos llevan estado en [DESPLIEGUE.md](../../DESPLIEGUE.md), y el paso 3 de ese
> procedimiento los cierra en el mismo commit que anota la tanda.** No es ceremonia: el
> bloque equivalente de la tanda anterior seguía diciendo «esperando el despliegue»
> **después** de desplegar, y la sesión de `myvc_flutter` planificó sobre eso una vuelta
> entera —fusionar una rama que no existía y escribir un endpoint que llevaba tres días en
> los quince—. **Un pendiente escrito en futuro no envejece a «hecho»: envejece a mentira.**
> Lo midió `8myvc-43` el 26 ago (`f5f6235`).

> **Escrito ya donde lo van a leer, el 26 ago por la tarde y a petición de Joseth:**
> `~/DESARROLLOS/myvc_front/TAREAS-AUDITORIA-CERTIFICADOS.md`. Lleva los dos avisos de
> arriba, **las cinco lecturas de auditoría que ya piden `can_view_auditoria` y que están
> DESPLEGADAS desde el 25** —eso no era un aviso de futuro y nadie se lo había dicho—, lo
> que hay que diseñar de la pantalla de la fase 5, y **el hallazgo de las N copias con el
> mismo número** (§7 de aquí abajo). Con los ganchos exactos de cada app, no con el
> nombre del endpoint a secas.


---

## 7. Y lo que salió al ir a diseñar la tabla de emitidos: **un número, N papeles**

Joseth reabrió la tabla de certificados emitidos (§5.1) al terminar esto, y lo primero que
había que medir era **qué es «un certificado emitido»**. La respuesta cambia el diseño
entero, y no la sabía nadie:

- El backend incrementa el consecutivo **una vez por petición**, dentro de la transacción
  con `FOR UPDATE` de la §2 de [cert-1](../noche-2026-08-25/cert-1.md).
- La respuesta es la tupla `[grupo, year, alumnos, escalas]`: **un solo `year`** y **N
  alumnos**.
- `$year` se lee **después** del incremento (`BolfinalesController:263`), así que trae el
  número **nuevo**.
- Las dos plantillas del front repiten sobre los alumnos y le pasan **el mismo `year`** a
  cada uno —`certificadosEstudio.html:1` (`ng-repeat`) y `certificados-estudio.html:25`
  (`@for`)— y el papel imprime `No {{ year.contador_certificados }}`.

> **Abrir el certificado de periodo de un grupo de 37 quema UN número e imprime 37
> papeles, los 37 con el mismo número encima.**

**Eso no se arregla en una línea y no es nuestro para decidirlo:** *¿el consecutivo numera
el papel o numera la tanda?* Si numera el papel hacen falta N números por apertura y el
backend cambia; si numera la tanda, el papel no debería decir *«No 144»* como si fuera
suyo. **Lo que decide es qué escribe secretaría en el libro**, y eso lo contesta el
colegio.

**Y por eso la tabla de emitidos no se puede diseñar todavía**: no se sabe si su clave es
un papel o una apertura. Es una pregunta que hay que contestar **antes** de una migración
en quince producciones, no dentro de ella. Queda en el front
(`TAREAS-AUDITORIA-CERTIFICADOS.md` §4) y aquí.
