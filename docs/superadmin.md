# Platform superadmin

Workspace owners/admins manage their own workspace; they are not platform superadmins. Django superusers can access the whole platform. Give this role only to trusted operators.

## Create an account

On the server, after pulling the code, run:

```bash
cd /var/www/Blog-SaaS
.venv/bin/python manage.py create_superadmin --email admin@gmail.com
```

Enter a strong password privately at the two prompts. Do not use the literal word `password`. The command validates password strength and refuses to silently promote or reset an existing account. No password is stored in Git or accepted as a command-line argument.

Log in at `/login`; superadmins go directly to `/superadmin`. Opening `/` or `/admin/projects` while signed in as superadmin also redirects there. Their separate sidebar contains Overview, Clients, Workspaces & allowances, AI usage & requests, Account management, and AI access controls. It has no workspace picker or project creation flow. Client accounts retain their own navigation. The Django admin at `/django-admin/` provides account, workspace, and content management, with a link to the usage dashboard. To reset an existing superadmin password use `manage.py changepassword admin@gmail.com` on the server.

Local development credentials, if created by the assistant, are stored privately in ignored `.local/superadmin-credentials.txt`. They do not create an account on the server. Create the server account separately and use a different password.

## Dashboard

- Counts of clients, workspaces, projects, and blogs.
- Month (UTC) and all-time views; search by requester email, workspace, or project.
- Per-client requests and recorded input/output tokens, plus cache-write/cache-read tokens.
- Workspace totals, current effective monthly request allowances, and monthly reservation ledger counts.
- Paginated request history with actor, workspace/project, source/output article ID, action/platform, model, status, and recorded token usage.
- CSV export of the filtered request history, including a flag for whether token usage was recorded.

Client totals refer to the user who requested a generation. Workspace totals include teammates. Outline requests are included even if they have no saved article. Legacy projects without workspaces still appear in request history/client totals. Reservation counts are per workspace and month, not provider billing or token counts. Allowance settings reflect current configuration, not historical configuration.

Input and output tokens are separate; thinking tokens are already included in output and are not added twice. Cache tokens are shown separately. Failed/cancelled calls may have usage. Missing usage is marked unknown, not free. Dollar charges are not available: reconcile actual spending against provider billing. Reports reflect retained AI request records, not a permanent billing ledger.

This report does not load encrypted prompt/result fields or show client API keys/provider tokens. Client account management elsewhere in Django admin remains highly privileged. Dashboard and export require an active superuser; ordinary clients, workspace owners/admins, and staff-only accounts are denied. Responses are marked private/no-store. All access is read-only on the usage dashboard.

## Deploy

No new schema migration is needed specifically for this dashboard. Apply outstanding migrations normally, collect static files, restart Django, then create the superadmin account privately. Do not import Laravel data again.

## Separate platform pages

- `/superadmin`: overview and usage totals.
- `/superadmin/clients`: client accounts and per-client usage.
- `/superadmin/workspaces`: workspace usage and allowances.
- `/superadmin/usage`: filters, token totals, and AI request history.
- `/django-admin/portal/user/`: account management.
- `/django-admin/portal/aisettings/`: AI access and allowance controls.

Each sidebar entry opens its own page. Client/workspace names open filtered AI history.
