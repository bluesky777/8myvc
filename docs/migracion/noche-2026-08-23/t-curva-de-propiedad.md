# La tercera curva — el identificador del cuerpo, y quién comprueba de quién es

> Sesión `8myvc-4f`, madrugada del 23 de agosto de 2026. Commit del árbol:
> **`9f363ed`**. Cierra la tercera pregunta abierta de la noche, la del lote H:
> *«230 rutas reciben un identificador por el cuerpo, 29 familias sin comprobar
> propiedad»*.
>
> **No se ejecutó ninguna ruta.**

## Por qué hacía falta medirla otra vez

El número del 08 y del 15 —**230 rutas**— está medido sobre **la tabla de rutas y
el cuerpo del método**. Y esta noche hemos aprendido dos cosas que lo afectan
directamente:

1. **La comprobación puede vivir a un salto**, en un helper privado que se llama
   `exigeQue…` en un sitio y `exigirQue…` en otro. La curva de autorización
   encontró **51 rutas defendidas solo por dentro, 9 de ellas a un salto**.
2. **«No comprueba propiedad» no es un fallo por sí solo.** Un profesor pone la
   falta de un alumno que no es suyo, y eso es su trabajo. La pregunta solo muerde
   **cuando la ruta la alcanza una familia**.

## El embudo, con la razón de cada paso

| | Rutas | Por qué se descartan |
|---|---|---|
| Leen un identificador de persona del cuerpo | **71** | las nueve claves que nombra `ExigirPersonaPropia` |
| — con `persona.propia` / `boletin.propio` | −11 | el guard ya compara con el token |
| — que comprueban propiedad **por dentro** | −7 | 6 en línea, **1 a un salto** |
| **Sin comprobar propiedad** | **53** | |
| — cerradas a las familias por `auth.personal` | −37 | un profesor escribiendo sobre otro **es su trabajo** |
| **Alcanzables por una familia con token** | **16** | |
| — que se defienden por **rol** dentro del método | −16 | 15 en línea, **1 a dos saltos** |
| **RESIDUO** | **0** | |

## El resultado

**Cero.** No hay ninguna ruta que reciba un identificador de persona por el
cuerpo, sea alcanzable por una familia, y **no compruebe ni propiedad ni rol a
ninguna profundidad**.

Las dieciséis que quedaban son las de `matriculas/*`, `alumnos/update`,
`acudientes/crear`, `enfermeria/crear-suceso` y `tardanzas/subir/poner-ausencia`:
todas **defendidas por dentro**, quince en línea y una a dos saltos. Son
exactamente las que `AutorizacionTest` ya lleva declaradas como *«aborta 400 salvo
superusuario o profesor con permiso»*.

## Por qué el cero es creíble

Un cero solo vale si el instrumento **encontró cosas antes de dejar de
encontrarlas**, y aquí las encontró en cada escalón: 71 → 53 → 16 → 0, y **cada
descarte tiene una razón distinta y comprobable**. Además:

- **Alcanza a un salto por los dos lados**: cazó una propiedad a un salto y un rol
  a dos. Si mirara solo el cuerpo del método, el residuo habría salido **17** y
  todos falsos.
- **El paso que más corta —los 37 de `auth.personal`— no es del instrumento**: es
  la regla de negocio de Joseth. *Un profesor escribiendo sobre un alumno que no
  es suyo es su trabajo, no un fallo.* Un inventario que cuente eso como agujero
  da 53 alarmas y ninguna es real.

## Lo que esto cierra, y lo que no

**Cierra**: la pregunta de si queda alguna escritura por identificador del cuerpo
sin nadie mirando. **No queda.**

**No cierra** —y hay que decirlo— la pregunta de **si el rol que comprueban es el
correcto**. Este barrido dice que **alguien mira**; no dice que mire lo que debe.
Esa sigue siendo una lectura de persona, ruta por ruta, y es la que tiene abierta
la tabla del §5 del 09.

> Y el matiz que hace útil el número: de los tres inventarios cerrados esta noche
> —escrituras, autorización y éste— **los tres convergen, y los tres necesitaron
> declarar la profundidad, el alcance y qué se estaba contando**. El único que dio
> cero es éste, y solo porque el paso que descarta 37 rutas **no es una medición:
> es una decisión ya tomada.**
