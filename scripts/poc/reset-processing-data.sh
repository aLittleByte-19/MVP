#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."

php artisan poc:reset-data --force

