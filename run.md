# Run BlogSaaS locally — Django

Use the `python` branch. The project directory contains a space, so keep the quotes in the commands below.

## Start now (this Mac is already set up)

Open a terminal and run:

```bash
cd "/Applications/XAMPP/xamppfiles/htdocs/Github/blog SaaS"
source .venv/bin/activate
python manage.py migrate
python manage.py runserver 127.0.0.1:8001
```

Open **http://127.0.0.1:8001** in your browser. Register an account if you have not created a Django account yet. Existing Laravel accounts are available only after an explicit data import.

Leave this terminal running. Press **Control + C** to stop the server.

## Background worker (second terminal)

AI generation, website analysis, link checks, and Search Console tasks need the worker. Open another terminal:

```bash
cd "/Applications/XAMPP/xamppfiles/htdocs/Github/blog SaaS"
source .venv/bin/activate
python manage.py work
```

Leave both terminals running while using background features. Press **Control + C** in each terminal to stop them.

## First-time setup on a new checkout

Use Python 3.12 or newer. Run these commands once:

```bash
cd "/Applications/XAMPP/xamppfiles/htdocs/Github/blog SaaS"
git switch python
python3 -m venv .venv
source .venv/bin/activate
python -m pip install -r requirements.lock

# Create Django settings only if they do not already exist.
if [ ! -f .env.django ]; then
    cp .env.django.example .env.django
fi

python manage.py migrate
python manage.py runserver 127.0.0.1:8001
```

On another machine, replace the `cd` path with that checkout's directory. If Git reports unsaved changes when switching branches, review them before proceeding; do not discard them.

## Enable Claude (optional)

The normal blog editor works without API credentials. To enable AI, edit the private Django configuration:

```bash
cd "/Applications/XAMPP/xamppfiles/htdocs/Github/blog SaaS"
nano .env.django
```

Set these values using your own Anthropic account:

```dotenv
AI_ENABLED=true
ANTHROPIC_API_KEY=your_api_key
CLAUDE_MODEL=your_available_model_id
```

Save the file, then restart both the server and worker. In the app, open **Project details**, save and approve the business profile, and enable the workspace AI allowance. Generation sends the selected context to Anthropic and incurs API usage charges. Keep the API key out of Git and chat.

## After pulling updates to the Python branch

Stop the server and worker, then run:

```bash
cd "/Applications/XAMPP/xamppfiles/htdocs/Github/blog SaaS"
source .venv/bin/activate
python -m pip install -r requirements.lock
python manage.py migrate
python manage.py check
python manage.py runserver 127.0.0.1:8001
```

Start the worker again in the second terminal using the commands above.

## Useful checks

```bash
cd "/Applications/XAMPP/xamppfiles/htdocs/Github/blog SaaS"
source .venv/bin/activate

# Check application configuration.
python manage.py check

# Run isolated Django tests.
python manage.py test portal

# Check functional-area routes.
python manage.py check_parity

# Process one background task, then exit.
python manage.py work --once

# Optional operator account for /django-admin/.
python manage.py createsuperuser
```

## Local data and common issues

- **Port 8001 is busy:** stop the previous Django server with Control + C, or use `python manage.py runserver 127.0.0.1:8002` and open http://127.0.0.1:8002. If using 8002 regularly, update `DJANGO_PUBLIC_URL` in `.env.django` too.
- **Task stays pending:** confirm the worker is running in the second terminal.
- **Claude is disabled:** configure the API key/model, restart both processes, enable the workspace allowance, and approve project details.
- **Missing database tables:** run `python manage.py migrate` with the virtual environment active.
- Django uses `.env.django` and `.local/db.sqlite3` by default. It does not use Laravel's `.env` or its MySQL database.
- Keep `.local/secret-key` stable; existing encrypted local data depends on it unless a separate encryption key was configured.
- PHP, Composer, npm, and XAMPP services are not required to run this Django version.
- These commands are for local development. For the DigitalOcean server, use [Django migration and deployment](docs/django-migration.md).
