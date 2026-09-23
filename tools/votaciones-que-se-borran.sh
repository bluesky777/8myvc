#!/usr/bin/env bash
#
# QUÉ SE PIERDE DE VOTACIONES SI SE DESPLIEGA ESTA TANDA — colegio por colegio.
#
#     RAIZ='/home/micolev1/*.micolevirtual.com/8myvc' tools/votaciones-que-se-borran.sh
#     RAIZ='/home/micolevi/public_html/8myvc'         tools/votaciones-que-se-borran.sh
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ EXISTE
#
# El rediseño de votaciones trae tres migraciones que **escriben sobre filas que
# ya existen**, y son las únicas de la tanda que lo hacen:
#
#   400000_los_grupos_dejan_de_ser_un_censo   `Schema::dropIfExists('vt_participantes')`
#   600000_el_voto_que_no_se_reemplaza        tres DELETE y dos UPDATE sobre `vt_votos`,
#                                             más `dropColumn('blanco_aspiracion_id')`
#   800000_el_blanco_del_acta_es_uno_solo     UPDATE y DELETE sobre `vt_acta_votos`
#
# De las tres, **sólo dos pueden llevarse algo de un colegio de hoy**. La 800000 es
# destructiva de forma pero no de fondo en este despliegue: `vt_acta_votos` **no
# existe** en el esquema desplegado --cero apariciones en `database/schema/mysql-schema.sql`
# de `e7ed5e75`-- y la crea vacía la 700000 unos segundos antes. Borra de una tabla
# que acaba de nacer. Por eso este guion no la mira.
#
# **Ninguna anota con `App\Support\RastroDeLaMigracion::anotar()`**, así que lo que
# se lleven no queda registrado en ninguna parte salvo el respaldo. Este guion
# contesta, ANTES de migrar y sobre la base de verdad de cada colegio, la única
# pregunta que decide si eso importa: *¿hay aquí alguna votación que valga algo, y
# de cuándo es?*
#
# ─────────────────────────────────────────────────────────────────────────────
# LO QUE CUENTA, Y POR QUÉ NO ES UN `COUNT(*)` A SECAS
#
# Los tres DELETE de la 600000 no borran «los votos»: borran tres conjuntos
# distintos, y en este orden, que importa.
#
#   1. LA PAPELERA     `deleted_at IS NOT NULL`. Se van enteros.
#   2. EL RELLENO      a cada voto vivo se le busca su cargo: por `candidato_id`
#                      -> `vt_candidatos` -> `vt_aspiraciones`, o, si no tiene
#                      candidato, por `blanco_aspiracion_id` -> `vt_aspiraciones`.
#   3. LOS SIN CARGO   los que el paso 2 no pudo resolver. Se van.
#   4. LOS DUPLICADOS  de cada `(votación, aspiración, votante)` sobrevive uno:
#                      el del `id` más pequeño. El resto se va.
#
# Aquí se reproducen esos cuatro conjuntos **sobre el esquema de hoy** —el de
# antes de migrar, donde `vt_votos` todavía no tiene `votacion_id` ni
# `aspiracion_id`—, con los mismos JOIN y el mismo criterio de desempate que el
# SQL de la migración. Por eso la columna `se_borran` es una predicción y no una
# estimación: es la misma pregunta, hecha antes.
#
# **SÓLO LEE.** No hay un `UPDATE`, un `DELETE` ni un `CREATE` en todo el fichero.
#
# ─────────────────────────────────────────────────────────────────────────────
# DOS DETALLES QUE SE PAGAN CAROS
#
# 1. **La clave NO va en la línea de comando.** En un alojamiento compartido el
#    `ps` lo ve cualquier otro inquilino del servidor. Va a un fichero temporal
#    con permisos 600 y de ahí a `--defaults-extra-file`, igual que en
#    `respaldo-antes-de-migrar.sh`.
# 2. **`tr -d '\r'`**: los `.env` de la cuenta de `lalvirtual` vienen con fin de
#    línea de Windows, y sin eso el nombre de la base sale con un `\r` pegado y la
#    conexión falla con un mensaje que no se parece a la causa.
#
set -u

RAIZ="${RAIZ:-/home/micolev1/*.micolevirtual.com/8myvc}"

tmp_cnf=""
limpiar() { [ -n "$tmp_cnf" ] && rm -f "$tmp_cnf"; }
trap limpiar EXIT INT TERM

# El informe. `vivos` son los votos que sobrevivirían a los pasos 1 a 3, que es
# contra lo que la migración busca los duplicados del paso 4.
consulta() {
    cat <<'SQL'
WITH voto_res AS (
    SELECT vo.id, vo.user_id, vo.deleted_at,
           COALESCE(a1.votacion_id, a2.votacion_id) AS votacion_id,
           COALESCE(a1.id, a2.id)                   AS aspiracion_id
      FROM vt_votos vo
      LEFT JOIN vt_candidatos   c  ON c.id  = vo.candidato_id
      LEFT JOIN vt_aspiraciones a1 ON a1.id = c.aspiracion_id
      LEFT JOIN vt_aspiraciones a2 ON a2.id = vo.blanco_aspiracion_id
                                  AND vo.candidato_id IS NULL
),
vivos AS (
    SELECT * FROM voto_res WHERE deleted_at IS NULL AND votacion_id IS NOT NULL
)
SELECT  v.id                                                 AS votacion,
        COALESCE(y.year, 0)                                  AS anio,
        CASE WHEN y.actual = 1 THEN 'ACTUAL' ELSE '-' END    AS es_el_anio_en_curso,
        COALESCE(DATE(v.fecha_inicio), DATE(v.created_at))   AS fecha,
        CASE WHEN v.deleted_at IS NOT NULL THEN 'EN-PAPELERA' ELSE 'viva' END AS votacion_estado,
        (SELECT COUNT(*) FROM vivos    w WHERE w.votacion_id = v.id) AS votos_que_quedan,
        (SELECT COUNT(*) FROM voto_res w WHERE w.votacion_id = v.id
                                           AND w.deleted_at IS NOT NULL) AS se_borran_papelera,
        (SELECT COUNT(*) FROM vivos x
          WHERE x.votacion_id = v.id
            AND EXISTS (SELECT 1 FROM vivos w
                         WHERE w.votacion_id   = x.votacion_id
                           AND w.aspiracion_id = x.aspiracion_id
                           AND w.user_id       = x.user_id
                           AND w.id            < x.id)) AS se_borran_duplicados,
        LEFT(v.nombre, 38)                                   AS nombre
   FROM vt_votaciones v
   LEFT JOIN years y ON y.id = v.year_id
  ORDER BY anio DESC, fecha DESC, v.id DESC;
SQL
}

# Los que no cuelgan de ninguna votación: no salen en la tabla de arriba porque no
# tienen a qué fila pertenecer, y son los del paso 3.
consulta_sueltos() {
    cat <<'SQL'
SELECT CONCAT(
    'votos sin cargo que se borran (paso 3): ',
    (SELECT COUNT(*) FROM vt_votos vo
      LEFT JOIN vt_candidatos   c  ON c.id  = vo.candidato_id
      LEFT JOIN vt_aspiraciones a1 ON a1.id = c.aspiracion_id
      LEFT JOIN vt_aspiraciones a2 ON a2.id = vo.blanco_aspiracion_id
                                  AND vo.candidato_id IS NULL
      WHERE vo.deleted_at IS NULL
        AND COALESCE(a1.votacion_id, a2.votacion_id) IS NULL),
    '   ·   filas de vt_participantes, tabla que se ELIMINA entera: ',
    (SELECT COUNT(*) FROM vt_participantes)
);
SQL
}

encontradas=0
for carpeta in $RAIZ; do
    [ -d "$carpeta" ] || continue
    printf '\n===== %s\n' "$carpeta"

    if [ ! -f "${carpeta}/.env" ]; then
        echo "  SIN .env — no se puede mirar esta carpeta" >&2
        continue
    fi

    leer() { grep -m1 "^$1=" "${carpeta}/.env" | cut -d= -f2- | tr -d '\r' | tr -d '"'"'"''; }
    base=$(leer DB_DATABASE); usuario=$(leer DB_USERNAME)
    clave=$(leer DB_PASSWORD); servidor=$(leer DB_HOST)
    [ -n "$servidor" ] || servidor=127.0.0.1

    if [ -z "$base" ] || [ -z "$usuario" ]; then
        echo "  El .env no dice DB_DATABASE o DB_USERNAME" >&2
        continue
    fi

    tmp_cnf=$(mktemp) || { echo "  no se pudo crear el temporal" >&2; continue; }
    chmod 600 "$tmp_cnf"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$usuario" "$clave" "$servidor" > "$tmp_cnf"
    mysql="mysql --defaults-extra-file=${tmp_cnf} ${base}"

    # ¿Está ya migrado este colegio? Si `vt_votos` ya no tiene `blanco_aspiracion_id`,
    # las tres migraciones ya corrieron aquí y lo que se fuera ya se fue.
    ya=$($mysql -N -B -e "SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA='${base}' AND TABLE_NAME='vt_votos'
                             AND COLUMN_NAME='blanco_aspiracion_id'" 2>/dev/null)
    if [ -z "$ya" ]; then
        echo "  NO SE PUDO CONSULTAR la base '${base}' (usuario, clave o host)" >&2
        rm -f "$tmp_cnf"; tmp_cnf=""
        continue
    fi
    if [ "$ya" = "0" ]; then
        echo "  YA MIGRADO (o sin tabla vt_votos): aquí esta tanda no borra nada de votaciones"
        rm -f "$tmp_cnf"; tmp_cnf=""
        continue
    fi

    cuantas=$($mysql -N -B -e "SELECT COUNT(*) FROM vt_votaciones" 2>/dev/null)
    if [ "${cuantas:-0}" = "0" ]; then
        echo "  SIN VOTACIONES — nada que perder"
    else
        consulta        | $mysql --table
        consulta_sueltos | $mysql -N -B | sed 's/^/  /'
        encontradas=$((encontradas + 1))
    fi

    rm -f "$tmp_cnf"; tmp_cnf=""
done

printf '\n===== %s colegio(s) con votaciones dentro\n' "$encontradas"
echo 'Las columnas `se_borran_*` son lo que se lleva la migración. `votos_que_quedan` es lo que sobrevive.'
