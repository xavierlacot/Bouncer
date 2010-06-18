<?php

class Bouncer
{

    const NICE = 'nice';
    const NEUTRAL = 'neutral';
    const SUSPICIOUS = 'suspicious';
    const BAD = 'bad';

    const ROBOT = 'robot';
    const BROWSER = 'browser';
    const UNKNOWN = 'unknown';

    protected static $_prefix = '';

    protected static $_backend = 'memcache';
    protected static $_backendInstance = null;

    protected static $_rules = array(
        'agent_infos' => array(),
        'ip_infos' => array(),
        'browser_identity' => array(),
        'robot_identity' => array(),
        'request' => array()
    );

    protected static $_namespaces = array(
        ''
    );

    public static function run(array $options = array())
    {
        self::setOptions($options);
        self::load();
        self::bounce();
    }

    public static function load()
    {
        require_once dirname(__FILE__) . '/Rules/Bbclone.php';
        Bouncer_Rules_Bbclone::load();
        require_once dirname(__FILE__) . '/Rules/Basic.php';
        Bouncer_Rules_Basic::load();
        require_once dirname(__FILE__) . '/Rules/Fingerprint.php';
        Bouncer_Rules_Fingerprint::load();
        require_once dirname(__FILE__) . '/Rules/Network.php';
        Bouncer_Rules_Network::load();
        require_once dirname(__FILE__) . '/Rules/Geoip.php';
        Bouncer_Rules_Geoip::load();
    }

    public static function setOptions(array $options = array())
    {
        if (isset($options['prefix'])) {
            self::$_prefix = $options['prefix'];
        }
        if (isset($options['backend'])) {
            self::$_backend = $options['backend'];
        }
        if (isset($options['namespaces'])) {
            self::$_namespaces = $options['namespaces'];
        }
    }

    protected static function identity()
    {
        $addr = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];

        $id = self::hash($addr . ':' . $user_agent);

        if ($identity = self::backend()->getIdentity($id)) {
            return $identity;
        }

        $headers = self::getHeaders(array('User-Agent', 'Accept', 'Accept-Charset', 'Accept-Language', 'Accept-Encoding', 'From'));
        $fingerprint = self::fingerprint($headers);

        $agent = self::getAgentInfos($user_agent);

        $ip = self::getIpInfos($addr);

        $identity = array_merge(compact('id', 'headers', 'fingerprint'), $agent, $ip);

        self::backend()->setIdentity($id, $identity);

        return $identity;
    }

    protected static function getAgentInfos($user_agent)
    {
        $signature = self::hash($user_agent);

        // Get From Backend
        $key = 'agent-infos-' . $signature;
        if ($agentInfos = self::get($key)) {
            return $agentInfos;
        }

        $agentInfos = array(
            'signature'  => $signature,
            'user_agent' => $user_agent,
            'name'       => 'unknown',
            'type'       => self::UNKNOWN
        );

        $rules = self::$_rules['agent_infos'];
        foreach ($rules as $func) {
            $agentInfos = call_user_func_array($func, array($agentInfos));
        }

        self::set($key, $agentInfos, (60 * 60 * 24));

        return $agentInfos;
    }


    protected static function getIpInfos($addr)
    {
        // Get From Backend
        $key = 'ip-infos-' . self::hash($addr);
        if ($ipInfos = self::get($key)) {
            return $ipInfos;
        }

        $ipInfos = array(
            'addr' => $addr,
            'host' => gethostbyaddr($addr),
        );

        $rules = self::$_rules['ip_infos'];
        foreach ($rules as $func) {
            $ipInfos = call_user_func_array($func, array($ipInfos));
        }

        self::set($key, $ipInfos, (60 * 60 * 24));

        return $ipInfos;
    }

    protected static function getHeaders($keys = array())
    {
        if (!is_callable('getallheaders')) {
            $headers = array();
            foreach ($_SERVER as $h => $v)
                if (ereg('HTTP_(.+)', $h, $hp))
                    $headers[str_replace("_", "-", uc_all($hp[1]))] = $v;
        } else {
            $headers = getallheaders();
        }
        // Filter
        if (!empty($keys)) {
            foreach ($headers as $key => $value) {
                if (!in_array($key, $keys)) {
                    unset($headers[$key]);
                }
            }
        }
        return $headers;
    }

    protected static function request()
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        $server = $_SERVER['SERVER_NAME'];
        $uri = $_SERVER['REQUEST_URI'];
        if (strpos($uri, '?')) {
            $split = explode('?', $uri);
            $uri = $split[0];
        }
        $headers = self::getHeaders();
        // Ignore Host + Agent headers
        $ignore = array('Host', 'User-Agent', 'Accept', 'Accept-Charset', 'Accept-Language', 'Accept-Encoding', 'From');
        foreach ($ignore as $key) {
            unset($headers[$key]);
        }
        $request = compact('method', 'server', 'uri', 'headers');
        if (!empty($_GET)) {
            $request['GET'] = $_GET;
        }
        if (!empty($_POST)) {
            $request['POST'] = $_POST;
        }
        if (!empty($_COOKIE)) {
            $request['COOKIE'] = $_COOKIE;
        }
        return $request;
    }

    protected static function anonymise($array)
    {
        $result = array();
        foreach ($array as $key => $value) {
            if (empty($value)) {
                $result[$key] = '';
            } else {
                $result[$key] = sprintf("%u", crc32($value)) . '-' . strlen($value);
            }
        }
        return $result;
    }

    protected static function bounce()
    {
        $identity = self::identity();
        if (empty($identity) || empty($identity['id'])) {
            return;
        }

        $request = self::request();

        // Analyse Identity
        list($identity, $result) = self::analyse($identity, $request);

        // Log Connection
        self::log($identity, $request, $result);

        // Release Backend Connection
        self::backend()->clean();

        // Set Bouncer Cookie
        if (empty($_COOKIE['bouncer-identity']) || $_COOKIE['bouncer-identity'] != $identity['id']) {
            setcookie('bouncer-identity', $identity['id'], time()+60*60*24*365 , '/');
        }

        // Process
        list($status, $score) = $result;
        switch ($status) {
            case self::BAD:
                $throttle = rand(1000*1000, 2000*1000);
                usleep($throttle);
                self::ban();
            case self::SUSPICIOUS:
                $throttle = rand(500*1000, 2000*1000);
                usleep($throttle);
            case self::NEUTRAL:
                if (!empty($identity['robot'])) {
                    $throttle = rand(250*1000, 1000*1000);
                    usleep($throttle);
                }
            case self::NICE:
            default:
                break;
        }
    }

    protected static function analyse($identity, $request)
    {
        // Initial identity id
        $id = $identity['id'];

        // Analyse Agent Identity Infos
        $analyseCacheKey = 'analyse-' . $id;
        if (!$result = self::get($analyseCacheKey)) {
            list($identity, $result) = self::analyseIdentity($identity);
            self::set($analyseCacheKey, $result, (60 * 60 * 12));
        }

        // Consolidate bots IDs
        if ($identity['type'] == self::ROBOT && $result[1] >= 1) {
            $identity['id'] = $identity['name'];
        }

        // Analysis resulted in a new identity id, we store it
        if ($identity['id'] != $id) {
            $id = $identity['id'];
            if (!self::getIdentity($id)) {
                self::setIdentity($id, $identity);
            }
        }

        // Additionaly parse request if result is ambigus
        if ($identity['type'] != self::ROBOT && $result[1] < 15 && $result[1] >= -15) {
            $result = self::analyseRequest($identity, $request, $result);
        } elseif ($identity['type'] == self::ROBOT && $result[1] < 1) {
            $result = self::analyseRequest($identity, $request, $result);
        }

        return array($identity, $result);
    }

    public static function analyseIdentity($identity, $request = array())
    {
        if ($identity['type'] == self::BROWSER) {
            $rules = self::$_rules['browser_identity'];
        } else {
            $rules = self::$_rules['robot_identity'];
        }
        $result = self::processRules($rules, $identity, $request);
        return array($identity, $result);
    }

    public static function analyseRequest($identity, $request, $result = array())
    {
        $rules = self::$_rules['request'];
        $result = self::processRules($rules, $identity, $request, $result);
        return $result;
    }

    public static function addRule($type, $function)
    {
        if (empty(self::$_rules[$type])) {
            self::$_rules[$type] = array();
        }
        self::$_rules[$type][] = $function;
    }

    public static function processRules($rules, $identity, $request, $result = array())
    {
        if (empty($result)) {
            $result = array(self::NEUTRAL, 0, array());
        }

        list($status, $score, $details) = $result;

        foreach ($rules as $func) {
            $scores = call_user_func_array($func, array($identity, $request));
            if (isset($scores) && is_array($scores)) {
                foreach ($scores as $detail) {
                    $details[] = $detail;
                    list($value, $message) = $detail;
                    $score += $value;
                }
            }
        }

        if ($score >= 10) {
            $result = array(self::NICE, $score, $details);
        } else if ($score <= -10) {
            $result = array(self::BAD, $score, $details);
        } else if ($score <= -5) {
            $result = array(self::SUSPICIOUS, $score, $details);
        } else {
            $result = array(self::NEUTRAL, $score, $details);
        }

        return $result;
    }

    protected static function log($identity, $request, $result)
    {
        $time = time();
        $agent = $identity['id'];

        $connection = array();
        $connection['identity'] = $identity['id'];
        $connection['request'] = $request;
        $connection['time'] = $time;
        $connection['result'] = $result;

        // Log connection
        $connectionKey = self::backend()->storeConnection($connection);

        foreach (self::$_namespaces as $ns) {
            // Add agent to agents index
            self::backend()->indexAgent($agent, $ns);
            // Add connection to index
            self::backend()->indexConnection($connectionKey, $agent, $ns);
        }
    }

    protected static function ban()
    {
        header("HTTP/1.0 403 Forbidden");
        die('Forbidden');
    }

    protected static function unavailable()
    {
        header("HTTP/1.0 503 Service Unavailable");
        die('Service Unavailable');
    }

    // Utils

    public static function fingerprint($array)
    {
        ksort($array);
        $string = '';
        foreach ($array as $key => $value) {
            $string .= "$key:$value;";
        }
        return self::hash($string);
    }

    public static function hash($string)
    {
        return md5($string);
    }

    public static function ismd5($string)
    {
        return !empty($string) && preg_match('/^[a-f0-9]{32}$/', $string);
    }

    // Backend

    protected static function backend()
    {
        if (empty(self::$_backendInstance)) {
            switch (self::$_backend) {
                case 'memcache':
                    require_once dirname(__FILE__) . '/Backend/Memcache.php';
                    $options = array('prefix' => self::$_prefix);
                    self::$_backendInstance = new Bouncer_Backend_Memcache($options);
                    break;
                case 'redis':
                    require_once dirname(__FILE__) . '/Backend/Redis.php';
                    $options = array('namespace' => self::$_prefix);
                    self::$_backendInstance = new Bouncer_Backend_Redis($options);
                    break;
            }
        }
        return self::$_backendInstance;
    }

    public static function get($key) { return self::backend()->get($key); }
    public static function set($key, $value) { return self::backend()->set($key, $value); }
    public static function getIdentity($id) { return self::backend()->getIdentity($id); }
    public static function setIdentity($id, $value) { return self::backend()->setIdentity($id, $value); }
    public static function getAgentsIndex($ns = '') { return self::backend()->getAgentsIndex($ns); }
    public static function getAgentConnections($agent, $ns = '') { return self::backend()->getAgentConnections($agent, $ns); }
    public static function getLastAgentConnection($agent, $ns = '') { return self::backend()->getLastAgentConnection($agent, $ns); }
    public static function getFirstAgentConnection($agent, $ns = '') { return self::backend()->getFirstAgentConnection($agent, $ns); }
    public static function countAgentConnections($agent, $ns = '') { return self::backend()->countAgentConnections($agent, $ns); }

    public static function stats(array $options = array())
    {
        self::setOptions($options);
        self::load();
        require dirname(__FILE__) . '/Stats.php';
        Bouncer_Stats::stats($options);
    }

}