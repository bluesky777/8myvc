# Lote E — Personas e imágenes · §97–§101

> Sesión `8myvc-4f`, noche del 22 al 23 de agosto de 2026. Rama
> `fix/lote-e-personas-imagenes`, árbol `.worktrees/e`, base
> `simonbolivar_testing_e`.
>
> La pregunta del lote era **quién ve y quién escribe la ficha de otro**. Acabó
> teniendo dos mitades, y la segunda no estaba en el enunciado: **qué le queda a
> la ficha** cuando el que sí puede escribirla manda medio formulario.

## Lo que se encontró, en una línea cada uno

| § | Qué | Estado |
|---|---|---|
| §97 | `profesores/destroy` no pedía nada, y las otras tres operaciones de la misma ficha piden superusuario | arreglado |
| §98 | `profesores/show` daba **500** con un id que no está | arreglado (404) |
| §99 | Un profesor se llevaba la **imagen privada de un alumno**, y el alumno la perdía | arreglado, sobre la operación |
| §100 | `perfiles/destroy` **borra un grupo**, y el front lo llama con un `user_id` | documentado; su autorización, cerrada |
| §101 | Cinco métodos vaciaban columnas con un cuerpo parcial. El peor: **22 y ninguna a salvo** | arreglado |
| §102 | Editar un grupo **lo movía al año del que edita**, con sus 56 matrículas dentro | arreglado |

Seis commits: `0f0e965`, `f8d1eb3`, `fc5bbf1`, `446dc8d`, `da15e0a` y el del §102.

---

## §97 — La pareja se mira junta, o da cuatro verdes

`ProfesoresController` tiene las cuatro operaciones de una ficha en el mismo
fichero. Medido con un token de profesor de los 51 de la copia de producción:

| Operación | Antes | Desde cuándo lo pedía |
|---|---|---|
| `profesores/update` | **403** | §37 |
| `profesores/restore` | **403** | §76 |
| `profesores/forcedelete` | **403** | §28.4 |
| `profesores/destroy` | **200** | — |

O sea: **no podías cambiarle el teléfono a un compañero, pero sí darlo de baja.**
Un test por ruta habría dado cuatro verdes; lo que lo encontró fue ponerlas en la
misma tabla. Es la técnica de la [§76](../05-codigo-muerto-y-roto.md), que se
inventó para las parejas `forcedelete`/`restore` y aquí sirve para una cuarteta.

Anclado a `Autoriza::esSuperusuario` y **no** a `esAdministrativo`: crear un rol
no regala permisos, y el alcance que Joseth describió del Secretario no nombra
dar de baja a un compañero. Nadie pierde un botón que hoy vea — la pantalla que
lleva la X vive en un menú que el front enseña con
`hasRoleOrPerm(['admin','secretario'])` y los diez `Admin` son exactamente los
diez `is_superuser`.

**Lo que se lleva por delante, medido**: el profesor sale del listado, pero
`grupos.titular_id` sigue apuntándole y el grupo lo sigue enseñando de titular.
No se arregla: es la familia del [§70](../05-codigo-muerto-y-roto.md) y espera
decisión.

## §98 — Un 500 que estaba a un id de distancia

`Profesor::detallado()` termina en `return $profesor[0]` sobre el resultado de un
`DB::select`. Sin filas, eso es clave indefinida y **error fatal en PHP 8**. Y no
hacía falta inventarse un id para verlo: **basta uno que esté en la papelera**,
porque la consulta filtra `deleted_at is null`.

Arreglado en los **dos llamantes de este lote** —`profesores/show` y
`planillas/show-profesor`— y **no en el modelo**, que lo comparten seis sitios:
un `?? null` allí convertiría seis 500 en seis comportamientos distintos sin
haber medido ninguno. Los otros cuatro quedan anotados con fichero y línea.

## §99 — La imagen que se le pone a otro tiene que ser propia

Medido con dos tokens del seed, **mirando el viaje de vuelta y no la respuesta**:
un profesor ponía en su perfil la imagen privada de un alumno, y después
`GET myimages` de la cuenta del alumno **ya no la traía**. No es una fuga: las
tres rutas `cambiar-*` escriben `images.user_id = <destino>` y `publica = false`.
Es un cambio de dueño.

`persona.propia` ya lo cerraba para las familias —`imagen_id` está en su lista de
claves desde la [§15](../05-codigo-muerto-y-roto.md)— pero **deja pasar al
personal antes de mirar ninguna clave**, y eso está escrito a propósito en su
cabecera: lo que el personal hace entre sí no lo decide ese guard. Lo que esa
exención no contemplaba es que **un alumno no es personal del colegio**.

**El criterio no se inventó aquí: se leyó del front.** Los tres botones mandan
`$ctrl.dato.selectedImg`, que sale de `imagenes_privadas` —las de quien pide— y
el confirm dice literalmente *«Esto quitará la imágen de tu lista»*. La operación
es **regalar la mía**, no llevarme la de otro.

**Población cerrada**: los tres métodos de `ImagesUsuariosController` que
escriben `images.user_id` — `putCambiarImagenUnUsuario` (la única de la lista del
lote), `putCambiarFotoUnUsuario` y `putCambiarFirmaUnProfe`. **Fuera a
propósito**: `putMoveImgToMe`, que es la operación contraria y deliberada —su
botón dice «Quitar de usuario y ponerla en mis imágenes»— y
`putCambiarImagenPerfil`, que escribe `users.imagen_id` sin cambiar de dueño.

Dos tests fijan las mitades que un `abort` arriba del método habría apagado en
silencio: **regalar la propia** y **asignar la del colegio** siguen dando 200.

### La población entera de «ponerle una imagen a otro»

Greppeada la operación en todo `app/`, no en el controlador que se estaba
mirando. Hay **siete** métodos que le ponen a una persona una imagen que llega
por el cuerpo, y **no hacen lo mismo**:

| Grupo | Qué escribe | Cuántos | Regla |
|---|---|---|---|
| `images-users/cambiar-*` | `images.user_id = <destino>` | 3 | la imagen tiene que ser **suya o del colegio** (§99) |
| `perfiles/cambiar*un*` | `users.imagen_id`, `alumnos.foto_id`, `profesores.foto_id`, `firma_id` | 4 | `esAdministrativo` (§36) |

**Lo que separa a los dos grupos no es quién llama: es si el dueño original se
queda sin la imagen.** Los tres primeros se la llevan —y el dueño la pierde de
`myimages`, que filtra por `user_id`—; los cuatro segundos solo apuntan. La
asimetría queda fijada con test por los dos lados, porque una asimetría sin
escribir es indistinguible de un descuido.

Medido y **no** juzgado: las cuatro de `perfiles` **no comprueban de quién es la
imagen que apuntan**, así que un administrativo puede poner la foto privada de un
alumno de avatar de otra cuenta. No se cierra —es la administración de fotos del
colegio y son los diez de siempre— pero queda escrito.

## §100 — Un botón que borra otra cosa

`perfiles/destroy/{id}` no borra un perfil: hace `Grupo::findOrFail($id)->delete()`.
Y tiene cliente enchufado — `usuarios/UsuariosCtrl.ts:139` llama
`PerfilesApi.eliminar(row.user_id)`.

Medido: **pulsar «Eliminar» sobre un usuario deja al usuario intacto y manda a la
papelera el grupo cuyo id coincide con su `user_id`**, y devuelve la fila de
`grupos` —con su `grado_id`— que el front lee como si fuera el usuario borrado.

Lo incómodo es que **el aviso estaba escrito en dos sitios**: la cabecera de
`PerfilesApi.ts` en el front («si alguien añade `obtener(id)` por analogía, va a
devolver un grupo y parecerá que funciona») y el docblock del `forcedelete` de
`PerfilesController` («cerrar solo la de grupos dejaba esta puerta abierta»). Dos
avisos en prosa, en dos repos, y el botón enchufado. **Un aviso en prosa no
defiende.**

**Lo que borra no se cambia** —roto y con ruta se documenta, y decidir qué
debería borrar es una decisión con el front delante—. Lo que se cierra es la
autorización, que no necesitaba ninguna decisión: sus hermanas de papelera piden
superusuario y ésta se había quedado con `auth.personal`.

Y la gemela, **`grupos/destroy`, estaba igual**. De las cuatro operaciones de
papelera de un grupo, **las dos que borran estaban abiertas y las dos que
deshacen pedían superusuario** — la pareja al revés de como suele salir.
Cerradas las dos.

> Durante unas horas hubo aquí un test **en verde** afirmando que `grupos/destroy`
> seguía abierta, escrito para que quien la cerrara tuviera que venir a borrarlo.
> Cuando se cerró no se quitó: **se le cambió el valor esperado**, que es como se
> cierra una población sin perder el rastro.

### Lo demás del §100, medido y no juzgado

- `planillas/show-grupo` de un grupo de **otro año** sale, con los periodos del
  año del token. Precedente exacto: `asignaturas/restaurar`.
- `publicaciones/restaurar` **ya estaba defendida por dentro** aunque su ruta solo
  pida token. Lo que faltaba era la respuesta: contesta `Restaurada` **como
  cadena suelta, no como JSON**, y **403 y no 404** con un id que no existe —
  distinguir «no existe» de «no es tuya» sería contar si existe.
- `acudientes/ultimos` son ocho, con documento, dirección, teléfono, celular y
  correo. `acudientes/planillas-ausencias` pide los parientes de uno en uno.

## §101 — Un campo que no se manda es un campo que se pisa

Cinco métodos, 52 columnas, y **dos herramientas distintas porque el
discriminador se comprobó, no se copió**:

| Método | Columnas | Herramienta | Por qué |
|---|---|---|---|
| `PerfilesController::putUpdate` | **22, ninguna a salvo** | defecto de `input()` | no hay `merge` ni `sanar*` |
| `GruposController::putUpdate` | 10 | defecto de `input()` | idem |
| `AcudientesController::putSeleccionarParentesco` | 4 | defecto de `input()` | idem |
| `ProfesoresController::putUpdate` | 17 | `CamposQueVinieron` | `sanarInputProfesor()` mete `null` |
| `ProfesoresController::postStore` | 19 | **ninguna** | es un alta: no hay nada que pisar |

Tres cosas que salieron de aquí y valen para el resto del barrido:

1. **«Tiene defecto» no es «está a salvo».** `caritas` era la única de las diez de
   grupos con defecto, y ese defecto **la apaga**. Es la
   [§68](../05-codigo-muerto-y-roto.md) con casco: el `is_active` de aquella
   también tenía defecto, y era justo el que se pisaba. Y las caritas deciden si
   el grupo se califica con escala de preescolar en vez de con números.
2. **`postStore` es un falso positivo**, fijado como tal con su test para que
   nadie lo reabra: `new Profesor` y `new User`. Un detector da sitios donde
   mirar, nunca una lista de fallos.
3. **El snapshot `grupos-show` tenía el fallo dentro.** `GruposTest` creaba el
   grupo **con** titular, lo editaba sin mandarlo y comprobaba dos líneas después
   que no tenía titular. El contrato guardaba el vaciado como si fuera correcto.

### La comprobación que hay que copiar de aquí

No es revertir el arreglo: es **revertir a la solución equivocada que parecía
buena**. Puesto en `Profesores` el arreglo copiado de `perfiles` —el defecto de
`input()`, bien escrito, no una versión de paja— quedan **quince columnas en
verde y `ciudad_nac` vaciada**. Por eso la aserción nombra a `ciudad_nac` y
`tipo_doc`: son justo las que `sanarInputProfesor()` mete como `null`, y las
únicas que un arreglo copiado deja rotas.

Y el caso que separa las dos implementaciones **no es el `0`, es el `null`
explícito**: con un `0` en el cuerpo, `input($k, $def)` y `input($k) ?? $def` se
comportan igual. Con `null`, la primera escribe null —el cuerpo diciendo
«quítalo»— y la segunda se comería la intención de borrar.

## §102 — Editar un grupo lo mueve de año, con las matrículas dentro

`GruposController::putUpdate` hacía `$grupo->year_id = $user->year_id` **sin leer
nunca el cuerpo**. Y el front tampoco lo manda: ni la rejilla (`GruposCtrl`) ni
el formulario (`GruposEditCtrl`) incluyen `year_id`. O sea que lo que se escribía
era **siempre** el año del que edita, y eso es una de dos cosas:

- el grupo ya estaba en su año → no pasa nada, que es el 99% de las veces;
- el grupo era de otro año → **se lo lleva**, y las matrículas van dentro, porque
  cuelgan del grupo y no del año.

Medido dos veces por separado —el lote K y esta sesión, con el mismo resultado—:
corregirle la abreviatura a un grupo del año 7 lo pasa al 8 **con sus 56
matrículas**. La respuesta es 200 y el nombre cambiado; no se ve nada.

Se deja de escribir `year_id` al editar. **No hay forma de que el cliente lo
pida, así que no se le da una**: el año se decide al crear, y `postStore` lo
sigue tomando del token, que ahí es la única fuente posible. Mover un grupo de
año es otra operación y hoy no existe.

> La mitad que un arreglo así se lleva por delante en silencio es la de crear, y
> tiene su propio test: **crear un grupo lo sigue creando en el año de quien lo
> crea**. Lo que cambia entre las dos rutas no es el dato — es que una fila nueva
> no tiene año previo y una que existe sí.

### Y lo que se nota en un colegio: nada, porque era una mina

Buscado en el front antes de afirmarlo. Las dos pantallas que editan un grupo
—la rejilla de `GruposCtrl` y el formulario de `GruposEditCtrl`— se alimentan de
`GET api/grupos`, que filtra `g.year_id = <el del token>`. **Solo enseñan grupos
del año en curso**, así que el sello del año era ahí un no-op.

Sí existen `grupos/next-year` y `grupos/con-paises-tipos-next-year`, pero lo que
los consume es `AlumnosNewCtrl` —un **desplegable** de prematrícula—, no una
rejilla que edite.

O sea que **por el front no se podía disparar**: era una mina esperando a una
pantalla nueva, a un cliente que reutilizara `grupos/update`, o a una llamada a
mano. Conviene que esté escrito, porque el número —56 matrículas— suena a
incidente en curso y no lo es, y porque la próxima pantalla que liste grupos de
otro año lo habría disparado sin que nadie lo relacionara con esto.

Este método es el mismo del §101: en la misma llamada se iban a `null`
`titular_id`, `cupo`, `abrev`, `valormatricula`, `valorpension` y `orden`. Las dos
cosas están arregladas y las dos tienen test.

---

## Lo que se encontró después, repasando el lote entero

El parte de las «11 rutas medidas» **tenía una de más**. `perfiles/forcedelete`
llevaba guard desde la §28.4 y por eso constaba mirada, pero **nadie había visto
su respuesta**: la exención dice quién *no* pasa, y ahí se quedó. Es *medir una
ruta no es haberla juzgado* aplicado a mi propio parte.

Con ella, y barriendo **todas** las rutas de los ocho controladores contra los
tests, quedaban dos sin comprobar:

- **`perfiles/forcedelete`** hace `forceDelete()` —borrado físico, 27 tablas en
  cascada hasta `notas`— y **solo desde la papelera**: con el grupo vivo,
  `onlyTrashed()` no lo encuentra y contesta **404, no 200**.
- **`myimages/store-firma` es la misma función que `myimages/store-intacta-privada`,
  línea por línea**: `guardar_imagen($user)` + `publica = false` + `save()`, a
  diez líneas una de otra. No se unifican —son dos entradas del contrato con
  cuatro clientes— pero se fija que **hacen lo mismo**: mientras nadie lo
  compruebe, arreglar una y no la otra es gratis, y es lo que ya pasó dos veces
  esta noche con `perfiles`/`grupos`.

> El primer barrido buscaba la ruta **con `{id}` literal dentro** y dio 26 falsos
> positivos. El detector era mío y mentía igual que los demás. Recortando el
> parámetro quedaron dos, que son los de arriba.

Y los dos controladores de `Informes/` que no eran de ningún lote
—`planillas-ausencias/show-profesor` y `notas-perdidas/show-profesor`— comparten
el 500 del §98 y quedan cerrados aquí. **Van cuatro de los seis llamantes de
`Profesor::detallado()`**; los dos que faltan son del lote D y el test lo dice
por escrito, para que se vea cuántas puertas quedan y no haya que contarlas otra
vez.

## Tres instrumentos que mintieron, y con qué se cazaron

Ninguno de los tres se cazó con una impresión: los tres, con un número.

| El instrumento | Qué decía | Qué pasaba | Qué lo destapó |
|---|---|---|---|
| `ps \| grep worktrees/e` | «no corre ninguna suite mía» | la línea del proceso es `php artisan test` a secas: ni worktree ni base, que viven en `/proc/<pid>/environ` | leer el `environ` de cada pid |
| El log que deja de crecer | «la suite murió» | búfer de bloque: se paró justo en **10.210 bytes** | el tamaño exacto del fichero |
| El barrido de rutas sin test | 26 rutas sin comprobar | buscaba `profesores/show/{id}` con las llaves dentro | recortar el `{param}` y repetir |

Y el cuarto, que es el caro y ya está arriba: **el snapshot `grupos-show`**, que
no es que no protegiera — **defendía el fallo**.

---

## Lo que queda anotado y no se tocó

- **Para Joseth**: si el alcance del Secretario incluye dar de baja a un
  compañero o a un grupo, `profesores/destroy`, `perfiles/destroy` y
  `grupos/destroy` suben a `esAdministrativo` con una palabra.
- **Para el front**: el botón «Eliminar» de la rejilla de Usuarios manda a la
  papelera un grupo. No se toca desde aquí.
- **Para quien tenga `Profesor::detallado()`**: cuatro llamantes más comparten el
  500 — `AsignaturasController:198`, `UnidadesController:163`,
  `Informes/PlanillasAusenciasController:70` y
  `Informes/NotasPerdidasController:135`.
- **Serie `restore`, agotada**: 13 sitios en todo `app/` (10 con `->restore()`, 3
  con `UPDATE … deleted_at = NULL`), 11 vivos, 9 enrutados y **los 9 con guard
  verificado por lectura**. Ceguera nombrada: **un `deleted_at=:x` con `null`
  atado no lo ve ninguno de los dos patrones** — los 7 sitios de esa forma se
  miraron uno a uno y los siete atan un `$now`.
