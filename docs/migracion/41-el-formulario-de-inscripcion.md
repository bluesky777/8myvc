# El formulario de inscripción impreso, y el código que lo sigue

Pedido por Joseth el **19 sep 2026**: un informe para **imprimir el formulario de
inscripción**, con un código generado que *«se queda con ese alumno al que inscriban para
matricular»*, imprimible en tanda, **sin grupo cuando el alumno es nuevo y con los datos
puestos cuando es antiguo**.

La pantalla la construyó `myvc-front-bf` en `myvc_front` (commits `04e8f003`, `6c60431d`,
`43fab911`). **Las dos rutas de este documento NO existen todavía**: esperan autorización.
El análisis largo del embudo de admisiones vive en
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
   imprime dos veces. La llave es **(alumno_id, year de destino)**.
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

## 5. Lo que espera una decisión de Joseth

1. ¿Existe el formulario en papel del colegio? Si aparece, **manda sobre la lista de campos**.
2. ¿Se cobra el formulario? (precios de mercado medidos en `INVESTIGACION-MATRICULAS.md`)
3. ¿Secretaría tiene lector de código de barras? Es lo único que devuelve el QR a la fase 1.
4. **Las dos rutas.**
