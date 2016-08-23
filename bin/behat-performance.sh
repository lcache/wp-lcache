#!/bin/bash


set -ex

./bin/behat-check-required.sh


export WORDPRESS_ADMIN_USERNAME=admin2

###
# Create a new environment for this particular test run.
###
#############################################################terminus site create-env --to-env=$TERMINUS_ENV --from-env=dev

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
########################################################git clone -b $TERMINUS_ENV $PANTHEON_GIT_URL $PREPARE_DIR
git clone $PANTHEON_GIT_URL $PREPARE_DIR
cd $PREPARE_DIR
git checkout -b $TERMINUS_ENV
# Force the multidev back to match master
git push origin $TERMINUS_ENV -f




#### wipe
yes | terminus site wipe

#### Install core

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
#./vendor/bin/behat --suite=performance --strict








#
# yes | terminus site wipe
#   terminus wp "core install --title=$TERMINUS_ENV-$TERMINUS_SITE --url=$PANTHEON_SITE_URL --admin_user=$WORDPRESS_ADMIN_USERNAME --admin_email=wp-lcache@getpantheon.com --admin_password=$WORDPRESS_ADMIN_PASSWORD"
#   terminus  site  clear-cache
#   terminus wp "cache flush"
# ./vendor/bin/behat --suite=core --strict

# exit 0;



































#### wipe
#### Install Redis
#### Run test suites


#### Wipe

#### wipe
yes | terminus site wipe
#### Install core


#### Install lcache




###
# Add the copy of this plugin itself to the environment
###
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
git commit -m "Include WP LCache and its configuration files"
git push origin $TERMINUS_ENV -f






###
# Set up WordPress, theme, and plugins for the test run
###
# Silence output so as not to show the password.

  terminus wp "core install --title=$TERMINUS_ENV-$TERMINUS_SITE --url=$PANTHEON_SITE_URL --admin_user=$WORDPRESS_ADMIN_USERNAME --admin_email=wp-lcache@getpantheon.com --admin_password=$WORDPRESS_ADMIN_PASSWORD"

terminus wp "cache flush"
terminus  site  clear-cache
terminus wp "plugin activate wp-lcache"

 terminus  wp  "user delete $WORDPRESS_ADMIN_USERNAME   --yes"


export WORDPRESS_ADMIN_USERNAME=admin3


echo $WORDPRESS_ADMIN_USERNAME




 terminus  wp  "user  create   $WORDPRESS_ADMIN_USERNAME  test@example.com  --role=administrator  --user_pass=$WORDPRESS_ADMIN_PASSWORD"

terminus  wp "user list"




cd $BASH_DIR/..
./vendor/bin/behat --suite=default --strict
./vendor/bin/behat --suite=core --strict
#./vendor/bin/behat --suite=performance --strict



#### Run test suites
