# Pendientes de despliegue

Cosas que hay que hacer **en el servidor**, no en el código. Se acumulan aquí para
no perderlas entre PRs.

---

## Del PR #3 (seguridad — 4 críticos)

### 1. Poner el correo en `sendmail` — PENDIENTE

**Decidido: se configura `sendmail` sin auditar el `.env` de cada colegio.**
`sendmail` reproduce el camino que usaba la función `mail()`, así que es el valor
que menos probablemente cambie el comportamiento.

**En el `.env` de cada colegio:**

```env
MAIL_MAILER=sendmail
MAIL_FROM_ADDRESS=josethmaster@lalvirtual.com
MAIL_FROM_NAME="MiColegioVirtual"
```

Las dos de `FROM` **no son opcionales**. El código viejo llevaba el remitente
incrustado a mano en las cabeceras del `mail()`:

```php
$headers .= "From: MiColegioVirtual <josethmaster@lalvirtual.com>\r\n";
```

Ahora sale de la configuración. Si están vacías, Laravel **rechaza el envío antes
de intentarlo** y el reseteo devuelve 500. Los valores de arriba son exactamente
los que enviaba el código anterior, así que el correo sale igual que siempre.

**Comprobar en 30 segundos, sin provocar un reseteo real:**

```bash
php artisan correo:probar tu-correo@ejemplo.com
```

Imprime el transporte, si el binario de sendmail existe, el `sendmail_path` que
usa PHP y el remitente configurado. Si falla, dice cuál de los tres es.

**VERIFICADO EN EL SERVIDOR (17 ago 2026).** Ya no es una hipótesis. Medido con
un script temporal en el docroot, bajo el SAPI real:

| | |
|---|---|
| SAPI web | `litespeed` |
| PHP | 8.0.30, `php.ini` en `/opt/alt/php80/etc/php.ini` |
| `sendmail_path` | `/usr/sbin/sendmail -t -i` |
| binario | existe, ejecutable, no es enlace |
| `mail()` | disponible, `disable_functions` vacío |

**Web y CLI cargan el mismo `php.ini`**, comprobado por separado. Tres
consecuencias:

1. **`MAIL_SENDMAIL_PATH` se deja vacío.** El valor por defecto de
   `config/mail.php` resuelve a `/usr/sbin/sendmail -t -i` en ambos contextos.
2. **`config:cache` es seguro.** No hay divergencia CLI/web que congelar mal.
   Si algún día dejaran de coincidir, habría que fijar `MAIL_SENDMAIL_PATH`
   explícitamente antes de cachear.
3. **`correo:probar` es fiable.** Lo que reporta en la terminal es lo que hará
   el reseteo real por web.

Este servidor es exactamente el caso que motivó el cambio: el valor por defecto
de Laravel (`-bs`) habría fallado aquí con *"Expected response code 220 but got
an empty response"*. Leer el `php.ini` da el mismo invocador que usaba `mail()`.

Estado completo del entorno en `PHP-BASELINE.md`.

**Confirmar que salió de verdad:** cPanel → *Rastreo de entrega*. `correo:probar`
solo garantiza que Exim aceptó el mensaje, no que llegara. Ahí se vería un
problema de SPF con `lalvirtual.com`.

**Dato de la comprobación local, por si ayuda a interpretar un fallo:** en el
contenedor de desarrollo `mail()` **ya devolvía `false`** — `sendmail` es un
enlace a `busybox` y no hay ningún MTA. El código viejo ignoraba ese `false` y
respondía `Enviado` igual. O sea que en cualquier entorno donde el reseteo
"funcionara" sin enviar nada, esto lo va a destapar. Eso no es una regresión: es
el fallo haciéndose visible.

**Qué pasa si esto se olvida:** el reseteo devuelve 500 en vez de fallar en
silencio. A menos de un reseteo al día históricamente, la ventana de exposición
es pequeña, y la causa queda en `storage/logs/laravel.log` en un `Log::error`.
El token no se queda colgado: se borra al fallar el envío.

### 1b. OPcache está activa desde ahora — afecta a CÓMO se despliega

Se activó `opcache` el 17 ago 2026 (estaba apagada; Laravel recompilaba cada
archivo en cada request). Es una mejora grande, pero **cambia el despliegue**:
copiar ficheros ya no basta necesariamente, porque PHP puede seguir sirviendo el
bytecode viejo.

Comprobar el modo antes del primer despliegue:

```bash
php -i | grep -E "opcache.(enable|validate_timestamps|revalidate_freq)"
```

- `opcache.validate_timestamps = 1` (lo habitual): PHP releé los ficheros
  cambiados cada `revalidate_freq` segundos. No hay que hacer nada.
- `opcache.validate_timestamps = 0`: **el código nuevo no se aplica nunca** hasta
  reiniciar. Con LiteSpeed, cPanel → *Restart PHP*, o `killall lsphp`.

Si tras desplegar el arreglo de correo el comportamiento sigue siendo el viejo,
esto es lo primero que hay que mirar — no el `.env`.

Se activó también `sodium`, que faltaba: `lcobucci/jwt` la declara requisito
duro y `composer install` habría fallado el chequeo de plataforma.

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

## Del PR #7 (auditoría de autenticación)

### 1. Avisar al frontend ANTES de desplegar — 58 rutas pasan a exigir token

Este PR sí cambia el comportamiento. 58 rutas que hoy responden sin token pasan
a devolver **401**. Si el front de un colegio llama a alguna sin mandar el token,
deja de funcionar en cuanto se despliegue el backend allí.

La lista está en `docs/migracion/04-auditoria-autenticacion.md`. Hay tests que
comprueban que un usuario con sesión válida pasa, pero **no** pueden comprobar
que el front mande siempre el token.

### 2. `login/ver-pass` se renombra a `login/recuperar-clave` — alias temporal

El nombre viejo engañaba: no muestra ninguna contraseña, manda el correo de
reseteo. **Las dos rutas funcionan**, apuntan al mismo método, así que el
backend se puede desplegar antes que el front.

**El alias se borra cuando el front de TODOS los colegios use la ruta nueva.**
Como cada colegio publica su front por separado, hay que confirmarlo colegio a
colegio, no una vez. `tests/Contrato/RecuperarClaveTest.php` falla el día que se
borre, como recordatorio de que hay que comprobarlo.

### 3. El reseteo ya no dice si un correo existe — cambia lo que ve el usuario

Antes devolvía `'No existe'` para un correo no registrado y `'Enviado'` para uno
registrado, y con eso cualquiera podía averiguar qué correos están dados de alta
en el colegio probándolos uno a uno. **Ahora devuelve `'Enviado'` en ambos
casos.**

Si el front muestra un mensaje distinto según la respuesta, hay que cambiarlo:
ya no puede decir "ese correo no está registrado". Lo correcto es un mensaje
neutro del tipo *"Si el correo está registrado, te llegará un enlace"*.

---

## Nota general sobre despliegues

Cada colegio tiene su **propia copia real** de `app/` — no hay symlink a un código
común. Circulaba la creencia contraria (un proyecto compartido llamado `coal`) y es
falsa.

Implicación: **un arreglo fusionado no está desplegado.** Llega a cada colegio por su
propio despliegue, así que un agujero cerrado en `main` sigue abierto en cualquier
colegio que aún no haya recibido el código.
