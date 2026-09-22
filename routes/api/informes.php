<?php

use App\Http\Controllers\Auditoria\AuditoriaController;
use App\Http\Controllers\CertificadosEstudioController;
use App\Http\Controllers\ConfigCertificadosController;
use App\Http\Controllers\Historiales\HistorialesController;
use App\Http\Controllers\Informes\ActasEvaluacionController;
use App\Http\Controllers\Informes\AcudientesExportController;
use App\Http\Controllers\Informes\Boletines2Controller;
use App\Http\Controllers\Informes\Boletines3Controller;
use App\Http\Controllers\Informes\BoletinesController;
use App\Http\Controllers\Informes\BoletinPorCompetenciasController;
use App\Http\Controllers\Informes\BolfinalesPreescolarController;
use App\Http\Controllers\Informes\CertificadosPersonaController;
use App\Http\Controllers\Informes\ColillasInscripcionController;
use App\Http\Controllers\Informes\ExcelListadoDocentesController;
use App\Http\Controllers\Informes\FormulariosInscripcionController;
use App\Http\Controllers\Informes\InformesController;
use App\Http\Controllers\Informes\InformesRecientesController;
use App\Http\Controllers\Informes\MembreteController;
use App\Http\Controllers\Informes\NivelacionesController;
use App\Http\Controllers\Informes\NotasPerdidasController;
use App\Http\Controllers\Informes\ObservadorController;
use App\Http\Controllers\Informes\ObservadorHorizontalController;
use App\Http\Controllers\Informes\PagosInscripcionController;
use App\Http\Controllers\Informes\PuestosController;
use App\Http\Controllers\Informes\SimatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: informes
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// ConfigCertificadosController
Route::get('certificados', [ConfigCertificadosController::class, 'getIndex'])->middleware('auth.personal');
Route::put('certificados/actual', [ConfigCertificadosController::class, 'putActual'])->middleware('auth.personal');
Route::put('certificados/encabezado', [ConfigCertificadosController::class, 'putEncabezado'])->middleware('auth.personal');
Route::post('certificados/store', [ConfigCertificadosController::class, 'postStore'])->middleware('auth.personal');
Route::put('certificados/update', [ConfigCertificadosController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('certificados/destroy/{id}', [ConfigCertificadosController::class, 'deleteDestroy'])->middleware('auth.personal');

// AuditoriaController — fase 5 del 18-auditoria.md. Las cuatro son ADITIVAS: no
// retiran ninguna de las de `historiales/*` ni las de `bitacoras`, que siguen
// contestando igual y con los mismos alias hasta la fase 7, porque cada una tiene su
// propio cliente y su propia condición de salida (Flutter publicado, `app2`
// sustituyendo a `app/`). El permiso concreto va DENTRO del método, no en la ruta:
// `auth.personal` deja pasar a 75 cuentas y aquí lo propio se ve siempre y lo ajeno
// pide `can_view_auditoria`.
Route::get('auditoria/alumno/{id}', [AuditoriaController::class, 'getAlumno'])->middleware('auth.personal');
Route::get('auditoria/entidad/{tipo}/{id}', [AuditoriaController::class, 'getEntidad'])->middleware('auth.personal');
Route::get('auditoria/ingresos', [AuditoriaController::class, 'getIngresos'])->middleware('auth.personal');
Route::get('auditoria/ingresos/{id}', [AuditoriaController::class, 'getIngreso'])->middleware('auth.personal');

// HistorialesController
Route::put('historiales/de-usuario', [HistorialesController::class, 'putDeUsuario'])->middleware('auth.personal');
Route::put('historiales/nota-detalle', [HistorialesController::class, 'putNotaDetalle'])->middleware('auth.personal');
Route::put('historiales/nota-final-detalle', [HistorialesController::class, 'putNotaFinalDetalle'])->middleware('auth.personal');
Route::put('historiales/sesion', [HistorialesController::class, 'putSesion'])->middleware('auth.personal');

// InformesController
Route::put('informes/cumpleanos-por-meses', [InformesController::class, 'putCumpleanosPorMeses'])->middleware('auth.personal');
Route::put('informes/datos', [InformesController::class, 'putDatos'])->middleware('auth.personal');

// MembreteController
//
// Lo que hace falta para encabezar y firmar un papel: `Year::datos()` y nada más.
// Existe porque esos datos —el rector, su cédula, su firma, la ciudad, la resolución—
// **sólo viven ahí** y `Year::datos()` no tenía ruta propia, así que la constancia de
// estudio se los pedía a `GET piars-config`, que es la configuración de la ruta de
// inclusión. Funcionaba; el nombre mentía, y estrechar aquel permiso rompería una
// constancia. `auth.personal`, como sus vecinas de esta familia.
Route::get('informes/membrete', [MembreteController::class, 'getIndex'])->middleware('auth.personal');

// NivelacionesController
//
// La sección A del acta de nivelación: las filas de `notas` niveladas de un grupo y un
// periodo, de una vez. **Las cinco rutas que tocan nivelar son de ESCRITURA sobre una
// fila**, así que «¿quién presentó nivelación en el grupo X?» no la contestaba ninguna:
// había que barrer asignatura por asignatura con `PUT notas/detailed` —la consulta más
// pesada del proyecto— y en serie, porque paralelizarla tumba el backend del colegio.
//
// `auth.personal` **y nada dentro**, dicho a propósito: son exactamente las filas que
// `notas/detailed` ya sirve con ese mismo guard, sólo que en un viaje en vez de doce.
// Estrechar aquí y no allí sería un cartel y no un candado.
Route::put('informes/nivelaciones-del-grupo', [NivelacionesController::class, 'putDelGrupo'])->middleware('auth.personal');

// FormulariosInscripcionController
//
// El formulario de inscripción que se imprime, en sus dos modos, con el código que
// ata el papel al alumno. Autorizado por Joseth el 19 sep 2026 con el precio
// delante; decisiones en `docs/migracion/41-el-formulario-de-inscripcion.md`.
//
// SON DOS Y NO UNA, y la segunda no es comodidad: **sin el `GET`, recargar la
// pantalla vuelve a acuñar códigos** y una impresora atascada cuesta diez. El
// `POST` escribe —acuñar es escribir— y el `GET` relee un lote ya acuñado sin
// tocar nada. Lo pidió el front y era el defecto más grave del contrato.
//
// Las dos con `auth.personal` y **nada dentro**: imprimir un formulario en blanco
// no es más delicado que listar un grupo, y la familia que lo llena no tiene cuenta
// en MYVC. La que sí necesitará permiso propio es la de aprobar la colilla del
// pago, que va aparte y todavía no está escrita.
Route::post('informes/formularios-inscripcion', [FormulariosInscripcionController::class, 'postAcunar'])->middleware('auth.personal');
// **`campos` VA ANTES QUE `{lote}` Y ESO NO ES ESTILO.** Laravel casa por orden de
// registro, así que con `{lote}` delante una petición a `…/campos` entraría por el
// lote llamado «campos» y contestaría 404 — la pantalla de configuración no
// funcionaría y el error no diría por qué. Lo fija `FormulariosInscripcionTest`.
Route::get('informes/formularios-inscripcion/campos', [FormulariosInscripcionController::class, 'getCampos'])->middleware('auth.personal');
Route::put('informes/formularios-inscripcion/campos', [FormulariosInscripcionController::class, 'putCampos'])->middleware('auth.personal');
// DEL PAPEL AL ALUMNO — las cuatro que cierran el ciclo. Autorizadas por Joseth el
// 20 sep 2026 con el alcance delante; decisiones en el 41 §9.
//
// **`campana` y `codigo/…` VAN ANTES QUE `{lote}`, por lo mismo que `campos`**: con
// el comodín delante, `…/campana` entraría por el lote llamado «campana» y
// contestaría 404 sin decir por qué. `codigo/{codigo}` son dos segmentos y no
// chocaría, pero se registra aquí para que las cuatro se lean juntas. Lo fija un
// test, porque es un fallo que vive en una línea invisible.
//
// **LAS DOS ESCRITURAS LLEVAN CRITERIO DENTRO Y LAS DOS LECTURAS NO, y la
// diferencia va escrita porque es una decisión**: `auth.personal` deja pasar a las
// 74 cuentas de personal, de las que 53 son docentes. Mirar un papel que se tiene en
// la mano es lo que hay que poder hacer en la estación de documentos; **atarlo a un
// alumno decide de quién es un cobro**, y corregir su código cambia lo que lleva
// impreso un papel que está en casa de una familia. Ésas dos son
// `Autoriza::puedeAtarFormularios` dentro del método — la forma de la bandeja del
// tesorero y la de `can_edit_plantilla_notas`, no la de las otras cuatro de esta
// familia.
Route::get('informes/formularios-inscripcion/campana', [FormulariosInscripcionController::class, 'getCampana'])->middleware('auth.personal');
Route::get('informes/formularios-inscripcion/codigo/{codigo}', [FormulariosInscripcionController::class, 'getPorCodigo'])->middleware('auth.personal');
Route::put('informes/formularios-inscripcion/codigo/{codigo}', [FormulariosInscripcionController::class, 'putCodigo'])->middleware('auth.personal');
Route::put('informes/formularios-inscripcion/codigo/{codigo}/alumno', [FormulariosInscripcionController::class, 'putAlumno'])->middleware('auth.personal');
Route::get('informes/formularios-inscripcion/{lote}', [FormulariosInscripcionController::class, 'getLote'])->middleware('auth.personal');

// ColillasInscripcionController
//
// El comprobante del pago del formulario, y su aprobación. Cuatro rutas
// autorizadas por Joseth el 19 sep 2026; decisiones en el documento 41.
//
// LA PRIMERA ES PÚBLICA, Y ES LA ÚNICA DE ESTA API QUE RECIBE UN FICHERO SIN
// TOKEN. Quien sube es la familia de un aspirante que todavía no es alumno: no
// tiene cuenta y no puede tenerla. Lo que la protege son el carácter de control
// del código —que se comprueba antes de tocar la base—, el limitador `colilla`
// por IP y por código, la lista blanca de tipos por extensión Y por contenido, y
// sobre todo el tope de TRES comprobantes por orden y UNA sola pendiente, que es
// lo único que no se reinicia con el reloj.
//
// Las otras tres exigen `auth.personal` en la ruta y **el tesorero dentro**:
// `Autoriza::puedeResolverColillas`, que es el tesorero del año y, si no hay,
// secretaría. El respaldo no es adorno — `years.tesorero_id` está en NULL en los
// cuatro años del docker, así que sin él no podría aprobar nadie.
Route::post('colillas-inscripcion/{codigo}', [ColillasInscripcionController::class, 'postSubir'])
    ->withoutMiddleware('auth.token')->middleware('throttle:colilla');
Route::get('colillas-inscripcion/pendientes', [ColillasInscripcionController::class, 'getPendientes'])->middleware('auth.personal');

// **Y VA DESPUÉS DE `pendientes`, QUE NO ES ESTILO: ESTO SE VIO ROTO.** `{codigo}` es
// un comodín, y registrado delante se traga `…/colillas-inscripcion/pendientes` — la
// BANDEJA DEL TESORERO, que pasaría a contestar 422 «ese código no es válido».
//
// Medido, no supuesto: con el orden al revés, `getRoutes()->match()` sobre
// `/api/colillas-inscripcion/pendientes` devolvía **`getEstado`**. Y `route:list` NO
// lo enseña, porque ordena alfabéticamente y no por orden de registro — o sea que la
// forma natural de comprobarlo miente. Lo fija un test.
//
// Es la MISMA trampa que `…/campos` antes que `…/{lote}`, cometida otra vez en la
// familia de al lado y el día siguiente. Un aviso escrito no protege solo.
// Y LA SEGUNDA PÚBLICA DE ESTA FAMILIA, autorizada por Joseth el 20 sep 2026: la
// familia pregunta cómo va lo suyo. Es la DECIMOSEXTA pública y **la primera de
// LECTURA de todo este módulo** — hasta hoy las tres públicas eran las tres de
// escritura, así que la familia mandaba su comprobante y no tenía forma de saber si
// se lo aprobaron, se lo rechazaron ni por qué.
//
// NO ESPERA AL CORREO, y ése es el punto: el aviso que debía cerrar esto va por
// correo, y el correo de esta API está en rojo desde el 2 sep (`lalvirtual.com` no
// está registrado, y falla callado). Con una lectura, la familia entra con el código
// que ya lleva impreso y lo ve — es *pull* en vez de *push*.
//
// Y NO ES «EL MEJOR CANAL PARA QUIEN NO TIENE CUENTA»: ES EL ÚNICO QUE EXISTE.
// Aquí se citaba el 9,2 % de acudientes con correo (doc 42), y esa cifra está mal
// dos veces: cuenta `acudientes.email` cuando todo lo que manda correo busca por
// `users.email` —eran 0, y `8myvc-9a` los dejó en 91 el 20 sep— y **sobre todo
// cuenta acudientes de alumnos YA MATRICULADOS**. Quien paga un formulario es la
// familia de un ASPIRANTE, que no tiene fila en `users` ni en `acudientes`. Medido:
// este flujo no le pide el correo en ningún momento y ninguna de sus tres tablas
// tiene esa columna. Para él, el correo no es un canal malo: no es un canal.
//
// LO QUE DEVUELVE LO DECIDE QUE SEA PÚBLICA, no que le sirva a la familia: el
// código se dicta por teléfono y viaja en un papel que pasa de mano en mano, así
// que **no sale el nombre del alumno, ni su documento, ni sus teléfonos, ni el
// fichero del recibo** —la URL es la llave—. Sale el trámite, no la persona.
//
// MISMA URI QUE EL POST, PERO **LIMITADOR PROPIO**, Y ESO SE VIO ROTO. Decía «mismo
// limitador: quien sube ahí es quien pregunta ahí», que era verdad y era justo lo que
// lo escondía: el problema no es QUIÉN, es que **preguntar consumía subidas**.
//
// La clave de un limitador con nombre es `md5($limiterName.$limit->key)` —
// `ThrottleRequests::handleRequestUsingNamedLimiter`, comprobado en el fuente—, así
// que **no lleva el verbo ni la ruta**: con el mismo `throttle:colilla` y los mismos
// `by()`, el GET y el POST eran **un solo cubo de diez por hora**. Y el reparto salía
// al revés de lo que conviene: preguntar es lo barato que se repite, subir es lo caro
// y raro. Medido: a la **undécima consulta**, la consulta misma daba 429.
//
// Lo encontró `8myvc-dd` revisando esta ruta el 20 sep 2026, unas horas después de
// fundirla. Lo fija `LaFamiliaPreguntaTest`, visto en rojo.
Route::get('colillas-inscripcion/{codigo}', [ColillasInscripcionController::class, 'getEstado'])
    ->withoutMiddleware('auth.token')->middleware('throttle:consulta-inscripcion');
Route::put('colillas-inscripcion/{id}/aprobar', [ColillasInscripcionController::class, 'putAprobar'])->middleware('auth.personal');
Route::put('colillas-inscripcion/{id}/rechazar', [ColillasInscripcionController::class, 'putRechazar'])->middleware('auth.personal');

// PagosInscripcionController
//
// El pago EN LÍNEA del formulario, que es el camino alternativo a la colilla. Las
// dos rutas que faltaban de las diez que autorizó Joseth el 19 sep 2026;
// decisiones en el documento 41 §7 y en el 40.
//
// LAS DOS SON PÚBLICAS, Y SUBEN LA DOCENA A QUINCE. La primera por el mismo
// motivo que la colilla —quien paga es la familia de un aspirante, que no tiene
// cuenta y no puede tenerla—; la segunda **no la llama una persona**: la llama la
// pasarela desde su propio servidor, así que no hay ninguna sesión que exigir.
//
// SON DOS Y NO UNA, y no es simetría: el checkout **no cobra**, sólo firma lo que
// el navegador le va a enseñar a la pasarela, y quien se entera de que el dinero
// llegó es el webhook. Con sólo la primera, una familia paga de verdad y en MYVC
// no consta nada — que es peor que no tener pagos en línea.
//
// LO QUE PROTEGE AL WEBHOOK NO ES UN MIDDLEWARE, y el orden importa: primero se
// busca la referencia en `pagos_inscripcion` —una consulta indexada que descarta
// lo que no es nuestro sin salir a internet—, después se comprueba la firma del
// evento (obligatoria) y sólo entonces se reconsulta la transacción con la llave
// privada, si el colegio la dio. La corrección del doc 40 §4 —que decía «llave
// pública» y va con la privada— está medida en la cabecera del controlador.
//
// Y las dos contestan 404 en el colegio que no tiene credenciales, porque **el
// back no publica lo que está apagado** (doc 40 §5, regla 1).
Route::post('pagos-inscripcion/{codigo}/checkout', [PagosInscripcionController::class, 'postCheckout'])
    ->withoutMiddleware('auth.token')->middleware('throttle:checkout-inscripcion');
Route::post('pagos-inscripcion/webhook', [PagosInscripcionController::class, 'postWebhook'])
    ->withoutMiddleware('auth.token')->middleware('throttle:webhook-inscripcion');

// CertificadosPersonaController
//
// Devuelve las matrículas de un alumno. Pide el alumno con `alumno_id` suelto en
// vez de `requested_alumnos`; el middleware entiende las dos formas.
Route::put('certificados-persona', [CertificadosPersonaController::class, 'putIndex'])->middleware('boletin.propio');

// BolfinalesPreescolarController
Route::put('bolfinales-preescolar/crear-frase', [BolfinalesPreescolarController::class, 'putCrearFrase'])->middleware('auth.personal');
Route::put('bolfinales-preescolar/eliminar-frase', [BolfinalesPreescolarController::class, 'putEliminarFrase'])->middleware('auth.personal');
Route::put('bolfinales-preescolar/guardar-frase', [BolfinalesPreescolarController::class, 'putGuardarFrase'])->middleware('auth.personal');
Route::put('bolfinales-preescolar/detailed-notas-year-group/{grupo_id}', [BolfinalesPreescolarController::class, 'putDetailedNotasYearGroup'])->middleware('boletin.propio');
Route::put('bolfinales-preescolar/detailed-notas-year/{grupo_id}', [BolfinalesPreescolarController::class, 'putDetailedNotasYear'])->middleware('boletin.propio');

// PuestosController
Route::put('puestos/detailed-notas-year', [PuestosController::class, 'putDetailedNotasYear'])->middleware('auth.personal');
Route::put('puestos/detailed-notas-periodo/{grupo_id}', [PuestosController::class, 'putDetailedNotasPeriodo'])->middleware('auth.personal');

// NotasPerdidasController
Route::put('notas-perdidas/profesor-grupos', [NotasPerdidasController::class, 'putProfesorGrupos'])->middleware('auth.personal');
Route::put('notas-perdidas/todos', [NotasPerdidasController::class, 'putTodos'])->middleware('auth.personal');
Route::get('notas-perdidas/show-profesor/{profesor_id}', [NotasPerdidasController::class, 'getShowProfesor'])->middleware('auth.personal');

// BoletinesController
//
// Los tres controladores de boletines son copias con distinta maqueta y sirven
// el mismo dato. `boletin.propio` impide que un alumno pida el de otro y que un
// acudiente pida el de quien no es su acudido. Estaba escrito en el constructor
// del primero y no se ejecutaba nunca; los otros dos ni lo tenían. Ver
// App\Http\Middleware\ExigirBoletinPropio.
Route::put('boletines/detailed-notas-group/{grupo_id}', [BoletinesController::class, 'putDetailedNotasGroup'])->middleware('boletin.propio');
Route::get('boletines/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [BoletinesController::class, 'getDetailedNotasYear'])->middleware('boletin.propio');
Route::put('boletines/detailed-notas/{grupo_id}', [BoletinesController::class, 'putDetailedNotas'])->middleware('boletin.propio');

// Boletines2Controller
Route::delete('boletines2/destroy/{id}', [Boletines2Controller::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('boletines2/detailed-notas-group/{grupo_id}', [Boletines2Controller::class, 'putDetailedNotasGroup'])->middleware('boletin.propio');
Route::get('boletines2/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [Boletines2Controller::class, 'getDetailedNotasYear'])->middleware('boletin.propio');
Route::put('boletines2/detailed-notas/{grupo_id}', [Boletines2Controller::class, 'putDetailedNotas'])->middleware('boletin.propio');

// Boletines3Controller
Route::delete('boletines3/destroy/{id}', [Boletines3Controller::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('boletines3/detailed-notas-group/{grupo_id}', [Boletines3Controller::class, 'putDetailedNotasGroup'])->middleware('boletin.propio');
Route::get('boletines3/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [Boletines3Controller::class, 'getDetailedNotasYear'])->middleware('boletin.propio');
Route::put('boletines3/detailed-notas/{grupo_id}', [Boletines3Controller::class, 'putDetailedNotas'])->middleware('boletin.propio');

// BoletinPorCompetenciasController
//
// **El boletín por competencias** — Fase 6 del doc 35, con D16 y D17. La
// competencia arriba, sus desempeños debajo y los sueltos al final.
//
// ## Son DOS y el plan decía cuatro, y las otras dos no se pueden calcar
//
// El doc 35 pedía «4 rutas, calcadas de `boletines3`». Medido:
//
//  - **`boletines3/destroy/{id}` no borra un boletín: manda un ALUMNO a la
//    papelera.** Lo dice su propio comentario (05 §89) y lo fija
//    `BoletinesBorranAlumnosTest` con las cuatro puertas en el mismo caso.
//    Calcarla aquí sería una **quinta puerta** a la papelera, escondida en la
//    familia de informes. No se calca.
//  - **`…/detailed-notas-year` es byte a byte la misma en los tres** —sus tres
//    instantáneas comparten md5, `054346c7…`— y **ningún cliente la llama**:
//    `app2/src/app/datos/boletines.ts` declara exactamente dos métodos,
//    `deAlumnos` y `deGrupo`. Una cuarta copia idéntica nace muerta.
//
// Así que la variante 6 del front son **dos rutas**, que son justo las dos que
// `BoletinesApi` sabe llamar: un cuarto valor en su `RECURSOS` y nada más.
//
// `boletin.propio` en las dos, como en las once de la familia: impide que un
// alumno pida el de otro y que un acudiente pida el de quien no es su acudido.
// Ver App\Http\Middleware\ExigirBoletinPropio.
Route::put('boletines-competencias/detailed-notas/{grupo_id}', [BoletinPorCompetenciasController::class, 'putDetailedNotas'])->middleware('boletin.propio');
Route::put('boletines-competencias/detailed-notas-group/{grupo_id}', [BoletinPorCompetenciasController::class, 'putDetailedNotasGroup'])->middleware('boletin.propio');

// SimatController
Route::get('simat', [SimatController::class, 'getIndex'])->middleware('auth.personal');
Route::get('simat/alumnos', [SimatController::class, 'getAlumnos'])->middleware('auth.personal');
Route::get('simat/alumnos-exportar', [SimatController::class, 'getAlumnosExportar'])->middleware('auth.personal');

// AcudientesExportController
Route::get('acudientes-export/acudientes', [AcudientesExportController::class, 'getAcudientes'])->middleware('auth.personal');

// ExcelListadoDocentesController
Route::get('excel-docentes', [ExcelListadoDocentesController::class, 'getIndex'])->middleware('auth.personal');
Route::get('excel-docentes/docentes/{year}/{year_id}', [ExcelListadoDocentesController::class, 'getDocentes'])->middleware('auth.personal');

// ObservadorController
Route::get('observador', [ObservadorController::class, 'getIndex'])->middleware('auth.personal');
Route::get('observador/vertical-todos', [ObservadorController::class, 'getVerticalTodos'])->middleware('auth.personal');
Route::get('observador/vertical/{grupo_id}/{tamanio}', [ObservadorController::class, 'getVertical'])->middleware('auth.personal');

// ObservadorHorizontalController
Route::put('observador-horizontal/horizontal/{grupo_id}', [ObservadorHorizontalController::class, 'putHorizontal'])->middleware('auth.personal');

// ActasEvaluacionController
Route::put('actas-evaluacion/acta-evaluacion-promocion', [ActasEvaluacionController::class, 'putActaEvaluacionPromocion'])->middleware('auth.personal');
Route::put('actas-evaluacion/detalle', [ActasEvaluacionController::class, 'putDetalle'])->middleware('auth.personal');
// La pantalla del acta llamaba a esta ruta desde siempre y no existía: guardar el texto del
// acta fallaba con 404 en silencio. Ahora guarda el texto y los firmantes de la comisión.
// Con auth.personal porque es la única escritura de este módulo: el resto de actas-evaluacion
// son lecturas, y el texto del acta es configuración del año lectivo.
Route::put('actas-evaluacion/cambiar-descripcion', [ActasEvaluacionController::class, 'putGuardarTextoActa'])->middleware('auth.personal');

// CertificadosEstudioController
Route::get('certificados-estudio/certificado-alumno/{grupo_id}', [CertificadosEstudioController::class, 'getCertificadoAlumno'])->middleware('auth.personal');
Route::get('certificados-estudio/certificado-grupo/{grupo_id}', [CertificadosEstudioController::class, 'getCertificadoGrupo'])->middleware('auth.personal');

// InformesRecientesController
//
// **Familia nueva (18 sep 2026)**, autorizada por Joseth con el precio delante.
// Guardan lo que cada quien sacó hace poco, para el «repetir esto» de la pantalla
// nueva de `/informes` en `app2`.
//
// `auth.personal` en las tres, y la razón no es proteger la fila —`user_id` y
// `year_id` salen de la sesión, así que nadie puede pedir la lista de otro—: es que
// el catálogo de informes es una pantalla del personal, y el guard baja la
// superficie de 2.358 cuentas a 74 sin quitárselo a nadie que tenga uso para ella.
// Consecuencia, no causa: con tres rutas guardadas la familia no entra en
// `familias-que-nunca-entran-en-el-candado.json`.
Route::get('informes-recientes', [InformesRecientesController::class, 'getIndex'])->middleware('auth.personal');
Route::post('informes-recientes', [InformesRecientesController::class, 'postStore'])->middleware('auth.personal');
Route::delete('informes-recientes', [InformesRecientesController::class, 'deleteIndex'])->middleware('auth.personal');
