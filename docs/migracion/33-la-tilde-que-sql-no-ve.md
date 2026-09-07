# 33 · La tilde que SQL no ve, y PHP sí

> **Medido el 7 sep 2026 contra `e9af70f`**, en **los dos motores**: el MySQL
> 8.0.42 del docker y un **MariaDB 10.5.29** levantado a propósito, que es la
> serie de producción. Todo lo de aquí sale igual en los dos — y **eso es parte
> del hallazgo**, porque durante un rato pareció que no (§4).

> ## SI HAS LLEGADO AQUÍ DESDE `git log`, ÉSTA ES LA CORRECCIÓN
>
> **Los mensajes de los commits `9032c10` y `e9af70f` afirman una diferencia entre
> MariaDB y MySQL 8 que NO EXISTE.** Dicen que comparar la columna contra
> `CONVERT(UNHEX(…) USING utf8mb4)` *«revienta con `Illegal mix of collations` en
> MariaDB y funciona en MySQL 8»*, y el segundo añade que *«aplica a cualquier
> receta futura»*. **Es falso: revienta en los dos.** El porqué del error y cómo se
> llegó a él están en la §4.
>
> Los dos mensajes se quedan como están —**reescribir historia ya fundida es peor
> que el error**— así que la corrección tiene que estar donde alguien la busque, y
> los hashes van escritos aquí para que un `grep 9032c10` sobre `docs/` la
> encuentre. *Un hallazgo retirado que sólo se retira en el documento nuevo deja el
> original suelto justo en el sitio donde se busca.*

Esto salió escribiendo una receta que después se canceló. **Lo medido sobrevive a
la receta** porque no depende de ella: es una trampa de cualquier fila con tilde
que un `Role::hasRole()` compare, y de cualquiera que vaya a comprobar una con un
`SELECT`.

---

## 1. El hecho, en una línea

**`roles.name` es `utf8mb4_unicode_ci` —insensible a acentos y a mayúsculas— y el
código compara la cadena en PHP, que no lo es.** De modo que la base y el
programa **no contestan lo mismo a la misma pregunta**.

| | SQL (`=` con la collation de la columna) | PHP (`==`) |
|---|---|---|
| `'Coord academico'` vs `'Coord académico'` | **iguales · 1** | **`false`** |
| `'COORD ACADÉMICO'` vs `'Coord académico'` | **iguales · 1** | **`false`** |

Medido en MySQL 8.0.42 y en MariaDB 10.5.29: **idéntico en los dos**.

## 2. Por qué eso es peligroso y no sólo curioso

`Role::hasRole()` trae los roles del usuario y compara **el nombre literal en
PHP**:

```php
if ($roles[$i]->name == $role) { … }
```

Así que en un colegio donde la fila esté escrita `Coord academico`:

- **`Role::isCoordAcademico()` devuelve `false`** y el permiso no funciona.
- **`SELECT … WHERE name = 'Coord académico'` DEVUELVE LA FILA**, así que quien
  vaya a comprobar por qué no funciona **verá que el rol está donde tiene que
  estar**.

**La comprobación obvia dice que está bien.** Ése es el daño: no es que falle, es
que la herramienta con la que uno lo investigaría **confirma lo contrario de lo
que pasa**. El docblock de `Role::isCoordAcademico` ya avisaba de que la
comparación es en PHP y que la collation no salva la tilde; lo que faltaba era
esto —que el `SELECT` de diagnóstico miente— y las formas de abajo.

> Y no es una hipótesis sobre un rol concreto: vale para **cualquier** rol con
> tilde comparado con `hasRole()`. Hoy son `Coord académico` y `Psicólogo`.

## 3. Las formas que sí sirven

**(a) `HEX()`, que es la que se recomienda.** Compara bytes, es ASCII puro y **no
depende del charset con que esté abierta la sesión** — comprobado corriéndolo con
el cliente en `latin1` a propósito:

```sql
SELECT id, name FROM roles
WHERE HEX(name) = '436F6F72642061636164C3A96D69636F'   -- 'Coord académico' en UTF-8
  AND deleted_at IS NULL;
```

Esos 32 caracteres son 16 bytes / 15 caracteres; el `C3A9` es la `é`. **Cópialos,
no los teclees**: teclear la cadena es justo lo que introduce el problema.

**(b) Un `COLLATE` explícito**, si se prefiere leer la cadena:

```sql
… WHERE name = CONVERT(UNHEX('436F6F72642061636164C3A96D69636F') USING utf8mb4)
               COLLATE utf8mb4_bin;
```

**El `COLLATE` no es opcional.** Sin él, la comparación mezcla la collation de la
columna (`utf8mb4_unicode_ci`) con la que produce el `CONVERT` y sale:

```
ERROR 1267 (HY000): Illegal mix of collations … for operation '='
```

**(c) Y si la fila está mal escrita, se CORRIGE, no se añade otra.**
`roles.name` tiene índice **UNIQUE** con esa collation, así que insertar la
versión correcta al lado de la mal escrita da
`ERROR 1062 Duplicate entry 'Coord académico'` — la base considera que ya está.
El remedio es un `UPDATE` de los bytes de la fila que hay.

*Las tres comprobadas en los dos motores.*

---

## 4. Y la parte que no es de SQL: **cómo se inventó una diferencia de motor que no existe**

**Esto se publicó afirmando que el `ERROR 1267` pasaba en MariaDB y no en MySQL
8** —en `9032c10`, y ampliado a *«aplica a cualquier receta futura»* en la fusión
`e9af70f`—. Era falso, y merece quedar escrito porque el mecanismo es reutilizable.

**Y son dos piezas distintas**, que conviene no fundir en una: la primera explica
por qué salió mal, y la segunda por qué **nadie lo notó, empezando por quien lo
escribió**.

**(1) Un experimento sin control.** Contra el docker se probó la forma **con**
`COLLATE utf8mb4_bin` —que funciona— y contra MariaDB la forma **sin** él —que
no—. **Nunca se corrió la misma sentencia en los dos.** La diferencia era de la
consulta y se le atribuyó al motor.

Eso no es un fallo de medida —los dos resultados eran correctos— sino **un
experimento sin control, que da un resultado limpio, reproducible y falso**. Se
puede repetir mil veces y sale siempre lo mismo, porque lo que está mal no es la
ejecución: es que faltaba la celda que lo habría desmentido.

**(2) Y lo que lo hizo creíble, que es lo peor:** el mensaje de error **nombra una
collation distinta en cada motor**, porque cada uno tiene la suya por defecto:

```
MySQL 8.0.42      … (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT)
MariaDB 10.5.29   … (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT)
```

Los dos textos son distintos, los dos llevan un nombre propio del motor, y **el
comportamiento es el mismo**. Lee como una diferencia de motor y no lo es.

> **La regla que sale de aquí, y sirve para cualquier trampa de esta familia: un
> mensaje de error que menciona nombres propios de un motor no es prueba de un
> comportamiento de ese motor.** Antes de escribir «en X pasa y en Y no», correr
> **la misma sentencia, sin una letra de diferencia**, en los dos.
>
> Y el aviso de `CLAUDE.md` —*un `JSON_TABLE`, un `LATERAL` o un `->>` pasan la
> suite entera y revientan en los dieciséis*— **sigue en pie y no lo toca esto**:
> lo que este caso enseña es que **también se puede inventar una divergencia que
> no está**, y que las dos direcciones cuestan lo mismo de comprobar.

## 5. Cómo se reprodujo, para que el siguiente no monte el andamio otra vez

```bash
docker run --rm --name m -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -d mariadb:10.5
until docker exec m mariadb -uroot -e "SELECT 1" >/dev/null 2>&1; do sleep 2; done
docker cp prueba.sql m:/tmp/
docker exec m sh -c 'mariadb -uroot --default-character-set=utf8mb4 < /tmp/prueba.sql'
docker rm -f m
```

Y la misma `prueba.sql` contra el docker:

```bash
docker cp prueba.sql 8myvc-database-1:/tmp/
docker exec 8myvc-database-1 sh -c 'mysql -uroot -p123456 --default-character-set=utf8mb4 < /tmp/prueba.sql'
```

**`SELECT VERSION()` como primera línea del script**, siempre: es lo que impide
creer que se midió un motor cuando se midió el otro.

*Lo que sigue sin medirse, dicho con esas palabras: **ninguna base de un colegio
real se ha mirado**. No se sabe si en alguno la fila está mal escrita, y desde el
7 sep 2026 tampoco es una tarea de nadie — donde no haya coordinador académico
publica el administrador, que es la decisión de Joseth ([32 §2](32-la-entrada-de-la-app-de-escritorio.md)).
Esto queda como diagnóstico para el día que algo apunte aquí.*
