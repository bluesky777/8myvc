<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * LAS PREGUNTAS Y LAS RESPUESTAS DE LAS ACTIVIDADES NUEVAS  *(26 sep 2026, contrato §1.2)*.
 *
 * Se reutilizan las cuatro tablas del módulo viejo —`ws_preguntas`, `ws_opciones`, `ws_respuestas`
 * y `ws_actividades_resueltas` (la «hoja» de una respuesta)— y se les añade lo que lo nuevo necesita:
 * secciones, obligatoria, multimedia en el enunciado, la explicación de la correcta, los valores de
 * las respuestas que no son una opción, y quién/cuándo en la hoja.
 *
 * **La única columna existente que cambia es `ws_actividades_resueltas.persona_id`, que pasa a
 * NULL** (aprobado por Joseth; 0 respuestas en producción). `MODIFY`, sin `->change()`, como en la
 * migración anterior. Se pensó para las hojas anónimas; desde el 26 sep el anonimato es sólo de
 * PRESENTACIÓN (Joseth: la base guarda siempre quién respondió qué, y lo que no ven el creador ni
 * los directivos lo decide la API), así que hoy toda hoja lleva su `persona_id`. Nula se queda:
 * una hoja de alguien sin ficha —un usuario del personal sin `profesores`— no tiene persona.
 *
 * La FK de `ws_respuestas.archivo_id` hacia `ws_archivos` NO va aquí: esa tabla se crea en
 * `2026_09_27_150000_las_entregas_de_las_tareas`, y es allí donde se ata.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->anadir('ws_preguntas', 'texto_abajo', [
            'seccion' => fn ($t) => $t->unsignedSmallInteger('seccion')->default(1),
            'obligatoria' => fn ($t) => $t->boolean('obligatoria')->default(false),
            'imagen_id' => fn ($t) => $t->unsignedInteger('imagen_id')->nullable(),
            'youtube_id' => fn ($t) => $t->char('youtube_id', 11)->nullable(),
            'youtube_inicio' => fn ($t) => $t->unsignedInteger('youtube_inicio')->nullable(),
            'youtube_fin' => fn ($t) => $t->unsignedInteger('youtube_fin')->nullable(),
            'enlace_url' => fn ($t) => $t->string('enlace_url', 500)->nullable(),
            'escala_estilo' => fn ($t) => $t->enum('escala_estilo', ['numeros', 'caras', 'estrellas'])->nullable(),
            'compartir' => fn ($t) => $t->boolean('compartir')->default(true),
            'explicacion' => fn ($t) => $t->text('explicacion')->nullable(),
            // Sólo `multiple` en cuestionario: 0 = todo o nada; 1 = suma por correcta marcada y resta
            // por incorrecta marcada, mínimo 0. Lo elige el docente por pregunta (Joseth, 26 sep).
            'puntaje_parcial' => fn ($t) => $t->boolean('puntaje_parcial')->default(false),
        ]);

        $this->anadir('ws_opciones', 'is_correct', [
            'error_tipico' => fn ($t) => $t->string('error_tipico', 300)->nullable(),
        ]);

        $this->anadir('ws_respuestas', 'opcion_cuadricula_id', [
            'texto' => fn ($t) => $t->text('texto')->nullable(),
            'valor' => fn ($t) => $t->smallInteger('valor')->nullable(),
            'fecha' => fn ($t) => $t->date('fecha')->nullable(),
            'archivo_id' => fn ($t) => $t->unsignedInteger('archivo_id')->nullable(),
        ]);

        $persona = DB::selectOne(
            "SELECT is_nullable AS nulo FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'ws_actividades_resueltas' AND column_name = 'persona_id'"
        );

        if ($persona && $persona->nulo === 'NO') {
            DB::statement('ALTER TABLE ws_actividades_resueltas MODIFY persona_id INT UNSIGNED NULL');
        }

        $this->anadir('ws_actividades_resueltas', 'timeout', [
            'user_id' => fn ($t) => $t->unsignedInteger('user_id')->nullable(),
            'alumno_id' => fn ($t) => $t->unsignedInteger('alumno_id')->nullable(),
            'publico' => fn ($t) => $t->enum('publico', ['alumno', 'acudiente', 'personal'])->nullable(),
            'grupo_id' => fn ($t) => $t->unsignedInteger('grupo_id')->nullable(),
            'intento' => fn ($t) => $t->unsignedTinyInteger('intento')->default(1),
            'iniciada_at' => fn ($t) => $t->dateTime('iniciada_at')->nullable(),
            'enviada_at' => fn ($t) => $t->dateTime('enviada_at')->nullable(),
            'puntaje' => fn ($t) => $t->decimal('puntaje', 6, 2)->nullable(),
            'puntaje_max' => fn ($t) => $t->decimal('puntaje_max', 6, 2)->nullable(),
            'nota_calculada' => fn ($t) => $t->smallInteger('nota_calculada')->nullable(),
        ]);

        $indice = 'ws_actividades_resueltas_act_user_alumno_index';

        if (Schema::hasColumn('ws_actividades_resueltas', 'alumno_id')
            && ! Schema::hasIndex('ws_actividades_resueltas', $indice)) {
            Schema::table('ws_actividades_resueltas', function (Blueprint $t) use ($indice) {
                $t->index(['actividad_id', 'user_id', 'alumno_id'], $indice);
            });
        }
    }

    /**
     * Añade las columnas que falten, en cadena detrás de `$ancla` (ver la migración anterior).
     *
     * @param  array<string, callable(Blueprint): \Illuminate\Database\Schema\ColumnDefinition>  $columnas
     */
    private function anadir(string $tabla, string $ancla, array $columnas): void
    {
        Schema::table($tabla, function (Blueprint $t) use ($tabla, $ancla, $columnas) {
            $previa = Ancla::de($t, $ancla);

            foreach ($columnas as $nombre => $definir) {
                if (! Schema::hasColumn($tabla, $nombre)) {
                    $columna = $definir($t);

                    if ($previa !== null) {
                        $columna->after($previa);
                    }
                }

                $previa = $previa === null ? null : $nombre;
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('ws_actividades_resueltas', 'ws_actividades_resueltas_act_user_alumno_index')) {
            Schema::table('ws_actividades_resueltas', fn (Blueprint $t) => $t->dropIndex('ws_actividades_resueltas_act_user_alumno_index'));
        }

        $this->quitar('ws_actividades_resueltas', ['user_id', 'alumno_id', 'publico', 'grupo_id', 'intento',
            'iniciada_at', 'enviada_at', 'puntaje', 'puntaje_max', 'nota_calculada']);
        $this->quitar('ws_respuestas', ['texto', 'valor', 'fecha', 'archivo_id']);
        $this->quitar('ws_opciones', ['error_tipico']);
        $this->quitar('ws_preguntas', ['seccion', 'obligatoria', 'imagen_id', 'youtube_id', 'youtube_inicio',
            'youtube_fin', 'enlace_url', 'escala_estilo', 'compartir', 'explicacion', 'puntaje_parcial']);

        // `persona_id` NO vuelve a `NOT NULL`: una hoja anónima escrita lo haría fallar.
    }

    /** @param list<string> $columnas */
    private function quitar(string $tabla, array $columnas): void
    {
        $existen = array_values(array_filter($columnas, fn ($c) => Schema::hasColumn($tabla, $c)));

        if ($existen !== []) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn($existen));
        }
    }
};
