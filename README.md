# Terminus Repository Plugin

[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#early-access)

This Terminus plugin configure directs integration between individual Pantheon sites and individual GitHub repositories via [Pantheon's GitHub Application](https://docs.pantheon.io/github-application).
This plugin will eventually handle direct integration with other Git providers, such as GitLab and Bitbucket.

To set up the GitHub Application this plugin enhance the commands `site:create` and `env:deploy` with additional functionality.

## Installation

To install this plugin using Terminus 3 or later, run the following command:

```
terminus self:plugin:install terminus-repository-plugin
```

## Feedback and support

Prior to General Availability, support for this plugin is handled directly by the product team instead of our regular support channels.
Use the issue queue on this repository to report bugs, request features or provide general feedback.

## Using the GitHub Application

### Creating a New Site

Once the plugin has been installed, you can run `site:create` with additional options to create a site using external version control:

```
terminus site:create <site_name> <label> <upstream> --vcs-provider=github --org=<your-organization>
```

- `vcs-provider`: Specifies where the site repository should be hosted.  Current options are `github` or `pantheon`.
- `org`: This parameter is _required_ if specifying a `--vcs-provider` other than "pantheon".

Some other options available:

- `visibility`: whether to make the repo "public" or "private" (default: private).
- `region`: Pantheon region for your site.
- `vcs-org`: If you've already set up a Pantheon site and want to create another one with the same Github organization, you can specify that organization using this option.
- `repository-name`: Custom name for the repository (defaults to site name) - Note: Repository names must be less than 100 characters. Only alphanumeric characters, hyphens, and underscores are allowed.

Once you run this command, the site creation process will start. You will be prompted to install the GitHub Application to be able to manage your external repository.
Follow through the steps in the provided GitHub link and once you do that, the site creation process will continue and will eventually create a repository (named after your site name) in the provided GitHub account.
On subsequent site creations, you will be able to select the previously configured GitHub account/organization or configure a new one.


### Pull Requests and Multidevs

[Multidevs](https://docs.pantheon.io/guides/multidev) are a core product at Pantheon. If your organization has access to Multidevs; you can leverage them through the Application by using Pull Requests in your repository:

- When you create or reopen a Pull Request, a Multidev environment will be created at Pantheon.
- When you update your open Pull Request, the associated Multidev will be updated.
- When you close or merge a Pull Request, the associated Multidev will be deleted.

Please note that initially, sites connect to the GitHub Application won't be subject of the 10 Multidevs limit that we have at Pantheon but we are planning on implementing automatic deletion of dormant Multidevs for these sites.

### Deployment to Test or Live Environments

This Terminus plugin includes a modified version of the `env:deploy` command; so to deploy to test or live environments.

While having the plugin installed, you can deploy using Terminus as usual. Deploying from the dashboard is not yet supported for sites using the GitHub Application.

### Status and logs of my code pushes

If you want to follow your code pushes; you can do so through the dashboard usual [Workflow Logs](https://docs.pantheon.io/workflow-logs) functionality.

## Private Beta

Pantheon's GitHub Application is currently in Private Beta. To get access, fill in [this form](https://forms.gle/GQqrfrkVWd3ghU8j8) and we will contact you soon.

## Testing of the plugin itself

This example project includes four testing targets:

* `composer lint`: Syntax-check all php source files.
* `composer cs`: Code-style check.
* `composer unit`: Run unit tests with phpunit

To run all tests together, use `composer test`.

Note that prior to running the tests, you should first run:
* `composer install`

## Help
Run `terminus help site-create` for help.
