# Lote I — El barrido por tipo de token

> Sesión `8myvc-ec`, noche del 22 al 23 de agosto de 2026. Rama
> `fix/lote-i-barrido-tokens`, árbol `.worktrees/i`, base `simonbolivar_testing_i`.
>
> Lee y reporta: no toca ningún controlador. Secciones **§111–113**.

La pregunta del lote era «qué alcanza un token de Profesor, de Acudiente y de
Usuario». La respuesta empieza por una que no estaba en el enunciado: **el
instrumento medía a otro sujeto**.

---

## §111 · El barrido de `Usuario` estaba midiendo al superusuario

`SuperficieDeUnTokenTest::sujetoDeBarrido()` delegaba en `CasoDeContrato::usuarioDeTipo()`
para todo lo que no fuera `Alumno`. Ese ayudante ordena por id y devuelve el primero
del tipo. Medido:

```
usuarioDeTipo('Usuario')  ->  usuario 1, users_1, is_superuser = 1, rol Admin
```

Y el **control** de la segunda pasada del propio barrido —el que repite las rutas
mudas para separar «un guard lo impidió» de «no había nada que alcanzar»— coge «el
superusuario de menor id», que es **el mismo usuario 1**.

O sea que `BARRIDO_TIPO=Usuario` comparaba un sujeto consigo mismo: todo lo que
alcanzara sería «lo suyo» y todo silencio, «no juzgable». **No daba un número malo:
no medía al sujeto que decía medir.**

### Por qué pasó, que es lo que importa

`usuarioDeTipo()` **no está mal**. Está bien para otra pregunta. Se escribió para los
tests de contrato, donde lo que se mira es **la forma de la respuesta** y «cualquiera
del tipo» es exactamente lo correcto —de hecho su docblock explica con cuidado por
qué no vale cualquiera *dentro* del tipo—. El barrido mira **qué alcanza un token**, y
ahí «cualquiera» es lo que no vale.

> Un ayudante correcto reutilizado para una pregunta distinta no falla: contesta bien
> a la pregunta para la que se escribió.

### Lo que se arregló, y sólo eso

En `tests/Barrido/SuperficieDeUnTokenTest.php`:

1. Para `Usuario` se exige **`is_superuser = 0`**, y si el seed no tuviera ninguno el
   barrido **se cae con nombre** en vez de medir al superusuario y llamarlo Usuario.
2. **La cabecera imprime `is_superuser` y los roles del sujeto**, y si aun así fuera
   superusuario lo dice en voz alta. «Usuario 1» no distingue a un administrativo de
   un superusuario, y esa diferencia es la que decide si el resultado significa algo.

> **Un instrumento que dice a quién midió no puede medir a otro en silencio.**

### Y lo que esto toca fuera del barrido — no se arregla aquí

El mismo ayudante lo usan los tests de contrato como «el personal del colegio»:

```
usuarioDeTipo('Usuario')   ->  usuario 1    is_superuser = 1
tokenDelPersonalDe(7)      ->  usuario 685  is_superuser = 0   (un Psicólogo)
tokenDelPersonalDe(8)      ->  usuario 1    is_superuser = 1
```

**El mismo ayudante devuelve un superusuario o un administrativo llano según el año
que le toque**, en silencio. Y ninguna llamada pasa el año literal: todas pasan
`$grupo->year_id`, así que el sujeto no depende de un número que se pueda leer en el
test — depende del grupo que ese test eligió.

**Cuánto daño hace eso se midió, y no es lo que parecía.** La primera lectura fue
«los que afirman un rechazo fallan ruidosamente, así que ésos no mienten». Al abrirlos
uno por uno resulta que **no fallan: prueban de más**. El ejemplo con nombre es

    PedidosDeAsignaturaTest::test_un_administrativo_recibe_403_al_pedir_una_materia

cuyo sujeto es el superusuario y que pasa **porque esa ruta rechaza incluso a un
superusuario**. El test es verdad y **su nombre es falso**: no demuestra «un
administrativo recibe 403», demuestra algo más fuerte. Repuntarlo a un administrativo
llano lo **debilitaría**.

Clasificados los 87 métodos que usan uno de los dos ayudantes:

| Lo que afirma con ese sujeto | Cuántos | Qué hay que hacer |
|---|---|---|
| Sólo un **permiso** (200/201/204) | **37** | **repuntar**: el superusuario prueba menos de lo que dice el nombre |
| Sólo un **rechazo** (401/403/404/422) | 12 | dejarlo y **corregir el nombre**: prueba más |
| Las dos cosas | 11 | idem |
| Ningún código | 27 | **no tocar**: son de forma, y cambiarle el sujeto a un snapshot lo invalida |

O sea que **el trabajo son 37 métodos, no 87**. De ellos, **31 van por
`usuarioDeTipo('Usuario')`** —siempre el usuario 1, deterministas— y **6 por
`tokenDelPersonalDe()`**, que hoy dicen dos cosas distintas según el grupo que el test
eligió.

Y siete de los 37 **se autoacusan por el nombre**, porque dicen «el personal» y
demuestran «el superusuario». Los tres que más pesan están en
`SuperficieDeUnAlumnoTest`, que es donde viven los candados de la §16 y siguientes: su
mitad positiva —«el personal sigue pudiendo»— es justo la que sujeta que un candado no
se pasó de frenada, y **si esa mitad la firma un superusuario, un candado que dejara
fuera a todo el personal no superusuario pasaría igual**.

No se toca desde aquí, y no por territorio: `CasoDeContrato.php` es **el suelo de
todas las sesiones**. Va al lote N, el último, con todo fundido.

---

## §112 · Lo que alcanza cada tipo, medido

> **Nota sobre las salidas pegadas más abajo**: se produjeron con la etiqueta antigua,
> `ESCRIBE`. El barrido la llama ahora **`EJECUTA`**, por la razón de más abajo — ve la
> sentencia, no las filas afectadas—. Es la misma columna con el nombre honrado.


Cinco barridos, misma noche, misma base. **El primero es la comprobación, no el
resultado**: Alumno y Acudiente tienen que reproducir lo que ya se midió el 20 de
agosto, y lo reproducen.

| Sujeto | Rutas que alcanza con algo dentro |
|---|---|
| Alumno (usuario 2375) | **8** |
| Acudiente (usuario 488) | **10** |
| Usuario llano (usuario 679, **cero roles**) | **145** |
| Profesor (usuario 8, rol Profesor) | **164** |
| Superusuario (usuario 1, rol Admin) | **170** |

El Acudiente alcanza exactamente **dos más** que el Alumno —`acudientes/mis-acudidos`
y `ChangesAsked/to-me`—, que son las dos que el 20 de agosto quedaron escritas, y las
dos siguen devolviendo lo de su acudido.

**Esa frase no se dio por buena leyendo el controlador: se midió**, porque el barrido
marca `ChangesAsked/to-me` con **209 KB y siete columnas personales**, y 209 KB no se
parecen a «lo de su acudido». Desglosado:

```
alumnos          1 elemento     4.424 b     <- su único acudido, alumno_id 460
publicaciones    2 elementos      833 b
eventos        593 elementos  204.584 b     <- el 97% de la respuesta
grados_sig       0 elementos        2 b
```

**El único `alumno_id` que aparece en toda la respuesta es el de su acudido**, y
`grados_sig`, `publicaciones` y `eventos` no traen ninguna columna personal con valor.
Los 209 KB son **el calendario del colegio**: 593 eventos. Las siete columnas
personales que ve el barrido salen de la ficha del acudido, que es la regla.

> Un tamaño no es una fuga, y una lista de columnas personales tampoco: las dos hay
> que abrirlas. Ésta se abrió y confirmó lo que ya estaba escrito.

> **Empezar por reproducir lo conocido es lo que da derecho a que te crean lo nuevo.**

### El salto, y dónde no está

De la familia al personal del colegio se multiplica por catorce. Pero el escalón **no
lo pone el rol**: un administrativo con **cero roles** alcanza 145.

| | Cuántas |
|---|---|
| Las alcanzan los tres (profesor, administrativo sin rol, superusuario) | **123** |
| Sólo el superusuario | **38** |
| El profesor y no el administrativo | **22** |
| El administrativo y no el profesor | **3** |

- Las **38** del superusuario son las que uno esperaría que lo fueran: los borrados
  físicos (`alumnos`, `grupos`, `profesores`, `unidades`, `subunidades`, `notas`), las
  papeleras, crear administradores, enfermeros y psicólogos, roles y permisos, las
  definitivas manuales y los uniformes.
- Las **22** del profesor son actividades, preguntas, opciones, calendario, crear
  unidades y `definitivas_periodos/calcular-grupo-periodo`.
- Las **3** del administrativo son enfermería (dos) y una de PIAR.

O sea que **`tipo` y `is_superuser` deciden casi todo, y los once roles casi nada.**

---

## §113 · Por qué los roles no separan: la causa, medida en la base

### 1. `Autoriza::esAdministrativo()` es hoy `is_superuser` a secas

```php
return (bool) ($user->is_superuser ?? false) || Role::isSecretario($user->user_id);
```

`Role::isSecretario()` pregunta por un rol llamado **`'Secretario'`**, y en la tabla
`roles` **no existe**. Los once son: Admin, Profesor, Alumno, Acudiente, Manager,
Asistente, Enfermero, Coord disciplinario, Coord académico, Rector y Psicólogo.

El docblock del método deja pendiente «quién es el Secretario» —la [§30.2](../05-codigo-muerto-y-roto.md)—
pero **no dice que el rol al que pregunta no exista**, así que el método se lee como
si tuviera dos ramas cuando tiene una. Y de ese criterio cuelgan las escrituras de
alumnos, las de acudientes y los tres `forcedelete`.

### 2. Los 19 permisos cuelgan de un rol que no tiene a nadie

- **16 de los 19** son del rol `Manager`. Los otros tres son los `can_work_like_*` de
  Profesor, Alumno y Acudiente.
- **`Manager` tiene 0 usuarios.** También `Asistente`, `Coord académico` y `Rector`.
- El backend **lee un permiso en un solo sitio**: `RolesController:28`, con
  `can_edit_usuarios`. Como el único rol que lo tiene está vacío, ese `if` sólo lo pasa
  un superusuario — que ya salió por el `return` de la línea de arriba.

**El sistema de permisos hoy no decide nada.**

### 3. De los once roles, tres deciden algo

| Rol | Qué decide | Usuarios |
|---|---|---|
| `Psicólogo` | dos columnas de la ficha del alumno (`nee`, `nee_descripcion`) | 4 |
| `Enfermero` | los antecedentes médicos en `EnfermeriaController` | 1 |
| `Coord disciplinario` | **nada todavía**: su método no tiene llamantes | 1 |
| `Secretario` | `Autoriza::esAdministrativo` — **pero la fila no existe** | — |

Los tres primeros existen en la tabla. La diferencia con `Secretario` no es que el
nombre esté mal escrito en un sitio: **es que a ése le falta la fila**.

### Nada de esto se arregla, y el porqué es el de siempre

Crear el rol `Secretario` o darle usuarios a `Manager` **cambia de golpe quién puede
qué en dieciséis colegios**. Es exactamente lo que el [09 §5](../09-pendientes.md)
tiene esperando a Joseth, y lo que faltaba allí eran estos números.

Lo que sí queda es `tests/Contrato/LoQueDecideUnRolTest.php`, que **fija que hoy
`esAdministrativo` ≡ `is_superuser`**, que `Manager` está vacío y que los tres roles
que deciden existen. El día que alguien cree ese rol, esos tests se ponen rojos y le
dicen lo que acaba de mover **antes** de que lo descubra en producción.

---

## §112.1 · Las 164 del profesor: por qué no son 164 agujeros

De los 164 hallazgos del token de Profesor, **140 no consultan ningún criterio de
rol** —ni `Autoriza`, ni `is_superuser`, ni `Role::`, ni un `exig*` privado, ni
`pueden_editar_notas`— y se apoyan enteros en `auth.personal`. Los otros 24 sí
consultan algo y aun así pasaron, que para un profesor suele ser lo correcto.

**140 no es el número de agujeros, y decirlo así sería el error que este documento
persigue.** El barrido lo dice él mismo en su salida: *«cada una hay que mirarla:
muchas son lo suyo, y eso el barrido no lo sabe»*. Un profesor **debe** crear
unidades, poner ausencias, escribir disciplina y montar actividades. Lo que la lista
da es **dónde mirar**, y el discriminador no lo pone la herramienta.

Lo que sí queda separado, y es lo único que este lote afirma:

- **La mayor parte de las 140 ya está decidida.** Las escrituras de `years`,
  `periodos`, `asignaturas`, `grupos` y `materias` son las 44 rutas que Joseth decidió
  **no cerrar**, porque cerrarlas dejaría fuera a un coordinador que hoy configura y no
  tiene el rol — y ahora se ve por qué no lo tiene: **el rol no existiría de todos
  modos**.
- **Lo que queda fuera de esa decisión y merece una pregunta**, sin proponer arreglo
  porque ninguna es de este lote:

  | Ruta | Qué hace |
  |---|---|
  | `PUT alumnos/cambiar-claves` | `update users` — cambia contraseñas de alumnos |
  | `DELETE profesores/destroy/{id}` | manda a otro **profesor** a la papelera |
  | `PUT detalles/eliminar-matricula-destroy` | `delete matriculas`, borrado **físico** |
  | `DELETE contratos/destroy/{id}` | los contratos de los docentes |
  | `GET nota_comportamiento/detailed/{grupo_id}` | un **GET que inserta** en dos tablas |
  | `PUT ciudades/actualizar-departamento` | 125 filas de golpe, ya medido |

Y una que **no** es hallazgo y conviene decir que no lo es: `GET api/contratos` sale
en el barrido del alumno **y** en el del acudiente con el expediente entero de los
nueve docentes. **Ya estaba juzgada** —el 05, `AutorizacionTest:609` y el 09 §5— y
espera decisión. Que el barrido la reencuentre solo es la señal de que la lista nueva
es creíble.

---

## Lo que este barrido sigue sin poder medir

Se añade a las cegueras que el propio fichero ya lleva escritas, porque salió mirando
los identificadores que usa:

**`{grupo_id}` ajeno es también «de otro año», y en este seed no puede no serlo.** Hay
exactamente **dos grupos vivos, uno por año** —84 en 2024 y 98 en 2025—, así que el
grupo «ajeno» que elige el barrido es siempre de un año distinto al del sujeto. Para
las rutas que llevan un grupo en la URL, un vacío puede no distinguir **«el guard lo
impidió»** de **«el filtro de año no encontró nada»**.

**Y la segunda pasada de control no lo rescata**, que es lo que hace a esta ceguera
distinta de las otras: el control usa un superusuario que **está en el mismo año que el
sujeto** —los cuatro sujetos de esta noche salieron con `year_id = 8`— así que ve
exactamente el mismo vacío por exactamente la misma razón. **Un control que comparte el
sesgo del sujeto confirma el sesgo, no lo desmiente.**

### Cuánto mide, porque una ceguera sin tamaño se lee como un descargo

De las **28** rutas con `{grupo_id}` en la URL:

- **19 no miran el año del usuario** para nada: su vacío sí es del guard o de la fila, y
  se puede leer.
- **9 lo nombran**, y de ésas **2 salieron como hallazgo igual** —`nota_comportamiento/detailed`
  y `planillas/show-grupo`, que devolvió 394 KB del grupo ajeno—, o sea que en ellas el
  año se usa para otra cosa y no para acotar el grupo.
- Quedan **7 mudas cuyo silencio no está explicado**:

```
GET api/boletines/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}
GET api/boletines2/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}
GET api/boletines3/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}
GET api/observador/vertical/{grupo_id}/{tamanio}
GET api/piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}   (muda para el profesor)
PUT api/observador-horizontal/horizontal/{grupo_id}
PUT api/puestos/detailed-notas-periodo/{grupo_id}
```

**Siete de las ~190 mudas de cada pasada**, o sea alrededor del 4%.

### Y al medirlas, seis de las siete no estaban cerradas

Un «no medido» con nombre se puede medir, así que se midió, en
`tests/Barrido/GrupoAjenoDelMismoAnioTest.php` y **sin tocar el barrido grande**: se
monta el caso que en el seed no existe —un grupo del **mismo año** del profesor, con un
alumno matriculado dentro y **cero asignaturas suyas**— y se golpean sólo esas siete.

```
GET  api/boletines/detailed-notas-year/103/1      200    6.738 b   PERSONALES: fecha_nac,email
GET  api/boletines2/detailed-notas-year/103/1     200    6.738 b   PERSONALES: fecha_nac,email
GET  api/boletines3/detailed-notas-year/103/1     200    6.738 b   PERSONALES: fecha_nac,email
GET  api/observador/vertical/103/10               200   21.599 b
GET  api/piars-asignaturas/asignaturas/103/460    200        2 b
PUT  api/observador-horizontal/horizontal/103     200    3.880 b   PERSONALES: documento,celular,direccion,fecha_nac,email,barrio
PUT  api/puestos/detailed-notas-periodo/103       200    3.676 b   PERSONALES: documento,celular,direccion,fecha_nac
```

**Seis de las siete contestan, y cuatro con datos personales dentro.** Su silencio en
el barrido grande no era «no hay nada que alcanzar»: era **el año**. Sólo
`piars-asignaturas` se queda muda para el profesor.

**Y con un administrativo sin ningún rol, las siete**: `piars-asignaturas` le contesta
435 b donde al profesor le da 2. La corrección es simétrica, o sea que **las dos cifras
estaban contando de menos** —164 y 145— y no es un efecto del tipo de sujeto. Se midió
con los dos a propósito: una corrección que sólo se comprueba en un sujeto no distingue
«el barrido contaba mal» de «este sujeto alcanza más».

**Esto no es un agujero nuevo**, y decirlo lo sería: es la misma decisión de Joseth
sobre `auth.personal` —el personal del colegio ve el colegio entero— aplicada a seis
rutas más. Lo que sí es, y por eso va aquí y no en la lista de sospechosas:

> **El barrido estaba contando seis rutas alcanzables dentro de «no juzgable»**, que es
> el cajón que se lee como «cerrada». La cifra honrada del profesor es **164 más al
> menos seis**, y el cajón de las no juzgables, seis más pequeño.

Es la misma forma que este documento persigue desde la primera línea, aplicada a sí
mismo: **un silencio no es una respuesta hasta que se sabe por qué está callado.**

`CasoDeContrato::grupoAjenoDelMismoAnio()` existe justo para esto y el barrido no lo
usa. No se cambia aquí: tocarlo mueve los cinco números de arriba, y esos números son
la entrega de este lote. Queda anotado para quien lo recoja.

---

## Y una ceguera que empuja al revés: `ESCRIBE` no quiere decir «cambió una fila»

La del `{grupo_id}` hacía contar **de menos**. Ésta hace contar **de más**, y las dos
juntas son lo que impide leer los cinco números como una cuenta exacta.

El barrido marca `ESCRIBE` escuchando con `DB::listen` las sentencias `insert`,
`update` y `delete` que ejecuta la petición. **`DB::listen` ve la sentencia, no las
filas afectadas.** Una ruta que ejecuta un `UPDATE ... WHERE id = 0` sale marcada como
escritura aunque no toque nada.

Medido, con el caso que lo prueba:

```
DELETE api/requisitos/destroy/0   ->  200 'Eliminado'
   UPDATE ejecutados que el barrido ve:  1
   filas borradas antes = 0    despues = 0
```

El barrido lo listó como `ESCRIBE: update requisitos_matricula` **y a la vez** lo puso
en su lista de «no medidas: el seed no tiene ninguna fila ajena de esa tabla». Las dos
cosas eran ciertas y ninguna de las dos es «escribió».

### Y de paso, una respuesta que miente que la herramienta de mentir no puede ver

`DELETE requisitos/destroy/{id}` contesta **200 «Eliminado»** con un id que no existe,
sin haber borrado nada. Es la familia de `respuestas-que-mienten.py`, que el
[15 §1](../15-la-noche-en-paralelo.md) dio por agotada —«da un solo sitio, es un
resultado y no un hueco»—, y **esta no sale ahí**: la herramienta busca métodos que
**frenan** la escritura y contestan 200 igual, y aquí la escritura **corre** y no
encuentra a quién. Misma mentira, mecanismo distinto, señal distinta.

No se arregla desde este lote —`RequisitosController` no es suyo— y se anota con la
distinción escrita, porque «esa serie está agotada» es exactamente la clase de renglón
que apaga la pregunta.

---

## §112.2 · La otra mitad de la regla del acudiente, comprobada desde el resultado

La regla —**un alumno ve lo suyo; un acudiente, lo suyo y lo completo de sus
acudidos**— está escrita como cerrada, y el barrido midió qué alcanza un acudiente
juzgándolo siempre **contra su propio acudido**. Faltaba la otra mitad: **que sobre un
alumno ajeno no alcance nada.**

`tests/Barrido/AcudienteSobreUnAjenoTest.php` la comprueba sobre las doce rutas que un
acudiente puede pedir con un alumno concreto, de las 41 que llevan uno de los dos
guards de propiedad (17 con `boletin.propio`, 24 con `persona.propia`).

El escenario sale del seed sin fabricar nada, y **el alumno ajeno es del mismo grupo** a
propósito: si fuera de otro grupo o de otro año, un vacío lo explicaría eso y no el
guard. Los dos alumnos están **a paz y salvo**, para que esa rama del guard tampoco
confunda el resultado.

**Resultado: 403 en las doce.** La regla se sostiene.

### Pero el resultado sólo vale por su control, y el control no sale entero

Cada caso se repite con **el acudiente de verdad de ese alumno**. Si ahí sale el dato,
el 403 del otro es del guard; si ahí tampoco sale, el 403 no prueba nada.

**Ocho de los doce controles devuelven el dato** —hasta 47 KB con `documento`,
`celular`, `direccion` y `fecha_nac`—, así que en esos ocho el 403 está probado.
**Cuatro contestan 500**, o sea que en esos cuatro el 403 **no está probado por un
control positivo** y hay que decirlo:

| Ruta | Acudiente propio | Personal |
|---|---|---|
| `boletines2/detailed-notas/{grupo}` | **500** | 200 |
| `boletines3/detailed-notas/{grupo}` | **500** | 200 |
| `notas-actuales-alumnos/{grupo}` | 500 | 500 |
| `matriculas/prematricular` | 500 | 500 |

Las dos últimas contestan 500 también con un token de personal, así que **el 500 no es
de la ruta: es del cuerpo que manda este fichero**. `notas-actuales-alumnos` queda sin
juzgar. `matriculas/prematricular` **sí se juzgó**, y lo que salió no es el 500.

#### `matriculas/prematricular`: el 500 era mío, y debajo había otra cosa

Al abrirlo, el 500 se explica entero y no acusa a nadie: el método **escribe primero y
consulta después**, y la consulta final busca la matrícula del alumno en
`years.year = <año del usuario> + 1`. En este seed **2026 está en la papelera**, así que
no la encuentra, hace `[0]` sobre una consulta vacía y revienta. Con el año siguiente
creado, no pasaría.

Pero midiendo eso salió lo que sí es un hallazgo, y **es la única escritura abierta a
una familia**:

```
matriculas ANTES:   id 3179  grupo 98 (2025, el año en curso)  estado MATR  prematriculado null
    PUT matriculas/prematricular {alumno_id: <mi acudido>, grupo_id: 98}   ->  500
matriculas DESPUES: id 3179  grupo 98                          estado PREM  prematriculado 2026-08-23  updated_by 488
```

**La escritura va al año del `grupo_id` que le manden, y el `grupo_id` viene del
cuerpo.** El guard `boletin.propio:sin-paz-y-salvo` comprueba **de quién es el alumno**
—y lo comprueba bien, por eso las doce dan 403 con uno ajeno— pero **nadie comprueba de
qué año es el grupo**. Con su propio acudido, que es lo que el guard permite, un
acudiente le cambia el estado a la matrícula **del año en curso**: de `MATR` a `PREM`,
con fecha de prematrícula y su `updated_by`.

Y la respuesta lo tapa: la pantalla recibe un 500 y `AnunciosDir.ts` lee
`r.matricula.prematriculado` en el `.then`, así que no llega nunca — **la familia ve un
error después de una escritura que sí ocurrió**, y lo natural es volver a intentarlo.

No se arregla desde este lote: `MatriculasController` no es suyo. El arreglo tiene dos
mitades y conviene separarlas, porque son decisiones distintas: **que el grupo sea del
año siguiente** es la regla del negocio, y **que no se conteste 500 después de haber
escrito** es la §69 otra vez.

### Y las dos primeras sí son un fallo, y es de familia

**`boletines2` y `boletines3` dan 500 a un acudiente y 200 al personal con el mismo
cuerpo.** El mensaje lo dice entero:

```
Undefined property: stdClass::$year_pasado_en_bol
   Boletines2Controller.php:154
   Boletines3Controller.php:156
```

`ContextoDeUsuario` monta `$this->user` con un `switch` de cuatro ramas, y **la del
Acudiente trae 43 columnas frente a 48 del Profesor, 48 del Alumno y 54 del Usuario**.
`year_pasado_en_bol` está en las otras tres y **no en la suya**.

Lo que lo convierte en el fallo de siempre es el tercer hermano: **`BoletinesController`,
la maqueta 1, lee esa misma propiedad y funciona** —el control le devolvió 47 KB—,
porque la lee así:

```php
if (isset($this->user->year_pasado_en_bol)) {      // BoletinesController:224
    if ($this->user->year_pasado_en_bol) {
```

y las otras dos la leen a pelo. **El `isset` está en uno de los tres.** Alguien se topó
con esto, arregló el fichero que estaba mirando y dejó las dos copias — y es el mismo
trío del que el docblock de `ExigirBoletinPropio` ya avisa: *«`Boletines2Controller` y
`Boletines3Controller` son copias del primero con otra maqueta y no tenían ni la
comprobación escrita, así que arreglar solo el primero habría dejado dos puertas
abiertas al mismo dato»*. **Misma familia, misma lección, otra propiedad y la dirección
contraria**: allí faltaba un candado, aquí sobra un 500.

**Qué se nota en un colegio**: una familia que abra el boletín de su hijo en la maqueta
2 o 3 recibe un 500 en vez del boletín. Las tres maquetas son pantallas de verdad —
`BoletinesApi` de `myvc_front` reparte cinco pantallas entre los tres controladores— y
el guard `boletin.propio` está en esas rutas precisamente porque las familias llegan.

**No se arregla desde este lote**: `Boletines2Controller` y `Boletines3Controller` son
del lote C. Se anota con el `isset` de la maqueta 1 delante, que es el arreglo, y con la
pregunta que va con él: **¿qué más de esas 21 columnas que le faltan a la rama del
acudiente lee alguien sin `isset`?** De las doce que miré, `year_pasado_en_bol` es la
única que lee un controlador; las otras once no las lee nadie hoy.

---

## Para Joseth

Todo lo de este lote es una decisión suya y ninguna se tomó:

1. **¿Quién es el «Secretario»?** Su rol no existe, así que `Autoriza::esAdministrativo`
   es hoy `is_superuser`. Crearlo abre de golpe las escrituras de alumnos y acudientes
   y los tres `forcedelete` a quien se lo den.
2. **¿Para qué está `Manager`?** Tiene los 16 permisos del sistema y cero usuarios.
   Meter a alguien ahí abre 16 puertas a la vez, y sólo una de ellas la lee el backend.
3. **De las seis rutas de la tabla de arriba** —contraseñas de alumnos, borrar
   profesores, borrar matrículas físicamente, contratos—, ¿cuáles debería alcanzar un
   profesor y cuáles no? Hoy las alcanza todas por `auth.personal`.
