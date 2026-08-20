# Lo que queda, y lo que ya se sabe de cada cosa

Las fases 0–6 están cerradas y el plan de rendimiento también, salvo lo que hay
aquí. **Ninguna de estas es trabajo que falte por hacer sin más: cada una está
parada por algo concreto**, y lo que vale de este documento es ese algo — para
no volver a descubrirlo dentro de tres meses.

Orden: primero lo que decidió Joseth que se hará, después lo que espera una
decisión suya.

---

## 1. La importación de Excel, reanudable

**Estado: acordado, sin fecha** (Joseth, 20 ago 2026).

`max_execution_time` está en **300 s** en la cuenta de cPanel, y está así **por
esto**: las importaciones de alumnos tardaban mucho. El objetivo a futuro es
poder **bajarlo**, y para eso la importación tiene que dejar de ser una sola
petición larga.

**Lo que ya se intentó, y es la mitad del diseño.** Joseth puso en su día un
`Log::info` que dejaba anotado **cuál fue el último alumno importado**, para que
si el proceso se rompía y alguien volvía a subir el mismo archivo, no se
recrearan los que ya habían entrado, sino que continuara desde ahí. La intuición
es la correcta —lo que hace falta es un punto de control— y lo que le faltaba
era dónde vivir: un `Log::info` no se puede consultar desde el código, se borra
con la rotación y no distingue un colegio de otro.

Por dónde ir cuando se retome:

- **El punto de control en la base, no en el log.** Una fila por importación
  —archivo, año, última fila procesada, estado— que el propio importador lea al
  empezar. Con eso «reanudar» deja de depender de que alguien lea un log.
- **Idempotencia por la clave natural.** Reanudar bien no es solo saber dónde se
  quedó: es que volver a procesar una fila ya procesada no cree un duplicado. El
  documento del alumno es esa clave, y el importador ya lo usa en otros sitios
  (`UPDATE alumnos SET ... WHERE documento=?`).
- **Por lotes.** Leer y guardar de N en N deja el pico de memoria plano y hace
  que el punto de control signifique algo. El `memory_limit` es de 768M, así que
  el problema no es la memoria: es el tiempo.
- **Medirlo antes.** Cuánto tarda de verdad una importación hoy no lo sabe
  nadie: se dice que «tardaba mucho». `CONSULTAS_LENTAS_MS` en el colegio que
  más importa lo dice en una temporada, y de paso dice si hay **otro** endpoint
  apoyado en esos 300 s —que es la pregunta que quedó abierta— antes de bajarlos
  y romperlo.

Los dos importadores son `Alumnos\ImportarController::postAlgo()` (alumnos, por
año) y `::postCartera()`. El tercero, `GET api/importar`, está roto por otra
razón desde hace años: usa la firma de `maatwebsite/excel` 2.x
([§8](05-codigo-muerto-y-roto.md)).

> Esto y las colas (§3) son el mismo problema visto desde dos sitios, y la
> versión reanudable es la barata: no cambia el contrato con los cuatro
> clientes.

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

## 4. Lo que espera una decisión del colegio

| Qué | Dónde está descrito | Qué falta decidir |
|---|---|---|
| Cuatro endpoints rotos desde siempre | [05 §6.5, §7.2, §8, §9.2](05-codigo-muerto-y-roto.md) | qué debe devolver cada uno; en dos de ellos, si la operación debe existir |
| La estructura de roles y permisos | [06 §4](06-autorizacion.md) | si los roles de la base se quedan y se pueblan, o se borran las cuatro tablas |
| 12 rutas de catálogo sin guard | [08](08-revision-idor.md) | a quién se abren; no exponen a nadie, pero no están decididas |
| `APP_DEBUG` en producción | [01](01-plan-seguridad.md) | comprobarlo colegio a colegio. `display_errors` de PHP está en Off, así que la mitad del riesgo ya está cubierta |
| Los correos `username@myvc.com` autogenerados | [01](01-plan-seguridad.md) | dos usuarios que compartan correo comparten reseteo de contraseña |

---

## 5. Continuo, sin final

- **Larastan del 2 al 3.** Cada subida de nivel ha encontrado endpoints rotos de
  verdad: 21 en el 1, cuatro en el 2.
- **Rector**, configurado y sin correr: por carpeta y revisando cada diff.
- **FormRequests**: hay 2 validaciones en 32.000 líneas. Cada endpoint que se
  toque estrena la suya.
- **Renombrar los métodos** `getIndex` → `index`. Cosmético, y ahora hay tests
  que lo harían seguro.
- **`User::$nota_minima_aceptada`**, la última estática mutable. La leen 26
  sitios del cálculo de notas, que el §5 del plan protege.
