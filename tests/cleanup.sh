#!/bin/bash

# Cleanup script to delete sites created during tests.

source $WORKSPACE/tests/sitenames.sh


terminus site:delete -y $REPOSITORY_SITE_CREATE_SITE_NAME || echo "Site deletion for $REPOSITORY_SITE_CREATE_SITE_NAME failed."
terminus site:delete -y $PANTHEON_SITE_CREATE_SITE_NAME || echo "Site deletion for $PANTHEON_SITE_CREATE_SITE_NAME failed."
terminus site:delete -y $GITHUB_SITE_CREATE_SITE_NAME || echo "Site deletion for $GITHUB_SITE_CREATE_SITE_NAME failed."