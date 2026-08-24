# GEMELO-1 — el gemelo vivo del boletín final

> Sesión `8myvc-79`, noche del 25 ago 2026. Rama `perf/gemelo-de-bolfinales`,
> árbol `.worktrees/79`, base `simonbolivar_testing_79`.
>
> **Lo primero, porque es lo que se pierde:** el arreglo de rendimiento de este
> documento está **hecho y sin fundir a propósito**. No es que falte terminarlo.
> Es que **no debe entrar hasta que Joseth decida otra cosa**, y el porqué está en
> la §2.
>
> **Estado de la medición: pendiente.** Lo escrito abajo que lleva número es
> *lectura de código, `grep` y las cifras ya publicadas en la [05 §224](../05-codigo-muerto-y-roto.md)*;
> **ningún número de este documento se ha medido todavía en esta rama**. La §7 se
> escribe cuando haya turno de docker y base propia, y dirá con qué base y qué
> árbol se midió.

## §1. Lo que se preguntó, y lo que cambió al mirar

El encargo eran dos trabajos en orden: **primero averiguar por qué el gemelo
devuelve un 500**, y sólo después desanidar sus dos bucles. El orden era el
acierto: la respuesta del primero **cambió qué hay que hacer con el segundo**.

`app/Http/Controllers/BolfinalesController.php` —el de la raíz, sin `Informes/`—
es una copia del que ya se curó en `2837171`, con los mismos dos bucles anidados
y las tres consultas invariantes escritas en Eloquent. Está medido en la
[05 §224](../05-codigo-muerto-y-roto.md): **3.820 consultas y 11,4 s para
devolver un 500**.

## §2. El 500 no tiene una causa: tiene dos, y la segunda cambia la decisión

La §224 encontró la primera y la dio por completa. **Son dos, apiladas, y
cualquiera de las dos bastaría para el 500 ella sola.**

`CertificadosEstudioController::getCertificadoGrupo` hace, en este orden:

```php
$datos   = $bol->detailedNotasGrupo($grupo_id, $user);   // paga las 3.820 consultas
$content = View::make('certificados.estudio')            // <- causa 1: la vista no existe
                ->with(...);
$pdf     = App::make('dompdf.wrapper');                  // <- causa 2: el binding no existe
$pdf->loadHTML($content);
return $pdf->download();
```

**Causa 1 — la vista.** `resources/views/certificados/` no existe, y
`certificados.estudio` sólo aparece nombrada en las dos líneas que la piden. Ya
estaba en la §224 y se confirma.

**Causa 2 — el PDF, que es nueva.** `App::make('dompdf.wrapper')` pide un binding
que **nunca existió en este proyecto**. No es un paquete que se cayó de un
colegio: no está en ninguno de los cuatro sitios donde tendría que estar. Los
cuatro comandos, y los cuatro vacíos:

```bash
grep -n "dompdf\|pdf" composer.json                       # nada
grep -n '"name":.*pdf\|"name":.*dompdf' composer.lock     # nada
ls vendor/ | grep -i "pdf\|barryvdh"                      # nada
grep -rn "dompdf" config/ bootstrap/                      # nada
```

Y `dompdf.wrapper` se nombra **en un solo sitio de todo `app/`**:
`CertificadosEstudioController:47`. Ningún otro endpoint lo usa, así que no hay
un camino vivo que demuestre lo contrario.

### Por qué esto no es una nota al pie: la rama 1 de la §224 cuesta el triple

La §224 dejó la decisión de Joseth en dos ramas, y su rama 1 decía: *«si esa
pantalla debe existir, hay que escribir la vista **y** curar el patrón»*.

**Eso está corto.** La rama 1 de verdad es **escribir la vista + curar el patrón +
meter una dependencia nueva de composer**, y esa tercera parte no es del mismo
tamaño que las otras dos: por `CLAUDE.md`, **`vendor/` es compartido por
symlink**, así que un `composer require` dentro de un colegio se lleva por
delante a todos los que cuelguen de esa carpeta. **No es un cambio por colegio;
es un cambio a los dieciséis a la vez.**

La rama 2 —retirar la ruta— **no se movió**: sigue igual de barata.

> **Una rama de decisión que cuesta el triple de lo que decía no es la misma
> decisión.** Por eso esto sube a Joseth y no se resuelve aquí.

Y **no se borra**, por la regla de la casa: *con ruta y roto se documenta*.

### La segunda fila de la §224, que allí estaba sin explicar

La tabla de la §224 da `certificado-alumno` con **5 consultas** y `certificado-grupo`
con **3.820**, las dos con 500, y no dice por qué se diferencian tanto. Es esto:
**`getCertificadoAlumno` construye el `new BolfinalesController` y no lo usa** —la
llamada a `detailedNotasGrupo` está comentada, `$bol` se asigna y se tira—, así
que revienta en `View::make` sin haber pagado nada. Número cerrado.

### Y una que confirma la §218 por el camino largo

**Ninguna ruta apunta al gemelo de la raíz.** Las cuatro de `bolfinales/*` van a
`Informes\BolfinalesController`, que es otro fichero; `routes/api/informes.php`
ni siquiera importa el de la raíz. El único camino vivo hasta su
`detailedNotasGrupo` es el `new` de `CertificadosEstudioController`.

## §3. El desanidado: hecho, y con las diferencias del gemelo respetadas

El patrón es el del hermano —`2837171`—, pero **no se copió**, y eso es lo que
más trabajo dio. **El instrumento correcto sobre el objeto equivocado no se ve
mirando el resultado, porque el resultado parece correcto.** Tres diferencias
entre los gemelos que un copiar-pegar habría borrado en silencio:

| | el hermano (`Informes/`) | el gemelo (raíz) | qué pasaba si se copia |
|---|---|---|---|
| matrículas | `m.estado = "MATR"` | `(m.estado="MATR" or m.estado="ASIS")` | **los alumnos en `ASIS` desaparecen del recuento**, y `Grupo::alumnos()` trae `MATR`, `ASIS` y `PREM`: los hay |
| periodos | `DB::select` → `stdClass` | `Periodo::where(...)->get()` → **modelos** | `Periodo` castea `created_at`/`updated_at`/`deleted_at` a fecha y **los serializa distinto** que una fila cruda |
| `deleted_at` de periodos | escrito a mano en el SQL | **lo pone `SoftDeletes`** | escribir la condición dos veces, o quitarla creyendo que falta |

**Ninguna de las tres la ve una cota de consultas**: mismo número de consultas,
resultado distinto. Es la misma forma que el `clone`.

Lo que se hizo, entonces:

- **`periodosDelAnio($year_id)`** — memoizado en los `attributes` de la petición y
  devolviendo **clones**, conservando la Collection de modelos. Las tres
  invariantes (líneas 67, 86 y 267) pasan de **408 ejecuciones a una**.
- **`perdidasPorAlumnoDelGrupo()`** y **`perdidasPorDefinitivaDelGrupo()`** — los
  dos bucles anidados a dos `GROUP BY`. **Son dos mapas y no uno, a propósito:**
  una consulta une con `matriculas` y no mira `deleted_at`; la otra filtra
  `deleted_at` en subunidades y unidades y no une con `matriculas`. Fundirlas
  cambiaría los números en las filas en que difieren.

Las tres trampas que el hermano ya había pagado —el `clone`, el memo fuera de una
propiedad de la clase, y los dos mapas separados— **no se volvieron a pagar**, y
cada una lleva su porqué escrito en el código, que es donde hace falta.

> Y una razón de más para que el memo no viva en una propiedad, que en el hermano
> no aplicaba: **aquí el controlador ni siquiera lo construye el router**, lo
> construye un `new` desde otro controlador. Una propiedad viviría lo que viva esa
> variable — otra vida distinta de la de la petición.

## §4. Cómo se comprueba que la respuesta no se movió

**Por HTTP no se puede**: las dos rutas dan 500 antes y 500 después, así que un
test del código de respuesta daría verde con el resultado cambiado dentro. Hay
que mirar **el objeto que devuelve el método**.

- **`tests/Contrato/GemeloDelBoletinFinalTest.php`** (barato, corre con la suite):
  que cada asignatura perdida conserva **sus propios objetos** de periodo, y que
  la invariante no crece con el grupo (cota **1**, no 3 como en el hermano: aquí
  no existe el parámetro `periodo_a_calcular`, así que hay una sola forma de
  consulta).
- **`tests/Barrido/EquivalenciaDelGemeloTest.php`** (caro, grupo `barrido`):
  recalcula **con el SQL viejo, copiado literal del controlador de antes del
  arreglo**, las 2.960 consultas de una en una, y compara valor a valor — más el
  **conjunto de alumnos** que acaba con `asignaturas_perdidas`.

Dos decisiones de esos tests que podrían haber salido al revés:

1. **El aserto del `clone` es de identidad de objeto, no de valores.** «Estas dos
   asignaturas tienen cuentas distintas» daría verde por casualidad el día que el
   seed las tuviera iguales: mediría el seed, no el arreglo.
2. **La referencia del test caro es el SQL viejo, no un segundo `GROUP BY`.**
   Comparar mi agregado contra otro agregado escrito por mí es la misma idea
   escrita dos veces, y **una idea equivocada dos veces da verde**.

Los dos llevan la mitad que impide el falso verde: que el oyente contó algo, y
que la comparación comparó algo.

## §5. Una mina que apareció al leer, y que refuerza lo de conservar Eloquent

Dentro del bucle que reparte las pérdidas por periodo hay esto:

```php
if ($periodoAlone->periodo_id == $periodo->periodo_id) {   // <- no filtra nada
    if ($periodo->id == $periodoAlone->id) {               // <- el que decide
```

**El `if` de fuera siempre pasa.** La tabla `periodos` **no tiene columna
`periodo_id`**, y los dos lados salen del mismo sitio, así que los dos dan `null`
y `null == null` es cierto. Quien decide de verdad es el `id` de dentro.

No se toca —el resultado de hoy es el correcto y este lote no mueve la
respuesta—, pero **se anota, porque es una mina con el gatillo puesto**: si
alguien cambiara `periodosDelAnio()` por un `SELECT id as periodo_id, ...` —que
es exactamente lo que hace `Periodo::hastaPeriodoN()`, ahí al lado— **sólo uno de
los dos lados tendría el campo**, el `==` empezaría a comparar un entero con
`null` y **este `if` se pondría a descartar periodos de verdad**, en silencio y
sin mover ni una consulta.

Es la tercera razón, y la que no se veía desde fuera, para **no cambiar aquí
Eloquent por SQL crudo**. Queda escrita en el código, al lado del `if`.

## §6. Lo que NO se hizo, y por qué

- **No se fundió a `main`, y no por falta de tiempo.** El endpoint devuelve 500
  pase lo que pase; optimizar el camino hacia un 500 **no le sirve a nadie hoy**,
  y sólo sirve si Joseth elige la rama 1. Como `app/` es copia real en cada uno de
  los dieciséis colegios, fundirlo significaría llevar a dieciséis despliegues un
  refactor de código que, si gana la rama 2, se va a borrar. **El trabajo está
  hecho, medido y sin fundir a propósito**, esperando esa decisión.
- **No se escribió la vista `certificados.estudio`** ni se añadió ningún paquete
  de PDF: las dos cosas son la rama 1 de una decisión que no es de una sesión, y
  la segunda toca un `vendor/` compartido por los dieciséis.
- **No se retiró la ruta**: es la rama 2 de la misma decisión, y *con ruta y roto
  se documenta*.
- **No se tocó `Informes/BolfinalesController`** ni ninguna de las otras siete
  copias de este patrón: un fichero, un dueño.

## §7. La medición

Base `simonbolivar_testing_79`, árbol `.worktrees/79`, reconstruida antes de mirar
ningún número: **94 tablas y 2.351 usuarios**, que es lo que tiene `main`.

Grupo **98** del seed: **37 alumnos × 10 asignaturas × 4 periodos**, el mismo
grupo sobre el que la [05 §224](../05-codigo-muerto-y-roto.md) midió las 3.820.

### Lo que prueba que la respuesta no se movió

`tests/Barrido/EquivalenciaDelGemeloTest.php`, en verde:

- **465 pares (alumno, asignatura, periodo) con notas perdidas**, recalculados uno
  a uno con el SQL viejo —**2.960 consultas**— y **coincidiendo valor a valor** con
  lo que producen los dos `GROUP BY`.
- **37 alumnos con `asignaturas_perdidas`** en el informe, el mismo conjunto antes
  y después. Ése era el daño que no se ve en un número: una asignatura sin
  periodos perdidos se borra con `unset`, y con ella el alumno se queda sin la
  propiedad y **sale del informe entero**.

`tests/Contrato/GemeloDelBoletinFinalTest.php`, en verde, **627 asertos**: ninguna
asignatura perdida comparte objeto de periodo con otra ni con `$year->periodos`.

### El número, y por qué NO se resta del 3.820 de la §224

Medido, con el contador congelado justo después de la llamada:

| | consultas |
|---|---|
| `detailedNotasGrupo()` **después** del arreglo | **450** |
| el recálculo a mano con el SQL viejo, para comprobarlo | 2.960 |

**Y aquí hay que parar antes de restar.** «3.820 → 450» sería una resta entre dos
cosas que no se midieron sobre lo mismo: **la §224 midió una petición HTTP entera**
—`GET certificados-estudio/certificado-grupo/{g}`, con la resolución del contexto
de usuario dentro— y **este test llama al método directamente**, con el usuario ya
resuelto fuera de la ventana del oyente.

La cuenta cuadra en cuanto se dice sobre qué se midió cada una:

- lo que el arreglo quita son **408 + 1.480 + 1.480 = 3.368**, y añade **3** (el memo
  y los dos `GROUP BY`);
- o sea que la petición entera debería quedar en **3.820 − 3.368 + 3 = 455**;
- y **455 − 450 = 5**, que es exactamente lo que cuesta una petición de este
  controlador sin llegar al boletín: **la fila `certificado-alumno` de la §224, que
  mide 5 consultas**, es esa misma resolución de contexto.

**Los dos números son correctos y no se pueden restar entre sí.** El número
comparable 1:1 con la §224 sale de `CosteDelGemeloDeLaRaizTest`, que mide **las
tres rutas por HTTP en la misma corrida y descartando una pasada en frío**, y es
el que se cita en la tabla de abajo. El de aquí mide **el método**, que es lo que
este arreglo toca.

Lo mismo con el tiempo: los milisegundos de este test **no se comparan con los
11,4 s** de la §224, porque este test no descarta la pasada en frío y aquél sí.

### Y un instrumento que mintió, que es de este documento y no de otro

La primera corrida imprimió **3.412 consultas del boletín**, y **el número era
correcto: lo que estaba mal era el tramo sobre el que se contó.** El contador
entra en la clausura de `DB::listen` **por referencia**, `DB::listen` no se puede
quitar —no hay `unlisten`—, y el informe lo leía **al final**, o sea después de
que el recálculo a mano hubiera metido sus 2.960 en la misma variable.

**Lo que lo hacía peligroso es que 3.412 es creíble**: se parece muchísimo a las
3.820 de antes del arreglo, así que la lectura falsa era *«el desanidado apenas
sirvió»* — y eso no se ve mirando el resultado, sólo preguntando **sobre qué** se
midió. Se arregla congelando el contador justo después de la llamada, y así está
escrito en el test con su porqué.
