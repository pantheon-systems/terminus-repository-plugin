# Terminus Repository Plugin

[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#early-access)

A Terminus plugin to manage eVCS Sites in Pantheon.

Adds command 'repository:site:create' to Terminus and a modified version of 'env:deploy' to support eVCS Sites.

## Installation

To install this plugin using Terminus 3:
```
terminus self:plugin:install terminus-repository-plugin
```

or clone from the repo and then:

```
terminus self:plugin:install <path-to-plugin-folder>
```

## About eVCS Sites

Git has become an essential part of developer's life and it is super common these days for development teams to do their day to day work using git collaboration tools like [GitHub](https://github.com/), [GitLab](https://gitlab.org/) or [BitBucket](https://bitbucket.org/).

Pantheon was created with git as a core component of the platform but until now there have been no native solution to integrate with external repositories. The closest to that we have is [Build Tools](https://github.com/pantheon-systems/terminus-build-tools-plugin) but this is a solution that puts some burden on customers to manage it.

eVCS Sites comes to close this gap by offering a solution for our customers to be able to manage their code wherever they want (GitHub only supported initially) and easily integrate it with our platform.

## Getting access to eVCS Sites

This product is currently in Private Beta. To get access, fill in [this form](https://forms.gle/GQqrfrkVWd3ghU8j8) and we will contact you soon.

## Getting support for eVCS Sites

As mentioned in our [documentation](https://docs.pantheon.io), support for Alpha products is handled by the product team using the ways provided to you in the onboarding materials.

## Using eVCS Sites

### Creating an eVCS Site

Once the plugin has been installed, you should execute a command like this to create your first eVCS Site:

```
terminus repository:site:create <site_name> <label> <upstream> "<your-organization>"
```

Some other options available:

- visibility: whether to make the repo "public" or "private" (default: private)
- region: Pantheon region for your site
- installation_id: If you already have one installation and want to reuse it for the new site

Once you run this command, the site creation process will start. You will be prompted to install the GitHub application (or reuse an existing installation) to be able to manage your external repository. Follow through the steps in the provided GitHub link and once you do that, the site creation process will continue and will eventually create a repository (named after your site name) in the provided GitHub account (support for using existing repository will come at a later stage).

### Pull Requests and Multidevs

[Multidevs](https://docs.pantheon.io/guides/multidev) are a core product at Pantheon. If your organization has access to Multidevs; you can leverage them easily in eVCS Sites by using Pull Requests in your repository:

- When you create or reopen a Pull Request, a Multidev environment will be created at Pantheon.
- When you update your open Pull Request, the associated Multidev will be updated.
- When you close or merge a Pull Request, the associated Multidev will be deleted.

Please note that initially, eVCS Sites won't be subject of the 10 Multidevs limit that we have at Pantheon but we are planning on implementing automatic deletion of dormant Multidevs for these sites.

### Deployments for eVCS Sites

The eVCS Sites Terminus plugin includes a modified version of the `env:deploy` command; so to deploy to test or live environments during this alpha stage, you will need this plugin. It won't affect your ability to deploy to other Pantheon sites.

While having the plugin installed, you can deploy using Terminus as usual. Deploying from the dashboard is not supported at this moment.

### Status and logs of my code pushes

If you want to follow your code pushes; you can do so through the dashboard usual [Workflow Logs](https://docs.pantheon.io/workflow-logs) functionality.

## Giving feedback to the eVCS Site steam

Your feedback for this product is really important to shape the future of it; please do not hesitate to provide your feedback by following the instructions provided in the onboarding materials.

## Testing
This example project includes four testing targets:

* `composer lint`: Syntax-check all php source files.
* `composer cs`: Code-style check.
* `composer unit`: Run unit tests with phpunit

To run all tests together, use `composer test`.

Note that prior to running the tests, you should first run:
* `composer install`

## Help
Run `terminus help repository:site-create` for help.
