#!/bin/bash
set -euo pipefail

export TEST_DB_TYPE="${TEST_DB_TYPE:-mysql}"
export TEST_DB_PROFILE="${TEST_DB_PROFILE:-minimal}"
case "$TEST_DB_TYPE" in
    mysql|pgsql|sqlite) ;;
    *) echo "Unsupported TEST_DB_TYPE. Use mysql, pgsql or sqlite." >&2; exit 1 ;;
esac
case "$TEST_DB_PROFILE" in
    empty|minimal|family) ;;
    *) echo "Unsupported TEST_DB_PROFILE. Use empty, minimal or family." >&2; exit 1 ;;
esac
if [ -n "${TEST_RUNTIME_INTERNAL_VERSION:-}${TEST_RUNTIME_PHP_VERSION:-}" ]; then
    if [[ ! "${TEST_RUNTIME_INTERNAL_VERSION:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] \
        || [[ ! "${TEST_RUNTIME_PHP_VERSION:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "Both frozen runtime versions are required and must be exact." >&2
        exit 1
    fi
    php -r 'require "/var/www/html/version.php";
        if (PHP_VERSION !== getenv("TEST_RUNTIME_PHP_VERSION")
            || implode(".", $OC_Version) !== getenv("TEST_RUNTIME_INTERNAL_VERSION")) {
            fwrite(STDERR, "Frozen code/PHP runtime mismatch.\n"); exit(1);
        }'
fi
TEST_RUNTIME_PRESERVATION="${TEST_RUNTIME_PRESERVATION:-false}"
TEST_RUNTIME_UPGRADE_FROM="${TEST_RUNTIME_UPGRADE_FROM:-}"
if [ "$TEST_RUNTIME_PRESERVATION" != true ] && [ "$TEST_RUNTIME_PRESERVATION" != false ]; then
    echo "TEST_RUNTIME_PRESERVATION must be exactly true or false." >&2
    exit 1
fi
if [ -n "$TEST_RUNTIME_UPGRADE_FROM" ]; then
    if [ "$TEST_RUNTIME_PRESERVATION" != true ] \
        || [[ ! "$TEST_RUNTIME_UPGRADE_FROM" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "TEST_RUNTIME_UPGRADE_FROM requires preservation mode and an exact internal version." >&2
        exit 1
    fi
fi
if [ "$TEST_RUNTIME_PRESERVATION" = true ] && [ -z "${TEST_RUNTIME_INTERNAL_VERSION:-}" ]; then
    echo "Preservation mode requires both frozen runtime versions." >&2
    exit 1
fi
if [ "$TEST_DB_TYPE" != sqlite ]; then
    for key in DB_HOST DB_PORT DB_NAME DB_USER DB_PASS; do
        if [ -z "${!key:-}" ]; then
            echo "Required database setting is missing: $key" >&2
            exit 1
        fi
    done
    if [[ ! "$DB_PORT" =~ ^[0-9]{1,5}$ ]] || (( 10#$DB_PORT < 1 || 10#$DB_PORT > 65535 )); then
        echo "DB_PORT must be an integer between 1 and 65535." >&2
        exit 1
    fi
fi
# AIO-near cache row (CS-PLAN-084 WP-A): with REDIS_HOST, PHP sessions,
# the distributed cache and file locking use Redis exactly like the AIO
# Nextcloud container. Without it the APCu-only default stays unchanged.
REDIS_HOST="${REDIS_HOST:-}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_SESSION_INI="${PHP_INI_DIR:-/usr/local/etc/php}/conf.d/zz-chorestrike-redis-session.ini"
if [ -n "$REDIS_HOST" ]; then
    if [[ ! "$REDIS_HOST" =~ ^[a-z0-9][a-z0-9.-]{0,62}$ ]] || [[ ! "$REDIS_PORT" =~ ^[0-9]{1,5}$ ]] \
        || (( 10#$REDIS_PORT < 1 || 10#$REDIS_PORT > 65535 )); then
        echo "REDIS_HOST must be a plain host name and REDIS_PORT an integer between 1 and 65535." >&2
        exit 1
    fi
fi
php -r 'if (!in_array(getenv("TEST_DB_TYPE"), PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Required PDO database driver is missing.\n"); exit(1);
}'
if [ "${1:-}" = --validate-only ] && [ "$#" -eq 1 ]; then
    echo "Database selector, profile, settings and driver validated: $TEST_DB_TYPE"
    exit 0
elif [ "$#" -ne 0 ]; then
    echo "Unsupported entrypoint argument." >&2
    exit 1
fi

rm -f /tmp/chorestrike-ready
echo "=== ChoreStrike Docker Test Environment ($TEST_DB_TYPE) ==="

# Redis sessions must be configured before Apache starts (AIO redis-session.ini).
if [ -n "$REDIS_HOST" ]; then
    {
        echo 'session.save_handler = redis'
        echo "session.save_path = \"tcp://${REDIS_HOST}:${REDIS_PORT}?timeout=3.0&read_timeout=10.0\""
        echo 'redis.session.locking_enabled = 1'
        echo 'redis.session.lock_retries = -1'
        echo 'redis.session.lock_wait_time = 100000'
        echo 'redis.session.lock_expire = 60'
    } > "$REDIS_SESSION_INI"
    REDIS_OK=false
    for i in {1..60}; do
        if php -r 'try { $r = new Redis(); $r->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"), 2.0);
            exit($r->ping() ? 0 : 1); } catch (Throwable $e) { exit(1); }'; then
            REDIS_OK=true
            break
        fi
        echo "Redis pending... ($i/60)"
        sleep 1
    done
    if [ "$REDIS_OK" != true ]; then
        echo "Redis is unreachable after 60 attempts. Aborting setup." >&2
        exit 1
    fi
else
    rm -f "$REDIS_SESSION_INI"
fi

apache2-foreground &
APACHE_PID=$!
trap 'kill "$APACHE_PID" 2>/dev/null || true' EXIT

# Probe authenticated TCP through the same PDO driver used by Nextcloud.
# SQLite is created by maintenance:install in its isolated on-disk data directory.
if [ "$TEST_DB_TYPE" != sqlite ]; then
    DB_AUTH_OK=false
    for i in {1..60}; do
        if php -r 'try {
            $dsn = getenv("TEST_DB_TYPE") . ":host=" . getenv("DB_HOST")
                . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_NAME");
            $db = new PDO($dsn, getenv("DB_USER"), getenv("DB_PASS"));
            exit($db->query("SELECT 1")->fetchColumn() == 1 ? 0 : 1);
        } catch (Throwable $e) { exit(1); }'; then
            DB_AUTH_OK=true
            break
        fi
        echo "Database authentication pending... ($i/60)"
        sleep 1
    done
    if [ "$DB_AUTH_OK" != true ]; then
        echo "Database authentication failed after 60 attempts. Aborting setup." >&2
        exit 1
    fi
fi

occ() {
    local command
    printf -v command '%q ' php /var/www/html/occ "$@"
    su -s /bin/bash www-data -c "$command"
}

# Install dependencies (a preserved candidate is unchanged; never touch its vendor tree)
if [ "$TEST_RUNTIME_PRESERVATION" = true ]; then
    echo "Preservation mode: keeping the installed vendor tree and populated data..."
else
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

# Install Nextcloud (if needed) and enable app
echo "Preparing Nextcloud installation..."
cd /var/www/html

# Check if Nextcloud is already installed
if occ status | grep -q "installed: true"; then
    echo "Nextcloud is already installed, skipping installation..."
elif [ "$TEST_RUNTIME_PRESERVATION" = true ]; then
    echo "Preservation mode requires an already installed Nextcloud. Aborting." >&2
    exit 1
else
    echo "Running initial Nextcloud installation..."
    INSTALL_ARGS=(maintenance:install "--database=$TEST_DB_TYPE"
        --admin-user=admin --admin-pass=admin --data-dir=/var/www/html/data)
    if [ "$TEST_DB_TYPE" != sqlite ]; then
        INSTALL_ARGS+=("--database-host=$DB_HOST" "--database-port=$DB_PORT"
            "--database-name=$DB_NAME" "--database-user=$DB_USER" "--database-pass=$DB_PASS")
    fi
    occ "${INSTALL_ARGS[@]}"
fi

if ! occ status | grep -q "installed: true"; then
    echo "Nextcloud installation failed"
    exit 1
fi

# Alias-free app discovery (CS-PLAN-083 WP1): the app physically lives in
# custom_apps and is NEVER linked into /apps — that symlink masked the
# production ChunkLoadError defect class. The complete apps_paths array is
# validated/imported atomically before any app-dependent OCC/version/upgrade
# call. Unexpected existing configuration or an existing /apps alias fails
# closed: never delete data or overwrite unrelated app roots. The old symlink
# lived in the container filesystem (not a volume), so recreating the owned
# container removes it while config/data/DB volumes are retained.
echo "Configuring alias-free app discovery..."
if [ -e /var/www/html/apps/chorestrike ] || [ -L /var/www/html/apps/chorestrike ]; then
    echo "Refusing to provision: /var/www/html/apps/chorestrike exists (legacy alias or foreign app root). Recreate the owned container to remove the container-local alias; config/data/DB volumes are retained." >&2
    exit 1
fi
EXPECTED_APPS_PATHS='[{"path":"\/var\/www\/html\/apps","url":"\/apps","writable":false},{"path":"\/var\/www\/html\/custom_apps","url":"\/custom_apps","writable":true}]'
if ! EXISTING_APPS_PATHS="$(occ config:system:get apps_paths --output=json --default-value=__cs_missing__)"; then
    echo "Refusing to provision: apps_paths configuration probe failed." >&2
    exit 1
fi
if [ "$EXISTING_APPS_PATHS" != '"__cs_missing__"' ]; then
    if [ "$EXISTING_APPS_PATHS" != "$EXPECTED_APPS_PATHS" ]; then
        echo "Refusing to provision: unexpected existing apps_paths configuration (${EXISTING_APPS_PATHS:-empty}). Not overwriting unrelated app roots." >&2
        exit 1
    fi
    echo "Keeping existing alias-free apps_paths configuration."
else
    # Import the complete array atomically: a partial apps_paths breaks
    # every later occ call (same contract as package-smoke-entrypoint.sh).
    printf '%s' '{"system":{"apps_paths":[{"path":"/var/www/html/apps","url":"/apps","writable":false},{"path":"/var/www/html/custom_apps","url":"/custom_apps","writable":true}]}}' | occ config:import
    echo "Imported complete apps_paths configuration."
fi
if ! occ config:system:get apps_paths 0 path | grep -qx /var/www/html/apps \
    || ! occ config:system:get apps_paths 1 url | grep -qx /custom_apps; then
    echo "apps_paths verification failed. Aborting setup." >&2
    exit 1
fi
echo "App discovery: /apps (read-only) + /custom_apps (writable), no alias."

# Nextcloud installs SQLite via the sqlite selector but stores dbtype=sqlite3.
EXPECTED_DB_TYPE="$TEST_DB_TYPE"
if [ "$TEST_DB_TYPE" = sqlite ]; then
    EXPECTED_DB_TYPE=sqlite3
fi
if ! occ config:system:get dbtype | grep -qx "$EXPECTED_DB_TYPE"; then
    echo "Installed database provider does not match TEST_DB_TYPE. Aborting setup." >&2
    exit 1
fi
if [ "$TEST_RUNTIME_PRESERVATION" = true ]; then
    INSTALLED_VERSION="$(occ config:system:get version)"
    if [ -n "$TEST_RUNTIME_UPGRADE_FROM" ]; then
        if [ "$INSTALLED_VERSION" != "$TEST_RUNTIME_UPGRADE_FROM" ]; then
            echo "Populated source version does not match the pinned update source. Aborting before any upgrade." >&2
            exit 1
        fi
        if [ "$TEST_RUNTIME_UPGRADE_FROM" = "$TEST_RUNTIME_INTERNAL_VERSION" ]; then
            echo "Update source equals the frozen target; a same-build reinstall is not a runtime update." >&2
            exit 1
        fi
        echo "Running the official Nextcloud upgrade path ($INSTALLED_VERSION -> $TEST_RUNTIME_INTERNAL_VERSION)..."
        occ upgrade
        INSTALLED_VERSION="$(occ config:system:get version)"
    fi
    if [ "$INSTALLED_VERSION" != "$TEST_RUNTIME_INTERNAL_VERSION" ]; then
        echo "Preserved runtime version does not match the frozen target. Aborting." >&2
        exit 1
    fi
elif [ -n "${TEST_RUNTIME_INTERNAL_VERSION:-}" ] \
    && ! occ config:system:get version | grep -qx "$TEST_RUNTIME_INTERNAL_VERSION"; then
    echo "Installed Nextcloud version does not match the frozen runtime. Aborting before configuration or seed." >&2
    exit 1
fi

# TECH-TEST-006: an app version bump on kept disposable volumes leaves
# Nextcloud waiting for an upgrade of the app alone. Ordinary, seeded
# provisioning applies it through the official upgrade path — only with a
# frozen core version, which was verified above, so this never updates
# Nextcloud itself; preservation mode keeps its pinned
# TEST_RUNTIME_UPGRADE_FROM contract and never upgrades implicitly.
if [ "$TEST_RUNTIME_PRESERVATION" != true ] && [ -n "${TEST_RUNTIME_INTERNAL_VERSION:-}" ] \
    && occ status --output=json | php -r '
    $status = json_decode(file_get_contents("php://stdin"), true);
    exit(empty($status["needsDbUpgrade"]) ? 1 : 0);'; then
    echo "App-only upgrade pending on the kept disposable volumes; running occ upgrade..."
    occ upgrade
fi

echo "Configuring trusted domains and URL..."
TEST_HTTP_URL="${TEST_HTTP_URL:-http://localhost:8080}"
occ config:system:set trusted_domains 1 "--value=${TEST_HTTP_URL#http://}"
occ config:system:set overwrite.cli.url "--value=$TEST_HTTP_URL"
occ config:system:set memcache.local '--value=\OC\Memcache\APCu'
if [ -n "$REDIS_HOST" ]; then
    echo "Configuring Redis distributed cache and file locking (AIO-near row)..."
    printf '{"system":{"memcache.distributed":"\\\\OC\\\\Memcache\\\\Redis","memcache.locking":"\\\\OC\\\\Memcache\\\\Redis","redis":{"host":"%s","port":%d,"timeout":3.0,"read_timeout":10.0}}}' \
        "$REDIS_HOST" "$((10#$REDIS_PORT))" | occ config:import
    if ! occ config:system:get memcache.locking | grep -qx '\\OC\\Memcache\\Redis' \
        || ! occ config:system:get redis host | grep -qx "$REDIS_HOST"; then
        echo "Redis cache/locking verification failed. Aborting setup." >&2
        exit 1
    fi
fi
occ config:system:set ratelimit.protection.enabled --value true --type boolean
occ background:cron

# The E2E setup logs several users in per run (including expected failures for
# profile users the active DB profile does not create). Without this, the
# brute-force throttle delays logins exponentially and breaks the auth setup.
echo "Disabling brute-force protection (disposable test environment)..."
occ config:system:set auth.bruteforce.protection.enabled --value false --type boolean

echo "Enabling notifications app (bell support for E2E, CS-PLAN-023)..."
occ app:enable notifications

echo "Enabling chorestrike app..."
occ app:enable chorestrike

if ! occ app:list | grep -A999 '^Enabled:' | grep -q '^  - chorestrike:'; then
    echo "ChoreStrike app is not enabled. Aborting setup."
    exit 1
fi

echo "Ensuring test users based on profile..."
PROFILE="$TEST_DB_PROFILE"
if [ "$TEST_RUNTIME_PRESERVATION" = true ]; then
    echo "Preservation mode: keeping existing users and populated data (no user creation, no reseed)."
fi

ensure_user() {
    local user_id="$1"
    local user_pass="$2"
    local display_name="$3"

    if occ user:info "$user_id" >/dev/null 2>&1; then
        echo "User '${user_id}' already exists."
        return 0
    fi

    export OC_PASS="$user_pass"
    occ user:add --password-from-env "--display-name=$display_name" "$user_id"
    unset OC_PASS
    echo "Created user '${user_id}' (${display_name})."
}

# Create users based on profile
if [ "$TEST_RUNTIME_PRESERVATION" != true ]; then
case "$PROFILE" in
    family)
        echo "Creating family users (6 users)..."
        ensure_user "mom" "Mom123!" "Mom"
        ensure_user "dad" "Dad123!" "Dad"
        ensure_user "teen" "Teen123!" "Teen"
        ensure_user "kid" "Kid123!" "Kid"
        ensure_user "grandma" "Grandma123!" "Grandma"
        ensure_user "friend" "Friend123!" "Friend"
        ;;
    minimal)
        echo "Creating minimal users (2 users)..."
        ensure_user "adult" "Adult123!" "Adult User"
        ensure_user "kid" "Kid123!" "Kid User"
        ;;
    empty)
        echo "No users created for empty profile."
        ;;
esac
fi

# Wait for Nextcloud to be fully ready via HTTP
echo "Waiting for Nextcloud HTTP interface..."
HTTP_READY=false
for i in {1..60}; do
    if curl -fsS http://localhost:80/status.php | php -r '
        $status = json_decode(file_get_contents("php://stdin"), true);
        $expected = getenv("TEST_RUNTIME_INTERNAL_VERSION");
        exit(($status["installed"] ?? null) === true
            && ($status["maintenance"] ?? null) === false
            && ($status["needsDbUpgrade"] ?? null) === false
            && (!$expected || ($status["version"] ?? null) === $expected) ? 0 : 1);'; then
        echo "Nextcloud HTTP interface is ready!"
        HTTP_READY=true
        break
    fi
    echo "Nextcloud HTTP not ready yet... ($i/60)"
    sleep 2
done
if [ "$HTTP_READY" != true ]; then
    echo "Nextcloud HTTP readiness failed. Aborting setup." >&2
    exit 1
fi

# Seed test data for E2E tests (never on a preserved populated instance)
if [ "$TEST_RUNTIME_PRESERVATION" != true ]; then
    echo "Seeding test data..."
    SEED_SCRIPT="${TEST_SEED_SCRIPT:-/seed-test-data.sh}"
    if [ ! -f "$SEED_SCRIPT" ]; then
        echo "Required seed script is missing. Aborting setup." >&2
        exit 1
    fi
    bash "$SEED_SCRIPT"
fi

# Health must include app enablement and required seed completion, not just HTTP.
touch /tmp/chorestrike-ready

echo "=== Setup Complete ==="
echo "ChoreStrike is ready ($TEST_DB_TYPE, $PROFILE) at $TEST_HTTP_URL"

# Keep Apache running
wait $APACHE_PID
