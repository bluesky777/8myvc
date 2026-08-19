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
