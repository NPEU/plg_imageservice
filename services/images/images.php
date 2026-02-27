<?php
declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

// Set up Joomla User stuff needs to resolve to /var/www/html/{site}}/public/":
$base_path = realpath(dirname(__DIR__, 5));
if ($base_path === false || !is_dir($base_path)) {
    throw new \RuntimeException('Invalid Joomla base path');
}
define('JPATH_BASE', $base_path);
define('_JEXEC', 1);

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

// Boot the DI container
$container = Factory::getContainer();


$session_alias          = 'session.web';
$session_suffix         = 'web.site';
// Alias the session service key to the web session service.
$container->alias(\Joomla\Session\SessionInterface::class, 'session.' . $session_suffix);

$app = $container->get('Joomla\CMS\Application\SiteApplication');
#$app->initialise();
Factory::$application = $app;

// Safe plugin load
$plugin = PluginHelper::getPlugin('system', 'imageservice');

if (is_array($plugin)) {
    $plugin = $plugin[0] ?? null;
}

$params = new Registry;
if (!empty($plugin) && isset($plugin->params)) {
    $raw = $plugin->params;
    if (is_string($raw)) {
        $params->loadString($raw);
    } else {
        $params->loadArray((array) $raw);
    }
}

$config = $app->getConfig();
$cache_root = $config->get('cache_path', JPATH_ROOT . '/cache') . '/' . $params->get('cache_folder', 'imageservice');

$dir_perm = 0771;
$file_perm = octdec($params->get('upload_file_permissions', false));
$dir_own  = $params->get('upload_file_owner', false);
$dir_grp  = $params->get('upload_file_group', false);

require_once __DIR__ . '/ImageService.php';

try {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
    $requestPath  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $size = isset($_GET['s']) ? (int) $_GET['s'] : 0;
    $min  = (isset($_GET['m']) && $_GET['m'] === '1');

    $svc = new ImageService($documentRoot);
    // optional configs:
    $svc->setCacheRoot($cache_root);
    $svc->setPermissions($dir_perm, $file_perm, $dir_own, $dir_grp);

    $resp = $svc->processRequest($requestPath, $size, $min);

    // send headers
    foreach ($resp['headers'] as $k => $v) {
        header($k . ': ' . $v);
    }
    // send body
    fpassthru($resp['body_stream']);
    fclose($resp['body_stream']);
} catch (Exception $e) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    // optionally log $e->getMessage()
    exit;
}