#!/usr/bin/env python3
"""
Qué consultas leen `unidades` o `subunidades` sin decir de quién son.

    python3 tools/unidades-sin-alcance.py            # desde la raíz del repo
    python3 tools/unidades-sin-alcance.py --csv      # para juntar varias medidas
    python3 tools/unidades-sin-alcance.py --detalle  # con el SQL de cada sitio
    python3 tools/unidades-sin-alcance.py --control  # su control positivo (lo corre la suite)

Es la fase 0 del [19-boletin-independiente.md](../docs/migracion/19-boletin-independiente.md),
y existe por una frase de ese plan que no es retórica:

    En cuanto una unidad pueda tener dueño, cada una de esas 74 consultas está
    o corregida o equivocada. No hay término medio y no hay aviso: la consulta
    sigue devolviendo filas.

`unidades.alumno_id` es NULL para las del grupo y lleva un id para las de un
alumno con boletín independiente. Una consulta que no lo mire **no falla**:
devuelve las del grupo *y* las de los independientes mezcladas —definitivas
infladas para los treinta— o, al revés, las del grupo cuando pedía las suyas
—boletín en blanco—. Las dos formas están medidas en la §9.2 del plan.

## Qué cuenta como «alcance», y por qué son dos preguntas y no una

**`unidades` tiene la columna.** Su alcance es que la consulta compare
`<alias>.alumno_id` con algo. El plan exige que la comparación sea `<=>` y no
`=` (§3): el igual null-safe empareja NULL con NULL, así que **una sola
condición resuelve las dos ramas**. Con `=` a secas la rama del alumno normal
devuelve cero filas y **todas las definitivas del colegio se van a 0** sin un
solo error en el log — por eso `=` se marca aparte y no como alcance.

**`subunidades` NO tiene la columna, y ahí está la trampa de este detector.**
Una subunidad no es de nadie: es de su unidad, y la unidad es la que tiene
dueño. Así que preguntarle a una consulta de `subunidades` por `alumno_id` es
preguntarle por una columna que no existe, y contestaría «sin alcance» a las 70
para siempre. La pregunta correcta es **por dónde llega**:

  - por `unidad_id = <parámetro>`  -> **segura por construcción**: el id ya es
    de su dueño, quien lo tenga puede leerla y esta consulta no elige nada;
  - por un `join` con `unidades`   -> **hereda**: vale lo que valga el alcance
    de esa `unidades`, y por eso se informa junto;
  - por ninguna de las dos         -> **hay que mirarla**: llega por asignatura,
    por periodo o por notas, y ésas son las que barren el grupo entero.

## Lo que este detector NO contesta, dicho antes de que alguien lo lea al revés

Da **sitios donde mirar, nunca una lista de fallos** (CLAUDE.md). Una consulta
sin alcance puede estar bien: si de verdad quiere las del grupo —la pantalla de
estructura del docente, por ejemplo— lo correcto es `u.alumno_id IS NULL`, que
sí es alcance, pero si quiere *todas* a propósito, no lo es y está bien igual.
Eso lo decide quien lee, con el inventario clasificado al lado
(`docs/migracion/noche-2026-08-24/bi-1.md`).

Y la mitad que muerde de la regla del CLAUDE.md: **un detector puede contar bien
un síntoma y no estar contando la causa**. Aquí el síntoma es «no nombra
alumno_id» y la causa es «devuelve las filas de otro». No son lo mismo, y las
dos columnas de arriba existen para que se vea cuál se está contando.

## Y por qué imprime siempre su población

Un `0 sin alcance` significa «las 74 están bien» o «no encontré ninguna
consulta», y de las dos lecturas la falsa es la que hace archivar el asunto.
Por eso la primera línea de la salida es cuántas encontró, no cuántas fallan, y
por eso **aborta si la carpeta no existe** en vez de contestar cero.
"""
import re, os, sys, csv

RAIZ = 'app'

# ─────────────────────────────────────────────────────────────────────────────
# Las dos tablas, y el hecho asimétrico que ordena todo el fichero: sólo una de
# las dos lleva la columna del dueño.
CON_COLUMNA = 'unidades'
HEREDADA = 'subunidades'


def sin_comentarios(texto):
    """Quita //, # y /* */ dejando los saltos de línea para no mover las líneas.

    Sin esto cuenta el `FROM unidades` de un docblock, y con muy buena pinta:
    el comentario que explica una consulta se escribe justo encima de ella.
    Es la trampa 1 de `escrituras-en-las-notas.py`, vivida allí primero.
    """
    def blanquear(m):
        return re.sub(r'[^\n]', ' ', m.group(0))

    return re.sub(r'/\*.*?\*/|//[^\n]*|(?<!\$)#[^\n]*', blanquear, texto, flags=re.S)


def literales(texto):
    """(inicio, fin, contenido) de cada cadena PHP: '…', "…" y <<<'…'.

    El SQL de este repo vive entero dentro de una cadena —990 consultas crudas,
    ni un query builder— y **una consulta puede ocupar quince líneas**. Recortar
    por línea, que es lo primero que se prueba, parte el `FROM` de su `where` y
    entonces toda consulta con el alcance en otra línea sale «sin alcance».
    Por eso la unidad de medida es la cadena y no la línea.

    ## Y las cadenas CONCATENADAS se funden en una, desde el 31 ago 2026

    Lo levantó el lote A de la noche del boletín independiente, y era un fallo de
    los caros: **el detector no veía su propio arreglo**. La forma que manda el plan
    para acotar se escribe concatenando una constante,

        ."\n where a.deleted_at is null and u.alumno_id <=> ".BoletinIndependiente::ALCANCE."

    y eso parte la consulta en **tres** cadenas. El `from unidades u` cae en la
    primera y el `<=>` en la segunda, así que la primera contestaba «sin alcance»
    **para siempre**, por bien hecho que estuviera. Las cuatro lecturas de
    `NotasPerdidasController` llevaban acotadas desde `58b5714` y este fichero
    seguía contándolas como pendientes; el reparto de la noche las mandó arreglar
    otra vez.

    **Es la regla del CLAUDE.md en su forma más cara**: el primer sitio donde mirar
    cuando el número sale raro es el detector — y aquí el número no salía raro, salía
    *plausible*, que es peor. Un criterio de aceptación medido con esto («0 sin
    alcance») era **inalcanzable por construcción** en cuanto alguien acotara algo.

    Se funden dos cadenas contiguas cuando lo único que hay entre ellas es una
    concatenación (`.` … `.`). Se unen con un espacio y no pegadas, para no crear
    tokens que no existen. Es deliberadamente conservador: `foo('x')` en medio **no**
    funde, porque el hueco no acaba en `.` — y quedarse corto sólo devuelve el
    comportamiento viejo, mientras que pasarse inventaría alcances que no están.
    """
    fuera = []
    i, n = 0, len(texto)
    while i < n:
        c = texto[i]
        if c in ("'", '"'):
            j = i + 1
            while j < n:
                if texto[j] == '\\':
                    j += 2
                    continue
                if texto[j] == c:
                    break
                j += 1
            fuera.append((i, j, texto[i + 1:j]))
            i = j + 1
        elif texto.startswith('<<<', i):
            m = re.match(r"<<<\s*'?\"?(\w+)'?\"?\r?\n", texto[i:])
            if m:
                etiqueta = m.group(1)
                cuerpo = i + m.end()
                fin = re.search(r'\n\s*' + etiqueta + r'\b', texto[cuerpo:])
                j = cuerpo + fin.start() if fin else n
                fuera.append((i, j, texto[cuerpo:j]))
                i = j + 1
            else:
                i += 1
        else:
            i += 1

    return _funde_concatenadas(texto, fuera)


def _funde_concatenadas(texto, trozos):
    """Une las cadenas que el PHP concatena, para medir sobre la sentencia.

    Ver la cabecera de `literales()`: sin esto, el alcance escrito como
    `'… <=> '.Servicio::ALCANCE.' …'` cae en una cadena distinta de la que lleva el
    `from unidades u`, y la consulta sale «sin alcance» estando bien.
    """
    if not trozos:
        return trozos

    unidos = [list(trozos[0])]

    for ini, fin, sql in trozos[1:]:
        hueco = texto[unidos[-1][1] + 1:ini].strip()

        # Sólo une si entre las dos cadenas no hay más que una concatenación. El `;`
        # se descarta aparte porque separa dos sentencias y unirlas mezclaría el
        # `from` de una con el `where` de la otra — exactamente el fallo que la
        # cabecera dice que se evita no midiendo por líneas.
        if hueco.startswith('.') and hueco.endswith('.') and ';' not in hueco:
            unidos[-1][1] = fin
            unidos[-1][2] = unidos[-1][2] + ' ' + sql
        else:
            unidos.append([ini, fin, sql])

    return [tuple(t) for t in unidos]


def metodos(texto):
    """(nombre, inicio, fin) de cada método, cortando en el siguiente `function`.

    `static` en medio y `[ \\t]*` en vez de `\\t*`: los dos son arreglos que ya se
    pagaron en `escrituras-en-las-notas.py`. El segundo importa aquí igual:
    pint reformatea fichero a fichero según se van tocando (CLAUDE.md), así que
    un recorte que sólo entienda tabuladores se va quedando ciego **sobre los
    ficheros recién tocados**, que son los que más falta hace mirar.
    """
    ms = list(re.finditer(
        r'\n[ \t]*(?:public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*\(', texto))
    for i, m in enumerate(ms):
        fin = ms[i + 1].start() if i + 1 < len(ms) else len(texto)
        yield m.group(1), m.start(), fin


def referencias(sql, tabla):
    """Cada sitio donde el SQL nombra la tabla, con su alias y su verbo.

    El `,` está a propósito: en SQL de 2006 los joins se escriben
    `FROM notas n, unidades u`, y una lista de tablas separada por comas es un
    join con todas las letras. Un detector que sólo mire `JOIN` no ve ésos.
    """
    # El `(?![\w])` DETRÁS del nombre no es defensivo, es un fallo pagado: sin él,
    # `unidades` casa con los ocho primeros caracteres de **`unidades_por_defecto`**
    # —otra tabla, que no lleva `alumno_id` y nunca lo llevará— y `_por_defecto`
    # se cuenta como si fuera el alias. Eran **3 referencias de 146**, y las tres
    # habrían salido en el inventario como trabajo que hacer sobre una tabla que
    # no cambia. Hay cuatro tablas cuyo nombre empieza igual: `unidades`,
    # `unidades_por_defecto`, `subunidades` y `subunidades_por_defecto`.
    # Y el `(?!\w*\s*\()` —el guardia contra las llamadas a función— **no puede
    # aplicarse al `INSERT INTO`**, que es el segundo fallo pagado de esta misma
    # línea. `INSERT INTO unidades(definicion, porcentaje, …)` lleva el paréntesis
    # pegado al nombre, así que el guardia rechazaba la referencia y el detector
    # decía **4 escrituras cuando son 6**: se le escapaban los dos `INSERT` de
    # `UnidadesController::getDeAsignaturaPeriodo`. Esa lista de columnas es
    # justo lo que distingue un `INSERT` de una llamada, o sea que el guardia
    # rechazaba por la señal que confirma.
    #
    # Lo destapó `8myvc-ad` midiendo otra cosa: ese endpoint **escribe** aunque
    # no lo diga ni el verbo (`PUT`) ni el nombre (`getDeAsignaturaPeriodo`).
    patron = re.compile(
        r'\b(?:(into)\s+`?' + tabla + r'`?(?![\w])\s*(\w+)?'
        r'|(from|join|,|update)\s+`?' + tabla + r'`?(?![\w])\s*(?:as\s+)?'
        r'(?!\w*\s*\()(\w+)?)',
        re.I | re.S)
    for m in patron.finditer(sql):
        # Dos ramas: la del `INSERT INTO` (grupos 1-2) y la de las demás (3-4).
        verbo = (m.group(1) or m.group(3)).lower()
        alias = m.group(2) if m.group(1) else m.group(4)
        # `where`, `on`, `set`… no son alias: es que la tabla iba sin alias.
        if alias and alias.lower() in (
                'where', 'on', 'set', 'group', 'order', 'left', 'right', 'inner',
                'join', 'having', 'limit', 'union', 'values', 'as', 'and', 'or'):
            alias = None
        yield verbo, (alias or tabla), m.start()


def alcance_de_unidades(sql, alias):
    """('si'|'con-igual'|'no'), mirando cómo compara la consulta el dueño.

    `<=>` es alcance; `=` a secas se separa **porque es el fallo caro**: parece
    hecho y devuelve cero filas para todo alumno normal.
    """
    ref = r'(?:\b' + re.escape(alias) + r'\.)?alumno_id'
    if re.search(ref + r'\s*<=>', sql, re.I):
        return 'si'
    # `ref` y no `\b<alias>\.`, igual que la rama de arriba: una consulta de UNA
    # tabla escribe `alumno_id IS NULL` sin alias, y ésa es justo la forma que la
    # §1.6 del reparto bendice para «las del grupo a propósito». Exigir el prefijo
    # sacaba consultas YA acotadas en la lista de «hay que acotarla» — la cuarta
    # ceguera de este detector, diagnosticada por el lote B el 1 sep 2026.
    if re.search(ref + r'\s+is\s+(?:not\s+)?null', sql, re.I):
        return 'si'
    if re.search(r'\b' + re.escape(alias) + r'\.alumno_id\s*=', sql, re.I):
        return 'con-igual'
    return 'no'


def via_de_subunidades(sql, alias):
    """Por dónde llega la consulta a la subunidad. Ver la cabecera."""
    if re.search(r'\b' + re.escape(alias) + r'\.unidad_id\s*(?:=|in)\s*[:?]', sql, re.I):
        return 'por-id'
    for _verbo, ali, _pos in referencias(sql, CON_COLUMNA):
        return 'hereda:' + ali
    return 'mirar'



# ─────────────────────────────────────────────────────────────────────────────
# La clasificación, que es lo que convierte «75 sitios» en trabajo repartible.
#
# La pregunta no es «¿nombra alumno_id?» —ninguna lo hace hoy, la columna no
# existe— sino **cómo elige el conjunto de filas de `unidades`**, porque ahí es
# donde se decide si mañana caben dentro las de otro:
#
#   por-id            la unidad viene nombrada (`u.id = ?`, `s.unidad_id = ?`,
#                     `n.id = ?`). Es UNA fila y ya es de su dueño: quien tenga
#                     el id puede leerla. **Queda bien por construcción** — no
#                     hay conjunto que acotar.
#   por-alumno        el conjunto sale por (asignatura, periodo) PERO la consulta
#                     nombra a un alumno concreto. **Hay que acotarla**, y con
#                     el `<=>` de la §3: es la rama del boletín y de la
#                     definitiva de uno.
#   por-asignatura    el conjunto sale por (asignatura, periodo) y NO nombra
#                     alumno: es la planilla, la estructura, el recálculo del
#                     grupo entero. **Hay que acotarla**, y aquí es donde se
#                     decide de qué lado — normalmente `u.alumno_id IS NULL`
#                     para la estructura y el `<=>` por fila para lo calculado.
#   mas-ancho         ni id, ni asignatura, ni periodo: barre por año, por
#                     grupo o por la papelera. **No se sabe sin leerla**, y son
#                     las que más se tarda en clasificar.
#
# El orden de las preguntas NO es indiferente y es el error que estuvo a punto
# de colarse aquí: `DefinitivasDeAsignatura::calcular` **une `notas`**, así que
# una comprobación que preguntara «¿llega desde una nota?» primero la habría
# dado por segura. No lo es: elige las unidades por (asignatura, periodo) y
# agrupa por alumno, así que a un independiente le sumaría **las suyas y las del
# grupo**. Es exactamente el «de más» de la §9.2. Por eso se pregunta primero
# si hay una fila **nombrada**, y sólo esa cierra el caso.
POR_ALUMNO = re.compile(r'\balumno_id\s*=\s*[:?]\w*', re.I)


def _pinchada(sql, alias):
    """¿Fija esta consulta UNA fila de `alias` por su id, o por su madre?"""
    a = re.escape(alias)
    return bool(
        re.search(r'\b' + a + r'\.id\s*=\s*[:?]', sql, re.I)
        or re.search(r'\b' + a + r'\.unidad_id\s*=\s*[:?]', sql, re.I))


def _sin_alias(sql, columnas):
    """`WHERE asignatura_id = ?` a pelo, sin alias delante. Existe la mitad de
    las veces en este repo y sin esto se clasifica como «no se sabe»."""
    return bool(re.search(
        r'(?<![\w.])(?:' + '|'.join(columnas) + r')\s*=\s*[:?]', sql, re.I))


def _notas_estrechan(sql):
    """¿Las `notas` de UN alumno acotan de verdad el conjunto de unidades?

    **Sólo si se llega a ellas por un `INNER JOIN` (o van en el `FROM`).** Es la
    distinción que decide la mitad de esta clasificación y la que estuvo a punto
    de colarse al revés dos veces esta noche:

        inner join notas n on ... and n.alumno_id = :alumno_id
            -> la unidad que sobrevive es la que sostiene una nota SUYA. Si es
               independiente, la suya; si no, la del grupo. **Acotado por el
               dato**, sin que nadie escribiera un alcance.

        left  join notas n on ... and alumno_id = :alumno_id
            -> **no acota nada**. Las unidades vienen del `FROM` y el `LEFT JOIN`
               sólo decide qué nota se les cuelga: sin nota, la unidad sale igual
               con `NULL`. Es exactamente `Unidad::deAsignaturaCalculada`, que
               nombra al alumno en la línea de al lado **y aun así hay que
               acotarla** — es la puerta de los tres boletines.

    Un detector que sólo mire «¿nombra al alumno?» da las dos por buenas, y la
    segunda es la que imprime el boletín.
    """
    if not POR_ALUMNO.search(sql) and not re.search(r'\bn\.id\s*=\s*[:?]', sql, re.I):
        return False
    if re.search(r'\b(?:from|,)\s*\bnotas\b', sql, re.I):
        return True
    return bool(re.search(r'\binner\s+join\s+notas\b', sql, re.I))


def como_elige(sql, alias, tabla):
    """Cómo elige ESTA consulta su conjunto de filas de `tabla`.

    La distinción que ordena todo, y que costó tres pasadas esta noche:

        una unidad se **ELIGE** cuando la consulta filtra por SUS propias
        `asignatura_id`/`periodo_id` —ahí hay un conjunto, y ahí es donde
        mañana caben dentro las de otro—;
        se **ALCANZA** cuando se llega a ella por `u.id = s.unidad_id` desde
        una subunidad, o desde una nota. Ahí no hay conjunto: hay una fila que
        ya es de su dueño.

    Las dos primeras pasadas de esta noche mezclaron las dos cosas y la
    clasificación bailó de 35 a 7 «por construcción» sin que cambiara una línea
    de `app/`. **Ninguno de los tres números era un hallazgo: eran tres
    definiciones.** Por eso ésta está escrita antes que el código.

    Y una comprobación **atada al alias**, que es lo que evita el fallo caro:
    sin atarla, el `nf.asignatura_id=:asi1` de una consulta de `notas_finales`
    cuenta como «elige las unidades por asignatura».
    """
    a = re.escape(alias)

    # 1. ¿Está fijada por su id, o por el id de su madre? Cierra el caso.
    if _pinchada(sql, alias) or (alias == tabla and _sin_alias(sql, ['id'])):
        return 'por-id'
    if tabla == HEREDADA and alias == tabla and _sin_alias(sql, ['unidad_id']):
        return 'por-id'

    # 2. ¿Se la ALCANZA desde una subunidad —`u.id = s.unidad_id`— en vez de
    #    elegirla? Entonces manda cómo esté fijada la subunidad, no la unidad.
    alcanzada = re.search(r'\b' + a + r'\.id\s*=\s*(\w+)\.unidad_id', sql, re.I) \
        or re.search(r'\b(\w+)\.unidad_id\s*=\s*' + a + r'\.id', sql, re.I)

    # 3. ¿La ELIGE por sus propias asignatura/periodo? Ése es el conjunto.
    #
    #    **Y vale igual contra un parámetro que contra la columna de otra
    #    tabla**, que es la corrección que más movió el número esta noche:
    #    `NotaFinal` no escribe `u.asignatura_id = :asignatura_id`, escribe
    #    `inner join unidades u on u.asignatura_id = asi.id` y fija la
    #    asignatura arriba. Es el mismo conjunto —todas las unidades de una
    #    asignatura— y el mismo riesgo, pero una comprobación que sólo mire
    #    `= :param` lo manda a «no se sabe». Eran **diez lecturas del sitio que
    #    calcula las definitivas**, o sea justo el «de más» de la §9.2.
    #    Y **`asignatura_id` y `periodo_id` no se tratan igual**, que es el
    #    ajuste que cerró el baile de números:
    #
    #      - `asignatura_id` ESTRECHA siempre. Contra un parámetro o contra la
    #        columna de otra tabla —`u.asignatura_id = asi.id`— da lo mismo: el
    #        conjunto acaba siendo «las unidades de esa asignatura».
    #      - `periodo_id` sólo estrecha contra un PARÁMETRO. El
    #        `inner join periodos p on p.id = u.periodo_id` que llevan casi
    #        todas **no elige nada**: sigue la clave hacia fuera para traerse el
    #        número del periodo. Tratarlo como estrechamiento se llevó por
    #        delante las 20 lecturas que llegan caminando desde una nota, y las
    #        dejó como trabajo pendiente cuando están bien por construcción.
    elegida = re.search(r'\b' + a + r'\.asignatura_id\s*=', sql, re.I) \
        or re.search(r'=\s*' + a + r'\.asignatura_id\b', sql, re.I) \
        or re.search(r'\b' + a + r'\.periodo_id\s*=\s*[:?]', sql, re.I) \
        or (alias == tabla and _sin_alias(sql, ['asignatura_id', 'periodo_id']))

    # 3.bis Un conjunto ya reducido a UN alumno no se puede ensanchar con más
    #       filtros: lo que venga después sólo quita filas suyas. Por eso esto
    #       va ANTES que `elegida` y no después.
    if _notas_estrechan(sql):
        return 'por-nota'

    if elegida:
        return 'por-alumno' if POR_ALUMNO.search(sql) else 'por-asignatura'

    if alcanzada:
        madre = alcanzada.group(1)
        if _pinchada(sql, madre) or re.search(r'\bn\.id\s*=\s*[:?]', sql, re.I):
            return 'por-id'

    if re.search(r'\bn\.id\s*=\s*[:?]', sql, re.I):
        return 'por-id'

    return 'mas-ancho'


VEREDICTO = {
    'por-id':         'bien por construccion',
    'por-nota':       'bien por construccion',
    'por-alumno':     'hay que acotarla',
    'por-asignatura': 'hay que acotarla',
    'mas-ancho':      'no se sabe',
}
CLASES = ('por-id', 'por-nota', 'por-alumno', 'por-asignatura', 'mas-ancho')


# ─────────────────────────────────────────────────────────────────────────────
# La tercera forma de fallar, que el plan no nombra y es la que aparece primero.
#
# La §9.2 del 19 dice que una consulta sin alcance «no falla: devuelve las filas
# de otro», y describe dos formas —de más y de menos—. **Hay una tercera, y se ve
# antes que las otras dos: la consulta revienta con un 500.**
#
#     SQLSTATE[23000]: 1052 Column 'alumno_id' in on clause is ambiguous
#
# Pasa cuando el SQL nombra `alumno_id` **sin alias delante** dentro de una
# consulta que une `unidades`. Hasta hoy no había ambigüedad —`notas` era la
# única tabla del join con esa columna— así que escribirlo desnudo funcionaba.
# En cuanto `unidades` tiene la suya, MySQL no puede elegir y aborta.
#
# Lo encontró la suite el 24 ago 2026, en `BoletinNoBorraDefinitivasTest`, con la
# migración puesta y **nadie marcado**: cuatro predicados en `Unidad::
# deAsignaturaCalculada` y `Subunidad::perdidasDeAsignatura`.
#
# **Y de las tres formas de fallar, ésta es la buena**: es ruidosa, sale en el
# primer test y no imprime un boletín equivocado. Lo que la hace peligrosa es
# **cuándo** aparece: la rompe el `ALTER TABLE`, no el código. Un colegio donde
# la migración corra antes de que llegue el `app/` nuevo —y `app/` es copia por
# colegio— tiene los boletines en 500 en esa ventana. Va en la §10 del plan.
def desnudas(sql):
    """Cada `alumno_id` sin alias que se COMPARA (no el `as alumno_id` de un SELECT)."""
    if not re.search(r'\bunidades\b', sql, re.I):
        return []
    return [m.group(0) for m in re.finditer(
        r'(?<![\w.:])alumno_id\s*(?:=|<=>|<|>|\bin\b)|(?:=|<=>)\s*(?<![\w.])alumno_id\b',
        sql, re.I)]


# ─────────────────────────────────────────────────────────────────────────────
# Y la mitad que el SQL crudo NO ve: Eloquent.
#
# `CLAUDE.md` dice que este repo tiene 990 consultas crudas y usa los modelos
# «marginalmente», **y esa frase es justo la que hace que se te olvide el
# margen**. `escrituras-en-las-notas.py` lo lleva escrito en su cabecera porque
# le pasó a él primero; a este detector le pasó igual el 24 ago 2026: publicó
# **6 escrituras** —las de SQL crudo— cuando hay **12 métodos más** que escriben
# `Unidad`/`Subunidad` con `new … ->save()`, `->delete()` y `->forceDelete()`.
#
# **Y las tres que más importan para el boletín independiente están en esta
# mitad**, no en la otra:
#
#   UnidadesController::postIndex        `POST unidades` — es donde tiene que
#                                        entrar `alumno_id` (§6.5 del plan)
#   SubunidadesController::postIndex     tiene que crear las notas de UN alumno
#                                        y no las del grupo cuando la unidad
#                                        tiene dueño (§6.5)
#   PeriodosController::putCopiar        tiene que copiar también las unidades
#                                        con dueño (§9.4), o el periodo nuevo
#                                        empieza con los independientes sin nada
#
# Un inventario que sólo mirara el SQL crudo diría que el camino de escritura no
# se toca, y **las tres piezas centrales de la función viven ahí**.
#
# La búsqueda es por método y no por línea: lo que importa es «este método
# escribe el modelo», y el `new` y el `->save()` suelen estar a diez líneas.
# `(Unidad|Subunidad)::` va sin cerrar a `find` a propósito —
# `Unidad::onlyTrashed()->findOrFail($id)` se le escapaba a la versión estrecha,
# y eran justo los dos `forcedelete`.
ELOQUENT_MODELO = re.compile(r'\bnew\s+(Unidad|Subunidad)\b|\b(Unidad|Subunidad)::')
ELOQUENT_ESCRIBE = re.compile(r'->(save|delete|forceDelete|restore|update|push)\s*\(')


def escrituras_eloquent(texto):
    """(metodo, modelos, operaciones) de cada método que escribe por Eloquent."""
    for nombre, ini, fin in metodos(texto):
        cuerpo = texto[ini:fin]
        modelos = {m.group(1) or m.group(2) for m in ELOQUENT_MODELO.finditer(cuerpo)}
        if not modelos:
            continue
        ops = sorted({m.group(1) for m in ELOQUENT_ESCRIBE.finditer(cuerpo)})
        if ops:
            yield nombre, texto.count('\n', 0, ini) + 1, sorted(modelos), ops


# ─────────────────────────────────────────────────────────────────────────────
# El control positivo, ejecutable — y por qué llega tarde.
#
# Este detector ya se había equivocado **cuatro veces**, las cuatro contando de
# más, y las cuatro las encontró una persona con el fichero delante y no él:
#
#   1ª  no fundía las cadenas concatenadas -> no veía su propio arreglo;
#   2ª  contaba el `FROM unidades` de los comentarios;
#   3ª  medía por línea y partía las consultas de quince;
#   4ª  exigía `<alias>.` para `IS NULL` -> daba «hay que acotarla» a CUATRO
#       consultas de una sola tabla que ya estaban acotadas, y justo con la
#       forma que la §1.6 del reparto bendice (1 sep 2026).
#
# **Y las cuatro veces el número salió plausible, que es peor que raro.** Un
# detector cuyo error es contar de más nunca se delata solo: su lista de trabajo
# simplemente tiene sitios donde no hay nada, y quien los revisa cierra cada uno
# «decidiendo no tocarlo», que aquí es una salida legítima (§1.5).
#
# Por eso el control **no** se ancla en un número del árbol —que se mueve cada
# noche y obliga a reescribirlo, hasta que alguien lo reescribe con el número
# equivocado—, sino en las **formas**: seis consultas mínimas cuyo veredicto
# está decidido, con la de la cuarta ceguera dentro. Si una cambia, el detector
# cambió de opinión sobre algo que no era opinable.
#
#     python3 tools/unidades-sin-alcance.py --control
#
# Lo corre `tests/Unit/AutopruebasDeLasHerramientasTest`, que es lo que lo
# convierte de intención en control: hasta el 1 sep 2026 esta herramienta era la
# única del boletín independiente **sin nada que la comprobara**, mientras su
# número gobernaba el reparto de una noche entera de cinco sesiones.
CASOS_DE_CONTROL = [
    # (qué demuestra, sql, alias, veredicto esperado)
    ('el <=> con alias es alcance',
     'select u.id from unidades u join notas n on n.unidad_id=u.id where u.alumno_id <=> 7',
     'u', 'si'),
    ('el <=> SIN alias tambien lo es (una sola tabla)',
     'select id from unidades where asignatura_id=? and alumno_id <=> ?',
     'unidades', 'si'),
    # LA CUARTA CEGUERA. Antes del 1 sep 2026 esta línea contestaba 'no', y con
    # ella salían como pendientes `PeriodosController::putCopiar`,
    # `Unidad::informacionAsignatura`, `ChangeAskedController::asignaturas_dia` y
    # `BoletinIndependienteController::putPlanilla` — las cuatro acotadas y las
    # cuatro con el porqué escrito encima.
    ('IS NULL SIN alias es alcance: es la forma que manda la §1.6 para «las del grupo a proposito»',
     'select id, definicion from unidades where asignatura_id=:a and periodo_id=:p '
     'and deleted_at is null and alumno_id is null order by orden',
     'unidades', 'si'),
    ('IS NULL con alias sigue siendo alcance',
     'select u.id from unidades u, matriculas m where u.alumno_id is null',
     'u', 'si'),
    # No es un aprobado: es el fallo caro de la §3 —con `=` el alumno normal no
    # empareja NULL y su definitiva sale 0—, y se informa aparte para que se lea.
    ('el = a secas se separa y NO cuenta como alcance',
     'select u.id from unidades u where u.alumno_id = :alumno_id',
     'u', 'con-igual'),
    ('sin nombrar al dueño no hay alcance',
     'select u.id from unidades u where u.asignatura_id=? and u.periodo_id=?',
     'u', 'no'),
]


def control():
    """Ejercita `alcance_de_unidades` contra las seis formas decididas.

    Tres salidas y no dos, como manda el runner: 0 pasa, 1 el detector cambió de
    opinión, 2 no se pudo ejercer aquí. Este control **no mira el árbol**, así que
    el 2 no debería aparecer nunca — y si aparece, es que le falta una pieza al
    propio fichero, no que el repo esté en otro estado.
    """
    fallos = []
    for que, sql, alias, esperado in CASOS_DE_CONTROL:
        try:
            salio = alcance_de_unidades(sql, alias)
        except Exception as e:  # noqa: BLE001 — el control informa, no revienta
            print(f'CONTROL NO CONCLUYENTE: alcance_de_unidades() reventó ({e}).')
            return 2
        marca = 'ok  ' if salio == esperado else 'FALLA'
        print(f'  {marca} [{esperado:>9}] {que}')
        if salio != esperado:
            fallos.append(f'    esperaba {esperado!r} y salió {salio!r}: {sql}')

    print(f'Población del control: {len(CASOS_DE_CONTROL)} formas comprobadas, '
          f'{len(fallos)} fallan.')
    if fallos:
        print('\n'.join(fallos))
        print('CONTROL FALLA: el detector cambió de opinión sobre una forma decidida. '
              'Su lista de «hay que acotarla» NO vale hasta arreglar esto.')
        return 1
    print('OK — las seis formas se clasifican como está decidido.')
    return 0


def main():
    if '--control' in sys.argv:
        sys.exit(control())

    csv_out = '--csv' in sys.argv
    detalle = '--detalle' in sys.argv

    if not os.path.isdir(RAIZ):
        # Un cero tiene la misma cara que un arreglo. Corrido desde otra
        # carpeta, `escrituras-en-las-notas.py` contestó «0 escriben en las
        # notas» en vez de «no existe la carpeta»; aquí se aborta.
        sys.exit(f'ERROR: no existe ./{RAIZ}/ — se corre desde la raíz del repo.')

    hallazgos = []
    eloquent = []
    ficheros_vistos = 0

    for base, _dirs, ficheros in os.walk(RAIZ):
        for f in sorted(ficheros):
            if not f.endswith('.php'):
                continue
            ruta = os.path.join(base, f)
            ficheros_vistos += 1
            texto = sin_comentarios(open(ruta, encoding='utf-8', errors='replace').read())
            ms = list(metodos(texto))
            usa_servicio = 'BoletinIndependiente' in texto

            for nombre, linea_e, modelos, ops in escrituras_eloquent(texto):
                eloquent.append((ruta, linea_e, nombre, '/'.join(modelos), ','.join(ops)))

            for ini, fin, sql in literales(texto):
                if not re.search(r'\b(sub)?unidades\b', sql, re.I):
                    continue
                linea = texto.count('\n', 0, ini) + 1
                metodo = next((n for n, a, b in ms if a <= ini < b), '(fuera de método)')

                for tabla in (CON_COLUMNA, HEREDADA):
                    for verbo, alias, _pos in referencias(sql, tabla):
                        if tabla == CON_COLUMNA:
                            estado = alcance_de_unidades(sql, alias)
                            via = ''
                        else:
                            via = via_de_subunidades(sql, alias)
                            if via == 'por-id':
                                estado = 'si'
                            elif via.startswith('hereda:'):
                                estado = alcance_de_unidades(sql, via.split(':', 1)[1])
                            else:
                                estado = 'no'
                        elige = como_elige(sql, alias, tabla)
                        # Una subunidad no es de nadie: es de su unidad. Si la
                        # consulta llega a ella por un `join` con `unidades`,
                        # **vale exactamente lo que valga esa unidad** — y decirlo
                        # así, en vez de dejarla en «no se sabe», es lo que hace
                        # que las dos columnas sumen el mismo trabajo y no dos.
                        if tabla == HEREDADA and via.startswith('hereda:'):
                            elige = como_elige(sql, via.split(':', 1)[1], CON_COLUMNA)
                        hallazgos.append({
                            'fichero': ruta, 'linea': linea, 'metodo': metodo,
                            'tabla': tabla, 'verbo': verbo, 'alias': alias,
                            'estado': estado, 'via': via, 'elige': elige,
                            'veredicto': VEREDICTO[elige],
                            'servicio': 'si' if usa_servicio else 'no',
                            'desnudas': len(desnudas(sql)),
                            'sql': ' '.join(sql.split()),
                        })

    lecturas = [h for h in hallazgos if h['verbo'] in ('from', 'join', ',')]
    ambiguas = [h for h in hallazgos if h['desnudas']]
    escrituras = [h for h in hallazgos if h['verbo'] in ('into', 'update')]

    if csv_out:
        w = csv.DictWriter(sys.stdout, fieldnames=[
            'fichero', 'linea', 'metodo', 'tabla', 'verbo', 'alias', 'estado',
            'via', 'elige', 'veredicto', 'servicio', 'desnudas'])
        w.writeheader()
        for h in hallazgos:
            w.writerow({k: v for k, v in h.items() if k != 'sql'})
        return

    print(f'Población: {ficheros_vistos} ficheros .php recorridos bajo ./{RAIZ}/, '
          f'{len(set(h["fichero"] for h in hallazgos))} nombran una de las dos tablas.')
    print()

    for tabla in (CON_COLUMNA, HEREDADA):
        propias = [h for h in lecturas if h['tabla'] == tabla]
        sin = [h for h in propias if h['estado'] == 'no']
        igual = [h for h in propias if h['estado'] == 'con-igual']
        print(f'== {tabla}: {len(propias)} lecturas en '
              f'{len(set(h["fichero"] for h in propias))} ficheros ==')
        print(f'   sin alcance: {len(sin)}   con alcance: '
              f'{len(propias) - len(sin) - len(igual)}   con `=` en vez de `<=>`: {len(igual)}')
        if tabla == HEREDADA:
            for v in ('por-id', 'mirar'):
                print(f'     de ésas, «{v}»: {len([h for h in propias if h["via"] == v])}')
        print()

    # Las dos mitades juntas y etiquetadas, para que nadie lea una por el total.
    print(f'== escrituras: {len(escrituras)} en SQL crudo + {len(eloquent)} por Eloquent '
          f'= {len(escrituras) + len(eloquent)} ==')
    for h in escrituras:
        print(f'   [sql]      {h["fichero"]}:{h["linea"]}  {h["metodo"]}  '
              f'{h["verbo"]} {h["tabla"]}')
    for ruta, linea_e, nombre, modelos, ops in sorted(eloquent):
        print(f'   [eloquent] {ruta}:{linea_e}  {nombre}  {modelos} ->{ops}')
    print('   Las tres que decide el plan están en la mitad de Eloquent: '
          'UnidadesController::postIndex (alumno_id en el cuerpo, §6.5),')
    print('   SubunidadesController::postIndex (notas de UN alumno, §6.5) y '
          'PeriodosController::putCopiar (§9.4).')
    print()

    vistas = {(h['fichero'], h['linea']) for h in ambiguas}
    if vistas:
        print(f'*** {len(vistas)} consultas comparan `alumno_id` SIN ALIAS uniendo '
              '`unidades`: son un 500 (1052 ambiguous), no una fila de más. ***')
        for f, l in sorted(vistas):
            print(f'      {f}:{l}')
        print()
    else:
        print('Ninguna consulta compara `alumno_id` sin alias uniendo `unidades` '
              '(la tercera forma de fallar; ver la cabecera).')
        print()

    print('== Cómo elige cada lectura su conjunto de filas ==')
    print('   (la clasificación de `docs/migracion/noche-2026-08-24/bi-1.md`)')
    for tabla in (CON_COLUMNA, HEREDADA):
        propias = [h for h in lecturas if h['tabla'] == tabla]
        print(f'   {tabla}: {len(propias)}')
        for clave in CLASES:
            hs = [h for h in propias if h['elige'] == clave]
            print(f'      {clave:<16} {len(hs):>3}   {VEREDICTO[clave]}')
    print()

    print('Por fichero — cuántas de cada clase:')
    porf = {}
    for h in lecturas:
        porf.setdefault(h['fichero'], []).append(h)
    for ruta in sorted(porf):
        hs = porf[ruta]
        cuenta = {c: len([h for h in hs if h['elige'] == c]) for c in CLASES}
        print(f'   {len(hs):>3}  id={cuenta["por-id"]:<3} nota={cuenta["por-nota"]:<3} '
              f'alu={cuenta["por-alumno"]:<3} asi={cuenta["por-asignatura"]:<3} '
              f'ancho={cuenta["mas-ancho"]:<3}  {ruta}')
    print()

    print('Sitios donde mirar — NO es una lista de fallos:')
    por_fichero = {}
    for h in lecturas:
        if h['estado'] != 'si':
            por_fichero.setdefault(h['fichero'], []).append(h)
    for ruta in sorted(por_fichero):
        hs = por_fichero[ruta]
        print(f'\n  {ruta}  ({len(hs)})')
        for h in hs:
            marca = '=!' if h['estado'] == 'con-igual' else '  '
            print(f'   {marca} :{h["linea"]:<5} {h["metodo"]:<34} '
                  f'{h["tabla"]:<12} alias={h["alias"]:<6} '
                  f'{h["elige"]:<15} {h["via"]}')
            if detalle:
                print(f'        {h["sql"][:400]}')

    if igual := [h for h in lecturas if h['estado'] == 'con-igual']:
        print(f'\n*** {len(igual)} usan `alumno_id =` y no `<=>`. Ver la §3 del plan: '
              'con `=` el alumno normal no empareja NULL y su definitiva sale 0.')


if __name__ == '__main__':
    main()
