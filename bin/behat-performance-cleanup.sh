#!/bin/bash

###
# Delete the Pantheon site environment after the Behat test suite has run.
###

set -ex

./bin/behat-check-required.sh


PREPARE_DIR="/tmp/$TERMINUS_ENV-$TERMINUS_SITE"

# Clean up by restoring this branch to match master
cd $PREPARE_DIR
git checkout master
git branch -D $TERMINUS_ENV
git checkout -b $TERMINUS_ENV
git push origin $TERMINUS_ENV -f
