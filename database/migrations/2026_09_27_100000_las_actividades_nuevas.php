<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * LAS ACTIVIDADES NUEVAS  *(26 sep 2026, tanda 1 de `myvc_front/docs/funciones/ACTIVIDADES-CONTRATO.md` §1.1)*.
 *
 * Tareas, cuestionarios y encuestas viven en la misma `ws_actividades` que el módulo viejo, y se
 * distinguen por `modo`: **`modo IS NULL` es una actividad vieja**, y todo lo nuevo filtra
 * `modo IS NOT NULL`. Así no hace falta una tabla paralela ni mover las filas que ya existen.
 *
 * Las siete migraciones de actividades van juntas en la tanda 1 aunque algunas columnas no se usen
 * hasta más tarde: un solo despliegue de esquema a los dieciséis, y ninguna tanda posterior tumba
 * la API por una migración sin correr.
 *
 * **Defensiva, porque los dieciséis han derivado**: cada columna dentro de su `hasColumn`, y el
 * `->after()` con `Ancla::de()` (ver `app/Support/Ancla.php`). Las columnas nuevas van en cadena
 * —cada una detrás de la anterior— porque Laravel emite un `ALTER` por columna y en orden, así que
 * cuando llega la segunda la primera ya existe. Si el ancla `finaliza_at` falta en un colegio, la
 * cadena entera se va al final de la tabla, que es cosmético.
 *
 * **La única columna existente que cambia es `asignatura_id`, que pasa a NULL** (una encuesta a todo
 * el colegio no tiene asignatura). Aprobado por Joseth: en producción hay 7 filas de prueba de
 * 2019–2020. Va con `MODIFY` y no con `->change()`: no depende de doctrine/dbal y conserva la FK.
 * Hacia tablas viejas (`users`, `grupos`, `unidades`) sólo índice, sin FK: un tipo derivado en un
 * colegio haría fallar el `ALTER`.
 */
return new class extends Migration
{
    private const TABLA = 'ws_actividades';

    public function up(): void
    {
        $asignatura = DB::selectOne(
            "SELECT is_nullable AS nulo FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'ws_actividades' AND column_name = 'asignatura_id'"
        );

        if ($asignatura && $asignatura->nulo === 'NO') {
            DB::statement('ALTER TABLE ws_actividades MODIFY asignatura_id INT UNSIGNED NULL');
        }

        Schema::table(self::TABLA, function (Blueprint $t) {
            $previa = Ancla::de($t, 'finaliza_at');

            foreach ($this->columnas() as $nombre => $definir) {
                if (! Schema::hasColumn(self::TABLA, $nombre)) {
                    $columna = $definir($t);

                    if ($previa !== null) {
                        $columna->after($previa);
                    }
                }

                $previa = $previa === null ? null : $nombre;
            }
        });

        // Los índices, sólo si no están: la migración tiene que poder correr dos veces.
        foreach (['modo', 'year_id', 'grupo_id', 'cierra_at'] as $columna) {
            $indice = 'ws_actividades_'.$columna.'_index';

            if (Schema::hasColumn(self::TABLA, $columna) && ! Schema::hasIndex(self::TABLA, $indice)) {
                Schema::table(self::TABLA, fn (Blueprint $t) => $t->index($columna, $indice));
            }
        }

        if (Schema::hasColumn(self::TABLA, 'duplicada_de') && ! $this->tieneFk('ws_actividades_duplicada_de_foreign')) {
            Schema::table(self::TABLA, function (Blueprint $t) {
                $t->foreign('duplicada_de')->references('id')->on('ws_actividades')->nullOnDelete();
            });
        }
    }

    /**
     * Las columnas nuevas, en el orden del contrato.
     *
     * @return array<string, callable(Blueprint): ColumnDefinition>
     */
    private function columnas(): array
    {
        return [
            'modo' => fn ($t) => $t->enum('modo', ['tarea', 'cuestionario', 'encuesta'])->nullable(),
            'year_id' => fn ($t) => $t->unsignedInteger('year_id')->nullable(),
            'grupo_id' => fn ($t) => $t->unsignedInteger('grupo_id')->nullable(),
            'titulo' => fn ($t) => $t->string('titulo', 160)->nullable(),
            'instrucciones' => fn ($t) => $t->mediumText('instrucciones')->nullable(),
            'estado' => fn ($t) => $t->enum('estado', ['borrador', 'por_aprobar', 'publicada', 'cerrada'])->nullable(),
            'alcance' => fn ($t) => $t->enum('alcance', ['clase', 'grupo', 'grupos', 'colegio', 'personal'])->nullable(),
            'responden' => fn ($t) => $t->enum('responden', ['alumnos', 'acudientes', 'ambos', 'personal'])->nullable(),
            'acudiente_por_hijo' => fn ($t) => $t->boolean('acudiente_por_hijo')->default(true),
            'anonimato' => fn ($t) => $t->enum('anonimato', ['nombre', 'seguimiento', 'total'])->default('nombre'),
            'requiere_aprobacion' => fn ($t) => $t->boolean('requiere_aprobacion')->default(false),
            'aprobada_por' => fn ($t) => $t->unsignedInteger('aprobada_por')->nullable(),
            'aprobada_at' => fn ($t) => $t->dateTime('aprobada_at')->nullable(),
            'rechazo_motivo' => fn ($t) => $t->string('rechazo_motivo', 500)->nullable(),
            'publica_at' => fn ($t) => $t->dateTime('publica_at')->nullable(),
            'cierra_at' => fn ($t) => $t->dateTime('cierra_at')->nullable(),
            'cerrada_at' => fn ($t) => $t->dateTime('cerrada_at')->nullable(),
            'recibir_tarde' => fn ($t) => $t->boolean('recibir_tarde')->default(false),
            'entrega_texto' => fn ($t) => $t->boolean('entrega_texto')->default(false),
            'entrega_foto' => fn ($t) => $t->boolean('entrega_foto')->default(false),
            'entrega_archivo' => fn ($t) => $t->boolean('entrega_archivo')->default(false),
            'entrega_enlace' => fn ($t) => $t->boolean('entrega_enlace')->default(false),
            'califica' => fn ($t) => $t->boolean('califica')->default(false),
            'unidad_id' => fn ($t) => $t->unsignedInteger('unidad_id')->nullable(),
            'peso' => fn ($t) => $t->unsignedSmallInteger('peso')->nullable(),
            'nota_maxima' => fn ($t) => $t->unsignedSmallInteger('nota_maxima')->nullable(),
            'mostrar_correctas' => fn ($t) => $t->enum('mostrar_correctas', ['nunca', 'al_enviar', 'al_cerrar'])->default('al_cerrar'),
            'comparte_resultados' => fn ($t) => $t->enum('comparte_resultados', ['no', 'respondieron', 'todos'])->default('no'),
            'resultados_compartidos_at' => fn ($t) => $t->dateTime('resultados_compartidos_at')->nullable(),
            'avisar_al_publicar' => fn ($t) => $t->boolean('avisar_al_publicar')->default(true),
            'recordar_horas_antes' => fn ($t) => $t->unsignedSmallInteger('recordar_horas_antes')->nullable(),
            'en_calendario' => fn ($t) => $t->boolean('en_calendario')->default(true),
            'duplicada_de' => fn ($t) => $t->unsignedInteger('duplicada_de')->nullable(),
        ];
    }

    private function tieneFk(string $nombre): bool
    {
        return DB::selectOne(
            "SELECT 1 AS si FROM information_schema.table_constraints
              WHERE constraint_schema = DATABASE() AND table_name = 'ws_actividades'
                AND constraint_type = 'FOREIGN KEY' AND constraint_name = ?",
            [$nombre]
        ) !== null;
    }

    public function down(): void
    {
        if ($this->tieneFk('ws_actividades_duplicada_de_foreign')) {
            Schema::table(self::TABLA, fn (Blueprint $t) => $t->dropForeign('ws_actividades_duplicada_de_foreign'));
        }

        foreach (['modo', 'year_id', 'grupo_id', 'cierra_at'] as $columna) {
            $indice = 'ws_actividades_'.$columna.'_index';

            if (Schema::hasIndex(self::TABLA, $indice)) {
                Schema::table(self::TABLA, fn (Blueprint $t) => $t->dropIndex($indice));
            }
        }

        $quitar = array_values(array_filter(
            array_keys($this->columnas()),
            fn ($c) => Schema::hasColumn(self::TABLA, $c)
        ));

        if ($quitar !== []) {
            Schema::table(self::TABLA, fn (Blueprint $t) => $t->dropColumn($quitar));
        }

        // `asignatura_id` NO vuelve a `NOT NULL`: con una encuesta de colegio ya escrita el
        // `ALTER` fallaría, y devolverla a obligatoria no protege nada que las rutas viejas usen.
    }
};
