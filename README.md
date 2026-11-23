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
* Create a Table Whose Columns Do Not Fit a Row
* List Tables
* Add Row
* Upload File
* List Files
* Create User
* List User
* Count Users Past the Reported Total
* Request Password Recovery
* Log a User In From a Token Secret
* Create Function, Deployment and Executions

Collections and documents were renamed to tables and rows in Appwrite 1.8. The
playground uses the `TablesDB` service; the older `Databases` service still
exists in the SDK, but every one of its methods is deprecated.

### List totals

A list `total` is a count with a budget. The server counts at most
`APP_LIMIT_COUNT` (5,000) rows and answers with whatever it reached, so a total
sitting at that number means "at least 5,000", not "5,000": a project with
50,000 users reports 5,000, and the 200 pages of 25 that number divides into end
on a page that is not the last one.
[appwrite/appwrite#15496](https://github.com/appwrite/appwrite/pull/15496) fixed
the console's half of this — a clamped total now reads `5,000+`, and no page
count is derived from it. Code reading the same lists has the same three
options:

* report the number as the floor it is (`5,000+`), which costs nothing;
* page to the end of the list for the exact number, asking for each next page
  with `Query::cursorAfter()` so it starts where the last one ended instead of
  re-scanning the rows ahead of it;
* ask for no count at all with `total: false`, which skips the count query. The
  `0` that answers means "not counted" — reading it as "no users" is the same
  mistake in the other direction.

`countUsersPastTheCountLimit()` in `playground.php` shows all three.

### Table schemas and the row they fit into

A table's columns have to fit the row its database gives it: 65,535 bytes on
every SQL adapter Appwrite runs on, of which the internal columns (`_uid`, the
timestamps, `_permissions` and friends) already spend 1,067. A string column
spends four bytes per character for as long as it stays in the row — past about
16,000 characters it becomes a `TEXT` stored outside the row and costs only a
20-byte pointer — so the columns that fill a row up are the mid-sized ones. A
table reports its own share as `bytesUsed` out of `bytesMax`.

A create whose columns do not fit used to be answered with
`table_limit_exceeded`, "The maximum number of tables for database '...' has
been reached": a limit that does not exist, naming the table that had just been
refused where it promised a database, with the library's explanation of the real
limit dropped for that description.
[appwrite/appwrite#15317](https://github.com/appwrite/appwrite/pull/15317) maps
the refusal to the limit it is and forwards the explanation, so the answer now
reads:

```
400 column_limit_exceeded
Document size limit of 65535 exceeded. Cannot create collection.
```

For code calling `createTable()` with inline columns that means:

* a `400 column_limit_exceeded` is about the schema in the request, not about a
  quota on the project or the database. Drop a column or shrink a string size
  and the same request goes through — there is no larger table allowance to ask
  for;
* the same type covers a schema with too many columns (`Attribute limit of 1017
  exceeded`), and the deprecated Databases API spells it
  `attribute_limit_exceeded`;
* a refused create is rolled back whole, so the id it asked for is free for the
  retry;
* `table_limit_exceeded` and `collection_limit_exceeded` were retired by the
  same PR. Nothing produces them any more, so code branching on either is
  branching on an answer that cannot arrive.

`createTablePastTheRowLimit()` in `playground.php` shows the handling.

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

## Tests

`composer test` runs the playground end to end against a stub Appwrite
(`tests/stub-server.php`) served by PHP's built-in web server, checking that
every call succeeded and hit the endpoint it was supposed to
(`tests/smoke.php`), and then drives the abuse-limited confirmation routes with
an API key to check that wrong guesses are counted for it, that the count is per
user, and that the `429` reaches the caller in a shape it can act on
(`tests/code-guesses.php`). It then lists a project holding more users than a
`total` counts to, checking that the clamped total is read as a floor, that the
rows past it are reachable with a cursor, and that `total: false` answers `0`
without hiding the rows (`tests/list-total.php`). Last, it creates tables whose
columns do not fit the row, checking that the refusal names the limit the
columns hit instead of a quota on tables, that it explains which one, and that
retrying with a schema that fits works under the same id
(`tests/column-limit.php`). No Appwrite instance and no network access are
needed.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Security

For security issues, kindly email us [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

## Follow Us

Join our growing community around the world! Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) or join our [Discord Server](https://appwrite.io/discord) for more help, ideas and discussions.
