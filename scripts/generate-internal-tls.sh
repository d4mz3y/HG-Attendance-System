#!/bin/sh
set -eu

if [ "$#" -ne 3 ]; then
    echo "Usage: $0 attendance.internal 10.147.20.10 /srv/hg-attendance/secrets/tls" >&2
    exit 1
fi

server_name=$1
server_ip=$2
output_dir=$3

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

command -v realpath >/dev/null 2>&1 || { echo "realpath is required." >&2; exit 1; }
output_dir=$(realpath -m -- "$output_dir")

case "$output_dir" in
    /srv/*|/opt/*|/home/*/secrets/*) ;;
    *) echo "Output must be an explicit secrets/tls directory below /srv, /opt, or a user's secrets directory." >&2; exit 1 ;;
esac

command -v openssl >/dev/null 2>&1 || { echo "OpenSSL is required." >&2; exit 1; }

mkdir -p "$output_dir"
chmod 700 "$output_dir"

for file in ca.key ca.crt ca.srl server.key server.crt server.csr; do
    test ! -e "$output_dir/$file" || { echo "Refusing to overwrite $output_dir/$file" >&2; exit 1; }
done

umask 077
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

openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out "$output_dir/ca.key"
openssl req -x509 -new -sha256 -days 3650 \
    -key "$output_dir/ca.key" \
    -subj '/CN=Hogan Guards Attendance Internal CA/O=Hogan Guards' \
    -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign' \
    -out "$output_dir/ca.crt"

openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out "$output_dir/server.key"
openssl req -new -sha256 \
    -key "$output_dir/server.key" \
    -subj "/CN=$server_name/O=Hogan Guards" \
    -out "$output_dir/server.csr"

printf '%s\n' \
    'authorityKeyIdentifier=keyid,issuer' \
    'basicConstraints=critical,CA:FALSE' \
    'keyUsage=critical,digitalSignature,keyEncipherment' \
    'extendedKeyUsage=serverAuth' \
    "subjectAltName=DNS:$server_name,IP:$server_ip" > "$extensions"

openssl x509 -req -sha256 -days 825 \
    -in "$output_dir/server.csr" \
    -CA "$output_dir/ca.crt" \
    -CAkey "$output_dir/ca.key" \
    -CAcreateserial \
    -extfile "$extensions" \
    -out "$output_dir/server.crt"

chmod 600 "$output_dir/ca.key"
chmod 640 "$output_dir/server.key"
chmod 644 "$output_dir/ca.crt" "$output_dir/server.crt"

openssl verify -CAfile "$output_dir/ca.crt" "$output_dir/server.crt"
openssl x509 -checkhost "$server_name" -noout -in "$output_dir/server.crt"
openssl x509 -checkip "$server_ip" -noout -in "$output_dir/server.crt"

echo "TLS material created in $output_dir."
echo "Set TLS_KEY_GROUP_ID=$(id -g) and distribute ca.crt to every authorized client."
echo "Move ca.key and ca.srl to encrypted offline storage now; they are not needed by the running server."
