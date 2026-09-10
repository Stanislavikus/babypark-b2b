<?php

namespace App\Support\Connectors\Transport\Internal;

use App\Support\Connectors\Transport\ConnectorDestinationKind;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorRequestSender;
use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use App\Support\Connectors\Transport\Curl\CurlClientFactory;
use App\Support\Connectors\Transport\Curl\CurlResolveFormatter;
use App\Support\Connectors\Transport\DestinationRequestMismatch;
use App\Support\Connectors\Transport\TimeoutPhase;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Connectors\Transport\ValidatedConnectorDestination;
use App\Support\Connectors\Transport\Validation\ConnectorUriValidator;
use App\Support\Connectors\Transport\Validation\HostHeaderValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ConnectorRequestSenderImpl implements ConnectorRequestSender
{
    private readonly Client $client;

    /**
     * @var array<int, mixed>|null
     */
    private ?array $lastCurlOptions = null;

    public function __construct(
        CurlClientFactory $clientFactory,
        bool|string $verify,
    ) {
        $verifyOption = $this->resolveVerifyOption($verify);

        $this->client = $clientFactory->create([
            'verify' => $verifyOption,
            'http_errors' => false,
            'allow_redirects' => false,
            'decode_content' => true,
        ]);
    }

    public function send(
        #[\SensitiveParameter] RequestInterface $request,
        ValidatedConnectorDestination $destination,
        ConnectorTransportLimits $limits,
        ConnectorTransportDeadline $deadline,
    ): ConnectorHttpResult {
        $this->assertDestinationMatchesRequest($request, $destination);

        try {
            HostHeaderValidator::validate(
                $request->hasHeader('Host') ? $request->getHeaderLine('Host') : null,
                $destination->host,
                $destination->port,
                $destination->scheme,
            );
        } catch (\InvalidArgumentException) {
            throw new ConnectorTransportException(TransportFailureReason::InvalidDestination);
        }

        $remaining = $deadline->remainingSeconds();
        if ($remaining <= 0) {
            throw new ConnectorTransportException(TransportFailureReason::Timeout);
        }

        $options = [
            'timeout' => $remaining,
            'connect_timeout' => min($limits->connectTimeoutSeconds, $remaining),
            'http_errors' => false,
            'allow_redirects' => false,
            'decode_content' => true,
            'curl' => [
                \CURLOPT_PROXY => '',
                \CURLOPT_NOSIGNAL => 1,
            ],
            'on_headers' => function (ResponseInterface $response) use ($limits): ?\GuzzleHttp\Promise\PromiseInterface {
                $encoding = strtolower($response->getHeaderLine('Content-Encoding'));
                if ($encoding !== '' && $encoding !== 'identity') {
                    return null;
                }

                $contentLength = $response->getHeaderLine('Content-Length');
                if ($contentLength !== '' && (int) $contentLength > $limits->maxResponseBodyBytes) {
                    throw new ResponseSizeExceededAbort;
                }

                return null;
            },
        ];

        if ($destination->kind === ConnectorDestinationKind::DnsHostname && $destination->pinnedIp !== null) {
            $options['curl'][\CURLOPT_RESOLVE] = [
                CurlResolveFormatter::format($destination->host, $destination->port, $destination->pinnedIp),
            ];
        }

        $this->lastCurlOptions = $options['curl'];

        $sinkPath = tempnam(sys_get_temp_dir(), 'connector_transport_');
        if ($sinkPath === false) {
            throw new ConnectorTransportException(TransportFailureReason::OtherTransportFailure);
        }

        $sink = new CappedResponseSink($limits->maxResponseBodyBytes, $sinkPath);
        $options['sink'] = $sink;

        try {
            $response = $this->client->send($request, $options);
            $body = $sink->getContents();
        } catch (ResponseSizeExceededAbort) {
            $sink->cleanup();
            throw new ConnectorTransportException(TransportFailureReason::ResponseSizeExceeded);
        } catch (ConnectException $exception) {
            $sink->cleanup();
            if ($this->isResponseSizeExceededCause($exception)) {
                throw new ConnectorTransportException(TransportFailureReason::ResponseSizeExceeded);
            }
            throw $this->mapException($exception, $deadline);
        } catch (RequestException $exception) {
            $sink->cleanup();
            if ($this->isResponseSizeExceededCause($exception)) {
                throw new ConnectorTransportException(TransportFailureReason::ResponseSizeExceeded);
            }
            throw $this->mapException($exception, $deadline);
        }

        $sink->cleanup();

        return new ConnectorHttpResult(
            statusCode: $response->getStatusCode(),
            headers: $response->getHeaders(),
            body: $body,
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function lastCurlOptions(): array
    {
        return $this->lastCurlOptions ?? [];
    }

    private function assertDestinationMatchesRequest(
        #[\SensitiveParameter] RequestInterface $request,
        ValidatedConnectorDestination $destination,
    ): void {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort();
        if ($port === null) {
            $port = $scheme === 'https'
                ? ConnectorUriValidator::DEFAULT_HTTPS_PORT
                : ConnectorUriValidator::DEFAULT_HTTP_PORT;
        }

        $host = $uri->getHost();
        if ($destination->scheme !== $scheme
            || $destination->host !== $host
            || $destination->port !== $port) {
            throw new DestinationRequestMismatch;
        }
    }

    private function resolveVerifyOption(bool|string $verify): bool|string
    {
        if ($verify === true) {
            return true;
        }

        if (! is_string($verify) || $verify === '' || ! is_readable($verify) || ! is_file($verify) || filesize($verify) === 0) {
            throw new TransportConfigurationException(TransportConfigurationFailureReason::InvalidCaBundle);
        }

        return $verify;
    }

    private function mapException(
        TransferException $exception,
        ConnectorTransportDeadline $deadline,
    ): ConnectorTransportException {
        if ($exception instanceof RequestException && $this->isTlsFailure($exception)) {
            return new ConnectorTransportException(TransportFailureReason::TlsVerificationFailed);
        }

        if ($exception instanceof RequestException) {
            $errno = $exception->getHandlerContext()['errno'] ?? null;

            if ($errno === \CURLE_OPERATION_TIMEDOUT) {
                $phase = TimeoutPhase::Unknown;
                $connectTime = $exception->getHandlerContext()['connect_time'] ?? null;
                if ($connectTime === 0.0 || $connectTime === 0) {
                    $phase = TimeoutPhase::Connect;
                }

                return new ConnectorTransportException(TransportFailureReason::Timeout, $phase);
            }
        }

        if ($deadline->isExpired()) {
            return new ConnectorTransportException(TransportFailureReason::Timeout);
        }

        return new ConnectorTransportException(TransportFailureReason::ConnectionFailed);
    }

    private function isTlsFailure(RequestException $exception): bool
    {
        $errno = $exception->getHandlerContext()['errno'] ?? null;

        return in_array($errno, [
            51, // CURLE_PEER_FAILED_VERIFICATION / SSL cert
            58, // CURLE_SSL_CERTPROBLEM
            60, // CURLE_SSL_CACERT
            77, // CURLE_SSL_CACERT_BADFILE
            80, // CURLE_SSL_SHUTDOWN_FAILED
            82, // CURLE_SSL_CRL_BADFILE
            83, // CURLE_SSL_ISSUER_ERROR
            91, // CURLE_SSL_PINNEDPUBKEYNOTMATCH
        ], true) || (defined('CURLE_SSL_CONNECT_ERROR') && $errno === CURLE_SSL_CONNECT_ERROR);
    }

    private function isResponseSizeExceededCause(\Throwable $exception): bool
    {
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof ResponseSizeExceededAbort) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }
}
