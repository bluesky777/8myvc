# MED-5 — el hueco de los seis grupos, y qué escribe `bolfinales` cuando se corta

**Sesión `ad`**, rama `medicion/lote-y-cobertura`. Noche del 24 ago 2026, después de
[MED-1](med-1.md), [HIST-1](hist-1.md) y [MED-4](med-4.md).

Dos preguntas encadenadas, y la primera nació de un error mío.

---

## §1 — La corrección que congeló un experimento

En el parte de [MED-4](med-4.md) escribí que `PUT bolfinales/detailed-notas-year-group`
**escribe**, y añadí de mi cosecha que *«ese 504 puede dejar definitivas a medio
actualizar»*. Lo primero es cierto; **lo segundo era especulación mía, no
medición**, y paró el experimento del front sobre los grupos 94/95/97/105 — porque
si esa ruta rellenara el hueco de definitivas, **medir un grupo alteraría la
variable independiente del experimento**.

Y encima había confundido dos rutas de nombre parecido:

| ruta | qué escribe |
|---|---|
| `PUT boletines/detailed-notas/{grupo}` | **sí recalcula definitivas** (`ponerAlDiaLasDefinitivas` → `DefinitivasDeAsignatura::recalcular`) |
| `PUT bolfinales/detailed-notas-year-group/{grupo}` | **no**. Su único `UPDATE` es `years.contador_certificados` |

El `[DB::update]` que mi detector marcó en `bolfinales` era **el contador de
certificados**, no `notas_finales`. Leer el método lo aclara en diez líneas; yo
mandé el mensaje antes de leerlo.

> **La lección, y es la que menos me gusta de la noche: un detector que dice
> «escribe» no dice QUÉ escribe, y la diferencia decidía si otro carril podía
> trabajar.** El detector estaba bien. Lo que estaba mal es que yo rellené el
> «qué» con lo que parecía razonable en vez de con lo que decía el código.

## §2 — Los seis números, y el hueco está intacto

Medido con la consulta de
[`salud-de-las-definitivas.php`](../../../tools/salud-de-las-definitivas.php) —la
tabla temporal `calculo_definitivas` y el bloque «deberían existir y no existen»—
**agrupada por `g.id`**. Sólo `SELECT`, sin llamar a la ruta.

```
grupo   antes (7b)        ahora            diff   deberían
 97      760 / 50,0%       760 / 50,0%      0      1.520
 98      776 / 52,4%       776 / 52,4%      0      1.480
105    1.144 / 50,2%     1.144 / 50,2%      0      2.280
 91        0 /  0,0%         0 /  0,0%      0      2.400
 84      112 / 10,0%       112 / 10,0%      0      1.120   ← control
 79        0 /  0,0%         0 /  0,0%      0      2.340   ← control
```

La ruta se llamó **al menos seis veces** esa noche —97 tres, 98, 105, y el 91 dos—
y **tres se cortaron a los 60 s**. El hueco no se movió en ninguno. **El
experimento del front es válido.**

### Y un cero en todas partes no basta: el control positivo

**Seis diffs a cero no distinguen «no escribió nada» de «mi consulta no puede
moverse».** Los controles 84 y 79 no lo resuelven si **nada** se movió en ninguna
parte: dos canarios vivos no prueban que el canario respire. Así que se preguntó si
`notas_finales` se mueve:

```
filas 128.015 · última creada 23 ago 22:45 · última tocada 24 ago 03:45
creadas en 12 h: 8 · tocadas en 12 h: 85 · de ésas, en los seis grupos: 77
```

**La tabla se mueve y la consulta lo vería.** El instrumento responde, y por eso el
cero significa algo.

> Es la forma general del control que este repositorio ya usa en el
> [§176](../05-codigo-muerto-y-roto.md) —*medir un caso que debe funcionar al lado
> del que falla*— aplicada al revés: cuando el resultado es **«nada cambió»**, el
> control que hace falta no es otro caso que tampoco cambie, es **la prueba de que
> algo podría haber cambiado**.

## §3 — La salvedad: 77 definitivas de esos grupos SÍ se tocaron

Desglosado, en las últimas 12 h:

```
grupo  creadas  tocadas  primera            última             updated_by
 97       0       39     24 ago 00:34:50    24 ago 03:45:21    1, 675
 98       0       37     23 ago 19:02:07    23 ago 19:02:08    1
105       0        1     24 ago 00:34:51    24 ago 00:34:51    1
 91       0        0     —                  —                  —
 84       0        0     —                  —                  —
 79       0        0     —                  —                  —
```

**Cero creadas** en los seis, así que el hueco no se pudo mover. Pero **77 filas
actualizadas** en los tres grupos con hueco del 50%, la última hace dos horas. Tres
cosas se deducen sin ambigüedad:

1. **No fue `recalcular`.** `recalcular` **crea** la fila de todo matriculado
   (§9.1) y en el 97 falta el 50%: si hubiera corrido habría creado cientos. Creó
   cero. Lo que escribió **sólo pisa filas que ya existen**.
2. **No fue `bolfinales`.** Por el §1, y porque **el 91 —llamado dos veces— tiene
   cero escrituras**: si la ruta escribiera definitivas, el 91 sería el primero en
   notarlo, porque tiene todas sus filas y un `UPDATE` no necesitaría crear nada.
3. **No fue esta sesión.** Sus tests van contra `simonbolivar_testing_adx`;
   `coste-del-recalculo.php` no ejecuta el UPSERT a propósito; la herramienta del
   historial es sólo `SELECT`.

Quedan como candidatos los botones de *«Calcular definitivas per N»*
(`DefinitivasPeriodosController::putUpdate`) o alguien corriendo algo **contra
`simonbolivar`** en vez de contra una base de tests. `updated_by` **1** y **675**.

**No bloquea el experimento** —el hueco es lo que mide y está entero— **pero lo
condiciona**: si esas tandas siguen cayendo y alguna llega a **crear** filas, el
hueco se mueve a mitad del experimento. La mitigación cuesta un `SELECT`: **medir
el hueco antes y después de cada tanda**, y si se movió, la tanda no vale.

## §4 — Qué escribe `bolfinales` cuando nginx lo corta

**No deja nada a medias, y por una razón de orden: la escritura ocurre ANTES de la
parte lenta.**

```php
// BolfinalesController::detailedNotasGrupo, línea 87 y 100
if (Request::has('aumentar_contador') && Request::input('aumentar_contador') == true) {
    $contador = DB::select('SELECT id, contador_certificados FROM years WHERE … actual=1')[0];
    DB::update('UPDATE years SET contador_certificados=? WHERE id=?',
               [(int) $contador->contador_certificados + 1, $contador->id]);
}
// ── y a partir de la línea 106 empieza lo que tarda:
// Grupo::datos, Year::datos, Grupo::alumnos, y el bucle por alumno con
// definitivasMateriasXPeriodo dentro
```

Es **una fila, una columna**, y **condicional**: sólo si el cuerpo trae
`aumentar_contador`. El comentario del propio código dice que sólo lo manda el
*«Certificado periodos»*, o sea que **para la ruta que el front midió puede no
haber escrito nada** — depende del cuerpo, y eso hay que comprobarlo en el front,
no aquí.

Lo que el 504 deja, entonces, no es trabajo a medias sino tres cosas:

1. **Un número de certificado gastado.** El contador es el **consecutivo visible
   del certificado** —medido en la copia: `007`, `021`, `022`, `037`, `044`, `045`,
   `060`, y el año actual en `143`— y `Year::datos()` lo lee **después** del
   incremento, así que el número nuevo viaja en la respuesta que nunca llega.
   **Cada reintento quema otro número.** En el grupo 97, que revienta siempre,
   tres reintentos son tres folios perdidos.
2. **Una carrera de lectura-escritura.** `SELECT … WHERE actual=1` y luego
   `UPDATE`, **sin transacción y sin `FOR UPDATE`**. Dos personas imprimiendo a la
   vez leen 143 y escriben 144 las dos → **dos certificados con el mismo número**,
   que en un documento oficial es peor que saltarse uno. Es la misma forma que la
   carrera del recálculo de la [§4.3 del 20](../20-pantalla-de-notas.md), y aquí
   tampoco la cierra el cliente: la cierra una transacción.
3. **El incremento pierde el relleno de ceros.** `(int)'007' + 1` es `8`, y la
   columna es `VARCHAR`, así que queda `'8'`. **Siete de los ocho años de la copia
   están rellenados a tres dígitos**, o sea que la convención existe.
   **El `(int)` es correcto y necesario** —sin él `'' + 1` lanza `TypeError` en PHP
   8, y eso ya está arreglado y comentado en el código—: esto es una consecuencia
   de un arreglo bueno, no un error suyo, y por eso va escrito así.

### Y una cuarta que no es del plazo, pero está al lado

`PUT bolfinales/cambiar-contador-certificados` pone el consecutivo **a lo que venga
en el cuerpo**, sin validación, con `auth.personal`. O sea que cualquiera de los 51
profesores puede fijar el número de certificados del colegio. **Decisión de
Joseth**, no de esta sesión; queda apuntado.

## §5 — Lo que se lleva de método

1. **Un detector que dice «escribe» no dice QUÉ escribe.** Rellenar el «qué» con
   lo razonable en vez de con el código costó congelar el trabajo de otro carril.
   El detector estaba bien.
2. **Cuando el resultado es «nada cambió», el control que hace falta no es otro
   caso que tampoco cambie: es la prueba de que algo podría haber cambiado.** Dos
   canarios vivos no prueban que el canario respire.
3. **«Cero creadas» y «cero tocadas» son preguntas distintas**, y en este caso la
   primera decidía el experimento y la segunda destapó que alguien escribe en la
   copia de desarrollo. Contar sólo una de las dos habría dado la respuesta buena
   sin la salvedad.
4. **Para saber si tienes un proceso vivo, contar los que pudiste mirar es parte
   del resultado.** El bucle sobre `/proc/*/environ` distingue tu suite de las
   otras siete —`ps` las llama a todas igual— pero **con `-u 0` aquí sólo se leen
   12 de 25**, y un bucle que no lee nada imprime «ninguna mía» exactamente igual
   que uno que lo comprobó. Lo cazó `9e`. La forma buena imprime
   `procesos=… leídos=… míos=…` y **sólo concluye si `leídos` cuadra con lo que
   `ps` ve**.
