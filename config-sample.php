<?php
/**
 * An example configuration
 */
$repos = array(
	/* Github => SVN URL */
	'example/Test-Repo' => array(
		'secret'   => 'GITHUB SHARED SECRET',
		'svn_url'  => 'http://svn.example.com/test-repo/',
		'username' => 'your-username-here',
		'password' => 'your-password-here'
	)
);

// Whether to save a log of all operations, and if so, where to save it.
// This should be moved outside of your web-root
$save_log = __DIR__ . '/push.php.log';

// This should be moved outside of your web-root, or at the least, suffixed with a hard-to-guess key
$svns_location = __DIR__ . '/svns/';
