# La planilla sin internet — fase 2: el ensayo y la escritura

*21 sep 2026. Continúa [49](49-la-planilla-sin-internet.md), que construyó la descarga. El plan
aprobado vive en `~/DESARROLLOS/myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y **manda sobre este
documento**. Aquí va lo que se construyó, lo que se midió, y los **cinco sitios donde el plan
resultó estar equivocado** — cada uno con su medida al lado.*

La fase 1 dejó al docente bajándose el libro y tecleando las notas en la web al llegar. Ésta cierra
el ciclo: **el libro vuelve**. Y la frase que gobierna todo lo de abajo es la D3, que es la razón de
ser del espejo firmado: *«sólo entra lo que el docente cambió»*.

---

## 1 · Lo que hay

| Pieza | Qué es |
|---|---|
| `POST planilla-offline/ensayo` | El diagnóstico. **No escribe nada** |
| `POST planilla-offline/importar` | La escritura, **reanudable** por huella del fichero |
| `App\Services\LaPlanillaQueSeSube` | El lector del `.xlsx` que vuelve, y **quién decide el peldaño** |
| `App\Services\EnsayoDeLaPlanilla` | Las familias F1–F5, F7 y F9, más los avisos de F6 y F8 |
| `App\Services\EscrituraDeNotasImportadas` | Siembra, escribe, audita y recalcula una vez por par |
| `App\Services\RespuestasDeLaPlanilla` | Las decisiones que manda el front, leídas sin creerse nada |

**Router de 658 a 660.** Dos rutas, con `auth.personal` como las tres de la fase 1 y el permiso de
verdad dentro. Se movieron `rutas.json`, `guards-por-ruta.json` y `guard-por-familia.json`;
**`familias-que-nunca-entran-en-el-candado.json` sigue sin moverse**, porque la familia sigue con
todas sus rutas con guard (`planilla-offline: 5 de 5`).

### Lo que NO se tocó

`notas/detailed`, `notas/lote`, `notas/update`, `ausencias/*` y `notas/nivelar/*`. Es la D8 y sigue
valiendo el motivo de la fase 1: cuatro clientes cuelgan de ahí.

Y **el ensayo no escribe ni una fila**. Lo comprueba
`PlanillaOfflineImportarTest::el_ensayo_no_escribe_ni_una_fila`, que cuenta filas y último `id` de
`notas`, `notas_finales`, `subunidades`, `unidades`, `bitacoras` y `auditoria` **y además la suma de
las notas** — porque un `UPDATE` no mueve ni el recuento ni el último id, y es justo lo que más daño
haría aquí.

### La D3, escrita como se implementó

| | `archivo` vs `espejo` | `base` vs `espejo` | Qué pasa |
|---|---|---|---|
| 1 | igual | — | **No se escribe.** El docente no tocó esa casilla, pase lo que pase con la base |
| 2 | distinto | igual | **Se escribe.** Es el caso normal y es «el trabajo», no un problema |
| 3 | distinto | distinto | **Choque** (F7). Por defecto manda el sistema |

Y la D9 al lado: **casilla vacía = no la toques**; **guion = `nota` a `NULL`**. Se aceptan los tres
guiones que la gente escribe (`-`, `–`, `—`): el corrector de Word y el teclado del móvil ponen los
otros dos solos, y negarse a entenderlos sería castigar un acierto.

---

## 2 · Los cinco sitios donde el plan está equivocado

### 2.1 · La F9 no se puede resolver con lo que el mapa guarda (§5 F9 contra §4.6)

La F9 —crear el indicador que el docente escribió en una columna de reserva— necesita una
`unidad_id`. **La hoja `_myvc` no la tiene**: su `reservadas` es `letra => número` y nada más.

```
"reservadas": {"F":3,"G":4,"H":5}        ← ni una unidad en todo el mapa
```

Y el caso que más la necesita es justo el que no se puede deducir mirando a los lados: **una
asignatura sin ningún indicador**, donde *todas* las columnas son de reserva y no hay vecina a la
que preguntar. Es la decisión (c) del [49](49-la-planilla-sin-internet.md) y el caso para el que la
D12 existe — el docente se lleva la hoja, propone los indicadores y los trae de vuelta.

Se resuelve **leyendo la banda de la fila 1 del propio archivo** —`"2 · Geometría  30%"`— y usando
ese número como orden dentro de las unidades vivas, con el mismo `ORDER BY u.orden, u.id` que usó el
generador. Funciona, y **es más frágil que el resto del mapa**: vive en una celda que el docente
puede editar, mientras que las columnas y las filas viven en una hoja oculta. Cuando no se puede
resolver, la F9 lo dice y la única opción que queda es dejar las notas fuera.

*Lo que arreglaría esto de raíz es una línea en el mapa —`"reservadas": {"F": {"numero":3,
"unidad_id":1204}}`— y es un cambio del generador, o sea de la fase 1, que mueve la firma de todos
los libros que ya andan por fuera. Se anota y no se hace aquí.*

### 2.2 · «El peso cambió» sólo se puede leer del texto de la cabecera, y en `promedio` no se puede leer

La F2 pide avisar de *«un peso distinto»*. **Ni el mapa ni el espejo guardan pesos**, y la base sólo
sabe el de hoy, así que la única fuente de lo que pesaba **el día de la descarga** es la celda de
cabecera del propio libro (D11):

```
getCell('D2')->getValue()  ->  "1.\n33%"        en modo porcentaje
                           ->  "1.\nprom."      en modo promedio
```

Dos consecuencias que el plan no dice:

- **En modo `promedio` el peso cambiado no es detectable**, porque el libro no imprime ninguno
  (§3.5). No es una limitación del código: es que el dato no existe en el archivo.
- La comparación se hace contra **texto que el docente puede editar**. Por eso el renglón sale
  `decidible: false` — es informativo y no cambia ni una nota; lo que cambia es la definitiva que
  salga de ellas.

### 2.3 · El ejemplo de la F3 describe un libro que la fase 1 no puede generar

El §5 dice: *«un docente que baja las cuatro planillas y sube el libro después del cierre del
periodo 2 tiene tres periodos que sí entran»*. **Eso no son un libro: son cuatro ficheros.** La D1
es «un libro por periodo» y `GET planilla-offline/libro/{periodo_id}` toma un solo periodo, así que
todas las hojas de un libro comparten periodo y un cierre se las lleva todas.

La regla se implementó igual **por hoja** (`mapa.periodo_id`, no la cabecera del libro), porque es
la forma correcta y porque la fase 3 y el peldaño 3 pueden traer libros armados a mano. Y el test
que la sujeta **fabrica el caso**: descarga un libro de dos hojas, cierra otro periodo del año,
reescribe `_myvc` apuntando una de las hojas a ese periodo y **vuelve a firmar**, para que lo que se
prueba sea la regla y no un libro roto.

### 2.4 · Una columna movida choca consigo misma si se compara tal cual

La F2 permite `mover:<subunidad_id>`: las notas de una columna cuyo indicador borraron van a otro.
Aplicando las tres puntas sin más, **eso da un choque en cada fila**:

- el **espejo** de esa columna es el del indicador **borrado**,
- la **base** es la del indicador de **destino**,
- o sea dos casillas distintas enfrentadas, que casi nunca coinciden.

Y como el defecto del choque es «manda el sistema», **mover una columna no movía nada**. Medido con
`f2_un_indicador_borrado_deja_su_columna_fuera_y_se_puede_mover`: **0 de 1 notas movidas antes de la
excepción, 1 de 1 después**. Una columna movida no puede chocar, y así está escrito en
`decidirLaCelda`.

### 2.5 · El §9.8 ya está medido para el ensayo, y el tope de tiempo sobra otra vez

El §9.8 dejaba el ensayo por cronometrar. Medido el 21 sep 2026 contra `caz_zaragoza`, docente 3,
periodo 39 (26 hojas de asignatura, 205 filas de alumno, **893 casillas**, fichero de 164 KB), en el
contenedor compartido:

| Caso | abrir el `.xlsx` | estudiar | **total** | consultas | pico |
|---|---|---|---|---|---|
| El libro tal como se bajó | 0,19 s | 0,10 s | **0,28 s** | 236 | 54 MB |
| **Todas** las casillas reescritas | 0,18 s | 0,10 s | **0,27 s** | 238 | 60 MB |

O sea que el peor caso del diagnóstico —ninguna celda descartable por «igual al espejo»— cuesta lo
mismo que el mejor: **lo que domina es abrir el fichero, no estudiarlo**. El tope de 20 s se queda
escrito porque el ensayo no puede reanudarse y un 500 por `max_execution_time` llega al navegador
sin cabeceras de CORS, pero **no hace falta para un libro de este tamaño**. De los segundos vale la
razón y no el valor —la máquina estaba compartida—; las **consultas** no dependen de eso.

**La escritura sigue sin cronometrar contra datos de verdad.** Es la mitad que escribe, y medirla
exige escribir en un colegio.

---

## 3 · Lo que estas cifras destaparon: `EscalaDeNotas::minimo()` no estaba cacheado

La primera medición del peor caso dio **1.130 consultas** para 893 casillas. Las 890 de más eran una
por casilla con valor, y salían de aquí:

```php
// EscalaDeNotas
public static function maximo(int $yearId): ?int   // cacheado desde el primer día
public static function minimo(int $yearId): ?int   // sin caché
```

`motivoSiNoCabeEnAnio()` llama a los dos por cada valor que estudia. `maximo()` se cacheó *«porque
`putLote` valida hasta 200 notas seguidas y casi siempre son del mismo año»* —lo dice su propio
docblock— y `minimo()` se quedó fuera.

Se le puso la misma caché. Medido con el mismo libro: **1.130 → 238 consultas**, y el tiempo de
estudiar de 0,42 s a 0,10 s. **El mismo ahorro se lo lleva `putLote`**, que hasta hoy hacía hasta
200 consultas idénticas por petición sin que nadie lo hubiera contado.

*No estaba en el encargo y se toca igual porque el número apareció midiendo esto: un `SELECT` por
nota es lo que la fase 2 vino a evitar, y dejarlo escondido detrás de una función de otra familia lo
habría hecho invisible otra vez.*

---

## 4 · Las decisiones que hubo que tomar, y no estaban escritas

| | Decisión | Por qué |
|---|---|---|
| a | El **peldaño 2 es un bloqueo que se resuelve**, no un camino aparte | La §4.7 dice «hay que confirmar la lista». Un bloqueo con su `resuelto_por` es la misma forma que `hoja_sin_grupo` en el importador de alumnos, y es lo que deja enseñar «esto era un problema y lo resolviste» |
| b | Con la firma rota **no hay choques** | Un choque se define contra el espejo. Sin espejo fiable no se puede decir «cambió en el sistema desde la descarga», y decirlo igual sería la comparación mentirosa que la firma existe para evitar |
| c | La **F1 no estudia ninguna hoja** | Un libro de otro colegio o de otro docente no tiene ni una hoja que se pueda escribir, y estudiarlas sería enseñarle a quien lo subió los nombres de treinta alumnos que no son suyos |
| d | `importar` da **422 y no escribe nada** si hay bloqueos | Escribir lo que sí se puede y avisar del resto dejaría media planilla dentro y un error delante |
| e | Si **ninguna hoja** sobrevive a la F3, `importar` contesta **422**, no 403 | No es un permiso de la persona: es el estado del periodo. Y la F3 es un filtro por hoja, no una guarda que tire la petición |
| f | La **F6 se contesta a medias y se dice** | Quién se retiró y quién entró después **no son decisiones** —no se le pregunta nada al docente—, así que van como aviso. Emparejar por nombre las tres filas del bloque final es la fase 3, y el aviso lo dice con esas palabras |
| g | Crear un indicador (F9) es **idempotente por nombre** | Pasa una vez por columna, **fuera** de la transacción de la fila, así que el punto de control no lo cubre. Si el proceso muere entre crear y la primera fila, la siguiente subida crearía **dos columnas con el mismo nombre** — y en `porcentaje`, una unidad que suma de más |
| h | El presupuesto de `importar` es `segundos_por_peticion`, no `segundos_del_ensayo` | Son dos números distintos en `config/importacion.php` y significan cosas distintas: «o cabe o se recorta» contra «cuánto avanzo en esta petición» |
| i | El `id` de un choque sale de `sha1(hoja\|alumno\|subunidad)` | Tiene que sobrevivir al viaje «decido en el ensayo, subo después». Las tres cosas identifican la casilla y ninguna se mueve al reenviar el fichero |
| j | `cambiaron = entran + se_borran + se_quedan_fuera`, **exactamente** | Una casilla que trae lo que ya hay no se cuenta como cambiada. Si se contara, los tres números de la pantalla no sumarían el total y habría que explicar por qué |

---

## 5 · La trampa que costó tiempo y se repetirá

### El espejo guarda lo de **el día de la descarga**, así que fabricar un caso tiene un orden

Dos tests salieron rojos por lo mismo y el síntoma no apuntaba ahí:

1. **`se_siembra_la_fila_de_una_casilla_que_nadie_visito_nunca`.** Borrar la fila de `notas`
   *después* de bajar el libro no fabrica «una casilla que nunca se calificó»: fabrica **un
   choque** —espejo con nota, base vacía— y por defecto gana el sistema, así que no se escribía
   nada y `filas_sembradas` salía 0.
2. La misma trampa al revés en cualquier caso que toque la base antes de subir.

La regla, escrita para quien venga: **lo que cambie el estado de partida va ANTES de la descarga; lo
que simule «alguien tocó la web» va DESPUÉS.** Son dos casos distintos y el orden es lo único que
los separa.

---

## 6 · Lo que queda abierto

1. **El peldaño 2 no se puede resolver con el contrato de respuestas del front.** El documento que
   `app2/src/app/datos/planilla-offline.ts` construye tiene `estructura`, `celdas`, `escala`,
   `choques` y `reserva`, y **no tiene `firma`**. El servidor acepta una sección `firma:
   {decision:"confirmo"}` de más para que ese camino exista, pero **hoy la pantalla no la manda**, y
   un libro con la firma rota no se puede subir desde el front. Hay que reconciliarlo: o el front
   añade la sección, o el peldaño 2 deja de ser bloqueante (y entonces un libro manipulado entra sin
   que nadie confirme nada).
2. **`familias.estructura[].columna` va `null` en el renglón `indicador_nuevo`.** La interfaz del
   front lo declara `string`. Es el único campo de todo el contrato que no se puede servir como está
   escrito, y por un motivo que es el propio caso: ese indicador **no tiene columna en la hoja**, que
   es exactamente el problema del que avisa.
3. **La escritura no está cronometrada.** El ensayo sí (§2.5). Medirla exige escribir en un colegio.
4. **La D4 —coordinación sube por otro— es la fase 5** y hoy da **403 con el motivo dentro**. La
   puerta de la descarga es más ancha (`Autoriza::puedeDescargarLaPlanillaDeOtro`) y la de escribir
   no puede serlo sin el acta que esa fase trae.
5. **Las ausencias y tardanzas (D5) son la fase 4.** El ensayo las cuenta y las declara como aviso
   con el motivo; la importación no escribe ni una.
6. **Las filas que no se reconocen (F6) son la fase 3.** `familias.filas` trae los avisos de «se
   retiró» y «entró después», y el emparejamiento por nombre no se intenta: *no se crea a nadie*.
7. **Los peldaños 3 y 4 devuelven su número y un bloqueo que dice que llegan en la fase 3.** No se
   intenta reconstruir nada por `ID` ni por nombre.
8. **El mapa de `_myvc` no dice de qué unidad es una columna de reserva** (§2.1). Arreglarlo es tocar
   el generador de la fase 1 y mover la firma de los libros que ya andan por fuera.

---

## 7 · Lo que se corrió

Con `DB_TEST_DATABASE=simonbolivar_testing_f2` (base de esta sesión, construida el 21 sep 2026 con
`tools/construir-bd-test.sh`, **48/48 migraciones, 118 tablas**).

```bash
docker exec -e DB_TEST_DATABASE=simonbolivar_testing_f2 8myvc-app-1 \
    php artisan test --filter='PlanillaOfflineImportarTest|RespuestasDeLaPlanillaTest'
# Tests: 41 passed (398 assertions) — 23,51 s
```

`tools/tests-que-tocan.py` dice **NO HAY SUBCONJUNTO SEGURO** —por `composer.json`,
`routes/api/academico.php` y la migración de la fase 1—, así que la suite entera es **decisión de
Joseth**. Lo que se corrió son las clases del dominio tocado, en una tanda.

- `composer run pint:test` → **PASS, 529 ficheros**. Los cuatro servicios nuevos y los dos tests
  entran por las carpetas `app/Services` y `tests`, que ya estaban en la lista curada: **no hizo
  falta tocar `composer.json`**, que además lo tiene a medias otra sesión.
- `composer run stan` → **8 errores, ninguno de esta entrega**: 7 en `app/Support/CandadoDeLaPlantilla.php`
  y su test —trabajo sin commitear de otra sesión— y 1 en `tests/Feature/ImportacionesAbandonadasTest.php`,
  que viene del commit `b1978b8`. Es el mismo recuento que dejó escrito el [49](49-la-planilla-sin-internet.md).

### Los 13 saltados

Son los del candado de la plantilla, que otra sesión tiene suspendido a mano
(`CandadoDeLaPlantilla::SUSPENDIDO`). **No los salta esta entrega**, igual que no los saltaba la fase 1.

---

## 8 · Al desplegar

**No hay migración nueva.** La fase 2 reutiliza `importaciones` —la tabla del punto de control del
importador de alumnos, con `tipo = 'planilla'`— y no crea ninguna tabla propia. Lo que sí sigue
haciendo falta es la migración de la fase 1 (`descargas_de_planilla`), y el síntoma de que falta
sigue siendo el del [49 §5.bis](49-la-planilla-sin-internet.md): **500 sólo en las dos rutas que
bajan fichero**.

Y una consecuencia de reutilizar `importaciones` que conviene saber antes de leer esa tabla:
`PuntoDeControlDeImportacion::marcarAbandonadas()` —el comando que pasa a `fallida` lo que lleva diez
minutos sin escribir— **no filtra por tipo**, así que también limpia las planillas a medias. Es lo
que se quiere, y se anota porque el comando se llama `importaciones:abandonadas` y su documentación
sólo habla de alumnos.
