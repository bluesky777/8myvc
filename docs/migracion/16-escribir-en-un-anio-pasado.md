# Escribir en un año pasado — los cuatro catálogos, y qué pantalla usa cada uno

> **Para Joseth, 23 ago 2026.** Está pedido «parar y enseñar las pantallas», así
> que **aquí no se ha tocado nada**: los cuatro catálogos siguen exactamente como
> estaban. Esto es lo que hay que tener delante para decidir.
>
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

- **Ninguno de los cuatro `store` cambia**: crear siempre estampa el año del
  usuario, salvo `ordinales/store`, que **lo toma del cuerpo** — que es la otra
  mitad de la misma pregunta y va con ella.
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
