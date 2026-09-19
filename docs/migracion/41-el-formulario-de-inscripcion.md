# El formulario de inscripción impreso, y el código que lo sigue

Pedido por Joseth el **19 sep 2026**: un informe para **imprimir el formulario de
inscripción**, con un código generado que *«se queda con ese alumno al que inscriban para
matricular»*, imprimible en tanda, **sin grupo cuando el alumno es nuevo y con los datos
puestos cuando es antiguo**.

La pantalla la construyó `myvc-front-bf` en `myvc_front` (commits `04e8f003`, `6c60431d`,
`43fab911`). **De las diez rutas autorizadas hay ocho escritas y probadas**; faltan las dos de la
pasarela (§7). El análisis largo del embudo de admisiones vive en
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

**Abierto:**

- **Las dos rutas de la pasarela**, autorizadas y sin escribir: el checkout y el webhook, las dos
  públicas (13 → 15). El webhook **nunca se cree lo que le llega**: vuelve a preguntarle a la
  pasarela con la llave pública, y con eso un secreto de eventos filtrado no sirve para forjar un
  pago (doc 40 §4).
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
4. **Las dos rutas.**
