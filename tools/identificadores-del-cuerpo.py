#!/usr/bin/env python3
"""
Qué identificadores lee cada ruta del cuerpo de la petición, y cuáles de ellos
no los comprueba nadie.

Contesta la pregunta que la 05 §50 dejó escrita después de encontrar el mismo
fallo cinco veces en tres pasadas: **«¿qué MÁS lee este identificador del
cuerpo?»**. `data_id` derivado del cuerpo y no de la fila apareció en
`aceptar-alumno`, `aceptar-asignatura`, los dos `destruir` y `rechazar` —cinco
métodos, un solo error—, y cada pasada entró por una ruta y arregló lo que esa
ruta tocaba. Esto entra por el identificador.

    docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas.json
    python3 tools/identificadores-del-cuerpo.py /tmp/rutas.json
    python3 tools/identificadores-del-cuerpo.py /tmp/rutas.json --clave alumno_id

Marca un identificador cuando se cumplen las tres:

  - llega por el CUERPO (`Request::input('algo_id')`), no por la URL — los de la
    URL los cubre `inventario-autorizacion.py`, y `AutorizacionTest` comprueba
    además que el nombre del parámetro sea uno de los que el guard mira (05 §13),
  - se usa en una consulta dentro del mismo método,
  - y NO se deriva de una fila ya comprobada ni lo mira ninguna condición.

**Es un filtro grueso a propósito.** No sabe distinguir un `year_id` —que nombra
una configuración del colegio y no a nadie— de un `alumno_id`, ni ve que un
`asignatura_id` esté acotado más abajo por un `WHERE profesor_id = $user->...`.
Su salida es una lista de sitios DONDE MIRAR, nunca una lista de fallos: la
lección de la 05 §52 es que el mismo patrón se midió cuatro veces y ninguna
medida era la buena. Lo que separa el fallo del ruido es leer el método.

Las dos columnas que hay que mirar primero:

  - `sin comprobar` — cuántos de los identificadores del cuerpo no los mira nadie.
    Dos o más es la forma exacta de la §49: el método comprueba uno y escribe con
    los otros.
  - `escribe` — si el método hace UPDATE/DELETE/INSERT. Un identificador ajeno en
    una lectura es una fuga; en una escritura es la fila de otro.
"""
import json, re, os, sys
from collections import defaultdict

args   = [a for a in sys.argv[1:] if not a.startswith('--')]
RUTAS  = args[0] if args else 'rutas.json'
CLAVE  = None
if '--clave' in sys.argv:
    CLAVE = sys.argv[sys.argv.index('--clave') + 1]

rutas = json.load(open(RUTAS))

# --- cuerpo de cada método de controlador -------------------------------------
cache = {}
def cuerpo(accion):
    if '@' not in accion: return None
    clase, metodo = accion.split('@')
    ruta = 'app/' + clase.replace('App\\', '').replace('\\', '/') + '.php'
    if not os.path.exists(ruta): return None
    if ruta not in cache: cache[ruta] = open(ruta, encoding='utf-8', errors='replace').read()
    src = cache[ruta]
    m = re.search(r'function\s+%s\s*\(' % re.escape(metodo), src)
    if not m: return None
    i = src.index('{', m.end()-1)
    nivel, j = 0, i
    while j < len(src):
        if src[j] == '{': nivel += 1
        elif src[j] == '}':
            nivel -= 1
            if nivel == 0: break
        j += 1
    return src[m.start():j+1]

# --- señales -------------------------------------------------------------------
# Un identificador del cuerpo. `Input::get` es la forma vieja; quedan unas pocas.
ENTRA = re.compile(r"""(?:Request::input|Input::get|->input)\(\s*['"]((?:[A-Za-z0-9_]+_ids?)|ids?)['"]""")

ESCRIBE  = re.compile(r'\b(UPDATE|DELETE|INSERT)\b|DB::(update|delete|insert|statement)|->(save|delete|update|forceDelete)\(', re.I)
# comprobaciones de propiedad DENTRO del método, en cualquier forma vista en el repo
PROPIEDAD = re.compile(r'Autoriza::|pedidoPropio|is_superuser|esSuperusuario|profes_can_edit|PeriodoDeLaFila', re.I)

filas = []
for r in rutas:
    uri = r['uri']
    if not uri.startswith('api/'): continue
    src = cuerpo(r['action'])
    if not src: continue

    claves = sorted(set(ENTRA.findall(src)))
    if not claves: continue
    if CLAVE and CLAVE not in claves: continue

    escribe   = bool(ESCRIBE.search(src))
    propiedad = bool(PROPIEDAD.search(src))

    # de los que entran, cuáles NO se derivan además de una fila
    sueltos = []
    for k in claves:
        if re.search(r'\$[A-Za-z_]\w*->%s\b' % re.escape(k), src):
            continue          # también se lee de una fila: probablemente derivado
        sueltos.append(k)

    mw = [m for m in r['middleware'] if not m.startswith('Illuminate') and m != 'api']
    filas.append({
        'uri': uri, 'metodo': r['method'].split('|')[0], 'accion': r['action'].split('\\')[-1],
        'claves': claves, 'sueltos': sueltos, 'escribe': escribe,
        'propiedad': propiedad, 'mw': ','.join(mw) or '—',
    })

# --- salida --------------------------------------------------------------------
def peso(f):
    return (len(f['sueltos']) if not f['propiedad'] else 0, f['escribe'], len(f['sueltos']))

filas.sort(key=peso, reverse=True)

print(f'{len(filas)} rutas leen al menos un identificador del cuerpo.\n')
print(f'{"ruta":<52} {"guard":<22} {"esc":<4} {"prop":<5} identificadores')
print('-' * 130)
for f in filas:
    marca = ' '.join(('*' + k if k in f['sueltos'] and not f['propiedad'] else k) for k in f['claves'])
    print(f'{f["metodo"]+" "+f["uri"]:<52} {f["mw"]:<22} '
          f'{"sí" if f["escribe"] else "no":<4} {"sí" if f["propiedad"] else "NO":<5} {marca}')

print('\n(*) identificador que entra por el cuerpo, no se lee de ninguna fila y el '
      'método no comprueba propiedad. Es el sitio donde mirar, no el fallo.')

# --- por identificador: la pregunta de la §50 ----------------------------------
por_clave = defaultdict(list)
for f in filas:
    for k in f['claves']:
        por_clave[k].append(f)

print(f'\n\nY la misma lista por identificador — «¿qué MÁS lee este id del cuerpo?»:\n')
for k, fs in sorted(por_clave.items(), key=lambda kv: -len(kv[1])):
    sin = [f for f in fs if not f['propiedad'] and k in f['sueltos']]
    if not sin: continue
    print(f'{k:<22} {len(fs):>3} rutas, {len(sin):>3} sin comprobar propiedad')
    for f in sin:
        print(f'{"":<22}   {f["metodo"]:<7} {f["uri"]:<46} {f["mw"]}')
