<?php
/**
 * Gmail that SENDS the Time In / Time Out alerts and the Daily Report.
 * Leave MAIL_PASSWORD blank to keep email switched off - the rest of the site works normally.
 * To turn it on: Google Account > Security > 2-Step Verification (ON) > App passwords,
 * then paste the 16-letter App Password between the quotes on the MAIL_PASSWORD line.
 * IMPORTANT: never commit a real password to GitHub.
 */
define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_SECURE',     'tls');
define('MAIL_USERNAME',   'biotrack.gnci.cba@gmail.com');
define('MAIL_PASSWORD',   '');   // <-- paste the App Password here
define('MAIL_FROM_EMAIL', 'biotrack.gnci.cba@gmail.com');   // must match MAIL_USERNAME
define('MAIL_FROM_NAME',  'BioTrack - GNCI CBA');
define('MAIL_CRON_TOKEN', '');
