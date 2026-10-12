<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WebPushSender
{
    public function __construct(private ?Client $client = null) {}

    public function send(Collection $subscriptions, array $payload): array
    {
        $client = $this->client ?? new Client(['timeout' => 5, 'connect_timeout' => 2, 'allow_redirects' => false, 'http_errors' => false]);
        $push = new class(['VAPID' => ['subject' => config('push.subject'), 'publicKey' => config('push.public_key'), 'privateKey' => config('push.private_key')]], ['TTL' => 3600, 'urgency' => 'high'], $client) extends WebPush
        {
            public function request(array $subscription, string $payload): RequestInterface
            {
                // queueNotification adds the mandatory final-record delimiter and padding.
                // Calling prepare with a raw payload encrypts an invalid RFC 8188 record.
                $this->queueNotification(Subscription::create($subscription + ['contentEncoding' => 'aes128gcm']), $payload);
                try {
                    return $this->prepare($this->notifications)[0];
                } finally {
                    $this->notifications = [];
                }
            }
        };
        $push->setReuseVAPIDHeaders(true);
        $results = [];
        $requests = function () use ($subscriptions, $payload, $push, &$results) {
            foreach ($subscriptions as $subscription) {
                try {
                    $data = $subscription->subscription;
                    if (! PushEndpoint::allowed($data['endpoint'])) {
                        $results[$subscription->id] = 410;

                        continue;
                    }
                    yield $subscription->id => $push->request($data, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                } catch (\Throwable) {
                    $results[$subscription->id] = 0;
                }
            }
        };
        // Parallel, bounded requests within ordinary PHP execution; no daemon or scheduler.
        $pool = new Pool($client, $requests(), [
            'concurrency' => 20,
            'options' => ['timeout' => 5, 'connect_timeout' => 2, 'allow_redirects' => false, 'http_errors' => false],
            'fulfilled' => function (ResponseInterface $response, $id) use (&$results) {
                $results[$id] = $response->getStatusCode();
            },
            'rejected' => function ($reason, $id) use (&$results) {
                $results[$id] = 0;
            },
        ]);
        $pool->promise()->wait();

        return $results;
    }
}
