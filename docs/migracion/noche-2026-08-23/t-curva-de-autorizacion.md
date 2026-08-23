# La curva de autorización — quién está defendido, y a qué distancia

> Sesión `8myvc-4f`, madrugada del 23 de agosto de 2026. Commit del árbol:
> **`9c4812f`**. Es la curva de profundidad aplicada al inventario que más
> importa, y el que **tiene el problema por definición**: el 15 ya avisaba de que
> el detector de identificadores buscaba `Autoriza::` y que **media API comprueba
> en un helper privado**, llamado `exigirQue…` en un sitio y `exigeQue…` en otro.
>
> **No se ejecutó ninguna ruta.** Todo es lectura.

## Qué se mide, declarado antes del número

Para cada una de las **539 rutas**, dos cosas distintas y las dos legítimas:

1. **El guard de la ruta**: `auth.personal`, `persona.propia`, `boletin.propio`.
   `auth.token` no cuenta — va por defecto a toda la API y no decide nada sobre
   quién.
2. **La comprobación dentro del método**, siguiendo los tres tipos de salto
   (`Clase::metodo()`, `$this->metodo()`, `$var->metodo()` resuelto por
   `$var = new Clase`).

Cuenta como comprobación: `Autoriza::*`, `User::pueden_*`, `User::permiteEditar*`,
**`exigeQue…` / `exigirQue…`** —las dos conjugaciones, que es lo que el detector
viejo no veía—, `hasRole*`, `Role::is*`, `$user->is_superuser` y `abort(401|403)`.

**Declarado, porque afecta al número**: `abort(401)` cuenta, y en
`tardanzas/login` eso es **autenticación**, no autorización — la ruta se
autentica sola en cada petición. Y `is_superuser` **solo cuenta cuando se lee del
usuario** (`$user->is_superuser`), no cuando aparece en un `SELECT`, que es una
columna y no una decisión.

## La curva: converge en 3

Profundidad a la que aparece la **primera** comprobación dentro del método:

| Profundidad | Rutas nuevas | Acumulado |
|---|---|---|
| 1 — en el propio método | **146** | 146 |
| 2 — un salto | **15** | 161 |
| 3 — dos saltos | **3** | 164 |
| 4 a 8 | 0 | 164 |

**Converge en 3**, igual que la de escrituras. Y **18 de las 164 comprueban a más
de un salto**: son las que un detector en línea da por indefensas.

## El cuadro completo, que es lo que había que juntar

Un número de «rutas sin autorización» que mire solo el cuerpo del método es
**falso por construcción**: la defensa principal de esta API está en el
middleware. Las dos mitades, juntas:

| Cómo está defendida | Rutas |
|---|---|
| Solo por el guard de la ruta | **328** |
| Guard **y** comprobación dentro | **92** |
| Solo por dentro (el guard no la cubre) | **51** — 42 en línea, **9 a un salto** |
| **Nada: ni guard ni comprobación, a ninguna profundidad** | **67** |

## Las 67, y por qué el número no asusta

**32 de las 67 ya están declaradas** en `AutorizacionTest`, con su motivo escrito
—los diecisiete catálogos «pendientes de decisión en 08», las rotas, las abiertas
a propósito—. Las **35 restantes** se leyeron una a una y se agrupan solas:

| Grupo | Cuántas | Qué son |
|---|---|---|
| Pre-login | 11 | `auth/*`, `login/*` — públicas por diseño, y son un test (`RutasPreLoginTest`) |
| Catálogos geográficos | 7 | `ciudades/*`, `paises` |
| Votaciones | 5 | el módulo entero; *«si esto lleva guard, no hay elecciones»* |
| Subir la imagen propia | 3 | `myimages/store*` — el dueño sale del token |
| Lo propio, resuelto del token | 4 | `mis-acudidos`, `eps-check`, `ocupaciones-check`, `piars-config` |
| El muro | 3 | `publicaciones/*` |
| **`calendario/this-year`** | 1 | **el cliente decide qué ve** — ver abajo |
| `auth/me` | 1 | devuelve el usuario del token |

**Ninguna de las 35 es un hallazgo nuevo salvo una**, y esa ya tiene lote:

> `PUT api/calendario/this-year` hace
> `$is_prof_admin = Request::input('is_prof_admin')` y, según lo que diga **el
> cliente**, devuelve todos los eventos o solo los que no son `solo_profes`. **El
> interruptor lo mueve quien pregunta.** Es el lote Q.

## Las dos comprobaciones del instrumento, esta vez a propósito

La lección de la curva anterior era que **sembrar prueba que alcanza y leer sus
resultados prueba que acierta**, y que la segunda la hice por accidente. Aquí las
dos son un paso:

**Que alcanza** — los positivos a un salto son, literalmente, el caso que el 15
daba por invisible:

```
PUT mis-actividades/finalizar-actividad -> exigirQueLaResueltaSeaSuya      abort(403)
PUT mis-actividades/mi-actividad        -> exigirQueLaActividadLeCorresponda abort(403)
PUT mis-actividades/seleccionar-opcion  -> exigirQueLaResueltaSeaSuya      abort(403)
```

**Que acierta** — tres negativos leídos a mano, y los tres confirmados sin
comprobación: `publicaciones/store` (solo `User::fromToken()`),
`calendario/this-year` (decide el cliente) y `votaciones/show/{id}`
(`return VtVotacion::findOrFail($id);`, una línea).

## Lo que se puede afirmar con esto detrás

**El inventario de autorización se puede cerrar**: no hay ninguna ruta que
compruebe a más de dos saltos, así que **67 es el número de rutas sin ninguna
comprobación, no «las que encontró este detector»**. De ellas, **32 están
declaradas y 34 de las 35 restantes son abiertas por diseño y agrupables en ocho
familias**.

Y el dato que le faltaba a la noche, ya con número: **51 rutas están defendidas
únicamente por dentro, y 9 de ellas a un salto**. Un inventario que mire la tabla
de rutas las cuenta como indefensas; uno que mire solo el cuerpo del método cuenta
como indefensas las 328 que defiende el middleware. **Las dos mitades hay que
sumarlas, y decir cuál se está midiendo.**
