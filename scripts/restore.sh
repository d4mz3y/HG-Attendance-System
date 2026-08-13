#!/bin/sh
set -eu
umask 077

assume_yes=0
if [ "${1:-}" = "--yes" ]; then
    assume_yes=1
    shift
fi

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 [--yes] /mnt/attendance-backups/hg-attendance-TIMESTAMP.tar.gz[.age]" >&2
    exit 1
fi

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
requested_archive=$1
test -f "$requested_archive" || { echo "Backup does not exist: $requested_archive" >&2; exit 1; }
command -v realpath >/dev/null 2>&1 || { echo "realpath is required." >&2; exit 1; }
command -v findmnt >/dev/null 2>&1 || { echo "findmnt is required." >&2; exit 1; }
archive=$(realpath -e -- "$requested_archive")
case "$archive" in
    /mnt/*/hg-attendance-*.tar.gz|/mnt/*/hg-attendance-*.tar.gz.age|\
    /media/*/hg-attendance-*.tar.gz|/media/*/hg-attendance-*.tar.gz.age|\
    /run/media/*/hg-attendance-*.tar.gz|/run/media/*/hg-attendance-*.tar.gz.age) ;;
    *) echo "Refusing an unexpected restore path: $archive" >&2; exit 1 ;;
esac

test -f "$archive.sha256" || { echo "Checksum does not exist: $archive.sha256" >&2; exit 1; }

archive_dir=$(dirname -- "$archive")
archive_name=$(basename -- "$archive")
mount_target=$(findmnt -n -o TARGET --target "$archive_dir")
case "$mount_target" in
    /mnt|/mnt/*|/media|/media/*|/run/media|/run/media/*) ;;
    *) echo "The restore archive is not on a separately mounted backup filesystem." >&2; exit 1 ;;
esac
test "$(stat -c '%d' "$archive_dir")" != "$(stat -c '%d' "$project_dir")" || {
    echo "The restore archive is on the application filesystem, not a separate backup filesystem." >&2
    exit 1
}

expected_checksum=$(awk 'NR == 1 { print $1 }' "$archive.sha256")
case "$expected_checksum" in
    *[!0-9a-fA-F]*|'') echo "Checksum file is invalid." >&2; exit 1 ;;
esac
test "${#expected_checksum}" -eq 64 || { echo "Checksum file is invalid." >&2; exit 1; }
actual_checksum=$(sha256sum "$archive" | awk '{ print $1 }')
test "$actual_checksum" = "$expected_checksum" || { echo "Backup checksum verification failed." >&2; exit 1; }
echo "$archive_name: OK"

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
work_dir=$(mktemp -d "$archive_dir/.hg-attendance-restore.XXXXXX")
app_was_running=0
app_stopped=0
data_replaced=0
cleanup() {
    status=$?
    rm -rf "$work_dir"
    if [ "$status" -ne 0 ] && [ "$data_replaced" -eq 1 ]; then
        compose stop app >/dev/null 2>&1 || true
        echo "Restore failed after production data was changed. The application remains stopped; recover from the safety backup before restarting it." >&2
    elif [ "$status" -ne 0 ] && [ "$app_stopped" -eq 1 ]; then
        if [ "$app_was_running" -eq 1 ]; then
            echo "Restore failed before production data changed; attempting to restart the application." >&2
            compose up -d app >/dev/null 2>&1 || true
        else
            echo "Restore failed before production data changed; the application remains in its original stopped state." >&2
        fi
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
chmod 700 "$work_dir"

plain_archive=$archive
case "$archive" in
    *.age)
        command -v age >/dev/null 2>&1 || { echo "The age command is required to decrypt this backup." >&2; exit 1; }
        test -n "${AGE_IDENTITY_FILE:-}" || { echo "Set AGE_IDENTITY_FILE to the private age identity file." >&2; exit 1; }
        test -f "$AGE_IDENTITY_FILE" || { echo "AGE_IDENTITY_FILE does not exist." >&2; exit 1; }
        identity_mode=$(stat -c '%a' "$AGE_IDENTITY_FILE")
        case "$identity_mode" in 400|600) ;; *) echo "AGE_IDENTITY_FILE must have mode 400 or 600." >&2; exit 1 ;; esac
        plain_archive="$work_dir/archive.tar.gz"
        age --decrypt --identity "$AGE_IDENTITY_FILE" --output "$plain_archive" "$archive"
        ;;
esac

archive_list="$work_dir/archive.list"
verbose_archive_list="$work_dir/archive.verbose-list"
tar -tzf "$plain_archive" > "$archive_list" || { echo "Backup is not a readable gzip-compressed tar archive." >&2; exit 1; }
tar -tvzf "$plain_archive" > "$verbose_archive_list" || { echo "Backup metadata could not be read." >&2; exit 1; }

if awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    ! /^(database\.sql|manifest\.txt|storage-app(\/(private|public)(\/.*)?|\/)?|uploads(\/.*)?)$/ { bad=1 }
    seen[$0]++ { bad=1 }
    END { exit bad }
' "$archive_list"; then
    :
else
    echo "Backup contains an unsafe or unexpected path; refusing to restore it." >&2
    exit 1
fi

if awk 'substr($1, 1, 1) !~ /^[-d]$/ { bad=1 } END { exit bad }' "$verbose_archive_list"; then
    :
else
    echo "Backup contains a link or special file; refusing to restore it." >&2
    exit 1
fi

tar -C "$work_dir" --no-same-owner --no-same-permissions --mode='u+rwX' -xzf "$plain_archive"
test -s "$work_dir/database.sql" || { echo "Backup has no database dump." >&2; exit 1; }
test -s "$work_dir/manifest.txt" || { echo "Backup has no manifest." >&2; exit 1; }
if [ -d "$work_dir/storage-app" ] && [ -d "$work_dir/uploads" ]; then
    echo "Backup contains both current and legacy application-file layouts; refusing to choose one." >&2
    exit 1
elif [ -d "$work_dir/storage-app" ]; then
    test -d "$work_dir/storage-app/private" && test -d "$work_dir/storage-app/public" || {
        echo "Current-format backup must contain both private and public application-file directories." >&2
        exit 1
    }
    storage_source="$work_dir/storage-app"
    storage_layout=current
elif [ -d "$work_dir/uploads" ]; then
    storage_source="$work_dir/uploads"
    storage_layout=legacy-public
else
    echo "Backup has no application files." >&2
    exit 1
fi

# The extraction directory itself is private. Normalize only the staged copy so
# the unprivileged app container can read legacy archives with restrictive modes.
find "$storage_source" -type d -exec chmod 0755 {} +
find "$storage_source" -type f -exec chmod 0644 {} +

compose config --quiet

if [ "$assume_yes" -ne 1 ]; then
    if [ ! -t 0 ]; then
        echo "Interactive confirmation is required. Re-run with --yes only after verifying the target." >&2
        exit 1
    fi
    printf 'This replaces the production database plus storage/app/private and storage/app/public.\nType "RESTORE %s" to continue: ' "$archive_name"
    IFS= read -r confirmation
    test "$confirmation" = "RESTORE $archive_name" || { echo "Restore cancelled." >&2; exit 1; }
fi

echo "Creating a safety backup before restore."
BACKUP_DIR="$archive_dir" BACKUP_SKIP_RETENTION=1 "$project_dir/scripts/backup.sh"

cd "$project_dir"
if [ -n "$(compose ps --status running --quiet app)" ]; then
    app_was_running=1
fi
compose stop app
app_stopped=1
compose up -d mysql

attempt=0
until compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqladmin ping -h 127.0.0.1 -u"$MYSQL_USER" --silent' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        echo "MySQL did not become ready." >&2
        exit 1
    fi
    sleep 2
done

data_replaced=1
compose exec -T mysql sh -eu -c '
    case "$MYSQL_DATABASE" in *[!A-Za-z0-9_]*) echo "Unsafe database name." >&2; exit 1;; esac
    umask 077
    credentials=$(mktemp)
    trap '\''rm -f "$credentials"'\'' EXIT HUP INT TERM
    printf "[client]\nuser=root\npassword=%s\nhost=127.0.0.1\n" "$MYSQL_ROOT_PASSWORD" > "$credentials"
    mysql --defaults-extra-file="$credentials" -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
' 

compose exec -T mysql sh -eu -c '
    umask 077
    credentials=$(mktemp)
    trap '\''rm -f "$credentials"'\'' EXIT HUP INT TERM
    printf "[client]\nuser=root\npassword=%s\nhost=127.0.0.1\n" "$MYSQL_ROOT_PASSWORD" > "$credentials"
    mysql --defaults-extra-file="$credentials" "$MYSQL_DATABASE"
' < "$work_dir/database.sql"

if [ "$storage_layout" = current ]; then
    compose run --rm --no-deps --entrypoint sh \
        -v "$storage_source:/restore-storage:ro" app \
        -c 'set -eu
            test ! -L /var/www/html/storage/app/private && test ! -L /var/www/html/storage/app/public || { echo "Refusing symbolic-link application storage targets." >&2; exit 1; }
            mkdir -p /var/www/html/storage/app/private /var/www/html/storage/app/public
            find /var/www/html/storage/app/private /var/www/html/storage/app/public -mindepth 1 -delete
            cp -R /restore-storage/private/. /var/www/html/storage/app/private/
            cp -R /restore-storage/public/. /var/www/html/storage/app/public/'
else
    compose run --rm --no-deps --entrypoint sh \
        -v "$storage_source:/restore-storage:ro" app \
        -c 'set -eu
            test ! -L /var/www/html/storage/app/private && test ! -L /var/www/html/storage/app/public || { echo "Refusing symbolic-link application storage targets." >&2; exit 1; }
            mkdir -p /var/www/html/storage/app/private /var/www/html/storage/app/public
            find /var/www/html/storage/app/private /var/www/html/storage/app/public -mindepth 1 -delete
            cp -R /restore-storage/. /var/www/html/storage/app/public/'
fi

compose run --rm app php artisan migrate --force
compose run --rm app php artisan cache:clear
compose run --rm app php artisan schedule:clear-cache
compose run --rm app php artisan staff:secure-photos
compose up -d app

attempt=0
until compose exec -T app curl --fail --insecure --silent https://127.0.0.1:8443/up >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Restore completed, but the application did not become healthy. Inspect Docker logs." >&2
        exit 1
    fi
    sleep 2
done

app_stopped=0
echo "Restore completed and the application is healthy."
