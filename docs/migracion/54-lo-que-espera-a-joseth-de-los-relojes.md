# Lo que necesito de ti sobre los relojes — para la sesión del 22 sep

**Escrito la noche del 21 sep 2026.** Son cuatro cosas: tres consultas que sólo se pueden
correr desde el servidor y **una decisión que es tuya**. Cada una dice qué contesta y qué
cambia según el resultado, para que no haya que reconstruirlo.

El censo entero está en [53](53-los-cuatro-relojes.md).

---

## 1 · ~~Cuántos `NOW()` corren hoy~~ — CONTESTADO el 22 sep: **16 en los diecisiete**

Lo de anoche está en el árbol, **sin desplegar**, y `main` lleva meses sin salir.

```bash
for d in /home/micolev1/*/8myvc; do
  printf '%-40s %s\n' "$(basename $(dirname $d))" \
    "$(grep -rn 'NOW()' $d/app/ 2>/dev/null | grep -vcE ':[0-9]+:[[:space:]]*(//|\*)')"
done
```

**Qué contesta:** cuántas escrituras de cada colegio están poniendo hora **EDT** —una hora
por delante de Bogotá de marzo a noviembre— ahora mismo.

**Resultado: 16 en los diecisiete, sin una sola excepción.** O sea que **el despliegue llegó
entero** — que era lo que esta consulta venía a descartar.

Y de paso cazó una errata mía: yo había publicado **20**, y eran **17** (ver [53](53-los-cuatro-relojes.md) §1).
El 16 desplegado contra el 17 de `main` cuadra y tiene explicación: falta el de la planilla
offline, que entró en `3e16747` y no se ha desplegado.

**Lo que queda de aquí:** esas 16 escrituras siguen poniendo hora EDT en los diecisiete hasta
que se despliegue. No es urgente —lleva años así— pero es una hora de más entre marzo y
noviembre en las tablas de inscripciones, favoritos e informes recientes.

---

## 2 · ~~El histórico movido~~ — CONTESTADO el 22 sep: **495 filas, todas de 2026**

En un colegio con datos; `caz-zaragoza` sirve.

```sql
SELECT COUNT(DISTINCT s.id) AS subunidades_en_utc, COUNT(*) AS pares,
       MIN(YEAR(s.created_at)) AS desde, MAX(YEAR(s.created_at)) AS hasta
FROM notas n JOIN subunidades s ON s.id = n.subunidad_id
WHERE ABS(TIMESTAMPDIFF(SECOND, s.created_at, n.created_at)) = 18000;

SELECT COUNT(*) AS subunidades_totales FROM subunidades WHERE created_at > '2000-01-01';
```

**Las dos, no la primera sola:** un número sin su denominador no dice nada.

**Qué contesta:** cuántas filas habría que reparar, y de qué años.

**Resultado (`caz-zaragoza`, 22 sep 2026):** `495 / 8058 / 2026 / 2026` sobre **28.448**
subunidades. **Reproduce el docker al número**, así que el docblock de
`App\Support\SellaConElReloj` —que decía 34.903 «de 2018 a 2026»— era el que estaba mal, y
ya lleva la corrección escrita sin borrar la cifra vieja.

**Qué significa:** la reparación del histórico, si se hace, es **una migración pequeña de un
solo año** con `RastroDeLaMigracion::anotar()` delante. No hay que tocar nueve años de notas.

---

## 3 · ~~Si el desfase de una hora se ve en los datos~~ — **NO MEDIDO** el 22 sep

```sql
SELECT COUNT(*) AS total FROM ordenes_inscripcion;
SELECT COUNT(*) AS a_una_hora
FROM ordenes_inscripcion o JOIN matriculas m ON m.id = o.matricula_id
WHERE ABS(TIMESTAMPDIFF(SECOND, o.updated_at, m.updated_at)) = 3600;
```

**Resultado: `ordenes_inscripcion` tiene CERO filas**, así que el cero de la segunda consulta
es **NO MEDIDO** y no «no pasa». El portal de inscripción no está en uso en ese colegio.

Por eso el total iba primero, y es la regla entera de esta casa en una línea: **un cero sin
denominador no distingue «lo revisé y no ocurre» de «no revisé nada»**.

**Y esta pregunta no bloquea nada**, que conviene decirlo para que nadie la persiga: el
desfase de EDT **ya está probado sin tocar los datos**. La medición del §3 del
[53](53-los-cuatro-relojes.md) compara `NOW()` con `UTC_TIMESTAMP()` en la misma consulta y
da **−4,0 h** en los diecisiete, contra los −5 de Bogotá. Buscarlo además en las filas sería
una confirmación agradable, no una incógnita.

Si alguna vez apetece confirmarlo sobre datos reales, el sitio es una tabla escrita con
`NOW()` **que sí tenga uso**, contra `bitacoras`, que va en Bogotá:

```sql
SELECT 'informes_recientes' t, COUNT(*) filas FROM informes_recientes
UNION ALL SELECT 'accesos_favoritos',     COUNT(*) FROM accesos_favoritos
UNION ALL SELECT 'descargas_de_planilla', COUNT(*) FROM descargas_de_planilla
UNION ALL SELECT 'colillas_inscripcion',  COUNT(*) FROM colillas_inscripcion
UNION ALL SELECT 'pagos_inscripcion',     COUNT(*) FROM pagos_inscripcion
UNION ALL SELECT 'bitacoras (referencia)', COUNT(*) FROM bitacoras;
```

Y si `informes_recientes` o `accesos_favoritos` tienen filas, emparejando cada una con la
línea de bitácora más cercana del mismo usuario: **el pico debería estar en 0 y estará en
+3.600** mientras el `NOW()` siga desplegado.

---

## 4 · LA DECISIÓN: `importaciones`

**Esto no lo puedo decidir yo.** La tabla está **entera en UTC** a propósito y es la
excepción declarada de `RelojUnicoTest`: se escribe con `now()` porque `inicio` y `fin` sólo
se restan entre sí.

Anoche se cerró la mitad que no era decisión tuya: **los tres lectores pasan ya por
`PuntoDeControlDeImportacion::enLaHoraDelColegio()`**, así que ninguna pantalla ni el acta
en Excel enseñan UTC. Antes eran tres y sólo uno convertía.

Lo que queda es de una línea y es tuyo:

| Opción | Lo que cuesta |
|---|---|
| **Dejarla en UTC** (lo de hoy) | Una tabla del sistema con reloj propio y una función que hay que recordar al leerla. A cambio, **su columna tiene UNA sola zona en toda su historia**. |
| **Moverla a Bogotá** | Se lee igual en phpMyAdmin que las demás y desaparece la excepción. A cambio, **las filas viejas se quedan cinco horas por delante** hasta que alguien las reescriba, y la columna pasa a tener dos relojes — la enfermedad que `Reloj` vino a curar. |

**Mi recomendación: dejarla en UTC.** El motivo por el que la excepción existía —«nunca sale
por pantalla»— ya no hace falta, porque ahora la conversión está en un solo sitio y con la
tabla. Moverla compraría legibilidad en phpMyAdmin pagando con una mezcla permanente, que es
justo el cambio que este trabajo lleva dos días deshaciendo.

Si decides moverla: va **entera y en un commit**, con `RastroDeLaMigracion::anotar()`
delante, y se quita de `PERMITIDOS` en `RelojUnicoTest` en el mismo.

---

## Y una cosa que NO te estoy pidiendo, pero que conviene saber

**La suite entera.** La lancé anoche contra la base por defecto porque el cambio toca
`freshTimestamp()` de dieciséis modelos y no había subconjunto honesto: filtrar por «tests
que nombran un modelo tocado» daba **137 clases** y filtrar por «tests que miran una fecha»
daba **291**, o sea la suite disfrazada las dos veces. El resultado está en
[ESTADO-ACTUAL](ESTADO-ACTUAL.md).

Eso **no sustituye** la corrida de antes de desplegar, que sigue siendo tuya y con
`COBERTURA_RUTAS` puesto.
