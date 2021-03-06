<?php

namespace Mix\Http\Server;

use Mix\Http\Message\Factory\ResponseFactory;
use Mix\Http\Message\Factory\ServerRequestFactory;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Class Server
 * @package Mix\Http\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class Server
{

    /**
     * @var string
     */
    public $host = '';

    /**
     * @var int
     */
    public $port = 0;

    /**
     * @var bool
     */
    public $ssl = false;

    /**
     * @var bool
     */
    public $reusePort = false;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var []callable
     */
    protected $callbacks = [];

    /**
     * @var \Swoole\Coroutine\Http\Server
     */
    public $swooleServer;

    /**
     * HttpServer constructor.
     * @param string $host
     * @param int $port
     * @param bool $ssl
     * @param bool $reusePort
     */
    public function __construct(string $host, int $port, bool $ssl = false, bool $reusePort = false)
    {
        $this->host      = $host;
        $this->port      = $port;
        $this->ssl       = $ssl;
        $this->reusePort = $reusePort;
    }

    /**
     * Set
     * @param array $options
     */
    public function set(array $options)
    {
        $this->options = $options;
    }

    /**
     * Handle
     * @param string $pattern
     * @param callable $callback
     */
    public function handle(string $pattern, callable $callback)
    {
        $this->callbacks[$pattern] = $callback;
    }

    /**
     * Start
     * @param HandlerInterface|null $handler
     * @throws \Swoole\Exception
     */
    public function start(HandlerInterface $handler = null)
    {
        if (!is_null($handler)) {
            $this->callbacks = [];
            $this->handle('/', [$handler, 'handleHTTP']);
        }
        $server     = $this->swooleServer = new \Swoole\Coroutine\Http\Server($this->host, $this->port, $this->ssl, $this->reusePort);
        $this->port = $server->port; // 当随机分配端口时同步端口信息
        $server->set($this->options);
        foreach ($this->callbacks as $pattern => $callback) {
            $server->handle($pattern, function (Request $requ, Response $resp) use ($callback) {
                try {
                    // 生成PSR的request,response
                    $request  = (new ServerRequestFactory)->createServerRequestFromSwoole($requ);
                    $response = (new ResponseFactory)->createResponseFromSwoole($resp);
                    // 执行回调
                    call_user_func($callback, $request, $response);
                } catch (\Throwable $e) {
                    // 错误处理
                    $isMix = class_exists(\Mix::class);
                    if (!$isMix) {
                        throw $e;
                    }
                    /** @var \Mix\Console\Error $error */
                    $error = \Mix::$app->context->get('error');
                    $error->handleException($e);
                }
            });
        }
        if (!$server->start()) {
            throw new \Swoole\Exception($server->errMsg, $server->errCode);
        }
    }

    /**
     * Shutdown
     * @throws \Swoole\Exception
     */
    public function shutdown()
    {
        if (!$this->swooleServer) {
            return;
        }
        if (!$this->swooleServer->shutdown()) { // 返回 null
            $errMsg  = $this->swooleServer->errMsg;
            $errCode = $this->swooleServer->errCode;
            if ($errMsg == 'Operation canceled' && in_array($errCode, [89, 125])) { // mac=89, linux=125
                return;
            }
            throw new \Swoole\Exception($errMsg, $errCode);
        }
    }

}
