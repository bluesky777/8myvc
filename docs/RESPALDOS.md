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

> ### Antes de montar esto: en `micolev1` el proveedor ya lo hace — leído en JetBackup el 24 sep 2026
>
> La cuenta tiene **JetBackup 5**, y en *Databases* salen **18 bases** con **30 copias cada
> una**, `Daily` · `Incremental` · **`Cloud Backup - SSHv1`**, la última del 23 sep a las
> **02:23**. Tres cosas se siguen de ahí y valen por tres de las cuatro preguntas de §2:
> hay copias diarias, **se restauran desde el panel sin ticket** —y por base, no sólo la
> cuenta entera—, y **no viven en el disco de la cuenta**: el destino es remoto.
>
> Así que **el cron de este apartado deja de ser urgente en `micolev1`**: duplicaría a las
> 02:05 lo que el proveedor hace a las 02:23, ocupando cuota propia. Lo que NO cubre
> JetBackup sigue en pie y es lo de siempre: **una copia de las 02:23 no deshace un `migrate`
> del mediodía sin borrar la mañana de los docentes** (§ el guion de antes de migrar), y una
> copia que vive en el proveedor no cubre perder la cuenta CON el proveedor.
>
> **El horizonte son 30 días y ni un día más — leído el 24 sep 2026 en el desplegable de
> `Choose Other Backup`.** La lista va del **24 sep 02:56** al **25 ago 02:01**, treinta
> entradas, una por día... salvo que **falta el 18 de septiembre**: la cadena tiene agujeros,
> y ninguno avisa. Las horas van de la 01:25 a las 03:31, así que tampoco hay un hueco fijo
> en la madrugada que un cron propio pudiera ocupar.
>
> De ahí sale lo único que el proveedor **no** puede darte: **cualquier cosa anterior a un
> mes**. El año pasado, el cierre del periodo anterior, el estado de una base antes de una
> tanda de hace seis semanas — nada de eso existe en ningún sitio. Por eso
> `tools/bajar-respaldos.sh` se queda, pero con otro ritmo: **una copia al mes, guardada un
> año**, en tu disco. No compite con JetBackup; cubre justo donde JetBackup termina.
>
> **Dos cosas sin comprobar:** si `micolevi` —la otra cuenta, donde está LAL solo— tiene lo
> mismo, y si esas copias cuentan contra la cuota. Y un detalle que se ve en la lista:
> `micolev1_la_hermosa` sigue ahí, con 2,81 MB. Es el colegio cuya **carpeta** se borró el 30
> ago; la base no, y por eso se respalda cada noche.

En cPanel **no hay una casilla de «respaldos automáticos»**: la programación de copias
vive en WHM, que es del proveedor, no de la cuenta. Lo que sí es de la cuenta es el
cron, y con él se hace lo mismo.

**Advanced → Cron Jobs → Add New Cron Job**, una vez por cuenta de cPanel. Son **dos**,
porque el bucle de una cuenta no ve las carpetas de la otra — la misma repetición a mano que
ya lleva el Paso 1 de [`DESPLIEGUE.md`](DESPLIEGUE.md). La hora va en los campos de arriba
(`5 2 * * *`, o *Once Per Day* y corregir el minuto) y esto en **Command**, tal cual:

```
/home/micolev1/demo.micolevirtual.com/8myvc/tools/respaldo-diario-cpanel.sh
```

```
RAIZ=/home/micolevi/public_html/8myvc /home/micolevi/public_html/8myvc/tools/respaldo-diario-cpanel.sh
```

La primera es la cuenta `micolev1` —los dieciséis colegios más `demo`— y no lleva `RAIZ`
porque la de fábrica ya es la suya; da igual desde qué carpeta se lance, el guión las recorre
todas. La segunda es la cuenta **`micolevi`**, donde vive el LAL de verdad, y ahí sí hace falta
`RAIZ`: su instalación está en `~/public_html/8myvc` y no cumple el patrón
`*.micolevirtual.com`. *No es la carpeta `lal.micolevirtual.com` de `micolev1`* — ésa es la
copia parada del ensayo de traslado ([`TRASLADO-LAL.md`](TRASLADO-LAL.md)), y el bucle de la
primera línea ya la coge.

Antes de pegar la segunda, la comprobación de que la ruta existe de verdad —que en esta cuenta
se ha deducido mal dos veces—, y después, en **cada** cuenta, la que demuestra que quedó puesto:

```sh
ls ~/public_html/8myvc/artisan      # en `micolevi`, antes de pegar
crontab -l | grep respaldo          # en las dos, después
```

Si `crontab -l` no imprime nada, el cron **no** está puesto por mucho que la pantalla de cPanel
se haya visitado.

### Si se toca el crontab por SSH en vez de por el panel, por ficheros

**`crontab -l | … | crontab -` dejó el crontab de `micolev1` VACÍO** —los dieciséis
`schedule:run` fuera— y hubo que restaurarlo de un respaldo (23 sep 2026, sesión de
notificaciones; el procedimiento quedó en `myvc_flutter/docs/notificaciones.md`). Un pipe que
falla a media tubería escribe un crontab vacío perfectamente válido, que es el mismo patrón
que el `.gz` truncado de más arriba. Por ficheros, y contando líneas antes y después:

```sh
crontab -l > ~/cron-actual.txt          # la copia, primero
cp ~/cron-actual.txt ~/cron-nuevo.txt
cat >> ~/cron-nuevo.txt <<'FIN'
MAILTO="TU-CORREO"
5 2 * * * /home/micolev1/demo.micolevirtual.com/8myvc/tools/respaldo-diario-cpanel.sh
FIN
wc -l ~/cron-actual.txt ~/cron-nuevo.txt   # dos líneas más, ni una menos
crontab ~/cron-nuevo.txt
crontab -l | grep -c respaldo              # 1
```

En `micolevi` es igual cambiando la última línea por la suya, la que lleva `RAIZ=`. Y **antes
de poner el cron**, que el guion exista de verdad en esa cuenta —`ls -l
…/8myvc/tools/respaldo-diario-cpanel.sh`—: si el colegio no tiene desplegada la versión del 22
sep, el cron llamaría cada noche a un fichero que no está.

Tres cosas de esta pantalla que se pagan caro:

1. **Aquí NO se pone `>/dev/null 2>&1`.** En el cron de `schedule:run` sí, porque habla
   cada minuto. Éste está escrito para **callar cuando sale bien**: si imprime algo es
   porque una base no se respaldó, y ese correo de cPanel es justo el aviso que se
   quiere. Silenciarlo deja un respaldo que lleva medio año sin correr y nadie lo sabe.

   > **Y hoy ese correo NO saldría: las dos cuentas empiezan el crontab con `MAILTO=""`.**
   > Eso descarta la salida de **todas** las líneas que vengan detrás, así que el guion
   > callaría igual el día que fallara — que es exactamente lo que este punto quiere
   > evitar. La línea del respaldo va **al final del fichero y con su propio `MAILTO`
   > delante**: cron aplica el que esté vigente en ese punto, y así `schedule:run` sigue
   > mudo y el respaldo no.
   >
   > *(Leído el 23 sep 2026 de dos capturas de `crontab -l` —18 líneas en `micolev1`, 4 en
   > `micolevi`— que Joseth pegó en la sesión de notificaciones. **No comprobado por SSH**:
   > esa noche aquí sólo había acceso por contraseña.)*
2. **La hora.** A las 02:05 no hay nadie calificando y el `--single-transaction` no le
   cierra la puerta a nadie. A las 07:00 sí.
3. **La cuota.** Los respaldos ocupan disco de la misma cuenta que las bases. El guion
   guarda 7 días y avisa al pasar de 4 GB (`DIAS`, `TOPE_MB`); si la cuenta va justa,
   `DIAS=3` y bajarlos fuera más a menudo.

La bitácora queda en `~/respaldos/diario/bitacora.log` y la tanda no rota si el día no
quedó limpio: un día malo no se lleva por delante los días buenos.

**Cuánto ocupa, medido el 22 sep 2026** y no estimado: los **17** volcados de `micolev1`
—dieciséis colegios más `demo`— suman **252 MB** comprimidos. Los extremos son `quibdo` con
43 MB y `coab_saravena` con 38 MB; el más pequeño, `colbosque_tame`, 2,3 MB. Con siete días
son ~1,8 GB, que es de donde sale el `TOPE_MB=4000` de fábrica: avisa antes de que la cuota
apriete, no cuando ya apretó.

> **Y un tropiezo que costó dos intentos**: los `.env` de la cuenta de `lalvirtual` tienen fin de
> línea de Windows, así que el nombre de la base sale como `micolevi_lalvirtual\r`. MySQL contesta
> «Incorrect database name» y **el retorno de carro se come la mitad del propio mensaje** al
> imprimirlo, así que ni se lee entero. Los dos guiones lo quitan; un bucle escrito a mano en la
> terminal, no.

## 2. Lo que hay que preguntarle al proveedor — las cuatro preguntas

**En `micolev1` esto ya está contestado, y por el panel** (24 sep 2026, recuadro de §1):
copias **diarias**, **30** por base, restaurables por ti sin ticket y guardadas **fuera** del
servidor. De las cuatro preguntas quedan vivas la **4** —si cuentan contra la cuota, que con
12,0 GB de uso no es ocioso— y todas las demás **para la cuenta `micolevi`**, que es otra
máquina y otro contrato. Al proveedor sólo hay que escribirle por eso.

El texto de abajo se queda porque sirve tal cual para `micolevi`, y porque el día que cambie
el plan de alojamiento vuelven a hacer falta las cuatro:

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
sea el disco. Desde el Mac, una vez por semana, y ya no a mano —
[`tools/bajar-respaldos.sh`](../tools/bajar-respaldos.sh), escrito el 23 sep 2026:

```bash
tools/bajar-respaldos.sh                  # la última fecha que tengan LAS DOS cuentas
tools/bajar-respaldos.sh 2026-09-20       # una fecha concreta
```

Trae las dos cuentas, comprueba con `gzip -t` que nada llegó cortado y deja un solo
`myvc-bases-FECHA.tar` en `~/DESARROLLOS/respaldos-myvc/`. Los 17 volcados de `micolev1`
más el de `lal` caben en unos 300 MB, así que el tar va sin `-z`: dentro ya está todo
comprimido.

Tres decisiones del guion que son el guion:

- **Se tira, no se empuja.** Si el servidor queda comprometido, no tiene credenciales
  para llegar a tu máquina. Esto ya estaba escrito aquí y no cambia.
- **Las dos cuentas se juntan en el Mac, no en un servidor.** Están en dos máquinas
  (`mi3-ss55` y `mi3-ss54`) y los usuarios de MySQL son locales a cada una. Para que una
  volcara las bases de la otra habría que guardarle sus credenciales, y entonces quien
  entre en una cuenta entra en las dieciséis.
- **La fecha se negocia.** No baja «hoy» —a la una de la mañana el volcado de hoy no
  existe todavía— ni «la más reciente de cada cuenta», que juntaría el lunes de una con
  el jueves de la otra sin avisar: baja **la más reciente que tengan las dos**, y si no
  hay ninguna en común no baja nada y dice qué tiene cada una. Eso es un cron parado.

Si algo no cuadra, el tar se llama `…-INCOMPLETO.tar` y el guion sale con código
distinto de cero: un respaldo a medias no se confunde con uno entero seis meses después.

> **Probado el 23 sep 2026 con servidores simulados, no contra producción** — los cinco
> caminos: la conexión que no entra, el cron sin poner (mensajes distintos, porque se
> arreglan en sitios distintos), la corrida limpia, un `.gz` cortado y dos cuentas sin
> ninguna fecha en común. Contra los servidores de verdad **no se ha corrido todavía**:
> hace falta la clave, y el 23 sep sólo había acceso por contraseña.

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
