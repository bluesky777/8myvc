# El título de los dos certificados, que estaba escrito dentro de la plantilla

**15 sep 2026.** Encargo de Joseth:

> *«En legacy y en app2 hay 2 certificados. Informes - Finales - Certificado final y
> Informes - Finales - Certificado periodos. Los títulos son iguales, CONSTANCIA DE
> DESEMPEÑO, aunque en el front creo que tiene un condicional para ese título
> hardcodeado. Necesito que agreguemos esos títulos como textos del año […] que por
> defecto sea el que ya se muestra.»*

La corazonada era cierta y **se quedaba corta por los dos lados**: el condicional
existe, no es uno sino dos —uno en cada front—, y lo que deciden **no es el mismo
texto**.

---

## §1. Lo que se imprimía, medido en los dos repositorios

| front | fichero | `coal` y `coljordan` | los otros catorce |
|---|---|---|---|
| legacy | `app/scripts/informes/certificadoEstudioDir.html:11` | `CERTIFICADO DE DESEMPEÑO` | `CONSTANCIA DE DESEMPEÑO` |
| `app2` | `app2/src/app/informes/certificado-estudio/certificado-estudio.html:41` | `CERTIFICADO DE DESEMPEÑO` | `CONSTANCIA DE DESEMPEÑO **ACADÉMICO**` |

Tres textos, no uno. Y **dos hallazgos que el encargo no pedía**:

1. **Los dos fronts ya se contradicen para los mismos catorce colegios.** `app2`
   añadió «ACADÉMICO» al migrar la pantalla y nadie lo notó, porque los dos papeles
   no se miran nunca juntos. No hay forma de saber cuál de los dos es el que el
   colegio quiere: sólo una decisión.

2. **El condicional no mira al colegio: mira la URL.**

   ```ts
   // legacy — CertificadoEstudioDir.ts:76
   scope.isCoalSchool = () => document.domain.includes('coal.micolevirtual.com');

   // app2 — certificado-estudio.ts:218
   const CON_CABECERA_PROPIA = ['coal.micolevirtual.com', 'coljordan.micolevirtual.com'];
   ```

   Un título que depende de `document.domain` **no lo puede cambiar el colegio**, no
   se puede probar sin cambiar de host y no aparece en ninguna pantalla de
   configuración. Es la forma exacta del problema que esto viene a resolver.

**Y «los dos certificados» comparten plantilla.** En el legacy son una sola
directiva (`certificadoEstudioDir`) que se pinta desde dos estados; en `app2`, un
solo componente (`CertificadosEstudio`) con dos rutas —`informes/certificados-estudio/:grupo_id`
y `informes/certificados-estudio-periodo/:grupo_id/:periodo_a_calcular`—. O sea que
**su título es el mismo por construcción y no porque nadie lo haya decidido.**

## §2. Dónde viven los títulos, y por qué no en la tabla de certificados

El encargo apuntaba a *«una tabla que guarda los certificados»*. Existe
—`config_certificados`— y **no sirve**, por dos medidas:

- **Es el membrete, no el certificado.** Sus columnas son `encabezado_img_id`,
  `piepagina_img_id` y sus anchos y márgenes. El texto del papel nunca ha estado
  ahí: `encabezado_certificado` y `frase_final_certificado` son **columnas de
  `years`** desde siempre.
- **El año elige UNA sola fila de esa tabla** (`years.config_certificado_estudio_id`),
  y aquí hacen falta **dos títulos vivos a la vez**. No caben.

A lo que se suma lo que decide el caso: el título tiene que ser **del año**, porque
un año cerrado conserva el suyo para siempre y de los años cerrados se siguen
pidiendo certificados —que es el caso de uso entero de esta pantalla—. Si viajara
con el membrete, cambiar el membrete reescribiría el título de los certificados de
2019.

**Decisión: dos columnas en `years`**, pegadas a `frase_final_certificado`.

```
titulo_certificado_final     varchar(255) NOT NULL DEFAULT 'CONSTANCIA DE DESEMPEÑO ACADÉMICO'
titulo_certificado_periodos  varchar(255) NOT NULL DEFAULT 'CONSTANCIA DE DESEMPEÑO ACADÉMICO PARCIAL'
```

**Son dos y no una** porque son dos papeles: uno certifica el año cerrado y el otro
«hasta el periodo que usted elija». Y una sola columna congelaría para siempre un
empate que era **un accidente de la plantilla compartida** — cosa que se ve mejor
desde el §4.bis, donde ese empate se deshace.

## §3. `NOT NULL` con defecto, y no anulable

`ADD COLUMN ... NOT NULL DEFAULT` rellena las filas que ya existen, así que los años
cerrados quedan con el título puesto sin tocar una fila.

No nacen `NULL` porque **un certificado sin título no es un estado que exista**: el
`@if` que lo leyera tendría que inventarse un texto de respaldo, y ese texto sería
otra vez una cadena escrita dentro de la plantilla — justo lo que se está quitando.
Por lo mismo, la escritura **rechaza la cadena vacía**.

## §4. La decisión que cambia papel, y va aquí porque es la cara

Las dos últimas columnas de políticas del año —`modelo_evaluacion` (doc 35) y
`reparto_subunidades` (doc 28 §5.5)— nacieron con un defecto que **afirmaba «esto es
lo que hacen hoy los dieciséis»**, y no movían un papel. **Ésta no puede hacer esa
afirmación**, porque hoy no hay un texto: hay tres (§1).

Joseth eligió el 15 sep 2026, con la tabla del §1 delante:

| | decisión | consecuencia el día del despliegue |
|---|---|---|
| defecto del **final** | `CONSTANCIA DE DESEMPEÑO ACADÉMICO` | el **legacy gana «ACADÉMICO»** en los catorce colegios |
| defecto del **parcial** | `CONSTANCIA DE DESEMPEÑO ACADÉMICO PARCIAL` | los dos papeles **dejan de decir lo mismo** — §4.bis |
| `coal` y `coljordan` | **amanecen con los defectos como todos** y los corrigen desde la pantalla | su certificado dice «CONSTANCIA…» hasta que alguien entre a cambiarlo |

O sea que **los dieciséis certificados cambian de título el día del despliegue**. Es
una decisión tomada con el precio delante, no un efecto secundario, y está escrita
en tres sitios —aquí, en el docblock de la migración y en `Year::TITULO_POR_DEFECTO`—
porque quien lea esto dentro de un año tiene que poder distinguir *«se decidió»* de
*«se coló»*.

> **Se descartó la opción segura, y conviene saber cuál era.** La alternativa era
> dejar las columnas vacías y que el front siguiera aplicando su condicional cuando
> no viniera texto: cero papeles movidos, y el colegio que escribiera el suyo ganaba
> el suyo. Se descartó porque **deja el título colgando del dominio**, y entonces
> `coal` y `coljordan` siguen sin poder cambiar el suyo — que es el problema original
> con una capa encima. *La salida barata no era la misma decisión con menos trabajo:
> era otra decisión.*

> **Y NO se les avisa.** Joseth, el 15 sep 2026, sobre la propuesta de avisarles:
> *«no importa lo de avisar»*. Queda escrito porque es lo contrario de lo que este
> documento recomendaba dos líneas más arriba, y **una recomendación retirada que no
> se tacha se lee como pendiente para siempre**: la siguiente sesión pararía el
> despliegue buscando un correo que nadie tiene que mandar.
>
> Lo que la sostiene es que el cambio es **visible y reversible desde la propia
> pantalla que entra con él**: quien abra el certificado ve el título nuevo, y
> corregirlo son dos campos. No es el caso de un cambio que se nota tarde —un puesto
> que se mueve, un consecutivo que salta—, que es cuando avisar sí compra algo.

## §4.bis. Y el parcial deja de decir lo que el final, que el encargo no pedía

**Joseth, el mismo 15 sep**, sobre la primera versión de esta entrega —que les había
puesto el mismo defecto a las dos columnas—: *«creo que es mejor poner otro texto por
defecto en el de periodos»*.

Tiene razón y la medida está en el botón: «Certificado periodos» es el que **«calcula
hasta el periodo que usted elija»** (`tabFinales.html`, el `uib-tooltip` del tipo 4).
O sea que se emite con **el año sin cerrar**, y un título que no lo diga afirma en
papel firmado algo que no es.

**Lo que hacía que nadie lo notara es justo lo que arregla esta entrega**: los dos
papeles decían lo mismo **porque comparten plantilla**, no porque nadie hubiera
decidido que dijeran lo mismo. En cuanto el título deja de estar en la plantilla, el
empate hay que sostenerlo a propósito — y no se sostiene.

La palabra que los separa es `PARCIAL`, y las dos conservan «constancia de
desempeño», que es el término del **Decreto 1290 art. 17**: el artículo habla
precisamente de las constancias «con los resultados de los informes periódicos», así
que la palabra le corresponde al parcial **todavía más** que al final
([21 §1](21-certificados-y-folios.md)).

Lo sujeta un `assertNotSame` en
`TitulosDelCertificadoTest::el_defecto_del_modelo_y_el_de_la_base_son_el_mismo`: si
alguien vuelve a igualarlos, se pone rojo con el motivo escrito. *Un empate que fue un
accidente y ahora es una decisión necesita algo que lo distinga de una copia mal
hecha.*

## §5. Quién los escribe: ninguna ruta nueva

Se escriben por **`PUT certificados/encabezado`**, que es la ruta de los textos del
certificado del año —hoy escribe `encabezado_certificado`— y la sirve **la misma
pantalla** que ya tiene ese cuadro: *Colegio → Configurar certificados*.

Ninguna ruta nueva, así que **no se mueve el contador de `CLAUDE.md` ni los tres
snapshots** (`rutas.json`, `guards-por-ruta.json`, `guard-por-familia.json`). Es lo
que se hizo con `reparto_subunidades` en `putModeloEvaluacion` y por el mismo
motivo.

`auth.personal`, el guard que ya tenía. **No lleva permiso propio**, y la diferencia
con `modelo_evaluacion` —que sí lo lleva— es real y no una inconsistencia: aquélla
cambia **cómo se calcula y qué se pide escribir** en el colegio entero; ésta cambia
**un rótulo de un papel**, al lado del párrafo de encabezado que cualquier docente
ya podía reescribir desde el 21 de agosto. Ponerle un permiso nuevo a un rótulo y
dejar el párrafo de al lado abierto no protegería nada.

### §5.1. Y con ella cambia algo que este método hacía mal

Hasta hoy `putEncabezado` escribía `encabezado_certificado` **viniera o no en el
cuerpo**, así que un `PUT` que no lo mandara lo borraba. No era una decisión como la
de `putUpdate()` con la imagen del membrete —donde el borrado es la función y hay un
test que lo fija—: era el único campo que había y el único cliente lo mandaba
siempre.

Con tres campos eso sería un **destructor silencioso**: la pantalla que guarda un
título borraría el encabezado del certificado, que es papel firmado. Ahora **cada
campo que viene se escribe y el que no viene no se toca**, que es la forma de
`putModeloEvaluacion`; y **sin ningún campo es 422**, no un 200 que no escribió nada
(la familia de `tools/respuestas-que-mienten.py`).

El **404 sigue yendo antes que el 422**: `findOrFail` es la primera línea, porque un
año que no existe no es un cuerpo mal formado — y porque es lo que fija
`ConfigCertificadosTest::test_un_id_que_no_existe_es_404`.

## §6. La puerta de al lado, cerrada por mecanismo

`PUT years/toggle-cambiar-valor` escribe **cualquier columna de `years` que exista**
con el mismo `auth.personal`. Sin tocarla, la comprobación de «un título no puede ir
vacío» sería un cartel con la puerta abierta al lado.

Así que los dos títulos quedan excluidos ahí, y es un **tercer motivo** de exclusión
—por eso es una lista aparte y no dos renglones más en la que ya había—:

| columna | motivo |
|---|---|
| `actual` | invariante **de fila**: uno solo encendido, y `years/set-actual` lo mantiene |
| `modelo_evaluacion`, `reparto_subunidades` | **dueño**: `can_edit_plantilla_notas` dentro de `years/modelo-evaluacion` |
| `titulo_certificado_final`, `titulo_certificado_periodos` | invariante **de valor**: no vacío y 255, y `certificados/encabezado` lo mantiene |

No les quita el campo a nadie —quien llega ahí ya podía escribir el título por la
ruta buena—: lo que evita es que se escriba **sin pasar por la validación**. Es la
lección de `modelo_evaluacion` aplicada antes de cometerla: *al ponerle una regla a
una columna se repasan todos los caminos que escriben esa tabla, no sólo el que se
está tocando.*

## §7. Cómo llega al papel, que es lo que hay que comprobar

El certificado recibe el año desde `PUT bolfinales/detailed-notas-year/{grupo}` y
`…-year-group/{grupo}`, que lo montan con **`Year::datos()`** — una proyección
nombrada, escrita a mano y **duplicada en dos ramas** (`$actual = true` y `false`).
Eran **36 de las 74 columnas** el día de la medición; con éstas, **38 de 76**.

O sea que **una columna de `years` no llega sola a este papel**. Las dos entran en
las dos ramas, y lo que lo sujeta es un test que cuenta las apariciones: arreglar una
rama y dejarse la otra es el fallo natural aquí, y desde la pantalla se ve como «en
los años pasados el título sale vacío» — que es justo el año del que se piden los
certificados viejos.

## §8. La herencia al año nuevo

`YearsController::postStore` copia las dos del año anterior, pegadas a
`encabezado_certificado`. Sin eso, **el colegio que escribió el suyo amanecería con
el defecto cada enero**.

Aquí muerde más que en sus vecinas de esa lista: `coal` y `coljordan` nacen con un
título que no es el que quieren y lo corrigen a mano (§4), así que sin la herencia
**lo corregirían otra vez todos los años**.

`CentinelaDeLasColumnasDelAnioNuevoTest` comprueba que las columnas estén
**nombradas** en ese método; que además **hereden el valor** lo comprueba
`TitulosDelCertificadoTest::el_anio_nuevo_hereda_los_dos_titulos`, porque una columna
escrita como `Request::input('x')` pasa el centinela y no hereda nada.

## §9. Lo que se mueve

- **6 instantáneas** llevan la fila entera de `years` y se mueven con la migración:
  `muestreo-years.json`, `muestreo-years-colegio.json`, `muestreo-years-trashed.json`,
  `years-store.json`, `years-delete.json` y `years-guardar-cambios.json`. Medido con
  `tools/lo-que-reparte-una-columna.py years` **antes** de escribirla.
- **19 instantáneas** llevan la proyección de `Year::datos()` y **serían inmunes**:
  las mueve la edición del modelo (§7), no la migración. Son las de `boletines`,
  `boletines2`, `boletines3`, `boletines-competencias`, `bolfinales`,
  `bolfinales-preescolar`, `puestos`, `grupos-grupos-con-disciplina`,
  `muestreo-informes-datos`, `muestreo-notas-actuales-alumnos` y
  `muestreo-piars-config`.

> **La predicción se cumplió exacta, y eso es lo que acredita la herramienta.** La
> suite entera con la migración dentro dio **25 rojos y los 25 eran instantáneas** —
> ni uno de lógica—, y son las 25 nombradas arriba. Regeneradas, el diff es
> **`25 files changed, 50 insertions(+)`**: dos líneas por fichero y **cero borrados**,
> que es la forma que tiene que tener el diff de una columna aditiva. Un borrado ahí
> habría significado que regenerar estaba enterrando otra cosa.

### §9.1. Y un rojo número 26 que NO era de esta entrega

`HuecosDelSeedTest` salió rojo en la corrida de la regeneración diciendo que
`muestreo-notas-actuales-alumnos` **había perdido un hueco**
(`alumnos.periodos.asignaturas_perdidas`). Su propio docblock dice que eso se lee como
una mejora: *«alguien cubrió esa parte, se regenera y se celebra»*.

No era ninguna de las dos cosas que ese test contempla. Ese test **lee el directorio de
snapshots**, y va antes que `MuestreoDeLecturasTest` en la corrida: cuando miró, el
fichero estaba borrado a propósito para regenerarlo, y **un fichero que no existe no
aporta huecos**. Corriéndolo solo, con los 25 ya escritos, pasa sin tocar nada.

Queda escrito **en el test y no sólo aquí**, porque el sitio donde hace falta es el
docblock que lo explica: regenerar en ese momento habría enterrado un hueco real, y el
siguiente que lo viera no sabría cuándo se fue.
- **Ni el contador de rutas ni los tres snapshots de rutas**: no hay ruta nueva (§5).
- **`database/schema/mysql-schema.sql` NO se toca**: el volcado es el esquema
  congelado y las columnas que entran por migración no están ahí a propósito — es lo
  que hace que `CentinelaDeLasColumnasDelAnioNuevoTest` mida contra `SHOW COLUMNS` y
  no contra el volcado.

## §10. Lo que le toca al front, y por qué no puede salir antes

En los dos repositorios: **quitarle el título al condicional de dominio** y pintar
`year.titulo_certificado_final` o `year.titulo_certificado_periodos` según la
pantalla, más el par de campos en *Colegio → Configurar certificados*.

> **Y «quitarle el título» no es «borrar el condicional», que es lo que sale solo al
> leer esto deprisa.** Medido en los dos fronts: esas funciones gobiernan **cinco
> sitios más**, no uno.
>
> | | dónde | qué decide |
> |---|---|---|
> | legacy | `certificadoEstudioDir.html:11` | **el título** ← el único que se toca |
> | legacy | `:22`, `:23` | «CERTIFICA QUE» frente a «Hacen constar» |
> | legacy | `:31`, `:37` | si sale el «Nro Matrícula» |
> | `app2` | `certificado-estudio.html:40` | **el título** ← el único que se toca |
> | `app2` | `certificado-estudio.html:123` | «CERTIFICA QUE» frente a «HACEN CONSTAR QUE» |
>
> `isCoalSchool()`, `isColJordanSchool()` y `cabeceraPropia()` **se quedan**. Borrarlas
> cambiaría cuatro cosas más del papel de esos dos colegios, y ninguna de las cuatro
> está en este encargo.

**El front no se publica hasta que la API esté desplegada**, no sólo fusionada:
`app/` es copia real en cada colegio (`CLAUDE.md`), y un front que lea
`year.titulo_certificado_final` contra una API sin la columna pinta **una cabecera en
blanco** en un papel que el colegio firma. El orden es: migración desplegada colegio
a colegio → front.

## §11. Lo que se vio de camino y NO entra aquí

**`years.frase_final_certificado` no la escribe nadie.** Se imprime en los dos fronts
—legacy `certificadoEstudioDir.html:128`, `app2` `certificado-estudio.html:321`— y en
toda la API la única escritura es la copia al año siguiente de `postStore`. No hay
pantalla, no hay ruta, no hay cliente: es **`profesores.tono` antes del 4 sep** con
otro nombre, y el colegio que quiera cambiar la frase de cierre de su certificado
sólo puede hacerlo con un `UPDATE` a mano.

Se deja escrito y **no se arregla aquí**, porque es otra decisión —¿va en la misma
pantalla?, ¿el mismo `PUT`?— y meterla en esta entrega la convertiría en dos. El sitio
donde entraría sin discusión es `putEncabezado`, al lado de los tres textos que ya
escribe.
