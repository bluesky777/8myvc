# FIRMA-1 — La firma del profesor: la mina desactivada, y la decisión que queda

> Lote de `8myvc-d2`. Está en el 05 **§168**, traído por `myvc-front-89` midiendo
> su propio contrato. **Confirmado punto por punto, sin correcciones.**

## §1. Las dos mitades, y sólo una es mía

`§168` describe **dos cosas distintas** y conviene no mezclarlas:

| | Qué es | Quién la cierra |
|---|---|---|
| El borrado silencioso | un campo sin comprobar | **arreglo — hecho aquí** |
| Cuál de los dos hermanos gana | dos criterios de permiso sobre la misma columna | **decisión de Joseth — sigue abierta** |

## §2. El arreglo: la mina

`Perfiles/PerfilesController::putCambiarfirmaunprofe` hacía:

```php
$profesor->firma_id = Request::input('imgFirmaProfe');
$profesor->save();
return ImageModel::find($profesor->firma_id);
```

Su hermana lee **`imagen_id`**, no `imgFirmaProfe`. Así que una llamada con la clave
de la hermana ponía **`firma_id = null`**, guardaba, y devolvía `find(null)`: **200
con cuerpo `null`**. La firma desaparece del boletín —la leen `Year::datos()` para
rector y secretaria, y `Grupo` para el titular— **y nadie ve un error**.

Es la familia del *200 que miente* de la §48.2, con una diferencia que la hace peor
de encontrar: **aquí lo dispara un nombre de campo equivocado**, no una rama sin
salida. No hay código roto que leer.

**No estaba disparado.** El front desplegado en los dieciséis manda `imgFirmaProfe`;
la pantalla nueva de `app2` llama a la hermana buena. Era **una mina puesta, no un
incendio** — y por eso se arregla ahora, que no cuesta nada, en vez de cuando el día
que alguien unifique los nombres de campo la pise.

### Las dos salidas, y por qué 422

`CamposQueVinieron`, **16.ª aplicación**. Y la decisión que hay dentro merece
escribirse porque las dos alternativas parecían razonables:

- **Saltar en silencio si el campo no vino** — deja el endpoint contestando 200 sin
  hacer nada. Es exactamente el fallo que se viene a cerrar, otra vez.
- **Rechazar el vacío** — cerraría también el **vaciado a propósito**, que es una
  operación legítima: la hermana la admite con `$img_id ? $img_id : null`.

Lo que hace posible elegir bien es que la clase distingue las dos preguntas:
*«¿vino la clave?»* y *«¿con qué valor?»*. **422 nombrando el campo cuando no vino;
vaciar cuando vino vacío.**

## §3. La decisión que NO se toca: cuál de los dos hermanos gana

Las cuatro diferencias, verificadas línea a línea:

| | `PerfilesController:941` | `ImagesUsuariosController:186` |
|---|---|---|
| Ruta | `PUT perfiles/cambiarfirmaunprofe/{id}` | `PUT images-users/cambiar-firma-un-profe/{id}` |
| Campo | `imgFirmaProfe` | `imagen_id` |
| Quién puede | `Autoriza::esAdministrativo` | `tipo == 'Profesor' \|\| is_superuser` |
| ¿De quién es la imagen? | **no lo pregunta** | `exigeQueLaImagenSeaSuyaODelColegio` |
| `updated_by` | no lo escribe | lo escribe |

**«Gana la que usa el front» es la respuesta equivocada**, y ése es el punto: la que
usa el front es **la que no comprueba de quién es la imagen**.

Es la familia de la [§14](../09-pendientes.md) —dos nombres casi iguales que no son
la misma condición— y no un problema de nombres de campo. Dos criterios de permiso
distintos sobre **la misma columna de la misma tabla** no se unifican dentro de un
arreglo: quien decida tiene que decidir **a la vez** quién puede firmar a otro y si
hace falta que la imagen sea suya.

Los dos criterios, dichos como conjuntos para que la decisión se pueda tomar sin
volver a leer el código:

- `esAdministrativo` = `is_superuser || Secretario`. **No incluye al docente.**
- `tipo == 'Profesor' || is_superuser`. **No incluye al administrativo no
  superusuario.**

O sea que **ninguno de los dos contiene al otro**: hoy un Secretario puede por una
puerta y no por la otra, y un docente al contrario. No es «uno es más estricto».

## §4. Comprobado al revés

Once tests en la clase; revertida la guarda, **cae uno**: el de la clave de la
hermana. El segundo test nuevo —el del vaciado a propósito— **pasa en los dos
casos, y se dice en su docblock**: no fija el fallo, fija que el arreglo no sea un
candado.

## §5. Y una nota de método sobre el paso previo

`§176.3` obliga a contar tablas y usuarios antes de una suite. Hecho: **92 tablas,
2.351 usuarios, 51 profesores** en `simonbolivar_testing_q`.

**El 92 no cuadraba con el 93 que dice la regla**, así que antes de correr nada se
comparó contra las otras tres bases: `_e`, `_base` y `simonbolivar_testing` **tienen
92 también, y la diferencia entre ellas es cero tablas**. Y no existe migración de
`auditoria` en `main`.

> Conclusión: **el 93 de la regla es de un estado que `main` no tiene**, no una base
> a medias. El número de la comprobación envejeció antes que la comprobación. Es la
> misma forma que el umbral de 300 ficheros de [EXP-1](exp-1.md) —que también estaba
> mal y también saltó— y merece el mismo arreglo: **un guardián se compara contra
> algo medible ahora, no contra un número recordado.**
