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
* Create Function, Deployment and Executions

Collections and documents were renamed to tables and rows in Appwrite 1.8. The
playground uses the `TablesDB` service; the older `Databases` service still
exists in the SDK, but every one of its methods is deprecated.

## Tests

`composer test` runs `playground.php` end to end against a stub Appwrite
(`tests/stub-server.php`) served by PHP's built-in web server, and checks that
every call succeeded and hit the endpoint it was supposed to. No Appwrite
instance and no network access are needed.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Security

For security issues, kindly email us [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

## Follow Us

Join our growing community around the world! Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) or join our [Discord Server](https://appwrite.io/discord) for more help, ideas and discussions.
