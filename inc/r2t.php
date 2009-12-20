<?php

class r2t {
    
    public $debug = true;
    protected $feeds = array();
    /**
    *
    */
    function __construct() {
        $this->init();
    }
    
    protected function init() {
        include_once ("sfYaml/sfYaml.class.php");
        define('R2T_TEMP_DIR', R2T_PROJECT_DIR . "/tmp/");
        if (!file_Exists(R2T_TEMP_DIR)) {
            if (!mkdir(R2T_TEMP_DIR)) {
                die("Could not create " . R2T_TEMP_DIR);
            }
        }
        $yaml = file_get_contents(R2T_PROJECT_DIR . '/conf/config.yml');
        $yaml .= file_get_contents(R2T_PROJECT_DIR . '/conf/feeds.yml');
        $f = sfYAML::Load($yaml);
        if ($f['feeds']) {
            $this->feeds = $f['feeds'];
        }
        $this->config = $f['config'];
    }
    
    public function process() {
        
        foreach ($this->feeds as $feedname => $options) {
            $options = $this->mergeFeedsWithConfig($options);
            $newentries = $this->getNewEntries($feedname, $options['url']);
            $cnt = 1;
            foreach ($newentries as $guid => $e) {
                $this->tumblrise($e, $options);
                $cnt++;
                if ($cnt > $options['maxposts']) {
                    break;
                }
            }
        }
        return $newentries;
    }
    
    protected function mergeFeedsWithConfig($options) {
        foreach ($this->config as $name => $value) {
            if (!isset($options[$name])) {
                $options[$name] = $value;
            }
        }
        return $options;
        
    }
    
    protected function tumblrise($entry, $options) {

        // Authorization info
        $tumblr_email    = $options['tumblr']['email'];
        $tumblr_password = $options['tumblr']['password'];
        
        $data = array(
            'email'     => $tumblr_email,
            'password'  => $tumblr_password,
            'type'      => $options['type'],
            'generator' => 'rss2tumblr'
        );
        
        foreach ($options['map'] as $key => $value) {
            // if it's an array it should mean it contains a regex, subject and keyname
            if(is_array($value)){
                $matches = '';
                preg_match($value['regex'], $entry[$value['subject']], $matches);
                $match = $matches[$value['keyname']];
                
                if(!empty($match)){
                    $data[$key] = $match;
                }
                
            }else{
                $data[$key] = $entry[$value];
            }
        }
        
        // Prepare POST request
        $request_data = http_build_query($data);

        // Send the POST request (with cURL)
        $c = curl_init('http://www.tumblr.com/api/write');
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, $request_data);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        // Check for success
        if ($status == 201) {
            $this->debug("Success! It has been posted to " . $options['tumblr']['url'] . "/post/" . $result);
        } else if ($status == 403) {
            $this->debug('Bad email or password');
        } else {
            $this->debug("Error: $result\n");
        }
    }
    
    protected function getNewEntries($feedname, $url) {
        $oldentries = $this->getOldEntries($feedname);
        $onlineentries = $this->getOnlineEntries($feedname,$url);
        if (count($onlineentries) > 0) {
            //keep some old entries, so that they don't get repostet if the show up later
            $z = 0;
            $max = count($onlineentries);
            
            foreach($oldentries as $k => $v) {
                if(!isset($onlineentries[$k])) { 
                    $onlineentries[$k] = $v;
                    $z++;
                    if ($z > $max) {
                        break;
                    }
                }
            }
            
            file_put_contents(R2T_TEMP_DIR . "/$feedname", sfYaml::dump($onlineentries));
            chmod(R2T_TEMP_DIR . "/$feedname",0666);
        }
        $newentries = $onlineentries;
        foreach ($onlineentries as $guid => $a) {
            if (isset($oldentries[$guid])) {
                unset($newentries[$guid]);
            } else {
                $this->debug("   New Entry: " . $a['link'] . " " . $a['title']);
            }
        }
        return $newentries;
    }
    
    protected function getOldEntries($feed) {
        $file = R2T_TEMP_DIR . "/$feed";
        $oldentries = array();
        if (file_exists($file)) {
            $oldentries = sfYAML::Load($file);
        }
        return $oldentries;
    }
    
    protected function getOnlineEntries($feedname,$url) {
        $feed = $this->readFeed($feedname,$url);
        $this->debug("Loop through entries");
        $entries = array();
        foreach ($feed as $entry) {
            if (isset($entry->guid)) {
                $entry->guid = $entry->link;
            }
            $e = array(
            "link" => $entry->link,
            "title" => $entry->title,
            "content" => $entry->content
            );
            
            foreach ($entry->model->getElementsByTagNameNS('http://search.yahoo.com/mrss', 'player') as $element) {
                $e["player"] = $element->nodeValue;
            }
            
            if(!$entry->guid) {
                $entry->guid = $entry->link;
            }
            $entries[$entry->guid] = $e;
        }
        return $entries;
    }
    
    protected function readFeed($feedname,$url) {
        require_once ("XML/Feed/Parser.php");
        
        $this->debug("readFeed for $url");
        $body = $this->httpRequest($feedname,$url);
        if ($body) {
            $this->debug("parse Feed");
            return new XML_Feed_Parser($body);
            
        } else {
            $this->debug("Feed for $url was empty");
            return array();
        }
    }
    
    protected function httpRequest($feedname,$url) {
        require_once ("HTTP/Request.php");
        $this->debug("httpRequest for $url");
        
        $req = new HTTP_Request($url);
        if (!PEAR::isError($req->sendRequest())) {
            return $req->getResponseBody();
        }
        return null;
    }
    
    protected function debug($msg) {
        if ($this->debug) {
            if (is_string($msg)) {
                print $msg . "\n";
            } else {
                var_dump($msg);
            }
        }
    }
}
