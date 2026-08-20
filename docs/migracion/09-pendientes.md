# Lo que queda, y lo que ya se sabe de cada cosa

Las fases 0–6 están cerradas y el plan de rendimiento también, salvo lo que hay
aquí. **Ninguna de estas es trabajo que falte por hacer sin más: cada una está
parada por algo concreto**, y lo que vale de este documento es ese algo — para
no volver a descubrirlo dentro de tres meses.

Orden: primero lo que decidió Joseth que se hará, después lo que espera una
decisión suya. Lo que se cierra **se deja aquí**, no se borra: el porqué de cada
desvío respecto al plan es justo lo que no se puede reconstruir después.

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
| Cuatro endpoints rotos desde siempre | [05 §6.5, §7.2, §8, §9.2](05-codigo-muerto-y-roto.md) | qué debe devolver cada uno; en dos de ellos, si la operación debe existir |
| La estructura de roles y permisos | [06 §4](06-autorizacion.md) | si los roles de la base se quedan y se pueblan, o se borran las cuatro tablas |
| 12 rutas de catálogo sin guard | [08](08-revision-idor.md) | a quién se abren; no exponen a nadie, pero no están decididas |
| `APP_DEBUG` en producción | [01](01-plan-seguridad.md) | comprobarlo colegio a colegio. `display_errors` de PHP está en Off, así que la mitad del riesgo ya está cubierta |
| Los correos `username@myvc.com` autogenerados | [01](01-plan-seguridad.md) | dos usuarios que compartan correo comparten reseteo de contraseña |

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

- **Larastan del 3 al 4.** Medido el 20 ago 2026: **55 errores**, y todos de la
  misma familia —14 `if.alwaysTrue`, 9 `deadCode.unreachable`, 7
  `booleanAnd.rightAlwaysTrue`, 5 `booleanOr.rightAlwaysFalse`—. O sea
  condiciones que no deciden nada y ramas que no se ejecutan nunca, que es
  exactamente la forma del `$user->is_superuser && $user->is_superuser` que
  destapó el nivel 2. Es el nivel con más pinta de encontrar fallos de verdad de
  los que quedan, y no está parado por nada: es trabajo.
- **Rector**, configurado y sin correr: por carpeta y revisando cada diff.
- **FormRequests**: hay 2 validaciones en 32.000 líneas. Cada endpoint que se
  toque estrena la suya.
- **Renombrar los métodos** `getIndex` → `index`. Cosmético, y ahora hay tests
  que lo harían seguro.
- **`User::$nota_minima_aceptada`**, la última estática mutable. La leen 26
  sitios del cálculo de notas, que el §5 del plan protege.
