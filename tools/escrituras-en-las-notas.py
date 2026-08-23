#!/usr/bin/env python3
"""
Qué métodos escriben en las notas, y cuáles preguntan por el interruptor del periodo.

    python3 tools/escrituras-en-las-notas.py          # desde la raíz del repo

Existe porque la lista de la §27 se construyó al revés y por eso se le escapó
una ruta durante un mes: se inventariaron **los sitios que ya llamaban a
`User::pueden_editar_notas()`** —26, en siete controladores— y un sitio que
nunca preguntó no puede aparecer en una lista hecha así. El que faltaba era
`detalles/eliminar-notas-periodo`, que borra notas con un `DELETE` físico.

    Una lista construida desde la comprobación no puede contener al que nunca
    comprobó.

Esto parte de la **operación**: cualquier INSERT/UPDATE/DELETE cuyo SQL nombre
`notas`, `notas_finales` o `recuperacion_final`. La columna «pregunta» dice si
el método llama a alguno de los dos candados. Los «NO» son sitios donde mirar,
no fallos: al medirlo el 22 ago 2026 salieron 13 métodos y 4 sin preguntar, y
tres de los cuatro estaban ya documentados a propósito (§71, §3.1 del 10, y el
`putDetailed` del 09).

**Dos trampas dentro, las dos vividas y las dos con freno puesto:**

1. Sin quitar los comentarios, cuenta las palabras INSERT y DELETE de un
   **docblock** — y con muy buena pinta, porque el docblock que explica un
   arreglo se escribe justo encima del método que sí escribe, y el recorte por
   `function` se lo cuelga al método **anterior**. En la primera pasada fueron
   4 sitios de 17, y **tres cayeron en la columna «NO pregunta»**, que es
   justo la que se lee. Es la §72.5: un detector que lee el fichero entero
   encuentra también lo que se escribió sobre él.
2. Corrido desde otro directorio contestaba «0 métodos escriben en las notas»
   en vez de «no existe la carpeta». Un cero tiene la misma cara que un
   arreglo. Por eso ahora aborta.
3. **Miraba sólo el SQL crudo y no veía Eloquent.** El repo tiene 990 consultas
   crudas y usa los modelos «marginalmente» (CLAUDE.md), y esa frase es justo
   la que hace que se te olvide el margen: `PeriodosController::putCopiar`
   escribe notas con `new Nota` y `save()`, y la primera versión de este script
   —escrita el mismo día— no la vio. Se encontró **una hora después**, leyendo
   otra cosa. Por eso ahora se buscan las dos formas y la salida dice cuál.
"""
import re, os, sys


def sin_comentarios(texto):
    """Quita //, # y /* */ dejando los saltos de línea para no mover las líneas."""
    def blanquear(m):
        return re.sub(r'[^\n]', ' ', m.group(0))

    return re.sub(r'/\*.*?\*/|//[^\n]*|(?<!\$)#[^\n]*', blanquear, texto, flags=re.S)


def metodos(texto):
    """(nombre, cuerpo) de cada método, cortando en el siguiente `function`."""
    ms = list(re.finditer(r'\n\t*(?:public|protected|private)?\s*function\s+(\w+)\s*\(', texto))
    for i, m in enumerate(ms):
        fin = ms[i + 1].start() if i + 1 < len(ms) else len(texto)
        yield m.group(1), texto[m.start():fin]


TABLAS = ('notas', 'notas_finales', 'recuperacion_final')
ESCRITURA = re.compile(r'\b(INSERT\s+INTO|UPDATE|DELETE)\b', re.I)

# La otra mitad: los modelos que escriben en esas mismas tablas. `new Nota` +
# `save()`, `Nota::create(...)`, `Nota::destroy(...)`. Se pide que el `save()` o
# el `delete()` esté en el mismo método, para no contar una lectura con
# `Nota::find()`.
MODELOS = {'Nota': 'notas', 'NotaFinal': 'notas_finales'}
ELOQUENT = re.compile(r'new\s+(' + '|'.join(MODELOS) + r')\b'
                      r'|(' + '|'.join(MODELOS) + r')::(create|destroy|forceCreate)\b')
GUARDA = re.compile(r'->(save|delete|forceDelete)\s*\(')
CANDADOS = ('pueden_editar_notas', 'pueden_modificar_definitivas')
RAIZ = 'app/Http/Controllers'

if not os.path.isdir(RAIZ):
    sys.exit(f'No existe {RAIZ}: hay que correrlo desde la raíz del repo.')

filas = []
for raiz, _, ficheros in os.walk(RAIZ):
    for f in sorted(ficheros):
        if not f.endswith('.php'):
            continue
        texto = sin_comentarios(open(os.path.join(raiz, f), encoding='utf-8').read())
        for nombre, cuerpo in metodos(texto):
            # Se mira el SQL y no el nombre del método: `putGuardarValor` escribe
            # y el `deleteDestroy` de otro controlador no toca notas.
            escribe = set()
            for sent in ESCRITURA.finditer(cuerpo):
                trozo = cuerpo[sent.start():sent.start() + 400]
                escribe |= {t for t in TABLAS if re.search(r'\b' + t + r'\b', trozo)}

            via = 'SQL' if escribe else ''
            for m in ELOQUENT.finditer(cuerpo):
                if GUARDA.search(cuerpo) or m.group(2):
                    escribe.add(MODELOS[m.group(1) or m.group(2)])
                    via = (via + '+Eloquent') if via else 'Eloquent'

            if escribe:
                filas.append((f[:-4], nombre, ','.join(sorted(escribe)) + ' (' + via + ')',
                              any(c in cuerpo for c in CANDADOS)))

filas.sort(key=lambda r: (r[3], r[0], r[1]))

print(f'{len(filas)} métodos escriben en las notas\n')
print(f'{"controlador":34s} {"método":36s} {"tablas (por dónde)":40s} pregunta')
for c, m, t, p in filas:
    print(f'{c:34s} {m:36s} {t:40s} {"sí" if p else "NO"}')
print(f'\nsin preguntar: {sum(1 for r in filas if not r[3])} de {len(filas)}'
      '   (sitios donde mirar, no fallos)')
