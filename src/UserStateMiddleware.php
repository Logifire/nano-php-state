<?php
namespace NaivePhpState;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UserStateMiddleware implements MiddlewareInterface
{

    /**
     * @var ResponseCookieService
     */
    private $cookie_response_service;

    public function __construct(ResponseCookieService $cookie_response_service)
    {

        $this->cookie_response_service = $cookie_response_service;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->initiatePhpSession($request);

        $response = $handler->handle($request);

        $client_sesion_id = $this->getClientSessionId($request);

        $response = $this->handleClientSessionId($response, $client_sesion_id);

        $response = $this->addCustomCookies($response);

        if (session_status() == PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $response;
    }

    private function addCustomCookies(ResponseInterface $response): ResponseInterface
    {
        /* @var $response_coookie ResponseCookie */
        foreach ($this->cookie_response_service->list() as $response_coookie) {
            $cookie_value = ResponseCookieHeaderCreator::getValue($response_coookie);

            // Multiple Set-Cookie: https://developer.mozilla.org/en-US/docs/Web/HTTP/Cookies#The_Set-Cookie_and_Cookie_headers
            $response = $response->withAddedHeader(ResponseCookieHeaderCreator::HEADER_NAME, $cookie_value);
        }

        return $response;
    }

    /**
     * Starts PHP session support, and disables the default autogenerated headers.
     * The directive session.auto_start should be turned off (default).
     */
    private function initiatePhpSession(ServerRequestInterface $request): void
    {
        // Session security: http://php.net/manual/en/session.security.ini.php
        $options = [
            // Disable cache headers http://php.net/manual/en/function.session-cache-limiter.php
            'cache_limiter' => '',
            // Prevent PHP writing the session cookie
            'use_cookies' => 0,
            // Only fetch session id from cookie
            'use_only_cookies' => 1,
            // Session ID may leak from bookmarked URL if on
            'use_trans_sid' => 0,
            // If uninitialized session ID is sent from browser, new session ID is sent to browser. 
            // Applications are protected from session fixation via session adoption with strict mode.
            //'use_strict_mode' => 1 
        ];

        $client_session_id = $this->getClientSessionId($request);
        if ($client_session_id !== null) {
            session_id($client_session_id);
        }

        session_start($options);
    }

    private function getClientSessionId(ServerRequestInterface $request): ?string
    {
        $sessino_id = null;
        $cookie = $request->getCookieParams();
        if (isset($cookie[session_name()])) {
            $sessino_id = $cookie[session_name()];
        }
        return $sessino_id;
    }

    /**
     * Session cookie generated based on PHP configuration.
     * @see http://php.net/manual/en/session.configuration.php
     */
    private function handleClientSessionId(ResponseInterface $response, ?string $client_session_id): ResponseInterface
    {
        // If session_destroy() has been called, clear the cookie
        if (session_status() === PHP_SESSION_NONE && $client_session_id !== null) {
            $response = $this->clearClientSessionId($response);
        }

        // If new session is started
        // Client may have sent an invalid session id
        if (session_status() == PHP_SESSION_ACTIVE && $client_session_id !== session_id()) {
            $response = $this->addClientSessionId($response);
        }

        return $response;
    }

    private function addClientSessionId(ResponseInterface $response): ResponseInterface
    {
        $cookie_params = session_get_cookie_params();

        $response_cookie = new ResponseCookie(session_name(), session_id());

        $expires = $cookie_params['lifetime'] ? time() + $cookie_params['lifetime'] : 0;
        $response_cookie->setExpires($expires);

        $response_cookie->setPath($cookie_params['path']);
        $response_cookie->setDomain($cookie_params['domain']);
        $response_cookie->setSecure($cookie_params['secure']);
        $response_cookie->setHttpOnly($cookie_params['httponly']);

        $same_site = $cookie_params['samesite'] ?? ''; // PHP 7.3.0
        $response_cookie->setSameSite($same_site);

        $cookie_value = ResponseCookieHeaderCreator::getValue($response_cookie);

        $response = $response->withAddedHeader(ResponseCookieHeaderCreator::HEADER_NAME, $cookie_value);

        return $response;
    }

    private function clearClientSessionId(ResponseInterface $response): ResponseInterface
    {
        $response_cookie = new ResponseCookie(session_name(), '');
        $response_cookie->setExpires(12345678); // Expires the cookie

        $cookie_value = ResponseCookieHeaderCreator::getValue($response_cookie);

        $response = $response->withAddedHeader(ResponseCookieHeaderCreator::HEADER_NAME, $cookie_value);

        return $response;
    }
}
