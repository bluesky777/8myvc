<?php

namespace Tests\Contrato;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §123 — El formato `'Y-m-d G:H:i'`: la población entera, y **el que lee no
 * existe**.
 *
 * `G` y `H` son las dos la hora del día —una sin cero delante, la otra con él—,
 * así que el formato es `hora:hora:minutos` y **los segundos no llegan nunca**:
 * las 21:07:33 se guardan como **21:21:07**.
 *
 * El lote K arregló el de `ChangeAskedController` y **anotó tres sitios**, uno de
 * ellos «leyendo» con el mismo formato. Barrida la población de verdad, son dos:
 *
 * | Sitio | Qué hace |
 * |---|---|
 * | `ChangeAskedController:947` | escribe — arreglado en el lote K (§121) |
 * | `Tardanzas/TSubirController:103` | **escribe** — lo arregla esto |
 * | `AusenciasController:177` | **está dentro de un `/* *&#47;`**. No lee nadie |
 *
 * > **La nota que decía «uno lo LEE» salió de un `grep`, y el `grep` no sabe que
 * > esa línea está comentada.** Es exactamente la ceguera que el mismo lote H
 * > acababa de quitarle a `identificadores-del-cuerpo.py` —«un detector que busca
 * > una palabra tiene que mirar solo el código»— aplicada esta vez **a la nota de
 * > uno mismo**. Se corrige aquí porque la conclusión que colgaba de ella era la
 * > contraria: «arreglar al que escribe rompe al que lee».
 *
 * Y es lo que hace seguro el arreglo: **no hay quien lo lea así**, así que
 * `created_at` y `updated_at` pasan a llevar la hora de verdad sin romper nada.
 *
 * El de aquí es además **el de más volumen de los dos**: escribe las dos fechas
 * de **cada ausencia que sube el lector de tardanzas**, no la de un pedido de
 * cambio que se cierra de vez en cuando.
 */
class LaHoraDelLectorDeTardanzasTest extends CasoDeContrato
{
    /**
     * El lector manda usuario y contraseña **en cada petición**, dentro de
     * `loginData`: estas tres rutas no llevan `auth.token` y se autentican dentro
     * del método. Tiene que ser `Profesor` o superusuario.
     */
    private function credencialesDeUnProfesor(): array
    {
        $profesor = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            INNER JOIN periodos pe ON pe.id = u.periodo_id
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($profesor, 'El seed necesita un profesor con cuenta.');

        return ['username' => $profesor->username, 'password' => self::CLAVE];
    }

    private function unaAusenciaParaSubir(): array
    {
        $fila = DB::selectOne('SELECT a.id AS alumno_id, asi.id AS asignatura_id, p.id AS periodo_id
            FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN asignaturas asi ON asi.grupo_id = g.id AND asi.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita un alumno matriculado con asignatura.');

        return [
            'alumno_id' => $fila->alumno_id,
            'asignatura_id' => $fila->asignatura_id,
            'periodo_id' => $fila->periodo_id,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-23 07:15:00',
            'uploaded' => 'to_create',
        ];
    }

    /**
     * §123 — La ausencia que sube el lector queda con la hora de verdad.
     *
     * Se compara contra el reloj del contenedor con un minuto de margen: lo que
     * se afirma no es un valor exacto sino que **los minutos son minutos**. Con el
     * formato viejo, los minutos eran la hora repetida, así que a cualquier hora
     * que no fuera la 00:00 esto se separaba por más de un minuto.
     */
    public function test_la_ausencia_subida_queda_con_la_hora_de_verdad(): void
    {
        $antes = DB::table('ausencias')->count();
        $ahora = Carbon::now('America/Bogota');

        $r = $this->postJson('/api/tardanzas/subir', [
            'loginData' => $this->credencialesDeUnProfesor(),
            'ausencias_to_create' => [$this->unaAusenciaParaSubir()],
        ]);

        $r->assertStatus(200);
        $this->assertSame($antes + 1, DB::table('ausencias')->count(), 'La ausencia se sube.');

        $fila = DB::table('ausencias')->orderByDesc('id')->first();

        // Se lee **en la zona en la que se escribió**: `config/app.php` dice UTC y
        // este código llama a `Carbon::now('America/Bogota')`. Las dos zonas
        // conviven a propósito hasta que se unifiquen (09 §2), y parsear a secas
        // separaría por cinco horas — por la zona, no por el formato.
        $creada = Carbon::parse($fila->created_at, 'America/Bogota');

        $this->assertSame($ahora->format('Y-m-d H'), $creada->format('Y-m-d H'));
        $this->assertLessThanOrEqual(60, abs($creada->diffInSeconds($ahora)),
            'Con `G:H:i` los minutos eran la hora repetida.');

        $this->assertSame($fila->created_at, $fila->updated_at,
            'Las dos salen del mismo `$dt`, y siguen saliendo.');
    }

    /** Y el resto de lo que escribe esa ruta no cambia, que es lo que hay que fijar. */
    public function test_lo_demas_de_la_ausencia_subida_sigue_igual(): void
    {
        $ausencia = $this->unaAusenciaParaSubir();

        $this->postJson('/api/tardanzas/subir', [
            'loginData' => $this->credencialesDeUnProfesor(),
            'ausencias_to_create' => [$ausencia],
        ])->assertStatus(200);

        $fila = DB::table('ausencias')->orderByDesc('id')->first();

        $this->assertSame((int) $ausencia['alumno_id'], (int) $fila->alumno_id);
        $this->assertSame((int) $ausencia['asignatura_id'], (int) $fila->asignatura_id);
        $this->assertSame((int) $ausencia['periodo_id'], (int) $fila->periodo_id);
        $this->assertSame('ausencia', $fila->tipo);
        $this->assertSame('created', $fila->uploaded,
            'El lector marca lo que sube, que es como sabe qué le queda pendiente.');
        $this->assertNotNull($fila->created_by, 'Y queda firmado por el profesor que subió.');
    }

    /**
     * Y sin credenciales buenas no sube nada, que es lo que sostiene que estas
     * tres rutas no lleven `auth.token`.
     *
     * `identificadores-del-cuerpo.py` las enseña con el guard en `—`, que se lee
     * como «sin guard». **No lo están**: se autentican dentro del método. Queda
     * fijado aquí porque es lo único que lo demuestra (§108, quinta ceguera).
     */
    public function test_sin_credenciales_no_sube_nada(): void
    {
        $antes = DB::table('ausencias')->count();
        $ausencia = $this->unaAusenciaParaSubir();

        // Y de paso queda fijada una asimetría que solo se ve mandando las dos
        // formas: el método acepta las credenciales **dentro de `loginData`** o
        // sueltas en la raíz, y el `else if` de después mira solo las de la raíz.
        // Así que **la misma contraseña equivocada da 401 o 400 según por dónde
        // haya entrado**. No se toca: los dos caminos rechazan, que es lo que
        // importa, y unificarlo cambia el código que lee el lector de tardanzas.
        $this->postJson('/api/tardanzas/subir', [
            'loginData' => ['username' => 'no_existe_este_usuario', 'password' => 'x'],
            'ausencias_to_create' => [$ausencia],
        ])->assertStatus(401);

        $this->postJson('/api/tardanzas/subir', [
            'username' => 'no_existe_este_usuario', 'password' => 'x',
            'ausencias_to_create' => [$ausencia],
        ])->assertStatus(400);

        $this->postJson('/api/tardanzas/subir', [
            'ausencias_to_create' => [$ausencia],
        ])->assertStatus(401);

        $this->assertSame($antes, DB::table('ausencias')->count());
    }

    /** Y un alumno con credenciales válidas tampoco: exige Profesor o superusuario. */
    public function test_un_alumno_con_su_clave_tampoco_sube(): void
    {
        $antes = DB::table('ausencias')->count();

        $this->postJson('/api/tardanzas/subir', [
            'loginData' => [
                'username' => $this->usuarioDeTipo('Alumno')->username,
                'password' => self::CLAVE,
            ],
            'ausencias_to_create' => [$this->unaAusenciaParaSubir()],
        ])->assertStatus(400);

        $this->assertSame($antes, DB::table('ausencias')->count());
    }
}
