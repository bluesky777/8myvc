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
| **Colas para importadores e informes** ([09 §3](09-pendientes.md)) | Cambia el contrato de los cuatro clientes, y uno es la app de Flutter, **una sola para los dieciséis colegios**: no se puede escalonar |
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
local, y no en `.gitignore`, que se copia a los dieciséis colegios. Hecho el 22
de agosto de 2026. Lo encontró la sesión del lote D mirando su `git status`, que
es lo que nadie hace cuando el documento ya afirma que sale limpio.

### `vendor/` no se puede enlazar, y eso costó encontrarlo

Lo primero que se prueba es un symlink a `vendor/`, que es exactamente lo que
hace el despliegue con los dieciséis colegios (CLAUDE.md). **Aquí miente.**
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
    TABLERO.md      solo lo escribe quien coordina
    a.md  b.md ...  uno por sesión; cada una escribe SOLO el suyo
```

Una línea por cosa: hora, qué coges, qué sueltas. Si añades una migración,
**va aquí en mayúsculas**: las bases de las demás se quedan viejas y lo que verán
son tests de contrato en rojo con mensajes creíbles.

---

## 5. La cola

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
   el árbol raíz.** Con `main` ya cerrado:
   ```bash
   docker exec -e DB_TEST_DATABASE=simonbolivar_testing \
       -e COBERTURA_RUTAS=/tmp/tocadas.txt 8myvc-app-1 php artisan test
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
