<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PerfilesController` no opera sobre la persona que dice. Tres cosas, y una es
 * una mina.
 *
 * El aviso más claro sobre este controlador no está en el backend: está en la
 * cabecera de `PerfilesApi.ts`, escrita por quien migró el front —*«CUIDADO CON
 * ESTE RECURSO. PerfilesController es el más engañoso del backend: cinco de sus
 * métodos —show, destroy, forcedelete, restore, trashed— operan sobre GRUPO, no
 * sobre persona»*—. Esto lo mide desde este lado.
 *
 * **Los tres se fijan, no se arreglan**, y el motivo es el mismo que con
 * `preguntas/edicion`: arreglar el primero **enciende** un guardado que lleva años
 * apagado en los dieciséis colegios, y eso es una decisión del colegio y no un
 * arreglo. Fijar primero es lo que permite verlo cuando se decida.
 *
 * ## 1. `putUpdate` no guarda nunca desde la pantalla de perfil
 *
 * El método tiene cuatro ramas y compara `tipo` contra `'Profesor'`, `'Alumno'`,
 * `'Ac'` y `'Usuario'`. La pantalla de perfil manda los **códigos cortos** del
 * front —los mismos `'Al'`, `'Pr'`, `'Acu'`, `'Usu'` que la
 * [05 §50](../../docs/migracion/05-codigo-muerto-y-roto.md) documentó al mirar
 * `solicitar-cambios`—. Ninguno casa con ninguna rama: el método **cae hasta el
 * final sin entrar en ningún `if`**, devuelve `null` y responde 200 con cuerpo
 * vacío. El botón dice «Datos guardados» y la fila no se toca.
 *
 * Y las cuatro etiquetas no son ni siquiera coherentes entre sí: tres son el
 * nombre largo y la cuarta, `'Ac'`, es un código corto. **Son dos vocabularios
 * mezclados dentro del mismo `switch`**, que es la misma forma de la §50 — leer
 * uno con el diccionario del otro da un hallazgo que no existe, o lo esconde.
 *
 * ## 2. La rama `'Usuario'` coge el modelo equivocado
 *
 * `Acudiente::findOrFail($id)`. No es un fallo de autorización: **es de
 * identidad**. El id se comprueba —la ruta lleva `persona.propia:persona_id`— y
 * después se usa contra **otra tabla**. Es primo del `asked_id` y del `foto_id` de
 * la §53, un paso más allá: allí se leía la fila de otro, aquí se **escribe en la
 * tabla de otra cosa**.
 *
 * ## 3. `perfiles/destroy/{id}` borra un GRUPO
 *
 * `Grupo::findOrFail($id)->delete()`, con `auth.personal`. Hoy no lo dispara nadie
 * **por accidente**: el botón del front depende de `is_superuser` y el `SELECT` de
 * `getUsuariosall` no devuelve esa columna. Una línea añadiéndola enciende un
 * botón que borra grupos. Por eso el aviso vive también **en el código, encima de
 * ese `SELECT`**, y no solo aquí.
 */
class PerfilesEscribeEnOtraTablaTest extends CasoDeContrato
{
    /** Un superusuario, que es quien alcanza estas rutas sin tropezar con los guards. */
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /**
     * Los códigos cortos del front no entran en ninguna rama: 200 y nada escrito.
     *
     * Se prueban los cuatro de golpe porque el fallo es del `switch` entero y no de
     * una rama: si mañana alguien arregla una sola, este test lo dice.
     */
    public function test_los_codigos_cortos_del_front_no_guardan_nada(): void
    {
        $token = $this->tokenDeSuperusuario();

        $profesor = DB::selectOne('SELECT id, nombres FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($profesor, 'El seed necesita un profesor.');

        foreach (['Pr', 'Al', 'Acu', 'Usu', 'Us'] as $codigo) {
            $r = $this->withToken($token)->putJson('/api/perfiles/update/'.$profesor->id, [
                'tipo' => $codigo,
                'persona_id' => $profesor->id,
                'nombres' => 'CAMBIADO POR EL TEST',
                'apellidos' => 'CAMBIADO',
                'sexo' => 'M',
            ]);

            $r->assertStatus(200);

            $this->assertSame($profesor->nombres,
                DB::table('profesores')->where('id', $profesor->id)->value('nombres'),
                "Con `tipo={$codigo}` el método SÍ guardó. Si es intencionado, la ".
                'pantalla de perfil acaba de encenderse en los dieciséis colegios.');
        }
    }

    /**
     * Y con el nombre largo sí guarda, que es lo que prueba que el fallo es la etiqueta.
     *
     * Sin esta mitad, el test de arriba pasaría igual si el método estuviera roto
     * por cualquier otra razón. Es la comprobación al revés metida en el mismo
     * fichero.
     */
    public function test_con_el_nombre_largo_si_guarda(): void
    {
        $token = $this->tokenDeSuperusuario();
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($token)->putJson('/api/perfiles/update/'.$profesor->id, [
            'tipo' => 'Profesor',
            'persona_id' => $profesor->id,
            'nombres' => 'SI ENTRA',
            'apellidos' => 'SI ENTRA',
            'sexo' => 'M',
        ])->assertStatus(200);

        $this->assertSame('SI ENTRA',
            DB::table('profesores')->where('id', $profesor->id)->value('nombres'),
            'Ni con el nombre largo guarda: el fallo no es la etiqueta, es otro.');
    }

    /**
     * Editar un `Usuario` escribe sobre el ACUDIENTE del mismo id.
     *
     * **La colisión se monta aquí dentro, y ésa es la mitad importante del test.**
     * En la base de tests hay veinte cuentas `tipo='Usuario'` y **cero** que
     * colisionen con un acudiente vivo, así que un test que se apoyara en los datos
     * del seed pasaría **sin medir nada** — sería la novena vez esta noche que un
     * verde no significa nada. En la base del colegio que lo midió había 14
     * colisiones.
     *
     * Se comprueba por el EFECTO en la tabla del acudiente, no por la respuesta: la
     * respuesta devuelve el acudiente y parece correcta.
     */
    public function test_editar_un_usuario_escribe_sobre_el_acudiente_del_mismo_id(): void
    {
        $token = $this->tokenDeSuperusuario();

        // La colisión: un acudiente cuyo id coincide con el de una cuenta `Usuario`.
        $cuenta = DB::selectOne('SELECT id FROM users
            WHERE tipo = "Usuario" AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($cuenta, 'El seed necesita una cuenta de tipo Usuario.');

        DB::table('acudientes')->where('id', $cuenta->id)->delete();
        DB::insert('INSERT INTO acudientes(id, nombres, apellidos, sexo, fecha_nac, celular, created_at, updated_at)
                    VALUES(?, "Acudiente", "Intacto", "F", "1980-05-05", "3001112233", NOW(), NOW())',
            [$cuenta->id]);

        $this->withToken($token)->putJson('/api/perfiles/update/'.$cuenta->id, [
            'tipo' => 'Usuario',
            'persona_id' => $cuenta->id,
            'sexo' => 'M',
            'fecha_nac' => '1999-01-01',
            'celular' => '3009998877',
            'email' => 'escrito-por-la-rejilla@ejemplo.com',
        ])->assertStatus(200);

        $acudiente = DB::table('acudientes')->where('id', $cuenta->id)->first();

        $this->assertSame('M', $acudiente->sexo,
            'La rama `Usuario` dejó de escribir sobre `acudientes`: se arregló, y '.
            'este test hay que cambiarlo por el que compruebe que escribe donde debe.');
        $this->assertSame('3009998877', $acudiente->celular);
    }

    /**
     * Y `fecha_nac` es la que se destruye, porque la rejilla manda «N/A».
     *
     * Es lo que convierte el fallo anterior en pérdida de datos y no solo en una
     * escritura mal dirigida: `acudientes.fecha_nac` es una columna `DATE` y, con
     * `'strict' => false`, MySQL no rechaza «N/A» — la guarda como
     * `0000-00-00`. La fecha anterior no se recupera.
     *
     * El «N/A» no lo inventa el test: es lo que `getUsuariosall` devuelve en las
     * columnas que no aplican a cada rama de su `UNION`, y la rejilla reenvía lo
     * que recibió.
     */
    public function test_un_na_en_la_fecha_la_deja_en_cero(): void
    {
        $token = $this->tokenDeSuperusuario();

        $cuenta = DB::selectOne('SELECT id FROM users
            WHERE tipo = "Usuario" AND deleted_at IS NULL ORDER BY id LIMIT 1');

        DB::table('acudientes')->where('id', $cuenta->id)->delete();
        DB::insert('INSERT INTO acudientes(id, nombres, apellidos, sexo, fecha_nac, created_at, updated_at)
                    VALUES(?, "Acudiente", "Con fecha", "F", "1980-05-05", NOW(), NOW())',
            [$cuenta->id]);

        $this->withToken($token)->putJson('/api/perfiles/update/'.$cuenta->id, [
            'tipo' => 'Usuario',
            'persona_id' => $cuenta->id,
            'sexo' => 'F',
            'fecha_nac' => 'N/A',
        ])->assertStatus(200);

        $this->assertNotSame('1980-05-05',
            (string) DB::table('acudientes')->where('id', $cuenta->id)->value('fecha_nac'),
            'La fecha sobrevivió: o se arregló la rama, o MySQL dejó de aceptar «N/A».');
    }

    /**
     * `perfiles/destroy/{id}` manda un GRUPO a la papelera.
     *
     * Se fija con un grupo montado aquí —sin matrículas— para no llevarse por
     * delante nada del seed aunque la transacción lo deshaga: el `delete()` de
     * `Grupo` arrastra una cascada larga y un test no debería depender de que la
     * transacción la deshaga entera.
     */
    public function test_destroy_manda_un_grupo_a_la_papelera(): void
    {
        $token = $this->tokenDeSuperusuario();

        $year = DB::selectOne('SELECT id FROM years WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $grado = DB::selectOne('SELECT id FROM grados WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $grupoId = DB::table('grupos')->insertGetId([
            'nombre' => 'Grupo de prueba destroy',
            'abrev' => 'GPD',
            'year_id' => $year->id,
            'grado_id' => $grado->id,
            'orden' => 999,
        ]);

        $this->withToken($token)->deleteJson('/api/perfiles/destroy/'.$grupoId)
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('grupos')->where('id', $grupoId)->value('deleted_at'),
            'Dejó de borrar el grupo: o se arregló, o apunta ya a otra tabla.');
    }

    /**
     * La mina: `usuariosall` no devuelve `is_superuser`, y eso es lo único que
     * mantiene apagado el botón de arriba.
     *
     * **Este es el test que hay que entender antes de tocar `getUsuariosall`.** El
     * botón de borrar de la rejilla de usuarios se pinta con `is_superuser`, y esa
     * columna no viaja en la respuesta, así que la condición es siempre falsa y
     * nadie pulsa nunca. Añadirla al `SELECT` —una línea, y es lo primero que uno
     * hace si necesita saber quién es administrador— **enciende un botón que manda
     * grupos a la papelera**.
     *
     * Si este test se pone en rojo, la pregunta no es «actualizo el test» sino
     * **«¿qué pasa ahora con `perfiles/destroy`?»**.
     */
    public function test_usuariosall_no_devuelve_is_superuser(): void
    {
        $token = $this->tokenDeSuperusuario();
        $year = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL LIMIT 1');

        $r = $this->withToken($token)->getJson('/api/perfiles/usuariosall?year_id='.$year->id);

        $r->assertStatus(200);

        $filas = $r->json();

        $this->assertNotEmpty($filas, 'La respuesta llegó vacía y el test no comprueba nada.');

        $this->assertArrayNotHasKey('is_superuser', (array) $filas[0],
            "`usuariosall` empezó a devolver `is_superuser`.\n".
            "Eso ENCIENDE el botón de borrar de la rejilla de usuarios, y ese botón\n".
            "llama a `perfiles/destroy/{id}`, que manda un GRUPO a la papelera.\n".
            'Antes de actualizar este test, mira qué hace ahora esa ruta.');
    }
}
