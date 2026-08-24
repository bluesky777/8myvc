#!/usr/bin/env python3
"""
Qué consultas leen `unidades` o `subunidades` sin decir de quién son.

    python3 tools/unidades-sin-alcance.py            # desde la raíz del repo
    python3 tools/unidades-sin-alcance.py --csv      # para juntar varias medidas
    python3 tools/unidades-sin-alcance.py --detalle  # con el SQL de cada sitio

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
    return fuera


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
    patron = re.compile(
        r'\b(from|join|,|into|update)\s+`?' + tabla + r'`?\s*(?:as\s+)?(?!\w*\s*\()(\w+)?',
        re.I | re.S)
    for m in patron.finditer(sql):
        verbo = m.group(1).lower()
        alias = m.group(2)
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
    if re.search(r'\b' + re.escape(alias) + r'\.alumno_id\s+is\s+(?:not\s+)?null', sql, re.I):
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


def main():
    csv_out = '--csv' in sys.argv
    detalle = '--detalle' in sys.argv

    if not os.path.isdir(RAIZ):
        # Un cero tiene la misma cara que un arreglo. Corrido desde otra
        # carpeta, `escrituras-en-las-notas.py` contestó «0 escriben en las
        # notas» en vez de «no existe la carpeta»; aquí se aborta.
        sys.exit(f'ERROR: no existe ./{RAIZ}/ — se corre desde la raíz del repo.')

    hallazgos = []
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
                            'sql': ' '.join(sql.split()),
                        })

    lecturas = [h for h in hallazgos if h['verbo'] in ('from', 'join', ',')]
    escrituras = [h for h in hallazgos if h['verbo'] in ('into', 'update')]

    if csv_out:
        w = csv.DictWriter(sys.stdout, fieldnames=[
            'fichero', 'linea', 'metodo', 'tabla', 'verbo', 'alias', 'estado',
            'via', 'elige', 'veredicto', 'servicio'])
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

    if escrituras:
        print(f'== escrituras (INSERT/UPDATE): {len(escrituras)} ==')
        for h in escrituras:
            print(f'   {h["fichero"]}:{h["linea"]}  {h["metodo"]}  {h["verbo"]} {h["tabla"]}')
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
