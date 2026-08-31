<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * La definitiva de una materia deja de ser un entero.
 *
 * Encargo de Joseth vía `myvc-front-b8` (30 ago 2026): los promedios y los puestos
 * empatan por redondeo. `Nota::puestoAlumno` cuenta a cuántos les gana el promedio,
 * así que dos promedios iguales comparten puesto — y hoy son iguales **porque la
 * columna los iguala**, no porque los alumnos hayan sacado lo mismo.
 *
 * ## Dónde se pierde de verdad, medido y no deducido
 *
 * La aritmética no pierde nada: el promedio es `$sumatoria / count($asignaturas)`
 * sin redondear (`BoletinesController:338`, `PuestosController:288`) y
 * `puestoAlumno` compara con `>` a secas. **El techo es la columna.**
 *
 * Recalculando la fórmula del propio servicio sobre la copia de `simonbolivar`:
 *
 * | población | valor |
 * |---|---|
 * | pares (alumno, asignatura, periodo) calculables | **125.352** |
 * | de ésos, con decimales que hoy se tiran | **96.608 (77,1 %)** |
 * | máximo exacto observado | **60,0000** |
 *
 * O sea: **tres de cada cuatro definitivas del colegio se guardan redondeadas.**
 *
 * ## Por qué `DECIMAL(7,4)` y no `(6,2)`, que era la corazonada del encargo
 *
 * La fórmula es `SUM(nota * pct_sub * pct_uni / 10000)` con los tres factores
 * enteros, así que **cada sumando tiene exactamente 4 decimales** y la suma
 * también. No es un argumento teórico, está contado sobre las 125.352:
 *
 * | escala | filas que NO caben |
 * |---|---|
 * | 2 decimales | **21.148** (16,9 %) |
 * | 3 decimales | **3.371** |
 * | **4 decimales** | **0** |
 *
 * `(6,2)` volvería a redondear una de cada seis definitivas —el mismo defecto por
 * la puerta de atrás, y ya sin nadie mirando—. **Cuatro decimales es exacto, y
 * exacto es una propiedad, no un margen**: recalcular una definitiva nunca cambia
 * el número guardado.
 *
 * Los tres dígitos enteros no son por si acaso: los porcentajes **no se
 * normalizan** (regla 2 de `DefinitivasDeAsignatura`, y es deliberada — una
 * asignatura mal configurada tiene que delatarse en la planilla). Hay pares cuyas
 * unidades suman **200**, y notas de **100** en una escala de 0 a 50. Con ese
 * suelo, 999,9999 no lo alcanza ningún dato posible de hoy.
 *
 * ## Lo que esta migración NO hace, y ninguna de las tres es olvido
 *
 * 1. **No toca `notas.nota`**, aunque el encargo pedía las dos columnas, y el porqué
 *    tiene dos mitades. La primera, medida aquí: esa columna se escribe **sólo**
 *    desde `Request::input('nota')` (`NotasController` 421, 722, 963) y desde
 *    `subunidades.nota_default` (`Nota` 63, 77, 150), y **no hay un solo `round()`
 *    en ese camino** — el redondeo que empata los puestos ocurre **al guardar la
 *    definitiva**, no al guardar la nota, así que migrar esta columna no desempata
 *    a nadie.
 *
 *    La segunda mitad **no se sabe desde este repositorio y por poco se escribe mal
 *    aquí**: el docente **sí puede teclear `85,5`**. Las cuatro pantallas de los dos
 *    fronts llevan `<input type="number">` sin `step`, y ninguna lo valida — lo midió
 *    `myvc-front-10` el 23 ago 2026 (`myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`).
 *    O sea que **sí se pierde un decimal en `notas.nota`**; lo que no hay es un
 *    `round()` de PHP, porque quien lo redondea es **MySQL al insertar en un `int`**.
 *    Y **redondea, no trunca**, al contrario de lo que dice esa entrada del front:
 *    medido contra el contenedor, `85.5 → 86`, `85.4 → 85`, `43.75 → 44`, igual
 *    ligado que como literal.
 *
 *    Aun así la columna se queda, **porque la decisión ya está tomada y es la
 *    contraria**: el 23 ago 2026 Joseth decidió cerrar esa puerta **en el teclado y
 *    no redondeando al guardar** —*«si un 85,5 tiene que ser 86, lo decide quien pone
 *    la nota»*—, y `myvc_flutter` ya lo implementó (`lib/Utils/TecladoDeNota.dart`).
 *    Volver decimal esta columna sería **deshacer esa decisión** por la puerta de
 *    atrás, no completarla; y además arrastraría la escala de `notas_finales` a
 *    `4 + d`. Confirmado con Joseth el 30 ago 2026. Lo que **sí** queda abierto es que
 *    los dos fronts de Angular todavía no tienen el arreglo del teclado que Flutter
 *    sí tiene, y eso es del front.
 * 2. **No recalcula ni convierte nada.** El `ALTER` ensancha el tipo y los valores
 *    se quedan donde están: un `15` a mano pasa a ser `15.0000` y sigue siendo el
 *    mismo 15. Las **6.830 definitivas `manual = 1` de 127.873 (5,34 %)** se copian
 *    solas por no hacer nada, que es justo lo que el encargo pedía para ellas.
 * 3. **No quita el `NOT NULL DEFAULT 0`.** Hay código que cuenta con el cero
 *    —`si_recupera_materia_recup_indicador`, y las asignaturas sin notas que entran
 *    en el promedio como 0—, y un `NULL` nuevo movería promedios en silencio. Por
 *    eso el `ALTER` va escrito a mano y con los dos atributos delante: un
 *    `->change()` del constructor de esquema los redeclara todos o los pierde, y
 *    perder el `DEFAULT 0` aquí no da error, da promedios distintos.
 *
 * ## Lo que sí cambia para los alumnos, y hay que decirlo
 *
 * **Esto mueve el puesto de alumnos reales.** En el grupo 97, periodo 2 —51
 * matriculados, que es la población de `matriculas` sin filtro de estado, la misma
 * que usa `DefinitivasDeAsignatura`— los alumnos metidos en algún empate bajan de
 * **41 a 27**. El front midió **28 de 38** sobre la población del boletín
 * (`Grupo::alumnos`, que sí filtra `MATR`/`ASIS`/`PREM`): son dos poblaciones
 * distintas del mismo grupo y las dos cifras concuerdan en dirección y tamaño.
 *
 * El primer boletín después del despliegue traerá **puestos distintos** a los del
 * periodo anterior sin que haya cambiado ninguna nota.
 */
class NotasFinalesEnDecimal extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE notas_finales MODIFY nota DECIMAL(7,4) NOT NULL DEFAULT 0');
    }

    public function down()
    {
        // Vuelve a redondear, que es lo que había: `int` no puede guardar el 43,75.
        // La vuelta atrás **pierde los decimales de verdad** y no se puede deshacer
        // otra vez — quien la corra en un colegio ya desplegado le devuelve los
        // empates y no recupera lo que tiró. Está aquí porque una migración sin
        // `down()` no se puede probar, no porque sea barata.
        DB::statement('ALTER TABLE notas_finales MODIFY nota INT NOT NULL DEFAULT 0');
    }
}
