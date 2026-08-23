<?php

namespace Tests\Contrato;

use App\Services\Notificaciones\TemasDeNotificacion;
use Illuminate\Support\Facades\DB;

/**
 * `GET notificaciones/temas` — **la pieza de seguridad del push entero**.
 *
 * Firebase reparte por temas y **el teléfono se apunta él mismo**, así que el
 * nombre del tema es en la práctica la única puerta. Si se llamara `alumno_345`,
 * cualquiera con la app se apuntaría al `alumno_346` y recibiría los avisos de un
 * menor que no es suyo.
 *
 * ## Lo que estos tests miran
 *
 * No basta con «devuelve una cadena». Lo que hay que sostener son cuatro cosas, y
 * cada una tiene su test porque cada una se puede romper por separado:
 *
 * 1. **Que del tema no se pueda volver al alumno** — que no lleve el id dentro.
 * 2. **Que no se pueda derivar el de otro** — dos alumnos, dos temas sin relación.
 * 3. **Que sea estable** — si cambiara entre peticiones, el teléfono quedaría
 *    suscrito a uno muerto y no recibiría nada, en silencio.
 * 4. **Que cada uno reciba sólo los suyos** — un alumno los propios, un acudiente
 *    los de sus acudidos, el personal ninguno.
 *
 * El plan entero está en `myvc_flutter/docs/notificaciones.md`.
 */
class TemasDeNotificacionTest extends CasoDeContrato
{
    /**
     * **El tema no lleva el id del alumno dentro, ni en claro ni disfrazado.**
     *
     * Es el fallo que este diseño existe para evitar, y es el que se cuela solo:
     * `'a_'.$alumnoId` funciona, pasa cualquier test de forma y abre la puerta.
     * Por eso se comprueba contra el id **y contra sus disfraces baratos** —el
     * md5, el base64—, que son a lo que recurre quien «simplifica» esto.
     */
    public function test_del_tema_no_se_vuelve_al_alumno(): void
    {
        $tema = TemasDeNotificacion::deAlumno(345);

        $this->assertStringNotContainsString('345', $tema,
            'El tema lleva el id del alumno: cualquiera se suscribe al de al lado sumando uno.');

        $this->assertStringNotContainsString(md5('345'), $tema);
        $this->assertStringNotContainsString(base64_encode('345'), $tema);
        $this->assertStringNotContainsString(hash('sha256', '345'), $tema,
            'Es un hash SIN secreto: se puede calcular en el teléfono, que es lo mismo que no tenerlo.');
    }

    /**
     * Dos alumnos, dos temas, y **el de uno no dice nada del otro**.
     *
     * Con ids consecutivos a propósito: es el caso que intentaría alguien que
     * quisiera colarse, y el que delataría cualquier derivación que no fuera
     * criptográfica.
     */
    public function test_dos_alumnos_seguidos_dan_temas_sin_relacion(): void
    {
        $uno = TemasDeNotificacion::deAlumno(345);
        $otro = TemasDeNotificacion::deAlumno(346);

        $this->assertNotSame($uno, $otro);

        // Sin prefijo, no comparten ni el principio.
        $this->assertNotSame(substr($uno, 2, 8), substr($otro, 2, 8),
            'Los temas de dos alumnos seguidos se parecen: la derivación no es un HMAC.');
    }

    /**
     * **Y es estable.** Si cambiara entre dos peticiones, el teléfono quedaría
     * suscrito a un tema al que ya no publica nadie y dejaría de recibir **sin
     * ningún error visible**, que es el fallo más caro de este diseño.
     */
    public function test_el_tema_de_un_alumno_no_cambia(): void
    {
        $this->assertSame(
            TemasDeNotificacion::deAlumno(345),
            TemasDeNotificacion::deAlumno(345)
        );
    }

    /** Empieza por letra, que es lo que exige FCM, y sólo lleva caracteres válidos. */
    public function test_el_nombre_del_tema_le_vale_a_firebase(): void
    {
        foreach (TemasDeNotificacion::TIPOS as $tipo) {
            $tema = TemasDeNotificacion::deAlumnoYTipo(345, $tipo);

            $this->assertMatchesRegularExpression('/^[a-zA-Z][a-zA-Z0-9\-_.~%]+$/', $tema,
                'FCM rechaza este nombre de tema: '.$tema);
        }
    }

    /**
     * Un tipo que no existe **revienta en vez de componer un tema cualquiera**.
     *
     * Publicar en un tema inexistente **no da error en Firebase** —es válido
     * publicar donde no hay nadie—, así que un tipo mal escrito perdería el aviso
     * en silencio. Más vale que se caiga aquí.
     */
    public function test_un_tipo_desconocido_no_compone_un_tema(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TemasDeNotificacion::deAlumnoYTipo(345, 'inventado');
    }

    /** Un alumno recibe los suyos, y sólo los suyos. */
    public function test_un_alumno_recibe_sus_tres_temas(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $suyo = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE a.user_id = ? AND a.deleted_at IS NULL LIMIT 1', [$usuario->id]);

        $this->assertNotNull($suyo, 'El seed necesita un alumno con matrícula viva.');

        $cuerpo = $this->withToken($this->tokenDe($usuario->username))
            ->getJson('/api/notificaciones/temas')
            ->assertStatus(200)
            ->json();

        $this->assertCount(1, $cuerpo['alumnos'], 'Un alumno recibió temas de más de una persona.');
        $this->assertSame((int) $suyo->id, $cuerpo['alumnos'][0]['alumno_id']);

        $this->assertSame(['notas', 'asistencia', 'disciplina'],
            array_keys($cuerpo['alumnos'][0]['temas']));

        $this->assertSame(
            TemasDeNotificacion::deAlumnoYTipo((int) $suyo->id, 'notas'),
            $cuerpo['alumnos'][0]['temas']['notas']
        );

        $this->assertSame(['colegio_muro', 'colegio_avisos'], $cuerpo['colegio']);
    }

    /**
     * Un acudiente recibe los de **sus acudidos**, y ninguno más.
     *
     * El aserto que sostiene la promesa es el segundo: se cuentan sus parentescos
     * con matrícula viva y se compara. Comprobar sólo que «viene al menos uno»
     * pasaría igual si la consulta devolviera el colegio entero.
     */
    public function test_un_acudiente_recibe_los_de_sus_acudidos_y_ninguno_mas(): void
    {
        $fila = DB::selectOne('SELECT u.username, ac.id acudiente_id
            FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún acudiente con parentesco y matrícula.');

        $suyos = DB::select('SELECT DISTINCT a.id
            FROM parentescos p
            INNER JOIN alumnos a ON a.id = p.alumno_id AND a.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            WHERE p.acudiente_id = ? AND p.deleted_at IS NULL', [$fila->acudiente_id]);

        $cuerpo = $this->withToken($this->tokenDe($fila->username))
            ->getJson('/api/notificaciones/temas')
            ->assertStatus(200)
            ->json();

        $this->assertCount(count($suyos), $cuerpo['alumnos'],
            'El acudiente recibió temas de '.count($cuerpo['alumnos']).' alumnos y tiene '
            .count($suyos).' acudidos con matrícula viva.');

        $esperados = array_map(fn ($a) => (int) $a->id, $suyos);
        $recibidos = array_column($cuerpo['alumnos'], 'alumno_id');
        sort($esperados);
        sort($recibidos);

        $this->assertSame($esperados, $recibidos);
    }

    /**
     * **El personal no recibe temas de alumno.**
     *
     * No es un olvido: un profesor no necesita que le avisen de las notas que
     * pone él, y dárselos convertiría cualquier cuenta de personal en una forma
     * de conseguir el tema de cualquier alumno — que es justo lo que este
     * endpoint existe para impedir.
     */
    public function test_el_personal_no_recibe_temas_de_alumno(): void
    {
        $personal = $this->usuarioDeTipo('Profesor');

        $cuerpo = $this->withToken($this->tokenDe($personal->username))
            ->getJson('/api/notificaciones/temas')
            ->assertStatus(200)
            ->json();

        $this->assertSame([], $cuerpo['alumnos']);
        $this->assertSame(['colegio_muro', 'colegio_avisos'], $cuerpo['colegio']);
    }

    /**
     * Un acudido **sin matrícula viva** no da tema.
     *
     * `parentescos` no caduca solo, así que sin este filtro el acudiente de un
     * alumno que se fue del colegio hace tres años seguiría recibiendo sus
     * avisos.
     */
    public function test_un_acudido_sin_matricula_viva_no_da_tema(): void
    {
        $fila = DB::selectOne('SELECT u.username, ac.id acudiente_id, p.alumno_id
            FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila);

        DB::table('matriculas')->where('alumno_id', $fila->alumno_id)
            ->update(['deleted_at' => now()]);

        $cuerpo = $this->withToken($this->tokenDe($fila->username))
            ->getJson('/api/notificaciones/temas')
            ->assertStatus(200)
            ->json();

        $this->assertNotContains((int) $fila->alumno_id, array_column($cuerpo['alumnos'], 'alumno_id'),
            'Sigue dando el tema de un acudido que ya no está matriculado.');
    }

    /** Sin token no se contesta: es la superficie que el guard por defecto cubre. */
    public function test_sin_token_no_hay_temas(): void
    {
        $this->getJson('/api/notificaciones/temas')->assertStatus(401);
    }
}
