# La curva de profundidad — cuántas rutas escriben, y a qué distancia

> Sesión `8myvc-4f`, madrugada del 23 de agosto de 2026. Sale del §137 del lote P,
> que terminaba con una advertencia sin contestar: *«seis a un salto, siete a dos,
> y ninguno de los dos ve tres».* Esto la contesta.
>
> **No se ejecutó ninguna ruta.** Todo lo de aquí es lectura del código: varias de
> las que salen caen en `notas_finales`, y ahí el criterio de la noche es que
> **medir la puerta no toca el cálculo; ejecutarlo, sí.**

> ## ⚠ ERRATAS — 23 ago 2026, madrugada
>
> **El total publicado aquí, ~~315~~, lleva dos falsos positivos. Son 313.**
>
> `bolfinales/detailed-notas-year` y `-year-group` **no escriben**:
> `BolfinalesController.php` no tiene ni una escritura —cero `DB::update`, cero
> `DB::insert`, cero `->save()` en el fichero entero— y sus cuatro llamadas
> estáticas (`Grupo::alumnos`, `Grupo::datos`, `Nota::puestoAlumno`,
> `Year::datos`) solo leen.
>
> **Lo que NO cambia**: los **300** del escalón 1 —recontados con una
> implementación independiente, sin recursión—, los hallazgos nominales (§137,
> §146, §147 y las once del escalón 2, verificados leyendo uno a uno) y **la
> forma de la curva**: 300 / +11 / +2 / **0 de 4 a 8**. Sigue convergiendo en 3,
> que era la afirmación que importaba: **el inventario de escrituras se puede
> cerrar igual**.
>
> El 315 se deja tachado y no borrado: estuvo publicado, y una corrección que
> borra el número anterior no deja ver que hubo corrección.
>
> **Y lo que enseña**, que es más caro que el número: antes de publicarlo se
> sembró el detector con nueve casos ya leídos —seis que escriben, tres que no— y
> pasó **9/9**, con las profundidades correctas.
>
> > Sembrar el detector con casos conocidos prueba que **alcanza**. Leer una
> > muestra de sus positivos prueba que **acierta**. Un instrumento puede pasar la
> > siembra 9/9 y seguir inventándose resultados: son dos comprobaciones distintas
> > y hacen falta las dos.
>
> La segunda se hizo **por accidente**, al ir a repartir el trabajo. Debería ser
> un paso, no una casualidad. El detalle está en el §149.

## El resultado

Sobre las **539 rutas**, buscando a qué profundidad aparece la **primera**
escritura del camino:

| Profundidad | Rutas nuevas | Acumulado |
|---|---|---|
| 1 — en el propio método | **300** | 300 |
| 2 — un salto | ~~13~~ **11** | ~~313~~ **311** |
| 3 — dos saltos | **2** | ~~315~~ **313** |
| 4 | 0 | **313** |
| 5 | 0 | **313** |
| 6 | 0 | **313** |
| 7 | 0 | **313** |
| 8 | 0 | **313** |

**Converge en 3.** O sea que el inventario de escrituras **se puede cerrar**:
**313 de 539 rutas de la API escriben** (58%), y **no hay ninguna a más de dos
saltos**. (El **~~315~~** de la primera publicación llevaba dos falsos positivos;
ver las erratas de arriba.) Es lo que hoy no se podía afirmar de ningún inventario de esta noche.

## Qué cuenta como «salto», que es lo que hay que declarar al lado del número

Tres formas, y las tres se siguen:

| Forma | Cómo se resuelve |
|---|---|
| `Clase::metodo()` | por el nombre de la clase, contra el índice de `app/` |
| `$this->metodo()` | en el mismo fichero |
| `$var->metodo()` | por `$var = new Clase` **en el mismo fichero**; si no, se prueba en el propio fichero |

**La tercera es la débil, y hay que decirlo con número**: en `app/` hay **529**
llamadas de esa forma; **239** tienen su `new Clase` a la vista y se resuelven;
las demás se prueban contra el propio fichero, que acierta poco. Es el hueco que
queda, y es el que hay que cerrar el día que alguien quiera subir el 315 a
definitivo.

## Dos artefactos que inflaban la cuenta, los dos míos

La primera versión de esta curva **no convergía**: seguía dando rutas nuevas a los
seis saltos. No era el código: era el instrumento.

1. **Contaba llamadas dentro de comentarios.** El caso que lo destapó:
   `acudientes/mis-acudidos` salía como escritura a cuatro saltos, y la cadena
   terminaba en `Debugging::pin()` → `->save()`… **en una línea comentada** de
   `EscalaDeValoracion::valoracion`. Quitando comentarios, la cuenta bajó de
   **326 a 310** — o sea **16 rutas fantasma**.
2. **Resolvía `$var->metodo()` por nombre**, así que cualquier método homónimo de
   cualquier clase valía como destino.

> Lo que hizo verlos no fue leer el código del detector: fue que **la curva no
> convergía**. Una curva que no converge es un detector que se está inventando
> caminos, y eso se ve en el número mucho antes que en el fuente.

## Lo que esto le añade al lote P

El barrido del lote P encontró **seis GET que escriben**. Con un salto son
**ocho**: dos GET que aquel barrido **no podía ver**, y los dos con el nombre
diciéndolo:

```
GET api/piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}
      -> PiarsAsignaturasUtils::getCreatePiarAsignatura   [DB::insert]
GET api/piars-grupos/contexto-de-grupo/{grupo_id}
      -> PiarsAlumnoUtils::getAlumnosPiar                 [DB::insert]
```

Son de **`PiarsAsignaturas` y `PiarsGrupos`**, del lote F. Van sin tocar.

## Las trece que solo se ven a un salto

Un detector «en línea» —el que casi todos escribimos— no ve ninguna de éstas:

| Ruta | La escritura está en |
|---|---|
| `PUT ChangesAsked/solicitar-cambios` | `ChangeAskedController::crear_o_modificar_datos_de_pedido` |
| `PUT acudientes/guardar-valor` | `GuardarAlumno::valorAcudiente` |
| `PUT alumnos/guardar-valor` | `GuardarAlumno::valor` |
| `PUT alumnos/guardar-valor-varios` | `GuardarAlumno::valor` |
| `PUT bolfinales/detailed-notas-year-group/{grupo_id}` | `BolfinalesController::detailedNotasGrupo` |
| `PUT bolfinales/detailed-notas-year/{grupo_id}` | idem |
| `GET definitivas_periodos` | `NotaFinal::alumnos_grupo_nota_final` — §137 |
| `POST importar/algo/{year}` | `PuntoDeControlDeImportacion::abrir` |
| `POST matriculas/matricular-en` | `Matricula::matricularUno` |
| `POST matriculas/matricularuno` | idem |
| `GET piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}` | `PiarsAsignaturasUtils::getCreatePiarAsignatura` |
| `GET piars-grupos/contexto-de-grupo/{grupo_id}` | `PiarsAlumnoUtils::getAlumnosPiar` |
| `PUT unidades/de-asignatura-periodo/{...}` | `UnidadesController::getDeAsignaturaPeriodo` — §47.2 |

## Y las dos que solo se ven a dos saltos

- **`PUT api/alumnos/show`** — y ésta **no estaba medida por nadie**. La cadena:
  `putShow` → `comprobar_alumno_con_grupos` → `traer_requisitos_detalle` →
  `INSERT INTO requisitos_alumno(...) VALUES(?, ?, "falta", ?)`. O sea que
  **mirar la ficha de un alumno le crea las filas de requisitos de matrícula que
  le falten**, con estado «falta». Es la misma forma del §133 —una pantalla que
  se fabrica al abrirla— en un endpoint que se llama `show`. `AlumnosController`
  es del lote K: **queda anotado y sin tocar.**
- **`PUT api/boletines/detailed-notas/{grupo_id}`** — la familia del boletín, ya
  conocida y con test propio (`BoletinNoBorraDefinitivasTest`). No es nueva: lo
  que explica la curva es **por qué costó encontrarla**.

> Y aquí está la trampa que dio la errata, porque las dos familias se parecen y
> **no son la misma**: `boletines/detailed-notas` **sí** escribe y tiene test que
> lo prueba; **`bolfinales` no**, y su fichero no tiene ni una escritura. Dos
> nombres a una letra de distancia, uno con antecedentes y el otro sin ellos: es
> exactamente la clase de vecindad que hace que un falso positivo **se lea como
> confirmado**.

## La regla, ya con número detrás

> Un detector no dice cuántos hay: **dice cuántos hay a la profundidad a la que
> miró**, y esa profundidad hay que escribirla al lado del número.

Y la que sale de haberla medido:

> **La curva es la que dice cuándo parar.** Mientras siga dando rutas nuevas, el
> inventario está abierto y el número que se publique será provisional. Cuando se
> aplana —aquí, en 3— el inventario se puede cerrar. **Y si no se aplana nunca,
> sospecha del detector antes que del código**: las dos veces que no me convergió,
> el que se inventaba caminos era el mío.
