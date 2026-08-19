# Autorización — quién puede hacer qué

La auditoría de autenticación ([04](04-auditoria-autenticacion.md)) contestaba a
"¿hay que presentar token?". Este documento contesta a la siguiente, que es la
que de verdad protege los datos: **teniendo token, ¿puedes ver esto?**

Un alumno con token es un usuario autenticado. Nada más.

---

## 1. Los cuatro guards que estaban escritos y no se ejecutaban

No se dedujo leyendo el código: se comprobó golpeando los endpoints con un token
de alumno del seed de tests, el 18 ago 2026.

| Con token de Alumno | Respondía |
|---|---|
| `PUT boletines/detailed-notas` pidiendo el de otro alumno | **200** + boletín completo |
| Lo mismo con token de Acudiente que no es su acudiente | **200**, y sin mirar el paz y salvo |
| `DELETE requisitos/destroy/{id}` | **200 "Eliminado"** |
| `GET piars-grupos/grupos` | **200**, listado entero |

Cuatro formas distintas del mismo descuido, y ninguna se parece a las otras:

### `catch (\Throwable)` se traga el `abort()`

`BoletinesController::__construct` tenía las comprobaciones dentro de un
`try { ... } catch (\Throwable $th) { return 'Error'; }`. `abort()` lanza una
`HttpException`, que **es** un `Throwable`, así que el catch se las tragaba
enteras. Y el `return` de un constructor no detiene nada.

Se buscó esta forma —un `abort()` dentro de un `try` con catch de `\Throwable` o
`\Exception`— en todo `app/` con el parser: solo pasaba aquí.

### `return` dentro de un constructor

`RequisitosController` y `PrematriculasController`:

```php
public function __construct()
{
    $this->user = User::fromToken();
    try {
        if (! $this->user->is_superuser) {
            return 'No tienes permiso';   // ← no detiene la petición
        }
    } catch (\Throwable $th) { return 'Error'; }
}
```

Ninguno de los seis métodos de `RequisitosController` comprueba nada por su
cuenta, así que ese `return` era toda la defensa. `MatriculasController` tenía la
misma forma —y encima con la condición al revés, `else if ($this->user->is_superuser)`—
pero ahí no había agujero: sus catorce métodos comprueban uno a uno.

### Precedencia de operadores

`PiarsGruposController`:

```php
if (!$this->user->is_superuser && !$this->user->tipo == 'Profesor') {
```

PHP agrupa `!$this->user->tipo == 'Profesor'` como `(!$tipo) == 'Profesor'`.
`!$tipo` es `false` para cualquier cadena no vacía, y `false == 'Profesor'` es
`false`. La condición no puede ser cierta nunca.

### Y una que no era descuido, sino una copia

`Boletines2Controller` y `Boletines3Controller` son `BoletinesController` con
otra maqueta. Sirven el mismo dato, por las mismas tres rutas, y **no tenían ni
la comprobación escrita**. Sus `deleteDestroy` borran un alumno por id sin mirar
nada.

Arreglar solo el primero habría dejado dos puertas abiertas al mismo sitio. Es el
mismo error que se corrigió con `getUltimas`/`putUltimas`, y la razón por la que
el guard vive ahora en un middleware y no en un método.

---

## 2. Lo que se aplica ahora

Dos middlewares, visibles en el archivo de rutas, con `route:list` para
comprobarlo.

### `boletin.propio` — 15 rutas

Un alumno solo puede pedir informes suyos; un acudiente, solo de sus acudidos, y
solo si el alumno está a paz y salvo. El personal del colegio no se ve afectado.

Cubre las tres familias de boletines (`boletines`, `boletines2`, `boletines3`),
los finales (`bolfinales`, `bolfinales-preescolar`), `notas-actuales-alumnos` y
`certificados-persona`. Los cinco últimos **nunca tuvieron comprobación escrita**:
entraron aquí porque sirven el mismo dato por otra puerta.

Entiende las dos formas de pedir un alumno que usan estos endpoints: la lista
`requested_alumnos` y el `alumno_id` suelto de `certificados-persona`.

Los `bitacoras` que insertaba el código original se conservan: son el rastro que
mira el colegio cuando alguien reclama.

> **El paz y salvo se activa, y se nota.** Joseth lo decidió el 18 ago 2026
> sabiendo que desde el despliegue un acudiente con deuda deja de ver el boletín,
> y hoy lo ve. Es lo que el código llevaba años intentando hacer.

### `auth.personal` — 14 rutas

Fuera alumnos y acudientes: `requisitos/*`, `prematriculas/*`, `piars-grupos/*` y
los `destroy` de `boletines2` y `boletines3`.

> **El criterio es "no es alumno ni acudiente", no `is_superuser`**, que es lo que
> decía el código muerto. El colegio tiene diez cuentas de tipo Usuario sin
> superusuario —secretarías, coordinación— y exigir superusuario las dejaría
> fuera de su propio trabajo. Lo que había que cerrar era la puerta a alumnos y
> acudientes. Decisión de Joseth, 18 ago 2026, y hay un test que comprueba que el
> personal sin superusuario sigue entrando.

Esto **no sustituye** a las comprobaciones por método: varias rutas de matrículas
exigen además `profes_can_edit_alumnos` o superusuario, y lo siguen exigiendo.

**Comprobado contra el cliente que de verdad usa `piars-grupos/*`:** no es
`myvc_front`, que no llama a ninguna ruta `piars-*`, sino **`myvc_front_2`**, la
aplicación Angular que cubre el PIAR y se publica en la carpeta `plus` de cada
colegio. Es para el personal —comprueba `tipo === 'Profesor'` contra el titular
del grupo antes de dejar editar— y manda `Authorization: Bearer` en todas sus
llamadas, así que ni el guard por defecto ni `auth.personal` le cambian nada.

### Cómo llega un alumno a su boletín, y por qué el guard no le estorba

Comprobado en `myvc_front`, no supuesto. Alumno y acudiente **no** pasan por la
pantalla del personal: tienen la suya, el estado `panel.boletin_acudiente`, y
llegan por dos botones de `NotasAlumnoCtrl`:

```js
// verMiBoletin()  — el alumno
$cookies.putObject('requested_alumno', [{ alumno_id: USER.persona_id, grupo_id: USER.grupo_id }]);
// verBoletin()    — el acudiente, con el acudido que haya seleccionado
$cookies.putObject('requested_alumno', [{ alumno_id: alumno.alumno_id, grupo_id: alumno.grupo_id }]);
```

y el estado llama a `PUT boletines/detailed-notas/{grupo_id}` con ese array. O
sea que **siempre mandan `requested_alumnos` con un solo `alumno_id`**, que es
exactamente lo que el guard pide. No hay regresión para ellos.

Dos consecuencias que cambian lo que parecía:

- **Activar el paz y salvo no cambia lo que ve una familia al día.** El front ya
  lo comprueba antes de llamar (`if (!alumno.pazysalvo) → 'Debe estar a paz y
  salvo'`). Lo que hace el backend es dejar de fiarse del front: quien salte la
  pantalla ya no pasa.
- **Esa pantalla llevaba cinco años rota, con 500.** El front manda
  `{alumno_id, grupo_id}` y `Grupo::alumnos()` exigía además `matricula_id` desde
  el 31 ago 2021. Arreglado en este PR; está en
  [05-codigo-muerto-y-roto.md](05-codigo-muerto-y-roto.md).

Y donde sí hay cambio: un alumno o acudiente que llegue a la pantalla **del
personal** y pida el grupo entero recibe ahora 403 donde antes recibía todos los
boletines. Eso es el agujero que se cierra.

La app Flutter no entra: de sus seis llamadas, ninguna es de boletines.

### Los códigos

`403`, no el `400` del código viejo. Nadie recibía esas respuestas hasta hoy —las
guards no se ejecutaban—, así que no hay contrato que conservar, y el
interceptor de `myvc_front` trata igual a las dos: emite `event:auth-forbidden`,
que nadie escucha, y rechaza la promesa. El `401` sigue reservado para "no hay
token", que es el único que hace que el front pida login otra vez.

`tests/Contrato/AutorizacionTest.php` cubre los dos guards, incluida la lista
exacta de rutas que las llevan: quitar un `->middleware(...)` de una ruta rompe un
test, que era justo lo que faltaba cuando la copia de al lado no tenía la
comprobación.

---

## 3. Lo que sigue sin auditar

Esto cubre una familia de endpoints: los que sirven el informe de una persona.
**No es una auditoría de autorización completa.** Queda sin mirar, al menos:

- **`observador`, `puestos`, `actas-evaluacion`, `notas-perdidas`, `simat`,
  `excel-docentes`, `acudientes-export`** — informes de grupo o de colegio. Hoy
  cualquiera con token los alcanza. Ninguno acepta `requested_alumnos`, así que
  no entran en el guard de arriba; hace falta decidir a quién se abren.
- **Las notas.** Guardar y modificar notas es lo más sensible que hace el sistema
  y su autorización vive repartida en condiciones por método
  (`profes_pueden_editar_notas`, `profes_can_edit_alumnos`).
- **Los perfiles y las imágenes**, que fueron CRÍTICO-3 del plan de seguridad.
- **Que un profesor solo pueda tocar SUS grupos.** Hoy la mayoría de las
  comprobaciones distinguen tipos de usuario, no relaciones: un profesor
  autenticado puede pedir el boletín de un grupo que no es suyo.

---

## 4. Paso siguiente: rehacer la estructura de roles y permisos

**Esto es lo que hay que arreglar antes de seguir poniendo guards uno a uno**, y
lo pidió Joseth explícitamente: que sea limpia y fácil de aplicar a endpoints
nuevos.

Hoy conviven dos sistemas y solo funciona el peor.

### El que se usa: cadenas repetidas por todas partes

**138 comprobaciones en 42 controladores** con la forma
`$user->is_superuser` o `$user->tipo == 'Profesor'`, copiadas y pegadas. La misma
condición aparece catorce veces seguidas en `MatriculasController`:

```php
if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
```

Copiar y pegar una condición es precisamente cómo aparecieron los cuatro guards
muertas de la sección 1: cuando la regla vive en 138 sitios, nadie comprueba que
los 138 la digan bien.

### El que existe y no se usa: roles y permisos en la base

Hay cuatro tablas (`roles`, `permissions`, `role_user`, `permission_role`), se
consultan **en cada petición** —con un N+1: una consulta por cada rol del
usuario— y el resultado se usa **en un solo sitio de todo el proyecto**:
`RolesController::exigirAdminUsuarios()`, que además lo salta si el usuario es
superusuario.

Los datos explican por qué no se usa:

| Rol | Permisos | Usuarios |
|---|---|---|
| **Manager** | **16** | **0** |
| Profesor | 1 | 51 |
| Alumno | 1 | 1.280 |
| Acudiente | 1 | 999 |
| Admin | **0** | 10 |
| Rector, Asistente, Enfermero, Coord. académico, Coord. disciplinario, Psicólogo | 0 | 0–4 |

El único rol con permisos de verdad **no lo tiene nadie**. El rol que sí tienen
los diez administradores no lleva ninguno. Los tres restantes son copias del
campo `tipo` con un permiso `can_work_like_*` cada uno.

O sea: se paga el coste de un sistema de permisos en cada petición y no decide
nada. La única comprobación real que se le pide —`can_edit_usuarios`— la cumple
un rol sin usuarios, así que en la práctica siempre gana el `is_superuser` de la
línea de arriba.

### Qué habría que decidir

1. **Un solo sitio donde se diga quién puede qué.** Hoy hay tres criterios
   mezclados —`tipo`, `is_superuser` y los permisos— sin regla de precedencia.
2. **Si los roles se quedan, poblarlos**: `Admin` con permisos, `Manager` con
   usuarios o fuera. Si no se quedan, borrar las cuatro tablas y ahorrar la
   consulta por petición.
3. **Autorización por relación, no solo por tipo**: "este profesor, en este
   grupo". Es lo que falta para cerrar lo de la sección 3, y ningún sistema de
   permisos planos lo cubre.
4. **Que aplicarla a un endpoint nuevo sea una línea en el archivo de rutas**,
   como `auth.personal`, y no un `if` copiado dentro del método. Es lo que hace
   que se pueda comprobar con un test que lea la tabla de rutas.

El momento natural es la **Fase 3** (Sanctum), cuando se toca la autenticación de
todos modos y `User::fromToken()` se sustituye por un servicio de contexto: ahí es
donde hoy se cargan los roles y los permisos.
