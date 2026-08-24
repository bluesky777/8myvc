# BAR-1 — el barrido con los cuatro roles, y los tres puntos ciegos que tiene

> **Sesión `8myvc-39`, noche del 24 ago 2026.** Cuatro pasadas de
> `tests/Barrido/SuperficieDeUnTokenTest` con `BARRIDO_TIPO`, dentro del turno del
> docker cogido con `turno.sh` y soltado al terminar.
>
> Era la comprobación cruzada de [med-5.md](med-5.md): yo **leí** las puertas, el
> barrido las **ejerce**. Si coinciden, el cuadro está confirmado por dos vías; si
> no, **el que está mal es el que leyó**.
>
> **Y el resultado no es ninguna de las dos cosas.** El barrido **no puede
> confirmar ni desmentir seis de las dieciocho filas**, y la razón es estructural.
> Eso es el entregable.

---

## 1. Con qué se midió — sin esto, ningún número de abajo se puede releer

| | Sujeto | `is_superuser` | Roles | Año |
|---|---|---|---|---|
| `Alumno` | `users_2375` | 0 | Alumno | **8 (2025)** |
| `Acudiente` | `users_488` | 0 | Acudiente | 8 |
| `Profesor` | `users_8` | 0 | Profesor | 8 |
| `Usuario` | `users_679` | 0 | **ninguno** | 8 |

**El año no es el de los sujetos**, y eso hay que decirlo porque leídos antes de
entrar están en 2021, 2018 y 2018: `Services\Login` **reescribe
`users.periodo_id` al periodo del año actual al entrar**, así que se mide en el 8.
Reportar el año de la ficha habría dicho «medido en 2018» de una medición hecha en
2025.

> Los `is_superuser` y los roles **los imprime el propio barrido**, que era la
> mitigación que yo pensaba añadir a mano por el hueco del §204 —el guardián del
> sujeto existe para `Usuario` y falta para `Profesor`—. Ya está resuelto en la
> salida. **De 19 `Profesor` activos, 0 son superusuario**, así que el sujeto es
> llano; sigue siendo **una propiedad de este seed y no del código**.

**Y los interruptores del año 8**, porque acotan lo que se puede alcanzar:

```
alumnos_can_see_notas 0 · profes_can_edit_alumnos 0 · mostrar_puesto_boletin 1
mostrar_nota_comport_boletin 1 · puestos_alfabeticamente 0 · show_fortaleza_bol 0
show_materias_todas 0 · show_subasignaturas_en_finales 1 · solo_escalas_valorativas 1
si_recupera_materia_recup_indicador 1 · year_pasado_en_bol 0
mensaje_aprobo_con_pendientes 1 · prematr_antiguos 0 · prematr_nuevos 0
```

Dos importan:

- **`alumnos_can_see_notas = 0`.** La pasada de `Alumno` se hizo **con las notas
  apagadas para los alumnos**, así que **un «no alcanza» de ese token puede ser el
  guard o puede ser el interruptor, y la salida no lo distingue.** Atribuirlo al
  guard sería **darle a la puerta el mérito del conmutador**.
- **`show_fortaleza_bol = 0`**, que confirma por otro camino lo que midió `9e`: **1
  de 8 años lo tiene encendido y ningún test usa ese año**. Ni su suite ni este
  barrido ejercen lo que ese interruptor abre. **Ese trozo no lo prueba nadie.**

---

## 2. Lo que alcanza cada rol — y el número que importa

| Rol | Rutas que **escribieron** | de ellas `GET` | pasaron de largo con algo dentro |
|---|---|---|---|
| `Alumno` | **2** | 0 | 7 |
| `Acudiente` | **2** | 0 | 9 |
| `Profesor` | **93** | 3 | 145 |
| **`Usuario` (sin ningún rol)** | **87** | **4** | 139 |

Las dos de la familia son **suyas**: `PUT login/logout` (escribe `historiales`) y
`PUT perfiles/guardar-mi-email-restore` (escribe `users`). Nada ajeno.

> **El número es el 87.** Un `Usuario` **activo, no superusuario y sin un solo
> rol** alcanza **ochenta y siete endpoints que escriben**: años, periodos,
> escalas, ciudades, materias, asignaturas, ausencias, disciplina, certificados,
> votaciones, contratos, enfermería. Y no es un agujero: es `auth.personal`
> haciendo lo que dice —**bloquear a la familia y a nadie más**—.
>
> Esto **mide** lo que [med-5 §6](med-5.md) leyó: *«la familia acotada, el personal
> no»*. Y le pone el número que le faltaba a la pregunta que ya espera a Joseth
> —**«quién del personal puede qué»**—: hoy la respuesta es **casi todo**, y no
> hace falta ni un rol para ello.
>
> **Un `Profesor` alcanza 93 y un `Usuario` sin rol 87**: la diferencia es de seis
> rutas. **Tener el rol de profesor no es lo que abre la API.**

### 2.1 Cuatro `GET` que escriben

Salen sólo con los tokens del personal, y ninguna herramienta que mire la petición
las ve:

| Ruta | Qué escribe |
|---|---|
| `GET api/folios/iniciar` | `update matriculas` |
| `GET api/nota_comportamiento/detailed/{grupo_id}` | `insert dis_libro_rojo` · `insert nota_comportamiento` |
| `GET api/piars-grupos/contexto-de-grupo/{grupo_id}` | `insert piars_grupos` · `insert piars_alumnos` · `insert piars_actas_acuerdo` |
| `GET api/piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}` | `insert piars_asignaturas` |

La tercera **inserta en tres tablas** desde un `GET`. Es la misma familia que la
§2.4 de med-5 —*«aquí no se crea la configuración del año si falta»*, la decisión
que se tomó en `disciplina/mis-fichas` para **no** hacer esto— y aquí está el
contraejemplo, cuatro veces, medido.

---

## 3. El diff contra mi lectura: **tres puntos ciegos, seis filas sin veredicto**

Esto es lo que se pedía y lo que salió no es ni «coincide» ni «no coincide».

| Mi fila de med-5 | ¿La confirma el barrido? |
|---|---|
| `ExigirBoletinPropio::anotar` | **NO PUEDE** — punto ciego 1 |
| `ExigirPersonaPropia::anotar` | **NO PUEDE** — punto ciego 1 |
| `Login::anotarEntrada` · `anotarIntentoFallido` | **NO PUEDE** — punto ciego 2 |
| `Sesion::anotarReutilizacion` | **NO PUEDE** — punto ciego 2 |
| `Unidad::arreglarOrden` | **NO PUEDE** — punto ciego 3 |
| `LimpiarHtmlPiar::limpiarTabla` | fuera de alcance: es consola, no HTTP |
| las 8 restantes (modelos y servicios) | **coinciden**: aparecen bajo los tokens del personal y no bajo los de la familia |

### 3.1 Punto ciego 1 — **el barrido no imprime los 403, y el middleware escribe en el 403**

Su cabecera lo dice: *«un 403 es la respuesta correcta y no se imprime; lo que se
imprime es lo que pasó de largo»*. Y los dos middlewares escriben **precisamente en
el camino rechazado**: anotan **a quién le dijeron que no**.

**Así que la comprobación cruzada tiene su punto ciego exactamente en las dos filas
que la motivaron.** Su silencio ahí no es evidencia de nada, y **mi lectura de las
puertas sigue siendo la única prueba de esas dos.**

### 3.2 Punto ciego 2 — las escrituras del login ocurren en el montaje, no en la medición

El barrido entra **antes** de barrer, para tener un token. Así que
`Login::anotarEntrada` se ejecuta **en su `setUp`** y no como resultado de una ruta
barrida; y `anotarIntentoFallido` y `anotarReutilizacion` **no se ejercen en
absoluto**, porque el barrido no falla un login ni reutiliza un refresco.

Y hay un detalle que casi leo mal: `POST api/login` **sí sale con 200** en las
cuatro pasadas, **y sin `EJECUTA`**. Parecía desmentir que entrar escriba en
`historiales`. Son 1.358 bytes, **el mismo tamaño que `api/auth/me`**: es la rama
que devuelve el contexto **con el token que ya lleva**, no un login por
credenciales. **No escribe porque no está entrando.**

### 3.3 Punto ciego 3 — el barrido golpea con lo AJENO, y `arreglarOrden` sólo escribe sobre lo tuyo

`PUT api/notas/detailed` **no aparece en ninguna de las cuatro salidas**: cayó
entre las que «contestaron bien y sin hacer nada». Y **eso no desmiente los 12,7
`UPDATE` por carga de med-5 §3**: los dos números son ciertos y de objetos
distintos.

El barrido manda **identificadores ajenos a propósito** —es su razón de ser—, así
que `putDetailed` recibió una asignatura que no es del profesor, la lista de
unidades salió **vacía**, y `arreglarOrden` recorrió **cero** elementos. Cero
escrituras. Mis 12,7 se calcularon **sobre las unidades y subunidades que existen
de verdad** en cada par (asignatura, periodo), o sea *cuando la pantalla se carga
con lo propio*.

> **La regla que queda, y es la de la noche otra vez:** el barrido contesta *«¿qué
> alcanzo de lo que no es mío?»*. **No contesta «¿qué escribe esto cuando lo uso
> normalmente?»** — y las escrituras que sólo ocurren sobre lo propio **no salen**.
> Dos instrumentos correctos sobre dos objetos distintos.

---

## 4. Qué se lleva de aquí la fase 4, además de lo de med-5

1. **La tabla de roles existe ya para 93 y 87 rutas**, y es la tercera columna que
   pedía med-5 §4. Cuando la fase 4 instrumente un dominio, **quién puede
   dispararlo está medido** — para todo lo que el barrido ve.
2. **Y para lo que no ve, hay que decirlo en el propio plan.** Seis filas de
   dieciocho no tienen veredicto por esta vía, y **cuatro de ellas son las de
   seguridad** —los dos middlewares y las dos del login—, o sea las que más
   importan en una auditoría. Instrumentarlas y darlas por comprobadas *porque el
   barrido salió limpio* sería exactamente el error que este documento existe para
   no cometer.
3. **Los cuatro `GET` que escriben** son cuatro sitios donde la fase 4 va a tener
   que decidir lo mismo que `arreglarOrden`: si una lectura que crea filas se
   audita como acción de una persona, como `porElSistema()`, o no se audita.

---

## 5. Lo que este lote NO hace

- **No arregla el barrido.** Los tres puntos ciegos son de diseño y dos de ellos
  están escritos en su propia cabecera; cerrarlos es cambiar qué mide. Y no se
  toca **mientras sea el instrumento**: cambiar el instrumento y medir con él a la
  vez es mover el instrumento y la pieza al mismo tiempo.
- **No toca ningún middleware, ni `Unidad::arreglarOrden`, ni los cuatro `GET`.**
- **No convierte el 87 en una propuesta.** Es el número que le faltaba a una
  pregunta que ya espera decisión, y ponerle un arreglo encima sería contestarla yo.
