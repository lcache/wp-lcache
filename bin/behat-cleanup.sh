#!/bin/bash

set -ex

###
# Delete the Pantheon site environment after the Behat test suite has run.
###

./bin/behat-check-required.sh

###
# Delete the environment used for this test run.
###
yes | terminus site delete-env --remove-branch
