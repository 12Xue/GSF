<?php
namespace Swoole\Async;

use Swoole;

class WebSocket extends Swoole\Client\WebSocket
{
    private $callbacks = array();

    /**
     * Connect client to server
     * @param float $timeout
     * @throws \Exception
     * @return null
     */
    public function connect($timeout = 0.5)
    {
        if (!extension_loaded('swoole'))
        {
            throw new \Exception('swoole extension is required for WebSocketAsync');
        }

        //没有onMessage
        if (!$this->_callback('message'))
        {
            throw new \Exception('no message event callback.');
        }

        $this->socket = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);

        $this->socket->on("connect", array($this, 'onConnect'));
        $this->socket->on("receive", array($this, 'onReceive'));
        $this->socket->on("error", array($this, 'onError'));
        $this->socket->on("close", array($this, 'onClose'));

        $this->socket->connect($this->host, $this->port, $timeout);
    }

    /**
     * @param $event
     * @param $callable
     * @throws Swoole\Http\WebSocketException
     */
    public function on($event, $callable)
    {
        switch($event)
        {
            case 'open':
            case 'message':
            case 'close':
            case 'error':
                break;
            default:
                throw new Swoole\Http\WebSocketException("unknow event type.");
        }
        $this->callbacks[$event] = $callable;
    }

    /**
     * @param $event
     * @return string
     */
    private function _callback($event)
    {
        return isset($this->callbacks[$event]) ? $this->callbacks[$event] : '';
    }

    /**
     * @param \swoole_client $socket
     */
    final public function onConnect(\swoole_client $socket)
    {
        $socket->send($this->createHeader());
        $this->connected = true;
    }

    /**
     * @param \swoole_client $socket
     * @param $data
     * @throws Swoole\Http\WebSocketException
     */
    final public function onReceive(\swoole_client $socket, $data)
    {
        //已建立连接并完成了握手，解析数据帧
        if ($this->handshake)
        {
            $this->parser->push($data);
            try
            {
                while (true)
                {
                    $frame = $this->parser->pop($data);
                    if ($frame and $callable = $this->_callback('message'))
                    {
                        call_user_func($callable, $this, $frame);
                    }
                    else
                    {
                        return;
                    }
                }
            }
            catch (Swoole\Http\WebSocketException $e)
            {
                if ($e->getCode() == Swoole\Http\WebSocketParser::ERR_TOO_LONG)
                {
                    $this->socket->close();
                    $this->connected = false;
                }
            }
        }
        //握手
        else
        {
            $this->buffer .= $data;
            if (substr($this->buffer, -4, 4) == "\r\n\r\n")
            {
                if ($this->doHandShake($this->buffer) and $callable = $this->_callback('open'))
                {
                    call_user_func($callable, $this, $this->header);
                }
                else
                {
                    $this->disconnect();
                }
            }
            else
            {
                return;
            }
        }
    }

    /**
     * @param \swoole_client $socket
     */
    public function onError(\swoole_client $socket)
    {
        if ($callable = $this->_callback('error'))
        {
            call_user_func($callable, $this);
        }
    }

    /**
     * @param \swoole_client $socket
     */
    public function onClose(\swoole_client $socket)
    {
        if ($callable = $this->_callback('close'))
        {
            call_user_func($callable, $this);
        }
    }
}