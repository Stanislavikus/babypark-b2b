<?php

namespace App\Support\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\Exceptions\OAuth1StructuralException;

final class OAuth1RequestSigner
{
    private const SIGNATURE_METHOD = 'HMAC-SHA256';

    private const OAUTH_VERSION = '1.0';

    public function __construct(
        private readonly OAuth1FormUrlEncodedParser $formUrlEncodedParser = new OAuth1FormUrlEncodedParser,
        private readonly OAuth1BaseStringUriBuilder $baseStringUriBuilder = new OAuth1BaseStringUriBuilder,
        private readonly OAuth1ParameterNormalizer $parameterNormalizer = new OAuth1ParameterNormalizer,
        private readonly OAuth1SignatureBaseStringBuilder $signatureBaseStringBuilder = new OAuth1SignatureBaseStringBuilder,
        private readonly OAuth1AuthorizationHeaderBuilder $authorizationHeaderBuilder = new OAuth1AuthorizationHeaderBuilder,
    ) {}

    public function sign(
        string $method,
        string $absoluteUrl,
        ?string $contentType,
        ?string $rawBody,
        OAuth1Credentials $credentials,
        OAuth1SigningContext $context,
    ): string {
        $requestUrl = OAuth1RequestUrl::parse($absoluteUrl);
        $parameterPairs = $this->collectParameterPairs($requestUrl, $contentType, $rawBody);
        $this->assertNoCallerSuppliedOAuthParameters($parameterPairs);

        $oauthParameters = [
            'oauth_consumer_key' => $credentials->consumerKey,
            'oauth_token' => $credentials->accessToken,
            'oauth_nonce' => $context->nonce,
            'oauth_timestamp' => (string) $context->timestamp,
            'oauth_signature_method' => self::SIGNATURE_METHOD,
            'oauth_version' => self::OAUTH_VERSION,
        ];

        $signingPairs = array_merge(
            $parameterPairs,
            $this->pairsFromAssociative($oauthParameters),
        );

        $normalizedParameterString = $this->parameterNormalizer->normalize($signingPairs);
        $baseStringUri = $this->baseStringUriBuilder->build($requestUrl);
        $signatureBaseString = $this->signatureBaseStringBuilder->build(
            $method,
            $baseStringUri,
            $normalizedParameterString,
        );

        $signature = $this->computeSignature(
            $signatureBaseString,
            $credentials->consumerSecret,
            $credentials->accessTokenSecret,
        );

        $authorizationParameters = array_merge($oauthParameters, [
            'oauth_signature' => $signature,
        ]);

        return $this->authorizationHeaderBuilder->build($authorizationParameters);
    }

    /**
     * @return list<OAuth1ParameterPair>
     */
    private function collectParameterPairs(
        OAuth1RequestUrl $requestUrl,
        ?string $contentType,
        ?string $rawBody,
    ): array {
        $pairs = $this->formUrlEncodedParser->parse($requestUrl->rawQuery);

        if (OAuth1MediaType::isFormUrlEncoded($contentType)) {
            $pairs = array_merge($pairs, $this->formUrlEncodedParser->parse($rawBody ?? ''));
        }

        return $pairs;
    }

    /**
     * @param  list<OAuth1ParameterPair>  $pairs
     */
    private function assertNoCallerSuppliedOAuthParameters(array $pairs): void
    {
        foreach ($pairs as $pair) {
            if (str_starts_with($pair->name, 'oauth_')) {
                throw new OAuth1StructuralException('Caller-supplied OAuth protocol parameters are not permitted.');
            }
        }
    }

    /**
     * @param  array<string, string>  $parameters
     * @return list<OAuth1ParameterPair>
     */
    private function pairsFromAssociative(array $parameters): array
    {
        $pairs = [];

        foreach ($parameters as $name => $value) {
            $pairs[] = new OAuth1ParameterPair($name, $value);
        }

        return $pairs;
    }

    private function computeSignature(
        string $signatureBaseString,
        string $consumerSecret,
        string $tokenSecret,
    ): string {
        $signingKey = OAuth1PercentEncoder::encode($consumerSecret)
            .'&'
            .OAuth1PercentEncoder::encode($tokenSecret);

        return base64_encode(hash_hmac('sha256', $signatureBaseString, $signingKey, true));
    }
}
