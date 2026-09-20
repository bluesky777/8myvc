#!/usr/bin/env python3
"""
Qué tests hay que correr para los ficheros que cambió esta rama — y cuándo NO
basta con un subconjunto.

Existe porque la suite entera son **2.520 pruebas y ~23 minutos**, y con cuatro
líneas de trabajo en paralelo eso es casi una hora de máquina por tanda. Pero
recortar la suite tiene un modo de fallo peor que el coste: **un selector que se
salta un test deja pasar una regresión y nadie se entera**. Así que esta
herramienta está escrita para equivocarse SIEMPRE hacia correr de más, y para
**decir en voz alta lo que no ha sabido mapear** en vez de callarlo.

    python3 tools/tests-que-tocan.py                       # cambios contra main
    python3 tools/tests-que-tocan.py --desde HEAD~3
    python3 tools/tests-que-tocan.py --ficheros a.php b.php

    Código de salida:
      0  hay un subconjunto seguro; lo imprime como `--filter`
      2  NO lo hay: hay que correr la suite entera, y dice por qué

## De dónde sale el mapeo, que es lo que lo hace fiable

**No es una heurística de nombres.** Se midió antes de escribir esto: de 259
ficheros de `app/` muestreados, **58 no los nombra ningún test** (el 22 %), y
`NotasController` sale con 7 ficheros cuando hay más que lo ejercitan por HTTP.
Un `grep` por el nombre de la clase perdería justo los tests de contrato, que
llaman por ruta y no nombran al controlador.

Lo que sí es exacto es la cadena que este repo ya tenía a medias:

    fichero cambiado -> sus rutas          (route:list --json, campo `action`)
                     -> tests que las tocan (el registrador de tests/TestCase.php)

El registrador lleva desde agosto escribiendo `Clase::test <TAB> MÉTODO uri` con
`COBERTURA_RUTAS` puesta, y es lo que usa `tools/cobertura-de-rutas.py`. Aquí se
lee al revés: de la ruta al test.

**El mapa se regenera gratis**: cualquier corrida de la suite entera lo produce
si se lanza con la variable puesta. Por eso la convención es ponerla siempre.

## Las cinco cosas que obligan a la suite entera, y por qué cada una

  routes/                el registro de rutas cambia el mapa que usa esto
  database/migrations/   una columna nueva se reparte sola por los `SELECT *`
  database/schema/       ídem, y además reconstruye las bases de test
  config/ · .env.*       lo lee cualquier cosa
  tests/TestCase.php     es el padre de las 291 clases
  composer.json/lock     cambia lo que hay debajo de todo

Y un momento que no es un fichero: **antes de DESPLEGAR**. Regla de Joseth del
20 sep 2026, y no es la que esta herramienta traía escrita.

> **La suite entera se ata al despliegue, NO a la fusión.** El motivo es cómo se
> trabaja aquí: Joseth lleva muchos frentes a la vez y `main` recibe fusiones toda
> la tarde —ocho el 20 sep entre las 11:31 y las 12:44—. Exigir 23 minutos de
> suite por fusión convierte el día en una cola de esperas, y **el riesgo que
> cubriría es reversible**: lo fundido se arregla con otro commit. Lo desplegado
> viaja a dieciséis colegios copia a copia y **allí el rojo lo descubre una
> secretaría**.
>
> O sea: **fundir es barato de deshacer, desplegar no.** La suite entera se paga
> donde el error es caro.

## Lo que esta herramienta NO puede contestar

Los ficheros de `app/` que **no son controladores** —modelos, servicios,
`Support/`, middlewares— no tienen rutas propias, así que la cadena de arriba no
los alcanza. Para ésos se listan candidatos por nombre **y se marca el veredicto
como incompleto**: son la causa más común de que salga `2`.

El mapeo exacto de ésos necesita cobertura por test. `pcov` está instalado en el
contenedor, así que se puede: `php artisan test --coverage-php /tmp/c.cov` y leer
`getData()`, que trae qué test tocó cada línea. **No está hecho**, y mientras no
lo esté esta herramienta manda a la suite entera en vez de adivinar.
"""
import argparse
import json
import os
import re
import subprocess
import sys
from collections import defaultdict

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Cambiar cualquiera de éstos invalida cualquier subconjunto. Ver cabecera.
OBLIGAN_ENTERA = (
    'routes/',
    'database/migrations/',
    'database/schema/',
    'config/',
    'tests/TestCase.php',
    'composer.json',
    'composer.lock',
    'phpunit.xml',
)


def sh(orden):
    return subprocess.run(orden, cwd=RAIZ, capture_output=True, text=True, shell=True).stdout


def ficheros_cambiados(desde):
    """Contra el MERGE-BASE, no contra la punta.

    `git diff main..rama` mezcla «la rama cambió esto» con «la rama va por detrás
    y fue main quien lo cambió». Con diecisiete worktrees vivos lo segundo es casi
    siempre, y costó un diagnóstico entero el 20 sep 2026.
    """
    base = sh(f'git merge-base {desde} HEAD').strip() or desde
    salida = sh(f'git diff --name-only {base}..HEAD') + sh('git status --porcelain')
    vistos = []
    for linea in salida.splitlines():
        f = linea[3:].strip() if re.match(r'^[ MARCDU?!]{2} ', linea) else linea.strip()
        if f and f not in vistos:
            vistos.append(f)
    return vistos


def mapa_rutas_a_tests(registro):
    """`Clase::test <TAB> MÉTODO uri`  ->  {uri: {clases}}"""
    por_uri = defaultdict(set)
    with open(registro, encoding='utf-8', errors='replace') as fh:
        for linea in fh:
            if '\t' not in linea:
                continue
            test, ruta = linea.rstrip('\n').split('\t', 1)
            uri = ruta.split(' ', 1)[1] if ' ' in ruta else ruta
            # **El nombre va DESNUDO, sin el espacio de nombres, y no es cosmético.**
            # El registrador escribe `Contrato\AutenticacionTest::test_x`, y eso
            # metido en un `--filter` es una expresión regular donde `\A` es el
            # ancla de principio de cadena: el patrón no casa con NADA y la corrida
            # ejecuta CERO tests — que se lee exactamente igual que una suite en
            # verde. Comprobado el 20 sep 2026 antes de que saliera de aquí.
            por_uri[uri].add(test.split('::')[0].split('\\')[-1])
    return por_uri


def rutas_por_clase(rutas_json):
    """`App\\Http\\Controllers\\XController@metodo` -> {XController: {uris}}"""
    por_clase = defaultdict(set)
    with open(rutas_json, encoding='utf-8') as fh:
        for r in json.load(fh):
            accion = r.get('action') or ''
            if '@' not in accion:
                continue
            clase = accion.split('@')[0].split('\\')[-1]
            por_clase[clase].add(r.get('uri') or '')
    return por_clase


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--desde', default='main')
    p.add_argument('--ficheros', nargs='*')
    p.add_argument('--registro', default='/tmp/rutas-tocadas.txt')
    p.add_argument('--rutas', default='/tmp/rutas.json')
    args = p.parse_args()

    cambiados = args.ficheros if args.ficheros else ficheros_cambiados(args.desde)
    if not cambiados:
        print('POBLACIÓN: 0 ficheros cambiados. Nada que correr.')
        return 0

    print(f'POBLACIÓN: {len(cambiados)} ficheros cambiados contra {args.desde}')

    # 1 · los que obligan a la suite entera
    forzosos = [f for f in cambiados if any(f.startswith(x) or f == x for x in OBLIGAN_ENTERA)]
    if forzosos:
        print('\nSUITE ENTERA, y el motivo no es prudencia genérica:')
        for f in forzosos:
            print(f'  {f}')
        print('\n  docker exec -w /app -e DB_TEST_DATABASE=<la tuya> \\')
        print('      -e COBERTURA_RUTAS=/tmp/rutas-tocadas.txt 8myvc-app-1 php artisan test')
        return 2

    # 2 · los tests que cambiaron se corren siempre, mapeo o no
    tests = {os.path.basename(f)[:-4] for f in cambiados
             if f.startswith('tests/') and f.endswith('Test.php')}

    # 3 · controladores -> rutas -> tests (la cadena medida)
    sin_mapear, avisos = [], []
    de_app = [f for f in cambiados if f.startswith('app/') and f.endswith('.php')]
    falta_mapa = None
    if not os.path.exists(args.registro):
        falta_mapa = (f'NO existe el registro {args.registro}: nunca se ha corrido la '
                      'suite con COBERTURA_RUTAS puesta, así que no hay mapa que leer.')
    elif not os.path.exists(args.rutas):
        falta_mapa = f'NO existe {args.rutas}: hace falta `route:list --json > {args.rutas}`.'

    if falta_mapa:
        avisos.append(falta_mapa)
        # **Sin mapa, TODOS los de app/ quedan sin mapear, y hay que contarlos.**
        # La primera versión los dejaba fuera de la cuenta y salía «NO SE PUDO
        # MAPEAR 0 de 1», que se lee como «no falló nada» — un contador que dice
        # cero cuando no ha revisado nada es la trampa que este repo persigue
        # desde agosto. Lo cazó el control de esta misma herramienta.
        sin_mapear += [f'{f} (sin mapa que consultar)' for f in de_app]
    else:
        por_uri = mapa_rutas_a_tests(args.registro)
        por_clase = rutas_por_clase(args.rutas)
        for f in cambiados:
            if not f.startswith('app/') or not f.endswith('.php'):
                continue
            clase = os.path.basename(f)[:-4]
            if clase in por_clase:
                encontrados = set()
                for uri in por_clase[clase]:
                    encontrados |= por_uri.get(uri, set())
                if encontrados:
                    tests |= encontrados
                else:
                    sin_mapear.append(f'{f} (tiene rutas, pero NINGÚN test registrado las toca)')
            else:
                sin_mapear.append(f'{f} (no es un controlador enrutado: sin cadena que seguir)')

    otros = [f for f in cambiados
             if not f.startswith(('app/', 'tests/', 'docs/')) and not f.endswith('.md')]
    sin_mapear += [f'{f} (fuera de app/ y tests/)' for f in otros]

    for a in avisos:
        print(f'\n⚠  {a}')

    if sin_mapear or avisos:
        print(f'\nNO SE PUDO MAPEAR {len(sin_mapear)} de {len(cambiados)}:')
        for f in sin_mapear:
            print(f'  {f}')
        print('\nVEREDICTO: INCOMPLETO — hay que PREGUNTAR, no decidir solo.')
        print('Un subconjunto que no cubre lo que cambió no es un subconjunto: es una')
        print('medición sobre la población equivocada. Pero la suite entera cuesta 23')
        print('minutos y aquí se paga en el despliegue, no en cada tanda — así que esto')
        print('lo elige Joseth con las dos cifras delante, no el guion.')
        if tests:
            print(f'\nLo que SÍ se mapeó ({len(tests)} clases), como atajo mientras tanto:')
            print('  --filter=' + "'" + '|'.join(sorted(tests)) + "'")
        return 2

    print(f'\nSUBCONJUNTO SEGURO: {len(tests)} clases de test para {len(cambiados)} ficheros')
    for t in sorted(tests):
        print(f'  {t}')
    print('\n  docker exec -w /app -e DB_TEST_DATABASE=<la tuya> 8myvc-app-1 \\')
    print("      php artisan test --testsuite=Contrato --filter='" + '|'.join(sorted(tests)) + "'")
    print('\nEsto basta para fundir. La suite ENTERA se paga antes de DESPLEGAR,')
    print('que es donde el rojo lo descubre una secretaría y no un commit.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
