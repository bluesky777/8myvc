# El reparto del boletín independiente — noche del 31 ago 2026

> **Coordina `8myvc-c1`**, relevo de `8myvc-2a` desde el 31 ago 2026 por ventana de contexto llena
> — el traspaso está en [traspaso-coordinacion.md](traspaso-coordinacion.md).
> Cada sesión lee **la sección 0, la 1 y su lote**, y nada más.
> Lo que no está aquí está en [19-boletin-independiente.md](../19-boletin-independiente.md)
> (el plan) y en [ESTADO-ACTUAL.md](../ESTADO-ACTUAL.md) (dónde está la aguja).
>
> Base: `main` en **`693649e`**. Cinco lotes, cinco árboles, cinco bases.

---

## 0. Montaje — se hace ANTES de leer el lote

```bash
# desde la raíz del proyecto, una vez, sustituyendo <x> por tu letra
tools/worktree-de-sesion.sh <x> fix/bi-lote-<x>

# y a partir de aquí TODO se corre así, con -w y con TU base:
docker exec -w /app/.worktrees/<x> -e DB_TEST_DATABASE=simonbolivar_testing_<x> \
    8myvc-app-1 php artisan test
```

El script imprime **desde dónde carga las clases**. Si imprime `/app/app/...` en vez de
`/app/.worktrees/<x>/app/...`, **para y avisa**: estarías editando tus ficheros y
probando los de otra sesión, con los tests en verde. Es la forma de fallo más cara de
este repo y ya pasó una noche entera.

**`vendor/` no se toca.** Va con enlaces duros: un `composer install` dentro de tu
árbol escribe también en el de las demás.

**Nadie hace `checkout main`.** `main` lo mueve sólo quien coordina, y sólo en la raíz.

---

## 1. Las reglas de esta noche

### 1.1 · Tu lote son FICHEROS, no temas

**Ningún fichero aparece en dos lotes.** Si tu trabajo te lleva a uno que no es tuyo,
**no lo tocas**: lo escribes en tu parte y me avisas. Esa es la única regla que si se
rompe cuesta la noche entera.

### 1.2 · Tres ficheros compartidos que NO edita nadie salvo yo

| Fichero | Qué haces en su lugar |
|---|---|
| `docs/migracion/ESTADO-ACTUAL.md` | escribes en `docs/migracion/noche-2026-08-31/<tu-letra>.md` |
| `docs/migracion/19-boletin-independiente.md` | idem — y si tu lote cambia el **contrato**, me avisas: el buzón del front lo llevo yo |
| `tests/Contrato/CasoDeContrato.php` | si necesitas un helper nuevo, me lo pides. Ya tiene `marcarIndependiente($alumnoId, $periodoId, aplica: true)` y `periodosDelAnioDelGrupo($grupoId)` |

**Las instantáneas de rutas** —`rutas.json`, `guards-por-ruta.json`,
`guard-por-familia.json`— las mueve **sólo el lote D**, que es el único que añade una
ruta. Si a ti se te mueven, es que has añadido una ruta sin querer: eso es una
decisión, no un efecto secundario.

### 1.3 · Cómo se sabe que has terminado

En tu árbol y con tu base:

```bash
docker exec -w /app/.worktrees/<x> -e DB_TEST_DATABASE=simonbolivar_testing_<x> 8myvc-app-1 php artisan test
docker exec -w /app/.worktrees/<x> 8myvc-app-1 composer run pint
docker exec -w /app/.worktrees/<x> -e TMPDIR=/tmp/stan-<x> 8myvc-app-1 composer run stan
python3 tools/unidades-sin-alcance.py     # desde tu árbol, para tus sitios
```

**Cero instantáneas regeneradas.** Es el criterio de aceptación de la §4 del plan:
con la migración puesta y **nadie marcado**, todas las respuestas son las de hoy. Si
una instantánea se mueve en la fase 1, no es una instantánea que se regenera: **es una
consulta a la que se le olvidó el alcance**.

### 1.4 · El test que vale y el que no

**Un test escrito después del arreglo no comprueba el arreglo.** Con nadie marcado, la
forma correcta y la incorrecta dan el mismo verde — así que **construye el caso**:
marca a un alumno, móntale unidades propias, y comprueba las dos direcciones.

> **Y compruébalo EN ROJO contra el código viejo antes de darlo por bueno.** Si no se
> pone rojo, no está midiendo tu arreglo. Esto no es opcional en esta noche.

Las dos formas de fallar, las dos ya vistas en este repo:

- **de más** — la consulta del grupo suma las unidades del independiente y las
  definitivas de los treinta salen infladas;
- **de menos** — el boletín del independiente pide las del grupo y sale en blanco.

> **Y una TERCERA forma de fallar el test, levantada por el lote A el 31 ago 2026 y que no estaba
> aquí: el escenario equilibrado.** Su primera versión estaba escrita **antes** del arreglo y aun así
> **pasaba en verde con la forma ingenua**. No por el código: su caso tenía «las del grupo» y «la
> suya» valiendo **las dos 1** en el mismo periodo, así que contar las contrarias daba **el mismo
> número**. Lo desequilibró —dos del grupo, una propia— y el control se puso rojo.
>
> **O sea que escribir el test primero no basta, y «se puso rojo» tampoco si no se comprueba.** La
> §1.4 se cumple **ejecutándolo contra la forma mala**, no escribiéndolo antes. Y al montar el caso,
> **que los dos lados tengan números distintos**: con un 1 contra un 1, el test correcto y el
> equivocado son indistinguibles.

### 1.5 · Acotar NO siempre es lo correcto — se lee cada fila

El detector **ordena candidatos, no lista fallos**. Ya hay tres sitios cerrados
*decidiendo no tocarlos*, y eso también es cerrarlos:

- `selloDeVersion` y `estadoDelGrupo` son **sellos de caché**: sobre-aproximar hace
  recalcular de más (cuesta tiempo); acotarlos haría **servir un dato viejo sin un
  error en el log**.
- `NotaFinal::calcularAsignaturaPeriodo` es **código muerto** — no se acota código
  muerto, se anota.

Si una consulta quiere **a propósito** las del grupo, lo correcto es
`u.alumno_id IS NULL`, que sí es alcance. Si quiere **todas** a propósito, se anota y
se deja. **Escribe el porqué en tu fichero de notas**, que es lo que impide que el
siguiente lo re-litigue.

### 1.6 · Las dos formas de acotar, y ninguna tercera

```php
// (1) la consulta tiene `matriculas m` y `unidades u` en el ámbito:
BoletinIndependiente::JOIN_ESTADO      // el LEFT JOIN con bol_ind_periodos
BoletinIndependiente::ALCANCE          // ... AND u.alumno_id <=> '.ALCANCE

// (2) NO hay matriculas en el ámbito: subconsulta escalar correlacionada
'... AND u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u')
```

**`<=>` y NUNCA `=`.** El igual null-safe empareja NULL con NULL, así que una sola
condición resuelve las dos ramas. **Con `=` a secas la rama del alumno normal devuelve
cero filas y todas las definitivas del colegio se van a 0 sin un solo error en el log.**

> **Y una excepción legítima, encontrada por el lote D el 31 ago 2026 — que nadie la «arregle».**
> La regla de arriba vale para *«¿qué unidades le tocan a este alumno?»*. **No vale para
> *«¿tiene alguna unidad SUYA?»***, que es un `EXISTS` y es otra pregunta: con `<=>` el
> alumno normal empareja con las del grupo y el campo saldría **`true` para los treinta**,
> con lo que el badge de la planilla dejaría de distinguir nada. Ahí `alumno_id = :id` es lo
> correcto.
>
> **El detector lo señala igual**, porque cuenta la forma y no la pregunta: es la §1.5 en su
> caso más incómodo —una fila que el instrumento marca en rojo y está bien—. Si te sale un
> `EXISTS` de esta familia, **escribe el porqué al lado**; sin él, el siguiente que pase lo
> convierte en `<=>` y el campo se muere en silencio.
>
> **Y ya son DOS sitios, así que deja de ser una excepción y pasa a ser la regla partida en
> dos** (formulación del lote D, 1 sep 2026):
>
> | Pregunta | Forma |
> |---|---|
> | **«¿qué unidades le TOCAN a este alumno?»** | `<=>` — el null-safe resuelve las dos ramas |
> | **«¿cuáles son SUYAS?»** | `=` — es una lectura que **afirma propiedad** |
>
> Los dos sitios donde toca `=` afirman propiedad: el `EXISTS` de `tiene_datos`, y **las
> unidades de `PUT boletin-independiente/planilla`**, que es la única pantalla que existe
> **para ver lo que se está ignorando**. Ahí `<=>` haría que un `aplica: false` saliera con
> la estructura del curso **pintada como suya**, y el docente creería que su boletín aparte
> se llenó solo.

**Y el alcance va al `WHERE` cuando la tabla que da el alumno se une DESPUÉS de
`unidades`** — una condición `ON` no puede nombrar una tabla que aún no está en el
ámbito. Pasó en `NotaFinal`.

Si te hace falta una **tercera** forma, no la inventes: es que falta un método en el
servicio. Me lo pides.

### 1.7 · Al terminar

1. Commit en tu rama, en español, con el porqué y con las cifras medidas.
2. Escribe tu `docs/migracion/noche-2026-08-31/<tu-letra>.md`.
3. **Me mandas**: rama, hash, `Tests: N passed`, pint, stan, y qué sitios cerraste
   **y cuáles decidiste no tocar y por qué**.
4. **Y NO te quedas esperando** — cambiado por Joseth el 31 ago 2026, a mitad de la
   noche: *«que no pregunten más, quiero que siempre commiteen lo que necesiten y
   continúen, y que el coordinador una todo a main cuando terminen su commit. Que sigan
   trabajando sin parar.»* O sea: **commiteas en tu rama sin pedir permiso**, tantas
   veces como haga falta —dos sitios cerrados de siete ya son un commit—, y **encadenas
   lo siguiente sin esperar respuesta**. Lo que no cambia es de dónde sale el trabajo:
   **la cola te la doy yo**, no la coges de otro lote. Si te bloqueas, avisa en vez de
   esperar en silencio.

**No fusiones tú.** `main` lo muevo yo desde la raíz.

### 1.8 · Un número de suite ya no vale sin estas tres cosas al lado

Se aprendió esta noche, y las tres a base de dar por bueno un número que no lo era:

- **el `exit=`**, y que sea el de PHPUnit y no el de una tubería. Dos lotes distintos
  dieron por buena una corrida con un `exit code 0` **que era el del `tail`**, y a uno el
  `tail` se comió además la línea `Tests:`. **Una suite sin línea `Tests:` al final no es
  una suite verde: es una suite muerta.**
- **si `AutopruebasDeLasHerramientasTest` está entre los fallos**, para descontarlo: falla
  en **los cinco** worktrees y no es de nadie — `git` no resuelve dentro del contenedor
  porque el `.git` de un worktree apunta a una ruta **del host**.
- **que no haya un zombi tuyo en la misma base.** Matar un `docker exec` mata al cliente,
  **no al proceso de dentro**; el huérfano sigue corriendo tests y provoca
  `SQLSTATE[40001] 1213 Deadlock`. La pista es que **los rojos cambian de sitio entre
  corridas**, y la firma es **`ppid=1`**:

```bash
docker exec 8myvc-app-1 ps -eo pid,ppid,etimes,args | grep phpunit
```

  **El `ps` del host no sirve: no ve dentro del contenedor.** Y la forma que no crea
  zombis es lanzarla desasida, sin cliente que matar:

```bash
docker exec -d -w /app/.worktrees/<x> -e DB_TEST_DATABASE=simonbolivar_testing_<x> \
  8myvc-app-1 sh -c 'php artisan test > /tmp/suite-<x>.txt 2>&1; echo "exit=$?" >> /tmp/suite-<x>.txt'
```

> **Y su reverso, que se ve igual de verde y es otra cosa: el contenedor se reinició.** Un
> zombi hace que **los rojos cambien de sitio**; un reinicio hace que **no haya rojos porque
> no hubo corrida**, y el fichero se queda contando media suite en verde. La firma son **dos
> o más suites muertas a la vez, ninguna con `exit=`, y cero phpunit vivos**. Se contesta en
> cuatro segundos y no hay que buscar quién mató qué:
>
> ```bash
> docker ps --format '{{.Names}}\t{{.Status}}'   # ¿«Up N minutes» con el repo de hace horas?
> docker exec 8myvc-app-1 ps -o etimes= -p 1     # PID 1 joven = el contenedor reinició
> ```
>
> Pasó el 1 sep 2026 y **se llevó dos suites en el mismo instante** —una en la 88 de 228 y
> otra en la 150—. El contenedor de MySQL es **otro** y no se reinició, así que **las bases
> sobreviven y no hay que reconstruir nada**. Lo levantó el lote D midiendo el `etimes` del
> PID 1 después de que la coordinación escribiera *«probablemente cuando se limpiaron
> procesos»* — **una causa plausible sin medir puesta sobre un hecho real, que es la forma
> que esta noche ya había fallado tres veces**. Con esa explicación en el documento, el
> siguiente se pone a buscar quién mató qué.
>
> **Y los mensajes de commit largos van por heredoc citado, `<<'EOF'`, nunca por `-m "..."`.**
> Pasó **dos veces el mismo turno, a dos sesiones distintas**: la shell interpreta lo que va entre
> acentos graves como un comando y **se come frases enteras del mensaje**, metiendo dentro la salida
> de lo que ejecutó. Y **el commit se crea igual de verde** — no hay error, no hay aviso, y el texto
> perdido sólo se ve releyendo el mensaje. En este repo casi todo lleva acentos graves (nombres de
> columna, de método, de fichero), así que no es un caso raro: es el caso normal. Las comillas
> simples del `<<'EOF'` impiden además la sustitución de `$`.
>
> **Y la base también, no sólo el árbol.** `docker exec … php -r '…'` **no lee la base de
> tests**: sin el entorno de testing carga el `.env` y pega contra `simonbolivar`, la de
> desarrollo. Se ve en el propio `EXPLAIN`, que dice `ref: simonbolivar.…`. Le pasó al lote F
> el 1 sep 2026: encontró dos filas en `bol_ind_periodos` y **estuvo a punto de reportar que
> algún test escribía fuera de su transacción**, cuando eran datos de desarrollo de otro lote
> probando su endpoint a mano. **Una consulta suelta contesta sobre la base que le toque, no
> sobre la que estás midiendo** — y la falsa alarma que produce es de las que hacen buscar un
> fallo que no existe en el sitio equivocado.
>
> **Y el árbol es parte del instrumento.** Dos sesiones distintas trabajaron esta noche en
> el árbol equivocado sin notarlo: una porque el `cwd` de su shell **persiste entre
> comandos** después de un `cd`, otra porque acabó editando en la raíz. Se caza por el
> número que no cuadra —dos tests que no aparecen con `--filter`, un fichero con 34
> métodos del que corren 32, el `md5` distinto entre host y contenedor— y se evita con
> **`-w` explícito en todo lo que corra dentro del contenedor y rutas absolutas fuera**.

---

## 2. Los lotes

### Lote A · `8myvc-5e` — los informes de pérdidas y los boletines finales

**Ficheros tuyos, y sólo éstos:**

```
app/Http/Controllers/Informes/NotasPerdidasController.php   :54  :65  :271  :287
app/Http/Controllers/BolfinalesController.php               :474 :536   + puesto :136
app/Http/Controllers/Informes/BolfinalesController.php      :717 :765   + puesto :433
```

**Ocho sitios.** Todos contestan la misma pregunta —*qué asignaturas pierde un
alumno*— y todos la contestan hoy **sumando unidades que pueden no ser suyas**.

**Ojo con `NotasPerdidasController`, que es el que tiene la trampa del lote:** sus
consultas abarcan **varios periodos** a la vez (`p.numero <= :periodo`). Un alumno
puede ir por independiente en el 3 y no en el 2, así que **el alcance se resuelve
DENTRO de la consulta, correlacionado por el periodo de la unidad** — un valor
bindeado una sola vez daría el alcance del periodo equivocado para todos los demás, y
**no habría ningún error que lo señalara**. Para eso existe `alcanceCorrelacionado()`,
que correlaciona por `<alias>.periodo_id`. Hay un test que ya fija esa forma:
`tests/Contrato/AlcanceCorrelacionadoPorPeriodoTest.php` — léelo antes de escribir.

**Y llevas además la decisión 6 en tus dos ficheros de boletines finales:** el puesto
de un independiente se imprime **`—`**, o sea que el backend manda `puesto: null`. Lo
tuyo son los dos `Bolfinales`; los otros seis sitios que copian `Nota::puestoAlumno`
son del lote E. **No toques los suyos.**

---

### Lote B · `8myvc-cf` — la planilla, las unidades y las subunidades

**Ficheros tuyos, y sólo éstos:**

```
app/Http/Controllers/NotasController.php        :73  :156   (putDetailed)
app/Http/Controllers/UnidadesController.php     :26  :64  :359  :398
app/Http/Controllers/SubunidadesController.php  :362
app/Models/Nota.php                             (verificarCrearNotas)   <- reasignado
```

> **`app/Models/Nota.php` pasa del lote E al B el 31 ago 2026, y lo levantó el propio
> lote B.** Estaba mal repartido: la fase 3 necesita `Nota::verificarCrearNotas` —que
> vive ahí— y no se puede hacer desde el llamador, porque el método recibe un
> `grupo_id` y resuelve la lista **dentro** con `Grupo::alumnos()`. **Se decide donde
> está el bucle.**
>
> **Y E no lo necesita, medido y no supuesto:** `puestoAlumno($promedio, $alumnos)` es
> una **función pura** —cuenta cuántos de `$alumnos` tienen el promedio por encima— así
> que sacar al independiente del recuento se hace **eligiendo `$alumnos` en los ocho
> llamadores**, que es donde el plan quería la decisión (§7: «el interruptor se lee en
> el servicio y los ocho preguntan»). `Nota.php` no se toca por la fase 6.

**Siete sitios, y además la FASE 3 entera**, que es tuya porque cae en tu fichero:

- `putDetailed` **deja de devolver a los independientes** entre `alumnos`;
- devuelve `independientes: [{alumno_id, nombres, apellidos}]` — **sin `aplica`**: ese
  array lista justo a los que tienen alcance, así que `aplica` sería `true` por
  construcción, y un campo constante es uno sobre el que alguien ramificará sin que su
  rama muerta se note nunca;
- y `Nota::verificarCrearNotas` **deja de crearles notas de las subunidades del grupo**.

**Dos avisos que te ahorran una noche:**

1. **`getDeAsignaturaPeriodo` (:64 y :26) LEE Y ESCRIBE.** Si esa asignatura no tiene
   unidades en ese periodo y quien mira puede editar, **inserta las unidades y
   subunidades por defecto del año** — y las inserta sin `alumno_id`, o sea del grupo.
   Además `Unidad::arreglarOrden` reescribe `orden` en **cada lectura**. Eso es
   **decisión tomada** ([05 §47.2](../05-codigo-muerto-y-roto.md), Joseth): con el
   periodo abierto crea queriendo. **No se lo quites.** Tu trabajo es el alcance de la
   lectura, no cambiar esa conducta.
2. **`SubunidadesController::postIndex` cambia en un punto** (§6.5 del plan): cuando la
   unidad tiene dueño, **crea las notas de un solo alumno en vez de las del grupo**.
   Está en tu fichero y entra en tu lote.

---

### Lote C · `8myvc-53` — copiar periodos, el modelo y los seis sueltos

**Ficheros tuyos, y sólo éstos:**

```
app/Http/Controllers/PeriodosController.php          :274   (putCopiar)  <- el grave
app/Models/Unidad.php                                :237   (informacionAsignatura)
app/Http/Controllers/AsignaturasController.php       :55
app/Http/Controllers/Informes/InformesController.php :107
app/Http/Controllers/ChangeAskedController.php       :511  :1232
app/Console/Commands/EnviarNotificaciones.php        :195
```

**`putCopiar` es el grave y es la §9.4 del plan.** Copia la estructura de un periodo al
siguiente, y **tiene que copiar también las unidades con dueño** — y sólo para los
alumnos que sigan marcados **en el periodo destino**. Si se olvida, el periodo nuevo
empieza con los independientes sin nada y caemos en la §9.1: definitiva 0, boletín en
blanco, **y nadie recibe un error**. Hay test y **se amplía, no se escribe otro**:
`tests/Contrato/CopiarUnidadesTest.php`.

**`Unidad::informacionAsignatura` es un modelo**, así que cuidado: mira quién lo llama
antes de cambiarle la firma. Su hermana `deAsignaturaCalculada` ya lleva el alcance —
compárate con ella, no la reinventes.

**Los cuatro sueltos son los que más probablemente NO haya que acotar**, y eso es
trabajo igual: `EnviarNotificaciones` manda avisos de notas, `ChangeAskedController`
son horarios del día. **Léelos, decide, y escribe el porqué.** Un «no se toca» razonado
vale lo mismo que un arreglo, y sin él el siguiente lo re-litiga.

---

### Lote D · `8myvc-82` — LA MARCA: fase 2, y es la que desbloquea al front

**El único lote que añade una ruta**, y por tanto el único que mueve las tres
instantáneas de rutas.

**Ficheros tuyos:**

```
routes/api/*.php                                (la ruta nueva)
app/Http/Controllers/BoletinIndependienteController.php   (nuevo)
app/Support/Autoriza.php
app/Http/Controllers/AlumnosController.php
app/Models/Grupo.php
tests/Contrato/Snapshots/rutas.json · guards-por-ruta.json · guard-por-familia.json
```

**NO toques `app/Services/BoletinIndependiente.php`** — es del lote E esta noche. Tu
escritura es un `INSERT ... ON DUPLICATE KEY UPDATE` de una línea sobre
`bol_ind_periodos`, y vive en tu controlador.

**1 · `PUT boletin-independiente/periodo`** (§6.3 del plan). Cuerpo:
`{ "alumno_id": 3311, "periodo_id": 91, "aplica": false }`.

> **`periodo_id` VA EN EL CUERPO, no del token**, y esto lo corrigió el front con razón:
> la ficha marca **cualquiera** de los cuatro periodos —incluido uno cerrado— y el del
> token es el activo, que casi nunca es el del accidente. Un backend que lo sacara del
> token marcaría **siempre el activo, en silencio y con 200**.
>
> **Y por eso entra una guarda que antes no hacía falta** (familia de
> `tools/identificadores-del-cuerpo.py`): comprueba **las dos cosas**, porque la clave
> foránea sólo obliga a que alumno y periodo existan, no a que tengan que ver:
> **(a)** que el periodo sea de un año sobre el que quien llama puede actuar;
> **(b)** que el alumno **esté matriculado en el año de ese periodo**.

**No borra ni una fila de `unidades`, `subunidades` ni `notas`, nunca.** Test que apaga
y enciende contando filas antes y después.

**Sí acepta un periodo CERRADO**, y es decisión tomada: las tres guardas de periodo
cerrado de `app/User.php` muerden sólo a `tipo == 'Profesor'`, y quien marca es
`tipo = 'Usuario'`.

> **LA TRAMPA DE ESTE LOTE, y es la que no se ve.** La §9.3 dice que al **APAGAR** la
> marca hay que crear las notas que falten, para que el alumno no vuelva a la planilla
> sin casillas. Ese sembrado pasa por `Nota::verificarCrearNotas` →
> `quienCreaLasNotas` → `User::permiteEditarNotas`, que termina en
> `is_superuser || tipo == 'Profesor'`. **Un secretario o un rector que no sean
> superusuarios reciben `false` — también con el periodo ABIERTO.** O sea que la gente
> que la decisión 5 puso a cargo es **exactamente la que no siembra nada**, en silencio.
> Hoy no se vería: en `simonbolivar` `Rector` y `Secretario` tienen **cero personas** y
> los diez `Admin` son los diez superusuarios — **funcionaría por coincidencia de
> población**. Ese sembrado **no debe preguntar `permiteEditarNotas`**: la pregunta es
> otra —«¿le dejamos las casillas puestas?»— y las filas que crea son notas sin valor.

**2 · La guarda de la decisión 5**, en `Autoriza.php`: marcan **administradores,
secretario y rector**, superusuario por encima. **NO el titular del grupo** —es más
estrecho que lo de hoy— y no el psicólogo.

> `Role::hasRoleOrPerm` es **del front**: en este backend aparece en cinco comentarios y
> en **ninguna línea de código**. Lo que hay es `Role::hasRole($user_id, $nombre)` y
> `Role::isSecretario()`. **Y no reutilices `esAdministrativo`**, que es
> `is_superuser || Secretario` y **no incluye el rol `Admin`** — coinciden sólo por
> población, y la decisión 5 nombra a los administradores explícitamente.
>
> **Antes de escribir el test:** en `simonbolivar` los roles `Rector` (#10) y
> `Secretario` (#12) **existen y tienen cero personas**. Un test que sólo compruebe «un
> administrador puede» **pasaría con la guarda mal escrita**. El caso que hay que montar
> a mano es **el secretario que no es superusuario**.

**3 · `bol_independiente_periodos` en `PUT alumnos/show`** (§6.4). **Siempre los cuatro
periodos del año**, no sólo las filas que existan:

```jsonc
[{ "periodo_id": 91, "numero": 1, "aplica": false, "tiene_datos": false }, ...]
```

`tiene_datos` = tiene alguna unidad propia viva en ese periodo. Lo contesta el backend
porque desde el navegador haría falta una llamada por periodo. **Mira el `EXPLAIN`**:
`unidades_alcance_index` es `(asignatura_id, periodo_id, alumno_id)` y aquí preguntas
por `(alumno_id, periodo_id)`, así que ese índice **no te sirve**.

> **Mandar los cuatro no es cosmética.** Una lista con sólo las filas presentes obliga
> al front a decidir qué significa una ausencia, y **este módulo ya perdió una semana
> por leer una ausencia al revés**.

**4 · `bol_independiente_datos` en `Grupo::alumnos`** — el badge de la planilla: `true`
= tiene boletín aparte guardado en este periodo aunque el periodo vaya con el grupo.
Es `tiene_datos` aplanado. **Coordina conmigo antes de tocar `Grupo.php` si el lote B
te lo pide**, aunque el fichero es tuyo.

**Cuando termines, avísame antes de nada:** el front tiene cuatro pantallas escritas y
escondidas esperando exactamente estos campos.

---

### Lote E · `8myvc-8f` — los puestos y el interruptor: fase 6

**Ficheros tuyos:**

```
database/migrations/2026_08_31_2000xx_puestos_con_bol_independiente.php  (nuevo)
app/Services/BoletinIndependiente.php
app/Http/Controllers/Informes/BoletinesController.php    :235
app/Http/Controllers/Informes/Boletines2Controller.php   :164
app/Http/Controllers/Informes/Boletines3Controller.php   :169
app/Http/Controllers/Informes/CertificadosPersonaController.php  :191
app/Http/Controllers/EditnotaController.php              :215
app/Http/Controllers/PromovidosController.php            :136
app/Http/Controllers/PuestosController.php
```

> **Rutas corregidas el 31 ago 2026**: los tres `Boletines*` y `CertificadosPersona`
> viven bajo `Informes/`, no en la raíz — el reparto los nombraba sin el prefijo.
>
> **Y `app/Models/Nota.php` YA NO ES TUYO**: pasó al lote B, que lo necesita para la
> fase 3. No te hace falta: `puestoAlumno($promedio, $alumnos)` es una función pura y
> el filtrado va en los ocho llamadores, que es donde el plan lo quiere.

**Los dos `Bolfinales` NO son tuyos: son del lote A.** Son ocho sitios que copian
`Nota::puestoAlumno`; tú llevas seis y el lote A los otros dos.

**1 · La migración**: `years.puestos_con_bol_independiente TINYINT(1) NOT NULL DEFAULT 1`.
Por defecto **1 = lo de hoy**.

> **Esta columna ya se intentó una vez y se retiró**, y conviene saber por qué antes de
> repetirlo: movía las **tres instantáneas** de `MuestreoDeLecturasTest` —`api/years`,
> `api/years/colegio` y `api/years/trashed`— porque `YearsController:27` y `:43` leen
> con `SELECT *`. Esta vez **entra con quien la consume**, que eres tú. Si esas
> instantáneas se mueven, **es legítimo y se regeneran** —es un campo añadido a una
> respuesta, no uno cambiado— pero **el diff se mira antes de aceptarlo**: cero líneas
> quitadas, y ni un campo renombrado.

**2 · El método del servicio.** `BoletinIndependiente::puestosCuentanIndependientes(int $yearId): bool`.

> **La regla que ya está pagada y hay que conservar:** contesta **«¿está activado el
> interruptor?»** y **NUNCA «¿se enseña el puesto?»**. El front esconde el puesto al
> `Acudiente` y al `Alumno` aunque el año lo tenga activado. Si contestara lo segundo,
> o le filtraría el puesto a las familias por su cuenta o dejaría muerta esa regla del
> front — **las dos en silencio**.

**3 · Los seis sitios preguntan al servicio**, no cada uno por su cuenta. Con el
interruptor en 0: el independiente **no sale** en la tabla de puestos, **no cuenta para
el puesto de los demás** —si iba primero, **los treinta de detrás suben un puesto**, en
pantalla y en el papel— y su propio boletín lleva `puesto: null`.

**4 · El interruptor viaja en la respuesta** de los cuatro informes de puestos
(`puestos_con_bol_independiente` y `alumno.bol_independiente_periodo`), en vez de que
cada pantalla lo pregunte. **Si lo pregunta uno y otro no, los otros tres mienten.**

**Tu test es el que comprueba que el puesto de un TERCERO cambia**, que es el efecto
que nadie espera. Con el interruptor en los dos valores.

---

## 3. La cola — lo que sigue cuando termines

**No la cojas por tu cuenta: te la doy yo.** Está aquí para que se vea que hay trabajo
y nadie se pare.

| | Qué | Depende de |
|---|---|---|
| 1 | **`PUT boletin-independiente/planilla`** (§6.1) — la pantalla nueva entera en una petición, con los tres `motivo` y `estructura_del_grupo` | D (la ruta) |
| 2 | **`POST boletin-independiente/copiar`** (§6.2) — **dos orígenes**, `si_ya_tiene` con tres valores, y el 422 de `con_notas` entre periodos distintos | D |
| 3 | **Fase 5** — los boletines probados **en negativo** con un alumno marcado: que el suyo traiga **sus** subunidades y **ninguna** del grupo, y que al compañero no le entre ninguna de las suyas | 1 |
| 4 | **`tools/independientes-sin-estructura.php`** (§9.1) — qué pares (alumno, asignatura) están marcados y **no tienen ni una unidad propia**. Con su población delante | D |
| 5 | **La §9.5 para las otras tres columnas** — `repitente`, `promovido` y `nro_folio` siguen leyéndose y escribiéndose con dos consultas distintas | — |
