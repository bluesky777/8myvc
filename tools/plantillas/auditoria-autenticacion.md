# Auditoría de autenticación — qué rutas no resuelven al usuario

**GENERADO. No editar a mano.** Se regenera con:

```bash
docker exec 8myvc-app-1 php tools/auditar-autenticacion.php --md \
  > docs/migracion/04-auditoria-autenticacion.md
```

## Por qué esta lista y no una semana de registro

El plan proponía desplegar un middleware que registrara durante una semana qué
rutas llegan sin token. No sirve: hay semanas en que los colegios no usan el
sistema, así que la ausencia de registros no distingue "nadie llama a esta ruta"
de "nadie usó el sistema esa semana". Esto se determina leyendo el código, que no
depende de que alguien entre.

## Cómo se determinó

Al principio este proyecto no tenía middleware de autenticación: cada método se
defendía solo llamando a `User::fromToken()`, que aborta con 401 si no hay token,
si expiró o si es inválido (`app/User.php:85-99`). Llamarlo **es** una
comprobación.

Se recorren las rutas reales del router y se analiza el cuerpo de cada método con
el analizador sintáctico —no con `grep`, que contaría un `fromToken` escrito
dentro de un comentario—. Se siguen además las llamadas a métodos auxiliares de
la propia clase: el PR #3 puso las guardas en `$this->exigirAdminUsuarios()`, y
mirando solo el cuerpo directo salían como desprotegidas.

Cuenta como resuelto: el middleware `auth.token`, o una llamada a
`User::fromToken()`, `JWTAuth::*`, `Auth::*`, `auth()` o `$this->user` (resuelto
en el constructor), directa o vía auxiliar.

**Lo que esto NO dice:** que las que sí resuelven al usuario estén bien.
Resolverlo prueba que hay token válido, no que ese usuario tenga permiso para lo
que va a hacer. Un alumno con token es un usuario autenticado. Eso es otra
auditoría.

## Resumen

| | Rutas |
|---|---|
| Resuelven al usuario | **{{CON}}** |
| No lo resuelven y **escriben** en la base | **{{ESCRIBEN}}** |
| No lo resuelven, solo leen | **{{LEEN}}** |
| Método vacío: la ruta existe, el método no hace nada | {{VACIOS}} |
| Ruta registrada cuyo método no existe | {{ROTAS}} |
| **Total** | **{{TOTAL}}** |

---

## 1. Escriben en la base sin resolver al usuario — {{N_ESC_REV}} a revisar

Lo urgente: permiten modificar datos de un colegio sin presentar token.

> **Las 58 que había aquí están cerradas** con el middleware `auth.token`
> (Joseth las confirmó todas como error el 18 ago 2026). `tests/Contrato/AutenticacionTest.php`
> comprueba que responden 401 sin token y que no rechazan a un usuario legítimo.

{{T_ESC_REV}}

### Públicas a propósito (escriben, pero son el flujo de entrada)

De `login/*` y `password/*`, que el plan ya lista como públicas. No pueden llevar
guard —son justo lo que se usa sin token—, pero conviene mirar `putLogout`:
recibe el `user_id` por parámetro, así que hoy cualquiera puede cerrar la sesión
de cualquiera.

{{T_ESC_PUB}}

---

## 2. Solo leen, sin resolver al usuario — {{N_LEE_REV}} a revisar

Menos grave que escribir, pero varias exponen datos de menores a cualquiera que
sepa la URL. **Pendiente de confirmar una por una.**

> **No todas pueden llevar guard.** La sesión de `myvc_front` avisó (18 ago 2026)
> de que **`publicaciones/ultimas` la llama la propia pantalla de login**, con el
> usuario todavía sin autenticar. Si se protege, no se rompe una función suelta:
> se rompe la pantalla de entrada.
>
> Comprobado que hoy responde 200 sin token y que no lleva guard. **Debe seguir
> pública.**
>
> La lección vale para el resto de la lista: antes de proteger cualquiera de
> estas 37 hay que preguntar al front si la llama antes del login. Es lo que
> este análisis no puede saber leyendo el backend.

{{T_LEE_REV}}

### Públicas a propósito (lectura)

{{T_LEE_PUB}}

---

## 3. Métodos vacíos — {{VACIOS}}

La ruta está registrada pero el método no hace nada. No son agujeros: son
endpoints muertos. Se pueden borrar sin tocar nada más.

{{T_VACIOS}}

---

## 4. Rutas registradas cuyo método no existe — {{ROTAS}}

Rutas cuyo controlador no implementa el método. Revientan con 500 si alguien las
llama.

> Las tres que había —`tiposdocumento/create`, `tiposdocumento/{id}` y
> `tiposdocumento/{id}/edit`, del andamiaje de recurso de Laravel— se
> eliminaron el 18 ago 2026, comprobado antes que devolvían 500.

{{T_ROTAS}}
