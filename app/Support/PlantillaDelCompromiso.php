<?php

namespace App\Support;

/**
 * Los ocho bloques del compromiso académico, y con qué texto nacen.
 *
 * Diseño en `myvc_front/COMPROMISOS-ACADEMICOS.md` §8. Esto es **el catálogo y el
 * defecto**, no lo que imprime un colegio: lo que imprime sale de
 * `compromiso_bloques`, y esas filas sólo existen cuando el colegio guarda.
 * Mientras no las haya, se lee de aquí.
 *
 * ## La decisión que más importa: qué NO trae texto por defecto
 *
 * Dos de los ocho nacen **vacíos y apagados**, y es a propósito:
 *
 *   - **`cita`** — la cita pedagógica. El formato del Bethel abre con un párrafo
 *     de Elena G. de White. Es suyo. Ponerlo de defecto lo imprimiría en los
 *     otros quince.
 *   - **`considerando_siee`** — los artículos del SIEE. **Éste es el peligroso.**
 *     El SIEP del Bethel dice «tres (3) o más asignaturas»; otro colegio puede
 *     decir dos, y el parágrafo de primaria no lo tienen todos. Un defecto aquí
 *     no es una molestia: es **un papel firmado que cita mal la norma interna del
 *     colegio**, que es exactamente por lo que la
 *     [T-646/11](https://www.corteconstitucional.gov.co/relatoria/2011/T-646-11.htm)
 *     condenó a un colegio — *«los reglamentos deben establecer normas con entera
 *     nitidez y armonía»*. Nace vacío con una instrucción dentro, para que quien
 *     abra la pantalla vea el hueco y lo llene.
 *
 * Los otros seis sí traen texto, y pueden traerlo porque **no son del colegio:
 * son del Decreto 1290 y de la Ley 1581**, que son iguales para los dieciséis.
 *
 * ## Y uno que no trae lo que el formato original sí tiene
 *
 * `determina` **no incluye la cláusula de cancelación del contrato de matrícula**
 * que el formato en papel del Bethel lleva en su punto 3. No es un olvido, es
 * §1.3 del diseño: esa cláusula es una **sanción**, y la Corte le exige debido
 * proceso disciplinario completo. Un compromiso que la lleve sin ese proceso es
 * el papel que se cae en tutela.
 *
 * El colegio puede escribirla —el bloque es suyo y se edita— pero **no se la
 * ofrece el sistema**: la diferencia entre «lo escribí» y «venía puesto» es la
 * que importa el día que alguien pregunte quién lo decidió.
 *
 * ## Los marcadores
 *
 * `{alumno}`, `{grado}`, `{acudiente}`… se sustituyen al imprimir. Van entre
 * llaves y en minúscula. La lista viva es `MARCADORES`.
 */
final class PlantillaDelCompromiso
{
    /**
     * Los marcadores que la impresión sustituye, con lo que significan.
     *
     * Está aquí y no repartido por los textos porque la pantalla de la plantilla
     * los enseña como ayuda: un colegio que escribe su propio bloque tiene que
     * poder saber qué puede meter dentro sin leer código.
     *
     * @var array<string, string>
     */
    public const MARCADORES = [
        '{alumno}'            => 'Nombre completo del estudiante',
        '{documento_alumno}'  => 'Documento del estudiante (T.I. o R.C.)',
        '{grado}'             => 'Grado y grupo',
        '{acudiente}'         => 'Nombre completo del acudiente',
        '{cedula_acudiente}'  => 'Cédula del acudiente',
        '{periodo}'           => 'Periodo en palabras: «tercero»',
        '{porcentaje}'        => 'Porcentaje del año cursado: «75 %»',
        '{cuantas}'           => 'Cuántas áreas o asignaturas perdidas',
        '{unidad}'            => '«áreas» o «asignaturas», según la regla del colegio',
        '{plazo}'             => 'Nombre y fechas del plazo',
        '{dias_reclamacion}'  => 'Días hábiles para reclamar',
        '{colegio}'           => 'Nombre del colegio',
    ];

    /**
     * El catálogo, en el orden en que nace.
     *
     * `clave` es estable y es lo que empareja con `compromiso_bloques.clave`:
     * cambiarla huérfana lo que los colegios ya escribieron. Añadir una entrada
     * nueva al final es seguro; renombrar una existente no lo es.
     *
     * @return list<array{clave: string, titulo: ?string, cuerpo: string, activo: bool, del_colegio: bool, ayuda: string}>
     */
    public static function catalogo(): array
    {
        return [
            [
                'clave'       => 'cita',
                'titulo'      => null,
                'cuerpo'      => '',
                'activo'      => false,
                'del_colegio' => true,
                'ayuda'       => 'El párrafo con el que el colegio abre el documento. Nace vacío porque es suyo.',
            ],

            [
                'clave'       => 'marco',
                'titulo'      => null,
                'activo'      => true,
                'del_colegio' => false,
                'ayuda'       => 'Por qué existe este documento. Sale del Decreto 1290, así que vale para cualquier colegio.',
                'cuerpo'      => 'El proceso de formación de los educandos requiere la constante participación de todos '
                    ."sus miembros desde sus respectivos roles, con el fin de facilitar el objetivo de aprendizaje y "
                    ."culminar satisfactoriamente el año académico. En coherencia con este principio y tomando como "
                    ."referente que:\n\n"
                    ."1. El Decreto 1290 de 2009 y las Sentencias de la Corte Constitucional exigen el diligenciamiento "
                    ."de un compromiso académico para el estudiante que ha registrado incumplimiento y/o bajo "
                    ."desempeño.\n"
                    ."2. {colegio}, como institución educativa, determina los principios y acuerdos que regulan el "
                    ."rendimiento académico de cada uno de sus estudiantes con el fin de favorecer la formación "
                    ."integral efectiva.\n"
                    ."3. El Proyecto Educativo Institucional — PEI promueve el desarrollo pleno de la formación "
                    .'integral del estudiante.',
            ],

            [
                'clave'       => 'considerando_siee',
                'titulo'      => 'Y CONSIDERANDO QUE:',
                'activo'      => false,
                'del_colegio' => true,
                'ayuda'       => 'Los artículos del SIEE de este colegio sobre promoción y reprobación. '
                    .'Nace vacío a propósito: citar mal la norma interna es lo que se cae en tutela.',
                'cuerpo'      => '[Escriba aquí los artículos del Sistema Institucional de Evaluación y Promoción de '
                    .'este colegio que hablen de promoción, reprobación y habilitación, copiados literalmente. '
                    .'Si el colegio tiene un parágrafo distinto para primaria, va también aquí.]',
            ],

            [
                'clave'       => 'considerando_norma',
                'titulo'      => null,
                'activo'      => true,
                'del_colegio' => false,
                'ayuda'       => 'Los deberes que el Decreto 1290 reparte entre colegio, padres y estudiante.',
                'cuerpo'      => 'Es RESPONSABILIDAD DE LA INSTITUCIÓN promover y mantener la interlocución con los '
                    ."padres de familia y el estudiante, con el fin de presentar los informes periódicos de "
                    ."evaluación, el plan de actividades de apoyo para la superación de las debilidades, y acordar "
                    ."los compromisos por parte de todos los involucrados. (Art. 11, Decreto 1290 de 2009).\n\n"
                    ."Es DEBER DE LOS PADRES acompañar el proceso educativo en cumplimiento de su responsabilidad "
                    ."como primeros educadores de sus hijos.\n\n"
                    ."Son DEBERES DE LOS(AS) ESTUDIANTES cumplir con los compromisos académicos y de convivencia "
                    ."definidos por el establecimiento educativo, y cumplir con las recomendaciones y compromisos "
                    .'frente a la superación de sus debilidades. (Art. 13, Decreto 1290 de 2009).',
            ],

            [
                'clave'       => 'determina',
                'titulo'      => 'SE DETERMINA QUE:',
                'activo'      => true,
                'del_colegio' => false,
                'ayuda'       => 'Qué se constata y qué se pide. No trae ninguna cláusula de sanción: '
                    .'eso es un proceso disciplinario aparte, no un plan de apoyo.',
                'cuerpo'      => 'Cursado el {porcentaje} del año escolar, el(la) estudiante presenta {cuantas} '
                    ."{unidad} por debajo de la nota mínima aprobatoria, según el detalle que aparece más "
                    ."adelante.\n\n"
                    ."El(la) estudiante DEBE realizar un mejoramiento significativo durante {plazo}, de manera que "
                    ."pueda alcanzar los Derechos Básicos del Aprendizaje de las asignaturas que viene "
                    ."comprometiendo.\n\n"
                    .'La institución dejará constancia escrita del resultado de cada una y lo comunicará al acudiente.',
            ],

            [
                'clave'       => 'actores',
                'titulo'      => 'ACTORES DEL COMPROMISO',
                'activo'      => true,
                'del_colegio' => false,
                'ayuda'       => 'A qué se compromete cada uno.',
                'cuerpo'      => 'Compromiso del ACUDIENTE: revisar la plataforma de la institución permanentemente '
                    ."con el fin de ayudar a su acudido en el alcance de los logros de cada área en que ha "
                    ."presentado dificultades, y acompañar el cumplimiento de este plan.\n\n"
                    ."Compromiso del ESTUDIANTE: solicitar asesoría, presentar al día cuadernos de apuntes, "
                    ."evaluaciones y trabajos a tiempo, presentarse con el uniforme correspondiente, dar los aportes "
                    ."en el momento preciso y aprobar todas las asignaturas. Así como ser puntual y asistir a clase o, "
                    ."de lo contrario, presentar excusa firmada por el acudiente.\n\n"
                    .'Compromiso de la INSTITUCIÓN: disponer las jornadas de apoyo en cada una de las {unidad} '
                    .'señaladas, informar el resultado de cada una al cierre del plazo y dejar constancia escrita de él.',
            ],

            [
                'clave'       => 'declaracion',
                'titulo'      => null,
                'activo'      => true,
                'del_colegio' => false,
                'ayuda'       => 'El párrafo que identifica a las dos partes. Lleva marcadores: se rellena solo.',
                'cuerpo'      => 'El(la) Señor(a) {acudiente}, identificado(a) con C.C. N.º {cedula_acudiente}, en '
                    .'calidad de acudiente de {alumno}, identificado(a) con documento N.º {documento_alumno}, '
                    .'estudiante del grado {grado}, declara conocer el presente compromiso académico y las '
                    .'{unidad} que en él se relacionan.',
            ],

            [
                'clave'       => 'plan',
                'titulo'      => null,
                'activo'      => true,
                'del_colegio' => false,
                'ayuda'       => 'El plazo y el derecho a reclamar. Es lo que convierte el papel en debido proceso.',
                'cuerpo'      => 'La institución dispondrá jornadas de apoyo durante {plazo} en cada una de las '
                    .'{unidad} señaladas, informará el resultado de cada una al cierre y dejará constancia escrita '
                    .'de él. Contra ese resultado procede reclamación escrita ante la Coordinación Académica dentro '
                    .'de los {dias_reclamacion} días hábiles siguientes a su entrega.',
            ],

            [
                'clave'       => 'datos',
                'titulo'      => null,
                'activo'      => true,
                'del_colegio' => false,
                'ayuda'       => 'El aviso de tratamiento de datos personales. La Ley 1581 es igual para todos.',
                'cuerpo'      => '{colegio}, conforme a las disposiciones contenidas en la Ley 1581 de 2012 y su '
                    .'Decreto reglamentario, como custodio responsable y/o encargado del tratamiento de datos '
                    .'personales, propenderá por la seguridad y confidencialidad de los datos sensibles o personales '
                    .'que se hayan recogido y tratado en operaciones tales como la recolección, almacenamiento, uso, '
                    .'circulación y supresión de aquella información que se reciba de terceros a través de los '
                    .'diferentes canales de recolección de información.',
            ],
        ];
    }

    /**
     * Las claves del catálogo, para validar lo que llega de la pantalla.
     *
     * @return list<string>
     */
    public static function claves(): array
    {
        return array_map(
            static fn (array $b): string => $b['clave'],
            self::catalogo()
        );
    }

    /**
     * Los firmantes con los que nace el papel.
     *
     * **Son cargos, no personas.** Los nombres salen de `Year::datos()` y se
     * confirman cada año por su lado; estos rótulos son los renglones de firma, y
     * por eso sí se heredan en enero (ver la cabecera de la migración).
     *
     * @return list<string>
     */
    public static function firmantesPorDefecto(): array
    {
        return ['Coordinación Académica', 'Director(a) de grupo', 'Acudiente', 'Estudiante'];
    }
}
