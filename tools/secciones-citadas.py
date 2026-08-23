#!/usr/bin/env python3
"""
Qué §§ cita el código y no existen en la documentación.

    python3 tools/secciones-citadas.py            # solo las huérfanas
    python3 tools/secciones-citadas.py --todas    # y de dónde sale cada cita

Existe porque **los comentarios de este repo citan secciones de `docs/` por su
número** —1.239 citas a 226 secciones distintas, medido el 23 ago 2026— y esas
citas son la única forma de saber por qué un guard está donde está. Renumerar una
sección en `docs/` deja atrás las que la citaban desde `app/`, y ahí no las mira
nadie: **un índice desalineado lo caza quien recorre el índice; un `// §144`
desalineado no lo caza nunca nadie.**

Y no se rompe: **miente**. Si el §144 pasa a ser otra cosa, el comentario sigue
apuntando a una sección **que existe** y manda a leer sobre otro asunto. No falla:
acierta a otro sitio. Por eso esto se corre **después de cada renumerado**, junto
a la comprobación de colisiones, y no una vez al año.

Salió de un caso real: la noche del 22 al 23 de agosto un `§144` se movió al
`§167` y hubo que arrastrar a mano tres comentarios de
`PublicacionesController`. Salió bien porque quien renumeró se acordó.

─────────────────────────────────────────────────────────────────────────────
LAS TRES TRAMPAS DEL PATRÓN, y las tres costaron un número publicado:

1. **Un encabezado puede declarar un rango**: `## §125–126 — Lo medido…` declara
   **dos**. Un patrón que solo lee el primer número deja el §126 fuera y lo
   publica como «hueco que nadie usó». Pasó, y lo peor no fue el fallo: fue que
   **dos mediciones independientes coincidieron en el número malo** porque las dos
   tenían el mismo punto ciego. Coincidir no es comprobar.
2. **Un subapartado citado no es una sección que falte.** `§112.1` en un test
   quiere decir «el caso 1 del §112»; el documento no tiene por qué declararlo
   como encabezado. Se resuelve contra su sección padre.
3. **`§08` y `§8` son el mismo.** Normalizar antes de comparar.

Lo que **no** es un error y por eso no se avisa: una sección declarada que no
cita nadie. La documentación puede describir cosas que no dejaron comentario.
─────────────────────────────────────────────────────────────────────────────
"""
import os
import re
import sys
import collections

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = 'docs'
CODIGO = ('app', 'tests', 'tools', 'routes', 'config', 'database')
EXTENSIONES = ('.php', '.py', '.sh')

# El § al principio del encabezado DECLARA; el § dentro de la frase REFERENCIA.
# La distinción es por posición, y sin ella el recuento sale ancho — con cara de
# más cobertura, que es la dirección en la que un número malo no se nota.
DECLARA = re.compile(r'^#+\s*(?:[\d.]+\s*)?§\s?(\d+)(?:\s*[–—-]\s*(\d+))?')

# **Y hay dos formas de declarar, no una.** Las secciones de la noche del 22 al 23
# llevan el § en el encabezado (`## §133 — …`); las anteriores NO lo llevan
# (`## 27. El interruptor…`, `### 27.4 El candado…`) y las citan igual desde el
# código. Con solo el primer patrón salían **75 huérfanas y las 75 eran falsas**:
# la §27 existe, con 43 citas, y el patrón no la veía.
#
# El precio de aceptar la segunda forma es que un encabezado numerado de
# cualquier documento cuenta como declaración —`## 4.b`, `## 1. La importación`—,
# o sea que la comprobación **declara de más**. Se acepta a sabiendas y se dice
# aquí: de los dos errores, éste solo puede esconder una huérfana cuyo número
# coincida con el de una sección ajena; el otro tapaba la comprobación entera.
DECLARA_SIN_SIMBOLO = re.compile(r'^#{2,}\s*(\d+(?:\.\d+)*)[.\s—–-]')

CITA = re.compile(r'§\s?(\d+(?:\.\d+)*)')


def normaliza(n):
    """`08` y `8` son el mismo; `112.1` conserva su padre."""
    partes = n.split('.')
    return '.'.join(str(int(p)) for p in partes)


def declaradas():
    vistas = {}
    for base, _, ficheros in os.walk(DOCS):
        for fichero in ficheros:
            if not fichero.endswith('.md'):
                continue
            ruta = os.path.join(base, fichero)
            for i, linea in enumerate(open(ruta, encoding='utf-8', errors='replace'), 1):
                if not linea.startswith('#'):
                    continue
                m = DECLARA.match(linea)
                if m:
                    desde = int(m.group(1))
                    hasta = int(m.group(2)) if m.group(2) else desde
                    for n in range(desde, hasta + 1):
                        vistas.setdefault(str(n), []).append(f'{ruta}:{i}')
                    continue
                m = DECLARA_SIN_SIMBOLO.match(linea)
                if m:
                    vistas.setdefault(normaliza(m.group(1)), []).append(f'{ruta}:{i}')
    return vistas


def citadas():
    vistas = collections.defaultdict(list)
    for raiz in CODIGO:
        for base, _, ficheros in os.walk(raiz):
            if 'vendor' in base.split(os.sep):
                continue
            for fichero in ficheros:
                if not fichero.endswith(EXTENSIONES):
                    continue
                ruta = os.path.join(base, fichero)
                for i, linea in enumerate(open(ruta, encoding='utf-8', errors='replace'), 1):
                    for m in CITA.finditer(linea):
                        vistas[normaliza(m.group(1))].append(f'{ruta}:{i}')
    return vistas


def main():
    os.chdir(RAIZ)
    todas = '--todas' in sys.argv
    dec, cit = declaradas(), citadas()

    huerfanas = {}
    for seccion, sitios in cit.items():
        padre = seccion.split('.')[0]
        if seccion not in dec and padre not in dec:
            huerfanas[seccion] = sitios

    print(f'§§ declaradas en {DOCS}/ ........ {len(dec)}')
    print(f'§§ distintas citadas del código . {len(cit)}')
    print(f'citas totales ................... {sum(len(v) for v in cit.values())}')
    print(f'huérfanas ....................... {len(huerfanas)}')

    if todas:
        print('\n--- de dónde sale cada cita ---')
        for seccion in sorted(cit, key=lambda s: [int(x) for x in s.split('.')]):
            marca = '' if seccion in dec or seccion.split('.')[0] in dec else '  HUÉRFANA'
            print(f'  §{seccion:<8} {len(cit[seccion]):>3} citas{marca}')

    if huerfanas:
        print('\n--- citadas desde el código y no declaradas en docs/ ---')
        print('Cada una manda a un lector a buscar algo que no está. Se arregla en')
        print('la dirección que corresponda: mover la cita, o escribir la sección.')
        for seccion in sorted(huerfanas, key=lambda s: [int(x) for x in s.split('.')]):
            sitios = huerfanas[seccion]
            print(f'\n  §{seccion}')
            for sitio in sitios[:6]:
                print(f'      {sitio}')
            if len(sitios) > 6:
                print(f'      … y {len(sitios) - 6} más')
        return 1

    print('\nNinguna cita del código apunta a una sección que no existe.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
