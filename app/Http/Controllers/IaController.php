<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\SafeUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;

/**
 * Las dos ayudas de IA de «Plan de evaluación»: proponer la plantilla de notas y
 * revisar un texto que el coordinador acaba de escribir.
 *
 * ## Este controlador NO habla con Anthropic. Habla con el proxy.
 *
 * La clave no vive aquí y no puede vivir aquí: `8myvc` está desplegado en
 * dieciséis cuentas de cPanel distintas, así que la clave repartida serían
 * dieciséis copias que rotar el día que una se comprometa, y el gasto sólo se
 * vería junto y después, en la consola de Anthropic. Lo que este colegio lleva en
 * su `.env` es **un secreto suyo** (`MYVC_IA_SECRETO`), y el proxy —`myvc-ia-proxy`,
 * repositorio aparte— es quien tiene la clave, mide el gasto por colegio y corta
 * cuando uno llega a su tope. Decidido por Joseth el 23 sep 2026.
 *
 * ## Lo que el navegador NO puede mandar, y es la mitad de por qué esto existe
 *
 * El contexto que ve el modelo **lo arma este método**, no la petición: el
 * colegio, el modelo de evaluación del año, cuántos periodos tiene y la escala
 * salen de la sesión y de la base. Lo único que viaja desde la pantalla es qué
 * fila se mira y qué escribió el coordinador. Si el contexto viniera del cuerpo,
 * cualquiera con una sesión podría contarle al modelo lo que quisiera y usar la
 * cuenta de Anthropic del colegio como le apeteciera.
 *
 * Y por lo mismo **no hay un endpoint que acepte un `prompt`**: las dos rutas del
 * proxy reciben datos, y el texto que decide qué se pide vive allí.
 *
 * ## El permiso es el de la pantalla, no uno nuevo
 *
 * Quien puede editar la plantilla puede pedir que se la propongan: un permiso
 * aparte obligaría a los dieciséis colegios a repartir otro desde su pantalla de
 * roles para que el botón apareciera, y un botón que no aparece se reporta como
 * avería.
 *
 * **Eso vale para proponer la plantilla, y sólo para eso** (desde el 26 sep 2026). Revisar un
 * texto y preguntar el estado salen también en el PIAR —docentes de materia, titulares,
 * psicología— y en la disciplina —docentes, coordinación disciplinaria—, y ninguno de ellos
 * tiene `can_edit_plantilla_notas`. Para esas dos basta ser personal del colegio (el
 * `auth.personal` de la ruta): **quién la usa lo decide el proxy**, con el rol que le manda
 * `quienPide()` y el tablero de Joseth. Otro filtro aquí sería una segunda lista de roles que
 * mantener en dieciséis colegios, y contradiría a la primera.
 */
class IaController extends Controller
{
    use ResuelveElUsuario;

    private const SIN_PERMISO = 'No tienes permiso para editar la plantilla de notas.';

    private const SIN_PERMISO_USO = 'Sólo rectoría puede ver el uso de la ayuda de IA.';

    /**
     * `GET ia/estado` — si este colegio tiene la ayuda encendida, y cómo va su gasto.
     *
     * **Existe para que el front no pinte un botón que no lleva a ninguna parte.** El orden del
     * despliegue no se puede garantizar —`up2` es común a los dieciséis y el proxy se enciende
     * colegio a colegio—, así que habrá días en que la pantalla esté puesta y la ayuda no. Sin
     * esto, ese colegio ve un botón que contesta «no configurado»: parece una avería, y se
     * reporta como tal.
     *
     * No gasta ni un token: el proxy contesta esta ruta con su libro, sin llamar a Anthropic.
     *
     * @return array<string, mixed>
     */
    public function getEstado(): array
    {
        // Sin `puedeEditarPlantillaNotas`: lo pregunta también el PIAR y la disciplina, y
        // quién la tiene lo contesta el proxy en `disponible`. Ver el docblock de la clase.
        $url = (string) config('services.ia.url');
        $secreto = (string) config('services.ia.secreto');

        if ($url === '' || $secreto === '') {
            return ['disponible' => false];
        }

        /*
         * UN PROXY CAÍDO ES «NO DISPONIBLE», NO UN ERROR DE LA PANTALLA. Esta ruta la llama el
         * front al abrir el plan de evaluación: si contestara 502, la pantalla entera enseñaría un
         * fallo por una función que es un botón. El espera es corto por lo mismo.
         */
        try {
            $respuesta = Http::withToken($secreto)->timeout(5)->acceptJson()
                ->get(rtrim($url, '/') . '/gasto', ['usuario' => $this->quienPide()]);
        } catch (\Throwable $fallo) {
            error_log('[ia] el proxy no contestó al estado: ' . $fallo->getMessage());

            return ['disponible' => false];
        }

        if ($respuesta->failed()) {
            return ['disponible' => false];
        }

        // Desde el 24 sep 2026 el proxy decide si ESTE usuario la tiene —colegio activo y su rol
        // marcado en el tablero— y cuántos usos le quedan. Un proxy de antes no manda
        // `disponible`: contestar bien ya era estar disponible.
        return [
            'disponible' => (bool) ($respuesta->json('disponible') ?? true),
            'gastado' => $respuesta->json('gastado'),
            'tope' => $respuesta->json('tope'),
            'quedan' => $respuesta->json('quedan'),
        ];
    }

    /**
     * `GET ia/uso` — el libro del mes de este colegio: gasto, tope, llamadas por tipo, el modelo y
     * cuánto ha usado cada persona. Es para la pantalla de rectoría, no para el botón.
     *
     * **Sólo superusuario o Rector.** Lo que vuelve lleva el nombre y el gasto de cada usuario,
     * y eso no es para cualquiera del personal; tampoco es el permiso de la plantilla, que tiene
     * coordinación académica y no tiene el rector.
     *
     * Como `getEstado()`, **nunca contesta error por el proxy**: sin configurar es
     * `configurada: false` y caído es `disponible: false`, en 200, para que la pantalla diga
     * cuál de las dos y no enseñe un fallo.
     *
     * @return array<string, mixed>
     */
    public function getUso(): array
    {
        Autoriza::exigir($this->esRectoria(), self::SIN_PERMISO_USO);

        $url = (string) config('services.ia.url');
        $secreto = (string) config('services.ia.secreto');

        if ($url === '' || $secreto === '') {
            return ['configurada' => false];
        }

        try {
            $respuesta = Http::withToken($secreto)->timeout(5)->acceptJson()
                ->get(rtrim($url, '/') . '/uso', ['usuario' => $this->quienPide()]);
        } catch (\Throwable $fallo) {
            error_log('[ia] el proxy no contestó al uso: ' . $fallo->getMessage());

            return ['configurada' => true, 'disponible' => false];
        }

        if ($respuesta->failed() || ! is_array($respuesta->json())) {
            return ['configurada' => true, 'disponible' => false];
        }

        return (array) $respuesta->json() + ['configurada' => true, 'disponible' => true];
    }

    /**
     * Superusuario o rol `Rector`. No hay en `Autoriza` uno con ese conjunto, y los roles se leen
     * igual que en `quienPide()`: de la sesión, que ya trae los del nombramiento del año.
     */
    private function esRectoria(): bool
    {
        return Autoriza::esSuperusuario($this->user)
            || in_array('Rector', $this->quienPide()['roles'], true);
    }

    /**
     * `POST ia/plantilla/proponer` — la plantilla entera, unidades y subunidades
     * con sus porcentajes.
     *
     * `conversacion` es lo que hace que «cámbiale a ese logro el 10 %» funcione:
     * son los turnos anteriores tal cual, y lo que vuelve es **la plantilla
     * completa otra vez**, no un parche, así que la pantalla pinta lo último que
     * llegó y no tiene que fusionar nada.
     *
     * @return array<string, mixed>
     */
    public function postPlantilla(): array
    {
        Autoriza::exigir(Autoriza::puedeEditarPlantillaNotas($this->user), self::SIN_PERMISO);

        return $this->alProxy('plantilla/proponer', [
            'contexto' => $this->contexto([
                'grado' => Request::input('grado'),
                'materia' => Request::input('materia'),
                'existentes' => Request::input('existentes'),
            ]),
            'conversacion' => (array) Request::input('conversacion', []),
        ]);
    }

    /**
     * `POST ia/texto/revisar` — si el texto sirve y, si no, uno que sirva.
     *
     * @return array<string, mixed>
     */
    public function postTexto(): array
    {
        /*
         * Sin permiso de plantilla, por lo mismo que `getEstado()`: la pide un docente desde el
         * PIAR o desde una falta, y el proxy es quien corta por rol y por cupo.
         *
         * `tipo` pasa tal cual: la lista de los válidos (logro, competencia, desempeno, piar,
         * piar-ajuste, falta, descargo, observador, acuerdo, frase-boletin) vive en el proxy,
         * junto al texto que cada uno le pide al modelo, y uno desconocido vuelve en 400 con su
         * frase. Copiarla aquí obligaría a redesplegar los dieciséis por cada tipo nuevo.
         */
        return $this->alProxy('texto/revisar', [
            'contexto' => $this->contexto([
                'grado' => Request::input('grado'),
                'materia' => Request::input('materia'),
            ]),
            'texto' => (string) Request::input('texto', ''),
            'tipo' => (string) Request::input('tipo', 'logro'),
        ]);
    }

    /**
     * `POST ia/competencias/proponer` — las competencias de UNA clase (materia + grado) en UN
     * periodo, para «Mis competencias». Pedido por Joseth el 26 sep 2026.
     *
     * **LAS EXISTENTES LAS PONE LA BASE, NO LA PANTALLA.** Van al modelo para que proponga
     * mejoras de ellas —con su `id`, que es por donde la pantalla sabe qué fila reescribir— y no
     * repita lo que ya hay. Si las mandara el cliente, una pantalla vieja revisaría filas que ya
     * no son las del boletín.
     *
     * Lo pide quien puede escribir en esa clase (`puedeEscribirDesempenos`): proponer para lo que
     * no se va a poder guardar es gastar un uso del cupo en nada. «Todos los grados» no entra:
     * esas filas son del Plan de evaluación. Cada llamada es una clase; «todas las clases del
     * periodo» son N llamadas desde la pantalla, y así cada una cuenta un uso y un fallo no tumba
     * las demás.
     *
     * @return array<string, mixed>
     */
    public function postCompetencias(): array
    {
        $yearId = (int) $this->user->year_id;
        $materiaId = (int) Request::input('materia_id');
        $gradoId = (int) Request::input('grado_id');
        $periodoId = (int) Request::input('periodo_id');

        if ($materiaId <= 0 || $gradoId <= 0 || $periodoId <= 0) {
            abort(400, 'Faltan la materia, el grado o el periodo.');
        }

        Autoriza::exigir(
            Autoriza::puedeEscribirDesempenos($this->user, $yearId, $materiaId, $gradoId),
            'No puedes escribir competencias en esta clase.'
        );

        $materia = DB::selectOne('SELECT materia FROM materias WHERE id = ? AND deleted_at IS NULL', [$materiaId]);
        $grado = DB::selectOne('SELECT nombre FROM grados WHERE id = ? AND deleted_at IS NULL', [$gradoId]);
        $periodo = DB::selectOne(
            'SELECT numero FROM periodos WHERE id = ? AND year_id = ? AND deleted_at IS NULL',
            [$periodoId, $yearId]
        );

        if ($materia === null || $grado === null || $periodo === null) {
            abort(404, 'La materia, el grado o el periodo no existen o están en la papelera.');
        }

        $existentes = array_map(static fn ($f) => [
            'id' => (int) $f->id,
            'definicion' => (string) $f->definicion,
            'tipo' => $f->tipo,
        ], DB::select(
            'SELECT id, definicion, tipo FROM desempenos_por_defecto
              WHERE year_id = ? AND materia_id = ? AND grado_id = ? AND periodo_id = ?
                AND deleted_at IS NULL
              ORDER BY orden, id',
            [$yearId, $materiaId, $gradoId, $periodoId]
        ));

        $contexto = $this->contexto([
            'grado' => $grado->nombre,
            'materia' => $materia->materia,
        ]);
        $contexto['periodo'] = (int) $periodo->numero;
        $contexto['existentes'] = $existentes;
        /*
         * CÓMO LAS QUIERE, elegido en el diálogo (26 sep 2026). Acotado aquí: por defecto una,
         * breve y sin marca, que es lo que Joseth dijo que vale para un boletín.
         */
        $contexto['cuantas'] = max(1, min(3, (int) Request::input('cuantas', 1)));
        $contexto['extension'] = Request::input('extension') === 'detallada' ? 'detallada' : 'breve';
        $contexto['con_marca'] = (bool) Request::input('con_marca', false);

        return $this->alProxy('competencias/proponer', [
            'contexto' => $contexto,
            'conversacion' => (array) Request::input('conversacion', []),
        ]);
    }

    /**
     * `POST ia/boletin/leer` — la puerta 2 de `myvc_front/docs/notas-de-otro-colegio/NOTAS-DE-OTRO-COLEGIO.md` §11 (26 sep 2026).
     *
     * Recibe UNA foto o UN PDF de un boletín y devuelve sus notas en filas con el formato de §11.1,
     * listas para `otros-colegios/lote/ensayo`. Aquí no se guarda nada: el archivo va al proxy en
     * base64 y se olvida. Se archiva después, en el año que se cree, como cualquier documento.
     *
     * Lo pide quien edita alumnos --es quien puede crear lo que salga de la lectura--, y las mismas
     * reglas de archivo que `OtrosColegiosController::postSubirDocumento`: 10 MB, PDF, JPG o PNG.
     *
     * @return array<string, mixed>
     */
    public function postBoletin(): array
    {
        Autoriza::exigir(Autoriza::puedeEditarAlumnos($this->user),
            'Sólo quien edita alumnos puede leer boletines de otros colegios.');

        $file = SafeUpload::archivoRecibido('file');

        if ($file->getSize() > 10 * 1024 * 1024) {
            abort(422, 'El archivo pasa de 10 MB. Escanéalo con menos resolución o pártelo.');
        }

        $tipo = (string) $file->getMimeType();
        if (! in_array($tipo, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            abort(422, 'Sólo se leen PDF, JPG o PNG.');
        }

        return $this->alProxy('boletin/leer', [
            'contexto' => $this->contexto([]),
            'archivo' => [
                'nombre' => mb_substr((string) SafeUpload::nombreParaGuardar($file), 0, 200),
                'tipo' => $tipo,
                'base64' => base64_encode((string) file_get_contents($file->getRealPath())),
            ],
        ]);
    }

    /**
     * Lo que el modelo sabe del colegio, y sale de la sesión y de la base.
     *
     * @param  array<string, mixed>  $deLaPantalla
     * @return array<string, mixed>
     */
    private function contexto(array $deLaPantalla): array
    {
        $yearId = (int) $this->user->year_id;

        /*
         * La escala no es la misma en los dieciséis: el colegio local califica
         * sobre 50 y otros sobre 100, así que un texto pensado para 0-100 le
         * propondría a un colegio notas que no existen. Sale del año, que es
         * donde el colegio la escribe.
         */
        $maxima = DB::table('escalas_de_valoracion')
            ->where('year_id', $yearId)
            ->whereNull('deleted_at')
            ->max('porc_final');

        $periodos = DB::table('periodos')
            ->where('year_id', $yearId)
            ->whereNull('deleted_at')
            ->count();

        return array_filter([
            'modelo' => $this->user->modelo_evaluacion ?: 'ponderado',
            'periodos' => $periodos ?: 4,
            'escala' => [
                'minima' => $this->user->nota_minima_aceptada,
                'maxima' => $maxima,
            ],
            'grado' => $deLaPantalla['grado'] ?? null,
            'materia' => $deLaPantalla['materia'] ?? null,
            'existentes' => $deLaPantalla['existentes'] ?? null,
        ], static fn ($valor) => $valor !== null);
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    /**
     * QUIÉN PIDE, para el proxy: el tablero de Joseth reparte la IA por rol y cuenta los usos por
     * usuario. Van los nombres de `roles.name`, que son los mismos en los dieciséis colegios.
     */
    private function quienPide(): array
    {
        $nombre = trim(($this->user->nombres ?? '') . ' ' . ($this->user->apellidos ?? ''));

        return [
            'id' => (string) ($this->user->user_id ?? ''),
            'nombre' => $nombre !== '' ? $nombre : (string) ($this->user->username ?? ''),
            'roles' => array_values(array_map(
                static fn ($rol) => (string) (is_array($rol) ? ($rol['name'] ?? '') : ($rol->name ?? '')),
                (array) ($this->user->roles ?? []),
            )),
            'superusuario' => (bool) ($this->user->is_superuser ?? false),
            // Tal como se escribe al entrar: el tablero del proxy da ajustes propios por usuario (26 sep).
            'username' => (string) ($this->user->username ?? ''),
        ];
    }

    private function alProxy(string $ruta, array $cuerpo): array
    {
        $url = (string) config('services.ia.url');
        $secreto = (string) config('services.ia.secreto');

        /*
         * SIN CONFIGURAR NO ES UN ERROR DEL COORDINADOR. Un colegio que todavía no
         * tiene el proxy contesta 503 y con una frase que se puede leer: la
         * pantalla esconde el botón con esto, y así la ausencia no parece avería.
         */
        if ($url === '' || $secreto === '') {
            abort(503, 'Este colegio todavía no tiene activada la ayuda de IA.');
        }

        /*
         * El tiempo de espera es alto a propósito: proponer una plantilla entera
         * tarda unos diez segundos, y el de por defecto de Guzzle son treinta que
         * se reparten con el proxy y con Anthropic. Un corte aquí se ve como «la
         * IA no contestó» después de haber pagado la llamada.
         */
        $respuesta = Http::withToken($secreto)
            ->timeout(90)
            ->acceptJson()
            ->post(rtrim($url, '/') . '/' . $ruta, $cuerpo + ['usuario' => $this->quienPide()]);

        if ($respuesta->failed()) {
            /*
             * El 403 (colegio apagado, rol sin marcar en el tablero), el 429 (tope del
             * colegio, o usos del usuario agotados) y el 400 (un `tipo` que el proxy no
             * conoce, un texto vacío) traen una frase escrita para quien la lee, y se pasan
             * tal cual. Las demás se resumen.
             */
            // 413: el boletín pasa de 10 MB o de 20 páginas (`boletin/leer`); también trae su frase.
            $conFrase = in_array($respuesta->status(), [400, 403, 413, 422, 429], true);
            $motivo = $conFrase
                ? ($respuesta->json('error') ?? 'La ayuda de IA no está disponible.')
                : 'La IA no contestó. Vuelve a intentarlo en un momento.';

            abort($conFrase ? $respuesta->status() : 502, $motivo);
        }

        return (array) $respuesta->json();
    }
}
