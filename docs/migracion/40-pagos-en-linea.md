# Pagos en línea: qué cuesta, qué hace falta y qué pasa con quien no lo quiera

Pedido por Joseth el **19 sep 2026**: *«Que me diga la opción más barata para los pagos en
línea y qué toca hacer de mi lado, y si algún colegio lo desea usar qué tiene que hacer,
pero si un colegio no quiere pagos por aquí entonces qué pasaría.»*

**Los apartados 1 y 2 son medición; del 3 en adelante es propuesta y va marcado.**

> **Y esa frase ya no vale entera, 19 sep 2026.** Este documento decía *«nada de esto está
> autorizado todavía: no hay ni tabla ni ruta ni decisión tomada»*, y ese mismo día Joseth
> autorizó el **nivel 1 para el formulario de inscripción** —no para las pensiones—: dos rutas,
> dos tablas y el proveedor de la recomendación. Lo entregado y sus decisiones están en
> [`41`](41-el-formulario-de-inscripcion.md) §7; lo de **pensiones** sigue exactamente como lo
> deja el §6 de aquí, empezando por que **no hay importes que cobrar**.

---

## 1. Lo que hay hoy, medido (19 sep 2026, copia de desarrollo — UN colegio, no los dieciséis)

| | |
|---|---|
| alumnos vivos | 1.245 |
| sin paz y salvo (`pazysalvo=0`) | **140** |
| con `deuda` > 0 | **1**, y vale `1` — es un dato de prueba |
| matrículas con `fecha_pension` | **0** de 3.579 |
| tablas de pagos, facturas o recibos | **ninguna** |
| código de pasarela en los cuatro clientes | **ninguno** |

Las órdenes que lo rehacen:

```sql
SELECT COUNT(*) FROM alumnos WHERE deleted_at IS NULL;                       -- 1.245
SELECT COUNT(*) FROM alumnos WHERE deleted_at IS NULL AND pazysalvo=0;       -- 140
SELECT COUNT(*) FROM alumnos WHERE deleted_at IS NULL AND deuda>0;           -- 1
SELECT COUNT(*) FROM matriculas WHERE deleted_at IS NULL
       AND fecha_pension IS NOT NULL;                                        -- 0
```

**La conclusión que manda sobre todo lo demás: hoy MYVC sabe QUIÉN debe y no sabe CUÁNTO.**
Toda la cartera son dos columnas de `alumnos` —`pazysalvo` (tinyint) y `deuda` (int)— que
llena `importar/cartera` desde un Excel, y de las dos **sólo se usa la primera**. No se puede
cobrar un importe que el sistema no tiene, así que **llenar `deuda` es anterior a cualquier
pasarela** y no es trabajo de código.

## 2. Lo que cuesta cobrar, medido contra las páginas de tarifas (19 sep 2026)

Comisión por pago, **IVA del 19% ya incluido** (el IVA va sobre la comisión, no sobre la venta).

| Medio | Tarifa | $200.000 | $400.000 | $800.000 |
|---|---|---:|---:|---:|
| **Wompi QR / Bre-B** | **1%** | **2.380** | **4.760** | **9.520** |
| Mercado Pago PSE | ~1,99% ⚠️ | ~4.736 | ~9.472 | ~18.945 |
| **Wompi** PSE y tarjeta | 2,65% + $700 | 7.140 | 13.447 | 26.061 |
| ePayco *con* Davivienda | 2,64% + $690 | 7.104 | 13.388 | 25.954 |
| PayU | 3,29% + $300 | 8.187 | 16.017 | 31.678 |
| ePayco (otros bancos) | 3,29% + $700 | 8.663 | 16.493 | 32.154 |
| Mercado Pago tarjeta | 3,29% + $800 | 8.782 | 16.612 | 32.273 |
| Convenio de recaudo bancario | **fija**, no porcentual | **no es público** | | |

⚠️ La fila de Mercado Pago PSE sale de fuentes secundarias, **no** de su tarifario oficial.
Las demás son de las páginas de tarifas de cada proveedor. Wompi no cobra afiliación,
mensualidad ni mínimo, y dispersa al día hábil siguiente.

**Tres cosas que salen de la tabla y no de la opinión:**

1. **Wompi es la más barata de las de «integrar y listo»**, y en Wompi **PSE cuesta lo mismo
   que tarjeta**, que es raro en el mercado.
2. **El QR es 2,8 veces más barato que PSE dentro de la misma Wompi.** Con pensión de
   $400.000, 1.245 alumnos y 10 mensualidades, la diferencia entre cobrarlo todo por QR y
   todo por PSE es del orden de **$108 millones al año en un solo colegio**. Antes de
   apoyarse en esa cifra hay que confirmar con Wompi que ese 1% no lleva fijo escondido.
3. **Con ticket de pensión el enemigo es el porcentaje, no el fijo.** Por eso lo barato a la
   larga es el **convenio de recaudo con el banco del colegio**, que cobra tarifa fija.
   **Su precio no está publicado en ninguna parte**: lo negocia cada colegio con su gerente
   de cuenta. Eso es un límite de esta investigación, no un dato que falte por pereza.

## 3. PROPUESTA — dos niveles, y el primero casi no cuesta

**Nivel 0: MYVC no cobra, MYVC enlaza.** Una columna con la URL de pago del colegio y un
botón en la pantalla de deuda. Cero terceros, cero llaves, cero conciliación, cero
responsabilidad sobre dinero ajeno. **Lo que no da:** MYVC no se entera de que pagaron.

**Nivel 1: checkout + webhook.** Cada colegio abre **su** cuenta; MYVC guarda sus
credenciales. Recomendación: **Wompi para arrancar, empujando QR**, y que el colegio grande
migre a convenio bancario sin tocar código — guardando `proveedor` + credenciales por colegio
en vez de atarse a una pasarela.

**Fuera del alcance 1**, y no por recorte: facturación mensual, intereses de mora, anticipos,
**factura electrónica DIAN** (otro proveedor y otro producto) y guardar tarjetas para cobro
recurrente (exige llave privada y mete en PCI).

## 4. PROPUESTA — por qué el razonamiento de la pasarela central de IA NO se copia aquí

El mismo día se decidió, para IA, una pasarela central fuera de los colegios porque *«dieciséis
`.env` son dieciséis filtraciones»*. **Aquí no aplica igual, y conviene verlo antes de construir:**

- Una llave de IA es una **llave de gasto**: quien la roba gasta tu dinero.
- En pagos, la llave que va al navegador es **pública por diseño** (`pub_...`) y el dinero va a
  la cuenta del colegio. Robarla no mueve un peso.
- El único secreto con consecuencia es el **secreto de eventos**: con él se forja un webhook de
  «pagado» y se le regala el paz y salvo a quien no pagó.
- Y eso **se resuelve por mecanismo, no por custodia**: al recibir un webhook no creerse lo que
  dice, sino **volver a preguntarle a la pasarela el estado de esa transacción**.

**Conclusión: credenciales por colegio, en la base y no en el `.env`** —para cambiarlas sin
desplegar— y verificación contra la pasarela siempre.

### ⚠️ CORRECCIÓN DEL 19 SEP 2026: LA LLAVE ERA OTRA, Y ESO CAMBIA EL PRECIO

Este apartado decía que la reconsulta se hace *«autenticado **con la llave pública**»*, y de ahí
concluía que el secreto de eventos filtrado no sirve para nada. **La regla es buena y la llave
estaba mal.** Medido contra la documentación de Wompi el día que se fue a implementar:

| | |
|---|---|
| `GET /v1/transactions/{id}` | *«only available via **Private Key (`prv_*`)** from your server/backend. Requests without authentication or using a Public Key (`pub_*`) are **NO LONGER SUPPORTED** and will return 404 Not Found»* |
| Lo que Wompi **sí** recomienda para validar un evento | **la firma del evento**: `SHA256(valores + timestamp + secreto_de_eventos)`, contra `X-Event-Checksum` |

**Y el «404 Not Found» convierte esto de error de documentación en avería de producción.** Una
reconsulta con la llave pública no falla diciendo *«no tienes permiso»*: contesta **que esa
transacción no existe**. Implementado al pie de la letra, un webhook de un pago bueno se habría
leído como *«no puedo confirmarlo»*, habría contestado 503, y la pasarela habría reintentado para
siempre. **Ni un solo pago habría quedado registrado en los diecisiete, y el registro diría que
la transacción no existe** — que es el diagnóstico que manda a buscar al sitio equivocado.

### De dónde salió el error, que importa más que el error

**No salió de una fuente mala: salió de una fuente VIEJA.** La frase se escribió desde un resumen
de búsqueda web y no desde la documentación del proveedor, y ese *«no longer supported»* dice que
el resumen **probablemente fue cierto y envejeció**. Encima de eso se apoyó un argumento de
arquitectura que se le dio a Joseth.

*Para una afirmación que sostiene un diseño, una fuente secundaria no vale: hay que ir al
primario.* Es la regla de esta casa sobre las cifras —una medición se anota con la orden que la
produjo— aplicada a un hecho técnico, que es donde no se estaba aplicando. Y la comprobación
cuesta una consulta: el error sobrevivió un día entero porque **nadie la hizo**, no porque fuera
difícil.

Las dos mitades del error van juntas y conviene verlas juntas: **este documento descartó
exactamente el mecanismo que el proveedor recomienda**, y lo sustituyó por otro que creía
gratuito. No lo es — la reconsulta exige guardar **la llave privada del colegio**, que es la
única credencial de todo esto que toca dinero. Con eso se cae también la frase de dos renglones
más arriba: *«robarla no mueve un peso»* es cierto de la pública y **falso de la privada**.

**Y el fallo no fue creerse un dato: fue razonar sobre una arquitectura sin abrir la
documentación del proveedor.** Los precios de la tabla del §2 se midieron uno a uno contra las
páginas de tarifas; esta frase se escribió de memoria en el mismo documento. *La medición no es
un tipo de párrafo: es lo que hace falta en el párrafo del que cuelga una decisión.*

**Lo que se construyó con la corrección delante** (doc 41 §7), y que es una decisión distinta de
la que este apartado había tomado:

- **La firma del evento es obligatoria.** Sin `secreto_eventos` no se admite ningún webhook. Es
  la recomendación de Wompi y es el secreto barato: filtrado, deja forjar un *«pagado»* —un
  formulario de inscripción gratis—, no tocar la cuenta del colegio.
- **La reconsulta es opcional**, y manda cuando hay llave privada. Un colegio que no quiera
  dárnosla sigue cobrando con una cerradura menos.
- **Cada pago guarda en `verificado_por` cuál de las dos lo admitió**, porque una comprobación
  opcional sin rastro es una que nadie sabe si está encendida en los diecisiete.

## 5. PROPUESTA — qué pasa si un colegio NO lo quiere

No pasa nada, y eso es **una exigencia de diseño, no una esperanza**. Tres reglas:

1. **El interruptor no puede ser un `if` en el front.** Eso es lo que hoy rompe `demo`
   ([demo fuera de las listas](../../docs/migracion/ESTADO-ACTUAL.md)). La regla es al revés:
   **el back no publica lo que está apagado**. Sin pagos, la configuración no trae el bloque,
   las rutas contestan 404 y el menú no tiene nada que esconder.
2. **El criterio de encendido no es el interruptor: son las credenciales.** Un colegio sin
   las tres cadenas está apagado aunque el interruptor esté en 1. No es «acuérdate de
   configurarlo»: es que sin configurar no existe.
3. **Ni una dependencia nueva de composer.** Redirect + webhook es HTTP puro, que Laravel ya
   trae. Wompi y ePayco tienen SDK y **no hacen falta** — y no es estética: `vendor/` está
   **compartido por symlink**, así que un SDK entra en los dieciséis a la vez, incluidos los
   que dijeron que no.

## 6. Lo que espera una decisión de Joseth

1. ¿Nivel 0 o nivel 1? — **contestado en parte el 19 sep 2026**: nivel 1 **para el formulario
   de inscripción**, ya construido (doc 41 §7). Para **pensiones** sigue abierta, y el punto 3
   de esta lista es anterior a ella.
2. ¿Quién recibe el dinero? (recomendado: el colegio — si pasa por una cuenta de MYVC, eso
   convierte a MYVC en recaudador de terceros)
3. Llenar `deuda`: sin importe no hay cobro, y hoy son 140 marcados y cero importes.
4. Las rutas nuevas, que en este repo se autorizan con el precio delante.

## Fuentes (consultadas el 19 sep 2026)

- Wompi — planes y tarifas: `https://wompi.com/es/co/planes-tarifas/`
- Wompi Docs — ambientes y llaves: `https://docs.wompi.co/en/docs/colombia/ambientes-y-llaves/`
- Wompi Docs — seguimiento de transacciones: `https://docs.wompi.co/en/docs/colombia/seguimiento-de-transacciones/`
  (**la que corrige el §4**: la consulta por id va con la llave privada)
- Wompi Docs — eventos: `https://docs.wompi.co/en/docs/colombia/eventos/` (la firma del evento)
- Wompi Docs — checkout web: `https://docs.wompi.co/docs/colombia/widget-checkout-web/`
  (la firma de integridad, y el aviso de que **tiene que calcularse en el servidor**)
- ePayco — tarifas: `https://epayco.com/tarifas/`
- PayU/Rapyd — tarifas LatAm: `https://corporate.payu.com/tarifas-de-payu-en-latinoamerica/`
- Mercado Pago — checkout: `https://www.mercadopago.com.co/herramientas-para-vender/check-out`
- Bancolombia — Botón Bancolombia y recaudo PSE
- ACH Colombia — tarifario PSP 2025 (**la tabla es una imagen dentro del PDF: no se pudo
  extraer, y por eso la fila del convenio bancario va sin cifra**)
- MinEducación — alza de matrículas y pensiones 2026 (IPC 5,10%, tope 9,1%)
- Competencia: [`myvc_front/BEAM-QUE-TIENE-Y-PROPUESTAS.md`](../../../myvc_front/BEAM-QUE-TIENE-Y-PROPUESTAS.md) §1.4 y §4.3.18
