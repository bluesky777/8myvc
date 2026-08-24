#!/usr/bin/env python3
"""
Donde una lectura ACOTADA entrega su resultado a una llamada que NO lo esta.

    python3 tools/alcance-en-los-traspasos.py            # desde la raiz del repo
    python3 tools/alcance-en-los-traspasos.py --control  # comprueba que se detecta a si mismo
    python3 tools/alcance-en-los-traspasos.py --detalle  # con el SQL y la llamada

Es la fase 0 de `BI-3`, y nace de un agujero del METODO y no de un fallo de nadie:
`unidades-sin-alcance.py` clasifica **lecturas**, y una clasificacion por lectura
**no puede ver que una lectura impecable entregue su resultado a una insegura**.
No es su pregunta. Los dos primeros casos aparecieron **solos, sin buscarlos**, en
una muestra de doce (19 §4 de `bi-2.md`):

    $grupo = DB::selectOne('SELECT a.grupo_id FROM unidades u ... WHERE u.id = ?', ...);
    Nota::verificarCrearNotas($grupo->grupo_id, ...);   # crea una nota por alumno del GRUPO

La lectura es de una fila por su id -del cajon «bien»-. Lo que sale de ella es un
`grupo_id`, y lo que lo recibe recorre **el grupo entero**. *El alcance no se pierde
en la lectura: se pierde en el traspaso.*

## QUE BUSCA, exactamente

Una asignacion `$v = DB::select|selectOne(<sql literal>)` cuyo SQL lea de una tabla
**estrecha** filtrando por id, y despues, **en el mismo metodo**, un `$v->campo`
usado **como argumento de una llamada**, donde `campo` es de una dimension **mas
ancha** que la tabla de la que se leyo.

La escala de dimensiones, de estrecha a ancha, y es lo unico que este guion
«sabe» del dominio:

    alumno  <  unidad/subunidad  <  asignatura  <  grupo  <  periodo/year

Subir en esa escala **no es un fallo**: casi siempre es intencionado y correcto.
Es un **sitio donde mirar**, y la pregunta que hay que hacerle a cada fila es
*«¿y si la fila de origen fuera de UN alumno?»*.

## LO QUE NO VE — dicho antes de que alguien lea un 0 como un OK

  - **Solo `DB::select`/`DB::selectOne`.** No ve Eloquent, ni un query builder, ni
    un SQL armado por trozos con `.=`. Es el mismo hueco por el que
    `escrituras-sin-auditoria.php` dijo 32 donde habia 52, y ahi **los cuatro
    controladores que no veia eran cuatro dominios enteros**.
  - Si sigue el SQL guardado en una variable del mismo metodo
    (`$consulta = '...'; DB::select($consulta, ...)`), **y eso no es un adorno**:
    la primera version solo miraba la forma inline y con eso veia **menos del 20%
    del terreno** -133 llamadas inline frente a 558 con variable-. La poblacion
    paso de **17 lecturas a 58** al arreglarlo, **y los hallazgos siguieron siendo
    5**: el terreno se triplico sin aparecer traspasos nuevos, que es lo que hace
    creible el numero.
  - **Solo dentro del mismo metodo.** Si la fila se lee en un sitio y se entrega en
    otro -o pasa por un parametro-, no lo sigue.
  - **No sigue variables intermedias**: `$g = $fila->grupo_id; f($g);` se le escapa.
  - **No sabe si la ampliacion es correcta.** No puede: eso depende de si la fila
    de origen puede tener dueno, y eso lo decide quien lee.

**Asi que su numero es una COTA BAJA por los cuatro lados.** Un 0 aqui no dice
«no hay traspasos»: dice «no hay traspasos DE ESTA FORMA, en `DB::select`, dentro
de un metodo».

## Y una cosa que el `--control` enseno sobre si mismo

La primera version encontraba **1 de los 2** casos conocidos: se le escapaba
`self::recalcular((int) $donde->asignatura_id, ...)` porque su expresion prohibia
cualquier parentesis entre la llamada y la variable, **y un cast es lo bastante
comun para que un detector que lo ignore parezca funcionar**. Lo cazo el control
al primer intento, no una revision. Con el arreglo paso de **1 a 5**: *la primera
version perdia el 80% y su salida no tenia ningun aspecto sospechoso.*

## EL CONTROL ES EJECUTABLE, no una frase

`--control` exige encontrar **los dos casos medidos a mano** en `bi-2.md` §4:

    app/Http/Controllers/SubunidadesController.php   unidades  -> grupo_id
    app/Services/DefinitivasDeAsignatura.php         unidades  -> asignatura_id

y **sale con codigo 1 si falta cualquiera**. Un control positivo escrito en prosa
-«tiene que encontrar X»- es una intencion: **nadie ejecuta una frase**, y esta
noche se vio que uno de esta carpeta llevaba desde que se escribio citando unas
lineas que ya no existian. Este se corre.
"""
import re
import os
import sys

RAIZ = 'app'

# La escala del dominio. Numero mas alto = alcance mas ancho.
NIVEL_TABLA = {
    'alumnos': 1, 'matriculas': 1,
    'subunidades': 2, 'unidades': 2,
    'asignaturas': 3,
    'grupos': 4,
    'periodos': 5, 'years': 5,
}

NIVEL_CAMPO = {
    'alumno_id': 1,
    'subunidad_id': 2, 'unidad_id': 2,
    'asignatura_id': 3,
    'grupo_id': 4,
    'periodo_id': 5, 'year_id': 5,
}

# Los dos que `bi-2.md` §4 midio a mano. El control exige encontrarlos.
CONTROL = [
    ('app/Http/Controllers/SubunidadesController.php', 'grupo_id'),
    ('app/Services/DefinitivasDeAsignatura.php', 'asignatura_id'),
]


def sin_comentarios(texto):
    """Quita //, # y /* */ conservando los saltos de linea, para no mover numeros.

    Sin esto se cuenta el `FROM unidades` de un docblock, y con muy buena cara:
    el comentario que explica una consulta se escribe justo encima de ella.
    """
    texto = re.sub(r'/\*.*?\*/', lambda m: '\n' * m.group(0).count('\n'), texto, flags=re.S)
    texto = re.sub(r'//[^\n]*', '', texto)
    return re.sub(r'(?m)^\s*#[^\n]*', '', texto)


def metodos(codigo):
    """Trozos (nombre, inicio_linea, texto) por metodo. Corte por firma, no por llaves.

    Contar llaves seria mas exacto y mucho mas fragil con heredocs y cadenas que
    llevan `}`. El corte por firma sobra-incluye el final de un metodo en el
    siguiente, lo cual **puede dar un falso positivo pero no un falso negativo**,
    y de los dos errores este es el que se ve al leer la fila.
    """
    marcas = [(m.start(), m.group(1)) for m in
              re.finditer(r'function\s+([A-Za-z_]\w*)\s*\(', codigo)]
    for i, (pos, nombre) in enumerate(marcas):
        fin = marcas[i + 1][0] if i + 1 < len(marcas) else len(codigo)
        yield nombre, codigo[:pos].count('\n') + 1, codigo[pos:fin]


def tabla_estrecha_de(sql):
    """La tabla con nivel MAS BAJO que aparece en el FROM/JOIN, o None.

    Se elige la mas estrecha y no la primera porque lo que importa es **de que
    puede ser la fila**: si el SQL entra por `unidades` y hace join con
    `asignaturas`, la fila sigue siendo de una unidad.
    """
    tablas = re.findall(r'(?:from|join)\s+`?(\w+)`?', sql, re.I)
    niveles = [(NIVEL_TABLA[t], t) for t in tablas if t in NIVEL_TABLA]
    return min(niveles)[1] if niveles else None


def analizar(ruta):
    bruto = open(ruta, encoding='utf-8', errors='replace').read()
    codigo = sin_comentarios(bruto)
    hallazgos, lecturas_vistas = [], 0

    for nombre, base, cuerpo in metodos(codigo):
        # **El SQL de este repositorio casi nunca esta en la llamada.** Medido:
        # 133 `DB::select` con la cadena inline frente a **558 que reciben una
        # variable**, y 100 asignaciones `$consulta = '... WHERE ... id = ?'`. Una
        # version que solo mirara la forma inline veria **menos del 20% del
        # terreno** y daria un numero pequeno con toda la cara de estar completo.
        # Por eso primero se resuelven las variables de SQL del metodo.
        sql_de_var = {}
        for lit in re.finditer(r'\$(\w+)\s*=\s*[\'"](.*?)[\'"]\s*;', cuerpo, re.S):
            texto = lit.group(2)
            if re.search(r'\bselect\b', texto, re.I):
                sql_de_var[lit.group(1)] = texto

        for asig in re.finditer(
                r'\$(\w+)\s*=\s*DB::(select|selectOne)\s*\(\s*(?:[\'"](.*?)[\'"]|\$(\w+))\s*,',
                cuerpo, re.S):
            var = asig.group(1)
            sql = asig.group(3) if asig.group(3) is not None else sql_de_var.get(asig.group(4))

            if not sql:
                continue

            # Que la lectura este ACOTADA: filtra por un id concreto.
            if not re.search(r'where.*?\bid\s*=\s*[?:]', sql, re.I | re.S):
                continue

            origen = tabla_estrecha_de(sql)
            if origen is None:
                continue

            lecturas_vistas += 1
            nivel_origen = NIVEL_TABLA[origen]
            resto = cuerpo[asig.end():]

            # `$var->campo` usado DENTRO de un parentesis de llamada.
            #
            # El `(?:int|...)` no es adorno: la primera version prohibia CUALQUIER
            # parentesis entre la llamada y la variable, y con eso **se le escapaba
            # `self::recalcular((int) $donde->asignatura_id, ...)`** -uno de los dos
            # casos que este guion existe para encontrar-. Lo cazo su propio
            # `--control`, no una revision: un cast es lo bastante comun para que un
            # detector que lo ignore parezca funcionar.
            for uso in re.finditer(
                    r'(\w+(?:::|->)\w+)\s*\('
                    r'(?:[^()]|\((?:int|float|string|bool|array)\))*?'
                    r'\$' + var + r'->(\w+)', resto):
                llamada, campo = uso.group(1), uso.group(2)

                if NIVEL_CAMPO.get(campo, 0) <= nivel_origen:
                    continue

                linea = base + cuerpo[:asig.end() + uso.start()].count('\n')

                # **¿Viaja tambien el alcance estrecho en la MISMA llamada?**
                # `DefinitivasDeAsignatura::recalcular()` acepta un cuarto
                # argumento `$soloAlumno`, y de sus tres puertas **una lo pasa y
                # dos no**. Sin esta columna las tres salen iguales y la fila que
                # esta BIEN cuesta lo mismo de revisar que las que no: el detector
                # las ordena, que es lo que lo hace utilizable.
                #
                # No filtra: **se informa, no se esconde**. Un traspaso acotado
                # sigue siendo un sitio donde mirar -acotar por alumno no es lo
                # mismo que acotar por unidad- y quien lea decide.
                tramo = resto[uso.start():uso.start() + 400]
                corte = tramo.find(';')
                argumentos = tramo[:corte if corte != -1 else len(tramo)]
                acota = sorted({
                    c for c in re.findall(r'\$\w+->(\w+)', argumentos)
                    if 0 < NIVEL_CAMPO.get(c, 0) <= nivel_origen
                })

                hallazgos.append({
                    'fichero': ruta, 'linea': linea, 'metodo': nombre,
                    'origen': origen, 'campo': campo, 'llamada': llamada,
                    'acota': acota,
                    'sql': ' '.join(sql.split())[:110],
                })

    return hallazgos, lecturas_vistas


def main():
    control = '--control' in sys.argv
    detalle = '--detalle' in sys.argv

    if not os.path.isdir(RAIZ):
        sys.exit(f"No existe '{RAIZ}/'. Corre esto desde la raiz del repo.")

    ficheros = [os.path.join(d, f)
                for d, _, fs in os.walk(RAIZ) for f in fs if f.endswith('.php')]

    todos, lecturas = [], 0
    for f in sorted(ficheros):
        h, n = analizar(f)
        todos += h
        lecturas += n

    print()
    print('Traspasos: una lectura acotada que entrega a una llamada que no lo esta')
    print('=' * 78)

    for h in todos:
        marca = ('ACOTA con ' + ','.join(h['acota'])) if h['acota'] else 'SIN ACOTAR'
        print(f"  [{marca:>22}]  {h['fichero']}:{h['linea']}  {h['metodo']}()")
        print(f"      lee de `{h['origen']}` por id  ->  entrega `{h['campo']}` a {h['llamada']}()")
        if detalle:
            print(f"      {h['sql']}")

    print()
    sin_acotar = [h for h in todos if not h['acota']]
    print(f"  POBLACION: {len(ficheros)} ficheros PHP, {lecturas} lecturas acotadas por id, "
          f"{len(todos)} traspasan a una dimension mas ancha.")
    print(f"  De esas {len(todos)}: **{len(sin_acotar)} SIN ACOTAR** y "
          f"{len(todos) - len(sin_acotar)} que si llevan el alcance estrecho en la llamada.")
    print()
    print('  Un traspaso NO es un fallo: subir de dimension casi siempre es intencionado.')
    print('  La pregunta para cada fila es: **¿y si la fila de origen fuera de UN alumno?**')
    print()

    if control:
        print('  CONTROL POSITIVO (los dos medidos a mano en bi-2.md §4)')
        print('  ' + '-' * 74)
        faltan = []
        for fichero, campo in CONTROL:
            ok = any(h['fichero'] == fichero and h['campo'] == campo for h in todos)
            print(f"    {'OK  ' if ok else 'FALTA'}  {fichero}  ->  {campo}")
            if not ok:
                faltan.append(fichero)
        print()
        if faltan:
            sys.exit('  EL DETECTOR ESTA ROTO: no encuentra un caso que SI existe. '
                     'Su lista no vale y el numero de arriba tampoco.')
        print('  Encuentra los dos. El numero de arriba se puede leer.')
        print()


if __name__ == '__main__':
    main()
