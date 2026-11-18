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
`authorization`, `cookie`, `card_number`, `cvc`, `cvv`, in any case and at
any depth -- are written as `[redacted]`. Pass your own list as `redact`, or
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
$log->writes( 'debug' );   // whether a debug entry would be written
$log->set_enabled( false );
```

Past `max_size` the log moves to `{file}.1`, replacing the one before, so
there are never more than two.

## Moving from arraypress/wp-logger

| Was | Now |
|---|---|
| `ArrayPress\Logger\Logger` | `SugarCommerce\Logger\Logger` |
| `ArrayPress\Logger\Registry::get_instance()->register( $name, $options )` | `Registry::register( $name, $options )` |
| `Registry::get_instance()->get()`, `has()`, `remove()` | `Registry::get()`, `has()`, `remove()` |
| `Registry::get_instance()->get_all()`, `get_names()`, `count()` | `Registry::all()`, `names()`, `count()` |
| `Registry::get_instance()->clear()`, `Registry::reset()` | `Registry::reset()` |
| `register_logger( $name, $options )` | `Registry::register( $name, $options )` |
| `get_logger( $name )` | `Registry::get( $name )` |
| `has_logger( $name )` | `Registry::has( $name )` |
| `remove_logger( $name )` | `Registry::remove( $name )` |
| `get_all_loggers()` | `Registry::all()` |

The global functions are gone: a global function is the one thing Strauss
cannot prefix, so two plugins bundling different copies shared whichever
loaded first.

Answers that changed:

* Context keys named like secrets are written as `[redacted]`. Pass `'redact' => array()` for the old behaviour.
* A new line in a message is written as `\n`; it started a new line, which read as a new entry.
* Context that is not valid UTF-8 is written with the bad bytes replaced. It was written as nothing at all.
* The folder, `.htaccess` and `index.php` are made on the first write, not when the logger is built, so a logger that never writes leaves nothing behind. The `.htaccess` says `Require all denied` for Apache 2.4 as well as `Deny from all` for 2.2.
* A log file name without a path is passed through `sanitize_file_name()`.

New: the `level` and `redact` options, `get_entries()`, `get_name()`,
`get_level()`, `set_enabled()` and `writes()`.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
