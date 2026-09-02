# 19 — El boletín independiente

**Escrito el 24 ago 2026.** Lo pidieron los colegios y lo autorizó Joseth con las
cuatro decisiones de la §2. **No es de la migración**: es trabajo nuevo, y va
detrás de que la fase 2 de las [definitivas](10-definitivas.md) esté decidida —
las dos tocan `notas_finales` desde el mismo sitio y el orden importa (§10).

> **Se lee entero antes de escribir la primera línea.** Lo que hace esta función
> peligrosa no es lo que añade, sino lo que **le quita a la consulta de otro**:
> hay **74 consultas** en `app/` que leen `unidades` y **70 que leen
> `subunidades`** dando por hecho que una unidad pertenece a la asignatura y a
> nadie más. En cuanto una unidad pueda tener dueño, cada una de esas 74 está o
> corregida o equivocada. No hay término medio y no hay aviso: la consulta sigue
> devolviendo filas.

---

## §1 — Lo que pidieron, en sus palabras y traducido

Un alumno se puede marcar como PIAR. Los colegios quieren marcarlo también como
**«requiere boletín independiente»**, y que eso signifique cinco cosas:

| Lo que pidieron | Lo que significa en este código |
|---|---|
| «que no aparezca en esa planilla de notas normales» | `NotasController::putDetailed` deja de devolverlo entre `alumnos`, y `Nota::verificarCrearNotas` deja de crearle notas de las subunidades del grupo |
| «otra pantalla con el listado de alumnos marcados, donde el docente le escriba sus unidades y subunidades del periodo de esa asignatura y la nota» | una pantalla nueva y **tres rutas nuevas**; las unidades, las subunidades y las notas se escriben con los endpoints que **ya existen** |
| «opciones para copiar las unidades/sub en los demás alumnos mostrados» | `POST boletin-independiente/copiar`, con y sin notas |
| «al cargar los puestos salen en el grado; que haya opción de que aparezcan o no» | un interruptor del colegio en `years`, y **mueve el puesto impreso de todos los demás** (§7) |
| «en los boletines salen todos, pero el independiente no lleva las subunidades de la asignatura sino las suyas» | `Unidad::deAsignaturaCalculada` y `Subunidad::deUnidadCalculada` — **dos funciones cubren los tres boletines** |

Y la sexta, que es la que decide el diseño de la tabla:

> «En ese módulo debe tener la opción *Este periodo no tiene boletín
> independiente*. Si la marca no debe borrar los datos suministrados en ese
> periodo si los puso antes de marcar la opción, pero esos datos deben ser
> ignorados en los boletines y el estudiante deberá volver a aparecer en las
> planillas de ese periodo junto a los demás, con algún badge o icono.»

**Eso es un interruptor por periodo que no borra nada**, o sea que el dato y su
visibilidad son dos cosas distintas y hay que guardarlas por separado. Todo lo
demás del diseño sale de ahí.

Y una que se pidió **en negativo**, y vale tanto como las otras:

> «La nota de comportamiento no debe ser otro módulo.»

`nota_comportamiento` cuelga de `(alumno_id, periodo_id)` y no sabe de
asignaturas ni de subunidades (`database/schema/mysql-schema.sql:1292`). **No se
toca ni una línea**: el alumno con boletín independiente sigue apareciendo en la
pantalla de comportamiento con todos los demás, y su nota entra en el promedio
igual que hoy. Si alguien acaba escribiendo una pantalla de comportamiento
dentro de este módulo, está construyendo lo que se pidió no construir.

---

## §2 — Las cuatro decisiones, tomadas el 24 ago 2026

| | Decisión | Qué se descartó y por qué importa |
|---|---|---|
| 1 | **La marca vale para TODAS las asignaturas del alumno** | Se descartó elegir asignatura por asignatura. Cuesta un riesgo real y está medido en la §9.1: en la asignatura cuyo docente no le cree nada, el alumno sale del grupo y **no entra en ninguna parte** |
| 2 | ~~**La marca vive en la matrícula: `matriculas.boletin_independiente`**~~ · **REVISADA el 31 ago 2026: la marca es POR PERIODO** — ver la decisión 7 y la §2.1. Lo de abajo sigue valiendo como el porqué de no ponerla en `alumnos` | No en `alumnos`, que es donde vive `nee`. `alumnos` es global: la marca se arrastraría al año siguiente sin que nadie la ponga, y **repintaría los boletines de años pasados** — la matrícula es por año y por grupo, que es el alcance real de la decisión |
| 3 | **Los puestos se deciden con un interruptor del colegio**, `years.puestos_con_bol_independiente`, **por defecto 1** | No una casilla en la pantalla: el puesto también se **imprime en el boletín** (`BoletinesController:238`), y una casilla de pantalla dejaría dos criterios para el mismo número. Por defecto 1 = lo de hoy |
| 4 | **Copiar copia la estructura, y pregunta por las notas** | Dos botones en la misma pantalla: `con_notas` en el cuerpo. Copiar sin notas es preparar la planilla; copiar con notas es calificar a varios de golpe y **el docente tiene que decirlo** |

### Las tres decisiones del 31 ago 2026 — y una de ellas REVISA la decisión 2

**Tomadas por Joseth** en la sesión `myvc-front-c5`, con el plan y los dos repos delante.
**Escritas también en el buzón del front** (`~/DESARROLLOS/myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`,
§B.5), que es donde manda el acuerdo del 24 ago.

| | Decisión | Qué cierra |
|---|---|---|
| 5 | **Marcan a un alumno los administradores, el secretario y el rector** | Cierra la primera de las dos abiertas. **Y no es «como está hoy»: es más estrecho.** La rama de matrícula de `GuardarAlumno::valor` hoy la escriben el **titular del grupo** y el administrativo — **el titular NO marca**, y el psicólogo tampoco (sí escribe `nee`). En los nombres que ya existen: `Role::hasRoleOrPerm(['admin', 'secretario'])` más el rol `Rector`, con el superusuario por encima |
| 6 | **El boletín de un independiente lleva `—` en el puesto** | Cierra la segunda. Confirma el punto 3 de la §7: el backend manda `puesto: null` y el front pinta `—`. **Vale para los cuatro informes de puestos y para el puesto impreso** en `boletin-periodo` y `boletin-final` |
| 7 | **La marca es POR PERIODO, no por año.** `bol_ind_periodos` deja de ser la excepción y pasa a ser **la marca**: `aplica=1` en ese periodo = va aparte; **fila ausente = va con el grupo** | **Revisa la decisión 2.** Ver el apartado siguiente, que es lo que hay que cambiar del plan |

### §2.1 — La marca por periodo: qué hay que cambiar del plan (decisión 7)

**El requisito, en sus palabras:**

> «si se pone en `matriculas.boletin_independiente` sería para todo el año, me gustaría poder elegir
> por periodo, porque a veces el estudiante tuvo un periodo normal y en el segundo periodo tuvo un
> accidente donde ya se le tiene que crear un boletín aparte pero no se le puede borrar el boletín
> del primer periodo, **tienen que convivir**.»

**Lo que no cuadraba, y es un default, no una tabla que falte:** el esqueleto ya trae
`bol_ind_periodos`, pero como **excepción** — fila ausente significa «lo que diga la matrícula», y de
ahí el `COALESCE(bip.aplica, 1)` de `BoletinIndependiente::ALCANCE` y de `alcanceCorrelacionado()`.
Con ese default, **marcar a un alumno en octubre repinta el boletín del primer periodo**, que es
exactamente lo que se pide que no pase. **La tabla estaba bien; el sentido del default estaba al
revés.**

**Lo que cambia, y el 31 ago 2026 ya está TODO hecho en código** salvo lo que dice la fase 1:

1. ✅ **`COALESCE(bip.aplica, 1)` → `COALESCE(bip.aplica, 0)`.** Es un carácter y cambia el
   significado entero: **sin fila, el alumno va con el grupo**. Sigue cumpliendo la §4 —con la tabla
   vacía, `alcance()` devuelve `null` para todo el mundo— pero ahora por la razón fuerte: antes se
   cumplía porque una columna estaba a 0 en todas las filas; ahora porque **no hay ninguna fila**,
   que es más difícil de romper sin querer. Lo fija
   `test_marcar_un_periodo_no_toca_el_alcance_de_los_demas`, que **se pone rojo con ese solo
   carácter** y es el test que este módulo no tenía.
2. ✅ **`matriculas.boletin_independiente` SE RETIRA.** Contestada por el backend, que es de quien
   era. Ver el apartado §2.2, que es donde está el porqué y lo que se lleva por delante.
3. ✅ **`PUT boletin-independiente/periodo` (§6.3) sube de la fase 4 a la fase 2.** Con la decisión 7
   es **el único escritor de la marca**, así que la fase 2 sin él no tiene nada que escribir. Y su
   guarda es la decisión 5, que hoy no está escrita en ningún sitio — ver §2.3.
4. ✅ **`PUT alumnos/guardar-valor` deja de estar en el camino.** El `case 'boletin_independiente'`
   de la §6.4 y toda la §6.6 dejan de ser el desbloqueo de la pantalla 1 del front: la escritura ya
   no pasa por ahí. (El «No guardado» con 200 sigue vivo y sigue siendo de [09 §13](09-pendientes.md),
   pero ya no es un problema de esta función.)
5. ✅ **El campo que la ficha necesita ya tiene nombre y forma: `bol_independiente_periodos`.** Está
   en la §6.4 con su JSON y sus cuatro estados.
6. **La §8 punto 1 se reescribe:** la pantalla 1 no es «un interruptor más en la ficha», es **un
   interruptor por periodo**, visible sólo para admin / secretario / rector. Sigue siendo la primera
   y sigue siendo la barata.
7. ✅ **¿Se puede marcar un periodo CERRADO? SÍ.** Ver §2.4, que además trae la trampa que la
   pregunta destapó y que no estaba en el plan.

**Lo que NO cambia con la decisión 7, y conviene decirlo para que nadie lo rehaga:** `unidades.alumno_id`
sigue siendo el diseño (§3), las unidades ya cuelgan de un periodo, `notas` y `notas_finales` no se
tocan, y **los periodos conviven solos**: cada periodo tiene su definitiva, calculada con la
estructura que ese periodo tuviera, y el boletín final promedia los dos sin que nadie elija.

**Y la marca ERE es otra marca.** `alumnos.nee` es de la ruta de inclusión. El boletín independiente
**no se deriva de ella, no la implica y no filtra por ella** — el alumno del accidente no es ERE.

### §2.2 — `matriculas.boletin_independiente` se retira. Contestada el 31 ago 2026

**La pregunta era del backend y ésta es la respuesta: se retira**, con su propia migración
(`2026_08_31_100000_retirar_boletin_independiente_de_matriculas`). No se queda de espejo del año.

**Lo primero, porque cambia la pregunta: la columna YA ESTÁ EN PRODUCCIÓN.** El esqueleto
(`2026_08_24_100000`) entró en `e37eab0`, que es **anterior** a `eb95cbc`, o sea que se desplegó en
una tanda anterior a la del 25–30 ago. Así que esto no es «editar una migración que aún no ha
salido»: es **una migración nueva que quita una columna viva en los quince colegios**. Que la
respuesta siga siendo «se retira» con ese coste delante es lo que la hace una respuesta y no una
preferencia.

**Por qué no de espejo.** Un espejo es un **segundo escritor de un dato derivado**, y este repositorio
ya sabe lo que cuesta: seis escritores de la definitiva con cinco criterios, de donde salió
`DefinitivasDeAsignatura`. Aquí el modo de fallo es peor que un número raro — dos columnas que
discrepan en silencio significan **un alumno que vuelve al boletín del grupo sin que nadie lo vea**,
y quien lo note lo ve en la planilla de otro docente, sin nada que lo relacione con un interruptor
que alguien tocó en otra pantalla. Es literalmente la §9.5.

**Y lo que se lleva por delante es más de lo que costaba:**

| Se va | Por qué estaba |
|---|---|
| **La §9.5, para esta marca** | La columna vivía en una fila de `matriculas`, y `matriculas` **no tiene clave única sobre (alumno, año)**. La ficha elegía «la matrícula del año» filtrando y ordenando; `GuardarAlumno::valor`, sin filtrar ni ordenar; las dos se quedaban con `[0]`. `bol_ind_periodos` cuelga de `(alumno_id, periodo_id)` **con clave única**: no hay dos filas entre las que equivocarse. *La §9.5 sigue viva para `repitente`, `promovido` y `nro_folio`* — para esta marca, no |
| **Treinta líneas de SQL** en `alcanceCorrelacionado()` | Sólo estaban para **derivar esa matrícula**: entrar por `periodos`, bajar a `grupos` del mismo `year_id`, unir `matriculas`, filtrar borrados y desempatar con `ORDER BY created_at DESC, id DESC LIMIT 1`. Hoy son **cuatro**: un `SELECT` sobre `bol_ind_periodos`. Un periodo pertenece a un año y sólo a uno, así que **el año se hereda en vez de derivarse** |
| **El `LIMIT 1` y su degradación consciente** | Existía porque dos matrículas vivas del mismo alumno convertirían la subconsulta escalar en un error de ejecución. Con la clave única no puede haber dos filas |
| **La duplicación del `LEFT JOIN`** | `JOIN_ESTADO` podía doblar un `SUM()` si `matriculas` traía dos filas. Ahora une contra una tabla con clave única sobre el par exacto: **no puede duplicar** |

**«Dos formas y ninguna tercera» pasa a ser prácticamente una.** La forma de grupo (`JOIN_ESTADO` +
`ALCANCE`) y la correlacionada dejan de ser dos reglas que hay que mantener iguales: las dos leen la
misma tabla por la misma clave, y la única diferencia es de dónde sale el `alumno_id`.

**El campo de listado que el front pedía no desaparece: pasa a ser derivado.** «¿Este alumno tiene
algún periodo marcado este año?» sale de un `EXISTS` sobre `bol_ind_periodos`, cuya clave única
empieza por `alumno_id`. **Un valor derivado no puede discrepar de su fuente**, que era justo lo que
el front pidió.

**Lo que cuesta desplegarlo, medido y no supuesto.** `DROP COLUMN` admite `ALGORITHM=INSTANT` en
MySQL desde la 8.0.29 y el contenedor va por la **8.0.42**. Sobre una copia real de `matriculas`
—**3.542 filas, 0,4 MB**— tarda **15,2 ms** y no reconstruye la tabla. Es la más barata de las
migraciones de este plan, la contraria del `ALTER TABLE` sobre `unidades` que avisa la §10. **Lo que
no sabemos es la versión de MySQL de los quince cPanel**: si alguno no admitiera `INSTANT`, el peor
caso es reconstruir una tabla de 0,4 MB.

**Y se puede correr sin ventana.** La columna está a **0 en las 3.542 filas**, **no la lee ni la
escribe nadie** desde este mismo lote —su único lector era `BoletinIndependiente`— y **nunca llegó a
viajar en una respuesta**: los cuatro sitios que hacían `SELECT *` sobre `matriculas` se pasaron a
columnas nombradas el 24 ago precisamente para que no saliera, y **ninguna de esas cuatro listas la
incluye**. O sea que quitarla **no mueve una sola instantánea**, comprobado. El trabajo defensivo de
aquella noche se cobra hoy en la dirección contraria a la que se hizo.

**Por eso se retira ahora y no «más adelante»:** hoy es una columna inerte que nadie lee; cada día
que se queda es un día más en que alguien puede leerla y convertirla en la segunda fuente que este
lote existe para no tener.

### §2.3 — La guarda de la decisión 5 no se puede escribir con los nombres del mensaje

`Role::hasRoleOrPerm(['admin','secretario'])` es la forma en que **el front** decide qué pantalla
enseña. **En este backend no existe**: `hasRoleOrPerm` aparece en cinco comentarios de controlador y
en ninguna línea de código. Lo que hay es `Role::hasRole($user_id, $nombre)`, `Role::isSecretario()` y
`App\Support\Autoriza`.

Así que la decisión 5 se escribe como un método nuevo de `Autoriza` —un solo sitio decide, como con
todo lo demás— y **no reutilizando `esAdministrativo`**, que es `is_superuser || Secretario` y **no
incluye el rol `Admin`**. Esa diferencia ya tiene un paso 0 en [`DESPLIEGUE.md`](../DESPLIEGUE.md)
porque coinciden sólo por población: los diez `Admin` medidos son los diez `is_superuser`. **Una
coincidencia de población no es un criterio**, y la decisión 5 nombra a los administradores
explícitamente, así que el método nuevo los nombra explícitamente.

**Y la medición que hay que tener delante antes de escribir el test:** en `simonbolivar` los roles
`Rector` (#10) y `Secretario` (#12) **existen y tienen cero personas**. Los que hay son `Admin` (10,
los diez superusuarios), `Profesor` (53), `Coord disciplinario` (1), `Enfermero` (1) y `Psicólogo`
(4). O sea que **en esta base la guarda de la decisión 5 admite hoy exactamente a los diez
superusuarios**, y un test que sólo compruebe «un administrador puede» pasaría igual con la guarda
mal escrita. El caso que hay que montar a mano es **el secretario que no es superusuario**, que es el
que la decisión 5 añade y el que aquí no existe.

### §2.4 — Sí se puede marcar un periodo cerrado. Y la pregunta destapó otra cosa

**La respuesta es sí, y sale del código que ya hay, no de una preferencia.** La guarda de periodo
cerrado son tres métodos de `app/User.php` —`pueden_editar_notas`, `permiteEditarNotas` y
`exigirPeriodoAbiertoParaNotas`— y **las tres muerden sólo a `$user->tipo == 'Profesor'`**. Quien
marca, según la decisión 5, es administrador, secretario o rector: los tres son `tipo = 'Usuario'`.
**La guarda de hoy no les llega**, así que ponerles una sería escribir una regla nueva, no aplicar la
que hay.

Y el requisito la quiere así: es el ejemplo del accidente **al revés** —el colegio cierra el periodo
2 y sólo entonces alguien cae en que el alumno lo necesitaba aparte—. Si un periodo cerrado no se
pudiera marcar, la única salida sería **reabrirlo**, que le abre la planilla de un periodo entero a
los 53 docentes. Es una puerta mucho más grande que la que cierra. Además, marcar **no escribe ni una
fila en `notas`**: la §6.3 promete que no borra nada y tampoco crea nada por sí sola.

> **LA TRAMPA, QUE NO ESTABA EN EL PLAN Y ES DE LA FASE 2.** La §9.3 dice que
> `PUT boletin-independiente/periodo` **crea las notas que falten dentro de su propia transacción**
> cuando se APAGA la marca, para que el alumno no vuelva a la planilla del grupo sin casillas. Ese
> sembrado pasa por `Nota::verificarCrearNotas` → `quienCreaLasNotas` → `User::permiteEditarNotas`,
> que termina en `return (bool) $user->is_superuser || $user->tipo == 'Profesor'`.
>
> **Un secretario o un rector que no sean superusuarios reciben `false` — también con el periodo
> ABIERTO.** O sea que la gente que la decisión 5 puso a cargo es exactamente la que **no siembra
> nada**, en silencio, y el alumno vuelve a la planilla sin notas: la ventana de la §9.3, que desde
> Flutter dura días porque esa app no llama a `/notas` nunca.
>
> **Hoy no se vería, y ése es el problema.** En `simonbolivar` `Rector` y `Secretario` tienen cero
> personas y los diez `Admin` son los diez superusuarios, así que **funcionaría por coincidencia de
> población** — la misma forma exacta del paso 0 de `DESPLIEGUE.md`. El colegio que le dé el rol a un
> secretario de verdad es el que lo descubre.
>
> **La recomendación, y es de la fase 2:** el sembrado de la §6.3 **no debe preguntar
> `permiteEditarNotas`**. Ese método contesta *«¿puedes editar notas?»*, y aquí la pregunta es otra:
> *«acabas de devolver a este alumno a la planilla del grupo, ¿le dejamos las casillas puestas?»*. Las
> filas que crea son notas **sin valor**; no crearlas es el daño, no crearlas es lo que hay que
> evitar. Se firman con el `user_id` de quien llama, que es quien tomó la decisión. Si eso hay que
> consultarlo con Joseth, la pregunta corta es: *«el secretario que desmarca un periodo, ¿deja al
> alumno con las casillas puestas o vacías?»* — y la respuesta obvia es la primera.

### ~~Lo que todavía espera una decisión~~ — **las dos CONTESTADAS el 31 ago 2026** (decisiones 5 y 6 de arriba). Texto original, para que se lea de dónde venían:


- **Quién puede marcar a un alumno.** Hoy la rama de propiedades de matrícula de
  `GuardarAlumno::valor` (`app/Http/Controllers/Alumnos/GuardarAlumno.php:48-81`)
  la escriben el **titular del grupo** y el **administrativo**. `nee` la escribe
  además el **psicólogo**, por la decisión del 21 ago. La propuesta es **igualar
  las dos**: quien puede meter a alguien en el PIAR puede marcarle el boletín
  independiente. **Sin contestar, se implementa como está hoy** (titular +
  administrativo) y ampliarlo es un `case` más.
- **Qué puesto lleva el boletín de un independiente** cuando el interruptor dice
  que no cuentan. La propuesta es imprimir `—`, no un puesto calculado sobre una
  lista de la que él no forma parte. Ver §7.

---

## §3 — El diseño, en una frase

> **Una unidad puede tener dueño.** Si `unidades.alumno_id` es `NULL` es del
> grupo —todas las de hoy—; si lleva un id, es de ese alumno y de nadie más. Las
> subunidades y las notas **no cambian**: siguen colgando de la unidad y de la
> subunidad como siempre.

Lo que compra:

- **`notas` no se toca.** Ni una columna. La nota del independiente es una nota
  normal colgada de una subunidad normal, así que `PUT notas/update/{id}`,
  `PUT notas/lote`, la bitácora, `PeriodoDeLaFila::deNota` y
  `DefinitivasDeAsignatura::recalcularPorNota` **funcionan sin cambio**.
- **`notas_finales` no se toca**, y por eso el independiente **sale en los
  puestos, en los boletines finales, en las actas y en los certificados** sin
  que nadie escriba una línea: su definitiva se calcula con la misma fórmula
  sobre sus propias unidades.
- **Los tres boletines se cubren en dos funciones**: los tres pasan por
  `Unidad::deAsignaturaCalculada` (`app/Models/Unidad.php:88`) y
  `Subunidad::deUnidadCalculada` (`app/Models/Subunidad.php:91`).

Lo que cuesta: **las 74 consultas de la cabecera.** Está en la §9.

### Las migraciones — eran cuatro, y la 2 se retiró el 31 ago 2026

```sql
-- 1. La unidad puede tener dueño. NULL = del grupo, que es todo lo que hay hoy.
ALTER TABLE unidades
    ADD COLUMN alumno_id INT UNSIGNED NULL AFTER asignatura_id,
    ADD KEY unidades_alcance_index (asignatura_id, periodo_id, alumno_id),
    ADD CONSTRAINT unidades_alumno_id_foreign
        FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE;

-- 2. RETIRADA el 31 ago 2026 por la decisión 7 (§2.2). Llegó a producción y se
--    quita con su propia migración: la marca es por periodo, y dos columnas que
--    pueden discrepar en silencio acaban discrepando.
--    ALTER TABLE matriculas ADD COLUMN boletin_independiente TINYINT(1) NOT NULL DEFAULT 0;
--    ALTER TABLE matriculas DROP COLUMN boletin_independiente;   -- INSTANT, 15,2 ms

-- 3. **LA marca**, no una excepción. `aplica=1` en ese periodo = va aparte;
--    la fila que falta = va con el grupo; `aplica=0` = «este periodo no, pero
--    no borres nada», que es distinto de no tener fila y es lo que pinta el badge.
CREATE TABLE bol_ind_periodos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alumno_id   INT UNSIGNED NOT NULL,
    periodo_id  INT UNSIGNED NOT NULL,
    aplica      TINYINT(1) NOT NULL DEFAULT 1,
    updated_by  INT DEFAULT NULL,
    created_at  TIMESTAMP NULL DEFAULT NULL,
    updated_at  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY bol_ind_periodos_unico (alumno_id, periodo_id),
    CONSTRAINT bol_ind_periodos_alumno_id_foreign
        FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE,
    CONSTRAINT bol_ind_periodos_periodo_id_foreign
        FOREIGN KEY (periodo_id) REFERENCES periodos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. El interruptor de los puestos, con el valor de hoy por defecto.
ALTER TABLE years
    ADD COLUMN puestos_con_bol_independiente TINYINT(1) NOT NULL DEFAULT 1;
```

**La clave única de `bol_ind_periodos` nace con la tabla, y es deliberado.** La
tabla `notas_finales` lleva sin ella desde 2014 y de ahí salen los tres síntomas
del [10](10-definitivas.md); una tabla nueva sin clave única es el mismo error
cometido a sabiendas. Con ella el interruptor se escribe con un
`INSERT ... ON DUPLICATE KEY UPDATE` de una línea y no hay ventana de borrado.

**`aplica` es un `tinyint` con dueño**, no uno de los de
`tools/interruptores-que-nadie-lee.py`: lo escribe una pantalla concreta, lo lee
un servicio único y hay un test que lo demuestra en los dos sentidos.

### El servicio único, `App\Services\BoletinIndependiente`

La misma forma que `DefinitivasDeAsignatura`: **un solo sitio decide**, y quien
pregunta no vuelve a escribir la regla.

```php
// ¿Este alumno, en este periodo, va por boletín independiente?
BoletinIndependiente::aplica(int $alumnoId, int $periodoId): bool

// El valor que va a `unidades.alumno_id` para las consultas de UN alumno:
// null (las del grupo) o su id (las suyas). Es el único parámetro que hace falta.
BoletinIndependiente::alcance(int $alumnoId, int $periodoId): ?int

// Los alumnos del grupo que van por independiente en ese periodo, y los que no.
BoletinIndependiente::delGrupo(int $grupoId, int $periodoId): array

// Copiar la estructura de un alumno a otros. Transaccional. §6.2
BoletinIndependiente::copiar(...): array
```

Y **la misma decisión escrita en SQL**, para las consultas que resuelven todo un
grupo de una vez y no pueden preguntar alumno por alumno:

```php
/** El `LEFT JOIN` que hay que añadir. `m` es `matriculas`, `u` es `unidades`. */
public const JOIN_ESTADO =
    'LEFT JOIN bol_ind_periodos bip
            ON bip.alumno_id = m.alumno_id AND bip.periodo_id = u.periodo_id';

/** El dueño que le toca a cada alumno. Se compara con `u.alumno_id <=> ...` */
public const ALCANCE =
    'IF(COALESCE(bip.aplica, 0) = 1, m.alumno_id, NULL)';
```

**`<=>` y no `=`**, y es la mitad del diseño: el igual null-safe de MySQL empareja
`NULL` con `NULL`, así que **una sola condición resuelve las dos ramas** —el
alumno normal contra las unidades del grupo, el independiente contra las suyas—
sin un `OR` que cada consulta escribiría de una forma distinta. Con `=` a secas
la rama del alumno normal devuelve cero filas y **todas las definitivas del
colegio se van a 0** sin un solo error en el log.

**Dos formas y ninguna tercera.** Si aparece una consulta que necesita una
tercera, es que hace falta un método más en el servicio, no un `OR` a mano.

---

## §4 — La regla que hace esto desplegable

> **Con la migración puesta y ningún alumno marcado, todas las respuestas de la
> API son byte a byte las de hoy.**

Es comprobable y es el criterio de aceptación de la fase 1: `alumno_id` nace
`NULL` en las unidades que ya existen, `boletin_independiente` nace 0 en todas
las matrículas, `bol_ind_periodos` nace vacía y el interruptor de puestos nace
en 1. **Los tests y sus snapshots tienen que seguir verdes sin regenerar ni uno**
—eran 1.344 cuando se escribió esto y el 31 ago 2026 son **1.579**; el criterio es
«ni una instantánea regenerada», no una cifra. Un snapshot que haya que regenerar en la fase 1 no es un snapshot que
se regenera: es una consulta a la que se le olvidó el alcance.

Eso es lo que permite desplegar el backend en los quince colegios **antes**
de que exista una sola pantalla, que es como tiene que ir (§10).

---

## §5 — Las fases

| | Qué | Depende de |
|---|---|---|
| **0** | **Medir.** `tools/unidades-sin-alcance.py`. Corrido el 31 ago 2026: **78 lecturas de `unidades` y 72 de `subunidades`**, de las cuales **29 sitios (60 lecturas) son la población real de la fase 1**. Ver el aviso de abajo, que es lo que hay que leer antes de creerse la cifra grande | — |
| **1** | **Las migraciones + el servicio + el alcance en los 29 sitios.** Sin ninguna pantalla, sin ninguna ruta nueva. Termina cuando la §4 se cumple y el detector dice **0 en «hay que acotarla» sin alcance** | 0 |
| **2** | **La marca y su escritor.** `PUT boletin-independiente/periodo` (§6.3, **subida desde la fase 4**: con la decisión 7 es el único escritor que hay), la guarda de la decisión 5 (§2.3), `bol_independiente_periodos` en `putShow` (§6.4) y la columna derivada en `Grupo::alumnos`. **Una ruta nueva** | 1 |
| **3** | **Las planillas normales.** `putDetailed` deja de devolver a los independientes y `verificarCrearNotas` deja de crearles notas del grupo. Devuelve `independientes` para que el front sepa a cuántos no está viendo | 1, 2 |
| **4** | **La pantalla nueva.** Las **dos** rutas que quedan de la §6 —`planilla` y `copiar`—, porque la tercera se fue a la fase 2 | 1, 2 |
| **5** | **Los boletines.** Las dos funciones de `Unidad`/`Subunidad` con alcance — ya hechas en la fase 1 si el barrido fue completo; aquí sólo se **prueban en negativo** con un alumno marcado | 1 |
| **6** | **Los puestos** y el interruptor de `years`, con el `puesto: null` de la decisión 6 | 1, 2 |
| **7** | **El front.** Ver §8. **No se publica hasta que la 1–6 estén DESPLEGADAS** en los quince, no fusionadas | todo lo anterior desplegado |

> ### El criterio «0 sin alcance» era inalcanzable, y la cifra grande no es la población
>
> **Corregido el 31 ago 2026 corriendo el detector, no releyéndolo.** La fase 1 decía que termina
> cuando el detector diga **0 sin alcance**, y el detector dice hoy **72 de 78** y **62 de 72**. Las
> dos cosas son ciertas y juntas engañan:
>
> | | `unidades` | `subunidades` |
> |---|---|---|
> | lecturas totales | 78 | 72 |
> | **«bien por construcción»** (llegan por id o por nota) | **38** | **46** |
> | **«hay que acotarla»** (por asignatura o por alumno) | **42** | **27** |
> | de ésas, **ya acotadas** | 5 | 4 |
> | **de ésas, PENDIENTES** | **37** | **23** |
>
> Las «bien por construcción» **nunca van a nombrar `alumno_id`** —una consulta que entra por
> `unidad_id = :id` no elige nada, el id ya es de su dueño—, así que **`0 sin alcance` no se puede
> alcanzar jamás** y la fase 1 no podría darse por terminada nunca. El criterio bueno es **0 en la
> columna «hay que acotarla»**.
>
> Y las 60 lecturas pendientes **viven en 29 sitios**, porque una misma consulta cuenta una vez por
> tabla y por `join`: `DefinitivasDeAsignatura::selloDeVersion` sale cinco veces y es un método.
> **La población de la fase 1 son 29 sitios, no 134.**
>
> Es la regla del `CLAUDE.md` en su forma que muerde: *un detector puede contar bien un síntoma y no
> estar contando la causa*. El síntoma —«no nombra `alumno_id`»— está contado bien; la causa
> —«devuelve las filas de otro»— sólo afecta a 29. **El primer sitio donde mirar cuando el número
> sale raro es el detector**, y aquí el detector no estaba mal: estaba contestando otra pregunta, y
> la fase 1 le estaba pidiendo la cifra de la columna equivocada.
>
> **Y el censo se vuelve a correr antes de encender la fase 1** (§9.6): clasificó lo que había el día
> que se corrió, y un arreglo posterior puede convertir una lectura inocua en un escritor. Las
> lecturas totales ya se movieron de **74 y 70** a **78 y 72** desde que se escribió este plan.

### Lo que ya está acotado — la fase 1 en marcha

| Sitio | Qué era, y qué hace ahora |
|---|---|
| ✅ `DefinitivasDeAsignatura::porcentajeDeLasUnidades` | **Era el único que no se podía acotar añadiendo una condición**, y por eso llevaba un rojo puesto en vez de un arreglo: devolvía un `float` a la pregunta *«¿las unidades suman 100?»*, que con dos boletines **no tiene una sola respuesta** — sumaba el reparto del grupo y el de cada marcado y daba **un número que no era el de ninguno**. El rojo esperaba «las dos preguntas del §2, que son de Joseth»; contestadas el 31 ago, se levantó el bloqueo. Ahora recibe `?int $alcance` **sin defecto** —quien no sepa de qué boletín pregunta, no compila— y `PorcentajeDeUnidadesConIndependienteTest` sale del grupo `rojo` y entra en la suite con tres casos |
| ✅ `DefinitivasDeAsignatura::recalcular` | La guarda **«sin unidades no se escribe»** del 28 ago era un `EXISTS` sobre la asignatura entera. Ver el aviso de abajo: era exacta mientras cada asignatura tuviera un solo reparto |
| ✅ `DefinitivasDeAsignatura::calcular` | Ya estaba acotada; ahora devuelve además **`dueno`** —el `ALCANCE` de cada fila— para que la guarda de arriba pueda preguntar por boletín **sin una consulta por alumno** |
| ✅ `NotaFinal::consultaAlumnosGrupoNotaFinal` | La consulta de la pantalla de definitivas por periodo. Sus **cuatro derivadas** —una por periodo— sumaban, para un marcado, **las notas que conserva en las subunidades del grupo** (que marcar **no borra**, §1) **más** las de sus unidades propias. La forma «de más» de la §9.2, y sale por pantalla acusando de estar mal a la definitiva guardada, que es la correcta. **Era una propiedad estática y pasó a método**: PHP no admite una llamada en el inicializador de una propiedad, así que la forma vieja era el techo, no una preferencia |
| ⏸️ `DefinitivasDeAsignatura::selloDeVersion` · `::estadoDelGrupo` | **Leídos y NO tocados, y eso también es cerrarlos.** Son sellos de caché: sobre-aproximar hace que recalculen de más —cuesta tiempo, nunca sirve un dato viejo—; acotarlos haría que **sirvieran un dato viejo sin un error en el log**. Aquí el criterio del lote mete el fallo, y el porqué ya vivía en el propio código |
| ⏸️ `NotaFinal::calcularAsignaturaPeriodo` | **Código muerto**: cero llamadores en todo `app/`. No se acota código muerto —la regla de la casa para lo que no tiene camino es que lo decide Joseth con los otros 34—, pero **escribe definitivas**, así que lleva un aviso: resucitarlo sin el alcance le sumaría a cada independiente los dos repartos y los guardaría en `notas_finales` |

> **La contabilidad, con la distinción que se pierde al copiar una cifra:** de los **29** originales
> hay **7 resueltos** —4 acotados, 2 leídos y descartados a propósito, 1 muerto y anotado— y
> **22 pendientes de verdad**. El detector lista **23**, porque sigue contando el muerto; y lista
> **27** en total, porque sigue contando los cuatro de `DefinitivasDeAsignatura` que ya están
> decididos. **Ninguna de las tres cifras está mal: contestan preguntas distintas**, y por eso van
> las tres escritas.

> **La guarda del 28 ago contestaba la pregunta de otro, EN LAS DOS DIRECCIONES.** Los dos casos dan
> el mismo síntoma —una definitiva en cero— por motivos opuestos:
>
> | Quién no tiene unidades | Qué pasaba |
> |---|---|
> | **el grupo**, y sí un independiente | `hay = 1`, y a los del grupo se les escribe **el cero que esa guarda existe para no escribir**. Es el fallo del 28 ago entrando otra vez por una puerta nueva. Medido sobre el seed al reproducirlo: **67 definitivas** |
> | **el marcado**, y sí el grupo | `hay = 1`, y se escribe **su** cero: la §9.1 con cara de nota — *«todavía no le han hecho el boletín»* leído como *«sacó cero»* |
>
> Ahora la pregunta es **por dueño**: una consulta da qué boletines tienen alguna unidad viva
> (`NULL` es el del grupo) y `calcular()` ya trae el dueño de cada fila. **Con nadie marcado no mueve
> nada y es comprobable**: el conjunto es `{NULL}` si hay unidades y vacío si no, que son exactamente
> las dos ramas del booleano de antes.
>
> Lo fija `PuertaSinUnidadesPorBoletinTest`, **con los dos escenarios construidos**: con nadie marcado
> los dos son inalcanzables —«el boletín del grupo tiene unidades» y «la asignatura tiene unidades»
> son la misma frase—, así que la suite entera no podía verlos. Comprobado en rojo contra la puerta
> vieja antes de darlo por bueno.

**Los sitios pendientes — 28 tras lo de arriba, medidos el 31 ago 2026** (`--csv`, columna `veredicto = hay que acotarla`
y `estado = no`):

```
app/Console/Commands/EnviarNotificaciones.php:195       avisosDeNotas
app/Http/Controllers/AsignaturasController.php:55       putDetalleAsignatura
app/Http/Controllers/BolfinalesController.php:474       perdidasPorAlumnoDelGrupo
app/Http/Controllers/BolfinalesController.php:536       perdidasPorDefinitivaDelGrupo
app/Http/Controllers/ChangeAskedController.php:511      datos_de_docentes_este_anio
app/Http/Controllers/ChangeAskedController.php:1232     asignaturas_dia
app/Http/Controllers/Informes/BolfinalesController.php:717   perdidasPorAlumnoDelGrupo
app/Http/Controllers/Informes/BolfinalesController.php:765   perdidasPorDefinitivaDelGrupo
app/Http/Controllers/Informes/InformesController.php:107     grupos_desactualizados
app/Http/Controllers/Informes/NotasPerdidasController.php:54,65    putProfesorGrupos
app/Http/Controllers/Informes/NotasPerdidasController.php:271,287  putTodos
app/Http/Controllers/NotasController.php:73,156         putDetailed
app/Http/Controllers/PeriodosController.php:274         putCopiar          ← §9.4
app/Http/Controllers/SubunidadesController.php:362      putEliminadas
app/Http/Controllers/UnidadesController.php:26          (fuera de método)
app/Http/Controllers/UnidadesController.php:64          putDeAsignaturaPeriodo
app/Http/Controllers/UnidadesController.php:359         putEliminadas
app/Http/Controllers/UnidadesController.php:398         getTrashed
app/Models/NotaFinal.php:70                             (fuera de método)
app/Models/NotaFinal.php:280                            calcularAsignaturaPeriodo
app/Models/Unidad.php:237                               informacionAsignatura
app/Services/DefinitivasDeAsignatura.php:567            selloDeVersion
app/Services/DefinitivasDeAsignatura.php:733            estadoDelGrupo
```

Y los tres que **ya salieron** de esta lista el 31 ago 2026 (tabla de arriba): `recalcular`,
`calcular` —que era el falso positivo— y `porcentajeDeLasUnidades`. El detector sigue contando
`calcular` como «sin alcance» porque el alcance se traspasa fuera de su derivada: **es la fila que
enseña que esta lista ordena candidatos y no fallos**.

> **Cada fila se lee, no se arregla en lote.** Una consulta sin alcance puede estar bien: la pantalla
> de estructura del docente quiere las del grupo a propósito, y ahí lo correcto es
> `u.alumno_id IS NULL`. La lista ordena candidatos; no es una lista de fallos.
>
> **Y hay un falso positivo demostrado en la propia lista, que sirve de patrón:**
> `DefinitivasDeAsignatura::calcular` (:472) sale como «sin alcance» **y está acotada**. Su `u` vive
> dentro de una derivada que devuelve `u.alumno_id AS dueno`, y la comparación ocurre **fuera**, en
> `c.dueno <=> ALCANCE`. El detector busca el `<=>` junto al alias de `unidades` y ahí no está: el
> alcance **se traspasa**, que es exactamente la familia de `SubunidadDeUnaUnidadConDuenoTest`. Lo
> demuestra `DefinitivaConAlcanceTest`, que está verde. **Antes de tocar una fila de esta lista, se
> mira si ya hay un test que la cubra.**

Las fases 1 a 6 son de este repo y se pueden fusionar en una sola tanda de
despliegue. La 7 es de `myvc_front`.

---

## §6 — El contrato: qué se añade a la API

**Tres rutas nuevas.** De **543** a **546** — el plan decía «de 542 a 545» y ese
543 se movió el 28 ago con `PUT users/mi-docente`. Una ruta nueva es una decisión:
éstas son las tres y no hay una cuarta. Todo lo demás **reutiliza lo que existe**.

**Y ya no entran las tres juntas:** `PUT boletin-independiente/periodo` (§6.3) va en la **fase 2**
porque con la decisión 7 es el único escritor de la marca; las otras dos siguen en la fase 4.

### 6.1 · `PUT boletin-independiente/planilla` · `auth.personal`

La pantalla nueva, entera, en una petición.

```jsonc
// petición
{ "asignatura_id": 812 }            // el periodo es el del usuario, como en notas/detailed

// respuesta
{
  "asignatura": { "asignatura_id": 812, "materia": "Matemáticas", "grupo_id": 44, ... },
  "periodo":    { "periodo_id": 91, "numero": 3 },
  "alumnos": [
    {
      "alumno_id": 3311, "nombres": "...", "apellidos": "...", "foto_nombre": "...",
      "aplica": true,                    // false = «este periodo no tiene boletín independiente»
      "porcentaje_unidades": 100,        // la suma REAL. != 100 se pinta en rojo, no se corrige
      "definitiva": { "nota": 78, "manual": false, "recuperada": false },
      "unidades": [
        { "unidad_id": 9912, "definicion": "...", "porcentaje": 40, "orden": 0,
          "subunidades": [
            { "subunidad_id": 55021, "definicion": "...", "porcentaje": 50, "orden": 0,
              "nota": { "id": 880431, "nota": 80 } }
          ] }
      ]
    }
  ]
}
```

- **`aplica: false` viene con sus unidades y sus notas dentro.** Es la §1: los
  datos no se borran, se ignoran. La pantalla los enseña en gris; el boletín no
  los mira.
- **`porcentaje_unidades` se devuelve y no se corrige**, por la misma decisión
  que en el [10](10-definitivas.md) §9.3: una estructura mal configurada da una
  definitiva rara y **que se note es lo que la delata**.
- **`unidades: []` viene con `motivo`, y eso lo pidió el front el 24 ago.** Un
  vacío que no dice por qué está vacío se lee como «no hay datos» cuando lo que
  hay es un fallo — el front lleva una noche entera cazando esa forma exacta, y
  su regla es que **«vacío legítimo» no significa «pantalla útil»**. Así que la
  respuesta no manda una lista vacía a secas:

  | `motivo` | Qué pasó, y con qué se distingue |
  |---|---|
  | `"asignatura_sin_montar"` | **tampoco hay unidades del grupo** en esa asignatura y periodo: el docente no ha entrado todavía. No es culpa de la marca y le pasa igual a los treinta |
  | `"sin_estructura_propia"` | **el grupo sí tiene unidades y este alumno no**: el docente entró, montó lo suyo y **a este alumno no le hizo nada**. Es el caso de la §9.1 y el único que la pantalla tiene que gritar |
  | `"vaciada"` | tuvo unidades propias y hoy están todas borradas. Distinto de no haber tenido nunca, y sólo se sabe mirando `deleted_at` |

  Se distinguen con datos que ya existen —las unidades del grupo, las del alumno
  y sus `deleted_at`—: no hace falta guardar nada nuevo para contestar la
  pregunta, sólo hace falta que el endpoint la conteste **en vez de dejársela a
  la pantalla**, que desde el navegador no puede.

#### `estructura_del_grupo`, para la vista previa de copiar — pedido y aceptado el 31 ago 2026

```jsonc
"estructura_del_grupo": [
  { "periodo_id": 91, "numero": 1, "unidades": 4, "subunidades": 9, "porcentaje_unidades": 100 },
  { "periodo_id": 92, "numero": 2, "unidades": 0, "subunidades": 0, "porcentaje_unidades": 0 }
]
```

Lo pidió el front al escribir la pantalla 3, y **entra porque la alternativa está envenenada**. El
diálogo de copiar enseña qué se va a copiar **antes** de copiar; con `origen.tipo: "alumno"` los
datos ya vienen en esta misma respuesta, pero con `"grupo"` la única fuente sería
`GET unidades/de-asignatura-periodo/{asignatura}/{periodo}` — **y esa ruta escribe**:

- si esa asignatura no tiene unidades en ese periodo y quien mira puede editar, **inserta las
  `unidades_por_defecto` y sus `subunidades_por_defecto` del año**, y las inserta **sin `alumno_id`**,
  o sea **estructura del grupo**: una vista previa montaría el periodo entero del curso;
- y `Unidad::arreglarOrden` hace un `UPDATE` por unidad **y otro por subunidad en cada lectura**,
  guardado sólo por `if ($puedeEscribir)`. *(El `$orden_duplicado` que se calcula veinte líneas antes
  **no lo lee nadie**: es una variable muerta dentro de un método que escribe. Medido: 15 pares
  (asignatura, periodo) de 3.930 tienen hoy algún orden repetido — o sea que lo que arregla es real,
  pero lo reescribe en los 3.930.)*

**Esa ruta NO se cambia para que sirva de previa**, y decirlo importa: que lea y escriba es una
decisión tomada —[05 §47.2](05-codigo-muerto-y-roto.md), Joseth— y con el periodo abierto **crea
queriendo**. Quitarle la escritura para desbloquear una pantalla sería colar una decisión del colegio
dentro de un arreglo del front. La salida es que el front **no tenga que llamarla**.

`porcentaje_unidades` lleva **el mismo nombre y el mismo número** que el de cada alumno de esta
respuesta, para que la pantalla no tenga dos campos que significan lo mismo. Y es una llamada directa
a `DefinitivasDeAsignatura::porcentajeDeLasUnidades($asignatura, $periodo, null)` — el `null` es «el
boletín del grupo», desde que ese método recibe el alcance (§5, fase 1).

**Y habilita algo mejor que un aviso:** con el recuento por periodo, la pantalla puede **apagar el
periodo que no tiene nada montado** en vez de dejar copiar un vacío. Es el `copiado` con
`unidades: 0` de la §6.2, pero visto **antes del clic** en vez de después.

### 6.2 · `POST boletin-independiente/copiar` · **DOS orígenes, no uno** — reescrita el 31 ago 2026

**Encargo de Joseth por `myvc-front-c5`:** *«que se puedan copiar unidades/subunidades tanto de otro
boletín que se le creó de manera independiente a otro estudiante como de las unidades/sub específicas
de asignaturas en algún periodo»*. La versión anterior de esta sección tenía **un solo origen
implícito** —otro alumno de la misma lista, misma asignatura, mismo periodo— y el caso normal no
cabía: el estudiante que vuelve y sigue el plan del curso, copiando **del periodo que sí está
montado**.

```jsonc
// petición
{
  "asignatura_id": 812, "periodo_id": 93,        // el DESTINO
  "alumnos_destino": [3311, 3402],
  "origen": { "tipo": "grupo",  "periodo_id": 91 },
  //     o : { "tipo": "alumno", "alumno_id": 2199, "periodo_id": 91 },
  "con_notas": false,
  "si_ya_tiene": "saltar"                        // "saltar" | "anadir" | "reemplazar"
}

// respuesta
{
  "origen": { "tipo": "grupo", "periodo_id": 91, "unidades": 4, "subunidades": 9 },
  "destinos": [
    { "alumno_id": 3311, "resultado": "copiado",
      "copiadas":  { "unidades": 4, "subunidades": 9, "notas": 0 },
      "retiradas": { "unidades": 0, "notas_que_dejan_de_contar": 0 },
      "porcentaje_unidades": 100 },
    { "alumno_id": 3402, "resultado": "saltado", "motivo": "ya_tiene_estructura",
      "porcentaje_unidades": 80 }
  ]
}
```

**`origen.asignatura_id` no existe, y es la respuesta a la pregunta 1 del front: sólo la misma
asignatura.** Se comprueba con **422** y no con un selector. Tres razones, y la tercera es la que
decide:

- `asignaturas` es `(materia_id, grupo_id)` y **no tiene `periodo_id`**, así que «la misma asignatura
  en otro periodo» ya cubre el caso A entero. Lo que abriría un `origen.asignatura_id` no es «otro
  periodo»: es **otra materia o, peor, otro grupo**.
- Y eso último es un **id del cuerpo que no comprueba nadie** —la familia de
  `tools/identificadores-del-cuerpo.py`—: el docente de 5A tirando de la estructura de 11B. No es un
  dato personal, pero `auth.personal` no lo para y nadie lo pidió.
- **Ya existe la puerta para eso y es otra**: `PUT periodos/copiar` copia **de una asignatura y
  periodo a OTRA asignatura y periodo**. Abrir ésta a otra asignatura sería **una segunda puerta para
  la misma operación con reglas distintas**, que es exactamente el patrón que este repositorio lleva
  pagando desde los seis escritores de la definitiva.

#### Los dos orígenes se leen con alcances CONTRARIOS, y por eso van por el servicio

Es la trampa de esta ruta y no se ve en el JSON:

| `origen.tipo` | Qué filas de `unidades` lee |
|---|---|
| `"grupo"` | las del grupo: **`u.alumno_id IS NULL`** |
| `"alumno"` | las de ese alumno: **`u.alumno_id = origen.alumno_id`** |

**Las dos ramas se escriben con `BoletinIndependiente`, nunca a mano.** Un `= $alumno_id` copiado a
la rama del grupo devuelve cero filas y **copia una estructura vacía en 200**, que es el fallo mudo
de siempre. Y el destino se comprueba contra el **periodo de DESTINO**, no el de origen: sólo se
copia a alumnos que van por independiente en `periodo_id`; los demás vuelven como
`resultado: "no_marcado"`, nunca como 400 — la pantalla los está listando y que uno se desmarque
entre la carga y el clic es normal.

#### `si_ya_tiene`: tres valores, y `reemplazar` NO borra lo que parece

Respuesta a la pregunta 2, y **trae una corrección al aviso que el front quiere pintar.**

| valor | Qué hace |
|---|---|
| `"saltar"` | **el defecto.** El destino con estructura propia vuelve con `resultado: "saltado"` y `motivo: "ya_tiene_estructura"`. Es lo que hacía el `reemplazar: false` de antes, ahora con nombre |
| `"anadir"` | añade las unidades del origen a las que ya tiene. **Puede dejar la suma por encima de 100 y no se corrige** (§6.1) |
| `"reemplazar"` | retira las propias del destino en ese `(asignatura, periodo)` y pone las del origen |

> **`reemplazar` no borra ni una nota, y el «¿está seguro?» no puede decir que sí.** Medido en
> `UnidadesController::deleteDestroy`: retirar una unidad es un **borrado en blando de la unidad y de
> nada más** — las subunidades y las notas **conservan su `deleted_at` a null** y siguen ahí. Salen
> de todos los cálculos porque cada lectura une `unidades u ... AND u.deleted_at IS NULL`, no porque
> se hayan ido. Y **`PUT unidades/restore/{id}` la devuelve entera, con sus subunidades y sus notas
> dentro**: la papelera de unidades ya existe y ya está enrutada.
>
> Por eso la respuesta trae **`retiradas.notas_que_dejan_de_contar`** y no `notas_borradas`. No es un
> matiz de nombre: *«se borrarán 9 notas»* es **falso** y asusta de una forma que hace que el docente
> no use el botón, y *«9 notas dejan de contar; se pueden recuperar desde la papelera»* es cierto y
> es una decisión distinta.
>
> **Y sólo toca unidades con `alumno_id = destino`.** Jamás una del grupo, ni una de otro alumno —
> retirar por `(asignatura_id, periodo_id)` sin el dueño le vaciaría la planilla a los treinta. Es la
> invariante que necesita su propio test, en los dos sentidos.

**El recuento para avisar ANTES no sale de aquí, y el front ya lo tiene.** Una cifra que llega en la
respuesta llega **después** de la acción: como advertencia no sirve. Lo que la pantalla enseña en el
«¿está seguro?» sale de `PUT boletin-independiente/planilla` (§6.1), que ya devuelve las unidades,
las subunidades y las notas de cada alumno. Las cifras de la respuesta valen para lo otro: **que una
discrepancia entre lo avisado y lo hecho se vea** en vez de quedarse en que la pantalla iba vieja.

#### `con_notas` significa dos cosas distintas, y una de ellas hay que prohibirla

- **`origen.periodo_id !== periodo_id` con `con_notas: true` → 422.** Copiar la estructura del
  periodo 1 al 3 es preparar la planilla; copiar **también las notas** es escribir en el periodo 3
  las calificaciones del 1. Eso no es una copia, es inventar un dato, y **el navegador no puede
  decidirlo** porque desde la pantalla las dos casillas parecen igual de inocentes.
- **Con el mismo periodo, el valor depende del origen y las dos ramas son deliberadas:**
  - `tipo: "grupo"` → se copian **las notas que el alumno de destino ya tenía él mismo** en las
    subunidades del grupo. Es el caso que hace útil esta operación: el alumno iba en la planilla, se
    le marca a mitad de periodo y **se lleva lo suyo** en vez de empezar en blanco (§9.3 por la otra
    puerta).
  - `tipo: "alumno"` → se copian **las del alumno de origen**. Eso es calificar a varios de golpe, y
    por eso `con_notas` es un botón aparte y el docente tiene que decirlo.

  **Son dos implementaciones, no una con un parámetro**, y quien escriba sólo la segunda creerá que
  ha hecho las dos: en las dos el SQL sale de `notas n ON n.subunidad_id = s.id`, y lo único que
  cambia es de quién es el `n.alumno_id`.

#### Lo que no cambia de la versión anterior

- **Una transacción para todo el lote**, y **un recálculo por alumno al final y fuera de ella** — lo
  que aprendió `PUT notas/lote`: media copia deja definitivas calculadas sobre estados intermedios.
- **No se reutiliza `PUT periodos/copiar`**, y no es por gusto: `putCopiar` escribe en un `foreach`
  **sin transacción**, según su propio test de contrato. Rótulos acordados con el front: aquí
  *«Copiar de…»*, allí *«Copiar unidades a otro grupo o periodo»*.
- **`porcentaje_unidades` se devuelve y no se corrige** (§6.1, y respuesta a la pregunta 3 del
  front): la suma resultante viaja **por destino** y con el mismo nombre que ya usa la planilla, para
  que la pantalla no tenga dos campos que significan lo mismo. Que `anadir` deje un 160 **se ve, y
  que se vea es lo que lo delata**.


### 6.3 · `PUT boletin-independiente/periodo` · **FASE 2**, no fase 4

```jsonc
{ "alumno_id": 3311, "periodo_id": 91, "aplica": false }
→ { "alumno_id": 3311, "periodo_id": 91, "aplica": false }
```

> **`periodo_id` VA EN EL CUERPO, y esto lo corrigió el front el 31 ago 2026 con razón.** Aquí ponía
> *«el periodo es el del usuario»*, copiado de `notas/detailed`, y **con esa forma la pantalla 1 no
> puede hacer lo que se pidió**: la ficha marca cualquiera de los cuatro periodos —incluido uno
> cerrado, que la §2.4 confirma que se puede y se quiere—, y **el periodo del token es el activo, que
> casi nunca es el del accidente**. Un backend que sacara el periodo del token marcaría **siempre el
> activo, en silencio y con 200**: exactamente el modo de fallo que este módulo lleva dos revisiones
> quitando. El front ya lo manda explícito y tiene una prueba que lo fija.
>
> **Y con eso entra una guarda que antes no hacía falta.** Un `periodo_id` que llega del cuerpo es la
> familia de `tools/identificadores-del-cuerpo.py`, así que el método comprueba **las dos cosas, y no
> le basta la clave foránea** —que sólo obliga a que el alumno y el periodo existan, no a que tengan
> algo que ver—:
>
>   1. que el periodo sea de un año sobre el que quien llama puede actuar;
>   2. que el alumno **esté matriculado en el año de ese periodo**.
>
> Sin la 2, un `alumno_id` y un `periodo_id` de años distintos escriben una fila que `consultar()`
> devolvería como buena — y `consultar()` **ya no lo comprueba a propósito** (§2.2): esa validación
> es de quien escribe, porque ponerla en la lectura la cobraría en cada boletín impreso para
> defenderse de un estado que el escritor no debe dejar crear.

`INSERT ... ON DUPLICATE KEY UPDATE` sobre `bol_ind_periodos`. **No borra ni una
fila de `unidades`, `subunidades` ni `notas`, nunca.** Hay un test que apaga y
enciende el interruptor y cuenta las filas antes y después.

**Subió de la fase 4 a la fase 2 el 31 ago 2026, y no es una reordenación de comodidad:** con la
decisión 7 ésta es **la única escritura de la marca que hay**. `PUT alumnos/guardar-valor` salió del
camino (§2.1 punto 4), así que la fase 2 sin esta ruta no tiene nada que escribir y la fase 3 no
tendría cómo montar un solo caso con datos de verdad.

**Su guarda es la decisión 5** y `auth.personal` **no basta**: un docente es personal. Hace falta el
método nuevo de `Autoriza` de la §2.3 —administrador, secretario o rector, superusuario por encima—,
y explícitamente **no el titular del grupo**, que hoy sí escribe la rama de matrícula de
`GuardarAlumno::valor`. La decisión 5 es más estrecha que «como está hoy».

**Y sí acepta un periodo cerrado**, por la §2.4 — que es justamente por lo que `periodo_id` tiene
que venir del cuerpo y no del token. Lo que no puede hacer es sembrar las notas de la
§9.3 preguntando `permiteEditarNotas`: ahí está la trampa, y está medida en esa misma sección.

**Lo que hay que validar antes de escribir la fila**, porque `consultar()` ya no lo comprueba a
propósito: que el alumno **esté matriculado en el año de ese periodo**. La clave foránea sólo obliga
a que el alumno y el periodo existan, no a que tengan que ver el uno con el otro. Esa comprobación es
de quien escribe; ponerla en la lectura la cobraría en cada boletín impreso para defenderse de un
estado que el escritor no debe dejar crear.

### 6.4 · Los campos añadidos a lo que ya existe

Campos **añadidos**, no cambiados: las claves de hoy siguen todas ahí, y el
front tiene que tolerar que no vengan —`app/` es copia por colegio y durante el
despliegue habrá colegios con el código viejo.

| Endpoint | Campo | Qué dice |
|---|---|---|
| `PUT notas/detailed` (la planilla) | `independientes: [{alumno_id, nombres, apellidos}]` | **a quién NO estás viendo en esta lista**, para que la planilla lo diga en vez de que el docente crea que se le perdió un alumno. **Sin `aplica`** — ver el aviso de abajo |
| `PUT notas/detailed`, `Grupo::alumnos` | `alumno.bol_independiente_datos` | **el badge, y es un campo nuevo con nombre nuevo.** `true` = este alumno **tiene un boletín aparte guardado en este periodo** aunque el periodo vaya con el grupo. Es el mismo dato que `tiene_datos` en la ficha, aplanado al periodo del token |
| ~~`PUT alumnos/guardar-valor`~~ | ~~acepta `propiedad: "boletin_independiente"`~~ | **DECAE con la decisión 7.** La escritura ya no pasa por aquí: el único escritor es `PUT boletin-independiente/periodo` (§6.3) |
| `PUT alumnos/show` | **`bol_independiente_periodos`** | por donde **la ficha lee** la marca. Ya no es un booleano: es la lista de los periodos del año con su estado. Ver el bloque de abajo |
| boletines 1, 2 y 3 | `asignatura.bol_independiente: true` | para que el boletín pueda rotularlo si el colegio quiere; las unidades ya vienen siendo las suyas |
| puestos | `alumno.bol_independiente_periodo` · `puestos_con_bol_independiente` | el interruptor viaja en la respuesta para que la pantalla explique por qué falta alguien. Y por la decisión 6, el puesto del independiente viaja como **`null`** y el front pinta `—` |

> ### Dos campos que iban a llegar valiendo siempre lo mismo — cazado por el front el 31 ago 2026
>
> La §6.4 decía que en la planilla quedaba `alumno.bol_independiente_periodo` y que **el badge era
> ese campo**. Lo levantó el front al ir a escribirlo, y el argumento es exacto:
>
> > «El alumno que llega a la planilla es, por definición, uno con `aplica = 0` — los que van aparte
> > no vienen en `alumnos`. O sea que ese booleano **no distingue al que tiene un boletín aparte
> > guardado del que nunca ha tenido nada**, y ésos son justo los dos casos que el badge separa.»
>
> **Es peor que insuficiente: es constante.** En `alumnos` sólo caben los que tienen `alcance() ===
> null`, así que `bol_independiente_periodo` valdría `false` en las 30 filas, siempre, en todos los
> colegios. Un campo que no varía **no es un campo pobre, es un campo que miente por omisión**: quien
> lo lea ramificará sobre él y su rama muerta no se notará jamás.
>
> **Y la misma medicina destapa un segundo, que nadie había mirado: `aplica` dentro de
> `independientes`.** Ese array lista a los que NO vienen en `alumnos`, o sea los que tienen
> `alcance() !== null`, o sea **`aplica = 1` por construcción**. Otra constante. Los dos son restos
> del modelo viejo: se diseñaron cuando la marca era del **año** y «marcado el año pero este periodo
> no» era un estado real. **La decisión 7 lo eliminó**, y estos dos campos son lo que quedó flotando.
>
> **Lo que entra:** un campo con nombre propio, **`bol_independiente_datos`**, que es exactamente
> `tiene_datos` de la ficha aplanado al periodo del token. Se descartó la otra salida que ofrecía el
> front —redefinir `bol_independiente_periodo` para que significara «tiene datos»— porque **el nombre
> diría una cosa y el campo otra**, y ese desajuste sobrevive a cualquier documento.

#### `bol_independiente_periodos`, el campo que la ficha necesita — nombre fijado el 31 ago 2026

```jsonc
// dentro de la respuesta de `PUT alumnos/show`
"bol_independiente_periodos": [
  { "periodo_id": 91, "numero": 1, "aplica": false, "tiene_datos": false },
  { "periodo_id": 92, "numero": 2, "aplica": true,  "tiene_datos": true  },
  { "periodo_id": 93, "numero": 3, "aplica": false, "tiene_datos": true  },
  { "periodo_id": 94, "numero": 4, "aplica": false, "tiene_datos": false }
]
```

**Vienen SIEMPRE todos los periodos del año, no sólo las filas que existen en `bol_ind_periodos`.**
Es la decisión de forma y no es cosmética: una lista con sólo las filas presentes obliga al front a
decidir qué significa una ausencia, y **este módulo acaba de perder una semana justamente por leer
una ausencia al revés**. Mandar los cuatro deja al cliente sin ese default que inventar.

**Los cuatro estados son `aplica` × `tiene_datos`, y los cuatro significan algo distinto:**

| `aplica` | `tiene_datos` | Qué es, y qué pinta la ficha |
|---|---|---|
| `false` | `false` | **el caso de todo el mundo hoy.** Ni fila ni estructura propia: va con el grupo. Sin badge |
| `true` | `true` | va aparte y tiene su estructura montada. El estado normal de un marcado |
| `true` | `false` | **va aparte y NO tiene ni una unidad propia.** Es la §9.1 —el alumno que se cae por el hueco— vista desde la ficha, y **es el único que la pantalla tiene que gritar**: su definitiva va a salir 0 y nadie va a recibir un error |
| `false` | `true` | **«este periodo va con el grupo», con sus datos guardados.** Es literalmente lo que se pidió: *«no debe borrar los datos … pero esos datos deben ser ignorados»*. La ficha lo enseña en gris y el badge dice que hay algo guardado que no se está usando |

**`tiene_datos` lo contesta el backend porque el navegador no puede.** Es «¿tiene este alumno alguna
unidad propia viva en ese periodo?», o sea un `EXISTS` sobre `unidades` con `alumno_id` y
`periodo_id` — la columna izquierda de `unidades_alcance_index` no sirve aquí, así que **es la
consulta a mirar con `EXPLAIN` antes de darla por buena**. El front tendría que pedir una llamada por
periodo para saberlo, y la ficha se abre para todo.

**Y esto cierra la trampa que la §6.4 traía escrita.** Decía que si el alumno no tiene matrícula ese
año, `putShow` cae a una segunda consulta que sale sólo de `alumnos` y **el campo no viene** —
`undefined` significando «no matriculado este año» y no «desmarcado». Con la marca colgada de
`(alumno_id, periodo_id)` **el campo ya no depende de la matrícula**: los periodos salen del año del
token y el estado de `bol_ind_periodos`, así que se manda en las dos ramas y `undefined` deja de ser
ambiguo. Es la §2.2 vista desde el contrato.


### 6.5 · Lo que NO hace falta escribir

Y es la mitad del valor del diseño de la §3:

- **Crear, editar, borrar y reordenar las unidades del alumno**: `POST unidades`
  con `alumno_id` en el cuerpo, y `unidades/update|destroy|update-orden` **tal
  cual** — van por id y el id ya es suyo.
- **Las subunidades**: `POST subunidades` sin cambio; nacen colgadas de una
  unidad que ya tiene dueño. `SubunidadesController::postIndex` sí cambia en un
  punto: **crea las notas de un solo alumno en vez de las del grupo** cuando la
  unidad tiene dueño.
- **Las notas**: `PUT notas/update/{id}` y `PUT notas/lote` sin tocar. Y con
  ellos, la bitácora, la guarda del periodo cerrado y el recálculo de la
  definitiva en la misma respuesta.

### 6.6 · La lista blanca de `guardar-valor`, y sus DOS modos de fallo

El front preguntó si el backend acepta esa propiedad, temiendo un «No guardado»
silencioso. **Medido el 24 ago, y son dos cosas distintas:**

1. **Sin el `case`, no es silencioso: es un 422.** `GuardarAlumno::valor` tiene
   un `switch` con casos literales; lo que no está cae al `default`, que hace
   `UPDATE alumnos SET ColumnaSegura::exigir('alumnos', $propiedad)`, y
   `boletin_independiente` **no existe en `alumnos`** —vive en `matriculas`—, así
   que `ColumnaSegura::exigir` corta con **422 «Propiedad no válida»**
   (`app/Support/ColumnaSegura.php:95`). Un colegio sin desplegar contesta 422 y
   se distingue de todo lo demás.
2. **El silencioso ya existe, y es de las veinte propiedades de hoy.** El método
   termina en `if ($res) return 'Guardado'; else return 'No guardado';` sobre lo
   que devuelve `DB::update`, **y MySQL devuelve 0 filas afectadas cuando el
   UPDATE no cambia ningún valor** — no cuando no encuentra la fila. Es
   exactamente lo que costó un test en rojo en el recalculador de definitivas.
   O sea: **marcar dos veces lo mismo contesta «No guardado» con 200 y el estado
   es correcto.** No lo introduce esta función y no se arregla aquí, pero el
   front tiene que saber que **ese texto no significa que haya fallado**, y quien
   escriba la pantalla no debe pintar un error con él.
   > **Medido después, y toca a esta función por partida doble**: son **4 sitios
   > y 6 rutas**, y una de ellas es `PUT years/toggle-cambiar-valor`, o sea **por
   > donde se guarda el interruptor de puestos de la §7**. Queda abierto en
   > [09 §13](09-pendientes.md) con sus tres opciones: **no se arregla en un solo
   > lado** y no lo decide una sesión.

---

## §7 — Los puestos, que es donde esto se ve desde fuera

`PuestosController` no calcula el puesto: devuelve `promedio` por alumno y el
puesto lo pone quien pinta (`Nota::puestoAlumno`, `app/Models/Nota.php:122`,
que cuenta cuántos promedios hay por encima). **Y ese mismo cálculo está copiado
en OCHO sitios**, medido hoy: los tres boletines
(`BoletinesController:235`, `Boletines2Controller:164`, `Boletines3Controller:169`),
los dos de finales (`Informes/BolfinalesController:233`, `BolfinalesController:114`),
los certificados (`CertificadosPersonaController:180`), `EditnotaController:215` y
`PromovidosController:136`. **El interruptor se lee en el servicio y los ocho
preguntan**, o habrá pantallas contando de una forma y papeles impresos contando
de otra — que es exactamente lo que le pasó a las definitivas con sus seis
escritores.

Con `years.puestos_con_bol_independiente = 0`:

1. el independiente **no sale** en la tabla de puestos del grupo;
2. **no cuenta para el puesto de los demás** — y esto es lo que hay que decir en
   voz alta: **si un independiente iba primero, los treinta de detrás suben un
   puesto**, en la pantalla **y en el boletín impreso**;
3. su propio boletín lleva `puesto: null`, que el front pinta `—`. Calcularle un
   puesto contra una lista de la que se le sacó sería inventarlo.

Por eso el interruptor **nace en 1** —lo de hoy— y cambiarlo es una decisión del
colegio con efecto visible, no un ajuste de pantalla. Va anotado en
[DESPLIEGUE.md](../DESPLIEGUE.md) cuando esto salga.

**Y desde el front son cuatro informes, no una pantalla** (`myvc-front-10`, 24
ago): `puestos_grupo_periodo`, `puestos_grupo_year`, `puestos_todos_periodo` y
`puestos_todos_year`, más el puesto impreso en `boletin-periodo` y
`boletin-final`. Encaja con los ocho sitios de arriba y decide una cosa: **el
interruptor viaja en la respuesta de todos ellos** (`puestos_con_bol_independiente`
y **`alumno.bol_independiente_periodo`** — el nombre corregido el 31 ago 2026: de la
pareja `alumno.bol_independiente` (el año) + `alumno.bol_independiente_periodo` **quedó
sólo el segundo**, porque la decisión 7 eliminó la marca por año y con ella el estado
«marcado el año pero este periodo no». Lo decidió el front en su buzón y el lote E lo
escribió así sin poder saberlo; este documento era el que estaba viejo), en vez de que
cada pantalla lo pregunte por su
cuenta. Si lo pregunta uno y otro no, los otros tres mienten.

### 7.1 · Y el día que un colegio lo ponga a 0 — la pregunta la hará un rector

Lo pidió el front el 24 ago, y la respuesta es de las que hay que dar medidas:

- **Es reversible y no deja rastro.** El interruptor es un `tinyint` en `years`:
  no borra ni escribe nada en `notas`, `notas_finales` ni `matriculas`. Volver a
  1 devuelve exactamente los números de antes.
- **Pero no hay foto de lo que se imprimió, y ésa es la parte incómoda.** El
  puesto **no se guarda en ninguna tabla que la API lea**: se calcula al vuelo en
  cada carga, en los ocho sitios de arriba. Así que el papel ya entregado se
  queda como está en el papel, y **reimprimir ese mismo boletín dará otro
  puesto**. No es culpa del interruptor —hoy pasa igual si alguien corrige una
  nota del periodo pasado—, pero con el interruptor el cambio es **masivo y en un
  solo clic**.
- **Las tablas `df_*` no sirven de foto.** Tienen `puesto` y `puntaje`
  (`df_grupos:756`) y parecen justo eso, pero **no las lee nadie**: medido, **0
  ficheros de `app/` las mencionan**. Son un congelado de años pasados que quedó
  huérfano.
- **La recomendación, por tanto: que el colegio lo decida antes de emitir el
  primer boletín del año** y no a mitad. Y que la pantalla de ajustes lo diga con
  esas palabras, no con un tooltip.

---

## §8 — Lo que le toca al front

Cuatro pantallas. Ninguna se publica hasta que el backend esté **desplegado en
los quince** (§10).

> **Decía «los dieciséis», y se corrige el 1 sep 2026 aunque la regla de la casa sea que
> las cifras fechadas se quedan como se midieron.** No es una medición de un día: es una
> **condición futura**, y una condición futura escrita contra dieciséis colegios **se
> cumple mal** — el día que se compruebe, faltará uno que ya no existe. Uno se dio de baja
> el 25 ago 2026 y se borró entero del servidor. Las cifras **medidas** sobre dieciséis
> siguen diciendo dieciséis, y la de la fase 0 de las definitivas —más abajo— es una de
> ésas y no se toca.

1. **Marcar al alumno, POR PERIODO.** Es `PUT boletin-independiente/periodo`
   (§6.3), con `auth.personal` y la guarda de la decisión 5 —marcan
   administradores, secretario y rector—. El cuerpo lleva los tres campos y el
   periodo **va explícito**:

   ```jsonc
   { "alumno_id": 3311, "periodo_id": 91, "aplica": false }
   ```

   **Sigue siendo la pantalla más barata de las cuatro y la primera que hay que
   hacer**, porque sin ella no hay forma de probar ninguna otra con datos de
   verdad. Lo que cambia es que **no es un interruptor de dos estados en la
   ficha**: son cuatro, uno por periodo, y **se puede marcar un periodo cerrado**
   (§2.4) — que es el caso real, porque el accidente casi nunca pasa en el
   periodo activo.

   Y si la ficha quiere **enseñar** el estado, ya lo tiene sin escribir nada:
   `bol_independiente_periodos` viaja en `PUT alumnos/show` (§6.4) con los cuatro
   periodos, su `aplica` y su `tiene_datos`.

   > **Aquí ponía otra cosa, y estuvo escrito desde el 24 ago 2026 hasta el 1 sep:**
   >
   > ~~Un interruptor más en la ficha, al lado del de PIAR. Es `PUT alumnos/guardar-valor`
   > con `propiedad: "boletin_independiente"` — el endpoint que la ficha ya usa para veinte
   > campos.~~
   >
   > **Se deja tachado y no borrado porque el front pudo haberlo leído ya**: llevaba ocho
   > días ahí, y un párrafo que desaparece no avisa a quien se lo creyó.
   >
   > **Y no es que el endpoint cambiara: es que la propiedad no existe en ninguna tabla.**
   > `matriculas.boletin_independiente` **se retiró** el 31 ago 2026 (§2.2) y **nunca
   > estuvo en `alumnos`**. Medido el 1 sep: cero apariciones en
   > `database/schema/mysql-schema.sql` y cero en `GuardarAlumno.php`, que no tiene `case`
   > para ella — así que cae al `default`, `ColumnaSegura::exigir('alumnos', …)` no la
   > reconoce y la llamada **contesta 422**. Un front que siguiera este párrafo se
   > encontraría un rechazo, no un fallo silencioso; es lo único bueno del asunto.
   >
   > **La reescritura estaba decidida y pendiente, no es una decisión nueva:** la decisión
   > 7 del 31 ago ya dice *«la §8 punto 1 se reescribe: la pantalla 1 no es un interruptor
   > más en la ficha, es un interruptor por periodo»*. Lo que faltaba era hacerla.

2. **La planilla de notas, dos cambios pequeños.** El alumno marcado ya no viene
   en `alumnos`: la pantalla tiene que **decir a cuántos no está viendo** con
   `independientes` y llevar a la pantalla nueva. Y el que tiene la marca del
   año pero `bol_independiente_periodo: false` **sí viene en la lista** y lleva
   el badge: *«este periodo va con el grupo»*.
   > **Ese badge cae en una celda que acaba de cambiar**, y lo avisó el front el
   > 24 ago: esa misma noche se restauró en ella la marca de NEE **con una
   > excepción por colegio**, los marcadores `(Asis)`/`(Prem)`, la foto y la
   > numeración. **Ya son cuatro cosas condicionadas y una excepción**, así que el
   > badge se añade mirando la celda, no encima de ella.
3. **La pantalla nueva**, por asignatura y periodo: la lista de alumnos
   marcados, y bajo cada uno sus unidades y subunidades con la casilla de la
   nota. Tres cosas que no son adorno:
   - el interruptor **«este periodo no tiene boletín independiente»** por alumno,
     que **no borra nada** y deja lo escrito en gris;
   - **la suma de porcentajes** visible por alumno, en rojo cuando no es 100;
   - **copiar**, que desde el 31 ago 2026 tiene **dos orígenes** (§6.2): la estructura
     del grupo —posiblemente de otro periodo— o el boletín aparte de otro alumno. Y
     enseñar lo que devuelve, incluidos los `saltado` y los `no_marcado`.
4. **Puestos**: enseñar por qué falta alguien cuando el interruptor está en 0, y
   pintar `—` en el puesto del independiente.

**Lo que el front NO tiene que construir:** un editor de unidades nuevo. Son los
mismos endpoints de `unidades` y `subunidades` que ya usa la pantalla de
estructura, con `alumno_id` en el cuerpo al crear la unidad. Si acaba habiendo
dos editores, uno de los dos se va a quedar viejo.

### 8.1 · Esa promesa NO estaba escrita, y mandar `alumno_id` hacía daño — 1 sep 2026

**Hasta el 1 sep 2026 `UnidadesController::postIndex` no leía `alumno_id`.** El párrafo
de arriba prometía un endpoint que no existía, que es la fase 7 rompiéndose el día que el
front lo llame.

Y **lo que pasaba al mandarlo era peor que ignorarlo**: la unidad nacía **del grupo**, se
le ponía a todo el curso y el reparto de la asignatura dejaba de sumar 100 — sin un
error, sin un aviso y sin que nada lo dijera. **Lo midió el front ejecutando**, sobre la
asignatura 1235: una unidad al 10 %, 51 estudiantes, el curso al **110 %**. Lo borraron y
volvió a 100 %.

O sea que **un docente que intentara montarle el boletín a un independiente le
desordenaba la asignatura a los otros treinta**, y la única pista era que los porcentajes
dejaban de cuadrar.

Arreglado y fijado por `tests/Contrato/UnidadPropiaAlCrearlaTest.php`.

#### El contrato de `POST unidades`, que es lo que el front necesita saber

| Cuerpo | Respuesta |
|---|---|
| sin `alumno_id`, o vacío | **201**, unidad **del grupo** — exactamente lo de hoy, sin cambio |
| `alumno_id` de un alumno matriculado en el grupo de esa asignatura **y marcado en ese periodo** | **201**, unidad **suya**: no la ve nadie más y el reparto del grupo no se mueve |
| `alumno_id` de alguien **sin matrícula viva** en el grupo de esa asignatura | **422** |
| `alumno_id` de alguien que **no va aparte en ese periodo** | **422** |
| `alumno_id` que no es un id (`0`, negativo) | **422** |

**El periodo es el del token**, como siempre: la unidad nace con
`periodo_id = $user->periodo_id`. La marca tiene que estar puesta **en ese** periodo.

#### Las tres decisiones, con su porqué

**1 · Quién puede mandar `alumno_id`: la guarda que ya había, y no se añade rol.** La
ruta pide `auth.personal` y `User::pueden_editar_notas` —superusuario, o profesor con el
periodo abierto—. **Montar la estructura de un boletín es trabajo docente**, y este mismo
§8 dice que el front reutiliza el editor que ya existe. Quien **decide** que un alumno va
aparte es otra cosa —administradores, secretario y rector, decisión 5— y eso ya lo guarda
`PUT boletin-independiente/periodo`. Aquí sólo se **construye** lo que aquella decisión
permitió. Poner un criterio de rol aquí **duplicaría la decisión 5 en un segundo sitio**,
que es de lo que va medio este plan.

**2 · Un alumno que no va aparte en ese periodo: 422.** Una unidad con dueño para quien
va con el grupo **no le cuenta a nadie**: su dueño lee las del grupo —la marca ausente
significa «va con el grupo», decisión 7— y los demás tampoco la ven, porque tiene dueño.
Nace muerta, en silencio, y con el reparto ya escrito. Es la §9.1 al revés.

> **Y NO prohíbe el estado «tiene unidades propias y no está marcado»**, que es legítimo
> y está decidido: apagar la marca **no borra nada** —*«no debe borrar los datos … pero
> esos datos deben ser ignorados»*— y `PUT boletin-independiente/planilla` (§6.1) existe
> justamente para ver lo que se está ignorando. Lo que se prohíbe es **crear** una fila
> así desde cero. **Un residuo tiene historia; una fila nueva sin dueño efectivo, no.**

**422 y no 403**: no es que quien llama no pueda, es que **lo que pide no tiene sentido
con el estado que hay**.

**3 · El reparto no se corrige: se separa.** La suma de porcentajes sigue viajando sin
corregir, como dice este documento; el backend no valida que sume 100. Lo que el arreglo
garantiza es que **los dos repartos no se mezclen**, que es justo lo que fallaba.

#### Y una cuarta que no estaba en el encargo: el `orden`

Se contaba sobre **todas** las unidades del periodo —las del grupo y las de cualquier
independiente juntas—, así que la primera unidad propia de un alumno nacía con el `orden`
de la quinta del curso y la siguiente del grupo se saltaba un número. Ahora se cuenta
**dentro del reparto en el que entra la unidad**: es la misma frontera que
`u.alumno_id <=> alcance` traza en las lecturas, aquí en la escritura.

#### `subunidades` no hizo falta tocarlo

La §6.5 —cuando la unidad tiene dueño nace **una** nota y no treinta— ya estaba, y la
decisión vive dentro de `Nota::verificarCrearNotas`, que lee `unidades.alumno_id`.
Comprobado antes de escribir nada.

**Y lo que no tiene que construir aunque parezca que sí:** comportamiento. No va
en este módulo (§1).

---

## §9 — Los riesgos, medidos

### 9.1 · El alumno que se cae por el hueco — **es el grave**

La decisión 1 dice que la marca vale para **todas** las asignaturas. Un alumno
de once asignaturas al que su profesor de Educación Física no le crea ninguna
unidad **desaparece de la planilla de esa asignatura y no aparece en ninguna
otra parte**: su definitiva sale 0, el boletín le imprime la asignatura vacía y
**nadie recibe un error**. Es el mismo patrón de la §4 del [10](10-definitivas.md)
—un 0 que significa «no hay nada» y se lee como «sacó cero»—.

**No se arregla con un aviso al final del periodo.** Se arregla con tres cosas,
y las tres van en la fase 4:

- `unidades: []` viaja en la respuesta y **la pantalla lo pinta**, no lo esconde;
- `porcentaje_unidades` distinto de 100 se ve **por alumno**;
- una consulta de coordinación —`tools/independientes-sin-estructura.php`— que
  liste, para un periodo, **qué pares (alumno, asignatura) están marcados y no
  tienen ni una unidad propia**, con su población delante: *«revisadas 11
  asignaturas × 3 alumnos = 33 pares; 4 sin estructura»*.

### 9.2 · Las 74 consultas

Es el trabajo de la fase 1 y su forma de fallar es callada: una consulta sin
alcance **sigue devolviendo filas**, sólo que las de otro. Dos formas de
equivocarse, y las dos ya han pasado en este repo:

- **de más**: la planilla del grupo suma las unidades del independiente y las
  definitivas de los treinta salen infladas;
- **de menos**: el boletín del independiente pide las del grupo, no encuentra
  sus notas y le imprime la asignatura en blanco.

Por eso la fase 0 es un **detector**, y por eso imprime su población. Y por eso
la §4 exige que **la suite entera pase sin regenerar un solo snapshot**.

> ### ~~«cualquier consulta a la que se le olvide el alcance mueve alguno»~~ — **FALSO, corregido el 1 sep 2026**
>
> Esta sección decía que los snapshots de contrato son *«el detector más fiel que
> hay … así que **cualquier consulta a la que se le olvide el alcance mueve
> alguno**»*. **Es el recíproco de la §4, y el recíproco no se sostiene.** Las dos
> frases se parecen tanto que se leen como una sola:
>
> | | |
> |---|---|
> | **§4, cierta** | instantánea movida **⟹** alcance olvidado. Con nadie marcado, añadir el alcance es un no-op: si algo se mueve, cambiaste conducta |
> | **§9.2, falsa** | alcance olvidado **⟹** instantánea movida |
>
> **Con nadie marcado, la consulta que olvidó el alcance se comporta exactamente
> igual que la que lo tiene: no mueve nada y sale verde.** `<=> NULL` y
> `<=> $alumno` seleccionan lo mismo cuando no hay a quién marcar.
>
> Y no hace falta creerlo: **está pagado tres veces en este repo**. La §1.4 del
> reparto —*«la forma correcta y la incorrecta dan el mismo verde»*—; la fase 5,
> cuyos diez rojos contra el código pre-fase-1 salen **sólo porque el test
> construye el caso**; y `PuertaSinUnidadesPorBoletinTest`, donde *con nadie
> marcado los dos escenarios son inalcanzables y la suite entera no podía verlos*.
>
> **Por qué importa más que un matiz:** esta frase es la que autoriza a concluir
> *«suite verde y cero instantáneas movidas ⟹ fase 1 hecha»*, y llama a las
> instantáneas el detector más fiel **justo donde son ciegas**. Las instantáneas
> prueban *«no rompiste lo de hoy»*; **no pueden probar *«te acordaste del
> alcance»***. Lo que prueba eso es el detector, leído fila a fila, y un test que
> **construya** el caso.
>
> **Y los dos instrumentos son ciegos en direcciones OPUESTAS, que es la razón de
> fondo por la que hacen falta los dos:**
>
> | | Puede fallar hacia… | Sirve para |
> |---|---|---|
> | **instantáneas** | sólo hacia el **verde**: con nadie marcado **no pueden ver** un alcance olvidado | *«no rompiste lo de hoy»* |
> | **detector** | sólo hacia el **rojo**: se ha equivocado **seis veces y las seis contando de MÁS** — nunca ha dejado pasar un alcance olvidado, ha metido en la lista consultas ya acotadas | *«no queda ninguno olvidado»* |
>
> **Y los seis arreglos del detector no cambiaron nunca el recuento: cambiaron la
> clasificación, y sólo muerden sobre el código nuevo.** Sobre el `app/` de 23 ago,
> el detector de entonces y el de hoy dan lo mismo —51 pendientes en 23 sitios—;
> sobre el de hoy, **44 en 21** contra **20 en 8**. La causa es que las formas que
> esos arreglos aprendieron a ver —`IS NULL` sin alias, `IN (…)`, el `=` sin alias
> con una sola tabla— **son el vocabulario que introdujo el propio trabajo de la
> fase 1** (§1.6 del reparto): **el detector era ciego al vocabulario que su propio
> proyecto inventó**, y por eso su ceguera no se veía en el código de antes, donde
> esas formas no existían. **Sin esos seis arreglos, `main` informaría hoy 44
> pendientes en 21 sitios: el trabajo cerrado parecería menos de la mitad de
> cerrado.** No descubrieron fallos — **hicieron visible como cerrado lo que ya lo
> estaba.**
>
> O sea que **el número del detector es una cota superior del trabajo que queda**,
> y por eso *«cero pendientes»* es una afirmación fuerte mientras que *«suite
> verde»* no lo es en absoluto.
>
> **Un instrumento que sólo se equivoca hacia el rojo es utilizable en cuanto se
> sabe** —basta leer sus filas—; **uno que sólo se equivoca hacia el verde no es
> utilizable de ninguna manera para esa pregunta**, por muy verde que salga. Ésa
> es la diferencia entre las dos cegueras de esta noche, y por eso la de aquí era
> la peor. *(Formulación del lote G, 1 sep 2026.)*
>
> *(Medido y levantado por `8myvc-e7` el 1 sep 2026 contra `1cb7092`.)*

**Y el número del título ya no es 74: son 92 lecturas de `unidades` y 77 de
`subunidades`, medidas el 1 sep 2026 sobre `1cb7092`. El crecimiento es CÓDIGO
al 100 %: el detector puso CERO** —comprobado corriendo el detector de hoy y **el
de aquella noche** contra tres `app/` distintos; sobre el árbol de entonces los
dos dan lo mismo columna a columna—. **14 de las 18 nuevas son
`BoletinIndependienteController`**, el fichero del propio módulo, **acotado por
construcción**. La medición entera, en
[`noche-2026-08-31/e7.md`](noche-2026-08-31/e7.md).

> **Y de paso apareció que el 74 nunca salió de este detector: la cabecera nació
> con dos lecturas de desfase.** El plan se congeló a las **21:39** del 23 ago
> (`e7632cf`) y `tools/unidades-sin-alcance.py` **no existía todavía** — llegó a
> las **23:01** en `e37eab0`, cuyo propio asunto dice *«las **146** lecturas
> clasificadas»*. **146 = 75 + 71**, que es lo que dan sobre ese árbol el detector
> de aquella noche **y** el de hoy; la cabecera dice **74 + 70 = 144**. Nadie
> reconcilió nunca los dos números **porque viven en sitios distintos**: uno en la
> cabecera de un plan y otro en un mensaje de commit.

> **La ironía va pegada al número porque sin ella el número asusta:** este plan
> avisaba de que *«cada una de esas 74 está o corregida o equivocada»*, y
> **corregirlas añadió 18 más**. Un número que sube aquí **no es una alarma: es el
> módulo llegando.** Leer «92» como superficie heredada manda a alguien a auditar
> lo que se acaba de escribir bien.
>
> **Y cuidado con la palabra:** el título dice *«consultas»* y el detector cuenta
> **lecturas** —un método puede ser cinco, `selloDeVersion` lo es—. En `1cb7092`:
> **169 lecturas = 65 sitios = 95 líneas = 27 ficheros**. Los dos extremos del
> 74 → 92 son la misma columna, así que la comparación vale; la palabra del título
> es la que no describe el número, y de ahí saldrá la próxima cifra mal copiada.
>
> **Y «sitio» aquí es `(fichero, método)`** — un método cuenta **uno** aunque
> tenga cinco lecturas, que es el caso de `selloDeVersion`. Va dicho porque de las
> cinco formas razonables de agrupar **sólo ésa da 65**: quien intente reproducir
> la cifra por `(fichero, línea)` obtendrá **95** y concluirá que el número está
> mal, cuando lo que falta es la definición. **Es el aviso de este mismo párrafo
> aplicado a su propia cifra.**

> Y la advertencia del `CLAUDE.md` aplica entera: **el primer sitio donde mirar
> cuando el número salga raro es el detector**. Un `0 sin alcance` puede
> significar «las 74 están bien» o «no encontré ninguna consulta».

### 9.3 · El alumno que se desmarca a mitad de periodo

Escenario real: se marca, el docente le crea sus unidades y le pone notas, y a
la semana el colegio marca *«este periodo no»*. El alumno vuelve a la planilla
del grupo y **no tiene notas en las subunidades del grupo**. `verificarCrearNotas`
se las crea en la siguiente carga de `/notas` —ya lo hace hoy con cualquier
alumno nuevo—, pero **desde Flutter, que no llama a `/notas` nunca**, esa
ventana puede durar días: es exactamente la §5.1 del [10](10-definitivas.md).

**Por eso `PUT boletin-independiente/periodo` crea las notas que falten dentro de
su propia transacción**, en vez de esperar a que alguien abra una pantalla.

### 9.4 · Copiar un periodo

`PUT periodos/copiar` copia la estructura de un periodo al siguiente y avisa al
recalculador (fase 3 del 10, hecha el 24 ago) — y **copia también las notas si se
lo piden**, según su propio test. **Tiene que copiar también las
unidades con dueño**, y sólo para los alumnos que sigan marcados en el periodo
destino. Si se olvida, el periodo nuevo empieza con los independientes sin nada
y volvemos a la §9.1. Hay test: `CopiarUnidadesTest.php` existe y hay que
ampliarlo, no escribir otro.

### 9.5 · ~~Se lee de una matrícula y se escribe en otra~~ — **CERRADA para esta marca el 31 ago 2026**

> **No se arregló: dejó de existir.** La decisión 7 retiró `matriculas.boletin_independiente`
> (§2.2), y con ella la pregunta «¿cuál es la matrícula del año?» que este riesgo consistía en
> acertar. `bol_ind_periodos` cuelga de `(alumno_id, periodo_id)` **con clave única**: no hay dos
> filas entre las que equivocarse, y leer y escribir no pueden elegir distinto porque no eligen.
>
> **El riesgo sigue vivo para `repitente`, `promovido` y `nro_folio`**, que siguen viviendo en
> `matriculas` y siguen leyéndose y escribiéndose con dos consultas distintas. Lo que cambia es que
> **ya no bloquea la fase 2 de este plan**: era «va en la fase 2 y no después» porque con esta marca
> el fallo se veía en la planilla de otro docente. Ahora es un pendiente de las matrículas, no del
> boletín independiente. El texto original se conserva porque el fallo de las otras tres columnas es
> exactamente éste.

#### El texto original, del 24 ago

Salió comprobando una pregunta del front (`myvc-front-10`) que resultó no ser el
problema. **La ficha y el guardado no eligen la matrícula del año de la misma
manera:**

| | Consulta | `m.deleted_at` | `m.estado` | `ORDER BY` | Se queda con |
|---|---|---|---|---|---|
| **escribe** | `GuardarAlumno::valor:62-67` | **no filtra** | **no filtra** | **ninguno** | `[0]` |
| **lee** | `AlumnosController::putShow:562-573` | filtra | no filtra | `a.apellidos` | `[0]` |

Un alumno con **dos matrículas en el mismo año** —cambió de grupo a mitad de
curso, o una quedó borrada— puede **leerse de una y escribirse en otra**. Hoy eso
ya pasa con `repitente`, `promovido` y `nro_folio`, **y nadie lo ha visto porque
nadie mira esos campos al día siguiente**.

**Con esta marca se ve al día siguiente y en la pantalla de otro**: el alumno
sigue apareciendo entre los normales de la planilla y la ficha jura que está
marcado. Y el front lo afinó todavía más (`myvc-front-10`): **el sitio donde se
ve no es la ficha, es la planilla de OTRO docente**, sin ninguna marca, y **quien
lo note no tiene forma de relacionarlo con un interruptor que alguien tocó en otra
pantalla**. Por eso va en la fase 2 y no después: con la pantalla ya hecha, el
primer informe sería *«el interruptor no guarda»* y se buscaría en el front. Es de la familia de la §28 —`WHERE actual=1` quedándose con el primero
sin `ORDER BY`— y se arregla igual: **una sola regla de cuál es la matrícula del
año, compartida por la lectura y la escritura**, con su test de un alumno con dos
matrículas. Va en la fase 2, antes de que exista la primera pantalla.

### 9.6 · Un escritor nuevo puesto encima de una lectura que aún no ha pasado por la fase 1 — 25 ago

`Unidad::deAsignatura` **no filtra `unidades.alumno_id`**. Su hermana
`deAsignaturaCalculada` sí —lleva el `u.alumno_id <=> :alumno_id` de la fase 1, y
por ahí pasan los tres boletines y los informes—, pero **la que usa
`Nota::alumnoPeriodoDetalle` es la otra**.

Mientras esa lectura fuera **sólo lectura**, la consecuencia era la de siempre: la
pantalla enseña unidades de más. **Desde el 25 ago no es sólo lectura.** El arreglo
del `notas/update/undefined` ([05 §234](05-codigo-muerto-y-roto.md)) le colgó una
siembra: por ahí entran ahora `notas/alumno` y `notas/alumno-periodo-grupo`, y las
dos **crean la fila que falte**. Con el boletín independiente encendido, eso es
**crear notas del alumno pedido en unidades cuyo dueño es otro**.

Es exactamente la familia de
[`SubunidadDeUnaUnidadConDuenoTest`](../../tests/Contrato/SubunidadDeUnaUnidadConDuenoTest.php)
—el alcance se pierde **al traspasarlo**, no al leerlo—, con la diferencia de que
esta vez el escritor lo pusimos nosotros y **encima de una lectura que el censo de
la fase 1 todavía no había tocado**.

**Hoy es inerte**: `unidades.alumno_id` es `NULL` en todas las filas de los quince
colegios, y `<=> NULL` seleccionaría exactamente lo mismo que hay ahora. **Se arma
solo el día que alguien marque al primer alumno**, que es el objeto de este
documento. Va aquí y no en el 05 porque **la fase 1 es quien lo cierra**: cuando
`deAsignatura` reciba el alumno como su hermana, esto desaparece sin tocar el
arreglo de las notas.

> Y la lección, que es de método y no de este caso: **el censo de lecturas de la
> fase 1 clasificó lo que había el día que se corrió.** Un arreglo posterior puede
> convertir una lectura clasificada como inocua en un escritor sin que el censo se
> entere. **Antes de encender la fase 1, el censo se vuelve a correr** — no por si
> se equivocó, sino por lo que se escribió después.

---

## §10 — Despliegue y orden

- **Esto lleva migraciones de esquema, y son las primeras en tocar tablas de
  producción de los quince colegios.** `unidades`, `matriculas` y `years` son
  tablas grandes y vivas: un `ALTER TABLE` sobre `unidades` bloquea la escritura
  de notas mientras dura. Hay que medir el tamaño real de `unidades` en el
  colegio más grande **antes**, con el mismo `for` de una línea de la fase 0 de
  las definitivas.
- **Ningún cambio a mano en phpMyAdmin: migración o no existe.**
- **Orden respecto a las definitivas**: la [fase 2 del 10](10-definitivas.md)
  —los índices únicos de `notas_finales`— **va antes o va después, pero no a la
  vez**. Las dos tocan el mismo camino de escritura y, si algo sale mal en el
  despliegue, con las dos dentro no se sabe cuál fue. La recomendación es
  **definitivas primero**: lleva más tiempo medido y ya está esperando los
  dieciséis números.
- **El front no publica hasta que el backend esté DESPLEGADO**, no fusionado. En
  un colegio sin desplegar, la pantalla nueva es un 404 y el interruptor de la
  ficha un «No guardado» silencioso.
- **`myvc_flutter` es una sola app para los quince.** Hoy la app crea
  subunidades y pone notas: mientras no sepa de esto, a un alumno marcado le
  seguirá enseñando la planilla del grupo. **No se rompe** —las unidades del
  grupo siguen existiendo— pero enseña una planilla incompleta. Hay que
  decidir, cuando esto se despliegue, si la app oculta a los independientes o
  los enseña con un aviso.

---

## §11 — Los tests, y cuáles encuentran algo

La regla de `tests/Contrato/`: comprueban que **la respuesta no ha cambiado**, y
lo que los hace encontrar cosas es **mirar el resultado y no el estado**.

| Test | Qué mira, y por qué ése |
|---|---|
| `BolIndependienteNoMueveNadaTest` | **el de la §4**: con la migración puesta y nadie marcado, las respuestas de planilla, boletines 1/2/3, puestos y definitivas son las de antes. Es el que hace desplegable la fase 1 |
| ✅ `BolIndependienteAlcanceTest::test_marcar_un_periodo_no_toca_el_alcance_de_los_demas` | **el de la decisión 7, escrito el 31 ago 2026 y verde.** Marca el periodo 2 y comprueba que los otros tres siguen yendo con el grupo. **Con nadie marcado, el default bueno y el malo dan el mismo verde**: por eso no había nada que cazara el fallo, y por eso este test construye el caso en vez de mirar que nada se mueva. Se pone rojo devolviendo el `COALESCE(bip.aplica, 0)` al `1` |
| `BolIndependienteDefinitivaTest` | **ida y vuelta**: marcar, crear estructura propia, poner notas, leer la definitiva. Que salga el número que sale de SUS porcentajes, no de los del grupo |
| `BolIndependienteNoBorraTest` | apagar y encender `aplica` **contando filas de `unidades`, `subunidades` y `notas` antes y después**. Un borrado no se ve en la respuesta |
| `BolIndependienteVuelveALaPlanillaTest` | con `aplica=0`, el alumno vuelve a `alumnos` en `notas/detailed`, **con sus notas del grupo creadas** (§9.3) y con el badge |
| `BolIndependienteBoletinTest` | el boletín del independiente trae **sus** subunidades y **ninguna** del grupo. Y el del compañero de al lado, ninguna de las suyas |
| `BolIndependientePuestosTest` | el interruptor en los dos valores, **comprobando que el puesto de un tercero cambia** — que es el efecto que nadie espera (§7) |
| `BolIndependienteCopiarTest` | copiar con y sin notas, un destino que ya tiene estructura (`saltado`) y uno que dejó de estar marcado |
| `SuperficieDeUnTokenTest` | el barrido que ya existe: que un docente no pueda escribirle estructura a un alumno de un grupo que no es suyo |

Y el que no es un test sino una herramienta: **`tools/unidades-sin-alcance.py`,
que se corre en cada fase y siempre imprime su población** — leyendo la columna **«hay que
acotarla»** y no la cifra grande, por lo que dice el aviso de la §5.

> **Todos montan la marca por un solo sitio: `CasoDeContrato::marcarIndependiente($alumno, $periodo)`.**
> Hasta el 31 ago 2026 eran nueve ficheros con su propio
> `UPDATE matriculas SET boletin_independiente = 1`, que era la marca **del año**. Con la marca por
> periodo, un test que siguiera escribiendo eso **no fallaría de forma útil: montaría un escenario
> que ya no existe**, y el verde no significaría nada. El helper tiene el tercer parámetro
> `aplica: false` porque **escribir la fila diciendo que no** y **no escribirla** son estados
> distintos: el primero es «este periodo va con el grupo, y hay datos guardados que no se están
> usando», que es el badge de la §1.

---

## §12 — La coordinación con el front

> **El canal es un fichero del front, no este documento.**
> `~/DESARROLLOS/myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, sección **C**, es
> el buzón: **toda decisión que cambie un cuerpo, un nombre de campo o una ruta se
> escribe ahí**, además de aquí. Lo pidió Joseth el 24 ago después de que este
> plan estuviera un día escrito sin que nadie del front lo viera — **el front no
> lee este repo por su cuenta**.

**Comunicado a `myvc_front` el 24 ago 2026** (sesión `myvc-front-12`, la
coordinadora). Las cuatro sesiones del front se habían cerrado ya, así que **el
plan lo revisa la sesión de mañana** y entra en su traspaso con la ruta de este
documento.

**Sus tres avisos ya están incorporados**, y los tres mejoraron el plan:

1. **La celda del alumno de la planilla acaba de cambiar** — §8, punto 2.
2. **El vacío tiene que decir por qué está vacío**, con tres casos de esa misma
   madrugada detrás: una pantalla que enseñaba el promedio correcto y las notas
   vacías, y tres columnas que **creaban notas** porque su lista no traía
   identificador. De ahí sale el `motivo` de la §6.1, que era el punto más flojo
   del plan: decía «píntalo» sin dar con qué distinguirlo.
3. **Qué pasa el día que el interruptor de puestos se pone a 0** — §7.1, que no
   estaba.

Y su pregunta contestada: **la propiedad nueva de `guardar-valor` falla con 422,
no en silencio** — pero *«No guardado» con 200 sí existe hoy* y no significa que
haya fallado (§6.6).

**Y una segunda vuelta el 24 ago con `myvc-front-10`**, que inventarió las
pantallas y trajo cuatro preguntas. **Contestadas en la sección C de su fichero**,
y las cuatro caben sin ruta nueva:

1. **`year_id` no era un problema**: sale del token si no viene. Pero comprobarlo
   destapó la §9.5, que sí muerde.
2. **La ficha lee la marca de `alumnos/show`**, con la trampa del alumno sin
   matrícula (§6.4).
3. **Puestos son cuatro informes más el papel**: el interruptor viaja en los seis
   (§7).
4. **Los botones de copiar son de la pantalla nueva**, y `copiar-unidades` es otra
   operación (§6.2).

**Lo que el front pidió de vuelta, y esto lo decide Joseth, no una sesión:**

- si el backend **empieza la fase 1 antes** de que ellos revisen el plan, hay que
  decírselo para que el relevo lo escriba así;
- **avisar cuando la tanda pendiente esté desplegada**: tienen cuatro cosas
  esperándola, incluida una que **cambia el cuerpo de una petición** y está
  congelada a propósito. Está en [DESPLIEGUE.md](../DESPLIEGUE.md).


## §13 — La pantalla POR ESTUDIANTE: dos lecturas pedidas, y **cinco correcciones al diseño** (1 sep 2026)

**Encargo del front (`myvc-front-7f`, 1 sep 2026, tarde), con diseño dibujado.** Le da la vuelta al
eje de la §6.1: hoy `boletin-independiente/planilla` es **una asignatura con sus alumnos marcados**;
la pantalla nueva es **un alumno, un periodo, y todas sus asignaturas** con unidades, notas y faltas,
más una lista para llegar a ella desde el menú. Pide **dos lecturas y ninguna escritura**:

| Ruta | Para qué | Estado |
|---|---|---|
| **548** · `PUT boletin-independiente/marcados` · `auth.personal` | la lista del menú: quién lleva boletín aparte en un periodo, con los recuentos por fila | **escrita** |
| **549** · `PUT boletin-independiente/alumno` · `auth.personal` | el detalle: un alumno con sus asignaturas, unidades, definitiva y faltas | **escrita** |

**No se escribieron el día que se pidieron, y eso es parte del procedimiento.** La §6 de este
documento dice *«éstas son las tres y no hay una cuarta»*, y `CLAUDE.md` que una ruta nueva **es una
decisión, no un efecto secundario**. El encargo llegó de otra sesión, y **un encargo de otra sesión
no es esa decisión**: se midió, se contestó, y se le subió a Joseth con las tres opciones —sólo
`marcados`, las dos, o ninguna hasta desplegar—. **Contestó «las dos» el 1 sep 2026** y entonces se
escribieron. De 547 a **549**.

La respuesta técnica entera está en el canal del front
(`myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, §C, 2026-09-01, `8myvc-2d`) y **no se duplica aquí a
propósito**: dos copias de un contrato es de donde salen dos contratos.

**Lo que sí se mide y se queda:** el diseño trae **cinco cosas que no cuadran con el código**, y la
primera es de las que invierten un color.

### 13.1 · `aplica` por asignatura no existe, y en esa pantalla **pinta de gris el caso del §9.1**

El mockup enseña, dentro de un periodo marcado, una asignatura *«Con el grupo»* en gris y sin
acciones. **Ese estado no puede existir**: la marca es `(alumno_id, periodo_id)` —sin
`asignatura_id`— y la **decisión 1** dice que vale para **todas** las asignaturas. Un `aplica` por
asignatura llegaría **constante**, que es el fallo que el propio front cazó el 31 ago en
`bol_independiente_periodo` y en `aplica` dentro de `independientes` (§6.4). **La tercera vez, y la
primera que hace daño:** si el gris no puede venir de `aplica`, la pantalla lo derivará de
`unidades: []` — y entonces **la asignatura sin estructura propia, la que va a sacar definitiva cero,
se pinta gris y tranquila**. `aplica` va en el periodo; lo que varía por asignatura es `motivo`, que
ya existe.

### 13.2 · Los denominadores estaban en la escala equivocada

Misma raíz: el diseño cuenta *«4 de 5 montadas · 1 sin unidades»*. Con la marca por periodo son
**«4 de 13» y 9 sin unidades**. La pantalla existe para gritar el §9.1 y el denominador de 5 **lo
subestima en ocho** — en la dirección en la que no se puede fallar. Medido en `simonbolivar`, año 9:
**13 grupos, de 7 a 15 asignaturas cada uno, media 10,3**.

### 13.3 · «Notas puestas» **tiene** definición honrada, y no es `nota > 0`

`notas.nota` es `int NOT NULL DEFAULT 0` y la fila **nace sembrada** con `subunidades.nota_default`,
así que ni «existe la fila» ni «`nota > 0`» contestan la pregunta. **Medido sobre las 1.166.138
notas vivas de `simonbolivar`** (1 sep 2026):

| | Filas | Qué son |
|---|---|---|
| `updated_by IS NOT NULL` | **1.046.033** | alguien la tecleó — `notas/update` y `notas/lote` lo escriben, la siembra no |
| `updated_by IS NULL`, `nota = 0` | **98.402** | sembrada y sin calificar: **la que falta** |
| `updated_by IS NULL`, `nota > 0` | **21.703** | sembrada con `nota_default` ≠ 0 y nunca tocada |
| `updated_by IS NOT NULL`, `nota = 0` | **3.939** | **un cero tecleado queriendo** |

**`nota > 0` etiqueta mal 25.642 filas en un solo colegio, y en las dos direcciones** — las últimas
3.939 son el §4 del [10](10-definitivas.md) del revés: *un cero real leído como «no hay nada»*. El
criterio que se usa es `updated_by`, y **es un proxy**: se sostiene en que la siembra no lo escribe.

### 13.4 · `excusa` / `con_excusa` no tiene ningún dato detrás

`ausencias` **no tiene columna de excusa**. Contados sus `tipo` vivos: **`ausencia` 44.393 y
`tardanza` 2.077, y nada más**. El `excusado` del esquema es de **`uniformes`**, otra tabla. Darlo
sería columna nueva + migración + quién la marca, y encima sobre `ausencias/*`, **contrato compartido
con `myvc_flutter`**. Recomendado al front: **fuera del encargo**, y su propio lote si el colegio lo
quiere — un `excusa: false` constante sería la cuarta constante del módulo en dos semanas.

### 13.5 · El precedente del alcance **no filtra por grupo**, y hay que decirlo antes de copiarlo

El front citó `PiarsAsignaturasController::getAsignaturas` como precedente de `alcance: mias|todas`.
**Existe, y para este uso está roto**: la rama del docente llama a
`Profesor::asignaturas($year_id, $persona_id)`, que filtra por `profesor_id` y `year_id` y **nunca
por el grupo** — el `$grupo_id` del argumento **no se usa en esa rama**. Un docente de cinco grupos
recibiría, dentro del boletín de un alumno de 8-B, sus materias de los cinco. **`mias` es la
intersección** con el grupo del alumno. *(Y esa misma rama deja `$asignaturas` sin definir para
`Alumno` y `Acudiente`, con `persona.propia` en la ruta: es del [05](05-codigo-muerto-y-roto.md), no
de aquí.)*

### 13.6 · Y «el grupo del alumno» hay que desempatarlo

`matriculas` no tiene clave única sobre `(alumno, grupo)` —de ahí venía el `LIMIT 1` que la decisión
7 pudo quitar— así que dentro de un año puede haber empate. El periodo fija el año; la regla de
desempate **se escribe** el día que se escriba la ruta, y la respuesta dice qué grupo eligió.

### Las tres que el front traía medidas: **las tres ciertas**

`piars-asignaturas/asignaturas` **escribe** (`getCreatePiarAsignatura` dentro del `for`);
`ausencias/detailed` trae el grupo entero con un `Alumno::userData` por cabeza y filtra por
**`$user->periodo_id`**, el del token; y el `periodo_id` **va en el cuerpo**, que es la §6.3 que ellos
mismos corrigieron.


### 13.7 · Lo que se decidió al escribirlas, y no estaba en el encargo

Cuatro cosas que el contrato no fijaba y que alguien tenía que fijar. Van aquí porque **son las que
un relevo no puede deducir leyendo la respuesta**.

- **El desempate del grupo del alumno**, que era el punto 6 de la lista de arriba: dentro del año del
  periodo, **la matrícula viva de `id` mayor** de entre `MATR`/`ASIS`/`PREM`. Es el mismo criterio con
  el que `definitivaDe()` desempata `notas_finales`, y por el mismo motivo: la tabla no tiene clave
  única, así que se elige la última escrita en vez de reventar. **`alumno.grupo_id` viaja siempre**,
  de modo que el empate se ve en la respuesta en vez de quedar en «la pantalla salió rara».
- **`sin_matricula`**, en `marcados`. Un marcado sin matrícula viva en el año del periodo no tiene
  grupo, luego no tiene asignaturas, luego su fila no diría nada — y se cae del `INNER JOIN`. Que se
  caiga está bien; que se caiga **sin decirlo** es la forma de fallo de este repo, así que viaja el
  recuento de los que se cayeron. Cero es lo normal.
- **`asignaturas_del_alumno` también en la LISTA**, no sólo en el detalle. El front lo pidió para el
  detalle con su propio argumento —*«sin el total, un docente con una sola materia cree que el
  estudiante sólo tiene una»*— y ese argumento **vale igual en la fila de la lista**, donde el docente
  ve «2 de 3» sin saber que hay trece.
- **`exigirPeriodoDelAnio()` ahora DEVUELVE el periodo** en vez de `void`. Las dos lecturas necesitan
  el `numero` justo detrás de la guarda; con `void`, cada una tendría que volver a buscarlo y escribir
  un `if ($periodo === null)` **que nadie puede ejecutar**, porque la guarda ya abortó. Este repo ya
  sabe cómo acaban las ramas inalcanzables.

### 13.8 · El test que pasaba con el endpoint escrito mal, y cómo se vio

`test_notas_puestas_cuenta_por_updated_by_y_no_por_el_valor` nació con **una** nota sembrada con
valor y **un** cero tecleado. Con ese montaje los dos criterios —`updated_by` y `nota > 0`— dan
**1**: los dos errores contrarios **se cancelan**, y el test pasaba igual con el endpoint contando al
revés. Es la trampa de la cabecera de `CLAUDE.md` en su forma exacta —*un detector puede contar bien
un síntoma y no estar contando la causa*— y no se ve corriendo el test, porque sale verde.

Se arregló haciendo el montaje **asimétrico**: dos sembradas con valor y un solo cero tecleado, así
`updated_by` cuenta 1 y `nota > 0` contaría 2. **Y se comprobó rompiendo el endpoint a propósito**
—cambiando el criterio a `nota > 0` y viendo el rojo— antes de darlo por bueno. Lo mismo con el
denominador de la §13.2. *Un test que no se ha visto fallar no se sabe si prueba algo.*
