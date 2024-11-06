# Terminus Repository Plugin

[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)

A Terminus plugin to manage ICR sites in Pantheon.

Adds command 'repository:site:create' to Terminus.

## Installation

To install this plugin using Terminus 3:
```
terminus self:plugin:install terminus-repository-plugin
```

or clone from the repo and then:

```
terminus self:plugin:install <path-to-plugin-folder>
```

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
