<?php

namespace App\Services;

use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use Throwable;

/**
 * El único sitio que escribe en `auditoria`.
 *
 * Es la otra mitad de la fase 3 de
 * [docs/migracion/18-auditoria.md](../../docs/migracion/18-auditoria.md); la
 * primera es la migración de la tabla. Se escriben juntas porque **la tabla sin
 * el escritor volvería a tener diez criterios distintos**, que es exactamente de
 * lo que se viene: hoy hay **diez escrituras de bitácora repartidas en ocho
 * ficheros**, con tres convenciones de nombre para el tipo, dos relojes distintos
 * y nueve formas de adivinar de qué ingreso salió la escritura.
 *
 * (Ese «diez» va escrito con letra y sin la sentencia SQL entera **a propósito**:
 * `CentinelaDeLosEscritoresDeBitacoraTest` cuenta los escritores con un
 * `preg_match_all` de `INSERT\s+INTO\s+bitacoras` **sobre el texto del fichero**,
 * comentarios incluidos. Escrita aquí de la forma natural, esta explicación
 * contaba como un escritor más y ponía el centinela en rojo con «han pasado de 10
 * a 11». Lo cazó él, que es para lo que está; queda dicho para que nadie lo
 * reescriba de la forma natural otra vez.)
 *
 * Es el mismo movimiento que `DefinitivasDeAsignatura` hizo con las definitivas
 * ([10](../../docs/migracion/10-definitivas.md)): seis escritores con cinco
 * criterios reducidos a uno, y eso es lo que hizo el problema tratable.
 *
 * **Todavía no lo llama nadie, y es a propósito.** Instrumentar los dominios es
 * la fase 4 y va dominio a dominio, cada uno con su test de contrato. Los diez
 * escritores viejos de `bitacoras` **no se tocan aquí**: siguen escribiendo donde
 * escriben hasta que su dominio pase por la fase 4.
 *
 * ## La primera regla, que es la que se hace mal por defecto
 *
 * **La escritura ocurrió porque no hubo excepción, nunca porque haya filas.**
 *
 * `DB::update` devuelve filas **afectadas**, y MySQL devuelve **0 cuando el
 * UPDATE no cambia ningún valor**: guardar 85 encima de 85 es un guardado
 * correcto con 0 filas. Colgar la auditoría de un `if ($res)` registraría esa
 * escritura **como fallida teniendo el estado correcto**. Está medido en la §13
 * de [09-pendientes.md](../../docs/migracion/09-pendientes.md): **4 sitios y 6
 * rutas** contestan hoy `'No guardado'` con 200 por exactamente ese motivo, y la
 * auditoría se estaría enganchando al mismo error.
 *
 * Por eso **esta clase no tiene dónde recibir «cuántas filas salieron»**: no hay
 * parámetro, no hay método, y no es un olvido. Un reguardado sin cambio **sí se
 * registra** —alguien tocó esa nota, y «quién la tocó» es la pregunta que la
 * tabla existe para contestar— y se reconoce solo porque `valor_anterior` y
 * `valor_nuevo` quedan iguales. No hace falta columna nueva: la pantalla los
 * puede filtrar y el rastro no pierde nada.
 *
 * ## Las otras dos reglas de escritura
 *
 * - **Dentro de la misma transacción que el cambio.** Esta clase no abre
 *   ninguna: hace un `INSERT` y punto, así que si el llamante está en una
 *   transacción la línea entra en ella. Si el cambio no se guardó, no hay línea;
 *   si se guardó, la línea existe. Hoy la bitácora de `putUpdate` está dentro del
 *   `try` con el `UPDATE` pero **sin transacción**: un fallo entre las dos deja la
 *   nota cambiada y sin rastro.
 * - **Nunca abortar la petición por un fallo de auditoría, pero nunca fallar en
 *   silencio.** Que falte el rastro no puede impedir guardar la nota; que falte
 *   **sin que nadie se entere** sí es inaceptable. Por eso `guardar()` atrapa y
 *   registra en el log con la fila entera dentro.
 *
 * ## Append-only, y qué lo sostiene de verdad
 *
 * Aquí no hay `UPDATE` ni `DELETE` sobre `auditoria`, y la tabla no tiene
 * `updated_at` ni `deleted_at` (§4.4). Lo fija `AuditoriaEscritorUnicoTest`, que
 * recorre `app/` y falla si aparece uno.
 *
 * **Lo que ese test puede prometer y lo que no**: prueba que *este código* no
 * edita ni borra una línea. No impide un `UPDATE` a mano en phpMyAdmin, y eso
 * sólo se cierra quitándole al usuario de MySQL los permisos de `UPDATE` y
 * `DELETE` sobre esta tabla — que es una decisión de los dieciséis hostings, no
 * de este repositorio. Queda dicho para que nadie lo cuente como cerrado.
 *
 * ## Quién llama decide el **qué**, nunca el **quién**, el **cuándo** ni el **desde dónde**
 *
 * El actor, la sesión, la hora, la IP y la ruta los resuelve esta clase. Son
 * justo las cinco cosas que hoy cada sitio decide distinto, y la razón por la que
 * `bitacoras` no se puede leer como una línea de tiempo.
 *
 *     Auditoria::registrar()
 *         ->editar('nota', $id)
 *         ->deAlumno($alumno_id, $nombre)
 *         ->en(asignatura: $asignatura_id, periodo: $periodo_id)
 *         ->de($valorViejo)->a($valorNuevo)
 *         ->guardar();
 */
final class Auditoria
{
    /*
     * Las cinco acciones. Cuatro son escrituras; `DENEGADO` no lo es.
     *
     * `DENEGADO` existe porque de los nueve sitios que hoy adivinan el ingreso,
     * **siete son controladores y dos son middlewares**: `ExigirPersonaPropia` y
     * `ExigirBoletinPropio` no anotan un cambio, anotan *«a quién le dijimos que
     * no»*. No llevan valor viejo ni valor nuevo porque no se escribió nada.
     * Contarlos como nueve iguales metería dos denegaciones disfrazadas de
     * edición en la pantalla de «qué hizo en este ingreso», que es justo lo
     * contrario de lo que pasó.
     */
    public const CREAR = 'crear';

    public const EDITAR = 'editar';

    public const BORRAR = 'borrar';

    public const RESTAURAR = 'restaurar';

    public const DENEGADO = 'denegado';

    /** @var list<string> */
    public const ACCIONES = [self::CREAR, self::EDITAR, self::BORRAR, self::RESTAURAR, self::DENEGADO];

    /**
     * El vocabulario de `entidad`, cerrado, y **cada nombre con la tabla que
     * representa al lado**.
     *
     * La tabla no es documentación: la comprueba `AuditoriaEscritorUnicoTest`
     * contra el esquema real, así que un nombre inventado o mal escrito no llega
     * a producción. `affected_element_type` de `bitacoras` es texto libre y por
     * eso tiene hoy `Nota`, `NF_UPDATE`, `Nueva subunidad` y
     * `AlumnoPideAjeno:user_id` conviviendo: una pantalla que agrupe por tipo
     * tiene que conocer la lista de memoria y un tipo nuevo mal escrito se pierde
     * en silencio.
     *
     * La lista sale de los siete dominios de la fase 4, en su orden, más los tres
     * que ya se graban hoy. **Añadir uno es editar esta constante**, que es
     * justamente el punto: que sea una decisión y no un `string` suelto.
     *
     * `null` significa «no es una fila de ninguna tabla», y son cuatro: dos
     * sucesos de seguridad y los dos recursos que los middlewares protegen. Van
     * declarados en vez de omitidos para que el test pueda distinguir *«no tiene
     * tabla a propósito»* de *«se me olvidó»*.
     *
     * @var array<string, string|null>
     */
    public const ENTIDADES = [
        // 1 — notas. La petición de origen.
        'nota' => 'notas',
        'nota_final' => 'notas_finales',
        'recuperacion_final' => 'recuperacion_final',

        // 2 — unidades y subunidades. Hoy sólo se graba crear una subunidad.
        'unidad' => 'unidades',
        'subunidad' => 'subunidades',

        // 3 — asistencia y faltas.
        'ausencia' => 'ausencias',

        // 4 — comportamiento.
        'comportamiento' => 'nota_comportamiento',
        'definicion_comportamiento' => 'definiciones_comportamiento',

        // 5 — disciplina y situaciones.
        'dis_proceso' => 'dis_procesos',
        'dis_libro_rojo' => 'dis_libro_rojo',
        'dis_ordinal' => 'dis_ordinales',
        'accion_restaurativa' => 'dis_acciones_restaurativas',

        // 6 — frases del boletín.
        'frase' => 'frases',
        'frase_asignatura' => 'frases_asignatura',
        'frase_preescolar' => 'frases_preescolar',

        // 7 — lo que ya se graba hoy y no es ninguno de los anteriores.
        'year_config' => 'years',

        // Sin tabla, y declarado: no son filas, son sucesos o recursos.
        'intento_login' => null,      // `Services\Login`: un login fallido. Sin actor.
        'refresco_reutilizado' => null, // `Services\Sesion`: un token de refresco usado dos veces.
        'persona' => null,            // `ExigirPersonaPropia`: pidió la ficha de otro.
        'boletin' => null,            // `ExigirBoletinPropio`: pidió el boletín de otro.
    ];

    /*
     * De dónde sale el actor, y por qué esta constante existe.
     *
     * `User::fromToken()` memoriza el contexto en los atributos de la petición,
     * bajo una constante **privada** suya. Esta clase lo lee de ahí en vez de
     * llamar a `fromToken()` porque **`fromToken()` aborta con 401 cuando no hay
     * token**, y hay dos casos que tienen que poder escribir sin él: un
     * `intento_login` fallido —que por definición no tiene sesión— y el comando
     * de consola.
     *
     * Duplicar la clave es un acoplamiento, y por eso lleva test:
     * `AuditoriaEscritorUnicoTest` compara esta constante con la de `App\User` por
     * reflexión y falla si dejan de coincidir. Sin ese test, renombrarla allí
     * dejaría **todas las líneas sin actor y sin ningún error visible**, que es la
     * forma de fallo que este documento entero viene a cerrar.
     *
     * No se añade un accesor público a `App\User` porque ese fichero es de la
     * fase 2 —la que ata la sesión al token— y aquí no se toca lo de otra fase.
     */
    public const CLAVE_DEL_CONTEXTO = 'usuario.contexto';

    /** @var array<string, mixed> La fila que se va a escribir. */
    private array $fila = [
        'sesion_id' => null,
        'historial_id' => null,
        'actor_user_id' => null,
        'actor_persona_id' => null,
        'actor_tipo' => null,
        'actor_nombre' => null,
        'actor_intentado' => null,
        'accion' => null,
        'entidad' => null,
        'entidad_id' => null,
        'alumno_id' => null,
        'alumno_nombre' => null,
        'grupo_id' => null,
        'asignatura_id' => null,
        'periodo_id' => null,
        'year_id' => null,
        'valor_anterior' => null,
        'valor_nuevo' => null,
        'resumen' => null,
        'ip' => null,
        'ruta' => null,
        'atribucion' => 'aproximada',
    ];

    /** El contexto de quien actúa, si se le dio uno a mano. Null = se resuelve de la petición. */
    private ?object $actor = null;

    /** True cuando el llamante ha dicho explícitamente que no hay actor, o que es el sistema. */
    private bool $actorDecidido = false;

    private function __construct() {}

    /** Empieza una línea. Nada se escribe hasta `guardar()`. */
    public static function registrar(): self
    {
        return new self;
    }

    public function crear(string $entidad, ?int $id = null): self
    {
        return $this->accion(self::CREAR, $entidad, $id);
    }

    public function editar(string $entidad, ?int $id = null): self
    {
        return $this->accion(self::EDITAR, $entidad, $id);
    }

    public function borrar(string $entidad, ?int $id = null): self
    {
        return $this->accion(self::BORRAR, $entidad, $id);
    }

    public function restaurar(string $entidad, ?int $id = null): self
    {
        return $this->accion(self::RESTAURAR, $entidad, $id);
    }

    /**
     * Un intento rechazado. **No es una escritura**: no se guardó nada.
     *
     * Lo usan los dos middlewares que le dicen que no a alguien. Por eso no
     * admite valor viejo ni valor nuevo — no hay ninguno.
     */
    public function denegado(string $entidad, ?int $id = null): self
    {
        return $this->accion(self::DENEGADO, $entidad, $id);
    }

    /** Sobre quién. El nombre se congela en la fila: el alumno se puede borrar. */
    public function deAlumno(?int $alumnoId, ?string $nombre = null): self
    {
        $this->fila['alumno_id'] = $alumnoId;
        $this->fila['alumno_nombre'] = $this->recortar($nombre, 120);

        return $this;
    }

    /**
     * En qué contexto escolar.
     *
     * Los cuatro con nombre porque en las llamadas reales se pasan dos de los
     * cuatro y en distinto orden según el dominio: posicional se equivoca solo.
     */
    public function en(?int $asignatura = null, ?int $periodo = null, ?int $grupo = null, ?int $year = null): self
    {
        $this->fila['asignatura_id'] = $asignatura ?? $this->fila['asignatura_id'];
        $this->fila['periodo_id'] = $periodo ?? $this->fila['periodo_id'];
        $this->fila['grupo_id'] = $grupo ?? $this->fila['grupo_id'];
        $this->fila['year_id'] = $year ?? $this->fila['year_id'];

        return $this;
    }

    /** El valor de antes. Cualquier cosa que quepa en JSON: un número, una cadena, una fila entera. */
    public function de(mixed $valor): self
    {
        $this->fila['valor_anterior'] = $this->aJson($valor);

        return $this;
    }

    /** El valor de después. Igual al anterior si fue un reguardado sin cambio, y eso está bien. */
    public function a(mixed $valor): self
    {
        $this->fila['valor_nuevo'] = $this->aJson($valor);

        return $this;
    }

    /** La frase legible, si el dominio sabe construir una mejor que la de serie. */
    public function resumen(string $frase): self
    {
        $this->fila['resumen'] = $this->recortar($frase, 255);

        return $this;
    }

    /**
     * El actor, cuando no hay petición de por medio.
     *
     * Para el comando de consola y para los tests. En una petición normal **no se
     * llama**: el actor sale del contexto que ya resolvió `auth.token`, que es lo
     * que impide que cada sitio decida quién fue.
     */
    public function porElUsuario(object $usuario): self
    {
        $this->actor = $usuario;
        $this->actorDecidido = true;

        return $this;
    }

    /**
     * Sin actor: un `intento_login` fallido.
     *
     * `$intentado` es el username que se tecleó, que es lo único que se sabe. No
     * va en `actor_nombre` a propósito: ese campo dice **quién fue**, y aquí no se
     * sabe. Hoy `Login.php` resuelve esto poniendo `created_by = 0` — un id que no
     * existe disfrazado de id que sí.
     */
    public function sinActor(?string $intentado = null): self
    {
        $this->actor = null;
        $this->actorDecidido = true;
        $this->fila['actor_intentado'] = $this->recortar($intentado, 120);

        return $this;
    }

    /**
     * Lo hizo el sistema, no una persona.
     *
     * Hace falta desde ya: la definitiva que un profesor **teclea** y la que el
     * recalculador único **recalcula** no son la misma cosa, y si las dos entran
     * como `editar` de una persona, la pantalla se llena de ruido automático y
     * deja de leerse (fase 4, punto 3).
     */
    public function porElSistema(): self
    {
        $this->actor = null;
        $this->actorDecidido = true;
        $this->fila['actor_tipo'] = 'sistema';

        return $this;
    }

    /**
     * Escribe la línea. Devuelve su `id`, o `null` si no se pudo.
     *
     * **El `null` no es para decidir nada**: no se aborta la petición porque falte
     * el rastro (razonado ya en `putLote`). Está para los tests y para el que
     * quiera dejar constancia de segundo orden. Un fallo aquí ya va al log con la
     * fila entera dentro.
     */
    public function guardar(): ?int
    {
        try {
            $this->resolverActor();
            $this->resolverPeticion();
            $this->comprobarVocabulario();

            $this->fila['ocurrido_en'] = Reloj::ahoraTexto();
            $this->fila['resumen'] ??= $this->frase();

            return (int) DB::table('auditoria')->insertGetId($this->fila);
        } catch (Throwable $e) {
            /*
             * Con la fila entera dentro, y no sólo el mensaje: sin ella el log
             * dice que se perdió una línea y no cuál, que para reconstruir un
             * incidente no sirve de nada. Es lo mismo que hace
             * `ConsultasLentas`, y por el mismo motivo.
             *
             * `valor_anterior` y `valor_nuevo` ya van en JSON aquí, así que el
             * log no lleva ninguna estructura que no cupiera en la columna.
             *
             * **Y no lleva nada que no fuera a estar en la base de todos modos**,
             * que es la pregunta que hay que hacerse antes de escribir una fila
             * entera en el disco: por aquí no pasa ninguna credencial —esta clase
             * no ve contraseñas— y lo más sensible que puede llevar es una nota,
             * que es exactamente lo que la fila iba a guardar. La regla que dejó
             * `ConsultasLentas` en su cabecera —un `Log::info($token)` dejó tokens
             * de sesión en texto plano— no se rompe aquí.
             *
             * Si algún día un colegio despliega sin correr la migración, esto
             * escribe una línea por cada escritura del sistema. Es ruidoso a
             * propósito: el modo de fallo que no se puede permitir es el
             * silencioso.
             */
            Log::error('Auditoría no escrita: '.$e->getMessage(), ['fila' => $this->fila]);

            return null;
        }
    }

    /** Comparte cuerpo con las cinco de arriba, que sólo se diferencian en el verbo. */
    private function accion(string $accion, string $entidad, ?int $id): self
    {
        $this->fila['accion'] = $accion;
        $this->fila['entidad'] = $entidad;
        $this->fila['entidad_id'] = $id;

        return $this;
    }

    /**
     * El vocabulario se cierra aquí, que es donde se cumple igual en los dieciséis
     * colegios.
     *
     * No hay `CHECK` en la tabla a propósito: MySQL 8.0.16 lo cumple y 5.7 lo
     * **ignora en silencio**, y los dieciséis colegios están en cuentas de cPanel
     * distintas. Una restricción que se cumple en unos y no en otros es peor que
     * no tenerla, porque se cuenta como cumplida — es la misma enfermedad que
     * `@@session.time_zone = SYSTEM` (§1.2).
     */
    private function comprobarVocabulario(): void
    {
        if (! in_array($this->fila['accion'], self::ACCIONES, true)) {
            throw new InvalidArgumentException(
                "Acción de auditoría desconocida: '{$this->fila['accion']}'. ".
                'Las cinco están en Auditoria::ACCIONES.'
            );
        }

        if (! array_key_exists((string) $this->fila['entidad'], self::ENTIDADES)) {
            throw new InvalidArgumentException(
                "Entidad de auditoría desconocida: '{$this->fila['entidad']}'. ".
                'Añadirla es editar Auditoria::ENTIDADES, con la tabla que representa al lado.'
            );
        }
    }

    /**
     * Quién, y de qué sesión.
     *
     * Si nadie lo dijo, sale del contexto que `auth.token` ya resolvió — nunca se
     * fuerza una resolución nueva, que costaría de 5 a 8 consultas y abortaría con
     * 401 donde no hay token.
     *
     * **`sesion_id` e `historial_id` se escriben sólo si el contexto los trae**, y
     * ahí está la decisión que más importa de este método: mientras la fase 2 no
     * esté desplegada, el contexto no los tiene y **aquí se escribe NULL**. No se
     * adivina con `order by id desc limit 1` sobre `historiales`, que es lo que
     * hacen los nueve sitios de hoy y lo que da la lista falsa: con el refresco
     * rotando 14 días, ese «último ingreso» puede ser de hace meses. Un NULL dice
     * «no se sabe»; la adivinanza dice «fue ése» y se equivoca sin avisar.
     */
    private function resolverActor(): void
    {
        $usuario = $this->actor;

        if ($usuario === null && ! $this->actorDecidido) {
            $atributos = Request::instance()->attributes;

            $memorizado = $atributos->has(self::CLAVE_DEL_CONTEXTO)
                ? $atributos->get(self::CLAVE_DEL_CONTEXTO)
                : null;

            $usuario = is_object($memorizado) ? $memorizado : null;
        }

        if ($usuario === null) {
            return;
        }

        $this->fila['actor_user_id'] = $this->entero($usuario->user_id ?? null);
        $this->fila['actor_persona_id'] = $this->entero($usuario->persona_id ?? null);
        $this->fila['actor_tipo'] = $this->recortar($usuario->tipo ?? null, 20);
        $this->fila['actor_nombre'] = $this->recortar(
            trim(($usuario->nombres ?? '').' '.($usuario->apellidos ?? '')) ?: ($usuario->username ?? null),
            120
        );

        $this->fila['year_id'] ??= $this->entero($usuario->year_id ?? null);

        $sesion = $this->entero($usuario->sesion_id ?? null);

        if ($sesion !== null) {
            $this->fila['sesion_id'] = $sesion;
            $this->fila['historial_id'] = $this->entero($usuario->historial_id ?? null);
            $this->fila['atribucion'] = 'sesion';
        }
    }

    /**
     * Desde dónde llegó.
     *
     * La ruta se guarda **con su patrón**, no con la URL resuelta: `PUT
     * notas/update/{id}` y no `PUT notas/update/8412`. El id concreto ya está en
     * `entidad_id`, y el patrón es lo que permite agrupar por endpoint. Se le
     * quita el prefijo `api/`, que lo llevan las 542.
     */
    private function resolverPeticion(): void
    {
        $peticion = Request::instance();

        $this->fila['ip'] = $this->recortar($peticion->ip(), 45);

        $ruta = $peticion->route();

        if ($ruta !== null) {
            $this->fila['ruta'] = $this->recortar(
                $peticion->method().' '.preg_replace('#^api/#', '', $ruta->uri()),
                120
            );
        }
    }

    /**
     * La frase de serie, para cuando el dominio no da una mejor.
     *
     * Se construye con lo que hay **en la fila**, no saliendo a buscar a otras
     * tablas: ése es el punto entero del denormalizado (§4.2). Hoy escribir
     * «cambió la nota de Ana Pérez en Matemáticas» obliga a visitar seis tablas, y
     * si cualquiera de esas filas se borró después, la línea deja de poder leerse.
     */
    private function frase(): string
    {
        $verbo = [
            self::CREAR => 'creó',
            self::EDITAR => 'editó',
            self::BORRAR => 'borró',
            self::RESTAURAR => 'restauró',
            self::DENEGADO => 'no pudo ver',
        ][$this->fila['accion']] ?? $this->fila['accion'];

        $quien = $this->fila['actor_nombre']
            ?? ($this->fila['actor_tipo'] === 'sistema' ? 'El sistema' : null)
            ?? ($this->fila['actor_intentado'] !== null ? 'Alguien como «'.$this->fila['actor_intentado'].'»' : 'Alguien');

        $frase = $quien.' '.$verbo.' '.str_replace('_', ' ', (string) $this->fila['entidad']);

        if ($this->fila['entidad_id'] !== null) {
            $frase .= ' '.$this->fila['entidad_id'];
        }

        if ($this->fila['alumno_nombre'] !== null) {
            $frase .= ' de '.$this->fila['alumno_nombre'];
        }

        return $this->recortar($frase, 255) ?? $frase;
    }

    /**
     * A JSON, y `null` se queda en `null`.
     *
     * `json_encode(null)` da la cadena `'null'`, que en una columna `json` es un
     * null **de JSON** y no un NULL de SQL: `valor_anterior IS NULL` dejaría de
     * encontrarlo. Son dos cosas distintas y la pantalla filtra por la segunda.
     */
    private function aJson(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /**
     * Recorta a lo que cabe en la columna.
     *
     * No es cosmética: con el modo estricto de MySQL, un nombre de 130 caracteres
     * en un `varchar(120)` es una excepción, y esta clase se la traga —así que la
     * línea **no se escribiría**—. Entre una línea recortada y ninguna línea, la
     * recortada. Se cuenta por caracteres y no por bytes porque las columnas son
     * `utf8mb4`.
     */
    private function recortar(?string $texto, int $limite): ?string
    {
        if ($texto === null || $texto === '') {
            return null;
        }

        return mb_substr($texto, 0, $limite);
    }

    /** El contexto trae `"N/A"` en varias columnas y `null` en otras; ninguno de los dos es un id. */
    private function entero(mixed $valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }
}
