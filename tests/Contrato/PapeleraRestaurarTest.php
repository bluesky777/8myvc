<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La otra mitad de la papelera: quién saca de ella lo que otro metió.
 *
 * El 21 ago 2026 se cerraron las rutas destructivas de la papelera y nació
 * `App\Support\Autoriza` para tener el criterio en un solo sitio. Su propia
 * cabecera dice de qué venía: *«grupos, perfiles, profesores, years y editnota
 * no comprobaban nada»*.
 *
 * **Son exactamente los mismos cinco cuyo `restore` seguía sin comprobar nada.**
 * Cada operación de la papelera es una pareja —`forcedelete` y `restore`, uno al
 * lado del otro en el mismo controlador— y aquella revisión cerró una mitad de
 * cada una. A la que devuelve no se le preguntó, y por eso nadie volvió: la serie
 * constaba cerrada. Es la lección de la §54 otra vez, con otro nombre.
 *
 * Lo que fija esta clase es **la pareja entera**, no cada ruta suelta: que las dos
 * mitades pidan lo mismo. Un test por ruta habría dejado pasar justo esto.
 */
class PapeleraRestaurarTest extends CasoDeContrato
{
    /**
     * Las dos mitades de cada pareja piden lo mismo.
     *
     * Un profesor cualquiera —de los 51 que hay en la copia de producción— se
     * queda fuera de las dos, y el superusuario entra en las dos. Se recorren
     * juntas a propósito: el fallo no era que una ruta no comprobara, era que
     * **la de al lado sí**, y eso solo se ve poniéndolas en la misma tabla.
     *
     * `years` es la de más alcance —59 tablas en cascada si se borra de verdad—
     * pero el restaurar tiene su propia trampa, y no es de permisos: devolver un
     * año encendido al lado del que ya lo está deja dos actuales y **en qué año
     * amanece el colegio lo decide el orden de las filas de MySQL**. La cubre
     * `YearsTest`; aquí se mira sólo quién puede llamarla.
     */
    public function test_las_dos_mitades_de_cada_pareja_piden_lo_mismo(): void
    {
        $profesor = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $jefe = $this->tokenDeUnSuperusuario();

        foreach ($this->parejas() as $nombre => $par) {
            $id = $par['enPapelera']();

            // `assertStatus()` acepta **un** parámetro: el mensaje de un segundo se
            // traga sin decir nada, y un rojo sin explicación en un bucle de cuatro
            // parejas no dice cuál falló. Lo cazó larastan.
            $rechazo = $this->withToken($profesor)->putJson('/api/'.$par['restore'].'/'.$id, []);
            $this->assertSame(403, $rechazo->status(),
                "Un profesor cualquiera restauró {$nombre} desde la papelera.");

            $this->assertNotNull($this->borradoEn($par['tabla'], $id),
                "El rechazo de {$nombre} restauró la fila antes de contestar que no.");

            $paso = $this->withToken($jefe)->putJson('/api/'.$par['restore'].'/'.$id, []);
            $this->assertSame(200, $paso->status(),
                "El superusuario no pudo restaurar {$nombre}.");

            $this->assertNull($this->borradoEn($par['tabla'], $id),
                "{$nombre} contestó 200 y no salió de la papelera.");
        }
    }

    /**
     * Restaurar una asignatura obedece al año del que pide, como su listado.
     *
     * Ésta no es de permisos y por eso va aparte: `asignaturas/papelera` filtra
     * `g.year_id = ?` con el año del token y `asignaturas/restaurar` hacía
     * `UPDATE asignaturas SET deleted_at=NULL WHERE id=?` con el id que llegara
     * en el cuerpo, sin mirar nada. O sea que el listado enseñaba un año y el
     * botón alcanzaba todos.
     *
     * No lo llama ningún cliente —la papelera del front tiene tres rejillas y
     * ninguna es ésta—, así que era una mina y no un fallo vivo. Se cierra igual,
     * y con 404 y no 403: para quien pide, una asignatura de otro año no es que
     * esté prohibida, es que no está en su papelera.
     */
    public function test_restaurar_una_asignatura_de_otro_ano_no_la_encuentra(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $suYear = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id])->year_id;

        $ajena = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id <> ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suYear]);

        $this->assertNotNull($ajena, 'El seed necesita una asignatura de otro año para que este test mida algo.');

        DB::update('UPDATE asignaturas SET deleted_at = ? WHERE id = ?', ['2026-08-22 07:00:00', $ajena->id]);

        // Y no sale en su papelera, que es la mitad que ya funcionaba: si saliera,
        // el test de abajo estaría midiendo dos cosas distintas.
        $papelera = $this->withToken($token)->getJson('/api/asignaturas/papelera');
        $papelera->assertStatus(200);
        $this->assertNotContains((int) $ajena->id,
            array_map('intval', array_column($papelera->json(), 'asignatura_id')),
            'La papelera de asignaturas empezó a enseñar otros años, y entonces restaurarlos no sería el fallo.');

        $this->withToken($token)->putJson('/api/asignaturas/restaurar', ['asignatura_id' => $ajena->id])
            ->assertStatus(404);

        $this->assertNotNull(DB::table('asignaturas')->where('id', $ajena->id)->value('deleted_at'),
            'La asignatura de otro año salió de la papelera de todas formas.');

        // Y la del año propio sigue volviendo, que es para lo que existe la ruta.
        $propia = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suYear]);

        $this->assertNotNull($propia, 'El seed necesita una asignatura del año del usuario.');

        DB::update('UPDATE asignaturas SET deleted_at = ? WHERE id = ?', ['2026-08-22 07:00:00', $propia->id]);

        $this->withToken($token)->putJson('/api/asignaturas/restaurar', ['asignatura_id' => $propia->id])
            ->assertStatus(200);

        $this->assertNull(DB::table('asignaturas')->where('id', $propia->id)->value('deleted_at'),
            'Restaurar dejó de funcionar para la asignatura del propio año.');
    }

    // ---------------------------------------------------------------- ayudas

    /**
     * Las parejas de la papelera cuya mitad destructiva pide superusuario.
     *
     * `perfiles/restore` está en la lista y no es un duplicado del test: **es un
     * duplicado de la ruta**. `PerfilesController` tiene una copia entera de las
     * dos mitades de grupos bajo otra URL, y cerrar sólo las de `grupos/` dejaba
     * esta puerta abierta — que es literalmente lo que decía el comentario que la
     * §28.4 dejó escrito en su `forcedelete`.
     */
    private function parejas(): array
    {
        return [
            'un grupo' => [
                'restore' => 'grupos/restore',
                'tabla' => 'grupos',
                'enPapelera' => fn () => $this->aLaPapelera('grupos'),
            ],
            'un grupo por la puerta de perfiles' => [
                'restore' => 'perfiles/restore',
                'tabla' => 'grupos',
                'enPapelera' => fn () => $this->aLaPapelera('grupos'),
            ],
            'un profesor' => [
                'restore' => 'profesores/restore',
                'tabla' => 'profesores',
                'enPapelera' => fn () => $this->aLaPapelera('profesores'),
            ],
            'un año' => [
                'restore' => 'years/restore',
                'tabla' => 'years',
                'enPapelera' => fn () => $this->aLaPapelera('years', 'actual = 0'),
            ],
        ];
    }

    /** Manda a la papelera la primera fila viva de la tabla y devuelve su id. */
    private function aLaPapelera(string $tabla, string $extra = '1 = 1'): int
    {
        $fila = DB::selectOne("SELECT id FROM {$tabla} WHERE deleted_at IS NULL AND {$extra} ORDER BY id LIMIT 1");

        $this->assertNotNull($fila, "El seed no tiene ninguna fila viva en {$tabla}.");

        // Se escribe la columna a mano en vez de llamar al `destroy` de cada
        // controlador: lo que se mide aquí es quién restaura, y pasar por cuatro
        // rutas de borrado distintas metería sus propios permisos en el test.
        DB::update("UPDATE {$tabla} SET deleted_at = ? WHERE id = ?", ['2026-08-22 07:00:00', $fila->id]);

        return (int) $fila->id;
    }

    private function borradoEn(string $tabla, int $id): ?string
    {
        return DB::table($tabla)->where('id', $id)->value('deleted_at');
    }

    /**
     * Un token de superusuario.
     *
     * Se elige por `is_superuser` y no por el rol `Admin`: son los mismos diez en
     * esta base —medido en la §28.4— pero lo que el código pregunta es la
     * columna, y un test que eligiera por el rol pasaría por la razón equivocada
     * el día que dejen de coincidir.
     */
    private function tokenDeUnSuperusuario(): string
    {
        $jefe = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($jefe, 'El seed no tiene ningún superusuario con contexto completo.');

        return $this->tokenDe($jefe->username);
    }
}
