# Pagos en línea: qué cuesta, qué hace falta y qué pasa con quien no lo quiera

Pedido por Joseth el **19 sep 2026**: *«Que me diga la opción más barata para los pagos en
línea y qué toca hacer de mi lado, y si algún colegio lo desea usar qué tiene que hacer,
pero si un colegio no quiere pagos por aquí entonces qué pasaría.»*

**Los apartados 1 y 2 son medición; del 3 en adelante es propuesta y va marcado.** Nada de
esto está autorizado todavía: no hay ni tabla ni ruta ni decisión tomada.

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
  dice, sino **volver a preguntarle a la pasarela el estado de esa transacción**, que en Wompi
  se hace con `GET /v1/transactions/{id}` autenticado **con la llave pública**. Con esa regla,
  el secreto filtrado no sirve para nada.

**Conclusión: credenciales por colegio, en la base y no en el `.env`** —para cambiarlas sin
desplegar— y verificación contra la pasarela siempre.

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

1. ¿Nivel 0 o nivel 1?
2. ¿Quién recibe el dinero? (recomendado: el colegio — si pasa por una cuenta de MYVC, eso
   convierte a MYVC en recaudador de terceros)
3. Llenar `deuda`: sin importe no hay cobro, y hoy son 140 marcados y cero importes.
4. Las rutas nuevas, que en este repo se autorizan con el precio delante.

## Fuentes (consultadas el 19 sep 2026)

- Wompi — planes y tarifas: `https://wompi.com/es/co/planes-tarifas/`
- Wompi Docs — ambientes y llaves: `https://docs.wompi.co/en/docs/colombia/ambientes-y-llaves/`
- Wompi Docs — seguimiento de transacciones y eventos
- ePayco — tarifas: `https://epayco.com/tarifas/`
- PayU/Rapyd — tarifas LatAm: `https://corporate.payu.com/tarifas-de-payu-en-latinoamerica/`
- Mercado Pago — checkout: `https://www.mercadopago.com.co/herramientas-para-vender/check-out`
- Bancolombia — Botón Bancolombia y recaudo PSE
- ACH Colombia — tarifario PSP 2025 (**la tabla es una imagen dentro del PDF: no se pudo
  extraer, y por eso la fila del convenio bancario va sin cifra**)
- MinEducación — alza de matrículas y pensiones 2026 (IPC 5,10%, tope 9,1%)
- Competencia: [`myvc_front/BEAM-QUE-TIENE-Y-PROPUESTAS.md`](../../../myvc_front/BEAM-QUE-TIENE-Y-PROPUESTAS.md) §1.4 y §4.3.18
