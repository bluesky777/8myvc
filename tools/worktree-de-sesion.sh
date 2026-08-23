#!/usr/bin/env bash
#
# Un árbol de trabajo propio por sesión, aislado de verdad, dentro del proyecto.
#
# Existe porque la noche del 21 al 22 de agosto de 2026 cinco sesiones
# trabajaron sobre EL MISMO árbol y la misma rama, y cuatro commits se llevaron
# dentro trabajo ajeno. Las tres reglas que se probaron para evitarlo fallaron
# las tres (09-pendientes.md §0.1): con varias sesiones sobre un árbol, «lo
# arreglo en un momento» ya es una ventana. Con un árbol por sesión no hay
# ventana que cerrar.
#
# Uso:
#   tools/worktree-de-sesion.sh <sufijo> [rama]
#   tools/worktree-de-sesion.sh b fix/lo-que-toque
#
# Deja:
#   .worktrees/<sufijo>            el árbol, en su rama
#   simonbolivar_testing_<sufijo>  su base de tests, si no existía
#
# **El árbol va DENTRO del proyecto, y eso no es una preferencia.** El
# contenedor monta `/Users/.../8myvc` en `/app` y nada más: un worktree hermano
# —`../8myvc.worktrees/x`, que es lo que se intentó el 19 de agosto y quedó
# abandonado vacío— no lo ve el contenedor, así que no se le pueden correr los
# tests. Git **no desciende** dentro de un worktree registrado, pero **sí lista la
# carpeta** como sin seguimiento (`?? .worktrees/`, medido el 22 ago 2026: aquí
# decía que no y era falso). Va en `.git/info/exclude` del árbol raíz —local— y
# no en `.gitignore`, que se copia a los dieciséis colegios.
#
# ─────────────────────────────────────────────────────────────────────────────
# LA TRAMPA, que es la razón entera de que esto sea un script y no tres órdenes:
# **`vendor/` NO se puede enlazar con un symlink.** Es lo primero que se prueba
# —es lo que hace el despliegue con los dieciséis colegios (CLAUDE.md)— y aquí
# miente:
#
#   vendor/composer/autoload_psr4.php:  $baseDir = dirname(dirname(__DIR__))
#
# `__DIR__` en PHP resuelve los symlinks, así que con `vendor` enlazado el
# `$baseDir` sale `/app` y **el worktree carga el `app/` del árbol principal**.
# Medido: desde `.worktrees/prueba`, `ReflectionClass('App\...\AlumnosController')
# ->getFileName()` devolvía `/app/app/Http/Controllers/AlumnosController.php`.
# O sea que la sesión edita sus ficheros y prueba los de otra — la forma de
# fallo más cara de este repo, un instrumento que miente con la cara del
# problema, y esta vez con los tests en verde.
#
# Se vio primero por un sitio raro: `stan` daba **2 errores en el worktree y
# `[OK]` en el principal**, con el mismo fichero y el mismo `phpstan.neon`. Con
# `vendor/` copiado, los dos dan `[OK]`. Un `stan` que no coincide con el del
# árbol principal es la señal de que el aislamiento está roto.
#
# Por eso `vendor/` se copia con `cp -al` (enlaces duros): 40 segundos y ~12 MB
# reales de los 177 MB, porque los ficheros se comparten y solo se duplican las
# entradas de directorio. No se toca `vendor/` en una sesión; si alguna necesita
# un `composer install`, que lo diga antes: con enlaces duros escribiría también
# en el de las demás.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

cd "$(dirname "$0")/.."
RAIZ="$(pwd)"

SUFIJO="${1:-}"
[ -n "$SUFIJO" ] || { echo "Uso: tools/worktree-de-sesion.sh <sufijo> [rama]" >&2; exit 1; }
RAMA="${2:-sesion/$SUFIJO}"
DESTINO=".worktrees/$SUFIJO"
BD="simonbolivar_testing_$SUFIJO"
APP_EXEC="${APP_EXEC-docker exec 8myvc-app-1}"

[ -d "$DESTINO" ] && { echo "Ya existe $DESTINO" >&2; exit 1; }

echo "1/5  worktree $DESTINO en la rama $RAMA"
git worktree add "$DESTINO" -b "$RAMA"

echo "2/5  .env (enlazado: es configuración, no código)"
ln -s ../../.env "$DESTINO/.env"

echo "3/5  vendor/ copiado con enlaces duros — leer la cabecera antes de cambiar esto"
cp -al vendor "$DESTINO/vendor"

echo "4/5  carpetas que no están en git"
mkdir -p "$DESTINO"/bootstrap/cache \
         "$DESTINO"/storage/framework/{cache/data,sessions,testing,views} \
         "$DESTINO"/storage/{logs,app/public}
$APP_EXEC sh -c "cd /app/$DESTINO && php artisan package:discover" >/dev/null

echo "5/5  base de tests $BD"
MYSQL_EXEC="${MYSQL_EXEC-docker exec -i 8myvc-database-1}"
CLAVE="$(grep '^DB_PASSWORD=' .env | cut -d= -f2- || true)"
YA="$($MYSQL_EXEC mysql -uroot -p"$CLAVE" -N -e \
    "SELECT 1 FROM information_schema.schemata WHERE schema_name='$BD'" 2>/dev/null || true)"
if [ -z "$YA" ]; then
    DB_TEST_DATABASE="$BD" tools/construir-bd-test.sh >/dev/null
else
    echo "     ya existía; comprueba que no la esté usando otra sesión"
fi

# La base deriva sin avisar y es la trampa que más veces mordió (09 §0.0): una
# base construida ayer no tiene la migración que otra sesión añadió hoy, y lo
# que se ve NO es un error de infraestructura sino tests de contrato en rojo con
# mensajes creíbles. Diez segundos aquí ahorran media hora leyendo un
# controlador que está bien.
echo
echo "migraciones por base (tienen que coincidir):"
for db in $($MYSQL_EXEC mysql -uroot -p"$CLAVE" -N -e "SHOW DATABASES LIKE '%testing%'" 2>/dev/null); do
    printf "  %-32s %s\n" "$db" \
        "$($MYSQL_EXEC mysql -uroot -p"$CLAVE" -N -e "SELECT COUNT(*) FROM \`$db\`.migrations" 2>/dev/null)"
done

# La comprobación DA UN NÚMERO, no una presencia: que el árbol exista no dice
# nada sobre desde dónde carga las clases, que es lo único que importa aquí, y
# los tests pasan igual cuando está cargando el app/ de otro árbol.
echo
echo "comprobación de aislamiento:"
CARGA=$($APP_EXEC sh -c "cd /app/$DESTINO && php -r 'require \"vendor/autoload.php\"; \
    echo (new ReflectionClass(\"App\\\\Http\\\\Controllers\\\\AlumnosController\"))->getFileName();'")
echo "  AlumnosController se carga desde: $CARGA"
case "$CARGA" in
    "/app/$DESTINO/"*) echo "  OK — el worktree usa su propio app/" ;;
    *) echo "  ROTO — está usando el app/ de otro árbol. NO trabajes aquí." >&2; exit 1 ;;
esac

cat <<FIN

Listo. Desde ahora, en esta sesión:

  cd $RAIZ/$DESTINO
  docker exec -w /app/$DESTINO -e DB_TEST_DATABASE=$BD 8myvc-app-1 php artisan test
  docker exec -w /app/$DESTINO -e TMPDIR=/tmp/stan-$SUFIJO 8myvc-app-1 composer run stan
  docker exec -w /app/$DESTINO -e DB_TEST_DATABASE=$BD \\
      -e COBERTURA_RUTAS=/tmp/tocadas-$SUFIJO.txt 8myvc-app-1 php artisan test

El TMPDIR no es adorno: la caché de resultados de phpstan es /tmp/phpstan y la
comparten todos los árboles dentro del contenedor.

Al terminar, desde $RAIZ:
  git worktree remove $DESTINO      (o rm -rf y git worktree prune)
FIN
