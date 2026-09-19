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

### EL HUECO QUE ESTO DESTAPÓ, Y QUE NO SE TAPA AQUÍ

**`ordenes_inscripcion.valor` no lo escribe nadie.** La columna entró en la primera migración con
el comentario *«el código queda atado a un cobro: cuánto, quién lo vendió y cuándo»*, y de las
tres sólo se escriben las dos últimas: el `INSERT` de `postAcunar` no la nombra, y en todo `app/`
no hay otra escritura de esa tabla. La bandeja del tesorero ya la **lee** (`o.valor`), así que hoy
enseña `null` en los diecisiete.

Es `profesores.tono` **otra vez**, y van tres en un mes. Lo que lo destapó no fue un barrido: fue
que **el checkout necesita un importe y no había ninguno**.

No se tapa desde aquí, y el porqué importa más que el hueco: las dos salidas fáciles son peores.
Inventarse el importe en el servidor es cobrar una cifra que nadie decidió, y dejar que lo mande
el cliente es **que la familia elija cuánto paga**. Así que el checkout **contesta 422 diciendo
que falta el precio**, con un test encima — *un agujero con un test encima es una decisión; sin
él es un olvido*. Quién pone ese precio y dónde vive es lo que espera en el §8.

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

**Las diez están dentro.** Router **612**, contado con `route:list --json` en el árbol principal
sobre `main` después de fundir.

**Abierto:**

- **QUIÉN PONE EL PRECIO DEL FORMULARIO, Y DÓNDE VIVE.** Es lo único que separa al pago en línea
  de funcionar, y no es una pregunta de código: `ordenes_inscripcion.valor` **no lo escribe nadie**
  (§5.bis), así que hoy el checkout contesta 422 en los diecisiete. Las tres formas, con lo que
  cuesta cada una:
  1. **Un precio por campaña**, que el colegio pone una vez y se estampa en cada formulario al
     acuñarlo. Es el que encaja con lo que ya hay —`config_formulario_inscripcion` es por año y ya
     tiene ruta de escritura, así que **no gasta ruta nueva**— y deja que subir el precio en marzo
     no reescriba lo que se vendió en enero. *Recomendado.*
  2. **Que secretaría teclee el importe al imprimir el lote.** Un campo más en `postAcunar`, sin
     tabla ni pantalla nueva; a cambio, el precio se puede teclear distinto dos veces el mismo día
     y nadie se entera.
  3. **Gratis**: que el formulario no se cobre. Entonces sobran la colilla, la bandeja del tesorero
     y la pasarela entera, que ya están construidas. No parece, porque Joseth ya contestó que **se
     cobra**, pero se deja escrito para que la lista sea honesta.
- **Si el colegio da o no su llave privada de la pasarela.** Con ella, un webhook se confirma
  preguntándole a Wompi; sin ella, decide la firma del evento. Las dos funcionan y **la diferencia
  es de cuánto se pierde si se filtra un secreto**, no de si cobra (§5.bis). Es una pregunta para
  cada colegio, no una decisión de producto.
- **El aviso al tesorero.** Hoy la bandeja hay que abrirla; nada avisa. `8myvc-95` midió que **sólo
  el 9,2% de los acudientes tiene correo** y el 94% tiene celular, así que el canal es WhatsApp y
  no el correo — y que este caso concreto cuesta **COP 12.261 al año para los dieciséis colegios**
  (doc 42).
- **El tope de altura del formulario configurable**, resuelto en el front avisando de que hay que
  imprimir en oficio. Ojo: el **oficio colombiano no es el `legal` de CSS** — 216×330 contra
  216×356, exactamente una pulgada.
- ¿Secretaría tiene lector de código de barras? Es lo único que devolvería el QR a esta fase.

## 8. Lo que espera una decisión de Joseth

1. ¿Existe el formulario en papel del colegio? Si aparece, **manda sobre la lista de campos**.
2. ¿Se cobra el formulario? (precios de mercado medidos en `INVESTIGACION-MATRICULAS.md`)
3. ¿Secretaría tiene lector de código de barras? Es lo único que devuelve el QR a la fase 1.
4. ~~Las dos rutas.~~ **Contestado el 19 sep 2026: autorizadas y entregadas.**
5. **El precio del formulario: quién lo pone y dónde vive.** Es la única que bloquea algo ya
   construido — ver la lista de §7, con las tres formas y lo que cuesta cada una.
