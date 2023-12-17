<?php

namespace Braiphub\Amqp;

use Closure;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;

class Amqp
{
    private static bool $publishingEnabled = true;

    /**
     * @return void
     * Global disable publishing of messages.
     */
    public static function disable(): void
    {
        self::$publishingEnabled = false;
    }

    /**
     * @return void
     * Global enable publishing of messages.
     */
    public function enable(): void
    {
        self::$publishingEnabled = true;
    }

    /**
     * @return bool
     * Check if publishing is enabled.
     */
    public function isEnabled(): bool
    {
        return self::$publishingEnabled;
    }

    /**
     * @param string $route
     * @param string $message
     * @param array $properties
     * @param array $messageProperties
     * @return void
     * Publish a message to a queue.
     */
    public function publish(string $route, string $message, array $properties = [], array $messageProperties = []): void
    {
        if (!self::$publishingEnabled) {
            return;
        }
        $message = new AMQPMessage($message, $messageProperties);
        AmqpFactory::create(array_merge());
    }

    /**
     * @param string $route
     * @param string $message
     * @param array $properties
     * @return void|null
     */
    public function publishPersistent(string $route, string $message, array $properties = [])
    {
        if(!$this->isEnabled()){
            return;
        }

        return $this->publish($route, $message, $properties, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
    }

    public function consume(Closure $callback, array $properties = []): void
    {
        AmqpFactory::create($properties)->consume($callback);
    }

    public function acknowledge(AMQPMessage $message, array $properties = []): void
    {
        AmqpFactory::create($properties)->acknowledge($message);
    }

    public function reject(AMQPMessage $message, bool $requeue = false, array $properties = []): void
    {
        AmqpFactory::create($properties)->reject($message, $requeue);
    }

    /**
     * @throws AmqpChannelException
     * @throws Exception
     */
    public function request(string $route, array $messages, Closure $callback, array $properties = []): bool
    {
        return AmqpFactory::createTemporary(array_merge($properties, [
            'exchange' => false,
            'queue' => false,
            'queue_passive' => false,
            'queue_durable' => false,
            'queue_exclusive' => true,
            'queue_auto_delete' => true,
            'queue_nowait' => false,
            'queue_properties' => ['x-ha-policy' => ['S', 'all'], 'x-queue-type' => ['S', 'classic']],
        ]))->request(
            $route,
            is_array($messages) ? [$messages] : $messages,
            $callback,
            $properties
        );
    }

    /**
     * @throws AmqpChannelException
     */
    public function requestWithResponse(string $route, string $message, array $properties = [])
    {
        $response = null;
        $this->request(
            $route,
            [$message],
            function ($message) use (&$response) {
                $response = $message->getBody();
            },
            $properties,
        );
        return $response;
    }
}