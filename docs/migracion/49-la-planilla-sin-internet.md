# La planilla sin internet — fase 1: la descarga

*21 sep 2026. Encargo de Joseth: «trabajo sin internet a través de Excel, sólo para que los
docentes pasen notas sin el sistema». El plan aprobado —doce pantallas dibujadas, doce
decisiones y siete comprobaciones contra el docker— vive en
`~/DESARROLLOS/myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y **manda sobre este documento**. Aquí
va lo que se construyó, lo que se midió, y los **tres sitios donde el plan resultó estar
equivocado**.*

La frase que decide el orden: **«que la app también permita descargarlo aunque no permita
importarlo»**. La descarga vale sola. Esto es esa fase, y se despliega sin nada de lo demás:
un docente ya puede bajarse su libro, pasar las notas en el bus y teclearlas cuando llegue.

---

## 1 · Lo que hay

| Pieza | Qué es |
|---|---|
| `GET planilla-offline/periodos` | Qué puede bajar y de qué periodos, con `sin_pasar` por asignatura |
| `GET planilla-offline/libro/{periodo_id}` | El `.xlsx` completo (`?asignaturas=`, `?profesor_id=`) |
| `GET planilla-offline/planilla/{asignatura_id}/{periodo_id}` | El mismo libro con **una** hoja |
| `App\Services\LaPlanillaQueSeDescarga` | La consulta de **sólo lectura** (§3.7 del plan) |
| `App\Exports\LibroDeNotas` | Portada + hojas + `_myvc`, PhpSpreadsheet directo |
| `App\Exports\HojaDeAsignatura` | La rejilla de una asignatura, con la D11 y la D12 |
| `App\Support\FirmaDelLibro` | HMAC-SHA256 con `APP_KEY` |
| `App\Support\Autoriza::puedeDescargarLaPlanillaDeOtro` | El camino nuevo de la §3.4 |
| `descargas_de_planilla` | Quién bajó qué, cuándo, con qué huella |

**Router de 655 a 658.** Tres rutas, contadas con `route:list --json` en el árbol principal
el 21 sep 2026. Se movieron `rutas.json`, `guards-por-ruta.json` y `guard-por-familia.json`;
**`familias-que-nunca-entran-en-el-candado.json` no se mueve**, porque la familia nace con
tres hermanas y las tres con guard (`planilla-offline: 3 de 3`).

### Lo que NO se tocó

`notas/detailed`, `notas/lote`, `notas/update`, `ausencias/*` y `notas/nivelar/*`. Es la D8
y es el mismo caso de las nivelaciones: cuatro clientes cuelgan de ahí, uno de ellos
versiones viejas de `myvc_flutter` que conviven meses.

**Y la descarga no escribe una sola nota.** `putDetailed` hace tres escrituras —siembra con
`Nota::verificarCrearNotas`, reordena unidades y recalcula definitivas—, así que **no se
reutiliza**: un docente bajándose los cuatro periodos del año (D1) sembraría cuatro periodos
de filas en un colegio de producción. Lo comprueba
`PlanillaOfflineTest::la_descarga_no_escribe_ni_una_nota_ni_una_definitiva`, que cuenta filas
y `MAX(updated_at)` de `notas`, `notas_finales`, `unidades` y `subunidades` **y el `orden` de
las unidades**, antes y después. Lo único que se escribe es la fila de auditoría.

---

## 2 · Los tres sitios donde el plan está equivocado

### 2.1 · El color del colegio no existe (§4.5)

La §4.5 dice que el color de las bandas sale *«de la misma configuración que usa el
boletín»*. **Esa configuración no está en este backend.** Medido el 21 sep 2026:

```
grep -in 'color' database/schema/mysql-schema.sql   ->  0 líneas, en 90 tablas
grep -rn 'color_\|colorPrimario' app/               ->  0
```

Lo que `app2` llama el color es `--myvc-acento`, y lo escribe `core/tema/tema-usuario.ts`
con **la paleta que elige cada usuario**, guardada en su `localStorage` — lo dice la
cabecera de ese fichero, línea 8: *«Hoy se guarda en `localStorage`»*. O sea que no es un
dato del colegio: **es un dato del navegador de una persona**, y el servidor no lo tiene.

Así que el libro sale con `LibroDeNotas::COLOR_DE_MARCA`, el azul por defecto de MyVc. **No
se pide por parámetro a propósito**: un color que mandara el front sería el de quien pulsa
el botón, y Flutter mandaría otro distinto para el mismo libro. Queda abierto (§5).

### 2.2 · «Número entero» y «escriba un guion» no caben en la misma validación (§3.1 contra D9)

Las dos reglas del libro chocan y el plan no lo dice:

- **§3.1**: `notas.nota` es `int`, así que un `4,5` tecleado se guardaría como `4` **sin
  error y sin aviso**. La celda tiene que validar entero.
- **D9**: una casilla vacía no borra; para borrar **se escribe un guion `-`**, y la regla va
  impresa en la portada.

Una validación de tipo `whole` con alerta de parada —que es la que pide el §4.4— **rechaza
el guion**: la D9 quedaría escrita en la portada y no se podría usar. Y bajar la alerta a
«aviso» deja pasar el `4,5` con un clic, que es justo lo que la §3.1 viene a cerrar.

Se resuelve con una validación `custom`, que acepta las dos cosas y nada más, y así la
alerta puede seguir siendo de **parada**:

```
IF(D3="-", TRUE, AND(ISNUMBER(D3), D3=INT(D3), D3>=0, D3<=50))
```

**`IF` y no `OR`, y es la diferencia entre que funcione y que no.** `OR` evalúa las dos
ramas, y `INT("-")` da `#¡VALOR!`, que contagia al `OR` entero: la casilla con guion daría
error de validación igual. `IF` sólo evalúa la rama que toca.

Por lo mismo, la `Def` orientativa usa **`SUMPRODUCT`** y no una suma de productos: `D3*0,2`
con un guion dentro da `#¡VALOR!` y **contagia el error a toda la fila**, mientras que
`SUMPRODUCT` trata lo que no es número como cero.

### 2.3 · Un libro de quince asignaturas ya está cronometrado (§9.8)

El §9.8 lo daba por medir y avisaba de que iba a hacer falta un corte por tiempo como el del
importador de alumnos (20 s). **No hace falta en la fase 1.** Medido el 21 sep 2026 contra
`caz_zaragoza`, periodo 37 (2025-P1), en el contenedor compartido:

| docente | hojas | filas de alumno | casillas | consultas | construir | escribir | **total** | fichero |
|---|---|---|---|---|---|---|---|---|
| 3 | **26** | 205 | 947 | 0,07 s | 1,06 s | 0,45 s | **1,60 s** | 183 KB |
| 65 | 22 | 331 | 1.587 | 0,06 s | 0,97 s | 0,53 s | **1,59 s** | 176 KB |
| 59 | 20 | 170 | 747 | 0,04 s | 0,92 s | 0,44 s | **1,41 s** | 155 KB |
| 62 | 17 | 297 | 1.223 | 0,04 s | 0,74 s | 0,41 s | **1,21 s** | 137 KB |

Memoria de pico **56 MB** en los cuatro. Lo que domina es armar el objeto en memoria, no la
base: **las consultas son el 4 % del tiempo**. De los segundos vale la razón y no el valor
—la máquina estaba compartida—, pero el orden de magnitud deja el corte por tiempo fuera de
esta fase. **El ensayo de la fase 2 es otra medición y sigue sin hacerse.**

---

## 3 · Las decisiones que hubo que tomar, y no estaban escritas

| | Decisión | Por qué |
|---|---|---|
| a | `periodos` lista **todas** las asignaturas del docente, también las que tienen `indicadores: 0` | Ver §3.bis: el generador nunca filtró, así que filtrar aquí hacía que el botón mintiera. Y por la D12 una asignatura sin indicadores es justo el caso en que las columnas de reserva sirven |
| b | `sin_pasar` se cruza contra **las matrículas vivas** | Para que la nota de un alumno retirado después de calificarle no descuente una casilla que sigue vacía para los que están |
| c | La reserva de la D12 es un **suelo, no un techo** | Una unidad con siete indicadores ocupa siete columnas. Recortarla a cinco dejaría dos indicadores existentes fuera del libro |
| d | El `ID` del alumno entra **como texto** | Hay colegios con `no_matricula` de ceros a la izquierda, y Excel se come los ceros del que parece número. Un `01055` convertido en `1055` es una fila que al subirla no casa con nadie |
| e | `Aus` y `Tar` salen **rellenas y escribibles, pero no se leen** | Importarlas es la D5 y es de la **fase 4** (§3.6: subir un conteo crea filas fechadas el día de la importación y bajarlo borra historia). Quitar las columnas escondería que existen; su comentario lo dice |
| f | `Def` queda **bloqueada** | Es la reconstrucción por el otro camino (`RepartoDeLaNota`), orientativa, y no se importa nunca. Abierta invitaría a corregirla a mano creyendo que eso cambia algo |
| g | La ruta de una sola hoja produce **el mismo libro con `n = 1`** | Portada, `_myvc`, firma y protección idénticas: la fase 2 tiene **un** lector y no dos |
| h | Pedir una asignatura ajena con `?asignaturas=` es **403**, no «se ignora» | Descartarla en silencio devolvería un libro con menos hojas de las pedidas y nadie sabría por qué |
| i | Una asignatura **sin docente asignado** sólo la baja quien tiene el permiso de la D4 | No hay dueño a quien preguntar; dejarla abierta a cualquier docente regalaría la planilla de un grupo por una fila mal rellenada |

### 3.bis · Las dos rutas no contaban lo mismo, y el botón mentía

**Encontrado conduciendo las tres rutas contra el docker**, no leyendo el código, y por eso
está escrito aquí: con el token de `administrador` y `?profesor_id=3` sobre `caz_zaragoza`,

    GET planilla-offline/periodos?profesor_id=3   ->  periodo 39: 21 asignaturas
    GET planilla-offline/libro/39?profesor_id=3   ->  .xlsx con 26 hojas de asignatura

El docente 3 tiene **26** asignaturas vivas en el año 10 y sólo **21** tienen unidades con
`periodo_id = 39 AND alumno_id IS NULL AND deleted_at IS NULL`. `/periodos` filtraba por
«tiene indicadores» y **el generador del libro no**.

No es una diferencia de números: la pantalla pinta *«Descargar el libro (21 hojas)»* y bajan
26, y **la tabla de la portada del propio libro no cuadra con sus pestañas**.

**Manda el libro**, y el motivo es la D12: una asignatura sin indicadores es exactamente el
caso para el que existen las columnas de reserva —el docente se lleva la hoja, propone los
indicadores y los trae de vuelta—. Esconderla de la lista y metérsela en el archivo era lo
peor de las dos opciones: ni la ve para elegirla ni se libra de ella. Ahora `/periodos`
lista las 26, con `indicadores: 0` y `sin_pasar: 0` en las cinco que no tienen nada.

Lo ata `PlanillaOfflineTest::las_hojas_del_libro_son_las_asignaturas_que_lista_periodos`, que
compara **los identificadores y no sólo la cuenta** —un número igual por casualidad con
asignaturas distintas sería el mismo fallo con otra cara— y que **fabrica el caso**: le
quita las unidades a una asignatura del docente y comprueba que sigue en la lista y sigue
trayendo hoja. Antes no había nada que sujetara ese pareado.

### El camino de autorización de la §3.4, escrito

`Autoriza::puedeDescargarLaPlanillaDeOtro` = `esAdministrativo` **o**
`puedeEditarPlantillaNotas`. O sea superusuario, `Secretario`, o quien tenga
`can_edit_plantilla_notas` —que es el permiso de coordinación académica (D13/D28 del doc 28)—.

**No pasa por ser `Profesor`**, que es lo que hace `User::pueden_editar_notas` y por lo que
no servía: ahí entran los 53 docentes por el tipo, o sea que cualquiera podría bajarse el
libro de un compañero, y en cambio **un coordinador sin `is_superuser` recibe 403 tenga el
rol que tenga**.

La puerta es **más ancha que la de escribir y puede serlo**: las tres rutas son de lectura y
lo que sale por ellas es la planilla que esa persona ya ve por la web. La escritura de la
fase 2 vuelve a autorizar cada nota contra «¿es esta asignatura de este docente?» y contra
el periodo.

---

## 4 · Dos trampas que costaron tiempo y se repetirán

### 4.1 · Los interruptores de `sheetProtection` dicen lo contrario de lo que parecen

En OOXML cada atributo de `sheetProtection` es una **prohibición**, no un permiso: `true` es
«esto queda bloqueado cuando la hoja está protegida». PhpSpreadsheet lo copia tal cual
—*«Sorting is locked when sheet is protected, default true»*— así que un `setSort(false)`
bien intencionado **permite ordenar**.

Escrito al revés, la hoja salía con `sort="0" insertRows="0"` y **nada lo delataba**: el
libro se abre igual, la protección se ve puesta, y sólo se nota el día que alguien ordena la
planilla por nota y el mapa fila↔alumno de `_myvc` deja de valer. Se cazó mirando el XML del
`.xlsx` generado:

```bash
unzip -p libro.xlsx xl/worksheets/sheet2.xml | grep -o '<sheetProtection[^>]*>'
```

*El píxel en vez del 200, otra vez.*

### 4.2 · El color de un formato condicional viaja en `bgColor`, no en `fgColor`

Al releer el libro, `getConditionalStyles(...)->getStyle()->getFill()->getStartColor()` sale
**vacío** y el color está en `getEndColor()`. No es un fallo: en una fuente diferencial
(`dxf`) el relleno se escribe como `<patternFill><bgColor rgb="…"/></patternFill>`. Un test
que comprobara `getStartColor()` diría que el formato condicional no tiene color **con el
libro perfectamente pintado**.

---

## 5 · Lo que queda abierto

1. **El color del colegio** (§2.1). Hoy azul fijo. Si se quiere de verdad por colegio hace
   falta una columna en `years` — y antes de escribirla, `tools/lo-que-reparte-una-columna.py`,
   porque `years` va dentro de boletines, informes y el contexto de usuario.
2. **El logo de la portada.** La §4.1 lo dibuja y **no está**: `years.logo_id` apunta a
   `images`, cuyo fichero es **propio de cada colegio** (`storage/` no se comparte) y puede
   no existir. Una descarga que abortara por un logo que falta es peor que una portada sin
   logo. Se hace el día que se decida qué pasa cuando no está.
3. **Los colegios en `modelo_evaluacion = competencias` no se han mirado.** Es el §9.3 del
   plan y sigue abierto: su planilla no es una rejilla de números. **Hay que decir cuáles
   son antes de anunciar la función**, no descubrirlo cuando uno la use. El libro de hoy
   sale igual para ellos, y eso es exactamente lo que hay que comprobar antes de desplegar.
4. **`alias_materia` sale como `string` en la instantánea, no como `null|string`.** El seed
   no tiene ninguna asignatura con alias vacío, así que `formaUnida` no puede ver el `null`.
   El contrato que el front está construyendo **sí lo admite**, y es el código el que
   manda: el día que un colegio deje un alias vacío, la instantánea se moverá y no será una
   regresión.
5. **El periodo vacío del docker es el 4, no el que está en curso.** *Aquí este documento
   decía lo contrario, y era falso: «el periodo en curso está vacío, el 39 tiene 0
   subunidades». Lo corrigió quien condujo las rutas.* Remedido el 21 sep 2026 sobre
   `caz_zaragoza`, año 10, contando **unidades y subunidades vivas**
   (`u.deleted_at IS NULL AND s.deleted_at IS NULL`):

   | periodo | numero | actual | abierto | unidades | subunidades |
   |---|---|---|---|---|---|
   | 37 | 1 | no | no | 548 | 831 |
   | 38 | 2 | no | no | 557 | 773 |
   | **39** | **3** | **sí** | **sí** | **470** | **629** |
   | 40 | 4 | no | no | **0** | **0** |

   O sea que **el periodo en curso tiene datos de sobra** y el vacío es el 4, que no es el
   actual. Lo que sí hay que saber al conducir la pantalla es eso: elegir el periodo 4
   devuelve una lista sin indicadores, y no es una avería.

   *Las dos cifras de subunidades que llegaron con la corrección —672, 850 y 906— no las
   reproduzco: con este predicado me salen 629, 773 y 831, y sin filtrar borrados 704, 883
   y 970. La diferencia no cambia el hecho —el 39 es el actual y no está vacío—, pero el
   número que vale es el que va con su consulta al lado.*
6. ~~**El ensayo y la importación (fases 2 a 5) no existen.** El libro ya lleva todo lo que
   necesitan —el mapa, el espejo y la firma—, y
   `PlanillaOfflineTest::la_firma_se_puede_recalcular_desde_lo_que_el_libro_lleva_escrito`
   comprueba que se pueden reconstruir **leyendo sólo las celdas**, que es lo único que la
   fase 2 va a tener.~~ **HECHO, las cuatro, y cada una con su documento**:
   [50](50-el-ensayo-y-la-escritura-de-la-planilla.md) (fases 2 y 3),
   [51](51-las-ausencias-de-la-planilla.md) (fase 4) y
   [52](52-el-acta-y-subir-por-otro.md) (fase 5). La corazonada se cumplió: las tres piezas que este
   documento dejó dentro del libro son exactamente de las que vive el lector.
8. **`sin_pasar` arranca en 0 en todo el seed**: las 1.184 notas del docente de prueba están
   todas puestas. Por eso el test no compara un número grande contra otro, sino **el salto**:
   vacía una nota y comprueba que la cuenta sube en uno. Contra datos de verdad no se ha
   mirado.
7. **La D12 sigue chocando con la numeración de la pantalla** (§9.6 del plan). Contando las
   reservadas, el **Indicador 6** del Excel es el **Indicador 4** de la web. Se implementó la
   salida (b) —numerar seguido— porque es lo que se pidió y lo que dibuja el mock. **El
   front no reserva**, así que hoy los dos números conviven.

---

## 5.bis · Al desplegar: la migración va ANTES, y el síntoma es un 500 en la descarga

`descargas_de_planilla` no es opcional: `planilla-offline/libro/*` escribe su fila de
auditoría **en cada descarga**, así que **sin la migración corrida esta familia contesta 500**.

Y contesta 500 **sólo en las dos rutas que bajan fichero**: `planilla-offline/periodos`
sigue respondiendo 200 perfectamente, porque no escribe nada. Eso es lo que hace el
diagnóstico caro — la pantalla carga, el selector de periodo se llena, y lo único que falla
es el botón. Pasó el 21 sep 2026 en el docker, conduciendo las rutas contra `caz_zaragoza`
antes de migrar.

Así que al desplegar colegio a colegio, `php artisan migrate` **antes** de anunciar la
función, no después. Tarda ~256 ms por colegio y la tabla nace vacía.

## 6 · Lo que se corrió

Con `DB_TEST_DATABASE=simonbolivar_testing_off` (base de esta sesión, construida el 21 sep
2026 con `tools/construir-bd-test.sh`, 47/47 migraciones).

```bash
docker exec -e DB_TEST_DATABASE=simonbolivar_testing_off 8myvc-app-1 \
    php artisan test --filter='PlanillaOfflineTest|FirmaDelLibroTest'
# Tests: 29 passed (202 assertions)
```

`tools/tests-que-tocan.py` dice **NO HAY SUBCONJUNTO SEGURO** —por `composer.json`,
`routes/api/academico.php` y la migración—, así que la suite entera es **decisión de
Joseth**. Lo que se corrió son las clases del dominio tocado, en una tanda:

```bash
--filter='PlanillaOfflineTest|FirmaDelLibroTest|NotasTest|ExcelTest|AutorizacionTest|RutasTest|
          RutasPreLoginTest|AutenticacionTest|FamiliasQueNuncaEntranTest|GuardsDestructivosTest|
          PuertasDeLaMismaOperacionTest|ConcederSuperusuarioTest|PermisoDeAuditoriaTest|
          AlcanceDeLaPlantillaTest|CandadoDeLaPlantillaTest|AliasDeFacadesTest'
# Tests: 13 skipped, 194 passed (1111 assertions) — 65,52 s, 0 rojas
```

Los **13 saltados son los del candado de la plantilla**, que otra sesión tiene suspendido a
mano (`CandadoDeLaPlantilla::SUSPENDIDO`): no los salta esta entrega.

- `composer run pint:test` → **PASS, 523 ficheros**. Los tres nuevos de fuera de la lista
  curada (`app/Exports/LibroDeNotas.php`, `app/Exports/HojaDeAsignatura.php`,
  `app/Http/Controllers/PlanillaOfflineController.php`) **se añadieron a la lista** de
  `composer.json`: la carpeta entera no, porque los seis exports viejos no están formateados
  y meterlos sería un diff ilegible dentro de una entrega que no los toca.
- `composer run stan` → **8 errores, ninguno de esta entrega**: 7 en
  `app/Support/CandadoDeLaPlantilla.php` y su test —trabajo sin commitear de otra sesión— y
  1 en `tests/Feature/ImportacionesAbandonadasTest.php`, que viene del commit `b1978b8`.
