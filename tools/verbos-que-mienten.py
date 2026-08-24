#!/usr/bin/env python3
"""Qué rutas NO son `GET` y aun así no escriben nada.

Existe porque en esta API **el verbo no dice si escribe**. Lo destapó
`myvc-front-98` la noche del 24 ago 2026: su cortafuegos de medición bloqueaba
«todo lo que no fuera GET» y tapó **una lectura** —la única llamada del muro sin
sesión, la que pinta el login—. La medición no se movió, pero por casualidad.

Importa más allá de una herramienta: cualquiera que instrumente esta API va a
asumir que `PUT` escribe, **porque es lo que significa en todas partes menos
aquí**. Y para la auditoría importa el doble: **lo que clasifique «qué escribe»
por el método HTTP mete estas rutas en el cajón equivocado**.

Uso:
    python3 tools/verbos-que-mienten.py            # el resumen
    python3 tools/verbos-que-mienten.py --rutas    # y cada ruta candidata

**Lo que el número ES y lo que NO ES.** Es *candidatas a sólo lectura*: dice que
en el cuerpo del método no aparece ninguna marca de escritura. **Cuenta de más**
si el método llama a un servicio o a un modelo que sí escribe —aquí `User::`,
`Nota::` y compañía escriben— y **de menos** si escribe por un camino que no está
en la lista de marcas. Confirmar es leer el método. Como todas las de `tools/`,
**imprime siempre su población**.
"""
import re, sys, pathlib, collections

RAIZ = pathlib.Path(__file__).resolve().parent.parent
MARCAS = ('DB::insert', 'DB::update', 'DB::delete', 'DB::statement', 'DB::table',
          '->save(', '->delete(', '->restore(', '->update(', '->forceDelete(',
          '::create(', '->create(', '->increment(', '->decrement(', 'Request::merge')

# clase -> fichero
clases = {}
for f in (RAIZ / 'app').rglob('*.php'):
    for m in re.finditer(r'^\s*(?:final\s+)?class\s+(\w+)', f.read_text(errors='replace'), re.M):
        clases[m.group(1)] = f

RE_RUTA = re.compile(r"Route::(get|post|put|patch|delete)\(\s*'([^']+)'\s*,\s*\[\s*(\w+)::class\s*,\s*'(\w+)'\s*\]")
RE_FN = re.compile(r'function\s+(\w+)\s*\(')

total = no_get = candidatas = sin_resolver = 0
por_verbo = collections.Counter()
sitios = []

for rf in sorted((RAIZ / 'routes').rglob('*.php')):
    for verbo, uri, clase, metodo in RE_RUTA.findall(rf.read_text(errors='replace')):
        total += 1
        if verbo == 'get':
            continue
        no_get += 1
        f = clases.get(clase)
        if not f:
            sin_resolver += 1
            continue
        txt = f.read_text(errors='replace')
        cortes = [m.start() for m in RE_FN.finditer(txt)]
        nombres = [m.group(1) for m in RE_FN.finditer(txt)]
        if metodo not in nombres:
            sin_resolver += 1
            continue
        i = nombres.index(metodo)
        fin = cortes[i + 1] if i + 1 < len(cortes) else len(txt)
        cuerpo = txt[cortes[i]:fin]
        if not any(mk in cuerpo for mk in MARCAS):
            candidatas += 1
            por_verbo[verbo.upper()] += 1
            sitios.append((verbo.upper(), uri, f'{clase}::{metodo}'))

print(f'POBLACIÓN: {total} rutas leídas de routes/, {no_get} no son GET')
print(f'  método no resuelto (herencia, closure, recurso) ... {sin_resolver}')
print(f'  sin ninguna marca de escritura en el método ...... {candidatas}'
      '   <- candidatas a sólo lectura, no confirmadas')
print('  por verbo: ' + ', '.join(f'{k} {v}' for k, v in por_verbo.most_common()))
if '--rutas' in sys.argv:
    print()
    for v, uri, m in sorted(sitios):
        print(f'  {v:<6} {uri:<52} {m}')
