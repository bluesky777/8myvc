<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Si el boletín imprime el número además del texto del desempeño.
 *
 * Encargo de Joseth (17 sep 2026) por la sesión de `myvc_front`: hoy eso es un
 * «boletín tipo 5» —*el tipo 1 pero sin números*— que se elige **al imprimir**, y
 * pasa a ser **configuración del año**, porque es una decisión del SIEE del
 * colegio y no algo que se decida cada vez que alguien saca un papel.
 *
 * La semántica es la que pidió, y va escrita aquí porque el nombre sólo dice la
 * mitad:
 *
 *     apagado  ->  sólo el texto del desempeño
 *     encendido -> el número Y el desempeño
 *
 * ## `DEFAULT 1`, y la mitad que el default NO protege
 *
 * Nace encendida porque es lo que los dieciséis colegios imprimen hoy: la
 * migración no puede cambiarle el boletín a ninguna familia el día que corre.
 *
 * El front la lee con la regla de *«sin campo, encendido»*, que es la misma de
 * `modelo_evaluacion`. **Pero esa regla protege al cliente y no al servidor**, y
 * conviene no confundirlas: `Year::datos` nombra las columnas **una a una**, así
 * que en un colegio donde esta migración no haya corrido, nombrarla allí no
 * devuelve un campo ausente — devuelve `Unknown column` y **500 en todo lo que
 * pida el año**. O sea que esto tiene que llegar a los dieciséis **antes o con**
 * el código que la nombra. (Le pasó a otra sesión el 17 sep con
 * `frases_asignatura`.)
 *
 * > **Aquí decía que «es un rojo que este docker no puede enseñar porque aquí la
 * > migración sí habrá corrido», y es FALSO — corregido el 18 sep 2026, el mismo
 * > día, porque se desmintió solo en unas horas.** Otra sesión estableció línea base
 * > con `git stash` y encontró **dos rojos que no eran suyos** —dos casos de
 * > boletines en `AutorizacionTest`— con
 * > `Unknown column 'y.mostrar_nota_numerica_boletin'` desde `Year::datos`: la base
 * > de tests compartida no tenía esta migración. **En este docker hay veinticuatro
 * > bases de test**, y ese día **22 seguían sin la columna**, porque quien migra
 * > migra la de desarrollo y la suya.
 * >
 * > Lo cierto es más estrecho: **la base de DESARROLLO no lo enseña**, porque está
 * > migrada; cualquier otra que no lo esté, sí. Y el modo de fallo es el peor que
 * > puede tener — **dos rojos que parecen del trabajo de otra sesión**, en ficheros
 * > que esa sesión no ha tocado.
 * >
 * > La frase hacía daño por la parte tranquilizadora: **le decía al lector que no
 * > buscara el fallo en local**, que es justo cuando lo tiene delante. Migrar la base
 * > de desarrollo **no termina** una migración como ésta: hay que migrar también la
 * > base de tests que se vaya a usar y **avisar a las sesiones vivas** — las suyas no
 * > se tocan sin decírselo.
 *
 * ## El vecino con el que se va a confundir
 *
 * `years.solo_escalas_valorativas` ya existe y **también vacía un número**, pero
 * sólo en la **cabecera de comportamiento** (`Informes/BoletinesController:648` y
 * `Boletines2Controller:602`, los dos únicos que la leen) y con la **polaridad
 * invertida**. O sea que el colegio va a tener dos interruptores que se leen como
 * el mismo: uno dice «sólo escalas» y el otro «muestra el número».
 *
 * **No se unifican aquí, y no por pereza**: los colegios que hoy tienen
 * `solo_escalas_valorativas` encendida imprimen así desde hace años, y
 * absorberla en ésta les cambiaría el papel sin que nadie lo pidiera. Lo que sí
 * se hace es que la pantalla de configuración los enseñe **juntos y con el
 * alcance escrito al lado de cada uno** — acordado con `myvc_front`. Separados en
 * dos pestañas, el colegio enciende uno esperando lo del otro.
 *
 * ## El sufijo `_boletin` no es decoración
 *
 * Sus dos vecinas son `mostrar_puesto_boletin` y `mostrar_nota_comport_boletin`.
 * Con el sufijo, el alcance se lee en el nombre y no se confunde con el de
 * comportamiento — que es exactamente la confusión de la que va el apartado de
 * arriba.
 *
 * ## Quién la escribe
 *
 * **No cualquiera del personal**, y eso NO lo decide esta migración: lo decide que
 * la columna entre en la lista `$conDueno` de `YearsController::putToggleCambiarValor`
 * —porque esa ruta escribe cualquier columna de `years` que exista, con sólo
 * `auth.personal`— y que su ruta propia pregunte por
 * `Autoriza::puedeCambiarLaNotaNumerica()`. Decisión de Joseth del 17 sep 2026:
 * **superusuario, Secretario, Coord académico y Rector**. Medido ese día en la
 * copia de desarrollo: son **12** personas frente a las **74** del personal
 * entero.
 *
 * ## Sin `after`, y volver atrás
 *
 * Igual que `peso_del_area`: `AFTER x` ata la migración al orden de su tanda.
 * Aditiva pura — `down()` quita la columna y no pierde ninguna nota; lo único que
 * se pierde es la elección del colegio, que con el código viejo no lee nadie.
 */
class NotaNumericaEnElBoletin extends Migration
{
    public function up()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->boolean('mostrar_nota_numerica_boletin')->default(true);
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('mostrar_nota_numerica_boletin');
        });
    }
}
