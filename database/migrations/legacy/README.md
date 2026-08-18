# Migraciones archivadas

Estas tres migraciones **no se ejecutan**. Están aquí como historia, no como
código vivo.

La razón: el repo tenía 3 ficheros de migración contra una base de datos de 90
tablas con 47 filas en `migrations`. La historia de migraciones nunca reflejó el
esquema real — se construyó a mano y por phpMyAdmin durante años. Reconstruir esa
historia hacia atrás no aporta nada y se puede equivocar.

A partir de ahora la fuente de verdad es `database/schema/mysql-schema.sql`, el
esquema real congelado. Laravel lo carga solo cuando `migrate` corre sobre una
base vacía (*squashed schema*).

**Toda tabla nueva o cambio de columna, de aquí en adelante, va en una migración
normal en `database/migrations/`.**

Laravel no mira dentro de subcarpetas al buscar migraciones, así que estar aquí
basta para que no se ejecuten.

## Un detalle que vale la pena saber

`2019_08_19_000000_create_failed_jobs_table.php` estaba en el repo pero **nunca se
ejecutó**: la tabla `failed_jobs` no existe en la base real, y la migración
tampoco figura en la tabla `migrations`. La baseline congela lo que hay de verdad,
no lo que el repo daba por hecho.

Si algún día se usan colas de verdad, `failed_jobs` se crea con una migración
nueva y normal.
