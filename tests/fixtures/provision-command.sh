#!/bin/bash
# Command doubles only: never touch the installed Nextcloud or its ready marker.
set -eu
case "${0##*/}" in
    php)
        # Drain piped input like the real interpreter: an early exit would
        # break the writer under pipefail and flip the probe at random.
        if [[ "$*" == *'php://stdin'* ]]; then cat > /dev/null; fi
        if [ "$PROVISION_SCENARIO" = wrong-code-runtime ]; then echo RUNTIME_FAILED; exit 1; fi
        if [[ "$*" == *'php://stdin'* ]] && [ "$PROVISION_SCENARIO" = http-runtime-mismatch ]; then exit 1; fi
        if [[ "$*" == *'new Redis()'* ]] && [ "$PROVISION_SCENARIO" = redis-unreachable ]; then exit 1; fi
        # TECH-TEST-006 app-only upgrade probe: pending only in the app-upgrade scenarios.
        if [[ "$*" == *'empty($status["needsDbUpgrade"])'* ]]; then [[ "$PROVISION_SCENARIO" == app-upgrade* ]]; exit $?; fi ;;
    su)
        case "$*" in
            *maintenance:install*) echo INSTALL_FAILED; exit 1 ;;
            *'occ status'*)
                if [ "$PROVISION_SCENARIO" = install-failure ] || [ "$PROVISION_SCENARIO" = preserve-uninstalled ]; then exit 1; fi
                echo 'installed: true' ;;
            *'config:system:get dbtype'*)
                if [ "$PROVISION_SCENARIO" = wrong-provider ]; then echo pgsql; else echo mysql; fi ;;
            *'config:system:get apps_paths --output=json'*)
                case "$PROVISION_SCENARIO" in
                    preserve-*|existing-apps-paths)
                        echo '[{"path":"\/var\/www\/html\/apps","url":"\/apps","writable":false},{"path":"\/var\/www\/html\/custom_apps","url":"\/custom_apps","writable":true}]' ;;
                    unexpected-apps-paths)
                        echo '[{"path":"/srv/foreign_apps","url":"/apps","writable":true}]' ;;
                    apps-paths-read-failure) echo CONFIG_READ_FAILED >&2; exit 1 ;;
                    *) echo '"__cs_missing__"' ;;
                esac ;;
            *'config:system:get memcache.locking'*)
                if [ "$PROVISION_SCENARIO" = redis-config-failure ]; then echo '\OC\Memcache\APCu'; else echo '\OC\Memcache\Redis'; fi ;;
            *'config:system:get redis host'*) echo "$REDIS_HOST" ;;
            *'config:system:get apps_paths 0 path'*) echo /var/www/html/apps ;;
            *'config:system:get apps_paths 1 url'*) echo /custom_apps ;;
            *config:import*)
                if [ "$PROVISION_SCENARIO" = apps-paths-import-failure ]; then echo APPS_PATHS_IMPORT_FAILED; exit 1; fi
                if [ -n "${REDIS_HOST:-}" ] && grep -q memcache.locking; then echo REDIS_CONFIG_IMPORTED; exit 0; fi
                echo APPS_PATHS_IMPORTED ;;
            *'config:system:get version'*)
                if [ "$PROVISION_SCENARIO" = old-volume ]; then echo 31.0.0.1;
                elif [[ "$PROVISION_SCENARIO" == preserve-upgrade* ]] && [ ! -f "${PROVISION_STATE:-/nonexistent}/upgraded" ]; then echo "$TEST_RUNTIME_UPGRADE_FROM";
                else echo "$TEST_RUNTIME_INTERNAL_VERSION"; fi ;;
            *'occ upgrade'*)
                if [[ "$PROVISION_SCENARIO" == *upgrade-failure ]]; then echo UPGRADE_FAILED; exit 1; fi
                if [ -n "${PROVISION_STATE:-}" ]; then : > "$PROVISION_STATE/upgraded"; fi
                echo UPGRADE_RAN ;;
            *'config:system:set'*) echo CONFIG_MUTATION ;;
            *'app:enable chorestrike'*)
                if [ "$PROVISION_SCENARIO" = enable-failure ]; then echo ENABLE_FAILED; exit 1; fi ;;
            *app:list*)
                echo 'Enabled:'
                if [ "$PROVISION_SCENARIO" != disabled-app ]; then echo '  - chorestrike: 0.1'; fi ;;
        esac ;;
    curl)
        if [ "$PROVISION_SCENARIO" = http-failure ]; then exit 1; fi
        echo "{\"installed\":true,\"maintenance\":false,\"needsDbUpgrade\":false,\"version\":\"$TEST_RUNTIME_INTERNAL_VERSION\"}" ;;
    bash)
        if [ "$PROVISION_SCENARIO" = seed-failure ]; then echo SEED_FAILED; exit 1; fi
        echo SEED_SUCCEEDED ;;
    touch) echo READY_MARKER ;;
esac
exit 0
