<?php

use App\Support\CorreoDeLaCuenta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copia a la CUENTA el correo que alumnos y docentes ya tenían escrito en su FICHA.
 *
 * Es la hermana de `2026_09_20_200000_el_correo_del_acudiente_en_su_cuenta` y la
 * razón es la misma: `alumnos.email` y `profesores.email` son la ficha, y **la
 * recuperación de contraseña sólo mira `users.email`** (`LoginController:240-266`,
 * cuatro consultas y las cuatro sobre esa columna). Nadie sincronizaba las dos.
 * Decisión de Joseth del 24 sep 2026 (PLAN-COSAS-PENDIENTES §2.6 en `myvc_front`):
 * *el correo que cuenta es el de la cuenta.*
 *
 * ## Medido antes de correrla, 24 sep 2026
 *
 * En el docker (`micolev1_la_hermosa`, lo que apunta el `.env` ese día) **no hay
 * nada que copiar**: 176 alumnos vivos, los 103 con ficha llevan el literal
 * `'@gmail.com'`; 14 docentes, los 14 con cuenta ya escrita (13 de ellas
 * `@myvc.com`). En `simonbolivar`, que tiene más datos:
 *
 *     alumnos     1.246 vivos · 783 con ficha, sólo 2 direcciones de verdad
 *                 31 candidatos — los 31 son `'@gmail.com'`, así que 0 se copian
 *     profesores     47 vivos · 24 con ficha · 13 cuentas vacías
 *                  5 candidatos → 4 se copian, 1 choca con otra cuenta viva
 *
 * ## Lo que hace, y lo que NO hace a propósito
 *
 * Copia la ficha **recortada** a la cuenta **sólo cuando la cuenta está vacía**
 * (`NULL` o sólo espacios). No pisa ningún valor que ya haya, **tampoco los
 * `username@myvc.com`** que fabricaban `AlumnosController` y `ProfesoresController`
 * hasta el 20 sep: son buzones de nadie, pero limpiarlos es otra decisión y no se
 * ha tomado. Se cuentan en el `echo` para que se vean.
 *
 * Y no copia lo que no es una dirección: la regla es `CorreoDeLaCuenta::oNada()`,
 * que rechaza el literal `'@gmail.com'` que mandaba el alta de la aplicación vieja.
 * Copiarlo haría que el reseteo encontrara la cuenta —y a otras 650 con el mismo
 * literal— y contestara «Enviado» sin entregar nada.
 *
 * ## Las colisiones se SALTAN
 *
 * `users.email` no tiene índice único, pero `LoginController:240` se queda con
 * `$persona[0]`: un correo repetido no da error, le manda el enlace a uno de los
 * dos y el otro no se entera. Se pregunta DENTRO del bucle —como en la de
 * acudientes, donde tres chocaban entre ellos mismos— y se cuentan aparte.
 *
 * ## Es segura de repetir, y `down()` no deshace
 *
 * Al copiar, la cuenta deja de estar vacía y la segunda pasada no la ve. Y no se
 * sabe qué filas puso esta migración: vaciar las que coincidan con la ficha
 * borraría también las que ya estaban bien. *La vuelta atrás es la copia de
 * seguridad.*
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['alumnos' => 'alumnos', 'profesores' => 'docentes'] as $tabla => $quienes) {
            $candidatas = DB::select(
                "SELECT u.id AS user_id, TRIM(p.email) AS correo
                   FROM {$tabla} p
                   JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
                  WHERE p.deleted_at IS NULL
                    AND p.email IS NOT NULL AND TRIM(p.email) <> ''
                    AND (u.email IS NULL OR TRIM(u.email) = '')
                  ORDER BY u.id"
            );

            $copiados = 0;
            $noEsCorreo = 0;
            $chocan = 0;

            foreach ($candidatas as $fila) {
                $correo = CorreoDeLaCuenta::oNada($fila->correo);

                if ($correo === null) {
                    $noEsCorreo++;

                    continue;
                }

                // DENTRO del bucle: si dos fichas comparten correo, la primera lo
                // ocupa y la segunda pasa a ser colisión.
                $ocupado = DB::selectOne(
                    'SELECT COUNT(*) AS n FROM users
                      WHERE deleted_at IS NULL AND id <> ? AND email = ?',
                    [$fila->user_id, $correo]
                );

                if ((int) $ocupado->n > 0) {
                    $chocan++;

                    continue;
                }

                DB::update('UPDATE users SET email = ? WHERE id = ?', [$correo, $fila->user_id]);
                $copiados++;
            }

            // Los inventados que se quedan como están, para que se vean al correrla.
            $inventados = DB::selectOne(
                "SELECT COUNT(*) AS n FROM {$tabla} p
                   JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
                  WHERE p.deleted_at IS NULL AND u.email LIKE '%@myvc.com'"
            );

            // Se imprime porque corre en dieciséis colegios y «migrated» a secas no
            // distingue «no había ninguno» de «no hizo nada».
            echo sprintf(
                "    correo de %s -> su cuenta:  copiados %d de %d candidatos; %d no son una direccion; %d sin tocar por colision; %d cuentas @myvc.com sin tocar\n",
                $quienes, $copiados, count($candidatas), $noEsCorreo, $chocan, (int) $inventados->n
            );
        }
    }

    public function down(): void
    {
        echo "    el_correo_de_alumnos_y_docentes_en_su_cuenta: down() no restaura — la vuelta es la\n"
            ."    copia de seguridad, no esta migracion. El porque, en su cabecera.\n";
    }
};
