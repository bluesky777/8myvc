<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Un consecutivo por HOJA, no por petición.**
 *
 * El defecto, medido y confirmado por `myvc_front` el 20 sep 2026: el consecutivo se
 * quemaba **una vez por petición**, así que un grupo de 37 salía con 37 papeles que
 * decían todos «No. 144». Por eso la constancia de estudio nueva **no imprimía número**:
 * antes sin número que con uno repetido o con uno que nadie reservó.
 *
 * Joseth lo decidió ese día, con el precio delante: quemar por hoja cambia cuántos
 * números gasta un colegio por informe, y eso se ve en la numeración oficial desde el
 * primer día.
 *
 * ## LO QUE ESTE FICHERO PROTEGE DE VERDAD: la rama de los que no piden nada
 *
 * El reparto va detrás de `consecutivo_por_hoja`, y **la llave no es ceremonia**. El
 * número viaja hoy en `year.contador_certificados` —uno para toda la respuesta— y los
 * dieciséis colegios llevan copias de `myvc_front` en versiones distintas. Un reparto
 * sin llave haría que un colegio con el front viejo **gastara 37 números para imprimir
 * 37 veces el mismo**: repetido igual que antes y con 36 folios oficiales tirados.
 *
 * En una cuenta de papel oficial la dirección irreversible es quemar. Por eso el caso
 * que más vale de este fichero no es el del reparto: es
 * `sin_pedirlo_se_sigue_quemando_exactamente_uno`.
 */
class ConsecutivoPorHojaTest extends CasoDeContrato
{
    private function encenderConsecutivo(): void
    {
        DB::update('UPDATE years SET usa_consecutivo_certificados=1 WHERE actual=1 and deleted_at is null');
    }

    private function contador(): int
    {
        return (int) DB::selectOne(
            'SELECT contador_certificados FROM years WHERE deleted_at is null and actual=1'
        )->contador_certificados;
    }

    /**
     * **La rama de siempre no se mueve: un número por petición y ninguna hoja numerada.**
     *
     * Es el caso que protege a los dieciséis colegios que todavía no han desplegado el
     * front nuevo. Si esto se pone rojo, alguien quitó la llave y ese día los colegios
     * empiezan a gastar un folio por estudiante sin poder imprimirlo.
     */
    #[Test]
    public function sin_pedirlo_se_sigue_quemando_exactamente_uno(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $antes = $this->contador();

        $r = $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => true],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertSame($antes + 1, $this->contador(),
            'La rama de siempre dejó de quemar exactamente uno.');

        $hojas = $r->json('2');

        $this->assertNotSame([], $hojas, 'Sin hojas este caso no prueba nada.');

        foreach ($hojas as $hoja) {
            $this->assertArrayHasKey('consecutivo_certificado', $hoja,
                'La clave tiene que viajar SIEMPRE: una que a veces no viene obliga a quien la lee '
                .'a distinguir «vacío» de «no vino», y en una plantilla eso se parece demasiado.');

            $this->assertNull($hoja['consecutivo_certificado'],
                'Se repartieron números sin que nadie los pidiera: el front viejo imprimiría uno '
                .'que no reservó.');
        }
    }

    /**
     * **Pidiéndolo: un número por hoja, seguidos y sin repetir.**
     *
     * Y se reservan **en una sola escritura** dentro de la transacción con `FOR UPDATE`,
     * no con N incrementos: N sentencias sueltas son N carreras, y dos secretarias
     * imprimiendo a la vez se llevarían bloques entrelazados.
     */
    #[Test]
    public function pidiendolo_cada_hoja_se_lleva_su_propio_numero(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $antes = $this->contador();

        $r = $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => true, 'consecutivo_por_hoja' => true],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $hojas = $r->json('2');
        $cuantas = count($hojas);

        $this->assertGreaterThan(1, $cuantas,
            'Con una sola hoja este caso no distingue «uno por hoja» de «uno por petición».');

        $this->assertSame($antes + $cuantas, $this->contador(),
            'Se quemaron '.($this->contador() - $antes).' números para '.$cuantas.' hojas.');

        $numeros = array_column($hojas, 'consecutivo_certificado');

        $this->assertSame(range($antes + 1, $antes + $cuantas), $numeros,
            'Los números de las hojas no son el bloque reservado, en orden y sin huecos.');

        $this->assertSame($cuantas, count(array_unique($numeros)),
            'Dos hojas se llevaron el mismo número, que es el defecto que esto viene a arreglar.');
    }

    /**
     * **Se quema por las hojas que se IMPRIMEN, no por los alumnos del grupo.**
     *
     * Cuando el cliente pide alumnos sueltos —que es como se saca una constancia
     * individual— el informe imprime ésos. Quemar por el grupo entero gastaría un folio
     * oficial por cada compañero que no se imprime.
     */
    #[Test]
    public function se_quema_por_las_hojas_pedidas_y_no_por_el_grupo(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id=? and m.deleted_at is null and m.estado in ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$grupo->id]);

        $antes = $this->contador();

        $r = $this->putJson('/api/bolfinales/detailed-notas-year/'.$grupo->id, [
            'aumentar_contador' => true,
            'consecutivo_por_hoja' => true,
            'requested_alumnos' => [['alumno_id' => $alumno->alumno_id]],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $hojas = $r->json('2');

        $this->assertCount(1, $hojas, 'Se pidió un alumno y salieron '.count($hojas).' hojas.');

        $this->assertSame($antes + 1, $this->contador(),
            'Se quemó un número por cada alumno del grupo en vez de por la única hoja pedida.');

        $this->assertSame($antes + 1, $hojas[0]['consecutivo_certificado']);
    }

    /**
     * **El colegio que no numera sus constancias no quema nada, tampoco por hoja.**
     *
     * Es el interruptor que Joseth abrió el 26 ago 2026, y el reparto tiene que
     * respetarlo: sin esto, encender `consecutivo_por_hoja` colaría la numeración por la
     * puerta de atrás en un colegio que decidió no numerar.
     */
    #[Test]
    public function el_colegio_que_no_numera_no_gasta_ni_reparte(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        DB::update('UPDATE years SET usa_consecutivo_certificados=0 WHERE actual=1 and deleted_at is null');

        [$grupo, $token] = $this->grupoYPersonal();

        $antes = $this->contador();

        $r = $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => true, 'consecutivo_por_hoja' => true],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertSame($antes, $this->contador(), 'Se gastó un número en un colegio que no numera.');

        foreach ($r->json('2') as $hoja) {
            $this->assertNull($hoja['consecutivo_certificado'],
                'Se repartieron números en un colegio que decidió no numerar sus constancias.');
        }
    }

    /**
     * **Cero hojas no queman nada.**
     *
     * Sin esto, pedir el informe de un alumno que ya no está en el grupo gastaría un
     * folio oficial por un papel que no existe. La rama de siempre sigue quemando uno
     * pase lo que pase, porque cambiar eso sería estrenar conducta en el camino que ya
     * usan los dieciséis.
     */
    #[Test]
    public function sin_hojas_no_se_quema_nada(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $antes = $this->contador();

        $r = $this->putJson('/api/bolfinales/detailed-notas-year/'.$grupo->id, [
            'aumentar_contador' => true,
            'consecutivo_por_hoja' => true,
            'requested_alumnos' => [['alumno_id' => 99999999]],
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertSame([], $r->json('2'), 'El control no controla: salió alguna hoja.');

        $this->assertSame($antes, $this->contador(),
            'Se quemó un consecutivo para un informe de cero hojas.');
    }

    /**
     * **El rastro dice de qué número a cuál**, que es lo que convierte un bloque
     * reservado en algo que se puede justificar ante quien reclama.
     */
    #[Test]
    public function el_rastro_recoge_el_bloque_entero(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->encenderConsecutivo();

        [$grupo, $token] = $this->grupoYPersonal();

        $antes = $this->contador();

        $r = $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id,
            ['aumentar_contador' => true, 'consecutivo_por_hoja' => true],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $cuantas = count($r->json('2'));

        $linea = DB::selectOne('SELECT valor_anterior, valor_nuevo FROM auditoria
            WHERE entidad="year_config" ORDER BY id DESC LIMIT 1');

        $this->assertNotNull($linea, 'La quema no dejó rastro.');

        $this->assertSame($antes + $cuantas, (int) $linea->valor_nuevo,
            'El rastro dice que se quemó hasta un número distinto del que quedó en la tabla.');
    }
}
