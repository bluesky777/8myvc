#!/usr/bin/env python3
"""
Comparacion LAXA sobre un valor del CLIENTE que gobierna una ESCRITURA.

    tools/verdad-laxa-que-escribe.py $(find app/Http/Controllers -name '*.php')

Las TRES condiciones a la vez, y la interseccion es lo que lo hace util. No es el
censo de `== true` del repo —ese es largo y casi todo inofensivo—: es el sitio
donde **una cadena cualquiera vale por «si»** y quien la manda cree que dice
«no». En PHP toda cadena no vacia que no sea '0' es cierta, asi que `"false"`
entra en el `if`.

Es el fallo del contador de certificados (05 §231, §232), y ahi quema un
documento oficial.

## QUE CUENTA COMO «LAXA», y por que se estrecho

Solo un test de VERDAD:  `if ($x)` · `if (!$x)` · `== true` · `!empty($x)`.
**`if ($x == '')` tambien es laxo y NO entra**: su modo de fallo es otro —«vino o
no vino»— y no convierte un «no» en un «si». Con `== ''` y `== 1` dentro salian
56 de 980 `if`; con solo los tests de verdad, 21. *Un filtro que devuelve
cincuenta y seis sitios no es una lista: es otro censo.*

## CONTROL POSITIVO

Tiene que encontrar el `if` del contador de certificados dentro de
`Informes/BolfinalesController::detailedNotasGrupo()` —medido valor a valor en la
§231—. Si no sale ahi, el detector esta roto y su lista no vale.

**Se cita por el NOMBRE DEL METODO y no por el numero de linea, y eso costo un
susto.** La primera version decia `BolfinalesController:85-86`. El 25 ago, con las
cuatro ramas de la noche fundidas, el `if` estaba en la **156** y las lineas 85-86
eran el docblock y la firma de `periodosDelAnio()`. El detector funcionaba —lo
encontraba en la 156 antes y despues del arreglo—; lo roto era **la cita de su
control**, y muerde justo donde no debe: **la unica instruccion que este fichero da
para desconfiar de si mismo apuntaba a un sitio que no era**, asi que el dia que de
verdad se rompa, el control no lo dira.

Un numero de linea envejece con cada commit del fichero que cita. Un nombre de
metodo tambien puede morir, pero **muere ruidosamente**: no lo encuentras.
Lo levanto `8myvc-e0`, que fue a usar el control antes de fiarse del detector.

## POBLACION MEDIDA — 25 ago 2026

Sobre las mismas 112 fuentes:

    main                       980 `if` mirados   ->  21 cumplen las tres
    con el arreglo de CERT-1   983 `if` mirados   ->  20

El -1 es el `== true` del contador, arreglado; el +3 son los `if` de la validacion
nueva, ninguno laxo. **Se anota la poblacion y no solo el resultado**: un «20» sin
los 983 al lado no distingue «revise novecientos y sobrevivio uno menos» de «mire
otra cosa».

## LO QUE NO VE

  - **Solo mira dentro del metodo**: un flag que se pasa a otro metodo y se
    evalua alli no lo sigue.
  - **No distingue que se escribe.** Un `->save()` que elige entre dos campos y
    un `DELETE`+`INSERT` de notas salen iguales. Las 21 filas hay que ordenarlas
    a mano por lo que se pierde si el cliente manda `"false"`, y en la §232 estan
    ordenadas asi: de las 21, **tres sitios** tienen consecuencia real.
  - **No mira quien puede llamarlo.** Eso sale de la ruta, y cambia el orden:
    un `auth.personal` que quema un folio no es lo mismo que uno que alcanza un
    alumno.
"""
import re, sys, pathlib, pathlib

METODO = re.compile(r'^\s*(?:public|private|protected|static|\s)*function\s+(\w+)\s*\(')
DEL_CLIENTE = re.compile(r'Request::(input|get|has|all)\s*\(|\$request->(input|get|has|all)\s*\(')
ASIGNA = re.compile(r'\$(\w+)\s*=\s*[^;]*(?:Request::(?:input|get|has|all)|\$request->(?:input|get|has|all))\s*\(')
ESCRIBE = re.compile(r'\bDB::(insert|update|delete|statement)\s*\(|->save\s*\(\s*\)|->delete\s*\(\s*\)|->forceDelete\s*\(\s*\)')
ESTRICTO = re.compile(r'===|!==|\bis_(?:string|numeric|bool|array)\s*\(|\bin_array\s*\([^)]*,\s*true\s*\)')

def sin_texto(l):
    l = re.sub(r"'(?:[^'\\]|\\.)*'", "''", l)
    l = re.sub(r'"(?:[^"\\]|\\.)*"', '""', l)
    return re.sub(r'//.*$', '', l)

def analizar(ruta):
    lineas = pathlib.Path(ruta).read_text(errors='replace').split('\n')
    metodo = None; vars_cliente = set(); hallazgos = []; nif = 0
    for i, cruda in enumerate(lineas):
        l = sin_texto(cruda)
        m = METODO.match(l)
        if m:
            metodo = m.group(1); vars_cliente = set()
        for a in ASIGNA.finditer(l):
            vars_cliente.add(a.group(1))
        mi = re.match(r'\s*(?:\}\s*else\s*)?if\s*\((.+)\)\s*\{?\s*$', l)
        if not mi:
            continue
        cond = mi.group(1)
        nif += 1
        toca_cliente = bool(DEL_CLIENTE.search(cond)) or any(
            re.search(r'\$'+re.escape(v)+r'\b', cond) for v in vars_cliente)
        if not toca_cliente or ESTRICTO.search(cond):
            continue
        # **La forma exacta que quema: un test de VERDAD sobre el valor del
        # cliente.** `if ($x == '')` tambien es laxo, pero su modo de fallo es
        # otro —«vino o no vino»— y no convierte un «no» en un «si». Aqui solo
        # entra lo que hace que la CADENA "false" valga por «si»:
        #   if ($x)        if (!$x)        == true       !empty($x)
        crudo = re.sub(r'\s+', ' ', cond)
        es_verdad = (
            re.search(r'==\s*true\b|!=\s*false\b', crudo, re.I)
            or re.search(r'!?\s*empty\s*\(', crudo)
            or re.fullmatch(r'!?\s*\$\w+(\s*(&&|and|\|\||or)\s*!?\s*\$\w+)*', crudo.strip())
            or re.fullmatch(r'!?\s*(?:Request::(?:input|get|has)|\$request->(?:input|get|has))\s*\([^()]*\)', crudo.strip())
        )
        if not es_verdad:
            continue
        # el bloque: desde aqui hasta que las llaves vuelvan a cero
        llaves = 0; abierto = False; bloque = []
        for j in range(i, min(i+60, len(lineas))):
            s = sin_texto(lineas[j])
            bloque.append(lineas[j])
            llaves += s.count('{') - s.count('}')
            if not abierto and llaves > 0: abierto = True
            elif abierto and llaves <= 0: break
        txt = '\n'.join(bloque)
        esc = ESCRIBE.findall(txt)
        if esc:
            hallazgos.append((metodo, i+1, re.sub(r'\s+',' ',cruda.strip())[:78],
                              sorted({e[0] if isinstance(e, tuple) else e for e in esc})))
    return hallazgos, nif

def control():
    """El control positivo, EJECUTABLE y anclado en un CASO SINTETICO.

    Hasta CONTROLES-1 esto era prosa que no corria nadie. Y al hacerlo ejecutable
    salio ROJO — pero **ni el detector estaba roto ni la cita estaba vieja**:

    > **El fallo que citaba se habia ARREGLADO.** La cabecera apuntaba al `if` del
    > contador de certificados de `Informes/BolfinalesController`, y el commit
    > `0473a9b` lo paso a `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — o sea que el
    > detector dejo de encontrarlo **porque ya no esta**, que es exactamente lo que
    > tiene que pasar.

    **Un control positivo anclado en un fallo VIVO muere cuando alguien arregla el
    fallo, y entonces parece que el detector se rompio.** El siguiente en pasar
    "arregla" el detector. Es una tercera forma de fallo, distinta de las dos que se
    conocian —el detector roto y la cita vieja— y la mas cara, porque **castiga
    justo a quien arregla el codigo**.

    Por eso el caso va aqui dentro y no en `app/`: **lo que este control tiene que
    comprobar es que el DETECTOR reconoce la forma, no que el repositorio siga
    teniendo el fallo.**

    Salidas: 0 pasa · 1 falla · 2 no concluyente.
    """
    import tempfile, os

    caso = """<?php
class CasoDelControl {
    public function conFormaLaxa() {
        $bandera = Request::input('bandera');
        if ($bandera) {
            DB::update('UPDATE cosas SET x=1');
        }
    }
    public function conFormaEstricta() {
        if (filter_var(Request::input('bandera'), FILTER_VALIDATE_BOOLEAN)) {
            DB::update('UPDATE cosas SET x=1');
        }
    }
    public function laxaQueNoEscribe() {
        if (Request::input('otra')) {
            $x = 1;
        }
    }
}
"""
    fd, ruta = tempfile.mkstemp(suffix='.php')
    with os.fdopen(fd, 'w') as fh:
        fh.write(caso)
    try:
        metodos = {h[0] for h in analizar(ruta)[0]}
    finally:
        os.unlink(ruta)

    fallos = []
    if 'conFormaLaxa' not in metodos:
        fallos.append('NO reconoce la forma laxa que escribe — su lista se queda corta')
    if 'conFormaEstricta' in metodos:
        fallos.append('marca la forma ESTRICTA, que ya no es el fallo — su lista sobra')
    if 'laxaQueNoEscribe' in metodos:
        fallos.append('marca una laxa que NO escribe — le falta la tercera condicion')

    if not fallos:
        print('  OK — reconoce la laxa que escribe, y solo esa.')
        return 0

    print('  ROTO:')
    for f in fallos:
        print(f'    - {f}')
    print(f'  Encontro: {sorted(metodos) or "nada"}.')
    return 1


if __name__ == '__main__':
    if '--control' in sys.argv[1:]:
        sys.exit(control())

    tot_f = tot_if = 0; todos = []
    for ruta in sys.argv[1:]:
        h, nif = analizar(ruta); tot_f += 1; tot_if += nif
        for x in h: todos.append((ruta, *x))
    for ruta, met, ln, cond, esc in todos:
        print(f"  {ruta.replace('app/Http/Controllers/',''):46}:{ln:<5} {met}()")
        print(f"      if ({cond})")
        print(f"      escribe: {esc}")
    print(f"\n  POBLACION: {tot_f} ficheros, {tot_if} sentencias `if` miradas, "
          f"{len(todos)} cumplen las TRES condiciones.")
