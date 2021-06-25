<?php

namespace App\Feeds\Downloader;

use App\Feeds\Utils\Data;
use App\Feeds\Utils\HttpClient;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Feeds\Utils\ProxyConnector;
use App\Helpers\HttpHelper;
use Exception;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use JetBrains\PhpStorm\Pure;

class HttpDownloader
{
    /**
     * @var HttpClient Ассинхронный HTTP клиент
     */
    private HttpClient $client;
    /**
     * @var bool Использовать статичный user agent или изменять его при каждом запросе
     */
    private bool $static_user_agent;
    /**
     * @var string Текущий user agent
     */
    private string $user_agent;
    /**
     * @var float Время ожидания между отправкой запроса
     */
    private float $delay_s;
    /**
     * @var array Параметры для авторизации
     *
     * 'check_login_text' => 'Log Out' - Проверочное слово, которое отображается только авторизованным пользователям (Log Out, My account и прочие)
     * 'auth_url' => 'https://www.authorise_uri.com/login' - Url адрес на который отправляется запрос для авторизации
     * 'auth_form_url' => 'https://www.authorise_uri.com/login' - Url адрес страницы, на которой находится форма авторизации
     * 'auth_info' => [] - Массив параметров для авторизации, содержит в себе все поля, которые были отправлены браузером для авторизации
     * 'find_fields_form' => true|false - Определяет искать дополнительные поля формы авторизации перед отправкой запроса или нет
     * Если этот параметр будет опущен, система сочтет его значение как "true"
     * 'api_auth' => true|false - Указывает в каком виде отправлять параметры формы авторизации ("request_payload" или "form_data")
     * Если этот параметр будет опущен, система сочтет его значение как "false".
     * По умолчанию параметры отправляются, как обычные поля формы
     *
     * Пример содержания auth_info:
     * 'auth_info' => [
     *     'login[username]' => 'user@my-email.com',
     *     'login[password]' => 'My-Password',
     * ],
     */
    private array $params;
    /**
     * @var bool Определяет использовать прокси или нет
     */
    private bool $use_proxy;
    /**
     * @var bool Определяет установлено соединение с прокси сервером или нет
     */
    private bool $connect = false;
    /**
     * @var int Определяет время ожидания обработки запроса в секундах
     */
    public int $timeout_s;

    public function __construct( array $headers = [], array $params = [], float $timeout_s = 15, float $delay_s = 0, bool $static_user_agent = false, bool $use_proxy = false, string $source = 'https://google.com' )
    {
        $this->timeout_s = $timeout_s;

        $this->setDelay( $delay_s );
        $this->setParams( $params );
        $this->setUseProxy( $use_proxy );

        $this->setStaticUserAgent( $static_user_agent );
        $this->setUserAgent( HttpHelper::getUserAgent() );

        $this->client = new HttpClient( $source );
        $this->client->setHeaders( $headers );
        $this->setTimeOut( $this->timeout_s );

        $this->processAuth();
    }

    /**
     * @param bool $static_agent Устанавливает использовать статичный user-agent или нет
     */
    public function setStaticUserAgent( bool $static_agent ): void
    {
        $this->static_user_agent = $static_agent;
    }

    /**
     * @param string $user_agent Устанавливает значение user-agent
     */
    public function setUserAgent( string $user_agent ): void
    {
        $this->user_agent = $user_agent;
    }

    /**
     * @param float $timeout Устанавливает время ожидания отклика на запрос
     */
    public function setTimeOut( float $timeout ): void
    {
        $this->getClient()->setRequestTimeOut( $timeout * 1000 );
    }

    /**
     * @param float $delay Устанавливает задержку между запросами
     */
    public function setDelay( float $delay ): void
    {
        $this->delay_s = $delay * 1000000;
    }

    /**
     * @param bool $use_proxy Устанавливает использовать прокси или нет
     */
    public function setUseProxy( bool $use_proxy ): void
    {
        $this->use_proxy = $use_proxy;
    }

    /**
     * @param array $params Устанавливает параметры авторизации
     */
    public function setParams( array $params ): void
    {
        $this->params = $params;
    }

    /**
     * Асинхронная отправка нескольких запросов
     * @param array $links Принимается массив ссылок или массив объектов app/Feeds/Utils/Link
     * @param bool $assoc Указывает в каком виде возвращать массив ответов на запросы
     * Обычный массив, где ключ - это адрес ссылки, на которую был отправлен запрос, а значение - это содержание ответа на запрос
     * Ассоциативный массив, вида:
     * 'data' => new Data() - Содержание ответа на запрос
     * 'link' => [
     * 'url' => $link->getUrl() - Адрес ссылки, на которую был отправлен запрос
     * 'params' => $link->getParams() - Массив параметров запроса
     * ]
     * @return array Массив ответов на запросы, каждый ответ помещен в объект app/Feeds/Utils/Data
     */
    public function fetch( array $links, bool $assoc = false ): array
    {
        $data = [];
        $errors_links = [];

        $requests = function ( $links ) use ( &$data, &$errors_links, $assoc ) {
            foreach ( $links as $link ) {
                if ( !$link instanceof Link ) {
                    $link = new Link( $link );
                }

                if ( $this->use_proxy && !$this->connect ) {
                    print PHP_EOL . 'Check proxies' . PHP_EOL;
                    $this->initProxy( $link );
                }

                if ( $this->static_user_agent ) {
                    $this->setHeader( 'User-Agent', $this->user_agent );
                }
                else {
                    $this->setHeader( 'User-Agent', HttpHelper::getUserAgent() );
                }

                yield function () use ( $link, &$data, &$errors_links, $assoc ) {
                    return $this->getClient()->request( $link->getUrl(), $link->getParams(), $link->getMethod(), $link->getTypeParams() )->
                    then(
                        function ( Response $response ) use ( $link, &$data, $assoc ) {
                            if ( $body = $response->getBody() ) {
                                $response_body = new Data( $body->getContents() );
                            }
                            $data = $this->prepareRequestData( $response_body ?? null, $link, $assoc, $data );
                        },
                        function ( RequestException $exception ) use ( $link, &$data, &$errors_links, $assoc ) {
                            if ( $response = $exception->getResponse() ) {
                                $status = $response->getStatusCode();
                                if ( $status === 403 ) {
                                    if ( $this->use_proxy ) {
                                        $this->connect = false;
                                    }
                                    $errors_links[] = $this->prepareErrorLinks( $link, 5 );
                                }
                                elseif ( $status >= 500 ) {
                                    $errors_links[] = $this->prepareErrorLinks( $link, 0 );
                                }
                                elseif ( in_array( $status, [ 200, 404 ] ) ) {
                                    $data = $this->prepareRequestData( new Data( $response->getBody()->getContents() ), $link, $assoc, $data );
                                }
                                else {
                                    $this->printParseError( $link, $exception );
                                    $data = $this->prepareRequestData( new Data( $response->getBody()->getContents() ), $link, $assoc, $data );
                                }
                            }
                            else if ( $this->use_proxy ) {
                                $this->connect = false;
                                $errors_links[] = $this->prepareErrorLinks( $link, 0 );
                            }
                            else {
                                $this->printParseError( $link, $exception );
                                $data = $this->prepareRequestData( null, $link, $assoc, $data );
                            }
                        }
                    );
                };
                usleep( $this->delay_s );
            }
        };
        $pool = new Pool( $this->getClient()->getHttpClient(), $requests( $links ) );
        $promise = $pool->promise();
        $promise->wait();

        if ( $errors_links ) {
            $data = array_merge( $data, $this->processErrorLinks( $errors_links, $assoc ) );
        }
        return $data;
    }

    /**
     * Попытка загрузить ссылки, при загрузке которых произошли 403 или 503 ошибки
     * @param array $errors_links Массив вида ['link' => Link, 'delay' => delay_s]
     * @param bool $assoc В каком виде возвращать массив ответов на запросы
     * @return array Массив ответов на запросы
     */
    private function processErrorLinks( array $errors_links, bool $assoc ): array
    {
        $data = [];
        foreach ( $errors_links as $error_link ) {
            $link = $error_link[ 'link' ];

            if ( $this->static_user_agent ) {
                $this->setHeader( 'User-Agent', $this->user_agent );
            }
            else {
                $this->setHeader( 'User-Agent', HttpHelper::getUserAgent() );
            }

            for ( $i = 1; $i <= 5; $i++ ) {
                if ( $this->use_proxy && !$this->connect ) {
                    print PHP_EOL . 'Check proxies' . PHP_EOL;
                    $this->initProxy( $link );
                }
                else {
                    sleep( $error_link[ 'delay' ] );
                }

                $response = null;
                $exception = null;

                $promise = $this->getClient()->request( $link->getUrl(), $link->getParams(), $link->getMethod(), $link->getTypeParams() )->then(
                    function ( Response $res ) use ( &$response ) {
                        $response = $res;
                    },
                    function ( RequestException $exc ) use ( &$exception ) {
                        $exception = $exc;
                    }
                );
                $promise->wait();
                if ( $response && $body = $response->getBody() ) {
                    $response_body = new Data( $body->getContents() );
                    break;
                }

                $this->connect = false;
            }

            if ( !isset( $response_body ) && isset( $exception ) && $response = $exception->getResponse() ) {
                $response_body = new Data( $response->getBody()->getContents() );
            }

            if ( isset( $exception ) && $exception ) {
                if ( $this->use_proxy ) {
                    $this->connect = false;
                }
                $this->printParseError( $link, $exception );
            }
            $data = $this->prepareRequestData( $response_body ?? null, $link, $assoc, $data );
        }
        return $data;
    }

    /**
     * Посылает одиночный запрос методом GET
     * @param string|Link $link Принимается ссылка или объект app/Feeds/Utils/Link
     * @param array $params Массив параметров, которые будут преобразованы в query string
     * @return Data Объект app/Feeds/Utils/Data который содержит ответ на запрос
     */
    public function get( string|Link $link, array $params = [] ): Data
    {
        if ( !$link instanceof Link ) {
            $link = new Link( $link, 'GET', $params );
        }
        $data = $this->fetch( [ $link ] );
        return array_shift( $data );
    }

    /**
     * Посылает одиночный запрос методом POST
     * @param string|Link $link Принимается ссылка или объект app/Feeds/Utils/Link
     * @param array $params Массив параметров
     * @param string $type_params Тип отправки параметров запроса - как тело html формы или как json строка (тело API запроса)
     * @return Data Объект app/Feeds/Utils/Data который содержит ответ на запрос
     */
    public function post( string|Link $link, array $params = [], string $type_params = 'form_data' ): Data
    {
        if ( !$link instanceof Link ) {
            $link = new Link( $link, 'POST', $params );
        }
        $link->setTypeParams( $type_params );
        $data = $this->fetch( [ $link ] );
        return array_shift( $data );
    }

    /**
     * @return HttpClient Возвращает объект асинхронного http клиента
     */
    public function getClient(): HttpClient
    {
        return $this->client;
    }

    /**
     * Устанавливает заголовок http запроса
     * @param string $name Название заголовка
     * @param string $value Значение заголовка
     */
    public function setHeader( string $name, string $value ): void
    {
        $this->getClient()->setHeader( $name, $value );
    }

    /**
     * @param array $headers Устанавливает набор заголовков http запроса
     */
    public function setHeaders( array $headers ): void
    {
        $this->getClient()->setHeaders( $headers );
    }

    /**
     * Возвращает значение указанного заголовка
     * @param string $name Название заголовка
     * @return string|null Значение заголовка
     */
    public function getHeader( string $name ): ?string
    {
        return $this->getClient()->getHeader( $name );
    }

    /**
     * @return array Возвращает массив заголовков
     */
    #[Pure]
    public function getHeaders(): array
    {
        return $this->getClient()->getHeaders();
    }

    /**
     * Устанавливает новую cookie
     * @param string $name Название cookie
     * @param string $value Значение cookie
     */
    public function setCookie( string $name, string $value ): void
    {
        try {
            $this->getClient()->setCookie( $name, $value );
        } catch ( Exception $e ) {
            print $e->getMessage();
        }
    }

    /**
     * Возвращает значение указанной cookie
     * @param string $name Название cookie
     * @return string Значение cookie
     */
    public function getCookie( string $name ): string
    {
        return $this->getClient()->getCookie( $name );
    }

    /**
     * Удаляет все куки
     */
    public function clearCookie(): void
    {
        $this->getClient()->clearCookie();
    }

    private function printParseError( Link $link, RequestException $exception ): void
    {
        if ( $response = $exception->getResponse() ) {
            if ( $response->getStatusCode() !== 404 ) {
                echo PHP_EOL . "Parser Error: " . $response->getReasonPhrase() .
                    PHP_EOL . "Status code: " . $response->getStatusCode() .
                    PHP_EOL . "URI: " . $link->getUrl() . PHP_EOL;
            }
            else {
                return;
            }
        }
        else {
            echo PHP_EOL . "Parser Error: " . $exception->getMessage() . PHP_EOL . "URI: " . $link->getUrl() . PHP_EOL;
        }
    }

    private function prepareErrorLinks( Link $link, int $delay ): array
    {
        return [
            'link' => $link,
            'delay' => $delay
        ];
    }

    private function prepareRequestData( ?Data $response_body, Link $link, bool $assoc, array $data ): array
    {
        if ( $assoc ) {
            $data[] = [
                'data' => $response_body ?? new Data(),
                'link' => [
                    'url' => $link->getUrl(),
                    'params' => $link->getParams()
                ]
            ];
        }
        else {
            $data[ $link->getUrl() ] = $response_body ?? new Data();
        }
        return $data;
    }

    /**
     * @return string|null Возвращает url адрес на который будет отправлен запрос для авторизации
     */
    public function getAuthUrl(): ?string
    {
        return $this->params[ 'auth_url' ] ?? null;
    }

    /**
     * @return string|null Возвращает url адрес страницы, на которой находится форма авторизации
     */
    public function getAuthFormUrl(): ?string
    {
        return $this->params[ 'auth_form_url' ] ?? null;
    }

    /**
     * @return array Возвращает массив параметров для авторизации
     */
    public function getAuthInfo(): array
    {
        return $this->params[ 'auth_info' ] ?? [];
    }

    /**
     * @return bool Возвращает значение, в зависимости от которого, параметры формы авторизации будут отправлены, как "form_data", либо "request_payload"
     */
    public function getApiAuth(): bool
    {
        return isset( $this->params[ 'api_auth' ] ) && $this->params[ 'api_auth' ];
    }

    /**
     * @return string|null Возвращает проверочное слово авторизации
     */
    public function getCheckLoginText(): ?string
    {
        return $this->params[ 'check_login_text' ] ?? null;
    }

    /**
     * Процесс авторизации
     * @param null $callback
     * @return bool
     */
    public function processAuth( $callback = null ): bool
    {
        if ( $this->getAuthUrl() && $this->getAuthInfo() ) {
            if ( !isset( $this->params[ 'find_fields_form' ] ) || $this->params[ 'find_fields_form' ] ) {
                $this->params[ 'auth_info' ] = $this->getFieldsFormOnLink( $this->getAuthFormUrl() ?? $this->getAuthUrl(), array_key_first( $this->getAuthInfo() ), $this->getAuthInfo() );
            }

            $data_n = $this->post( $this->getAuthUrl(), $this->getAuthInfo(), $this->getApiAuth() ? 'request_payload' : 'form_data' );
            $crawler_n = new ParserCrawler( $data_n->getData() );
            if ( $crawler_n->count() && stripos( $crawler_n->html(), 'sucuri_cloudproxy_js' ) !== false ) {
                $cookies = HttpHelper::sucuri( $crawler_n->html() );
                if ( $cookies ) {
                    $this->setCookie( $cookies[ 0 ], $cookies[ 1 ] );

                    $data_n = $this->post( $this->getAuthUrl(), $this->getAuthInfo() );
                    $crawler_n = new ParserCrawler( $data_n->getData() );
                }
            }

            if ( $callback ) {
                $callback( $crawler_n, $this );
            }

            return $this->checkLogin( $crawler_n );
        }
        return false;
    }

    /**
     * Используется для получения полей формы
     * @param string|Link $link Ссылка на страницу, где расположена форма
     * @param string $field_name Имя поля, находящееся внутри нужной формы. По имени этого поля будет роизведен поиск формы
     * Для получения всех полей из любой формы необходимо передать пустое значение
     * @param array $params Массив параметров с исходными значениями, если они есть
     * @param bool $only_hidden Параметер позволяет собирать все поля формы или только скрытые. По-умолчанию собираются все поля формы
     * @return array Ассоциативный массив, в котором ключи - имена полей формы, значения - значения полей формы
     */
    public function getFieldsFormOnLink( string|Link $link, string $field_name = '', array $params = [], bool $only_hidden = false ): array
    {
        return $this->findFieldsForm( new ParserCrawler( $this->get( $link )->getData() ), $field_name, $params, $only_hidden );
    }

    /**
     * Используется для получения полей формы
     * @param ParserCrawler $crawler Html содержимое страницы, где расположена форма
     * @param string $field_name Имя поля, находящееся внутри нужной формы. По имени этого поля будет роизведен поиск формы
     * Для получения всех полей из любой формы необходимо передать пустое значение
     * @param array $params Массив параметров с исходными значениями, если они есть
     * @param bool $only_hidden Параметер позволяет собирать все поля формы или только скрытые. По-умолчанию собираются все поля формы
     * @return array Ассоциативный массив, в котором ключи - имена полей формы, значения - значения полей формы
     */
    public function getFieldsFormOnCrawler( ParserCrawler $crawler, string $field_name = '', array $params = [], bool $only_hidden = false ): array
    {
        return $this->findFieldsForm( $crawler, $field_name, $params, $only_hidden );
    }

    /**
     * @param ParserCrawler $crawler
     * @param string $field_name
     * @param array $params
     * @param bool $only_hidden
     * @return array
     */
    private function findFieldsForm( ParserCrawler $crawler, string $field_name = '', array $params = [], bool $only_hidden = false ): array
    {
        $selector = 'input';
        if ( $only_hidden ) {
            $selector = 'input[type="hidden"]';
        }

        if ( empty( $field_name ) ) {
            if ( $crawler->filter( 'form' )->count() ) {
                $crawler->filter( "form $selector" )->each( static function ( ParserCrawler $c ) use ( &$params ) {
                    $name = $c->attr( 'name' );
                    $value = $c->attr( 'value' );
                    if ( ( !empty( $params[ $name ] ) ) ) {
                        return;
                    }
                    $params[ $name ] = $value ?? '';
                } );
            }
            return $params;
        }

        if ( $crawler->filter( 'input[name="' . $field_name . '"]' )->count() ) {
            $parents = $crawler->filter( 'input[name="' . $field_name . '"]' )->parents();
            $parents->filter( 'form' )->first()->filter( $selector )->each( function ( ParserCrawler $c ) use ( &$params ) {
                $name = $c->attr( 'name' );
                $value = $c->attr( 'value' );
                if ( ( !empty( $params[ $name ] ) ) ) {
                    return;
                }
                $params[ $name ] = $value ?? '';
            } );
        }
        return $params;
    }

    /**
     * Проверка авторизации на сайте по проверочному слову
     * @param ParserCrawler $crawler
     * @return bool
     */
    public function checkLogin( ParserCrawler $crawler ): bool
    {
        if ( !$crawler ) {
            return false;
        }

        if ( $this->getCheckLoginText() && $crawler->count() ) {
            if ( stripos( $crawler->text(), $this->getCheckLoginText() ) !== false ) {
                print PHP_EOL . 'Authorization successful!' . PHP_EOL;
                return true;
            }
        }
        else {
            return true;
        }

        print PHP_EOL . 'Authorization fail!' . PHP_EOL;
        return false;
    }

    private function initProxy( Link $link ): void
    {
        ( new ProxyConnector() )->connect( $this, $link );
        $this->connect = true;
    }
}