#!/bin/sh
set -eu

cd /var/www/html

setup_marker="storage/framework/poc-setup-complete"
view_compiled_path="${VIEW_COMPILED_PATH:-storage/framework/views}"
mkdir -p "$view_compiled_path"
chmod 0777 "$view_compiled_path"

run_with_retries() {
    description="$1"
    shift

    attempt=1
    max_attempts="${LARAVEL_SETUP_RETRIES:-30}"

    until "$@"; do
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "$description failed after $max_attempts attempts."
            return 1
        fi

        echo "$description not ready yet; retrying in 2 seconds..."
        attempt=$((attempt + 1))
        sleep 2
    done
}

if [ "${COMPOSER_INSTALL_ON_STARTUP:-true}" = "true" ] && [ -f composer.json ]; then
    if [ -f composer.lock ]; then
        composer_hash="$(sha256sum composer.lock | awk '{ print $1 }')"
    else
        composer_hash="$(sha256sum composer.json | awk '{ print $1 }')"
    fi

    composer_install_mode="${COMPOSER_INSTALL_DEV:-false}"
    stamp_file="vendor/.composer-lock.${composer_install_mode}.sha256"
    needs_install=false

    if [ ! -f vendor/autoload.php ]; then
        needs_install=true
    elif [ ! -f "$stamp_file" ]; then
        needs_install=true
    elif [ "$(cat "$stamp_file" 2>/dev/null || true)" != "$composer_hash" ]; then
        needs_install=true
    fi

    if [ "$needs_install" = "true" ]; then
        mkdir -p vendor
        lock_dir="vendor/.composer-install.lock"
        lock_acquired=false

        while [ "$lock_acquired" = "false" ]; do
            if mkdir "$lock_dir" 2>/dev/null; then
                lock_acquired=true
                trap 'rmdir "$lock_dir" 2>/dev/null || true' EXIT INT TERM
            else
                echo "Composer install already running in another container; waiting..."
                sleep 2

                if [ -f vendor/autoload.php ] \
                    && [ -f "$stamp_file" ] \
                    && [ "$(cat "$stamp_file" 2>/dev/null || true)" = "$composer_hash" ]; then
                    needs_install=false
                    break
                fi
            fi
        done

        if [ "$needs_install" = "true" ]; then
            echo "Installing Composer dependencies..."
            composer_install_flags="--no-interaction --prefer-dist"

            if [ "$composer_install_mode" != "true" ]; then
                composer_install_flags="$composer_install_flags --no-dev"
            fi

            # shellcheck disable=SC2086
            composer install $composer_install_flags
            echo "$composer_hash" > "$stamp_file"
        fi
    fi
fi

if [ "${LARAVEL_AUTOMATED_SETUP:-false}" = "true" ]; then
    mkdir -p storage/framework
    rm -f "$setup_marker"

    if [ ! -f .env ] && [ -f .env.example ]; then
        echo "Creating local .env from .env.example..."
        cp .env.example .env
    fi

    if [ -f .env ]; then
        app_key="$(grep -E '^APP_KEY=' .env | tail -n 1 | cut -d '=' -f 2- || true)"

        if [ -z "$app_key" ]; then
            echo "Generating Laravel application key..."
            php artisan key:generate --force --no-interaction
        fi
    fi

    run_with_retries "Database migration" php artisan migrate --force --no-interaction

    if [ "${POC_RESET_PROCESSING_DATA_ON_STARTUP:-true}" = "true" ]; then
        php artisan poc:reset-data --force --no-interaction
    fi

    touch "$setup_marker"
fi

if [ "${LARAVEL_WAIT_FOR_SETUP:-false}" = "true" ]; then
    waited=0
    max_wait="${LARAVEL_SETUP_WAIT_SECONDS:-120}"

    while [ ! -f "$setup_marker" ]; do
        if [ "$waited" -ge "$max_wait" ]; then
            echo "Laravel automated setup did not complete within $max_wait seconds."
            exit 1
        fi

        echo "Waiting for Laravel automated setup to complete..."
        waited=$((waited + 2))
        sleep 2
    done
fi

exec "$@"
