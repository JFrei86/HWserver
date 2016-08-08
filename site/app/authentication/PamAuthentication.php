<?php

namespace app\authentication;
use app\exceptions\AuthenticationException;
use app\libraries\Core;
use app\libraries\FileUtils;

/**
 * Class PamAuthentication
 *
 * Module that utilizes PAM (@link http://www.linux-pam.org/) to handle authentication.
 * Unfortunately, the PHP-PAM package (@link https://pecl.php.net/package/PAM) is depreciated
 * so to do this requires using a cgi script (@see cgi-bin/pam_check.cgi) that runs python to
 * use its supported PAM module. We save the username/password to a tmp file, pass the random
 * filename to the cgi script via GET, then saves the results to another tmp file passing back
 * the filename via GET to this page, all using the cURL library.
 */
class PamAuthentication implements IAuthentication {
    /** @var Core Core library for running the application */
    private $core;
    
    /**
     * PamAuthentication constructor.
     *
     * @param Core $core
     */
    public function __construct(Core $core) {
        $this->core = $core;
    }

    public function authenticate($username, $password) {
        if (!FileUtils::createDir("/tmp/pam")) {
            throw new AuthenticationException("Could not create tmp PAM directory.");
        }

        do {
            $file = md5(uniqid(rand(), true));
        } while (file_exists("/tmp/{$file}"));

        $contents = json_encode(array('username' => $username, 'password' => $password));
        if (file_put_contents("/tmp/{$file}", $contents) === false) {
            throw new AuthenticationException("Could not create tmp user PAM file.");
        }
        register_shutdown_function(function() use ($file) {
            unlink("/tmp/{$file}");
        });

        // Open a cURL connection so we don't have to do a weird redirect chain to authenticate
        // as that would require some hacky path handling specific to PAM authentication
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->core->getConfig()->getCgiUrl()."pam_check.cgi?file={$file}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        if ($output === false) {
            throw new AuthenticationException(curl_error($ch));
        }
        $output = json_decode($output, true);
        curl_close($ch);

        if ($output === null) {
            throw new AuthenticationException("Error JSON response for PAM: ".json_last_error_msg());
        }
        else if (!isset($output['authenticated'])) {
            throw new AuthenticationException("Missing response in JSON for PAM");
        }
        
        return $output['authenticated'];
    }
}