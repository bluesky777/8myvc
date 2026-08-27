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

    /**
     * Los del colegio entero, que no dependen de ningún alumno.
     *
     * **Son nombres LÓGICOS, no el nombre del tema.** El de verdad lo compone
     * `delColegio()`, y esa distinción es el arreglo entero de abajo: hasta el
     * 26 ago 2026 estas dos cadenas **eran** el tema, literales y sin nada que
     * dijera de qué colegio.
     */
    public const DEL_COLEGIO = ['colegio_muro', 'colegio_avisos'];

    /**
     * El tema del colegio entero: `c_` + 32 hex de HMAC-SHA256.
     *
     * ## Por qué lleva hash uno que NO tiene nada que esconder
     *
     * Porque **el HMAC del tema del alumno hace dos cosas a la vez y aquí sólo se
     * razonó una**: esconder *de quién* es, y separar *un colegio de otro*. Para el
     * muro la primera no hace falta —«hay 3 publicaciones nuevas» no dice nada de
     * ningún menor, y eso sigue siendo cierto—, y con ella se fue la segunda, que sí.
     *
     * **El proyecto de Firebase es UNO para los quince colegios**: una sola app, un
     * solo `com.micolevirtual.app`, un solo `google-services.json`. Los temas viven
     * en un espacio de nombres compartido, así que un tema llamado `colegio_muro`
     * **es el mismo tema para los quince**. En cuanto dos colegios tuvieran la app,
     * una publicación del muro de uno le llegaría a las familias de los otros
     * catorce: no es fuga de contenido, es **el aviso equivocado a la familia
     * equivocada**, y multiplicado por quince convierte la función en ruido.
     *
     * Lo encontró la sesión de `myvc_flutter` **leyendo el contrato antes de
     * cablearlo**, y no había forma de verlo desde aquí: la premisa que lo convierte
     * en fallo —*un solo proyecto de Firebase*— vive en su repositorio.
     *
     * ## Por qué se deriva del tema y no de un identificador de colegio
     *
     * Porque `secreto()` **ya es distinto en cada colegio** —es su `APP_KEY`, que
     * `key:generate` hace por instalación—, así que HMAC del nombre lógico basta
     * para separarlos. `myvc_flutter` proponía `c_` + HMAC del identificador del
     * colegio; es lo mismo con un dato de más, y ese dato **no existe hoy en
     * `config/`**: meterlo obligaría a editar quince `.env`, que es exactamente lo
     * que la cabecera de `config/notificaciones.php` dice que no se puede pedir.
     *
     * > **Y la letra pequeña, que hay que decirla porque es la misma para todo este
     * > diseño:** si dos colegios compartieran `APP_KEY` —un `.env` copiado al crear
     * > uno nuevo, que es como se crean— sus temas colisionarían. **Eso no lo
     * > introduce esta función**: el tema de alumno depende del mismo secreto desde
     * > el primer día. Lo que cambia es que ahora el fallo sería el mismo en los dos
     * > sitios y no sólo en uno.
     *
     * @throws \InvalidArgumentException si no es uno de los dos, por el mismo motivo
     *                                   que en `de()`: publicar en un tema que no
     *                                   existe es válido en FCM y el aviso se pierde
     *                                   en silencio.
     */
    public static function delColegio(string $tema): string
    {
        if (! in_array($tema, self::DEL_COLEGIO, true)) {
            throw new \InvalidArgumentException("Tema de colegio desconocido: {$tema}");
        }

        return 'c_'.substr(hash_hmac('sha256', $tema, self::secreto()), 0, 32);
    }

    /**
     * Los dos temas del colegio ya compuestos, que es lo que se le entrega a la app.
     *
     * **El nombre viaja hecho y el teléfono no deriva nada**, igual que con los del
     * alumno. Si derivara la app habría dos sitios donde puede escribirse mal y uno
     * no da error: publicar en un tema vacío es válido en FCM, así que el aviso se
     * perdería en silencio. Lo pidió así `myvc_flutter` y es lo que ya hacía el
     * resto de esta clase.
     *
     * @return array<string, string> nombre lógico => tema de verdad
     */
    public static function todosLosDelColegio(): array
    {
        $temas = [];

        foreach (self::DEL_COLEGIO as $tema) {
            $temas[$tema] = self::delColegio($tema);
        }

        return $temas;
    }

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
