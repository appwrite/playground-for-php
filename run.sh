git clone https://github.com/appwrite/appwrite
cd appwrite

docker-compose up -d

cd ~

git clone https://github.com/appwrite/playground-for-php

composer require 'appwrite/appwrite'

composer require jfcherng/php-color-output

