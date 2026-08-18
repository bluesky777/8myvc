<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Perfiles\CalendarioController;
use App\Http\Controllers\Perfiles\ImagesController;
use App\Http\Controllers\Perfiles\ImagesUsuariosController;
use App\Http\Controllers\Perfiles\PerfilesController;
use App\Http\Controllers\Perfiles\PublicacionesController;

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
Route::put('perfiles/creartodoslosusuarios', [PerfilesController::class, 'putCreartodoslosusuarios']);
Route::put('perfiles/guardar-mi-email-restore', [PerfilesController::class, 'putGuardarMiEmailRestore']);
Route::post('perfiles/store', [PerfilesController::class, 'postStore']);
Route::get('perfiles/trashed', [PerfilesController::class, 'getTrashed']);
Route::get('perfiles/usernames', [PerfilesController::class, 'getUsernames']);
Route::get('perfiles/usuariosall', [PerfilesController::class, 'getUsuariosall']);
Route::put('perfiles/cambiaremailrestore/{id}', [PerfilesController::class, 'putCambiaremailrestore']);
Route::put('perfiles/cambiarfirmaunprofe/{profeelegido}', [PerfilesController::class, 'putCambiarfirmaunprofe'])->middleware('auth.token');
Route::put('perfiles/cambiarimgunalumno/{alumnoelegido}', [PerfilesController::class, 'putCambiarimgunalumno'])->middleware('auth.token');
Route::put('perfiles/cambiarimgunprofe/{profeelegido}', [PerfilesController::class, 'putCambiarimgunprofe'])->middleware('auth.token');
Route::put('perfiles/cambiarimgunusuario/{usuarioelegido}', [PerfilesController::class, 'putCambiarimgunusuario'])->middleware('auth.token');
Route::put('perfiles/cambiarpassword/{id}', [PerfilesController::class, 'putCambiarpassword']);
Route::get('perfiles/comprobarusername/{username}', [PerfilesController::class, 'getComprobarusername']);
Route::delete('perfiles/destroy/{id}', [PerfilesController::class, 'deleteDestroy'])->middleware('auth.token');
Route::delete('perfiles/forcedelete/{id}', [PerfilesController::class, 'deleteForcedelete']);
Route::put('perfiles/guardar-username/{id}', [PerfilesController::class, 'putGuardarUsername']);
Route::put('perfiles/reset-password/{id}', [PerfilesController::class, 'putResetPassword']);
Route::put('perfiles/restore/{id}', [PerfilesController::class, 'putRestore']);
Route::get('perfiles/show/{id}', [PerfilesController::class, 'getShow']);
Route::put('perfiles/update/{id}', [PerfilesController::class, 'putUpdate']);
Route::get('perfiles/username/{username}', [PerfilesController::class, 'getUsername']);

// ImagesController
Route::get('myimages', [ImagesController::class, 'getIndex']);
Route::put('myimages/cambiarlogocolegio', [ImagesController::class, 'putCambiarlogocolegio']);
Route::put('myimages/datos-imagen', [ImagesController::class, 'putDatosImagen']);
Route::post('myimages/store', [ImagesController::class, 'postStore']);
Route::post('myimages/store-firma', [ImagesController::class, 'postStoreFirma']);
Route::post('myimages/store-intacta', [ImagesController::class, 'postStoreIntacta']);
Route::post('myimages/store-intacta-privada', [ImagesController::class, 'postStoreIntactaPrivada']);
Route::delete('myimages/destroy/{id}', [ImagesController::class, 'deleteDestroy']);
Route::put('myimages/privatizar-imagen/{imagen_id}', [ImagesController::class, 'putPrivatizarImagen']);
Route::put('myimages/publicar-imagen/{imagen_id}', [ImagesController::class, 'putPublicarImagen']);

// ImagesUsuariosController
Route::put('images-users/imagenes-de-usuario', [ImagesUsuariosController::class, 'putImagenesDeUsuario']);
Route::put('images-users/move-img-to-me', [ImagesUsuariosController::class, 'putMoveImgToMe']);
Route::put('images-users/cambiar-firma-un-profe/{profe_id}', [ImagesUsuariosController::class, 'putCambiarFirmaUnProfe']);
Route::put('images-users/cambiar-foto-un-usuario/{user_id}', [ImagesUsuariosController::class, 'putCambiarFotoUnUsuario']);
Route::put('images-users/cambiar-imagen-oficial/{user_id}', [ImagesUsuariosController::class, 'putCambiarImagenOficial']);
Route::put('images-users/cambiar-imagen-perfil/{user_id}', [ImagesUsuariosController::class, 'putCambiarImagenPerfil']);
Route::put('images-users/cambiar-imagen-un-usuario/{user_id}', [ImagesUsuariosController::class, 'putCambiarImagenUnUsuario']);
Route::delete('images-users/destroy/{id}', [ImagesUsuariosController::class, 'deleteDestroy'])->middleware('auth.token');
Route::put('images-users/rotar-imagen-izquierda/{imagen_id}', [ImagesUsuariosController::class, 'putRotarImagenIzquierda'])->middleware('auth.token');
Route::put('images-users/rotarimagen/{imagen_id}', [ImagesUsuariosController::class, 'putRotarimagen'])->middleware('auth.token');

// PublicacionesController
Route::put('publicaciones/borrar-comentario', [PublicacionesController::class, 'putBorrarComentario']);
Route::put('publicaciones/comentar', [PublicacionesController::class, 'putComentar']);
Route::put('publicaciones/delete', [PublicacionesController::class, 'putDelete']);
Route::put('publicaciones/guardar-edicion', [PublicacionesController::class, 'putGuardarEdicion']);
Route::put('publicaciones/restaurar', [PublicacionesController::class, 'putRestaurar']);
Route::put('publicaciones/store', [PublicacionesController::class, 'putStore']);
Route::put('publicaciones/ultimas', [PublicacionesController::class, 'putUltimas']);
Route::get('publicaciones/ultimas', [PublicacionesController::class, 'getUltimas']);

// CalendarioController
Route::put('calendario/crear-evento', [CalendarioController::class, 'putCrearEvento']);
Route::put('calendario/eliminar-evento', [CalendarioController::class, 'putEliminarEvento']);
Route::put('calendario/guardar-evento', [CalendarioController::class, 'putGuardarEvento']);
Route::put('calendario/sincronizar-cumples', [CalendarioController::class, 'putSincronizarCumples']);
Route::put('calendario/this-year', [CalendarioController::class, 'putThisYear']);
