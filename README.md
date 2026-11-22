# Appwrite's PHP Playground 🎮

Appwrite playground is a simple way to explore the Appwrite API & Appwrite PHP SDK. Use the source code of this repository to learn how to use the different Appwrite PHP SDK features.

[![PHP Style Guide](https://img.shields.io/badge/code_style-standard-brightgreen.svg)](https://www.php-fig.org/psr/psr-12/)

**Work in progress**

## Get Started

This playground doesn't include any PHP best practices but rather intended to show some of the most simple examples and use cases of using the Appwrite API in your PHP application and server.

## System Requirements
* A system with PHP 8.5 or newer installed (required by `appwrite/appwrite` 32.x).
* You have readily available Appwrite running instance (localhost in most cases).
* Create a project in Appwrite instance using console.
* Generate a secret key in the Appwrite instance using console.

### Installation
1. Clone this repository.
2. cd into to repository.
3. Copy the project_id, endpoint, and secret key from your Appwrite Console into the global.inc.php file.
4. Install dependencies using  composer (`composer install`).
5. Execute the command `php playground.php`
6. You will see the JSON response in the console.

### API Covered in Playground.
* Create Table
* List Tables
* Add Row
* Upload File
* List Files
* Create User
* List User
* Request Password Recovery
* Log a User In From a Token Secret
* Review the Project's Web Platforms
* Create Function, Deployment and Executions

Collections and documents were renamed to tables and rows in Appwrite 1.8. The
playground uses the `TablesDB` service; the older `Databases` service still
exists in the SDK, but every one of its methods is deprecated.

### Abuse limits

The account and recovery routes are abuse limited. Reaching a limit answers
`429`, which the SDK raises as an `AppwriteException` whose code is `429` and
whose type is `general_rate_limit_exceeded`. Since
[appwrite/appwrite#15280](https://github.com/appwrite/appwrite/pull/15280) the
count is kept per project: the attempts your project is allowed for a user or an
address are its own, and another project cannot spend them for you.
`createRecovery()` in `playground.php` shows how to handle the refusal.

An API key used to be waived from those limits. Since
[appwrite/appwrite#15470](https://github.com/appwrite/appwrite/pull/15470) the
routes that confirm a secret — `updateRecovery()`, `updateRecoveryOTP()`,
`createSession()`, `updatePhoneSession()`, `updateMagicURLSession()` and
`updateMFAChallenge()` — count every caller's guesses, a key holder included. A
key is trusted with throughput, not with the inbox or handset the secret was
sent to.

So a server relaying logins or recoveries for its users has to treat the
confirmation as something it gets a handful of tries at per user:

* `401` means the secret is wrong or has expired — ask the user for a new one.
* `429` means that user's attempts are spent — wait, do not guess again.
  Once a limit is spent the route refuses the correct secret too, so retrying a
  mistyped code locks the user out of signing in for the rest of the window.

`createSessionFromToken()` in `playground.php` shows the shape of that handling.

### Web platforms and origins

A project's web platforms are the origins Appwrite answers a credentialed
browser request from. A request whose origin is not registered is refused `403`
typed `general_unknown_origin`.

The console used to seed a new project with the hostname `*`, which matches
every origin. Since
[appwrite/appwrite#15509](https://github.com/appwrite/appwrite/pull/15509) it
seeds `localhost` ("Local development") instead: a browser app still talks to a
local Appwrite without touching the console, and no other origin is trusted
until it is added. A wildcard can still be entered by hand, and nothing
migrates a project created before the fix, so `*` may well be registered on one.

That is worth checking from code, because a wildcard means any page a signed-in
user visits can call the project as them — their session cookie goes along on
its own, and the public project ID is all the page needs.
`reviewWebPlatforms()` in `playground.php` reads the list, flags a wildcard with
`wildcardHostnames()` from `global.inc.php` (the `*.example.com` form included,
which the console does not warn about) and registers the one hostname a browser
app of the project is served from.

None of this constrains this playground, or any PHP server: the origin check is
skipped for a request carrying an API key, and the SDK sends no `Origin` header
at all. Reading and writing the list needs a key with the `platforms.read` and
`platforms.write` scopes; without them the calls answer `401`, which
`reviewWebPlatforms()` reports rather than mistaking for an all-clear.

## Tests

`composer test` runs the playground end to end against a stub Appwrite
(`tests/stub-server.php`) served by PHP's built-in web server, checking that
every call succeeded and hit the endpoint it was supposed to
(`tests/smoke.php`), and then drives the abuse-limited confirmation routes with
an API key to check that wrong guesses are counted for it, that the count is per
user, and that the `429` reaches the caller in a shape it can act on
(`tests/code-guesses.php`). `tests/web-platforms.php` then checks the platform
list: that a project seeded since appwrite/appwrite#15509 trusts `localhost` and
nothing else, that the wildcard a project from before it still carries is found,
that registering an origin from PHP never sends a wildcard, and that a PHP
caller — keyed or with a JWT — sends no origin and so is unaffected either way.
No Appwrite instance and no network access are needed.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Security

For security issues, kindly email us [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

## Follow Us

Join our growing community around the world! Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) or join our [Discord Server](https://appwrite.io/discord) for more help, ideas and discussions.
