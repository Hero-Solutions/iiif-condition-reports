# IIIF Condition Reports

IIIF Condition Reports is a Symfony application for creating and managing condition reports for cultural heritage objects. Objects can be imported from a DataHub, linked to projects and documented through quick or detailed reports.

## Requirements

- PHP 8.4 or newer
- Composer
- MariaDB 10.6 or a compatible MySQL version
- PHP extensions `ctype`, `iconv` and `simplexml`

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

Configure the web server with `public/` as its document root.

## Main commands

```bash
php bin/console app:datahub:harvest
php bin/console app:datahub:list-object-types
php bin/console app:user:create
```

## License

This project is licensed under the GNU General Public License version 3. See [LICENSE](LICENSE).
