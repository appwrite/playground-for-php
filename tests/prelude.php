<?php
// Prepended to playground.php by tests/smoke.php so the run targets the stub
// server instead of a real Appwrite instance.
\define('ENDPOINT', \getenv('APPWRITE_ENDPOINT') ?: 'http://127.0.0.1:8099/v1');
\define('PROJECT_ID', 'playground');
\define('API_KEY', 'smoke-test-key');
