<?php

namespace App\Support\Portal;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Session\SessionManager;

/**
 * The client portal's own sessions (docs/spec/12, "Poseban origin"): a second
 * session manager with the portal's cookie, table and lifetime, next to the
 * application's. The astrologers' `sessions` table is read by user id
 * (Settings → Security, the admin's suspension), so a client's session must
 * never land in it, and the cookie is set for the portal host only.
 *
 * StartPortalSession starts it, PreventPortalRequestForgery sets the portal's
 * XSRF cookie from it, and the `portal` guard keeps the person in it.
 */
class PortalSessionManager extends SessionManager
{
    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->config = new Repository(['session' => self::settings($container->make('config'))]);
    }

    /**
     * The application's session settings with the portal's own laid over them.
     *
     * @return array<string, mixed>
     */
    public static function settings(Repository $config): array
    {
        $portal = $config->get('portal.session');

        return array_merge($config->get('session'), [
            'driver' => $portal['driver'],
            'table' => $portal['table'],
            'cookie' => $portal['cookie'],
            'lifetime' => $portal['lifetime'],
            'expire_on_close' => false,
            // No Domain attribute: the browser keeps the cookie for the portal host alone.
            'domain' => null,
            'path' => '/',
            'secure' => $portal['secure'] ?? $config->get('session.secure'),
            'http_only' => true,
            'same_site' => 'lax',
            'partitioned' => false,
            'encrypt' => false,
            'store' => null,
            'block' => false,
        ]);
    }

    protected function createDatabaseDriver()
    {
        return $this->buildSession(new PortalSessionHandler(
            $this->getDatabaseConnection(),
            $this->config->get('session.table'),
            $this->config->get('session.lifetime'),
            $this->container,
        ));
    }
}
