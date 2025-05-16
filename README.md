# Terminus Repository Plugin

[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#early-access)

This Terminus plugin configure directs integration between individual Pantheon sites and individual Github repositories via [Pantheon's GitHub Application](https://docs.pantheon.io/github-application).
This plugin will eventually handle direct integration with other Git providers, such as GitLab and Bitbucket.

To set up the GitHub Application this plugin provides the command 'repository:site:create'  and a modified version of 'env:deploy'.

## Installation

To install this plugin using Terminus 3 or later, run the following command:

```
terminus self:plugin:install terminus-repository-plugin
```


## Private Beta

Pantheon's GitHub Application is currently in Private Beta. To get access, fill in [this form](https://forms.gle/GQqrfrkVWd3ghU8j8) and we will contact you soon.

## Feedback and support

Prior to General Availability, support for this plugin is handled directly by the product team instead of our regular support channels.
Use the issue queue on this repository to report bugs, request features or provide general feedback.

## Using the GitHub Application

### Creating a New Site

Once the plugin has been installed, you should execute a command like this to create a site:

```
terminus repository:site:create <site_name> <label> <upstream> "<your-organization>"
```

Some other options available:

- visibility: whether to make the repo "public" or "private" (default: private)
- region: Pantheon region for your site
- installation_id: If you already have one installation and want to reuse it for the new site

Once you run this command, the site creation process will start. You will be prompted to install the GitHub Application (or reuse an existing installation) to be able to manage your external repository. Follow through the steps in the provided GitHub link and once you do that, the site creation process will continue and will eventually create a repository (named after your site name) in the provided GitHub account (support for using existing repository will come at a later stage).

### Pull Requests and Multidevs

[Multidevs](https://docs.pantheon.io/guides/multidev) are a core product at Pantheon. If your organization has access to Multidevs; you can leverage them through the Application by using Pull Requests in your repository:

- When you create or reopen a Pull Request, a Multidev environment will be created at Pantheon.
- When you update your open Pull Request, the associated Multidev will be updated.
- When you close or merge a Pull Request, the associated Multidev will be deleted.

Please note that initially, sites connect to the GitHub Applicaiton won't be subject of the 10 Multidevs limit that we have at Pantheon but we are planning on implementing automatic deletion of dormant Multidevs for these sites.

### Deployment to Test or Live Environments

This Terminus plugin includes a modified version of the `env:deploy` command; so to deploy to test or live environments.

While having the plugin installed, you can deploy using Terminus as usual. Deploying from the dashboard is not yet supported for site using the GitHub Application.

### Status and logs of my code pushes

If you want to follow your code pushes; you can do so through the dashboard usual [Workflow Logs](https://docs.pantheon.io/workflow-logs) functionality.

## Testing of the plugin itself
This example project includes four testing targets:

* `composer lint`: Syntax-check all php source files.
* `composer cs`: Code-style check.
* `composer unit`: Run unit tests with phpunit

To run all tests together, use `composer test`.

Note that prior to running the tests, you should first run:
* `composer install`

## Help
Run `terminus help repository:site-create` for help.
