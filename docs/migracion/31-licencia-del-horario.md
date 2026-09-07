# 31 · La licencia del programa de horarios: lo que emite esta API

> **Qué es esto.** `myvc_horarios` es el programa de escritorio con el que el
> colegio cuadra su horario (ver [23-horarios.md](23-horarios.md) para lo que
> esta API ya hace por ese módulo). Ese programa lleva una licencia firmada, y la
> decisión **25** de `myvc_horarios/docs/decisiones.md` dice quién la firma: **la
> emite `8myvc`**. Este documento es la mitad de acá.
>
> Escrito el 6 de septiembre de 2026 sobre `7c3a0b9`, en el worktree `l`
> (`feat/licencia-del-horario`). Lo que se midió se dice con la orden que lo
> midió; lo que no se midió tiene su propia sección al final.

---

## 0 · `sodium`: la pregunta se cayó con una decisión, y el hallazgo de al lado NO

**En el docker está.** PHP 8.4.24, `sodium` cargado, libsodium 1.0.22,
`sodium_crypto_sign` existe.

**Y en los diecisiete colegios ya no importa.** El 6 sep 2026 Joseth decidió la
§3.2 —la clave privada vive **sólo en la máquina desde la que se emite**—, y con
esa decisión **el único PHP que necesita `sodium` es el de esa máquina**. La
orden de abajo **ya no hace falta correrla** en las dos cuentas de cPanel; se
deja escrita porque el día que alguien proponga emitir desde un servidor, ésta es
la pregunta que tiene que contestar antes.

> **Pero lo que se encontró de camino sobrevive a esta decisión, y por eso tiene
> su propio recuadro.** Lo que sigue **ya no es un problema de licencias**: es una
> trampa abierta para **la próxima dependencia que exija una extensión de PHP**.
> Entrará sin que nada la delate al desplegar, y el síntoma será un 500 en
> producción con la suite entera en verde.

`DESPLIEGUE-REFERENCIA.md:1583` ya lleva el renglón:
*«Sin confirmar: si `sodium` y `opcache` siguen activas en 8.4 — se marcaron en
8.0 y **la selección de extensiones es por versión**»*.

Lo que este documento añade a ese renglón es **por qué ahora es peor que cuando
se escribió**, y son tres hechos medidos en el árbol:

1. Sodium se activó en producción por una razón concreta y escrita
   (`DESPLIEGUE-REFERENCIA.md:597`): **`lcobucci/jwt` la declaraba requisito duro
   y `composer install` habría fallado el chequeo de plataforma.**
2. **`lcobucci/jwt` ya no está instalado** (`ls vendor/lcobucci/jwt` → no
   existe). Se fue con `tymon/jwt-auth` al pasar a Sanctum
   (`app/Services/Sesion.php:23`). Y **ningún paquete de `vendor/` exige hoy
   `ext-sodium`**: `grep -rl '"ext-sodium"' vendor/*/*/composer.json` sale vacío.
3. **El guardián se fue con él.** `vendor/composer/platform_check.php` comprueba
   hoy `PHP_VERSION_ID >= 80401` y `PHP_INT_SIZE === 8`. **Ni una sola
   extensión.**

Juntos dicen una cosa: desapareció el requisito **y el mecanismo que lo habría
delatado al desplegar**. Hoy la API arranca y funciona en los diecisiete con
sodium ausente, y nadie se entera.

*Cuando esto se escribió, el final de ese párrafo decía que el día que alguien se
enterara sería el día que se vendiera una licencia y saliera un 500. **Con la
§3.2 decidida ya no es verdad** —de los servidores no sale ninguna licencia—, y
se corrige en vez de borrarse porque lo que queda es peor: ahora no hay ningún
síntoma previsto. La trampa dejó de tener una fecha en la que se iba a delatar
sola.*

**La orden que lo contesta**, en cada una de las dos cuentas de cPanel —`micolev1`
y `lalvirtual.edu.co`—, sin elegir colegio, porque el PHP es de la cuenta:

```bash
php -r 'echo extension_loaded("sodium") ? "SI " : "NO ", PHP_VERSION, PHP_EOL;'
```

**Si algún día sale NO** y para entonces hiciera falta, la salida está
identificada: `paragonie/sodium_compat`, que implementa Ed25519 en PHP puro y
produce **los mismos bytes**. No se ha instalado a propósito: entra en un
`vendor/` que es **symlink compartido**, o sea que entra para los diecisiete a la
vez, y eso es una decisión de Joseth y no de una sesión.

### Y el hallazgo que se queda, dicho aparte de las licencias

**Este repositorio ya no tiene ningún mecanismo que avise si a producción le
falta una extensión de PHP.** No es una hipótesis: es lo que quedó al retirar
`lcobucci/jwt`. Antes, una extensión que faltara reventaba el `composer install`
del despliegue con un mensaje que la nombraba. Hoy, la única barrera que queda
—`platform_check.php`— comprueba la versión de PHP y que sea de 64 bits, **y
nada más**, porque Composer sólo genera comprobaciones de extensión cuando algún
paquete las declara, y **ninguno lo hace**.

La consecuencia no es de este carril: **la próxima dependencia que necesite una
extensión entrará en verde y fallará en los diecisiete colegios**, y el sitio
donde se verá será un 500 en una pantalla, no un despliegue que se planta. Quien
añada un paquete con `ext-` en su `require` debería comprobar esa extensión en
las dos cuentas **antes** de desplegarlo, porque ya no lo comprueba nadie.

---

## 1 · El contrato, traducido del otro repositorio

Leído del disco —`nucleo/licencia.ts`, `escritorio/src-tauri/src/licencia.rs` y
`herramientas/emitir-licencia-de-prueba.ts`—, no de la bitácora.

### El fichero `.myvcl`

```json
{"formato": 1, "carga": "<base64>", "firma": "<base64>"}
```

- **`carga`** es base64 estándar (con relleno, alfabeto `+/` — o sea
  `base64_encode` tal cual) del **JSON en UTF-8** de la licencia.
- **`firma`** son los **64 bytes** de Ed25519 puro sobre los bytes **ya
  decodificados** de `carga`. Rust verifica con `verify_strict`.
- **`formato`** va **fuera de la firma a propósito**: la carga son los siete
  campos de la decisión 27 y ninguno es una versión, así que el hueco se reserva
  en la parte no firmada, que no pertenece a esa decisión.
- **Tope de 16 KiB** para el fichero entero (`TOPE` en `licencia.rs`). Por encima
  no dice «licencia inválida», dice «esto no parece una licencia».

**Y por qué la carga va en base64 y no como objeto JSON**, que es lo que ahorra
el error caro: **la firma se comprueba sobre bytes exactos.** Si la licencia
viajara como objeto, verificarla obligaría a reserializarla, y cualquier
diferencia de orden de claves, de espacios o de escapado —la que mete cualquier
librería— **tumbaría la firma de una licencia buena**.

**Lo que NO hay que imitar, y ahorra trabajo:** el formateo del envoltorio es
indiferente (Rust lo parsea con `serde_json` y su struct no lleva
`deny_unknown_fields`), y **el orden de los campos dentro de la carga tampoco
importa**, porque `leerLicencia()` lee por clave. Lo único sagrado son los bytes
que se firmaron, y de eso se encarga el base64.

### Los siete campos, y qué rechaza el lector

| Campo | Tipo | Se rechaza si… |
|---|---|---|
| `colegioId` | entero | no es `number`, o no es entero |
| `nombreColegio` | cadena | falta, o queda vacía al recortarla |
| `edicion` | `myvc` \| `independiente` | cualquier otra cosa. **No hay una tercera** |
| `anioEscolar` | entero | no es `number`. Es `2026`, **no** «2026-2027» |
| `caduca` | ISO 8601 \| `null` \| ausente | viene y no es cadena |
| `huella` | `string[]` \| ausente | no es una lista de cadenas |
| `emitida` | ISO 8601 | falta o viene vacía |

**El lector ignora los campos que no conoce, y es deliberado**: un emisor que
mañana añada un campo tiene que seguir funcionando contra los binarios ya
instalados en los colegios. Si rechazara lo desconocido, añadir un campo
obligaría a actualizar **todas** las instalaciones antes de emitir la primera
licencia nueva. O sea: **desde aquí se puede añadir un campo sin romper nada**.

### `caduca` y `huella` viajan firmados y NO LOS COMPRUEBA NADIE

Es la **decisión 27**, y hay que escribirla con sus palabras porque el renglón
existe para que nadie la «arregle» dentro de tres meses:

> **Ni activación ni caducidad, por ahora — y es una decisión informada, no un
> olvido.** Se le advirtió a Joseth y lo reafirmó: sin ninguna de las dos, **la
> firma sólo impide fabricar licencias**, y **una licencia legítima copiada a
> otro colegio funciona indefinidamente sin que nadie se entere.**

Los dos campos viajan **desde la primera licencia** porque reservar el hueco
ahora es gratis y después es carísimo: reemitir todas las licencias vivas más un
binario que entienda dos formatos. En el otro lado hay pruebas que se ponen
**rojas** si alguien intenta encender la caducidad sin querer; en éste, la prueba
`sin_caduca_ni_huella_los_campos_viajan_igual` hace lo propio si alguien los quita
por no usarse.

### Qué hace la licencia, que son dos cosas y sólo dos

1. **Decide la edición.** Y la diferencia entre las dos es **una sola**:
   `puedeHablarConMyvc`. La edición `myvc` puede importar de MyVC y subirle
   versiones; la `independiente` no le pide un dato al servidor en ninguna
   pantalla (decisión 14). **Sin licencia la edición es `null`, que no es lo
   mismo que ser independiente.**
2. **Pone nombre y número al pie del horario impreso** (decisión 25).

### El pie del papel, que es lo que esta API alimenta

- **`aNombreDe`** = `nombreColegio` tal cual, sin normalizar ni recortar. Sale de
  nuestra firma, y acaba impreso en las **22 hojas** del horario por docente.
- **`numero`** = **hoy NO lo mandamos.** Se **compone** en su lado como
  `MYVC-2026-0007` / `IND-2026-0007` a partir de edición + año + `colegioId`,
  porque ninguno de los siete campos es un número de licencia. Está anotado allí
  como pregunta abierta para Joseth, no como diseño. **Y esa composición tiene
  una consecuencia que muerde aquí — ver la §3.**
- **El pie NO afirma que la licencia esté vigente**, porque la vigencia no se
  calcula en ninguna parte del programa (§27).

---

## 2 · Lo medido: que esta API puede producir bytes que ese programa acepta

Esto se comprobó **antes de escribir el producto**, y es lo que separa este
documento de un plan.

### El vector de referencia, y por qué vale

El arnés de TypeScript del otro repositorio tiene una opción `--emitida` que hace
su salida **reproducible byte a byte**. Se emitió con ella la licencia que su
propio verificador de Rust lleva **pegada dentro**, en la constante
`EMITIDO_POR_EL_ARNES` de `licencia.rs`, que su prueba
`lo_que_emite_el_arnes_de_typescript_lo_verifica_esto` mete por
`verificar_licencia()`:

```bash
node herramientas/emitir-licencia-de-prueba.ts --colegio "Colegio Simón Bolívar" \
  --id 3 --caduca 2025-01-01 --huella maquina-1 \
  --emitida 2026-09-03T11:16:43.818Z --salida /tmp/rehecha.myvcl
```

Después se produjo **la misma licencia en PHP** con `sodium`, derivando la clave
de la misma semilla. Resultado, y es el hallazgo entero:

    carga   IDÉNTICA byte a byte
    firma   IDÉNTICA byte a byte

Y se comprobó que la firma resultante **es la que está literalmente escrita en el
verificador de Rust** (`grep -c` de la firma en `licencia.rs` → 1). O sea: **los
bytes que produce este PHP ya los verifica una prueba verde de Rust.**

Los dos indicadores de `json_encode` que hacen falta —y salieron de medir, no de
elegir— son `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Sin el segundo,
«Simón Bolívar» viaja como `Simón`, que es JSON válido y **se lee igual**;
lo que se pierde no es la corrección, es la **comparabilidad** con el otro
emisor, que es lo único que hace comprobable esta traducción.

### El camino entero, recorrido

La licencia emitida por `php artisan licencia:emitir` se metió por el núcleo real
del otro repositorio (`nucleo/licencia.ts`, importado del disco):

    formato           1
    firma valida      true
    firma mide        64 bytes
    leerLicencia      valida
    edicion           myvc | habla con MyVC: true
    al pie            Colegio Simón Bolívar · Licencia MYVC-2026-0003
    aNombreDeQuien    este-colegio        ← contra «COLEGIO SIMON BOLIVAR»

El último renglón vale la pena: la comparación de nombres **ignora mayúsculas y
tildes a propósito**, porque un «licenciada a otro colegio» impreso en 22 hojas
por una tilde manda a alguien a llamar a soporte.

> **Y ese renglón comprueba menos de lo que parece, así que va acotado.** El
> segundo argumento —`COLEGIO SIMON BOLIVAR`— es una **cadena escrita a mano en el
> script de la medición**, elegida para ver el efecto de las tildes. **No hubo
> ningún proyecto abierto**: lo único que se leyó del disco fue la licencia
> emitida. O sea que esto mide `comoSeEscribe()` y **no** mide que
> `Proyecto.colegio` llegue hasta ahí con el valor que debe — si el proyecto
> hubiera sido de otro colegio, esta comprobación no se habría enterado, porque el
> proyecto no participaba. Esa mitad la tiene que probar el otro repositorio con
> un proyecto conocido.

**Y `--publica` reproduce exactamente los 32 bytes que hoy lleva incrustado
`licencia.rs`** (`57, 240, 186, 1, …`), que es la autoprueba de que el
formateador de esa salida no miente.

### El límite de estas medidas, dicho aparte

- **No se ejecutó `cargo test`.** La aceptación por Rust es **transitiva**: los
  bytes son idénticos a los de un fichero que una prueba verde suya ya verifica.
  Es un argumento fuerte, y aun así la frase que lo acota es de ellos y es la
  buena: **hoy nadie ha visto al binario aceptar una licencia hecha por
  nosotros.** Está **aplazado, no descartado** —tres carriles suyos compiten por
  el lock de `target/`—, y el día que corran, este renglón cambia de transitivo a
  medido **con el hash contra el que corrió**.
- **No se abrió el programa.** Nada de esto dice que la aplicación empaquetada
  acepte una licencia nuestra; eso está en su lado y sigue pendiente allí.
- **No se midió producción.** Ver la §0.

---

## 3 · Las decisiones que faltan, con el precio delante

### 3.1 · `colegioId` NO EXISTE EN ESTA API, y eso contesta una pregunta que ellos ya tenían escrita

En `myvc_horarios` está anotado, en `nucleo/licencia.ts` y en su carril, que
`colegioId` se lee como **«el id del colegio en los libros de `8myvc`, que es
quien emite»**, y que por eso se declaró `number` y no `number | null`. Su propio
documento avisa de la consecuencia: *si resultara que un colegio de la edición
independiente no existe en `8myvc`, el campo sería NULLable y
`numeroDeLicencia()` se quedaría sin la única parte que distingue una licencia de
otra.*

**Medido aquí, y la respuesta es que esos libros no existen:**

- **No hay tabla de colegios.** Las 90 tablas del volcado y ninguna es un
  registro de colegios.
- **`grep -rn 'colegio_id' app/` sale vacío.** No existe el concepto.
- **Cada colegio ES una base de datos.** No hay ningún sitio donde estén todos, y
  por eso `HorarioController` ya avisa de que *«`years.id` 8 es 2025 en un colegio
  y 2019 en otro»*: **ningún identificador de esta base sirve para nombrar un
  colegio fuera de él.**

Lo que sí hay es el nombre y un identificador nacional, los dos en `years` y por
tanto **por año**:

| Columna | En el docker | Sirve porque | No sirve porque |
|---|---|---|---|
| `years.nombre_colegio` | `COLEGIO ADVENTISTA SIMÓN BOLIVAR` | es el nombre que ya se enseña | es texto libre, editable y **por año** |
| `years.codigo_dane` | `305001010358`, igual en los 9 años | es **nacional, estable y externo** | es `varchar(255)` **NULLable**, y puede faltar |

**Las opciones, con su precio:**

- **(a) El código DANE.** Es el identificador real del colegio en Colombia, no se
  lo inventa nadie y sobrevive a que el colegio se renombre. **Precio:** es
  NULLable y no está garantizado en los diecisiete; y **rompe el número del pie
  impreso** — `numeroDeLicencia()` rellena `colegioId` a cuatro cifras, así que
  saldría `MYVC-2026-305001010358`, que **no es el diseño que aprobó nadie**. Un
  colegio independiente además puede no tener DANE cargado.
- **(b) Un número que asigna Joseth**, del 1 al 17, escrito a mano al emitir. **Precio:**
  hay que llevar la cuenta fuera del sistema, y dos licencias con el mismo número
  no las caza nadie. **Ventaja:** `MYVC-2026-0007` sigue saliendo como se diseñó,
  y funciona igual para un colegio independiente, **que es el caso que no tiene
  base de datos aquí.**
- **(c) `years.id`.** **Descartada, y no es cuestión de gusto:** es distinta por
  año y por colegio, así que el mismo colegio tendría un `colegioId` distinto cada
  año y dos colegios compartirían el mismo. Se deja escrita como descartada para
  que no se vuelva a proponer.

**DECIDIDO POR JOSETH EL 6 SEP 2026: la (b).** Un número que asigna él. La razón
que la ganó no es la comodidad: **la licencia tiene que poder emitirse para un
colegio que no está en ninguna base de datos de `8myvc`** —el caso
`independiente`—, y (a) sólo existe si el colegio ya es cliente de MyVC. Un
identificador que no sirve para la mitad de los casos de uso no es el
identificador. Con ella, el pie del papel sigue saliendo `MYVC-2026-0007` como se
diseñó.

> **Y esto es el precio aceptado, no un descuido: la cuenta de los números se
> lleva FUERA DEL SISTEMA, y dos licencias con el mismo número no las caza
> nadie.** No hay tabla, no hay restricción de unicidad y **no hay nada en este
> repositorio que pueda comprobarlo** — precisamente porque no existe un sitio
> donde estén todos los colegios, que es lo que se midió arriba. Se escribe aquí
> para que quien lo lea dentro de tres meses **no lo tome por un agujero que se le
> escapó a alguien** y se ponga a «arreglarlo» inventando un registro central: ese
> registro es justamente lo que esta API no tiene y no va a tener.

### 3.2 · Dónde vive la clave privada — y la pregunta no es la que parecía

La pregunta llegó como *«¿una clave por colegio o una para todos?»*. **Medido, no
es una pregunta: sólo puede haber una.** `verificar_licencia` en `licencia.rs`
verifica contra `const PUBLICA: [u8; 32]`, **una sola constante incrustada en el
binario**. Diecisiete claves privadas exigirían diecisiete públicas y el binario
tiene sitio para una. Así que **en todo el sistema existe exactamente una clave
privada**, y la pregunta de verdad es **dónde se pone**.

Y hay un segundo hecho que estrecha la respuesta: **un colegio de la edición
independiente no tiene despliegue de `8myvc`.** Por decisión 14, en esa edición
*ninguna pantalla le pide un dato al servidor*. Su licencia no la puede emitir «su»
API porque **no existe**.

Con esos dos, las opciones:

- **(a) La clave en los diecisiete `.env`.** **Precio, y es el que la descarta:**
  pone la clave maestra de firma de todo el producto en diecisiete hostings
  compartidos. Comprometer **uno cualquiera** de los diecisiete da la capacidad de
  fabricar licencias para todos los demás — que es exactamente lo único que la
  firma existía para impedir. Y hay un precedente medido en este repositorio de
  que los secretos por colegio no son de fiar: **`APP_KEY` puede estar repetida
  entre colegios y nadie lo ha comprobado.** Además no resuelve el caso
  independiente.
- **(b) La clave sólo en la máquina desde la que se emite** (la de Joseth), y el
  comando se corre ahí. **Precio:** hay que custodiar un fichero y respaldarlo —
  si se pierde, **no se pueden emitir licencias nuevas nunca más sin reinstalar
  los diecisiete colegios**, porque su clave pública está incrustada en el
  binario. **Ventajas:** la clave no viaja a ningún servidor, no está en ningún
  respaldo de cPanel, funciona igual para un colegio independiente, y encaja con
  el espíritu de la decisión 26 —*el generador se queda en local*—. Y hace
  **irrelevante la §0**: el sodium que importa es el de una sola máquina.

**DECIDIDO POR JOSETH EL 6 SEP 2026: la (b).** La clave privada vive **sólo en la
máquina desde la que se emite**: **nada en los diecisiete `.env`, nada en ningún
respaldo de cPanel**. Es también la razón por la que esto se escribió como
comando de consola y no como ruta, y la que dejó sin objeto la pregunta de la §0.

**LO QUE SIGUE ABIERTO Y ES DE JOSETH: dónde se respalda esa clave.** Va aparte
porque es la mitad que la decisión anterior no contesta, y es la cara:
**perderla es peor que filtrarla.** Filtrarla se arregla emitiendo una clave nueva
y actualizando el binario; **perderla obliga a lo mismo sin poder emitirle una
licencia a nadie mientras tanto**.

> **Y hasta que eso se conteste, la clave de producción NO SE FABRICA.** Fabricarla
> es irreversible en un sentido concreto: su pública se incrusta en los binarios que
> se instalan en los colegios, así que cambiarla después obliga a reinstalarlos. El
> comando imprime la orden exacta para el día que se decida.

### 3.3 · Quién puede pedir una licencia

**Con la forma de comando, hoy no se plantea**: quien tenga acceso a la consola y
al fichero de la clave. No hay guard que elegir porque no hay petición HTTP.

Si algún día se decide que haga falta una ruta —y **no se ha escrito ninguna**—,
el criterio que ya existe y que se propuso es `Autoriza::puedePublicarHorario()`,
que **no es `esSuperusuario`**: es superusuario **o** coordinador académico
(`app/Support/Autoriza.php:288`), el mismo que ya gobierna publicar la versión
oficial del horario. Pero conviene decir que **para emitir una licencia
probablemente sea demasiado ancho**: publicar el horario del propio colegio y
emitir una licencia del producto no son la misma clase de acto, y el segundo lo
hace quien vende, no quien coordina.

---

## 4 · Lo que se escribió, y lo que se dejó sin escribir a propósito

**Escrito:**

| Fichero | Qué es |
|---|---|
| `app/Services/FirmaDeLicencia.php` | Firma, y sólo eso. **La clave entra por constructor**, para no congelar la §3.2 |
| `app/Console/Commands/EmitirLicencia.php` | `licencia:emitir`, y `--publica` para los 32 bytes de Rust |
| `config/licencia.php` | Una **ruta a un fichero**, no la clave |
| `tests/Unit/FirmaDeLicenciaTest.php` | 11 pruebas. Fijan el **vector de referencia** |

> **Sobre dos nombres, porque se apartaron de lo propuesto.** El servicio se llamó
> `FirmaDeLicencia` y no `Licencia` porque **sólo firma**: no decide quién tiene
> derecho a una, y un `Licencia` a secas se lee como un modelo y deja sin nombre a
> la política del día que la haya. Y las pruebas viven en `tests/Unit` y no en
> `tests/Contrato` porque **no tocan la base de datos**: en `Contrato` obligarían a
> tener la base construida para comprobar una serialización, y ahí lo que se fija
> es que una respuesta de la API no cambió, que no es esto.

### Y una variable que se llamaba `$obligatoria` y puso roja la suite

Vale la pena porque es un modo de fallo, no una anécdota. `EmitirLicencia.php`
recorría sus dos opciones obligatorias con `foreach (… as $obligatoria)`, y
`CensoDeInterruptoresTest` se puso rojo: **93 → 92**.

`obligatoria` es una columna `tinyint(1)` del esquema, y ese censo cuenta *los
interruptores que no lee nadie* buscando el nombre de cada columna en `app/`,
`routes/`, `config/` y los seeders, con una condición SQL delante. Una variable
local de un comando de licencias, con un `if` al lado, **se hizo pasar por el
lector de una columna del colegio** y la sacó del montón de las que no lee nadie.

**El arreglo es renombrar la variable, y la tentación era subir la constante.**
Subirla habría escondido el fallo *y* arrastrado al §105, que cruza esa población
con los cuatro clientes para llegar al 49 y al 53 — números que **ningún test de
este repositorio puede volver a medir**. Es la regla del `CLAUDE.md` en su
versión más literal: **el primer sitio donde mirar cuando el número sale raro es
el detector**, y aquí el detector tenía razón: alguien había escrito ese nombre
donde no era.

*Cómo se separó de lo demás, porque el método importa: se apartaron los cuatro
ficheros nuevos a otro directorio y se volvió a correr. Pasó. O sea que era mío,
y no un rojo heredado del árbol ni una base a medio migrar — que era la otra
explicación plausible y la que habría hecho archivar el asunto.*

**Sin escribir a propósito:**

- **Ninguna ruta.** Una ruta nueva es una decisión de Joseth.
- **Ninguna clave.** Fabricar la clave de producción es un acto irreversible en
  un sentido concreto: **su pública se incrusta en el binario que se instala en
  los colegios**, así que cambiarla después obliga a reinstalarlos. El comando
  imprime la orden exacta para fabricarla cuando se decida la §3.2.
- **`paragonie/sodium_compat` no se instaló.** Entra en un `vendor/` compartido,
  o sea para los diecisiete a la vez.
- **Nada que lea el colegio de la base.** El comando pide `--id` y `--colegio`
  explícitos: rellenarlos desde `years` presupondría la §3.1 y además no
  funcionaría para un colegio independiente.

---

## 5 · Lo que este documento NO midió

- **Si `sodium` está en el PHP de producción.** Y ya **no se va a medir**: con la
  §3.2 decidida, ese PHP no firma nada. Lo que **sí** queda sin medir y no es de
  este carril es si alguna extensión falta hoy en las dos cuentas — ver el
  recuadro de la §0, que ya no habla de licencias.
- **Si el programa empaquetado acepta una licencia nuestra.** Requiere la clave de
  producción, que no existe, y abrir la aplicación.
- **`cargo test` no se corrió.** La aceptación por Rust es transitiva; ver el
  límite en la §2.
- **Cuánto tarda emitir.** No se midió y no importa hoy: son diecisiete emisiones
  en la vida del producto.
- **Si algún colegio tiene `years.codigo_dane` NULL o repetido.** Se midió **un
  solo colegio** —`simonbolivar` en el docker, nueve años, mismo DANE—, y eso no
  es una población. Si se elige la opción (a) de la §3.1, **hay que contarlo en
  los diecisiete antes**.

---

## 6 · Peticiones abiertas del otro repositorio a éste

De «Peticiones a `main`» de su carril, quedan vivas dos y las dos son de Joseth:

1. **Los 32 bytes de la clave pública de `8myvc`.** **Bloquea que puedan
   empaquetar nada para un colegio.** Hoy el binario incrusta una clave **de
   desarrollo** cuya semilla está publicada en su repositorio, así que
   *cualquiera que lea `myvc_horarios` puede fabricar hoy una licencia que ese
   binario acepte*. Es correcto mientras no se empaquete para un colegio y deja
   de serlo el minuto después. En cuanto se decida la §3.2, esos 32 bytes salen
   de `php artisan licencia:emitir --publica`, ya formateados para pegar.
2. **Las dos preguntas de las §3.1 y §3.2**, que son las mismas que ellos tienen
   anotadas como `NOTAS` al pie de `nucleo/licencia.ts`.
