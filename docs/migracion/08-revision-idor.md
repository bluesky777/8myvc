# Revisión de autorización horizontal (IDOR) — 19 ago 2026

> **Estado: arreglado.** 141 rutas sin guard → 12. Ver «Qué se hizo» al final.

> Herramienta: [`tools/inventario-autorizacion.py`](../../tools/inventario-autorizacion.py)
> Fallos confirmados: [`tests/Contrato/SuperficieDeUnAlumnoTest.php`](../../tests/Contrato/SuperficieDeUnAlumnoTest.php)
> Documentos hermanos: [01-plan-seguridad.md](01-plan-seguridad.md) · [06-autorizacion.md](06-autorizacion.md)

---

## Por qué se hizo

El plan de seguridad la dejó pendiente después del primer IDOR y la volvió a dejar
pendiente después del segundo:

- **P0**: `GET api/notas/alumno/{alumno_id}` — un alumno leía las notas de cualquier
  compañero cambiando el número de la URL.
- **P1**: `PUT api/matriculas/prematricular` — un alumno le cambiaba el estado y el
  grupo de matrícula a cualquier compañero mandando otro `alumno_id` en el cuerpo.

Dos no son casualidad. Los dos salieron de escribir tests, ninguno de leer código.

## Cómo se hizo

En dos pasos, y el segundo es el que vale.

**1. Filtro grueso, con `tools/inventario-autorizacion.py`.** Marca las rutas que
exigen token, reciben un identificador del cliente —por URL o por cuerpo— y no
tienen ni `auth.personal` ni `boletin.propio` ni una comprobación de permisos
dentro del método. **Salieron 141 de 539.**

Ese número no es la cantidad de fallos: hay rutas de catálogo ahí dentro que no
exponen datos de nadie. Es la lista que había que mirar.

**2. Golpear cada una con un token de alumno del seed.** Es lo único que decide, y
es lo que convirtió la lista en esto de abajo. **El token de un alumno no es el de
un atacante externo: es la credencial que el colegio le da a cada uno de sus 1.279
alumnos**, y la que cualquiera puede usar desde las herramientas del navegador sin
saber programar.

## Lo que se podía hacer con un token de alumno (antes del arreglo)

Todo lo de esta tabla está comprobado contra el seed, no deducido. Cada fila tiene
su test en `SuperficieDeUnAlumnoTest`.

### Cuenta ajena

| Ruta | Qué pasa |
|---|---|
| `PUT perfiles/guardar-username/{id}` | **Le cambia el nombre de usuario al rector.** Es con lo que se entra, así que es dejarlo fuera de su cuenta. Solo hace falta su id, que es un número pequeño |
| `PUT perfiles/update/{id}` | Edita el perfil de cualquier usuario |

`PUT perfiles/cambiarpassword/{id}` **sí** comprueba la contraseña antigua. Es la
única de la familia que se defiende, y conviene decirlo: no es que el módulo no
compruebe nada, es que comprueba en un sitio de tres.

### Datos personales de terceros

| Ruta | Qué devuelve de otra persona |
|---|---|
| `PUT enfermeria/datos` | **Antecedentes médicos**: cirugías, alergias, vacunas |
| `GET bitacoras/{user_id}` | La bitácora de otro usuario |
| `PUT historiales/de-usuario` | Su historial de sesiones e intentos fallidos |
| `PUT acudientes/de-persona` | Sus acudientes, con documento y teléfono |
| `PUT detalles/alumno` | Sus matrículas de todos los años |
| `PUT alumnos/years-con-notas` | Los años y grupos en los que ha estado |
| `PUT mis-actividades/datos` | Sus asignaturas |
| `GET frases_asignatura/show/{alumno_id}/{asignatura_id}` | Las frases de su boletín |

### El grupo entero, sin pedir a nadie en concreto

Con el id de su propio grupo:

| Ruta | Qué devuelve |
|---|---|
| `GET grupos/listado/{grupo_id}` | Los 68 compañeros con documento, dirección y deuda |
| `PUT alumnos/de-grupo/{grupo_id}` | La ficha completa de cada uno |
| `GET observador/vertical/{grupo_id}/{tamanio}` | El observador, con los acudientes de cada alumno |
| `PUT observador-horizontal/horizontal/{grupo_id}` | Lo mismo en JSON |
| `PUT editnota/detailed-notas/{grupo_id}` | La rejilla de notas que edita el profesor |
| `PUT puestos/detailed-notas-periodo/{grupo_id}` | El escalafón del periodo |
| `GET nota_comportamiento/detailed/{grupo_id}` | El comportamiento de todos |
| `GET piars-alumnos/alumnos/{grupo_id}` | Los alumnos con **PIAR** |
| `GET piars-actas-acuerdo/matriculas/{grupo_id}` | Las actas de acuerdo del PIAR |

Las dos últimas pesan aparte: PIAR son planes de apoyo por discapacidad, y es el
módulo cuyos datos el generador de seed **omite a propósito** por ser «el dato más
sensible del sistema». La API los sirve a cualquier alumno del grupo.

`boletines` y `bolfinales` **no** están en esta lista: se cerraron en el P0 con
`boletin.propio`. Es exactamente el ejemplo de lo que falta — el guard existe y
funciona; lo que no se hizo fue ponerlo en el resto de la pantalla del grupo.

### Escrituras sobre otro alumno

| Ruta | Qué escribe |
|---|---|
| `POST disciplina/store` | Le abre un **proceso disciplinario** a otro alumno |
| `POST ausencias/agregar-ausencia` | Le pone una ausencia |
| `PUT detalles/eliminar-notas-periodo` | Le borra las notas de un periodo |
| `PUT detalles/eliminar-matricula-destroy` | **Le borra la matrícula, sin papelera**: es un `DELETE FROM matriculas` a pelo, no marca `deleted_at`. Lo saca del colegio y no queda de dónde restaurarlo |

### Configuración del colegio

Esto ya no es autorización horizontal —no es «los datos de otro»— sino que faltan
guards de rol. Salió de la misma revisión y es lo más destructivo:

| Ruta | Qué pasa |
|---|---|
| `DELETE years/delete/{id}` | Un alumno borra un año lectivo entero |
| `DELETE grupos/destroy/{id}` | Borra su grupo |
| `DELETE materias/destroy/{id}` | Borra una materia |
| `PUT periodos/establecer-actual/{periodo_id}` | Cambia el periodo actual del colegio, que es lo que ven todos los profesores al entrar |

Y con ellas los otros ~30 `destroy/{id}` de catálogo que el inventario marca:
áreas, grados, niveles educativos, escalas de valoración, tipos de documento.

## Lo que sí está cerrado

Va aquí para no caerse sin que nadie lo note, y tiene su test:

- `PUT roles/addroletouser/{role_id}` — asignarse un rol. Fase 6.
- `DELETE grupos/forcedelete/{id}` — el cascade a 27 tablas. Fase 6.
- `GET notas/alumno/{alumno_id}` — P0.
- `PUT matriculas/prematricular` — P1.
- Los once de boletines y bolfinales — P0.
- `PUT perfiles/cambiarpassword/{id}` — comprueba la contraseña antigua.

## Qué se hizo (19 ago 2026)

Joseth fijó la regla el mismo día: **un alumno solo puede ver información de sí
mismo; un acudiente, la suya y la completa de sus acudidos.** Y dejó fuera, a
propósito, lo que puede hacer el personal del colegio entre sí: eso va en el
refactor de permisos que viene después.

Con esa regla el arreglo deja de ser una discusión y pasa a ser un reparto de dos
guards, y los dos ya existían o casi:

- **`auth.personal`** —«no es alumno ni acudiente»— en todo lo que una familia no
  usa: las escrituras sobre estructura, catálogos y administración; las lecturas
  de grupo entero; y las escrituras sobre terceros.
- **`persona.propia`**, nuevo, en lo que una familia SÍ usa: su perfil, sus fotos,
  sus datos, los de sus acudidos.

**141 → 12.** Lo que queda son lecturas de catálogo —ciudades, grados, niveles,
periodos, la ficha de una asignatura— que no exponen datos de ninguna persona.

### Cómo está hecho `persona.propia`

Recoge **todos** los identificadores de persona que traiga la petición —`alumno_id`,
`user_id`, `persona_id`, `acudiente_id`, `profesor_id`, `matricula_id`, `imagen_id`,
y la lista `requested_alumnos`—, vengan por URL o por cuerpo, y exige que **todos**
sean suyos. No uno: todos. El agujero de `matriculas/prematricular` no fue que
faltara una comprobación, fue que el id viajaba por donde nadie miraba.

Sin identificador deja pasar, porque eso significa «lo mío» y lo resuelve el
controlador desde el token. Las rutas de grupo entero, que no llevan id, no llegan
aquí: llevan `auth.personal`.

Dos cosas que se descubrieron cerrando:

- **`perfiles/update/{id}` elige la TABLA con el `tipo` que manda el cliente.** Con
  `tipo=Profesor` busca en `profesores`, con `tipo=Alumno` en `alumnos`. Comprobar
  solo el id no bastaba: un alumno con id 460 habría editado al profesor 460
  diciendo que era profesor. El guard comprueba las dos cosas.
- **`images.user_id` es nullable.** Las imágenes públicas del colegio no son de
  nadie, y `null` no es de quien pregunta, así que rotarlas o publicarlas queda
  para el personal. Es lo correcto: una imagen sin dueño es del colegio.

### Cómo se comprobó

Los 27 casos de `SuperficieDeUnAlumnoTest` **se escribieron primero al revés**,
afirmando que el agujero estaba abierto. Al cerrar las rutas, los 27 fallaron —y
ese fallo es lo que demostró que el guard llegaba a la ruta— y luego se les dio la
vuelta. Un test de autorización escrito solo en su versión final no prueba que
antes hiciera falta.

Y se añadió la otra mitad, que es la que de verdad hay que vigilar: **nueve casos
de que un alumno sigue viendo lo suyo y un acudiente lo de su acudido**. Cerrar de
más también se nota en producción, y se nota cuando una familia abre la app y no ve
nada.

`AutorizacionTest` pasa de una lista escrita a mano de 31 rutas a un snapshot: son
más de trescientas, y a ese tamaño una lista a mano deja de leerse.

## Lo que queda para el refactor de permisos

1. **Qué puede hacer el personal entre sí.** Hoy un profesor alcanza todo lo que
   alcanza un administrativo. Es lo que Joseth dejó fuera a propósito.
2. **Las 12 lecturas de catálogo** que quedan sin guard. No exponen a nadie, pero
   conviene decidir si un alumno tiene que poder leerlas.
3. **`asignaturas/show`, `unidades/de-asignatura-periodo`, `votaciones/show`**: no
   son de una persona, pero sí de la estructura del colegio.

Volver a medir es una orden:

    docker exec 8myvc-app-1 php artisan route:list --json > /tmp/rutas.json
    python3 tools/inventario-autorizacion.py /tmp/rutas.json
