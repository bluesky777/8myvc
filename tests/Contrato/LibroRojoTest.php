<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **El libro rojo: la única escritura de `dis_libro_rojo` en toda la API, y no
 * mira de quién es la fila.** §91.
 *
 * `PUT api/nota_comportamiento/guardar-libro` recibe `{campo, valor, libro_id}` y
 * hace `UPDATE dis_libro_rojo SET <campo>=:valor WHERE id=:libro_id`. El nombre
 * de columna está a salvo desde la §31 —lo valida `ColumnaSegura::exigir` contra
 * el esquema, y eso lo fija `ColumnaConcatenadaTest`—. Lo que no mira nadie es
 * **`libro_id`**.
 *
 * `dis_libro_rojo` es el observador disciplinario del alumno: doce columnas de
 * texto, tres por periodo (`per1_col1` … `per4_col3`), más su fecha. Una fila por
 * alumno y año.
 *
 * ## Es la única puerta, y eso corta las dos maneras
 *
 * `dis_libro_rojo` se nombra en tres controladores —`ChangeAskedController`,
 * `Disciplina\ComportamientoController` y éste— y **sólo aquí se escribe**: los
 * otros dos leen la fila o la crean vacía. O sea que no hay hermana de al lado
 * con criterio de la que copiar el guard, que es lo que resolvió la §89 en una
 * línea. Aquí **no hay criterio elegido todavía**, y por eso esta sección mide y
 * fija en vez de cerrar.
 *
 * ## Por qué el detector no lo señalaba
 *
 * `tools/identificadores-del-cuerpo.py` da esta ruta como **«prop = sí»**, o sea
 * comprobada. Su señal de propiedad es la raíz `exig` —ensanchada a propósito
 * para cazar los helpers privados que el repo conjuga `exigirQue…` y `exigeQue…`—
 * y en este método el único `exig` es **`ColumnaSegura::exigir`**, que no
 * comprueba propiedad de nada: valida un nombre de columna.
 *
 * Son cinco rutas de escritura las que se cuelan por ahí, medidas quitando esa
 * llamada del texto y volviendo a pasar la misma regex: las dos de
 * `ordinales/guardar-valor*`, `years/toggle-cambiar-valor`,
 * `asignaturas/toggle-dia` y ésta. **Ensanchar una señal para no perder
 * verdaderos positivos mete falsos negativos por el otro lado**, y los de un
 * detector de propiedad no se ven nunca: la ruta sale de la lista y ya está.
 *
 * Ver docs/migracion/noche-2026-08-23/c.md §91.
 */
class LibroRojoTest extends CasoDeContrato
{
    /**
     * Un profesor y **un alumno de ningún grupo suyo**, con su libro rojo creado
     * aquí mismo.
     *
     * El seed no trae ninguna fila de `dis_libro_rojo` —las crea la pantalla al
     * abrirse, en `getDetailed`—, así que se monta una y la deshace la transacción
     * del test. Buscar «un alumno que no sea suyo» con un `!=` es la trampa que ya
     * ha costado cuatro veces lo mismo (ver `grupoAjenoDelMismoAnio`); aquí se pide
     * al revés, exigiendo que **ninguno** de sus grupos tenga asignatura de este
     * profesor.
     */
    private function escenario(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $profesorId = DB::selectOne('SELECT id FROM profesores WHERE user_id = ? AND deleted_at IS NULL',
            [$prof->id])->id;

        $alumno = DB::selectOne('SELECT a.id, g.year_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
              AND NOT EXISTS (
                SELECT 1 FROM matriculas m2
                INNER JOIN asignaturas asi ON asi.grupo_id = m2.grupo_id AND asi.deleted_at IS NULL
                WHERE m2.alumno_id = a.id AND m2.deleted_at IS NULL AND asi.profesor_id = ?
              )
            ORDER BY a.id LIMIT 1', [$profesorId]);

        $this->assertNotNull($alumno,
            'El seed no tiene ningún alumno fuera de los grupos de este profesor: sin eso «ajeno» no significa nada.');

        $libroId = DB::table('dis_libro_rojo')->insertGetId([
            'alumno_id' => $alumno->id,
            'year_id' => $alumno->year_id,
            'per1_col1' => 'lo que escribió el titular',
        ]);

        return (object) [
            'token' => $token,
            'profesor_id' => $profesorId,
            'alumno_id' => $alumno->id,
            'year_id' => $alumno->year_id,
            'libro_id' => $libroId,
        ];
    }

    private function columna(int $libroId, string $campo)
    {
        return DB::table('dis_libro_rojo')->where('id', $libroId)->value($campo);
    }

    /**
     * **Un profesor escribe el observador disciplinario de un alumno que no es de
     * ninguno de sus grupos**, y contesta 200.
     *
     * Se fija lo que hay **sin juzgarlo**, y el porqué de que no se cierre aquí:
     * quién puede escribir el libro rojo de quién es una decisión del colegio, de
     * la misma familia que las 44 rutas de escritura de configuración que llevan
     * sólo `auth.personal` y que Joseth decidió **no cerrar todavía**
     * ([15 §5, lote D](../../docs/migracion/15-la-noche-en-paralelo.md)) porque
     * cerrarlas dejaría fuera a un coordinador que hoy hace ese trabajo sin tener
     * el rol. Aquí pasa igual: el coordinador de convivencia no es el titular del
     * grupo.
     *
     * La diferencia con la §89 —donde sí se cerró en el acto— es que allí **el
     * criterio ya estaba elegido por tres hermanas** y aquí no hay ninguna: esta
     * es la única escritura de la tabla.
     */
    public function test_un_profesor_escribe_el_libro_rojo_de_un_alumno_ajeno(): void
    {
        $e = $this->escenario();

        $this->withToken($e->token)->putJson('/api/nota_comportamiento/guardar-libro', [
            'campo' => 'per1_col1',
            'valor' => 'lo escribió un profesor de otro grupo',
            'libro_id' => $e->libro_id,
        ])->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame('lo escribió un profesor de otro grupo',
            $this->columna($e->libro_id, 'per1_col1'),
            'Si esto deja de escribirse es que alguien decidió quién puede tocar el libro rojo: anótese la decisión.');
    }

    /**
     * **Contesta `Cambiado` con un `libro_id` que no existe**, sin haber cambiado
     * nada.
     *
     * El método devuelve la cadena fija pase lo que pase; `DB::update` sí devuelve
     * el número de filas y **no se mira**. Es la familia de la
     * [§74](../../docs/migracion/05-codigo-muerto-y-roto.md) —la respuesta que dice
     * que sí— y aquí importa más de lo normal porque la pantalla guarda campo a
     * campo mientras se escribe: un `libro_id` viejo en el navegador da un
     * observador que parece guardado y no lo está.
     *
     * **Su vecina de la misma forma sí lo mira**: `years/toggle-cambiar-valor`,
     * copia del mismo patrón, hace `$res = DB::update(...)` y contesta `Guardado`
     * o `No guardado`. O sea que el dato está a mano y aquí se tira.
     */
    public function test_contesta_cambiado_con_un_libro_que_no_existe(): void
    {
        $e = $this->escenario();

        $inexistente = ((int) DB::table('dis_libro_rojo')->max('id')) + 1000;

        $this->withToken($e->token)->putJson('/api/nota_comportamiento/guardar-libro', [
            'campo' => 'per1_col1',
            'valor' => 'a ninguna parte',
            'libro_id' => $inexistente,
        ])->assertStatus(200)->assertSee('Cambiado');

        $this->assertSame(0, DB::table('dis_libro_rojo')->where('id', $inexistente)->count(),
            'Se creó la fila: entonces ya no es un UPDATE que no encuentra nada.');
    }

    /**
     * **Con el periodo cerrado escribe igual**, que es la pregunta del lote C.
     *
     * Las columnas del libro rojo son **por periodo** —`per1_col1` … `per4_col3`—,
     * así que la pregunta tiene sentido: esto es escribir en el periodo 1 con el
     * periodo 1 cerrado. Y el método no llama a `pueden_editar_notas` ni a nada
     * parecido; es una de las tres rutas de `NotaComportamientoController` que no
     * lo hacen, frente a las cinco que sí.
     *
     * **No se cierra**, y el motivo no es el mismo que el de arriba: el interruptor
     * se llama `profes_pueden_editar_notas` y lo que decidió Joseth el 21 ago 2026
     * al meter `nota_comportamiento` dentro del candado fue que **la nota de
     * comportamiento** sale en el boletín ([05 §40.2](../../docs/migracion/05-codigo-muerto-y-roto.md)).
     * El libro rojo **no sale en el boletín**: no lo lee ningún controlador de
     * `Informes/`. O sea que meterlo bajo ese interruptor sería ampliar lo que el
     * interruptor significa, y eso no se decide de noche.
     */
    public function test_con_el_periodo_cerrado_el_libro_rojo_se_escribe_igual(): void
    {
        $e = $this->escenario();

        DB::table('periodos')->where('year_id', $e->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $this->withToken($e->token)->putJson('/api/nota_comportamiento/guardar-libro', [
            'campo' => 'per1_col1',
            'valor' => 'escrito con el periodo 1 cerrado',
            'libro_id' => $e->libro_id,
        ])->assertStatus(200);

        $this->assertSame('escrito con el periodo 1 cerrado',
            $this->columna($e->libro_id, 'per1_col1'),
            'Si esto deja de escribirse es que el libro rojo entró bajo el candado del periodo: anótese la decisión y su porqué.');
    }

    /**
     * **Sigue siendo la única puerta**, leído del código y no de una lista a mano.
     *
     * Es lo que sostiene todo lo anterior: si mañana aparece un segundo sitio que
     * escriba en `dis_libro_rojo`, esta sección se queda corta sin que falle nada
     * —que es exactamente cómo la §72 se cerró sobre tres de cuatro—. Se lee el
     * código, se descartan comentarios y se cuenta, que es el arreglo que la §72.5
     * le hizo a su propio detector después de que contara un docblock.
     */
    public function test_no_hay_otra_escritura_de_dis_libro_rojo(): void
    {
        $escritores = [];

        $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($ficheros as $fichero) {
            if ($fichero->isDir() || $fichero->getExtension() !== 'php') {
                continue;
            }

            $codigo = file_get_contents($fichero->getPathname());

            // Sin comentarios: un docblock que hable de la tabla no es una escritura.
            $codigo = implode('', array_map(
                fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($t) ? $t[1] : $t),
                token_get_all($codigo)
            ));

            // **Todas** las coincidencias del fichero, no la primera: este mismo
            // controlador tiene un INSERT y el UPDATE de esta sección, y quedarse
            // con una escondería la otra — que es el fallo que persigue el caso.
            if (preg_match_all('/(UPDATE|INSERT INTO|DELETE FROM)\s+dis_libro_rojo/i', $codigo, $m)) {
                $operaciones = array_values(array_unique(array_map('strtoupper', $m[1])));
                sort($operaciones);
                $escritores[str_replace(app_path().'/', '', $fichero->getPathname())] = $operaciones;
            }
        }

        ksort($escritores);

        $this->assertSame(
            [
                // La crea vacía al abrir la pantalla de comportamiento del grupo, dos veces.
                'Http/Controllers/Disciplina/ComportamientoController.php' => ['INSERT INTO'],
                // La crea igual en la pantalla de notas de comportamiento, y hace el
                // UPDATE de esta sección: la única escritura de contenido que hay.
                'Http/Controllers/NotaComportamientoController.php' => ['INSERT INTO', 'UPDATE'],
            ],
            $escritores,
            'Cambió quién escribe en el libro rojo. Si hay un sitio nuevo, la §91 se queda corta: léelo antes de tocar el test.'
        );
    }
}
