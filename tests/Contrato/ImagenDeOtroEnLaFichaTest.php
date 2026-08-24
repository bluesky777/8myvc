<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Quién escribe en la ficha de otro por el lado de las imágenes — §99.
 *
 * Las dos rutas del lote llevan `persona.propia`, y ese guard **dice por escrito
 * lo que no decide**: «lo que puede hacer el personal del colegio entre sí queda
 * como está». Lo que no está escrito en ninguna parte es que **un alumno no es
 * personal del colegio**, y `images-users/cambiar-imagen-un-usuario` no lee la
 * imagen que le llega en el cuerpo: la reasigna.
 *
 * Se mide **el viaje de ida y vuelta** y no la respuesta: se pide la imagen ajena
 * y luego se vuelve a preguntar por ella desde la cuenta de su dueño. La
 * respuesta del cambio es 200 en los dos casos; lo que las separa es que en uno
 * la imagen sigue en `myimages` de su dueño y en el otro ya no está.
 */
class ImagenDeOtroEnLaFichaTest extends CasoDeContrato
{
    /**
     * Una familia no alcanza la imagen de otro: la cierra `persona.propia`.
     *
     * El guard recoge `imagen_id` del cuerpo —está en su lista de claves desde la
     * §15— y comprueba de quién es antes de dejar pasar. Es la mitad cerrada, y
     * va primero porque es la que demuestra que el agujero de abajo **no es del
     * guard**, sino de a quién se le aplica.
     */
    public function test_un_alumno_no_se_lleva_la_imagen_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($alumno->username);

        $ajena = $this->imagenPrivadaDe($this->usuarioDeTipo('Profesor')->id);

        $r = $this->withToken($token)->putJson(
            '/api/images-users/cambiar-imagen-un-usuario/'.$alumno->id,
            ['imagen_id' => $ajena]
        );

        $this->assertSame(403, $r->status(), 'Un alumno se llevó la imagen de un profesor.');

        $this->assertSame(
            (int) $this->usuarioDeTipo('Profesor')->id,
            (int) DB::table('images')->where('id', $ajena)->value('user_id'),
            'El rechazo cambió el dueño de la imagen antes de contestar que no.'
        );
    }

    /**
     * Un profesor sí, y la imagen deja de estar en `myimages` de su dueño.
     *
     * `putCambiarImagenUnUsuario()` escribe `images.user_id = <quien pide>` sin
     * preguntar de quién era. Es la misma operación que `move-img-to-me`, que la
     * §15 ya cerró **para las familias** por su nombre de clave; aquí la clave se
     * llama como toca y el guard la ve, pero **al personal lo deja pasar antes de
     * mirar ninguna clave**.
     *
     * Lo que se fija no es el 200: es que después `GET myimages` de la cuenta del
     * alumno **ya no la trae**, porque filtra por `user_id`. Y no es un descuido
     * de esa lectura: es la definición de a quién pertenece.
     *
     * Se cierra **por la operación y no por la ruta**: las tres `cambiar-*` de
     * este controlador hacen la misma escritura y las tres se comprueban aquí,
     * aunque sólo una estuviera en la lista del lote.
     */
    public function test_un_profesor_no_se_lleva_la_imagen_privada_de_un_alumno(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $profesor = $this->usuarioDeTipo('Profesor');
        $suya = $this->imagenPrivadaDe($alumno->id);

        $delAlumno = $this->withToken($this->tokenDe($alumno->username))->getJson('/api/myimages');
        $delAlumno->assertStatus(200);
        $this->assertContains($suya, $this->idsPrivadas($delAlumno->json()),
            'La imagen del alumno no salía en sus imágenes ni antes de nada.');

        $r = $this->withToken($this->tokenDe($profesor->username))->putJson(
            '/api/images-users/cambiar-imagen-un-usuario/'.$profesor->id,
            ['imagen_id' => $suya]
        );

        $this->assertSame(403, $r->status(),
            'Un profesor se llevó la imagen privada de un alumno — §99.');

        $this->assertSame(
            (int) $alumno->id,
            (int) DB::table('images')->where('id', $suya)->value('user_id'),
            'El rechazo cambió el dueño antes de contestar que no.'
        );

        // El viaje de vuelta, que es lo que lo hace un hallazgo y no una línea de SQL.
        $otraVez = $this->withToken($this->tokenDe($alumno->username))->getJson('/api/myimages');
        $this->assertContains($suya, $this->idsPrivadas($otraVez->json()),
            'El alumno perdió su imagen de `myimages`.');
    }

    /**
     * Las tres hermanas hacen la misma escritura y se cierran juntas.
     *
     * `cambiar-imagen-un-usuario`, `cambiar-foto-un-usuario` y
     * `cambiar-firma-un-profe` escriben las tres `images.user_id = <destino>`.
     * Sólo la primera estaba en la lista del lote; cerrar esa y dejar las otras
     * dos es exactamente lo que la §89 llama arreglar el sitio que se mira en vez
     * de la operación. Van en la misma tabla por eso.
     */
    public function test_las_tres_hermanas_rechazan_la_imagen_de_un_tercero(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $rutas = [
            'images-users/cambiar-imagen-un-usuario/'.$profesor->id,
            'images-users/cambiar-foto-un-usuario/'.$profesor->id,
            'images-users/cambiar-firma-un-profe/'.$this->profesorDe($profesor->id),
        ];

        foreach ($rutas as $ruta) {
            $ajena = $this->imagenPrivadaDe($alumno->id);

            $this->assertSame(403,
                $this->withToken($token)->putJson('/api/'.$ruta, ['imagen_id' => $ajena])->status(),
                "{$ruta} aceptó la imagen privada de un alumno.");

            $this->assertSame(
                (int) $alumno->id,
                (int) DB::table('images')->where('id', $ajena)->value('user_id'),
                "{$ruta} cambió el dueño antes de contestar que no."
            );
        }
    }

    /**
     * Y el camino que el front sí sabe pedir sigue abierto, que es la otra mitad.
     *
     * El botón manda `$ctrl.dato.selectedImg`, que sale de `imagenes_privadas`
     * —las de quien pide— y el confirm avisa de que **se la quita de su lista**.
     * Sin este test, un `abort(403)` puesto arriba del método daría verde el de
     * al lado y habría apagado la pestaña entera del gestor de imágenes.
     */
    public function test_regalar_una_imagen_propia_sigue_funcionando(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $alumno = $this->usuarioDeTipo('Alumno');
        $mia = $this->imagenPrivadaDe($profesor->id);

        $this->withToken($this->tokenDe($profesor->username))->putJson(
            '/api/images-users/cambiar-imagen-un-usuario/'.$alumno->id,
            ['imagen_id' => $mia]
        )->assertStatus(200);

        $this->assertSame((int) $alumno->id,
            (int) DB::table('images')->where('id', $mia)->value('user_id'),
            'Regalar la imagen propia dejó de cambiarle el dueño.');

        $this->assertSame((int) $mia,
            (int) DB::table('users')->where('id', $alumno->id)->value('imagen_id'),
            'Regalar la imagen propia dejó de ponérsela al destino.');
    }

    /**
     * Y la imagen del colegio también, que es la que no tiene dueño.
     *
     * `images.user_id` es nullable porque las imágenes públicas del colegio no son
     * de nadie, y la pestaña las ofrece en `imagenes_publicas`. Si el criterio
     * fuera sólo «tuya», el gestor perdería esa mitad sin que ningún test lo
     * dijera.
     */
    public function test_una_imagen_del_colegio_sigue_pudiendo_asignarse(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $delColegio = $this->imagenPrivadaDe($profesor->id);
        DB::update('UPDATE images SET user_id = NULL, publica = 1 WHERE id = ?', [$delColegio]);

        $this->withToken($this->tokenDe($profesor->username))->putJson(
            '/api/images-users/cambiar-imagen-un-usuario/'.$profesor->id,
            ['imagen_id' => $delColegio]
        )->assertStatus(200);
    }

    /**
     * Privatizar el logo del colegio contesta 200 y no privatiza nada.
     *
     * La rama del logo devuelve `['imagen' => ['is_logo_of_year' => <año>]]`, que
     * **no es la forma que devuelve el éxito** —el éxito devuelve el nombre del
     * fichero, una cadena suelta—. O sea que el front puede distinguirlas, y por
     * eso esto no es una de las «respuestas que mienten»: es una negativa con
     * datos, no un éxito falso. Se fija la forma porque es lo único que la separa.
     */
    public function test_privatizar_el_logo_del_colegio_no_lo_privatiza(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $logo = DB::selectOne('SELECT logo_id, year FROM years
            WHERE logo_id IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($logo, 'El seed no tiene ningún año con logo.');

        $antes = DB::table('images')->where('id', $logo->logo_id)->value('publica');

        $r = $this->withToken($token)->putJson('/api/myimages/privatizar-imagen/'.$logo->logo_id, []);
        $r->assertStatus(200);

        $this->assertSame(['imagen' => ['is_logo_of_year' => $logo->year]], $r->json(),
            'Cambió la forma con la que se rechaza privatizar el logo — la lee el front.');

        $this->assertSame($antes, DB::table('images')->where('id', $logo->logo_id)->value('publica'),
            'Contestó que es el logo y la privatizó igual.');
    }

    /**
     * La pareja publicar/privatizar no pide lo mismo a una familia, y está bien.
     *
     * Publicar le contesta 403 desde la §37 —hacer pública una imagen es una
     * decisión del colegio— y privatizar la suya la deja. Se fijan juntas porque
     * una asimetría sin escribir es indistinguible de un descuido, y ésta ya se
     * juzgó: **lo que se cerró fue publicar, no la pareja.**
     */
    public function test_una_familia_privatiza_lo_suyo_pero_no_lo_publica(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $token = $this->tokenDe($alumno->username);
        $suya = $this->imagenPrivadaDe($alumno->id);

        $this->assertSame(403,
            $this->withToken($token)->putJson('/api/myimages/publicar-imagen/'.$suya, [])->status(),
            'Una familia publicó una imagen.');

        DB::update('UPDATE images SET publica = 1 WHERE id = ?', [$suya]);

        $this->assertSame(200,
            $this->withToken($token)->putJson('/api/myimages/privatizar-imagen/'.$suya, [])->status(),
            'Una familia dejó de poder privatizar lo suyo.');

        $this->assertNull(DB::table('images')->where('id', $suya)->value('publica'),
            'Contestó 200 y la imagen siguió pública.');
    }

    /**
     * La población entera de «ponerle una imagen a otro», y por qué son dos reglas.
     *
     * Greppeada la operación en todo `app/`, hay **siete** métodos que le ponen a
     * una persona una imagen que llega por el cuerpo, y se parten en dos grupos
     * que hacen cosas distintas:
     *
     * - **Tres cambian de dueño** (`images.user_id = <destino>`): las
     *   `images-users/cambiar-*`. Ésas son las del §99 y piden que la imagen sea
     *   de quien la regala, porque el dueño original **la pierde de `myimages`**.
     * - **Cuatro solo apuntan** (`users.imagen_id`, `alumnos.foto_id`,
     *   `profesores.foto_id`, `profesores.firma_id`): las `perfiles/cambiar*un*`.
     *   No se lleva nada nadie, y piden `esAdministrativo` desde la §36.
     *
     * Este test fija esa asimetría **a propósito**, porque una asimetría sin
     * escribir es indistinguible de un descuido y ésta ya se juzgó: lo que separa
     * a los dos grupos no es quién llama, es **si el dueño original se queda sin
     * la imagen**. Queda medido, además, que las cuatro de `perfiles` **no
     * comprueban de quién es la imagen que apuntan** — un administrativo puede
     * poner la foto privada de un alumno de avatar de otra cuenta. No se cierra
     * aquí: es la administración de fotos del colegio, y son los diez de siempre.
     */
    public function test_las_cuatro_de_perfiles_apuntan_pero_no_se_llevan_la_imagen(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $suya = $this->imagenPrivadaDe($alumno->id);

        $jefe = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($jefe, 'El seed no tiene ningún superusuario con contexto completo.');

        $this->withToken($this->tokenDe($jefe->username))->putJson(
            '/api/perfiles/cambiarimgunusuario/'.$jefe->id,
            ['imgParaUsuario' => $suya]
        )->assertStatus(200);

        // Apunta...
        $this->assertSame($suya,
            (int) DB::table('users')->where('id', $jefe->id)->value('imagen_id'),
            '`perfiles/cambiarimgunusuario` dejó de apuntar a la imagen que se le manda.');

        // ...pero no se la lleva: el alumno la conserva.
        $this->assertSame((int) $alumno->id,
            (int) DB::table('images')->where('id', $suya)->value('user_id'),
            '`perfiles/cambiarimgunusuario` empezó a cambiar el dueño: entonces entra en '
            .'la regla del §99 y hay que cerrarla también.');

        $otraVez = $this->withToken($this->tokenDe($alumno->username))->getJson('/api/myimages');
        $this->assertContains($suya, $this->idsPrivadas($otraVez->json()),
            'El alumno perdió su imagen por una ruta que no cambia de dueño.');
    }

    /** Un profesor cualquiera no llega a ninguna de las cuatro: piden administrativo (§36). */
    public function test_las_cuatro_de_perfiles_siguen_pidiendo_administrativo(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);
        $suya = $this->imagenPrivadaDe($profesor->id);

        $fichaProfe = $this->profesorDe($profesor->id);
        $alumnoId = (int) DB::table('alumnos')->whereNotNull('user_id')->orderBy('id')->value('id');

        $rutas = [
            'perfiles/cambiarimgunusuario/'.$profesor->id => ['imgParaUsuario' => $suya],
            'perfiles/cambiarimgunalumno/'.$alumnoId => ['imgOficialAlumno' => $suya],
            'perfiles/cambiarimgunprofe/'.$fichaProfe => ['imgOficialProfe' => $suya],
            'perfiles/cambiarfirmaunprofe/'.$fichaProfe => ['imgFirmaProfe' => $suya],
        ];

        foreach ($rutas as $ruta => $cuerpo) {
            $this->assertSame(403,
                $this->withToken($token)->putJson('/api/'.$ruta, $cuerpo)->status(),
                "`{$ruta}` dejó de pedir administrativo — §36.");
        }
    }

    /**
     * La clave de la hermana ya no borra la firma en silencio — §168.
     *
     * `perfiles/cambiarfirmaunprofe` lee `imgFirmaProfe`; su hermana
     * `images-users/cambiar-firma-un-profe` lee `imagen_id`. Una llamada a la
     * primera con la clave de la segunda hacía
     * `$profesor->firma_id = Request::input('imgFirmaProfe')` → **null**, guardaba,
     * y devolvía `ImageModel::find(null)`: **200 con cuerpo `null`**. La firma
     * desaparece del boletín —la leen `Year::datos()` para rector y secretaria, y
     * `Grupo` para el titular— y nadie ve un error.
     *
     * **Se comprueba en negativo**: no basta con que la respuesta sea 422, hay que
     * ver que `firma_id` **no cambió**. Un guard que aborta después de escribir
     * responde 422 igual, y aquí lo que importa es la columna.
     */
    public function test_la_clave_de_la_hermana_no_borra_la_firma(): void
    {
        $admin = $this->tokenDelAdministrativo();
        $profesor = $this->usuarioDeTipo('Profesor');
        $ficha = $this->profesorDe($profesor->id);
        $imagen = $this->imagenPrivadaDe($profesor->id);

        // Se le pone una firma de verdad primero: sin nada que perder, el caso no
        // se ve.
        $this->withToken($admin)
            ->putJson('/api/perfiles/cambiarfirmaunprofe/'.$ficha, ['imgFirmaProfe' => $imagen])
            ->assertStatus(200);

        $antes = DB::table('profesores')->where('id', $ficha)->value('firma_id');
        $this->assertNotNull($antes, 'No se pudo dejar una firma puesta para el caso.');

        $this->withToken($admin)
            ->putJson('/api/perfiles/cambiarfirmaunprofe/'.$ficha, ['imagen_id' => $imagen])
            ->assertStatus(422);

        $this->assertSame($antes, DB::table('profesores')->where('id', $ficha)->value('firma_id'),
            'La clave de la hermana borró la firma de todas formas.');
    }

    /**
     * Y la otra mitad, que es la que hace que el arreglo no sea un candado:
     * **vaciar la firma a propósito sigue funcionando.**
     *
     * `CamposQueVinieron` distingue «no vino» de «vino vacío», y su hermana admite
     * el vaciado con `$img_id ? $img_id : null`. Sin este test, cerrar el borrado
     * accidental habría cerrado también el querido y nadie se enteraría hasta que
     * alguien necesitara quitar una firma.
     *
     * **Pasa también con el código viejo, y se dice a propósito**: comprobado al
     * revés, de los once cae **uno** —el de arriba— y éste no. No fija el fallo:
     * fija que el arreglo no se convierta en un candado.
     */
    public function test_vaciar_la_firma_a_proposito_sigue_pudiendose(): void
    {
        $admin = $this->tokenDelAdministrativo();
        $profesor = $this->usuarioDeTipo('Profesor');
        $ficha = $this->profesorDe($profesor->id);
        $imagen = $this->imagenPrivadaDe($profesor->id);

        $this->withToken($admin)
            ->putJson('/api/perfiles/cambiarfirmaunprofe/'.$ficha, ['imgFirmaProfe' => $imagen])
            ->assertStatus(200);

        $this->withToken($admin)
            ->putJson('/api/perfiles/cambiarfirmaunprofe/'.$ficha, ['imgFirmaProfe' => null])
            ->assertStatus(200);

        $this->assertNull(DB::table('profesores')->where('id', $ficha)->value('firma_id'),
            'Vaciar la firma a propósito dejó de funcionar.');
    }

    /** El superusuario del seed con contexto completo, que es quien pasa `esAdministrativo`. */
    private function tokenDelAdministrativo(): string
    {
        $jefe = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($jefe, 'El seed no tiene ningún superusuario con contexto completo.');

        return $this->tokenDe($jefe->username);
    }

    /** La ficha de profesor de una cuenta, que es lo que pide `cambiar-firma-un-profe`. */
    private function profesorDe(int $userId): int
    {
        $fila = DB::selectOne('SELECT id FROM profesores WHERE user_id = ? AND deleted_at IS NULL', [$userId]);

        $this->assertNotNull($fila, 'Esa cuenta de profesor no tiene ficha en `profesores`.');

        return (int) $fila->id;
    }

    /** Una imagen privada recién creada de esa cuenta. Se deshace con la transacción del test. */
    private function imagenPrivadaDe(int $userId): int
    {
        DB::insert(
            'INSERT INTO images (nombre, user_id, publica, created_by, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, ?)',
            ['prueba-lote-e.png', $userId, $userId, '2026-08-23 01:00:00', '2026-08-23 01:00:00']
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Los ids de `imagenes_privadas`, que es lo que `GET myimages` filtra por dueño.
     *
     * @param  array<string, mixed>|null  $cuerpo
     * @return array<int, int>
     */
    private function idsPrivadas(?array $cuerpo): array
    {
        $privadas = $cuerpo['imagenes_privadas'] ?? [];

        return array_map(static fn ($i) => (int) $i['id'], $privadas);
    }
}
