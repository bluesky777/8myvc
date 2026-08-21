#!/usr/bin/env python3
"""
Métodos que frenan la escritura y responden como si no la hubieran frenado.

Contesta una pregunta que no se puede contestar leyendo, porque la forma es
invisible en el sitio donde está: **un `if` de permiso que envuelve el cuerpo
entero del método y no tiene `else`**. Quien no cumple la condición no escribe
nada —eso está bien— pero recibe un 200, a veces con un `['Guardado.']` dentro.

    if ($this->user->is_superuser) {
        ... todo el método ...
    }
    return ['Guardado.'];        <-- para todos los demás, esto es lo único

Y una respuesta que dice que sí cuando fue que no **es peor que un error**,
porque el que la lee deja de mirar. Se comprobó con el front: en el gestor de
archivos, `publicarImagen` enseña «Ahora la imagen es pública» en cualquier
respuesta correcta, así que un alumno pulsaba el botón, le decían que sí, y la
imagen seguía privada.

De aquí salió la §37 de docs/migracion/05-codigo-muerto-y-roto.md.

**Lee la forma, no el significado**, así que hay que mirar cada resultado: un
método puede abortar en un sitio que este script no reconozca. La primera versión
daba catorce falsos positivos en `MatriculasController` porque buscaba el `else`
en la línea siguiente al cierre del `if`, y en este proyecto el `} else {` va en
la misma línea del cierre. El criterio de ahora —«el cuerpo no contiene ni `else`
ni `abort(`»— es más burdo y por eso deja fuera algún caso real: `putUpdate` de
profesores tenía un `abort(422)` en su `catch` y no salía. Se encontró leyendo
al lado.

Uso, desde la raíz del proyecto:

    python3 tools/respuestas-que-mienten.py
"""

import re
import pathlib
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent / 'app' / 'Http' / 'Controllers'

# Las condiciones que son de permiso y no de negocio. Una lista corta a
# propósito: ampliarla mete ruido y lo que se busca es una forma concreta.
# `->tipo ==` y no `tipo ==`: el segundo casa también con `$tipo == "img_perfil"`,
# que es una condición de negocio —qué campo del pedido se rechaza— y no de
# permiso. Salió al ensanchar el preámbulo de abajo: con una sola línea de
# preámbulo el `$tipo = Request::input('tipo')` tapaba el `if` y el falso positivo
# no llegaba a verse. Ensanchar un detector enseña dónde era flojo.
PERMISO = re.compile(r'^\s*if\s*\(.*(is_superuser|esAdministrativo|esSuperusuario|->tipo\s*==)')

# Lo que puede haber ANTES del `if` sin que cuente como cuerpo del método:
# comentarios y asignaciones de una línea. Se buscan a mano y no con «cualquier
# cosa» a propósito: si se admitiera una llamada con efectos, el `if` dejaría de
# envolver el método entero y el hallazgo sería otro.
PREAMBULO = re.compile(r'^\s*(//|/\*|\*|\$\w+(\s*\[[^\]]*\])?\s*=\s*[^;]*;\s*$)')


def metodos(lineas):
    """Cada método público del fichero, con su cuerpo completo."""
    for i, linea in enumerate(lineas):
        m = re.match(r'^\s*public function (\w+)\s*\(', linea)
        if not m:
            continue

        profundidad, k, cuerpo = 0, i, []
        while k < len(lineas):
            cuerpo.append(lineas[k])
            profundidad += lineas[k].count('{') - lineas[k].count('}')
            k += 1
            if k > i + 1 and profundidad <= 0:
                break

        yield m.group(1), i + 1, cuerpo


def main():
    hallazgos = []

    for fichero in sorted(RAIZ.rglob('*.php')):
        lineas = fichero.read_text(encoding='utf-8').split('\n')

        for nombre, numero, cuerpo in metodos(lineas):
            texto = '\n'.join(cuerpo)
            resto = [x for x in cuerpo[1:] if x.strip() not in ('{', '')]

            if not resto:
                continue

            # Lo que hay antes del `if` de permiso y no es cuerpo: comentarios y
            # asignaciones sueltas. Antes se saltaba **una sola línea**, y por eso
            # este script no encontró la §44 —`putCambiarFotoUnUsuario` resuelve al
            # usuario y ADEMÁS busca la persona a la que le cambia la foto, dos
            # líneas—, que tuvo que salir leyendo. Ver 05 §48.
            i = 0
            while i < len(resto) and PREAMBULO.match(resto[i]):
                i += 1

            if i >= len(resto) or not PERMISO.match(resto[i]):
                continue

            if 'else' in texto or 'abort(' in texto:
                continue

            hallazgos.append(f'{fichero.stem}::{nombre}  (línea {numero})')

    for h in hallazgos:
        print(h)

    print(f'\n{len(hallazgos)} métodos que frenan la escritura y responden 200')

    return 1 if hallazgos else 0


if __name__ == '__main__':
    sys.exit(main())
