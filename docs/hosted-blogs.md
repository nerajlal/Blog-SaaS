# Hosted blogs

In a project, open **Connect with your site → Set up hosted blog**.
Enable hosting to publish a listing and post pages at the shared URL shown there.
Only published posts and scheduled posts whose publication time has arrived are public.
Disabling hosting makes both the shared URL and custom domain return 404. It does
not delete posts. The API integration remains available independently.

The application serves sanitized post content, featured images, author details,
SEO metadata, canonical URLs, paginated listings and an XML sitemap. Drafts stay
private. The sitemap omits noindex posts and posts canonicalized elsewhere.

## Deploy the application

After committing and pushing the changes:

```bash
cd /var/www/Blog-SaaS
umask 022
git pull --ff-only origin main
.venv/bin/pip install -r requirements.lock
.venv/bin/python manage.py migrate
.venv/bin/python manage.py collectstatic --noinput
.venv/bin/python manage.py check
chown -R www-data:www-data .local/static
systemctl restart blogsaas-web blogsaas-worker
```

Ensure `DJANGO_PUBLIC_URL` in `.env.django` is your canonical platform URL,
currently `https://blogsaas.task19.com`. Keep `DJANGO_ALLOWED_HOSTS` explicit;
do not use `*`. Hosting is disabled for every project until enabled by a manager.

## Customer subdomain

1. Enter an owned subdomain, for example `blog.example.com`, and save.
2. Point its A record to the application server's public IPv4. Do not add AAAA
   unless IPv6 is configured on that server. No main-site DNS change is needed.
3. Add the TXT record shown in the setup screen and click **Verify DNS ownership**.
4. An operator provisions nginx and HTTPS as below. This is intentionally an
   operator action: the web process does not get root access or run Certbot.

For each domain, replace `blog.example.com` below with the verified hostname.
Add that exact hostname to `DJANGO_ALLOWED_HOSTS` in `.env.django`, preserving
existing entries, and restart the web service.

Create `/etc/nginx/sites-available/trywryter-blog-example`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name blog.example.com;
    client_max_body_size 12M;

    location /static/ {
        alias /var/www/Blog-SaaS/.local/static/;
        add_header X-Content-Type-Options nosniff always;
    }
    location /storage/ {
        alias /var/www/Blog-SaaS/.local/media/;
        add_header X-Content-Type-Options nosniff always;
    }
    location ~ /\. { deny all; }
    location = /.well-known/trywryter-hosted {
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    location / {
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Enable the configuration, validate it, provision TLS, then activate:

```bash
ln -s /etc/nginx/sites-available/trywryter-blog-example /etc/nginx/sites-enabled/trywryter-blog-example
nginx -t && systemctl reload nginx
certbot --nginx -d blog.example.com --redirect
cd /var/www/Blog-SaaS
.venv/bin/python manage.py activate_hosted_domain blog.example.com
curl -I https://blog.example.com/
curl -I https://blog.example.com/sitemap.xml
```

The activation command checks an HTTPS challenge with normal certificate
validation and no redirects. Activation fails unless traffic reaches the correct
verified project on this server. Custom domains serve public blog routes only;
login, admin and API routes are unavailable on them. Staff keep using the platform
hostname. Cookies remain host-scoped; do not configure a cross-domain session
cookie domain.

The shared URL continues serving content with the custom URL as its canonical
once activated. Changing the custom subdomain clears verification and activation.
Remove obsolete nginx configurations/certificates and allowed-host entries when
retiring a domain, so an abandoned DNS record cannot keep serving an old site.

For setup assistance: trywryter@gmail.com.
