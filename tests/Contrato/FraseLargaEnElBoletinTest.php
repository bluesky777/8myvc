<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La frase del boletín se guardaba cortada a los 255 y contestaba 200.
 *
 * `frases_asignatura.frase` era `varchar(255)` mientras sus dos hermanas del
 * mismo camino —`frases.frase`, el catálogo del que sale
 * `IFNULL(f.frase, fa.frase)`, y `definiciones_comportamiento.frase`— son `text`.
 * Y el `sql_mode` de estos servidores no lleva `STRICT_TRANS_TABLES`, así que
 * MySQL **cortaba y devolvía 200**: el docente veía su frase guardada y el
 * acudiente la recibía partida a mitad de palabra, impresa en el boletín.
 *
 * No es una hipótesis. Contado sobre la copia de `simonbolivar` del contenedor:
 * **626 de las 11.596 frases escritas a mano tienen exactamente 255 caracteres**
 * y acaban sin punto — *«…para desarrollar las competencias p»*. 255 exactos es
 * la firma de un truncamiento, no una casualidad.
 *
 * Lo arregla `2026_09_05_100000_frase_del_boletin_en_text`, autorizado por Joseth
 * el 2 sep 2026 para que quepan los indicadores de desempeño
 * (`docs/migracion/28-competencias-e-indicadores.md` §1.ter y §5.3).
 *
 * ## Por qué el test mira el viaje de ida y vuelta y no el 200
 *
 * Porque **el 200 nunca faltó**: es exactamente lo que hacía que el fallo viviera
 * dos años. Un `assertStatus(200)` aquí pasa con la columna rota y con la columna
 * arreglada. Lo único que separa las dos es **leer lo que quedó guardado**, y por
 * eso se comprueba en los tres sitios donde la frase puede perderse: lo que
 * devuelve el `POST`, lo que devuelve el `GET` de después y lo que hay en la
 * tabla.
 *
 * Se corrió **rojo antes que verde**: contra la base sin migrar da 255 en los
 * tres.
 */
class FraseLargaEnElBoletinTest extends CasoDeContrato
{
    /**
     * 388 caracteres, que es un indicador de desempeño de verdad.
     *
     * La longitud no es redonda a propósito: 300 se leería como «más de 255» y no
     * dice de dónde sale. Un indicador de preescolar del estilo de los que trae
     * §5.7 —«Reconoce las partes de su cuerpo y practica hábitos de higiene…»—
     * ronda esta cifra en cuanto lleva el «para» y el «mediante» que el colegio
     * escribe siempre.
     */
    private const FRASE = 'Identifica y clasifica los tipos de triángulo según sus lados y '
        .'sus ángulos, resuelve problemas de perímetro y área en situaciones de la vida '
        .'cotidiana, y argumenta el procedimiento que siguió mediante representaciones '
        .'gráficas y lenguaje matemático apropiado para el grado, demostrando además '
        .'responsabilidad en la entrega puntual de sus trabajos y disposición para el '
        .'trabajo en equipo.';

    public function test_una_frase_de_mas_de_255_llega_entera_al_boletin(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL
              AND m.estado IN ("MATR","ASIS","PREM") ORDER BY m.id LIMIT 1', [$grupo->id]);
        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            WHERE a.grupo_id = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id]);

        $this->assertNotNull($alumno, 'El seed no tiene un alumno matriculado en el grupo elegido.');
        $this->assertNotNull($asignatura, 'El seed no tiene una asignatura en el grupo elegido.');

        $largo = mb_strlen(self::FRASE);
        $this->assertGreaterThan(255, $largo,
            'La frase de este test tiene que pasar de 255 o no comprueba nada.');

        $r = $this->withToken($token)->json('POST', '/api/frases_asignatura/store', [
            'alumno_id' => $alumno->alumno_id,
            'asignatura_id' => $asignatura->id,
            'frase' => self::FRASE,
        ]);

        $r->assertStatus(200);

        // 1) Lo que devuelve el POST. Es la lista de frases del alumno en esa
        //    asignatura, así que se busca la recién creada por su principio.
        $delPost = collect($r->json())->first(
            fn ($f) => str_starts_with((string) ($f['frase'] ?? ''), 'Identifica y clasifica'));
        $this->assertNotNull($delPost, 'El POST no devolvió la frase que acababa de crear.');
        $this->assertSame($largo, mb_strlen($delPost['frase']),
            'El POST devolvió la frase cortada a '.mb_strlen($delPost['frase'])." de {$largo}.");

        // 2) El viaje de ida y vuelta: lo que le llega al boletín es esto.
        $show = $this->withToken($token)->json('GET',
            "/api/frases_asignatura/show/{$alumno->alumno_id}/{$asignatura->id}");
        $show->assertStatus(200);

        $delShow = collect($show->json())->first(
            fn ($f) => str_starts_with((string) ($f['frase'] ?? ''), 'Identifica y clasifica'));
        $this->assertNotNull($delShow, 'El GET de después no trae la frase.');
        $this->assertSame(self::FRASE, $delShow['frase'],
            'La frase que lee el boletín no es la que escribió el docente.');

        // 3) Y en la tabla, que es donde se perdía. Sin esto, un accessor que
        //    rellenara el texto taparía el corte en las dos comprobaciones de
        //    arriba.
        $enLaBase = (int) DB::selectOne('SELECT CHAR_LENGTH(frase) n FROM frases_asignatura
            WHERE id = ?', [$delShow['id']])->n;
        $this->assertSame($largo, $enLaBase,
            "En la tabla quedaron {$enLaBase} caracteres de {$largo}. La columna sigue cortando.");
    }
}
