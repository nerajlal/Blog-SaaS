# Product interface

The landing page remains the visual reference and its Blade/CSS/JavaScript files are unchanged. Authentication and the workspace now share its light background, Plus Jakarta Sans headings, Inter body text, blue-to-mint accents, dark pill buttons, fine borders and rounded white surfaces.

## Shared files

- `resources/css/product.css` contains the product tokens and styles, imported through `resources/css/app.css`. Styles are scoped to `.auth-page` and `.workspace-app`; they do not restyle the public landing page.
- `layouts/auth.blade.php` provides the shared login, registration and password recovery/reset layout. The editorial illustration is CSS/HTML, with no remote image dependency.
- `layouts/app.blade.php` provides the workspace header, account menu, notices, content area and footer.
- `partials/product-head.blade.php` and `partials/product-brand.blade.php` keep fonts, favicon and branding consistent.
- `partials/sidebar.blade.php` provides workspace switching/creation, contextual project navigation and sign-out. Context comes from the bound route project/post, never the last variable in a Blade card loop.

The dashboard displays database-backed project/story/published/draft totals for the accessible workspace and legacy personal projects. Scheduled stories are included in the total but not counted as already published. No sample metrics are shown outside isolated preview fixtures. Project pages show stories first, with API and integration settings below. Editor, SEO, link and reporting screens inherit the same shared controls and shell.

## Interaction and accessibility

Form labels, autocomplete, validation, CSRF tokens and route names are retained. Password visibility toggles are explicit. Mobile navigation closes on Escape/outside click and restores focus to its opener; Tab stays inside the open navigation. Account menus close on Escape/outside click. Keyboard focus indicators, skip links, reduced-motion support and local avatar initials are included. Tables scroll inside their containers instead of widening the page.

Autosave and manual update behavior are preserved. Selected image files still require Update Post. The redesign does not alter credentials, database schema, publication rules, Google connections or API contracts.

## Build and delivery

Build with `npm run build`, deploy the generated `public/build` assets with the source, and clear compiled views (`php artisan view:clear`, or rebuild them during deployment). No new packages or database migrations are required for the interface change. Local previews use isolated fixtures; the existing local database is not seeded or reset. A local source update does not deploy the DigitalOcean website automatically.
