# Código que ya no funciona y nadie ha notado

Hallazgos de la auditoría de rutas (18 ago 2026). Ninguno lo provocó esta
migración: son cosas que **se rompieron solas** al subir de versión de PHP o de
Laravel, sin que nadie tocara el fichero, y que no se notaron porque nadie mira
lo que no falla en pantalla.

Están aquí porque el salto de la Fase 4 (Laravel 8 → 13, PHP 8.0 → 8.4) va a
producir más de lo mismo, y porque son el argumento más concreto a favor de
terminar los tests de contrato antes de darlo.

---

## 1. `Input::` — eliminada en Laravel 5.2, todavía en 7 controladores

La clase `Input` desapareció de Laravel en la 5.2. No hay alias en
`config/app.php` y ningún controlador la importa, así que cada `Input::` que se
ejecute lanza **"Class App\Http\Controllers\Input not found"** y responde 500.

Comprobado: `class_exists('Input')` es `false`.

| Dónde | Estado |
|---|---|
| `RemindersController` (4 métodos) | ~~Vivo y roto~~ · **sus 4 rutas borradas** el 18 ago 2026; el controlador sigue |
| `EstadosCivilesController::store` y `::update` | ~~Enrutados y rotos~~ · **controlador borrado** el 18 ago 2026 |
| `UsersController::store` y `::update` | Rotos, pero **sin ruta**: nadie los alcanza |
| `RolesController`, `CertificadosEstudioController` | Comentados, inertes |
| `LoginController::postIndex`, `LoginAppController::postIndex` | **Inalcanzable** — ver abajo |

### Por qué el de `postIndex` no llega a ejecutarse

Está dentro de un `catch` que nunca casa:

```php
catch(Tymon\JWTAuth\Exceptions\TokenExpiredException $e)   // sin barra inicial
```

El fichero está en `namespace App\Http\Controllers`, así que eso resuelve a
`App\Http\Controllers\Tymon\JWTAuth\Exceptions\TokenExpiredException`, una clase
que no existe. Un `catch` con un tipo inexistente no lanza error: simplemente
no captura nunca.

Y aunque casara, tampoco llegaría: `User::fromToken()` atrapa la excepción por
dentro y aborta con 401 antes de devolver el control.

**Verificado en vivo**, no deducido: `POST /api/login` con un token caducado de
verdad devuelve `401 Token ha expirado.`, que es lo correcto.

O sea que un fallo (la barra que falta) tapa a otro (`Input`). Al arreglar
cualquiera de los dos por separado, aparece el otro.

---

## 2. `RemindersController` — scaffolding de Laravel 4, sin cliente

> **Rutas borradas el 18 ago 2026.** El controlador sigue en el repo; quitarlo es
> limpieza aparte. Lo de abajo es cómo estaba.

Los 4 endpoints de `password/*` respondían **500**:

```
GET  api/password/remind        500
POST api/password/remind        500
GET  api/password/reset/{token} 500
POST api/password/reset         500
```

Usa `Input`, `Password::remind()` (que ya no existe; hoy es `sendResetLink`) y
`View::make('password.remind')` sobre vistas que **no están en el repo**.

**No es una segunda vía de reseteo de contraseñas.** No puede cambiar nada: falla
antes de tocar la base. La recuperación real es
`login/recuperar-clave` → `login/reset-password`.

Confirmado por la sesión de `myvc_front` y verificado leyendo los dos repos: ni
el front web ni la app Flutter llaman a ninguna de las cuatro.

---

## 3. `estados_civiles` — recurso completo sin cliente, y roto a medias

> **Borrado entero el 18 ago 2026**, rutas y controlador. Lo de abajo es por qué.

El front **no llama a este recurso de ninguna forma**: la lista está escrita a
mano en `ProfesoresNewCtrl:14` y `ProfesoresEditCtrl:16` (`Soltero`, `Casado`,
`Divorciado`, `Viudo`).

Además `store` y `update` usan `Input::`, así que **responderían 500** si alguien
las llamara. Solo `index` y `destroy` funcionarían.

Tenía 8 rutas: 3 de scaffolding vacío y 5 con código. Ninguna tenía cliente.

---

## 4. Ya arreglados en el PR #7

- **`login/logout` devolvía 500 siempre**, desde el import de 2021.
  `DB::update()` devuelve un entero y el código le aplicaba `[0]`. Hasta PHP 7.3
  indexar un entero devolvía `null` en silencio; desde 7.4 es un warning y
  Laravel lo convierte en excepción. Se rompió solo al subir de versión.
  Nadie lo notó porque el front lanza la llamada sin esperar la respuesta.

- **3 rutas de `tiposdocumento`** (`create`, `show`, `edit`) apuntaban a métodos
  que no existen: 500. Borradas.

- **10 rutas con el método vacío**: devolvían 200 y nada. Borradas.

---

## 5. Encontrado al sacar la autenticación de los constructores (18 ago 2026)

### `fromToken()` se llamaba a sí misma y tiraba el resultado

Cuando el `periodo_id` del usuario es de otro año, `User::fromToken()` lo corrige
en la base y vuelve a resolver. Pero hacía `USER::fromToken($already_parsed);` y
después `return;` a secas, así que **devolvía null**.

El síntoma: el usuario entra y recibe **200 con el cuerpo vacío**. Al segundo
intento funciona, porque el `UPDATE` de la primera vez ya dejó el periodo
arreglado. O sea que parece cosa de una vez y nadie lo reporta.

Se llega ahí de forma natural al pasar de año, que es justo cuando más gente
entra a la vez. Arreglado, y con test:
`ContextoDeUsuarioTest::test_un_periodo_de_otro_anio_se_corrige_y_devuelve_el_contexto`.

### "Ver mi boletín" llevaba cinco años respondiendo 500

Es la pantalla por la que un alumno ve su boletín y un acudiente el de sus
acudidos: `panel.boletin_acudiente` en el front, `PUT boletines/detailed-notas`
en el back.

El front manda `requested_alumnos: [{alumno_id, grupo_id}]` —así desde 2018,
`NotasAlumnoCtrl`—. Y `Grupo::alumnos()` daba por hecho que cada elemento trae
además `matricula_id`, desde `6bc08ac` (**31 ago 2021**, "Evitar que cuente los
retirados en bol requested"):

```php
$sql_condicion .= ' or m.id="'.$con_retirados[$i]['matricula_id'].'"';
```

Sin esa clave, PHP avisa "Undefined array key" y Laravel lo convierte en
excepción: **500**. La pantalla del personal sí funcionaba, porque selecciona los
alumnos de una lista que viene del backend y esa sí lleva `matricula_id`.

Nadie lo notó porque el error sale en la consola del navegador y en el log del
servidor, no en la pantalla; y porque quien lo sufre —una familia— no tiene a
quién reportarlo más que al colegio.

**Arreglado**, y con test que usa el payload exacto del front:
`AutorizacionTest::test_el_alumno_recibe_su_boletin_y_solo_el_suyo`. De paso se
quitó la concatenación: ese `matricula_id` entraba en el SQL sin parametrizar, o
sea **inyección SQL** al alcance de cualquiera con token, y `Grupo::alumnos()` la
llaman casi todos los informes.

### `boletines3` divide por cero cuando un área no tiene asignaturas

`Area::agrupar_asignaturas_periodos()` hace `round($sumatoria / $found)` donde
`$found` es el número de asignaturas del área en ese grupo. Si un área existe en
el año pero no tiene asignaturas en el grupo, `$found` es 0 y el informe responde
**500 "Division by zero"** (`app/Models/Area.php:154`).

Pasa con los datos del seed de tests, así que puede pasar en un colegio.
**No se ha arreglado**: decidir qué debe mostrar un área sin asignaturas —cero,
en blanco, o no salir— es una decisión del colegio, no mecánica.

### Un `BolfinalesController` duplicado, sin rutas

Hay dos: `app/Http/Controllers/BolfinalesController.php` y
`app/Http/Controllers/Informes/BolfinalesController.php`. **El de la raíz no está
enrutado**; el que atiende peticiones es el de `Informes/`.

No es urgente —código muerto no hace daño— pero sí es una trampa: quien arregle
un boletín final en el archivo equivocado no verá ningún efecto, y no tendrá
forma de saber por qué. Igual que pasó con `getUltimas`/`putUltimas`.

> **Corregido el 19 ago 2026, en la Fase 6:** «nadie lo instancia» era falso.
> `CertificadosEstudioController` hace `new BolfinalesController` dos veces, y
> como está en `namespace App\Http\Controllers` sin ningún `use`, eso resuelve
> al **de la raíz**. O sea que el duplicado no está muerto: es el que usan los
> certificados de estudio. Lo que sí está muerto es el camino entero — ver la
> sección 6.

### Cuatro guards de autorización que no se ejecutaban

Están en [06-autorizacion.md](06-autorizacion.md), porque son un agujero de
seguridad y no solo código muerto. Comparten la forma de esta lista: llevaban
años a la vista, el sistema respondía 200 y nadie miró.

---

## 6. Lo que encontró Larastan en nivel 0 (19 ago 2026, Fase 6)

Las cinco secciones de arriba salieron de leer código y de golpear endpoints a
mano. Esta salió de una herramienta, en su nivel más bajo —el que solo se queja
de lo que **no puede funcionar**: clases que no existen, variables sin definir,
propiedades sin declarar—. Encontró **207**.

Que un nivel 0 dé 207 en 32.000 líneas es el dato. No son opiniones de estilo:
cada uno es una línea que, al ejecutarse, lanza.

### 6.1 La misma forma de siempre: el nombre sin barra

Cuarenta y dos `catch (Exception $e)` y diecinueve `App::abort(400, ...)`,
todos dentro de un `namespace`. Sin barra inicial, `Exception` resuelve a
`App\Http\Controllers\Exception` y `App` a `App\Http\Controllers\App`.
Ninguna de las dos existe.

- **El catch nunca captura.** PHP no protesta por un `catch` con un tipo
  inexistente: simplemente no casa. Es exactamente el
  `catch (Tymon\JWTAuth\...)` de la sección 1, sesenta veces más.
- **El abort no aborta.** Lanza «Class not found», así que donde el autor
  escribió «no tienes permiso» el usuario recibe un **500**.

Comprobado con la aplicación arrancada, no deducido: `class_exists()` devuelve
`false` para las dos. El alias global `App` de `config/app.php` no ayuda —el
autoloader de alias recibe el nombre con el namespace delante y no casa.

**Arreglados los 61**, y no solo poniéndoles la barra: la mitad de los cuerpos
estaba mal. Ocho hacían `return $e;`, que al empezar a capturar habría devuelto
**200 con la traza serializada dentro**; cinco pasaban la excepción como
*mensaje* de `abort()`, y `Exception` tiene `__toString()`, así que el cliente
habría recibido la traza entera como texto del error. Los `abort` pasan a
403/404/422 según el caso.

### 6.2 Seis reservas contra la división por cero que no reservaban nada

Seis catch envolvían un `$suma / count($algo)` y ponían 0 al fallar. En PHP 5 y
7 dividir entre cero era un aviso que devolvía `false`, así que el catch
sobraba. **Desde PHP 8 lanza `DivisionByZeroError`, que es un `Error` y no un
`Exception`**: ponerle la barra al `catch (Exception)` lo habría dejado igual
de roto. Necesitan `\Throwable`.

Es la misma familia que el «Division by zero» de `boletines3` de la sección 5,
solo que aquí el autor sí había escrito qué hacer.

### 6.3 Un paquete que no está desde antes de esta migración

`App\Models\Permission` extendía `Zizaco\Entrust\EntrustPermission`. **Entrust
no aparece en `composer.json` ni en `composer.lock`** — cero coincidencias—, así
que no lo quitó el salto de framework. Cargar la clase era un fatal, y con ella
caía la pantalla de administración de roles entera:

| Endpoint | Por qué fallaba |
|---|---|
| `GET api/permissions` | `Permission::all()`, clase padre inexistente |
| `PUT api/roles/addpermission/{id}` | además `attachPermission()`, que era de Entrust |
| `PUT api/roles/removepermission/{id}` | `Input::get()`, que no existe desde Laravel 6 |

El tercero llevaba **anotado en el propio fichero** desde la auditoría de
autenticación. Arreglados los tres, con test.

### 6.4 Un nombre de clase declarado en dos ficheros

`app/Http/Controllers/Alumnos/ImportarAnteriorController.php` declaraba dentro
`class ImportarController` — el mismo nombre completo que
`ImportarController.php`. PSR-4 resuelve ese nombre al fichero que se llama
igual, así que el otro **no se carga nunca**: 408 líneas inalcanzables.

Peor que muerto: el classmap de `composer dump-autoload -o` elige uno de los dos
por orden de escaneo. Hoy elige el bueno. Borrado.

### 6.5 Endpoints que responden 500 desde que se escribieron

No los rompió nada: nacieron con variables que no se definen en ninguna parte.
Se dejan **como están, y anotados en `phpstan.neon` con su motivo**, porque
arreglarlos no es limpieza sino decidir de dónde sale cada valor —y en dos de
ellos, si la operación debe existir—. Esa decisión es del colegio.

| Endpoint | Qué le falta |
|---|---|
| `PUT api/definitivas_periodos/calcular-notas-finales-asignatura` | `$asignaturas`. Además lee `Request::input('profesor_id')` en una variable llamada `$asignatura_id`, con un `// Aquí un error por arreglar` al lado |
| `GET api/editnota/detailed-notas-year` | `$grupo_id` y `$periodos_a_calcular`; el método no recibe parámetros y la ruta tampoco los lleva |
| `PUT api/piars-config/config` | `$field`, `$alumno_id`, `$arr`, `$fullPath`. El `if` de superusuario está además invertido y sin `return`, así que no comprueba nada |
| `PUT api/uniformes/guardar-cambios` | `$propiedad`, `$valor`, `$user`, `$user_id`. Su propio autor lo anotó: «No la estoy usando actualmente» |
| `GET api/certificados-estudio/certificado-alumno/{grupo_id}` | la vista `certificados.estudio` no está en el repo |
| `GET api/certificados-estudio/certificado-grupo/{grupo_id}` | la misma vista |

Los dos últimos son el único consumidor del `BolfinalesController` de la raíz,
el duplicado de la sección 5. O sea que el duplicado no está muerto — está vivo
dentro de un camino que no llega.

### 6.6 Los que sí se arreglaron, uno por uno

Todos eran 500 seguros al tocar la línea:

- `GET api/alumnos/trashed` — la papelera de alumnos metía `$user` en la consulta sin resolverlo.
- `GET api/vt_votaciones/actual-in-action` — `VtVotacion::actualInAction()` estaba declarada como método de instancia y se llama estáticamente.
- `PUT api/subunidades/update-orden-varias` — `User::pueden_editar_notas()` sin argumento: `ArgumentCountError`. De paso, el guard no se comprobaba.
- `DELETE api/subunidades/forcedelete/{id}` — preguntaba `if ($unidad)` donde la variable se llama `$subunidad`. Copia de `UnidadesController` sin renombrar una palabra: **nunca borró nada**. Con test, por destructivo.
- `GET api/observador/vertical-todos` — usa `$tamanio` y ni lo recibe ni lo lleva su ruta.
- `EditnotaController`, `NotasPerdidasController`, `PlanillasAusenciasController`, `ChangeAskedAssignmentController`, `Asignatura` — nueve modelos usados sin importar, cada uno un fatal en su línea.

### 6.7 Y 1.500 líneas que no ejecuta nadie

Quince ficheros y catorce métodos sin ruta y sin referencia: los seis
controladores de `Auth/` de `laravel new` (que además usan traits que Laravel 13
ya no trae), `HomeController`, `WelcomeController`, `RemindersController`,
`ParentescosController`, el `ComportamientoController` de la raíz,
`CalcularDefinitivasAlumno`, `PuestosAnualesController`, `EstadoCivil`,
`IndicadoresPerdidos`, cinco métodos de `vt_aspiraciones` colados en
`UsersController` y cinco métodos vacíos de `EventosController`.

---

## 7. Lo que encontró el nivel 1 (19 ago 2026)

El nivel 0 se queja de lo que no puede existir. El **nivel 1** añade una
pregunta más: métodos y propiedades que no existen **en una clase que sí
existe**. Es justo el punto ciego que dejó la Fase 6 al descubierto con los
`$this->user()` — `Illuminate\Routing\Controller` tiene `__call()`, y para el
análisis la clase «podría» responder. `Model` y las facades tienen lo mismo.

Salieron **341 errores. 320 eran uno solo**: `$this->user` en 129 controladores.
La propiedad la sirve un `__get` en el trait `ResuelveElUsuario`, y un `__get`
no le dice a phpstan qué propiedades existen. Una anotación `@property` en el
trait, y los 320 se van sin tocar una línea de código. Lo que importa es que
tapaban a los otros 21.

### 7.1 El que se arregló

**`GET api/perfiles/comprobarusername/{username}`** llamaba a
`User::withTrashed()`, y `App\User` no usa SoftDeletes: `BadMethodCallException`
desde que se escribió. Lo usa la pantalla de crear usuario para avisar antes de
guardar; el error salía en la consola del navegador, no en la pantalla.

El arreglo no tiene decisión detrás: sin SoftDeletes tampoco hay scope global
que filtre, así que un `where` a secas ya devuelve los borrados, que es
exactamente lo que `withTrashed` pretendía. Y hace falta que los devuelva: el
username de alguien borrado sigue ocupado, porque la fila sigue en la tabla.
Con test de contrato, incluido el caso del usuario borrado.

### 7.2 Los que se documentan, y qué falta decidir en cada uno

Misma regla que en la 6.5: enrutados y rotos, así que se anotan en
`phpstan.neon` con nombre, motivo y `count` — no en un baseline generado, que
los escondería. Uno nuevo en cualquiera de estos ficheros vuelve a romper el
análisis; está comprobado insertando una variable inexistente.

| Endpoint | Qué falta decidir |
|---|---|
| `GET api/excel-docentes/docentes/{year}/{year_id}` · `GET api/simat/alumnos` | `Excel::create()` es la API de maatwebsite/excel **2.x**. La 3.x la quitó, y el proyecto lleva en la 3.x desde antes de esta migración. Reescribirlos a la API nueva es rehacer el informe, y los exports están fuera de alcance por el §5 del plan. **Las dos rutas estaban mal escritas aquí hasta el 19 ago 2026**: la primera no existe, y la segunda decía `api/simat/alumnos-exportar`, que sí existe y funciona —su `Excel::create()` queda detrás de un `return`—. Lo fija ahora `ExcelTest`, golpeando las tres |
| `GET api/candidatos/conaspiraciones` | `VtVotacion::actualInscrito()` no existe; lo que hay es `actualesInscrito()`, que devuelve un **array**, y aquí se usa como una sola votación. No es un renombre: hay que decidir cuál es «la suya». Solo revienta para Alumno y Acudiente |
| `PUT api/publicaciones/borrar-comentario` | `$user.persona_id==comentario.persona_id` — notación de punto de otro lenguaje. En PHP el punto concatena y las dos son constantes que no existen: fatal. Solo lo esquiva un superusuario, porque el `\|\|` corta antes. Falta la consulta que diga de quién es el comentario |
| `PUT api/publicaciones/guardar` | `$para_todos` se define en las dos ramas del `if` y en ninguna otra; sus cuatro hermanas sí se inicializan a 0 justo encima. Inicializarla cambiaría un 500 por una publicación que no ve nadie, que es peor |
| ~~`POST api/piars-alumnos/document` · `DELETE api/piars-alumnos/document/{alumno_id}` · `DELETE api/piars-actas-acuerdo/document/{alumno_id}`~~ | **Decidido el 20 ago 2026, ver §7.3.** La fila decía `PUT api/piars-alumnos/field`, y `putField` no usa `$document` en ninguna parte: eran los tres endpoints de documento |
| `GET api/piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}` | `$asignaturas` solo se define para Profesor y para Usuario. Qué ve un Alumno de su propio PIAR no es una decisión de programación |
| `POST api/importar/cartera` | `return (array)$rr;` y `$rr` no se define en ninguna rama |
| `GET api/definitivas_periodos` | `$profe_id` se define para Profesor y para superusuario —con la condición escrita dos veces, `$user->is_superuser && $user->is_superuser`— y para nadie más |
| `PUT api/uniformes/guardar-cambios` | El mismo de la 6.5, que su autor anotó con «No la estoy usando actualmente». El nivel 1 ve dos de sus variables otra vez, donde se leen antes de existir |

**El patrón, otra vez:** ninguno de estos diez salió de leer el código con una
lista delante. Salieron de subirle una pregunta a la herramienta.

### 7.3 El `$document` de los documentos del PIAR: decidido (20 ago 2026)

La decisión que faltaba era **qué devolver cuando el `if` no entra**, y resultó
que no hay nada que devolver: el `if` preguntaba si existe la fila, y no existir
no es un caso raro del que haya que salir con algo — es que no hay PIAR.

- Sin fila en `piars_alumnos`, ese alumno no tiene PIAR. La crea
  `PiarsAlumnoUtils::getAlumnosPiar` al pedir el grupo, y solo para los que
  tienen `nee=1`. Los tres endpoints contestan ahora **404**.
- Sin fila en `piars_actas_acuerdo`, no hay acta que borrar. **404** también.

Con eso las variables ya no pueden quedar sin definir y **las dos entradas de
`phpstan.neon` desaparecen**, comentario incluido.

De paso, en `POST piars-alumnos/document` el archivo se guardaba en disco
**antes** de comprobar la fila. O sea que el caso roto no solo devolvía un 500:
dejaba el archivo escrito bajo `uploads/` sin nada que lo apuntara. Ahora se
comprueba primero y se guarda después.

**Lo que salió de paso y no era esto.** Los tres `postDocument` devolvían
`['document' => <nº de filas afectadas>]`, que no le sirve de nada a quien acaba
de subir un archivo: el nombre final lo decide el servidor —carpeta
`user_<user_id>/` y sufijo `(1)`, `(2)`… al chocar, ver `SafeUpload`—, así que
el cliente no podía saber a qué enlazar y pintaba un enlace roto hasta que se
recargaba la página. Y la carpeta es **por usuario, no por alumno**: un titular
que suba `acta.pdf` para dos estudiantes provoca la colisión de verdad. Se añade
`documento` a la respuesta con la ruta real; `document` se mantiene por si algo
lo lee.

## 8. Lo que encontró golpear las rutas (20 ago 2026, P2 de tests)

El muestreo de la P2 pidió una lectura por controlador con un token de verdad.
De 66 lecturas sin parámetro, **cuatro fallaban siempre**, y ninguna de las
cuatro estaba en las listas de arriba.

Lo que las une importa más que las cuatro: **tres son SQL contra columnas que no
existen, y larastan pasó por esos tres ficheros en la Fase 6 sin ver ninguna.**
El análisis estático lee PHP, y estos errores viven dentro de una cadena de
texto. Solo aparecen golpeando.

| Endpoint | Qué le pasa |
|---|---|
| `GET api/profesores/trashed` | `order by p.nombres` y en el `FROM` no hay ninguna `p`. Y aunque no fallara devolvería lo que no debe: la consulta es la papelera de ALUMNOS copiada entera —`FROM alumnos a … where a.deleted_at is not null`—, con dos `year_id` escritos a mano. La papelera de profesores nunca ha devuelto un profesor |
| `PUT api/preguntas/edicion` | `ORDER BY p.order` cuando la columna se llama `orden`; la misma consulta la selecciona bien tres líneas más arriba. `order` es además palabra reservada |
| `GET api/votaciones/unsignedsusers` | `select p.user_id from vt_participantes p`, y `vt_participantes` no tiene `user_id`: tiene `grupo_profes_acudientes`. La tabla cambió de forma y la consulta se quedó |
| `GET api/importar` | `Excel::import('…/alumnos.xls', function($reader){…})` es la firma de maatwebsite/excel **2.x**. En la 3.x el primer argumento es el objeto de importación, así que el closure llega donde se espera una ruta y `pathinfo()` revienta. Misma familia que los `Excel::create()` de la 7.2, y mismo motivo para no tocarlo: reescribirlo es rehacer el importador |

**Se dejan como están** —los cuatro de la tabla y el de la 8.4—, con la misma
regla de la 6.5 y la 7.2: arreglarlos no es limpieza. Qué debe devolver la
papelera de profesores, o de dónde sale el `user_id` de un participante ahora
que la columna no está, son decisiones del colegio. Lo que sí queda es el test que fija el error exacto, para que un cambio
de la migración no los mueva sin que nadie se entere.

### 8.1 Tres pantallas que devuelven la cadena `'Holaa'`

`GET api/observador`, `GET api/simat` y `GET api/excel-docentes` responden 200
con la palabra `Holaa`. Es el `getIndex` de andamio que quedó cuando cada
controlador pasó a servir solo sus métodos con parámetros. No molesta a nadie
—`myvc_front` no las llama— pero son rutas publicadas de la API, y quien las
encuentre en el inventario merece saber que no son un error de despliegue.

### 8.2 Pedir algo que no existe da 500, no 404

`PUT api/respuestas/actividad` y `PUT api/ChangesAskedAssignment/ver-detalles`
hacen `DB::select(…)[0]` sin mirar si vino algo: con un id que no está, el error
es `Undefined array key 0`. No es un fallo del seed —con la tabla llena pasa
igual con cualquier id ajeno—, y se ve desde fuera: un 500 en el log del colegio
que en realidad era «eso no está».

Queda fijado en `MuestreoDeLecturasConContextoTest` y sin tocar. Cambiarlo a 404
es tocar el contrato de dos pantallas sin saber qué hace `myvc_front` con cada
código, y eso es otro trabajo.

### 8.3 Y una tabla que no existe, otra vez

`ws_preguntas`, `ws_actividades`, `ws_opciones` y `ws_respuestas` sí existen —el
módulo de actividades está vivo—, pero el generador de seed no copia ninguna. No
es lo mismo que `llevo_formulario` del P1, que no existía en ninguna base. Se
anota aquí porque la primera lectura del inventario fue esa, y costó una hora:
las tablas del módulo llevan prefijo `ws_`, y las de cambios de asignatura están
en singular (`change_asked_assignment`).

### 8.4 Y el importador de cartera, que es el quinto (20 ago 2026)

`POST api/importar/cartera` hace `Excel::import($ruta, function($reader){…})`:
**la misma firma de la 2.x** que rompe `GET api/importar`, y el mismo error
exacto —`pathinfo(): Argument #1 ($path) must be of type string, Closure given`—.

Lo interesante no es el endpoint, es **por qué tardó un día más en aparecer**. El
muestreo de la P2 golpeó 66 **lecturas sin parámetro**, y esta es un POST con un
archivo dentro: no había forma de que saliera ahí. Es la lección de esta sección
aplicada a sí misma —lo que no se golpea no se sabe si funciona— en el único
rincón donde golpear cuesta trabajo, porque hay que fabricar el archivo. El que
lo destapó fue el trabajo de la importación reanudable, que fue a mirar los dos
importadores que [09 §1](09-pendientes.md) daba por vivos.

Queda fijado en `tests/Contrato/ExcelTest.php`, con la hoja que produce el propio
export de deudores. **Se deja roto** por lo mismo que los otros cuatro: qué debe
hacer la importación de cartera —y si la operación debe existir, ahora que se
sabe que lleva años sin funcionar— es una decisión del colegio.

---

## 9. Lo que encontró subir larastan al nivel 2 (20 ago 2026)

El nivel 1 miraba métodos y propiedades que no existen en una clase que sí. El
2 añade las propiedades de los objetos con tipo conocido, y con eso llega a lo
que ninguna de las dos tandas anteriores alcanzaba: **cuatro endpoints
enrutados que revientan siempre**, dos de ellos con el fallo escondido detrás de
otro.

Empezó en 465 errores, y **438 no eran del código sino de una anotación
falsa**. El trait `ResuelveElUsuario` declaraba `@property User $user`, y lo que
devuelve `User::fromToken()` no es una fila de `users`: es el contexto aplanado
—`year_id`, `persona_id`, `nombre_grupo`, `perms`— que sale de un `(object)
$array`. La anotación se sostuvo mientras el análisis no miró dentro. Puesto el
tipo honesto, `stdClass`, se fueron 381 de una vez.

Los 144 siguientes eran columnas de verdad, y **el motivo de que no se
supieran es la Fase 5**: el esquema no está en migraciones sino congelado en un
volcado SQL, y larastan de ahí no lee. Se resolvió generando las columnas dentro
de cada modelo **desde el esquema real** —`tools/columnas-en-los-modelos.php`—,
no a mano, por la misma razón por la que `ColumnaSegura` le pregunta al esquema:
una lista escrita se queda corta el día que alguien añada un campo. De paso salió
que **`NotaFinal` no declaraba su tabla**: Eloquent deducía `nota_finals`, que no
existe. Hoy no se nota porque todo lo que hay en esa clase es SQL a mano, pero el
primer `NotaFinal::where(...)` se habría llevado un «Table doesn't exist».

### 9.1 Los dos que se arreglaron

| Endpoint | Qué le pasaba |
|---|---|
| `PUT api/perfiles/creartodoslosusuarios` | Llamaba a `attachRole()`, que es de **Entrust** —el paquete que ya salió en la §6.3, y que no está instalado ni aparece en el `composer.lock`—. Moría en la primera persona de la lista, y moría **entre** guardar el usuario y engancharlo a la persona: cada intento dejaba un usuario huérfano y devolvía 500 |
| `PUT api/ChangesAsked/…` (cambio de fecha de nacimiento) | `$alumno->fecha_nac->format('Y-m-d')` sobre una columna que no está en `$casts`: «Call to a member function format() on string», cada vez que alguien pedía cambiar la fecha de nacimiento de un alumno que ya tenía una |

**Ninguno de los dos tenía decisión detrás, y por eso se arreglaron.** El de
Entrust porque `AlumnosController` ya tenía hecha esa misma migración
—`$usuario->roles()->attach(...)`, con la línea vieja comentada al lado— y en
estos tres sitios quedó sin hacer; los ids 2, 3 y 4 son Profesor, Alumno y
Acudiente en la tabla `roles`. El de la fecha porque `Carbon::parse()` es
exactamente lo que la línea de al lado ya hacía con el valor nuevo.

### 9.2 Los dos que se quedan, y qué falta decidir

| Endpoint | Qué falta decidir |
|---|---|
| `PUT api/periodos/update/{id}` | Escribe `$periodo->year` y guarda, pero `periodos` no tiene esa columna: tiene `year_id`. MySQL responde «Unknown column 'year' in 'field list'» —comprobado contra la base—. Falta saber **qué manda el cliente en `year`**, el número del año o el id, y no hay cliente al que preguntarle: `myvc_front` no llama a esta ruta. Escribir un número de año en `year_id` sería peor que el 500 |
| `POST api/asistencias` · `POST api/appmobile/asistencias` | **Dos fallos, uno tapando al otro.** El INSERT declara `:asignatura_id` y el array de valores no lo trae, así que la consulta ni se ejecuta; detrás espera `$datos->id = $id` sobre un array, que **en PHP 8 es un Error y no un aviso** —de los que cambiaron de gravedad con el salto de versión—. Falta decidir qué es `asignatura_id` en una asistencia y qué debe devolver el endpoint |

Los dos quedan fijados por `tests/Contrato/EntrustYPropiedadesTest.php`, que
comprueba el 500 exacto, y anotados en `phpstan.neon` con su `count` — no en un
baseline generado, que los escondería.

### 9.3 Y una propiedad dinámica que no hacía nada

`PiarsGruposController` tenía un `else` que escribía
`$piarsAlumnosUtils->acudientes = []`. No era lo que parecía: `getAcudientes()`
cuelga `acudientes` de **cada alumno**, no del objeto de utilidades, y nadie leía
esa propiedad. La rama no hacía nada — salvo crear una propiedad dinámica sobre
una clase normal, que en PHP 8.2 es una deprecación y en PHP 9 será un error. Se
borró sin cambiar comportamiento. Lo que queda por decidir es otra cosa: si un
profesor que no es superusuario debería ver `acudientes: []` en cada alumno en
vez de que la clave no aparezca.

### 9.4 Lo demás eran atributos de respuesta, y se anotaron

Los 45 restantes eran el mismo patrón repetido por todo el proyecto: el código le
cuelga al modelo un atributo que no es columna —`$grupo->titular`,
`$aspiracion->candidatos`, `$periodo->sumatoria`— para armar la respuesta.
Eloquent los guarda entre los atributos y **salen en el JSON**, así que son parte
del contrato con el frontend igual que las columnas. Se anotaron en cada modelo,
que es lo que permite que el análisis siga avisando de un nombre mal escrito en
vez de callarse con todos.

Uno NO se anotó a propósito: el `$periodo->year` de la 9.2. Anotarlo habría
escondido el fallo.

---
---
## La lección para la Fase 4

Todos los casos de esta lista comparten forma: **algo dejó de funcionar, o nunca
funcionó, y el sistema siguió respondiendo lo bastante bien como para que nadie
mirara.** Unos se rompieron al cambiar de versión de PHP; otros —los guards de
la sección 5— nacieron rotos y devolvían 200 con datos, que es la forma más
difícil de notar.

El salto de PHP 8.0 a 8.4 y de Laravel 8 a 13 va a producir más. Lo que los
detecta no es leer el código —estos llevaban años a la vista— sino golpear los
endpoints y comparar la respuesta. Es exactamente lo que hacen los tests de
contrato de la Fase 0, y por eso conviene terminarlos antes.

> **Media lección, visto desde la Fase 6.** Los tests de contrato encuentran lo
> que se rompió; no encuentran lo que **nunca funcionó**, porque para escribir
> el test hay que sospechar del endpoint, y nadie sospecha de 539. Los 207 de la
> sección 6 los encontró una herramienta que lee todas las líneas sin
> preguntarle a nadie cuáles importan. Las dos cosas hacen falta, y ninguna
> sustituye a la otra: por eso `larastan` y `pint` corren ahora en el CI junto a
> la suite.

---

## 10. La hora, mirada a fondo el 20 ago 2026 — y está bien

Salió al ver `date.timezone = UTC` en el panel de PHP mientras se comprobaba
otra cosa. En este proyecto conviven **dos zonas horarias**, y Colombia está a
UTC−5, así que la sospecha era razonable:

| Dónde | Qué usa | Cuántas veces |
|---|---|---|
| `config/app.php` | `'timezone' => 'UTC'` | — |
| El código de siempre | `Carbon::now('America/Bogota')` | 114 |
| La sesión de la Fase 3 | `Carbon::now()`, o sea UTC | 8 |

**Se revisaron las ocho, y no hay fallo.** Las ocho son de
`Services\Sesion`, `TokenDeSesion` y `LimpiarSesiones`, y todas comparan contra
valores que ellas mismas escribieron en UTC: `expires_at`, `last_used_at`, el
corte de la limpieza. Una duración calculada entera en UTC da lo mismo que
calculada entera en Bogotá.

Lo mismo por el otro lado: los tokens de reseteo de contraseña se escriben con
`Carbon::now('America/Bogota')` y se comprueban con
`Carbon::now('America/Bogota')->subHour()`, así que la ventana de una hora dura
una hora.

**Lo que sí queda es el aviso**, porque el día que alguien mezcle las dos el
error no se ve: son cinco horas, no un fallo. La regla es la que ya siguen los
dos grupos sin habérselo dicho — **cada fecha se escribe y se compara en la
misma zona**—, y lo que decide cuál es de dónde sale el dato: si es un dato del
colegio (una matrícula, una ausencia, una nota), va en `America/Bogota` como
todo lo demás de su tabla; si es un plazo interno del sistema, en UTC.

---

## 11. Lo que encontró subir larastan al nivel 3 y mirar el 4 (20 ago 2026)

El nivel 3 comprueba tipos y el 4 comprueba si una condición decide algo. El
3 está puesto y cerrado (§ [09-pendientes](09-pendientes.md) §6); del 4 se
arregló lo que tenía arreglo claro y aquí queda **lo que no lo tiene**, que es
lo de siempre: enrutado, roto, y esperando que alguien decida qué debería hacer.

### 11.1 `case 'Profesor' or 'Usuario':` — el error de escritura que tapaba una fuga

**Decidido y arreglado el 20 ago 2026.** Se deja el análisis entero porque el
porqué de haber esperado a la decisión es lo que no se reconstruye después.

`AsignaturasController::getListasignaturas`, la ruta
`GET api/asignaturas/listasignaturas/{persona_id?}` con guard `persona.propia`:

```php
switch ($user->tipo) {
    case 'Profesor' or 'Usuario':
```

En PHP eso no es «Profesor o Usuario». Es `case ('Profesor' or 'Usuario')`, o
sea **`case true`**, y como `switch` compara con `==`, cualquier `tipo` que no
sea cadena vacía entra por ahí. **El `case 'Alumno'` de más abajo no se ejecutaba
nunca.**

Lo que importa es hacia dónde fallaba, y era al revés de lo que parece. La rama
muerta filtraba por `a.profesor_id = :profesor_id` **pasándole el `persona_id`
del alumno**, y los ids de `alumnos` y de `profesores` son dos numeraciones
distintas que se solapan. Medido contra la base de desarrollo `simonbolivar`,
que es copia de un colegio real:

| Pregunta | Respuesta |
|---|---|
| Alumnos cuyo id coincide con el de un profesor con asignaturas | **34** de 1.245 |
| El más expuesto vería | **92 asignaturas ajenas** |
| De los que además pueden iniciar sesión, el primero vería | 13 |
| Lo que devolvía la rama que sí se ejecutaba | 0 filas |

O sea que **el `or` mal escrito era lo único que impedía que un alumno viera el
horario de un profesor**. Escribirlo como se pretendía abría esa fuga en el
mismo commit que «arreglaba» el aviso del analizador. Es el mismo patrón que el
`putCambiarpassword` de perfiles: un `if` de adorno que resultó ser la cerradura.

**Lo que decidió Joseth**, y que es lo que permitió tocarlo: un alumno o
acudiente solo puede **alcanzar** asignaturas de su grupo, o de todos sus grupos.
Es una regla de acceso, no una especificación de la respuesta — lo dijo
explícitamente.

Con eso, el arreglo es de una pieza: el `switch` se escribe como se pretendía y
la consulta ajena se retira, con lo que la rama devuelve lista vacía. Cumple la
regla y **no cambia lo que ve ningún cliente**, porque la consulta que había
tampoco devolvía nada.

**Lo que sigue abierto:** si esa pantalla debe enseñarle al alumno sus
asignaturas de verdad, que son las de su grupo — las que **ninguna de las dos
consultas miraba**. Eso no es arreglar esta ruta, es escribir la consulta que
nadie escribió, y Joseth lo dejó fuera a propósito.

**Y el agujero del seed, que se queda.** El candado se intentó escribir y se
tiró: la base de tests copia un solo grupo de alumnos, así que ahí no hay
ninguna colisión de ids y el test habría pasado dijera lo que dijera el código.
**El seed no puede demostrar los fallos que dependen de que dos numeraciones se
crucen** — ver [03-tests.md](03-tests.md). Lo que hay hoy es este documento y el
comentario al lado del `switch`.

### 11.2 `$todos_anios = true;` — un interruptor fijado a mano

`AlumnosController::putPersonasCheck`, con el `Request::input` comentado justo
encima:

```php
//$todos_anios = Request::input('todos_anios');
$todos_anios = true;

if ($todos_anios) { … }else{ … }
```

La rama `else` es una búsqueda distinta —limitada al año y con el grupo— que
alguien apagó fijando la variable.

**Decidido el 20 ago 2026: se queda como está.** Joseth: que un profesor pueda
ver a todos los estudiantes del plantel sin importar el año está bien. O sea que
el `true` fijado a mano no es un descuido pendiente de revertir, es el
comportamiento que se quiere — y lo que estaba mal era no tenerlo escrito en
ninguna parte.

La rama `else` **no se borra**, por la misma regla que el resto de este
documento: la línea comentada dice qué se pretendía y la rama dice qué hacía. Y
hay una razón añadida — `AsignarAcudienteAOtroModalCtrl.js` sigue mandando
`todos_anios: true` en el cuerpo, así que el front cree que el interruptor
existe. Quitarlo del backend sin avisar dejaría esa llamada mintiendo.

Lo que sí salió de esta decisión, y era la mitad importante de la pregunta, es
el otro lado: **quién puede usar el buscador**. Iba sin guard y lo contestaba
todo el mundo — está en §11.3.

### 11.3 Los buscadores de personas no tenían guard

Salió de la misma conversación: «si es un alumno el que está buscando… un
compañero no puede ver datos personales de otro».

`alumnos/personas-check` y `alumnos/documento-check` iban con `auth.token` y
nada más. Medido con el token de un alumno del seed y un `texto` de un carácter,
**antes** de tocarlas:

| Ruta | Lo que le devolvía a un alumno |
|---|---|
| `personas-check` | 61 compañeros: nombres, apellidos, foto y `alumno_id` |
| `documento-check` | 51 compañeros **con su número de documento** |

Un acudiente recibía lo mismo. Y el `alumno_id` no es un dato más: es la llave
de la superficie que fija `SuperficieDeUnAlumnoTest` — el buscador era el paso
previo, el que dice qué números pedir.

Las dos pasan a `auth.personal`, y lo fija `BuscadoresDePersonasTest`.
`alumnos/eps-check` y `acudientes/ocupaciones-check` se quedan como estaban:
devuelven `DISTINCT eps` y `DISTINCT ocupacion`, valores de catálogo sin ninguna
persona detrás.

**La mitad que no está en este repo.** El buscador del `sidebarMenu` de
`myvc_front` se pinta **sin `ng-if`**, mientras que todas las entradas del menú
que hay debajo sí están condicionadas por rol. O sea que un alumno ve la caja
«Buscar alumno» y puede teclear en ella. Con el guard puesto no obtiene nada,
pero esconderla es trabajo del front. El orden es el bueno: el guard va
desplegado antes, no después.

**Y uno que se ve al pasar y no se toca:** el `WHERE` de `putPersonasCheck` dice
`a.deleted_at is null and nombres like :texto or apellidos like :texto2`, sin
paréntesis. Con la precedencia de SQL eso es `(deleted_at is null AND nombres
like) OR (apellidos like)`, así que **buscar por apellido devuelve también
alumnos borrados**. Ahora solo lo ve el personal, y `AlumnosNewCtrl` usa la
respuesta —que incluye `deleted_at`— para detectar duplicados al crear un
alumno, donde ver los borrados puede ser lo que se quiere. Qué debe devolver es
una decisión pequeña, pero es una decisión.
