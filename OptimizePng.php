<?php

namespace mrssoft\image;

use yii;

/**
 * Optimize PNG with service http://optimizeweb.ru/
 */
class OptimizePng extends \yii\base\Component
{
    public $url = 'http://optimizeweb.ru/api';

    public $token;

    public function init()
    {
        if (empty($this->token)) {
            $this->token = Yii::$app->params['optimizeWebToken'];
        }

        parent::init();
    }

    /**
     * @param string $filename
     * @return bool
     */
    public function run(string $filename): bool
    {
        if (empty($this->token)) {
            return false;
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            return false;
        }

        $postdata = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'png' => $content,
                    'token' => $this->token,
                    'params' => [
                        'compress' => 7
                    ]
                ]),
                'timeout' => 30
            ],
        ];

        $response = file_get_contents($this->url, false, stream_context_create($postdata));
        if (empty($response)) {
            return false;
        }

        $response = json_decode($response, true);
        if ($response['status'] == 'error') {
            return false;
        }

        $content = file_get_contents($response['file']);
        if ($content === false) {
            return false;
        }

        return file_put_contents($filename, $content) !== false;
    }
}