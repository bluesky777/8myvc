<?php

namespace Tests\Contrato;

use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Quién puede leer la ficha del personal del colegio — `GET api/profesores`.
 *
 * Es la **§234** que midió `myvc-front-6b` el 25 ago 2026 conduciendo la aplicación
 * con un token de docente, y la autorizó Joseth el 28. Lo que había: la ruta iba con
 * `auth.personal` y **nada más**, así que un docente cualquiera recibía **las mismas
 * 28 claves y los mismos 47 registros** que un administrador — `num_doc`,
 * `fecha_nac`, `direccion`, `barrio`, `celular`, `username` y el `is_superuser` de
 * cada empleado. Medido sobre el seed: 35 documentos de identidad, 41 fechas de
 * nacimiento, 11 domicilios. En los colegios esos campos están más llenos.
 *
 * **Las dos mitades, que es lo que hace que esto no sea cosmético.** Un test que sólo
 * comprueba el 403 no distingue «la guarda funciona» de «la ruta está rota para
 * todos», y ese error ya se cometió una vez en este repositorio. Aquí van las dos:
 * quién deja de entrar y quién tiene que seguir entrando.
 *
 * **El Secretario se fabrica dentro de la transacción**, como en `SecretarioTest` y
 * `PermisoDeAuditoriaTest`: `database/dumps/test-seed.sql` hace `TRUNCATE TABLE roles`
 * y `role_user` antes de insertar, así que lo que siembre una migración no sobrevive a
 * construir la base — y un test que se apoyara en ello comprobaría el seed y no el
 * código. Es además la única forma de comprobar el caso que importa: **tener el
 * criterio sin `is_superuser`**.
 *
 * Ver 05 §243.
 */
class FichaDelPersonalTest extends CasoDeContrato
{
    /** Las columnas por las que esto es un hallazgo y no una preferencia. */
    private const SENSIBLES = ['num_doc', 'fecha_nac', 'direccion', 'barrio', 'celular', 'username', 'is_superuser'];

    public function test_un_docente_ya_no_recibe_la_ficha_de_los_empleados(): void
    {
        $profe = $this->usuarioDeTipo('Profesor');

        $this->withToken($this->tokenDe($profe->username))
            ->getJson('/api/profesores')
            ->assertStatus(403);
    }

    /**
     * **El escalón no es `users.tipo`**, y por eso va su caso.
     *
     * `auth.personal` deja pasar a cualquier `Usuario`, tenga rol o no —lo fija
     * `LoQueDecideUnRolTest`—, así que si la §243 se hubiera escrito mirando el tipo
     * en vez del criterio administrativo, un administrativo llano seguiría llevándose
     * los 47 expedientes y el test del docente pasaría igual.
     */
    public function test_un_administrativo_llano_tampoco_la_recibe(): void
    {
        $llano = $this->usuarioLlanoDelPersonal();

        $this->withToken($this->tokenDe($llano->username))
            ->getJson('/api/profesores')
            ->assertStatus(403);
    }

    public function test_un_alumno_y_un_acudiente_siguen_fuera(): void
    {
        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $this->withToken($this->tokenDe($this->usuarioDeTipo($tipo)->username))
                ->getJson('/api/profesores')
                ->assertStatus(403);
        }
    }

    /**
     * La otra mitad: **la pantalla que sí existe tiene que seguir funcionando**, y con
     * la ficha entera. Es la que llaman `ProfesoresCtrl` en `myvc_front` y `profesores/`
     * y `profesores/editar/{id}` en `app2`, las tres de administración.
     */
    public function test_un_superusuario_sigue_recibiendo_la_ficha_entera(): void
    {
        $jefe = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($jefe, 'El seed necesita un superusuario para esta prueba.');

        $r = $this->withToken($this->tokenDe($jefe->username))->getJson('/api/profesores');

        $r->assertStatus(200);

        $filas = $r->json();

        $this->assertNotEmpty($filas, 'La ficha del personal salió vacía: un 200 sin datos no prueba nada.');

        // No basta con el 200: lo que la pantalla de editar necesita son los campos.
        // Si un día esto se «arregla» recortando la respuesta en vez de la puerta,
        // aquí se entera alguien.
        foreach (self::SENSIBLES as $columna) {
            $this->assertArrayHasKey($columna, (array) $filas[0],
                "La ficha dejó de traer '{$columna}', y la pantalla de editar la escribe de vuelta.");
        }
    }

    /**
     * Y el caso que separa «administrativo» de «superusuario»: una secretaria **sin**
     * `is_superuser`. Es el rol que `app2` deja entrar a `/profesores` junto al
     * administrador, y el motivo por el que la §243 no se ancló a `esSuperusuario`
     * como sí lo está la escritura de este mismo controlador.
     */
    public function test_una_secretaria_sin_superusuario_sigue_entrando(): void
    {
        $llano = $this->usuarioLlanoDelPersonal();

        // El rol **se crea aquí dentro**, igual que en `SecretarioTest`: el seed hace
        // `TRUNCATE TABLE roles`, y `LoQueDecideUnRolTest` tiene su propio caso fijando
        // que en la base de tests esa fila no existe. Darlo por sembrado sería comprobar
        // el seed en vez del código.
        $rol = DB::table('roles')->where('name', 'Secretario')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Secretario',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('role_user')->insert(['user_id' => $llano->id, 'role_id' => $rol]);

        $this->assertTrue(Role::isSecretario($llano->id), 'El rol no quedó puesto.');

        $this->withToken($this->tokenDe($llano->username))
            ->getJson('/api/profesores')
            ->assertStatus(200);
    }
}
