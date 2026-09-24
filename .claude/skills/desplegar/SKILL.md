---
name: desplegar
description: Desplegar el backend 8myvc a los colegios de producción (las dos cuentas de cPanel). Úsalo cuando Joseth diga «despliega», «deploy», «pon al día los colegios», «sube esto a producción» o pregunte si los colegios están al día. Conduce la tanda entera - el plan, las preguntas, el despliegue y la comprobación - con tools/desplegar.sh y tools/censo.sh.
---

# Desplegar a los colegios

Joseth **no se sabe los pasos y no tiene por qué**. Él dice «despliega»; tú conduces,
preguntas lo imprescindible y le enseñas números, no procedimientos.

Todo esto son **dieciocho colegios de verdad con clases dentro**. La regla que manda:
*el guion prepara y comprueba, la persona decide.*

## 1. Enseñar el plan antes de preguntar nada

```bash
cd ~/DESARROLLOS/8myvc && tools/desplegar.sh
```

No toca nada. Devuelve, por colegio: hash actual, commits pendientes, cuántas
migraciones traería, si `composer.lock` cambia, si `vendor` es symlink y si el árbol
está limpio. **Si dice «18 ya al día», dilo en una línea y para aquí**: no hay tanda.

## 2. Las preguntas, y sólo si el plan las justifica

Pregunta con `AskUserQuestion`, opciones cortas y el precio delante. Nunca preguntes
lo que el plan ya contesta.

- **Si hay migraciones pendientes** → *¿cuándo?* Entre el `pull` y el `migrate` ese
  colegio **da 500**. Son segundos, pero en horario de clase son segundos con gente
  dentro. Mira la hora de Colombia (UTC-5): la jornada de mañana va de 6:30 a 12:30.
  Recomienda fuera de ese rango y deja que él decida; si dice «ahora», es su decisión,
  se hace y no se insiste.
- **Si alguna migración borra o reescribe filas** → enséñale **el número**, no el
  riesgo en abstracto. Se mira así, antes de tocar nada:
  ```bash
  git diff --name-only <hash-del-colegio> origin/main -- database/migrations
  git show origin/main:<fichero> | grep -nE "DELETE|UPDATE|dropColumn"
  ```
  Muchas migraciones llevan **la autorización escrita en su propia cabecera** («esto es
  irreversible y está autorizado: Joseth, fecha, ...»). Si la lleva, cítala: la
  decisión ya está tomada y no se vuelve a abrir.
- **Si el plan marca alguno PARADO por `composer.lock`** → ese colegio necesita
  `composer install` y es otro procedimiento. Pregunta si se despliegan los demás y se
  deja ése aparte.
- **Si algún árbol sale SUCIO** → no se despliega ese: alguien tocó ficheros en el
  servidor. Enséñale `git status` de ese colegio y pregunta.

## 3. Desplegar

```bash
tools/desplegar.sh --ejecutar              # todos
tools/desplegar.sh --ejecutar --solo lal   # uno
```

El guion respalda antes de migrar donde hay migraciones, se para en seco si la tanda
toca `composer.lock` o el árbol está sucio, y **si un `migrate` falla detiene la tanda
entera** e imprime el camino de vuelta. Queda registro en `despliegues.log`.

Si se detiene: **no improvises un arreglo**. Enseña el estado —ese colegio tiene código
nuevo y base vieja, da 500—, el `git reset --hard` y el respaldo que el propio guion
imprime, y pregunta antes de tocar nada.

## 4. Comprobar, siempre

```bash
tools/censo.sh                             # ¿quedaron todos en el mismo hash?
```

Y que los sitios contestan (302 es lo normal, es la redirección al login):

```bash
for h in bethelexplora cads casb caz coab comad maranatha bethel coaf inseaq \
         coljordan eal colbosque semillitasdedios coal amiguitosdejesus demo; do
  printf '%-18s %s\n' "$h" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://$h.micolevirtual.com/)"
done
printf '%-18s %s\n' lalvirtual.edu.co "$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://lalvirtual.edu.co/)"
```

## 5. Avisar a las otras sesiones

Si la tanda llevaba cambios en `routes/api.php`, en notificaciones o en el modelo de
datos, avisa por `SendMessage` a las sesiones vivas de 8myvc y de Flutter: su código de
producción cambió bajo sus pies y una medición de antes ya no vale. `ListAgents` primero
— **los nombres cambian entre sesiones**, no des por hecho el de ayer.

## Lo que hace falta y no es de este repositorio

- La clave `~/.ssh/cpanel`, instalada en las dos cuentas. Si algo dice
  `Permission denied`, es eso: `ssh-copy-id -i ~/.ssh/cpanel -p 7822 <usuario@host>`,
  y esa orden **la teclea Joseth**, porque pide contraseña.
- Las dos cuentas: `micolev1@70.32.23.72` (17 carpetas) y
  `micolevi@lalvirtual.edu.co` (LAL), las dos por el puerto **7822**.

## Dos cosas que este skill no decide nunca

1. **Desplegar sin que Joseth lo haya pedido.** Ni siquiera «ya que estamos».
2. **Revertir una migración.** Un `rollback` no devuelve las filas borradas; salen de un
   respaldo o no salen. Si hace falta volver atrás, se para y se pregunta.
