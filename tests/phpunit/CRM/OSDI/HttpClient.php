<?php

class CRM_OSDI_HttpClient {

  public static function client(): \Jsor\HalClient\HttpClient\Guzzle6HttpClient {
    return new \Jsor\HalClient\HttpClient\Guzzle6HttpClient(new \GuzzleHttp\Client([
      'timeout' => 10.0,
      'request.options' => [
        'proxy' => 'tcp://127.0.0.1:2001',
      ],
    ]));
  }

}