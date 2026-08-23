# Qué puede hacer un cron por este backend

> Escrito el 24 ago 2026, a petición de Joseth, que confirmó con la sesión de
> `myvc_flutter` que **los cron jobs funcionan en su hosting compartido de
> cPanel**. La pregunta era cómo sacarles el jugo con el backend que hay hoy, no
> con uno hipotético.

## El cron ya está puesto. Lo que falta es qué meterle

No hay que montar nada: cada colegio tiene **un solo cron**, `schedule:run` cada
minuto, y lo que corre se decide en `app/Console/Kernel.php`, que viaja con el
`app/`. La línea y sus dos trampas están en
[DESPLIEGUE-REFERENCIA.md](../DESPLIEGUE-REFERENCIA.md). Hoy ahí dentro sólo hay
`sesion:limpiar`, semanal.

O sea que **añadir una tarea es escribir una línea en un fichero**, no entrar a
dieciséis paneles de cPanel. Esa decisión ya está tomada y es la que hace barato
todo lo de abajo.

---

## El jugo no está donde parece

La respuesta intuitiva a «¿para qué un cron?» es *«para mover al fondo lo que
tarda»* — importar el Excel, generar boletines. Eso está analizado en
[02 §5](02-plan-rendimiento.md) y **sigue bloqueado por algo que no es el cron**:
encolar cambia el contrato con **cuatro clientes**, uno de ellos una app de
Flutter que es **una sola para los dieciséis colegios**. No se puede escalonar, y
el problema que resolvería —los timeouts en los imports— **todavía no lo ha
medido nadie**.

El jugo está en otra cosa, y se ve leyendo los incidentes de este repo en fila:

| Lo que pasó | Cuánto tardó en saberse |
|---|---|
| **`instival` no recibe ni código ni migraciones** — no hay repositorio ni aplicación en su carpeta | semanas. Se supo por accidente, y **el cierre del 19 ago que dio los 16 por desplegados no lo comprobó** |
| **«Already up to date» no significa desplegado** — cada colegio apunta a su propio remoto | los dieciséis lo dijeron minutos después de un `push`, y sólo el hash lo distinguía |
| **11.988 definitivas que deberían existir y no existen** | medido en **un** colegio, y la [fase 2](10-definitivas.md) lleva días esperando el mismo número de los otros quince |
| **El boletín de una familia devuelve 500** | lo encontró un barrido, no un reporte |

**Los cuatro son el mismo problema: dieciséis cajas opacas y nadie dentro.** Un
arreglo fusionado no está desplegado, `app/` es copia por colegio, y para saber
qué está sirviendo o cómo está de sana la base de uno **hay que viajar hasta él**.

**El cron es lo único de esta arquitectura que corre dentro de cada colegio sin
que nadie viaje.** Ese es el jugo: no es un ejecutor de tareas pesadas, es **el
único informante que se puede tener en dieciséis sitios a la vez.**

---

## La pieza que lo hace posible, y que hoy está catalogada como trampa

La referencia avisa de que **cPanel manda un correo con la salida de cada
ejecución**, y por eso el cron de cada minuto lleva `>/dev/null 2>&1`: sin él son
1.440 correos al día por colegio, 23.000 con dieciséis.

Dado la vuelta, eso es **exactamente el canal que hace falta**:

> **cPanel sabe mandar por correo la salida de un cron sin que Laravel tenga el
> correo configurado.** No pasa por `MAIL_MAILER`, ni por `sendmail`, ni por el
> `.env` — lo manda el propio panel.

Y eso importa porque **hoy el correo de Laravel no está puesto**: la
[referencia](../DESPLIEGUE-REFERENCIA.md) lo lleva como PENDIENTE desde el PR #3,
y en el `.env` de desarrollo sigue apuntando a `mailhog`. Cualquier plan que diga
«que el cron te avise por correo» **no funcionaría hoy** si el correo lo manda la
aplicación.

Así que la receta es un **segundo cron**, diario, **sin** `>/dev/null`:

```cron
# El de cada minuto, como está hoy — uno por colegio y callado a propósito
* * * * * /usr/local/bin/php /home/micolev1/COLEGIO.micolevirtual.com/artisan schedule:run >/dev/null 2>&1

# Y el parte diario: UNA línea para los dieciséis, y SÍ habla
30 5 * * * for d in /home/micolev1/*.micolevirtual.com/8myvc; do echo "=== $d"; /usr/local/bin/php "$d/artisan" colegio:parte; done
```

**El bucle, y no una línea por colegio.** Es la forma que propuso `8myvc-d0` el 24
ago 2026 para el cron de las notificaciones, y para el parte es mejor que la que
tenía escrita aquí, por dos razones que no se ven de entrada:

1. **Un correo con dieciséis partes se lee; dieciséis correos se archivan sin
   abrir.** Y lo que hace útil a este parte es comparar los colegios entre sí — un
   número raro se ve **al lado** de los otros quince, no en un correo suelto.
2. **Un colegio que falta se nota.** Con dieciséis correos, el que no llega es un
   correo que no llega, y eso no se ve. En un solo listado, `instival` aparece como
   un hueco con su `=== ruta` y nada debajo. **Es exactamente el fallo que tardó
   semanas en descubrirse**, y así se descubre solo el primer día.

> **La otra cuenta de cPanel (`lalvirtual.edu.co`) necesita su propia línea**: es
> otro login y el bucle no la alcanza. Es la misma advertencia que lleva el
> despliegue, y por el mismo motivo.
>
> Y **el de cada minuto se queda como está: uno por colegio.** No se convierte en
> bucle. `schedule:run` corre cada minuto y tiene que devolver el control rápido;
> un bucle de dieciséis arranques de Laravel cada minuto es otra cosa
> completamente, y en un hosting compartido se nota.

> `/usr/local/bin/php` tiene que ser **8.4**; en la cuenta `micolev1` lo estaba el
> 20 ago 2026. La otra cuenta de cPanel hay que mirarla aparte, y un cron con el
> PHP viejo **falla en silencio**.

---

## Lo que ese parte diario debería decir, por orden de valor

### 1. Los dieciséis números de la fase 0 — y con esto se desbloquea la fase 2

Es lo de más valor de toda esta lista, porque **hay una decisión parada
esperándolo**. [`tools/salud-de-las-definitivas.php`](../../tools/salud-de-las-definitivas.php)
ya existe, sólo hace SELECT y contesta las siete preguntas. Lo que falta es que
alguien lo corra en dieciséis servidores.

Un cron lo corre **cada colegio sobre sí mismo**, y el resultado llega solo. La
diferencia con el `for` de una línea que hay escrito en el
[10](10-definitivas.md) es que el `for` hay que acordarse de correrlo y **el cron
lo repite sin que nadie se acuerde** — que es justo lo que hace falta cuando la
[fase 5](10-definitivas.md) exige *«cero discrepancias durante un periodo
completo»*. Eso no es una medición: es una vigilancia.

**Precaución medida:** en la copia de desarrollo la herramienta tarda **61
segundos**, y ahí es donde muerde el hosting compartido. Va de madrugada, y si en
algún colegio se pasa de tiempo, la salida es acotarla con `--year` al año en
curso, no quitarla.

### 2. Qué está sirviendo este colegio — `instival` nunca más

Cuatro líneas que ningún viaje puede dar mejor:

- el **hash** del commit desplegado (no «Already up to date», que no distingue);
- `migrate:status` — cuántas migraciones en `Ran`;
- la versión de PHP con la que corre **de verdad**;
- y si `config:cache` y `route:cache` están puestos.

Con esto, un colegio que se quede atrás **lo dice él mismo al día siguiente**, y
uno que no mande parte es un colegio que no tiene cron o no tiene aplicación —
que es exactamente el caso de `instival`, y se habría visto en 24 horas en vez de
en semanas.

### 3. Las consultas lentas, por ventanas

`CONSULTAS_LENTAS_MS` está apagado de serie y con motivo: en cPanel no hay acceso
al `slow_query_log` de MySQL, así que el registro lo hace la aplicación y cuesta.

Un cron puede **encenderlo una hora a la semana** y resumir lo que salga. Es la
forma de tener la medición sin pagarla siempre — y contesta la pregunta que el
[02](02-plan-rendimiento.md) deja abierta: *«los imports dan timeout» es una
impresión, no una medición*. La tabla `importaciones` ya guarda `inicio`, `fin` y
`filas` de cada una, así que el parte puede dar el número sin instrumentar nada.

### 4. Lo que se pudre en silencio

Cosas que hoy nadie mira y que un parte convierte en una línea:

- filas nuevas con `fecha_hora` nulo — **6.681 (13%)** cuando se midió, y el
  arreglo del front va en camino: el parte dice si de verdad dejó de crecer;
- `created_at` imposibles (las 732 de la §5 de la fase 0);
- definitivas duplicadas, que después de la fase 2 **deben ser cero para siempre**;
- imports que empezaron y no terminaron.

**Lo que hace útil a esta lista no es el número: es su derivada.** Un 13% que baja
dice que el arreglo llegó; el mismo 13% la semana siguiente dice que no.

---

## Lo que NO hay que meter en un cron

**Recalcular definitivas en masa de madrugada.** Es la idea que más natural suena
y es la peor:

- **no hace falta**: medido el 24 ago con
  [`tools/coste-del-recalculo.php`](../../tools/coste-del-recalculo.php), una nota
  tecleada cuesta **~4 ms** de recálculo, contra los ~40–80 ms que cuesta sólo
  resolver quién pregunta. Los siete disparadores de la fase 3 lo mantienen al día
  sin ayuda;
- **y es justo lo que se acaba de quitar**: la [fase 3](10-definitivas.md) redujo
  a uno los seis escritores masivos de `notas_finales`, y los tres síntomas —
  definitivas que desaparecen, duplicadas, y notas que no aparecen— salían de que
  varios procesos reescribían la tabla entera con criterios distintos. **Un cron
  que la reescriba de madrugada es el séptimo escritor**, y encima sin nadie
  mirando cuando falle.

La excepción es **el relleno de la fase 2** —las 11.988 filas que faltan—, y esa
es una migración que corre **una vez**, no una tarea repetida.

**Y la regla general: un cron que escribe algo que nadie lee es peor que no
tenerlo.** Falla en silencio —`>/dev/null` se encarga— y deja la sensación de que
hay vigilancia donde no la hay. Antes de programar cualquier cosa aquí: **¿quién
lee la salida, y qué hace si dice algo raro?**

---

## Lo que costaría

| | |
|---|---|
| **El comando `colegio:parte`** | un fichero en `app/Console/Commands/`, que reúne lo que las herramientas de `tools/` ya calculan. No hay lógica nueva que inventar |
| **La línea de cron** | una por colegio, una sola vez, en el mismo viaje al panel donde ya está la otra |
| **Riesgo** | bajo y acotado: **sólo lee**. La regla de la fase 0 vale igual — la corrección de datos va en una migración, nunca en algo que corre solo cada noche |
| **Lo que devuelve** | los dieciséis números que la fase 2 está esperando, y que hoy dependen de que alguien viaje a dieciséis servidores |

**El orden que propongo:** primero el punto 2 —el estado del despliegue, que son
cuatro líneas y contesta «¿llegó mi tanda?»—, y con el mismo comando ya montado,
el punto 1 detrás. Los puntos 3 y 4 cuando el parte ya se esté leyendo a diario;
antes no, porque un parte largo que nadie abre es lo mismo que no tenerlo.
