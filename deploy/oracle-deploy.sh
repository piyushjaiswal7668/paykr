#!/usr/bin/env bash
# Invoked over authenticated SSH by the main-only production workflow.
set -Eeuo pipefail
umask 0077
sha=${1:?Full commit SHA required}
incoming=${2:?Upload directory required}
root=${PAYKR_ROOT:-/srv/paykr}
mode=${3:-graceful}
[[ $mode == graceful || $mode == maintenance ]] || exit 2
[[ $sha =~ ^[0-9a-f]{40}$ ]] || exit 2
[[ $incoming == "$root/incoming/$sha-"* && $incoming != *..* ]] || exit 2
exec 9>"$root/deploy.lock"
flock -n 9 || { echo 'Another deployment is running'; exit 1; }
# Fail before activation if the deploy user's reload permission has not been installed.
sudo -n -l /usr/bin/systemctl reload php8.3-fpm >/dev/null
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
# Compile views into this release, never clear views used by live requests.
export VIEW_COMPILED_PATH="$release/bootstrap/cache/views"
mkdir -p "$VIEW_COMPILED_PATH"
sudo -n chgrp www-data "$VIEW_COMPILED_PATH"
chmod 2770 "$VIEW_COMPILED_PATH"
COMPOSER_MAX_PARALLEL_HTTP=2 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
services=(paykr@horizon paykr@clock paykr@reverb paykr@scheduler)
if systemctl is-enabled --quiet paykr@whatsapp-sidecar; then
    if [[ $mode == maintenance ]]; then
        services+=(paykr@whatsapp-sidecar)
    elif ! diff -qr --exclude=node_modules --exclude=.env --exclude=data "$previous/services/whatsapp-sidecar" services/whatsapp-sidecar; then
        echo 'Sidecar code changed: use a scheduled maintenance deployment to restart it.' >&2
        exit 2
    fi
    npm ci --prefix services/whatsapp-sidecar --omit=dev --no-audit --no-fund
fi
# Let FPM and service users read newly installed dependencies too.
chmod -R a+rX vendor
# Composer's filament:upgrade republishes assets under the restrictive deployment umask.
# Restore public readability after those hooks; do not follow storage symlinks.
find public -type d -exec chmod a+rx {} +
find public -type f -exec chmod a+r {} +
if [[ -d services/whatsapp-sidecar/node_modules ]]; then
    chmod -R a+rX services/whatsapp-sidecar/node_modules
fi
php artisan storage:link
php artisan config:cache
php artisan route:cache
pending=$(php deploy/pending-migrations.php)
[[ $pending =~ ^[0-9]+$ ]] || exit 2
if [[ $mode == graceful && $pending != 0 ]]; then
    echo 'Pending migrations: graceful deployment stopped without touching live services. Review schema compatibility and schedule maintenance mode.' >&2
    exit 2
fi
# Retain hashed assets for pages opened before the symlink switch.
if [[ -d "$previous/public/build/assets" ]]; then
    cp -Rn "$previous/public/build/assets/." public/build/assets/
fi
php artisan view:cache
schema_started=0
activated=0
maintenance=0
restart_services() {
    if [[ $mode == graceful ]]; then
        sudo -n systemctl reload php8.3-fpm
        # systemd sends SIGTERM and waits for workers to finish (TimeoutStopSec=100).
        # Restart sequentially so the clock stays available while Horizon drains.
        for service in "${services[@]}"; do sudo -n systemctl restart "$service"; done
    else
        sudo -n systemctl reload php8.3-fpm
        sudo -n systemctl restart "${services[@]}"
    fi
}
activate() {
    ln -s "$1" "$root/current.deploy-next" &&
    mv -Tf "$root/current.deploy-next" "$root/current"
}
on_error() {
    code=$?
    trap - ERR
    set +e
    if [[ $mode == graceful ]] && (( activated )); then
        echo 'DEPLOY FAILED. Restoring previous code while HTTP remains available.' >&2
        rm -f "$root/current.deploy-next"
        if activate "$previous"; then
            restart_services || echo 'Service recovery failed; inspect systemd immediately.' >&2
        fi
    elif (( maintenance )); then
        if (( schema_started )); then
            sudo -n systemctl stop "${services[@]}"
            echo 'DEPLOY FAILED after migrations began. Maintenance remains ON. Inspect logs; no automatic database restore or code rollback.' >&2
        else
            echo 'DEPLOY FAILED. Restoring previous code release.' >&2
            rm -f "$root/current.deploy-next"
            if activate "$previous"; then
                cd "$previous"
                if restart_services; then php artisan up; fi
            fi
        fi
    fi
    echo "Failed release: $release; previous release: $previous" >&2
    exit "$code"
}
trap on_error ERR
if [[ $mode == maintenance ]]; then
    php artisan down --retry=30
    maintenance=1
    # Keep FPM running to return Laravel's 503 + Retry-After instead of nginx 502.
    sudo -n systemctl stop "${services[@]}"
fi
backup="$root/backups/$(basename "$incoming")"
mkdir -p "$backup"
# This installation uses the local root socket and paykr_production database.
sudo -n mysqldump --single-transaction --quick --no-tablespaces --routines --triggers --events paykr_production | gzip > "$backup/database.sql.gz"
if [[ $mode == maintenance ]]; then
    sudo -n tar -czf - -C "$root/shared" --exclude=storage/framework/cache --exclude=storage/framework/views --exclude=storage/logs .env storage sidecar-data > "$backup/shared.tar.gz"
else
    # Live mutable files cannot form a consistent archive. Back up configuration;
    # full storage/sidecar backups belong to scheduled maintenance/snapshot backups.
    sudo -n tar -czf - -C "$root/shared" .env > "$backup/config.tar.gz"
fi
if (( pending > 0 )); then
    schema_started=1
    php artisan migrate --force
fi
sudo -n chgrp -R www-data bootstrap/cache
sudo -n chmod -R g+rwX bootstrap/cache
if [[ $mode == maintenance ]]; then
    sudo -n chgrp -R www-data "$root/shared/storage"
    sudo -n find "$root/shared/storage" -type d -exec chmod 2770 {} +
    sudo -n find "$root/shared/storage" -type f -exec chmod 660 {} +
fi
# Caches can contain secrets; never leave them world-readable.
sudo -n find bootstrap/cache -type f -exec chmod 660 {} +
activate "$release"
activated=1
restart_services
if (( maintenance )); then php artisan up; fi
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
    if (( maintenance )); then php artisan down --retry=30; fi
    false
fi
maintenance=0
trap - ERR
echo "DEPLOYED $sha"
echo "Current: $release"
echo "Backup: $backup"
