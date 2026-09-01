# Traspaso de la coordinación — 31 ago 2026

> **Coordina `8myvc-c1` desde ahora.** Lo llevaba `8myvc-2a`, que cierra por ventana de
> contexto llena. Este documento existe para que el relevo **no tenga que preguntar
> nada**: lo que no está aquí está en
> [reparto.md](reparto.md) (los lotes y las reglas) y en
> [19-boletin-independiente.md](../19-boletin-independiente.md) (el plan).

---

## 1. Estado, medido y no copiado

| | |
|---|---|
| `main` | **`ce56351`**, árbol raíz limpio, **8 commits sin subir** — nada se ha hecho `push` |
| Última suite entera verde | **1.590 pruebas, 11.987 aserciones**, cero rojos, pint PASS, larastan `[OK]` |
| Rutas | **544** (`route:list`) |
| Fase 1 | **23 sitios listados**, de los cuales **4 decididos** y **1 código muerto** → **18 de trabajo** |
| Fases 2 a 7 | **sin empezar**, salvo lo que hagan los lotes D y E ahora mismo |
| Ramas | `fix/bi-lote-{a,b,c,d,e}`, **ninguna con trabajo commiteado todavía** |

**Nadie ha entregado nada aún.** Las cinco están trabajando; cuando entreguen, es el
relevo quien fusiona.

---

## 2. Quién es quién

| Sesión | Lote | Qué lleva |
|---|---|---|
| `8myvc-5e` | **A** | Informes de pérdidas + los dos `Bolfinales` (alcance) y el `puesto: null` de esos dos |
| `8myvc-cf` | **B** | Planilla, unidades, subunidades, `Nota.php` + **la fase 3 entera** |
| `8myvc-53` | **C** | `putCopiar` (§9.4), `Unidad::informacionAsignatura` y cuatro sueltos |
| `8myvc-82` | **D** | **La marca**: ruta nueva, guarda de la decisión 5, `bol_independiente_periodos`, `bol_independiente_datos` |
| `8myvc-8f` | **E** | Puestos e interruptor: migración, `puestosCuentanIndependientes()`, seis llamadores |
| `myvc-front-c5` | — | El front. **Es el canal**: `myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md` |
| `myvc-front-24`, `myvc-front-60`, `myvc-flutter-70` | — | Otras sesiones vivas, no son de esta noche |

**Hay una dependencia entre lotes y es la única:** A necesita
`BoletinIndependiente::puestosCuentanIndependientes($yearId)`, que lo escribe **E**. A
tiene instrucción de hacer primero sus sitios de alcance y preguntar. **E tiene
instrucción de avisar al coordinador en cuanto ese método esté commiteado**, aunque el
resto de su lote esté a medias.

---

## 3. Lo que el coordinador hace, y lo que no

**Hace:** repartir, resolver bloqueos de fichero, fusionar a `main` desde la raíz,
llevar `ESTADO-ACTUAL.md` y `19-boletin-independiente.md`, y hablar con el front.

**No hace:** escribir código de los lotes. La razón por la que esto se traspasa es la
ventana de contexto: **si el coordinador se pone a programar, se acaba y hay que
traspasar otra vez**.

**Ficheros reservados al coordinador** (regla 1.2 del reparto): `ESTADO-ACTUAL.md`,
`19-boletin-independiente.md` y `tests/Contrato/CasoDeContrato.php`. Cada lote escribe
su parte en `docs/migracion/noche-2026-08-31/<letra>.md`.

### Cómo se fusiona

```bash
# desde la raíz, con main checkout
git merge --no-ff fix/bi-lote-<x>
```

**Antes de fusionar cada lote, se comprueba lo que dijo que hizo**, no se firma en
blanco. Lo que se mira, y por qué cada cosa:

1. **`git diff --stat main..fix/bi-lote-<x>`** — que no haya tocado ficheros de otro
   lote. Es la regla que si se rompe cuesta la noche.
2. **Instantáneas**: en la fase 1 **cero regeneradas**. Una que se mueva no es un
   snapshot que se regenera: es una consulta a la que se le olvidó el alcance. Las
   excepciones legítimas de esta noche son **el lote B** (la fase 3 cambia el contrato
   de `notas/detailed`) y **el lote E** (la columna de `years` mueve las tres de
   `MuestreoDeLecturasTest`). En esos dos **se mira el diff**: cero líneas quitadas, ni
   un campo renombrado.
3. **La suite entera después de cada fusión**, no sólo la del lote. Dos lotes verdes
   por separado pueden dar rojo juntos.

> **Y el número de la suite ya no es atribuible a una sola sesión.** Hay otra sesión
> trabajando en este mismo `tests/` que no es de la noche. Cuando se anote un total, se
> anota **con el desglose de qué sumandos son de quién**, o la próxima cifra nace mal.

---

## 4. La cola — se da, no se coge

Está en la [§3 de reparto.md](reparto.md). En orden, y con lo que la desbloquea:

| | Qué | Depende de |
|---|---|---|
| 1 | **`PUT boletin-independiente/planilla`** (§6.1), con los tres `motivo` y `estructura_del_grupo` | D |
| 2 | **`POST boletin-independiente/copiar`** (§6.2): dos orígenes, `si_ya_tiene` de tres valores, 422 de `con_notas` entre periodos | D |
| 3 | **Fase 5** — boletines probados **en negativo** con un alumno marcado | 1 |
| 4 | **`tools/independientes-sin-estructura.php`** (§9.1) | D |
| 5 | **La §9.5 para `repitente`, `promovido` y `nro_folio`** | — |

**El primero que termine se lleva la 1**, porque es lo que más desbloquea al front.

---

## 5. Lo abierto con el front, que es de quien coordina

El canal es **su fichero**, no éste: `~/DESARROLLOS/myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`.
Lo pidió Joseth el 24 ago porque **el front no lee este repositorio por su cuenta**.

**Lo acordado y ya escrito allí** (no hay que re-litigarlo): el campo
`bol_independiente_periodos` con `aplica` × `tiene_datos` y los cuatro periodos
siempre; `bol_independiente_datos` para el badge; `independientes` **sin `aplica`**;
`puesto: null` → `—`; se puede marcar un periodo cerrado; copiar con dos orígenes;
`si_ya_tiene` con `saltar|anadir|reemplazar`; y que **`reemplazar` no borra ninguna
nota** —es borrado en blando de la unidad, recuperable por `unidades/restore/{id}`—,
por lo que el campo se llama `notas_que_dejan_de_contar`.

**Lo que el front está esperando y bloquea sus cuatro pantallas: el lote D.** Tienen
las cuatro escritas, en verde y **escondidas** (la pestaña sólo existe si el campo
viene). En cuanto D entregue, avisarles es lo primero.

---

## 6. Lo que salió mal esta noche, para que no se repita

Tres, y las tres son de método:

1. **El detector estaba ciego a su propio arreglo.** `unidades-sin-alcance.py` medía
   sobre el literal PHP y la forma de acotar se escribe concatenando, así que partía la
   consulta en tres. Arreglado en `ce56351`. **La consecuencia es la que importa: el
   criterio de aceptación que se dio a los cinco lotes era inalcanzable por
   construcción**, y el reparto mandó arreglar por segunda vez cuatro sitios que ya
   estaban hechos desde `58b5714`. Lo levantó el lote A, no el coordinador.
2. **Una cifra repartida sin comprobar.** Se dijo «22 sitios pendientes» y eran **18**.
   Salía del detector ciego. *Una cifra que se reparte se comprueba primero, porque
   cinco sesiones la propagan.*
3. **Un fichero en dos lotes.** `Nota.php` estaba en E y lo necesitaba B. Lo levantó B
   con la regla 1.1. Se resolvió **midiendo**: `puestoAlumno` es una función pura, así
   que E no lo necesita y el fichero entero se fue a B — sin partirlo por métodos, que
   es la ventana que costó cuatro commits con trabajo ajeno la noche del 21 al 22.

> **El patrón de las tres: el coordinador dio por bueno algo que no había medido.** Las
> tres las cazaron los lotes. Vale la pena decirles que sigan haciéndolo.

---

## 7. Lo que NO se toca, aunque lo parezca

- **`GET unidades/de-asignatura-periodo` lee y escribe queriendo** (05 §47.2, decisión
  de Joseth): con el periodo abierto crea las unidades por defecto. **No se le quita.**
- **`selloDeVersion` y `estadoDelGrupo` no se acotan**: son sellos de caché, y acotarlos
  haría servir un dato viejo **sin un error en el log**.
- **`NotaFinal::calcularAsignaturaPeriodo` es código muerto** y se queda: lo decide
  Joseth con los otros 34 métodos sin camino. Lleva aviso porque **escribe definitivas**.
- **`Nota::puestoAlumno` es una función pura y no se toca**: el filtrado va en los ocho
  llamadores, que es donde el plan quiere la decisión.
- **Desplegar es de Joseth.** Y hay una migración bloqueante esperando en `main`
  (`2026_08_31_100000_retirar_boletin_independiente_de_matriculas`), más la de `years`
  que traiga E.
