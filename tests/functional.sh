#!/bin/bash

source $WORKSPACE/tests/sitenames.sh

# Pantheon hosted site create.
echo "Creating Pantheon hosted site..."
terminus site:create $PANTHEON_SITE_CREATE_SITE_NAME $PANTHEON_SITE_CREATE_SITE_NAME drupal-11-composer-managed --org="$ORG_ID" --vcs-provider=pantheon

# Github hosted site create.
echo "Creating Github hosted site..."
terminus site:create $GITHUB_SITE_CREATE_SITE_NAME $GITHUB_SITE_CREATE_SITE_NAME drupal-11-composer-managed --org="$ORG_ID" --vcs-provider=github --vcs-org="$VCS_ORG"
