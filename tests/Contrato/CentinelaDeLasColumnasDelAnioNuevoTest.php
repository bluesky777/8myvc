<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Que `YearsController::postStore` no se deje ninguna columna de `years`.
 *
 * Crear el año siguiente es **copiar el anterior**: ese método escribe hoy 61 de
 * las 68 columnas vivas de `years`, una por una y a mano. Es la lista que
 * decide con qué configuración amanece el colegio cada enero.
 *
 * **Y nada la mantenía cerrada.** De las cuatro columnas que han entrado a
 * `years` por migración desde el volcado congelado, **dos se acordaron de la
 * lista y dos no**:
 *
 *     usa_consecutivo_certificados   sí — copiada con su contador (21 §2.3)
 *     usa_folio_certificados         sí — ídem
 *     firmantes_acta                 no — y es a propósito, ver NACEN_VACIAS
 *     puestos_con_bol_independiente  no — y era un fallo, arreglado el 31 ago 2026
 *
 * El último es el que paga este centinela. La columna nace con `DEFAULT 1`, así
 * que **el colegio que la puso a 0 la recuperaba a 1 el enero siguiente**, sin
 * que nadie tocara nada y sin un solo error en ningún log. Lo que reaparecía no
 * era un valor cualquiera: era el puesto impreso de todos los alumnos moviéndose
 * en papel firmado.
 *
 * **Y el olvido es silencioso en las dos direcciones**, que es lo que lo hace
 * caro: una columna con `DEFAULT` hace que el año nuevo nazca con una decisión
 * que nadie tomó —y con pinta de tomada—, y una sin `DEFAULT` lo hace nacer
 * vacío. Ninguna de las dos cosas rompe nada el día que pasa; se nota en enero,
 * imprimiendo.
 *
 * ## Lo que este test comprueba, y lo que NO
 *
 * Comprueba que **cada columna viva está nombrada**: o la escribe `postStore`, o
 * está en una de las dos listas de excepciones **con su motivo**. Lo que no
 * comprueba es de dónde sale el valor: una columna nueva escrita como
 * `Request::input('x')` pasa este centinela, y debe pasarlo —pedirla en el
 * cuerpo también es una decisión—, pero **no significa que se herede**. Si lo
 * que hace falta es que el año nuevo la traiga del anterior, eso se comprueba
 * mirando el resultado, no esta lista.
 *
 * ## Por qué `SHOW COLUMNS` y no `database/schema/mysql-schema.sql`
 *
 * Porque el volcado tiene **64** columnas y la tabla viva **68**, y las cuatro de
 * diferencia son **justo las que entraron por migración**, o sea justo las
 * candidatas a olvidarse. Un centinela medido contra el volcado estaría midiendo
 * donde ninguna candidata puede aparecer, y saldría verde para siempre —que es
 * la peor de las formas de fallar de las que este repo lleva contadas: la que
 * tranquiliza.
 *
 * A la manera de `CentinelaDeLosEscritoresDeBitacoraTest`, y por el mismo motivo:
 * *una lista a mano sin centinela dura hasta el siguiente que escriba.*
 */
class CentinelaDeLasColumnasDelAnioNuevoTest extends TestCase
{
    private const FICHERO = 'app/Http/Controllers/YearsController.php';

    /**
     * Las que lleva el framework, y por eso no las escribe nadie a mano.
     *
     * **Son seis y no siete: `created_by` SÍ se escribe** (`$year->created_by =
     * $user->user_id`), porque no lo pone Eloquent — es de este proyecto, y dice
     * quién creó el año.
     *
     * `id` lo pone MySQL; `created_at` y `updated_at` los pone Eloquent en cada
     * `save()`; `deleted_at`, `deleted_by` y `updated_by` son de la papelera y
     * del rastro de edición, y un año recién creado no está borrado ni editado.
     * **Copiar cualquiera de las tres del año anterior sería un error de otro
     * tipo**: heredar el borrado de un año pasado, o decir que lo editó alguien
     * que no ha entrado todavía.
     *
     * @var list<string>
     */
    private const ESTRUCTURALES = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'deleted_by',
        'updated_by',
    ];

    /**
     * Las que un colegio QUIERE que nazcan vacías, con el porqué de cada una.
     *
     * **Sin el porqué escrito, un centinela se convierte en un `@ignore` que
     * nadie vuelve a mirar** — y ése, y no el falso positivo, es el modo de fallo
     * que hay que evitar aquí: una lista de nombres a secas hace que la salida
     * barata ante un rojo sea añadir la columna a esta constante, que es
     * exactamente lo contrario de lo que el test existe para forzar.
     *
     * Añadir una entrada aquí **es tomar una decisión sobre el colegio**, no
     * silenciar un test. Si no se sabe el motivo, la columna no va aquí: va
     * copiada.
     *
     * @var array<string, string>
     */
    private const NACEN_VACIAS = [
        // Decisión de Joseth, 31 ago 2026, y no se re-litiga: los firmantes se
        // confirman cada año a propósito. **Un acta firmada por quien ya no está
        // es peor que un acta sin firmantes** — el hueco se ve la primera vez que
        // alguien imprime, y la firma de más no la ve nadie hasta que importa.
        'firmantes_acta' => 'los firmantes se confirman cada año a propósito (Joseth, 31 ago 2026)',
    ];

    #[Test]
    public function ninguna_columna_de_years_se_queda_sin_copiar_al_crear_el_anio(): void
    {
        $vivas = $this->columnasVivas();
        $escritas = $this->columnasQueEscribePostStore();

        // La población, antes que el veredicto: un «0 sin copiar» no distingue
        // «las 68 están decididas» de «no leí ninguna columna» (CLAUDE.md).
        $this->assertGreaterThan(60, count($vivas),
            'Sólo se han leído '.count($vivas)." columnas de `years`, y son 68.\n".
            'Esto no es un aprobado: es que `SHOW COLUMNS` no ha contestado lo que se cree.');
        $this->assertGreaterThan(50, count($escritas),
            'Sólo se han encontrado '.count($escritas)." asignaciones en postStore, y son 61.\n".
            "El bloque que las lleva ALINEA CON TABULADORES: un patrón que sólo entienda\n".
            'espacios se come nueve líneas y da 50. El sitio donde mirar es este test.');

        $decididas = array_merge($escritas, self::ESTRUCTURALES, array_keys(self::NACEN_VACIAS));
        $huerfanas = array_values(array_diff($vivas, $decididas));

        $this->assertSame([], $huerfanas,
            "`YearsController::postStore` no dice nada de estas columnas de `years`:\n\n".
            '    '.implode("\n    ", $huerfanas)."\n\n".
            "No es un fallo del test: es una decisión sin tomar, y el año nuevo la está\n".
            "tomando solo. Una columna con DEFAULT hace que enero amanezca con un valor\n".
            "que nadie eligió y con pinta de elegido —así resucitaba a 1\n".
            "`puestos_con_bol_independiente`, moviendo el puesto impreso de todo el grupo—;\n".
            "una sin DEFAULT hace que nazca vacía. Las dos en silencio.\n\n".
            "Hay DOS salidas, y las dos son escribir:\n".
            "  1. copiarla del año anterior en postStore, junto a sus vecinas de sentido;\n".
            "  2. o meterla en NACEN_VACIAS **con el motivo**, si el colegio la quiere vacía.\n\n".
            'Población: '.count($vivas).' columnas vivas, '.count($escritas).' escritas por postStore.');
    }

    /**
     * Y la dirección contraria, que es la que convierte la lista en un `@ignore`.
     *
     * Una excepción que ya no hace falta —porque la columna se copia, o porque ya
     * no existe— **no da ningún error por sí sola**: simplemente se queda ahí, y
     * el siguiente que lea la lista la da por vigente. Es el mismo argumento por
     * el que `AutopruebasDeLasHerramientasTest` no deja apuntar una herramienta
     * como «no concluyente» y olvidarla.
     */
    #[Test]
    public function ninguna_excepcion_sobra(): void
    {
        $vivas = $this->columnasVivas();
        $escritas = $this->columnasQueEscribePostStore();

        foreach (array_merge(self::ESTRUCTURALES, array_keys(self::NACEN_VACIAS)) as $excepcion) {
            $this->assertContains($excepcion, $vivas,
                "`{$excepcion}` está excusada de copiarse y **ya no es una columna de `years`**.\n".
                'Sobra de la lista: una excepción a algo que no existe se lee como vigente.');

            $this->assertNotContains($excepcion, $escritas,
                "`{$excepcion}` está en la lista de excepciones y postStore **sí la escribe**.\n\n".
                "Una de las dos cosas está mal, y la que hay que mirar primero es la lista:\n".
                "si la columna se copia, su excepción sobra y hay que borrarla. Dejarla es\n".
                'cómo una lista de excepciones deja de decir la verdad sin que nada falle.');
        }
    }

    /** @return list<string> las columnas vivas, de la base y no del volcado. */
    private function columnasVivas(): array
    {
        return array_values(array_map(
            static fn (object $c): string => $c->Field,
            DB::select('SHOW COLUMNS FROM years')
        ));
    }

    /**
     * Las columnas que `postStore` deja escritas, leídas del fuente.
     *
     * Dos cuidados, los dos pagados al medirlo:
     *
     * 1. **El bloque alinea con tabuladores.** `\s*` y no ` *`: con espacios se
     *    pierden nueve líneas y salen 50 en vez de 61, que es una cifra bastante
     *    creíble como para no mirarla.
     * 2. **No todo `$year->x` es una columna.** `postStore` cuelga además
     *    `periodos` y `grupos_ant` del objeto para armar la respuesta. No se
     *    filtran por nombre: se cruzan con `SHOW COLUMNS`, que es la única lista
     *    de columnas que hay.
     *
     * @return list<string>
     */
    private function columnasQueEscribePostStore(): array
    {
        $fuente = file_get_contents(dirname(__DIR__, 2).'/'.self::FICHERO);
        $this->assertIsString($fuente, 'No se pudo leer '.self::FICHERO);

        $desde = strpos($fuente, 'function postStore');
        $this->assertNotFalse($desde,
            'No hay ningún `function postStore` en '.self::FICHERO.".\n".
            'Si se renombró, este centinela dejó de vigilar nada — y no lo diría solo.');

        $hasta = strpos($fuente, 'public function ', $desde + 20);
        $cuerpo = substr($fuente, $desde, $hasta === false ? null : $hasta - $desde);

        preg_match_all('/\$year->(\w+)\s*=(?!=)/', $cuerpo, $m);

        $vivas = $this->columnasVivas();

        return array_values(array_unique(array_filter(
            $m[1],
            static fn (string $p): bool => in_array($p, $vivas, true)
        )));
    }
}
