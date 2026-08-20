#!/usr/bin/env python3
"""
Agrupa el registro de consultas lentas y dice a qué tabla ir a mirar.

Lee lo que escribe App\\Support\\ConsultasLentas —una línea de JSON por consulta
que pasó del umbral— y lo colapsa por FORMA de la consulta. Sin colapsar, una
semana de producción son miles de líneas que solo se distinguen en el id del
alumno; colapsadas, son diez o quince consultas y se ve cuál se lleva el tiempo.

    # en el servidor del colegio, con CONSULTAS_LENTAS_MS puesto una temporada
    cat storage/logs/consultas-lentas*.log > /tmp/lentas.log

    python3 tools/consultas-lentas.py /tmp/lentas.log
    python3 tools/consultas-lentas.py /tmp/lentas.log --top 40

**Se ordena por tiempo TOTAL, no por la consulta más lenta.** Una consulta de
tres segundos que corre una vez al día importa menos que una de 600 ms que corre
en cada carga de la pantalla de notas, y la segunda es la que paga un índice.
La columna `max` se imprime igual, porque una sola consulta de treinta segundos
es un timeout aunque su total sea pequeño.

La columna `origen` es la que no da el slow_query_log de MySQL: con 538 rutas y
990 consultas crudas, un SQL suelto no dice a qué pantalla ir.
"""
import json, sys, re, collections

ARCHIVO = None
TOP = 20

args = sys.argv[1:]
while args:
    a = args.pop(0)
    if a == '--top':
        TOP = int(args.pop(0))
    elif ARCHIVO is None:
        ARCHIVO = a

if ARCHIVO is None:
    sys.exit(__doc__)


def forma(sql):
    """La consulta sin los valores concretos, para poder agruparla.

    Laravel deja `?` en lo que pasa parametrizado, pero de las 990 consultas
    crudas de este proyecto muchas interpolan el id dentro de la cadena. Sin
    normalizar los números, cada alumno sería una consulta distinta y el informe
    no agruparía nada.
    """
    sql = re.sub(r"'[^']*'", "?", sql)
    sql = re.sub(r'"[^"]*"', "?", sql)
    sql = re.sub(r'\b\d+\b', "?", sql)
    sql = re.sub(r'\bin \(\s*(\?,\s*)+\?\s*\)', "in (?)", sql, flags=re.I)
    return re.sub(r'\s+', ' ', sql).strip()


def tablas(sql):
    """De qué tablas lee. Es lo que hay que mirar al decidir un índice."""
    return sorted(set(re.findall(r'(?:from|join|update|into)\s+`?(\w+)`?', sql, re.I)))


grupos = collections.defaultdict(lambda: {'n': 0, 'total': 0.0, 'max': 0.0,
                                          'origenes': collections.Counter(), 'muestra': ''})
lineas = descartadas = 0

for linea in open(ARCHIVO, encoding='utf-8', errors='replace'):
    linea = linea.strip()
    if not linea:
        continue
    lineas += 1
    try:
        registro = json.loads(linea)
        contexto = registro['context']
        sql, ms = contexto['sql'], float(contexto['ms'])
    except (ValueError, KeyError):
        descartadas += 1
        continue

    g = grupos[forma(sql)]
    g['n'] += 1
    g['total'] += ms
    g['max'] = max(g['max'], ms)
    g['origenes'][contexto.get('origen', '?')] += 1
    g['muestra'] = sql

if not grupos:
    sys.exit(f"No hay consultas en {ARCHIVO} ({lineas} líneas leídas, {descartadas} sin forma de registro).")

orden = sorted(grupos.items(), key=lambda kv: -kv[1]['total'])
tiempo_total = sum(g['total'] for _, g in orden)

print(f"{len(grupos)} consultas distintas, {sum(g['n'] for _, g in orden)} ejecuciones, "
      f"{tiempo_total/1000:.1f} s en total")
if descartadas:
    print(f"({descartadas} líneas descartadas por no tener forma de registro)")
print()

for i, (huella, g) in enumerate(orden[:TOP], 1):
    parte = 100 * g['total'] / tiempo_total
    print(f"{i:3d}. {g['total']/1000:8.1f} s  {parte:4.1f}%   {g['n']:6d} veces   "
          f"media {g['total']/g['n']:7.0f} ms   max {g['max']:7.0f} ms")
    print(f"     tablas: {', '.join(tablas(huella)) or '?'}")
    for origen, veces in g['origenes'].most_common(3):
        print(f"     origen: {origen}  ({veces})")
    print(f"     {huella[:300]}")
    print()

if len(orden) > TOP:
    resto = sum(g['total'] for _, g in orden[TOP:])
    print(f"... y {len(orden)-TOP} consultas más, {resto/1000:.1f} s entre todas "
          f"({100*resto/tiempo_total:.1f}%).")

print()
print("Lo siguiente es EXPLAIN sobre las de arriba, y crear SOLO los índices que")
print("el plan justifique: tools/indices-que-faltan.php hace eso mismo con las")
print("consultas que ejecuta la suite de tests.")
