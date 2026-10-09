#!/bin/bash
# Fresh, isolated runtime-package qualification only. No test APIs or app seeds.
set -euo pipefail
test "${NEXTCLOUD_TEST_MODE:-false}" = false
test "${NEXTCLOUD_TEST_ENV:-false}" = false
test ! -e /var/www/html/apps/chorestrike
test ! -L /var/www/html/apps/chorestrike
cd /var/www/html
occ() {
    local command
    printf -v command '%q ' php /var/www/html/occ "$@"
    su -s /bin/bash www-data -c "$command"
}
for attempt in {1..60}; do
    if php -r 'try { $db=new PDO("pgsql:host=db-pgsql;port=5432;dbname=nextcloud", "nextcloud", getenv("DB_PASS")); exit($db->query("SELECT 1")->fetchColumn()==1?0:1); } catch (Throwable $e) { exit(1); }'; then
        break
    fi
    test "$attempt" -lt 60
    sleep 1
done
if occ status --output=json | php -r '$s=json_decode(stream_get_contents(STDIN),true); exit(($s["installed"]??false)===true?0:1);'; then
    echo 'Existing qualification installation retained; no reinstall or reset.'
else
    occ maintenance:install --database=pgsql --database-host=db-pgsql --database-port=5432 \
        --database-name=nextcloud --database-user=nextcloud "--database-pass=$DB_PASS" \
        --admin-user=admin --admin-pass=admin --data-dir=/var/www/html/data
fi
# Import the complete array atomically: a partial apps_paths breaks later occ calls.
printf '%s' '{"system":{"apps_paths":[{"path":"/var/www/html/apps","url":"/apps","writable":false},{"path":"/var/www/html/custom_apps","url":"/custom_apps","writable":true}]}}' | occ config:import
occ config:system:set trusted_domains 1 "--value=${TEST_HTTP_URL#http://}"
occ config:system:set overwrite.cli.url "--value=$TEST_HTTP_URL"
occ config:system:set memcache.local '--value=\OC\Memcache\APCu'
if ! occ user:info package-owner >/dev/null 2>&1; then
    export OC_PASS=PackageOwner123!
    occ user:add --password-from-env --display-name='Package Owner' package-owner
    unset OC_PASS
fi
occ app:enable notifications
occ app:enable chorestrike
composer --working-dir=/var/www/html/custom_apps/chorestrike check-platform-reqs --no-dev
occ app:list --output=json | php -r '$apps=json_decode(stream_get_contents(STDIN),true); exit(isset($apps["enabled"]["chorestrike"])?0:1);'
occ config:system:get apps_paths 1 url | grep -qx /custom_apps
touch /tmp/chorestrike-ready
exec apache2-foreground
