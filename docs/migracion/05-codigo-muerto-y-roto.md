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

> **Y son tres, no dos** (20 ago 2026, nivel 5). `GET api/importar/modificar/{year}`
> tiene la misma firma de la 2.x y tampoco estaba en ninguna lista. No lo
> encontró golpear —lleva parámetro en la URL, como esta— sino leer la firma.
> Ver la [§13.3](#133-el-cuarto-importador-con-la-firma-de-maatwebsite-2x).

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

## 12. Los cinco que quedaron del nivel 4 (20 ago 2026)

Con el nivel 4 puesto, de los 30 errores mecánicos que quedaban se borraron o se
simplificaron 24 y se reescribió 1. **Estos cinco se quedan con la línea que
sobra puesta**, y la razón es la misma en todos: aquí la línea muerta es
información. Borrarla dejaría el fichero limpio y la pregunta sin responder.

Cada uno tiene su entrada en `phpstan.neon` con su `count`, no en un baseline.

### 12.1 `PUT api/aplicacion-descargas/detailed` — el `return` que tapa un 500

`InicioController::putDetailed` tiene esto en sus dos primeras líneas:

```php
$user = User::fromToken();
return $user;
```

y debajo, sin ejecutarse nunca, el cuerpo entero del endpoint: la consulta de
grupos con su grado y su titular, condicionada a que llegue `year_id`.

Lo interesante es qué pasaría al quitar el `return`, que es lo que parece el
arreglo: el método terminaría en `return $resultado;` y **`$resultado` solo se
define dentro del `if ($con_grupos)`**. Sin `year_id` en el cuerpo, 500. O sea
que el `return` de más no es un descuido que sobra, **es lo único que hace que
la ruta conteste algo**, igual que el `oldpassword` de `putCambiarpassword`
resultó ser la cerradura del endpoint (§11).

Y hay un cliente detrás: el nombre del controlador es `AplicacionDescargas` y la
ruta la puede estar llamando la app de Flutter, que es **una sola para los
dieciséis colegios**. Hoy recibe el contexto del usuario y no se rompe. Decidir
qué debe devolver —el usuario, los grupos, o las dos cosas— es una pregunta para
quien conozca esa pantalla, no una limpieza.

### 12.2 `GET api/simat/alumnos-exportar` — la plantilla perdió sus instrucciones

La ruta **funciona**: devuelve `Excel::download(new AlumnosExport, 'alumnos.xlsx')`,
que es la API de maatwebsite 3.x. Debajo, sin ejecutarse, está la implementación
de la 2.x — y con ella el método `Comentarios()`, que escribe un comentario en
cada columna de la hoja:

> «Coloque: MATR, ASIS, RETI, DESE» · «¿Es urbano? SI o NO» · «Coloque "No
> aplica" o deje vacío si no tiene el antiguo SISBEN» · «Coloque: "CÉDULA",
> "TARJETA DE IDENTIDAD", "REGISTRO CIVIL"…»

**Eso no es decoración: es la especificación del importador que sí está vivo.**
Es exactamente lo que `ImporterFixer` lee de vuelta cuando la secretaría sube la
hoja — `strtolower($alumno["urbana"])=='si'`, `=='no aplica'` para el SISBEN—.
La hoja del SIMAT es de ida y vuelta: sale de aquí, la llena la secretaría y
entra por el importador que acabamos de hacer reanudable.

Y aquí está el hallazgo: **`AlumnosSheet`, el export 3.x que sí se ejecuta, no
escribe ninguno de esos comentarios.** Se perdieron en el salto de versión. Hoy
la secretaría recibe la plantilla en blanco y tiene que acordarse de que el
estado se escribe `MATR` y no `Matriculado`. No se arregla aquí —los exports
están fuera de alcance por el §5 del plan, y son 33 comentarios repartidos por
60 columnas—, pero **este bloque muerto es el único sitio del repo donde está
escrito qué acepta cada columna**, así que no se borra hasta que esté en el
export nuevo.

Su gemelo sí se borró: `ExcelListadoDocentesController` tenía una copia de
`Comentarios()` **que nadie llamaba**, y encima con las columnas de alumnos
—SISBEN, urbana, acudientes— dentro de un informe de docentes. Copia y pega en
el fichero equivocado; el original se queda en `SimatController`.

### 12.3 `PUT api/respuestas/actividad` — el promedio se calcula siempre sobre 4

En `WsActividadResuelta::alumnos_grupo`, con el comentario de su autor al lado:

```php
$cantidad_pregs = 4; // Debo hacer un código que traiga la cantidad de preguntas de la actividad

if ($cantidad_pregs > 0) {
    $promedio = $correctas * 100 / $cantidad_pregs;
}
```

El porcentaje de cada alumno sale **siempre sobre cuatro preguntas**, tenga la
actividad las que tenga: una actividad de 10 con 4 aciertos da 100, y una de 2
con 2 aciertos da 50. El número viaja al cliente tal cual, en
`$actividad_res->cantidad_pregs`.

Parece de una línea —`SELECT COUNT(*) FROM ws_preguntas WHERE actividad_id=?`— y
no se hace por dos razones. La primera es que **no se puede medir**: el módulo de
actividades está vacío en la base de desarrollo, cero actividades con preguntas y
cero resueltas, así que no hay forma de saber desde aquí cuántas actividades de
un colegio real tienen cuatro preguntas y a cuántos alumnos les cambiaría la
nota en pantalla. *Antes de optimizar algo: medirlo* vale también para arreglar.
La segunda es que hay decisiones dentro: si cuentan las preguntas borradas
(`deleted_at`), y qué pasa con `tipo_calificacion = 'Por promedio'`, que es una
columna de `ws_actividades` que nadie lee.

El `if ($cantidad_pregs > 0)` **no se borra aunque hoy no decida nada**: es el
guardia que hará falta el día que el 4 se sustituya por un `COUNT(*)`, y quitarlo
dejaría una división por cero esperando a la primera actividad sin preguntas.

### 12.4 y 12.5 Los dos que ya tenían dueño

- **`Definitivas.php`, el `$alumnos[$i];` suelto** (dos veces, uno por método).
  No asigna a nada, pero el `INSERT` de la línea siguiente usa `$alumno_id`, que
  no existe en ninguna parte — es uno de los cinco endpoints rotos de la §6.5.
  **La expresión suelta es la única pista de por dónde iba el autor**: lo que
  debía ir ahí es `$alumnos[$i]->alumno_id`. Se arregla el día que se decida qué
  hace el endpoint, y entonces la línea se usa en vez de borrarse.
- **`AlumnosController`, el `if ($todos_anios)`** que siempre es cierto. Decidido
  el 20 ago 2026 y explicado en la §11.2: se queda, la rama `else` también, y el
  front sigue mandando `todos_anios` como si el interruptor existiera.

### Y uno que no se quedó: el `== 'true'` que murió con PHP 8

Los tres informes con `Request::input('year_selected') == true || … == 'true'`
—`BolfinalesController`, `BolfinalesPreescolarController`,
`CertificadosPersonaController`— se simplificaron, pero **la rama derecha no se
escribió muerta**. En PHP 7 atrapaba los valores falsy, porque `0 == 'true'`
valía true; en PHP 8 la comparación entre número y cadena cambió y ya no se
alcanza.

O sea que un cliente que mandara `year_selected=0` recibía **el año seleccionado
antes de la migración y el año actual después**, sin que nadie tocara una línea.
Es el mismo patrón que los `tinyint(1)` del nivel 3: el analizador no encontró
código muerto, encontró un cambio de comportamiento del salto de versión que
llevaba ahí sin que nadie lo mirara. Los snapshots `bolfinales` y
`bolfinales-preescolar` de `BoletinesTest` cubren la forma de los tres.

---

## 13. Lo que encontró subir larastan al nivel 5 (20 ago 2026)

El nivel 5 comprueba **los argumentos**: si lo que se le pasa a un método es del
tipo que ese método pide. Empezó en **45 errores**, el número más bajo de todas
las subidas, y aun así trajo el fallo más caro de la serie — porque los tipos que
no encajan no son todos iguales. La mayoría son cadenas donde se espera un
entero, que PHP convierte solo; una era **una clase que no implementa
`Countable`**, y ahí no hay conversión que valga.

### 13.1 Borrar una imagen la borraba y luego respondía 500

`DELETE api/images-users/destroy/{id}` termina así:

```php
$asks = ChangeAsked::where('oficial_image_id', $id);   // ← un Builder, no una colección

if (count($asks) > 0) {
    if (method_exists($asks, 'destroy')) {
        $asks->destroy();
    }
}
```

**Tres fallos apilados, y el orden en que se destapan es lo que importa:**

1. `count()` sobre un `Builder`. En PHP 7 era un warning que devolvía **1**, así
   que la condición entraba. En PHP 8 es un `TypeError` y **mata la petición**.
2. `method_exists($asks, 'destroy')` es **false**: `destroy` es un método
   estático de `Model`, no del `Builder`. O sea que el bloque **nunca limpió
   nada**, ni antes ni después del salto de versión.
3. `change_asked.oficial_image_id` **no existe**. No está en `change_asked` ni en
   ninguna de las 90 tablas del esquema. Aunque los dos anteriores se arreglaran,
   la consulta saldría con «Unknown column».

Lo grave no es el 500, es **dónde** está: en la última línea. Cuando revienta, el
endpoint ya ha borrado el archivo del disco, ha marcado la fila de `images` y ha
puesto a `null` las cinco referencias —alumnos, profesores, acudientes, usuarios
y años—. **El cliente recibe un error de una operación que sí ocurrió.** Quien lo
reintente recibirá el 404 del `findOrFail`, que parece otro fallo distinto.

El bloque no hizo nunca nada, pero **lo que pretendía sí hacía falta**: al borrar
una imagen hay que limpiar también las peticiones de cambio que la nombran. Las
columnas están en `change_asked_data` y son cuatro —`foto_id_new`,
`image_id_new`, `firma_id_new` e `image_to_delete_id`—, que son las cuatro formas
que tiene una petición de nombrar una imagen.

**Decidido por Joseth el 20 ago 2026: se borra la petición**, no se pone su
referencia a `null`. El razonamiento es el que zanja la duda que dejaba abierta
la primera redacción de esta sección: una petición que pide cambiar la foto por
una imagen que ya no está **no es una petición a medias, es una que no se puede
conceder**. Dejarla viva con la referencia en `null` es dejarle al administrativo
algo que solo puede rechazar; y `image_to_delete_id` —la que pide «bórrame
esta»— ya está concedida de hecho.

Se borra como lo hace `putDestruir`, que es la operación que ya existía en
`ChangeAskedController` para esto: de verdad y en las tres tablas, porque ni
`change_asked_data` ni `change_asked_assignment` tienen `deleted_at`. Las tres
van en una transacción: media petición borrada es peor que ninguna, porque
`$consulta_all` la lee por `LEFT JOIN` y saldría entera con los campos del lado
que quedara sin borrar.

**Y el efecto que no se ve venir, que por eso tiene su propio test:** una petición
es **una por usuario y año**, así que puede llevar dentro un cambio de asignatura
que no tiene nada que ver con la imagen. Se va con ella — es lo que significa
borrar la petición, y es lo que hace `putDestruir`. Queda escrito para que el día
que alguien lo reporte sea una decisión y no una sorpresa.

Los seis casos están en `ImagenesTest`: uno por cada una de las cuatro columnas,
el del cambio de asignatura arrastrado, y el del otro lado —la petición de otra
imagen sigue viva—. Los cinco primeros se comprobaron al revés, desactivando el
borrado; el sexto sigue pasando sin él, que es exactamente el reparto que debe
dar.

### 13.2 Y detrás, un alumno borrando la foto de cualquiera

Al escribir el test del 500 salió lo que no se estaba buscando. La ruta lleva
`persona.propia` desde la revisión de IDOR del 19 ago 2026 — y **el guard no
miraba nada**.

`ExigirPersonaPropia` recoge los identificadores **por su nombre**
(`alumno_id`, `user_id`, `imagen_id`…), vengan por URL o por cuerpo. Y esta es la
única ruta de imagen cuyo parámetro se llama `{id}`:

| Ruta | Parámetro | ¿Comprobada? |
|---|---|---|
| `PUT myimages/publicar-imagen/{imagen_id}` | `imagen_id` | sí |
| `PUT myimages/privatizar-imagen/{imagen_id}` | `imagen_id` | sí |
| `PUT images-users/rotarimagen/{imagen_id}` | `imagen_id` | sí |
| `PUT images-users/rotar-imagen-izquierda/{imagen_id}` | `imagen_id` | sí |
| `DELETE images-users/destroy/{id}` | `id` | **no** |

Sin identificador reconocible, el guard entiende «lo mío» y deja pasar. Eso es lo
correcto para las rutas que no llevan id —`myimages/datos-imagen` devuelve las de
quien pregunta— y lo peor posible para esta, que lleva uno y con otro nombre. El
mecanismo para decirlo ya existía y es el que usan los `perfiles/*/{id}`
(`persona.propia:user_id`); aquí faltaba: ahora es `persona.propia:imagen_id`.

El efecto era el completo, no un intento: un alumno borraba la imagen de un
profesor —o el logo del colegio, que vive en `years.logo_id`— y **recibía el 500
de la 13.1 con el borrado ya hecho**. Los dos fallos se tapaban el uno al otro:
el 500 hacía parecer que la operación no había ocurrido.

**Es el tercer punto ciego de la misma familia**, después de los buscadores de la
§11.3 y del inventario de [08 §4](08-revision-idor.md). Los tres se resumen en
una frase: *el guard estaba puesto y la pregunta era otra*. Aquí la pregunta no
era «¿tiene guard esta ruta?» —lo tenía— sino «¿el guard reconoce lo que esta
ruta llama id?». **`inventario-autorizacion.py` no puede contestar esa**: lee
qué middleware lleva cada ruta, no si el nombre del parámetro casa con las claves
que el middleware busca.

Esa comparación sí es mecánica, y **se escribió como test en vez de como
herramienta** (decisión de Joseth, 20 ago 2026): corre con los otros y no depende
de que alguien se acuerde de lanzar un script. Son dos en `AutorizacionTest`, y
las claves las leen del propio middleware por reflexión, porque una lista copiada
se queda corta el día que el guard aprenda una clave nueva — que es justo el
fallo que existen para impedir:

- **`test_el_guard_reconoce_algun_identificador_de_cada_ruta_que_protege`**, que
  es la forma exacta del fallo y vale para cualquier nombre nuevo: si una ruta
  trae identificadores y el guard **no reconoce ninguno**, no está haciendo nada.
  Las que no traen ninguno no entran —esas sí significan «lo mío»—. Comprobado al
  revés devolviendo la ruta a `persona.propia` a secas: falla y nombra
  `api/images-users/destroy/{id} → id`.
- **`test_los_identificadores_que_el_guard_no_reconoce`**, un snapshot. Hoy trae
  dos, `{grupo_id}` y `{asignatura_id}`, y las dos están bien: no nombran a una
  persona y sus rutas llevan además un `{alumno_id}` que sí se comprueba. Va a
  snapshot y no a `assert` porque la máquina no sabe cuál es cuál — pero el día
  que aparezca un `{expediente_id}` de otro alumno al lado del `{alumno_id}`,
  saldrá en un diff y lo decidirá una persona.

**Lo que sigue sin verse, y hay que saberlo:** los dos miran los parámetros de la
URL. El guard lee también el cuerpo y la query, y ahí una clave con nombre nuevo
sigue siendo invisible desde fuera. Para eso no hay atajo estático — hace falta
golpear la ruta, que es lo que hace `SuperficieDeUnAlumnoTest`.

Los dos quedan fijados en `ImagenesTest`, escritos primero al revés —afirmando el
500 y el borrado ajeno— como los 27 de `SuperficieDeUnAlumnoTest`. El tercero es
el del otro lado: un alumno **sí** borra la suya, porque cerrar de más también se
nota en producción.

### 13.3 El cuarto importador con la firma de maatwebsite 2.x

`GET api/importar/modificar/{year}`, que no estaba en ninguna lista. La §8
nombraba dos —`GET api/importar` y `POST api/importar/cartera`— y son tres: los
tres hacen `Excel::import($ruta, function ($reader) { … })`, que es la firma de la
2.x, y los tres revientan con el mismo `pathinfo(): Argument #1 ($path) must be
of type string, Closure given`.

No salió antes por la misma razón que la cartera: el muestreo de la P2 golpeaba
lecturas **sin parámetro**, y esta lleva `{year}` en la URL. Lo que lo destapó fue
el nivel 5, que no golpea nada — lee la firma del método y compara. Es la
contraria exacta de la lección de la §8: allí, lo que no se golpea no se sabe si
funciona; aquí, lo que no se puede golpear a veces se puede leer.

Se deja roto con la misma regla que los otros —con ruta y roto se documenta— y se
añade a `ExcelTest`, que ahora fija los tres.

### 13.4 Lo demás: latente, mecánico, y una anotación que faltaba

- **22 `abort('400', …)` con el código entre comillas**, en `MatriculasController`
  (16), `AlumnosController` (5) e `ImagesController` (1). **Hoy funcionan**:
  comprobado en el contenedor, PHP convierte la cadena numérica y sale un 400 de
  verdad. Se pasan a entero porque es gratis y porque el día que alguien ponga
  `declare(strict_types=1)` en uno de esos ficheros, o escriba un código que no
  sea numérico, esto deja de convertirse solo. **No se les cambia el número**:
  que un «no tiene permisos» responda 400 en vez de 403 es el contrato que hoy
  reciben los cuatro clientes, y cambiarlo es otro trabajo.
- **Tres `Carbon::createFromDate($anio, $mes, $dia)`** con lo que devuelve
  `date('m', …)`, que es `'08'`. Mismo caso: funciona por conversión, se hace
  explícita con un `(int)`. El bloque es el mismo copiado tres veces
  —`MatriculasController`, `PrematriculasController`, `PlanillasController`—.
- **Cinco relaciones Eloquent escritas en la sintaxis de Laravel 4**:
  `hasMany('Alumno')`, `belongsToMany('Materia', 'asignaturas')`… Sin namespace,
  así que hoy ninguna resuelve. **No las llama nadie** —comprobado en `app/`,
  `resources/` y `tests/`, con paréntesis y sin ellos—: lo que parecía una
  llamada en `observador.blade.php` (`$grupo->alumnos`) es una propiedad que el
  controlador asigna a mano sobre un `stdClass` de una consulta cruda, como todo
  en este proyecto. Se borran las cinco. Una de ellas, `Matricula::alumnos()`,
  estaba además al revés: una matrícula es de **un** alumno.
- **`Sesion::rotar()`**: `$token->tokenable` es `Model|null` para el análisis, y
  `emitir()` pide un `User`. No es un fallo —`SesionController` comprueba
  `instanceof User` y devuelve 401 antes de llamar— pero el invariante vivía solo
  en el llamador. Ahora está escrito donde se usa.

---

## 14. Los listados que no nombran a nadie (20 ago 2026)

**El cuarto punto ciego de la misma familia, y el que más gente expone.** Los
tres anteriores fueron los buscadores de [§11.3](#113-y-de-la-misma-decisión-salió-lo-que-no-se-estaba-mirando),
el inventario de [08 §4](08-revision-idor.md) y el `{id}` que el guard no
reconocía de [§13.2](#132-y-detrás-un-alumno-borrando-la-foto-de-cualquiera).
Los tres se encontraron preguntando por **la petición**: qué identificador viaja
y si el guard lo mira. Este no se podía encontrar así, y por eso llevaba abierto
desde la revisión de IDOR.

### 14.1 La pregunta que faltaba

`inventario-autorizacion.py` contesta «¿qué guard cubre esta ruta?».
`auditar-autenticacion.php`, «¿exige token?». Los dos candados de
`AutorizacionTest` que se escribieron esta misma mañana, «¿el guard reconoce lo
que esta ruta llama id?». **Las cuatro preguntan por lo que entra.**

Estas siete rutas no traen ningún identificador —`planillas/ver-simat` no pide
grupo: devuelve **todos los del año**— así que ninguna herramienta tenía nada que
señalar. La pregunta que las encuentra es la otra: **¿qué sale?** Es exactamente
el criterio que hace útiles a los tests de contrato —mirar el resultado y no el
estado— y que no se estaba aplicando a la autorización.

Se midió golpeando las 121 lecturas de la API con **el token de un alumno** y
mirando si en la respuesta aparecía el dato personal de alguien: documento,
dirección, teléfono, celular, correo o fecha de nacimiento. Es un barrido de una
sola tarde y encontró siete.

### 14.2 Lo que un alumno podía leer, medido

| Ruta | Lo que devolvía |
|---|---|
| `GET api/planillas/ver-simat` | **todos los grupos del año**, y de cada alumno: documento, tipo de documento, fecha de nacimiento, tipo de sangre, EPS, teléfono, celular, dirección, barrio, estrato, religión, correo y estado de deuda |
| `GET api/planillas/ver-ausencias` | los mismos grupos y los mismos alumnos, por otro lado |
| `GET api/planillas/listas-personalizadas` | ídem |
| `GET api/perfiles/usuariosall` | el directorio entero: **2.279 personas** con nombre, usuario, tipo, correo, fecha de nacimiento, foto y roles. 1,5 MB |
| `GET api/profesores` | la hoja de vida de los **47 docentes**: documento, dirección, teléfono, celular, correo, título y estado civil |
| `GET api/grupos/show/{id}` | cualquier grupo con la ficha **completa** de su titular |
| `GET api/perfiles/show/{id}` | el mismo método copiado en el controlador equivocado |

**El patrón se ve al mirar las vecinas, y es lo que explica cómo pasó.** En
`ProfesoresController` las once rutas llevan `auth.personal` **menos el
listado**. En `ContratosController`, igual. En `PlanillasController` lo llevan
`show-grupo` y `show-profesor` —las que piden un id— y no las tres que no piden
nada. O sea que la revisión de IDOR guardó **lo que nombraba a alguien**, que era
su criterio, y estas se quedaron fuera por no nombrar a nadie.

Lo dice el propio `ExigirPersonaPropia` en su cabecera, escrito aquel día: *«lo
que no puede pasar es que una ruta de grupo entero llegue aquí sin id y salga
entera: esas llevan `auth.personal`»*. **La regla estaba escrita y estas siete se
quedaron sin ella.**

`perfiles/show/{id}` merece su línea aparte porque es la variante fina: **sí
tenía guard**, `persona.propia:user_id`, y el guard cumplía. Lo que era falso es
lo que le habían dicho — el método hace `Grupo::findOrFail($id)`, así que `{id}`
es un grupo y no un usuario. Un alumno cuyo `user_id` coincidiera con el id de
algún grupo recibía ese grupo; los demás, un 404 que parecía normal. Es
`GruposController` copiado en el fichero equivocado, y con él otros cuatro
métodos (`destroy`, `forcedelete`, `restore`, `trashed`) — lo que explica que
`perfiles/forcedelete` cascadeara por las mismas 27 tablas que
`grupos/forcedelete`: **es** `grupos/forcedelete`. Está anotado desde el front en
`services/api/PerfilesApi.js` y en `usuarios/UsuariosEditCtrl.js`, que se
tropezaron con lo mismo por el otro lado y dejaron dicho que **que el nombre de
una ruta encaje no dice nada de lo que devuelve**.

### 14.3 Lo que se hizo

Las siete llevan `auth.personal`. No es una decisión nueva: es la regla del 19
de agosto —*un alumno solo ve lo suyo*— aplicada donde no se había aplicado, y el
mismo remedio que se les puso a los buscadores de §11.3.

Se comprobó antes de cerrar que ninguna la llama una pantalla de familia, en los
cuatro clientes: las tres de planillas cuelgan de `panel.informes` con
`hasRoleOrPerm(['psicólogo'])` encima, `perfiles/usuariosall` es la rejilla de
`UsuariosCtrl`, `profesores` lo piden cinco pantallas de administración —la app
de Flutter usa `/contratos` para los nombres, no esta— y `grupos/show` y
`perfiles/show` **no las llama nadie en ningún cliente**.

Lo fijan catorce casos nuevos en `SuperficieDeUnAlumnoTest`, siete con token de
alumno y siete con token de acudiente. Como todos los de ese archivo, **se
escribieron primero al revés**: afirmando la fuga y comprobando que el dato
personal salía en la respuesta. Seis pasaron —o sea que seis filtraban— y la
séptima, `perfiles/show`, dio 403 por el motivo de arriba: su guard la cerraba
por accidente, comprobando lo que no era.

### 14.4 Lo que queda, y por qué no se cerró hoy

Tres rutas más salieron del mismo barrido y **ninguna se puede cerrar con un
guard sin romper una pantalla que una familia sí usa**. Van al
[09-pendientes.md](09-pendientes.md) porque cada una necesita una decisión:

- **`GET api/contratos`** — nueve docentes con documento, dirección, teléfono,
  celular, correo y estado civil. La app de Flutter la llama desde pantallas de
  alumno y de acudiente (`FaltasAlumnoScreen`, `AsistenciaClaseScreen`,
  `UnidadesApi`, `NotasApi`) y **solo la usa para pasar de un id a un nombre**.
  Lo que hay que recortar es la respuesta, no la puerta — y eso cambia el
  contrato de los dieciséis colegios a la vez, porque la app es una sola.
- **`GET api/perfiles/usernames`** — los 2.351 nombres de usuario del colegio.
  Lo pide `UserConfiguracionCtrl`, la pantalla de configuración **del propio
  usuario**, que un alumno alcanza, y solo para saber si el nombre que escribe
  está libre. Ya existe el endpoint que contesta esa pregunta sin la lista:
  `GET api/perfiles/comprobarusername/{username}`, 17 bytes. Es un arreglo de
  front primero y de backend después, en ese orden: cerrar antes de desplegar el
  front deja a las familias sin poder cambiarse el usuario.
- **`GET api/perfiles/username/{username}`** — la ficha de una persona por su
  nombre de usuario: documento, correo de recuperación y fecha de nacimiento. Es
  el `resolve` de `panel.user`, la pantalla del perfil propio, y **no comprueba
  que el nombre de usuario sea el tuyo**. Aquí `ExigirPersonaPropia` no puede
  ayudar tal como está: sus siete claves terminan todas en `_id` y el
  identificador de esta ruta es un nombre. **Es la quinta variante de la
  familia** — no es que falte el guard ni que no reconozca el parámetro: es que
  el parámetro no se parece a un identificador.

Y una que se midió y **está bien**, para no volver a mirarla:
`GET api/ChangesAsked/to-me` pesa 210 KB y a un alumno le devuelve lo suyo —sus
ausencias, su comportamiento, sus publicaciones— más 593 eventos del calendario y
los nombres y fotos de sus nueve profesores. Nada ajeno.

---

## 15. La otra mitad: las escrituras que no nombran a nadie (20 ago 2026)

La [§14](#14-los-listados-que-no-nombran-a-nadie-20-ago-2026) preguntó **qué
sale**. Esta pregunta **si llegó a escribir**, y son dos preguntas distintas por
una razón concreta de este proyecto: **aquí se lee con `PUT`**. Un 200 no
distingue una consulta de un `UPDATE`, así que la lista de «escrituras que el
guard no cortó» no dice nada por sí sola.

Se midió escuchando las consultas de cada petición —`DB::listen`, quedándose con
las que empiezan por `insert`, `update` o `delete`— y descontando `bitacoras` y
`personal_access_tokens`, que son la huella de la defensa y no del ataque.

**De las 417 escrituras de la API, 133 llegaban al controlador con el token de un
alumno y 27 cambiaban datos de verdad.**

### 15.1 El patrón, que esta vez se ve de un vistazo

En `ActividadesController`, `PreguntasController` y `OpcionesController`, **la
única ruta de cada uno que llevaba guard era `destroy/{id}`** — la única que tiene
un `{id}` en la URL. Las otras veinte —crear la actividad, editarla, compartirla
con un grupo, añadir preguntas y opciones— iban abiertas.

Es el mismo criterio de §14 visto desde el otro lado: **se guardó lo que nombraba
a alguien**. Y aparece otra vez en parejas de hermanas donde una lo lleva y la
otra no, en el mismo fichero y a pocas líneas:

| Sin guard | Su hermana, que sí lo tenía |
|---|---|
| `PUT matriculas/alumnos-con-grado-anterior` | `PUT prematriculas/alumnos-con-grado-anterior` |
| `PUT images-users/cambiar-imagen-perfil/{user_id}` | `cambiar-imagen-oficial/{user_id}` · `cambiar-imagen-un-usuario/{user_id}` |
| `PUT images-users/cambiar-foto-un-usuario/{user_id}` | `putRotarimagen/{imagen_id}` y las demás de imagen |

### 15.2 Las cuatro que valen por sí solas

**`PUT api/alumnos/cambiar-claves` — la contraseña de todo un grupo.** Toma
`clave` y `grupo_id` **del cuerpo** y hace un `UPDATE users INNER JOIN matriculas
... WHERE m.grupo_id = :grupo_id`. Un alumno le ponía la contraseña que quisiera
a todos los alumnos de cualquier grupo. No nombra a ninguna persona: nombra un
grupo. Y es la única de todo lo encontrado estos dos días que es
**irreversible** —nadie guarda la contraseña anterior—, así que su test se
comprueba por el efecto y no por el 403.

**Los seis interruptores de la elección.** `votaciones/set-actual`,
`set-in-action`, `set-locked`, `set-permiso-ver-results`, `set-votan-acudientes` y
`set-votan-profes`: cuál es la votación vigente, si está abierta, si está
bloqueada, quién puede votar y quién ve los resultados. La votación viaja en el
cuerpo. Y el `UPDATE` de `set-actual` **no lleva ninguna condición de dueño** —el
de arriba sí la lleva, el de abajo no—, así que valía para cualquier votación de
cualquier colegio del año.

**`PUT api/images-users/move-img-to-me` — una escalada, no una fuga.**
`UPDATE images SET user_id = <yo> WHERE id = :img_id`, sin mirar de quién era. El
daño no está en esa línea: está en que **una vez la imagen es suya, sus hermanas
—rotar, publicar, privatizar, borrar— comprueban la propiedad y dicen que sí**.
El guard no veía nada que comprobar porque aquí la clave se llama `img_id` y sus
siete claves se escribían `imagen_id`. **Es la sexta variante de la familia**: no
falta el guard, ni deja de reconocer el parámetro — es el mismo identificador con
otro nombre. `ExigirPersonaPropia` ya lo conoce, y esa lista tiene que crecer con
los endpoints, nunca al revés.

**`PUT api/publicaciones/delete` — la regla vivía solo en el frontend.** Borraba
por `publi_id` sin mirar de quién era la publicación, así que cualquiera con un
token vaciaba el muro. La regla existía, escrita en el `ng-if` del botón de la
papelera de `publicacionesPanelDir.html`:
`publi.persona_id == USER.persona_id || USER.is_superuser`. Aquí el guard no
sirve —la publicación no viaja como persona, viaja como `publi_id`—, así que la
comprobación se puso en el controlador, **exactamente la misma que el front ya
decide**, para que nada de lo que hoy se puede hacer deje de poderse. `tipo_persona`
entra en la comparación aunque el front no lo mire, porque `persona_id` solo es
único dentro de su tabla: el alumno 12 y el profesor 12 existen los dos.

### 15.3 Y una corrección a la §14: el barrido de lectura miró poco

El de ayer solo golpeó las **GET**, y en este proyecto se lee con `PUT`. Pasando
el mismo detector de datos personales por las lecturas-`PUT` salieron cuatro más,
todas del fichero de acudientes —documento, celular, dirección, fecha de
nacimiento—: `acudientes/buscar` (38 KB), `acudientes/planillas-ausencias`
(50 KB), `acudientes/no-asignados` y `acudientes/ultimos`. Más
`participantes/profesores`, con la ficha completa de los docentes.

Las cuatro las piden `NewAcudienteModalCtrl`, `AcudientesCtrl` e `informes`, y la
app de Flutter no llama a ninguna ruta de `acudientes/`.

### 15.4 Lo que se dejó abierto a propósito

- **`DELETE api/myimages/destroy/{id}`** parece la gemela sin guard de la que se
  arregló en [§13](#13-lo-que-encontró-subir-larastan-al-nivel-5-20-ago-2026), y
  no lo es: cuando la imagen **no** es tuya no la borra, **abre una petición de
  cambio**. Eso está escrito a propósito y ponerle `persona.propia` mataría la
  función. Es el ejemplo de por qué esto no se puede cerrar con una lista.
- **`PUT api/publicaciones/comentar`**, **`mis-actividades/*`**,
  **`respuestas/actividad`**, **`perfiles/guardar-mi-email-restore`** y las de
  sesión: son lo suyo, y siguen abiertas. La mitad que no se puede perder tiene
  su propio caso en `SuperficieDeUnAlumnoTest` — borrar **su** publicación se
  comprueba en el mismo test que impide borrar la ajena.
- **`PUT api/aplicacion-descargas/detailed`** devuelve una `fecha_nac`, la suya.
  Es lo que pide la app de Flutter al arrancar y está descrita en
  [§12.1](#121-put-apiaplicacion-descargasdetailed).

### 15.5 Qué queda de esto

Veinte casos nuevos en `SuperficieDeUnAlumnoTest`: diecisiete de puerta y tres de
efecto —la contraseña del grupo, el dueño de la imagen y el muro—. **No hacía
falta escribirlos al revés**, que es lo que pide el resto del archivo: el barrido
que los encontró **ya es la prueba al revés**, porque no midió códigos de
respuesta sino filas cambiadas. Un test que afirmara la fuga no habría demostrado
nada que la medición no demostrara antes.

Lo que este barrido **no** cubre, para no repetir la lección de la §14: se hizo
con token de **alumno**. Un acudiente tiene una superficie parecida pero no
idéntica —`persona.propia` le acepta lo de sus acudidos— y no se ha barrido con
el mismo detector.

---

## 16. El acudiente, y lo que el barrido no puede ver (20 ago 2026)

La [§15](#15-la-otra-mitad-las-escrituras-que-no-nombran-a-nadie-20-ago-2026)
terminaba diciendo que faltaba barrer con token de **acudiente**. Se hizo, y el
resultado tiene dos mitades muy distintas de tamaño: el acudiente no encontró
ningún agujero, y **el barrido sí**.

### 16.1 El acudiente: nada nuevo, y por qué eso se escribe

Con `BARRIDO_TIPO=Acudiente` pasan de largo 17 rutas frente a las 15 del alumno.
Las dos de diferencia son suyas:

- **`PUT api/acudientes/mis-acudidos`** — sus acudidos, que es literalmente para
  lo que existe.
- **`GET api/ChangesAsked/to-me`** — 209 KB con documento, celular, dirección y
  correo. Los 204 KB son **593 filas del calendario**, no personas; la rama de
  acudiente une por `parentescos` y el único alumno que devuelve es su acudido.

Que no haya hallazgos no significa que la pasada sobrara: lo que se sabe ahora y
no se sabía es que **`persona.propia` resuelve bien los parentescos en toda la
superficie**, no solo en las rutas que tienen su caso escrito.

### 16.2 Tres fallos del barrido, y el más caro no se veía

- **Imprimía menos de lo que contaba.** Entre las 539 rutas hay descargas, y una
  respuesta de archivo de Symfony vacía el buffer de salida al enviarse; con él
  se iban las líneas ya escritas. El barrido decía «17 rutas» y enseñaba once.
  **Las seis que faltaban eran siempre las primeras**, o sea las mismas seis en
  cada pasada, incluidas las de la §14 y la §15. Ahora acumula y vuelca al final.
- **Pedía en el año equivocado.** `Services\Login` reescribe `users.periodo_id`
  al periodo del año actual, así que el año del contexto solo se sabe **después**
  de entrar, y el barrido elegía los identificadores con la fila leída antes. Es
  exactamente la trampa que `tokenDelPersonalDe()` lleva documentada desde la
  P1: la respuesta sale vacía, en 200, y no ha calculado nada.
- **36 rutas no se estaban midiendo.** El seed tiene **dos** grupos y 56 de sus
  68 alumnos están matriculados en los dos, así que para el sujeto de siempre no
  existía ningún grupo ajeno y el barrido pedía `grupo_id=0`. Boletines,
  planillas, observador, certificados y actas **de otro grupo** contestaban vacío
  sin haber medido nada, y un vacío se parece a un guard que funciona.

  Ahora el barrido elige un alumno matriculado en un solo grupo —y del año
  actual, porque el login le reescribe el periodo y uno de otro año se quedaría
  sin contexto—. Las 36 se midieron y **las 34 que son de grupo dan 403**. Las
  dos que no son las de la §16.3.

  Para el acudiente no hay sujeto posible: ninguno del seed tiene a sus acudidos
  en un solo grupo. El barrido lo imprime al final en vez de callárselo, que es
  lo mismo que hace el `assertSame` de los parámetros desconocidos.

### 16.3 Un GET que escribe

**`GET api/unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}/{user?}`
era la única ruta de `unidades/*` sin `auth.personal`, y no es una lectura.**
Cuando esa asignatura y ese periodo no tienen unidades todavía, **las crea** a
partir de las `unidades_por_defecto` del año, con sus subunidades y con
`created_by` de quien pregunta. Comprobado: un alumno y un acudiente crean
unidades en la estructura de notas del colegio pidiendo una URL.

No lo encontró ninguna de las tres herramientas, y cada una falló por un motivo
distinto que conviene separar:

- **`inventario-autorizacion.py`** sí la lista, pero entre las lecturas de
  estructura pendientes de decidir ([08](08-revision-idor.md), punto 3): la
  herramienta pregunta qué identificador viaja, no qué hace el método.
- **El barrido de escrituras** la golpeó con `asignatura_id=0` — el fallo de la
  §16.2.
- **Y aunque la hubiera golpeado bien, no habría escrito**, porque
  `unidades_por_defecto` **está vacía en el seed**. Es de la familia de «la base
  de tests no puede demostrar los fallos que dependen de dos numeraciones que se
  cruzan» ([§11](#11-lo-que-encontró-subir-larastan-al-nivel-3-y-mirar-el-4-20-ago-2026)),
  y por eso el candado llena la tabla antes de pedir: sin esa fila pasaría dijera
  lo que dijera el código.

Cerrada con `auth.personal`, como sus once hermanas. **Esto no re-litiga la
decisión pendiente** de [08](08-revision-idor.md): allí se aplazó como lectura de
estructura, y una escritura de estructura por un alumno ya estaba decidida en la
§15.

Y de paso, **el `{user?}` de esa URL era un 500 seguro**. El tercer parámetro del
método es a la vez el argumento de la llamada interna de `putDeAsignaturaPeriodo`
—que pasa el objeto de usuario— y un segmento de la URL, que solo puede llegar
como cadena; con `if ($user == null)` se colaba y reventaba en `$user->year_id`
siempre que el par no tuviera unidades ya. Ahora es `is_object()`.

### 16.4 Cuatro papeleras sin guard, y dos que mienten en el nombre

| Ruta | Qué devuelve de verdad |
|---|---|
| `GET api/subunidades/trashed` | **Los alumnos borrados del colegio**, con documento, fecha de nacimiento, celular y dirección |
| `GET api/editnota/trashed` | La misma consulta copiada. Tampoco son notas editadas |
| `GET api/unidades/trashed` | La papelera académica del colegio entero: 29 KB en el seed |
| `GET api/asignaturas/papelera` | Las asignaturas borradas del año, con el nombre de su profesor |

Las cuatro con token de alumno y de acudiente. Las cuatro son pantallas de
administración y sus familias enteras ya llevaban `auth.personal`; ahora lo
llevan ellas.

**Lo que hay que recordar de estas cuatro no es el número: es por qué ninguna
herramienta podía verlas.**

- `inventario-autorizacion.py` no las lista porque **no reciben ningún
  identificador**. Es el mismo punto ciego que dejó pasar los buscadores
  ([§11.3](#113-los-buscadores-de-personas)) y que
  [08 §4](08-revision-idor.md) dejó escrito.
- El barrido las golpeó y las vio contestar. **`unidades/trashed` le devolvió
  29 KB y no la imprimió**, porque su criterio de fuga es la lista `PERSONALES`
  y ahí no hay ninguna de esas columnas. Hay una tercera categoría entre «dato
  personal» y «escritura» —**lo del colegio que no es de nadie en particular**—
  y el barrido no la mide.
- Y a las dos que **sí** sueltan datos personales las vio contestar `[]`, porque
  **el seed no tiene ningún alumno borrado**. Un `[]` de una papelera vacía se
  lee igual que un `[]` de un guard que corta.

Por eso los candados de estas cuatro comprueban el 403 y no el cuerpo, al revés
que `noSaleElDato()`: con el seed que hay, la comprobación del cuerpo pasaría sin
significar nada, y un test que pasa por vacío es peor que no tenerlo.

### 16.5 Y un 500 que era un 404

`Asignatura::detallada` termina en `return (array)$asignatura[0];` y la consulta
une por `g.year_id`: **una asignatura que no sea del año desde el que se pregunta
no devuelve filas**, y ese `[0]` reventaba. Lo alcanzan `asignaturas/show`,
`ausencias/detailed` y `notas/detailed-notas`.

No es un error del servidor —es que esa asignatura no existe en ese año— así que
ahora es un `abort(404)`. Con `APP_DEBUG` puesto, además, el 500 se llevaba la
traza dentro, que es la mitad de la §1 de [01](01-plan-seguridad.md).

### 16.6 Lo que queda de esto

- Nueve casos nuevos en `SuperficieDeUnAlumnoTest`, **comprobados al revés**:
  quitados los cinco `auth.personal` y el `abort(404)`, los seis fallan, cada uno
  por su motivo —el de las unidades por haberlas creado, no por el código—.
- El barrido, arreglado y con las dos pasadas hechas, en
  `tests/Barrido/SuperficieDeUnTokenTest.php`.
- **Y una pendiente que no se toca, porque es la misma que Joseth dejó abierta a
  propósito en la [§11.2](#11-lo-que-encontró-subir-larastan-al-nivel-3-y-mirar-el-4-20-ago-2026):**
  `GET api/asignaturas/listasignaturas-alone` llama a
  `Profesor::asignaturas($year, $user->persona_id)` sin mirar el tipo, así que a
  un alumno le devuelve las asignaturas del **profesor que tenga su mismo id**.
  Es el gemelo sin parámetro de `asignaturas/listasignaturas/{persona_id?}`, que
  sí lleva `persona.propia`. Cerrarlo con `auth.personal` es fácil; lo que no
  está decidido —y es lo que quedó abierto entonces— es **si esa pantalla debe
  enseñarle sus asignaturas de verdad**. El seed no puede demostrar el fallo,
  por lo de siempre: ahí los ids de alumno y de profesor no se solapan.

---

## 17. La hermana que se quedó sin el guard (20 ago 2026)

Los cinco agujeros de la [§16](#16-el-acudiente-y-lo-que-el-barrido-no-puede-ver-20-ago-2026)
se encontraron mirándolos a mano, y al escribirlos salió que los cinco tenían la
**misma forma**: eran la única ruta de su familia sin guard. Eso es mecánico, y
lo mecánico se escribe como test.

`AutorizacionTest::test_ninguna_ruta_se_queda_sola_sin_el_guard_de_su_familia`
mira, por prefijo de URL, las rutas que no llevan `auth.personal`,
`persona.propia` ni `boletin.propio` cuando **dos o más hermanas sí** y las que
no son minoría clara. No afirma que estén mal —hay excepciones legítimas, y van
en `EXCEPCIONES_DE_FAMILIA` una a una con su motivo, como en `phpstan.neon`—
sino que **nadie ha decidido**. Un segundo test comprueba que ninguna excepción
sobre, para que la lista no pueda solo crecer.

Se mide por prefijo y no por controlador a propósito: `editnota/trashed` vive en
`EditnotaController` y devuelve alumnos borrados. Lo que la delata es estar sola
en `editnota/*`, no su clase.

### 17.1 Lo que encontró el mismo día que se escribió

Las 27 que marcó estaban todas explicadas —catálogos pendientes de decisión en
[08](08-revision-idor.md), rutas defendidas dentro del método, y dos rotas que ya
tenían su entrada aquí—. **Lo que no estaba explicado fue lo que enseñó su
gemelo**, el snapshot `guard-por-familia`: de las 95 familias, **12 no tenían
ningún guard**, y por eso la regla de «la que se quedó sola» no las mira — no hay
hermana con la que comparar.

Nueve de las doce son correctas (`auth`, `login`, `publicaciones`,
`respuestas`, `tardanzas` —que son públicas y son un test—, `folios`,
`aplicacion-descargas`, `importar`, `calendario`, defendidas dentro o ya
descritas). Las otras tres, no.

### 17.2 Quién pasa el año

**`PUT api/promovidos/calcular-grupo` no es un cálculo que se devuelve: escribe.**
Recorre el grupo que se nombre en el CUERPO y hace

```sql
UPDATE matriculas SET promovido=?, promedio=?, cant_asign_perdidas=?, cant_areas_perdidas=?
WHERE id=? AND promovido NOT LIKE '%(manual)%'
```

por cada alumno. Un alumno y un acudiente lo dispararon sobre un grupo que no es
suyo, y de paso recibieron **331 KB** con las notas de ese grupo.

De todo lo que se ha encontrado en esta serie es lo más caro, porque el campo que
escribe es el que dice si un alumno repite. Y **el barrido no podía verlo**: el
`grupo_id` viaja en el cuerpo y el barrido golpea con el cuerpo vacío, así que
`Grupo::alumnos(null)` no devolvía a nadie y no había nada que actualizar. Es la
limitación que la §15 dejó escrita, con su primera víctima concreta.

### 17.3 La cartera, que no miraba el token ni una vez

| Ruta | De dónde saca su alcance |
|---|---|
| `PUT api/cartera/solo-deudores` | `year_id` del cuerpo. Devuelve **todos los deudores del colegio** con documento, celular, dirección y deuda |
| `PUT api/cartera/alumnos` | `grupo_actual` del cuerpo, cualquier grupo. Tiene el `User::fromToken()` **comentado** |
| `GET api/cartera/exportar-solo-deudores` | De ningún sitio: devuelve el **Excel de deudores** sin parámetros |

Las tres, con token de alumno y de acudiente. El barrido no vio ninguna, y por
las **dos mitades de su límite a la vez**: las dos primeras piden por el cuerpo,
que él manda vacío, y la tercera devuelve un `xlsx` cuyos bytes su detector de
datos personales no puede leer.

En el seed `solo-deudores` sale vacía porque no hay nadie con `pazysalvo=false`
—la misma trampa que `unidades_por_defecto` y que los alumnos borrados de la
§16.4—, así que el candado comprueba el 403 y no el cuerpo.

### 17.4 Los otros dos buscadores, y una comilla

`PUT api/buscar/por-nombre` y `PUT api/buscar/por-apellido` hacen lo mismo que
`alumnos/personas-check` y `alumnos/documento-check`, que se cerraron en la
[§11.3](#113-los-buscadores-de-personas): con una sola letra le devolvían a
cualquier alumno **49 compañeros** con su `alumno_id`, su `user_id`, su foto y su
grupo. Se quedaron fuera de aquella pasada porque viven en otra familia,
`buscar/*`, y porque **reciben `texto_a_buscar` y no un id**, que es exactamente
el punto ciego que [08 §4](08-revision-idor.md) dejó descrito: la herramienta mide
la cerradura y no mira quién reparte las llaves.

Y había una segunda cosa dentro, que es de otra clase:

```php
$consulta = $this->consulta_ini . " WHERE a.apellidos like '%$texto_a_buscar%'";
$res = DB::select($consulta, [$user->year_id]);
```

**El texto entra interpolado en la cadena de la consulta.** El `year_id` va como
parámetro y el texto del cliente no. Para verlo no hace falta plantear un ataque:
basta un alumno que se apellide **O'Brien** — el buscador responde 500. Por eso
el candado busca una comilla, que es la misma prueba escrita sin construir nada.

Ahora va como parámetro. El `%` que escriba quien busca sigue funcionando como
comodín, que es lo que hace hoy y lo que la secretaría usa.

### 17.5 Lo que queda de esto

- Seis rutas más con `auth.personal`, y tres familias que pasan de no tener
  ningún guard a tenerlo entero: `buscar`, `cartera` y `promovidos`.
- Cuatro casos nuevos en `SuperficieDeUnAlumnoTest`, comprobados al revés. El de
  la promoción mira **el efecto**: guarda el `promovido` de cada matrícula del
  grupo antes y después, porque un 403 que llegue tras el `UPDATE` no vale nada.
- Dos tests y un snapshot nuevos en `AutorizacionTest`.
- Y la lección, que es la de siempre con una vuelta más: **cada herramienta de
  esta serie encontró lo que las anteriores no podían ver, y ésta encontró lo
  suyo sin golpear nada.** El inventario mira la petición; el barrido, el
  resultado; ésta mira **la forma de la tabla de rutas**, y por eso ve las que no
  reciben identificador y las que solo escriben con el cuerpo lleno.

---

## 18. El cuerpo lleno, y el módulo de votaciones (20 ago 2026)

La [§17](#17-la-hermana-que-se-quedó-sin-el-guard-20-ago-2026) terminó nombrando
lo que faltaba: **golpear con cuerpos plausibles**. Es lo que había ocultado
`promovidos/calcular-grupo` y media cartera, y se hizo a continuación. El barrido
manda ahora, en cada petición, los mismos identificadores ajenos con los nombres
que usan los cuerpos —`grupo_id`, `grupo_actual`, `alumno_id`, `votacion_id`,
`texto_a_buscar`…—, todos a la vez: una ruta lee la que conoce y las demás le dan
igual.

Lo que cuesta está escrito en el método: una ruta que lea **dos** recibe una
combinación que puede no casar, y entonces el vacío vuelve a no probar nada. Es
el mismo límite que el mapa de la URL y se acepta por lo mismo — la alternativa
es una petición por combinación, y son 539 rutas.

Dos cosas se vieron de inmediato. Una fue que **`images-users/move-img-to-me`
desapareció de la lista de hallazgos**: con el `img_id` ajeno en el cuerpo,
`persona.propia` lo corta. Antes pasaba porque el cuerpo iba vacío y el guard
entendía «lo mío». La otra fue el módulo de votaciones.

### 18.1 El mismo patrón, sin una sola variación

De las cinco familias del módulo, **la ruta con guard era `destroy/{id}` en todas
ellas**. Es literalmente el patrón de la [§15](#15-la-otra-mitad-las-escrituras-que-no-nombran-a-nadie-20-ago-2026):
el guard fue a la que tiene un `{id}` en la URL. Lo que había detrás, con token
de alumno y de acudiente:

| Ruta | Qué hacía |
|---|---|
| `POST votaciones/store` | **Crea una votación.** Y con `actual=1` hace `UPDATE vt_votaciones SET actual=0 WHERE actual=1` sobre todas. Escribía y **después** respondía 500, que es el patrón de la [§13.1](#13-lo-que-encontró-subir-larastan-al-nivel-5-20-ago-2026) |
| `POST aspiraciones/store` · `PUT aspiraciones/update` | Crea y edita los cargos a los que se aspira |
| `POST candidatos/store` | **Inscribe como candidato al `user_id` que venga en el cuerpo**, o sea a cualquiera |
| `PUT participantes/votantes` | 37 KB con documento, celular, dirección, fecha de nacimiento y correo de todo un grupo — **y a quién votó cada uno** |
| `GET votos` | `VtVoto::all()`: 52 KB con todos los votos del colegio y el `user_id` que emitió cada uno. Es el voto secreto |
| `GET candidatos` | `VtCandidato::all()`, de todos los años |
| `GET participantes` · `allinscritos` · `PUT datos` · `guardar-inscripciones` · `inscribir-profesores` · `set-locked` | El censo electoral, y las cuatro últimas lo escriben |

### 18.2 Qué se cierra lo decidió el front, no el criterio

Cerrar catorce rutas de un módulo a ojo es dejar sin elecciones a dieciséis
colegios, así que se miró quién las llama, que es lo que ya se hizo en la
[§14](#14-los-listados-que-no-nombran-a-nadie-20-ago-2026):

- **`VotarCtrl` es el único estado de `votaciones/*` sin `needed_permissions`** en
  `VotacionesConfig.js`, y llama exactamente a dos endpoints:
  `votaciones/en-accion-inscrito` y `votos/store`.
- `ParticipantesCtrl` y `CandidatosCtrl` van con `can_edit_participantes` y
  `can_edit_candidatos`. `VotacionesCtrl` es la pantalla de Configuración, cuyos
  seis `set-*`, su `update` y su `destroy` **ya llevaban `auth.personal`**: la
  pantalla ya estaba cerrada a las familias por todas partes menos por `store`.
- `votaciones/unsignedsusers`, `participantes/inscribir-profesores` y
  `participantes/set-locked` **no las llama ningún cliente**, en ninguno de los
  cuatro.

Con eso, catorce con `auth.personal` y cinco familias que pasan a estar cerradas
o casi: `aspiraciones` 3/3, `participantes` 9/9, `candidatos` 3/4, `votos` 3/5,
`votaciones` 10/15.

Se quedan abiertas y con su motivo en `EXCEPCIONES_DE_FAMILIA`:
`candidatos/conaspiraciones` (la papeleta de la pantalla de prueba, §18.5),
`votos/store` (votar), `votos/show` (los resultados de quien pregunta, acotados
por su `user_id`), el índice de `votaciones` (acotado por `user_id`) y las tres
lecturas de «la votación en curso».

Y el dato que lo cierra, que es más fuerte que la lista de permisos:
**`VotacionesInicioCtrl` manda a un Alumno o un Acudiente a
`panel.actividades.votaciones.votar`, y a admin o profesor a `.config`.** El
alumno tiene un destino y uno solo, y ese destino llama a dos endpoints.

### 18.3 Y aun así, eso se lee: hay que votar

Comprobar catorce puertas cerradas y siete abiertas **no es lo mismo que votar**,
y darlo por bueno leyendo el front es justo el error que este archivo lleva
evitando desde la P1 — mirar el 403 y no el resultado. Así que
`SuperficieDeUnAlumnoTest` monta ahora **una elección de verdad** —votación en
acción, un cargo, un candidato, y el grupo del alumno en `vt_participantes`— y
recorre el camino real de `VotarCtrl`:

1. `GET votaciones/en-accion-inscrito`, que es de donde el panel saca las
   aspiraciones y, dentro de cada una, **sus candidatos**. No de
   `candidatos/conaspiraciones`, que es la pantalla de prueba.
2. `POST votos/store` con el `candidato_id` que salió de ahí — y se comprueba la
   fila en `vt_votos`, no el código: un 201 no prueba que el voto se guardara.

Se comprobó al revés de las dos maneras posibles de dejar sin elecciones a
dieciséis colegios: poniéndole `auth.personal` a `votos/store` y poniéndoselo a
`votaciones/en-accion-inscrito`. El test falla con cada una.

De paso fijó un detalle que se habría escrito mal de memoria: **`votos/store`
responde 201**, no 200, porque devuelve el modelo recién creado.

### 18.4 Y el candado del §17 hizo lo que tenía que hacer

Al cerrar el módulo, las tres del flujo de votar se convirtieron en «la que se
quedó sola» de su familia y **el test falló el mismo día que se escribió el
arreglo**. Es exactamente para lo que está: no dice que estén mal, dice que hay
que decidir. Van a la lista de excepciones con su motivo, y la de `votos/store`
lleva escrito lo que hay que saber — *si esto lleva guard, no hay elecciones*.

### 18.5 La papeleta lleva rota para las familias desde siempre

`GET candidatos/conaspiraciones` hace, en la rama de Alumno y Acudiente:

```php
$votacion = VtVotacion::actualInscrito($user);
```

**y ese método no existe.** Los que tiene `VtVotacion` son `actual`,
`actualInAction` y `actualesInscrito`, en plural. Un alumno que abra la papeleta
recibe un 500, y lo ha recibido siempre.

No lo encontró el muestreo de la P2 ([§8](#8-lo-que-encontró-golpear-las-rutas-20-ago-2026-p2-de-tests)),
y el motivo es el de toda esta serie con una variación más: **es una lectura sin
parámetros, así que sí se golpeó — pero con un token del personal**, y el `else`
del personal llama a un método que sí existe. La herramienta preguntaba bien, con
un solo tipo de usuario.

**Se deja rota**, con la regla de siempre: arreglarlo es decidir qué votación es
«la suya» cuando hay varias en curso, y de paso encender para los alumnos una
pantalla que hoy no funciona en dieciséis colegios. Está en la tabla del
[09 §5](09-pendientes.md) y su test fija el 500 exacto, para que el día que se
arregle falle y haya que venir aquí.

Y una que ya estaba y ahora tiene guard: `votaciones/unsignedsusers` responde 500
desde antes de la migración ([§8](#8-lo-que-encontró-golpear-las-rutas-20-ago-2026-p2-de-tests)),
porque `vt_participantes` no tiene `user_id`. El guard es para el día que se
arregle — lo que pretende devolver es el directorio de cuentas del colegio con su
correo y su `is_superuser`.

---

## 19. Las dos familias que quedaban sin guard (20 ago 2026)

El snapshot `guard-por-familia` de la [§17](#17-la-hermana-que-se-quedó-sin-el-guard-20-ago-2026)
decía que doce familias no tenían ningún guard, y que nueve de las doce estaban
bien. **Dos de esas nueve no lo estaban**, y las dos las había visto el barrido
sin poder decir lo que pasaba dentro.

### 19.1 Un alumno importando alumnos

`POST api/importar/algo/{year}` es el importador **vivo** —los otros tres de su
familia están rotos con la firma de maatwebsite 2.x, [§8](#8-lo-que-encontró-golpear-las-rutas-20-ago-2026-p2-de-tests)—
y no llevaba ningún guard. Medido con token de alumno y de acudiente antes de
cerrarlo:

```
POST importar/algo -> 200
  ESCRITURAS: {"importaciones":39,"alumnos":37,"matriculas":37,
               "debugging":37,"acudientes":44,"parentescos":44}
  fila:       {"estado":"completada","filas":37,"created_by":2375}
```

**La importación se ejecutó entera, y la fila quedó a nombre del alumno.** Es la
escritura más grande que ha alcanzado un token de familia en toda esta serie.

Que el número de alumnos no cambiara es mérito de la **idempotencia por
documento** que se hizo en [09 §1](09-pendientes.md), no del guard: la hoja que
se subió era un export de los que ya estaban. Una hoja editada crea y modifica lo
que diga — que es precisamente lo que hace un importador.

**Por qué el barrido no podía verlo**, que es la parte que hay que recordar:
`postAlgo` empieza con `if (Request::hasFile('file'))`, y el barrido no manda
archivos. Es el **tercer sabor del mismo límite**, y conviene tenerlos juntos:

1. el cuerpo vacío, que escondió `promovidos/calcular-grupo` y media cartera
   ([§17](#17-la-hermana-que-se-quedó-sin-el-guard-20-ago-2026));
2. el `xlsx` de respuesta, cuyos bytes su detector de datos personales no lee
   —`cartera/exportar-solo-deudores`—;
3. y ahora el archivo de **entrada**, que no sabe mandar.

Las tres son la misma frase con el sujeto cambiado: **el barrido mide lo que sabe
construir.**

### 19.2 El `UPDATE` que no mira el token ni una vez

`GET api/folios/iniciar` no llama a `fromToken()`. Hace:

```sql
UPDATE matriculas m
  INNER JOIN grupos g ON g.id=m.grupo_id AND (m.nro_folio is null OR m.nro_folio="")
    AND g.year_id=? and g.deleted_at is null
  INNER JOIN years y ON y.id=g.year_id and y.deleted_at is null
SET m.nro_folio=CONCAT(y.year,"-", m.alumno_id);
```

o sea que numera de golpe todas las matrículas del año actual que no tengan
folio. **Esta sí la enseñaba el barrido** —salió como `ESCRIBE: update matriculas`
desde la primera pasada de la §15— y se dejó pasar porque en el seed **afecta a
cero filas**: todas las matrículas tienen folio. La consulta se ejecuta igual, así
que `DB::listen` la ve, y el resultado no distingue «escribió» de «no había nada
que escribir».

Es la cuarta vez que el seed vacío tapa un hallazgo, después de
`unidades_por_defecto`, los alumnos borrados y `pazysalvo`
([§16.4](#164-cuatro-papeleras-sin-guard-y-dos-que-mienten-en-el-nombre),
[§17.3](#173-la-cartera-que-no-miraba-el-token-ni-una-vez)). El candado de ésta
comprueba la puerta y no el efecto, y lo dice.

### 19.3 Lo que queda de esto

- Cinco rutas más con `auth.personal` —las cuatro de `importar` y `folios/iniciar`—
  y dos familias que pasan de no tener ningún guard a tenerlo entero.
- Dos casos nuevos en `SuperficieDeUnAlumnoTest`, comprobados al revés. El del
  importador mira **el efecto**: que no quede fila en `importaciones`, porque un
  403 que llegara después de la primera hoja ya habría escrito.
- Y el barrido baja de 16 rutas con algo dentro a **13**, que son las de siempre:
  lo suyo del alumno, la configuración del colegio, y las tres que esperan una
  decisión en la tabla del [09 §5](09-pendientes.md).

---

## 20. El cuerpo entero, y el examen de otro alumno (20 ago 2026)

La [§18](#18-el-cuerpo-lleno-y-el-módulo-de-votaciones-20-ago-2026) puso al
barrido a mandar cuerpo, con **veinte claves escritas a mano**. La pregunta que
no se hizo entonces es cuántas hay, y tiene respuesta: los controladores leen
**setenta y ocho** identificadores del cuerpo. Se contaron así, que es la
medición entera:

```bash
grep -rhoE "(Request::(input|has)|request\(\)->input)\(\s*'[a-zA-Z0-9_]+'" app/Http/Controllers/ \
  | grep -oE "'[a-zA-Z0-9_]+'" | tr -d "'" | sort -u | grep -E '(^id$|_id$|_ids$)'
```

Y con eso cae la frase que la §18 dejó escrita —«no hay forma estática de saber
qué claves lee un controlador»—, que **no es cierta**: no es exacta, porque una
clave construida en una variable se escapa, pero encuentra la que alguien añada
escribiéndola, que es como se añaden. Así que el barrido tiene ahora, por el lado
del cuerpo, el mismo candado que ya tenía por el de la URL: un `assertSame` que
falla cuando un controlador lee un identificador que el mapa no manda.

`CLAVES_DE_CUERPO` declara qué clase de cosa nombra cada una —alumno, grupo,
imagen, matrícula…— y el valor sale de la misma consulta que el mapa de la URL.
Las que no hace falta acertar van a `otro`, un id que existe y no es suyo: lo que
se mide es si la ruta llega a actuar sobre algo ajeno, no sobre qué.

**`tipo` no se manda, y es deliberado.** `ExigirPersonaPropia` comprueba que el
`tipo` declarado sea el del token, así que mandarlo provocaría 403 en rutas que
sin él pasan, y el barrido mediría menos creyendo que mide más.

### 20.1 Un alumno respondiendo el examen de otro

Con el cuerpo entero, `mis-actividades/seleccionar-opcion` pasó de aparecer como
una escritura propia a aparecer **borrando y reventando**. Mirado de cerca, y
montando el examen que el seed no tiene:

| Ruta | Qué hacía |
|---|---|
| `PUT mis-actividades/seleccionar-opcion` | Recibe `actividad_resuelta_id` por el cuerpo y no mira de quién es: **borra la respuesta de otro alumno y escribe la suya** —el método hace `DELETE` y luego `INSERT`— |
| `PUT mis-actividades/finalizar-actividad` | Le pone `terminado = 1` al intento de otro: **le cierra el examen en mitad de la prueba** |

**Ninguna de las dos puede llevar `auth.personal`**: responder y terminar un
examen es justo lo que hace un alumno. Y `persona.propia` tampoco sirve, porque
el identificador que viaja —`actividad_resuelta_id`— nombra **un intento y no una
persona**, y el guard recoge los identificadores por su nombre. Es la misma forma
del punto ciego de la [§13.2](#13-lo-que-encontró-subir-larastan-al-nivel-5-20-ago-2026),
vista desde el otro lado: allí el guard estaba puesto y no reconocía el nombre;
aquí no hay nombre que reconocer.

Así que la comprobación va **dentro del controlador**, contra `persona_id`, que
es lo que guarda la tabla y lo que ya usaba `putMiActividad()` para buscarla. Un
acudiente no tiene intentos, así que le cierra las dos, y es lo correcto: ver el
examen de su acudido es una cosa y responderlo por él es otra.

### 20.2 Y la tercera, que no la llama nadie y además está rota

`PUT mis-actividades/guardar` sobrescribe la actividad entera —descripción,
duración, oportunidades, si está en acción—: es la operación del profesor
duplicada en el controlador del alumno. **No la llama ningún cliente**, y no hay
que deducirlo: lo dice el comentario del propio `MisActividadesApi.ts`, y la que
usa el profesor es `actividades/guardar`.

Además está rota: escribe `puntaje_por_promedio`, que no es una columna de
`ws_actividades`. Es el quinto endpoint de la familia de la
[§8](#8-lo-que-encontró-golpear-las-rutas-20-ago-2026-p2-de-tests) —SQL contra una
columna que no existe— y responde 500 siempre. Lleva `auth.personal` para el día
que se arregle.

### 20.3 Lo que queda de esto

- El candado de cobertura del cuerpo, comprobado al revés quitándole
  `matricula_id` al mapa: el barrido lo señala.
- Dos casos nuevos en `SuperficieDeUnAlumnoTest` que montan el examen que el seed
  no tiene, y que comprueban **el efecto**: que la respuesta del otro siga ahí y
  que su intento siga abierto. Más su contrario —que el alumno sí responde y
  termina el suyo—, porque la mitad de esto es no romper el examen.
- **Y la quinta vez que el seed vacío tapa un hallazgo**: `ws_actividades` está
  vacía, así que el barrido golpeaba estas dos con un `actividad_resuelta_id` que
  no existía. Van ya `unidades_por_defecto`, los alumnos borrados, `pazysalvo`,
  los folios y las actividades. **El patrón está claro y merece una decisión que
  no es de esta sesión:** el seed copia un grupo y sus datos, y todo lo que un
  colegio acumula alrededor —papeleras, deudas, exámenes, plantillas— llega
  vacío. Mientras siga así, un `[]` de este seed no distingue «cerrado» de «no
  había nada».

---

## 21. El `{id}`, que es el mismo nombre para cuarenta y tres tablas (20 ago 2026)

La [§20](#20-el-cuerpo-entero-y-el-examen-de-otro-alumno-20-ago-2026) cerró el
lado del cuerpo, así que la pregunta siguiente es qué queda mal medido por el
lado de la URL. Y quedaba esto: **85 de las 538 rutas llevan `{id}`, y el barrido
les mandaba a las ochenta y cinco el mismo número** —el `users.id` del
superusuario—, porque el mapa de identificadores resuelve por nombre de parámetro
y `{id}` es un nombre solo.

Contra `perfiles/*` era el número correcto. Contra las otras setenta y tantas era
un id de otra tabla: `areas/destroy/{id}` nombra una fila de `areas`,
`matriculas/destroy/{id}` una de `matriculas`, `unidades/restore/{id}` una de
`unidades`. Y un id de otra tabla o no existe, o existe y no es la fila que la
ruta pretende tocar.

**Por qué eso no es un detalle:** un 404 por «esa fila no está» se lee igual que
un guard que funciona. Eran ochenta y cinco rutas —casi todas `DELETE` y `PUT`,
que es lo que hace daño— cuya respuesta no probaba nada, y el barrido las contaba
como medidas. Es el mismo error de lectura que la §16 encontró en los grupos y la
§17 en el cuerpo vacío, en el sitio que quedaba.

### 21.1 Qué se hizo

`TABLA_DE_ID` dice qué tabla nombra el `{id}` de cada familia de rutas. El nombre
de la familia sale de la URL y la tabla de lo que hace el controlador, **y no
siempre coinciden**: `boletines2/destroy/{id}`, `boletines3/destroy/{id}` y las
tres de `editnota` operan sobre **alumnos**; `definitivas_periodos` borra de
`notas_finales`; `certificados` es `config_certificados`.

`AJENO_POR` dice cómo se elige una fila ajena, y solo aparecen las siete tablas
donde «suyo» significa algo —`alumnos`, `users`, `grupos`, `profesores`,
`acudientes`, `images` por su `user_id` y `matriculas` por su `alumno_id`—. En
las demás cualquier fila sirve: `areas`, `frases` o `ciudades` son del colegio y
no de nadie, que es el mismo criterio del comodín `otro` del mapa del cuerpo.

**Y la papelera se detecta leyendo el método, no el nombre de la ruta**, porque
los nombres mienten. `years/destroy/{id}` hace `forceDelete()` sobre
`onlyTrashed()` —es el borrado de mayor alcance del sistema, el que arrastra 59
tablas por las FK— y `years/delete/{id}` es el que manda a la papelera. Con el
nombre por criterio, la de verdad peligrosa se habría golpeado con un año vivo y
habría contestado 404 sin haber medido nada. Se mira si el cuerpo del método
contiene `onlyTrashed`, por reflexión, que es el mismo atajo estático que la §20
usó para las claves del cuerpo.

Con eso el barrido tiene su **tercer** candado contra encogerse en silencio: si
mañana aparece una familia de rutas con `{id}` que el mapa no conoce, falla en
vez de golpearla con un cero. Ya lo tenía por el lado de los parámetros de la URL
y por el de las claves del cuerpo.

### 21.2 Lo que salió: pedir que borren la foto de otro

Con el `{id}` resuelto contra `images`, `DELETE myimages/destroy/{id}` dejó de
recibir un `users.id` y pasó a recibir **la imagen de otra persona**. Y siguió
escribiendo.

Borrar una imagen se pide por dos rutas, y son la misma operación:

| Ruta | Guard |
|---|---|
| `DELETE images-users/destroy/{id}` | `persona.propia:imagen_id` — cerrada, y con sus dos tests desde la §13.1 |
| `DELETE myimages/destroy/{id}` | ninguno, y **es la que usan las familias**: se llama «mis imágenes» |

Lo que hace con una imagen ajena no es borrarla —eso habría salido antes—: **es
pedir que la borren.** El controlador solo mira `created_by`, que es quién la
subió, y nunca `user_id`, que es de quién es. Así que con una imagen que no es
suya cae en la rama de la petición de cambio y deja el id ajeno escrito en
`change_asked_data.image_to_delete_id`. Desde ahí, `putAceptarAlumno` con
`tipo=img_delete` ejecuta `eliminar_imagen_y_enlaces()` sobre ella: **la foto de
otro a un clic de quien revisa peticiones**, que es exactamente el trabajo de
quien revisa peticiones.

Y alcanza a más que a las fotos de las personas. `images.user_id` es nullable
porque las imágenes del colegio no son de nadie —el logo del año, la firma de un
profesor—, así que también se podía pedir que borraran ésas.

**El arreglo no va dentro del controlador**, al contrario que el de la §20.1:
allí el identificador nombraba un intento y el guard no tenía cómo reconocerlo;
aquí nombra una imagen, y `ExigirPersonaPropia` ya sabe de quién es una imagen
—`duenoDeLaImagen()`, y una sin dueño es del colegio—. Es una línea, la misma que
lleva su ruta hermana: `persona.propia:imagen_id`, con el parámetro `$como` que
existe justo para decir a qué tabla apunta un `{id}` genérico.

**Y no le quita nada a nadie.** `myimages` le devuelve a una familia solo las
imágenes con su `user_id`, así que en su galería no hay ninguna que el guard
pueda cortar; a profesores y administrativos el guard no les aplica. Lo que sí
tenía que seguir vivo es el caso legítimo —la foto oficial la sube la secretaría,
o sea que es del alumno pero la subió otro, y pedir que la borren es justo para
lo que está esa rama—, y ése es el segundo de los dos tests.

### 21.3 Y lo que sigue sin medirse, que ahora al menos se dice

Las otras setenta y dos aguantaron. Eso no es un hallazgo, pero **antes no estaba
medido y ahora sí**: casi todas llevan `auth.personal` o comprueban dentro, y el
403 llega antes de mirar la fila.

Lo que no aguanta es el seed: **trece rutas no se podían medir**, y ocho de ellas
por la misma razón —son operaciones de papelera y en este seed no hay ni un
alumno, ni un grupo, ni un usuario borrado, porque el seed copia un grupo y sus
datos y las papeleras se quedan fuera—.

### 21.4 Las ocho de papelera: prestar una fila y devolverla

**Preparar el sujeto no es fabricar el efecto**, y ésa es la línea. Lo que se mide
en `alumnos/restore/{id}` sigue siendo si el token restaura la fila de OTRO;
marcarla como borrada antes de golpear es lo mismo que ya se hace al elegir a
quién se le da el token. Lo que no se hace —y eso sí volvería turbia la medida— es
montar la fila que la ruta escribiría.

Así que el barrido presta una fila ajena a la papelera para la petición y la
devuelve después. Dos detalles que no son opcionales:

- **Se devuelve en cuanto se golpea la ruta, no al final.** El seed tiene dos
  grupos y uno es el del sujeto, así que el único grupo ajeno es el mismo
  `{grupo_id}` que usan otras treinta y seis rutas. Dejarlo borrado hasta el
  final las mediría todas contra un grupo que ya no está.
- **Lo que escribió la petición se captura antes de devolver.** El
  `UPDATE ... deleted_at = NULL` de la vuelta es del barrido y no de la ruta;
  contado como suyo, aparecería como un hallazgo en cada una de las ocho.

Y un `assertSame` más: que no quede nada prestado al terminar. Una fila que se
quedara en la papelera mediría a partir de ahí todas las rutas que la usan contra
algo que no está, y el barrido diría que están cerradas.

Con eso son **doce las rutas con `{id}` que aguantan sin haber sido nunca
medidas**, y las ocho de papelera pasan a medirse. Ninguna de las ocho dio nada:
llevan `auth.personal` o comprueban dentro, y el 403 llega antes de mirar la fila.

### 21.5 Y las cinco que quedan, que no son un descuido del seed

`enfermeria/destroy`, `requisitos/destroy`, `actividades/destroy`,
`preguntas/destroy` y `opciones/destroy` nombran tablas vacías. Y no lo están
solo en el seed: **están vacías en la base de desarrollo**, las once de la familia
—`registros_enfermeria`, `requisitos_matricula`, `requisitos_alumno` y las ocho
`ws_*`—. O sea que el generador del seed no puede traerlas, porque no hay nada
que copiar; meterlas es **fabricarlas**, que es otra decisión y no la misma.

Lo que cuesta, medido y no supuesto: solo **dos snapshots** tocan esa familia
—`muestreo-actividades-compartidas` y `muestreo-actividades-datos`—, y los dos
están ya en `huecos-del-seed.json` como listas vacías conocidas. `enfermeria` y
`requisitos` no aparecen en ningún snapshot. Y la forma de un examen no habría que
inventarla: `montarElExamenDeOtroAlumno()` la escribió la §20 y funciona.

Lo que **no** cuesta poco es el contrato del generador, que hoy es «una rebanada
de la base real, determinista a partir del id». Fabricar rompe esa frase, y por
eso queda anotado y no hecho. Con el matiz que decide si merece la pena: las cinco
llevan `auth.personal` o comprueban dentro, así que medirlas no va a encontrar
nada — **compra honestidad de la medición, no hallazgos**. Es exactamente lo
contrario que las ocho de papelera, que salieron gratis.

---

## 22. El control: «vacío» no es «cerrado» (20 ago 2026)

Las §14 a §21 se apoyan todas en la misma lectura —una ruta que no escribe ni
devuelve datos personales está defendida— y **esa lectura es falsa la mitad de las
veces**. Un silencio puede ser el guard, o puede ser que los identificadores que se
le mandaron no nombren nada. Desde fuera se ven idénticos, y de ahí salieron los
seis hallazgos que el seed vacío tapó: `folios/iniciar` pasó cuatro pasadas
escribiendo sobre cero filas.

Así que las mudas se repiten con un token de **superusuario**, que no tiene guard
que lo pare, con los mismos identificadores y el mismo cuerpo. Si tampoco saca
nada, el silencio de la primera pasada **no prueba nada**.

Cada control va en su propio savepoint y se deshace: son escrituras de verdad
hechas por quien sí puede hacerlas —`years/destroy` fuerza el borrado de un año y
arrastra 59 tablas por las FK— y sin deshacerlas la pasada se destruiría a sí
misma a mitad de camino.

### 22.1 El número

**59 de 106.** Más de la mitad de los silencios del barrido no significaban nada.
Y no son rutas cerradas con 403: ésas ni siquiera entran en el recuento, porque un
403 sí es un juicio. Son rutas por las que **un token de alumno pasa** y sobre las
que el barrido no tenía nada que decir.

Tres causas, y las tres estaban nombradas por separado sin saber que eran la
misma:

- **El desajuste de año.** El sujeto trabaja en 2025 y **el único grupo ajeno que
  existe es de 2024**, porque el seed tiene dos grupos y uno es el suyo. Cualquier
  ruta que cruce grupo y año contesta vacío por eso. O sea que las 36 rutas que la
  [§16](#16-el-acudiente-y-lo-que-el-barrido-no-puede-ver-20-ago-2026) dio por
  cerradas al elegir un sujeto con un solo grupo **pueden no haberse medido
  nunca**: el grupo quedó libre, pero del año que no era.
- **Las tablas vacías**, que es la [§21.5](#215-y-las-cinco-que-quedan-que-no-son-un-descuido-del-seed).
- **El cuerpo que no casa.** La §18 lo dejó escrito —«una ruta que lea DOS recibe
  una combinación que puede no casar»— y aquí tiene número.

De las 59, unas quince son límites ya nombrados y aceptados: las diez rutas
públicas de pre-login, que no dependen del token, y las cinco que esperan un
archivo ([§19](#19-las-dos-familias-que-quedaban-sin-guard-20-ago-2026)). Las
otras cuarenta y pico son las que no se sabían.

### 22.2 Lo que salió del primer vistazo a esas cuarenta

Veintidós de ellas son escrituras **sin guard ninguno más allá de `auth.token`**.
Casi todas comprueban dentro del controlador —el `(Profesor && profes_can_edit_alumnos)
|| is_superuser` que se repite trece veces en `MatriculasController`—, y tres no
comprobaban nada. Dos de esas tres eran agujeros:

**`POST api/perfiles/store` no crea un perfil: crea un grupo.** Un método que se
quedó del copiar y pegar, con el año sacado del token, así que el grupo nace en el
año en curso del colegio. Medido: los grupos pasan de 2 a 3 con un token de
alumno, y responde 201.

`PerfilesApi.ts` ya llevaba anotado que **cinco** métodos de ese controlador operan
sobre grupo y no sobre persona —`show`, `destroy`, `forcedelete`, `restore`,
`trashed`—. `store` es la sexta y la única que escribe; las otras cuatro con `{id}`
ya llevaban `auth.personal` y ésta se quedó fuera. Es la §17 otra vez.

**Por qué no salió en cuatro pasadas del barrido:** lee
`Request::input('titular')['id']`, un array anidado, y el barrido manda
`titular_id` plano. El índice sobre `null` lanza, el `catch` de al lado lo
convierte en 422, y desde fuera se ve una ruta que no hace nada.

**`PUT api/publicaciones/guardar-edicion` reescribe cualquier publicación.**
`UPDATE publicaciones ... WHERE id=:id` con el `id` del cuerpo y sin mirar de quién
es. No solo el texto: también `para_todos`, `para_alumnos`, `para_acudientes`,
`para_profes` y `para_administradores`, o sea **a quién se le enseña**. Medido: un
alumno reescribió el anuncio de bienvenida del colegio.

Y es la §17 por segunda vez en la misma pasada: `putDelete` y `putRestaurar` ya
llevaban `exigeQueLaPublicacionSeaSuya()` desde que se cerró el módulo, y la
edición se quedó fuera — **porque nombra la publicación `id` y no `publi_id`**,
que es el mismo tropiezo de los nombres de la §15.

**Por qué tampoco salió:** sin `publi_para` en el cuerpo, `$para_todos` se queda
sin asignar y la petición muere en 500 antes del `UPDATE`. El barrido lo leía como
una ruta que no escribe. Rota por fuera, viva en cuanto le llega el cuerpo que
espera — igual que `folios/iniciar`.

El arreglo de las dos es el que ya existía al lado: `auth.personal` en la ruta del
grupo, y el ayudante de publicaciones en la edición. Con sus contrarios, que aquí
importan más que de costumbre: el personal sigue creando grupos por esa ruta y el
autor sigue editando la suya, que es lo que hace el lápiz del front
—`ng-if="$ctrl.USER.persona_id==$ctrl.publicacion_actual.persona_id"`—.

### 22.3 Y la tercera, que se queda rota a propósito

`PUT api/publicaciones/borrar-comentario` tiene esto:

```php
if ($user->is_superuser || $user.persona_id==comentario.persona_id) {
```

Eso es **sintaxis de JavaScript en PHP**. El `.` es concatenación, `persona_id` es
una constante que no existe y `comentario` una variable que tampoco, así que la
rama derecha lanza en cuanto se evalúa. Con el `||` en corto, el resultado es que
**un superusuario borra cualquier comentario y todos los demás reciben un 500**,
incluido el autor borrando el suyo.

No es un agujero: es un botón que no funciona para nadie. Y arreglarlo **enciende**
en los dieciséis colegios una función que hoy no existe, que es una decisión y no
un arreglo — la misma forma que `candidatos/conaspiraciones` de la
[§18.4](#18-el-cuerpo-lleno-y-el-módulo-de-votaciones-20-ago-2026). Se queda
documentado y con su ruta, según la regla.

### 22.4 Lo que este control no garantiza

El superusuario tiene su propio año de contexto, así que una ruta puede salir muda
para él por lo mismo que para el alumno. **El error va hacia el lado seguro**: dice
«no puedo juzgarla» de alguna que quizá sí estaba medida, nunca «cerrada» de una
que no lo está. Que es la dirección correcta para una herramienta cuyo trabajo es
no mentir sobre lo que ha mirado.

---

## 23. El cuerpo anidado, y las cuatro que escondía (20 ago 2026)

El control de la [§22](#22-el-control-vacío-no-es-cerrado-20-ago-2026) dejó 57
rutas marcadas como no juzgables. Lo primero fue afinar ese número, porque estaba
inflado: **el bucle salta los 403 por ser la respuesta correcta, pero este legacy
rechaza con 400** —`pueden_modificar_definitivas()` sin ir más lejos—, y también
con 401 y con 422. Un 4xx es un juicio igual que el 403: la ruta miró y dijo que
no. Descontadas ésas, **57 pasan a 14**, y 47 de las mudas resultaron ser rechazos
con el código equivocado. El código es cosa aparte y no se toca: la regla del
proyecto es que el legacy se queda como está.

Con catorce se puede mirar una por una. Y mirándolas salió la causa común.

### 23.1 La cuarta forma del mismo límite

El barrido manda `titular_id`, `acudiente_id` y `grupo_actual` como **números
planos**, y esos controladores hacen `Request::input('titular')['id']`,
`$acu['nombres']` y `$grupo_actual['id']`. El índice sobre un `int` o sobre `null`
lanza, la ruta responde 500 o 422, y desde fuera se ve una que no hace nada.

Es la cuarta vez que aparece el mismo límite con otra cara —el cuerpo vacío
(§17), el `xlsx` que no sabe leer (§17), el archivo que no sabe mandar (§19)— y la
que más ha escondido. Medido con las dos formas de leer una clave como array:

```bash
# directa
grep -rhoE "Request::input\('[a-zA-Z0-9_]+'\)\[" app/Http/Controllers/
# en dos pasos: $v = Request::input('x'); ... $v['y']
```

Salen **veintiséis claves**, y los campos que se indexan dentro son `id` en 53
sitios, `profesor_id` en cinco y sueltos `sangre`, `estado_civil`, `username`,
`password` y `parentesco`.

**No se sustituyen las planas: se golpea con las dos formas.** La misma clave se
lee de las dos maneras en sitios distintos —`grupo_actual` lo indexa
`acudientes/datos` y lo usa plano `cartera/alumnos`—, así que elegir una deja la
otra sin medir. Son dos peticiones por ruta y el barrido tarda diez segundos.

Y el **cuarto candado**, que se ganó el sueldo al escribirlo: señaló
`encabezado_img_id` y `piepagina_img_id`, que `ConfigCertificadosController` lee
como objeto **aunque se llamen `_id`**. El nombre no dice la forma, que es
exactamente por lo que este mapa se comprueba contra el código y no se escribe a
ojo.

### 23.2 Las cuatro que salieron

Todas son **la §17 otra vez** —la hermana que se quedó sin el guard—, y las cuatro
estaban invisibles por lo mismo.

| Ruta | Qué hacía | Sus hermanas |
|---|---|---|
| `POST perfiles/store` | **No crea un perfil: crea un grupo.** Un alumno creaba uno del colegio en el año en curso; medido, de 2 a 3, con un 201 | las otras cuatro del controlador que operan sobre grupo ya llevaban `auth.personal` |
| `PUT publicaciones/guardar-edicion` | Reescribe cualquier publicación por su `id`, y no solo el texto: también **a quién se le enseña** | `putDelete` y `putRestaurar` ya llevaban `exigeQueLaPublicacionSeaSuya()` |
| `POST acudientes/crear-usuario` | Crea un `User` de tipo Acudiente con `Hash::make('123456')` y **reapunta `acudientes.user_id`** a la cuenta nueva | ninguna: solo lo llaman pantallas de personal |
| `PUT acudientes/datos` | Los acudientes del grupo que le nombren, con documento, teléfono, email y dirección. **La consulta filtra por grupo y no por año**, así que vale cualquiera | las otras seis rutas `*/datos` llevan `auth.personal` o `persona.propia` |
| `PUT matriculas/alumnos-grado-anterior` | El grupo entero con `fecha_nac`, `celular`, `direccion` y `religion` — 24 KB | `matriculas/alumnos-con-grado-anterior` y **las dos de `prematriculas`** lo llevan |

La de `crear-usuario` es la más seria de las cinco y merece decirlo entero: la
cuenta nace con una contraseña que conoce quien la pidió, y si ese acudiente ya
tenía una, **el `UPDATE` la deja fuera**. Un acudiente ve lo completo de sus
acudidos.

### 23.3 Lo que esto dice del candado de la §17

`matriculas/alumnos-grado-anterior` es el caso que el candado de la
[§17](#17-la-hermana-que-se-quedó-sin-el-guard-20-ago-2026) **no puede ver**, y
conviene tenerlo escrito. Ese candado comprueba que no quede una sola ruta sin
guard en su familia, y la familia `matriculas` tiene muchas con él: la que falta
no está sola. Pero sí estaba sola entre sus **hermanas de operación** —el mismo
nombre de método en cuatro controladores, tres con guard—.

Son dos preguntas distintas y las dos hacen falta. **La segunda ya tiene candado**
—`test_ninguna_ruta_se_queda_sola_entre_sus_hermanas_de_operacion`—, y agrupa por
`Controlador@metodo` en vez de por prefijo de URL.

Al escribirlo salió un detalle que merece quedar: **el umbral no puede ser el
mismo que el de familia.** Allí hacen falta dos hermanas con guard porque
compartir prefijo es una relación floja —`matriculas/*` son treinta rutas que no
se parecen en nada—. Aquí basta **una**, porque compartir nombre de método es una
relación fuerte: en este proyecto significa que la operación está copiada y pegada
en dos controladores. Y hacía falta bajarlo: `putAlumnosGradoAnterior` existe
exactamente dos veces, así que con el umbral de familia el caso que motivó el
candado **se le habría escapado**. Comprobado quitándole el guard a la ruta: con
el umbral en dos, pasa; con el umbral en uno, falla y la nombra.

Bajarlo cuesta dos entradas más en la lista de excepciones —`piars-alumnos/field`
y `publicaciones/restaurar`, las dos defendidas dentro—, y las quince que ya
había son casi todas lo mismo: controladores que abortan con 400 o 401 en vez de
con 403. Cada una lleva su motivo escrito, y el test inverso grita si alguna deja
de hacer falta.

---

## 24. Las once que quedaron sin juzgar (20 ago 2026)

La [§23](#23-el-cuerpo-anidado-y-las-cuatro-que-escond%C3%ADa-20-ago-2026) dejó
once rutas que ni el token de familia ni el superusuario del control mueven. Con
once se pueden mirar una por una, y eso es lo que se hizo. Nueve ya tenían sitio:

| Cuántas | Cuáles | Por qué están ahí |
|---|---|---|
| 1 | `login/crear-prematricula` | pública de pre-login, no depende del token |
| 2 | `ciudades/by-departamento`, `asignaturas/listasignaturas-alone` | esperan una decisión, ya anotadas en [08](08-revision-idor.md) y en 09 §5 |
| 2 | `definitivas_periodos`, `editnota/detailed-notas-year` | rotas desde antes de la migración, con su entrada en la tabla de variables sin definir |
| 1 | `publicaciones/store` | es el diseño: el muro no restringe quién publica y cada uno borra lo suyo |
| 3 | `votaciones`, `votaciones/actual`, `votaciones/actual-in-action` | el flujo de votar que la §18 dejó abierto a propósito |

Y quedaban dos de actividades, mudas porque **`ws_actividades` está vacía en el
seed**. Ésas se midieron montando la actividad, que es la regla que quedó escrita
al partir la decisión del seed: si falta el estado, lo prepara quien mide; si falta
la fila, la monta el test que la necesita.

### 24.1 La pantalla de corregir del profesor

`PUT respuestas/actividad` recibe `actividad_id` por el cuerpo y devuelve, por
cada grupo al que se compartió la actividad, **todos sus alumnos con lo que
contestaron**: nombres, foto, si terminaron, `puntaje_manual` y la respuesta a
cada pregunta. Sin guard.

Lo que lo hace claro es de dónde se llega a esa pantalla. `panel.respuestas` tiene
exactamente dos entradas en el front y las dos son del autor: la lista de
actividades del profesor (`actividades.html`) y su botón «Ver resultados»
(`EditarActividadCtrl::verResultados`). Y `actividades/datos`, que es la que abre
esa lista, lleva `auth.personal` desde siempre.

**Y el comentario de la ruta decía lo contrario.** En `routes/api/actividades.php`
estaba escrito que «el lado del alumno es `mis-actividades/*` y
`respuestas/actividad`», que es de donde salió que se quedara abierta. Corregido
junto con el guard: el lado del alumno es `mis-actividades/*` y nada más.

### 24.2 Y la otra rama del mismo método, que se queda rota

Para una actividad **no compartida** —que son la mayoría, porque `compartida`
viene a 0 por defecto— el `else` hace:

```php
$consulta = '';
$alumnos = DB::select($consulta, [$user->year_id]);
```

Una consulta vacía no es SQL. O sea que un profesor que abra «Ver resultados» de
cualquier actividad de un solo grupo recibe **500 desde que existe la pantalla**.

Es la familia de la [§8](#8-lo-que-encontró-golpear-las-rutas-20-ago-2026-p2-de-tests)
y se queda, según la regla: tiene ruta, y qué debe enseñar esa pantalla para una
actividad sin compartir es una decisión del colegio y no un arreglo. El test fija
el error exacto para que el día que se arregle se note.

### 24.3 Lo que deja la pasada

Diez rutas sin juzgar, y **las diez con nombre y motivo**. Ninguna es un agujero
sin mirar: son públicas, decisiones pendientes, rotas conocidas o el flujo de
votar. Es el punto en el que la serie deja de encontrar por barrido — lo que queda
son decisiones, no hallazgos.

---

## 25. Las seis rutas que no autentican por token (20 ago 2026)

La [§24](#24-las-once-que-quedaron-sin-juzgar-20-ago-2026) cerró el barrido
diciendo que lo que quedaba eran decisiones y no hallazgos. Era cierto **para lo
que el barrido puede ver**, y el barrido golpea con un token. Estas seis rutas no
usan token.

Salieron de otra pregunta, la de la cobertura: `tools/cobertura-de-rutas.py` sobre
la corrida del 20 de agosto da **261 de 539 rutas con la respuesta comprobada
(48%)** y, dentro de eso, **cinco controladores con cero**. Dos de los cinco son
`TLoginController` y `TSubirController`, que son Tardanzas.

Tardanzas es el lector montado en la puerta del colegio. No usa token: manda
usuario y contraseña **en el cuerpo de cada petición** y el controlador las
comprueba con `Support\Credenciales`. Por eso sus seis rutas llevan
`withoutMiddleware('auth.token')` sin ser públicas, y por eso quedan fuera de las
tres redes que teníamos: el barrido golpea con token, `AutorizacionTest` mira
guards, y `RutasPreLoginTest` enumera las públicas. **La única defensa de estas
seis era lo que dijera su propio código, y nadie lo había mirado.**

### 25.1 El respaldo que TSubir había quitado y TLogin conservaba

`TSubirController::user()` lleva escrito desde la Fase 3 por qué se le quitaron
dos respaldos, y el segundo con nombre y todo: comparaba la columna `password`
**contra la contraseña en claro** y, si acertaba, la hasheaba en su sitio y dejaba
entrar. Era el camino de subida para las cuentas guardadas sin hashear.

`TLoginController` tenía el mismo bloque **copiado tres veces**, con los dos
respaldos intactos. O sea que la decisión estaba tomada, escrita y aplicada a la
mitad del módulo. Aquí solo se termina de aplicar: los tres bloques pasan a un
`usuarioAutenticado()` privado, como el de al lado.

No es teórico y tampoco es de hoy: quien tuviera una fila con la columna en texto
plano entraba al lector escribiendo ese texto. El seed no tiene ninguna —las
2.351 filas están hasheadas— así que el test **escribe una a propósito** dentro de
su transacción, que es la única forma de comprobar que la puerta ya no está.

### 25.2 Y lo que dejaba salir: las ausencias del colegio entero

Los tres métodos de `TLoginController` verificaban la contraseña y **no miraban de
quién era**. Que eso no se notara es un accidente de dos de ellos:

| Método | Con credenciales de un alumno | Por qué |
|---|---|---|
| `tardanzas/login` | 500 | su `switch ($userTemp->tipo)` no tiene rama para Alumno ni Acudiente, así que `$usuario` sigue vacío y `$usuario[0]` revienta con «Undefined array key 0» |
| `tardanzas/login/traer-datos` | 500 | el mismo `switch` |
| `tardanzas/login/traer-datos-ausencias` | **200** | **este no tiene `switch`**: su consulta es una sola y no depende del tipo |

El tercero devolvía, a cualquier alumno con su propia clave y sin token, **todas
las ausencias y tardanzas del colegio**: en la base de desarrollo, **801 filas de
51 alumnos distintos**. Y no eran «las de su año»: el `year_id` sale del cuerpo si
viene, así que se piden las de cualquiera. Un acudiente, lo mismo.

Es quién llegó tarde y cuántas veces, de todo el colegio, con solo la cuenta que
el propio colegio le da a cada alumno. Rompe de frente la regla que no se
re-litiga: *un alumno solo ve lo suyo*.

**Y es el cuarto punto ciego de la misma familia**, después de los buscadores de
la §11.3, el inventario de [08 §4](08-revision-idor.md) y el `{id}` de la §13. Los
cuatro se resumen igual: *la cerradura estaba y la pregunta era otra*. Aquí la
pregunta era «¿y las rutas que no tienen cerradura porque abren con otra llave?».

### 25.3 Cómo se cerró, y las dos cosas que no se copiaron

`usuarioAutenticado()` admite **Profesor y Usuario**, que son exactamente los dos
que el `switch` de los otros dos métodos ya sabía servir. Así no cambia nada de lo
que hoy funciona en los dieciséis colegios: lo único que se mueve es que Alumno y
Acudiente reciben 403 donde recibían 500 en dos rutas y 200 en la tercera.

Dos cosas de `TSubirController` **no** se copiaron, y las dos a propósito:

- **Su `|| $userTemp->is_superuser`.** Escrito entero, ese `if` exige ser Profesor
  *o* superusuario, y deja fuera a un Usuario administrativo que no lo sea. Para
  escribir es lo que hay hoy y no se toca; copiarlo aquí cerraría la lectura a
  gente que hoy entra. **Queda una pregunta para Joseth**, en 09 §5.
- **Su `abort(400, 'No tienes permiso')`.** El candado nuevo devuelve **403**,
  que es la regla para código nuevo. El 400 de TSubir se queda: es una respuesta
  que el lector ya recibe hoy, y cambiarla se ve desde los dieciséis colegios sin
  arreglar nada.

### 25.4 Lo que sale de aquí y no se arregla

- ~~**`tardanzas/login` y `traer-datos` devuelven el hash bcrypt del usuario**~~
  — **quitado el 21 ago 2026**, decidido por Joseth. Estaba en los cuatro `SELECT`
  del `switch` y salía en la clave `password`. Esta sección lo había dejado por
  miedo a apagar el lector —es un aparato que trabaja sin red, y validar contra el
  hash guardado es justo lo que necesitaría estando desconectado—, y ese miedo se
  contestó yendo a mirar el cliente en vez de razonándolo: **no usa el hash**,
  guarda la contraseña en claro y compara contra ella. Fijado por
  `TardanzasTest::test_el_hash_no_sale_en_la_respuesta`.

  Y el test se equivocó primero, que es lo que vale la pena de esto. Buscaba el
  hash **en el texto** de la respuesta y pasaba con el hash puesto: en JSON las
  barras salen escapadas (`$2y$10$a\/b`), así que un bcrypt nunca aparece literal
  en el cuerpo. Se vio al comprobarlo al revés —desactivar el arreglo y exigir que
  el test falle—, que es la costumbre que lo cazó. Ahora compara contra la
  respuesta **decodificada**, no contra sus bytes.
- **`Credenciales::verificar` no filtra `deleted_at`** —está escrito y decidido en
  su propio docblock—, así que un usuario borrado sigue entrando al lector. Medido
  al pasar: borrar al profesor y entrar sigue dando 200. No cambia con este
  arreglo, y ahora que el candado de tipo existe expone menos.
- **`(array)$userTemp['attributes']` no fusiona nada.** Está en los tres métodos:
  `$modelo['attributes']` pide un atributo llamado así, no el array interno, y
  devuelve `null`. La respuesta es solo las columnas del `SELECT`. Se deja igual
  que los tres de la §12: la línea que sobra es la única pista de lo que se
  pretendía, y quitarla no cambia ni un byte de la respuesta.

### 25.5 Lo que deja la pasada

Que la serie dejara de encontrar **por barrido** no era que dejara de haber qué
encontrar: era que la herramienta había agotado su pregunta. La siguiente pregunta
la hizo la cobertura, y su respuesta —«cinco controladores donde nadie mira ninguna
respuesta»— apuntó a las dos únicas familias de la API que se autentican de otra
manera. Quedan tres de esos cinco por mirar: `CambiarUsuarios`, `Opciones` y
`Uniformes`.

---

## 26. Sin `clave`, el colegio entero con la contraseña vacía (20 ago 2026)

Tercero de los cinco controladores mudos de la cobertura, y el que más lejos
llega. `CambiarUsuariosController` son cuatro rutas de `routes/api/admin.php`:
dos ponen el número de documento como nombre de usuario de todos los alumnos —o
de todos los acudientes— y las otras dos les ponen la misma contraseña a todos.

```php
$password = Hash::make(Request::input('clave'));
DB::update('UPDATE users SET password=:texto WHERE tipo="Alumno";', [':texto' => $password]);
```

**`Hash::make(null)` no falla: devuelve el hash de la cadena vacía.** Una llamada
sin `clave` responde 200 y deja a **los 1.280 alumnos** de la base de desarrollo
con esa contraseña. Y no se queda ahí: medido en el mismo test,
`POST api/login/credentials` con la contraseña vacía responde **200**.

Con la ruta hermana puesta antes —el nombre de usuario pasa a ser el número de
documento— las dos juntas dejan todas las cuentas de alumno del colegio abiertas
a quien tenga una lista de documentos, que es un papel que circula.

**Quién las llama, corregido.** Esta sección dijo primero que no las llamaba
ningún cliente. Era falso y el error fue de método: se buscó en
`myvc_front/www/js`, que es la ruta del front **viejo**, y el actual vive en
`myvc_front/app`. Rehecha la búsqueda, `CambiarUsuariosApi.ts` llama a las cuatro
desde el botón «Cambiar claves y usuarios» de la pantalla de alumnos —con su
`confirm()` de «¿Seguro que quiere cambiar la contraseña a TODOS los alumnos?»—.
La contraseña sale de un `<input>` sin `required`, así que el campo vacío llega
igual: el 422 es exactamente el caso que pasa en la vida real, no uno de
laboratorio.

La lección es de las que se repiten: **una búsqueda que no encuentra nada no
prueba nada hasta comprobar que estaba mirando donde hay algo.** Las demás
búsquedas de esta serie se rehicieron contra la ruta buena.

Se exige `clave` no vacía, con **422**. No se exige nada más —longitud, forma—
porque eso es una política del colegio y no se inventa aquí. Fijado en
`OperacionesMasivasTest`, que comprueba las dos mitades: que sin clave no cambia
**ninguna** fila, y que con clave las cambia todas.

### 26.1 Y quién puede dispararlas, que sí tenía respuesta

Las cuatro llevaban `auth.personal` a secas, así que **cualquiera de los 51
profesores del colegio podía reiniciar la contraseña de los 1.280 alumnos**. Eso
parece «qué puede hacer el personal entre sí», que Joseth dejó fuera a propósito
([08 §1](08-revision-idor.md)) — y la primera versión de esta sección lo dejó ahí
diciendo que no había dónde apoyarse porque ninguno de los cuatro middlewares es
de superusuario.

**Era falso, y se vio al leer `YearsController` para otra cosa.** El sitio donde
apoyarse existe y está decidido: `App\Support\Autoriza`, escrito en esta misma
migración para los `forceDelete`, con tres criterios y un 403, y con el porqué en
su cabecera: *«el criterio estaba copiado a mano en unos controladores y ausente
en otros… con la regla en un solo sitio no puede volver a divergir»*. Reiniciar la
cuenta de todo el colegio es la misma clase de operación que vaciar la papelera de
un año: de colegio, no de aula. Las cuatro piden ya `Autoriza::esAdministrativo`.

**Lo que salió de aplicarlo, y vale para los cinco sitios que ya lo usaban:**
`esAdministrativo` es `is_superuser || Role::isSecretario()`, y **en la base no
existe ningún rol llamado `Secretario`**. Los once son Alumno (1.280), Acudiente
(999), Profesor (51), **Admin (10)**, Psicólogo, Enfermero, Coord disciplinario,
Manager, Asistente, Coord académico y Rector. O sea que hoy la condición vale
exactamente `is_superuser`, y el rol que existe y tiene gente dentro se llama
`Admin`. Si ése es el nombre correcto, se cambia una línea y arregla los seis
sitios a la vez — que es justo para lo que la regla está en uno solo. Anotado en
09 §5.

El test no copia el criterio: se lo pregunta a `Autoriza`. Escribirlo como
«superusuario sí, profesor no» pasaría por la razón equivocada el día que el
colegio cree el rol que falta.

**Y el riesgo de cerrar de más resultó no existir**, comprobado antes de cerrar y
no después. La duda era dejar fuera a quien hoy usa el botón: el menú del front
(`sidebarMenu.html`) enseña la pantalla de alumnos con
`hasRoleOrPerm(['admin', 'secretario'])`, o sea que la puerta de entrada **ya
exigía lo mismo**. Y los dos conjuntos coinciden fila por fila:

```
superusuarios: [1,2,3,687,688,706,1217,1218,1495,1503]
rol Admin:     [1,2,3,687,688,706,1217,1218,1495,1503]
```

Diez y diez, los mismos diez. O sea que `is_superuser` y el rol `Admin` son en
esta base la misma cosa escrita dos veces, y nadie que hoy vea el botón pierde el
botón. Eso además responde a medias la pregunta de arriba: si `esAdministrativo`
mira `Admin` en vez de `Secretario`, hoy no cambia nada — y mañana sí, en cuanto
alguien tenga uno sin el otro.

---

## 27. El interruptor del periodo lo elige el cliente (20 ago 2026)

Salió de mirar el cuarto controlador mudo, `UniformesController`, y no es de
uniformes: es de notas.

`User::pueden_editar_notas()` y `User::pueden_modificar_definitivas()` son el
interruptor con el que el colegio cierra un periodo a los profesores
(`periodos.profes_pueden_editar_notas` y `profes_pueden_nivelar`). Entre las dos
las llaman **26 veces desde 7 controladores**: `DefinitivasPeriodos` (7),
`Subunidades` (5), `Uniformes` (4), `Unidades` (3), `Ausencias` (3), `Notas` (2) y
`FrasesAsignatura` (2).

Las dos deciden así:

```php
$num_periodo = (int)Request::input('num_periodo');
if ($num_periodo) { /* Todo bien */ } else { $num_periodo = (int)$user->numero_periodo; }

for (...) if ($periodos[$i]->numero == $num_periodo) {
    $user->profes_pueden_editar_notas = $periodos[$i]->profes_pueden_editar_notas;
}
```

**La bandera que se consulta es la del periodo que nombra el cuerpo de la
petición, y la escritura va a otro sitio.** Medido de punta a punta con un
profesor del seed:

| Estado | Petición | Resultado |
|---|---|---|
| los cuatro periodos del año cerrados | `PUT uniformes/agregar` | **400**, y no se escribe nada — el interruptor funciona |
| los cuatro cerrados, y luego el periodo 1 abierto | `PUT uniformes/agregar` con `num_periodo=1` | **200**, y la fila se escribe con `periodo_id` = el del profesor, **que sigue cerrado** |

O sea: el interruptor se abre nombrando el periodo de al lado. Y no hace falta
que ninguno esté abierto de verdad — basta con que lo esté uno cualquiera de los
del año.

### 27.1 Por qué no se arregló la noche que se encontró

Porque `num_periodo` **no es un parámetro parásito, es la declaración del periodo
que se está editando** — y en las pantallas que más importan es la única que hay.
Medido en los tres clientes (contra `myvc_front/app`, que es donde vive el front
actual; la primera versión de esta sección buscó en `www/js`, que es el viejo, y
por eso dijo que los otros dos clientes no lo mandaban nunca):

| Quién | Qué manda como `num_periodo` |
|---|---|
| `myvc_flutter`, unidades y subunidades | el periodo que edita, junto a `periodo_id`, los dos iguales |
| `DefinitivasPeriodosCtrl.js` | **el periodo de la columna que se toca** en la rejilla de las cuatro, sin `periodo_id` |
| `PromocionarNotasCtrl.js` | el periodo **de destino** de la promoción |
| `NotasPerdidasProfesorEditCtrl.js` | el de la nota que se corrige |
| `UnidadesCtrl.js`, `UnidadesProfesorCtrl.js`, `InformesCtrl.js` | el del usuario, `USER.numero_periodo` |

O sea que la rejilla de definitivas —donde un usuario edita los cuatro periodos
en la misma pantalla— **depende** de poder nombrar un periodo distinto del suyo.
Ahí el diseño es correcto: se consulta la bandera del periodo que se edita. Lo que
falta es lo único que lo haría un candado: **nada comprueba que la declaración
coincida con la fila que se escribe**.

El candado correcto se dice en una frase —*la bandera del periodo al que escribe
esta petición*— y esa frase se resuelve en un sitio distinto en cada una de las 26
llamadas: de `periodo_id` del cuerpo en uniformes, de la unidad en
`unidades/update/{id}`, de la nota en `notas/update/{id}`, de
`notas_finales.periodo_id` en `definitivas_periodos/*`. Son 26 consultas nuevas
dentro del cálculo de notas, que es lo que el [§5 del plan](00-plan-migracion.md)
protege y lo que la [§4 de 09](09-pendientes.md) tiene parado por decisión.

Las formas de cerrarlo, con lo que cuesta cada una:

1. **Exigir que `num_periodo` y `periodo_id` concuerden** cuando vienen los dos.
   Cierra uniformes y las cuatro de Flutter, es media hora, y **no cierra las que
   más pesan**: la rejilla de definitivas manda `nf_id` y `num_periodo` sin
   `periodo_id`, y `notas/update/{id}` tampoco lo manda.
2. **Derivar el periodo de la fila que se toca**, las 26. Es el arreglo de verdad.
3. ~~**Ignorar `num_periodo`** y usar siempre el del usuario.~~ **Descartada al
   medir el front:** apagaría la rejilla de definitivas para los tres periodos que
   no son el del usuario, que es justo para lo que esa pantalla existe. Es el
   ejemplo de por qué la búsqueda se rehízo: con la ruta equivocada esta opción
   parecía la barata.

### 27.1.1 Arreglado — **la 2, el 21 ago 2026**

Joseth eligió derivar de la fila. La regla vive ahora en
`App\Support\PeriodoDeLaFila`, un método por cada forma que tiene una llamada de
saber a qué fila le toca, y `User::pueden_editar_notas()` y
`pueden_modificar_definitivas()` reciben ese periodo como segundo parámetro.

Las cadenas que hacían falta, que es lo que convertía esto en trabajo de verdad:

| De qué se escribe | Cómo se llega al periodo |
|---|---|
| uniforme, ausencia, frase de asignatura, nota final | la columna `periodo_id` de su propia fila |
| unidad | `unidades.periodo_id` |
| **subunidad** | no lo lleva: cuelga de la unidad |
| **nota** | no lo lleva: cuelga de la subunidad, que cuelga de la unidad |

**Y la §27.1 se equivocaba en un número: no son 26 derivables, son 24.**
`recuperacion_final` **no tiene `periodo_id`** —sus columnas son alumno,
asignatura, `year` y nota, o sea que se guarda por AÑO—, así que sus dos llamadas
no tienen fila de la que sacar un periodo. La tercera que no deriva es
`uniformes/guardar-cambios`, que está rota desde antes de la migración y cuyo
`UPDATE` apunta a una variable que no existe: no hay fila porque no hay fila.

**Las dos de la recuperación se cerraron por el otro lado**, el mismo día y con su
propia decisión de Joseth. Si lo que se toca es del año, el permiso se pide para el
año: **tienen que estar abiertos los cuatro periodos**. Como el candado cruza la
lista con AND, pasarle los periodos del año dice exactamente eso.

Se midió antes de preguntar, y el dato cambió la pregunta: `DefinitivasPeriodosCtrl`
manda `{rf_id, nota}` y **nunca manda `num_periodo`** en esas dos, así que el hueco
estaba abierto y no lo usaba ningún cliente. Cerrarlo no apaga ninguna pantalla.

La otra cara quedó dicha al elegir: **si el colegio deja cerrado el periodo 1 y
abre el 4, la recuperación final no se puede tocar.** Es lo elegido a sabiendas, no
un efecto lateral, y por eso está escrito aquí y en `PeriodoDeLaFila::todosLosDelAnio()`.

**25 de 26 comprueban ahora el permiso del sitio al que escriben**; la que falta es
la ruta rota.

Tres decisiones que hubo que tomar dentro y que no estaban en el enunciado:

- **Varias filas de golpe se cruzan con AND.** `subunidades/update-orden` reordena
  una lista entera y `update-orden-varias` mueve subunidades entre dos unidades,
  que pueden ser de periodos distintos. Basta que una esté cerrada para que no
  pase ninguna: **escribir la mitad de un reordenado deja el orden inconsistente**,
  que es peor que no escribir nada.
- **Un periodo borrado debajo de una fila cuenta como cerrado**, no como «no se
  pudo derivar». No se inventa permiso.
- **`num_periodo` sigue mandando donde declarar y escribir son la misma cosa.** El
  `else` de `definitivas_periodos/update` **inserta** una fila nueva con el
  `periodo_id` sacado de ese mismo `num_periodo`; ahí comprobar el declarado sí es
  comprobar el escrito, y tratarlo como los demás habría apagado la creación de
  definitivas.

**Los tests que hubo que tocar son la mejor prueba de que el arreglo hace algo.**
Dos de `NotasTest` empezaron a dar 400, y tenían razón: abrían el periodo **del
profesor** y editaban una nota que colgaba de una unidad de un periodo **cerrado**.
Llevaban así desde que se escribieron. Ahora abren el de la fila.

Y `UniformesTest::test_el_periodo_que_se_comprueba_lo_elige_el_cliente` —el que
afirmaba el fallo a propósito y decía «el día que se arregle, este test falla, y
ese es su trabajo»— falló, y se le dio la vuelta. Al lado quedó su simétrico:
**nombrar el periodo abierto y escribir en él sigue pasando**, que es lo que
impide que el arreglo sea un candado tonto y apague la rejilla de definitivas.

Lo demás está en `PeriodoDeLaFilaTest`, con los cinco comprobados al revés. Uno de
ellos pasaba también con el arreglo desactivado y hubo que reescribirlo: **un test
que pasa de las dos maneras no comprueba nada**, y solo se ve mirándolo.

### 27.2 Y lo demás que se midió al pasar

- **`uniformes/guardar-cambios` sigue rota**, con su `// No la estoy usando
  actualmente` encima y sus cuatro variables sin definir. Ya estaba en la §6.5 y
  en la §7 con su `count` en `phpstan.neon`; ahora tiene además el test que fija
  el 500.
- **`opciones/guardar` usaba `find()` donde sus tres hermanas usan `findOrFail()`**:
  una opción que no existe llegaba a `null->definicion` y daba 500 en vez de 404.
  Arreglado, que es la misma decisión de los doce `abort()` de la §12 y no una
  nueva. `ws_actividades` está vacía en el seed, así que `OpcionesTest` monta la
  actividad, la pregunta y las dos opciones — es la sexta vez que hace falta.
- **`opciones/add-opcion` responde 201, no 200.** Laravel lo pone solo cuando la
  respuesta es un modelo Eloquent recién creado. No es un cambio: es lo que
  reciben los clientes hoy, y ahora está escrito.

### 27.3 Los cinco mudos, cerrados

Los cinco controladores que la cobertura dio con cero respuestas comprobadas ya
no lo están: `TLogin` y `TSubir` en la §25, `CambiarUsuarios` en la §26, y
`Uniformes` y `Opciones` aquí. **Ninguno de los cinco estaba mudo por casualidad**
—dos autentican por el cuerpo, uno no lo llama ningún cliente, y dos necesitan
filas que el seed no trae—, y de los cinco salieron cuatro cosas que arreglar.
Que no los mirara nadie y que no los mirara ninguna herramienta era la misma
frase dicha dos veces.

### 27.4 El candado es por (año, periodo), no por número de periodo (21 ago 2026)

Lo dijo Joseth al terminar el arreglo, y es la frase que le faltaba a toda esta
sección: **cada año tiene sus propios periodos con su propio interruptor.** El
periodo 1 del año pasado puede estar bloqueado y el 1 de este año abierto. O sea
que «el periodo 1» no identifica nada: el par (año, periodo) sí.

Se fue a comprobar si el arreglo del 21 ago aguantaba esa frase, y **aguanta por
donde importa**: `aplicarBanderasDelPeriodo()` lee el periodo **por id**
—`WHERE id IN (...)`, sin filtrar año—, y el id lo saca `PeriodoDeLaFila` de la
propia fila que se escribe. Un id de periodo ya lleva el año dentro. Los dos
sitios que sí traducen un número a un periodo —`porNumero()` y el camino viejo de
`num_periodo`— lo hacen **dentro de `$user->year_id`**, que es lo correcto porque
los dos existen para filas que se están creando en el año en curso.

Al comprobarlo aparecieron dos preguntas que nadie había hecho, y las dos se le
hicieron con lo medido delante. **Las dos se contestaron «se queda como está»**, y
por eso lo que hay aquí no es un arreglo sino dos tests.

#### La primera: escribir en un año que ya no es el actual

Hoy, si el colegio dejó abierto el periodo 1 de 2024, un profesor puede seguir
corrigiendo unidades, notas y definitivas de 2024 aunque el colegio esté en 2025
—porque el candado mira la fila, y la fila dice «periodo 1 de 2024, abierto»—.
La alternativa era exigir además `years.actual`.

**Joseth: manda solo el interruptor.** Un colegio cierra un año pasado bloqueando
sus periodos, que es la herramienta que ya tiene; si dejó uno abierto es porque
quiere poder corregir ahí. Añadir `years.actual` habría apagado las correcciones
de enero en los dieciséis colegios.

Y por eso hay test, que es lo único que hacía falta escribir en código:
`test_un_periodo_abierto_de_un_ano_pasado_deja_escribir_...` en
`PeriodoDeLaFilaTest`. **Es exactamente el «arreglo» que una sesión futura haría
sin preguntar** —un `years.actual` al lado del interruptor parece prudencia—, y
ahora falla si alguien lo intenta. Comprobado al revés poniéndolo de verdad: cae
**uno solo**, y es ése; su simétrico —el mismo periodo del año pasado cerrado no
deja— sigue en rojo como debe.

#### La segunda: la nivelación de otro año

`recuperacion_final` **no tiene `periodo_id`** —guarda alumno, asignatura, `year`
y nota— y por eso su permiso se pide con `todosLosDelAnio($user)`, los periodos
del año **del usuario**. Con un `rf_id` de otro año, el candado que se comprueba
no es el de esa fila: **con 2024 cerrado y 2025 abierto, se toca 2024.** Es el
único sitio donde la frase de arriba no se cumple, y es de esquema: el `year` de
esa tabla es el **número** (2024), no el id, así que llegar a sus periodos exige
pasar por `years`.

**Joseth: se queda como está.** El front manda `{rf_id, nota}` desde la pantalla
del año en curso —medido en la §27.1.1—, así que ninguna pantalla llega aquí con
un `rf_id` viejo. El hueco existe y no lo usa nadie.

Queda fijado por `test_la_nivelacion_de_otro_ano_la_gobierna_el_ano_en_curso`, en
la forma que ya usó `UniformesTest` antes de la §27.1.1: **el día que se decida
cerrarlo, ese test falla, y ése es su trabajo** — obliga a venir a leer por qué se
dejó abierto antes de cambiarlo.

#### Lo que se vio al pasar y no se tocó

`unidades/update/{id}` **no comprueba de quién es la unidad**: el único candado
que tiene es el del periodo, así que un profesor puede reescribir la definición y
el porcentaje de una unidad de la asignatura de otro profesor con solo saber su
id. Es la misma forma que la §16.6 y no se toca aquí porque no es la pregunta que
se estaba contestando; va a la lista de la cobertura.

---

## 28. Cuál es el año actual del colegio (20 ago 2026)

`YearsController` era el hueco más grande de la cobertura —**4 de 19 rutas
comprobadas**— y de él cuelga lo que decide en qué año trabaja todo el mundo:
`Services\Login` mete a cada usuario en el periodo `actual` del año `actual` en
cada inicio de sesión. Mirar las quince que faltaban dio tres cosas, y las tres
son la misma frase escrita en tres sitios: **`actual = 1` sin condición**.

### 28.1 Destildar la casilla encendía el año

```php
$actual = (bool) Request::input('can');
if ($actual) { Year::where('actual', true)->update(['actual'=>false]); }
$year = Year::findOrFail($year_id);
$year->actual = 1;                       // <- pasara lo que pasara
if ($actual) { return 'Ahora es año actual.'; } else { return 'Ahora NO es año actual';}
```

La respuesta dice una cosa y la fila queda con la contraria. El front
(`colegio/years.html`) es **una casilla por año**, con `ng-false-value="0"`: quien
la apaga manda `can=0`, recibe «Ahora NO es año actual» en verde, y el año queda
encendido — y como el `update` que apaga a los demás solo corre cuando `can` es
verdadero, queda encendido **además** del que ya lo estaba.

### 28.2 Y crear un año, lo mismo

`postStore` guarda `actual` con lo que venga, apaga a los demás **solo si venía
verdadero**, y después hace `$year->actual = 1` igual. Crear un año pidiendo
`actual: false` lo encendía sin apagar a nadie. El front manda siempre
`actual: true` (`YearsCtrl.fixControles`), así que arreglarlo no cambia la
pantalla; cierra el segundo camino.

### 28.3 La papelera, que es donde estaba la prueba

En la base hay un año con `actual=1` **y `deleted_at` puesto**: el 2026, borrado
el 24 de junio de 2025. Hoy no se ve, y merece la pena por qué no: todo lo que lee
el año actual filtra `deleted_at`, así que para el sistema entero ese año no
existe. Y `putSetActual` apaga a los demás con Eloquent, que tampoco ve los
borrados — o sea que **ese año se libraba de todas las apagadas**.

La trampa es `years/restore/{id}`. Restaurarlo lo devuelve encendido al lado del
que lo esté, y con dos encendidos:

```php
$anios = DB::select('SELECT id, year, actual FROM years WHERE actual=1 and deleted_at is null');
$anio  = $anios[0];      // sin ORDER BY
```

**En qué año entra todo el colegio lo decidiría el orden en que MySQL devuelva las
filas.** No se toca ese `[0]` —cambiarlo es elegir por 16 colegios en qué año
amanecen, y eso es una decisión— sino la causa: un año que va a la papelera deja
de ser el actual. No cambia nada de lo que hoy calcula nadie, porque para todos
los lectores ese año ya no estaba; pone en la fila lo que todos ya deducían.

El test no comprueba la línea, comprueba la trampa: borra el año actual, pone otro
como actual, restaura el primero y exige que no haya dos.

**Y los datos de antes siguen ahí**, que es lo que este arreglo no toca: impide
que vuelva a pasar, no deshace lo que pasó. Joseth eligió el 21 ago 2026 recogerlo
con un comando en vez de con una consulta pegada dieciséis veces:

```bash
php artisan anios:actuales      # en cada colegio. No escribe nada.
```

Cuenta los años actuales, enseña además **los que están encendidos en la
papelera** —que no se ven desde ninguna pantalla y que `years/restore/{id}`
devuelve encendidos— y sale con código 1 si hay algo que mirar, para que se note
en un bucle sobre los dieciséis. **No apaga ninguno**: elegir en qué año amanece
un colegio no es de un script.

Corrido en la base de desarrollo el mismo día, encontró exactamente el caso de
esta sección: un año actual vivo (2025) y el 2026 encendido en la papelera desde
el 24 de junio de 2025.

**Y hay fecha para correrlo.** Al contestar esta pregunta, Joseth contó algo que
no estaba escrito en ninguna parte:

> Más o menos en **octubre se crea el año siguiente copiando todo del anterior**
> excepto el número del año. El año que elige el usuario rige la plataforma con
> sus configuraciones, **excepto en los informes, donde siempre salen el rector y
> el secretario del año actual** — para que se puedan firmar informes viejos
> cuando el rector de aquel año ya no trabaja en el colegio.

Las dos mitades importan. La primera pone fecha: **la copia de octubre es el
momento exacto en que un colegio con dos años actuales se lleva la ambigüedad al
año nuevo**. La segunda explica el `$actual=true` de `Year::datos()`, que hasta hoy
parecía un parámetro suelto: **es una regla de negocio**, y de las que un refactor
bienintencionado borra por parecer un descuido. Anotado también en `Year`.

### 28.4 Lo que se midió y estaba bien

- **`years/useractive/{id}` muda al usuario y no al colegio.** Es el par que el
  propio front avisa de no confundir (`YearsApi.ts`: «set-actual cambia el año DEL
  COLEGIO; useractive/{id} cambia el año en el que trabaja UN usuario»). Se
  comprueban las dos mitades: que el usuario cambia de periodo y que la lista de
  años actuales no se mueve. Y que un año sin periodos responde 400.
- **Los ocho conmutadores del boletín** guardan lo que dicen, comprobados
  encendiendo y apagando cada uno y leyendo su columna.
- **El borrado físico sigue siendo solo de superusuario**, que es el candado de
  59 tablas en cascada que se puso en la revisión de la papelera y que hasta ahora
  no tenía cerrojo que lo sostuviera.
- **`years/store` responde 200 y no 201**, aunque devuelva un modelo recién
  creado, y es un buen contraste con `opciones/add-opcion` de la §27, que responde
  201 haciendo lo mismo. Laravel decide mirando `wasRecentlyCreated`, y aquí el
  controlador vuelve a buscar el año con `Year::find()` antes de devolverlo: la
  instancia que sale ya no sabe que acaba de nacer. Los dos números están fijados,
  porque los dos son lo que reciben los clientes.

---

## 29. Un docente se hacía con la cuenta del superusuario (20 ago 2026)

Es lo más caro de la serie, y estaba en el segundo hueco de la cobertura:
`PerfilesController`, 12 de 22 rutas comprobadas.

`PUT api/perfiles/reset-password/{id}` es **la única ruta de la API que deja a una
persona poner la contraseña de otra sin conocer la anterior**. Su comprobación era:

```php
if (!$user->is_superuser){
    if(!($user->tipo == 'Profesor' && $user->profes_can_edit_alumnos)){
        abort(400, 'No tiene permisos para resetear password');
    }
}
$perfil->password = Hash::make((string)Request::input('password'));
$perfil->save();
return 'Password cambiado -> '.(string)Request::input('password');
```

Mira **quién pide** y no mira **a quién**. Con `years.profes_can_edit_alumnos`
encendida —que es una configuración normal del colegio, con su propio conmutador
en la pantalla de años— cualquier profesor podía escribir la contraseña de
cualquier cuenta. Medido: un profesor del seed contra el superusuario id 1
responde **200**, la cuenta queda tomada, y **la respuesta le devuelve la clave
que acaba de poner**.

Eso no es «qué puede hacer el personal entre sí», que es lo que Joseth dejó fuera
a propósito. Aquello es alcance horizontal; esto es subir de nivel: el profesor
entra después como superusuario y tiene el colegio entero.

### 29.1 El criterio ya estaba escrito, otra vez

`Autoriza::puedeBorrarAlumnos` dice exactamente lo que esa bandera significa:

```php
if (($user->tipo ?? '') === 'Profesor' && ($user->profes_can_edit_alumnos ?? false)) return true;
```

…y lo dice **para borrar alumnos**. La bandera se llama `profes_can_edit_alumnos`.
Aquí falta la mitad: el objetivo. Se añade el 403 sobre `$perfil->tipo === 'Alumno'`
—el superusuario sigue alcanzando a cualquiera— y con eso la ruta hace lo que su
propia bandera dice desde el principio.

Es la segunda vez esta noche que un candado nuevo resulta ser la aplicación de una
regla ya decidida y escrita, después de la §26.1. Y la tercera del proyecto, con el
respaldo en claro de la §25.1. **Cuando hace falta un criterio, primero se mira si
ya está escrito**: las tres veces lo estaba, en el fichero de al lado.

### 29.2 La contraseña vacía, en cuatro sitios

`Hash::make('')` no falla. Los cuatro sitios que escriben una contraseña la
escribían sin mirar:

| Ruta | Qué dejaba abierto |
|---|---|
| `cambiar-usuarios/poner-password-todos-alumnos` | los 1.280 alumnos |
| `cambiar-usuarios/poner-password-todos-acudientes` | los 999 acudientes |
| `perfiles/reset-password/{id}` | la persona que se elija |
| `perfiles/cambiarpassword/{id}` | la cuenta de quien la pide |

Y detrás, `login/credentials` con la contraseña vacía responde 200 — comprobado.

La regla se saca a **`App\Support\ClaveNueva`** y no se copia cuatro veces, por lo
mismo que `Autoriza` y `ColumnaSegura` existen: lo que está copiado a mano
diverge, y esto ya estaba divergido en la peor forma posible, que es ausente en
los cuatro. Solo exige que venga y que no esté vacía: **cuánto debe medir es una
política del colegio** y no se inventa aquí. El front pide cuatro caracteres en su
pantalla, y esa cifra es suya.

### 29.3 Y dos cosas más de la misma pasada

- **La contraseña ya no vuelve en el cuerpo.** Las dos rutas la devolvían —una con
  `'Password cambiado -> '.$clave` y la otra la clave a secas—. Ninguno de los dos
  sitios del front la lee: los dos enseñan un aviso fijo. Una contraseña en un
  cuerpo acaba en el registro de todo lo que haya en medio.
- **`perfiles/creartodoslosusuarios` pide ser administrativo.** Recorre alumnos,
  profesores y acudientes y crea la cuenta del que no la tenga; es la misma clase
  de operación que `cambiar-usuarios/*`. Su botón vive en la pantalla de usuarios,
  que el menú del front enseña solo con `hasRoleOrPerm('admin')`, así que el
  backend estaba dos escalones por debajo de su propia pantalla.

### 29.4 Lo que enseña el patrón

Las cuatro secciones de esta noche —§25, §26, §28 y §29— salieron de la misma
pregunta, que no es «¿tiene guard esta ruta?» sino **«¿alguien ha mirado alguna
vez qué responde?»**. `tools/cobertura-de-rutas.py` contesta esa, y las cinco
rutas más caras del año estaban en los dos huecos más grandes que señaló.

Y las tres del final se parecen demasiado para ser casualidad: `actual = 1` sin
condición, `Hash::make()` sin condición, y una comprobación de permiso sin
objetivo. **Las tres son una decisión que el código no llega a tomar.** Vale la
pena buscarlas con esa forma en la cabeza.

---

## 30. Fabricar un superusuario, que es más fácil que robar uno (20 ago 2026)

La §29.4 dejó escrita la forma que buscar: **una decisión que el código no llega a
tomar**. Buscarla con esa forma en la cabeza —`grep is_superuser` sobre los
controladores— dio esto en la primera pasada.

Cuatro sitios copian `is_superuser` del cuerpo de la petición:

```php
$usuario->is_superuser = Request::input('is_superuser', 0);
```

Están en `profesores/store`, en las dos ramas de `profesores/update/{id}` y en
`alumnos/store`. Las de `update` van dentro de un `if ($this->user->is_superuser)`
y por tanto estaban bien; las otras dos, no.

**`POST api/profesores/store` no tiene más candado que `auth.personal`.** O sea
que cualquiera de los 51 profesores mandaba un profesor nuevo con
`is_superuser: 1`, un `username` y una `password` suyos, y entraba con esa cuenta
como superusuario. Es más limpio que la §29: no hay que quitarle la cuenta a
nadie, se fabrica una.

Y la otra rama es peor de lo que parece. `alumnos/store` está detrás de «profesor
con `profes_can_edit_alumnos`», y un usuario de **tipo Alumno** con `is_superuser`
no es inofensivo aunque `auth.personal` lo pare en casi todas partes:
`perfiles/reset-password/{id}` **no lleva `auth.personal`**, y su primera línea es
`if (!$user->is_superuser)`. La cadena entera cabe en un renglón: profesor con la
bandera → alumno superusuario → contraseña de cualquiera. Y sobrevivía al arreglo
de la §29, porque aquel candado está dentro del `if` que un `is_superuser` se
salta.

### 30.1 El arreglo

La regla que faltaba se dice en cinco palabras: **un permiso no se concede a sí
mismo**. Va a `Autoriza`, con los otros:

```php
public static function concederSuperusuario($user, $pedido): int
{
    return (self::esSuperusuario($user) && $pedido) ? 1 : 0;
}
```

Devuelve `int` a propósito. `sanarInputUser()` hace
`Request::merge(['is_superuser' => false])` cuando el campo viene falsy, y ese
`false` de PHP en una columna `tinyint(1)` es la familia de la [§13](#13-lo-que-encontró-subir-larastan-al-nivel-5-20-ago-2026):
el mismo campo del mismo registro con dos tipos según por dónde se lea.

Cinco tests, y el último no mira las rutas sino la regla: si
`concederSuperusuario` devuelve 1 para un profesor, las cuatro vuelven a fabricar
superusuarios de golpe, y eso tiene que fallar en un sitio y no en cuatro.

### 30.2 «Secretario» es un permiso que nadie tiene, buscado en dos sitios distintos

De la misma pasada, y esto **no se arregla**: hace falta que lo decida el colegio.

El código pregunta por «Secretario» de dos maneras que no pueden ser las dos:

| Dónde | Cómo pregunta | Qué pasa hoy |
|---|---|---|
| `Autoriza::esAdministrativo` + 7 sitios de `AlumnosController` | `Role::isSecretario($user_id)` | la tabla `roles` tiene once nombres y **ninguno es `Secretario`** |
| `AcudientesController`, 3 sitios | `$this->user->tipo == 'Secretario'` | `users.tipo` solo toma cuatro valores —Usuario (20), Profesor (51), Alumno (1.280), Acudiente (1.000)—, así que **es siempre falso** |

Los cuatro valores de `tipo` no son una costumbre: son las cuatro ramas del
`switch` de `ContextoDeUsuario`. `Secretario` no puede estar ahí.

Así que hoy, en los once sitios, el criterio efectivo es `is_superuser` (más
`Profesor` donde lo lleve al lado). Y en `AcudientesController` eso significa que
**un administrativo sin `is_superuser` no puede crear ni editar acudientes**, que
es exactamente lo contrario de lo que la línea pretendía decir.

### Y no es solo el «Secretario»: el psicólogo, con la nota del autor al lado

En `AlumnosController::putGuardarValor` hay una cuarta rama:

```php
// Debo verificar que tenga rol Psicólogo. Por ahora lo dejo Usuario para que funcione
} else if($this->user->tipo == 'Psicólogo' && (Request::input('propiedad') == 'nee' || ...)){
```

**El comentario dice `Usuario` y el código dice `Psicólogo`.** Su autor sabía que
el criterio bueno era el rol y dejó escrito que mientras tanto lo abría al tipo
`Usuario`; lo que quedó escrito compara `tipo` con un valor que `tipo` no toma
nunca. O sea que la rama **no se ejecuta jamás**, y quien iba a usarla —el rol
`Psicólogo`, que existe y tiene **cuatro personas dentro**— cae al `else` con un
400.

`nee` y `nee_descripcion` son las necesidades educativas especiales del alumno.
Hoy solo las escribe un superusuario, por la rama de arriba. La rama que existía
para el psicólogo es la única de las doce que **quita** una función en vez de
dejar una puerta abierta, y es la que mejor explica el patrón: cuando el criterio
no está donde se busca, unas veces se abre de más y otras se cierra de más, y las
dos se ven igual desde fuera —que es como no verse—.

### 30.3 Contestado — **el 21 ago 2026**

La pregunta era una sola y valía para las doce: **quién es el Secretario** (y
quién el Psicólogo). Joseth contestó y **la respuesta no era ninguna de las
opciones que se le ofrecieron**, que es lo que hay que quedarse de aquí.

**El Secretario es un rol nuevo**, `Secretario`, que se le asigna a un usuario
**docente**. No es `Admin`, que era lo propuesto: la razón de existir del rol es
precisamente una secretaria docente **sin** `is_superuser`, y los diez `Admin`
son exactamente los diez `is_superuser`, así que con `Admin` el rol no
distinguiría a nadie. Lo crea `2026_08_21_100000_create_rol_secretario` y
`Role::isSecretario()` ya lo buscaba por ese nombre exacto, así que los once
sitios empezaron a funcionar con la fila.

**Y el alcance va por otro corte del que se le propuso.** Se le ofreció «alumnos,
matrículas, docencia e informes» y lo corrigió:

| Puede | No puede |
|---|---|
| Las **configuraciones del colegio**: materias y su orden, las asignaturas de **todos** los grupos, los titulares de grado | **Crear usuarios** |
| Alumnos y su edición, matrículas | |
| La **configuración del año**, y **bloquear periodos** | |
| Ver e imprimir **todos** los informes, no solo los de sus grupos | |
| Cambiar username y contraseña **de alumnos y acudientes** | |
| De unidades, subunidades y notas, **solo las suyas como docente** | Las de los demás docentes |

El Secretario **no** es «un docente con más cosas» ni «un superusuario con
menos»: administra la **estructura** del colegio y es docente normal en **su
propia aula**. Los dos ejes son independientes y confundirlos es lo que haría el
arreglo mal.

### 30.4 La regla que hizo falta inventarse para repartirlo

`Autoriza::esAdministrativo()` ya llamaba a `Role::isSecretario()` desde el 20 de
agosto, esperando esta respuesta. Colgaban de él **seis llamadas**, y crear el rol
se las habría dado todas de golpe. Dos de ellas no estaban en la lista de Joseth:

- **`perfiles/creartodoslosusuarios`**, que crea las cuentas de alumnos,
  profesores y acudientes. «No crea usuarios» fue textual.
- **Los tres `forcedelete`** —perfiles, grupos y profesores—, borrado físico en
  cascada de 20, 27 y 31 tablas. La [§28.4](#284-lo-que-se-midió-y-estaba-bien) ya
  había fijado que eso es de superusuario, y Joseth no lo nombró.

Las cuatro se anclaron a `esSuperusuario` el mismo día. La regla, que vale para la
próxima vez que se cree un rol:

> **Crear un rol no puede dar permisos que nadie pidió.** Todo lo que colgaba del
> criterio compartido y no estaba en la lista se ancla explícitamente a donde ya
> estaba de hecho.

Sin esa pasada, el rol habría llegado a los dieciséis colegios con el poder de
borrar profesores en cascada, y **nadie lo habría escrito en ninguna parte**:
habría sido un efecto de una fila en una tabla.

**Una lectura que conviene confirmar.** «No crea usuarios» y «puede crear
acudientes» se tocan: `acudientes/crear` crea también la **cuenta** del acudiente,
porque un acudiente sin usuario no puede entrar. Se ha entendido que «no crea
usuarios» se refiere a las cuentas del personal y a la creación masiva —que es lo
que hace `creartodoslosusuarios`—, y no a la cuenta que nace con cada acudiente,
porque desbloquear justo eso era el problema visible de la §30.2. Anotado para
que Joseth lo confirme o lo corrija.

### 30.5 El Psicólogo, que solo necesitaba que le preguntaran donde vive

El rol `Psicólogo` **sí existía** —id 11, desde enero de 2019, cuatro personas
dentro, se lo asigna `users/crear-psicologo` insertando el `role_id` a pelo— y no
gobernaba nada. Ahora abre `nee` y `nee_descripcion`, y nada más.

La decisión se tomó después de ir a mirar el PIAR, y **lo que se encontró allí
cambió la pregunta**: el PIAR filtra sus consultas por `a.nee=1`, así que solo ve
a los alumnos ya marcados. Con la rama muerta, el psicólogo podía trabajar el PIAR
pero **no meter a nadie en él**. Está en la [§35](#35-el-piar-al-que-entraba-cualquiera-21-ago-2026).

Fijado por `SecretarioTest`, con los tres tests que abren algo comprobados al
revés.

---

## 31. La tabla de al lado: los periodos (21 ago 2026)

La [§28](#28-cuál-es-el-año-actual-del-colegio-20-ago-2026) encontró en `Years`
tres veces la misma frase —`actual = 1` sin condición— y quedaba mirar si estaba
repetida en `Periodos`, que es la tabla de al lado y tiene la misma bandera.

**No lo está.** `putEstablecerActual` apaga a los hermanos del mismo año y
enciende el pedido, que es lo correcto, y `postStore` fija `actual = 0` a mano
aunque el cuerpo pida otra cosa. Los siete tests de `PeriodosTest` lo dejan fijado:
encender cada periodo de un año por turno y comprobar que queda **exactamente
uno**, y que los otros ocho años no se mueven.

Salieron otras dos cosas.

### 31.1 `periodos/update/{id}`: lo que la §9 no podía saber

**Ya estaba documentada** en la [§9](#9-lo-que-encontró-subir-larastan-al-nivel-2-20-ago-2026),
con su entrada en `phpstan.neon`: escribe `$periodo->year` y `periodos` no tiene
esa columna. Lo que aquella entrada dejó abierto era «qué manda el cliente en
`year`, el número o el id», sin cliente al que preguntar. Golpearla añade dos
cosas que no se ven leyendo:

- **Falla mandando `year` y falla sin mandarlo.** El atributo se asigna siempre,
  así que la fila siempre sale sucia y el `UPDATE` siempre nombra la columna que
  no existe. No hay cuerpo con el que funcione — la pregunta de la §9 no tiene
  respuesta buena porque el campo no llega a importar.
- **Y arreglarla enciende el fallo de la §28.** Su
  `$periodo->actual = Request::input('actual')` **no apaga a los hermanos**, así
  que la única forma de dejar dos periodos actuales en un año está detrás de este
  500. Repararla sin mirar eso abriría exactamente lo que la §28 acaba de cerrar
  en la tabla de al lado.

Sigue sin cliente —el front tiene un endpoint por campo, que es lo que se
construye cuando el de «guardar todo» no funciona— y ahora tiene test: el 500 con
y sin `year`, y la fila sin moverse.

### 31.2 Y el «primero sin ORDER BY», que aparece por tercera vez

`ContextoDeUsuario::para()`, para un usuario sin `periodo_id`:

```php
$userTemp->periodo_id = Periodo::where('actual', '=', true)->first()->id;
```

Sin filtrar por año y sin `ORDER BY`. Cada año tiene su periodo actual —nueve en
la base, uno por año, que es lo correcto— así que ese `first()` devuelve el del
**año más viejo**: medido, el periodo 4, de **2018**. La regla buena está escrita
tres ficheros más allá, en `Login::ponerEnElPeriodoActual`: el año actual primero,
su periodo actual después.

**No se toca, y por dos razones.** Afecta a 4 usuarios de 2.351 —los que tienen
`periodo_id` nulo— y el efecto dura hasta que entran, porque el propio `Login` los
mueve al periodo bueno. Y la línea está en el camino más caliente de la API, el
que resuelve el contexto de **cada petición**. Cambiarla por poco a cambio de nada
la misma noche en que se toca la autorización de seis controladores es exactamente
lo que no se hace.

Queda anotado con los otros dos de su familia —el `$anios[0]` de la [§28.3](#283-la-papelera-que-es-donde-estaba-la-prueba)
y el `$periodos[0]` del mismo `Login`—, porque los tres son la misma frase: **una
fila elegida entre varias sin decir cuál**. Y en la misma familia entra el
`periodo_id = 1` escrito a mano de `AcudientesController::postCrear`, que apunta al
mismo 2018 por el mismo motivo.

---

## 32. La última copia del criterio de alumnos (21 ago 2026)

`AlumnosController` era el mayor hueco de cobertura que quedaba —8 de 17— y
guardaba **siete copias a mano** de esto:

```php
($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos)
    || $this->user->is_superuser || Role::isSecretario($this->user->user_id)
```

Es exactamente la condición que `App\Support\Autoriza` existe para no volver a
tener repartida —su propia cabecera lo dice, y nombra a `alumnos/forcedelete` como
el sitio del que se copió—. Las demás pasaron por el helper en la revisión de la
papelera; ésta se quedó atrás porque era **el original**.

Se llevan las siete a `Autoriza`, sin cambiar la condición. Lo que gana no es
estética: la [§30.2](#302-secretario-es-un-permiso-que-nadie-tiene-buscado-en-dos-sitios-distintos)
deja pendiente **quién es el «Secretario»**, y con esto esa respuesta se escribe en
una línea en vez de en ocho.

**Y no se funden en un solo método**, aunque hoy digan lo mismo. Crear un alumno y
borrarlo definitivamente —20 tablas en cascada— comparten condición **por herencia,
no porque nadie haya decidido que deban compartirla**. Con dos nombres
—`puedeEditarAlumnos` y `puedeBorrarAlumnos`, uno delegando en el otro— se pueden
separar el día que se decida; con uno, hay que volver a repartirlas por siete
sitios.

### 32.1 Dos cosas que el test creyó fallos y eran suyas

Merece la pena porque las dos se pueden volver a creer:

- **«De otro grupo» no es «con matrícula en otro grupo».** Un alumno arrastra
  matrículas de varios años, así que casi todos están en el grupo *y* en otro. El
  primer test acusó a `alumnos/cambiar-claves` de cambiar la clave de quien no
  tocaba; el filtro bueno es *no tener ninguna matrícula en ese grupo*, y con él la
  consulta está bien.
- **`alumnos/destroy/{id}` no lleva `auth.personal`**, a diferencia de casi todas
  sus hermanas. Lo que la defiende es la condición de dentro, que responde **400**
  y no 403. No es un fallo —una familia no pasa— pero es una respuesta distinta
  para la misma pregunta según la ruta, y ahora está fijada.

---

## 33. Dos cosas que parecían fallos y no lo eran (21 ago 2026)

Cubrir `AsignaturasController` —5 de 14 rutas comprobadas— no dio ningún fallo, y
las dos veces que lo pareció fue por leer en vez de ejecutar. Se dejan escritas
porque las dos se pueden volver a creer.

### 33.1 Marcadores nombrados con ataduras posicionales

`putToggleDia` monta la consulta con `:valor`, `:modificador`, `:fecha` y
`:asignatura_id`, y le pasa un array **posicional**:

```php
DB::update($consulta, [$valor, $user->user_id, $now, $asignatura_id]);
```

Leído, eso es un `HY093 Invalid parameter number`. Ejecutado, funciona: PDO liga
por posición. Está fijado por test —los cinco días, encendiendo y apagando— para
que si alguna versión deja de tolerarlo se note aquí y no en el horario de un
colegio.

### 33.2 `ColumnaSegura` no limita a los días

La misma ruta se llama `toggle-dia` y su comprobación es
`ColumnaSegura::exigir('asignaturas', $dia)`, que acepta **cualquier columna que
exista en la tabla**. O sea que por ahí se escribe `profesor_id`, `materia_id` o
`creditos`.

**No es un agujero**: lleva `auth.personal`, y quien pasa ese guard ya puede
escribir esas columnas por `asignaturas/update/{id}`. Pero el nombre promete otra
cosa, y el día que alguien apoye un permiso en «esta ruta solo toca el horario» se
llevará una sorpresa. El test fija las dos mitades: que una columna real que no es
un día entra igual, y que un nombre que no es columna no llega a la consulta —que
es lo que `ColumnaSegura` sí hace, y hace bien—.

Y una tercera, ésta sí del comportamiento: **`asignaturas/copiar` llamado dos
veces duplica**. Inserta una fila por asignatura del origen sin mirar lo que ya hay
en el destino. Queda fijado, no arreglado: quien copia sobre un grupo que ya tiene
asignaturas está pidiendo algo que el endpoint no sabe resolver, y decidir qué
debería pasar —saltar, reemplazar, fallar— es del colegio.

---

## 34. El directorio del colegio, que se había quedado sin juzgar (21 ago 2026)

Se volvió a correr el barrido —`BARRIDO_TIPO=Alumno`— **después** de tocar la
autorización de seis controladores, para comprobar el efecto. Encontró una que
llevaba abierta todo el tiempo.

```
200  GET  api/alumnos   PERSONALES: celular,direccion,fecha_nac [27128 b]
```

`GET api/alumnos` devuelve **todos los alumnos del colegio**, sin filtrar por
grupo ni por año: nombre, apellidos, sexo, fecha de nacimiento, ciudad de
nacimiento, **celular, dirección**, religión, estado de deuda, nombre de usuario y
si la cuenta está activa. Iba **sin más guard que el token**, así que la leía
cualquier alumno o acudiente.

### 34.1 Por qué se había escapado a todo

Es el quinto de la familia de la [§14](#14-los-listados-que-no-nombran-a-nadie-20-ago-2026),
y el que faltaba. Cae justo entre dos criterios:

- **No nombra a nadie.** No lleva `{alumno_id}` ni nada parecido, así que
  `inventario-autorizacion.py` no tenía qué señalar — es el punto ciego que la
  §14 describió y cerró para siete rutas.
- **Y no está muda.** Las listas de «sin juzgar» de la [§23](#23-el-cuerpo-anidado-y-las-cuatro-que-escondía-20-ago-2026)
  y la [§24](#24-las-once-que-quedaron-sin-juzgar-20-ago-2026) recogían las que no
  devolvían nada; ésta devuelve 27 KB.

O sea que estaba en el grupo de «pasaron de largo con algo dentro», que es el que
**se juzga a mano, una por una**. Se juzgaron once de las doce. Ésta no, y no hay
manera de saberlo mirando: el barrido las imprime todas cada vez, y una que ya
tiene sitio se lee igual que una que no lo tiene.

**La lección es sobre la herramienta, no sobre la ruta:** una lista que hay que
repasar a mano cada vez acaba teniendo un hueco, y el hueco no se ve. Lo que lo
encontró fue volver a correr el barrido por otra razón —comprobar seis
arreglos— y leerlo entero otra vez.

### 34.2 Qué se hizo

`auth.personal`, que es lo que llevan **quince de sus dieciséis hermanas** en
`routes/api/alumnos.php` y lo que la §14 decidió siete veces para esta misma
forma. No hay decisión nueva que tomar: `ExigirPersonaPropia` lo lleva escrito en
su cabecera desde la revisión de IDOR —*«una ruta de grupo entero… esas llevan
`auth.personal`»*—.

Y **ningún cliente la llama**: `AlumnosApi.ts` enumera diecisiete rutas de este
recurso y la del listado no está. Comprobado también en `myvc_flutter` y en
`myvc_front_2`, y esta vez contra `myvc_front/app`, que es donde vive el front
actual — la lección de la [§26](#26-sin-clave-el-colegio-entero-con-la-contraseña-vacía-20-ago-2026).

### 34.3 Lo que queda después, medido

Con el guard puesto, el barrido de un alumno baja de doce rutas a once, y **las
once tienen sitio**: cinco son datos propios (`auth/me`, `login`, `logout`,
`aplicacion-descargas/detailed`, `guardar-mi-email-restore`), dos son
configuración del colegio (`years`, `years/colegio`), dos son el muro y la
votación (`publicaciones/comentar`, `votos/store`), y **dos esperan una decisión
que ya estaba anotada**: `GET api/contratos` y
`GET api/perfiles/username/{username}` (09 §5).

Con token de acudiente son trece, y las dos de más son las suyas: `mis-acudidos` y
`ChangesAsked/to-me`, que traen la ficha completa de su acudido — que es
exactamente lo que la regla del colegio permite y no se re-litiga.

---

## 35. El PIAR, al que entraba cualquiera (21 ago 2026)

**No se llegó buscando aquí.** Joseth pidió, antes de decidir el alcance del rol
`Psicólogo` de la [§30.2](#302-secretario-es-un-permiso-que-nadie-tiene-buscado-en-dos-sitios-distintos),
ver **qué endpoints toca el PIAR**. La lista se sacó de `myvc_front_2` —el único
cliente que los llama— y contestó otra pregunta antes que la suya.

El PIAR (plan individual de ajustes razonables) es el módulo de las necesidades
educativas especiales, y sus catorce rutas se habían mirado poco por una razón
concreta: **es la única aplicación del sistema que no comparte pantallas con el
resto**, así que ni el barrido ni las revisiones por dominio pasaron por ahí.

### 35.1 Lo que estaba abierto

| Ruta | Llevaba | Qué permitía |
|---|---|---|
| `PUT piars-alumnos/field` | **nada, solo `auth.token`** | reescribir la valoración pedagógica, los ajustes generales o el reporte del PIAR **de cualquier alumno**, con un token cualquiera y el `id` de la fila |
| `POST piars-alumnos/document` | `persona.propia` | que el **propio alumno** subiera los documentos de su PIAR |
| `DELETE piars-alumnos/document/{id}` | `persona.propia` | que el **propio alumno** los borrara |

La de `field` es la que importa. Elige la fila **por el `id` del PIAR**, no por el
alumno, así que `persona.propia` tampoco habría servido: no hay ningún
identificador de persona en el cuerpo que un guard pudiera comprobar. Lo único
que la cierra es que el PIAR entero sea del personal.

Y lo es: `myvc_front_2` **no tiene camino de familia**. En todo su código fuente
solo distingue `tipo === 'Usuario'`, `tipo === 'Profesor'` con titularidad, e
`is_superuser`. El `familiar-context.service` que hay dentro no es la familia
entrando: es la sección «contexto familiar» de la ficha del alumno.

Las tres pasan a `auth.personal`. Decisión de Joseth, 21 ago 2026: **los
documentos del PIAR los pone el colegio**. Fijado por `PiarTest`, comprobado al
revés.

### 35.2 Las cuatro comprobaciones que no comprobaban

`PiarsAlumnosController` y `PiarsActasAcuerdoController` tenían, cada uno dos
veces, esto:

```php
if ($this->user->tipo != 'Usuario' && $this->user->tipo != 'Profesor') {
    response()->json(['error' => 'Unknownthorized'], 400);   // sin return
}
```

**`response()` sin `return` construye una respuesta y la tira.** La petición
sigue. Es el mismo error de forma que el `return 'No tienes permiso';` dentro de
un constructor que documenta `ExigirPersonal`, y la tercera familia de la misma
cosa: **una autorización escrita en una expresión que no corta nada**. Se borran
las cuatro en vez de arreglarlas, porque el criterio que intentaban aplicar
—«Usuario o Profesor»— es letra por letra lo que hace `auth.personal`, que ahora
llevan las rutas. Un candado en un sitio.

**Queda una quinta, y esa está además al revés:**

```php
// PiarsConfigController::putConfig
if ($this->user->is_superuser) {
    response()->json(['error' => 'Unknownthorized'], 400);   // sin return, y la
}                                                            // condición invertida
```

Dice «si **es** superusuario, error». Si el `return` hubiera estado, la ruta
habría dejado fuera exactamente a quien tenía que dejar entrar. No la llama
**ningún** cliente de los cuatro —se comprobó contra `myvc_front/app`,
`myvc_front_2/src` y `myvc_flutter/lib`—, y en `myvc_front_2` la única llamada a
`piars-config/field` está **comentada**. Se arregla junto con el rol `Secretario`,
porque la pregunta de quién configura el colegio es la suya y no otra.

### 35.3 Lo que contestó la pregunta de Joseth

**El PIAR no pregunta por el rol `Psicólogo` en ningún sitio.** Autoriza por
`tipo`, así que hoy entran los 71 del personal, tengan el rol o no. El rol existe
—id 11, cuatro personas, se lo asigna `users/crear-psicologo`— y no gobierna nada.

Lo que sí falta es **el eslabón de antes**. `PiarsAlumnoUtils` filtra sus dos
consultas por `a.nee=1`: el PIAR solo ve a los alumnos **ya marcados**. Y marcar a
uno es `alumnos/guardar-valor`, que hoy exige `is_superuser` porque la rama del
psicólogo compara `tipo` con un valor que `tipo` no toma nunca (§30.2). O sea:

> **El psicólogo trabaja el PIAR pero no puede meter a nadie en él.**

Con eso delante, Joseth decidió: el rol `Psicólogo` abre `nee` y
`nee_descripcion`, y nada más. El PIAR se queda como está —abierto al personal—
porque cerrarlo al rol dejaría fuera de golpe a los docentes que hoy lo usan.

### 35.4 Y dos cosas que se ven al pasar y no se tocan

- **`getAlumnosPiar()` escribe dentro de un `GET`.** `contexto-de-grupo` crea la
  fila de PIAR del alumno marcado que no la tenga. Funciona y nadie se queja, pero
  es un `INSERT` en una lectura: lo que significa es que **no se puede cachear esa
  ruta** ni servirla desde una réplica. Anotado aquí para que el día que alguien
  lo intente sepa por qué no.
- **`data.alumnos` de esa misma respuesta no filtra nada**: son los del grupo
  entero con teléfono, celular, dirección, `nee` y `nee_descripcion` de cada uno.
  Lo recibe personal del colegio, así que no es un caso de la §34; se fija en el
  test porque **la diferencia entre `alumnos` y `alumnos_piar` es justo lo que un
  refactor confundiría**, y confundirlas sería mandar las necesidades educativas
  de todo el grupo a una pantalla que solo debía mostrar diez.

---

## 36. El correo por el que llega el reseteo, y las imágenes de los demás (21 ago 2026)

De seguir la lista de cobertura. `PerfilesController` tenía **nueve rutas cuya
respuesta no había mirado nadie nunca**, y dos de ellas resultaron caras.

### 36.1 Un profesor se llevaba la cuenta del superusuario, otra vez

`login/recuperar-clave` busca a quien pide el reseteo así:

```php
$consulta = 'SELECT * FROM users WHERE email = ? and deleted_at is null and is_active=1';
```

y le manda el enlace a esa dirección. O sea que **`users.email` no es un dato de
perfil: es la llave de la cuenta.**

Y `PUT perfiles/cambiaremailrestore/{id}` escribe esa columna **de cualquier id**.
Su único guard era `persona.propia:user_id`, que frena a alumnos y acudientes y
**deja pasar de largo a todo el personal** — es lo que ese middleware dice de sí
mismo en su propio docblock: «lo que puede hacer el personal del colegio entre sí
queda como está». Con eso, cualquiera de los 51 profesores ponía su correo en la
cuenta del superusuario y pedía un reseteo.

Medido antes de arreglar: con token de profesor, la ruta respondía **200** sobre
el id del superusuario.

**Y devolvía el hash.** El método terminaba en:

```php
return $perfil->password . ' - ' . (string)Request::input('password');
```

Esto merece quedarse: el modelo `User` **tiene `password` en `$hidden`**, así que
en cualquier respuesta JSON no sale nunca. Una **concatenación en una cadena se
salta `$hidden` entero**. La protección estaba puesta, era la correcta, y no
cubría la única salida que ese método usaba.

El criterio nuevo es el suyo o el de un superusuario, comprobado dentro del método
porque «el suyo» necesita comparar el `{id}` con el del token y el middleware eso
solo lo hace para familias. **No rompe ninguna pantalla**: el propio front dejó
escrito, al retirar su último llamante, que `PerfilesApi.cambiarEmailRestore` «se
queda sin llamantes». Lo que cada uno usa para cambiar el suyo es
`perfiles/guardar-mi-email-restore`, que ya sacaba el id del token.

Es la tercera de la misma familia que la §29, y las tres se parecen: **una ruta
que escribe sobre un id ajeno sin preguntar de quién es.**

### 36.2 Las cinco imágenes, y el backend por debajo de su propia pantalla

Las cinco rutas que cambian la imagen o la firma **de otra persona** llevaban
`auth.personal`:

| Ruta | Qué cambia |
|---|---|
| `perfiles/cambiarfirmaunprofe/{id}` | la **firma** de un profesor |
| `perfiles/cambiarimgunprofe/{id}` | su foto |
| `perfiles/cambiarimgunusuario/{id}` | la imagen de un administrativo |
| `perfiles/cambiarimgunalumno/{id}` | la foto de un alumno — **sin ningún cliente que la llame** |
| `myimages/cambiarlogocolegio` | **el logo, que sale en cada boletín y cada certificado** |

Las cinco viven, en el front, dentro de la pestaña «Imágenes de usuarios» del
gestor de archivos, que la plantilla enseña con
`ng-if="$ctrl.hasRoleOrPerm('admin')"`. **Es la situación de la [§29.3](#293-y-dos-cosas-más-de-la-misma-pasada)
otra vez: el backend dos escalones por debajo de su propia pantalla.** Se cierra
con aquella decisión y no con una nueva, y por eso no hace falta preguntar: a
quien no es admin el front **no le enseña ni el botón**, así que subir el listón
no puede apagar un flujo que nadie ve.

Pasan a `Autoriza::esAdministrativo`. Fijado por `ImagenesDeOtrosTest`, que
comprueba las tres caras —profesor no, familia no, administrativo sí— y **la
columna** en cada una, no solo el código.

### 36.3 Lo que enseñó de paso

El test de las cinco escribía `assertStatus(403, "cuál de las cinco falló")`.
**`assertStatus()` acepta un solo parámetro**, y PHP se traga en silencio los que
sobran: el test parecía llevar mensajes y no llevaba ninguno, así que un fallo
habría dicho «403 esperado, 200 recibido» sin decir en cuál de las cinco rutas.

No lo dijo la corrida —pasaba— sino **larastan**. Vale la pena por lo que dice del
reparto: los tests dicen si el código hace lo que se quiere, y el análisis estático
dice si el test comprueba lo que dice comprobar.

---

## 37. Tres respuestas que decían que sí cuando fue que no (21 ago 2026)

No son un agujero: las tres **frenaban la escritura**. Lo que hacían mal era
contarlo.

| Ruta | Qué respondía a quien no podía |
|---|---|
| `PUT profesores/update/{id}` | **200 con el cuerpo vacío** |
| `PUT profesores/guardar-valor` | **200 con `['Guardado.']`** |
| `PUT myimages/publicar-imagen/{id}` | **200** con la cadena «No tiene permisos…» dentro |

Las tres tienen la misma forma, y es una forma que **no se ve leyendo el sitio
donde está**: un `if` de permiso que envuelve el cuerpo entero del método y no
tiene `else`. Quien no cumple la condición cae por debajo del `if` y se encuentra
con lo que haya al final —nada, o un `return ['Guardado.']` que estaba pensado
para el caso bueno.

**Que el cliente no puede distinguirlo está comprobado, no supuesto.** En
`FileManagerCtrl`:

```js
$ctrl.publicarImagen = imagen => MyimagesApi.publicar(imagen.id).then(function(){
    toastr.info('Ahora la imagen es pública');
```

O sea que un alumno pulsaba «Publicar» en la pestaña «Mis imágenes» —que ven los
cuatro tipos de usuario—, le decían que ahora era pública, y seguía privada. Y un
profesor que editaba a otro profesor veía la pantalla decir que se guardó.

**Una respuesta que miente es peor que un error, porque el que la lee deja de
mirar.** Es la tercera vez que aparece la misma idea con otra cara: los doce
`abort()` de la §12, el `response()->json()` sin `return` de la §35, y esto.

### 37.1 Cómo se encontraron, que es lo que se puede repetir

Con un buscador de **forma**, no leyendo: `tools/respuestas-que-mienten.py`
recorre los 129 controladores y saca los métodos cuyo primer statement es un `if`
de permiso que abarca todo y no tiene `else` ni `abort()`. Se llegó a él por
casualidad —mirando `ProfesoresController` porque la cobertura lo señalaba— y en
cuanto la forma tuvo nombre, buscarla costó un minuto.

Y el buscador **se equivocó dos veces, en las dos direcciones**, que es lo que hay
que saber de él:

- **Catorce falsos positivos** en `MatriculasController`, que sí abortan: buscaba
  el `else` en la línea siguiente al cierre del `if`, y en este proyecto el
  `} else {` va **en la misma línea del cierre**.
- **Un falso negativo**: `profesores/update` tiene un `abort(422)` en su `catch`,
  así que el criterio «no contiene `abort(`» lo dejaba fuera. Se encontró leyendo
  el fichero de al lado.

Por eso la herramienta lleva escrito en su cabecera que **hay que mirar cada
resultado**. Un contador de formas no entiende lo que cuenta.

### 37.2 Y una de propina, que se deja como está

`profesores/guardar-valor` acepta una `propiedad` cualquiera y **solo actúa sobre
`is_active`**; con cualquier otra responde igual y tampoco escribe. Eso no se
toca: es la forma del método y no un permiso, y arreglarlo es decidir qué
propiedades debería aceptar, que es otra pregunta. Queda dicho en su docblock.

---

## 38. Dos formas de leer un usuario, y solo una está protegida (21 ago 2026)

Salió de barrer la forma de la §36.1 por todo el proyecto: **credenciales que
acaban en una respuesta**. El barrido de `u.password` en los `SELECT` salió
limpio —solo queda el de `Services\Login`, que es el que la verifica— pero al
buscar `SELECT *` sobre `users` aparecieron **siete**.

Están en `ChangeAsked::extender_datos()` y en su gemelo de `ChangeAskedDetails`,
que cuelgan de cada pedido de cambio hasta **tres filas de `users`**: quien lo
pide, a quién se le pide y sobre quién.

Lo que hace que esto sea una lección y no un descuido:

> **El modelo `App\User` tiene `password` y `remember_token` en `$hidden`.** La
> protección está puesta, es la correcta, y funciona — pero solo si el usuario se
> lee con el modelo. Aquí se lee con `DB::select`, y **una consulta cruda no tiene
> modelo al que ocultarle nada**.

Es exactamente la misma frase de la §36.1 —allí lo que se saltaba `$hidden` era
una concatenación en una cadena— y en un proyecto con **990 consultas crudas** no
es casualidad que salga dos veces el mismo día: el `$hidden` cubre el camino que
este proyecto casi no usa.

**Y sale por una ruta de familia.** `PUT images-users/cambiar-imagen-perfil/{id}`
lleva `persona.propia`, o sea que la usa un alumno sobre su propia foto: como no
es superusuario, su cambio no se aplica sino que se convierte en un **pedido**, y
el pedido volvía con las filas dentro. Medido con token de alumno: la respuesta
traía un `$2y$…`.

### 38.1 El arreglo quita en vez de elegir

`App\Support\UsuarioSinCredenciales::porId()` hace la misma consulta y borra las
dos columnas que son credenciales.

**Quitar y no elegir es la decisión.** Una lista de columnas permitidas habría
dejado fuera cualquier columna nueva sin que nadie se enterara, y esto va dentro
de una respuesta que los clientes ya reciben. Quitando solo las dos, la forma no
cambia: sale exactamente lo mismo menos lo que no debía estar.

Se comprobó además que **ningún cliente lee `asked_by_user`, `asked_to_user` ni
`asked_for_user`** —ni el front viejo, ni el del PIAR, ni Flutter—. Con eso se
podrían haber borrado los tres; se dejan porque quitar tres claves de una
respuesta es un cambio de contrato y quitar un hash no lo es, y no hacía falta lo
primero para conseguir lo segundo.

Fijado por `PedidosDeCambioTest`, con las dos mitades: que no sale ningún hash y
que **el pedido se sigue creando y sigue diciendo quién lo hizo** — porque
recortar una respuesta es fácil de hacer de más.

---

## 39. El pedido era decorativo: aprobar escribía lo que dijera el cuerpo (21 ago 2026)

Siete rutas de `ChangeAskedController` sin que nadie hubiera mirado nunca lo que
responden. Es el flujo con el que alguien **pide** un cambio sobre sus propios
datos y otro lo **aprueba**, y las dos rutas que aprueban no comprobaban lo que
estaban aprobando.

### 39.1 Renombrar a cualquier alumno

`putAceptarAlumno` recibe un `asked_id`, busca el pedido… y luego escribe así:

```php
$consulta = 'UPDATE alumnos SET nombres=:nombres WHERE id=:id';
DB::select($consulta, [ ':nombres' => Request::input('valor_nuevo'),
                        ':id'      => Request::input('alumno_id') ]);
```

**A quién se renombra y con qué lo decidía el cuerpo de la petición.** El pedido
se buscaba y no se usaba: se podía mandar un `asked_id` inventado. Con
`auth.personal` como único guard, la ruta era un `UPDATE alumnos SET nombres`
abierto a los 51 profesores —y saltándose `alumnos/update`, que sí exige
superusuario o `profes_can_edit_alumnos`—. Las cuatro ramas de texto igual:
nombres, apellidos, sexo y fecha de nacimiento.

Medido antes de tocarlo: con token de profesor y `asked_id: 999999`, el alumno
quedó renombrado.

### 39.2 Reasignar o borrar cualquier asignatura

`putAceptarAsignatura` empezaba con `$pedido = Request::input('pedido')`, y a
partir de ahí **todo** salía del cuerpo: a qué asignatura, a qué profesor, con
cuántos créditos, y cuál mandar a la papelera. La rama de
`asignatura_to_remove_id` es la que más alcance tiene: un `UPDATE asignaturas SET
deleted_at` sobre el id que se escriba.

### 39.3 El arreglo es el mismo de la §27, y por eso se dice igual

**Derivar de la fila que se aprueba, no de lo que venga escrito.** El alumno sale
de `change_asked.asked_by_user_id` —como ya hacía `cambiarOficialAlumno()`, que
estaba bien y era el modelo a seguir dentro del mismo fichero— y el valor de
`change_asked_data.<campo>_new`, que es lo que la persona pidió de verdad. En la
de asignaturas, el cuerpo solo aporta ya el `asked_id`, que es lo que el front
manda dentro de `pedido` porque **el objeto se lo dio este mismo servidor**.

Se recalcula además `asignatura_actual`, que no es una columna sino algo que
`Solicitudes` **calcula** a partir de la materia y el grupo pedidos. Cuesta una
consulta y quita la última cosa que se estaba creyendo.

Y de paso: **siete `UPDATE` se ejecutaban con `DB::select`**. Funciona —PDO no
distingue— pero devuelve una lista vacía en vez del número de filas afectadas, así
que ninguno de los siete podía saber si había escrito algo. Pasan a `DB::update`.

### 39.4 Un test que pasaba por la razón equivocada

El primer test de la asignatura **daba verde antes del arreglo**, y no porque la
ruta estuviera bien: mandaba `asignatura_actual['id']` y el controlador lee
`asignatura_actual['asignatura_id']`, así que el `UPDATE` iba con `:id => null`,
no tocaba nada, y el test concluía que la fila no se había movido.

Se vio comparando el test con el código, no corriéndolo. **Un test que da verde
porque no llegó a ninguna parte es peor que no tenerlo**, porque además ocupa el
sitio del que sí habría comprobado. Es la tercera vez en el día que aparece la
misma idea —el test del hash de la §25.4 y las excepciones de `AutorizacionTest`
de la §35—, y las tres se cazaron igual: **mirando si el test falla cuando debe
fallar.**

Y las tres líneas del final de `putAceptarAsignatura` que hacían
`$pedido['..._accepted'] = true` sobre un array local que ya nadie leía se
retiran: la respuesta era y sigue siendo `['finalizado' => true, 'msg' => …]`.

---

## 40. Qué escrituras cierra el interruptor del periodo — **decidido** (21 ago 2026)

Al cubrir `AusenciasController` y `NotaComportamientoController` —doce rutas que
nadie había mirado— salió que **la lista de lo que cierra el interruptor del
periodo no la había elegido nadie**: se fue formando llamada a llamada, y tenía
dos asimetrías que no parecían decididas.

| Asimetría | Qué pasaba |
|---|---|
| De las ausencias cerraba **la mitad** | Corregir una o borrarla exigía el periodo abierto; **anotar una nueva no**. |
| **Uniformes cerraba y la nota de comportamiento no** | Siendo las dos disciplina — y la de comportamiento sale en el boletín. |

Se le preguntó a Joseth, con las dos listas medidas delante, y contestó las dos.
La respuesta partió por un sitio distinto del que sugería el código:

> **«Que poner asistencias no se bloquee al bloquear periodos.»**

Y al preguntarle por la otra mitad —corregir y borrar— contestó que también libre.
El criterio que queda, y que vale para la próxima ruta que se escriba:

> **El interruptor cierra las notas, no la asistencia.** `profes_pueden_editar_notas`
> es lo que dice su nombre. Pasar asistencia, excusar una falta cuando el alumno
> trae la excusa o corregir una tardanza mal puesta es trabajo de todos los días y
> no cambia ninguna calificación.

### 40.1 Lo que cambió

**Las ausencias salen del interruptor, las cinco.** Se retiraron las tres llamadas
que quedaban —`guardar-cambios-ausencia`, `cambiar-tipo-ausencia` y `destroy`—,
que eran tres de las 26 de la [§27](#27-el-interruptor-del-periodo-lo-elige-el-cliente-20-ago-2026).
El porqué está en la cabecera de `AusenciasController`, que es donde alguien va a
preguntarse por qué faltan.

**La nota de comportamiento entra, en las cuatro que escriben.** No estaba entre
las 26 porque no llamaba al candado en **ninguna** de sus ocho rutas: aquí no
había una llamada que arreglar sino una que poner. Se le pone a `store`, `crear`,
`update` y `destroy`, con el periodo derivado igual que en la §27:

- `store` escribe en el periodo del profesor, así que mira ése.
- **`crear` escribe en el periodo que nombra el cuerpo, así que mira ése y no el
  del profesor.** Es la lección de la §27 aplicada a una llamada que no existía
  cuando se hizo aquel arreglo, y tiene su propio test: con el periodo del profesor
  abierto y el de destino cerrado, no pasa.
- `update` y `destroy` lo derivan de `nota_comportamiento.periodo_id`.

`guardar-libro` se queda fuera: escribe en `dis_libro_rojo`, que es el observador
y no una nota. Y `frases-check` es una lectura, aunque sea un `PUT`.

### 40.2 Y dos cosas que se fijan sin tocarlas

- **`nota_comportamiento/detailed/{grupo_id}` escribe dentro de un `GET`**:
  `crearVerifNota()` crea la fila del alumno que no la tenga. Es lo mismo que hace
  el PIAR (§35.4) y significa lo mismo — esa ruta no se puede cachear ni servir
  desde una réplica de lectura. Por eso el candado **no** se le puso: bloquear la
  lectura de la pantalla habría sido el efecto, y nadie lo pidió.
- **`ausencias/store` y `agregar-tardanza` responden 201, no 200.** Modelo Eloquent
  recién creado, igual que `opciones/add-opcion` (§27.2) y al contrario que
  `years/store` (§28.4). Los tres números están fijados porque los tres son lo que
  reciben los clientes.
- **`ausencias/detailed` no arrastra credenciales**: `Alumno::userData()` es una
  lista de columnas nombrada y no un `SELECT *`, que es justo la diferencia que la
  §38 encontró cara.

### 40.3 Los dos tests que se dieron la vuelta

Los dos afirmaban el comportamiento de entonces **a propósito**, esperando esta
decisión, y los dos fallaron al aplicarla — que era su trabajo:

- el de ausencias fijaba que corregir y borrar respetaban el periodo cerrado;
- el de comportamiento fijaba que con el periodo cerrado se seguía escribiendo.

Ahora fijan lo contrario, y **cada uno comprueba la fila y no solo el código**: que
la corrección de la ausencia llega, y que la nota de comportamiento no cambia.

---

## 41. La ficha del alumno por un identificador que el guard no conocía (21 ago 2026)

De cruzar dos listas: las rutas **sin comprobar** y las rutas **alcanzables por una
familia** —o sea, sin `auth.personal`—. Salieron 38, y dos importan.

### 41.1 `PUT alumnos/show`, la hermana en detalle de la §34

Devuelve la ficha completa: documento, tipo de sangre, EPS, dirección, teléfono,
religión, sisbén, deuda, `username`, y **`nee` y `nee_descripcion`** —las
necesidades educativas especiales—. La ruta lleva solo `auth.token`.

Tiene una rama para acudientes que **sí** comprueba el vínculo y devuelve «No es
tu acudido», y **un `else` que cubre a todos los demás**, incluido un alumno,
buscando por `a.id` sin mirar de quién es. Medido: con token de alumno y el id de
otro, **200 con la ficha entera**.

**Y lo interesante es por qué no lo cazó `persona.propia`**, que existe justo para
esto y que se aplicó a treinta y tantas rutas en la revisión de IDOR. El
identificador aquí se llama **`id`**, y la lista de nombres que ese guard reconoce
es `alumno_id`, `user_id`, `persona_id`, `acudiente_id`, `profesor_id`,
`matricula_id`, `imagen_id`, `img_id`. Su propio docblock había previsto
exactamente este fallo:

> «Los endpoints de este sistema nombran a una persona de seis maneras distintas
> […] Comprobar solo la que uno espera deja abierta la que no.»

Y aun así faltaba ésta. **`id` no se le puede añadir a la lista**: media API lo usa
para cosas que no son personas —una unidad, una nota, un año, una imagen— y el
guard intentaría resolver cada una como si lo fuera. Por eso se cierra dentro del
método.

Lo que deja: **una lista de nombres nunca está completa**, y la forma de encontrar
lo que le falta no es leerla otra vez sino cruzar «quién puede llegar» con «quién
lo ha comprobado». Es la misma pregunta de la cobertura, hecha sobre otro eje.

### 41.2 El Enfermero, la tercera de la familia del Secretario

En el mismo barrido salió `enfermeria/guardar-valor`:

```php
// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
if($this->user->is_superuser || $this->user->tipo == 'Enfermero'){
```

**Es la rama del psicólogo otra vez, con el mismo comentario del autor al lado y
el mismo error debajo**: el comentario dice `Usuario` y el código compara `tipo`
con un valor que `tipo` no toma nunca. Y de las que cierran de más: **la enfermera
del colegio no podía escribir los antecedentes médicos** salvo que fuera
superusuaria. El rol `Enfermero` existe y tiene una persona dentro.

Se arregla con la decisión que Joseth ya tomó en la §30.2 —el criterio es el rol—
sin volver a preguntar, porque es la misma pregunta. Con esto se cierran **las
tres** de esa familia: Secretario, Psicólogo y Enfermero.

Se buscó si quedaba alguna cuarta comparando `tipo` con los once nombres de rol:
**no queda ninguna**.

### 41.3 Y dos cosas medidas al pasar

- **`enfermeria/guardar-valor` ejecutaba su `UPDATE` con `DB::select`**, igual que
  los siete de la §39. Pasa a `DB::update`.
- **`ColumnaSegura::exigir()` es lo único que separa esa ruta de una inyección**:
  la `propiedad` se concatena en el SQL. Queda fijado con un test que manda
  `observaciones=1, updated_by=99 WHERE 1=1 -- ` y exige un 422 — para que el día
  que alguien quite la lista blanca «porque estorba», esto lo cuente.

---

## 42. El comentario que su autor no podía borrar (21 ago 2026)

`PUT publicaciones/borrar-comentario` decidía así:

```php
if ($user->is_superuser || $user.persona_id==comentario.persona_id) {
```

**`$user.persona_id` no lee una propiedad.** El punto en PHP concatena, así que
eso es `$user . persona_id`, con `persona_id` y `comentario` como **constantes que
no existen** — notación de punto de otro lenguaje. En PHP 7 una constante
indefinida era un aviso y valía su propio nombre; **en PHP 8 es un error fatal**.

Y como `||` corta por la izquierda, **un superusuario nunca llegaba a esa mitad**.
Todos los demás sí. O sea que el autor de un comentario recibía un **500 al borrar
el suyo**, y solo desde el salto a PHP 8 — antes «funcionaba» comparando dos
cadenas que nunca coincidían, así que tampoco borraba: pasó de no dejar a reventar.

Estaba anotado en `phpstan.neon` con su `count: 3` y con esta frase, que resultó
ser el arreglo entero:

> «Lo que falta es de dónde sale el dueño del comentario, y eso es una consulta
> que nadie escribió.»

### 42.1 Y `persona_id` sola no identifica a nadie

La consulta que faltaba compara **dos columnas**, no una. Los ids de este sistema
son **por tabla**: el alumno 5 y el profesor 5 son personas distintas, y por eso
`comentarios` guarda `tipo_persona` al lado de `persona_id`. Comparando solo el
número, un alumno habría podido borrar el comentario del profesor que compartiera
número — un agujero nuevo, metido al arreglar uno viejo.

Es la misma trampa que `$user->user_id` frente a `$user->persona_id`, que el
CLAUDE.md avisa en su primera página. Aquí aparece en su tercera forma: **el id
sin la tabla no es una identidad.**

Fijado por `ComentariosTest`, con las cuatro caras: el autor sí, un tercero no, el
superusuario sí, y comentar guarda de quién es.

---

## 43. El examen de otro grupo, por su número (21 ago 2026)

`PUT api/mis-actividades/mi-actividad` recibe un `actividad_id` y **no comprobaba
nada sobre él**. Medido con un token de alumno contra una actividad de un grupo
en el que no está matriculado: **200 con el examen entero** —el enunciado de cada
pregunta y el texto de cada opción—, teniendo la actividad `para_alumnos = 0` e
`in_action = 0`, o sea un examen que el profesor todavía no había abierto a nadie.

Los ids son enteros pequeños y consecutivos, así que no hay que adivinar nada: se
recorren en orden.

### Y no solo leía

La primera línea del método **crea el intento**:

```php
$res = WsActividadResuelta::where('actividad_id', $actividad_id)
    ->where('persona_id', $user->persona_id)->first();
if (!$res) { $res = new WsActividadResuelta(); ... $res->save(); }
```

Así que abrir el examen de otro grupo dejaba una fila en
`ws_actividades_resueltas` a nombre del que miraba — y esa fila es exactamente la
que sale después en la pantalla de corregir de ese profesor
(`respuestas/actividad`, la §24), donde aparece un alumno que no es suyo. Por eso
la comprobación va **delante** de la creación y no después.

### Por qué se había quedado sin nada

Las tres rutas de familia de este controlador no pueden llevar `auth.personal`
—responder un examen es justo lo que hace un alumno— y la §20 ya lo había
resuelto para dos de ellas con una comprobación dentro,
`exigirQueLaResueltaSeaSuya()`. Ésta se quedó fuera **porque su identificador es
otro**: las dos de la §20 reciben `actividad_resuelta_id`, que nombra un intento,
y ésta recibe `actividad_id`, que nombra una actividad. La §20 contestó «de quién
es el intento»; **«a qué actividad se puede entrar» no la contestaba nadie**, y
como la pregunta no era la misma, la respuesta de la §20 no la cubría.

Es la forma de la §21 vista desde el otro lado: allí un mismo nombre de parámetro
—`{id}`— tapaba tablas distintas; aquí dos nombres distintos tapan que la
pregunta de autorización es la misma.

### Lo que se cerró, y lo que no

El guard no puede ir en la ruta, y esto es lo que la había dejado sin ninguno:
`panel.mi_actividad` tiene **dos entradas en el front y son de bandos distintos**
—`misActividades.html`, que es la lista del alumno, y `actividades.html`, que es
la del profesor—, así que `auth.personal` apagaría la pantalla del alumno. La
comprobación va dentro, y al personal no se le toca: abre cualquiera, como hoy.

Lo que se cierra es la familia, con la regla de siempre. Y «lo suyo» son **las dos
formas** en que una actividad llega a un grupo:

- ser de una asignatura de un grupo suyo del año en curso, o
- estar compartida con ese grupo en `ws_actividades_compartidas`.

Comprobar solo la primera habría **apagado el compartir entre grupos**, que es una
función viva —es de donde saca sus grupos la pantalla de corregir— y que se habría
roto en los dieciséis colegios sin que ningún test lo dijera. Tiene su propio caso.

De paso, un `actividad_id` que no existe era **500 con el intento ya escrito**,
porque `datosActividadConRespuestas()` indexa con `[0]` el resultado de la
consulta. Ahora es 404.

### Lo que se deja abierto a propósito

`para_alumnos`, `in_action`, `inicia_at` y `oportunidades` **siguen sin
comprobarse**, y no es un descuido: hoy un alumno puede abrir un examen de su
propio grupo antes de que el profesor lo suelte, y las veces que quiera. Cerrarlo
no es un arreglo de autorización sino **encender una regla de procedimiento** que
hoy no existe, y eso cambia cómo se dan los exámenes en dieciséis colegios. Va a
la tabla del §5 de [09-pendientes.md](09-pendientes.md), con su test dejándolo
fijado como está (`test_hoy_se_abre_un_examen_de_su_grupo_que_no_esta_en_accion`).

Es la misma forma que el `locked` de las votaciones, y no es una analogía: se
midieron el mismo día y por separado, y allí salió lo mismo — se vota con
`locked = 1` e `in_action = 0` porque nadie lee esas columnas. **La regla de
procedimiento no la comprueba nadie porque no es un guard.** El módulo entero
está en [11-votaciones.md](11-votaciones.md), fuera de este documento porque lo
midió otra sesión; su §1 —`PUT votos/show` destapando el recuento en vivo con un
`permitir` en el cuerpo— es la misma familia que ésta.

Y una segunda de la misma familia, que salió al medir: **entregar no es
entregar.** `finalizar-actividad` pone `terminado = true` y nadie vuelve a mirar
esa columna, así que `seleccionar-opcion` sigue borrando la respuesta anterior y
escribiendo la nueva. El profesor corrige lo último que se escribió, no lo que
había al entregar. También fijado como está.

### Lo que enseñó el seed, por tercera vez

El primer intento de medir esto **no midió nada**, y el segundo tampoco, por dos
trampas que ya estaban escritas y que aun así volvieron a morder:

1. **No hay ningún grupo ajeno.** El seed copia UN grupo por año —84 del año 7, 98
   del 8— y el alumno de siempre está matriculado en los dos, así que un
   `grupo_id != el suyo` devuelve *el otro grupo suyo*. Es lo que ya costó 36
   rutas mal medidas en la §16, y aquí habría dado un test en verde afirmando que
   el alumno abre «el examen de otro grupo» cuando abría el propio.
2. **El año no se elige.** `Services\Login` reescribe `users.periodo_id` al
   periodo del año `actual` al entrar, así que un sujeto elegido por el periodo
   que tiene guardado *antes* de entrar monta el examen en un año y lo pide desde
   otro — y entonces el 403 sale por el año y no por el grupo. Lo documenta
   `tokenDelPersonalDe()` desde la P1.

Las dos caben en una frase, y es la que hay que llevarse: **un fixture que el seed
no puede expresar da un test que pasa sin comprobar lo que dice.** El grupo ajeno
hay que montarlo —lo que falta es una fila, no el estado de una que exista— y el
año hay que anclarlo a `years.actual = 1`.

Y la frase se ganó dos caras más el mismo día, en el módulo de votaciones
([11](11-votaciones.md)), que conviene tener juntas porque las tres se parecen y
fallan distinto:

- **La fila que se monta y la consulta descarta en silencio.**
  `VtCandidato::porAspiracion()` une solo con `alumnos`, con matrícula
  MATR/ASIS/PREM y filtrando por año, así que un candidato cuyo `user_id` no sea
  un alumno de ese año **no da error: desaparece de la papeleta**. Un
  `assertNotEmpty` encima pasa con la lista que trae solo el «Voto en Blanco».
- **El año, otra vez y por el otro extremo.** Los alumnos del seed están en los
  años 7 y 8 y el primer profesor está en el 4, así que montar la elección contra
  el año del profesor deja la papeleta vacía.

Las tres tienen la misma forma —**el test no falla, se queda sin sujeto**— y
ninguna la detecta el propio test. Lo único que las caza es afirmar sobre el
contenido y no sobre la forma: no `assertNotEmpty`, sino el nombre del candidato
que se acaba de insertar.

Fijado por `MisActividadesTest`, nueve casos, comprobado al revés: desactivando la
comprobación caen tres y los otros seis siguen verdes, que es lo que dice que no
se rompió nada de lo que ya funcionaba.

---

## 44. La foto oficial de quien no tiene ficha (21 ago 2026)

Primero de la lista que dejó medida el nivel 7 de larastan
([12 §5](12-larastan-nivel-7.md)), y el que más pinta tenía por una razón que
resultó ser la correcta: **`save()` sobre `Acudiente|Alumno|Profesor|stdClass`**.
Que el análisis vea un `stdClass` en esa unión significa que hay un camino por el
que lo que se guarda no es un modelo, y `stdClass` no tiene `save()`.

Había dos caminos, no uno.

### El que señaló el análisis

```php
$persona = new stdClass();

switch ($usu->tipo) {
    case 'Alumno':    ... break;
    case 'Profesor':  ... break;
    case 'Acudiente': ... break;
}                      // <- ni rama para 'Usuario' ni default

$persona->foto_id = $img_id ? $img_id : null;
$persona->save();
```

`users.tipo` toma **cuatro** valores —son los del `switch` de
`ContextoDeUsuario`— y el `switch` cubre tres. Con un administrativo,
`$persona` se queda en el `stdClass` vacío de la inicialización y la última línea
es un fatal:

```
Error: Call to undefined method stdClass::save()
```

Medido: **500**. Y no es un fallo que haya que arreglar para que funcione, porque
**la operación no existe**: hay dos imágenes por persona y no una.
`users.imagen_id` es el avatar de la cuenta, y lo cambia la ruta hermana
`cambiar-imagen-un-usuario`, que sí funciona para los cuatro tipos.
`alumnos|profesores|acudientes.foto_id` es la foto **oficial**, la del carné y los
informes, y vive en la ficha. Un `Usuario` administrativo tiene lo primero y no
tiene lo segundo. Así que la respuesta correcta es decirlo —422— y no un fatal.

### El que no señaló, y es el que puede pasar de verdad

`Alumno::where('user_id', $user_id)->first()` devuelve **null** si la cuenta
existe y su ficha no. `null->foto_id` revienta igual. Y esa combinación no es
rara: es exactamente lo que queda cuando se retira a un alumno —la ficha se manda
a la papelera y la cuenta sigue viva—. Ahora es 404.

El análisis no lo vio porque para él `first()` devuelve `Model|null` y el `null`
lo absorbía la misma unión; lo que denunció fue el `stdClass`, que era el más
llamativo de los dos. **Vale la pena el apunte: el nivel 7 señala el camino más
raro y a su lado suele estar el probable.**

### Y una tercera, que ya tenía nombre

El método entero era un `if` sin `else`:

```php
if ($user->tipo == 'Profesor' or $user->is_superuser) {
    ...
    return $persona;
}
// y aquí no hay nada
```

`auth.personal` deja pasar a los 51 profesores **y a los 20 administrativos**, así
que un `Usuario` sin `is_superuser` no cumplía la condición, caía por el final y
recibía **200 con el cuerpo vacío**, con la foto sin tocar. Es la §37 otra vez —la
cuarta cara de «una respuesta que miente»— y ahora es 403.

**No se le amplía nada a nadie**: el que no podía sigue sin poder. Lo único que
cambia es que se entera, y el test comprueba además que la foto sigue como estaba.

### Lo que hay que llevarse

Los tres fallos estaban **en el mismo método**, y ninguna de las herramientas
anteriores podía verlos: el barrido no, porque la ruta lleva `auth.personal` y un
token de familia no llega; los inventarios de autorización tampoco, porque el
guard está puesto y es el correcto. Lo que había aquí no era un agujero de
autorización sino **tres formas de que la respuesta no se corresponda con lo que
pasó**, y eso solo se ve leyendo el método o preguntándole al analizador por los
tipos.

El front no llegaba a ninguno de los tres: `fileManager.html` solo llama a esta
ruta con `alumnoElegido` y `profeElegido`. O sea que estaban esperando a que
alguien la llamara con otra cosa — y la ruta está abierta a los 71.

Fijado por `FotoOficialTest`, cinco casos, comprobado al revés: revirtiendo los
tres arreglos caen tres y los dos que comprueban que la foto se sigue cambiando
—ponerla y quitarla— siguen verdes.

### §43.1. Las dos reglas de procedimiento — **decididas** (21 ago 2026)

La §43 dejó abiertas cuatro cosas que no eran agujeros sino reglas que nadie
comprobaba, y Joseth contestó las dos que importaban. **Se cierran.**

**1. El examen se abre cuando el profesor lo suelta.** `in_action` e `inicia_at`
se comprueban ahora para la familia. Antes se leía el examen antes de que
empezara, que es lo mismo que repartir la hoja el día anterior.

La comprobación va **después** de la de grupo, y el orden es la decisión: quien no
es del grupo recibe «no es de tu grupo» y no «todavía no está abierta», porque lo
segundo confirmaría que ahí hay un examen y cuándo empieza.

Y va **solo para la familia**: el profesor tiene que poder abrirla antes que
nadie, porque eso *es* la vista previa —`actividades.html` enlaza a la misma
pantalla—. Cerrarla para todos habría apagado la única forma que tiene un profesor
de ver su examen como lo verá su clase.

`inicia_at` se compara en `America/Bogota` y no con `now()` a secas, que es la
trampa que [09 §2](09-pendientes.md) lleva documentada: `config/app.php` dice UTC y
el código de siempre escribe en hora de Colombia, así que mientras las dos zonas
convivan comparar una fecha de esta tabla con `now()` la adelanta cinco horas — y
un examen se abriría cinco horas antes sin que fallara nada.

**2. Entregar es entregar.** `finalizar-actividad` ponía `terminado = true` y
**nadie volvía a mirar esa columna**, así que `seleccionar-opcion` seguía borrando
la respuesta anterior y escribiendo la nueva. El profesor corregía lo último que
se escribió, no lo que había al entregar. Ahora es 403.

**La consecuencia se eligió a sabiendas y hay que tenerla escrita: quien entregue
sin querer se queda fuera**, porque hoy no existe ninguna ruta que reabra un
intento. Si eso aparece en un colegio, el sitio del arreglo es una ruta nueva del
profesor —«reabrir el intento de este alumno»— y **no** relajar esta comprobación,
que es lo que se hace cuando corre prisa y deja el agujero otra vez.

### Lo que sigue abierto, y por qué

`oportunidades` y `para_alumnos` **no** se cerraron. El primero es el que más
puede sorprender a un colegio a mitad de periodo —hoy los intentos son ilimitados
y hay clases que cuentan con ello—, y el segundo no tiene un uso claro separado de
`compartida`. Siguen en la tabla del §5 de [09-pendientes.md](09-pendientes.md).

Fijado por `MisActividadesTest`, que pasa de 9 casos a 12: los tres nuevos son
que no se abre sin soltar, que no se abre antes de la hora, y que **sí** se abre
pasada la hora — este último es el que se rompería sin que nadie se enterase—, más
que el personal sigue abriendo la que no está en acción.

---

## 45. `Request::file()` devuelve dos cosas, y el código esperaba una (21 ago 2026)

Segundo de la lista del nivel 7 ([12 §5](12-larastan-nivel-7.md)), y el que venía
señalado **tres veces**: `getRealPath()` y `move()` sobre
`array<UploadedFile>|UploadedFile`, en `ImagesController`, en
`Piars/Utils/UploadDocuments` y en `ImportarController::postCartera`.

`Request::file('file')` devuelve un `UploadedFile` si el cliente manda `file` y un
**array** si manda `file[]`. Los dos puntos de subida del sistema asumían lo
primero. Medido: con dos archivos en el mismo campo, `nombreDisponible()` recibe
un array donde su firma declara `?UploadedFile`, y el TypeError sale como **500**.

**No es un agujero** —no llega a guardarse nada, y la lista blanca de extensiones
sigue intacta— pero es un 500 en la única operación de subida que tiene el
sistema, y desde fuera un 500 no se distingue de «el servidor está caído».

### Dónde va el arreglo, y por qué ahí

En `SafeUpload`, no en cada controlador, por la misma razón que ya tenía escrita
esa clase: *para que no haya dos versiones de la misma regla*. Se añade
`archivoRecibido($campo)`, que lee el campo y devuelve **un** `UploadedFile` o
aborta con 422, y los dos puntos vivos pasan por ella.

**Se rechaza en vez de quedarse con el primero.** Quien manda dos archivos cree
que va a subir dos; guardar uno en silencio es la respuesta que miente otra vez, y
esta vez por adelantado. Ninguna pantalla de los cuatro clientes manda `file[]`,
así que no apaga nada.

`postCartera` se queda como está: lleva rota desde el salto a maatwebsite 3.x por
la firma de la 2.x ([§8](05-codigo-muerto-y-roto.md)), y ponerle un 422 delante de
un 500 que ya existe no arregla nada — qué debe hacer esa importación sigue siendo
una decisión del colegio.

### Y una cosa que salió al comprobarlo al revés

La comprobación se escribió con dos ramas —`is_array()` primero, `instanceof`
después— y al desactivar la primera **el test seguía verde**: el `instanceof`
también rechaza un array. O sea que esa rama no decide el control, decide el
**mensaje**.

Se queda, y por eso está escrito en el código: «sube los archivos de uno en uno» y
«no se recibió un archivo válido» le dicen cosas distintas a quien las lee, y la
segunda manda a buscar el fallo donde no está. Pero la distinción importa para el
que venga: **la línea que se puede quitar sin que falle un test no siempre es la
que sobra** — a veces es la única que explica.

Y el orden del reverso importó: quitar esa rama no probaba nada, y hubo que
revertir la llamada entera a `Request::file()` para ver el 500. **Comprobar al
revés solo vale si se revierte lo que de verdad cambió el comportamiento.**

Fijado por `SubidaDeArchivosTest`: se sube una imagen, dos son 422 y no se guarda
ninguna, ninguna sigue siendo 422, y un `.php` disfrazado sigue sin entrar — este
último porque el atajo nuevo no puede saltarse la lista blanca.

---

## 46. Lo que depende de dónde corre, y por eso no se ve (21 ago 2026)

No es un hallazgo: es la forma que tuvieron **cuatro** de los tropiezos del día,
y se escribe porque cuesta más reconocerla que arreglarla. En los cuatro había
algo correcto en el código y equivocado en el **contexto en que se ejecutaba**, y
en los cuatro el síntoma fue el mismo: *no falla nada, y el número que sale es
plausible*.

| Lo que dependía del contexto | Cómo se manifestó |
|---|---|
| **El directorio de trabajo.** Los controladores de imagen escriben en `images/perfil/...`, que es relativo: por HTTP es `public/`, en phpunit es la raíz del repo | Un test de subidas dejó cinco `.jpg` dentro del repo. `ImagenesTest` ya se mudaba a un temporal por esto mismo, con su porqué escrito, y aun así el siguiente que tocó subidas volvió a caer |
| **Qué migraciones tiene la base contra la que corres.** Tres sesiones, tres bases | Una vez **no cambió ningún resultado** (faltaba el rol `Secretario`, que ningún test exigía) y otra **rompió cinco tests**. Misma causa, consecuencias opuestas, y ninguna visible sin mirar la tabla `migrations` |
| **El año en el que el login deja al usuario.** `Services\Login` reescribe `users.periodo_id` al periodo del año `actual` al entrar | Un test montaba el examen en un año y lo pedía desde otro, con lo que el 403 salía por el año y no por lo que el test decía comprobar. Y un candidato de votación fuera del año **desaparecía de la papeleta en silencio**, dejando un `assertNotEmpty` que pasaba sin mirar nada |
| **El fichero de medición y quién más lo tiene abierto.** `FILE_APPEND | LOCK_EX` no protege de un `rm -f` de otra sesión | La cobertura salió **86 de 539 cuando eran 346**, con 135 casos de 588 registrados |

Lo que las une, y lo que hay que preguntar cuando algo salga raro: **¿esto lo
decide el código, o lo decide el sitio donde el código está corriendo?** Si es lo
segundo, leer el código no lo va a contar, y el test tampoco — porque el test
corre en el mismo sitio.

Y de ahí la única defensa que ha funcionado hoy: **afirmar sobre el contenido y no
sobre la forma**. No `assertNotEmpty`, sino el nombre del candidato que se acaba de
insertar; no «responde 200», sino el enunciado de la pregunta que tenía que llegar
—o no llegar—. Es el mismo criterio con el que se escribieron los tests de
contrato, aplicado a la trampa de un nivel más abajo.

---

## 47. Tres llamadas del interruptor del periodo que no estaban en la cuenta (21 ago 2026)

Sale de la cobertura: `UnidadesController` tenía **7 de sus 11 rutas** sin
ninguna respuesta comprobada, y es el controlador de la **rejilla con la que se
calcula la nota** de una asignatura — cada unidad lleva un `porcentaje`.

La §27.1.1 dejó el interruptor puesto en «25 de 26 llamadas». **Eran más de 26.**
Estas tres no estaban en la lista:

| Ruta | Con el periodo cerrado devolvía |
|---|---|
| `POST unidades` — crear una unidad | **201**, con su porcentaje dentro |
| `PUT unidades/update-orden` — reordenarlas | **200**, y el orden escrito |
| `PUT unidades/restore/{id}` — sacarla de la papelera | **200**, y la unidad de vuelta en la rejilla |

Medido, no supuesto. Y lo que lo vuelve una incoherencia y no una decisión es que
**los métodos de al lado sí lo piden**: `unidades/update` y `unidades/destroy`
llevan `pueden_editar_notas` desde la §27, así que con el periodo cerrado se podía
**crear** una unidad y no **editarla un segundo después**.

### Lo que lo hizo decidible: el controlador gemelo

`SubunidadesController` es el mismo controlador un nivel más abajo, y ahí las dos
llamadas equivalentes **sí están**:

- `subunidades/store` comprueba el periodo al crear; `unidades` no lo hacía.
- `subunidades/update-orden-varias` lo comprueba al reordenar —con su comentario
  de la §27 al lado, «hay dos periodos que mirar y tienen que estar abiertos los
  dos»—; `unidades/update-orden` no.

O sea que no hace falta decidir nada nuevo: la §40 ya fijó que **el interruptor
cierra las notas**, y una unidad es una nota. Lo que faltaba era aplicarlo.

Es la forma de la §17 —*ser la única de su familia sin la comprobación*— pero
sobre el interruptor del periodo en vez de sobre un guard, y por eso el candado de
familia de `AutorizacionTest` no la veía: ese mira middlewares en la tabla de
rutas, y esto ocurre dentro del método.

### El periodo de una fila que todavía no existe

`postIndex` no puede derivar el periodo de la fila que toca, porque la crea. Pero
no hace falta: la unidad nace con `periodo_id = $user->periodo_id` tres líneas más
abajo, así que **el periodo del usuario ES el de la fila**. Es el único caso de la
§27 donde preguntar por `$user->periodo_id` es la respuesta correcta y no el atajo
que la §27.1 descartó.

`putUpdateOrden` sí puede, y pide **todos** los periodos de las unidades que mueve
—`PeriodoDeLaFila::deVariasUnidades()`, el mismo que usa su gemelo—, porque un
reordenamiento puede tocar unidades de más de uno.

`putRestore` deriva de la unidad borrada, y funciona porque `deUnidad()` **no
filtra `deleted_at`** — algo que ya estaba escrito en su docblock, decidido para
que los `forcedelete` pudieran derivar. Aquí se cobró ese diseño sin tocarlo.

### Y un 500 de la misma familia que la §44

`putUpdateOrden` hacía `Unidad::find((int)$key)` y escribía `->orden` sobre el
resultado. Con un id que no existe, `find()` devuelve null y era un **500** — que
tapaba lo único que había pasado de verdad: el cliente nombró una unidad que no
está. Ahora es 404.

### §47.1. Y entonces se contó bien

Encontrar tres que no estaban en la cuenta dijo algo más importante que las tres:
**la cuenta estaba mal**, y una lista hecha a mano que se da por completa es
exactamente lo que la §0 de [09](09-pendientes.md) ya había avisado que acaba
teniendo un hueco invisible.

Así que se contó a máquina: **todos los métodos de `app/Http/Controllers` que
escriben en `unidades`, `subunidades`, `notas`, `notas_finales`,
`recuperacion_final` o `nota_comportamiento`**, y cuáles piden el interruptor.

**17 métodos escriben en la rejilla. 11 lo piden, 6 no.** De esos seis:

| Método | Qué es |
|---|---|
| `SubunidadesController::putRestore` | **Gemelo exacto** del de unidades: `UPDATE subunidades SET deleted_at=NULL` a pelo, mientras `putUpdate` y `deleteDestroy` en el mismo fichero sí lo piden. **Arreglado aquí** |
| `UnidadesController::getDeAsignaturaPeriodo` | El GET que escribe de la [§16](05-codigo-muerto-y-roto.md): crea las unidades por defecto cuando no hay ninguna. **Sin decidir** — ver abajo |
| `Informes/BoletinesController::putDetailedNotas` | La causa principal del borrado de definitivas, con su propio `// CALCULAMOS SIN VERIFICAR` al lado. **Parado** por la decisión del §4 de 09 |
| `DefinitivasPeriodosController::putCalcularGrupoPeriodo` | Misma zona de definitivas. **Parado** |
| `NotasController::putDetailed` | Misma zona. **Parado** |
| `NotasController::putSubunidad` | El que no guarda nada porque la consulta está en comillas dobles con sintaxis de simples. **Roto y documentado** |

O sea que de los seis, **uno era un arreglo, cuatro están parados por decisiones
ya tomadas, y uno hay que preguntarlo**. Que cuatro de los seis caigan en la zona
de definitivas no es casualidad: es la misma zona que el §4 de 09 dejó congelada
**porque seis sitios escriben en `notas_finales` con cinco criterios distintos**,
y el interruptor es un sexto criterio que tampoco comparten.

### Lo que hay que preguntar

`GET unidades/de-asignatura-periodo` **crea unidades** cuando la asignatura y el
periodo no tienen ninguna, copiándolas de las del año. Con el periodo cerrado, hoy
las crea igual.

**No se cierra sin decidirlo**, y la razón es que la pregunta no es «¿debe
escribir con el periodo cerrado?» —claramente no— sino **qué debe devolver
entonces**. Es la pantalla con la que un profesor mira la rejilla, así que
responderle 400 le apaga la vista de un periodo cerrado, que es justo lo que
querrá consultar cuando esté cerrado. Las dos salidas razonables —devolver la
lista vacía sin crear nada, o crear igual porque son unidades por defecto y no
notas— cambian lo que ve la pantalla, y eso lo decide el colegio. Va a la tabla
del §5 de [09-pendientes.md](09-pendientes.md).

### §47.2. La rejilla que se lee — **decidido** (21 ago 2026)

Joseth contestó: con el periodo cerrado, **enseña lo que hay y no crea nada**. Ni
400 —que le apagaría al profesor la vista de un periodo cerrado— ni seguir
creando.

Como la ruta lee y de paso escribe, no le sirve el `abort()` de sus hermanas.
Hace falta preguntar sin abortar, y eso es `User::permiteEditarNotas()`, que
repite la forma de `pueden_editar_notas()` en vez de compartirla: aquélla
distingue 400 de 403 y ésta solo dice sí o no, así que unificarlas obligaría a que
una de las dos dejara de decir lo que dice.

**Y al ponerlo apareció la otra mitad, que no estaba en la pregunta.**
`Unidad::arreglarOrden()` no ordena la respuesta: **reescribe `orden` en la tabla**
—de todas las unidades y de todas sus subunidades— **en cada lectura**. O sea que
este GET escribía en la rejilla siempre, hubiera unidades o no.

Eso convierte el arreglo en obligatorio y no en opcional: sin él, la §47 habría
dejado `unidades/update-orden` tapado y **el mismo cambio abierto por el camino
del GET**. Es la lección de la §36 —la misma protección, dos caminos, y solo uno
cubierto— pero creada por el propio arreglo de hace una hora, que es la forma más
fácil de que vuelva a pasar: **al tapar un camino hay que preguntarse cuál es el
otro**.

### Y el test que pasaba por el motivo equivocado

El caso de «no crea nada» **pasaba también con el arreglo desactivado**, y solo se
vio al comprobar al revés: de los dos, caía uno. La razón es la §21.5 otra vez —
`unidades_por_defecto` es una de las tablas que el seed trae vacías, así que el
método salía por su `return ''` sin llegar nunca a la rama que crea. El test no
comprobaba «no crea», comprobaba «no había nada que copiar».

Van ya tres veces en un día que el seed vacío deja verde un test que no mide nada.
Y las tres se cazaron igual: **contando cuántos caen al revertir**. Si el arreglo
tapa dos caminos, tienen que caer dos.

Fijado por `UnidadesTest`, catorce casos. Comprobado al revés: revirtiendo los cinco
arreglos caen cinco, y los seis que comprueban que con el periodo **abierto** se
sigue creando, reordenando y restaurando siguen verdes — que es lo que dice que no
se cerró de más.

---

## 48. El detector que no se encontraba a sí mismo (21 ago 2026)

`tools/respuestas-que-mienten.py` salió de la §37 y busca **un `if` de permiso que
envuelve el método entero y no tiene `else`**. Corrido hoy: **cero**.

Y sin embargo la [§44](05-codigo-muerto-y-roto.md), encontrada esta misma tarde
leyendo el método a mano, **es exactamente esa forma**. O sea que el detector no
encontraba un caso que cumplía su propia definición.

### Por qué se le escapaba

Por una línea. El script admitía **una sola** línea de preámbulo antes del `if`
—la resolución del usuario— y `putCambiarFotoUnUsuario` tiene dos:

```php
$user = User::fromToken();
$usu  = User::findOrFail($user_id);   // <- la segunda
if ($user->tipo == 'Profesor' or $user->is_superuser) {
```

Con la segunda línea, el `if` ya no estaba donde el script miraba y el método se
descartaba entero. Ahora se salta **todo** preámbulo que sea comentario o
asignación de una línea.

Es la misma forma que llevamos encontrando todo el día en el código, aplicada a la
herramienta: **un criterio correcto con un límite arbitrario dentro**, y el límite
no se ve porque lo que deja fuera no aparece en ninguna lista.

### Lo que apareció al ensancharlo

Tres casos. Uno es de `actividades`, que llevaba otra sesión. Los otros dos son
`ChangeAskedAssignment::putSolicitarMateria` y `::putPedirQuitarAsignatura`:
devolvían **200 con `['msg' => 'No puedes']`** y no escribían nada.

**Y el front convierte eso en algo que se ve.** `ListAsignaturasCtrl` hace:

```js
.then(function(r){ $ctrl.pedidos.push(r.pedido); })
```

Con esa respuesta `r.pedido` es `undefined`, así que mete un `undefined` en la
lista y pinta **una solicitud en blanco** que desaparece al recargar. Quien lo veía
es el administrativo, que `auth.personal` deja pasar.

El criterio no cambia —solo el docente pide cambios de asignatura, porque un
superusuario no pide, hace— y no se le amplía nada a nadie. Lo que cambia es que
ahora es 403 y se dice.

### Y un falso positivo que enseñó dónde era flojo

Al ensanchar el preámbulo apareció también `ChangeAskedController::putRechazar`,
que **no** es un fallo: su condición es `$tipo == "img_perfil"` —qué campo del
pedido se rechaza—, negocio y no permiso. El patrón de permiso decía `tipo\s*==`,
que casa con las dos cosas.

Lo interesante es **por qué no había salido antes**: con una sola línea de
preámbulo, el `$tipo = Request::input('tipo');` de encima tapaba el `if` y el falso
positivo nunca llegaba a verse. O sea que el límite estrecho estaba **escondiendo
un fallo del patrón**, no protegiendo de él. Ajustado a `->tipo ==`.

**Ensanchar un detector enseña dónde era flojo**, y las dos cosas que enseña —lo
que dejaba fuera y lo que dejaba entrar mal— salen a la vez.

Fijado por `PedidosDeAsignaturaTest`, cuatro casos. Comprobado al revés:
revirtiendo los dos arreglos caen dos —los dos del administrativo— y los dos que
comprueban que el docente sigue pidiendo siguen verdes.

### §48.1. Y la lista de «ramifica por Profesor y no tiene salida», mirada una a una

La otra sesión propuso una pista: los `if ($user->tipo == 'Profesor')` **sin
`else`** serían huecos como el de la §44. Se midió por separado en las dos
sesiones y los números no coincidían, así que se volvió a contar aquí emparejando
llaves en vez de con una ventana de líneas:

| Cómo se resuelve el usuario | Total | Con `else` | Sin `else` |
|---|---|---|---|
| `$user = User::fromToken()` — el estilo de antes | 24 | 15 | **9** |
| `$this->user` — el trait de la migración | 16 | 16 | **0** |

**La correlación es real y se confirma**: ninguna rama escrita contra el trait
tiene el hueco. (Y hubo que quitar dos falsos positivos del propio script: buscaba
el `if` hacia atrás y se tragaba la palabra «if» escrita **dentro de un
comentario** — uno de ellos, el de la §44, comentando que allí *había habido* un
`if` sin `else`.)

**Pero las nueve se miraron una a una, y ninguna es un hueco.** Es lo que hay que
apuntar, porque la conclusión fácil era la contraria:

| Dónde | Qué es de verdad |
|---|---|
| `AsignaturasController:226`, `GruposController:50`, `ImagesController:44` | **Enriquecen la respuesta**: al docente se le añaden sus pedidos, sus grupos de titularía o las imágenes públicas. Quien no es docente no necesita `else` porque no le falta nada |
| `NotasController:195` y `:247` | **Acotan**: `$profesor_id` se inicializa vacío arriba y se rellena si es docente. El `else` está escrito como valor por defecto, tres líneas antes |
| `PerfilesController:416`, `TSubirController:71` | **Sí cortan, con `abort()`**. Son guards que funcionan; lo que no tienen es `else`, que no es lo mismo |

O sea que el patrón sintáctico —«ramifica por Profesor y no tiene salida»— tiene
**precisión cero** una vez se mira qué hace cada rama. Lo que separa la §44 y las
dos de la §48 de estas nueve no es que no tengan `else`: es que **el `if` envuelve
el método entero y dentro hay una escritura**. Que son exactamente las dos
condiciones que `tools/respuestas-que-mienten.py` ya exige, y por eso encontró
tres y no doce.

**La lección, que es la contraria de la que apetecía sacar:** una correlación
limpia —0 de 16 contra 9 de 24— invita a leerla como un marcador de riesgo, y aquí
solo marca **estilo**. El código viejo resuelve el usuario con `$user =` y también
escribe ramas que enriquecen; las dos cosas van juntas sin que una cause la otra.
Mandar a alguien a auditar esas nueve habría sido gastar una tarde en confirmar
que están bien.

### §48.2. Tres medidas del mismo patrón, y la que mentía de verdad

La otra sesión volvió a medir aceptando también `abort` y `return` como salida
—que es lo correcto: no tener `else` no es no tener salida— y el patrón se encogió
otra vez:

| Cómo se midió | «Sin salida» |
|---|---|
| ventana de 25 líneas | 12 |
| emparejando llaves | 9 |
| llaves **y** `abort`/`return` como salida | **6** |

Y de las seis, ninguna es un hueco. **En todo el repo queda una sola que lo sea, y
no la encontró el patrón: la encontró alguien leyendo.**

De camino se cayó el ejemplo que más prometía. Las cuatro de `CalendarioController`
parecían las buenas —el `if` envuelve el método, dentro hay un `INSERT`, y encima
hay un ternario `$user->tipo == 'Usuario' ? …` **dentro** de la rama que
supuestamente excluye a los `Usuario`, o sea el autor contando con ellos y el
guard olvidándolos—. **El test lo desmintió**: hay un `abort` veinticinco líneas
más abajo, y el ternario tampoco probaba nada porque **los superusuarios son de
tipo `Usuario`** y entran por el `|| $user->is_superuser` de al lado.

Sus cuatro `abort` devuelven **404** donde tocaría 403. **No se cambian**, y la
razón está en CLAUDE.md: los códigos correctos son para el código nuevo, y el
legacy no se toca por cosmética. Un 404 y un 403 fallan igual en el front —los dos
rechazan la promesa— así que cambiarlos sería mover el contrato de dieciséis
colegios a cambio de nada. Queda medido y escrito, que es lo que hacía falta.

### La que sí mentía

`putCambiarFirmaUnProfe`, la hermana de la foto de la §44, con una vuelta más:
aquí el `else` **existía** y devolvía la cadena `'No tienes permiso'` **con 200**.

Es peor que no tenerlo. El front hace `.then()` y dentro **pinta la firma como
cambiada**: mueve la imagen de la lista de privadas a las del usuario y actualiza
`firma_id` en pantalla. Así que al administrativo que `auth.personal` deja pasar se
le enseñaba la firma puesta, y al recargar no estaba. Ahora 403.

**Y por eso el detector no la veía**: `respuestas-que-mienten.py` descarta el
método en cuanto encuentra la palabra `else`, porque su criterio es «el `if`
envuelve el método y no hay salida». Aquí la salida existe — lo que miente es lo
que devuelve. Son dos fallos distintos con el mismo efecto, y el segundo necesita
otra pregunta: **no «¿hay `else`?» sino «¿el `else` dice la verdad?»**, que es
mirar el código de respuesta y no la forma del bloque.

Lo que queda de toda esta serie, en una línea: **buscar el `else` que falta no es
buscar el fallo.** Lo que distingue un hueco son dos condiciones juntas —el `if`
envuelve el método entero y dentro está lo único que el método produce— y las dos
las exigía ya la herramienta, que por eso encontró tres y no doce.

Fijado por `FotoOficialTest`, que pasa de cinco casos a siete.

### Y una mina que hay que tener escrita antes de escribir ningún guard ahí

`ws_actividades.created_by` guarda **`persona_id`**, mientras `ws_preguntas.added_by`
guarda **`user_id`**. Son dos numeraciones distintas en dos columnas que se llaman
casi igual y viven en tablas hermanas.

Un guard de propiedad escrito de la forma natural —comparar `created_by` con
`$user->user_id`— daría un `WHERE` que **no casa nunca**: un permiso que deniega
todo. Y eso no se reporta como «el guard está mal», se reporta como **«la pantalla
no funciona»**, que manda a buscar a otro sitio. Es la §41.1 —el id sin su tabla no
es una identidad— con las dos numeraciones dentro del mismo módulo. Lo midió la
sesión de actividades; el detalle está en [13-actividades.md](13-actividades.md).

---

## 49. El pedido de otro, retirado por su número (21 ago 2026)

Sigue la lista de cobertura: `ChangeAskedController` tenía **cinco rutas sin
ninguna respuesta comprobada**, y dos de ellas —`ChangesAsked/destruir` y
`ChangesAsked/destruir-pedido-asignatura`— recibían `asked_id`, `data_id` y
`assignment_id` **por el cuerpo** y borraban las tres filas sin mirar de quién
eran.

Medido con dos profesores: **200, y la fila del otro borrada.**

Lo que hace que importe no es el borrado en sí, es **quién no se entera**: un
pedido de cambio es cómo un docente o una familia piden que se corrija un nombre,
una foto o una asignatura, y quien los revisa los ve en una bandeja. Un pedido
retirado por un tercero no deja rastro en ninguna parte — no hay `deleted_at`,
es un `DELETE`. Para el que lo pidió, sigue esperando; para el que revisa, nunca
existió.

### El criterio no se eligió: lo dicen los dos únicos sitios que llaman

Y se confirman entre ellos, que es lo que lo vuelve un arreglo y no una decisión:

- `ListAsignaturasCtrl.quitarSolicitud` retira **el suyo**, desde la lista del
  propio docente.
- El modal de `AnunciosDir` se abre desde el panel de revisión, y ese panel es
  `getToMe()`, que exige `Usuario` **y** `is_superuser`.

O sea: **el dueño o el superusuario**, y nadie más. Ningún cliente pide otra cosa.

### Y la §39 otra vez, en la misma familia

Los otros dos identificadores —`data_id` y `assignment_id`— también venían del
cuerpo, así que aun siendo el dueño del pedido se podían borrar los anexos **de
otro** con solo nombrarlos. Ahora se derivan de la fila.

Es exactamente lo que la [§39](05-codigo-muerto-y-roto.md) encontró un día antes
en `aceptar-alumno` y `aceptar-asignatura` —aprobar un cambio escribía lo que
dijera el cuerpo— y **está en el mismo controlador**. La §39 arregló las dos de
aceptar y las dos de destruir se quedaron: no por descuido de aquella pasada, sino
porque **la §39 salió de leer las de aceptar y nadie preguntó por las hermanas**.
Es la §17 —la que se quedó sola— vista desde el otro lado: allí faltaba el guard
en la ruta, aquí falta la comprobación en el método hermano.

Y de paso, un pedido que no existe devolvía **200 con `['borrar' => 0]`**: tres
`DELETE` que no borran nada y una respuesta que no lo dice. Ahora 404.

Fijado por `RetirarPedidosTest`, cinco casos: el dueño retira, un tercero no, el
superusuario sí, el anexo ajeno no se borra nombrándolo, y el pedido inexistente
es 404. Comprobado al revés: revirtiendo las dos comprobaciones caen dos, y las
tres que dicen que el dueño y el superusuario siguen pudiendo siguen verdes.

---

## 50. Y las otras tres del mismo controlador (21 ago 2026)

La §49 cerró las dos de `destruir`. Quedaban tres rutas de
`ChangeAskedController` sin ninguna respuesta comprobada, y **dos de ellas tenían
la misma forma**, así que se miraron a la vez en vez de una por sesión — que es lo
que había dejado a la §39 sin terminar.

**`ChangesAsked/rechazar` no comprobaba nada**: ni quién rechaza, ni de quién es
el pedido, y el `data_id` sobre el que escribía venía del cuerpo. Cualquiera de
los 71 que pasan `auth.personal` rechazaba el pedido de cualquiera.

Rechazar es una operación de la bandeja de revisión, y **esa bandeja es
`getToMe()`, que exige `Usuario` y `is_superuser`**. Eso es lo que hace el cierre
barato de justificar: quien no es superusuario **no puede ni ver la lista desde la
que se rechaza**, así que restringirlo no le quita nada por el front — cierra la
llamada directa y nada más. Su único llamante, el modal de `AnunciosDir`, se abre
desde ahí.

**`ChangesAsked/ver-detalles` tampoco miraba de quién era el pedido**, y aquí el
criterio es **otro a propósito**: el de la §49, el dueño **o** el superusuario, y
no «solo el superusuario» como en rechazar. Leer lo suyo es legítimo; rechazar lo
suyo no significa nada. Dos rutas del mismo controlador con criterios distintos
porque las operaciones son distintas, y eso hay que escribirlo o el siguiente las
unifica «por coherencia».

Y `ChangeAskedDetails::detalles()` indexa con `[0]` el resultado de su consulta,
así que un id que no existe era un **500**. Es la tercera vez hoy —§44, §47 y
ésta— que el mismo `[0]` sobre una consulta vacía tapa que el cliente nombró una
fila que no está. Ahora 404.

### Lo que hay que llevarse de las tres juntas

`data_id` derivado del cuerpo y no de la fila va **tres veces en este mismo
controlador**: la §39 en `aceptar-alumno` y `aceptar-asignatura`, la §49 en los dos
`destruir`, y ésta en `rechazar`. Cinco métodos, un solo error, encontrado en tres
pasadas distintas con un día de diferencia.

No es que las pasadas anteriores fueran flojas: es que **cada una entró por una
ruta y arregló lo que esa ruta tocaba**. La pregunta que las habría juntado no es
«¿está bien esta ruta?» sino **«¿qué más lee este identificador del cuerpo?»**, y
esa se contesta de una vez y no cinco.

### La quinta, y el `[0]` que va por su cuarta vez

`ChangesAsked/solicitar-cambios` —la pantalla «pedir que me corrijan mis datos»—
cierra el controlador. Con un `persona_id` que no existe, o de un alumno en la
papelera, `first()` devuelve null y la primera comparación era un **500**. Ahora
404, y con eso van **cuatro en un día**: §44, §47, §50 y ésta. Siempre lo mismo —
una fila que no está sale como error del servidor en vez de como «eso no existe»,
y quien lo lee busca el fallo en el sitio equivocado.

Y dos cosas que se aprendieron mirándola y que **no** son fallos, porque merecía
la pena comprobarlo antes de tocar:

- **`$tipo == 'Al'` no compara con `users.tipo`.** Esa columna vale `'Alumno'`, así
  que la condición parecía imposible de cumplir y el método, muerto. No lo está:
  `'Al'` es el **código corto del front** —`'Pr'`, `'Acu'`, `'Usu'` son los otros—
  y `userConfiguracion.html` lleva `ng-if="perfilactual.tipo=='Al'"`, o sea que esa
  pantalla solo se pinta para alumnos. La rama única del controlador es el reflejo
  de eso. **Dos vocabularios para lo mismo en los dos lados de la API**, y leer uno
  con el diccionario del otro da un hallazgo que no existe.
- **`persona_id` no decide de quién es el pedido.** El pedido se archiva siempre
  contra `$user->user_id` —lo hace `crear_o_modificar_datos_de_pedido()`—, así que
  el parámetro solo elige **contra qué ficha se compara** lo que se manda. Medido:
  con el id de otro, el pedido sale igual a nombre de quien llama. No es un
  agujero, pero el nombre del parámetro promete otra cosa, y el siguiente que lo
  lea va a creer que ahí se elige el sujeto.

Fijado por `RetirarPedidosTest`, doce casos.

---

## 51. El expediente del docente, por la pantalla de asignar materias (21 ago 2026)

`AsignaturasController` tenía **seis de sus catorce rutas** sin ninguna respuesta
comprobada. La que llena la pantalla de asignar materias a grupos,
`PUT api/asignaturas/datos-asignaturas`, devuelve tres cosas: materias, grupos y
`Profesor::contratos($user->year_id)`.

Y `contratos()` devuelve, **de cada docente del colegio**: `tipo_doc`, `num_doc`,
`ciudad_doc`, `fecha_nac`, `ciudad_nac`, `estado_civil`, `barrio`, `direccion`,
`telefono`, `celular`, `facebook`, `email`, el `email` de su cuenta, su `username`
y su `is_superuser`.

Para una pantalla cuya parte de docentes es **un desplegable con nombres**.

### Lo que decide el recorte no es una opinión: es el propio front

No hizo falta juzgar qué sobra. `AsignaturasCtrl.ts` declara su interfaz:

```ts
interface ProfesorDeAsignatura { profesor_id: number; nombres?: string; apellidos?: string; … }
```

y su plantilla usa exactamente `profe.nombres`, `profe.apellidos` y
`profe.foto_nombre`. Cuatro campos. El resto no lo lee nadie.

### Dónde va el recorte, y dónde NO

En un método nuevo, `Profesor::paraElegirEnAsignaturas()`. **`contratos()` se queda
como está**, y eso es lo importante: su otro llamante es `GET api/contratos`, que
**lo lee la app de Flutter desde pantallas de familia** y cuyo recorte lleva desde
la §14.4 esperando una decisión del colegio en el §5 de
[09-pendientes.md](09-pendientes.md).

Recortar el método compartido habría sido cómodo —una consulta en vez de dos— y
habría metido esa decisión **por la puerta de atrás y en los dieciséis colegios a
la vez**, que es exactamente lo que el §5 existe para impedir. Dos consultas
parecidas conviviendo es el precio de que la decisión siga siendo de quien le toca.

Y hay un test que lo vigila por el otro lado:
`test_contratos_sigue_devolviendo_el_expediente` **afirma que `GET api/contratos`
todavía manda `num_doc`**. Si algún día falla, es que alguien recortó el método
compartido sin pasar por la decisión — el día que se decida, se cambia el test y se
nota que se decidió, en vez de arrastrarse.

### Y lo que esto dice de la serie del §14

La §14 barrió las lecturas con un token de **alumno** y cerró siete rutas que
entregaban datos personales a quien no debía. Ésta no salió allí, y no podía:
lleva `auth.personal`, así que el barrido no llega. Es de otra familia — no «quién
alcanza esto» sino **«cuánto de más devuelve esto a quien sí puede pedirlo»**, que
ninguna de las herramientas de la serie mide y que solo se ve comparando la
respuesta con lo que la pantalla pinta.

Fijado por `AsignaturasDatosTest`, tres casos. Comprobado al revés: devolviendo la
llamada a `contratos()` cae uno, y los otros dos —que la pantalla sigue recibiendo
sus cuatro campos y que `contratos()` sigue intacto— siguen verdes.

---

## 52. El bucle de reordenar, copiado en cinco controladores (21 ago 2026)

La §47 arregló un `Unidad::find()` sin comprobar dentro de `unidades/update-orden`
—500 donde tocaba 404— y se dio por un caso suelto. **No lo era.**

Al contar los `Modelo::find()` sin `OrFail` del repo salió que **de los diez sitios
donde el resultado se usa en la línea siguiente sin comprobarlo, seis son este
mismo bucle**, copiado y pegado en `areas`, `materias` (dos veces) y `subunidades`
(tres). Cinco arreglados aquí; el sexto era el de la §47.

### Cómo se llegó al número, porque las dos primeras cuentas estaban mal

Se contó tres veces y las tres dieron distinto:

| Medida | Sitios |
|---|---|
| «sin comprobación en las 3 líneas siguientes» (otra sesión) | 47 |
| lo mismo, aceptando `if ($x)`, `empty()`, `is_null()` y `??` | 37 |
| «sin comprobar en 15 líneas y usado como objeto» | 20 |
| «el resultado se usa en la LÍNEA siguiente como objeto» | 10 |

**Ninguna de las cuatro es «la buena», y creerlo fue el error.** Este documento
llegó a decir que la de 10 lo era, por ser la más estrecha y la más limpia. La
otra sesión lo refutó con un contraejemplo **probado con test**:
`ConfigCertificadosController::putUpdate` hace el `find()`, deja una línea en
blanco, abre un `if` que no toca la variable, y la usa en la siguiente. Era un 500
de verdad, y el criterio de la línea de al lado lo descarta.

Así que cada una falla por su lado y **en direcciones contrarias**:

- Las anchas tienen **recall alto y precisión baja**: contaban como fallo cosas
  con la comprobación una línea más abajo, en forma positiva (`if ($x)`) o
  repartida (`if (!$rol || !$per)`, donde la variable señalada no es la primera).
  Las dos sesiones cayeron en esa segunda variante **por separado**.
- La estrecha tiene **precisión alta y recall bajo**: no ve nada que no esté pegado
  a la línea siguiente, y el patrón real no siempre lo está.

Lo único que lo resolvió fue **leer los sitios uno a uno y escribir el test**. De
los veinte de la medida ancha, nueve sobrevivieron a la lectura y de esos **uno era
falso positivo al leerlo** —el `Role::find()` de arriba—.

Vale la pena tenerlo junto a la §48.2, porque son la misma lección desde los dos
lados: allí un patrón **inflaba** la lista y aquí otro la **encoge**, y las dos
veces el número parecía una respuesta. **Un detector da una lista de sitios donde
mirar, nunca una lista de fallos.**

### Y una que salió escribiendo el test

Las cuatro rutas hacen lo mismo y **`materias` recibe el cuerpo de otra forma**:
las otras tres leen `sortHash` plano y ésa lo lee dentro de `partFrom`. El test se
escribió con el cuerpo plano para las cuatro y materias devolvió **500** — pero por
`$partFrom['sortHash']` sobre null, no por el `find()`. O sea que **casi se apunta
como arreglado algo que no se había probado**, y lo que lo delató fue que el 500
siguiera ahí después del arreglo.

Es la §23 —la misma clave leída de dos maneras en sitios distintos— dentro de un
bucle que por lo demás está copiado y pegado. Cinco copias de las mismas doce
líneas y una de ellas con otro contrato de entrada: el copiar y pegar no conserva
lo de fuera.

Fijado por `ReordenarTest`, seis casos —las cuatro rutas con un id que no existe, y
dos que comprueban que reordenar de verdad sigue funcionando—. Comprobado al revés:
devolviendo los `find()` caen tres, que son los tres controladores que se tocaron
aquí; el cuarto sigue verde porque su arreglo es el de la §47.

### Los que no son el bucle

Leídos uno a uno, y separados por lo que se sabe de cada uno:

**Arreglados** —el identificador viene de fuera y se usa como objeto acto seguido,
así que un id inventado era 500—: `ciudades/actualizar-ciudad`,
`ciudades/actualizar-departamento`, `roles/addroletouser/{role_id}` y
`roles/removeroletouser/{role_id}`. Los dos de roles exigen ya administrar
usuarios, así que el 500 no lo alcanzaba cualquiera; los de ciudades llevan
`auth.personal`. Fijados por `IdentificadoresQueNoExistenTest`.

**Sin tocar, porque no es un id de fuera sino una fila que puede no tener cuenta**:
`AlumnosController:719` y `ProfesoresController:344` hacen
`User::find($persona->user_id)` y revientan cuando esa persona **no tiene usuario**.
Ahí 404 sería la respuesta equivocada —el alumno existe, lo que no existe es su
cuenta— y lo correcto es saltarse la parte de usuario o crearla, que es una
decisión y no un arreglo. Queda anotado.

**Sin tocar, porque el id sale de una consulta previa**: `PlanillasController:128`
y `YearsController:107` buscan una fila que se acaba de leer o de crear. Se pueden
blindar, pero hoy no hay camino conocido por el que sean null.

---

## 53. La pregunta del identificador, contestada de una vez (21 ago 2026)

La §50 no cerró un fallo: cerró **el quinto sitio** de un mismo fallo, y dejó
escrito por qué había hecho falta tres pasadas para eso.

> No es que las pasadas anteriores fueran flojas: es que cada una entró por una
> ruta y arregló lo que esa ruta tocaba. La pregunta que las habría juntado no es
> «¿está bien esta ruta?» sino **«¿qué más lee este identificador del cuerpo?»**,
> y esa se contesta de una vez y no cinco.

Esto es esa pregunta, contestada. Salieron **tres**, y ninguno de los tres estaba
en el controlador que se había repasado ya.

La herramienta es `tools/identificadores-del-cuerpo.py`: por cada ruta, qué
identificadores llegan por el cuerpo, cuáles no se leen además de ninguna fila y
si el método comprueba propiedad. Da **231 rutas**, que es una lista para mirar y
no una lista de fallos —la lección de la §52, otra vez—; lo que la vuelve
manejable es filtrarla por quién puede llegar, porque «cualquiera de los 71 del
personal configura el colegio» ya está decidido que se queda (09 §0).

### 53.1 El sexto `asked_id`, en el controlador de al lado

`PUT ChangesAskedAssignment/ver-detalles` es **la copia literal** de
`ChangeAskedController::putVerDetalles`, la que arregló la §50, con la
comprobación quitada. Medido con token de profesor, uno al lado del otro:

| | pedido de otro | id que no existe |
|---|---|---|
| `ChangesAsked/ver-detalles` (§50) | **403** | **404** |
| `ChangesAskedAssignment/ver-detalles` | **200** | **500** |

El 200 devuelve la fila entera de `change_asked_data`: `documento_new`,
`telefono_new`, `celular_new`, `direccion_new`, `email_new`. O sea el dato nuevo
**y** el viejo del mismo campo, que es para lo que existe la tabla.

**Por qué se salvó de tres pasadas seguidas**, que es lo que hay que llevarse:

1. **Está en otro controlador.** La §39, la §49 y la §50 entraron las tres por la
   lista de cobertura de `ChangeAskedController`, y esta ruta no vive ahí.
2. **Los dos recursos se llaman casi igual y los dos tienen `ver-detalles`.** No
   lo decimos nosotros: lo avisa el propio front en la cabecera de
   `ChangesAskedAssignmentApi.ts` —«los dos van en CamelCase y en inglés, los dos
   tienen `ver-detalles`, y se confunden con solo mirar el nombre»—. Arreglado el
   que se llama, el nombre de la lista ya parecía tachado.
3. **Y ya estaba medida. Dos veces.** Vivía en
   `MuestreoDeLecturasConContextoTest` con un test que **fijaba su 500** como
   comportamiento conocido y con un párrafo explicando que cambiarlo a 404 era
   otro trabajo. La pregunta que se le hizo fue «¿qué código devuelve con un id
   que no existe?»; la que faltaba era «¿y de quién es la fila cuando sí existe?».
   **Medir una ruta no es haberla juzgado**, y esto es lo más parecido a una
   prueba de eso que hay en el repo.

Y el arreglo salió gratis por donde se temía que costara: el párrafo de aquel test
frenaba el 404 por no saber qué hace `myvc_front` con cada código. **No hace
nada**: `ChangesAskedAssignmentApi` expone `solicitar-materia` y
`pedir-quitar-asignatura`, y `ver-detalles` de este recurso **no lo llama ningún
cliente**. La ruta que quedaba abierta era justamente la que no usa nadie.

El criterio no se eligió aquí —es el que fijaron sus cinco hermanas: el dueño o el
superusuario— y por eso se mudó a `App\Support\PedidoPropio` en vez de copiarse.
Copiar un criterio que costó tres pasadas fijar una vez es cómo se llega a la
sexta.

### 53.2 El álbum privado de cualquiera, y la exención escrita contra otra clave

`PUT images-users/imagenes-de-usuario` iba con `auth.token` a secas. Medido con el
token de un alumno del seed: **200 y las 162 imágenes privadas de un
superusuario**, con el nombre de archivo dentro, que es la ruta con la que se
piden.

Lo que la tuvo tapada no fue que nadie la mirara —tenía su entrada en
`AutorizacionTest`— sino **el nombre de la clave**. La exención decía:

> `'PUT api/images-users/imagenes-de-usuario' => 'sin user_id significa «las mías», que es lo que devuelve'`

y las dos mitades son falsas. El método no lee `user_id`: lee **`usuario_id`**. Y
sin la clave no devuelve «las mías» sino las imágenes cuyo `user_id` es NULL. La
frase describe un método que no existe.

Dos líneas más arriba, en ese mismo fichero, está escrito el aviso:

> Esta lista es de las pocas cosas del repo que se escriben creyendo al código en
> vez de midiéndolo, y por eso se le coló.

Es la **segunda** que se le cuela, después de `piars-alumnos/field` en la §35. Van
dos de dos por lo mismo. Lo que las separa de todo lo demás no es la dificultad:
es que una exención es la única línea del repo cuya recompensa es que nadie la
vuelva a mirar.

El criterio tampoco es nuevo: la pestaña «Imágenes de usuarios» del gestor de
archivos es la única que llama aquí —`alumSelect` y `profeSelect` de
`FileManagerCtrl`, y el propio `ImagesUsersApi` la documenta como «el álbum de
otra persona, para el gestor de imágenes del administrador»— y el front la enseña
con `ng-if="hasRoleOrPerm('admin')"`. Es la §29.3 —**el backend dos escalones por
debajo de su propia pantalla**— y se cierra con la decisión que ya tomó la §36
para las cinco hermanas de este mismo controlador.

### 53.3 `foto_id`, el tercer nombre de una imagen

`PUT images-users/cambiar-imagen-oficial/{user_id}` lleva `persona.propia` desde
la revisión de IDOR, y el guard miraba el `user_id` de la URL —suyo— sin ver la
imagen que proponía el cuerpo. Medido: un alumno deja **la imagen de un
superusuario** en su propio pedido de foto oficial, `change_asked_data.foto_id_new`
la guarda, y un administrador que acepte el pedido se la pone en la ficha.

Sus **dos hermanas del mismo controlador llaman `imagen_id` a este mismo dato**.
La asimetría era del vocabulario, no del criterio.

Es la §13 por tercera vez —allí, `{id}` donde sus cuatro hermanas dicen
`{imagen_id}`; en la §15, `img_id`—, y la lista del guard ya lleva **tres nombres
para una sola cosa**. Eso es lo que hay que leer en ella: en este repo, **un
identificador con un nombre nuevo es un guard ciego**, y la lista se queda corta
en cuanto un endpoint escribe el suyo.

`AutorizacionTest` compara por reflexión los nombres de los parámetros de URL con
las claves del middleware, y por eso no vio ninguno de estos: **los tres viajan en
el cuerpo**. Ese test ya avisaba de su propio límite —«lo que siguen sin ver son
las claves que viajan en el cuerpo: eso no tiene atajo estático y hay que
golpearlo»—, y eso es lo que hace `IdentificadoresDelCuerpoTest`: nueve casos,
golpeando.

### 53.4 Comentar donde no se lee, y el cuarto que salió al afinar el detector

Las tres de arriba salieron de la primera pasada. La cuarta salió de **arreglar la
herramienta**, que es un sitio del que no se espera que salga nada.

El detector marcaba `MisActividadesController` y `PublicacionesController` enteros
como candidatos, y los dos tienen todas sus comprobaciones puestas desde la §20,
la §22 y la §43. El motivo es tonto y vale la pena tenerlo escrito: la señal
buscaba `Autoriza::` y los dos comprueban en un helper privado — y los helpers se
llaman **`exigirQueLaResueltaSeaSuya`** y **`exigeQueLaPublicacionSeaSuya`**, o sea
el mismo verbo conjugado de dos maneras. Buscar por la raíz `exig` bajó los
candidatos alcanzables por una familia de catorce a uno.

**Es la misma trampa que persigue esta herramienta, un piso más arriba: el
detector también se queda ciego ante un nombre nuevo.**

Y el uno que quedó era real. `PUT publicaciones/comentar` recibía `publi_id` del
cuerpo y no miraba nada. Medido con token de alumno contra una publicación marcada
solo `para_administradores`: **200 y la fila escrita**, y la misma llamada
comprobando que esa publicación **no sale en su muro**. Escribir donde no se lee.
Con un `publi_id` que no existe, **500** —la clave ajena de `comentarios`—, donde
tocaba 404.

Es la §22 en la hermana que aquella pasada no tocó, y no la tocó por una razón que
se ve ahora: la §22 entró por `publi_id` en **borrar, restaurar y editar**, y
comentar es la cuarta que lo lleva. El criterio tampoco se inventa —es el reparto
que ya aplica `Publicaciones::ultimas_publicaciones()` para pintar el muro de cada
tipo, que es lo mismo que hace el front, que solo enseña la caja de comentario
debajo de una publicación que ha pintado—.

El `Usuario` administrativo las ve todas —su rama de `ultimas_publicaciones()` no
lleva filtro— y por eso tampoco se le pregunta aquí. `para_administradores` existe
como columna y esa rama no la mira: se respeta lo que hay, porque unificarlo sería
colar una decisión dentro de un arreglo.

### Los dos que la herramienta marca y no lo son, para no volver a mirarlos

- **`aplicacion-descargas/detailed`** lee `grupo_id` y `year_id` del cuerpo… en
  código que no se ejecuta: el método hace `return $user` en su segunda línea
  (§12.1). El detector lee las claves del texto del método, no del que llega a
  correr.
- **`tardanzas/subir/*`** salen sin guard porque lo llevan `withoutMiddleware`:
  autentican por dentro con usuario y contraseña, que es el mecanismo propio del
  lector (§25). Lo que sigue abierto ahí es la pregunta del §5 de 09, no ésta.

### Lo que queda de la pregunta

Comprobado al revés, revirtiendo cada arreglo por separado: caen **3, 2 y 1**, y
los tres «esto sigue funcionando» —el dueño ve su pedido, el administrador ve el
álbum, el alumno propone su propia imagen— siguen verdes en las tres reversiones.

Y lo que la pregunta **no** contesta, para que no se dé por agotada: la
herramienta mira si alguien comprueba el identificador, no si lo comprueba bien, y
sobre todo **no sabe qué identificadores son de una persona y cuáles del colegio**.
Las 231 rutas siguen ahí; lo que se ha leído es el trozo alcanzable por una
familia y por el personal que no debería. El resto se lee el día que se decida
quién configura el colegio, que es lo que está abierto en 09 §0.

---

## 54. Ocho rechazos que contestan con el código de otra cosa (21 ago 2026)

Sigue la lista de cobertura. Quedaban **veintidós rutas que ningún test había
mirado nunca y que alcanza cualquiera con token** —las de `auth.token` a secas—,
así que se golpearon las veintidós con un token de alumno de una vez, mirando el
resultado.

**Ninguna dejaba pasar nada**: las diecisiete que deniegan lo hacían bien y las
cinco que responden 200 son catálogos o cosas suyas. O sea que no hay aquí un
hallazgo de autorización, y eso ya es un dato: es el primer barrido del mes que
sale sin uno.

Lo que sí salió es que **la mitad contesta con un código que dice otra cosa que su
propio mensaje**, y son cuatro códigos distintos para lo mismo:

| Rutas | Contestaba | Mensaje |
|---|---|---|
| `enfermeria/*` (4) | **401** | «No puedes cambiar» / «No puedes eliminar» |
| `calendario/*` (4) | **404** | «No tienes permiso» |
| `alumnos/forcedelete`, `alumnos/guardar-valor-varios` | 400 | «No tiene permisos» |
| `publicaciones/restaurar`, `acudientes/crear`… | 403 | el bueno |

### El 401 no es un código mal elegido: es una orden al frontend

Es el que hay que mirar, y no se ve leyendo el backend. `Sesion.ts` de
`myvc_front` intercepta **todo 401** que no venga de una ruta de sesión, pide una
renovación de tokens y reenvía la petición; si la renovación falla —el refresco ya
rotado en otra pestaña, que es la carrera que el propio fichero documenta—, llama
a `expirar('token')`: borra los tokens, avisa con «La sesión ha expirado» y manda
al login.

O sea que a quien no tiene el permiso de enfermería no se le decía «no puedes»:
se le **rotaba la sesión en cada intento**, y en la carrera se le echaba de la
plataforma. Eso se reporta como **«me saca»**, que manda a mirar el código de
sesión —donde no está el fallo— y no como «no tengo permiso».

Es el reverso exacto de la §45: allí un `else` devolvía 200 y el front pintaba
como hecho algo que no se hizo; aquí devuelve 401 y el front deshace algo que sí
estaba bien.

### El 404 de calendario, y por qué ahora importa más que antes

`404, 'No tienes permiso'` es un código y un mensaje que dicen cosas distintas. En
un API donde 404 significa «esa fila no está» en todas partes —y donde se acaba de
gastar una serie entera en que lo signifique: §44, §47, §49, §50, §53— dejar
cuatro que lo usan para «no puedes» es sembrar el siguiente rato perdido.

Los ocho pasan a **403**, que es lo que dice CLAUDE.md para código nuevo y lo que
hace el resto de la API. **Ningún cliente los leía para otra cosa**: comprobado en
los tres fronts, el `.catch` de las ocho llamadas pinta el mensaje del cuerpo con
`toastr.error` y no mira el código. Fijado por `RechazosQueMientenTest`, con el
caso al revés —un profesor sigue creando eventos— para que se vea que se corrige
el código y no el criterio.

Los 400 se dejan: son el legacy que CLAUDE.md ya describe, no mienten sobre lo que
pasó y cambiarlos es tocar rutas cuyo front no se ha leído.

### Y un mensaje que hablaba de otra operación

`PUT alumnos/update/{id}` respondía **403 «No tienes permiso para eliminar alumnos
definitivamente»**, copiado del `forcedelete` de más abajo del mismo controlador.
El código era el correcto; el que mentía era el texto — y el texto es lo que se
enseña en pantalla y lo que queda en el log de un colegio, donde parecería que
alguien intentó borrar a un alumno. El criterio que la ruta comprueba es
`puedeEditarAlumnos`.

### Y la tercera vez en dos días de lo mismo

El 401 de enfermería **ya tenía un test que lo fijaba**: `EnfermeriaTest`, escrito
por la §41.2 al arreglar el criterio —quién puede escribir los antecedentes—. Aquel
trabajo entró por el criterio, anotó el código que había y no se lo preguntó.

Es la tercera en dos días, después de las dos de la §53: el 500 de
`ChangesAskedAssignment/ver-detalles` en `MuestreoDeLecturasConContextoTest` y la
exención de `images-users/imagenes-de-usuario` en `AutorizacionTest`. Las tres
tienen la misma forma y conviene decirla entera:

> **Un test que fija lo que hay deja fijado también lo que estaba mal, y lo vuelve
> más difícil de ver** — porque a partir de ahí hay un test verde que dice que es
> así.

No es un argumento contra fijar lo que hay: es lo que hace útiles a los tests de
contrato y no se cambia. Lo que hace falta es la costumbre de **escribir al lado
por qué ese valor es el que es**, aunque solo sea «no se juzgó». Los tres estaban
anotados con precisión y ninguno decía eso, así que los tres se leyeron como
decididos.

### Lo que se lleva de aquí el método

Un barrido que sale **sin ningún fallo de autorización** no es un barrido perdido:
es la primera medición que dice que ese trozo está cubierto. Y aun así trajo ocho
respuestas que mienten, porque **la pregunta era «qué responde» y no «quién
entra»**. Es la misma diferencia que hizo útil la cobertura desde el principio.

---

## 55. El `year_id` del cuerpo entraba crudo en el SQL de los ordinales (22 ago 2026)

`Disciplina\OrdinalesController::putOrdinales()` armaba la primera de sus tres
consultas concatenando:

```php
$year_id  = Request::input('year_id', $user->year_id);
$consulta = 'SELECT * FROM dis_ordinales WHERE year_id='.$year_id.' and deleted_at is null order by ordinal';
$ordinales = DB::select($consulta);
```

Mandando `2 OR 1=1` salen **dieciséis filas donde tocaban dos**: los ordinales de
todos los años del colegio. `and` liga más fuerte que `or`, así que el
`deleted_at is null` se queda colgando del `or` y deja de filtrar.

**No es la familia del `ColumnaSegura`**, y por eso esa defensa no lo tapaba: allí
lo que se concatena es el **nombre** de la columna y el valor va ligado. Aquí era
el valor. Arreglado ligando `:year_id`, con `CatalogosDelColegioTest` fijándolo.

### Lo que lo hace barato de encontrar, y lo que lo hacía invisible

Las **otras dos consultas del mismo método** ya ligaban `:year_id`. Esa asimetría
—una hermana concatenando entre dos que ligan— es lo que convierte una sospecha en
una prueba de dos líneas: se manda un `year_id` con SQL dentro y `ordinales`
obedece al SQL mientras `tipos` sigue contestando por el año de verdad.

Y lo que lo escondía: **la ruta ya estaba cubierta**.
`MuestreoDeLecturasConContextoTest` la golpea con un `year_id` legítimo y compara
la instantánea desde que se escribió. Es la tercera vez en dos días que un
hallazgo aparece debajo de un verde que fijaba el comportamiento — ver la §54.
La instantánea **no se movió** con el arreglo: el camino legítimo responde igual,
que es justo por lo que el test no lo veía.

---

## 58. Los cuatro borrados de las votaciones: el mismo código, tres resultados (22 ago 2026)

Sale de la cobertura de la noche del 21 al 22. De las 36 rutas `Vt*`, 29 ya
tenían la respuesta comprobada y las siete que faltaban eran **los cuatro
`destroy` del módulo** más tres de `votaciones`. Que lo único no mirado fueran
los borrados no es casualidad estadística: un `destroy` es lo más caro de probar
a mano y lo único que no se puede deshacer en producción.

Los cuatro métodos son **idénticos**, línea por línea:

```php
public function deleteDestroy($id)
{
    $x = VtLoQueSea::findOrFail($id);
    $x->delete();

    return $x;
}
```

Sin comprobar año, ni dueño, ni si la urna está abierta. Y aun así **hacen tres
cosas distintas**, porque lo que decide no está en el controlador:

| Ruta | Trait en el modelo | Columna en la tabla | Qué pasa de verdad |
|---|---|---|---|
| `votaciones/destroy/{id}` | sí | sí | lógico; los hijos sobreviven |
| `candidatos/destroy/{id}` | sí | sí | lógico; el voto sobrevive |
| `aspiraciones/destroy/{id}` | **no** | sí | **físico**, y la cascada se lleva candidatos y **votos** |
| `participantes/destroy/{id}` | sí | **no** | **500** |

Las dos condiciones —el trait en el modelo, la columna en la tabla— se pusieron
por separado y **nadie las comparó nunca**. Donde no cuadran, salen las dos de
abajo.

### 58.1. Borrar una aspiración destruye los votos, de verdad y sin papelera

`VtAspiracion` es **el único de los cinco modelos que no lleva `SoftDeletes`**.
Lo importa en la cabecera del fichero y no lo usa dentro de la clase, que es
justo la forma de no verlo: un `grep SoftDeletes` sobre `app/Models/Vt*` los
devuelve los cinco.

Así que ahí `delete()` sí manda un `DELETE` a MySQL, y entonces la cascada que el
esquema tenía declarada hace su trabajo:

```
vt_aspiraciones ──ON DELETE CASCADE──> vt_candidatos ──ON DELETE CASCADE──> vt_votos
```

**El escrutinio de una elección se borra de forma irreversible con una sola
llamada**, aunque `vt_candidatos` y `vt_votos` tengan su `deleted_at` puesto y
listo para usarse. La papelera existe en las dos tablas; lo que se pierde no pasa
por ella.

Su vecina de arriba hace lo contrario con el mismo código. `votaciones/destroy`
sí es lógica, así que **el `DELETE` nunca llega a MySQL y la cascada no
dispara**: la fila padre se queda marcada como borrada, y sus aspiraciones,
participantes, candidatos y votos siguen vivos. La intención escrita en el
esquema —cuatro `ON DELETE CASCADE` apuntando a `vt_votaciones`— **no se cumple
en ningún caso**, porque el único camino que la activaría está tapado por el
trait.

### 58.2. Borrar un participante responde 500

`vt_participantes` es **la única de las cinco tablas `vt_*` sin columna
`deleted_at`** (`database/schema/mysql-schema.sql:1934`). Y `VtParticipante` sí
lleva `use SoftDeletes` dentro de la clase. El trait traduce el `delete()` a
`UPDATE vt_participantes SET deleted_at = ?`, MySQL contesta que esa columna no
existe, y la petición muere.

El modelo lleva además `protected $softDelete = true`, que es la sintaxis de
Laravel 4 y hoy no la lee nadie: **dos formas de pedir lo mismo, y ninguna
comprobada contra el esquema**.

Ha sobrevivido a la migración entera porque el resto del módulo lee por SQL
crudo —de las 990 consultas del proyecto—, así que el censo funciona y **el
fallo solo asoma al borrar**. Es el mismo motivo por el que no lo vio ninguna
herramienta: larastan mira si el código puede funcionar, no si la columna está;
el barrido mira quién entra; `inventario-autorizacion.py` mira la firma.

### Por qué se fija y no se arregla

Los dos están fijados por `tests/Contrato/VotacionesBorradoTest.php` —cuatro
tests, 34 aserciones— **describiendo lo que hacen hoy**, no lo que deberían
hacer. Son endpoints vivos en los dieciséis colegios y las dos correcciones son
decisiones, no arreglos:

- En `participantes`, o se le añade la columna a la tabla —migración, y el
  borrado pasa a ser lógico— o se le quita el trait al modelo, y entonces pasa a
  ser **físico con la cascada del esquema detrás**, que es lo que hace hoy la
  aspiración. Las dos son un cambio de comportamiento, en direcciones opuestas.
- En `aspiraciones`, ponerle el trait detiene la pérdida de votos, pero deja
  candidatos y votos colgando de un padre invisible — que es exactamente lo que
  ya hace `votaciones/destroy`, y nadie ha decidido que eso esté bien.

Ninguna de las cuatro rutas comprueba tampoco **el año ni el dueño**: `findOrFail`
acepta cualquier id de la base. Eso no se toca aquí porque es la familia del
§5 de [09-pendientes.md](09-pendientes.md) —las rutas de estructura con solo
`auth.personal`, que Joseth decidió el 21 ago no cerrar— y cerrarlas puede dejar
fuera a un coordinador.

### Lo que enseña, y no es sobre votaciones

**Cuatro métodos idénticos no son cuatro veces el mismo comportamiento.** Aquí lo
que decide está repartido entre el modelo y el esquema, dos sitios que no se
leen cuando se lee el controlador — y el controlador es lo único que mira un
diff. Es la misma familia que el §52, el bucle copiado en cinco sitios, pero al
revés: allí cinco copias hacían lo mismo mal, aquí cuatro copias iguales hacen
tres cosas distintas.

Y comprobado al revés, como manda el §45: al añadirle `SoftDeletes` a
`VtAspiracion` cae **un solo test**, el de la cascada, con su mensaje. Uno de
cuatro es lo correcto — si hubieran caído dos, es que alguno medía de rebote.

---

## 59. El barrido de la concatenación cruda: cuarenta sitios, dos fallos (22 ago 2026)

Después de la §55 se barrió el patrón por toda `app/`: **cuarenta sitios en
diecinueve ficheros**, de los ciento cuarenta que usan SQL crudo. Se repartió
entre cuatro sesiones. Aquí van los **veintiún sitios** de los bloques C0-c y
C0-d, leídos uno a uno.

**Quince falsos positivos, una inyección real.** El ratio importa menos que los
motivos, porque los motivos son lo que afina el detector y el recuento no:

| Motivo del falso positivo | Dónde |
|---|---|
| Concatena **marcas de parámetro** (`?,?,?` de `array_fill`), no valores — la única forma de un `IN` de longitud variable | `ContextoDeUsuario:305`, `MisActividadesController:210` |
| Está **dentro de un bloque comentado** | `Bitacora:76` |
| **Ya estaba arreglado**, con su comentario al lado explicando qué se inyectaba antes | `Grupo:138` |
| El valor sale de un **`switch` de casos literales**: no lo elige el cliente | `ChangeAskedController:1215`, `GuardarAlumno:44` y `:76` |
| Es un **literal fijo** con su propio parámetro ligado dentro | `CertificadosPersonaController:228` |
| La columna es **`int unsigned`**: no puede llevar texto, así que ningún payload sobrevive al viaje | `AlumnosController:642` |
| La ruta está **rota a propósito y documentada**: la variable concatenada ni siquiera está definida y revienta antes del SQL (§6.5, §27.2) | `UniformesController:87` |
| **La variable concatenada no se usa en la consulta** | `Grupo:159` y `:161` |

> **«Viene de la base» no es un motivo, y este documento lo tuvo mal escrito una
> hora.** Descartar por el origen solo vale si además se sabe **quién escribe esa
> columna**: si alguna ruta la llena desde `Request::input` con texto libre, el
> sitio sigue vivo y la entrada está en otro fichero — que es exactamente la
> [§59.1](#591-la-inyección-que-ninguna-señal-encuentra-el-nombre-que-guarda-otra-ruta).
> Lo que salva a `AlumnosController:642` no es venir de una consulta: es que la
> columna es un entero. Los descartes por constante, por marcas de parámetro, por
> bloque comentado y por «no llega al SQL» no dependen del origen y siguen buenos.

Ninguno de esos ocho motivos lo distingue un `grep`, y por eso la lista de
cuarenta era **una lista de sitios donde mirar y no una lista de fallos** — la
misma lección de la §52, recorrida esta vez en una noche en lugar de en cuatro
mediciones.

### La condición que redujo cuarenta a dos

La señal buena no resultó ser la concatenación, sino **la asimetría**: un método
donde una consulta liga el parámetro y su hermana lo concatena. Pero la asimetría
sola no basta, y casi cuela un falso positivo con muy buena pinta —
`AlumnosController:642` tiene `WHERE m.year_id=? and a.alumno_id='.$alumno_id`,
ligado y concatenado en la misma consulta, y no es inyectable.

Lo que hay que añadirle es preguntar **si ese valor llega de verdad al `DB::` que
se ejecuta**: hay que seguir la variable, no leer la línea. Con esa condición, los
cuarenta sitios quedaron en **dos**, y los dos eran el mismo fallo real
(`ImporterFixer` → `ImportarController`, la casilla de la hoja de cálculo que sube
el usuario dentro de un `UPDATE alumnos`).

**Y aun así la lista de dos estaba incompleta**, que es lo siguiente.

### 59.1. La inyección que ninguna señal encuentra: el nombre que guarda otra ruta

`Perfiles\CalendarioController::putSincronizarCumples()` metía el nombre del que
llama **dentro de unas comillas dobles del SQL, sin ligar**:

```php
$nombres = $user->tipo == 'Usuario' ? $user->username : ($user->nombres.' '.$user->apellidos);
...
SELECT '.$user->user_id.' as created_by, "'.$nombres.'" as created_by_nombres, ...
```

Medido: un profesor llamado `Ana "La Profe"` recibe un **500 con error de sintaxis
de MySQL** y su propio nombre visible dentro de la consulta. Un nombre con comilla
doble es un nombre legítimo: **la ruta se caía sola sin que nadie atacara nada**.

Es una **inyección de segundo orden**, y por eso ninguna de las dos señales de la
noche la caza. El valor no llega del cuerpo de la petición que detona: llega de la
fila del usuario, y esa fila la escribe el cuerpo de **otra** ruta —
`ProfesoresController::postStore()` asigna `nombres` desde `Request::input`, **no
tiene ninguna `Autoriza::exigir`** y su `sanarInputProfesor()` solo normaliza
`tipo_sangre` y `estado_civil`: no toca las comillas.

**La asimetría está repartida entre dos peticiones.** Un detector que mire un
método a la vez no da la lista larga —eso se corrige leyendo—, da la **lista
incompleta**, que no se corrige con nada porque no se ve. Se guarda por una puerta
y detona por otra, y las dos por separado parecen inocentes.

Arreglado ligando los seis valores de las dos consultas.
`CalendarioCumplesTest` lo fija en dos mitades a propósito —la fuente guarda el
texto tal cual, el sumidero ya no lo interpreta— y mira **lo que queda escrito en
`calendario`**, no el código de respuesta: una inyección de segundo orden contesta
200 igual de bien. Comprobado al revés según el §45: revertido el arreglo cae **un
solo test**, el del sumidero.

#### La puerta de entrada es un hallazgo por sí sola

`POST profesores/store` **no tiene ninguna `Autoriza::exigir`**. Su único uso de
`Autoriza` es `concederSuperusuario()` para el flag `is_superuser`; el resto del
método no comprueba nada. Con `auth.personal` delante, eso son los **51
profesores**, y lo que crea es un profesor **con su cuenta de usuario dentro** —
`username` del cuerpo y `password` con `'123456'` por defecto—.

Es del tamaño de la [§26.1](#) y la [§30](#), que salieron de este mismo
controlador, y **no debe quedar enterrado dentro de la explicación de la
inyección**: aquí aparece como la fuente del texto, pero se sostiene solo.

#### Lo que enseñó la comprobación al revés, y no es lo que se buscaba

Revertido el arreglo del calendario cae **un solo test**, el del sumidero. El de
la fuente **sigue verde**, y eso no es que sobre: es el resultado.

**En una cadena de dos rutas, arreglar una no protege a la otra.**
`profesores/store` sigue guardando comillas sin sanear, exactamente igual que
antes; lo único que ha cambiado es que ya no hay un sumidero que las interprete.
El test de la fuente es el que sigue diciendo eso en voz alta, y por eso se queda.

#### ¿Cuántos sumideros más leen ese nombre? Uno, y era éste

La pregunta obvia al cerrar la §59.1 —si `nombres` se guarda crudo, quién más lo
lee— se contestó barriendo `app/` en busca de texto de una fila metido entre
comillas dentro de SQL crudo. Salen cuatro sitios más y **ninguno está vivo**:

- `UsersController:46`, `:84` y `:122` concatenan un `username`, pero es
  **generado**: `'usuario'.rand(100, 9999)`, `'psicologo'.rand(...)`,
  `'enfermero'.rand(...)`. No lo elige nadie. Confirma el descarte de C0-a.
- `Bitacora:76-78` está **dentro de un bloque comentado**.

Así que `putSincronizarCumples` era **el único sumidero vivo** de esta familia. Es
un resultado y no un rato perdido: cierra la pregunta en vez de dejarla abierta
tres meses.

### 59.2. Y debajo de un falso positivo había un filtro que no filtra

`Grupo::detailed_materias($grupo_id, $profesor_id, $exceptuando)` salió en la lista
por concatenar `' and p.id!='.$profesor_id`. No es inyectable **porque
`$complemento` se escribe en tres sitios y no se lee en ninguno**: nunca entra en
la consulta.

Lo que hay debajo es que **los dos parámetros que distinguen «las mías» de «las de
los demás» no hacen nada**. Medido en `PUT actividades/datos`: a un profesor del
grupo 84 le llegan las **diez** asignaturas del grupo dentro de
`mis_asignaturas`, con las de los otros seis profesores dentro. Treinta llamadas
pasan solo el grupo; tres pasan además el profesor y se comportan igual.

**No se arregla, y el test fija lo que hace hoy.** Arreglarlo encoge una lista que
ven dieciséis colegios en una pantalla del front, y `app/` es copia real en cada
uno: es decisión de Joseth y no del que pasaba por aquí. La pregunta que la decide
en un minuto es **quién mira esa lista y para qué la usa** — si es el profesor
eligiendo entre sus asignaturas, la lista larga es un estorbo; si es una rejilla
del grupo, es correcta. `FiltroDeProfesorEnMateriasTest` lo fija con el «no se
juzgó» escrito al lado, para que el verde no se lea como que esto está bien.

### Y esto cambia cómo hay que leer las revisiones viejas

`PUT actividades/datos` no solo estaba cubierta: **la §6 de
[13-actividades.md](13-actividades.md) la revisó y se dio por cerrada** — «se
revisó entera y no queda ninguna». Lo que revisó fue la rama del administrativo,
que era la que había abierto. El contenido de la rama del profesor no lo miró
nadie.

Los otros casos de estos dos días eran un verde que fijaba lo que había. Éste es
**una revisión explícita dada por cerrada mirando una sola rama**, y es el más
fuerte de los cinco: obliga a leer «se revisó entero» como **«se revisó la rama
que abrí»**.


---

## 56. Los doce sitios de concatenación del dominio de cuentas: ninguno inyectable (22 ago 2026)

El barrido de la [§59](#59-el-barrido-de-la-concatenación-cruda-cuarenta-sitios-dos-fallos)
sacó cuarenta sitios, y el bloque más grande caía en el dominio de credenciales:
nueve en `UsersController`, dos en `LoginController` y uno en
`ProfesoresController`. Se leyeron los doce, uno a uno. **Ninguno es
inyectable**, y eso es el resultado — no la ausencia de uno.

Se escribe porque el dominio pesa: de ahí salieron la [§26.1](#261),
la [§29](#29), la [§29.3](#293) y la [§30](#30). Un «no hay nada» sin el porqué
de cada sitio se vuelve a mirar dentro de tres meses.

- **Los nueve de `UsersController`** están en `postCrearAdministrador`,
  `postCrearPsicologo` y `postCrearEnfermero`, que son copia literal uno de otro.
  Lo concatenado es siempre lo mismo y **nada sale del usuario**: `$username`
  generado con `'usuario'.rand(100, 9999)`, `Hash::make('123456')` —bcrypt de una
  constante, y su salida no lleva comillas—, `Carbon::now()` y el
  `lastInsertId()`. El único valor que viene del token va **ligado**, y es esa
  mezcla la que hace que el sitio parezca sospechoso en un grep.
- **`LoginController` 251 y 265 ni siquiera son SQL**: una arma la URL del correo
  de reseteo y la otra un `Log::error`. Las dos consultas de verdad de ese trozo
  van con `?`. Se leyeron línea a línea igualmente, por ser superficie pre-login.
- **`ProfesoresController:237` es el único con la forma mala** —`'UPDATE users
  SET '.$propiedad` con `$propiedad` del cuerpo— y lo salva estar dentro de
  `if(Request::input('propiedad') == 'is_active')`. Es **correcto por el guard, no
  por el código**: el día que esa línea salga del `if`, es inyección de nombre de
  columna. No se toca —cambiar código que funciona no era el trabajo de esa
  noche— pero ahora lo dice un comentario, que antes no.

**La lección es la de la [§52](#52) otra vez, y ya van cinco recuentos del mismo
patrón**: un detector da una lista de sitios donde mirar, nunca una lista de
fallos. Lo que sí salió de leerlos es una señal mejor, y está en la
[§60](#60): no la concatenación a secas, sino **la asimetría** —dos consultas
hermanas del mismo método, una liga y la otra no—.

---

## 57. `POST api/asistencias-app`, que no puede funcionar y no la llama nadie (22 ago 2026)

Dos fallos en el mismo método, `AsistenciasAppController::postIndex`:

1. La consulta de inserción lleva `:asignatura_id` y **el array de datos no lo
   incluye**, así que PDO revienta antes de insertar.
2. Y si llegara a pasar, la línea siguiente hace `$datos->id = $id` sobre un
   **array**, que en PHP 8.4 es un `Error`.

O sea: 500 siempre, sin escribir nada.

**Lo primero que se pensó de esto era falso, y por eso se escribe.** El
controlador vive en `app/Http/Controllers/AppMobile/`, así que se dio por hecho
que era una ruta de `myvc_flutter` —la app única de los dieciséis colegios— y
que el fallo era de producción. No lo es.

Que no la llama nadie es **comprobado, no supuesto**, y por quién y cómo importa
tanto como el resultado: lo midió la sesión que llevaba el árbol de
`myvc_front` la noche del 22 de agosto, en los cuatro clientes, y en tres pasos
—`grep` de la cadena, enumeración de **las 34 rutas literales** que arma el
Flutter, y sobre todo **las cuatro que se construyen con variable**, que es
donde un `grep` de cadenas se pierde y donde se habrían equivocado los dos
lados—. No hay ninguna llamada a `asistencias-app`, ni al `POST` ni a las otras
cuatro. Es ese tercer paso el que convierte «no encontré nada» en «no está».

Lo que el Flutter sí usa es **otro controlador con nombres de método idénticos**,
y conviene que quede fijado porque el parecido es lo que engaña:

| lo que manda `myvc_flutter` | quién lo sirve |
|---|---|
| `PUT api/asistencias/detailed` | `AsistenciasController@putDetailed` |
| `POST api/ausencias/store` y las tres de `agregar-*` | `AusenciasController` |

`asistencias/detailed` y `asistencias-app/detailed` **no son la misma ruta**.

Se deja roto, con la regla de siempre —con ruta y roto se documenta— y con un
argumento que aporta el lado del front y es mejor que el de aquí: **los dos
fallos son la especificación de lo que esa pantalla pretendía hacer**. Borrar la
ruta convierte el 500 en un 404 y esa intención se pierde, y estas cinco son las
que un cliente móvil futuro volvería a necesitar.

**Lo que queda sin comprobar, dicho como tal**: que hoy nadie la llame no
significa que nunca la llamara. `myvc_flutter` se actualiza por tienda, y la
versión instalada en un móvil no tiene por qué ser la del repositorio. Eso no lo
puede contestar el código: solo el log de alguno de los dieciséis, buscando
`POST /api/asistencias-app` con 500.

---

## 60. La casilla del SISBEN de la hoja de importación entraba en el SQL sin ligar (22 ago 2026)

`ImporterFixer::verificar()` arma un trozo de SQL —`$cons`— que
`ImportarController` mete **dentro de la lista SET** de un `UPDATE alumnos` que
sí se ejecuta. Casi todo lo que concatena ahí sale de la base, pero
`nro_sisben` y `nro_sisben_3` salían de **la casilla de la hoja que sube el
usuario**:

    ImporterFixer:106   $cons .= ', has_sisben=1, nro_sisben='.$alumno["sisben"];
    ImporterFixer:113   $cons .= ', has_sisben_3=1, nro_sisben_3='.$alumno["sisben_3"];
    ImportarController:186   'UPDATE alumnos SET …, updated_at=?'.$res['consulta'].' WHERE id=?'

Alcanzable por `POST api/importar/algo/{year}`, que es `auth.personal`: el mismo
grupo del que salieron la §26.1 y la §30. Con `1, nombres='X'` en la casilla se
escribe **cualquier columna de `alumnos`** de la fila que se importa, `user_id`
incluida —que es apuntar la ficha de un alumno a la cuenta de otro— y admite
subconsulta, que deja el dato robado dentro de una columna que el front enseña.

**Por qué no se había visto nunca, y esto es lo que hay que llevarse**: el
intento ingenuo lleva `--`, que comenta el `WHERE id=?` final y deja **una marca
de menos frente a las vinculaciones**, así que PDO revienta y lo que se ve parece
una hoja mal formada. El que entra es el que **respeta el número de marcas**. Una
vulnerabilidad que castiga el ataque torpe y premia el cuidadoso sobrevive años.

### 60.1 El arreglo barato se llevaba cuatro columnas por delante

La consulta de `procesarFila` **ya liga `nro_sisben=?`**, así que la
concatenación parecía una segunda asignación redundante y el arreglo parecía ser
borrarla. Medido con `grep` sobre `app/` entero, no lo era:

- `has_sisben`, `has_sisben_3` y `nro_sisben_3` **no las escribe nadie más en
  todo `app/`**;
- `nro_sisben` solo va ligada por esa ruta —la consulta de `getModificar` no la
  lleva—;
- y la rama del «no aplica» escribe `nro_sisben=null` **pisando a propósito** al
  `?` que se ligó antes, porque va después en el SET.

El arreglo bueno es que el fragmento lleve **marcas y sus valores aparte**, y que
los dos llamantes los fusionen en el orden en que el fragmento entra en la
consulta.

`getModificar` quedó ligado igual **aunque no llegue nunca al fixer**: muere
antes en `Excel::import()` con la firma de maatwebsite 2.x, que es la
[§13.3](#133-el-cuarto-importador-con-la-firma-de-maatwebsite-2x). Un fragmento
que solo es seguro porque su llamante está muerto es una trampa esperando a que
alguien lo reviva.

### 60.2 Revertir al código original no basta: hay que revertir también al atajo

Lo fija `tests/Contrato/ImportarSisbenTest.php`, cinco casos. Y comprobar al
revés **una sola vez daba una respuesta falsa**:

| se revierte a… | caen |
|---|---|
| el código original | **1** de 5 |
| el arreglo barato | **4** de 5 |

Revertir el arreglo bueno tumba solo el de la inyección, y es correcto: el
arreglo bueno **no cambia lo que se escribe**, solo cómo llega. Los otros cuatro
no miden ese arreglo — miden **la regresión que el atajo habría causado**. Con
una sola reversión se habrían leído como cuatro tests que no miden nada, y se
habrían borrado.

**De ahí sale la adición a la regla de la [§45](#45)**: no basta revertir lo que
cambió el comportamiento; hay que revertir también **a la solución equivocada que
parecía buena**. Es lo único que demuestra que los tests distinguen el arreglo
del atajo.

Y la segunda reversión destapó **un verde hueco** que leer el test no destapó:
los dos casos de «no aplica» pasaban sin medir nada porque la columna ya llegaba
vacía del seed. Ahora plantan el valor antes de importar. **Van ocho** con el
seed vacío.

### 60.3 «Viene de la base» dejó de ser un motivo

Las tres concatenaciones de `ciudades` de las líneas 77-90 del mismo método sí
aguantan, pero **no por lo que se pensó primero**. Después de la inyección de
segundo orden de la [§61](#61) hubo que mirar quién escribe esa tabla, y resulta
que **la escribe el cuerpo**: `CiudadesController::postGuardarCiudad` hace
`$ciudad->ciudad = Request::input('ciudad')`, y la columna es `varchar(255)`.

Lo que las salva es que lo concatenado es **`->id`, entero autoincremental**, y
no `->ciudad`. Está escrito al lado, con la ruta que escribe la columna de texto
nombrada: si algún día se concatena el nombre en vez del id, eso es inyección.

Y por el otro lado: **ningún otro sumidero concatena `nro_sisben`**. Los cinco
sitios que la nombran —`Matricula:92`, `AlumnosController:488` y `:521`,
`PlanillasController:319`, `ActasEvaluacionController:102`— la llevan como
nombre de columna en un `SELECT`, y `OperacionesAlumnos` solo copia el valor.


---

## 63. El gemelo de la inyección, y cómo se encuentra lo que ningún detector ve (22 ago 2026)

La §55 y la §60 salieron de barrer la concatenación cruda. Ésta salió de otra
cosa, y por eso merece sección aparte: **de mirar los hermanos de la que ya
estaba arreglada.**

La cadena fue: `22` encuentra el `year_id` concatenado de `putOrdinales` (§55) y,
buscando la misma forma, ve que `DefinitivasPeriodosController` concatena
`periodo_id` y `num_periodo` en un `INSERT INTO notas_finales` dentro de un
bucle. Me lo pasa medido en vez de tocarlo, porque el fichero está en el dominio
congelado de las definitivas. Se liga con alcance estrecho —un `?` donde había
una concatenación, ni una línea de lógica— y se cierra (`c24706e`).

**Y ahí es donde empieza esta sección.** Cerrada aquélla, la pregunta que quedaba
no era «¿hay más sitios con esta forma?» —eso ya lo contestaba el barrido de la
§59— sino **«¿dónde más está copiada esta consulta?»**. Está copiada en
`NotaFinal::calcularAsignaturaPeriodo`: la misma consulta, palabra por palabra,
con los mismos valores del cuerpo. Sus tres argumentos los pasan los **cuatro**
llamantes crudos de `Request::input`:

| Ruta | Guard |
|---|---|
| `PUT unidades/update` | `auth.personal` |
| `DELETE unidades/destroy/{id}` | `auth.personal` |
| `PUT subunidades/update` | `auth.personal` |
| `DELETE subunidades/destroy/{id}` | `auth.personal` |

Cuatro rutas más sobre el mismo INSERT en bucle, y **ningún detector de los de
esta noche la habría señalado por sí sola**: el de la asimetría no la ve porque
aquí no hay hermana ligada con la que comparar —las dos copias concatenan—, y el
del barrido la habría dado como un sitio más de cuarenta, sin decir que es la
misma consulta.

Lo que sí la encuentra es la pregunta, y conviene escribirla porque es la misma
que la §53 dejó para los identificadores:

> **Cuando una consulta concatena, la pregunta no es si está mal. Es dónde más
> está copiada.**

Ligada en `640306e`, con el mismo alcance estrecho. Los dos métodos están
condenados —son dos de los seis escritores que la fase 3 de
[10-definitivas.md](10-definitivas.md) sustituye por
`App\Services\DefinitivasDeAsignatura`— y aun así se arreglan, por una razón que
conviene tener escrita para la próxima vez que aparezca la duda: **el criterio no
es «va a morir» sino «cuándo muere»**. La fase 3 no tiene fecha y esto está
desplegado en los dieciséis colegios. Si la fase 3 estuviera lista para
desplegarse, lo correcto habría sido dejarlo morir.

### Los otros tres sitios del dominio, que no lo son

C0-b listaba cinco. Leídos uno a uno:

- **`DefinitivasPeriodosController:318` no existe.** Esa zona ya ligaba con `?`.
  El número apareció al pasar el aviso de una sesión a otra, no en el código. Es
  el mismo mecanismo que persiguen todas estas secciones —**una lista se ensancha
  al pasar de mano en mano**— pero entre nosotros, y cuesta lo mismo: quien lo lea
  va a buscar algo que no está.
- **`BolfinalesController:281` no es inyectable.** Su `$sqlPeriodo` es un
  fragmento constante que elige un `if`; no entra nada del cliente.
- **`NotasController:371` no es una inyección**, es otra cosa y tiene su propia
  sección abajo.

### Y lo que salió de escribir el test, que vale más que el arreglo

Probando la carga útil apareció que **`num_periodo` se escribe tal cual en la
columna `periodo` sin comprobar que concuerde con el `numero` del `periodo_id`
que va al lado**. Sin ninguna carga útil: basta mandar el `periodo_id` del
periodo 1 y `num_periodo = 9`.

Eso es exactamente el mecanismo de la [§2.1 de 10-definitivas](10-definitivas.md)
—tres de los seis escritores buscan la fila por `periodo` y los otros por
`periodo_id`, así que una fila desincronizada es invisible para unos y duplicable
por otros—, que hasta hoy estaba descrito como algo que **puede** pasar. Ahora
está medido: **se provoca desde el cliente en una llamada**. La fase 0 contó
**0 filas descuadradas** en el colegio de desarrollo, así que está abierto y nadie
ha entrado.

No se arregla: es lógica, y el alcance de estos dos commits era ligar y nada más.
Muere con la fase 3, donde `periodo` se deriva de `periodo_id` y no se acepta del
cuerpo.

### Una trampa de método que casi hace leer mal el arreglo

El primer test de la inyección **falló después de arreglarla**, y por poco se lee
como «el arreglo no sirve». No era eso: ligado, MySQL coacciona la cadena
`9, 0, 0, 0, "x", "x") AS t2 -- ` a su prefijo numérico —**9**— y la escribe, así
que la tabla quedaba descuadrada igual que antes.

O sea que al medir una carga útil hay que separar dos cosas que se parecen:

- **entró como SQL** — la consulta cambió, y eso es la inyección;
- **entró como dato raro** — la consulta es la misma y el valor es absurdo, que es
  lo que hace un parámetro ligado con `strict => false` delante.

Sin separarlas, un arreglo bueno parece malo. Y con una columna de texto en vez
de entera no habría pasado, que es lo que hace la trampa difícil de anticipar.

---

## 64. Lo que impide escribir basura es el esquema, y una conclusión mía que era falsa (22 ago 2026)

`PUT notas/subunidad` es el quinto sitio de C0-b y no es una inyección. Es el
fallo que la [§3.1 de 10-definitivas](10-definitivas.md) ya tenía anotado —*«la
consulta está en comillas dobles con sintaxis de concatenación de simples»*—, y
al medirlo salieron dos cosas: **la causa estaba bien vista y el efecto no**.

En comillas dobles PHP **sí** interpola la variable; lo que no hace es concatenar,
así que los `'.` y `.'` se quedan como texto:

```
$sql = "SELECT '.$sub_id.' as subunidad_id"   con $sub_id = 7
     → SELECT '.7.' as subunidad_id
     → devuelve la CADENA «.7.»
```

De ahí salía una hipótesis razonable, y con `'strict' => false` en
`config/database.php` era muy creíble: MySQL coacciona `.7.` a su prefijo
numérico y **escribe una fila de basura**, apuntando a una subunidad que no es —
y esa fila entraría en el cálculo de la definitiva de quien fuera.

**No pasa.** `notas` lleva `FOREIGN KEY (subunidad_id) REFERENCES subunidades(id)`,
el valor coaccionado es `0`, la subunidad 0 no existe y MySQL rechaza el INSERT.
El endpoint responde **500** y no escribe nada. Es lo mismo que la
[§4 de 13-actividades](13-actividades.md) encontró en
`ws_actividades_compartidas`: **la integridad la sostiene el esquema y no el
código**.

Y para quien lo usa, la corrección a la §3.1 no es un matiz: «no guarda nada» es
un botón que no hace nada, y **500** es un error en pantalla.

### La conclusión que saqué de más, y que era falsa

De ahí salté a escribir —en un commit y en la pizarra de la noche— que
**`notas_finales` no lleva ninguna de esas claves** y que por eso las inyecciones
de esta noche podían escribir en ella, lo cual le daría a la fase 2 un argumento
de integridad además del de los duplicados.

Medido contra `information_schema` al ir a verificarlo:

| Tabla | Claves ajenas |
|---|---|
| `notas` | 2 — `alumno_id`, `subunidad_id` |
| `notas_finales` | **3** — `alumno_id`, `asignatura_id`, `periodo_id` |

Y estaba escrito desde antes: la §2 del 10, al hablar del índice único, aclara
«solo hay tres índices de clave foránea». **Lo que le falta a `notas_finales` no
es integridad referencial: es la clave única**, que es lo que aquel documento dijo
siempre y lo que la fase 2 viene a poner. La fase 2 no gana ningún argumento; el
de los duplicados era el bueno y basta.

Se deja escrito en vez de borrado porque el error es más instructivo que el dato,
y porque tuvo **tres saltos y no uno**:

1. Generalicé de un sitio medido a la tabla de al lado sin mirarla.
2. La sesión que coordina lo subió a «dato estructural de la noche» y le añadió un
   argumento que yo no había dado.
3. Y llegó al resumen del usuario como hecho.

Nadie mintió, y el dato creció en cada salto. Es la misma forma que persiguen la
§52 y la §53 —«se revisó entero» significando «se revisó la rama que abrí», una
lista de sitios convertida en una lista de fallos— aplicada esta vez a una
conclusión propia y con un amplificador delante. Las dos reglas que deja:

> **Un sitio medido no autoriza a hablar del de al lado.**
>
> Y lo que llega de otro y suena a hallazgo estructural **se comprueba antes de
> subirlo de rango**, no después: diez segundos de `information_schema` contra dos
> horas de una conclusión falsa dentro de un documento.

Y una tercera, sobre el aviso: se mandó **en cuanto se vio, sin esperar a
arreglarlo del todo**. Veinte minutos más tarde ya estaba en el resumen de la
mañana y en un argumento para reabrir la fase 2. **Para una corrección, rápido
gana a pulido; para un arreglo, al revés.**

---

## 62. El observador del grupo, y la prueba de por qué muerde una copia (22 ago 2026)

Sale del hueco de cobertura de `comportamiento`: tres rutas que no miraba ningún
test al 22 ago, las tres `auth.personal` y las tres `PUT` que **solo leen** — el
verbo es del front, no escriben nada. Fijadas por
`tests/Contrato/ObservadorDelGrupoTest.php`, cinco tests y 30 aserciones.

### 62.1. Lo que enseña, y no es el fallo: arreglar una copia deja la otra rota

`putObservadorPeriodo()` es `putObservadorCompleto()` **copiado**. La única
diferencia entre los dos es que el segundo filtra las notas por
`p.id = :periodo_id`; el bloque que resuelve el grupo es idéntico. Y **están en
el fichero en orden inverso al que sugiere el nombre**: `Periodo` aparece antes
que `Completo`, que es justo la clase de detalle que hace que alguien parchee uno
y se vaya convencido de haberlo arreglado.

Eso ya lo decía el [§52](#52-el-bucle-de-reordenar-copiado-en-cinco-controladores-21-ago-2026)
como argumento. Aquí está el experimento:

> Se parcheó **solo** `putObservadorCompleto` —comprobando el array antes de
> indexarlo— y se corrió la clase entera. Cayeron **dos tests**, los dos de esa
> ruta, **y el de `observador-periodo` siguió en verde**.

O sea que un arreglo de la copia buena deja la otra rota **y ningún test lo
dice**, a menos que estén separados. Es la razón de fondo por la que en este
documento cada copia lleva su propio test en vez de uno que valga para las dos.
Y es la misma forma que el §47.2: al tapar un camino, la pregunta siguiente es
cuál es el otro.

### 62.2. Un grupo de otro año responde 500

Las dos rutas resuelven el grupo así:

```php
$grupo = DB::select($consulta, [':year_id' => $user->year_id, ':grupo_id' => $grupo_id])[0];
```

El `[0]` da por hecho que hubo fila. La consulta filtra por
`g.year_id = :year_id`, así que **un `grupo_id` de otro año no devuelve nada y la
petición muere**. El grupo existe y no está borrado: lo único que falla es el
año.

**Y el año no lo elige quien llama**: sale de `$user->year_id`, del contexto. Un
coordinador que se cambia de año y vuelve a una pantalla que tenía abierta manda
el mismo `grupo_id` de antes y se come un 500 sin haber hecho nada raro. No es un
caso límite, es un martes.

Sin `grupo_id` en el cuerpo pasa lo mismo: `Request::input()` devuelve `null`, la
consulta no casa y se llega al mismo `[0]`. **500 donde CLAUDE.md pide un 422.**

Se fija y no se arregla: son endpoints vivos en los dieciséis colegios y cambiar
el 500 por un 404 cambia lo que ve una pantalla.

### 62.3. Cuatro `[0]` más, señalados y sin cubrir

En el mismo fichero hay **cuatro `$libro = $libro[0];`** sin comprobar, dos en
cada copia del método. No están cubiertos: viven dentro del bucle de alumnos y
para dispararlos hace falta **un alumno sin libro de comportamiento**, que el
seed no trae. Queda dicho con su condición de disparo para quien coja ese hueco.

### 62.4. `situaciones-por-grupos` devuelve el colegio entero

No recibe `grupo_id` ni filtra por titular: recorre **todos los grupos del año**
y por cada uno saca `nombres`, `apellidos`, `celular`, `direccion`, `religion` y
`fecha_nac` de sus alumnos. Con `auth.personal`, así que **cualquier profesor lo
alcanza**, no solo un coordinador.

No se cierra: es la familia del §5 de [09-pendientes.md](09-pendientes.md) —las
rutas de `auth.personal` que Joseth decidió el 21 ago no cerrar, porque cerrarlas
puede dejar fuera a quien hoy trabaja con ellas—. Lo que aporta el test es que
**el alcance quede medido**, para que esa decisión se tome sabiendo qué sale.

### 62.5. Y una de método: la guarda que cazó lo que nadie buscaba

El test de la §62.4 llevaba un `assertGreaterThan(1, ...)` puesto **por la
regla**, sin sospechar nada. Falló: el seed trae **un solo grupo por año**, y con
uno solo la respuesta es idéntica devuelva «todos los grupos» o «el mío». Habría
sido un verde que no distingue nada. El segundo grupo se monta ahora dentro del
test, y la transacción lo deshace.

**Van ocho veces que el seed deja pasar un verde que no mide** —dos de esta
noche—. Lo que hace distinta a ésta es que la regla cazó algo **cuando quien la
aplicaba no sabía qué buscar**, que es la única prueba real de que una regla se
ha ganado el sitio.


---

## 65. `perfiles/*` no opera sobre la persona que dice — tres cosas y una mina (22 ago 2026)

El aviso más claro sobre este controlador no estaba en el backend: estaba en la
cabecera de `PerfilesApi.ts`, escrita por quien migró el front.

> **CUIDADO CON ESTE RECURSO.** `PerfilesController` es el más engañoso del
> backend: cinco de sus métodos —`show`, `destroy`, `forcedelete`, `restore`,
> `trashed`— operan sobre **GRUPO**, no sobre persona. `GET perfiles/show/{id}`
> devuelve el grupo cuyo id coincide, no el perfil.

Esta sección lo mide desde este lado. **Los tres se fijan y no se arreglan**, por
la misma razón que `preguntas/edicion`: arreglar el primero **enciende** un
guardado que lleva años apagado en los dieciséis colegios, y eso lo decide el
colegio. Fijado por `PerfilesEscribeEnOtraTablaTest`, seis casos.

### 65.1 `putUpdate` no guarda nunca desde la pantalla de perfil

Cuatro ramas, que comparan `tipo` contra `'Profesor'`, `'Alumno'`, `'Ac'` y
`'Usuario'`. La pantalla de perfil manda los **códigos cortos** del front. Ninguno
casa: el método recorre los cuatro `if`, no entra en ninguno, **cae hasta el final
sin `return`** y responde 200 con cuerpo vacío. El botón dice «Datos guardados» y
la fila no se ha tocado.

Y las cuatro etiquetas no son coherentes ni entre sí: tres son el nombre largo y
la cuarta, `'Ac'`, es un código corto. **Son dos vocabularios mezclados dentro del
mismo `switch`** — la misma forma que la [§50](05-codigo-muerto-y-roto.md)
encontró en `solicitar-cambios`, donde `'Al'` era el código del front y no el
valor de `users.tipo`. Allí el vocabulario cruzado producía un hallazgo falso;
aquí esconde uno verdadero.

El test prueba los cinco códigos cortos de golpe y **la mitad de al revés en el
mismo fichero**: con `'Profesor'` sí guarda. Sin esa mitad, el caso pasaría igual
si el método estuviera roto por cualquier otro motivo.

### 65.2 La rama `'Usuario'` coge el modelo equivocado

```php
if (Request::input('tipo') == 'Usuario') {
    $perfil = Acudiente::findOrFail($id);
```

Desde la rejilla de usuarios —que sí manda los nombres largos— editar la fila de
un administrador **escribe sobre el acudiente del mismo id**.

**No es autorización: es identidad.** El id se comprueba —la ruta lleva
`persona.propia:persona_id`— y después se usa contra **otra tabla**. Es primo del
`asked_id` y del `foto_id` de la [§53](05-codigo-muerto-y-roto.md), un paso más
allá: allí se **leía** la fila de otro, aquí se **escribe en la tabla de otra
cosa**. Ningún guard puede ver esto, porque el guard mira de quién es el id y no a
qué tabla va.

**Y destruye una fecha.** `getUsuariosall` rellena con la cadena **«N/A»** las
columnas que no aplican a cada rama de su `UNION`; la rejilla reenvía lo que
recibió y `putUpdate` lo escribe tal cual. `acudientes.fecha_nac` es una columna
`DATE` y, con `'strict' => false` en `config/database.php`, MySQL no rechaza «N/A»:
la guarda como `0000-00-00`. **La fecha anterior no se recupera.**

Es el tercer sitio de la noche donde `strict => false` convierte un error en un
dato silencioso, después del `created_at` de la fase 0 y de la
[§64](05-codigo-muerto-y-roto.md). Empieza a parecer menos una nota al pie y más
una decisión pendiente.

### 65.3 La mina: `perfiles/destroy/{id}` borra un grupo

`Grupo::findOrFail($id)->delete()`, con `auth.personal`. **Hoy no lo dispara nadie
por accidente**: el botón de borrar de la rejilla se pinta con `is_superuser`, y
el `SELECT` de `getUsuariosall` no devuelve esa columna, así que la condición es
siempre falsa.

O sea que lo único que separa la rejilla de usuarios de un botón que manda grupos
a la papelera es **una columna que falta en un `SELECT`** — y añadirla es lo
primero que hace cualquiera que necesite saber quién es administrador.

Por eso el aviso **no vive solo aquí**: está escrito encima de ese `SELECT`, en
`PerfilesController::getUsuariosall`, que es donde lo va a leer quien lo toque. Un
aviso en un documento que hay que saber que existe no protege de nada.

Y el test que lo fija —`test_usuariosall_no_devuelve_is_superuser`— lleva el
mensaje en el `assert`, no en un comentario: cuando se ponga en rojo, lo que se
lee no es «actualiza el test» sino **«esto enciende un botón que borra grupos;
mira antes qué hace ahora esa ruta»**.

### Lo que enseña el conjunto

Los tres fallos tienen la misma raíz y no es el descuido: **este controlador
mezcla dos dominios**. Se llama `Perfiles`, cinco de sus métodos operan sobre
`Grupo`, uno escribe en `Acudiente` creyendo que escribe en un usuario, y su
`putUpdate` habla un vocabulario distinto del de la pantalla que lo llama.
Ninguna de las herramientas de esta noche podía verlo: no hay concatenación, no
falta ningún guard, el id que llega es el correcto y todas las rutas responden
200.

Lo que lo destapó fue **ejecutarlo y mirar la fila**, que es lo mismo que lleva
encontrando cosas desde la §14 — y esta vez con el aviso del front delante, que
llevaba escrito desde la fase 11 y nadie había traído a este lado.

---

## 66. Los requisitos de matrícula: «Actualizado» sin haber actualizado (22 ago 2026)

Cuatro rutas de `requisitos` que no miraba ningún test al 22 ago, las cuatro
`auth.personal`. El carril no se eligió por carpeta sino por una pregunta —**qué
contestan cuando no han escrito nada**— y por eso salieron dos respuestas que
mienten donde una lectura por controlador habría dado un test de `store` y otro
de `index`.

Fijado por `tests/Contrato/RequisitosDeMatriculaTest.php`: cinco tests, 34
aserciones. **Los tests miran `updated_at`, `updated_by` y el tamaño de la tabla,
nunca el cuerpo de la respuesta** — si el endpoint hubiera insertado en vez de
actualizar, el conteo lo dice y la cadena no.

### 66.1. Dos respuestas que mienten

```php
$consulta = 'UPDATE requisitos_matricula SET requisito=?, descripcion=?, updated_by=?, updated_at=? WHERE id=?';
DB::select($consulta, [$requ, $descrip, $this->user->user_id, $now, $id]);

return 'Actualizado';
```

El `id` llega del cuerpo y **nadie mira cuántas filas tocó**. MySQL no se queja
de un `WHERE` que no casa con nada: afecta a cero y sigue. Así que el 200 y la
cadena `'Actualizado'` no dicen que se haya escrito. Es la familia del
[§54](#54-ocho-rechazos-que-contestan-con-el-código-de-otra-cosa-21-ago-2026) y
la que `tools/respuestas-que-mienten.py` existe para encontrar.

`requisitos/alumno` es la misma forma sobre `requisitos_alumno`, y **es la que
importa**: esa tabla no guarda un catálogo, guarda **el estado de la matrícula de
una persona**. Un «Actualizado» que no actualizó nada, en una pantalla de
secretaría, es **un papel que consta entregado y no lo está**. Eso no se descubre
nunca desde el backend: se descubre el día que alguien reclama.

### 66.2. Dos de alcance, medidas y no cerradas

Las dos son la familia del §5 de [09-pendientes.md](09-pendientes.md) —rutas de
estructura con solo `auth.personal`, que Joseth decidió el 21 ago no cerrar
porque cerrarlas puede dejar fuera a un coordinador—. Se dejan **medidas con el
nombre de la columna que falta**, para que el día que se decidan sea un cambio de
una línea y no una investigación:

- **`requisitos/update` escribe sobre un requisito de otro año.** Al `WHERE` del
  `UPDATE` le falta **`year_id`**, y el año de quien llama no interviene.
- **`requisitos/listado-observaciones` obedece al `year_id` del cuerpo**:
  `Request::input('year_id', $this->user->year_id)` — el año propio es solo el
  **valor por defecto**. Devuelve `nombres`, `apellidos` y `celular` de los
  alumnos con observación. Que el año propio sea el defecto y no el límite es lo
  que convierte esto en una decisión y no en un descuido.

### 66.3. El mismo experimento, en un segundo dominio

Se parcheó **solo** `putUpdate` —usando `DB::update` y rechazando cuando afecta a
cero filas— y cayó **un** test: el del id inexistente. Los otros dos siguieron en
verde, y cada uno por su motivo, que es lo que hace útil la medida:

- el de **otro año** siguió verde porque ése **sí escribe**;
- el de **`requisitos/alumno`** siguió verde porque es **la copia gemela y
  necesita su propio arreglo**.

Es el segundo experimento independiente de la misma noche que demuestra lo que la
[§52](#52-el-bucle-de-reordenar-copiado-en-cinco-controladores-21-ago-2026)
afirmaba y la [§62.1](#621-lo-que-enseña-y-no-es-el-fallo-arreglar-una-copia-deja-la-otra-rota)
midió en `comportamiento`. **Dos dominios distintos, dos veces el mismo
resultado: arreglar una copia deja la otra rota y ningún test lo dice**, salvo
que estén separados. Un patrón medido dos veces ya no es una anécdota.

### 66.4. Y una del arnés: entrar mueve el periodo del usuario

Los helpers de estos tests piden el token **antes** de leer nada del contexto, y
lleva su comentario al lado. El motivo lo encontró otra sesión la misma noche:

> **Autenticarse cambia el estado del usuario**: entrar mueve `users.periodo_id`
> al periodo vigente.

Así que un test que lea el año o el periodo **antes** de pedir el token está
midiendo un estado que su propio login va a alterar. Lo que se lee entonces es el
periodo del seed, cuyo año puede no tener asignaturas con alumnos, y todo lo que
cuelga sale vacío — con la cara de **«falta seed»**, que manda a reconstruir la
base o a comparar migraciones cuando el fallo está en el test. Costó cinco tests
en rojo.

Es la séptima vez esta noche que **un instrumento falla con la cara exacta del
problema que se estaba buscando**, después del detector de asimetría, el fichero
de medición desenganchado y el test verde que fija el comportamiento viejo.


---

## 67. Tres rechazos más de la §54, y por qué no salieron entonces (22 ago 2026)

`POST api/users/crear-administrador`, `crear-enfermero` y `crear-psicologo`
respondían **`404, 'Sin autorización'`** a quien no es superusuario. Es
exactamente el defecto de la [§54](#54-ocho-rechazos-que-contestan-con-el-código-de-otra-cosa-21-ago-2026):
un código que significa «esa fila no está» usado para decir «no puedes», en un
API donde se gastó una serie entera —§44, §47, §49, §50, §53— en que signifique
lo primero. Pasan a **403**.

### 67.1 Lo que hay que llevarse: sobre qué población se cerró la serie

La §54 no se equivocó de criterio. Se quedó corta de **alcance**: barrió las
**veintidós rutas de `auth.token` a secas**, y estas tres son `auth.personal`.
El defecto era el mismo, el criterio para arreglarlo era el mismo, y aun así
sobrevivieron tres — porque a partir del día en que la serie consta cerrada,
**nadie vuelve a buscar ese fallo en ningún sitio**.

> **Cuando una serie se cierra, hay que anotar sobre qué población se cerró.**

Es la hermana de la lección del [13 §2](13-actividades.md): «se revisó entero»
leído tres meses después significa «se revisó la rama que abrí». Aquí, «los
rechazos que mienten están arreglados» significaba «los de `auth.token` están
arreglados», y nada lo decía.

### 67.2 Por qué se cambió esta noche y no la semana que viene

No es «se cambió porque es lo correcto». Se cambió **en la única ventana en que
era invisible**, y el argumento es medible:

- Hoy solo las llama `myvc_front`, desde los tres botones de `UsuariosCtrl.ts`
  (`:86`, `:102`, `:118`) a través de `UsersApi`. `myvc_flutter` no las llama y
  `myvc_front_2` tampoco; la aplicación nueva las tiene en su repositorio de
  datos pero ninguna pantalla lo usa todavía.
- Y su `.catch` **está declarado sin argumentos**: `function(){ toastr.error('No
  se pudo crear'); … }`. No mira `status`, no lee el cuerpo, no recibe siquiera
  el objeto de error. Enseña un texto fijo escrito en el front.

Es un caso **más** limpio que los de `calendario/*`, que al menos pintaban el
mensaje del servidor: aquí no se nota ningún cambio de la respuesta, ni de código
ni de cuerpo.

**Pero esa pantalla se está reescribiendo.** En la versión nueva ese `catch` mudo
va a enseñar el mensaje del servidor, y a partir de entonces un cambio de código
sí sería visible. Cambiarlo hoy es gratis; dentro de una semana ya no.

### 67.3 Y dos cosas de `usernames-check`, fijadas y no juzgadas

Las cuatro rutas del controlador estaban a **1 de 5** y quedan cubiertas por
`tests/Contrato/UsersCuentasTest.php`. Dos de lo que se vio se fija **sin
juzgarlo**, con el porqué escrito al lado, que es la costumbre que pedía la §54:

- **Con el texto vacío devuelve el colegio entero.** La consulta es
  `WHERE username LIKE :texto` con `$texto.'%'`, así que sin texto el patrón
  queda en `%`. No es un fallo de autorización —es `auth.personal`, y el personal
  ve al colegio— pero es **enumeración completa de nombres de usuario en una ruta
  que parece de autocompletado**. Es la misma forma que `perfiles/usernames`:
  **son dos puertas al mismo dato**, y la decisión que se tome sobre una tiene
  que tomarse sobre la otra.
- **Devuelve también los borrados**, porque la consulta no filtra `deleted_at`.

El seed no trae ningún usuario borrado, así que el test **planta uno**: sin eso
pasaba sin medir nada. Comprobado al revés añadiendo el filtro —cae ese y solo
ese—. **Van nueve** verdes huecos por seed vacío.

Lo demás del fichero se fija porque es lo que hay y se dice que no se juzga: los
tres `crear-*` cuelgan los roles 1, 7 y 11 escritos a mano en tres copias
literales, nacen activos y con la contraseña `123456`. Esa constante es material
de la [§26](#26) y merece su propia pregunta.

## 61. La lista de usuarios del colegio: el autocompletado que filtra (22 ago 2026)

Esta sección **no trae un arreglo, trae una recomendación medida**. El fallo es
real; el arreglo evidente —ponerle el guard que llevan sus vecinas— habría roto
los dieciséis colegios; y la salida que sí sirve apareció **escribiendo esta
sección**, no investigando el fallo. Está contada en ese orden a propósito,
porque lo que frenaba esto no era código.

### 61.1 Qué pasa

`GET api/perfiles/usernames` es, entero:

```php
public function getUsernames()
{
    $usernames = DB::select('SELECT username FROM users');
    return $usernames;
}
```

**Todos los usuarios del colegio, sin filtro y sin límite.** Su guard efectivo es
`auth.token` **a secas**: lo alcanza cualquiera con sesión válida, incluidos
alumnos y acudientes.

Sus dos vecinas, que hacen lo mismo en más pequeño, sí exigen ser personal:

| Ruta | Guard |
|---|---|
| `GET perfiles/usernames` | `auth.token` |
| `GET perfiles/usuariosall` | `auth.token` + `auth.personal` |
| `PUT users/usernames-check` | `auth.token` + `auth.personal` |

### 61.2 Cómo apareció, que es lo reutilizable

**No lo encontró ninguna de las tres herramientas de autorización.** Salió de
aplicarle a los guards la señal que `c8` y `22` habían afinado esa misma noche
para las inyecciones de SQL (§55, §59, §60): **la asimetría — una línea que no
hace lo que hacen sus hermanas.**

Se descubrió leyendo consultas —el `?` que falta entre dos que sí ligan— pero no
es una regla sobre SQL. Vale igual para **el guard que falta entre dos rutas
vecinas que sí lo llevan**. Es la misma pregunta de la §53 girada: allí era «¿qué
más lee este identificador?»; aquí es «**¿por qué esta línea es distinta de la de
al lado?**».

### 61.3 Por qué no se cierra, y no es prudencia genérica

Ponerle `auth.personal` es una línea. **Rompe los dieciséis colegios**, y la
prueba la dio la sesión que coordinaba el árbol de `myvc_front` esa noche, con
fichero y línea:

- `app/scripts/usuarios/UserConfig.ts:122` — la pantalla cuelga de `panel.user`,
  que **no declara `needed_permissions`**. Veinte líneas antes, `panel.usuarios`
  **sí** lo declara (`can_edit_usuarios`). La asimetría también está aquí, **y
  aquí es deliberada**: una es «mi perfil», la otra es «los usuarios».
- `app/scripts/panel/panel.html:60` — el enlace vive en el desplegable del
  avatar, **sin un solo `ng-if` de rol**. Lo ve cualquiera que inicie sesión.
- `app/scripts/usuarios/UserConfiguracionCtrl.ts:115` — `PerfilesApi.usernames()`
  se llama en el cuerpo del controlador, **fuera de toda comprobación**: se
  dispara incluso cuando `canConfig` es falso, es decir **mirando el perfil de
  otra persona**.

Es decir: la pantalla es el único sitio donde un alumno puede cambiarse su propio
usuario y su contraseña, y cerrarle la ruta se la deja inservible.

### 61.4 Para qué se usa la lista, que es peor de lo que parecía

`app/scripts/usuarios/userConfiguracion.html:210`:

```html
uib-typeahead="... for nombresusu in $ctrl.nombresdeusuario | filter:{username:$viewValue} | limitTo:8"
```

**No es una comprobación de «este nombre está libre». Es un autocompletado.** Un
alumno abre su configuración, empieza a teclear, y la pantalla **le va sugiriendo
los nombres de usuario reales de sus compañeros y de sus profesores**.

De ahí salen las dos consecuencias que hay que tener escritas antes de tocar
nada:

1. **`comprobarusername/{username}` no es sustituto directo.** Contesta
   «libre/ocupado» sobre uno; no puede alimentar un autocompletado. Sustituir la
   llamada es **quitar el autocompletado** y comprobar la disponibilidad al salir
   del campo: un cambio de comportamiento de la pantalla, no una línea.
2. **Se cierra con el sustituto puesto, no antes** — y «puesto» significa
   **desplegado en ese colegio**, no fusionado.

### 61.5 La regla, que es la de CLAUDE.md girada

CLAUDE.md dice que **un arreglo del front que exponga un endpoint no se publica
hasta que el guard del backend esté desplegado, no solo fusionado**. Esto es el
mismo filo por el otro lado: **un guard del backend no se cierra hasta que el
front que llamaba a esa ruta esté desplegado, no solo fusionado.**

Y aquí muerde especialmente, porque `myvc_front` **está congelado por decisión
del colegio, ni siquiera correcciones**, y sigue desplegado en los dieciséis. El
sustituto llegará cuando la pantalla `usuarios/` se reescriba en Angular — o sea,
por la aplicación nueva y colegio a colegio.

### 61.6 Para calibrar la prisa

Se va **la lista de nombres de usuario**, no el directorio. **No es la §34**,
donde salía la ficha entera de cada alumno con fecha de nacimiento, celular,
dirección, religión y deuda. Es real y hay que cerrarlo, pero **no es lo que hay
que correr a arreglar**, y confundirlo con la §34 llevaría a cerrarlo con prisa,
que es justo lo que rompe las pantallas.

### 61.7 La salida, medida: devolver una lista vacía

Las dos opciones evidentes son malas por motivos opuestos. **Esperar** a que la
`usuarios/` nueva esté desplegada en cada colegio deja el fallo abierto meses y
va colegio a colegio; **cerrar ya** con `auth.personal` les deja a alumnos y
acudientes sin la pantalla de su usuario y su contraseña.

**Hay una tercera y está medida: dejar la ruta abierta y que deje de devolver la
lista.** Rompe el autocompletado —que *era* la fuga— y **no rompe la pantalla**,
y sobre todo **no espera a ningún despliegue del front**, que es lo que hacía
inaceptables a las otras dos.

Lo comprobó la sesión del árbol de `myvc_front`, y no de memoria:

- **`nombresdeusuario` lo consume solo el typeahead.** Cuatro apariciones en todo
  el proyecto: el tipo, la inicialización a `[]`, la asignación y el
  `uib-typeahead`. Nada más lo lee.
- **Con lista vacía no revienta**, verificado en el código de las librerías
  (AngularJS 1.8.3 y `angular-ui-bootstrap`): `filterFilter` devuelve `null` tal
  cual y sale de `isArrayLike`, `limitToFilter` igual, y el typeahead hace
  `resetMatches()` cuando no hay coincidencias. El campo se sigue escribiendo y
  guardando.
- **Y ese camino ya está vivo hoy.** El controlador tiene manejador de error:

  ```js
  PerfilesApi.usernames().then(r => $ctrl.nombresdeusuario = r,
                               () => toastr.error('No se trajeron los nombres de usuario'));
  ```

  Cada vez que esa petición falla en producción, **la pantalla ya corre con la
  lista vacía, y nadie lo ha reportado nunca como pantalla rota**. No es una
  predicción: es el caso vacío funcionando en los dieciséis colegios desde
  siempre.

#### El contrato, que hay que respetar al pie de la letra

```
devolver  []                 → bien: no sugiere y todo lo demás funciona
devolver  null               → bien: filterFilter lo devuelve tal cual
devolver  {}  o  {"…": []}   → ROMPE: filter:notarray, «Expected array but received: [object Object]»
```

**Un objeto vacío no es una lista vacía.** `PerfilesApi.usernames()` es
`api.get(RECURSO + '/usernames')` a secas, sin desenvolver nada, así que **lo que
devuelva el backend cae directo en el filtro**. Envolverlo en `{"usernames": []}`
—que es lo que haría cualquiera que "moderniza" la respuesta de paso— **lanza y
se lleva la pantalla**, que es exactamente lo que esta opción quería evitar.

Si en vez de vaciarla se decide que conteste solo sobre un nombre pasado por
parámetro, **el resultado tiene que seguir siendo un array** con cero o un
elemento y con la forma de hoy, `[{username: "..."}]`: la plantilla lee
`nombresusu.username`.

#### La regla general, que sirve para las demás rutas

**«Devolver vacío en vez de cerrar» es seguro solo si el cliente trata la
respuesta como una colección.** Aquí lo hacía. En cuanto una pantalla lea un
campo de dentro —`r.total`, `r.usernames`—, devolver `[]` la rompe igual que
cerrarla y encima con un error más raro de diagnosticar. **Exige un grep del
llamante antes de aplicarlo, ruta por ruta.**

### 61.8 Lo que queda por decidir

Con lo anterior medido, la decisión ya no es entre tres mundos:

1. **Recomendada: vaciar la respuesta**, respetando el contrato de arriba. No
   espera despliegue del front, no rompe nada, y quita la fuga. Se puede hacer en
   días, no en meses.
2. **Esperar** a la `usuarios/` en Angular y cerrar la ruta detrás, colegio a
   colegio. Sigue siendo el final limpio; la 1 no lo estorba, lo adelanta.
3. **Cerrar ya con `auth.personal`.** Descartada salvo que se acepte romper la
   pantalla de alumnos y acudientes.

Lo único que la 1 no resuelve: la ruta sigue existiendo y sigue alcanzable por
cualquiera con sesión. Deja de filtrar, pero **no vuelve a tener el guard de sus
dos vecinas** hasta el paso 2.

## 68. Un campo que no se manda no es un campo que no cambia (22 ago 2026)

Medido **ejecutando** por el árbol de `myvc_front`, con las filas restauradas y
verificadas después de cada prueba; **ampliado y confirmado en el código** desde
este lado, donde resultó ser **más ancho de lo que se veía desde el front**.

`ProfesoresController::sanarInputUser()` **rellena con valores por defecto lo que
el cuerpo no trae**. Y el formulario viejo no trae cuatro campos. De ahí la
frase, que es lo que hay que llevarse:

> **Un campo que no se manda no es un campo que no cambia: es un campo que se
> pisa.**

### 68.1 Corregirle el teléfono a un docente le devuelve la entrada al sistema

```php
$usuario->is_active = Request::input('is_active', 1);   // <- por defecto UNO
```

Medido: `users.is_active` de un profesor pasa de **0 a 1** con el cuerpo exacto
de la pantalla vieja, respondiendo 200. **Reactiva una cuenta que alguien cerró**,
y quien edita no se entera.

### 68.2 Son SEIS sitios, y solo DOS son el fallo — el discriminador no es el método

Aquí hubo dos recuentos malos antes de éste, y los dos con la misma forma:

- **`f3` dijo cinco.** Había contado con `grep … | head -5`: **un recuento
  truncado presentado como total.** Es la misma familia que el `| tail` que se
  traga un código de salida — **un tubo que limita en silencio y contesta como si
  hubiera contestado entero.**
- **`myvc-front-99` dijo seis y clasificó cuatro como fallo**, usando el nombre
  del método: todo lo que estuviera en `putUpdate` era edición.

**El nombre del método no lo dice.** `putUpdate` **también da de alta**: crea la
cuenta del alumno o del profesor que todavía no tiene una. Lo que discrimina es
si la línea cuelga de un **`new User`** o de un **`User::find()`**:

| Sitio | Método | Objeto | ¿Fallo? |
|---|---|---|---|
| `ProfesoresController:138` | `postStore` | `new User` | no — alta |
| **`ProfesoresController:348`** | **`putUpdate`** | **`User::find($profesor->user_id)`** | **SÍ** |
| `ProfesoresController:371` | `putUpdate` | `new User` | no — alta |
| `AlumnosController:278` | `postStore` | `new User` | no — alta |
| **`AlumnosController:723`** | **`putUpdate`** | **`User::find($alumno->user_id)`** | **SÍ** |
| `AlumnosController:757` | `putUpdate` | `new User` | no — alta |

**Dos fallos y cuatro altas.** En una cuenta que se está creando, `is_active = 1`
por defecto es el comportamiento correcto y **no se toca**.

Esto importa porque el aviso que dio `myvc-front-99` —«sin la distinción, alguien
arregla los seis y rompe el alta»— **era exacto, y su propia clasificación habría
roto dos**: la 371 y la 757 son altas dentro de `putUpdate`. La regla utilizable
es **`new User` contra `User::find`**, no el nombre del método.

**Y la magnitud sigue en pie**: el fallo de `AlumnosController:723` afecta a la
ficha de alumno, que un colegio tiene ~1.280 frente a 47 docentes.
`AlumnosController:723` trae además, en las líneas de al lado, **el mismo apaño
del correo** (`$usuario->email = Request::input('email2')`) y un
`is_superuser = 0` escrito a pelo.

### 68.2.1 La mina latente de al lado: la condición de la contraseña está invertida

`AlumnosController:726`, dentro del mismo bloque:

```php
if (Request::has('password')) {
    if (Request::input('password') == "") {        // <- solo si está VACÍA
        $usuario->password = Hash::make(Request::input('password'));
    }
}
```

Mandar una contraseña de verdad **no hace nada**; mandar la cadena vacía pone la
contraseña al **hash de la cadena vacía**.

**Hoy no muerde, y está comprobado por qué**: la pantalla vieja **no manda
`password`** en `alumnos/update` —`grep` en `AlumnosCtrl` y `PersonaCtrl`, cero
resultados—, así que `Request::has` es falso y el bloque no se ejecuta jamás.

> **Es la tercera pata de la lectura**: el controlador dice qué acepta, el `return`
> qué devuelve, **y el llamante si el fallo está vivo o solo latente**. Éste está
> **latente**, como `perfiles/destroy` borrando grupos (§65.3).

**Y como aquélla, se enciende sola el día que alguien añada el campo** — y es una
pantalla de fichas de alumno, así que va a pasar. La prueba que lo fija: `PUT` con
`password: 'algoDeVerdad'` y comprobar que **el hash cambia**. Hoy falla.

### 68.3 El correo no se pierde: se muda de columna

`if (!email1) { email2 = email }` y después `$usuario->email = email2`. Medido:
`users.email` pasó de la dirección **de la cuenta** a la **del profesor**. Existe
un quinto nombre, **`email1`, cuya única función es desactivar ese apaño**, y no
lo manda ningún cliente.

### 68.4 Lo que sí es lectura de código y NO está medido

`if (!is_superuser) is_superuser = false` **quitaría el superusuario** a quien lo
tuviera. **No está comprobado**: no hay ningún docente superusuario en la base
donde se midió. Se cita como lectura de código, no como hecho.

### 68.5 El fallo de fondo, que es del backend y no de la pantalla

**Ningún endpoint de lectura devuelve los veintiún campos juntos.** `show` se deja
tres. Así que **el contrato de `putUpdate` no se puede cumplir con lo que el
backend deja leer**: no es que la pantalla llame mal, es que **no existe una forma
correcta de llamar a ese endpoint**. Mientras siga así, cualquier cliente que no
mande los veintiuno hace daño — y ninguno puede mandarlos.

La pantalla nueva lo resuelve cargando de `GET profesores` en vez de `show`, o sea
**trayéndose 47 filas para editar una**. Se aceptó porque la alternativa era
guardar sin saber qué se pisa. Eso es el síntoma, no la cura.

### 68.6 La prueba que valdría para siempre

`PUT` con el cuerpo real de la pantalla **sobre un usuario con `is_active = 0`**,
comprobando que sigue en 0. **Hoy falla.** Y como en la §65, la condición hay que
**construirla en el `setUp`** —desactivar la cuenta— y no buscarla: la base de
tests no la trae, y un test que la busque pasaría sin medir nada. **Van diez.**

**Sin arreglar a propósito**: tocar esto cambia lo que hace un formulario en
dieciséis colegios y la aplicación vieja está congelada por decisión del colegio.
La excepción que se dio esa noche era sobre «el borrado del correo» y **esto es
otra cosa y más grande** — un permiso dado sobre un diagnóstico equivocado no
cubre el corregido.

### 68.7 Arreglado el 22 ago 2026, **con la palabra pedida y dada**

Se preguntó con la medición delante —incluida la [§69](#69), que es la que dice
que la mitad de alumno de esta sección **no estaba viva** porque la pantalla no
guardaba nunca— y Joseth contestó **que entre**, las dos cosas y con la casilla de
la contraseña encendida.

Se pidió porque el permiso anterior era sobre un diagnóstico más pequeño, y la
regla que lo obliga es la de arriba: **cuando la medición cambia lo que pasa, el
permiso se vuelve a pedir**, aunque sea la misma pantalla.

Qué hace, para que la decisión se tome sobre lo que es:

| Dónde | Antes | Ahora |
|---|---|---|
| `ProfesoresController:348` · `AlumnosController:723` | `is_active` se pisaba a 1 | si el cuerpo no lo trae, **no se toca** |
| las mismas dos | `users.email` se sustituía por el de la persona, o por `usuario@myvc.com` | sólo se escribe si vino `email2` |
| `AlumnosController:726` | escribía la contraseña **sólo si venía vacía** | `filled`: vacía o ausente no toca nada; una de verdad **sí la cambia** |
| las cuatro altas (`new User`) | `is_active = 1` por defecto | **igual**, y hay un caso que lo fija |

Lo que decide una línea y no otra es `new User` contra `User::find()`, como decía
la §68.2. `App\Support\CamposQueVinieron` es lo que permite contestar «¿lo mandó
el cliente?» **después** de que los `sanarInput*` hayan hecho su `merge` — a esa
altura `Request::has()` ya no lo sabe, y ése es el motivo de que sea una clase y
no un `if`.

**Lo único que enciende algo que hoy no funciona** es la contraseña: la casilla de
`alumnosEdit.html` pasa a cambiarla de verdad. Se ofreció dejarla fuera —es un
`if`— y se decidió encenderla: la pantalla la pide dos veces y la verifica, o sea
que promete lo que ahora hace.

---

## 69. La ficha de alumno no guarda: 422 «Datos incorrectos» **después** de escribir (22 ago 2026)

Salió de escribir la prueba que pedía la [§68.6](#686-la-prueba-que-valdría-para-siempre).
El caso del profesor se puso en rojo a la primera, como decía el documento; **el
del alumno no llegaba a la línea**: contestaba 422 antes. Un test que no alcanza
lo que quiere medir es un dato, no un estorbo — y éste tapaba una pantalla entera.

### 69.1 El mecanismo: se indexa dos veces lo que ya se convirtió una

`sanarInputAlumno()` convierte tres campos de `{id: N}` al número:

```php
if (Request::input('ciudad_nac')['id']) {
    Request::merge(array('ciudad_nac' => Request::input('ciudad_nac')['id'] ));
}
```

Y `putUpdate`, a continuación, los volvía a indexar:

```php
$alumno->ciudad_nac = Request::input('ciudad_nac')['id'];   // <- sobre un ENTERO
$alumno->tipo_doc   = Request::input('tipo_doc')['id'];
$alumno->ciudad_doc = Request::input('ciudad_doc')['id'];
```

Indexar un entero —o un null, si el campo no venía— es un aviso de PHP, no un
error. **Lo que lo convierte en 422 es Laravel**: `HandleExceptions::bootstrap`
hace `error_reporting(-1)` al arrancar, así que el aviso sube a `ErrorException`,
la caza el `catch (\Exception $e)` del propio método y sale
`abort(422, 'Datos incorrectos')`.

Eso último importa más de lo que parece: **no depende del `php.ini` del colegio**.
Es la lección de la [§46](#46) al revés — aquí buscas una diferencia entre
entornos y no la hay, porque el framework la borra.

### 69.2 La asimetría, otra vez, y esta vez entre métodos hermanos

`postStore` —el alta, en el mismo controlador— lee **los mismos tres campos sin
`['id']`** desde siempre. La [§59](#59) sacó tres inyecciones mirando dos consultas
vecinas que sí ligaban; aquí la vecina es un método:

> **La asimetría entre hermanas vale también entre dos métodos del mismo
> controlador.** Y el que se desvía no es siempre el nuevo.

### 69.3 Guardaba y decía que no

El desplegable de grupo de la ficha sólo pone `grupo` en el cuerpo **cuando alguien
lo toca** —`putShow` no devuelve ese objeto—, así que el guardado normal caía
también en `Request::input('grupo')['id']`, al final del método. Y ese está
**después** de `$alumno->save()` y de `$usuario->save()`: la ficha y la cuenta ya
estaban escritas cuando salía el 422.

O sea que hay dos formas de fallar, y la segunda es peor:

| Cuerpo | Qué pasaba |
|---|---|
| con los catálogos como objeto (lo que manda la pantalla) | 422 **sin escribir nada** |
| con los catálogos ya resueltos y sin tocar el grupo | **escribía** la ficha y la cuenta, y contestaba 422 |

Es la [§45](#45) por el otro lado: allí un `else` decía 200 sin hacer nada; aquí un
`catch` dice «Datos incorrectos» sobre algo que sí se hizo. **Las dos formas
engañan a quien mira el código de estado, y por eso este repo mira el resultado.**

### 69.4 Lo que esto le hace a la §68

**La mitad de alumno de la §68 no estaba viva: estaba tapada por esto.** El
`is_active` que se pisa a 1, el correo que se muda de columna y la condición
invertida de la contraseña son ciertos los tres, y **ninguno llegaba a ocurrir**
por el camino normal, porque el método no terminaba.

Por eso las dos cosas van juntas o no van: arreglar el 422 **enciende** los tres.
Un arreglo que devuelve la vida a una pantalla tiene que llegar con los guardas
puestos.

### 69.5 Y una corrección a la §68.2.1: el grep miró los ficheros de al lado

La §68.2.1 daba la condición invertida de la contraseña por **latente** con este
argumento: *«la pantalla vieja no manda `password` — `grep` en `AlumnosCtrl` y
`PersonaCtrl`, cero resultados»*.

La casilla existe. Está en **`alumnosEdit.html:229`**, atada a
`$ctrl.alumno.password`, y `$ctrl.alumno` es el objeto entero que `AlumnosEditCtrl`
manda al guardar. Los dos ficheros que se grepearon son las **rejillas**; el
formulario es otro.

> **Un grep de clientes vale lo que valen los ficheros que mira**, y «no lo manda
> nadie» es la afirmación más fácil de hacer con una muestra incompleta: no hay
> ningún resultado que la contradiga a la vista.

Con la casilla delante, el fallo se lee distinto: escribir una contraseña de
verdad **no hacía nada** —la condición pedía que estuviera vacía— y vaciarla
después de escribir en ella guardaba **el hash de la cadena vacía**, que es entrar
sin contraseña ([§26](#26)). No era una mina para el día que un cliente añadiera el
campo: era una tecla.

### 69.6 Cómo queda

Los tres `['id']` de más, fuera —la forma de `postStore`, que es la que siempre
estuvo bien—; los dos `Request::input('grupo')['id']` pasan a `grupo.id`, que es
nulo-seguro; y con la pantalla ya viva, los guardas de la §68 puestos en la misma
tanda. Lo fija `CamposQueSePisanTest`, once casos, y cada pieza del arreglo se
comprobó al revés: **ninguna se puede quitar sin que caiga por lo menos un caso**.

Un alta sin grupo deja de contestar 422 y crea al alumno sin matrícula, que es lo
que ya decía el `$grupo_id = false` de al lado.

### 69.7 La ruta ya constaba «comprobada», y el número no se movió

`PUT api/alumnos/update/{id}` sale como cubierta en `tools/cobertura-de-rutas.py`
desde la [§54](#54-ocho-rechazos-que-contestan-con-el-código-de-otra-cosa-21-ago-2026),
y sigue saliendo igual después de esto: **418 de 539 antes y después**. Lo que la
cubría era `RechazosQueMientenTest`, que comprueba **cómo rechaza** a quien no
tiene permiso — y un rechazo no entra en el cuerpo del método.

> **«Comprobada» puede significar que alguien miró sólo cómo rechaza.** La ruta
> que devuelve 403 al que no puede y 422 a todo el mundo tiene el mismo aspecto en
> la medición que una sana.

Es la hermana de la lección de la §54 —medir una ruta no es haberla juzgado— y
tiene una consecuencia práctica para la lista de huecos: **cuando el hueco se
elige por el número, conviene mirar si lo que cubre esa ruta es un caso que
escribe o uno que rechaza**. Los que rechazan son baratos de escribir y por eso
son los que más hay.

---

## 70. Qué se lleva por delante borrar un catálogo del colegio (22 ago 2026)

Hueco de cobertura elegido por la pregunta y no por la carpeta, que es lo que
funciona cuando el hueco es plano: de las 121 rutas sin comprobar, nueve son
`destroy` de catálogos repartidos por nueve controladores. **Una sola lectura.**

La pregunta era doble: qué se lleva por delante el borrado, y quién puede
llamarlo. La segunda ya está decidida —las 44 rutas de configuración se quedan
con `auth.personal`, 09— así que lo que había que medir era la primera.

### 70.1 Lo primero, y descarta el susto: las cascadas no disparan

El esquema está lleno de `ON DELETE CASCADE` apuntando a los catálogos:

| Quién | A quién | Qué haría un borrado real |
|---|---|---|
| `grupos.grado_id` · `df_grupos.grado_id` | `grados` | se lleva los grupos, y con ellos `asignaturas`, `matriculas` y `ws_actividades_compartidas` |
| `grados.nivel_educativo_id` | `niveles_educativos` | se lleva los grados, y de ahí lo anterior |
| `materias.area_id` | `areas` | se lleva las materias |
| `definiciones_comportamiento.frase_id` | `frases` | se lleva las definiciones |

**Ninguna se dispara**, porque los seis modelos son de papelera (`SoftDeletes`) y
`$grado->delete()` es un `UPDATE deleted_at`. El susto no está ahí. Conviene
saberlo escrito: **la cascada existe y está armada** para el día que alguien
escriba un `forceDelete()` en uno de estos controladores.

### 70.2 Lo que sí pasa: la papelera esconde una pantalla y no la otra

Medido, no leído — un grado del año actual, con su grupo y un profesor con una
asignatura dentro:

| Después de `DELETE api/grados/destroy/{id}` | Antes | Después |
|---|---|---|
| asignaturas del profesor (`asignaturas/listasignaturas/{id}`) | 1 | **0** |
| el grupo en la rejilla (`GET api/grupos`) | sale | **sigue saliendo** |

El grupo no se ha ido, las asignaturas siguen en su tabla y las notas también.
Lo que cambió es **quién las une**: `Profesor::asignaturas` hace
`inner join grados … and gr.deleted_at is null`, y la rejilla de `GruposController`
une por el mismo grado **sin ese filtro**.

> **La misma fila en la papelera esconde una pantalla y deja intacta la otra**,
> según lo que decidiera la consulta que las une. No es una regla del sistema: son
> catorce consultas decidiendo por su cuenta.

Y el contraste, que es lo que lo convierte en regla utilizable: `tipos_documentos`
entra en las listas de alumnos por `left join … and t.deleted_at is null`. Mandar
uno a la papelera **no esconde a ningún alumno**: le deja el tipo de documento
vacío, a la vista.

> **Con `left join` la papelera deja un hueco; con `inner join` esconde la fila
> entera.** Mismo gesto, misma columna, dos consecuencias.

### 70.3 El tamaño, que es lo que hay que decidir — **DECIDIDO Y APLICADO el 23 ago 2026**

> **Joseth: se impide, y el aviso dice cuántos grupos dependen.** De las dos
> salidas que se plantean abajo se tomó la primera. Está en
> `App\Support\CatalogoEnUso` y fijado por `BorrarUnCatalogoEnUsoTest`, con **las
> dos mitades**: que corta con 422 *y que no escribe*, y que un grado **sin**
> grupos se sigue borrando — sin esa segunda, un candado que bloquea siempre
> pasaría por arreglo.
>
> **No se aplicó a los demás catálogos, y eso es la parte que hay que leer**: la
> regla de la §70.2 tiene una segunda mitad que salió al mirar los seis —**no es
> sólo el tipo de `join`, es si lo que desaparece era el sentido de la fila
> hija**—. `definiciones_comportamiento` guarda `frase_id` **y `frase`**, con el
> texto ya copiado, así que bloquear `frases` dejaría **235 de 426** sin poder
> retirar del banco a cambio de nada. `niveles_educativos`, `areas` y `materias`
> tienen la misma forma que `grados` y **están sin aplicar a propósito**: bloquear
> niveles dejaría **4 de 4** sin poder borrarse nunca. Las cuentas están en el 09.


Un clic en «eliminar» de la pantalla de grados **apaga la planilla de todos los
profesores de ese grado**: no ven asignaturas, así que no pueden poner notas. Y no
sale ningún error en ninguna parte — la rejilla de grupos sigue enseñando el grupo,
así que quien lo mire desde administración no ve nada raro.

**No hay ruta de `restore` para grados.** Las cinco que tiene el controlador son
index, show, store, update y destroy. Así que desde ninguna pantalla se puede
deshacer: hay que entrar a la base.

**Se fija y no se juzga**, con el porqué escrito al lado, que es lo que pedía la
§54: qué debe pasar con los grupos de un grado borrado es una pregunta del
colegio. Las dos salidas razonables —que `destroy` se niegue si el grado tiene
grupos vivos, o que haya `restore`— son código pequeño; lo que no es pequeño es
decidir cuál.

### 70.4 Y tres respuestas que decían que sí — arregladas

`EscalasDeValoracionController` contestaba **200 «En papelera»** al borrar una
escala que no existe y **200 «Guardado»** al editarla; con un cuerpo sin `id`
—que es fácil, porque en esa ruta el id va en el cuerpo y no en la URL—,
también «Guardado». Es la familia de `tools/respuestas-que-mienten.py` y de las
§37 y §45: una respuesta que dice que sí cuando fue que no es peor que un error,
porque quien la lee deja de mirar.

Pasan a **404**, que en esta API significa «esa fila no está» desde la serie
§44/§47/§49/§50/§53. Comprobado en el cliente antes de tocarlo: `ConfigEscalas.ts`
ya tiene rama de error en las dos llamadas —«Cambio no guardado» y «Escala no
eliminada»— y **no mira el código**, así que lo único que cambia es que ahora
enseña el error verdadero en vez de un éxito falso.

#### La trampa de escribir ese 404, que es la misma de las definitivas

Lo primero que uno hace es contar las filas afectadas por el `UPDATE`. **MySQL
devuelve 0 filas afectadas cuando el `UPDATE` no cambia ningún valor**, no sólo
cuando no encuentra la fila: guardar una escala sin tocarle nada daría 404. Por eso
la comprobación es un `SELECT` aparte. Es exactamente el tropiezo que se cazó
escribiendo el UPSERT de la fase 1 de las definitivas (10-definitivas.md).

Los seis casos de `CatalogosDelColegioTest` se comprobaron al revés, y **también
contra esa solución equivocada**: al escribir el 404 contando filas afectadas cae
`test_guardar_una_escala_sin_cambiar_nada_sigue_siendo_guardado`, que es justo el
caso que existe para eso.

### 70.5 Lo que queda anotado sin tocar

- **La escala de otro año se puede borrar y editar**: no hay filtro por año en
  ninguno de los dos métodos. Es coherente con la decisión de escribir en años
  pasados (§27.4) y por eso se deja, pero decide cómo se pinta el desempeño en los
  boletines de aquel año. Fijado por un test.
- **`GET api/nota_comportamiento/detailed/{grupo}` devuelve un array posicional**
  —`[frases, alumnos, grupo]`, montado con tres `array_push`—. Añadir un elemento
  en medio le cambia el sitio a todo lo de detrás, en un contrato que ningún
  cliente puede nombrar por clave.
- **`TipoDocumentoController::update` no devuelve nada**: 200 con cuerpo vacío
  aunque haya guardado. No miente —guardó—, pero no deja al cliente comprobar qué
  quedó.

---

## 71. El cálculo que nunca calculó y borraba lo escrito a mano (22 ago 2026)

`PUT api/definitivas_periodos/calcular-notas-finales-asignatura`, una de las cinco
rutas de ese controlador que nadie había mirado. Estaba **documentada como rota**
desde el primer recorrido y en [10-definitivas.md §7](10-definitivas.md), que
además dejó escrito el detalle importante: su DELETE usa el criterio invertido.
Lo que faltaba era la pregunta que convierte una anotación en un hallazgo:

> **¿Escribe antes de morir?**

Medido sobre una asignatura con 164 definitivas:

```
respuesta:    500  «Unknown column 'g.asignatura_id'»
definitivas:  164 -> 160
manuales:       4 ->   0
```

Sí escribe. Y lo que se lleva son **exactamente las manuales**.

### 71.1 El orden, que es lo que hace el daño

1. `Definitivas::calcular_notas_finales_asignatura` empieza por
   `DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or manual=1)`.
   Es un `DELETE` de verdad —esta tabla no tiene papelera— y **sin filtro de periodo
   ni de año**: se lleva todos los periodos de todos los años de esa asignatura.
2. El criterio está **invertido**. El resto del proyecto borra lo automático y
   respeta lo manual (`manual is null or manual=0`); éste borra `manual=1`. En la
   base de desarrollo eso son las cuatro que alguien escribió a mano, y las 160
   automáticas —que están en `manual=0`— ni se enteran.
3. Después consulta `g.asignatura_id`, que **`grupos` no tiene**, y revienta con
   500. No hay transacción, así que el 500 llega con el borrado hecho.

**Y la asimetría del daño es lo que lo pone por delante de otros rotos**: una
definitiva automática se recalcula desde las notas; una manual la escribió una
persona mirando un caso concreto, y no hay de dónde sacarla otra vez.

### 71.2 El id que recibe no es el que dice

`$asignatura_id = Request::input('profesor_id')`, con el comentario del propio
autor al lado: `// Aquí un error por arreglar`. O sea que quien lo llamara creyendo
que recalcula lo suyo estaría borrando las definitivas de **la asignatura cuyo id
coincida con su número de profesor** — dos numeraciones distintas que se solapan,
que es la misma forma de la §11.1 y de la §53.

### 71.3 Qué se hizo, y qué no

**Cortado antes de escribir**: el método contesta **410** y no ejecuta nada.

- **No se borra la ruta.** La regla de este repo es que un endpoint enrutado y roto
  se documenta; borrarlo convierte un 500 en un 404 sin decirle a nadie qué
  pretendía hacer esa pantalla.
- **No se arregla.** Recalcular una asignatura de verdad ya está escrito
  —`App\Services\DefinitivasDeAsignatura`, fase 1— y cablearlo aquí es la fase 3,
  que va detrás de la fase 2. Retirar el botón es la fase 5. Ninguna de las dos se
  decide desde aquí.
- **Ningún cliente lo llama.** `myvc_front` tiene el método en
  `DefinitivasPeriodosApi.ts:57` y ninguna pantalla lo usa; en Flutter y en el PIAR
  no aparece. Así que el 410 no se ve en ninguna parte — lo que cambia es que un
  token cualquiera del personal deja de poder vaciar las definitivas manuales de
  una asignatura por su número.

Lo fija `CalcularAsignaturaBorraYRevientaTest`, y el caso está escrito para medir
**el borrado y no el código**: comprobado al revés contra tres versiones —sin el
corte, con el corte después del cálculo, y con un 410 correcto que aun así
borra—. La última pasa el `assertStatus(410)` y cae por el recuento, que es justo
para lo que existe.

### 71.4 Lo que enseñó, y no es sobre definitivas

**Al quedarse el método sin cuerpo, saltó `phpstan.neon`.** Tres excepciones
—`Undefined variable: $asignaturas` con `count: 5` y una llamada con dos
parámetros de tres— dejaron de casar, y larastan las convirtió en error
(`ignore.unmatched`). Se borraron con el código que las producía.

Eso es exactamente lo que el propio `phpstan.neon` dice arriba —«nunca en un
baseline generado, que los escondería»— y **es la primera vez que se cobra**: un
baseline se habría callado, y las tres habrían seguido ahí tapando lo que ya no
existe. Una excepción con nombre y `count` es la única que se queja cuando deja de
hacer falta.

La que se queda es la de `Alumnos/Definitivas.php`: la clase sigue entera, con las
dos copias del método roto, y hoy es lo único que dice qué se pretendía.

---

## 72. `editnota` no borra notas: borra alumnos, y por la puerta sin llave (22 ago 2026)

Cuatro rutas sin comprobar en `EditnotaController` —la pantalla con la que se
corrige el histórico académico de un alumno ya promovido—. **Tres de las cuatro no
tocan ninguna nota**: mandan un alumno a la papelera, lo sacan y lo borran
definitivamente.

Es la forma de la [§65](#65): un controlador que opera sobre otra cosa de la que
dice su nombre. Con un agravante que la §65 no tenía — **las mismas tres
operaciones existen en `AlumnosController`, con criterio**:

| Operación | `AlumnosController` | `EditnotaController` |
|---|---|---|
| a la papelera | `puedeEditarAlumnos` | **nada** |
| restaurar | `puedeEditarAlumnos` | **nada** |
| borrado definitivo | `puedeBorrarAlumnos` | `puedeBorrarAlumnos` ✅ |

### 72.1 El hueco era real, no teórico

`puedeEditarAlumnos` es superusuario **o** profesor con
`profes_can_edit_alumnos`, y esa bandera está **apagada en los dieciséis colegios**
—por seguridad, no por olvido ([§29.1](#291))—. Así que hasta hoy:

> Un profesor **no** podía mandar un alumno a la papelera por `alumnos/destroy`, y
> **sí** por `editnota/destroy`.

Medido antes de tocar nada: con un token de profesor y la bandera apagada, las dos
rutas contestaban **200** y el alumno iba y volvía de la papelera.

### 72.2 Por qué se quedó abierta, que es la parte que se repite

El `forceDelete` de este mismo controlador **ya se había cerrado**, y lleva su
comentario contándolo: no tenía ninguna autenticación y era inerte por accidente
—faltaba el `use` de `Alumno` y reventaba antes de borrar—. Aquel arreglo miró
**ese método**, no la operación.

> **Cerrar una de tres es lo que pasa cuando se arregla el sitio que se está
> mirando y no la operación.** Es la hermana de la lección de la
> [§67.1](#671-lo-que-hay-que-llevarse-sobre-qué-población-se-cerró-la-serie):
> cuando se cierra algo, hay que anotar **sobre qué población** se cerró — y si la
> población es «un método», decirlo.

### 72.3 Cómo queda

Las dos rutas pasan a exigir `puedeEditarAlumnos`, que **no es un criterio nuevo**:
es el que ya decidió su hermana. Cerrarlas no apaga ninguna pantalla —`EditnotaApi.ts`
sólo declara `alum-asignatura`, con un comentario que dice «cubierto hasta donde hay
call site»— y ningún otro cliente las nombra.

Lo fija `EditnotaBorraAlumnosTest`, con el caso al revés incluido: **un superusuario
sigue mandando el alumno a la papelera y sacándolo**, ida y vuelta, para que se vea
que se cerró la puerta y no la casa.

### 72.4 Y la cuarta, que sí es de notas, fijada sin juzgar

`PUT api/editnota/alum-asignatura` es la única que llama un cliente.
`periodos_a_calcular` viaja en el cuerpo y `Periodo::hastaPeriodo` sólo conoce tres
valores —`de_usuario`, `de_colegio`, `todos`—. Con cualquier otro **no falla**:
devuelve un `stdClass` vacío, el `foreach` no da vueltas y la pantalla del
histórico sale **vacía en 200**.

Una errata en un cliente vacía la pantalla y nadie se entera. Se fija como está
—decidir si debe ser 422 o tratarse como `de_usuario` cambia lo que ve una pantalla
en dieciséis colegios, y hoy ningún cliente manda un valor malo— con el porqué al
lado, que es lo que pedía la §54.

### 72.5 El detector contó lo que se escribió *sobre* la bandera

Al cerrar los dos guards, `BanderaProfesEditaAlumnosTest` se puso rojo. Ese test
lee del código —no de una lista a mano— cada sitio que mira
`profes_can_edit_alumnos`, y avisó de **un sitio nuevo**:

```
+ 'app/Http/Controllers/EditnotaController.php::asignaturasPerdidasDeAlumnoPorPeriodo'
```

Que es falso dos veces: ese método no mira la bandera, y el guard nuevo tampoco
está ahí. **Lo que encontró fue el docblock que yo acababa de escribir**, donde se
nombra la bandera para explicar por qué el hueco era real. El detector buscaba la
cadena con `preg_match_all` sobre el fichero entero, y `metodoEn` se la atribuyó al
último `function` anterior — el de al lado.

> **Un detector que lee el fichero entero encuentra también lo que se escribió
> sobre él.** Y no tiene la cara de un fallo del detector: tiene la cara de un
> sitio nuevo, que es exactamente lo que ese test existe para avisar.

Es la cuarta de la familia de la §48 —el detector que no se encontraba a sí
mismo— y la novena «mentira de instrumento» de la lista del [09 §0.1](09-pendientes.md).

**Arreglado en el detector, no en el comentario**: se tokeniza y se descartan
`T_COMMENT` y `T_DOC_COMMENT`. Escribir la prosa esquivando al instrumento habría
dejado el instrumento roto para el siguiente.

#### Y al arreglarlo, la lista adelgazó — con una ruta que nunca leyó la bandera

| | antes | después |
|---|---|---|
| sitios | 24 | **21** |
| rutas | 20 | **19** |

Los tres que se van son prosa: un docblock de `ChangeAskedController`, otro de
`ExigirPersonal` y **el `@property` generado de `Year`** —o sea que el generador de
columnas alimentaba al detector—. Y el primero colgaba una ruta,
`PUT api/ChangesAsked/ver-detalles`, que **no mira la bandera**: la nombra para
explicar otra.

Eso estaba en la medición que se le pasó a Joseth para decidir sobre la bandera
([12 §20](12-larastan-nivel-7.md)). **No cambia la respuesta** —las catorce rutas
del módulo de matrículas, que son las que sostenían el argumento, siguen ahí— pero
la cuenta buena es **22 apariciones en código, 21 sitios y 19 rutas**, y queda
corregida donde vive el número.

Lo que sí se lleva de aquí es que **una lista que se lee del código no es
automáticamente cierta**: hereda lo que el lector no sabe distinguir. Ésta no
distinguía código de comentario.

---

## 73. «Quién cambió esta definitiva» contestaba 500 a todo el mundo (22 ago 2026)

Las dos últimas rutas sin comprobar de `Historiales`: los modales que contestan
**quién cambió una nota** y **quién cambió una definitiva**. Uno funciona y el
otro no ha abierto nunca.

```php
$consulta = 'SELECT n.*, u2.username as modificado_por
                FROM notas_finales n
                left join users u2 on u2.id=n.updated_by
                where n.id=?';

$nota = DB::select($consulta, [$nf_id, $nf_id] );   // <- UNA marca, DOS valores
```

Con `EMULATE_PREPARES` en false —que es como está este proyecto— MySQL prepara de
verdad y eso es `SQLSTATE[HY093]: Invalid parameter number`. Medido: **500**, para
cualquiera y con cualquier `nf_id`.

El dos está copiado de la consulta de arriba, en el mismo método, que sí lleva dos
porque es un `UNION` de dos ramas con el mismo `where`. **La asimetría entre
hermanas otra vez, y esta vez dentro del mismo método**: dos consultas seguidas,
una con dos marcas y otra con una, y la lista de valores copiada tal cual.

### 73.1 Se mide haciendo el cambio, porque `bitacoras` llega vacía

El seed no trae ninguna bitácora, así que un caso que buscara un cambio ya
registrado pasaría sin comprobar nada — la duodécima vez que ese agujero aparece.
Los dos casos **hacen el cambio por la API** y después le preguntan a la pantalla:

1. `PUT api/notas/update/{id}` con una nota nueva → `historiales/nota-detalle`
   cuenta un cambio, con valor viejo, valor nuevo y quién.
2. `PUT api/definitivas_periodos/update` con una definitiva → hoy
   `historiales/nota-final-detalle` lo cuenta igual; antes de esto, 500.

Y el primero comprueba de paso algo que no se ve: el `historial_id` de la bitácora
sale de la fila de `historiales` **que escribe el login**, y la primera consulta de
`NotasController::putUpdate` cruza `notas` con el último historial del usuario. Sin
esa fila no devuelve ninguna y **la nota no se puede guardar**. Nadie llega ahí sin
haber entrado, así que no es un fallo — pero es una dependencia que no está escrita
en ninguna parte y que ata guardar una nota a haber iniciado sesión por la vía
normal.

### 73.2 Lo que la pantalla sigue sin enseñar, y no se toca

`putNotaFinalDetalle` pregunta sólo por `affected_element_type="NF_UPDATE"`. La
recuperación se anota con **`"RF_UPDATE"`** —`DefinitivasPeriodosController:275`—,
así que **los cambios de recuperación no salen** en el historial de la definitiva
aunque la nota que se ve sí los refleje.

Se cita como lectura del código y **no como hecho medido**: para provocarlo hace
falta la ruta de recuperación, que exige los cuatro periodos abiertos, y eso es
montar un caso para una pregunta que no es ésta. Qué debe enseñar ese modal es del
colegio; lo que había que arreglar era que abriera.

---

## 74. Cuál de los cuatro interruptores de una actividad decide algo (22 ago 2026)

Las seis rutas sin comprobar de `ActividadesController` son la superficie con la
que un profesor dice **quién ve un examen**: crear, los tres *toggles*
—alumnos, profesores, acudientes—, `set-compartida` y `quitando-grupo-compartido`.
La pregunta no era si guardan —guardan— sino **qué cambian de lo que ve un
alumno**, y eso sólo se contesta abriendo el examen con un token de alumno.

Contesta también la anotación que llevaba abierta desde el 21 ago en la tabla del
[§5 de 09](09-pendientes.md): *«`para_alumnos` sigue con ella, sin un uso claro
separado de `compartida`»*.

| Interruptor | Ruta que lo mueve | Qué decide para un alumno |
|---|---|---|
| `in_action` | `actividades/guardar` | **cierra** — 403 «todavía no está abierta» (cerrado el 21 ago, §43.1) |
| fila en `ws_actividades_compartidas` | `insert-`/`quitando-grupo-compartido` | **decide** el acceso desde otro grupo |
| `compartida` | `actividades/set-compartida` | **nada** |
| `para_alumnos` | `actividades/para-alumnos-toggle` | **nada** |

Medido, no leído: con `para_alumnos = 0` y `compartida = 0`, el alumno **abre el
examen igual** —200 con el enunciado—. Y en el par de contraste, quitar la fila de
grupo compartido lo cierra de verdad: 403 antes de compartir, 200 compartido, 403
después de quitarlo.

### 74.1 Dónde sí se leen, que es lo que los hace engañosos

`compartida` y `para_alumnos` aparecen en siete `WHERE` — todos en listados **del
lado del profesor** (`actividades/compartidas`) y en la rama de la pantalla de
corregir. `exigirQueLaActividadLeCorresponda`, que es la comprobación que cerró el
lado del alumno, no los mira.

> **El interruptor esconde en una pantalla y no cierra en la otra.** Es la misma
> forma que `vt_votaciones.in_action`, que manda al usuario a otra pantalla y deja
> la urna abierta ([11-votaciones.md](11-votaciones.md)) — y van dos módulos donde
> el mismo nombre significa «lo escondo» en un sitio y «lo prohíbo» en otro.

Para el profesor que marca la casilla, las dos cosas se parecen mucho: la
actividad desaparece de su lista de compartidas. Lo que no puede saber es que el
alumno que ya tiene el enlace la sigue abriendo.

### 74.2 Por qué se fija y no se arregla

Hacer que `para_alumnos` cierre es una línea en `exigirQueLaActividadLeCorresponda`.
Y es justo lo que no se decide desde aquí: **hoy los alumnos abren exámenes que ese
interruptor dice que no son para ellos**, así que encenderlo **esconde de golpe
actividades que hoy se ven**, en los dieciséis colegios y a mitad de periodo. Es la
misma forma que `oportunidades` —la de los intentos ilimitados— y va en la misma
fila del §5.

Lo que cambia respecto a ayer es que ya no es una pregunta abierta con una
suposición dentro, sino **un hecho con su número**: no es que `para_alumnos` no
tenga un uso claro; es que para el alumno **no tiene ninguno**.

Lo fija `InterruptoresDeUnaActividadTest`, dos casos, y el segundo existe para que
el primero no se lea como «los interruptores no hacen nada»: uno de ellos sí.

---

## 75. El permiso que se calculaba y se tiraba, y las tres firmas de un borrado (22 ago 2026)

El hueco de cobertura llegó plano —105 rutas repartidas en 48 controladores,
mediana 2— así que se agrupó por la pregunta y no por la carpeta, como enseñó la
noche anterior: **quién pone y quita una falta, y a quién**. Son siete rutas en
cuatro controladores, y no es una familia cualquiera: es justo la que Joseth sacó
del candado del periodo el 21 ago ([§40](#40)), así que lo único que le queda
protegiéndola es la autorización.

### 75.1 El `if` con el cuerpo vacío

`AusenciasController` calculaba el permiso y lo tiraba a la basura, en dos
métodos:

```php
$isCoorDisciplinario = Role::isCoorDisciplinario($user->user_id);

if (!$isCoorDisciplinario) {
}
```

Corregir el día de una falta y borrarla. Un barrido del patrón en `app/` entero da
**tres** sitios y dos son éstos.

**No lo encontró un detector: lo encontró la asimetría entre hermanas.** Las tres
rutas que borran una ausencia se leyeron seguidas —la del lector, la de
`asistencias/*` y ésta— y ésta era la única que preguntaba algo antes de borrar. Y
lo llamativo no era que preguntara: era que **no hacía nada con la respuesta**.

`myvc_front` ya lo había visto en la fase 11 y lo dejó escrito en su MIGRATION.md,
en «lo que se vio y no se cambió»: *«el cuerpo del `if` está vacío, así que
cualquiera con `auth.personal` puede cambiar la fecha de cualquier inasistencia.
Es del backend… queda apuntado»*. Nadie volvió. **Un hallazgo apuntado en el
documento del cliente equivocado es un hallazgo perdido**, y van dos —el otro fue
el sexto `asked_id` de la §53—.

### 75.2 Por qué rellenar el `if` era el arreglo equivocado

Leído en frío tiene un arreglo obvio y de una línea. Es el que no se puede hacer, y
para saberlo hubo que ir a los cuatro clientes:

| Cliente | Qué exige para tocar una falta |
|---|---|
| `myvc_front` · menú | «Asistencias» se le enseña a `admin`, `profesor` y `Coord disciplinario` |
| `myvc_front` · `crearFaltaModal` | el mismo botón «Eliminar» **tres veces**, y **sólo uno** mira el rol |
| `myvc_flutter` | borra y corrige desde la pantalla de asistencia del profesor, **sin mirar ningún rol** |
| esta API | nada: el `if` estaba vacío |

O sea que el rol **no gobierna esto en ningún sitio**, y rellenar el `if` dejaba a
los 51 profesores sin poder corregir una falta mal puesta — de golpe, en dieciséis
colegios, y por una app que es **una sola para los dieciséis** y no se publica el
mismo día que el backend.

Joseth lo decidió con eso medido delante: **se queda abierto**, en la misma línea
que el interruptor del periodo. El cálculo muerto se retiró y en su sitio quedó el
porqué y la lista de arriba, que es lo que hay que volver a leer el día que
alguien quiera cerrarlo. Lo fija `AusenciasTest`, y el test corrige una falta **de
otro grupo** a propósito: lo que está abierto no es «cualquier profesor», es
«cualquier profesor sobre cualquier falta del colegio», y quien lo cierre tiene que
decidir las dos cosas.

`Role::isCoorDisciplinario()` se queda sin llamantes: es el **cuarto rol de la
familia que no gobierna nada**, tras Psicólogo y Enfermero ([§30.2](#302)). Falla
al revés que aquellos —que **cerraban de más** preguntando por un `users.tipo` que
no toma ese valor nunca—; éste no cerró nada.

### 75.3 Lo que sí se cerró: el rastro, que estaba en blanco

Si el permiso se queda abierto, lo único que queda es saber quién fue. De las tres
rutas que borran una ausencia, dos ponían `deleted_by` antes del `delete()` y la
tercera —la de las pantallas web y la mitad de Flutter— no ponía nada. Medido en la
copia de producción:

```
ausencias borradas: 5.689
  sin deleted_by:   5.684
  uploaded=deleted:     5
```

Las cinco firmadas son exactamente las que pasaron por el lector. **El 99,9% de los
borrados de faltas del colegio no tiene autor**, y la columna existe desde siempre.

**El `save()` antes del `delete()` no es cosmético, y está medido.** Se revirtió de
las dos maneras que manda el método: al código original —cae un test, el que
debía— y **al atajo que parece bueno**, poner `deleted_by` y llamar a `delete()`
sin guardar. También cae, porque el borrado suave de Eloquent escribe solo
`deleted_at` y se lleva por delante lo que esté sin guardar. Sin esa segunda
reversión el comentario del código sería una suposición.

### 75.4 La copia muerta estaba cubierta y la viva no

Hay dos ejemplares casi idénticos del controlador de asistencias.
`AsistenciasAppTest` cubría las cinco rutas de `AppMobile\AsistenciasAppController`
—que la [§57](#57) ya midió que **no llama ningún cliente**— y las de
`Tardanzas\AsistenciasController`, que es la que llama `myvc_flutter`, estaban a
dos de cinco.

Y **ya han divergido**: la viva selecciona `a.created_at` en sus cuatro consultas y
la muerta no. No es casual y se ve desde el cliente — `AsistenciaModel.fromJson`
de Flutter lee `created_at`. Alguien lo añadió donde hacía falta y la copia se
quedó atrás sin que nada lo dijera.

> **Un test que fija una copia deja que la otra se vaya sin que nadie lo note.**
> Por eso `AsistenciasTest` **compara las dos respuestas** en vez de fijar la suya:
> afirma que la única diferencia es `created_at`, con nombre. Comprobado al revés
> añadiendo la columna a la copia muerta — el test cae, o sea que la comparación
> mide y no adorna.

Es la tercera cara de la misma trampa de la semana: el 21 ago fue *medir una ruta
no es haberla juzgado* (§53), hoy es **cubrir un controlador no es haber cubierto
el que se ejecuta**.

### 75.5 El lector: un lote mixto, y por qué `find()` es lo correcto aquí

`tardanzas/subir` es la ruta de verdad del aparato de la puerta: acumula el día
entero sin red y lo sube completo. Altas y bajas van en el **mismo `foreach`**, y
no había ningún test que comprobara que hace las dos cosas en la misma petición.

Lo interesante es una asimetría que **no** es un fallo: `postIndex` borra con
`Ausencia::find()` y comprueba el resultado, mientras sus hermanas de
`eliminar-ausencia` usan `findOrFail()`. Aquí tragarse un id que no existe es lo
correcto — si alguien ya borró esa fila desde la web, un 404 tiraría el lote entero
y perdería lo que venía detrás. Se fija con su porqué, porque leído sin él parece
justo el descuido que hay que arreglar.

### 75.6 La planilla de la puerta: 392 consultas para una columna que nadie lee

`planillas-ausencias/tardanza-entrada` monta el año entero y llama a
`Alumno::userData()` **una vez por alumno**. Medido contra la copia de desarrollo,
con 13 grupos y 378 matriculados en el año actual:

```
1 consulta   los grupos del año
13 consultas los alumnos de cada grupo
378 consultas userData, una por alumno
─────────────
392 en una sola petición
```

`Grupo::alumnos()` ya devuelve del alumno el `user_id`, el `username`, el `sexo`,
la `fecha_nac`, la imagen y la foto. Las 378 consultas añaden sobre eso **una sola
columna: el correo**. Y las dos vistas que consumen la ruta —«Control entrada» y
«Control asistencia a clases», dos hojas para imprimir— leen del alumno `nombres`,
`apellidos` y `estado`, nada más. `userData` no lo mira ninguna vista del front.

O sea: **el correo de cada alumno del colegio viaja hasta una hoja de papel donde
no sale**, y cuesta una consulta por alumno.

Se fija y no se arregla, y no por prudencia genérica: encoger una respuesta es
contrato con **dieciséis copias del front** que no se pueden grepear desde aquí.
Lo que deja hecho `PlanillasAusenciasTest` es que el arreglo sea comprobable — el
día que se quite, el número de consultas por alumno tiene que bajar a cero y el
test lo dice. Va al [§5 de 09](09-pendientes.md).

### 75.7 Lo que se llevó la sesión

- 986 tests (eran 977), cobertura **441 de 539 (81%)**, las siete rutas dentro.
- Un arreglo desplegable: `deleted_by` en `ausencias/destroy`.
- Y la regla que resume las dos primeras: **una comprobación que existe pero no
  decide es peor que ninguna**, porque el que lee el código cuenta con ella. El
  `if` vacío llevaba escrito desde antes de la migración y sobrevivió al barrido de
  autorización, a la revisión IDOR y a la fase 11 del front — las tres lo vieron y
  ninguna lo tocó, porque las tres buscaban *comprobaciones que faltan*, no
  *comprobaciones que sobran*.

---

## 76. La otra mitad de la papelera: se cerró el borrar y no el devolver (22 ago 2026)

La cabecera de `App\Support\Autoriza`, escrita el 21 de agosto, dice de dónde
venía la clase:

> *«alumnos/forcedelete comprobaba, unidades/forcedelete comprobaba otra cosa, y
> **grupos, perfiles, profesores, years y editnota no comprobaban nada**»*

**Son exactamente los mismos cinco cuyo `restore` seguía sin comprobar nada.**

Cada operación de la papelera es una **pareja** —`forcedelete` y `restore`, uno al
lado del otro en el mismo controlador— y aquella revisión cerró **una mitad de
cada una**. A la que devuelve no se le preguntó, y por eso nadie volvió en un mes:
la serie constaba cerrada. Es la lección de la [§54](#54) otra vez —*cuando una
serie se cierra, anotar sobre qué población se cerró*— y es también, aplicada a
nuestro propio arreglo, la de la [§47.2](#472): **al tapar un camino, la pregunta
siguiente es cuál es el otro.**

### 76.1 La tabla, que es todo el hallazgo

| Pareja | Borrar definitivamente exigía | Restaurar exigía |
|---|---|---|
| `alumnos` | `Autoriza::puedeBorrarAlumnos` | `Autoriza::puedeEditarAlumnos` |
| `editnota` (alumnos) | `Autoriza::puedeBorrarAlumnos` | `Autoriza::puedeEditarAlumnos` |
| `unidades` | `pueden_editar_notas` del periodo | `pueden_editar_notas` del periodo |
| `subunidades` | `pueden_editar_notas` del periodo | `pueden_editar_notas` del periodo |
| `grupos` (27 tablas en cascada) | `Autoriza::esSuperusuario` | **nada** |
| `perfiles` — el mismo grupo, otra URL | `Autoriza::esSuperusuario` | **nada** |
| `profesores` (31 tablas) | `Autoriza::esSuperusuario` | **nada** |
| `years` (59 tablas) | `Autoriza::esSuperusuario` | **nada** |
| `asignaturas` | *(borrado blando, sin criterio)* | **nada, y sin filtrar por año** |

Las cuatro primeras son simétricas porque las escribió la misma revisión. Las
cinco de abajo no, porque aquella revisión sólo miraba lo que borra.

Medido, no leído: con un token de profesor cualquiera, `grupos/restore`,
`perfiles/restore` y `profesores/restore` contestaban **200**. Con un token de
alumno, **403** — `auth.personal` sí estaba, así que el alcance del agujero es el
personal del colegio y no cualquiera con cuenta. Eso es lo que lo separa de la
[§28.4](#284), donde el `forcedelete` de grupos lo alcanzaba **el token de
cualquier alumno**.

### 76.2 Por qué `esSuperusuario` y no `esAdministrativo`

Las dos son hoy las **mismas diez personas** —`is_superuser` y el rol `Admin`
coinciden fila por fila, medido en la §28.4— así que hoy da igual. La diferencia
es mañana: `esAdministrativo` incluye al `Secretario` del día que ese rol exista.

Se eligió el criterio **del gemelo destructivo de cada pareja**, que es lo que
hace la regla escrita en la propia clase: *crear el rol no puede dar permisos que
nadie pidió*. El alcance del `Secretario` que se repartió el 21 ago no nombra la
papelera, y colar restaurar ahí dentro sería concederle algo por la puerta de
atrás de un arreglo. Subirlo es una palabra el día que se decida, y está anotado
en el [§5 de 09](09-pendientes.md).

**Y el riesgo de cerrar de más se comprobó antes, no después**: la pantalla de
papelera de `myvc_front` está en el menú «Colegio» con
`ng-show="hasRoleOrPerm('admin')"`, o sea que **ya se enseñaba sólo a los mismos
diez**. Nadie pierde un botón que hoy vea. La papelera del front tiene tres
rejillas —alumnos, grupos y unidades— y de las cinco rutas cerradas **sólo una,
`grupos/restore`, la llama algún cliente**: las otras cuatro eran minas, no
fallos vivos.

### 76.3 La pareja de profesores, donde sólo era alcanzable la mitad peligrosa

`profesores/trashed` contesta **500 desde siempre** —`order by p.nombres` y en el
`FROM` no hay ninguna `p`, porque la consulta es la papelera de alumnos copiada
entera— y ya estaba fijado por `MuestreoDeLecturasTest` desde la primera pasada
(tabla del [§6](#6)). O sea que **la papelera de profesores nunca ha devuelto un
profesor**.

Puesto junto a lo de arriba, la pareja entera queda así: mandar un profesor a la
papelera funciona, **listarla no funciona para nadie**, y sacarlo de ella lo podía
hacer cualquiera de los 51 sabiendo el id. De las dos mitades, la única alcanzable
era la que no debía serlo.

### 76.4 `asignaturas/restaurar`: el listado filtraba y el botón no

Ésta no es de permisos. `getPapelera()` filtra `g.year_id = ?` con el año del
token y `putRestaurar()` hacía `UPDATE asignaturas SET deleted_at=NULL WHERE id=?`
con el id que llegara en el cuerpo. **La misma pantalla, dos alcances**: el
listado enseñaba un año y el botón los alcanzaba todos.

Ahora restaura con la ligadura del listado, y contesta **404** y no 403: para
quien pide, una asignatura de otro año no es que esté prohibida — es que no está
en su papelera.

Es la misma forma que ya salió en la §69 (`postStore` leía tres campos bien y
`putUpdate` los leía mal) y en la §75 (`getPapelera` contra su restore): **la
asimetría que más ha dado es la que hay entre las dos mitades de una misma
pantalla**, no la que hay entre dos controladores.

### 76.5 Lo que dice que ningún test lo cubría, y cómo se supo

Al aplicar el arreglo **no se rompió ni un test de los 986**. Eso no es que el
arreglo sea inocuo: es que **ninguno fijaba quién podía restaurar**.

El de años lo parecía. `YearsTest::test_el_ano_va_a_la_papelera_y_vuelve` llama a
`years/restore` y sigue verde — y sigue verde porque su token sale de
`usuarioDeTipo('Usuario')`, y **el `Usuario` del seed resulta ser
`is_superuser = 1`**. Pasaba por el token que eligió, no por haber juzgado el
permiso.

> **Un test verde sobre una ruta abierta no dice que la ruta esté bien: dice que
> quien la llamó tenía permiso.** Es la tercera cara de la misma trampa de la
> semana —«medir una ruta no es haberla juzgado» (§53), «cubrir un controlador no
> es haber cubierto el que se ejecuta» (§75.4)— y la más difícil de ver, porque
> aquí el test **sí** llama a la ruta y **sí** mira lo que devuelve.

Por eso `PapeleraRestaurarTest` recorre **la pareja entera en un solo bucle** y no
cada ruta por su lado: el fallo no era que una ruta no comprobara, era que **la de
al lado sí**, y eso sólo se ve poniéndolas en la misma tabla. Comprobado al revés
quitando el guard de la **última** pareja del bucle: cae, o sea que el recorrido
llega al final y no se queda en la primera.

Y un tercero, que lo cazó larastan y no el test: `assertStatus()` acepta **un**
parámetro. El mensaje que le pasé de segundo se lo tragaba sin decir nada, así que
un rojo dentro de un bucle de cuatro parejas no habría dicho cuál falló. **Una
comprobación que no puede explicarse cuando falla es media comprobación**, y el
nivel 7 la encontró por la aridad.

---

## 77. El botón peligroso que la §27 no podía ver (22 ago 2026)

`PUT detalles/eliminar-notas-periodo` hace un `DELETE FROM notas` **físico** —no
marca `deleted_at`, no hay papelera, no hay de dónde restaurar— de **todas las
notas de un alumno en un grupo y un periodo**. Toma los tres ids del cuerpo y no
comprobaba nada: ni el interruptor del periodo, ni de quién es el grupo.

El botón que la llama se llama, en el propio `myvc_front`, *«Eliminar todas las
notas de este periodo (¡peligroso!)»*.

### 77.1 Por qué no la vio la §27, que es el hallazgo de verdad

La §27 dejó **25 de 26 rutas** pidiendo el permiso del sitio al que escriben. Ésta
no estaba entre las 26, y no por descuido: aquel inventario se hizo de **los sitios
que ya llamaban a `User::pueden_editar_notas()`** —26 llamadas en siete
controladores— y `DetallesController` no llamaba a ninguno.

> **Una lista construida desde la comprobación no puede contener al que nunca
> comprobó.**

Es una forma distinta de la trampa habitual del detector. No es un detector con
falsos positivos ni uno ciego a un nombre nuevo (§53): **el detector era exacto y
el conjunto estaba mal elegido**. Y el resultado tiene la peor cara posible — «25
de 26» suena a serie cerrada.

La §08 sí la tenía apuntada desde la revisión IDOR, en la tabla de «escrituras
sobre otro alumno», y nunca se cerró. Van **tres** hallazgos de esta semana que ya
estaban escritos en algún documento y nadie recogió: el sexto `asked_id` (§53), el
`if` vacío de ausencias (§75.1) y éste.

### 77.2 Rehecha por la operación

`tools/escrituras-en-las-notas.py`, que parte de **cualquier INSERT/UPDATE/DELETE
cuyo SQL nombre `notas`, `notas_finales` o `recuperacion_final`**:

```
13 métodos escriben en las notas
sin preguntar: 4 de 13
```

Y los cuatro, leídos uno a uno —que es lo único que convierte una lista en un
veredicto—:

| Método | Ya estaba |
|---|---|
| `DefinitivasPeriodosController::putCalcularGrupoPeriodo` | §71, cortada con 410 |
| `NotasController::putDetailed` | 10 §3, 05 y 09 |
| `NotasController::putSubunidad` | 10 §3.1 — no guarda nada, con su test |
| **`DetallesController::putEliminarNotasPeriodo`** | **nada** |

### 77.3 Las dos veces que el detector mintió, antes de dar el número bueno

Las dos están escritas en la cabecera del script, porque el script se va a volver
a usar:

- **Sin quitar los comentarios contó los docblocks.** La primera pasada dio 17
  métodos y 7 sin preguntar; cuatro eran **texto**, no código. Y no repartidos al
  azar: **tres de los cuatro cayeron en la columna «NO pregunta»**, que es la única
  que se lee. La razón es estructural y merece quedarse — el docblock que explica
  un arreglo se escribe **encima** del método que sí escribe, y un recorte por
  `function` se lo cuelga al método **anterior**, que por construcción es otro. Es
  la §72.5 con una vuelta más: no sólo cuenta lo que se escribió sobre el código,
  es que lo cuenta **en el sitio equivocado**.
- **Corrido desde otro directorio contestó «0 métodos escriben en las notas»** en
  vez de «no existe la carpeta». Un cero tiene exactamente la misma cara que un
  arreglo terminado. Va con freno: ahora aborta.

Y una tercera, dentro del test y no del detector: el contador de consultas por
grupo buscaba `from notas n` en minúsculas contra un SQL que dice `FROM notas n`,
contó cero y **falló diciendo «el listado ha dejado de preguntar grupo a grupo»** —
o sea, anunciando una optimización que nadie había hecho. **Un contador que no
encuentra nada tiene la misma cara que un arreglo.** Tres instrumentos, tres veces,
en un solo hallazgo.

### 77.4 Qué se hizo, y por qué el candado y no otra cosa

Joseth eligió el candado del periodo con las cuatro opciones y sus costes medidos
delante. Es la regla ya decidida —**el interruptor cierra las notas**, §40— aplicada
a una ruta que el inventario se saltó: no es una decisión nueva, es terminar una.

El periodo que se le pasa es el **del cuerpo**, y eso es correcto aquí aunque sea
literalmente lo que la §27 avisa que no se haga. Allí el problema era que el
cliente elegía con `num_periodo` **el permiso que se le comprobaba mientras escribía
en otro sitio**. Aquí `periodo_id` es la misma ligadura que acota el `DELETE`
—`u.periodo_id=:periodo_id`—, así que pedir permiso para él es pedirlo para
exactamente lo que se va a borrar. **La regla no es «no leer el periodo del
cuerpo»: es «pedir permiso para el sitio al que se escribe»**, y cuando el cuerpo
elige las dos cosas a la vez, leerlo es lo correcto.

Lo que **no** se hizo: cerrarla a superusuario. Habría pasado el test del periodo
cerrado y apagado la pantalla en dieciséis colegios — lo dice el segundo test, que
existe para eso. Comprobado: con ese atajo caen tres de los cuatro.

### 77.5 Lo que queda medido de la pantalla, sin tocar

`putGruposPeriodos`, la otra ruta sin comprobar del mismo controlador, alimenta esa
misma pantalla y **recorre todos los grupos del año preguntando uno a uno si el
alumno tiene notas**, y por cada grupo con notas los periodos, y por cada periodo
las asignaturas. Contesta lo que le piden; el coste no estaba medido y ahora lo
fija el test.

Y no filtra la papelera: `SELECT * FROM grupos g WHERE g.year_id=:year_id` a secas,
así que un grupo borrado con notas dentro sale igual. Es lo contrario de lo que
hace la rejilla de grupos del mismo módulo — **la §70.2 otra vez**: catorce
consultas decidiendo por su cuenta qué hace la papelera.

---

## 78. Crear un catálogo: nueve rutas, el mismo gesto, cuatro respuestas (22 ago 2026)

La [§70](#70) midió **borrar** un catálogo del colegio —qué se lleva por delante y
quién puede llamarlo— y ahí se quedó. La otra mitad de cada pareja, **crear y
editar**, no la había mirado nadie: veinte rutas en trece controladores. Es el
mismo reparto que dejó abierta la papelera (§76) y por la misma razón: una
revisión mira lo que destruye.

La pregunta que las junta es la más simple que hay: **¿qué contesta un catálogo
cuando le mandas el cuerpo vacío?** Medido, no leído:

| Catálogo | Respuesta | ¿Escribe? |
|---|---|---|
| áreas, grados, niveles educativos, tipos de documento, ciudades | **422** «Datos incorrectos» | no |
| países, frases, definiciones de comportamiento | **500** con el `SQLSTATE` de MySQL | no |
| materias | **500** «Trying to access array offset on null» | no |
| **contratos** | **200 con `[]`** | **sí** |

### 78.1 Lo que separa las cuatro columnas no es el código

**Los nueve controladores son igual de crédulos**: leen `Request::input(...)`,
llaman a `save()` y no validan nada — que es lo esperable en un proyecto con dos
validaciones en total (CLAUDE.md). No hay ninguno mejor escrito que otro.

Lo que los separa es **el esquema**:

- las ocho tablas que no escriben tienen una columna `NOT NULL` —`materias.materia`,
  `areas.nombre`, `paises.pais`, `tipos_documentos.tipo`…— y es MySQL quien rechaza
  el `INSERT`;
- las cinco que contestan 422 y las tres que contestan 500 se distinguen sólo por
  llevar o no un `try/catch` alrededor del `save()`;
- y **`contratos` es la única de las nueve cuya tabla no tiene ninguna columna
  `NOT NULL`** —`profesor_id` y `year_id` son las dos nulables—, así que la fila
  entra.

> **Lo que impide que ocho de los nueve escriban basura no es el código: es el
> esquema.** Es la misma forma que `putSubunidad` (10 §3.1), donde lo que salvaba
> las notas era una columna, y es la razón de fondo por la que la ausencia de
> validaciones en este proyecto casi nunca se nota: las tablas viejas están llenas
> de `NOT NULL` y hacen de validador. La que no lo tenga, no lo tiene.

### 78.2 El contrato huérfano, y la pantalla que decía que sí

`POST api/contratos` con un `profesor_id` que no existe **escribía la fila igual**.
El `SELECT` de después une por `profesores`, así que no encontraba nada y devolvía
**200 con `[]`**.

Y lo que hace el cliente con eso ya estaba escrito, en `ProfesoresCtrl`:

> *«crear devuelve un array de un elemento. Si viniera vacío —que sería un backend
> distinto del documentado— lo único honrado es no tocar las rejillas: el aviso ya
> ha salido y el contrato existe.»*

O sea que la pantalla enseñaba **«contratado para este año»** mientras aquí quedaba
una fila sin profesor: invisible desde cualquier pantalla y por tanto imposible de
quitar. El front había razonado bien sobre una respuesta que no debería existir, y
al hacerlo la volvió silenciosa.

**Es una mina, no un fallo vivo, y está medido**: en la copia de producción hay
**cero contratos huérfanos de 164**. El front siempre manda un id bueno. Se cierra
porque el día que mande uno malo —una rejilla desactualizada apuntando a un
profesor ya borrado— la fila que queda no se puede ni ver ni deshacer.

Comprobado al revés de las dos maneras que manda el método. Sin el arreglo caen dos
tests; **con el atajo** —comprobar que el campo llega, en vez de que el profesor
exista— pasa el del cuerpo vacío y cae el del id inexistente, que es exactamente
para lo que ese segundo test está escrito.

### 78.3 Lo que se fija y no se toca

`CrearUnCatalogoTest` deja la tabla de arriba fijada **tal como está**, con los
cuatro comportamientos. No se unifican los 500 a 422, y no por pereza: son cuatro
controladores y el front pinta **el mensaje del cuerpo**, así que hoy a un
administrador se le enseña el `SQLSTATE` entero de MySQL y mañana se le enseñaría
«Datos incorrectos». Eso se decide, no se arregla de paso. Va al [§5 de 09](09-pendientes.md).

Lo que sí deja el test es que cualquiera de los cuatro que cambie lo diga con
nombre, y sobre todo que **la última columna no vuelva a moverse**: lo que se
afirma de verdad no es el código de estado, es que ninguna de las nueve deje una
fila detrás.

### 78.4 Y una asimetría que se miró y se dejó

`AreasController::putUpdate` **no devuelve nada** —ni siquiera la fila que acaba de
guardar— mientras `postIndex`, aquí al lado, devuelve el área, y los `putUpdate` de
grados y niveles educativos devuelven la suya. Parece el mismo descuido de siempre.

No se toca, y la razón es el llamante: `AreasCtrl` hace
`AreasApi.actualizar(...).then(function(){ ... })` **con la función sin
argumentos**. No lee la respuesta. Devolver el área sería un cambio de contrato
para no arreglar nada.

> Van tres esta semana en las que leer el cliente **desactivó** el arreglo obvio: el
> `if` vacío de ausencias (§75.2), el criterio del `restore` (§76.2) y ésta. **La
> pregunta «¿quién lo llama?» no es el último paso de la revisión, es el segundo.**

---

## 79. Lo que alcanza un token cualquiera: las escrituras sin `auth.personal` (22 ago 2026)

El guard va por defecto a toda la API y `auth.personal` se pone **ruta a ruta**, así
que las que llevan sólo `auth.token` las alcanzan las **2.321 cuentas**, alumnos y
acudientes incluidos. El barrido de agosto midió qué **devuelven** esas rutas. Lo
que faltaba era la otra mitad: qué **escriben**.

Salen cuatro comportamientos y **sólo uno es un problema**, lo cual es en sí un
resultado: dos rechazan bien con `Autoriza` dentro del método —por eso su ruta no
lleva `auth.personal` y aun así están cerradas—, una obedece al token y no al
cuerpo, y una hace exactamente lo que le pidan.

### 79.1 Un alumno se queda con el correo de otra cuenta, y con su enlace

`PUT perfiles/guardar-mi-email-restore` escribe `users.email` y **no valida nada**.
Medido con un token de alumno:

```
email_restore='no-es-un-correo'      -> 200  guardado='no-es-un-correo'
email_restore=''                     -> 200  guardado=NULL
email_restore='victima@ejemplo.test' -> 200  guardado='victima@ejemplo.test'
```

Y `users.email` **no es un dato de perfil: es la llave de la cuenta** (§36.1). Se
comprobó de un lado: es la única columna del sistema que gobierna algo —
`postRecuperarClave` manda ahí el enlace— y en todos los demás sitios sólo se
muestra.

**El viaje entero, que es lo único que lo hace visible:**

1. el alumno (id 1223) se pone el correo de otra cuenta (id 1224);
2. la dueña de ese correo pide **su** reseteo;
3. `password_reminders` guarda `username = users_1223`.

O sea: **a la víctima le llega a su buzón un enlace que cambia la contraseña de un
desconocido**, y ella no puede recuperar la suya nunca. `postRecuperarClave` se
queda con `$persona[0]`, la cuenta de id más bajo del grupo, y eso ya estaba
documentado — lo que no estaba es que **cualquiera puede meterse en ese grupo**.

**No es robo de cuenta**, y la distinción importa: el correo llega al buzón de la
víctima, no al del alumno. Es **quitarle la recuperación**, a cualquiera con un id
más alto que el tuyo. Y es el mismo mecanismo que la [§13 del 12](12-larastan-nivel-7.md)
midió como accidente —ocho hermanos con el correo de un padre, y las ocho segundas
sin recuperación— con la diferencia de que aquí se hace a propósito y con un solo
PUT.

El formato libre es además una de las vías por las que se llenan las **677 cuentas
cuyo «correo no es una dirección»** del §9 del 12, que están dentro del 91% que no
puede recuperar la contraseña.

**Joseth decidió no tocarlo y medirlo** (22 ago 2026). El coste de cerrarlo estaba
en la otra columna: rechazar un correo repetido dejaría a una familia sin poder
poner la dirección del padre en dos cuentas desde esa pantalla. Va al
[§5 de 09](09-pendientes.md), y el test fija **el agujero, no el arreglo** — el día
que alguien ponga la validación, falla y le cuenta qué pasaba antes.

### 79.2 «Guardar valor varios» guarda uno, y es una mina con fecha

`AlumnosController::putGuardarValorVarios` tiene dos ramas que hacen lo mismo con
una diferencia de una línea:

| Rama | El `return` | Qué guarda de N alumnos |
|---|---|---|
| administrativo (`Autoriza::esAdministrativo`) | **fuera** del bucle | los N |
| profesor (`years.profes_can_edit_alumnos`) | **dentro** del bucle | **el primero** |

De N alumnos guarda el primero, contesta 200 y **tira los demás sin decir nada**. Y
si el profesor no es titular del grupo del primero, contesta 400 y no guarda
ninguno — aunque fuera titular de los otros.

**Es una mina y tiene fecha**: la rama del profesor cuelga de
`years.profes_can_edit_alumnos`, apagada en los dieciséis colegios y cuya decisión
está aplazada a después de la migración ([§29.1](#291)). El día que se encienda,
esa pantalla empieza a guardar uno de cada N.

Se fija con el test **encendiendo la bandera dentro de la transacción**, que es lo
único que hace medible una mina antes de que estalle, y con **dos alumnos mirando
las dos filas**: con uno solo el fallo no existe, y mirando el 200 no se ve nunca.

> **La asimetría entre las dos ramas de un mismo método** va por la tercera esta
> semana —§69 (`postStore` contra `putUpdate`), §75 (`getPapelera` contra su
> restore) y ésta—. Y las tres tienen la misma forma: **una de las dos ramas se
> escribió después, copiando la otra, y la copia se desvió en una línea.**

### 79.3 Los dos autocompletados, que se fijan y no se cierran

`alumnos/eps-check` y `acudientes/ocupaciones-check` son `LIKE '%texto%'` sobre una
columna de **todos** los alumnos y de **todos** los acudientes, con sólo
`auth.token`. Con el texto vacío el patrón es `%%` y devuelven la lista entera del
colegio a un alumno.

Lo que los deja fuera de la §34 es que devuelven **`distinct`**: sale el conjunto de
EPS que usa el colegio, no la EPS de nadie. El test fija justo eso —**una sola
columna por fila**— para que el día que alguien añada `a.nombres` a ese `SELECT`,
que es el cambio de una palabra, deje de ser un conjunto y falle.

### 79.4 Y las tres que no tenían nada, que también es un resultado

`alumnos/guardar-valor-varios` y `alumnos/forcedelete/{id}` comprueban con
`Autoriza` **dentro del método**, así que su ruta no lleva `auth.personal` y aun así
un alumno se queda en 400 — y la fila sigue ahí después del rechazo, comprobado.
`acudientes/mis-acudidos` liga por `$user->persona_id` del token y **no mira el id
del cuerpo**, comprobado mandándole el de otro acudiente.

> Un barrido que sale limpio no es una tarde perdida: dice que ese trozo está
> cubierto y que no hay que volver. Es lo mismo que dejó escrito la §54 con las
> veintidós rutas de `auth.token`.

### 79.5 Las dos veces que el instrumento volvió a mentir

Van con el hallazgo porque son la misma familia de siempre y las dos costaron un
rato:

- **La sonda mandó `ruta: 'x'` a `recuperar-clave` y recibió 422**, que leído en
  frío parecía «el sistema rechaza el correo compartido» — o sea, parecía que el
  agujero no existía. No era del correo: `ruta_frontend_segura()` rechaza una ruta
  de retorno que no sea del propio host. **Un rechazo correcto por otro motivo es
  la forma más convincente de que un fallo parezca cerrado.**
- **`users.persona_id` no existe como columna.** Es del `stdClass` que monta
  `ContextoDeUsuario`, no de la tabla, y CLAUDE.md lo dice. El test lo dio por hecho
  y el SQL reventó — que aquí fue barato porque rompe ruidosamente, pero es la misma
  confusión que en un `UPDATE` escribiría en la fila de otro.

---

## 80. Las dos que faltaban del candado del periodo, y cómo se escondieron (22 ago 2026)

La §77 cerró `detalles/eliminar-notas-periodo` y dejó escrita la lección: la lista
de la §27 se construyó desde **la comprobación** y por eso no podía contener al que
nunca comprobó. Para arreglarlo se escribió `tools/escrituras-en-las-notas.py`, que
parte de la operación.

**Y la herramienta también estaba mal.** Salieron dos más, cada una escondida de
una forma distinta, y las dos formas valen más que las dos rutas.

### 80.1 La herramienta sólo veía SQL crudo

`PeriodosController::putCopiar` —la pantalla con la que un profesor se trae la
estructura de otro periodo— crea unidades, subunidades y, si se lo piden, **notas**
en `periodo_to_id`, que llega en el cuerpo. Y no pedía permiso para nada.

No salió en la lista de la §77 porque **escribe con Eloquent**:

```php
$nota_new = new Nota;
$nota_new->nota = $nota->nota;
$nota_new->save();
```

y el detector buscaba `INSERT INTO … notas`. CLAUDE.md dice que el repo tiene 990
consultas crudas y usa los modelos «marginalmente», y **esa frase es exactamente la
que hace que se te olvide el margen**. Se encontró **una hora después de escribir la
herramienta**, leyendo otra cosa.

> Van tres formas distintas de ceguera del mismo detector en un día: ciego a los
> comentarios (contaba docblocks), ciego fuera de su carpeta (contestaba «0») y
> ciego a Eloquent. Las tres están ahora en su cabecera, que es donde sirven.

**Lo que lo convierte en incoherencia y no en decisión** es el vecindario: las
cuatro rutas que hacen eso mismo de una en una —`unidades/store`,
`unidades/update`, `subunidades/store`, `subunidades/update`— piden permiso desde la
§27. Un profesor no podía crear una unidad en un periodo cerrado a mano, **y sí
copiar treinta de golpe**.

El permiso se pide para el **destino** y no para el origen, porque del origen sólo
se lee. Comprobado al revés de las dos maneras: sin el candado cae un test; **con el
candado puesto sobre el origen caen dos**, y el segundo existe justo para eso —
copiar *desde* un periodo cerrado tiene que seguir funcionando, que es lo que hace
un profesor en enero.

### 80.2 La otra estaba tapada por una frase escrita en un test

`SubunidadesController::deleteDestroy` borra un componente calificable **y recalcula
las definitivas de la asignatura** en la línea siguiente. De los siete métodos que
escriben en ese controlador, **seis piden el permiso y éste no** — mientras su
gemelo exacto, `UnidadesController::deleteDestroy`, sí lo pide. En el gemelo lo
piden los siete de siete.

Es la asimetría más limpia que ha dado la serie: **el mismo nombre de método, en el
controlador de al lado, uno pregunta y el otro no.** Y aun así llevaba un mes ahí,
por esto — el docblock de un test verde de `UnidadesTest`:

> *«`subunidades/restore` tenía la misma forma mientras `subunidades/update` y
> **`subunidades/destroy`**, en el mismo fichero, **sí piden el periodo**.»*

Uno de los dos sí. El otro no. La frase se escribió para justificar por qué había
que cerrar `restore`, nadie la comprobó, y a partir de ahí `subunidades/destroy`
constaba cerrado para cualquiera que leyera ese test.

> **Una afirmación sobre el código de al lado envejece igual que el código, y ésta
> nació ya vieja.** Escrita dentro de un test verde se lee como una medición.

Es la tercera vez esta semana que un verde tapa algo, y las tres son distintas:
- **§75** — un test fijaba el comportamiento permisivo sin juzgarlo.
- **§76.5** — un test pasaba porque el token que eligió resultó tener permiso.
- **§80.2** — el test no tocaba la ruta; **lo que la tapaba era su prosa**.

La frase **no se corrige en silencio**: se deja escrita en el propio docblock con lo
que era falso, porque la lección es la frase y no la ruta.

### 80.3 Y el candado se pide por la fila, no por el cuerpo

`subunidades/destroy` recibe `periodo_id` en el cuerpo —lo necesita para el
recálculo de definitivas que hace después—, así que comprobar el permiso con **ese**
valor es la tentación evidente y es exactamente lo que la §27 existe para no repetir.
Se deriva con `PeriodoDeLaFila::deSubunidad($id)`, y hay un test que manda en el
cuerpo un periodo abierto mientras el de la subunidad está cerrado: sigue diciendo
que no.

La diferencia con la §77 conviene tenerla junta, porque las dos leen del cuerpo y
sólo una está bien:

| | De dónde sale el periodo | ¿Correcto? |
|---|---|---|
| `detalles/eliminar-notas-periodo` (§77) | del cuerpo | **sí** — es la misma ligadura que acota el `DELETE` |
| `subunidades/destroy` (§80) | de la fila | **sí** — el del cuerpo es de otra cosa, del recálculo |

**La regla no es «no leer el periodo del cuerpo»: es pedir permiso para el sitio al
que se escribe.** Cuando el cuerpo elige las dos cosas a la vez, leerlo es lo
correcto; cuando elige una y se escribe en otra, es el agujero.


---

# La noche del 22 al 23 de agosto de 2026 — §81 a §166

Seis sesiones en paralelo, **un árbol y una base por cada una**, veinte lotes.
Cada lote dejó su documento en [`noche-2026-08-23/`](noche-2026-08-23/), y **ahí
viven las secciones con su medición entera**: este apartado no las repite, las
indexa y recoge lo que ninguna de ellas podía decir sola.

**Por dónde empezar**: [`noche-2026-08-23/README.md`](noche-2026-08-23/README.md)
dice cuáles son los dos que hay que leer si solo se leen dos —la tabla de
despliegue y el barrido de cegueras— con la distinción que los ordena: **el
primero caduca con la tanda; el segundo no.**

## Qué se midió, entre dos medidas y no entre una medida y una cita

Los dos extremos corridos **el mismo día, en la misma máquina, con el mismo
`vendor`**, bases separadas y el aislamiento comprobado en los dos árboles. La
suite **entera**, no `--testsuite=Contrato`.

| | `c2c2a04` (partida) | `9492a2b` (cierre) | Δ |
|---|---|---|---|
| Tests | **1.006** | **1.276** | **+270** |
| Aserciones | **6.546** | **8.594** | **+2.048** |
| Rutas comprobadas | **462/539 (85%)** | **535/539 (99%)** | **+73** |
| Controladores con alguna | **97/97** | **97/97** | — |
| Controladores **a medias** | **41** | **4** | **−37** |
| larastan | nivel 7 `[OK]` | nivel 7 `[OK]` | — |

**Los 97/97 no se mueven: ya estaban al empezar.** Decir «de 96/97 a 97/97» —el
96 sale de medir solo con Contrato— afirmaría que la noche cerró el último
controlador sin cubrir, y **no ocurrió**. Lo que la noche movió son los **41
controladores a medias → 4**, y las cuatro rutas que quedan son de catálogo,
repartidas en cuatro controladores distintos.

Las dos advertencias que van pegadas a esas cifras y no debajo:

- **`COBERTURA_RUTAS` con fichero propio y sin borrar antes.** Compartirlo dio una
  vez *86 de 539 cuando eran 346*.
- **Los dos barridos que la herramienta descuenta** —`AutenticacionTest` (520
  rutas) y `RutasPreLoginTest` (527)— **no cuentan como comprobar, y hacen bien**:
  si contaran, el 99% sería 100% y no significaría nada.

## Las secciones, por lote

| Lote | La pregunta | §§ |
|---|---|---|
| [A](noche-2026-08-23/a.md) | Los catálogos: editar y borrar | §81–84, §122 |
| [B](noche-2026-08-23/b.md) | Ordinales de disciplina y ciudades | §85–88 |
| [C](noche-2026-08-23/c.md) | La rejilla: quién escribe una definitiva y con qué candado | §89–92 |
| [D](noche-2026-08-23/d.md) | La configuración del año | §93–96 |
| [E](noche-2026-08-23/e.md) | Personas e imágenes: quién ve y quién escribe la ficha de otro | §97–100, §153–156 |
| [F](noche-2026-08-23/f.md) | PIAR, actividades y votaciones | §101–104 |
| [G](noche-2026-08-23/g.md) | Los 44 interruptores, contra los cuatro clientes | §105–107 |
| [H](noche-2026-08-23/h.md) | Los 230 identificadores del cuerpo | §108–110 |
| [I](noche-2026-08-23/i.md) | El barrido por tipo de token | §111–113 |
| [J](noche-2026-08-23/j.md) | Las rutas ya cubiertas que nadie juzgó | §114–116 |
| [K](noche-2026-08-23/k.md) | Las columnas que se pisan donde no llegaba ningún lote | §118–121 |
| [L](noche-2026-08-23/l.md) | Las sobras huérfanas | §123–124 |
| [M](noche-2026-08-23/m.md) | Descongelar los dos modelos congelados | §125–127 |
| [N](noche-2026-08-23/n.md) | El ayudante que devolvía un superusuario | §157–159 |
| [O](noche-2026-08-23/o.md) | La población de `PerfilesController` | §130–132 |
| [P](noche-2026-08-23/p.md) | Las que escriben sin decirlo |  §133–137 |
| [Q](noche-2026-08-23/q.md) | El calendario, donde el cliente decidía | §150–152 |
| [R](noche-2026-08-23/r.md) | El boletín de una familia, y la imagen ajena en el muro | §140–142, §166–167 |
| [S](noche-2026-08-23/s.md) | La única escritura que alcanza a una familia | §143–145 |
| [T](noche-2026-08-23/t.md) | Lo que destapó la curva de profundidad | §146–149 |

**Los huecos —§117, §128–129, §138–139 y §160–165— son números que nadie usó**
al abrir lotes sobre la marcha. Un hueco no es una sección perdida.

---

# La noche del 24 de agosto de 2026 — §168 en adelante

Esta noche trabajan **catorce sesiones en tres repositorios**, con dos
coordinaciones —`myvc-front-98` en el front, `8myvc-34` aquí— y una sola interfaz
entre ellas. El reparto y el tablero están fuera de git, en
`8myvc-cola/noche-2026-08-24/`. Aquí van sólo los hallazgos.

## §168. La firma del profesor: dos endpoints hermanos, tres diferencias y un borrado silencioso

**Lo trajo `myvc-front-89` desde el front, midiendo su propio contrato**, y lo que
reportaron era cierto: los dos hermanos leen **claves distintas** para lo mismo.
Comprobado aquí, hay **tres** diferencias y la del cuerpo es la menos grave.

| | `Perfiles/PerfilesController::putCambiarfirmaunprofe:941` | `Perfiles/ImagesUsuariosController::putCambiarFirmaUnProfe:186` |
|---|---|---|
| Ruta | `PUT perfiles/cambiarfirmaunprofe/{profeelegido}` | `PUT images-users/cambiar-firma-un-profe/{profe_id}` |
| Campo del cuerpo | **`imgFirmaProfe`** | **`imagen_id`** |
| Quién puede | `Autoriza::esAdministrativo` | `tipo == 'Profesor' \|\| is_superuser` |
| ¿De quién es la imagen? | **no lo pregunta** | `exigeQueLaImagenSeaSuyaODelColegio` |
| `updated_by` | no lo escribe | lo escribe |

**El borrado silencioso.** La primera asigna sin preguntar si el campo vino:

```php
$profesor->firma_id = Request::input('imgFirmaProfe');   // null si viene la otra clave
$profesor->save();
return ImageModel::find($profesor->firma_id);            // find(null) -> null, con 200
```

Una llamada con la clave de la hermana pone **`firma_id = null`**, guarda, y
contesta **200 con cuerpo `null`**. La firma desaparece del boletín —la leen
`Year::datos()` para rector y secretaria, y `Grupo` para el titular— **y nadie ve
un error**. Es la misma forma que el 200 que miente de la [§48.2](#), sólo que
aquí lo dispara **un nombre de campo equivocado**, no una rama sin salida.

**No está disparado hoy, y por qué importa saberlo:** el front viejo —el que corre
en los dieciséis— manda `imgFirmaProfe` (`FileManagerCtrl.ts:476`), así que el
camino vivo funciona. El tipo nuevo de `app2` declaraba `imagen_id`, y **la
pantalla de imágenes de `app2` llama a la hermana buena**, no a ésta. O sea que
esto no es un incendio: es una mina puesta. El front la desactiva por su lado
mandando `imgFirmaProfe` —**lo que lee el backend desplegado, no el fusionado**— y
lo deja escrito en la cabecera para que nadie lo «arregle» al revés dentro de seis
meses.

**Lo que hay que decidir aquí es cuál de las dos gana**, y la respuesta no es
«la que llama el front»: la que llama el front es **la que no comprueba de quién es
la imagen**. Las dos escriben la misma columna de la misma tabla con dos criterios
de permiso distintos, y ésa es la familia del [§14](09-pendientes.md) —dos nombres
casi iguales que no son la misma condición—, no un problema de nombres de campo.

**El arreglo del borrado ya está escrito en este repo y por eso no hace falta
inventarlo:** `App\Support\CamposQueVinieron` distingue *«el campo no vino»* de
*«el campo vino vacío»*, y va por **15 ficheros**. Ésta es la 16.ª.

## §169. `puestoAlumno` son dos funciones, en dos repos, con un desfase de uno — y la muerta es la de fuera

Salió de una discrepancia entre `myvc-front-98` y esta sesión que **parecía que
una de las dos estaba equivocada, y no lo estaba ninguna**:

```
8myvc   app/Models/Nota.php:122                        Nota::puestoAlumno   -> $puesto = 1
front   app/scripts/informes/PuestoAlumnoFilter.ts:57  filtro puestoAlumno  -> let puesto = 0
```

**El mismo nombre, la misma idea —contar cuántos te ganan, para que los empatados
compartan puesto— y un desfase de uno.** Cada una citó su fuente y las dos eran
ciertas; lo que fallaba era dar por hecho que un nombre igual en dos repositorios
es la misma función.

Lo que lo hace peligroso es lo que se midió después: **el filtro del front está
muerto** —ninguna plantilla de `app/scripts` lo llama, y por eso no se tradujo a
`app2`—. Queda un **cadáver 0-based con el mismo nombre que un método vivo
1-based**. El día que alguien lo resucite «para que cuadre», mete un desfase de uno
**en un papel que se entrega a las familias**, y se leerá como *«el backend cambió
el puesto»*.

**Y de paso deja limpio lo que separa a los dos caminos vivos**, que es lo que hay
que escribir en el plan del boletín independiente: las cuatro rutas de puestos no
calculan nada —el front pinta `$index + 1`— y los ocho impresores llaman a
`Nota::puestoAlumno`. Los dos son 1-based, **así que la única diferencia aparece
con empates**: posición `1,2,3,4` · puesto `1,1,3,4`. Un test del interruptor **sin
un empate dentro no distingue los dos caminos** y pasa sin probar nada.

> La regla que deja esta noche, y es de método: **una cifra sobrevivió tres veces
> sólo porque alguien fue a mirar el código en vez de fiarse del texto — y las tres
> veces el texto era nuestro.**

## §170. El botón de borrar la bitácora llevaba años mudo — y eso es la evidencia que sostiene su retirada

Joseth decidió esta noche que **nadie borra un intento de login fallido y el botón
desaparece**. `myvc-front-98` fue a medir el lote para entregarlo con fichero y
línea, y encontró lo que faltaba en el expediente:

**En la aplicación vieja ese botón no borraba.** Su `cellTemplate` llamaba a
`eliminar(row.entity)` y **el controlador no definía ningún `eliminar`**: estaban
la ruta del backend y el método del repositorio, **faltaba el de en medio**
(escrito por quien lo tradujo, en `app2/src/app/paginas/bitacora/bitacora.ts:137-142`).
Pulsarlo no hacía nada y no lo decía. Se conectó en la **fase 8** de la migración
del front, y a `app2` llegó ya funcionando.

**O sea que ese botón lleva años mudo en dieciséis colegios y nadie lo reportó
jamás.** Es el mismo patrón que `boletines/detailed-notas` dando 500 durante cinco
años sin una queja, **sólo que aquí juega a favor**: la ausencia de quejas sobre
una función que no funciona **mide cuánta falta hace**. Si algún día hay que
defender la retirada ante un colegio, ése es el argumento y tiene fecha.

Y la vuelta, que conviene decir en voz alta para que nadie lo lea como trabajo
tirado: **la migración arregló ese botón y ahora se retira.** No se perdió el
trabajo — **fue el arreglo el que puso el asunto delante de alguien que podía
decidir.** Mudo, nadie lo habría mirado nunca.

### Lo que se comprobó aquí antes de meterlo en la cola, que es lo que faltaba

**Ningún otro cliente llama a `DELETE bitacoras/destroy/{id}`.** Importa porque
`myvc_flutter` es **una sola app para los dieciséis** y una retirada no se puede
escalonar: si la llamara, la retirada sería un 404 en todos a la vez. No la llama
—su única mención a `bitacoras` es un comentario en `HistorialNotaApi.dart:8`, y
sus borrados son `ausencias`, `frases_asignatura`, `notas`, `unidades` y
`subunidades`—. `myvc_front_2` (el PIAR) tampoco.

**Y el primer detector mintió, en la dirección de siempre.** Un
`grep -rn "bitacoras/destroy"` sobre los cuatro clientes devolvió **un solo
resultado y era un comentario**, o sea *«no la llama nadie»*. Falso: la URL se
arma por concatenación —`api.del(RECURSO + '/destroy/' + id)`,
`services/api/BitacorasApi.ts:29`— y la cadena completa **no existe en el código**.
La conclusión final coincide, pero por el camino corto habría sido una casualidad:
**el primer sitio donde mirar cuando el número sale limpio también es el detector.**

### Orden de retirada, y el hilo que queda suelto

**El front va delante**: quita los dos botones (`mis-sesiones` y la rejilla de
`/panel/bitacora`), y sus dos pruebas **no se borran, se dan la vuelta** — pasan a
afirmar que el botón **no está**, que es lo que impide que vuelva por descuido.
`eliminar()` de `datos/bitacoras.ts` **se queda sin consumidores y tampoco se
borra**: el endpoint sigue vivo en los dieciséis hasta que se retire aquí. **Cuando
se retire hay que avisar al front para que lo limpie entonces** — sin ese aviso se
queda un método muerto apuntando a una ruta que ya no existe, que es la forma en
que estas retiradas dejan basura detrás.
