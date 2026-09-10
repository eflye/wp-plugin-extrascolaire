#!/usr/bin/env bash
set -euo pipefail

# Vérifie qu'un dump MySQL peut être restauré sans toucher à la base source.
# La base de restauration est créée sur le serveur de l'environnement jetable,
# comparée au dump, puis supprimée même en cas d'échec.

engine="${PSC_CONTAINER_ENGINE:-docker}"
source_db="${PSC_SOURCE_DB:-wordpress}"
restore_db="${PSC_RESTORE_DB:-wordpress_step01_restore}"
db_user="${PSC_DB_ROOT_USER:-root}"
db_password="${PSC_DB_ROOT_PASSWORD:-rootpassword}"

if [[ ! "$source_db" =~ ^[A-Za-z0-9_]+$ || ! "$restore_db" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Les noms de bases doivent contenir uniquement lettres, chiffres et underscore." >&2
    exit 2
fi
if [[ "$source_db" == "$restore_db" ]]; then
    echo "La base source et la base de restauration doivent être différentes." >&2
    exit 2
fi

compose=("$engine" compose)
backup_file="$(mktemp "${TMPDIR:-/tmp}/psc-backup.XXXXXX.sql")"
restored_file="$(mktemp "${TMPDIR:-/tmp}/psc-restored.XXXXXX.sql")"
backup_canonical="$(mktemp "${TMPDIR:-/tmp}/psc-backup-canonical.XXXXXX.sql")"
restored_canonical="$(mktemp "${TMPDIR:-/tmp}/psc-restored-canonical.XXXXXX.sql")"
chmod 600 "$backup_file" "$restored_file" "$backup_canonical" "$restored_canonical"

db_exec() {
    "${compose[@]}" exec -T -e MYSQL_PWD="$db_password" db "$@"
}

cleanup() {
    db_exec mysql -u"$db_user" -e "DROP DATABASE IF EXISTS \`$restore_db\`;" >/dev/null 2>&1 || true
    rm -f "$backup_file" "$restored_file" "$backup_canonical" "$restored_canonical"
}
trap cleanup EXIT

dump_options=(
    --single-transaction
    --quick
    --skip-comments
    --skip-add-locks
    --skip-column-statistics
    --set-gtid-purged=OFF
    --no-tablespaces
)

echo "Sauvegarde transactionnelle de ${source_db}…"
db_exec mysqldump -u"$db_user" "${dump_options[@]}" "$source_db" >"$backup_file"
test -s "$backup_file"

echo "Restauration dans la base isolée ${restore_db}…"
read -r source_charset source_collation < <(db_exec mysql -N -u"$db_user" -e \
    "SELECT default_character_set_name, default_collation_name FROM information_schema.schemata WHERE schema_name = '$source_db';")
if [[ ! "$source_charset" =~ ^[A-Za-z0-9_]+$ || ! "$source_collation" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Impossible de lire le charset et la collation de la base source." >&2
    exit 1
fi
db_exec mysql -u"$db_user" -e "DROP DATABASE IF EXISTS \`$restore_db\`; CREATE DATABASE \`$restore_db\` CHARACTER SET $source_charset COLLATE $source_collation;"
db_exec mysql -u"$db_user" "$restore_db" <"$backup_file"

echo "Comparaison exacte du schéma et des données restaurés…"
db_exec mysqldump -u"$db_user" "${dump_options[@]}" "$restore_db" >"$restored_file"
# MySQL 8 peut rendre « CHARACTER SET utf8mb4 » explicite au second dump
# alors que la COLLATION, qui implique déjà ce charset, est inchangée. Cette
# seule redondance syntaxique est canonisée ; tout le reste (DDL, index et
# INSERT) reste comparé octet pour octet.
sed -E 's/ CHARACTER SET [A-Za-z0-9_]+//g' "$backup_file" >"$backup_canonical"
sed -E 's/ CHARACTER SET [A-Za-z0-9_]+//g' "$restored_file" >"$restored_canonical"
if ! cmp -s "$backup_canonical" "$restored_canonical"; then
    echo "La base restaurée diffère de la sauvegarde." >&2
    diff -u "$backup_canonical" "$restored_canonical" | head -100 >&2 || true
    exit 1
fi

table_count="$(db_exec mysql -N -u"$db_user" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$restore_db';")"
echo "Restauration vérifiée : $table_count tables, dump temporaire supprimé."
