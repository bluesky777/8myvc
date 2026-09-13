#!/usr/bin/env python3
"""
Qué instantáneas de contrato llevan dentro la fila ENTERA de una tabla — o sea,
cuáles se mueven el día que esa tabla gane una columna.

Contesta la pregunta de `docs/migracion/30-lo-que-reparte-una-columna-nueva.md`
antes de escribir la migración, y no se puede contestar leyendo el código: para
saberlo hay que cruzar **las columnas vivas de la tabla** con **el juego de claves
de cada objeto de cada instantánea**, a cualquier profundidad del JSON. Este
proyecto lee con `SELECT *` por todas partes, así que una columna nueva aparece
sola en respuestas que nadie tocó.

Nació el 13 sep 2026 midiendo la Fase 1 del modelo de evaluación: se había
afirmado que una columna nueva en `years` movía ~30 instantáneas, luego que 3, las
dos veces **por inspección**. Son 3, y el corte que lo demuestra es lo que esta
herramienta imprime:

    70 de 70 columnas  ->  3 instantáneas   (la fila entera: `SELECT *`)
    36 de 70 columnas  -> 18 instantáneas   (`Year::datos()`, proyección nombrada)
    0                  -> 104

**Nada entre medias**, y por eso no hay umbral que interpretar: o la respuesta
publica la fila o publica una lista de campos elegidos a mano.

COMPROBADO CONTRA UN CASO REAL YA OCURRIDO, que es lo único que lo acredita
--------------------------------------------------------------------------
`grupos.ih` entró el 7 sep 2026 y movió instantáneas de verdad. Pasado este
script por `grupos`, contesta **4**: las tres que hubo que arreglar después en
`95a0c31` —«las tres que `grupos.ih` movió y un `--filter` no podía ver»— **más
`grupos-show.json`, que se había arreglado el mismo día, dentro del commit de la
migración (`5bc035d`)**. O sea que reproduce el conjunto entero, incluida la que
no aparece en el relato porque no llegó a romperse.

Y de paso explica el «3 contra 4»: el commit cuenta **las que se escaparon**, no
las que la columna toca. Son preguntas distintas y las dos respuestas son ciertas.

Hay una tercera, y por eso este script NO sirve tal cual para reconstruir un caso
pasado: entre los dos commits se tocaron **cinco** instantáneas, y la quinta
—`grupos-grupos-cant-alumnos.json`— se movió por otro motivo. Su consulta es una
**proyección nombrada** y el diff de `5bc035d` le añade `g.ih` **a mano**:

    - SELECT g.id, g.nombre, g.abrev, g.orden, ..., g.cupo,
    + SELECT g.id, g.nombre, g.abrev, g.orden, ..., g.cupo, g.ih,

O sea que esa no la movió la columna: la movió **una decisión de publicarla**. Este
script contesta *«¿qué se mueve si añado la columna y no toco nada más?»*, que es
la pregunta que hay que hacerse **antes** de escribir la migración. Lo que un
commit pasado movió incluye además lo que alguien decidió enseñar ese día, y eso
no se deduce del esquema: se lee en su diff.

LO QUE ESTA HERRAMIENTA NO CONTESTA, y hay que leerlo pegado a su salida
-----------------------------------------------------------------------
Cuenta **cobertura, no exposición**: instantáneas que se mueven, no respuestas
que ganan la columna. **No son lo mismo y el mismo día ya costó una cifra.** En
`years` hay **ocho** métodos que publican la fila entera y sólo **tres** tienen
instantánea: `postStore`, `putGuardarCambios`, `deleteDelete`, `deleteDestroy` y
`putRestore` devuelven el modelo Eloquent completo y **ningún test mira su
cuerpo**, sólo su código de estado. Esas cinco respuestas reparten la columna
nueva **sin que ninguna suite se ponga roja**.

O sea: un `3` aquí dice *«tres ficheros que regenerar»*. **No** dice *«tres
respuestas afectadas»*. Para eso hay que leer los `return` del controlador, que
es trabajo de ojo y no de script.

Y una segunda ausencia: sólo ve lo que alguien decidió fotografiar. Una tabla sin
una sola instantánea sale `0` y ese cero significa *«nadie mira esto»*, que es lo
contrario de *«esto no se publica»*.

POR QUÉ LA BASE VIVA Y NO EL VOLCADO
------------------------------------
Las columnas salen de `SHOW COLUMNS` contra la base de **tests**, no de
`database/schema/mysql-schema.sql`. El volcado está congelado y no trae lo que
añaden las migraciones: el 13 sep 2026 la misma tabla daba **74** en la base de
desarrollo, **70** en la de tests, **68** en un docblock envejecido y **64** en el
volcado — las cuatro ciertas sobre bases distintas. La que vale es contra la que
corren los tests, que es la que van a ver las instantáneas.

Uso:
    tools/lo-que-reparte-una-columna.py years
    tools/lo-que-reparte-una-columna.py grupos --base simonbolivar_testing_b
    tools/lo-que-reparte-una-columna.py years --columnas id,year,nombre_colegio

    DB_TEST_DATABASE=simonbolivar_testing_x tools/lo-que-reparte-una-columna.py years
"""

import argparse
import json
import os
import pathlib
import subprocess
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
INSTANTANEAS = RAIZ / 'tests' / 'Contrato' / 'Snapshots'

# Los dos cortes, y **ninguno de los dos es cosmético**. Los dos salieron de
# correr este script el día que se escribió, no de pensarlo:
#
# ENTERA no es `== total` porque el caso normal de uso es justo el que rompe esa
# igualdad: añades la columna, y entonces la tabla tiene N+1 mientras las
# instantáneas, hechas antes, traen N. Con `==` el script contesta **cero** a la
# única pregunta para la que existe. Con 0,90 el corte real —70 de 71 contra 36
# de 71— sigue siendo un abismo.
#
# PROYECCION existe porque sin suelo cualquier objeto que comparta UNA clave
# común —`id`, `year`, `orden`— entraba como «proyección nombrada»: 99 de 125
# instantáneas, o sea ruido con cara de hallazgo. Las proyecciones de verdad de
# este repo rondan la mitad de la tabla.
#
# SUELO es el mismo problema en tablas pequeñas: con 18 columnas, el 20 % son
# 3 claves, y tres nombres corrientes (`id`, `nombre`, `orden`) los comparte medio
# esquema. Sin suelo, `grupos` daba 83 «inmunes» de 125. El montón de en medio es
# orientación, no veredicto: **el número que este script contesta es el de arriba.**
ENTERA = 0.90
PROYECCION = 0.20
SUELO = 8


def columnas_vivas(tabla: str, base: str) -> list[str]:
    """Las columnas que la base de tests tiene HOY, migraciones incluidas."""
    # Sin comillas invertidas alrededor de los identificadores: las ejecutaría
    # el `sh` del contenedor como sustitución de orden y el error sale disfrazado
    # de «base que no existe». Medido el 13 sep 2026 al estrenar el script.
    orden = (f'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B '
             f'-e "SHOW COLUMNS FROM {base}.{tabla}"')
    r = subprocess.run(
        ['docker', 'exec', '8myvc-database-1', 'sh', '-c', orden],
        # `errors='replace'`: la salida de `SHOW COLUMNS` trae los DEFAULT tal
        # cual, y este esquema tiene valores en español que no son UTF-8 válido
        # (una `ñ` en latin-1). Sin esto el script revienta con UnicodeDecodeError
        # a mitad de una tabla y parece un fallo de docker.
        capture_output=True, text=True, errors='replace')
    filas = [l.split('\t')[0] for l in r.stdout.splitlines() if l.strip()]
    if not filas:
        sys.exit(f"No pude leer las columnas de `{base}`.`{tabla}`.\n"
                 f"  ¿Existe la base? ¿Corre el contenedor 8myvc-database-1?\n"
                 f"  stderr: {r.stderr.strip()[:300]}\n"
                 f"  Si no hay docker, pásalas con --columnas.")
    return filas


def solapes(nodo, columnas: set[str], ruta: str = '(raiz)'):
    """Cada objeto del JSON, con cuántas columnas de la tabla tiene."""
    if isinstance(nodo, dict):
        yield ruta, len(set(nodo.keys()) & columnas), len(nodo)
        for clave, valor in nodo.items():
            yield from solapes(valor, columnas, f'{ruta}.{clave}')
    elif isinstance(nodo, list):
        for i, valor in enumerate(nodo):
            yield from solapes(valor, columnas, f'{ruta}[{i}]')


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[1])
    p.add_argument('tabla')
    p.add_argument('--base', default=os.environ.get('DB_TEST_DATABASE',
                                                    'simonbolivar_testing'))
    p.add_argument('--columnas', help='lista separada por comas, en vez de SHOW COLUMNS')
    p.add_argument('--instantaneas', default=str(INSTANTANEAS))
    args = p.parse_args()

    cols = ([c.strip() for c in args.columnas.split(',') if c.strip()]
            if args.columnas else columnas_vivas(args.tabla, args.base))
    columnas = set(cols)
    total = len(columnas)

    ficheros = sorted(pathlib.Path(args.instantaneas).glob('*.json'))
    if not ficheros:
        sys.exit(f'Ninguna instantánea en {args.instantaneas} — población vacía, '
                 f'que no es lo mismo que «no hay ninguna afectada».')

    enteras, parciales, ilegibles = [], [], []
    for f in ficheros:
        try:
            datos = json.loads(f.read_text(encoding='utf-8'))
        except Exception as e:                                  # noqa: BLE001
            ilegibles.append((f.name, str(e)[:80]))
            continue
        mejor = max(solapes(datos, columnas), key=lambda s: s[1],
                    default=('(raiz)', 0, 0))
        ruta, n, claves = mejor
        if n >= total * ENTERA:
            enteras.append((f.name, ruta, n, claves))
        elif n >= max(total * PROYECCION, SUELO):
            parciales.append((f.name, ruta, n, claves))

    origen = 'pasadas a mano' if args.columnas else f'vivas en `{args.base}`'
    print(f'\nPoblación: {len(ficheros)} instantáneas en {args.instantaneas}, '
          f'{total} columnas de `{args.tabla}` ({origen}).\n')

    print(f'Con la fila ENTERA (>={int(total * ENTERA)}/{total}) — '
          f'LAS QUE SE MUEVEN: {len(enteras)}')
    for nombre, ruta, _n, claves in sorted(enteras):
        print(f'    {nombre:<48} {ruta}   ({claves} claves)')

    print(f'\nCon proyección nombrada — INMUNES: {len(parciales)}')
    for nombre, ruta, n, _c in sorted(parciales, key=lambda x: (-x[2], x[0])):
        print(f'    {n:>3}/{total}  {nombre:<44} {ruta}')

    print(f'\nSin `{args.tabla}` dentro (o con menos de '
          f'{int(total * PROYECCION)} claves suyas, que es coincidencia de nombres): '
          f'{len(ficheros) - len(enteras) - len(parciales) - len(ilegibles)}')
    if ilegibles:
        print(f'\nILEGIBLES (no revisadas, no son un cero): {len(ilegibles)}')
        for nombre, err in ilegibles:
            print(f'    {nombre}: {err}')

    print(f'\n*** {len(enteras)} es COBERTURA, no exposición: son los ficheros que hay '
          f'que regenerar,\n    no las respuestas que ganan la columna. En `years` '
          f'son 3 y 8. Ver la cabecera.')


if __name__ == '__main__':
    main()
