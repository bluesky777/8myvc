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

### Varias sesiones a la vez: una base por sesión

Si hay más de una persona —o más de una sesión de Claude— trabajando en el mismo
repo, **cada una quiere su propia base**. Se construye con `DB_TEST_DATABASE` y
se usa con la misma variable:

```bash
# Una vez, para montarla
DB_TEST_DATABASE=simonbolivar_testing_b tools/construir-bd-test.sh

# Cada vez
docker exec -e DB_TEST_DATABASE=simonbolivar_testing_b 8myvc-app-1 php artisan test
```

El sufijo es libre mientras el nombre lleve `_testing` o `_test` dentro: es lo
que miran los dos guardias que impiden correr los tests contra la base de
trabajo, el del script y el de `CasoDeContrato::comprobarBaseDeTest()`.

**Por qué hace falta, que no es evidente:** los tests se aíslan con
`DatabaseTransactions`, y una transacción aísla de las demás conexiones **de esa
misma corrida**. Dos `php artisan test` a la vez sobre la misma base se pisan de
dos maneras distintas, y ninguna se parece a un fallo del código:

- **Deadlock.** Medido el 21 de agosto de 2026: cinco tests muertos en el
  `insert` de `personal_access_tokens` que hace `CasoDeContrato::token()`, con
  las dos corridas peleándose por la misma tabla.
- **Fallos que no se reproducen.** Cada corrida ve a medias lo que la otra está
  escribiendo. Costó un cuarto de hora el 20 de agosto de 2026 y cinco tests el
  21.

Con base propia no hay nada que coordinar: cada sesión corre suites enteras
cuando quiera.

> **Lo que cuesta tener una base por sesión: se quedan viejas por separado.**
> Cada una se construye una vez y no vuelve a mirar las migraciones. Si otra
> sesión añade una, tu base no la tiene — y **la suite sigue en verde**, porque
> lo que falta son tablas o columnas que solo usan los tests de esa otra sesión.
>
> Pasó el mismo día que se montó esto: `simonbolivar_testing` se quedó sin
> `2026_08_21_100000_create_rol_secretario` mientras las otras dos sí la tenían.
>
> **Y la forma en que se encontró es media lección.** Se llegó por una corazonada
> equivocada: dos sesiones daban «todo verde» con números distintos —604 y 682— y
> se supuso que la base vieja lo explicaba. No lo explicaba. La diferencia era que
> una había corrido `--testsuite=Contrato` y la otra la suite entera
> (`Unit: 69 + Feature: 9 + Contrato: 604 = 682`), y la migración que faltaba **no
> cambiaba ningún resultado**: crea la fila del rol `Secretario` y todavía no hay
> test que la exija.
>
> O sea que la base **sí** estaba desactualizada y los números **sí** diferían, y
> las dos cosas no tenían nada que ver. Un conteo distinto no delata una base
> vieja —lo que la delata es mirar `migrations`—, y una base vieja no se nota en
> el conteo: **no se nota en nada**, que es exactamente por lo que hay que
> comprobarla a mano.
>
> Antes de fiarte de una corrida, compara:
>
> ```bash
> docker exec -i 8myvc-database-1 mysql -uroot -p"$DB_PASSWORD" -N \
>     -e "SELECT migration FROM simonbolivar_testing_b.migrations ORDER BY id;" | tail -5
> ```
>
> Si le falta alguna, se reconstruye: `DB_TEST_DATABASE=... tools/construir-bd-test.sh`.
> Tarda unos minutos y es lo mismo que hacía falta antes al cambiar el esquema —
> lo nuevo es que ahora hay que hacerlo **en cada base**, no en una.

> **La variable tiene que cruzar el `docker exec`**, y por eso el paso de
> migraciones de `construir-bd-test.sh` va con `env`: un `docker exec` **no**
> hereda el entorno de quien lo llama. Sin eso, pedir otra base la creaba con
> `mysql` —que corre desde el equipo— pero la migraba con `artisan` **dentro**
> del contenedor, o sea contra la de por defecto, y el seed moría después con
> `Unknown column 'firmantes_acta'` acusando al fichero equivocado.

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
docker exec -e COBERTURA_RUTAS=/tmp/tocadas.txt 8myvc-app-1 php artisan test
docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas.json
docker exec 8myvc-app-1 cat /tmp/tocadas.txt > /tmp/tocadas.txt   # sacarlo del contenedor
python3 tools/cobertura-de-rutas.py /tmp/rutas.json /tmp/tocadas.txt
```

El registrador vive en `tests/TestCase.php` y solo se enciende con la variable
puesta; una corrida normal no escribe nada.

> **No borres el fichero a mano antes de medir, y el modo de empleo ya no lo
> pide.** Lo vacía la propia corrida, una vez, al primer test que lo va a usar.
>
> El paso `rm -f` que había aquí hasta el 21 de agosto de 2026 es exactamente
> como se pierde una medición entera sin que nada avise: si cae a mitad de otra
> corrida —dos sesiones trabajando a la vez—, desengancha el inode que esa
> corrida tiene abierto y el siguiente `file_put_contents` empieza un fichero
> nuevo. **Ni `FILE_APPEND` ni `LOCK_EX` protegen de eso.** Ese día la cobertura
> salió **86 de 539** en vez de 346, con 135 casos registrados de los 588 que
> corrieron.
>
> Y la lección no es el fallo, es que **el número salió plausible**: 86 de 539 no
> se distingue de un mal día de verdad. Una medición que puede equivocarse hacia
> abajo en silencio no es una medición.

> **Con varias sesiones midiendo a la vez, un fichero por sesión** —
> `/tmp/tocadas-b.txt`, `/tmp/consultas-b.jsonl`—, igual que la base. La ruta es
> fija dentro del contenedor, así que bases distintas no bastan: dos corridas
> escribirían sus rutas mezcladas en el mismo fichero y las dos mentirían hacia
> arriba.

> **El día que se instale paratest esto hay que revisarlo.** Hoy la suite corre
> en un proceso y por eso vaciar una vez por proceso es correcto; con
> `--parallel`, cada worker borraría lo que escribieron los demás. Entonces toca
> un fichero por PID y un lector que los junte. No está en `composer.json`
> —comprobado el 21 de agosto de 2026—, y el aviso está también en el docblock
> de `TestCase::$medicionesVaciadas`.

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


#### El seed sólo tiene dos estados de matrícula, y eso ciega a una familia entera — 25 ago 2026

Medido sobre `simonbolivar_testing`, matrículas vivas:

```
MATR   65
RETI   59
ASIS    0      <- y DESE, PREM y el resto: 0
```

**Cero `ASIS`.** Y no es que ese estado no exista: la base de desarrollo tiene 1
`ASIS`, 1 `DESE` y 1 `PREM` entre sus 3.542 — o sea que **son reales y el seed se
quedó con dos**.

**La consecuencia es más ancha que un test.** `ASIS` aparece **82 veces en 43
ficheros** de `app/`, casi siempre en la forma `(m.estado="MATR" or m.estado="ASIS")`.
**Sobre este seed, ese predicado y `m.estado="MATR"` a secas devuelven exactamente
lo mismo**, así que:

> **Ningún test que corra contra este seed puede distinguir los dos.** Un arreglo
> que «limpie» un `(MATR or ASIS)` dejándolo en `MATR` **pasa la suite entera en
> verde** y deja fuera del informe a los alumnos asistentes en los quince
> colegios.

Lo encontró `8myvc-79` **saboteando su propio código a propósito** para comprobar si
sus tests lo cazaban: el sabotaje que sustituía el predicado **no puso nada en
rojo**. Sus tests no estaban mal escritos — *lo único que sostenía esa protección
era que el SQL se había escrito bien*.

**Qué hacer con esto**, y es la regla de arriba aplicada: `ASIS` es **estado de una
fila que ya existe**, así que **lo prepara quien mide, dentro de su transacción**
—pasar a `ASIS` la matrícula de un alumno con notas y comprobar que sigue
apareciendo—. Llevarlo al seed es una decisión aparte.

Y la parte que no se arregla con un test: **un test que no puede ver algo tiene que
decirlo.** Cualquier medición sobre esa familia se escribe con esta línea al lado
—*este seed tiene 0 `ASIS`*— o afirma una cobertura que no tiene.

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

## Cómo se corre una tanda de la que te vas a fiar

Los tres pasos salen de **tres tropiezos distintos de la noche del 22 al 23 de
agosto de 2026**, y hasta entonces estaban repartidos por el
[barrido de cegueras](noche-2026-08-23/las-cegueras.md) sin que ninguno dijera el
procedimiento entero.

```bash
# 1. que no haya nadie más contra esa base — el hijo se llama phpunit, NO artisan
docker exec 8myvc-app-1 sh -c 'pgrep -af "phpunit.*worktrees/X"'

# 2. desacoplada del harness, y con el código de salida DENTRO del contenedor
docker exec -d -w /app/.worktrees/X -e DB_TEST_DATABASE=simonbolivar_testing_X \
    -e COBERTURA_RUTAS=/tmp/tocadas-X.txt 8myvc-app-1 \
    sh -c 'php artisan test > /tmp/suite-X.log 2>&1; echo "EXIT=$?" >> /tmp/suite-X.log'

# 3. esperar al EXIT=, no al texto ni al tamaño del fichero
until docker exec 8myvc-app-1 sh -c 'grep -q "^EXIT=" /tmp/suite-X.log'; do sleep 25; done
```

| Paso | El tropiezo del que sale |
|---|---|
| **1** | Un `pkill -f "artisan test"` mató el envoltorio y **dejó al hijo vivo**, reparentado a init, corriendo contra la misma base. Buscando `artisan` no aparece: **el hijo se llama `phpunit`**. Dos tandas contra una base chocan en `personal_access_tokens`, y los rojos salen en cualquier familia y con toda la cara de ser un test roto |
| **2** | Un `docker exec` muerto por ese `pkill` devolvió **exit 143** y el harness lo resumió como **«completed, exit code 0»**. El código de salida escrito **dentro** del contenedor es lo único que no lo hace |
| **3** | El log **deja de crecer** por el búfer de bloque —parado justo en 10.210 bytes— y parece que la suite murió. El tamaño del fichero no dice nada; el `EXIT=` sí |
| **4** | *(2 sep 2026)* El **aviso de «terminado» del propio lanzador** dijo «exit code 0» con la suite en **exit 1**. No hubo `pkill` ni tubería: el comando acababa en un `echo`, así que el código que se propagó fue **el del `echo`**. Se cazó porque el de verdad se había guardado antes con `echo $? > code.txt`, y porque la línea `Tests:` decía `1 failed` — **dos fuentes que tenían que coincidir y no coincidían** |

> **La regla del código de salida, en su forma ancha.** No es «cuidado con `| tail`»
> ni «cuidado con `pkill`»: es **cualquier cosa que se ejecute entre el comando que te
> importa y la lectura de su `$?`** — una tubería, un `echo` de etiqueta, o el
> resumen que te da el envoltorio. Por eso el paso 2 escribe el `EXIT=` **dentro** del
> contenedor y por eso se lee además la línea `Tests:`: **una cifra sola no se
> comprueba a sí misma**, y las dos que discrepan son el hallazgo.

> **Y para identificar de quién es un proceso, el `--configuration=` y no el
> `cwd`**: un test se mete en un directorio temporal y el `cwd` deja de nombrar su
> árbol. Lo estable es la línea de comandos del hijo.

Y dos que no son pasos pero deciden el número:

- **Para la medición de cobertura va la suite entera, no `--testsuite=Contrato`.**
  Es la diferencia entre 462 y 461: `GET /` solo la toca
  `tests/Feature/ExampleTest.php`, el stub que dejó `laravel new`.
- **`COBERTURA_RUTAS` con un fichero por sesión.** `/tmp` del contenedor está
  compartido: compartirlo dio una vez *86 de 539 cuando eran 346*.

---

## `ORDER BY` que empata + `LIMIT 1` = un test que no es determinista — 2 sep 2026

`EditorDeNotaConElParTest` salió **rojo en la suite entera y verde al correr la clase
sola**. No era el entorno y no era el código de los tres carriles: era su `contexto()`.

    ORDER BY a.id LIMIT 1     -- sobre un join que empata en 333 filas

Medido sobre la base de tests: de las **333 filas que empatan** en el primer `a.id`,
las seis columnas que selecciona `NotasTest::contexto()` —grupo, profesor, `user_id`,
`username`, periodo— tienen **un solo valor**, y `m.alumno_id` tiene **37**. `NotasTest`
está a salvo; este test se rompió al **copiarle la consulta y añadirle una columna que
ese `ORDER BY` no determina**. El criterio era correcto allí y dejó de serlo aquí, y
**nada se puso rojo al copiarlo**.

**La regla, que vale para todos los `contexto()` de `tests/Contrato/`:** *el orden sólo
es determinista si la clave de orden es única en el join.* Acotar con más `INNER JOIN`
**no** es una garantía — sólo hace que hoy haya menos empates, y un seed con dos
alumnos más lo reabre sin que nadie toque el test.

**Y por qué no se ve como inestabilidad:** el síntoma era un **tipo**. Las notas se
calculan; para unos alumnos la cuenta da redonda y para otros no, y la instantánea
guarda el tipo — `float` contra `int`. Un rojo que dice «`float` esperado, `int`
recibido» se lee como una regresión del código, no como una fila distinta.

> **Quedan sin revisar los demás `LIMIT 1` sobre empates.** `8myvc-59` señaló dos con la
> misma forma: `PlanillaSinIndependientesTest::contexto()`, que está en este árbol, y el
> `PlanillaSinProfesorTest::contexto()` que escribió esa noche —hoy sólo en
> `fix/planilla-sin-profesor` (`1728c10`), todavía sin fusionar—. **Los dejó así a
> sabiendas**, y su razón es la buena: hoy están a salvo **por el seed, no por
> construcción**, y blindar uno mientras el resto sigue igual es peor que hacer el
> barrido de una vez. Es un barrido, no un arreglo suelto.

---

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

---

## Un test que no se ha visto en rojo no prueba nada — 24 ago 2026

La receta es de una línea y es la más barata de este documento:

> **Revierte el arreglo y comprueba que el test se pone rojo.** Si sigue en verde,
> lo que prueba no es lo que crees.

Ya estaba en el repo con otras palabras —`EditarUnaNotaActualizaLaDefinitiva`
explica por qué borra la nota antes— y aun así el 24 ago dos sesiones la
olvidaron teniéndola escrita al lado.

**El caso que la trajo de vuelta**, y es el que la justifica: `8myvc-d0` escribió
el test de «la sesión sigue abierta cuando el colegio cambia de año», movió el
periodo del acudiente, pidió la ficha y **pasó**. Al revertir el arreglo, **siguió
pasando**. El verde no significaba nada: había un tercero en el camino —el login,
que repara `users.periodo_id`— que su razonamiento no incluía.

Sin esa comprobación, el test se habría quedado ahí **defendiendo un
comportamiento que nadie había producido**, y de paso confirmando un diagnóstico
falso que ya se había publicado en el
[09 §8](09-pendientes.md) como decisión para Joseth.

**Por qué esta comprobación caza lo que ninguna otra:** todas las demás miran el
test. Ésta mira **la distancia entre el test y el código**, y es la única que
detecta un verde producido por algo que no es el sujeto — un valor por defecto que
ya era correcto, una guarda de más arriba, un `catch` que traga, o un servicio que
repara el estado por el camino.

Es la misma familia que las cuatro formas del [apéndice de
`las-cegueras.md`](noche-2026-08-23/las-cegueras.md): **la medición no miente; lo
que miente es lo que se concluye de ella.** Aquí la medición es el test y la
conclusión es «el arreglo funciona».

> Cuando el negativo no se puede montar —porque revertir es caro o el fallo no se
> puede reintroducir sin tocar producción— **eso se dice en el test**, en vez de
> dejarlo en verde como si se hubiera comprobado. Un verde sin negativo es un
> verde con una nota al pie, no un verde.
