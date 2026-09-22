# La planilla sin internet — fase 4: las ausencias y las tardanzas

*21 sep 2026. Continúa [49](49-la-planilla-sin-internet.md) —la descarga— y
[50](50-el-ensayo-y-la-escritura-de-la-planilla.md) —el ensayo, la escritura y «¿es este?»—. El
plan aprobado vive en `~/DESARROLLOS/myvc_front/PLAN-NOTAS-SIN-INTERNET.md` y **manda sobre este
documento**. Aquí va lo que se construyó, lo que se midió, y **los cuatro sitios donde el plan o el
contrato resultaron estar equivocados**, cada uno con su medida al lado.*

La frase que gobierna la fase entera, y de la que sale todo lo demás:

> **Subir es añadir y baja el listón; bajar es borrar historia y no ocurre sin que alguien lo pida.**

---

## 1 · Lo que hay

| Pieza | Qué cambió |
|---|---|
| `App\Exports\HojaDeAsignatura::mapa()` | El mapa de `_myvc` lleva ahora `asistencia`: **el espejo de los dos conteos** |
| `App\Support\FirmaDelLibro` | `FORMATO` 1 → **2**, y `FORMATOS_QUE_SE_LEEN = [1, 2]`: un libro se comprueba **con su propio número** |
| `App\Services\LaPlanillaQueSeSube` | Comprueba la firma con el formato que el libro declara; un formato desconocido es **peldaño 5**, no firma rota |
| `App\Services\EnsayoDeLaPlanilla` | `estudiarLasAusencias` + `decidirElConteo`: la F8 pasó de **aviso** a **decisión** |
| `App\Services\RespuestasDeLaPlanilla` | `ausencias` salió de `NO_APLICADAS`, con `queHacerConLasAusencias()` y **el único defecto asimétrico** del asistente |
| `App\Services\EscrituraDeNotasImportadas` | Crea y borra faltas **dentro de la transacción de la fila del alumno** |
| `App\Services\LaPlanillaQueSeDescarga` | Sólo docblock: su `asistenciaDe()` es ahora **la única fuente** de los dos números, que viajan a la celda **y** al espejo |

**No hay migración nueva y no hay ruta nueva.** El router no se mueve, así que
`rutas.json`, `guards-por-ruta.json` y `guard-por-familia.json` se quedan como estaban
(`planilla-offline: 5 de 5`).

### Lo que NO se tocó

**`ausencias/*` no se toca**, que es la D8 y sigue valiendo entera: esas seis rutas las comparte
`myvc_flutter`, que es **una sola app para los dieciséis colegios** y cuyas versiones viejas
conviven meses. Lo que se le añade a una ruta que usa la móvil viaja a una app que no se puede
publicar el mismo día.

Lo que sí se copió, línea por línea, es **su regla de negocio**: el tipo, `cantidad_ausencia = 1`,
`entrada = 0`, `created_by`, `deleted_by` antes del borrado suave, y la fila de auditoría con el
nombre del alumno congelado dentro. Lo sujeta `AusenciasTest`, que es la instantánea de contrato de
esa familia y **sigue verde sin haberla tocado**.

---

## 2 · El contrato, palabra por palabra

`familias.ausencias[]` dejó de ser `{hoja, descripcion}`:

```
{ id,                      llave del renglón — sha1(hoja|alumno|tipo)
  hoja, asignatura, alumno, alumno_id,
  tipo: 'ausencias' | 'tardanzas',
  espejo: number|null,     lo que había AL DESCARGAR (null si no hay espejo)
  base: number,            lo que hay AHORA en el sistema
  archivo: number,         lo que dice el libro
  direccion: 'sube' | 'baja',
  cuantas: number,         filas que se crearían o se borrarían
  choque: bool,            cambió en los dos sitios desde la descarga
  si_no_hago_nada: string,
  por_defecto,             (de más) el defecto de esa dirección
  decision }               (de más) lo que de verdad va a pasar con lo que llegó
```

Y en `respuestas`:

```
ausencias?: [ { hoja, tipo: 'ausencias'|'tardanzas', direccion: 'sube'|'baja',
                decision: 'aplicar' | 'dejar' } ]
```

**Tres cosas que no son adorno:**

- **`tipo` va en plural en el contrato y en singular en la base.** `ausencias.tipo` es
  `ausencia`/`tardanza`; las dos formas se cruzan en `estudiarLasAusencias` y en ningún otro sitio.
- **La llave de la decisión es la tripleta `hoja` + `tipo` + `direccion`.** La dirección es parte de
  la llave y no una etiqueta: ver la §3.2.
- **`por_defecto` y `decision` son campos de más**, que el front no lee. Están para que la
  diferencia entre lo que la pantalla pinta y lo que el servidor haría se pueda ver **en la propia
  respuesta**, y porque de `decision` salen los avisos de después de escribir.

### El defecto asimétrico, que es el punto de la fase

| Dirección | Defecto | Por qué |
|---|---|---|
| `sube` | **`aplicar`** | Añadir faltas no borra nada. Lo peor que puede pasar es que queden fechadas el día de la importación, y eso se ve, se explica y se corrige falta a falta |
| `baja` | **`dejar`** | Bajar **borra filas con sus fechas**, y esas fechas son las que leen las planillas de ausencias de los acudientes |

Es la única asimetría del asistente, y no contradice el principio de las otras siete familias —«el
defecto es el que no pierde trabajo»— sino que lo aplica: **lo que no se puede deshacer volviendo a
subir el archivo es el borrado**, y ése es justo el que necesita que alguien lo pida.

### Si la sección llega, manda ella

El renglón **no tiene campo `decision`** en el contrato que el front construyó, así que el defecto
lo pinta la pantalla y **manda la sección entera con las dos decisiones escritas** en cuanto hay un
renglón, igual que hace con `choques`. El servidor lo lee de una manera: **lo que llega se obedece
tal cual**, y sus propios defectos son la red para el cliente que no manda la sección —uno viejo, un
colegio sin desplegar, unas instrucciones escritas a mano—.

Los dos números son los mismos **a propósito**. Lo que no puede pasar es que los dos lados tengan
defectos propios y un día dejen de coincidir en silencio.

### La D3 vale igual aquí

Las tres puntas son las mismas y la regla 1 también: **si el archivo trae lo mismo que el espejo, no
se toca nada aunque la base haya cambiado**. Es el caso que hay que proteger —el docente baja el
libro, no toca la columna `Aus`, y mientras tanto secretaría anota dos faltas en la web—: sin el
espejo, el archivo diría «2» contra una base de «4» y el importador borraría las dos. Lo sujeta
`f8_d3_lo_que_el_docente_no_toco_no_se_toca_aunque_la_base_haya_cambiado`, **visto en rojo** quitando
esa comparación.

---

## 3 · Los cuatro sitios donde el plan o el contrato estaban equivocados

### 3.1 · El espejo de la asistencia no existía, y añadirlo mueve la firma de todos los libros de fuera

El plan da la D3 por aplicable a estas dos columnas y `_myvc` **sólo guardaba el espejo de las
notas**. Sin él, «el docente subió las faltas de 2 a 4» no se distingue de «el docente no tocó la
columna y alguien anotó dos faltas en la web».

Se añadió `asistencia` al mapa de cada hoja, **y eso va dentro de lo firmado**. O sea que
`FirmaDelLibro::FORMATO` pasa de 1 a 2 y **todos los libros que ya andan por fuera cambian de
firma**. El riesgo era comérselos: dejando la comprobación como estaba, el día del despliegue cada
libro descargado antes habría caído al **peldaño 2** —«alguien modificó este archivo»— sin que nadie
lo hubiera tocado, que es exactamente la alarma que salta sola contra la que avisa el propio
docblock de esa clase.

La salida es que **un libro se comprueba con el número que él mismo declara**
(`FORMATOS_QUE_SE_LEEN`), y entonces:

- Un libro de **formato 1** valida su firma, entra por el peldaño 1 y **su familia de asistencia se
  comporta como si no hubiera espejo**: se compara archivo contra base, no hay choques que declarar
  y los defectos siguen siendo los de la dirección.
- Un libro de un **formato que este servidor no conoce** —uno de un despliegue más nuevo— es
  **peldaño 5 con su salida escrita**, y no firma rota: mandar a buscar un manipulador que no existe
  es peor que no decir nada.

Lo sujetan `f8_un_libro_de_la_version_de_formato_anterior_se_sigue_leyendo` —**visto en rojo**
quitando el formato de la comprobación— y
`f8_un_libro_de_una_version_que_este_servidor_no_conoce_no_es_firma_rota`.

**Al desplegar esto hay que decirlo**: los libros que los docentes tengan bajados siguen
funcionando, pero **sin el espejo de las faltas**, y el renglón se lo dice a quien lo suba con esas
palabras. Volver a descargar el libro quita el aviso.

### 3.2 · La llave no puede ser el par: en la misma columna unos suben y otros bajan

El plan dice que la F8 se decide **por columna**. No basta. Los renglones salen **por alumno**, y en
la misma hoja y el mismo tipo es normal que a unos alumnos les suban las faltas y a otros les bajen
— es *el* caso normal, no un raro.

Con la llave en `hoja` + `tipo`, un grupo mixto se contestaría con **dos entradas de la misma
columna** y una de las dos se perdería: se aplicaría el borrado con el defecto de subir, o al revés.
Que es justo lo que esta fase existe para no hacer a ciegas.

Así que la llave es la **tripleta** `hoja` + `tipo` + `direccion`, en el ensayo y en el front. Lo
sujetan `la_misma_columna_se_contesta_distinto_en_cada_direccion` (unidad) y
`f8_las_dos_direcciones_de_la_misma_columna_se_contestan_por_separado` (contrato), que fabrica el
grupo mixto de verdad: un alumno de 0 a 2 y otro de 3 a 1, en la misma columna, contestando **sólo
la bajada**.

### 3.2.bis · Y la dirección se mide contra la BASE, no contra el espejo

*(Pregunta del front; el plan no la contesta.)* Las tres puntas pueden apuntar a sitios distintos:
**el libro pide 4, al descargar había 2, y el sistema tiene ahora 5.** Respecto al espejo eso sube;
respecto a la base baja.

**`direccion` dice `baja`**, con el criterio de la fase: lo que importa es lo que se va a borrar, y
ahí se borran tres filas con sus fechas. Llamarlo `sube` lo metería en el grupo cuyo defecto es
`aplicar` y borraría historia con el defecto de añadir.

En ese caso concreto hay además una segunda red —`base !== espejo` lo convierte en **choque** y gana
el sistema—, pero **la red no se puede usar como argumento**: un libro de formato 1 no la tiene, y
ahí la dirección es lo único que separa «añadir» de «borrar».

### 3.3 · `espejo: null` no se puede pintar con un guion, y el motivo lo tiene el servidor

El front pintaba `—` y no sabía si quería decir «el libro es de una versión anterior y no trae
espejo» o «había cero». **Son cosas distintas y la segunda es un número, no un desconocido.**

Las tres causas de un `espejo: null` son distintas y sólo una es un problema del libro:

1. **Firma rota** (peldaño 2): el espejo existe y no se puede creer.
2. **Formato anterior**: se bajó antes de que esto se guardara.
3. **El alumno no estaba** en la rejilla al descargar.

Así que el motivo va **dentro de `si_no_hago_nada`**, que es donde el docente ya está mirando, y lo
escribe el servidor, que es el único que lo sabe.

### 3.4 · «Las más recientes primero» no sobrevive a mirar los datos: **tres de cada cuatro faltas no tienen fecha**

El encargo dice *«borrar las más recientes primero; borrar la más antigua tiraría el registro que
más se consulta»*. El motivo es bueno. La regla, tal cual, hace lo contrario de lo que el motivo
pide.

*Contado sobre `caz_zaragoza` el 21 sep 2026, filas vivas de `ausencias`:*

| Población | Filas | Sin `fecha_hora` |
|---|---|---|
| Faltas de clase (`entrada = 0`, con asignatura) — **las que el libro cuenta** | 544 | **420 (77 %)** |
| Faltas de portería (`entrada = 1`) — el libro no las ve | 352 | 0 |
| **Total** | **896** | 420 |

```sql
SELECT entrada, (asignatura_id IS NULL) AS sin_asig, (fecha_hora IS NULL) AS sin_fecha, COUNT(*)
  FROM ausencias WHERE deleted_at IS NULL GROUP BY 1,2,3;
```

O sea que **tres de cada cuatro filas que esta columna puede borrar no están en ningún día**.
`AusenciasController::putDeAlumno` ya lo tenía escrito —*«hay filas que cuentan en los totales y no
están en ningún día»*— y cambia la conclusión: MySQL manda los `NULL` **al final** en un `ORDER BY …
DESC`, así que un `fecha_hora DESC` a secas se llevaría por delante las pocas filas fechadas y
dejaría intactas las 420 que no dicen nada. **Destruir la única información que hay para conservar
la que no existe.**

El orden es `(fecha_hora IS NULL) DESC, fecha_hora DESC, id DESC`: **primero lo que no tiene nada
que perder**, y sólo después la regla del encargo. Sirve **mejor** a su propio motivo que su lectura
literal — lo que se protege es el registro que alguien puede consultar, y una falta sin día no sale
en ninguna consulta por fecha.

Lo sujeta `f8_bajar_con_la_decision_puesta_borra_las_mas_recientes_y_por_el_camino_blando`, que pone
cuatro faltas —tres fechadas y una sin fecha—, baja el conteo en dos y comprueba **qué dos
sobreviven**. **Visto en rojo** con el orden natural de MySQL.

### 3.4.bis · Y el segundo argumento de «las más recientes», que sí se mantiene

La falta que **esta misma importación acaba de crear** —fechada hoy— es la más reciente de todas.
Así que corregir un error de tecleo subiendo y bajando deshace lo que se acaba de hacer, en vez de
morderle un día real al historial. Eso vale con o sin la corrección de arriba.

---

## 4 · La marca de «vino de una planilla»: **no hay columna, y no se inventa**

El encargo pide *«una marca que diga que vino de una planilla, para que quien mire la planilla de
acudientes entienda por qué hay tres faltas el mismo día»*, y añade: *«usa el campo que ya exista
para eso; si no existe ninguno, dilo»*.

**No existe ninguno.** Lo único parecido es `ausencias.uploaded`, y no sirve:

```
uploaded  →  (null) 854 · created 278 · deleted 7     (caz_zaragoza, 21 sep 2026, 1.139 filas
                                                      contando las borradas — las vivas son 896)
```

Es **el estado de sincronización del lector de tardanzas y de la app**, no el origen de la fila:
`TSubirController` y `AsistenciasAppController` escriben `created` al subir y `deleted` al borrar, y
`TSubirController:101` **decide** con `uploaded == 'to_delete'`. Además viaja a Flutter en cada
payload de asistencia. Meter ahí un cuarto valor sería cambiar el contrato de una app que es **una
sola para los dieciséis colegios** y cuyas versiones viejas conviven meses — la misma razón por la
que no se toca `ausencias/*`.

Así que la marca va donde **sí** hay sitio para ella: **`auditoria`**, que es el rastro que esta
familia usa desde el 22 ago 2026, con `origen: 'planilla sin internet'` dentro del `valor_nuevo`.
Es la misma forma que ya usa la F9 para los indicadores creados desde una columna de reserva.

**Y se dice lo que eso NO resuelve, porque es la mitad del encargo:** la planilla de ausencias del
acudiente lee **filas de `ausencias`**, y ahí la marca no está. Quien la mire seguirá viendo tres
faltas el mismo día sin saber por qué; lo que hay es dónde preguntarlo. Ponerlo en el papel necesita
**una columna en `ausencias`**, y antes de escribirla toca `tools/lo-que-reparte-una-columna.py`,
porque esa tabla entra en boletines, informes, el observador y el contrato de Flutter. **Queda
abierto y es decisión de Joseth** (§6.1).

### Y `bitacoras` no, tampoco

El encargo dice «con `bitacoras` y `Auditoria::registrar()`». Aquí se escribe **sólo `auditoria`**, y
es lo que hacen las seis rutas de `ausencias/*` (`AusenciasController::anotar`): **`bitacoras` no
tiene vocabulario para una falta** —sus `affected_element_type` son `Nota`, `NF_UPDATE`, `Nueva
subunidad`…— y las dos pantallas del front que la leen buscan **por tipo**. Un tipo nuevo ahí sería
una fila que nadie ve, en la tabla que el [18](18-auditoria.md) está retirando.

Consecuencia contada, que es la que el `3ebdc07` enseñó a declarar: **los `INSERT INTO bitacoras`
siguen siendo 14**, así que `CentinelaDeLosEscritoresDeBitacoraTest` no se mueve y
`tools/salud-de-la-bitacora.php` tampoco.

---

## 5 · Lo que la escritura hace, en cuatro líneas

- **Dentro de la transacción por fila de alumno que ya existía**, detrás de las notas, con su marca
  del punto de control en la misma transacción. Una fila está aplicada si y sólo si el punto de
  control la da por hecha, y eso ahora incluye las faltas.
- **Una fila del plan se crea aunque no haya ni una nota que escribir.** Es el caso normal de esta
  fase —un docente que sólo corrigió las faltas— y sin esto esa fila no tendría transacción.
- **En el reloj de Bogotá** (`Reloj::ahora()`), como el resto de la importación: `fecha_hora`,
  `created_at`, `updated_at` y `deleted_at`.
- **Las filas escritas a mano (F6) no llevan asistencia**, y no es un olvido: esas casillas nacen
  vacías en el libro, así que leerlas sería ver un hueco donde el sistema tiene faltas — y eso se
  leería como «bajar a 0» en cada fila que alguien resolviera.

### El choque de la F8 lo gana el sistema **siempre**, y no hay excepción

En las notas la pantalla de §6.3 deja repasar los choques uno a uno. El contrato de esta familia se
decide **por columna y dirección**, así que no hay sitio donde contestar «en este alumno manda el
archivo». Antes que inventar un mecanismo, gana el sistema siempre y el renglón lo dice con su
frase. Lo sujeta `f8_cambio_en_los_dos_sitios_es_un_choque_y_manda_el_sistema`, que además **lo pide
con `aplicar` puesto** y comprueba que no pasa nada. Queda anotado en §6.

---

## 6 · Lo que queda abierto

1. **La marca de origen no llega al papel del acudiente** (§4). Hace falta una columna en
   `ausencias` y el reparto de `tools/lo-que-reparte-una-columna.py` antes de escribirla.
2. **Un choque de la F8 no se puede resolver por el archivo.** Es correcto y es estrecho: si
   apareciera de verdad y a menudo, el contrato tendría que crecer con excepciones por `id`, como
   las de `choques`.
3. **Una casilla de `Aus` que no es un entero no se declara.** Un `4,5`, un guion o un texto en una
   columna que cuenta faltas no tienen lectura posible —no hay media falta ni «borrar la cuenta»— y
   se ignoran en silencio. No se llevan a la F4 a propósito: esa pantalla ofrece «interpretar como
   N» sobre algo que no es una nota. Si un docente escribe ahí y no pasa nada, hoy no se entera.
4. **La escritura de las faltas no está cronometrada.** Crear N faltas son N `INSERT` más N filas de
   `auditoria`, dentro de la transacción de la fila. Con conteos de una cifra no se nota; con un
   colegio que suba treinta faltas de golpe a cuarenta alumnos, no se ha medido.
5. **El espejo de asistencia engorda el mapa.** Son **dos números por alumno** dentro del `json` de
   la hoja, ~40 bytes por alumno: un grupo de 45 son ~1,8 KB, muy lejos del tope de 32.767
   caracteres de una celda de Excel. Va ahí y no en filas sueltas como el espejo de las notas
   porque aquél crece con *alumnos × indicadores* y éste no.
6. **Las faltas de portería (`entrada = 1`) quedan fuera del libro, y es por construcción y no por
   un filtro.** Las 352 que hay en `caz_zaragoza` tienen las 352 `asignatura_id` a null, así que ni
   entran en el total que el libro imprime ni pueden salir de aquí. Si algún día un colegio anotara
   una falta de portería **con** asignatura, entraría en la cuenta — y eso no se ha visto.
7. **Un ensayo recortado por tiempo enseña renglones de filas que esa petición no va a escribir.**
   La familia se calcula de una pasada por hoja —una consulta— mientras que las filas se escriben en
   el bucle, así que si el reloj corta a la mitad, los renglones de abajo se aplican en la petición
   siguiente y no en ésta. La promesa se cumple, sólo que más tarde, y `terminado: false` ya lo
   dice; pero un recuento leído en medio de un libro enorme no cuadra con la familia que se enseñó.

### Y una regla que sí se cerró: el que ya no está en el grupo no se pregunta

Un alumno cuyo `ID` estaba al descargar y hoy ya no está en el grupo (F6,
`ya_no_esta_en_el_grupo`) **no produce renglón de ausencias**, por lo mismo que sus notas se quedan
fuera: la escritura se salta su fila entera, así que enseñar una decisión sobre sus faltas sería
prometer algo que la importación no va a hacer. Lo que se le dice es lo que ya dice su renglón de la
F6 — se retiró, con su fecha y su motivo. Lo sujeta la ampliación de
`f6b_el_id_que_ya_no_esta_en_el_grupo_se_informa_con_su_motivo_y_no_se_pregunta`, **vista en rojo**
quitando ese filtro.

---

## 7 · Lo que se corrió

Con `DB_TEST_DATABASE=simonbolivar_testing_f8` (base de esta sesión, construida el 21 sep 2026 con
`tools/construir-bd-test.sh`, **48/48 migraciones, 118 tablas**).

<!-- RECUENTO -->

### Y una tanda que NO valió, porque el árbol se movió debajo

La primera pasada entera salió **1 roja, 2.808 verdes, 14 saltadas (56.311 aserciones) en 1.089,92 s**,
y la roja no era de esta entrega:

```
⨯ Tests\Contrato\RutasTest > cada uri la atiende la misma accion
  Aparecieron rutas nuevas sin actualizar el snapshot:
  GET api/auditoria/alumno/{id}, GET api/auditoria/entidad/{tipo}/{id},
  GET api/auditoria/ingresos, GET api/auditoria/ingresos/{id}
```

**Esta fase no añade ni una ruta.** Lo que pasó lo dicen las horas del propio árbol compartido:

| Hora | Qué |
|---|---|
| 19:11:55 | se lanza la tanda |
| 19:27 | otra sesión crea `app/Http/Controllers/Auditoria/AuditoriaController.php` y toca `routes/api/informes.php` |
| — | la tanda llega a `RutasTest`: las cuatro rutas ya existen y el snapshot todavía no |
| 19:28 | esa sesión actualiza `tests/Contrato/Snapshots/rutas.json` |
| 19:30 | la tanda termina con esa roja ya escrita |

Relanzando **sólo** la clase con el snapshot ya actualizado:
`RutasTest|FamiliasQueNuncaEntranTest|GuardsDestructivosTest|RutasPreLoginTest` → **22 passed**.

La lección no es «otra sesión rompió algo» —no rompió nada, estaba a mitad de su propio trabajo—
sino que **una tanda de 18 minutos sobre un árbol compartido puede leer dos estados distintos del
mismo commit**, y el rojo sale en una familia que no tiene nada que ver con lo que uno está
entregando. Por eso se relanzó entera.

### Y la segunda tampoco valió, por lo mismo con otra cara: **39 rojas y una migración**

La segunda pasada salió con **39 rojas**, y las 38 primeras eran todas de `auditoria`:

```
⨯ anotar y borrar una falta dejan las dos lineas
⨯ editar una nota deja una linea con los dos valores
⨯ un login fallido no inventa un actor
… (38 en total, todas escribiendo en `auditoria`)
⨯ no hay relojes sin zona nuevos
```

Entre el lanzamiento (19:30) y el final, la otra sesión **commiteó tres veces**, y una de ellas
—`c60a88e feat(auditoria): el valor viejo también en un entero`— **trae una migración**:
`2026_09_21_200000_el_valor_entero_de_la_auditoria`. La base de esta sesión tenía 48 de las 49, así
que **todo `Auditoria::registrar()->guardar()` fallaba** y con él los 38 tests que lo miran — más el
`f8_subir_un_conteo…` de esta entrega, que comprueba la línea de auditoría de una falta creada.

Es exactamente el modo de fallo que el [03](03-tests.md) deja escrito para las bases por sesión
—*«se quedan viejas por separado»*— sólo que aquí no se quedó vieja con el tiempo: **se quedó vieja
a mitad de la tanda**. Se arregla con una línea y hay que saber buscarla:

```bash
docker exec -e DB_DATABASE=simonbolivar_testing_f8 8myvc-app-1 php artisan migrate --force
```

**El diagnóstico es caro porque el síntoma apunta lejos**: 38 rojas repartidas por ocho familias que
no se tocaron, con toda la cara de una regresión del escritor único. Lo que lo delata no es leerlas
—son todas distintas— sino contar **qué tienen en común** y mirar `git log`.

### La roja que queda, y de quién es

Tras migrar: `f8_*`, `RespuestasDeLaPlanillaTest`, `AuditoriaEscritorUnicoTest`, `RutasTest` y
`RelojUnicoTest` → **65 passed, 1 failed**, y la que falla **no es de esta entrega**:

```
⨯ RelojUnicoTest > no hay relojes sin zona nuevos
  +  'app/Http/Controllers/Auditoria/AuditoriaController.php' => 2,
```

Son los dos `now()` sin zona del controlador que `72942f2` estrenó, sin declararlos en los
`PERMITIDOS` de ese centinela. **Es el centinela haciendo su trabajo sobre el cambio de la otra
sesión**, y la decisión que pide —¿esas horas acaban en una columna o sólo se comparan consigo
mismas?— es suya y no se toca desde aquí. Esta fase no añade ni un `now()`: escribe siempre con
`Reloj::ahora()`.

### Los seis que se vieron en rojo

*«Un test que no se ha visto en rojo no prueba nada»* ([03 §Un test que no se ha visto en rojo](03-tests.md)).
Se revirtió el comportamiento y se comprobó que el test canta:

| Se rompió | Se puso rojo |
|---|---|
| `porDefectoSegunLaDireccion` devolviendo siempre `aplicar` | `el_defecto_de_las_ausencias_no_es_simetrico` + `f8_bajar_un_conteo_no_borra_nada_si_nadie_lo_pide` |
| `ORDER BY` de las candidatas a `ASC` | `f8_bajar_con_la_decision_puesta_borra_las_mas_recientes…` |
| `ORDER BY` sin el `(fecha_hora IS NULL) DESC` | el mismo |
| La regla 1 de la D3 (`archivo === espejo`) | `f8_d3_lo_que_el_docente_no_toco_no_se_toca…` |
| `comprobar()` sin el formato del libro | `f8_un_libro_de_la_version_de_formato_anterior_se_sigue_leyendo` |
| El filtro de `matriculados` en `estudiarLasAusencias` | `f6b_el_id_que_ya_no_esta_en_el_grupo…` |

Y seis salieron rojos **solos** en la primera corrida, por una razón que merece quedar escrita:
**los alumnos del seed ya tienen faltas** en esa asignatura y ese periodo, así que «subir de 0 a 3»
no era de 0 a 3 y los seis fallaron con el mismo desfase de uno. *Un caso fabricado sobre una
población que no se ha mirado no es un caso: es una coincidencia.* El helper `conFaltas()` empieza
vaciando.
