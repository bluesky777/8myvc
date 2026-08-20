<?php

use App\Http\Controllers\Perfiles\CalendarioController;
use App\Http\Controllers\Perfiles\ImagesController;
use App\Http\Controllers\Perfiles\ImagesUsuariosController;
use App\Http\Controllers\Perfiles\PerfilesController;
use App\Http\Controllers\Perfiles\PublicacionesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: perfiles
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// PerfilesController
Route::get('perfiles', [PerfilesController::class, 'getIndex']);
// Crea usuarios, les asigna roles y toca acudientes. No nombra a nadie.
Route::put('perfiles/creartodoslosusuarios', [PerfilesController::class, 'putCreartodoslosusuarios'])->middleware('auth.personal');
Route::put('perfiles/guardar-mi-email-restore', [PerfilesController::class, 'putGuardarMiEmailRestore']);
Route::post('perfiles/store', [PerfilesController::class, 'postStore']);
Route::get('perfiles/trashed', [PerfilesController::class, 'getTrashed'])->middleware('auth.personal');
Route::get('perfiles/usernames', [PerfilesController::class, 'getUsernames']);
// El directorio entero del colegio: nombre, usuario, tipo, correo, fecha de
// nacimiento, foto y roles de las 2.279 personas. Lo pinta la rejilla de
// `UsuariosCtrl`, que es de administración.
Route::get('perfiles/usuariosall', [PerfilesController::class, 'getUsuariosall'])->middleware('auth.personal');
Route::put('perfiles/cambiaremailrestore/{id}', [PerfilesController::class, 'putCambiaremailrestore'])->middleware('persona.propia:user_id');
// Las cuatro `cambiar*un*` operan sobre OTRA persona —y además devuelven su
// ficha entera—, y el identificador no se llama como ninguno de los que el
// guard conoce: `{profeelegido}`, `{alumnoelegido}`, `{usuarioelegido}`.
Route::put('perfiles/cambiarfirmaunprofe/{profeelegido}', [PerfilesController::class, 'putCambiarfirmaunprofe'])->middleware('auth.personal');
Route::put('perfiles/cambiarimgunalumno/{alumnoelegido}', [PerfilesController::class, 'putCambiarimgunalumno'])->middleware('auth.personal');
Route::put('perfiles/cambiarimgunprofe/{profeelegido}', [PerfilesController::class, 'putCambiarimgunprofe'])->middleware('auth.personal');
Route::put('perfiles/cambiarimgunusuario/{usuarioelegido}', [PerfilesController::class, 'putCambiarimgunusuario'])->middleware('auth.personal');
Route::put('perfiles/cambiarpassword/{id}', [PerfilesController::class, 'putCambiarpassword'])->middleware('persona.propia:user_id');
Route::get('perfiles/comprobarusername/{username}', [PerfilesController::class, 'getComprobarusername']);
Route::delete('perfiles/destroy/{id}', [PerfilesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::delete('perfiles/forcedelete/{id}', [PerfilesController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::put('perfiles/guardar-username/{id}', [PerfilesController::class, 'putGuardarUsername'])->middleware('persona.propia:user_id');
Route::put('perfiles/reset-password/{id}', [PerfilesController::class, 'putResetPassword']);
Route::put('perfiles/restore/{id}', [PerfilesController::class, 'putRestore'])->middleware('auth.personal');
// `getShow` hace `Grupo::findOrFail($id)`: es GruposController copiado en el
// fichero equivocado, y con él otros cuatro métodos. El guard que llevaba decía
// que `{id}` era un `user_id` —el guard cumplía, y lo que le habían dicho era
// falso—, así que un alumno cuyo user_id coincidiera con un grupo recibía ese
// grupo. No la llama ningún cliente. §14.2.
Route::get('perfiles/show/{id}', [PerfilesController::class, 'getShow'])->middleware('auth.personal');
Route::put('perfiles/update/{id}', [PerfilesController::class, 'putUpdate'])->middleware('persona.propia:persona_id');
Route::get('perfiles/username/{username}', [PerfilesController::class, 'getUsername']);

// ImagesController
Route::get('myimages', [ImagesController::class, 'getIndex']);
// El logo del colegio es de `years`, o sea del colegio.
Route::put('myimages/cambiarlogocolegio', [ImagesController::class, 'putCambiarlogocolegio'])->middleware('auth.personal');
Route::put('myimages/datos-imagen', [ImagesController::class, 'putDatosImagen'])->middleware('persona.propia');
Route::post('myimages/store', [ImagesController::class, 'postStore']);
Route::post('myimages/store-firma', [ImagesController::class, 'postStoreFirma']);
Route::post('myimages/store-intacta', [ImagesController::class, 'postStoreIntacta']);
Route::post('myimages/store-intacta-privada', [ImagesController::class, 'postStoreIntactaPrivada']);
Route::delete('myimages/destroy/{id}', [ImagesController::class, 'deleteDestroy']);
Route::put('myimages/privatizar-imagen/{imagen_id}', [ImagesController::class, 'putPrivatizarImagen'])->middleware('persona.propia');
Route::put('myimages/publicar-imagen/{imagen_id}', [ImagesController::class, 'putPublicarImagen'])->middleware('persona.propia');

// ImagesUsuariosController
Route::put('images-users/imagenes-de-usuario', [ImagesUsuariosController::class, 'putImagenesDeUsuario']);
// `UPDATE images SET user_id=<yo> WHERE id=:img_id`, sin mirar de quién era.
// Es una escalada, no una fuga: hecha mía la imagen, las hermanas de abajo
// —rotar, publicar, privatizar— ya la dan por suya y dejan pasar. El guard no
// veía nada porque aquí la clave se llama `img_id`; ahora la conoce.
Route::put('images-users/move-img-to-me', [ImagesUsuariosController::class, 'putMoveImgToMe'])->middleware('persona.propia');
Route::put('images-users/cambiar-firma-un-profe/{profe_id}', [ImagesUsuariosController::class, 'putCambiarFirmaUnProfe'])->middleware('auth.personal');
Route::put('images-users/cambiar-foto-un-usuario/{user_id}', [ImagesUsuariosController::class, 'putCambiarFotoUnUsuario'])->middleware('auth.personal');
Route::put('images-users/cambiar-imagen-oficial/{user_id}', [ImagesUsuariosController::class, 'putCambiarImagenOficial'])->middleware('persona.propia');
// Sus dos vecinas —`cambiar-imagen-oficial` y `cambiar-imagen-un-usuario`, con
// el mismo `{user_id}`— sí lo llevaban desde la revisión de IDOR. Esta se saltó.
Route::put('images-users/cambiar-imagen-perfil/{user_id}', [ImagesUsuariosController::class, 'putCambiarImagenPerfil'])->middleware('persona.propia');
Route::put('images-users/cambiar-imagen-un-usuario/{user_id}', [ImagesUsuariosController::class, 'putCambiarImagenUnUsuario'])->middleware('persona.propia');
// `:imagen_id` no es decorativo. El guard recoge los identificadores por su
// NOMBRE, y esta es la única ruta de imagen que llama `{id}` a lo que sus
// hermanas llaman `{imagen_id}`: sin decirle a qué apunta, un alumno pasaba de
// largo y borraba la foto de cualquiera. Ver 05-codigo-muerto-y-roto.md §13.
Route::delete('images-users/destroy/{id}', [ImagesUsuariosController::class, 'deleteDestroy'])->middleware('persona.propia:imagen_id');
Route::put('images-users/rotar-imagen-izquierda/{imagen_id}', [ImagesUsuariosController::class, 'putRotarImagenIzquierda'])->middleware('persona.propia');
Route::put('images-users/rotarimagen/{imagen_id}', [ImagesUsuariosController::class, 'putRotarimagen'])->middleware('persona.propia');

// PublicacionesController
Route::put('publicaciones/borrar-comentario', [PublicacionesController::class, 'putBorrarComentario']);
Route::put('publicaciones/comentar', [PublicacionesController::class, 'putComentar']);
Route::put('publicaciones/delete', [PublicacionesController::class, 'putDelete']);
Route::put('publicaciones/guardar-edicion', [PublicacionesController::class, 'putGuardarEdicion']);
Route::put('publicaciones/restaurar', [PublicacionesController::class, 'putRestaurar']);
Route::put('publicaciones/store', [PublicacionesController::class, 'putStore']);
// Las pinta la propia pantalla de login, con el usuario aún sin autenticar, y su
// respuesta alimenta además el formulario público de prematrícula (el desplegable
// de grupo sale de year.grados_sig). Los dos verbos siguen abiertos porque el GET
// fue el verbo real del front durante cinco años y medio y devuelve exactamente
// lo mismo. Ver docs/migracion/04-auditoria-autenticacion.md §5.
Route::put('publicaciones/ultimas', [PublicacionesController::class, 'putUltimas'])->withoutMiddleware('auth.token');
Route::get('publicaciones/ultimas', [PublicacionesController::class, 'getUltimas'])->withoutMiddleware('auth.token');

// CalendarioController
Route::put('calendario/crear-evento', [CalendarioController::class, 'putCrearEvento']);
Route::put('calendario/eliminar-evento', [CalendarioController::class, 'putEliminarEvento']);
Route::put('calendario/guardar-evento', [CalendarioController::class, 'putGuardarEvento']);
Route::put('calendario/sincronizar-cumples', [CalendarioController::class, 'putSincronizarCumples']);
Route::put('calendario/this-year', [CalendarioController::class, 'putThisYear']);
