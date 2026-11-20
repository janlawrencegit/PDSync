<?php
// Loads the app's libraries for unit tests. These tests need no database: they cover the code that cleans, encrypts
// and checks data, and the form analyzer, against the built-in Revised 2026 template.
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/form-analyze.php';
