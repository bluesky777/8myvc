#!/usr/bin/env python3
"""
En que profundidad de bucle vive cada consulta, metodo a metodo.

    tools/consultas-en-bucle.py app/Http/Controllers/Informes/*.php

**Contesta «cuantos bucles hay ENCIMA de esta consulta», no «cuantas consultas
hay».** Una consulta a profundidad 0 se ejecuta una vez por peticion; a
profundidad 1, una por alumno; a profundidad 2, una por (alumno x asignatura).
El 504 del boletin final (05 §210, §224) estaba entero en las de profundidad
>= 1 dentro de un metodo que YA se llama desde un bucle.

Existe porque mirar nueve cuerpos de metodo a ojo es justo donde se cuela el
error, y porque el patron del 504 esta COPIADO en ocho ficheros mas: la
pregunta que hay que poder repetir no es «esta roto este» sino «quien mas hace
esto mismo».

## LO QUE NO VE, y va aqui arriba porque cambia como se leen sus numeros

**Cuenta la profundidad DENTRO del metodo, no la del programa.** Si el metodo
se llama desde un `foreach` de alumnos —que es el caso de
`definitivasMateriasXPeriodo` y de `asignaturasPerdidasDeAlumno`—, lo que aqui
sale a profundidad 2 se ejecuta una vez por (alumno x asignatura x periodo).
**Sus numeros ORDENAN candidatos; no son coste.** El coste se mide ejecutando,
con `tests/Barrido/CosteDelGemeloDeLaRaizTest.php`.

Y no dice nada de si el metodo es ALCANZABLE. La 05 §224 lo pago: el fichero
con el peor numero de todos —`Informes/CertificadosPersonaController`, ocho
consultas en bucle y dos a profundidad 2— **no tiene ruta que llegue**.

## POR QUE LLEVA CONTROL, y como se corre

**Este detector dio 0 dos veces antes de dar un numero, y las dos veces el 0
era creible.** Primero cerraba cada metodo en su propia linea de declaracion
—0 consultas sobre un fichero con 13 `DB::select`—; despues descartaba cada
bucle en la linea en que lo abria —26 consultas y 0 en bucle—, porque la
cabecera de un `foreach` no abre nivel por si misma: lo abre su `{`, que puede
estar en la linea siguiente.

Lo cazo tener una respuesta conocida delante, no releerlo. El control esta
abajo y se corre asi:

    tools/consultas-en-bucle.py --control

Compara `Informes/BolfinalesController.php` antes y despues del commit que lo
curo (`2837171`): tiene que dar **10 consultas en bucle antes y 4 despues**,
con las dos de profundidad 2 desapareciendo. Si eso no sale, el detector esta
roto otra vez y sus tablas no valen.

Imprime SIEMPRE su poblacion. Un «0 encontrados» sin poblacion no distingue
«revise y no habia» de «no revise».
"""
import re, sys, pathlib

CONSULTA = re.compile(r'\bDB::(?:select|insert|update|delete|statement|table)\b'
                      r'|\b\w+::(?:datos|alumnos|detailed_materias|hastaPeriodo\w*)\s*\(')
ABRE_BUCLE = re.compile(r'\bforeach\s*\(|\bwhile\s*\(|\bfor\s*\(')
METODO = re.compile(r'^\s*(?:public|private|protected|static|\s)*function\s+(\w+)\s*\(')

def sin_texto(linea):
    linea = re.sub(r"'(?:[^'\\]|\\.)*'", "''", linea)
    linea = re.sub(r'"(?:[^"\\]|\\.)*"', '""', linea)
    linea = re.sub(r'//.*$', '', linea)
    linea = re.sub(r'#.*$', '', linea)
    return linea

def analizar(ruta):
    lineas = pathlib.Path(ruta).read_text(errors='replace').split('\n')
    metodo = None; base = 0; llaves = 0; abierto = False
    prof = []            # niveles de llave a los que VUELVE cada bucle abierto
    pendiente = None     # cabecera de bucle vista, esperando su `{`
    filas = []; nmet = 0; ncons = 0
    for i, cruda in enumerate(lineas, 1):
        linea = sin_texto(cruda)
        m = METODO.match(linea)
        if m and metodo is None:
            metodo, base, prof, pendiente, abierto = m.group(1), llaves, [], None, False
            nmet += 1
        if metodo:
            for c in CONSULTA.finditer(linea):
                ncons += 1
                filas.append((metodo, i, len(prof), c.group(0).strip()))
        # Una cabecera de bucle NO abre nivel por si misma: lo abre su `{`, que
        # puede estar en esta linea o en la siguiente. Sin esta distincion el
        # bucle se descartaba en su propia linea y todo salia a profundidad 0.
        if ABRE_BUCLE.search(linea):
            pendiente = llaves
        llaves += linea.count('{') - linea.count('}')
        if pendiente is not None and llaves > pendiente:
            prof.append(pendiente); pendiente = None
        while prof and llaves <= prof[-1]:
            prof.pop()
        if metodo is not None:
            if not abierto and llaves > base:
                abierto = True
            elif abierto and llaves <= base:
                metodo = None
    return filas, nmet, ncons

def control():
    """La respuesta conocida: el mismo fichero antes y despues de `2837171`."""
    import subprocess, tempfile, os
    ruta = 'app/Http/Controllers/Informes/BolfinalesController.php'
    antes = subprocess.run(['git', 'show', f'2837171^:{ruta}'],
                           capture_output=True, text=True)
    if antes.returncode != 0:
        print('CONTROL NO CONCLUYENTE: no se pudo leer 2837171^ '
              '(¿worktree sin ese commit?). NO uses las tablas sin esto.')
        return 2
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False) as f:
        f.write(antes.stdout); tmp = f.name
    try:
        n_antes = len([x for x in analizar(tmp)[0] if x[2] >= 1])
        n_ahora = len([x for x in analizar(ruta)[0] if x[2] >= 1])
    finally:
        os.unlink(tmp)
    print(f'  antes de 2837171: {n_antes} consultas en bucle  (se esperan 10)')
    print(f'  despues:          {n_ahora} consultas en bucle  (se esperan 4)')
    if (n_antes, n_ahora) == (10, 4):
        print('  OK — el detector reconoce un arreglo que ya se sabe que ocurrio.')
        return 0
    print('  ROTO — no reproduce la respuesta conocida. Sus tablas NO valen.')
    return 1


if __name__ == '__main__':
    if '--control' in sys.argv[1:]:
        sys.exit(control())

    tot_f = tot_m = tot_c = tot_h = 0
    for ruta in sys.argv[1:]:
        filas, nmet, ncons = analizar(ruta)
        calientes = [f for f in filas if f[2] >= 1]
        tot_f += 1; tot_m += nmet; tot_c += ncons; tot_h += len(calientes)
        print(f'\n=== {ruta}')
        print(f'    {nmet} metodos, {ncons} consultas, {len(calientes)} dentro de algun bucle')
        for met, ln, p, q in calientes:
            print(f'    prof {p}  {met}():{ln}  {q}')
    print(f'\nPOBLACION: {tot_f} ficheros, {tot_m} metodos, {tot_c} consultas, {tot_h} en bucle.')
