#!/bin/sh
set -eu
umask 077

if [ "$#" -ne 5 ]; then
    echo "Usage: $0 attendance.internal 10.147.20.10 /secure/ca.crt /secure/ca.key /srv/hg-attendance/secrets/tls-next" >&2
    exit 1
fi

server_name=$1
server_ip=$2
ca_cert=$3
ca_key=$4
output_dir=$5

test "$(id -u)" -ne 0 || { echo "Run this command as the dedicated non-root deployment user, not with sudo." >&2; exit 1; }

case "$server_name" in
    ''|*[!A-Za-z0-9.-]*|.*|*.) echo "Server name is invalid." >&2; exit 1 ;;
esac

if ! printf '%s\n' "$server_ip" | awk -F. '
    NF != 4 { exit 1 }
    { for (i = 1; i <= 4; i++) if ($i !~ /^[0-9]+$/ || $i < 0 || $i > 255) exit 1 }
'; then
    echo "Server IP must be a valid IPv4 address." >&2
    exit 1
fi

command -v openssl >/dev/null 2>&1 || { echo "OpenSSL is required." >&2; exit 1; }
command -v realpath >/dev/null 2>&1 || { echo "realpath is required." >&2; exit 1; }

ca_cert=$(realpath -e -- "$ca_cert")
ca_key=$(realpath -e -- "$ca_key")
output_dir=$(realpath -m -- "$output_dir")

case "$output_dir" in
    /srv/*|/opt/*|/home/*/secrets/*) ;;
    *) echo "Output must be an explicit secrets/tls directory below /srv, /opt, or a user's secrets directory." >&2; exit 1 ;;
esac

test -r "$ca_cert" && test -r "$ca_key" || { echo "The CA certificate and private key must be readable." >&2; exit 1; }
case "$(stat -c '%a' "$ca_key")" in
    400|600) ;;
    *) echo "The offline CA private key must have mode 400 or 600." >&2; exit 1 ;;
esac

ca_cert_public=$(openssl x509 -in "$ca_cert" -pubkey -noout 2>/dev/null) || { echo "The CA certificate is invalid." >&2; exit 1; }
ca_key_public=$(openssl pkey -in "$ca_key" -pubout 2>/dev/null) || { echo "The CA private key is invalid." >&2; exit 1; }
test "$ca_cert_public" = "$ca_key_public" || { echo "The CA certificate and private key do not match." >&2; exit 1; }

mkdir -p "$output_dir"
chmod 700 "$output_dir"
for file in server.key server.crt server.csr; do
    test ! -e "$output_dir/$file" || { echo "Refusing to overwrite $output_dir/$file" >&2; exit 1; }
done

extensions=$(mktemp)
cleanup() {
    status=$?
    rm -f "$extensions" "$output_dir/server.csr"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

printf '%s\n' \
    'authorityKeyIdentifier=keyid,issuer' \
    'basicConstraints=critical,CA:FALSE' \
    'keyUsage=critical,digitalSignature,keyEncipherment' \
    'extendedKeyUsage=serverAuth' \
    "subjectAltName=DNS:$server_name,IP:$server_ip" > "$extensions"

openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out "$output_dir/server.key"
openssl req -new -sha256 \
    -key "$output_dir/server.key" \
    -subj "/CN=$server_name/O=Hogan Guards" \
    -out "$output_dir/server.csr"
serial=$(openssl rand -hex 16)
openssl x509 -req -sha256 -days 397 \
    -in "$output_dir/server.csr" \
    -CA "$ca_cert" \
    -CAkey "$ca_key" \
    -set_serial "0x$serial" \
    -extfile "$extensions" \
    -out "$output_dir/server.crt"

chmod 640 "$output_dir/server.key"
chmod 644 "$output_dir/server.crt"
openssl verify -purpose sslserver -CAfile "$ca_cert" "$output_dir/server.crt"
openssl x509 -checkhost "$server_name" -noout -in "$output_dir/server.crt"
openssl x509 -checkip "$server_ip" -noout -in "$output_dir/server.crt"

echo "Renewed server certificate created in $output_dir."
echo "Set TLS_KEY_GROUP_ID=$(id -g), update TLS_CERT_PATH/TLS_KEY_PATH, run preflight, and recreate the app container."
echo "Unmount or return the CA private key to encrypted offline storage immediately."
