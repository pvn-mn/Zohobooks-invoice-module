<?php
/**
 * use as refernce example into config.php.
 * 
 * Dont share / commit this file - credentials
 */

// Zoho connection
define('ZOHO_CLIENT_ID', 'your_client_id');
define('ZOHO_CLIENT_SECRET', 'your_client_secret');
define('ZOHO_REFRESH_TOKEN', 'your_refresh_token');
define('ZOHO_ORG_ID', 'your_organization_id');


// App login (single user)
define('APP_USERNAME', 'invoice');
// Generate the hash once: create hash.php containing
// <?php echo password_hash('your-password-here', PASSWORD_DEFAULT);
// open it in the browser, paste the output below, then delete hash.php.
define('APP_PASSWORD_HASH', 'paste-the-hash-here');

