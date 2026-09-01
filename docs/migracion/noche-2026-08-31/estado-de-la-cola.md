# La cola, a mitad de la noche — 31 ago 2026

> **Esto existe porque CUATRO de las cinco sesiones de lote murieron a la vez**, con
> una de ellas sin commitear. *(La coordinación escribió «las cinco» y se equivocó: el
> lote D estaba vivo y lo dijo él. Su trabajo se commiteó dándolo por perdido, y el
> commit resultó ser exactamente sus doce ficheros — pero **la comprobación de que una
> sesión ha muerto era «su nombre ya no resuelve», y eso no es una medición**: los
> nombres de sesión cambian solos.)* Lo que sigue es lo que una sesión nueva necesita para
> continuar **sin preguntar nada**: qué está fundido, qué está en una rama, qué
> falta y de quién era. Coordina `8myvc-c1`.
>
> Las reglas de la noche siguen en [reparto.md](reparto.md) —**la §1.7 y la §1.8
> son las que cambiaron a mitad**— y el plan en
> [19-boletin-independiente.md](../19-boletin-independiente.md).

## 1. Lo fundido en `main`

| | Qué | Merge |
|---|---|---|
| **E** | fase 6: el interruptor `years.puestos_con_bol_independiente`, `ponerPuestos()` y los seis llamadores; el arreglo de que el interruptor resucitaba a 1 cada enero | `9304441` |
| **A** | los cuatro recuentos de pérdidas de los dos `Bolfinales`, con `alcanceCorrelacionado` | `d58066e` |
| **B** | siete sitios de la fase 1 y **la fase 3 entera** | `9515642` |

**Nada está desplegado, y `main` no se ha subido.** El push y el despliegue son de
Joseth. Hay **tres migraciones bloqueantes** esperando: la de retirar
`matriculas.boletin_independiente`, la de `years.puestos_con_bol_independiente` y
lo que traiga la tanda.

## 2. Lo commiteado y SIN fundir

| Rama | Commit | Por qué no está fundido |
|---|---|---|
| `fix/bi-lote-c` | `d0707ef` | su sesión murió antes de mandar el `Tests: N passed`. **Falta correr la suite entera contra esa rama.** El trabajo está verificado por ficheros: los siete son suyos y no toca ninguno ajeno |
| `fix/bi-lote-d` | `78f7be1` | **dos rojos sin explicar**, ver §4. El código está verificado por ficheros y sus instantáneas miradas una a una |
| `fix/bi-lote-a` | `d62520e` + merge de `main` | el arreglo del seed (el rol `Secretario`). **Al fundirlo, las demás sesiones tienen que reconstruir su base** |

Las dos primeras las commiteó la coordinación **sin tocar una línea**, con el trabajo
en el árbol y sin su sesión: *lo que no está commiteado es lo que se pierde*. La
autoría está en el mensaje de cada commit. **La del lote D se commiteó por error**
—esa sesión seguía viva— y ella misma verificó después que el commit son sus doce
ficheros y su mensaje en sustancia.

## 3. Lo que falta, en orden y con dueño

1. **La suite entera contra `fix/bi-lote-c`**, y fundirla. Con eso **la fase 1
   queda cerrada**: 4 de A + 7 de B + 7 de C = los 18 de trabajo.
2. **Discriminar los dos rojos del lote D** (§4) y fundirlo. **Es lo que
   desbloquea al front**, que tiene cinco pantallas escritas y escondidas.
3. **`NotasController:113`** — una línea que le pasa el periodo a `Grupo::alumnos`
   para que `bol_independiente_datos` viaje. **No se puede escribir hasta que D
   esté en `main`**: hoy `Grupo::alumnos` es de dos parámetros y PHP dejaría pasar
   un tercero sin protestar — una llamada en verde contra una firma que no existe.
4. **El arreglo del detector, cuarta ceguera**, diagnosticado por el lote B y sin
   hacer: `alcance_de_unidades()` acepta `<=>` sin alias pero **exige
   `\b<alias>\.` para `IS NULL`**, así que marca «hay que acotarla» a consultas
   acotadas — y justo con la forma que la §1.6 bendice. El arreglo es de una
   línea: que la segunda rama use `ref` como la primera. **Se corre
   `AutopruebasDeLasHerramientasTest` después, y se deja la población antes y
   después**: hoy, desde `main`, son **43 lecturas pendientes en 23 sitios**.
5. **`PUT boletin-independiente/planilla`** (§6.1) y **`POST
   boletin-independiente/copiar`** (§6.2). Depende de D.
6. **`asignatura.bol_independiente` en SEIS sitios**, no en tres: los tres
   boletines, los certificados, preescolar y los dos `Bolfinales`. **En pantalla,
   no impreso**: son documentos del colegio y no se rotulan; el front pinta una
   nota que desaparece al imprimir y **no la inventa en el cliente**.

   > **Eran siete y el acta se cayó — corregido por el front el 1 sep 2026, y la
   > coordinación se había equivocado.** En el acta **no hay dónde colgarlo**: su
   > respuesta son grupos con matrículas, resumen, promoción y periodos, y **no
   > tiene ni una asignatura por alumno**. Emitirlo ahí no pintaría nada y **no
   > daría ningún error**: una rama muerta más. El acta pasa al punto 7 con el
   > campo que sí contesta algo.
7. **Dos campos que el front pidió y están fijados**:
   `alumno.bol_independiente_aparte_en: [2, 3]` en `DefinitivasPeriodosController`
   —lista plana de `numero`, **con nombre distinto del `bol_independiente_periodos`
   de la ficha a propósito**, que carga objetos— y
   `alumno.bol_independiente_periodo` (booleano) en
   `Informes/NotasPerdidasController`, donde hoy un independiente sin unidades
   montadas **sale perdiéndolo todo y parece un alumno que no estudia**.

   **Y `bol_independiente_aparte_en` va TAMBIÉN en el acta de evaluación**
   (`Informes/ActasEvaluacionController`), que es donde el front ya lo escribió.
   No es sólo el campo que cabe, es el que corresponde: **el acta es de todo el
   año**, así que decir «va aparte» sin decir en cuál de los cuatro periodos no
   contesta nada — el mismo argumento por el que este campo no se aplanó a un
   booleano en `definitivas-periodos`.
8. **El centinela de `YearsController::postStore`**, propuesto por el lote E: que
   compare `SHOW COLUMNS FROM years` con lo que ese método escribe y falle
   **nombrando** la columna que nadie copió. Con la lista de excepciones partida
   en dos clases —las estructurales y las que un colegio **quiere** vacías— y el
   porqué de cada una. **Su primera excepción ya está decidida: `firmantes_acta`**,
   porque *los firmantes se confirman cada año a propósito* y un acta firmada por
   quien ya no está es peor que un acta sin firmantes (Joseth, 31 ago 2026).

## 3 bis. ⚠️ `main` ESTÁ EN ROJO A PROPÓSITO: 3 fallos, y son un centinela haciendo su trabajo

Suite de `main` tras fundir el lote C y el seed del lote A:
**`Tests: 3 failed, 1614 passed (12231 assertions)`, `exit=1`.** Los tres salen del
**seed**, no del código:

```
⨯ LoQueDecideUnRolTest › el rol secretario no existe y por eso administrativo es superusuario
⨯ MuestreoDeLecturasTest › la lectura conserva su forma with data set "api/roles"
⨯ MuestreoDeLecturasTest › la lectura conserva su forma with data set "api/roles/rolesconpermisos"
```

**El primero no es un test que se haya roto: es un aviso que alguien dejó armado**, y su
propio docblock lo dice — *«Si alguien crea ese rol, este test se pone rojo. **Eso es lo
que hace**: no impedirlo, avisar de que en ese momento cambia quién puede qué.»* Y el
mensaje de su aserción nombra la consecuencia: **`Autoriza::esAdministrativo()` deja de
ser `is_superuser` a secas, y con él cambian las escrituras de alumnos, las de acudientes
y los tres `forcedelete`.**

Los otros dos son las instantáneas de la lista de roles, que gana una fila.

**Está sin resolver y esperando a Joseth, porque es exactamente la decisión que ese
centinela existe para forzar.** Lo que hay que tener delante al decidir:

- El cambio es **sólo de la base de tests**; en producción cada colegio tiene el rol o no
  según su migración, y `esAdministrativo()` no se ha tocado.
- O sea que lo que hace el seed nuevo es **acercar la base de tests a un colegio migrado**
  (doce roles) en vez de dejarla en once.
- Pero el rol **sin nadie asignado** no cambia ninguna respuesta por sí solo: lo que cambia
  es que la rama deja de ser inalcanzable.
- Y la premisa con la que se encargó este arreglo **era falsa** (§ ESTADO-ACTUAL): la rama
  ya la ejercitaban seis o siete ficheros de test fabricándose el rol.

**Las dos salidas, y ninguna es «regenerar y seguir»:** o el centinela se reescribe contra
la realidad nueva —y entonces hay que decir qué comprueba ahora, porque su valor era
avisar de este momento— o **se revierte el merge del seed** (`cc1af2f`), que devuelve
`main` a verde y deja el arreglo en su rama.

## 4. Los dos rojos del lote D, sin resolver

`BoletinesTest::la forma del boletin de un alumno`, con `boletines` y
`boletines2`; `boletines3` verde. **En aislamiento pasan los 17.**

**El dato que orienta y que hay que usar antes de mirar el código:** dentro de la
suite fallaron en **2,58 s** y **1,63 s**, y en aislamiento esos mismos tardan
**43,91 s** y **39,39 s**. Un `assertSame` de instantánea **no puede** fallar antes
de calcular la respuesta: lo que falla en dos segundos revienta **antes** de llegar
ahí. Y había **cuatro suites contra el mismo MySQL** —bases distintas, mismo
servidor—.

**Cómo se discrimina, y las dos respuestas valen:** correr esa clase con el
contenedor vacío. Si desaparecen, era contención; si siguen, hay algo real y
entonces se mira el **tipo** de fallo antes que el mensaje —si es excepción y no
aserción, ya está contestado—. **No se regenera ninguna instantánea de respuesta
sin enseñar el diff.**

Ojo al comparar: `main` ya lleva el lote E, que **tocó esos dos controladores**
para meter `ponerPuestos()`. El árbol de D no lo lleva, así que su rojo no puede
venir de ahí — pero después de traer `main` ya no sería el mismo rojo.

## 5. Lo que esta noche ha demostrado que no se puede dar por bueno

Cinco cifras nacieron mal, **y las cinco eran creíbles**. Está contado en
[ESTADO-ACTUAL.md](../ESTADO-ACTUAL.md); lo que hay que llevarse a la siguiente
sesión es la forma, no la lista: **contar bien el síntoma no es haber contado la
causa**, y **el instrumento es lo primero que se mira cuando el número sale raro**
— incluido el que no se llama a sí mismo herramienta: `grep -rln` devuelve nombres
de fichero y no líneas, el `ps` del host no ve dentro del contenedor, el `cwd` de
una shell persiste después de un `cd`, y **el árbol es parte del instrumento**.
