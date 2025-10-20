#!/bin/bash

# Setup fakes.
cd tests/fakes && python3 -m venv . && source ./bin/activate && pip3 install -r requirements.txt && chmod +x ./fakes.py && ./fakes.py &
source $WORKSPACE/.envrc.dist

source $WORKSPACE/tests/sitenames.sh

# Pantheon hosted site create.
echo "Creating Pantheon hosted site..."
terminus site:create $PANTHEON_SITE_CREATE_SITE_NAME $PANTHEON_SITE_CREATE_SITE_NAME drupal-11-composer-managed --org="$ORG_ID" --vcs-provider=pantheon

# Request server url in background.
echo "Starting request server url script..."
chmod +x $WORKSPACE/tests/request_server_url.sh
$WORKSPACE/tests/request_server_url.sh &

# Github hosted site create.
echo "Creating Github hosted site..."
terminus site:create $GITHUB_SITE_CREATE_SITE_NAME $GITHUB_SITE_CREATE_SITE_NAME drupal-11-composer-managed --org="$ORG_ID" --vcs-provider=github
