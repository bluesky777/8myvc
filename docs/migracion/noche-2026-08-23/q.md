# Lote Q — el calendario, donde el cliente decidía si el interruptor se aplicaba

> Sesión `8myvc-1e`, noche del 22 al 23 de agosto de 2026. Árbol
> `.worktrees/q`, rama `fix/lote-q-calendario`, base `simonbolivar_testing_q`,
> arrancada de `93ec58a`.
>
> Secciones asignadas del 05: **§150–139**.

---

## §150. `solo_profes` funcionaba; lo que llegaba del cuerpo era el permiso

`PUT api/calendario/this-year` —`auth.token` y nada más— empezaba así:

```php
$is_prof_admin = Request::input('is_prof_admin');      // ← del CUERPO
if ($is_prof_admin == 'true') { … SELECT * FROM calendario … }
else                          { … WHERE solo_profes = 0 … }
```

`calendario.solo_profes` es el interruptor con el que el colegio marca un evento
como **interno**. Medido antes de tocar nada, con eventos internos creados en el
propio test —no buscados en el seed, para comparar **ids** y no un número—:

| Token | Cuerpo | ¿Ve los internos? |
|---|---|---|
| Alumno | vacío | **no** |
| Alumno | `is_prof_admin=true` | **sí** |
| Acudiente | `is_prof_admin=true` | **sí** |
| Profesor | vacío | **no** |
| Administrativo | vacío | **no** |

O sea que **el permiso lo decidía enteramente el cuerpo, para todos**. La columna
no fallaba: sin la bandera, un alumno veía exactamente los públicos.

> **No hay que enseñar a nadie a leer una columna: hay que mover de sitio un
> dato.** Es lo que separa esto de la [§74](../05-codigo-muerto-y-roto.md), donde
> el interruptor no lo miraba nadie.

### 137.1 El criterio, y por qué el que parecía mejor era peor

El arreglo obvio era el de `ExigirPersonal` —«personal del colegio es el que no
es alumno ni acudiente», decisión de Joseth del 18 ago—, y **razonando** parecía
el bueno: el front manda `IS_PROF_ADMIN = hasRoleOrPerm(['admin', 'profesor'])`,
que es un criterio de **rol**, y «rol `Admin` sin `is_superuser`» es un conjunto
que el esquema permite. Con ese criterio, esas cuentas conservarían lo que ven.

**Contado en la base, ese conjunto está vacío aquí.** De las 20 cuentas de tipo
`Usuario`:

```
  is_superuser=1  rol Admin=1  →  10
  is_superuser=0  rol Admin=0  →  10
```

Los dos conjuntos coinciden. Así que el `if` que **ya usan las otras cuatro rutas
de este mismo controlador** —`($user->tipo == 'Profesor') || $user->is_superuser`—
reproduce **exactamente** lo que ve hoy cada persona, y «no es familia» habría
**ampliado** el calendario interno a diez cuentas administrativas —secretaría,
coordinación, enfermería, rectoría—.

**Ampliar no es arreglar.** Y el criterio elegido no es nuevo: es el que ya
decidieron sus cuatro hermanas, que es lo que evita acabar con cuatro criterios
para el mismo módulo.

> Lo que se lleva de aquí: **el criterio se eligió contando cuentas, no leyendo
> código.** Los dos candidatos eran defendibles sobre el papel y sólo se
> distinguen en una población que hay que ir a contar — y esa población resultó
> estar vacía.

### 137.2 Comprobado al revés, dos veces

- Con el criterio de «no es familia»: **cae un caso y sólo uno**, el del
  administrativo. O sea que ese caso es el único que separa los dos arreglos, y
  sin él los dos habrían salido verdes.
- Y las dos mitades están fijadas: que la familia **deje** de verlos y que el
  personal **siga** viéndolos sin mandar nada. Sin la segunda, «no enseñárselos a
  nadie» pasaría verde y apagaría el calendario interno del colegio.

### 137.3 Lo que no se decide

**Si `solo_profes` debe significar «solo profesores» o «solo personal» es una
pregunta para Joseth**, y hoy significa lo primero. Queda anotado; el día que se
conteste, el caso del administrativo es el que cae y dice qué se decidió.

---

## §151. Por qué no lo vio ningún candado: las 18 familias que **nunca entran**

El candado de familia de `AutorizacionTest` —«si un prefijo tiene dos o más rutas
con guard de propiedad y alguna sin él, esa alguna se mira»— tiene dos puertas de
salida, y sólo una estaba declarada.

Medido sobre las 538 rutas de la API, con la misma función que usa el test:

| | familias | rutas |
|---|---|---|
| **Entran** en el candado | 70 | 400 |
| **Se salen** por tener demasiadas abiertas (`sinGuard > max(2, n/4)`) — el §114 | 7 | 88 |
| **Nunca entran** porque `conGuard < 2` | **18** | **50** |

Las que nunca entran, por tamaño:

```
publicaciones  0 de 8      calendario   0 de 5      paises        1 de 2
login          0 de 7      enfermeria   1 de 5      piars-config  1 de 2
tardanzas      0 de 6      auth         0 de 5      … y 10 con una sola ruta
```

De esas 50 rutas, **23 llevan verbo de escritura y no llevan guard de propiedad**
(sin contar `login/*` y `auth/*`, que son pre-login por diseño).

**Se cuenta por el verbo, y el verbo no es la operación**: al menos tres de las 23
sólo leen —`PUT api/publicaciones/ultimas`, que es el formulario público de
prematrícula y va por PUT desde 2024, y las dos `POST api/tardanzas/login/traer-datos*`—.
Se quedan dentro a propósito: quitarlas a mano convertiría un recuento mecánico en
una lista curada, y el día que una empiece a escribir de verdad no lo diría nadie.

**Y «sin guard» no es «abierta»**, que es la mitad que hay que decir para que la
lista no sea peor que no tenerla:

- `RutasPreLoginTest::test_ninguna_otra_ruta_responde_sin_token` recorre **todas**
  las rutas sin cabecera y comprueba que ninguna contesta 2xx salvo las doce
  públicas. Está en verde, así que **ninguna de las 23 es alcanzable sin token** —
  incluidas las cuatro de `tardanzas/*` que aparecen con middleware `—`, que se
  defienden por dentro.
- Y varias comprueban por dentro: las cuatro escrituras de `enfermeria/*` abortan
  403 si no tienes el rol, y las de `publicaciones/*` usan
  `exigeQueLaPublicacionSeaSuya`.

Lo que **ningún mecanismo pregunta nunca** a esas 23 es la otra pregunta: **de
quién es la fila que tocan.** Ahí es donde estaba `calendario/this-year`, y por eso
tardó.

> Una familia se sale del candado por tener demasiadas puertas abiertas, y **no
> entra nunca por tener demasiadas pocas cerradas**. La segunda es más silenciosa:
> la primera aparece en el snapshot del §114, y la segunda no aparece en ninguna
> parte, porque el `continue` que la descarta no deja rastro.

### 138.1 Y ahora deja rastro

`FamiliasQueNuncaEntranTest`, dos casos y dos snapshots: **las 18 familias con
cuántas de sus rutas llevan guard**, y **las 23 rutas** de escritura que viven
donde el candado no llega.

La lista y no sólo el número: un 18 que sigue siendo 18 porque una familia entró y
otra nació no diría nada, y es justo el movimiento que hay que ver. Y el número de
escrituras está escrito con su mensaje: si **sube**, hay una ruta nueva a la que
ningún mecanismo va a preguntar de quién es la fila que toca; si **baja**, alguien
puso un guard o la familia llegó a dos hermanas guardadas — bien, y hay que
actualizar el número.

---

## §152. Las otras cuatro del calendario, medidas de paso

Las cuatro escrituras —`crear-evento`, `guardar-evento`, `eliminar-evento` y
`sincronizar-cumples`— comprueban **todas** `($user->tipo == 'Profesor') ||
$user->is_superuser` y abortan 403. O sea que **la lectura y la escritura usan
ahora el mismo criterio**, que es lo que se quería.

Dos cosas quedan dichas y no se tocan:

- **Un administrativo sin superusuario no puede crear ni editar ningún evento**,
  ni siquiera público. Es de antes de esta noche y no lo cambia este lote; se
  anota porque la secretaría del colegio es justo quien pondría las fechas.
- `guardar-evento` y `crear-evento` aceptan `solo_profes` del cuerpo, y **eso está
  bien**: ahí el que escribe es el que decide si su evento es interno. La §150 no
  va de quién marca el interruptor, sino de quién decide si se le aplica.

---

## §152.1 El renglón que sí acertó

Al arreglar el §150 se puso rojo `CalendarioSoloProfesTest`, del lote J. En su
docblock, escrito **antes** de que este lote existiera:

> *«Si alguien lo arregla, este test cae, y lo que hay que hacer es cambiar el
> `assertGreaterThan` por la comprobación contraria. **Que caiga es el aviso.**»*

Esta noche ha coleccionado cinco renglones que apagaban una pregunta: una tabla de
veredictos que atribuía a una ruta el cierre de su vecina ([§90](c.md)), la
cabecera de una herramienta que decía «dos» por cuarenta y nueve ([§105](g.md)),
una columna calculada que llamaba comprobada a una ruta porque validaba un nombre
de columna ([§107.1](g.md)), una etiqueta que clasificaba un listado de grupos como
«Personas» ([§132](o.md)) y una lista de tablas escrita antes de la decisión que
la ampliaba ([§92.0](c.md)).

**Éste es el mismo tipo de renglón y hace lo contrario.** Y la diferencia no es que
esté mejor escrito:

> Los cinco que fallaron afirmaban **un estado** —«ya está», «son dos», «esto es de
> personas»—, y un estado envejece en silencio. Éste afirmaba **una condición de
> caducidad**: *cuando pase esto, este test caerá, y entonces hay que hacer lo
> otro.* Un veredicto se queda viejo sin avisar; **una condición de caducidad
> avisa el día que se cumple**, porque quien la cumple es justo el que rompe el
> test.

Por eso el caso viejo **no se ha borrado**: se le ha dado la vuelta y se conserva
al lado del nuevo. Lo que uno afirmaba y lo que afirma ahora, juntos, son lo que
cuenta qué se decidió — que es lo que un `git log` no dice cuando alguien borra un
caso y escribe otro.

---

## Lo que se nota en un colegio (para DESPLIEGUE.md)

| Cambio | Antes | Después | Quién lo nota |
|---|---|---|---|
| §150 `PUT api/calendario/this-year` | un alumno o un acudiente que mandara `is_prof_admin=true` recibía los eventos marcados **solo para profesores** | los recibe el personal, según el token | **nadie de las pantallas**: el front manda ese campo desde un solo sitio y con el mismo predicado. Lo que cambia es que ya no se puede pedir a mano |

Ninguna migración.
