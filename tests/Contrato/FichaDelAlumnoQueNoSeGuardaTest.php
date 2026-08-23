<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §118–119 — `alumnos/update`: la ruta más llamada del sistema, y lo que hace de
 * verdad con la ficha.
 *
 * Es el método que más columnas pisa del repo —**23**— y sobre la tabla más
 * sensible. Las tres cosas que salieron de aquí no se ven leyéndolo, y las tres
 * salen de **ejecutarlo con cuerpos distintos y comparar la fila, no la
 * respuesta**:
 *
 * | Cuerpo | Qué contesta | Qué escribe |
 * |---|---|---|
 * | **sin `username`** | 200 con los cambios dentro | **nada** (§118) |
 * | con `username` | 200 | la ficha, **pisando lo que no venga** (§119) |
 * | lo que devuelve `alumnos/show` | **500** | nada (§119) |
 *
 * La primera es la que importa: **el JSON de la respuesta trae los valores
 * nuevos y la base se queda con los viejos.** Quien guarda ve «Alumno
 * actualizado correctamente», recarga, y su cambio no está.
 *
 * `respuestas-que-mienten.py` da **un solo sitio** y este no es ese. No es un
 * fallo de la herramienta: busca métodos que **frenen** la escritura y contesten
 * 200 igual, y aquí no hay nada que frene — **el `save()` sencillamente no está
 * en ese camino**. Es la tercera ceguera de detector de la noche, y la tercera
 * vez que la señal buscada no es la forma que tiene el fallo.
 */
class FichaDelAlumnoQueNoSeGuardaTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($super, 'El seed no tiene superusuario.');

        return $this->tokenDe($super->username);
    }

    /** Un alumno con cuenta, y la ficha rellena para que se note lo que se pierde. */
    private function alumnoConLaFichaLlena(): object
    {
        $alumno = DB::selectOne('SELECT a.id, a.user_id, a.nombres, a.apellidos, a.documento,
                a.ciudad_nac, a.ciudad_doc, a.tipo_doc, u.username
            FROM alumnos a INNER JOIN users u ON u.id = a.user_id
            WHERE a.deleted_at IS NULL AND u.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed necesita un alumno con cuenta.');

        DB::table('alumnos')->where('id', $alumno->id)->update([
            'no_matricula' => 'M000999', 'eps' => 'SURA', 'barrio' => 'Laureles',
            'estrato' => 3, 'celular' => '3001112233', 'religion' => 'Adventista',
            'facebook' => 'fb/prueba', 'deuda' => 150000, 'direccion' => 'Calle 1',
            'telefono' => '6041112233', 'email' => 'alumno@correo.com',
        ]);

        return $alumno;
    }

    /** El cuerpo mínimo que atraviesa `sanarInputAlumno` sin reventar. */
    private function cuerpoMinimo(object $alumno, array $extra = []): array
    {
        return array_merge([
            'nombres' => $alumno->nombres,
            'apellidos' => $alumno->apellidos,
            'documento' => $alumno->documento,
            'ciudad_nac' => ['id' => $alumno->ciudad_nac],
            'ciudad_doc' => ['id' => $alumno->ciudad_doc],
            'tipo_doc' => ['id' => $alumno->tipo_doc],
            'tipo_sangre' => ['sangre' => 'O+'],
            'grupo' => ['id' => null],
        ], $extra);
    }

    /**
     * §118 — Sin `username` en el cuerpo, **contesta 200 con los cambios dentro y
     * no guarda nada**.
     *
     * `putUpdate` asigna las 23 columnas sobre el modelo y **el `$alumno->save()`
     * vive dentro de los dos `if (… Request::has('username'))`**. Sin esa clave no
     * se ejecuta ninguno de los dos, así que el método devuelve el modelo
     * modificado **en memoria** —que es lo que Laravel serializa— y la fila no se
     * toca.
     *
     * La respuesta no solo no avisa: **afirma lo contrario**, porque el JSON trae
     * los valores nuevos.
     *
     * **No se arregla**, y el porqué es el mismo de `mis-actividades/guardar`
     * (§104): añadir el `save()` que falta **enciende de golpe el pisado de las 23
     * columnas** en un camino donde hoy no se escribe nada. Se convertiría una
     * respuesta que miente en una pérdida de datos silenciosa, y en la ficha del
     * alumno. Las dos cosas —el `save()` y conservar los ausentes con
     * `CamposQueVinieron`— van juntas o ninguna. PARA JOSETH.
     */
    public function test_sin_username_contesta_que_guardo_y_no_guarda(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConLaFichaLlena();

        DB::table('alumnos')->where('id', $alumno->id)->update(['nombres' => 'Nombre Original']);

        $r = $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpoMinimo($alumno, ['nombres' => 'Nombre Cambiado']));

        $r->assertStatus(200);
        $this->assertSame('Nombre Cambiado', $r->json('nombres'),
            'La respuesta trae el valor nuevo: es el modelo en memoria.');

        $this->assertSame('Nombre Original',
            DB::table('alumnos')->where('id', $alumno->id)->value('nombres'),
            'Y la fila se quedó como estaba. El 200 no describe lo que pasó.');

        // Y tampoco pisó nada, que es la otra mitad del mismo hecho.
        $this->assertSame('SURA', DB::table('alumnos')->where('id', $alumno->id)->value('eps'));
        $this->assertSame('M000999', DB::table('alumnos')->where('id', $alumno->id)->value('no_matricula'));
    }

    /**
     * §119 — Con `username`, sí guarda: y entonces **pisa lo que no venga**.
     *
     * Es el mismo cuerpo, con una clave más, y el resultado es el contrario: la
     * fila se escribe entera y las columnas que el cuerpo no trae se van a null.
     * `eps` es la que se enseña aquí porque es la que un colegio necesita el día
     * que hay que llamar a una ambulancia.
     *
     * La [§68](../../docs/migracion/05-codigo-muerto-y-roto.md) cerró este método
     * **por el lado de `users`** —`is_active`, `email2`, `password`, con
     * `CamposQueVinieron` capturado dos líneas antes— y **dejó las 23 columnas de
     * `alumnos` sin tocar**. La herramienta está ahí, en el mismo método, ya
     * llamada. Es la cuarta vez esta noche que una serie se cierra sobre media
     * población.
     */
    public function test_con_username_guarda_y_pisa_lo_que_no_viene(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConLaFichaLlena();

        $r = $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpoMinimo($alumno, [
                'nombres' => 'Nombre Con Username',
                'username' => $alumno->username,
            ]));

        $r->assertStatus(200);

        $fila = DB::table('alumnos')->where('id', $alumno->id)->first();

        $this->assertSame('Nombre Con Username', $fila->nombres, 'Lo que sí se manda, se guarda.');

        // Y lo que no se mandó, que era media ficha.
        $this->assertNull($fila->eps, 'La EPS del alumno se fue por no venir en el cuerpo.');
        $this->assertNull($fila->no_matricula);
        $this->assertNull($fila->barrio);
        $this->assertNull($fila->estrato);
        $this->assertNull($fila->celular);
        $this->assertNull($fila->religion);
        $this->assertNull($fila->facebook);
        $this->assertNull($fila->deuda);
    }

    /**
     * Y los dos únicos con defecto no se van a null — pero **el defecto no es el
     * valor de la fila**, que es lo que la §68 llamó «tiene defecto no es está a
     * salvo».
     *
     * `sexo` se pisa con `'M'` y `pazysalvo` con `true`. O sea que guardar la
     * ficha de una alumna sin mandar el sexo **la convierte en hombre**, y guardar
     * la de un alumno con deuda **lo pone a paz y salvo**. Salen «a salvo» en el
     * detector porque tienen segundo argumento.
     */
    public function test_los_dos_defectos_tampoco_conservan_el_valor(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConLaFichaLlena();

        DB::table('alumnos')->where('id', $alumno->id)->update(['sexo' => 'F', 'pazysalvo' => 0]);

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id,
            $this->cuerpoMinimo($alumno, ['username' => $alumno->username]))->assertStatus(200);

        $fila = DB::table('alumnos')->where('id', $alumno->id)->first();

        $this->assertSame('M', $fila->sexo, 'Sin mandar el sexo, la ficha de una alumna pasa a "M".');
        $this->assertSame(1, (int) $fila->pazysalvo, 'Y quien debía queda a paz y salvo.');
    }

    /**
     * §119 — **La ida y la vuelta no encajan**: lo que devuelve `alumnos/show` no
     * se le puede mandar a `alumnos/update`.
     *
     * Es el viaje completo, que es lo que hace la pantalla: pedir la ficha,
     * cambiar un campo, devolverla. `show` entrega `tipo_doc`, `ciudad_nac`,
     * `ciudad_doc` y `tipo_sangre` **planos**, y `putUpdate` indexa
     * `Request::input('tipo_sangre')['sangre']`. Con el valor plano —o con null,
     * que es lo que hay en el seed— eso es un aviso de PHP, y Laravel arranca con
     * `error_reporting(-1)`, así que el `catch` de abajo lo convierte en **500**.
     *
     * O sea: **la pantalla tiene que reconstruir a mano cuatro campos que acaba de
     * recibir**, y ningún test lo veía porque todos los cuerpos de prueba estaban
     * escritos ya reconstruidos. Es la §69 mirada desde el otro extremo del viaje.
     */
    public function test_lo_que_devuelve_show_no_se_le_puede_mandar_a_update(): void
    {
        $token = $this->tokenDeSuperusuario();
        $alumno = $this->alumnoConLaFichaLlena();
        $antes = DB::table('alumnos')->where('id', $alumno->id)->first();

        $ficha = $this->withToken($token)->putJson('/api/alumnos/show', ['id' => $alumno->id])
            ->assertStatus(200)->json();
        // `putShow` envuelve la ficha, y la forma es parte del contrato: se
        // desenvuelve aquí igual que lo hace la pantalla.
        if (isset($ficha[0]) && is_array($ficha[0])) {
            $ficha = $ficha[0];
        }
        if (isset($ficha['alumno']) && is_array($ficha['alumno'])) {
            $ficha = $ficha['alumno'];
        }
        $this->assertIsArray($ficha);

        $this->assertArrayHasKey('tipo_sangre', $ficha);
        $this->assertArrayHasKey('username', $ficha, 'Show sí devuelve el username, así que el save se ejecutaría.');

        $this->withToken($token)->putJson('/api/alumnos/update/'.$alumno->id, $ficha)
            ->assertStatus(500);

        $this->assertEquals($antes, DB::table('alumnos')->where('id', $alumno->id)->first(),
            'Revienta antes de guardar: la ficha se queda entera.');
    }

    /** El guard de arriba sigue en pie: quien no puede editar alumnos recibe 403. */
    public function test_una_familia_no_edita_la_ficha(): void
    {
        $alumno = $this->alumnoConLaFichaLlena();
        $antes = DB::table('alumnos')->where('id', $alumno->id)->first();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->putJson('/api/alumnos/update/'.$alumno->id,
                    $this->cuerpoMinimo($alumno, ['nombres' => 'X']))
                ->assertStatus(403);
        }

        $this->assertEquals($antes, DB::table('alumnos')->where('id', $alumno->id)->first());
    }
}
