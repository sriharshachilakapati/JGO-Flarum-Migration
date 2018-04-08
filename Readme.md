# JGO Flarum Migration

This repository hosts a script that will perform migration of the JGO from SMF to Flarum backend. The following are the instructions for this.

  1. Install Flarum on the server.

         composer create-project flarum/flarum . --stability=beta

  2. Once Flarum is installed, install the Guardian extension.

         composer require flagrow/flarum-ext-guardian

  3. Now install the pipetables extension to be able to show tables.

         composer require dogsports/flarum-ext-pipetables

  4. Once that is done, proceed with configuring the new JGO forum on Flarum. Make sure that you set the table prefix to `jgoforums` (Note there is no underscore).

  5. Setup the admin account as `adminjgo` (anything works which is not an existing user name). This is only used to log into flarum while migration is in progress.

  6. Create the source database for the migration script to use. Use the source command in the MySQL console to get the dump into the database. The latest public dump can be found at http://java-gaming.org/jgo-dump-20171116-shared.zip

         $ mysql -uuname -p
         Enter password: ******
         mysql> use dbname
         mysql> source path/to/dumpFile.sql

  7. Configure the script. Rename the `settings.sample.php` to `settings.php` and edit it to set the user names and passwords of the databases.

  8. Install the dependencies. This project depends on the s9e/TextFormatter library.

         $ composer install

  9. Run the migration script. This is a CLI based script, so run from a Terminal.

         $ php migrate.php

  10. Enjoy your migration. If there is any error, please report it in the issues along with the dump file you are using.
