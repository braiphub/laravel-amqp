<?php

namespace Braiphub\Amqp\Tests;

use Braiphub\Amqp\AmqpFactory;
use phpmock\Mock;
use PHPUnit\Framework\TestCase;
use phpmock\MockBuilder;
use Mockery;

class BaseTest extends TestCase
{
    protected static Mock $mocks;

    protected array $properties;

    public function setUp(): void
    {
        $amqpConfig = include dirname(__FILE__) . '/../config/amqp.php';
        $this->properties = array_merge($amqpConfig['properties'][$amqpConfig['use']], [
            'host'          => 'localhost',
            'port'          =>  5672,
            'username'      => 'guest',
            'password'      => 'guest',
            'exchange'      => 'test',
            'consumer_tag'  => 'test',
        ]);

        if (empty(self::$mocks)) {
            $builder = new MockBuilder();
            $builder->setNamespace('ComLaude\\Amqp')
                ->setName('config')
                ->setFunction(
                    function ($string) {
                        if ($string === 'amqp.use') {
                            return '';
                        }
                        return $this->properties;
                    }
                );
            self::$mocks = $builder->build();
            self::$mocks->enable();
        }
    }

    public function tearDown(): void
    {
        if (! empty(self::$mocks)) {
            self::$mocks->disable();
            self::$mocks = null;
        }
        Mockery::close();
    }

    public function consumeNextMessage($properties)
    {
        AmqpFactory::create($properties)->getChannel()->wait(null, true);
    }

    public function deleteEverything($properties)
    {
        AmqpFactory::create($properties)->getChannel()->queue_delete($properties['queue']);
        AmqpFactory::create($properties)->disconnect();
    }

    public function createQueue($properties)
    {
        AmqpFactory::create($properties)->declareQueue();
    }
}
