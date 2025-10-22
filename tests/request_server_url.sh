#!/bin/bash

# Configuration
FILENAME="/tmp/terminus_test_server_url"
POLL_INTERVAL=5
TARGET_COUNT=1

# Initialize last value and counter
LAST_VALUE=""
DIFFERENT_VALUES_COUNT=0

while true; do
    if [[ -f "$FILENAME" ]]; then
        VALUE=$(cat "$FILENAME" | tr -d '[:space:]')
        if [[ -n "$VALUE" && "$VALUE" != "$LAST_VALUE" ]]; then
            LAST_VALUE="$VALUE"
            ((DIFFERENT_VALUES_COUNT++))
            # Perform GET request
            curl -s -L "$VALUE" -o /dev/null
            # Exit if target reached
            if [[ "$DIFFERENT_VALUES_COUNT" -ge "$TARGET_COUNT" ]]; then
                echo "Target of $TARGET_COUNT different values reached. Exiting."
                exit 0
            fi
        fi
    fi
    sleep "$POLL_INTERVAL"
done