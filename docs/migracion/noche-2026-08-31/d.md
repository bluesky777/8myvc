# Lote D — la marca del boletín independiente, fase 2

> Sesión `8myvc-82`, rama `fix/bi-lote-d`, árbol `.worktrees/d`, base
> `simonbolivar_testing_d`. Reparto: [reparto.md](reparto.md) §2 lote D.
> Coordinó `8myvc-2a` hasta el relevo y `8myvc-c1` después.

**Las cuatro cosas del encargo están hechas.** La ruta 545, la guarda de la decisión 5,
`bol_independiente_periodos` en la ficha y `bol_independiente_datos` en la planilla.

---

## 1 · `PUT boletin-independiente/periodo` — la ruta 545

`app/Http/Controllers/BoletinIndependienteController.php`, nuevo, con
`auth.personal` en la ruta y `Autoriza::puedeMarcarBoletinIndependiente` dentro.

```jsonc
{ "alumno_id": 3311, "periodo_id": 91, "aplica": false }
→ { "alumno_id": 3311, "periodo_id": 91, "aplica": false }
```

Un `INSERT ... ON DUPLICATE KEY UPDATE` sobre `bol_ind_periodos`, dentro de una
transacción. **No se tocó `app/Services/BoletinIndependiente.php`**, que es del lote E.

### Las dos comprobaciones del `periodo_id` del cuerpo

Son la familia de `tools/identificadores-del-cuerpo.py` y **no las da la clave
foránea**, que sólo obliga a que el alumno y el periodo existan:

| | Qué exige | Qué contesta |
|---|---|---|
| (a) | el periodo es del año del token | **403** |
| (b) | el alumno tiene matrícula en un grupo de ese año | **422** |

**(a) es el año del token y no otro**, porque de ahí saca la ficha los cuatro periodos
que enseña (§6.4): un `periodo_id` de otro año no viene de la pantalla. Es 403 y no
404 porque el periodo **existe** — decir «no existe» sería mentir sobre la base.

**(b) no filtra por `estado`, y es deliberado.** Lo que la guarda defiende es que el
alumno y el periodo tengan que ver el uno con el otro; el estado de la matrícula es
otra pregunta y no la ha contestado nadie. Filtrar por `MATR`/`ASIS` dejaría fuera al
**retirado a mitad de año**, que es justamente de quien se imprime un boletín con lo
que alcanzó a cursar. Si algún día se decide estrechar, se decide, no se hereda.

### `aplica` con vocabulario cerrado

Familia de `tools/verdad-laxa-que-escribe.py`. Con un `if ($valor)` de PHP, `"false"` y
`"no"` valen **true**: el colegio pulsa «este periodo va con el grupo» y el alumno
**desaparece de la planilla**, en 200 y sin un error en ningún sitio. Va por
`FILTER_VALIDATE_BOOLEAN` con `FILTER_NULL_ON_FAILURE` —`1/0`, `true/false`,
`"on"/"off"`, `"yes"/"no"`— y todo lo demás es 422. La cadena vacía se rechaza aparte:
ese filtro la lee como `false`, y «no mandé el campo» no puede significar «apágalo».

---

## 2 · La guarda de la decisión 5

`Autoriza::puedeMarcarBoletinIndependiente()`: **superusuario, o el rol `Admin`,
`Secretario` o `Rector`**. No el titular del grupo —es más estrecha que lo de hoy, que
en `GuardarAlumno::valor` sí lo deja— y no el psicólogo.

**Tres decisiones que no son obvias y están escritas en el propio método:**

1. **No es `esAdministrativo()`.** Aquél es `is_superuser || Secretario` y **no incluye
   `Admin`**, al que la decisión 5 nombra. Hoy admiten a la misma gente, y eso es lo
   que lo hace peligroso: los diez `Admin` del seed **son** los diez `is_superuser`, o
   sea que coinciden **por población y no por definición**.
2. **Una consulta y no tres.** `Role::hasRole()` es una consulta entera por nombre; se
   pide `Role::getUserRoles()` una vez y se cruza en PHP.
3. **Y sale de `Role::getUserRoles()` y no de `$user->roles`, que ya viaja en el
   contexto y sería gratis.** No es lo mismo: aquélla filtra `r.deleted_at is null` y
   la del contexto **no**. Con `$user->roles`, un rol mandado a la papelera daría
   permiso aquí y no en `esAdministrativo()` — dos criterios de rol decidiendo distinto
   dentro de la misma clase. Se paga una consulta por no tener eso.

> ### El rol `Secretario` NO está en la base de tests, y eso no lo sabía nadie
>
> `2026_08_21_100000_create_rol_secretario` lo inserta, y a continuación
> `database/dumps/test-seed.sql` hace **`TRUNCATE TABLE roles`** y lo deja fuera: la
> base acaba con **once** roles y sin él. Medido el 31 ago 2026 sobre
> `simonbolivar_testing_d` recién construida con las catorce migraciones puestas.
>
> **Lo que eso significa para lo que ya existe:** la rama `Secretario` de
> `Autoriza::esAdministrativo()` **no se ejerce en ningún test de la suite** salvo en
> los que crean el rol a mano —`ConsecutivoDeCertificadosTest` lo hace—. El criterio
> efectivo que la suite comprueba de ese método es `is_superuser` a secas. No es un
> fallo del código: es que el instrumento no tiene dentro el caso.

---

## 3 · `bol_independiente_periodos` en `PUT alumnos/show`

Los **cuatro** periodos del año del token, siempre, con `aplica` y `tiene_datos`
**booleanos de verdad** —no `"0"`, que en JavaScript es verdadero—. Va en las **dos
ramas** de `putShow`, que cierra la trampa que el plan traía escrita: con la marca
colgada de `(alumno_id, periodo_id)` el campo ya no depende de la matrícula, así que
`undefined` deja de significar dos cosas.

### El `EXPLAIN`, que el reparto pedía y aquí está

`unidades_alcance_index` es `(asignatura_id, periodo_id, alumno_id)` y aquí se pregunta
por `(alumno_id, periodo_id)`: **ese índice no sirve**, tal como avisaba el reparto. Lo
resuelve **`unidades_alumno_id_foreign`**, el índice que la clave foránea de `alumno_id`
arrastra consigo y que nadie había contado:

```
id=1 PRIMARY            p    ref    periodos_year_id_foreign        rows=4
id=1 PRIMARY            bip  eq_ref bol_ind_periodos_unico          rows=1
id=2 DEPENDENT SUBQUERY u    ref    unidades_alumno_id_foreign      rows=1
                                              → 0,49 ms por llamada (50 corridas)
```

### Y el `u.alumno_id = ?` que el detector señala es CORRECTO — no se toca

`tools/unidades-sin-alcance.py` termina diciendo *«1 usan `alumno_id =` y no `<=>`»*, y
ese uno es **este `EXISTS`**. Comprobado por descarte: sin este fichero el aviso
desaparece, sin `Grupo.php` sigue.

**Es la §1.5 del reparto en su forma exacta: el detector ordena candidatos, no lista
fallos.** `<=>` es lo correcto cuando se pregunta *«¿qué unidades le tocan a este
alumno?»*, porque el NULL de la izquierda tiene que emparejar con el NULL del grupo.
Aquí la pregunta es otra: *«¿tiene este alumno alguna unidad **suya**?»*, que es una
afirmación de propiedad y no una resolución de alcance. **Con `<=>` este campo saldría
`true` para los treinta alumnos del grupo** —todos emparejarían con las unidades del
curso— y el badge dejaría de distinguir nada, que es literalmente el fallo que este
campo nació para no repetir.

---

## 4 · `bol_independiente_datos` en `Grupo::alumnos`

`Grupo::alumnos($grupo_id, $con_retirados = '', ?int $periodo_id = null)`.

**El parámetro es opcional y sin él el campo no viaja.** Este método lo llaman
veinticinco sitios —asistencias, disciplina, boletines, certificados, planillas— y a
casi ninguno le consta un periodo: ponérselo a todos sería una consulta más por llamada
y un campo más en veinte respuestas para que lo lea una. **Es también lo que deja las
instantáneas de esas veinte quietas**, y hay un test que lo fija
(`test_sin_periodo_el_badge_no_viaja`).

> ### ⚠️ Esto necesita UNA LÍNEA DEL LOTE B, y no la he tocado
>
> Quien tiene que pasar el periodo es **`NotasController::putDetailed`**
> (`NotasController.php:113`, `Grupo::alumnos($asignatura->grupo_id)`), y ese fichero es
> del **lote B**. Es literalmente añadir el tercer argumento con el periodo de la
> planilla. **Mientras no se haga, el campo existe y funciona pero no sale por ninguna
> respuesta**, así que la pantalla de la planilla del front no lo verá. Lo he probado
> llamando al modelo directamente. Va a coordinación, no lo cojo por mi cuenta.

**La consulta entra por `alumno_id IN (...)` y no sólo por el periodo**, que es lo
primero que se escribe y lo que hay que no hacer: con `WHERE u.periodo_id = ?` a secas
MySQL usa `unidades_periodo_id_foreign` y **recorre las unidades de ese periodo en todo
el colegio** —unas 4.200 de las 16.931— para quedarse con las de treinta alumnos. Con
los ids delante usa `unidades_alumno_id_foreign`: treinta búsquedas que hoy, sin nadie
marcado, no devuelven **ninguna** fila. Medido: 0,39 ms.

### El predicado está escrito dos veces a propósito, y lo que lo ata es un test

La ficha pregunta por cuatro periodos de un alumno; la planilla, por treinta alumnos de
un periodo. Son formas distintas de la misma pregunta y compartir una cadena no las
haría iguales. **Lo que las ata es `test_el_badge_de_la_planilla_cuadra_con_la_ficha`**,
que enfrenta las dos respuestas **alumno a alumno**, con un reparto desigual montado a
propósito —si nadie tuviera datos, las dos dirían `false` a todo y el cuadre no
comprobaría nada— y comprobando que en la lista hay un `true` y un `false`. Es el mismo
criterio que `AlumnosDetrasDelNumeroTest` aplicó a las cifras del panel.

**Y no se filtra por las asignaturas del grupo**, aunque parezca lo natural: el campo
tiene que decir exactamente lo mismo que el de la ficha, que tampoco filtra. Acotarlo
aquí haría que la ficha dijera «tiene datos» y el badge no, sobre el mismo alumno y el
mismo periodo.

---

## 5 · La trampa: al APAGAR se siembran las casillas, y NO preguntando `permiteEditarNotas`

La §9.3. `BoletinIndependienteController::sembrarLasNotasQueFaltan()`, una sola
sentencia dentro de la misma transacción.

**No pasa por `Nota::verificarCrearNotas`**, que es el camino natural y el que rompe:
`verificarCrearNotas` → `quienCreaLasNotas` → `User::permiteEditarNotas` termina en
`is_superuser || tipo == 'Profesor'`, así que **un secretario o un rector que no sean
superusuarios reciben `false` — también con el periodo ABIERTO**. La gente que la
decisión 5 acaba de poner a cargo de esta ruta sería exactamente la que no siembra
nada, en silencio, y desde `myvc_flutter` —que no llama a `/notas` nunca— esa ventana
dura días. Hoy no se vería: funcionaría **por coincidencia de población**.

La razón de fondo es que **la pregunta es otra**. `permiteEditarNotas` contesta *«¿puedes
editar notas?»*; aquí es *«acabas de devolver a este alumno a la planilla del grupo,
¿le dejamos las casillas puestas?»*. Las filas que crea son **notas sin valor**, con
`nota_default`, firmadas con el `user_id` de quien decidió.

**Y el `GROUP BY s.id` no es adorno.** `matriculas` no tiene clave única sobre
(alumno, año) —es la §9.5, viva para todo lo que no sea esta marca—, así que un alumno
con dos matrículas vivas del mismo año entra dos veces por el `JOIN` y el `INSERT`
metería **la misma casilla dos veces**, que es el estado que un `NOT EXISTS` no puede
evitar dentro de una sola sentencia. Entrar por **todas** sus matrículas del año, en vez
de elegir una, es lo que hace que aquí no haya ninguna fila que acertar.

---

## 6 · Los ocho rojos, porque un test escrito después del arreglo no comprueba el arreglo

§1.4 del reparto. Cada uno se corrió **contra el código malo** antes de darlo por bueno.

| | Se rompe | Qué se pone rojo |
|---|---|---|
| R1 | la guarda escrita como `esAdministrativo` | `un administrador sin superusuario`, `un rector`, `la fila guarda quien la escribio` — y **`un secretario` sigue verde**, que es exactamente por qué el atajo engaña |
| R2 | la guarda abierta a todo el personal | `alguien del personal sin esos roles`, `un docente`, `el titular del grupo` |
| R3 | **el sembrado preguntando `permiteEditarNotas`** | `apagar siembra las casillas que faltan`, **y sólo ése** de los veinte |
| R4 | `periodo_id` sacado del token | 10 de 20 |
| R5 | `aplica` leído con un `if` de PHP | `aplica no admite una cadena cualquiera` |
| R6 | sin la comprobación (b) de matrícula | `un alumno que no esta en ese anio se rechaza` |
| R7 | `COALESCE(bip.aplica, 1)` en la ficha | `la ficha trae los cuatro periodos`, `los cuatro estados` |
| R8 | el badge acotado a las asignaturas del grupo | `el badge marca al que tiene datos propios`, **`el badge de la planilla cuadra con la ficha`** |

**R3 es el que vale la noche**: se rompe con un `&&` que cualquiera escribiría, y de los
veinte casos se pone rojo **uno solo** — el que tiene de sujeto a un secretario y el
periodo abierto. Con un superusuario delante, la forma mala pasa entera.

---

## 7 · Las instantáneas: son CUATRO, no tres

Y la cuarta no la esperaba el reparto. Las cuatro con **una línea añadida, cero
quitadas, ningún campo renombrado**:

| Instantánea | Qué cambia |
|---|---|
| `rutas.json` | `PUT api/boletin-independiente/periodo` → `BoletinIndependienteController@putPeriodo`. **544 → 545** |
| `guards-por-ruta.json` | esa ruta entra en la lista de `auth.personal` |
| `guard-por-familia.json` | familia **`boletin-independiente`** nueva: `total: 1, con_guard: 1` |
| **`familias-que-nunca-entran-en-el-candado.json`** | **`"boletin-independiente": "1 de 1"`** |

**La cuarta es legítima y hay que leerla, no taparla.** `FamiliasQueNuncaEntranTest`
lista las familias con **menos de dos** rutas con guard, porque el candado de familia de
`AutorizacionTest` las descarta con un `continue` que no deja rastro. Una familia con una
sola ruta **no puede** establecer la costumbre que ese candado comprueba, así que entra
aquí por construcción y saldrá sola en cuanto la cola añada
`boletin-independiente/planilla` y `/copiar` — las dos con guard, o sea `3 de 3`.

**Lo que NO cambió, y es el criterio de aceptación:** `test_cuantas_escrituras_viven_donde_el_candado_no_llega`
sigue en **23**. La ruta escribe y está en una familia de una sola hermana, pero **lleva
guard**, así que no suma. Y **ninguna instantánea de respuesta se movió** — ni las de
`muestreo-*`, ni `grupos-*`, ni `notas-*`.

---

## 8 · Lo que dejo abierto, y no es un olvido

1. **La línea del lote B** (arriba, §4): sin `Grupo::alumnos(..., $periodo)` en
   `putDetailed`, `bol_independiente_datos` no sale por ninguna respuesta.
2. **`composer.json` no incluye `app/Http/Controllers/` en el ámbito de `pint`**, así que
   el controlador nuevo **no lo formatea `composer run pint`**. Comprobado a mano:
   `pint --test` sobre él da **PASS**. Añadirlo al ámbito arrastraría los otros 112
   controladores, que es justo lo que la regla del `CLAUDE.md` evita — y `composer.json`
   no es de este lote. Queda dicho para quien lo decida.
3. **La cola de la §3 del reparto** —`planilla`, `copiar`, fase 5,
   `independientes-sin-estructura.php`— depende de esta ruta y ya no está bloqueada.

---

## Verde

```
Tests:    <N> passed          (suite entera, sin --filter, contra simonbolivar_testing_d
                               reconstruida con las catorce migraciones)
pint      PASS   324 ficheros
stan      [OK]   nivel 7, 531 ficheros
```

**La base se reconstruyó antes de medir nada**, y hacía falta: la que traía el árbol se
quedaba en `2026_08_21_300000` —sin `bol_ind_periodos`, sin `unidades.alumno_id` y sin la
retirada de `matriculas.boletin_independiente`—, así que un rojo contra ella no habría
significado lo que parece. Va con `PHP_EXEC` apuntando al worktree, porque
`construir-bd-test.sh` migra **el directorio que le digas dentro del contenedor**:

```bash
PHP_EXEC="docker exec -i -w /app/.worktrees/d 8myvc-app-1" \
DB_TEST_DATABASE=simonbolivar_testing_d tools/construir-bd-test.sh
```
