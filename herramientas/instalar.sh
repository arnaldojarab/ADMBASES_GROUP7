#!/bin/bash
# Prepara las carpetas privadas de la Consola DBA en la cuenta actual.
# Uso (desde ~/public_html/dba):  bash herramientas/instalar.sh
# No sobrescribe archivos que ya existan.

set -e
cd "$(dirname "$0")"

for d in "$HOME/dba_config" "$HOME/dba_scripts" "$HOME/respaldos" "$HOME/dba_planes"; do
    mkdir -p "$d"
    chmod 700 "$d"
done

if [ ! -f "$HOME/dba_config/config.php" ]; then
    cp config.ejemplo.php "$HOME/dba_config/config.php"
    echo "Creado  ~/dba_config/config.php  (falta pegar el hash)"
else
    echo "Existe  ~/dba_config/config.php  (no se tocó)"
fi

if [ ! -f "$HOME/dba_config/db.cnf" ]; then
    sed -e "s/USUARIO_LINUX/$(whoami)/" db.ejemplo.cnf > "$HOME/dba_config/db.cnf"
    echo "Creado  ~/dba_config/db.cnf      (falta la clave y el nombre de la base)"
else
    echo "Existe  ~/dba_config/db.cnf      (no se tocó)"
fi

chmod 600 "$HOME/dba_config/config.php" "$HOME/dba_config/db.cnf"

echo
ls -ld "$HOME/dba_config" "$HOME/dba_scripts" "$HOME/respaldos" "$HOME/dba_planes"
ls -l "$HOME/dba_config"

# Script de respaldos (Módulo 02): se copia fuera de la web
cp backup.sh "$HOME/dba_scripts/backup.sh"
chmod 700 "$HOME/dba_scripts/backup.sh"
echo "Copiado ~/dba_scripts/backup.sh (700)"
