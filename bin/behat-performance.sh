#!/bin/bash

set -ex

./bin/behat-check-required.sh

###
# Create a new environment for this particular test run.
###
terminus site create-env --to-env=$TERMINUS_ENV --from-env=dev

###
# Get all necessary environment details.
###
PANTHEON_GIT_URL=$(terminus site connection-info --field=git_url)
PANTHEON_SITE_URL="$TERMINUS_ENV-$TERMINUS_SITE.pantheonsite.io"
PREPARE_DIR="/tmp/$TERMINUS_ENV-$TERMINUS_SITE"
BASH_DIR="$( cd -P "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
export BEHAT_PARAMS='{"extensions" : {"Behat\\MinkExtension" : {"base_url" : "http://'$TERMINUS_ENV'-'$TERMINUS_SITE'.pantheonsite.io"} }}'

###
# Switch to git mode for pushing the files up
###
terminus site set-connection-mode --mode=git
rm -rf $PREPARE_DIR
git clone -b $TERMINUS_ENV $PANTHEON_GIT_URL $PREPARE_DIR

# The purpose of this section is to ensure that two CircleCI build are not
# trying to run tests on the same multidev environment at the same time.
IN_PROGRESS_INDICATOR_FILE="$PREPARE_DIR/performance_test_in_progress.txt"
echo $IN_PROGRESS_INDICATOR_FILE
if [ -f $IN_PROGRESS_INDICATOR_FILE ];
  then echo "Another process or build is already running tests on this environment. Exiting.";
  exit 0
fi

cd $PREPARE_DIR
echo "Build in progress" > performance_test_in_progress.txt
git add performance_test_in_progress.txt
git commit -m "Starting perfomance test on plain WordPress core."
##### #Force the multidev back to match master
git push origin $TERMINUS_ENV

### wipe
yes | terminus site wipe

 ###
 # Set up WordPress, theme, and plugins for the test run
 ###
 # Silence output so as not to show the password.
 {
   terminus wp "core install --title=$TERMINUS_ENV-$TERMINUS_SITE --url=$PANTHEON_SITE_URL --admin_user=$WORDPRESS_ADMIN_USERNAME --admin_email=wp-lcache@getpantheon.com --admin_password=$WORDPRESS_ADMIN_PASSWORD"
 } &> /dev/null
 terminus  site  clear-cache
 terminus wp "cache flush"

 #### Run test suites
 cd $BASH_DIR/..
 ./vendor/bin/behat --suite=core --strict
 ./vendor/bin/behat --suite=performance --strict

#### Wipe
terminus wp "cache flush"
terminus  site  clear-cache
#### wipe
yes | terminus site wipe

###
# Add the copy of this plugin itself to the environment
###
# Ensure this branch has the latest from master
git merge origin/master
rm -rf $PREPARE_DIR/wp-content/plugins/wp-lcache
rm -rf $PREPARE_DIR/wp-content/object-cache.php
cd $BASH_DIR/..
rsync -av --exclude='vendor/' --exclude='node_modules/' --exclude='tests/' ./* $PREPARE_DIR/wp-content/plugins/wp-lcache
rm -rf $PREPARE_DIR/wp-content/plugins/wp-lcache/.git
cd $PREPARE_DIR/wp-content
ln -s plugins/wp-lcache/object-cache.php object-cache.php

###
# Add the debugging plugin to the environment
###
rm -rf $PREPARE_DIR/wp-content/mu-plugins/lcache-debug.php
cp $BASH_DIR/fixtures/lcache-debug.php $PREPARE_DIR/wp-content/mu-plugins/lcache-debug.php

###
# Push files to the environment
###
cd $PREPARE_DIR
git add wp-content
git commit -m "Starting performance test with wp-lcache installed"
git push origin $TERMINUS_ENV -f


###
# Set up WordPress, theme, and plugins for the test run
###
# Silence output so as not to show the password.
{
  terminus wp "core install --title=$TERMINUS_ENV-$TERMINUS_SITE --url=$PANTHEON_SITE_URL --admin_user=$WORDPRESS_ADMIN_USERNAME --admin_email=wp-lcache@getpantheon.com --admin_password=$WORDPRESS_ADMIN_PASSWORD"
} &> /dev/null

terminus wp "cache flush"
terminus  site  clear-cache
terminus wp "plugin activate wp-lcache"


cd $BASH_DIR/..
./vendor/bin/behat --suite=default --strict
./vendor/bin/behat --suite=core --strict
./vendor/bin/behat --suite=performance --strict
