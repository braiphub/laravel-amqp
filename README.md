# Braiphub/laravel-amqp

## Installation

__________________
### composer

``
composer require braiphub/laravel-amqp
``

## Setup
__________________

### config/app.php

```php
return [

    'use' => 'production',

    'properties' => [

        'production' => [
            'host'                  => 'localhost',
            'port'                  =>  5672,
            'username'              => '',
            'password'              => '',
            'vhost'                 => '/',
            'connect_options'       => [],
            'ssl_options'           => [],

            'exchange'              => 'amq.topic',
            'exchange_type'         => 'topic',
            'exchange_passive'      => false,
            'exchange_durable'      => true,
            'exchange_auto_delete'  => false,
            'exchange_internal'     => false,
            'exchange_nowait'       => false,
            'exchange_properties'   => [],

            'queue_force_declare'   => false,
            'queue_passive'         => false,
            'queue_durable'         => true,          // only change when not using quorum queues
            'queue_exclusive'       => false,
            'queue_auto_delete'     => false,         // only change when not using quorum queues
            'queue_nowait'          => false,
            'queue_properties'      => [
                'x-ha-policy' => ['S', 'all'],
                'x-queue-type' => ['S', 'quorum'],
                // 'x-dead-letter-exchange' => ['S', 'amq.topic-dlx'], // if provided an exchange and queue (queue_name-dlx) will be automatically declared
                // 'x-delivery-limit' => ['I', 5],                     // the delivery limit will be set on the relevant queue but not the DLX queue itself
            ],
            'queue_acknowledge_is_final' => true,     // if important work is done inside a consumer after the acknowledge call, this should be false
            'queue_reject_is_final'      => true,     // if important work is done inside a consumer after the reject call, this should be false

            'consumer_tag'              => '',
            'consumer_no_local'         => false,
            'consumer_no_ack'           => false,
            'consumer_exclusive'        => false,
            'consumer_nowait'           => false,
            'timeout'                   => 0,        // seconds
            'persistent'                => false,
            'persistent_restart_period' => 0,        // seconds
            'request_accepted_timeout'  => 0.5,      // seconds in decimal accepted
            'request_handled_timeout'   => 5,        // seconds in decimal accepted

            'qos'                   => true,
            'qos_prefetch_size'     => 0,
            'qos_prefetch_count'    => 1,
            'qos_a_global'          => false,

            /*
            |--------------------------------------------------------------------------
            | An example binding set up when declaring exchange and queues
            |--------------------------------------------------------------------------
            |'bindings' => [
            |    [
            |        'queue'    => 'example',
            |        'routing'  => 'example.route.key',
            |    ],
            |],
            */
        ],

    ],

];
```
### config/app.php

#### providers and aliases

```php
'providers' => [
    ...
    Braiphub\AMQP\AMQPServiceProvider,
    ...
],
```

```php
'aliases' => [
    ...
    'Amqp' => Braiphub\AMQP\Facades\Amqp,
    ...
],
```
# Usage
__________________

### Publish message

```php
use Braiphub\AMQP\Facades\Amqp;

Amqp::publish('example.route.key', 'message');
```

### Publish message with routing key and overwrite properties

```php
Amqp::publish('routing-key', 'message' , ['exchange' => 'amq.direct']);
```

### Publishing Fanout message

```php
Amqp::publish('', 'message' , [
    'exchange_type' => 'fanout',
    'exchange' => 'amq.fanout',
]);
```
__Note:__ The routing key is ignored when publishing to a fanout exchange.

_______________________________

### Consume message forever

```php
Amqp::consume(function ($message) {

    var_dump($message->body);

    Amqp::acknowledge($message);

});
```

### Consume message forever with custom settings

```php
Amqp::consume(function ($message) {

   var_dump($message->body);

   Amqp::acknowledge($message);

}, [
    'timeout' => 0.5,
    'vhost'   => 'vhost-name
    'queue'   => 'queue-name',
    'persistent' => true // required if you want to listen forever
]);
```
_______________________________

## Disable publishing
This is useful for development and sync requirements, if you are using observers 
or events to trigger messages over AMQP you may want to temporarily disable the 
publishing of messages. When turning the publishing off the publish method will 
silently drop the message and return.

#### Check if publishing is enabled

```php
if(Amqp::isEnabled()) {
    Amqp::publish('example.route.key', 'message');
}
```

#### Disable publishing

```php
Amqp::disable();
```

#### Enable publishing

```php
Amqp::enable();
```




