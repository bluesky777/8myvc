# PROFES-1 — Corregirle el teléfono a un docente le cambiaba la cuenta

> Lote de `8myvc-d2`. Medición de partida de `myvc-front-89` vía `8myvc-34`,
> escrita en el 05 §173. **Verificada, y corregida en un punto.**

## §1. Lo que pasaba

`PUT profesores/update/{id}` —la rejilla de docentes, con la que se corrige un
teléfono— hacía tres cosas que nadie pidió, en la misma petición:

```
username      ZZTestFirma  ->  ZZTest             RENOMBRA
is_superuser  1            ->  0                  DEGRADA
email_usu     …@example    ->  ZZTest@myvc.com    pierde el correo de recuperación
```

Con `users.username` UNIQUE, la primera **deja a alguien fuera del sistema**. Es
la [§11](../09-pendientes.md) por la otra puerta: allí era **quién puede**
renombrar, aquí es que **se renombra sin que nadie lo pida**.

## §2. La causa, y por qué una guarda que existía no la paraba

`sanarInputUser()` **fabrica tres campos** cuando no vienen —y la rejilla no manda
ninguno de los tres—: `username` desde `nombres`, `is_superuser` a `false`, y
`email2` desde el correo de la ficha.

El escritor ya usaba `CamposQueVinieron` para `is_active` y `email2` desde la §68,
y estaba bien usada: `capturar()` corre **antes** de los dos `sanar*`. Lo que
faltaba era distinto en cada campo:

| Campo | Qué le pasaba |
|---|---|
| `username` | **sin guardar**. Y peor: era la condición de entrada del bloque (`if ($profesor->user_id and Request::input('username'))`), que tras la fabricación **es cierta siempre** |
| `is_superuser` | **sin guardar** |
| `email2` | **guardado, y aun así pisado** — ver abajo |

### La grieta: `trae()` contesta «¿vino?», no «¿es éste el valor que vino?»

La llave del correo es `if (!Request::input('email1'))`. **`email1` no existe:
cero apariciones en los cuatro clientes** —comprobado en `myvc_front`,
`myvc_front-fase11`, `myvc_front_2` y `myvc_flutter`—. Así que esa rama corre
**siempre** y sustituye el `email2` que el cliente sí manda.

Y desde arriba era invisible: `$vinieron->trae('email2')` contesta que **sí vino**
—vino— y escribe el valor **ya sustituido**. La guarda estaba puesta y no
protegía.

## §3. La pregunta que había que contestar, y la respuesta es NO

`8myvc-34` la planteó bien: *¿debe `CamposQueVinieron` contestar también «¿es éste
el valor que vino?»*. Si sí, toca quince ficheros y **es una decisión, no un
arreglo**.

**Medido, y la respuesta es que no hace falta:**

```
usan CamposQueVinieron ........................ 14   (el 15º era la propia clase)
con Request::merge() o un sanador delante ...... 9   <- sitios donde MIRAR
  solape entre lo que mergean y lo que guardan . 5
  pisotón de verdad ............................ 2   <- Profesores y Alumnos
```

**El 9 era un número de sitios donde mirar, no una lista de fallos**, y leerlo
como lista habría convertido un arreglo en una refactorización de quince ficheros.
Abiertos los cinco solapes uno a uno:

| Fichero | Solape | ¿Pisotón? |
|---|---|---|
| `AsignaturasController` | `profesor_id`, `grupo_id` | **No.** El merge está guardado por ausencia: `if (!Request::input('x') and …)`. Si `trae('x')` es cierto, el merge no corrió |
| `GradosController` | `nivel` | **No.** Igual, guardado por ausencia |
| `MateriasController` | `area` | **No.** El merge **normaliza** el objeto a su id bajo la misma clave. Transformación deliberada, no sustitución |
| `ProfesoresController` | `email2` | **Sí** |
| `AlumnosController` | `email2` | **Sí** — el gemelo, con el mismo `email1` muerto |

**El patrón de la casa ya era correcto**: los merges o preguntan antes, o
normalizan. El único pisotón es una **condición muerta**, y se arregla donde está.

## §4. El arreglo

Tres cambios, todos en `ProfesoresController`:

1. **`if (!Request::input('email1'))` → `if (!Request::input('email2'))`.** Es lo
   que la condición quería decir: *derivar un correo sólo si no hay ninguno*. Una
   palabra, y la rama pasa de muerta a útil — quitarla habría perdido el defecto
   del alta, que sí es correcto.
2. **`username` e `is_superuser` se guardan con `trae()`**, como ya hacían sus dos
   vecinos desde la §68.
3. **La puerta del bloque pasa a ser `if ($profesor->user_id)`.** Antes exigía
   además un `username` que la fabricación garantizaba, así que no filtraba nada y
   escondía las dos escrituras. De paso arregla un caso latente: con `nombres`
   vacío, `username` salía vacío y el bloque **entero** se saltaba, así que ni la
   contraseña ni `is_active` se escribían.

**No se toca `CamposQueVinieron`**, ni los quince ficheros, ni `AlumnosController`
—que tiene el gemelo y va en su propio commit, por instrucción del lote—.

## §5. La corrección a la medición de partida

**Los tres síntomas del parte no tienen la misma causa**, y se supo comprobando al
revés: revertido el arreglo, **caen 4 de los 5 tests**. El que no cae es el del
correo.

> **«Corregir el teléfono pierde el correo de recuperación» sólo pasa si la
> rejilla SÍ manda `email2`.** Con un cuerpo que no lo manda, la guarda de la §68
> ya protegía desde el 21 ago. Lo que el pisotón rompe es el caso en que el
> cliente manda su valor y llega sustituido.

No cambia el arreglo. Cambia lo que hay que decirle al front: **su rejilla manda
`email2`**, y ése es el dato que explica el síntoma que midieron.

Ese test se conserva a propósito, con el porqué escrito dentro: fija que el caso
de la §68 sigue cubierto.

## §6. Lo que enseñó, y no es del código

- **El «comprueba al revés» encontró lo que la medición no vio.** Cinco tests, cae
  el que tiene que caer y uno no: eso es lo que delató que la tercera causa era
  otra. Un «los cinco en verde» no lo habría dicho.
- **Larastan avisó de la sobra que deja simplificar.** Al pasar la puerta a
  `if ($profesor->user_id)`, el `else if (!$profesor->user_id and …)` de al lado
  pasó a ser redundante: *«Negated boolean expression is always true»*. La sobra
  aparece justo cuando se simplifica una condición y nadie mira la de al lado.
- **Y una de infraestructura**, que costó media hora: la suite empezó a dar
  deadlocks y luego `getaddrinfo for database failed`. No era el código —era
  `8myvc-database-1` en `Exited (137)`, o sea **SIGKILL por falta de memoria** con
  varias suites a la vez, y **quince `phpunit` huérfanos dentro del contenedor**
  realimentando el problema. **Una base por sesión evita los deadlocks, no el
  OOM**: el límite es el contenedor. Y `ps` en el host no ve los procesos de
  dentro.

## §7. Lo que queda

- **`AlumnosController` tiene el mismo sanador y el mismo `email1` muerto.** Es el
  segundo pisotón de la tabla y va en su propio commit.
- **El front tiene que dejar de mandar `username` e `is_superuser`** desde esa
  rejilla. Con este arreglo ya no hacen daño, pero mandarlos sigue siendo mandar
  algo que esa pantalla no edita.
- **Nada que desplegar todavía**: no hay decisión de Joseth sobre esta tanda.
