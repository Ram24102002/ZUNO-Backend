#!/bin/sh
# Runs on every container start: install Bagisto on first boot, then keep the schema,
# admin login and ZUNO catalogue up to date, then hand over to Apache.
set -e
cd /var/www/bagisto

# Debian's MariaDB client rejects MySQL's self-signed certificate; both containers share a private network
DB_CLIENT="--skip-ssl -h $DB_HOST -u $DB_USERNAME -p$DB_PASSWORD"

echo "Waiting for MySQL..."
until mysqladmin ping $DB_CLIENT --silent 2>/dev/null; do sleep 2; done

# The storage volume starts empty on some hosts; Laravel needs these folders
mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

installed=$(mysql $DB_CLIENT -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_DATABASE' AND table_name='channels'")

php artisan migrate --force

if [ "$installed" = "0" ]; then
  echo "First boot: seeding Bagisto"
  php artisan db:seed --force
fi

php artisan storage:link 2>/dev/null || true

# The admin login always matches ADMIN_EMAIL / ADMIN_PASSWORD from the environment
php artisan tinker --execute='
  $admin = Webkul\User\Models\Admin::orderBy("id")->first();
  $admin->forceFill(["email" => env("ADMIN_EMAIL"), "password" => bcrypt(env("ADMIN_PASSWORD")), "status" => 1])->save();
'

php artisan zuno:setup
php artisan optimize:clear >/dev/null

chown -R www-data:www-data storage bootstrap/cache
exec "$@"
