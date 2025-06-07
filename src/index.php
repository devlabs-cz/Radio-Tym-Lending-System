<?php

use chillerlan\QRCode\QROptions;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use DI\Container;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/utils.php';


// LOAD ENVS

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../');
$dotenv->load();


// CONFIGURATION

$config['displayErrorDetails'] = true;
$config['addContentLengthHeader'] = false;
$config['db']['sqliteDbName'] = 'rtls.sqlite';

$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(new \Slim\Middleware\TrailingSlashMiddleware());

// Add settings to container
$container->set('settings', function() use ($config) {
    return $config;
});


// DEPENDENCIES

$container->set('logger', function ($c) {
    $logger = new \Monolog\Logger('fileLogger');
    $file_handler = new \Monolog\Handler\StreamHandler('../logs/rtls.log');
    $logger->pushHandler($file_handler);
    return $logger;
});

$container->set('db', function ($c) {
    $db = $c->get('settings')['db'];
    $pdo = new PDO('sqlite:'.$db['sqliteDbName']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
});

$container->set('view', function () {
    return new \Slim\Views\PhpRenderer('../templates/');
});

// MIDDLEWARE
// AUTH

$app->add(new Tuupola\Middleware\HttpBasicAuthentication([
    'users' => [
        $_ENV['AUTH_USER'] => $_ENV['AUTH_PASS'],
    ]
]));


// ROUTES

$app->get('/phpinfo', function (Request $request, Response $response, $args) {
    $response->getBody()->write(phpinfo());
    return $response;
})->setName('phpinfo');

$app->get('/management-radio', function (Request $request, Response $response, $args) use ($container,$app) {
    $db = $container->get('db');
    $view = $container->get('view');
    $query = $db->query('SELECT `id`,`radioId`, `name` FROM `radios` ORDER BY `radioId` ASC, `name` ASC');
    $radios = $query->fetchAll();

    return $view->render($response, 'management-radio.phtml', [
        'radios' => $radios,
        'router' => $app->getRouteCollector()->getRouteParser(),
    ]);
})->setName('management-radio');

$app->post('/add-new-radio', function (Request $request, Response $response) use ($container) {
    $db = $container->get('db');
    $logger = $container->get('logger');
    $parsedBody = $request->getParsedBody();

    $query = $db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-action-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');
    $query->execute([
        htmlspecialchars($parsedBody['radioId'], ENT_QUOTES),
        htmlspecialchars($parsedBody['name'], ENT_QUOTES),
        'ready',
        getNow(),
        NULL,
    ]);
    $logger->addInfo('Added radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));

    $routeParser = $request->getAttribute('routeParser') ?? $GLOBALS['app']->getRouteCollector()->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('radio-list'))->withStatus(302);
})->setName('add-new-radio');

$app->post('/import-radio', function (Request $request, Response $response) use ($container) {
    $db = $container->get('db');
    $logger = $container->get('logger');
    $parsedBody = $request->getParsedBody();
    $importRadio = $parsedBody['importRadio'];
    $explodedImportRadio = explode(PHP_EOL, $importRadio);

    foreach ($explodedImportRadio as $singleRadio) {
        $radioData = explode(';', $singleRadio);
        [$radioId, $name] = $radioData;
        if (!empty($radioId) && !empty($name)) {
            $query = $db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-action-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');
            $query->execute([
                trim(htmlspecialchars($radioId, ENT_QUOTES)),
                trim(htmlspecialchars($name, ENT_QUOTES)),
                'ready',
                getNow(),
                NULL,
            ]);
            $logger->addInfo('Added radio from import with ID '.htmlspecialchars($radioId, ENT_QUOTES));
        }
    }

    $routeParser = $request->getAttribute('routeParser') ?? $GLOBALS['app']->getRouteCollector()->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('radio-list'))->withStatus(302);
})->setName('import-radio');

$app->post('/delete-radio', function (Request $request, Response $response) use ($container) {
    $db = $container->get('db');
    $logger = $container->get('logger');
    $parsedBody = $request->getParsedBody();
    $query = $db->prepare('DELETE FROM `radios` WHERE `id` = ?');
    $query->execute([htmlspecialchars($parsedBody['id'], ENT_QUOTES)]);
    $logger->addInfo('Deleted radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));

    $routeParser = $request->getAttribute('routeParser') ?? $GLOBALS['app']->getRouteCollector()->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('management-radio'))->withStatus(302);
})->setName('delete-radio');

$app->post('/update-channel', function (Request $request, Response $response) use ($container) {
    $db = $container->get('db');
    $logger = $container->get('logger');
    $parsedBody = $request->getParsedBody();
    $query = $db->prepare('UPDATE `radios` SET `channel` = ? WHERE `id` = ?');
    $query->execute([
        htmlspecialchars($parsedBody['channel'], ENT_QUOTES),
        htmlspecialchars($parsedBody['radioId'], ENT_QUOTES),
    ]);
    $logger->addInfo('Changed channel for radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));

    $routeParser = $request->getAttribute('routeParser') ?? $GLOBALS['app']->getRouteCollector()->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('radio-list'))->withStatus(302);
})->setName('update-channel');

$app->post('/radio-action/{action}', function (Request $request, Response $response, $args) use ($container) {
    $db = $container->get('db');
    $logger = $container->get('logger');
    $argumentAction = htmlspecialchars($args['action'], ENT_QUOTES);
    $parsedBody = $request->getParsedBody();
    $id = htmlspecialchars($parsedBody['id'], ENT_QUOTES);
    $radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);

    switch ($argumentAction) {
        case 'lend':
            $borrower = htmlspecialchars($parsedBody['borrower'], ENT_QUOTES);
            $lastBorrower = htmlspecialchars($parsedBody['last-borrower'], ENT_QUOTES);
            if (empty($borrower) && !empty($lastBorrower)) {
                $borrower = $lastBorrower;
            }
            $query = $db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ?, `last-borrower` = ? WHERE `id` = ?');
            $query->execute(['lent', getNow(), $borrower, $id]);
            $logger->addInfo('Radio with ID '.$radioId.' is lent to '.$borrower.'.');
            break;
        case 'return':
            $query = $db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ? WHERE `id` = ?');
            $query->execute(['charging', getNow(), $id]);
            $logger->addInfo('Radio with ID '.$radioId.' is returned.');
            break;
        case 'charged':
            $query = $db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ? WHERE `id` = ?');
            $query->execute(['ready', getNow(), $id]);
            $logger->addInfo('Radio with ID '.$radioId.' is set as fully charged.');
            break;
        default:
            throw new Exception('Unknown radio-action argument');
    }

    $routeParser = $request->getAttribute('routeParser') ?? $GLOBALS['app']->getRouteCollector()->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('radio-list'))->withStatus(302);
})->setName('radio-action');

$app->get('/log', function (Request $request, Response $response) use ($container,$app) {
    $view = $container->get('view');
    $logData = file_get_contents('../logs/rtls.log');
    return $view->render($response, 'log.phtml', [
        'log' => explode(PHP_EOL, $logData),
        'router' => $app->getRouteCollector()->getRouteParser(),
    ]);
})->setName('log');

$app->post('/fast-return', function (Request $request, Response $response) use ($container) {
    $db = $container->get('db');
    $parsedBody = $request->getParsedBody();
    $query = $db->prepare('UPDATE `radios` SET `status` = "ready", `last-action-time` = ? WHERE `radioId` = ?');
    $query->execute([
        getNow(),
        $parsedBody['radioId'],
    ]);

    $routeParser = $request->getAttribute('routeParser') ?? $GLOBALS['app']->getRouteCollector()->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('radio-list'))->withStatus(302);
})->setName('fast-return');

$app->post('/fast-lent', function (Request $request, Response $response) use ($container) {
    $db = $container->get('db');
    $logger = $container->get('logger');
    $parsedBody = $request->getParsedBody();
    $radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);
    $borrower = htmlspecialchars($parsedBody['borrower'], ENT_QUOTES);

    $query = $db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ?, `last-borrower` = ? WHERE `radioId` = ?');
    $query->execute([
        'lent',
        getNow(),
        $borrower,
        $radioId,
    ]);
    $logger->addInfo('Radio with ID '.$radioId.' is lent to '.$borrower.'.');

    $routeParser = $request->getAttribute('routeParser') ?? $GLOBALS['app']->getRouteCollector()->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('radio-list'))->withStatus(302);
})->setName('fast-lent');

$app->get('/qr-generate', function (Request $request, Response $response) use ($container,$app) {
    $db = $container->get('db');
    $view = $container->get('view');
    $query = $db->query('SELECT * FROM `radios`');
    $radios = $query->fetchAll();
    $options = new QROptions([
        'eccLevel' => 0,
    ]);

    return $view->render($response, 'qr.phtml', [
        'base_uri' => $_ENV['BASE_URL'],
        'radios' => $radios,
        'qr_options' => $options,
        'router' => $app->getRouteCollector()->getRouteParser(),
    ]);
})->setName('qr-generate');

$app->get('/{radioId}', function (Request $request, Response $response, array $args) use ($container, $app) {
    $db = $container->get('db');
    $view = $container->get('view');
    $radioId = $args['radioId'];
    $query = $db->prepare('SELECT * FROM `radios` WHERE `radioId` = ?');
    $query->execute([$radioId]);

    return $view->render($response, 'fast.phtml', [
        'r' => $query->fetch(),
        'router' => $app->getRouteCollector()->getRouteParser(),
    ]);
})->setName('fast');

$app->get('/', function (Request $request, Response $response) use ($container, $app) {
    $db = $container->get('db');
    $view = $container->get('view');
    $logger = $container->get('logger');
    $query = $db->query('SELECT `id`,`radioId`, `name`, `status`, `last-action-time`, `channel`, `last-borrower` FROM `radios` ORDER BY `last-action-time` DESC');
    $radios = $query->fetchAll();
    $formTemplatesDirectory = 'radio-list-form-templates/';

    foreach ($radios as &$r) {
        switch ($r['status']) {
            case 'ready':
                $r['formTemplateLink'] = $formTemplatesDirectory.'lend.phtml';
                break;
            case 'lent':
                $r['formTemplateLink'] = $formTemplatesDirectory.'return.phtml';
                break;
            case 'charging':
                $query = $db->prepare('UPDATE `radios` SET `status` = "ready" WHERE `id` = ?');
                $query->execute([$r['id']]);
                $logger->addInfo('Radio with ID '.$r['radioId'].' is set as charged and ready.');
                $r['formTemplateLink'] = $formTemplatesDirectory.'lend.phtml';
                break;
        }
    }
    unset($r);

    $channels = range(1, 16);

    $statusDictionary = [
        'lent' => 'Vypůjčeno',
        'charging' => 'Nabíjí se',
        'ready' => 'Ready',
    ];

    $radioCounts = [
        'lent' => $db->query('SELECT COUNT(`id`) as count FROM `radios` WHERE status = "lent"')->fetch()['count'],
        'notLent' => $db->query('SELECT COUNT(`id`) as count  FROM `radios` WHERE status = "ready" OR status = "charging"')->fetch()['count'],
    ];

    return $view->render($response, 'radio-list.phtml', [
        'radios' => $radios,
        'channels' => $channels,
        'radioCounts' => $radioCounts,
        'statusDictionary' => $statusDictionary,
        'router' => $app->getRouteCollector()->getRouteParser(),
    ]);
})->setName('radio-list');


// FIRE!

try {
    $app->run();
} catch (Throwable $e) {
    echo 'Pardon, radio ztratilo spojení...';
    echo '<pre>' . htmlspecialchars($e, ENT_QUOTES) . '</pre>';
    die;
}
