<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Borrar una banda de la escala **degradaba boletines ya impresos en silencio**.
 *
 * El papel no guarda la palabra del nivel: la busca cada vez que se imprime,
 * cruzando la definitiva contra la escala viva del año
 * (`Grupo::detailed_materias_notafinal` y siete consultas más, todas con
 * `deleted_at is null`). Quitada la banda, la nota no cae en ninguna, el `LEFT
 * JOIN` devuelve `NULL` y el renglón sale **con la nota y sin la palabra**. Sin
 * error, sin línea en el log y sin ninguna pantalla donde se vea antes de que
 * salga el papel — el modo de fallo caro de esta casa.
 *
 * Medido el 14 sep 2026 en la copia de desarrollo de `simonbolivar`: la banda
 * BÁSICO del año en curso la usan **1.620 definitivas**; la del año 7, **8.354**.
 * Y **nadie ha borrado una banda nunca**: 36 vivas y **cero** con `deleted_at` en
 * los nueve años.
 *
 * ## La decisión: avisar y dejar pasar, no prohibir
 *
 * De Joseth, 14 sep 2026, con las tres salidas delante —prohibirlo cuando hay
 * filas que dependen, avisar con la población como `acepto_desviacion`, o
 * aceptarlo y pintarlo bien en el front—. Eligió la segunda, **y su motivo es lo
 * que hace que la primera fuera peor**: *«igual pueden crear un nuevo listón que
 * reemplace el que eliminaron»*. O sea que reestructurar la escala de un año es
 * una operación legítima, y un 403 dejaría al colegio sin forma de hacerla.
 *
 * Es la forma de `PlantillaNotasController::exigirRepartosCompletos`: un aviso que
 * se puede aceptar convierte un efecto invisible en una decisión **sin quitarle al
 * colegio nada de lo que ya podía hacer**.
 *
 * ## La población, y va acotada al año de la banda
 *
 * **`definitivas`** — lo que pierde la palabra en los boletines. Viaja porque quien
 * acepta tiene que ver a cuántos alcanza, y va **acotada al año de la banda**: sin
 * el `INNER JOIN periodos` la cuenta suma los nueve años y diría 14.054 donde el
 * daño real son 4.178. *Una cifra que exagera se aprende a ignorar, y entonces el
 * aviso deja de avisar.*
 *
 * > **Eran DOS poblaciones hasta el 17 sep 2026** —`celdas`, las casillas de la
 * > rejilla de desempeños que apuntaban a la banda por
 * > `frases_asignatura.escala_id`—, y se fueron con la rejilla entera (modelo
 * > plano, [39](../../docs/migracion/39-el-modelo-plano-por-competencias.md)). Con
 * > ella se fue `test_borrar_la_banda_no_toca_las_celdas_que_la_apuntaban`, que
 * > defendía que el `nivel` congelado sobrevivía al borrado: **ya no hay nivel
 * > congelado**, el boletín por competencias lo deriva de la definitiva como el
 * > papel de siempre, así que el daño que esa columna medía es ahora el mismo que
 * > mide `definitivas`.
 */
class BorrarUnaBandaDeLaEscalaTest extends CasoDeContrato
{
    /**
     * Una banda que arrastra definitivas: **422 y sigue viva**.
     *
     * Se comprueba la fila de después y no sólo el código. Un 422 con la banda ya
     * en la papelera sería lo peor de los dos mundos y se ve igual mirando el
     * status.
     */
    public function test_borrar_una_banda_con_definitivas_avisa_y_no_borra(): void
    {
        $banda = $this->unaBandaConDefinitivas();
        $token = $this->tokenDelPersonalLlano();

        $r = $this->withToken($token)->json('DELETE', '/api/escalas/destroy/'.$banda->id);

        $r->assertStatus(422)
            ->assertJsonStructure(['message', 'definitivas', 'year_id']);

        $this->assertSame((int) $banda->definitivas, $r->json('definitivas'),
            'El aviso no dice la misma población que la consulta de control. Si la cuenta del '
            .'controlador no está acotada al año de la banda, exagera sumando los nueve.');

        $this->assertNull(
            DB::selectOne('SELECT deleted_at FROM escalas_de_valoracion WHERE id = ?', [$banda->id])->deleted_at,
            'Contestó 422 y borró la banda igual: es el peor de los dos resultados, porque quien lee '
            .'el 422 cree que no pasó nada.'
        );
    }

    /**
     * Y con `acepto_desviacion` se borra.
     *
     * **Es la mitad que hace que la decisión sea «avisar» y no «prohibir»**: sin
     * este caso, el de arriba se cumpliría igual con un 403 cableado, que es
     * justamente la salida que Joseth descartó.
     */
    public function test_con_acepto_desviacion_la_banda_se_va_a_la_papelera(): void
    {
        $banda = $this->unaBandaConDefinitivas();
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->json('DELETE', '/api/escalas/destroy/'.$banda->id,
            ['acepto_desviacion' => true])->assertStatus(200);

        $this->assertNotNull(
            DB::selectOne('SELECT deleted_at FROM escalas_de_valoracion WHERE id = ?', [$banda->id])->deleted_at,
            'Aceptó la desviación y la banda sigue viva.'
        );
    }

    /**
     * Una banda que no arrastra nada se borra sin aceptar nada.
     *
     * **El aviso tiene que callarse cuando no hay nada que avisar**, o se convierte
     * en un paso más que se teclea sin leer — y entonces deja de proteger justo el
     * día que dice algo.
     *
     * La banda se crea con `POST escalas/store`, que la inserta en **91–100**. La
     * escala del seed llega hasta 50, así que ahí no cae ninguna definitiva: el
     * caso no depende de que el seed tenga un hueco, lo fabrica.
     */
    public function test_una_banda_que_no_arrastra_nada_se_borra_sin_avisar(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $creada = $this->withToken($token)->json('POST', '/api/escalas/store')->json();
        $this->olvidarControladores();

        $this->assertSame(0, (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM notas_finales nf
               INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
              WHERE p.year_id = ? AND ROUND(nf.nota, 0) >= ? AND ROUND(nf.nota, 0) < ? + 1',
            [$creada['year_id'], $creada['porc_inicial'], $creada['porc_final']]
        )->n, 'La banda recién creada (91–100) sí recoge definitivas, así que este caso no mide lo '
            .'que dice: la escala del seed ya no llega hasta 50.');

        $this->withToken($token)->json('DELETE', '/api/escalas/destroy/'.$creada['id'])
            ->assertStatus(200);
    }

    /**
     * `acepto_desviacion` con una cadena cualquiera es 422, **no un sí**.
     *
     * Sin `FILTER_VALIDATE_BOOLEAN`, `"no"` y `"nunca"` valdrían por verdadero y
     * **gobernarían un borrado**: es la familia que persigue
     * `tools/verdad-laxa-que-escribe.py`, y aquí la consecuencia es la banda de un
     * año entero. *Una llave que se abre con cualquier palabra no es una llave.*
     */
    public function test_un_acepto_desviacion_que_no_es_booleano_es_422_y_no_borra(): void
    {
        $banda = $this->unaBandaConDefinitivas();
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->json('DELETE', '/api/escalas/destroy/'.$banda->id,
            ['acepto_desviacion' => 'quizá'])->assertStatus(422);

        $this->assertNull(
            DB::selectOne('SELECT deleted_at FROM escalas_de_valoracion WHERE id = ?', [$banda->id])->deleted_at,
            'Una cadena cualquiera abrió el borrado.'
        );
    }

    /**
     * Y `"false"` tampoco cuela por ser una cadena no vacía.
     *
     * Es el caso que separa `FILTER_VALIDATE_BOOLEAN` de un `(bool)` a secas: en
     * PHP, `(bool) "false"` es **`true`**. Un cliente que mande el booleano
     * serializado como texto —que es lo que hacen varios— borraría sin querer.
     */
    public function test_la_cadena_false_no_acepta_la_desviacion(): void
    {
        $banda = $this->unaBandaConDefinitivas();
        $token = $this->tokenDelPersonalLlano();

        $this->withToken($token)->json('DELETE', '/api/escalas/destroy/'.$banda->id,
            ['acepto_desviacion' => 'false'])->assertStatus(422);

        $this->assertNull(
            DB::selectOne('SELECT deleted_at FROM escalas_de_valoracion WHERE id = ?', [$banda->id])->deleted_at,
            '`"false"` se leyó como un sí. `(bool) "false"` es `true` en PHP: por eso esto va con '
            .'`FILTER_VALIDATE_BOOLEAN` y no con un cast.'
        );
    }

    /**
     * Una banda del año corriente que sí recoge definitivas, con su cuenta de
     * control al lado.
     *
     * **La cuenta se hace aquí otra vez, a mano**, en vez de leerla de la
     * respuesta: comparar la respuesta consigo misma no comprueba nada. Es la
     * consulta de control del doc 36.
     */
    private function unaBandaConDefinitivas(): object
    {
        $banda = DB::selectOne('SELECT e.id, e.year_id, e.desempenio, e.porc_inicial, e.porc_final,
              (SELECT COUNT(*) FROM notas_finales nf
                 INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
                WHERE p.year_id = e.year_id AND ROUND(nf.nota, 0) >= e.porc_inicial
                  AND ROUND(nf.nota, 0) < e.porc_final + 1) AS definitivas
            FROM escalas_de_valoracion e
            INNER JOIN years y ON y.id = e.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            WHERE e.deleted_at IS NULL
            HAVING definitivas > 0
            ORDER BY definitivas DESC LIMIT 1');

        $this->assertNotNull($banda,
            'El seed no tiene ninguna banda del año corriente con definitivas dentro. Sin eso este '
            .'fichero comprueba el aviso sin que haya nada que avisar, que es un verde hueco.');

        return $banda;
    }
}
