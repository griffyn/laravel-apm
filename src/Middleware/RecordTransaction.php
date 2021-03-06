<?php

namespace faridcs\ApmLaravel\Middleware;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use PhilKra\Agent;

class RecordTransaction
{
    /**
     * @var \PhilKra\Agent
     */
    protected $agent;

    /**
     * RecordTransaction constructor.
     * @param Agent $agent
     */
    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * [handle description]
     * @param  Request  $request [description]
     * @param  Closure $next    [description]
     * @return [type]           [description]
     */
    public function handle($request, Closure $next)
    {
        $transaction = $this->agent->startTransaction(
            $this->getTransactionName($request)
        );

        // await the outcome
        $response = $next($request);

        $transaction->setResponse([
            'finished'     => true,
            'headers_sent' => true,
            'status_code'  => $response->getStatusCode(),
            'headers'      => $this->formatHeaders($response->headers->all()),
        ]);

        $transaction->setUserContext([
            'id'    => \faridcs\ApmLaravel\Utils\Helper::optional($request->user())->id,
            'email' => \faridcs\ApmLaravel\Utils\Helper::optional($request->user())->email,
        ]);

        $transaction->setMeta([
            'result' => $response->getStatusCode(),
            'type'   => 'HTTP'
        ]);

        $transaction->setSpans(app('query-log')->toArray());

        $transaction->stop(
            $this->getDuration(LARAVEL_START)
        );

        return $response;
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     *
     * @return void
     */
    public function terminate($request, $response)
    {
        $this->agent->send();
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected function getTransactionName(\Illuminate\Http\Request $request)
    {
        // fix leading /
        $path = ($request->server->get('PATH_INFO') == '') ? '/' : $request->server->get('PATH_INFO');

        return sprintf(
            "%s %s",
            $request->server->get('REQUEST_METHOD'),
            $path
        );
    }

    /**
     * @param $start
     * @return float
     */
    protected function getDuration($start): float
    {
        $diff = microtime(true) - $start;
        $corrected = $diff * 1000; // convert to miliseconds

        return round($corrected, 3);
    }

    /**
     * @param array $headers
     * @return array
     */
    protected function formatHeaders(array $headers): array
    {
        return collect($headers)->map(function ($values, $header) {
            return head($values);
        })->toArray();
    }
}
