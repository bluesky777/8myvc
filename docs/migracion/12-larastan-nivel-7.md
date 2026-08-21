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

## §6. El interruptor, escrito y **sin poner**

Subir el gate a 7 es lo que convierte esto en un trinquete en vez de una limpieza
de un día. No se aplica aquí por una razón de calendario y no de criterio:
**cambia `composer run stan` para las tres sesiones**, y dieciocho de los
veinticuatro errores están en ficheros que las otras dos tenían abiertos. Se deja
listo para pegar el día que sus frentes cierren, con los tres identificadores de
ruido diferidos por regla —no por error, y nunca en un baseline generado—:

```neon
    # Nivel 7 desde <fecha>, con tres reglas diferidas. El 7 comprueba los tipos
    # "parcialmente equivocados", y de sus 1.276 errores 1.163 son las 990
    # consultas crudas devolviendo stdClass — la misma razón por la que
    # checkModelProperties está en false. Se difieren por identificador, con su
    # número aquí para que se note si crece:
    #
    #   property.notFound  1.002   ·  method.notFound  161  ·  argument.type  89
    #
    # Lo que queda encendido es la familia que encontró la §13.1 y la §9:
    # method.nonObject, property.nonObject, offsetAccess.nonOffsetAccessible,
    # foreach.nonIterable, assign.propertyType y return.type.
    #
    # El nivel 6 NO se sube: sus 1.940 errores son anotación pura, ninguno
    # señala código que pueda fallar y el 68% cae en los 129 controladores.
    # Ver docs/migracion/12-larastan-nivel-7.md.
    level: 7
    ignoreErrors:
        - identifier: property.notFound
        - identifier: method.notFound
        - identifier: argument.type
        - identifier: missingType.return
        - identifier: missingType.parameter
        - identifier: missingType.property
        - identifier: missingType.iterableValue
        - identifier: missingType.generics
        - identifier: argument.templateType
```

Antes de ponerlo hay que cerrar los 18 de la §5, o darles su entrada con nombre,
motivo y `count` como las demás.

**Y lo que hay que quedarse de todo esto, más que los arreglos**: la escalera de
phpstan se había estado subiendo por inercia, un peldaño detrás de otro, y
funcionó cinco veces seguidas. La sexta no, y **medir antes de subir costó veinte
minutos**. Las herramientas de `tools/` existen todas por esta misma razón; esta
vez la pregunta era sobre una herramienta y no sobre el código.
