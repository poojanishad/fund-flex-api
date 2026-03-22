<?php

$password = 'pooja123'; // change this to your password

$hash = password_hash($password, PASSWORD_BCRYPT);

echo PHP_EOL;
echo "Password: " . $password . PHP_EOL;
echo "Hash:     " . $hash . PHP_EOL;
echo "Length:   " . strlen($hash) . PHP_EOL;
echo "Verify:   " . (password_verify($password, $hash) ? 'TRUE - works!' : 'FALSE - broken!') . PHP_EOL;
echo PHP_EOL;
echo "=====================================================" . PHP_EOL;
echo "Copy this EXACT line into your .env.local file:" . PHP_EOL;
echo "=====================================================" . PHP_EOL;
echo "API_USER_PASSWORD_HASH='" . $hash . "'" . PHP_EOL;
echo "=====================================================" . PHP_EOL;
