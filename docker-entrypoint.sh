#!/bin/sh
# docker-entrypoint.sh
#
# Container boot sequence. Kept as a real script file (rather than an
# inline `sh -c "..."` one-liner in the Dockerfile CMD or a Render "Docker
# Command" dashboard override) so there's a single unambiguous path to
# invoke, with no shell quoting/escaping to get mangled by any layer in
# between.
#
# `set -e` makes any failing command here stop the boot immediately and
# fail the deploy visibly, instead of silently starting the web server on
# a broken/incomplete boot (config not cached, migrations not applied).

set -e

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan migrate --force

# This app has no resources/views (API-only, no Blade templates) and no
# config/view.php, so `view:cache` has nothing to cache and always fails
# with "View path not found" — intentionally omitted.

# package:discover regenerates the cached package/service-provider manifest
# that `composer install --no-scripts` skips during the image build. Best
# effort only: don't fail the whole deploy if it errors.
php artisan package:discover --ansi || true

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
