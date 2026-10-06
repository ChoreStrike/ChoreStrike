#!/bin/bash
# Command doubles only: never touch the installed Nextcloud or its ready marker.
set -eu
case "${0##*/}" in
    php)
        if [ "$PROVISION_SCENARIO" = wrong-code-runtime ]; then echo RUNTIME_FAILED; exit 1; fi
        if [[ "$*" == *'php://stdin'* ]] && [ "$PROVISION_SCENARIO" = http-runtime-mismatch ]; then exit 1; fi ;;
    su)
        case "$*" in
            *maintenance:install*) echo INSTALL_FAILED; exit 1 ;;
            *'occ status'*)
                if [ "$PROVISION_SCENARIO" = install-failure ]; then exit 1; fi
                echo 'installed: true' ;;
            *'config:system:get dbtype'*)
                if [ "$PROVISION_SCENARIO" = wrong-provider ]; then echo pgsql; else echo mysql; fi ;;
            *'config:system:get version'*)
                if [ "$PROVISION_SCENARIO" = old-volume ]; then echo 31.0.0.1; else echo "$TEST_RUNTIME_INTERNAL_VERSION"; fi ;;
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
