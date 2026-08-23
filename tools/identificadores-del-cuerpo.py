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
# Comprobaciones de propiedad DENTRO del método, en cualquier forma vista en el repo.
#
# La raíz `exig` y no `exigir`: cubre `Autoriza::exigir` y también los helpers
# privados que este proyecto escribe con ese verbo, que es donde vive la mitad de
# las comprobaciones — y los escribe **conjugados de dos maneras**,
# `exigirQueLaResueltaSeaSuya` y `exigeQueLaPublicacionSeaSuya`. Con `exigir`,
# `MisActividadesController` y `PublicacionesController` salían enteros como
# candidatos teniéndolas todas puestas desde la §20, la §22 y la §43.
#
# Que dos controladores conjuguen distinto el mismo verbo es la misma trampa que
# persigue esta herramienta, un piso más arriba: **el detector también se queda
# ciego ante un nombre nuevo.**
PROPIEDAD = re.compile(r'exig|pedidoPropio|is_superuser|esSuperusuario|'
                       r'profes_can_edit|PeriodoDeLaFila|pueden_modificar|pueden_editar', re.I)

# Y lo que la raíz `exig` se traga sin ser una comprobación de propiedad.
#
# `ColumnaSegura::exigir` valida **un nombre de columna**, no de quién es la fila:
# es la defensa contra la inyección de las pantallas que guardan un campo suelto
# mandando {propiedad, valor}. Con la raíz `exig` a secas, esos métodos salían en
# la mitad limpia de la tabla —`prop = sí`— **sin que nadie comprobara nada**, que
# es peor que salir marcados: una ruta del lado limpio no la vuelve a mirar nadie.
#
# Son CINCO, medidas el 23 ago 2026 sobre las 230: `asignaturas/toggle-dia`,
# `nota_comportamiento/guardar-libro`, `ordinales/guardar-valor`,
# `ordinales/guardar-valor-config` y `years/toggle-cambiar-valor`. **No es toda la
# familia `guardar-valor`**: las demás —alumnos, acudientes, profesores,
# enfermería, uniformes— tienen además una comprobación de verdad, y su `sí` es
# correcto. Contarlo antes de escribirlo es lo que separa las dos cosas.
#
# Es la §53 girada del revés. Allí el detector se quedó **ciego ante un nombre
# nuevo** —`exigeQue…` frente a `exigirQue…`— y por eso la raíz es `exig`; aquí
# **ve un nombre que no es**. Ensanchar una señal para no perder nada la hace
# tragar de más, y las dos formas del error se pagan en el mismo sitio: una ruta
# que nadie vuelve a mirar.
NO_ES_PROPIEDAD = re.compile(r'ColumnaSegura::exigir')

# Y la tercera forma del mismo error: **la señal leía la prosa**.
#
# Con la columna nueva puesta, la primera ejecución sacó
# `definitivas_periodos/update-recuperacion` marcada como comprobada por un
# token que era `exigen` — la palabra, dentro del comentario «se exigen abiertos
# todos los periodos». Un método con un docblock que hable de exigir salía del
# lado limpio sin comprobar nada.
#
# Es la misma ceguera que ya se midió en `escrituras-en-las-notas.py`, que
# también leía prosa de los docblocks. Que dos herramientas distintas caigan en
# lo mismo es lo que lo convierte en una regla y no en un caso: **un detector que
# busca una palabra tiene que mirar solo el código.**
COMENTARIOS = re.compile(r'/\*.*?\*/|//[^\n]*|(?<!:)#[^\n]*', re.S)


def senal_de_propiedad(src):
    """Qué disparó el `prop = sí`, o None. Devolver el token y no un booleano es
    lo que permite ver el siguiente falso positivo sin volver a medirlo: una
    columna que dice `sí` afirma; una que dice `Autoriza::exigir` se puede
    comprobar de un vistazo."""
    limpio = NO_ES_PROPIEDAD.sub('', COMENTARIOS.sub(' ', src))
    m = PROPIEDAD.search(limpio)
    if not m:
        return None

    # El token entero alrededor de la coincidencia, para que se lea `esSuperusuario`
    # y no `exig`.
    i, j = m.start(), m.end()
    while i > 0 and (limpio[i - 1].isalnum() or limpio[i - 1] in '_:>-'):
        i -= 1
    while j < len(limpio) and (limpio[j].isalnum() or limpio[j] == '_'):
        j += 1
    return limpio[i:j].lstrip(':>-')

# --- lo que el guard `persona.propia` ya comprueba por su nombre ----------------
#
# La comprobación de propiedad **no siempre está dentro del método**: para ocho de
# estas rutas la hace `ExigirPersonaPropia`, que recoge del cuerpo y de la URL los
# identificadores que nombran a una persona y comprueba que sean del que pregunta.
# Sin cruzarlo, esta herramienta las marcaba «sin comprobar propiedad» teniéndola
# puesta desde la revisión de IDOR — y son **más falsos positivos que los cinco de
# `ColumnaSegura::exigir`**.
#
# La lista se lee **del propio middleware** y no se copia aquí: lleva tres nombres
# para una sola cosa —`imagen_id`, `img_id`, `foto_id`— porque cada endpoint que
# inventó el suyo dejó al guard ciego (05 §15, §53). Una copia a mano se
# desincronizaría en el siguiente nombre nuevo, que es exactamente el fallo que
# esta herramienta persigue.
def claves_del_guard():
    ruta = 'app/Http/Middleware/ExigirPersonaPropia.php'
    if not os.path.exists(ruta):
        return set()
    src = open(ruta, encoding='utf-8', errors='replace').read()
    m = re.search(r'private const CLAVES = \[(.*?)\];', src, re.S)
    if not m:
        return set()
    cuerpo_lista = re.sub(r'/\*.*?\*/|//[^\n]*', ' ', m.group(1), flags=re.S)
    return set(re.findall(r"'([a-z_]+)'", cuerpo_lista))


CLAVES_GUARD = claves_del_guard()

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
    senal     = senal_de_propiedad(src)

    mw = [m for m in r['middleware'] if not m.startswith('Illuminate') and m != 'api']
    lleva_persona_propia = any(m.split(':')[0] == 'persona.propia' for m in mw)
    cubiertas = CLAVES_GUARD if lleva_persona_propia else set()

    # de los que entran, cuáles NO se derivan de una fila NI los mira el guard
    sueltos = []
    for k in claves:
        if re.search(r'\$[A-Za-z_]\w*->%s\b' % re.escape(k), src):
            continue          # también se lee de una fila: probablemente derivado
        if k in cubiertas:
            continue          # lo comprueba `persona.propia` por su nombre
        sueltos.append(k)

    if senal is None and cubiertas & set(claves):
        senal = 'persona.propia'
    propiedad = senal is not None

    filas.append({
        'uri': uri, 'metodo': r['method'].split('|')[0], 'accion': r['action'].split('\\')[-1],
        'claves': claves, 'sueltos': sueltos, 'escribe': escribe,
        'propiedad': propiedad, 'senal': senal or 'NO', 'mw': ','.join(mw) or '—',
    })

# --- salida --------------------------------------------------------------------
def peso(f):
    return (len(f['sueltos']) if not f['propiedad'] else 0, f['escribe'], len(f['sueltos']))

filas.sort(key=peso, reverse=True)

print(f'{len(filas)} rutas leen al menos un identificador del cuerpo.\n')
print(f'{"ruta":<52} {"guard":<22} {"esc":<4} {"quién comprueba":<26} identificadores')
print('-' * 150)
for f in filas:
    marca = ' '.join(('*' + k if k in f['sueltos'] and not f['propiedad'] else k) for k in f['claves'])
    print(f'{f["metodo"]+" "+f["uri"]:<52} {f["mw"]:<22} '
          f'{"sí" if f["escribe"] else "no":<4} {f["senal"][:26]:<26} {marca}')

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
