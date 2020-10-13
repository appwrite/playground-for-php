# playground-for-php
THis is Php playground for [appwrite](https://www.appwrite.io).

To install it and run in your local enviourment do these commands:
```
git clone https://github.com/appwrite/appwrite
cd appwrite

docker-compose up -d

cd ~
```
after doing that the appwrite server is up and testing of php playground need to do by these command:

```
git clone https://github.com/appwrite/playground-for-php

composer require 'appwrite/appwrite'

composer require jfcherng/php-color-output

php app.php

```

And now you are now succesfully ran the php playground.
I add a run.sh for linux users to automate all the commands required just once.

```
sudo chmod +x run.sh
./run.sh

```