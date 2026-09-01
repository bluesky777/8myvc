# Lote E — los puestos y su interruptor (fase 6)

**Rama `fix/bi-lote-e`, sobre `main` en `ce56351`.** Sesión `8myvc-8f`.

Lo que este lote cierra es la [§7](../19-boletin-independiente.md) entera: la columna
`years.puestos_con_bol_independiente`, el método del servicio que la lee, los **seis**
sitios que copiaban `Nota::puestoAlumno` (los otros dos son del lote A) y el
interruptor viajando en la respuesta de los informes de puestos.

---

## 1 · Lo que se hizo, con su porqué

### 1.1 · La migración vuelve, y esta vez con quien la consume

`2026_08_31_200000_puestos_con_bol_independiente` — `TINYINT(1) NOT NULL DEFAULT 1`.

Esta columna **ya entró el 24 ago y se retiró el mismo día**, y no por un fallo: movía
las tres instantáneas de `MuestreoDeLecturasTest` —`YearsController:27` y `:43` leen con
`SELECT *`— **sin que nada la leyera**. Una columna que nadie consume moviendo tres
respuestas vivas es coste sin contrapartida. Vuelve porque ahora la consume esta misma
noche.

**El default es 1 y eso NO es una preferencia**: 1 es lo de hoy. Con 0 por defecto, el
despliegue le cambiaría el puesto impreso a los quince colegios sin que ninguno lo
hubiera pedido — el mismo modo de fallo que el `COALESCE(bip.aplica, 1)` de la decisión
7, un carácter que cambia el significado entero y no da ningún error. Hay un test que lo
fija (`test_el_interruptor_nace_encendido_en_todos_los_anios`) y dice su población.

`Year::datos` y `Year::datos_basicos` leen **por columnas nombradas**, así que la
columna no se cuela sola en los boletines ni en los puestos: donde viaja es porque
alguien la nombró.

### 1.2 · El método del servicio, y la regla que se conserva

`BoletinIndependiente::puestosCuentanIndependientes(int $yearId): bool` contesta
**«¿está activado el interruptor?»** y nunca «¿se enseña el puesto?». El front esconde el
puesto al `Acudiente` y al `Alumno` aunque el año lo tenga activado; si contestara lo
segundo, o le filtraría el puesto a las familias por su cuenta o dejaría muerta esa regla
del front, **las dos en silencio**. Está escrito en el docblock para que no se re-litigue.

Un `year_id` que no existe cuenta como **encendido**, igual que el `DEFAULT 1`: que un
año malo apagara los puestos de un informe entero sería el fallo caro, porque **nadie
mira un puesto y piensa «esto es que el año no existe»**.

Con él entran tres ayudantes más en el mismo fichero:

| Método | Qué contesta |
|---|---|
| `aplicaEnAlguno($alumnoId, $periodoIds)` | ¿va aparte en **alguno** de estos periodos? |
| `losQueCuentanParaElPuesto($alumnos, $periodoIds, $yearId)` | la lista contra la que se cuenta |
| `ponerPuestos($alumnos, $periodoIds, $yearId)` | pone `puesto` a cada fila (o `null`) |

Y `olvidar()` limpia **dos** memorias, no una. La del interruptor es la que muerde: un
test que lo apaga y otro que lo deja encendido corren en el mismo proceso, así que
olvidar sólo `$memoria` dejaría al segundo leyendo la respuesta del primero — verde o
rojo **según el orden de la suite**, que es la peor forma de fallar que hay.

### 1.3 · `Nota::puestoAlumno` NO se toca, y ésa es la forma correcta

Lo levantó el coordinador a mitad del lote y tenía razón: `puestoAlumno($promedio,
$alumnos)` es una **función pura** —cuenta cuántos promedios hay por encima— y no sabe de
matrículas, periodos ni boletines. Meter el interruptor ahí dentro sería esconder la
regla en un sitio donde sus otros llamadores no la ven.

**Lo que cambia es quién entra en `$alumnos`**, y eso se decide antes de llamarla.

### 1.4 · Pero la decisión va en el servicio, no copiada en ocho sitios

Lo que estaba copiado ocho veces era un `foreach` de **una línea**. Lo que habría que
copiar ahora son **tres decisiones** —sácalo de la lista, si es él pon `null`, y qué
periodos deciden—, y ocho copias de tres decisiones es exactamente lo que le pasó a la
definitiva con sus seis escritores y cinco criterios. Por eso los seis llaman a
`ponerPuestos()` y ninguno escribe el ternario.

El `null` es la **decisión 6** y no un caso degenerado: calcularle un puesto a alguien
contra una lista de la que se le acaba de sacar sería inventarlo. Y es `null` y **no
`0`**, porque `0` es un puesto en la escala del filtro `puestoAlumno` del front antiguo
—arranca en 0— y se pintaría como un número.

### 1.5 · Los seis sitios

```
app/Http/Controllers/Informes/BoletinesController.php           :235
app/Http/Controllers/Informes/Boletines2Controller.php          :164
app/Http/Controllers/Informes/Boletines3Controller.php          :169
app/Http/Controllers/Informes/CertificadosPersonaController.php :191
app/Http/Controllers/EditnotaController.php                     :215
app/Http/Controllers/PromovidosController.php                   :136
```

En `PromovidosController` el `foreach` **se queda vacío al quitarle la línea** y por eso
desaparece entero; en los otros cinco el bucle sigue haciendo el filtrado de
`requested_alumnos`.

---

## 2 · Las decisiones que tomé yo y no estaban escritas

### 2.1 · Qué periodo decide: **los que el informe promedia**, ni el del token ni el año

De los seis, **cuatro son de un periodo** y **dos promedian varios**, y no da igual:

- Boletines 1, 2, 3 y `Editnota` calculan el promedio en `allNotasAlumno(...,
  $user->periodo_id, ...)`. Reciben `[(int) $user->periodo_id]`.
- `PromovidosController` y `CertificadosPersonaController` promedian las definitivas de
  **todos los periodos de `$year->periodos`**. Reciben esa lista entera.

El razonamiento, en las dos direcciones, porque las dos equivocaciones cuestan:

- **Preguntar sólo por el periodo del token** en un informe anual dejaría dentro del
  recuento a quien tuvo el accidente en el segundo periodo y hoy va con el grupo. Su
  promedio anual lleva dentro una definitiva **que no se calculó sobre el reparto del
  grupo**, así que no es comparable con el de los demás.
- **Preguntar por el año entero** sacaría del recuento a quien lo tuvo en un periodo que
  ese informe **no está promediando** — y `CertificadosPersonaController` promedia «hasta
  el periodo N» cuando se lo piden. Eso no le cambia el puesto a él: **se lo cambia a los
  treinta de detrás**, por un dato que no entra en la cuenta.

Los periodos que se promedian son los que deciden. Lo fijan
`test_apagado_la_marca_de_otro_periodo_no_cambia_este` y
`test_apagado_un_informe_de_varios_periodos_ve_la_marca_de_cualquiera`, uno por dirección.

### 2.2 · `PuestosController` FILTRA la lista, no manda una fila marcada

**Y esto lo decidió el front sin saberlo.** Las dos rutas de puestos no calculan puesto:
devuelven `promedio`, y el filtro `puestoAlumno` de
`myvc_front/app/scripts/informes/PuestoAlumnoFilter.ts` cuenta **sobre el array que le
llega**. O sea que aquí «sacar al independiente del recuento» es **no mandarlo**:
mandarlo y esperar que el navegador lo descarte sería la misma regla escrita en dos
sitios, que es de lo que salió el recalculador único.

Con el interruptor en 1 —el default— la lista llega **entera, fila por fila como antes**.
Sólo con 0 viene más corta, y entonces `puestos_con_bol_independiente: false` es lo que
le permite a la pantalla explicar por qué falta alguien.

### 2.3 · Los dos campos nuevos de la respuesta de puestos

| Campo | Dónde | Qué dice |
|---|---|---|
| `puestos_con_bol_independiente` | raíz de las dos respuestas | el interruptor del año |
| `alumno.bol_independiente_periodo` | cada fila | ¿va aparte en alguno de los periodos que **este** informe promedia? |

Van los dos porque son cuatro pantallas colgando de dos rutas: **si lo pregunta una y
otra no, las otras tres mienten** (§7).

**`bol_independiente_periodo` no es el campo constante que el front cazó el 31 ago.** Aquel
era constante *por construcción* —a la planilla no llegan los marcados, nunca—; aquí, con
el interruptor encendido, los marcados **sí** están en la lista y el campo los distingue.
Con el interruptor apagado sale `false` en todas las filas porque los que serían `true`
ya no están, y ahí lo que explica la ausencia es el otro campo.

### 2.4 · Lo que decidí NO tocar

- **`Nota::puestoAlumno`** — ver 1.3. Sigue pura y sigue con sus dos llamadores del
  lote A intactos.
- **Los dos `Bolfinales`** — son del lote A. `ponerPuestos()` está escrito para que les
  sirva tal cual: son informes **anuales**, así que les toca la lista de periodos del año,
  como a `Promovidos`.
- **El `use App\Models\Nota;` que queda sin usar** en `CertificadosPersonaController` y
  `PromovidosController`. Pint **no cubre `app/Http/Controllers`** (ver `composer.json`) y
  la regla del `CLAUDE.md` es que cada fichero se formatea el día que le toca su pasada,
  no el día que se le cambia una línea. Quitarlo aquí sería empezar esa pasada en dos
  ficheros de 500 líneas dentro de un diff que va de otra cosa.
- **El `$index + 1` del front** y el que la respuesta de puestos no traiga `puesto`
  calculado. Es contrato del front y no estaba en el encargo; el interruptor le llega, que
  es lo que la §7 pide.

---

## 3 · El test, y las DOS comprobaciones en rojo

`tests/Contrato/BolIndependientePuestosTest.php` — **9 casos, 101 aserciones**.

El que importa es **`test_apagado_el_puesto_de_un_tercero_sube`**: tres alumnos con
promedios 90/80/70, el primero marcado. Con el interruptor encendido son 1, 2 y 3; al
apagarlo el marcado va a `null` y **el segundo pasa a primero y el tercero a segundo**.
Ninguno de los dos está marcado y a ninguno le han tocado una nota. Ése es el efecto que
la §7 pide decir en voz alta.

**Comprobado en rojo dos veces, y las dos hacían falta:**

1. **Contra el código viejo, por HTTP.**
   `test_apagado_los_demas_suben_un_puesto_en_el_boletin_impreso` va contra
   `PUT boletines/detailed-notas/{grupo}` y es el único que se puede correr contra el
   controlador de antes de este lote — los otros llaman a `ponerPuestos()`, que no
   existía, así que no distinguirían «el arreglo funciona» de «el arreglo existe».
   Devolviendo `BoletinesController` a `HEAD`: **rojo**, `Failed asserting that 1 is null`.
2. **Contra el ARREGLO A MEDIAS**, que es el que de verdad hay que cazar. Con
   `ponerPuestos()` contando sobre `$alumnos` entero en vez de sobre la lista filtrada
   —o sea, poniendo a `null` el puesto del marcado y dejándolo contando para los demás—
   caen **tres** casos, y el primero por su aserción del tercero:
   `Failed asserting that 2 is identical to 1`. Sin esa segunda comprobación, un arreglo
   que sólo mirara al marcado habría pasado.

Los tres tests que miden el efecto **fallan con un mensaje explícito si el grupo del seed
tuviera los promedios empatados o menos de tres alumnos**: un grupo donde todos van
primeros cuadra con y sin interruptor, y un test que pasa sobre él no comprueba nada.

> **La base de tests de este lote hay que reconstruirla desde el árbol, no desde la
> raíz.** `tools/construir-bd-test.sh` corre `artisan migrate` **dentro del contenedor y
> en el directorio que le digas**, así que lanzarlo desde `/8myvc` aplica las migraciones
> de `main` y deja la base **sin la columna nueva** — nueve rojos con
> `Unknown column 'puestos_con_bol_independiente'`. Se hace así:
>
> ```bash
> PHP_EXEC="docker exec -i -w /app/.worktrees/e 8myvc-app-1" \
>   DB_TEST_DATABASE=simonbolivar_testing_e ./tools/construir-bd-test.sh
> ```
>
> Es otra vez «el primer sitio donde mirar cuando el número sale raro es el instrumento».

---

## 4 · Lo que le toca al lote A, y la firma que le sirve

```php
BoletinIndependiente::ponerPuestos($alumnos, $periodoIds, $yearId);
```

`$alumnos` son las filas con `alumno_id` y `promedio` —las de `Grupo::alumnos`—, y
`$periodoIds` es una `list<int>` con **los periodos que ese informe promedia**. Los dos
`Bolfinales` son anuales, así que les toca la lista entera del año:

```php
array_map(fn ($periodo) => (int) $periodo->id, $year->periodos)
```

El método pone `puesto` en cada fila y `null` en las de quien no cuenta. **No saca a
nadie de `$alumnos`**: la fila del independiente sigue viajando con sus notas y su
boletín, y lo único que le falta es el puesto.
