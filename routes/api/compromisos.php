<?php

use App\Http\Controllers\CompromisosConfigController;
use App\Http\Controllers\CompromisosController;
use App\Http\Controllers\CompromisosDeLaFamiliaController;
use App\Http\Controllers\CompromisosDelDocenteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La plantilla del compromiso académico
|--------------------------------------------------------------------------
|
| Diseño y porqués: `myvc_front/COMPROMISOS-ACADEMICOS.md` §8. Las columnas y por
| qué son dos tablas, en el docblock de
| `2026_09_22_100000_la_plantilla_del_compromiso`.
|
| ## POR QUÉ ARCHIVO PROPIO Y NO UN RENGLÓN EN `academico.php`
|
| Porque esto es el primer trozo de un módulo que todavía no existe entero. Lo que
| falta —crear el compromiso de un alumno, firmarlo, entregar el resultado— son
| escrituras sobre `compromisos`, con permisos que no son éstos y con un `{id}` por
| medio. Nacen aquí, y con el archivo ya abierto la decisión de dónde ponerlas no
| se toma dos veces.
|
| ## TRES RUTAS, TODAS CON `auth.personal`, Y EL PERMISO FINO VA DENTRO
|
| `auth.personal` cierra la puerta a alumnos y acudientes **antes de tocar el
| controlador**, que es lo que tiene que hacer un middleware. Lo que no puede hacer
| es distinguir entre las 74 cuentas que deja pasar, de las que **53 son docentes**,
| y aquí las dos escrituras no son de ellos: `regla` y `corte` deciden a qué alumnos
| les llega un compromiso, y los bloques son lo que sale impreso en un papel que
| firman el acudiente y el estudiante.
|
| Así que el criterio va **dentro de los dos `PUT`**, con el mismo conjunto que
| `years/toggle-mostrar-nota-numerica` —superusuario, Secretario, Coordinación
| Académica y Rector—, y el porqué entero está en el docblock de `putConfig`. Es la
| misma forma que `aspirantes/{id}/decision` y que `can_edit_plantilla_notas`: guard
| de familia en la ruta, decisión con nombre propio en el método.
|
| **El `GET` no lleva permiso dentro, y va escrito para que no se lea como un
| olvido.** Leer la plantilla no decide nada, y el docente que va a entregar el papel
| a una familia tiene que poder ver qué dice antes de firmarlo. Es la misma decisión
| —con las mismas palabras— que `informes/formularios-inscripcion/codigo/{codigo}`.
|
| ## EL ORDEN, QUE AQUÍ TODAVÍA NO MUERDE Y MAÑANA SÍ
|
| Los dos tramos son literales, así que hoy no hay comodín que pueda tragarse a
| nadie y no hace falta ningún `->where()`: no entra ni un parámetro numérico. Se
| escriben igualmente **literales primero**, porque el día que llegue
| `compromisos/{id}` —y va a llegar— Laravel sirve la primera que casa y
| `compromisos/config` pasaría a ser el compromiso número «config». Es literalmente
| lo que le pasó a `…/campos` con `…/{lote}` y a `…/pendientes` con `…/{codigo}`, dos
| veces en dos días y las dos con la bandeja de alguien tragada por un comodín.
|
| ## LO QUE MUEVE
|
| Familia nueva `compromisos/` con **3 de 3** guards declarados, así que entra en
| verde en `guards-por-ruta.json`. Y mueve `Snapshots/rutas.json`: son tres renglones
| nuevos y ninguno retirado.
*/

Route::get('compromisos/config', [CompromisosConfigController::class, 'getConfig'])
    ->middleware('auth.personal');

Route::put('compromisos/config', [CompromisosConfigController::class, 'putConfig'])
    ->middleware('auth.personal');

// Los bloques se guardan aparte de la configuración **porque son otra forma**: una
// lista ordenada de textos largos contra una fila de columnas. Y porque mover un
// bloque no debería obligar a remandar el corte, la regla y los firmantes enteros —
// que es la forma exacta de pisar lo que otro acababa de cambiar en la otra pestaña.
Route::put('compromisos/bloques', [CompromisosConfigController::class, 'putBloques'])
    ->middleware('auth.personal');

/*
|--------------------------------------------------------------------------
| El compromiso de un alumno
|--------------------------------------------------------------------------
|
| Lo de arriba es lo que el colegio escribe **una vez al año**. Esto es el papel de
| cada alumno: quién lo propone, quién lo firma, quién dictamina y quién se entera.
| Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §3.3 y §5; el contrato que manda
| es `myvc_front/app2/src/app/datos/compromisos.ts`.
|
| ## YA LLEGÓ EL COMODÍN QUE LA CABECERA DE ARRIBA ANUNCIABA
|
| `compromisos/{id}` existe desde este renglón, así que el orden **ya muerde**:
| `compromisos/candidatos` y `compromisos/mios` van declaradas ANTES, porque Laravel
| sirve la primera ruta que casa y si no, el compromiso número «candidatos» se come
| la pantalla del coordinador y el número «mios» la del docente. Es literalmente lo
| que le pasó a `…/campos` con `…/{lote}` y a `…/pendientes` con `…/{codigo}`.
|
| Y todos los `{id}` llevan `->where(…, '[0-9]+')`. Con el comodín acotado a dígitos
| el orden deja de ser lo único que sujeta esto: aunque alguien reordene el fichero
| mañana, `compromisos/candidatos` no puede casar con una ruta que exige números.
| Son las dos mitades de la misma defensa y se ponen las dos.
|
| ## TRES PUERTAS Y NO UNA, y la diferencia es de quién es el interés
|
|   `auth.personal`   el personal del colegio: coordinación, titular y docentes.
|                     El permiso fino va DENTRO del controlador, como en los dos
|                     `PUT` de la plantilla — `auth.personal` deja pasar a las 74
|                     cuentas de personal y **53 son docentes**, que no crean ni
|                     cierran compromisos.
|
|   `persona.propia`  la lectura de la FAMILIA, `de-alumno`. Sin `auth.personal`,
|                     que es el que cierra la puerta a alumnos y acudientes: aquí
|                     son ellos los que entran. El patrón ya existe y es
|                     `disciplina/mis-fichas` (`routes/api/disciplina.php:109`), la
|                     única de disciplina sin él.
|
|   sin guard         las dos FIRMAS, `acuse` y `acuse-resultado`. Y no es un
|                     olvido: está decidido y escrito abajo, encima de las dos.
|
| `ExigirPersonaPropia` resuelve el `{alumno_id?}` de `de-alumno` —recoge **todos**
| los identificadores que vengan, por URL o por cuerpo—. Lo que no puede resolver es
| el `{id}` de `acuse` y `acuse-resultado`, que es un compromiso y no una persona,
| **y por eso esas dos no lo llevan**: puesto ahí no reconoce nada y deja pasar la
| petición entera, aparentando una protección que no existe. La propiedad la
| comprueba el controlador, que es quien sabe que ese papel es del hijo de quien
| firma. El porqué entero, encima de las dos rutas.
|
| ## LOS DOS CONTROLADORES QUE NO SON ÉSTE
|
| `CompromisosDelDocenteController` y `CompromisosDeLaFamiliaController`. Las rutas
| se declaran aquí y no en tres ficheros porque **el orden de `{id}` es global a la
| familia**: repartirlas dejaría el desempate entre `compromisos/mios` y
| `compromisos/{id}` en manos del orden en que `routes/api.php` incluya los ficheros,
| que es exactamente la clase de dependencia que no se ve hasta que falla.
*/

/* ── el coordinador ─────────────────────────────────────────────────────────── */

// Literal, y va la PRIMERA de todas: es la que un `compromisos/{id}` se tragaría.
// `PUT` y no `GET` porque lleva cuerpo —regla, corte, grupo— y es el patrón de la
// casa para las consultas con filtros. **No escribe una sola fila.**
Route::put('compromisos/candidatos', [CompromisosController::class, 'putCandidatos'])
    ->middleware('auth.personal');

// Literal también, y del otro controlador: la pantalla del docente. Mismo motivo
// para ponerla arriba.
Route::get('compromisos/mios', [CompromisosDelDocenteController::class, 'getMios'])
    ->middleware('auth.personal');

// La familia, con el `{alumno_id?}` opcional: sin él, el alumno de la sesión; con
// él, ese hijo, y el guard comprueba que sea suyo. Va antes que `compromisos/{id}`
// porque el prefijo `de-alumno` es literal y tiene que ganar.
Route::get('compromisos/de-alumno/{alumno_id?}', [CompromisosDeLaFamiliaController::class, 'getDeAlumno'])
    ->where('alumno_id', '[0-9]+')
    ->middleware('persona.propia');

// El veredicto es del ITEM y no del compromiso (D3): `compromiso_items.id`. Lleva
// `items/` por delante para que no se confunda nunca con un id de cabecera.
Route::put('compromisos/items/{id}/veredicto', [CompromisosDelDocenteController::class, 'putVeredicto'])
    ->where('id', '[0-9]+')
    ->middleware('auth.personal');

Route::post('compromisos', [CompromisosController::class, 'postStore'])
    ->middleware('auth.personal');

Route::get('compromisos', [CompromisosController::class, 'getIndex'])
    ->middleware('auth.personal');

/* ── y a partir de aquí, el comodín ─────────────────────────────────────────── */

Route::get('compromisos/{id}', [CompromisosController::class, 'getShow'])
    ->where('id', '[0-9]+')
    ->middleware('auth.personal');

// R2: se avisó, y consta el día, la hora y el canal. Escribe `entregado_at`, que es
// inmutable — y escribirla ES disparar el push, porque `EnviarNotificaciones`
// avanza por ese sello.
Route::put('compromisos/{id}/entregar', [CompromisosController::class, 'putEntregar'])
    ->where('id', '[0-9]+')
    ->middleware('auth.personal');

Route::put('compromisos/{id}/cerrar', [CompromisosController::class, 'putCerrar'])
    ->where('id', '[0-9]+')
    ->middleware('auth.personal');

// R4: el resultado vuelve y arranca el plazo de reclamación. Es el paso que ningún
// formato del país sabe hacer hoy (§1.5).
Route::put('compromisos/{id}/entregar-resultado', [CompromisosController::class, 'putEntregarResultado'])
    ->where('id', '[0-9]+')
    ->middleware('auth.personal');

/*
|--------------------------------------------------------------------------
| La familia: las dos firmas
|--------------------------------------------------------------------------
|
| ## ESTAS DOS NO LLEVAN `persona.propia`, Y QUITARLO FUE UN ARREGLO
|
| *Decisión de Joseth, 23 sep 2026.* Nacieron con él y **no protegía nada**:
| `ExigirPersonaPropia` recoge los identificadores **por su nombre** de una lista
| de claves de persona —`alumno_id`, `user_id`, `persona_id`, `acudiente_id`,
| `profesor_id`, `matricula_id`, `imagen_id`…— y el `{id}` de estas dos rutas es
| un **compromiso**, no una persona. Sin ningún identificador reconocido, el guard
| entiende «lo mío» y **deja pasar la petición entera**.
|
| Es la forma exacta del IDOR de `DELETE images-users/destroy/{id}`
| (05 §13.2): la única ruta de imagen que llamaba `{id}` a lo que sus cuatro
| hermanas llamaban `{imagen_id}`, con el guard puesto y un alumno borrando la
| foto de cualquiera. Lo caza `AutorizacionTest::test_el_guard_reconoce_algun_
| identificador_de_cada_ruta_que_protege`, que salió en rojo por estas dos.
|
| **Por qué se quita en vez de arreglarlo dentro del middleware.** `persona.propia:
| <clave>` sólo admite claves de PERSONA, así que declarar que este `{id}` apunta a
| una sería mentirle al guard. La otra salida —enseñarle un modo «este id no es una
| persona»— toca un middleware que comparten unas cuarenta rutas para arreglar dos,
| y el riesgo de ese cambio es mayor que el que quita.
|
| **Y dejarlo puesto era la peor de las tres**: un guard declarado que no reconoce
| nada aparenta protección, y el siguiente que lea la ruta se fía.
|
| ## QUIÉN CIERRA ESTA PUERTA ENTONCES
|
| `CompromisosDeLaFamiliaController::compromisoDelAcudiente()`, que comprueba en
| `parentescos` que el alumno del compromiso sea hijo de quien firma —403 si no—, y
| `::acudienteQueFirma()`, que exige `tipo === 'Acudiente'` con ficha. **La
| comprobación siempre estuvo ahí; lo que faltaba era que se viera que es la única.**
|
| Y tiene test: `CompromisosDelAlumnoTest::test_un_acudiente_no_puede_firmar_el_
| compromiso_de_otro`, comprobado en rojo quitando ese `esAcudienteDe()` — sin él,
| un acudiente firma el compromiso de otro niño cambiando un número en la URL, y
| firma de verdad, porque `acuse_at` es una fecha con valor probatorio que no se
| reescribe.
|
| Las dos están declaradas en `AutorizacionTest::EXCEPCIONES_DE_FAMILIA` con este
| mismo motivo, que es lo que impide que la excepción se quede sin explicar.
*/

// PRIMERA firma: «conozco el compromiso». Ley 527 de 1999 (§4.3).
Route::put('compromisos/{id}/acuse', [CompromisosDeLaFamiliaController::class, 'putAcuse'])
    ->where('id', '[0-9]+');

// SEGUNDA firma: «conozco el resultado», o «no estoy de acuerdo» con su texto. No
// es un visto: tiene el mismo valor probatorio que la primera y es la que hace que
// el papel salga con dos firmas y dos fechas (R4).
Route::put('compromisos/{id}/acuse-resultado', [CompromisosDeLaFamiliaController::class, 'putAcuseResultado'])
    ->where('id', '[0-9]+');
