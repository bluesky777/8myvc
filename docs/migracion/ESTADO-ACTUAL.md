# Dónde está la aguja ahora mismo

> **Léeme el primero.** Este documento existe para que una sesión nueva pueda
> continuar **sin que Joseth tenga que dar contexto**. Es corto a propósito: dice
> qué se está haciendo, qué acaba de terminar, qué es lo siguiente y qué espera una
> decisión suya. El detalle de cada cosa vive en su documento y está enlazado.
>
> **Se actualiza en el mismo commit que el trabajo**, no en uno aparte al final:
> un commit aparte es el que no se hace cuando la sesión se corta.

**Última actualización: 24 ago 2026** · rama `fix/definitivas-fase-2`

---

## La migración planeada está terminada

Las fases 0–4 del [plan](00-plan-migracion.md) están cerradas, la 5 recortada y la
6 es continua por diseño. **Laravel 13 sobre PHP 8.4**, con red de seguridad y
autenticación real. Hoy: **1.284 tests, 8.640 aserciones, 535/539 rutas con la
respuesta comprobada, larastan nivel 7 `[OK]`.** Al empezar había **0 tests** y
`route:list` estaba roto.

Lo que sigue **no son fases pendientes de la migración**: es el trabajo que se
decidió hacer después.

---

## En curso: las definitivas — fase 3

**El plan entero está en [10-definitivas.md](10-definitivas.md).** Resumen de por
qué se hace: seis sitios escriben en `notas_finales` con cinco criterios distintos
de qué borrar, ninguno transaccional, sobre una tabla sin clave única. De ahí
salen los tres síntomas que se reportaban por separado —definitivas que
desaparecen, duplicadas, y notas puestas que no aparecen— y son el mismo problema.

### Lo hecho

| | |
|---|---|
| **Fase 0** — medir | **hecha.** `tools/salud-de-las-definitivas.php`, sólo SELECT. Medido en un colegio: **11.988 definitivas que deberían existir y no existen**, 718 que discrepan teniendo notas detrás, 1 duplicado |
| **Fase 1** — recalculador único | **escrita y probada.** `App\Services\DefinitivasDeAsignatura`, 14 tests de ida y vuelta. **Cableada sólo en el boletín** |

### Lo que se está haciendo ahora — fase 3

Cablear el recalculador en los seis disparadores que faltan, **y con ellos
desaparecen los `INSERT` sin guarda** que hoy impiden poner el índice único:

| Disparador | Estado |
|---|---|
| Abrir un boletín | **hecho** |
| Editar una nota (`putUpdate`) y **borrarla** (`deleteDestroy`) | **hecho** — era la petición de origen |
| `putSubunidad`, la nota rápida del horario | **hecho**, y de paso arreglada la §3.1: no guardaba nada **y era una inyección** |
| Unidades y subunidades (crear, editar, borrar) | **hecho** — las cuatro llamadas al calculador viejo, y **ya no dependen de que el cliente mande `asignatura_id`** |
| Copiar un periodo | **hecho** — traía la estructura y no avisaba a nadie |
| Cada carga de /notas (`putDetailed`) | **hecho** — era un DELETE+INSERT por alumno en cada carga; ahora pregunta primero |
| Crear la subunidad y sus notas en la misma transacción | **falta — es lo único que queda de la fase 3** |

**Cinco `INSERT` sin guarda menos**: al desaparecer los de `putDetailed` y los del
calculador viejo, la fase 2 queda más cerca. Lo que sigue sin guarda es
`DefinitivasPeriodosController::putUpdate` —el profesor tecleando una definitiva—
y los cuatro de `NotaFinal::alumnos_grupo_nota_final`.

### Y el orden, que se corrigió el 24 ago

**La fase 2 —los índices únicos— no puede ir antes que la 3.** Auditados los once
`INSERT INTO notas_finales`: tres están protegidos, dos son código muerto y
**seis están en pantallas vivas sin guarda**. Con el índice puesto, cada choque es
**un 500 en la pantalla de un profesor** — el peor, `putUpdate`, es el que teclea
la definitiva. Está detallado en el 10, justo antes de la fase 2.

---

## Lo que espera una decisión de Joseth

Están en [09-pendientes.md](09-pendientes.md), agrupadas. Las que quedan sin
contestar:

- **La hora mal escrita** en filas ya guardadas — y ojo, **se midió y el dato no
  distingue** una fila mal escrita de una normal.
- **Los interruptores `para_*`** — hay que contestarlos con los tres delante.
- **Quién del personal puede qué** — cinco lotes preguntan variantes.
- **Los dieciséis números de la fase 0** de definitivas: la herramienta está, hay
  que correrla en el servidor colegio por colegio (`for` de una línea en el 10).

---

## Lo que está fusionado y NO desplegado

**Fusionado no es desplegado**, y `app/` es copia por colegio. En
[DESPLIEGUE.md](../DESPLIEGUE.md) hay una tanda entera sin salir: la noche del 22
al 23 más las decisiones del 23 y el 24. Dentro está **el boletín que hoy devuelve
500 a una familia**.

Y en `myvc_front` queda apuntado, sin hacer, el arreglo de **las cuatro altas de
la planilla de notas que no mandan `fecha_hora`** (`MIGRATION.md` §4b.3b).
