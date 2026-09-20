<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **El calendario interno del colegio: quien preguntaba decidía si el interruptor
 * se le aplicaba.** §150.
 *
 * `PUT api/calendario/this-year` —sin middleware propio, o sea `auth.token`—
 * empezaba así:
 *
 * ```php
 * $is_prof_admin = Request::input('is_prof_admin');   // ← del CUERPO
 * if ($is_prof_admin == 'true') {  … SELECT * FROM calendario …
 * } else {                         … WHERE solo_profes = 0 …
 * ```
 *
 * `calendario.solo_profes` es el interruptor con el que el colegio marca un evento
 * como **interno**. La columna funciona; lo que fallaba es **de dónde salía el
 * booleano**: del cuerpo de la petición.
 *
 * ## Lo que lo separa de la §74
 *
 * En la §74 el interruptor no lo leía nadie. Aquí **se lee y se respeta**: sin la
 * bandera, un alumno ve exactamente los públicos. No hay que enseñar a nadie a
 * mirar una columna — hay que **mover de sitio un dato**.
 *
 * ## Y por qué no lo cazó ningún candado
 *
 * `calendario/*` tiene **1 de 6 rutas con guard**, así que **nunca entra** en el
 * candado de familia de `AutorizacionTest`, que exige dos o más hermanas con
 * guard. Es el lado simétrico del §114: unas familias se salen del candado por
 * tener demasiadas abiertas y otras **no entran nunca** por tener demasiadas
 * pocas cerradas.
 */
class CalendarioInternoTest extends CasoDeContrato
{
    /**
     * Eventos internos montados aquí, no buscados en el seed.
     *
     * Se crean con `solo_profes = 1` y un título reconocible: lo que se compara
     * después son **estos ids**, no un número. Contra un número fijo el caso
     * mediría el seed y no el guard — y el seed cambia.
     *
     * @return list<int>
     */
    /**
     * El año lectivo que resuelve un token — **no el del reloj**.
     *
     * Hace falta desde el 20 sep 2026, que es cuando el calendario pasó a
     * leerse **por año**. Se le pregunta al contexto y no a `users.periodo_id`,
     * porque entrar mueve a la persona al periodo actual: en el seed la fila
     * dice 2021 y el token resuelve 2025.
     */
    private function anioDelToken(string $token): int
    {
        $r = $this->postJson('/api/login', [], ['Authorization' => 'Bearer '.$token]);
        $r->assertStatus(200);

        return (int) $r->json('year');
    }

    /**
     * Tres eventos internos **dentro del año de ese token**.
     *
     * **Nacían con `start => now()`, y eso dejó de servir el 20 sep 2026**: el
     * calendario se lee por año lectivo y el reloj real va por 2026 mientras el
     * seed vive en 2025, así que los tres caían fuera y **ninguna de las cinco
     * pruebas de esta clase medía ya lo que dice**.
     *
     * Las dos que exigen ver los internos se pusieron rojas y avisaron. **Las
     * tres que exigen NO verlos siguieron verdes y ésas son las peligrosas**:
     * pasaban porque los eventos estaban fuera del año, o sea que habrían
     * pasado igual **con el filtro de `solo_profes` quitado** — justo el agujero
     * que esta clase existe para cazar.
     */
    private function eventosInternos(string $token, int $cuantos = 3): array
    {
        $anio = $this->anioDelToken($token);
        $ids = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $ids[] = DB::table('calendario')->insertGetId([
                'title' => 'Reunión interna de prueba '.$i,
                'solo_profes' => 1,
                'allDay' => 1,
                'start' => $anio.'-07-0'.($i + 1).' 08:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /** Los ids que devuelve la ruta con ese token y ese cuerpo. */
    private function idsQueVe(string $token, array $cuerpo): array
    {
        $r = $this->withToken($token)->putJson('/api/calendario/this-year', $cuerpo);

        $r->assertStatus(200);

        return array_map(fn ($e) => (int) $e['id'], $r->json());
    }

    /**
     * **Un alumno no ve los internos aunque diga que es profesor.**
     *
     * Se comparan **las dos respuestas del mismo token** y se mira si los que
     * aparecen de más son justamente los internos. Comparar contra un número
     * mediría el seed.
     */
    public function test_un_alumno_no_ve_los_internos_diciendo_que_es_profesor(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);
        $internos = $this->eventosInternos($token);

        $sinBandera = $this->idsQueVe($token, []);

        $this->assertSame([], array_values(array_intersect($internos, $sinBandera)),
            'Sin bandera ya se colaban los internos: entonces el interruptor no se respeta y esto es otra cosa.');

        $this->olvidarControladores();

        $conBandera = $this->idsQueVe($token, ['is_prof_admin' => 'true']);

        $this->assertSame([], array_values(array_intersect($internos, $conBandera)),
            'El alumno recibió los eventos `solo_profes = 1` diciendo en el cuerpo que era profesor.');

        $this->assertSame($sinBandera, $conBandera,
            'La bandera del cuerpo sigue cambiando lo que ve un alumno.');
    }

    /**
     * **Y un profesor los sigue viendo, mande lo que mande.**
     *
     * Sin esta mitad el arreglo podría ser «no enseñar los internos a nadie» y
     * salir verde: apagaría el calendario interno del colegio y nadie lo vería
     * hasta que alguien preguntara por una reunión.
     *
     * Se golpea **sin** la bandera a propósito: lo que se comprueba es que el
     * permiso sale del token y ya no del cuerpo.
     */
    public function test_un_profesor_ve_los_internos_sin_mandar_nada(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $internos = $this->eventosInternos($token);

        $ve = $this->idsQueVe($token, []);

        $this->assertSame($internos, array_values(array_intersect($internos, $ve)),
            'El profesor dejó de ver los eventos internos: el arreglo apagó el calendario del colegio.');
    }

    /**
     * **Un administrativo sin superusuario NO los ve** — y ésta es la mitad que
     * decidió cuál de los dos criterios era el correcto.
     *
     * El candidato obvio era el de `ExigirPersonal` —«personal es el que no es
     * alumno ni acudiente»—, y razonando parecía el bueno: el front manda
     * `IS_PROF_ADMIN = hasRoleOrPerm(['admin', 'profesor'])`, un criterio de
     * **rol**, y «rol Admin sin `is_superuser`» es un conjunto que existe en el
     * esquema. **Contado en la base, no lo es aquí**: de las 20 cuentas de tipo
     * `Usuario`, **10 son superusuario y tienen el rol `Admin`, y las otras 10 no
     * tienen ninguno de los dos**. Los dos conjuntos coinciden.
     *
     * Así que el `if` que ya usan las otras cuatro rutas del controlador
     * —`tipo == 'Profesor' || is_superuser`— **reproduce exactamente lo que ve hoy
     * cada persona**, y el de «no es familia» habría **ampliado** el calendario
     * interno a diez cuentas administrativas. Ampliar no es arreglar.
     *
     * Este caso fija lo que hay hoy. **Si `solo_profes` debe significar «solo
     * profesores» o «solo personal» es una pregunta para Joseth**, y está anotada:
     * el día que se conteste, es este caso el que cae.
     */
    public function test_un_administrativo_sin_superusuario_no_ve_los_internos(): void
    {
        $usuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($usuario, 'El seed necesita un administrativo sin superusuario.');

        $token = $this->tokenDe($usuario->username);
        $internos = $this->eventosInternos($token);

        $ve = $this->idsQueVe($token, []);

        $this->assertSame([], array_values(array_intersect($internos, $ve)),
            'Un administrativo sin superusuario empezó a ver los eventos internos. '.
            'Si es a propósito, `solo_profes` pasó a significar «solo personal»: anótese la decisión.');

        $this->olvidarControladores();

        // Y tampoco los ve mandando la bandera, que es lo que sí hacía antes.
        $conBandera = $this->idsQueVe($token, ['is_prof_admin' => 'true']);

        $this->assertSame([], array_values(array_intersect($internos, $conBandera)),
            'La bandera del cuerpo sigue decidiendo para un administrativo.');
    }

    /**
     * **Y un superusuario los sigue viendo**, que es la otra mitad del criterio
     * elegido: sin este caso, «sólo los de tipo Profesor» pasaría igual.
     */
    public function test_un_superusuario_ve_los_internos(): void
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($super, 'El seed necesita un superusuario.');

        $token = $this->tokenDe($super->username);
        $internos = $this->eventosInternos($token);

        $ve = $this->idsQueVe($token, []);

        $this->assertSame($internos, array_values(array_intersect($internos, $ve)),
            'El superusuario dejó de ver los internos: el criterio se estrechó a sólo los de tipo Profesor.');
    }

    /**
     * Un acudiente tampoco, que es la otra mitad de «la familia».
     */
    public function test_un_acudiente_no_ve_los_internos(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Acudiente')->username);
        $internos = $this->eventosInternos($token);

        $ve = $this->idsQueVe($token, ['is_prof_admin' => 'true']);

        $this->assertSame([], array_values(array_intersect($internos, $ve)),
            'El acudiente recibió los eventos internos del colegio.');
    }
}
