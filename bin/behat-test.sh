#!/bin/bash

set -ex

###
# Execute the Behat test suite against a prepared Pantheon site environment.
###

./bin/behat-check-required.sh

export BEHAT_PARAMS='{"extensions" : {"Behat\\MinkExtension" : {"base_url" : "http://'$TERMINUS_ENV'-'$TERMINUS_SITE'.pantheonsite.io"} }}'

./vendor/bin/behat "$@" --strict
