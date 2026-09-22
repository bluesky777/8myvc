# Lo que necesito de ti sobre los relojes — para la sesión del 22 sep

**Escrito la noche del 21 sep 2026.** Son cuatro cosas: tres consultas que sólo se pueden
correr desde el servidor y **una decisión que es tuya**. Cada una dice qué contesta y qué
cambia según el resultado, para que no haya que reconstruirlo.

El censo entero está en [53](53-los-cuatro-relojes.md).

---

## 1 · Cuántos `NOW()` están corriendo HOY en los colegios

Lo de anoche está en el árbol, **sin desplegar**, y `main` lleva meses sin salir.

```bash
for d in /home/micolev1/*/8myvc; do
  printf '%-40s %s\n' "$(basename $(dirname $d))" \
    "$(grep -rn 'NOW()' $d/app/ 2>/dev/null | grep -vcE ':[0-9]+:[[:space:]]*(//|\*)')"
done
```

**Qué contesta:** cuántas escrituras de cada colegio están poniendo hora **EDT** —una hora
por delante de Bogotá de marzo a noviembre— ahora mismo.

**Qué cambia según salga:**

- **20 en los diecisiete** → es lo esperado; el arreglo de anoche es lo que hay que
  desplegar y no hay sorpresa.
- **Números distintos entre colegios** → no todos tienen la misma versión desplegada, y eso
  es un hallazgo aparte: el despliegue no llegó entero alguna vez.
- **0 en alguno** → alguien ya lo tocó a mano en ese colegio, y hay que mirar qué más tocó.

---

## 2 · El histórico movido, que decide el tamaño de la reparación

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

**Qué cambia según salga:**

- **~495 / 8.058 / sólo 2026**, como en la copia del docker → la reparación es pequeña y de
  un solo año, y se puede hacer con una migración con `RastroDeLaMigracion::anotar()`.
- **~34.903 y «de 2018 a 2026»**, como dice el docblock de `App\Support\SellaConElReloj` →
  **entonces el que mide mal soy yo**, y hay que averiguar por qué antes de escribir ningún
  `UPDATE`. La discrepancia está anotada en el [53 §5](53-los-cuatro-relojes.md) con las tres
  formas de emparejar que probé; ninguna llega a 34.903.

---

## 3 · Si el desfase de una hora se ve en los datos

```sql
SELECT COUNT(*) AS total FROM ordenes_inscripcion;
SELECT COUNT(*) AS a_una_hora
FROM ordenes_inscripcion o JOIN matriculas m ON m.id = o.matricula_id
WHERE ABS(TIMESTAMPDIFF(SECOND, o.updated_at, m.updated_at)) = 3600;
```

**El total primero**, y por lo mismo: estas tablas son nuevas y pueden estar vacías, y un
cero sin denominador no distingue «no pasa» de «no hay nada que mirar».

**Qué contesta:** si el reloj EDT dejó rastro visible, o si las tablas nuevas todavía no
tienen uso suficiente.

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
