# MED-5 — las escrituras que no viven en un controlador, y bajo qué rol disparan

> **Sesión `8myvc-39`, noche del 24 ago 2026.** Todo lectura: no se toca ningún
> middleware —`ExigirBoletinPropio` y `ExigirPersonaPropia` fueron de `8myvc-7b`
> esta noche— y no se corre nada contra el servidor.
>
> Sale de una medición del front: la escritura de
> `bolfinales/detailed-notas-year-group` **no está en el controlador, está en
> `ExigirBoletinPropio:68`**, y **sólo dispara si quien pide es `Alumno` o
> `Acudiente`**. Entrando como `Usuario`, ni el middleware anota.

---

## 0. La pregunta que esto reordena

> **«¿Este endpoint escribe?» es la pregunta equivocada. La buena es «¿escribe
> cuando lo llamo YO?»**

Y de ahí sale el aviso que hay que dar **antes** de cualquier número de este
documento: **un middleware con salida temprana escribe o no según quién llame.** Un
*«no escribe»* medido con un token de `Usuario` **es verdad y es inútil**.

**Cómo se midió esto, para que se pueda creer:** no golpeando con tokens, sino
**leyendo la puerta de cada sitio en el código**. Golpear con un token demuestra lo
que hace **ese** rol; leer el `if` demuestra lo que hacen **todos**. Es la única de
las dos que contesta la pregunta de arriba.

---

## 1. La población

Del detector de la fase 3 (`tools/escrituras-sin-auditoria.php --todas`), que
barre `app/` **entero** y no sólo los controladores:

| | |
|---|---|
| Ficheros de `app/` revisados | **220** |
| Escrituras de datos en total | **252** |
| Métodos que escriben | **159** |
| **Métodos que escriben y NO están en un controlador** | **15** |
| Sentencias de escritura en esos 15 | **18** |

> **Un barrido de controladores no puede encontrar esas 15**, y eso no es una
> observación sobre ninguna herramienta concreta: es de construcción. Dos de las
> quince no tienen **ningún** método de controlador de por medio —corren en un
> middleware, antes—, así que no hay nada que un recorrido de
> `app/Http/Controllers/` pueda mirar.
>
> Del **detector de la fase 3** sí se puede afirmar más, porque es mío y lo he
> comprobado: las quince salen **porque recorre `app/` y no
> `app/Http/Controllers/`** — una diferencia de una línea y de todo. Cuando se lee
> su **lista por dominios**, en cambio, se vuelve a mirar por controlador y las
> quince se pierden otra vez.
>
> **Y una corrección de esto mismo, hecha después de escribirlo:** la primera
> versión decía *«y eso incluye los de este repositorio: la §175 y la §191 miran
> métodos de controlador»*. **Esas dos secciones no están en este árbol** —el 05 que
> tengo llega a la §174— así que **cité como apoyo dos cosas que no he leído**,
> tomadas de un mensaje. La afirmación de arriba se sostiene sin ellas; la de abajo
> no se sostenía y por eso se ha ido. Es la forma que este documento tiene en el
> §6: **una afirmación escrita con el alcance del sujeto y no con el de lo
> comprobado.**

---

## 2. El cuadro, con la tercera columna que faltaba

| Dónde corre | Métodos | Escrituras | **Quién lo dispara** |
|---|---|---|---|
| **Antes del controlador** — middleware | 2 | 2 | **sólo `Alumno` y `Acudiente`** |
| **Durante el login o el refresco** — `Services\Login`, `Services\Sesion` | 4 | 4 | uno **sin actor**; los otros, cualquiera que entre |
| **Debajo del controlador** — modelos y servicios | 8 | 11 | quien pase el guard del controlador que los llama |
| **Sin HTTP** — comando de consola | 1 | 1 | nadie por HTTP |

### 2.1 Los dos middlewares: la misma puerta, a catorce líneas

```php
// ExigirBoletinPropio:68        y        ExigirPersonaPropia:82
if ($usuario->tipo !== 'Alumno' && $usuario->tipo !== 'Acudiente') {
    return $next($request);
}
```

**Idéntica en los dos.** Todo lo que no sea familia sale antes de que exista la
posibilidad de anotar. Así que las dos escrituras:

| Middleware | Escribe | Dispara con |
|---|---|---|
| `ExigirBoletinPropio::anotar` (línea 165) | `bitacoras` | `Alumno`, `Acudiente` |
| `ExigirPersonaPropia::anotar` (línea 296) | `bitacoras` | `Alumno`, `Acudiente` |

### 2.2 Y dos correcciones al §0 del [18](../18-auditoria.md), que salen de leerlas

**`ExigirBoletinPropio` no escribe un tipo: escribe cinco.** La tabla del §0 dice
`AlumnoVerBoletin`. Hay **cuatro** sitios que llaman a `anotar()` y uno es un
ternario:

```
línea  87 → AcudienteVerVariosBoletines | AlumnoVerVariosBoletines
línea  95 → AlumnoVerBoletin
línea 109 → AcudienteVerBoletin
línea 121 → AcudienteVerBoletinSinPagar
```

**Y el de `ExigirPersonaPropia` no es una lista: es una plantilla.**

```php
mb_substr($usuario->tipo.'PideAjeno:'.$clave, 0, 45)
```

El tipo **se construye en tiempo de ejecución** con la clave que el cliente mandó,
y se **recorta a 45 caracteres**. O sea que su vocabulario **no está acotado por
nada**: cada identificador nuevo que alguien intente pedir de otro inventa un
`affected_element_type` que no existía.

> **Esto ya estaba absorbido en el diseño de la fase 3, y conviene decirlo porque
> es la primera vez que se comprueba contra el código:** en `auditoria` esos dos no
> son un tipo de texto libre — son `accion = 'denegado'` con `entidad` en
> `persona` \| `boletin`, y la clave concreta cabe en `valor_anterior`. El
> vocabulario cerrado **no se rompe con estas dos**, que era el riesgo.

### 2.3 Las que corren en el login, y la que no tiene actor

| Método | Dispara con |
|---|---|
| `Services\Login::anotarIntentoFallido` | **nadie autenticado** — es el intento fallido, el del `created_by = 0` |
| `Services\Login::anotarEntrada` | cualquiera que entre |
| `Services\Login::ponerEnElPeriodoActual` | cualquiera que entre |
| `Services\Sesion::anotarReutilizacion` | cualquiera cuyo refresco se reutilice |

### 2.4 Las ocho de debajo del controlador, y las dos que hay que mirar

| Método | Quién lo llama |
|---|---|
| `Nota::verificarCrearNotas` | `SubunidadesController:94`, `NotasController:61` |
| `Unidad::arreglarOrden` (2) | `UnidadesController:160`, **`NotasController:78`** |
| `DefinitivasDeAsignatura::recalcular` (2) | **seis** controladores, incluido `BoletinesController` |
| `PuntoDeControlDeImportacion::abrir` (2) · `anotar` · `completar` · `fallar` | `Alumnos/ImportarController` |
| **`NotaFinal::calcularAsignaturaPeriodo`** | **NADIE** |

**`NotaFinal::calcularAsignaturaPeriodo` no la llama nada en todo `app/`.** Su
`DB::delete` de la línea 257 es inalcanzable. Sin ruta y sin llamante, por la regla
de la casa se borra — pero **no desde aquí**: ese fichero es de las definitivas y su
§5 ya tiene prevista una limpieza.

---

## 3. `Unidad::arreglarOrden`: la que mejor contesta la pregunta del §0

```php
public static function arreglarOrden($unidadesT, $asignatura_id, $periodo_id)
{
    for (...) {
        DB::update('UPDATE unidades SET orden=? WHERE id=?', [$i, ...]);
        for (...) {
            DB::update('UPDATE subunidades SET orden=? WHERE id=?', [$j, ...]);
        }
    }
```

**Escribe sin condición**: un `UPDATE` por unidad y otro por subunidad, **cada
vez**, aunque el orden ya esté bien. No hay ningún `if`.

Y la llama `NotasController:78`, que está dentro de **`putDetailed()`** →
`PUT notas/detailed`, o sea **cada carga de la pantalla de notas**.

Medido sobre el seed:

| | |
|---|---|
| `UPDATE` por carga, media | **12,7** |
| Peor caso medido | **20** |
| Pares (asignatura, periodo) medidos | 58 |

**Y casi todos no cambian nada**, por la regla de la §13: `DB::update` devuelve
filas **afectadas**, y MySQL devuelve 0 cuando el valor no cambia. El orden ya
estaba bien la segunda vez y todas las siguientes.

> **Un endpoint que se llama para leer la pantalla reescribe la columna `orden` de
> todas las unidades y subunidades de esa asignatura.** No es un fallo declarado —el
> comentario de `UnidadesController:153` ya dice que *«`arreglarOrden()` no ordena la
> respuesta: reescribe `orden` en la tabla»*— pero **nadie lo había contado**, y es
> el ejemplo más limpio de que «¿este endpoint escribe?» no se puede contestar
> mirando el controlador.

---

## 4. Qué le hace esto a la fase 4 — que es por lo que no es una curiosidad

La fase 4 de la auditoría instrumenta **siete dominios**, y su lista está escrita
**por controlador**. Si se instrumenta mirando sólo ahí:

**1. Las líneas del middleware quedan fuera del escritor único.** Los dos
middlewares seguirían escribiendo en `bitacoras` mientras el dominio de al lado
escribe en `auditoria`, y entonces **la auditoría tiene dos escritores otra vez** —
que es exactamente lo que la fase 3 vino a quitar. Y peor que dos escritores: **dos
escritores con relojes y esquemas distintos**, o sea el problema del §1.1 reabierto
en pequeño.

**2. `Unidad::arreglarOrden` es el caso que obliga a decidir, no a programar.** El
dominio 2 de la fase 4 es *«unidades y subunidades — crear, editar, borrar»*.
Instrumentar `UnidadesController` coge las ediciones explícitas; **no coge
`arreglarOrden`**, que reescribe `orden` doce veces por carga de pantalla. Las dos
salidas son defendibles y **ninguna es la de no mirarlo**:

- **auditarla** — y entonces el rastro se llena de doce líneas automáticas por
  carga, casi todas sin cambio real. Es literalmente el ruido que el plan ya previó
  para las definitivas: *«la definitiva que un profesor teclea y la que el sistema
  recalcula no son la misma cosa»*. La herramienta para eso **ya existe** en el
  escritor: `->porElSistema()`, más `valor_anterior == valor_nuevo` para reconocer el
  reguardado sin cambio;
- **no auditarla** — y entonces queda escrito que `unidades.orden` cambia sin
  rastro, que es una respuesta legítima **si está dicha**.

**3. Y la regla de método que se lleva la fase 4**, que es lo que este lote deja:

> **El inventario de escrituras de un dominio no es el de su controlador.** Antes de
> instrumentar un dominio hay que mirar, además: **el middleware de sus rutas**, los
> **modelos** que toca y los **servicios** que llama. Son 15 métodos y 18 escrituras
> las que viven fuera, y **ningún barrido de controladores las ve**.

---

## 5. Lo que este lote NO hace

- **No toca ningún middleware.** Los dos fueron de `8myvc-7b` esta noche.
- **No toca `NotaFinal.php`** aunque su método no lo llame nadie: es de las
  definitivas y su fase 5 ya prevé la limpieza.
- **No toca `Unidad::arreglarOrden`.** Que reescriba doce veces por carga es una
  medición, no un arreglo: si se cambia, cambia el orden que ve la pantalla de
  notas, y eso es una decisión con su propio riesgo.
- **No golpea con tokens.** Se leyeron las puertas, que dice más — pero **no
  sustituye** a un barrido con los cuatro roles, que sería la comprobación cruzada y
  no está hecha.

---

## 6. Y la pregunta de las dos puertas idénticas: **sí deben serlo**, y por qué eso importa poco

Que `ExigirBoletinPropio:68` y `ExigirPersonaPropia:82` lleven **el mismo `if`
palabra por palabra** es un dato. Si **deben** llevarlo es otra pregunta, y se
contesta leyendo **las rutas que los usan**, no el `if`.

Leídas: **18 rutas** con `boletin.propio` y **26** con `persona.propia`, 17 de ellas
en `perfiles.php`. Las dos puertas dicen lo mismo —*«la familia está acotada, el
personal no»*— y en las 44 rutas esa política es la que corresponde: un boletín, un
certificado o una ficha los ve cualquiera del colegio, y eso es la regla confirmada
del proyecto.

**Así que la identidad no es un olor. Lo interesante es lo otro:**

### 6.1 En la ruta más delicada de las 44, el middleware no es lo que protege

`PUT perfiles/cambiarpassword/{id}` lleva `persona.propia`. Con el paso franco,
**cualquier `Profesor` atraviesa el guard**. Lo que impide que le cambie la
contraseña a otro no es el middleware:

```php
if (! Hash::check((string) Request::input('oldpassword'), $perfil->password))
    abort(400, 'Contraseña antigua es incorrecta');
```

**Es la contraseña vieja.** El guard acota a la familia; al personal lo acota una
comprobación **de dentro del método**, independiente y de otra naturaleza. O sea que
en esa ruta el middleware **no es load-bearing**, y quien lo leyera para saber qué
permite se llevaría la mitad.

### 6.2 Hay tres políticas en uso y el middleware sólo sabe expresar una

| Política | Dónde vive |
|---|---|
| «la familia acotada, el personal no» | los dos middlewares — 44 rutas |
| «el personal también acotado» | **dentro del método** (`Hash::check` de la contraseña vieja) |
| «el personal **nada**» | **dentro del controlador** — `GET notificaciones/temas`, y su propio comentario dice que `boletin.propio` no le sirve porque *«no se pide de quién, se contesta quién eres»* |

**Las dos políticas que el middleware no sabe decir viven en controladores.** Eso
convierte la lista de guards en una respuesta incompleta a *«¿qué permite esta
ruta?»*, y es la misma forma que el §4 de este documento, girada:

> **El inventario de escrituras de un dominio no es el de su controlador** — y
> **la política de una ruta no es su guard.** En los dos casos, lo que falta está
> justo en el sitio donde nadie mira porque la herramienta que se usa mira otro.

**No se propone nada.** Que las dos puertas sean iguales es correcto, y sacar las
otras dos políticas a un middleware sería inventar dos guards para dos rutas — más
piezas para menos claridad. Lo que queda escrito es que **la lista de guards no
contesta la pregunta entera**, para que nadie la cite como si lo hiciera.
