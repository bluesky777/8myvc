# Pendientes de despliegue

Cosas que hay que hacer **en el servidor**, no en el código. Se acumulan aquí para
no perderlas entre PRs.

---

## Del PR #3 (seguridad — 4 críticos)

### 1. ⚠️ Revisar `MAIL_*` en el `.env` de cada colegio — PENDIENTE

**Es el único cambio del PR que puede romper algo que hoy funciona.**

El reseteo de contraseña pasó de la función `mail()` de PHP al Mail de Laravel:

| | Antes | Después |
|---|---|---|
| Transporte | `mail()` → sendmail del sistema | Mail de Laravel → lo que diga `MAIL_MAILER` |
| Si falla | **Silencio.** Devolvía `Enviado` aunque no saliera nada | Lanza excepción → 500 con el motivo en `Log::error` |

Si en el servidor el correo salía por el sendmail del sistema y las `MAIL_*` del `.env`
no están bien puestas, el reseteo pasaría de fallar callado a devolver 500.

**Comprobar en cada colegio antes o justo después de desplegar:**

```bash
grep -E '^MAIL_' .env
```

- Si hay un SMTP real configurado y alcanzable → no hay que hacer nada.
- Si está con los valores de plantilla (`MAIL_HOST=mailhog`, `MAIL_FROM_ADDRESS=null`)
  → poner `MAIL_MAILER=sendmail`, que reproduce el comportamiento anterior.

**Prueba después de desplegar**, con un correo real que exista en `users`:

```bash
curl -s -X POST https://<colegio>/api/login/ver-pass \
  -H "Content-Type: application/json" \
  -d '{"email":"<correo real>","ruta":"https://<colegio>/myvc_front/"}'
```

`Enviado` = bien. Un 500 = revisar `storage/logs/laravel.log`, la causa está en un
`Log::error` con el mensaje de la excepción.

**Alternativa si molesta**, para eliminar el riesgo sin revisar nada: cambiar el
`abort(500, ...)` de `LoginController::postVerPass` por un `return 'Enviado'`. Deja
el arreglo de seguridad (ya no hay inyección de cabeceras) y recupera el fallo
silencioso de antes. Es una línea. No se hizo porque el fallo silencioso es en sí
mismo un problema: llevaba años diciendo a la gente "verifica tu correo" sin haber
enviado nada.

### 2. Definir `CORS_ALLOWED_ORIGINS` — PENDIENTE

Sin esta variable el arreglo de CORS **no hace nada**: el fallback sigue siendo `*`,
puesto a propósito para que desplegar el PR no tumbe nada.

```env
CORS_ALLOWED_ORIGINS=https://<dominio del colegio>
```

Varios orígenes van separados por comas. Documentado en `.env.example`.

### 3. Coordinar con el frontend — PENDIENTE

`myvc_front` tiene un typo en `PapeleraCtrl:62` (`'::'` en vez de `'=='`) que impide
abrir el modal de borrado definitivo de grupos. Ese typo es lo único que hoy tapa el
agujero de `grupos/forcedelete` desde la interfaz.

**El typo no debe arreglarse hasta que el backend de ese colegio tenga el guard
desplegado.** No basta con que el PR esté fusionado.

Como el frontend se publica **por colegio**, la condición es por pareja: el typo puede
salir en el colegio X cuando el backend del colegio X ya esté desplegado.

Avisar a la sesión/persona que lleve `myvc_front` cuando cada colegio esté listo.

---

## Nota general sobre despliegues

Cada colegio tiene su **propia copia real** de `app/` — no hay symlink a un código
común. Circulaba la creencia contraria (un proyecto compartido llamado `coal`) y es
falsa.

Implicación: **un arreglo fusionado no está desplegado.** Llega a cada colegio por su
propio despliegue, así que un agujero cerrado en `main` sigue abierto en cualquier
colegio que aún no haya recibido el código.
