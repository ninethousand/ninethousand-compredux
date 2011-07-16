<?php
//set up constants
foreach ( array(
    'LIBRARY_PATH'     => realpath(dirname(__FILE__) . '/src'),
    'AUTOLOADER_PATH'  => realpath(dirname(__FILE__) . '/src/Symfony/Component/ClassLoader/UniversalClassLoader.php'),
    'APPLICATION_ENV'  => getenv('APPLICATION_ENV') ?: 'production',
    'APPLICATION_PATH' => realpath(dirname(__FILE__))
) as $key => $val) { defined($key) || define($key, $val); }

//create the include path
set_include_path(implode(PATH_SEPARATOR, array(LIBRARY_PATH, APPLICATION_PATH)));

//load autoloader
require_once AUTOLOADER_PATH;

$loader = new Symfony\Component\ClassLoader\UniversalClassLoader();

$loader->registerNamespaces(array(
    'Compredux' => LIBRARY_PATH,
    'Zend'      => LIBRARY_PATH,
));
$loader->register();