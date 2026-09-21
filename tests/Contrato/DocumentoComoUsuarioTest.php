<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las dos rutas nuevas de «el documento como nombre de usuario».
 *
 * Las viejas —`poner-documento-como-username-{alumnos,acudientes}`— siguen donde
 * estaban y `OperacionesMasivasTest` sigue fijando su `assertExactJson`. Lo que se
 * comprueba aquí es lo que aquéllas **no pueden** hacer y por lo que existe la
 * pareja nueva: contar, avisar de los choques y acotarse a un grupo.
 *
 * **LO QUE ESTE SEED NO PUEDE VER, y hay que decirlo** (03-tests.md §«El seed sólo
 * tiene dos estados de matrícula»): `simonbolivar_testing` tiene 65 `MATR`, 59
 * `RETI` y **cero** `ASIS`, `PREM` y `PREA`. El ámbito de grupo usa los cuatro
 * estados de `Matricula::$consulta_asistentes_o_matriculados`, así que sobre este
 * seed ese predicado y `estado = "MATR"` a secas devuelven lo mismo: **un test que
 * sólo mire el seed tal cual no distingue los dos**, y ése fue exactamente el fallo
 * que se cazó a mano contra el docker de desarrollo —la pantalla decía «14
 * matriculados» y el desglose sumaba 7—. Por eso
 * `test_el_grupo_cuenta_tambien_a_los_prematriculados` **prepara el `PREM` dentro de
 * su transacción** en vez de fiarse de lo que hay.
 */
class DocumentoComoUsuarioTest extends CasoDeContrato
{
    private const REVISAR = '/api/cambiar-usuarios/revisar-documento-como-username';
    private const APLICAR = '/api/cambiar-usuarios/documento-como-username';

    private function tokenDelSuperusuario(): string
    {
        $u = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($u->username);
    }

    /**
     * REVISAR NO ESCRIBE. Es la mitad del diseño: la pantalla la llama al abrir el
     * diálogo, antes de que nadie haya decidido nada.
     */
    public function test_revisar_no_toca_ni_un_username(): void
    {
        $token = $this->tokenDelSuperusuario();
        $antes = DB::table('users')->orderBy('id')->pluck('username', 'id')->all();

        $r = $this->withToken($token)->putJson(self::REVISAR, ['destino' => 'alumnos']);

        $r->assertStatus(200)->assertJsonStructure([
            'destino', 'ambito', 'grupo_id', 'total', 'por_cambiar', 'ya_lo_tenian',
            'sin_cuenta', 'sin_cuenta_lista', 'sin_documento', 'sin_documento_lista',
            'en_conflicto', 'en_conflicto_lista',
        ]);

        $this->assertSame($antes, DB::table('users')->orderBy('id')->pluck('username', 'id')->all(),
            'La ruta que sólo mira ha escrito.');
    }

    /**
     * LOS CINCO CONTADORES SUMAN EL TOTAL, y por eso la pantalla puede prometer un
     * número en la fila y sostenerlo en el diálogo.
     *
     * No es aritmética de adorno: la primera versión entraba por `users` con un
     * INNER JOIN y quien no tenía cuenta desaparecía del recuento sin aparecer en
     * ningún contador.
     */
    public function test_el_desglose_cuadra_con_el_total(): void
    {
        $token = $this->tokenDelSuperusuario();

        foreach (['alumnos', 'acudientes', 'profesores'] as $destino) {
            $d = $this->withToken($token)->putJson(self::REVISAR, ['destino' => $destino])->json();

            $this->assertSame(
                $d['total'],
                $d['por_cambiar'] + $d['ya_lo_tenian'] + $d['sin_cuenta']
                    + $d['sin_documento'] + $d['en_conflicto'],
                "El desglose de $destino no suma el total."
            );
        }
    }

    /**
     * APLICAR DEJA EL DOCUMENTO PUESTO, Y CUENTA CUÁNTOS.
     *
     * La segunda mitad es la que ninguna de las dos viejas puede dar: se vuelve a
     * revisar y `por_cambiar` tiene que ser 0. Si el `cambiados` fuera un literal
     * —lo era—, esta segunda llamada lo desmentiría.
     */
    public function test_aplicar_cambia_y_luego_no_queda_nada_por_cambiar(): void
    {
        $token = $this->tokenDelSuperusuario();

        $r = $this->withToken($token)->putJson(self::APLICAR, ['destino' => 'alumnos']);
        $r->assertStatus(200);

        $cambiados = $r->json('cambiados');
        $this->assertIsInt($cambiados);

        $despues = $this->withToken($token)->putJson(self::REVISAR, ['destino' => 'alumnos'])->json();
        $this->assertSame(0, $despues['por_cambiar'],
            'Después de aplicar sigue habiendo cuentas por cambiar.');

        // Y lo que quedó fuera sigue fuera: quien no tiene documento conserva su nombre.
        $sinDocumento = DB::selectOne('SELECT u.username, u.id FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            WHERE u.tipo = "Alumno" AND u.deleted_at IS NULL
              AND TRIM(COALESCE(a.documento, "")) = "" LIMIT 1');

        if ($sinDocumento !== null) {
            $this->assertNotSame('', (string) $sinDocumento->username);
        }
    }

    /**
     * UN CHOQUE NO SE TRAGA EN SILENCIO, que es el motivo entero de la ruta nueva.
     *
     * Se fabrica el choque: a un alumno con documento se le pone ese mismo documento
     * como username **a otra cuenta**. El `UPDATE IGNORE` del camino viejo saltaba
     * esa fila y respondía «Usernames cambiados.» igual; aquí tiene que salir con
     * nombre y motivo, y el username del alumno tiene que quedarse como estaba.
     */
    public function test_el_choque_sale_con_nombre_y_no_se_escribe(): void
    {
        $token = $this->tokenDelSuperusuario();

        $victima = DB::selectOne('SELECT u.id AS user_id, u.username, a.documento,
                TRIM(CONCAT(a.nombres, " ", COALESCE(a.apellidos, ""))) AS nombre
            FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            WHERE u.tipo = "Alumno" AND u.deleted_at IS NULL
              AND TRIM(COALESCE(a.documento, "")) <> "" AND u.username <> a.documento
            GROUP BY a.documento HAVING COUNT(*) = 1 ORDER BY u.id LIMIT 1');

        $this->assertNotNull($victima, 'El seed no tiene ningún alumno con documento único sin usar.');

        // El ladrón: otra cuenta cualquiera que no sea la suya.
        $ladron = DB::selectOne('SELECT id FROM users WHERE id <> ? ORDER BY id DESC LIMIT 1',
            [$victima->user_id]);
        DB::table('users')->where('id', $ladron->id)->update(['username' => $victima->documento]);

        $d = $this->withToken($token)->putJson(self::REVISAR, ['destino' => 'alumnos'])->json();

        $nombres = array_column($d['en_conflicto_lista'], 'nombre');
        $this->assertContains($victima->nombre, $nombres, 'El choque no aparece en la lista.');
        $this->assertGreaterThan(0, $d['en_conflicto']);

        $this->withToken($token)->putJson(self::APLICAR, ['destino' => 'alumnos'])->assertStatus(200);

        $this->assertSame((string) $victima->username,
            (string) DB::table('users')->where('id', $victima->user_id)->value('username'),
            'Se escribió encima de un choque.');
    }

    /**
     * EL GRUPO CUENTA TAMBIÉN A LOS PREMATRICULADOS.
     *
     * El `PREM` lo prepara este test dentro de su transacción porque **el seed no
     * tiene ninguno** —ver la cabecera de la clase—. Sin esto, el predicado de
     * cuatro estados y uno de dos dan lo mismo aquí y el test pasa por la razón
     * equivocada, que es exactamente lo que ocurrió al escribirlo: se copió
     * `IN ('MATR','ASIS')` de otro método y la pantalla prometía 14 donde el
     * servidor miraba 7.
     */
    public function test_el_grupo_cuenta_tambien_a_los_prematriculados(): void
    {
        $token = $this->tokenDelSuperusuario();

        $matricula = DB::selectOne('SELECT m.id, m.grupo_id FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            WHERE m.estado = "MATR" AND m.deleted_at IS NULL
              AND TRIM(COALESCE(a.documento, "")) <> "" ORDER BY m.id LIMIT 1');

        $this->assertNotNull($matricula, 'El seed no tiene ninguna matrícula MATR con documento.');

        $cuerpo = ['destino' => 'alumnos', 'grupo_id' => $matricula->grupo_id];
        $conMatr = $this->withToken($token)->putJson(self::REVISAR, $cuerpo)->json('total');

        DB::table('matriculas')->where('id', $matricula->id)->update(['estado' => 'PREM']);

        $this->assertSame($conMatr, $this->withToken($token)->putJson(self::REVISAR, $cuerpo)->json('total'),
            'Un prematriculado deja de contarse: el predicado del grupo se quedó corto.');
    }

    /** El grupo acota de verdad: no puede mirar más gente que el colegio entero. */
    public function test_el_grupo_no_es_el_colegio(): void
    {
        $token = $this->tokenDelSuperusuario();

        $grupo = DB::selectOne('SELECT grupo_id FROM matriculas WHERE deleted_at IS NULL
            GROUP BY grupo_id ORDER BY COUNT(*) DESC LIMIT 1');

        $delGrupo = $this->withToken($token)
            ->putJson(self::REVISAR, ['destino' => 'alumnos', 'grupo_id' => $grupo->grupo_id])->json();
        $delColegio = $this->withToken($token)->putJson(self::REVISAR, ['destino' => 'alumnos'])->json();

        $this->assertSame('grupo', $delGrupo['ambito']);
        $this->assertSame('colegio', $delColegio['ambito']);
        $this->assertLessThan($delColegio['total'], $delGrupo['total']);
    }

    /**
     * LOS PROFESORES SON DE SUPERUSUARIO, NO DE ADMINISTRATIVO.
     *
     * Viene de la frase de Joseth del 21 ago 2026 que fijó las cuatro masivas
     * —«puede cambiarle la contraseña/username a los alumnos y acudientes
     * solamente»—: los profesores no estaban en esa lista, y crear el destino nuevo
     * no puede regalar lo que nadie pidió. Un profesor no dispara ninguna de las dos.
     */
    public function test_un_profesor_no_dispara_ninguna_de_las_dos(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $antes = DB::table('users')->orderBy('id')->pluck('username', 'id')->all();

        foreach ([self::REVISAR, self::APLICAR] as $ruta) {
            foreach (['alumnos', 'acudientes', 'profesores'] as $destino) {
                $this->withToken($token)->putJson($ruta, ['destino' => $destino])->assertStatus(403);
            }
        }

        $this->assertSame($antes, DB::table('users')->orderBy('id')->pluck('username', 'id')->all());
    }

    /** Un destino que no existe se rechaza, y no se interpreta como «todos». */
    public function test_el_destino_invalido_es_422(): void
    {
        $token = $this->tokenDelSuperusuario();
        $antes = DB::table('users')->orderBy('id')->pluck('username', 'id')->all();

        $this->withToken($token)->putJson(self::APLICAR, ['destino' => 'perros'])->assertStatus(422);
        $this->withToken($token)->putJson(self::APLICAR, [])->assertStatus(422);

        $this->assertSame($antes, DB::table('users')->orderBy('id')->pluck('username', 'id')->all());
    }

    /**
     * UN `grupo_id` CON `profesores` NO SE IGNORA: es 422.
     *
     * Quien lo manda cree estar acotando la operación a un grupo, y la operación
     * sería del colegio entero. Tragárselo en silencio es la forma de fallo que esta
     * pareja de rutas existe para no repetir.
     */
    public function test_los_profesores_no_admiten_grupo(): void
    {
        $token = $this->tokenDelSuperusuario();

        $this->withToken($token)
            ->putJson(self::APLICAR, ['destino' => 'profesores', 'grupo_id' => 1])
            ->assertStatus(422);
    }

    /** Un grupo que no existe es 404, no un «colegio entero» disfrazado. */
    public function test_un_grupo_inexistente_es_404(): void
    {
        $token = $this->tokenDelSuperusuario();

        $this->withToken($token)
            ->putJson(self::REVISAR, ['destino' => 'alumnos', 'grupo_id' => 99999999])
            ->assertStatus(404);
    }
}
