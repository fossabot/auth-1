<?php
/**
 * SocialConnect project
 * @author: Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */
declare(strict_types=1);

namespace SocialConnect\OpenID;

use SocialConnect\Provider\AbstractBaseProvider;
use SocialConnect\Provider\Consumer;
use SocialConnect\Provider\Exception\InvalidAccessToken;
use SocialConnect\Provider\Exception\InvalidResponse;

abstract class AbstractProvider extends AbstractBaseProvider
{
    /**
     * @return string
     */
    abstract public function getOpenIdUrl();

    /**
     * @var int
     */
    protected $version;

    /**
     * @var string
     */
    protected $loginEntrypoint;

    /**
     * @param bool $immediate
     * @return string
     */
    protected function makeAuthUrlV2($immediate)
    {
        $params = [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => $immediate ? 'checkid_immediate' : 'checkid_setup',
            'openid.return_to' => $this->getRedirectUrl(),
            'openid.realm' => $this->getRedirectUrl()
        ];

        $params['openid.ns.sreg'] = 'http://openid.net/extensions/sreg/1.1';
        $params['openid.identity'] = $params['openid.claimed_id'] = 'http://specs.openid.net/auth/2.0/identifier_select';

        return $this->loginEntrypoint . '?' . http_build_query($params, '', '&');
    }

    /**
     * @param string $url
     * @return string
     * @throws InvalidResponse
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    protected function discover(string $url)
    {
        $response = $this->executeRequest(
            $this->httpStack->createRequest('GET', $url)
        );

        $contentType = $response->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/xrds+xml;charset=utf-8') === false) {
            throw new InvalidResponse(
                'Unexpected Content-Type',
                $response
            );
        }

        $xml = new \SimpleXMLElement($response->getBody()->getContents());

        $this->version = 2;
        $this->loginEntrypoint = $xml->XRD->Service->URI;

        return $this->getOpenIdUrl();
    }

    /**
     * {@inheritdoc}
     */
    public function makeAuthUrl(): string
    {
        $this->discover($this->getOpenIdUrl());

        return $this->makeAuthUrlV2(false);
    }

    /**
     * @param string $identity
     * @return int
     */
    protected function parseUserIdFromIdentity($identity)
    {
        return null;
    }

    /**
     * @link http://openid.net/specs/openid-authentication-2_0.html#verification
     *
     * @param array $requestParameters
     * @return AccessToken
     * @throws InvalidAccessToken
     * @throws InvalidResponse
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getAccessTokenByRequestParameters(array $requestParameters)
    {
        $params = [
            'openid.assoc_handle' => $requestParameters['openid_assoc_handle'],
            'openid.signed' => $requestParameters['openid_signed'],
            'openid.sig' => $requestParameters['openid_sig'],
            'openid.ns' => $requestParameters['openid_ns'],
            'openid.op_endpoint' => $requestParameters['openid_op_endpoint'],
            'openid.claimed_id' => $requestParameters['openid_claimed_id'],
            'openid.identity' => $requestParameters['openid_identity'],
            'openid.return_to' => $this->getRedirectUrl(),
            'openid.response_nonce' => $requestParameters['openid_response_nonce'],
            'openid.mode' => 'check_authentication'
        ];

        if (isset($requestParameters['openid_claimed_id'])) {
            $claimedId = $requestParameters['openid_claimed_id'];
        } else {
            $claimedId = $requestParameters['openid_identity'];
        }

        $this->discover($claimedId);

        $response = $this->executeRequest(
            $this->httpStack->createRequest('POST', $this->loginEntrypoint)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($this->httpStack->createStream(http_build_query($params, '', '&')))
        );

        $content = $response->getBody()->getContents();
        if (preg_match('/is_valid\s*:\s*true/i', $content)) {
            return new AccessToken(
                $requestParameters['openid_identity'],
                $this->parseUserIdFromIdentity(
                    $requestParameters['openid_identity']
                )
            );
        }

        throw new InvalidAccessToken;
    }

    /**
     * {@inheritDoc}
     */
    protected function createConsumer(array $parameters): Consumer
    {
        return new Consumer(
            $this->getRequiredStringParameter('applicationId', $parameters),
            ''
        );
    }
}
