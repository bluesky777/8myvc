# Escribir en un año pasado — CONTESTADO dos veces: se puede, y desde el 14 sep sólo el superusuario

> ## AFINADO el 14 sep 2026 — es la **opción B** de la §«Las tres formas de cerrarlo»
>
> **Joseth, con las escrituras y las poblaciones delante** (74 cuentas de personal,
> 11 superusuarias):
>
> > **Un año cerrado sólo lo escribe un superusuario.**
>
> Contestado en **dos tandas el mismo día**, y las dos se anotan porque el alcance creció
> entre una y otra: primero **las cinco** de `frases`, `escalas` y `contratos` —preguntado
> expresamente si el cierre cubría sólo los cuatro endpoints que tocan boletines o también
> el de contratos, y contestó los cinco—; y después **las cinco de `ordinales`**, que la
> primera pregunta había dejado fuera a propósito por ser la que rompe una pantalla. Son
> **diez escrituras y cuatro catálogos**.
>
> **Esto NO revierte la respuesta del 24 ago**, que sigue abajo y sigue vigente: moverse
> por un año pasado y escribir en él **es el producto**, no un agujero. Lo que aquella no
> decía es **quién**, y su propia frase lo dejaba abierto — *«un usuario **con permisos**
> puede ir al año pasado y cambiar las frases y situaciones, lo mismo que las escalas»*—.
> En el backend ese «con permisos» no existía: las diez escrituras iban con
> `auth.personal`, o sea **cualquiera de los 74**.
>
> ### Y es literalmente la opción B de más abajo, escrita el 23 ago y elegida el 14 sep
>
> Con su columna «qué se rompe» ya puesta: *«sólo lo del profesor que hoy edita ordinales
> de otro año»*. **Se cierran los CUATRO catálogos, `ordinales` incluido** — preguntado
> aparte y contestado aparte el mismo día, porque es el caso caro: es el único al que
> **llega un profesor** —el estado `panel.ordinales` pide `can_work_like_teacher` **o**
> `can_work_like_admin`— y el único que **obliga a tocar el front**.
>
> ### Lo que se cerró, y dónde — **diez escrituras, no cinco**
>
> | | |
> |---|---|
> | `PUT escalas/update` · `DELETE escalas/destroy/{id}` | `EscalasDeValoracionController` |
> | `PUT frases/update/{id}` · `DELETE frases/destroy/{id}` | `FrasesController` |
> | `DELETE contratos/destroy/{id}` | `ContratosController` |
> | `POST ordinales/store` · `PUT ordinales/update` · `PUT ordinales/guardar-valor` · `PUT ordinales/guardar-valor-config` · `PUT ordinales/destroy` | `Disciplina\OrdinalesController` |
>
> **`ordinales` son CINCO y la familia parecía de cuatro.** La quinta es
> `putGuardarValorConfig`, que escribe en `dis_configuraciones` —**otra** tabla con
> `year_id`, la configuración del manual de ese año—. Sin ella el cierre tendría un
> agujero del tamaño de la propia pantalla: artículos bloqueados y la configuración que
> los gobierna abierta.
>
> **Y `ordinales/store` es el único `store` de los cuatro catálogos que lleva candado**,
> porque es el único que **toma el `year_id` del cuerpo**. Los otros tres estampan
> `$user->year_id` al crear, así que no pueden sembrar fuera de su año — razonado en
> `EscalasDeValoracionController::postStore`. Aquí, crear un artículo del manual de 2022
> **es una llamada**, no un descuido de contexto.
>
> > ### ❌ ESA SEGUNDA MITAD ERA FALSA — corregido el 15 sep 2026, y la lista sube a TRECE
> >
> > *«Estampan `$user->year_id`, así que no pueden sembrar fuera de su año»* junta dos
> > cosas ciertas y saca una que no lo es. **Lo que faltaba era preguntar quién escribe
> > el año de la sesión**, y lo escribe la barra de año:
> >
> > ```
> > PUT years/useractive/{year_id}    auth.personal, y NO mira si el año está cerrado
> >   YearsController:758-778         $usuario->periodo_id = <un periodo de ese año>
> >   ContextoDeUsuario:169           left join years y on y.id=per.year_id
> >   $user->year_id                  el año cerrado
> > ```
> >
> > `putUseractive` **no escribe `users.year_id`**: escribe `users.periodo_id`, que es
> > justo la columna de la que el contexto deriva el año. Medido en la copia de
> > desarrollo: `years` id **6** = 2023 con `actual = 0` y los `periodos` **22–25**
> > colgando de él; con `AnioCerrado` —`year < MIN(year donde actual = 1)`, o sea
> > `< 2025`— está cerrado.
> >
> > **Quién lo levantó importa, porque dice dónde estaba el punto ciego**: lo levantó
> > `myvc_front` implementando su lado de los 403 — desde fuera se veía que el aviso al
> > usuario tenía que decir dos cosas distintas y que la regla quedaba rara de leer. De
> > este lado se había mirado el código, se había encontrado que el año viene de
> > `periodo_id` y se había dado la cadena por cerrada **un eslabón antes del que
> > decidía**.
> >
> > **Decisión de Joseth del 15 sep: se cierran los tres `store`**, y la lista pasa de
> > **diez a trece**. El argumento que había para dejarlos fuera no era sólo la premisa
> > falsa —de `escalas/store` sigue siendo cierto que la banda nace en 91–100 y no
> > recoge ninguna nota—: lo que inclina la decisión es que **`deleteDestroy` sí llevaba
> > candado en los tres**, así que la única puerta abierta era la que fabrica filas que
> > luego nadie de los 74 puede quitar.
> >
> > Lo fija [`AltaEnUnAnioCerradoTest`](../../tests/Contrato/AltaEnUnAnioCerradoTest.php),
> > **cuyo primer caso no comprueba ningún candado: comprueba la premisa.** Una decisión
> > tomada sobre un hecho necesita que ese hecho tenga un test, o se vuelve a decidir mal
> > — y aquí ni el código ni este documento lo habrían delatado, porque los dos decían lo
> > mismo y los dos estaban equivocados.
>
> ### ⚠️ ESTO SÍ ROMPE UNA PANTALLA, Y HAY QUE AVISAR AL FRONT
>
> Lo dice la §4 de este documento y sigue siendo verdad: **Disciplina ▸ Ordinales tiene un
> selector de año de verdad y lo alcanza un profesor**. Con esto desplegado, ese selector
> deja de guardar en los años cerrados para todo el que no sea superusuario — y el síntoma
> será **un 403 al guardar**, no un control deshabilitado. Hay que esconder o deshabilitar
> el selector para los años que no sean el corriente; dejarlo visible y que conteste 403 es
> la peor de las dos.
>
> **El año corriente no cambia para nadie**, y eso lo sujeta
> `ElManualDeConvivenciaDeUnAnioCerradoTest::test_el_anio_corriente_se_sigue_escribiendo_sin_ser_superusuario`.
>
> El criterio vive en **`Autoriza::puedeEscribirEnUnAnioCerrado`** y el hecho —¿está
> cerrado ese año?— en **`App\Support\AnioCerrado`**, separados a propósito. Lo fija
> `EscrituraDeCatalogoDeOtroAnioTest`, que mide **los dos lados**: 403 para el personal
> llano y **200 para el superusuario**, que es lo que mantiene viva la §27.4.
>
> ### Por qué NO rompe el panel que esta página decía que rompería
>
> Más abajo, en la §3, está escrito que cerrar esto *«rompe ese panel para los siete años
> que no son el actual»* y que **lo notarían los diez `admin`**. Medido el 14 sep 2026 en
> la copia de desarrollo de `simonbolivar`:
>
> ```sql
> -- rol Admin: 10 personas, y las diez con is_superuser = 1
> -- can_edit_years / can_edit_plantilla_notas / can_edit_unidades_subunidades: 0 personas
> ```
>
> O sea que **en ese colegio «admin» ES `is_superuser`**, y los diez que usan el panel lo
> siguen usando igual. **Es de UN colegio y no de los dieciséis**, y ésa es la
> comprobación del día del despliegue: *un colegio que le haya dado ese panel a alguien sin
> `is_superuser` lo va a notar*, y el síntoma será un 403 al guardar en un año viejo.
>
> ### Y «cerrado» es ANTERIOR al actual, no «distinto del actual»
>
> Medido el mismo día: `years` tiene **2026 vivo con `actual = 0`** mientras corre 2025.
> El colegio **monta el año siguiente antes de conmutarlo**, y ahí es donde copia las
> escalas y el plan de área. Una regla escrita como *«lo que no es el actual está
> cerrado»* le cerraría justo esa pantalla. Está razonado entero en la cabecera de
> `AnioCerrado`, incluido qué pasa con los años que quedaron con `actual = 1` de más por
> el fallo de `YearsController::putSetActual` — **población desconocida, y por eso se toma
> la salida permisiva**.

> ## La respuesta, 24 ago 2026
>
> **Joseth, con las pantallas delante:**
>
> > «Todos pueden seleccionar un año y moverse por él como si fuera el año actual.
> > Un profe puede ir a años pasados y editar periodos que no estén bloqueados. Un
> > usuario con permisos puede ir al año pasado y cambiar las frases y situaciones,
> > lo mismo que las escalas y todo lo demás.»
>
> O sea que **moverse por un año pasado no es un agujero: es el producto**, y **lo
> que decide si se puede escribir no es el año, es el interruptor del periodo**
> —el candado de la [§27](05-codigo-muerto-y-roto.md), que ya existe y ya se
> comprueba—.
>
> **No se cierra ninguno de los cuatro.** Y esto es una decisión cerrada, **no
> pendiente**: la §84 dejó de ser un hallazgo.
>
> > ⚠️ **Esta frase se quedó a medias el 14 sep 2026 — lee el recuadro de arriba.** Sigue
> > siendo cierto que **no se cierra ninguno**: los cuatro catálogos se siguen pudiendo
> > escribir en años pasados, y por eso este párrafo no se tacha. Lo que cambió es
> > **quién**: en los cuatro, sólo superusuario. *Quien lea sólo este recuadro va a creer
> > que `escalas/update` sigue siendo `auth.personal` a secas, y lleva sin serlo desde esa
> > fecha.*
> >
> > **Y esta nota dijo durante un rato que `ordinales` se quedaba fuera, que fue cierto
> > exactamente una hora.** Se escribió cuando la decisión cubría tres catálogos y Joseth
> > cerró el cuarto después, en la misma sesión. Queda dicho en vez de reescrito, porque es
> > la forma en que envejece una nota puesta a media decisión.
>
> Lo que describía —«el listado filtra
> por año y la escritura no»— es la mitad de un mecanismo que se completa cuando
> el usuario cambia de año: entonces el listado enseña ese año y edita ese año.
>
> Se conserva lo de abajo porque **sigue siendo el mapa de las cuatro pantallas** y
> porque explica por qué la pregunta parecía necesaria. Lo que ya no hay que hacer
> es contestarla.
>
> **Y una consecuencia que sí queda abierta**: si un profesor puede moverse a un
> año pasado, entonces lo único que lo frena es el interruptor del periodo — así
> que **la pregunta buena no es «¿qué año?» sino «¿qué periodos deja abiertos cada
> colegio en los años cerrados?»**, que es operación del colegio y no código.

---

## El mapa de las cuatro pantallas *(escrito el 23 ago, antes de la respuesta)*

> Todo lo de abajo está **medido**, no deducido: la copia de producción para los
> números, `myvc_front` en el disco para las pantallas. Cada dato lleva de dónde
> sale.

## El resumen, que es lo que cambia la respuesta

La pregunta parecía una —*¿se puede escribir en un año pasado?*— y al ir a
cerrarla resultó que **los cuatro catálogos no están en la misma situación**. Dos
no tienen forma de llegar a otro año desde el front, y dos sí: **tienen una
pantalla que hoy edita años pasados, y funciona.**

| Catálogo | ¿Se llega a otro año desde el front? | Quién lo ve | Qué pasa si se cierra |
|---|---|---|---|
| **frases** | **No.** El listado (`GET api/frases`) filtra por el año del usuario, así que la pantalla nunca enseña una frase de otro año | Colegio ▸ Frases, sólo `admin` | **Nada.** Se cierra una puerta que ninguna pantalla usa |
| **contratos** | **No.** `ContratosController::getIndex` filtra por el año del usuario | Profesores, `admin` y `secretario` | **Nada.** Igual que frases |
| **escalas** | **Sí.** Colegio ▸ Años pinta **un panel de escalas por cada año** y deja editar y borrar en todos | Colegio ▸ Años, sólo `admin` | **Se rompe ese panel** para los siete años que no son el actual |
| **ordinales** | **Sí.** El manual de convivencia tiene **selector de año** y escribe en el que se elija | Disciplina ▸ Ordinales, **lo alcanza un profesor** | **Se rompe el selector** para todo el mundo |

**Y es una asimetría de front, no de backend.** En las cuatro tablas el backend se
comporta igual: la escritura no mira el año. Lo que las separa es que en dos hay
una pantalla que se aprovecha de eso y en dos no.

## Lo que hay dentro, por año

Cada año tiene **su propia copia** de los cuatro catálogos — no se comparten:

```
 id   año            frases  escalas  contratos  ordinales
  1   2018               50        4         16          0
  2   2019               47        4         16          2
  3   2020               47        4         16          2
  4   2021               47        4         16          2
  5   2022               47        4         17          2
  6   2023               47        4         18          2
  7   2024               47        4         20          2
  8   2025   ACTUAL      47        4         16          2
```

Total vivo: **426 frases, 36 escalas, 135 contratos, 16 ordinales.** Medido el 23
ago 2026 sobre la copia de producción.

## Las cuatro pantallas, una a una

### 1. Frases — Colegio ▸ Frases *(sólo `admin`)*

El listado pide `GET api/frases`, que **filtra por el año del usuario**
(`Frase::where('year_id', $user->year_id)`). La rejilla enseña las 47 del año en
curso y **ninguna de las otras 379**.

Editar o borrar una de otro año **es posible por la API pero no hay forma de
llegar a ella desde la pantalla**: no aparece en la rejilla, y las rutas llevan el
id en la URL.

> **Cerrarlo no le quita nada a nadie.**

### 2. Contratos — Profesores *(`admin` y `secretario`)*

Igual. `ContratosController::getIndex` lista con `Profesor::contratos($user->year_id)`,
así que la rejilla de profesores enseña **los contratos del año en curso**. El
botón de quitar contrato (`$ctrl.quitarContrato`) sólo puede mandar un id de los
que se están viendo.

> **Cerrarlo no le quita nada a nadie.**

### 3. Escalas — Colegio ▸ Años *(sólo `admin`)* — **esta sí se usa**

`YearsController` monta el listado de años y **a cada año le cuelga sus escalas**:

```php
$consulta      = 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by orden asc';
$year->escalas = DB::select($consulta, [$year->id]);
```

Y la pantalla lo pinta con un acordeón por año, cada uno con su propio panel:

```html
<div ng-repeat="year in $ctrl.years">
  ...
  <configuracion-escalas year="year"></configuracion-escalas>
```

O sea: **se abre el año 2022, se despliega «Escalas de valoración» y se edita.**
Y funciona hoy. Es exactamente el caso que protegió la §27.4 —una escala de 2022
sigue decidiendo cómo se pinta el desempeño en los boletines **de 2022**—.

> **Si se cierra, ese panel deja de guardar en los siete años que no son el
> actual.** Lo notarían los diez `admin`. Habría que tocar el front para esconder
> o deshabilitar el panel en los años que no sean el actual; dejarlo visible y
> que conteste 404 es la peor de las dos.

### 4. Ordinales — Disciplina ▸ Ordinales *(un profesor llega)* — **y ésta también**

La pantalla del manual de convivencia tiene un selector de año de verdad:

```html
<ui-select ng-model="$ctrl.datos.year" on-select="$ctrl.selectYear($item)">
```

y al crear o editar manda el año elegido:

```js
ordinal.year_id = $ctrl.datos.year.id;
```

El listado (`PUT ordinales/ordinales`) también toma el año del cuerpo:
`Request::input('year_id', $user->year_id)`.

**Y la diferencia con las escalas es quién llega**: el estado `panel.ordinales`
pide `can_work_like_teacher` **o** `can_work_like_admin`, y se entra por un botón
de la pantalla de Disciplina. **No es una pantalla de administración.**

> **Si se cierra, el selector de año deja de servir para nadie** — y a un profesor
> le deja de servir incluso para el año en curso si el colegio le tiene el año del
> perfil desalineado. Aquí hay que tocar el front sí o sí.

## Las tres formas de cerrarlo, con lo que cuesta cada una

| | Qué hace | Qué se rompe | Front |
|---|---|---|---|
| **A. Cerrar los cuatro** | Es la decisión literal: cada catálogo sólo se escribe desde su año | El panel de escalas por año y el selector del manual de convivencia | **Hay que tocarlo en dos sitios** |
| **B. Cerrar salvo al superusuario** | Un profesor sólo escribe en su año; un `admin` puede en cualquiera | Sólo lo del profesor que hoy edita ordinales de otro año | Escalas sigue igual —sólo la ve `admin`—; ordinales hay que avisarlo |
| **C. Cerrar sólo frases y contratos** | Los dos donde no hay pantalla que lo use | Nada | **No hay que tocarlo** |

**Y una cuarta que no es cerrar y conviene tener presente:** dejarlo como está y
**escribir en el propio front** que el panel de escalas y el selector de ordinales
editan años pasados a propósito. Hoy eso no está dicho en ninguna parte, y es
justo lo que hizo que la §27.4 se tomara mirando escalas y quedara escrita sólo en
escalas.

## Lo que NO cambia decida lo que decida

- ~~**Ninguno de los cuatro `store` cambia**: crear siempre estampa el año del
  usuario, salvo `ordinales/store`, que **lo toma del cuerpo**.~~ **Los cuatro
  cambiaron** (15 sep 2026): «estampa el año del usuario» no implica «no llega a un año
  cerrado», porque ese año lo mueve la barra. Ver el recuadro de la §1.
- **Leer no se toca.** La pregunta es sobre escribir; consultar el manual de
  convivencia de 2022 o las escalas de 2019 seguiría funcionando en cualquiera de
  las tres opciones.
- **`putCambiarClaves` ya está cerrada** a superusuario, que era la otra decisión
  del 23 ago y no depende de ésta.

Lo que hay hoy está fijado por
[`EscrituraDeCatalogoDeOtroAnioTest`](../../tests/Contrato/EscrituraDeCatalogoDeOtroAnioTest.php),
que afirma que **las cinco escrituras se comportan igual entre sí**: el día que se
cierre una, ese test cae y obliga a decidir sobre todas a la vez en vez de sobre
la que se tenía delante.
