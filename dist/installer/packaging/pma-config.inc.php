<?php

// phpMyAdmin as LPanel sets it up: one server (this one's MariaDB, over its
// socket), signed in to only through the User panel (lpanel-signon.php).
// The installer writes the secret below.

declare(strict_types=1);

$cfg['blowfish_secret'] = sodium_hex2bin('@BLOWFISH@');

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'LPanelPMA';
$cfg['Servers'][$i]['SignonCookieParams'] = ['lifetime' => 0, 'path' => '/phpmyadmin/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
$cfg['Servers'][$i]['SignonURL'] = '/panel/databases';
$cfg['Servers'][$i]['LogoutURL'] = '/panel/databases';
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['connect_type'] = 'socket';
$cfg['Servers'][$i]['socket'] = '/var/lib/mysql/mysql.sock';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = false;
$cfg['Servers'][$i]['hide_db'] = '^(information_schema|performance_schema|mysql|sys)$';

$cfg['AllowArbitraryServer'] = false;
$cfg['ShowCreateDb'] = false;
$cfg['ShowServerInfo'] = false;
$cfg['ShowPhpInfo'] = false;
$cfg['ShowChgPassword'] = false;
$cfg['VersionCheck'] = false;
$cfg['SendErrorReports'] = 'never';
$cfg['CheckConfigurationPermissions'] = false;
$cfg['TempDir'] = '/var/lib/lpanel-pma/tmp';
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['LoginCookieValidity'] = 3600;
$cfg['ThemeDefault'] = 'pmahomme';
