#!/usr/bin/env python3
"""
Qué rutas tienen la respuesta comprobada por algún test, y cuáles no las mira nadie.

Es la herramienta con la que se decide qué falta por cubrir. Existe porque la
pregunta no se puede contestar leyendo los tests: las URLs se construyen
interpolando —"api/boletines/detailed-notas/{$grupo}"— así que buscarlas con
grep pierde justo las que llevan parámetro, que son las que más se rompen.

    docker exec 8myvc-app-1 rm -f /tmp/tocadas.txt
    docker exec -e COBERTURA_RUTAS=/tmp/tocadas.txt 8myvc-app-1 \
        php artisan test --testsuite=Contrato
    docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas.json
    python3 tools/cobertura-de-rutas.py /tmp/rutas.json /tmp/tocadas.txt

El registrador vive en tests/TestCase.php y solo se enciende con la variable
puesta, así que una corrida normal no escribe nada.

**«Ejecutada» no es «comprobada», y la diferencia es todo el valor de esto.**
Medido a secas, el 99% de las rutas se ejecutan: `AutorizacionTest` las hace
pasar a las 539 por el router para su snapshot de guards, y `RutasTest` otro
tanto. Un test así dice que la ruta existe y que su guard es el que era; no mira
lo que devuelve. Por eso aquí se separan:

  - **barrido**: el test que recorre muchas rutas de una vez. Se reconoce por
    tocar más de LIMITE_BARRIDO rutas distintas EN UNA SOLA EJECUCIÓN, y se
    listan en la salida para que el criterio se pueda discutir en vez de creer.
    Contarlas por clase no vale: un test parametrizado que mira 66 respuestas de
    una en una toca 66 rutas entre todas sus ejecuciones y una en cada una, y
    con el criterio por clase se descartaría a sí mismo. Pasó con
    `MuestreoDeLecturasTest` en cuanto se escribió.
  - **comprobada**: la toca algún test que NO es de barrido.

Lo que sale en «nadie mira su respuesta» es la lista de trabajo.
"""
import json, sys, collections

LIMITE_BARRIDO = 25

if len(sys.argv) < 3:
    sys.exit(__doc__)

rutas = json.load(open(sys.argv[1]))

por_caso = collections.defaultdict(set)   # Clase::metodo con data set -> rutas
for linea in open(sys.argv[2], encoding='utf-8'):
    if '\t' not in linea:
        continue
    caso, ruta = linea.rstrip('\n').split('\t', 1)
    por_caso[caso].add(ruta)

barridos = collections.defaultdict(int)   # clase -> rutas de su caso más ancho
comprobadas = set()
for caso, tocadas_del_caso in por_caso.items():
    clase = caso.split('::')[0]
    if len(tocadas_del_caso) > LIMITE_BARRIDO:
        barridos[clase] = max(barridos[clase], len(tocadas_del_caso))
    else:
        comprobadas |= tocadas_del_caso

def controlador(r):
    a = r.get('action', '')
    return a.split('@')[0].split('\\')[-1] if '@' in a else '(sin controlador)'

def esta_comprobada(r):
    # route:list junta los verbos: "GET|HEAD". Basta con que uno se haya visto.
    return any(f'{v} {r["uri"]}' in comprobadas for v in r['method'].split('|'))

por_ctrl = collections.defaultdict(lambda: {'si': [], 'no': []})
for r in rutas:
    por_ctrl[controlador(r)]['si' if esta_comprobada(r) else 'no'].append(
        (r['method'].split('|')[0], r['uri']))

total = len(rutas)
vistas = sum(len(v['si']) for v in por_ctrl.values())
vacios = [c for c, v in por_ctrl.items() if not v['si']]

print(f'Barridos (un caso con >{LIMITE_BARRIDO} rutas, no cuenta como comprobar): '
      + ', '.join(f'{t} ({n})' for t, n in sorted(barridos.items())))
print()
print(f'Rutas: {vistas}/{total} con la respuesta comprobada ({100*vistas//total}%)')
print(f'Controladores: {len(por_ctrl)-len(vacios)}/{len(por_ctrl)} con alguna comprobada')
print()
print(f'--- {len(vacios)} controladores donde nadie mira ninguna respuesta ---')
for c in sorted(vacios, key=lambda c: -len(por_ctrl[c]['no'])):
    lecturas = [u for m, u in por_ctrl[c]['no'] if m == 'GET']
    print(f'  {c:38s} {len(por_ctrl[c]["no"]):3d} rutas'
          + (f'   GET: {lecturas[0]}' if lecturas else '   (ningún GET)'))

parciales = [(c, v) for c, v in por_ctrl.items() if v['si'] and v['no']]
print()
print(f'--- {len(parciales)} controladores a medias ---')
for c, v in sorted(parciales, key=lambda x: -len(x[1]['no'])):
    print(f'  {c:38s} {len(v["si"]):3d} de {len(v["si"])+len(v["no"]):3d} comprobadas')
