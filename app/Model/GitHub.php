<?php

namespace Model;

require __DIR__.'/../../vendor/OAuth/bootstrap.php';

use OAuth\Common\Storage\Session;
use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Uri\UriFactory;
use OAuth\ServiceFactory;
use OAuth\Common\Http\Exception\TokenResponseException;

/**
 * GitHub model
 *
 * @package  model
 */
class GitHub extends Base
{
    /**
     * Authenticate a GitHub user
     *
     * @access public
     * @param  string  $github_id   GitHub user id
     * @return boolean
     */
    public function authenticate($github_id)
    {
        $userModel = new User($this->db, $this->event);

        $user = $userModel->getByGitHubId($github_id);

        if ($user) {

            // Create the user session
            $userModel->updateSession($user);

            // Update login history
            $lastLogin = new LastLogin($this->db, $this->event);
            $lastLogin->create(
                LastLogin::AUTH_GITHUB,
                $user['id'],
                $userModel->getIpAddress(),
                $userModel->getUserAgent()
            );

            return true;
        }

        return false;
    }

    /**
     * Unlink a GitHub account for a given user
     *
     * @access public
     * @param  integer   $user_id    User id
     * @return boolean
     */
    public function unlink($user_id)
    {
        $userModel = new User($this->db, $this->event);

        return $userModel->update(array(
            'id' => $user_id,
            'github_id' => '',
        ));
    }

    /**
     * Update the user table based on the GitHub profile information
     *
     * @access public
     * @param  integer   $user_id    User id
     * @param  array     $profile    GitHub profile
     * @return boolean
     * @todo Don't overwrite existing email/name with empty GitHub data
     */
    public function updateUser($user_id, array $profile)
    {
        $userModel = new User($this->db, $this->event);
        
        return $userModel->update(array(
            'id' => $user_id,
            'github_id' => $profile['id'],
            'email' => $profile['email'],
            'name' => $profile['name'],
        ));
    }

    /**
     * Get the GitHub service instance
     *
     * @access public
     * @return \OAuth\OAuth2\Service\GitHub
     */
    public function getService()
    {
        $uriFactory = new UriFactory();
        $currentUri = $uriFactory->createFromSuperGlobalArray($_SERVER);
        $currentUri->setQuery('controller=user&action=gitHub');

        $storage = new Session(false);

        $credentials = new Credentials(
            GITHUB_CLIENT_ID,
            GITHUB_CLIENT_SECRET,
            $currentUri->getAbsoluteUri()
        );

        $serviceFactory = new ServiceFactory();

        return $serviceFactory->createService(
            'gitHub',
            $credentials,
            $storage,
            array('')
        );
    }

    /**
     * Get the authorization URL
     *
     * @access public
     * @return \OAuth\Common\Http\Uri\Uri
     */
    public function getAuthorizationUrl()
    {
        return $this->getService()->getAuthorizationUri();
    }

    /**
     * Get GitHub profile information from the API
     *
     * @access public
     * @param  string    $code   GitHub authorization code
     * @return bool|array
     */
    public function getGitHubProfile($code)
    {
        try {
            $gitHubService = $this->getService();
            $gitHubService->requestAccessToken($code);
            
            return json_decode($gitHubService->request('user'), true);
        }
        catch (TokenResponseException $e) {
            return false;
        }

        return false;
    }
    
    /**
     * Revokes this user's GitHub tokens for Kanboard
     *
     * @access public
     * @return bool|array
     * @todo Currently this simply removes all our tokens for this user, ideally it should
     *       restrict itself to the one in question
     */
    public function revokeGitHubAccess()
    {
        try {
            $gitHubService = $this->getService();

            $basicAuthHeader = array('Authorization' => 'Basic ' .
            base64_encode(GITHUB_CLIENT_ID.':'.GITHUB_CLIENT_SECRET));

            return json_decode($gitHubService->request('/applications/'.GITHUB_CLIENT_ID.'/tokens', 'DELETE', null, $basicAuthHeader), true);
        }
        catch (TokenResponseException $e) {
            return false;
        }

        return false;
    }
}
