<?php

// Roundcube as LPanel sets it up: webmail for every mailbox on this server,
// at /webmail/ on the User panel's port. Mailbox users sign in with their
// address and password; owners open it from the User panel with no password
// (the lpanel_sso plugin). The installer writes the secrets below and keeps
// them across upgrades.

declare(strict_types=1);

$config = [];

// Its own database, reached over MariaDB's socket.
$config['db_dsnw'] = 'mysql://lpanel_webmail:@DBPASS@@unix(/var/lib/mysql/mysql.sock)/lpanel_webmail';

// This server's Dovecot and Exim, over loopback. Dovecot takes a password
// without TLS from the machine itself; Exim offers a login only over TLS,
// and its certificate names the server, not "localhost".
$config['imap_host'] = 'localhost:143';
$config['smtp_host'] = 'tls://localhost:587';
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['smtp_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
$config['imap_cache'] = null;
$config['messages_cache'] = false;

// Logins are whole addresses, in lowercase.
$config['username_domain'] = '';
$config['login_lc'] = 2;
$config['login_autocomplete'] = 0;
$config['auto_create_user'] = true;

$config['des_key'] = '@DESKEY@';
$config['cipher_method'] = 'AES-256-CBC';
$config['use_https'] = true;
$config['ip_check'] = true;
$config['session_lifetime'] = 30;
$config['session_samesite'] = 'Lax';
// Its cookies stay under /webmail/: the panel and phpMyAdmin share the host.
$config['session_path'] = '/webmail/';
$config['x_frame_options'] = 'sameorigin';
$config['enable_installer'] = false;
$config['useragent'] = 'Webmail';
$config['product_name'] = 'Webmail';
$config['support_url'] = '';
$config['skin'] = 'elastic';
$config['display_product_info'] = 0;

$config['plugins'] = ['lpanel_sso', 'archive', 'zipdownload'];
$config['archive_mbox'] = 'Archive';
$config['drafts_mbox'] = 'Drafts';
$config['junk_mbox'] = 'Junk';
$config['sent_mbox'] = 'Sent';
$config['trash_mbox'] = 'Trash';
$config['create_default_folders'] = true;

$config['temp_dir'] = '/var/lib/lpanel-webmail/temp/';
$config['log_dir'] = '/var/lib/lpanel-webmail/logs/';
$config['log_driver'] = 'file';
$config['mime_types'] = '/etc/mime.types';
$config['max_message_size'] = '32M';
