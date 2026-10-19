# IIIF Condition Reports

IIIF Condition Reports is a Symfony application for creating and managing condition reports for cultural heritage objects. Objects can be imported from a DataHub, linked to projects and documented through quick or detailed reports.

## Requirements

- PHP 8.2 or newer
- Composer
- MariaDB 10.6 or a compatible MySQL version
- PHP extensions `ctype`, `curl`, `gd`, `iconv` and `simplexml`

## Microsoft Entra SSO

See [docs/sso-entra.md](docs/sso-entra.md) for the Entra app registration, Ansible configuration and user-management procedure.
Set `LOCAL_LOGIN_ENABLED=0` to disable local login, password reset and local user creation.

For photo and document uploads up to 50 MB, the repository contains `public/.user.ini`. Verify the same `upload_max_filesize=50M` and `post_max_size=55M` limits in the web server configuration when `.user.ini` files are disabled.
Set `IMAGE_DOWNLOAD_CA_FILE=config/cacert.pem` in `.env` to use the included Mozilla CA bundle. Leave the value empty to let PHP/cURL use its system trust store.
`SESSION_LIFETIME` controls the session duration in seconds. Configure `MAILER_DSN`, `MAIL_FROM` and `FEEDBACK_EMAIL` to enable password-reset and feedback emails.

## Manual object creation

Manual object creation is enabled by default, including when the setting is absent.
Set `MANUAL_OBJECT_CREATION_ENABLED=0` in `.env` to hide the creation links and deny
both GET and POST requests to the creation page. Set it to `1` to enable it again.
Editing existing objects and importing from the Datahub remain available.
Ansible manages this flag through `condition_reports.manual_object_creation_enabled`
(boolean, default `true`).

## Datahub measurements

Harvest stores LIDO measurements on the object, grouped by `extentMeasurements`.
Structured `measurementType` / `measurementValue` / `measurementUnit` fields always
take precedence over `displayObjectMeasurements`, including zeroes and values
that appear incorrect. Repeated values use the first occurrence in document order.
Corrections belong in TMS; the importer does not compare plausibility or repair data.
Lengths are converted to cm (mm, cm, dm, m, inches and feet); weights to kg (g, kg).
When axis labels are absent, a pair/triple uses H × W (× D). Plain display dimensions
with an explicit unit fill missing axes only. Unknown units are not guessed.

New reports start with the latest imported measurements, assuming one part.
For paintings/paper, `Inclusief lijst` fills the framed measurements and `Volledig`
the unframed measurements. For paintings, the framed measurement also fills the
complete-work group. For sculptures/other objects, `Volledig` fills the complete
work and its single part. `Dagmaat` and `Onderdeel` remain separate source groups;
they are not substituted for complete-work measurements. Paper storage-box fields
are not filled from frame dimensions.

Current source values take precedence over copied measurements from a previous
report; other copied fields remain intact. Existing drafts and finalized reports
keep their stored measurements. No database migration is needed; objects receive
measurements on their next harvest. Offline regression: `tests/datahub_measurements.php`.

## Historical main images

On the first main-image annotation, the application stores an independent full-resolution
copy in `public/uploads/objects/`. Reports using that image share the copy. Canvas order,
annotation coordinates and individual stroke widths are preserved.
When the Datahub manifest URL or image-service URL changes, annotated reports keep their
original image in the editor, preview, PDF, zoom viewer and report list. New reports and
reports without active main-image annotations follow the current image. Annotations on
extra photos or frame sketches do not pin the main image.
Previous annotated images appear under **Vorige hoofdafbeeldingen** on the object page,
with the date the sync detected the change. This is not a restoration date.
Existing annotations without a copy are captured before the sync changes their source.
If that copy fails, the sync transaction stops without replacing the annotated image.
A stale editor page cannot save a drawing onto a different image; it asks for a reload.

The operator must apply migration `Version20261014120000` before using this version.
Keep `public/uploads/objects/` persistent and backed up together with the database.
Offline checks: `tests/main_image_history.php`, `tests/main_image_storage.php`,
`tests/report_image_manager.php` and `tests/report_image_viewer.js`.

For an annotation POST that returns HTTP 500, inspect **Network > Response** in the
browser after the operator deploys the updated `ReportAnnotationController.php` and
`ObjectImageStorage.php` and refreshes the application cache. Errors caught during
saving return `annotation_save_failed` with the failed stage, exception type, code,
filename and line. Database errors also include `sqlState`; download errors include
the cURL description or remote HTTP status when available. Exception messages, SQL,
URLs and trace arguments are excluded. Validation and stale-image responses keep
their existing status. Failures before the controller and fatal process termination
are outside this handler. No production action is performed by the diagnostic tests.

## Installation

1. Copy `.env.sample` to `.env` and configure the database, application URL and DataHub URL.
2. Install the PHP dependencies:

   ```bash
   composer install
   ```

3. Create and initialise the database:

   ```bash
   php bin/console doctrine:database:create --if-not-exists
   php bin/console doctrine:migrations:migrate --no-interaction
   ```

4. Create an administrator:

   ```bash
   php bin/console app:user:create admin@example.com "Administrator" "change-me" --admin
   ```

Configure the web server with `public/` as its document root. The application must be able to write to `public/uploads/objects/` and `public/uploads/reports/`; keep those directories persistent between deployments. Uploaded photos, IIIF images and external image URLs are stored locally with a generated thumbnail.

## Main commands

Daily TMS loan/exhibition import: see [setup, mapping and scheduling](docs/tms-sync.md).

```bash
php bin/console app:datahub:harvest
php bin/console app:datahub:list-object-types
php bin/console app:tms:sync
php bin/console app:user:create
php bin/console app:user:password
```

## License

This project is licensed under the GNU General Public License version 3. See [LICENSE](LICENSE).

`config/cacert.pem` is the [curl CA extract from Mozilla](https://curl.se/docs/caextract.html) and is distributed under MPL 2.0.
