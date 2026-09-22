# La planilla sin internet — fase 5: coordinación sube por otro, y el acta

*21 sep 2026. Continúa [49](49-la-planilla-sin-internet.md) —la descarga—,
[50](50-el-ensayo-y-la-escritura-de-la-planilla.md) —el ensayo, la escritura y «¿es este?»— y
[51](51-las-ausencias-de-la-planilla.md) —las ausencias—. El plan aprobado vive en
`~/DESARROLLOS/myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y **manda sobre este documento**. Aquí va lo
que se construyó, la decisión de formato que el plan no tomó, y **los dos sitios donde lo que había
escrito estaba equivocado**.*

La frase del plan que gobierna la fase entera, y que dice para qué sirve antes de decir qué es:

> **Un acta descargable de lo que entró — que es lo que hace falta cuando el docente y coordinación
> no están de acuerdo en qué se subió.**

De ahí salen las tres decisiones de abajo. El lector del acta **no es quien acaba de pulsar el
botón** —ése ya vio el recuento en la pantalla— sino alguien que pregunta **después**, cuando la
respuesta HTTP hace semanas que se fue. Por eso hay una columna nueva, por eso acumula entre
reanudaciones, y por eso el rastro lleva las dos personas.

---

## 1 · Lo que hay

| Pieza | Qué es |
|---|---|
| `GET planilla-offline/acta/{importacion_id}` | **La ruta nueva.** El acta en `.xlsx`. `auth.personal` + tres puertas dentro |
| `importaciones.hechos` | Columna nueva (`longText`, anulable): **lo que entró**, acumulado entre pasadas |
| `App\Services\ActaDeLaImportacion` | Arma lo que hizo cada pasada, y pinta el acta |
| `App\Support\Autoriza::puedeSubirLaPlanillaDeOtro` | La D4. **Más estrecha que la de bajar**: `Secretario` baja y no sube |
| `App\Services\RespuestasDeLaPlanilla` | Sección `por_otro` en `APLICADAS`, con `confirmaSubirPorOtro()` |
| `App\Services\EnsayoDeLaPlanilla` | El bloqueo `libro_de_otro_docente` es ahora `subir_por_otro` para quien puede, y se resuelve |
| `App\Services\PuntoDeControlDeImportacion` | `guardarHechos()`: **acumula, no pisa** |
| `App\Services\Auditoria` | `porCuentaDe()`: **la segunda persona** en el resumen de la línea |
| `App\Services\EscrituraDeNotasImportadas` | Le pasa el dueño del libro a las tres líneas de rastro que escribe |
| `PlanillaOfflineController` | `exigirQueElLibroSeaSuyo` → `exigirPoderSubirEsteLibro`, y `getActa` |

**Router de 664 a 665.** Una ruta, y se mueven las **tres** instantáneas de la regla del
`CLAUDE.md`: `rutas.json`, `guards-por-ruta.json` y `guard-por-familia.json`
(`planilla-offline: 6 de 6`). **`familias-que-nunca-entran-en-el-candado.json` no se mueve**: esta
familia ya tenía cinco hermanas con guard, así que nunca estuvo en esa lista.

### Lo que NO se tocó

**`notas/*` y `ausencias/*` siguen congeladas**, que es la D8 y vale igual en la quinta fase que en
la primera: cuatro clientes, uno de ellos versiones viejas de `myvc_flutter` que conviven meses.

Y **no se tocó `auditoria`**: ni una columna nueva ni una tabla. El porqué está en la §3.2.

---

## 2 · Las tres decisiones

### 2.1 · La puerta de escribir es MÁS ESTRECHA que la de leer

La D4 dice «coordinación», y la de bajar (`puedeDescargarLaPlanillaDeOtro`) incluye
`esAdministrativo` —o sea el `Secretario`— con un argumento que está escrito arriba y que **aquí no
se sostiene**: lo que sale por las rutas de lectura es la planilla que esa persona *ya puede ver por
la web*, sólo que en un `.xlsx`. Aquí no sale nada: **se escriben las notas de un grupo entero a
nombre de otra persona**.

Así que se cae la rama administrativa: **`Secretario` puede bajar el libro y no puede subirlo.**
Secretaría administra la estructura del colegio —matrículas, grupos, papeles— y por eso ve la
planilla; calificar es de quien enseña, y decidir sobre una calificación ajena es de coordinación
académica. `puedeEditarPlantillaNotas` —superusuario o `can_edit_plantilla_notas`— es exactamente
ese alcance y no uno parecido.

**Las tres cosas que este permiso NO relaja**, y conviene leerlas juntas porque las tres se pierden
por separado:

1. **El periodo tiene que estar abierto igual.** Subir *por* un docente no es subir *a* un periodo
   cerrado: eso es otra decisión, del colegio, y tiene su propia pantalla. Lo fija
   `d4_un_periodo_cerrado_no_se_escribe_ni_con_permiso_ni_con_confirmacion`.
2. **Hace falta confirmarlo a mano.** Ver la §2.2.
3. **Queda auditado con las dos personas.** Ver la §3.2.

### 2.2 · Dos preguntas, y en dos sitios distintos: «puede» y «quiso»

`puedeSubirLaPlanillaDeOtro` contesta si esta persona **puede**; es un permiso del colegio entero y
no dice nada de *este* archivo. La segunda pregunta es si **quiso**, y ésa se contesta sobre el
libro que tiene delante, con la sección `por_otro` de las instrucciones.

Hacen falta las dos porque el permiso es ancho de por sí: quien lo tiene lo tiene para los
cincuenta y tres docentes, así que confundirse de archivo —o subir el que un compañero dejó en la
carpeta compartida— es escribir las notas de un grupo que nadie ha mirado. Un bloqueo que hay que
resolver a mano obliga a **leer de quién es el libro antes de que pase nada**, y el motivo lo dice
con su nombre.

Es la misma forma que `firma_rota`, y no por simetría: las dos son «se puede seguir, pero no en
silencio». El bloqueo `subir_por_otro` se resuelve y **pasa a `bloqueos_resueltos`**; no desaparece,
porque el acta y la pantalla tienen que poder decir que esto se subió por otro.

> **La escritura queda cubierta sin una segunda guarda, y eso se comprobó en vez de suponerlo.**
> `postImportar` devuelve **422 sin escribir nada** en cuanto `bloqueos` no está vacío, y ese `if`
> va **antes** de instanciar `EscrituraDeNotasImportadas`. Así que sin la confirmación no entra una
> nota, y no porque nadie lo compruebe después. Lo fija
> `d4_sin_confirmar_la_importacion_da_422_y_no_escribe_ni_una_nota`, que **no se conforma con el
> código de estado**: compara la huella de seis tablas antes y después, porque el día que alguien
> mueva ese `if` el 422 podría seguir saliendo con media planilla dentro.

### 2.3 · El acta es un `.xlsx` y NO un PDF

El plan dice *«un acta descargable»* y **no dice el formato**; esta es la decisión, con su precio
delante.

**En este backend no hay ninguna librería de PDF.** Los informes del colegio —boletines,
certificados, actas de evaluación— los imprime el **front** desde el navegador: la API manda los
datos y el papel se hace allí. Montar `dompdf` o `mpdf` aquí sería meter una dependencia nueva, con
sus fuentes y su memoria, en los dieciséis colegios **para un único documento**, y encima uno cuyo
contenido es una tabla de números.

Con el `.xlsx` no se añade nada: **PhpSpreadsheet ya está**, es lo que genera la planilla que esta
misma familia descarga, y el acta comparte con ella la portada —el colegio arriba, el periodo
debajo— así que las dos se reconocen como del mismo sitio. De paso el acta se puede **filtrar y
sumar**, que es lo que alguien hace de verdad con un desglose de cuarenta hojas y no podría hacer
con un PDF. Y si hay que entregarlo en papel se abre y se imprime, que es exactamente lo que ya se
hace hoy con la planilla.

**Lo que se paga:** un `.xlsx` no es un documento cerrado —quien lo recibe puede editarlo antes de
reenviarlo—, y por eso la hoja va **protegida**. La protección de PhpSpreadsheet no es una firma y
no pretende serlo: lo que hace es que nadie «corrija» una casilla sin querer. La versión de la que
uno se fía siempre es la que se vuelve a descargar.

---

## 3 · Los dos sitios donde lo escrito estaba equivocado

### 3.1 · La mitad de la autorización no llegaba al importador: el controlador cortaba antes

`EnsayoDeLaPlanilla` ya sabía convertir `libro_de_otro_docente` en `subir_por_otro` para quien tiene
el permiso, y `RespuestasDeLaPlanilla` ya sabía leer la confirmación. **Y no servía de nada en
`POST planilla-offline/importar`**, porque tres líneas antes el controlador hacía:

```php
$this->exigirQueElLibroSeaSuyo($lector);   // 403 para cualquiera que no sea el dueño
```

O sea: coordinación recibía **403 antes de que el ensayo llegara a correr**. El camino nuevo existía
entero y era inalcanzable por la única ruta que escribe. En `POST planilla-offline/ensayo` sí
funcionaba —esa ruta no tiene la guarda—, así que el síntoma habría sido el peor posible: **la
pantalla ofrece la confirmación, el usuario la marca, y al pulsar «Importar» sale un 403 sin
relación aparente con nada de lo que acaba de leer.**

El arreglo es la segunda puerta, y el método pasa a llamarse por lo que hace:

```php
private function exigirPoderSubirEsteLibro(LaPlanillaQueSeSube $lector): void
{
    // 1 · el dueño
    if ($propio !== null && $propio === $delLibro) { return; }

    // 2 · coordinación; lo que le espera dentro es el bloqueo, no la vía libre
    if (Autoriza::puedeSubirLaPlanillaDeOtro($this->user)) { return; }

    Autoriza::exigir(false, '…');
}
```

**Un docente ajeno sin el permiso sigue recibiendo 403 aquí**, y eso no cambió: lo que se abrió fue
la puerta de coordinación, no la de al lado.

### 3.2 · El rastro tenía una sola persona, y la fase entera existe por la segunda

`Auditoria::registrar()` escribe en este camino tres clases de línea —la nota editada, la falta
creada o borrada, y la subunidad nueva— y las tres nombran **al actor de la petición**, que con la
D4 es coordinación. El docente cuyo libro entró **no aparecía en ninguna parte**. Que es, literal, el
dato que se reclama el día que se reclama algo.

Se completa con `->porCuentaDe($nombre)`, que **añade** al final del resumen (no lo pisa: un resumen
que perdiera «editó nota 88.412 de Fulanito» para ganar «por cuenta de Mengano» cambiaría un dato
por otro en vez de sumarlos), y **no se añade ninguna fila**: las que ya se escribían se completan.

**Por qué al `resumen` y no a una columna propia**, que es la pregunta que hay que contestar antes
de tocar esta tabla:

- **La pregunta es de lectura humana, no de consulta.** Las columnas de `auditoria` existen para
  filtrar —«qué le han hecho a este alumno», «quién cambió esta nota»— y cada una tiene su índice
  detrás. «Por cuenta de quién» no se filtra: se lee, en la fila que ya estás mirando porque
  llegaste a ella por la nota o por el alumno.
- **Y el coste.** Una columna aquí es un `ALTER` en **dieciséis** bases que han derivado entre sí,
  con el precedente fresco de una migración que se paró a mitad de despliegue y dejó un colegio con
  siete sin correr (ver `App\Support\Ancla`). Para un dato que no se consulta, no sale.
- **Y la consulta tiene dónde hacerse el día que haga falta**: `importaciones.hechos` guarda las dos
  personas **en campos** (`contexto.subio` y `contexto.por_cuenta_de`), con sus ids.

---

## 4 · `importaciones.hechos`, y por qué acumula

La migración es `2026_09_21_300000_lo_que_la_importacion_hizo`. Una columna, `longText`, anulable,
idempotente — las tres decisiones son las mismas de sus dos hermanas y están argumentadas en su
docblock. La que no es de trámite es ésta:

> **El acta tiene que contar lo que entró, no lo que se prometió.**

Son tres cosas distintas y sólo la tercera sirve de acta:

1. **El plan** (lo que el ensayo prometió) no es lo que pasó. Una petición que se queda sin
   presupuesto escribe una fila y promete cuarenta. Un acta que contara el plan diría «entraron 312
   notas» de una importación en la que entraron once.
2. **`avance`** dice por qué fila iba, no qué escribió: una fila que sólo tenía casillas vacías
   cuenta igual que una que cambió cinco notas.
3. **`avisos`** guarda lo que no se supo traducir. Es la mitad mala del acta —y esta familia **no la
   escribía**: la devolvía en la respuesta y la perdía al contestar—. Ahora se guarda también.

### Lo que se suma, lo que se sustituye y lo que se une

Lo pide el encargo en una frase —*una importación cortada y continuada tiene que dar un acta con el
total, no con la última tanda*— y la trampa está en que **no todo se suma**. Lo que se suma tiene
que ser lo que hizo *esta* pasada, y sólo lo es cuando la pasada siguiente **no lo vuelve a contar**:

| Qué | Cómo se junta | Por qué |
|---|---|---|
| `notas_escritas`, `notas_borradas`, `ausencias_*`, `filas` | **Suma** | Las filas que el punto de control da por hechas ni se estudian en la pasada siguiente |
| `se_quedan_fuera` de una hoja que **entró** | **Suma** | Cada pasada sólo miró las casillas que le tocaban |
| `se_quedan_fuera` de una hoja que **se cayó** | **Sustituye** | Una hoja caída no tiene ninguna fila marcada, así que se re-diagnostica **entera** en cada pasada: sumar diría que una planilla de 40 casillas dejó fuera 120 en tres tandas |
| Filas descartadas (F6) | **Unión de ids** | Una fila que no se puede escribir tampoco se marca como hecha, así que **la vuelve a diagnosticar cada pasada**. Por eso viaja como conjunto (`descartadas`) y no como contador, y el acta cuenta el conjunto |
| Motivos | **Unión sin repetir** | El mismo corte se vuelve a diagnosticar en cada pasada, y «el periodo está cerrado» escrito tres veces se lee peor, no mejor |
| Indicadores creados | **Unión por `subunidad_id`** | La F9 es idempotente por nombre: una pasada reanudada puede volver a *reportar* la misma subunidad sin haberla creado dos veces |
| `contexto` | **Pisa**, salvo `pasadas` (incrementa) y `reanudada` (queda en `true`) | Describe la importación, no la pasada. Pero la **última** pasada de una importación cortada es la que la terminó: si `reanudada` no acumulara, el acta diría que no hubo corte |

> **`asignatura_id` es un número y no se suma**, que es por lo que los contadores van **declarados**
> en `CONTADORES_DE_HOJA` y no deducidos con `is_numeric`. Con la regla automática, una hoja que
> apareciera en dos tandas acabaría apuntando a la asignatura 604 porque 302 + 302 son 604.

**Se escribe al final de cada pasada, también cuando la pasada revienta.** Lo que se escribió antes
del error está escrito en `notas`; un acta que sólo contara las pasadas que acabaron bien sería un
acta que no cuenta lo que entró, y el caso en que alguien la pide es precisamente ése.

---

## 5 · El acta: qué dice y quién la puede pedir

Una hoja —`Acta`— y no cinco, aunque tenga cinco secciones: se lee de arriba abajo una vez, no se
navega, y repartirla en pestañas obligaría a mirar cuatro para saber si falta algo.

| Sección | Qué lleva |
|---|---|
| Cabecera | El colegio, el periodo y el año, **como la portada del libro** |
| Quién, qué y cuándo | Quién la subió, **por cuenta de quién**, de quién es el libro, cuándo empezó y terminó, el archivo, **su huella**, cuándo se descargó, si estuvo reanudada y en cuántas pasadas |
| Lo que entró, en total | Notas escritas y borradas, faltas creadas y borradas, indicadores, filas aplicadas, filas descartadas, definitivas recalculadas |
| Por hoja | Escritas, borradas, las que se quedaron fuera, las filas descartadas — y **debajo de cada hoja, sus motivos** |
| Indicadores creados | Con nombre y peso, no como un número: un indicador nuevo cambia la definitiva de todo el grupo |
| Avisos | Lo que no entró y **no tiene hoja**: la F4 y la F5, que se agrupan por valor porque así es como se arreglan |

Los nombres del colegio y del periodo se resuelven **al pedir el acta**; los de las dos personas se
guardaron **al importar**. La diferencia tiene motivo: el colegio y el periodo siguen existiendo y
su nombre de hoy es el bueno, mientras que una cuenta de usuario se puede borrar y entonces el
nombre guardado es lo único que queda de quién fue. Es la misma regla que ya sigue
`auditoria.actor_nombre`.

> **Las fechas se convierten al leer, y el acta no lo hacía.** `importaciones` es la excepción
> declarada de `RelojUnicoTest`: se escribe entera con `now()`, o sea en **UTC**. La primera versión
> de esta ruta las pasaba tal cual al Excel, así que el acta decía «Empezó a las 14:41» donde la
> pantalla de esa misma fila dice las 9:41 — la misma tabla leída de dos maneras en el mismo repo, y
> el Excel es el que se imprime y se archiva. Lo encontró y lo arregló la sesión de los relojes;
> `getActa` convierte `inicio`, `fin` y `created_at` a `Reloj::ZONA` antes de pintar, igual que hace
> `Alumnos\ImportarController` para su pantalla. Ver el [53](53-los-cuatro-relojes.md) §4.2.

### Las tres puertas, y la que está cerrada

1. **Quien la subió.** Es su propio recibo.
2. **El docente dueño del libro.** Es la mitad que hace útil a la fase entera: sin esto,
   coordinación sube por él y él no tiene con qué comprobarlo.
3. **Quien puede subir por otro.** Quien puede hacerlo tiene que poder revisarlo.

**Un docente no puede leer el acta de otro**, y el 403 lo dice con su motivo. El acta lleva dentro
el recuento de las notas de un grupo, o sea lo mismo que la planilla, y la puerta de la planilla ya
es ésta: que un docente pudiera pedir actas por `id` sería un listado de las notas del colegio a
razón de una petición por número.

**Los dos 404 no son el mismo**, y por eso dicen cosas distintas:

- **No existe, o no es de una planilla.** Las importaciones de alumnos viven en la misma tabla y no
  tienen acta.
- **Es de una planilla y no tiene `hechos`.** Sólo puede pasar con las de antes de esta migración y
  con las que **se quedaron en un bloqueo** —un 422 que ni siquiera llegó a mirar las hojas—. Se
  contesta 404 **con los dos motivos dentro**, porque desde la fila no se distinguen.

> **«No entró nada» sí tiene acta, y es la que hace falta.** Cuando el libro es legítimo y aun así
> no se escribe una línea —el periodo se cerró mientras el docente pasaba las notas, que es el caso
> de verdad— el recuento se guarda igual, con sus ceros y **con el motivo por hoja**. Lo que hay que
> poder contestar después no es «¿entró algo?» sino «¿por qué no?».

---

## 6 · Al desplegar

**La migración va ANTES, y el síntoma de olvidarla no apunta a la migración.** Es el mismo caso que
`descargas_de_planilla` en la [49](49-la-planilla-sin-internet.md) §5.bis: sin la columna,
`guardarHechos` revienta con `Unknown column 'hechos'` **al terminar una importación que ya escribió
las notas**. O sea que el colegio vería un 500 en «subir la planilla» con las notas dentro, que es
la peor combinación posible de las dos.

Orden en cada uno de los dieciséis: `php artisan migrate` y después el código.

**No hay permiso nuevo que sembrar.** `can_edit_plantilla_notas` ya existe desde
`2026_09_05_300000` y ya está repartido; lo que la fase 5 hace es **leerlo desde un sitio más**.
Quien hoy puede editar la plantilla de notas podrá, además, subir la planilla de otro docente: eso
es la D4 y es lo que se pidió, pero **conviene decírselo a cada colegio** en vez de que lo
descubran.

---

## 7 · Lo que se corrió

**Ocho tests nuevos**, todos en `tests/Contrato/PlanillaOfflineImportarTest.php`, porque reusan la
máquina de fabricar casos de la fase 2: cada uno **baja un libro de verdad con la fase 1**, le
escribe encima lo que un docente escribiría, lo sube y mira la base. Los del acta, además, **leen el
`.xlsx` que se descarga**, que es lo único que prueba lo que la gente va a abrir.

| Test | Qué fija |
|---|---|
| `d4_un_docente_ajeno_sin_permiso_sigue_bloqueado_con_libro_de_otro_docente` | Sin el permiso sigue siendo callejón sin salida, con su motivo |
| `d4_con_el_permiso_el_ensayo_ofrece_subir_por_otro` | Con el permiso el bloqueo cambia de cara; tenerlo no es haber confirmado |
| `d4_sin_confirmar_la_importacion_da_422_y_no_escribe_ni_una_nota` | 422 **y la huella de seis tablas idéntica antes y después** |
| `d4_con_la_confirmacion_escribe_y_queda_auditado_con_las_dos_personas` | Entra, va a `bloqueos_resueltos`, y la línea de `auditoria` nombra a los dos |
| `d4_un_periodo_cerrado_no_se_escribe_ni_con_permiso_ni_con_confirmacion` | El permiso no abre un periodo cerrado, y la confirmación tampoco |
| `el_acta_la_piden_quien_subio_el_dueno_del_libro_y_coordinacion_y_no_un_docente_ajeno` | Las tres puertas y la cerrada |
| `el_acta_cuenta_lo_hecho_y_no_lo_prometido` | Corte con el presupuesto a cero: el plan promete cuatro, el acta dice una |
| `el_acta_de_una_importacion_reanudada_trae_el_total_acumulado` | Dos tandas, y el acta trae la suma — no la última |

Y el que ya estaba, `la_planilla_de_otro_docente_da_403_al_importar`, se queda con su 403: lo que
cambió es el comentario, porque el motivo ya no es «la fase 5 no existe» sino «ese docente no tiene
el permiso».

**La suite entera**, contra base propia, el 21 sep 2026 (`b9876af` + este trabajo sin commitear):

```
docker exec -d -w /app -e DB_TEST_DATABASE=simonbolivar_testing_acta 8myvc-app-1 \
    sh -c 'php artisan test > /tmp/suite-acta.log 2>&1; echo "EXIT=$?" >> /tmp/suite-acta.log'

  Tests:    1 skipped, 2830 passed (56587 assertions)
  Duration: 1094.12s
EXIT=0
```

> **Y una que NO valió, con lo que costó dicho.** La tanda anterior salió **1 roja** y era mía:
> `RelojUnicoTest` contó **14** `now()` en `PuntoDeControlDeImportacion` donde la lista declaraba
> 13. El que sobraba es el de `guardarHechos()`, que escribe `updated_at` **en el mismo `UPDATE`**
> que la columna del acta. Se resolvió **declarándolo** —13 → 14, con el motivo al lado— y no
> poniéndolo en `Reloj::ahora()`: esa columna es contra la que `marcarAbandonadas()` compara un
> corte que también sale de `now()`, así que ponerle Bogotá sólo a una de las dos puntas sería el
> fallo de verdad. La tabla se mueve entera o no se mueve, y esa decisión no es de esta fase.
>
> El acta sí usa `Reloj::ahora()`, porque su hora **sale por pantalla y no entra en ninguna
> columna**. Ésa es la línea que separa los dos casos.

---

## 8 · Lo que queda abierto

1. **La pantalla de «subir por…» no existe.** El backend ya sirve `libro.puede_por_otro` y
   `libro.por_otro_confirmado` para que el front no tenga que adivinar, y acepta
   `respuestas.por_otro = {decision:"confirmo"}`. Lo que falta es el paso del asistente, y el botón
   de descarga del acta detrás del resultado.
2. **El acta no se ha medido con un libro grande.** Un libro de quince asignaturas con filas
   descartadas en todas puede dar un acta de varios cientos de renglones. El tope de motivos por
   hoja está en **200** y es una decisión sin medición detrás: lo que protege no es la columna —es
   `longText`— sino que el acta se siga leyendo.
3. **`filas_sembradas` no sale en el acta.** Está en `hechos.totales` y no se pinta: es un dato para
   leer un incidente, no para una discusión entre dos personas. Si alguna vez hace falta, está
   guardado.
4. **Los avisos de una importación reanudada se parecen entre sí.** La columna `avisos` acumula por
   pasada, y las familias F4 y F5 se agrupan **por valor**: dos tandas dan «6 casillas con “4,5”» y
   «4 casillas con “4,5”», que son dos cadenas distintas y las dos ciertas de su tanda. El acta
   colapsa los repetidos **exactos** y no éstos. Arreglarlo de verdad es agrupar por valor al
   guardar —trabajo de la columna, no del papel— y no se ha hecho porque el caso pide a la vez un
   corte y un valor no interpretado, que es raro.
4. **El peldaño 2 sigue como lo dejó la [50](50-el-ensayo-y-la-escritura-de-la-planilla.md) §6.1**:
   el front no manda la sección `firma`. `por_otro` se construyó con el mismo mecanismo, así que si
   el front añade una añade las dos.
