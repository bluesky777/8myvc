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

---

## 1. Lo entregado

| | Fichero |
|---|---|
| La migración | `database/migrations/2026_08_24_120000_create_auditoria_table.php` |
| El escritor único | `app/Services/Auditoria.php` |
| Los tests de contrato | `tests/Contrato/AuditoriaEscritorUnicoTest.php` |

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

---

## 5. Las tres decisiones del escritor que no estaban escritas en el plan

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

---

## 6. La dependencia: `App\Support\Reloj` (AUD-1)

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

## 7. Lo que este lote NO hace, y por qué cada cosa

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

## 8. Estado de los tests

`tests/Contrato/AuditoriaEscritorUnicoTest.php`, 19 métodos.

**Sin el `Reloj` en el árbol: 9 pasan, 10 fallan**, y los diez fallan por lo
mismo y sólo por eso —`Class "App\Support\Reloj" not found`, con la fila entera en
el log—. No es un verde fingido ni un rojo que esconda otra cosa: son los diez
que llaman a `guardar()`.

En cuanto la rama de `8myvc-7b` esté fundida se corre la tanda entera y este
apartado se cierra con el número de verdad.
