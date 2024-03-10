<?php

/**
 * sleepyq.php
 * References:
 * https://github.com/technicalpickles/sleepyq/blob/master/sleepyq/__init__.py
 * https://github.com/tuctboh/adjustTheBed/blob/master/adjustTheBed-main.php
 * https://raw.githubusercontent.com/rvrolyk/SleepNumberController/master/SleepNumberController_App.groovy
 * https://community.hubitat.com/t/release-sleep-number-controller-control-your-sleep-number-bed-and-use-it-for-presence/46454/27?page=2
 */

// Change these DEFINE() values as desired
define('WRITE_DEBUG_PRINT', false); // Set to true to enable logging to files
define('WRITE_DEBUG_LOG', false); // Set to true to enable printing logs
define('WRITE_DEBUG_MAIN_FILE', '/tmp/writeDebugLogs.txt');
define('WRITE_DEBUG_JSON_LOGS', '/tmp/requestJSONHasLoginErrors.txt');
define('SLEEPYQ_COOKIE_PATH', 'cookie/'); // Directory where cookie files will be stored while in use

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
        $this->left = new SideStatus($data['leftSide']);
        $this->right = new SideStatus($data['rightSide']);
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
    public $rightUnderbedLightPMW = null;
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

    const RIGHT_NIGHT_STAND = 1;
    const LEFT_NIGHT_STAND = 2;
    const RIGHT_NIGHT_LIGHT = 3;
    const LEFT_NIGHT_LIGHT = 4;

    const BED_LIGHTS = [
        self::RIGHT_NIGHT_STAND,
        self::LEFT_NIGHT_STAND,
        self::RIGHT_NIGHT_LIGHT,
        self::LEFT_NIGHT_LIGHT
    ];

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

                if (array_key_exists('Error', $json_response) && $path == "/login") {
                    print "Your userid/password or adjustTheBedPassProxy is invalid. Please say Alexa, ask adjust the bed to reset my information to get new signup information";
                    exit;
                }

                if ($this->requestJSONHasLoginErrors($json_response)) {
                    writeDebug(WRITE_DEBUG_MAIN_FILE, "in requestJSONHasLoginErrors");
                    unset($this->_session_params['_k']);
                    writeDebug(WRITE_DEBUG_MAIN_FILE, "token deleted for {$this->_cookieFile}");
                    writeDebug(WRITE_DEBUG_MAIN_FILE, "Would have re-run using  $path , $data, $method\n");
                    $json_response = $this->__makeRequest($path, $data, $method);
                    writeDebug(WRITE_DEBUG_MAIN_FILE, "re-executing command");
                }

                if (array_key_exists('Error', $json_response)) {
                    throw new Exception("requestJSON(): [" . $json_response->Error->Code . "] " . $json_response->Error->Message . "");
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
     * @return array Bed objects with optional foudnation features
     */
    public function beds($withFoundationFeatures = false): array
    {
        $response = $this->__makeRequest('/bed');
        $beds = [];
        foreach ($response['beds'] as $bed) {
            $bed = new Bed($bed);
            if ($withFoundationFeatures) {
                $bed->foundationFeatures = $this->getFoundationFeatures($bed->bedId);
            }
            $beds[] = $bed;
        }
        return $beds;
    }

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
                $sleeperKey = 'sleeper_' . $side . '_id';
                $sleeperId = $bed->$sleeperKey;
                if ($sleeperId == "0") {
                    continue;
                }
                $sleeper = $sleepersById[$sleeperId];
                $status = $familyStatus->$side;
                $status->sleeper = $sleeper;
                $bed->$side = $status;
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
        $response = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/footwarming');
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
            $result = new FootwarmingStatus($response);
        } catch (Exception $e) {
            $result = null;
        }
        return $result;
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

        $data = ['footWarmingTemp' . $side => $temp, 'footWarmingTimer' . $side => $timer];
        $response = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/footwarming', "PUT", $data);
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
        foreach ($response['beds'] as $status) {
            $statuses[] = new FamilyStatus($status);
        }
        return $statuses;
    }

    public function defaultBedId($bedId)
    {
        if (empty($bedId)) {
            $beds = $this->beds();
            if (count($beds) == 1) {
                $bedId = $beds[0]->data['bedId'];
            } else {
                throw new Exception("Bed ID must be specified if there is more than one bed");
            }
        }
        return $bedId;
    }

    /**
     * @param $light 1-4 based on self::BED_LIGHTS
     * @param $setting false=off, true=on
     * @param $bedId Optional
     */
    public function setLight($light, $setting, $bedId = '')
    {
        if (in_array($light, self::BED_LIGHTS)) {
            $data = ['outletId' => $light, 'setting' => $setting ? 1 : 0];
            $response = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/outlet', "PUT", $data);
            return true;
        } else {
            throw new Exception("Invalid light");
        }
    }

    /**
     * Same light numbering as set_light
     * RIGHT_NIGHT_LIGHT
     * {'data': {'bedId': '<bed_id>',
     * 'outlet': 3,
     * 'setting': 0,
     * 'timer': None}}
     */
    public function getLight($light, $bedId = '')
    {
        if (in_array($light, self::BED_LIGHTS)) {
            $this->_session_params['outletId'] = $light; // Must be added to the GET querystring
            $response = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/outlet');
            unset($this->_session_params['outletId']);
            return new Status($response);
        } else {
            throw new Exception("Invalid light");
        }
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
            $data = ['preset' => $preset, 'side' => $side, 'speed' => $slowSpeed ? 1 : 0];
            $response = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/preset', "PUT", $data);
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
        $side = strtolower($side);
        if ($side == 'right' || $side == 'r') {
            $side = "R";
        } elseif ($side == 'left' || $side == 'l') {
            $side = "L";
        } else {
            throw new \InvalidArgumentException("Side must be one of the following: left, right, L or R");
        }
        $data = [
            'bed' => $this->defaultBedId($bedId),
            'side' => $side,
            "sleepNumber" => round($setting / 5) * 5
        ];
        $this->_session_params['side'] = $side; // Must be added to the GET querystring
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/sleepNumber', "PUT", $data);
        unset($this->_session_params['side']);
        return true;
    }

    public function setFavSleepnumber($side, $setting, $bedId = '')
    {
        if ($setting < 0 || $setting > 100) {
            throw new \InvalidArgumentException("Invalid SleepNumber, must be between 0 and 100");
        }
        $side = strtolower($side);
        if ($side == 'right' || $side == 'r') {
            $side = "R";
        } elseif ($side == 'left' || $side == 'l') {
            $side = "L";
        } else {
            throw new \InvalidArgumentException("Side must be one of the following: left, right, L or R");
        }
        $data = ['side' => $side, "sleepNumberFavorite" => round($setting / 5) * 5];
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/sleepNumberFavorite', "PUT", $data);
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
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/sleepNumberFavorite');
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
        $side = strtolower($side);
        if ($side == 'right' || $side == 'r') {
            $side = "R";
        } elseif ($side == 'left' || $side == 'l') {
            $side = "L";
        } else {
            throw new \InvalidArgumentException("Side must be one of the following: left, right, L or R");
        }
        $data = ["footMotion" => 1, "headMotion" => 1, "massageMotion" => 1, "side" => $side];
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/motion', "PUT", $data);
        return true;
    }

    public function stopPump($bedId = '')
    {
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/pump/forceIdle', "PUT");
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
     * 'fsRightUnderbedLightPWM': 1}}
     */
    public function getFoundationSystem($bedId = '')
    {
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/system');
        return new Status($r);
    }

    /**
     * {'data': {'boardIsASingle': False,
     * 'easternKing': False,
     * 'hasFootControl': True,
     * 'hasFootWarming': True,
     * 'hasMassageAndLight': False,
     * 'hasUnderbedLight': True,
     * 'leftUnderbedLightPMW': 100,
     * 'rightUnderbedLightPMW': 1,
     * 'single': False,
     * 'splitHead': False,
     * 'splitKing': True}}
     */
    public function getFoundationFeatures($bedId = '')
    {
        $fs = $this->getFoundationSystem($this->defaultBedId($bedId));
        $fsBoardFeatures = $fs->fsBoardFeatures;
        $fsBedType = $fs->fsBedType;

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
            'leftUnderbedLightPMW' => $fs->fsLeftUnderbedLightPWM,
            'rightUnderbedLightPMW' => $fs->fsRightUnderbedLightPWM
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

        $side = strtolower($side) == 'right' ? 'R' : 'L';
        $actuator = strtolower($actuator) == 'head' ? 'H' : 'F';
        $data = ['position' => $position, 'side' => $side, 'actuator' => $actuator, 'speed' => $slowSpeed ? 1 : 0];
        $r = $this->__makeRequest('/bed/' . $this->defaultBedId($bedId) . '/foundation/adjustment/micro', "PUT", $data);
        return true;
    }

    /**
     * Get current bed presets by side
     */
    public function getBedSidePresets(string $bedId = '')
    {
        $fs = $this->getFoundationStatus($bedId);
        $presetsString = $fs->fsCurrentPositionPreset;
        $presetsList = str_split($presetsString);
        $presetData = [];

        if ($this->isSingleBed($bedId)) {
            $presetData = [
                self::LEFT => [
                    'side' => self::LEFT,
                    'preset' => $presetsList[0],
                    'bed_id' => $bedId,
                ],
            ];
        } else {
            $presetData = [
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

        return $presetData;
    }

    /**
     * Get current bed side statuses
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
                    $response[$status->data['bedId']][$side] = [
                        'pressure' => $sideStatus->data['pressure'],
                        'sleepnumber' => $sideStatus->data['sleepNumber'],
                    ];
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
        $this->setSleepnumber($side, $faves->{$side} ?: $bedId);
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
