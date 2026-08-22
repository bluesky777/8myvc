<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `Grupo::detailed_materias()` acepta un profesor y no filtra por él.
 *
 * El método arma el filtro en una variable y **nunca la mete en la consulta**:
 *
 *     $complemento = '';
 *     if ($profesor_id) {
 *         if ($exceptuando) { $complemento = ' and p.id!='.$profesor_id.' '; }
 *         else              { $complemento = ' and p.id='.$profesor_id.' '; }
 *     }
 *     $consulta = 'SELECT ... where a.grupo_id=:grupo_id and a.deleted_at is null ...';
 *
 * `$complemento` se escribe en tres sitios y no se lee en ninguno. Así que los dos
 * parámetros que distinguen «las mías» de «las de los demás» no hacen nada, y las
 * treinta llamadas que pasan solo el grupo se comportan igual que las tres que además
 * pasan el profesor.
 *
 * Salió buscando el patrón de concatenación cruda de C0. **No es una inyección**: lo
 * concatenado es código muerto, nunca llega al SQL. Es la otra cara de la misma lección
 * —un detector da sitios donde mirar, no fallos— y aquí lo que había debajo era un
 * filtro que no filtra.
 *
 * QUÉ SE FIJA AQUÍ Y QUÉ NO. Se fija **lo que hace hoy**, no lo que debería hacer:
 * `putDatos()` le manda al profesor todas las asignaturas del grupo dentro de
 * `mis_asignaturas`. **No está juzgado.** Arreglarlo encoge una lista que ven
 * dieciséis colegios en una pantalla del front, y `app/` es copia real en cada uno,
 * así que es decisión de Joseth y no del que pasaba por aquí. Se escribe el porqué del
 * valor —que es la lección que ya va por la tercera vez en dos días— para que el verde
 * no se lea como que esto está bien.
 *
 * `PUT actividades/datos` **ya estaba cubierta** por `MuestreoDeLecturasConContextoTest`
 * y por la §6 de 13-actividades.md, que revisó la rama del administrativo. Lo que nadie
 * había mirado es el contenido de la rama del profesor.
 */
class FiltroDeProfesorEnMateriasTest extends CasoDeContrato
{
    /** Un profesor con asignaturas en el grupo, y menos que todas. */
    private function profesorDelGrupo(int $grupoId): object
    {
        $fila = DB::selectOne('SELECT u.username, pr.id AS persona_id, COUNT(*) AS suyas
            FROM asignaturas a
            INNER JOIN profesores pr ON pr.id = a.profesor_id AND pr.deleted_at IS NULL
            INNER JOIN users u ON u.id = pr.user_id AND u.tipo = "Profesor"
                AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = p.year_id
            WHERE a.grupo_id = ? AND a.deleted_at IS NULL
            GROUP BY pr.id, u.username ORDER BY suyas DESC, pr.id LIMIT 1', [$grupoId]);

        $this->assertNotNull($fila, "El seed no tiene un profesor con contexto en el grupo {$grupoId}.");

        return $fila;
    }

    public function test_mis_asignaturas_trae_tambien_las_de_los_demas_profesores(): void
    {
        $grupo = $this->grupoConAlumnos();
        $profesor = $this->profesorDelGrupo($grupo->id);

        $todas = (int) DB::selectOne('SELECT COUNT(*) n FROM asignaturas
            WHERE grupo_id = ? AND deleted_at IS NULL', [$grupo->id])->n;

        $this->assertGreaterThan((int) $profesor->suyas, $todas,
            'El grupo necesita asignaturas de más de un profesor: si no, filtrar y no filtrar dan lo mismo.');

        $cuerpo = $this->withToken($this->tokenDe($profesor->username))
            ->putJson('/api/actividades/datos', ['grupo_id' => $grupo->id])
            ->assertStatus(200)
            ->json();

        // Hoy llegan TODAS las del grupo, no las {$profesor->suyas} suyas.
        $this->assertCount($todas, $cuerpo['mis_asignaturas'],
            '`mis_asignaturas` trae el grupo entero: el filtro por profesor no se aplica.');

        $ajenas = array_filter(
            $cuerpo['mis_asignaturas'],
            fn ($a) => (int) $a['profesor_id'] !== (int) $profesor->persona_id
        );

        $this->assertNotEmpty($ajenas,
            'Entre «mis asignaturas» hay asignaturas de otros profesores, que es lo que el '.
            'parámetro ignorado debía dejar fuera.');
    }
}
