#!/usr/bin/env python3
"""
Qué §§ cita el código y no existen en la documentación.

    python3 tools/secciones-citadas.py            # solo las huérfanas
    python3 tools/secciones-citadas.py --todas    # y de dónde sale cada cita
    python3 tools/secciones-citadas.py --autoprueba   # que el patrón sigue leyendo bien

Existe porque **los comentarios de este repo citan secciones de `docs/` por su
número** —1.254 citas a 227 secciones distintas, medido el 23 ago 2026— y esas
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
LAS CUATRO TRAMPAS DEL PATRÓN, y las cuatro costaron un número publicado:

0. **Un encabezado no es una línea: es un identificador y un título.** Buscar el
   número en la línea entera lee `## §140 — 500 en vez del boletín` como el rango
   140–500: **361 secciones fantasma y un «cero huérfanas» que parecía limpio.**
   Ver `identificador()`. Va primera porque es la que **tapa a las otras tres**:
   con ella puesta, ninguna huérfana llega a salir.
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
# **La raya del rango es la corta (U+2013) y va pegada. Sólo ésa.** Medido en
# `docs/ app/ tests/ tools/` el 23 ago 2026: **93 rangos con `–` pegada, 0 con `—`
# larga y 0 con `-` ASCII.** Aceptar las otras dos no compra ningún caso real y
# cuesta 261 fantasmas el día que alguien escriba `## §NNN—400 en vez de 403` sin
# espacios — que es lo mismo que ya escribió una vez con ellos. (El ejemplo lleva
# `NNN` y no un número: **esta herramienta se lee a sí misma**, y un § con dígitos
# aquí dentro sería una cita más — se cazó sola la primera vez que se escribió.)
#
# Es la única vez en todo esto que la respuesta es **estrechar**, y se justifica
# igual que las demás: **por la población medida, no por la intuición.** 93 a 0.
DECLARA = re.compile(r'^#+\s*(?:[\d.]+\s*)?§\s?(\d+)(?:–(\d+))?')

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


def identificador(cabecera):
    """La mitad del encabezado que numera, sin el título.

    **Un encabezado no es una línea: es un identificador y un título, separados
    por un guion largo con espacios.** Buscar el número en la línea entera es lo
    que hace que `## §140 — 500 en vez del boletín` se lea como el rango
    140–500 — **361 secciones fantasma, y un «cero huérfanas» que parecía
    limpio**. Pasó aquí, en la primera versión de esto.

    El guion de un rango va **pegado** (`§125–126`) y el que separa el título va
    **con espacios** (`§140 — 500…`). Esa es toda la diferencia, y basta.
    """
    return re.split(r'\s+[—–-]\s+', cabecera, maxsplit=1)[0]


def normaliza(n):
    """`08` y `8` son el mismo; `112.1` conserva su padre."""
    partes = n.split('.')
    return '.'.join(str(int(p)) for p in partes)


def declara(linea):
    """Qué secciones declara UN encabezado. Vacío si no declara ninguna."""
    if not linea.startswith('#'):
        return []
    cabecera = identificador(linea)
    m = DECLARA.match(cabecera)
    if m:
        desde = int(m.group(1))
        hasta = int(m.group(2)) if m.group(2) else desde
        return [str(n) for n in range(desde, hasta + 1)]
    m = DECLARA_SIN_SIMBOLO.match(cabecera)
    if m:
        return [normaliza(m.group(1))]
    return []


def declaradas():
    vistas = {}
    for base, _, ficheros in os.walk(DOCS):
        for fichero in ficheros:
            if not fichero.endswith('.md'):
                continue
            ruta = os.path.join(base, fichero)
            for i, linea in enumerate(open(ruta, encoding='utf-8', errors='replace'), 1):
                for n in declara(linea):
                    vistas.setdefault(n, []).append(f'{ruta}:{i}')
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


# Cada trampa con **cuántas secciones debe declarar**, que es lo que se comprueba.
# El número esperado es el dato: «no revienta» no distingue 1 de 261.
#
# **Las trampas se montan con `S` y no con el símbolo escrito.** Esta herramienta
# se lee a sí misma —`tools/` está en la lista— y un `§` seguido de dígitos aquí
# dentro **es una cita**, no un ejemplo: la primera versión de esto se denunció a
# sí misma ocho veces. Escrito así, el fichero no contiene ninguna.
S = '\u00a7'
TRAMPAS = [
    (f'## {S}300 — 500 en vez del boletín', 1, 'raya larga CON espacios: separa título, no declara rango'),
    (f'## {S}320–322 — un rango de verdad', 3, 'raya corta PEGADA: sí es rango'),
    (f'## {S}330 – 400 en vez de 403', 1, 'raya corta CON espacios: sigue separando título'),
    (f'## {S}340—600 raya larga PEGADA', 1, 'la raya larga no marca rango en este repo: 93 a 0'),
    (f'## {S}350-420 guion ASCII PEGADO', 1, 'el guion ASCII tampoco'),
    (f'## {S}360 · sin guion ninguno', 1, 'el caso llano'),
    ('### 27.4 El candado es por (año, periodo)', 1, 'la otra forma de declarar, sin el símbolo'),
    (f'Texto suelto que menciona la {S}370 por la mitad', 0, 'no es encabezado: no declara'),
]


def autoprueba():
    """Mete cabeceras trampa por el lado de las DECLARACIONES.

    **Un `NNN` inventado entra por el lado de las citas y no dice nada del otro.**
    Una herramienta que cruza dos poblaciones tiene **dos lados que pueden
    fallar**, y aquí el fallo caro es el del mapa —declarar de más—, que es justo
    el que hace que la alarma **calle**: con 361 secciones fantasma dentro,
    cualquier cita cae en algo declarado y la salida dice «cero huérfanas».
    Pasó, y se publicó.

    Así que se inyecta **por el lado que produce el silencio**, y se comprueba el
    número que aporta cada trampa, no que no reviente.

    **Y demuestra que los dos guardas hacen falta**, porque parecen redundantes y
    no lo son. Quitando `identificador()` fallan las dos trampas *con espacios*
    —201 y 71 fantasmas—; ensanchando la raya de `DECLARA` fallan las dos
    *pegadas* —261 y 71—. Cada guarda caza exactamente lo que el otro deja pasar,
    y comprobado quitándolos, no leyéndolos.
    """
    fallos = 0
    for linea, esperado, porque in TRAMPAS:
        dio = len(declara(linea))
        marca = 'ok ' if dio == esperado else 'MAL'
        if dio != esperado:
            fallos += 1
        print(f'  {marca}  declara {dio:>3} (esperado {esperado:>3})  {linea}')
        print(f'         {porque}')
    print('\n  ' + ('todas las trampas dan lo que deben.' if not fallos
                    else f'{fallos} trampa(s) mal: el mapa está declarando lo que no es.'))
    return 1 if fallos else 0


def main():
    os.chdir(RAIZ)
    if '--autoprueba' in sys.argv:
        return autoprueba()
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
