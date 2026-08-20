#!/usr/bin/env python3
"""
Inventario de rutas que reciben un identificador del cliente y no tienen guard.

Es la herramienta con la que se hizo la revisión de IDOR de docs/migracion/08-revision-idor.md,
y está aquí para poder repetirla: la lista se acorta a medida que la Fase 2 va cerrando rutas, y
lo que importa es poder medir cuánto.

    docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas.json
    python3 tools/inventario-autorizacion.py /tmp/rutas.json

Marca una ruta cuando se cumplen las tres:

  - exige token (`auth.token`); las de antes del login las cubre RutasPreLoginTest,
  - recibe un identificador del cliente, por segmento de URL o por el cuerpo,
  - y NO tiene guard: ni `auth.personal`, ni `persona.propia`, ni `boletin.propio`
    en la ruta, ni una comprobación de permisos dentro del método.

Es un filtro grueso a propósito: da falsos positivos —hay rutas de catálogo que no exponen datos
de nadie— y por eso su salida es una lista PARA REVISAR, no una lista de fallos. Los fallos
confirmados, golpeando cada ruta con un token de alumno de verdad, están en
tests/Contrato/SuperficieDeUnAlumnoTest.php.
"""
import json, re, os, sys

RUTAS = sys.argv[1] if len(sys.argv) > 1 else 'rutas.json'
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
GUARDS_RUTA = ('auth.personal', 'boletin.propio', 'persona.propia')
# comprobaciones de permiso dentro del método
GUARD_CUERPO = re.compile(r'is_superuser|profes_can_edit|Autoriza::|pueden_editar_notas|es_secretari', re.I)
# identificadores de PERSONA que llegan del cliente
ID_PERSONA = re.compile(
    r"""input\(\s*['"](alumno_id|acudiente_id|profesor_id|user_id|persona_id|matricula_id|parentesco_id)['"]"""
    r"""|\$(alumno_id|acudiente_id|profesor_id|user_id|matricula_id)\b""", re.I)

filas = []
for r in rutas:
    accion = r['action']
    if 'Closure' in accion: continue
    uri = r['uri']
    if not uri.startswith('api/'): continue
    mw = r['middleware'] or []
    # rutas sin token: las cubre RutasPreLoginTest
    if 'auth.token' not in mw: continue

    guard_ruta = [g for g in GUARDS_RUTA if any(x == g or x.startswith(g + ':') for x in mw)]
    b = cuerpo(accion) or ''
    guard_cuerpo = bool(GUARD_CUERPO.search(b))
    id_persona = ID_PERSONA.search(b)
    # id por segmento de URL
    segmentos = re.findall(r'\{(\w+)\??\}', uri)
    id_url = [s for s in segmentos if s.endswith('_id') or s == 'id']

    if not (id_persona or id_url): continue
    if guard_ruta or guard_cuerpo: continue   # ya protegida

    filas.append({
        'ruta': r['method'].replace('|HEAD','') + ' ' + uri,
        'accion': accion,
        'id_url': ','.join(id_url),
        'id_cuerpo': id_persona.group(0) if id_persona else '',
    })

filas.sort(key=lambda f: f['ruta'])
print('Rutas con token, con identificador del cliente y SIN guard de autorización:', len(filas))
print()
for f in filas:
    print('%-58s %-52s url=%-18s cuerpo=%s' % (f['ruta'][:58], f['accion'].split('\\')[-1][:52], f['id_url'], f['id_cuerpo']))
