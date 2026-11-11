# Laravel → Django: blogsaas.task19.com

Run these commands on the Ubuntu droplet as root, in order. Stop if a command fails. If Django is already serving this site, skip to **Later Django updates** at the end. This is a one-time migration; do not rerun the import after users start using Django. Keep the original Laravel MySQL database, `.env`, APP_KEY, uploads, and backups. Do not run Laravel migrations for this cutover.

## 1. Back up and put Laravel into maintenance

```bash
cd /var/www/Blog-SaaS
umask 077
CUTOVER_BACKUP="/root/blogsaas-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$CUTOVER_BACKUP"
git rev-parse HEAD > "$CUTOVER_BACKUP/laravel-commit.txt"
cp .env "$CUTOVER_BACKUP/laravel.env"
cp -a /etc/nginx "$CUTOVER_BACKUP/nginx"
php artisan down
mysqldump --single-transaction --routines --triggers blog_saas > "$CUTOVER_BACKUP/laravel.sql"
tar -czf "$CUTOVER_BACKUP/uploads.tar.gz" storage/app/public
```

Stop any Laravel queue worker/scheduler service you previously created before the final export; do not stop MySQL. The dump uses root's local MySQL socket authentication. Verify it succeeds and the backup exists before continuing.

## 2. Pull main, preserving the storage symlink

If `public/storage` is a symlink and Git tracks `public/storage/.gitignore`, the old tracked placeholder can block pull. Temporarily move only the symlink; uploaded files stay in storage/app/public.

```bash
cd /var/www/Blog-SaaS
if [ -L public/storage ] && git ls-files --error-unmatch public/storage/.gitignore >/dev/null 2>&1; then
    mv public/storage "$CUTOVER_BACKUP/public-storage-link"
    git restore --source=HEAD --worktree -- public/storage/.gitignore
fi
git status --short
```

If other tracked files are modified, preserve/review those changes before proceeding; do not use `git reset --hard`.

```bash
git switch main
git pull --ff-only origin main
mkdir -p .local
chmod 700 .local
php tools/export_laravel.php .local/laravel-export.json
```

The export requires the existing Laravel vendor directory and original `.env`. It contains private data; never put it in public/ or Git. Exporter refuses to overwrite an existing export. Save the export to the backup too:

```bash
cp .local/laravel-export.json "$CUTOVER_BACKUP/laravel-export.json"
```

## 3. Install Python dependencies

The backup shell uses a private umask. Restore normal permissions before creating the virtual environment so the www-data service can read and execute it:

```bash
umask 022
```

On the 512 MB droplet, check `free -h`, `swapon --show`, and `df -h /`. If no swap exists and at least 2 GB disk is free:

```bash
fallocate -l 1G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
printf '/swapfile none swap sw 0 0\n' >> /etc/fstab
```

Skip these swap commands if swap is already configured.

```bash
apt update
apt install -y python3-venv python3-dev build-essential pkg-config default-libmysqlclient-dev
python3 -m venv .venv
chmod -R a+rX .venv
.venv/bin/pip install -r requirements.lock
.venv/bin/pip install 'mysqlclient>=2.2,<3'
```

## 4. Create a separate Django database

```bash
mysql
```

Inside MySQL, replace CHANGE_THIS_DB_PASSWORD with a password you choose; use exactly that same password in the environment below. This does not change the Laravel user's password.

```sql
CREATE DATABASE blog_saas_django CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'blog_saas_django'@'localhost' IDENTIFIED BY 'CHANGE_THIS_DB_PASSWORD';
GRANT ALL PRIVILEGES ON blog_saas_django.* TO 'blog_saas_django'@'localhost';
EXIT;
```

## 5. Configure Django

```bash
if [ ! -e .env.django ]; then cp .env.django.example .env.django; fi
chmod 600 .env.django
.venv/bin/python -c 'import secrets; print(secrets.token_urlsafe(64))'
nano .env.django
```

Paste the generated value into DJANGO_SECRET_KEY. Set these values, keeping other provider settings as needed:

```dotenv
DJANGO_DEBUG=false
DJANGO_SECRET_KEY=PASTE_GENERATED_VALUE
DJANGO_ALLOWED_HOSTS=blogsaas.task19.com,127.0.0.1,localhost
DJANGO_CSRF_TRUSTED_ORIGINS=https://blogsaas.task19.com
DJANGO_PUBLIC_URL=https://blogsaas.task19.com
DJANGO_TRUST_PROXY=true
DJANGO_DB_ENGINE=mysql
DJANGO_DB_NAME=blog_saas_django
DJANGO_DB_USER=blog_saas_django
DJANGO_DB_PASSWORD="CHANGE_THIS_DB_PASSWORD"
DJANGO_DB_HOST=localhost
DJANGO_DB_PORT=3306
AI_ENABLED=false
```

Keep this Django secret stable: encrypted imported data depends on it. Configure the Anthropic key/model separately to enable AI; do not copy local SQLite databases or commit credentials.

## 6. Import and verify data

```bash
.venv/bin/python manage.py migrate
.venv/bin/python manage.py import_laravel .local/laravel-export.json
```

Review the printed source counts, then apply to the fresh Django database:

```bash
.venv/bin/python manage.py import_laravel .local/laravel-export.json --apply
mkdir -p .local/media
cp -a storage/app/public/. .local/media/
.venv/bin/python manage.py collectstatic --noinput
.venv/bin/python manage.py check --deploy
chown -R www-data:www-data .local
chmod 750 .local
chown root:www-data .env.django
chmod 640 .env.django
```

The importer preserves users and supported password hashes, projects, stories, and supported related records. It refuses a populated destination. Real production data migration still needs validation: compare counts, log in with an existing account, inspect media and published API responses. Do not rerun import blindly after a failure.

## 7. Start Django and its AI worker

```bash
cat > /etc/systemd/system/blogsaas-web.service <<'UNIT'
[Unit]
Description=TryWryter Django web
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/Blog-SaaS
ExecStart=/var/www/Blog-SaaS/.venv/bin/gunicorn blogsaas.wsgi:application --bind 127.0.0.1:8001 --workers 1 --threads 2 --timeout 90 --access-logfile - --error-logfile -
Restart=on-failure
RestartSec=5
UMask=0027

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/blogsaas-worker.service <<'UNIT'
[Unit]
Description=TryWryter background worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/Blog-SaaS
ExecStart=/var/www/Blog-SaaS/.venv/bin/python manage.py work
Restart=on-failure
RestartSec=5
UMask=0027

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now blogsaas-web blogsaas-worker
systemctl status blogsaas-web blogsaas-worker --no-pager
sudo -u www-data /var/www/Blog-SaaS/.venv/bin/python manage.py check
curl -I http://127.0.0.1:8001/login
```

A 200 response confirms the login page is served; inspect service logs if not. Existing Laravel remains in maintenance behind Nginx until the next step.

## 8. Switch Nginx while preserving HTTPS

```bash
nano /etc/nginx/sites-available/blog-saas
```

In the existing HTTPS server block for blogsaas.task19.com, **keep its listen 443, ssl_certificate, ssl_certificate_key, and Certbot settings**. Keep the HTTP-to-HTTPS redirect server block. Remove the Laravel `location /`, exact `/index.php`, and PHP regex/FastCGI handlers from this virtual host. Replace them with:

```nginx
client_max_body_size 12M;

location /static/ {
    alias /var/www/Blog-SaaS/.local/static/;
    add_header X-Content-Type-Options nosniff always;
}
location /storage/ {
    alias /var/www/Blog-SaaS/.local/media/;
    add_header X-Content-Type-Options nosniff always;
}
location / {
    proxy_pass http://127.0.0.1:8001;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 100s;
}
location ~ /\. {
    deny all;
}
```

Do not put a PHP execution location alongside the media alias. Keep uploads non-executable. Save, then:

```bash
nginx -t && systemctl reload nginx
curl -I https://blogsaas.task19.com/login
journalctl -u blogsaas-web -u blogsaas-worker -n 60 --no-pager
```

Verify existing login, project/story counts, images, editor save, API publishing, and AI (after enabling its environment settings). Leave the old Laravel database and backups intact. Google OAuth remains unconfigured unless you arrange it separately. Do not stop PHP-FPM globally if other websites use it.

## Rollback before allowing new Django writes

Restore the saved Nginx site file from the backup, run nginx -t and reload. Run `php artisan up` using the preserved Laravel checkout/vendor/.env, restore its worker/scheduler services if previously stopped, and stop the two Django services. If Laravel needs its old source, check out the commit recorded in laravel-commit.txt only after preserving Django changes and resolving working-tree changes. Once Django accepts new writes, rollback requires reconciling those records; the old Laravel DB will not contain them.

## Google and sitemap features

The latest release includes editable article slugs, saved font sizes, and Analytics & Search with Search Console, GA4, and Sitemap tabs. Migration 0004 adds Analytics connections and is included by `manage.py migrate` above.

Google reports need OAuth settings and enabled Google APIs. Follow [google-analytics-search.md](google-analytics-search.md). Missing configuration disables Connect with an explanation; it does not block blog publishing. The sitemap API generates current XML on request; the customer must serve/proxy it on their website. Configure and verify its website URL and article route in the Sitemap tab.

## Later Django updates

After this initial migration, deploy using:

```bash
cd /var/www/Blog-SaaS
git status --short
git pull --ff-only origin main
umask 022
.venv/bin/pip install -r requirements.lock
.venv/bin/python manage.py migrate
.venv/bin/python manage.py collectstatic --noinput
systemctl restart blogsaas-web blogsaas-worker
systemctl status blogsaas-web blogsaas-worker --no-pager
curl -I https://blogsaas.task19.com/login
```

Do not run composer, npm, Laravel seeders, or the import again for routine Django updates.

## Recovery: MySQL error 1071 during the initial migration

Versions before the MySQL index fix attempted to index full 1,000-character URL paths. With utf8mb4 these exceed MySQL's 3,072-byte index limit. The corrected schema indexes SHA-256 hashes and keeps full paths. Migration 0005 also upgrades existing Django databases.

If portal.0001_initial failed, MySQL can leave partially created tables. Do not fake the migration or run import against this partial schema. Preserve it and create a fresh destination instead:

```bash
cd /var/www/Blog-SaaS
git pull --ff-only origin main
mysql
```

```sql
CREATE DATABASE blog_saas_django_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON blog_saas_django_v2.* TO 'blog_saas_django'@'localhost';
EXIT;
```

Change only DJANGO_DB_NAME to blog_saas_django_v2 in `.env.django`. Keep the existing working database username/password and stable Django secret. Then:

```bash
.venv/bin/python manage.py migrate
```

Proceed to the import only once every migration succeeds. This preserves the original Laravel database and the partial first Django database. If the v2 database already exists, inspect it rather than recreating or dropping it.
