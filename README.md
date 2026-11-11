# Appwrite's PHP Playground 🎮

Appwrite playground is a simple way to explore the Appwrite API & Appwrite PHP SDK. Use the source code of this repository to learn how to use the different Appwrite PHP SDK features.

[![PHP Style Guide](https://img.shields.io/badge/code_style-standard-brightgreen.svg)](https://www.php-fig.org/psr/psr-12/)

**Work in progress**

## Get Started

This playground doesn't include any PHP best practices but rather intended to show some of the most simple examples and use cases of using the Appwrite API in your PHP application and server.

## System Requirements
* A system with PHP 8.5 or later installed — that is what `appwrite/appwrite` 32.x requires.
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
* List Users
* Mark a user verified
* Confirm an email verification code
* Create, deploy and execute a Function

Tables, rows and columns are what Appwrite 1.8 renamed collections, documents
and attributes to. The playground uses the `TablesDB` service; the `Databases`
service still answers to the old names but is deprecated.

### Verifying a user from a server

Two calls can end up with a verified user, and they are not interchangeable:

* `markUserVerified()` uses `users->updateEmailVerification()`. It needs
  `users.write`, takes no code, and is the right call when the server itself is
  the one deciding a user is verified.
* `confirmEmailVerification()` replays the six digit code Appwrite sent the
  user. Appwrite counts every attempt against that route — including attempts
  made with an API key — at 10 per user per hour, so a server that retries after
  a rejected code spends the budget and then gets
  `429 general_rate_limit_exceeded` for the rest of the hour, the user's own
  correct code included. Treat a rejected code as final and issue a fresh one.

Set `VERIFICATION_USER_ID` and `VERIFICATION_SECRET` in `global.inc.php` to run
`confirmEmailVerification()`; it is skipped while they are empty.

### Tests

`composer test` runs the playground against a stub Appwrite served by PHP's
built-in web server, so it needs no running instance:

* `tests/smoke.php` — every playground call succeeds and hits the endpoint it
  means to.
* `tests/verification.php` — the verification confirmation routes count every
  caller, and the playground reports a rate limited confirmation instead of
  failing the run.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Security

For security issues, kindly email us [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

## Follow Us

Join our growing community around the world! Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) or join our [Discord Server](https://appwrite.io/discord) for more help, ideas and discussions.
