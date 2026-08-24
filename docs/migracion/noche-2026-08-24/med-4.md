# MED-4 — confirmar o refutar las 115 candidatas

**Sesión `ad`**, rama `medicion/lote-y-cobertura`, árbol `.worktrees/ad`. Noche del
24 ago 2026, después de [MED-1](med-1.md) y [HIST-1](hist-1.md).

La [§175](../05-codigo-muerto-y-roto.md) mide **115 rutas no-`GET` que no escriben
nada** y lo dice de sí misma: *«es **candidatas a sólo lectura**. **Cuenta de más**
si el método llama a un servicio o a un modelo que sí escribe. Confirmar es leer el
método»*. Esto lee el método **y a quién llama**.

**Aquí el detector auditado no es el código: es la otra herramienta.**

| | |
|---|---|
| candidatas, medidas de nuevo | **110**, no 115 — y las 5 de diferencia reconciliadas una a una |
| de ésas, **escriben por un ayudante** | **18** |
| **sólo lectura** hasta donde se puede ver | **86** |
| **no decidibles**, con el motivo escrito | **6** |
| y un hallazgo que no estaba en la pregunta | **6 métodos escriben con `DB::select`** |

---

## §1 — Lo primero: el 115 era 110, y las cinco se reconcilian

Medido con [`tools/quien-escribe-de-verdad.py`](../../../tools/quien-escribe-de-verdad.py)
(nueva, sólo lee ficheros):

```
POBLACIÓN: 541 rutas leídas de routes/, 418 no son GET
  clases indexadas de app/ ......................... 216
  método no resuelto (herencia, closure, recurso) ... 0
  escriben en su PROPIO cuerpo ...................... 308
  candidatas a sólo lectura ........................ 110
```

Contra las **115** de `verbos-que-mienten.py`. **Mi lista es un subconjunto
estricto de la suya** —cero rutas mías que no estén en la suya— y las cinco de
diferencia son éstas, todas reclasificadas como **escritoras directas**:

| ruta | por qué escribe |
|---|---|
| `POST enfermeria/crear-suceso` | `INSERT INTO` en una cadena |
| `PUT enfermeria/datos` | `INSERT INTO` |
| `POST requisitos/alumno` | `UPDATE requisitos_alumno SET` |
| `PUT requisitos/update` | `UPDATE requisitos_matricula SET` |
| `PUT roles/addroletouser` | `INSERT INTO role_user` |

Las cinco escriben **SQL crudo dentro de una cadena**, sin ninguna de las marcas
que busca la otra herramienta. O sea que la diferencia no es un desacuerdo: es una
**vía de detección más**. Y de ahí sale el §3, que es lo gordo.

## §2 — De las 110: 18 escriben por un ayudante

Éstas son las que la §175 cuenta en el cajón equivocado, con el camino de llamadas
que lo demuestra:

```
POST   importar/algo/{year}              → PuntoDeControlDeImportacion::abrir [DB::insert]
POST   matriculas/matricular-en          → Matricula::matricularUno [->save(]
POST   matriculas/matricularuno          → Matricula::matricularUno [->save(]
POST   auth/login                        → Sesion::abrir / Login::entrar
PUT    ChangesAsked/solicitar-cambios    → crear_o_modificar_datos_de_pedido [DB::insert]
PUT    acudientes/guardar-valor          → GuardarAlumno::valorAcudiente [DB::update]
PUT    alumnos/guardar-valor             → GuardarAlumno::valor [DB::update]
PUT    alumnos/guardar-valor-varios      → GuardarAlumno::valor [DB::update]
PUT    alumnos/show                      → comprobar_alumno_con_grupos → traer_requisitos_detalle [DB::insert]
PUT    boletines/detailed-notas/{grupo}  → ponerAlDiaLasDefinitivas → DefinitivasDeAsignatura::recalcular
PUT    bolfinales/detailed-notas-year-group/{grupo} → detailedNotasGrupo [DB::update]
PUT    bolfinales/detailed-notas-year/{grupo}       → detailedNotasGrupo [DB::update]
PUT    notas/detailed                    → DefinitivasDeAsignatura::recalcular [DB::insert]
PUT    perfiles/creartodoslosusuarios    → createAndAsignUser [->save(]
PUT    respuestas/actividad              → WsActividadResuelta::alumnos_grupo [DB::table]
PUT    unidades/de-asignatura-periodo/…  → getDeAsignaturaPeriodo [DB::insert]
```

Tres de ellas merecen que alguien las lea con este dato delante:

- **`PUT alumnos/show` inserta.** Un `show` que escribe, por un `PUT`, a través de
  dos saltos. Es el peor caso posible para cualquiera que clasifique por el nombre
  **o** por el verbo: los dos dicen que lee.
- **`PUT bolfinales/detailed-notas-year-group/{grupo}` escribe**, y es exactamente
  el endpoint de la [§176](../05-codigo-muerto-y-roto.md) que **da 504 tras 60 s en
  el grupo 97**. Un tiempo de espera agotado sobre un endpoint que escribe no es lo
  mismo que sobre una lectura: **nginx corta la respuesta y la escritura sigue** —
  no hay transacción alrededor—, así que ese 504 puede dejar definitivas a medio
  actualizar. Quien mida esa §176 debería contar también qué escribió.
- **`PUT unidades/de-asignatura-periodo` y `PUT notas/detailed`** son los de la
  fase 3 de las definitivas: ya se sabía que recalculan, y aquí quedan con su
  camino escrito.

## §3 — El hallazgo que no estaba en la pregunta: **`DB::select` escribe**

Las cinco del §1 ejecutan su `INSERT`/`UPDATE` con **`DB::select`**. Y no es un
descuido aislado: barrido `app/` entero, **seis métodos** tienen SQL de escritura
cuyo **único ejecutor** es `select`:

```
ContextoDeUsuario::construir            [UPDATE users SET]
EnfermeriaController::postCrearSuceso   [INSERT INTO]
EnfermeriaController::putDatos          [INSERT INTO]
RequisitosController::postAlumno        [UPDATE requisitos_alumno SET]
RequisitosController::putUpdate         [UPDATE requisitos_matricula SET]
RolesController::putAddroletouser       [INSERT INTO]
```

**Funciona, y por eso nadie lo ha notado.** PDO no mira el verbo: `DB::select`
hace `prepare` + `execute` y devuelve `fetchAll()`, que en un `INSERT` es un array
vacío. La escritura entra. `RolesController:96` es el caso limpio —`INSERT INTO
role_user(user_id, role_id) VALUES(:user_id, :role_id)` pasado a `DB::select`— y
`ContextoDeUsuario` lo confirma con su propio comentario: *«el UPDATE de arriba ya
había corregido el periodo»*.

> **Así que en esta API no sólo miente el verbo HTTP: miente el nombre del método
> de la base.** La §175 dice que un `PUT` no dice si escribe; esto dice que un
> `select` tampoco.

### Y a qué le entra de lleno

1. **A una separación lectura/escritura.** Laravel enruta por nombre de método:
   un `DB::select` va a la conexión `read`. Con una réplica montada, estas seis
   escrituras **se irían al lector** — y la de `ContextoDeUsuario` es la peor de
   las seis por dónde vive, no por lo que hace: está en la clase que resuelve
   `$this->user`, en la rama que **repara** el `periodo_id` de un alumno cuyo
   periodo no es de su año. Si esa reparación va al lector, no ocurre nunca, y el
   alumno vuelve al bucle del *200 con el cuerpo vacío* que ese mismo comentario
   dice que se arregló. **Un fallo que sólo aparece el día que alguien añada la
   réplica, en el sitio donde nadie iría a buscarlo.**
2. **A la auditoría.** Cualquier cosa que clasifique «qué escribe» por
   `DB::insert|update|delete` deja estas seis fuera. Es la misma familia que la
   §175 y un nivel más abajo.
3. **A cualquier instrumentación.** Un cortafuegos que deje pasar los `select`
   —que es lo razonable— deja pasar seis escrituras.

**Matiz que hay que conservar**: el de `ContextoDeUsuario` está en una **rama de
reparación**, no en el camino normal. No se ejecuta en cada petición. Decirlo
importa porque «cada petición hace un UPDATE por select» sería más llamativo y
falso.

## §4 — Las 6 que no se pueden decidir, y por qué eso se imprime

Una herramienta de esta familia falla llamando **«sólo lectura»** a lo que en
realidad es **«no lo pude ver»**. Así que van en su propio cajón, con el nombre de
la llamada que no se pudo seguir:

```
POST   auth/logout          SesionController::logout llama a $sesion->cerrar()
POST   auth/refresh         SesionController::refrescar llama a $sesion->refrescoDe()
POST   login                Sesion::tokenPlanoDe llama a $peticion->bearerToken()
POST   tardanzas/login                        Credenciales::verificar → $usuario->getAuthPassword()
POST   tardanzas/login/traer-datos            ídem
POST   tardanzas/login/traer-datos-ausencias  ídem
```

Leídas a mano, que es lo que la herramienta deja hacer al bajar la lista de 110 a 6:

- **`auth/logout` y `auth/refresh` ESCRIBEN.** `Sesion::cerrar` hace `->delete()`
  ([Sesion.php:189](../../../app/Services/Sesion.php#L189)) y `refrescoDe` cuelga de
  la maquinaria de tokens que hace `->save()` y `DB::insert`. No se corrige en la
  herramienta porque `Sesion.php` está cogido por otra sesión esta noche; queda
  dicho aquí y **son escritores, no lectores**.
- **`POST login`** entra por el camino del login legado: crea sesión. **Escribe.**
- **Las tres de `tardanzas/login`**: `Credenciales::verificar` es una lectura
  —`User::where(...)->first()` y `Hash::check`— así que la duda es sólo formal.
  Lo que hagan después esas tres es otra cosa y no está resuelto.

O sea que el reparto real, con las seis decididas a mano, es **23 escritoras y 87
lectoras** de las 110 candidatas.

## §5 — Y ninguna de las 87 es de sólo lectura **a nivel de petición**

Lo midió [HIST-1 §5](hist-1.md): toda petición autenticada de esta API escribe

```sql
update `personal_access_tokens` set `last_used_at` = ?, `updated_at` = ? where `id` = ?
```

**No contradice la §175** —su herramienta busca marcas **dentro del método**, así
que por diseño no puede ver ésta, y su número contesta lo que dice contestar— pero
**decide para qué sirve ese número**:

- para clasificar **qué escribe un endpoint**: sirve;
- para montar **una réplica de sólo lectura** o un cortafuegos: **no sirve**, y las
  110 escriben una fila igual.

## §6 — El detector, y los tres fallos que tuvo antes de dar un número

Se cuentan porque el número no vale sin ellos, y porque los tres son la misma
clase de error: **una herramienta que busca texto en código necesita saber qué
parte del texto es código, y la respuesta no es la misma para cada cosa que
busca.** `--autoprueba` lleva las siete trampas.

1. **Las marcas se buscaban en el texto crudo.** `"no escribe: 'DB::insert' es
   texto"` contaba como escritura: el detector movía rutas al cajón «escribe»
   **por hablar de escribir**. Se corrigió buscando sobre el texto **sin cadenas ni
   comentarios**.
2. **Y entonces el SQL crudo dejaba de encontrarse**, porque vive dentro de una
   cadena. Se buscó en el texto entero — y ahí apareció el segundo fallo: el
   `/* … */` de `AcudientesController::putDatos`, que **explica** una limpieza hecha
   a mano una vez (*«esta consulta me sirvió para eliminar parentescos que quedaron
   al importar de Excel»*) seguido de un `delete from` de varias líneas. Contado
   como escritura. Hizo falta una **tercera vista**: sin comentarios, **con**
   cadenas.
3. **El «no decidible» empezó en 11 y bajó a 6**, y las dos reducciones importan:
   - **7 eran `Model::where` / `Model::destroy`**, que no están escritos aquí: los
     hereda Eloquent. **No añaden incertidumbre**, y ésa es la razón de no
     contarlos — lo que decide si esa cadena escribe es su llamada **final**
     (`->update(`, `->delete(`), y ésas ya se buscan en el mismo cuerpo;
   - **`app(Sesion::class)->abrir(...)` era una cuarta forma de llamada** que el
     detector no seguía, y por eso `auth/login` salía sin decidir **siendo el
     escritor más obvio de la API**. La encontró el propio informe: seis rutas sin
     decidir, tres de `SesionController`, y al leerlas las tres llamaban así.

> La delimitación del cuerpo también cambió: **se cuentan llaves en vez de cortar
> hasta la siguiente `function`**. Cortar por la siguiente `function` mete dentro
> el docblock de la de al lado y **parte el cuerpo por la mitad** si hay una función
> anidada con nombre, dejando el resto sin mirar. Contar llaves obliga a saltarse
> cadenas, y aquí eso no es teórico: **990 consultas crudas** en cadenas de varias
> líneas, algunas con `{` dentro.

## §7 — Lo que NO se hace aquí

- **No se toca `verbos-que-mienten.py`.** Es de otra sesión y está sin fundir; su
  número no está mal, está **incompleto en la dirección que ella misma declara**.
  Las dos herramientas contestan preguntas distintas y conviene que sigan las dos.
- **No se arregla ningún `DB::select` que escribe.** Cambiarlos a `DB::insert` /
  `DB::update` es un cambio de una palabra por sitio y **no cambia ningún
  comportamiento hoy** —de ahí que sea tentador hacerlo sin pedir permiso—, pero
  toca seis ficheros de los que dos están cogidos esta noche y uno es
  `ContextoDeUsuario`, que corre en cada petición. Es un cambio pequeño con un
  radio grande: lo decide quien coordina.
- **No se toca `Sesion.php`** ni ninguno de los ficheros cogidos.

## §8 — Lo que se lleva de método

1. **Auditar una herramienta es una medición como cualquier otra**, y se hace igual:
   con su población, reconciliando las diferencias una a una, y sin cuadrar a mano
   lo que no cuadre. Aquí cuadró: 115 − 5 = 110, con las cinco nombradas.
2. **Un subconjunto estricto es una señal buena.** Que mi lista no tuviera **ni una**
   ruta que no estuviera en la suya es lo que dice que las dos miran lo mismo y una
   mira más lejos. Si hubiera aparecido una ruta sólo en la mía, habría un tercer
   error que buscar.
3. **Separar «no lo pude ver» de «no escribe» es la mitad del valor.** Es el fallo
   que esta familia comete, y la lista de 6 con el motivo escrito es lo que permitió
   decidirlas leyendo, que es lo que la §175 pedía.
4. **Buscar texto en código son tres vistas y no una**: con todo, sin cadenas ni
   comentarios, y sin comentarios pero con cadenas. Cada cosa que se busca vive en
   una distinta, y usar la vista equivocada falla **sin fallar**.
