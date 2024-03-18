<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth:api')->get('/user', function (Request $request) {
//     return $request->user();
// });

// Route::get('/usuario', function () {
//     return view('welcome');
// });

AdvancedRoute::controller('login', 'LoginController');

AdvancedRoute::controller('alumnos', 'AlumnosController');
AdvancedRoute::controller('importar', 'Alumnos\ImportarController');
AdvancedRoute::controller('folios', 'Alumnos\FoliosController');
AdvancedRoute::controller('acudientes', 'AcudientesController');
AdvancedRoute::controller('buscar', 'BuscarController');
AdvancedRoute::controller('paises', 'PaisesController');
AdvancedRoute::controller('ciudades', 'CiudadesController');
Route::resource('tiposdocumento', 'TipoDocumentoController');
AdvancedRoute::controller('areas', 'AreasController');
AdvancedRoute::controller('materias', 'MateriasController');
AdvancedRoute::controller('asignaturas', 'AsignaturasController');
AdvancedRoute::controller('unidades', 'UnidadesController');
AdvancedRoute::controller('subunidades', 'SubunidadesController');
AdvancedRoute::controller('users', 'UsersController');
AdvancedRoute::controller('cambiar-usuarios', 'CambiarUsuarios\CambiarUsuariosController');
AdvancedRoute::controller('notas', 'NotasController');
Route::resource('estados_civiles', 'EstadosCivilesController');
AdvancedRoute::controller('niveles_educativos', 'NivelesEducativosController');
AdvancedRoute::controller('grados', 'GradosController');
AdvancedRoute::controller('grupos', 'GruposController');
AdvancedRoute::controller('matriculas', 'Matriculas\MatriculasController');
AdvancedRoute::controller('enfermeria', 'Matriculas\EnfermeriaController');
AdvancedRoute::controller('prematriculas', 'Matriculas\PrematriculasController');
AdvancedRoute::controller('requisitos', 'Matriculas\RequisitosController');
AdvancedRoute::controller('cartera', 'CarteraController');
AdvancedRoute::controller('detalles', 'DetallesController');
AdvancedRoute::controller('profesores', 'ProfesoresController');
AdvancedRoute::controller('contratos', 'ContratosController');
AdvancedRoute::controller('nota_comportamiento', 'NotaComportamientoController');
AdvancedRoute::controller('definitivas_periodos', 'DefinitivasPeriodosController');
AdvancedRoute::controller('definiciones_comportamiento', 'DefinicionesComportamientoController');
AdvancedRoute::controller('comportamiento', 'Disciplina\ComportamientoController');
AdvancedRoute::controller('frases', 'FrasesController');
AdvancedRoute::controller('ChangesAsked', 'ChangeAskedController');
AdvancedRoute::controller('ChangesAskedAssignment', 'ChangeAskedAssignmentController');
AdvancedRoute::controller('ausencias', 'AusenciasController');
AdvancedRoute::controller('uniformes', 'UniformesController');
AdvancedRoute::controller('parentescos', 'ParentescosController');
AdvancedRoute::controller('bitacoras', 'BitacorasController');
AdvancedRoute::controller('roles', 'RolesController');
AdvancedRoute::controller('permissions', 'PermissionsController');
AdvancedRoute::controller('escalas', 'EscalasDeValoracionController');
AdvancedRoute::controller('frases_asignatura', 'FrasesAsignaturaController');
AdvancedRoute::controller('eventos', 'EventosController');
AdvancedRoute::controller('years', 'YearsController');
AdvancedRoute::controller('certificados', 'ConfigCertificadosController');
AdvancedRoute::controller('periodos', 'PeriodosController');
AdvancedRoute::controller('asistencias', 'Tardanzas\AsistenciasController');
AdvancedRoute::controller('aplicacion-descargas', 'AplicacionDescargas\InicioController');

AdvancedRoute::controller('historiales', 'Historiales\HistorialesController');

# Informes
AdvancedRoute::controller('informes', 'Informes\InformesController');
AdvancedRoute::controller('bolfinales', 'Informes\BolfinalesController');
AdvancedRoute::controller('certificados-persona', 'Informes\CertificadosPersonaController');
AdvancedRoute::controller('bolfinales-preescolar', 'Informes\BolfinalesPreescolarController');
#AdvancedRoute::controller('puestos', 'Informes\PuestosAnualesController');
AdvancedRoute::controller('puestos', 'Informes\PuestosController');
AdvancedRoute::controller('planillas-ausencias', 'Informes\PlanillasAusenciasController');
AdvancedRoute::controller('notas-perdidas', 'Informes\NotasPerdidasController');
AdvancedRoute::controller('notas-actuales-alumnos', 'Informes\NotasActualesAlumnosController');
AdvancedRoute::controller('boletines', 'Informes\BoletinesController');
AdvancedRoute::controller('boletines2', 'Informes\Boletines2Controller');
AdvancedRoute::controller('boletines3', 'Informes\Boletines3Controller');
AdvancedRoute::controller('simat', 'Informes\SimatController');
AdvancedRoute::controller('acudientes-export', 'Informes\AcudientesExportController');
AdvancedRoute::controller('excel-docentes', 'Informes\ExcelListadoDocentesController');
AdvancedRoute::controller('observador', 'Informes\ObservadorController');
AdvancedRoute::controller('observador-horizontal', 'Informes\ObservadorHorizontalController');
AdvancedRoute::controller('ordinales', 'Disciplina\OrdinalesController');
AdvancedRoute::controller('disciplina', 'Disciplina\DisciplinaController');
AdvancedRoute::controller('promovidos', 'PromovidosController');

# Perfiles
AdvancedRoute::controller('perfiles', 'Perfiles\PerfilesController');
AdvancedRoute::controller('myimages', 'Perfiles\ImagesController');
AdvancedRoute::controller('images-users', 'Perfiles\ImagesUsuariosController');
AdvancedRoute::controller('/publicaciones', 'Perfiles\PublicacionesController');
AdvancedRoute::controller('calendario', 'Perfiles\CalendarioController');
AdvancedRoute::controller('editnota', 'EditnotaController');

AdvancedRoute::controller('votaciones', 'VtVotacionesController');
AdvancedRoute::controller('aspiraciones', 'VtAspiracionesController');
AdvancedRoute::controller('participantes', 'VtParticipantesController');
AdvancedRoute::controller('candidatos', 'VtCandidatosController');
AdvancedRoute::controller('votos', 'VtVotosController');

AdvancedRoute::controller('planillas', 'PlanillasController');
AdvancedRoute::controller('actas-evaluacion', 'Informes\ActasEvaluacionController');
AdvancedRoute::controller('certificados-estudio', 'CertificadosEstudioController');



AdvancedRoute::controller('password', 'RemindersController');

AdvancedRoute::controller('asistencias-app', 'AppMobile\AsistenciasAppController');

AdvancedRoute::controller('tardanzas/login', 'Tardanzas\TLoginController');
AdvancedRoute::controller('tardanzas/subir', 'Tardanzas\TSubirController');
AdvancedRoute::controller('actividades', 'Actividades\ActividadesController');
AdvancedRoute::controller('mis-actividades', 'Actividades\MisActividadesController');
AdvancedRoute::controller('preguntas', 'Actividades\PreguntasController');
AdvancedRoute::controller('opciones', 'Actividades\OpcionesController');
AdvancedRoute::controller('respuestas', 'Actividades\RespuestasController');

AdvancedRoute::controller('piars-config', 'Piars\PiarsConfigController');
AdvancedRoute::controller('piars-grupos', 'Piars\PiarsGruposController');
AdvancedRoute::controller('piars-alumnos', 'Piars\PiarsAlumnosController');
AdvancedRoute::controller('piars-asignaturas', 'Piars\PiarsAsignaturasController');
