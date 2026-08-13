#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
env_file="$project_dir/.env.docker"
failed=0

fail() {
    echo "ERROR: $1" >&2
    failed=1
}

has_value() {
    key=$1
    grep -Eq "^${key}=[^[:space:]].*" "$env_file"
}

validate_line() {
    key=$1
    pattern=$2
    message=$3

    if has_value "$key" && ! grep -Eq "$pattern" "$env_file"; then
        fail "$message"
    fi
}

value_for() {
    key=$1
    awk -v key="$key" 'index($0, key "=") == 1 { sub(/^[^=]*=/, ""); print; exit }' "$env_file"
}

if [ ! -f "$env_file" ]; then
    echo "ERROR: Create .env.docker from .env.docker.example first." >&2
    exit 1
fi

mode=$(stat -c '%a' "$env_file")
test "$mode" = '600' || fail ".env.docker permissions are $mode; run chmod 600 .env.docker."

if git -C "$project_dir" ls-files --error-unmatch .env.docker >/dev/null 2>&1; then
    fail '.env.docker is tracked by Git.'
fi

if [ -n "$(git -C "$project_dir" status --porcelain --untracked-files=normal)" ]; then
    fail 'The application worktree is not clean; deploy a reviewed commit, not local source changes.'
fi

for key in APP_ENV APP_KEY APP_DEBUG APP_URL APP_TIMEZONE APP_IMAGE_TAG APP_PORT ZEROTIER_BIND_ADDRESS LAN_BIND_ADDRESS TLS_CERT_PATH TLS_KEY_PATH TLS_CA_CERT_PATH TLS_KEY_GROUP_ID LOG_CHANNEL LOG_LEVEL DB_DATABASE DB_USERNAME DB_PASSWORD DB_ROOT_PASSWORD CACHE_STORE FILESYSTEM_DISK SESSION_DRIVER SESSION_LIFETIME SESSION_ENCRYPT SESSION_SECURE_COOKIE SANCTUM_EXPIRATION_MINUTES QUEUE_CONNECTION REPORT_SCHEDULE_TIME REPORT_MAX_DAYS REPORT_MAX_MATRIX_CELLS PAYSTACK_PUBLIC_KEY PAYSTACK_SECRET_KEY PAYSTACK_CURRENCY PAYSTACK_MONTHLY_AMOUNT PAYSTACK_YEARLY_AMOUNT SUBSCRIPTION_TRIAL_START SUBSCRIPTION_TRIAL_END SUBSCRIPTION_WARNING_DAYS SUBSCRIPTION_DAILY_SCAN_CAP BILLING_EMAIL; do
    has_value "$key" || fail "$key is blank or missing in .env.docker."
done

if grep -Eqi '(^|=).*(change_me|generate_|replace_|your-domain|example\.com)' "$env_file"; then
    fail '.env.docker still contains a documented placeholder.'
fi

if grep -Eq '^APP_ENV_FILE=' "$env_file"; then
    fail 'Remove APP_ENV_FILE from .env.docker; the supported production container environment file is .env.docker itself.'
fi

validate_line APP_KEY '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' 'APP_KEY is not a generated 32-byte Laravel base64 key.'
validate_line APP_ENV '^APP_ENV=production$' 'APP_ENV must be production.'
validate_line APP_DEBUG '^APP_DEBUG=false$' 'APP_DEBUG must be false.'
validate_line APP_URL '^APP_URL=https://[A-Za-z0-9.-]+:[0-9]+$' 'APP_URL must be a root HTTPS origin with an explicit port and no path.'
validate_line APP_IMAGE_TAG '^APP_IMAGE_TAG=[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$' 'APP_IMAGE_TAG is not a valid Docker image tag.'
grep -Eqi '^APP_URL=https://(localhost|127\.0\.0\.1)(:|/|$)' "$env_file" && fail 'APP_URL still points to the local machine.'
validate_line APP_PORT '^APP_PORT=([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$' 'APP_PORT must be between 1 and 65535.'
validate_line TLS_KEY_GROUP_ID '^TLS_KEY_GROUP_ID=[1-9][0-9]*$' 'TLS_KEY_GROUP_ID must be a non-root numeric group.'
validate_line LOG_CHANNEL '^LOG_CHANNEL=stderr$' 'LOG_CHANNEL must be stderr in the container.'
validate_line LOG_LEVEL '^LOG_LEVEL=(info|notice|warning|error|critical|alert|emergency)$' 'LOG_LEVEL must not expose debug logging in production.'
validate_line DB_DATABASE '^DB_DATABASE=[A-Za-z0-9_]+$' 'DB_DATABASE may contain only letters, numbers, and underscores.'
validate_line DB_USERNAME '^DB_USERNAME=[A-Za-z0-9_]+$' 'DB_USERNAME may contain only letters, numbers, and underscores.'
validate_line CACHE_STORE '^CACHE_STORE=database$' 'CACHE_STORE must be database so concurrent throttles and scheduler locks are atomic.'
validate_line FILESYSTEM_DISK '^FILESYSTEM_DISK=local$' 'FILESYSTEM_DISK must be local so staff photos remain private.'
validate_line SESSION_DRIVER '^SESSION_DRIVER=file$' 'SESSION_DRIVER must be file for the supported single-server deployment.'
validate_line SESSION_LIFETIME '^SESSION_LIFETIME=[1-9][0-9]*$' 'SESSION_LIFETIME must be a positive integer.'
validate_line SESSION_ENCRYPT '^SESSION_ENCRYPT=true$' 'SESSION_ENCRYPT must be true in production.'
validate_line SESSION_SECURE_COOKIE '^SESSION_SECURE_COOKIE=true$' 'SESSION_SECURE_COOKIE must be true in production.'
validate_line SANCTUM_EXPIRATION_MINUTES '^SANCTUM_EXPIRATION_MINUTES=[1-9][0-9]*$' 'SANCTUM_EXPIRATION_MINUTES must be a positive integer.'
validate_line QUEUE_CONNECTION '^QUEUE_CONNECTION=sync$' 'QUEUE_CONNECTION must be sync unless a separately managed queue worker is added.'
validate_line REPORT_SCHEDULE_TIME '^REPORT_SCHEDULE_TIME=([01][0-9]|2[0-3]):[0-5][0-9]$' 'REPORT_SCHEDULE_TIME must use 24-hour HH:MM.'
validate_line REPORT_MAX_DAYS '^REPORT_MAX_DAYS=[1-9][0-9]*$' 'REPORT_MAX_DAYS must be a positive integer.'
validate_line REPORT_MAX_MATRIX_CELLS '^REPORT_MAX_MATRIX_CELLS=[1-9][0-9]*$' 'REPORT_MAX_MATRIX_CELLS must be a positive integer.'
validate_line PAYSTACK_PUBLIC_KEY '^PAYSTACK_PUBLIC_KEY=pk_(test|live)_[A-Za-z0-9]+$' 'PAYSTACK_PUBLIC_KEY does not have a valid Paystack key shape.'
validate_line PAYSTACK_SECRET_KEY '^PAYSTACK_SECRET_KEY=sk_(test|live)_[A-Za-z0-9]+$' 'PAYSTACK_SECRET_KEY does not have a valid Paystack key shape.'
validate_line PAYSTACK_CURRENCY '^PAYSTACK_CURRENCY=(USD|NGN)$' 'PAYSTACK_CURRENCY must be USD or a deliberate NGN configuration.'
validate_line PAYSTACK_MONTHLY_AMOUNT '^PAYSTACK_MONTHLY_AMOUNT=[1-9][0-9]*$' 'PAYSTACK_MONTHLY_AMOUNT must be a positive integer in the smallest currency unit.'
validate_line PAYSTACK_YEARLY_AMOUNT '^PAYSTACK_YEARLY_AMOUNT=[1-9][0-9]*$' 'PAYSTACK_YEARLY_AMOUNT must be a positive integer in the smallest currency unit.'
validate_line SUBSCRIPTION_TRIAL_START '^SUBSCRIPTION_TRIAL_START=[0-9]{4}-[0-9]{2}-[0-9]{2}$' 'SUBSCRIPTION_TRIAL_START must use YYYY-MM-DD.'
validate_line SUBSCRIPTION_TRIAL_END '^SUBSCRIPTION_TRIAL_END=[0-9]{4}-[0-9]{2}-[0-9]{2}$' 'SUBSCRIPTION_TRIAL_END must use YYYY-MM-DD.'
validate_line SUBSCRIPTION_WARNING_DAYS '^SUBSCRIPTION_WARNING_DAYS=[1-9][0-9]*$' 'SUBSCRIPTION_WARNING_DAYS must be a positive integer.'
validate_line SUBSCRIPTION_DAILY_SCAN_CAP '^SUBSCRIPTION_DAILY_SCAN_CAP=[1-9][0-9]*$' 'SUBSCRIPTION_DAILY_SCAN_CAP must be a positive integer.'
validate_line BILLING_EMAIL '^BILLING_EMAIL=[^[:space:]@]+@[^[:space:]@]+$' 'BILLING_EMAIL must be configured.'

cors_origins=$(value_for CORS_ALLOWED_ORIGINS)
if [ -n "$cors_origins" ] && ! printf '%s\n' "$cors_origins" | grep -Eq '^https://[A-Za-z0-9.-]+(:[0-9]+)?(,https://[A-Za-z0-9.-]+(:[0-9]+)?)*$'; then
    fail 'CORS_ALLOWED_ORIGINS must be blank or a comma-separated list of exact HTTPS origins without paths or wildcards.'
fi

app_url=$(value_for APP_URL)
app_port=$(value_for APP_PORT)
app_authority=${app_url#https://}
app_host=${app_authority%:*}
app_url_port=${app_authority##*:}
if [ -n "$app_url" ] && [ -n "$app_port" ]; then
    test "$app_url_port" = "$app_port" || fail 'The APP_URL port must match APP_PORT.'
fi

app_timezone=$(value_for APP_TIMEZONE)
case "$app_timezone" in
    ''|/*|*..*) fail 'APP_TIMEZONE is invalid.' ;;
    *) test -f "/usr/share/zoneinfo/$app_timezone" || fail 'APP_TIMEZONE is not installed on this server.' ;;
esac

db_password=$(value_for DB_PASSWORD)
db_root_password=$(value_for DB_ROOT_PASSWORD)
test "${#db_password}" -ge 16 || fail 'DB_PASSWORD must contain at least 16 characters.'
test "${#db_root_password}" -ge 16 || fail 'DB_ROOT_PASSWORD must contain at least 16 characters.'
test "$db_password" != "$db_root_password" || fail 'DB_PASSWORD and DB_ROOT_PASSWORD must be different.'

session_lifetime=$(value_for SESSION_LIFETIME)
sanctum_expiration=$(value_for SANCTUM_EXPIRATION_MINUTES)
report_max_days=$(value_for REPORT_MAX_DAYS)
report_max_cells=$(value_for REPORT_MAX_MATRIX_CELLS)
subscription_warning_days=$(value_for SUBSCRIPTION_WARNING_DAYS)
subscription_scan_cap=$(value_for SUBSCRIPTION_DAILY_SCAN_CAP)
if [ -n "$session_lifetime" ]; then
    test "$session_lifetime" -le 1440 2>/dev/null || fail 'SESSION_LIFETIME must not exceed 1440 minutes.'
fi
if [ -n "$sanctum_expiration" ]; then
    test "$sanctum_expiration" -ge 15 2>/dev/null && test "$sanctum_expiration" -le 1440 2>/dev/null || fail 'SANCTUM_EXPIRATION_MINUTES must be between 15 and 1440.'
fi
if [ -n "$report_max_days" ]; then
    test "$report_max_days" -le 3660 2>/dev/null || fail 'REPORT_MAX_DAYS must not exceed 3660.'
fi
if [ -n "$report_max_cells" ]; then
    test "$report_max_cells" -ge 1000 2>/dev/null && test "$report_max_cells" -le 1000000 2>/dev/null || fail 'REPORT_MAX_MATRIX_CELLS must be between 1000 and 1000000.'
fi
if [ -n "$subscription_warning_days" ]; then
    test "$subscription_warning_days" -le 365 2>/dev/null || fail 'SUBSCRIPTION_WARNING_DAYS must not exceed 365.'
fi
if [ -n "$subscription_scan_cap" ]; then
    test "$subscription_scan_cap" -le 10000 2>/dev/null || fail 'SUBSCRIPTION_DAILY_SCAN_CAP must not exceed 10000.'
fi

paystack_public=$(value_for PAYSTACK_PUBLIC_KEY)
paystack_secret=$(value_for PAYSTACK_SECRET_KEY)
public_mode=$(printf '%s\n' "$paystack_public" | cut -d_ -f2)
secret_mode=$(printf '%s\n' "$paystack_secret" | cut -d_ -f2)
test "$public_mode" = "$secret_mode" || fail 'Paystack public and secret keys must both be test keys or both be live keys.'

paystack_currency=$(value_for PAYSTACK_CURRENCY)
monthly_amount=$(value_for PAYSTACK_MONTHLY_AMOUNT)
yearly_amount=$(value_for PAYSTACK_YEARLY_AMOUNT)
if [ "$paystack_currency" = NGN ]; then
    test "$monthly_amount" != 2500 && test "$yearly_amount" != 28000 || fail 'For NGN, deliberately replace both USD default amounts with reviewed kobo amounts.'
fi
if [ -n "$monthly_amount" ] && [ -n "$yearly_amount" ]; then
    test "$yearly_amount" -gt "$monthly_amount" 2>/dev/null || fail 'PAYSTACK_YEARLY_AMOUNT must be greater than PAYSTACK_MONTHLY_AMOUNT.'
fi

trial_start=$(value_for SUBSCRIPTION_TRIAL_START)
trial_end=$(value_for SUBSCRIPTION_TRIAL_END)
if parsed_trial_start=$(date -u -d "$trial_start" +%F 2>/dev/null); then
    test "$parsed_trial_start" = "$trial_start" || fail 'SUBSCRIPTION_TRIAL_START is not a real calendar date.'
else
    fail 'SUBSCRIPTION_TRIAL_START is not a real calendar date.'
fi
if parsed_trial_end=$(date -u -d "$trial_end" +%F 2>/dev/null); then
    test "$parsed_trial_end" = "$trial_end" || fail 'SUBSCRIPTION_TRIAL_END is not a real calendar date.'
else
    fail 'SUBSCRIPTION_TRIAL_END is not a real calendar date.'
fi
if [ -n "${parsed_trial_start:-}" ] && [ -n "${parsed_trial_end:-}" ]; then
    trial_start_epoch=$(date -u -d "$trial_start" +%s)
    trial_end_epoch=$(date -u -d "$trial_end" +%s)
    test "$trial_start_epoch" -lt "$trial_end_epoch" || fail 'SUBSCRIPTION_TRIAL_END must be after SUBSCRIPTION_TRIAL_START.'
fi

for key in APP_KEY APP_URL APP_IMAGE_TAG APP_PORT ZEROTIER_BIND_ADDRESS LAN_BIND_ADDRESS TLS_CERT_PATH TLS_KEY_PATH TLS_KEY_GROUP_ID DB_DATABASE DB_USERNAME DB_PASSWORD DB_ROOT_PASSWORD PAYSTACK_PUBLIC_KEY PAYSTACK_SECRET_KEY PAYSTACK_CURRENCY PAYSTACK_MONTHLY_AMOUNT PAYSTACK_YEARLY_AMOUNT SUBSCRIPTION_TRIAL_START SUBSCRIPTION_TRIAL_END BILLING_EMAIL; do
    if printenv "$key" >/dev/null 2>&1 && [ "$(printenv "$key")" != "$(value_for "$key")" ]; then
        fail "$key is exported in the shell with a conflicting value; unset it before deployment."
    fi
done

if printenv APP_ENV_FILE >/dev/null 2>&1; then
    fail 'APP_ENV_FILE is exported in the shell; unset it so Compose cannot substitute a different container environment file.'
fi

zerotier_address=$(value_for ZEROTIER_BIND_ADDRESS)
lan_address=$(value_for LAN_BIND_ADDRESS)
if [ -n "$zerotier_address" ] && [ -n "$lan_address" ]; then
    test "$zerotier_address" != "$lan_address" || fail 'ZEROTIER_BIND_ADDRESS and LAN_BIND_ADDRESS must be different interface addresses.'
    ip -4 address show | grep -Fq " $zerotier_address/" || fail 'ZEROTIER_BIND_ADDRESS is not assigned to this server.'
    ip -4 address show | grep -Fq " $lan_address/" || fail 'LAN_BIND_ADDRESS is not assigned to this server.'

    if command -v getent >/dev/null 2>&1 && [ -n "$app_host" ]; then
        if ! getent ahostsv4 "$app_host" 2>/dev/null | awk '{ print $1 }' | grep -Fqx -e "$zerotier_address" -e "$lan_address"; then
            fail 'APP_URL hostname does not resolve to either configured server address.'
        fi
    fi
fi

tls_cert=$(value_for TLS_CERT_PATH)
tls_key=$(value_for TLS_KEY_PATH)
tls_ca=$(value_for TLS_CA_CERT_PATH)
tls_group=$(value_for TLS_KEY_GROUP_ID)

for tls_path in "$tls_cert" "$tls_key" "$tls_ca"; do
    if [ -n "$tls_path" ]; then
        case "$tls_path" in
            /*) ;;
            *) fail 'TLS certificate and key paths must be absolute.' ;;
        esac
        test -r "$tls_path" || fail "TLS file is missing or unreadable: $tls_path"
    fi
done

if [ -n "$tls_key" ] && [ -r "$tls_key" ]; then
    key_mode=$(stat -c '%a' "$tls_key")
    case "$key_mode" in 440|640) ;; *) fail 'TLS_KEY_PATH must have mode 440 or 640.' ;; esac
    test "$(stat -c '%g' "$tls_key")" = "$tls_group" || fail 'TLS_KEY_GROUP_ID does not match the TLS private key group.'
fi

if [ -n "$tls_ca" ] && [ -e "$(dirname -- "$tls_ca")/ca.key" ]; then
    fail 'The internal CA private key is still on the application server; move it to encrypted offline storage.'
fi

if [ -n "$tls_cert" ] && [ -r "$tls_cert" ] && [ -n "$tls_key" ] && [ -r "$tls_key" ] && [ -n "$tls_ca" ] && [ -r "$tls_ca" ]; then
    command -v openssl >/dev/null 2>&1 || fail 'OpenSSL is required for TLS preflight checks.'
    if command -v openssl >/dev/null 2>&1; then
        openssl x509 -checkend 2592000 -noout -in "$tls_cert" >/dev/null 2>&1 || fail 'TLS certificate is invalid or expires within 30 days.'
        openssl verify -purpose sslserver -CAfile "$tls_ca" "$tls_cert" >/dev/null 2>&1 || fail 'TLS certificate is not a valid server certificate signed by TLS_CA_CERT_PATH.'

        if cert_public_key=$(openssl x509 -in "$tls_cert" -pubkey -noout 2>/dev/null); then
            :
        else
            cert_public_key=
            fail 'TLS certificate public key could not be read.'
        fi
        if private_public_key=$(openssl pkey -in "$tls_key" -pubout 2>/dev/null); then
            :
        else
            private_public_key=
            fail 'TLS private key could not be read.'
        fi
        test -n "$cert_public_key" && test "$cert_public_key" = "$private_public_key" || fail 'TLS certificate and private key do not match.'

        case "$app_host" in
            *[!0-9.]*) openssl x509 -checkhost "$app_host" -noout -in "$tls_cert" >/dev/null 2>&1 || fail 'TLS certificate does not cover the APP_URL hostname.' ;;
            *) openssl x509 -checkip "$app_host" -noout -in "$tls_cert" >/dev/null 2>&1 || fail 'TLS certificate does not cover the APP_URL address.' ;;
        esac
    fi
fi

if ! command -v docker >/dev/null 2>&1; then
    fail 'Docker is not installed.'
elif ! docker info >/dev/null 2>&1; then
    fail 'The current user cannot reach the Docker daemon.'
elif ! env -u APP_ENV_FILE docker compose --project-directory "$project_dir" --project-name hg-attendance --env-file "$env_file" -f "$project_dir/docker-compose.yml" config --quiet; then
    fail 'Docker Compose configuration is invalid.'
fi

if [ "$failed" -ne 0 ]; then
    exit 1
fi

echo 'Production preflight passed.'
