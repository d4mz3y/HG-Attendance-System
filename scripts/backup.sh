#!/bin/sh
set -eu
umask 077

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
backup_dir=${BACKUP_DIR:-/mnt/attendance-backups}
retention_days=${BACKUP_RETENTION_DAYS:-30}
skip_retention=${BACKUP_SKIP_RETENTION:-0}
compose() {
    env \
        -u APP_ENV_FILE -u APP_IMAGE_TAG -u APP_KEY -u APP_URL -u APP_PORT \
        -u ZEROTIER_BIND_ADDRESS -u LAN_BIND_ADDRESS \
        -u TLS_CERT_PATH -u TLS_KEY_PATH -u TLS_KEY_GROUP_ID \
        -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD -u DB_ROOT_PASSWORD \
        -u PAYSTACK_PUBLIC_KEY -u PAYSTACK_SECRET_KEY -u PAYSTACK_CURRENCY \
        -u PAYSTACK_MONTHLY_AMOUNT -u PAYSTACK_YEARLY_AMOUNT \
        -u SUBSCRIPTION_TRIAL_START -u SUBSCRIPTION_TRIAL_END -u BILLING_EMAIL \
        docker compose --project-directory "$project_dir" --project-name hg-attendance \
        --env-file "$project_dir/.env.docker" -f "$project_dir/docker-compose.yml" "$@"
}

case "$backup_dir" in
    /mnt/*|/media/*|/run/media/*) ;;
    *) echo "BACKUP_DIR must be an explicit directory below /mnt, /media, or /run/media." >&2; exit 1 ;;
esac

case "$retention_days" in
    ''|*[!0-9]*) echo "BACKUP_RETENTION_DAYS must be a non-negative integer." >&2; exit 1 ;;
esac

case "$skip_retention" in
    0|1) ;;
    *) echo "BACKUP_SKIP_RETENTION must be 0 or 1." >&2; exit 1 ;;
esac

if [ ! -f "$project_dir/.env.docker" ]; then
    echo "Missing $project_dir/.env.docker" >&2
    exit 1
fi

command -v realpath >/dev/null 2>&1 || { echo "realpath is required." >&2; exit 1; }
command -v findmnt >/dev/null 2>&1 || { echo "findmnt is required." >&2; exit 1; }

if [ ! -d "$backup_dir" ] || [ ! -w "$backup_dir" ]; then
    echo "Backup destination is not mounted and writable: $backup_dir" >&2
    exit 1
fi

backup_dir=$(realpath -e -- "$backup_dir")
case "$backup_dir" in
    /mnt/*|/media/*|/run/media/*) ;;
    *) echo "The resolved backup directory is outside /mnt, /media, or /run/media." >&2; exit 1 ;;
esac

mount_target=$(findmnt -n -o TARGET --target "$backup_dir")
case "$mount_target" in
    /mnt|/mnt/*|/media|/media/*|/run/media|/run/media/*) ;;
    *) echo "BACKUP_DIR is not on a separately mounted backup filesystem: $backup_dir" >&2; exit 1 ;;
esac
test "$(stat -c '%d' "$backup_dir")" != "$(stat -c '%d' "$project_dir")" || {
    echo "BACKUP_DIR is on the same filesystem as the application; refusing to fill the server disk." >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || { echo "Docker is required." >&2; exit 1; }
compose config --quiet
compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqladmin ping -h 127.0.0.1 -u"$MYSQL_USER" --silent' >/dev/null

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
work_dir=$(mktemp -d "$backup_dir/.hg-attendance-work.XXXXXX")
app_was_running=0
app_stopped=0
cleanup() {
    status=$?
    rm -rf "$work_dir"
    if [ "$app_was_running" -eq 1 ] && [ "$app_stopped" -eq 1 ]; then
        echo "Backup interrupted; restarting the application." >&2
        compose start app >/dev/null 2>&1 || true
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
chmod 700 "$work_dir"
mkdir "$work_dir/storage-app"
chmod 777 "$work_dir/storage-app"

cd "$project_dir"
if [ -n "$(compose ps --status running --quiet app)" ]; then
    app_was_running=1
    app_stopped=1
    compose stop --timeout 60 app >/dev/null
fi

compose exec -T mysql sh -eu -c '
    umask 077
    credentials=$(mktemp)
    trap '\''rm -f "$credentials"'\'' EXIT HUP INT TERM
    printf "[client]\nuser=root\npassword=%s\nhost=127.0.0.1\n" "$MYSQL_ROOT_PASSWORD" > "$credentials"
    mysqldump --defaults-extra-file="$credentials" --single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"
' > "$work_dir/database.sql"

compose run --rm --no-deps --entrypoint sh \
    -v "$work_dir/storage-app:/backup-storage:rw" app \
    -c 'set -eu
        mkdir -p /backup-storage/private /backup-storage/public
        test -z "$(find /var/www/html/storage/app/private /var/www/html/storage/app/public ! -type d ! -type f -print -quit)" || { echo "Refusing to back up a link or special file in application storage." >&2; exit 1; }
        cp -R /var/www/html/storage/app/private/. /backup-storage/private/
        cp -R /var/www/html/storage/app/public/. /backup-storage/public/
        find /backup-storage -mindepth 1 -type d -exec chmod 0777 {} +
        find /backup-storage -type f -exec chmod 0644 {} +' >/dev/null

if [ "$app_was_running" -eq 1 ]; then
    compose start app >/dev/null
    attempt=0
    until compose exec -T app curl --fail --insecure --silent https://127.0.0.1:8443/up >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "The application did not become healthy after the backup." >&2
            exit 1
        fi
        sleep 2
    done
    app_stopped=0
fi

revision=$(git rev-parse --verify HEAD 2>/dev/null || printf 'unknown')
if git diff --quiet --ignore-submodules HEAD -- 2>/dev/null && [ -z "$(git status --porcelain --untracked-files=normal 2>/dev/null)" ]; then
    tree_state=clean
else
    tree_state=dirty
fi
cat > "$work_dir/manifest.txt" <<EOF
created_utc=$timestamp
application_revision=$revision
application_tree=$tree_state
database_format=mysql-logical-dump
application_files_paths=storage/app/private,storage/app/public
EOF

plain_archive="$work_dir/hg-attendance-$timestamp.tar.gz"
tar -C "$work_dir" -czf "$plain_archive" database.sql manifest.txt storage-app
chmod 600 "$plain_archive"

if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then
    command -v age >/dev/null 2>&1 || {
        echo "BACKUP_AGE_RECIPIENT is set, but the age command is not installed." >&2
        exit 1
    }
    archive="$backup_dir/hg-attendance-$timestamp.tar.gz.age"
    staged_archive="$work_dir/$(basename -- "$archive")"
    age --recipient "$BACKUP_AGE_RECIPIENT" --output "$staged_archive" "$plain_archive"
    chmod 600 "$staged_archive"
else
    archive="$backup_dir/hg-attendance-$timestamp.tar.gz"
    staged_archive="$plain_archive"
fi

archive_dir=$(dirname -- "$archive")
archive_name=$(basename -- "$archive")
test ! -e "$archive" && test ! -e "$archive.sha256" || {
    echo "Refusing to overwrite an existing backup for timestamp $timestamp." >&2
    exit 1
}
checksum_file="$work_dir/$archive_name.sha256"
checksum=$(sha256sum "$staged_archive" | awk '{ print $1 }')
printf '%s  %s\n' "$checksum" "$archive_name" > "$checksum_file"
chmod 600 "$checksum_file"
mv "$staged_archive" "$archive"
mv "$checksum_file" "$archive.sha256"
(cd "$archive_dir" && sha256sum --check --status "$archive_name.sha256")

if [ "$skip_retention" -eq 0 ]; then
    find "$backup_dir" -maxdepth 1 -type f -name 'hg-attendance-*.tar.gz' -mtime "+$retention_days" -delete
    find "$backup_dir" -maxdepth 1 -type f -name 'hg-attendance-*.tar.gz.age' -mtime "+$retention_days" -delete
    find "$backup_dir" -maxdepth 1 -type f -name 'hg-attendance-*.sha256' -mtime "+$retention_days" -delete
fi

echo "Backup created and verified: $archive"
