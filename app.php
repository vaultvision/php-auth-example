<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';


// Loads the .env file.
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();


// Configuration.
$CONFIG = [
    'BASE_URL' => $_ENV['BASE_URL'] ?? 'http://localhost:8090',
    'STATIC_DIR' => $_ENV['STATIC_DIR'] ?? 'static',
    'SESSION_SECRET' => $_ENV['SESSION_SECRET'],
    'VV_ISSUER_URL' => $_ENV['VV_ISSUER_URL'],
    'VV_CLIENT_ID' => $_ENV['VV_CLIENT_ID'],
    'VV_CLIENT_SECRET' => $_ENV['VV_CLIENT_SECRET'],
];


// Setup OIDC
use Jumbojett\OpenIDConnectClient;

$oidcClient = new OpenIDConnectClient(
    $CONFIG['VV_ISSUER_URL'],
    $CONFIG['VV_CLIENT_ID'],
    $CONFIG['VV_CLIENT_SECRET'],
);

$oidcClient->setRedirectURL($CONFIG['BASE_URL'] . '/auth/callback');
$oidcClient->setResponseTypes(array('code'));
$oidcClient->addScope(array('openid email profile'));


// Setup router
$router = new \Bramus\Router\Router();
$router->setBasePath('/');

// Start the session before all routes.
$router->before('GET|POST|PUT|DELETE', '/.*', function() {
    session_start();
});

$router->get('/', function() use($CONFIG) {
    $tplCtx = [
        'oidc' => array(
            'issuer_url' => $CONFIG['VV_ISSUER_URL'],
        ),
    ];

    if(key_exists('user', $_SESSION)) {
        $tplCtx['user'] = $_SESSION['user'];
        $tplCtx['user_json'] = json_encode($tplCtx['user'], JSON_PRETTY_PRINT);
    }

    // We just use php include statement to render our index template.
    include 'views/index.php';
});

// /login just redirects to /auth/login. But it could contain any app specific
// logic or a confirmation page that shows a login button.
$router->get('/login', function() use ($oidcClient) {
    header('Location: /auth/login');
});

// /auth/login kicks off the OIDC flow by redirecting to Vault Vision. Once
// authentication is complete the user will be returned to /auth/callback.
$router->get('/auth/login', function() use ($oidcClient) {
    if(!$oidcClient->authenticate()) {
        // On failure this library redirects the browser.
    }
});

// Once Vault Vision authenticates a user they will be sent here to complete
// the OIDC flow.
$router->get('/auth/callback', function() use ($oidcClient) {
    if(!$oidcClient->authenticate()) {
        // On failure this library redirects the browser.
    }

    $info = $oidcClient->requestUserInfo();
    $_SESSION['user'] = $info;

    header('Location: /');
});

// Logout clears the cookies and then sends the users to Vault Vision to clear
// the session, then Vault Vision will redirect the user to /auth/logout.
$router->get('/logout', function() use($CONFIG) {
    $url = $CONFIG['VV_ISSUER_URL'] . '/logout?' . http_build_query(array(
        'client_id' => $CONFIG['VV_CLIENT_ID'],
        'return_to' => $CONFIG['BASE_URL'] . '/auth/logout',
    ));
    header('Location: ' . $url);
});

// Once Vault Vision clears the users session, they return to this route.
$router->get('/auth/logout', function() {
    $_SESSION = array();
    session_destroy();

    header('Location: /');
});

// /settings just redirects to /auth/settings. But it could contain any app 
// specific logic or a confirmation page that shows a settings button.
$router->get('/settings', function() {
    header('Location: /auth/settings');
});

// /auth/settings redirects to the Vault Vision settings page so users can
// manage their email, password, social logins, webauthn credentials and more.
$router->get('/auth/settings', function() use($oidcClient) {
    $oidcClient->addAuthParam(array('prompt' => 'settings'));

    // This will call the private oidcClient->requestAuthorization method
    // if no query params are set.
    $oidcClient->authenticate();
});

// Basic static routes for this example, you wouldn't use these in a
// production env.
$router->get('/static/js/bootstrap.bundle.min.js', function() {
    header('Content-Type: application/javascript; charset=UTF-8');
    echo file_get_contents('static/js/bootstrap.bundle.min.js');
});

$router->get('/static/css/bootstrap.min.css', function() {
    header('Content-Type: text/css; charset=UTF-8');
    echo file_get_contents('static/css/bootstrap.min.css');
});

$router->get('/static/img/favicon_root.png', function() {
    header('Content-Type: image/png');
    echo file_get_contents('static/img/favicon_root.png');
});

$router->get('/static/img/vault-vision-just-triad-dark-blue.svg', function() {
    header('Content-Type: image/svg+xml');
    echo file_get_contents('static/img/vault-vision-just-triad-dark-blue.svg');
});

// Runs the router.
$router->run();