# El nivel 6 no encuentra nada, y el 7 sí — medido el 21 de agosto de 2026

Este documento contesta una pregunta que se dio por hecha: **«el siguiente nivel
de larastan es el 6»**. Se midió antes de subirlo, y la respuesta es que no.

Los niveles 1→5 fueron la vena más productiva del repo: cada subida trajo fallos
reales, y el 4→5 trajo el más caro de la serie con solo 45 errores. La
extrapolación natural —«sigamos subiendo»— falla aquí, y el motivo es que **los
niveles de phpstan no son una escala de severidad, son familias de reglas
distintas**. El 6 es la familia que este proyecto no puede usar y el 7 es la que
más le conviene.

---

## Lo que dio la medición

| Nivel | Errores | De qué son |
|---|---|---|
| 5 (hoy) | 0 | — |
| **6** | **1.940** | anotaciones de tipo que faltan, **el 100%** |
| 7 | 1.276 (sin contar los del 6) | 1.163 de ruido de `stdClass`, **24 con forma de fallo** |
| 8 | 1.452 | lo mismo, más nulos |
| 9 | 2.695 | `mixed` explícito; no aplica a un repo con 990 consultas crudas |

### Por qué el 6 no vale aquí, aunque parezca el siguiente

El nivel 6 pide que cada parámetro, cada retorno y cada propiedad lleven tipo
declarado. Sus 1.940 errores se reparten en cuatro identificadores —
`missingType.return` (846), `missingType.parameter` (786),
`missingType.iterableValue` (155) y `missingType.property` (129)— **y ninguno de
los 1.940 señala código que pueda fallar**. Se revisaron a mano los 24 que no
eran anotación pura (`argument.templateType`, `missingType.generics`) por si
escondían la forma del `count()` sobre un Builder de la §13.1: son
`collect($r->json())` en los tests y genéricos de `TestResponse`. Ruido.

Y hay una segunda razón, que es del repo y no de la herramienta: **1.322 de los
1.940 (el 68%) están en `app/Http/Controllers/`**. Cerrar el nivel 6 significa
tocar los 129 controladores para escribir `mixed` en casi todos los huecos —
exactamente el diff ilegible que CLAUDE.md prohíbe para Pint, y por el mismo
motivo.

La conclusión no es «el 6 algún día»: es que **el 6 se paga en los controladores
el día que se toquen por otra cosa**, un fichero cada vez, como el formato.

### Por qué el 7 sí

El nivel 7 mezcla dos cosas. La mayor parte es ruido inevitable aquí —1.002
`property.notFound` y 161 `method.notFound`, que son las 990 consultas crudas
devolviendo `stdClass`, y por eso `checkModelProperties` está en `false`—. Pero
debajo hay seis identificadores que son **la familia exacta que dio los dos
hallazgos más caros de toda la serie**:

- `count()` sobre un Builder — §13.1, nivel 5, un TypeError en producción.
- `$datos->id = $id` sobre un array — §9, nivel 2, un `Error` de PHP 8.

Los dos son *«se usa como objeto algo que no lo es»*, y eso es precisamente
`method.nonObject`, `property.nonObject`, `offsetAccess.nonOffsetAccessible`,
`foreach.nonIterable`, `assign.propertyType` y `return.type`. **Son 24 en todo el
repo**, y de ahí salió lo que sigue.

---

## §1. La cuarta cara de «una respuesta que miente», y el cuarto cambio de PHP 8

**Arreglado. Lo fija `tests/Contrato/MatriculaReactivadaTest.php`.**

Esto no es un fallo de matrículas. Es otro caso de las dos familias que este repo
lleva persiguiendo todo el mes, y conviene leerlo así antes que como un bug
suelto:

- **Una respuesta que miente**, que ya iba por tres caras — los `abort()` de la
  §12, el `response()->json()` sin `return` de la §35 y el `if` de permiso sin
  `else` de la §37. Esta es la cuarta, y la más cara de las cuatro: durante todos
  los años de PHP 7 la API contestó **200 dejando al alumno sin matrícula**.
- **Un cambio de comportamiento del salto de versión**, sin que nadie tocara una
  línea, como los `== 'true'` de los informes que salieron en el nivel 4. Y con
  la vuelta de tuerca de que el salto lo hizo *más* visible: pasó de mentir a
  reventar.

Lo que sigue es el mecanismo.

`Matricula::matricularUno()` tiene dos bucles casi idénticos: el primero busca
las matrículas **borradas** del alumno en ese año para revivir una, y el segundo
hace lo mismo con las vivas cuando el alumno cambia de grupo. Veinte líneas de
diferencia y la misma estructura, salvo una palabra:

```php
// bucle 1, sobre onlyTrashed()
if ($matricula->nro_folio == null) $matricula->nro_folio = $year->year.'-'.$alumno_id;

// bucle 2, veinte líneas más abajo, idéntico
if ($matri->nro_folio == null) $matri->nro_folio = $year->year.'-'.$alumno_id;
```

`$matricula` vale `false` hasta que el bucle consigue revivir alguna, así que en
la primera vuelta se le está asignando una propiedad a un booleano. **No es una
lectura de más: es la única línea del método que decide el resto**, y lo que hacía
depende de la versión de PHP:

- **PHP 7** — asignar una propiedad a `false` convertía `$matricula` en un
  `stdClass` vacío. Como un objeto es *truthy*, el `if ($matricula)` de la línea
  siguiente entraba **siempre** por la rama de «esta ya sobra, bórrala», así que
  ninguna matrícula se revivía nunca y todas volvían a la papelera. Y al final
  `!$matricula` era falso, con lo que tampoco se creaba una nueva: **el alumno se
  quedaba sin matrícula y la API respondía 200** con un objeto de una sola
  propiedad.
- **PHP 8** — la misma asignación es
  `Error: Attempt to assign property "nro_folio" on false`. O sea **500**.
  Comprobado en el contenedor, PHP 8.4.24.

Que el gemelo de abajo lleve `$matri` es lo que convierte esto en un arreglo y no
en una decisión: **el bucle correcto es la especificación del roto**. Y hay un
tercer testigo, `MatriculasController::putReMatricularuno`, que escribe la misma
línea bien tres métodos más allá.

Alcance: `POST api/matriculas/matricularuno`, `POST api/matriculas/matricular-en`
y `AlumnosController:775`. Se dispara cuando el alumno tiene una matrícula
borrada de ese año — o sea, **justo el caso para el que existe el bucle**: el que
se retiró y vuelve. `MatriculasTest` no lo veía porque cubría las escrituras de
`matricularuno` solo por el lado de los permisos.

Y el detalle que cierra el marco de arriba: **el 500 de PHP 8 es la buena
noticia**. Mientras la conversión de `false` a `stdClass` funcionó, esto no dejó
ni un rastro —ni excepción, ni log, ni código de error— y la secretaría vio un
200 cada vez. El fallo solo se volvió medible el día que dejó de ser silencioso,
que es justo el argumento de por qué `tools/respuestas-que-mienten.py` existe.

## §1.1. `php artisan matriculas:huerfanas`, para saber a quién le pasó

Arreglar el fallo no deshace lo que hizo. Durante los años de PHP 7 esto
respondió 200 dejando alumnos sin matricular, y **eso sigue escrito en dieciséis
bases**. El comando las cuenta, con el mismo criterio que `anios:actuales`: no
decide nada, solo acota la lista a mano de colegio.

Lo que **no** puede hacer, y va escrito en su cabecera: **distinguir el daño del
fallo de una baja legítima**. Un alumno con todas sus matrículas del año en la
papelera puede haber llegado ahí por `matriculas/destroy`, que es una operación
normal. Lo que sí es cierto de todos los que lista es que **hoy no salen en
ninguna lista, ningún boletín ni ninguna acta** de ese año, y eso el colegio lo
puede contrastar en un minuto. Un diagnóstico que afirmara más de lo que sabe se
dejaría de mirar a la tercera vez.

La primera cifra que imprime es la que más dice: **si el colegio no tiene ninguna
matrícula en la papelera, el fallo no se disparó nunca ahí**.

Y ese es el hallazgo lateral de escribirlo: **la copia de desarrollo tiene cero
matrículas en la papelera** y 479 retiros vivos con `estado = 'RETI'`. O sea que
en este colegio los retiros no borran la fila, la marcan — así que el bucle roto
no llegaba a entrar y el daño aquí es nulo. Es perfectamente posible que varios
de los dieciséis salgan limpios, y por eso el comando dice primero cuántas hay
en la papelera y solo después mira a quién le falta: **la pregunta barata va
delante de la cara**.

Lo fija `tests/Contrato/MatriculasHuerfanasTest.php`, y el caso que de verdad
comprueba es el de en medio —hay matrículas en la papelera pero nadie se quedó
fuera—, que es el que un diagnóstico perezoso confundiría con el tercero y
mandaría a revisar dieciséis colegios para nada.

## §2. `segundosDeVida()` emitía un *deprecated* de PHP en cada login

**Arreglado.**

`TokenDeSesion::segundosDeVida()` está declarado `: int` y devolvía
`max(0, Carbon::now()->diffInSeconds($this->expires_at, false))`. En Carbon 3
`diffInSeconds()` devuelve **float**, y `expires_at` casi nunca cae en un segundo
entero, así que la conversión implícita del `: int` disparaba
`Implicit conversion from float 3600.4 to int loses precision` — deprecación de
PHP 8.1 — en **cada** login y cada refresco, porque `expira_en` va en la
respuesta. Ahora el `(int)` es explícito.

Es código nuevo, de la migración: no lo heredamos, lo escribimos nosotros. Lo
interesante es que ni los 438 tests ni el nivel 5 lo veían, porque **un
*deprecated* no rompe nada** — solo llena el log de cada colegio.

## §3. `Services\Login::entrar()` — precisión, no fallo

`User::find($fila->id)` con `$fila->id` en `mixed` deja abierta la rama en que
`find()` devuelve una `Collection`, que es el mismo enredo `first()`/`get()` que
costó el TypeError de la §13.1. Aquí **no es un fallo** —`$fila->id` viene de un
`SELECT u.id`— pero el `(int)` explícito cierra la rama y deja el tipo honesto.

## §4. Tres `foreach` sobre lo que puede ser `false`, en `tools/`

`file()` y `preg_split()` devuelven `false` al fallar.
`tools/indices-que-faltan.php` ahora avisa y sale con código 1 si no puede leer
el fichero, en vez de recorrer un booleano; los dos `preg_split()` de
`tools/generar-seed-test.php` llevan `?: []`. Ninguno era alcanzable hoy; se
cierran porque son de casa y cuestan una línea.

---

## §5. Lo que queda, y de quién es

De los 24, **seis se arreglaron** (§1–§4). Los otros 18 caen en ficheros que
otras dos sesiones tenían en vuelo el mismo día, así que se traspasan medidos en
vez de tocarlos. **Ninguno está comprobado más allá de lo que dice phpstan**: son
pistas con dirección, no hallazgos confirmados.

Por orden de lo que más pinta tiene:

| Dónde | Qué dice el análisis | Por qué mirarlo |
|---|---|---|
| `Perfiles/ImagesUsuariosController.php:135` | `save()` sobre `Acudiente\|Alumno\|Profesor\|stdClass` | Si `$persona` sale del `switch` como `stdClass`, `->save()` es un fatal. Mismo patrón que la §1 |
| `Perfiles/PerfilesController.php:518` | `User::$username (string)` no acepta `string\|false` | Un `false` acabando en la columna del nombre de usuario |
| `Alumnos/Definitivas.php:52,82` | offset sobre `array\|bool` | Ya es zona conocida: los cinco endpoints rotos del `phpstan.neon` |
| `Alumnos/ImportarController.php:520`, `Perfiles/ImagesController.php:192`, `Piars/Utils/UploadDocuments.php:25` | `getRealPath()`/`move()` sobre `array<UploadedFile>\|UploadedFile` | El clásico de `Request::file()` cuando llegan varios |
| `AlumnosController.php:322` | `Alumno::$grupo` no acepta `Grupo\|Collection\|null` | `first()`/`get()` otra vez |
| `tests/Contrato/AniosActualesTest.php` (4), `HuecosDelSeedTest.php:56`, `ImagenesTest.php:635`, `PeriodosTest.php:26`, `YearsTest.php:31`, `tests/Unit/AliasDeFacadesTest.php:81` | `nonObject`, `nonIterable`, `return.type` | De los tests, y de una línea cada uno |


### Y lo que pasó con los 18, unas horas después

**Los 18 son 3.** Se volvieron a medir al final del día, con las otras dos
sesiones ya habiendo cerrado sus frentes, y la mayor parte se había ido sola:
`getRealPath()`/`move()` sobre `array<UploadedFile>|UploadedFile` cayó en dos de
sus tres sitios con el arreglo de las subidas, y el `string|false` del
`username` con el del generador de cuentas — los dos salieron de esta misma
lista, o sea que la lista funcionó como lo que era: **pistas con dirección**.

Los 17 de los tests eran de una línea cada uno y se cerraron aquí:

| Qué era | Cómo se cerró |
|---|---|
| 11 × `assertExitCode()`/`expectsOutputToContain()` sobre `PendingCommand\|int` | Un `comando()` en `CasoDeContrato`. El `int` es el caso de `withoutMockingConsoleOutput()`, que ningún test de contrato usa —lo que se comprueba de un comando de diagnóstico es justo lo que imprime—, así que la rama se cierra en un sitio y no en once |
| `glob()` y `file()` devolviendo `false` | `assertNotFalse()` con su mensaje. En un test, un `?: []` pasaría en verde recorriendo nada |
| `getimagesize()` devolviendo `false` | `(array) false` es `[false]`, así que el ancho salía `false` y la comparación de dimensiones **pasaba a comparar otra cosa sin decirlo**. Es el mismo fallo que persigue todo este documento, esta vez dentro del test que vigila |
| `array<int>` donde se prometía `list<int>` | `array_values()` |

Y `Alumno::$grupo`, que era el único de app/ con arreglo claro: la anotación decía
`Grupo` y el código asigna lo que devuelve `Grupo::find()`, que es `null` cuando el
grupo está en la papelera. **La anotación obligatoria escondía ese caso**; ahora es
`?Grupo`, y el `(int)` de las dos llamadas cierra la rama de la `Collection` como
en la §13.1.

Los tres que quedan —`Definitivas.php:52,82` y `ImportarController.php:520`— caen
**dentro de endpoints que esta misma lista ya tenía como rotos**, así que
arreglarles el tipo no arreglaría la ruta: seguiría respondiendo 500 por lo de
arriba. Van a `phpstan.neon` con nombre, motivo y `count`.

## §6. El interruptor, **puesto**

**Nivel 7 desde el 21 de agosto de 2026**, con las tres reglas de ruido y las
siete de la deuda de anotación diferidas **por identificador y con su número en
el comentario** — nunca en un baseline generado, que las escondería una a una y
dejaría de avisar cuando aparezca una nueva.

No se puso a la vez que los arreglos, y esa espera era la parte deliberada:
`composer run stan` es de las tres sesiones, y dieciocho de los veinticuatro
errores estaban en ficheros que las otras dos tenían abiertos. Poner el gate con
sus errores dentro habría dejado en rojo la herramienta que usan para saber si lo
suyo está bien, que es la forma más rápida de que alguien la desactive. Se puso
cuando el análisis dio **cero**, que es lo que hace que un gate se note solo
cuando algo nuevo lo rompe.

```neon
    level: 7
    ignoreErrors:
        - identifier: property.notFound      # 1.002 · las 990 consultas crudas
        - identifier: method.notFound        #   161 · lo mismo
        - identifier: argument.type          #    89 · lo mismo
        - identifier: missingType.return     # y las siete de la deuda del 6
        - identifier: missingType.parameter
        - identifier: missingType.property
        - identifier: missingType.iterableValue
        - identifier: missingType.generics
        - identifier: argument.templateType
```

Lo que queda encendido son los seis de la familia «se usa como objeto algo que no
lo es», que es la que encontró la §13.1 y la §9. Y el nivel 6 sigue sin subirse:
está escrito arriba por qué, y en el comentario del propio `phpstan.neon` para
que nadie tenga que venir a buscarlo.

**Y lo que hay que quedarse de todo esto, más que los arreglos**: la escalera de
phpstan se había estado subiendo por inercia, un peldaño detrás de otro, y
funcionó cinco veces seguidas. La sexta no, y **medir antes de subir costó veinte
minutos**. Las herramientas de `tools/` existen todas por esta misma razón; esta
vez la pregunta era sobre una herramienta y no sobre el código.

---

# El enlace de reseteo abre cualquier cuenta que comparta el correo

**Medido el 21 de agosto de 2026.** Esto ya no es del nivel 7 — salió de bajar a
mirar una de las entradas del §5 de `09-pendientes.md` que no espera una decisión
sino **un número**: «los correos auto-generados `username@myvc.com` — colisiones
y reseteos cruzados si dos usuarios comparten correo»
([01-plan-seguridad.md](01-plan-seguridad.md)). El número no estaba, y sin él no
se puede decidir nada.

## §8. El `username` sigue viniendo del cliente

`LoginController::putResetPassword` cambia la contraseña así:

```php
UPDATE users SET password=? WHERE username=? and email=? and deleted_at is null
```

El `email` sale del token. **El `username` sale del cuerpo de la petición.** Y
`password_reminders` no guarda a quién se le emitió el token — la tabla tiene
`email`, `token` y `created_at`, y nada más—, así que el endpoint no tiene de
dónde sacarlo aunque quisiera.

El comentario que hay encima de esa consulta dice que «el token manda: la
contraseña solo puede cambiarse en la cuenta cuyo correo recibió el enlace». Es
verdad **hasta el borde del grupo de cuentas que comparten ese correo**; dentro
del grupo elige quien llama. El arreglo anterior cerró «cualquier cuenta del
colegio» y dejó abierto «cualquier cuenta con tu mismo correo», que es un agujero
mucho más pequeño y **exactamente el que el documento de seguridad predijo**.

No se afirma: se demuestra. `tests/Contrato/ResetCorreoCompartidoTest.php` pide
el enlace para un correo compartido por dos cuentas y lo usa contra la segunda —
200 y contraseña cambiada—, y comprueba después que contra una tercera cuenta con
otro correo responde «Token inválido». La protección existe y llega hasta donde
llega.

**No se arregla desde aquí**: `LoginController` estaba en vuelo en otra sesión el
mismo día. El arreglo de fondo es de una columna y no de una línea — que
`password_reminders` guarde el usuario al emitir, que es un dato que
`postRecuperarClave` **ya calcula** y tira.

## §9. Y el número que faltaba: el 91% no puede recuperar la contraseña

`php artisan usuarios:correos-compartidos`, en la copia de desarrollo:

```
  cuentas activas ................. 2321

  NO PUEDEN RECUPERAR CONTRASEÑA .. 2112  (91%)
     sin correo ................... 1435
     el correo no es una dirección  677

  SE RESETEAN ENTRE SÍ ............ 16 cuentas en 8 grupos
```

Las dos cifras son de problemas distintos y **el comando las separa a propósito**,
porque la primera vez que se midió salieron juntas: «690 cuentas en peligro», de
las cuales 674 compartían el correo `@gmail.com` — un dominio suelto, sin parte
local. Esa dirección **`filter_var` la rechaza**, así que `postRecuperarClave`
aborta con 422 antes de tocar la base: esas 674 cuentas no corren el riesgo de la
§8, es que no pueden pedir el enlace. El riesgo real son 16 cuentas, no 690, y la
diferencia entre las dos cifras es la diferencia entre mandar a revisar dieciséis
colegios o no.

De separarlas salió un hallazgo que no estaba en ninguna lista: **4 de los 29
correos auto-generados `username@myvc.com` no son direcciones válidas**, y todos
por lo mismo —`CarlosAndrés@myvc.com`, `MÓNICAXAMARA@myvc.com`,
`JOSUÉ3@myvc.com`, `DÁMARIS@myvc.com`—. El generador pega el nombre de usuario
delante del dominio, y **un nombre con tilde da una dirección que PHP rechaza**.
En un colegio colombiano eso no es un caso raro: es el 14% de los que llevan
correo generado. O sea que el mecanismo que existía **para** que todo el mundo
tuviera correo produce, en castellano, cuentas que no pueden recuperar su
contraseña.

Eso explica de paso por qué `perfiles/reset-password/{id}` —el reseteo a mano de
un superusuario— es una ruta tan usada, y por qué las §26 y §26.1 (la llamada que
dejaba a 1.280 alumnos con la contraseña vacía, y los 51 profesores que podían
resetear a todo el colegio) eran tan graves: **es la única vía de recuperación que
tiene el 91% de las cuentas.**

El comando ordena los grupos por lo que cuesta que pase y no por tamaño —primero
los que llevan un superusuario dentro, después los que cruzan tipos, después el
resto—, y no decide nada: qué hacer con un colegio donde 1.435 cuentas no tienen
correo no es de un script.

## §10. Cerrado — el token ya sabe a quién iba

**Arreglado el 21 ago 2026**, por decisión de Joseth, en `LoginController` y con
una migración. Tres piezas:

1. `2026_08_21_200000_add_username_to_password_reminders_table` añade `username`
   a `password_reminders`.
2. `postRecuperarClave` **guarda** ahí el username. No hace falta calcularlo: ya
   lo calculaba —lo mete en la URL del enlace— y lo tiraba.
3. `putResetPassword` lo saca de la fila del token y **deja de leer el del
   cuerpo**.

Las tres decisiones que hacen que esto sea un arreglo y no un parche:

- **El `username` del cuerpo se ignora, no se compara.** Compararlo dejaría el
  mismo agujero con un paso más —el cliente seguiría eligiendo dentro del grupo,
  solo que teniendo que acertar— y encima *parecería* arreglado.
- **Los tokens emitidos antes de la migración se rechazan.** Quedan con
  `username` nulo y no hay forma de saber a quién iban; caer al comportamiento
  viejo reabriría el agujero durante una hora en los dieciséis colegios, y una
  puerta trasera con fecha de caducidad sigue siendo una puerta. El coste real es
  que quien pidiera el enlace justo antes del despliegue lo pida otra vez.
- **La columna es nullable para siempre**, no por comodidad de la migración.
  Ponerle un valor por defecto convertiría un token viejo en un token válido para
  ese valor.

### Lo que cambia para el cliente, que es poco pero hay que decirlo

Antes, nombrar en el cuerpo una cuenta distinta a la del token dejaba el `UPDATE`
en cero filas y la respuesta era `Token inválido`. Ahora el cuerpo se ignora, así
que el enlace hace lo que decía hacer —resetea a su dueño— y responde
`Reseteado`. `LoginCtrl.ts` manda `$stateParams.username`, que sale **del enlace
que construyó el propio backend**, así que para el cliente real los dos nombres
siempre coinciden y el cambio no se nota. El `Token inválido` que el front sí
sabe leer se sigue devolviendo donde importa: token caducado, token desconocido y
token sin usuario guardado.

### Dos comprobaciones que parecían obligatorias y no lo eran

- **Índice por `token`.** La consulta filtra por `token` y la tabla solo tiene
  `KEY` por `email`, así que `EXPLAIN` da `type=ALL`. **No se crea índice**:
  desde que cada petición purga lo caducado, la tabla tiene **0 filas** y el
  `EXPLAIN` dice `rows=1`. Un índice que no se usa solo cuesta escrituras. Queda
  escrito para que nadie lo añada «por si acaso» — *antes de crear un índice:
  `EXPLAIN`*.
- **Zonas horarias.** `created_at` se escribe con `Carbon::now('America/Bogota')`
  y se compara con `Carbon::now('America/Bogota')->subHour()`: las dos puntas en
  la misma zona, así que la trampa del §2 de `09-pendientes.md` —los `expires_at`
  en UTC de `personal_access_tokens`— **no toca a esta tabla**.

`ResetCorreoCompartidoTest` se queda con la misma forma y la expectativa
cambiada, que es lo que lo hace útil: nació fijando que el token abría la cuenta
de al lado y ahora fija que no. Si alguien vuelve a leer el username del cuerpo,
se pone rojo.

## §11. Mi territorio, cerrado al nivel 7

Con las tres reglas de deuda de anotación diferidas, `app/Models`,
`app/Services`, `app/Support`, `app/Mail`, `app/Console`, `app/Http/Middleware`
y `tools/` pasaron de **57 a 36** errores, y los 36 que quedan son los 31
`property.notFound` de las consultas crudas —`stdClass`, el ruido documentado— y
cinco `argument.type` en herramientas, todos de la misma forma.

Lo que salió por el camino, y es lo que valía la pena:

**Un `year_id` que no existe escribía el folio «-1234» en el libro de matrícula.**
`Matricula::matricularUno()` hacía `Year::find($year_id)`, y `year_id` llega **del
cuerpo de la petición sin validar** (`MatriculasController::postMatricularuno`).
Con un año inexistente, `find()` devuelve null, `$year->year` da un aviso de PHP y
**la matrícula se crea igual**, con el folio empezando por un guion. El folio es
`{año}-{alumno_id}` y es lo que el colegio escribe en el libro físico. Ahora es
`findOrFail((int) $year_id)` → 404, y si no hay ningún año actual —el caso que
diagnostica `anios:actuales`— un 409 con su motivo en vez de «Attempt to read
property on null». Misma familia que la §1: **un dato malo escrito en silencio es
peor que un error**.

**`ImageModel::eliminar_imagen_y_enlaces()`** hacía `findOrFail($imagen_id)` sin
tipo, así que la firma dejaba abierta la rama en que devuelve una `Collection` —
la misma confusión `first()`/`get()` de la §13.1. Hoy ningún llamador puede pasar
un array (uno es parámetro de ruta y el otro una columna), así que es precisión y
no arreglo; pero `$img->nombre` sobre una Collection es una **excepción**, no un
aviso, y la línea de al lado ya borró el fichero del disco. Cerrado con un
`(int)`.

**Seis `fopen()`/`file_get_contents()` sin comprobar en `tools/`**, más los tres
`foreach` de la §4. Ninguno alcanzable hoy; se cierran porque son de casa y
cuestan una guarda. Una herramienta de medición que falla en silencio es peor que
una que no existe: **da un número, y el número es plausible** — que es la lección
que costó una medición de cobertura esta misma tarde.

## §12. La tercera cara: el generador de cuentas fabrica cuentas rotas

`PUT api/perfiles/creartodoslosusuarios` recorre alumnos, profesores y
acudientes y le crea la cuenta al que no la tenga. Es **el mecanismo que existe
para que todo el mundo tenga una**, y es el mismo del que salieron los correos
con tilde de la §9. Fabricaba dos clases de cuenta que su dueño no puede usar.

**Las tildes se borraban en vez de transliterarse.** El generador hacía
`filter_var($nombre, FILTER_SANITIZE_EMAIL)`, que es el sanitizador de **correos**
aplicado a un nombre. Lo que hace con un nombre castellano:

| Nombre | Username que salía | Y ahora |
|---|---|---|
| `José Andrés` | `JosAndrs` | `JoseAndres` |
| `Ñoño` | `oo` | `Nono` |
| `MARÍA JOSÉ` | `MARAJOS` | `MARIAJOSE` |

Arreglado con `Str::ascii()` **antes** del sanitizador, que es la diferencia entre
transliterar y borrar. El sanitizador se conserva detrás porque sigue quitando lo
que `ascii()` deja pasar.

**Con el nombre en blanco, el username salía vacío.** `preg_replace('/\s+/','')`
sobre un nombre de solo espacios da la cadena vacía, el `if ($user)` que
desambigua solo entra si ya existe alguien con ese username, y la cuenta se creaba
con `username = ''`. **Ya pasó**: en la copia de desarrollo hay una así desde 2019
—usuario 842, un acudiente activo— y `acudientes` tiene dos filas con `nombres` en
blanco, que es de donde salen. Ahora cae a `{tipo}{id}` (`acudiente227`).

Y aquí conviene no pasarse: se fue a mirar si esa cuenta era una puerta abierta y
**no lo es**. Tiene su hash bcrypt de 60 caracteres y la contraseña vacía no
entra. Es una cuenta inservible, no un acceso — pero es un cebo esperando a la
próxima operación masiva de contraseñas, que es exactamente lo que fue la §26.

De paso, la desambiguación se leía como una comprobación y era una casualidad:
`sizeof((array) User::where(...)->first()) > 0` funciona porque `(array) null` es
`[]` y un modelo encontrado nunca lo es. Ahora es `exists()`. Una casualidad que
se lee como una comprobación es de las que sobreviven a un refactor
bienintencionado y dejan de funcionar sin que nadie lo note.

**Las tres caras juntas dicen algo que ninguna dice sola.** El mecanismo que
reparte cuentas y correos produce, en castellano, artefactos rotos: correos con
tilde que `filter_var` rechaza (§9), usernames con las tildes borradas y usernames
vacíos (§12). No es un fallo repetido tres veces — es **un idioma que el código no
contempla**, apareciendo en cada sitio donde un nombre propio se convierte en un
identificador.


## §13. El arreglo le quitó la recuperación a ocho cuentas, y el diagnóstico seguía contando el agujero de ayer

**Arreglado el diagnóstico. La decisión de fondo es de Joseth y está abajo.**

Esto salió de volver a correr `usuarios:correos-compartidos` unas horas después
del §10, y es de las cosas que solo se ven mirando el resultado: **el comando
imprimía un problema que ya no existe y callaba el que había creado el arreglo**.

Las dos mitades:

**El comando mentía, como mentía `DESPLIEGUE.md` esta mañana.** Decía
`SE RESETEAN ENTRE SÍ ... 16 cuentas en 8 grupos` y cerraba recomendando el
arreglo que ya estaba hecho —«que `password_reminders` guarde a quién se le
emitió el token»—. Un diagnóstico que manda a arreglar lo arreglado no es ruido:
es la forma que tiene una herramienta de medición de hacer daño, porque el que lo
lea o pierde el rato o deja de creérsela. Se escribió a las 12:20 y el arreglo
entró a las 12:35; **nada avisa de eso salvo volver a correrlo**.

**Y lo que callaba.** `postRecuperarClave` recibe solo el correo —el formulario
del front manda `{email, ruta}` y nada más— y se queda con `$persona[0]`, o sea
la cuenta de **id más bajo** del grupo. Antes, la segunda del grupo llegaba a
cambiar su contraseña nombrándose en el cuerpo al canjear el token, que es
exactamente el agujero que se cerró. Dicho al derecho: **el arreglo de seguridad
le quitó a ocho cuentas la única vía de recuperación que tenían**.

Ocho, en la copia de desarrollo, y las ocho son hermanos con el correo de un
padre — el caso legítimo, no el peligroso. El número que hay que mirar sube de
2.112 a **2.120 (91%)**, y ahora sale desglosado:

```
  NO PUEDEN RECUPERAR CONTRASEÑA .. 2120  (91%)
     sin correo ................... 1435
     el correo no es una dirección  677
     lo comparten y no son la 1ª ..   8
```

El bloque de grupos se queda —compartir buzón sigue importando— pero dice otra
cosa: ya no es «se alcanzan entre sí», es **«quien lea ese buzón resetea la
cuenta a la que le toque el enlace, y a cuál le toca lo decide un `id`»**. Por eso
cada grupo imprime ahora `recibe el enlace: <username>`, que es el dato que no
estaba y del que depende todo lo demás. El orden por superusuario y por cruce de
tipos se conserva, con ese motivo nuevo.

Lo fijan dos tests, y a propósito en sitios distintos: `CorreosCompartidosTest`
comprueba que el comando **cuenta** a la segunda cuenta entre las que no pueden
recuperar, y `ResetCorreoCompartidoTest` comprueba en el endpoint que el token se
emite para la primera. Si alguien cambia eso, lo segundo se pone rojo con el
mensaje escrito: *se decidió algo y hay que contarlo*.

### La decisión, que no es mía — **contestada el 21 ago 2026**

> **«Dejarlo como está.»** Las ocho dependen del reseteo a mano del superusuario,
> igual que las otras 2.112, y `usuarios:correos-compartidos` lo dice en voz alta
> cada vez que se corre. No se re-litiga: si algún día molesta, las tres opciones
> siguen aquí abajo con lo que cuesta cada una.

Devolverle el enlace a esas ocho cuentas se puede, y no reabre nada — pero es una
decisión y toca a los cuatro clientes:

- **Dejarlo como está.** Las ocho dependen del reseteo a mano del superusuario,
  igual que las otras 2.112. Cero trabajo, y honesto mientras el comando lo diga.
- **Que `postRecuperarClave` acepte un `username` para elegir dentro del grupo.**
  Ojo a la distinción, que es la que hace que esto no sea reabrir el §10: leer el
  username **al emitir** solo decide a quién va un enlace que llega igualmente a
  ese buzón; leerlo **al canjear** era dejar que el que llama eligiera qué cuenta
  abre un token que ya tiene. Lo primero no alcanza a ninguna cuenta cuyo correo
  no controles; lo segundo sí. Pero el front hoy no lo manda, así que sin tocar
  `myvc_front` **y la app de Flutter, que es una para los dieciséis**, el cambio
  no hace nada.
- **Darles correo propio**, que es lo que el comando recomienda y lo único que
  además saca a un superusuario de un buzón de familia si algún colegio tiene ese
  caso.

Y la forma, que es la que se repite: **un arreglo de seguridad quita algo, y lo
que quita no aparece en ningún test porque los tests fijan lo que el arreglo
protege.** Lo que lo destapó fue volver a correr la herramienta de medición
*después*, que es el mismo criterio de los tests de contrato —mirar el resultado,
no el estado— aplicado a las propias herramientas.


## §14. El generador roto era el que no se usaba, y el que se usa tenía otros tres

**Arreglado. Lo fija `tests/Contrato/UsernameGeneradoTest.php`.**

La §12 arregló `perfiles/creartodoslosusuarios` y de ahí salió la frase que
resume el día —«no es un fallo repetido tres veces, es un idioma que el código no
contempla»—. Al ir a contar el daño para poder decidir qué hacer con los
usernames ya creados, la cuenta salió **cero**, y esa es la parte interesante:

```
personas con cuenta ....................... 2288
usernames MUTILADOS por el sanitizador ....    0
usernames VACÍOS ..........................    1   (el 842, de 2019)
usuarios activos sin ficha viva ...........   63
```

**El generador de la §12 no llegó a crear ninguna cuenta usable en años.** Moría
en el `attachRole()` de Entrust —que no está instalado— *entre* el `save()` del
usuario y el enlace con la ficha, así que no dejaba cuentas con el nombre
mutilado: dejaba usuarios sin ficha, uno por intento. Los `JosAndrs` de la tabla
de la §12 eran reales como comportamiento y **ficticios como daño**. Que el
arreglo de aquella lo vuelva alcanzable es justamente lo que obliga a mirar el
otro.

Los 63 de la cuarta línea **no son esos**, y darlos por tales fue un error de un
minuto que conviene dejar escrito porque es el que produjo la §15: al ir a
mirarlos uno a uno resultaron ser cuentas administrativas de 2015 y alumnos con
la ficha en la papelera —lo normal cuando alguien se retira—, **todas con su hash
bcrypt intacto**. Ninguna huella del fatal de Entrust. Y entre ellas, diez
superusuarios.

Porque hay otro, y es el que sí se usa: **`OperacionesAlumnos::username_no_repetido()`**,
al que llaman el importador de alumnos (dos veces) y `acudientes/crear-usuario`.
Tenía tres cosas, y la primera está escrita en los datos de este colegio.

### El sufijo se acumulaba

```php
$username_a_verificar = $username_a_verificar.$i;   // sobre el candidato, no sobre la base
```

`Samuel` → `Samuel1` → `Samuel12` → `Samuel123`… A la quinta colisión el username
no es `Samuel5`, es `Samuel12345`. Y no es cosmético: **es lo que esa persona
teclea para entrar**. En la copia de desarrollo se lee tal cual —
`SamuelSamuel12345`, `MatíasMatías1234`, `MariaJoséMariaJosé12`— y durante años
nadie lo leyó como un fallo, porque un username raro se lee como un dato de la
persona.

De paso quedó descartada la otra mitad de esos nombres, que era la sospecha
inicial: **el nombre repetido no es de este código**. Las 75 cuentas cuyo username
es el nombre dos veces tienen todas la misma fecha —febrero de 2018, una
importación—, y el importador de hoy arma el nombre de dos columnas de la hoja,
no de una repetida. Medido antes de arreglar nada.

### Con el nombre en blanco, la cadena vacía — y eso ya es un 500

`users.username` es **UNIQUE**, y en la base hay una cuenta con el username vacío
desde 2019. O sea que el segundo nombre en blanco no fabrica una cuenta
inservible como decía la §12: **choca con la primera**. Por
`acudientes/crear-usuario`, que no tiene `catch`, eso es un 500; por
`acudientes/crear` sería el `abort(422, 'Datos incorrectos')` que tapa cualquier
cosa que pase dentro del `try`.

Ahora cae a `{tipo}{id}`, que lo arma el llamador porque es el único que tiene la
ficha delante.

Y hay que decir por dónde **no** entra: por `acudientes/crear` un nombre en blanco
no llega nunca al generador, porque `acudientes.nombres` es NOT NULL y el
`ConvertEmptyStringsToNull` de Laravel lo convierte en null antes. El test va por
la otra puerta a propósito, y eso está escrito en el test.

### Y `rand(1000, 99999)` como desambiguación, en la puerta de al lado

`AcudientesController::postCrear` no llamaba al generador: se fabricaba el suyo
pegando **cinco dígitos al azar** al nombre. Eso no evita la colisión —
`users.username` es UNIQUE, así que la convierte en un error de clave duplicada
que el `catch` traduce a «Datos incorrectos»— y el precio lo paga el acudiente
todos los días, porque `Maria12345` es lo que tiene que teclear. Ahora llama al
mismo generador que el importador: mira si está libre y **solo numera cuando hace
falta**.

### Las tildes, y por qué esto no era un problema de acceso

Se transliteran, como en la §12. Pero al medirlo salió algo que cambia la
respuesta a la pregunta que quedó abierta —«¿corregimos los usernames ya
creados?»—: **`users.username` es `utf8mb4_unicode_ci`, y esa colación ignora las
tildes**. Comprobado contra la base:

```
"José" = "Jose"   → 1        "Ñoño" = "Nono" → 1        "ADMIN" = "admin" → 1
SELECT … WHERE username = 'maria.beleno'   →  encuentra  maria.beleño  (id 470)
```

O sea que las **113 cuentas con tilde** en el username entran perfectamente
escribiéndolo sin tilde, y también el `exists()` que desambigua las ve. No hay
nada que arreglar ahí, y renombrarlas sería cambiarle el usuario a gente que hoy
entra sin problema. La transliteración se hace por coherencia y porque el
identificador acaba en sitios que **no** son MySQL — el correo autogenerado
`username@myvc.com` de la §9, donde `filter_var` sí rechaza la tilde.

**Lo que queda como respuesta a la pregunta abierta:** de los usernames ya
creados, los mutilados son **cero**, los que llevan tilde **no necesitan nada**, y
el único roto es el vacío de 2019 — una fila, y ahora sin sucesores.

> **Decidido por Joseth el 21 ago 2026, dos cosas:**
>
> - **Los usernames ya creados no se tocan**, incluidos los `SamuelSamuel12345`
>   que dejó el sufijo acumulado. Entran sin problema con lo que tienen, y
>   renombrarlos es cambiarle el usuario a alguien que hoy usa el suyo y avisarle
>   uno a uno. El generador arreglado solo afecta a los que nazcan a partir de
>   ahora, así que un colegio puede acabar con las dos formas conviviendo — y eso
>   está aceptado a sabiendas.
> - **El generador de correos `username@myvc.com` se queda como está.** No se
>   repara la tilde ni se deja de generar. Lo que se sabe de esas 29 direcciones
>   —dominio del proveedor, cuatro inválidas, ninguna la lee su dueño— queda
>   escrito en la §9 y en la lista de decisiones; el día que se toque, el número
>   ya está.


## §15. Los diez superusuarios no son diez personas: seis dicen en su nombre que están inhabilitados

**Medido el 21 ago 2026. `php artisan usuarios:superusuarios`, y lo fija
`tests/Contrato/SuperusuariosTest.php`. No se arregla desde aquí: apagar la
cuenta de alguien es del colegio.**

> **Decidido por Joseth el 21 ago 2026: se queda.** Ni se apagan ni se sale a
> correr el comando en los dieciséis ahora mismo. Queda medido, con su test, y el
> comando lo vuelve a decir cada vez que alguien lo ejecute — que es lo que
> convierte esto en un dato disponible en vez de en un hallazgo perdido.

Salió de comprobar una frase que yo mismo acababa de escribir mal —«los 63
huérfanos son los del generador roto»— y de ir a mirarlos uno a uno en vez de
darla por buena.

`is_superuser` es el permiso más grande que hay aquí, y varios documentos de esta
migración razonan sobre él como si fuera un dato limpio: **«los diez `Admin` son
exactamente los diez `is_superuser`»** ([06 §4](06-autorizacion.md), [09 §0](09-pendientes.md),
[05 §30.2](05-codigo-muerto-y-roto.md)). La frase es cierta y la conclusión que se
saca de ella no:

```
  SUPERUSUARIOS ENCENDIDOS ........ 10
     y el nombre los da por rotos . 6
  apagados o en la papelera ....... 0

    1      administrador                      Usuario   desde 2015-02-24
    2      admin(inhabilitado)                Usuario   desde 2015-02-24   <--
    3      coordinacion(inhabilitado)         Usuario   desde 2015-02-24   <--
    687    convivencia2019(inhabilitado)      Usuario   desde 2019-01-24   <--
    688    admin.psicologia(inhabilitado)     Usuario   desde 2019-01-29   <--
    706    AUXILIAR(inhabilitado)             Usuario   desde 2019-02-14   <--
    1217   admin.maryeline                    Usuario   desde 2021-01-07
    1218   admin.veronica(inhabilitado)       Usuario   desde 2021-01-07   <--
    1495   usuario5213                        Usuario   desde 2022-05-03
    1503   PSICOLILI                          Usuario   desde 2022-05-10
```

**Seis de los diez están `is_active = 1`, fuera de la papelera y con su hash
bcrypt intacto.** El colegio dio por apagadas seis cuentas de superusuario
**renombrándolas**, y el sistema no lee el nombre: lee la bandera, y la bandera
dice que sí. Una de ellas se llama `convivencia2019`.

No es un fallo del código —`perfiles/update` escribe `is_active`, o sea que la
forma de apagar una cuenta existe y funciona— y por eso no se arregla desde aquí:
puede que alguna de las seis se siga usando pese al nombre, y apagar la cuenta de
alguien un lunes por la mañana no es una decisión de esta migración. Lo que sí se
puede es **dejar de suponer**, y de ahí el comando: los lista para que cada
colegio confirme uno a uno, con el mismo criterio que `anios:actuales` y
`matriculas:huerfanas`.

Lo que el comando **no** puede decir, y va escrito en su cabecera para que nadie
le pida más: si una cuenta sin marca pertenece a alguien que sigue trabajando
ahí. Los superusuarios son `tipo = 'Usuario'` y **no tienen ficha** —ni alumno,
ni profesor, ni acudiente—, así que no hay en la base ni un nombre real ni una
fecha de último acceso. La marca en el nombre es la única pista que dejó el
colegio, y por eso es la que se busca; el total sale siempre impreso justamente
porque la lista de marcas se puede quedar corta en silencio.

### Por qué esto importa más que seis filas

Porque **cambia el suelo de tres decisiones que están abiertas ahora mismo**. El
alcance del rol `Secretario` se diseñó sobre «con `Admin` no se puede, porque los
diez `Admin` son los diez `is_superuser`» — y de esos diez, seis son cuentas que
el colegio cree apagadas. La cuenta de la §29 que un docente podía tomar, y la
§26.1 de los 51 profesores que reseteaban contraseñas, valen para estas seis
igual que para las otras cuatro.

Y la forma, que ya salió hoy con el año en la papelera y con el `in_action` de
las actividades: **el colegio expresó una intención por un camino que el código no
mira**. Renombrar no apaga, igual que un año borrado seguía siendo el actual. La
pregunta que lo encuentra es siempre la misma — *¿esto que parece un estado, lo
lee alguien?*


## §16. «Apagar la cuenta» dejaba a esa persona dentro hasta 24 horas

**Arreglado. Lo fijan dos tests nuevos en `tests/Contrato/SesionTest.php`.**

Sale directamente de la §15. Al escribir allí *«si de verdad sobran, lo que las
apaga es `is_active = 0`»* convenía comprobar que eso es cierto, porque es un
consejo que un colegio va a seguir. Y era cierto **a medias**.

Las tres puertas y lo que comprobaba cada una:

| Puerta | ¿mira `is_active`? |
|---|---|
| `Services\Login::entrar()` — el login | **sí**, `abort(400, 'Usuario invalidado')` |
| `POST /api/auth/refresh` — la renovación | **sí**, `400 user_inactivo`, y con su comentario al lado explicando que sin eso el usuario seguiría dentro catorce días |
| `Sesion::resolverDeVerdad()` — **cada petición** | **no** |

O sea que la mitad larga estaba cerrada y la corta no: a quien desactivaban no se
le podía renovar, pero **el token de acceso que llevaba en la mano seguía
valiendo hasta su hora**. Son 60 minutos por la puerta nueva y **24 horas por
`login/credentials`**, que es la que usan los fronts que todavía no conocen
`/api/auth/*` —y por tanto la que más colegios están usando hoy—.

Para el caso que motiva todo esto —apagar la cuenta de alguien un lunes por la
mañana— ese hueco es justo el que importa, y con la §15 delante todavía más: seis
de los diez superusuarios de la copia de desarrollo son cuentas que el colegio ya
dio por apagadas.

El arreglo son cuatro líneas en `resolverDeVerdad()`, y **no cuesta una consulta**:
`$token->tokenable` ya trae la fila entera de `users` y las dos columnas vienen
dentro. Con `is_active` va `deleted_at`, que hoy no escribe ningún endpoint —`App\User`
no usa SoftDeletes y la papelera de usuarios está vacía— pero que `Services\Login`
**sí** filtra al entrar: sin esto, el día que alguien añada el borrado de
usuarios, el que ya tuviera sesión abierta se quedaría dentro renovando cada
catorce días para siempre. Cerrarlo hoy cuesta una línea; encontrarlo entonces,
no.

La respuesta es **401 `Usuario invalidado`** y no el 400 del login, a propósito:
aquí lo que ha dejado de valer es el token, y 401 es lo que el front ya sabe leer
como «vuelve a entrar». Que el mensaje sea el mismo que el del login es
deliberado — es la misma frase para la misma causa.

Y la forma, que es la de la §15 vista desde el otro lado: **la intención estaba
escrita en un sitio que casi todo el mundo lee, y quedaba una puerta que no lo
leía**. Las dos que sí lo miraban tenían el comentario puesto y el razonamiento
hecho; la que faltaba era la que se ejecuta más veces al día.


## §17. `tools/interruptores-que-nadie-lee.py` — y lo que dijo la primera corrida

**Medido el 21 ago 2026. No hay nada que arreglar, y eso es el resultado.**

La forma que más ha encontrado hoy es siempre la misma: **el colegio expresa una
intención por un camino que el código no mira.** Salió cuatro veces en un día y
cada vez por un sitio distinto —el `actual` de un año en la papelera, el
`(inhabilitado)` escrito en el `username`, el `in_action` de las votaciones y el
de las actividades—, así que valía la pena preguntarse si el resto de esa forma
se puede buscar en vez de tropezarse con ella.

El subconjunto mecánico son las columnas `tinyint(1)`: interruptores. La
herramienta las saca del volcado del esquema y las reparte en tres montones —las
que nadie nombra, las que se guardan y se sirven pero **ningún `if` ni ningún
`WHERE` mira**, y las que deciden algo—. Y con `--clientes` pasa por encima de
`myvc_front`, `myvc_front_2` y `myvc_flutter`, que es lo que convierte
«candidato» en una frase que se puede afirmar: *esto no lo lee nadie, en ninguna
parte*.

```
  columnas tinyint(1) distintas ... 157
  ni se nombran .................. 48
  NO DECIDEN NADA ................ 44
  alguien decide con ellas ....... 65

  SIN NADIE QUE LAS MIRE, ni aquí ni allí: 49
```

### Y la segunda pregunta, que es la que decide si importa

Un interruptor que nadie lee solo importa **si alguien lo ha estado pulsando**.
Se contaron las filas encendidas de las 49 en la copia de desarrollo, y la
respuesta es que no: `dis_procesos` tiene 327 filas y **cero** firmas de alumno o
de acudiente marcadas; `change_asked_data`, 96 filas y **cero** campos aceptados;
`unidades` y `subunidades`, 54.516 filas entre las dos y **cero** `por_defecto`.
O sea que casi todas son **esquema muerto**, no una casilla que alguien marca
para nada.

Tres cosas que sí quedan dichas, y ninguna es un fallo:

**1. `users.can_ask` está encendida en las 2.351 cuentas y no la lee nadie.** Es
la única de las 49 con datos dentro, y lo que tiene dentro es su valor por
defecto en todas. Encaja con lo de al lado: los `*_accepted` por campo de
`change_asked_data` y `change_asked_assignment` tampoco los lee nadie y están a
cero en las 131 filas. O sea que **el modelo de aprobación por campo de los
pedidos de cambio está abandonado**: se diseñó campo a campo y lo que quedó vivo
aprueba escribiendo directamente — que es justo el mecanismo de la §39, el que
escribía lo que dijera el cuerpo. Quien retome aquello se ahorra reconstruirlo.

**2. `df_alumnos`, `df_asignaturas` y `df_grupos` no existen para nadie**: cero
filas, y **ni una sola mención en `app/`, `routes/`, `config/` ni los seeders**.
Sus veinte columnas `per{1..4}_manual` / `per{1..4}_recuperada` son la copia
denormalizada de las definitivas que alguien empezó y no terminó. Importa para
[10-definitivas.md](10-definitivas.md), que está parado: **no son un séptimo
sitio que escriba notas**, están muertas. Lo mismo con `default_unidades` y
`default_subunidades`, cero filas y cero menciones.

**3. `matriculas.profes_editar_notas`**, cero encendidas en 3.542 filas, y nadie
la lee. Es la hermana por matrícula de `years.profes_can_edit_alumnos`, que sí
decide dos cosas y **está esperando una decisión** ([09 §5](09-pendientes.md)).
Quien conteste aquella pregunta debería saber que existe esta, porque contesta
sola parte de la duda: alguien ya pensó en un permiso por alumno y no llegó a
usarse.

### Lo que la herramienta no puede decir, y por qué se corre igual

Que una columna esté en el montón de las vivas **no significa que la comprobación
sea la correcta**: `vt_votaciones.locked` la miraba el front —para pintar un
candado— y aun así se podía votar en una votación cerrada. Y el reconocimiento de
«esto es SQL» es por palabras clave dentro de la cadena, no un parser; con 990
consultas escritas a mano acierta lo normal y falla en lo raro, por eso imprime
el número de apariciones de cada una.

Sirve para lo que sirve: **preguntar por una ausencia**, que es lo que no se puede
hacer leyendo. Y el resultado de hoy —«casi todo esto es esquema muerto y nadie
ha pulsado nada»— vale exactamente lo mismo que si hubiera salido lo contrario,
porque la sospecha estaba y ahora está contestada con números.


## §18. «Matriculado» se responde de cinco maneras, y cuál toca depende de la consulta

**Medido el 21 ago 2026. No se arregla: se mide por colegio, que es lo que
faltaba para poder decidir.**

Es el hermano de la §17 con las columnas que no son booleanas. `matriculas.estado`
lleva siete valores —`MATR`, `ASIS`, `PREM`, `PREA`, `FORM`, `RETI`, `DESE`— y las
consultas de `app/` que preguntan «¿está matriculado?» **no usan la misma lista**.
Contando las condiciones sobre esa columna:

```
   MATR 78 · ASIS 67 · PREM 40 · PREA 11 · FORM 8 · RETI 10 · DESE 7
```

`MATR` y `ASIS` los incluyen todas las variantes y `RETI`/`DESE` los excluye
todas — hasta ahí hay acuerdo. Los tres de en medio son el desacuerdo: **un
alumno en `PREM` sale en cuarenta sitios y no sale en otros treinta y ocho**, y
uno en `PREA` o `FORM`, casi en ninguno. No es un fallo con un culpable: son
cinco listas escritas a mano en años distintos, y cada pantalla heredó la que
tenía delante.

**Y aquí es donde importa medir en vez de arreglar.** En la copia de desarrollo
esto no le pasa a nadie: 3.060 `MATR`, 479 `RETI` y **una fila suelta** de cada
uno de los demás. Cambiar sesenta consultas para un caso que aquí no existe sería
tocar listas, boletines y actas a ciegas. Pero la copia de desarrollo es **un**
colegio de dieciséis, y hay uno que es previsible: Joseth contó que *«en octubre
se crea el año siguiente copiando todo del anterior»*, y la prematrícula es
exactamente de esas fechas. Un colegio que la use de verdad tiene alumnos que
salen en unas pantallas y no en otras, y eso se reporta como «la aplicación va
mal», no como esto.

Así que lo que se hace es imprimirlo. `php artisan matriculas:huerfanas` —que ya
había que correr en los dieciséis— dice ahora también en qué estados está cada
colegio y señala los tres ambiguos:

```
  matrículas vivas por estado .. 3542
     MATR     3060
     RETI      479
     DESE        1
     PREM        1   <-- sale en unas listas y en otras no
     ASIS        1
```

Va **antes** del corte por «papelera vacía», que es lo que le pasaba al comando
si no: en este colegio no hay nada en la papelera, así que salía por el `return`
temprano y no habría llegado a imprimirlo nunca. Son dos preguntas
independientes en el mismo viaje.

Lo fija `MatriculasHuerfanasTest`, y el test va **de ida y vuelta en el mismo
método** a propósito: el seed solo tiene `MATR` y `RETI`, así que comprobar la
ausencia del aviso por separado habría pasado en verde sin comprobar nada. Es la
cuarta vez que este repo tropieza con lo mismo —**un fixture que el seed no puede
expresar da un test que pasa sin mirar**— y por eso el caso se crea antes de
negarlo.


## §19. El perfil de cualquiera por su nombre de usuario — y el 500 que tapa una fuga

**Cerrada la ruta el 21 ago 2026, por decisión de Joseth. El fallo de detrás se
documenta y se traspasa: `PerfilesController` es de otra sesión.**

`GET api/perfiles/username/{username}` devuelve `fecha_nac`, `email_persona` y
**`email_restore`** —el correo al que llega el enlace de reseteo— y no comprobaba
de quién era. Con token de alumno y el nombre de usuario de otro: 200 y la ficha
dentro. Lo volvió a sacar el barrido después de arreglar el reseteo, y llevaba
abierta desde siempre; estaba en [05 §14.4](05-codigo-muerto-y-roto.md) esperando
una decisión entre tres salidas.

> **Decidido: que el guard resuelva el username.** De las tres —sacarlo del
> token, enseñárselo al guard, o recortar las columnas— es la que menos rompe: la
> ruta sigue aceptando parámetro, el personal la usa igual, y lo único que cambia
> es que una familia ya no alcanza a nadie que no sea suyo.

`ExigirPersonaPropia` aprende una séptima forma de nombrar a una persona, y solo
eso: `persona.propia:username` resuelve el nombre contra `users` y lo comprueba
**como si fuera `user_id`**, con el camino de siempre — que es lo que hace que un
acudiente siga viendo el perfil de su acudido sin escribir un `if` nuevo.

Tres decisiones pequeñas, y las tres se ven en el test:

- **La resolución no va en `CLAVES`.** Un `username` en el cuerpo de una petición
  suele ser el nombre que se quiere **poner**, no la persona a la que se apunta;
  mirarlo en todas las rutas convertiría un renombrado legítimo en un 403. Por
  eso es opt-in por ruta, como el `{id}` genérico.
- **Un username que no existe pasa.** No nombra a nadie, así que no hay nada que
  proteger, y un 403 diría «ese usuario existe y no es tuyo» — justo lo que un
  guard no debe contar.
- **La comparación la hace MySQL con `utf8mb4_unicode_ci`**, o sea ignorando
  tildes y mayúsculas. Es a propósito la misma regla que el login: si con ese
  nombre se entra, con ese nombre se comprueba. Ver §14.

### Y lo que apareció al preguntar qué pasa con un nombre inventado

Un 500. Y detrás del 500, esto:

`getUsername()` tiene una consulta grande que cubre profesores, alumnos y
usuarios sin ficha, y **una segunda para acudientes** a la que se cae si la
primera no encuentra nada. Esa segunda:

- **no filtra por el nombre**. Su `WHERE` entero es `ac.deleted_at is null`, así
  que devolvería **los 1.000 acudientes del colegio** —documento, fecha de
  nacimiento, correo personal y correo de recuperación de cada uno—;
- y se le pasa un `:username` que **no aparece en el SQL**, así que PDO lanza
  `Invalid parameter number` antes de ejecutarla.

O sea que **lo único que hoy impide que esa ruta entregue el directorio entero de
acudientes es un fallo de binding**. Es la forma de la §1 otra vez —un fallo
tapando a otro— y esta vez con una trampa en el arreglo: lo que sugiere el
mensaje de error es *quitar el parámetro que sobra*, y eso es exactamente lo que
abre la puerta. El arreglo bueno es el contrario, **añadir `and u.username =
:username`**, que es lo que hacen sus tres consultas hermanas — el mismo criterio
que la §1: *el gemelo correcto es la especificación del roto*.

No se arregla desde aquí porque el fichero está en vuelo en otra sesión. Se
traspasa medido y **se fija el 500 con un test que dice en su mensaje de fallo
cuál de los dos arreglos es el bueno**, para el día que alguien lo toque:

```
La segunda consulta de getUsername dejó de reventar con "…". Si el arreglo fue
quitar el parámetro que sobra, la ruta acaba de empezar a devolver los mil
acudientes del colegio: hace falta el WHERE por username.
```

Y una consecuencia lateral del guard que conviene notar: **hasta hoy ese 500 lo
alcanzaba cualquiera con token**; desde hoy, una familia que pida un nombre ajeno
se queda en el 403 y no llega. El guard no arregla el fallo, pero le quita casi
todos los visitantes.

### Lo que costó ponerlo, que es la parte reutilizable

Cerrar una ruta con `persona.propia` no es añadir una línea: son **tres
instantáneas y una lista de excepciones** las que se mueven, y todas a propósito.

- `guards-por-ruta.json` gana una línea. Es el diff que hay que mirar.
- `guard-por-familia.json` pasa `perfiles/*` de 16 a 17 rutas con guard.
- Y con eso la familia **cruza el umbral** del test que busca «la que se quedó
  sola»: al ser mayoría con guard, sus cinco hermanas sin guard pasan a ser la
  excepción y hay que justificarlas una a una. Es el test funcionando, no un
  estorbo — obliga a mirar cinco rutas que nadie había mirado juntas:

| Ruta | Por qué se queda sin guard |
|---|---|
| `GET api/perfiles` | **no devuelve perfiles: devuelve los grupos del año.** Es un catálogo con el nombre cambiado |
| `GET api/perfiles/comprobarusername/{username}` | contesta `{existe: true\|false}` y nada más |
| `GET api/perfiles/usernames` | devuelve los 2.351 usuarios y **hay que cerrarla**, pero antes tiene que dejar de llamarla `UserConfiguracionCtrl` (05 §14.4) |
| `PUT api/perfiles/guardar-mi-email-restore` | no acepta ningún id: saca el usuario del token |
| `PUT api/perfiles/reset-password/{id}` | no es de familia sino el reseteo a mano del personal, defendido por dentro con `Autoriza` (05 §26.1, §29) |
