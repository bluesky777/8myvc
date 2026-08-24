#!/usr/bin/env python3
"""
Que metodos de controlador NO se defienden solos — los que dependen entero del
middleware de su ruta.

    tools/guardas-sin-respaldo.py app/Http/Controllers/X.php:metodo [...]

Contesta la pregunta de la 05 §227/§228: **a una guarda no se le pregunta
"¿funciona?" sino "¿de que protege, y que queda si se la quitan?"**. Para cada
metodo mira si dentro hay (a) la identidad de la sesion —`$user->persona_id`,
`user_id`, `tipo`— y (b) algun freno (`abort(`, `Hash::check`).

## ESTE DETECTOR ORDENA CANDIDATOS. NO CONCLUYE. Y se equivoco en las DOS
## direcciones sobre las mismas 24 rutas:

  - **de mas**: su primera version reconocia como freno solo `abort(403|404|401)`
    y marco desnuda a `perfiles/cambiarpassword`, que se defiende exigiendo la
    contrasena antigua y cortando con `abort(400)`. **Un detector de guardas que
    solo reconoce el codigo HTTP correcto es ciego justo en el codigo que este
    repo usa** (el legacy devuelve 400 para todo).
  - **de menos**: cuenta como freno los `abort(422, 'Datos incorrectos')` de
    `perfiles/update`, que validan FORMATO y no propiedad.

O sea que las dos columnas son pistas, no veredictos:

  - un `$user->user_id` puede alimentar `updated_by` y no constrenir nada
    (`myimages/privatizar-imagen`);
  - un `$user->periodo_id` es ALCANCE, no propiedad (`frases_asignatura/show`);
  - y un `abort()` puede ser de validacion.

**Cada fila se verifica leyendola.** En la 05 §228 se leyeron las 24: trece
enteras y once por inspeccion dirigida de todas sus lineas con identidad o
`abort`.

La poblacion de rutas con un guard sale de `php artisan route:list --json`, no de
un grep sobre `routes/`: alli hay comentarios que nombran el middleware y lineas
de continuacion, y el grep cuenta 26 donde hay 24.
"""

import re, sys, pathlib
METODO = re.compile(r'^\s*(?:public|private|protected|static|\s)*function\s+(\w+)\s*\(')
def cuerpo(ruta, nombre):
    lineas = pathlib.Path(ruta).read_text(errors='replace').split('\n')
    ini=None; llaves=0; abierto=False; out=[]
    for i,l in enumerate(lineas):
        m=METODO.match(l)
        if m and m.group(1)==nombre and ini is None: ini=i
        if ini is not None:
            out.append(l); llaves += l.count('{')-l.count('}')
            if not abierto and llaves>0: abierto=True
            elif abierto and llaves<=0: break
    return out
def sin_comentarios(ls):
    fuera=[]
    dentro=False
    for l in ls:
        s=re.sub(r'//.*$','',l)
        if '/*' in s: dentro=True; s=s.split('/*')[0]
        if dentro and '*/' in l: dentro=False; s=l.split('*/')[1]
        elif dentro: s=''
        if s.strip().startswith('*'): s=''
        fuera.append(s)
    return fuera
PROPIO = re.compile(r'\$(?:this->)?user(?:io)?->(persona_id|user_id|tipo)\b')
# **Cualquier `abort(`, no solo 403/404/401.** La primera version solo miraba
# esos tres y marco DESNUDA a `putCambiarpassword`, que se defiende exigiendo la
# contrasena antigua y cortando con `abort(400)` — el legacy devuelve 400 para
# todo. Un detector de guardas que solo reconoce el codigo HTTP correcto es
# ciego justo en el codigo que este repo usa.
FRENO  = re.compile(r'\babort\s*\(|\bHash::check\b')
print('  {:34} {:6} {:6} {:6}  {}'.format('metodo','propio','abort','tipo','veredicto del DETECTOR'))
print('  '+'-'*96)
desnudas=[]
for spec in sys.argv[1:]:
    f,n = spec.rsplit(':',1)
    ls = sin_comentarios(cuerpo(f,n))
    txt='\n'.join(ls)
    propio = len(PROPIO.findall(txt))
    abortos = len(FRENO.findall(txt))
    tipo = 1 if re.search(r'\$(?:this->)?user->tipo', txt) else 0
    if propio==0 and abortos==0:
        v='DESNUDA — ni id propio ni abort'; desnudas.append(n)
    elif propio==0:
        v='abort sin id propio — revisar de que'
    elif abortos==0:
        v='usa id propio, sin abort — revisar si CONSTRINE'
    else:
        v='tiene las dos — revisar'
    print('  {:34} {:^6} {:^6} {:^6}  {}'.format(n, propio, abortos, tipo, v))
print(f'\n  POBLACION: {len(sys.argv)-1} metodos analizados, {len(desnudas)} marcados DESNUDA por el detector.')
print('  (el detector ORDENA; cada uno se verifica leyendolo)')
