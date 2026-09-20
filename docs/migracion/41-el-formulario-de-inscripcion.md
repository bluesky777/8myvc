# El formulario de inscripción impreso, y el código que lo sigue

Pedido por Joseth el **19 sep 2026**: un informe para **imprimir el formulario de
inscripción**, con un código generado que *«se queda con ese alumno al que inscriban para
matricular»*, imprimible en tanda, **sin grupo cuando el alumno es nuevo y con los datos
puestos cuando es antiguo**.

La pantalla la construyó `myvc-front-bf` en `myvc_front` (commits `04e8f003`, `6c60431d`,
`43fab911`). **Las diez rutas autorizadas están escritas y probadas** (§7). El análisis largo del
embudo de admisiones vive en
[`myvc_front/INVESTIGACION-MATRICULAS.md`](../../../myvc_front/INVESTIGACION-MATRICULAS.md);
esto es sólo el contrato y sus porqués.

---

## 1. Lo medido (19 sep 2026, copia de desarrollo — UN colegio)

- **El embudo ya existe y se usa.** Año 2026: `FORM` 5, `PREM` 12, `PREA` 3, `MATR` 14,
  `ASIS` 3. `FORM` significa literalmente *«la familia se llevó el formulario»*, o sea que
  **el colegio ya cuenta formularios entregados**; lo que no tiene es nada que ate ese papel
  al alumno que vuelve.
- **`years.prematr_nuevos` y `years.prematr_antiguos` son dos interruptores distintos**, uno
  por flujo. La pantalla respeta los dos: el modo apagado no se ofrece.
- **`matriculas.nro_folio` NO es el sitio del código.** Son **1.720** filas con formatos
  incompatibles entre sí (`2018-55`, `170`, `174`), tecleados a mano durante años. Es el único
  registro histórico que el colegio tiene, y pisarlo lo destruye.
- **El formulario en papel del colegio no está en ningún repo**: ni PDF, ni Word, ni plantilla
  blade, buscado en los cuatro. La prematrícula pública tampoco sirve de fuente — sólo recibe
  `grupo_actual`, `grado_ant_id` y `year_ant`, ningún dato de persona. **Por eso la lista de
  campos sale de `AlumnoNuevo` y de la pantalla de alta, que son los campos que el colegio
  guarda de verdad, y por eso el papel del colegio manda sobre ella el día que aparezca.**

## 2. El contrato  *(las dos primeras, ENTREGADAS el 19 sep 2026)*

```
POST informes/formularios-inscripcion        (auth.personal)   ACUÑA
  nuevos   -> { modo:"nuevos",   cantidad:N, grado_id?, cierra? }
  antiguos -> { modo:"antiguos", grupo_id:<del año ACTUAL>, cierra? }   get-or-create
GET  informes/formularios-inscripcion/{lote} (auth.personal)   NO acuña

respuesta: { lote_id, year, cierra, colegio:{…membrete…},
             formularios:[ { codigo, alumno?:{…textos resueltos, no ids…} } ] }
```

Código: `2027-4K7M2` — año + 5 caracteres de un alfabeto sin `O/0`, `I/1/L`, `S/5`, más un
carácter de control. **Aleatorio dentro del año, no secuencial**: si son 171, 172, 173,
cualquiera se imprime su propio 174.

### Lo que cambió al construirlas, y por qué se dice

Tres cosas se movieron entre el contrato y el código. Ninguna la pidió nadie: las tres salieron
de escribirlo.

1. **`year_campana` es una columna, no `year_id + 1`.** La fila de `years` del año que viene
   **puede no existir todavía** —el colegio abre la campaña antes de crear el año, el mismo hecho
   que ya nos obligó a elegir el grupo del año actual— y además **la campaña no siempre es la del
   año siguiente**: un aspirante que entra a mitad de curso (`ASIS`) se inscribe al año en curso, y
   un `+1` le imprimiría 2027. Así que aquí hay **tres años distintos**: `year_id` la fila desde la
   que se imprimió, `year_campana` el año al que se inscribe, y `grupo_id` el grupo actual desde el
   que se eligió.

2. **El `UNIQUE` se mueve a `(year_campana, alumno_id)`.** Con `year_id`, imprimir en diciembre de
   2026 y otra vez en enero de 2027 para la MISMA campaña daba dos códigos al mismo alumno — justo
   lo que el índice existe para impedir. Remedido: tres filas con `alumno_id` NULL pasan, el segundo
   del mismo alumno en la misma campaña choca, y el mismo alumno en **otra** campaña pasa. La
   tercera es nueva; el índice viejo no la distinguía.

3. **En `antiguos` el `lote_id` es determinista** —`antiguos-<campaña>-g<grupo>`— y no un UUID. «Las
   renovaciones de 5°A para 2027» son UNA cosa aunque se impriman cinco veces. Con un aleatorio por
   llamada la reimpresión reusaría los códigos —eso lo garantiza el `UNIQUE`— pero **dejaría el lote
   anterior colgado**: la fila apunta a uno solo, así que el primer `lote_id` que se le dio a la
   pantalla dejaría de resolver. Es más de lo que pidió el front y sale gratis.

### Y un candado del repositorio cazó un cuarto

El método se llamaba `postIndex`, lo que lo metía en la cohorte de `@postIndex` —donde viven tres
rutas públicas por diseño— y `AutorizacionTest` lo delató como *«una ruta sola entre sus
hermanas»*. **El arreglo no fue añadirlo a ninguna lista de excepciones: fue llamarlo
`postAcunar`, que es lo que hace.** Un candado de consistencia diciendo la verdad sobre un nombre.

## 3. Las cinco decisiones, con su porqué

1. **El código lo acuña la API, nunca el front.** Dos secretarías imprimiendo a la vez
   producirían el mismo código, y un código que no está guardado no puede «quedarse con el
   alumno», que es el requisito.
2. **`lote_id` y un `GET` que relee.** Sin él, **recargar la pantalla vuelve a acuñar**: una
   impresora atascada cuesta diez códigos. «No recargues» no es un mecanismo. Es la razón de
   que las rutas sean **dos y no una**.
3. **`antiguos` es get-or-create, no acuñar.** El requisito es «un código por alumno y año»;
   si reimprimir 5°A acuña otros, el código deja de identificar al alumno en cuanto alguien
   imprime dos veces. La llave es **(year_campana, alumno_id)** — ver §2, punto 2: no es el año
   desde el que se imprime, sino el año al que la familia se inscribe.
4. **En `antiguos` se elige el grupo del año ACTUAL, no del siguiente.** El front midió que el
   desplegable salía vacío y lo leyó como *«el colegio abre la campaña antes de crear los
   grupos»*. Es cierto, y la causa está un piso más abajo: la prematrícula de hoy
   (`PrematriculasController::putAlumnosGradoAnterior`) necesita el grupo del año nuevo porque
   prematricular es meter a alguien **dentro** de un grupo. **Imprimir la renovación no.** El
   papel pregunta «¿vuelve el año que viene?» y el grupo de destino no tiene por qué existir.
   Con el cambio, el desplegable pasó de **0 a 13 grupos**.
5. **Sin QR en la fase 1.** No hay portal al que llevar, y **un QR que no abre nada es peor que
   ninguno**. Y del lado del back tampoco es gratis: un QR en Laravel es un paquete de
   composer, y `vendor/` está **compartido por symlink**, así que entraría en los dieciséis
   colegios. Lo único que lo justificaría antes del portal es que secretaría **escanee** el
   código al digitarlo en vez de teclearlo — depende de si el colegio tiene lector, y **eso no
   se sabe**.

## 4. Un hueco latente que salió de camino, y que NO se arregló

`GruposController::putConCantidadAlumnos` cuenta con `INNER JOIN matriculas`, así que **un
grupo sin alumnos no vuelve con `cant_alumnos: 0`: desaparece de la respuesta**. Medido:

```
grupos vivos del año actual        13
con >=1 alumno ASIS/MATR            3
con >=1 de cualquier estado         3
```

**No se toca, y el porqué importa más que el hueco:** ese método lleva encima dos decisiones
de Joseth del 31 ago 2026 —alinear los tres contadores en ASIS+MATR quitando PREM, que
descuadraba la portada de `app2` en `lal` por 22 alumnos; y soltar las fechas de periodo de
`>` a `>=` en tres sitios a la vez— y lo ata `tests/Contrato/AlumnosDetrasDelNumeroTest.php`,
que compara la cifra con el listado que la explica. Pasarlo a `LEFT JOIN` metería diez filas
con `0` en **todas** las pantallas que lo consumen, incluido el informe impreso «Cantidad de
alumnos por grupos», **en los dieciséis colegios**, para que a una pantalla nueva le saliera
un número bonito.

La pantalla del formulario no lo necesita: la cifra sale de contar el lote que devuelve la
ruta nueva, y por eso ya no llama a ese endpoint.

**El síntoma que esto produce el día que alguien lo reporte** es *«creé los grupos del año que
viene y no aparecen»*. No se nota a mitad de curso porque entonces todos los grupos tienen
alumnos.

## 5. El comprobante del pago  *(ENTREGADO el 19 sep 2026)*

    POST colillas-inscripcion/{codigo}      PÚBLICA   la manda la familia
    GET  colillas-inscripcion/pendientes    tesorero  su bandeja
    PUT  colillas-inscripcion/{id}/aprobar  tesorero
    PUT  colillas-inscripcion/{id}/rechazar tesorero

**Foto O número de referencia**, idea de Joseth y mejor que las dos que se le propusieron: el
camino de la referencia **no sube ningún fichero**, así que para esa mitad de los casos no hay
almacenamiento, ni URL que se escape, ni nada que un desconocido pueda subir. Que venga **al menos
una de las dos** lo cierra un `CHECK` en la tabla y no un `if` — comprobado contra la base: sólo
fichero pasa, sólo referencia pasa, ni una ni otra **choca**.

**El fichero vive bajo `public/`, decidido por Joseth con el precio delante.** Es lo que hace el
resto de la casa —las fotos de perfil y los documentos del PIAR, que son valoraciones de menores y
más delicadas que un recibo—, así que tratar la colilla aparte habría sido aplicarle una vara más
estricta que a lo ya guardado. El precio, dicho una vez: **la URL es la llave**, no caduca y no se
revoca. Por eso el nombre es **aleatorio y nunca el código**: si el fichero se llamara como el
formulario, saber un código —que la familia dicta por teléfono— daría el recibo.

### La decimotercera pública, y la primera que RECIBE algo

Las doce anteriores entregan datos sin token; ésta **acepta un fichero de un desconocido**. Quien
paga es la familia de un aspirante que todavía no es alumno: no tiene cuenta y no puede tenerla.

Mueve **cinco** sitios y no tres —las tres instantáneas más `AutenticacionTest::SIN_GUARD` y
`RutasPreLoginTest::TOTAL_PUBLICAS` (12 → 13)— y además hay que declararla en
`AutorizacionTest::EXCEPCIONES_DE_FAMILIA`: el candado la delató como *«sola sin el guard de su
familia»*, que es **un positivo verdadero en la forma** porque sus tres hermanas sí llevan guard.
Se declara con el motivo, nunca regenerando y pasando.

Lo que la acota, en orden de lo que de verdad aguanta:

1. **Tres comprobantes por orden y uno solo pendiente.** Es el único tope que **no se reinicia con
   el reloj**: acota el disco y la bandeja del tesorero.
2. El carácter de control del código, comprobado **antes de tocar la base**.
3. `throttle:colilla`, por IP **y** por código a la vez.
4. Lista blanca de tipos **por extensión y por contenido**, sin `svg` ni `html` — servidos desde el
   dominio del colegio **ejecutan JavaScript en ese origen**, y eso es lo único de esta lista que
   sería un agujero y no una molestia.

Para un DDoS de verdad no vale nada de eso: se para en el borde, y es una decisión de hosting, no
de código.

### Y un test que mentía, que es lo que más costó ver

La prueba de «lo que el navegador ejecutaría no entra» daba **200** para un `recibo.jpg` con PHP
dentro. El agujero no estaba en el controlador:

    subida real    getMimeType() = text/x-php     -> se rechaza
    fake del test  getMimeType() = image/jpeg     -> pasa

`UploadedFile::fake()` **declara su tipo a partir de la extensión** en vez de husmear el contenido,
así que el doble mentía sobre sí mismo. En producción el fichero cae. **La reacción natural ante
ese rojo habría sido «arreglar» un controlador que ya estaba bien**; lo que había que arreglar era
el test, construyendo la subida como llega una de verdad.

### `tesorero_id` es un `profesores.id`, no un `users.id`

Joseth decidió «el tesorero, y si no hay, secretaría». El respaldo no es adorno: `years.tesorero_id`
está **en NULL en los cuatro años** del docker y **no lo lee nadie** en toda la API, así que sin él
no podría aprobar nadie. Y la comparación natural —contra `user_id`— **está mal**: medido, el id 5
es la profesora MARYELINE y el usuario 5 es MARYOLY, o sea que el error no daría un 403 ruidoso,
**le daría permiso de aprobar pagos a otra persona**.

## 5.bis El pago en línea  *(ENTREGADO el 19 sep 2026)*

    POST pagos-inscripcion/{codigo}/checkout   PÚBLICA   la abre la familia
    POST pagos-inscripcion/webhook             PÚBLICA   la llama la pasarela

Las dos que faltaban, y las que suben la docena a **quince** públicas (13 → 15). Proveedor
**Wompi**, que es la recomendación medida del doc 40 §2, guardado en una columna `proveedor`
para que el colegio que negocie un convenio bancario no obligue a tocar código.

**Son dos y no una, y tampoco aquí es simetría.** El checkout **no cobra**: prepara y firma lo
que el navegador le va a enseñar a la pasarela. Quien se entera de que el dinero llegó es el
webhook. Con sólo el primero, una familia paga de verdad y **en MYVC no consta nada** — que es
peor que no tener pagos en línea, porque el colegio o cobra dos veces o no deja inscribirse a
quien ya pagó.

### La corrección que cambió el diseño antes de escribirlo

El doc 40 §4 mandaba *«no te creas el webhook: vuelve a preguntarle a la pasarela **con la llave
pública**»*. Al ir a implementarlo se comprobó contra la documentación de Wompi y **la llave era
otra**: `GET /v1/transactions/{id}` va con la **privada** —con la pública Wompi contesta **404 Not
Found**— y lo que recomienda para validar un evento es justo lo que aquel documento descartaba:
**la firma del evento**. El detalle y el porqué del error están en el §4 del 40, corregido.

**Ese 404 es lo que lo convierte de errata en avería**: implementado al pie de la letra, un pago
bueno se habría leído como *«no puedo confirmarlo»*, habría contestado 503 y la pasarela habría
reintentado para siempre. **Ni un pago registrado en los diecisiete**, y el registro señalando que
la transacción no existe.

Lo que cambia no es un nombre, es el precio: reconsultar **exige guardar la llave privada del
colegio**, la única credencial de todo esto que toca dinero. De ahí salen dos cerraduras de
tamaño distinto, y por eso no se les exige lo mismo:

| | filtrado, qué permite | |
|---|---|---|
| `secreto_eventos` | forjar un «pagado» → **un formulario gratis** | **obligatorio** |
| `llave_privada` | tocar **la cuenta de la pasarela del colegio** | **opcional** |

Sin `secreto_eventos` el webhook no admite nada. Con `llave_privada` manda lo que conteste la
pasarela; sin ella, decide la firma. **Y el modo débil no puede ser invisible**, que es como una
seguridad opcional acaba apagada en los diecisiete sin que nadie lo sepa: cada pago guarda en
`verificado_por` cuál de las dos lo admitió.

### Lo que protege al webhook, en el orden en que ocurre

No es un middleware, y **el orden es parte de la defensa**:

1. **La referencia se busca primero.** Una consulta indexada descarta lo que no es nuestro
   **sin calcular nada y sin salir a internet**. Sin este orden, cualquiera podría hacernos
   consultar a la pasarela a su ritmo. Lo fija un test cuyo cliente HTTP revienta si alguien lo
   llama.
2. **La firma del evento**, obligatoria. Una que no cuadra es 401.
3. **La reconsulta**, si hay llave privada, y su respuesta gana siempre sobre el cuerpo.
4. **Sin llave privada, sólo se cree lo que la firma CUBRE.** Wompi deja elegir qué propiedades
   entran en el checksum, y las que se queden fuera viajan sin proteger: un evento **genuino**
   capturado y reenviado con el `status` cambiado seguiría validando, y el estado es justo lo que
   decide si esto se paga. Así que si `transaction.status` no está firmado, el pago queda en
   `ERROR` con el registro diciendo el arreglo —firmarla, o dar la llave privada—. **No es 401**,
   porque el evento es auténtico, **ni 503**, porque reintentar no lo arregla.
5. **El importe se compara.** *Aprobado no basta: tiene que estar aprobado por lo que pedimos.*
   Sin esta línea, una transacción de mil pesos aprobada de verdad —y firmada de verdad— pagaría
   un formulario de treinta mil. (El importe **no** necesita la cautela del punto 4, y conviene
   ver por qué: no se cree el del evento, se compara contra **el nuestro**, que salió de nuestra
   base al abrir el checkout. Manipularlo hace que falle, no que pase.)
6. **La transacción tiene que ser de ESTA referencia.** Sin ella, un evento que apunte a una
   transacción aprobada ajena aprobaría éste.

Y el código de respuesta es **una instrucción, no un diagnóstico**, porque no lo lee ninguna
persona: `200` es *«resuelto, o no es asunto nuestro y no lo será nunca»*, `401` es *«no vienes
de donde dices, no reintentes»* y `503` es *«no lo sé **ahora**, vuelve»*. La diferencia entre el
200 y el 503 es la que decide si un pago de verdad se pierde en silencio: **«no sé» no es «no»**,
así que una pasarela que no contesta deja el pago como estaba en vez de darlo por rechazado.

### EL HUECO QUE ESTO DESTAPÓ — Y QUE JOSETH CERRÓ EL MISMO DÍA

**`ordenes_inscripcion.valor` no lo escribe nadie.** La columna entró en la primera migración con
el comentario *«el código queda atado a un cobro: cuánto, quién lo vendió y cuándo»*, y de las
tres sólo se escriben las dos últimas: el `INSERT` de `postAcunar` no la nombra, y en todo `app/`
no hay otra escritura de esa tabla. La bandeja del tesorero ya la **lee** (`o.valor`), así que hoy
enseña `null` en los diecisiete.

Es `profesores.tono` **otra vez**, y van tres en un mes. Lo que lo destapó no fue un barrido: fue
que **el checkout necesita un importe y no había ninguno**.

No se tapó desde el código, y el porqué importa más que el hueco: las dos salidas fáciles son
peores. Inventarse el importe en el servidor es cobrar una cifra que nadie decidió, y dejar que lo
mande el cliente es **que la familia elija cuánto paga**. Las dos habrían sido decidir por Joseth
el precio de un producto. Así que se le puso delante con las tres formas y su coste, y **eligió un
precio por campaña**.

## 5.ter El precio del formulario  *(ENTREGADO el 19 sep 2026)*

**Sin ruta nueva.** Va en `config_formulario_inscripcion` —la misma fila, la misma clave y la
misma ruta que los campos—, así que el router se queda en **612**:

```
GET  informes/formularios-inscripcion/campos  ->  { year_id, campos:[…], valor: 30000|null }
PUT  informes/formularios-inscripcion/campos  <-  { campos:[…], valor: 30000 }
```

> ⚠️ **ESTO ES UN CAMBIO DE CONTRATO Y EL FRONT NO LO SABE.** `myvc-front-bf` construyó la
> pantalla de configuración antes de que existiera este campo y su sesión ya no está. `valor` es
> **opcional** —un `PUT` sin él borra el precio, que es el comportamiento que hace falta para
> dejar de cobrar sin borrar la configuración— así que **la pantalla vieja no revienta: apaga el
> cobro sin querer**. Es exactamente la clase de cambio que hay que avisar, y por eso va aquí
> arriba y no en una nota.

**La clave es `year_id` y no `year_campana`**, igual que los campos, aunque el precio sea «de la
campaña»: la fila de `years` del año de la campaña **puede no existir todavía**, así que no se le
puede colgar una clave ajena. Se configura desde el año en el que se trabaja, que es el que
siempre existe.

### Y SE ESTAMPA, NO SE REFERENCIA — que es la mitad que lo hace correcto

Al acuñar, el precio se **copia** a `ordenes_inscripcion.valor` y allí se queda. La forma «obvia»
de no duplicar el dato —que la orden mire la configuración— haría que **subir el precio en marzo
cambiara el importe de lo que se vendió en enero**, y el síntoma sería que la bandeja del tesorero
enseña meses después una cifra distinta de la que la familia pagó, sin nada que lo explique.

Dicho al revés: *la columna de la configuración es lo que el colegio cobra a partir de ahora; la
de `ordenes_inscripcion` es lo que cobró.* Son dos cosas, y por eso son dos columnas.

Lo fija `test_subir_el_precio_no_cambia_lo_que_ya_se_acuno`, **visto en rojo** implementando la
versión por referencia: acuñar, subir el precio y comprobar que el formulario viejo conserva el
suyo y el siguiente sale con el nuevo. `FormulariosInscripcionTest`: **25 passed (155
assertions)**.

### El tope, y lo que el tope NO hace

`MAXIMO_VALOR` son diez millones de pesos, validado **en el método y no en la base** porque el
docker trunca en silencio y MariaDB 10.5 aborta: el mismo dato daría dos resultados distintos en
desarrollo y en producción.

Y lo que no hace, dicho para que nadie lo suponga: **no caza una errata de tecleo**. Un cero de
más en 30.000 da 300.000 y pasa por debajo del tope sin despeinarse. Contra eso lo único que sirve
es que la pantalla enseñe el precio guardado, y eso es del front.

### Veintitrés pruebas, de las que cinco se vieron en rojo a propósito

`PagosInscripcionTest`: **23 passed (142 assertions)** (`--filter=PagosInscripcionTest`,
`php artisan test`). Lo que las hace valer algo no es el número:

- **Las dos firmas se comprueban contra la fórmula de Wompi, no contra nuestro método.** Llamar a
  `Wompi::firmaDeIntegridad()` compararía la función consigo misma y pasaría también el día que la
  fórmula estuviera mal. Es el mismo error que el `UploadedFile::fake()` de la colilla, visto
  desde el otro lado: allí el doble mentía sobre sí mismo, aquí sería el test mintiendo sobre el
  código.
- **Control visto, cinco veces**, una por propiedad que de verdad sostiene esto: quitando la
  comparación de importes cae *«un importe distinto no paga el formulario»*; haciendo que gane el
  cuerpo sobre la reconsulta cae *«manda lo que conteste la pasarela»*; saltándose la firma cae
  *«un evento mal firmado no aprueba nada»*; leyendo el cuerpo por `Request::all()` cae *«la firma
  se comprueba sobre el cuerpo crudo»*; y creyéndose un estado sin firmar cae *«en modo firma no
  se cree un estado que la firma no cubre»*.

### El cuerpo se lee CRUDO, y eso es una de las cinco

`TrimStrings` y `ConvertEmptyStringsToNull` son globales a esta API, así que `Request::all()` no
devuelve el cuerpo: devuelve **el cuerpo ya modificado**. Sobre una firma eso es fatal y
silencioso — el hash se calcularía sobre un valor distinto del que firmó la pasarela y **fallarían
todos los pagos**, con el motivo a dos middlewares de distancia del sitio donde se ve. Hoy ninguna
propiedad que Wompi firma tiene espacios, así que las dos formas dan el mismo hash **y por eso
hace falta un test sintético**: es el único sitio donde la diferencia se nota antes de que
importe.

### Y un candado que nadie había avisado: son SEIS sitios, no cinco

El relevo decía que una ruta pública mueve **cinco** sitios. Son cinco **más uno** cuando además
**escribe**: `FamiliasQueNuncaEntranTest` lleva la cuenta de las escrituras que viven en familias
que el candado de familia **no mira nunca**, y pasa de **22 a 24**. La colilla no lo movió porque
sus tres hermanas llevan guard; `pagos-inscripcion` **no tiene ninguna**, así que sus dos entran.

Se acepta con el motivo escrito y **no** metiendo la familia en la lista de exclusiones, que era
el atajo que había a mano: lo que ese candado pregunta es *«¿algún mecanismo comprueba de quién es
la fila que toca?»*, y aquí la respuesta es **sí, pero no es un guard — es que la llave es el
dato**: un código que valida su propio carácter de control, y una referencia de 64 caracteres que
acuñamos nosotros. El día que acepten un `orden_id` suelto en el cuerpo seguirán contando 24 y ya
serán un agujero, **así que el número no es la garantía: el porqué de cada renglón sí.**

## 6. Los interruptores de campaña: el hallazgo, y la decisión de Joseth

`myvc-front-bf` pidió dos rutas nuevas midiendo que **nada escribía `years.prematr_*`**. **La
medición era falsa**, y el porqué vale más que el dato: existe `PUT years/toggle-cambiar-valor`,
que escribe **cualquier** columna de `years` con `auth.personal`. Comprobado de punta a punta:
HTTP 200 y la columna cambia. Un `grep` de `prematr` **no puede encontrar esa ruta, porque no
nombra ninguna columna** — el nombre llega en el cuerpo.

Lo que sí es cierto: `prematr_nuevos` **enciende datos en una ruta pública** —`publicaciones/ultimas`
devuelve los grupos del año siguiente sin token— y hoy lo enciende cualquiera de las cuentas de
personal. **Joseth lo decidió con ese precio delante y eligió el genérico**: sin dueño y sin
exclusión. Otra sesión construyó además las rutas propias (`99060be`), y él resolvió dejar las dos
puertas. Queda escrito porque una decisión tomada con el riesgo a la vista no es lo mismo que un
descuido.

## 7. Lo decidido, y lo que sigue abierto

**Decidido por Joseth el 19 sep 2026** — todo con el precio delante:

| | |
|---|---|
| Las dos rutas del formulario | autorizadas · **entregadas** |
| La lista de campos configurable por colegio | autorizada · **entregada** |
| El formulario **se cobra** | sí |
| El comprobante: colilla **+** pasarela opcional | las dos |
| Quién sube la colilla | **la familia**, ruta pública · **entregada** |
| Quién aprueba | tesorero, y si no hay, secretaría · **entregado** |
| Si no cabe en carta | se avisa de que hay que imprimir en **oficio** |
| Los interruptores de campaña | por el genérico, sin dueño |
| Las dos rutas de la pasarela | autorizadas · **entregadas** (§5.bis) |
| El precio del formulario | **un precio por campaña** · **entregado** (§5.ter) |

**Decidido por Joseth el 20 sep 2026** — el alcance y el permiso, con las poblaciones delante:

| | |
|---|---|
| Atar el papel a un alumno sin reacuñar el código | autorizado · **entregado** (§9) |
| El informe de la campaña, con «compró y no volvió» | autorizado · **entregado** (§9) |
| Cerrar a `MATRICULADA` con su `matricula_id` | autorizado · **entregado**, sin gastar ruta |
| El código lo acuña **siempre** la API | sí — y secretaría puede **corregirlo** después (§9) |
| Quién ata y quién corrige | secretaría o superusuario, **dentro del método** |

**Las diez del 19 sep están dentro, y las cuatro del 20 también.** Router **619**, contado con
`route:list --json` **en el árbol principal, sobre `main` y después de fundir** (`032a1a6`).
Antes de ellas eran **612**, contadas en
el árbol principal sobre `main` después de fundir; el precio **no gastó ruta** —va en la fila y la
ruta que ya tenían los campos— y las 615 intermedias las trajeron otras ramas.

**Abierto** *(y el 20 sep se cerró lo que faltaba del código: §9)*:

- **Si el colegio da o no su llave privada de la pasarela.** Con ella, un webhook se confirma
  preguntándole a Wompi; sin ella, decide la firma del evento. Las dos funcionan y **la diferencia
  es de cuánto se pierde si se filtra un secreto**, no de si cobra (§5.bis). Es una pregunta para
  cada colegio, no una decisión de producto.
- **Los dos avisos que este flujo no manda**, y la respuesta cambió el mismo día que se escribió
  esta línea. Decía *«el canal es WhatsApp»* y **Joseth descartó WhatsApp esa noche** (doc 42
  §revocada): estrechó el alcance al único hueco real y el mapa quedó **matriculado → app y push**
  —que ya funciona, agrupado y gratis— **y aspirante → correo**. Así que:
  - **Al tesorero**, cuando entra una colilla. Es personal del colegio: **tiene cuenta y tiene
    app**, o sea push, que ya existe. Hoy la bandeja hay que abrirla porque nadie ha conectado el
    aviso, no porque falte canal.
  - **A la familia**, cuando su pago se aprueba o se rechaza. Va por correo, **y el correo de
    esta API está medido en rojo desde el 2 sep** (29 §2): `lalvirtual.com` —el
    `MAIL_FROM_ADDRESS` de quince colegios— **no está registrado**. Lo que lo hace peor que caro
    es que **falla callado**: un «pago aprobado» que no llega no se reintenta, porque el
    aspirante no sabe que existía.

  Para este módulo eso tiene una consecuencia concreta y no teórica: **el rechazo de una colilla
  pide un motivo para que la familia sepa qué corregir, y hoy ese motivo no sale de la base.**
- **El tope de altura del formulario configurable**, resuelto en el front avisando de que hay que
  imprimir en oficio. Ojo: el **oficio colombiano no es el `legal` de CSS** — 216×330 contra
  216×356, exactamente una pulgada.
- ¿Secretaría tiene lector de código de barras? Es lo único que devolvería el QR a esta fase.

## 8. Lo que espera una decisión de Joseth

1. ¿Existe el formulario en papel del colegio? Si aparece, **manda sobre la lista de campos**.
2. ¿Se cobra el formulario? (precios de mercado medidos en `INVESTIGACION-MATRICULAS.md`)
3. ¿Secretaría tiene lector de código de barras? Es lo único que devuelve el QR a la fase 1.
4. ~~Las dos rutas.~~ **Contestado el 19 sep 2026: autorizadas y entregadas.**
5. ~~El precio del formulario: quién lo pone y dónde vive.~~ **Contestado el 19 sep 2026 entre
   tres formas y con el coste de cada una delante: un precio por campaña, en la misma fila y la
   misma ruta que los campos, estampado en cada formulario al acuñarlo (§5.ter).**
6. ~~Qué pasa cuando el papel vuelve lleno: quién lo ata al alumno y qué ocurre si ese alumno ya
   tiene formulario.~~ **Contestado el 20 sep 2026: el código no cambia al atarlo, el alumno que
   ya tiene el suyo no gasta otro y el papel del segundo acudiente queda libre (§9).**
7. ~~Si secretaría puede corregir un código.~~ **Contestado el 20 sep 2026: sí, tecleando el
   sufijo —el carácter de control lo pone la API— y con el código viejo guardado para que el papel
   que circula no quede huérfano (§9).**

---

## 9. DEL PAPEL AL ALUMNO — las cuatro que cierran el ciclo  *(20 sep 2026)*

**Autorizado por Joseth el 20 sep 2026** con el alcance y el permiso delante, en respuesta a
*«terminemos lo del formulario de inscripción… lo del código único que no cambia cuando se le
asigna a un alumno ni se repite cuando ya le dimos el formulario a un nuevo acudiente que vino
por él»*.

    GET  informes/formularios-inscripcion/campana                 auth.personal
    GET  informes/formularios-inscripcion/codigo/{codigo}         auth.personal
    PUT  informes/formularios-inscripcion/codigo/{codigo}         auth.personal + puedeAtarFormularios
    PUT  informes/formularios-inscripcion/codigo/{codigo}/alumno  auth.personal + puedeAtarFormularios

**Router 619**, contado con `route:list --json` **en el árbol principal, sobre `main` y después de
fundir** (`032a1a6`) — y coincidió con las 619 contadas antes en `.worktrees/fi`, que es la única
forma de saber que coincidía.

### EL HUECO, MEDIDO ANTES DE ESCRIBIR NADA — Y ES EL CASO PRINCIPAL

Las diez rutas del 19 sep **acuñan, imprimen y cobran**, y ahí se acababa. Medido con un `grep`
de los `UPDATE` de esa tabla en todo `app/`:

```
UPDATE ordenes_inscripcion  ->  ColillasInscripcionController:227   SET estado="PAGADA"
                                PagosInscripcionController:325      SET estado="PAGADA"
```

Dos, y las dos escriben lo mismo. O sea:

- **`alumno_id` sólo se escribe al acuñar una renovación.** Un formulario del modo `nuevos`
  —**el del aspirante, que es el caso principal**— nace con `alumno_id` NULL y **no se ata a
  nadie jamás**. El requisito literal de Joseth del 19 sep, *«el código se queda con ese alumno
  al que inscriban para matricular»*, **no ocurría** en el flujo que lo motivó.
- **`matricula_id` no lo escribía nadie**, aunque su comentario en la migración prometiera lo
  contrario.
- **`estado = 'MATRICULADA'` tampoco**, y `PagosInscripcionController` ya lo nombra en
  `YA_NO_SE_COBRA`.

Es `profesores.tono` **por cuarta vez en un mes**, y otra vez **no lo destapó un barrido**:
`interruptores-que-nadie-lee.py` mira `tinyint(1)` y esto es un `int` y dos `varchar`. Lo
destapó que la función siguiente necesitaba el dato. *Construir encima encuentra huecos que un
detector no busca, porque el detector sólo enumera las formas que alguien ya imaginó.*

### Las tres frases de Joseth, y dónde vive cada una

**1 · «El código no cambia cuando se le asigna a un alumno.»** `putAlumno` hace un `UPDATE` de
`alumno_id`, nunca de `codigo`. Lo fija `test_el_codigo_no_cambia_al_atarlo_a_un_alumno`, que
**compara contra la base y no contra la respuesta**: una respuesta que devuelva el código bueno
mientras la fila guarda otro pasaría igual, y el papel que la familia tiene en la mano dejaría
de encontrar nada.

**2 · «Ni se repite cuando ya le dimos el formulario a un nuevo acudiente que vino por él.»** Si
el alumno ya tiene orden de esa campaña, **no se acuña nada y no se mueve nada**: 409 con el
código que ya tiene dentro del cuerpo, y **el papel en blanco del segundo acudiente queda
libre** para otra familia. El cuerpo importa tanto como el código HTTP — sin él la pantalla sólo
podría decir «ya tiene uno» y la secretaría tendría que ir a buscarlo, que es el trabajo que
este módulo existe para quitar.

> **Y aquí el control visto en rojo enseñó algo que no se sabía: esa propiedad la sostienen DOS
> mecanismos independientes.** Quitando **sólo** la lectura previa de «¿ya tiene el suyo?», los
> 23 tests siguen verdes —el `UNIQUE (year_campana, alumno_id)` la atrapa igual: el `UPDATE`
> choca, el `catch` relee y contesta lo mismo—. Quitando **sólo** el `catch`, también verdes.
> Quitando **los dos**, cae exactamente el test que la nombra y ningún otro.
>
> Eso no es un test flojo: es **un test que comprueba la propiedad y no el camino**. Uno atado a
> la implementación se habría puesto rojo al quitar cualquiera de las dos y habría hecho creer
> que el código estaba roto mientras la base seguía defendiéndolo.

**3 · «Los códigos no los debe inventar la secretaría, eso debe ser automático, aunque no
estaría mal que lo pueda modificar después, asegurando de darle herramientas para que no repita
código.»** `PUT …/codigo/{codigo}` corrige, y **la forma ES la herramienta**:

| | |
|---|---|
| se teclea el **sufijo**, cinco caracteres, no el código | el carácter de control **lo pone la API**, así que por este camino no puede salir uno que no valide: la persona no escribe la parte que podría estar mal |
| sin `sufijo`, se acuña **uno automático** | es el botón de *«deme otro»* cuando el papel se estropeó |
| el alfabeto se valida contra el mismo que el generador | la `O`, el `0`, la `I`, el `1`, la `L`, la `S` y el `5` están fuera **porque una persona los confunde leyendo un papel**; colar una `O` aquí metería en circulación justo el código que este módulo se cuida de no acuñar |
| que no se repita lo garantiza el **`UNIQUE`** | este método lo traduce a **409 diciendo qué pasó**, no a un 500 |

### `codigo_anterior`: la columna que hace que corregir sea seguro

Es lo único nuevo del esquema, y no es comodidad. **El código viejo está impreso en un papel que
está en casa de una familia.** Sin guardarlo, corregir convierte ese papel en basura silenciosa:
quien lo teclee recibe *«no existe»* y **nadie puede saber que existió**. Con ella,
`getPorCodigo` busca por las dos columnas y la respuesta dice **`encontrado_por`**, para que la
pantalla pueda avisar de que ese papel ya no lleva el código bueno.

Guarda **uno solo**, el inmediatamente anterior. Corregir dos veces deja huérfano el primero, y
es una limitación consciente: el caso de uso es *una* corrección, y un historial sería una tabla
que nadie ha pedido. Va dicho porque una limitación que no se escribe se descubre el día que
duele.

**Lo único que no se puede corregir es lo que ya cerró**: `MATRICULADA` es 409. Con pagos **sí**
se deja, y no por descuido — las colillas y los pagos apuntan a `orden_id`, no al código, así
que dentro de la casa no se rompe nada. La respuesta lleva `tenia_pagos` para que la pantalla
pueda avisar antes.

### El informe de campaña, y su única decisión de fondo

`GET …/campana?year_campana=2027` da el resumen, las cinco cuentas por estado y **la lista de
quién compró y no volvió**, con los teléfonos del alumno y de su primer acudiente. Esa lista es
la que el docblock de la migración prometía —*«hoy no existe en ninguna parte y es dinero que el
colegio ya recibió»*— y hasta hoy no salía de ningún sitio: `ordenes_inscripcion` **sólo se podía
leer por lote**.

> **Los matriculados NO se cuentan por `estado`: se cuentan con un `JOIN` vivo contra
> `matriculas`.** El motivo es que `matriculas` tiene **ocho escritores** en todo `app/`
> —`MatriculasController` (6), `LoginController` (3), `PromovidosController`,
> `ImportarController` (4) y `GuardarAlumno`— y **ninguno es de este módulo**. La columna
> `estado` la escribe sólo `putAlumno`, así que va por detrás siempre que alguien matricule por
> cualquiera de los otros siete caminos: contarla daría **una cifra que baja sola** y metería en
> la lista de llamadas a gente que ya está en clase.
>
> **Y por eso no se engancha una escritura nuestra en los ocho**, que era la salida «completa»:
> tocar el camino caliente de los dieciséis colegios para una columna que sólo lee este informe.
> *Una caché con ocho escritores ajenos es `notas_finales` otra vez, que es medio
> [doc 10](10-definitivas.md).* La columna se queda como comodidad; la verdad la dice el `JOIN`.
> Lo fija `test_el_informe_cuenta_los_matriculados_contra_matriculas_y_no_contra_el_estado`,
> **visto en rojo** con la versión que cuenta la columna.

### Y el mismo pecado, cometido por mí dos métodos más abajo

La lista de «compró y no volvió» llevaba una columna llamada **`pagado_at`** que era
`o.updated_at`. **No es la fecha del pago**: es la última modificación de la fila, así que
corregir el código de un formulario ya pagado la mueve y la lista de llamadas diría que pagó hoy.

Es exactamente lo que el apartado siguiente denuncia de `vendida_at`, escrito **el mismo día y en
el mismo fichero**, y no lo cazó ningún test —el nombre de una clave no lo comprueba nadie— sino
releer la consulta. Se llama **`actualizado_at`**, que es lo que es, y la fecha del pago de verdad
sale de `colillas.resuelta_at` y `pagos.verificado_at`, que el `GET` por código ya devuelve.

*Un nombre que miente no falla: pasa la suite, pasa larastan y llega a la pantalla.* Por eso el
test fija ahora las dos cosas —que `actualizado_at` está y que `pagado_at` **no**—, que es lo
único que convierte esta nota en un candado.

### Un hallazgo de nombre en la dirección contraria: `vendida_at` NO es la fecha de venta

`vendida_por` y `vendida_at` se escriben **al acuñar**, o sea al imprimir. **Cincuenta
formularios en blanco no son cincuenta ventas**, así que un informe que sumara sobre esa fecha
contaría como recaudado todo lo que salió de la impresora. Lo que dice que alguien pagó es el
**estado**, y por eso `recaudado` suma sobre `IMPRESA|PAGADA|APROBADA` y no sobre la fecha. Las
columnas no se renombran —están desplegadas— pero el nombre queda desmentido por escrito.

### El permiso va al revés que el de las otras cuatro de esta familia, a propósito

Las cuatro del 19 sep llevan `auth.personal` y **nada dentro**. Éstas se parten en dos, decidido
por Joseth el 20 sep con las poblaciones delante:

- **Las dos lecturas, `auth.personal` a secas.** Mirar el papel que a uno le ponen delante es lo
  que hay que poder hacer en la estación de documentos el día de matrículas, y ahí quien atiende
  es un docente.
- **Las dos escrituras, `Autoriza::puedeAtarFormularios` dentro del método.** `auth.personal`
  deja pasar a las **74** cuentas de personal, de las que **53 son docentes**; atar decide **de
  quién es un cobro** y corregir cambia lo que lleva impreso un papel que está en casa de una
  familia.

**No se reusó `esAdministrativo()` directamente**, aunque hoy devuelva exactamente lo mismo: lo
comparten quince llamadas de dominios que no se parecen a éste, y el día que alguien lo ensanche
—*crear un rol no puede regalar permisos que nadie pidió*, que es la regla escrita en su propio
docblock— esta puerta se ensancharía con él **sin que nadie lo decidiera**.

### Lo que esto NO hace, dicho para que nadie lo suponga

- **No crea el alumno.** `putAlumno` ata a un alumno **que ya existe**; darlo de alta es la
  pantalla de siempre. El día que haya tabla `aspirantes` (§7 de `INVESTIGACION-MATRICULAS.md`)
  esto es donde encaja.
- **No engancha en el flujo de matrícula**, por lo dicho arriba.
- **No manda ningún aviso.** Sigue abierto, y sigue dependiendo del correo (§7).

---

## 10. LA FAMILIA PREGUNTA — la decimosexta pública, y la primera de LECTURA  *(20 sep 2026)*

    GET colillas-inscripcion/{codigo}    PÚBLICA    la llama la familia, sin cuenta

**Router 620**, contado con `route:list --json` en el árbol principal sobre `main` y después de
fundir (`b8b3853`) — y coincidió con las 620 contadas antes en `.worktrees/es`. Autorizada por
Joseth el 20 sep 2026.

### El hueco estaba medido, y era de forma, no de olvido

De las catorce rutas del formulario, **las tres públicas eran las tres de ESCRITURA y ninguna
lectura lo era**:

```
PÚBLICA  POST  colillas-inscripcion/{codigo}          la familia manda el comprobante
PÚBLICA  POST  pagos-inscripcion/{codigo}/checkout    la familia paga en línea
PÚBLICA  POST  pagos-inscripcion/webhook              la pasarela avisa
```

O sea que **la familia mandaba su comprobante y no tenía forma de saber si se lo aprobaron, se lo
rechazaron ni por qué**. El motivo del rechazo ya se guardaba —`putRechazar` lo exige desde el 19
sep, y no por formulismo: sin texto no se puede rechazar— pero **sólo lo veía el personal**.

### No espera al correo, y ése es el punto

El aviso que debía cerrar esto iba por correo, y el correo de esta API **está en rojo desde el 2
sep**: `lalvirtual.com`, el `MAIL_FROM_ADDRESS` de quince colegios, no está registrado, y **falla
callado**.

Así que esto es **_pull_ en vez de _push_**: la familia entra con el código que ya lleva impreso el
papel. *Arreglar el correo sigue haciendo falta; lo que ya no hace falta es esperarlo.*

> ### LA CIFRA QUE JUSTIFICABA ESTO ESTABA MAL DOS VECES, Y LA SEGUNDA ES LA QUE IMPORTA
>
> Aquí decía *«sólo el 9,2 % de los 1.085 acudientes vivos tiene correo»*, del doc 42.
>
> **Primer error, y lo levantó `8myvc-9a` el 20 sep 2026** (corregido por `myvc-front-2e`): ese
> 9,2 % es `acudientes.email` —la **ficha**— y **todo lo que manda correo busca por `users.email`,
> la CUENTA**: `LoginController`, cuatro consultas y las cuatro sobre esa columna. Por ahí eran
> **0 de 1.085**, incluidos los 100 que tenían correo escrito en la ficha. Su arreglo los dejó en
> **91**. Remedido aquí, en la copia de desarrollo:
>
> ```
> acudientes vivos                        1085
> con correo de FICHA  (acudientes.email)  100     <- el 9,2 % que se citaba
> con cuenta viva y activa                1000
> con correo de CUENTA (users.email)        91     <- por aquí busca todo
> ```
>
> **Segundo error, y es el de fondo: ninguna de las dos cifras es la de este módulo.** Las dos
> cuentan **acudientes de alumnos YA MATRICULADOS**. Quien paga un formulario de inscripción es la
> familia de un **aspirante**, que por definición **no tiene fila en `users` ni en `acudientes`**
> — es el motivo entero de que estas rutas sean públicas.
>
> Y medido el 20 sep: **este flujo no le pide el correo en ningún momento, y ninguna de sus tres
> tablas tiene esa columna.** Así que para un aspirante el correo no es un canal malo: **no es un
> canal**, y esta ruta no es «el que funciona mejor», es **el único que existe**.
>
> *Un `SELECT` no habría dicho esto —no hay dónde mirar—, y las dos cifras que circulaban eran
> ciertas sobre una población que no es ésta. Es la regla de que una cuenta sobre la población
> equivocada no falla: contesta, y contesta bien a otra pregunta.*
>
> **Y de aquí sale lo que habría que pedir el día que se quiera avisar de verdad**: un correo **en
> el formulario**, validado mandándolo —que es lo que el §7 ya proponía— porque es el único momento
> en que el aspirante está delante y puede corregir una errata. Hasta entonces, no hay a dónde
> mandar nada.

### Lo que devuelve lo decide que sea PÚBLICA, no que le sirva a la familia

La llave es el código, y el código **se dicta por teléfono y viaja en un papel que pasa de mano en
mano**. Así que la pregunta de cada campo no fue *«¿le sirve?»* sino **«¿qué pasa si esto lo lee
quien se encontró el papel?»**:

| | |
|---|---|
| **sí** | el estado, el valor, la fecha límite, en qué va cada comprobante y **el motivo del rechazo** — que el tesorero escribe *para* la familia |
| **no** | el nombre del alumno, su documento, sus teléfonos, de qué grupo es |
| **no** | el nombre del fichero del recibo: **la URL es la llave** (§5), y saber un código no puede dar el recibo que subió otro |
| **no** | quién lo resolvió, ni desde qué IP se subió |

**Un código no puede revelar el nombre de un menor**, y ésa es la línea: lo que sale describe un
trámite, no a una persona. El motivo de una colilla **aprobada** tampoco viaja — ahí no hay nada
que corregir, y un texto interno del tesorero pegado a un «aprobado» es información que nadie
decidió enseñar.

> **Y por eso el test tiene más aserciones de lo que NO sale que de lo que sale**, y las hace
> **sobre el JSON entero en vez de campo a campo**: lo que hay que impedir es que el dato aparezca,
> esté donde esté. *Un campo de más en una respuesta pública no rompe nada, no pone nada en rojo y
> no se nota hasta que importa.*

También devuelve `puede_enviar_otro` y `comprobantes_restantes`, que son **las dos condiciones que
`postSubir` comprueba de verdad, dichas antes de subir**. Sin eso la familia se entera con un 429
después de elegir la foto, que es el peor momento para enterarse.

### EL AGUJERO QUE ESTO DESTAPÓ, Y QUE ERA DE UNAS HORAS ANTES

Al escribirla salió que **la §9 había dejado medio cerrado su propio invariante**. `putCodigo`
guarda el código retirado en `codigo_anterior` para que el papel viejo no quede huérfano, y
**sólo `getPorCodigo` —la ruta del PERSONAL— aprendió a buscar por él**. Las dos públicas, que son
justo las que usa la familia, seguían con `WHERE codigo=?`:

```
POST colillas-inscripcion/{codigo}          subir el comprobante
POST pagos-inscripcion/{codigo}/checkout    pagar en línea
```

**Corregir un código dejaba a la familia sin poder pagar.** Y lo que lo hacía peor es que era
**silencioso para las dos partes**: secretaría corrige creyendo que es inocuo —nada le dice que
acaba de invalidar un papel que está en una casa— y la familia se estrella contra un 404 que no
puede reportarle a nadie, porque no tiene cuenta. Ni error, ni registro, ni llamada: sólo una
inscripción que no se paga.

**Se arregló con una clase compartida —`App\Services\OrdenDeInscripcion`— y no parcheando las dos
consultas**, que era lo obvio y lo insuficiente: eso habría tapado el agujero de hoy y dejado el de
mañana, porque la siguiente ruta que reciba un código —y este módulo lleva quince— se escribiría
con la consulta obvia, que es la mala. *Un sitio compartido convierte «acordarse» en «no tener que
acordarse».*

Y su `SELECT` nombra las columnas en vez de `*`, a propósito: esta tabla la leen rutas públicas, y
un `*` reparte a la respuesta cualquier columna que la tabla gane mañana **sin que nadie lo
decida**.

### Y LA TRAMPA Nº 3 DEL PROPIO MÓDULO, COMETIDA OTRA VEZ AL DÍA SIGUIENTE

La ruta se registró primero **antes** de `GET colillas-inscripcion/pendientes`, y `{codigo}` es un
comodín: **se tragaba la bandeja del tesorero**, que pasaba a contestar 422 «ese código no es
válido».

Medido, no supuesto — `getRoutes()->match()` sobre `/api/colillas-inscripcion/pendientes` devolvía
**`getEstado`**. Y lo que lo hace difícil de ver es que **`route:list` NO lo enseña**: ordena
alfabéticamente y no por orden de registro, así que la forma natural de comprobarlo miente.

Es exactamente la trampa que este documento ya tenía escrita para `…/campos` antes que `…/{lote}`,
cometida en la familia de al lado y al día siguiente. *Un aviso escrito no protege solo; sólo
protege el día que alguien hace lo que dice.* Ahora lo fija un test que pide
`…/pendientes` **sin token** y exige **401**: si se la tragara `getEstado`, daría 422.

### Los cinco sitios que movió, que son los cinco de la regla

Una ruta pública mueve cinco, y **ésta los mueve los cinco justos** porque **no escribe**:

```
AutenticacionTest::SIN_GUARD              declarada con su motivo
RutasPreLoginTest::TOTAL_PUBLICAS         15 -> 16
AutorizacionTest::EXCEPCIONES_DE_FAMILIA  declarada: sus hermanas llevan guard
rutas.json                                619 -> 620
guard-por-familia.json                    colillas-inscripcion 4 -> 5 (con_guard sigue en 3)
```

**Las dos que NO se movieron, y el porqué importa**: `guards-por-ruta.json` lista las que **llevan**
guard, y ésta no lleva; y `familias-que-nunca-entran-en-el-candado.json` no la recoge porque
`colillas-inscripcion` tiene **3 hermanas con guard**, o sea ≥ 2, así que el candado de familia
sigue mirándola. *Ése fue el motivo de ponerla aquí y no en `pagos-inscripcion`, que está en ese
censo como «0 de 2».*

`FamiliasQueNuncaEntranTest` —las **escrituras** que viven donde el candado no llega— tampoco se
mueve: sigue en **26**, porque esto lee.

### PREGUNTAR CONSUMÍA SUBIDAS — y la causa no era un número mal puesto

**Lo encontró `8myvc-dd` revisando esta ruta unas horas después de fundirla**, y está reproducido,
no razonado. La clave de un limitador con nombre la arma Laravel así:

```php
// ThrottleRequests::handleRequestUsingNamedLimiter
'key' => md5($limiterName.$limit->key)
```

**Sin el verbo y sin la ruta.** Como el `GET` y el `POST` de `colillas-inscripcion/{codigo}`
llevaban el mismo `throttle:colilla` y los mismos `by('ip:…')` y `by('cod:…')`, eran **un solo
cubo de diez por hora para las dos**.

Y el reparto salía justo al revés de lo que conviene: **preguntar es la acción barata que una
familia repite** —*«¿ya me aprobaron?»*, refrescando— y **subir es la cara y la rara**.

> **El comentario del router lo escondía diciendo la verdad.** Decía *«mismo limitador: quien sube
> ahí es quien pregunta ahí»*, que es cierto — y es exactamente por lo que no se veía: **el
> problema no era quién, era que preguntar consumía subidas**.

**Y el síntoma llega antes de donde parecía.** El informe predecía que fallaría el `POST` tras
diez consultas; reproducido, **falla la undécima CONSULTA**: la familia ni siquiera puede
preguntar once veces. Si hubiera quedado saldo para subir, habría sido peor todavía —
`puede_enviar_otro` diría `true`, porque el tope por orden está libre, y el `POST` rebotaría con
el **429 genérico de Laravel** en vez del mensaje que explica qué pasa: *la familia leyendo «puede
mandar otro» y recibiendo «demasiados intentos»*.

**Arreglado con `throttle:consulta-inscripcion`**: 60 por hora por IP **y** por código —seis veces
el de la subida, mismo reparto doble que su hermana— dejando `colilla` sólo para el `POST`. Lo que
este límite protege es la base de datos de quien consulte en bucle: la ruta no escribe nada, y los
códigos los protege el carácter de control rechazando **28 de cada 29** cadenas antes de tocar
disco.

#### Y UN TEST QUE NO MEDÍA LO QUE DECÍA, DEL MISMO GÉNERO

`test_subir_sigue_topado` subía dos comprobantes **a la misma orden** y esperaba 429 en el
segundo. Eso pasa siempre — **pero por el tope de «una pendiente por orden», no por el
limitador**. Se destapó por accidente: un `sed` pisó la línea del `POST` y lo dejó apuntando al
limitador generoso, **y el test siguió en verde**.

Es el mismo error que el hallazgo de arriba visto desde el otro lado: **un detector que cuenta
bien un síntoma sin estar contando la causa**. Reescrito para gastar el limitador **por IP**
—once órdenes distintas, una subida en cada una—, que es lo único que el tope por orden no tapa.
Ahora cae cuando se rompe, comprobado.

#### PENDIENTE QUE SALE DE AQUÍ: un test genérico del comodín

**Dos veces en la misma familia ya no es casualidad**: `…/campos` tragada por `{lote}` el 19 sep,
y `…/pendientes` tragada por `{codigo}` el 20. Las dos se arreglaron con un test propio, y las dos
habrían salido de un test **genérico**: recorrer el router y, para cada ruta con URI literal,
comprobar con `getRoutes()->match()` que la atiende **su** acción y no un comodín registrado
antes.

Cazaría la familia entera de una vez y las futuras. **Es idea de `8myvc-dd` y queda escrita sin
hacer**, porque escribirla al cerrar una sesión es empezar algo que no se puede verificar entero.
