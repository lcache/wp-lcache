# WP LCache #
**Contributors:** getpantheon, danielbachhuber, stevector  
**Tags:** cache, plugin  
**Requires at least:** 4.3  
**Tested up to:** 4.6.1  
**Stable tag:** 0.0.0  
**License:** GPLv2 or later  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html  

Supercharge your WP Object Cache with LCache, a persistent, performant, and multi-layer cache library.

## Description ##

[![Travis CI](https://travis-ci.org/lcache/wp-lcache.svg?branch=master)](https://travis-ci.org/lcache/wp-lcache) [![CircleCI](https://circleci.com/gh/lcache/wp-lcache/tree/master.svg?style=svg)](https://circleci.com/gh/lcache/wp-lcache/tree/master)

For sites concerned with high traffic, speed for logged-in users, or dynamic pageloads, a high-speed and persistent object cache is a must. WP LCache improves upon Memcached and Redis implementations by using APCu, PHP's in-memory cache, in a way that's compatible with multiple web nodes.

Under the hood, WP LCache uses [LCache](https://github.com/lcache/lcache), a library that applies the tiered caching model of multi-core processors (with local L1 and central L2 caches) to web applications. In this configuration, APCu is the L1 cache and the database is the L2 cache. APCu traditionally can't be used on multiple web nodes because each web node represents a different cache pool. Because WP LCache has a database-based L2 cache, a cache update or delete on one node can then be applied to all other nodes.

Read the installation instructions, then install WP LCache from [WordPress.org](https://wordpress.org/plugins/wp-lcache/) or [Github](https://github.com/lcache/wp-lcache).

Go forth and make awesome! And, once you've built something great, [send us feature requests (or bug reports)](https://github.com/lcache/wp-lcache/issues).

## Installation ##

**WP LCache requires PHP 5.6 or greater with the APCu extension enabled.** If you're running an older PHP version, or APCu is unavailable, you'll see an admin notice in your WordPress dashboard.

To install WP LCache, follow these steps:

1. Install the plugin from WordPress.org using the WordPress dashboard or WP-CLI.
2. Activate the plugin, to ensure LCache's database tables are created. These are created on the plugin activation hook.
3. Symlink the object cache drop-in to its appropriate location: `cd wp-content; ln -s plugins/wp-lcache/object-cache.php object-cache.php`

If you need to install APCu, the PECL installer is the easiest way to do so.

* PHP 7.0: `pecl install apcu`
* PHP 5.6: `pecl install channel://pecl.php.net/apcu-4.0.11`

If you can't easily use PHP 5.6 or greater, you should switch to a more responsible hosting provider.

## Contributing ##

The best way to contribute to the development of this plugin is by participating on the GitHub project:

https://github.com/lcache/wp-lcache

Pull requests and issues are welcome!

You may notice there are two sets of tests running, on two different services:

* Travis CI runs the [PHPUnit](https://phpunit.de/) test suite in a variety of environment configurations (e.g. APCu available vs. APCu unavailable).
* Circle CI runs the [Behat](http://behat.org/) test suite against a Pantheon site, to ensure the plugin's compatibility with the Pantheon platform.

Both of these test suites can be run locally, with a varying amount of setup.

PHPUnit requires the [WordPress PHPUnit test suite](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/), and access to a database with name `wordpress_test`. If you haven't already configured the test suite locally, you can run `bash bin/install-wp-tests.sh wordpress_test root '' localhost`. You'll also need to install and configure APCu in order to run the test suite against APCu.

Behat requires a Pantheon site. Once you've created the site, you'll need [install Terminus](https://github.com/pantheon-systems/terminus#installation), and set the `TERMINUS_TOKEN`, `TERMINUS_SITE`, and `TERMINUS_ENV` environment variables. Then, you can run `./bin/behat-prepare.sh` to prepare the site for the test suite.

## Changelog ##

### 0.1.0 (September 7th, 2016) ###
* Initial release.
