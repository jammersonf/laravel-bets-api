<?php

namespace Andonovn\LaravelBetsApi;

use GuzzleHttp\{
    Client, ClientInterface
};
use GuzzleHttp\Exception\TransferException as HttpException;
use Andonovn\LaravelBetsApi\Exceptions\{
    CallFailedException, InvalidConfigException, MissingConfigException
};
use Andonovn\LaravelBetsApi\Events\ {
    ResponseReceived, RequestFailed
};

class B365Api
{
    /**
     * @var Client
     */
    protected $http;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string|null
     */
    protected $country;

    /**
     * @var string|null
     */
    protected $date;

    /**
     * Fetcher constructor.
     *
     * @param  ClientInterface  $http
     * @param  array  $config
     * @throws MissingConfigException
     * @throws InvalidConfigException
     */
    public function __construct(ClientInterface $http, array $config)
    {
        $this->validateConfig($config);

        $this->http = $http;
        $this->config = $config;
    }

    /**
     * Validate the config data
     *
     * @param  array  $config
     * @throws MissingConfigException
     * @throws InvalidConfigException
     */
    protected function validateConfig(array $config)
    {
        $requiredKeys = ['token', 'endpoint', 'failed_calls'];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $config)) {
                throw new MissingConfigException('The following config option is missing: ' . $key);
            }

            if (in_array($key, ['token', 'endpoint'], true)) {
                if (! is_string($config[$key])) {
                    throw new InvalidConfigException('The following config option must be string: ' . $key);
                }
            } else {
                if (! is_array($config['failed_calls'])) {
                    throw new InvalidConfigException('The following config option must be array: failed_calls');
                }

                $failedCallsKeys = ['retries', 'seconds_to_sleep'];

                foreach ($failedCallsKeys as $key) {
                    if (! array_key_exists($key, $config['failed_calls'])) {
                        throw new MissingConfigException('The following config option is missing: failed_calls.' . $key);
                    }

                    $int = $config['failed_calls'][$key];

                    if ((! is_numeric($int)) || ($int - intval($int) != 0)) {
                        throw new InvalidConfigException('The following config option must be int: failed_calls.' . $key);
                    }
                }
            }
        }
    }

    /**
     * Set the country
     *
     * @param  string  $country
     * @return B365Api
     */
    public function forCountry(string $country) : B365Api
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Set the date
     *
     * @param  string  $date
     * @return B365Api
     */
    public function forDate(string $date) : B365Api
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Get the given sport's leagues
     *
     * @param  int $sportId
     * @return array
     * @throws CallFailedException
     */
    public function upcoming(int $sportId) : array
    {
        $leagues = [];

        $page = 1;

        do {
            $leagueResponse = $this->upcomingCall($sportId, $page++);

            $totalPages = (int) ceil(
                $leagueResponse['pager']['total'] / $leagueResponse['pager']['per_page']
            );

            $leagues = array_merge($leagues, $leagueResponse['results']);
        } while ($page <= $totalPages);

        return $leagues;
    }

    /**
     * Get the soccer's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function soccerLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_SOCCER);
    }

    /**
     * Get the soccer's leagues inPlay
     *
     * @return array
     * @throws CallFailedException
     */
    public function soccerLeaguesInPlay() : array
    {
        return $this->liveEvents(Glossary::SPORT_SOCCER);
    }

    /**
     * Get the basketball's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function basketballLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_BASKETBALL);
    }

    /**
     * Get the tennis's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function tennisLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_TENNIS);
    }

    /**
     * Get the cricket's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function cricketLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_CRICKET);
    }

    /**
     * Get the hockey's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function hockeyLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_ICE_HOCKEY);
    }

    /**
     * Get the baseball's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function baseballLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_BASEBALL);
    }

    /**
     * Get the American football's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function americanFootballLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_AMERICAN_FOOTBALL);
    }

    /**
     * Get the fight's leagues
     *
     * @return array
     * @throws CallFailedException
     */
    public function fightLeagues() : array
    {
        return $this->upcoming(Glossary::SPORT_BOXING_UFC);
    }

    /**
     * Get the given league's events
     *
     * @param  int $sportId
     * @param  int $leagueId
     * @return array
     * @throws CallFailedException
     */
    public function events(int $sportId, int $leagueId) : array
    {
        $events = [];

        $page = 1;

        do {
            $eventsResponse = $this->eventsCall($sportId, $leagueId, $page++);

            $totalPages = (int) ceil(
                $eventsResponse['pager']['total'] / $eventsResponse['pager']['per_page']
            );

            $events = array_merge($events, $eventsResponse['results']);
        } while ($page <= $totalPages);

        return $events;
    }

    /**
     * View an event
     *
     * @param  int $eventId
     * @return array
     * @throws CallFailedException
     */
    public function viewEvent(int $eventId) : array
    {
        return current($this->eventViewCall($eventId)['results']);
    }

    /**
     * View an event
     *
     * @param  int $eventId
     * @return array
     * @throws CallFailedException
     */
    public function viewLiveEvent(int $eventId) : array
    {
        return current($this->eventLiveViewCall($eventId)['results']);
    }


    /**
     * Get the given league's live events
     *
     * @param  int $sportId
     * @param  int $leagueId
     * @return array
     * @throws CallFailedException
     */
    public function liveEvents(int $sportId, $leagueId = '') : array
    {
        $events = $this->liveEventsCall($sportId, $leagueId);

        return $events;
    }

    public function results($event)
    {
        $endpoint = $this->endpoint('result'). '&event_id=' .$event;

        return $this->call($endpoint);
    }

    /**
     * Build the requested route's endpoint
     *
     * @param  string  $route
     * @param  int|null  $page
     * @param  bool  $isV2  Some requests requires v2, while others don't work with v2...
     * @return string
     */
    protected function endpoint(string $route, ?int $page = null, bool $isV2 = false) : string
    {
        $endpoint = $this->config['endpoint'];
        if ($isV2) {
            $endpoint = str_replace('/v1', '/v2', $endpoint);
        }
        $endpoint .= $route . '?token=' . $this->config['token'];

        if ($page) {
            $endpoint .= '&page=' . $page;
        }

        return $endpoint;
    }

    /**
     * Build the requested route's v2 endpoint
     *
     * @param  string  $route
     * @param  int|null  $page
     * @return string
     */
    protected function endpointV2(string $route, ?int $page = null) : string
    {
        return $this->endpoint($route, $page, true);
    }

    /**
     * Call the B365Api to get the leagues
     *
     * @param  int $sportId
     * @param  int $page
     * @return array
     * @throws CallFailedException
     */
    protected function upcomingCall(int $sportId, int $page) : array
    {
        $endpoint = $this->endpoint('upcoming', $page)
            . '&sport_id=' . $sportId
            . ($this->country ? '&cc=' . $this->country : '');

        return $this->call($endpoint);
    }

    /**
     * Call the B365Api to get the events
     *
     * @param  int $sportId
     * @param  int $leagueId
     * @param  int $page
     * @return array
     * @throws CallFailedException
     */
    protected function eventsCall(int $sportId, int $leagueId, int $page) : array
    {
        $endpoint = $this->endpointV2('upcoming', $page)
            . '&league_id=' . $leagueId . '&sport_id=' . $sportId;

        return $this->call($endpoint);
    }
    
    /**
     * Call the B365Api to view an event
     *
     * @param  int $eventId
     * @return array
     * @throws CallFailedException
     */
    protected function eventViewCall(int $eventId) : array
    {
        return $this->call($this->endpoint('prematch') . '&FI=' . $eventId);
    }
    
    /**
     * Call the B365Api to view an live event
     *
     * @param  int $eventId
     * @return array
     * @throws CallFailedException
     */
    protected function eventLiveViewCall(int $eventId) : array
    {
        return $this->call($this->endpoint('event') . '&FI=' . $eventId);
    }

    /**
     * Call the B365Api to get the live events
     *
     * @param  int $sportId
     * @param  int $leagueId
     * @return array
     * @throws CallFailedException
     */
    protected function liveEventsCall(int $sportId, $leagueId = '') : array
    {
        $endpoint = $this->endpoint('inplay_filter'). '&sport_id=' . $sportId;
        if ($leagueId) {
            $endpoint = $endpoint. '&league_id=' . $leagueId;
        }

        return $this->call($endpoint);
    }

    /**
     * Trigger a call to the given the B365Api service's endpoint
     * 
     * @param  string  $endpoint
     * @return array
     * @throws CallFailedException
     */
    protected function call(string $endpoint) : array
    {
        for ($attempt = 1; $attempt <= $this->config['failed_calls']['retries'] + 1; $attempt++) {
            try {
                $response = $this->http->get($endpoint);

                $jsonResponse = $response->getBody()->getContents();

                event(new ResponseReceived($response, $jsonResponse, $endpoint));

                return json_decode($jsonResponse, true);
            } catch (HttpException $e) {
                event(new RequestFailed($e));

                if ($attempt == $this->config['failed_calls']['retries'] + 1) {
                    throw CallFailedException::whenAttemptedToReach($endpoint);
                }

                if ($this->config['failed_calls']['seconds_to_sleep'] > 0) {
                    sleep($this->config['failed_calls']['seconds_to_sleep']);
                }
            }
        }
    }
}
