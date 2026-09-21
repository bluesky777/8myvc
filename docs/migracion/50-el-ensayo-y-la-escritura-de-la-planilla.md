# La planilla sin internet — fases 2 y 3: el ensayo, la escritura y «¿es este?»

*21 sep 2026. Continúa [49](49-la-planilla-sin-internet.md), que construyó la descarga. El plan
aprobado vive en `~/DESARROLLOS/myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y **manda sobre este
documento**. Aquí va lo que se construyó, lo que se midió, y los sitios donde el plan resultó estar
equivocado —**cinco en la fase 2 (§2) y dos en la fase 3 (§7.6)**—, cada uno con su medida al lado.*

> **La fase 3 se escribió la misma noche, encima de esto.** La F6 —«las filas que no se
> reconocen»— dejó de ser un aviso y pasó a ser una decisión de verdad: **la §7 entera es suya**, y
> lo de la fase 2 se deja como estaba salvo donde la 3 lo contradice, que está tachado y marcado.

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
| `App\Services\EnsayoDeLaPlanilla` | Las familias F1–F7 y F9, más el aviso de F8 |
| `App\Support\ParecidoDeNombres` | *(fase 3)* Cuánto se parecen dos nombres, y el umbral **medido** |
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
6. ~~**Las filas que no se reconocen (F6) son la fase 3.**~~ **HECHO la misma noche**: la §7 de este
   documento. `familias.filas` ya no son avisos, son decisiones por fila — y se sigue sin crear a
   nadie.
7. **Los peldaños 3 y 4 devuelven su número y un bloqueo que dice que llegan en la fase 3.** No se
   intenta reconstruir nada por `ID` ni por nombre. *La fase 3 hizo la F6 y **no** los peldaños: son
   dos cosas distintas —una fila que no casa dentro de un libro legítimo, contra un libro sin mapa—
   y siguen abiertos.*
8. **El mapa de `_myvc` no dice de qué unidad es una columna de reserva** (§2.1). Arreglarlo es tocar
   el generador de la fase 1 y mover la firma de los libros que ya andan por fuera.

---

## 7 · La fase 3: «¿es este?» — las filas que no se reconocen (F6)

*21 sep 2026, la misma noche. El §6.4 del plan es el diseño y la frase del encargo es la ley:*

> «**No debe crear el alumno**, pero sí intentar encontrarlo en el grupo y preguntarle si ese es,
> para proseguir.»

**Nunca se crea un alumno. Nunca se fusionan dos. Y se busca dentro del grupo de esa hoja**, no en
el colegio: escribir la nota de alguien que no está matriculado ahí sería corromper la planilla en
silencio —un dato que parece bueno, que nadie revisa y que sale en un boletín—. Por eso la
comprobación de «este alumno está en este grupo» se repite en el servidor aunque la pantalla ya la
haya hecho: la decisión llega del cliente y no se cree.

### 7.1 · Los tres casos, que son distintos y se contestan distinto

| `tipo` | Qué pasó | ¿Se pregunta? | Si no se hace nada |
|---|---|---|---|
| `escrita_a_mano` | El docente escribió un nombre en el bloque del final | **Sí.** Hasta 3 candidatos del grupo, con foto | Esa fila no se importa |
| `ya_no_esta_en_el_grupo` | El `ID` estaba al descargar y hoy no | **No.** Motivo y fecha de retiro | Sus notas se quedan fuera |
| `entro_despues` | Está en el grupo y no en el archivo | **No.** Es un aviso con su nombre | Nada: no tiene casillas |

Y el «no hay nadie» **dice quién puede arreglarlo** —*«si es un alumno nuevo, secretaría tiene que
matricularlo primero»*—, porque un error que no ofrece salida obliga a llamar por teléfono.

### 7.2 · El contrato, palabra por palabra

`familias.filas[]` pasó de `{hoja, descripcion}` a esto. **Es la única familia cuya llave es la
fila**, y lo es porque cada fila es una persona distinta: agrupar por valor —que es lo que salva a
las otras siete pantallas— aquí sería preguntar por dos personas a la vez.

```
{ id,                       la llave de la decisión, POR FILA
  hoja, asignatura, grupo,  «3° B», para la frase de la pantalla
  fila,                     la fila del libro — null en `entro_despues` (ver 7.6)
  tipo,                     'escrita_a_mano' | 'ya_no_esta_en_el_grupo' | 'entro_despues'
  escrito,                  lo que el docente tecleó, tal cual, o null
  notas_en_la_fila,         casillas con algo escrito, contando las de reserva
  decidible,                sólo 'escrita_a_mano' CON candidatos
  resuelta,                 (de más) si la decisión que llegó se va a aplicar
  titulo,                   (de más) la frase corta; es la que sale en `avisos()`
  si_no_hago_nada,          la escribe el servidor, como en el resto de familias
  alumno: { alumno_id, nombre, no_matricula, foto, sexo, motivo } | null,
  candidatos: [ { alumno_id, nombre, no_matricula, foto, sexo,
                  desde,      «matriculado desde el 3 de febrero»
                  parecido,   0..1
                  ya_esta_en_la_hoja: { fila, notas: string[] } | null } ] }
```

Y en `respuestas`: `filas?: [{ id, decision: 'es:<alumno_id>' | 'fuera' }]`.

Cuatro cosas que no son adorno:

- **`nombre` va como lo ordena la planilla**, `APELLIDOS, Nombres`: es el orden en el que el docente
  ve la lista en su hoja.
- **`foto` va como la sirve `Grupo::alumnos`** —el nombre del fichero, con la caída al avatar por
  sexo— para que el front use el pipe `perfil` que ya tiene.
- **`sexo` va crudo, sin traducir.** El botón dice «Sí, es él» o «Sí, es ella» delante de una
  persona con nombre y foto, y **adivinarlo por el nombre falla justo ahí**. El rótulo es cosa de la
  pantalla; un servidor que mandara «Masculino» obligaría al front a deshacer la traducción.
- **`decidible` sólo con candidatos.** Tres botones sin nadie a quien señalar es una pantalla rota,
  así que la invariante se mantiene aquí y el front no tiene que defenderse de ella.

Y **`filas` salió de `RespuestasDeLaPlanilla::NO_APLICADAS`**. No es cosmética: el front borra el
paso «Alumnos» entero en cuanto ve esa sección declarada como no aplicada, en vez de ofrecer botones
que el servidor no va a obedecer. Si volviera a esa lista, la F6 se serviría y no se vería. Lo
sujeta `RespuestasDeLaPlanillaTest::las_filas_salieron_de_no_aplicadas_al_llegar_la_fase_3`.

### 7.3 · `ya_esta_en_la_hoja` es lo que hace útil la tarjeta

**El caso de verdad frecuente no es el alumno nuevo: es el que ya estaba en la lista.** Está como
*Cárdenas*, el docente escribió *Cardenaz*, no se vio y lo apuntó abajo. Si la respuesta no dice que
esa persona **ya tiene notas en la fila 8**, quien mira acepta y pisa notas sin enterarse.

Van la fila y los valores que ya hay, leídos **del archivo** —la frase del §6.4 es *«sus notas en
esta hoja»*, y lo que hay que poder comparar de un vistazo es la fila de arriba con la que se
escribió abajo—, con el guion largo marcando la casilla vacía igual que en el libro.

### 7.4 · Y la otra mitad de esa red: una fila escrita a mano NO TIENE ESPEJO

Es la decisión de diseño de toda la fase y no está en el plan. La tarjeta **avisa antes**; esto
**para después**:

> Al descargar el libro, las casillas del bloque del final están vacías. Así que el espejo de una
> fila escrita a mano es `null` **aunque el alumno que se eligió sí tenga espejo en su fila de la
> rejilla**.

Y de ahí sale, sin escribir ni una regla nueva, exactamente lo que el encargo pide:

| Estado de la casilla del alumno elegido | `espejo` | `base` | Qué pasa |
|---|---|---|---|
| Sin calificar | `null` | `null` | `base === espejo` → **se escribe**. El caso normal |
| **Ya tiene nota** | `null` | 33 | `base !== espejo` → **choque (F7)**, y por defecto manda el sistema |

O sea que resolver una fila **no puede pisar una nota en silencio**: entra por el camino de los
choques, con su renglón en la pantalla del §6.3 y con el defecto seguro. Comparar contra el espejo
de la fila de arriba habría hecho lo contrario —*«el docente cambió la nota»*, y a escribir—.

Lo sujeta `f6a_resolver_una_fila_que_pisa_una_nota_existente_es_un_choque_y_no_una_escritura_silenciosa`,
y **se vio en rojo**: quitando el `$filaAMano === null` de la línea del espejo, el test dice
`0 choques` donde tiene que haber 1.

Una consecuencia menor de esto: el `id` de un choque de una fila escrita a mano **lleva su fila
dentro** (`sha1(hoja|alumno|subunidad|f37)`), porque si no chocaría con el de la fila de la rejilla
del mismo alumno y el mismo indicador —el caso de *Cárdenas*, que es el frecuente—. **Los de la
rejilla no cambian**, así que unas respuestas guardadas antes de este cambio siguen apuntando a lo
mismo.

### 7.5 · El emparejador, y el umbral MEDIDO

`App\Support\ParecidoDeNombres`. Hacía falta uno nuevo y no valía ninguno de los dos que hay:

- `AlumnosParecidos` busca **en todo el colegio** y contesta «existe una ficha así», que es otra
  pregunta.
- El `=` de MySQL ya ignora tildes y mayúsculas por la colación (doc [33](33-la-tilde-que-sql-no-ve.md)),
  y con eso casa *Jose* con *José*… y nada más: ni *Cardenaz* con *Cárdenas*, ni el orden cambiado.
- Un `LIKE %palabra%` por cada palabra **exige que estén todas**, y lo normal es que el docente
  escriba un apellido de los dos.

Se normaliza antes de comparar —minúsculas, sin tildes, sin puntuación— con un `strtr` a mano y **no
con `iconv('ASCII//TRANSLIT')`**, por lo de siempre: `iconv` depende del locale del servidor y los
dieciséis colegios no corren en el mismo. Y se mezclan **dos medidas**, no una:

| | Qué caza | Dónde falla sola |
|---|---|---|
| Cadena entera (`similar_text`) | la errata dentro de una palabra | el orden cambiado: «jose luis cardenaz» contra «cardenas pena jose luis» se queda en **0,391** |
| Conjunto de palabras, **en las dos direcciones** | el orden cambiado y el apellido de más | dos nombres cortos comparten «maria» y ya |

`0,35 · cadena + 0,65 · conjunto`. El par del plan sale **0,713**.

**El umbral es 0,58 y está medido**, no elegido. Contra `caz_zaragoza`: 2.121 matrículas en 129
grupos, cinco formas de teclear cada nombre —orden cambiado; un apellido de menos; nombre y
apellido sueltos; las dos últimas con una errata de una letra— = **10.425 emparejamientos buenos**,
contra **2.165 forasteros**, que es un nombre de otro grupo buscado en éste y modela al alumno que
de verdad no está.

| Umbral | Pierde al bueno | Cuela a un forastero |
|---|---|---|
| 0,54 | 0,0 % | 10,3 % |
| 0,56 | 0,1 % | 7,2 % |
| **0,58** | **0,1 % (8 de 10.425)** | **4,8 %** |
| 0,60 | 0,5 % | 3,1 % |
| 0,62 | 4,0 % | 2,1 % |
| 0,66 | 16,5 % | 1,0 % |

**El error caro es el de abajo**, y por eso la raya está donde la curva de los buenos todavía no ha
empezado a caer: si el alumno sí está y no se le enseña, la pantalla dice «no hay nadie con ese
nombre» y manda al docente a secretaría a matricular a alguien que ya está matriculado. Enseñar de
más cuesta una lectura, y la tarjeta lleva foto, matrícula y nombre completo.

Los ocho buenos que se pierden a 0,58 son todos del mismo tipo degenerado —«JUAN DE», salido de «DE
LOS RIOS PEREZ, JUAN FELIPE»—, que nadie teclea. Y los forasteros que se cuelan son hermanos y
homónimos de verdad —«NAHILY MEZA MANCHEGO» contra «MEZA MANCHEGO, NATALY», **0,805**—, donde
preguntar es exactamente lo que hay que hacer.

Se devuelven **tres como mucho**, ordenados por parecido y con la llave como desempate, para que dos
lecturas del mismo fichero enseñen lo mismo en el mismo orden.

*El guion de la medición no se commitea: vivía en el scratchpad y lo que vale es la tabla. Se
reproduce volcando `alumnos`+`matriculas`+`grupos` de un colegio y puntuando cada nombre contra los
de su grupo con `ParecidoDeNombres::entre()`.*

### 7.6 · Los dos sitios donde el plan de la fase 3 no se pudo servir como está escrito

**a) `fila` va `null` en `entro_despues`.** El contrato lo declara número. Ese alumno **no tiene
fila en el libro**, que es exactamente el problema del que avisa. Es lo mismo que ya le pasaba a
`estructura[].columna` en el renglón `indicador_nuevo` (§6.2 de esta lista), y se resuelve igual:
`null` y dicho. Mandar un 0 o la última fila leída sería peor —un número que parece una fila del
Excel y no lo es acaba copiado en un correo y no lleva a ninguna parte—.

**b) `desde` es una frase, no una fecha.** El contrato lo escribe como *«matriculado desde el 3 de
febrero»*, así que eso es lo que se manda, con el mes en palabras y armado en el servidor. Los meses
van a mano y no con `strftime()` ni `IntlDateFormatter`, por lo mismo que el `strtr`: dependen del
locale y una fecha que sale en inglés en un colegio y en español en otro es un fallo que sólo se ve
en producción.

### 7.7 · Lo que el servidor NO se cree, y da 422

Dos puertas, y las dos dan **bloqueo** —o sea 422 y no se escribe **nada**, que es la decisión (d)
de la §4— y no un renglón que se ignora en silencio:

| `bloqueos[].tipo` | Cuándo | Por qué no basta con ignorarlo |
|---|---|---|
| `fila_de_otro_grupo` | El `es:<alumno_id>` señala a alguien que no está matriculado en el grupo de esa hoja, con estado válido | Es la corrupción silenciosa que el §6.4 existe para evitar. Y devolver la fila como «no decidida» dejaría a la pantalla enseñando una pregunta que la persona ya contestó |
| `dos_filas_el_mismo_alumno` | Dos filas escritas a mano señalan a la misma persona | Se escribirían las dos, una encima de la otra, y ganaría la de abajo por el orden del bucle. Es un error de quien decide y hay que devolvérselo, no repartirlo a suertes |

### 7.8 · La escritura no tiene camino aparte, y no puede tenerlo

Una fila resuelta llega a `EscrituraDeNotasImportadas` **como una fila más del plan**, con su
`alumno_id` dentro, y se escribe con la misma siembra, el mismo rastro (`bitacoras` + `auditoria`) y
el mismo recálculo único por par. Quién es esa persona, que esté matriculada y si su nota pisa una
que ya existe lo decide **el ensayo**, que es quien tiene el grupo delante — la misma regla que
gobierna toda la fase 2: *lo que se promete y lo que se hace salen del mismo recorrido*.

El `indice` de una fila escrita a mano es `nº de filas de la rejilla + su fila del Excel`. Suena
raro y hace falta: `PuntoDeControlDeImportacion::yaProcesada` es **una marca de agua** —«voy por la
N»— así que los índices tienen que crecer en el orden en que se procesan y no moverse entre dos
subidas del mismo fichero. Con un contador de bucle, un recorte por tiempo movería el índice de esas
tres filas y la siguiente petición reescribiría o se saltaría una.

### 7.9 · Lo que queda abierto de la fase 3

1. **El umbral se midió contra un colegio, no contra dieciséis.** `caz_zaragoza` es el que había a
   mano con nombres reales. La forma de la curva no debería cambiar —son apellidos españoles en
   todos— pero el número es de una muestra.
2. **El «No, es otro…» del front busca en el grupo, y el servidor no le da esa lista.** Hoy la saca
   de los `candidatos`, que son tres. Para buscar en los cuarenta hace falta o una ruta de «alumnos
   del grupo de esta hoja» o mandar el grupo entero con el diagnóstico. No se hace aquí porque son
   dos decisiones de tamaño de respuesta y las dos se pagan en cada ensayo.
3. **Nada impide que un docente resuelva una fila en un alumno que ya está en la hoja y además
   escriba en su fila de la rejilla.** Las dos entran; gana la de abajo, y la casilla pisada sale
   como choque si el valor no coincide. Es correcto y es raro; si apareciera de verdad, la tarjeta
   tendría que decirlo con más fuerza que un choque.
4. **La F6 no se reanuda distinto que el resto**, pero sus filas se estudian **después** del recorte
   por tiempo: si el ensayo se corta en la rejilla, las escritas a mano se quedan enteras para la
   petición siguiente. Es lo correcto y significa que un libro enorme las ve tarde.

---

## 8 · Lo que se corrió

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

### 8.bis · Lo de la fase 3, y LA SUITE ENTERA — que estaba roja de antes

Base propia: `DB_TEST_DATABASE=simonbolivar_testing_fase3`, construida el 21 sep 2026,
**48/48 migraciones, 118 tablas**.

```bash
php artisan test --filter='PlanillaOfflineImportarTest'        # 31 passed (525 assertions) — 25,39 s
php artisan test --filter='ParecidoDeNombresTest|RespuestasDeLaPlanillaTest'
                                                               # 33 passed  (59 assertions) —  0,90 s
php artisan test                                               # 2.793 passed, 6 failed, 14 skipped
                                                               # (56.085 assertions) — 1.014,91 s
php artisan test      # tras arreglar las seis  ->  2.799 passed, 0 failed, 14 skipped
                                                               # (56.103 assertions) —   976,02 s
```

`composer run pint:test` → **PASS, 531 ficheros**. `composer run stan` → **8 errores, los mismos de
siempre y ninguno de esta entrega**: 7 del `CandadoDeLaPlantilla` de otra sesión y 1 de
`ImportacionesAbandonadasTest`, del commit `b1978b8`.

**Y esta vez sí se corrió la suite entera, que es lo que la fase 2 dejó sin correr.** Salió **roja
por seis, todas del commit `3e16747` y ninguna de la fase 3** — y se arreglaron aquí, con las cuatro
decisiones tomadas por Joseth el 21 sep, para no heredarlas a la fase 4.

| Centinela | Qué decía | La decisión |
|---|---|---|
| `CentinelaDeLasTablasDelAnioNuevoTest` ×2 | `descargas_de_planilla` tiene `year_id` y nadie la clasificó | **No se copia**, y va a `DATOS_DEL_ANIO`: es el rastro de qué libros salieron del colegio **ese** año, de auditoría, y con filas que apuntan a periodos y asignaturas del año viejo. Copiarla fabricaría descargas que nadie hizo y encima ilegibles |
| `CentinelaDeLosEscritoresDeBitacoraTest` ×2 | Los `INSERT INTO bitacoras` pasaron de 12 a **14** | Los dos nuevos son de `EscrituraDeNotasImportadas` y escriben **en Bogotá** —usa `Reloj::ahora()`, **comprobado antes de declararlo**—, con los tipos que ya existían (`Nota`, `Nueva subunidad`). Declarados en `tools/salud-de-la-bitacora.php` |
| `RelojUnicoTest` | `PuntoDeControlDeImportacion` pasó de 11 a **13** `now()` sin zona | **Se queda en UTC** y sube el esperado a 13. Lo dice su propia cabecera: *«`inicio` y `fin` solo se restan entre sí»*. Y las dos nuevas son de `marcarAbandonadas()`, donde el corte y la columna con la que se compara salen **las dos** de `now()` |
| `CensoDeInterruptoresTest` | Las `tinyint(1)` sin lector: 91 → **90** | Bajó, no subió. **Medido y no supuesto**: el censo se corrió sobre `3e16747~1`, `3e16747` y el árbol de trabajo, y el único nombre que se mueve es **`perdido`** (`escalas_de_valoracion`), a la que la fase 1 le dio lector — `HojaDeAsignatura:885` decide con ella para pintar la banda reprobatoria en rojo y en negrita |

**La 2 y la 3 dicen lo contrario y las dos son correctas**, y ésa es la frase que hay que guardar:
la bitácora escribe renglones que **una persona lee al lado de otros** —dos relojes ahí son cinco
horas entre dos líneas que cuentan lo mismo—, y el punto de control escribe marcas que **sólo se
restan entre sí**. Por eso uno puede quedarse en UTC y el otro no.

*Y la lección que las cuatro comparten: ninguna se coló, todas **se contaron tarde**. La fase 2 no
corrió la suite entera antes de commitear porque `tests-que-tocan.py` decía «no hay subconjunto
seguro» y los 17 minutos quedaron como decisión pendiente. Cuatro centinelas que existen justo para
esto estuvieron rojos un commit entero sin que nadie lo viera.*

**Después de las cuatro: `2.799 passed, 0 failed, 14 skipped` (56.103 aserciones) en 976,02 s.** Los
14 saltados siguen siendo los 13 del candado de la plantilla más el que el seed no puede fabricar,
que es lo de siempre. Los 8 de `stan` tampoco se mueven: 7 del `CandadoDeLaPlantilla` de otra sesión
y 1 de `ImportacionesAbandonadasTest`.

---

## 9 · Al desplegar

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
