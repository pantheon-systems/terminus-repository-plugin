# Fakes

## Setting up Fakes

Install requirements with:

```
python3 -m pip install -r requirements.txt
```

o an equivalent command in your environment.

## Running fakes

```
./fakes.py
```

You can override the default port running it like this:

```
FAKES_PORT=8484 ./fakes.py
```

## Querying the fakes

### VCS Authorize

This is a POST endpoint that should be requested like this:

```
curl -H 'Content-Type: application/json' -X POST -d '{"vcs_organization": "something"}' http://127.0.0.1:8484/vcs-auth/v1/authorize
```

### Poll VCS Workflow

This is a GET endpoint that should be requested like this:

```
curl http://127.0.0.1:8484/vcs-auth/v1/workflows/90800ed4-7ee2-41b2-8d17-c68d2285feda
```

And it will change the status from `auth_pending` to `auth_complete` 30 seconds after the workflow was originally created.