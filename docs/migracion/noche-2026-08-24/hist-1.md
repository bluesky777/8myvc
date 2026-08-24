# HIST-1 — la sesión que cuenta de menos

**Sesión `ad`**, rama `medicion/lote-y-cobertura`, árbol `.worktrees/ad`. Noche del
24 ago 2026, después de [MED-1](med-1.md).

`PUT historiales/sesion` es la pantalla con la que el colegio contesta **«¿qué se
tocó en esta sesión?»** — la que pidió Joseth. El encargo venía con una lectura y
una pregunta: *«cuenta de menos por dos motivos independientes; mide cuál se ve
antes»*.

**Son cuatro, no dos. Y la que se ve antes no es ninguna de las dos.**

| | La causa | Cuándo se ve |
|---|---|---|
| **1** | la bitácora **no es de una nota** y se une a `notas` igual | **en casi todas las sesiones** |
| **2** | la nota **ya no existe** — y `notas/destroy` borra **duro** | cuando alguien borra una nota |
| **3** | la **subunidad** está borrada | cuando alguien quita una columna |
| **4** | el **alumno** está borrado | 3,04% de los alumnos |

Y una quinta que **no es contar de menos, es contar mal**: una bitácora que no es
de una nota puede volver **atribuida a otra**, y lo único que hoy lo impide es un
accidente.

---

## §1 — La consulta, y por qué son cuatro cosas y no una

Las bitácoras de la sesión salen de una sola consulta
([HistorialesController:135](../../app/Http/Controllers/Historiales/HistorialesController.php#L135)):

```sql
SELECT b.*, a.nombres, a.apellidos, s.definicion FROM bitacoras b
  INNER JOIN alumnos a     ON b.affected_user_id = a.id AND a.deleted_at IS NULL
  INNER JOIN notas n       ON n.id = b.affected_element_id
  INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
 WHERE b.historial_id = ? AND b.deleted_at IS NULL
```

Tres `INNER JOIN` y **ningún filtro por `affected_element_type`**. Eso último es
la causa 1 y es la grande: `affected_element_id` es un id **de la tabla que diga
el tipo** —de `subunidades` en «Nueva subunidad», de `users` en
`AlumnoPideAjeno:user_id`, de `notas_finales` en `NF_UPDATE`— y la consulta lo une
con `notas` para todas.

> Y una que **no** hay que arreglar, porque parece un error y no lo es: el join de
> `alumnos` va por `a.id` y el código lleva al lado un bloque comentado que dice
> *«se supone que debe ser con el user_id, pero la embarré»*. **La versión viva es
> la correcta**: los escritores de bitácora de notas ponen `notas.alumno_id` en
> `affected_user_id`, o sea un `alumnos.id`. Quien lea ese comentario y «arregle»
> el join lo rompe.

## §2 — Cuál se ve antes: **el tipo**, y por mucho

Medido con [`tools/historial-que-cuenta-de-menos.php`](../../../tools/historial-que-cuenta-de-menos.php)
(nueva, **sólo lee**), sobre la copia de desarrollo:

```
POBLACIÓN
  sesiones anotadas (`historiales`) ............ 3.270
  bitácoras vivas .............................. 86
  de ésas, con una sesión detrás ............... 34
  sesiones que tienen alguna bitácora .......... 18 de 3.270

  tipo                             total  vuelve   alumno    nota   subunidad
  ----------------------------------------------------------------------------
  Nueva subunidad                     14       0       14       0           0
  AlumnoPideAjeno:user_id              5       0        0       5           0
  Nota                                 4       4        0       0           0
  NF_UPDATE                            3       0        0       3           0
  AcudientePideAjeno:alumno_id         3       0        0       3           0
  AcudientePideAjeno:user_id           3       0        0       3           0
  AlumnoVerBoletin                     1       0        0       1           0
  YEAR CONFIGURACION                   1       0        1       0           0
  ----------------------------------------------------------------------------
  TOTAL                               34       4       15      15           0

  De las 34 bitácoras de una sesión, la pantalla enseña 4 y se calla 30 (88%).
```

**Las 4 que vuelven son las 4 de tipo `Nota`.** O sea:

> **La pantalla no cuenta de menos las notas —ésas las cuenta bien— sino LA
> SESIÓN, que es lo que dice su nombre.** Un profesor que en una sesión creó una
> subunidad, abrió dos boletines y cambió una nota aparece como si sólo hubiera
> hecho lo último.

Y por eso el **orden de los arreglos** decide si el usuario nota algo:
**arreglar los `INNER JOIN` sin poner el filtro por tipo no cambia nada** en la
mayoría de las sesiones, porque las que se caen no se caen por estar borradas
sino por no ser notas.

### Lo que este número es y lo que no es

**34 bitácoras es una población diminuta, y la mayoría las escribieron nuestras
propias pruebas sobre la copia.** Así que las **proporciones de arriba no son las
de producción**: dicen qué causas están **activas**, no cada cuánto pasan.

Lo que sí generaliza son **las precondiciones**, medidas sobre las tablas grandes:

| Causa | Precondición medida |
|---|---|
| 1 · el tipo | **9 tipos distintos** de bitácora se guardan, y la consulta une los nueve con `notas` |
| 3 · subunidad borrada | **35.796 notas de 1.165.565 (3,07%)** cuelgan de una subunidad borrada |
| 4 · alumno borrado | **39 de 1.284 (3,04%)** |
| 2 · nota borrada | **no se puede medir** — ver abajo |

## §3 — La causa que ninguna herramienta puede medir, y la que más duele

`notas/destroy` es **`DELETE FROM notas WHERE id=?`**
([NotasController:756](../../../app/Http/Controllers/NotasController.php#L756)):
un borrado **duro**, aunque el modelo `Nota` use SoftDeletes. Dos consecuencias, y
la segunda es la que hay que leer dos veces:

1. **la bitácora sobrevive y su nota no**, así que el `INNER JOIN` la pierde. La
   pantalla que existe para saber quién tocó una nota **se calla justo el caso que
   más se reclama**: el de la nota que ya no está;
2. **no deja rastro contable.** `COUNT(*) FROM notas WHERE deleted_at IS NOT NULL`
   da **cero**, y ese cero no dice «no se borran notas»: dice **«las borradas no
   están»**. Es la única de las cuatro cuya frecuencia **no se puede medir**, ni
   aquí ni en el servidor.

> Es la forma exacta que persigue CLAUDE.md —*un «0 encontrados» no distingue
> «revisé 466 y ninguno lo era» de «no revisé nada»*— con una vuelta más: aquí el
> cero es correcto, la tabla está bien contada, y **la pregunta que uno creía estar
> haciendo no se puede hacer sobre esa tabla.**

**Por eso esa causa se demuestra en un test y no en la herramienta**: la única
forma de verla es borrar una nota y mirar qué pasa, y la única forma de que eso sea
inocuo es la transacción de un test. Es el mismo motivo por el que
`SuperficieDeUnTokenTest` vive en `tests/` y no en `tools/`.

## §4 — Contar mal, que es peor que contar de menos

La consulta une por `affected_element_id` **sin mirar el tipo**, así que basta con
que ese id —que es de otra tabla— **exista en `notas`** para que la fila vuelva con
el nombre del alumno y la definición de la subunidad **de una nota que no tiene
nada que ver**. `notas` tiene **1.165.565 filas**: cualquier id pequeño de
cualquier otra tabla existe ahí con mucha probabilidad.

Medido: **15 de las 34 bitácoras ya pasan los joins de `notas` y `subunidades`**.
Lo único que las tumba es que su `affected_user_id` no sea un alumno vivo.

> **Que hoy no salga ninguna mal atribuida no es por diseño: es por accidente.** Y
> el accidente tiene fecha de caducidad — `AcudientePideAjeno:alumno_id` **ya
> lleva un alumno** en esa columna. El día que una bitácora de ese tipo caiga sobre
> un id que exista en `notas`, sale en la pantalla como una nota.

Hay un test que lo fija **hoy, con el borde abierto**, para que el día que se
cumpla ya esté escrito qué pasa — y que además **avisa al revés**. Los dos modos en
que puede fallar dicen cosas distintas, y por eso lleva dos mensajes:

- **la fila ajena no vuelve** → alguien filtró por tipo **y además** dejó los
  `INNER JOIN`;
- **vuelve sin `definicion`** → alguien filtró por tipo y pasó a `LEFT JOIN`. Es lo
  que de verdad ocurre con el arreglo probable, comprobado en el §6.b: la mala
  atribución desaparece y la fila sale, pero muda.

## §5 — El segundo motivo del encargo: el `PUT` que no escribe

`historiales/sesion` es una de las **115 rutas no-`GET` que no escriben** de la
[§175](../05-codigo-muerto-y-roto.md). El test que lo comprueba se escribió
esperando **cero escrituras** y salió **una**:

```sql
update `personal_access_tokens` set `last_used_at` = ?, `updated_at` = ? where `id` = ?
```

**No es del endpoint: es la contabilidad del token, y la hace toda petición
autenticada de esta API.** O sea que la afirmación exacta es «**el método** no
escribe», no «**la petición** no escribe». No contradice la §175 —su herramienta
busca marcas de escritura **dentro del método**, así que por diseño no puede ver
ésta y su número sigue contestando lo que dice contestar— pero **afina para qué
sirve ese número**:

- quien lo use para clasificar «qué escribe este endpoint»: correcto;
- quien lo use para montar **una réplica de sólo lectura** o un cortafuegos de
  medición: **las 115 escriben una fila igual**, y un `PUT` que «no escribe»
  revienta contra una réplica.

Por eso el aserto del test **no es un cero**: es «exactamente esta escritura y
ninguna más», más un segundo aserto de que **la del token sí está**. Un cero se
rompería el día que alguien añada la línea de verdad, y —esto es lo que importa—
**un `DB::listen` que no se engancha da cero escrituras y parece un éxito**.

## §6 — Lo que se entrega, y lo que NO se decide aquí

| | |
|---|---|
| `tools/historial-que-cuenta-de-menos.php` | mide las cuatro causas y su precondición. **Sólo lee.** El `for` de los dieciséis queda escrito dentro, sin correr |
| `tests/Contrato/HistorialDeSesionCuentaDeMenosTest.php` | **6 tests, 86 aserciones**: una por causa, la mala atribución, y el `PUT` que sólo escribe la marca del token |

**Ninguno de los dos arregla la consulta**, y eso es a propósito. Cambiarla es
cambiarle la respuesta a una pantalla desplegada en dieciséis colegios, y antes hay
que decidir **qué debe enseñar una bitácora que no es de una nota**:

- ¿una línea genérica —«creó una subunidad»— sin nombre de alumno ni de columna?
- ¿o se filtra por `affected_element_type = 'Nota'` y la pantalla pasa a llamarse
  «las notas que tocó esta sesión», que es lo que de verdad enseña hoy?

**Son dos pantallas distintas y la diferencia es de negocio, no de SQL.** Lo que
esta sesión deja es que la pregunta esté hecha con números al lado.

Y una que sí es sólo técnica y no espera a nadie: **la causa 4 puede ser lo que se
quiere.** Un alumno retirado quizá no deba salir. Lo que no puede ser es que **lo
decida un `INNER JOIN`** y que la pantalla no distinga «no hay rastro» de «hay
rastro de alguien a quien no te enseño».

## §6.b — Comprobado al revés: el arreglo probable tumba los cinco

Un test que fija el estado actual sólo vale si **cae cuando el estado cambia**. Así
que se aplicó el arreglo candidato —los tres `INNER JOIN` a `LEFT JOIN` **y** el
filtro `AND b.affected_element_type = "Nota"` en el join de `notas`— y se corrió:

```
⨯ al borrar la nota su bitacora desaparece de la sesion aunque siga en la tabla
⨯ una bitacora que no es de una nota no sale en la sesion
⨯ una subunidad borrada se lleva la bitacora de sus notas
⨯ un alumno borrado se lleva su rastro de la sesion
⨯ una bitacora ajena con alumno vivo sale atribuida a otra nota
✓ pedir el detalle de una sesion solo escribe la marca del token

Tests: 5 failed, 1 passed
```

**Los cinco de comportamiento caen y el de las escrituras no**, que es la forma
correcta: si el arreglo hubiera tumbado los seis, los tests estarían mirando el
estado general y no lo que cada uno afirma.

Y el quinto cayó **por su segundo mensaje**, no por el primero: *«la fila ajena
vuelve SIN el nombre de la subunidad»*. O sea que con ese arreglo la mala
atribución **se cierra** —la fila sale muda en vez de disfrazada de nota—, que es
justo el dato que hacía falta para poder decidir. La mutación se revirtió con
`git checkout --`; los seis vuelven a verde.

> Lo que esto convierte en un hecho, y es el argumento para tocar la consulta:
> **el arreglo de una línea —el filtro por tipo— cierra a la vez la causa 1 y la
> mala atribución.** Lo que queda por decidir sigue siendo de negocio: si esas
> filas mudas deben aparecer, y con qué texto.

## §7 — Lo que se lleva de método

1. **«Dos motivos» era el suelo, no el techo.** El encargo traía dos causas y la
   consulta tiene cuatro. Enumerar lo que puede fallar **antes** de medir cuál pesa
   evita arreglar la que no se ve.
2. **Un cero puede estar bien contado y no contestar la pregunta.** Las notas
   borradas no se pueden contar porque el borrado es duro: la tabla dice la verdad
   y la verdad no sirve.
3. **Lo que hoy funciona por accidente hay que escribirlo mientras funciona.** La
   mala atribución la frena un join que no está ahí para eso, y el dato que la
   destapa —una bitácora ajena con un alumno vivo— ya existe en el esquema.
4. **Un aserto de «cero» es frágil en las dos direcciones.** Cero escrituras no
   distingue «no escribe» de «el oyente no está enganchado». Se afirma **qué** hay,
   no sólo cuánto.
5. **Un test que fija el estado actual hay que romperlo a propósito antes de
   creerlo**, y hay que mirar **cuál** de sus mensajes salta: el del §4 tenía dos
   modos de fallo previstos y el que se dispara con el arreglo probable es el
   segundo. Saber cuál salta es lo que hace que el mensaje sirva de indicación a
   quien lo encuentre en rojo dentro de seis meses.
6. **A `tools/` no se le pasa Pint**, y no por gusto: no está en la lista del
   `composer.json`, y su regla `fully_qualified_strict_types` acortó la clase del
   kernel y puso el `use` **debajo** de la línea que la usa. PHP lo resolvería —los
   `use` no son sentencias— pero el contenedor recibe la cadena literal y revienta
   con `Class "Kernel" does not exist`. Larastan lo cazó primero, como un
   `class.notFound` que parecía un error del fichero y era del **orden**.
