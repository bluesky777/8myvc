# AUD-1 · el reloj único, y ESC · la escala de notas

> Los dos lotes de la sesión del [18-auditoria.md](../18-auditoria.md), noche del
> 24 ago 2026. **Los dos con permiso explícito de Joseth**, contestado en un
> cuadro de opciones: «empieza la fase 1» y «validar en el servidor ya».
>
> Y los dos salieron de la misma pregunta suya, que era otra: *«quiero un
> historial de las notas modificadas, hoy salen horas extrañas»*.

---

## §1. AUD-1 — el reloj único

### Qué estaba mal

`bitacoras.created_at` tenía **dos relojes dentro y nada en la fila que dijera
cuál era cuál**. Medido con `tools/salud-de-la-bitacora.php`: **12 filas escritas
en UTC contra 74 en Bogotá**, cinco horas de diferencia en la misma columna.
Ordenar por ella no daba una línea de tiempo, y ése era literalmente el síntoma
que se reportó.

El reparto exacto de relojes en `app/`, contado sin comentarios y por llamada:

| Forma | Llamadas | Zona |
|---|---|---|
| `Carbon::now('America/Bogota')` | 118 | Bogotá |
| `Carbon::now()` / `now()` | **19** en 16 líneas de 7 ficheros | UTC |

> **19 y no 17.** El primer recuento mezcló líneas con llamadas —una línea de
> `PuntoDeControlDeImportacion` tiene tres `now()`— y el documento llegó a decir
> 17. Corregido contando ocurrencias, no líneas.

### Qué se hizo

**`App\Support\Reloj`**, con tres cosas y ninguna más:

```php
Reloj::ZONA         = 'America/Bogota'   // const, no sale de config/app.php
Reloj::ahora()      : Carbon
Reloj::ahoraTexto() : string             // 'Y-m-d H:i:s.v'
```

`ZONA` es una constante y **no se lee de la configuración a propósito**: si
saliera de `config/app.php` volvería a poder cambiar sin que nadie lo notara, que
es de lo que venimos. Y `ahoraTexto()` lleva milisegundos porque **dos notas
tecleadas en el mismo segundo son dos líneas distintas del historial**, y con
precisión de segundo no se sabe cuál fue primero.

**Movidos: los tres que escribían `bitacoras.created_at`** — `ExigirPersonaPropia`,
`ExigirBoletinPropio` y `Sesion.php:477`. Con eso la columna queda **uniforme a
partir del despliegue**; los otros siete escritores ya estaban en Bogotá.

**No movidos: los trece restantes**, cada uno con su motivo en el test. El
criterio es uno solo: **no guardan una fecha que alguien vaya a leer**. O se
restan consigo mismos —las expiraciones de token, el corte de `LimpiarSesiones`—
o son un TTL relativo, como la caché de FCM.

Con una excepción declarada: **`PuntoDeControlDeImportacion` sí guarda fechas y se
queda.** Su cabecera ya documentaba que `inicio` y `fin` sólo se restan entre sí,
así que unificar la zona no cambia ningún resultado — **sólo desplaza cinco horas
lo que se lee en pantalla**. Moverlo arregla la pantalla y a cambio deja *esa*
tabla con dos relojes en su historia, que es la enfermedad que este lote cura.
Elegir entre las dos cosas no es de aquí: es de quien lleve las importaciones, y
está anotado en el test para que se encuentre.

### El centinela

`tests/Contrato/RelojUnicoTest` — cuatro comprobaciones. La que importa es la
tercera: **fija el reparto de `now()` por fichero y salta si aparece uno nuevo**.

Por qué va sobre el código y no sobre el resultado: **un `now()` nuevo en un sitio
que guarda una fecha no rompe nada, no falla ningún test, y mete una fila cinco
horas movida que después nadie puede distinguir de una buena.** No hay marca, así
que no hay reparación posible a posteriori. Cuando el síntoma se puede ver ya es
tarde.

Cuenta **por fichero y no por línea** a propósito: las líneas se mueven al editar,
y un centinela que salte por un cambio de formato se acaba desactivando — que es
peor que no tenerlo.

### Lo que AUD-1 NO arregla, y hay que decirlo

**La mitad de las horas raras es del esquema, no del código.** Las columnas
`created_at` son `TIMESTAMP`, que convierte al leer con la zona de sesión de
MySQL, y ésa sigue sin fijarse (`@@session.time_zone = SYSTEM`): hereda la del
hosting, y son dieciséis cuentas de cPanel distintas. El `Reloj` garantiza que lo
que **escribimos** es una sola hora; no garantiza que lo que se **lee** sea la
misma en los dieciséis. Eso sólo se cierra en la tabla nueva, que es `DATETIME(3)`
— y para `bitacoras` no se cierra nunca, porque se congela.

---

## §2. ESC — la escala de notas

### Qué estaba mal, y no era una pantalla

Vino del front: un `[nzMax]="100"` escrito a mano en `panel-de-notas.html:126`, en
un colegio cuya escala **va de 0 a 50**. Al comprobar el backend salió lo de
fondo:

**Nada en el servidor rechazaba una nota por pasarse de la escala.** Hay **diez**
sitios en `app/` que comparan contra `porc_inicial`/`porc_final` —`Grupo.php` ×5,
`Subunidad.php` ×2, `BolfinalesController`, `PromovidosController`,
`CertificadosPersonaController`— y **los diez son para pintar la banda**
(SUPERIOR, ALTO, BÁSICO). Ninguno aborta. En todo el proyecto hay **2
validaciones** y ninguna era ésta.

O sea que **el único guardián era el navegador**, y de tres pantallas hermanas dos
guardan y una no. Arreglar sólo esa pantalla dejaba el sistema cubierto **por
costumbre y no por diseño**: la cuarta pantalla, un `curl`, o un teléfono con una
versión vieja de la app se lo saltan igual — y a la app **no se la puede obligar a
actualizarse**, porque no comprueba versión mínima en ninguna parte.

### Medido antes de tocar, que es lo que lo volvió trámite

| Notas fuera de rango, escala 0–50 | |
|---|---|
| `100` | 65 |
| `95` | 24 |
| `78` | 2 |
| `89` | 1 |
| **Total** | **92** |

Son notas tecleadas **como si la escala fuera de 100**. Y el reparto por año es lo
que decidió que se pudiera poner ahora: **todas en los años 1 a 5, cero en los
cuatro más recientes**. Si hubiera aparecido una sola en el año en curso, la
validación nueva habría reventado una pantalla viva el primer día.

### Qué se hizo

`App\Support\EscalaDeNotas`, cableado en **las cuatro puertas** por las que entra
un número a pelo:

| Dónde | Cómo responde |
|---|---|
| `NotasController::putUpdate` | **422** con el motivo |
| `NotasController::putLote` | la nota vuelve en `fallidas` con su `motivo`; **las demás se guardan** |
| `DefinitivasPeriodosController::putUpdate`, rama con `nf_id` | **422** |
| ídem, rama **sin** `nf_id` | **422**, antes de abrir la transacción |
| `putUpdateRecuperacion` | **422**, por año y no por periodo |

> **Empecé validando una de las cuatro.** Las otras tres salieron al ir a
> comprobar un aviso de `8myvc-d2` sobre el orden de las guardas — el aviso no
> aplicaba, y aun así encontró el hueco. Es la regla de siempre por el lado bueno:
> **conté tres escritores y validé uno.**

Y `recuperacion_final` obligó a cambiar el diseño: **no tiene `periodo_id`**
—guarda `alumno`, `asignatura`, `year` y `nota`—, así que hay dos puertas,
`comprobar($valor, $periodoId)` y `comprobarEnAnio($valor, $yearId)`. El primer
intento fue inventar un `PeriodoDeLaFila::deRecuperacionFinal()`, y eso habría
sido resolver la escala contra algo que esa fila no tiene.

### Las dos decisiones de diseño, que son lo revisable

**1. Un año sin escala configurada NO bloquea.** Parece un olvido y es
deliberado. Los colegios crean sus propias escalas y un año recién abierto puede
no tenerlas: rechazar entonces **deja a sus profesores sin poder calificar**. De
los dos fallos posibles, dejar pasar es el estado de hoy y bloquear sería nuevo y
peor. Está fijado con un test para que no se «arregle».

**2. No se inventa un máximo por defecto.** Es la tercera vía y la peor: el front
tiene un `escalaMaxima() ?? 100` que en un colegio de 0 a 50 **afloja el límite al
doble** en vez de apretarlo. **Un defecto que empeora el caso que viene a proteger
es peor que no tener defecto**, y un valor inventado engaña más que ninguno
porque parece una comprobación.

Y la escala se resuelve **desde la fila y no desde `$user->year`**: se puede
escribir en un año pasado ([16](../16-escribir-en-un-anio-pasado.md)), y validar
contra la escala del año en curso rechazaría notas correctas de un año viejo.

---

## §2.bis El cabo suelto que dejo medido: el centinela vigila quién escribe, no quién lee

Lo encontró `8myvc-39` fundiendo el reloj, con **17.999 segundos** de diferencia
en un test suyo —las cinco horas al segundo— leyendo `auditoria.ocurrido_en` con
`strtotime()`.

**Son dos decisiones correctas que juntas dejan una trampa en la vuelta:**

- la **decisión 1** guarda hora de pared de Bogotá en un `DATETIME`, que es lo que
  hace que lo escrito sea lo leído en phpMyAdmin y en los dieciséis;
- la **decisión 2** deja `config/app.php` en UTC;
- y una cadena `'2026-08-24 03:51:13.000'` **no lleva la zona dentro**.

Así que cualquier PHP que la lea sin decirla la interpreta como UTC y **la mueve
cinco horas, devolviendo algo que parece una fecha correcta**.

Cerrada la mitad que se podía cerrar: **`Reloj::desdeTexto()`** existe y hace el
viaje de vuelta bien, con tres tests —incluido uno que comprueba que la diferencia
sigue siendo de **18.000 segundos exactos**, porque si deja de serlo es que cambió
la zona o cambió `config/app.php`, y las dos cosas obligan a revisar el 18—.

**Lo que queda abierto: nada obliga a usarlo.** Medido:

| | |
|---|---|
| `strtotime()` / `Carbon::parse()` / `new Carbon()` en `app/` | **42** |
| De ésos, peligrosos | los que leen una **columna de fecha y hora** de la base |
| Inofensivos | los que parsean **entrada del usuario** o columnas de **sólo fecha** (`fecha_nac`), donde la zona no desplaza nada |

**No se escribe el centinela de lectura aquí, y el motivo no es pereza:** un
detector sobre las 42 daría sobre todo falsos positivos, y la [§142](../noche-2026-08-23/r.md)
enseñó lo que cuesta un detector que cuenta bien el síntoma y no la causa.
**Cabe en la fase 5**, que es la que va a leer estas columnas de verdad y tendrá
la lista corta de las que importan.

> **Y la asimetría merece quedarse escrita aparte del caso**, porque se va a
> repetir: `RelojUnicoTest` se escribió para vigilar **quién escribe** con el
> reloj equivocado. La mitad de lectura ni siquiera se pensó. Un centinela que
> cubre una dirección de un problema de dos **parece** que cubre el problema — y
> lo demostró fallando: el reloj estaba bien, el test del reloj estaba verde, y la
> hora salía cinco horas movida igual.

---

## §3. Lo que cambia para los clientes

Por el §1.8 del briefing, porque **esto cambia respuestas**:

- `PUT notas/update`, `PUT definitivas-periodos/update` y
  `PUT definitivas-periodos/update-recuperacion` pueden contestar **422** donde
  antes daban 200.
- `PUT notas/lote` devuelve la nota fuera de rango en **`fallidas`**, con
  `motivo`. El contrato no cambia: la clave ya existía.
- Ningún nombre de campo cambia. Ninguna ruta cambia.

Avisado a `myvc-front-10` con una advertencia de orden que importa: **el 422 llega
a los dieciséis antes de que la guarda del front esté publicada**, así que durante
un tiempo el rechazo lo va a dar el servidor y no el formulario.

---

## §4. Comprobado

    suite completa .......... en base aislada (simonbolivar_testing_aud)
    larastan nivel 7 ........ [OK]
    pint .................... limpio
    secciones-citadas ....... 0 huérfanas

> **Y una advertencia de método que costó cuatro fallos fantasma.** Corriendo
> contra la base compartida salieron **fallos distintos en cada pasada** —dos
> primero, otros cuatro después, y los primeros pasando— con tests que no tocan
> nada de esto. No era código: eran **otras sesiones corriendo su suite contra la
> misma base**. Con `DB_TEST_DATABASE=simonbolivar_testing_aud`: **cero**.
>
> Lo peligroso no es que falle: es que **falla en tests ajenos y parece tuyo**. Yo
> misma llegué a decirle a Joseth que había «inestabilidad preexistente» cuando
> era mi propio deadlock por lanzar dos corridas a la vez. **Si los fallos cambian
> entre pasadas, la base es lo primero que hay que descartar, no el código.**
