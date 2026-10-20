#!/bin/bash
# backup.sh - Respaldo de la base de datos de la cuenta (Consola DBA)

set -o pipefail                 # si falla mariadb-dump, falla toda la tuberia

# Ubicarse en la carpeta del script (necesario cuando lo ejecute PHP)
cd "$(dirname "$0")" || exit 1

CNF="../dba_config/db.cnf"
DESTINO="../respaldos"
LOG="$DESTINO/respaldos.log"
BD="$(grep '^database=' "$CNF" | cut -d= -f2)"
FECHA="$(date +%F_%H%M%S)"
ARCHIVO="respaldo_${BD}_${FECHA}.sql.gz"

echo "----- Generando $ARCHIVO"
if mariadb-dump --defaults-extra-file="$CNF" "$BD" | gzip > "$DESTINO/$ARCHIVO"; then
    chmod 600 "$DESTINO/$ARCHIVO"
    echo "$(date '+%F %T') | $ARCHIVO | OK" >> "$LOG"
    echo "      OK"
else
    rm -f "$DESTINO/$ARCHIVO"
    echo "$(date '+%F %T') | $ARCHIVO | ERROR" >> "$LOG"
    echo "      FALLO el respaldo"
    exit 1
fi
