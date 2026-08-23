<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Un campo que no se manda no es un campo que no cambia: es un campo que se pisa.
 *
 * La frase es de la §68, y costó un fallo de verdad: `is_active` con
 * `Request::input('is_active', 1)` devolvía la entrada al sistema a quien se le
 * corrigiera el teléfono. Este test es la misma pregunta sobre los métodos de
 * este lote que resuelven una fila existente y le asignan varias columnas con
 * `Request::input('x')` **sin defecto**.
 *
 * Lo que mide no es el código: es **qué le queda a la ficha** cuando el cliente
 * manda medio formulario. Y el mecanismo es una petición de verdad, no un
 * `assert` sobre el fuente, porque lo que hay que fijar es la fila después.
 *
 * `PerfilesController::putUpdate` era el peor del repo en proporción: **22
 * columnas y ninguna a salvo**, sobre la ficha de una persona.
 */
class CamposQueSeVacianTest extends CasoDeContrato
{
    /**
     * La ficha de una persona sobrevive a un formulario a medias.
     *
     * Se recorren las cuatro ramas del `switch` de tipo porque **son cuatro
     * copias de las mismas seis líneas**, y arreglar una y dejar tres es
     * exactamente lo que la §89 llama arreglar el sitio en vez de la operación.
     * La rama `Usuario` escribe sobre `acudientes` —eso ya está fijado en
     * `PerfilesEscribeEnOtraTablaTest` y aquí no se juzga—, así que se comprueba
     * sobre la tabla en la que de verdad escribe.
     */
    public function test_editar_un_perfil_con_medio_formulario_no_vacia_el_resto(): void
    {
        foreach ($this->ramasDePerfil() as $nombre => $rama) {
            $antes = DB::table($rama['tabla'])->where('id', $rama['id'])->first();

            $this->assertNotNull($antes, "No hay fila en {$rama['tabla']} para la rama {$nombre}.");

            // Solo el nombre, que es lo que manda la pantalla al corregir una tilde.
            $r = $this->withToken($rama['token'])->putJson('/api/perfiles/update/'.$rama['id'], [
                'tipo' => $rama['tipo'],
                'nombres' => 'Nombre Corregido',
            ]);

            $this->assertSame(200, $r->status(), "La rama {$nombre} no guardó.");

            $despues = DB::table($rama['tabla'])->where('id', $rama['id'])->first();

            $this->assertSame('Nombre Corregido', $despues->nombres,
                "La rama {$nombre} no guardó lo que sí se mandó.");

            foreach ($rama['intactas'] as $columna) {
                $this->assertSame($antes->{$columna}, $despues->{$columna},
                    "La rama {$nombre} vació `{$columna}` con un formulario que no la traía — §101.");
            }
        }
    }

    /**
     * Editar un grupo sin mandar `caritas` no debe apagar las caritas.
     *
     * Ésta es la que enseña por qué «tiene defecto» no es lo mismo que «está a
     * salvo»: `caritas` es la única de las diez con defecto —`Request::input(
     * 'caritas', false)`— y ese defecto **la apaga**. Es literalmente la forma de
     * la §68 con casco: el `is_active` de aquella también tenía defecto.
     *
     * Y las caritas no son cosméticas: deciden si el grupo se califica con
     * escala de preescolar en vez de con números.
     */
    public function test_editar_un_grupo_con_medio_formulario_no_apaga_las_caritas(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $grupo = DB::selectOne('SELECT * FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        DB::update('UPDATE grupos SET caritas = 1, cupo = 33, valorpension = 12345 WHERE id = ?', [$grupo->id]);

        $r = $this->withToken($jefe)->putJson('/api/grupos/update', [
            'id' => $grupo->id,
            'nombre' => $grupo->nombre,
            'abrev' => $grupo->abrev,
            'grado_id' => $grupo->grado_id,
            'orden' => $grupo->orden,
        ]);

        $this->assertSame(200, $r->status(), 'Editar el grupo no guardó.');

        $despues = DB::table('grupos')->where('id', $grupo->id)->first();

        $this->assertSame(1, (int) $despues->caritas,
            'Editar el nombre de un grupo apagó sus caritas — §101.');

        $this->assertSame(33, (int) $despues->cupo,
            'Editar el nombre de un grupo borró su cupo — §101.');

        $this->assertSame(12345.0, (float) $despues->valorpension,
            'Editar el nombre de un grupo borró su valor de pensión — §101.');
    }

    /**
     * Editar a un profesor con medio formulario no le borra la hoja de vida.
     *
     * Aquí el arreglo **no puede ser el mismo** que en los dos de arriba, y ésa es
     * la parte que hay que fijar: `sanarInputProfesor()` corre antes de leer y
     * hace `Request::merge(['ciudad_nac' => null])` —y lo mismo con `ciudad_doc`,
     * `tipo_doc`, `estado_civil` y `foto_id`— cuando la clave no viene. O sea que
     * a la altura de la asignación **la clave existe y vale null**, y el defecto
     * de `Request::input()` no se dispara nunca.
     *
     * Por eso la comprobación de este test incluye `ciudad_nac` y `tipo_doc` a
     * propósito: son justo las que un arreglo copiado de `perfiles/update`
     * dejaría rotas, y con las otras quince en verde.
     */
    public function test_editar_un_profesor_con_medio_formulario_no_le_borra_la_ficha(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $profesor = DB::selectOne('SELECT * FROM profesores WHERE deleted_at IS NULL
            AND ciudad_nac IS NOT NULL AND tipo_doc IS NOT NULL AND num_doc IS NOT NULL
            ORDER BY id LIMIT 1');

        $this->assertNotNull($profesor,
            'El seed no tiene ningún profesor con ciudad de nacimiento y tipo de documento.');

        $r = $this->withToken($jefe)->putJson('/api/profesores/update/'.$profesor->id, [
            'nombres' => 'Nombre Corregido',
        ]);

        $this->assertSame(200, $r->status(), 'Editar al profesor no guardó.');

        $despues = DB::table('profesores')->where('id', $profesor->id)->first();

        $this->assertSame('Nombre Corregido', $despues->nombres,
            'No guardó lo que sí se mandó.');

        // Las dos que `sanarInputProfesor()` mete como null: si estas dos aguantan,
        // el arreglo es el correcto y no el copiado.
        foreach (['ciudad_nac', 'tipo_doc'] as $columna) {
            $this->assertSame($profesor->{$columna}, $despues->{$columna},
                "Editar el nombre vació `{$columna}`, que es de las que `sanarInputProfesor()` "
                .'mete como null: el defecto de `Request::input()` no basta aquí — §101.');
        }

        foreach (['apellidos', 'num_doc', 'direccion', 'telefono', 'celular', 'titulo'] as $columna) {
            $this->assertSame($profesor->{$columna}, $despues->{$columna},
                "Editar el nombre de un profesor vació `{$columna}` — §101.");
        }
    }

    /**
     * Cambiar la observación de un parentesco no desata a la familia del alumno.
     *
     * `putSeleccionarParentesco` lleva **las dos ramas en el mismo método**: con
     * `parentesco_acudiente_cambiar_id` edita, y sin él crea. En la de editar,
     * las cuatro asignaciones sin defecto borraban `acudiente_id` y `alumno_id`,
     * o sea que dejaban la fila viva **sin atar a nadie con nadie**.
     */
    public function test_cambiar_la_observacion_de_un_parentesco_no_lo_desata(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $parentesco = DB::selectOne('SELECT * FROM parentescos
            WHERE deleted_at IS NULL AND acudiente_id IS NOT NULL AND alumno_id IS NOT NULL
            ORDER BY id LIMIT 1');

        $this->assertNotNull($parentesco, 'El seed no tiene ningún parentesco vivo.');

        $r = $this->withToken($jefe)->putJson('/api/acudientes/seleccionar-parentesco', [
            'parentesco_acudiente_cambiar_id' => $parentesco->id,
            'observaciones' => 'Recoge los martes',
        ]);

        $this->assertSame(200, $r->status(), 'Cambiar la observación no guardó.');

        $despues = DB::table('parentescos')->where('id', $parentesco->id)->first();

        $this->assertSame('Recoge los martes', $despues->observaciones,
            'No guardó la observación que sí se mandó.');

        $this->assertSame((int) $parentesco->acudiente_id, (int) $despues->acudiente_id,
            'Cambiar la observación desató al acudiente del alumno — §101.');

        $this->assertSame((int) $parentesco->alumno_id, (int) $despues->alumno_id,
            'Cambiar la observación desató al alumno de su acudiente — §101.');

        $this->assertSame($parentesco->parentesco, $despues->parentesco,
            'Cambiar la observación borró qué parentesco era — §101.');
    }

    /**
     * Y el alta de un profesor NO es de esta familia, que es un resultado.
     *
     * `postStore` salía en el barrido con 19 columnas y una a salvo, y **es un
     * falso positivo**: hace `new Profesor` y `new User`, así que un campo que no
     * llega no pisa nada, se queda nulo en una fila que acaba de nacer. Es el
     * discriminador que la propia `CamposQueVinieron` lleva escrito —`new User`
     * contra `User::find()`— y va fijado aquí para que nadie vuelva a abrirlo: un
     * detector da sitios donde mirar, nunca una lista de fallos.
     */
    public function test_dar_de_alta_un_profesor_sin_todos_los_campos_no_pisa_nada(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $r = $this->withToken($jefe)->postJson('/api/profesores/store', [
            'nombres' => 'Alta Parcial',
            'apellidos' => 'De Prueba',
            'sexo' => 'M',
            'username' => 'alta.parcial.lote.e',
        ]);

        // **201, no 200**: `postStore` es de las pocas del repo que contesta el
        // código correcto de creación, y queda fijado de paso — el legacy de al
        // lado devuelve 200 para todo y alguien podría «uniformarlo».
        $this->assertSame(201, $r->status(), 'El alta de un profesor dejó de funcionar con lo mínimo.');

        $creado = DB::table('profesores')->where('nombres', 'Alta Parcial')->first();

        $this->assertNotNull($creado, 'No creó la ficha.');
        $this->assertNull($creado->telefono,
            'Un campo no mandado en un ALTA dejó de quedarse nulo: entonces sí hay algo que mirar aquí.');
    }

    /**
     * Las cuatro ramas de `perfiles/update`, cada una con un token que pase su guard.
     *
     * El guard es `persona.propia:persona_id`, o sea que una familia solo puede
     * sobre sí misma y el personal pasa de largo. Se usa el token del propio
     * afectado donde lo hay, que es como lo llama la pantalla de perfil.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ramasDePerfil(): array
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $profesor = $this->usuarioDeTipo('Profesor');

        $ramas = [];

        $ramas['Alumno'] = [
            'tipo' => 'Alumno',
            'tabla' => 'alumnos',
            'id' => (int) DB::table('alumnos')->where('user_id', $alumno->id)->value('id'),
            'token' => $this->tokenDe($alumno->username),
            'intactas' => ['apellidos', 'sexo', 'fecha_nac', 'celular', 'email'],
        ];

        $ramas['Profesor'] = [
            'tipo' => 'Profesor',
            'tabla' => 'profesores',
            'id' => (int) DB::table('profesores')->where('user_id', $profesor->id)->value('id'),
            'token' => $this->tokenDe($profesor->username),
            'intactas' => ['apellidos', 'sexo', 'fecha_nac', 'celular', 'email'],
        ];

        return $ramas;
    }

    /** Igual que en `PapeleraRestaurarTest`: por la columna, que es lo que el código pregunta. */
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
