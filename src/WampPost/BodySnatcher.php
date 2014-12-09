<?php

namespace WampPost;


use React\Http\Request;
use React\Promise\Deferred;

class BodySnatcher {
    protected $body = '';
    protected $contentLength = 0;
    protected $request;
    protected $deferred;

    function __construct(Request $request)
    {
        $this->request = $request;

        $headers = $request->getHeaders();
        if (isset($headers['Content-Length']) && is_numeric($contentLength = $headers['Content-Length'])) {
            $this->contentLength = $contentLength;
        }

        $request->on('data', [$this, 'handleData']);
        $request->on('close', [$this, 'handleClose']);
        $request->on('error', [$this, 'handleError']);
    }

    public function promise() {
        if ($this->deferred === null) $this->deferred = new Deferred();
        return $this->deferred->promise();
    }

    private function resolvePromise() {
        // remove ourselves from the handlers
        $this->request->removeListener('data', [$this, 'handleData']);
        $this->request->removeListener('close', [$this, 'handleClose']);
        $this->request->removeListener('error', [$this, 'handleError']);
        if ($this->deferred !== null) $this->deferred->resolve($this->body);
    }

    public function handleData($data) {
        $this->body .= $data;
        if ($this->contentLength >= 0 && strlen($this->body) >= $this->contentLength) {
            $this->resolvePromise();
        }

        $this->deferred->progress($data);
    }

    public function handleClose($data) {
        $this->resolvePromise();
    }

    public function handleError($e)
    {
        $this->deferred->reject($e);
    }
}