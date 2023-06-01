#!/usr/bin/env python3

import os
import time
import uuid
from flask import Flask, request

app = Flask(__name__)

port = os.environ.get('TERMINUS_PAPI_PORT', 8443)

workflows = {}

@app.route('/vcs-auth/v1/authorize', methods=['POST'])
def authorizeVcs():
    if not request.is_json:
        return "Request body must be json", 400
    if not request.json.get("vcs_organization"):
        return "vcs_organization is required", 400

    workflow_id = str(uuid.uuid4())

    data = {
        "timestamp": time.time(),
        "vcs_auth_link": "https://github.com",
        "vcs_type": "github",
        "workflow_id": workflow_id,
        "site_uuid": "FAKE_SITE_UUID",
        "status": "auth_pending",
    }
    workflows[workflow_id] = data

    return data

@app.route('/vcs-auth/v1/workflows/<id>', methods=['GET'])
def getWorkflow(id):

    if id not in workflows:
        return "Workflow not found", 404

    data = workflows[id]

    current_timestamp = time.time()

    # Polling this will return auth_pending for 30 seconds and then will change to auth_complete
    if current_timestamp - data["timestamp"] > 30:
        data["status"] = "auth_complete"
        workflows[id] = data

    # @todo Should this randomly fail?

    return data

if __name__ == '__main__':
   app.run(host='0.0.0.0', port=port)
