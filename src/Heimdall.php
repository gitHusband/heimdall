<?php namespace Heimdall;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use DateInterval;
use Exception;
use Heimdall\Config\HeimdallAuthorizationConfig;
use Heimdall\Config\HeimdallAuthorizationGrantType;
use Heimdall\Config\HeimdallResourceConfig;
use Heimdall\Exception\HeimdallConfigException;
use Heimdall\Http\HeimdallRequest;
use Heimdall\Http\HeimdallResponse;
use Heimdall\interfaces\IdentityRepositoryInterface;
use Heimdall\Plugin\HeimdallAuthorizationOIDC;
use Heimdall\Server\HeimdallAuthorizationServer;
use Heimdall\Server\HeimdallResourceServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class Heimdall
 * @package Heimdall
 */
abstract class Heimdall
{
    /**
     * @param HeimdallAuthorizationConfig $config
     * @param HeimdallAuthorizationGrantType $grantType
     * @param HeimdallAuthorizationOIDC|null $oidc
     * @return HeimdallAuthorizationServer
     * @throws Exception
     */
    static function initializeAuthorizationServer(
        HeimdallAuthorizationConfig $config,
        HeimdallAuthorizationGrantType $grantType,
        HeimdallAuthorizationOIDC $oidc = null
    ): HeimdallAuthorizationServer
    {
        switch ($grantType->getCode()) {
            case HeimdallAuthorizationGrantType::AuthorizationCode:
                return new HeimdallAuthorizationServer($config, $grantType, $oidc);
            default:
                throw new HeimdallConfigException(
                    'Unknown Heimdall grant type, please recheck your parameter.'
                );
        }
    }

    /**
     * @param HeimdallResourceConfig $config
     * @return HeimdallResourceServer
     */
    static function initializeResourceServer(HeimdallResourceConfig $config): HeimdallResourceServer
    {
        return new HeimdallResourceServer($config);
    }

    /**
     * @param IncomingRequest $request
     * @return HeimdallRequest
     */
    static function handleRequest(IncomingRequest $request): HeimdallRequest
    {
        return (new HeimdallRequest($request))->withParsedBody($request->getPost());
    }

    /**
     * @param Response $response
     * @return HeimdallResponse
     */
    static function handleResponse(Response $response): HeimdallResponse
    {
        return new HeimdallResponse($response);
    }

    /**
     * @param ResponseInterface $generatedResponse
     * @param Response $response
     * @return Response
     */
    static function return(ResponseInterface $generatedResponse, Response $response): Response
    {
        $formattedResponse = $response
            ->setContentType('application/json')
            ->setStatusCode($generatedResponse->getStatusCode(), $generatedResponse->getReasonPhrase())
            ->setHeader('Location', $generatedResponse->getHeader('Location'))
            ->setBody($generatedResponse->getBody());
        echo $formattedResponse->getBody();
        return $formattedResponse;
    }

    /**
     * @param Exception $exception
     * @param Response $response
     * @return Response|void
     */
    static function handleException(Exception $exception, Response $response)
    {
        if($exception instanceof OAuthServerException) {
            $error = [
                'error' => $exception->getCode(),
                'messages' => $exception->getMessage(),
                'hint' => $exception->getHint()
            ];
            if($response !== null) {
                $errorResponse = $response
                    ->setContentType('application/json')
                    ->setStatusCode($exception->getHttpStatusCode(), $exception->getMessage())
                    ->setBody($error);
            }
        } else {
            $error = [
                'error'    => $exception->getCode(),
                'messages' => $exception->getMessage()
            ];
            if($response !== null) {
                $errorResponse = $response
                    ->setContentType('application/json')
                    ->setStatusCode(500, 'Internal HeimdallServer Error')
                    ->setBody($error);
            }
        }
        echo json_encode($error);
        if($response === null) exit;
        return $errorResponse;
    }

    /**
     * @param $something
     * @param bool $prettify
     * @param bool $asJSON
     * @return void
     */
    static function debug($something, $prettify = true, $asJSON = false)
    {
        echo ($prettify === true) ? '<pre>' : '';
        ($asJSON === true) ? print_r(json_encode($something)) : print_r($something);
        echo ($prettify === true) ? '</pre>' : '';
        exit;
    }

    /**
     * @param ClientRepositoryInterface $clientRepository
     * @param AccessTokenRepositoryInterface $accessTokenRepository
     * @param ScopeRepositoryInterface $scopeRepository
     * @param $privateKey
     * @param ResponseTypeInterface|null $responseType
     * @return HeimdallAuthorizationConfig
     * @throws Exception
     */
    static function withConfig(
        ClientRepositoryInterface $clientRepository,
        AccessTokenRepositoryInterface $accessTokenRepository,
        ScopeRepositoryInterface $scopeRepository,
        $privateKey,
        ResponseTypeInterface $responseType = null
    ): HeimdallAuthorizationConfig
    {
        if(is_string($privateKey)) $privateKey = ['path' => $privateKey];
        return new HeimdallAuthorizationConfig(
            $clientRepository, $accessTokenRepository, $scopeRepository, $privateKey, $responseType
        );
    }

    /**
     * @param IdentityRepositoryInterface $identityRepository
     * @param array $claimSet
     * @return HeimdallAuthorizationOIDC
     * @throws Exception
     */
    static function withOIDC(
        IdentityRepositoryInterface $identityRepository, array $claimSet = []
    ): HeimdallAuthorizationOIDC
    {
        return new HeimdallAuthorizationOIDC($identityRepository, $claimSet);
    }

    /**
     * @param AuthCodeRepositoryInterface $authCodeRepository
     * @param RefreshTokenRepositoryInterface $refreshTokenRepository
     * @param string $accessTokenTTL
     * @return HeimdallAuthorizationGrantType
     */
    static function withAuthorizationGrantType(
        AuthCodeRepositoryInterface $authCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        $accessTokenTTL = 'PT1H'
    ): HeimdallAuthorizationGrantType
    {
        try {
            return new HeimdallAuthorizationGrantType(
                HeimdallAuthorizationGrantType::AuthorizationCode,
                new AuthCodeGrant($authCodeRepository, $refreshTokenRepository, new DateInterval('PT10M')),
                $accessTokenTTL
            );
        } catch (Exception $e) {
            throw new HeimdallConfigException(
                'Error happened initializing Heimdall grant type, please recheck your parameter.'
            );
        }
    }
}