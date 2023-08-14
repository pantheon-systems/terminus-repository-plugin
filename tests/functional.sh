#!/bin/bash

# Setup fakes.
cd tests/fakes && pip install -r requirements.txt && ./fakes.py &
source .envrc.dist

# Create site.
terminus repository:site:create $TERMINUS_SITE_NAME $TERMINUS_SITE_NAME drupal-10-composer-managed --org=$ORG_ID --vcs=github
