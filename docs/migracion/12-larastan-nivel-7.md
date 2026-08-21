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
