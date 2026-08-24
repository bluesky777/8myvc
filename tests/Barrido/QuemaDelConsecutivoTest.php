<?php

namespace Tests\Barrido;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * Qué hace subir el consecutivo de certificados, exactamente.
 *
 *     docker exec -w /app/.worktrees/12 -e DB_TEST_DATABASE=simonbolivar_testing_12 \
 *         8myvc-app-1 php artisan test --group=barrido --filter=QuemaDelConsecutivoTest
 *
 * La [§225](../../docs/migracion/05-codigo-muerto-y-roto.md) dejó la carrera fijada
 * en rojo, y la pregunta que faltaba para poder decidir era **cuántos números se
 * han quemado ya**. Esa resta —contador menos certificados emitidos— **no se puede
 * hacer, y el motivo es el hallazgo**: ver la §231.
 *
 * Lo que sí se puede medir es **qué exactamente dispara el incremento**, porque de
 * eso depende dónde está la cura:
 *
 *     if (Request::has('aumentar_contador')) {
 *         if (Request::input('aumentar_contador') == true) {      // <- `==`, no `===`
 *
 * **Si el servidor sólo sube cuando se lo piden, la cura está entera en el front y
 * no toca los dieciséis despliegues.** Si sube con valores que el front cree que
 * significan «no», no.
 *
 * Imprime, y afirma sólo lo que no depende del seed.
 */
#[Group('barrido')]
class QuemaDelConsecutivoTest extends CasoDeContrato
{
    /**
     * Los valores que puede mandar un cliente, y qué hace cada uno.
     *
     * `'false'` y `'0'` están aquí a propósito: un front que quiera decir «no
     * subas» **manda una cadena**, porque JSON viaja por HTTP y el cliente viejo
     * de este proyecto manda cadenas. Y `'false' == true` **es cierto en PHP** —
     * cualquier cadena no vacía que no sea `'0'` lo es.
     *
     * Es el mismo error que está documentado **doce líneas más arriba en el mismo
     * fichero**, en `year_selected`: *«aquí había un `|| … == 'true'` que en PHP 7
     * atrapaba los valores falsy»*. La comparación laxa de al lado ya se miró; ésta
     * no.
     */
    public static function valores(): array
    {
        return [
            'sin la clave' => [null, false],
            'true (bool)' => [true, null],
            'false (bool)' => [false, null],
            '"true" (cadena)' => ['true', null],
            '"false" (cadena)' => ['false', null],
            '"0" (cadena)' => ['0', null],
            '0 (entero)' => [0, null],
            '"si" (cualquier cadena)' => ['si', null],
        ];
    }

    public function test_que_hace_subir_el_consecutivo(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $lee = fn () => (int) DB::selectOne(
            'SELECT contador_certificados FROM years WHERE deleted_at is null and actual=1'
        )->contador_certificados;

        $informe = '';
        $subenSinPedirlo = [];

        foreach (self::valores() as $etiqueta => [$valor, $_]) {
            $cuerpo = $valor === null ? [] : ['aumentar_contador' => $valor];

            $antes = $lee();
            $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id, $cuerpo,
                ['Authorization' => 'Bearer '.$token])->assertStatus(200);
            $subio = $lee() - $antes;

            $informe .= sprintf("    %-26s  %s\n", $etiqueta,
                $subio > 0 ? 'SUBE  +'.$subio : 'no sube');

            // Lo que un cliente manda creyendo que dice «no»: los tres falsy
            // textuales y el booleano falso.
            if ($subio > 0 && in_array($etiqueta, ['false (bool)', '"false" (cadena)', '"0" (cadena)', '0 (entero)'], true)) {
                $subenSinPedirlo[] = $etiqueta;
            }
        }

        $emitidos = $this->tablaDeCertificadosEmitidos();

        fwrite(STDERR, sprintf(
            "\n%s\n  base `%s` · contador actual %d\n  %s\n%s  %s\n".
            "  certificados emitidos guardados en: %s\n".
            "  auditoría del contador: %s\n  %s\n\n",
            'Qué hace subir `years.contador_certificados`',
            DB::connection()->getDatabaseName(), $lee(), str_repeat('-', 66),
            $informe, str_repeat('-', 66),
            $emitidos ?? 'NINGUNA TABLA — no se puede saber cuántos se emitieron',
            'ninguna — `putCambiarContadorCertificados` no escribe en `bitacoras`',
            str_repeat('-', 66)
        ));

        // **El barrido imprime; el candado de esto es rojo y vive aparte**
        // (`Tests\Contrato\ConsecutivoDeCertificadosTest`, grupo `rojo`). Aquí sólo
        // se afirma que la medición ocurrió: sin esto, un informe de ocho «no sube»
        // por no haber llegado a llamar al endpoint se leería como «está cerrado».
        $this->assertNotSame('', $informe, 'No se midió ningún valor.');

        $this->assertGreaterThan(0, substr_count($informe, 'SUBE'),
            'Ningún valor subió el contador, ni siquiera `true`: el endpoint no se está '
            .'ejerciendo y el informe de arriba no mide nada.');
    }

    /**
     * ¿Hay alguna tabla donde quede constancia de un certificado emitido?
     *
     * Se pregunta al esquema vivo y no al volcado: si mañana alguien la crea, esto
     * la encuentra sola. **`config_certificados` no cuenta y por eso se excluye**:
     * es maquetación —imágenes de encabezado y pie, márgenes—, no un registro de
     * emisiones.
     */
    private function tablaDeCertificadosEmitidos(): ?string
    {
        $filas = DB::select(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND (TABLE_NAME LIKE "%certificad%" OR TABLE_NAME LIKE "%folio%")
                AND TABLE_NAME <> "config_certificados"'
        );

        return $filas === [] ? null : implode(', ', array_map(fn ($f) => $f->TABLE_NAME, $filas));
    }
}
