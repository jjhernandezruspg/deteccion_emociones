<?php
$host = 'localhost';
$db = 'emotions';
$user = 'dbae';
$pass = 'l2h1eTkfkbWKFmaGwX1A';

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $mysqli->connect_error]));
}
?>