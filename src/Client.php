<?php

namespace Fortnite;

use Fortnite\Http\HttpClient;
use Fortnite\Http\ResponseParser;
use Fortnite\Http\TokenMiddleware;
use Fortnite\Http\FortniteAuthMiddleware;

use Fortnite\Http\Exception\FortniteException;

use Fortnite\Api\Account;
use Fortnite\Api\Profile;
use Fortnite\Api\SystemFile;
use Fortnite\Api\News;
use Fortnite\Api\Store;
use Fortnite\Api\Leaderboard;
use Fortnite\Api\Status;

use Fortnite\Api\Exception\TwoFactorRequiredException;

use GuzzleHttp\Middleware;

class Client {

    const EPIC_ACCOUNT_ENDPOINT         = 'https://account-public-service-prod03.ol.epicgames.com/account/api/';
    const EPIC_OAUTH_EXCHANGE_ENDPOINT  = 'https://account-public-service-prod03.ol.epicgames.com/account/api/oauth/exchange';
    const EPIC_OAUTH_VERIFY_ENDPOINT    = 'https://account-public-service-prod03.ol.epicgames.com/account/api/oauth/verify';
    const EPIC_FRIENDS_ENDPOINT         = 'https://friends-public-service-prod06.ol.epicgames.com/friends/api/public/friends/';

    private $httpClient;
    private $options;

    private $accountId;
    private $accountInfo;

    private $challenge;
    private $deviceId;

    private $in_app_id;

    public function __construct($options = [])
    {
        $this->options = $options;

        // Create a random hash to be used for the device ID.
        // This random ID can be overwritten by passing $deviceId to login().
        $this->deviceId = md5(uniqid());
    }
    
    /**
     * Login to Fortnite using Epic email and password.
     *
     * @param string $email The email.
     * @param string $password The password.
     * @param string $deviceId The device ID.
     * 
     * The device ID parameter is optional, and should only be used if the account you're logging in with has two factor authentication enabled.
     * If you've logged in with this device token, you won't have to enter 2FA details.
     * 
     * @return void
     */
    public function login(string $email, string $password, string $deviceId = '') 
    {
        if ($deviceId != '') {
            $this->deviceId = $deviceId;
        }

        $handler = \GuzzleHttp\HandlerStack::create();
        $handler->push(Middleware::mapRequest(new FortniteAuthMiddleware($this->deviceId)));

        $newOptions = array_merge(['handler' => $handler], $this->options);

        $this->httpClient = new HttpClient(new \GuzzleHttp\Client($newOptions));

        try {
            // Get our Epic Launcher authorization token.
            $response = $this->httpClient()->post(self::EPIC_ACCOUNT_ENDPOINT . 'oauth/token', [
                'grant_type'    => 'password',
                'username'      => $email,
                'password'      => $password,
                'includePerms'  => 'false',
                'token_type'    => 'eg1'
            ]);
        } catch (FortniteException $e) {
            if ($e->code() === 'errors.com.epicgames.common.two_factor_authentication.required') {
                $this->challenge = $e->challenge();
                throw new TwoFactorRequiredException();
            }
            throw $e;
        }

        $this->httpClient = $this->buildHttpClient($response);

        $this->account()->killSession();
    }

    public function twoFactor(string $code) : void
    {
        if (!$this->challenge) {
            throw new Exception('Two factor challenge has not been set.');
        }

        $response = $this->httpClient()->post(self::EPIC_ACCOUNT_ENDPOINT . 'oauth/token', [
            'grant_type'    =>  'otp',
            'otp'          =>   $code,
            'challenge'     =>  $this->challenge,
            'includePerms'  =>  'true',
            'token_type'    =>  'eg1'
        ]);

        $this->httpClient = $this->buildHttpClient($response);

        $this->account()->killSession();
    }

    /**
     * Creates a new HttpClient for making authenticated requests to the Fortnite API.
     *
     * @param object $response The login response data.
     * @return HttpClient
     */
    private function buildHttpClient(object $response) : HttpClient
    {
        $this->accountId = $response->account_id;
        $this->in_app_id = $response->in_app_id ?? "";

        $handler = \GuzzleHttp\HandlerStack::create();
        $handler->push(Middleware::mapRequest(
            new TokenMiddleware($response->access_token, $response->refresh_token, $response->expires_in, $this->deviceId))
        );

        $newOptions = array_merge(['handler' => $handler], $this->options);

        return new HttpClient(new \GuzzleHttp\Client($newOptions));
    }

    /**
     * Gets the HttpClient.
     *
     * @return HttpClient
     */
    public function httpClient() : HttpClient
    {
        return $this->httpClient;
    }

    /**
     * Gets the user's account ID.
     *
     * @return string
     */
    public function accountId() : string
    {
        return $this->accountId;
    }

    /**
     * Gets the in app ID (for leaderboard cohort).
     *
     * @return string
     */
    public function inAppId() : string
    {
        return $this->in_app_id;
    }

    /**
     * Gets the device ID used for two factor authenticated requests.
     * 
     * This Id can be used once you've logged in with it to automatically login even if 2FA is active on your account.
     *
     * @return string
     */
    public function deviceId() : string
    {
        return $this->deviceId;
    }

    /**
     * Gets the user's Epic display name
     *
     * @return string
     */
    public function displayName() : string
    {
        return $this->accountInfo()->displayName;
    }

    public function accountInfo() : object
    {
        if ($this->accountInfo === null) {
            $this->accountInfo = $this->httpClient()->get(sprintf(self::EPIC_ACCOUNT_ENDPOINT . 'public/account/%s', $this->accountId()));
        }

        return $this->accountInfo;
    }

    /**
     * Gets the logged in user's account.
     *
     * @return Account
     */
    public function account() : Account
    {
        return new Account($this);
    }

    /**
     * Gets a user's profile.
     *
     * @param string $username The user's display name.
     * @return Profile
     */
    public function profile(string $username = null) : Profile
    {
        return new Profile($this, $username ?? $this->displayName());
    }

    /**
     * Gets a matchmaking session.
     *
     * @param string $sessionId The session ID.
     * @return Session
     */
    public function session(string $sessionId) : Session
    {
        return new Session($this, $sessionId);
    }

    /**
     * Gets leaderboard information
     *
     * @param string $platform The platform @see Api\Type\Platform
     * @param string $mode The mode @see Api\Type\Mode
     * @return Leaderboard
     */
    public function leaderboards(string $platform, string $mode) : Leaderboard
    {
        return new Leaderboard($this, $platform, $mode);
    }

    /**
     * Gets system files (hotfixes)
     *
     * @return array Array of Api\SystemFile.
     */
    public function systemFiles() : array
    {
        $returnSystemFiles = [];
        
        $files = $this->httpClient()->get(SystemFile::SYSTEM_API);

        foreach ($files as $file) {
            $returnSystemFiles[] = new SystemFile($this, $file);
        }

        return $returnSystemFiles;
    }

    /**
     * Gets Fortnite news.
     *
     * @return News
     */
    public function news() : News
    {
        return new News($this);
    }

    /**
     * Gets the Store Front.
     *
     * @return Store
     */
    public function store() : Store
    {
        return new Store($this);
    }

    /**
     * Gets the Fortnite status.
     *
     * @return Status
     */
    public function status() : Status
    {
        return new Status($this);
    }
}