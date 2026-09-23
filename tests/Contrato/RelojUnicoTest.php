<?php

namespace Tests\Contrato;

use App\Models\Acudiente;
use App\Models\Alumno;
use App\Models\Area;
use App\Models\Asignatura;
use App\Models\Ausencia;
use App\Models\Bitacora;
use App\Models\ChangeAsked;
use App\Models\Ciudad;
use App\Models\ConfigCertificado;
use App\Models\Contrato;
use App\Models\Debugging;
use App\Models\DefinicionComportamiento;
use App\Models\EscalaDeValoracion;
use App\Models\Frase;
use App\Models\FraseAsignatura;
use App\Models\Grado;
use App\Models\Grupo;
use App\Models\ImageModel;
use App\Models\Materia;
use App\Models\Matricula;
use App\Models\NivelEducativo;
use App\Models\Nota;
use App\Models\NotaComportamiento;
use App\Models\NotaFinal;
use App\Models\Pais;
use App\Models\Parentesco;
use App\Models\Periodo;
use App\Models\Permission;
use App\Models\Profesor;
use App\Models\Role;
use App\Models\Rubrica;
use App\Models\RubricaCriterio;
use App\Models\RubricaDescriptor;
use App\Models\RubricaNivel;
use App\Models\RubricaValoracion;
use App\Models\Subunidad;
use App\Models\TipoDocumento;
use App\Models\TokenDeSesion;
use App\Models\Unidad;
use App\Models\VtAspiracion;
use App\Models\VtCandidato;
use App\Models\VtVotacion;
use App\Models\VtVoto;
use App\Models\WsActividad;
use App\Models\WsActividadCompartida;
use App\Models\WsActividadResuelta;
use App\Models\WsOpcion;
use App\Models\WsPregunta;
use App\Models\WsRespuesta;
use App\Models\Year;
use App\Support\Reloj;
use App\Support\SellaConElReloj;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Que no vuelvan los dos relojes.
 *
 * `bitacoras.created_at` llegó a tener **12 filas escritas en UTC y 74 en
 * Bogotá** —medido el 24 ago 2026 con `tools/salud-de-la-bitacora.php`—, cinco
 * horas de diferencia dentro de la misma columna y nada en la fila que diga cuál
 * es cuál. Ordenar por ella no da una línea de tiempo. La fase 1 de
 * [docs/migracion/18-auditoria.md](../../docs/migracion/18-auditoria.md) lo
 * arregla con `App\Support\Reloj`, y esto es lo que impide que se deshaga.
 *
 * **Una regla sin test se deshace sola**, y ésta se deshace de la peor manera: un
 * `now()` nuevo en un sitio que escribe una fecha no rompe nada, no falla ningún
 * test, y mete una fila cinco horas movida que **nadie va a poder distinguir
 * después**. No hay forma de repararlo a posteriori porque no hay marca. Por eso
 * el centinela va sobre el código y no sobre el resultado: cuando el síntoma se
 * puede ver ya es tarde.
 */
class RelojUnicoTest extends TestCase
{
    /**
     * Los usos de reloj SIN zona que quedan, y por qué cada uno puede quedarse.
     *
     * Fichero => cuántas llamadas. Se cuenta por fichero y no por línea a
     * propósito: las líneas se mueven al editar y un centinela que salte por un
     * cambio de formato se acaba desactivando, que es peor que no tenerlo.
     *
     * **Todos los de esta lista tienen algo en común: no guardan una fecha que
     * alguien vaya a leer.** O se restan consigo mismos, o son un TTL relativo.
     * Ése es el criterio para entrar aquí, y el único.
     *
     * @var array<string, int>
     */
    private const PERMITIDOS = [
        // Diferencia contra `expires_at`, que se escribe con este mismo reloj.
        // Las dos puntas en UTC: la resta sale bien y no se guarda nada.
        'app/Models/TokenDeSesion.php' => 1,

        // Las cinco gobiernan la vida de los tokens (`expires_at`,
        // `last_used_at`, la gracia del refresco y el barrido de caducados).
        // Sólo se comparan con columnas escritas por ellas mismas, y ninguna
        // sale por pantalla. La sexta de este fichero SÍ se movió: escribía en
        // `bitacoras.created_at`, que es de todos.
        'app/Services/Sesion.php' => 5,

        // El TTL de la caché del token de FCM. Relativo (55 minutos desde
        // ahora), nunca guardado ni mostrado.
        'app/Services/Notificaciones/EnvioFcm.php' => 1,

        // El corte de antigüedad para limpiar sesiones, contra `expires_at`,
        // que está en UTC. Cambiarlo a Bogotá sin cambiar la columna es lo que
        // rompería la comparación.
        'app/Console/Commands/LimpiarSesiones.php' => 1,

        // **Y AQUÍ VIVÍA LA EXCEPCIÓN, hasta el 22 sep 2026.**
        //
        // `app/Services/PuntoDeControlDeImportacion.php` tenía catorce `now()` y era
        // el único sitio del repo que **guardaba** fechas sin zona: `importaciones`
        // entera en UTC. El motivo estaba escrito y era bueno —`inicio` y `fin` sólo
        // se restan entre sí— y el motivo para no moverla también: las filas viejas
        // se quedarían cinco horas por delante, dejando la columna con dos relojes,
        // que es la enfermedad que este fichero existe para impedir.
        //
        // **Se movió cuando alguien miró el producto en vez del código.** La tabla
        // nació el 20 ago 2026; la importación de alumnos es para principios de año
        // y la de notas la está construyendo el front. **Ningún colegio la había
        // usado.** Sin filas viejas no hay dos relojes que crear, así que la mudanza
        // que llevaba un mes bloqueada por su precio resultó no tener ninguno.
        //
        // Queda escrito porque la lección no es de esta tabla: **un coste que
        // bloquea una decisión se mide sobre los datos que hay, no sobre los que la
        // tabla podría tener.** Aquí el coste era cero y nadie lo había comprobado.
        //
        // La premisa se comprueba antes de desplegar, no ahora:
        // `SELECT COUNT(*) FROM importaciones;` en los diecisiete.
    ];

    /**
     * Los modelos cuyo sello va en Bogotá, y **por qué justo éstos**.
     *
     * El criterio es uno, se mide y no se opina: **su tabla ya recibe
     * `created_at`/`updated_at` escritos a mano en Bogotá por alguna sentencia de
     * `app/`**. Donde eso pasa, el rasgo REDUCE la mezcla; donde no, la CREA.
     *
     * La medición está en el 53 §1 y se rehace buscando la CADENA SQL, no la
     * llamada: el patrón de la casa es `$consulta = 'UPDATE users SET …
     * updated_at=:fecha'` y ejecutar después, así que un detector que sólo mire
     * dentro de `DB::update(` se deja la mitad. Eso costó tres intentos el 21 sep
     * 2026, y los tres primeros dieron números distintos y plausibles.
     *
     * @var list<class-string<Model>>
     */
    private const SELLAN_EN_BOGOTA = [
        Acudiente::class,
        Alumno::class,
        Asignatura::class,
        Ausencia::class,
        Bitacora::class,
        ChangeAsked::class,
        EscalaDeValoracion::class,
        ImageModel::class,
        Matricula::class,
        Nota::class,
        NotaComportamiento::class,
        NotaFinal::class,
        Parentesco::class,
        Rubrica::class,
        RubricaDescriptor::class,
        RubricaValoracion::class,
        Subunidad::class,
        Unidad::class,
        Year::class,
        User::class,
    ];

    /**
     * Los que se quedan en UTC, **cada uno con su motivo**, que es lo que se revisa.
     *
     * Casi todos comparten el mismo: su columna está entera en UTC porque nadie le
     * escribe el sello a mano, así que el rasgo no arreglaría una mezcla —crearía
     * una—. Los que tienen un motivo distinto lo llevan escrito encima.
     *
     * @var array<class-string<Model>, true>
     */
    private const SELLAN_EN_UTC = [
        // El motivo compartido por los que no llevan uno propio:
        // Medido el 21 sep 2026: ninguna sentencia de `app/` le escribe `created
        // _at`/`updated_at` a mano, así que su columna está ENTERA en UTC. El rasgo no reduciría una mezcla: la crearía.
        Area::class => true,
        Ciudad::class => true,
        ConfigCertificado::class => true,
        Contrato::class => true,
        Debugging::class => true,
        DefinicionComportamiento::class => true,
        Frase::class => true,
        FraseAsignatura::class => true,
        Grado::class => true,

        // Medido: CERO escrituras de su sello a mano. Está entero en UTC y ponerle
        // el rasgo CREARÍA la mezcla en vez de reducirla.
        Grupo::class => true,
        Materia::class => true,
        NivelEducativo::class => true,
        Pais::class => true,

        // Igual, y se le puso el rasgo por error el 21 sep 2026 contando
        // escrituras a la tabla en vez de escrituras del SELLO. `UPDATE periodos
        // SET profes_pueden_editar_notas = 0` no toca `updated_at`.
        Periodo::class => true,
        Permission::class => true,

        // Igual que `Periodo`, y por el mismo error: `UPDATE profesores SET tono =
        // ?` escribe la tabla, no el sello.
        Profesor::class => true,
        Role::class => true,
        RubricaCriterio::class => true,
        RubricaNivel::class => true,
        TipoDocumento::class => true,

        // Sus fechas se comparan contra un `now()` de UTC —`expires_at`, la gracia
        // del refresco, el barrido de caducados— y moverle el reloj cinco horas le
        // cambia la VIDA ÚTIL a las sesiones. Es el único de esta lista que se
        // rompería, no sólo que se mezclaría.
        TokenDeSesion::class => true,
        VtAspiracion::class => true,
        VtCandidato::class => true,
        VtVotacion::class => true,
        VtVoto::class => true,
        WsActividad::class => true,
        WsActividadCompartida::class => true,
        WsActividadResuelta::class => true,
        WsOpcion::class => true,
        WsPregunta::class => true,
        WsRespuesta::class => true,
    ];

    #[Test]
    public function el_reloj_guarda_en_bogota(): void
    {
        $this->assertSame('America/Bogota', Reloj::ZONA);
        $this->assertSame('America/Bogota', Reloj::ahora()->timezoneName);

        // Y que de verdad difiere de UTC, que es lo que se está previniendo. Sin
        // esto, un `config/app.php` puesto en Bogotá haría pasar el test de
        // arriba sin que `Reloj` hiciera nada.
        $this->assertNotSame(
            Carbon::now()->format('Y-m-d H:i'),
            Reloj::ahora()->format('Y-m-d H:i'),
            'El reloj de la aplicación y el de Bogotá dan la misma hora. O se '.
            'cambió `config/app.php` —y entonces hay que revisar la decisión 2 '.
            'del 18— o `Reloj` dejó de aplicar la zona.'
        );
    }

    #[Test]
    public function el_texto_lleva_milisegundos(): void
    {
        // `auditoria.ocurrido_en` es DATETIME(3): dos notas tecleadas en el mismo
        // segundo son dos líneas distintas del historial, y con precisión de
        // segundo no se sabe cuál fue primero.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/',
            Reloj::ahoraTexto()
        );
    }

    /**
     * El camino de vuelta, que es el que faltaba.
     *
     * Lo encontró `8myvc-39` con **17.999 segundos** de diferencia —las cinco
     * horas al segundo— leyendo `auditoria.ocurrido_en` con `strtotime()`. Una
     * cadena `DATETIME` **no lleva la zona dentro** y `config/app.php` está en
     * UTC, así que quien la lea sin decirla la mueve cinco horas y devuelve algo
     * que parece correcto.
     */
    #[Test]
    public function el_texto_vuelve_en_la_misma_hora_en_la_que_salio(): void
    {
        $salida = Reloj::ahoraTexto();
        $vuelta = Reloj::desdeTexto($salida);

        $this->assertNotNull($vuelta);
        $this->assertSame(Reloj::ZONA, $vuelta->timezoneName);
        $this->assertSame($salida, $vuelta->format('Y-m-d H:i:s.v'),
            'La ida y la vuelta no dan la misma hora: el viaje redondo está roto.');

        // Y la comparación que de verdad muerde: leerlo mal mueve cinco horas.
        //
        // El signo importa y me lo comí a la primera: Bogotá es **UTC−5**, así que
        // la misma hora de pared leída como UTC es un instante ANTERIOR, no
        // posterior. `$vuelta` (bien) va 18.000 segundos por delante de `$mal`.
        $mal = Carbon::createFromFormat('Y-m-d H:i:s.v', $salida);   // sin zona -> UTC
        $this->assertSame(
            5 * 3600,
            (int) round($vuelta->getTimestamp() - $mal->getTimestamp()),
            'Si esto deja de ser 18.000 segundos, o cambió la zona o cambió '.
            '`config/app.php`, y las dos cosas obligan a revisar el 18.'
        );
    }

    /** Una columna vacía o corrupta no se convierte en una hora plausible. */
    #[Test]
    public function un_texto_que_no_es_una_fecha_devuelve_null(): void
    {
        $this->assertNull(Reloj::desdeTexto(null));
        $this->assertNull(Reloj::desdeTexto(''));
        $this->assertNull(Reloj::desdeTexto('vete a saber'));
    }

    /** Y las columnas viejas, que son DATETIME sin milisegundos. */
    #[Test]
    public function tambien_lee_las_columnas_sin_milisegundos(): void
    {
        $fecha = Reloj::desdeTexto('2026-08-24 03:51:13');

        $this->assertNotNull($fecha);
        $this->assertSame('2026-08-24 03:51:13', $fecha->format('Y-m-d H:i:s'));
        $this->assertSame(Reloj::ZONA, $fecha->timezoneName);
    }

    #[Test]
    public function no_hay_relojes_sin_zona_nuevos(): void
    {
        $encontrados = $this->relojesSinZona();
        $esperados = self::PERMITIDOS;

        ksort($encontrados);
        ksort($esperados);

        $this->assertSame(
            $esperados,
            $encontrados,
            "Ha cambiado el reparto de `now()` / `Carbon::now()` sin zona en `app/`.\n\n".
            "Si has AÑADIDO uno: mira si lo que escribes acaba en una columna de la base.\n".
            "  - Si acaba en la base            -> usa App\\Support\\Reloj::ahora().\n".
            "  - Si sólo se compara consigo mismo o es un TTL relativo -> añádelo a\n".
            "    PERMITIDOS de este test CON EL MOTIVO, que es lo que se revisa.\n\n".
            "Por qué importa: un `now()` en un sitio que guarda una fecha no rompe nada,\n".
            "no falla ningún test, y mete una fila cinco horas movida que después NADIE\n".
            "puede distinguir de una buena. `bitacoras` ya tiene 12 así. Ver 18 §1.1.\n\n".
            'Encontrado ahora: '.json_encode($encontrados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function los_tres_escritores_de_bitacora_usan_el_reloj(): void
    {
        // El corazón de la fase 1: los tres que escribían `bitacoras.created_at`
        // con el reloj equivocado. Comprobar el reparto global no basta —alguien
        // podría devolverlos a `now()` y ajustar PERMITIDOS de paso—, así que
        // estos tres se nombran uno a uno.
        $movidos = [
            'app/Http/Middleware/ExigirPersonaPropia.php',
            'app/Http/Middleware/ExigirBoletinPropio.php',
            'app/Services/Sesion.php',
        ];

        foreach ($movidos as $fichero) {
            $codigo = (string) file_get_contents(base_path($fichero));

            $this->assertStringContainsString(
                'Reloj::ahora()',
                $codigo,
                "{$fichero} ya no usa `Reloj::ahora()`. Escribe en `bitacoras.created_at`, ".
                'que es una columna compartida con otros siete escritores en hora de Bogotá.'
            );
        }
    }

    /**
     * Los `now()` / `Carbon::now()` sin zona que hay en `app/`, por fichero.
     *
     * Cuenta llamadas y no líneas: `PuntoDeControlDeImportacion` tiene una línea
     * con tres. Y descarta comentarios, porque este repo explica sus decisiones
     * de zona por escrito y media docena de cabeceras mencionan `now()` — la
     * primera versión de este contador las incluyó y dio de más.
     *
     * @return array<string, int>
     */
    private function relojesSinZona(): array
    {
        $encontrados = [];

        /** @var iterable<\SplFileInfo> $ficheros */
        $ficheros = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'))
        );

        foreach ($ficheros as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $cuantos = 0;

            foreach (file($fichero->getPathname()) ?: [] as $linea) {
                $limpia = ltrim($linea);

                if (str_starts_with($limpia, '*') || str_starts_with($limpia, '//') || str_starts_with($limpia, '/*')) {
                    continue;
                }

                $cuantos += preg_match_all('/Carbon::now\(\s*\)|(?<![>$\w:\'"-])now\(\s*\)/', $linea);
            }

            if ($cuantos > 0) {
                $encontrados[str_replace(base_path().'/', '', $fichero->getPathname())] = $cuantos;
            }
        }

        return $encontrados;
    }

    /**
     * **Y los modelos del sello de las definitivas sellan en el reloj de la casa.**
     *
     * El otro camino por el que entra una fecha en UTC no es un `now()` escrito a
     * mano: son los `timestamps` automáticos de Eloquent, que salen de
     * `config/app.php`. El censo de arriba **no los ve** —no hay `now()` en el
     * fichero— y aun así un `->save()` deja la columna cinco horas movida.
     *
     * Estas cuatro tablas son las que alimentan
     * `DefinitivasDeAsignatura::selloDeVersion()`, y las cuatro se escriben por los
     * dos caminos: Eloquent y SQL a mano. Medido el 21 sep 2026 en la copia de
     * `caz_zaragoza`, emparejando cada nota con la subunidad de la que nace —se
     * crean en la misma petición—: **34.903 pares separados 18.000 segundos
     * exactos y 6.188 en el mismo segundo**, las dos familias de 2018 a 2026.
     *
     * Quitar el rasgo no rompe ninguna otra prueba: la fecha se guarda igual, sólo
     * que movida. Por eso hace falta éste.
     *
     * @see SellaConElReloj
     */
    #[Test]
    public function los_modelos_del_sello_sellan_en_bogota(): void
    {
        $modelos = [
            Nota::class,
            Subunidad::class,
            Unidad::class,
            Matricula::class,
        ];

        foreach ($modelos as $clase) {
            $sello = (new $clase)->freshTimestamp();

            $this->assertSame(Reloj::ZONA, $sello->timezoneName,
                "{$clase} volvió a sellar con el reloj de Eloquent. Sus fechas conviven en la misma "
                .'columna con las que escribe SQL a mano en Bogotá, así que ahí eso son cinco horas '
                .'de diferencia y nada en la fila que diga cuál es cuál.');
        }
    }

    /**
     * **Y el reparto ENTERO, que es lo que este fichero no sabía mirar.**
     *
     * El censo de `no_hay_relojes_sin_zona_nuevos` lee el código y cuenta `now()`.
     * Por construcción **no puede ver un `->save()`**: `Model::freshTimestamp()`
     * devuelve `Carbon::now()` sin que aparezca un solo `now()` en el fichero del
     * modelo, y aun así rellena `created_at`, `updated_at` y —con `SoftDeletes`—
     * `deleted_at`. Un modelo nuevo entra en UTC sin que nada se ponga rojo.
     *
     * Por eso esto no comprueba una lista de los que deben estar bien: comprueba
     * **el reparto de los dos grupos contra el esperado**, igual que el censo de
     * `now()`. Un modelo nuevo no cae en ninguno de los dos y el test lo dice.
     *
     * El criterio para entrar en {@see SELLAN_EN_UTC} es uno solo y se comprueba
     * midiendo, no opinando: **que su tabla no reciba ya fechas en Bogotá por otro
     * camino**. Si las recibe, el rasgo REDUCE la mezcla y va puesto; si no, el
     * rasgo la CREA, que es la enfermedad que {@see Reloj} vino a curar.
     */
    #[Test]
    public function ningun_modelo_sella_en_utc_sin_estar_declarado(): void
    {
        $encontrados = [];

        foreach ($this->modelosDelProyecto() as $clase) {
            $modelo = new $clase;

            if (! $modelo->usesTimestamps()) {
                continue;   // No sella nada: no tiene reloj que equivocar.
            }

            $encontrados[$clase] = $modelo->freshTimestamp()->timezoneName;
        }

        $esperados = [];

        foreach (self::SELLAN_EN_BOGOTA as $clase) {
            $esperados[$clase] = Reloj::ZONA;
        }

        foreach (array_keys(self::SELLAN_EN_UTC) as $clase) {
            $esperados[$clase] = 'UTC';
        }

        ksort($encontrados);
        ksort($esperados);

        $this->assertSame(
            $esperados,
            $encontrados,
            "Ha cambiado el reparto de relojes de los `timestamps` de Eloquent.\n\n".
            "Si has AÑADIDO un modelo: mira si su tabla YA recibe fechas en Bogotá por\n".
            "otro camino (`INSERT INTO <tabla>` o `DB::table('<tabla>')` en `app/`).\n".
            "  - Si las recibe   -> `use App\\Support\\SellaConElReloj;` y a SELLAN_EN_BOGOTA.\n".
            "  - Si NO las recibe -> a SELLAN_EN_UTC **CON EL MOTIVO**, que es lo que se revisa.\n\n".
            "Por qué importa: `Model::freshTimestamp()` sale de `config/app.php` —UTC— y no\n".
            "aparece ningún `now()` en el fichero, así que el censo de arriba NO LO VE. Un\n".
            "`->save()` deja la columna cinco horas movida sin romper nada. Ver el 53 §1.\n\n".
            'Encontrado ahora: '.json_encode($encontrados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Los modelos del proyecto: `app/Models/` **y `app/User.php`**.
     *
     * El segundo va nombrado a mano y no por el barrido, porque **no está en
     * `app/Models/`**: escribe `users` con ~19 `->save()` y cualquier censo que
     * recorra esa carpeta se lo deja fuera. Se descubrió justo así, el 21 sep 2026.
     *
     * @return list<class-string<Model>>
     */
    private function modelosDelProyecto(): array
    {
        $clases = ['App\\User'];

        foreach (glob(base_path('app/Models/*.php')) ?: [] as $fichero) {
            $clases[] = 'App\\Models\\'.basename($fichero, '.php');
        }

        return array_values(array_filter($clases, static fn (string $c): bool => class_exists($c)
            && is_subclass_of($c, Model::class)));
    }
}
