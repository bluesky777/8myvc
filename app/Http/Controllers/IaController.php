<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
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
 */
class IaController extends Controller
{
    use ResuelveElUsuario;

    private const SIN_PERMISO = 'No tienes permiso para editar la plantilla de notas.';

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
        Autoriza::exigir(Autoriza::puedeEditarPlantillaNotas($this->user), self::SIN_PERMISO);

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
        Autoriza::exigir(Autoriza::puedeEditarPlantillaNotas($this->user), self::SIN_PERMISO);

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
             * El 403 (colegio apagado, rol sin marcar en el tablero) y el 429 (tope del
             * colegio, o usos del usuario agotados) traen una frase escrita para quien
             * la lee, y se pasan tal cual. Las demás se resumen.
             */
            $conFrase = in_array($respuesta->status(), [403, 429], true);
            $motivo = $conFrase
                ? ($respuesta->json('error') ?? 'La ayuda de IA no está disponible.')
                : 'La IA no contestó. Vuelve a intentarlo en un momento.';

            abort($conFrase ? $respuesta->status() : 502, $motivo);
        }

        return (array) $respuesta->json();
    }
}
