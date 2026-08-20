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

## Las tres piezas

| Fichero | Qué es |
|---|---|
| `database/schema/mysql-schema.sql` | El esquema real congelado. 90 tablas. |
| `database/dumps/test-seed.sql` | Los datos: rebanada anonimizada, 46 tablas, ~15.800 filas. |
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

Por defecto se ancla en el año 8 (2025) y el grupo 98 (Cuarto, 68 alumnos).
Sigue el grafo de claves foráneas hacia fuera, así que lo que entra tiene sus
referencias dentro.

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

### Lo que el seed no cubre, y hay que saberlo

Cuatro listas salen vacías siempre, así que su contenido **no lo comprueba
nadie** aunque el test esté verde:

- `AlumnosSinMatricula` (matrículas y prematrículas) y las otras dos listas de
  prematrículas: el seed es un grupo de un año, y no hay grado anterior del que
  sacar candidatos. Es la consulta del `NOT IN`, la más enredada de las tres.
- `grupos/next-year`: el año siguiente al del seed está borrado.
- `con-disciplina.descripciones_typeahead`: lee de `dis_procesos`, una de las dos
  tablas que el generador omite a propósito.
- `promovidos.recuperaciones`: `recuperacion_final` no entra en el seed.

Cubrirlas pide un seed con dos años. Es trabajo de la P2.

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
