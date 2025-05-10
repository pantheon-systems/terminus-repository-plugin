#!/bin/bash

# Get the site names to create and delete.

export REPOSITORY_SITE_CREATE_SITE_NAME="test-${TERMINUS_SITE_NAME_SUFFIX}"
export PANTHEON_SITE_CREATE_SITE_NAME="test-pantheon-${TERMINUS_SITE_NAME_SUFFIX}"
export GITHUB_SITE_CREATE_SITE_NAME="test-github-${TERMINUS_SITE_NAME_SUFFIX}"