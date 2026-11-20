# Logger

A log file for each WordPress plugin, at a path nobody can guess.

## What it does

`error_log()` puts everything from every plugin in one file, which is useless
the moment two things misbehave. The usual fix is a log of the plugin's own
in uploads -- but a predictable path is a public URL, and that file holds
customer emails, IP addresses and gateway responses.

So the file is named with a hash of the plugin's name salted with the site's
own keys, the way WooCommerce names its logs, and cannot be guessed from
outside. That matters because the `.htaccess` written beside it does nothing
on nginx.

## Features

* One log per plugin, at a path that cannot be guessed
* Levels -- error, warning, info, debug -- and a quietest level to write
* Log a `Throwable` with its file, line and trace, or a `WP_Error` with its code and data
* Attach context to any entry, written as JSON, with passwords, tokens and card numbers left out
* One entry per line, whatever a customer typed into the message
* Rotate at a size, keeping one previous file, so a forgotten debug flag cannot fill the disk
* Off unless `WP_DEBUG` or the plugin's own `{NAME}_DEBUG` constant is on
* Reach the same logger from anywhere in the plugin by its name

## Installation

```bash
composer require sugarcommerce/logger
```

## Quick start

```php
use SugarCommerce\Logger\Registry;

// Once, while the plugin loads.
Registry::register( 'myshop' );

// Anywhere after that.
$log = Registry::get( 'myshop' );

$log->info( 'Order completed', array( 'order_id' => 1001, 'total' => 4999 ) );

try {
	$gateway->charge( $order );
} catch ( GatewayException $e ) {
	$log->exception( $e, array( 'order_id' => $order->id ) );
}

$response = wp_remote_post( $url, $args );

if ( is_wp_error( $response ) ) {
	$log->wp_error( $response );
}
```

Nothing is written unless `WP_DEBUG` is on or `MYSHOP_DEBUG` is defined
true -- and nothing is made on disk until the first entry is.

## Entries

```
[2026-11-18T10:00:00+00:00] INFO: Order completed {"order_id":1001,"total":4999}
[2026-11-18T10:00:02+00:00] ERROR: [RuntimeException] Gateway down {"order_id":1001,"file":"…","line":42,"trace":"…"}
```

The time is the site's, from `current_time( 'c' )`. `log( $message,
$context, 'NOTICE' )` writes a level of your own, filtered as info. A new
line in a message is written as `\n`, so a name or an address somebody typed
cannot forge an entry of its own.

Context keys named like secrets -- `password`, `secret`, `token`, `api_key`,
`private_key`, `authorization`, `cookie`, `card_number`, `cvc`, `cvv`, in any
case and at any depth -- are written as `[redacted]`. Each is matched as a
word of the key, so `client_secret`, `access_token`, `stripe_secret_key` and
`webhookSecret` are redacted too, while `passenger` is not. A value that is a
Stripe secret or restricted key or a webhook signing secret is redacted
whatever its key, and in the message itself. A plain object in the context
(a decoded answer, a row) is redacted by its properties as an array is. Pass your own list as `redact` (matched the same way), or
an empty one to keep everything.

## Options

```php
Registry::register( 'myshop', array(
	'enabled'  => true,          // default: MYSHOP_DEBUG, else WP_DEBUG
	'level'    => 'warning',     // the quietest level written; default debug
	'log_file' => 'payments.log',// a name in uploads/myshop/, or a whole path
	'max_size' => 1048576,       // bytes before it rotates; 0 never; default 5 MB
	'redact'   => array( 'iban', 'password' ),
) );
```

The options count the first time a name is registered; after that
`register()` hands back the same logger.

## Reading it back

```php
$log->get_entries( 50 );   // the last 50 lines
$log->get_contents();      // the whole file
$log->get_file();          // where it is
$log->clear();             // the file and its previous one
$log->prune( 30 );         // entries 30 days old or more, from both; returns how many
$log->writes( 'debug' );   // whether a debug entry would be written
$log->set_enabled( false );
```

Past `max_size` the log moves to `{file}.1`, replacing the one before, so
there are never more than two. Rotation keeps the size down; `prune()` keeps
the age down -- run it from a daily event for a log that holds what customers
typed. A line that does not start with a time is kept, and a previous file
left empty is removed.

## Security

* Secrets in the log: secret-named context keys at any depth, the
  properties of plain objects, and Stripe or webhook secrets anywhere in a
  value or in the message are written as `[redacted]`.
* Forged entries: a new line in a message is written as `\n`, so text a
  customer typed cannot start an entry of its own.
* A readable log: it lives in uploads under a name salted per site
  (`wp_hash()`), beside an `.htaccess` that denies Apache 2.2 and 2.4 and an
  empty `index.php`.
* A log that grows for ever: it rotates past `max_size`, and `prune()` drops
  entries past an age.

## Layout

* `src/Logger.php`: one log and its levels; `src/Traits/Logger/Files.php` is
  the file (path, guard files, rotation, pruning, reading back) and
  `Redaction.php` what is kept out of it.
* `src/Registry.php`: one logger a name.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
