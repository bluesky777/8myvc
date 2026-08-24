#!/usr/bin/env python3
"""De las rutas no-`GET` que parecen no escribir, cuáles escriben **por un ayudante**.

    python3 tools/quien-escribe-de-verdad.py              # el resumen
    python3 tools/quien-escribe-de-verdad.py --rutas      # y cada ruta, con su veredicto
    python3 tools/quien-escribe-de-verdad.py --autoprueba # que el analizador lee bien

Es la segunda mitad de `verbos-que-mienten.py`, y existe porque ese número lo dice
de sí mismo: *«**cuenta de más** si el método llama a un servicio o a un modelo que
sí escribe —aquí `User::`, `Nota::` y compañía escriben—. Confirmar es leer el
método»*. Esto lee el método **y a quién llama**, hasta tres niveles.

O sea que aquí el detector auditado **no es el código: es la otra herramienta**, y
eso es exactamente lo que pide CLAUDE.md — *el primer sitio donde mirar cuando el
número sale raro es el detector*. Aquella cuenta candidatas; ésta las resuelve.

───────────────────────────────────────────────────────────────────────────────
LO QUE ESTO PUEDE DECIDIR Y LO QUE NO, que es la mitad del valor

**Puede** seguir tres formas de llamada, que son las que usa este repositorio:

    Clase::metodo(...)        estática, la más común aquí (`User::`, `Nota::`)
    $this->metodo(...)        dentro de la misma clase
    $x->metodo(...)           cuando `$x = new Clase` está en el mismo cuerpo

**No puede** decidir cuando la llamada es dinámica —una variable que llega por
parámetro, un `Facade` que no sea `DB`, un `call_user_func`— y **eso no se
esconde**: cada ruta cuyo veredicto dependa de una llamada sin resolver sale
listada como `NO DECIDIBLE`, con el nombre del método que no se pudo seguir. Un
«sólo lectura» que en realidad era «no lo pude ver» es justo el fallo que esta
familia de herramientas comete, así que se separan.

**Y hay una escritura que ninguna de las dos ve, porque no está en el
controlador**: toda petición autenticada de esta API actualiza
`personal_access_tokens.last_used_at`. O sea que **ninguna de estas rutas es de
sólo lectura a nivel de PETICIÓN**, aunque su método lo sea. Se midió en
`tests/Contrato/HistorialDeSesionCuentaDeMenosTest.php` (§186.1) y cambia para qué
sirve el número: vale para *clasificar qué escribe un endpoint*, **no** para
montar una réplica de sólo lectura.

───────────────────────────────────────────────────────────────────────────────
DOS COSAS DEL ANALIZADOR, y las dos son correcciones a cómo se hacía

1. **El cuerpo del método se delimita contando llaves, no cortando hasta la
   siguiente `function`.** Cortar por la siguiente `function` mete dentro el
   docblock de la de al lado y, peor, **parte el cuerpo por la mitad** en cuanto
   hay una función anidada con nombre — y deja el resto sin mirar. Contar llaves
   exige saltarse cadenas y comentarios, y aquí eso no es teórico: este
   repositorio lleva **990 consultas crudas** en cadenas de varias líneas.

2. **Se busca la escritura por dos vías, no una.** Las marcas de llamada
   (`DB::insert`, `->save(`…) y, aparte, **el SQL de escritura dentro de una
   cadena** (`INSERT INTO`, `UPDATE … SET`, `DELETE FROM`). La segunda existe
   porque en esta API la consulta y quien la ejecuta pueden estar separados: una
   cadena con `DELETE FROM` es una intención de escribir **aunque la ejecute algo
   que no está en la lista de marcas**.
───────────────────────────────────────────────────────────────────────────────
"""
import re
import sys
import pathlib
import collections

RAIZ = pathlib.Path(__file__).resolve().parent.parent
PROFUNDIDAD = 3

# Vía 1: la llamada que escribe.
MARCAS = (
    'DB::insert', 'DB::update', 'DB::delete', 'DB::statement', 'DB::unprepared',
    'DB::table', '->save(', '->delete(', '->restore(', '->update(', '->forceDelete(',
    '::create(', '->create(', '->increment(', '->decrement(', '->insert(',
    '->insertGetId(', '->updateOrInsert(', '->firstOrCreate(', '->updateOrCreate(',
    '->truncate(', '->attach(', '->detach(', '->sync(', '->saveMany(', '->push(',
    'Schema::', 'Request::merge',
)

# Vía 2: el SQL de escritura escrito a mano, ejecute quien lo ejecute.
SQL_ESCRIBE = re.compile(
    r'\b(INSERT\s+INTO|REPLACE\s+INTO|DELETE\s+FROM|TRUNCATE\s+TABLE|UPDATE\s+`?\w+`?\s+SET)\b',
    re.I)

# `DB::getPdo()` da un PDO crudo, con el que se puede escribir. `lastInsertId()`
# no escribe, así que esto NO es una marca: es un motivo para no decidir.
PDO_CRUDO = 'DB::getPdo()'


def sin_ruido(txt):
    """El mismo texto con cadenas y comentarios sustituidos por espacios.

    Hace falta para contar llaves: una `{` dentro de un `'...'` de SQL descuadra
    el conteo y el cuerpo del método sale cortado donde no toca. Se conserva la
    longitud exacta para que los índices sigan sirviendo sobre el texto original.
    """
    fuera = list(txt)
    i, n = 0, len(txt)
    while i < n:
        c = txt[i]
        if c in ('"', "'"):
            cierre, j = c, i + 1
            while j < n:
                if txt[j] == '\\':
                    j += 2
                    continue
                if txt[j] == cierre:
                    break
                j += 1
            for k in range(i, min(j + 1, n)):
                fuera[k] = ' '
            i = j + 1
        elif txt.startswith('//', i) or txt[i] == '#':
            j = txt.find('\n', i)
            j = n if j == -1 else j
            for k in range(i, j):
                fuera[k] = ' '
            i = j
        elif txt.startswith('/*', i):
            j = txt.find('*/', i)
            j = n if j == -1 else j + 2
            for k in range(i, j):
                fuera[k] = ' '
            i = j
        else:
            i += 1
    return ''.join(fuera)


def sin_comentarios(txt):
    """El mismo texto con los comentarios en blanco y **las cadenas intactas**.

    Es la tercera vista y hace falta porque las dos vías de este detector quieren
    cosas distintas y ninguna quiere comentarios:

      - `sin_ruido` quita cadenas **y** comentarios → para las marcas de llamada;
      - ésta quita **sólo** los comentarios → para el SQL crudo, que vive dentro de
        una cadena y por tanto no puede buscarse en la vista anterior.

    **La escribió un falso positivo, y de los caros.** La primera versión buscaba
    el SQL en el texto entero, y `AcudientesController::putDatos` tiene un bloque
    `/* … */` que empieza con *«esta consulta me sirvió para eliminar parentescos
    que quedaron al importar de Excel»* seguido de un `delete from` de varias
    líneas. El detector lo leyó como una escritura y **movió la ruta al cajón
    equivocado** — o sea que un comentario que explica una limpieza que se hizo una
    vez a mano se contaba como código que escribe.

    Es la misma trampa que la de las marcas, por el otro lado: **una herramienta
    que busca texto en código necesita saber qué parte del texto es código**, y la
    respuesta no es la misma para cada cosa que busca.
    """
    fuera = list(txt)
    i, n = 0, len(txt)
    while i < n:
        c = txt[i]
        if c in ('"', "'"):
            cierre, j = c, i + 1
            while j < n:
                if txt[j] == '\\':
                    j += 2
                    continue
                if txt[j] == cierre:
                    break
                j += 1
            i = j + 1                      # la cadena se conserva tal cual
        elif txt.startswith('//', i) or txt[i] == '#':
            j = txt.find('\n', i)
            j = n if j == -1 else j
            for k in range(i, j):
                fuera[k] = ' '
            i = j
        elif txt.startswith('/*', i):
            j = txt.find('*/', i)
            j = n if j == -1 else j + 2
            for k in range(i, j):
                fuera[k] = ' '
            i = j
        else:
            i += 1
    return ''.join(fuera)


RE_CLASE = re.compile(r'^\s*(?:final\s+|abstract\s+)?class\s+(\w+)', re.M)
RE_METODO = re.compile(r'function\s+(\w+)\s*\(')


def metodos_de(txt):
    """`nombre -> cuerpo`, delimitando por llaves sobre el texto sin cadenas."""
    limpio = sin_ruido(txt)
    salida = {}
    for m in RE_METODO.finditer(limpio):
        abre = limpio.find('{', m.end())
        if abre == -1:
            continue                      # firma de interfaz o método abstracto
        nivel, i, n = 0, abre, len(limpio)
        while i < n:
            if limpio[i] == '{':
                nivel += 1
            elif limpio[i] == '}':
                nivel -= 1
                if nivel == 0:
                    break
            i += 1
        salida[m.group(1)] = txt[m.start():i + 1]
    return salida


def escribe_directo(cuerpo):
    """Qué marca de escritura tiene este cuerpo, si tiene alguna.

    **Las dos vías necesitan vistas OPUESTAS del mismo texto, y confundirlas es un
    fallo real que cazó la autoprueba de este fichero:**

      - las **marcas de llamada** se buscan en el texto **sin cadenas ni
        comentarios**. Un `"no escribe: 'DB::insert' es texto"` o un `DB::insert`
        citado en un docblock no son escrituras, y buscándolos en el texto crudo
        contaban como tales — o sea que el detector movía rutas al cajón «escribe»
        por hablar de escribir;
      - el **SQL crudo** se busca en el texto **sin comentarios pero con cadenas**,
        porque vive justamente dentro de una cadena. Buscarlo en el texto limpio no
        encuentra nada nunca —esa vía quedaría muerta sin que nada fallara— y
        buscarlo en el texto entero cuenta los `/* … */` que **explican** una
        limpieza que alguien hizo a mano una vez. Las dos versiones existieron y las
        dos estaban mal; ver `sin_comentarios()`.

    La primera versión hacía las dos sobre el texto crudo: la vía 1 contaba de más
    por hablar de escribir, y la vía 2 por citar SQL en un comentario.
    """
    for mk in MARCAS:
        if mk in sin_ruido(cuerpo):
            return mk

    m = SQL_ESCRIBE.search(sin_comentarios(cuerpo))
    return ('SQL crudo: ' + re.sub(r'\s+', ' ', m.group(1)).upper()) if m else None


RE_ESTATICA = re.compile(r'\b([A-Z]\w+)::(\w+)\s*\(')
RE_THIS = re.compile(r'\$this->(\w+)\s*\(')
RE_NEW = re.compile(r'\$(\w+)\s*=\s*new\s+([A-Z]\w+)')
RE_VAR = re.compile(r'\$(\w+)->(\w+)\s*\(')
RE_NEW_DIRECTO = re.compile(r'\(\s*new\s+([A-Z]\w+)[^)]*\)\s*\)?->(\w+)\s*\(')

# La cuarta forma, y la que faltaba: `app(Sesion::class)->abrir(...)`. Sin ella
# `auth/login` salía **no decidible** —un «no lo pude ver» que se lee igual que un
# «no escribe»— cuando es el escritor más obvio de toda la API: crea el token y la
# sesión. La encontró el propio informe: seis rutas sin decidir, tres de ellas de
# `SesionController`, y al leerlas a mano las tres llamaban así.
RE_APP = re.compile(r'(?:app|resolve)\(\s*([A-Z]\w+)::class\s*\)\s*->\s*(\w+)\s*\(')

# Éstas no son código del repositorio: seguirlas no aporta y ensucia el «no
# decidible». `DB` va aparte porque sus escrituras ya son marcas.
AJENAS = {'DB', 'Request', 'Carbon', 'Response', 'Log', 'Cache', 'Storage', 'Hash',
          'Auth', 'Config', 'Str', 'Arr', 'Mail', 'Validator', 'Excel', 'PDF',
          'JWTAuth', 'Schema', 'File', 'Session', 'Cookie', 'URL', 'Route',
          'Artisan', 'Event', 'Queue', 'Crypt', 'App', 'Http', 'Exception'}


def llamadas(cuerpo, clase_propia, texto_clase='', clases=None):
    """A quién llama este cuerpo: `(clase, metodo)` resueltas, y las que no."""
    resueltas, sin_resolver = set(), set()
    limpio = sin_ruido(cuerpo)

    for clase, metodo in RE_ESTATICA.findall(limpio):
        if clase in AJENAS:
            continue
        # `Alumno::where`, `Nota::find`… los hereda Eloquent y no están escritos
        # aquí. Ver DEL_FRAMEWORK: no añaden incertidumbre.
        if metodo in DEL_FRAMEWORK and clases is not None \
                and metodo not in clases.get(clase, {}).get('metodos', {}):
            continue
        resueltas.add((clase, metodo))

    for metodo in RE_THIS.findall(limpio):
        resueltas.add((clase_propia, metodo))

    for clase, metodo in RE_NEW_DIRECTO.findall(limpio):
        if clase not in AJENAS:
            resueltas.add((clase, metodo))

    for clase, metodo in RE_APP.findall(limpio):
        if clase not in AJENAS:
            resueltas.add((clase, metodo))

    tipos = {var: clase for var, clase in RE_NEW.findall(limpio)}
    for var, clase in tipos_declarados(texto_clase, cuerpo).items():
        tipos.setdefault(var, clase)

    for var, metodo in RE_VAR.findall(limpio):
        if var == 'this':
            continue
        if var in tipos and tipos[var] not in AJENAS:
            resueltas.add((tipos[var], metodo))
        else:
            sin_resolver.add(f'${var}->{metodo}()')

    if PDO_CRUDO in cuerpo:
        sin_resolver.add('DB::getPdo() — PDO crudo')

    return resueltas, sin_resolver


# `$sesion->cerrar()` no se puede seguir por el nombre de la variable, pero **sí
# por su tipo declarado**: este repositorio inyecta servicios con propiedades y
# parámetros tipados (`private Sesion $sesion`, `function x(Sesion $sesion)`). Sin
# esto, los cuatro endpoints de `SesionController` salían «no decidible» y son
# escritores obvios — un «no lo pude ver» que se lee igual que un «no escribe».
RE_PROP_TIPADA = re.compile(r'(?:private|protected|public|readonly)\s+(?:readonly\s+)?\??([A-Z]\w+)\s+\$(\w+)')
RE_PARAM_TIPADO = re.compile(r'\??([A-Z]\w+)\s+\$(\w+)\s*[,)=]')

# Los `Model::where`, `Model::find`, `Model::destroy`… no están escritos en el
# repositorio: los hereda Eloquent. **Y no añaden incertidumbre**, que es la razón
# de no contarlos como «no decidible»: lo que decide si esa cadena escribe es su
# llamada FINAL —`->update(`, `->delete(`, `->save(`— y ésas ya están en MARCAS y
# se buscan en el mismo cuerpo. Un `Model::where(...)->get()` es una lectura y el
# detector ya lo sabe por lo que NO encontró.
DEL_FRAMEWORK = {'where', 'find', 'findOrFail', 'first', 'firstOrFail', 'all',
                 'destroy', 'query', 'with', 'whereIn', 'select', 'orderBy', 'count'}


def tipos_declarados(txt_clase, cuerpo):
    """`variable -> Clase`, a partir de las propiedades y parámetros tipados."""
    tipos = {}
    for clase, var in RE_PROP_TIPADA.findall(sin_ruido(txt_clase)):
        tipos[var] = clase
    for clase, var in RE_PARAM_TIPADO.findall(sin_ruido(cuerpo)):
        tipos.setdefault(var, clase)
    return tipos


def indexar():
    clases = {}
    for f in sorted((RAIZ / 'app').rglob('*.php')):
        txt = f.read_text(errors='replace')
        ms = metodos_de(txt)
        for m in RE_CLASE.finditer(txt):
            clases[m.group(1)] = {'fichero': f, 'metodos': ms, 'texto': txt}
    return clases


def veredicto(clases, clase, metodo, visto=None, nivel=0):
    """`('escribe', camino)` · `('lee', None)` · `('no decidible', motivo)`."""
    if visto is None:
        visto = set()
    if (clase, metodo) in visto or nivel > PROFUNDIDAD:
        return ('lee', None)
    visto.add((clase, metodo))

    info = clases.get(clase)
    if info is None or metodo not in info['metodos']:
        return ('no decidible', f'{clase}::{metodo} no se encuentra')

    cuerpo = info['metodos'][metodo]

    marca = escribe_directo(cuerpo)
    if marca:
        return ('escribe', f'{clase}::{metodo} [{marca}]')

    resueltas, sin_resolver = llamadas(cuerpo, clase, info.get('texto', ''), clases)
    dudas = []

    for c, m in sorted(resueltas):
        if c not in clases:
            continue                       # clase de fuera del repo
        estado, detalle = veredicto(clases, c, m, visto, nivel + 1)
        if estado == 'escribe':
            return ('escribe', f'{clase}::{metodo} → {detalle}')
        if estado == 'no decidible':
            dudas.append(detalle)

    for s in sorted(sin_resolver):
        dudas.append(f'{clase}::{metodo} llama a {s}')

    return ('no decidible', dudas[0]) if dudas else ('lee', None)


RE_RUTA = re.compile(
    r"Route::(get|post|put|patch|delete)\(\s*'([^']+)'\s*,\s*\[\s*(\w+)::class\s*,\s*'(\w+)'\s*\]")

# `DB::select` **ejecuta** lo que se le dé: PDO no mira el verbo, así que un
# `INSERT` pasado por ahí entra igual y devuelve un array vacío. O sea que en este
# repositorio **no sólo miente el verbo HTTP: miente el nombre del método de la
# base**. Se cuenta aparte porque es la lista que importa para la auditoría y para
# cualquier separación lectura/escritura: un `DB::select` va al **lector**.
RE_EJECUTOR = re.compile(r'DB::(select|selectOne|insert|update|delete|statement|unprepared|table)\b')


def escribe_por_select(cuerpo):
    """El cuerpo lleva SQL de escritura y **el único ejecutor que tiene es `select`**.

    Se exige «el único» a propósito. Un método con un `INSERT` en una cadena y un
    `DB::insert` al lado no dice nada raro; el que importa es el que **no tiene
    ninguna forma reconocible de escribir** y aun así escribe.
    """
    if not SQL_ESCRIBE.search(sin_comentarios(cuerpo)):
        return False

    ejecutores = set(RE_EJECUTOR.findall(sin_ruido(cuerpo)))

    return bool(ejecutores) and ejecutores <= {'select', 'selectOne'}


def autoprueba():
    """Que el analizador delimita el cuerpo donde debe.

    **Las trampas van por el lado del ANALIZADOR y no por el de las marcas**, que
    es la lección de `secciones-citadas.py`: una herramienta que cruza dos
    poblaciones tiene dos lados que pueden fallar, y meter algo falso por el lado
    fácil no dice nada del otro. Aquí el lado que se rompe solo es el que delimita
    el cuerpo: si corta corto, la escritura queda fuera y el veredicto es «lee».
    """
    casos = [
        ("""<?php class A {
            function uno() { $q = 'DELETE FROM x WHERE id={1}'; return 1; }
            function dos() { return 2; }
        }""", 'uno', True, 'una llave DENTRO de una cadena no corta el cuerpo'),

        ("""<?php class A {
            function uno() {
                $f = function () { return 1; };
                DB::insert('x');
            }
        }""", 'uno', True, 'un cierre anónimo dentro no corta el cuerpo'),

        ("""<?php class A {
            /** DB::insert en el DOCBLOCK, que no escribe */
            function uno() { return 1; }
        }""", 'uno', False, 'una marca en un comentario no cuenta como escritura'),

        ("""<?php class A {
            function uno() { $s = "no escribe: 'DB::insert' es texto"; return $s; }
        }""", 'uno', False, 'una marca dentro de una cadena tampoco'),

        ("""<?php class A {
            function uno() { return 1; }
            function dos() { DB::delete('x'); }
        }""", 'uno', False, 'la escritura de la de al lado no se cuenta en ésta'),

        # El caso real que obligó a escribir `sin_comentarios()`: un bloque que
        # EXPLICA una limpieza hecha a mano, no código que la haga.
        ("""<?php class A {
            function uno() {
                /* Esta consulta me sirvio para eliminar parentescos:
                delete from parentescos where id in (select 1) */
                return 1;
            }
        }""", 'uno', False, 'SQL de escritura dentro de un /* comentario */ no cuenta'),

        ("""<?php class A {
            function uno() { $q = 'delete from parentescos where id=?'; return $q; }
        }""", 'uno', True, 'SQL de escritura dentro de una CADENA sí cuenta'),
    ]

    print('\nAUTOPRUEBA — el analizador, no las marcas')
    print('  ' + '-' * 74)
    fallos = 0
    for fuente, metodo, espera, porque in casos:
        ms = metodos_de(fuente)
        cuerpo = ms.get(metodo, '')
        obtuvo = escribe_directo(cuerpo) is not None
        ok = obtuvo == espera
        fallos += 0 if ok else 1
        print(f'  {"ok  " if ok else "FALLA"} escribe={obtuvo!s:<5} (esperado {espera!s:<5})  {porque}')
    print()
    print('  todas dan lo que deben.' if not fallos else f'  {fallos} trampas mal leídas.')
    return fallos


if '--autoprueba' in sys.argv:
    sys.exit(1 if autoprueba() else 0)

clases = indexar()

total = no_get = 0
sin_resolver_ruta = 0
buckets = {'escribe': [], 'lee': [], 'no decidible': []}
ya_escribe_directo = 0

for rf in sorted((RAIZ / 'routes').rglob('*.php')):
    for verbo, uri, clase, metodo in RE_RUTA.findall(rf.read_text(errors='replace')):
        total += 1
        if verbo == 'get':
            continue
        no_get += 1

        info = clases.get(clase)
        if info is None or metodo not in info['metodos']:
            sin_resolver_ruta += 1
            continue

        cuerpo = info['metodos'][metodo]

        # Las que ya escriben en su propio cuerpo no son candidatas: son las que
        # `verbos-que-mienten.py` descarta antes de contar. Se cuentan para poder
        # cuadrar la población con la suya.
        if escribe_directo(cuerpo):
            ya_escribe_directo += 1
            continue

        estado, detalle = veredicto(clases, clase, metodo)
        buckets[estado].append((verbo.upper(), uri, f'{clase}::{metodo}', detalle))

candidatas = sum(len(v) for v in buckets.values())

print(f'\nPOBLACIÓN: {total} rutas leídas de routes/, {no_get} no son GET')
print(f'  clases indexadas de app/ ......................... {len(clases)}')
print(f'  método no resuelto (herencia, closure, recurso) ... {sin_resolver_ruta}')
print(f'  escriben en su PROPIO cuerpo ...................... {ya_escribe_directo}')
print(f'  candidatas a sólo lectura ........................ {candidatas}'
      '   <- la población de verbos-que-mienten.py')
print()
print('DE LAS CANDIDATAS, SIGUIENDO LAS LLAMADAS')
print('  ' + '-' * 74)
print(f'  escriben por un ayudante ......................... {len(buckets["escribe"])}')
print(f'  sólo lectura hasta donde se puede ver ............ {len(buckets["lee"])}')
print(f'  NO DECIDIBLE (una llamada sin resolver) .......... {len(buckets["no decidible"])}')
print()
print('  Y ninguna de las tres es de sólo lectura a nivel de PETICIÓN: toda petición')
print('  autenticada escribe `personal_access_tokens.last_used_at` (§186.1).')

# ── Y el otro nombre que miente: `DB::select` ────────────────────────────────
por_select = []

for clase, info in sorted(clases.items()):
    for metodo, cuerpo in sorted(info['metodos'].items()):
        if escribe_por_select(cuerpo):
            m = SQL_ESCRIBE.search(sin_comentarios(cuerpo))
            por_select.append((clase, metodo, re.sub(r'\s+', ' ', m.group(1)).upper()))

enrutadas = {(c, m) for _, _, cm, _ in
             (buckets['escribe'] + buckets['lee'] + buckets['no decidible'])
             for c, m in [cm.split('::')]}

print()
print('EL OTRO NOMBRE QUE MIENTE: escrituras que viajan por `DB::select`')
print('  ' + '-' * 74)
print(f'  métodos de app/ con SQL de escritura cuyo ÚNICO ejecutor es select ... {len(por_select)}')
print('  `DB::select` ejecuta lo que se le dé —PDO no mira el verbo— y devuelve un')
print('  array vacío. Así que no sólo miente el verbo HTTP: miente el nombre del')
print('  método de la base, y **una separación lectura/escritura manda esto al lector**.')

if por_select:
    print()
    for clase, metodo, sql in por_select:
        marca = '  (ruta no-GET)' if (clase, metodo) in enrutadas else ''
        print(f'    {clase}::{metodo}  [{sql}]{marca}')

if '--rutas' in sys.argv:
    for nombre, titulo in [
            ('escribe', 'ESCRIBEN POR UN AYUDANTE — el verbo no miente, la herramienta sí'),
            ('no decidible', 'NO DECIDIBLE — hay que leerlas a mano, y aquí está por qué'),
            ('lee', 'SÓLO LECTURA hasta donde se puede ver')]:
        print()
        print(titulo)
        print('  ' + '-' * 74)
        for verbo, uri, m, detalle in sorted(buckets[nombre]):
            print(f'  {verbo:<6} {uri:<46} {m}')
            if detalle:
                print(f'         {detalle}')

print()
