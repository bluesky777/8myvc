<?php

namespace Tests\Contrato;

use App\Support\AnioCerrado;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Los tres `store` de catálogo que el 14 sep se dejaron fuera por una premisa que
 * no era cierta** — `frases/store`, `escalas/store` y `POST contratos`.
 *
 * Decisión de Joseth del 15 sep 2026: se cierran, y la lista del año cerrado sube
 * de **diez a trece**. El antes y el después está en
 * [16-escribir-en-un-anio-pasado.md](../../docs/migracion/16-escribir-en-un-anio-pasado.md).
 *
 * ## La premisa que falló, porque es lo que este fichero existe para que no vuelva
 *
 * Aquel día se razonó que estos tres no necesitaban candado **porque estampan
 * `$user->year_id` al crear, «así que no pueden sembrar fuera de su año»**. Las dos
 * mitades son ciertas por separado y la conclusión no se sigue: lo que faltaba era
 * preguntar **quién escribe el año de la sesión**. Lo escribe la barra de año, y es
 * `auth.personal`:
 *
 *     PUT years/useractive/{year_id}    <- no mira si el año está cerrado
 *       YearsController:758-778         <- $usuario->periodo_id = <un periodo de ese año>
 *       ContextoDeUsuario:169           <- left join years y on y.id=per.year_id
 *       $user->year_id                  <- el año cerrado
 *
 * Lo levantó `myvc_front` implementando su lado de los 403, y **esta sesión había
 * dado por buena la conclusión contraria**: leyó que el año se deriva de
 * `periodo_id` y dedujo «luego no lo elige una pantalla», dando la cadena por
 * cerrada un eslabón antes del que decidía.
 *
 * **Por eso el primer caso de este fichero no comprueba ningún candado: comprueba
 * la premisa.** Una decisión que se toma sobre un hecho necesita que ese hecho
 * tenga un test, o vuelve a decidirse mal la próxima vez — y ni el código ni el
 * documento lo habrían delatado, porque los dos decían lo mismo y los dos estaban
 * equivocados.
 *
 * ## Y por qué cerrarlos, que no es simetría por simetría
 *
 * El argumento que dejó fuera a `escalas/store` sigue siendo cierto en su mitad
 * buena: la banda nace en **91–100**, por encima del techo de las escalas reales,
 * así que **no recoge ninguna nota y no cambia ni un boletín**. Lo que inclina la
 * decisión es la asimetría con el borrado: **`deleteDestroy` sí llevaba candado en
 * los tres**. O sea que cualquiera de los 74 podía crear en un año cerrado y **no
 * podía luego borrar lo creado**. La única puerta abierta era la que fabrica filas
 * permanentes, que es la peor forma de tener una puerta abierta.
 */
class AltaEnUnAnioCerradoTest extends CasoDeContrato
{
    // ── La premisa, que es lo que costó la decisión ──────────────────────────

    /**
     * **La barra de año mete la sesión en un año cerrado**, y desde ahí un alta
     * siembra ahí.
     *
     * Se comprueba por los dos extremos a propósito:
     *
     *  - **la fila**, que `users.periodo_id` acabó en un periodo del año cerrado —lo
     *    que demuestra que `putUseractive` escribe esa columna y no `users.year_id`—;
     *  - **y lo que la API contesta**, que es lo que de verdad decide: `GET frases`
     *    filtra por `$user->year_id`, así que si después de mover la barra devuelve
     *    la frase del año cerrado, el contexto siguió a la barra.
     *
     * Lo segundo sin lo primero no distinguiría un `year_id` movido de un listado
     * que dejó de filtrar; lo primero sin lo segundo probaría la escritura y no la
     * derivación, que es justo el eslabón que se dio por supuesto.
     */
    #[Test]
    public function la_barra_de_anio_mete_la_sesion_en_un_anio_cerrado(): void
    {
        $cerrado = $this->unAnioCerradoConPeriodos();
        $usuario = $this->usuarioLlanoDelPersonal();
        $token = $this->tokenDe($usuario->username);

        // Una frase que sólo existe en el año cerrado: es la que hace observable el
        // cambio de contexto. Sin ella, «el listado no trae nada» se cumpliría igual
        // con la barra rota.
        $fraseId = DB::table('frases')->insertGetId([
            'frase' => 'FRASE SÓLO DEL AÑO CERRADO',
            'tipo_frase' => 'Fortaleza',
            'year_id' => $cerrado->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $antes = array_column($this->withToken($token)->getJson('/api/frases')->json(), 'id');

        $this->assertNotContains($fraseId, $antes,
            'La frase del año cerrado ya salía ANTES de mover la barra: este caso no mediría el cambio.');

        $this->olvidarControladores();

        $this->withToken($token)->putJson('/api/years/useractive/'.$cerrado->id)->assertStatus(200);

        // Extremo 1: la columna que se escribió es `periodo_id`, y apunta al año cerrado.
        $anioDeLaSesion = DB::selectOne('SELECT p.year_id FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id WHERE u.id = ?', [$usuario->id]);

        $this->assertSame((int) $cerrado->id, (int) $anioDeLaSesion->year_id,
            '`putUseractive` no dejó al usuario en el año cerrado.');

        $this->olvidarControladores();

        // Extremo 2: y la API lo cree, que es lo que decide.
        $despues = array_column($this->withToken($token)->getJson('/api/frases')->json(), 'id');

        $this->assertContains($fraseId, $despues,
            "Tras mover la barra a {$cerrado->year}, `GET frases` no devuelve la frase de ese año.\n"
            .'Si esto falla, la premisa del 14 sep era cierta después de todo y los tres `store` '
            .'sobran en la lista — pero entonces la barra de año tampoco funcionaría, y funciona '
            .'en los dieciséis colegios.');
    }

    // ── Las tres altas ───────────────────────────────────────────────────────

    /**
     * Las tres, para quien no es superusuario: **403 y sin fila nueva**.
     *
     * Se cuenta la tabla antes y después y no sólo el status, que es la regla de
     * esta casa: un 403 con la fila ya escrita sería peor que un 200 honesto, y los
     * dos se ven igual mirando el código.
     */
    #[Test]
    public function las_tres_altas_son_403_en_un_anio_cerrado_para_el_personal_llano(): void
    {
        $cerrado = $this->unAnioCerradoConPeriodos();
        $token = $this->tokenDe($this->usuarioLlanoDelPersonal()->username);

        $this->moverLaSesionA($token, (int) $cerrado->id);

        $colados = [];

        foreach ($this->lasTresAltas((int) $cerrado->id) as $nombre => $alta) {
            $antes = $this->cuantasFilas($alta['tabla'], (int) $cerrado->id);

            $r = $this->withToken($token)->json($alta['verbo'], $alta['url'], $alta['cuerpo']);

            $despues = $this->cuantasFilas($alta['tabla'], (int) $cerrado->id);

            if ($r->status() !== 403) {
                $colados[] = "{$nombre} -> ".$r->status().' y no 403';
            }

            if ($antes !== $despues) {
                $colados[] = "{$nombre} -> sembró igual en el año cerrado: {$antes} -> {$despues} filas";
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $colados,
            "Alguna de las tres altas sigue sembrando en un año cerrado sin ser superusuario.\n"
            .'Y lo que la hace peor que su `update` es que `deleteDestroy` SÍ lleva candado: lo '
            ."creado ahí no lo puede quitar nadie de los 74.\n  ".implode("\n  ", $colados));
    }

    /**
     * Y el superusuario sigue llegando a las tres.
     *
     * **Éste es el que impide que el cierre se convierta en otra cosa.** Sin él,
     * alguien que endureciera el criterio —de superusuario a nadie— vería el fichero
     * entero en verde, porque el caso de arriba sólo afirma que el personal llano NO
     * puede. Es la 05 §27.4, que sigue viva.
     */
    #[Test]
    public function un_superusuario_sigue_dando_de_alta_en_un_anio_cerrado(): void
    {
        $cerrado = $this->unAnioCerradoConPeriodos();
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El sujeto de este caso no es superusuario, así que no demuestra lo que dice su nombre.');

        $token = $this->tokenDe($usuario->username);

        $this->moverLaSesionA($token, (int) $cerrado->id);

        $noAlcanzan = [];

        foreach ($this->lasTresAltas((int) $cerrado->id) as $nombre => $alta) {
            $antes = $this->cuantasFilas($alta['tabla'], (int) $cerrado->id);

            $r = $this->withToken($token)->json($alta['verbo'], $alta['url'], $alta['cuerpo']);

            $despues = $this->cuantasFilas($alta['tabla'], (int) $cerrado->id);

            if ($r->status() !== $alta['exito']) {
                $noAlcanzan[] = "{$nombre} -> ".$r->status().' y no '.$alta['exito'];
            } elseif ($despues !== $antes + 1) {
                $noAlcanzan[] = "{$nombre} -> 200 y no escribió: {$antes} -> {$despues} filas";
            }

            $this->olvidarControladores();
        }

        $this->assertSame([], $noAlcanzan,
            "El candado se comió también al superusuario, que es quien tiene que poder.\n  "
            .implode("\n  ", $noAlcanzan));
    }

    /**
     * Y en el año corriente las tres siguen abiertas para el personal llano.
     *
     * **Es el caso que más se nota si falla y el menos visible desde el código**:
     * un candado que muerde el año en curso deja al colegio sin poder crear una
     * frase, y el 403 no dice «te has equivocado de año», dice «no puedes».
     */
    #[Test]
    public function en_el_anio_corriente_las_tres_altas_siguen_abiertas(): void
    {
        $corriente = AnioCerrado::anioCorriente();

        $this->assertNotNull($corriente, 'El seed no tiene ningún año con `actual = 1`.');

        $yearId = (int) DB::table('years')->where('year', $corriente)->whereNull('deleted_at')->value('id');
        $token = $this->tokenDelPersonalLlanoDe($yearId);

        foreach ($this->lasTresAltas($yearId) as $nombre => $alta) {
            $antes = $this->cuantasFilas($alta['tabla'], $yearId);

            $r = $this->withToken($token)->json($alta['verbo'], $alta['url'], $alta['cuerpo']);

            $this->assertSame($alta['exito'], $r->status(),
                "`{$nombre}` sobre el año corriente ({$corriente}) contestó ".$r->status()
                .'. El candado del año cerrado se está comiendo el año en curso.');

            $this->assertSame($antes + 1, $this->cuantasFilas($alta['tabla'], $yearId),
                "`{$nombre}` contestó 200 sobre el año corriente y no escribió nada.");

            $this->olvidarControladores();
        }
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    /**
     * Un año cerrado **con periodos**, que son los dos requisitos y no uno.
     *
     * Sin periodos, `putUseractive` contesta 400 —«Año sin ningún periodo»— y el
     * caso fallaría por el montaje y no por el candado.
     */
    private function unAnioCerradoConPeriodos(): object
    {
        $corriente = AnioCerrado::anioCorriente();

        $this->assertNotNull($corriente, 'El seed no tiene ningún año con `actual = 1`.');

        $fila = DB::selectOne('SELECT y.id, y.year FROM years y
            WHERE y.year < ? AND y.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM periodos p WHERE p.year_id = y.id AND p.deleted_at IS NULL)
            ORDER BY y.year DESC LIMIT 1', [$corriente]);

        $this->assertNotNull($fila,
            "El seed no tiene ningún año anterior a {$corriente} con periodos: no hay dónde medir esto.");

        $this->assertTrue(AnioCerrado::estaCerrado($fila->id),
            "El año {$fila->year} salió como NO cerrado siendo anterior al corriente ({$corriente}): "
            .'el montaje no está midiendo lo que cree.');

        return $fila;
    }

    /** Mover la barra de año, que es el camino por el que se llega de verdad. */
    private function moverLaSesionA(string $token, int $yearId): void
    {
        $this->withToken($token)->putJson('/api/years/useractive/'.$yearId)->assertStatus(200);

        $this->olvidarControladores();
    }

    /**
     * Las tres altas, con la tabla en la que habría que ver la fila nueva.
     *
     * `contratos` necesita un profesor **sin contrato en ese año**: con uno ya
     * contratado, `postIndex` contesta **400 de «ya contratado»** y el caso del
     * superusuario fallaría por el montaje. En el del personal llano daría igual
     * —el 403 va delante, a propósito— pero un montaje que sólo vale para dos de
     * los tres casos es el que se rompe cuando alguien añade el cuarto.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lasTresAltas(int $yearId): array
    {
        $profesor = DB::selectOne('SELECT p.id FROM profesores p
            WHERE p.deleted_at IS NULL AND NOT EXISTS (
                SELECT 1 FROM contratos c
                 WHERE c.profesor_id = p.id AND c.year_id = ? AND c.deleted_at IS NULL
            ) ORDER BY p.id LIMIT 1', [$yearId]);

        $this->assertNotNull($profesor,
            "Todos los profesores del seed ya tienen contrato en el año {$yearId}: `POST contratos` "
            .'contestaría 400 por «ya contratado» y no por el año.');

        return [
            // **Los tres no contestan igual cuando pasan, y eso se declara en vez de
            // aplanarse**: `frases/store` devuelve **201** —lo puso la migración, que
            // usa los códigos correctos— y los otros dos **200**, que es el legacy de
            // al lado. Un `assertTrue($r->isSuccessful())` taparía la diferencia y de
            // paso dejaría pasar un 204 sin cuerpo el día que alguien lo cambiara.
            'frases/store' => [
                'verbo' => 'POST', 'url' => '/api/frases/store', 'exito' => 201,
                'cuerpo' => ['frase' => 'ALTA EN UN AÑO CERRADO', 'tipo_frase' => 'Fortaleza'],
                'tabla' => 'frases',
            ],
            'escalas/store' => [
                'verbo' => 'POST', 'url' => '/api/escalas/store', 'exito' => 200,
                'cuerpo' => [], 'tabla' => 'escalas_de_valoracion',
            ],
            'contratos' => [
                'verbo' => 'POST', 'url' => '/api/contratos', 'exito' => 200,
                'cuerpo' => ['profesor_id' => $profesor->id], 'tabla' => 'contratos',
            ],
        ];
    }

    /** Cuántas filas vivas tiene esa tabla en ese año. */
    private function cuantasFilas(string $tabla, int $yearId): int
    {
        return (int) DB::table($tabla)->where('year_id', $yearId)->whereNull('deleted_at')->count();
    }
}
