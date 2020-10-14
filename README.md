# playground-for-php

This is Php playground for [appwrite](https://www.appwrite.io).

To install it and run in your local environment do these commands:

```
git clone https://github.com/appwrite/appwrite
cd appwrite

docker-compose up -d

cd ~
```

after doing that the appwrite server is up and testing of php playground need to do by these command:

```
git clone https://github.com/appwrite/playground-for-php

composer install

php app.php

```

And now you are now succesfully ran the php playground.
I add a run.sh for all users of mac,linux,windows under wsl to automate all the commands required just once.

```
sudo chmod +x run.sh
./run.sh

```
After that you need to edit the `globel.inc.php` by entering the api key and project id to run it successfully.
