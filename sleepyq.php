<?php

/**
 * sleepyq.php
 * References:
 * https://github.com/technicalpickles/sleepyq/blob/master/sleepyq/__init__.py
 * https://github.com/tuctboh/adjustTheBed/blob/master/adjustTheBed-main.php
 * https://raw.githubusercontent.com/rvrolyk/SleepNumberController/master/SleepNumberController_App.groovy
 * https://community.hubitat.com/t/release-sleep-number-controller-control-your-sleep-number-bed-and-use-it-for-presence/46454/27?page=2
 * https://github.com/danpenn/SleepIQ/blob/1531466e2b64/control.go
 */

require_once __DIR__ . '/settings.php';

//Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/28.0.1500.95 Safari/537.36
//Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; .NET CLR 1.0.3705; .NET CLR 1.1.4322; Media Center PC 4.0)
define('SN_USER_AGENT', "SleepIQ/1669639706 CFNetwork/1399 Darwin/22.1.0");

function writeDebug($f_debugfile, $f_msg)
{
    if (WRITE_DEBUG_LOG) {
        $fh_debugout = fopen($f_debugfile, "a");
        fwrite($fh_debugout, "[" . microtime(true) . "] - " . $f_msg . "\n");
        fclose($fh_debugout);
    }
    if (WRITE_DEBUG_PRINT) {
        print "DEBUG: $f_msg\n";
    }
}

class APIObject extends stdClass
{
    public $data;

    public function __construct($data)
    {
        $this->data = $data;
        if (!$data) {
            return;
        }

        // Iterate through each element in $data and set the associated object property
        foreach ($data as $k => $v) {
            $this->{$k} = $v;
        }
    }

    public function __get($name)
    {
        $adjustedName = lcfirst($name);
        return isset($this->data[$adjustedName]) ? $this->data[$adjustedName] : null;
    }
}

class Bed extends APIObject
{
    public $left = null;
    public $right = null;
    public $sides = [];

    // Expected properties
    public $accountId = null;
    public $base = null;
    public $bedId = null;
    public $dualSleep = null;
    public $foundationFeatures = null;
    public $generation = null;
    public $isKidsBed = null;
    public $macAddress = null;
    public $model = null;
    public $name = null;
    public $purchaseDate = null;
    public $reference = null;
    public $registrationDate = null;
    public $returnRequestStatus = null;
    public $serial = null;
    public $size = null;
    public $sku = null;
    public $sleeperLeftId = null;
    public $sleeperRightId = null;
    public $status = null;
    public $timezone = null;
    public $version = null;
    public $zipcode = null;

    public function __construct($data)
    {
        parent::__construct($data);
        $this->left = null;
        $this->right = null;
    }
}

class FamilyStatus extends APIObject
{
    public $bed = null;
    public $bedId = null;
    public $left = null;
    public $right = null;
    public $status = null;

    public function __construct($data)
    {
        parent::__construct($data);
        $this->bed = null;
        if ($data['leftSide']) {
            $this->left = new SideStatus($data['leftSide']);
        }
        if ($data['rightSide']) {
            $this->right = new SideStatus($data['rightSide']);
        }
    }
}

class SideStatus extends APIObject
{
    public $alertDetailedMessage = null;
    public $alertId = null;
    public $bed = null;
    public $isInBed = null;
    public $lastLink = null;
    public $pressure = null;
    public $sleeper = null;
    public $sleepNumber = null;

    public function __construct($data)
    {
        parent::__construct($data);
        $this->bed = null;
        $this->sleeper = null;
    }
}

class Sleeper extends APIObject
{
    public $bed = null;

    public function __construct($data)
    {
        parent::__construct($data);
        $this->bed = null;
    }
}

class FavSleepNumber extends APIObject
{
    public $bedId = null;
    public $left = null;
    public $right = null;
    public $sleepNumberFavoriteLeft = null;
    public $sleepNumberFavoriteRight = null;

    public function __construct($data)
    {
        parent::__construct($data);
        $this->left = null;
        $this->right = null;
    }
}

class Status extends APIObject
{
    public function __construct($data)
    {
        parent::__construct($data);
    }
}

class FootwarmingStatus extends APIObject
{
    public $footWarmingStatusLeft = null;
    public $footWarmingStatusRight = null;
    public
        $footWarmingTimerLeft = null;
    public
        $footWarmingTimerRight = null;
    public $sides = null;
    public function __construct($data)
    {
        parent::__construct($data);
    }
}

class FoundationFeatures extends APIObject
{
    public $boardIsASingle = null;
    public $easternKing = null;
    public $hasFootControl = null;
    public $hasFootWarming = null;
    public $hasMassageAndLight = null;
    public $hasUnderbedLight = null;
    public $leftUnderbedLightPMW = null;
    public $rightUnderbedLightPMW = null; // This one actually reflects changes
    public $single = null;
    public $splitHead = null;
    public $splitKing = null;

    public function __construct($data)
    {
        parent::__construct($data);
    }
}

class SleepyqPHP
{
    private $_login;
    private $_password;
    private $_session;
    private $_session_params = []; // To be added to the GET querystring
    private $_base_api = "prod-api.sleepiq.sleepnumber.com";
    private $_api;
    private $_cookieFile;
    // Fuzion support caches
    private $_accountId = null; // For bamkey path
    private $_bedGenerations = []; // bedId => generation
    private $_fuzionFeatureCache = []; // bedId => parsed features
    private $_fuzionLightState = []; // bedId => ['state'=>word,'timer'=>int]
    private $_lastBamkeyErrors = []; // bedId => list of recent bamkey errors

    private function recordBamkeyError($bedId, $command, array $args, $e)
    {
        $this->_lastBamkeyErrors[$bedId][] = [
            'cmd' => $command,
            'args' => $args,
            'error' => ($e instanceof Exception) ? $e->getMessage() : (string)$e,
            'ts' => microtime(true),
        ];
        // keep only last 25
        if (count($this->_lastBamkeyErrors[$bedId]) > 25) {
            array_splice($this->_lastBamkeyErrors[$bedId], 0, -25);
        }
        if (function_exists('writeDebug')) {
            writeDebug(WRITE_DEBUG_MAIN_FILE, "bamkey error ($command): " . (($e instanceof Exception) ? $e->getMessage() : $e));
        }
    }

    private function bamkeyOrDefault($bedId, $command, array $args = [], $default = null)
    {
        try {
            return $this->__bamkey($bedId, $command, $args);
        } catch (Exception $e) {
            $this->recordBamkeyError($bedId, $command, $args, $e);
            return $default;
        }
    }

    public function getLastBamkeyErrors($bedId = null)
    {
        if ($bedId === null) return $this->_lastBamkeyErrors;
        return $this->_lastBamkeyErrors[$bedId] ?? [];
    }

    public function hadRecentBamkeyError($bedId, $withinSeconds = 5.0)
    {
        $errors = $this->_lastBamkeyErrors[$bedId] ?? [];
        $cut = microtime(true) - $withinSeconds;
        for ($i = count($errors) - 1; $i >= 0; $i--) {
            if ($errors[$i]['ts'] >= $cut) return true;
            if ($errors[$i]['ts'] < $cut) break;
        }
        return false;
    }

    // Underbed lights are bed-wide. Not per side.
    const RIGHT_NIGHT_STAND = 1;
    const LEFT_NIGHT_STAND = 2;
    const RIGHT_NIGHT_LIGHT = 3; // Active/working for me/Only working option
    const LEFT_NIGHT_LIGHT = 4;

    const BED_LIGHTS = [
        self::RIGHT_NIGHT_STAND,
        self::LEFT_NIGHT_STAND,
        self::RIGHT_NIGHT_LIGHT,
        self::LEFT_NIGHT_LIGHT
    ];

    const LIGHT_SETTINGS_OFF = 0;
    const LIGHT_SETTINGS_ON = 1;
    const LIGHT_SETTINGS = [
        self::LIGHT_SETTINGS_OFF,
        self::LIGHT_SETTINGS_ON,
    ];

    // Associated with fsRightUnderbedLightPWM (and the left, but left doesn't update)
    const LIGHT_BRIGHTNESS_OFF = 0;
    const LIGHT_BRIGHTNESS_LOW = 1;
    const LIGHT_BRIGHTNESS_MEDIUM = 30;
    const LIGHT_BRIGHTNESS_HIGH = 100;
    const LIGHT_BRIGHTNESS = [
        self::LIGHT_BRIGHTNESS_OFF,
        self::LIGHT_BRIGHTNESS_LOW,
        self::LIGHT_BRIGHTNESS_MEDIUM,
        self::LIGHT_BRIGHTNESS_HIGH
    ];

    const LIGHT_TIMER_15 = 15;
    const LIGHT_TIMER_30 = 30;
    const LIGHT_TIMER_45 = 45;
    const LIGHT_TIMER_60 = 60;
    const LIGHT_TIMER_120 = 120;
    const LIGHT_TIMER_180 = 180;
    const LIGHT_TIMER = [
        self::LIGHT_TIMER_15,
        self::LIGHT_TIMER_30,
        self::LIGHT_TIMER_45,
        self::LIGHT_TIMER_60,
        self::LIGHT_TIMER_120,
        self::LIGHT_TIMER_180
    ];

    // 0 can also be returned, which means not in a preset state (something custom, but unsaved)
    const FAVORITE = 1;
    const READ = 2;
    const WATCH_TV = 3;
    const FLAT = 4;
    const ZERO_G = 5;
    const SNORE = 6;

    const BED_PRESETS = [
        self::FAVORITE,
        self::READ,
        self::WATCH_TV,
        self::FLAT,
        self::ZERO_G,
        self::SNORE
    ];

    const OFF = 0;
    const LOW = 1;
    const MEDIUM = 2;
    const HIGH = 3;

    const MASSAGE_SPEED = [
        self::OFF,
        self::LOW,
        self::MEDIUM,
        self::HIGH
    ];

    const SOOTHE = 1;
    const REVITILIZE = 2;
    const WAVE = 3;

    const MASSAGE_MODE = [
        self::OFF,
        self::SOOTHE,
        self::REVITILIZE,
        self::WAVE
    ];

    const FOOTWARM_OFF = 0;
    const FOOTWARM_LOW = 31;
    const FOOTWARM_MEDIUM = 57;
    const FOOTWARM_HIGH = 72;

    const FOOTWARM_TEMP = [
        self::FOOTWARM_OFF,
        self::FOOTWARM_LOW,
        self::FOOTWARM_MEDIUM,
        self::FOOTWARM_HIGH
    ];

    const FOOTWARM_30 = 30;
    const FOOTWARM_60 = 60;
    const FOOTWARM_120 = 120;
    const FOOTWARM_180 = 180;
    const FOOTWARM_240 = 240;
    const FOOTWARM_300 = 300;
    const FOOTWARM_360 = 360;

    const FOOTWARM_TIMER = [
        self::FOOTWARM_30,
        self::FOOTWARM_60,
        self::FOOTWARM_120,
        self::FOOTWARM_180,
        self::FOOTWARM_240,
        self::FOOTWARM_300,
        self::FOOTWARM_360
    ];

    const LEFT = 'left';
    const RIGHT = 'right';
    const SIDES_NAMES = [
        self::LEFT,
        self::RIGHT
    ];

    private function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    // Subset of BAMKEY commands required for existing feature parity
    const BAMKEY = [
        'HaltAllActuators' => 'ACHA',
        'GetSystemConfiguration' => 'SYCG',
        'InterruptSleepNumberAdjustment' => 'PSNI',
        'StartSleepNumberAdjustment' => 'PSNS',
        'GetSleepNumberControls' => 'SNCG',
        'SetFavoriteSleepNumber' => 'SNFS',
        'GetFavoriteSleepNumber' => 'SNFG',
        'SetUnderbedLightSettings' => 'UBLS',
        'GetUnderbedLightSettings' => 'UBLG',
        // Added for auto underbed lighting support
        'SetUnderbedLightAutoSettings' => 'UBAS',
        'GetUnderbedLightAutoSettings' => 'UBAG',
        'GetActuatorPosition' => 'ACTG',
        'SetActuatorTargetPosition' => 'ACTS',
        'SetTargetPresetWithoutTimer' => 'ASTP',
        'GetCurrentPreset' => 'AGCP',
        'GetFootwarmingPresence' => 'FWPG',
        'SetFootwarmingSettings' => 'FWTS',
        'GetFootwarmingSettings' => 'FWTG',
        // Future Fuzion features (not yet fully implemented here)
        'SetResponsiveAirState' => 'LRAS',
        'GetResponsiveAirState' => 'LRAG',
        'SetSleepiqPrivacyState' => 'SPRS',
        'GetSleepiqPrivacyState' => 'SPRG',
        'SetHeidiMode' => 'THMS',
        'GetHeidiMode' => 'THMG',
        'GetHeidiPresence' => 'THPG',
    ];

    private function isFuzionBed($bedId)
    {
        return isset($this->_bedGenerations[$bedId]) && strtolower($this->_bedGenerations[$bedId]) === 'fuzion';
    }

    private function ensureAccountId()
    {
        if ($this->_accountId) return;
        $beds = $this->beds();
        if (!$this->_accountId && count($beds)) {
            $this->_accountId = $beds[0]->accountId ?? null;
        }
    }

    private function __bamkey($bedId, $command, array $args = [])
    {
        $this->ensureAccountId();
        if (!$this->_accountId) throw new Exception("Missing accountId for bamkey");
        if (!array_key_exists($command, self::BAMKEY)) throw new Exception("Unsupported bamkey command $command");
        $body = [
            'args' => implode(' ', $args),
            'key' => self::BAMKEY[$command],
            'sourceApplication' => 'SleepyqPHP'
        ];
        $path = '/sn/v1/accounts/' . $this->_accountId . '/beds/' . $bedId . '/bamkey';
        $resp = $this->__makeRequest($path, 'PUT', $body);
        if (!is_array($resp) || !isset($resp['cdcResponse'])) throw new Exception("Bamkey command $path failed: $command\n" . print_r($resp, true));
        $val = $resp['cdcResponse'];
        if (strpos($val, 'PASS:') === 0) $val = substr($val, 5);
        return $val;
    }

    private function normalizeSideShort($side)
    {
        $s = strtolower($side);
        if ($s === 'left' || $s === 'l') return 'L';
        if ($s === 'right' || $s === 'r') return 'R';
        throw new Exception("Invalid side: $side");
    }
    private function normalizeSideFullLower($side)
    {
        $s = strtolower($side);
        if ($s === 'left' || $s === 'l') return 'left';
        if ($s === 'right' || $s === 'r') return 'right';
        throw new Exception("Invalid side: $side");
    }

    private function fuzionPresetNumericToWord($preset)
    {
        $map = [
            self::FAVORITE => 'favorite',
            self::READ => 'read',
            self::WATCH_TV => 'watch_tv',
            self::FLAT => 'flat',
            self::ZERO_G => 'zero_g',
            self::SNORE => 'snore',
        ];
        return $map[intval($preset)] ?? null;
    }
    private function fuzionPresetWordToNumeric($word)
    {
        $rev = [
            'favorite' => self::FAVORITE,
            'read' => self::READ,
            'watch_tv' => self::WATCH_TV,
            'flat' => self::FLAT,
            'zero_g' => self::ZERO_G,
            'snore' => self::SNORE,
            'not at preset' => 0,
        ];
        return $rev[strtolower($word)] ?? null;
    }

    private function fuzionFootwarmTempWordToValue($word)
    {
        $rev = [
            'off' => self::FOOTWARM_OFF,
            'low' => self::FOOTWARM_LOW,
            'medium' => self::FOOTWARM_MEDIUM,
            'high' => self::FOOTWARM_HIGH,
        ];
        return $rev[strtolower($word)] ?? self::FOOTWARM_OFF;
    }
    private function fuzionFootwarmValueToWord($val)
    {
        $map = [
            self::FOOTWARM_OFF => 'off',
            self::FOOTWARM_LOW => 'low',
            self::FOOTWARM_MEDIUM => 'medium',
            self::FOOTWARM_HIGH => 'high',
        ];
        return $map[intval($val)] ?? 'off';
    }

    private function fuzionBrightnessValueToWord($val)
    {
        if ($val <= self::LIGHT_BRIGHTNESS_OFF) return 'off';
        if ($val <= self::LIGHT_BRIGHTNESS_LOW) return 'low';
        if ($val <= self::LIGHT_BRIGHTNESS_MEDIUM) return 'medium';
        return 'high';
    }
    private function fuzionBrightnessWordToPwm($word)
    {
        $w = strtolower($word);
        if ($w === 'off') return self::LIGHT_BRIGHTNESS_OFF;
        if ($w === 'low') return self::LIGHT_BRIGHTNESS_LOW;
        if ($w === 'medium') return self::LIGHT_BRIGHTNESS_MEDIUM;
        if ($w === 'high') return self::LIGHT_BRIGHTNESS_HIGH;
        return self::LIGHT_BRIGHTNESS_OFF;
    }

    private function fuzionParseSystemConfiguration($string)
    {
        $tokens = preg_split('/\s+/', trim($string));
        $flags = [
            'underbedLightEnableFlag' => false,
            'articulationEnableFlag' => false,
            'thermalControlEnabledFlag' => false,
        ];
        if (isset($tokens[2])) $flags['articulationEnableFlag'] = ($tokens[2] === 'yes');
        if (isset($tokens[3])) $flags['underbedLightEnableFlag'] = ($tokens[3] === 'yes');
        if (isset($tokens[5])) $flags['thermalControlEnabledFlag'] = ($tokens[5] === 'yes');
        return [
            'single' => false,
            'splitHead' => false,
            'splitKing' => false,
            'easternKing' => false,
            'boardIsASingle' => false,
            'hasMassageAndLight' => $flags['articulationEnableFlag'],
            'hasFootControl' => $flags['articulationEnableFlag'],
            'hasFootWarming' => $flags['thermalControlEnabledFlag'],
            'hasUnderbedLight' => $flags['underbedLightEnableFlag'],
            'leftUnderbedLightPMW' => 0,
            'rightUnderbedLightPMW' => 0,
        ];
    }

    public function __construct($login, $password)
    {
        $this->_api = "https://" . $this->_base_api . "/rest";
        $this->_login = $login;
        $this->_password = $password;
        $this->_session = curl_init();
        $cookieFile = SLEEPYQ_COOKIE_PATH . $this->generateRandomString() . ".txt";
        // Create cookie directory if not already made
        if (!is_dir(SLEEPYQ_COOKIE_PATH) && SLEEPYQ_COOKIE_PATH != '.' && SLEEPYQ_COOKIE_PATH != '..') {
            if (!mkdir(SLEEPYQ_COOKIE_PATH, 0755, true)) {
                writeDebug(WRITE_DEBUG_MAIN_FILE, "Failed to create cookie directory: " . SLEEPYQ_COOKIE_PATH);
                exit;
            }
        }
        // Create cookie file
        if (!is_file($cookieFile)) {
            //chmod(dirname($this->_cookieFile), 0755);
            if (!touch($cookieFile)) {
                writeDebug(WRITE_DEBUG_MAIN_FILE, "Failed to touch cookie file:$cookieFile");
                exit;
            }
            if (!chmod($cookieFile, 0777)) {
                writeDebug(WRITE_DEBUG_MAIN_FILE, "Failed to chmod cookie file:$cookieFile");
                exit;
            }
        }
        // Get the absolute path
        $this->_cookieFile = realpath($cookieFile);
        if (!$this->_cookieFile) {
            writeDebug(WRITE_DEBUG_MAIN_FILE, "Failed to get realpath for cookie file");
            exit;
        }
        curl_setopt($this->_session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $this->_session,
            CURLOPT_HTTPHEADER,
            [
                'User-Agent: ' . SN_USER_AGENT
            ]
        );
    }

    function __destruct()
    {
        if ($this->_cookieFile) {
            try {
                if (file_exists($this->_cookieFile)) {
                    unlink($this->_cookieFile);
                }
            } catch (Exception $e) {
                writeDebug(WRITE_DEBUG_MAIN_FILE, "Couldn't delete cookie file " . $this->_cookieFile);
            }
        }
    }

    public function __makeRequest($path, $method = "GET", $data = null, $attempt = 0)
    {
        $site_url    = $this->_api;
        $user_agent  = SN_USER_AGENT;
        if ($attempt < 4) {
            try {
                // Add values to the GET querystring
                $queryString = count($this->_session_params) ? '?' . http_build_query($this->_session_params) : '';
                $url = $site_url . $path . $queryString;

                writeDebug(WRITE_DEBUG_MAIN_FILE, "Identified URL $url");
                ob_start();
                $out = fopen('php://output', 'w');

                $request = curl_init($url);

                writeDebug(WRITE_DEBUG_MAIN_FILE, "Cookie file is {$this->_cookieFile}");
                // FOR DEBUGGING
                if (WRITE_DEBUG_LOG || WRITE_DEBUG_PRINT) {
                    curl_setopt($request, CURLOPT_VERBOSE, true);
                    curl_setopt($request, CURLOPT_STDERR, $out);
                }
                curl_setopt($request, CURLOPT_COOKIEFILE, $this->_cookieFile);
                curl_setopt($request, CURLOPT_COOKIEJAR, $this->_cookieFile);
                curl_setopt($request, CURLOPT_ENCODING, "gzip");
                curl_setopt($request, CURLOPT_USERAGENT, $user_agent);
                curl_setopt($request, CURLOPT_HEADER, 0);
                curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($request, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($request, CURLOPT_TIMEOUT, 2000);
                curl_setopt($request, CURLOPT_CONNECTTIMEOUT, 2000);

                if (is_array($data)) {
                    curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($data));
                }
                curl_setopt($request, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                curl_setopt($request, CURLOPT_CUSTOMREQUEST, $method);

                $response = curl_exec($request);

                /**
                 * COOKIE STRUCTURE (tab-delimited)
                 * https://www.php.net/manual/en/function.curl-setopt.php#118967
                 * char *my_cookie =
                 *  "example.com"    // Hostname //
                 *   SEP "FALSE"      // Include subdomains //
                 *   SEP "/"          // Path //
                 *   SEP "FALSE"      // Secure //
                 *   SEP "0"          // Expiry in epoch time format. 0 == Session //
                 *   SEP "foo"        // Name //
                 *   SEP "bar";       // Value //
                 * 
                 * NEEDED COOKIES:
                 * {
                 * 'name': 'AWSALB', 
                 * 'value': '<longString>', 
                 * 'domain': 'prod-api.sleepiq.sleepnumber.com', 
                 * 'path': '/'
                 * }
                 * {
                 * 'name': 'AWSALBCORS', 
                 * 'value': '<longString>', 
                 * 'domain': 'prod-api.sleepiq.sleepnumber.com', 
                 * 'path': '/'
                 * }
                 * {
                 * 'name': 'JSESSIONID', 
                 * 'value': '<sessionString>', 
                 * 'domain': 'prod-api.sleepiq.sleepnumber.com', 
                 * 'path': '/'
                 * }
                 * 
                 * RECEIVED COOKIES:
                 * "#HttpOnly_.prod-api.sleepiq.sleepnumber.com	TRUE	/	TRUE	0	JSESSIONID	<sessionString>"
                 * "prod-api.sleepiq.sleepnumber.com	FALSE	/	FALSE	1707366272	AWSALB	<longString>"
                 * "prod-api.sleepiq.sleepnumber.com	FALSE	/	TRUE	1707366272	AWSALBCORS	<longString>"
                 * "#HttpOnly_prod-api.sleepiq.sleepnumber.com	FALSE	/	FALSE	0	JSESSIONID	<sessionString>"
                 */
                if ($path == '/login') {
                    // Make sure you have openssl and curl enabled in php.ini
                    // https://stackoverflow.com/questions/28858351/php-ssl-certificate-error-unable-to-get-local-issuer-certificate
                    // Make sure you have the following set from https://curl.se/docs/caextract.html:
                    // curl.cainfo="C:/wamp/cacert.pem"
                    // openssl.cafile="C:/wamp/cacert.pem"
                    $cookies = curl_getinfo($request, CURLINFO_COOKIELIST);
                    // Check the return value of curl_exec(), too
                    if ($response === false) {
                        print curl_error($request) . "::" . curl_errno($request) . "::" . curl_getinfo($request, CURLINFO_HTTP_CODE);
                        exit;
                    }
                }
                writeDebug(WRITE_DEBUG_MAIN_FILE, "CURL_EXEC: $response");

                $responseCode = curl_getinfo($request, CURLINFO_HTTP_CODE);
                writeDebug(WRITE_DEBUG_MAIN_FILE, "Response Code: $responseCode");

                if (curl_errno($request)) {
                    throw new Exception("Response Error: " . curl_error($request));
                }

                fclose($out);
                $outdebug = ob_get_clean();
                writeDebug(WRITE_DEBUG_MAIN_FILE, "CURL: $outdebug");

                // If done after a login, will write the cookies to the cookie file
                curl_close($request);

                $json_response = json_decode($response, true);

                if (!$json_response && $responseCode != 200) {
                    throw new Exception("requestJSON(): Missing/Invalid Response");
                }

                // if (array_key_exists('Error', $json_response) && $path == "/login") {
                //     throw new Exception("Your userid/password is invalid.");
                // }

                if ($this->requestJSONHasLoginErrors($json_response)) {
                    writeDebug(WRITE_DEBUG_MAIN_FILE, "in requestJSONHasLoginErrors");
                    unset($this->_session_params['_k']);
                    writeDebug(WRITE_DEBUG_MAIN_FILE, "token deleted for {$this->_cookieFile}");
                    writeDebug(WRITE_DEBUG_MAIN_FILE, "Would have re-run using $path, " . print_r($data, true) . ", $method\n");
                }

                if (is_array($json_response) && array_key_exists('Error', $json_response)) {
                    throw new Exception("requestJSON(): [" . $json_response['Error']['Code'] . "] " . $json_response['Error']['Message'] . "");
                }

                writeDebug(WRITE_DEBUG_MAIN_FILE, "Dumping response");
                writeDebug(WRITE_DEBUG_MAIN_FILE, $response);
                return $json_response;
            } catch (Exception $e) {
                $retry = $this->__makeRequest($url, $method, $data, $attempt + 1);
                return $retry;
            }
        }
    }

    private function requestJSONHasLoginErrors($response)
    {
        writeDebug(WRITE_DEBUG_JSON_LOGS, "----------------------");
        if (array_key_exists('Error', $response)) {
            $error_code    = array_key_exists('Code', $response['Error'])    ? $response['Error']['Code']    : null;
            $error_message = array_key_exists('Message', $response['Error']) ? $response['Error']['Message'] : null;
            writeDebug(WRITE_DEBUG_JSON_LOGS, "Error code $error_code");
            writeDebug(WRITE_DEBUG_JSON_LOGS, "Error message $error_message");

            $login_error_codes = [
                50002,
                401,
            ];
            $login_error_messages = [
                "Session is invalid",
                "HTTP 401 Unauthorized",
            ];

            if (in_array($error_code, $login_error_codes)) {
                writeDebug(WRITE_DEBUG_JSON_LOGS, "------code------------");
                return true;
            }
            if (in_array($error_message, $login_error_messages)) {
                writeDebug(WRITE_DEBUG_JSON_LOGS, "-------message-------");
                return true;
            }
        }

        writeDebug(WRITE_DEBUG_JSON_LOGS, "-------false-------");
        return false;
    }

    private function __featureCheck($value, $digit)
    {
        if (!$value) {
            return false;
        }
        return (($value >> $digit) & 1) > 0;
    }

    public function login()
    {
        if (isset($this->_session_params['_k'])) {
            unset($this->_session_params['_k']);
        }

        if (empty($this->_login) || empty($this->_password)) {
            throw new Exception("username/password not set");
        }

        $data = ['login' => $this->_login, 'password' => $this->_password];

        $responseJson = $this->__makeRequest('/login', 'PUT', $data);

        if (!is_array($responseJson)) {
            throw new Exception("Login failed");
        }
        $this->_session_params['_k'] = $responseJson['key'];
        writeDebug(WRITE_DEBUG_MAIN_FILE, "***Setting token " . $this->_session_params['_k'] . "\n");
        return true;
    }

    /**
     * {'bed': None,
     * 'data': {'accountId': '<account_id>',
     * 'active': True,
     * 'bedId': '<bed_id>',
     * 'birthMonth': 1,
     * 'birthYear': '1932',
     * 'duration': 0,
     * 'email': 'blah@gmail.com',
     * 'emailValidated': True,
     * 'firstName': 'John',
     * 'firstSessionRecorded': '2020-09-25T05:03:53Z',
     * 'gender': 0, -- 0 for female, 1 for male
     * 'height': 62, -- inches
     * 'isAccountOwner': False,
     * 'isChild': False,
     * 'lastLogin': '2023-10-29T16:34:48Z',
     * 'licenseVersion': 9,
     * 'privacyPolicyVersion': 3,
     * 'side': 0,
     * 'sleepGoal': 480,
     * 'sleeperId': '<sleeper_id>',
     * 'timezone': 'US/Pacific',
     * 'username': 'blah@gmail.com',
     * 'weight': 111,
     * 'zipCode': '55555'}}
     */
    public function sleepers()
    {
        $response = $this->__makeRequest('/sleeper');
        $sleepers = [];
        foreach ($response['sleepers'] as $sleeper) {
            $sleepers[] = new Sleeper($sleeper);
        }
        return $sleepers;
    }

    /**
     * {'data': {'accountId': '<account_id>',
     * 'base': None,
     * 'bedId': '<bed_id>',
     * 'dualSleep': True,
     * 'generation': '360',
     * 'isKidsBed': False,
     * 'macAddress': '<mac_addr>',
     * 'model': 'ILE',
     * 'name': 'iLE',
     * 'purchaseDate': '2020-09-07T03:34:04Z',
     * 'reference': '<ref_id>',
     * 'registrationDate': '2020-09-24T18:53:50Z',
     * 'returnRequestStatus': 0,
     * 'serial': '',
     * 'size': 'KING-SPLIT',
     * 'sku': 'SZILE',
     * 'sleeperLeftId': '<sleeper_id>',
     * 'sleeperRightId': <sleeper_id>',
     * 'status': 1,
     * 'timezone': 'US/Pacific',
     * 'version': '',
     * 'zipcode': '12345-2123'},
     * 'left': None,
     * 'right': None}
     * @param $withFoundationFeatures Default to false. Includes foundation features with bed properties if true.
     * @return array Bed objects with optional foundation features
     */
    public function beds($withFoundationFeatures = false): array
    {
        $response = $this->__makeRequest('/bed');
        $beds = [];
        foreach ($response['beds'] as $bed) {
            $bed = new Bed($bed);
            if ($bed->bedId) {
                $this->_bedGenerations[$bed->bedId] = $bed->generation ?? '';
            }
            if (!$this->_accountId && isset($bed->accountId)) {
                $this->_accountId = $bed->accountId;
            }
            if ($withFoundationFeatures) {
                $bed->foundationFeatures = $this->getFoundationFeatures($bed->bedId);
            }
            $beds[] = $bed;
        }
        return $beds;
    }

    /**
     * Get a bunch of information about the current bed state including sleepr 
     * info.
     * @return [
     *   {
     *       "data": {
     *           "registrationDate": "2020-09-24T18:53:50Z",
     *           "sleeperRightId": "<sleeperId>",
     *           "base": null,
     *           "returnRequestStatus": 0,
     *           "size": "KING-SPLIT",
     *           "name": "iLE",
     *           "serial": "",
     *           "isKidsBed": false,
     *           "dualSleep": true,
     *           "bedId": "<bedId>",
     *           "status": 1,
     *           "sleeperLeftId": "<sleeperId>",
     *           "version": "",
     *           "accountId": "<accountId>",
     *           "timezone": "US\/Pacific",
     *           "generation": "360",
     *           "model": "ILE",
     *           "purchaseDate": "2020-09-07T17:01:08Z",
     *           "macAddress": "<macAddress>",
     *           "sku": "SZILE",
     *           "zipcode": "<zipcode>",
     *           "reference": "<referenceId>"
     *       },
     *       "left": {
     *           "data": {
     *               "isInBed": false,
     *               "alertDetailedMessage": "No Alert",
     *               "sleepNumber": 100,
     *               "alertId": 0,
     *               "lastLink": "00:00:00",
     *               "pressure": 3943
     *           },
     *           "alertDetailedMessage": "No Alert",
     *           "alertId": 0,
     *           "bed": null,
     *           "isInBed": false,
     *           "lastLink": "00:00:00",
     *           "pressure": 3943,
     *           "sleeper": {
     *               "data": {
     *                   "firstName": "<firstName>",
     *                   "active": true,
     *                   "emailValidated": true,
     *                   "gender": 0,
     *                   "isChild": false,
     *                   "bedId": "<bedId>",
     *                   "birthYear": "<year>",
     *                   "zipCode": "<zipCode>",
     *                   "timezone": "US\/Pacific",
     *                   "privacyPolicyVersion": 6,
     *                   "duration": 0,
     *                   "weight": <weight>,
     *                   "sleeperId": "<sleeperId>",
     *                   "firstSessionRecorded": "2020-09-25T05:03:53Z",
     *                   "height": <height>,
     *                   "licenseVersion": 9,
     *                   "username": "<email>",
     *                   "birthMonth": 1,
     *                   "sleepGoal": 480,
     *                   "accountId": "<accountId>",
     *                   "isAccountOwner": false,
     *                   "email": "<email>",
     *                   "lastLogin": "2025-05-24T16:19:04Z",
     *                   "side": 0
     *               },
     *               "bed": null,
     *               "firstName": "<firstName>",
     *               "active": true,
     *               "emailValidated": true,
     *               "gender": 0,
     *               "isChild": false,
     *               "bedId": "<bedId>",
     *               "birthYear": "<year>",
     *               "zipCode": "<zipCode>",
     *               "timezone": "US\/Pacific",
     *               "privacyPolicyVersion": 6,
     *               "duration": 0,
     *               "weight": <weight>,
     *               "sleeperId": "<sleeperId>",
     *               "firstSessionRecorded": "2020-09-25T05:03:53Z",
     *               "height": <height>,
     *               "licenseVersion": 9,
     *               "username": "<email>",
     *               "birthMonth": 1,
     *               "sleepGoal": 480,
     *               "accountId": "<accountId>",
     *               "isAccountOwner": false,
     *               "email": "<email>",
     *               "lastLogin": "2025-05-24T16:19:04Z",
     *               "side": 0
     *           },
     *           "sleepNumber": 100
     *       },
     *       "right": {
     *           ...
     *       },
     *       "sides": {
     *           "left": {
     *               "data": {
     *                   "isInBed": false,
     *                   "alertDetailedMessage": "No Alert",
     *                   "sleepNumber": 100,
     *                   "alertId": 0,
     *                   "lastLink": "00:00:00",
     *                   "pressure": 3943
     *               },
     *               "alertDetailedMessage": "No Alert",
     *               "alertId": 0,
     *               "bed": null,
     *               "isInBed": false,
     *               "lastLink": "00:00:00",
     *               "pressure": 3943,
     *               "sleeper": {
     *                   "data": {
     *                       "firstName": "<firstName>",
     *                       ...
     *                   },
     *                   "bed": null,
     *                   "firstName": "<firstName>",
     *                   ...
     *               },
     *               "sleepNumber": 100
     *           },
     *           "right": {
     *               ...
     *           }
     *       },
     *       "accountId": "<accountId>",
     *       "base": null,
     *       "bedId": "<bedId>",
     *       "dualSleep": true,
     *       "foundationFeatures": null,
     *       "generation": "360",
     *       "isKidsBed": false,
     *       "macAddress": "<macAddress>",
     *       "model": "ILE",
     *       "name": "iLE",
     *       "purchaseDate": "2020-09-07T17:01:08Z",
     *       "reference": "<referenceId>",
     *       "registrationDate": "2020-09-24T18:53:50Z",
     *       "returnRequestStatus": 0,
     *       "serial": "",
     *       "size": "KING-SPLIT",
     *       "sku": "SZILE",
     *       "sleeperLeftId": "<sleeperId>",
     *       "sleeperRightId": "<sleeperId>",
     *       "status": 1,
     *       "timezone": "US\/Pacific",
     *       "version": "",
     *       "zipcode": "<zipcode>"
     *   }
     * ]
     */
    public function bedsWithSleeperStatus()
    {
        $beds = $this->beds();
        $sleepers = $this->sleepers();
        $familyStatuses = $this->getBedFamilyStatus();
        $sleepersById = [];
        foreach ($sleepers as $sleeper) {
            $sleepersById[$sleeper->sleeperId] = $sleeper;
        }
        $bedFamilyStatusesByBedId = [];
        foreach ($familyStatuses as $familyStatus) {
            $bedFamilyStatusesByBedId[$familyStatus->bedId] = $familyStatus;
        }

        foreach ($beds as $bed) {
            $familyStatus = $bedFamilyStatusesByBedId[$bed->bedId] ?? null;
            /**
             * {'bed': None,
             * 'data': {'alertDetailedMessage': 'No Alert',
             * 'alertId': 0,
             * 'isInBed': False,
             * 'lastLink': '00:00:00',
             * 'pressure': 3460,
             * 'sleepNumber': 75},
             * 'sleeper': <sleepyq.Sleeper object at 0x7f938f5d1990>}
             */
            foreach (['left', 'right'] as $side) {
                $sleeperKey = 'sleeper' . ucfirst($side) . 'Id'; // Dynamically created
                $sleeperId = $bed->$sleeperKey; // Dynamically accessed
                if ($sleeperId == "0" || $familyStatus->$side == null) {
                    continue;
                }
                $sleeper = $sleepersById[$sleeperId];
                $status = $familyStatus->$side;
                $status->sleeper = $sleeper;
                $bed->$side = $status;
                $bed->sides[$side] = $status;
            }
        }

        return $beds;
    }

    /**
     * To view the data, you issue a GET to the same endpoint and the response is the JSON you'd PUT.
     * {
     * footWarmingStatusLeft: FOOTWARM_TEMP value
     * footWarmingStatusRight: FOOTWARM_TEMP value
     * footWarmingTimerLeft: FOOTWARM_TIMER value
     * footWarmingTimerRight: FOOTWARM_TIMER value
     * sides => left/right => ['temp' => FOOTWARM_TEMP, 'time' => FOOTWARM_TIME]
     * }
     */
    public function getFoundationFootwarming($bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        // Fuzion path (bamkey)
        if ($this->isFuzionBed($bedId)) {
            // Provide legacy-parity baseline structure (zeros) even if presence is false
            $resp = [
                'footWarmingStatusLeft' => 0,
                'footWarmingStatusRight' => 0,
                'footWarmingTimerLeft' => 0,
                'footWarmingTimerRight' => 0,
                'sides' => [
                    'left' => ['temp' => 0, 'time' => 0],
                    'right' => ['temp' => 0, 'time' => 0],
                ],
            ];
            foreach (self::SIDES_NAMES as $side) {
                try {
                    $present = $this->__bamkey($bedId, 'GetFootwarmingPresence', [$side]);
                    if ($present === '1') {
                        $data = $this->__bamkey($bedId, 'GetFootwarmingSettings', [$side]);
                        $parts = preg_split('/\s+/', trim($data));
                        $stateWord = strtolower($parts[0] ?? 'off');
                        $timeVal = intval($parts[1] ?? 0);
                        $tempVal = $this->fuzionFootwarmTempWordToValue($stateWord);
                        $uc = ucfirst($side);
                        $resp['footWarmingStatus' . $uc] = $tempVal;
                        $resp['footWarmingTimer' . $uc] = $timeVal;
                        $resp['sides'][$side] = ['temp' => $tempVal, 'time' => $timeVal];
                    }
                } catch (Exception $e) {
                    // ignore side errors; retain baseline zeros
                }
            }
            return new FootwarmingStatus($resp);
        }
        // Legacy 360 path
        $response = $this->__makeRequest('/bed/' . $bedId . '/foundation/footwarming');
        try {
            foreach (self::SIDES_NAMES as $side) {
                $ucSide = ucfirst($side);
                if (array_key_exists("footWarmingStatus$ucSide", $response)) {
                    $response['sides'][$side] = [
                        'temp' => $response["footWarmingStatus$ucSide"],
                        'time' => $response["footWarmingTimer$ucSide"],
                    ];
                }
            }
            return new FootwarmingStatus($response);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * https://community.hubitat.com/t/sleepiq-sleep-number/15053/83
     * side: Right and Left.
     * temp: off: 0, low: 31, medium: 57, high: 72
     * timer: 30m,1h,2h,3h,4h,5h,6h (in minutes)
     */
    public function setFoundationFootwarming($side, $temp = self::FOOTWARM_OFF, $timer = self::FOOTWARM_30, $bedId = '')
    {
        /**
         * {
         * "footWarmingTempRight": <temp>,
         * "footWarmingTimerRight": <time in minutes>,
         * }
         */
        if (strtolower($side) == 'r' || strtolower($side) == 'right') {
            $side = "Right";
        } elseif (strtolower($side) == 'l' || strtolower($side) == 'left') {
            $side = "Left";
        } else {
            throw new Exception("Side must be one of the following: left, right, L or R");
        }

        if (!in_array($temp, self::FOOTWARM_TEMP)) {
            throw new Exception("Invalid footwarming temp");
        }

        if (!in_array($timer, self::FOOTWARM_TIMER)) {
            throw new Exception("Invalid footwarming timer duration");
        }

        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            // side is already Left/Right
            $sideFullLower = strtolower($side);
            $sideWord = ($sideFullLower === 'right') ? 'right' : 'left';
            $word = $this->fuzionFootwarmValueToWord($temp);
            $this->__bamkey($bedId, 'SetFootwarmingSettings', [$sideWord, $word, (string)intval($timer)]);
            return true;
        }
        $data = ['footWarmingTemp' . $side => $temp, 'footWarmingTimer' . $side => $timer];
        $this->__makeRequest('/bed/' . $bedId . '/foundation/footwarming', "PUT", $data);
        return true;
    }

    /**
     * {'bed': None,
     * 'data': {
     * 'bedId': '<bed_id>',
     * 'leftSide': {
     * 'alertDetailedMessage': 'No Alert',
     * 'alertId': 0,
     * 'isInBed': False,
     * 'lastLink': '00:00:00',
     * 'pressure': 3460,
     * 'sleepNumber': 75
     * },
     * 'rightSide': {
     * 'alertDetailedMessage': 'No Alert',
     * 'alertId': 0,
     * 'isInBed': False,
     * 'lastLink': '00:00:00',
     * 'pressure': 3307,
     * 'sleepNumber': 70
     * },
     * 'status': 1
     * },
     * 'left': <sleepyq.SideStatus object at 0x7f938fa4f150>,
     * 'right': <sleepyq.SideStatus object at 0x7f938fa4f8d0>}
     */
    public function getBedFamilyStatus()
    {
        $response = $this->__makeRequest('/bed/familyStatus');
        $statuses = [];
        if ($response) {
            foreach ($response['beds'] as $status) {
                $statuses[] = new FamilyStatus($status);
            }
        }
        return $statuses;
    }

    public function defaultBedId($bedId)
    {
        if (empty($bedId)) {
            $beds = $this->beds();
            if (count($beds) == 1) {
                $bedId = $beds[0]->data['bedId'];
            }
            /**
             * This else should never be hit. If an exception is thrown, the 
             * caller of this method is not passing an explicit bedId to some
             * other method when it should be.
             */
            else {
                throw new Exception("Bed ID must be specified if there is more than one bed");
            }
        }
        return $bedId;
    }

    /**
     * Response from /sleepData API:
     * {
     * 'sleepers': [
     * {
     * sleeperId: "<id>",
     * message: "",
     * tip: "",
     * avgHeartRate: 0,
     * avgRespirationRate: 0,
     * totalSleepSessionTime: 0,
     * inBed: 0,
     * outOfBed: 0,
     * restful: 0,
     * restless: 0,
     * avgSleepIQ: 0, // Sleep score
     * sleepData: [
     * {
     * tip: "Get up and go to bed at the same time each and every day, even on weekends, days off and holidays.  This prevents “social jetlag.”",
     * message: "You had an EXCELLENT nights sleep",
     * date: "2024-12-01",
     * sessions: [
     * {
     * startDate: "2024-11-30T22:11:22",
     * longest: true,
     * sleepIQCalculating: false,
     * originalStartDate: "2024-11-30T22:11:22",
     * restful: 29059,
     * originalEndDate: "2024-12-01T07:07:52",
     * sleepNumber: 100,
     * totalSleepSessionTime: 32190,
     * avgHeartRate: 61,
     * restless: 1350,
     * avgRespirationRate: 14,
     * isFinalized: true,
     * sleepQuotient: 97,
     * endDate: "2024-12-01T07:07:52",
     * outOfBed: 0,
     * inBed: 32151
     * }
     * ],
     * goalEntry: null,
     * tags: []
     * }
     * ]
     * }
     * ]
     * }
     * 
     * @param string $sleeperId Optional. If not provided, both Sleepers' data will be returned
     * @param string $interval Defaults to 'D' for Day. Can also be 'M' or 'Y'
     * @return array Of Sleeper objects
     */
    public function getSleepData(string $sleeperId = null, string $interval = 'D'): array
    {
        // If provided interval is not valid, default to D
        if (!in_array($interval, ['D', 'M', 'Y'])) {
            $interval = 'D';
        }
        $params = [
            'interval' => $interval . '1',
            'sleeper' => $sleeperId,
            'includeSlices' => false, // Unsure what this does: https://github.com/rvrolyk/SleepNumberController/blob/master/SleepNumberController_App.groovy#L2505
            'date' => date("Y-m-d"),
        ];
        $query = http_build_query($params);
        $response = $this->__makeRequest('/sleepData?' . $query);
        // If a single sleeper was returned, bundle into an array for consistency
        if (array_key_exists('sleeperId', $response)) {
            $response = ['sleepers' => [$response]];
        }
        $sleepers = [];
        foreach ($response['sleepers'] as $sleeper) {
            $sleepers[] = new Sleeper($sleeper);
        }
        return $sleepers;
    }

    /**
     * https://github.com/danpenn/SleepIQ/blob/1531466e2b64/control.go#L233
     * @param $setting 0=off, 1=on (from LIGHT_SETTING). Auto needs to be set with enableOrDisableUnderBedLighting().
     * @param $light Optional. Only 3 (RIGHT_NIGHT_LIGHT) works, so this is the default. 1-4 based on self::BED_LIGHTS (for me, setting).
     * @param $timer Optional. Defaults to null (no timer). Only applicable for mode 1 (on). Can only be intervals defined via LIGHT_TIMER (or between 0 and 180)
     * @param $bedId Optional
     * @return array
     */
    public function setLightSettingAndTimer($setting, $light = self::RIGHT_NIGHT_LIGHT, $timer = null, $bedId = '')
    {
        if (!in_array($light, self::BED_LIGHTS)) {
            throw new Exception("Invalid light");
        }
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            // For Fuzion we map setting (0/1) to brightness off/high unless brightness explicitly set elsewhere
            $brightnessWord = $setting ? 'high' : 'off';
            $duration = $timer !== null ? intval($timer) : 0; // seconds/mins? Keep minutes as provided
            if ($timer !== null && !in_array($timer, self::LIGHT_TIMER)) {
                // For Fuzion allow raw minute values 0-180 else throw
                if ($duration < 0 || $duration > 180) throw new Exception("Invalid timer duration");
            }
            $this->__bamkey($bedId, 'SetUnderbedLightSettings', [$brightnessWord, (string)$duration]);
            $this->_fuzionLightState[$bedId] = ['state' => $brightnessWord, 'timer' => $duration];
            return ['success' => true];
        }
        $data = [
            'outletId' => $light,
            'setting' => $setting ? 1 : 0
        ];
        if ($timer !== null) {
            if (!in_array($timer, self::LIGHT_TIMER)) {
                throw new Exception("Invalid timer duration");
            }
            $data['timer'] = $timer;
        }
        return $this->__makeRequest('/bed/' . $bedId . '/foundation/outlet', "PUT", $data);
    }

    /**
     * https://github.com/danpenn/SleepIQ/blob/1531466e2b64/control.go#L233
     * @param $brightness Optional. Defaults to LIGHT_BRIGHTNESS_OFF. Should be a value from self::LIGHT_BRIGHTNESS.
     * @param $bedId Optional
     * @return array
     */
    public function setLightBrightness($brightness = self::LIGHT_BRIGHTNESS_OFF, $bedId = '')
    {
        if (!in_array($brightness, self::LIGHT_BRIGHTNESS)) {
            throw new Exception("Invalid light");
        }
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            $word = $this->fuzionBrightnessValueToWord($brightness);
            $this->__bamkey($bedId, 'SetUnderbedLightSettings', [$word, '0']);
            $this->_fuzionLightState[$bedId] = ['state' => $word, 'timer' => 0];
            return ['state' => $word];
        }
        $data = [
            'rightUnderbedLightPWM' => $brightness, // Only this key reflects changes
            'leftUnderbedLightPWM' => $brightness,
        ];
        return $this->__makeRequest('/bed/' . $bedId . '/foundation/system', "PUT", $data);
    }

    /**
     * Same light numbering as set_light
     * @param $light Optional. 1-4. Defaults to RIGHT_NIGHT_LIGHT
     * @param $bedId Optional. If not provided, the default bed will be used.
     * @return Status
     * {'data': {'bedId': '<bed_id>',
     * 'outlet': 3, // Must be 3 for RIGHT_NIGHT_LIGHT
     * 'setting': 0, // On (1) or Off (0)
     * 'timer': None // 0-180
     * }}
     */
    public function getLight($light = self::RIGHT_NIGHT_LIGHT, $bedId = '')
    {
        if (!in_array($light, self::BED_LIGHTS)) {
            throw new Exception("Invalid light");
        }
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            // Determine capability first; if system features show no underbed light, return a structure that clearly indicates absence.
            $features = $this->getFoundationFeatures($bedId); // safe cached call
            $hasLight = isset($features->hasUnderbedLight) ? (bool)$features->hasUnderbedLight : false;
            if (!$hasLight) {
                $core = [
                    'bedId' => $bedId,
                    'outlet' => 3,
                    'setting' => 0,
                    'timer' => 0,
                    'enableAuto' => false,
                    'supported' => false, // explicit marker (legacy callers ignore unknown key)
                ];
                $synthetic = ['data' => $core] + $core;
                return new Status($synthetic);
            }
            $manualVal = $this->bamkeyOrDefault($bedId, 'GetUnderbedLightSettings', [], 'off 0');
            $manualParts = preg_split('/\s+/', trim($manualVal));
            $manualState = strtolower($manualParts[0] ?? 'off');
            $manualTimer = isset($manualParts[1]) ? intval($manualParts[1]) : 0;
            $autoVal = $this->bamkeyOrDefault($bedId, 'GetUnderbedLightAutoSettings', [], 'false off');
            $autoParts = preg_split('/\s+/', trim($autoVal));
            $autoEnabled = isset($autoParts[0]) ? ($autoParts[0] === 'true') : false;
            $autoBrightnessWord = isset($autoParts[1]) ? strtolower($autoParts[1]) : 'off';
            $effectiveStateWord = $autoEnabled ? 'auto' : $manualState;
            $effectiveTimer = $autoEnabled ? 0 : $manualTimer;
            $this->_fuzionLightState[$bedId] = ['state' => $effectiveStateWord, 'timer' => $effectiveTimer, 'auto' => $autoEnabled, 'autoBrightness' => $autoBrightnessWord];
            $core = [
                'bedId' => $bedId,
                'outlet' => 3,
                'setting' => ($effectiveStateWord !== 'off') ? 1 : 0,
                'timer' => $effectiveTimer,
                'enableAuto' => $autoEnabled,
                'supported' => true,
            ];
            $synthetic = ['data' => $core] + $core;
            return new Status($synthetic);
        }
        $this->_session_params['outletId'] = $light; // Must be added to the GET querystring
        $response = $this->__makeRequest('/bed/' . $bedId . '/foundation/outlet');
        unset($this->_session_params['outletId']);
        return new Status($response);
    }

    /**
     * Enable or disable under-bed lighting.
     * https://github.com/danpenn/SleepIQ/blob/1531466e2b64/control.go#L323
     * @param $enable true to enable, false to disable
     * @param $bedId Optional. If not provided, the default bed will be used.
     * @return array
     */
    public function enableOrDisableUnderBedLighting($enable, $bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            // Fuzion: Auto settings controlled via bamkey SetUnderbedLightAutoSettings
            // When enabling auto, first turn manual light off to mimic app behavior
            if ($enable) {
                $this->__bamkey($bedId, 'SetUnderbedLightSettings', ['off', '0']);
                // Use previously cached manual brightness if any, else default to 'low' for auto brightness
                $cached = $this->_fuzionLightState[$bedId]['autoBrightness'] ?? 'low';
                if (!in_array($cached, ['off', 'low', 'medium', 'high'])) $cached = 'low';
                $this->__bamkey($bedId, 'SetUnderbedLightAutoSettings', ['true', $cached]);
                $this->_fuzionLightState[$bedId] = ['state' => 'auto', 'timer' => 0, 'auto' => true, 'autoBrightness' => $cached];
            } else {
                // Disable auto -> set auto false and retain last manual timer/brightness (default off)
                $this->__bamkey($bedId, 'SetUnderbedLightAutoSettings', ['false', 'low']);
                // Manual state remains whatever prior manual setting was cached; if none, off
                $prev = $this->_fuzionLightState[$bedId] ?? [];
                $manualState = isset($prev['state']) && $prev['state'] !== 'auto' ? $prev['state'] : 'off';
                $this->_fuzionLightState[$bedId] = ['state' => $manualState, 'timer' => 0, 'auto' => false, 'autoBrightness' => 'low'];
            }
            return ['success' => true, 'enableAuto' => (bool)$enable];
        }
        $data = ['enableAuto' => (bool)$enable];
        return $this->__makeRequest('/bed/' . $bedId . '/foundation/underbedLight', "PUT", $data);
    }

    /**
     * Check if under-bed lighting auto mode is enabled.
     * @param $bedId Optional. If not provided, the default bed will be used.
     * @return bool true if auto mode is enabled, false otherwise
     */
    public function isUnderBedLightingAutoModeEnabled($bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            // Query auto settings bamkey
            $val = $this->__bamkey($bedId, 'GetUnderbedLightAutoSettings');
            $parts = preg_split('/\s+/', trim($val));
            $enabled = isset($parts[0]) ? ($parts[0] === 'true') : false;
            // Cache auto brightness for potential reuse
            if (!empty($parts[1])) {
                $autoBrightness = strtolower($parts[1]);
                $existing = $this->_fuzionLightState[$bedId] ?? [];
                $existing['autoBrightness'] = $autoBrightness;
                $existing['auto'] = $enabled;
                $this->_fuzionLightState[$bedId] = $existing;
            }
            return $enabled;
        }
        $response = $this->__makeRequest('/bed/' . $bedId . '/foundation/underbedLight');
        if (array_key_exists('enableAuto', $response)) {
            return (bool)$response['enableAuto'];
        }
        throw new Exception("Unable to get under-bed lighting status: " . print_r($response, true));
    }

    /**
     * @param $preset 1-6 based on self::BED_PRESETS
     * @param $side "R" or "L" or "right" or "left" (any capitalization)
     * @param $bedId Optional
     * @param $slowSpeed Optional. Defaults to false. false=fast, true=slow
     */
    public function preset($preset, $side, $bedId = '', $slowSpeed = false)
    {
        if (strtolower($side) == 'r' || strtolower($side) == 'right') {
            $side = "R";
        } elseif (strtolower($side) == 'l' || strtolower($side) == 'left') {
            $side = "L";
        } else {
            throw new Exception("Side must be one of the following: left, right, L or R");
        }

        if (in_array($preset, self::BED_PRESETS)) {
            $bedId = $this->defaultBedId($bedId);
            if ($this->isFuzionBed($bedId)) {
                $presetWord = $this->fuzionPresetNumericToWord($preset);
                if ($presetWord === null) throw new Exception('Unsupported preset');
                $this->__bamkey($bedId, 'SetTargetPresetWithoutTimer', [strtolower($side === 'R' ? 'right' : 'left'), $presetWord]);
                return true;
            }
            $data = ['preset' => $preset, 'side' => $side, 'speed' => $slowSpeed ? 1 : 0];
            $this->__makeRequest('/bed/' . $bedId . '/foundation/preset', "PUT", $data);
            return true;
        } else {
            throw new Exception("Invalid preset");
        }
    }

    /**
     * @param $footSpeed 0-3
     * @param $headSpeed 0-3
     * @param $side "R" or "L"
     * @param $timer Optional. Defaults to 0
     * @param $mode Optional. Defaults to 0. 0-3 based on self::MASSAGE_MODE
     * @param $bedId Optional
     */
    public function setFoundationMassage($footSpeed, $headSpeed, $side, $timer = 0, $mode = 0, $bedId = '')
    {
        if (in_array($mode, self::MASSAGE_MODE)) {
            if ($mode != 0) {
                $footSpeed = 0;
                $headSpeed = 0;
            }
            if (array_reduce([$footSpeed, $headSpeed], function ($carry, $speed) {
                return $carry && in_array($speed, self::MASSAGE_SPEED);
            }, true)) {
                $data = ['footMassageMotor' => $footSpeed, 'headMassageMotor' => $headSpeed, 'massageTimer' => $timer, 'massageWaveMode' => $mode, 'side' => $side];
                $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/adjustment', "PUT", $data);
                return true;
            } else {
                throw new \InvalidArgumentException("Invalid head or foot speed");
            }
        } else {
            throw new \InvalidArgumentException("Invalid mode");
        }
    }

    /**
     * @param $side "R" or "L"
     * @param $setting 0-100 (rounds to nearest multiple of 5)
     * @param $bedId Optional
     */
    public function setSleepnumber($side, $setting, $bedId = '')
    {
        if ($setting < 0 || $setting > 100) {
            throw new \InvalidArgumentException("Invalid SleepNumber, must be between 0 and 100");
        }
        $bedId = $this->defaultBedId($bedId);
        $side = strtolower($side);
        if ($side == 'right' || $side == 'r') {
            $sideShort = "R";
        } elseif ($side == 'left' || $side == 'l') {
            $sideShort = "L";
        } else {
            throw new \InvalidArgumentException("Side must be one of the following: left, right, L or R");
        }
        $rounded = round($setting / 5) * 5;
        if ($this->isFuzionBed($bedId)) {
            $this->__bamkey($bedId, 'StartSleepNumberAdjustment', [strtolower($sideShort === 'L' ? 'left' : 'right'), (string)$rounded]);
            return true;
        }
        $data = [
            'bed' => $bedId,
            'side' => $sideShort,
            "sleepNumber" => $rounded
        ];
        $this->_session_params['side'] = $sideShort; // Must be added to the GET querystring
        $this->__makeRequest('/bed/' . $bedId . '/sleepNumber', "PUT", $data);
        unset($this->_session_params['side']);
        return true;
    }

    public function setFavSleepnumber($side, $setting, $bedId = '')
    {
        if ($setting < 0 || $setting > 100) {
            throw new \InvalidArgumentException("Invalid SleepNumber, must be between 0 and 100");
        }
        $bedId = $this->defaultBedId($bedId);
        $side = strtolower($side);
        if ($side == 'right' || $side == 'r') {
            $sideShort = "R";
            $sideWord = 'right';
        } elseif ($side == 'left' || $side == 'l') {
            $sideShort = "L";
            $sideWord = 'left';
        } else {
            throw new \InvalidArgumentException("Side must be one of the following: left, right, L or R");
        }
        $rounded = round($setting / 5) * 5;
        if ($this->isFuzionBed($bedId)) {
            $this->__bamkey($bedId, 'SetFavoriteSleepNumber', [$sideWord, (string)$rounded]);
            return true;
        }
        $data = ['side' => $sideShort, "sleepNumberFavorite" => $rounded];
        $this->__makeRequest('/bed/' . $bedId . '/sleepNumberFavorite', "PUT", $data);
        return true;
    }

    /**
     * {'data': {'bedId': '<bed_id>',
     * 'sleepNumberFavoriteLeft': 75,
     * 'sleepNumberFavoriteRight': 70},
     * 'left': 75,
     * 'right': 70}
     */
    public function getFavSleepnumber($bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            $leftRaw = $this->bamkeyOrDefault($bedId, 'GetFavoriteSleepNumber', ['left'], null);
            $rightRaw = $this->bamkeyOrDefault($bedId, 'GetFavoriteSleepNumber', ['right'], null);
            $left = is_numeric($leftRaw) ? intval($leftRaw) : null;
            $right = is_numeric($rightRaw) ? intval($rightRaw) : null;
            $r = [
                'bedId' => $bedId,
                'sleepNumberFavoriteLeft' => $left,
                'sleepNumberFavoriteRight' => $right,
                'left' => $left,
                'right' => $right,
                'data' => [
                    'bedId' => $bedId,
                    'sleepNumberFavoriteLeft' => $left,
                    'sleepNumberFavoriteRight' => $right,
                ]
            ];
            // return new FavSleepNumber($synthetic);
        } else {
            $r = $this->__makeRequest('/bed/' . $bedId . '/sleepNumberFavorite');
        }
        $favSleepnumber = new FavSleepNumber($r);
        foreach (['Left', 'Right'] as $side) {
            $side_key = 'sleepNumberFavorite' . $side;
            $favSleepnumberSide = $favSleepnumber->{$side_key};
            $favSleepnumber->{strtolower($side)} = $favSleepnumberSide;
        }
        return $favSleepnumber;
    }

    /**
     * side "R" or "L"
     */
    public function stopMotion($side, $bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            $this->__bamkey($bedId, 'HaltAllActuators');
            return true;
        }
        $side = strtolower($side);
        if ($side == 'right' || $side == 'r') {
            $side = "R";
        } elseif ($side == 'left' || $side == 'l') {
            $side = "L";
        } else {
            throw new \InvalidArgumentException("Side must be one of the following: left, right, L or R");
        }
        $data = ["footMotion" => 1, "headMotion" => 1, "massageMotion" => 1, "side" => $side];
        $this->__makeRequest('/bed/' . $bedId . '/foundation/motion', "PUT", $data);
        return true;
    }

    public function stopPump($bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            $this->__bamkey($bedId, 'InterruptSleepNumberAdjustment');
            return true;
        }
        $this->__makeRequest('/bed/' . $bedId . '/pump/forceIdle', "PUT");
        return true;
    }

    /**
     * {'data': {'fsConfigured': True,
     * 'fsCurrentPositionPreset': '11', -- (when in bed) first digit is left side preset, second digit is right side preset
     * 'fsCurrentPositionPresetLeft': 'Custom',
     * 'fsCurrentPositionPresetRight': 'Custom',
     * 'fsIsMoving': False,
     * 'fsLeftFootActuatorMotorStatus': '00',
     * 'fsLeftFootPosition': '00',
     * 'fsLeftHeadActuatorMotorStatus': '00',
     * 'fsLeftHeadPosition': '0d',
     * 'fsLeftPositionTimerLSB': '00',
     * 'fsLeftPositionTimerMSB': '00',
     * 'fsNeedsHoming': False,
     * 'fsOutletsOn': False,
     * 'fsRightFootActuatorMotorStatus': '00',
     * 'fsRightFootPosition': '00',
     * 'fsRightHeadActuatorMotorStatus': '00',
     * 'fsRightHeadPosition': '0c',
     * 'fsRightPositionTimerLSB': '00',
     * 'fsRightPositionTimerMSB': '00',
     * 'fsStatusSummary': '44',
     * 'fsTimedOutletsOn': False,
     * 'fsTimerPositionPreset': '00',
     * 'fsTimerPositionPresetLeft': 'No timer running, thus no preset to '
     * 'active',
     * 'fsTimerPositionPresetRight': 'No timer running, thus no preset to '
     * 'active',
     * 'fsType': 'Split King'}}
     */
    public function getFoundationStatus($bedId = '')
    {
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/status');
        try {
            $result = new Status($r);
        } catch (\Exception $e) {
            $result = null;
        }
        return $result;
    }

    /**
     * {'data': {'fsBedType': 2,
     * 'fsBoardFaults': 0,
     * 'fsBoardFeatures': 29,
     * 'fsBoardHWRevisionCode': 21,
     * 'fsBoardStatus': 0,
     * 'fsLeftUnderbedLightPWM': 100,
     * 'fsRightUnderbedLightPWM': 1 // Only use this key (not left)
     * }}
     */
    public function getFoundationSystem($bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            // Use cached light state if available to simulate PWM values
            $lightState = $this->_fuzionLightState[$bedId]['state'] ?? 'off';
            $pwm = $this->fuzionBrightnessWordToPwm($lightState);
            $status = [
                'fsLeftUnderbedLightPWM' => $pwm,
                'fsRightUnderbedLightPWM' => $pwm,
                'fsBoardFeatures' => null,
                'fsBedType' => null,
            ];
            return new Status(['data' => $status] + $status);
        }
        $r = $this->__makeRequest('/bed/' . $bedId . '/foundation/system');
        return new Status($r);
    }

    /**
     * @return {
     * 'boardIsASingle': False,
     * 'easternKing': False,
     * 'hasFootControl': True,
     * 'hasFootWarming': True,
     * 'hasMassageAndLight': False,
     * 'hasUnderbedLight': True,
     * 'leftUnderbedLightPMW': 100,
     * 'rightUnderbedLightPMW': 1, // Only use this key (not left)
     * 'single': False,
     * 'splitHead': False,
     * 'splitKing': True,
     * 'data': {
     *      'boardIsASingle': False,
     *      'easternKing': False,
     *      'hasFootControl': True,
     *      'hasFootWarming': True,
     *      'hasMassageAndLight': False,
     *      'hasUnderbedLight': True,
     *      'leftUnderbedLightPMW': 100,
     *      'rightUnderbedLightPMW': 1, // Only use this key (not left)
     *      'single': False,
     *      'splitHead': False,
     *      'splitKing': True
     *   }
     * }
     */
    public function getFoundationFeatures($bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            if (!isset($this->_fuzionFeatureCache[$bedId])) {
                try {
                    $raw = $this->__bamkey($bedId, 'GetSystemConfiguration');
                    $this->_fuzionFeatureCache[$bedId] = $this->fuzionParseSystemConfiguration($raw);
                } catch (Exception $e) {
                    $this->_fuzionFeatureCache[$bedId] = $this->fuzionParseSystemConfiguration('');
                }
            }
            return new FoundationFeatures($this->_fuzionFeatureCache[$bedId]);
        }
        $fs = $this->getFoundationSystem($bedId);
        $fsBoardFeatures = $fs->fsBoardFeatures ?: null;
        $feature = [
            'single' => false,
            'splitHead' => false,
            'splitKing' => false,
            'easternKing' => false,
            'boardIsASingle' => $this->__featureCheck($fsBoardFeatures, 0),
            'hasMassageAndLight' => $this->__featureCheck($fsBoardFeatures, 1),
            'hasFootControl' => $this->__featureCheck($fsBoardFeatures, 2),
            'hasFootWarming' => $this->__featureCheck($fsBoardFeatures, 3),
            'hasUnderbedLight' => $this->__featureCheck($fsBoardFeatures, 4),
            'leftUnderbedLightPMW' => $fs->fsLeftUnderbedLightPWM ?: false,
            'rightUnderbedLightPMW' => $fs->fsRightUnderbedLightPWM ?: false, // Only use this key (not left)
        ];
        if ($feature['hasMassageAndLight']) {
            $feature['hasUnderbedLight'] = true;
        }
        if ($feature['splitKing'] || $feature['splitHead']) {
            $feature['boardIsASingle'] = false;
        }
        return new FoundationFeatures($feature);
    }

    /**
     * @param $side "R" or "L"
     * @param $actuator "H" or "F" (head or foot)
     * @param $position 0-100
     * @param $bedId Optional
     * @param $slowSpeed Optional. Defaults to false. false=fast, true=slow
     */
    public function setFoundationPosition($side, $actuator, $position, $bedId = '', $slowSpeed = false)
    {
        if ($position < 0 || $position > 100) {
            throw new \Exception("Invalid position, must be between 0 and 100");
        }
        $bedId = $this->defaultBedId($bedId);
        $sideShort = strtolower($side) == 'right' ? 'R' : 'L';
        $act = strtolower($actuator) == 'head' ? 'H' : 'F';
        if ($this->isFuzionBed($bedId)) {
            $this->__bamkey($bedId, 'SetActuatorTargetPosition', [strtolower($sideShort === 'L' ? 'left' : 'right'), strtolower($act === 'H' ? 'head' : 'foot'), (string)intval($position)]);
            return true;
        }
        $data = ['position' => $position, 'side' => $sideShort, 'actuator' => $act, 'speed' => $slowSpeed ? 1 : 0];
        $this->__makeRequest('/bed/' . $bedId . '/foundation/adjustment/micro', "PUT", $data);
        return true;
    }

    /**
     * Get current bed presets by side
     * @return [
     *      <side>' => [
     *          'side' => 'left'
     *          'preset' => 1
     *          'bed_id' => '<bed_id>'
     *      ]
     * ]
     */
    public function getBedSidePresets(string $bedId = '')
    {
        $bedId = $this->defaultBedId($bedId);
        if ($this->isFuzionBed($bedId)) {
            $data = [];
            foreach (self::SIDES_NAMES as $side) {
                try {
                    $word = $this->__bamkey($bedId, 'GetCurrentPreset', [$side]);
                    $presetNumber = $this->fuzionPresetWordToNumeric($word);
                    if ($presetNumber !== null) {
                        $data[$side] = [
                            'side' => $side,
                            'preset' => $presetNumber,
                            'bed_id' => $bedId,
                        ];
                    }
                } catch (Exception $e) {
                    // ignore side issues
                }
            }
            return $data;
        }
        $fs = $this->getFoundationStatus($bedId);
        if (!$fs->data) {
            return [
                self::LEFT => [
                    'side' => self::LEFT,
                    'preset' => null,
                    'bed_id' => $bedId,
                ],
            ];
        }
        $presetsString = $fs->fsCurrentPositionPreset;
        $presetsList = str_split($presetsString);
        if ($this->isSingleBed($bedId)) {
            return [
                self::LEFT => [
                    'side' => self::LEFT,
                    'preset' => $presetsList[0],
                    'bed_id' => $bedId,
                ],
            ];
        }
        return [
            self::LEFT => [
                'side' => self::LEFT,
                'preset' => $presetsList[0],
                'bed_id' => $bedId,
            ],
            self::RIGHT => [
                'side' => self::RIGHT,
                'preset' => $presetsList[1],
                'bed_id' => $bedId,
            ],
        ];
    }

    /**
     * Get current bed side statuses
     * @return [
     *   '<bedId>' => [
     *      '<side>' => [
     *         'alertDetailedMessage': 'No Alert',
     *         'alertId': 0,
     *         'isInBed': False,
     *         'lastLink': '00:00:00',
     *         'pressure': 3144,
     *         'sleepNumber': 75
     *      ]
     *   ]
     * ]
     */
    public function getBedSidesStatuses()
    {
        $response = [];
        $statuses = $this->getBedFamilyStatus();
        /**
         * {'bed': None,
         *  'data': {
         *  'alertDetailedMessage': 'No Alert',
         * 'alertId': 0,
         * 'isInBed': False,
         * 'lastLink': '00:00:00',
         * 'pressure': 3144,
         * 'sleepNumber': 75
         *  },
         * 'sleeper': None}
         */
        foreach ($statuses as $status) {
            $response[$status->data['bedId']] = [];
            foreach (self::SIDES_NAMES as $side) {
                $sideStatus = $status->{$side} ?: null;
                if ($sideStatus != null) {
                    $response[$status->data['bedId']][$side] = $sideStatus->data;
                }
            }
        }
        return $response;
    }

    /**
     * Returns if a single bed or not. Uses $foundationFeatures (result of 
     * getFoundationFeatures()) if not null to extract the value. Otherwise,
     * uses $bedId to call getFoundationFeatures.
     */
    public function isSingleBed(string $bedId = '', Status $foundationFeatures = null): bool
    {
        if ($foundationFeatures) {
            $features = $foundationFeatures;
        } else {
            $features = $this->getFoundationFeatures($bedId);
        }
        return $features->single;
    }


    /**
     * Set both sides of the bed to FLAT and sleep number 100
     */
    public function resetBed($bedId = '')
    {
        foreach (self::SIDES_NAMES as $side) {
            $this->preset(self::FLAT, $side, $bedId, false);
            $this->setSleepnumber($side, 100, $bedId);
        }
    }


    /**
     * Set the bed mode to one of the BED_PRESETS values
     */
    public function setBedMode(string $bedId, string $side, $preset)
    {
        $preset = intval($preset);
        if (!in_array($preset, self::BED_PRESETS)) {
            $preset = self::FAVORITE;
        }

        if (!in_array($side, self::SIDES_NAMES)) {
            $side = self::LEFT;
        }

        return $this->preset($preset, $side, $bedId, false);
    }


    /**
     * Set the bed sleep number value
     */
    public function setBedSleepNumber(string $bedId, string $side, int $number)
    {
        return $this->setSleepnumber($side, $number, $bedId);
    }


    /**
     * Set both sides of the bed to FAVORITE and the associated sleep number for each side
     */
    public function setBedToFavorites(string $bedId = ''): bool
    {
        // Initializes the favSleepnumber attribute
        $faves = $this->getFavsleepnumber($bedId);
        foreach (self::SIDES_NAMES as $side) {
            $this->preset(self::FAVORITE, $side, $bedId, false);
            $this->setSleepnumber($side, $faves->{$side}, $bedId);
        }
        return true;
    }


    /**
     * Set one side of the bed to FAVORITE and the associated sleep number
     */
    public function setBedSideToFavorite(string $bedId = '', string $side = self::LEFT): bool
    {
        // Initializes the favSleepnumber attribute
        $faves = $this->getFavsleepnumber($bedId);
        $this->preset(self::FAVORITE, $side, $bedId, false);
        $this->setSleepnumber($side, $faves->{$side}, $bedId);
        return true;
    }


    /**
     * Get the favorite sleep number values as an assoc array [side_name => sleep_number]
     */
    public function getBedFaves(string $bedId)
    {
        $faves = $this->getFavsleepnumber($bedId);
        $sideFaves = [];
        foreach (self::SIDES_NAMES as $side) {
            $sideFaves[$side] = $faves->{$side} ?: null;
        }
        return $sideFaves;
    }
}
