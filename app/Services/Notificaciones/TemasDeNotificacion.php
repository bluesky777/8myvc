<?php

namespace App\Services\Notificaciones;

/**
 * El nombre del tema al que se suscribe un teléfono. **Es la pieza de seguridad
 * de todo el diseño de notificaciones.**
 *
 * Firebase reparte por *temas*: el teléfono se apunta él mismo y el servidor
 * publica una vez, tenga el tema tres dispositivos o tres mil. Eso es lo que
 * hace viable el push en un hosting compartido —cero tokens que guardar, cero
 * tablas, una petición por aviso— y a la vez **es lo que hay que hacer bien**:
 *
 * > Si el tema se llamara `alumno_345`, cualquiera con la app podría apuntarse
 * > al `alumno_346` y recibir los avisos de un menor que no es suyo. **El nombre
 * > del tema es, en la práctica, la única puerta.**
 *
 * Por eso el nombre **no se calcula en el teléfono**: se deriva aquí con
 * HMAC-SHA256 y un secreto que vive sólo en el servidor, y el teléfono lo recibe
 * ya hecho al identificarse (`GET notificaciones/temas`). Sin el secreto no se
 * puede derivar el de otro alumno, y del tema no se vuelve al secreto.
 *
 * ## Por qué el hash se recorta a 32 caracteres
 *
 * Porque 128 bits ya no se adivinan y el nombre se lee en registros y en la
 * consola de Firebase. Recortar un HMAC es la práctica normal —no debilita nada
 * más allá de los bits que se quitan— y aquí lo que protege es que el espacio
 * sea inabarcable, no que sea largo.
 *
 * ## Y el contenido del aviso no dice nada, que es la segunda mitad
 *
 * «Laura tiene 4 notas nuevas en Matemáticas», nunca «Laura sacó 45». Una
 * notificación se ve en la pantalla bloqueada, en el bus, con gente al lado. Eso
 * hace que **incluso el peor caso —que se filtre un nombre de tema— entregue
 * ruido y no datos**: quien se colara recibiría «hay algo nuevo» de un
 * desconocido.
 *
 * El plan entero está en `myvc_flutter/docs/notificaciones.md`.
 */
class TemasDeNotificacion
{
    /**
     * Los tres tipos que se avisan por alumno. Cada uno es un tema aparte, y ahí
     * está la clave de las preferencias: apagar «Notas» es que el teléfono se
     * desapunte de ese tema —una llamada a Google, **cero peticiones al servidor
     * y cero filas en la base**—. El envío no filtra por preferencias porque
     * quien no lo quiere ya no está en el tema.
     */
    public const TIPOS = ['notas', 'asistencia', 'disciplina'];

    /** Los del colegio entero, que no dependen de ningún alumno. */
    public const DEL_COLEGIO = ['colegio_muro', 'colegio_avisos'];

    /**
     * La raíz del tema de un alumno: `a_` + 32 hex de HMAC-SHA256.
     *
     * El prefijo `a_` no es decorativo: FCM exige que el nombre empiece por
     * letra, y un hash puede empezar por dígito.
     */
    public static function deAlumno(int $alumnoId): string
    {
        return 'a_'.substr(hash_hmac('sha256', (string) $alumnoId, self::secreto()), 0, 32);
    }

    /**
     * El tema concreto: la raíz del alumno más el tipo.
     *
     * @throws \InvalidArgumentException si el tipo no es uno de los tres. Se
     *                                   prefiere reventar a componer un tema que no existe: un tema mal escrito
     *                                   no da error en Firebase —publicar en un tema vacío es válido— así que el
     *                                   aviso se perdería en silencio, que es el fallo más caro de este diseño.
     */
    public static function deAlumnoYTipo(int $alumnoId, string $tipo): string
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de notificación desconocido: '.$tipo);
        }

        return self::deAlumno($alumnoId).'_'.$tipo;
    }

    /**
     * Los tres temas de un alumno, listos para mandárselos al teléfono.
     *
     * @return array<string, string>
     */
    public static function todosLosDe(int $alumnoId): array
    {
        $temas = [];

        foreach (self::TIPOS as $tipo) {
            $temas[$tipo] = self::deAlumnoYTipo($alumnoId, $tipo);
        }

        return $temas;
    }

    /**
     * Si hay con qué derivar. Sin secreto **no se inventa uno**: un secreto
     * vacío haría que los dieciséis colegios derivaran el mismo tema para el
     * alumno 345, o sea que el acudiente de un colegio recibiría los avisos de
     * un alumno de otro.
     */
    public static function hayComoDerivar(): bool
    {
        return self::secreto() !== '';
    }

    private static function secreto(): string
    {
        return (string) config('notificaciones.secreto', '');
    }
}
