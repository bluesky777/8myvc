# AUD-5 — el permiso de la auditoría

> **Lote de la noche del 25 ago 2026, sesión `8myvc-48`.** Rama
> `feat/auditoria-permiso`, árbol `.worktrees/48`, base `simonbolivar_testing_48`.
> Abierto con el visto bueno expreso de Joseth, que es lo que la ficha del lote
> exigía antes de tocar nada: **cambia quién ve qué**.
>
> Es la **decisión 3** de [18-auditoria.md](../18-auditoria.md), y son tres cosas a
> la vez: **un permiso por rol**, **sembrado sólo a rectoría y coordinación**, y
> **lo propio se ve siempre sin permiso**.

---

## 0. Lo que había, que es lo que hace que esto no sea cosmético

Las seis rutas viejas de la auditoría iban con `auth.personal` **y nada más**. O
sea: **cualquiera del personal leía el rastro de cualquiera, incluido el de su
rector.** El censo, comprobado ruta a ruta:

| ruta | el id viene de | qué entregaba de un tercero |
|---|---|---|
| `GET bitacoras/{user_id?}` | la **URL** | su bitácora entera (sin id, la tuya) |
| `PUT historiales/de-usuario` | el **cuerpo** (`user_id`) | sus sesiones **y sus intentos de login fallidos** |
| `PUT historiales/sesion` | el **cuerpo** (`historial_id`) | ese ingreso, **con `u.username`** |
| `PUT historiales/nota-detalle` | el **cuerpo** (`nota_id`) | quién cambió esa nota, con nombre |
| `PUT historiales/nota-final-detalle` | el **cuerpo** (`nf_id`) | quién cambió esa definitiva |
| `DELETE bitacoras/destroy/{id}` | la **URL** | **borra** — *no entra en este lote, ver §5* |

`putDeUsuario` era el caso puro del manual: **`$user` se resolvía y no se usaba.**
El `user_id` llegaba del cuerpo y nadie preguntaba de quién era.

---

## 1. Qué se hizo, en tres piezas y ni una más

**1. `App\Support\Autoriza::puedeVerAuditoria()` y `exigirVerAuditoriaDe()`.** El
criterio vive donde ya viven los demás criterios de esta casa. `Autoriza` existe
porque el criterio estaba **copiado a mano en unos controladores y ausente en
otros**; repartirlo otra vez —aunque fuera en un middleware nuevo— sería el mismo
error con otra forma.

> **Y por qué NO es un middleware, que es lo primero que uno pensaría.** Las seis
> rutas reciben el identificador de **tres sitios distintos**: la URL, el cuerpo con
> `user_id`, y el cuerpo con `historial_id` — que **ni siquiera es un usuario**, es
> un ingreso, y de quién es sólo se sabe después de traer la fila. Un middleware
> tendría que saber de qué ruta viene para saber dónde mirar.

**2. La migración `2026_08_25_200000_create_permiso_can_view_auditoria`.** Crea el
permiso **y lo siembra** a `Rector` y `Coord académico`.

**3. Las cinco rutas de lectura**, cada una con su comprobación en el sitio donde
se sabe de quién es el dato.

---

## 2. La decisión que tomé, y se tumba con una fila

**`Coord disciplinario` NO recibe el permiso.**

La decisión 3 dice «rector y coordinación», y en la tabla `roles` hay **dos**
coordinaciones: `Coord académico` (9) y `Coord disciplinario` (8). Quien lleva la
disciplina **no es obviamente** quien puede ver quién cambió una nota, y eso lo
decide el colegio y no una migración. Queda en el lado seguro.

**Dónde se cambia:** una fila en `permission_role` desde la pantalla de roles, sin
migración y sin desplegar nada. O añadiendo el nombre a la constante `ROLES` de la
migración, si se quiere en los dieciséis a la vez.

---

## 3. Por qué esta migración SÍ reparte, al revés que la del Secretario

`create_rol_secretario` creaba un rol vacío **a propósito**, para que el cambio
entrara colegio a colegio. Aquí no se puede, y el motivo es la dirección del
cambio: **este permiso no abre nada, cierra.**

Si la migración no se lo diera a nadie, el día del despliegue **los dieciséis
colegios se quedarían sin la pantalla** — que es literalmente la pregunta 2 de la
ficha del lote, puesta ahí como aviso. Sembrarlo a los dos roles que la decisión
nombra es lo que hace que esto sea **un endurecimiento y no una avería**.

---

## 4. Lo que cambia para un cliente — y esto SÍ va al buzón de los fronts

**Ninguna ruta nueva** (siguen 542), **ningún cuerpo cambia de forma**, ningún campo
se retira. Lo que cambia es **quién recibe 403 donde antes recibía 200**:

| Quién | Qué le pasa |
|---|---|
| Superusuario | **nada**, entra igual que antes |
| Rector, Coord académico | **nada** tras la migración: la reciben sembrada |
| Cualquiera, sobre **su propio** rastro | **nada** — `bitacoras` sin id, sus sesiones, sus ingresos |
| Personal, sobre el rastro **de otro** | **403** donde antes 200 |
| Profesor, en `nota-detalle` / `nota-final-detalle` | **403** hasta que le siembren el permiso |

**La última fila es la que hay que decir en voz alta, y el plan ya la había
avisado.** Hasta hoy esa pantalla la gobernaba `califica`, que tiene **cualquiera
que ponga notas**. La decisión 4 lo escribió así: *«un profesor que hoy entra a
`/panel/bitacora` puede dejar de poder… si el colegio quiere que sigan entrando, la
respuesta no es dejar la pantalla vieja: es sembrar el permiso más ancho.»*

> **Y por qué esas dos no tienen mitad «lo tuyo».** Las otras tres preguntan por
> **una persona**, así que «lo propio siempre» significa algo. `nota-detalle` y
> `nota-final-detalle` preguntan por **una nota** y contestan *quién la cambió, con
> nombre y apellidos*: no hay recorte «lo tuyo» que dejar abierto sin dejar abierto
> el dato entero.

---

## 5. Lo que NO entra, y por qué no es un olvido

- **`DELETE bitacoras/destroy/{id}` se queda como está.** No porque dé igual —hoy
  **cualquiera del personal puede borrar el registro que lo vigila, incluido el
  suyo**, y la §3 del plan ya lo llamaba el cuarto problema del esquema viejo—, sino
  porque **ya está decidido y no es esto**: la decisión 4 dice que **nadie borra** y
  que la ruta **se retira en la fase 7**, con el botón fuera de `mis-sesiones`.
  Colgarla de `can_view_auditoria` sería contradecirlo, y además **borrar la
  auditoría no es verla**. Queda anotado aquí porque entre hoy y la fase 7 el
  agujero sigue abierto.
- **Ninguna ruta que lea la tabla `auditoria`.** No hay ninguna todavía: medido,
  **cero** `FROM`/`JOIN` sobre ella en `app/Http/Controllers/`. Las cuatro rutas
  nuevas son la fase 5.

  > **Y de paso, una precisión sobre la ficha del lote**, que decía *«sus únicos
  > tocadores son `Services\Auditoria` y `Services\Sesion`, y los dos escriben»*.
  > **`Sesion` no toca la tabla: llama a `Auditoria::registrar()`**, igual que
  > `Services\Login`. Quien la toca es **`Services\Auditoria` y nadie más**, que es
  > justo el invariante que la §4.3 del plan puso ahí —*un solo escritor*— y que
  > conviene no desdibujar al citarlo: si «tocador» y «llamador» se cuentan juntos,
  > el día que alguien escriba un `INSERT` suelto no va a destacar.
- **`GET ChangesAsked/to-me` no se toca**, buscada a propósito. Sirve historial de
  sesiones e intentos fallidos en dos de sus cuatro ramas, pero **acotado con
  `h.user_id = $user->user_id`**: lo suyo y nada más. Es una puerta al mismo dato y
  **está bien que siga abierta** — queda escrito para que nadie la cierre de paso.

---

## 6. Cómo se comprobó, que es la mitad que decide si esto vale

**17 casos, las dos mitades en cada ruta**: quién entra y quién no. *Un test que
sólo comprueba el 403 no distingue «la guarda funciona» de «la ruta está rota para
todos».*

**Y la guarda se vio saltar.** Se quitó la línea de `BitacorasController` y el caso
pasó de verde a rojo con `Expected response status code [403] but received 200`. Sin
ese paso, un 403 que viniera de `auth.personal` —o de cualquier otra cosa— se leería
como «mi guarda funciona». Es la lección del gemelo: *la red que decía estar puesta
no lo estaba.*

### Dos trampas de la base que había que esquivar

**1. Lo que siembra la migración NO sobrevive a construir la base.**
`database/dumps/test-seed.sql` hace `TRUNCATE TABLE permissions`,
`permission_role`, `roles` y `role_user` **antes** de insertar, y las migraciones
corren **antes** del seed en `tools/construir-bd-test.sh`. Así que:

- cada test **se fabrica su permiso y su rol dentro de su transacción**, por la vía
  real —permiso → rol → usuario—, que es como llega a `perms` en el contexto;
- y **hay un caso que ejecuta la migración de verdad** y comprueba a quién reparte.
  Sin él, **el reparto que decide la decisión 3 no lo ejecutaría ninguna suite**: el
  fichero se carga al construir la base y su efecto se borra acto seguido. Es el
  §5.bis del briefing aplicado a una migración — *si tu entregable no lo ejecuta
  ninguna suite, ése es el primer hallazgo de tu lote.*

**2. El `null` del cuerpo tenía que caer del lado de «otro».** Cuatro de las seis
rutas reciben el id por el cuerpo, y un cuerpo sin esa clave llega como `null`. Si
`null` contara como «es lo suyo», **bastaría con no mandar el campo** para saltarse
la comprobación entera — el mismo agujero con otra forma. Tiene su caso:
`test_un_cuerpo_sin_user_id_no_se_cuela_como_propio`.

---

## 7. Lo que este lote deja abierto

- **`Coord disciplinario`** (§2), que es una fila.
- **`DELETE bitacoras/destroy`** hasta la fase 7 (§5).
- **La pregunta grande sigue siendo grande.** La ficha del lote avisaba de que el
  permiso de la auditoría dejó de ser una decisión aislada: con `GET profesores` y
  sus hermanas sirviendo la ficha del profesorado a cualquier docente, y con que
  **el rol no cambia nada en lectura de fichas** —las mismas 52 rutas y las mismas
  17 proyecciones, [FICHAS-1](fichas-1.md)—, cerrar la auditoría **no cierra el
  resto**. Este lote hace lo suyo entero y **no pretende contestar las casillas
  `8bis`–`8quater`** de la lista de la mañana.

---

## 8. Cuatro tests que ya existían tuvieron que cambiar, y dos eran el punto

La suite entera dio **cuatro rojos**, y ninguno era un fallo: los cuatro eran
código de prueba que describía el mundo de antes. Se listan porque **cuál cambia y
por qué es la mitad del valor de este lote**.

**Los dos que estaban ahí justamente para esto:**

| Test | Lo que decía |
|---|---|
| `BitacorasTest::test_cualquiera_del_personal_lee_el_rastro_de_otro` | *«se mide y se fija; **quién puede leer el rastro de quién es decisión del colegio**»* |
| `QuienDecideDeQuienEsUnAlumnoTest::test_cualquiera_del_personal_lee_el_historial_de_otro` | *«**quién puede leer el rastro de quién sigue abierto en las dos**, y ahora está medido en las dos»* |

**Esto es el sistema funcionando como se diseñó.** Los dos casos no comprobaban que
algo estuviera bien: **fijaban un agujero medido mientras esperaba una decisión**,
que es la regla de la casa —*sin ruta y roto se borra; con ruta y roto se
documenta*— aplicada a la autorización. Cuando la decisión llegó, **los dos se
pusieron rojos solos y señalaron los dos sitios exactos.** No hizo falta acordarse
de ellos.

**Se invierten, no se borran**, y conservan dentro la frase que decían antes. Un
caso que desaparece se lleva consigo el motivo por el que existió; uno invertido
deja escrito que **lo que cambió fue la respuesta, no la pregunta**. Y los dos
conservan su mitad de «con permiso sale, y sale con contenido»: *un 403 a secas no
distingue «la guarda funciona» de «la ruta está rota para todos»*.

**Los otros dos eran daño colateral, y se arreglan distinto cada uno:**

- `test_borrar_una_bitacora_la_saca_del_listado` leía el listado **de otro** como
  parte del montaje. Lo que mide es que borrar saque la fila del listado, no quién
  puede leer — así que pasa a usar **la propia**. *Sembrarle el permiso habría
  metido en ese test una condición que no es la suya*, y el día que la guarda se
  rompiera él también se pondría rojo sin tener nada que ver.
- `test_borrar_la_bitacora_no_borra_quien_cambio_la_nota` llama a
  `historiales/nota-detalle`, que ahora pide el permiso. Aquí **sí** se le siembra:
  lo que mide —que borrar la bitácora no borre el rastro de la nota— necesita
  llegar a esa ruta, y no hay forma de llegar sin el permiso.

> **La regla que sale de los dos últimos, y no es la misma para los dos:** cuando
> una guarda nueva pone rojo un test que no va de guardas, hay que preguntarse si el
> test **necesitaba** ese privilegio o sólo lo estaba usando de paso. Si lo usaba de
> paso, se le quita la dependencia; si lo necesita, se le concede. Concederlo
> siempre es lo cómodo y es lo que convierte una suite en una que no puede volver a
> encontrar el agujero.

Y el ayudante que los tres necesitan —`darPermisoDeAuditoria()`— vive en
`CasoDeContrato` y **monta permiso, rol y asignación por la vía real**, no
inventando una columna: es como el permiso llega a `perms` en el contexto. Un atajo
ahí comprobaría un camino que en producción no existe.
