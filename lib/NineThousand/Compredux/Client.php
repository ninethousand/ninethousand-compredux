<?php
namespace NineThousand\Compredux;
/**
 * CompRedux main class file.
 * This file contains the codebase of the main Compredux engine.
 * @author Jesse Greathouse
 * @version 1.0
 * @package Compredux
 */
 
/**
 * Client class
 * You can pass an option array to the constructor
 * The options array will merge and overwrite the default options
 * $client = new NineThousand\Compredux\Client($options);
 * @package Compredux
 */
use Zend\Dom\Query;
class Client
{

    /**
     * Contains all pieces of the response from the curl request
     * @var array
     */
    protected $response = array();

    /**
     * Container for the curl_info() value
     * @var array
     */
    protected $info = array();

    /**
     * Container for the curl handler resource
     * @var array
     */
    protected $ch;

    /**
     * Container for the DOM object
     * @var array
     */
    protected $dom;

    /**
     * all configurable options
     * @var array
     */
    protected $options = array(
        'mode'            => 'standard',
        'client_hostname' => null,
        'server'          => 'http://www.google.com',
        'controller'      => '/compredux',
        'curl_options'    => array(
            'CURLOPT_CONNECTTIMEOUT' => 20,
            'CURLOPT_HEADER'         => true,
            'CURLOPT_FAILONERROR'    => true,
            'CURLOPT_FILETIME'       => true,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_SSL_VERIFYHOST' => false,
            'CURLOPT_SSL_VERIFYPEER' => false,
        ),
        'curl_headers'    => array(
            'X-Vendor-Id: Compredux',
        ),
        'illegal_headers' => array(
            'Transfer-Encoding',
            'Set-Cookie',
        ),
        'action_map' => array(
            'gallery' => 'http://www.flickr.com/groups/best100only/pool/',    
        )
    );

    /**
     * Constructor
     *
     * @param  null|array $options
     * @return void
     */
    public function __construct($options = array()) 
    {
        $this->setOptions($options);
        $dom = new Query;
        $this->setDom($dom);
        $this->prepare();
    }

    /**
     * Executes http request via Curl
     *
     * @param  bool $cache
     */
    public function request($cache = true)
    {
        
        if ($this->isAjax()) {
            $this->options['curl_headers'][] = 'X-Requested-With: XMLHttpRequest';
            $cache = false;
        }

        if (($post = $this->getPost()) !== null) {
            $cache = false;
            $this->setPost($post);
        }

        if ($cache && file_exists($this->getCachePath($this->getRemoteUrl()))) {
            $this->response = $this->getCache();
            $cache = false;
        } else {
            $this->setCookie();
            $this->setOption('CURLOPT_HTTPHEADER', $this->options['curl_headers']);
            $this->response['raw'] = curl_exec($this->ch);
            $this->response['header'] = curl_getinfo($this->ch);
            $this->response['error_id'] = curl_errno($this->ch);
            $this->response['error'] = curl_error($this->ch);
            $this->response['content'] = substr($this->response['raw'], $this->response['header']['header_size']);
        }

        $header_size = $this->response['header']['header_size'];
        $header = substr($this->response['raw'], 0, $header_size);
        if ($this->response['header']['redirect_count'] > 0){
                $cache = false;
                preg_match_all("/location:(.*?)\n/is", $header, $locations);
                $destination = trim(end($locations[1]));
                
                if ($this->options['mode'] == 'cherrypick') {
                    
                    $options = array(
                       'curl_options' => array(
                            'CURLOPT_URL' => trim(end($locations[1])),
                        ),
                    );
                    $options = $this->mergeConfig($this->options, $options);
                    $client = new self($options);
                    $client->request();
                    $this->response = $client->getResponse();
                    
                    $header_size = $this->response['header']['header_size'];
                    $header = substr($this->response['raw'], 0, $header_size);
                } else {
                    if (strpos($destination, 'http') !== false) {
                        $urlparts = parse_url($destination);
                        $destination = $urlparts['path'];
                        if (!empty($urlparts['query'])) {
                           $destination .= '?'.$urlparts['query'];
                        }
                    }
                        
                    $destination = $this->getHost() . $destination;
                    header("Location: " . $destination);
                    exit();
                }
                
        }

        $this->response['headers'] = $this->stripIllegalHeaders(explode("\n", $header));

        if ($this->isType('html')) {
            $cache = false;
            $this->response['content'] = $this->postProcess($this->response['content']);
        }
        
        //dont cache the page if errors were returned
        if ($this->hasErrors()) {
            $cache = false;
        }

        $dom = new Query($this->response['content']);
        $this->setDom($dom);
        
        if ($cache) {
            $this->setCache($this->response);
        }

        curl_close($this->ch);
    }

    /**
     * Infer an action from the url string
     *
     * @return false|string
     */
    public function getAction()
    {
        $uri = $this->stripController($_SERVER['REQUEST_URI']);
        $pieces = explode('/', $uri);
        if (count($pieces)>0) {
            return $pieces[1];
        } else {
            return false;
        }
    }

    /**
     * Get the action key => value store
     *
     * @return array
     */
    public function getActionMap()
    {
        return $this->options['action_map'];
    }

    /**
     * Gets any cached content related to the request
     *
     * @return array
     */
    public function getCache() 
    {
        $path = $this->getCachePath($this->getRemoteUrl());
        return unserialize(file_get_contents($path));
    }

    /**
     * Saves the cached content in the request
     */
    public function setCache()
    {
        $path = $this->getCachePath($this->getRemoteUrl());
        $fh = fopen($path, "w");
        fwrite($fh, serialize($this->response));
        fclose($fh);
    }

    /**
     * Transpose the path to the cached content based on the url
     * @param  string $url
     * @return string
     */
    public function getCachePath($url)
    {
        $file = substr(str_replace('/', '-', $this->convertUriToPath($url)), 1);
        return $this->options['home_dir'] . '/cache/' . $file;
    }

    /**
     * Get the content of the request
     * @param  string null|string|array $selector
     * @return string
     */
    public function getContent($selector = null, $exclude = null)
    {
        if (isset($this->response['content'])) {
            if ($this->isType('html') && ($selector != null || $exclude != null)) {
                $content = $this->response['content'];
            
                if ($selector != null) {
                    if (!is_array($selector)) {
                        $selector = array($selector);
                    }
                    $composite = new \DomDocument;
                    foreach ($selector as $selection) {
                        $results = $this->dom->execute($selection);
                        foreach ($results as $result) {
                            $composite->appendChild($composite->importNode($result, true));
                        }
                        unset($results);
                    }
                    unset($selector);

                    $content = $composite->saveHTML();
                }

                if ($exclude != null) {
                    if (!is_array($exclude)) {
                        $exclude = array($exclude); 
                    }
                    
                    foreach ($exclude as $antiSelection) {
                        $results = $this->dom->execute($antiSelection);
                        foreach ($results as $result) {
                            $antiComposite = new \DomDocument;
                            $antiComposite->appendChild($antiComposite->importNode($result, true));
                            $content = str_replace(trim($antiComposite->saveHTML()), '', $content);
                            unset($antiComposite);
                        }
                        unset($results);
                    }
                }
                return $content;
            } else {
                return $this->response['content'];
            }
        } else {
            return null;
        }
    }

    /**
     * Gets the name of the controller
     * 
     * @return string
     */
    public function getController()
    {
        return $this->options['controller'];
    }

    /**
     * Handles the cookies during the request
     * 
     */
    function setCookie() 
    {   
        $dir = $this->options['home_dir'] . '/sessions';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        if (!isset($_COOKIE['CSid'])) {
            $CSid = md5(date('U'));
            setcookie('CSid', $CSid, 0, '/');
        } else {
            $CSid = $_COOKIE['CSid'];
        }
        $ckfile = $dir.'/'.$CSid;
        $this->setOption('CURLOPT_COOKIEJAR', $ckfile);
        $this->setOption('CURLOPT_COOKIEFILE', $ckfile);
    }

    /**
     * Gets the current version of the script
     * 
     * @return false|string
     */
    public function getCurrentRevision() 
    {
        $file = $this->options['home_dir'] . '/cache/REVISION';
        if (file_exists($file)) {
            $fh = fopen($file, 'r');
            $contents = fread($fh, filesize($file));
            fclose($fh);
            return $contents;
        } else {
            return false;
        }
    }
    
    /**
     * Sets the current revision of the script
     * @param string $content
     *
     */
    public function setCurrentRevision($content) 
    {
        prepare();
        $file = $this->options['home_dir'] . '/cache/REVISION';
        $fh = fopen($file, "w");
        fwrite($fh, $content);
        fclose($fh);
    }

    /**
     * Gets the dom container
     * 
     * @return \Zend\Dom\Query
     */
    public function getDom()
    {
        return $this->dom;
    }
    
    /**
     * Gets the errors returned from the request
     * 
     * @return string
     */
    public function getErrors()
    {
        if (!empty($this->response['error'])) {
            return $this->response['error'];
        } else {
            return null
        }
    }

    /**
     * Sets the dom container
     * @param \Zend\Dom\Query $dom
     *
     */
    public function setDom(Query $dom)
    {   
        $this->dom = $dom;
    }

    /**
     * Gets the headers from the request
     * 
     * @return array
     */
    public function getHeaders() 
    {
        return $this->response['headers'];
    }

    /**
     * sets the curl resource handler
     * @param resource $ch
     *
     */
    public function setHandler($ch = null) 
    {
        unset($this->ch);
        if ($ch == null) {
            $ch = curl_init();
        }
        $this->ch = $ch;
    }

    /**
     * Gets the current host
     * 
     * @return string
     */
    public function getHost() 
    {
        if ($this->options['client_hostname'] != null) {
            $host = $this->options['client_hostname'];
        } else {
            $host = $_SERVER['SERVER_NAME'];
        }
        return 'http://'.$host.'/'.$this->getController();
    }

    /**
     * Gets the latest revision from the remote server
     * 
     * @return bool|string
     */
    public function getLatestRevision() 
    {
        $feed = $this->getServer().'/revision';
        $client = new self(array('curl_options' => array('CURLOPT_URL' => $feed)));
        $response = $client->getResponse();
	    if (isset($response['content']) && $response['content'] != "") {
	        return $response['content'];
        } else {
            return 0;
        }
    }
    
    /**
     * Gets the content of the info container
     * 
     * @return array
     */
    public function getInfo() {
        if (empty($this->info)) {
            $this->info = curl_getinfo($this->ch);
        }
        return $this->info;
    }

    /**
     * Sets an option to the curl resource
     * @param string $option
     * @param string $value
     * 
     */
    public function setOption($option, $value) 
    {
        curl_setopt($this->ch, constant($option), $value);
    }

    /**
     * Gets the current options
     * 
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Sets an array of options
     * @param array $options
     * @param null|resource $ch 
     * 
     */
    public function setOptions($options = array(), $ch = null)
    {
        $this->setHandler($ch);
        $this->options = $this->mergeConfig($this->options, $options);
        //when the default and user are merged, then merge the dynamic server options
        $this->options = $this->mergeConfig($this->getWebserverConfigs(), $this->options);
        foreach ($this->options['curl_options'] as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * Gets the global _POST params
     *  
     * @return null|$_POST
     */
    public function getPost() 
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            return $_POST;
        } else {
            return null;
        }
    }

    /**
     * Sets up the curl resource to use POST
     * @param array $params
     * 
     */
    public function setPost($params) 
    {
        $this->options['curl_headers'][] = 'Content-type: application/x-www-form-urlencoded';
        $this->setOption('CURLOPT_POST', true);
        $this->setOption('CURLOPT_POSTFIELDS', http_build_query($params));
    }

    /**
     * Gets the current response container
     *  
     */
    public function getResponse() 
    {
        return $this->response;
    }

    /**
     * Gets current server
     *  
     * @return string
     */
    public function getServer() 
    {
        $server = $this->options['server'];
        if ((($action = $this->getAction()) !== false) && 
              array_key_exists($action, $this->options['action_map'])) {
              $server  = $this->options['action_map'][$action];
        }
        return $server;
    }

    /**
     * Gets current uri without the controller or mapped actions
     *  
     * @return string
     */
    public function getUri() 
    {
        $uri = $_SERVER['REQUEST_URI'];
        foreach (array_keys($this->getActionMap()) as $needle) {
            if (strpos($uri, '/'.$needle) !== false) {
                $uri = str_replace('/'.$needle, '', $uri);
            }
        }
        return $this->stripController($uri);
    }

    /**
     * Gets remote uri via the info container
     *  
     * @return string
     */
    public function getRemoteUrl()
    {
        $info = $this->getInfo();
        return $info['url'];
    }

    /**
     * Gets webserver configs dynamically from the $_SERVER global
     *  
     * @return array
     */
    private function getWebserverConfigs() 
    {
        return array(
            'home_dir'     => realpath(dirname(__FILE__)),
            'curl_options' => array(
                'CURLOPT_URL'       => $this->getServer().$this->getUri(),
                'CURLOPT_REFERER'   => $this->getHost(),
            ),
            'curl_headers' => array(
                'Host: '.str_replace(array('http://','https://'), '', $this->getServer()),
                'User-Agent: '.$_SERVER['HTTP_USER_AGENT'],
                'X-Publisher-Id: '.$_SERVER['SERVER_NAME'],
            ),
        );
    }

    /**
     * Sends all headers to output
     * 
     */
    public function initHeaders() 
    {
        foreach ($this->getHeaders() as $header) {
            header($header);
        }
    }

    /**
     * greps the Input string against the "Content Type:" of the request
     * @param string $type
     * @return bool
     */
    public function isType($type) {
        if (key(preg_grep("/$type/i", $this->response['headers'])) !== null) {
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Detects if errors were found in the response
     * @return bool
     */
    public function hasErrors() {
        
        if (!empty($this->response['error'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Takes a Uri string and converts it to a file path
     * @param string $uri
     * @return string
     */
    public function convertUriToPath($uri) 
    {
        $pattern = '/(http:|https:)\/\/(.+?)\//i';
        $replacement = '/';
        $uri = preg_replace($pattern, $replacement, $uri );

        if ($uri === '/') {
            $uri = '/index';
        }
        $lastchar = strlen($uri)-1;
        if (substr($uri,$lastchar) === '/') {
            $uri = substr($uri, 0, $lastchar);
        }
        
        return $uri;
    }

    /**
     * Strips out any headers based on the Illegal_headers config
     * @param array $headers
     * @return array
     */
    public function stripIllegalHeaders($headers) 
    {
        $headers = array_filter(array_map("trim", $headers));
        foreach ($this->options['illegal_headers'] as $val) {
            unset($headers[key(preg_grep("/$val/i", $headers))]);
        }
        return $headers;
    }

    /**
     * Takes a string of content and runs it through arbitrary methods
     * @param string $content
     * @return string
     */
    public function postProcess($content) 
    {
        $content = $this->swapHosts($content);
        $content = $this->stripRootReference($content);
        $content = $this->shuntPath($content);
        return $content;
    }

    /**
     * Greps the content for the remote server address and replaces it with the local host
     * @param string $content
     * @return string
     */
    public function swapHosts($content) 
    {
        $pattern = "/".addcslashes($this->getServer(),'/')."/";
        $replacement = $this->getHost();
        $newcontent = preg_replace($pattern , $replacement , $content );
        return $newcontent;
    }

    /**
     * Greps the content for a root reference and removes it
     * @param string $content
     * @return string
     */
    public function stripRootReference($content) 
    {
        $pattern = '/\=\"\//';
        $replacement = '="';
        $newcontent = preg_replace ($pattern, $replacement , $content);

        #for linked js
        $pattern = '/\"\/js/';
        $replacement = '"'.$this->getController().'/js';
        $newcontent = preg_replace ($pattern, $replacement , $newcontent);

        return $newcontent;
    }
    
    /**
     * Greps the content for a link beginning without the controller and suppliments the controller string
     * @param string $content
     * @return string
     */
    public function shuntPath($content) 
    {
        $pattern = "/".addcslashes('<a href="(?!http)','/')."/";
        $replacement = '<a href="' . $this->getHost() ;
        $newcontent = preg_replace($pattern , $replacement , $content );
        return $newcontent;
    }

    /**
     * Removes the controller from a given uri string
     * @param string $uri
     * @return string
     */
    public function stripController($uri = "") 
    {
        $controller = $this->getController();
        if (strpos($uri, $controller) !== false) {
            $uri = str_replace($controller, '', $uri);
        }
        return $uri;
    }

    /**
     * Checks for software updates and executes if there is a new revision
     * 
     */
    public function checkForUpdates()
    {
        $latest = getLatestRevision();
        if ($latest && ($latest != getCurrentRevision())) {
            dumpCache();
            setCurrentRevision($latest);
        }
    }    

    /**
     * deletes the cache folder
     * 
     */
    public function dumpCache() 
    {
        $this->rmdir_recursive($this->options['home_dir'] . '/cache');  
    }

    /**
     * Removes a directory and all files and subdirectories within it
     * @param string $dir
     *
     */
    public function rmdir_recursive($dir) 
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir."/".$object) == "dir") rmdir_recursive($dir."/".$object); else unlink($dir."/".$object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    /**
     * Determines if the current request is using Ajax by the $_SERVER global
     *
     * @return bool
     */
    public function isAjax() 
    {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Prepares the current environment for use
     *
     */
    private function prepare() 
    {
        if (!is_dir($this->options['home_dir'] . '/cache')) {
            mkdir($this->options['home_dir'] . '/cache');
        }
    }

    /**
     * taken from php.net http://php.net/manual/en/function.array-merge-recursive.php
     *
     * posted by: mark dot roduner at gmail dot com 14-Feb-2010 07:34
     * originally posted as array_merge_recursive_distinct 
     *
     * Merges any number of arrays / parameters recursively, replacing
     * entries with string keys with values from latter arrays.
     * If the entry or the next value to be assigned is an array, then it
     * automagically treats both arguments as an array.
     * Numeric entries are appended, not replaced, but only if they are
     * unique
     *
     * calling: result = this::mergeConfig(a1, a2, ... aN)
     * @param array $arr1
     * @param array $arr2
     * @return array
    **/

    private function mergeConfig($arr1, $arr2)
    {
      $arrays = func_get_args();
      $base = array_shift($arrays);
      if(!is_array($base)) $base = empty($base) ? array() : array($base);
      foreach($arrays as $append) {
        if(!is_array($append)) $append = array($append);
        foreach($append as $key => $value) {
          if(!array_key_exists($key, $base) and !is_numeric($key)) {
            $base[$key] = $append[$key];
            continue;
          }
          if(is_array($value) or is_array($base[$key])) {
            $base[$key] = $this->mergeConfig($base[$key], $append[$key]);
          } else if(is_numeric($key)) {
            if(!in_array($value, $base)) $base[] = $value;
          } else {
            $base[$key] = $value;
          }
        }
      }
      return $base;
    }

}
