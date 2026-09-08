<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Que el bucle que copia los grupos al año nuevo no se deje ninguna columna.
 *
 * El hermano de `CentinelaDeLasColumnasDelAnioNuevoTest`, una tabla más abajo y por
 * el mismo motivo: dentro del **mismo** `YearsController::postStore` hay un segundo
 * traspaso escrito a mano, columna a columna, y ése no lo vigilaba nada.
 *
 *     foreach ($grupos_ant as $grupo) { $newGr = new Grupo; $newGr->nombre = ...
 *
 * **Y ya se había dejado dos, con siete días de diferencia.** El 30 ago 2026 se
 * descubrió que la asignatura copiada perdía a su docente —`profesor_id`, que sí
 * copiaba `POST asignaturas/copiar` de grupo a grupo— y el 7 sep 2026 que el grupo
 * copiado perdía `grupos.ih`, la intensidad horaria, **el mismo día que la columna
 * entró**. Ninguna de las dos la encontró nadie usando el sistema: las dos las
 * encontró alguien que fue a mirar el bucle por otra cosa.
 *
 * ## Por qué este bucle olvida en silencio, y peor que el de `years`
 *
 * Porque **sólo corre una vez al año y por colegio**, en enero. Una columna que se
 * quede fuera no da un error, no cambia ninguna respuesta y no se nota al
 * desplegar: se nota —si se nota— el enero siguiente, con el año ya creado, y para
 * entonces lo que falta hay que teclearlo a mano en los ~200 grupos vivos de cada
 * una de las dieciséis bases. Con `ih`, además, lo que se apagaba era **un aviso**:
 * un descuadre entre Σ `asignaturas.creditos` y la IH del grupo deja de avisar sin
 * decir que ha dejado de avisar, que es la peor de las formas de fallar que este
 * repo lleva contadas — la que tranquiliza.
 *
 * ## Lo que comprueba y lo que no
 *
 * Igual que el de `years`: que **cada columna viva esté nombrada** —o la escribe el
 * bucle, o está excusada aquí con su motivo—, no de dónde sale el valor. Que una
 * columna se copie no dice que se copie **bien**; eso se mira desde el resultado, y
 * de `ih` lo mira `YearsTest::test_el_grupo_del_ano_nuevo_hereda_su_intensidad_horaria`.
 *
 * **No cubre las asignaturas.** El bucle de dentro copia seis de las veinte columnas
 * de `asignaturas`, y siete de las catorce restantes son las de día
 * —`lunes`…`domingo`, el horario viejo por asignatura que audita
 * `tools/deriva-del-horario.php`—: decidir una por una si se heredan **es una
 * decisión sobre el horario del colegio**, no un centinela, y no se toma de paso en
 * un fichero de tests. Queda escrito aquí para que se sepa que falta, que es la
 * mitad que este repo suele perder.
 */
class CentinelaDeLasColumnasDelGrupoCopiadoTest extends TestCase
{
    private const FICHERO = 'app/Http/Controllers/YearsController.php';

    /**
     * Las que no escribe nadie a mano porque las pone otro.
     *
     * `id` lo pone MySQL; `created_at` y `updated_at` los pone Eloquent en el
     * `save()`. `deleted_at`, `deleted_by` y `updated_by` son la papelera y el rastro
     * de edición: **copiarlas sería un error de otro tipo** —un grupo recién creado
     * que nace borrado, o que dice haberlo editado alguien que todavía no ha entrado
     * al año nuevo.
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
     * Las que el grupo nuevo NO hereda, con el porqué de cada una.
     *
     * **Sin el motivo escrito esto es un `@ignore`**, y entonces la salida barata
     * ante un rojo es añadir aquí la columna que se acaba de olvidar — que es
     * exactamente lo contrario de lo que el centinela existe para forzar. Si no se
     * sabe el motivo, la columna no va aquí: va copiada.
     *
     * @var array<string, string>
     */
    private const NACEN_VACIAS = [
        // Decisión de Joseth, 30 ago 2026, y no se re-litiga. El listado de grupos
        // hace `left join profesores p on p.id=g.titular_id` **sin pasar por
        // `contratos`**, así que un titular copiado no sale en blanco: sale con
        // nombre y apellidos, como si estuviera en la planta del año nuevo. **Un dato
        // que se ve y parece cierto no es un borrador pendiente.** Es al revés que el
        // docente de la asignatura, en el bucle de dentro, que sí se copia — y por
        // ese mismo contraste: aquél sale en blanco hasta que se le hace el contrato,
        // y entonces aparece solo.
        'titular_id' => 'copiado saldría con nombre en la planta del año nuevo, sin contrato (Joseth, 30 ago 2026)',

        // Y ésta no es una decisión de este bucle: es un hueco de la tabla entera.
        // **Ningún sitio de esta API escribe `grupos.created_by`** —medido el 7 sep
        // 2026 sobre los tres únicos `new Grupo` que hay: `GruposController::postStore`,
        // `Perfiles\PerfilesController::postStore` y éste—, y en el seed la tienen a
        // NULL los 2 de 2 grupos vivos. Heredarla sería falso de una forma nueva: diría
        // que el grupo de este año lo creó quien creó el del año pasado. Escribir el
        // usuario actual sí sería correcto, pero eso es tapar el hueco **por un tercio**
        // y dejar sin autor a los grupos creados a mano, que son los demás.
        'created_by' => 'no la escribe ningún sitio de la API (3 de 3 `new Grupo`, medido); heredarla diría que lo creó quien creó el del año pasado',
    ];

    #[Test]
    public function ninguna_columna_de_grupos_se_queda_sin_copiar_al_crear_el_anio(): void
    {
        $vivas = $this->columnasVivas();
        $escritas = $this->columnasQueCopiaElBucle();

        // La población antes que el veredicto: un «0 sin copiar» no distingue «las 18
        // están decididas» de «no leí ninguna columna» (CLAUDE.md).
        $this->assertGreaterThan(15, count($vivas),
            'Sólo se han leído '.count($vivas)." columnas de `grupos`, y son 18.\n".
            'Esto no es un aprobado: es que `SHOW COLUMNS` no ha contestado lo que se cree.');
        $this->assertGreaterThan(8, count($escritas),
            'Sólo se han encontrado '.count($escritas)." asignaciones a `\$newGr` en postStore, y son 10.\n".
            "El bloque ALINEA CON TABULADORES y la variable se llama `\$newGr`: si se renombró,\n".
            'este centinela dejó de vigilar nada y no lo diría solo. El sitio donde mirar es este test.');

        $decididas = array_merge($escritas, self::ESTRUCTURALES, array_keys(self::NACEN_VACIAS));
        $huerfanas = array_values(array_diff($vivas, $decididas));

        $this->assertSame([], $huerfanas,
            "El bucle que copia los grupos al año nuevo no dice nada de estas columnas de `grupos`:\n\n".
            '    '.implode("\n    ", $huerfanas)."\n\n".
            "No es un fallo del test: es una decisión sin tomar, y el año nuevo la está tomando\n".
            "solo. Este bucle corre **una vez al año y por colegio**, así que lo que se quede\n".
            "fuera no rompe nada hoy: aparece el enero siguiente, con el año ya creado, y se\n".
            "arregla a mano en los ~200 grupos de cada una de las dieciséis bases.\n\n".
            "Hay DOS salidas, y las dos son escribir:\n".
            "  1. copiarla en el bucle, junto a sus vecinas de sentido;\n".
            "  2. o meterla en NACEN_VACIAS **con el motivo**, si el grupo nuevo la quiere vacía.\n\n".
            'Población: '.count($vivas).' columnas vivas, '.count($escritas).' copiadas por el bucle.');
    }

    /**
     * Y la dirección contraria, que es la que convierte la lista en un `@ignore`.
     *
     * Una excepción que ya no hace falta —porque la columna se copia, o porque ya no
     * existe— **no da ningún error por sí sola**: se queda ahí, y el siguiente que lea
     * la lista la da por vigente.
     */
    #[Test]
    public function ninguna_excepcion_sobra(): void
    {
        $vivas = $this->columnasVivas();
        $escritas = $this->columnasQueCopiaElBucle();

        foreach (array_merge(self::ESTRUCTURALES, array_keys(self::NACEN_VACIAS)) as $excepcion) {
            $this->assertContains($excepcion, $vivas,
                "`{$excepcion}` está excusada de copiarse y **ya no es una columna de `grupos`**.\n".
                'Sobra de la lista: una excepción a algo que no existe se lee como vigente.');

            $this->assertNotContains($excepcion, $escritas,
                "`{$excepcion}` está en la lista de excepciones y el bucle **sí la copia**.\n\n".
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
            DB::select('SHOW COLUMNS FROM grupos')
        ));
    }

    /**
     * Las columnas que el bucle deja escritas en el grupo nuevo, leídas del fuente.
     *
     * Se cruzan con `SHOW COLUMNS` y no se filtran por nombre, que es lo mismo que
     * hace el centinela de `years` y por el mismo motivo: **no todo `$newGr->x` tiene
     * que ser una columna** —el código de este proyecto cuelga atributos de los
     * modelos para armar respuestas—, y la única lista de columnas que hay es la de
     * la base.
     *
     * El volcado congelado no sirve para esto: tiene 17 columnas de `grupos` y la
     * tabla viva 18, y la de diferencia es **justo `ih`**, o sea justo la clase de
     * columna que este centinela existe para cazar. Medir contra el volcado sería
     * medir donde ninguna candidata puede aparecer.
     *
     * @return list<string>
     */
    private function columnasQueCopiaElBucle(): array
    {
        $fuente = file_get_contents(dirname(__DIR__, 2).'/'.self::FICHERO);
        $this->assertIsString($fuente, 'No se pudo leer '.self::FICHERO);

        $desde = strpos($fuente, 'function postStore');
        $this->assertNotFalse($desde,
            'No hay ningún `function postStore` en '.self::FICHERO.".\n".
            'Si se renombró, este centinela dejó de vigilar nada — y no lo diría solo.');

        $hasta = strpos($fuente, 'public function ', $desde + 20);
        $cuerpo = substr($fuente, $desde, $hasta === false ? null : $hasta - $desde);

        // **Los comentarios se quitan ANTES de contar, y esto está medido.** La primera
        // versión de este centinela contaba texto y no código: comentando la línea de
        // `ih` —`// $newGr->ih = $grupo->ih;`— el test seguía en verde, porque la
        // asignación seguía escrita. Lo destapó mutar el controlador para comprobar que
        // el centinela sabía ponerse rojo, que es lo único que distingue un test que
        // vigila de uno que acompaña.
        //
        // El filtro se puede pasar de listo —una cadena con `//` dentro y una asignación
        // detrás en la misma línea—, y esa dirección es la inocua: se perdería una
        // columna, saldría como huérfana y el test se pondría **rojo**, que hace que
        // alguien mire. La dirección peligrosa es la contraria, y es la que se cierra.
        $cuerpo = (string) preg_replace('~//[^\n]*~', '', $cuerpo);
        $cuerpo = (string) preg_replace('~/\*.*?\*/~s', '', $cuerpo);

        preg_match_all('/\$newGr->(\w+)\s*=(?!=)/', $cuerpo, $m);

        $vivas = $this->columnasVivas();

        return array_values(array_unique(array_filter(
            $m[1],
            static fn (string $p): bool => in_array($p, $vivas, true)
        )));
    }
}
