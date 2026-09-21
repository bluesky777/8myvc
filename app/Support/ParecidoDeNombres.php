<?php

namespace App\Support;

/**
 * ¿Este nombre escrito a mano es el de aquella persona?
 *
 * Nace para la F6 de «notas sin internet» (fase 3 del plan
 * `myvc_front/PLAN-NOTAS-SIN-INTERNET.md`, §6.4): el docente escribió
 * *«Jose Luis Cardenaz»* en el bloque del final de su planilla y hay que decirle
 * si se refería a *«CÁRDENAS PEÑA, José Luis»*, **sin crear a nadie**.
 *
 * ## Por qué no vale ninguno de los emparejadores que ya hay
 *
 * - {@see AlumnosParecidos} busca **en todo el colegio** y con `LIKE`: contesta
 *   «existe una ficha así», que es otra pregunta. Aquí buscar fuera del grupo
 *   sería escribirle la nota a alguien que no está matriculado ahí.
 * - El `=` de MySQL **ya ignora tildes y mayúsculas** por la colación
 *   `utf8mb4_unicode_ci` (doc 33), así que casa *«Jose»* con *«José»*… y nada
 *   más: no casa *«Cardenaz»* con *«Cárdenas»* ni aguanta el orden cambiado.
 * - Un `LIKE %palabra%` por cada palabra **exige que estén todas**, y el caso
 *   frecuente es justo el contrario: el docente escribe un apellido de los dos.
 *
 * O sea que hace falta un número, no un sí/no, y tiene que salir de PHP.
 *
 * ## La medida, y por qué son dos y no una
 *
 * Se comparan **la cadena entera** y **el conjunto de palabras**, y se mezclan:
 *
 * | | Qué caza | Dónde falla sola |
 * |---|---|---|
 * | Cadena entera (`similar_text`) | la errata dentro de una palabra | el **orden cambiado**: «jose luis cardenaz» contra «cardenas pena jose luis» se queda en **0,391** |
 * | Conjunto de palabras | el orden cambiado y el apellido de más | dos nombres cortos comparten «maria» y ya |
 *
 * El conjunto se calcula en **las dos direcciones**: cuánto de lo que el docente
 * escribió aparece en la ficha, y cuánto de la ficha aparece en lo que escribió.
 * Sólo la primera dejaría que *«Maria»* casara con *«MARÍA FERNANDA GÓMEZ
 * RESTREPO»* al 100 %; sólo la segunda castigaría el apellido que el docente no
 * escribió, que es lo normal.
 *
 * No se usa `levenshtein()` porque **no es multibyte** —cuenta bytes, y una «ñ»
 * son dos— y aquí la entrada viene con tildes por definición. Se normaliza antes
 * de comparar, así que a `similar_text` llega ASCII, pero la normalización es la
 * que tiene que ser fiable y no la comparación.
 *
 * ## El umbral se mide, no se elige
 *
 * {@see UMBRAL} sale de medir contra nombres de verdad de `caz_zaragoza`; el
 * número, la muestra y el método están ahí abajo y en la §7.5 de
 * `docs/migracion/50-el-ensayo-y-la-escritura-de-la-planilla.md`. **Cinco nombres
 * que no se parecen a nada son peor que ninguno**: una tarjeta con cinco caras
 * parecidas convierte una pregunta en una lotería, así que se devuelven pocos y
 * sólo los que pasan la raya.
 */
class ParecidoDeNombres
{
    /**
     * De qué parecido para arriba se enseña un candidato.
     *
     * **Medido, no elegido** (doc 50 §7.5), contra `caz_zaragoza` el 21 sep 2026: 2.121
     * matrículas en 129 grupos, cinco formas de teclear cada nombre (orden
     * cambiado, un apellido de menos, nombre y apellido sueltos, y las dos últimas
     * con una errata de una letra) = **10.425 emparejamientos buenos**, contra
     * **2.165 forasteros** —un nombre de otro grupo buscado en éste, que es el caso
     * del alumno que de verdad no está—.
     *
     * | Umbral | Pierde al bueno | Cuela a un forastero |
     * |---|---|---|
     * | 0,54 | 0,0 % | 10,3 % |
     * | **0,58** | **0,1 %** | **4,8 %** |
     * | 0,62 | 4,0 % | 2,1 % |
     * | 0,66 | 16,5 % | 1,0 % |
     *
     * **El error caro es el de abajo, no el de arriba**, y por eso la raya está
     * donde la curva de los buenos todavía no ha empezado a caer: si el alumno sí
     * está y no se le enseña, la pantalla dice *«no hay nadie con ese nombre»* y
     * manda al docente a secretaría a matricular a alguien que ya está matriculado
     * — un callejón sin salida. Enseñar de más cuesta una lectura: la tarjeta lleva
     * foto, matrícula y el nombre completo, y quien decide es una persona.
     *
     * Los ocho buenos que se pierden a 0,58 son todos del mismo tipo degenerado
     * —«JUAN DE» salido de «DE LOS RIOS PEREZ, JUAN FELIPE»—, que nadie teclea. Y
     * los forasteros que se cuelan son hermanos y homónimos de verdad
     * —«NAHILY MEZA MANCHEGO» contra «MEZA MANCHEGO, NATALY», 0,805—, donde
     * preguntar es exactamente lo que hay que hacer.
     */
    public const UMBRAL = 0.58;

    /**
     * Cuántos candidatos se enseñan como mucho.
     *
     * Tres. No es el tope de una lista: es lo que cabe en una tarjeta que se lee
     * de un vistazo. Si hacen falta cinco, lo que hay es un nombre que no se
     * reconoce, y ésa es la otra respuesta de la pantalla.
     */
    public const CUANTOS = 3;

    /**
     * Minúsculas, sin tildes, sin puntuación y sin espacios de sobra.
     *
     * El `strtr` es a mano y **no `iconv('ASCII//TRANSLIT')`** por lo mismo que en
     * {@see CatalogoDelMen::normalizar}: el resultado de `iconv` depende del locale
     * del servidor y los dieciséis colegios no corren en el mismo. Un emparejador
     * que casa en desarrollo y no en producción es peor que uno que no casa nunca.
     *
     * La coma de `APELLIDOS, Nombres` desaparece aquí, y eso es justamente lo que
     * hace que el orden deje de importar: la planilla imprime el apellido delante
     * y el docente escribe el nombre delante.
     */
    public static function normalizar(?string $texto): string
    {
        $texto = mb_strtolower((string) $texto, 'UTF-8');

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto) ?? '';

        return trim(preg_replace('/\s+/', ' ', $texto) ?? '');
    }

    /**
     * Las palabras de un nombre ya normalizado.
     *
     * @return list<string>
     */
    public static function palabras(?string $texto): array
    {
        $normal = self::normalizar($texto);

        return $normal === '' ? [] : explode(' ', $normal);
    }

    /**
     * Cuánto se parecen dos nombres, de 0 a 1.
     *
     * El reparto —**35 % la cadena entera y 65 % el conjunto de palabras**— no es
     * un gusto: la cadena entera se hunde en cuanto cambia el orden, y el orden
     * cambiado es **el caso normal** de esta pantalla, porque la planilla imprime
     * `APELLIDOS, Nombres` y la gente escribe `Nombres Apellidos`. Dejarle más peso
     * a la cadena entera sería puntuar el formato de la hoja en vez de la persona.
     */
    public static function entre(?string $uno, ?string $otro): float
    {
        $a = self::normalizar($uno);
        $b = self::normalizar($otro);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        return round(0.35 * self::deLaCadena($a, $b) + 0.65 * self::delConjunto($a, $b), 4);
    }

    /**
     * Los mejores candidatos para un nombre escrito a mano.
     *
     * Devuelve **como mucho {@see CUANTOS}**, ordenados de más parecido a menos y
     * sólo los que pasan el umbral. Con empate manda la llave, para que dos
     * ensayos del mismo fichero enseñen lo mismo en el mismo orden: un candidato
     * que baila de sitio entre dos lecturas es un candidato en el que no se puede
     * confiar (`ORDER BY` que empata, doc 03).
     *
     * @param  array<array-key, string>  $entreEstos  llave => nombre
     * @return list<array{clave: array-key, parecido: float}>
     */
    public static function mejores(string $escrito, array $entreEstos,
        float $umbral = self::UMBRAL, int $cuantos = self::CUANTOS): array
    {
        $puntuados = [];

        foreach ($entreEstos as $clave => $nombre) {
            $parecido = self::entre($escrito, $nombre);

            if ($parecido >= $umbral) {
                $puntuados[] = ['clave' => $clave, 'parecido' => $parecido];
            }
        }

        usort($puntuados, static function (array $uno, array $otro) {
            return $otro['parecido'] <=> $uno['parecido']
                ?: ((string) $uno['clave'] <=> (string) $otro['clave']);
        });

        return array_slice($puntuados, 0, max(0, $cuantos));
    }

    /**
     * El parecido de las dos cadenas enteras, con `similar_text`.
     *
     * `similar_text` devuelve **caracteres en común**, no un porcentaje comparable:
     * su tercer parámetro divide por la media de las dos longitudes, que premia al
     * nombre corto. Se normaliza contra **la cadena más larga**, que es la medida
     * que no se deja engañar por «maria» dentro de «maria fernanda gomez restrepo».
     */
    private static function deLaCadena(string $a, string $b): float
    {
        $comunes = similar_text($a, $b);
        $largo = max(strlen($a), strlen($b));

        return $largo === 0 ? 0.0 : $comunes / $largo;
    }

    /**
     * El parecido de los dos conjuntos de palabras, **en las dos direcciones**.
     *
     * Cada palabra busca su mejor pareja en el otro nombre y se promedia; luego se
     * promedian las dos direcciones. Así:
     *
     * - «Jose Luis Cardenaz» → «CÁRDENAS PEÑA, José Luis»: las tres palabras del
     *   docente están (`cardenaz`≈`cardenas`), y de las cuatro de la ficha sobra
     *   `pena`. Sale alto **y no 1**, que es lo correcto: falta un apellido.
     * - «Maria» → «MARÍA FERNANDA GÓMEZ RESTREPO»: en una dirección es 1 y en la
     *   otra es 0,25, así que la media lo deja lejos del umbral. Una sola palabra
     *   no identifica a nadie en un grupo de cuarenta.
     */
    private static function delConjunto(string $a, string $b): float
    {
        $unas = explode(' ', $a);
        $otras = explode(' ', $b);

        return (self::cobertura($unas, $otras) + self::cobertura($otras, $unas)) / 2;
    }

    /**
     * Cuánto de `$estas` aparece en `$aquellas`: la media de la mejor pareja de
     * cada palabra.
     *
     * @param  list<string>  $estas
     * @param  list<string>  $aquellas
     */
    private static function cobertura(array $estas, array $aquellas): float
    {
        if ($estas === [] || $aquellas === []) {
            return 0.0;
        }

        $suma = 0.0;

        foreach ($estas as $palabra) {
            $mejor = 0.0;

            foreach ($aquellas as $candidata) {
                $mejor = max($mejor, self::deLaCadena($palabra, $candidata));

                if ($mejor === 1.0) {
                    break;
                }
            }

            $suma += $mejor;
        }

        return $suma / count($estas);
    }
}
