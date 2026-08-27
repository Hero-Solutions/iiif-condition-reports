# IIIF Condition Reports

IIIF Condition Reports is a Symfony application for creating and managing condition reports for cultural heritage objects. Objects can be imported from a DataHub, linked to projects and documented through quick or detailed reports.

## Requirements

- PHP 8.2 or newer
- Composer
- MariaDB 10.6 or a compatible MySQL version
- PHP extensions `ctype`, `curl`, `gd`, `iconv` and `simplexml`

For photo and document uploads up to 50 MB, the repository contains `public/.user.ini`. Verify the same `upload_max_filesize=50M` and `post_max_size=55M` limits in the web server configuration when `.user.ini` files are disabled.
Set `IMAGE_DOWNLOAD_CA_FILE=config/cacert.pem` in `.env` to use the included Mozilla CA bundle. Leave the value empty to let PHP/cURL use its system trust store.
`SESSION_LIFETIME` controls the session duration in seconds. Configure `MAILER_DSN`, `MAIL_FROM` and `FEEDBACK_EMAIL` to enable password-reset and feedback emails.

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

```bash
php bin/console app:datahub:harvest
php bin/console app:datahub:list-object-types
php bin/console app:user:create
php bin/console app:user:password
```

## License

This project is licensed under the GNU General Public License version 3. See [LICENSE](LICENSE).

`config/cacert.pem` is the [curl CA extract from Mozilla](https://curl.se/docs/caextract.html) and is distributed under MPL 2.0.
