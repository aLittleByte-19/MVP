#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."

php artisan mvp:reset-data --force

