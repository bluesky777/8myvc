---
name: desplegar
description: Desplegar a los colegios de producción - el backend 8myvc (las dos cuentas de cPanel) y el front (up y up2, por las Actions de myvc_front). Úsalo cuando Joseth diga «despliega», «deploy», «pon al día los colegios», «sube esto a producción» o pregunte si los colegios están al día. Conduce la tanda entera - el plan, las preguntas, el despliegue y la comprobación - con tools/desplegar.sh y tools/censo.sh.
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

**Cuánto tarda** (medido el 24 sep 2026, 18 destinos, 46 commits, 6 migraciones nuevas):

    plan (tools/desplegar.sh)          ~30 s
    --ejecutar, respaldo + pull +      185 s  (unos 10 s por colegio; lal y maranatha,
      migrate de los 18                        las bases grandes, unos 20 s)
    censo + curl de los 18             ~30 s
    bajar respaldos al Mac             lo largo: ~1 min por respaldo de 8–40 MB, así que
                                       media hora o más para una tanda completa

Cada colegio da 500 como mucho durante sus ~10 s (entre su `pull` y su `migrate`, menos aún), no durante los tres minutos. Si `ssh` contesta
«Connection closed» o «Connection reset» al empezar, es el servidor limitando conexiones
seguidas: se repite en un minuto y entra.

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

## 5. Bajar los respaldos al Mac, siempre que hubo migraciones

Los respaldos que hace `desplegar.sh` antes de migrar **se quedan en los servidores**. En
cuanto la comprobación del paso 4 sale bien, y sin preguntar:

```bash
tools/bajar-respaldos-del-despliegue.sh           # los de hoy; otra fecha: ... 2026-09-24
```

Los deja en `~/DESARROLLOS/respaldos-myvc/antes-de-migrar/<fecha>/` y pasa `gzip -t` a cada
uno. Dile a Joseth en una línea cuántos son, cuánto pesan y si alguno salió corrupto. Si el
guion sale con 1, eso es lo primero que se cuenta. (No es `bajar-respaldos.sh`, que trae
los volcados diarios del cron.)

**Si el inventario del final dice «Hay más de 4. Se podrían borrar: …»**, pregúntale con
`AskUserQuestion` qué fechas se borran: las candidatas con lo que pesan, y la opción de no
borrar ninguna. Por cada una que elija, `tools/bajar-respaldos-del-despliegue.sh --borrar
FECHA`. **Nunca se borran la más reciente ni la más cercana a hace un mes**: ésa es la que
sirve para comparar en una emergencia. El guion no las propone y `--borrar` se niega a
tocarlas. No hay que buscarle la vuelta.

## 6. El front: empujar `main` y lanzar up y up2 — **en la misma tanda, pedido por Joseth el 24 sep 2026**

«Despliega» es el backend **y** el front. Va **después** del backend, porque el front nuevo puede
llamar a rutas o columnas que sólo trae el `8myvc` nuevo.

```bash
cd ~/DESARROLLOS/myvc_front
git log --oneline origin/main..main       # lo que se va a subir; enséñaselo en una línea
git push origin main
gh workflow run desplegar-up.yml  --ref main -f simulacro=false
gh workflow run desplegar-up2.yml --ref main -f simulacro=false
gh run list -w desplegar-up2.yml -L 1     # y esperar a que los dos salgan `success`
```

- **`-f simulacro=false` NO ES OPCIONAL.** El input `simulacro` nace en `true`: sin él, los dos
  workflows construyen, verifican y salen en verde **sin publicar nada** en `myvc_dist`. Pasó el
  25 sep 2026: dos `success` y el front de los colegios seguía en el build de la víspera. Se
  comprueba en el resumen del run («Publicado en…» y no «Simulacro») y en `tools/censo.sh`: la
  columna `up` tiene que cambiar de hash.
- **Sólo commits**: el árbol lo comparten otras sesiones; nunca `git add -A` antes de empujar.
- Los dos workflows construyen en GitHub y publican en `myvc_dist`; el servidor lo recoge. Se
  comprueba con `tools/censo.sh` (columnas `up` y `up2`): todos los colegios en el mismo hash.
- El workflow `build` sale en rojo y **no es de esto**: es el CI de la app vieja.
- Medido el 24 sep: el `gh workflow run` lo aceptó el clasificador cuando Joseth lo pidió en
  el mensaje; antes lo había bloqueado. Si lo bloquea, se le da la orden y la lanza él.

## 7. Avisar a las otras sesiones

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
