# BI-4 — `Unidad::deAsignatura`, que no es un sitio

**Rama:** `fix/alcance-de-unidad-de-asignatura` · **Sesión:** `8myvc-e0` ·
**Noche del 25 ago 2026**

**No se cambió la firma y no se acotó ningún llamador.** Lo que entra es el censo
con veredicto, la premisa medida y el test que la fija. El porqué de no acotar está
en el §5, y es la propia regla del lote.

---

## 1. La premisa de la ficha no se sostiene: **no hay dos montones**

La ficha repartía los diecisiete en *«los que corren dentro de un bucle de alumnos»*
y *«los de planilla y ausencias, que resuelven una asignatura entera sin alumno»*.

**Medido — de dónde sale el alumno en cada uno de los 17:**

| vía | cuántos |
|---|---|
| **parámetro del método** | 13 |
| **bucle `foreach ($alumnos …)`** | 3 |
| **`Request::input('alumno_id')`** | 1 |
| **sin alumno** | **0** |

Y los tres que la ficha daba como el montón difícil —`PlanillasController:144`,
`PlanillasAusenciasController:112`, `NotasPerdidasController:179`, los tres
`getShowProfesor`— **están dentro de `foreach ($alumnos as $keyAl => $alumno)`**,
verificado por **saldo de llaves** (bucle en 136 / 104 / 171, saldo 2), **no por
indentación**.

---

## 2. Y el uso también es por alumno en los diecisiete

Que el alumno esté disponible no dice que deba usarse. Así que se miró **qué se
hace con las unidades justo después**:

- **8** las usan para **notas o definitiva de ese alumno**;
- **9** las usan para `Subunidad::perdidasDeUnidad($unidad->unidad_id, $alumno_id)`
  — **las subunidades perdidas de ese alumno**.

Los nueve son **el mismo bloque copiado** (`asignaturasPerdidasDeAlumnoPorPeriodo`
en ocho ficheros y `PromovidosController`). **Ninguno de los diecisiete pinta la
estructura del grupo por sí misma.**

> **Veredicto uniforme: los diecisiete deben acotarse por alumno.** No hay reparto
> de producto pendiente, que es lo que la ficha esperaba encontrar. La pregunta
> *«¿esta pantalla quiere las unidades del alumno o las del grupo?»* **tiene la
> misma respuesta en los diecisiete**, y se lee en el código, no se decide.

---

## 3. La premisa del arreglo, medida

`tests/Contrato/UnidadDeAsignaturaConAlcanceTest.php`, **escrito antes de tocar
nada** — con `boletin_independiente` a 0 y `alumno_id` NULL en todas las unidades,
la forma correcta y la incorrecta dan el mismo verde, así que un test escrito
después no discrimina nada.

1. **Con nadie marcado, `deAsignatura` y `deAsignaturaCalculada` traen las mismas
   unidades** → **acotar es un no-op hoy**: el cambio de conducta es latente, no
   actual. *Ése es el criterio de aceptación y ya no es un razonamiento.*
2. **Con un alumno marcado y una unidad suya, las dos formas se separan** → **el
   alcance ya escrito sabe separar**.

`2 passed (6 assertions)`.

### Y la tercera vez de la misma forma de deuda

`Unidad::deAsignaturaCalculada` —el método hermano, cinco llamadas— **ya usa el
alcance** (`Unidad:111`):

    $alcance = \App\Services\BoletinIndependiente::alcance((int) $alumno_id, (int) $periodo_id);
    ... where u.alumno_id <=> :alcance

**`deAsignatura` es el mismo método sin acotar, con diecisiete llamadores.** No hay
que inventar cómo se acota ni escribir una segunda regla de matrícula —lo que BI-2
pagó con un `LIMIT 1` que leía el interruptor de 2024 para un periodo de 2026.

> **Van tres esta noche con esta forma exacta**, y ya no es coincidencia:
>
> 1. `recalcular()` acepta `$soloAlumno` y **una de sus tres puertas lo pasa** (BI-3);
> 2. los `DB::update`/`DB::delete` correctos **conviviendo con sus hermanos malos** en el mismo fichero (VERBOS-1);
> 3. `deAsignaturaCalculada` acotado **al lado de `deAsignatura` sin acotar** (éste).
>
> **Alguien arregla el sitio donde le dolió y no mira al lado.** Es deuda que este
> repositorio produce sola, y **se busca barato: un `grep` del nombre del método
> hermano.**

---

## 4. El censo, uno a uno

| sitio | vía del alumno | uso de las unidades | veredicto |
|---|---|---|---|
| `Models/Nota.php:177` `alumnoPeriodoDetalle` | parámetro | notas del alumno | **acotar** |
| `Models/Nota.php:277` `alumnoAsignaturasPeriodosDetailed` | parámetro | notas del alumno | **acotar** |
| `BolfinalesController.php:342` | parámetro | perdidas del alumno | **acotar** |
| `Informes/BolfinalesController.php:832` | parámetro | perdidas del alumno | **acotar** |
| `Informes/BoletinesController.php:501` | parámetro | perdidas del alumno | **acotar** |
| `Informes/Boletines2Controller.php:433` | parámetro | perdidas del alumno | **acotar** |
| `Informes/Boletines3Controller.php:425` | parámetro | perdidas del alumno | **acotar** |
| `Informes/CertificadosPersonaController.php:495` | parámetro | perdidas del alumno | **acotar** |
| `PromovidosController.php:289` | parámetro | perdidas del alumno | **acotar** |
| `EditnotaController.php:59` `notasDeLaAsignatura` | parámetro | notas del alumno | **acotar** |
| `EditnotaController.php:244` `allNotasAlumno` | parámetro | perdidas del alumno | **acotar** |
| `EditnotaController.php:327` `asignaturasPerdidasDeAlumno` | parámetro | notas del alumno | **acotar** |
| `EditnotaController.php:401` | parámetro | perdidas del alumno | **acotar** |
| `DetallesController.php:118` `putGruposPeriodos` | `Request::input('alumno_id')` | notas del alumno | **acotar** |
| `PlanillasController.php:144` `getShowProfesor` | bucle `$alumnos` | notas del alumno | **acotar** |
| `Informes/PlanillasAusenciasController.php:112` | bucle `$alumnos` | notas del alumno | **acotar** |
| `Informes/NotasPerdidasController.php:179` | bucle `$alumnos` | notas del alumno | **acotar** |

`Unidad::informacionAsignatura` —**2 llamadas, 1 fichero**— queda fuera de este
censo: es otro método y no se miró.

---

## 5. **Por qué no se acotó ninguno, que es la regla del propio lote**

La ficha lo dice y no se negocia: **el test va antes que la acotada, y la red de
cada llamador es un test propio con la respuesta del endpoint delante.** Con todo a
`NULL`, *«no se movió nada»* pasa idéntico sobre el código sin acotar.

**Diecisiete acotadas son diecisiete redes**, y **un commit por acotada** para que
bisecar sea `git`. Eso no cabía en lo que quedaba de turno, y **la alternativa mala
está prohibida por la ficha**: cambiar la firma dejando llamadores con el valor por
defecto *parece acotado y no lo está, y el siguiente creerá que la pregunta ya se
contestó*.

**Lo que queda hecho para quien lo continúe** es lo caro: el veredicto es uniforme,
la premisa está medida, el mecanismo existe y **no hay ninguna decisión de producto
esperando**. La acotación es mecánica: `?int $alcance = null` en la firma y
`BoletinIndependiente::alcance($alumno_id, $periodo_id)` en cada llamador —**los
diecisiete tienen los dos argumentos a mano**.

---

## 6. Lo que me retiro

**El docblock de mi propio test mentía, y van dos lotes seguidos.** Escribí
*«EL CONTROL, y la mitad que hoy está en rojo»* **antes de correrlo**; al correrlo
**pasa, y pasa a propósito** — compara los dos métodos del modelo entre sí, así que
ningún llamador pasa por ahí y no puede estar en rojo.

Es lo mismo que me pasó en [BI-3](bi-3.md) dos horas antes, **después de escribir
allí que prometer lo que no se mide es más fácil en un docblock que en el código,
porque el docblock no se ejecuta.**

Corregido sin borrar la corrección —el docblock ahora empieza diciendo qué decía y
por qué era falso— y con un apartado **«Lo que este fichero NO es»**.

> **La regla, y ésta va en forma de procedimiento y no de conclusión, porque la de
> BI-3 estaba escrita como conclusión y no me paró: el docblock de un test se
> escribe DESPUÉS de verlo correr.**

Y una del método, para quien lea el §1: **mi clasificador de los diecisiete falló
tres veces** —17/17 «con alumno» buscando la palabra en el contexto; luego 11 «sin
alumno» por no mirar la firma; luego 1 por no mirar `Request::input`—. **Las tres
las delató lo absurdo del número, ninguna una revisión.** Con un reparto 9/8 y sin
métodos que se llamen `asignaturasPerdidasDeAlumno`, me lo habría creído.
