<?php

class Bouncer_Stats
{

    public static $_keys = array('id', 'fingerprint', 'time', 'hits', 'host', 'system', 'agent', 'referer', 'score');

    public static $_namespace = '';

    public static function stats(array $options = array())
    {
        error_reporting(E_ALL | E_STRICT);
        date_default_timezone_set('Europe/Berlin');

        self::setOptions($options);

        ?>

        <style type="text/css">
        body { overflow-y:scroll; }
        body, table { font-size:11px; font-family:Arial, sans-serif; }
        body, a { color: #333; }
        #filter, table { width:100%; margin:auto; max-width:1200px; }
        #filter { display:block; margin-bottom:10px; border:1px solid #ccc; }
        table { border-collapse:collapse; }
        table, td, th { border:1px solid #ccc; }
        td { height:18px; line-height:14px; }
        table td img { vertical-align:-2px; width:14px; height:14px; }
        tr.neutral { background-color:#E0E5F2; }
        tr.bad { background-color:#EFE2EC; }
        tr.suspicious { background-color:#f2e8e0; }
        tr.nice { background-color:#e2f2e0; }
        .ic { padding-left:20px; background:2px 2px no-repeat }
        .fr { background-image:url(http://h6e.net/bouncer/images/ext_fr.png) }
        .unknown { background-image:url(http://h6e.net/bouncer/images/os_question.png) }
        .windowsxp, .windowsmc { background-image:url(http://h6e.net/bouncer/images/os_windowsxp.png) }
        .windowsvista, .windows7 { background-image:url(http://h6e.net/bouncer/images/os_windowsvista.png) }
        .explorer { background-image:url(http://h6e.net/bouncer/images/browser_explorer.png) }
        .firefox { background-image:url(http://h6e.net/bouncer/images/browser_firefox.png) }
        .chrome { background-image:url(http://h6e.net/bouncer/images/browser_chrome.png) }
        .macosx { background-image:url(http://h6e.net/bouncer/images/os_macosx.png) }
        </style>

        <?php

        if (isset($_GET['extract'])) {
            self::extract();
        } else if (isset($_GET['stats'])) {
            self::charts();
        } else if (isset($_GET['connection'])) {
            self::connection();
        } else if (isset($_GET['agent'])) {
            self::agent();
        } else {
            self::index();
        }

    }

    public static function setOptions(array $options = array())
    {
        if (isset($options['namespace'])) {
            self::$_namespace = $options['namespace'];
        }
        if (isset($options['keys'])) {
            self::$_keys = $options['keys'];
        }
    }

    public static function index()
    {   
         require( dirname(__FILE__) . "/lib/browser.php" );
         require( dirname(__FILE__) . "/lib/os.php" );
         require( dirname(__FILE__) . "/lib/robot.php" );

         require_once dirname(__FILE__) . '/Rules/Fingerprint.php';

         $agents = Bouncer::getAgentsIndex(self::$_namespace);

         $cssRules = array();
         $cssRules['unknown'] = 'background-image:url(http://h6e.net/bouncer/images/os_question.png)';

         $count = 0;

         echo '<form method="get" action="">';

         $value = isset($_GET['filter']) ? htmlspecialchars($_GET['filter']) : '';
         echo '<input type="search" name="filter" id="filter" value="' . $value . '"/>';
         echo '<script type="text/javascript">document.getElementById("filter").focus();</script>';

         echo '<table>' . "\n";

         $linkify = true;

         echo '<tr>';
         foreach (self::$_keys as $key) {
             if ($key == 'fingerprint') {
                 echo '<th style="width:14px">', '', '</th>';
                 echo '<th colspan="2">', ucfirst($key), '</th>';
             } else {
                 echo '<th>', ucfirst($key), '</th>';
             }
         }
         echo '</tr>' . "\n";

         $filters = array();
         if (!empty($_GET['filter'])) {
             foreach (explode(' ', $_GET['filter']) as $f) {
                 if (strpos($f, ':')) $filters[] = explode(':', trim($f));
             }
         }

         foreach ($agents as $time => $id) {

             $identity = Bouncer::getIdentity($id);
             if (empty($identity)) {
                 continue;
             }

             $last = Bouncer::getLastAgentConnection($id, self::$_namespace);
             if (empty($last)) {
                 continue;
             }

             $first = Bouncer::getFirstAgentConnection($id, self::$_namespace);

             $status = isset($last['result']) ? $last['result'][0] : 'neutral';
             $user = isset($last['request']['COOKIE']['user']) ? $last['request']['COOKIE']['user'] : '';
             $fingerprint = $identity['fingerprint'];
             $time = $last['time'];
             $hits = Bouncer::countAgentConnections($id, self::$_namespace);
             $addr = $identity['addr'];
             $host = $identity['host'];
             $extension = isset($identity['extension']) ? $identity['extension'] : 'numeric';
             $type = $identity['type'];
             $signature = $identity['signature'];
             $agent = $name = $identity['name'];
             $version = isset($identity['version']) ? $identity['version'] : null;
             $system = $system_name = isset($identity['os']) ? $identity['os'][0] : 'unknown';
             $system_version = isset($identity['os']) ? $identity['os'][1] : '';
             $referer = isset($first['request']['headers']['Referer']) ? $first['request']['headers']['Referer'] : '';
             $score = isset($last['result']) ? $last['result'][1] : 0;
             $server = isset($last['request']['server']) ? $last['request']['server'] : '';
             $method = isset($last['request']['method']) ? $last['request']['method'] : 'GET';

             foreach ($filters as $filter) {
                 list($filterKey, $filterValue) = $filter;
                 if (strpos($filterKey, '-') === 0) {
                    $filterKey = substr($filterKey, 1);
                    if (isset($$filterKey) && $$filterKey == $filterValue) {
                         continue 2;
                     }
                 } else {
                     if (isset($$filterKey) && $$filterKey != $filterValue) {
                         continue 2;
                     }
                 }
             }

             if ($linkify) {
                 $id = '<a href="?agent=' . $id . '">' . substr($id, 0, 10) . '</a>';
             } else {
                 $id = substr($id, 0, 10);
             }

             if (isset($user) && $linkify) {
                 $user = '<a href="http://blogmarks.net/user/' . $user . '">' . $user . '</a>';
             }

             $fingerprint = substr($identity['fingerprint'], 0, 6);
             $fgtype = Bouncer_Rules_Fingerprint::getType($identity['fingerprint']);
             if ($linkify) {
                 $fingerprint = '<a href="?filter=fingerprint%3A' . $identity['fingerprint'] . '">' . $fingerprint . '</a>';
             }

             $time = date("d/m/Y.H:i:s", $last['time']);

             if ($type == 'browser' && isset($os[$system])) {
                 if (empty($cssRules[$system])) {
                     $cssRules[$system] = 'background-image:url(http://h6e.net/bouncer/images/os_' . $os[$system]['icon'] . '.png)';
                 }
                 $system = $os[$system]['title'] . ' ' . $system_version;
             } else {
                 $system = '';
                 $system_name = '';
             }

             if (empty($cssRules[$extension])) {
                  $cssRules[$extension] = 'background-image:url(http://h6e.net/bouncer/images/ext_' . $extension . '.png)';
             }
             if ($linkify) {
                 $host = '<a href="?filter=host%3A' .  $host . '">' .  $host . '</a>';
             }

             if ($type == 'browser') {
                 if (empty($cssRules[$name])) {
                     $cssRules[$name] = 'background-image:url(http://h6e.net/bouncer/images/browser_' . $browser[$name]['icon'] . '.png)';
                 }
                 $agent = $browser[$name]['title'] . ' ' . $version;
             } else if ($type == 'robot') {
                 if (empty($cssRules[$name])) {
                     $cssRules[$name] = 'background-image:url(http://h6e.net/bouncer/images/robot_' . $robot[$name]['icon'] . '.png)';
                 }
                 $agent = $robot[$name]['title'] . ' ' . $version;
             }

             if (!empty($referer)) {
                 $preferer = parse_url($referer);
             }
             if (!empty($referer) && isset($preferer['host']) && $first['request']['server'] != $preferer['host']) {
                 if ($linkify) {
                     $referer = '<a href="' . $referer . '">' . $preferer['host'] . '</a>';
                 } else {
                     $referer = $preferer['host'];
                 }
             } else {
                 $referer = '';
             }

             echo '<tr class="', $status, '">';
             foreach (self::$_keys as $key) {
                 if ($key == 'fingerprint') {
                     echo '<td style="background:#' . substr($identity['fingerprint'], 0, 6) . '">&nbsp;</td>';
                     echo '<td>' . $fingerprint . '</td>';
                     echo '<td>' . ( isset($fgtype) ? $fgtype : '' ) . '</td>';
                 } else if ($key == 'host') {
                     echo '<td class="ic ' . $extension . '">', $host, '</td>';
                 } else if ($key == 'agent') {
                     echo '<td class="ic ' . $name . '">', $agent ,'</td>';
                 } else if ($key == 'system') {
                     echo '<td class="ic ' . $system_name . '">', $system ,'</td>';
                 } else {
                     if (isset($$key)) {
                          echo '<td>', $$key ,'</td>';
                     } else {
                         echo '<td>', '&nbsp;' ,'</td>';
                     }
                 }
             }
             echo '</tr>' . "\n";

             $count ++;
             if ($count >= 100) {
                 break;
             }

         }

         echo '</table>';

         echo '<style type="text/css">' . "\n";
         foreach ($cssRules as $class => $content) {
             echo ".$class { $content; }\n";
         }
         echo '</style>';

         echo '</form>';
    }

    public static function agent()
    {

        require_once dirname(__FILE__) . '/Rules/Network.php';
        require_once dirname(__FILE__) . '/Rules/Geoip.php';

        $id = $_GET['agent'];
        $identity = Bouncer::getIdentity($id);
        if (empty($identity)) {
            return;
        }

        list($identity, $result) = Bouncer::analyseIdentity($identity);
        list($status, $score, $details) = $result;

        echo '<table>';
        echo '<tr>', '<th>', 'Identity', '</th>', '</tr>';
        echo '<tr>', '<td>', 'Id', '</td>', '<td>', $identity['id'], '</td>', '</tr>';
        echo '<tr>', '<td>', 'Signature', '</td>', '<td>', '<a href="?filter=signature%3A' . $identity['signature'] . '">', $identity['signature'], '</a></td>', '</tr>';
        echo '<tr>', '<td>', 'Fingerprint', '</td>', '<td>', '<a href="?filter=fingerprint%3A' . $identity['fingerprint'] . '">', $identity['fingerprint'], '</a></td>', '</tr>';
        echo '<tr>', '<td>', 'IP', '</td>', '<td>', $identity['addr'], '</td>', '</tr>';
        echo '<tr>', '<td>', 'Extension', '</td>', '<td>', $identity['extension'], '</td>', '</tr>';

        echo '<tr>', '<td>', 'Host', '</td>', '<td>', '<a href="?filter=host%3A' . $identity['host'] . '">', $identity['host'], '</a></td>', '</tr>';
        echo '<tr>', '<td>', 'Name', '</td>', '<td>', $identity['name'], '</td>', '</tr>';
        if (isset($identity['version'])) {
            echo '<tr>', '<td>', 'Version', '</td>', '<td>', $identity['version'], '</td>', '</tr>';
        }
        if (isset($identity['os'])) {
            echo '<tr>', '<td>', 'OS', '</td>', '<td>', $identity['os'][0], '</td>', '</tr>';
        }
        echo '<tr>', '<td>', 'Http:BL', '</td>', '<td>'; if (isset($identity['httpbl'])) print_r($identity['httpbl']); echo '</td>', '</tr>';

        echo '<tr>', '<td>', 'Country Code', '</td>', '<td>', Bouncer_Rules_Geoip::country_code_by_addr($identity['addr']), '</td>', '</tr>';

        echo '<tr>', '<td>', 'Reverse', '</td>', '<td>';
        $rev = preg_replace('/^(\\d+)\.(\\d+)\.(\\d+)\.(\\d+)$/', '$4.$3.$2.$1', $identity['addr']);
        $ptrs = @dns_get_record("{$rev}.in-addr.arpa.", DNS_PTR);
        print_r($ptrs);
        echo '</td>', '</tr>';

        if ($identity['addr'] != $identity['host']) {
            echo '<tr>', '<td>', 'DNS', '</td>', '<td>';
            $dns = @dns_get_record($identity['host'], DNS_A);
            print_r($dns);
            echo '</td>', '</tr>';
        }

        echo '<tr>', '<td>', 'Network', '</td>', '<td>';
        print_r(Bouncer_Rules_Network::doPWLookupBulk(array($identity['addr'])));
        echo '</td>', '</tr>';

        echo '<tr>', '<th>', 'Agent HTTP Headers', '</th>', '</tr>';
        foreach ($identity['headers'] as $key => $value) {
            echo '<tr>', '<td>', $key, '</td>', '<td>', $value, '</td>', '</tr>';
        }
        echo '<tr>', '<th>', 'Score', '</th>', '</tr>';
        foreach ($details as $detail) {
            list($value, $message) = $detail;
            echo '<tr>', '<td>', $message, '</td>', '<td>', $value, '</td>', '</tr>';
        }
        echo '<tr>', '<td>', 'Total', '</td>', '<td><b>', $score, '</b></td>', '</tr>';
        echo '</table>';

        echo '<br/>';

        $connections = Bouncer::getAgentConnections($id, self::$_namespace);
        if (empty($connections)) {
            $connections = array();
        }

        echo '<table>';
        echo '<tr>';
        echo '<th>', 'Id', '</th>';
        echo '<th>', 'Time', '</th>';
        echo '<th>', 'Method', '</th>';
        echo '<th>', 'Server', '</th>';
        echo '<th>', 'URI', '</th>';
        echo '<th>', 'Referer', '</th>';
        echo '<th>', 'Score', '</th>';
        echo '</tr>';
        foreach ($connections as $id => $connection) {
            $request = $connection['request'];
            $status = $connection['result'][0];
            echo '<tr class="', $status, '">';
            echo '<td>', '<a href="?connection=', $id, '">', substr($id, 0, 10), '</a></td>';
            echo '<td>', date("d/m/Y H:i:s", $connection['time']), '</td>';
            echo '<td>' , $request['method'], '</td>';
            echo '<td>' , $request['server'], '</td>';
            echo '<td>' , urldecode($request['uri']), '</td>';
            if (!empty($request['headers']['Referer'])) {
                $preferer = parse_url($request['headers']['Referer']);
            }
            if (!empty($request['headers']['Referer']) && isset($preferer['host']) && $request['server'] != $preferer['host']) {
                echo '<td>', '<a href="', $request['headers']['Referer'], '">', $preferer['host'], '</td>';
            } else {
                echo '<td>', '</td>';
            }
            if (isset($connection['result'])) {
                echo '<td>' , $connection['result'][1], '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
    }

    public static function connection()
    {
        $id = $_GET['connection'];
        $connection = Bouncer::get('connection-' . $id);
        if (empty($connection)) {
            return;
        }

        $request = $connection['request'];

        $identity = Bouncer::getIdentity($connection['identity']);
        $result = Bouncer::analyseRequest($identity, $request);
        list($status, $score, $details) = $result;

        echo '<table>';
        if (!empty($request['headers'])) {
            echo '<tr>', '<th>', 'Request Headers', '</th>' , '</tr>';
            foreach ($request['headers'] as $key => $value) {
                echo '<tr>', '<td>', $key, '</td>', '<td>', $value, '</td>', '</tr>';
            }
        }
        foreach (array('GET', 'COOKIE', 'POST') as $G) {
            if (!empty($request[$G])) {
                echo '<tr>', '<th>', $G, '</th>' , '</tr>';
                foreach ($request[$G] as $key => $value) {
                    echo '<tr>', '<td>', $key, '</td>', '<td>', $value, '</td>', '</tr>';
                }
            }
        }
        echo '<tr>', '<th>', 'Score', '</th>', '</tr>';
        foreach ($details as $detail) {
            list($value, $message) = $detail;
            echo '<tr>', '<td>', $message, '</td>', '<td>', $value, '</td>', '</tr>';
        }
        echo '<tr>', '<td>', 'Total', '</td>', '<td><b>', $score, '</b></td>', '</tr>';
        echo '</table>';
    }

    public static function charts()
    {
        $stats = array();
        $agents = Bouncer::getAgentsIndex(self::$_namespace);
        foreach ($agents as $id) {
            $key = $_GET['stats'];
            $identity = Bouncer::getIdentity($id);
            if (empty($identity) || empty($identity[$key])) {
                continue;
            }
            $value = $identity[$key];
            if (empty($stats[$value])) {
                $stats[$value] = 1;
            } else {
                $stats[$value] ++;
            }
        }

        ksort($stats);
        arsort($stats);

        echo '<table>';
        foreach ($stats as $value => $count) {
            echo '<tr>';
            if ($count == 1) {
                continue;
            }
            if ($key == 'fingerprint') {
                echo '<td>', $count, '</td>';
                echo '<td style="background:#' . substr($value, 0, 6) . '">&nbsp;</td>';
                echo '<td width="10">', '<a href="?filter=fingerprint%3A' . $value . '">', $value, '</a></td>';

                if (in_array($value, Bouncer_Rules_Fingerprint::$suspicious_fingerprints)) {
                    echo '<td>', 'suspicious', '</td>';
                } else if (in_array($value, Bouncer_Rules_Fingerprint::$banned_fingerprints)) {
                    echo '<td>', 'banned', '</td>';
                } else if (in_array($value, Bouncer_Rules_Fingerprint::$known_fingerprints)) {
                    echo '<td>', 'known', '</td>';
                } else if (in_array($value, Bouncer_Rules_Fingerprint::get('botnet'))) {
                    echo '<td>', 'botnet', '</td>';
                } else if (in_array($value, Bouncer_Rules_Fingerprint::get('browser'))) {
                    echo '<td>', 'nice', '</td>';
                } else {
                    echo '<td>', '', '</td>';
                }

            } else if ($key == 'host') {
                echo '<td width="10">', $count, '</td>';
                echo '<td width="10">', '<a href="?filter=host%3A' . $value . '">', $value, '</a></td>';
            } else {
                echo '<td>', $count, '</td>';
                echo '<td>', $value, '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
    }

    public static function extract()
    {
        $agents = Bouncer::getAgentsIndex(self::$_namespace);

        $fingerprints = array();

        // Collect level 1 fingerprints
        foreach ($agents as $id) {
            $key = $_GET['extract'];
            $identity = Bouncer::getIdentity($id);
            $fg = $identity['fingerprint'];
            if (strpos($identity['host'],$key) !== false) {
                $fingerprints[] = $fg;
            }
        }
        $fingerprints = array_unique($fingerprints);

        $hosts = array();

        // Collect level 1 hosts
        foreach ($agents as $id) {
            $identity = Bouncer::getIdentity($id);
            $fg = $identity['fingerprint'];
            $host = $identity['host'];
            if (in_array($fg, $fingerprints)) {
                if (empty($hosts[$host])) {
                    $hosts[$host] = 1;
                } else {
                    $hosts[$host] ++;
                }
            }
        }

        $fingerprints2 = array();

        // Collect level 2 fingerprints
        foreach ($agents as $id) {
            $identity = Bouncer::getIdentity($id);
            $fg = $identity['fingerprint'];
            $host = $identity['host'];
            if (isset($hosts[$host])) {
                 if (empty($fingerprints2[$fg])) {
                     $fingerprints2[$fg] = 1;
                 } else {
                     $fingerprints2[$fg] ++;
                 }
             }
        }

        $fingerprints3 = array();

        // Check Ambigous agents
        foreach ($agents as $id) {
            $identity = Bouncer::getIdentity($id);
            $fg = $identity['fingerprint'];
            $host = $identity['host'];
            if (isset($fingerprints2[$fg]) && empty($hosts[$host]) ) {
               if (empty($fingerprints3[$fg])) {
                     $fingerprints3[$fg] = 1;
                 } else {
                     $fingerprints3[$fg] ++;
                 }
            }
        }

        ksort($fingerprints2);
        arsort($fingerprints2);

        foreach ($fingerprints2 as $value => $count) {
            if (isset($fingerprints3[$value])) {
                continue;
            }
            echo "\n'$value', // $count";
        }
    }

}