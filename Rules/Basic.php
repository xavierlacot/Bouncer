<?php

class Bouncer_Rules_Basic
{

    public static function load()
    {

        Bouncer::addRule('browser_identity', array('Bouncer_Rules_Basic', 'browser_version'));
        Bouncer::addRule('browser_identity', array('Bouncer_Rules_Basic', 'os_version'));
        Bouncer::addRule('browser_identity', array('Bouncer_Rules_Basic', 'browser_identity'));

        Bouncer::addRule('robot_identity', array('Bouncer_Rules_Basic', 'robot_identity'));

        Bouncer::addRule('request', array('Bouncer_Rules_Basic', 'has_cookie'));
        Bouncer::addRule('request', array('Bouncer_Rules_Basic', 'request_headers'));
    }

    public static function browser_version($identity)
    {
        if ($identity['type'] != Bouncer::BROWSER) {
            return null;
        }

        $scores = array();

        $name = $identity['name'];
        $version = $identity['version'];

        // plus
             if ($name == 'safari'   && strpos($version, '4.') === 0)    $scores[] = array(2.5, 'Recent Browser');
        else if ($name == 'chrome'   && strpos($version, '5.') === 0)    $scores[] = array(2.5, 'Recent Browser');
        else if ($name == 'chrome'   && strpos($version, '6.') === 0)    $scores[] = array(2.5, 'Recent Browser');
        else if ($name == 'firefox'  && strpos($version, '3.6') === 0)   $scores[] = array(2.5, 'Recent Browser');
        else if ($name == 'explorer' && strpos($version, '8.') === 0)    $scores[] = array(2.5, 'Recent Browser');
        else if ($name == 'opera'    && strpos($version, '10.') === 0)   $scores[] = array(2.5, 'Recent Browser');

        // minus
        else if ($name == 'explorer' && strpos($version, '5.') === 0)    $scores[] = array(-2.5, 'Old Browser');
        else if ($name == 'explorer' && strpos($version, '6.') === 0)    $scores[] = array(-2.5, 'Old Browser');
        else if ($name == 'netscape')                                    $scores[] = array(-2.5, 'Old Browser');

        return $scores;
    }

    public static function os_version($identity)
    {
        if (empty($identity['os'])) {
            if ($identity['type'] == Bouncer::BROWSER) return -5;
            else return 0;
        }

        $scores = array();

        $os_name = $identity['os'][0];
        $os_version = $identity['os'][1];

        // plus
        if ($os_name == 'macosx' && strpos($os_version, '10.6') === 0) $scores[] = array(2.5, 'Recent OS');
        else if ($os_name == 'windows7')                               $scores[] = array(2.5, 'Recent OS');

        // minus
        else if ($os_name == 'windows95') $scores[] = array(-2.5, 'Old OS');
        else if ($os_name == 'windows98') $scores[] = array(-2.5, 'Old OS');
        else if ($os_name == 'windowsnt') $scores[] = array(-2.5, 'Old OS');

        return $scores;
    }

    public static function browser_identity($identity)
    {
        $scores = array();

        if ($identity['type'] != Bouncer::BROWSER) {
            return 0;
        }

        $name = $identity['name'];
        $headers = $identity['headers'];

        // Identify Explorer derivatives
        $explorers = array('msn', 'maxthon', 'deepnet', 'avantbrowser', 'aol', 'myie2', 'crazybrowser');
        if (in_array($name, $explorers)) {
            $name = 'explorer';
        }

        $known_browsers = array('explorer','firefox', 'safari', 'chrome', 'opera');

        // Legitimates browsers always send this Accept-* header
        if (in_array($name, $known_browsers)) {
            if (empty($headers['Accept'])) {
                $scores[] = array(-5, 'Accept header Missing (Bad Behavior: 17566707)');
            // FIXME: Ajax Requests send this header
            } else if ($headers['Accept'] == '*/*') {
                $scores[] = array(-5, '*/* Accept header');
            }
            if (empty($headers['Accept-Language'])) {
                $scores[] = array(-2.5, 'Accept-Language header missing');
            }
            if (empty($headers['Accept-Encoding'])) {
                $scores[] = array(-2.5, 'Accept-Encoding header missing');
            }
        }

        // Legitimates Opera/Chrome/Firefox Browsers send Accept-Charset header
        if (in_array($name, array('opera', 'chrome', 'firefox'))) {
            if (empty($headers['Accept-Charset'])) {
                $scores[] = array(-2.5, 'Accept-Charset header missing');
            }
            if (isset($headers['Accept-Language'], $headers['Accept-Charset'], $headers['Accept-Encoding'])) {
                $scores[] = array(2.5, 'All Accept-* headers detected');
            }
        }

        return $scores;
    }

    public static function request_headers($identity, $request)
    {
        $scores = array();

        $name = $identity['name'];
        $headers = $request['headers'];

        // PALC
        if (isset($headers['X-BlueCoat-Via'])) {
            $scores[] = array(5, 'BlueCoat PALC');
        } else if (isset($headers['Via'])) {
            // Proxy sometimes remove Accept-Encoding header, so we give a bonus
            $scores[] = array(2.5, 'PALC bonus');
        }

        // Identify Explorer derivatives
        $explorers = array('msn', 'maxthon', 'deepnet', 'avantbrowser', 'aol', 'myie2', 'crazybrowser');
        if (in_array($name, $explorers)) {
            $name = 'explorer';
        }

        // Cookie, if it exists, it must not be blank
        if (isset($headers['Cookie']) && empty($headers['Cookie'])) {
            $scores[] = array(-7.5, 'Blank Cookie');
        }

        // Referer, if it exists, it must not be blank
        if (isset($headers['Referer'])) {
            if (empty($headers['Referer'])) {
                $scores[] = array(-7.5, 'Blank Referer (Bad Behavior: 69920ee5)');
            } else {
                // Like Bad Behavior: 45b35e30
                $purl = parse_url($headers['Referer']);
                if (empty($purl['host'])) {
                    $scores[] = array(-5, 'Invalid Referer');
                }
            }
        }

        // Proxy-Connection does not exist and should never be seen in the wild
        if (isset($headers['Proxy-Connection'])) {
            $scores[] = array(-5, 'Proxy-Connection header detected (Bad Behavior: b7830251)');
        }

        // Content Type is surely not for GET ... moron!
        if (isset($headers['Content-Type']) && $request['method'] == 'GET') {
            $scores[] = array(-7.5, 'Content-Type Header with a GET Request');
        }

        $known_browsers = array('explorer', 'firefox', 'safari', 'chrome', 'opera');

        if (in_array($name, $known_browsers)) {
            // Legitimate Browsers always send a Connection:Keep-Alive|Close header
            if (empty($headers['Connection'])) {
                $scores[] = array(-2.5, 'Connection Header Missing');
            }
            // libWWW used to fake a browser identity
            if (isset($headers['TE']) && $headers['TE'] == 'deflate,gzip;q=0.3') {
                if ($headers['Connection'] == 'TE, close') {
                    $scores[] = array(-10, 'libWWW signature detected');
                }
            }
        }

        switch ($name) {
            case 'opera':
                // Real Opera send this header (but sometimes not)
                if (isset($headers['Cookie2'])) {
                    $scores[] = array(2.5, 'Cookie2 header detected');
                }
                break;
            case 'explorer':
                // Only legitimate browsers are setting this header
                if (isset($headers['UA-CPU']))
                    $scores[] = array(2.5, 'UA-CPU Header Detected');
                // Only Spambots are identifying as explorer and sending this header
                if (isset($headers['Cookie2']) && $headers['Cookie2'] == '$Version="1"')
                    $scores[] = array(-5, 'Cookie2 header with value $Version="1"');
                break;
        }

        if (!empty($request['headers']['Referer'])) {
            $preferer = parse_url($request['headers']['Referer']);
            if (strpos($preferer['host'], 'google')) {
                $scores[] = array(2.5, 'Google as Referer');
            }
        }

        return $scores;
    }

    public static function has_cookie($identity, $request)
    {
        $scores = array();

        if ($identity['name'] == 'rss-atom') {
            $scores[] = array(0, 'Identity cookie test skipped');
            return $scores;
        }

        if (isset($request['COOKIE']['bouncer-identity'])) {
            if ($request['COOKIE']['bouncer-identity'] == $identity['id']) {
                $scores[] = array(2.5, 'Good identity cookie detected');
            } else {
                $scores[] = array(-2.5, 'Wrong identity cookie detected');
            }
        }
        return $scores;
    }

    public static function robot_identity($identity)
    {
        $scores = array();
        $score = 0;

        if ($identity['type'] != Bouncer::ROBOT || empty($identity['name'])) {
            return null;
        }

        $addr = $identity['addr'];
        $host = $identity['host'];
        $headers = $identity['headers'];

        switch ($identity['name']) {
            // top crawlers
            case 'google':
                $score += strpos($host, 'googlebot.com') === false ? -5 : 1;
                $score += empty($headers['From']) ? -5 : 1;
                break;
            case 'mediapartners':
                $score += strpos($host, 'googlebot.com') === false ? -5 : 2.5;
                $score += $identity['fingerprint'] != '4b8841489bb368a2e0defed8149bf912' ? -5 : 2.5;
                // $score += empty($headers['From']) ? -5 : 1;
                break;
            case 'googlemobile':
                $score += strpos($host, 'googlebot.com') === false ? -5 : 2.5;
                $score += empty($headers['From']) ? -5 : 2.5;
                break;
            case 'yahoo':
                $score += strpos($host, 'yahoo.net') === false ? -5 : 1;
                break;
            case 'msnbot':
                $score += strpos($host, 'msn.com') === false && empty($headers['From']) ? -5 : 1;
                break;
            case 'voila':
                $score += strpos($host, 'fti.net') === false ? -5 : 2.5;
                $score += $identity['fingerprint'] != '2e593407134622b8cab54d30e1efb9d9' ? -5 : 2.5;
            case 'orange':
                $score += strpos($host, 'fti.net') === false ? -5 : 2.5;
                break;
            case 'yandex':
                $score += strpos($host, 'yandex.ru') === false ? -5 : 1;
                break;
            case 'naverbot':
                $score += strpos($host, 'naver.jp') === false ? -5 : 1;
                break;
            case 'scoutjet':
                $score += strpos($host, 'scoutjet.com') === false ? -5 : 2.5;
                $score += $identity['fingerprint'] != 'd970c6ffb8d5547d9f6052207200b0dd' ? -5 : 2.5;
                break;
            case 'baidu':
                $score += (strpos($host, 'baidu.') === false && strpos($addr, '123.125.') === false) ? -5 : 2.5;
                break;
            case 'ask':
                $score += strpos($host, 'ask.com') === false ? -5 : 2.5;
                $score += $identity['fingerprint'] != '742b4a79a150f6ab785e558ddbf7aaa6' ? -5 : 2.5;
                break;
            case 'mailru':
                $score += strpos($host, 'mail.ru') === false ? -5 : 1;
                break;
            case 'twiceler':
                $score += strpos($host, 'cuil.com') === false ? -5 : 2.5;
                $score += $identity['fingerprint'] != 'b90c08ff5b5c8265dbfd9c6fa65f2e09' ? -5 : 2.5;
                break;
            case 'spinn3r':
                $score += strpos($host, 'spinn3r.com') === false ? -5 : 1;
                break;
            case 'picsearch':
                $score += strpos($host, 'picsearch.com') === false ? -5 : 2.5;
                break;
            case 'exabot':
                $score += strpos($host, 'exabot.com') === false ? -5 : 1;
                $score += empty($headers['From']) ? -5 : 1;
                break;
            case 'entireweb':
                $score += strpos($host, 'entireweb.' === false) ? -2.5 : 1;
                $score += $identity['extension'] != 'se' ? -2.5 : 1;
                $score += empty($headers['From']) ? -2.5 : 1.5;
                $score += $identity['fingerprint'] != 'c2bff0ebec4c3dab9da8035e5219a0fd' ? -2.5 : 1.5;
                break;
            case 'alexa':
                $score += strpos($host, 'amazonaws.com') === false ? -5 : 1;
                $score += empty($headers['From']) ? -5 : 1;
                break;
            case 'daum':
                $score += $identity['fingerprint'] != 'c566ee1e58e2bfc09389b1d4f5790574' ? -5 : 1;
                $score += $identity['extension'] != 'kr' ? -5 : 1;
                break;
            case 'soso':
                $score += $identity['fingerprint'] != '6c722beb9681d0d922b8919606168c43' ? -5 : 1;
                $score += $identity['extension'] != 'cn' ? -5 : 1;
                break;
            case 'youdao':
                $score += $identity['fingerprint'] != '123153a2352ae8af25b8944eadb38fcb' ? -5 : 1;
                $score += $identity['extension'] != 'cn' ? -5 : 1;
                break;
            case 'hatena':
                $score += $identity['fingerprint'] != '2d2155f7ce9b6b4f866fffa067a76a14' ? -5 : 1;
                $score += $identity['extension'] != 'jp' ? -5 : 1;
                break;
            case 'dotbot':
                $score += strpos($host, 'dotnetdotcom.org') === false ? -4 : 1;
                $score += $identity['extension'] != 'us' ? -3 : 1;
                $score += $identity['fingerprint'] != '1ba3e09e05c3a64578777e53d4f20a3c' ? -3 : 1;
                break;
            case 'sogou':
                $score += $identity['fingerprint'] != 'a86f74048055ff8ea8a8570615c478f4' ? -5 : 2.5;
                $score += $identity['extension'] != 'cn' ? -5 : 2.5;
                break;
            case 'alexa':
                $score += strpos($host, 'amazonaws.com') === false ? -5 : 2.5;
                $score += empty($headers['From']) ? -5 : 2.5;
                break;
            case 'setooz':
                $score += strpos($host, 'setooz.com') === false ? -5 : 2.5;
                break;
            case 'goo':
                $score += strpos($host, 'super-goo.com') === false ? -5 : 2.5;
                break;
            case 'mlbot':
                $score += (strpos($addr, '66.219.58.') === false && strpos($addr, '71.41.201.') === false) ? -5 : 2.5;
                break;
            case 'feedburner':
                $score += $identity['fingerprint'] != 'cdcb44c8464c40d53a6f5635ee66d642' ? -5 : 2.5;
                $score += $identity['extension'] != 'us' ? -5 : 2.5;
                break;
            // feeds
            case 'netvibes':
                $score += strpos($host, 'netvibes.com') === false ? -5 : 1;
                break;
            case 'googlefeeds':
                $score += strpos($host, 'google.com') === false ? -5 : 1;
                break;
            case 'bloglines':
                $score += strpos($host, 'bloglines.com') === false ? -5 : 1;
                break;
            case 'page2rss':
                $score += strpos($host, 'page2rss.com') === false ? -5 : 1;
                break;
            case 'icerocket':
                $score += strpos($host, 'icerocket.com') === false ? -5 : 2.5;
                $score += $identity['fingerprint'] != '261b05f8f307e382d8acce6f304f481e' ? -5 : 2.5;
                break;
        }

        if ($score >= 1) {
            $scores[] = array($score, 'Robot identity verified');
        } else if ($score == 0) {
            $scores[] = array($score, 'Robot identity not verified');
        } else if ($score <= -5) {
            $scores[] = array($score, 'Robot identity suspicious');
        } else {
            $scores[] = array($score, 'Robot identity ambigous');
        }

        return $scores;
    }

}