#!/bin/bash

# Setup fakes.
cd tests/fakes && python3 -m venv . && source ./bin/activate && pip3 install -r requirements.txt && chmod +x ./fakes.py && ./fakes.py &
source $WORKSPACE/.envrc.dist

source $WORKSPACE/tests/sitenames.sh

# Create site.
terminus repository:site:create $REPOSITORY_SITE_CREATE_SITE_NAME $REPOSITORY_SITE_CREATE_SITE_NAME drupal-10-composer-managed $ORG_ID --vcs=github


# Pantheon hosted site create.
echo "Creating Pantheon hosted site..."
terminus site:create $$PANTHEON_SITE_CREATE_SITE_NAME $PANTHEON_SITE_CREATE_SITE_NAME drupal-11-composer-managed --org="$ORG_ID" --vcs-provider=pantheon

# Github hosted site create.
echo "Creating Github hosted site..."
terminus site:create $GITHUB_SITE_CREATE_SITE_NAME $GITHUB_SITE_CREATE_SITE_NAME drupal-11-composer-managed --org="$ORG_ID" --vcs-provider=github