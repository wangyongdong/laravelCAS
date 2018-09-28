<?php
namespace Cas;

use phpCAS;

class Cas {

    /**
     * Cas" configuration settings array
     */
    protected $config;

    /**
     * Fake user for use when the "CAS" server cannot be called
     */
    protected $_maskdummy = false;

    /**
     * @param array $config
     */
    public function __construct(array $config) {
        if(!$this->validateCasAuth()) {
            return false;
        }

        $this->config = $config;

        $this->debug();

        $this->setsess();

        $this->initializerCas(!empty($this->config['CAS_PROXY']) ? 'client' : $this->config['CAS_PROXY'], $this->initializer());

        // Set url
        $this->set_service_url();

        // For production use set the CA certificate that is the issuer of the cert
        // on the CAS server and uncomment the line below
        $this->configureCasCert();

        $this->dummy_user();
    }

    /**
     * If a fake user is set in the configuration
     */
    protected function dummy_user() {
        if ($this->config['CAS_MASK_DUMMY']) {
            $this->_maskdummy = true;
            phpCAS::log( 'Masquerading as user: '. $this->config['CAS_MASK_DUMMY'] );
        }
    }

    /**
     * set session and cookie
     */
    protected function setsess() {
        // Fix for PHP 7.2.  See http://php.net/manual/en/function.session-name.php
        if (!headers_sent() && session_id() == "" ) {
            session_name($this->config['SESSION_NAME']);

            // Harden session cookie to prevent some attacks on the cookie (e.g. XSS)
            session_set_cookie_params($this->config['SESSION_MAX_LIFE'],
                $this->config['SESSION_PATH'],
                env('APP_DOMAIN'),
                env('HTTPS_ONLY_COOKIES'),
                $this->config['SESSION_HTTPONLY']
            );
        }
    }

    /**
     * set debug and verbose
     */
    public function debug() {
        if ($this->config['CAS_DEBUG'] === true) {
            phpCAS::setDebug();
            phpCAS::log( 'Loaded configuration:' . PHP_EOL . serialize($this->config) );
        } else {
            phpCAS::setDebug($this->config['cas_debug'] );
        }

        phpCAS::setVerbose($this->config['CAS_VERBOSE']);
    }

    /**
     * Set up login and logout url
     */
    public function set_service_url() {
        // Set the login URL of the CAS server.
        if($this->config['CAS_LOGIN_URL']) {
            phpCAS::setServerLoginURL($this->config['CAS_LOGIN_URL']);
        }

        // If specified, this will override the URL the user will be returning to.
        if($this->config['CAS_REDIRECT_PATH']) {
            // Set the fixed URL that will be set as the CAS service parameter.
            // When this method is not called, a phpCAS script uses its own URL.
            phpCAS::setFixedServiceURL( $this->config['CAS_REDIRECT_PATH'] );
        }

        phpCAS::setServerLogoutURL($this->config['CAS_LOGOUT_URL']);
    }

    /**
     * Get phpCAS initializer server type
     * @return mixed|string
     */
    protected function initializer() {
        if ($this->config['CAS_ENABLE_SAML']) {
            $server_type = SAML_VERSION_1_1;
        } else {
            // This allows the user to use 1.0, 2.0, etc as a string in the config
            $cas_version_str = $this->config['CAS_VERSION'];

            // We pull the phpCAS constant values as this is their definition
            // PHP will generate a E_WARNING if the version string is invalid which is helpful for troubleshooting
            $server_type = constant($cas_version_str);

            if (is_null($server_type)) {
                // This will never be null, but can be invalid values for which we need to detect and substitute.
                phpCAS::log( 'Invalid CAS version set; Reverting to defaults' );
                $server_type = CAS_VERSION_2_0;
            }
        }

        return $server_type;
    }

    /**
     * Configure CAS Client|Proxy
     *
     * @param $method
     */
    protected function initializerCas($method = 'client', $initializer) {
        phpCAS::$method(
            $initializer,
            $this->config['CAS_HOST'],
            (int) $this->config['CAS_PORT'],
            $this->config['CAS_CONTENT'], $this->config['CAS_CONTROL_SESSION']
        );

        if ($this->config['CAS_ENABLE_SAML']) {
            // Handle SAML logout requests that emanate from the CAS host exclusively.
            // Failure to restrict SAML logout requests to authorized hosts could
            // allow denial of service attacks where at the least the server is
            // tied up parsing bogus XML messages.
            phpCAS::handleLogoutRequests(true, explode( ',', $this->config['CAS_REAL_HOSTS']));
        }
    }

    /**
     * Configure SSL Validation
     * Having some kind of server cert validation in production is highly recommended.
     */
    protected function configureCasCert() {
        if (!empty($this->config['CAS_CERT_PATH'])) {
            // You can also disable the validation of the certficate CN. This means the
            // certificate must be valid but the CN of the certificate must not match the
            // IP or hostname you are using to access the server
            phpCAS::setCasServerCACert($this->config['CAS_CERT_PATH'], $this->config['CAS_CERT_VALIDATE_CN']);
        } else {
            // For quick testing you can disable SSL validation of the CAS server.
            // THIS SETTING IS NOT RECOMMENDED FOR PRODUCTION.
            // VALIDATING THE CAS SERVER IS CRUCIAL TO THE SECURITY OF THE CAS PROTOCOL!
            phpCAS::setNoCasServerValidation();
        }
    }

    /**
     * Get login url
     * @return string
     */
    public static function login_url() {
        return \phpCAS::getServerLoginURL();
    }

    /**
     * Get logout url
     * @return string
     */
    public static function logout_url() {
        return \phpCAS::getServerLogoutURL();
    }

    /**
     * Logout of the CAS session and redirect users.
     *
     * @param string $url
     * @param string $service
     */
    public function logout($url = '', $service = '' ) {
        if (phpCAS::isSessionAuthenticated()) {
            if (isset($_SESSION['phpCAS'])) {
                $serialized = serialize($_SESSION['phpCAS']);
                phpCAS::log('Logout requested, but no session data found for user:' . PHP_EOL . $serialized );
            }
        }
        $params = [];
        if ($service) {
            $params['service'] = $service;
        } elseif ($this->config['CAS_LOGOUT_REDIRECT']) {
            $params['service'] = $this->config['CAS_LOGOUT_REDIRECT'];
        } else {
            if(empty($url)) {
                if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
                    $params['service'] = 'https://';
                } else {
                    $params['service'] = 'http://';
                }
                $params['service'] .= $_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'];
            }
        }
        if ($url) {
            $params['url'] = $url;
        }
//        phpCAS::logoutWithRedirectService($url);
        phpCAS::logout($params);
        exit;
    }

    /**
     * Retrieve authenticated credentials.
     * Returns either the masqueraded account or the phpCAS user.
     *
     * @return string
     */
    public function user() {
        if ($this->_maskdummy) {
            return $this->config['CAS_MASK_DUMMY'];
        }

        return phpCAS::getUser();
    }

    /**
     * Authenticates the user based on the current request.
     *
     * @return bool
     */
    public function authenticate() {
        if ($this->_maskdummy) {
            return true;
        }
        return phpCAS::forceAuthentication();
    }

    /**
     * Checks to see is user is globally in CAS
     *
     * @return boolean
     */
    public function checkAuthentication() {
        if($this->_maskdummy) {
            return true;
        }
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            // 1.可能出现页面显示到提交这段时间内，其他站点登录，本站无效问题
            // 2.直接跨域POST请求，可能发生身份取得不正确的问题
            $auth = $this->isAuthenticated();
        } else {
             $auth = phpCAS::checkAuthentication();
        }
        if (!$auth) {
            return false;
        }
        return true;
    }

    /**
     * Checks to see is user is authenticated locally
     *
     * @return boolean
     */
    public function isAuthenticated() {
        return $this->_maskdummy ? true : phpCAS::isAuthenticated();
    }

    /**
     * validate HTTP_USER_AGENT
     * @return bool
     */
    private static function validateCasAuth() {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }
        $agent = $_SERVER['HTTP_USER_AGENT'];
        $spiders = array(
            'Googlebot',
            'msnbot',
            'Baiduspider',
            'bingbot',
            'Sogou web spider',
            'Sogou inst spider',
            'Sogou Pic Spider',
            'JikeSpider',
            'Sosospider',
            'Slurp',
            '360Spider',
            'YodaoBot',
            'OutfoxBot',
            'fast-webcrawler',
            'lycos_spider',
            'scooter',
            'ia_archiver',
            'MJ12bot',
            'AhrefsBot',
            'Yisouspider',
        );
        foreach($spiders as $spider) {
            if (stristr($agent, $spider)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pass through undefined methods to phpCAS
     * @param $method
     * @param $params
     * @return mixed
     */
    public function __call($method, $params) {
        if (method_exists('phpCAS', $method) && is_callable(['phpCAS', $method])) {
            return call_user_func_array(['phpCAS', $method], $params);
        }
        throw new \BadMethodCallException( 'Method not callable in phpCAS ' . $method . '::' . print_r($params,true));
    }
}
