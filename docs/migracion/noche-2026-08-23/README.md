# La noche del 22 al 23 de agosto de 2026 — índice

> Esta carpeta tiene **veinte documentos** y ninguno dice por dónde empezar. Este
> índice existe para que quien la abra dentro de tres meses no tenga que leer
> veinte cabeceras para encontrar el que busca.
>
> El reparto y las reglas están en [15-la-noche-en-paralelo.md](../15-la-noche-en-paralelo.md).
> Los hallazgos numerados viven en [05-codigo-muerto-y-roto.md](../05-codigo-muerto-y-roto.md);
> **la numeración la lleva quien coordina**, y algunas secciones se renumeraron al
> fundir para deshacer colisiones del reparto original.

## Si solo vas a leer dos

| Documento | Para qué sirve |
|---|---|
| **[que-se-nota-en-un-colegio.md](que-se-nota-en-un-colegio.md)** | **Lo que hay que tener delante para desplegar.** Qué deja de pasar, qué capacidad quita y a quién, qué enciende que hoy no funciona — y las minas, que no se notan al desplegar y esperan a que alguien haga lo razonable |
| **[las-cegueras.md](las-cegueras.md)** | **Por qué nadie lo sabía antes.** Las siete formas en que un detector contesta la señal en vez del hecho, con la población de cada una y los dieciocho instrumentos que mintieron esta noche |

El primero caduca con la tanda. **El segundo no**: es lo que va a seguir sirviendo
cuando estos arreglos sean historia.

## Los lotes, por lo que preguntaban

| Lote | La pregunta | §§ que declara |
|---|---|---|
| [A](a.md) | Los catálogos del colegio: **editar y borrar** | §81–84, §122 |
| [B](b.md) | Ordinales de disciplina y ciudades | §85–88 |
| [C](c.md) | La rejilla: **quién escribe una definitiva y con qué candado** | §89–92 |
| [D](d.md) | La configuración del año | §93–96 |
| [E](e.md) | Personas e imágenes: **quién ve y quién escribe la ficha de otro** | §97–100, §153–156 |
| [F](f.md) | PIAR, actividades y votaciones: los interruptores de lo que ve el alumno | §101–104 |
| [G](g.md) | Los 44 interruptores `tinyint(1)`, **contra los cuatro clientes** | §105–107 |
| [H](h.md) | Los 230 identificadores del cuerpo | §108–110 |
| [I](i.md) | El barrido por tipo de token: qué alcanza cada uno | §111–113 |
| [J](j.md) | Las rutas **ya cubiertas que nadie ha juzgado** | §114–116 |
| [K](k.md) | Las columnas que se pisan donde no llegaba ningún lote | §118–121 |
| [L](l.md) | Las sobras huérfanas | §123–124 |
| [M](m.md) | Descongelar los dos modelos que se habían congelado | §125–127 |
| [N](n.md) | Los ayudantes compartidos de los tests: **lo que devuelve no es lo que promete su nombre** | §157–159 |
| [O](o.md) | Completar la población de `PerfilesController` | §130–132 |
| [P](p.md) | **Las que escriben sin decirlo** | §133–137 |
| [Q](q.md) | El calendario, donde **el cliente decidía si el interruptor se aplicaba** | §150–152 |
| [R](r.md) | **El boletín de una familia**, y la imagen privada en el muro | §140–142, §166–167 |
| [S](s.md) | **La única escritura que alcanza una familia** | §143–145 |
| [T](t.md) | Lo que destapó la curva de profundidad | §146–149 |

Y uno que no es de lote: [p-curva-de-profundidad.md](p-curva-de-profundidad.md)
—cuántas rutas escriben, y a qué distancia—.

> **Los rangos son los que declara cada documento**, no los de la tabla del
> reparto: al fundir se renumeró lo que colisionaba. Si buscas una sección por
> número y no aparece donde esperas, mira el 05, que es la lista buena.

## La numeración, comprobada — contra `main` en `35cf76f`

Extraídas todas las `§NNN` que aparecen **en encabezados** de los diecisiete
documentos de lote —el encabezado es donde un documento *reclama* una sección, no
donde la cita—:

| | |
|---|---|
| Secciones reclamadas | **68** |
| Reclamadas por **más de un documento** | **0** |

**Cero colisiones**: la renumeración que se hizo al fundir no dejó nada cruzado.

Y los huecos que quedan en el rango de esta noche, con lo que son:

| Hueco | Qué es |
|---|---|
| **§117**, **§128–129**, **§138–139**, **§160–165** | **números que nadie usó**, no secciones perdidas: quedaron sin asignar al ir abriendo lotes sobre la marcha. **No hay que rellenarlos** |

> **Y hay que repetirla después de cada renumerado, no solo al final.** Es la
> lección más cara de las tres colisiones que hubo esta noche: **arreglar una
> crea la siguiente si el número nuevo se elige sin volver a correr la
> comprobación** — el renumerado es justo el momento en que se inventa un número.
> Al mover un §143 a §166, el número que quedó libre lo ocupó el §144 de al lado,
> que ya tenía dueño.
>
> Y su hermana: **el título del documento se queda viejo cada vez.** Dos de las
> tres colisiones fueron un título declarando un rango que su cuerpo ya no tenía,
> y **la segunda fue en el mismo fichero que la primera**.

> **Esta comprobación caduca en cuanto entra un lote nuevo, y no avisa de que ha
> caducado.** La primera vez que se corrió daba **cero colisiones y era cierto**;
> repetida después de fundir el último lote dio **una** —§143, reclamada por dos
> documentos—. Por eso lleva el sello del commit: **hay que repetirla después del
> último merge, no antes.** Son treinta segundos y es lo único que separa un 05
> con la numeración limpia de uno con dos secciones llamadas igual.

> **Y lo primero, porque es lo que hace ir a buscar a alguien**: **un hueco en la
> numeración no es una sección perdida, es un número que nadie usó.** Quien lea el
> 05 de corrido y eche en falta la §117 no ha perdido nada: no existe.

> **Un aviso sobre esta misma comprobación, porque su salida se lee entera y solo
> vale la mitad**: por debajo de §81 da «huecos» que no lo son —§54–80—. Son
> secciones de noches anteriores que viven en el 05 y que estos documentos solo
> **citan**; el filtro mira encabezados y algún encabezado cita una sección vieja.
> **El tramo de abajo es ruido y el de arriba es el que vale**, y por eso está
> dicho aquí en vez de dejar el número solo.

---

## Lo que se repite en varios, y por eso está aquí

Cuatro cosas aparecieron en tres o más lotes distintos, cada uno mirando otra
cosa. Si vas a leer solo un párrafo de cada documento, que sean éstos:

- **«Un campo que no se manda no es un campo que no cambia: es un campo que se
  pisa»** (§68). Sale en A, C, D, E, K y S. Y su matiz, que costó dos lotes:
  **«tiene defecto» no es «está a salvo»** — un defecto que no es el valor de la
  fila es un pisado con otro nombre.
- **Cerrar una serie no es cerrar la operación.** Cinco series de noches
  anteriores se habían cerrado sobre media población, y las cinco se completaron
  esta noche. Lo que hay que escribir al cerrar no es «arreglado»: es **sobre qué
  población se cerró**.
- **Mira el resultado, no el estado.** Casi todo lo que salió, salió de ejecutar
  dos rutas seguidas, de comparar un método con su hermana, o de mirar lo que
  recibe el alumno en vez de la fila.
- **El arreglo evidente puede ser peor que el fallo.** Dos rutas de esta noche
  —`mis-actividades/guardar` y `alumnos/update`— convertirían, «arregladas», un
  error ruidoso en una escritura silenciosa que borra.

## Cómo se corre una tanda de la que te vas a fiar

No está aquí: está en **[03-tests.md](../03-tests.md)**, porque este README caduca
con la noche y ese procedimiento vale para cualquier tanda. Los tres pasos salen
de tres tropiezos distintos de esta noche, y cada uno lleva escrito **por qué
existe** — que es lo único que impide que alguien se salte el que parece
redundante, que es justo el primero.

---

## Y una cosa sobre los cuadernos

Cada sesión llevó además un cuaderno fuera del repo, en
`../../../8myvc-cola/<letra>.md`, con una línea por cosa y la hora. **No están en
git a propósito**: son el rastro de la coordinación, no del trabajo. Lo que valía
la pena conservar de ellos está en estos documentos.
