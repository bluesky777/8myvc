#!/usr/bin/env python3
"""Qué `UPDATE ... SET` cruda vive en una función que lee del cliente.

Existe porque el punto ciego lo destapó `myvc-front-98` la noche del 24 ago 2026:
las herramientas de esta fase buscan asignaciones de Eloquent —`$x->campo = …`—
y **una consulta cruda no tiene ninguna que grepear**. El `null` entra como
binding y la columna se vacía igual, sin que ningún detector lo vea. Es el mismo
borrado silencioso de la §168, por el camino que nadie miraba.

Uso:
    python3 tools/escrituras-crudas-con-entrada.py            # el resumen
    python3 tools/escrituras-crudas-con-entrada.py --sitios   # y cada sitio

**Lo que este número ES y lo que NO ES.** Es *sitios donde mirar*, nunca una
lista de fallos: dice «en esta función hay un `SET` crudo y además se lee del
cliente», no «este binding viene del cliente». Así que **cuenta de más** —el
valor puede venir de una variable ya comprobada, o el `SET` puede no usarlo—.
Y puede contar **de menos** si el valor llega por un ayudante de otro fichero.
Clasificar es leer los sitios; el detector sólo dice dónde.

Y por eso imprime **siempre su población**: un «0 encontrados» no distingue
«los revisé todos» de «no revisé nada», y de las dos lecturas la falsa es la que
hace archivar el asunto.
"""
import re, sys, pathlib, collections

RAIZ = pathlib.Path(__file__).resolve().parent.parent / 'app'
RE_FN = re.compile(r'function\s+(\w+)\s*\(')
ENTRADA = ('Request::input(', '$request->', 'Input::get(')

ficheros = sorted(RAIZ.rglob('*.php'))
total = con_set = con_entrada = 0
por_fichero = collections.Counter()
sitios = []

for f in ficheros:
    txt = f.read_text(errors='replace')
    cortes = [m.start() for m in RE_FN.finditer(txt)] + [len(txt)]
    nombres = [m.group(1) for m in RE_FN.finditer(txt)]
    for i in range(len(cortes) - 1):
        cuerpo = txt[cortes[i]:cortes[i + 1]]
        n = cuerpo.count('DB::update(')
        if not n:
            continue
        total += n
        if not re.search(r'\bset\b', cuerpo, re.I):
            continue
        con_set += n
        if not any(e in cuerpo for e in ENTRADA):
            continue
        con_entrada += n
        rel = f.relative_to(RAIZ.parent)
        sitios.append((str(rel), txt[:cortes[i]].count('\n') + 1, nombres[i], n))
        por_fichero[str(rel)] += n

print(f'POBLACIÓN: {len(ficheros)} ficheros de app/ leídos, '
      f'{total} llamadas a DB::update dentro de una función')
print(f'  con un SET en la misma función ................... {con_set}')
print(f'  y además con entrada del cliente en la función ... {con_entrada}'
      '   <- sitios donde mirar, no fallos')
print(f'  repartidos en {len(por_fichero)} ficheros')
if not sitios:
    print('\nNinguno. Con la población de arriba delante: se revisaron '
          f'{total} llamadas, no cero.')
print()
for f, c in por_fichero.most_common(12):
    print(f'  {c:>3}  {f}')
if '--sitios' in sys.argv:
    print()
    for f, l, fn, n in sitios:
        print(f'  {f}:{l}  {fn}()  x{n}')
