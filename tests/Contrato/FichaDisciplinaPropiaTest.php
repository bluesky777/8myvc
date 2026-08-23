<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `GET disciplina/mis-fichas/{alumno_id?}` — **el alumno y su familia ven su
 * situación disciplinaria**.
 *
 * Lo pide la app. Hasta hoy la pantalla existía y funcionaba para el personal, y
 * ellos no entraban: los cuatro controladores que tocan `dis_procesos` llevan
 * `auth.personal` en **todas** sus rutas, y ése aborta con 403 a `Alumno` y
 * `Acudiente`.
 *
 * ## Lo que estos tests miran, y por qué
 *
 * Dos cosas distintas, y las dos hacen falta:
 *
 * 1. **Quién entra** — que la guarda sea la de propiedad y no la de personal, que
 *    un alumno no pueda pedir la de un compañero, y que un acudiente sí pueda la
 *    de su acudido y no la de otro. Aquí lo que se comprueba es el **403**.
 * 2. **Que la respuesta tenga la forma de `PUT disciplina/alumnos`** — y esto se
 *    comprueba **clave a clave contra la respuesta de verdad**, no contra una
 *    lista escrita a mano. Es la promesa entera del endpoint: la app reutiliza
 *    `AlumnoDisciplinaModel` y `FichaDisciplinaScreen` tal cual, en modo lectura.
 *    Una lista escrita a mano se queda vieja el día que alguien añada una columna
 *    a `Grupo::alumnos`, y entonces el test seguiría verde con la promesa rota.
 *
 * El paz y salvo **no** aplica —modo `sin-paz-y-salvo`—, y también tiene su test:
 * retener el boletín de quien debe es una cosa y esconderle a una familia la
 * situación disciplinaria de su hijo es otra, y esa nadie la ha pedido.
 */
class FichaDisciplinaPropiaTest extends CasoDeContrato
{
    /**
     * **La forma es la misma que la de `PUT disciplina/alumnos`.** Es el test que
     * sostiene el contrato con la app.
     *
     * Se piden las dos cosas en la misma ejecución y sobre el **mismo alumno**, y
     * se comparan los juegos de claves. Comparar contra una lista escrita a mano
     * probaría que la respuesta no cambió; comparar contra la otra respuesta
     * prueba lo que de verdad se prometió, que es que **las dos digan lo mismo**.
     *
     * Los valores no se comparan: el editor los trae para el grupo entero y esto
     * para uno, así que coincidir en claves es la promesa y coincidir en valores
     * sería casualidad del montaje.
     */
    public function test_la_ficha_tiene_las_mismas_claves_que_un_elemento_del_editor(): void
    {
        [$tokenPersonal, $alumno] = $this->alumnoConSituacion();

        $delEditor = $this->withToken($tokenPersonal)->putJson('/api/disciplina/alumnos', [
            'grupo_id' => $alumno->grupo_id,
            'year_id' => $alumno->year_id,
        ])->assertStatus(200)->json('alumnos');

        $suyoEnElEditor = null;

        foreach ($delEditor as $fila) {
            if ((int) $fila['alumno_id'] === (int) $alumno->alumno_id) {
                $suyoEnElEditor = $fila;
            }
        }

        $this->assertNotNull($suyoEnElEditor,
            'El montaje necesita que el alumno salga en el grupo que pide el editor.');

        $deLaFicha = $this->withToken($tokenPersonal)
            ->getJson('/api/disciplina/mis-fichas/'.$alumno->alumno_id)
            ->assertStatus(200)
            ->json('alumno');

        $esperadas = array_keys($suyoEnElEditor);
        $reales = array_keys($deLaFicha);
        sort($esperadas);
        sort($reales);

        $this->assertSame($esperadas, $reales,
            "La ficha dejó de tener la forma de un elemento de PUT disciplina/alumnos.\n"
            .'Sobran: '.implode(', ', array_diff($reales, $esperadas))."\n"
            .'Faltan: '.implode(', ', array_diff($esperadas, $reales))."\n"
            .'La app reutiliza AlumnoDisciplinaModel y FichaDisciplinaScreen con esa forma.');
    }

    /**
     * Y las claves por periodo están de verdad, con la situación dentro.
     *
     * El test de arriba compara juegos de claves, así que pasaría igual si las dos
     * respuestas se quedaran **las dos** sin los periodos. Éste mira el contenido:
     * la situación que el montaje acaba de crear tiene que salir en su `periodoN`,
     * con sus `proceso_ordinales`, y el contador de su tipo tiene que valer 1.
     */
    public function test_la_ficha_trae_la_situacion_en_su_periodo_con_sus_ordinales(): void
    {
        [$token, $alumno] = $this->alumnoConSituacion();

        $ficha = $this->withToken($token)
            ->getJson('/api/disciplina/mis-fichas/'.$alumno->alumno_id)
            ->assertStatus(200)
            ->json('alumno');

        $clave = 'periodo'.$alumno->numero_periodo;

        $this->assertArrayHasKey($clave, $ficha, 'La ficha no trae el periodo de la situación.');

        $ids = array_column($ficha[$clave], 'id');

        $this->assertContains($alumno->proceso_id, $ids,
            'La situación creada no sale en la ficha del alumno.');

        $suya = null;

        foreach ($ficha[$clave] as $situacion) {
            if ((int) $situacion['id'] === (int) $alumno->proceso_id) {
                $suya = $situacion;
            }
        }

        $this->assertCount(1, $suya['proceso_ordinales'],
            'La situación viene sin el ordinal del manual que la justifica.');

        $this->assertSame(1, (int) $ficha['per'.$alumno->numero_periodo.'_cant_t1'],
            'El contador del tipo 1 de ese periodo no cuenta la situación.');
    }

    /**
     * `config` y `ordinales` vienen, y **`grupos` y `descripciones_typeahead` no**.
     *
     * Los dos primeros porque la ficha los necesita para pintar: los tres tipos se
     * llaman como los llame el colegio y los ordinales de cada situación se
     * resuelven contra el catálogo del año. Los dos últimos son del **editor** —la
     * lista de grupos y las descripciones que se sugieren al teclear—, y aquí no
     * se escribe nada: mandárselos a una familia es enseñarle el mapa del colegio
     * y lo que se ha escrito de los demás alumnos.
     */
    public function test_trae_config_y_ordinales_y_no_lo_que_es_del_editor(): void
    {
        [$token, $alumno] = $this->alumnoConSituacion();

        $cuerpo = $this->withToken($token)
            ->getJson('/api/disciplina/mis-fichas/'.$alumno->alumno_id)
            ->assertStatus(200)
            ->json();

        $this->assertSame(['alumno', 'config', 'ordinales'], array_keys($cuerpo),
            'La respuesta cambió de claves de primer nivel.');

        $this->assertNotNull($cuerpo['config']);
        $this->assertArrayHasKey('falta_tipo1_displayname', $cuerpo['config'],
            'Sin los nombres que el colegio le da a cada tipo, la ficha no se puede pintar.');

        $this->assertNotEmpty($cuerpo['ordinales']);
        $this->assertArrayHasKey('ordinal', $cuerpo['ordinales'][0]);
    }

    /**
     * **Y no crea la configuración del año si falta.**
     *
     * Sus dos hermanas —`grupos/con-disciplina` y `ordinales/ordinales`— insertan
     * una fila en `dis_configuraciones` cuando el año no la tiene. Ésta la abre
     * **una familia**, y una lectura que escribe es la forma más silenciosa de que
     * un endpoint de sólo lectura deje de serlo.
     *
     * Se comprueba contando filas y no mirando la respuesta: un endpoint que
     * inserte y devuelva la fila recién creada da un `config` perfectamente
     * correcto, y el fallo estaría igual.
     */
    public function test_sin_configuracion_del_ano_no_la_crea(): void
    {
        [$token, $alumno] = $this->alumnoConSituacion();

        DB::table('dis_configuraciones')->where('year_id', $alumno->year_id)->delete();

        $antes = (int) DB::table('dis_configuraciones')->count();

        $cuerpo = $this->withToken($token)
            ->getJson('/api/disciplina/mis-fichas/'.$alumno->alumno_id)
            ->assertStatus(200)
            ->json();

        $this->assertNull($cuerpo['config'],
            'Sin fila de configuración debe venir null, para que el cliente use sus valores por defecto.');

        $this->assertSame($antes, (int) DB::table('dis_configuraciones')->count(),
            'La ficha creó la configuración del año: es una lectura que escribe, y la abre una familia.');
    }

    /**
     * **Un alumno ve la suya sin decir de quién**, que es el caso normal desde la
     * app: no tiene por qué saber su propio `alumno_id`.
     *
     * La guarda deja pasar cuando no hay id —no hay nada que proteger todavía— y
     * es el controlador el que lo traduce a `persona_id`. Por eso el test
     * comprueba **de quién es la ficha que llega**, no sólo que llegue un 200: sin
     * esa traducción, «lo mío» podría ser la ficha de cualquiera.
     */
    public function test_un_alumno_pide_la_suya_sin_id(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $suyo = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id
                AND m.estado IN ("MATR","ASIS","PREM") AND m.deleted_at IS NULL
            WHERE a.user_id = ? AND a.deleted_at IS NULL LIMIT 1', [$usuario->id]);

        $this->assertNotNull($suyo, 'El seed necesita un alumno con matrícula viva.');

        $ficha = $this->withToken($this->tokenDe($usuario->username))
            ->getJson('/api/disciplina/mis-fichas')
            ->assertStatus(200)
            ->json('alumno');

        $this->assertSame((int) $suyo->id, (int) $ficha['alumno_id'],
            'Sin id, «lo mío» resolvió a la ficha de otro.');
    }

    /**
     * Y **no la de un compañero**. Es el agujero que la guarda existe para tapar.
     */
    public function test_un_alumno_no_ve_la_ficha_de_un_companero(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $mio = DB::selectOne('SELECT a.id, m.grupo_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE a.user_id = ? AND a.deleted_at IS NULL LIMIT 1', [$usuario->id]);

        $otro = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.id <> ? AND a.deleted_at IS NULL LIMIT 1',
            [$mio->grupo_id, $mio->id]);

        $this->assertNotNull($otro, 'El montaje necesita un compañero de grupo.');

        $this->withToken($this->tokenDe($usuario->username))
            ->getJson('/api/disciplina/mis-fichas/'.$otro->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes ver el de otros');
    }

    /**
     * Un acudiente ve la de su acudido, y **aunque el acudido deba**.
     *
     * Las dos mitades van juntas a propósito: comprobar sólo que entra dejaría sin
     * fijar la decisión que de verdad se tomó aquí, que es que el paz y salvo **no
     * aplica**. Por eso el test pone `pazysalvo` a 0 antes de pedir — con la
     * guarda en modo `boletin`, esto sería un 403.
     */
    public function test_un_acudiente_ve_la_de_su_acudido_aunque_deba(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        DB::table('alumnos')->where('id', $suyo->alumno_id)->update(['pazysalvo' => 0]);

        $ficha = $this->withToken($token)
            ->getJson('/api/disciplina/mis-fichas/'.$suyo->alumno_id)
            ->assertStatus(200)
            ->json('alumno');

        $this->assertSame((int) $suyo->alumno_id, (int) $ficha['alumno_id']);
    }

    /** Y no la de quien no es su acudido. */
    public function test_un_acudiente_no_ve_la_de_quien_no_es_su_acudido(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        $ajeno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE a.id <> ? AND a.deleted_at IS NULL LIMIT 1', [$suyo->alumno_id]);

        $this->withToken($token)
            ->getJson('/api/disciplina/mis-fichas/'.$ajeno->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'No es acudiente de este alumno. Lo siento.');
    }

    /**
     * Un acudiente **sin id** recibe 400, no la ficha de nadie.
     *
     * «Lo mío» no significa nada para un acudiente: tiene varios acudidos y él no
     * es alumno. La guarda deja pasar la petición sin id porque no hay alumno
     * concreto que proteger, así que **el 400 tiene que ponerlo el controlador**, y
     * si no lo pusiera el `fichaConFormaDeGrupo` recibiría un id vacío. Es la misma
     * respuesta que da `notas/alumno` en el mismo caso.
     */
    public function test_un_acudiente_sin_id_recibe_400(): void
    {
        [$token] = $this->acudienteConAcudido();

        $this->withToken($token)
            ->getJson('/api/disciplina/mis-fichas')
            ->assertStatus(400);
    }

    /**
     * Un alumno sin matrícula viva en el año es **404 y no 500**.
     *
     * Es la lección de la §52: el `[0]` desnudo de las tres escrituras de este
     * controlador daba «Undefined array key 0» porque la consulta lleva un INNER
     * JOIN a `matriculas`. Aquí llega por el mismo camino, y quien lo dispara no
     * es un id inventado sino un alumno **real** al que se le borró la matrícula.
     */
    public function test_un_alumno_sin_matricula_viva_es_404(): void
    {
        [$token, $alumno] = $this->alumnoConSituacion();

        DB::table('matriculas')->where('alumno_id', $alumno->alumno_id)
            ->update(['deleted_at' => now()]);

        $this->withToken($token)
            ->getJson('/api/disciplina/mis-fichas/'.$alumno->alumno_id)
            ->assertStatus(404);
    }

    /**
     * Un alumno con una situación de tipo 1 y un ordinal, en el año del personal
     * que la va a pedir.
     *
     * Se monta la situación en vez de buscar una: el seed tiene procesos, pero no
     * hay ninguno del que se pueda afirmar el tipo ni el número de ordinales, y
     * sin eso los asertos de contenido no podrían decir un número.
     */
    private function alumnoConSituacion(): array
    {
        $personal = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($personal->username);

        // El año sale del **periodo** del usuario y no de `users`, que no tiene
        // `year_id`. Es el mismo camino que usa `ContextoDeUsuario` para aplanarlo.
        $suyo = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$personal->id]);

        $this->assertNotNull($suyo, 'El usuario del seed se quedó sin periodo al entrar.');

        $yearId = (int) $suyo->year_id;

        $fila = DB::selectOne('SELECT m.alumno_id, m.grupo_id, p.id periodo_id, p.numero
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY p.numero, m.alumno_id LIMIT 1', [$yearId]);

        $this->assertNotNull($fila, 'El seed no tiene una matrícula viva en el año del usuario.');

        $procesoId = DB::table('dis_procesos')->insertGetId([
            'year_id' => $yearId,
            'alumno_id' => $fila->alumno_id,
            'periodo_id' => $fila->periodo_id,
            'descripcion' => 'SITUACION DE PRUEBA',
            'tipo_situacion' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ordinalId = DB::table('dis_ordinales')->insertGetId([
            'year_id' => $yearId,
            'tipo' => '1',
            'ordinal' => 'ART. DE PRUEBA',
            'descripcion' => 'Lo que dice el manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dis_proceso_ordinales')->insert([
            'ordinal_id' => $ordinalId,
            'proceso_id' => $procesoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$token, (object) [
            'alumno_id' => (int) $fila->alumno_id,
            'grupo_id' => (int) $fila->grupo_id,
            'year_id' => $yearId,
            'numero_periodo' => (int) $fila->numero,
            'proceso_id' => (int) $procesoId,
        ]];
    }

    private function acudienteConAcudido(): array
    {
        $fila = DB::selectOne('SELECT u.username, p.alumno_id
            FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún acudiente con parentesco y matrícula.');

        return [$this->tokenDe($fila->username), $fila];
    }
}
