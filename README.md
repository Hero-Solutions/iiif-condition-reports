# IIIF Condition Reports

Demo: https://conditierapporten.vlaamsekunstcollectie.be/

## Project overview

This tool is aimed toward the creation and management of condition reports using IIIF in cultural heritage institutions, such as museums. It relies on a [Datahub](https://github.com/thedatahub/Datahub) as its source for information about objects for which the reports are to be created.


## Requirements

* php-imagick

The tables as defined in `condition_report_tables.sql` have to be present in MySQL.

An admin user in the database with a BCrypt password, which you can generate as follows:
From the command line (replacing 'asupersafepassword' with a password of your choice):
```
php -r "echo password_hash('asupersafepassword', PASSWORD_BCRYPT, ['cost' => 13]) . PHP_EOL;"
```
In MySQL (replacing 'admin@example.com' with the admin e-mail, 'Firstname Lastname' with the full name of the admin user and 'bcrypt_hash' with the output from the previous PHP command):
```
INSERT INTO user(email, full_name, roles, password) VALUES('admin@example.com', 'Firstname Lastname', '["ROLE_USER","ROLE_ADMIN"]', 'bcrypt_hash');
```


## Architecture concept

![Alt text](architectuurConditierapporten.png?raw=true)
