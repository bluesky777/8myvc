<?php

namespace Tests\Contrato;

use App\Services\Notificaciones\Publicador;
use App\Services\Notificaciones\TemasDeNotificacion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

/**
 * Un publicador que no habla con nadie y apunta lo que le mandan.
 *
 * Existe para que el comando se pueda probar sin credenciales de Firebase. Lo
 * que hay que comprobar de `notificaciones:enviar` es **qué avisa, agrupado
 * cómo, y hasta dónde llegó** — nada de eso necesita a Google, y si lo
 * necesitara no se comprobaría nunca.
 */
class PublicadorDeMentira implements Publicador
{
    /** @var array<int, array<string, mixed>> */
    public array $mandados = [];

    public bool $configurado = true;

    public function estaConfigurado(): bool
    {
        return $this->configurado;
    }

    public function publicar(string $tema, string $titulo, string $cuerpo, array $datos = []): bool
    {
        $this->mandados[] = compact('tema', 'titulo', 'cuerpo', 'datos');

        return true;
    }
}

/**
 * `notificaciones:enviar` — **lo que se avisa, agrupado, y por dónde iba.**
 *
 * El comando lo corre el cron cada quince minutos y **nadie lee su salida**, así
 * que todo lo que puede salir mal aquí sale mal en silencio: un aviso repetido,
 * un aviso perdido, o —el peor— treinta avisos por una columna de notas, que es
 * el día que el acudiente apaga las notificaciones para siempre.
 *
 * Por eso los tests miran tres cosas que no se ven en ninguna respuesta:
 *
 * 1. **Que agrupe.** Cuatro notas del mismo alumno y la misma asignatura son UN
 *    aviso, no cuatro.
 * 2. **Que la primera pasada no mande nada.** Sin esto, encender el push en un
 *    colegio le manda a cada familia un aviso por cada nota del año.
 * 3. **Que no repita.** La segunda pasada sobre lo mismo no vuelve a avisar.
 *
 * Y una cuarta que es de contenido y no de mecánica: **el aviso no lleva la nota
 * dentro**. Se ve en la pantalla bloqueada, en el bus, con gente al lado.
 *
 * El plan entero está en `myvc_flutter/docs/notificaciones.md`.
 */
class EnviarNotificacionesTest extends CasoDeContrato
{
    private PublicadorDeMentira $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicador = new PublicadorDeMentira;
        $this->app->instance(Publicador::class, $this->publicador);

        // Las marcas viven en el caché, que **no** entra en la transacción del
        // test: sin esto, la marca de un test la heredaría el siguiente y los
        // resultados dependerían del orden.
        foreach (['notas', 'asistencia', 'disciplina', 'muro'] as $fuente) {
            Cache::forget('notificaciones.marca.'.$fuente);
        }
    }

    /**
     * **La primera pasada no manda nada: pone la marca y se va.**
     *
     * Es lo que separa encender el push de mandarle a cada familia un aviso por
     * cada fila del año. Va el primero porque además es la condición de partida
     * de todos los demás.
     */
    public function test_la_primera_pasada_no_avisa_de_lo_viejo(): void
    {
        $this->correr();

        $this->assertSame([], $this->publicador->mandados,
            'La primera pasada avisó de lo que ya estaba en la base.');

        $this->assertNotNull(Cache::get('notificaciones.marca.notas'),
            'La primera pasada no dejó puesta la marca, así que la siguiente volvería a ser la primera.');
    }

    /**
     * **Cuatro notas del mismo alumno y asignatura son UN aviso.** Es la promesa
     * central del comando.
     *
     * Un docente que pasa una columna genera treinta cambios en dos minutos. Sin
     * agrupar son treinta avisos seguidos en el teléfono de la misma familia.
     */
    public function test_cuatro_notas_de_la_misma_asignatura_son_un_solo_aviso(): void
    {
        $ctx = $this->asignaturaConNotas();

        $this->correr();   // marca
        $this->publicador->mandados = [];

        foreach ($ctx['notas'] as $notaId) {
            $this->bitacoraDeNota($notaId, $ctx['alumno']);
        }

        $this->correr();

        $deNotas = $this->mandadosAlTema(TemasDeNotificacion::deAlumnoYTipo($ctx['alumno'], 'notas'));

        $this->assertCount(1, $deNotas,
            'Cuatro notas dieron '.count($deNotas).' avisos: la agrupación por alumno y asignatura se deshizo.');

        $this->assertStringContainsString('4 notas nuevas', $deNotas[0]['cuerpo']);
        $this->assertStringContainsString($ctx['asignatura_nombre'], $deNotas[0]['cuerpo'],
            'El aviso no dice de qué asignatura, que es lo que lo hace útil sin abrir la app.');
    }

    /**
     * **Y el aviso no lleva la nota dentro.**
     *
     * «Laura tiene 4 notas nuevas en Matemáticas», nunca «Laura sacó 45». Una
     * notificación se ve en la pantalla bloqueada, con gente al lado, y una nota
     * de un menor no es algo que deba aparecer ahí. Además el aviso llega aunque
     * el colegio tenga las notas bloqueadas (`alumnos_can_see_notas = 0`), y
     * sería absurdo que enseñara lo que la app niega.
     */
    public function test_el_aviso_no_lleva_la_nota_dentro(): void
    {
        $ctx = $this->asignaturaConNotas();

        $this->correr();
        $this->publicador->mandados = [];

        // Una nota con un valor inconfundible: si se colara, se vería.
        DB::table('notas')->where('id', $ctx['notas'][0])->update(['nota' => 37]);
        $this->bitacoraDeNota($ctx['notas'][0], $ctx['alumno'], 37);

        $this->correr();

        $deNotas = $this->mandadosAlTema(TemasDeNotificacion::deAlumnoYTipo($ctx['alumno'], 'notas'));

        $this->assertCount(1, $deNotas);

        $todo = $deNotas[0]['titulo'].' '.$deNotas[0]['cuerpo'].' '.json_encode($deNotas[0]['datos']);

        $this->assertStringNotContainsString('37', $todo,
            'El valor de la nota viajó dentro del aviso: eso se lee en la pantalla bloqueada.');
    }

    /**
     * **La segunda pasada sobre lo mismo no repite el aviso.**
     *
     * Es la marca haciendo su trabajo. Sin ella, el cron de cada quince minutos
     * reenviaría lo mismo 96 veces al día.
     */
    public function test_lo_ya_avisado_no_se_repite(): void
    {
        $ctx = $this->asignaturaConNotas();

        $this->correr();
        $this->publicador->mandados = [];

        $this->bitacoraDeNota($ctx['notas'][0], $ctx['alumno']);

        $this->correr();
        $this->assertCount(1, $this->publicador->mandados);

        $this->publicador->mandados = [];
        $this->correr();

        $this->assertSame([], $this->publicador->mandados,
            'Volvió a avisar de lo mismo: la marca no se está guardando.');
    }

    /**
     * Una situación del observador avisa **por el tema de disciplina**, no por el
     * de notas.
     *
     * Los tres tipos son temas distintos porque las preferencias van por tema:
     * quien apagó «Notas» y dejó «Disciplina» tiene que seguir recibiendo esto.
     * Si los tres cayeran en el mismo tema, apagar uno los apagaría todos.
     */
    public function test_una_situacion_avisa_por_el_tema_de_disciplina(): void
    {
        $ctx = $this->asignaturaConNotas();

        $this->correr();
        $this->publicador->mandados = [];

        DB::table('dis_procesos')->insert([
            'year_id' => $ctx['year_id'],
            'alumno_id' => $ctx['alumno'],
            'periodo_id' => $ctx['periodo'],
            'descripcion' => 'LO QUE PASO, QUE NO DEBE VIAJAR',
            'tipo_situacion' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->correr();

        $deDisciplina = $this->mandadosAlTema(
            TemasDeNotificacion::deAlumnoYTipo($ctx['alumno'], 'disciplina')
        );

        $this->assertCount(1, $deDisciplina);

        $this->assertStringNotContainsString('LO QUE PASO', $deDisciplina[0]['cuerpo'],
            'La descripción de la situación viajó en el aviso.');

        $this->assertSame([], $this->mandadosAlTema(
            TemasDeNotificacion::deAlumnoYTipo($ctx['alumno'], 'notas')
        ), 'La situación avisó también por el tema de notas: apagar uno apagaría el otro.');
    }

    /**
     * Una publicación del muro es **un solo aviso para el colegio**, y su tema no
     * lleva HMAC.
     *
     * No es de nadie, así que no hay a quién derivar: `colegio_muro` es público a
     * propósito porque no dice nada de ningún menor.
     */
    public function test_el_muro_avisa_una_vez_al_colegio(): void
    {
        $this->correr();
        $this->publicador->mandados = [];

        foreach ([1, 2] as $i) {
            DB::table('publicaciones')->insert([
                'tipo_persona' => 'Us',
                'contenido' => 'Publicacion de prueba '.$i,
                'para_todos' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->correr();

        $delMuro = $this->mandadosAlTema('colegio_muro');

        $this->assertCount(1, $delMuro, 'Dos publicaciones dieron dos avisos en vez de uno.');
        $this->assertStringContainsString('2 publicaciones nuevas', $delMuro[0]['cuerpo']);
    }

    /**
     * Una publicación **sólo para profesores no llega a las familias**.
     *
     * El muro tiene interruptores por destinatario. Avisar de una marcada sólo
     * `para_profes` sería enseñarle a las familias que existe, que es media fuga
     * con la otra media puesta.
     */
    public function test_una_publicacion_solo_para_profes_no_avisa_al_muro(): void
    {
        $this->correr();
        $this->publicador->mandados = [];

        DB::table('publicaciones')->insert([
            'tipo_persona' => 'Us',
            'contenido' => 'Reunion de profesores',
            'para_todos' => 0,
            'para_alumnos' => 0,
            'para_acudientes' => 0,
            'para_profes' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->correr();

        $this->assertSame([], $this->mandadosAlTema('colegio_muro'),
            'Se avisó a las familias de una publicación que no es para ellas.');
    }

    /**
     * **Sin credenciales el comando no es un error.**
     *
     * Al principio va a ser el caso en los dieciséis colegios, y esto lo corre un
     * cron cada quince minutos: devolver un código de error sería llenar el
     * registro de un fallo que no lo es. Se dice y se sale con 0.
     *
     * **Y no mueve la marca**, que es la mitad que importa: si la moviera, el día
     * que el colegio ponga las credenciales se habría perdido en silencio todo lo
     * ocurrido mientras tanto.
     */
    public function test_sin_credenciales_no_falla_ni_mueve_la_marca(): void
    {
        $this->publicador->configurado = false;

        $this->correr();

        $this->assertNull(Cache::get('notificaciones.marca.notas'),
            'Sin credenciales movió la marca: lo ocurrido hasta que se configuren se perdería.');
    }

    /**
     * El modo `--seco` dice qué mandaría y **no manda ni mueve la marca**.
     *
     * Es con lo que se comprueba en un colegio que esto ve lo que tiene que ver,
     * antes de que exista ninguna credencial y sin gastar un aviso.
     */
    public function test_el_modo_seco_no_manda_ni_mueve_la_marca(): void
    {
        $this->correr();
        $marca = Cache::get('notificaciones.marca.notas');

        $ctx = $this->asignaturaConNotas();
        $this->bitacoraDeNota($ctx['notas'][0], $ctx['alumno']);

        $this->publicador->mandados = [];

        $this->correr('--seco');

        $this->assertSame([], $this->publicador->mandados, 'En seco mandó de verdad.');
        $this->assertSame($marca, Cache::get('notificaciones.marca.notas'),
            'En seco movió la marca, así que el aviso real ya no saldría.');
    }

    /**
     * Correr el comando y exigir que salga con 0.
     *
     * Se envuelve porque `artisan()` devuelve `PendingCommand|int` —el `int` es
     * cuando no quedan expectativas por cumplir— y larastan nivel 7 no deja
     * llamar `assertExitCode()` sobre esa unión. `run()` es lo que ejecuta de
     * verdad y devuelve el código, así que la comprobación se conserva entera en
     * vez de anotarse para que calle.
     */
    private function correr(string $opciones = ''): void
    {
        $resultado = $this->artisan(trim('notificaciones:enviar '.$opciones));

        $codigo = $resultado instanceof PendingCommand ? $resultado->run() : $resultado;

        $this->assertSame(0, $codigo, 'El comando salió con código '.$codigo.'.');
    }

    /** @return array<int, array<string, mixed>> */
    private function mandadosAlTema(string $tema): array
    {
        return array_values(array_filter(
            $this->publicador->mandados,
            fn ($m) => $m['tema'] === $tema
        ));
    }

    /**
     * La bitácora que deja `putUpdate` al guardar una nota, que es de donde el
     * comando saca qué avisar.
     */
    private function bitacoraDeNota(int $notaId, int $alumnoId, int $valor = 40): void
    {
        DB::table('bitacoras')->insert([
            'created_by' => 1,
            'affected_user_id' => $alumnoId,
            'affected_person_type' => 'Al',
            'affected_element_type' => 'Nota',
            'affected_element_id' => $notaId,
            'affected_element_new_value_int' => $valor,
            'created_at' => now(),
        ]);
    }

    /**
     * Una asignatura con cuatro notas de un alumno, y el nombre de su materia
     * para poder comprobar que el aviso lo dice.
     *
     * **El periodo se elige desde los datos y no desde el usuario**, al revés que
     * en los demás tests de esta familia, y la razón merece quedar escrita:
     * aquéllos piden un token, y entrar refresca el `periodo_id` del usuario al
     * año en curso. Éste no necesita identificarse —el comando lo corre el cron,
     * no una petición—, así que el profesor del seed se queda con el periodo que
     * tuviera guardado, que resultó ser el de un año **sin asignaturas**. Buscar
     * el periodo por el usuario daba `null` y el montaje entero se caía por algo
     * que no tenía nada que ver con las notificaciones.
     *
     * @return array<string, mixed>
     */
    private function asignaturaConNotas(): array
    {
        // El nombre sale de `materias`: `asignaturas` sólo lleva las claves.
        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id, p.id AS periodo_id, p.year_id,
                COALESCE(NULLIF(mat.alias, ""), mat.materia) AS nombre
            FROM asignaturas a
            INNER JOIN materias mat ON mat.id = a.materia_id
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
            WHERE a.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM matriculas m WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
            ) ORDER BY a.id, p.numero LIMIT 1');

        $this->assertNotNull($asignatura, 'El seed no tiene una asignatura con materia y matrículas.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 1',
            [$asignatura->grupo_id]);

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $asignatura->periodo_id,
            'definicion' => 'UNIDAD DE PRUEBA',
            'porcentaje' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notas = [];

        foreach ([1, 2, 3, 4] as $n) {
            $subId = DB::table('subunidades')->insertGetId([
                'unidad_id' => $unidadId,
                'definicion' => 'SUB '.$n,
                'porcentaje' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $notas[] = DB::table('notas')->insertGetId([
                'subunidad_id' => $subId,
                'alumno_id' => $alumno->alumno_id,
                'nota' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'alumno' => (int) $alumno->alumno_id,
            'asignatura' => (int) $asignatura->id,
            'asignatura_nombre' => (string) $asignatura->nombre,
            'periodo' => (int) $asignatura->periodo_id,
            'year_id' => (int) $asignatura->year_id,
            'notas' => $notas,
        ];
    }
}
