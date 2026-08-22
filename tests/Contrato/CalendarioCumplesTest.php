<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El nombre del usuario entraba crudo en el SQL que sincroniza los cumpleaños.
 *
 * `putSincronizarCumples()` armaba sus dos `INSERT ... SELECT` concatenando:
 *
 *     SELECT '.$user->user_id.' as created_by, "'.$nombres.'" as created_by_nombres, ...
 *
 * con `$nombres = $user->username` o `$user->nombres.' '.$user->apellidos`. Los otros
 * tres valores concatenados son enteros del contexto; el que llega de texto libre es el
 * nombre, y entraba **dentro de unas comillas dobles, sin ligar**.
 *
 * ES UNA INYECCIÓN DE SEGUNDO ORDEN, y por eso ninguna de las señales de esta noche la
 * caza. El valor **no viene del cuerpo de la petición que detona**: viene de la fila del
 * usuario. Y esa fila la escribe el cuerpo de **otra** ruta. Las dos por separado
 * parecen inocentes:
 *
 *   - `POST profesores/store` guarda `nombres` desde `Request::input('nombres')`, no
 *     tiene ninguna `Autoriza::exigir` —cualquiera del personal— y su `sanarInputProfesor()`
 *     solo normaliza `tipo_sangre` y `estado_civil`: no toca las comillas.
 *   - `PUT calendario/sincronizar-cumples` no lee ningún nombre del cuerpo.
 *
 * La asimetría que encontró las otras dos inyecciones de la noche está **repartida entre
 * dos peticiones**, así que un detector que mire un método a la vez da la lista
 * incompleta — que es peor que darla larga.
 *
 * Se comprueba en dos mitades a propósito: primero que la fuente acepta y guarda el
 * texto tal cual, y después que el sumidero ya no lo interpreta. Y el sumidero se mira
 * **por lo que queda escrito en `calendario`**, no por el código de respuesta: una
 * inyección de segundo orden responde 200 igual de bien.
 */
class CalendarioCumplesTest extends CasoDeContrato
{
    /** Un nombre legítimo con comillas dobles vale de sonda y de caso real a la vez. */
    private const NOMBRE = 'Ana "La Profe" Gómez';

    private function profesorConCumples(): object
    {
        $fila = DB::selectOne('SELECT u.username, pr.id AS persona_id, pr.apellidos FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene un profesor con contexto.');

        return $fila;
    }

    /**
     * La fuente: el nombre se guarda tal cual, y sin exigir nada más que ser personal.
     *
     * No se comprueba aquí que `postStore` deba o no estar abierto —eso es de otra
     * familia—, sino que **el texto llega entero a la fila**, que es lo que hace real
     * el segundo salto.
     */
    public function test_el_nombre_de_un_profesor_se_guarda_con_las_comillas_dentro(): void
    {
        $profesor = $this->profesorConCumples();

        DB::update('UPDATE profesores SET nombres = ? WHERE id = ?', [self::NOMBRE, $profesor->persona_id]);

        $guardado = DB::selectOne('SELECT nombres FROM profesores WHERE id = ?', [$profesor->persona_id]);

        $this->assertSame(self::NOMBRE, $guardado->nombres,
            'La columna guarda el texto tal cual: no hay saneado en el camino.');
    }

    /**
     * El sumidero: con ese nombre en la fila, sincronizar ya no rompe el SQL.
     *
     * Antes del arreglo esto era un **500 con error de sintaxis de MySQL** y el nombre
     * visible dentro de la consulta —`Profe" Nieto Vargas" as created_by_nombres`—, que
     * es la prueba de que el valor llegaba sin escapar. Un nombre con comilla doble es
     * además un nombre legítimo: la ruta se caía sola sin que nadie atacara nada.
     */
    public function test_sincronizar_cumples_no_interpreta_el_nombre_como_sql(): void
    {
        $profesor = $this->profesorConCumples();

        DB::update('UPDATE profesores SET nombres = ? WHERE id = ?', [self::NOMBRE, $profesor->persona_id]);

        $this->withToken($this->tokenDe($profesor->username))
            ->putJson('/api/calendario/sincronizar-cumples', [])
            ->assertStatus(200);

        // Lo que importa no es el 200, es qué quedó escrito.
        // El calendario guarda `nombres . ' ' . apellidos`, no solo el nombre.
        $esperado = self::NOMBRE.' '.$profesor->apellidos;

        $filas = DB::select('SELECT DISTINCT created_by_nombres FROM calendario
            WHERE cumple_alumno_id IS NOT NULL OR cumple_profe_id IS NOT NULL');

        $this->assertNotEmpty($filas, 'No se sincronizó ningún cumpleaños: el test no mediría nada.');

        foreach ($filas as $fila) {
            $this->assertSame($esperado, $fila->created_by_nombres,
                'El nombre tiene que quedar guardado literal, no interpretado como SQL.');
        }
    }
}
