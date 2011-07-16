<?php
require_once 'Autoload.php';
$options = array(
    'home_dir'     => realpath(dirname(__FILE__)),
    'server'       => 'http://reports.MySite.com',
    'curl_options' => array(
                'CURLOPT_URL'       => 'http://reports.MySite.com/qlikview/index.htm',
                'CURLOPT_REFERER'   => 'http://reports.MySite.com/',
                'CURLOPT_USERPWD'   => '[myuser]:[mypasswd]',
    ),
);

$client = new Compredux\Client($options);
$client->request();
$client->initHeaders();

if (!$client->isType('html')) {
    echo $client->getContent();
    exit();
}
echo $client->getContent();
