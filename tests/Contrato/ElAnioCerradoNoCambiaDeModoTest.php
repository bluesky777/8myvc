<?php

namespace Tests\Contrato;

use App\Support\AnioCerrado;
use App\Support\Autoriza;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Un año cerrado no cambia de modelo de evaluación ni de reparto** salvo un
 * superusuario — decisión de Joseth del 15 sep 2026, que sube la lista del año
 * cerrado de quince a **dieciséis**.
 *
 * ## Lo que existe para cazar, y por qué no lo cazaba nada
 *
 * `PUT years/modelo-evaluacion` recibe el `year_id` **por el cuerpo** y su único
 * guard era `puedeEditarPlantillaNotas`. Así que cualquiera con ese permiso podía
 * poner 2023 en `promedio` y **reescribir las definitivas guardadas de un año
 * cerrado** — boletines impresos y firmados que salen con otros números la próxima
 * vez que se impriman.
 *
 * **El doc 28 §5.5 prometía que eso no podía pasar**, y ahí está la raíz:
 *
 * > *«El interruptor es del año, así que un año cerrado conserva su modo para
 * > siempre y ningún boletín ya impreso se mueve. Ésa es la garantía, y es la misma
 * > de §4.»*
 *
 * **No era la misma.** La de §4 es **estructural** —ninguna nota apunta a una fila
 * de plantilla, así que no hay nada que romper, y eso no se puede incumplir—. Ésta
 * era **una costumbre**: el modo se lee vivo en cada cálculo del año, y sólo se
 * cumplía mientras nadie pulsara. *Las dos frases se escriben igual*, y por eso la
 * segunda heredó la solidez de la primera y viajó por tres documentos sin que nadie
 * fuera a comprobarla.
 *
 * Lo levantó `myvc_front`. Esta sesión se hizo la pregunta construyendo el 422 del
 * recuento, la apartó por estar fuera de lo que tenía entre manos y **no la escribió
 * en ninguna parte** — con lo que dejó de existir para todos.
 *
 * ## Superusuario y no «nadie», que era la otra opción
 *
 * La letra del plan decía *«para siempre»*. Se eligió lo otro con su motivo: un
 * colegio que cierre un año con el modo equivocado se quedaría sin más salida que un
 * `UPDATE` a mano. Con el candado sigue pudiendo, y el 422 de
 * {@see AceptoRecalcularElRepartoTest} le pone delante cuántas definitivas recalcula
 * — que es lo que convierte el paso en una decisión informada en vez de una pared.
 */
class ElAnioCerradoNoCambiaDeModoTest extends CasoDeContrato
{
    /**
     * **Con el permiso pero sin superusuario: 403, y la fila intacta.**
     *
     * El sujeto tiene `can_edit_plantilla_notas` **a propósito**: sin él el 403
     * vendría del guard de siempre y este caso no diría nada sobre el año. Lo que se
     * mide es que el permiso de la plantilla **ya no basta** cuando el año está
     * cerrado.
     */
    #[Test]
    public function con_el_permiso_pero_sin_superusuario_un_anio_cerrado_es_403(): void
    {
        $cerrado = $this->unAnioCerrado();
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->assertSame(0, (int) $usuario->is_superuser,
            'El sujeto NO puede ser superusuario: con la columna puesta el 403 no diría nada.');

        $this->darPermisoDeLaPlantilla((int) $usuario->id);

        $antes = DB::table('years')->where('id', $cerrado->id)->first();

        $r = $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $cerrado->id,
                'reparto_subunidades' => 'promedio',
                'acepto_recalcular' => true,
            ]);

        $r->assertStatus(403);

        $despues = DB::table('years')->where('id', $cerrado->id)->first();

        $this->assertSame($antes->reparto_subunidades, $despues->reparto_subunidades,
            'Contestó 403 y cambió el reparto igual. En un año cerrado eso reescribe las '
            .'definitivas guardadas: boletines ya impresos con otros números.');

        $this->assertSame($antes->modelo_evaluacion, $despues->modelo_evaluacion);
    }

    /**
     * **Y el modelo de evaluación tampoco**, aunque ése no recalcule nada.
     *
     * Es el matiz que `myvc_front` corrigió de su propio aviso, y merece caso
     * propio porque **cambia la urgencia y no la regla**: con `modelo_evaluacion`
     * solo, el método escribe una columna de configuración y no toca ninguna nota
     * (D3, y hay test). Sigue siendo el año de otro y sigue sin poder tocarlo quien
     * no es superusuario — pero quien lea este fichero dentro de un año tiene que
     * poder distinguir cuál de las dos quemaba notas.
     */
    #[Test]
    public function el_modelo_de_evaluacion_de_un_anio_cerrado_tambien_es_403(): void
    {
        $cerrado = $this->unAnioCerrado();
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->darPermisoDeLaPlantilla((int) $usuario->id);

        $antes = DB::table('years')->where('id', $cerrado->id)->value('modelo_evaluacion');

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $cerrado->id,
                'modelo_evaluacion' => 'competencias',
            ])->assertStatus(403);

        $this->assertSame($antes,
            DB::table('years')->where('id', $cerrado->id)->value('modelo_evaluacion'));
    }

    /**
     * **El superusuario sigue pudiendo**, que es la mitad que la decisión eligió.
     *
     * Sin este caso, endurecer el criterio a «nadie» dejaría el fichero en verde — y
     * «nadie» era literalmente la otra opción sobre la mesa, así que aquí no es
     * hipotético.
     */
    #[Test]
    public function un_superusuario_sigue_cambiando_el_reparto_de_un_anio_cerrado(): void
    {
        $cerrado = $this->unAnioCerrado();
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser);

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $cerrado->id,
                'reparto_subunidades' => 'promedio',
                'acepto_recalcular' => true,
            ])->assertStatus(200);

        $this->assertSame('promedio',
            DB::table('years')->where('id', $cerrado->id)->value('reparto_subunidades'),
            'El candado se comió también al superusuario, que es quien tiene que poder: un '
            .'colegio que cerrara el año con el modo equivocado se quedaría sin salida.');
    }

    /**
     * **Y el año en curso sigue abierto a quien tiene el permiso**, sin superusuario.
     *
     * El caso que más se nota si falla: si el candado del año cerrado mordiera el año
     * corriente, la pantalla de configuración del colegio dejaría de funcionar para
     * quien la usa todos los días.
     */
    #[Test]
    public function el_anio_corriente_lo_sigue_cambiando_quien_tiene_el_permiso(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->darPermisoDeLaPlantilla((int) $usuario->id);

        $yearId = (int) DB::table('years')->where('actual', 1)->whereNull('deleted_at')->value('id');

        $this->assertFalse(AnioCerrado::estaCerrado($yearId),
            'El año actual salió como cerrado: el montaje no mide lo que cree.');

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson('/api/years/modelo-evaluacion', [
                'year_id' => $yearId,
                'reparto_subunidades' => RepartoDeLaNota::PROMEDIO,
                'acepto_recalcular' => true,
            ])->assertStatus(200);
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    private function unAnioCerrado(): object
    {
        $numero = AnioCerrado::anioCorriente();

        $this->assertNotNull($numero, 'El seed no tiene ningún año con `actual = 1`.');

        $fila = DB::selectOne('SELECT id, year FROM years WHERE year < ? AND deleted_at IS NULL
            ORDER BY year DESC LIMIT 1', [$numero]);

        $this->assertNotNull($fila, "El seed no tiene ningún año anterior a {$numero}.");
        $this->assertTrue(AnioCerrado::estaCerrado($fila->id),
            "El año {$fila->year} salió como NO cerrado siendo anterior al corriente.");

        return $fila;
    }

    /**
     * El permiso por rol, que es como llega de verdad al contexto. Calcado de
     * `ModeloDeEvaluacionDelAnioTest`: `test-seed.sql` hace `TRUNCATE` de
     * `permissions`, así que lo que siembre la migración no sobrevive a construir la
     * base y un caso que se apoyara en ello comprobaría el seed y no el código.
     */
    private function darPermisoDeLaPlantilla(int $userId): void
    {
        $permiso = DB::table('permissions')->where('name', Autoriza::PERMISO_PLANTILLA_NOTAS)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => Autoriza::PERMISO_PLANTILLA_NOTAS,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $rol = DB::table('roles')->where('name', 'CoordinacionDePrueba')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'CoordinacionDePrueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        if (! DB::table('permission_role')->where('permission_id', $permiso)->where('role_id', $rol)->exists()) {
            DB::table('permission_role')->insert(['permission_id' => $permiso, 'role_id' => $rol]);
        }

        if (! DB::table('role_user')->where('user_id', $userId)->where('role_id', $rol)->exists()) {
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rol]);
        }
    }
}
