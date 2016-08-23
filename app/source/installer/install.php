<?php
namespace Postleaf;
require_once dirname(dirname(__DIR__)) . '/source/runtime.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Deny if already installed or the request is invalid
if(Postleaf::isInstalled() || $_REQUEST['cmd'] !== 'install') {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

// Send a JSON response
header('Content-Type: application/json');

// Force slug syntax for username
$_REQUEST['username'] = Postleaf::slug($_REQUEST['username']);

// Force prefix to use valid chars
$_REQUEST['db-prefix'] = preg_replace('/[^A-Za-z_-]/', '_', $_REQUEST['db-prefix']);

// Set defaults for missing fields
if(empty($_REQUEST['db-host'])) $_REQUEST['db-host'] = 'localhost';
if(empty($_REQUEST['db-prefix'])) $_REQUEST['db-prefix'] = 'postleaf_';
if(empty($_REQUEST['db-port'])) $_REQUEST['db-port'] = '3306';

// Check for errors
$invalid = [];
// Note: we don't check for a database password since some dev environments leave it blank
foreach(['name', 'email', 'username', 'password', 'db-user', 'db-database'] as $field) {
    if(empty($_REQUEST[$field])) $invalid[] = $field;
}
if(count($invalid)) {
    exit(json_encode([
        'success' => false,
        'invalid' => $invalid,
        'message' => 'Please correct the highlighted errors.'
    ]));
}
if(mb_strlen($_REQUEST['password']) < 8) {
    exit(json_encode([
        'success' => false,
        'invalid' => ['password'],
        'message' => 'Passwords need to be at least eight characters.'
    ]));
}
if(!Postleaf::isValidEmail($_REQUEST['email'])) {
    exit(json_encode([
        'success' => false,
        'invalid' => ['email'],
        'message' => 'Please enter a valid email address.'
    ]));
}

// Test database connection
try {
    Database::connect([
        'host' => $_REQUEST['db-host'],
        'port' => $_REQUEST['db-port'],
        'database' => $_REQUEST['db-database'],
        'user' => $_REQUEST['db-user'],
        'password' => $_REQUEST['db-password'],
        'prefix' => $_REQUEST['db-prefix']
    ], [
        // Set a shorter timeout in case the host is entered incorrectly
        \PDO::ATTR_TIMEOUT => 5
    ]);
} catch(\Exception $e) {
    switch($e->getCode()) {
        case Database::AUTH_ERROR:
            $message = 'The database rejected this user or password. Make sure the user exists and has access to the specified database.';
            $invalid = ['db-user', 'db-password'];
            break;
        case Database::DOES_NOT_EXIST:
            $message = 'The specified database does not exist.';
            $invalid = ['db-database'];
            break;
        case Database::TIMEOUT:
            $message = 'The database is not responding. Is the host correct?';
            $invalid = ['db-host'];
            break;
        default:
            $message = $e->getMessage();
            $invalid = ['db-host', 'db-user', 'db-password', 'db-database'];
    }

    exit(json_encode([
        'success' => false,
        'invalid' => $invalid,
        'message' => $message
    ]));
}

// Read/write test for special folders
foreach(['backups', 'content', 'content/cache', 'content/themes', 'content/uploads'] as $folder) {
    // Create the folder if it doesn't exist
    if(!Postleaf::makeDir(Postleaf::path($folder))) {
        exit(json_encode([
            'success' => false,
            'message' =>
                "Postleaf could not create the /$folder folder. Please make sure the parent " .
                "directory is writeable or create it manually and try again."
        ]));
    }

    // Create a test file
    $file = Postleaf::path($folder, 'postleaf-read-write-test-' . time() . '.txt');
    $test_string = 'This is a test file generated by Postleaf. You can safely delete it.';

    // Write
    $result = file_put_contents($file, $test_string);
    if(!$result) {
        exit(json_encode([
            'success' => false,
            'message' =>
                "Postleaf needs write access to /$folder. Please make sure this directory is " .
                "writeable and try again."
        ]));
    }

    // Read
    $result = file_get_contents($file);
    if($result !== $test_string) {
        exit(json_encode([
            'success' => false,
            'message' =>
                "Postleaf needs read access to /$folder. Please make sure this directory is " .
                "readable and try again."
        ]));
    }

    // Delete
    unlink($file);
}

// Create .htaccess if it doesn't already exist
if(!file_exists(Postleaf::path('.htaccess'))) {
    if(!file_put_contents(
        Postleaf::path('.htaccess'),
        file_get_contents(Postleaf::path('source/defaults/default.htaccess'))
    )) {
        exit(json_encode([
            'success' => false,
            'message' =>
                'Unable to create /.htaccess. Make sure the directory is writeable or create the ' .
                'file yourself by copying it from /source/defaults/default.htaccess and try again.'
        ]));
    }
}

// Create database.php from default.database.php
$db_pathname = Postleaf::path('database.php');
$db_config = file_get_contents(Postleaf::path('source/defaults/default.database.php'));
$db_config = str_replace('{{host}}', $_REQUEST['db-host'], $db_config);
$db_config = str_replace('{{port}}', $_REQUEST['db-port'], $db_config);
$db_config = str_replace('{{database}}', $_REQUEST['db-database'], $db_config);
$db_config = str_replace('{{user}}', $_REQUEST['db-user'], $db_config);
$db_config = str_replace('{{password}}', $_REQUEST['db-password'], $db_config);
$db_config = str_replace('{{prefix}}', $_REQUEST['db-prefix'], $db_config);
if(!file_put_contents($db_pathname, $db_config)) {
    exit(json_encode([
        'success' => false,
        'message' =>
            'Unable to create /database.php. Make sure the directory is writeable or create the ' .
            'file yourself by copying it from /source/defaults/default.database.php and try again.'
    ]));
}

// Initialize database tables
try {
    Database::resetTables();
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    exit(json_encode([
        'success' => false,
        'message' => 'Unable to create the database schema: ' . $e->getMessage()
    ]));
}

// Insert default settings
Setting::add('auth_key', Postleaf::randomBytes(32)); // create a unique and secure auth key
Setting::add('allowed_upload_types', 'pdf,doc,docx,ppt,pptx,pps,ppsx,odt,xls,xlsx,psd,txt,md,csv,jpg,jpeg,png,gif,ico,svg,mp3,m4a,ogg,wav,mp4,m4v,mov,wmv,avi,mpg,ogv,3gp,3g2');
Setting::add('cover', 'source/assets/img/leaves.jpg');
Setting::add('default_content', 'Start writing here...');
Setting::add('default_title', 'Untitled Post');
Setting::add('favicon', 'source/assets/img/logo-color.png');
Setting::add('foot_code', '');
Setting::add('frag_admin', 'admin');
Setting::add('frag_author', 'author');
Setting::add('frag_blog', 'blog');
Setting::add('frag_feed', 'feed');
Setting::add('frag_page', 'page');
Setting::add('frag_search', 'search');
Setting::add('frag_tag', 'tag');
Setting::add('hbs_cache', 'on');
Setting::add('head_code', '');
Setting::add('homepage', '');
Setting::add('language', 'en-us');
Setting::add('logo', 'source/assets/img/logo-color.png');
Setting::add('navigation', '[{"label":"Home","link":"/"}]');
Setting::add('posts_per_page', '10');
Setting::add('tagline', 'Go forth and create!');
Setting::add('theme', 'range');
Setting::add('timezone', 'America/New_York');
Setting::add('title', 'A Postleaf Blog');
Setting::add('twitter', '');
Setting::add('password_min_length', '8');

// Insert owner
try {
    User::add($_REQUEST['username'], [
        'name' => $_REQUEST['name'],
        'email' => $_REQUEST['email'],
        'password' => $_REQUEST['password'],
        'role' => 'owner'
    ]);
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    switch($e->getCode()) {
        case User::INVALID_SLUG:
            $invalid = ['username'];
            $message = 'This username is reserved and cannot be used.';
            break;
        default:
            $invalid = null;
            $message = 'Unable to create the owner user: ' . $e->getMessage();
    }

    exit(json_encode([
        'success' => false,
        'invalid' => $invalid,
        'message' => $message
    ]));
}

// Insert default tag
try {
    Tag::add('getting-started', [
        'name' => 'Getting Started',
        'description' => 'This is a sample tag. You can delete it, rename it, or do whatever you want with it!'
    ]);
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    exit(json_encode([
        'success' => false,
        'message' => 'Unable to insert default tags: ' . $e->getMessage()
    ]));
}

// Insert initial posts
try {
    Post::add('welcome-to-postleaf', [
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'Welcome to Postleaf',
        'content' => file_get_contents(Postleaf::path('source/defaults/post.welcome.html')),
        'image' => 'source/assets/img/leaves.jpg',
        'status' => 'published',
        'tags' => ['getting-started'],
        'sticky' => true
    ]);
    Post::add('the-editor', [
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'The Editor',
        'content' => file_get_contents(Postleaf::path('source/defaults/post.editor.html')),
        'image' => 'source/assets/img/sunflower.jpg',
        'status' => 'published',
        'tags' => ['getting-started']
    ]);
    Post::add('themes-and-plugins', [
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'Themes & Plugins',
        'content' => file_get_contents(Postleaf::path('source/defaults/post.themes.html')),
        'image' => 'source/assets/img/autumn.jpg',
        'status' => 'published',
        'tags' => ['getting-started']
    ]);
    Post::add('help-and-support', [
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'Help & Support',
        'content' => file_get_contents(Postleaf::path('source/defaults/post.support.html')),
        'image' => 'source/assets/img/ladybug.jpg',
        'status' => 'published',
        'tags' => ['getting-started']
    ]);
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    exit(json_encode([
        'success' => false,
        'message' => 'Unable to insert default posts: ' . $e->getMessage()
    ]));
}

// Log the owner in
Session::login($_REQUEST['username'], $_REQUEST['password']);

// Send response and redirect to the editor
exit(json_encode([
    'success' => true,
    'redirect' => Admin::url()
]));
