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
    # `static` en medio: sin él, este recorte **no ve ningún método estático**, y en
    # este repo los de los modelos lo son casi todos —`NotaFinal::calcularAsignatura
    # Periodo`, `Nota::verificarCrearNotas`, `NotaComportamiento::crearVerifNota`—.
    # En los controladores no se notaba porque ahí todo es `public function`, así
    # que la ceguera sólo aparece al mirar los modelos. Es la tercera frontera de
    # este script y la única puramente sintáctica: las otras dos son decisiones
    # (qué tablas y qué carpeta).
    # Dos arreglos del 23 ago 2026, y el segundo es el que más asusta:
    #
    # 1. `static` en medio: sin él **no se ve ningún método estático**, y en los
    #    modelos de este repo lo son casi todos. En los controladores no se
    #    notaba porque ahí todo es `public function`.
    # 2. `[ \t]*` en vez de `\t*`: el recorte **sólo veía la indentación con
    #    tabuladores**. `app/Models/NotaFinal.php` va con cuatro espacios —lo
    #    formateó pint— y por eso `alumnos_grupo_nota_final`, que hace un
    #    `DELETE FROM notas_finales`, no aparecía. Y esto es peor que una
    #    ceguera fija: **pint reformatea fichero a fichero según se van tocando**
    #    (CLAUDE.md), así que el detector se iba quedando ciego a medida que
    #    avanzaba la migración, y siempre sobre los ficheros recién tocados —los
    #    que más falta hace mirar.
    ms = list(re.finditer(r'\n[ \t]*(?:public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*\(', texto))
    for i, m in enumerate(ms):
        fin = ms[i + 1].start() if i + 1 < len(ms) else len(texto)
        yield m.group(1), texto[m.start():fin]


# `nota_comportamiento` entra el 23 ago 2026, y por qué faltaba merece leerse: no
# era una ceguera de implementación, era **la definición**. Esta lista se escribió
# el 22 con las tres tablas de la rejilla, y el candado sobre
# `nota_comportamiento` lo decidió Joseth **el 21** —sale en el boletín y el año
# tiene su conmutador (05 §40.2); `PeriodoDeLaFila::deNotaComportamiento` existe
# justo para eso—. La decisión es anterior a la herramienta y nadie volvió a la
# lista.
#
#     Un detector no falla sólo por mirar mal: falla por mirar **lo que se
#     decidió mirar antes de que cambiara la decisión.**
#
# Es la única de las formas que encontró la noche del 22 al 23 que ningún cuidado
# al medir habría evitado, así que lo que hay que dejar montado no es esta línea:
# es releer esta lista cada vez que se meta una tabla bajo el candado.
TABLAS = ('notas', 'notas_finales', 'recuperacion_final', 'nota_comportamiento')
ESCRITURA = re.compile(r'\b(INSERT\s+INTO|UPDATE|DELETE)\b', re.I)

# La otra mitad: los modelos que escriben en esas mismas tablas. `new Nota` +
# `save()`, `Nota::create(...)`, `Nota::destroy(...)`. Se pide que el `save()` o
# el `delete()` esté en el mismo método, para no contar una lectura con
# `Nota::find()`.
MODELOS = {'Nota': 'notas', 'NotaFinal': 'notas_finales',
           'NotaComportamiento': 'nota_comportamiento'}
ELOQUENT = re.compile(r'new\s+(' + '|'.join(MODELOS) + r')\b'
                      r'|(' + '|'.join(MODELOS) + r')::(create|destroy|forceCreate)\b')
GUARDA = re.compile(r'->(save|delete|forceDelete)\s*\(')
CANDADOS = ('pueden_editar_notas', 'pueden_modificar_definitivas', 'permiteEditarNotas')
RAIZ = 'app/Http/Controllers'

# La segunda frontera de la definición, y la que escondía el caso que abrió esto:
# **sólo se miraban los controladores**. Una escritura que vive en un método de
# modelo llamado desde un controlador no la ve nadie, y hay al menos una que
# importa: `NotaComportamiento::crearVerifNota()` hace `firstOrNew` + `save()`, y
# su único llamante —`NotaComportamientoController::getDetailed`, un GET— no
# pregunta por el candado.
#
# Ampliar `ELOQUENT` no bastaba: reconoce `new Modelo` y `Modelo::create`, y esto
# es `Modelo::unMetodoCualquiera()`. Así que se miran también los modelos, y de
# cada método de modelo que escriba se dice **quién lo llama y si el llamante
# pregunta** — que es la pregunta de verdad, porque el candado se pide en el
# controlador y no en el modelo.
RAIZ_MODELOS = 'app/Models'

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


# --- los modelos, y quién los llama ------------------------------------------
#
# El candado se pide en el controlador, así que de un método de modelo que
# escriba lo único que importa es **si el que lo llama pregunta**. Un modelo que
# escribe no es un fallo; un controlador que lo llama sin preguntar, sí es un
# sitio donde mirar.
modelos = []

if os.path.isdir(RAIZ_MODELOS):
    for raiz, _, ficheros in os.walk(RAIZ_MODELOS):
        for f in sorted(ficheros):
            if not f.endswith('.php'):
                continue
            texto = sin_comentarios(open(os.path.join(raiz, f), encoding='utf-8').read())
            for nombre, cuerpo in metodos(texto):
                escribe = set()
                for sent in ESCRITURA.finditer(cuerpo):
                    trozo = cuerpo[sent.start():sent.start() + 400]
                    escribe |= {t for t in TABLAS if re.search(r'\b' + t + r'\b', trozo)}

                via = 'SQL' if escribe else ''
                # Dentro de un modelo, `$x->save()` sobre su propia clase basta:
                # `firstOrNew(...)` + `save()` es la forma de `crearVerifNota`, y
                # no la reconoce ELOQUENT porque no es `new` ni `::create`.
                propio = f[:-4]
                if propio in MODELOS and re.search(r'\b' + propio + r'::(firstOrNew|firstOrCreate|updateOrCreate|create)\b', cuerpo) \
                        and GUARDA.search(cuerpo):
                    escribe.add(MODELOS[propio])
                    via = (via + '+Eloquent') if via else 'Eloquent'

                if escribe:
                    modelos.append((propio, nombre, ','.join(sorted(escribe)) + ' (' + via + ')'))

if modelos:
    # Los llamantes se buscan en los controladores, con el candado del llamante.
    fuente = {}
    for raiz, _, ficheros in os.walk(RAIZ):
        for f in sorted(ficheros):
            if f.endswith('.php'):
                fuente[f[:-4]] = sin_comentarios(open(os.path.join(raiz, f), encoding='utf-8').read())

    print('\nY en los MODELOS, con el candado del que los llama:\n')
    print(f'{"modelo::método":40s} {"tabla (por dónde)":34s} llamantes')
    for modelo, metodo, tabla in modelos:
        llamantes = []
        for ctrl, texto in sorted(fuente.items()):
            for nombre, cuerpo in metodos(texto):
                if re.search(r'\b' + modelo + r'::' + metodo + r'\b', cuerpo):
                    pregunta = 'sí' if any(c in cuerpo for c in CANDADOS) else 'NO'
                    llamantes.append(f'{ctrl}::{nombre} ({pregunta})')
        cola = ', '.join(llamantes) if llamantes else 'ninguno en app/Http/Controllers'
        print(f'{modelo + "::" + metodo:40s} {tabla:34s} {cola}')

    sinPreguntar = sum(1 for _, _, _ in modelos)
    print('\n  Un modelo que escribe no es un fallo. Lo que hay que mirar es el (NO)'
          ' de sus llamantes:\n  el candado se pide en el controlador, no en el modelo.')
