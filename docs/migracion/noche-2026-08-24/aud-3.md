# AUD-3 — la tabla `auditoria` y su escritor único

> **Lote de la noche del 24 ago 2026, sesión `8myvc-39`.** Rama
> `feat/auditoria-tabla-y-escritor`, árbol `.worktrees/39`.
> Es la **fase 3** de [18-auditoria.md](../18-auditoria.md).
>
> **Lo que este lote entrega:** que exista **dónde** escribir la auditoría y **un
> solo sitio** que escriba. Nada más, y el «nada más» es parte del encargo:
> **los diez `INSERT INTO bitacoras` viejos no se tocan**, y ningún dominio queda
> instrumentado. Eso es la fase 4 y va dominio a dominio con su test.

---

## 0. AVISO: ESTE LOTE AÑADE UNA MIGRACIÓN

**Tabla nueva `auditoria`. Ningún `ALTER TABLE` sobre nada existente, y
`bitacoras` intacta.** Las bases de test de las demás sesiones se quedan viejas
en cuanto esto se funda, y lo que verán es `Table 'auditoria' doesn't exist` con
mensaje creíble y culpa ajena. Se arregla reconstruyendo:

```bash
DB_TEST_DATABASE=simonbolivar_testing_<sufijo> tools/construir-bd-test.sh
```

> Y una trampa medida esta noche, que le pasará a cualquiera que reconstruya
> desde un worktree: `construir-bd-test.sh` hace `cd` a **su** raíz para leer el
> esquema y el seed, pero las migraciones las corre con
> `docker exec … php artisan migrate`, **sin `-w`**, o sea en `/app` — el árbol
> principal. Desde `.worktrees/39` eso construye la base con el esquema de tu
> árbol y **las migraciones del otro**, y la tabla nueva no aparece. Con el
> `PHP_EXEC` puesto sí:
>
> ```bash
> DB_TEST_DATABASE=simonbolivar_testing_39 \
> PHP_EXEC="docker exec -w /app/.worktrees/39 -i 8myvc-app-1" \
>     tools/construir-bd-test.sh
> ```
>
> No se arregla el script aquí porque no es de este lote y lo comparten catorce
> sesiones. Queda escrito porque falla **en silencio**: 92 tablas en vez de 93 y
> ningún error.
>
> **Y el 93 solo no demostraría nada** — podría ser esta tabla o cualquier otra
> cosa del árbol. Lo cierra el número de al lado: `8myvc-7b`, trabajando en el
> árbol raíz y **sin esta migración**, midió **92** en su base aislada. Dos
> mediciones que no comparten supuesto y difieren en exactamente uno; eso atribuye
> la diferencia, que es lo que un número solo no hace.

---

## 1. Lo entregado

| | Fichero |
|---|---|
| La migración | `database/migrations/2026_08_24_120000_create_auditoria_table.php` |
| El escritor único | `app/Services/Auditoria.php` |
| Los tests de contrato | `tests/Contrato/AuditoriaEscritorUnicoTest.php` |
| **El detector** | `tools/escrituras-sin-auditoria.php` — la tercera pata de la fase 3 (§6) |

**No lo llama nadie todavía, y es a propósito** — igual que
`DefinitivasDeAsignatura` estuvo escrita y sin cablear entre su fase 1 y su fase
3. Se escribe antes para que lo que llegue a producción llegue ya medido.

---

## 2. La tabla, comprobada contra la base y no contra el `Blueprint`

Aplicada sobre el esquema congelado y leída de vuelta con `SHOW CREATE TABLE`:
24 columnas, cinco índices, `atribucion` con su default, `actor_user_id`
nullable, `ocurrido_en datetime(3)`. Lo que sigue es lo que **no** se ve leyendo
el §4.2 del plan.

### 2.1 `ocurrido_en datetime(3)` — y el test lo lee de `information_schema`

Es la mitad del arreglo de las horas raras **que el código solo no puede dar**.
El `Reloj` garantiza que lo que se *escribe* es una sola hora; no garantiza que
lo que se *lee* en un colegio sea la misma. Un `TIMESTAMP` convierte al escribir
y al leer con la zona de la sesión de MySQL, y `config/database.php` no la fija:
`@@session.time_zone = SYSTEM`, o sea la del hosting, y son dieciséis cuentas de
cPanel. Con `TIMESTAMP`, si un hosting cambia su zona **todas las filas
históricas se desplazan a la vez** sin que nadie toque la base.

Por eso el test no comprueba «existe la columna» sino su `COLUMN_TYPE` literal:

```php
$this->assertSame('datetime(3)', $tipo, …);
```

Las milésimas tampoco son adorno: dos notas tecleadas en el mismo segundo son
dos líneas distintas del historial, y con precisión de segundo no se sabe cuál
fue primero. `Reloj::ahoraTexto()` formatea con `.v` y coincidimos en ello sin
hablarlo con `8myvc-7b`, que lo escribió pensando en este caso.

### 2.2 `atribucion DEFAULT 'aproximada'` — **desviación del plan escrito, a propósito**

El §4.2 lo dibujaba `NOT NULL DEFAULT 'sesion'`. Aquí es `'aproximada'`.

El valor por defecto es lo que recibe **la fila de quien se olvidó de ponerlo**,
y quien se olvidó es exactamente aquel cuya atribución no hay que creerse.
`DEFAULT 'sesion'` es un instrumento que falla hacia el lado que tranquiliza —la
familia de fallo que CLAUDE.md lleva catalogada— y aquí el lado que tranquiliza
significa afirmar *«esto lo hizo esa sesión»* sin que nadie lo haya comprobado.

**No cambia nada para el escritor**, que siempre escribe la columna explícita.
Sólo cambia qué pasa cuando alguien escriba desde fuera del servicio, que es el
único caso en que un DEFAULT decide algo. Avisado a `8myvc-34` para que lo tumbe
si no lo comparte; es una línea de la migración.

### 2.3 Por qué **no** hay `CHECK` sobre `accion` ni sobre `entidad`

Es la pregunta natural al leer «vocabulario cerrado» y la respuesta es que no se
puede cerrar ahí:

**MySQL 8.0.16 en adelante cumple un `CHECK`; 5.7 lo acepta y lo ignora en
silencio.** Los dieciséis colegios están en cuentas de cPanel distintas y este
repositorio ya se comió una vez que la garantía dependa del hosting — es la §1.2
entera. Una restricción que se cumple en unos colegios y no en otros **es peor
que no tenerla, porque se cuenta como cumplida**.

El vocabulario se cierra donde sí se cumple igual en los dieciséis: en el
servicio, con constantes y una excepción si el valor no está en la lista.

### 2.4 Sin claves foráneas, y los nombres copiados dentro

Ni a `users`, ni a `alumnos`, ni a `notas`, ni a `historiales`. `bitacoras` sí
tiene una a `historiales` **con `ON DELETE CASCADE`**, y eso convierte borrar el
ingreso en **borrar su auditoría**. Por lo mismo `actor_nombre` y `alumno_nombre`
se congelan en la fila: la línea se tiene que poder leer dentro de tres años
aunque la nota, la subunidad y hasta el alumno se hayan borrado.

---

## 3. Los cinco índices, medidos con `EXPLAIN` sobre 200.000 filas

Regla del repo: antes de crear un índice, `EXPLAIN`. Y un `EXPLAIN` sobre una
tabla vacía no distingue nada, así que se pobló con **200.000 filas sintéticas**
—3.000 sesiones, 400 actores, 1.200 alumnos, dos años de fechas— se corrió
`ANALYZE TABLE` y se midieron las cinco preguntas de la pantalla. Después se
vació.

| La pregunta | `type` | Índice usado | Filas | `Extra` |
|---|---|---|---|---|
| qué hizo en este ingreso | `ref` | `aud_sesion` | 67 | — |
| qué ha hecho este profe (rango) | `range` | `aud_actor` | 407 | *Backward index scan* |
| qué le han hecho a este alumno | `ref` | `aud_alumno` | 167 | *Backward index scan* |
| quién cambió esta nota | `ref` | `aud_entidad` | 1 | — |
| barrido por rango (retención) | `range` | `aud_fecha` | 38.074 | *Using index* |

**Ningún `type: ALL` y ningún `Using filesort`** en las cinco. El segundo campo
de cada índice es lo que quita el `filesort`: el orden sale del índice, hacia
atrás cuando la pantalla pide lo más reciente primero. El barrido de retención
además es **covering** — no toca la tabla.

### Y dos cosas que salieron de medir, que no estaban en el plan

**1. Los índices pesan más que los datos.**

| | |
|---|---|
| 198.068 filas | **65,1 MB** en total |
| datos | 26,6 MB |
| **índices** | **38,6 MB** |
| por fila | **~345 bytes** |

Con el matiz honesto: las filas sintéticas llevan `valor_anterior`,
`valor_nuevo`, `resumen`, `ip` y `ruta` a NULL, así que **26,6 MB es un suelo del
lado de los datos** y las filas reales pesarán más. El lado de los índices sí es
representativo. Es el primer número que la **fase 6** —retención y archivado—
tiene para dimensionar, y dice dónde está el coste: no en guardar el rastro, en
poder buscarlo.

**2. El contador de la lista de ingresos necesita una tabla temporal.**
`GROUP BY sesion_id, accion` usa `aud_sesion` para filtrar pero acaba en `Using
temporary`. Un índice `(sesion_id, accion)` lo quitaría. **No se añade**: la
consulta es de la fase 5, todavía no está escrita, y un sexto índice sin una
consulta real que lo pida es justo la intuición que la regla del `EXPLAIN`
prohíbe. Queda anotado para quien escriba esa pantalla, con el `EXPLAIN` ya
hecho.

---

## 4. El escritor único: las tres reglas y qué las sostiene

### 4.1 La escritura ocurrió porque no hubo excepción, nunca porque haya filas

`DB::update` devuelve filas **afectadas**, y MySQL devuelve **0 cuando el UPDATE
no cambia ningún valor**. Guardar 85 encima de 85 es un guardado correcto con 0
filas. Colgar la auditoría de un `if ($res)` registraría esa escritura **como
fallida teniendo el estado correcto** — es la [§13](../09-pendientes.md) que midió
`8myvc-dd`: 4 sitios y 6 rutas contestan hoy `'No guardado'` con 200 por
exactamente ese motivo.

Puesto en la forma de la clase, no en un comentario: **`Auditoria` no tiene dónde
recibir «cuántas filas salieron».** No hay parámetro y no hay método. Un test lo
fija recorriendo por reflexión los parámetros de todos los métodos públicos y
fallando si alguno se llama `$filas`, `$afectadas`, `$resultado`, `$ok`, `$res`…

Y comprobado además **por el resultado**, que es lo que de verdad cuenta:
`test_un_reguardado_sin_cambio_se_registra_igual` hace el `UPDATE` de verdad con
el mismo valor, **afirma primero que MySQL devolvió 0** —si devolviera 1, la
conexión llevaría `CLIENT_FOUND_ROWS` y el test estaría midiendo otra cosa— y
después comprueba que la línea existe con `valor_anterior == valor_nuevo`.

Un reguardado sin cambio **sí se registra**: alguien tocó esa nota, y «quién la
tocó» es la pregunta que la tabla existe para contestar. Se reconoce solo, sin
columna nueva.

### 4.2 Dentro de la transacción del llamante, y sin abrir ninguna propia

`Auditoria` hace un `INSERT` y punto. Si el llamante está en transacción, la
línea entra en ella: si el cambio no se guardó, no hay línea. Lo fija
`test_si_el_cambio_se_deshace_la_linea_tambien`, que revienta dentro de un
`DB::transaction` y cuenta las filas.

Hoy la bitácora de `putUpdate` está dentro del `try` con el `UPDATE` pero **sin
transacción**: un fallo entre las dos deja la nota cambiada y sin rastro.

### 4.3 Nunca abortar la petición, nunca fallar en silencio

`guardar()` atrapa cualquier `Throwable`, devuelve `null` y **registra en el log
la fila entera** —no sólo el mensaje: sin la fila, el log dice que se perdió una
línea y no cuál, que para reconstruir un incidente no sirve.

**Esto quedó probado en vivo y sin querer**, que es la mejor forma de probarlo:
al correr la suite sin el `Reloj` en el árbol (ver §6), los diez tests que
escriben fallaron **por la aserción del test**, no por una excepción escapada, y
el log tenía las diez filas dentro:

```
testing.ERROR: Auditoría no escrita: Class "App\Support\Reloj" not found
{"fila":{"sesion_id":null,…,"accion":"crear","entidad":"nota","entidad_id":1,…}}
```

O sea: una clase que **no existía** no abortó nada y no calló. Es exactamente el
comportamiento que se pedía, comprobado contra un fallo real en vez de contra uno
simulado.

### 4.4 Append-only, y qué se puede prometer de verdad

Sin `updated_at` y sin `deleted_at` en la tabla; ni un `UPDATE` ni un `DELETE`
sobre `auditoria` en `app/`. Lo fija un detector que **recorre `app/` entero,
dice cuántos ficheros revisó** —un «0 encontrados» sin población no distingue *«no
hay»* de *«no miré»*— y que **se comprueba al revés antes de creerle**: se le
pasan cuatro cadenas que sí son una edición (`DB::update('update auditoria …')`,
`DB::table('auditoria')->…->delete()`…) y el test falla si no las reconoce.
Mientras no las reconozca, su cero no dice nada.

> **Lo que ese test NO promete, y se dice para que nadie lo cuente como cerrado:**
> prueba que *este código* no edita ni borra una línea. **No impide un `UPDATE` a
> mano en phpMyAdmin.** Eso sólo se cierra quitándole al usuario de MySQL los
> permisos de `UPDATE` y `DELETE` sobre esta tabla, y eso es una decisión de los
> dieciséis hostings, no de este repositorio.

### 4.5 Quien llama decide el **qué**; nunca el **quién**, el **cuándo** ni el **desde dónde**

El actor, la sesión, la hora, la IP y la ruta los resuelve el servicio. Son justo
las cinco cosas que hoy cada sitio decide distinto, y la razón por la que
`bitacoras` no se puede leer como una línea de tiempo.

```php
Auditoria::registrar()
    ->editar('nota', $id)
    ->deAlumno($alumno_id, $nombre)
    ->en(asignatura: $asignatura_id, periodo: $periodo_id)
    ->de($viejo)->a($nuevo)
    ->guardar();
```

Tres salidas para los casos que no son «una persona editando»:

| | Para qué |
|---|---|
| `->sinActor('jperez')` | un `intento_login` fallido: **no hay actor**, y el username tecleado va en `actor_intentado` |
| `->porElSistema()` | el recalculador único: la definitiva que se **recalcula** no es la que un profesor **teclea** |
| `->porElUsuario($u)` | consola y tests, donde no hay petición de la que sacarlo |

`->denegado(...)` es la quinta acción y **no admite valor viejo ni valor nuevo**,
porque en un intento rechazado no se escribió nada.

### 4.6 Dónde va la llamada — un aviso para la fase 4, que no es de la 3

Lo trajo `8myvc-7b` la misma noche desde su lote: había puesto la validación de
escala **antes** de la comprobación de permisos en `putLote`, y con un periodo
cerrado las notas caían en `fallidas` y la respuesta salía **200 con la lista en
vez del 400 del guard** — un dato fuera de escala tapaba una respuesta de
autorización. Su regla: *la forma se valida antes del permiso sólo cuando no
depende de datos; lo que mira la base va después.*

**A la fase 3 no le aplica** —`Auditoria` no comprueba permisos y no valida nada
del cliente: sólo escribe—. **Pero la misma familia está esperando a la fase 4**,
que es quien va a meter la llamada dentro de esos métodos, y girada queda así:

> **El rastro va después de la escritura, dentro de su transacción, y nunca antes
> de la guarda.** Auditar antes de la guarda deja registrada una escritura que
> **nunca ocurrió**, en la única tabla que existe para contestar qué ocurrió — que
> es peor que no auditarla, porque una línea falsa se lee igual que una cierta.

Queda escrito aquí para que quien haga la fase 4 se lo encuentre puesto en vez de
descubrirlo.

---

## 5. Lo del escritor que no estaba escrito en el plan

### 5.1 De dónde sale el actor: del contexto ya resuelto, y con test del acoplamiento

`User::fromToken()` memoriza el contexto en los atributos de la petición, bajo
una constante **privada** suya. `Auditoria` lo lee de ahí en vez de llamar a
`fromToken()` porque **`fromToken()` aborta con 401 cuando no hay token**, y hay
dos casos que tienen que poder escribir sin él: el `intento_login` fallido y el
comando de consola.

Eso duplica una clave, así que lleva test:
`test_la_clave_del_contexto_es_la_misma_que_la_de_user` compara por reflexión
`Auditoria::CLAVE_DEL_CONTEXTO` con `User::CONTEXTO`. **Sin ese test, renombrarla
allí dejaría todas las líneas sin actor y sin ningún error visible** — la forma
exacta de fallo que este trabajo entero viene a cerrar.

> **Y por eso no se añadió un accesor público a `App\User`**, que sería más
> limpio: ese fichero es del territorio de la fase 2 —la que ata la sesión al
> token— y un fichero tiene un dueño. El acoplamiento con test es el precio de no
> pisar a nadie, y es reversible en cinco líneas el día que la fase 2 lo exponga.

### 5.2 Sin sesión conocida se escribe NULL, **no se adivina**

Es el corazón de la §2 y la decisión que más importa del servicio. Hoy los nueve
sitios que escriben `historial_id` lo resuelven con `order by id desc limit 1`
sobre `historiales`, o sea **el último login de esa persona, no la sesión que
hizo el cambio**. Y no hace falta el caso raro de dos aparatos: el refresco vive
14 días y rota en cada uso, así que quien entre a diario puede llevar **meses**
sin teclear la contraseña, y todas sus escrituras de esos meses colgarían del
mismo ingreso de hace meses.

**Aquí no se adivina.** Si el contexto no trae `sesion_id`, se escribe NULL y
`atribucion = 'aproximada'`. Un NULL dice «no se sabe»; la adivinanza dice «fue
ése» y se equivoca sin avisar.

Y el día que la **fase 2** exponga `sesion_id` en el contexto, esto **no hay que
tocarlo**: el servicio ya lo lee y ya pone `atribucion = 'sesion'`. Hay un test
que lo adelanta —`test_con_la_sesion_conocida_la_atribucion_es_cierta`— para que
el día del despliegue esté comprobado que se usa y no se ignora.

> Un detalle que salió al escribirlo: el contexto trae **`grupo_id = "N/A"`** —la
> cadena— para tres de los cuatro tipos de usuario. Eso no es un id, y meterlo
> como si lo fuera dejaría filas apuntando a un grupo que no existe. El servicio
> sólo acepta numéricos y hay test.

### 5.3 Recortar antes que perder la línea

Con el modo estricto de MySQL, un nombre de 130 caracteres en un `varchar(120)`
es una **excepción**, y el servicio se la traga: la línea no se escribiría en
absoluto. Se recorta por caracteres (las columnas son `utf8mb4`). Entre una línea
recortada y ninguna línea, la recortada: el rastro de quién tocó qué sigue
estando.

Y `null` se guarda como **NULL de SQL, no como el `null` de JSON**:
`json_encode(null)` da la cadena `'null'`, que en una columna `json` es un valor
y no la ausencia de valor, así que `valor_anterior IS NULL` dejaría de
encontrarlo. Son dos cosas distintas —«se creó, no había valor antes» contra «el
valor de antes era null»— y la pantalla filtra por la segunda. Con su test.

### 5.4 Y lo que encontró el único test que falló al fundir el `Reloj`

Merecería estar arriba, porque no lo buscaba nadie y afecta a la fase 5.

Con el `Reloj` ya fundido, diecinueve de los veinte tests pasaron a la primera.
El que falló fue `test_la_hora_escrita_es_la_del_reloj`, con una diferencia de
**17.999 segundos**: las cinco horas, al segundo. Y **no era el `Reloj`**: era el
test leyendo la columna con `strtotime()`.

El razonamiento, que es lo que importa:

`ocurrido_en` guarda **hora de pared de Bogotá** en un `DATETIME`. Eso es la
decisión 1, y es lo que hace que lo escrito sea lo leído en phpMyAdmin y en los
dieciséis colegios. El precio no estaba escrito en ninguna parte: **la columna no
se describe a sí misma.** La cadena `2026-08-24 03:51:13.000` no lleva la zona
dentro, y `config/app.php` sigue en UTC por la decisión 2 — así que **cualquier
PHP que la lea sin decir la zona la interpreta como UTC y la desplaza cinco
horas**.

> **La decisión 1 y la decisión 2 son correctas cada una por su lado, y juntas
> dejan una trampa en el camino de vuelta.** El `Reloj` cierra la **ida**: todo lo
> que se guarda sale de un sitio. La **vuelta** está abierta, y no la vigila nadie:
> `RelojUnicoTest` caza a quien **escribe** con el reloj equivocado, no a quien
> **lee**. Un `strtotime()` o un `new Carbon($fila->ocurrido_en)` sobre esta
> columna no lo detecta ninguna herramienta de hoy, y falla dando **una fecha que
> parece correcta**.

Aquí se reprodujo dentro de un test, que es el único sitio donde no hace daño. Se
arregló leyendo con la zona explícita:

```php
Carbon::createFromFormat('Y-m-d H:i:s.v', $fila->ocurrido_en, Reloj::ZONA)
```

**Dónde sí haría daño: la fase 5.** Sus cuatro endpoints leen esta columna, y un
ingreso pintado cinco horas movido saldría justamente en la pantalla que se pidió
porque «salen horas extrañas». Propuesto a `8myvc-7b`, que es de quien es el
fichero: al `Reloj` le falta la mitad de vuelta —un `desdeTexto()`— por el mismo
argumento con el que su cabecera justifica `ahoraTexto()`, que existe *para que no
haya que acordarse del formato*. Quien lee tiene que acordarse del formato **y
además de la zona**, que es peor. No se escribe desde aquí porque sería un segundo
sitio decidiendo lo mismo, que es de lo que se viene.

---

## 6. El detector — y los dos números del plan que corrige

La fase 3 del [18](../18-auditoria.md) son tres cosas, no dos: la tabla, el
servicio y **el detector** que compara las escrituras de `app/` con las que
llaman al servicio. No estaba en el encargo de este lote pero sí en la fase, y es
lo que da **la lista de trabajo de la fase 4** y lo que dirá cuándo está
terminada. Va aquí.

### 6.1 Es `.php` y el plan lo nombraba `.py`, y hay una razón con número

La pregunta *«¿esto es una escritura?»* la contesta **exacta** el analizador de
PHP y **aproximada** una expresión regular, y la diferencia se cobró antes de la
primera línea:

```
grep -rnE "DB::(insert|update|delete|statement)\(" app/ | wc -l   ->  257
```

y el plan decía **256**. Con `token_get_all()` los comentarios llegan como
`T_COMMENT` y el SQL como `T_CONSTANT_ENCAPSED_STRING`, así que ni un
`// DB::update()` ni un `'DELETE FROM notas'` se cuentan como llamadas. **No hay
que acordarse de la diferencia: el analizador ya la sabe.**

### 6.2 Las escrituras no son 256: son **252**

Las cinco de diferencia se miraron **una a una** —la regla del repo es que un
detector da sitios donde mirar, no una lista de fallos— y **las cinco están
dentro de comentarios**:

| Sitio | Qué es |
|---|---|
| `LoginController:147` | un comentario que **habla** de `DB::update()` |
| `LoginController:383` | dentro de `/* */`: un `INSERT INTO matriculas` desactivado |
| `PerfilesController:970` | dentro de `/* */` |
| `Nota.php:61` | dentro de `/* */`: `crearNotas()` entero, comentado |
| `NotaFinal.php:253` | dentro de `/* */`, en `calcularAsignaturaPeriodo` |

**No cambia el argumento del §0** —10 contra 252 cuenta la misma historia que 10
contra 256— pero el número que se publica es el que se cita después, y éste es el
bueno.

### 6.3 Y un número del plan que sale **confirmado, no corregido**

El detector encuentra **10 `INSERT INTO bitacoras` en 10 métodos**, exactamente
los diez del §0. Vale la pena decirlo porque **ese número se contó a mano y se
publicó mal la primera vez** (se dijeron 9, y lo cazó
`CentinelaDeLosEscritoresDeBitacoraTest`). Ahora hay dos caminos que no comparten
supuesto —una lista escrita a mano con centinela, y un recuento por tokens que
reconoce la tabla **por la consulta**— y dan lo mismo. Es lo más cerca de una
comprobación que hay aquí.

### 6.4 Qué imprime, y las dos cosas que dice de sí mismo

```
ficheros de app/ revisados ....... 219
escrituras de datos .............. 252
de ellas, `INSERT INTO bitacoras`  10
métodos que escriben ............. 159
  con rastro NUEVO (Auditoria) ... 0
  con rastro VIEJO (bitacoras) ... 10   <- traducir al servicio
  SIN NINGUNO .................... 149   <- decidir qué se graba
```

**Separar «rastro viejo» de «ningún rastro» no es cosmética**: son dos trabajos
distintos. Diez métodos hay que **traducirlos** —el rastro existe y hay que
moverlo al servicio—; en ciento cuarenta y nueve hay que **decidir qué se graba**
en un dominio donde nunca se grabó nada. Juntarlos daría un número más grande y
una lista peor.

Y las dos advertencias que la herramienta lleva dentro, porque un cero suyo tiene
que poder leerse:

- **La unidad es el método, no la sentencia.** Un método que escribe tres veces la
  misma fila es **un** cambio para quien lee el historial, no tres líneas.
- **Y por eso no demuestra que cada escritura esté auditada.** Que un método llame
  al servicio dice que alguien pensó en el rastro ahí, no que lo haya puesto en
  las tres ramas. Por eso imprime `escrituras:auditorías` de cada método: un
  **`5:1` es un sitio donde mirar**, y el que mire decide. Es la segunda mitad de
  la regla del repo, dicha en la salida en vez de esperar a que alguien lea nueve
  y entienda otra cosa.

### 6.5 La trampa que encontró su propia autoprueba

El primer recuento dio **una** escritura «fuera de cualquier método»,
`LimpiarHtmlPiar.php:132` — que está dentro de un método de toda la vida. Un uno
es lo bastante pequeño para archivarlo como rareza. **Era el detector**, y el
fallo es bonito:

En `"UPDATE {$tabla} SET …"`, el `}` de cierre llega como el token suelto `'}'`
—igual que el que cierra un método— pero el `{$` de apertura llega como
`T_CURLY_OPEN`, que es un token **de array**. Contando sólo los literales,
**cada variable interpolada resta una llave sin haber sumado ninguna**, y a partir
de ahí la profundidad va corrida.

Lo peor de ese fallo es dónde aparece: no en el método que tiene la
interpolación, sino en **el siguiente**. Por eso la autoprueba tiene ahora dos
métodos seguidos —uno con llaves interpoladas y otro detrás— y comprueba **el de
detrás**, que es el que se rompería.

`--autoprueba` pasa las **siete** trampas: el comentario de línea, el bloque
`/* */`, el SQL dentro de una cadena, el `closure` anónimo, las llaves
interpoladas, el método siguiente, y el rastro viejo de `bitacoras`. **Mientras no
las reconozca, ningún número suyo vale**, y lo dice él mismo al fallar.

### 6.6 Y algo que salió de camino, y no es mío

Mirando las cinco discrepancias apareció
`PerfilesController::getQuieroCambiarContrasenia`, comentado, con su comentario
encima: *«Para recuperar una contraseña en caso de emergencia. Volver
comentario.»* Lo que hace es

```sql
UPDATE users SET password=? WHERE id=1
```

sin comprobar nada, desde un `GET`. **Hoy no está enrutado y no es una fuga**: es
código comentado. Pero su propio comentario dice que se **descomenta** cuando hace
falta, así que existe como herramienta de guardia — y mientras está descomentada,
cualquiera que sepa la ruta le pone la contraseña que quiera al usuario 1.

> **Y lo peor no es lo que hace: es su procedimiento.** Lo señaló `8myvc-34` al
> registrarlo, y es la mitad que se me pasó: *«se descomenta a mano y se vuelve a
> comentar»* **depende de que alguien se acuerde de la segunda mitad**, en
> **dieciséis copias distintas de `app/`** —que es copia por colegio, no
> compartida—. **Nadie sabría decir hoy si en alguna quedó descomentada**, y ésa es
> una pregunta que no se puede contestar desde este repositorio.

No se toca desde aquí: no es de este lote, y el `05` es de coordinación. Registrado
por `8myvc-34` con el párrafo de arriba dentro.

---

## 7. La dependencia: `App\Support\Reloj` (AUD-1)

`app/Support/Reloj.php` y `tests/Contrato/RelojUnicoTest.php` los lleva
`8myvc-7b`. **No se han tocado y no se han copiado**: copiarlos habría metido
trabajo ajeno dentro de este commit, que es la ventana que el árbol por sesión
viene a cerrar, y escribir un segundo reloj habría dejado el proyecto en el
problema exacto que la fase 1 quita.

Firma acordada con `8myvc-7b` y confirmada por él como estable:

```php
App\Support\Reloj::ZONA         = 'America/Bogota'   // const
App\Support\Reloj::ahora()      : Carbon
App\Support\Reloj::ahoraTexto() : string             // 'Y-m-d H:i:s.v'
```

`Auditoria` llama **sólo a `ahoraTexto()`**, una vez, en `guardar()`. Y su aviso
recogido: `ahoraTexto()` sólo vale contra `DATETIME`, nunca contra `TIMESTAMP`
—comprobado, §2.1—.

`Auditoria` **no usa `Carbon::now()` ni `now()`** en ninguna parte, así que no
necesita entrar en la lista de excepciones de `RelojUnicoTest`. Confirmado por
`8myvc-7b`.

---

## 8. Lo que este lote NO hace, y por qué cada cosa

| | Por qué |
|---|---|
| **Los diez `INSERT INTO bitacoras`** siguen donde están | Es la fase 4, dominio a dominio con su test. `bitacoras` deja de escribirse el día que el escritor nuevo esté **desplegado**, no fusionado |
| **`DELETE bitacoras/destroy/{id}`** sigue enrutado | **Ya no espera decisión: espera turno.** Decidido esta noche —«nadie borra un intento fallido, y el botón desaparece»— y **con orden obligatorio**: el front quita primero los dos botones, y sólo cuando eso esté **desplegado** el backend retira la ruta. Al revés son dos botones dando 404 en dieciséis colegios |
| **`can_view_auditoria`** y las seis rutas viejas con `auth.personal` | Van **juntos en su fase**. Poner el permiso dejando las viejas abiertas lo convierte en decoración, y hacerlo a medias es peor que no hacerlo |
| **`Sesion.php` y los dos middlewares** | Fase 2, y están cogidos por otra sesión |
| **Ninguna ruta nueva** | La fase 3 no enruta nada: el contador sigue en 542. Las cuatro rutas de la pantalla son la fase 5, y **cuatro rutas nuevas son una decisión** |
| **`database/schema/mysql-schema.sql`** sin tocar | Es el volcado congelado de **producción**, y producción va por detrás de la rama. La tabla la pone la migración, que es lo que cada colegio correrá al desplegar |

**Nada de este lote cambia un cuerpo, un nombre de campo ni una ruta que exista
hoy.** Cero avisos para los cuatro clientes.

---

## 9. Estado

`tests/Contrato/AuditoriaEscritorUnicoTest.php`, **20 métodos, 156 aserciones,
los veinte en verde**, medidos en **corrida aislada** con `--filter` y con el
`Reloj` fundido (`33c3db8` y `73e3b79`, de `8myvc-7b`; los dos merges limpios).

| | |
|---|---|
| Los 20 del lote | **verde**, en corrida aislada |
| `pint` | **PASS**, 267 ficheros |
| `stan` nivel 7, **caché limpia** | **1 error, y no es de este lote** — ver abajo |
| `secciones-citadas.py` | **0 huérfanas** sobre 1.306 citas |
| `escrituras-sin-auditoria.php --autoprueba` | **7 de 7** |
| **La suite completa** | **1.396 tests, 9.445 aserciones, en verde.** Pagada — y encontró uno. Ver §9.1 |

### 9.1 La suite completa, pagada — y encontró un rojo que era mío

**1.396 tests, 9.445 aserciones, 543 s, en verde.** Corrida limpia, con lo que las
tres corridas anteriores enseñaron: `ps` **dentro del contenedor** antes (0
huérfanos), la población de la base impresa primero (93 tablas, 2.351 usuarios,
`auditoria` en 0 filas), y **un fichero de salida propio de esa corrida**.

Los tres intentos anteriores fueron tumbados por la máquina y no por el lote —uno
se cortó con `context canceled`, otro coincidió con `Exited (137)` de la base, y el
tercero arrancó con una corrida anterior **todavía viva** escribiendo al mismo
fichero con `>` y contra la misma base—. De ahí se retiró el «94 tests, cero en
rojo» que se había reportado: la conclusión se sostenía por otra vía, pero el
número era una mezcla imposible de separar.

**Y la corrida buena encontró dos rojos, los dos de este lote.** Merece quedar
escrito porque durante horas se dijo que este trabajo no rompía nada, y no estaba
medido:

```
⨯ los escritores de bitacora siguen siendo diez
⨯ cada escritor sigue estando donde estaba
      +    'app/Services/Auditoria.php' => 1
```

`CentinelaDeLosEscritoresDeBitacoraTest` cuenta los escritores con
`preg_match_all('/INSERT\s+INTO\s+bitacoras/i', $codigo)` **sobre el texto del
fichero, comentarios incluidos**. Y el docblock de `App\Services\Auditoria`
explicaba por qué existe la clase con esa frase dentro. **La documentación contaba
como el escritor número once.**

El centinela tenía razón en el número: había once coincidencias. Lo que no había
era once escritores.

Arreglado por este lado —la frase va con letra y sin la sentencia entera, con una
nota dentro para que nadie la reescriba «de la forma natural» otra vez— y el
centinela vuelve a dar **3 verdes**.

> **Y la lectura útil es la contraria de la que parece: el centinela hizo su
> trabajo.** El número se movió y avisó. Que la causa fuera un falso positivo suyo
> no lo invalida — lo que habría sido grave es que **no** se hubiera movido.
>
> Su fragilidad es real y no se toca desde aquí: es de AUD-1 y un fichero tiene un
> dueño. Está avisada, con la ironía que le corresponde — **el centinela nació de un
> recuento a mano que publicó 9 en vez de 10, y sigue contando por el método que
> produce ese tipo de error**, sólo que hacia el otro lado. El arreglo ya existe
> escrito en esta misma fase: `tools/escrituras-sin-auditoria.php` los reconoce
> **por su consulta y sobre tokens**, y coincide en 10.

### 9.2 El `stan` que queda en rojo no es de aquí, y cómo se supo

Con la caché de phpstan **compartida** (`/tmp` es común a los catorce árboles del
contenedor) salía un `ignore.unmatched` en `PiarsConfigController`. Con la caché
borrada **desaparece**: era la caché, no el código. Es la misma familia que todo
lo demás de esta noche.

Limpia, queda **un** error, en `app/Http/Controllers/ProfesoresController.php:473`
(*«Negated boolean expression is always true»*), que **no es de este lote**:
es uno de los ficheros que un commit mío arrastró a `main` sin querer (§9.3), y
llegó allí **sin la pasada de larastan de su autor**. Ninguno de los cuatro
ficheros de AUD-3 aparece.

### 9.3 Y el error propio que hay que dejar escrito

Un commit de esta sesión —`9cb4409`— **fue a parar a `main`**, no a esta rama, y
se llevó dentro el trabajo sin commitear de cinco sesiones bajo un mensaje que
hablaba de otra cosa. El contenido no se perdió; la firma sí. No se reescribe —de
ese historial cuelgan tres árboles vivos— y la autoría quedó anotada con
`git notes`.

**El mecanismo importa más que el error**, porque es el mismo que mordió a cuatro
sesiones esa noche por caminos distintos: un `cd` al árbol raíz dejó **el shell**
allí mientras el `docker exec -w` seguía apuntando a este worktree. **Los tests
siguieron diciendo la verdad sobre el sitio equivocado.** Nada se puso rojo.

De ahí salieron tres reglas, ya en las órdenes permanentes:

- **Se commitea nombrando los ficheros uno a uno.** Nunca `git add -A`, ni en tu
  propio árbol.
- **Antes de commitear, `git rev-parse --abbrev-ref HEAD`.** Si no dice tu rama,
  estás en el árbol de otros — y un `docker exec -w` correcto no dice nada del
  sitio donde está tu shell.
- **Antes de lanzar una suite, mira si ya tienes una viva** — y **dentro del
  contenedor**: `docker exec 8myvc-app-1 ps -ax | grep phpunit`. Un `ps` del host
  no ve esos procesos, y matar el `docker exec` **no mata el `php` de dentro**.

Y el diagnóstico que salió de tirar de ese hilo, que es de la máquina y no de este
lote: `8myvc-database-1` **no tiene límite de memoria propio**
(`HostConfig.Memory: 0`), así que **no puede pasarse**: cuando la VM de Docker
—7,65 GiB para todo— se queda sin memoria, el kernel elige a la víctima más gorda
y ésa es siempre MySQL. Cada muerte deja `phpunit` huérfanos dentro del contenedor
—**15 vivos, 2.187 MB**, de nueve a cuarenta y nueve minutos— y con menos memoria
libre la siguiente muerte llega antes. **Es realimentación**, y explica por qué
reiniciar la base no arreglaba nada.

> **La frase que resume las seis de la noche**, y no es de este lote sino de todas:
> el `cd` de aquí, el `PDO` con credencial inventada de coordinación, el `vendor/`
> con symlink, el `construir-bd-test.sh` sin `-w`, las dos suites simultáneas y el
> `ps` del host. **El instrumento correcto sobre el objeto equivocado.** Ninguna se
> ve mirando el resultado, porque el resultado es correcto; sólo se ven
> preguntando **sobre qué** se midió.

---

## 10. La comprobación al revés — y las dos garantías que no estaban probadas

La §1.1 de las órdenes de la noche pide comprobar al revés lo hecho: **romper el
arreglo y contar cuántos tests caen.** Se hizo garantía por garantía, rompiéndola
a mano en la base de esta sesión, corriendo los tests del lote y revirtiendo. Diez
roturas, una a una y nunca dos a la vez, porque el punto es **la atribución**: no
que caiga algo, sino **qué** cae.

| Se rompe | Caen | Quién lo caza |
|---|---|---|
| *(nada — línea base)* | **0** | — |
| `ocurrido_en` → `TIMESTAMP(3)` | 1 | la hora no la convierte el hosting |
| `ocurrido_en` → `DATETIME` sin milésimas | 2 | la del tipo **y** la de la hora escrita |
| aparece `updated_at` | 1 | la tabla no tiene dónde editarse ni borrarse |
| aparece `deleted_at` | 1 | idem |
| `actor_user_id` → `NOT NULL DEFAULT 0` | 7 | un intento de login cabe sin actor, **y otros seis** |
| `atribucion` → `DEFAULT 'sesion'` | 1 | quien no dice la atribución no la da por cierta |
| **`valor_anterior` → `varchar(255)`** | **0** | **nadie — hallazgo** |
| **`DROP INDEX aud_sesion`** | **0** | **nadie — hallazgo** |

Los siete primeros están probados: la rotura cae, y cae donde debe. Y **el caso del
`NOT NULL` enseña la otra cara**: tumba siete tests, o sea que su guardián es
ancho. No se estrecha —cazar de más no es un fallo—, pero queda dicho para que
nadie lea «siete tests protegen `actor_user_id`».

### 10.1 Los dos huecos, y por qué no se veían

**`valor_anterior` y `valor_nuevo` como `json`.** Había un test del *null* de SQL
contra el de JSON, y **no valía para esto**: comprueba lo que hace el **servicio**,
no de qué tipo es la **columna**. Con `valor_anterior` convertido a `varchar(255)`
seguía verde. Y el tipo importa por dos motivos que no son teóricos:

- **El tamaño.** Un `json` de MySQL aguanta lo que un `LONGTEXT`; un `varchar(255)`
  no. `valor_nuevo` va a llevar filas enteras cuando la fase 4 instrumente
  disciplina y frases, y con la columna estrecha eso es una excepción que este
  servicio **se traga** — la línea perdida, en silencio, **justo en las escrituras
  más grandes**.
- **La fase 5 consulta dentro** (`JSON_EXTRACT`, `->>`) para pintar el antes y el
  después. Sobre texto no se puede.

**Los cinco índices.** Estaban **medidos** con `EXPLAIN` sobre 200.000 filas y
escritos en la §3, pero **una medición no es un guardián**: nada impedía que el
siguiente `ALTER` se llevara uno y «qué hizo en este ingreso» pasara a recorrer la
tabla entera sin que nada se quejara. La medición dice que el índice **sirve**; no
dice que **siga ahí**.

### 10.2 Los dos tests nuevos, y comprobados al revés otra vez

`test_los_dos_valores_son_json_y_aguantan_una_fila_entera` y
`test_los_cinco_indices_de_las_cinco_preguntas_siguen_ahi`. Con ellos, las mismas
roturas ya no pasan:

| Se rompe | Antes | Ahora |
|---|---|---|
| `valor_anterior` → `varchar(255)` | 0 | **1** |
| `valor_nuevo` → `varchar(255)` | 0 | **1** |
| `DROP INDEX aud_sesion` | 0 | **1** |
| *(nada)* | 0 | **0** |

El de los valores comprueba **el tipo y el viaje de ida y vuelta** de algo que no
cabría en 255 caracteres —y afirma primero que no cabe, porque si cupiera no
mediría nada—. El de los índices dice **dentro de sí mismo lo que no promete**:
fija que los cinco existan, **no** que el planificador los use, y no puede — en la
base de tests `auditoria` está vacía y con cero filas MySQL no elige ningún
índice. Esa mitad se midió con la tabla poblada y vive en la §3. Decir aquí que se
comprueba sería la afirmación de más contra la que este repositorio escribe sus
detectores.

**Total: 22 tests, 170 aserciones.**

### 10.3 Y una trampa del propio arnés, que costó la primera vuelta entera

La primera pasada dio **«caen 20»** en las cuatro roturas, incluidos tests que no
tienen nada que ver con lo roto. Eso no es un resultado: es el arnés.

Lo que pasaba: la reconstrucción de la base anterior había hecho su
`DROP DATABASE` y **había muerto antes de cargar el esquema** —MySQL se cayó en
medio—, así que la base existía y estaba **vacía**. Los veinte fallaban en el
`setUp`.

Se vio rápido por una razón que merece nombre: **`CasoDeContrato` ya tenía la
guarda**, y falla con la frase exacta —*«La base 'simonbolivar_testing_39' está
vacía. Constrúyela con: tools/construir-bd-test.sh»*— en vez de dejar veinte
fallos de aserción sin explicación. Un veinte redondo también ayudó: cuando cae
**todo**, la causa casi nunca es lo que acabas de romper.

El arnés lleva ahora esa comprobación dentro: si la salida menciona la base vacía,
**aborta y dice que la medición no vale** en vez de imprimir un número.
