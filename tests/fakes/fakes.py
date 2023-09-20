#!/usr/bin/env python3

import os
import time
import uuid
from flask import Flask, request

app = Flask(__name__)

port = os.environ.get('TERMINUS_PAPI_PORT', 8443)

workflows = {}

@app.route('/vcs/v1/workflow', methods=['POST'])
def postWorkflow():
    if not request.is_json:
        return "Request body must be json", 400

    request_data = request.get_json()

    if not "site_uuid" in request_data:
        return "Site uuid is required", 400

    site_uuid = request_data["site_uuid"]

    workflow_id = str(uuid.uuid4())

    data = {
        "site_details_id": site_uuid,
        "workflow_id": workflow_id,
        "timestamp": time.time(),
        "vcs_auth_links": {
            "github_oauth": "https://github.com/login/oauth/authorize?client_id=1234567890",
            "bitbucket_oauth": "",
            "gitlab_oauth": "",
        }
    }
    workflows[site_uuid] = data
    return {
        "data": [
            data
        ]
    }

@app.route('/vcs/v1/site-details/<id>', methods=['GET'])
def getSiteDetails(id):

    if id not in workflows:
        return "Site not found", 404

    workflow = workflows[id]

    current_timestamp = time.time()

    data = {
        "site_details_id": id,
        "is_active": False,
        "vcs_installation_id": "1",
    }

    # Polling this will return auth_pending for 15 seconds and then will change to auth_complete
    if current_timestamp - workflow["timestamp"] > 15:
        data["is_active"] = "true"

    return {
        "data": [
            data
        ]
    }

@app.route('/vcs/v1/repo-initialize', methods=['POST'])
def postRepoInitialize():
    if not request.is_json:
        return "Request body must be json", 400

    return ""

@app.route('/vcs/v1/site-details/<id>', methods=['DELETE'])
def cleanupSiteDetails(id):
    return id

if __name__ == '__main__':
   app.run(host='0.0.0.0', port=port)
