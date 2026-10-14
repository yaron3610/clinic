# Clinic – new-patient intake for shovalclinic.co.il

A WordPress plugin (`shoval-intake`) that adds a digital intake questionnaire to the clinic's
Elementor site, without changing the site's design:

- a page **"שאלון קבלה למטופל חדש"** at `/intake-form/` (Hebrew, RTL, mobile friendly);
- two buttons on the homepage that lead to it (added or removed with one click in the settings);
- for every submission: a private record for administrators, a **draft clinical summary in Word**
  (the clinic's letterhead format) and an RTL e-mail to the clinic with the draft and the uploaded files.

Installation steps (Hebrew): **[IMPORT-BACK-GUIDE.md](IMPORT-BACK-GUIDE.md)**.
The installable plugin is attached to the latest [release](../../releases) as `shoval-intake.zip`.

## Configuration

**WP Admin → שאלוני קבלה → הגדרות**: report e-mail, Google Gemini key, Resend key and sender.
Keys can instead be defined in `wp-config.php` (they then take precedence and are not stored in the database):

```php
define( 'SHOVAL_INTAKE_GEMINI_API_KEY', '...' );
define( 'SHOVAL_INTAKE_RESEND_API_KEY', '...' );
define( 'SHOVAL_INTAKE_RESEND_FROM', 'Clinic <no-reply@example.com>' );
define( 'SHOVAL_INTAKE_REPORT_EMAIL', 'reports@example.com' );
```

No keys are stored in this repository. Never commit them.

## Privacy and safety

- Submissions are a private post type visible only to administrators; files are stored in
  `wp-content/uploads/shoval-intake-private/` behind `.htaccess` and are downloadable only by
  administrators. Submissions are deleted after a configurable retention period (default 90 days).
- Gemini receives clinical fields only – never name, ID number, phone, e-mail, city or parents' names.
- Every AI-suggested diagnosis must quote the questionnaire verbatim; unsupported diagnoses are dropped.
  Without AI, a keyword rule set (whole-word Hebrew matching with negation) is used.
- The draft never invents findings: mental-status examination and developmental history are left as
  blank lines for the physician, and the e-mail is marked as a draft.
- Spam protection: signed form token, honeypot, 5 submissions per hour per IP. Consent is required.
- E-mail goes through Resend, falling back to the site's `wp_mail()`.

## Structure

| Path | Contents |
|---|---|
| `shoval-intake/shoval-intake.php` | Plugin bootstrap |
| `shoval-intake/includes/` | REST endpoint, storage, AI and rules, Word generator, e-mail, admin, settings, homepage buttons |
| `shoval-intake/templates/` | Questionnaire page and form |
| `shoval-intake/assets/` | CSS, JS, logo and stamp images |
| `local-server/` | `router.php` and `start-local.cmd` for running a local copy with PHP's built-in server |

## Local development

The local copy needs, next to this repository (not committed):
`site/` – a WordPress install restored from a site backup, with `shoval-intake` linked into
`site/wp-content/plugins/`; `local-server/php/` – PHP 8.1 for Windows; `local-server/mariadb/` and
`local-server/mariadb-data/` – MariaDB 10.11 on port 3307. Then run `local-server\start-local.cmd`
and open http://localhost:8080.

Requirements on the live host: WordPress 6.0+, PHP 7.4+ (tested on 8.1), Elementor for the homepage buttons.
