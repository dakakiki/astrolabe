<?php

namespace Tests\Unit\Portal;

use App\Models\PortalPushSubscription;
use App\Support\Portal\Push\PortalPush;
use App\Support\Portal\Push\PushMessage;
use App\Support\Portal\Push\PushResult;
use App\Support\Portal\Push\WebPushSender;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\VAPID;
use OpenSSLAsymmetricKey;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;
use Throwable;

/**
 * Web push under the notifications (Phase 9c, docs/spec/12): which addresses
 * the server posts to, what a push carries, and what WebPushSender really
 * sends — minishlink/web-push encrypting for a real browser key pair and
 * signing with real VAPID keys, the push service played by Guzzle's
 * MockHandler. No database.
 */
class PushTest extends TestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/dGhlLWJyb3dzZXJzLW93bi10b2tlbg:APA91bH';

    /** @var list<array{request: RequestInterface, response: mixed, error: mixed, options: array<mixed>}> */
    private array $requests = [];

    /** @var list<MessageLogged> */
    private array $logged = [];

    private OpenSSLAsymmetricKey $browserKey;

    /** The browser's public key (uncompressed P-256 point) and auth secret, as PushManager.subscribe() gives them. */
    private string $browserPublic;

    private string $authSecret;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null', 'portal.push.ttl' => 3600]);
        Log::listen(function (MessageLogged $logged) {
            $this->logged[] = $logged;
        });
    }

    public function test_only_https_addresses_of_the_known_push_services_are_allowed(): void
    {
        foreach ([
            self::ENDPOINT,
            'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABk',
            'https://web.push.apple.com/QGxrZHNmbGtzZGZqbGtzZGY',
            'https://wns2-par02p.notify.windows.com/w/?token=BQYAAAD',
            'https://fcm.googleapis.com:443/fcm/send/abc',
            'https://FCM.googleapis.com/fcm/send/abc',
        ] as $endpoint) {
            $this->assertTrue(PortalPush::allowsEndpoint($endpoint), $endpoint);
        }

        foreach ([
            'http://fcm.googleapis.com/fcm/send/abc',
            'wss://fcm.googleapis.com/fcm/send/abc',
            'https://evil.test/fcm/send/abc',
            'https://fcm.googleapis.com.evil.test/fcm/send/abc',
            'https://googleapis.com/fcm/send/abc',
            'https://evilnotify.windows.com/w/',
            // ".notify.windows.com" is a suffix rule: a name must stand in front of it.
            'https://notify.windows.com/w/',
            'https://.notify.windows.com/w/',
            'https://user:secret@fcm.googleapis.com/fcm/send/abc',
            'https://fcm.googleapis.com@evil.test/fcm/send/abc',
            'https://evil.test\\@fcm.googleapis.com/fcm/send/abc',
            'https://fcm.googleapis.com:8443/fcm/send/abc',
            'https://fcm.googleapis.com:80/fcm/send/abc',
            'https://evil.test#.notify.windows.com/w/',
            'https://evil.test?.notify.windows.com/w/',
            // Names the URL parser and the HTTP client could read differently.
            'https://evil.test;.notify.windows.com/w/',
            'https://evil.test%2F.notify.windows.com/w/',
            'https://fcm.googleapis.com./fcm/send/abc',
            "https://fcm.googleapis.com/fcm/send/abc\r\nHost: evil.test",
            'https://fcm.googleapis.com /fcm/send/abc',
            'https://[::1]/fcm/send/abc',
            'https://127.0.0.1/fcm/send/abc',
            '//fcm.googleapis.com/fcm/send/abc',
            'https:fcm.googleapis.com/fcm/send/abc',
            'fcm.googleapis.com/fcm/send/abc',
            'not an address',
            '',
        ] as $endpoint) {
            $this->assertFalse(PortalPush::allowsEndpoint($endpoint), $endpoint);
        }
    }

    public function test_an_empty_vapid_subject_falls_back_to_the_operators_address(): void
    {
        // As a .env copied from .env.example with the line left empty would have it.
        $saved = [$_SERVER['VAPID_SUBJECT'] ?? null, $_SERVER['OPERATOR_EMAIL'] ?? null];
        $_SERVER['VAPID_SUBJECT'] = '';
        $_SERVER['OPERATOR_EMAIL'] = 'operator@example.com';

        try {
            $config = require config_path('portal.php');
        } finally {
            foreach (['VAPID_SUBJECT', 'OPERATOR_EMAIL'] as $i => $name) {
                if ($saved[$i] === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $saved[$i];
                }
            }
        }

        $this->assertSame('mailto:operator@example.com', $config['push']['subject']);
    }

    public function test_the_payload_carries_only_the_title_the_line_the_path_and_the_tag(): void
    {
        $this->assertSame(
            '{"title":"Booking request sent","body":"Mon, Oct 5, 2026 at 9:00 AM · waiting for confirmation","url":"/appointments","tag":"appointment-42"}',
            (new PushMessage('Booking request sent', 'Mon, Oct 5, 2026 at 9:00 AM · waiting for confirmation', '/appointments', 'appointment-42'))->payload(),
        );

        // Without a tag there is no "tag" key at all, not a null one.
        $this->assertSame(
            '{"title":"Notifications are on","body":"This device will get your appointment notices.","url":"/"}',
            (new PushMessage('Notifications are on', 'This device will get your appointment notices.'))->payload(),
        );
    }

    public function test_a_push_is_encrypted_for_the_browser_and_signed_with_the_vapid_keys(): void
    {
        $vapid = $this->realKeys();
        $message = new PushMessage('Appointment reminder', 'Tomorrow at 9:00 AM', '/appointments', 'appointment-42');

        $result = $this->sender(new Response(201))->send($this->subscription(), $message);

        $this->assertSame(PushResult::Sent, $result);
        $this->assertCount(1, $this->requests);
        $request = $this->requests[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT, (string) $request->getUri());
        $this->assertSame('3600', $request->getHeaderLine('TTL'));
        $this->assertSame('normal', $request->getHeaderLine('Urgency'));
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('application/octet-stream', $request->getHeaderLine('Content-Type'));

        // The tag, hashed into a Topic the push services take (URL-safe, at most 32 characters).
        $topic = $request->getHeaderLine('Topic');
        $this->assertSame(substr(hash('sha256', 'appointment-42'), 0, 32), $topic);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,32}$/', $topic);

        // VAPID: a JWT for the push service's origin, signed by our key, and our public key.
        $authorization = $request->getHeaderLine('Authorization');
        $this->assertStringStartsWith('vapid t=', $authorization);
        $this->assertStringEndsWith(', k='.$vapid['publicKey'], $authorization);
        $claims = json_decode($this->base64UrlDecode(explode('.', substr($authorization, 8))[1]), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame(config('portal.push.subject'), $claims['sub']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 24 * 3600, $claims['exp']);

        // The body is ciphertext; only the browser's private key opens it, to exactly the payload.
        $body = (string) $request->getBody();
        $this->assertStringNotContainsString('Appointment reminder', $body);
        $this->assertStringNotContainsString('appointment-42', $body);
        $this->assertSame($message->payload(), $this->decrypt($body));

        // Without a tag: no Topic.
        $this->sender(new Response(201))->send($this->subscription(), new PushMessage('Notifications are on', 'This device will get your appointment notices.'));
        $this->assertFalse($this->requests[1]['request']->hasHeader('Topic'));

        $this->assertSame([], $this->logged);
    }

    public function test_the_push_services_answers_become_sent_expired_or_failed(): void
    {
        $this->realKeys();
        $message = new PushMessage('Appointment moved', 'Thu, Oct 8, 2026 at 10:00 AM', '/appointments', 'appointment-7');
        $sender = $this->sender(
            new Response(201),
            new Response(410),
            new Response(404),
            new Response(500),
            new ConnectException('cURL error 7: Failed to connect to fcm.googleapis.com port 443 for '.self::ENDPOINT, new Request('POST', self::ENDPOINT)),
        );

        $results = array_map(fn () => $sender->send($this->subscription(), $message), range(1, 5));

        $this->assertSame([PushResult::Sent, PushResult::Expired, PushResult::Expired, PushResult::Failed, PushResult::Failed], $results);

        // A dropped subscription is not worth a log line; the two failures are, without the endpoint.
        $this->assertSame([
            ['warning', 'Web push was refused by the push service.', ['subscription' => 7, 'status' => 500]],
            ['warning', 'Web push did not reach the push service.', ['subscription' => 7, 'status' => null]],
        ], $this->logLines());
        $this->assertLogsKeepTheEndpointOut();
    }

    public function test_a_push_that_cannot_be_prepared_fails_without_a_request_and_without_the_endpoint_in_the_log(): void
    {
        $this->realKeys();
        $subscription = $this->subscription()->forceFill(['public_key' => 'not-a-browser-key']);

        $result = $this->sender(new Response(201))->send($subscription, new PushMessage('Appointment booked', 'Mon, Oct 5, 2026 at 9:00 AM'));

        $this->assertSame(PushResult::Failed, $result);
        $this->assertSame([], $this->requests);
        $this->assertCount(1, $this->logged);
        $this->assertSame('Web push failed before reaching the push service.', $this->logged[0]->message);
        $this->assertSame(7, $this->logged[0]->context['subscription']);
        $this->assertLogsKeepTheEndpointOut();
    }

    /**
     * Real VAPID keys in the config and a real browser key pair. Windows PHP makes EC
     * keys only with OPENSSL_CONF pointing at its openssl.cnf; CI runs on Linux.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    private function realKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot make EC keys here; set OPENSSL_CONF to PHP\'s openssl.cnf.');
        }

        $ec = openssl_pkey_get_details($key)['ec'];
        $this->browserKey = $key;
        $this->browserPublic = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $this->authSecret = random_bytes(16);

        $vapid = VAPID::createVapidKeys();
        config([
            'portal.push.public_key' => $vapid['publicKey'],
            'portal.push.private_key' => $vapid['privateKey'],
            'portal.push.subject' => 'mailto:operator@astrolabe.test',
        ]);

        return $vapid;
    }

    private function subscription(): PortalPushSubscription
    {
        return (new PortalPushSubscription)->forceFill([
            'id' => 7,
            'portal_user_id' => 3,
            'endpoint' => self::ENDPOINT,
            'public_key' => $this->base64UrlEncode($this->browserPublic),
            'auth_token' => $this->base64UrlEncode($this->authSecret),
            'content_encoding' => 'aes128gcm',
        ]);
    }

    /** A sender whose push service gives these answers in turn; the requests land in $this->requests. */
    private function sender(Response|Throwable ...$answers): WebPushSender
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->requests));

        return new WebPushSender(new Client(['handler' => $stack]));
    }

    /** What the browser does with the body (RFC 8291, aes128gcm, one record). */
    private function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $keyIdLength = ord($body[20]);
        $serverPublic = substr($body, 21, $keyIdLength);
        $cipherText = substr($body, 21 + $keyIdLength);

        // The server's one-off public key as a SubjectPublicKeyInfo on P-256.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$serverPublic;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive(openssl_pkey_get_public($pem), $this->browserKey);

        $keyPrk = hash_hmac('sha256', $shared, $this->authSecret, true);
        $ikm = hash_hmac('sha256', "WebPush: info\x00".$this->browserPublic.$serverPublic."\x01", $keyPrk, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $plain = openssl_decrypt(substr($cipherText, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipherText, -16));
        $this->assertIsString($plain, 'The browser could not decrypt the push.');

        // The last record: the data, the delimiter 0x02, then zero padding.
        $plain = rtrim($plain, "\x00");
        $this->assertSame("\x02", substr($plain, -1));

        return substr($plain, 0, -1);
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function logLines(): array
    {
        return array_map(fn (MessageLogged $logged) => [$logged->level, $logged->message, $logged->context], $this->logged);
    }

    private function assertLogsKeepTheEndpointOut(): void
    {
        $everything = json_encode($this->logLines(), JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('fcm.googleapis.com', $everything);
        $this->assertStringNotContainsString('dGhlLWJyb3dzZXJzLW93bi10b2tlbg', $everything);
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $text): string
    {
        return base64_decode(strtr($text, '-_', '+/'));
    }
}
