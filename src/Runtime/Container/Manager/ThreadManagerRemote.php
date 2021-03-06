<?php

namespace Kraken\Runtime\Container\Manager;


use Kraken\Channel\Channel;
use Kraken\Promise\Promise;
use Kraken\Channel\ChannelInterface;
use Kraken\Channel\Extra\Request;
use Kraken\Runtime\Container\ThreadManagerInterface;
use Kraken\Runtime\Runtime;
use Kraken\Runtime\RuntimeCommand;
use Kraken\Runtime\RuntimeContainerInterface;
use Kraken\Throwable\Exception\Runtime\RejectionException;

class ThreadManagerRemote implements ThreadManagerInterface
{
    /**
     * @var RuntimeContainerInterface
     */
    protected $runtime;

    /**
     * @var ChannelInterface
     */
    protected $channel;

    /**
     * @var string
     */
    protected $receiver;

    /**
     * @param RuntimeContainerInterface $runtime
     * @param ChannelInterface $channel
     */
    public function __construct(RuntimeContainerInterface $runtime, ChannelInterface $channel, $receiver = null)
    {
        $this->runtime = $runtime;
        $this->channel = $channel;
        $this->receiver = ($receiver !== null) ? $receiver : $runtime->getParent();
    }

    /**
     *
     */
    public function __destruct()
    {
        unset($this->runtime);
        unset($this->channel);
        unset($this->receiver);
    }

    /**
     * @override
     * @inheritDoc
     */
    public function sendRequest($alias, $message, $params = [])
    {
        $req = new Request(
            $this->channel,
            $alias,
            $message,
            $params
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function sendMessage($alias, $message, $flags = Channel::MODE_DEFAULT)
    {
        $result = $this->channel->send(
            $alias,
            $message,
            $flags
        );

        return Promise::doResolve($result);
    }

    /**
     * @override
     * @inheritDoc
     */
    public function existsThread($alias)
    {
        return false;
    }

    /**
     * @override
     * @inheritDoc
     */
    public function createThread($alias, $name, $flags = Runtime::CREATE_DEFAULT)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('thread:create', [ 'alias' => $alias, 'name' => $name, 'flags' => $flags ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function destroyThread($alias, $flags = Runtime::DESTROY_FORCE_SOFT)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('thread:destroy', [ 'alias' => $alias, 'flags' => $flags ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function startThread($alias)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('thread:start', [ 'alias' => $alias ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function stopThread($alias)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('thread:stop', [ 'alias' => $alias ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function createThreads($definitions, $flags = Runtime::CREATE_DEFAULT)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('threads:create', [ 'definitions' => $definitions, 'flags' => $flags ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function destroyThreads($aliases, $flags = Runtime::DESTROY_FORCE_SOFT)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('threads:destroy', [ 'aliases' => $aliases, 'flags' => $flags ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function startThreads($aliases)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('threads:start', [ 'aliases' => $aliases ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function stopThreads($aliases)
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('threads:stop', [ 'aliases' => $aliases ])
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getThreads()
    {
        $req = $this->createRequest(
            $this->channel,
            $this->receiver,
            new RuntimeCommand('threads:get')
        );

        return $req->call();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function flushThreads($flags = Runtime::DESTROY_KEEP)
    {
        return Promise::doReject(new RejectionException('Threads storage cannot be flushed.'));
    }

    /**
     * Create Request.
     *
     * @param ChannelInterface $channel
     * @param string $receiver
     * @param string $command
     * @return Request
     */
    protected function createRequest(ChannelInterface $channel, $receiver, $command)
    {
        return new Request($channel, $receiver, $command);
    }
}
