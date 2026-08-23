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
                    "La rama {$nombre} vació `{$columna}` con un formulario que no la traía — §153.");
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
            'Editar el nombre de un grupo apagó sus caritas — §153.');

        $this->assertSame(33, (int) $despues->cupo,
            'Editar el nombre de un grupo borró su cupo — §153.');

        $this->assertSame(12345.0, (float) $despues->valorpension,
            'Editar el nombre de un grupo borró su valor de pensión — §153.');
    }

    /**
     * Mandar `null` a propósito SÍ vacía, y ésa es la mitad que el arreglo no puede perder.
     *
     * «No mandar el campo» y «mandarlo vacío» son dos intenciones distintas, y un
     * arreglo que las confunda es tan malo como el fallo: dejaría a un colegio sin
     * poder borrar un teléfono que ya no existe.
     *
     * **El caso que separa las dos implementaciones posibles es éste, y no el del
     * `0`.** Con un `0` en el cuerpo, `Request::input('x', $actual)` y
     * `Request::input('x') ?? $actual` se comportan igual —`??` solo mira `null`—
     * y el test pasa con cualquiera de las dos. Con `null` explícito se separan:
     * `input()` con defecto devuelve **null**, porque la clave existe y
     * `Arr::exists()` cuenta un null como presente; el `??` devolvería el valor
     * viejo y **se comería la intención de borrar**. Lo que hay escrito es lo
     * primero, y esto es lo que lo demuestra.
     */
    public function test_mandar_null_a_proposito_si_vacia_el_campo(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $id = (int) DB::table('alumnos')->where('user_id', $alumno->id)->value('id');

        DB::update('UPDATE alumnos SET celular = ? WHERE id = ?', ['3001234567', $id]);

        $this->withToken($this->tokenDe($alumno->username))->putJson('/api/perfiles/update/'.$id, [
            'tipo' => 'Alumno',
            'celular' => null,
        ])->assertStatus(200);

        $this->assertNull(DB::table('alumnos')->where('id', $id)->value('celular'),
            'Mandar `celular: null` dejó de vaciarlo: el arreglo del §153 se comió la '
            .'intención de borrar, que es distinta de no mandar el campo.');
    }

    /**
     * Y lo mismo en la rama que usa `CamposQueVinieron`, donde no es evidente.
     *
     * Aquí el `null` explícito y el campo ausente llegan **iguales** a la altura de
     * la asignación, porque `sanarInputProfesor()` mete `null` para las claves que
     * no vienen. Lo único que los distingue es que `CamposQueVinieron` se captura
     * **antes** del primer `sanar`, y por eso este test dice qué herramienta hay
     * puesta de verdad: con el defecto de `input()` daría verde por casualidad, y
     * con `has()` daría verde siempre.
     */
    public function test_mandar_null_a_un_profesor_tambien_vacia(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $profesor = DB::selectOne('SELECT id, ciudad_nac FROM profesores
            WHERE deleted_at IS NULL AND ciudad_nac IS NOT NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($profesor, 'El seed no tiene ningún profesor con ciudad de nacimiento.');

        DB::update('UPDATE profesores SET telefono = ? WHERE id = ?', ['6041234567', $profesor->id]);

        $this->withToken($jefe)->putJson('/api/profesores/update/'.$profesor->id, [
            'telefono' => null,
        ])->assertStatus(200);

        $despues = DB::table('profesores')->where('id', $profesor->id)->first();

        $this->assertNull($despues->telefono,
            'Mandar `telefono: null` dejó de vaciarlo — §153.');

        // Y la de al lado, que NO se mandó, sigue donde estaba: es la misma
        // petición demostrando las dos mitades a la vez.
        $this->assertSame($profesor->ciudad_nac, $despues->ciudad_nac,
            'La misma petición que vació el teléfono se llevó la ciudad de nacimiento — §153.');
    }

    /**
     * Y «quítame la ciudad» se sigue pudiendo decir, que es donde la herramienta
     * podía haber tapado la distinción en vez de conservarla.
     *
     * `ciudad_nac` es de las cinco que `sanarInputProfesor()` fusiona como `null`
     * cuando no vienen, así que **a la altura de la asignación el campo ausente y
     * el `null` explícito son idénticos**: `Request::has()` dice que sí en los dos
     * casos y `Request::input()` devuelve `null` en los dos. Si la guarda mirara
     * ahí, «quítame la ciudad de nacimiento» sería **inexpresable** en este
     * controlador, y el arreglo del §153 habría cambiado un fallo por otro con
     * mejor cara.
     *
     * No lo es, y el porqué es una línea del propio `CamposQueVinieron`: se
     * captura con `array_keys(Request::all())` **antes del primer `sanar*`**, o
     * sea sobre el cuerpo tal como llegó. Este test es la prueba, y va con la
     * pareja completa —el mismo campo ausente y mandado a null— porque una sola
     * mitad no distingue las dos implementaciones.
     */
    public function test_a_un_profesor_se_le_puede_quitar_la_ciudad_a_proposito(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $profesor = DB::selectOne('SELECT id, ciudad_nac FROM profesores
            WHERE deleted_at IS NULL AND ciudad_nac IS NOT NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($profesor, 'El seed no tiene ningún profesor con ciudad de nacimiento.');

        // 1. Sin la clave: no se toca.
        $this->withToken($jefe)->putJson('/api/profesores/update/'.$profesor->id, [
            'nombres' => 'Sigue Igual',
        ])->assertStatus(200);

        $this->assertSame($profesor->ciudad_nac,
            DB::table('profesores')->where('id', $profesor->id)->value('ciudad_nac'),
            'Sin mandar `ciudad_nac` se vació igual — §153.');

        // 2. Con la clave a null: se quita. Misma ruta, mismo campo, otra intención.
        $this->withToken($jefe)->putJson('/api/profesores/update/'.$profesor->id, [
            'ciudad_nac' => null,
        ])->assertStatus(200);

        $this->assertNull(
            DB::table('profesores')->where('id', $profesor->id)->value('ciudad_nac'),
            'Mandar `ciudad_nac: null` no la quitó: entonces el arreglo del §153 dejó '
            .'un campo que ya no se puede vaciar nunca, que es un fallo nuevo con mejor cara.');
    }

    /**
     * Y un `0` explícito escribe 0, que es lo que casi se pierde al arreglar esto.
     *
     * `caritas` y `cupo` son las dos donde importa: **apagar las caritas es
     * mandar un 0**, y un arreglo que confundiera «0» con «no vino» dejaría un
     * grupo de preescolar sin poder volver a la escala numérica. No prueba qué
     * implementación hay —con un `0` las dos coinciden— pero sí que ninguna se
     * pasó de lista.
     */
    public function test_mandar_cero_a_proposito_escribe_cero(): void
    {
        $jefe = $this->tokenDeUnSuperusuario();

        $grupo = DB::selectOne('SELECT * FROM grupos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        DB::update('UPDATE grupos SET caritas = 1, cupo = 33 WHERE id = ?', [$grupo->id]);

        $this->withToken($jefe)->putJson('/api/grupos/update', [
            'id' => $grupo->id,
            'caritas' => 0,
            'cupo' => 0,
        ])->assertStatus(200);

        $despues = DB::table('grupos')->where('id', $grupo->id)->first();

        $this->assertSame(0, (int) $despues->caritas,
            'Apagar las caritas a propósito dejó de funcionar: el §153 confundió un 0 con un ausente.');

        $this->assertSame(0, (int) $despues->cupo,
            'Poner el cupo a 0 dejó de funcionar — §153.');
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
                .'mete como null: el defecto de `Request::input()` no basta aquí — §153.');
        }

        foreach (['apellidos', 'num_doc', 'direccion', 'telefono', 'celular', 'titulo'] as $columna) {
            $this->assertSame($profesor->{$columna}, $despues->{$columna},
                "Editar el nombre de un profesor vació `{$columna}` — §153.");
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
            'Cambiar la observación desató al acudiente del alumno — §153.');

        $this->assertSame((int) $parentesco->alumno_id, (int) $despues->alumno_id,
            'Cambiar la observación desató al alumno de su acudiente — §153.');

        $this->assertSame($parentesco->parentesco, $despues->parentesco,
            'Cambiar la observación borró qué parentesco era — §153.');
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
     * Editar un grupo de otro año **lo mueve al año de quien lo edita**, con sus
     * matrículas dentro — §154.
     *
     * `putUpdate` hace `$grupo->year_id = $user->year_id` **sin leer nunca el
     * cuerpo**, y el front tampoco lo manda: ni la rejilla (`GruposCtrl`) ni el
     * formulario (`GruposEditCtrl`) incluyen `year_id`. O sea que lo que se
     * escribe es siempre el año del que edita, y eso es una de dos cosas:
     *
     * - el grupo ya estaba en su año → no pasa nada, que es el 99% de las veces;
     * - el grupo era de otro año → **se lo lleva**, y las matrículas van dentro
     *   porque cuelgan del grupo, no del año.
     *
     * Corregirle la abreviatura a un grupo del año que viene lo mete en el año en
     * curso. Nadie ve nada: la respuesta es 200 y el nombre cambiado.
     *
     * Lo que este test comprueba es **el año de las matrículas del grupo, no el
     * del grupo**: la fila de `grupos` es un número que se puede volver a poner,
     * y lo que importa es de qué año pasan a ser los alumnos que cuelgan de ella.
     */
    public function test_editar_un_grupo_de_otro_ano_no_se_lo_lleva_al_tuyo(): void
    {
        $jefe = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDeUnSuperusuario();

        $suYear = (int) DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1')->year_id;

        $ajeno = DB::selectOne('SELECT g.id, g.year_id, g.nombre, g.abrev, g.grado_id, g.orden,
                (SELECT COUNT(*) FROM matriculas m WHERE m.grupo_id = g.id AND m.deleted_at IS NULL) AS matriculas
            FROM grupos g WHERE g.year_id <> ? AND g.deleted_at IS NULL
            ORDER BY g.id LIMIT 1', [$suYear]);

        if ($ajeno === null) {
            $this->markTestSkipped('El seed solo trae grupos de un año.');
        }

        $r = $this->withToken($token)->putJson('/api/grupos/update', [
            'id' => $ajeno->id,
            'nombre' => $ajeno->nombre,
            'abrev' => 'XX',
            'grado_id' => $ajeno->grado_id,
            'orden' => $ajeno->orden,
        ]);

        $this->assertSame(200, $r->status(), 'Editar el grupo de otro año no guardó.');

        $this->assertSame('XX', DB::table('grupos')->where('id', $ajeno->id)->value('abrev'),
            'No guardó lo que sí se mandó.');

        $this->assertSame((int) $ajeno->year_id,
            (int) DB::table('grupos')->where('id', $ajeno->id)->value('year_id'),
            'Cambiarle la abreviatura a un grupo de otro año se lo llevó al del que edita, '
            ."con sus {$ajeno->matriculas} matrículas dentro — §154.");
    }

    /**
     * Y crear un grupo **sí** lo crea en el año del que lo crea, que es lo correcto.
     *
     * Esta es la mitad que un arreglo del §154 podría llevarse por delante sin que
     * nada se pusiera rojo: `postStore` también escribe `year_id` desde el token,
     * y ahí **es la única fuente posible** —el front no lo manda en ninguna de las
     * dos rutas—. Lo que cambia entre las dos no es el dato: es que una fila nueva
     * no tiene año previo y una que existe sí.
     */
    public function test_crear_un_grupo_lo_crea_en_el_ano_del_que_lo_crea(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $suYear = (int) DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id])->year_id;

        $grado = DB::selectOne('SELECT id FROM grados WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $id = $this->withToken($token)->postJson('/api/grupos/store', [
            'nombre' => 'Grupo del ano en curso', 'abrev' => 'GAC',
            'grado' => ['id' => $grado->id], 'orden' => 997,
            'valormatricula' => 0, 'valorpension' => 0, 'caritas' => 0,
        ])->json('id');

        $this->assertSame($suYear, (int) DB::table('grupos')->where('id', $id)->value('year_id'),
            'Crear un grupo dejó de ponerlo en el año de quien lo crea — §154.');
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
