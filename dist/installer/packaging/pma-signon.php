<?php

// LPanel's single sign-on into phpMyAdmin (installed as lpanel-signon.php
// beside phpMyAdmin's index.php).
//
// The panel's "Open phpMyAdmin" button asks the agent for a fresh login for
// the account, writes it to a one-time ticket file and sends the browser
// here with the ticket's name. This script reads the ticket, deletes it at
// once (so it works a single time), checks it has not expired, and hands the
// login to phpMyAdmin through its signon session. The password never
// appears in a URL, a cookie or the browser.

declare(strict_types=1);

const TICKETS = '/var/lib/lpanel-pma/tickets';
const SESSION = 'LPanelPMA';
const PANEL = '/panel/databases';

function back(): never
{
    header('Location: ' . PANEL, true, 303);
    exit;
}

$token = is_string($_GET['ticket'] ?? null) ? $_GET['ticket'] : '';

if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
    back();
}

$path = TICKETS . '/' . $token;
$text = @file_get_contents($path);
@unlink($path);

$ticket = is_string($text) ? json_decode($text, true) : null;

if (!is_array($ticket) || !is_string($ticket['user'] ?? null) || !is_string($ticket['password'] ?? null) || (int) ($ticket['expires'] ?? 0) < time()) {
    back();
}

session_set_cookie_params(['lifetime' => 0, 'path' => '/phpmyadmin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_name(SESSION);
session_start();
session_regenerate_id(true);
$_SESSION['PMA_single_signon_user'] = $ticket['user'];
$_SESSION['PMA_single_signon_password'] = $ticket['password'];
$_SESSION['PMA_single_signon_host'] = 'localhost';
$_SESSION['PMA_single_signon_HMAC_secret'] = bin2hex(random_bytes(32));
session_write_close();

header('Cache-Control: no-store');
header('Location: index.php?route=/', true, 303);
