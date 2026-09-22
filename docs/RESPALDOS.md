# Respaldos

> **De qué tamaño es el agujero, con fecha.** El 20 sep 2026 a las 23:51 se desplegó una
> tanda que vació 407.909 casillas de `notas` en catorce colegios. Al día siguiente, al
> ir a deshacerlo: *«**Ninguna copia de seguridad previa al despliegue existía.** La del
> 21 sep a las 09:52 se hizo con el estropicio ya dentro; sirvió de red para el arreglo,
> no para deshacerlo»* — [doc 43, §incidente](migracion/43-lo-que-todavia-no-se-ha-calificado.md).
>
> Este documento es lo que hace que esa frase no se pueda volver a escribir.

## Las tres capas, y por qué no se sustituyen entre ellas

| capa | cuándo | qué cubre | qué **no** cubre |
|---|---|---|---|
| `tools/respaldo-antes-de-migrar.sh` | pegado al `migrate`, colegio por colegio | «esta migración acaba de pisar 12.632 notas» | que se pierda la cuenta |
| `tools/respaldo-diario-cpanel.sh` (cron) | de madrugada, toda la cuenta | «ayer funcionaba» | lo que pasó **hoy** |
| el del proveedor | lo decide él | el servidor entero, el disco, la cuenta | nada que puedas dar por hecho sin preguntarlo |

**Las dos primeras son de verdad distintas.** Restaurar el respaldo de madrugada para
deshacer un `migrate` del mediodía **borra las notas que los docentes pusieron esa
mañana**. Un respaldo que obliga a elegir qué día pierdes no es una vuelta atrás.

## 1. El cron diario, que es lo que se puede montar hoy

En cPanel **no hay una casilla de «respaldos automáticos»**: la programación de copias
vive en WHM, que es del proveedor, no de la cuenta. Lo que sí es de la cuenta es el
cron, y con él se hace lo mismo.

**Advanced → Cron Jobs → Add New Cron Job**, una vez por cuenta de cPanel:

```
5 2 * * *  /home/micolev1/demo.micolevirtual.com/8myvc/tools/respaldo-diario-cpanel.sh
```

Y otra vez en `lalvirtual.edu.co`, con su ruta y su `RAIZ`: el bucle de una cuenta no ve
las carpetas de la otra — la misma repetición a mano que ya lleva el Paso 1 de
[`DESPLIEGUE.md`](DESPLIEGUE.md).

Tres cosas de esta pantalla que se pagan caro:

1. **Aquí NO se pone `>/dev/null 2>&1`.** En el cron de `schedule:run` sí, porque habla
   cada minuto. Éste está escrito para **callar cuando sale bien**: si imprime algo es
   porque una base no se respaldó, y ese correo de cPanel es justo el aviso que se
   quiere. Silenciarlo deja un respaldo que lleva medio año sin correr y nadie lo sabe.
2. **La hora.** A las 02:05 no hay nadie calificando y el `--single-transaction` no le
   cierra la puerta a nadie. A las 07:00 sí.
3. **La cuota.** Los respaldos ocupan disco de la misma cuenta que las bases. El guion
   guarda 7 días y avisa al pasar de 4 GB (`DIAS`, `TOPE_MB`); si la cuenta va justa,
   `DIAS=3` y bajarlos fuera más a menudo.

La bitácora queda en `~/respaldos/diario/bitacora.log` y la tanda no rota si el día no
quedó limpio: un día malo no se lleva por delante los días buenos.

## 2. Lo que hay que preguntarle al proveedor — las cuatro preguntas

El proveedor **puede** estar haciendo copias diarias ya; también puede no estar
haciendo ninguna. Las dos cosas son normales en un compartido y **ninguna se deduce
del panel**. Por correo, con estas palabras:

1. ¿Hacen copias de seguridad automáticas de mi cuenta y de sus bases de datos? ¿Cada
   cuánto, y **cuántos días atrás** puedo llegar?
2. ¿Puedo **restaurarlas yo** desde cPanel —JetBackup, o «Restaurar» en el panel— o
   tengo que abrir un ticket? Si es ticket, **¿cuánto tarda?**
3. ¿Las copias están en **otro servidor** o en el mismo disco que mi cuenta?
4. ¿Las copias **cuentan contra mi cuota** de disco?

Si el panel tiene **JetBackup**, la respuesta a 1 y 2 ya está en pantalla: entra, mira
cuántos puntos de restauración hay y de qué días. Si lo que hay es **Backup Wizard**,
eso es una copia **a demanda** y no una copia programada: sirve para bajarse una
instantánea antes de un cambio gordo, no para tener ayer.

## 3. Sacarlo de la cuenta, que es lo único que cubre perder la cuenta

Un respaldo que vive en el mismo disco que la base no cubre el caso en que el problema
sea el disco. Desde el Mac, una vez por semana:

```bash
rsync -avz --delete \
  micolev1@SERVIDOR:~/respaldos/diario/ \
  ~/DESARROLLOS/respaldos-myvc/micolev1/
```

Es tirar y no empujar **a propósito**: si el servidor queda comprometido, no tiene
credenciales para llegar a tu máquina.

## 4. Un respaldo que nadie ha restaurado no es un respaldo

Una vez, y luego cada vez que cambie algo del alojamiento:

```bash
gzip -dc ~/respaldos/diario/2026-09-22/caz_zaragoza.sql.gz \
  | docker exec -i 8myvc-database-1 mysql -uroot -p<clave> caz_zaragoza_restaurado
```

y entrar a la aplicación apuntando a esa base. Lo que se comprueba no es que el fichero
exista —eso ya lo comprueban los dos guiones, y por eso miran que el volcado llegue a
`Dump completed` y no sólo que `mysqldump` no diera error—: lo que se comprueba es que
de ahí sale un colegio que funciona.

## Lo que este documento no arregla

**Nada de esto quita la pregunta de antes de migrar.** Un respaldo es lo que se hace
cuando ya sabes que algo puede pisarte datos; saberlo es el Paso 0 de
[`DESPLIEGUE.md`](DESPLIEGUE.md) y lo contesta `tools/riesgo-de-la-tanda.php`, base por
base. Restaurar 400.000 casillas es caro aunque salga bien: hay un día de clases entre
la copia y el error.
