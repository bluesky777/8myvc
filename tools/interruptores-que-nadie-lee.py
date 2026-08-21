#!/usr/bin/env python3
"""
Qué interruptores del esquema no decide nada nadie, aunque se guarden y se sirvan.

Contesta la pregunta que más ha encontrado en este repo y que no se puede
contestar leyendo el código, porque lo que hay que ver es una AUSENCIA: una
columna `tinyint(1)` que el colegio enciende desde una pantalla y que ningún
`if` ni ningún `WHERE` mira nunca. La casilla se marca, se guarda, se devuelve
en el JSON — y no pasa nada.

Ya ha salido cuatro veces en un solo día, cada vez por un camino distinto:

  · `years.actual` encendido en un año de la papelera, que nadie filtraba
  · `(inhabilitado)` escrito en el `username` de seis superusuarios activos
  · `vt_votaciones.in_action` y `locked`, que nadie leía al votar
  · `ws_actividades.in_action` e `inicia_at`, que nadie leía al abrir el examen

Las cuatro son la misma forma: **el colegio expresó una intención por un camino
que el código no mira.** Este script busca el subconjunto de esa forma que sí es
mecánico — las columnas booleanas — y deja el resto para el ojo.

Tres montones:

  A. NI SE NOMBRAN        el nombre no aparece en app/, routes/, config/ ni
                          database/. Si llegan al cliente es por un `SELECT *`.
  B. NO DECIDEN NADA      aparecen, pero solo en listas de `SELECT`/`INSERT`/`SET`
                          y nunca en un `WHERE`, un `if` ni una comparación.
                          **Este es el montón que interesa.**
  C. alguien decide con ellas.

**Lo que esto NO puede decir**, y hay que leerlo antes de tocar nada:

  · Una columna del montón A o B puede estar decidiendo algo **en el frontend**,
    que la recibe por un `SELECT *` y la pinta o la usa. Son cuatro clientes y
    ninguno está en este repo, así que sin ellos la lista es de CANDIDATOS.
    Con `--clientes` se les pasa por encima y entonces sí se puede decir «esto
    no lo lee nadie, en ninguna parte» — que es la frase que vale.
  · Y al revés: que una columna esté en C no significa que la comprobación sea
    la correcta. `vt_votaciones.locked` se leía —para pintar un candado— y aun
    así se podía votar en una votación cerrada.
  · El reconocimiento de «esto es SQL» es por palabras clave dentro de la
    cadena, no un parser. Con 990 consultas crudas escritas a mano acierta lo
    normal y falla en lo raro; por eso imprime el número de apariciones, para
    que se pueda ir a mirar.

Uso, desde la raíz del proyecto:

    python3 tools/interruptores-que-nadie-lee.py
    python3 tools/interruptores-que-nadie-lee.py --tabla users
    python3 tools/interruptores-que-nadie-lee.py \
        --clientes ../myvc_front ../myvc_front_2 ../myvc_flutter

Con `--clientes` la salida separa lo que un cliente sí mira de lo que no mira
nadie. El 21 ago 2026, con los tres delante, quedaron **dos** columnas que no
aparecen ni en el backend ni en ningún cliente: `users.can_ask` y
`matriculas.profes_editar_notas`. Ver docs/migracion/12-larastan-nivel-7.md §17.
"""

import collections
import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
ESQUEMA = RAIZ / 'database/schema/mysql-schema.sql'
CARPETAS = ('app', 'routes', 'config', 'database/seeders')

# Delante del nombre, en la misma cadena: lo que convierte una columna en una
# decisión. `on` entra porque este proyecto filtra en los JOIN tanto como en el
# WHERE (`... on g.id=m.grupo_id and g.deleted_at is null`).
CONDICION_SQL = re.compile(
    r'\b(where|and|or|on|having|when|if|case)\b[^;]{0,120}$', re.IGNORECASE | re.DOTALL)


def columnas_booleanas():
    """Cada `tinyint(1)` del volcado, con las tablas donde está."""
    if not ESQUEMA.exists():
        print(f'No encuentro el esquema en {ESQUEMA}.', file=sys.stderr)
        raise SystemExit(1)

    tablas = collections.defaultdict(set)
    tabla = None

    for linea in ESQUEMA.read_text(encoding='utf-8', errors='replace').splitlines():
        creacion = re.match(r'CREATE TABLE `(\w+)`', linea)
        if creacion:
            tabla = creacion.group(1)
            continue

        columna = re.match(r'\s*`(\w+)` tinyint\(1\)', linea)
        if columna and tabla:
            tablas[columna.group(1)].add(tabla)

    return tablas


def codigo():
    textos = []
    for carpeta in CARPETAS:
        for fichero in sorted((RAIZ / carpeta).rglob('*.php')):
            textos.append(fichero.read_text(encoding='utf-8', errors='replace'))
    return '\n'.join(textos)


EXTENSIONES = ('.js', '.ts', '.html', '.dart', '.vue')

# Lo compilado no cuenta: `dist/` y `build/` son el mismo código otra vez, y
# `node_modules` es de otros. Sin esto el barrido de los tres clientes pasa de
# unos segundos a varios minutos, y el resultado no cambia.
IGNORADAS = {'node_modules', 'dist', 'build', '.git', 'vendor', '.dart_tool',
             'bower_components', 'coverage', '.angular', 'ios', 'android'}


def clientes(rutas):
    """El texto de los clientes, por nombre, para poder decir cuál lo mira."""
    porCliente = {}

    for ruta in rutas:
        carpeta = pathlib.Path(ruta).expanduser()

        if not carpeta.is_dir():
            print(f'  (aviso: {carpeta} no existe, se salta)', file=sys.stderr)
            continue

        trozos = []
        for fichero in carpeta.rglob('*'):
            if fichero.suffix not in EXTENSIONES:
                continue
            if IGNORADAS & set(fichero.parts):
                continue
            # Un minificado de un mega no dice nada que no diga su fuente.
            if fichero.stat().st_size > 1_000_000:
                continue
            trozos.append(fichero.read_text(encoding='utf-8', errors='replace'))

        porCliente[carpeta.name] = '\n'.join(trozos)

    return porCliente


def quienLoMira(porCliente, nombre):
    """Los clientes que nombran esta columna."""
    return [
        cliente for cliente, texto in porCliente.items()
        if re.search(r'\b' + re.escape(nombre) + r'\b', texto)
    ]


def decide(fuente, nombre):
    """¿Hay algún sitio donde esta columna decida el rumbo?"""
    escapado = re.escape(nombre)

    # Desde PHP: una propiedad, una clave de array o un input que se compara,
    # se niega o entra en un `if`. Se mira la línea entera, que es donde vive la
    # comparación en este repo.
    php = re.compile(
        r'^.*(?:->' + escapado + r'\b|[\'"]' + escapado + r'[\'"])'
        r'.*$', re.MULTILINE)

    for linea in php.findall(fuente):
        if re.search(r'\b(if|while|elseif|switch|case)\b|[=!]==?|&&|\|\||[?]|\bempty\b|\bisset\b|->where', linea):
            return True

    # Desde SQL: el nombre detrás de un WHERE/AND/ON en la misma cadena.
    for encuentro in re.finditer(r'\b' + escapado + r'\b', fuente):
        antes = fuente[max(0, encuentro.start() - 140):encuentro.start()]
        if CONDICION_SQL.search(antes):
            return True

    return False


def main():
    tabla_pedida = None
    if '--tabla' in sys.argv:
        tabla_pedida = sys.argv[sys.argv.index('--tabla') + 1]

    rutasDeClientes = []
    if '--clientes' in sys.argv:
        rutasDeClientes = [a for a in sys.argv[sys.argv.index('--clientes') + 1:]
                           if not a.startswith('--')]

    tablas = columnas_booleanas()
    fuente = codigo()
    porCliente = clientes(rutasDeClientes) if rutasDeClientes else {}

    nunca, mudas, vivas = [], [], []

    for nombre in sorted(tablas):
        if tabla_pedida and tabla_pedida not in tablas[nombre]:
            continue

        apariciones = len(re.findall(r'\b' + re.escape(nombre) + r'\b', fuente))
        donde = ', '.join(sorted(tablas[nombre]))

        miran = quienLoMira(porCliente, nombre) if porCliente else None

        if decide(fuente, nombre) and apariciones:
            vivas.append((nombre, donde, apariciones, miran))
        elif apariciones == 0:
            nunca.append((nombre, donde, 0, miran))
        else:
            mudas.append((nombre, donde, apariciones, miran))

    total = len(nunca) + len(mudas) + len(vivas)

    print()
    print(f'  columnas tinyint(1) distintas ... {total}')
    print(f'  ni se nombran .................. {len(nunca)}')
    print(f'  NO DECIDEN NADA ................ {len(mudas)}')
    print(f'  alguien decide con ellas ....... {len(vivas)}')
    print()

    def pintar(titulo, filas):
        if not filas:
            return
        print(f'  {titulo}')
        for nombre, donde, apariciones, miran in filas:
            veces = f'{apariciones:>4}x' if apariciones else '   -'
            cola = ''
            if miran is not None:
                cola = '  <-- lo mira '+', '.join(miran) if miran else '  <-- NADIE, en ningún cliente'
            print(f'    {nombre:<34} {veces}  {donde[:52]}{cola}')
        print()

    pintar('NI SE NOMBRAN — si llegan al cliente, es por un SELECT *:', nunca)
    pintar('NO DECIDEN NADA — se guardan y se sirven, y ningún if las mira:', mudas)

    if porCliente:
        huerfanas = [f[0] for f in nunca + mudas if f[3] == []]
        print(f'  Clientes mirados: {", ".join(porCliente)}')
        print(f'  SIN NADIE QUE LAS MIRE, ni aquí ni allí: {len(huerfanas)}')
        for nombre in huerfanas:
            print(f'    {nombre}')
        print()
        print('  Esas son las que se pueden afirmar. De las demás solo se sabe que la')
        print('  decisión no está en el backend, y estar en el cliente no es lo mismo')
        print('  que estar bien: `vt_votaciones.locked` la miraba el front —para pintar')
        print('  un candado— y aun así se podía votar en una votación cerrada.')
    else:
        print('  Sin --clientes esto es una lista de CANDIDATOS: una columna que aquí no')
        print('  decide nada puede estar decidiendo en myvc_front, myvc_front_2 o')
        print('  myvc_flutter, que son cuatro clientes de dieciséis colegios.')

    print()

    return 1 if mudas else 0


if __name__ == '__main__':
    raise SystemExit(main())
