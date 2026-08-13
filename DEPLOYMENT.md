# Production deployment: office server, LAN, and ZeroTier

This runbook deploys the system to one Ubuntu office server. HR, the HR assistant, IT, and kiosk devices connect over an approved LAN or a private ZeroTier network. MySQL has no host port and no router port-forward is required.

## 1. Prepare the server

Use Ubuntu Server 24.04 LTS with automatic security updates, a UPS where possible, a static DHCP reservation, full-disk encryption where the company's restart procedure can support it, and a dedicated non-root deployment user. Keep the server in a physically controlled location with a locked console. Install Docker Engine and the Docker Compose plugin from Docker's supported repository, then allow the deployment user to operate Docker.

Attendance and offline replay depend on accurate clocks. Set the server to the approved timezone and keep network time synchronization enabled; also keep every kiosk clock synchronized:

```bash
sudo timedatectl set-timezone Africa/Lagos
sudo timedatectl set-ntp true
timedatectl status
```

Do not expose TCP 22, 8443, or 3306 through the internet router. Anyone with Docker access effectively has root-equivalent control of the server, so restrict membership in the `docker` group.

Clone the private repository:

```bash
sudo mkdir -p /srv/hg-attendance
sudo chown "$USER":"$USER" /srv/hg-attendance
git clone YOUR_PRIVATE_REPOSITORY_URL /srv/hg-attendance
cd /srv/hg-attendance
cp .env.docker.example .env.docker
chmod 600 .env.docker
```

`.env.docker` was tracked in an early project revision. The current repository ignores it, but deleting it from the latest commit does not erase old Git history. Rotate every production credential ever placed in an earlier committed copy. Do not rewrite shared Git history casually; coordinate that separately if the repository has been distributed.

## 2. Create production secrets

Generate independent values. Do not reuse a password for two variables:

```bash
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
openssl rand -hex 32
openssl rand -hex 32
```

Use the first result as `APP_KEY`, then use separate hexadecimal values as `DB_PASSWORD` and `DB_ROOT_PASSWORD`. Set:

- `APP_URL` to the trusted internal HTTPS name users actually open, including port 8443.
- `APP_IMAGE_TAG` to a unique reviewed release label. Do not reuse a tag after changing application code.
- `ZEROTIER_BIND_ADDRESS` and `LAN_BIND_ADDRESS` to the server's exact interface addresses. Do not use `0.0.0.0`.
- `CORS_ALLOWED_ORIGINS` blank for same-origin operation. If a separately hosted approved browser client is genuinely required, list its exact origins separated by commas; never use `*`.
- `APP_TIMEZONE=Africa/Lagos` unless the company policy specifies another zone.
- `APP_DEBUG=false` and `LOG_CHANNEL=stderr`.
- `CACHE_STORE=database`; production throttles and scheduler locks require atomic shared increments/locks.
- `FILESYSTEM_DISK=local`; staff photos must never use the web-accessible public disk.
- `SESSION_DRIVER=file`, `SESSION_ENCRYPT=true`, and `SESSION_SECURE_COOKIE=true` for the supported single-server topology.
- `QUEUE_CONNECTION=sync`; this stack does not run a separate queue worker.

Changing `APP_KEY` later invalidates sessions and can make encrypted application values unreadable. Store a recovery copy of `.env.docker` in a separate encrypted password vault; it is intentionally excluded from attendance backups.

Complete the TLS, SMTP, and Paystack values in the next sections before running the production preflight.

## 3. Create and trust the internal TLS certificate

Trusted HTTPS is mandatory, not optional. The offline kiosk uses a service worker, and browsers do not treat ordinary HTTP on a LAN or ZeroTier address as a secure context. ZeroTier encrypts its tunnel, but an `http://` origin still cannot reliably install or reopen the offline application.

Create the private ZeroTier network before issuing the certificate. Use a company-controlled ZeroTier account with multi-factor authentication and protected recovery codes; do not leave the network under one employee's unrecoverable personal account. Install ZeroTier on the server and each approved remote workstation from ZeroTier's current official package, join the server to the new network, authorize it in ZeroTier Central, and assign it a stable managed address. Join and authorize only the three approved workstations and any kiosk that genuinely requires the overlay network. Remove retired/lost members immediately. Record the server address and confirm it appears on the server:

```bash
sudo zerotier-cli join YOUR_NETWORK_ID
sudo zerotier-cli listnetworks
ip -4 address
```

Do not continue until the stable ZeroTier and LAN addresses entered in `.env.docker` are actually assigned. Docker cannot publish a port on an interface address that does not yet exist.

Choose an internal name such as `attendance.hoganguards.internal`. Configure the office DNS server, or the hosts file on every authorized workstation and kiosk, so that name resolves to the server's stable ZeroTier address. LAN-only clients may resolve the same name to the LAN address; hostname validation remains correct.

Generate a private internal CA and server certificate:

```bash
cd /srv/hg-attendance
./scripts/generate-internal-tls.sh \
  attendance.hoganguards.internal \
  10.147.20.10 \
  /srv/hg-attendance/secrets/tls
```

The IP argument is an additional certificate SAN and should be the stable ZeroTier address. The DNS SAN is what permits the same name to resolve to either the ZeroTier or LAN address.

Set the generated paths in `.env.docker`. Set `TLS_KEY_GROUP_ID` to the numeric group printed by the script (normally the deployment user's primary group). The container runs as an unprivileged user but receives that one supplemental read group. The private key must remain mode `640`; the preflight rejects a broadly readable key.

Immediately move `ca.key` and `ca.srl` to encrypted offline media. The running server needs only `ca.crt`, `server.crt`, and `server.key`. The CA private key is required for renewal and must not remain on the server or be copied into a backup archive.

Securely transfer `ca.crt` to each authorized client and compare its SHA-256 fingerprint before trusting it:

```bash
sha256sum /srv/hg-attendance/secrets/tls/ca.crt
```

On Windows, run an elevated Command Prompt on each approved client:

```bat
certutil -addstore -f Root C:\SecureTransfer\ca.crt
```

On Ubuntu clients:

```bash
sudo cp ca.crt /usr/local/share/ca-certificates/hg-attendance-ca.crt
sudo update-ca-certificates
```

Restart the browser after installing the CA. If a browser maintains its own trust store, import the CA there too. Never instruct users to click through a certificate warning. Verify that the browser reports a secure connection, `window.isSecureContext` is `true`, and the service worker is registered before relying on offline capture.

## 4. Configure SMTP

Scheduled reports need a real SMTP account. Obtain a dedicated mailbox or SMTP credential approved by the company and set:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=attendance@example.com
MAIL_PASSWORD=REPLACE_WITH_SMTP_PASSWORD
MAIL_FROM_ADDRESS=attendance@hoganguards.com
MAIL_FROM_NAME="Hogan Guards Attendance"
REPORT_SCHEDULE_TIME=06:00
```

Use `MAIL_SCHEME=smtps` with the provider's implicit-TLS port when its documentation requires it. Do not enable scheduled reports until a manual `reports:send --force` succeeds. The scheduler reads the enable switch and daily/weekly/monthly frequency from Settings. Daily reports cover the prior day; weekly and monthly runs cover the most recently completed calendar period. A stored delivery marker prevents duplicates and lets the next daily scheduler run recover a missed weekly or monthly send after downtime.

## 5. Configure Paystack

Copy the public and secret keys from the Paystack dashboard; never paste them into Git, chat, screenshots, or frontend source. Amounts are stored in the currency's smallest unit (USD cents by default; NGN uses kobo):

```dotenv
PAYSTACK_PUBLIC_KEY=pk_live_REPLACE
PAYSTACK_SECRET_KEY=sk_live_REPLACE
PAYSTACK_CURRENCY=USD
PAYSTACK_MONTHLY_AMOUNT=2500
PAYSTACK_YEARLY_AMOUNT=28000
SUBSCRIPTION_TRIAL_START=2026-09-01
SUBSCRIPTION_TRIAL_END=2027-09-01
BILLING_EMAIL=billing@hoganguards.com
```

The payment endpoints are:

- Authenticated initialization: `POST {APP_URL}/api/subscription/initialize`
- Browser callback: `GET {APP_URL}/api/subscription/callback`
- Signature-verified webhook handler: `POST {APP_URL}/api/subscription/webhook`

Those intentional defaults charge USD 25 monthly or USD 280 yearly. Confirm that the Paystack account is enabled to receive USD before enabling checkout. If billing must use NGN, deliberately change the currency and both amounts to the corresponding kobo values; never change only the currency label.

The authenticated initialization supplies the callback URL. After Paystack redirects the user's browser back through ZeroTier, the callback verifies the transaction directly with Paystack. The scheduler also runs `subscriptions:reconcile` every five minutes to verify pending references through outbound HTTPS, so a private-only deployment does not depend on an inbound webhook.

Paystack cannot call a private LAN or ZeroTier address. Leave the dashboard webhook blank unless the company has a stable, publicly reachable HTTPS endpoint that securely forwards only that path. If such an endpoint is available, register the exact URL in Paystack Dashboard → Settings → API Keys & Webhooks; the handler verifies Paystack's signature. That ingress must preserve the raw request body and `X-Paystack-Signature`, and rewrite the upstream `Host` to the hostname in `APP_URL` so Laravel's trusted-host check accepts it. Never expose port 8443 directly, and do not use a temporary tunnel URL as production ingress. Callback verification plus scheduled reconciliation are the supported private-only path.

Start with Paystack test keys and complete initialization, successful callback verification, reconciliation of an interrupted callback, a cancelled payment, and a failed payment before installing live keys. If public HTTPS ingress is intentionally configured, also test a valid signed webhook and a duplicate webhook.

Now validate the complete deployment configuration. Commit and review the release first: the preflight intentionally rejects a dirty source tree. It does not print secret values; it also rejects placeholders, conflicting exported variables, unsafe database settings, mismatched Paystack modes/amounts, invalid trial dates, an untrusted or expiring TLS setup, unresolved internal DNS, unassigned bind addresses, and an unreachable Docker daemon:

```bash
./scripts/preflight.sh
```

## 6. Build, migrate, and start

Build the immutable image, start MySQL, apply forward-only migrations explicitly, and then start the application:

```bash
docker compose --env-file .env.docker build --pull
docker compose --env-file .env.docker up -d --wait --wait-timeout 120 mysql
docker compose --env-file .env.docker run --rm app php artisan migrate --force
docker compose --env-file .env.docker run --rm app php artisan cache:clear
docker compose --env-file .env.docker run --rm app php artisan staff:secure-photos
docker compose --env-file .env.docker up -d --wait --wait-timeout 120 app
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=100 app
```

Migrations and the one-way legacy-photo hardening command do not run implicitly on container restart. `staff:secure-photos` moves any older publicly stored staff photos into protected local storage and is safe to rerun. Never run `db:seed` to create production users.

The app container runs PHP-FPM, Nginx, and Laravel's scheduler as `www-data`, uses a read-only root filesystem, drops Linux capabilities, and limits Docker JSON logs. Only storage, Laravel cache, and `/tmp` are writable.

## 7. Create the four accounts

Passwords require at least 12 characters with upper- and lowercase letters, a number, and a symbol. Omit `--password` so it never enters shell history:

```bash
docker compose --env-file .env.docker exec -it app php artisan users:create-super-admin system.owner
docker compose --env-file .env.docker exec -it app php artisan users:upsert hr.manager hr
docker compose --env-file .env.docker exec -it app php artisan users:upsert hr.assistant hr_assistant
docker compose --env-file .env.docker exec -it app php artisan users:upsert it.manager it_manager
```

The bootstrap command creates the first and only initial super administrator, and refuses if one already exists. On an older deployment with a legacy `admin` account but no super administrator, give that legacy username to the same command to promote and recover it. Give each password to only its named owner through an approved private channel. HR and IT accounts work with the password assigned by their authorized operator; the super administrator and IT manager can later set another user's password through **Portal users**. Use one named account per person and never share an IT or HR credential.

If the super administrator loses their password, an authorized person with direct local terminal access to the office server can run `docker compose --env-file .env.docker exec -it app php artisan users:recover-super-admin`. The command uses the single existing super-admin account automatically; if there is more than one, append its username. It requires an interactive confirmation and hidden password entry, revokes that account's sessions, and records the recovery. It does not run automatically and does not reset the database.

### Configure the reception scanner

Log in as the IT manager or super administrator and open **Scan devices**. The page manages exactly one reception scanner. Enter the reception computer's one fixed IP address and save it. A CIDR/range is intentionally rejected: another computer on the LAN must never be able to claim the scanner during pairing. Then open the trusted HTTPS `/scan` address on that computer. It securely pairs itself and saves an opaque browser credential; staff never see, enter, copy, or manage a token/passcode.

The page retains the operational controls: status, approved address, server event history, blocked-queue recovery, enable/disable, and an explicit **Re-pair replacement browser** action. Re-pair only after checking the old browser's Device queue. It cannot discard unresolved attendance events, and a disabled scanner must be explicitly enabled before it can pair again.

Run the attendance browser in the operating system's locked-down kiosk/full-screen mode under a non-administrator account. Restrict access to developer tools, browser profile management, local storage controls, and the server console; anyone with operating-system or browser-profile control can erase IndexedDB regardless of application safeguards. The in-app reset is disabled while pending or blocked attendance events exist, but physical kiosk hardening remains an IT responsibility.

If a permanently rejected offline event blocks later queued scans, do not clear browser storage or re-enrol the device. On the kiosk, open **Device queue** and submit a recovery request; the browser sends the exact original device-signed blocked events to `POST /api/scan/recover`. The IT manager then opens that device under **Scan devices**, reviews its recovery requests (`GET /api/devices/{device}/recoveries`), investigates the listed event failures, and approves the specific request only when dropping those rejected events is justified (`POST /api/devices/{device}/recoveries/{recovery}/approve`). If another event becomes blocked while IT reviews the first request, the earlier reviewed subset can still be consumed safely; it does not need a new approval. When the kiosk checks again, it removes only the approved blocked IDs, then atomically re-signs and resequences the remaining pending events. The request and approval remain in the audit trail. Never approve a recovery merely to make a queue warning disappear; correct any legitimate missed attendance through the normal audited manual-correction workflow.

The global scanner CIDRs in Settings apply in addition to the reception scanner's exact single-IP binding. Nginx replaces client-supplied forwarding headers before PHP receives the request, so these checks use the direct connection address and cannot be bypassed with a forged `X-Forwarded-For` header.

## 8. Configure the firewall

Find the ZeroTier interface and the actual office subnet:

```bash
ip link
ip route
```

Configure UFW using the real values in place of the examples:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow in on ztXXXXXXXX to any port 8443 proto tcp
sudo ufw allow in on ztXXXXXXXX to any port 22 proto tcp
sudo ufw allow from 192.168.10.0/24 to any port 8443 proto tcp
sudo ufw allow from 192.168.10.50 to any port 22 proto tcp
sudo ufw enable
sudo ufw status verbose
```

Replace `192.168.10.50` with the IT manager's fixed administration workstation address before enabling UFW; do not open SSH to the whole office subnet. Keep physical console access available in case the overlay network fails.

Docker-published ports can bypass ordinary UFW forwarding rules on some installations. The exact LAN/ZeroTier binds and absence of a router port-forward are therefore mandatory boundaries, not optional conveniences. If company policy must restrict HTTPS to a smaller set of LAN sources, have IT add persistent `DOCKER-USER`/nftables or upstream-firewall rules appropriate to that server. Verify from an unauthorized LAN device, and repeat the test after both Docker and the server have restarted; `ufw status` alone is not proof that a published container port is filtered.

The Compose file publishes HTTPS only on the two explicit interface addresses, never every interface. Do not add a plaintext HTTP listener: it would expose bearer tokens and disable reliable service-worker/offline behavior.

## 9. Verify the installation

```bash
curl --fail --cacert /srv/hg-attendance/secrets/tls/ca.crt \
  https://attendance.hoganguards.internal:8443/up
docker compose --env-file .env.docker exec app php artisan about
docker compose --env-file .env.docker exec app php artisan schedule:list
docker compose --env-file .env.docker exec app php artisan reports:send --frequency=daily --to=2026-08-11
```

Also verify:

1. Each role can log in and sees only its permitted actions.
2. One complete day and one overnight clock-in/clock-out flow.
3. Kiosk/device authentication and allowed-network enforcement.
4. Leave approval and a manual correction with an audit record.
5. Excel and PDF reports.
6. Photo upload and download.
7. Paystack test-mode callback and reconciliation, plus webhook handling if public ingress is configured.
8. A backup followed by a restore on a separate test installation.

## 10. Backups

Mount a dedicated encrypted external drive at `/mnt/attendance-backups`. LUKS initialization erases the selected device; have IT identify the exact device and follow the operating system's encrypted-volume procedure rather than copying an unreviewed format command.

After unlocking and mounting it, make the backup directory private and confirm that it resolves to the external filesystem rather than the server's root disk:

```bash
sudo chown "$USER":"$USER" /mnt/attendance-backups
chmod 700 /mnt/attendance-backups
findmnt --target /mnt/attendance-backups
```

The backup script repeats this mount check, verifies that the destination and application are on different filesystems, and fails closed if the drive is absent; it will not silently fill an ordinary `/mnt` directory on the office server.

Run a backup:

```bash
BACKUP_DIR=/mnt/attendance-backups ./scripts/backup.sh
```

The script verifies that the destination is a separately mounted filesystem, verifies MySQL health, briefly stops the app so the database and application files share one consistent point in time, creates a transactional logical dump, copies `storage/app/private` plus legacy `storage/app/public` content through the Docker volume, restarts the app, adds a revision manifest, writes and verifies a SHA-256 checksum, and keeps 30 days by default. It deliberately excludes `storage/app/backups`, logs, sessions, and caches so a forgotten plaintext legacy dump cannot be nested into new archives. Its protected working directory is on the backup drive, and it never copies `.env.docker`.

For file-level encryption in addition to drive encryption, install `age`, create an offline identity, and provide only its public recipient during backup:

```bash
BACKUP_DIR=/mnt/attendance-backups \
BACKUP_AGE_RECIPIENT='age1REPLACE_WITH_PUBLIC_RECIPIENT' \
./scripts/backup.sh
```

Keep the private age identity offline and outside this server. Restore an encrypted archive by setting `AGE_IDENTITY_FILE` to that protected identity file.
Set its permissions to `600` (or read-only `400`) before restore; the restore script rejects a broadly readable identity.

Schedule backups as the deployment user. Write logs somewhere that user can actually write:

```cron
15 22 * * * umask 077; cd /srv/hg-attendance && BACKUP_DIR=/mnt/attendance-backups ./scripts/backup.sh >> storage/logs/backup.log 2>&1
```

Use two rotated encrypted drives and keep one disconnected in another secure location. A permanently attached drive is vulnerable to theft, hardware failure, operator error, and ransomware. Periodically copy an encrypted `age` archive to a separate company-approved off-machine location and verify its checksum there.

## 11. Restore

Test restoration at least quarterly on a separate server or isolated VM, never in a second checkout connected to the production Docker daemon. The Compose project name is intentionally fixed so operational scripts cannot silently target a different set of volumes. On production, the restore script first creates a safety backup, stops the application, verifies and extracts only expected archive paths, replaces the database and the two backed-up application-file directories, migrates forward, clears restored cache and scheduler locks, secures any photos restored from an older public-only archive, restarts, and waits for health.

The restore script accepts both the current archive layout containing only `storage/app/private` and `storage/app/public`, and the previous public-uploads-only layout. Legacy files are restored below `storage/app/public` and immediately processed by `staff:secure-photos` before the application starts.

Restore replaces only those two application-file directories; it does not inspect or delete excluded paths such as `storage/app/backups`. An authorized IT operator should handle any old plaintext dump separately under the company's retention and secure-disposal policy after a tested encrypted backup exists.

```bash
./scripts/restore.sh /mnt/attendance-backups/hg-attendance-TIMESTAMP.tar.gz

# Encrypted archive
AGE_IDENTITY_FILE=/secure/offline/path/age-key.txt \
BACKUP_AGE_RECIPIENT='age1REPLACE_WITH_PUBLIC_RECIPIENT' \
./scripts/restore.sh /mnt/attendance-backups/hg-attendance-TIMESTAMP.tar.gz.age
```

`BACKUP_AGE_RECIPIENT` keeps the automatic pre-restore safety backup file-level encrypted as well; the external volume itself must still be encrypted. Interactive restore requires typing the archive's exact confirmation phrase. `--yes` is intended only for a reviewed disaster-recovery automation. Restore replaces current production data; do not run it merely to inspect a backup.

## 12. Safe updates

Review release notes and migrations, then:

```bash
cd /srv/hg-attendance
BACKUP_DIR=/mnt/attendance-backups ./scripts/backup.sh
git pull --ff-only
new_tag="release-$(git rev-parse --short HEAD)"
sed -i "s/^APP_IMAGE_TAG=.*/APP_IMAGE_TAG=$new_tag/" .env.docker
./scripts/preflight.sh
docker compose --env-file .env.docker build --pull
docker compose --env-file .env.docker stop --timeout 60 app
docker compose --env-file .env.docker run --rm app php artisan migrate --force
docker compose --env-file .env.docker run --rm app php artisan cache:clear
docker compose --env-file .env.docker run --rm app php artisan schedule:clear-cache
docker compose --env-file .env.docker run --rm app php artisan staff:secure-photos
docker compose --env-file .env.docker up -d --wait --wait-timeout 120 app
docker compose --env-file .env.docker ps
curl --fail --cacert /srv/hg-attendance/secrets/tls/ca.crt \
  https://attendance.hoganguards.internal:8443/up
```

After the explicit stop, a failed migration or hardening command is a stop condition: leave the app stopped, inspect the error, and use the tested restore procedure if needed. Do not restart the old image against a partially migrated database.

Keep the previous tagged image until verification is complete. If an application-only rollback is safe, restore its prior `APP_IMAGE_TAG` in `.env.docker` and run `docker compose --env-file .env.docker up -d --no-build app`. Do not run old code against a forward-migrated database unless that release is explicitly compatible; otherwise use the tested database-and-files restore procedure.

## 13. Routine operations

Renew the server certificate before the 30-day preflight threshold. Securely attach the encrypted offline media containing the original CA key, confirm its mode is `600` or read-only `400`, and create a new versioned directory without replacing the live key in place:

```bash
./scripts/renew-internal-tls.sh \
  attendance.hoganguards.internal \
  10.147.20.10 \
  /srv/hg-attendance/secrets/tls/ca.crt \
  /media/secure-ca/ca.key \
  /srv/hg-attendance/secrets/tls-2028

sed -i 's#^TLS_CERT_PATH=.*#TLS_CERT_PATH=/srv/hg-attendance/secrets/tls-2028/server.crt#' .env.docker
sed -i 's#^TLS_KEY_PATH=.*#TLS_KEY_PATH=/srv/hg-attendance/secrets/tls-2028/server.key#' .env.docker
./scripts/preflight.sh
docker compose --env-file .env.docker up -d --no-deps --force-recreate --wait --wait-timeout 120 app
```

Use the real stable ZeroTier IP and a unique output directory. The renewal script verifies that the CA certificate and key match, signs a fresh DNS/IP certificate without copying the CA key, and verifies the resulting chain. After every authorized client passes the HTTPS health and kiosk secure-context checks, securely unmount the CA media. Retain the previous server certificate/key only until rollback and verification are complete; the CA certificate remains unchanged, so clients do not need to trust a new root.

```bash
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --since=1h app
docker compose --env-file .env.docker logs --since=1h mysql
openssl x509 -checkend 2592000 -noout -in /srv/hg-attendance/secrets/tls/server.crt
df -h
docker system df
```

Review failed logins, device failures, scheduled-report failures, subscription events, certificate lifetime, free disk space, backup age, and Docker health at least weekly. Apply Ubuntu and Docker security updates on a planned schedule. Never run `docker compose down -v`, `docker volume prune`, or an unreviewed cleanup command on the production server.
