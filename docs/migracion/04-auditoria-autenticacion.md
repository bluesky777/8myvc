# Auditoría de autenticación — qué rutas no resuelven al usuario

**Generado por `tools/auditar-autenticacion.php`.** Para regenerarlo:

```bash
docker exec 8myvc-app-1 php tools/auditar-autenticacion.php
docker exec 8myvc-app-1 php tools/auditar-autenticacion.php --csv > /tmp/auditoria.csv
```

## Por qué esta lista y no una semana de registro

El plan proponía desplegar un middleware que registrara durante una semana qué
rutas llegan sin token. No sirve: hay semanas en que los colegios no usan el
sistema, así que la ausencia de registros no distingue "nadie llama a esta ruta"
de "nadie usó el sistema esa semana". Esto se determina leyendo el código, que
no depende de que alguien entre.

## Cómo se determinó

El proyecto no tiene middleware de autenticación: cada método se defiende solo
llamando a `User::fromToken()`, que aborta con 401 si no hay token, si expiró o
si es inválido (`app/User.php:85-99`). Llamarlo **es** una comprobación.

Se recorren las rutas reales del router y se analiza el cuerpo de cada método con
el analizador sintáctico —no con `grep`, que contaría un `fromToken` escrito
dentro de un comentario—. Se siguen además las llamadas a métodos auxiliares de
la propia clase: el PR #3 puso las guardas en `$this->exigirAdminUsuarios()`, y
mirando solo el cuerpo directo salían como desprotegidas.

Cuenta como resuelto: `User::fromToken()`, `JWTAuth::*`, `Auth::*`, `auth()` o
`$this->user` (resuelto en el constructor), directo o vía auxiliar.

**Lo que esto NO dice:** que las 438 que sí resuelven al usuario estén bien.
Resolver al usuario prueba que hay token válido, no que ese usuario tenga permiso
para lo que va a hacer. Un alumno con token es un usuario autenticado.

## Resumen

| | Rutas |
|---|---|
| Resuelven al usuario | **438** |
| No lo resuelven y **escriben** en la base | **63** |
| No lo resuelven, solo leen | **40** |
| Método vacío: la ruta existe, el método no hace nada | 10 |
| Ruta registrada cuyo método no existe | 3 |
| **Total** | **554** |

---

## 1. Escriben en la base sin resolver al usuario — 58 a revisar

Lo urgente. Cada una permite modificar datos de un colegio sin presentar token.

**Marca la casilla si es un error y necesita el guard.**

| ✔ | Verbo | Ruta | Controlador · método | Escribe |
|---|---|---|---|---|
| ☐ | `PUT` | `api/actividades/insert-grupo-compartido` | Actividades\ActividadesController::putInsertGrupoCompartido | ->save() |
| ☐ | `GET` | `api/folios/iniciar` | Alumnos\FoliosController::getIniciar | DB::update |
| ☐ | `GET` | `api/importar` | Alumnos\ImportarController::getIndex | ->save() |
| ☐ | `POST` | `api/importar/cartera` | Alumnos\ImportarController::postCartera | DB::update |
| ☐ | `GET` | `api/importar/modificar/{year}` | Alumnos\ImportarController::getModificar | DB::update |
| ☐ | `PUT` | `api/asistencias-app/eliminar-ausencia` | AppMobile\AsistenciasAppController::putEliminarAusencia | ->save(), ->delete() |
| ☐ | `PUT` | `api/asistencias-app/poner-ausencia` | AppMobile\AsistenciasAppController::putPonerAusencia | DB::insert |
| ☐ | `POST` | `api/areas` | AreasController::postIndex | ->save() |
| ☐ | `DELETE` | `api/areas/destroy/{id}` | AreasController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/areas/update/{id}` | AreasController::putUpdate | ->save() |
| ☐ | `POST` | `api/asignaturas` | AsignaturasController::postIndex | ->save() |
| ☐ | `DELETE` | `api/asignaturas/destroy/{id}` | AsignaturasController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/asignaturas/update/{id}` | AsignaturasController::putUpdate | ->save() |
| ☐ | `DELETE` | `api/definiciones_comportamiento/destroy/{id}` | DefinicionesComportamientoController::deleteDestroy | ->delete() |
| ☐ | `POST` | `api/definiciones_comportamiento/store` | DefinicionesComportamientoController::postStore | ->save() |
| ☐ | `POST` | `api/definiciones_comportamiento/store-escrita` | DefinicionesComportamientoController::postStoreEscrita | ->save() |
| ☐ | `DELETE` | `api/editnota/destroy/{id}` | EditnotaController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/editnota/restore/{id}` | EditnotaController::putRestore | ->restore() |
| ☐ | `POST` | `api/estados_civiles` | EstadosCivilesController::store | EstadoCivil::create |
| ☐ | `PUT` | `api/estados_civiles/{estados_civile}` | EstadosCivilesController::update | ->save() |
| ☐ | `PATCH` | `api/estados_civiles/{estados_civile}` | EstadosCivilesController::update | ->save() |
| ☐ | `DELETE` | `api/estados_civiles/{estados_civile}` | EstadosCivilesController::destroy | ->delete() |
| ☐ | `DELETE` | `api/grados/destroy/{id}` | GradosController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/grados/update/{id}` | GradosController::putUpdate | ->save() |
| ☐ | `PUT` | `api/bolfinales/cambiar-contador-certificados` | Informes\BolfinalesController::putCambiarContadorCertificados | DB::update |
| ☐ | `PUT` | `api/bolfinales/cambiar-contador-folios` | Informes\BolfinalesController::putCambiarContadorFolios | DB::update |
| ☐ | `DELETE` | `api/materias/destroy/{id}` | MateriasController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/materias/update-orden` | MateriasController::putUpdateOrden | ->save() |
| ☐ | `PUT` | `api/materias/update/{id}` | MateriasController::putUpdate | ->save() |
| ☐ | `DELETE` | `api/niveles_educativos/destroy/{id}` | NivelesEducativosController::deleteDestroy | ->delete() |
| ☐ | `POST` | `api/niveles_educativos/store` | NivelesEducativosController::postStore | ->save() |
| ☐ | `PUT` | `api/niveles_educativos/update/{id}` | NivelesEducativosController::putUpdate | ->save() |
| ☐ | `DELETE` | `api/nota_comportamiento/destroy/{id}` | NotaComportamientoController::deleteDestroy | ->delete() |
| ☐ | `POST` | `api/paises/store` | PaisesController::postStore | DB::insert |
| ☐ | `DELETE` | `api/images-users/destroy/{id}` | Perfiles\ImagesUsuariosController::deleteDestroy | File::delete, ->delete(), ->save() |
| ☐ | `PUT` | `api/images-users/rotar-imagen-izquierda/{imagen_id}` | Perfiles\ImagesUsuariosController::putRotarImagenIzquierda | ->save() |
| ☐ | `PUT` | `api/images-users/rotarimagen/{imagen_id}` | Perfiles\ImagesUsuariosController::putRotarimagen | ->save() |
| ☐ | `PUT` | `api/perfiles/cambiarfirmaunprofe/{profeelegido}` | Perfiles\PerfilesController::putCambiarfirmaunprofe | ->save() |
| ☐ | `PUT` | `api/perfiles/cambiarimgunalumno/{alumnoelegido}` | Perfiles\PerfilesController::putCambiarimgunalumno | ->save() |
| ☐ | `PUT` | `api/perfiles/cambiarimgunprofe/{profeelegido}` | Perfiles\PerfilesController::putCambiarimgunprofe | ->save() |
| ☐ | `PUT` | `api/perfiles/cambiarimgunusuario/{usuarioelegido}` | Perfiles\PerfilesController::putCambiarimgunusuario | ->save() |
| ☐ | `DELETE` | `api/perfiles/destroy/{id}` | Perfiles\PerfilesController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/asistencias/eliminar-ausencia` | Tardanzas\AsistenciasController::putEliminarAusencia | ->save(), ->delete() |
| ☐ | `PUT` | `api/asistencias/poner-ausencia` | Tardanzas\AsistenciasController::putPonerAusencia | DB::insert |
| ☐ | `POST` | `api/tiposdocumento` | TipoDocumentoController::store | ->save() |
| ☐ | `PUT` | `api/tiposdocumento/{tiposdocumento}` | TipoDocumentoController::update | ->save() |
| ☐ | `PATCH` | `api/tiposdocumento/{tiposdocumento}` | TipoDocumentoController::update | ->save() |
| ☐ | `DELETE` | `api/tiposdocumento/{tiposdocumento}` | TipoDocumentoController::destroy | ->delete() |
| ☐ | `DELETE` | `api/aspiraciones/destroy/{id}` | VtAspiracionesController::deleteDestroy | ->delete() |
| ☐ | `POST` | `api/aspiraciones/store` | VtAspiracionesController::postStore | ->save() |
| ☐ | `PUT` | `api/aspiraciones/update` | VtAspiracionesController::putUpdate | ->save() |
| ☐ | `DELETE` | `api/candidatos/destroy/{id}` | VtCandidatosController::deleteDestroy | ->delete() |
| ☐ | `DELETE` | `api/participantes/destroy/{id}` | VtParticipantesController::deleteDestroy | ->delete() |
| ☐ | `DELETE` | `api/votaciones/destroy/{id}` | VtVotacionesController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/votaciones/update/{id}` | VtVotacionesController::putUpdate | ->save() |
| ☐ | `DELETE` | `api/votos/destroy/{id}` | VtVotosController::deleteDestroy | ->delete() |
| ☐ | `PUT` | `api/votos/update/{id}` | VtVotosController::putUpdate | ->save() |
| ☐ | `PUT` | `api/years/restore/{id}` | YearsController::putRestore | ->restore() |

### Públicas a propósito (escriben, pero son el flujo de entrada)

De `login/*` y `password/*`, que el plan ya lista como públicas. No pueden llevar
guard —son justo lo que se usa sin token—, pero conviene mirar `putLogout`: recibe
el `user_id` por parámetro, así que hoy cualquiera puede cerrar la sesión de
cualquiera.

| ✔ | Verbo | Ruta | Controlador · método | Escribe |
|---|---|---|---|---|
| ☐ | `PUT` | `api/login/crear-prematricula` | LoginController::putCrearPrematricula | DB::update, DB::insert, ->save() |
| ☐ | `PUT` | `api/login/logout` | LoginController::putLogout | DB::update |
| ☐ | `PUT` | `api/login/reset-password` | LoginController::putResetPassword | DB::update, DB::delete |
| ☐ | `POST` | `api/login/ver-pass` | LoginController::postVerPass | DB::delete, DB::insert |
| ☐ | `POST` | `api/password/reset` | RemindersController::postReset | ->save() |

---

## 2. Solo leen, sin resolver al usuario — 37 a revisar

Menos grave que escribir, pero varias exponen datos de menores a cualquiera que
sepa la URL.

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

## 4. Rutas registradas cuyo método no existe — 3

`TipoDocumentoController` está registrado como recurso pero no implementa estos
tres métodos. Hoy revientan si alguien las llama.

| ✔ | Verbo | Ruta | Controlador · método |
|---|---|---|---|
| ☐ | `GET` | `api/tiposdocumento/create` | TipoDocumentoController::create |
| ☐ | `GET` | `api/tiposdocumento/{tiposdocumento}` | TipoDocumentoController::show |
| ☐ | `GET` | `api/tiposdocumento/{tiposdocumento}/edit` | TipoDocumentoController::edit |
