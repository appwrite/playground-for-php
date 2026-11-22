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
* Create, List and Delete Indexes
* Delete an Indexed Column
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

### Indexes

Index work is asynchronous. `createIndex()`, `deleteIndex()` and
`deleteColumn()` are answered as accepted, and a worker does the schema work
afterwards, so reading the index back is the only report of how that work went.
Besides `available` there are two answers to be ready for:

* `failed` — the worker could not build the index. A unique index over rows that
  already repeat a value is the usual case, and only the worker finds that out. A
  failed index is a document in the table's index list, not nothing, and clearing
  it away is a `deleteIndex()` call like any other.
* `stuck` — the worker gave up on the delete. Before
  [appwrite/appwrite#15471](https://github.com/appwrite/appwrite/pull/15471) this
  was the answer for deleting an index the schema did not hold: an index that
  never finished creating had nothing to drop, the worker read that as a failure,
  and every retry took the same path — so the index could not be removed at all.
  The worker now treats a missing schema index as the state the delete asked for
  and drops the document anyway, which also means a `stuck` index left over from
  an older server goes on being asked again.

The same PR fixed the other end of it: dropping a column an index covers prunes
the column out of the index, and the length and the order at its position go with
it. They used to be pruned by value, so an index whose lengths repeat lost every
length equal to the removed one, leaving the index covering more columns than it
had lengths for and the table refusing later column changes with "Invalid index
lengths".

`manageIndexes()` in `playground.php` builds an index, clears away one the worker
could not build, and drops an indexed column.

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
without hiding the rows (`tests/list-total.php`). Last it drives the index
routes, checking that an index the worker could not build reads as `failed`, that
one `deleteIndex()` clears it away, and that dropping an indexed column leaves
the index carrying one length and one order per remaining column — against a stub
behaving as the worker does since appwrite/appwrite#15471, and against one
behaving as it did before, so the checks are known to notice
(`tests/index-cleanup.php`). No Appwrite instance and no network access are
needed.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Security

For security issues, kindly email us [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

## Follow Us

Join our growing community around the world! Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) or join our [Discord Server](https://appwrite.io/discord) for more help, ideas and discussions.
