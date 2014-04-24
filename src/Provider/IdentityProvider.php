<?php

namespace League\OAuth2\Client\Provider;

use Guzzle\Service\Client as GuzzleClient;
use League\OAuth2\Client\Token\AccessToken as AccessToken;
use League\OAuth2\Client\Exception\IDPException as IDPException;
use League\OAuth2\Client\Grant\GrantInterface;

abstract class IdentityProvider
{
    public $clientId = '';

    public $clientSecret = '';

    public $redirectUri = '';

    public $name;

    public $uidKey = 'uid';

    public $scopes = array();

    public $method = 'post';

    public $scopeSeparator = ',';

    public $responseType = 'json';

    protected $cachedUserDetailsResponse;

    public $headers = null;

   /** @var int This represents: PHP_QUERY_RFC1738. The default encryption type for the http_build_query setup */
    protected $httpBuildEncType = 1;

    public function __construct($options = array())
    {
        foreach ($options as $option => $value) {
            if (isset($this->{$option})) {
                $this->{$option} = $value;
            }
        }
    }

    abstract public function urlAuthorize();

    abstract public function urlAccessToken();

    abstract public function urlUserDetails(\League\OAuth2\Client\Token\AccessToken $token);

    abstract public function userDetails($response, \League\OAuth2\Client\Token\AccessToken $token);

    public function getScopes()
    {
        return $this->scopes;
    }

    public function setScopes(array $scopes)
    {
        $this->scopes = $scopes;
    }

    public function getAuthorizationUrl($options = array())
    {
        $state = md5(uniqid(rand(), true));

        $params = array(
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => is_array($this->scopes) ? implode($this->scopeSeparator, $this->scopes) : $this->scopes,
            'response_type' => isset($options['response_type']) ? $options['response_type'] : 'code',
            'approval_prompt' => 'auto'
        );

        return $this->urlAuthorize() . '?' . $this->httpBuildQuery($params, '', '&', PHP_QUERY_RFC1738);
    }

    public function authorize($options = array())
    {
        header('Location: ' . $this->getAuthorizationUrl($options));
        exit;
    }

    public function getAccessToken($grant = 'authorization_code', $params = array())
    {
        if (is_string($grant)) {
            $grant = 'League\\OAuth2\\Client\\Grant\\'.ucfirst(str_replace('_', '', $grant));
            if ( ! class_exists($grant)) {
                throw new \InvalidArgumentException('Unknown grant "'.$grant.'"');
            }
            $grant = new $grant;
        } elseif (! $grant instanceof GrantInterface) {
            throw new \InvalidArgumentException($grant.' is not an instance of League\OAuth2\Client\Grant\GrantInterface');
        }

        $defaultParams = array(
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => $grant,
        );

        $requestParams = $grant->prepRequestParams($defaultParams, $params);

        try {
            switch ($this->method) {
                case 'get':
                    $client = new GuzzleClient($this->urlAccessToken() . '?' . $this->httpBuildQuery($requestParams, '', '&', PHP_QUERY_RFC1738));
                    $request = $client->send();
                    $response = $request->getBody();
                    break;
                case 'post':
                    $client = new GuzzleClient($this->urlAccessToken());
                    $request = $client->post(null, null, $requestParams)->send();
                    $response = $request->getBody();
                    break;
            }
        } catch (\Guzzle\Http\Exception\BadResponseException $e) {
            $raw_response = explode("\n", $e->getResponse());
            $response = end($raw_response);
        }

        switch ($this->responseType) {
            case 'json':
                $result = json_decode($response, true);
                break;
            case 'string':
                parse_str($response, $result);
                break;
        }

        if (isset($result['error']) && ! empty($result['error'])) {
            throw new IDPException($result);
        }

        return $grant->handleResponse($result);
    }

    public function getUserDetails(AccessToken $token, $force = false)
    {
        $response = $this->fetchUserDetails($token);

        return $this->userDetails(json_decode($response), $token);
    }

    public function getUserUid(AccessToken $token, $force = false)
    {
        $response = $this->fetchUserDetails($token, $force);

        return $this->userUid(json_decode($response), $token);
    }

    public function getUserEmail(AccessToken $token, $force = false)
    {
        $response = $this->fetchUserDetails($token, $force);

        return $this->userEmail(json_decode($response), $token);
    }

    public function getUserScreenName(AccessToken $token, $force = false)
    {
        $response = $this->fetchUserDetails($token, $force);

        return $this->userScreenName(json_decode($response), $token);
    }

    /**
     * Build HTTP the HTTP query, handling PHP version control options
     *
     * @param  array        $params
     * @param  integer      $numeric_prefix
     * @param  string       $arg_separator
     * @param  null|integer $enc_type
     * @return string
     */
    protected function httpBuildQuery($params, $numeric_prefix = 0, $arg_separator = '&', $enc_type = null)
    {
        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            if ($enc_type === null) {
                $enc_type = $this->httpBuildEncType;
            }
            $url = http_build_query($params, $numeric_prefix, $arg_separator, $enc_type);
        } else {
            $url = http_build_query($params, $numeric_prefix, $arg_separator);
        }

        return $url;
    }
    protected function fetchUserDetails(AccessToken $token, $force = false)
    {
        if (! $this->cachedUserDetailsResponse || $force == true) {

            $url = $this->urlUserDetails($token);

            try {

                $client = new GuzzleClient($url);

                if ($this->headers) {
                    $client->setDefaultOption('headers', $this->headers);
                }

                $request = $client->get()->send();
                $response = $request->getBody();
                $this->cachedUserDetailsResponse = $response;

            } catch (\Guzzle\Http\Exception\BadResponseException $e) {

                $raw_response = explode("\n", $e->getResponse());
                throw new IDPException(end($raw_response));

            }
        }

        return $this->cachedUserDetailsResponse;
    }

}
