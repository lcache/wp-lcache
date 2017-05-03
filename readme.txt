=== WP LCache ===
Contributors: getpantheon, danielbachhuber, stevector
Tags: cache, plugin
Requires at least: 4.3
Tested up to: 4.7.4
Stable tag: 0.5.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Supercharge your WP Object Cache with LCache, a persistent, performant, and multi-layer cache library.

== Description ==

[![Travis CI](https://travis-ci.org/lcache/wp-lcache.svg?branch=master)](https://travis-ci.org/lcache/wp-lcache) [![CircleCI](https://circleci.com/gh/lcache/wp-lcache/tree/master.svg?style=svg)](https://circleci.com/gh/lcache/wp-lcache/tree/master)

For sites concerned with high traffic, speed for logged-in users, or dynamic pageloads, a high-speed and persistent object cache is a must. WP LCache improves upon Memcached and Redis object cache implementations by using APCu, PHP's in-memory cache, in a way that's compatible with multiple web nodes. Under the hood, WP LCache uses [LCache](https://github.com/lcache/lcache), a library that applies the tiered caching model of multi-core processors (with local L1 and central L2 caches) to web applications.

WP LCache is faster than other object cache implementations because:

* By using APCu, which is in-memory, WP LCache uses the fastest possible persistent object cache backend and avoids costly network connections on every request. When using a Memcached or Redis-based persistent object cache where Memcached or Redis is on a different machine, the millisecond cost of each cache hit can add up to seconds of network transactions on every request.
* By incorporating a common L2 cache, WP LCache synchronizes cache data between multiple web nodes. Cache updates or deletes on one node are then applied to all other nodes. Without this synchronization behavior, APCu can't be used in server configurations with multiple web nodes because the cache pool is local to the machine.

Still not convinced? WP LCache includes features that no one else has:

* Cache groups are handled natively, meaning you can delete an entire group of keys with `wp_cache_delete_group()`.
* WordPress' alloptions cache is sharded into distinct keys, mitigating cache pollution on high traffic sites. [Read #31245](https://core.trac.wordpress.org/ticket/31245) for all of the gory details.

Read the installation instructions, then install WP LCache from [WordPress.org](https://wordpress.org/plugins/wp-lcache/) or [Github](https://github.com/lcache/wp-lcache).

Go forth and make awesome! And, once you've built something great, [send us feature requests (or bug reports)](https://github.com/lcache/wp-lcache/issues).

== Frequently Asked Questions ==

= Do you have benchmarks you can share? =

We've done some rudimentary testing with New Relic on Pantheon infrastructure. [The results](https://twitter.com/outlandishjosh/status/775756511611990016) were substantial enough for us to begin using LCache in production. [Watch David Strauss' DrupalCon presentation](https://twitter.com/outlandishjosh/status/781281995213115396) for a more thorough explanation.

If you'd like to do some benchmarking yourself, we'd love to hear about your testing methodology and conclusions. Caching is more of an art than a science, and outcomes can vary. Because cost of network transactions is one of the problems solved by WP LCache, the performance gains will be more impressive if you've historically been using Redis or Memcached on a separate machine.

= Is APCu persistent like Redis is? =

APCu is persistent through the life of a PHP-FPM process. However, unlike Redis, APCu doesn't save its state to disk at shutdown. When PHP-FPM is restarted, WP LCache will repopulate the L1 cache (APCu) from the L2 cache (database).

== Installation ==

**WP LCache requires PHP 5.6 or greater with the APCu extension enabled.**

To install WP LCache, follow these steps:

1. Install the plugin from WordPress.org using the WordPress dashboard.
1a. Those installing from Github will need to run `composer install --no-dev --no-scripts` after cloning to get the [LCache library](https://github.com/lcache/lcache).
2. Activate the plugin, to ensure LCache's database tables are created. These are created on the plugin activation hook.
3. Create a stub file at `wp-content/object-cache.php` to require `wp-content/plugins/wp-lcache/object-cache.php`.

The `wp-content/object-cache.php` file should contain:

    <?php
    # Engage LCache object caching system.
    # We use a 'require_once()' here because in PHP 5.5+ changes to symlinks
    # are not detected by the opcode cache, making it frustrating to deploy.
    #
    # More info: http://codinghobo.com/opcache-and-symlink-based-deployments/
    #
    $lcache_path = dirname( realpath( __FILE__ ) ) . '/plugins/wp-lcache/object-cache.php';
    require_once( $lcache_path );

To install WP LCache in one line with WP-CLI:

    wp plugin install wp-lcache --activate && wp lcache enable

If you need to install APCu, the PECL installer is the easiest way to do so.

* PHP 7.0: `pecl install apcu`
* PHP 5.6: `pecl install channel://pecl.php.net/apcu-4.0.11`

Enabling APCu for CLI is a matter of adding `apc.enable_cli='on'` to your `etc/php5/cli/php.ini`.

If you can't easily use PHP 5.6 or greater, you should switch to a more responsible hosting provider.

= Admin Notices =

If any of the requirements for LCache to function are not met, you will see an admin notice indicating the issue. Here's how to resolve issues for each possible dependency:

* "LCache database table": This indicates you have the `object-cache.php` symlink in place, but have not activated the plugin (which installs the LCache db table). Activate the plugin and verify the LCache tables are created.
* "PHP 5.6 or greater": You need to update your PHP runtime, which will also make your site faster and more secure. Do it today, or contact your hosting provider if you don't have access.
* "APCu extension installed/enabled": You don't have the required PHP extension to power LCache. See above instructions for installing APCU, or contact your hosting provider.
* "LCache library": you're probably installing direct from GitHub, not a download from the WordPress.org plugins directory. Awesome! You just need  to run `composer install --no-dev` inside the `wp-lcache` directory, and make sure the resulting `vendor` directory is deployed along with the rest of `wp-lcache`.

== Contributing ==

The best way to contribute to the development of this plugin is by participating on the GitHub project:

https://github.com/lcache/wp-lcache

Pull requests and issues are welcome!

You may notice there are two sets of tests running, on two different services:

* Travis CI runs the [PHPUnit](https://phpunit.de/) test suite in a variety of environment configurations (e.g. APCu available vs. APCu unavailable).
* Circle CI runs the [Behat](http://behat.org/) test suite against a Pantheon site, to ensure the plugin's compatibility with the Pantheon platform.

Both of these test suites can be run locally, with a varying amount of setup.

PHPUnit requires the [WordPress PHPUnit test suite](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/), and access to a database with name `wordpress_test`. If you haven't already configured the test suite locally, you can run `bash bin/install-wp-tests.sh wordpress_test root '' localhost`. You'll also need to install and configure APCu in order to run the test suite against APCu.

Behat requires a Pantheon site. Once you've created the site, you'll need [install Terminus](https://github.com/pantheon-systems/terminus#installation), and set the `TERMINUS_TOKEN`, `TERMINUS_SITE`, and `TERMINUS_ENV` environment variables. Then, you can run `./bin/behat-prepare.sh` to prepare the site for the test suite.

== Upgrade Notice ==

= 0.2.2 =
Existing WP LCache users will need to alter the `value` column on the lcache_event table from `BLOB` to `LONGBLOB`.

== Changelog ==

= 0.5.2 (May 3rd, 2017) =
* Normalizes address key to comply with DB column length.
* Always runs database table initialization on the `enable` CLI command.
* Doesn't require APCu to be enabled in CLI.
* Test improvements.

= 0.5.1 (April 25th, 2017) =
* Uses the correct DSN format in all DB_HOST scenarios.
* Only loads LCache library for PHP 5.6+, to ensure WordPress doesn't fatal on older versions.
* Test improvements.

= 0.5.0 (November 2nd, 2016) =
* Splits WordPress' alloptions cache into separate cache keys to mitigate cache pollution caused by race conditions. [See #31245](https://core.trac.wordpress.org/ticket/31245) for further detail.
* Emits warnings in CLI when LCache isn't properly configured.
* Incorporates a variety of test suite improvements.

= 0.4.0 (October 5th, 2016) =
* Switches to stub file approach for enabling object cache drop-in, because symlink changes aren't detected by opcode cache in PHP 5.5+.

= 0.3.1 (September 22nd, 2016) =
* Updates LCache to [v0.3.4](https://github.com/lcache/lcache/releases/tag/v0.3.4), which automatically detects and handles misuse of the TTL as an expiration timestamp.

= 0.3.0 (September 21st, 2016) =
* Introduces the `wp lcache enable` WP-CLI command to create the `object-cache.php` symlink.
* Updates LCache to [v0.3.2](https://github.com/lcache/lcache/releases/tag/v0.3.2), which is more noisy about failed L2 serialization.
* Better admin notices: alerts when LCache database tables are missing, or if the plugin is active but `object-cache.php` is missing.

= 0.2.2 (September 14th, 2016) =
* Updates LCache to [v0.3.1](https://github.com/lcache/lcache/releases/tag/v0.3.1), which has L2 cache guard against returning failed unserializations.
* Sets `STRICT_ALL_TABLES` on the database handler to fail and give warnings if there are issues with database inserts.
* Bug fix: Uses `LONGBLOB` column type for lcache_event `value` column. Previously, the `value` column was `BLOB` which meant that long cache values (e.g. alloptions) could be unexpectedly truncated.

= 0.2.1 (September 14th, 2016) =
* Bug fix: Properly flushes entire LCache with `wp_cache_flush()` is called. Previously, LCache was called improperly, meaning none of the cache was flushed.

= 0.2.0 (September 14th, 2016) =
* Updates LCache to [v0.3.0](https://github.com/lcache/lcache/releases/tag/v0.3.0), fixing issues with faulty expiration.

= 0.1.0 (September 7th, 2016) =
* Initial release.
