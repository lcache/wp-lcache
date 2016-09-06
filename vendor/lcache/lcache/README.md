# LCache
Foundation Library for Coherent, Multi-Layer Caching

## Testing

[![Build Status](https://travis-ci.org/lcache/lcache.svg?branch=master)](https://travis-ci.org/lcache/lcache)
[![Coverage Status](https://coveralls.io/repos/github/lcache/lcache/badge.svg?branch=master)](https://coveralls.io/github/lcache/lcache?branch=master)

### On Fedora

 1. Install packages:

    ```
    sudo dnf install -y php-cli composer php-phpunit-PHPUnit php-phpunit-DbUnit php-pecl-apcu
    ```

 2. Enable APCu caching for the CLI:

    ```
    echo "apc.enable_cli=1" | sudo tee -a /etc/php.d/40-apcu.ini
    ```

 3. From the project root directory:

    ```
    composer install
    composer test
    ```

## Support

LCache is maintained and sponsored by [Pantheon](https://pantheon.io/).
