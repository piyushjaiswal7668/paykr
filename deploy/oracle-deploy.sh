#!/usr/bin/env bash
# Invoked over authenticated SSH by the main-only production workflow.
set -Eeuo pipefail
umask 0077
sha=${1:?Full commit SHA required}
incoming=${2:?Upload directory required}
root=${PAYKR_ROOT:-/srv/paykr}
[[ $sha =~ ^[0-9a-f]{40}$ ]] || exit 2
[[ $incoming == "$root/incoming/$sha-"* && $incoming != *..* ]] || exit 2
exec 9>"$root/deploy.lock"
flock -n 9 || { echo 'Another deployment is running'; exit 1; }
cd "$incoming"
sha256sum --strict --check SHA256SUMS
previous=$(readlink -f "$root/current")
[[ -d $previous && -f $root/shared/.env ]] || exit 2
release="$root/releases/$(basename "$incoming")"
mkdir "$release"
# Source is git archive output from the exact tested main commit.
tar --no-same-owner -xzf source.tar.gz -C "$release"
python3 "$release/scripts/install-release-assets.py" "$sha" "$incoming/assets.tar.gz" "$release"
cd "$release"
mv storage storage.dist
ln -s "$root/shared/storage" storage
ln -s "$root/shared/.env" .env
# Source/assets must be readable by FPM; secret files remain in shared storage.
chmod -R a+rX "$release"
sudo -n chgrp -R www-data bootstrap/cache
sudo -n chmod -R g+rwX bootstrap/cache
sudo -n find bootstrap/cache -type d -exec chmod g+s {} +
COMPOSER_MAX_PARALLEL_HTTP=2 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
services=(paykr@horizon paykr@clock paykr@reverb paykr@scheduler)
if systemctl is-enabled --quiet paykr@whatsapp-sidecar; then
    services+=(paykr@whatsapp-sidecar)
    npm ci --prefix services/whatsapp-sidecar --omit=dev --no-audit --no-fund
fi
# Let FPM and service users read newly installed dependencies too.
chmod -R a+rX vendor
if [[ -d services/whatsapp-sidecar/node_modules ]]; then
    chmod -R a+rX services/whatsapp-sidecar/node_modules
fi
php artisan storage:link
php artisan config:cache
php artisan route:cache
pending=$(php deploy/pending-migrations.php)
[[ $pending =~ ^[0-9]+$ ]] || exit 2
schema_started=0
maintenance=0
restart_services() { sudo -n systemctl restart php8.3-fpm "${services[@]}"; }
activate() {
    ln -s "$1" "$root/current.deploy-next" &&
    mv -Tf "$root/current.deploy-next" "$root/current"
}
on_error() {
    code=$?
    trap - ERR
    set +e
    if (( maintenance )); then
        if (( schema_started )); then
            sudo -n systemctl stop "${services[@]}"
            echo 'DEPLOY FAILED after migrations began. Maintenance remains ON. Inspect logs; no automatic database restore or code rollback.' >&2
        else
            echo 'DEPLOY FAILED. Restoring previous code release.' >&2
            rm -f "$root/current.deploy-next"
            if activate "$previous"; then
                cd "$previous"
                php artisan view:clear
                if restart_services; then php artisan up; fi
            fi
        fi
    fi
    echo "Failed release: $release; previous release: $previous" >&2
    exit "$code"
}
trap on_error ERR
php artisan down --retry=30
maintenance=1
sudo -n systemctl stop php8.3-fpm "${services[@]}"
backup="$root/backups/$(basename "$incoming")"
mkdir -p "$backup"
# This installation uses the local root socket and paykr_production database.
sudo -n mysqldump --single-transaction --quick --no-tablespaces --routines --triggers --events paykr_production | gzip > "$backup/database.sql.gz"
sudo -n tar -czf - -C "$root/shared" --exclude=storage/framework/cache --exclude=storage/framework/views --exclude=storage/logs .env storage sidecar-data > "$backup/shared.tar.gz"
if (( pending > 0 )); then
    schema_started=1
    php artisan migrate --force
fi
php artisan view:cache
sudo -n chgrp -R www-data bootstrap/cache "$root/shared/storage"
sudo -n chmod -R g+rwX bootstrap/cache
sudo -n find "$root/shared/storage" -type d -exec chmod 2770 {} +
sudo -n find "$root/shared/storage" -type f -exec chmod 660 {} +
# Caches can contain secrets; never leave them world-readable.
sudo -n find bootstrap/cache -type f -exec chmod 660 {} +
activate "$release"
restart_services
php artisan up
# On failure, close HTTP access again before the failure handler runs.
healthy=0
for attempt in {1..12}; do
    if systemctl is-active --quiet php8.3-fpm "${services[@]}" &&
       curl --fail --silent --show-error --connect-timeout 5 --max-time 10 \
         "$(php deploy/application-url.php)/health" > "$incoming/health.json" &&
       python3 "$release/scripts/check-deploy-health.py" "$incoming/health.json"; then
        healthy=1
        break
    fi
    sleep 5
done
if (( ! healthy )); then
    php artisan down --retry=30
    false
fi
maintenance=0
trap - ERR
echo "DEPLOYED $sha"
echo "Current: $release"
echo "Backup: $backup"
