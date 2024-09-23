#!/bin/bash

# Setup fakes.
cd tests/fakes && python3 -m venv . && source ./bin/activate && pip3 install -r requirements.txt && chmod +x ./fakes.py && ./fakes.py &
source $WORKSPACE/.envrc.dist

# Create site.
terminus repository:site:create $TERMINUS_SITE_NAME $TERMINUS_SITE_NAME drupal-10-composer-managed --org=$ORG_ID --vcs=github
