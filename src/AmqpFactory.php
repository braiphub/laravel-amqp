<?php

namespace Braiphub\Amqp;

use Exception;

class AmqpFactory
{
    private static array $channels = [];

    /**
     * @param array $properties
     * @param array|null $base
     * @return Amqp
     */
    public static function create(array $properties = [], array $base = null): Amqp
    {
        if (empty($base)) {
            $base = config('laravel-amqp.properties.' . config('laravel-amqp.use'));
        }

        $final = array_merge($base, $properties);

        if(isset(self::$channels[$final['exchange'] . $final['queue']])) {
            return self::$channels[$final['exchange'] . $final['queue']];
        }
        return self::$channels[$final['exchange'] . $final['queue']] = new Amqp($final);
    }

    /**
     * @param array $properties
     * @param array|null $base
     * @return AmqpChannel
     * @throws Exception
     */
    public static function createTemporary(array $properties = [], array $base = null): AmqpChannel
    {
        if (empty($base)) {
            $base = config('laravel-amqp.properties.' . config('laravel-amqp.use'));
        }
        return new AmqpChannel(array_merge($base, $properties));
    }

    /**
     * @param array $properties
     * @return void
     */
    public static function clear(array $properties = []): void
    {
        if (!empty($properties['exchange']) && !empty($properties['queue'])) {
            unset(self::$channels[$properties['exchange'] . '.' . $properties['queue']]);
        }
    }
}