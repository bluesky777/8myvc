<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Quién puede subir, listar y publicar una versión del horario.
 *
 * Es la §5.4 de [23-horarios.md](../../docs/migracion/23-horarios.md), decisiones
 * 10 y 12 de Joseth (2 sep 2026), y lo que fija es **la asimetría**: *subir no
 * publica*. Secretaría sube todas las versiones que quiera y **no elige la que ve
 * el colegio**; el coordinador académico publica y **no sube**.
 *
 * ## Este test NO fija el 501, y eso es a propósito
 *
 * Los tres métodos de `HorarioController` contestan hoy **501**: la ruta existe,
 * está autorizada y el cuerpo lo escriben los lotes siguientes. Pinar ese 501
 * aquí rompería su lote el día que devuelvan 200 — así que lo que se afirma es
 * **el 403 y la ausencia de 403**, que es lo único que este lote decide. Es la
 * forma que ya tiene `AutorizacionTest::test_un_alumno_si_puede_pedir_el_suyo`, y
 * por la misma razón: *lo que se mide es el guard, no lo que hay detrás*.
 *
 * ## Y lo que su verde NO demuestra, medido y no supuesto
 *
 * `test-seed.sql` hace `TRUNCATE` de `permissions` y `permission_role` **después**
 * de que corran las migraciones (`tools/construir-bd-test.sh`: `migrate` y luego
 * el seed), así que en la base de tests `can_view_auditoria` **no existe ni como
 * fila**. En producción, en cambio, `2026_08_25_200000_create_permiso_can_view_auditoria`
 * se la siembra a `['Rector', 'Coord académico']` desde el 25 ago 2026: **dar ese
 * rol reparte allí dos permisos y no uno** —publicar el horario y ver el rastro de
 * auditoría ajeno—. El test de abajo lo comprueba y lo deja escrito, porque un
 * verde que se lea como «los dos permisos van separados» sería exactamente la
 * clase de tranquilidad falsa que este repo lleva contadas.
 */
class HorarioAutorizacionTest extends CasoDeContrato
{
    /** El rol que publica, tal como está escrito en la tabla desde 2018. */
    private const ROL_QUE_PUBLICA = 'Coord académico';

    /** El rol que sube, creado el 21 ago 2026 y también con cero usuarios. */
    private const ROL_QUE_SUBE = 'Secretario';

    /**
     * Las cuatro rutas de la familia, con el verbo que cada una acepta.
     *
     * Eran tres —las de la §5.3— hasta el 4 sep 2026, cuando entró `getLecciones`
     * (§9.bis). **El nombre de este proveedor se movió con ellas a propósito**: un
     * `lasTresRutas` que devolviera cuatro es la clase de cifra que envejece sin
     * ponerse roja, que es de lo que va medio `CLAUDE.md`.
     */
    public static function lasCuatroRutas(): array
    {
        return [
            'subir' => ['postJson', '/api/horario/versiones'],
            'listar' => ['getJson', '/api/horario/versiones'],
            'publicar' => ['putJson', '/api/horario/versiones/1/oficial'],
            'leer las lecciones' => ['getJson', '/api/horario/versiones/1/lecciones'],
        ];
    }

    private function pedir(string $metodo, string $ruta, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        return $metodo === 'getJson'
            ? $this->getJson($ruta, $cabeceras)
            : $this->{$metodo}($ruta, [], $cabeceras);
    }

    /**
     * Le da un rol a alguien. La transacción del test lo deshace al terminar.
     *
     * **Y crea la fila de `roles` si no está, que no es una comodidad.** En la base
     * de tests falta `Secretario` —lo mide
     * `test_los_roles_que_crea_una_migracion_no_llegan_a_la_base_de_tests`—, y sin
     * esto el caso que ejerce `esAdministrativo` moriría por el motivo equivocado:
     * `hasRole()` compara el nombre literal en PHP, así que un rol ausente devuelve
     * `false` para todo el mundo y la rama se quedaría sin probar **con el test en
     * verde**.
     */
    private function darElRol(int $userId, string $nombre): void
    {
        $rol = DB::selectOne('SELECT id FROM roles WHERE name = ? AND deleted_at IS NULL', [$nombre]);

        if ($rol === null) {
            DB::insert('INSERT INTO roles (name, display_name) VALUES (?, ?)', [$nombre, $nombre]);
            $rol = DB::selectOne('SELECT id FROM roles WHERE name = ? AND deleted_at IS NULL', [$nombre]);
        }

        $this->assertNotNull($rol, "No se pudo dejar el rol `{$nombre}` en la tabla `roles`.");

        DB::insert('INSERT INTO role_user (role_id, user_id) VALUES (?, ?)', [$rol->id, $userId]);
    }

    /**
     * El guard de la ruta: ni alumnos ni acudientes, en ninguna de las tres.
     *
     * Es `auth.personal`, y está en las tres **incluida la de listar**, que es la
     * más abierta de las tres por decisión 12. «Más abierta» es *cualquier
     * docente*, no *cualquiera*: una versión del horario dice qué docente está
     * dónde a cada hora.
     */
    #[DataProvider('lasCuatroRutas')]
    public function test_un_alumno_no_entra_a_ninguna_de_las_cuatro(string $metodo, string $ruta): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->pedir($metodo, $ruta, $token)->assertStatus(403);
    }

    #[DataProvider('lasCuatroRutas')]
    public function test_un_acudiente_tampoco(string $metodo, string $ruta): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Acudiente')->username);

        $this->pedir($metodo, $ruta, $token)->assertStatus(403);
    }

    /**
     * Un administrativo llano —personal, sin `is_superuser` y sin ninguno de los
     * dos roles— **lista y nada más**.
     *
     * Ésta es la fila que separa los tres criterios: si `esAdministrativo` o
     * `puedePublicarHorario` se ensancharan a «cualquiera del personal», este
     * test sería el que lo dijera.
     */
    #[Test]
    public function el_personal_llano_si_lista(): void
    {
        $r = $this->pedir('getJson', '/api/horario/versiones', $this->tokenDelPersonalLlano());

        $this->assertNotSame(403, $r->getStatusCode(),
            'Listar es `auth.personal` y nada más (decisión 12): cualquier docente puede ver '.
            'qué versiones hay, porque el horario es un papel que acaba pegado en la puerta '.
            'del salón.');
    }

    /**
     * Y el mismo sujeto, por las dos rutas que sí deciden. Va aparte del de
     * arriba porque lo que afirma es lo contrario y conviene que fallen por
     * separado.
     */
    #[Test]
    public function el_personal_llano_recibe_403_al_subir_y_al_publicar(): void
    {
        $token = $this->tokenDelPersonalLlano();

        $this->pedir('postJson', '/api/horario/versiones', $token)->assertStatus(403);
        $this->pedir('putJson', '/api/horario/versiones/1/oficial', $token)->assertStatus(403);
    }

    /**
     * Un superusuario pasa los cuatro criterios.
     *
     * **`assertNotSame(403, …)` y no `assertStatus(200)`**: hoy detrás hay un 501
     * y mañana habrá un 200 o un 422. Lo que este lote decide es si el guard deja
     * pasar, no qué contesta el que está detrás.
     */
    #[DataProvider('lasCuatroRutas')]
    public function test_un_superusuario_pasa_los_cuatro_criterios(string $metodo, string $ruta): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este test tiene que ser superusuario y no lo es: el seed cambió, '.
            'y sin superusuario esta comprobación no ejerce la rama que dice.');

        $r = $this->pedir($metodo, $ruta, $this->tokenDe($usuario->username));

        $this->assertNotSame(403, $r->getStatusCode(),
            "El criterio rechaza a un superusuario en {$metodo} {$ruta}.");
    }

    /**
     * **Subir no publica**, por el lado del `Secretario`.
     *
     * Con el rol puesto, `esAdministrativo` deja pasar la subida — y
     * `puedePublicarHorario` **sigue** devolviendo 403, porque la decisión 10 no
     * nombra a secretaría. Es la mitad del criterio que se habría perdido si
     * `puedePublicarHorario` se hubiera escrito ensanchando `esAdministrativo`,
     * que es lo primero que se prueba.
     */
    #[Test]
    public function con_el_rol_secretario_se_sube_pero_no_se_publica(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $this->darElRol((int) $usuario->id, self::ROL_QUE_SUBE);

        $subir = $this->pedir('postJson', '/api/horario/versiones', $token);

        $this->assertNotSame(403, $subir->getStatusCode(),
            'Con el rol `Secretario` la subida sigue cerrada: `esAdministrativo` dejó de '.
            'mirar ese rol.');

        $this->assertSame(403,
            $this->pedir('putJson', '/api/horario/versiones/1/oficial', $token)->getStatusCode(),
            'Secretaría sube pero NO publica (decisión 10).');
    }

    /**
     * **Publicar no sube**, por el lado del `Coord académico`.
     *
     * La dirección contraria de la de arriba, y la que demuestra que
     * `puedePublicarHorario` no es `esAdministrativo`: con este rol se publica y
     * **la subida sigue en 403**.
     *
     * **Ojo con lo que este verde NO dice.** Aquí el rol se fabrica, y en
     * `simonbolivar` tiene **cero usuarios**: la regla nace correcta e *inerte*, y
     * hoy la oficial la marcan los 11 superusuarios y nadie más hasta que un
     * colegio se lo dé a su coordinadora (decisión 11). Que este test pase no
     * significa que haya alguien detrás.
     */
    #[Test]
    public function con_el_rol_coord_academico_se_publica_pero_no_se_sube(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        $this->darElRol((int) $usuario->id, self::ROL_QUE_PUBLICA);

        $publicar = $this->pedir('putJson', '/api/horario/versiones/1/oficial', $token);

        $this->assertNotSame(403, $publicar->getStatusCode(),
            'Con el rol `Coord académico` la publicación sigue cerrada. Lo primero que hay '.
            'que mirar es la CADENA: `hasRole()` compara el nombre literal en PHP, así que '.
            "`'Coord academico'` sin tilde devuelve `false` para todo el mundo y no falla nada.");

        $this->assertSame(403,
            $this->pedir('postJson', '/api/horario/versiones', $token)->getStatusCode(),
            'El coordinador académico publica pero NO sube (decisión 10).');
    }

    /**
     * Lo que la base de tests **no** puede demostrar, dicho con su medición.
     *
     * En producción, dar `Coord académico` reparte **dos** permisos: publicar el
     * horario y `can_view_auditoria`, que `2026_08_25_200000_create_permiso_can_view_auditoria`
     * le siembra desde el 25 ago 2026. Aquí ese acoplamiento **no existe**, porque
     * el seed trunca `permissions` y `permission_role` después de migrar — así que
     * los dos tests de arriba pasan **sin heredar nada**, y su verde no dice ni una
     * palabra sobre los quince colegios.
     *
     * Este método no arregla eso: lo **fija**. Si algún día el seed dejara de
     * truncar esas tablas, el acoplamiento entraría en la suite sin que nadie lo
     * decidiera y los dos tests de arriba pasarían a demostrar otra cosa. Es el
     * mismo mecanismo que el `count` de `phpstan.neon`: lo que no se puede
     * comprobar se escribe con nombre y número.
     */
    #[Test]
    public function el_acoplamiento_con_la_auditoria_no_existe_en_la_base_de_tests(): void
    {
        $permisos = (int) DB::selectOne(
            "SELECT COUNT(*) AS n FROM permissions WHERE name = 'can_view_auditoria'"
        )->n;

        $this->assertSame(0, $permisos,
            "`can_view_auditoria` ha aparecido en la base de tests.\n\n".
            "Hasta hoy no existía —el seed trunca `permissions` y `permission_role` DESPUÉS\n".
            "de migrar—, y por eso los tests de `Coord académico` de esta clase demuestran\n".
            "sólo el permiso del horario. Con la fila dentro pasan a demostrar algo más\n".
            "ancho, y quien los lea creerá que eso siempre fue así.\n\n".
            'Mira por qué cambió el seed antes de tocar este número.');

        // La segunda mitad, que es la que hace falso el enunciado corto «este rol
        // también ve la auditoría»: el acoplamiento es por FILA y por colegio, no
        // por código. `Autoriza::puedeVerAuditoria()` no pregunta por ningún rol,
        // lee `in_array('can_view_auditoria', $user->perms)`.
        $enlaces = (int) DB::selectOne(
            "SELECT COUNT(*) AS n FROM permission_role pr
               INNER JOIN roles r ON r.id = pr.role_id AND r.name = 'Coord académico'"
        )->n;

        $this->assertSame(0, $enlaces,
            '`Coord académico` tiene permisos enlazados en la base de tests. En producción '.
            'tiene `can_view_auditoria`; aquí no debería tener ninguno.');
    }

    /**
     * **Un rol creado por migración en 2026 no llega a la base de tests, y esto lo
     * fija.** Salió midiendo la autorización de este lote, y no es de horarios.
     *
     * `tools/construir-bd-test.sh` corre `migrate` **y después** carga
     * `test-seed.sql`, que hace `TRUNCATE TABLE roles` (línea 29686) igual que de
     * `role_user`, `permissions` y `permission_role`. Así que lo que una migración
     * de 2026 siembre en esas cuatro tablas **se borra a continuación**:
     *
     *     Coord académico   está — es de 2018 y viene DENTRO del seed
     *     Secretario        NO está — lo crea 2026_08_21_100000_create_rol_secretario
     *
     * **La consecuencia no es de este módulo, es de `Autoriza::esAdministrativo()`**,
     * que es `is_superuser || Role::isSecretario()` y lo leen otros seis sitios: en
     * la suite entera esa segunda mitad **no la ejerce nadie**, porque el rol no
     * existe y `hasRole()` devuelve `false` para todo el mundo. Un test que diga
     * «un Secretario puede X» está demostrando «un superusuario puede X», que es
     * menos — el mismo modo de fallo que `usuarioLlanoDelPersonal()` vino a cerrar
     * por el otro lado, y por eso los dos casos de esta clase que necesitan un rol
     * lo **fabrican** en vez de darlo por puesto.
     *
     * Este método no arregla el seed: **declara el número**, a la manera del
     * `count` de `phpstan.neon`. El día que se regenere trayendo los roles de las
     * migraciones, esto se pone rojo y hay que releer qué pasaron a demostrar los
     * tests que dependen de ellos.
     */
    #[Test]
    public function test_los_roles_que_crea_una_migracion_no_llegan_a_la_base_de_tests(): void
    {
        $hay = static fn (string $nombre): int => (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM roles WHERE name = ? AND deleted_at IS NULL', [$nombre]
        )->n;

        // La población antes que el veredicto: si `roles` viniera vacía, los dos
        // asertos de abajo saldrían «bien» sin haber mirado nada.
        $total = (int) DB::selectOne('SELECT COUNT(*) AS n FROM roles WHERE deleted_at IS NULL')->n;

        $this->assertGreaterThan(8, $total,
            "La tabla `roles` de la base de tests tiene {$total} filas y son 11.\n".
            'Con la tabla vacía este test aprueba sin comprobar nada.');

        $this->assertSame(1, $hay(self::ROL_QUE_PUBLICA),
            '`Coord académico` es de 2018 y viene dentro del seed: si falta, el seed cambió.');

        $this->assertSame(0, $hay(self::ROL_QUE_SUBE),
            "`Secretario` ha aparecido en la base de tests.\n\n".
            "Hasta hoy no estaba —lo crea una migración de ago 2026 y el seed hace TRUNCATE de\n".
            "`roles` justo después—, y por eso la rama `Role::isSecretario()` de\n".
            "`Autoriza::esAdministrativo()` no la ejercía nadie en toda la suite.\n\n".
            'Con el rol dentro sí se ejerce, que es mejor: repasa qué tests pasan a demostrar '.
            'algo distinto de lo que su nombre dice, y borra este número.');
    }
}
