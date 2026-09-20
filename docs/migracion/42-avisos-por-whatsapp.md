# Avisos por WhatsApp: qué cuesta, qué se manda y cada cuánto

> **Encargo de Joseth, 19 sep 2026**, llegado por la sesión `8myvc-92`, literal:
> *«que me analice los mensajes de whatsapp, la implementación, costos, cuánto se le
> cobraría al colegio, el límite de mensajes para no enviar un mensaje por cada cambio
> que hace un docente en una nota, sino hacer algo para que se envíen en determinado
> momento, que no gaste muchos mensajes, y que sean útiles»*.
>
> Sale del recorte de alcance de pagos en línea del mismo día: MYVC no cobra pensiones,
> sólo el formulario de prematrícula, y el flujo que Joseth describió —la familia sube la
> colilla, avisa al tesorero, el tesorero aprueba, y al aprobar *«tal vez un sistema
> económico de mensajería por WhatsApp»*— es el primer uso real. Ese hilo vive en
> `40-pagos-en-linea.md` y `41-el-formulario-de-inscripcion.md`, que **todavía no están
> en `main`** el día que se escribe esto; por eso este documento toma el 42 y no depende
> de ellos.
>
> **DECIDIDO EL 19 sep 2026: NO SE USA WHATSAPP, EN NINGÚN CASO.** Joseth lo cerró al
> estrechar el alcance hasta el único hueco real —el aspirante, que no tiene la app— y ver
> que ahí **basta con exigirle un correo válido en el formulario**. El documento se queda
> entero y sin recortar: las cifras de volumen valen para **cualquier** canal que se plantee
> mañana, y lo que aquí se midió es lo que evita volver a discutirlo desde cero.
>
> **El mapa de canales que deja esa decisión, y no tiene huecos**: la familia matriculada se
> entera **por la app**, con el push que ya funciona, agrupado y gratis; el aspirante se
> entera **por correo**, que se le pide en el formulario. WhatsApp no cubría ningún tercer
> caso — cubría mejor el segundo, y no lo bastante mejor.
>
> **Y la decisión tiene una dependencia que hay que comprobar, porque hoy NO se cumple**:
> ver §13, que es el único apartado que sobrevive como trabajo pendiente.

---

## 0. Si sólo se leen diez líneas

1. **Agrupar no es para ahorrar dinero.** En Colombia un mensaje *utility* cuesta **USD
   0,0008 = COP 2,55**, y el peor escenario medido —un mensaje por cada nota que teclea un
   docente, un colegio entero, un año lectivo real— son **117.381 mensajes y COP 398.142
   al año**. Eso no arruina a nadie.
2. **Agrupar es para que el canal no se muera.** Medido en la base: **una familia llegaría
   a recibir 100 mensajes en un solo día**, y el colegio entero 6.184. La familia bloquea
   el número, los bloqueos bajan la calificación de calidad de Meta, la calidad baja el
   cupo diario, y lo que deja de llegar es **lo que sí importaba**: el pago aprobado y el
   boletín.
3. **Lo que multiplica la factura no es la frecuencia, son dos decisiones de fuera**: que
   Meta recategorice la plantilla de *utility* a *marketing* (**×17,5**) y contratar un
   BSP con recargo por mensaje (**×7,25**: el recargo típico de Twilio, USD 0,005, es
   **6,25 veces la tarifa colombiana de Meta**). Frente a eso, elegir entre avisar cada 15
   minutos o una vez al día mueve un ×1,5.
4. **La recomendación**: un **resumen por alumno y día** a hora fija (política D), más un
   puñado de eventos que no esperan —ausencia, pago aprobado, citación— con **tope duro
   por familia y día**. Y el despliegue empieza por **prematrícula**, que son tres
   mensajes por aspirante y COP 12.261 al año para los dieciséis.
5. **Y el alcance real es 80 %, no 100 %**: de los 377 matriculados de 2025, **75 no
   tienen ningún móvil colombiano válido** en la ficha. Limpiar eso no es un proyecto de
   WhatsApp.

---

## 1. Lo medido: cuánto escribe de verdad un colegio

La copia de desarrollo (`simonbolivar` en el docker) tiene **1.166.464 filas en `notas`**,
pero sólo unas 124.000 llevan `created_at` real: el resto entró en la importación del
legacy sin fecha. **El año utilizable es 2018**, que es el último con el colegio entero
funcionando y con marcas de tiempo: **419 matriculados, 421 alumnos con nota, 109 días con
actividad**.

```sql
-- Volumen bruto y días activos
SELECT COUNT(*) dias, MIN(n) minimo, ROUND(AVG(n)) media, MAX(n) maximo
FROM (SELECT DATE(created_at) d, COUNT(*) n FROM notas WHERE YEAR(created_at)=2018 GROUP BY d) x;
-- dias 109 · minimo 1 · media 1.077 · maximo 6.184
```

| Lo medido (2018, un colegio) | |
|---|---|
| Notas escritas en el año | **117.381** |
| Media por día activo | **1.077** |
| **Peor día** | **6.184 notas, 227 alumnos tocados, 3 docentes** |
| Notas de **un solo docente** en **un solo día** | **4.023** |
| Notas de **un solo alumno** en **un solo día** | **100** |
| Media de notas por alumno y día | 9,6 |
| Familias tocadas en un día activo normal | 112 de 419 (27 %) |
| Notas por alumno y año | 279 |

> **El 6.184 de un día lo hicieron tres personas.** No es un colegio desbordado: es un
> docente cerrando periodo, que pasa una columna entera de una sentada. Cualquier diseño
> que reaccione a la escritura individual está diseñando contra eso.

---

## 2. El precio, buscado y no citado de memoria

Meta cobra **por mensaje entregado** desde el 1 jul 2025 (antes era por conversación de 24
h), y el precio depende de **la categoría** y **del país del destinatario**. Colombia está
entre los mercados más baratos del mundo.

| Categoría | Colombia, USD/mensaje | COP (TRM 3.192,92 del 19 sep 2026) |
|---|---|---|
| **Utility** (transaccional, no promocional) | **0,0008** | **2,55** |
| **Authentication** | 0,0008 | 2,55 |
| **Marketing** | ≈ 0,014 | ≈ 44,70 |
| **Service** (respuesta dentro de la ventana de 24 h) | 0 hasta el 1 oct 2026 | — |

Contexto para calibrar: un **SMS masivo en Colombia cuesta COP 6–7**, o sea que el
WhatsApp *utility* es **2,3–2,7 veces más barato que un SMS** y además lleva enlace,
formato y acuse de entrega.

**Tres fechas que importan y una que no:**

- **1 oct 2026** (en once días): Meta empieza a cobrar los *service messages* y las
  plantillas *utility* enviadas **dentro** de la ventana de 24 h. **A nosotros casi no nos
  toca**: nuestros avisos los inicia el colegio, van **fuera** de cualquier ventana y por
  tanto siempre fueron de pago. Lo que cambia es el precio de **responder** a una familia
  que conteste.
- Los BSP (360dialog, respond.io, SendPulse, Wati) coinciden en que habrá **1.000 service
  messages gratis al mes por número**, que no acumulan. **Eso no lo vi escrito en la página
  de Meta**, así que se anota como dato de terceros y se comprueba antes de contar con él.
- **30 sep 2026**: quien tenga cuenta y no tenga medio de pago registrado deja de entregar
  service messages. No nos afecta porque **no hay cuenta**.
- La ventana gratis de 72 h es sólo para anuncios *Click to WhatsApp*. No aplica.

> **La categoría es el riesgo grande, y no lo decidimos nosotros.** Meta define *utility*
> como no promocional **y** *«specific to or requested by the user»* o esencial, y **tiene
> un proceso recurrente que recategoriza plantillas ya aprobadas** —desde el 16 abr 2025
> sin aviso previo para reincidentes—. «Laura tiene novedades» es específico del usuario y
> no vende nada, así que *utility* es defendible; pero **si un día lo recategorizan, la
> misma plantilla pasa de COP 2,55 a COP 44,70 sin que cambie una línea de código**. Ése es
> el número que decide si MYVC puede ofrecer precio fijo (§9).

---

## 3. Las seis políticas, con lo que cuesta cada una

Medido sobre el mismo año 2018 y el mismo colegio. La columna «×1,33» aplica el reparto
real por acudiente: hay **976 pares (alumno, móvil válido) para 735 alumnos**, o sea 1,33
números por alumno si se avisa a todos los acudientes y no sólo a uno.

| Política | Msgs/año | ×1,33 acud. | USD *utility* | COP/año | COP por alumno | Si fuera *marketing* | Con recargo de BSP |
|---|---:|---:|---:|---:|---:|---:|---:|
| **A** · 1 mensaje por nota | 117.381 | 155.869 | 124,70 | 398.142 | 950 | **2.182** | 904 |
| **B** · cadencia push de hoy (15 min, alumno+asignatura) | 18.694 | 24.824 | 19,86 | 63.408 | 151 | 348 | 144 |
| **C** · alumno+asignatura, 1 vez al día | 17.099 | 22.706 | 18,16 | 57.998 | 138 | 318 | 132 |
| **D** · **resumen por alumno, 1 al día** | 12.208 | 16.211 | **12,97** | **41.408** | **99** | 227 | 94 |
| **E** · resumen por alumno, 1 a la semana | 6.232 | 8.275 | 6,62 | 21.138 | 50 | 116 | 48 |
| **F** · sólo boletín de periodo (5 al año) | 2.095 | 2.782 | 2,23 | 7.106 | 17 | 39 | 16 |

```sql
-- De dónde salen las cinco primeras filas (la F es 5 × matriculados)
SELECT 'A' , COUNT(*) FROM notas WHERE YEAR(created_at)=2018
UNION ALL SELECT 'B', COUNT(*) FROM (SELECT n.alumno_id, asig.id, FLOOR(UNIX_TIMESTAMP(n.created_at)/900) q
  FROM notas n JOIN subunidades s ON s.id=n.subunidad_id JOIN unidades u ON u.id=s.unidad_id
  JOIN asignaturas asig ON asig.id=u.asignatura_id WHERE YEAR(n.created_at)=2018 GROUP BY 1,2,3) b
UNION ALL SELECT 'C', COUNT(*) FROM (SELECT n.alumno_id, asig.id, DATE(n.created_at) d
  FROM notas n JOIN subunidades s ON s.id=n.subunidad_id JOIN unidades u ON u.id=s.unidad_id
  JOIN asignaturas asig ON asig.id=u.asignatura_id WHERE YEAR(n.created_at)=2018 GROUP BY 1,2,3) c
UNION ALL SELECT 'D', COUNT(*) FROM (SELECT alumno_id, DATE(created_at) d FROM notas WHERE YEAR(created_at)=2018 GROUP BY 1,2) d
UNION ALL SELECT 'E', COUNT(*) FROM (SELECT alumno_id, YEARWEEK(created_at) w FROM notas WHERE YEAR(created_at)=2018 GROUP BY 1,2) e;
```

**Tres cosas que esta tabla dice y que no se ven pensándolo:**

1. **El eje caro no es el tiempo, es la asignatura.** Avisar cada 15 minutos (B, 18.694)
   cuesta casi lo mismo que avisar una vez al día separando por asignatura (C, 17.099):
   sólo un 9 % más, porque el docente pasa la columna de una sentada y cae toda en la misma
   ventana. Lo que de verdad ahorra es **dejar de mandar un mensaje por asignatura**: de C a
   D se cae un 29 % sin perder un solo hecho, sólo juntando las asignaturas del día en una
   frase.
2. **A es 9,6 veces D y no es 100 veces D.** La intuición dice que «un mensaje por cambio»
   es catastróficamente caro; medido, es caro pero no absurdo. Lo que lo descarta no es la
   columna de pesos (§4).
3. **Toda la columna de COP cabe en el ruido de un presupuesto escolar** —de 7.106 a
   398.142 pesos al año por colegio— **salvo si la categoría cambia**. Con *marketing*, la
   política A son COP 6,97 millones al año por colegio, y ahí sí se acabó.

**Para los dieciséis colegios, si todos fueran de este tamaño** (no medido: sólo tengo la
copia de uno), la política D son **259.374 mensajes al año = USD 207,50 = COP 662.529**. Si
Meta recategoriza: **COP 11,6 millones**. Con BSP: **COP 4,8 millones**.

---

## 4. La razón de verdad para agrupar, y no es la factura

Meta puntúa cada número con una **calificación de calidad** que se mueve sobre todo con
**bloqueos y reportes de los destinatarios**. Cuando baja, baja el cupo diario; si sigue
bajando, el número se bloquea entero.

Y el dato que lo hace concreto está medido arriba: **un alumno llegó a tener 100 notas en un
solo día**. Con la política A, su familia recibe 100 mensajes de WhatsApp en una tarde.

| Política | Peor día de **una sola familia** |
|---|---|
| A · por cambio | **100 mensajes** |
| B · push cada 15 min | 10 |
| C · alumno+asignatura diario | 10 |
| **D · resumen diario** | **1** |

```sql
SELECT MAX(n) FROM (SELECT alumno_id, DATE(created_at) d, COUNT(*) n
  FROM notas WHERE YEAR(created_at)=2018 GROUP BY 1,2) x;   -- 100
```

> **Lo que se pierde cuando una familia bloquea el número no son los avisos de notas: son
> los otros.** El pago aprobado, el boletín publicado, la citación. El canal caro de
> recuperar es la atención, no el saldo — y un aviso que llega y no se puede accionar
> entrena a la familia a ignorar el resto. Por eso el tope por familia es un **invariante
> del mecanismo** y no una recomendación: no «procura no mandar muchos», sino **la pasarela
> no entrega más de N a la misma familia en un día, y lo que sobra se cae con un registro**.

---

## 5. Los límites que no pone MYVC, sino Meta

Antes de diseñar nuestro tope conviene saber el suyo, porque **no mide lo que uno supone**:

- **Los escalones son 250 → 2.000 → 10.000 → 100.000 → ilimitado.**
- **Cuentan destinatarios únicos en una ventana móvil de 24 h, no mensajes.** Literal de
  Meta: *«the maximum number of unique WhatsApp user phone numbers your business can deliver
  messages to, outside of a customer service window, within a moving 24-hour period»*.
- **Son de la cartera de negocio, no del número**: desde oct 2025, todos los números de una
  misma cartera comparten el cupo.
- Se sube con verificación del negocio (250 → 2.000) y después solo, si la calidad se
  mantiene y se usa **al menos la mitad del cupo en los últimos 7 días**.

**Dos consecuencias que cambian el diseño:**

1. **Agrupar no ahorra cupo, ahorra dinero y paciencia.** Los 6.184 mensajes del peor día
   irían a 227 destinatarios únicos: para Meta son 227, igual que si mandáramos uno. O sea
   que el argumento del cupo **no sirve** para justificar la agrupación, y no hay que usarlo.
2. **Lo que sí topa el cupo es el abanico**: el día que se publica el boletín se escribe a
   **todas** las familias a la vez. Un colegio de 419 familias **no cabe en el escalón de
   entrada de 250**. Hace falta la verificación del negocio —el colegio tiene NIT, así que
   puede— para llegar a 2.000 antes del primer boletín. Con la cartera compartida entre los
   dieciséis, el abanico de un día son ~6.700 familias y hace falta el escalón de 10.000.

---

## 6. Qué se manda, y qué hace quien lo recibe

«Que sean útiles» se comprueba con una pregunta por mensaje: **¿qué hace la familia al
leerlo?** Si la respuesta es «nada» o «abrir la app por si acaso», el mensaje gasta dinero y
gasta atención.

| Mensaje | Qué hace quien lo recibe | Volumen/año/colegio | COP/año |
|---|---|---:|---:|
| **Pago de prematrícula aprobado / devuelto** | Nada, o vuelve a subir la colilla. **Cierra una espera.** | ~200 | 510 |
| **Ausencia de hoy** | Llama al colegio o justifica. **Es el único que caduca en horas.** | 2.109 | 5.377 |
| **Boletín de periodo publicado** | Abre y lo lee. Es lo que la familia sí quiere. | 2.095 | 5.342 |
| **Citación / situación disciplinaria** | Asiste. | pocas cientos | ~1.000 |
| **Resumen diario de novedades académicas** | Abre la app si le interesa. **Opcional, se puede apagar.** | 12.208 | 31.130 |
| ~~Nota individual~~ | **Nada.** Ni se manda el número (regla ya tomada para push). | — | — |

```sql
-- Ausencias: un aviso por alumno y día
SELECT COUNT(*) FROM (SELECT alumno_id, DATE(created_at) d FROM ausencias WHERE YEAR(created_at)=2018 GROUP BY 1,2) x;  -- 2.109
```

> **Los cuatro primeros valen 12.229 pesos al año por colegio y son los que la familia
> agradecería.** El quinto cuesta más que los cuatro juntos y es el único discutible. Ése es
> el orden en que hay que encenderlos, y **no es el orden en que se pidió**.

**Y lo que no lleva dentro ningún mensaje: el dato.** «Laura tiene novedades en 3
asignaturas», nunca «Laura sacó 45». Esa regla ya está tomada y escrita para push
(`TemasDeNotificacion`, `EnviarNotificaciones`), por dos motivos que valen igual aquí: un
aviso se lee en la pantalla bloqueada con gente al lado, y **el aviso llega aunque el colegio
tenga las notas bloqueadas** (`alumnos_can_see_notas = 0`), así que enseñar lo que la app
niega sería absurdo. En WhatsApp hay un tercero: el mensaje **se queda en el teléfono para
siempre** y se reenvía con dos toques.

---

## 7. Implementación: casi todo está escrito, y la parte que falta no es la que parece

**Ya existe el subsistema entero de avisos agrupados.** `notificaciones:enviar` corre **cada
quince minutos** desde el cron único de cada colegio, lee cuatro fuentes (notas, asistencia,
disciplina, muro) desde `bitacoras`, **agrupa por alumno y asignatura**, publica por
`Publicador` y adelanta una marca. Tiene tope por fuente (300), `--seco`, `withoutOverlapping`
y la regla de que **la primera pasada no manda nada**. El problema que pregunta Joseth ya se
resolvió una vez en este repo, y la respuesta está en el docblock de ese comando.

**Pero WhatsApp no es «push con otro transporte», y conviene verlo antes de escribir nada:**

| | Push (FCM) | WhatsApp |
|---|---|---|
| A quién se reparte | a un **tema** que el teléfono se deriva | a un **número de teléfono** |
| Destinatarios que guardamos | **ninguno** | el móvil de cada acudiente |
| Consentimiento | instalar la app y entrar | **opt-in explícito exigido por Meta** |
| Coste por destinatario | 0 | COP 2,55 (o 44,70) |
| Texto | libre | **plantilla aprobada por Meta**, con variables |
| Si molesta | el usuario lo apaga | **bloquea, y se lleva el número por delante** |

O sea que WhatsApp invierte exactamente las dos propiedades que hicieron barato el push. No
se reutiliza `Publicador` —su firma es `publicar(tema, …)` y aquí no hay temas—; se reutiliza
**la forma del comando**: marca, tope, agrupación, `--seco`, y el cron que ya está puesto.

**Lo que hay que escribir, por orden de tamaño:**

1. **La pasarela central, fuera de los dieciséis.** Es la decisión copiada de la IA
   (`myvc-ia-prototipo/docs/importacion-asistida.md` §4.1, D1), y aquí el argumento es más
   fuerte que allí: **una llave de WhatsApp es una llave de gasto**. Dieciséis `.env` son
   dieciséis filtraciones, y quien se lleve una **gasta dinero de verdad** hasta que alguien
   mire la factura. Lo que vive en el `.env` del colegio es una llave **de cupo** contra la
   pasarela: se revoca, se topa y no compra nada.
2. **El tope de gasto en la pasarela, no en el colegio.** Máximo por colegio y día, máximo
   por familia y día, y horario (nada antes de las 7:00 ni después de las 20:00). Sin eso, un
   bucle mal escrito en un colegio es una factura, y el sitio donde se descubre es el extracto.
3. **`EnvioWhatsapp` en el colegio**: un POST con Guzzle a la pasarela. **Cero dependencias
   nuevas de composer** —`vendor/` es compartido por symlink entre los diecisiete, así que
   cada dependencia se paga diecisiete veces—, y ya está demostrado que se puede hablar con
   una API de un tercero sin SDK: `EnvioFcm` firma un JWT con `openssl_sign` por esa misma
   razón. La API de WhatsApp es más fácil todavía: un POST con *bearer token*.
4. **Una fuente nueva con ventana diaria** en `notificaciones:enviar`, con su propia marca,
   que a una hora fija junta lo del día en un mensaje por alumno.
5. **Opt-in y opt-out**: una columna por acudiente, la pantalla donde se marca, y atender el
   «responde BAJA» (que entra por webhook y es un *service message*, hoy gratis).
6. **Las plantillas**, aprobadas una a una por Meta, en categoría *utility*.

**Lo que NO hay que escribir**: un cron nuevo (está), una cola (`QUEUE_CONNECTION=sync`, y en
hosting compartido no hay proceso vivo; por eso esto es un comando y no un job), ni envío
dentro de la petición del docente (treinta notas serían treinta llamadas a un tercero en el
camino crítico de quien está calificando).

---

## 8. Lo que cuesta de verdad, que no son los mensajes

| Concepto | Coste |
|---|---|
| Mensajes de un colegio, política D + eventos | **COP ~53.000/año** |
| Alta: cartera de Meta, verificación del negocio, número, nombre para mostrar | tiempo, por colegio |
| Plantillas aprobadas y mantenidas | tiempo, una vez |
| La pasarela central (servidor, despliegue, vigilancia del gasto) | fijo, se reparte entre 17 |
| **Limpiar los teléfonos** | **el 20 % de las familias no tiene móvil válido** |
| Atender lo que la familia responde | **el coste que nadie presupuesta** |

> **El último renglón es el que hunde estos proyectos.** Un canal de WhatsApp es una puerta
> que se abre en las dos direcciones: la familia contesta *«¿por qué perdió Matemáticas?»* y
> alguien del colegio tiene que estar ahí. Si la respuesta va a ser el silencio, conviene que
> las plantillas lo digan («este número no recibe respuestas, escriba a …»), que es gratis, o
> asumir que el canal necesita una persona, que no lo es.

---

## 9. Cuánto cobrarle al colegio

**El suelo está medido: COP 99 por alumno y año** con la política D, o **COP ~127 por alumno
y año** con los eventos incluidos. Cualquier precio que se ponga es, casi entero, servicio y
riesgo — no reventa de mensajes. Tres formas, y lo que expone cada una:

**(a) El colegio pone su propia cartera de Meta y su medio de pago; MYVC cobra el módulo.**
MYVC no revende, no adelanta dinero, no asume el riesgo de cambio ni el de la recategorización
—si Meta multiplica por 17,5, el recibo le llega al colegio, no a MYVC—, y el colegio que deja
de pagar apaga su propio canal sin tocar a los demás. El precio: cada colegio arranca en el
escalón de 250 y hay que verificarlo uno a uno. **Es la que recomiendo.**

**(b) MYVC centraliza y refactura.** Una sola cartera, un solo escalón que se sube una vez y
aprovecha a los diecisiete, y el colegio no ve a Meta. A cambio MYVC se vuelve revendedor:
paga en dólares, cobra en pesos, come la diferencia de cambio, asume el ×17,5 si la categoría
cambia y **paga de su bolsillo el bucle mal escrito**. Si se elige, el tope de gasto de §7.2
deja de ser prudencia y pasa a ser lo único que separa a MYVC de una factura abierta.

**(c) Incluido en la licencia, sin cobro aparte.** Con las cifras medidas es **viable**
—COP 41.408 al año por colegio es menos de lo que cuesta facturarlo— y tiene la ventaja de
que no hay que medir nada por colegio. Pero convierte el ×17,5 en un problema de MYVC, y
regala el argumento comercial de una función que la familia sí valora.

> **Si se quiere una cifra para negociar**: entre **COP 1.000 y 2.000 por alumno y año**
> —de 8 a 16 veces el coste— cubre el alta, la verificación, el soporte y el riesgo de
> categoría con margen, y para un colegio de 400 alumnos son COP 400.000–800.000 al año, que
> es menos que un mes de una persona contestando el teléfono. **No es una recomendación de
> precio: es el suelo y el múltiplo, que es lo que aquí se puede medir.** El precio lo pone
> quien conoce lo que ya paga cada colegio.

---

## 10. A cuántos se llega de verdad

```sql
-- Matriculados de 2025 con algún móvil colombiano válido (acudiente o propio)
SELECT COUNT(*) matriculados, SUM(acud>0 OR prop>0) alcanzables, SUM(acud=0 AND prop=0) inalcanzables
FROM (SELECT m.alumno_id,
        SUM(REGEXP_REPLACE(COALESCE(a.celular,''),'[^0-9]','') REGEXP '^3[0-9]{9}$') acud,
        MAX(REGEXP_REPLACE(COALESCE(al.celular,''),'[^0-9]','') REGEXP '^3[0-9]{9}$') prop
      FROM years y JOIN grupos g ON g.year_id=y.id AND g.deleted_at IS NULL
      JOIN matriculas m ON m.grupo_id=g.id AND m.deleted_at IS NULL AND m.estado='MATR'
      JOIN alumnos al ON al.id=m.alumno_id
      LEFT JOIN parentescos p ON p.alumno_id=m.alumno_id
      LEFT JOIN acudientes a ON a.id=p.acudiente_id AND a.deleted_at IS NULL
      WHERE y.year=2025 GROUP BY 1) x;
-- 377 matriculados · 302 alcanzables · 75 inalcanzables
```

| | |
|---|---|
| Matriculados 2025 | 377 |
| Con móvil válido de un acudiente | 251 (67 %) |
| Con móvil propio del alumno | 276 (73 %) |
| **Alcanzables por alguno de los dos** | **302 (80 %)** |
| **Sin ningún móvil válido** | **75 (20 %)** |
| Acudientes vivos | 1.085 |
| …con celular | 1.020 (94 %) |
| …con móvil colombiano válido | 998 (92 %) |
| **…con correo electrónico** | **100 (9,2 %)** |

> **El renglón del correo es el hallazgo que no venía a buscar y que cambia otro documento.**
> El flujo de prematrícula termina en *«al aprobar sale un correo»*, y en este colegio **el
> correo alcanza al 9,2 % de los acudientes**. No es que WhatsApp sea mejor que el correo:
> es que **el correo, hoy, casi no existe** como canal con las familias. Va avisado a la
> sesión que lleva `41-el-formulario-de-inscripcion.md`.
>
> > ### CORREGIDO EL 20 SEP 2026: NO ES EL 9,2 %, ES EL 0 %
> >
> > **Levantado por `myvc-front-2e` y reproducido aquí antes de escribirlo.** La medición de
> > arriba es cierta y cuenta `acudientes.email` —la **FICHA**—, pero **la recuperación de
> > contraseña no busca por ahí**: `LoginController.php:240-266` busca por `users.email`, la
> > **CUENTA**, en cuatro consultas seguidas y todas sobre esa columna. Contado en el mismo
> > docker (`simonbolivar`) el 20 sep 2026:
> >
> > | | |
> > |---|---|
> > | Acudientes vivos | 1.085 |
> > | …con correo de **ficha** (`acudientes.email`) | 100 (9,2 %) |
> > | …con cuenta de usuario viva y activa | 1.000 |
> > | **…con correo de CUENTA (`users.email`)** | **0** |
> > | **Alcanzables por la recuperación** | **0** |
> >
> > **No son nueve de cada diez, son diez de diez** — incluidos los 100 que sí tienen correo en
> > la ficha. Y **no es que la copia esté anonimizada**: 917 cuentas vivas sí tienen correo. El
> > cero es sólo de los acudientes.
> >
> > **La causa está en este repositorio y es una asimetría entre tres controladores.**
> > `AlumnosController.php:496-502` y `ProfesoresController.php:248-254` derivan `email2` desde
> > `email` cuando el cliente no lo manda —y si tampoco hay `email`, inventan
> > `username@myvc.com`—. **`AcudientesController` no tiene esa red**: su línea 400 hace
> > `$usuario->email = Request::input('email2')` a pelo, y el front sólo manda `email`. Los tres
> > formularios se comportan igual; los tres controladores no. De ahí 876 alumnos con correo de
> > cuenta y 0 acudientes.
> >
> > **Y no se arregla sólo en un lado.** `Alumnos/GuardarAlumno.php:147` (`valorAcudiente`) sólo
> > tiene rama a `users` para `username`; cualquier otra propiedad cae en el `default`, que hace
> > `UPDATE acudientes SET …`. Sin una rama `email2` ahí, una columna de correo nueva en la
> > rejilla del front **volvería a escribir la ficha**.
> >
> > **Un dato que no tenía ninguno de los dos y que hay que decidir qué hacer con él:
> > 94 de los 100 correos de ficha no están en NINGUNA cuenta.** Hay direcciones reales que
> > nadie puede usar para recuperar.
> >
> > *Y el desglose por tipo salió distinto en las dos sesiones —853/34 allí, 876/40 aquí— con el
> > mismo total de 917: la diferencia es la población, no el dato. 853 y 34 son los que además
> > tienen ficha viva; 876 y 40 son las cuentas de ese tipo. **Y para profesores lo que la
> > recuperación alcanza son 12, no 34**, porque exige `is_active=1` y veintidós no la tienen.*
>
> Y el 20 % inalcanzable no se arregla con esto: se arregla pidiendo el número en la
> matrícula. **Un canal nuevo no repara los datos que necesita.**

---

## 11. Las decisiones: dos tomadas y cuatro abiertas

**D1 · ¿De quién es la cuenta de Meta? — DECIDIDO por Joseth el 19 sep 2026: (a), una por
colegio**, con su cartera y su medio de pago; MYVC cobra el módulo y **no revende**. Se
descartaron la central que refactura y la incluida en la licencia. Lo que esta decisión
compra: MYVC no adelanta dólares, no come la diferencia de cambio, y **si Meta recategoriza
la plantilla a *marketing* el recibo del ×17,5 le llega al colegio**, no a MYVC.

> **Y lo que cuesta, que hay que tener delante antes de implementar nada**: el escalón de
> cupo es **de la cartera**, así que con una cartera por colegio **no se hereda**. Cada uno
> de los diecisiete arranca en **250 destinatarios únicos en 24 h** y hay que **verificar su
> negocio uno a uno** para llegar a 2.000, que es lo mínimo para un abanico de boletín de un
> colegio de 419 familias. O sea que **D4 deja de ser una preferencia de orden y pasa a ser
> el camino obligado**: prematrícula primero, que cabe de sobra en 250, y el boletín después
> de la verificación. Dieciséis verificaciones son dieciséis trámites con NIT y documentos
> del colegio, y ése —no los mensajes— es el trabajo de esta decisión.

**D2 · ¿Cuál es la ventana del resumen académico? — DECIDIDO por Joseth el 19 sep 2026:
(a), un resumen por alumno y día** a hora fija, juntando todas las asignaturas del día en una
frase. **12.208 mensajes al año, COP 99 por alumno y año, y 1 mensaje en el peor día de una
familia.** Se descartaron el semanal —la mitad de barato pero llega tarde para actuar— y el
sólo-boletín.

> **Lo que esta decisión obliga a escribir, y es la única pieza que el subsistema de hoy no
> tiene**: `notificaciones:enviar` corre **cada quince minutos** y su marca es por fuente.
> Un resumen diario necesita **su propia marca y su propia ventana** —una fuente que no
> publica hasta que llega su hora y entonces junta todo lo del día—, conviviendo con las
> cuatro fuentes de push que siguen saliendo cada quince minutos. **El push no se toca**: es
> gratis y es donde vive el detalle.

**D3 · ¿A quién se escribe?** (a) a **un** número por alumno, el marcado como principal
—866 números—; (b) a **todos** los acudientes con móvil —976 envíos, +13 % y dos familias
enteradas—.

**D4 · ¿Qué se enciende primero?** Recomendado: **prematrícula** (COP 12.261 al año para los
dieciséis, y de paso se gana el escalón de cupo con volumen bajo y calidad alta), después
**ausencias** y **boletín**, y el **resumen académico el último y opcional**.

**D5 · ¿Qué viaja a la pasarela?** El número, el nombre de la plantilla y las variables. La
pasarela **ve teléfonos de familias y nombres de menores**; no ve notas, porque el mensaje no
las lleva. Falta decidir qué registro guarda y cuánto tiempo.

**D6 · ¿Contesta alguien?** Si nadie va a leer las respuestas, la plantilla lo dice.

---

## 12. Lo que este documento NO propone

- **No propone mandar notas por WhatsApp.** Ni el número ni la asignatura con el número.
- **No propone tocar el push.** Es gratis, ya funciona agrupado y es donde vive el detalle.
  WhatsApp es para quien no tiene la app instalada, que es de lo que se trata.
- **No propone un BSP.** En Colombia el recargo típico es 6,25 veces la tarifa de Meta; la
  Cloud API es gratis y un POST con Guzzle, y este repo ya demostró con `EnvioFcm` que sabe
  hablar con un tercero sin SDK.
- **No propone encenderlo en los dieciséis a la vez.** El escalón de entrada son 250
  destinatarios únicos en 24 h y un colegio son 419 familias.

---

## Cómo se rehacen estas cifras

Las de volumen y alcance, con las consultas de arriba contra `simonbolivar` en el docker
(`docker exec 8myvc-database-1 mysql -uroot -p… simonbolivar`), **medidas el 19 sep 2026** con
el árbol principal en `99060be`. Son de **un** colegio y del año **2018**, que es el último
con datos completos y fechados; los otros quince no los tengo y **no se han supuesto iguales**
salvo donde se dice.

Las de precio, buscadas el 19 sep 2026 en la documentación de Meta y en los rate cards de
terceros (§Fuentes). **Meta no publica las tarifas por país en la página, sólo en un CSV
descargable**, así que las de Colombia vienen de terceros que dicen haberlo verificado
—coinciden en 0,0008 *utility* y discrepan entre 0,014 y 0,02 en *marketing*, y aquí se usa
la baja—. **Antes de firmar un precio se descarga el rate card.**

### Fuentes

- [Pricing on the WhatsApp Business Platform — Meta](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing)
- [Upcoming pricing updates for service and utility messages — Meta](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing/non-template-messages)
- [Messaging Limits — Meta](https://developers.facebook.com/documentation/business-messaging/whatsapp/messaging-limits)
- [Template categorization — Meta](https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/template-categorization)
- [WhatsApp Business Messaging Policy](https://whatsappbusiness.com/policy/)
- [Meta WhatsApp Business API Pricing — Rates by Country (FormBeep, verificado 10 sep 2026)](https://formbeep.com/whatsapp-api-pricing/)
- [WhatsApp Messaging Pricing — Twilio](https://www.twilio.com/en-us/whatsapp/pricing)
- [Service Message Charging Starts October 1, 2026 — 360dialog](https://360dialog.com/blog/whatsapp-service-message-charging-october-2026/)
- [WhatsApp Pricing Change 2026 — respond.io](https://respond.io/blog/whatsapp-pricing-change-2026)
- [SMS masivos en Colombia — Inalambria](https://www.inalambria.express/post/cuanto-cuesta-enviar-sms-masivos-en-colombia) · [Onurix](https://www.onurix.com/portal/tarifas/sms-masivo-colombia)
- TRM del 19 sep 2026: COP 3.192,92/USD ([Banco de la República](https://www.banrep.gov.co/es/glosario/tasa-cambio-trm))


---

## 13. La decisión de usar correo tiene una dependencia, y está medida en rojo

**Escrito el 19 sep 2026, al cerrar el documento.** No es una objeción a la decisión —el
correo es gratis y el aspirante está delante del formulario, así que se le puede **exigir**
en vez de heredarlo—. Es la condición que tiene que cumplirse para que funcione, y hoy no se
cumple. Todo esto está medido en [29](29-los-env-no-son-uniformes.md) §2 el 2 sep 2026:

- **La única función que manda correo en toda la API es el reseteo de contraseña**
  (`LoginController.php:312`). No hay infraestructura de correo transaccional: hay un
  `Mailable` y un comando de diagnóstico.
- En `cads-itagui` el `.env` de producción es **el de desarrollo sin tocar**: `MAIL_MAILER=smtp`
  con `MAIL_HOST=mailhog` —un capturador que no existe en el servidor— y
  `MAIL_FROM_ADDRESS=null`, que Laravel lee como null de verdad y **rechaza el envío antes de
  intentarlo**. *Ese colegio no ha enviado un correo nunca.*
- **`lalvirtual.com` —el `MAIL_FROM_ADDRESS` de quince colegios— no está registrado.** Un
  remitente cuyo dominio no existe es lo que un proveedor grande manda a spam o rechaza.
- El arreglo de los dieciséis `.env` está escrito ahí como **tarea de Joseth, no de las
  sesiones**, y seguía abierto el 2 sep. **Desde el repositorio no se puede saber si ya se
  hizo**; se contesta corriendo `correo:probar` en cada colegio.

> **Y la diferencia que decide esto no es el precio, es el silencio.** WhatsApp avisa cuando
> no entrega; **el correo falla callado**. El reseteo de contraseña que no llega se reintenta
> —el usuario vuelve a pulsar, o llama—; el «pago aprobado» que no llega **no se reintenta,
> porque el aspirante no sabe que existía**. Ahí el coste de un mensaje perdido no son mil
> pesos: es un aspirante que se fue creyendo que nadie le contestó.

**Las dos cosas que lo cierran, y las dos son baratas:**

1. **Correr `correo:probar` en los diecisiete.** El comando ya existe, detecta los tres
   fallos por separado y dice cuál es cada uno. **Nadie lo ha corrido nunca en un colegio**
   —eso también está medido en el 29—. Va con el bucle del despliegue.
2. **Validar el correo del aspirante mandándolo, no con una expresión regular.** Un código o
   un enlace al enviar el formulario: es el único momento en que la persona está delante y
   puede corregir una errata. Tres días después, cuando el tesorero aprueba, ya no está — y
   una errata en el correo es indistinguible de un correo que no llegó.

**Lo que ya no hace falta de este documento**: las seis decisiones de §11 quedan sin efecto,
incluidas las dos que Joseth tomó esa misma tarde. Se dejan escritas con su razón porque
**una decisión revocada y una decisión que nunca se tomó no se leen igual**, y porque la de
D1 —cuenta por colegio o central— vuelve tal cual el día que se plantee cualquier canal de
pago por mensaje.
