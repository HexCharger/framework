<?php

namespace Kraken\Ipc\Socket;

use Kraken\Event\BaseEventEmitter;
use Kraken\Throwable\Exception\Runtime\ReadException;
use Kraken\Throwable\Exception\Logic\InstantiationException;
use Kraken\Throwable\Exception\LogicException;
use Kraken\Loop\LoopAwareTrait;
use Kraken\Loop\LoopInterface;
use Error;
use Exception;

class SocketListener extends BaseEventEmitter implements SocketListenerInterface
{
    use LoopAwareTrait;

    /**
     * @var int
     */
    const DEFAULT_BACKLOG = 1024;

    /**
     * @var resource
     */
    protected $socket;

    /**
     * @var bool
     */
    protected $open;

    /**
     * @var bool
     */
    protected $paused;

    /**
     * @param string|resource $endpointOrResource
     * @param LoopInterface $loop
     * @param mixed[] $config
     * @throws InstantiationException
     */
    public function __construct($endpointOrResource, LoopInterface $loop, $config = [])
    {
        try
        {
            if (!is_resource($endpointOrResource))
            {
                $endpointOrResource = $this->createServer($endpointOrResource, $config);
            }

            $this->socket = $endpointOrResource;
            $this->loop = $loop;
            $this->open = true;
            $this->paused = true;

            $this->resume();
        }
        catch (Error $ex)
        {
            throw new InstantiationException('SocketServer could not be created.', $ex);
        }
        catch (Exception $ex)
        {
            throw new InstantiationException('SocketServer could not be created.', $ex);
        }
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->handleClose();

        parent::__destruct();

        unset($this->socket);
        unset($this->loop);
        unset($this->open);
        unset($this->paused);
    }

    /**
     * @override
     * @inheritDoc
     */
    public function stop()
    {
        $this->close();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getLocalEndpoint()
    {
        return $this->parseEndpoint();
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getLocalAddress()
    {
        $endpoint = explode('://', $this->getLocalEndpoint(), 2);

        return isset($endpoint[1]) ? $endpoint[1] : $endpoint[0];
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getLocalHost()
    {
        $address = explode(':', $this->getLocalAddress(), 2);

        return $address[0];
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getLocalPort()
    {
        $address = explode(':', $this->getLocalAddress(), 2);

        return isset($address[1]) ? $address[1] : '';
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getResource()
    {
        return $this->socket;
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getResourceId()
    {
        return (int) $this->socket;
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getMetadata()
    {
        return stream_get_meta_data($this->socket);
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getStreamType()
    {
        $data = $this->getMetadata();

        return isset($data['stream_type']) ? $data['stream_type'] : 'undefined';
    }

    /**
     * @override
     * @inheritDoc
     */
    public function getWrapperType()
    {
        $data = $this->getMetadata();

        return isset($data['wrapper_type']) ? $data['wrapper_type'] : 'undefined';
    }

    /**
     * @override
     * @inheritDoc
     */
    public function isOpen()
    {
        return $this->open;
    }

    /**
     * @override
     * @inheritDoc
     */
    public function isPaused()
    {
        return $this->paused;
    }

    /**
     * @override
     * @inheritDoc
     */
    public function close()
    {
        if (!$this->isOpen())
        {
            return;
        }

        $this->open = false;

        $this->emit('close', [ $this ]);
        $this->handleClose();
        $this->emit('done', [ $this ]);
    }

    /**
     * @override
     * @inheritDoc
     */
    public function pause()
    {
        if (!$this->paused)
        {
            $this->paused = true;

            if (isset($this->loop))
            {
                $this->loop->removeReadStream($this->socket);
            }
        }
    }

    /**
     * @override
     * @inheritDoc
     */
    public function resume()
    {
        if ($this->paused)
        {
            $this->paused = false;

            if (isset($this->loop))
            {
                $this->loop->addReadStream($this->socket, [ $this, 'handleConnect' ]);
            }
        }
    }

    /**
     * Create the server resource.
     *
     * This method creates server resource for socket connections.
     *
     * @param string $endpoint
     * @param mixed[] $config
     * @return resource
     * @throws LogicException
     */
    protected function createServer($endpoint, $config = [])
    {
        if (stripos($endpoint, 'unix://') !== false && $endpoint[7] !== DIRECTORY_SEPARATOR)
        {
            $endpoint = 'unix://' . getcwd() . DIRECTORY_SEPARATOR . substr($endpoint, 7);
        }

        $backlog = (int) (isset($config['backlog']) ? $config['backlog'] : self::DEFAULT_BACKLOG);
        $reuseaddr = (bool) (isset($config['reuseaddr']) ? $config['reuseaddr'] : false);
        $reuseport = (bool) (isset($config['reuseport']) ? $config['reuseport'] : false);

        $context = [];
        $context['socket'] = [
            'bindto'        => $endpoint,
            'backlog'       => $backlog,
            'ipv6_v6only'   => true,
            'so_reuseaddr'  => $reuseaddr,
            'so_reuseport'  => $reuseport,
        ];

        $context = stream_context_create($context);
        // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure.
        $socket = @stream_socket_server(
            $endpoint,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$socket || $errno)
        {
            throw new LogicException(
                sprintf('Could not bind socket [%s] because of error [%d; %s]', $endpoint, $errno, $errstr)
            );
        }

        return $socket;
    }

    /**
     * Create the client resource.
     *
     * This method creates client resource for socket connections.
     *
     * @param resource $resource
     * @return SocketInterface
     */
    protected function createClient($resource)
    {
        return new Socket($resource, $this->loop);
    }

    /**
     * Handle the new connection.
     *
     * @internal
     */
    public function handleConnect()
    {
        $newSocket = @stream_socket_accept($this->socket);

        if ($newSocket === false)
        {
            $this->emit('error', [ $this, new ReadException('Socket could not accept new connection.') ]);
            return;
        }

        stream_set_blocking($newSocket, 0);

        $client = $this->createClient($newSocket);

        $this->emit('connect', [ $this, $client ]);
    }

    /**
     * Handle closing event.
     *
     * @internal
     */
    public function handleClose()
    {
        $this->pause();

        if (is_resource($this->socket))
        {
            if ($this->getStreamType() === Socket::TYPE_UNIX)
            {
                $path = substr($this->parseEndpoint(), 7);
                unlink($path);
            }
            fclose($this->socket);
        }
    }

    /**
     * @return string
     */
    private function parseEndpoint()
    {
        $name = stream_socket_get_name($this->socket, false);
        $type = $this->getStreamType();

        switch ($type)
        {
            case Socket::TYPE_UNIX:
                $endpoint = 'unix://' . $name;
                break;

            case Socket::TYPE_TCP:
                if (substr_count($name, ':') > 1)
                {
                    $parts = explode(':', $name);
                    $count = count($parts);
                    $port = $parts[$count - 1];
                    unset($parts[$count - 1]);
                    $endpoint = 'tcp://[' . implode(':', $parts) . ']:' . $port;
                }
                else
                {
                    $endpoint = 'tcp://' . $name;
                }
                break;

            default:
                $endpoint = '';
        }

        return $endpoint;
    }
}
