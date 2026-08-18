# Auditoría de autenticación — qué rutas no resuelven al usuario

**GENERADO. No editar a mano.** Se regenera con:

```bash
docker exec 8myvc-app-1 php tools/auditar-autenticacion.php --md \
  > docs/migracion/04-auditoria-autenticacion.md
```

## Por qué esta lista y no una semana de registro

El plan proponía desplegar un middleware que registrara durante una semana qué
rutas llegan sin token. No sirve: hay semanas en que los colegios no usan el
sistema, así que la ausencia de registros no distingue "nadie llama a esta ruta"
de "nadie usó el sistema esa semana". Esto se determina leyendo el código, que no
depende de que alguien entre.

## Cómo se determinó

Al principio este proyecto no tenía middleware de autenticación: cada método se
defendía solo llamando a `User::fromToken()`, que aborta con 401 si no hay token,
si expiró o si es inválido (`app/User.php:85-99`). Llamarlo **es** una
comprobación.

Se recorren las rutas reales del router y se analiza el cuerpo de cada método con
el analizador sintáctico —no con `grep`, que contaría un `fromToken` escrito
dentro de un comentario—. Se siguen además las llamadas a métodos auxiliares de
la propia clase: el PR #3 puso las guardas en `$this->exigirAdminUsuarios()`, y
mirando solo el cuerpo directo salían como desprotegidas.

Cuenta como resuelto: el middleware `auth.token`, o una llamada a
`User::fromToken()`, `JWTAuth::*`, `Auth::*`, `auth()` o `$this->user` (resuelto
en el constructor), directa o vía auxiliar.

**Lo que esto NO dice:** que las que sí resuelven al usuario estén bien.
Resolverlo prueba que hay token válido, no que ese usuario tenga permiso para lo
que va a hacer. Un alumno con token es un usuario autenticado. Eso es otra
auditoría.

## Resumen

| | Rutas |
|---|---|
| Resuelven al usuario | **496** |
| No lo resuelven y **escriben** en la base | **6** |
| No lo resuelven, solo leen | **40** |
| Método vacío: la ruta existe, el método no hace nada | 10 |
| Ruta registrada cuyo método no existe | 0 |
| **Total** | **552** |

---

## 1. Escriben en la base sin resolver al usuario — 0 a revisar

Lo urgente: permiten modificar datos de un colegio sin presentar token.

> **Las 58 que había aquí están cerradas** con el middleware `auth.token`
> (Joseth las confirmó todas como error el 18 ago 2026). `tests/Contrato/AutenticacionTest.php`
> comprueba que responden 401 sin token y que no rechazan a un usuario legítimo.

_Ninguna._


### Públicas a propósito (escriben, pero son el flujo de entrada)

De `login/*` y `password/*`, que el plan ya lista como públicas. No pueden llevar
guard —son justo lo que se usa sin token—, pero conviene mirar `putLogout`:
recibe el `user_id` por parámetro, así que hoy cualquiera puede cerrar la sesión
de cualquiera.

| ✔ | Verbo | Ruta | Controlador · método | Escribe |
|---|---|---|---|---|
| ☐ | `PUT` | `api/login/crear-prematricula` | LoginController::putCrearPrematricula | DB::update, DB::insert, ->save() |
| ☐ | `PUT` | `api/login/logout` | LoginController::putLogout | DB::update |
| ☐ | `POST` | `api/login/recuperar-clave` | LoginController::postRecuperarClave | DB::delete, DB::insert |
| ☐ | `PUT` | `api/login/reset-password` | LoginController::putResetPassword | DB::update, DB::delete |
| ☐ | `POST` | `api/login/ver-pass` | LoginController::postRecuperarClave | DB::delete, DB::insert |
| ☐ | `POST` | `api/password/reset` | RemindersController::postReset | ->save() |


---

## 2. Solo leen, sin resolver al usuario — 37 a revisar

Menos grave que escribir, pero varias exponen datos de menores a cualquiera que
sepa la URL. **Pendiente de confirmar una por una.**

| ✔ | Verbo | Ruta | Controlador · método |
|---|---|---|---|
| ☐ | `POST` | `api/importar/algo/{year}` | Alumnos\ImportarController::postAlgo |
| ☐ | `GET` | `api/asistencias-app/datos-solo-alumnos` | AppMobile\AsistenciasAppController::getDatosSoloAlumnos |
| ☐ | `GET` | `api/areas` | AreasController::getIndex |
| ☐ | `PUT` | `api/asignaturas/detalle-asignatura` | AsignaturasController::putDetalleAsignatura |
| ☐ | `PUT` | `api/cartera/alumnos` | CarteraController::putAlumnos |
| ☐ | `PUT` | `api/cartera/solo-deudores` | CarteraController::putSoloDeudores |
| ☐ | `GET` | `api/definiciones_comportamiento` | DefinicionesComportamientoController::getIndex |
| ☐ | `GET` | `api/estados_civiles` | EstadosCivilesController::index |
| ☐ | `GET` | `api/grados/show/{id}` | GradosController::getShow |
| ☐ | `GET` | `api/grupos/show/{id}` | GruposController::getShow |
| ☐ | `GET` | `api/grupos/trashed` | GruposController::getTrashed |
| ☐ | `GET` | `api/acudientes-export/acudientes` | Informes\AcudientesExportController::getAcudientes |
| ☐ | `GET` | `api/excel-docentes` | Informes\ExcelListadoDocentesController::getIndex |
| ☐ | `GET` | `api/observador` | Informes\ObservadorController::getIndex |
| ☐ | `GET` | `api/simat` | Informes\SimatController::getIndex |
| ☐ | `GET` | `api/niveles_educativos` | NivelesEducativosController::getIndex |
| ☐ | `GET` | `api/niveles_educativos/show/{id}` | NivelesEducativosController::getShow |
| ☐ | `PUT` | `api/nota_comportamiento/frases-check` | NotaComportamientoController::putFrasesCheck |
| ☐ | `GET` | `api/paises` | PaisesController::getIndex |
| ☐ | `PUT` | `api/calendario/this-year` | Perfiles\CalendarioController::putThisYear |
| ☐ | `PUT` | `api/myimages/datos-imagen` | Perfiles\ImagesController::putDatosImagen |
| ☐ | `GET` | `api/perfiles/comprobarusername/{username}` | Perfiles\PerfilesController::getComprobarusername |
| ☐ | `GET` | `api/perfiles/show/{id}` | Perfiles\PerfilesController::getShow |
| ☐ | `GET` | `api/perfiles/trashed` | Perfiles\PerfilesController::getTrashed |
| ☐ | `GET` | `api/perfiles/username/{username}` | Perfiles\PerfilesController::getUsername |
| ☐ | `GET` | `api/perfiles/usernames` | Perfiles\PerfilesController::getUsernames |
| ☐ | `GET` | `api/perfiles/usuariosall` | Perfiles\PerfilesController::getUsuariosall |
| ☐ | `PUT` | `api/publicaciones/ultimas` | Perfiles\PublicacionesController::putUltimas |
| ☐ | `GET` | `api/publicaciones/ultimas` | Perfiles\PublicacionesController::getUltimas |
| ☐ | `GET` | `api/asistencias/datos-solo-alumnos` | Tardanzas\AsistenciasController::getDatosSoloAlumnos |
| ☐ | `GET` | `api/tiposdocumento` | TipoDocumentoController::index |
| ☐ | `GET` | `api/users/export` | UsersController::getExport |
| ☐ | `PUT` | `api/users/usernames-check` | UsersController::putUsernamesCheck |
| ☐ | `GET` | `api/votaciones/show/{id}` | VtVotacionesController::getShow |
| ☐ | `GET` | `api/votaciones/unsignedsusers` | VtVotacionesController::getUnsignedsusers |
| ☐ | `GET` | `api/votos` | VtVotosController::getIndex |
| ☐ | `GET` | `api/years/trashed` | YearsController::getTrashed |


### Públicas a propósito (lectura)

| ✔ | Verbo | Ruta | Controlador · método |
|---|---|---|---|
| ☐ | `GET` | `api/password/remind` | RemindersController::getRemind |
| ☐ | `POST` | `api/password/remind` | RemindersController::postRemind |
| ☐ | `GET` | `api/password/reset/{token?}` | RemindersController::getReset |


---

## 3. Métodos vacíos — 10

La ruta está registrada pero el método no hace nada. No son agujeros: son
endpoints muertos. Se pueden borrar sin tocar nada más.

| ✔ | Verbo | Ruta | Controlador · método |
|---|---|---|---|
| ☐ | `GET` | `api/ausencias` | AusenciasController::getIndex |
| ☐ | `POST` | `api/bitacoras/store` | BitacorasController::postStore |
| ☐ | `PUT` | `api/bitacoras/update/{id}` | BitacorasController::putUpdate |
| ☐ | `GET` | `api/estados_civiles/create` | EstadosCivilesController::create |
| ☐ | `GET` | `api/estados_civiles/{estados_civile}` | EstadosCivilesController::show |
| ☐ | `GET` | `api/estados_civiles/{estados_civile}/edit` | EstadosCivilesController::edit |
| ☐ | `POST` | `api/permissions` | PermissionsController::postIndex |
| ☐ | `DELETE` | `api/permissions/destroy/{id}` | PermissionsController::deleteDestroy |
| ☐ | `GET` | `api/permissions/show/{id}` | PermissionsController::getShow |
| ☐ | `PUT` | `api/permissions/update/{id}` | PermissionsController::putUpdate |


---

## 4. Rutas registradas cuyo método no existe — 0

Rutas cuyo controlador no implementa el método. Revientan con 500 si alguien las
llama.

> Las tres que había —`tiposdocumento/create`, `tiposdocumento/{id}` y
> `tiposdocumento/{id}/edit`, del andamiaje de recurso de Laravel— se
> eliminaron el 18 ago 2026, comprobado antes que devolvían 500.

_Ninguna._

