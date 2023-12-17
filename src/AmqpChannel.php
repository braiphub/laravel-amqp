<?php

namespace Braiphub\Amqp;

use Closure;
use Exception;
use Braiphub\Amqp\Exceptions\AmqpChannelException;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpChannel
{
    private array $properties;
    private string $tag;
    private $connection;
    private $signaller;
    private $channel;
    private $queue;
    private $lastAcknowledge;
    private $lastReject;
    private mixed $retry = 0;

    /**
     * @throws Exception
     */
    public function __construct(array $properties = [])
    {
        $this->properties = $properties;
        $this->tag = ($this->properties['consumer_tag'] ?? 'laravel-amqp-' . config('app.name')) . uniqid();
        $this->retry = $properties['reconnect_attempts'] ?? 3;
        $this->lastReject = [];
        $this->lastAcknowledge = [];

        $this->preConnectionEstablished();
        $this->connect();
        $this->declareExchange();
        $this->postConnectionEstablished();
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getChannel()
    {
        return $this->channel;
    }

    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * @param $route
     * @param $message
     * @return $this
     * @throws AMQPConnectionException | AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException|AmqpChannelException
     */
    public function publish($route, $message): static
    {
        $this->retry = $this->properties['reconnect_attempts'] ?? 3;

        while ($this->retry >= 0) {
            try {
                $this->channel->basic_publish($message, $this->properties['exchange'], $route);
                break;
            } catch (AMQPConnectionException|AMQPHeartbeatMissedException|AMQPChannelClosedException|AMQPConnectionClosedException|AmqpChannelException $e) {
                if (--$this->retry < 0) {
                    throw $e;
                }
                $this->reconnect();
            }
        }
        return $this;
    }

    /**
     * @throws AmqpChannelException
     */
    public function consume(Closure $callback): bool
    {
        $this->declareQueue();

        if (!$this->properties['qos'] || $this->properties['qos'] !== null) {
            $this->channel->basic_qos(
                $this->properties['qos_prefetch_size'] ?? 0,
                $this->properties['qos_prefetch_count'] ?? 1,
                $this->properties['qos_a_global'] ?? false
            );
        }

        $channelCallback = function ($message) use ($callback) {
            if ($message->get('redelivered') === true && $this->redeliveryCheckAndSkip($message)) {
                return;
            }

            if ($message->has('reply_to') && $message->has('correlation_id')) {

                $responseChannel = AmqpFactory::create(['exchange' => '']);
                $responseChannel->publish($message->get('reply_to'), new AMQPMessage('', [
                    'correlation_id' => $message->get('correlation_id') . '_accepted',
                ]));

                return $responseChannel->publish($message->get('reply_to'), new AMQPMessage($callback($message), [
                    'correlation_id' => $message->get('correlation_id'),
                ]));
           }
            $callback($message);
        };

        try {
            $this->channel->basic_consume(
                $this->properties['queue'],
                $this->tag,
                $this->properties['consumer_no_local'] ?? false,
                $this->properties['consumer_no_ack'] ?? false,
                $this->properties['consumer_exclusive'] ?? false,
                $this->properties['consumer_nowait'] ?? false,
                $channelCallback,
            );

            $restart = false;
            $startTime = time();
            do {
                $this->channel->wait(null, false, $this->properties['timeout'] ?? 0);
                if ($this->properties['persistent_restart_period'] > 0
                    && $this->properties['persistent_restart_period'] < time() - $startTime
                ) {
                    $restart = true;
                    break;
                }
            } while (count($this->channel->callbacks) || $this->properties['persistent'] ?? false);
        } catch (AMQPTimeoutException $e) {
            $restart = false;
        } catch (AMQPProtocolChannelException|AmqpChannelException $e) {
            $restart = true;
        }

        if ($restart) {
            $this->reconnect(true);
            return $this->consume($callback);
        }

        return true;

    }

    /**
     * @throws AmqpChannelException
     */
    public function request($route, $messages, $callback, $properties = []): bool
    {
        $this->declareQueue();

        $requestId = $properties['correlation_id'] ?? uniqid() . '_' . count($messages);
        $requestSender = AmqpFactory::createTemporary($properties);

        foreach ($messages as $message) {
            $requestSender->publish($route, new AMQPMessage($message, [
                'correlation_id' => $requestId,
                'reply_to' => $this->queue[0],
            ]));
        }

        $startTime = microtime(true);
        $startHandleTime = microtime(true);
        $jobAccepted = false;
        $jobsHandled = 0;

        $this->channel->basic_consume(
            $this->queue[0],
            'request-exclusive-listener',
            false,
            true,
            false,
            false,
            function($message) use (&$jobAccepted, &$jobsHandled, $requestId, $callback){
                if ($message->get('correlation_id') === $requestId . '_accepted') {
                    $jobAccepted = true;
                    return;
                }

                if ($message->get('correlation_id') === $requestId . '_handled') {
                    $jobsHandled++;
                    $callback($message);
                }
            },
        );

        while (
            ($this->properties['request_accepted_timeout'] > microtime(true) - $startTime || $jobAccepted)
            && $this->properties['request_handled_timeout'] > microtime(true) - $startHandleTime && $jobsHandled < count($messages)
        ) {
            usleep(10);
            $this->channel->wait(null, true, $this->properties['request_accepted_timeout']);
        }

        $this->channel->basic_cancel('request-exclusive-listener');
        $this->channel->queue_delete($this->queue[0]);

        return $jobsHandled == count($messages);
    }

    /**
     * @throws AmqpChannelException
     * @throws Exception
     */
    private function reconnect($intentionalReconnection = false): void
    {
        try {
            if ($this->channel->is_consuming()) {
                $this->channel->close();
            }
            $this->disconnect();
        } catch (AMQPProtocolChannelException|AMQPRuntimeException $e) {
            // just continue with reconnect
        }

        $this->preConnectionEstablished();
        $this->connect();
        $this->declareExchange();
        $this->postConnectionEstablished();
        if (!$intentionalReconnection) {
            throw new AmqpChannelException('Reconnected to AMQP server');
        }
    }

    public function declareQueue(): static
    {
        $this->queue = $this->channel->queue_declare(
            $this->properties['queue'],
            $this->properties['queue_passive'] ?? false,
            $this->properties['queue_durable'] ?? true,
            $this->properties['queue_exclusive'] ?? false,
            $this->properties['queue_auto_delete'] ?? false,
            $this->properties['queue_nowait'] ?? false,
            $this->properties['queue_properties'] ?? [
            'x-ha-policy' => ['S', 'all'],
            'x-queue-type' => ['S', 'quorum'],
        ]
        );

        $queueName = $this->properties['queue'] ?: $this->queue[0];
        $queueNameDeadLetterExchange = $queueName . '-dlx';

        if (!empty($this->properties['queue_properties']['x-dead-letter-exchange'][1])) {
            $this->queue = $this->channel->queue_declare(
                $queueNameDeadLetterExchange,
                $this->properties['queue_passive'] ?? false,
                $this->properties['queue_durable'] ?? true,
                $this->properties['queue_exclusive'] ?? false,
                $this->properties['queue_auto_delete'] ?? false,
                $this->properties['queue_nowait'] ?? false,
                array_diff_key($this->properties['queue_properties'] ?? [
                    'x-ha-policy' => ['S', 'all'],
                    'x-queue-type' => ['S', 'quorum'],
                ], [
                    'x-dead-letter-exchange' => 'not_set_on_dlx_queues',
                    'x-delivery-limit' => 'not_set_on_dlx_queues',
                ])
            );
        }

        if (!empty($this->properties['bindings'])) {
            foreach ((array)$this->properties['bindings'] as $binding) {
                if ($binding['queue'] === $this->properties['queue']) {
                    $this->channel->queue_bind(
                        $queueName,
                        $this->properties['exchange'],
                        $binding['routing']
                    );

                    if (!empty($this->properties['queue_properties']['x-dead-letter-exchange'][1])) {
                        $this->channel->queue_bind(
                            $queueNameDeadLetterExchange,
                            $this->properties['queue_properties']['x-dead-letter-exchange'][1],
                            $binding['routing']
                        );
                    }
                }
            }
        }

        return $this;
    }

    private function declareExchange(): void
    {
        if (!empty($this->properties['exchange'])) {
            $this->channel->exchange_declare(
                $this->properties['exchange'],
                $this->properties['exchange_type'] ?? 'topic',
                $this->properties['exchange_passive'] ?? false,
                $this->properties['exchange_durable'] ?? true,
                $this->properties['exchange_auto_delete'] ?? false,
                $this->properties['exchange_internal'] ?? false,
                $this->properties['exchange_nowait'] ?? false,
                $this->properties['exchange_properties'] ?? []
            );
        }

        if (!empty($this->properties['queue_properties']['x-dead-letter-exchange'][1])) {
            $this->channel->exchange_declare(
                $this->properties['queue_properties']['x-dead-letter-exchange'][1],
                $this->properties['exchange_type'] ?? 'topic',
                $this->properties['exchange_passive'] ?? false,
                $this->properties['exchange_durable'] ?? true,
                $this->properties['exchange_auto_delete'] ?? false,
                $this->properties['exchange_internal'] ?? false,
                $this->properties['exchange_nowait'] ?? false,
                $this->properties['exchange_properties'] ?? []
            );
        }

    }

    private function preConnectionEstablished(): void
    {
        if ($this->signaller) {
            $this->signaller->unregister();
        }
    }

    private function postConnectionEstablished(): void
    {
        $this->connection->set_close_on_destruct(true);
        if ($this->properties['register_pcntl_heartbeat_sender'] ?? false) {
            $this->signaller = new PCNTLHeartbeatSender($this->connection);
            $this->signaller->register();
        }
    }

    /**
     * @throws Exception
     */
    public function connect(): static
    {
        if (!empty($this->properties['ssl_options'])) {
            $this->connection = new AMQPSSLConnection(
                $this->properties['host'],
                $this->properties['port'],
                $this->properties['username'],
                $this->properties['password'],
                $this->properties['vhost'],
                $this->properties['ssl_options'],
                $this->properties['connect_options']
            );
        } else {
            $this->connection = new AMQPStreamConnection(
                $this->properties['host'],
                $this->properties['port'],
                $this->properties['username'],
                $this->properties['password'],
                $this->properties['vhost'],
                $this->properties['connect_options']['insist'] ?? false,
                $this->properties['connect_options']['login_method'] ?? 'AMQPLAIN',
                $this->properties['connect_options']['login_response'] ?? null,
                $this->properties['connect_options']['locale'] ?? 3,
                $this->properties['connect_options']['connection_timeout'] ?? 3.0,
                $this->properties['connect_options']['read_write_timeout'] ?? 130,
                $this->properties['connect_options']['context'] ?? null,
                $this->properties['connect_options']['keepalive'] ?? false,
                $this->properties['connect_options']['heartbeat'] ?? 60,
                $this->properties['connect_options']['channel_rpc_timeout'] ?? 0.0,
                $this->properties['connect_options']['ssl_protocol'] ?? null
            );
        }

        $this->channel = $this->connection->channel();

        return $this;
    }

    public function disconnect(): void
    {
        AmqpFactory::clear($this->properties);
        try {
            if (!empty($this->channel)) {
                $this->channel->close();
            }
            if (!empty($this->connection)) {
                $this->connection->close();
            }
        } catch (AMQPChannelClosedException|AMQPConnectionClosedException $e) {
            $this->channel = null;
            $this->connection = null;
        }
    }

    /**
     * @throws AmqpChannelException
     */
    public function redeliveryCheckAndSkip(AMQPMessage $message): bool
    {
        if (! empty($this->lastAcknowledge)) {
            foreach ($this->lastAcknowledge as $index => $item) {
                if ($item->body === $message->body && $item->get('routing_key') === $message->get('routing_key')) {
                    unset($this->lastAcknowledge[$index]);
                    $this->acknowledge($message);
                    return true;
                }
            }
        }
        if (! empty($this->lastReject)) {
            foreach ($this->lastReject as $index =>$item) {
                if ($item[0]->body === $message->body && $item[0]->get('routing_key') === $message->get('routing_key')) {
                    unset($this->lastReject[$index]);
                    $this->reject($message, $item[1]);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @throws AmqpChannelException
     */
    public function reject(AMQPMessage $message, $requeue = false): void
    {
        try {
            $message->getChannel()->basic_reject($message->get('delivery_tag'), $requeue);
        } catch (AMQPConnectionException|AMQPHeartbeatMissedException|AMQPChannelClosedException|AMQPConnectionClosedException $e) {
            if ($this->properties['queue_reject_is_final'] ?? true) {
                // We cache the reject just in case it is redelivered
                $this->lastReject[] = [$message, $requeue];
            }
            $this->reconnect();
        }
    }

    /**
     * @throws AmqpChannelException
     */
    private function acknowledge(AMQPMessage $message): void
    {
        try {
            $message->getChannel()->basic_ack($message->get('delivery_tag'));

            if ($message->body === 'quit') {
                $message->getChannel()->basic_cancel($this->tag);
            }
        } catch (AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
            if ($this->properties['queue_acknowledge_is_final'] ?? true) {
                $this->lastAcknowledge[] = $message;
            }
            $this->reconnect();
        }
    }

}