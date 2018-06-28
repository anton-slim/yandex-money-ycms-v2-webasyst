<?php

Class YaMetrika
{
    var $client_id;
    var $client_secret;
    var $username;
    var $password;
    var $number;
    var $token;
    public $url_api = 'https://api-metrika.yandex.ru/management/v1/';

    public function __construct($token, $number, $appId, $password)
    {
        $this->number        = $number;
        $this->token         = $token ? $token : '';
        $this->client_id     = $appId;
        $this->client_secret = $password;
    }

    /**
     * @param array $oldSettings
     * @return bool
     */
    public function isNeedUpdateToken($oldSettings)
    {
        if (empty($this->number) || empty($this->client_id) || empty($this->client_secret)) {
            return false;
        }
        if ($this->number !== $oldSettings['ya_metrika_number']) {
            return true;
        }
        if ($this->client_id !== $oldSettings['ya_metrika_appid']) {
            return true;
        }
        if ($this->client_secret !== $oldSettings['ya_metrika_pwapp']) {
            return true;
        }
        if (empty($oldSettings['ya_metrika_token'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array $newSettings
     * @param array $oldSettings
     * @return bool
     */
    public function isNeedUpdateCode($newSettings, $oldSettings)
    {
        if (empty($this->number) || empty($this->token)) {
            return false;
        }

        if (empty($oldSettings['ya_metrika_code'])) {
            return true;
        }

        $params = array(
            'ya_metrika_number',
            'ya_metrika_map',
            'ya_metrika_ww',
            'ya_metrika_hash',
            'ya_metrika_informer'
        );
        foreach ($params as $param) {
            if ($newSettings[$param] !== $oldSettings[$param]) {
                return true;
            }
        }

        if (mb_strpos($oldSettings['ya_metrika_code'], $newSettings['ya_metrika_number']) === false) {
            return true;
        }

        return false;
    }

    public function updateCode()
    {
        if ($this->editCounter()) {
            $_SESSION['metrika_errors'][] = $this->success_alert('Данные метрики отправлены на сервер.');

            $counter = $this->SendResponse('counter/'.$this->number, array(), array(), 'GET');
            if (!empty($counter['counter']['code'])) {
                $sm = new waAppSettingsModel();
                $sm->set('shop.yamodule_api', 'ya_metrika_code', $counter['counter']['code']);
            } else {
                $_SESSION['metrika_errors'][] = $this->errors_alert('Проверьте настройки метрики. Получен ответ с ошибкой.');
            }
        } else {
            $_SESSION['metrika_errors'][] = $this->errors_alert('Ошибка редактирования счётчика');
        }
    }

    // Все цели счётчика
    public function getCounterGoals()
    {
        return $this->SendResponse('counter/'.$this->number.'/goals', array(), array(), 'GET');
    }

    // Добавление цели
    public function addCounterGoal($params)
    {
        return $this->SendResponse('counter/'.$this->number.'/goals', array(), $params, 'POSTJSON');
    }

    // Удаление цели
    public function deleteCounterGoal($goal)
    {
        return $this->SendResponse('counter/'.$this->number.'/goal/'.$goal, array(), array(), 'DELETE');
    }

    /**
     * @return array|null
     */
    public function editCounter()
    {
        $sm     = new waAppSettingsModel();
        $data   = $sm->get('shop.yamodule_api');
        $params = array(
            'counter' => array(
                'code_options' => array(
                    'clickmap'   => (int)$data['ya_metrika_map'],
                    'visor'      => (int)$data['ya_metrika_ww'],
                    'track_hash' => (int)$data['ya_metrika_hash'],
                    'informer'   => array(
                        'enabled' => (int)$data['ya_metrika_informer']
                    )
                )
            )
        );

        return $this->SendResponse('counter/'.$this->number, array(), $params, 'PUT');
    }

    public function SendResponse($to, $headers, $params, $type)
    {
        $response = $this->post($this->url_api.$to.'?oauth_token='.$this->token, $headers, $params, $type);
        if (!empty($response['errors'])) {
            $_SESSION['metrika_errors'][] = $this->errors_alert(json_encode($response['errors']));
            return null;
        }

        return $response;
    }

    public function errors_alert($text)
    {
        $html = '<div class="alert alert-danger">
                        <i class="fa fa-exclamation-circle"></i> '.$text.'
                    </div>';
        return $html;
    }

    public function success_alert($text)
    {
        $html = ' <div class="alert alert-success">
                    <i class="fa fa-check-circle"></i> '.$text.'
                    </div>';
        return $html;
    }

    public static function post($url, $headers, $params, $type){
        $curlOpt = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'php-market',
        );

        switch (strtoupper($type)){
            case 'DELETE':
                $curlOpt[CURLOPT_CUSTOMREQUEST] = "DELETE";
            case 'GET':
                if (!empty($params))
                    $url .= (strpos($url, '?')===false ? '?' : '&') . http_build_query($params);
            break;
            case 'PUT':
                $json      = json_encode($params);
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: '.strlen($json);

                $curlOpt[CURLOPT_HTTPHEADER]    = $headers;
                $curlOpt[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $curlOpt[CURLOPT_POSTFIELDS]    = $json;
                break;
            case 'POST':
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $curlOpt[CURLOPT_HTTPHEADER] = $headers;
                $curlOpt[CURLOPT_POST] = true;
                $curlOpt[CURLOPT_POSTFIELDS] = http_build_query($params);
            break;
            case 'POSTJSON':
                $headers[] = 'Content-Type: application/x-yametrika+json';
                $curlOpt[CURLOPT_HTTPHEADER] = $headers;
                $curlOpt[CURLOPT_POST] = true;
                $curlOpt[CURLOPT_POSTFIELDS] = json_encode($params);
            break;
            default:
                throw new YandexApiException("Unsupported request type '$type'");
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, $curlOpt);
        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }
}