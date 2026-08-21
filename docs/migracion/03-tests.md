# Tests de contrato — cómo se usan

La red de seguridad de la migración. No comprueban que el código esté bien:
comprueban que **la respuesta no ha cambiado**. Su único trabajo es gritar
cuando la migración altera algo que el frontend está leyendo.

## Correrlos

```bash
# Una vez, para construir la base de tests
tools/construir-bd-test.sh

# Cada vez
docker exec 8myvc-app-1 php artisan test
docker exec 8myvc-app-1 php artisan test --testsuite=Contrato   # solo los de contrato
```

La base **no** se reconstruye entre tests: cada test corre dentro de una
transacción que se deshace al terminar. Solo hace falta reconstruirla si cambia
el esquema o el seed.

> **Si alguna vez ejecutas `php artisan config:cache` en local, bórralo antes de
> correr los tests** (`php artisan config:clear`). El config cacheado congela el
> `.env`, así que `phpunit.xml` deja de poder apuntar a la base de tests. No hay
> daño —`CasoDeContrato` aborta al ver que la base no acaba en `_testing`— pero
> el mensaje despista. Con `route:cache` no pasa: la suite pasa entera.

## Qué falta por cubrir, medido

La pregunta «¿qué rutas no mira nadie?» no se puede contestar leyendo los tests:
las URLs se construyen interpolando —`"api/boletines/detailed-notas/{$grupo}"`—
así que buscarlas con grep pierde justo las que llevan parámetro. Se mide
ejecutando:

```bash
docker exec 8myvc-app-1 rm -f /tmp/tocadas.txt
docker exec -e COBERTURA_RUTAS=/tmp/tocadas.txt 8myvc-app-1 php artisan test
docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas.json
docker exec 8myvc-app-1 cat /tmp/tocadas.txt > /tmp/tocadas.txt   # sacarlo del contenedor
python3 tools/cobertura-de-rutas.py /tmp/rutas.json /tmp/tocadas.txt
```

El registrador vive en `tests/TestCase.php` y solo se enciende con la variable
puesta; una corrida normal no escribe nada.

> **Una corrida cada vez.** Cada test se aísla dentro de una transacción, y una
> transacción aísla de las otras conexiones **de esa misma corrida**. Dos
> `php artisan test` a la vez sobre la misma base —por ejemplo, la medición de
> cobertura de arriba en segundo plano mientras se prueba un fichero suelto— se
> pisan: aparecen fallos que no se reproducen después y que no están en el
> código. Costó un cuarto de hora el 20 de agosto de 2026.

**Lo que hace útil al informe es separar «ejecutada» de «comprobada».** Medido a
secas, el 99% de las rutas se ejecutan: `AutenticacionTest` y `RutasPreLoginTest`
las hacen pasar a las 539 por el router para sus snapshots de guards, y eso no
mira lo que devuelven. Descontando esos barridos, la cuenta real era **96 de 539
el 20 de agosto de 2026**, antes de la P2. Después, 208.

Un barrido se reconoce por tocar más de 25 rutas **en una sola ejecución**, no
por clase: un test parametrizado que mira 66 respuestas de una en una toca una en
cada ejecución y 66 entre todas, y con el criterio por clase se descartaría a sí
mismo. Pasó con `MuestreoDeLecturasTest` en cuanto se escribió.

## Y qué consultas hace, que es otra pregunta

La misma corrida sirve para lo que necesita el plan de rendimiento. Con
`EXPLICAR_CONSULTAS` puesta, `tests/TestCase.php` anota cada consulta distinta
que la suite ejecuta —493 en la corrida del 20 de agosto—, y
`tools/indices-que-faltan.php` les pasa `EXPLAIN` y se queda con las que
recorren una tabla sin que exista ningún índice aplicable:

```bash
docker exec 8myvc-app-1 rm -f /tmp/consultas.jsonl
docker exec -e EXPLICAR_CONSULTAS=/tmp/consultas.jsonl 8myvc-app-1 \
    php artisan test --testsuite=Contrato
docker exec 8myvc-app-1 php tools/indices-que-faltan.php /tmp/consultas.jsonl
```

**Vale medirlo contra el seed pequeño, y esa es la parte que no es obvia.** Lo
que se busca es `possible_keys` vacío: que para esa consulta no exista un índice
que MySQL pudiera considerar. El optimizador decide eso mirando el WHERE, antes
de contar filas — es una propiedad del esquema. Con el seed se ve el mismo hecho
que en un colegio con un millón de notas; lo que cambia es cuánto cuesta el
escaneo, no si el índice está.

Lo que esto **no** contesta es cuáles de esos merecen el índice. Eso lo dice el
registro de consultas lentas de producción (`CONSULTAS_LENTAS_MS`) leído con
`tools/consultas-lentas.py`, y por eso los tres índices que se pusieron llevan
escrito al lado por qué entraron ellos y no los otros trece.

Los índices puestos los vigila `tests/Contrato/IndicesTest.php`, que no comprueba
que el índice exista —eso ya lo dice `migrate`— sino que **para estas consultas
hay un índice aplicable**, preguntándoselo a MySQL. Sobrevive a que alguien
reordene un WHERE; «hay un índice llamado así» no.

## La trampa del proceso compartido: una ruta, dos identidades

**`Illuminate\Routing\Route::getController()` guarda la instancia del
controlador dentro de la ruta**, y el router es un singleton que sobrevive a
todas las peticiones del proceso. En una petición HTTP de verdad da igual —cPanel
corre php-fpm, un proceso por petición— pero dentro de un test method todas las
llamadas comparten proceso.

O sea que **golpear la misma ruta con dos tokens distintos reutiliza la misma
instancia del controlador**, y con ella el `$this->user` que el trait
`ResuelveElUsuario` memorizó la primera vez. La segunda llamada se ejecuta con la
identidad de la primera.

Lo que eso rompe casi nunca es que un test falle: es que **pase por la razón
equivocada**. El patrón de media suite —«un alumno no puede; un acudiente
tampoco»— sobre una ruta cuyo permiso se comprueba **dentro del controlador**
comprueba dos veces al alumno y nunca al acudiente. Cuando el permiso lo pone un
middleware (`auth.personal`) no pasa, porque el middleware lee el token de la
petición y no del controlador; por eso no se había notado.

Se descubrió al revés, el 20 de agosto de 2026: un superusuario recibiendo el 403
del profesor de la línea anterior, con el token bueno y el contexto bien resuelto.

**Está cerrado y no hace falta acordarse de nada**: `CasoDeContrato` sobreescribe
`withToken()` para soltar la instancia que cada ruta guarda. Se puso encima de
`withToken()` y no en un método aparte a propósito, porque la disciplina es justo
lo que falló. Al activarlo no se puso en rojo ningún test existente —ninguno
cambiaba de resultado— pero desde entonces la segunda identidad se ejercita de
verdad.

**Y fuera de los tests esto tiene una fecha de caducidad.** Bajo un runtime
persistente —Octane, FrankenPHP— los procesos se reutilizan entre peticiones de
usuarios distintos, y entonces esto deja de ser cosa de los tests para ser una
fuga de identidad entre personas. Está anotado en la nota sobre Octane del plan.

## Las tres piezas

| Fichero | Qué es |
|---|---|
| `database/schema/mysql-schema.sql` | El esquema real congelado. 90 tablas. |
| `database/dumps/test-seed.sql` | Los datos: rebanada anonimizada de DOS años, 47 tablas, ~32.800 filas. |
| `tests/Contrato/Snapshots/*.json` | La forma esperada de cada respuesta. |

Los tests de imagen se mudan a una carpeta temporal antes de correr, y vuelven al
directorio anterior al terminar. No es manía: los controladores de imagen
escriben en `images/perfil/...`, una ruta **relativa**, así que el destino real
depende del directorio de trabajo del proceso —`public/` sirviendo por HTTP, la
raíz del repo por CLI—. De ahí sale el `images/perfil/` vacío con carpetas
`user_*` que hay en la raíz sin que nadie lo creara.

Contraseña de todos los usuarios del seed: `test-1234`.

## Cómo funcionan los snapshots

Se guarda la **forma** de la respuesta —qué claves trae y de qué tipo es cada
valor—, no los valores.

```json
{ "el_token": "string", "cambia_anio": "int" }
```

Guardar los valores haría fallar el test porque avanzó un id o cambió una fecha.
Lo que el frontend consume es la forma, y es lo que no puede cambiar.

**La primera vez que un test corre, su snapshot no existe y se crea solo.**
El test avisa por STDERR. Un snapshot recién creado no ha verificado nada:
solo ha registrado el comportamiento de hoy. Hay que leerla antes de fiarse.

**Cuando un test falla porque la forma cambió**, hay dos casos:

1. *No era intencionado* → es una regresión. Ese es el trabajo del test.
2. *Sí lo era* → borra el `.json` y vuelve a correr. El diff del fichero
   regenerado es lo que hay que enseñar a quien lleve `myvc_front`.

## Regenerar el seed

Se genera desde la base real, así que solo se puede hacer en local:

```bash
docker exec 8myvc-app-1 php tools/generar-seed-test.php [year_id] [grupo_id]
```

Por defecto se ancla en **dos años**: el grupo 84 (Tercero 2024, 56 alumnos) y
el 98 (Cuarto 2025, los mismos 56 más 12). Sigue el grafo de claves foráneas
hacia fuera, así que lo que entra tiene sus referencias dentro.

**El segundo año es lo que hace que se ejecuten las consultas del grado
anterior**, y ampliarlo destapó tres cosas del andamiaje que llevaban ahí desde
el principio:

- **Lo que no está en `$OMITIDAS` ni en los recortes se copia ENTERO**, así que
  cualquier tabla nueva entra sola. Se coló `personal_access_tokens` con 319
  tokens de sesión —credenciales, en el repo— y además rompió la carga.
- **Las migraciones van antes del seed**, no después. El seed sale de la base de
  desarrollo, que ya está migrada, así que trae las columnas nuevas dentro y
  sobre el esquema pelado muere con `Unknown column 'firmantes_acta'`. Lo que ese
  orden no comprueba es una migración que TRANSFORME datos existentes; las de hoy
  son aditivas, pero la primera que toque datos necesitará su propio test.
- **`construir-bd-test.sh` silenciaba los errores de `mysql`.** Con `set -e` eso
  deja el peor estado posible: el script imprime sus pasos, se corta a la mitad, y
  la base queda vacía sin que nada lo diga.

### El detector de fugas

El generador **aborta sin escribir nada** si encuentra un nombre real en el
fichero que iba a generar. Compara palabra a palabra contra los nombres y
apellidos de la base.

No es decorativo. La lista de columnas a anonimizar se quedó corta tres veces:

- `vt_candidatos.plancha` guardaba `PAULINA - SOFIA - EMELY` — forman el nombre
  del equipo con los nombres de pila de las candidatas.
- `calendario.title` lleva `Cumpleaños de <alumno>`.
- `images.nombre` lleva el nombre del alumno en el nombre del fichero.

Si al añadir una tabla el detector se queja, hay dos salidas: añadir la columna
a `$ANONIMAS`, o la tabla entera a `$OMITIDAS`. Silenciarlo con
`COLISIONES_ACEPTADAS` **solo** después de mirar el texto de origen y confirmar
que es una palabra corriente que casualmente es el nombre de alguien.

### Lo que no entra nunca

Expedientes disciplinarios (`dis_procesos`, `dis_libro_rojo`) y PIAR —planes de
apoyo por discapacidad—. Son el dato más sensible del sistema, van en texto
libre, y ningún test los necesita. No se anonimizan: se omiten.

## Añadir un test

```php
class MiTest extends CasoDeContrato
{
    public function test_lo_que_sea(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token   = $this->tokenDe($usuario->username);

        $r = $this->getJson('/api/loquesea', ['Authorization' => 'Bearer ' . $token]);

        $r->assertStatus(200);
        $this->compararConInstantanea('loquesea-profesor', $this->forma($r->json()));
    }
}
```

`usuarioDeTipo()` no devuelve "el primer usuario del tipo": devuelve uno cuyo
contexto el seed pueda resolver de verdad. Un Alumno necesita ficha,
matrícula en estado MATR/ASIS/PREM, grupo, y que su periodo sea del mismo año
que el grupo. Sin las cuatro cosas, el endpoint responde 400.

## Los tres bloques P0 del 19 de agosto

Se escribieron después de la Fase 6, y los tres encontraron algo. Lo que los hizo
encontrar no fue leer código: fue **mirar el resultado en vez del estado**.

| Bloque | Qué se mira, y por qué así |
|---|---|
| `ImagenesTest` | El PÍXEL, no la respuesta. Las imágenes de prueba llevan una marca roja en una esquina porque `UploadedFile::fake()->image()` sale plana, y una imagen plana rotada es idéntica a sí misma. Con ella se ve que los dos botones de girar hacían lo contrario de lo que decían |
| `ExcelTest` | La FORMA de la hoja —hojas, títulos, encabezados, filas—, no los bytes: PhpSpreadsheet escribe la fecha dentro y dos exports iguales dan archivos distintos. Y el viaje de ida y vuelta: exportar, reimportar lo exportado y comparar. Eso destapó que la importación dejaba un espacio dentro de los nombres |
| `NotasTest` | Quién ve qué, pidiendo con el token de otro. Ahí salió el IDOR de `notas/alumno` |

Tres trampas que costaron tiempo y que se repetirán:

- **Un test que pasa no siempre ejecuta algo.** `notas/detailed` responde 200 con
  `unidades: []` si el periodo del profesor no es el de las unidades, y todas las
  comprobaciones de debajo pasan sin haber tocado nada. Por eso `NotasTest`
  comprueba que la rejilla no venga vacía antes de mirar nada más.
- **El periodo del usuario no se puede elegir**: `Services\Login` reescribe
  `users.periodo_id` al periodo `actual` en cada inicio de sesión. Hay que buscar
  el dato que encaja en el periodo, no al revés.
- **Una snapshot de listas vacías pasa siempre.** Las de `myimages` y la de
  deudores salían vacías con el seed tal cual; los tests se crean el dato antes
  de mirar, dentro de su transacción.

## Los dos bloques P1 del 19 de agosto

Los informes que el colegio imprime y entrega, y las matrículas de las que
cuelgan. Sesenta y seis tests en cinco ficheros.

| Bloque | Qué se mira, y por qué así |
|---|---|
| `BoletinesTest` | La TUPLA, no el cálculo. Los cinco controladores devuelven `[grupo, year, alumnos, escalas]` y `myvc_front` la desempaqueta por índice; el cálculo lo declara intocable el §5 del plan. Cada posición va nombrada en el snapshot |
| `ObservadorTest` | El ESQUELETO del HTML —qué etiquetas con qué clases, qué cabeceras de tabla—, no el HTML: trae nombres y fotos. Más las cuentas que importan en papel: dos páginas por alumno y las filas en blanco que decide el tamaño de hoja |
| `ActasEvaluacionTest` | Las IDENTIDADES que el acta lleva escritas (`resumen.cuadra`, `promocion.cuadra`), rehechas aquí en vez de creídas, y cada total reconciliado contra los `ids` que lo componen |
| `MatriculasTest` | La TRANSICIÓN, no la respuesta. Cada ruta se comprueba leyendo `matriculas.estado` de vuelta desde la base |
| `GruposTest` | Que las tres formas de borrar sigan siendo tres cosas distintas: papelera, DELETE de verdad, y el cascade a 27 tablas |

Dos trampas nuevas, además de las tres del P0:

- **`forma()` reduce una lista a su primer elemento, y eso no siempre vale.**
  Con una tupla posicional guarda la primera posición y tira el resto: el primer
  snapshot de boletines guardaba el grupo y no el boletín, y pasaba siempre. Y
  con filas de una tabla que tiene columnas nullable, describe la fila que MySQL
  puso primera: el snapshot del acta **falló en su segunda ejecución sin que
  hubiera cambiado nada**, porque ordena por `apellidos, nombres` y el seed
  anonimizado tiene ocho alumnos llamados igual. Para eso está `formaUnida()`,
  que une todos los elementos y escribe `'null|string'` cuando una columna
  aparece de dos maneras.
- **Un snapshot puede llevar dentro un dato de la base sin que se note.** El
  primer intento del observador recogía todo el texto en mayúsculas de la hoja,
  y ahí venía el nombre del colegio. Las cabeceras se leen ahora solo de los
  `<th>`, donde no hay una sola interpolación.

## El muestreo P2 del 20 de agosto

Una lectura por controlador, para los 62 que no tenían ninguna. Setenta y ocho
tests en dos ficheros. Los controladores con algo mirándolos pasan de 35 a 90 de
97; los siete que faltan no tienen ninguna lectura que mirar —son escrituras, o
los lectores de tardanzas—.

| Bloque | Qué se mira, y por qué así |
|---|---|
| `MuestreoDeLecturasTest` | Las 66 lecturas SIN parámetro: catálogos, listados y papeleras. Se separan en tres grupos —con datos, vacías, rotas— y **separarlas es todo el valor**: una lista vacía pasa cualquier comprobación, así que las once que salen vacías se anotan con su motivo en vez de guardarles un snapshot que describiría la nada |
| `MuestreoDeLecturasConContextoTest` | Las que piden un id que exista y que encaje. **Casi ninguna es un GET**: en este proyecto se lee con `PUT` y el filtro va en el cuerpo, así que buscar «un GET por controlador» —como estaba escrita la P2 en el plan— dejaba fuera a veinte controladores enteros por un detalle de verbo |

Dos trampas nuevas, además de las cinco del P0 y el P1:

- **El id no basta: tiene que encajar.** `tokenDelPersonalDe()` ya resolvía esto
  para el año; un escalón más abajo está el mismo problema con el profesor. «El
  primer profesor» no da clase en el año del grupo, así que su planilla sale
  vacía en 200 y el test pasa sin haber calculado nada. Se elige **el profesor
  con más asignaturas en el año del grupo**.
- **Los huecos se cuelan un nivel más adentro.** Una respuesta puede traer datos
  y aun así dejar una lista vacía dentro; el resto viene lleno, la comprobación
  pasa, y esa clave concreta se queda sin que nadie le mire la forma. Los vigila
  `test_los_huecos_del_seed_son_los_conocidos`, que los lee de los propios
  ficheros de snapshot —el snapshot ES la forma, así que sale igual y sin
  repetir las peticiones— y falla en cuanto aparece uno nuevo. Hay ocho.

Y dos más que salieron al ampliar el seed, y que valen para cualquier test que
se escriba a partir de ahora:

- **`periodos.actual` no dice cuál es el año actual del colegio.** Marca el
  periodo actual DE SU AÑO, así que los nueve años del seed tienen uno; el año
  del colegio lo dice `years.actual`. `NotasTest` filtraba solo por el primero y
  con dos años pasó a elegir una asignatura de 2024 mientras el login ponía al
  profesor en 2025: la rejilla en 500 y el periodo cerrado dejando guardar notas.
- **Un join con `matriculas` da una fila por MATRÍCULA, no por alumno.** Desde
  que hay dos años el mismo alumno sale dos veces, así que «los tres primeros»
  eran tres filas de dos personas. `ExcelTest` marcaba dos deudores creyendo que
  marcaba tres, y su `assertCount(3)` seguía pasando porque contaba filas.

**Y encontró cuatro endpoints que fallan siempre, ninguno en las listas del
inventario de código roto.** Tres son SQL contra columnas que no existen, y
**larastan pasó por esos tres ficheros en la Fase 6 sin ver ninguna**: el
análisis estático lee PHP, y estos errores viven dentro de una cadena de texto.
Están en [05-codigo-muerto-y-roto.md §8](05-codigo-muerto-y-roto.md).

### Lo que el seed no cubre, y hay que saberlo

**La regla, fijada el 20 ago 2026** después de que el seed vacío tapara seis
hallazgos: si lo que falta es **estado** de una fila que ya existe —una papelera
vacía, una matrícula sin retirar—, lo prepara quien mide y lo devuelve; si lo que
falta es la **fila** —un examen, un registro de enfermería—, se monta en el test
que la necesita. Llevarla al seed es una decisión aparte, y se toma cuando compre
hallazgos y no solo cobertura. Ver [09 §6](09-pendientes.md).


Un snapshot describe la forma de lo que vino, así que **una lista vacía se
describe como vacía y a partir de ahí pasa siempre**. El mapa completo de eso
—31 snapshots, más de cien claves— lo mantiene `HuecosDelSeedTest`, que lo lee de
los propios ficheros de snapshot y lo compara contra
`Snapshots/huecos-del-seed.json`. Su docblock lleva las ocho familias que hay hoy
y qué hacer cuando el test falla.

Es el test que hay que mirar cuando aparece un hueco nuevo: o el seed dejó de
traer un dato, o la ruta dejó de devolverlo, y **lo segundo no lo ve ningún otro
test**, porque la forma de una lista vacía casa con el snapshot anterior si el
anterior también estaba vacío.

De las cuatro listas que motivaron el seed de dos años, tres quedaron cubiertas y
una se queda fuera a propósito:

- `AlumnosSinMatricula` — cubierta, pero **fabricando el caso**. Con dos años ya
  hay grado anterior, y aun así el `NOT IN` seguía descartando a todos: los 56 de
  Tercero 2024 están todos en Cuarto 2025. El test desmatricula a uno, que es la
  situación real de la pantalla —el que estuvo el año pasado y este todavía no ha
  vuelto—.
- `grupos/next-year` — cubierta **al revés**. 2026 existe con sus trece grupos
  pero está borrado en producción, así que se retrasa el año actual a 2024 dentro
  de la transacción y se pregunta desde ahí.
- Las otras dos listas de prematrículas — cubiertas por el año anterior sin más.
- `con-disciplina.descripciones_typeahead` — **se queda vacía y así se queda**.
  Lee de `dis_procesos`, una de las dos tablas que el generador omite por ser el
  dato más sensible del sistema.

## La importación reanudable, del 20 de agosto

`ImportacionReanudableTest`, seis tests. No comprueban que el importador
funcione —de eso se encarga el viaje de ida y vuelta de `ExcelTest`— sino las
dos promesas que estrena: que volver a subir el mismo archivo **continúa** en
vez de repetir, y que aunque repitiera **no duplicaría**, porque el documento
del alumno se mira antes de crear.

Lo que hace que comprueben algo:

- **No matan el proceso a media importación**, que no se puede hacer desde
  PHPUnit. Escriben a mano la fila de `importaciones` que habría dejado un
  corte y miran qué hace el importador con ella. Es la misma fila, con los
  mismos valores, que la que deja un `kill`.
- **El corte va DENTRO de una pestaña**, no entre dos: el proceso no muere en el
  hueco entre hojas, muere en el alumno 340. Se marca a dos alumnos de la misma
  hoja —uno detrás del punto de control y otro delante— con un nombre imposible,
  y se comprueba que respeta al primero y pisa al segundo. Un corte entre hojas
  habría pasado igual con el salto roto.
- **Las columnas se buscan por su encabezado**, no por su letra. Una letra fija
  se rompe el día que el export añada una columna, y el test seguiría pasando
  mirando la columna equivocada.
- **Los tres que fijan comportamiento nuevo se comprobaron al revés**,
  desactivando el arreglo y viendo fallar exactamente el que debía. Dos de los
  tres pasaban al principio por una razón tonta —una sustitución con la
  indentación mal— y sin ese paso se habrían quedado así.

Los dos últimos miran el otro lado. Uno, que una pestaña cuyo nombre no es el de
ningún grupo deje **el nombre de la pestaña escrito** en `importaciones.error`
—sigue siendo un 500, porque cambiarlo es tocar el contrato de la pantalla; lo
que cambia es que ahora se puede leer—. El otro, que el nombre del archivo se
guarde **saneado**: lo pone quien sube, y lo que entra en una columna acaba
saliendo por una pantalla. Ese lo pidió `GuardsDestructivosTest`, que falló en
cuanto el código nuevo llamó a `getClientOriginalName()` fuera de `SafeUpload`
— exactamente para lo que estaba puesto.

**Y encontró un quinto endpoint roto**: `POST api/importar/cartera`, con la
misma firma de `maatwebsite/excel` 2.x que rompe `GET api/importar`. No había
salido en el muestreo P2 porque aquello solo golpeaba lecturas sin parámetro, y
esta es un POST con un archivo dentro. Queda fijado en `ExcelTest` y descrito en
[05 §8.4](05-codigo-muerto-y-roto.md).

## Cosas que aparecieron en los datos reales

Encontradas construyendo esto. Ninguna está arreglada.

- **Dos años con `actual = 1`** a la vez (2025 y 2026). `LoginController` hace
  `SELECT ... WHERE actual=1` y coge `[0]`, así que hoy funciona por accidente
  de orden.
- **El acudiente 505 tiene una frase de boletín de 252 caracteres en el campo
  `apellidos`.**
- **La tabla `failed_jobs` no existe** en la base real, aunque su migración
  estaba en el repo. Nunca se ejecutó.
- **`notas.nota` es una columna `int`.** Mandar 3,5 guarda un 4, sin error ni
  aviso. No se toca: el §5 del plan protege el cálculo de notas.
- **Los cuatro alumnos del seed sin paz y salvo están todos retirados**, y el
  export de deudores solo mira ASIS, MATR y PREM. Por eso salía vacío.
- **`ext-exif` es una sugerencia de `intervention/image`, no un requisito.** Sin
  ella no falla nada: las fotos de móvil suben tumbadas y ya. Hay que confirmarla
  en el PHP 8.4 de cada cuenta de cPanel.

Y cuatro más del P1, **las cuatro arregladas el mismo día**:

- **`PUT api/matriculas/prematricular` no miraba de quién era el `alumno_id`.** La
  ruta está abierta a Alumno y Acudiente a propósito —la prematrícula del año
  siguiente la hace la familia— pero el id llega en el cuerpo, así que con token de
  alumno se cambiaba el estado y el grupo de cualquier compañero. Era el IDOR de
  notas del P0, de escritura. Cerrado con `boletin.propio:sin-paz-y-salvo`; un
  acudiente solo puede prematricular a sus acudidos.
- **`GET api/boletines/detailed-notas-year/{grupo}` sin el segmento opcional
  devolvía el acumulado del año entero en ceros**, con 200 y sin log.
  `Periodo::hastaPeriodoN` toma un número y `Periodo::hastaPeriodo` una cadena; el
  default `10` de la primera acababa en la segunda, ninguna rama del `if` casaba, y
  el TypeError del `count()` sobre el `stdClass` inicial lo absorbía un `try/catch`.
  Igual en `boletines2` y `boletines3`. El default es ahora `de_usuario`.
- **`GET api/grupos/listado/{grupo}` nunca devolvía la dirección.** La consulta
  hacía `(a.direccion + " - " + a.barrio)`, y en MySQL el `+` es suma: salía `0`, o
  `null` si faltaba el barrio. Ahora `CONCAT_WS`, que no se traga la dirección
  cuando falta el barrio.
- **La tabla `llevo_formulario` no existía**, ni en el volcado de producción ni en
  la de desarrollo, y `PUT api/prematriculas/llevo-formulario` hacía un `DELETE`
  contra ella de entrada: 500 seguro desde siempre, como `failed_jobs`. **La ruta
  se borró en vez de crearle la tabla**: quién llevó el formulario es
  `matriculas.estado = 'FORM'`, que pone y cambia `matriculas/prematricular` con
  `estado=FORM` y lee `AlumnosFormularios`. Eran dos mecanismos para el mismo dato,
  uno vivo y otro que nunca llegó a escribir una fila.
