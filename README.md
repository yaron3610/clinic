# Clinic – new-patient intake for shovalclinic.co.il

A WordPress plugin (`shoval-intake`) that adds a digital intake questionnaire to the clinic's
Elementor site, without changing the site's design:

- a page **"שאלון קבלה למטופל חדש"** at `/intake-form/` (Hebrew, RTL, mobile friendly);
- two buttons on the homepage that lead to it (added or removed with one click in the settings);
- for every submission: a private record for administrators, a **draft clinical summary in Word**
  (the clinic's letterhead format) and an RTL e-mail to the clinic with the draft and the uploaded files.

Installation steps (Hebrew): **[IMPORT-BACK-GUIDE.md](IMPORT-BACK-GUIDE.md)**.
Since 1.1.0 the installable `shoval-intake.zip` is built locally and not attached to [releases](../../releases)
(it contains the clinic stamp, which is kept out of this public repository).

## Configuration

**WP Admin → שאלוני קבלה → הגדרות**: report e-mails (one or more, separated by commas), the Dx. and Rx.
lists of the letter, the Gmail address and app password, and an optional Brevo key and sender (backup). These can instead be defined in `wp-config.php` (they then take
precedence and are not stored in the database):

```php
define( 'SHOVAL_INTAKE_GMAIL_USER', 'clinic@gmail.com' );
define( 'SHOVAL_INTAKE_GMAIL_APP_PASSWORD', '...' );
define( 'SHOVAL_INTAKE_BREVO_API_KEY', '...' );
define( 'SHOVAL_INTAKE_BREVO_FROM', 'Clinic <clinic@example.com>' );
define( 'SHOVAL_INTAKE_REPORT_EMAIL', 'reports@example.com, doctor@example.com' );
```

No keys are stored in this repository. Never commit them.

## Privacy and safety

- Submissions are a private post type visible only to administrators; files are stored in
  `wp-content/uploads/shoval-intake-private/` behind `.htaccess` and are downloadable only by
  administrators. Submissions are deleted after a configurable retention period (default 90 days).
- No questionnaire data is sent to AI services. The letter is the clinic's fixed template: every line of
  the Dx. and Rx. lists, and the clinic's standard background, mental-status examination, impression and
  treatment plan in the patient's gender. Name, age, city, parents, family position and medications come
  from the questionnaire; the letter date leaves the day blank. The e-mail is marked as a draft for the physician.
- The letterhead and stamp images (`shoval-intake/assets/letter/`) are read from disk only; the folder is
  closed to the web by `.htaccess`. The stamp image (`stamp.png`) is not in this public repository – it is
  part of the plugin zip only.
- Spam protection: signed form token, honeypot, 5 submissions per hour per IP. Consent is required.
- E-mail: one message per report address, through Gmail SMTP (app password, sent by Google's own servers), then Brevo, then the site's `wp_mail()`.

## Structure

| Path | Contents |
|---|---|
| `shoval-intake/shoval-intake.php` | Plugin bootstrap |
| `shoval-intake/includes/` | REST endpoint, storage, letter content, Word generator, e-mail, admin, settings, homepage buttons |
| `shoval-intake/templates/` | Questionnaire page and form |
| `shoval-intake/assets/` | CSS, JS, logo; `letter/` – letterhead, footer icons and stamp of the Word letter |
| `local-server/` | `router.php` and `start-local.cmd` for running a local copy with PHP's built-in server |

## Local development

The local copy needs, next to this repository (not committed):
`site/` – a WordPress install restored from a site backup, with `shoval-intake` linked into
`site/wp-content/plugins/`; `local-server/php/` – PHP 8.1 for Windows; `local-server/mariadb/` and
`local-server/mariadb-data/` – MariaDB 10.11 on port 3307. Then run `local-server\start-local.cmd`
and open http://localhost:8080.

Requirements on the live host: WordPress 6.0+, PHP 7.4+ (tested on 8.1), Elementor for the homepage buttons.
