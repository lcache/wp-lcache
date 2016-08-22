#!/bin/bash

###
# Delete the Pantheon site environment after the Behat test suite has run.
###

set -ex

./bin/behat-check-required.sh

###
# Delete the environment used for this test run.
###
yes | terminus site delete-env --remove-branch
