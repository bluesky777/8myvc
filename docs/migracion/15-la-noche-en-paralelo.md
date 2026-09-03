# El reparto de la noche — 22 al 23 de agosto de 2026

> Para la sesión que coordina y para las que trabajan. **Se lee entero antes de
> tocar nada**, y luego cada sesión solo necesita su lote.
>
> Documentos hermanos que este no repite: [09-pendientes.md](09-pendientes.md)
> (qué está parado y por qué), [05-codigo-muerto-y-roto.md](05-codigo-muerto-y-roto.md)
> (los hallazgos, §1 a §80), [03-tests.md](03-tests.md) (cómo se corre y qué no
> cubre el seed), [../DESPLIEGUE.md](../DESPLIEGUE.md) (la tanda sin desplegar).

Lo que hace distinta a esta noche de la anterior no es el reparto: es que
**cada sesión trabaja en su propio árbol**. La noche del 21 al 22 trabajaron
cinco sobre el mismo, y **cuatro commits se llevaron dentro trabajo ajeno**
([09 §0.1](09-pendientes.md)). Se probaron tres reglas para
evitarlo y ninguna cerró el agujero, porque **cualquier secuencia de dos pasos
tiene un antes y un después**. Un árbol por sesión no tiene esa ventana.

> **Antes de crear ningún worktree, esto tiene que estar en `main`.**
> `git worktree add` sale de `HEAD`, no del árbol de trabajo: un worktree creado
> con este documento sin commitear nace sin él, y sin `tools/worktree-de-sesion.sh`.

---

## 1. El estado, medido hoy — no copiado de ayer

Todo lo de esta tabla se midió el 22 de agosto de 2026 por la noche, contra
`main` en `5a78deb`, con una base propia.

| Qué | Número | Cómo se midió |
|---|---|---|
| Rutas | **539** | `php artisan route:list --json` |
| Tests de la suite de Contrato | **903, todos en verde** (828 s) | `--testsuite=Contrato` |
| Rutas con la respuesta comprobada | **461 de 539 (85%)** corriendo solo Contrato | `tools/cobertura-de-rutas.py` |
| Controladores sin ninguna comprobada | **0** | idem |
| Controladores a medias | **41** | idem |
| larastan | **nivel 7, `[OK]`**, también con la caché borrada | `composer run stan` |
| pint sobre su alcance | verde | `composer run pint:test` |
| Ficheros de `app/` que pint cambiaría | **164** (107 en `Http/`, 48 en `Models/`) | `pint --test app` |
| Bases de test montadas | **5**, las cinco con **54** migraciones | `SHOW DATABASES LIKE '%testing%'` |
| `respuestas-que-mienten.py` | **1 sitio**, y ya juzgado ([05 §74](05-codigo-muerto-y-roto.md)) | la herramienta |
| `interruptores-que-nadie-lee.py` | 157 columnas `tinyint(1)`; **44 no las lee nadie** en el backend, 48 ni se nombran | la herramienta |
| `identificadores-del-cuerpo.py` | **230 rutas**, 29 familias de identificador sin comprobar propiedad | la herramienta |

### El 461 y el 462 no se contradicen, y la diferencia es exactamente una ruta

El commit `5a78deb` dice **462**; esta medición dice **461**, con el mismo código.
No hay que buscar una regresión: la diferencia es **`GET /`**, y lo único que la
toca es `tests/Feature/ExampleTest.php`, **el stub que dejó `laravel new`**. Con
`--testsuite=Contrato` esa suite no corre y la ruta cae del lado de las no
comprobadas.

O sea que **el trabajo real que queda son 77 rutas de la API**, no 78, y la
cobertura no llegará nunca a 539 por esa vía. Conviene tenerlo escrito porque un
número que baja solo es justo la clase de cosa que manda a alguien a buscar
media hora lo que no existe.

### Y una serie que se puede dar por agotada

`respuestas-que-mienten.py` —«qué método frena la escritura y responde 200
igual»— **da un solo sitio**, y ese sitio ya tiene su párrafo en la
[§74](05-codigo-muerto-y-roto.md). Empezó dando catorce. Nadie tiene que volver
a mirar ahí esta noche: es un resultado, no un hueco.

---

## 2. Lo que NO es trabajo de esta noche, y por qué

Esta lista vale tanto como la cola. Cada una de estas parece trabajo disponible,
y las seis están cerradas por una razón que ya se pagó una vez.

| No se toca | Por qué |
|---|---|
| **Pasar pint a los 164 ficheros** | Lo prohíbe CLAUDE.md, y no por gusto: el diff sería ilegible y taparía el trabajo de las seis sesiones a la vez. Se formatea el fichero **el día que se toca por otra cosa** — o sea, si tu lote toca `AreasController`, ese sí |
| **Larastan nivel 6** | Saltado a propósito, con la medición delante: 1.940 errores, **ninguno señala código que pueda fallar**, y el 68% cae en los controladores. Es la misma deuda que pint y se paga igual. Está en `phpstan.neon` con su porqué |
| **Rector** | Configurado y sin correr **a propósito**: va por carpeta y revisando cada diff, que es trabajo de decisión, no de noche |
| **Unificar las fechas en `-05`** ([09 §2](09-pendientes.md)) | No es una línea de config: cambia lo que `Carbon::now()` devuelve **para lo ya escrito**, y los `expires_at` de `personal_access_tokens` vivirían cinco horas de más sin que falle nada. Necesita una ventana en la que se puedan invalidar todas las sesiones vivas |
| **Colas para importadores e informes** ([09 §3](09-pendientes.md)) | Cambia el contrato de los cuatro clientes, y uno es la app de Flutter, **una sola para los quince colegios**: no se puede escalonar |
| **El cálculo de las definitivas** ([10-definitivas.md](10-definitivas.md)) | Parado por decisión de Joseth hasta que termine la migración, para no tener dos frentes sobre `notas_finales`. **Ojo al matiz**: lo congelado es el cálculo. La autorización y el candado del periodo de esas mismas rutas **sí** se han seguido tocando (§71, §73, §77) y siguen en juego — ver el lote C |
| **La tabla del [§5 de 09](09-pendientes.md)** | Diecisiete filas que **esperan una respuesta de Joseth o del colegio**, no código. Encenderlas por iniciativa propia enciende pantallas en dieciséis colegios. Si tu lote roza una, se anota y se deja |
| **Desplegar** | Es de Joseth, y hay una tanda entera esperando: diez y pico arreglos de autorización y **tres migraciones**, una de ellas con orden obligatorio (`password_reminders` **antes** que `app/`). [DESPLIEGUE.md](../DESPLIEGUE.md) |

---

## 3. El montaje: un árbol por sesión, y la trampa que tiene

```bash
tools/worktree-de-sesion.sh a fix/lote-a-catalogos
```

Deja `.worktrees/a` en su rama, con su `.env`, su `vendor/`, sus carpetas de
`storage/`, su base `simonbolivar_testing_a`, e imprime **desde dónde carga las
clases**, que es lo único que demuestra que el aislamiento existe.

**El árbol va dentro del proyecto y no al lado.** El contenedor monta
`/Users/.../8myvc` en `/app` y nada más; un worktree hermano no lo ve, así que
no se le pueden correr los tests. Ya se intentó el 19 de agosto —
`../8myvc.worktrees/` sigue ahí, vacío—.

**Y una corrección medida esta misma noche, porque aquí decía lo contrario**: git
**no desciende** dentro de los worktrees que él mismo registró —por eso
`git status` no lista sus ficheros— pero **sí lista la carpeta**:

```
$ git status --short
?? .worktrees/
$ git check-ignore -v .worktrees/d     # exit=1: no está ignorado
```

O sea que un `git add -A` desde la raíz se los encuentra delante, y quien
coordina verifica el índice con un número (`git diff --cached --stat`) que esto
mueve. Se arregla con `/.worktrees/` en **`.git/info/exclude` del árbol raíz** —
local, y no en `.gitignore`, que se copia a los quince colegios. Hecho el 22
de agosto de 2026. Lo encontró la sesión del lote D mirando su `git status`, que
es lo que nadie hace cuando el documento ya afirma que sale limpio.

### `vendor/` no se puede enlazar, y eso costó encontrarlo

Lo primero que se prueba es un symlink a `vendor/`, que es exactamente lo que
hace el despliegue con los quince colegios (CLAUDE.md). **Aquí miente.**
`autoload_psr4.php` calcula su raíz con `dirname(__DIR__)`, y `__DIR__` en PHP
resuelve los symlinks: con `vendor` enlazado, la raíz sale `/app` y el worktree
**carga el `app/` del árbol principal**. Medido:

```
$ docker exec -w /app/.worktrees/prueba 8myvc-app-1 php -r '...getFileName()'
/app/app/Http/Controllers/AlumnosController.php      <-- el de OTRO árbol
```

O sea: la sesión edita sus ficheros y prueba los de otra. Es la forma de fallo
más cara de este repo —el instrumento que miente con la cara del problema— y
esta vez **con los tests en verde**, porque los tres que se corrieron pasaban en
los dos árboles.

Y por dónde se vio, que es lo que hay que reconocer la próxima vez: **`stan` daba
2 errores en el worktree y `[OK]` en el principal, con el mismo fichero y el
mismo `phpstan.neon`.** Con `vendor/` copiado los dos dan `[OK]`. Un `stan` que
no coincide con el del árbol principal **no es un fallo de larastan: es el
aislamiento roto.**

Por eso el script copia `vendor/` con `cp -al` —enlaces duros: 40 segundos y
unos 12 MB reales de los 177—. Con enlaces duros, **un `composer install` dentro
de un worktree escribiría también en el de las demás**; es el mismo mecanismo
que la topología de los colegios y aquí tiene la misma consecuencia. Si alguna
sesión lo necesita, que lo diga antes.

### Lo que sigue compartido, y hay que nombrarlo

| Compartido | Qué se hace |
|---|---|
| `/tmp` del contenedor | `TMPDIR=/tmp/stan-<sufijo>` para stan, `COBERTURA_RUTAS=/tmp/tocadas-<sufijo>.txt` para la cobertura. **Un fichero por sesión**: una medición salió *86 de 539 cuando eran 346* por compartirlo |
| Las bases de test | Una por sesión, la que monta el script. **Y comparar las migraciones antes de diagnosticar nada**: mordió tres veces el 21 de agosto, y las tres con cara de test de contrato en rojo |
| `vendor/` | Nadie lo toca |
| `main` | **Solo lo mueve quien coordina, y solo en el árbol raíz.** Ninguna sesión hace `checkout main` |

---

## 4. Las reglas de la noche

Siete, y las siete costaron algo. Las de git de la noche anterior desaparecen
casi todas con los worktrees; las que quedan son las que el worktree no arregla.

1. **Tu lote es tuyo entero, incluidos sus ficheros.** El reparto está hecho por
   **controlador**, no por ruta, justo para que dos sesiones no editen el mismo
   fichero. Si necesitas tocar un controlador de otro lote: **se anota, no se
   toca** — salvo que ese lote ya esté cerrado.
2. **Un documento por sesión.** El tuyo es
   `docs/migracion/noche-2026-08-23/<lote>.md`. **Nadie escribe en el 05, ni en
   el 09, ni en DESPLIEGUE.md**: los tres son de quien coordina, que los funde en
   frío al final. Es la conclusión de la noche anterior después de cuatro
   commits que se llevaron trabajo ajeno dentro.
3. **Tu rango de secciones del 05 ya está asignado** (en la tabla de la cola).
   Escribe `§NN` desde el principio, también en los docblocks de los tests, y al
   fundir no hay que renumerar nada.
4. **Comprueba al revés, y cuenta cuántos tests caen.** Revierte lo que de verdad
   cambió el comportamiento; si tu arreglo tapa dos caminos y cae un test, el
   otro no estaba probado. Y revierte también **a la solución equivocada que
   parecía buena**: de ahí salió un verde hueco que leer el test no destapaba.
5. **Un detector da sitios donde mirar, nunca una lista de fallos**, y una lista
   sin clasificar es más peligrosa que no tenerla. El mismo patrón se midió
   cuatro veces y ninguna medida era la buena.
6. **Si el resultado va a ser un número o un veredicto, el comando no lleva tubo
   que corte.** `| head -5` para contar dijo cinco donde había seis; `| tail` se
   tragó un código de salida y un script siguió como si nada. Tres de los ocho
   instrumentos que mintieron son de esta familia.
7. **Nadie autoriza a nadie.** Coordinar es administrar el permiso que Joseth ya
   dio; no es concederlo. Si la medición cambia lo que va a pasar, el permiso se
   vuelve a pedir aunque el arreglo sea más pequeño.

Y una que no es regla sino el método que ha dado casi todo:

> **Mira el resultado, no el estado.** El píxel en vez del 200, la forma de la
> hoja de Excel en vez de los bytes, el viaje de ida y vuelta en vez de una
> llamada. Y cuando el hueco llega plano —77 rutas en 41 controladores—,
> **la pregunta agrupa mejor que la carpeta**: por eso los lotes de abajo son
> preguntas.

### El tablero, que no está en git

Las sesiones no se ven entre sí: cada worktree tiene sus ficheros sin commitear.
Para decir en voz alta qué coges —la regla que evitó que dos escribieran el mismo
test del mismo endpoint— hay una carpeta fuera del repo:

```
/Users/josethguerrero/DESARROLLOS/8myvc-cola/
    TABLERO.md          solo lo escribe quien coordina
    a.md  b.md ...      uno por sesión; cada una escribe SOLO el suyo
    LOTES.txt           la cola, en orden de servicio
    tomar-lote.sh       servirse uno, sin carrera — ver abajo
    refrescar-tablero.sh
    BRIEFING.md         lo que lee una sesión al entrar
```

**Los tres scripts se quedan puestos para la próxima noche**, y el que importa es
`tomar-lote.sh`: reservar con un `mkdir` es **un solo paso**, mientras que
servirse «leyendo la tabla y escribiendo debajo» tiene un antes y un después — y
en ese hueco caben dos sesiones cogiendo lo mismo.

Una línea por cosa: hora, qué coges, qué sueltas. Si añades una migración,
**va aquí en mayúsculas**: las bases de las demás se quedan viejas y lo que verán
son tests de contrato en rojo con mensajes creíbles.

---

## 5. La cola

> **Esto es la cola con la que se empezó, y la noche terminó con veinte lotes**:
> se fueron abriendo sobre la marcha según lo que iba destapando cada uno. El
> reparto real —quién hizo qué y con qué §§— está en
> [`noche-2026-08-23/README.md`](noche-2026-08-23/README.md); esta tabla se deja
> como estaba porque **lo que enseña es el tamaño de un lote**, no cuáles fueron.

Seis lotes de cobertura, disjuntos por controlador, y dos de detector que van
detrás. **Caben hasta seis sesiones trabajando a la vez** más la que coordina; con
menos, sobran lotes y no pasa nada — lo que no se puede es partir un lote en dos
sesiones, porque entonces vuelven a compartir fichero. **Nadie espera respuesta
para empezar**: coge el tuyo y, al cerrarlo, sírvete el siguiente libre del
tablero.

Cada lote es la misma forma de trabajo, la que ha dado los hallazgos: **leer el
controlador con una pregunta delante, escribir el test que fija lo que responde
hoy, y separar lo que se arregla de lo que se anota.** Un test que fija lo que
hay también fija lo que está mal, así que **al lado de cada valor va escrito por
qué es ese** — aunque solo sea «no se juzgó».

| Lote | La pregunta | Rutas | Controladores (son tuyos) | §05 |
|---|---|---|---|---|
| **A** | Los catálogos del colegio: **editar y borrar**, que es la mitad que la §78 no miró | 18 | Areas, Frases, FrasesAsignatura, DefinicionesComportamiento, EscalasDeValoracion, TipoDocumento, Materias, Contratos, NivelesEducativos, Grados | §81–84 |
| **B** | Los dos huecos más grandes que quedan: **ordinales de disciplina** y **ciudades** | 13 | Ordinales, Disciplina, Ciudades, Bitacoras | §85–88 |
| **C** | La rejilla: **quién escribe una definitiva y con qué candado** — medir y fijar, el cálculo no se toca | 13 | DefinitivasPeriodos, Notas, NotaComportamiento, Puestos, Boletines2, Boletines3, Subunidades | §89–92 |
| **D** | La **configuración del año**: años, periodos, asignaturas, unidades | 10 | Years, Periodos, Asignaturas, Unidades, Prematriculas | §93–96 |
| **E** | **Personas e imágenes**: quién ve y quién escribe la ficha de otro | 11 | Profesores, Acudientes, Perfiles, Images, ImagesUsuarios, Publicaciones, Planillas | §97–100 |
| **F** | **PIAR, actividades y votaciones**: los interruptores de lo que ve el alumno | 12 | PiarsActasAcuerdo, PiarsAsignaturas, PiarsConfig, PiarsGrupos, Actividades, MisActividades, VtVotaciones, ConfigCertificados | §101–104 |
| **G** | Los **44 interruptores `tinyint(1)`** que en el backend no decide nadie: ¿los mira algún cliente? | — | ninguno (documento y tests nuevos) | §105–107 |
| **H** | Los **230 identificadores del cuerpo**, la parte que no ha leído nadie | — | ninguno propio: **lee y reporta** | §108–110 |

### Lote A — Catálogos: editar y borrar

Continúa dos series abiertas. La [§78](05-codigo-muerto-y-roto.md) midió
**crear** en nueve catálogos y encontró que el mismo cuerpo vacío saca **cuatro
respuestas distintas**, y que lo que las separa **no es el código sino el
esquema**. La [§70](05-codigo-muerto-y-roto.md) midió qué se lleva por delante
**borrar** uno. Falta el resto de la matriz.

```
DELETE api/areas/destroy/{id}                          PUT api/areas/update/{id}
DELETE api/frases/destroy/{id}                         PUT api/frases/update/{id}
DELETE api/frases_asignatura/destroy/{id}              POST api/frases_asignatura/store/{frase_id?}
DELETE api/definiciones_comportamiento/destroy/{id}    POST api/definiciones_comportamiento/store-escrita
POST   api/escalas/store
PUT    api/tiposdocumento/{tiposdocumento}             PATCH api/tiposdocumento/{tiposdocumento}
PUT    api/materias/update/{id}
DELETE api/contratos/destroy/{id}
DELETE api/niveles_educativos/destroy/{id}             PUT api/niveles_educativos/update/{id}
PUT    api/grados/update/{id}
GET    api/niveles_educativos/show/{id}                (solo auth.token)
GET    api/grados/show/{id}                            (solo auth.token)
```

Dos cosas que ya están medidas y **no** hay que volver a averiguar:

- `contratos` era el único de los nueve que **escribía** una fila huérfana y
  contestaba 200; ya está cerrado. Los otros ocho no escriben, y **lo impide el
  `NOT NULL` del esquema, no el código**.
- **Borrar un grado apaga la planilla de sus profesores y no hay forma de
  deshacerlo** ([05 §70](05-codigo-muerto-y-roto.md)): el profesor se queda con
  0 asignaturas y la rejilla de grupos **sigue enseñando el grupo**. Está en la
  tabla del §5 esperando decisión: **no lo arregles**, mídelo y fíjalo.

Y la pregunta que el lote tiene que contestar de verdad: **de los cuatro `GET
.../show/{id}` que solo piden `auth.token`, ¿qué ve por ahí un alumno?**

### Lote B — Ordinales y ciudades

`OrdinalesController` es **1 de 6 comprobadas**, el peor que queda, y sus
ordinales de disciplina ya dieron una inyección la noche anterior
([05 §55](05-codigo-muerto-y-roto.md)) — o sea que ese fichero ya se leyó
buscando **otra cosa**, que es exactamente cuando se escapan las de esta.

```
POST api/ordinales/store          PUT api/ordinales/update
PUT  api/ordinales/destroy        PUT api/ordinales/guardar-valor
PUT  api/ordinales/guardar-valor-config
PUT  api/disciplina/update
DELETE api/bitacoras/destroy/{id}
GET  api/ciudades/datosciudad/{ciudad_id}         (solo auth.token)
GET  api/ciudades/departamentos/{pais_id}         (solo auth.token)
GET  api/ciudades/paisdeciudad/{ciudad_id}        (solo auth.token)
GET  api/ciudades/por-departamento/{departamento} (solo auth.token)
PUT  api/ciudades/departamentos-by-id
DELETE api/ciudades/destroy/{id}
```

`ordinales/destroy` y `ciudades/departamentos-by-id` salen además en
`identificadores-del-cuerpo.py` como identificador **sin comprobar propiedad**.
Las nueve rutas de catálogo sin guard del [08](08-revision-idor.md) rozan esto:
**si tocas una, se anota; se decide después.**

### Lote C — La rejilla de definitivas y las notas

**Lo congelado es el cálculo**, no la puerta. La [§77](05-codigo-muerto-y-roto.md)
encontró un `DELETE` físico de notas que no miraba nada, la
[§80](05-codigo-muerto-y-roto.md) las dos últimas que le faltaban al candado del
periodo, y las dos son de esta familia.

```
DELETE api/definitivas_periodos/destroy/{id}     PUT api/definitivas_periodos/toggle-manual
PUT    api/definitivas_periodos/toggle-recuperada PUT api/definitivas_periodos/eliminar-recuperada
DELETE api/notas/destroy/{id}                     GET api/notas/show/{nota_id}
PUT    api/nota_comportamiento/frases-check       PUT api/nota_comportamiento/guardar-libro
PUT    api/puestos/detailed-notas-year
DELETE api/boletines2/destroy/{id}                DELETE api/boletines3/destroy/{id}
POST   api/subunidades                            PUT api/subunidades/eliminadas/{asignatura_id}
```

La pregunta: **¿cuál de estas escribe o borra en la rejilla sin preguntar por el
interruptor del periodo?** `tools/escrituras-en-las-notas.py` la rehace por la
operación — y lleva escritas dentro **sus tres cegueras**, que costaron tres
falsos negativos en un día: solo miraba SQL crudo (`periodos/copiar` escribe con
Eloquent y se le escapó), leía prosa de los docblocks, y contaba truncado.

El candado **se pide por la fila que se toca, no por el cuerpo**: `App\Support\
PeriodoDeLaFila`. Y las cuatro rutas de ausencias **están fuera a propósito** —
Joseth decidió que el interruptor cierra las notas, no la asistencia.

### Lote D — La configuración del año

```
PUT api/years/guardar-cambios          PUT api/years/toggle-cambiar-valor
PUT api/periodos/useractive/{periodo_id}
POST api/asignaturas                   PUT api/asignaturas/update/{id}
PUT api/asignaturas/detalle-asignatura
GET api/asignaturas/list-asignaturas-year/{profesor_id}/{periodo_id}
DELETE api/unidades/forcedelete/{id}
PUT api/unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}
PUT api/prematriculas/alumnos-grado-anterior
```

Dos avisos que ahorran una tarde:

- **Las 44 rutas de escritura de materias, asignaturas, grupos, años y periodos
  llevan solo `auth.personal`**, o sea que hoy cualquiera de los 51 profesores
  configura el colegio. **Joseth decidió no cerrarlas todavía**, y está bien
  decidido: cerrarlas dejaría fuera a un coordinador que hoy configura y no
  tiene el rol. Mídelo, no lo cierres.
- `unidades/de-asignatura-periodo` **no es una lectura: escribe.** Salió de la
  lista de rutas sin guard del 08 por recategorización, no por decisión.

Y `list-asignaturas-year` es vecina de `listasignaturas-alone`, que le da a un
alumno las asignaturas del profesor con su mismo id y espera decisión
([05 §16.6](05-codigo-muerto-y-roto.md)). **La asimetría entre hermanas** es lo
que hay que mirar ahí: encontró tres inyecciones y un guard ausente en una noche.

### Lote E — Personas e imágenes

```
GET    api/profesores/show/{id}          DELETE api/profesores/destroy/{id}
PUT    api/profesores/listado
PUT    api/acudientes/ultimos            PUT api/acudientes/planillas-ausencias
DELETE api/perfiles/forcedelete/{id}
PUT    api/myimages/privatizar-imagen/{imagen_id}
PUT    api/images-users/cambiar-imagen-un-usuario/{user_id}
PUT    api/publicaciones/restaurar                       <-- solo auth.token
GET    api/planillas/show-grupo/{grupo_id}
GET    api/planillas/show-profesor/{profesor_id}
```

**Empieza por `publicaciones/restaurar`.** Es la única escritura de las 77 que
pide **solo token**, y la papelera ya dio dos hallazgos: el 21 de agosto se
cerraron sus rutas destructivas y **la mitad que devuelve se quedó abierta un
mes** en los mismos cinco sitios ([05 §76](05-codigo-muerto-y-roto.md)). Esta no
estaba entre esos cinco. Cuando una serie se cierra hay que anotar **sobre qué
población se cerró**, y aquella se cerró sobre otra.

Y `planillas/show-*` es la vecina de la que ya está medida: la planilla de la
puerta monta **1 + 13 + 378 consultas** en una petición para añadir una sola
columna que las dos hojas no imprimen ([05 §75.6](05-codigo-muerto-y-roto.md)).
`PlanillasAusenciasTest` deja el arreglo comprobable; **encoger la respuesta es
contrato con dieciséis copias del front**, así que se mide y se anota.

### Lote F — PIAR, actividades y votaciones

```
POST   api/piars-actas-acuerdo/document       DELETE api/piars-actas-acuerdo/document/{alumno_id}
PUT    api/piars-asignaturas/field            PUT api/piars-config/config
PUT    api/piars-grupos/contexto-de-grupo
PUT    api/actividades/para-acudientes-toggle PUT api/actividades/para-profesores-toggle
PUT    api/mis-actividades/guardar
GET    api/votaciones/show/{id}               (solo auth.token)
PUT    api/votaciones/update/{id}             PUT api/votaciones/set-votan-profes
POST   api/certificados/store
```

Tres cosas ya sabidas:

- **`para_alumnos` no decide nada para el alumno** ([05 §74](05-codigo-muerto-y-roto.md)):
  con él apagado el alumno abre el examen igual, y los dos interruptores solo se
  leen en listados del profesor. Los dos que te tocan son sus gemelos: **la
  pregunta es la misma, y la respuesta puede no serlo.**
- En votaciones, **`in_action` no es un candado** —manda al usuario a la
  pantalla, no cierra la urna— y **`locked` es una pausa reversible**. La misma
  columna significa cosas distintas en dos módulos ([11-votaciones.md](11-votaciones.md)).
- Los **intentos de un examen son ilimitados**: `oportunidades` no lo mira nadie.
  Espera decisión, es la que más puede sorprender a un colegio a mitad de
  periodo. No la enciendas.

### Lote G — Los 44 interruptores que en el backend no decide nadie

```bash
# Rutas ABSOLUTAS: desde un worktree, `../myvc_front` apunta a
# `.worktrees/<x>/../myvc_front`, que no existe. Medido esta noche: con dos
# clientes inexistentes la herramienta daba **50** donde la buena da 49 — el
# error da un número MÁS GRANDE, o sea con cara de mejor hallazgo, y el aviso
# salía por `stderr`, que cualquier tubo se traga. Ya aborta.
python3 tools/interruptores-que-nadie-lee.py \
    --clientes /Users/josethguerrero/DESARROLLOS/myvc_front \
               /Users/josethguerrero/DESARROLLOS/myvc_front_2 \
               /Users/josethguerrero/DESARROLLOS/myvc_flutter
```

> **Y no basta con los tres directorios.** `myvc_front` tiene **veinte worktrees**
> en ramas `fase-11/*`, y el control (cinco columnas que sí se leen) aparece en
> **52–54 ficheros por rama frente a 31 en `main`**: mirar solo `main` es una
> muestra incompleta. Un grep de clientes vale lo que valen los ficheros que
> mira, y en un repositorio a mitad de migración **«los ficheros» no son los del
> directorio: son los de todas sus ramas**.

Sin `--clientes` la salida es **una lista de candidatos, no de fallos**: una
columna que aquí no decide nada puede estar decidiendo en cualquiera de los
cuatro clientes, y uno de ellos es una app sola para dieciséis colegios. El
precedente es la §74, donde la respuesta fue justo la contraria a la esperada.

Hay 48 columnas que **ni se nombran** en el backend: si llegan al cliente es por
un `SELECT *`, y esa es la segunda pregunta. Cuidado con la trampa medida:
**un grep de clientes vale lo que valen los ficheros que mira**, y «no lo manda
nadie» es la afirmación más fácil de hacer con una muestra incompleta, porque
nada a la vista la contradice.

Este lote no es dueño de ningún controlador: su entrega es **su documento y sus
tests nuevos**. Lo que haya que arreglar se anota en el tablero para el lote
dueño del fichero.

### Lote H — Los 230 identificadores del cuerpo

```bash
docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas-h.json
python3 tools/identificadores-del-cuerpo.py /tmp/rutas-h.json
```

La [§53](05-codigo-muerto-y-roto.md) sacó de aquí cuatro fallos, y **dos de los
tres primeros ya estaban medidos** — por eso nadie había vuelto. **Medir una ruta
no es haberla juzgado.** El cuarto salió de arreglar la propia herramienta: la
señal buscaba `Autoriza::` y media API comprueba en un helper privado, llamado
`exigirQue…` en un sitio y `exigeQue…` en otro. **El detector también se queda
ciego ante un nombre nuevo**, que es la misma trampa que persigue.

Lo que queda por leer es lo que **no** es alcanzable por una familia: la
herramienta no sabe distinguir un `alumno_id` de un `year_id`. Empieza por los
identificadores que nombran a una persona.

Como G: **lee y reporta**, no edita controladores de otros lotes.

---

## 6. Qué hace quien coordina

1. **Antes de repartir**: correr `tools/worktree-de-sesion.sh` una vez para
   comprobar que el docker está arriba, y mirar que **las migraciones coincidan
   en todas las bases** — el script las imprime.
2. **No reparte a mano.** El reparto es esta tabla; las sesiones se sirven solas.
   Dos coordinadoras chocaron tres veces la primera hora del 21 por hacerlo al
   revés.
3. **Es la única que mueve `main`**, y solo en el árbol raíz. Las fusiones van
   `--no-ff`, y en el mensaje va **qué sesión y qué lote**, porque los seis
   commitean como «Joseth David» y `git log --author` no las distingue: cualquier
   reparto de crédito leído del log es una conjetura.
4. **Funde los tres documentos compartidos en frío al final**: el 05 (secciones
   ya numeradas), el 09 y DESPLIEGUE.md. Si hay que armar el índice a mano, **se
   verifica con un número, no con una presencia**:
   `git diff --cached --stat` tiene que dar **tu** número de líneas.
5. **Comprueba antes de subir de rango lo que le llega.** Quien coordina es quien
   más ensancha las afirmaciones, porque es quien menos toca el código y más
   lejos las manda: una afirmación falsa sobre unas claves ajenas llegó a «dato
   estructural» y a argumento para reabrir una fase entera.
6. **No reabre un acuerdo bilateral cerrado y en ejecución**, ni siquiera
   coordinando. Y **no autoriza**: si algo necesita permiso de Joseth, espera.

---

## 7. Cómo se cierra la noche

En este orden, y el primero no es opcional:

1. **La medición buena se hace una sola vez, al final, con todo fusionado y desde
   el árbol raíz** — y **el sello se imprime al disparar y al terminar**, que es
   la forma operativa de las lecciones 1, 6 y 32: una suite de siete minutos
   corre mientras la rama se mueve, y sin los dos extremos no se sabe **qué**
   midió.
   ```bash
   SHA=$(git rev-parse --short HEAD); echo "sellado al disparar: $SHA"
   docker exec -e DB_TEST_DATABASE=simonbolivar_testing \
       -e COBERTURA_RUTAS=/tmp/tocadas.txt 8myvc-app-1 php artisan test
   echo "commit al terminar: $(git rev-parse --short HEAD)"
   docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas.json
   docker exec 8myvc-app-1 cat /tmp/tocadas.txt > /tmp/tocadas.txt
   python3 tools/cobertura-de-rutas.py /tmp/rutas.json /tmp/tocadas.txt
   ```
   **La suite entera, no solo Contrato**: es la diferencia entre 462 y 461.
2. `composer run pint` y `composer run stan` desde la raíz. Stan tiene que dar
   `[OK]` en el nivel 7 — y si no coincide con lo que daba una sesión, sospecha
   del aislamiento antes que de larastan.
3. Los documentos fundidos, y **lo que se nota en un colegio** escrito en
   DESPLIEGUE.md por lote: es lo único que hace desplegable la tanda.
4. `git worktree remove` de cada árbol. Las bases de test **se dejan**: montarlas
   cuesta y la próxima noche las reutiliza el script.
5. En el 09, la sección de cierre — **el estado real, no el resumen de lo hecho**,
   que es lo que ha servido cada mañana.

---

## 8. Lo que enseñó la noche del 22 al 23 — para la que venga

Todo esto se pagó una vez. Lo que sigue **no son buenas prácticas**: cada línea
tiene detrás media hora perdida, un número publicado mal o una afirmación que se
cayó al mirarla.

### Los números

1. **Sella el commit al disparar, no al escribir el número.** Una medición se
   publicó como de `da48e28` y había corrido sobre `f6770d0`. Se cazó porque una
   sesión comparó **listas de nombres** entre los dos registros y encontró tres
   casos de un fichero que no existía en el commit medido — comparando **totales**
   no se habría visto nunca. **Un número mal etiquetado es peor que un número sin
   etiqueta**: el segundo se vuelve a medir, el primero se cita.
2. **Cada suite mide el commit que tiene debajo, no el que uno tiene en la
   cabeza.** Se le dijo a una sesión que la corrida de otra cubría sus commits;
   era falso, el árbol de la otra estaba en el suyo. Con un árbol por sesión, «la
   suite ya pasó» **nunca** es una afirmación sobre el trabajo de otro.
3. **No mezcles poblaciones.** Suite entera y sólo Contrato dan 462 y 461, 97/97 y
   96/97, 1.276 y 1.272. Cualquier delta entre las dos **inventa mejora**. Estuvo
   a punto de publicarse un «96/97 → 97/97» que era exactamente eso: un mérito
   fabricado por cambiar de población a mitad de la resta.
4. **Antes de publicar una mejora, pregunta cuál de los dos números se movió por
   el trabajo y cuál por la forma de medir.** Es la misma pregunta que la 3, pero
   se hace en el sitio donde no duele: antes de escribir la tabla.
5. **Un número sin su comando al lado es un número que nadie ha vuelto a correr,
   empezando por quien lo escribió.** De los cuatro datos de la cabecera de la
   tanda, el único sin comando —«47 commits»— era el único equivocado, y lo cazó
   quien revisaba precisamente por eso. **El comando no está para el lector de
   dentro de un año: está para que el autor lo verifique al escribirlo.**
6. **Y sella con el sha, nunca con el nombre de la rama.** Ese 47 salió de
   `c2c2a04..main` mientras la etiqueta decía `9492a2b`: entre los dos había un
   commit de documentación que **tocaba un fichero de `app/`** al renumerar una
   sección. `main` se mueve debajo del número mientras se escribe el párrafo — y
   la diferencia fue de uno, que es justo el tamaño que no llama la atención.

### Las afirmaciones

7. **Un apunte sin verificar se convierte en premisa en cuanto se copia a una
   instrucción.** Se repartió un lote diciendo que existía un lector del formato
   `'Y-m-d G:H:i'`; el «lector» estaba dentro de un `/* */`. La sesión gastó su
   primera media hora en desmentirlo. La cadena es siempre la misma: **apunte →
   premisa → media hora**, y se corta en el primer paso, que es el barato.
8. **Cuando una comprobación se salta, no se salta al azar: se salta hacia la
   respuesta que da menos trabajo.** Cinco comprobaciones se cayeron al mirarlas
   de cerca y **las cinco fallaban en esa dirección**; ninguna falló hacia la
   duda. La más cara habría dicho que `alumnos/cambiar-claves` estaba defendida
   —el `Autoriza::` era de otro método más abajo— y habría **encogido la pregunta
   de otro lote con un dato falso, y con la autoridad de venir medido**.
9. **Quien coordina es quien más ensancha una afirmación**, porque es quien menos
   toca el código y más lejos la manda. Ya estaba escrito en el §6.5 y volvió a
   pasar: **quien escribe la regla se la salta en el sitio donde no está
   mirando.**
10. **Cuando el tablero y `main` discrepan, gana `main`.** Se le dijo a una sesión
    que un lote no había cerrado, leyéndolo de un tablero que quien coordina nunca
    marcó. El tablero es un apunte; el repositorio es el hecho.

### Los instrumentos

11. **Mirar el padre y concluir sobre el hijo da una respuesta coherente y falsa.**
    `php artisan test` es un envoltorio: el proceso que corre es
    `vendor/phpunit/phpunit/phpunit`. Leyendo el `--configuration` del **padre** se
    dio una falsa alarma sobre una medición que estaba bien.
12. **Y matar al padre deja huérfano al hijo.** Un `pkill` sobre `artisan test`
    dejó dos phpunit con `ppid=1` **escribiendo en las bases de otros dos lotes
    durante 31 minutos**. Se identificaron por `/proc/<pid>/environ`. Para matar
    de verdad la suite de un árbol:
    ```bash
    pkill -f "phpunit.*worktrees/<sufijo>"
    ```
13. **Tres suites a la vez como mucho.** Por encima de eso el contenedor se
    arrastra y los tests de contrato empiezan a caer por *deadlock* en el `insert`
    de `personal_access_tokens` —que lo hacen todos, porque todos inician
    sesión—. **Lo que se ve no es un error de infraestructura: son tests en rojo
    con mensajes creíbles**, y se diagnostica corriendo un control en el árbol
    raíz y comparando las tablas de las dos bases, no leyendo el controlador.
    Cuando el contenedor está cargado, se reparten **lotes de leer y reportar**,
    que no necesitan suite.
14. **Una comprobación no vale más que su patrón, y el patrón se mide contra uno
    más ancho — no se lee.** Los dos errores existen y no cuestan lo mismo: **un
    patrón estrecho declara limpio lo que no ha mirado; uno ancho declara
    cobertura que no existe.** El primero se nota en cuanto alguien mete la mano;
    **el segundo no se nota nunca, porque va a favor de lo que uno quería creer.**
    Los dos de esta noche fueron anchos y los dos dieron **de más**: el conteo de
    secciones dio 75 donde había 74 —los subapartados `§112.1` contados como
    secciones nuevas—, y `interruptores-que-nadie-lee.py` con rutas relativas dio
    **50 donde la buena da 49**, o sea con cara de mejor hallazgo. **Un patrón
    ancho no falla en proporción a lo ancho que sea: falla donde el dato no lo
    tapa**, y por eso el número que da es siempre creíble.

### La numeración compartida

15. **Arreglar una colisión crea la siguiente si el número nuevo se elige sin
    volver a correr la comprobación.** Hubo tres colisiones y **la tercera la
    creó el arreglo de la segunda**, en el número de al lado. La comprobación va
    **después de cada renumerado**, no sólo al final: el renumerado es justo el
    momento en que se inventa un número.
16. **Y el título se queda viejo cada vez.** De las tres, dos fueron un título
    que declaraba un rango que su cuerpo ya no tenía —**las dos en el mismo
    fichero**— y una cuarta declaraba de menos, escondiendo una sección de quien
    la buscara por el título. Al renumerar hay que tocar **el cuerpo, el título y
    el índice**, y comprobar los tres.
17. **La comprobación de colisiones discrimina por la posición del `§`**: un
    título que **abre** con él (tras su numeración) **declara**; un `§` **dentro
    de la frase** **referencia**. Con el criterio mal puesto salían 65 o 73 donde
    había 75, y las dos cifras parecían razonables.

### El reparto

18. **Deja una franja para releer lo escrito.** Casi todo lo de esta lista se
    encontró releyendo, no midiendo — y se encontró tarde porque no había hueco
    para ello. La cola creció de ocho lotes a veinte; el registro real de quién
    hizo qué está en
    [`noche-2026-08-23/README.md`](noche-2026-08-23/README.md), y las secciones
    que salieron, en el 05 §81–§167.
19. **Que revise el cierre alguien que no lo produjo.** La medición final la
    corrió quien coordina y la comprobó una sesión que no había escrito ninguno
    de los documentos que revisaba. Las cuatro pruebas que valió la pena pasarle a
    cada documento acabado: **numeración, aritmética, poblaciones y expresiones
    que envejecen** — un «anoche» en un documento que acumula noches no se puede
    resolver, y un «hoy» que describe el estado del código sí, porque deja de ser
    cierto cuando el código cambia.
20. **Cualquier afirmación cuyo sujeto sea el momento de medirla lleva fecha de
    caducidad y no la lleva escrita.** El caso puro de esta noche: «*§138–142 no
    son huecos: son los lotes que seguían abiertos al hacer esta comprobación*».
    Cerraron, y la frase se volvió falsa **manteniendo el mismo aspecto de
    verdad** — un lector de diciembre no tiene forma de saber que hablaba de un
    martes. **No se marchitan como un «hoy»: se quedan igual de bien escritas.**
    Se buscan por su propia forma: «al hacer esto», «por ahora», «los que siguen
    abiertos».
21. **Y una corrección a la 19, que si no se lee como una técnica y no lo es:**
    mandar el comando dentro de la corrección **no desarma a nadie** — sólo
    desarma a quien piensa correrlo. La misma corrección, con el mismo comando
    dentro, se contestó esa noche explicando por qué el número seguía siendo bueno
    **sin haberlo ejecutado**. Lo que hace que la revisión externa sirva no es el
    comando: es que **quien lo recibe lo corra**. Contra un coordinador que
    discute el dato en vez de correr el comando, revisar es teatro.
22. **Dos mediciones independientes que coinciden no se han comprobado la una a
    la otra si comparten el punto ciego.** El recuento de secciones dio 75, se
    «corrigió» a 74 y lo confirmó una segunda sesión con su propio script — y **el
    bueno era el 75**. Las dos regex leían el primer número del encabezado, y hay
    uno que declara **dos**: `## §125–126 — Lo medido…`. La coincidencia no era
    acuerdo: era **el mismo agujero visto dos veces**. Y no se quedó en el número
    —publicó el §126 como «hueco que nadie usó» **existiendo**. Al cerrarlo, los
    dos parsers volvieron a dar 76 por caminos distintos, y **eso vale más pero
    tampoco vale del todo**: los dos comparten ya la discriminación
    raya-corta/raya-larga, así que si esa fuera falsa volveríamos a coincidir en
    el número malo. **Lo que sostiene el 76 es la medición del corpus —93 a 0—,
    no que dos programas digan lo mismo.**
23. **Los comentarios del código citan secciones de `docs/` por su número, y
    renumerar deja atrás las citas.** 1.251 citas a 227 secciones, medido el 23 de
    agosto de 2026. Tiene dos agravantes que una colisión dentro de un documento
    no tiene: **nadie lee un comentario para comprobar si su número sigue siendo
    el bueno**, y **no se rompe, miente** — un `// §144` desalineado sigue
    apuntando a una sección que existe y manda a leer sobre otro asunto. Lo
    comprueba `tools/secciones-citadas.py`, y va **después de cada renumerado**,
    al lado de la de colisiones.
24. **Y prueba el comprobador antes de fiarte de su cero.** El primer patrón de
    esa herramienta daba **75 huérfanas y las 75 eran falsas** —no veía los
    encabezados numerados sin `§`, que son los del 05 anterior a esta noche—; con
    los dos patrones da cero. Un cero solo significa algo si la herramienta
    enseña que **falla cuando hay algo que encontrar**: se le pone delante un
    `§999` inventado y se mira que salga.
25. **Y lo que hace caro a un error de medición no es su tamaño: es su
    verosimilitud.** El mismo bug de patrón dio dos números esa noche. Uno dio
    **415 secciones** y murió en la corrida en que nació: nadie se cree 415. El
    otro dio **75**, sobrevivió una noche entera, se publicó, se «corrigió» a 74 y
    lo confirmaron dos sesiones desde dos lados. **Un 415 se defiende solo; un 75
    hay que ir a buscarlo.** Cuando un número sale plausible a la primera es
    cuando más falta hace correr el comando otra vez.
26. **Un patrón ancho y uno estrecho son dos maneras de equivocarse mientras no
    reconozcan la estructura del sitio donde buscan.** Las dos regex de esa noche
    leían **la línea**; un encabezado no es una línea, es **un identificador y un
    título separados por un guion largo con espacios** — y el guion de un rango va
    pegado (`§125–126`). Partir por ahí y buscar solo en el identificador arregla
    las dos a la vez. **Calibrar el ancho no era la salida: era elegir en qué
    dirección fallar.**
27. **Una herramienta que cruza dos poblaciones tiene dos lados que pueden
    fallar, y meter algo falso por uno no dice nada del otro.** El `§999` que
    probó `secciones-citadas.py` entra por el lado de **las citas**; el bug estaba
    en el de **las declaraciones**. Probé la alarma y el roto estaba en el mapa —y
    peor: el fallo del mapa era *declarar de más*, o sea justo el que hace que la
    alarma **calle**. **Se inyecta por el lado que produce el silencio**, y se
    comprueba **cuánto** aporta cada inyección, no que no reviente: «no revienta»
    no distingue 1 de 261. Queda puesto en `--autoprueba`.
28. **Estrechar también es una respuesta, y se justifica igual: por la población
    medida.** El rango de una sección se escribe en este repo con la raya corta
    pegada — **93 casos, y 0 con la raya larga o el guion ASCII**—, así que
    aceptar los otros dos no compra un solo caso real y cuesta 261 secciones
    fantasma. Es la única vez de la noche en que la salida fue quitar, y no se
    decidió por intuición sino contando: **93 a 0.**
29. **Y el bucle que produjo la mitad de esta lista no era «releer»: era
    «responder».** Diez de estas lecciones salieron después de dar la noche por
    cerrada dos veces, y **ninguna salió de mirar el propio trabajo otra vez**:
    salieron de mandarle a otra sesión un número con su comando dentro y de que
    esa sesión lo corriera. No se replica poniendo una franja al final del plan.
    Se replica poniendo **dos**, y es más caro.
30. **Arreglar el caso no es cerrar la clase, y se nota en si el fallo puede
    volver a entrar por la misma puerta.** `secciones-citadas.py` se denunció a sí
    misma dos veces —el ejemplo de su cabecera y las trampas de su propia
    autoprueba, ocho huérfanas propias impresas debajo de un `0`—. Escribir los
    ejemplos con una variable arreglaba **hoy**; el fichero seguía dentro de la
    población que lee, así que **cualquier ejemplo futuro con el símbolo entero
    volvía a ser una cita**. La clase se cierra excluyéndose de su propia
    población — y **diciendo cuántas se dejan fuera**, porque una exclusión
    silenciosa se lee como «aquí no había nada».
31. **Una defensa que no se ha visto fallar no se sabe si defiende.** Los dos
    guardas de ese patrón parecían redundantes; **quitándolos de uno en uno** se ve
    que fallan cosas distintas —sin el `split`, las dos formas con espacios; con la
    raya ancha, las dos pegadas—. Es la comprobación inversa de siempre, la que se
    le hace a un test para saber si mide algo, aplicada a las mitades de un
    patrón. Y el día que una sobre, se sabrá igual: quitándola.
32. **Una diferencia entre dos estados solo mide lo que crees si lo demás no se
    movió** — y cuando se puede contar directo, se cuenta directo. Al comprobar
    cuántas citas aporta un fichero, restar entre dos commits daba **16** y contar
    sobre el fichero daba **17**: entre los dos commits había cambiado **el propio
    fichero que se estaba contando**. Es la lección 2 en pequeño y más traicionera,
    porque **una resta es una medición de dos cosas** y basta con que se mueva una
    para que el resultado siga teniendo la pinta exacta de la respuesta.

### Y lo único que hay que llevarse si no se lee el resto

Las treinta y dos de arriba no se cumplen por sabérselas. **La del sello la
escribimos las dos sesiones, la citamos las dos, y la incumplimos las dos** — la
última vez en la cabecera de la herramienta que existe justo para eso, que
envejeció **dos veces en una sola noche**.

Y ninguna de las treinta y dos salió de mirar mejor:

> **Todas salieron de que un número tuviera que salir de un sitio hacia otro.** El
> único momento en que este trabajo se comprueba de verdad es **cuando cruza una
> frontera** — cuando alguien que no lo produjo tiene delante el número, el
> comando al lado, y la costumbre de correrlo antes de contestar.

Por eso el reparto de la noche no es solo una forma de ir más rápido: **es el
único mecanismo que hizo que estas treinta y dos aparecieran.** Cuatro veces se
dio la noche por cerrada y las cuatro había algo debajo; las cuatro lo destapó un
mensaje, no una relectura.

Corolario operativo, que es lo que hay que montar la próxima vez: **una sesión que
trabaja sola no tiene frontera que cruzar, así que sus números no se comprueban.**
Si alguna vez hay una sola, que la última hora sea escribirle el cierre a alguien
—aunque ese alguien sea el documento— **con el comando al lado de cada número**.
Lo que no puede pasar es que el último que mire el número sea el que lo escribió.

---

## Una suite verde no corresponde a ningún commit si el árbol es compartido

Lo señaló `8myvc-d0` el 24 ago 2026, después de darme una corrida limpia y
**negarse a decir que cubría mi último commit**. La precisión que puso es la
regla:

> **PHPUnit mide lo que cada fichero era en el instante en que lo cargó**, no un
> commit. Doce minutos de suite en un árbol compartido son doce minutos durante
> los cuales otra sesión puede commitear código. Si lo hace, **el «verde» no
> corresponde a ningún estado que exista en git** — ni al de antes ni al de
> después.

Ese día no mordió, y la comprobación que lo demostró es la que hay que copiar. La
corrida arrancó sobre `c99cf6e` y terminó con `HEAD` en `c37ba82`. En vez de decir
«corrida sobre `c37ba82`» —que habría sido falso— ni «sólo vale para `c99cf6e`»
—que habría sido inútil—, se comprobó lo que sí se podía afirmar:

```bash
git log c99cf6e..HEAD --name-only -- app/ routes/ config/ tests/   # sale vacío
git status --short                                                 # limpio
```

**Los commits intermedios sólo tocaban documentación, así que el árbol ejecutable
de las dos puntas es el mismo.** Con eso la corrida vale para las dos, y se puede
citar diciendo **eso** y no «se corrió sobre X».

**La regla, entonces:**

1. **Corre la suite en tu propia base** (`DB_TEST_DATABASE=…_b`): dos suites
   contra la misma base dan deadlocks, y eso ya está arriba.

   > **Y la segunda suite puede ser la TUYA de hace un rato.** La regla se lee como
   > «que no la lance nadie más», y le falta la otra mitad: **matar el `docker exec`
   > desde fuera no mata el `php` de dentro del contenedor**. Una corrida que se
   > corta por el tope de tiempo de la orden **sigue viva**, y se solapa con la
   > siguiente. Pasó el 2 sep 2026 al fusionar: **30 rojos, 88 deadlocks y `exit=0`**,
   > y no era ninguna regresión —sola dio 1859 en verde—. **La señal que lo delató no
   > fueron los rojos: fue la duración**, 1247 s contra los ~670 s de siempre. Un
   > número que no cuadra antes de mirar el contenido, otra vez.
   >
   > Así que antes de relanzar, comprueba que la anterior murió de verdad:
   >
   > ```bash
   > docker exec 8myvc-app-1 sh -c 'pgrep -af "[a]rtisan test"'
   > ```
   >
   > **Los corchetes no son adorno**: con `"artisan test"` a secas el patrón **se
   > encuentra a sí mismo** —el `sh -c` que lo lanza lleva esas palabras en su propia
   > línea de órdenes— y contesta que sí siempre, incluso con el contenedor parado.
   > Comprobado en las dos direcciones el 2 sep 2026: sin nada corriendo, vacío; con
   > un proceso que las lleva dentro, lo nombra.
   >
   > **Y cópialo TAL CUAL: no le pongas nada detrás en la misma línea.** Los corchetes
   > protegen el patrón, **no la orden**. `pgrep -f` mira la línea de órdenes entera del
   > `sh -c`, así que un mensaje de respaldo que contenga la palabra buscada la
   > reintroduce y vuelve el falso positivo por la otra puerta. Lo encontró `8myvc-23`
   > el 2 sep 2026 usando este mismo truco para otra cosa, y está reproducido:
   >
   > ```bash
   > # MAL — se encuentra a sí mismo por el `echo`, no por el patrón:
   > pgrep -af "[p]hpstan|[p]int" || echo "ni pint ni stan corriendo"
   > #   -> 43699 sh -c pgrep -af "[p]hpstan|[p]int" || echo "ni pint ni stan corriendo"
   >
   > # BIEN — el mismo patrón, sin nada detrás:
   > pgrep -af "[p]hpstan|[p]int"     # exit 1 = ninguno
   > ```
   >
   > Aquí el falso positivo era inofensivo. En **este** comando sería peor: le diría
   > *«tu suite anterior sigue viva»* a quien sólo quiere relanzar, que es exactamente
   > el daño que viene a evitar.
   >
   > **Son ya tres puertas distintas al mismo fallo**, las tres de la noche del 2 sep y
   > las tres dando un resultado plausible sin ningún error visible: el **patrón que
   > enumera lo que imaginabas** (las cabeceras de `ESTADO-ACTUAL.md`: 13, 15 y 16 según
   > quién contara, porque cada uno listó los prefijos que conocía), la **salida que
   > truncas** con un `| head` (las coincidencias iban en las posiciones 18 y 19 de 19),
   > y ahora la **orden que rodea al patrón**. La regla que las cubre a las tres:
   > *comprobar que el detector detecta lo que dice su nombre es un paso aparte, y no se
   > salta porque el detector sea de una línea.*
   >
   > Y mira la duración al terminar. **Una suite que tarda el doble no es una suite
   > lenta: son dos.**

2. **Anota la punta al arrancar y al terminar.** Si son la misma, no hay nada que
   pensar.
3. **Si no lo son, comprueba qué cambió en medio** con el `git log --name-only`
   acotado a los cuatro caminos ejecutables. Vacío ⇒ la corrida vale para las dos
   puntas.
4. **Si en medio entró código, la corrida no vale para ninguna punta.** Repítela.

Y el motivo por el que esto va aquí y no en el [03](03-tests.md): **no es una
regla de tests, es una de coordinación.** El test no tiene ningún problema; lo
tiene la frase «la suite está verde» cuando hay dos manos en el mismo árbol.

Es la misma familia que las tres formas de medir mal del
[02](02-plan-rendimiento.md) y de la [§142](noche-2026-08-23/r.md): **el número es
honesto y la afirmación que se cuelga de él no lo es.**

---

## Todas las herramientas de git son del árbol, y ninguna es de la sesión

La frase es de `8myvc-f0`, la noche del 2 al 3 de sep 2026, y explica de golpe
**cuatro accidentes distintos** que esa noche tuvieron cinco sesiones de `8myvc`
escribiendo en el mismo árbol. La sección de arriba dice que **una suite** no sabe
de sesiones; ésta dice que **tampoco lo sabe ninguna otra orden de git**, y ahí es
donde se pierde trabajo en vez de sólo medirlo mal.

| gesto | se lleva | ¿avisa? | ¿destruye? |
|---|---|---|---|
| `git add -A` | ficheros ajenos, dentro de tu commit | **sí**, en el `--stat` | no |
| `git diff` pelado | trabajo ajeno, dentro de tu parche de respaldo | no | no |
| `git switch -c` | **la rama de todos** — y tu commit siguiente aterriza en la ajena | no | no |
| `git checkout -- <fichero>` | **el trabajo ajeno** | **no** | **sí** |

**Los tres primeros se arreglan nombrando rutas. El cuarto no**, y ésa es toda la
gracia: `git checkout -- docs/migracion/ESTADO-ACTUAL.md` **ya lleva la ruta
nombrada**, y la ruta es del **árbol**, no de quien escribió. Se llevó **~49
líneas** de `8myvc-ee` de una sesión que ni sabía que existía, y contestó con
silencio y un árbol limpio. Se vio **sólo** porque al preparar el commit el
`--stat` daba **103 insertions donde se habían medido 54**, y alguien fue a mirar
por qué en vez de seguir.

**La regla que sale de ahí**: *antes de revertir un fichero compartido, mirar el
diff entero — no basta con confirmar que hay cambios tuyos dentro.* `git status`
dijo ` M`, que es verdad, y no dice de quién.

### Y la vuelta que hay que entender, porque la precaución obvia empeora las cosas

`docs/migracion/28-competencias-e-indicadores.md` **sobrevivió a ese `checkout`
por una propiedad que nadie eligió: estaba sin trackear.** Lo levantó `8myvc-ee`.
Si alguien lo hubiera hecho `git add` para no perderlo —que es exactamente la
precaución razonable— habría entrado en el radio del `checkout` y se habría
destruido igual que el otro.

**Y la misma propiedad, dos horas después, hizo el daño contrario**: al commitear,
el `git add` de `f0` se llevó el fichero **entero** —1016 líneas—, así que
`03f8175` lleva dentro la §1.ter y la Entrega 6 de `8myvc-64` y el candado de
`8myvc-ee` **firmadas por quien no las escribió**, después de que esa misma sesión
hubiera sacado `ESTADO-ACTUAL.md` de su commit precisamente para no firmar texto
ajeno. **La regla se rompió en la dirección contraria a la que temía.**

Estar sin trackear protegió el fichero de una orden y lo entregó entero a la
siguiente. No hay un lado bueno: **lo que hay es un árbol compartido.**

### Qué se hace, en orden de lo que más ahorra

1. **Un worktree por sesión, y no es higiene: es lo único que permite que dos
   sesiones commiteen el mismo día.** `tools/worktree-de-sesion.sh <x> <rama>`.
   Un worktree no comparte `HEAD`, así que el `switch` de la tabla deja de existir
   y los otros tres se quedan dentro de tu árbol.
2. **Nombra las rutas en `add`, en `diff` y en el respaldo.** Nunca `-A` ni un
   `git diff` pelado en un árbol compartido.
3. **Antes de revertir, lee el diff entero.**
4. **Si aun así trabajas en el árbol común** —que a veces es lo correcto: la
   sesión que está con Joseth en directo trabaja donde él mira—, **avisa a quien
   coordina de qué ficheros tocas**, y que nadie revierta un fichero tuyo sin
   pedírtelo.

### El delator fue el mismo las cuatro veces

**Un número que no cuadraba con otro medido antes**: cinco ficheros en un `--stat`
donde se esperaban cuatro; 103 insertions donde se habían medido 54; 1290 líneas
en un fichero que se había commiteado con 1016; y —de la sección de arriba— 1247 s
de suite donde siempre son 670.

Ninguno de los cuatro se vio mirando el contenido. **Los cuatro se vieron porque
alguien tenía una cifra anterior y la comparó.** Ésa es la razón operativa de que
en este repositorio se escriba la población al lado de cada medición: no es
rigor, es que **la cifra vieja es el único detector que tenemos** para la familia
de fallos que no da error y produce un resultado creíble.

### Y una que no es de git y salió la misma noche

**Relayar la medición de otra sesión sin rehacerla.** Quien coordina es por donde
pasan todas las cifras, así que es justo el sitio donde una medición ajena se
convierte en un hecho sin que nadie la haya repetido. Esa noche pasó dos veces en
la misma conversación —«tu trabajo no está commiteado» y «tiene tres entregas»,
las dos falsas y las dos repetidas de buena fe— y las dos las corrigió **la
sesión a la que se le estaba contando su propio trabajo**, que es el único control
que quedaba.

---

## El estado de la copia local, que no está en git y ninguna sesión hereda

La base `simonbolivar` del docker **es una copia de producción y varias sesiones
escriben en ella**. Lo que se le haya hecho no viaja en el repositorio, así que si
no está escrito aquí, la sesión siguiente no lo sabe. Esto es lo que se le hizo el
**24 ago 2026** y sigue puesto.

### Cuatro cuentas con contraseña de prueba

Para poder **entrar en el navegador con cada rol**, que hasta ese día no se podía:
la única contraseña conocida era la de `administrador`, y **el administrador puede
verlo todo, así que ninguna comprobación hecha con él distingue «correcto» de
«abierto»**. En la primera vuelta con roles reales salieron cuatro fallos en una
tarde, uno de ellos la fuga de la [09 §10](09-pendientes.md).

| id | usuario | tipo | |
|---|---|---|---|
| 2343 | `DANIEL1` | Profesor | |
| 675 | `carolina` | Profesor | la de la asignatura 1233, que es donde están las notas con historial |
| 2400 | `JuanEsteban2` | Alumno | matrícula viva en «Noveno», paz y salvo |
| 1562 | `39428524` | Acudiente | dos acudidos matriculados; **se le movió `periodo_id` a 31** |

**Contraseña: `verificar2026`.** Son cuentas de **personas reales** en una copia de
producción: no salen de la máquina.

> **Al acudiente hubo que moverle el periodo a mano** y conviene saber por qué:
> `years/useractive` lleva `auth.personal`, y `ExigirPersonal` declara
> `FUERA = ['Alumno','Acudiente']`, así que **no puede cambiarse el año él mismo**.
> El login se lo repara solo al entrar ([09 §8](09-pendientes.md)), pero para
> montar la sesión desde fuera hay que ponerlo.

**Los hashes originales están en `~/.myvc-local/hashes-originales.txt`**, fuera del
repositorio a propósito —son hashes de cuentas reales—. Para dejar la base como
estaba, se reponen desde ahí. Se sacaron del scratchpad de la sesión, que se borra.

### Once bitácoras borradas, y por qué se borraron

Una sesión del front pulsó en la planilla sin comprobar una precondición y disparó
**doce `PUT notas/update`**. Ninguna nota cambió de valor, pero quedaron **once
filas nuevas en `bitacoras`**. Se borraron —copia en
`~/.myvc-local/bitacoras-borradas.txt`— porque distorsionaban una medida que se
estaba citando: en esa base hay **4 filas de bitácora de tipo `Nota`**, y con las
once eran 15.

**Lo que NO se revirtió, y el motivo importa:** las once notas conservan el
`updated_at`/`updated_by` movidos, y hay **una definitiva reescrita** con el mismo
valor. Restaurar un `updated_at` exigiría saber el anterior, **y se perdió al
sobrescribirlo**. Uno movido es ruido; **uno inventado es un dato falso que parece
bueno.**

> Y la regla que salió de ahí, que vale para cualquier sesión que verifique en el
> navegador: **una guarda que se comprueba y no se obedece no es una guarda.** El
> guion buscó el interruptor, obtuvo «0 encontrados» **y siguió pulsando**.
> Comprobarlo así es peor que no comprobarlo: deja constancia de que se sabía.

### Bases de tests que se usaron

Además de `simonbolivar_testing`, están creadas de la `_a` a la `_t` y la `_z`. El
24 ago se usó la **`_z`** para poder correr tests mientras otra sesión tenía la
suite entera en la `_b`. **Dos suites contra la misma base dan deadlocks**, y eso
ya está arriba; lo que hay que recordar es que **las letras están libres y son
gratis**.
