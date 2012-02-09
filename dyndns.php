<?php
/**
 * For updating a record with your dynamic ip.
 * Run with command: php -f dyndns.php
 */
include('lib/gratisdns.php');

$username = 'your-login';
$password = 'your-password';

$domain = 'test123.dk';
$host = 'home.test123.dk';

$ip = file_get_contents('http://whatismyip.org/');

$dns = new GratisDNS($username, $password);
$record = $dns->getRecordByDomain($domain, 'A', $host);
$dns->updateRecord($domain, $record['recordid'], 'A', $host, $ip, 3600);
