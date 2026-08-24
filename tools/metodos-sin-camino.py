#!/usr/bin/env python3
"""
Qué métodos públicos de controlador NO alcanza ningún camino desde una ruta.

    python3 tools/metodos-sin-camino.py            # desde la raíz del repo
    python3 tools/metodos-sin-camino.py --csv

## La pregunta, y por qué es la contraria de la que ya sabíamos contestar

`tools/cobertura-de-rutas.py` va de la ruta hacia abajo: dice qué rutas tienen su
respuesta comprobada. Esa dirección **no puede ver un método que nadie llama** —
no aparece, y eso se lee igual que «ahí no hay problema».

Esto va al revés: parte de **todos** los métodos públicos y pregunta cuáles no
tiene forma de alcanzar ninguna ruta. Salió de que `8myvc-ad` encontrara un
controlador de 510 líneas con **una docena alcanzables**, y de que su propia
herramienta, por construcción, no pudiera medirlo.

## «Sin ruta» NO es «muerto», y el ámbito existe por eso

`App\\Services\\ContextoDeUsuario::construir` **no tiene ruta** y está en el camino
de **toda petición autenticada** (`construir ← para ← User::fromToken()`). Si el
criterio fuera «ningún `Route::` lo nombra», los servicios saldrían todos y serían
lo más vivo del proyecto.

Por eso el ámbito es **`app/Http/Controllers/` y nada más**: ahí «no llega ningún
camino desde una ruta» sí significa algo, porque **un método público de controlador
existe para ser enrutado**. En un servicio o un modelo no significa nada.

Y por eso lo que se calcula es el **cierre transitivo**, no un salto: un método al
que sólo llama un huérfano es huérfano. Medido el 24 ago 2026, esa diferencia son
**41 contra 48** — y una búsqueda global de llamadas, que es lo primero que se
prueba, decía **15**.

## Las cinco trampas que costó, todas medidas

1. **Un fichero con cuatro clases.** `Alumnos/ImportarController.php` declara
   `AlumnoSheetImport`, `AlumnosImport`, `ExcelUtils` e `ImportarController`.
   Tomando sólo la primera declaración por fichero, sus métodos se atribuyen a la
   clase equivocada y el detector **inventa «rutas a métodos que no existen»**:
   dio 4 y son **0**.
2. **Dos clases con el mismo nombre corto.** `BolfinalesController` existe en la
   raíz y en `Informes/`. Con la clave por nombre corto, una **colapsa** a la otra
   y se lleva sus métodos: 646 métodos contra los **657** reales.
3. **`$this->x()` es por clase, no global.** Este repo copia el mismo método en
   ocho controladores, así que buscar el nombre en todo el proyecto dice
   «llamado» porque lo llama **otra** clase. `asignaturasPerdidasDeAlumnoPorPeriodo`
   está definido en 8 y sin llamar en 6.
4. **`new C()` y luego `$v->m()`.** No se puede seguir sin análisis de tipos, y es
   el patrón de los ayudantes de este repo: `GuardarAlumno::valor` —el método que
   escribe las propiedades de matrícula, en el camino de `PUT alumnos/guardar-valor`—
   salía **inalcanzable**. Aquí se resuelve **grueso a propósito**: si una clase se
   instancia con `new` en código alcanzable, sus métodos llamados con `->m(` en
   cualquier parte pasan a alcanzables. En una lista de candidatos a borrar,
   equivocarse hacia «vivo» cuesta una revisión y hacia «muerto» cuesta un endpoint.
5. **Las interfaces de `vendor/`.** `collection`, `headingRow`, `sheets`,
   `registerEvents`, `chunkSize` los llama **la librería de Excel**, no nuestro
   código. Se separan por el `implements` de su clase, no se cuentan como muertos.

## Lo que este detector NO contesta

**Sigue llamadas, no ramas.** Un método invocado sólo dentro de un `if` que nunca
se cumple cuenta como alcanzable. Esto responde a la alcanzabilidad **entre**
métodos; la de **dentro** de un método es otra pregunta y no la mide nadie.

Y **una llamada dinámica invalidaría el cierre**. Medido: en este proyecto hay 6
usos de `->{...}`, y **los seis son acceso a propiedades** de objetos de datos
(`$fila->{...}`), no despacho de métodos. Si algún día aparece un
`$this->{$metodo}()`, este número deja de valer y hay que decirlo.

**No es una lista de borrados: es una lista de candidatos.** La regla de la casa
pide comprobar que ningún cliente resucite el camino, y esta misma noche apareció
un método muerto con el nombre casi idéntico a uno vivo.
"""
import re, os, sys, csv, json, collections

RAIZ = 'app/Http/Controllers'


def sin_comentarios(t):
    return re.sub(r'/\*.*?\*/|//[^\n]*|(?<!\$)#[^\n]*',
                  lambda m: re.sub(r'[^\n]', ' ', m.group(0)), t, flags=re.S)


def inventario():
    """(cuerpos, metodos, publicos, corto2fqn, implementa, ficheros)."""
    cuerpos, metodos, publicos = {}, {}, set()
    corto2fqn = collections.defaultdict(list)
    implementa = {}
    ficheros = 0
    for base, _d, fs in os.walk(RAIZ):
        for f in sorted(fs):
            if not f.endswith('.php'):
                continue
            ruta = os.path.join(base, f)
            ficheros += 1
            t = sin_comentarios(open(ruta, encoding='utf-8', errors='replace').read())
            ns = (re.search(r'namespace\s+([\w\\]+)\s*;', t) or [None, ''])[1]
            # TODAS las declaraciones del fichero, no la primera: trampa 1.
            decls = [(m.start(), m.group(1), m.group(2))
                     for m in re.finditer(r'\b(class|trait|interface)\s+(\w+)', t)]
            for m in re.finditer(r'\bclass\s+(\w+)[^\n{]*implements\s+([^{\n]+)', t):
                implementa[f'{ns}\\{m.group(1)}'] = m.group(2).strip()
            for _p, k, n in decls:
                if k == 'class':
                    corto2fqn[n].append(f'{ns}\\{n}')
            ms = list(re.finditer(
                r'\n[ \t]*(public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*\(', t))
            for i, mm in enumerate(ms):
                fin = ms[i + 1].start() if i + 1 < len(ms) else len(t)
                previas = [d for d in decls if d[0] < mm.start()]
                if not previas or previas[-1][1] != 'class':
                    continue
                # La clave lleva el namespace: trampa 2.
                clave = (f'{ns}\\{previas[-1][2]}', mm.group(2))
                cuerpos[clave] = t[mm.start():fin]
                metodos[clave] = (ruta, t.count('\n', 0, mm.start()) + 1,
                                  t[mm.start():fin].count('\n'))
                if (mm.group(1) or 'public') == 'public':
                    publicos.add(clave)
    return cuerpos, metodos, publicos, corto2fqn, implementa, ficheros


def semillas_de_rutas():
    """Los pares (FQN, metodo) que nombra `routes/`, resueltos por su `use`."""
    fuera = set()
    for base, _d, fs in os.walk('routes'):
        for f in sorted(fs):
            if not f.endswith('.php'):
                continue
            t = open(os.path.join(base, f), encoding='utf-8', errors='replace').read()
            usos = {m.group(2): m.group(1) + '\\' + m.group(2)
                    for m in re.finditer(r'use\s+([\w\\]+)\\(\w+)\s*;', t)}
            for m in re.finditer(r'\[\s*(\w+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]', t):
                fuera.add((usos.get(m.group(1), m.group(1)), m.group(2)))
    return fuera


def llamadas_por_flecha():
    """Todo `->m(` del proyecto. Para el `new C(); $v->m()` de la trampa 4."""
    texto = ''
    for raiz in ('app', 'routes', 'resources', 'database', 'config'):
        if not os.path.isdir(raiz):
            continue
        for b, _d, fs in os.walk(raiz):
            for f in fs:
                if f.endswith(('.php', '.blade.php')):
                    texto += open(os.path.join(b, f), encoding='utf-8', errors='replace').read()
    return set(re.findall(r'->\s*(\w+)\s*\(', texto)), texto


def main():
    if not os.path.isdir(RAIZ):
        sys.exit(f'ERROR: no existe ./{RAIZ}/ — se corre desde la raíz del repo.')

    cuerpos, metodos, publicos, corto2fqn, implementa, ficheros = inventario()
    semillas = semillas_de_rutas()
    flecha, proyecto = llamadas_por_flecha()

    # Sólo despacho DINÁMICO DE MÉTODO: `->{...}(` con paréntesis detrás, o
    # `call_user_func`. Sin el paréntesis esto cuenta los `$fila->{...}` de
    # acceso a propiedades —seis en este proyecto— y **el centinela se dispara
    # siempre**, que enseña a ignorarlo y es peor que no tenerlo. Es la misma
    # falta que contar la línea `Duration:` como un test lento.
    dinamicas = re.findall(r'->\s*\{[^}\n]*\}\s*\(|::\s*\$\w+\s*\(|call_user_func',
                           proyecto)

    # Cierre transitivo desde las rutas.
    alc = {s for s in semillas if s in metodos}
    frontera = set(alc)
    while frontera:
        nueva = set()
        for k in frontera:
            c = cuerpos.get(k, '')
            for m in re.finditer(r'\$this->\s*(\w+)\s*\(|(?:self|static)::\s*(\w+)\s*\(', c):
                n = m.group(1) or m.group(2)
                if (k[0], n) in metodos and (k[0], n) not in alc:
                    nueva.add((k[0], n))
            for m in re.finditer(r'\b(\w+)::\s*(\w+)\s*\(', c):
                for o in corto2fqn.get(m.group(1), []):
                    if (o, m.group(2)) in metodos and (o, m.group(2)) not in alc:
                        nueva.add((o, m.group(2)))
            for m in re.finditer(r'\bnew\s+(\w+)\b', c):
                for o in corto2fqn.get(m.group(1), []):
                    for (cc, mth) in metodos:
                        if cc == o and mth in flecha and (cc, mth) not in alc:
                            nueva.add((cc, mth))
        alc |= nueva
        frontera = nueva

    inalc = sorted(k for k in publicos if k not in alc and k[1] != '__construct')
    de_iface = {k for k in inalc if implementa.get(k[0])}
    candidatos = [k for k in inalc if k not in de_iface]

    if '--csv' in sys.argv:
        w = csv.writer(sys.stdout)
        w.writerow(['fichero', 'linea', 'lineas', 'clase', 'metodo'])
        for k in candidatos:
            r, l, n = metodos[k]
            w.writerow([r, l, n, k[0], k[1]])
        return

    print(f'Población — ámbito: ./{RAIZ}/ y nada más (ver la cabecera).')
    print(f'  ficheros: {ficheros}   clases: {sum(len(v) for v in corto2fqn.values())}   '
          f'métodos: {len(metodos)}   públicos: {len(publicos)}')
    print(f'  pares (clase,método) nombrados en routes/ : {len(semillas)}')
    print(f'  rutas a métodos que no existen            : {len(semillas - set(metodos))}')
    for k in sorted(semillas - set(metodos)):
        print(f'      {k[0]}::{k[1]}')
    print(f'  alcanzables por cierre transitivo         : {len(alc)}')
    print(f'  llamadas dinámicas (invalidarían el cierre): {len(dinamicas)}'
          + ('  <-- REVISAR' if dinamicas else '  (ninguna: el cierre vale)'))
    print()
    print(f'  públicos sin camino desde ninguna ruta    : {len(inalc)} '
          f'({sum(metodos[k][2] for k in inalc)} líneas)')
    print(f'    de ésos, implementan interfaz de vendor : {len(de_iface)}  (los llama la librería)')
    for k in sorted(de_iface):
        print(f'        {k[0].split(chr(92))[-1]}::{k[1]}  implements {implementa[k[0]]}')
    print()
    print(f'  CANDIDATOS — no es una lista de borrados  : {len(candidatos)} '
          f'({sum(metodos[k][2] for k in candidatos)} líneas)')
    for k in sorted(candidatos, key=lambda k: -metodos[k][2]):
        r, l, n = metodos[k]
        print(f'    {n:>4} líneas  {r.replace(RAIZ + "/", "")}:{l}  '
              f'{k[0].split(chr(92))[-1]}::{k[1]}')


if __name__ == '__main__':
    main()
