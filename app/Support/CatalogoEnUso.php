<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Impedir que se borre una fila de catálogo a la que otra fila sigue apuntando.
 *
 * **Decisión de Joseth, 23 ago 2026** sobre la pregunta que abrieron la
 * [§70](../../docs/migracion/05-codigo-muerto-y-roto.md) y el lote B: *si borrar
 * un catálogo referenciado debe impedirse, avisar, o dejarse.* Se impide, **y el
 * aviso dice cuántas filas dependen**, que es lo que convierte un «no se puede»
 * en algo accionable.
 *
 * ## Por qué no se aplica a todos los catálogos, que es la parte que importa
 *
 * La §70.2 dejó medida la regla que decide dónde duele: **con `inner join` la
 * papelera esconde la fila entera; con `left join` deja un hueco visible.** Pero
 * el criterio completo tiene una segunda mitad, que salió al mirar los seis:
 * **no es sólo el tipo de `join`, es si lo que desaparece era el sentido de la
 * fila hija.**
 *
 *   grados        -> grupos      `Profesor::asignaturas` une por `inner join`, así que el
 *                                profesor **deja de ver sus asignaturas y no puede poner
 *                                notas**. Y no hay `restore` de grados: se arregla entrando
 *                                a la base. Es el daño medido en la §70.3.  **SE BLOQUEA.**
 *
 *   dis_ordinales -> faltas      `left join`, así que la falta sigue saliendo — **sin el
 *                                artículo que dice qué se incumplió**. El hueco no es
 *                                cosmético: es el contenido del registro.  **SE BLOQUEA.**
 *
 *   frases        -> definiciones   `definiciones_comportamiento` tiene `frase_id` **y
 *                                `frase`**, con el texto ya copiado dentro. Borrar la frase
 *                                del banco no le quita nada a la definición.  **NO se
 *                                bloquea** — y bloquear dejaría 235 de 426 frases sin poder
 *                                retirar del banco, a cambio de nada.
 *
 *   tipos_documentos, ciudades   `left join` y hueco visible, medido en la §70.2: mandar un
 *                                tipo de documento a la papelera **no esconde a ningún
 *                                alumno**, le deja la casilla vacía a la vista.  **NO se
 *                                bloquean**, y además dejarían las dos tablas prácticamente
 *                                inborrables.
 *
 * Los tres que quedan —`niveles_educativos`, `areas`, `materias`— tienen la misma
 * forma que `grados` y **están sin aplicar a propósito**, porque el número manda:
 * bloquear niveles dejaría **4 de 4** sin poder borrarse nunca, areas 20 de 22 y
 * materias 27 de 35. Eso es una decisión aparte y está en el 09 con sus cuentas.
 * Añadir uno es una línea aquí.
 *
 * ## 422 y no 409
 *
 * Es la respuesta a un cuerpo que no se puede procesar por el estado de los datos,
 * que es lo que este repo viene usando para eso. El legacy de al lado contesta 400
 * a todo; en código nuevo van los códigos correctos.
 */
class CatalogoEnUso
{
    /**
     * Corta con 422 si alguna fila viva de `$tabla` apunta a `$id` por `$columna`.
     *
     * @param  string  $que  cómo se llama lo que depende, en plural y en el idioma
     *                       del colegio: «grupos», «situaciones de disciplina».
     */
    public static function exigirQueNadieApunte(string $tabla, string $columna, mixed $id, string $que): void
    {
        // **Sin id no hay nada que proteger**, y esto no es una guarda defensiva de
        // adorno: `where($columna, null)` en Laravel **no compara contra null, monta
        // un `IS NULL`** —lo convierte a `whereNull` cuando el valor es null—, así
        // que contaría las filas huérfanas de la tabla hija y **bloquearía un
        // borrado que no iba a tocar nada**. Lo encontró `OrdinalesTest`, que llama
        // a `ordinales/destroy` con el cuerpo vacío a propósito: las cuatro rutas de
        // ese controlador contestan «hecho» sin escribir cuando falta el id (§87.1),
        // y eso se fija tal cual — cerrar eso es otra decisión.
        if ($id === null || $id === '') {
            return;
        }

        // `$tabla` y `$columna` son literales escritos en el controlador que
        // llama, y **aquí no se comprueban contra el esquema**. Si algún día
        // alguien los hace venir del cuerpo, esto pasa a ser una inyección: en ese
        // momento hay que meterlos por `ColumnaSegura`, que es lo que ya existe
        // para eso. Se dice porque un `DB::table($variable)` no lo parece.
        $dependen = DB::table($tabla)
            ->where($columna, $id)
            ->whereNull('deleted_at')
            ->count();

        if ($dependen > 0) {
            abort(422, "No se puede eliminar: hay {$dependen} {$que} que dependen de esto. "
                .'Quítalos o muévelos antes de eliminarlo.');
        }
    }
}
