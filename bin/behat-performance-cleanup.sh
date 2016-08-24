#!/bin/bash

###
# Delete the Pantheon site environment after the Behat test suite has run.
###

set -ex

./bin/behat-check-required.sh


PREPARE_DIR="/tmp/$TERMINUS_ENV-$TERMINUS_SITE"

# The purpose of this section is to ensure that two CircleCI build are not
# trying to run tests on the same multidev environment at the same time.
IN_PROGRESS_INDICATOR_FILE="$PREPARE_DIR/performance_test_in_progress.txt"
echo $IN_PROGRESS_INDICATOR_FILE
if [ -f $IN_PROGRESS_INDICATOR_FILE ];
  then echo "Another process or build is already running tests on this environment. Exiting.";
  exit 0
fi

# Clean up by restoring this branch to match master
cd $PREPARE_DIR
git checkout master
git branch -D $TERMINUS_ENV
git checkout -b $TERMINUS_ENV
git push origin $TERMINUS_ENV -f
