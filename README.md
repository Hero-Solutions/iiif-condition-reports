# IIIF Condition Reports

Demo: https://conditierapporten.vlaamsekunstcollectie.be/

## Project overview

This tool is aimed toward the creation and management of condition reports using IIIF in cultural heritage institutions, such as museums. It relies on a [Datahub](https://github.com/thedatahub/Datahub) as its source for information about objects for which the reports are to be created.


## Requirements

* A MySQL database with a user and password who has full access to the database associated with this tool
* php-imagick

## Installation

Run the file `condition_report_tables.sql`once against your MySQL database.

An admin user needs to be present in the database with a BCrypt password, which you can generate from the command line as follows (replacing 'asupersafepassword' with a password of your choice):
```
php -r "echo password_hash('asupersafepassword', PASSWORD_BCRYPT, ['cost' => 13]) . PHP_EOL;"
```
In MySQL, run the following query, replacing 'admin@example.com' with the admin e-mail, 'Firstname Lastname' with the full name of the admin user and 'bcrypt_hash' with the output from the previous PHP command:
```
INSERT INTO user(email, full_name, roles, password) VALUES('admin@example.com', 'Firstname Lastname', '["ROLE_USER","ROLE_ADMIN"]', 'bcrypt_hash');
```

Copy '.env.sample' to '.env', replacing the following values in the DATABASE_URL variable:
* 'user' with the username of the MySQL user
* 'password' with the password of the MySQL user
* '127.0.0.1' with the IP of the database if the database does not run locally
* 'db_name' with the name of the database

Copy 'config/condition-reports.yaml.sample' to 'config/condition-reports.yaml', setting or replacing the following values in the config file:
* lookup_source: set this to 'omeka' if you wish to use Omeka-S as data source instead of the Datahub
* omeka_api: set 'url' to your Omeka-S URL, 'key_identity' and 'key_credential' to your API key identity and credential in Omeka-S and 'inventory_number_property_id' with the property ID of the inventory number in your Omeka-S installation.
* service_url: set this to the URL where your application runs, for example conditionreports.example.com/iiif/3/

After everything is set up, either configure your Nginx or Apache webserver or run the builtin webserver with the following command (from the folder where the application is installed):
```
symfony server:start
```

## Architecture concept

![Alt text](architectuurConditierapporten.png?raw=true)
