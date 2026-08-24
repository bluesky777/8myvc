# AUD-2 — la sesión atada al token

> **Lote de la noche del 25 ago 2026, sesión `8myvc-9a`.** Rama
> `feat/auditoria-sesion-del-token`, árbol `.worktrees/9a`, base
> `simonbolivar_testing_9a`. Estaba reservado a `8myvc-d2`, que lo midió y ya no
> está: su medición se usa, su reserva no.
>
> Es la **fase 2** de [18-auditoria.md](../18-auditoria.md) — la que la fase 3
> estaba esperando para dejar de escribir NULL. `Auditoria` **no se toca**: ya leía
> `sesion_id`/`historial_id` del contexto y ya ponía `atribucion = 'sesion'` cuando
> venían. Lo único que faltaba era que vinieran.

---

## 0. AVISO: ESTE LOTE AÑADE UNA MIGRACIÓN

**`ALTER TABLE personal_access_tokens ADD historial_id`.** Ninguna tabla nueva
—siguen siendo 94— así que **el número de tablas no demuestra que esté aplicada**;
lo que lo demuestra es la columna. Las bases de test de las demás sesiones se
quedan viejas en cuanto esto se funda, y lo que verán es `Unknown column
'historial_id'` con muy buena cara. Se arregla reconstruyendo:

```bash
DB_TEST_DATABASE=simonbolivar_testing_<sufijo> \
PHP_EXEC="docker exec -w /app/.worktrees/<sufijo> -i 8myvc-app-1" \
    tools/construir-bd-test.sh
```

Comprobado aquí leyendo de vuelta `information_schema`: `int unsigned`,
`nullable=YES`, índice `pat_historial` con una columna.

---

## 1. Qué arregla, que no es el caso raro

El token y la fila de `historiales` **se creaban en el mismo login y luego no
volvían a hablarse**. Así que los nueve sitios que escriben `historial_id` lo
resolvían con `order by id desc limit 1` sobre `historiales`: **el último login de
esa persona, no la sesión que está haciendo el cambio.**

**Y no hace falta imaginarse dos aparatos.** El refresco vive **catorce días y
rota en cada uso**, así que quien entra a diario **puede llevar meses sin teclear
la contraseña**: no hay login nuevo, `historiales` no crece, y **todas sus
escrituras de esos meses colgaban del mismo ingreso de hace meses**. La pantalla
«qué hizo en este ingreso» enseñaba una lista falsa sin ningún error visible.

Ahora el ingreso viaja **en los dos tokens** (acceso y refresco), **se arrastra en
cada rotación** —ésa es la mitad que hace que sirva a los catorce días— y llega al
código por el contexto que `auth.token` ya resolvió, **sin ninguna consulta de
más**: `Sesion` devuelve el usuario con `withAccessToken($token)`, o sea que la
fila del token ya está resuelta y `historial_id` viene dentro.

---

## 2. Los nueve sitios — y con qué se contaron, que es lo que hay que decir

**Mi primer barrido dijo ocho.** Fue esto:

```bash
grep -rn -i "from historiales" app/ --include='*.php' | grep -iE "order by id desc|limit 1"
```

Y el noveno **no tiene `FROM historiales`: tiene `UPDATE historiales`.** Es
`LoginController::putLogout`, y es **el único de los nueve que escribe** — los
otros ocho usan esa forma para *leer* un id.

> **Lo que hay que llevarse, y es la regla que este repo ya tiene escrita:** un
> detector que clasifica por la forma superficial pone sitios en el cajón
> equivocado. Aquí el sesgo era mío y del lado peor: **buscar «de dónde se lee»
> encuentra ocho lecturas y se deja la escritura**, que es justo el sitio donde el
> error hace daño visible al usuario (te cierra la sesión del otro aparato).
> Cualquier cuenta de «quién usa el último ingreso» hecha por `SELECT` los deja
> fuera, igual que las cuentas de «qué escribe» por el nombre del método dejan
> fuera los seis `DB::select` que escriben.

El reparto, ya comprobado leyéndolos:

| | Dónde | Qué hacía con el ingreso |
|---|---|---|
| 7 controladores | `NotasController` ×2 · `DefinitivasPeriodosController` ×2 · `SubunidadesController` · `YearsController` · **`LoginController`** | atribuir una escritura, y el último **marcar la salida** |
| 2 middlewares | `ExigirPersonaPropia` · `ExigirBoletinPropio` | anotar un intento **rechazado** |

---

## 3. Tres de ellos escondían un fallo que no era de la auditoría

Los de `NotasController::putUpdate` y los dos de `DefinitivasPeriodosController`
no eran una consulta aparte: eran un **producto cartesiano** dentro de la consulta
que trae la fila.

```sql
SELECT n.*, h.id as history_id FROM notas n,
  (select * from historiales where user_id=? and deleted_at is null order by id desc limit 1) h
 WHERE n.id=? and n.deleted_at is null
```

**Si el usuario no tenía ninguna fila viva en `historiales`, el cruce devolvía cero
filas** y el `[0]` de la línea siguiente reventaba con «Undefined array key 0». O
sea: **la escritura fallaba por no encontrar un INGRESO, no por nada de la fila que
se quería guardar.**

Y en `putUpdate` eso caía en su `catch`, que contesta **422 «No se pudo guardar la
nota»** — un mensaje que apunta a la nota y no a lo que pasaba. En
`YearsController` era peor: el `[0]` estaba **después** del `$year->save()`, así que
el `catch` de abajo contestaba **422 «Datos incorrectos» con el año ya guardado**.

Con el cruce fuera, la consulta pregunta sólo por lo que quiere saber. Tiene su
caso: `test_guardar_una_nota_ya_no_depende_de_tener_un_ingreso`, que borra **todos**
los ingresos del usuario y guarda igual.

> **Esto cambia un comportamiento observable** —una petición que antes daba 422 en
> ese caso ahora da 200 y guarda—, y va en el parte. Es un arreglo, pero es un
> cambio.
>
> Y deja **obsoleto un comentario que no es mío**:
> `tests/Contrato/QuienCambioLaNotaTest.php` explica que *«sin esa fila,
> `putUpdate` no puede ni guardar — su primera consulta cruza `notas` con el último
> historial»*. El test sigue pasando; la frase ya no es cierta. **No lo toco —un
> fichero, un dueño— y queda dicho aquí y en el parte.**

### Y una cosa que el barrido NO iba a encontrar, y encontró el snapshot

`PUT notas/update/{id}` **devuelve la fila**, y la fila salía del cruce con
`SELECT n.*, h.id as history_id`. O sea que **`history_id` viajaba en el cuerpo de
la respuesta** — de rebote, sin que nadie lo hubiera decidido, pero viajaba. Quitar
el cruce se lo llevaba por delante: **un campo retirado a cuatro clientes sin
decírselo.**

No lo vio ninguna lectura del código ni ningún barrido de consultas: **lo cazó el
snapshot `notas-update`**, que existe justamente para eso. La columna se vuelve a
colgar de la fila y la respuesta queda igual.

Lo que sí cambia es **el valor**, y va en el parte: antes era el último ingreso de
esa persona y ahora es el de esta sesión — o `null` mientras el token sea anterior
a la migración.

> **La lección de método, que es la que me llevo:** miré «qué consultas usan el
> ingreso» y «qué escribe cada sitio», y las dos preguntas se dejaban fuera **qué
> sale por el cable**. Una columna que nadie pidió puede estar en un contrato sólo
> por estar en un `SELECT *`.

---

## 4. La decisión que tomé y que se puede tumbar en una línea

`auditoria` tiene **dos** columnas —`sesion_id` e `historial_id`— y este lote
escribe **el mismo número en las dos**. Es deliberado y éste es el razonamiento:

- Son dos preguntas distintas: el **ingreso** (la fila de `historiales`) y la
  **sesión** (la familia de tokens que sobrevive a las rotaciones).
- **Hoy son 1:1.** Cada login crea una fila de `historiales` y una familia de
  tokens, y la familia arrastra ese id en cada rotación. El mismo número contesta
  las dos.
- **Dejar `sesion_id` en NULL costaría dos cosas concretas**: el índice
  `aud_sesion (sesion_id, id)` está **medido con EXPLAIN sobre 200.000 filas** para
  la pregunta «qué hizo en este ingreso» (aud-3 §3), y se quedaría sin usar; y
  `Auditoria` sólo pone `atribucion = 'sesion'` cuando `sesion_id` viene (18 §5.2),
  así que **todas las líneas seguirían diciendo «aproximada» teniendo la
  atribución cierta**.

**Dónde se cambia el día que dejen de ser 1:1:** en `User::atarLaSesion()`, que es
una línea. Está ahí y no repartido por los nueve sitios exactamente para eso.

---

## 5. La ventana de despliegue, y qué se hace en ella

**Los tokens que ya existen no tienen ingreso y no se les puede inventar uno.** Se
quedan en NULL, y NULL se queda:

- **En `auditoria`**, `sesion_id` e `historial_id` van NULL y `atribucion` dice
  `aproximada`. Es la columna que existe para que la pantalla lo **diga** en vez de
  disimularlo. *Un NULL dice «no se sabe»; la adivinanza decía «fue ése» y se
  equivocaba sin avisar.*
- **En `bitacoras`** igual: `historial_id` NULL en vez de un ingreso ajeno.

La ventana dura **lo que dure el refresco más largo vivo — hasta catorce días** y
**se cierra sola**: cada login nuevo emite tokens que sí lo traen, y a quien cierre
sesión y vuelva a entrar le funciona al momento.

**La única excepción, y va razonada:** `putLogout` **conserva la adivinanza vieja**
cuando el token no sabe su ingreso. No es incoherencia — `logout_at` no atribuye a
nadie lo que hizo, así que no marcarlo **pierde el dato** en vez de dejarlo en «no
se sabe». Es una rama de transición, se apaga sola y está marcada como tal.

---

## 6. Lo que cambia para un cliente

**Ningún cuerpo, ningún campo, ninguna ruta, ningún código HTTP nuevo.** Lo que
cambia son **valores**, y son tres:

1. **`bitacoras.historial_id` pasa a ser cierto** en vez de adivinado. La pantalla
   «qué hizo en este ingreso» empieza a enseñar lo de esa sesión y deja de enseñar
   lo de otra. **Esto ya mejora la bitácora vieja sin tocar el front** — lo dice el
   plan, y es lo que hace que esta fase se pueda desplegar sola.
2. **Durante la ventana, `historial_id` puede venir NULL** donde antes venía un
   número (falso). Para esa pantalla eso son filas que dejan de salir.
3. **`historiales.logout_at` se marca en la sesión que se cierra**, no en la última
   de esa persona.

Y el cambio de comportamiento del §3: donde antes había 422 por no tener ingreso,
ahora guarda.

### Dos claves nuevas en el contexto de usuario — y esto sí es un cuerpo

El plan pide que **el contexto exponga `sesion_id`/`historial_id` como expone
`persona_id`**, y el contexto **se serializa entero al cliente**: va en `auth/me`,
en el `usuario` de `auth/login` y en la respuesta de `POST /login`. O sea que
exponerlo en el contexto es, quiera uno o no, **añadirlo al cuerpo**.

**Son dos claves nuevas, enteras, y additivas**: no se quita ningún campo, no se
renombra ninguno y no cambia ningún tipo. Verificado por el diff de las
instantáneas, que es lo único que lo demuestra:

```
6 +    "historial_id": "int",
6 +    "sesion_id": "int",
```

Seis instantáneas y **nada más que esas dos líneas**. Están regeneradas en el
mismo commit.

> **Se podría haber evitado**, quitándolas justo antes de serializar en los tres
> sitios que devuelven el contexto. **No se hizo, y el motivo es el modo de
> fallo**: son tres sitios hoy y el cuarto que alguien añada mañana se olvidaría,
> y se olvidaría **en silencio** — filtrando lo que se quería esconder. Entre dos
> enteros de más en un cuerpo que ya lleva cuarenta columnas y un filtro que hay
> que acordarse de repetir, el filtro es el que falla solo.
>
> Además no son un dato ajeno: son **el ingreso del propio usuario**, que es lo que
> necesita una pantalla de «mis sesiones».

**La forma de `historiales/nota-detalle` y `historiales/nota-final-detalle` no se
toca.** Es lo que la coordinación pidió avisar si cambiaba, y no cambia: ni
columnas ni nombres.

---

## 6.bis La clave foránea que había descartado con un argumento falso

La migración decía, escrito por mí: *«sin clave foránea — una `FK` con `ON DELETE
CASCADE` convertiría limpiar ingresos viejos en cerrar sesiones vivas, y con `ON
DELETE SET NULL` no se gana nada que el nullable no dé ya»*.

**La primera mitad es cierta y la segunda era falsa.** Lo destapó
`GuardarNotasEnLoteTest`, que borra los historiales del usuario para comprobar que
el lote guarda igual: con el ingreso viviendo en el token, borrar la fila deja
**tokens vivos apuntando a una fila que ya no existe**, y el siguiente `INSERT INTO
bitacoras` de esa sesión revienta contra **la FK que `bitacoras` sí tiene** — un
500 al profesor guardando una nota, por una limpieza que hizo otro.

Con `ON DELETE SET NULL` el token **deja de saber de qué ingreso salió**, que es
exactamente lo que ha pasado, y todo lo demás ya sabe tratar ese NULL como «no se
sabe». Cuesta cero en cada petición y convierte el invariante en estructura en vez
de en algo que haya que acordarse de comprobar.

Comprobado leyendo de vuelta `information_schema`:
`pat_historial -> historiales.id, ON DELETE SET NULL`.

> **Lo que me llevo:** descarté la FK mirando **una** de sus reglas de borrado y
> escribí la conclusión como si valiera para todas. El argumento contra `CASCADE`
> era bueno y me sirvió para no mirar `SET NULL`.

---

## 6.ter Los `intento_login` no son todos intentos de entrar

Llega de la coordinación del front, medido sobre la aplicación **desplegada**, y no
se puede deducir desde este repositorio. Queda escrito aquí porque **el día que
alguien mire esa tabla buscando ataques va a necesitar esta línea**:

```
login.html:135   <form ng-submit="$ctrl.login($ctrl.credentials)" name="prematriculaForm">
```

**El `<form>` de la pantalla de PREMATRÍCULA hace `submit` a `login()`.** Un padre
que pulse Enter en cualquier campo en vez de darle al botón llama al login con
usuario y contraseña **vacíos**, y eso entra como un `intento_login` fallido.

> **Parte de los intentos fallidos que hay en `bitacoras` no son intentos de
> entrar: son padres intentando prematricular a su hijo.** Quien cuente esa tabla
> está sumando dos poblaciones distintas, y va a leer como ataque un formulario mal
> cableado.

**No se arregla aquí** —es del front y no está decidido— y este lote no cambia nada
por ello.

### Y la buena noticia: separarlas ya se puede, sin tocar nada

Se preguntó si habría una forma barata de que la fila dijera **de qué pantalla
viene**, sin inventar un campo ni ampliar el vocabulario de `accion`. **No hace
falta ninguna de las dos cosas: ya está separable con lo que la fase 4 escribe.**

El camino, comprobado leyendo los tres sitios:

1. `POST auth/login` **valida** `username` con `required|string`, así que un envío
   vacío es un **422 y no llega a registrarse**.
2. Las rutas viejas —`POST login` y `POST login/credentials`, que son **las que
   llama el front**— **no validan**, así que el username vacío sí llega a
   `Login::entrar()` y se anota.
3. `Auditoria::sinActor('')` pasa por `recortar()`, y `recortar('')` devuelve
   **null**.

O sea que en `auditoria`:

| La fila | Qué es |
|---|---|
| `entidad = 'intento_login'` y `actor_intentado IS NULL` | un envío **sin usuario** — el Enter de la prematrícula |
| `entidad = 'intento_login'` y `actor_intentado` con algo | alguien tecleó un nombre y falló |

**Y no es un efecto colateral afortunado**: `actor_intentado` existe justamente para
guardar *lo que se pretendió y no se pudo comprobar*, así que «no se pretendió
nada» es una respuesta que la columna ya sabía dar.

En `bitacoras` la distinción también está, aunque más torpe:
`affected_person_name` guarda el `$username` crudo, o sea **la cadena vacía** en
vez de NULL.

---

## 7. Lo que no entra

- **No se inventa ningún valor de `Auditoria::ACCIONES`.** `denegado` ya existía
  —lo trajo la fase 3— y los dos middlewares ya lo usan desde AUD-4. Este lote no
  necesita un sexto verbo.
- **No se toca `Auditoria`.** Ni una línea: ya leía las dos columnas del contexto.
  Que la fase 3 se escribiera «sin cablear» es lo que hace que la 2 sea un cambio
  pequeño.
- **No se poda `historiales`.** La retención es la fase 6.
- **`Tardanzas/TLoginController`** tiene su propia entrada y no pasa por
  `Services\Login`; queda como estaba. No escribe `historial_id` en ninguna parte,
  así que no hay nada que atar todavía.

---

## 8. El cierre, medido por otra sesión — `8myvc-48`, 25 ago 2026

Lo de abajo lo corrió el relevo, no quien escribió el lote. Va aquí y no en un
parte aparte porque **el número de cierre pertenece al documento del lote**: quien
lea esto dentro de tres meses necesita saber con qué se midió, no que fue verde.

### Los tres números, con su población

| Qué | Número | Con qué |
|---|---|---|
| Suite **entera**, sin `--testsuite` | **1.479 pasados, 10.556 aserciones**, 0 fallos, 484 s | Unit (8) + Feature (4) + Contrato (193), sobre `3f61bf6`, base `simonbolivar_testing_9a` |
| Larastan nivel 7 | **`[OK] No errors`**, 505 ficheros | sobre `3f61bf6`, o sea **con la FK y este documento dentro** |
| Pint | **PASS**, 299 ficheros | alcance real abajo — no es «el lote formateado» |

**El delta contra el número de la rama cuadra, y por eso el número vale.** Esta
sesión midió antes **1.374 / 10.346** con `--testsuite=Contrato`; la diferencia es
**105 tests y 210 aserciones**, que es exactamente Unit+Feature. Los dos números
son correctos y **miden cosas distintas** — que es justo lo que la [§5.bis del
briefing](../../../../8myvc-cola/noche-2026-08-25/BRIEFING.md) pide no confundir.

### §5.bis: el entregable sí tenía suite, y aun así faltaba una

Este documento se cerró creyendo que incumplía §5.bis. **Lo cumplía a medias ya:**
su test propio, `tests/Contrato/SesionDelTokenTest.php`, vive en `Contrato`.

Lo que `Contrato` sola se saltaba es otra cosa, y ésa sí importaba: la fase 2
cambia **`app/User.php` en 66 líneas**, y el test que guarda el invariante de ese
fichero —que el usuario se resuelva **en la primera lectura y no en el
constructor**, porque un constructor que lo resuelva rompe `route:list`— es
`tests/Unit/UsuarioPerezosoTest.php`. **Ése es el único que no corre con
`--testsuite=Contrato`.**

> **Y el matiz que generaliza:** fuera de `Contrato` **no hay ni un test que nombre
> `historial_id`, `sesion_id` ni `atarLaSesion`**. La cobertura que aporta correr la
> suite entera **no es por nombre, es por fichero** — protege `User.php` de un
> cambio que nadie escribió pensando en ella. Buscar «qué test cubre mi lote» por el
> nombre de lo que toqué habría contestado «ninguno» y habría sido falso.

### «Pint verde» no quiere decir «el lote formateado»

El `composer.json` limita pint a `app/Console`, `app/Http/Middleware`,
`app/Http/Controllers/Concerns`, `app/Mail`, `app/Services`, `app/Support`,
`routes`, `tests` y `database/migrations`. De lo que toca la fase 2, eso **cubre**
los dos middlewares, `Services/Login.php`, `Services/Sesion.php`, la migración y
los tests; y **no cubre** los seis controladores, `app/Models/TokenDeSesion.php` ni
`app/User.php`.

**No es un hueco** —CLAUDE.md decide a propósito que se formatea el día que se toca
cada fichero, porque reformatear los 113 de golpe da un diff ilegible—. Es que la
frase corta «pint verde» se lee como una cobertura que no tiene, y en un lote que
toca seis controladores esa lectura es del 100% de lo que uno cree y del 0% de lo
que hay.

### Lo que el relevo comprobó en vez de creérselo

Las cuatro salieron como decía este documento. Se listan porque **un dato que llega
de otra sesión se verifica antes de reenviarlo**, y porque una de ellas resultó
estar dicha corta.

- **La migración está aplicada de verdad**, leído de `information_schema` y no del
  hecho de que los tests pasen: `historial_id int unsigned NULL` y FK
  `pat_historial -> historiales`. **94 tablas** — que es exactamente lo que el §0
  avisa que NO lo demuestra, y por eso se miró la columna.
- **El `[OK]` de larastan no está comprado**: `phpstan.neon` **no aparece** entre
  los ficheros que toca el lote, así que no hay ningún ignore nuevo sosteniéndolo.
- **Las seis instantáneas son aditivas**, y esto es lo que se le promete al front:
  el diff son **doce líneas y las doce son `+`**, dos claves por fichero. Ni un `-`,
  ni un renombre, ni un cambio de tipo.
- **El detector ciego del §«tres cosas que me costaron» está dicho corto, y en la
  dirección mala.** Los cuatro controladores tienen **9 `Auditoria::registrar()`
  entre ellos y CERO `DB::insert/update/delete/statement`**. O sea que
  `escrituras-sin-auditoria.php` **no los cuenta de menos: no puede verlos**, porque
  sólo cuenta formas `DB::` y ahí no hay ninguna. «32 donde había 52» describe el
  síntoma; la causa es que **cuatro dominios caen fuera de su universo entero**.

### El estado contra `main`, que esta rama no podía mirar

Al cerrar, `main` estaba **18 commits por delante**. `git merge-tree` da **cero
conflictos**. Un solo fichero se toca por los dos lados —
`DefinitivasPeriodosController.php`, por DEF-108 en `main` y por la fase 2 aquí— y
**la limpieza es real y no suerte del diff**: DEF-108 acota una lectura de
`unidades` en `:116`/`:158`, y la fase 2 quita el producto cartesiano en
`:217`/`:411`. Métodos distintos, asuntos distintos, sin interacción.

> **Lo que estos números NO dicen, y es la mitad que se olvida:** el verde es el de
> **la rama**, no el de la fusión. Dieciocho commits de otras cuatro sesiones no
> heredan un verde — **se vuelve a correr en el árbol fundido**, y ése es el número
> que se publica.
