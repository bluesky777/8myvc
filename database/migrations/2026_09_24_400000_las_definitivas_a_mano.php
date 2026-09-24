<?php

use App\Support\Ancla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * years.profes_pueden_cambiar_definitivas  tinyint(1) NOT NULL DEFAULT 1
 *
 * Fase 3 del cierre de periodo (`myvc_front/PLAN-CIERRE-DE-PERIODO.md`, decisión 3 de
 * Joseth del 23 sep 2026): **nivelar es sólo nivelar**. Hasta hoy
 * `periodos.profes_pueden_nivelar` gobernaba dos cosas —nivelar y cambiar la definitiva
 * a mano— y un colegio que abría la semana de nivelaciones abría también la edición
 * suelta de definitivas. Esta columna parte la segunda.
 *
 * **Nace en 1, que es lo de hoy**: con ella en 1 la regla es exactamente la de antes
 * —la definitiva a mano se abre y se cierra con la nivelación de cada periodo—. Sólo el
 * colegio que la baje a 0 nota algo. Aditiva: no toca ninguna fila existente.
 *
 * Es del AÑO y no del periodo: la ventana sigue siendo la de nivelar de cada periodo;
 * esto dice si dentro de esa ventana cabe también la edición a mano. Quién la escribe,
 * en `YearsController::putDefinitivasAMano`.
 */
class LasDefinitivasAMano extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('years', 'profes_pueden_cambiar_definitivas')) {
            return;
        }

        Schema::table('years', function (Blueprint $tabla) {
            $tabla->boolean('profes_pueden_cambiar_definitivas')
                ->default(true)
                ->after(Ancla::de($tabla, 'profes_pueden_editar_plantilla'));
        });
    }

    public function down()
    {
        Schema::table('years', function (Blueprint $tabla) {
            $tabla->dropColumn('profes_pueden_cambiar_definitivas');
        });
    }
}
