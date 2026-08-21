# Lo que queda, y lo que ya se sabe de cada cosa

Las fases 0–6 están cerradas y el plan de rendimiento también, salvo lo que hay
aquí. **Ninguna de estas es trabajo que falte por hacer sin más: cada una está
parada por algo concreto**, y lo que vale de este documento es ese algo — para
no volver a descubrirlo dentro de tres meses.

Orden: primero lo que decidió Joseth que se hará, después lo que espera una
decisión suya. Lo que se cierra **se deja aquí**, no se borra: el porqué de cada
desvío respecto al plan es justo lo que no se puede reconstruir después.

---

## 0. La noche del 20 al 21 de agosto de 2026 — lo que hay que mirar primero

Se cerró la serie del barrido y se abrió otra, la de **cobertura**: en vez de
«¿tiene guard esta ruta?», la pregunta fue **«¿alguien ha mirado alguna vez qué
responde?»**. `tools/cobertura-de-rutas.py` daba 261 de 539 rutas comprobadas y
cinco controladores con **cero**. Ahí estaban casi todos los hallazgos de abajo.
La cobertura quedó en **312 de 539 (58%)** y ningún controlador a cero.

**Y la pregunta funcionó tan bien que merece quedarse escrita.** Seis fallos de
autorización o de credenciales en una noche, todos en los dos huecos más grandes
que señaló la medición, y ninguno lo había encontrado ni el barrido, ni larastan,
ni las tres herramientas de autorización. Lo que ninguna miraba era **el resultado
de la ruta**: el barrido mira quién llega, larastan mira si el código puede
funcionar, y `inventario-autorizacion.py` mira la firma. La cobertura mira si
alguien ha leído la respuesta alguna vez, y donde nadie la había leído estaba
todo.

### Lo que se arregló, y **hay que desplegar**

Los seis son de autorización o de credenciales, y ninguno está desplegado: `app/`
es **copia real en cada colegio** (`docs/DESPLIEGUE.md`). Fusionar no es desplegar.

| Qué pasaba | Dónde |
|---|---|
| Un **alumno** sacaba, con su propia clave y sin token, **todas las ausencias y tardanzas del colegio** de cualquier año | [05 §25](05-codigo-muerto-y-roto.md) |
| El lector de tardanzas aceptaba la contraseña **en claro** contra la columna, un respaldo que su controlador hermano ya había quitado por escrito | [05 §25.1](05-codigo-muerto-y-roto.md) |
| Una llamada sin `clave` dejaba a **los 1.280 alumnos con la contraseña vacía** — y entrar con la contraseña vacía responde 200 | [05 §26](05-codigo-muerto-y-roto.md) |
| Cualquiera de los **51 profesores** reiniciaba la contraseña de todo el colegio y creaba las cuentas de todo el colegio | [05 §26.1](05-codigo-muerto-y-roto.md), [§29.3](05-codigo-muerto-y-roto.md) |
| Un **docente se hacía con la cuenta del superusuario** en una petición, y recibía la clave nueva en la respuesta | [05 §29](05-codigo-muerto-y-roto.md) |
| Cualquier profesor **se fabricaba un superusuario** mandando `is_superuser: 1` al crear un profesor | [05 §30](05-codigo-muerto-y-roto.md) |
| **`GET api/alumnos` entregaba el directorio del colegio entero** —nombre, fecha de nacimiento, celular, dirección, religión y deuda de cada alumno— a cualquier alumno o acudiente | [05 §34](05-codigo-muerto-y-roto.md) |
| «Ahora NO es año actual» dejaba el año **encendido**, por tres caminos distintos | [05 §28](05-codigo-muerto-y-roto.md) |

Y uno que no es del código sino de la red que lo vigila: **una ruta golpeada con
dos tokens en el mismo test medía dos veces al primero**, porque Laravel guarda la
instancia del controlador dentro de la ruta. Cerrado en `CasoDeContrato`, y
anotado como bloqueante de Octane — [03-tests.md](03-tests.md).

**El último salió de comprobar los otros seis.** Se volvió a correr el barrido
para medir el efecto de los arreglos y apareció `GET api/alumnos`, que llevaba
abierta desde siempre: cae justo entre dos criterios —no nombra a nadie, así que
ningún inventario la señalaba, y no está muda, así que tampoco entró en las listas
de «sin juzgar»— y se quedó en el grupo que se repasa a mano. Se repasaron once de
las doce. Vale la pena quedarse con eso: **una lista que hay que mirar a mano cada
vez acaba teniendo un hueco, y el hueco no se ve**.

### Lo que necesitaba una respuesta suya — **contestado el 21 ago 2026**

Las cuatro preguntas de la noche anterior se le hicieron a Joseth una a una y las
cuatro tienen respuesta. Se escriben aquí **con lo que dijo, no con lo que se le
propuso**, porque en dos de ellas la respuesta no era ninguna de las opciones y
eso es justo lo que había que aprender.

1. **El interruptor del periodo** ([05 §27](05-codigo-muerto-y-roto.md)) →
   **derivar el periodo de la fila que se toca, las 26**. La opción barata —exigir
   que `num_periodo` y `periodo_id` concuerden— queda descartada por lo que ya
   decía la §27.1: no cierra la rejilla de definitivas ni `notas/update`, que son
   las que más pesan.
2. **Quién es el «Secretario»** ([05 §30.2](05-codigo-muerto-y-roto.md)) → **un
   rol nuevo, `Secretario`, que se le asigna a un usuario docente.** No es
   `Admin`: la razón de existir del rol es precisamente **una secretaria docente
   que no es superusuario**, y con `Admin` eso no se puede porque los diez `Admin`
   son los diez `is_superuser`. `Role::isSecretario` ya lo busca exactamente por
   ese nombre, así que los once sitios empiezan a funcionar en cuanto la fila
   existe y alguien la tiene.

   **Y el alcance no es el que se había propuesto.** Se le ofreció «alumnos,
   matrículas, docencia e informes» y corrigió el corte, que va por otro sitio:

   | Puede | No puede |
   |---|---|
   | Las **configuraciones del colegio**: materias y su orden, las asignaturas de **todos** los grupos, los titulares de grado | **Crear usuarios** |
   | Alumnos y su edición, matrículas | |
   | La **configuración del año**, y **bloquear periodos** | |
   | Ver e imprimir **todos** los informes, no solo los de sus grupos | |
   | De unidades, subunidades y notas, **solo las suyas como docente** | Las de los demás docentes |

   O sea que el Secretario **no** es «un docente con más cosas» ni «un
   superusuario con menos»: es administrador de la **estructura** del colegio y
   docente normal en **su propia aula**. Los dos ejes son independientes, y
   confundirlos es lo que haría el arreglo mal.
3. **El «Psicólogo»** ([05 §30.2](05-codigo-muerto-y-roto.md)) → el rol
   `Psicólogo` (que ya existe, id 11, cuatro personas) **abre `nee` y
   `nee_descripcion`, y nada más**. La decisión se tomó después de ir a mirar el
   PIAR, y lo que se encontró allí cambió la pregunta — está en la [§35](05-codigo-muerto-y-roto.md).
4. **El hash del lector de tardanzas** ([05 §25.4](05-codigo-muerto-y-roto.md)) →
   **quitarlo del `SELECT`**, en `tardanzas/login` y en `traer-datos`.
5. **Los años actuales de los dieciséis colegios**
   ([05 §28.3](05-codigo-muerto-y-roto.md)) → **un comando de diagnóstico**, en vez
   de una consulta suelta que hay que pegar dieciséis veces. Y con contexto nuevo
   que Joseth dio al contestar y que no estaba escrito en ninguna parte:

   > Más o menos en **octubre se crea el año siguiente copiando todo del anterior**
   > excepto el número del año. El año que elige el usuario rige la plataforma con
   > sus configuraciones, **excepto en los informes, donde siempre salen el rector
   > y el secretario del año actual** — para que se puedan firmar informes viejos
   > cuando el rector de aquel año ya no trabaja en el colegio.

   Las dos mitades importan. La primera pone **fecha** a esto: la copia de octubre
   es exactamente el momento en que un colegio con dos años actuales se lleva la
   ambigüedad al año nuevo, así que el comando quiere estar corrido antes. La
   segunda explica el `$actual=true` de `Year::datos()`, que hasta hoy parecía un
   parámetro suelto: **es una regla de negocio, y de las que un refactor bienintencionado
   borra** por parecer un descuido.

Lo demás de esa noche está abajo, en la tabla del §5, con las anteriores.

---

## 1. La importación de Excel, reanudable — **hecha el 20 ago 2026**

**Estado: cerrada.** Acordada por Joseth ese mismo día y hecha a continuación.
Se deja escrito lo que se hizo y, sobre todo, **en qué se apartó del plan de
arriba y por qué**, que es lo que no se puede reconstruir leyendo el diff.

`max_execution_time` está en **300 s** en la cuenta de cPanel, y está así **por
esto**: las importaciones de alumnos tardaban mucho. Bajarlo exige que la
importación deje de ser una sola petición que o entra entera o se pierde.

### Lo que ya estaba, y que era la mitad del diseño

Joseth había puesto un punto de control. No era un `Log::info` —eso es lo que
decía este documento antes de ir a mirarlo— sino **`Debugging::pin`, que escribe
en una tabla**, con el comentario `//No eliminar para continuar si se cae el
servidor!!` al lado. La intuición era la correcta y el sitio también; lo que le
faltaba era **forma**: tres cadenas sueltas por alumno (`'Alum_id: 431'`,
`'Grupo: 5A'`) sin decir de qué archivo ni de qué año son. Un humano puede
leerlas. El importador no.

Las líneas siguen ahí, porque son el único rastro de las importaciones
anteriores a hoy en las dieciséis bases, con el comentario reescrito para que se
sepa qué las reemplazó.

### Lo que se hizo

- **`importaciones`**, una fila por importación, no por alumno: archivo, huella,
  año, avance por hoja, filas, estado, error, inicio y fin
  (`2026_08_20_200000_create_importaciones_table`).
- **`App\Services\PuntoDeControlDeImportacion`**, que es quien decide qué se
  reanuda y qué no. Todo el porqué está en su cabecera.
- **La huella es el sha256 del CONTENIDO**, no el nombre: la secretaría sube
  tres veces `alumnos.xlsx` y son tres archivos distintos.
- **Idempotencia por el documento del alumno**, la clave natural. Antes, una
  fila sin `id` significaba «créalo» sin mirar si ese documento ya estaba; eso
  duplicaba alumno, usuario y matrícula.
- **Índice en `alumnos.documento`**, que hasta hoy no tenía ninguno porque nada
  buscaba por ahí. El `EXPLAIN` da el mismo criterio del paso 12: `type: ALL`,
  `possible_keys: NULL`.
- **La respuesta no cambia**: sigue siendo la cadena `'Importados.'`. Ese era el
  punto — es lo que separa esto de las colas (§3).
- Seis tests en `tests/Contrato/ImportacionReanudableTest.php`. Los tres que
  fijan comportamiento nuevo se comprobaron al revés, desactivando el arreglo,
  para que no pasaran por casualidad.
- Un método nuevo en `SafeUpload`, `nombreParaGuardar()`, porque
  `GuardsDestructivosTest` falló en cuanto el código nuevo leyó el nombre del
  archivo subido. Tenía razón: lo que se guarda en una columna acaba saliendo
  por una pantalla, y `getClientOriginalName()` vive en un solo sitio.

### Dónde se apartó del plan, y por qué

**«Por lotes, de N en N» → una transacción por fila.** El plan pedía lotes
pensando en la memoria, y **la memoria no es el problema**: `memory_limit` son
768M y una hoja de un colegio entero cabe de sobra. Lo que se agota es el
tiempo. Y anotar de N en N tiene un coste que el plan no había visto: obliga a
**reprocesar hasta N-1 filas** al reanudar, y reprocesar una fila de alumno no
es inocuo —el camino de acudientes inserta sin mirar si ya estaba—. Con la fila
entera y su marca de avance **en la misma transacción**, no se reprocesa
ninguna: una fila está aplicada si y solo si el punto de control la da por hecha.

Cuesta un `UPDATE` más por alumno sobre las ocho escrituras que ya hacía cada
fila, y la transacción ahorra los `fsync` sueltos de esas ocho. No se paga: se
cambia de sitio.

**«Medirlo antes» → la tabla es la medición.** No había forma de medir una
importación de producción desde aquí, y la tabla contesta la pregunta ella
misma, en cada colegio, sin instrumentar nada:

```sql
SELECT archivo, year, filas, TIMESTAMPDIFF(SECOND, inicio, fin) AS segundos
FROM importaciones WHERE estado = 'completada' ORDER BY id DESC;
```

**Ese número sigue siendo el que falta**, y ahora sí se puede recoger: hace
falta una temporada de matrículas en el colegio que más importa antes de tocar
`max_execution_time`. La otra pregunta que quedó abierta —si hay **otro**
endpoint apoyado en esos 300 s— sigue abierta y sigue necesitando
`CONSULTAS_LENTAS_MS`.

### Y una corrección: importador vivo hay uno, no dos

Este documento decía que los dos importadores eran
`ImportarController::postAlgo()` y `::postCartera()`. **`postCartera` está roto**
desde el salto a `maatwebsite/excel` 3.x, con el mismo error exacto que
`GET api/importar`: la firma de la 2.x. No había salido antes porque el muestreo
de la P2 solo golpeaba lecturas sin parámetro, y esta es un POST con un archivo
dentro. Queda fijado en `ExcelTest` y descrito en
[05 §8](05-codigo-muerto-y-roto.md); qué debe hacer la importación de cartera es
una decisión del colegio, como los otros tres de esa familia.

### Lo que esto NO cubre

Si la secretaría, en vez de volver a subir el mismo archivo, **exporta uno
nuevo** y sube ese, la huella cambia y no se reanuda nada. No hace falta que lo
haga: la hoja recién exportada ya trae el `id` de los alumnos que sí entraron.
Los dos caminos reales están cubiertos, cada uno por su lado — el punto de
control el primero, la clave natural el segundo.

Lo que sigue sin cubrir es **duplicar acudientes** en ese segundo camino: sus
tres ramas dependen de lo que la secretaría escribió en la hoja, y hacerlas
idempotentes exige decidir qué significa «este acudiente ya está» cuando la fila
viene sin documento. No se tocó a propósito.

---

## 2. Unificar las fechas en `-05`

**Estado: propuesta de Joseth (20 ago 2026)**, razonable y sin urgencia.

Hoy conviven dos zonas: `config/app.php` dice `UTC`, el código de siempre llama
114 veces a `Carbon::now('America/Bogota')` y la sesión de la Fase 3 llama 8
veces a `Carbon::now()`. **Se revisaron las ocho y no hay fallo**
([§10](05-codigo-muerto-y-roto.md)): cada grupo escribe y compara en su propia
zona, y una duración calculada entera en una zona da lo mismo que en la otra.

La propuesta —todos los clientes están en Colombia, así que `-05` en todas
partes— es defendible. Como dice el propio Joseth, cualquier zona sirve mientras
se maneje bien; el valor de unificar no es la zona, es dejar de tener dos.

**La trampa, que es la razón de que esto no sea un cambio de una línea:** poner
`'timezone' => 'America/Bogota'` en `config/app.php` cambia lo que devuelve
`Carbon::now()` **para los datos que ya están escritos**. Las filas de
`personal_access_tokens` tienen `expires_at` en UTC; con el `now()` nuevo,
cinco horas por detrás, **esos tokens vivirían cinco horas de más**. No se ve, no
falla, y no lo detecta ningún test que no lo esté buscando.

O sea que el cambio son dos cosas: la línea de configuración **y** decidir qué
pasa con lo ya escrito. Lo barato es hacerlo en una ventana en la que se puedan
invalidar todas las sesiones vivas —`sesion:limpiar` con `--dias=0`, o vaciar la
tabla— y avisar de que todo el mundo vuelve a entrar. Es lo mismo que ya se hizo
una vez al pasar de JWT a Sanctum.

Las demás tablas no necesitan conversión: sus fechas ya están en hora de
Colombia, que es la que pasarían a leerse.

`importaciones`, que es de hoy, escribe en UTC como la sesión — pero sus dos
marcas solo se restan entre sí, nunca se comparan con otra tabla, así que el
cambio no la afecta.

---

## 3. Colas para importadores e informes

**Estado: posible desde el 20 ago 2026** —sí hay cron—, y frenado por otra cosa.

Ya no es un problema de infraestructura: un worker es `queue:work
--stop-when-empty` desde el scheduler, y el cron está. Lo que frena es que
encolar **cambia el contrato de los cuatro clientes** —hoy el importador
responde con el resultado; encolado responde con un identificador y hay que
preguntar—, y uno de esos clientes es la app de Flutter, que es **una sola para
los dieciséis colegios** y por tanto no se puede escalonar.

Y sigue faltando el número: «los imports dan timeout» es una impresión, y el
techo real son cinco minutos. Ver [02-plan-rendimiento.md](02-plan-rendimiento.md) §5.

---

## 4. Las definitivas: notas que se pierden, se duplican y no se actualizan

**Estado: analizado, planificado y decidido — parado hasta que termine la
migración en curso.** Lo decidió Joseth el 20 ago 2026, y la razón es de orden,
no de duda: el trabajo entra de lleno en el cálculo de notas, que el §5 del plan
protege, y abrirlo a la vez que la migración deja dos frentes tocando lo mismo.

El análisis completo está en **[10-definitivas.md](10-definitivas.md)**: seis
sitios distintos escriben en `notas_finales` con cinco criterios distintos de qué
borrar, ninguno transaccional, sobre una tabla **sin clave única**. De ahí salen
los tres síntomas que se venían reportando por separado y resultaron ser el mismo
problema: definitivas que desaparecen al cambiar de periodo, definitivas
duplicadas que el profesor puede editar dos veces, y notas que los profesores
juraban haber puesto —y tenían razón.

Lo que **no** hay que volver a averiguar cuando se retome:

- La causa principal del borrado es `BoletinesController::putDetailedNotas`, con
  su propio `// CALCULAMOS SIN VERIFICAR QUE ESTÉ DESACTUALIZADO` al lado. Usa el
  periodo **del usuario que mira**, no el del boletín, y su ruta es
  `boletin.propio`: también lo dispara un acudiente.
- La comprobación de «desactualizada» se calcula en /notas y **el `if` de al lado
  no la mira**. Y aunque la mirara, es un `MAX(notas.updated_at)`: ciega a los
  borrados, a los porcentajes y a los alumnos nuevos.
- `putSubunidad` no guarda nada: la consulta está en comillas dobles con sintaxis
  de concatenación de simples.
- El front no revierte el valor cuando falla el guardado, y pierde la última nota
  tecleada si se cambia de asignatura antes del segundo del `debounce`.

Las **tres decisiones ya están tomadas** y no se re-litigan (10 §9): la fila
existe siempre que exista la matrícula; entre notas duplicadas gana la más alta
—pero entre definitivas duplicadas gana la manual—; y la fórmula **no** se
normaliza, para que los porcentajes mal puestos se vean en la planilla.

Cuando se retome, se empieza por la fase 0: la herramienta de medición, para
saber el tamaño real del daño en las dieciséis bases antes de tocar código.
**Antes de optimizar algo: medirlo.**

---

## 5. Lo que espera una decisión del colegio

| Qué | Dónde está descrito | Qué falta decidir |
|---|---|---|
| Cuatro endpoints rotos desde siempre | [05 §6.5, §7.2, §8, §9.2](05-codigo-muerto-y-roto.md) | qué debe devolver cada uno; en dos de ellos, si la operación debe existir. **De uno ya no hay que averiguar nada más** (21 ago 2026): `periodos/update/{id}` falla con y sin el campo que la §9 dejó en duda, y arreglarla enciende la única forma de dejar dos periodos actuales en un año — [05 §31.1](05-codigo-muerto-y-roto.md) |
| La estructura de roles y permisos | [06 §4](06-autorizacion.md) | si los roles de la base se quedan y se pueblan, o se borran las cuatro tablas |
| 9 rutas de catálogo sin guard | [08](08-revision-idor.md) | a quién se abren; no exponen a nadie, pero no están decididas. Vuelto a medir el 20 ago 2026 tras [05 §16](05-codigo-muerto-y-roto.md): 12 → 11 → 9. La que salió de la lista no se decidió, **se recategorizó**: `unidades/de-asignatura-periodo` no era una lectura, escribía |
| `APP_DEBUG` en producción | [01](01-plan-seguridad.md) | comprobarlo colegio a colegio. `display_errors` de PHP está en Off, así que la mitad del riesgo ya está cubierta |
| Los correos `username@myvc.com` autogenerados | [01](01-plan-seguridad.md) | dos usuarios que compartan correo comparten reseteo de contraseña |
| `GET api/contratos` manda el expediente y el cliente solo quiere el nombre | [05 §14.4](05-codigo-muerto-y-roto.md) | qué columnas se recortan. Lo llama la app de Flutter desde pantallas de familia, así que el cambio entra en los dieciséis colegios a la vez |
| `GET api/perfiles/usernames` devuelve los 2.351 usuarios del colegio | [05 §14.4](05-codigo-muerto-y-roto.md) | apuntar `UserConfiguracionCtrl` a `comprobarusername/{username}`, que ya existe, **y desplegar el front antes** de cerrar la ruta |
| `GET api/perfiles/username/{username}` no comprueba que el usuario sea el tuyo | [05 §14.4](05-codigo-muerto-y-roto.md) | si `ExigirPersonaPropia` aprende a resolver un nombre de usuario, o si la ruta deja de aceptar parámetro y lo saca del token |
| `GET api/asignaturas/listasignaturas-alone` le da a un alumno las asignaturas del profesor con su mismo id | [05 §16.6](05-codigo-muerto-y-roto.md) | es la misma pregunta que Joseth dejó abierta en [05 §11.2](05-codigo-muerto-y-roto.md): si esa pantalla debe enseñarle sus asignaturas de verdad. Cerrarla con `auth.personal` es de una línea; decidir qué ve el alumno, no |
| `PUT api/publicaciones/borrar-comentario` responde 500 a todo el que no sea superusuario | [05 §22.3](05-codigo-muerto-y-roto.md) | si se arregla. Hoy nadie borra un comentario suyo; arreglarlo enciende esa función en los dieciséis colegios de golpe |
| `GET api/candidatos/conaspiraciones` responde 500 a alumnos y acudientes desde siempre | [05 §18.4](05-codigo-muerto-y-roto.md) | qué votación es «la suya» cuando hay varias en curso. Y que arreglarlo **enciende** para los alumnos una pantalla que hoy no funciona en los dieciséis colegios, que es una decisión y no un arreglo |
| El lector de Tardanzas devuelve el hash bcrypt del usuario | [05 §25.4](05-codigo-muerto-y-roto.md) | **solo decir que sí.** Se temía que el lector validara contra ese hash estando sin red; se fue a mirarlo (`tardanzasMyvc-old`) y no: `insertUser()` hace `user.password = localStorage.password` antes de guardar, así que la columna local lleva la contraseña **en claro**, y el login sin red compara contra eso. El único sitio que conserva el hash es `localStorage.USER`, que nadie lee. Ningún otro cliente llama a estas rutas |
| Un `Usuario` administrativo sin `is_superuser` lee en Tardanzas pero no puede subir | [05 §25.3](05-codigo-muerto-y-roto.md) | si el `if` de `TSubirController::user()` debe decir `Profesor o Usuario` como el de lectura, o si dejar fuera al administrativo era la intención. Hoy entra al lector, ve los datos y recibe 400 al subir |
| `years.profes_can_edit_alumnos` decide más cosas de las que dice su nombre | [05 §29.1](05-codigo-muerto-y-roto.md) | qué debe poder hacer un docente con esa bandera encendida. Hoy son dos cosas escritas en dos sitios: borrar alumnos definitivamente (`Autoriza::puedeBorrarAlumnos`) y resetear la contraseña de un alumno. Las dos son suyas por herencia, no por decisión |
| **Quién es el «Secretario»** —y el «Psicólogo»—, que el código busca donde no están | [05 §30.2](05-codigo-muerto-y-roto.md) | ocho sitios preguntan `Role::isSecretario()` y la tabla `roles` no tiene ese nombre; otros tres preguntan `users.tipo == 'Secretario'`, y `tipo` solo toma los cuatro valores del `switch` del contexto, así que es siempre falso. Hoy el criterio efectivo en los once es `is_superuser` — y en `AcudientesController` eso deja a un administrativo sin poder crear acudientes, que es lo contrario de lo que la línea pretendía. Si la respuesta es el rol `Admin`, hoy no cambia nada y mañana sí. Y con el psicólogo pasa al revés: su rama de `putGuardarValor` compara `tipo` con `'Psicólogo'` y **nunca se ejecuta**, así que las necesidades educativas especiales de un alumno solo las escribe hoy un superusuario — con el comentario del propio autor al lado diciendo que quería el rol |
| El interruptor con el que el colegio cierra el periodo a los profesores lo elige el cliente | [05 §27](05-codigo-muerto-y-roto.md) | si se hace la 1 —exigir que `num_periodo` y `periodo_id` concuerden, media hora, y deja fuera la rejilla de definitivas y `notas/update/{id}`, que no mandan `periodo_id`— o la 2, que es derivar el periodo de la fila en las 26 llamadas y entra en el cálculo de notas. La tercera vía, ignorar `num_periodo`, quedó descartada al medir el front: apagaría la rejilla de definitivas. **Es la única de esta lista que deja algo abierto ahora mismo** |
| `Login::ponerEnElPeriodoActual` se queda con el primer año actual, sin `ORDER BY` | [05 §28.3](05-codigo-muerto-y-roto.md) | qué hacer si un colegio tiene dos años marcados como actuales. Los tres caminos que los creaban están cerrados, así que esto solo puede venir de datos de antes; poner `ORDER BY year DESC` es una línea, pero **decide en qué año amanece un colegio** que hoy entra en el otro. Se contesta mirando las dieciséis bases: `SELECT id, year FROM years WHERE actual=1 AND deleted_at IS NULL` |

---

## 6. Continuo, sin final

- **Larastan del 2 al 3 — hecho el 20 ago 2026.** Sigue siendo cierto que cada
  subida encuentra cosas: 21 endpoints rotos en el 1, cuatro en el 2, y en el 3
  un fallo de otra clase, porque el nivel es de otra clase. El 3 no comprueba
  que algo exista sino que **sea lo que dice ser**, y lo que salió fue eso:

  - **Siete columnas `tinyint(1)` escritas con booleanos de PHP.** Eloquent no
    relee la fila tras `save()`, así que el JSON de la llamada que crea la fila
    lleva `false` y el de cualquier lectura posterior lleva `0` — el mismo campo
    del mismo registro con dos tipos según por dónde se pida. En
    `vt_participantes` las dos formas salen **en la misma respuesta**: los
    restaurados de la papelera con `0`, los creados en esa llamada con `false`.
    33 sitios; larastan veía 14 y el resto estaban detrás de un
    `Request::input('is_active', true)`, que para el análisis es `mixed`.
    Arreglado hacia `0` porque es lo que reciben los clientes casi siempre —
    medido: con `EMULATE_PREPARES` en false, MySQL devuelve `int`—, y fijado por
    el viaje de ida y vuelta en `BanderasDeUnBitTest`.
  - **El generador de columnas tiraba el `NOT NULL`.** `tools/columnas-en-los-modelos.php`
    leía el tipo de cada columna y descartaba el resto de la línea, así que los
    47 modelos con columnas nulables las anotaban como obligatorias. Arreglado en
    la herramienta, no en los modelos.
  - **Un `[0]` sobre el entero que devuelve `DB::update()`**, dentro del bucle
    del importador: un warning de PHP por cada alumno actualizado de cada
    importación, en la operación más lenta que tiene la API.

  Y una cosa que no había pasado antes: **el nivel 3 no dejó ninguna excepción
  nueva** en `phpstan.neon`. El 1 dejó once y el 2 tres, todas endpoints rotos
  que esperan una decisión; los hallazgos del 3 o tenían arreglo claro o eran
  anotaciones que mentían.

- **Larastan del 3 al 4 — cerrado el 20 ago 2026.** Medido al empezar: 55
  errores, todos de la familia «esta condición no decide nada» y «esta rama no
  se ejecuta». Acertó el pronóstico: es donde estaban los fallos. Se arreglaron
  primero los que tenían arreglo claro, quedaron 30 mecánicos, y se cerraron
  así: **24 borrados o simplificados, 1 reescrito sin cambiar comportamiento y
  5 anotados en `phpstan.neon`** con su motivo y su `count`.

  Los cinco que se quedan no son pereza, y merece la pena el porqué de cada uno
  —está entero en [05 §12](05-codigo-muerto-y-roto.md), aquí va en una línea—:
  en tres de ellos **la línea que sobra es la única pista de lo que se
  pretendía** (el `$alumnos[$i]` suelto de `Definitivas`, el `return $user`
  de `aplicacion-descargas/detailed`, el cuerpo 2.x de `simat/alumnos-exportar`
  con las instrucciones de la plantilla del SIMAT dentro), y en los otros dos
  hay una decisión que dice que se queden (el `$todos_anios = true` de §11.2, y
  el `if` que protege el `$cantidad_pregs = 4` de las actividades, que es el
  guardia que hará falta el día que ese 4 se sustituya por un `COUNT(*)`).

  Y lo que se llevó por delante, que es el hallazgo del cierre: los tres
  `Request::input('year_selected') == true || ... == 'true'` de los informes
  **no se escribieron muertos, murieron con el salto a PHP 8**. En PHP 7 la
  rama derecha atrapaba los valores falsy, porque `0 == 'true'` valía true; en
  PHP 8 ya no. Un cliente que mandara `year_selected=0` recibía el año
  seleccionado antes de la migración y el actual después, sin que nadie
  cambiara una línea. Es el mismo patrón que los `tinyint(1)` del nivel 3: el
  analizador no encuentra código muerto, encuentra **cambios de comportamiento
  del salto de versión** que llevaban ahí sin mirar.

  Lo que salió, que es la razón de haberlo empezado:

  - **Cambiar la contraseña borraba el correo de recuperación**, en los
    dieciséis colegios y ahora mismo. Dos `if` escritos
    `has('x') || has('x') == ''`, que son siempre ciertos porque `false == ''`
    vale true en PHP. Uno asignaba `null` al correo cuando el cliente no mandaba
    el campo —y el front nunca lo manda, comprobado en `UserConfiguracionCtrl.js`—;
    el otro, el de `oldpassword`, resultó ser **lo único que defiende el
    endpoint**: al ser siempre cierto, la contraseña antigua se comprueba
    también cuando no la mandan. Arreglados los dos y fijados en
    `CambiarPasswordTest`.
  - **Doce `abort()` inalcanzables** en los `forcedelete` y `restore` de la
    papelera: `findOrFail()` ya lanza, así que el `else` prometía un 400 que
    nunca ocurre —y en dos ficheros con un código distinto del de al lado para
    el mismo caso—. Lo que devuelven de verdad es el 404 de `findOrFail`, que
    además es el correcto.
  - **Dos que esperaban decisión, y Joseth las decidió el mismo día** — en
    [05 §11](05-codigo-muerto-y-roto.md), con el análisis entero por qué se
    esperó:
    - El `case 'Profesor' or 'Usuario':`, que es `case true`, y cuyo error de
      escritura era lo único que impedía que un alumno viera las asignaturas del
      profesor con su mismo id (34 alumnos en la base de desarrollo, uno con 92
      ajenas). Con la regla puesta —**un alumno o acudiente solo alcanza
      asignaturas de su grupo o de todos sus grupos**— el `switch` queda escrito
      como se pretendía y la consulta ajena se retira. Sigue abierto si esa
      pantalla debe enseñarle sus asignaturas de verdad, que Joseth dejó fuera a
      propósito.
    - El `$todos_anios = true` fijado a mano: **se queda**. Que un profesor vea
      a todos los estudiantes del plantel sin importar el año está bien, así que
      no era un descuido pendiente de revertir; lo que faltaba era tenerlo
      escrito.
  - **Y de esa misma decisión salió lo que no se estaba mirando:** los
    buscadores `alumnos/personas-check` y `alumnos/documento-check` iban sin más
    guard que `auth.token`. Un alumno obtenía 61 compañeros con nombre y foto, y
    51 **con su número de documento**; un acudiente, lo mismo. Ahora son
    `auth.personal`, fijado por `BuscadoresDePersonasTest` — [05 §11.3](05-codigo-muerto-y-roto.md).
    Queda la mitad del front: la caja de búsqueda del `sidebarMenu` se pinta sin
    `ng-if` y un alumno la ve.

  **Y el aviso que dejó escrito el 3 se cumplió al pie de la letra**: las
  excepciones del 4 no se podían poner antes de subir el nivel, porque el
  analizador avisa de las que no llegan a usarse y habrían roto el análisis del
  3. Van con `count`, como todas. El mismo mecanismo mordió en el cierre por el
  otro lado: la sesión del PIAR arregló los `$document` de dos controladores de
  Piars y **eso dejó sin casar las dos entradas que los documentaban**, con lo
  que el análisis se puso en rojo sin que ninguno de los dos hubiera tocado
  `phpstan.neon`. Es lo que hace ese `count`: cuando el fallo se arregla de
  verdad, la anotación grita en vez de callarse.

  Y una cosa aprendida sobre el seed, que vale para todo lo que venga: **la base
  de tests no puede demostrar los fallos que dependen de que dos numeraciones se
  crucen**, porque copia un solo grupo de alumnos y ahí los ids de alumno y de
  profesor no se solapan. El candado del `switch` se intentó escribir y se tiró:
  habría pasado siempre, dijera lo que dijera el código.
- **Larastan del 4 al 5 — cerrado el 20 ago 2026.** Medido al empezar: **45
  errores**, el número más bajo de todas las subidas —el 1 encontró 341, el 2
  465, el 3 61, el 4 55—. Y aun así trajo el fallo más caro de la serie, que es
  lo que hay que recordar de este nivel: **el número de errores no dice nada del
  tamaño de lo que hay dentro**.

  El 5 comprueba los argumentos. La mayoría de lo que encuentra son cadenas
  donde se espera un entero, y PHP las convierte solo: 31 de los 45 eran eso
  —22 `abort('400', …)` y tres `Carbon::createFromDate('2010','08','05')`
  copiados— y funcionan hoy, comprobados en el contenedor. Se hicieron
  explícitos y ya. Otros cinco eran relaciones Eloquent con la sintaxis de
  Laravel 4 (`hasMany('Alumno')`, sin namespace) que no llamaba nadie, borradas.

  **Y una era `count()` sobre un Builder, que no se convierte: es un TypeError.**
  De ahí salieron los dos hallazgos, y salieron juntos porque se tapaban el uno
  al otro — [05 §13](05-codigo-muerto-y-roto.md):

  - **`DELETE api/images-users/destroy/{id}` borraba la imagen y después
    respondía 500.** El `count()` está en la última línea del método: cuando
    revienta, el archivo ya no está en el disco, la fila de `images` está marcada
    y las cinco referencias —alumnos, profesores, acudientes, usuarios y años—
    puestas a `null`. El cliente recibía un error de una operación que sí había
    ocurrido, y quien reintentara vería el 404 del `findOrFail`, que parece otro
    fallo distinto. En PHP 7 ese `count()` era un warning que devolvía 1: es el
    tercer cambio de comportamiento del salto de versión que encuentra el
    analizador, después de los `tinyint(1)` del 3 y los `== 'true'` del 4.

    El bloque buscaba `change_asked.oficial_image_id`, una columna que no existe
    en ninguna de las 90 tablas — las buenas son cuatro y están en
    `change_asked_data`. **Lo que pretendía sí hacía falta, y Joseth lo decidió
    el mismo día: se borra la petición**, no se pone su referencia a `null`. Una
    que pide cambiar la foto por una imagen que ya no está no es una petición a
    medias, es una que solo se puede rechazar. Se borra como lo hace
    `putDestruir`, en las tres tablas y en una transacción. El efecto que no se
    ve venir —que una petición es una por usuario y año, así que arrastra el
    cambio de asignatura que viajara dentro— tiene su propio test para que no
    sea una sorpresa.

  - **Y detrás, un alumno borrando la foto de cualquiera.** La ruta llevaba
    `persona.propia` desde la revisión de IDOR y el guard **no miraba nada**:
    recoge los identificadores por su NOMBRE, y esta es la única ruta de imagen
    que llama `{id}` a lo que sus cuatro hermanas llaman `{imagen_id}`. Un alumno
    borraba la foto de un profesor —o el logo del colegio, que vive en
    `years.logo_id`— y recibía el 500 de arriba con el borrado ya hecho.

    Es el **tercer punto ciego de la misma familia**, después de los buscadores
    de [05 §11.3](05-codigo-muerto-y-roto.md) y del inventario de
    [08 §4](08-revision-idor.md), y los tres caben en una frase: *el guard estaba
    puesto y la pregunta era otra*. Aquí no era «¿tiene guard esta ruta?» —lo
    tenía— sino «¿el guard reconoce lo que esta ruta llama id?».
    **`inventario-autorizacion.py` no contesta esa**, y esta sí es mecánica:
    comparar el nombre del parámetro de cada ruta con las claves que busca su
    middleware. **Se escribió como test y no como herramienta** —decisión de
    Joseth el mismo día—, porque así corre con los otros y no depende de que
    alguien se acuerde de lanzar un script: son los dos últimos de
    `AutorizacionTest`, leen las claves del propio middleware por reflexión, y el
    primero se comprobó al revés devolviendo la ruta a `persona.propia` a secas.
    Lo que siguen sin ver son las claves que viajan en el cuerpo: eso no tiene
    atajo estático y hay que golpearlo.

  Lo que queda anotado en `phpstan.neon` son seis errores que son un solo fallo
  contado tres veces: los tres endpoints del importador con la firma de
  maatwebsite 2.x. **El tercero —`GET api/importar/modificar/{year}`— no estaba
  en ninguna lista hasta este nivel**, y no salió antes porque el muestreo de la
  P2 golpeaba lecturas sin parámetro y esta lleva `{year}`. Es la contraria de la
  lección de la §8: allí, lo que no se golpea no se sabe si funciona; aquí, lo
  que no se puede golpear a veces se puede leer.

- **El barrido de lo que sale, hecho el 20 ago 2026.** Las herramientas de
  autorización preguntan todas por la petición —qué identificador viaja, qué
  guard lo mira—. Golpear las 121 lecturas con el token de un alumno y mirar si
  en la respuesta salía el dato personal de alguien encontró **siete rutas** que
  no nombran a nadie y devuelven a todo el mundo: la planilla SIMAT del colegio
  entero, el directorio de las 2.279 personas, la hoja de vida de los 47
  docentes. Cerradas con `auth.personal` y fijadas por catorce casos de
  `SuperficieDeUnAlumnoTest`; las tres que no se pueden cerrar sin romper una
  pantalla de familia están arriba, en la tabla del §5. Todo el detalle en
  [05 §14](05-codigo-muerto-y-roto.md).

  Lo que hay que recordar de esto no es el número: es que **la medición del
  resultado encuentra lo que la medición de la petición no puede ver**, y que era
  el mismo criterio que ya hacía útiles a los tests de contrato, sin aplicar a la
  autorización. Y no está agotado: el barrido solo miró **lecturas**, y solo con
  token de alumno.

- **El barrido de las escrituras, hecho el 20 ago 2026.** La otra mitad del
  anterior, y con la pregunta cambiada: no qué código responde una ruta sino **si
  llegó a escribir**, que aquí no es lo mismo porque este proyecto lee con `PUT`.
  Medido escuchando las consultas: de 417 escrituras, 133 llegaban al controlador
  con token de alumno y **27 cambiaban datos**. Entre ellas, ponerle la
  contraseña a todos los alumnos de un grupo, los seis interruptores de la
  elección del colegio, y quedarse con la imagen de otro —que no es una fuga sino
  una escalada: hecha suya, los demás guards ya la dan por suya—. Cerradas, más
  veinte casos nuevos en `SuperficieDeUnAlumnoTest`. Detalle en
  [05 §15](05-codigo-muerto-y-roto.md).

  De paso corrigió al barrido de lecturas del mismo día, que **solo había mirado
  las GET**: el fichero de acudientes se lee con `PUT` y por eso no había salido.

  **Y el barrido se quedó**, que es lo que permite retomarlo:
  `tests/Barrido/SuperficieDeUnTokenTest.php`, fuera de la corrida normal, con
  el tipo de usuario en `BARRIDO_TIPO`. Reproduce las dos medidas —qué sale y
  qué escribe— y afirma una sola cosa: que su mapa de identificadores cubra
  todos los parámetros de las 539 rutas. Un barrido que se encoge en silencio
  sería peor que no tenerlo.

  Sigue fuera de su alcance lo que un alumno sí puede escribir pero sobre lo de
  otro sin que el guard pueda verlo, como fue el caso del muro: eso no lo
  encuentra un barrido, lo encuentra leer el controlador.

- **El barrido del acudiente, hecho el 20 ago 2026 — y el barrido, arreglado.**
  El acudiente **no encontró ningún agujero**: alcanza dos rutas más que el
  alumno y las dos le devuelven lo de su acudido, que es la regla. Lo que sí
  encontró la segunda pasada fue **tres fallos del propio barrido**, y por eso
  están aquí y no solo en [05 §16](05-codigo-muerto-y-roto.md):

  - **Imprimía menos de lo que contaba** —una respuesta de archivo vacía el
    buffer de salida al enviarse—, y las seis líneas que se perdían eran siempre
    las primeras. O sea las mismas seis en las medidas de la §14 y la §15.
  - **Pedía en el año equivocado**, porque el login reescribe `users.periodo_id`
    y el barrido elegía los identificadores con la fila leída antes de entrar.
    Es la trampa que `tokenDelPersonalDe()` lleva documentada desde la P1.
  - **36 rutas no se estaban midiendo.** El seed tiene dos grupos y el sujeto de
    siempre está matriculado en los dos, así que no había ningún grupo ajeno y
    boletines, planillas, observador y certificados **de otro grupo** se pedían
    con un cero. Arreglado eligiendo un sujeto que deje uno libre: las 34 de
    grupo dan 403. Para el acudiente no hay sujeto posible en este seed, y el
    barrido lo imprime en vez de callárselo.

  Y lo que se aprendió, que es lo que hay que llevarse: **hay una tercera
  categoría que este detector no mide**. Su criterio de fuga son los datos
  personales y las escrituras, y entre las dos cabe *lo del colegio que no es de
  nadie en particular*: `unidades/trashed` le devolvió a un alumno 29 KB con la
  papelera académica del colegio y el barrido la vio pasar. De ahí salieron un
  GET que escribía, cuatro papeleras sin guard —dos de ellas devolviendo alumnos
  borrados con su documento— y un 500 que era un 404. Todo en
  [05 §16](05-codigo-muerto-y-roto.md).

- **La hermana que se quedó sin el guard, hecho el 20 ago 2026.** Los cinco
  agujeros de la §16 tenían la misma forma —ser la única de su familia sin
  guard—, y eso es mecánico. Está en `AutorizacionTest` como test y no como
  herramienta, por lo mismo que el candado de los identificadores: así corre con
  los demás. Las excepciones legítimas van en `EXCEPCIONES_DE_FAMILIA` con su
  motivo, y un segundo test impide que la lista solo crezca.

  Las 27 que marcó estaban todas explicadas. **Lo que no lo estaba fue lo que
  enseñó su gemelo**, el snapshot `guard-por-familia`: de 95 familias, doce no
  tienen ningún guard, y por eso la regla no las mira — no hay hermana con la que
  comparar. Nueve son correctas; las otras tres eran
  [05 §17](05-codigo-muerto-y-roto.md):

  - **`promovidos/calcular-grupo` escribe `matriculas.promovido`** —si el alumno
    pasa el año— de cualquier grupo nombrado en el cuerpo, y devuelve 331 KB con
    sus notas. Es lo más caro de toda la serie, y el barrido no podía verlo
    porque golpea con el cuerpo vacío.
  - **La cartera entera**, que no mira el token ni una vez: los deudores del
    colegio con su documento y su deuda, cualquier grupo, y el Excel de deudores
    sin parámetros. El barrido falló aquí por sus dos mitades a la vez — dos
    piden por el cuerpo y la tercera devuelve un `xlsx`.
  - **`buscar/por-nombre` y `buscar/por-apellido`**, los otros dos buscadores de
    la §11.3: 49 compañeros a cualquier alumno. Y con el texto **interpolado en
    la consulta** — que no hace falta un atacante para verlo, basta un alumno
    apellidado O'Brien: 500.

  Lo que queda de esto para lo que venga: **cada herramienta de la serie
  encuentra lo que las anteriores no pueden ver.** El inventario mira la
  petición, el barrido el resultado, y ésta la forma de la tabla de rutas — que
  es lo único que ve las que no reciben identificador y las que solo escriben
  con el cuerpo lleno.

- **Rector**, configurado y sin correr: por carpeta y revisando cada diff.
- **FormRequests**: hay 2 validaciones en 32.000 líneas. Cada endpoint que se
  toque estrena la suya.
- **Renombrar los métodos** `getIndex` → `index`. Cosmético, y ahora hay tests
  que lo harían seguro.
- **`User::$nota_minima_aceptada`**, la última estática mutable. La leen 26
  sitios del cálculo de notas, que el §5 del plan protege.

- **El cuerpo lleno, hecho el 20 ago 2026.** Lo que la entrada anterior dejaba
  pendiente. El barrido manda ahora los mismos identificadores ajenos con los
  nombres que usan los cuerpos, todos a la vez. Dos efectos inmediatos:
  `images-users/move-img-to-me` **dejó** de aparecer —con el `img_id` ajeno en el
  cuerpo, `persona.propia` lo corta; antes pasaba porque el cuerpo iba vacío y el
  guard entendía «lo mío»— y salió el módulo de votaciones entero.

  De sus cinco familias, **la única ruta con guard era `destroy/{id}` en todas**,
  que es el patrón de la §15 sin una variación. Un alumno creaba votaciones,
  creaba y editaba los cargos, inscribía de candidato a cualquier `user_id`, y
  leía el censo con los datos personales de todos y **a quién votó cada uno**, más
  los 52 KB de `VtVoto::all()`. Catorce cerradas — [05 §18](05-codigo-muerto-y-roto.md).

  Lo que hay que recordar de esta pasada es **cómo se comprobó que no se rompía
  nada**, porque cerrar catorce rutas de un módulo a ojo es dejar sin elecciones a
  dieciséis colegios. Primero el front: `VotacionesInicioCtrl` manda a un alumno o
  acudiente a la pantalla de votar y a admin o profesor a la de configuración, y
  la de votar llama a dos endpoints. **Pero eso es leer.** Así que hay además un
  test que monta una elección de verdad y vota con un token de alumno de punta a
  punta, comprobando la fila en `vt_votos` y no el código de respuesta. Se
  verificó al revés de las dos maneras de romperlo, cerrando `votos/store` y
  cerrando `en-accion-inscrito`: falla con cada una.

  Y el candado de la §17 se ganó el sueldo el mismo día: al cerrar el módulo, las
  tres del flujo de votar pasaron a ser «la que se quedó sola» y el test falló.
  Es para lo que está — no dice que estén mal, dice que hay que decidir.

  Lo que sigue sin cubrir: el barrido manda **todas** las claves a la vez, así que
  una ruta que lea dos recibe una combinación que puede no casar, y ahí el vacío
  vuelve a no probar nada. Y sigue sin haber forma estática de saber qué claves
  lee un controlador — la lista de nombres del cuerpo se amplía a mano, como el
  mapa de la URL.

- **Las dos familias que quedaban, hechas el 20 ago 2026.** El snapshot por
  familia decía que doce no tenían ningún guard y que nueve estaban bien. **Dos
  de esas nueve no lo estaban** — [05 §19](05-codigo-muerto-y-roto.md):

  - **`POST importar/algo/{year}` es el importador vivo y no llevaba guard.** Un
    alumno sube una hoja y la importación se ejecuta entera a su nombre:
    `completada`, 37 filas, y 37 alumnos, 37 matrículas, 44 acudientes y 44
    parentescos escritos. Es la escritura más grande que ha alcanzado un token de
    familia en toda la serie. Que no crecieran los alumnos es mérito de la
    idempotencia por documento del §1 de este documento, no del guard.
  - **`GET folios/iniciar` numera de golpe las matrículas del año** y no llama a
    `fromToken()` ni una vez.

  Lo que hay que llevarse, porque es lo que decide dónde mirar después:

  - **El barrido mide lo que sabe construir.** Tres sabores del mismo límite, ya
    con nombre: el cuerpo vacío (§17), el `xlsx` de salida que no sabe leer
    (§17), y el archivo de entrada que no sabe mandar (§19). Si mañana aparece un
    endpoint que solo actúa con una cabecera concreta, será el cuarto.
  - **El seed vacío tapa hallazgos, y ya van cuatro**: `unidades_por_defecto`,
    los alumnos borrados, `pazysalvo` y ahora los folios. `folios/iniciar` salía
    en el barrido desde la primera pasada **escribiendo**, y se dejó pasar porque
    afectaba a cero filas. Una consulta que se ejecuta sobre cero filas se
    parece demasiado a una que no se ejecuta.

- **El cuerpo entero, hecho el 20 ago 2026.** La §18 mandaba veinte claves
  escritas a mano; los controladores leen **setenta y ocho**. Y con eso cae la
  frase que aquella dejó escrita —«no hay forma estática de saber qué claves lee
  un controlador»—: no es exacta, pero encuentra la que alguien añada
  escribiéndola, que es como se añaden. El barrido tiene ya por el lado del
  cuerpo el mismo candado que tenía por el de la URL.

  Lo que salió — [05 §20](05-codigo-muerto-y-roto.md): **un alumno respondía el
  examen de otro.** `mis-actividades/seleccionar-opcion` recibe el
  `actividad_resuelta_id` por el cuerpo y no miraba de quién es, así que borraba
  la respuesta del otro y escribía la suya; y `finalizar-actividad` le cerraba el
  examen en mitad de la prueba. Ninguna de las dos puede llevar `auth.personal`
  —responder un examen es lo que hace un alumno— ni `persona.propia` —el
  identificador nombra un intento, no una persona—, así que la comprobación va
  dentro del controlador. Es la forma de la §13.2 vista del revés: allí el guard
  estaba puesto y no reconocía el nombre; aquí no hay nombre que reconocer.

- **El `{id}`, hecho el 20 ago 2026.** Cerrado el cuerpo, quedaba mal medido el
  otro lado de la URL: **85 rutas llevan `{id}` y el barrido les mandaba a las
  ochenta y cinco el mismo número**, el `users.id` del superusuario, porque el
  mapa resuelve por nombre de parámetro y `{id}` es un nombre solo. Contra
  `perfiles/*` era el correcto; contra las otras setenta y tantas era un id de
  otra tabla, y **un 404 por «esa fila no está» se lee igual que un guard que
  funciona**. Casi todas son `DELETE` y `PUT`. Ver [05 §21](05-codigo-muerto-y-roto.md).

  Ahora el `{id}` se resuelve contra la tabla que la ruta nombra de verdad, que
  no es la que dice la URL: `boletines2/destroy/{id}` y las tres de `editnota`
  operan sobre **alumnos**, y `definitivas_periodos` borra de `notas_finales`. Y
  **la papelera se detecta leyendo el método, no el nombre**: `years/destroy/{id}`
  hace `forceDelete()` sobre `onlyTrashed()` —el borrado que arrastra 59 tablas—
  y `years/delete/{id}` es el que manda a la papelera. Con el nombre por
  criterio, la peligrosa se habría golpeado con un año vivo. Es el tercer candado
  del barrido contra encogerse en silencio, después del de la URL y el del cuerpo.

  Lo que salió: **se podía pedir que borraran la foto de otro.** Borrar una imagen
  se pide por dos rutas y son la misma operación; `images-users/destroy/{id}`
  lleva `persona.propia:imagen_id` desde la §13.1 y `myimages/destroy/{id}` —la
  que usan las familias, porque se llama «mis imágenes»— no llevaba ninguna. Con
  una imagen ajena no la borra: el controlador solo mira `created_by` —quién la
  subió— y nunca `user_id` —de quién es—, así que cae en la rama de la petición de
  cambio y deja el id ajeno escrito. Desde ahí lo ejecuta un clic de quien revisa
  peticiones. Alcanzaba también a las imágenes sin dueño, que son las del colegio:
  el logo del año, la firma de un profesor.

  Aquí el arreglo **sí** es el guard y no una comprobación dentro, al revés que el
  del examen: el identificador nombra una imagen y `ExigirPersonaPropia` ya sabe
  de quién es una imagen. Es la misma línea que lleva su ruta hermana.

  Y las otras setenta y dos aguantaron — que no es un hallazgo, pero antes no
  estaba medido.

- **El control, hecho el 20 ago 2026 — y es el hallazgo de método de toda la
  serie.** Las §14 a §21 se apoyan todas en leer «vacío» como «cerrado», y esa
  lectura **es falsa la mitad de las veces**: un silencio puede ser el guard o
  puede ser que los identificadores no nombren nada. Ahora las mudas se repiten con
  un token de superusuario —sin guard que lo pare, mismos identificadores, mismo
  cuerpo—; si tampoco saca nada, el silencio de la primera pasada no prueba nada.
  Cada control va en su savepoint y se deshace, porque son escrituras de verdad
  hechas por quien sí puede hacerlas. Ver [05 §22](05-codigo-muerto-y-roto.md).

  **59 de 106.** Y no son las que dan 403 —ésas ni entran, un 403 sí es un juicio—:
  son rutas por las que un token de alumno pasa y sobre las que el barrido no tenía
  nada que decir. Tres causas, que estaban nombradas por separado sin saber que
  eran la misma: el **desajuste de año** —el sujeto trabaja en 2025 y el único
  grupo ajeno del seed es de 2024, así que las 36 rutas que la §16 dio por cerradas
  pueden no haberse medido nunca—, las tablas vacías de §21.5, y el cuerpo que no
  casa que la §18 dejó escrito sin número.

  Lo que salió del primer vistazo a las cuarenta que no eran límites ya conocidos:

  - **`POST perfiles/store` no crea un perfil: crea un grupo.** Un alumno creaba un
    grupo del colegio en el año en curso; medido, de 2 a 3, con un 201. `PerfilesApi.ts`
    ya tenía anotado que cinco métodos de ese controlador operan sobre grupo y no
    sobre persona; ésta es la sexta y la única que escribe, y las otras cuatro ya
    llevaban `auth.personal`. La §17 otra vez. Invisible al barrido porque lee
    `Request::input('titular')['id']` y el barrido manda `titular_id` plano: el
    índice sobre `null` lanza y el `catch` lo convierte en 422.
  - **`PUT publicaciones/guardar-edicion` reescribe cualquier publicación**, y no
    solo el texto: también a quién se le enseña. La §17 por segunda vez en la misma
    pasada — `putDelete` y `putRestaurar` ya llevaban la comprobación y la edición
    se quedó fuera **porque nombra la publicación `id` y no `publi_id`**. Invisible
    porque sin `publi_para` la petición muere en 500 antes del `UPDATE`.
  - Y una que se queda rota a propósito: **`publicaciones/borrar-comentario` tiene
    sintaxis de JavaScript en PHP** —`$user.persona_id==comentario.persona_id`—, así
    que con el `||` en corto un superusuario borra cualquier comentario y todos los
    demás reciben un 500. No es un agujero, es un botón que no funciona; arreglarlo
    **enciende** una función en los dieciséis colegios, que es decisión y no arreglo.

- **El cuerpo anidado, hecho el 20 ago 2026.** Las 57 no juzgables de la §22
  estaban infladas: el bucle salta los 403 por ser la respuesta correcta, pero
  este legacy rechaza con **400** —y con 401 y 422—, y un 4xx es un juicio igual.
  Descontadas, **57 pasan a 14**; 47 de las mudas eran rechazos con el código
  equivocado, que se quedan como están porque la regla es que el legacy no se
  toca. Con catorce se mira una por una, y salió la causa común.

  **El barrido manda números planos y esos controladores leen objetos**:
  `Request::input('titular')['id']`, `$acu['nombres']`, `$grupo_actual['id']`. El
  índice sobre un `int` lanza, la ruta responde 500 y desde fuera se ve una que no
  hace nada. Es la cuarta cara del mismo límite —tras el cuerpo vacío, el `xlsx`
  de salida y el archivo de entrada— y la que más ha escondido. Ahora se golpea con
  **las dos formas**, porque la misma clave se lee de las dos maneras en sitios
  distintos. Cuarto candado, y señaló al escribirlo `encabezado_img_id` y
  `piepagina_img_id`, que se leen como objeto aunque se llamen `_id`.

  Cinco rutas salieron, y **las cinco son la §17 otra vez** — ver
  [05 §23](05-codigo-muerto-y-roto.md):

  - **`POST perfiles/store` no crea un perfil: crea un grupo.** De 2 a 3 con un
    token de alumno.
  - **`PUT publicaciones/guardar-edicion` reescribe cualquier publicación**, y no
    solo el texto: también a quién se le enseña.
  - **`POST acudientes/crear-usuario` crea cuentas** de tipo Acudiente con
    `Hash::make('123456')` y **reapunta `acudientes.user_id`**: si ese acudiente ya
    tenía cuenta, se queda fuera y entra una cuya contraseña conoce quien la pidió.
    Y un acudiente ve lo completo de sus acudidos. La más seria de las cinco.
  - **`PUT acudientes/datos`** devuelve los acudientes del grupo que le nombren con
    documento, teléfono, email y dirección — y la consulta filtra por grupo y **no
    por año**, así que vale cualquiera del colegio.
  - **`PUT matriculas/alumnos-grado-anterior`** devuelve el grupo entero con
    `fecha_nac`, `celular`, `direccion` y `religion`. Sus tres hermanas
    —`matriculas/alumnos-con-grado-anterior` y las dos de `prematriculas`— llevan
    `auth.personal` desde siempre.

  **Y esta última dice algo del candado de la §17 que hay que apuntar:** ese
  candado comprueba que no quede una sola ruta sin guard **en su familia**, y la
  familia `matriculas` tiene muchas con él, así que la que faltaba no estaba sola.
  Sí lo estaba entre sus **hermanas de operación** —el mismo nombre de método en
  cuatro controladores, tres con guard—. Son dos preguntas distintas y hoy solo se
  hace la primera. **La segunda ya tiene candado** —hecho a continuación, el mismo
  día—: agrupa por `Controlador@metodo` en vez de por prefijo de URL.

  Y al escribirlo salió el detalle que lo hace funcionar: **el umbral no puede ser
  el mismo**. En el de familia hacen falta dos hermanas con guard porque compartir
  prefijo es una relación floja; aquí basta una, porque compartir nombre de método
  significa que la operación está copiada y pegada en dos controladores.
  `putAlumnosGradoAnterior` existe exactamente dos veces, así que con el umbral de
  familia el caso que motivó el candado se le habría escapado — comprobado
  quitándole el guard a la ruta: con dos pasa, con uno falla y la nombra.

- **Las once sin juzgar, miradas una por una el 20 ago 2026.** Nueve ya tenían
  sitio —una pública de pre-login, dos que esperan decisión, dos rotas conocidas,
  el muro de publicaciones y las tres del flujo de votar—. Las dos de actividades
  estaban mudas porque `ws_actividades` está vacía, y se midieron **montando la
  actividad**, que es justo la regla que salió al partir la decisión del seed.

  Salió una: **`PUT respuestas/actividad` es la pantalla de corregir del profesor**
  —por cada grupo al que se compartió la actividad, todos sus alumnos con lo que
  contestaron, su `puntaje_manual` y su respuesta a cada pregunta— y no llevaba
  guard. `panel.respuestas` tiene dos entradas en el front y las dos son del autor;
  `actividades/datos`, que abre esa lista, lleva `auth.personal` desde siempre.

  **Y el comentario de la ruta decía lo contrario** —«el lado del alumno es
  `mis-actividades/*` y `respuestas/actividad`»—, que es de donde salió que se
  quedara abierta. Corregido con el guard.

  Se queda rota, y documentada, la otra rama del mismo método: para una actividad
  **no compartida** hace `DB::select('')` con la consulta vacía, así que el
  profesor que abra «Ver resultados» de cualquier actividad de un solo grupo
  recibe un 500 desde que existe la pantalla. Con su test fijando el error. Ver
  [05 §24](05-codigo-muerto-y-roto.md).

  Quedan **diez sin juzgar, las diez con nombre y motivo**. Es el punto en el que
  la serie deja de encontrar por barrido: lo que queda son decisiones.

- **La decisión del seed, tomada el 20 ago 2026: partirla en dos.** El seed vacío
  llevaba seis hallazgos tapados —`unidades_por_defecto`, los alumnos borrados,
  `pazysalvo`, los folios, `ws_actividades` y trece rutas con `{id}`—, y lo que la
  volvió decidible fue ver que las trece se parten en dos mitades con costes
  distintos.

  **Las ocho de papelera van dentro del barrido, y salieron gratis.** Necesitan
  una fila con `deleted_at` puesto, y para eso no hace falta un dato nuevo: vale
  marcar una que ya está. **Preparar el sujeto no es fabricar el efecto** —lo que
  se mide sigue siendo si el token restaura la fila de otro—, así que la
  objeción de «poner al barrido a fabricar lo vuelve turbio» no se le aplica: es
  lo mismo que ya hace al elegir a quién se le da el token. Se presta y se
  devuelve en cuanto se golpea la ruta, porque el seed tiene dos grupos y dejar
  borrado el único ajeno mediría mal otras treinta y seis. Ninguna dio nada, que
  ahora está medido y antes no.

  **Las cinco restantes quedan anotadas y sin hacer, y por un motivo que no se
  sabía al decidir:** el generador del seed no puede traerlas porque **no están
  solo vacías en el seed, están vacías en la base de desarrollo** —las once tablas
  de la familia—. No es ampliar el generador, es fabricar, y eso rompe su
  contrato: «una rebanada de la base real, determinista a partir del id».
  Medido lo que costaría: solo dos snapshots tocan esa familia y los dos están ya
  en `huecos-del-seed.json`, y la forma del examen la escribió la §20. Y medido lo
  que compraría: **nada**, porque las cinco llevan `auth.personal` o comprueban
  dentro. Honestidad de la medición, no hallazgos. Ver [05 §21.5](05-codigo-muerto-y-roto.md).

  El patrón que queda escrito, porque va a volver: **el seed copia un grupo y sus
  datos, y todo lo que un colegio acumula alrededor —papeleras, deudas, exámenes,
  plantillas— llega vacío**, así que un `[]` no distingue «cerrado» de «no había
  nada». La regla con la que se resolvió: si lo que falta es **estado** de una
  fila que ya existe, lo prepara quien mide; si lo que falta es la **fila**, se
  monta en el test que la necesita — y llevarla al seed es una decisión aparte,
  que se toma cuando compre hallazgos y no solo cobertura.
