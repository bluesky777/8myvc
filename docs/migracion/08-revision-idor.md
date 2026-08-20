# Revisión de autorización horizontal (IDOR) — 19 ago 2026

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

## Lo que se puede hacer hoy con un token de alumno

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

## Qué hacer con esto

**No se arregla en esta revisión**, y no por falta de ganas: son 141 rutas, el
arreglo es exactamente el trabajo que el plan llama **Fase 2 — «Organizar rutas +
middleware `auth` real»**, y hacerlo bien pide decidir tres cosas que no son de
programación:

1. **Qué ve un alumno de su propio grupo.** Hoy la respuesta de facto es «todo».
   La lista de compañeros con nombre y foto probablemente sí; con documento,
   dirección y deuda, probablemente no. Eso lo dice el colegio.
2. **Qué ve un acudiente.** El guard `boletin.propio` ya resuelve «solo sus
   acudidos» y se puede reutilizar tal cual en casi todas las de la tabla de datos
   personales.
3. **Dónde va la línea entre personal y administrativo.** `auth.personal` y
   `Autoriza::esAdministrativo` ya existen y ya se usan; falta repartirlas.

Mientras tanto, `SuperficieDeUnAlumnoTest` deja los 27 casos fijados. **Cada uno
está escrito para fallar el día que se cierre su ruta**, y la respuesta correcta
entonces es cambiarlo por `assertStatus(403)`, no borrarlo. Así el arreglo de la
Fase 2 se puede hacer en tandas y en cada tanda se ve exactamente qué se cerró.

### Por dónde empezar, si hay que priorizar

En este orden, que es el de daño irreversible primero:

1. `detalles/eliminar-matricula-destroy` y `detalles/eliminar-notas-periodo` —
   borran sin papelera.
2. `years/delete`, `grupos/destroy`, `materias/destroy`, `periodos/establecer-actual`
   y el resto de `destroy` de catálogo — `auth.personal`, que ya existe.
3. `perfiles/guardar-username` y `perfiles/update` — cuenta ajena.
4. Las dos de PIAR y `enfermeria/datos` — el dato más sensible del sistema.
5. El resto de la pantalla del grupo, cuando el colegio diga qué ve un alumno.
